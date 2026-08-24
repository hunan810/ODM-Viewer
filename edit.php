<?php
/**
 * 编辑项目接口：接收前端 POST 的 folder（必填）、name / remark（可选）、cover（可选文件），
 * 更新 files/<folder>/meta.json，可选覆盖 cover.jpg，最后重建 manifest.js。
 *
 * 前置条件：与 upload.php 一致——PHP 支持，files/ 目录可写。
 * 鉴权：复用 upload.php 同样的上传密码 sha256 哈希（共用同一把"写权限钥匙"）。
 */
header('Content-Type: application/json; charset=utf-8');
@ini_set('memory_limit', '256M');
@error_reporting(0);    // 与 delete.php 一致：避免目录不存在等 warning 污染 JSON 输出

// ===== 密码校验（与 upload.php 同哈希：sha256('199515')）=====
require_once __DIR__ . '/settings_lib.php';
$UPLOAD_PWD_HASH = odmGetPwdHash();
$submittedPwd = isset($_POST['pwd']) ? strval($_POST['pwd']) : '';
if (!hash_equals($UPLOAD_PWD_HASH, hash('sha256', $submittedPwd))) {
    echo json_encode(['success' => false, 'error' => '编辑密码错误']);
    exit;
}

// ===== 定位项目子目录 =====
$folder = isset($_POST['folder']) ? trim(strval($_POST['folder'])) : '';
$folder = basename($folder);    // 防 ../ 目录穿越；只剩最末级名
if ($folder === '' || !preg_match('/^[A-Za-z0-9_\-]+$/', $folder)) {
    echo json_encode(['success' => false, 'error' => 'folder 名称不合法（仅允许字母/数字/下划线/中划线）']);
    exit;
}
$filesDir = __DIR__ . '/files';
$projDir  = $filesDir . '/' . $folder;
if (!is_dir($projDir)) {
    echo json_encode(['success' => false, 'error' => '项目文件夹不存在：' . $folder]);
    exit;
}

// ===== 读旧 meta.json（保留 uploadedAt / main 等字段）=====
$metaPath = $projDir . '/meta.json';
$meta = [];
if (is_file($metaPath)) {
    $dec = json_decode(file_get_contents($metaPath), true);
    if (is_array($dec)) $meta = $dec;
}

// ===== 更新 name / remark（trim 后写入；空串也允许）=====
if (array_key_exists('name', $_POST)) {
    $meta['name'] = trim(strval($_POST['name']));
}
if (array_key_exists('remark', $_POST)) {
    $meta['remark'] = trim(strval($_POST['remark']));
}

// ===== 可选：替换封面图 =====
if (isset($_FILES['cover']) && is_array($_FILES['cover']) && ($_FILES['cover']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
    $cv = $_FILES['cover'];
    // 大小限制 5MB
    if ($cv['size'] > 5 * 1024 * 1024) {
        echo json_encode(['success' => false, 'error' => '封面图超过 5MB，请先在客户端压缩']);
        exit;
    }
    // MIME 校验
    $finfo = function_exists('finfo_open') ? finfo_open(FILEINFO_MIME_TYPE) : null;
    $mime = $finfo ? finfo_file($finfo, $cv['tmp_name']) : ($cv['type'] ?? '');
    if ($finfo) finfo_close($finfo);
    if (!preg_match('#^image/(jpeg|png|webp|gif|bmp)$#i', (string)$mime)) {
        echo json_encode(['success' => false, 'error' => '封面 MIME 不合法：' . $mime]);
        exit;
    }
    // 覆盖 cover.jpg（与 upload.php 命名一致，front-end buildCoverUrl 用固定名）
    if (!move_uploaded_file($cv['tmp_name'], $projDir . '/cover.jpg')) {
        echo json_encode(['success' => false, 'error' => '保存封面图失败']);
        exit;
    }
    $meta['cover'] = 'cover.jpg';
}

// uploadedAt 不变（编辑不算"上传"）
if (!isset($meta['uploadedAt']) || $meta['uploadedAt'] === '') {
    $meta['uploadedAt'] = date('Y-m-d H:i:s', filemtime($projDir));
}
// 兜底：若 meta.json 中没有 main，重新探测（兼容历史缺字段）
if (empty($meta['main'])) {
    $meta['main'] = findMainModel($projDir);
}

// 基准经纬度：前端手动填入优先；否则尝试从 metadata.xml 重新解析（无则保留 null）
$manual = parseManualGps();
if ($manual !== null) {
    $meta['originGps'] = $manual;
} else {
    $meta['originGps'] = parseTwinMeta($projDir);
}

// 写回 meta.json
file_put_contents(
    $metaPath,
    json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
);

// 重建 manifest.js
rebuildManifest($filesDir);

echo json_encode([
    'success'    => true,
    'folder'     => $folder,
    'name'       => $meta['name'],
    'remark'     => $meta['remark'],
    'cover'      => $meta['cover'],
    'objs'       => findAllModels($projDir),
    'originGps'  => $meta['originGps'],
    'uploadedAt' => $meta['uploadedAt'],
]);

// ============================================================
//  工具函数（与 upload.php 一致，复制自那边以便独立运行）
// ============================================================

/** 在目录中按优先级查找主模型文件名（glb > gltf > obj > fbx > 3mf > dae > 3ds > stl > ply > pcd），大小写不敏感 */
function findMainModel($dir) {
    $pref = ['glb', 'gltf', 'obj', 'fbx', '3mf', 'dae', '3ds', 'stl', 'ply', 'pcd'];
    $found = [];
    foreach (array_diff(scandir($dir), ['.', '..']) as $f) {
        if (is_dir($dir . '/' . $f)) continue;
        $e = strtolower(pathinfo($f, PATHINFO_EXTENSION));
        if (in_array($e, $pref, true)) $found[$e] = $f;
    }
    foreach ($pref as $e) {
        if (isset($found[$e])) return $found[$e];
    }
    return '';
}

/** 递归查找目录下所有模型文件（glb/gltf/obj/fbx/3mf/dae/3ds/stl/ply/pcd），返回相对路径数组（按优先级；同优先级按字母序）。
 *  用于支持 iTwin Capture 等分块（Tile）输出：编辑项目时保留 / 重建分块列表，避免丢失多 obj 信息。 */
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

/** 解析前端手动填入的 GPS：仅当 lng 与 lat 均为合法数字时返回 ['lng','lat','alt']，否则 null。
 *  选填：lng/lat 完全留空 → 返回 null（不覆盖，交给 metadata.xml 或保留 null）；
 *        仅填其一或超出经纬度范围 → 同样忽略（前端已做校验，后端兜底）。 */
function parseManualGps() {
    if (!isset($_POST['gpsLng']) && !isset($_POST['gpsLat']) && !isset($_POST['gpsAlt'])) return null;
    $lng = isset($_POST['gpsLng']) ? trim(strval($_POST['gpsLng'])) : '';
    $lat = isset($_POST['gpsLat']) ? trim(strval($_POST['gpsLat'])) : '';
    if ($lng === '' && $lat === '') return null;
    if (!is_numeric($lng) || !is_numeric($lat)) return null;
    $lngF = floatval($lng); $latF = floatval($lat);
    if ($lngF < -180 || $lngF > 180 || $latF < -90 || $latF > 90) return null;
    $altF = (isset($_POST['gpsAlt']) && is_numeric(trim(strval($_POST['gpsAlt'])))) ? floatval(trim(strval($_POST['gpsAlt']))) : 0;
    return ['lng' => $lngF, 'lat' => $latF, 'alt' => $altF];
}

/** 重建 files/manifest.js：子文件夹项目（读 meta.json）+ files/ 根目录扁平历史文件 */
function rebuildManifest($filesDir) {
    $projects = [];
    // 1) 子文件夹项目
    foreach (array_diff(scandir($filesDir), ['.', '..']) as $entry) {
        $full = $filesDir . '/' . $entry;
        if (!is_dir($full)) continue;
        $meta = [];
        $metaPath = $full . '/meta.json';
        if (is_file($metaPath)) {
            $dec = json_decode(file_get_contents($metaPath), true);
            if (is_array($dec)) $meta = $dec;
        }
        $main = isset($meta['main']) ? $meta['main'] : '';
        if ($main === '') $main = findMainModel($full);
        if ($main === '') continue;
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
        if (is_dir($p)) continue;
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
    usort($projects, function ($a, $b) { return strcmp($a['name'], $b['name']); });
    file_put_contents(
        $filesDir . '/manifest.js',
        'window.PROJECT_FILES = ' . json_encode($projects, JSON_UNESCAPED_UNICODE) . ';' . "\n"
    );
}
