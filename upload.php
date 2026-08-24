<?php
/**
 * 上传项目接口：接收前端 POST 的「项目名称」「备注」与模型/材质/贴图文件，
 * 在 files/ 下新建专属子文件夹（仅用上传日期时间命名），保存文件并写入 meta.json，
 * 然后自动重写 files/manifest.js 清单（供项目列表显示 名称 / 备注 / 文件夹）。
 *
 * 前置条件：服务器支持 PHP，且 files/ 目录可写（web 进程有权限）。
 */
header('Content-Type: application/json; charset=utf-8');
// 注意：upload_max_filesize / post_max_size 属于 PERDIR/SYSTEM 级指令，运行时 ini_set 改不了！
// 真正的放开请放在 php.ini 或站点根目录的 .user.ini（本项目已附 .user.ini，部分虚拟主机生效）。
// memory_limit 可在运行时调整（下面这行有效）。
@ini_set('memory_limit', '512M');

// ===== 上传密码校验（后端强制，前端页面任何人可见，故必须后端再校验一次）=====
// 密码以 sha256 哈希存储，PHP 源码中不出现明文；比对用 hash_equals 防时序攻击。
require_once __DIR__ . '/settings_lib.php';
$UPLOAD_PWD_HASH = odmGetPwdHash();
$submittedPwd = isset($_POST['pwd']) ? strval($_POST['pwd']) : '';
if (!hash_equals($UPLOAD_PWD_HASH, hash('sha256', $submittedPwd))) {
    echo json_encode(['success' => false, 'error' => '上传密码错误']);
    exit;
}

$filesDir = __DIR__ . '/files';
if (!is_dir($filesDir)) {
    @mkdir($filesDir, 0755, true);
}

// 统一为文件数组（multiple 上传时为数组，单文件也兼容）
$raw = $_FILES['file'] ?? null;
if (!$raw) {
    echo json_encode(['success' => false, 'error' => '未收到文件']);
    exit;
}
if (!is_array($raw['name'])) {
    $raw = [
        'name'     => [$raw['name']],
        'type'     => [$raw['type']],
        'tmp_name' => [$raw['tmp_name']],
        'error'    => [$raw['error']],
        'size'     => [$raw['size']],
    ];
}
$count = count($raw['name']);

// 仅允许模型与材质/贴图 / iTwin metadata.xml，防任意文件上传（OBJ 工程常含 .mtl 与贴图）
$ALLOWED = '/\.(glb|gltf|obj|mtl|stl|ply|fbx|3mf|dae|3ds|pcd|jpe?g|png|bmp|tga|tiff?|webp|xml)$/i';
if (!is_writable($filesDir)) {
    echo json_encode(['success' => false, 'error' => 'files 目录不可写，请检查权限']);
    exit;
}

$errMap = [
    1 => '文件超过服务器限制（upload_max_filesize）',
    2 => '文件超过表单限制（post_max_size）',
    3 => '文件仅部分上传',
    4 => '未选择文件',
    6 => '缺少临时目录',
    7 => '写入磁盘失败',
    8 => '扩展被拒绝',
];

// 项目名称与备注（均为选填）
$projName   = isset($_POST['name'])   ? trim(strval($_POST['name']))   : '';
$projRemark = isset($_POST['remark']) ? trim(strval($_POST['remark'])) : '';

// 专属子文件夹名：仅用上传日期时间（不含项目名称），秒级时间戳保证可读且基本唯一
$folder = makeFolderName();
while (is_dir($filesDir . '/' . $folder)) {
    $folder = date('Ymd_His') . '_' . mt_rand(100, 999);   // 极小概率同秒上传，追加随机数避免冲突
}
$projDir = $filesDir . '/' . $folder;
if (!is_dir($projDir)) {
    @mkdir($projDir, 0755, true);
}
if (!is_dir($projDir)) {
    echo json_encode(['success' => false, 'error' => '无法创建项目文件夹：' . $folder]);
    exit;
}

$savedCount = 0;
for ($i = 0; $i < $count; $i++) {
    $err = $raw['error'][$i];
    if ($err !== UPLOAD_ERR_OK) {
        echo json_encode(['success' => false, 'error' => ($errMap[$err] ?? ('上传错误码 ' . $err)) . '（' . $raw['name'][$i] . '）']);
        exit;
    }
    $name = basename($raw['name'][$i]);           // basename 防目录穿越
    if (!preg_match($ALLOWED, $name)) {
        echo json_encode(['success' => false, 'error' => '仅支持 GLB/GLTF/OBJ(+MTL)/STL/PLY/FBX/3MF/DAE/3DS/PCD 及贴图/metadata.xml 文件（' . $name . '）']);
        exit;
    }
    // 防重名：已存在则追加时分秒
    $dest = $projDir . '/' . $name;
    if (file_exists($dest)) {
        $dest = $projDir . '/' . pathinfo($name, PATHINFO_FILENAME) . '_' . date('His') . '.' . pathinfo($name, PATHINFO_EXTENSION);
    }
    if (!move_uploaded_file($raw['tmp_name'][$i], $dest)) {
        echo json_encode(['success' => false, 'error' => '保存文件失败：' . $name]);
        exit;
    }
    $savedCount++;
}

// 封面图（选填）：客户端已用 canvas 压缩为 800×500 / JPEG 0.8，PHP 仅做落盘 + 校验
// 不在 PHP 二次压缩（避免重复 encode 损失画质 + 节省 CPU），但会做 MIME/大小校验
$coverFilename = '';
if (isset($_FILES['cover']) && is_array($_FILES['cover']) && ($_FILES['cover']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
    $cv = $_FILES['cover'];
    // 限制：≤ 5MB（压缩后基本不会超过；防恶意大文件占盘）
    if ($cv['size'] > 5 * 1024 * 1024) {
        echo json_encode(['success' => false, 'error' => '封面图超过 5MB，请先在客户端压缩']);
        exit;
    }
    // 校验 MIME（防止 .php 之类伪封面）
    $finfo = function_exists('finfo_open') ? finfo_open(FILEINFO_MIME_TYPE) : null;
    $mime = $finfo ? finfo_file($finfo, $cv['tmp_name']) : ($cv['type'] ?? '');
    if ($finfo) finfo_close($finfo);
    if (!preg_match('#^image/(jpeg|png|webp|gif|bmp)$#i', (string)$mime)) {
        echo json_encode(['success' => false, 'error' => '封面 MIME 不合法：' . $mime]);
        exit;
    }
    $coverFilename = 'cover.jpg';
    $coverDest = $projDir . '/' . $coverFilename;
    if (!move_uploaded_file($cv['tmp_name'], $coverDest)) {
        echo json_encode(['success' => false, 'error' => '保存封面图失败']);
        exit;
    }
}

// 主模型文件：优先 .glb，其次 .gltf，其次 .obj（大小写不敏感）
$objs = findAllModels($projDir);
$main = $objs[0] ?? '';

// 上传时间戳（统一用服务器时间；manifest 排序与首页显示用）
$uploadedAt = date('Y-m-d H:i:s');

// 自动解析 iTwin metadata.xml → 基准经纬度（打点工具换算 WGS84 用，无需手动填）
$originGps = parseTwinMeta($projDir);

// 写入项目元信息（名称 / 备注 / 主模型 / 分块列表 / 封面 / 基准经纬度 / 上传时间）
file_put_contents(
    $projDir . '/meta.json',
    json_encode([
        'name'       => $projName !== '' ? $projName : ($main !== '' ? pathinfo($main, PATHINFO_FILENAME) : $folder),
        'remark'     => $projRemark,
        'main'       => $main,
        'objs'       => $objs,
        'cover'      => $coverFilename,            // 空字符串 = 未传封面
        'originGps'  => $originGps,                // null 或 {lng,lat,alt}
        'uploadedAt' => $uploadedAt,
    ], JSON_UNESCAPED_UNICODE)
);

// 重新扫描 files/ 下所有项目，重写 manifest.js（无需 MySQL，清单即对象数组）
rebuildManifest($filesDir);

echo json_encode([
    'success' => true,
    'count'   => $savedCount,
    'folder'  => $folder,
    'main'    => $main,
    'cover'   => $coverFilename !== '',   // 是否成功保存了封面
]);

// ============================================================
//  工具函数
// ============================================================

/** 生成专属文件夹名：仅用上传日期时间（不含项目名称），形如 20260731_130228 */
function makeFolderName() {
    return date('Ymd_His');
}

/** 在目录中按优先级查找主模型文件名（glb > gltf > obj > fbx > 3mf > dae > 3ds > stl > ply > pcd），大小写不敏感 */
function findMainModel($dir) {
    $pref = ['glb', 'gltf', 'obj', 'fbx', '3mf', 'dae', '3ds', 'stl', 'ply', 'pcd'];
    $found = [];
    foreach (array_diff(scandir($dir), ['.', '..']) as $f) {
        if (is_dir($dir . '/' . $f)) {
            continue;
        }
        $e = strtolower(pathinfo($f, PATHINFO_EXTENSION));
        if (in_array($e, $pref, true)) {
            $found[$e] = $f;
        }
    }
    foreach ($pref as $e) {
        if (isset($found[$e])) {
            return $found[$e];
        }
    }
    return '';
}

/** 递归查找目录下所有模型文件（glb/gltf/obj/fbx/3mf/dae/3ds/stl/ply/pcd），返回相对路径数组（按优先级；同优先级按字母序）。
 *  用于支持 iTwin Capture 等分块（Tile）输出的实景模型：一个项目含多个 obj，需全部加载并自动拼接。 */
function findAllModels($dir, $prefix = '') {
    $result = [];
    if (!is_dir($dir)) return $result;
    $prefRank = ['glb' => 0, 'gltf' => 1, 'obj' => 2, 'fbx' => 3, '3mf' => 4, 'dae' => 5, '3ds' => 6, 'stl' => 7, 'ply' => 8, 'pcd' => 9];
    $entries  = array_diff(scandir($dir), ['.', '..']);
    $files = [];
    $dirs  = [];
    foreach ($entries as $f) {
        $p = $dir . '/' . $f;
        if (is_dir($p)) {
            if ($f === '_chunks') continue;
            $dirs[] = $f;
        } else {
            $e = strtolower(pathinfo($f, PATHINFO_EXTENSION));
            if (in_array($e, ['glb', 'gltf', 'obj', 'fbx', '3mf', 'dae', '3ds', 'stl', 'ply', 'pcd'], true)) $files[] = $f;
        }
    }
    usort($files, function ($a, $b) use ($prefRank) {
        $ea = strtolower(pathinfo($a, PATHINFO_EXTENSION));
        $eb = strtolower(pathinfo($b, PATHINFO_EXTENSION));
        $ra = $prefRank[$ea] ?? 9; $rb = $prefRank[$eb] ?? 9;
        if ($ra !== $rb) return $ra - $rb;
        return strcmp($a, $b);
    });
    foreach ($files as $f) $result[] = $prefix . $f;
    foreach ($dirs as $d) $result = array_merge($result, findAllModels($dir . '/' . $d, $prefix . $d . '/'));
    return $result;
}

/** 解析 iTwin Capture 的 metadata.xml：提取 SRS 经纬度（ENU:纬度,经度），返回 ['lng'=>..,'lat'=>..,'alt'=>0] 或 null。
 *  用于打点工具自动换算 WGS84，无需手动填写基准。扫描项目根目录及一级子目录（metadata.xml 为单份）。 */
function parseTwinMeta($dir) {
    $check = [$dir];
    foreach (array_diff(scandir($dir), ['.', '..']) as $f) {
        $p = $dir . '/' . $f;
        if (is_dir($p)) $check[] = $p;
    }
    foreach ($check as $d) {
        $xmlPath = $d . '/metadata.xml';
        if (!is_file($xmlPath)) continue;
        $txt = @file_get_contents($xmlPath);
        if ($txt === false) continue;
        // <SRS>ENU:22.717,114.25307</SRS>  —— ENU 顺序：纬度在前，经度在后
        if (preg_match('/<SRS>\s*ENU\s*:\s*([-\d.]+)\s*,\s*([-\d.]+)\s*<\/SRS>/i', $txt, $m)) {
            $lat = floatval($m[1]);
            $lng = floatval($m[2]);
            if ($lat >= -90 && $lat <= 90 && $lng >= -180 && $lng <= 180) {
                return ['lng' => $lng, 'lat' => $lat, 'alt' => 0];
            }
        }
    }
    return null;
}

/** 重建 files/manifest.js：子文件夹项目（读 meta.json）+ files/ 根目录扁平历史文件 */
function rebuildManifest($filesDir) {
    $projects = [];

    // 1) 子文件夹项目
    foreach (array_diff(scandir($filesDir), ['.', '..']) as $entry) {
        $full = $filesDir . '/' . $entry;
        if (!is_dir($full)) {
            continue;
        }
        $meta = [];
        $metaPath = $full . '/meta.json';
        if (is_file($metaPath)) {
            $dec = json_decode(file_get_contents($metaPath), true);
            if (is_array($dec)) {
                $meta = $dec;
            }
        }
        $main = isset($meta['main']) ? $meta['main'] : '';
        if ($main === '') {
            $main = findMainModel($full);
        }
        if ($main === '') {
            continue;   // 子文件夹内无模型则跳过
        }
        $objs = isset($meta['objs']) && is_array($meta['objs'])
            ? $meta['objs']
            : ($main !== '' ? [$main] : []);
        // uploadedAt 优先用 meta 字段，否则用目录 mtime 兜底
        $uploadedAt = isset($meta['uploadedAt']) ? $meta['uploadedAt'] : date('Y-m-d H:i:s', filemtime($full));
        $projects[] = [
            'name'       => isset($meta['name']) && $meta['name'] !== '' ? $meta['name'] : $entry,
            'remark'     => isset($meta['remark']) ? $meta['remark'] : '',
            'folder'     => $entry,
            'main'       => $main,
            'objs'       => $objs,
            'cover'      => isset($meta['cover']) ? $meta['cover'] : '',   // 老项目无 cover 字段
            'originGps'  => isset($meta['originGps']) ? $meta['originGps'] : null,
            'uploadedAt' => $uploadedAt,
        ];
    }

    // 2) files/ 根目录下的扁平模型（兼容历史平铺文件：Duck.glb 等）
    foreach (glob($filesDir . '/*.{glb,gltf,obj,fbx,3mf,dae,3ds,stl,ply,pcd}', GLOB_BRACE) as $p) {
        if (is_dir($p)) {
            continue;
        }
        $bn = basename($p);
        $projects[] = [
            'name'       => pathinfo($bn, PATHINFO_FILENAME),
            'remark'     => '',
            'folder'     => '',
            'main'       => $bn,
            'cover'      => '',
            'uploadedAt' => date('Y-m-d H:i:s', filemtime($p)),
        ];
    }

    // 中文友好排序
    usort($projects, function ($a, $b) {
        return strcmp($a['name'], $b['name']);
    });

    file_put_contents(
        $filesDir . '/manifest.js',
        'window.PROJECT_FILES = ' . json_encode($projects, JSON_UNESCAPED_UNICODE) . ';' . "\n"
    );
}
