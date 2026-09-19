<?php
/**
 * tile_proxy.php — 高德瓦片同源代理（2026-09-08 加服务端磁盘缓存）
 *
 *   作用：浏览器 WebGL 加载跨域图片纹理必须要求 CORS，
 *        而高德瓦片服务器不一定返回 ACAO 头，直接跨域加载会报安全错误。
 *        本代理在服务端拉取瓦片、以同源返回，彻底规避跨域问题。
 *
 *   用法：tile_proxy.php?x=<x>&y=<y>&z=<z>[&style=7|6][&key=<高德Key>][&nocache=1]
 *
 *   缓存：拉过的瓦片存到 cache/tiles/{style}/{z}/{x}/{y}.png，
 *        默认 30 天内直接读磁盘返回（约 5ms），不再回源高德（约 150ms）。
 *        第二次打开同一区域基本秒出；换区域/换 zoom 才需要重新拉。
 *
 *   调试：加 &nocache=1 强制回源；响应头 X-Cache: HIT / MISS 可看是否命中。
 *   清理：直接删掉 cache/tiles 整个目录即可，下次自动重建。
 *
 *   依赖：curl（优先）或 allow_url_fopen（兜底）
 */

// ---------- 顶层文档导航守卫 ----------
// 本脚本的合法调用者只有浏览器的图片加载（WebGL 纹理 / <img>）。
// 但如果这个地址曾经被当成“网页”打开过（地址栏直接访问、历史恢复、崩溃恢复等），
// 浏览器就会把它当成一个文档，之后在该标签页点刷新，刷出来的就是一张空白瓦片图。
// 这里按请求特征区分：顶层网页导航 → 302 回查看器，并带上 restore=1 让它自动回到上次看的模型。
//   图片请求：Sec-Fetch-Dest: image   或 Accept 以 image/ 开头
//   网页导航：Sec-Fetch-Dest: document 或 Accept 以 text/html 开头
$secDest = isset($_SERVER['HTTP_SEC_FETCH_DEST']) ? strtolower(trim($_SERVER['HTTP_SEC_FETCH_DEST'])) : '';
$accept  = isset($_SERVER['HTTP_ACCEPT']) ? strtolower(trim($_SERVER['HTTP_ACCEPT'])) : '';
$isDocNav = ($secDest === 'document')
         || ($secDest === '' && strpos($accept, 'text/html') === 0)
         || ($secDest === '' && $accept === '');          // 极老的浏览器 / 空 Accept：按网页处理
if ($isDocNav && $secDest !== 'image') {
    header('Location: ./index.html?restore=1', true, 302);
    exit;
}

// ---------- 参数 ----------
$x = isset($_GET['x']) ? intval($_GET['x']) : -1;
$y = isset($_GET['y']) ? intval($_GET['y']) : -1;
$z = isset($_GET['z']) ? intval($_GET['z']) : -1;
$key = isset($_GET['key']) ? strval($_GET['key']) : '';
$nocache = (isset($_GET['nocache']) && $_GET['nocache'] === '1');

if ($z < 1 || $z > 20 || $x < 0 || $y < 0) {
    http_response_code(400);
    exit;
}

// style：7=矢量路网（PNG，含 POI 注记），6=卫星影像，8=卫星+路网混合
$style = isset($_GET['style']) ? intval($_GET['style']) : 7;
if ($style !== 6 && $style !== 8) $style = 7;

// ---------- 缓存配置 ----------
$CACHE_TTL = 30 * 86400;   // 服务端缓存有效期：30 天
$cacheDir  = __DIR__ . '/cache/tiles/' . $style . '/' . $z . '/' . $x;
$cacheFile = $cacheDir . '/' . $y . '.png';

// ETag：内容由 style/z/x/y 唯一决定，浏览器可 304 复用（连图片体都不用传）
$etag = '"' . md5($style . '/' . $z . '/' . $x . '/' . $y) . '"';
header('ETag: ' . $etag);
header('Cache-Control: public, max-age=86400');   // 浏览器缓存 1 天

$ifNoneMatch = isset($_SERVER['HTTP_IF_NONE_MATCH']) ? trim($_SERVER['HTTP_IF_NONE_MATCH']) : '';
if ($ifNoneMatch !== '' && $ifNoneMatch === $etag) {
    http_response_code(304);                       // 浏览器已有，直接 304
    exit;
}

/** 发送 PNG 响应（$path 优先按文件流式输出，失败则从内存字符串输出） */
function odmEmitPng($path, $data) {
    header('Content-Type: image/png');
    if ($path !== null && @readfile($path) !== false) return;
    echo $data;
}

// ---------- 1. 命中磁盘缓存 ----------
if (!$nocache && is_file($cacheFile)) {
    $mtime = @filemtime($cacheFile);
    if ($mtime !== false && (time() - $mtime) < $CACHE_TTL) {
        header('X-Cache: HIT');
        odmEmitPng($cacheFile, '');
        exit;
    }
}
header('X-Cache: MISS');

// ---------- 2. 回源高德 ----------
$sub = (($x + $y) % 4) + 1;                       // wprd01~04 多子域轮询，摊开并发
// 关键：scl=1 才返回「含文字注记（路名 + POI 店铺名）」的瓦片；缺省 / scl=2 只有路网线
// size=1 = 256px 瓦片（size 更大单张更重）；scale=1 = 普通清晰度（scale=2 数据量翻 4 倍）
$url = "https://wprd0{$sub}.is.autonavi.com/appmaptile?lang=zh_cn&size=1&scale=1&scl=1&style={$style}&x={$x}&y={$y}&z={$z}";
if ($key !== '') {
    $url .= '&key=' . urlencode($key);
}

/** 拉取远程瓦片，返回 [数据, content-type]；失败返回 [false, ''] */
function odmFetch($url) {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_BINARYTRANSFER => true,
            // 超时放宽：首屏一次要拉几十张，共享主机上 PHP 并发排队本身就耗时，
            // 6s 太紧会砍掉一部分请求（表现就是"有的块出不来"）。宁可等也不要失败。
            CURLOPT_TIMEOUT         => 10,         // 回源总超时
            CURLOPT_CONNECTTIMEOUT  => 5,          // 连接超时
            CURLOPT_ENCODING        => '',         // 允许 gzip 传输
            CURLOPT_USERAGENT       => 'Mozilla/5.0 (compatible; ODMViewer/1.0)',
            CURLOPT_REFERER         => 'https://lbs.amap.com/',
            CURLOPT_HTTPHEADER      => ['Accept: image/png,image/*,*/*'],
        ]);
        $data = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $ctype = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);
        if ($data !== false && $code === 200 && $data !== '') {
            return [$data, $ctype];
        }
        return [false, ''];
    }
    if (ini_get('allow_url_fopen')) {
        $data = @file_get_contents($url);
        if ($data !== false) return [$data, 'image/png'];
    }
    return [false, ''];
}

/** 原子写缓存：先写临时文件再 rename，避免并发请求读到半截文件 */
function odmPutCache($dir, $file, $data) {
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) return false;
    $tmp = $file . '.' . getmypid() . '.tmp';
    if (@file_put_contents($tmp, $data) === false) return false;
    if (!@rename($tmp, $file)) { @unlink($tmp); return false; }
    return true;
}

list($data, $ctype) = odmFetch($url);
if ($data !== false) {
    if (!empty($ctype)) header('Content-Type: ' . $ctype);
    echo $data;
    // 写盘放到输出之后：浏览器先拿到图，不受磁盘 IO 影响
    if (function_exists('fastcgi_finish_request')) { @fastcgi_finish_request(); }
    odmPutCache($cacheDir, $cacheFile, $data);
    exit;
}

// 失败：返回 1x1 浅灰像素，避免 WebGL 纹理黑块（不写缓存，下次还会重试）
http_response_code(200);
header('Content-Type: image/png');
echo base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAAC0lEQVR4nGNgYGAAAAAEAAH2FzhVAAAAAElFTkSuQmCC');
exit;
