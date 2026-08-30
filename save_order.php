<?php
/**
 * save_order.php — 保存首页卡片的自定义排序
 *   前端在「管理模式」下拖动卡片后，把当前顺序（key 数组）POST 过来，
 *   写入 files/home_order.json；首页加载时按此文件排序（无此文件则保持默认：新上传在前）。
 *
 *   key 规则与前端一致：子文件夹项目 = folder 名；扁平文件项目 = "_flat_" + 文件名。
 *
 * 鉴权：与 upload.php / edit.php 共用同一密码哈希（settings.json 的 pwdHash）。
 */
header('Content-Type: application/json; charset=utf-8');
@error_reporting(0);

require_once __DIR__ . '/settings_lib.php';
$UPLOAD_PWD_HASH = odmGetPwdHash();
$submittedPwd = isset($_POST['pwd']) ? strval($_POST['pwd']) : '';
if (!hash_equals($UPLOAD_PWD_HASH, hash('sha256', $submittedPwd))) {
    echo json_encode(['success' => false, 'error' => '密码错误，无法保存排序']);
    exit;
}

// ===== 解析 order（JSON 字符串数组）=====
$orderRaw = isset($_POST['order']) ? strval($_POST['order']) : '';
$order = json_decode($orderRaw, true);
if (!is_array($order)) {
    echo json_encode(['success' => false, 'error' => 'order 参数不合法']);
    exit;
}

$clean = [];
foreach ($order as $k) {
    if (!is_string($k)) continue;
    $k = trim($k);
    // 长度 / 空串 / 路径穿越 / 控制字符过滤（允许中英文、字母数字下划线中划线点）
    if ($k === '' || strlen($k) > 255) continue;
    if (!preg_match('#^[^/\\\\\x00-\x1f]+$#', $k)) continue;
    if (in_array($k, $clean, true)) continue;   // 去重
    if (count($clean) >= 500) break;           // 上限保护
    $clean[] = $k;
}

$filesDir = __DIR__ . '/files';
if (!is_dir($filesDir)) {
    echo json_encode(['success' => false, 'error' => 'files 目录不存在']);
    exit;
}

$ok = file_put_contents(
    $filesDir . '/home_order.json',
    json_encode(['order' => $clean], JSON_UNESCAPED_UNICODE)
);

if ($ok === false) {
    echo json_encode(['success' => false, 'error' => '写入 home_order.json 失败（请检查 files 目录写权限）']);
    exit;
}

echo json_encode(['success' => true, 'count' => count($clean)]);
