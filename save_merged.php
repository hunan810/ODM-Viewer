<?php
header('Content-Type: application/json; charset=utf-8');

function sm_fail($m) {
    echo json_encode(['success' => false, 'error' => $m]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sm_fail('仅支持 POST');
}

// 写入鉴权：合并 GLB 落盘同样需要管理密码（与 save.php / upload.php 同一把钥匙）
require_once __DIR__ . '/settings_lib.php';
$_writePwd = isset($_POST['pwd']) ? strval($_POST['pwd']) : '';
if (!hash_equals(odmGetPwdHash(), hash('sha256', $_writePwd))) {
    echo json_encode([
        'success'  => false,
        'error'    => '管理密码错误或缺失（写入需授权）',
        'needAuth' => true
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$folder = isset($_POST['folder']) ? trim($_POST['folder']) : '';
$name   = isset($_POST['name'])   ? trim($_POST['name'])   : '';
$main   = isset($_POST['main'])   ? trim($_POST['main'])   : '';

// 仅允许文件夹名 / 主文件名作定位（basename 防目录穿越，name 同样处理）
$folder = $folder !== '' ? basename($folder) : '';
$main   = $main   !== '' ? basename($main)   : '';
$name   = $name   !== '' ? basename($name)   : '';

if ($folder === '' && $name === '' && $main === '') {
    sm_fail('缺少项目标识');
}

$root = __DIR__ . '/files/';
if (!is_dir($root)) sm_fail('files 目录不存在');

if ($folder !== '') {
    $dir = $root . $folder;
} else {
    // 兜底：用 main 去扩展名（扁平时多 obj 也可能以 main 定位）
    $base = $main !== '' ? preg_replace('/\.[^.]+$/', '', $main) : $name;
    $dir  = $root . $base;
}

if (!is_dir($dir)) sm_fail('项目目录不存在：' . $dir);

if (!isset($_FILES['blob']) || $_FILES['blob']['error'] !== UPLOAD_ERR_OK) {
    sm_fail('未收到合并文件（blob）');
}

$blob = $_FILES['blob'];
// 校验扩展名（必须是 glb 二进制）
$tmpName = $blob['name'] ?? 'merged.glb';
if (!preg_match('/\.glb$/i', $tmpName)) {
    sm_fail('合并文件必须是 .glb');
}

$dest = $dir . '/merged.glb';
if (!move_uploaded_file($blob['tmp_name'], $dest)) {
    sm_fail('保存合并文件失败（无写入权限？）');
}

// 可选：把 merged 标记写回 meta.json，便于未来清单直接携带（不强制）
$metaPath = $dir . '/meta.json';
if (is_file($metaPath)) {
    $meta = json_decode(file_get_contents($metaPath), true);
    if (is_array($meta)) {
        $meta['merged'] = true;
        @file_put_contents($metaPath, json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }
}

echo json_encode([
    'success' => true,
    'path'    => $dest,
    'size'    => filesize($dest)
]);
