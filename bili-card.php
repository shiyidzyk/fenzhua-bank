<?php
/**
 * 粉爪银行 · 卡包 —— B 站公开资料代理（同源，避免 CORS）
 *
 * 许可：GNU GPL-3.0（全文见同目录 LICENSE.txt）；上游 https://gitee.com/hiteex/haitecard-web3
 * 修改声明：本文件为改造版（modified），修改日期 2026-11-22
 * 用途：前端登录页「哔哩哔哩账号同步」调用本文件取公开主页的昵称与头像。
 *   GET /bili-card.php?mid=<UID>
 *   返回：{ "code":0, "data":{ "card":{ "mid":"", "name":"", "face":"" } } }
 *
 * 说明：
 * - 转发到 https://api.bilibili.com/x/web-interface/card（公开接口，无需登录态）；
 * - 该接口本身不带 CORS 头，浏览器无法直连，所以由同源 PHP 代取；
 * - 结果缓存 5 分钟（系统临时目录），减少对上游的请求；
 * - 仅读取 UID 对应的公开信息，不涉及任何账号凭据。
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, must-revalidate');
header('X-Content-Type-Options: nosniff');

$mid = isset($_GET['mid']) ? preg_replace('/[^0-9]/', '', (string)$_GET['mid']) : '';
if ($mid === '' || strlen($mid) > 12) {
    echo json_encode(array('code' => -1, 'message' => 'bad mid'));
    exit;
}

$upstream = 'https://api.bilibili.com/x/web-interface/card?mid=' . $mid;
$cacheFile = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'haitecard-bili-' . $mid . '.json';
$ttl = 300;

if (is_file($cacheFile) && (time() - @filemtime($cacheFile)) < $ttl) {
    $cached = @file_get_contents($cacheFile);
    if (is_string($cached) && strpos($cached, '"code":0') !== false) {
        echo $cached;
        exit;
    }
}

$ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36';
$body = null;

if (function_exists('curl_init')) {
    $ch = curl_init($upstream);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 8);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_USERAGENT, $ua);
    curl_setopt($ch, CURLOPT_REFERER, 'https://www.bilibili.com/');
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Accept: application/json'));
    if (defined('CURLOPT_ENCODING')) {
        curl_setopt($ch, CURLOPT_ENCODING, '');   // 自动解压
    }
    $body = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    if ($body === false) {
        $body = null;
    }
} else {
    $ctx = stream_context_create(array(
        'http' => array(
            'method' => 'GET',
            'timeout' => 8,
            'header' => "User-Agent: $ua\r\nReferer: https://www.bilibili.com/\r\nAccept: application/json\r\n",
        ),
    ));
    $body = @file_get_contents($upstream, false, $ctx);
}

if (!is_string($body) || $body === '') {
    http_response_code(502);
    echo json_encode(array('code' => -2, 'message' => 'upstream unavailable'));
    exit;
}

$data = json_decode($body, true);
if (!is_array($data) || !isset($data['code'])) {
    http_response_code(502);
    echo json_encode(array('code' => -2, 'message' => 'bad upstream response'));
    exit;
}

if (isset($data['code']) && (int)$data['code'] === 0) {
    @file_put_contents($cacheFile, $body);
}

echo $body;
