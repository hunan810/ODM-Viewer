<?php
/**
 * 叠加模型接口（newODM 叠加模型功能）
 *
 *  ① GET  overlay_files.php?action=list&folder=<项目文件夹>
 *     列出 files/<folder>/overlay/ 下已保存的叠加模型文件
 *
 *  ② POST overlay_files.php?action=upload   （表单字段：files[] 多文件 + folder + pwd）
 *     把叠加模型文件保存到 files/<folder>/overlay/，配合同目录的 .mtl / 贴图（jpg/png）
 *     即可让 OBJ 带材质加载；同名文件直接覆盖（重传=更新）
 *
 *  ③ POST overlay_files.php?action=delete   （表单字段：folder + pwd + name）
 *     删除一个叠加模型（整组同基名文件：Model.obj + Model.mtl + Model.png + Model_0.jpg 等），
 *     需管理密码；服务端按基名扫描删除，不依赖前端传的清单
 *
 * 安全：
 *  · 写操作必须带管理密码（sha256 哈希比对，同 upload.php）
 *  · folder 必须是 files/ 下已存在、且只含 [A-Za-z0-9_-] 的目录名，防目录穿越
 *  · 文件名用 basename() 归一 + 扩展名白名单，防任意文件上传 / 删除
 */
header('Content-Type: application/json; charset=utf-8');

$filesDir = __DIR__ . '/files';
$action   = isset($_GET['action']) ? strval($_GET['action']) : '';

/** 校验并取回合法的项目文件夹名；非法返回 null */
function ovFolderName($filesDir) {
    $f = isset($_REQUEST['folder']) ? strval($_REQUEST['folder']) : '';
    if ($f === '' || !preg_match('/^[A-Za-z0-9_\-]+$/D', $f)) return null;   // 不含 / \ .. 等
    if (!is_dir($filesDir . '/' . $f)) return null;                          // 必须是 files/ 下已存在的目录
    return $f;
}

/** 叠加模型允许的扩展名：模型 + 材质 + 贴图 + metadata.xml */
function ovAllowedRegex() {
    return '/\.(glb|gltf|obj|mtl|stl|ply|fbx|3mf|dae|3ds|pcd|jpe?g|png|bmp|tga|tiff?|webp|xml|bin)$/i';
}

/**
 * 收集一个叠加模型关联的全部文件（删除预览 / 实际删除都走这里，保证单一逻辑）
 *  策略：
 *   ① 同基名整组：完全相同，或以 "<base>_ / . / -" 开头（覆盖 Model.jpg / Model_0.jpg / Model.diffuse.png / Model-normal.jpg）
 *   ② 若存在 <base>.mtl，解析其中 map_Kd / map_Bump 等贴图引用，连同这些贴图一起删（覆盖任意命名的贴图）
 *  返回将被删除的文件名数组（不含路径）；兼容 PHP < 8.0（使用 substr 兼容写法，不依赖任何 PHP 8.0+ 专属函数）
 */
function ovCollectModelFiles($dir, $name) {
    $base = strtolower(pathinfo($name, PATHINFO_FILENAME));   // e.g. "Model.obj" → "model"
    $all  = array_diff(scandir($dir), ['.', '..']);
    $found = [];
    // ① 同基名扫描（substr 兼容写法，不依赖任何 PHP 8.0+ 专属函数）
    foreach ($all as $f) {
        $p = $dir . '/' . $f;
        if (!is_file($p)) continue;
        $stem = strtolower(pathinfo($f, PATHINFO_FILENAME));
        if ($stem === $base
            || substr($stem, 0, strlen($base) + 1) === $base . '_'
            || substr($stem, 0, strlen($base) + 1) === $base . '.'
            || substr($stem, 0, strlen($base) + 1) === $base . '-') {
            $found[] = $f;
        }
    }
    // ② 解析 mtl 里的贴图引用（大小写不敏感匹配 <base>.mtl）
    $mtlName = null;
    foreach ($all as $f) { if (strtolower($f) === $base . '.mtl') { $mtlName = $f; break; } }
    if ($mtlName !== null) {
        $txt = @file_get_contents($dir . '/' . $mtlName);
        if ($txt !== false && preg_match_all('/^\s*map_\w+\s+(\S+)/m', $txt, $mm)) {
            foreach ($mm[1] as $ref) {
                $ref = basename($ref);                 // 去掉可能的相对路径前缀
                if ($ref === '' || !preg_match(ovAllowedRegex(), $ref)) continue;
                if (is_file($dir . '/' . $ref) && !in_array($ref, $found, true)) $found[] = $ref;
            }
        }
    }
    return $found;
}

// ============================================================
//  action=list  —— 列出某项目 overlay 文件夹里已保存的模型文件
// ============================================================
if ($action === 'list') {
    $folder = ovFolderName($filesDir);
    if (!$folder) {
        echo json_encode(['success' => false, 'error' => '无效的项目文件夹'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $dir  = $filesDir . '/' . $folder . '/overlay';
    $MODEL = ['glb', 'gltf', 'obj', 'fbx', '3mf', 'dae', '3ds', 'stl', 'ply', 'pcd'];
    $models = [];
    $others = [];   // mtl / 贴图 / xml：前端按需配对（obj 同名 mtl 优先）
    if (is_dir($dir)) {
        foreach (array_diff(scandir($dir), ['.', '..']) as $f) {
            $p = $dir . '/' . $f;
            if (!is_file($p)) continue;
            $e = strtolower(pathinfo($f, PATHINFO_EXTENSION));
            $info = ['name' => $f, 'size' => filesize($p)];
            if (in_array($e, $MODEL, true)) $models[] = $info;
            else $others[] = $info;
        }
    }
    usort($models, function ($a, $b) { return strcmp($a['name'], $b['name']); });
    echo json_encode([
        'success' => true,
        'dir'     => 'files/' . $folder . '/overlay/',
        'models'  => $models,
        'others'  => $others,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ============================================================
//  action=upload —— 保存叠加模型文件（需管理密码）
// ============================================================
if ($action === 'upload') {
    // 密码校验：与 upload.php 同一把钥匙（settings.json 的 pwdHash）
    require_once __DIR__ . '/settings_lib.php';
    $submittedPwd = isset($_POST['pwd']) ? strval($_POST['pwd']) : '';
    if (!hash_equals(odmGetPwdHash(), hash('sha256', $submittedPwd))) {
        echo json_encode(['success' => false, 'needAuth' => true, 'error' => '管理密码错误'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $folder = ovFolderName($filesDir);
    if (!$folder) {
        echo json_encode(['success' => false, 'error' => '无效的项目文件夹（本地打开的模型没有项目目录，无法同步到服务器）'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (!is_dir($filesDir)) @mkdir($filesDir, 0755, true);

    $dir = $filesDir . '/' . $folder . '/overlay';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    if (!is_dir($dir)) {
        echo json_encode(['success' => false, 'error' => '无法创建 overlay 目录：' . $dir], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (!is_writable($dir)) {
        echo json_encode(['success' => false, 'error' => 'overlay 目录不可写，请检查服务器权限'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $raw = isset($_FILES['files']) ? $_FILES['files'] : null;
    if (!$raw) {
        echo json_encode(['success' => false, 'error' => '未收到文件'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    // 单文件时 PHP 不返回数组，统一成数组处理
    if (!is_array($raw['name'])) {
        $raw = [
            'name'     => [$raw['name']],
            'type'     => [$raw['type']],
            'tmp_name' => [$raw['tmp_name']],
            'error'    => [$raw['error']],
            'size'     => [$raw['size']],
        ];
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
    $ALLOWED = ovAllowedRegex();
    $count = count($raw['name']);
    $saved = [];

    for ($i = 0; $i < $count; $i++) {
        $err = $raw['error'][$i];
        if ($err !== UPLOAD_ERR_OK) {
            echo json_encode([
                'success' => false,
                'error'   => ($errMap[$err] ?? ('上传错误码 ' . $err)) . '（' . $raw['name'][$i] . '）',
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $name = basename($raw['name'][$i]);           // 剥离路径，防目录穿越
        if (!preg_match($ALLOWED, $name)) {
            echo json_encode([
                'success' => false,
                'error'   => '不支持的文件类型：' . $name . '（仅模型 / mtl / 贴图 / xml）',
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        // 同名直接覆盖（重传即更新），保证 obj 与 mtl 的引用关系不被改名打乱
        if (!move_uploaded_file($raw['tmp_name'][$i], $dir . '/' . $name)) {
            echo json_encode(['success' => false, 'error' => '保存文件失败：' . $name], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $saved[] = $name;
    }

    echo json_encode([
        'success' => true,
        'count'   => count($saved),
        'files'   => $saved,
        'dir'     => 'files/' . $folder . '/overlay/',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ============================================================
//  action=delete —— 删除一个叠加模型（同名同基的所有文件：Model.obj + Model.mtl + Model.png 等）
//  需管理密码（同 upload）。安全策略同 upload：folder 校验 + 文件名白名单 + 服务端按基名扫描，
//  不依赖前端传的清单。前端 confirm 仅用于 UX 提示。
// ============================================================
if ($action === 'delete') {
    $folder = ovFolderName($filesDir);
    if (!$folder) {
        echo json_encode(['success' => false, 'error' => '无效的项目文件夹'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $dir = $filesDir . '/' . $folder . '/overlay';
    if (!is_dir($dir)) {
        echo json_encode(['success' => true, 'deleted' => [], 'dir' => 'files/' . $folder . '/overlay/'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $name = isset($_POST['name']) ? basename(strval($_POST['name'])) : '';
    if ($name === '' || !preg_match(ovAllowedRegex(), $name)) {
        echo json_encode(['success' => false, 'error' => '非法的文件名：' . $name], JSON_UNESCAPED_UNICODE);
        exit;
    }
    // 预览模式：不校验密码，返回将删除的文件清单（前端 confirm 用，作为唯一权威来源；dryRun 可放 URL 或 POST，故用 $_REQUEST 读取）
    if (!empty($_REQUEST['dryRun'])) {
        $will = ovCollectModelFiles($dir, $name);
        echo json_encode(['success' => true, 'dryRun' => true, 'name' => $name, 'willDelete' => $will], JSON_UNESCAPED_UNICODE);
        exit;
    }
    // 真删：校验密码（与 upload 同一把）
    require_once __DIR__ . '/settings_lib.php';
    $submittedPwd = isset($_POST['pwd']) ? strval($_POST['pwd']) : '';
    if (!hash_equals(odmGetPwdHash(), hash('sha256', $submittedPwd))) {
        echo json_encode(['success' => false, 'needAuth' => true, 'error' => '管理密码错误'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $files  = ovCollectModelFiles($dir, $name);
    $deleted = [];
    $failed  = [];
    foreach ($files as $f) {
        $p = $dir . '/' . $f;
        if (!is_file($p)) continue;
        if (@unlink($p)) $deleted[] = $f;
        else             $failed[]  = $f;
    }
    if (!$deleted && !$failed) {
        echo json_encode(['success' => false, 'error' => '没找到「' . strtolower(pathinfo($name, PATHINFO_FILENAME)) . '」相关的文件（可能已被删除）'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    echo json_encode([
        'success' => !$failed,
        'deleted' => $deleted,
        'failed'  => $failed,
        'dir'     => 'files/' . $folder . '/overlay/',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['success' => false, 'error' => '未知操作（list / upload / delete）'], JSON_UNESCAPED_UNICODE);
