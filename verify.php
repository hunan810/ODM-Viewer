<?php
/**
 * verify.php — 管理/删除密码校验接口
 *   复用 upload.php 的上传密码哈希（同一把写权限钥匙，即上传时输入的密码）。
 *   接收 POST pwd，后端用 sha256 + hash_equals 校验（防时序攻击），返回 {success:bool}。
 *   前端「管理模式」点击后弹窗输入密码，校验通过才允许进入删除模式。
 */

header('Content-Type: application/json; charset=utf-8');

// 关闭显示错误（防止泄露路径）
error_reporting(0);
ini_set('display_errors', '0');

// 与 upload.php 完全一致的管理密码哈希（sha256('199515')）；源码不出现明文
require_once __DIR__ . '/settings_lib.php';
$UPLOAD_PWD_HASH = odmGetPwdHash();

$submittedPwd = isset($_POST['pwd']) ? strval($_POST['pwd']) : '';

if (hash_equals($UPLOAD_PWD_HASH, hash('sha256', $submittedPwd))) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'error' => '管理密码错误']);
}
