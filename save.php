<?php
/**
 * 保存项目视图状态（裁剪选框 + 测量标注）到项目文件夹。
 * 前端 POST：folder / name / main / state(JSON 字符串) / renderParams(可选，画面调参)
 * 写入：files/<folder>/state.json  或  files/<main去扩展>_state.json（扁平时）
 * 两种模式：
 *   1) 带 state      —— 整份视图保存（裁剪/标注/打点/相机）；未带 renderParams 时保留原文件里的画面调参
 *   2) 只带 renderParams —— 部分更新：仅把画面调参写进已有 state.json，不碰裁剪/标注/相机（跨设备同步用）
 */
header('Content-Type: application/json; charset=utf-8');

function ok($data)  { echo json_encode($data, JSON_UNESCAPED_UNICODE); exit; }
function fail($msg) { echo json_encode(['success' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE); exit; }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('仅支持 POST 请求');

$raw  = file_get_contents('php://input');
$json = json_decode($raw, true);
if (!is_array($json)) fail('无效的 JSON 数据');

/** 写入鉴权：公网部署后所有写操作必须携带管理密码（sha256 校验，与上传/删除同一把钥匙）。
 *  校验失败返回 needAuth 标记，前端据此弹窗询问密码（本机输入一次即缓存）。 */
require_once __DIR__ . '/settings_lib.php';
$_writePwd = isset($json['pwd']) && is_string($json['pwd']) ? $json['pwd'] : '';
if (!hash_equals(odmGetPwdHash(), hash('sha256', $_writePwd))) {
    echo json_encode([
        'success'  => false,
        'error'    => '管理密码错误或缺失（保存到服务器需要授权）',
        'needAuth' => true
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$state = $json['state'] ?? null;

/** 画面调参白名单清洗：数值限范围、开关转 bool */
function cleanRenderParams($rp) {
    if (!is_array($rp)) return null;
    $out = [];
    $numKeys = [
        // —— 基础参数 ——
        'exposure' => [0.2, 3.0], 'aniso' => [0, 1], 'ambient' => [0, 1.5],
        'hemi' => [0, 1.5], 'dir' => [0, 2.0], 'dir2' => [0, 1.0], 'texBright' => [0.5, 1.5],
        // —— 高级参数 ——
        'pixelRatio' => [0, 3], 'fov' => [20, 90], 'fogAmount' => [0, 100],
        'lightAz' => [0, 360], 'lightEl' => [10, 90],
        'metal' => [0, 1], 'rough' => [0, 1],
        'bloomStrength' => [0, 2], 'bright' => [0.5, 1.5], 'contrast' => [0.5, 2], 'saturation' => [0, 2]
    ];
    foreach ($numKeys as $k => $rng) {
        if (isset($rp[$k]) && is_numeric($rp[$k])) {
            $v = floatval($rp[$k]);
            if ($v < $rng[0]) $v = $rng[0];
            if ($v > $rng[1]) $v = $rng[1];
            $out[$k] = round($v, 3);
        }
    }
    $boolKeys = ['envOn', 'mipmap', 'fogOn', 'bgOn', 'metalOn', 'roughOn',
                 'wireframe', 'fxaa', 'bloom', 'ssao'];
    foreach ($boolKeys as $k) {
        if (array_key_exists($k, $rp)) $out[$k] = boolval($rp[$k]);
    }
    // 字符串型：色调映射（枚举）+ 颜色（#rrggbb）
    $tones = ['aces', 'linear', 'reinhard', 'agx'];
    if (isset($rp['toneMapping']) && in_array($rp['toneMapping'], $tones, true)) $out['toneMapping'] = $rp['toneMapping'];
    foreach (['fogColor', 'bgColor'] as $ck) {
        if (isset($rp[$ck]) && is_string($rp[$ck]) && preg_match('/^#[0-9a-fA-F]{6}$/', $rp[$ck])) $out[$ck] = $rp[$ck];
    }
    return $out ?: null;
}
$renderParams = isset($json['renderParams']) && is_array($json['renderParams'])
    ? cleanRenderParams($json['renderParams']) : null;

$folder = trim((string)($json['folder'] ?? ''));
$name   = trim((string)($json['name']   ?? ''));
$main   = trim((string)($json['main']   ?? ''));

$filesDir = __DIR__ . '/files';
if (!is_dir($filesDir)) fail('files 目录不存在');

if ($folder !== '') {
    $folder = basename($folder);                 // 防目录穿越
    $targetDir = $filesDir . '/' . $folder;
    if (!is_dir($targetDir)) fail('项目文件夹不存在: ' . $folder);
    $statePath = $targetDir . '/state.json';
    $relPath   = $folder . '/state.json';
} else {
    // 扁平时：按主文件名（去扩展）存放，避免与模型同名
    $base = $main !== '' ? preg_replace('/\.[^.]+$/', '', basename($main)) : basename($name);
    if ($base === '') fail('无法确定状态文件名（缺少 main/name）');
    $statePath = $filesDir . '/' . $base . '_state.json';
    $relPath   = $base . '_state.json';
}

// 模式 2：只更新画面调参（部分更新，不动裁剪/标注/打点/相机）
if (!is_array($state)) {
    if ($renderParams === null) fail('缺少 state 字段');
    $old = is_file($statePath) ? json_decode(file_get_contents($statePath), true) : [];
    if (!is_array($old)) $old = [];
    $old['renderParams'] = $renderParams;
    $out = json_encode($old, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if (file_put_contents($statePath, $out) === false) fail('写入状态文件失败（请检查目录写权限）');
    ok(['success' => true, 'path' => $relPath, 'partial' => 'renderParams']);
}

// 仅允许保存已知字段，防止前端提交超大/异常数据塞满磁盘
function cleanPoint($p) {
    if (!is_array($p)) return null;
    $x = isset($p['x']) ? floatval($p['x']) : null;
    $y = isset($p['y']) ? floatval($p['y']) : null;
    $z = isset($p['z']) ? floatval($p['z']) : null;
    if ($x === null || $y === null || $z === null) return null;
    return ['x' => round($x, 4), 'y' => round($y, 4), 'z' => round($z, 4)];
}
function cleanState($st) {
    $crop = [];
    if (isset($st['crop']) && is_array($st['crop'])) {
        foreach ($st['crop'] as $p) { $c = cleanPoint($p); if ($c) $crop[] = $c; }
    }
    $measurements = [];
    if (isset($st['measurements']) && is_array($st['measurements'])) {
        foreach ($st['measurements'] as $m) {
            if (!is_array($m) || !isset($m['type']) || !isset($m['points']) || !is_array($m['points'])) continue;
            if ($m['type'] !== 'distance' && $m['type'] !== 'area') continue;
            $pts = [];
            foreach ($m['points'] as $p) { $c = cleanPoint($p); if ($c) $pts[] = $c; }
            if ($m['type'] === 'distance' && count($pts) < 2) continue;
            if ($m['type'] === 'area'     && count($pts) < 3) continue;
            $measurements[] = ['type' => $m['type'], 'points' => $pts];
        }
    }
    // 相机视角：2D（正交）与 3D（透视）是两套独立相机，缩放机制不同
    // （2D 靠 zoom，3D 靠 相机到 target 的距离）。需两套都存 + 当前模式，
    // 否则在 2D 保存、刷新默认进 3D 时，2D 俯视参数套到透视相机上会视角错位。
    $camera = null;
    if (isset($st['camera']) && is_array($st['camera'])) {
        $c = $st['camera'];
        if (isset($c['persp']) || isset($c['ortho'])) {
            // —— 新双相机格式 ——
            $mode = (isset($c['mode']) && $c['mode'] === '2d') ? '2d' : '3d';
            $persp = null;
            if (isset($c['persp']) && is_array($c['persp'])) {
                $pp = cleanPoint($c['persp']['position'] ?? null);
                $pt = cleanPoint($c['persp']['target']   ?? null);
                if ($pp && $pt) {
                    $near = 0.01; $far = 10000;
                    if (isset($c['persp']['near']) && is_numeric($c['persp']['near'])) $near = max(0.0001, floatval($c['persp']['near']));
                    if (isset($c['persp']['far'])  && is_numeric($c['persp']['far']))  $far  = max($near * 2, min(1e9, floatval($c['persp']['far'])));
                    $persp = ['position' => $pp, 'target' => $pt, 'near' => round($near, 6), 'far' => round($far, 2)];
                }
            }
            $ortho = null;
            if (isset($c['ortho']) && is_array($c['ortho'])) {
                $op = cleanPoint($c['ortho']['position'] ?? null);
                $ot = cleanPoint($c['ortho']['target']   ?? null);
                if ($op && $ot) {
                    $zoom = 1;
                    if (isset($c['ortho']['zoom']) && is_numeric($c['ortho']['zoom'])) {
                        $z = floatval($c['ortho']['zoom']);
                        if ($z > 0 && $z < 1000) $zoom = round($z, 4);
                    }
                    $up = ['x' => 0, 'y' => 0, 'z' => 1];
                    if (isset($c['ortho']['up']) && is_array($c['ortho']['up'])) {
                        $ux = isset($c['ortho']['up']['x']) && is_numeric($c['ortho']['up']['x']) ? floatval($c['ortho']['up']['x']) : 0;
                        $uy = isset($c['ortho']['up']['y']) && is_numeric($c['ortho']['up']['y']) ? floatval($c['ortho']['up']['y']) : 0;
                        $uz = isset($c['ortho']['up']['z']) && is_numeric($c['ortho']['up']['z']) ? floatval($c['ortho']['up']['z']) : 1;
                        $up = ['x' => round($ux, 4), 'y' => round($uy, 4), 'z' => round($uz, 4)];
                    }
                    $ortho = ['position' => $op, 'target' => $ot, 'zoom' => $zoom, 'up' => $up];
                }
            }
            if ($persp || $ortho) $camera = ['mode' => $mode, 'persp' => $persp, 'ortho' => $ortho];
        } else {
            // —— 旧扁平格式兼容（{position,target,zoom}）——
            $pos = cleanPoint($c['position'] ?? null);
            $tgt = cleanPoint($c['target']   ?? null);
            if ($pos && $tgt) {
                $zoom = 1;
                if (isset($c['zoom']) && is_numeric($c['zoom'])) {
                    $z = floatval($c['zoom']);
                    if ($z > 0 && $z < 1000) $zoom = round($z, 4);
                }
                $camera = ['position' => $pos, 'target' => $tgt, 'zoom' => $zoom];
            }
        }
    }
    // 打点：保留 id / name / position / normal（normal 缺失则回退默认上方向）
    $points = [];
    if (isset($st['points']) && is_array($st['points'])) {
        foreach ($st['points'] as $p) {
            if (!is_array($p)) continue;
            $pos = cleanPoint($p['position'] ?? null);
            if (!$pos) continue;
            $nrm = cleanPoint($p['normal'] ?? null);
            if (!$nrm) $nrm = ['x' => 0, 'y' => 1, 'z' => 0];
            $id   = isset($p['id'])   && is_numeric($p['id'])   ? intval($p['id'])   : 0;
            $name = isset($p['name']) && is_string($p['name']) && $p['name'] !== '' ? $p['name'] : ('点' . $id);
            $points[] = ['id' => $id, 'name' => $name, 'position' => $pos, 'normal' => $nrm];
        }
    }
    return ['crop' => $crop, 'measurements' => $measurements, 'points' => $points, 'camera' => $camera];
}

$clean = cleanState($state);
// 画面调参：随 state.json 一并保存（前端保存视图不携带时，保留原文件里的参数不被清掉）
if ($renderParams === null && is_file($statePath)) {
    $old = json_decode(file_get_contents($statePath), true);
    if (is_array($old) && isset($old['renderParams']) && is_array($old['renderParams'])) {
        $renderParams = $old['renderParams'];
    }
}
if ($renderParams !== null) $clean['renderParams'] = $renderParams;
$out = json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
if (file_put_contents($statePath, $out) === false) fail('写入状态文件失败（请检查目录写权限）');

ok(['success' => true, 'path' => $relPath, 'count' => count($clean['measurements'])]);
