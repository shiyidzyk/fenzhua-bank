<?php
/**
 * 粉爪银行 · 第三方授权回调示例（单文件）
 *
 * 用途：前端（index.html 的 SOCIAL.qq.backend / SOCIAL.wx.backend）把 code POST 到这里，
 *       本文件用 AppSecret 在服务端换取 openid / 昵称 / 头像并返回 JSON。
 * 部署：改名 oauth-callback.php 放到站点目录，支持 PHP 的虚拟主机即可；不需要数据库。
 * 安全：AppSecret 只存在服务端，绝不能写进 index.html。
 */

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

/* ========== 1. 配置（建议用环境变量，避免密钥进版本库） ========== */
$CONFIG = [
    'qq' => [
        'appid'  => getenv('QQ_APPID')  ?: '你的QQ互联AppID',
        'secret' => getenv('QQ_SECRET') ?: '你的QQ互联AppKey',
    ],
    'wx' => [
        'appid'  => getenv('WX_APPID')  ?: '你的微信AppID',
        'secret' => getenv('WX_SECRET') ?: '你的微信AppSecret',
    ],
    // 允许的回跳地址白名单，必须与 QQ互联/微信开放平台后台登记的一致
    'allow_redirect' => [
        'https://你的域名/index.html',
    ],
];

/* ========== 2. 读取请求 ========== */
$raw  = file_get_contents('php://input');
$body = json_decode((string)$raw, true);
if (!is_array($body)) {
    $body = $_POST;                       // 兼容表单提交
}

$provider     = strtolower((string)($body['provider'] ?? ''));
$code         = (string)($body['code'] ?? '');
$redirectUri  = (string)($body['redirect_uri'] ?? '');

function fail(string $msg, int $status = 400): void {
    http_response_code($status);
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($code === '')                        fail('缺少 code');
if (!isset($CONFIG[$provider]))          fail('不支持的 provider');
if (strlen($code) > 512)                 fail('code 非法');
if ($redirectUri === '')                 fail('缺少 redirect_uri');
if ($CONFIG['allow_redirect'] && !in_array($redirectUri, $CONFIG['allow_redirect'], true)) {
    fail('redirect_uri 不在白名单内');
}

/* ========== 3. 通用 GET 请求 ========== */
function httpGet(string $url, int $timeout = 8): string {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT      => 'haitecard-oauth/1.0',
        ]);
        $out = curl_exec($ch);
        if ($out === false) {
            $err = curl_error($ch);
            curl_close($ch);
            fail('上游请求失败: ' . $err, 502);
        }
        curl_close($ch);
        return (string)$out;
    }
    $ctx = stream_context_create(['http' => ['timeout' => $timeout, 'ignore_errors' => true]]);
    $out = @file_get_contents($url, false, $ctx);
    if ($out === false) fail('上游请求失败（未启用 curl）', 502);
    return (string)$out;
}

/* QQ 的 JSON 接口偶尔返回 callback({...}); 包裹，需要剥壳 */
function looseJson(string $text): array {
    $text = trim($text);
    $start = strpos($text, '{');
    $end   = strrpos($text, '}');
    if ($start === false || $end === false || $end <= $start) {
        $data = json_decode($text, true);
        return is_array($data) ? $data : [];
    }
    $data = json_decode(substr($text, $start, $end - $start + 1), true);
    return is_array($data) ? $data : [];
}

/* ========== 4. QQ：code → token → openid → 用户信息 ========== */
if ($provider === 'qq') {
    $cfg = $CONFIG['qq'];
    if ($cfg['appid'] === '' || $cfg['secret'] === '') fail('服务端未配置 QQ AppID/AppKey', 500);

    $tokenUrl = 'https://graph.qq.com/oauth2.0/token?' . http_build_query([
        'grant_type'    => 'authorization_code',
        'client_id'     => $cfg['appid'],
        'client_secret' => $cfg['secret'],
        'code'          => $code,
        'redirect_uri'  => $redirectUri,
        'fmt'           => 'json',
    ]);
    $tokenResp = looseJson(httpGet($tokenUrl));
    $token = (string)($tokenResp['access_token'] ?? '');
    if ($token === '') fail('QQ 换取 access_token 失败: ' . json_encode($tokenResp, JSON_UNESCAPED_UNICODE), 502);

    $me = looseJson(httpGet('https://graph.qq.com/oauth2.0/me?' . http_build_query([
        'access_token' => $token,
        'fmt'          => 'json',
    ])));
    $openid = (string)($me['openid'] ?? '');
    if ($openid === '') fail('QQ 获取 openid 失败', 502);

    $info = looseJson(httpGet('https://graph.qq.com/user/get_user_info?' . http_build_query([
        'access_token'       => $token,
        'oauth_consumer_key' => $cfg['appid'],
        'openid'             => $openid,
        'format'             => 'json',
    ])));
    if (($info['ret'] ?? 0) !== 0) fail('QQ 获取用户信息失败: ' . ($info['msg'] ?? 'unknown'), 502);

    echo json_encode([
        'ok'       => true,
        'provider' => 'qq',
        'openid'   => $openid,
        'nickname' => (string)($info['nickname'] ?? 'QQ用户'),
        'avatar'   => (string)($info['figureurl_qq_2'] ?? $info['figureurl_qq_1'] ?? ''),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/* ========== 5. 微信：code → token → 用户信息 ========== */
if ($provider === 'wx') {
    $cfg = $CONFIG['wx'];
    if ($cfg['appid'] === '' || $cfg['secret'] === '') fail('服务端未配置微信 AppID/AppSecret', 500);

    $tokenUrl = 'https://api.weixin.qq.com/sns/oauth2/access_token?' . http_build_query([
        'appid'      => $cfg['appid'],
        'secret'     => $cfg['secret'],
        'code'       => $code,
        'grant_type' => 'authorization_code',
    ]);
    $tokenResp = looseJson(httpGet($tokenUrl));
    if (!empty($tokenResp['errcode'])) {
        fail('微信换取 access_token 失败: ' . ($tokenResp['errmsg'] ?? 'unknown'), 502);
    }
    $wxToken = (string)($tokenResp['access_token'] ?? '');
    $openid  = (string)($tokenResp['openid'] ?? '');
    if ($wxToken === '' || $openid === '') fail('微信返回缺少 access_token/openid', 502);

    $info = looseJson(httpGet('https://api.weixin.qq.com/sns/userinfo?' . http_build_query([
        'access_token' => $wxToken,
        'openid'       => $openid,
        'lang'         => 'zh_CN',
    ])));
    if (!empty($info['errcode'])) {
        fail('微信获取用户信息失败: ' . ($info['errmsg'] ?? 'unknown'), 502);
    }

    echo json_encode([
        'ok'       => true,
        'provider' => 'wx',
        'openid'   => (string)($info['openid'] ?? $openid),
        'nickname' => (string)($info['nickname'] ?? '微信用户'),
        'avatar'   => (string)($info['headimgurl'] ?? ''),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

fail('未处理的分支', 500);
