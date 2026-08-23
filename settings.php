<?php
/**
 * settings.php — 系统设置接口
 *   GET : 返回 { success, siteName }（不返回密码哈希，安全）
 *   POST: 修改站点设置，需当前密码校验（pwd 字段）。
 *         可选字段：siteName / newPwd / logoPC(文件) / logoMobile(文件)
 *         成功写回 settings.json，并覆盖根目录 LOGO.png / LOGO-home-mobile.png
 *   依赖：settings_lib.php
 */
header('Content-Type: application/json; charset=utf-8');
error_reporting(0);
ini_set('display_errors', '0');
require_once __DIR__ . '/settings_lib.php';

function odmWriteSettings($data) {
    $path = __DIR__ . '/settings.json';
    return @file_put_contents($path, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) !== false;
}

// ---------- GET：返回站点名（公开，不含密码哈希）----------
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $s = odmLoadSettings();
    echo json_encode([
        'success'  => true,
        'siteName' => $s ? (isset($s['siteName']) ? $s['siteName'] : '') : ''
    ]);
    exit;
}

// ---------- POST：保存设置 ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pwd = isset($_POST['pwd']) ? strval($_POST['pwd']) : '';
    if (!hash_equals(odmGetPwdHash(), hash('sha256', $pwd))) {
        echo json_encode(['success' => false, 'error' => '当前密码错误']);
        exit;
    }

    $s = odmLoadSettings();
    if (!$s) {
        $s = ['siteName' => '', 'pwdHash' => odmGetPwdHash()];
    }

    // 站点名称（选填，非空才改）
    if (isset($_POST['siteName'])) {
        $name = trim(strval($_POST['siteName']));
        if ($name !== '') $s['siteName'] = $name;
    }

    // 新密码（选填，非空则更新哈希）
    if (isset($_POST['newPwd']) && strval($_POST['newPwd']) !== '') {
        $s['pwdHash'] = hash('sha256', strval($_POST['newPwd']));
    }

    // LOGO 文件覆盖（PC / 移动端）
    $allowed = ['png', 'jpg', 'jpeg', 'gif', 'svg', 'webp'];
    function odmSaveLogo($field, $dest) {
        global $allowed;
        if (!isset($_FILES[$field]) || $_FILES[$field]['error'] !== UPLOAD_ERR_OK) return;
        $ext = strtolower(pathinfo($_FILES[$field]['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed, true)) return;
        @move_uploaded_file($_FILES[$field]['tmp_name'], __DIR__ . '/' . $dest);
    }
    odmSaveLogo('logoPC', 'LOGO.png');
    odmSaveLogo('logoMobile', 'LOGO-home-mobile.png');

    if (!odmWriteSettings($s)) {
        echo json_encode(['success' => false, 'error' => '写入 settings.json 失败（请检查写权限：chmod 666 settings.json）']);
        exit;
    }
    echo json_encode(['success' => true]);
    exit;
}

echo json_encode(['success' => false, 'error' => '不支持的请求方法']);
