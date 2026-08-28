<?php
/**
 * 分片上传接口：前端把每个文件切成 ≤5MB 的小片，逐片 POST 到此接口。
 * 后端把每片存到 files/_chunks/<folder>/<fileName>.partNNNNN，
 * 某文件所有片到齐后合并成 files/<folder>/<fileName>；
 * 最后一个文件（isLastFile=1）的最后一片收齐后，写 meta.json + 重建 manifest.js + 清理临时目录。
 *
 * 作用：每片仅 5MB，远小于空间商的 upload_max_filesize / post_max_size / client_max_body_size，
 * 从而绕过所有单文件/单请求大小限制，支持任意大小（含数百 MB 实景模型）上传 + 断点续传。
 */
header('Content-Type: application/json; charset=utf-8');
@ini_set('memory_limit', '512M');

// ===== 上传密码校验（与 upload.php 同源）=====
require_once __DIR__ . '/settings_lib.php';
$PWD_HASH = odmGetPwdHash();
$submittedPwd = isset($_POST['pwd']) ? strval($_POST['pwd']) : '';
if (!hash_equals($PWD_HASH, hash('sha256', $submittedPwd))) {
    echo json_encode(['success' => false, 'error' => '上传密码错误']);
    exit;
}

$filesDir = __DIR__ . '/files';
if (!is_dir($filesDir)) @mkdir($filesDir, 0755, true);
if (!is_writable($filesDir)) {
    echo json_encode(['success' => false, 'error' => 'files 目录不可写，请检查权限']);
    exit;
}

// ===== 参数 =====
$folder     = isset($_POST['folder'])    ? trim(strval($_POST['folder'])) : '';
$fileName   = isset($_POST['fileName'])  ? basename(strval($_POST['fileName'])) : '';
$index      = isset($_POST['index'])     ? intval($_POST['index']) : 0;
$total      = isset($_POST['total'])     ? intval($_POST['total']) : 0;
$isLastFile = isset($_POST['isLastFile']) && ($_POST['isLastFile'] === '1' || $_POST['isLastFile'] === 1 || $_POST['isLastFile'] === true);
$name       = isset($_POST['name'])      ? trim(strval($_POST['name'])) : '';
$remark     = isset($_POST['remark'])    ? trim(strval($_POST['remark'])) : '';
$chunk      = isset($_FILES['chunk'])    ? $_FILES['chunk'] : null;

if ($fileName === '' || $total <= 0) {
    echo json_encode(['success' => false, 'error' => '缺少 fileName 或 total']);
    exit;
}
if (!$chunk || ($chunk['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'error' => '分片接收失败（错误码 ' . ($chunk['error'] ?? -1) . '）']);
    exit;
}

// 仅允许模型与材质/贴图 / iTwin metadata.xml（含封面 jpg/png）
$ALLOWED = '/\.(glb|gltf|obj|mtl|stl|ply|fbx|3mf|dae|3ds|pcd|jpe?g|png|bmp|tga|tiff?|webp|xml)$/i';
if (!preg_match($ALLOWED, $fileName)) {
    echo json_encode(['success' => false, 'error' => '不支持的文件类型：' . $fileName]);
    exit;
}

// ===== folder：首片（前端未带 folder）由后端生成 =====
if ($folder === '') {
    $folder = makeFolderName();
    while (is_dir($filesDir . '/' . $folder)) {
        $folder = date('Ymd_His') . '_' . mt_rand(100, 999);
    }
}
$projDir = $filesDir . '/' . $folder;
if (!is_dir($projDir)) @mkdir($projDir, 0755, true);
if (!is_dir($projDir)) {
    echo json_encode(['success' => false, 'error' => '无法创建项目文件夹：' . $folder]);
    exit;
}
$tmpDir = $filesDir . '/_chunks/' . $folder;
if (!is_dir($tmpDir)) @mkdir($tmpDir, 0755, true);
if (!is_dir($tmpDir)) {
    echo json_encode(['success' => false, 'error' => '无法创建临时分片目录']);
    exit;
}

// 存分片（零填充序号，保证合并顺序）
$partName = rawurlencode($fileName) . '.part' . sprintf('%05d', $index);
$partPath = $tmpDir . '/' . $partName;
if (!move_uploaded_file($chunk['tmp_name'], $partPath)) {
    echo json_encode(['success' => false, 'error' => '保存分片失败：' . $fileName . ' #' . $index]);
    exit;
}

// ===== 本文件分片是否到齐？到齐则合并 =====
// 注：glob 是 shell 风格通配（不是正则），不要用 preg_quote —— 它的 . 会被加反斜杠导致永远找不到
$merged = false;
$parts = glob($tmpDir . '/' . rawurlencode($fileName) . '.part*');
if (is_array($parts) && count($parts) >= $total) {
    try {
        mergeFile($tmpDir, $fileName, $total, $projDir);
        $merged = true;
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => '合并失败：' . $e->getMessage()]);
        exit;
    }
}

// ===== 最后一片：所有文件已顺序上传完，写 meta + rebuild + 清理 =====
if ($isLastFile) {
    $objs = findAllModels($projDir);
    $main = $objs[0] ?? '';
    // 自动解析 iTwin metadata.xml → 基准经纬度（打点工具换算 WGS84 用，无需手动填）
    $originGps = parseTwinMeta($projDir);
    // 封面：若 projDir 下存在 cover.jpg 则记录
    $cover = file_exists($projDir . '/cover.jpg') ? 'cover.jpg' : '';
    $uploadedAt = date('Y-m-d H:i:s');
    file_put_contents($projDir . '/meta.json', json_encode([
        'name'       => $name !== '' ? $name : ($main !== '' ? pathinfo($main, PATHINFO_FILENAME) : $folder),
        'remark'     => $remark,
        'main'       => $main,
        'objs'       => $objs,
        'cover'      => $cover,
        'originGps'  => $originGps,   // null 或 {lng,lat,alt}
        'uploadedAt' => $uploadedAt,
    ], JSON_UNESCAPED_UNICODE));
    rebuildManifest($filesDir);
    rrmdir($tmpDir);
    echo json_encode([
        'success'  => true,
        'folder'   => $folder,
        'main'     => $main,
        'cover'    => $cover !== '',
    ]);
    exit;
}

// 非最后一片：返回 partial 并带上 folder（供前端后续片使用）
echo json_encode([
    'success' => true,
    'folder'  => $folder,
    'merged'  => $merged,
]);
exit;

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
            if ($f === '_chunks') continue;   // 跳过分片临时目录
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

/** 把某文件的全部分片按序号顺序合并为完整文件，并删除临时分片 */
function mergeFile($tmpDir, $fileName, $total, $projDir) {
    $dest = $projDir . '/' . $fileName;
    if (file_exists($dest)) {
        // 防重名：已存在则追加时分秒
        $dest = $projDir . '/' . pathinfo($fileName, PATHINFO_FILENAME) . '_' . date('His') . '.' . pathinfo($fileName, PATHINFO_EXTENSION);
    }
    $fp = fopen($dest, 'wb');
    if (!$fp) {
        throw new Exception('无法打开目标文件：' . $fileName);
    }
    for ($i = 0; $i < $total; $i++) {
        $part = $tmpDir . '/' . rawurlencode($fileName) . '.part' . sprintf('%05d', $i);
        if (!is_file($part)) {
            fclose($fp);
            throw new Exception('分片缺失：' . $fileName . ' #' . $i);
        }
        fwrite($fp, file_get_contents($part));
        unlink($part);
    }
    fclose($fp);
}

/** 递归删除目录 */
function rrmdir($dir) {
    if (!is_dir($dir)) {
        return;
    }
    foreach (array_diff(scandir($dir), ['.', '..']) as $f) {
        $p = $dir . '/' . $f;
        if (is_dir($p)) {
            rrmdir($p);
        } else {
            @unlink($p);
        }
    }
    @rmdir($dir);
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
        if ($entry === '_chunks') {
            continue;   // 跳过分片临时目录
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
        $uploadedAt = isset($meta['uploadedAt']) ? $meta['uploadedAt'] : date('Y-m-d H:i:s', filemtime($full));
        $projects[] = [
            'name'       => isset($meta['name']) && $meta['name'] !== '' ? $meta['name'] : $entry,
            'remark'     => isset($meta['remark']) ? $meta['remark'] : '',
            'folder'     => $entry,
            'main'       => $main,
            'objs'       => $objs,
            'cover'      => isset($meta['cover']) ? $meta['cover'] : '',
            'originGps'  => isset($meta['originGps']) ? $meta['originGps'] : null,
            'uploadedAt' => $uploadedAt,
        ];
    }

    // 2) files/ 根目录下的扁平模型（兼容历史平铺文件）
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
