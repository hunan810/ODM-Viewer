<?php
/**
 * delete.php — 删除 files/ 下的项目
 *   · 新格式（子文件夹项目）：POST folder=子文件夹名 → 递归删除整个文件夹
 *   · 历史扁平文件：POST folder=（空） + name=模型名（或 main=主文件名）
 *     删除 files/{name}.{glb|gltf|obj} 及其同名 .mtl
 * 删除后自动重写 files/manifest.js，保持前端清单同步。
 */

header('Content-Type: application/json; charset=utf-8');

// 关闭显示错误（防止泄露路径）
error_reporting(0);
ini_set('display_errors', '0');

$filesDir = __DIR__ . DIRECTORY_SEPARATOR . 'files' . DIRECTORY_SEPARATOR;
$manifestFile = $filesDir . 'manifest.js';

// 读取参数
$folder = isset($_POST['folder']) ? trim(strval($_POST['folder'])) : '';
$name   = isset($_POST['name'])   ? trim(strval($_POST['name']))   : '';
$main   = isset($_POST['main'])   ? trim(strval($_POST['main']))   : '';

// 防目录穿越 / 特殊字符
if ($folder !== '' && preg_match('/[\/\\\\\.\:\*\?\"<>\|]/', $folder)) {
    echo json_encode(['success' => false, 'error' => '非法文件夹名']);
    exit;
}
if ($name !== '' && preg_match('/[\/\\\\\.\:\*\?\"<>\|]/', $name)) {
    echo json_encode(['success' => false, 'error' => '非法文件名']);
    exit;
}

if (!is_writable($filesDir)) {
    echo json_encode(['success' => false, 'error' => 'files 目录不可写，请设置权限为 755/777']);
    exit;
}

// 管理/删除密码校验（复用 upload.php 的上传密码哈希，与 verify.php 同一把钥匙）
// 防止绕过前端直删：即使拿到 delete.php 地址，无正确密码也无法删除
require_once __DIR__ . '/settings_lib.php';
$UPLOAD_PWD_HASH = odmGetPwdHash();
$submittedPwd = isset($_POST['pwd']) ? strval($_POST['pwd']) : '';
if (!hash_equals($UPLOAD_PWD_HASH, hash('sha256', $submittedPwd))) {
    echo json_encode(['success' => false, 'error' => '管理密码错误']);
    exit;
}

$deletedLabel = '';

if ($folder !== '') {
    // 新格式：删除整个项目子文件夹
    $target = $filesDir . $folder;
    if (!is_dir($target)) {
        echo json_encode(['success' => false, 'error' => '项目文件夹不存在：' . $folder]);
        exit;
    }
    $deletedLabel = $folder;
    rrmdir($target);
} else {
    // 历史扁平文件：按 main 或 name 定位
    $target = null;
    if ($main !== '') {
        $candidate = $filesDir . basename($main);
        if (is_file($candidate)) {
            $target = $candidate;
        }
    }
    if ($target === null && $name !== '') {
        foreach (['glb', 'gltf', 'obj'] as $ext) {
            $p = $filesDir . basename($name) . '.' . $ext;
            if (is_file($p)) {
                $target = $p;
                break;
            }
        }
    }
    if ($target === null || !is_file($target)) {
        echo json_encode(['success' => false, 'error' => '文件不存在：' . ($name !== '' ? $name : $main)]);
        exit;
    }
    $deletedLabel = basename($target);
    @unlink($target);
    // OBJ 工程：连带删除同名 .mtl（贴图若与主名不同则保留，避免误删）
    $mtlPath = $filesDir . pathinfo($target, PATHINFO_FILENAME) . '.mtl';
    if (is_file($mtlPath)) {
        @unlink($mtlPath);
    }
}

// 重写 manifest.js：扫描 files/ 下剩余项目
rebuildManifest($filesDir);

echo json_encode(['success' => true, 'deleted' => $deletedLabel]);

// ============================================================
//  工具函数
// ============================================================

/** 递归删除目录及其内容 */
function rrmdir($dir) {
    if (!is_dir($dir)) {
        return;
    }
    $items = array_diff(scandir($dir), ['.', '..']);
    foreach ($items as $item) {
        $p = $dir . '/' . $item;
        if (is_dir($p)) {
            rrmdir($p);
        } else {
            @unlink($p);
        }
    }
    @rmdir($dir);
}

/** 重建 files/manifest.js（与 upload.php 完全一致，含 cover/objs/originGps/uploadedAt） */
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
            continue;
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
    foreach (glob($filesDir . '/*.{glb,gltf,obj}', GLOB_BRACE) as $p) {
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

    usort($projects, function ($a, $b) {
        return strcmp($a['name'], $b['name']);
    });

    file_put_contents(
        $filesDir . '/manifest.js',
        'window.PROJECT_FILES = ' . json_encode($projects, JSON_UNESCAPED_UNICODE) . ';' . "\n"
    );
}

/** 在目录中查找主模型文件名（glb > gltf > obj），大小写不敏感 */
function findMainModel($dir) {
    $pref = ['glb', 'gltf', 'obj'];
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
