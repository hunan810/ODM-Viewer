<?php
/**
 * settings_lib.php — 共享读取站点配置（站点名 + 密码哈希）
 *   被 upload.php / edit.php / delete.php / upload_chunk.php / verify.php / settings.php 复用，
 *   实现「改密码」全网生效：所有写操作都从 settings.json 读取同一份 pwdHash。
 */

function odmLoadSettings() {
    $path = __DIR__ . '/settings.json';
    if (!is_file($path)) return null;
    $txt = @file_get_contents($path);
    if ($txt === false) return null;
    $data = json_decode($txt, true);
    return is_array($data) ? $data : null;
}

function odmGetPwdHash() {
    $s = odmLoadSettings();
    if ($s && !empty($s['pwdHash'])) return (string)$s['pwdHash'];
    // 兜底：settings.json 丢失时回退到初始哈希，保证系统仍可登录（与历史版本一致）
    return '240be518fabd2724ddb6f04eeb1da5967448d7e831c08c8fa822809f74c720a9';
}
