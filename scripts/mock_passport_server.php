<?php
/**
 * 测试专用：未晞通行证 mock 服务（v5.36.6）
 * 用法：php -S 127.0.0.1:8898 scripts/mock_passport_server.php
 *
 * 只实现接入方用到的端点：authorize / token / userinfo / introspect / jwks / discovery。
 * 授权页不渲染，自动同意并按 config 里的资料发码；config、code/token 与临时 RSA 密钥
 * 都放 runtime/ 下，不进版本库，也不进热更新包。
 */
declare(strict_types=1);

$RUNTIME = dirname(__DIR__) . '/runtime';
$BASE = 'http://' . ($_SERVER['HTTP_HOST'] ?? '127.0.0.1:8898');
$CONFIG = $RUNTIME . '/_mock_passport_config.json';
$STORE  = $RUNTIME . '/_mock_passport_store.json';
$KEY1   = $RUNTIME . '/_mock_passport_main.pem';
$KEY2   = $RUNTIME . '/_mock_passport_other.pem';

function b64u(string $s): string
{
    return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
}
function b64u_dec(string $s): string
{
    $s = strtr($s, '-_', '+/');
    $p = strlen($s) % 4;
    if ($p > 0) { $s .= str_repeat('=', 4 - $p); }
    $v = base64_decode($s, true);
    return $v === false ? '' : $v;
}
function json_out(int $status, $data): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function redirect_to(string $url): void
{
    http_response_code(302);
    header('Location: ' . $url);
    exit;
}
function config(): array
{
    global $CONFIG;
    $c = is_file($CONFIG) ? json_decode((string)file_get_contents($CONFIG), true) : [];
    return is_array($c) ? $c : [];
}
function with_store(callable $fn)
{
    global $STORE;
    $fp = fopen($STORE, 'c+');
    if (!$fp) { return $fn([]); }
    flock($fp, LOCK_EX);
    $raw = stream_get_contents($fp);
    $data = json_decode($raw !== '' ? $raw : '{}', true);
    if (!is_array($data)) { $data = []; }
    $out = $fn($data);
    rewind($fp);
    ftruncate($fp, 0);
    fwrite($fp, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    return $out;
}
function rsa_key(string $path)
{
    if (!is_file($path)) {
        $opts = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
        // Windows 便携版 PHP 默认找不到 openssl.cnf，显式指到发行包自带的配置
        $conf = dirname(PHP_BINARY) . '/extras/ssl/openssl.cnf';
        if (is_file($conf)) {
            $opts['config'] = $conf;
        }
        $key = openssl_pkey_new($opts);
        if (!$key) { return null; }
        $pem = '';
        openssl_pkey_export($key, $pem, null, $opts);
        file_put_contents($path, $pem);
    }
    $pem = (string)file_get_contents($path);
    return openssl_pkey_get_private($pem) ?: null;
}
function jwks(string $pemPath): array
{
    $key = rsa_key($pemPath);
    if (!$key) { return ['keys' => []]; }
    $d = openssl_pkey_get_details($key);
    return ['keys' => [[
        'kty' => 'RSA',
        'use' => 'sig',
        'alg' => 'RS256',
        'kid' => 'mock-1',
        'n'   => b64u($d['rsa']['n']),
        'e'   => b64u($d['rsa']['e']),
    ]]];
}
function sign_jwt(array $claims, string $pemPath): string
{
    $header = ['alg' => 'RS256', 'typ' => 'JWT', 'kid' => 'mock-1'];
    $h = b64u(json_encode($header));
    $p = b64u(json_encode($claims, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $key = rsa_key($pemPath);
    $sig = '';
    if ($key) { openssl_sign($h . '.' . $p, $sig, $key, OPENSSL_ALGO_SHA256); }
    return $h . '.' . $p . '.' . b64u($sig);
}

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$cfg = config();

if ($path === '/control/health') {
    json_out(200, ['ok' => true, 'issuer' => $BASE]);
}
if ($path === '/control/reset') {
    file_put_contents($STORE, '{}');
    json_out(200, ['ok' => true]);
}

if ($path === '/.well-known/openid-configuration') {
    json_out(200, [
        'issuer'                 => $BASE,
        'authorization_endpoint' => $BASE . '/oauth/authorize',
        'token_endpoint'         => $BASE . '/oauth/token',
        'userinfo_endpoint'      => $BASE . '/oauth/userinfo',
        'jwks_uri'               => $BASE . '/.well-known/jwks.json',
        'introspection_endpoint' => $BASE . '/oauth/introspect',
        'response_types_supported' => ['code'],
        'subject_types_supported'  => ['public'],
        'id_token_signing_alg_values_supported' => ['RS256'],
    ]);
}
if ($path === '/.well-known/jwks.json') {
    if (!empty($cfg['no_jwks'])) { json_out(404, ['error' => 'not_found']); }
    json_out(200, jwks($KEY1));
}

if ($path === '/oauth/authorize') {
    if (!empty($cfg['authorize_deny']) || ($_GET['mock_action'] ?? '') === 'deny') {
        $sep = strpos((string)$cfg['redirect_uri'], '?') === false ? '?' : '&';
        redirect_to($cfg['redirect_uri'] . $sep . http_build_query([
            'error' => 'access_denied',
            'state' => (string)($_GET['state'] ?? ''),
        ]));
    }
    foreach (['client_id', 'redirect_uri', 'response_type', 'scope', 'state', 'nonce', 'code_challenge'] as $need) {
        if ((string)($_GET[$need] ?? '') === '') {
            json_out(400, ['error' => 'invalid_request', 'missing' => $need]);
        }
    }
    if ((string)$_GET['client_id'] !== (string)($cfg['client_id'] ?? '')) {
        json_out(400, ['error' => 'invalid_client']);
    }
    if ((string)$_GET['redirect_uri'] !== (string)($cfg['redirect_uri'] ?? '')) {
        json_out(400, ['error' => 'invalid_redirect_uri']);
    }
    if ((string)($_GET['code_challenge_method'] ?? '') !== 'S256') {
        json_out(400, ['error' => 'invalid_request', 'reason' => 'S256 required']);
    }

    $profile = [
        'sub'                => (string)($cfg['sub'] ?? 'sub-alpha'),
        'name'               => (string)($cfg['name'] ?? '测试成员'),
        'preferred_username' => (string)($cfg['preferred_username'] ?? '10001'),
        'email'              => (string)($cfg['email'] ?? 'alpha@example.com'),
        'email_verified'     => (bool)($cfg['email_verified'] ?? true),
        'picture'            => (string)($cfg['picture'] ?? ''),
    ];
    foreach (['sub', 'name', 'preferred_username', 'email'] as $k) {
        if (isset($_GET['mock_' . $k])) { $profile[$k] = (string)$_GET['mock_' . $k]; }
    }
    if (isset($_GET['mock_email_verified'])) {
        $profile['email_verified'] = $_GET['mock_email_verified'] === '1';
    }

    $code = bin2hex(random_bytes(16));
    $q = $_GET;
    with_store(function (array &$s) use ($code, $profile, $q) {
        $s['codes'][$code] = [
            'client_id'    => (string)$q['client_id'],
            'redirect_uri' => (string)$q['redirect_uri'],
            'challenge'    => (string)$q['code_challenge'],
            'nonce'        => (string)$q['nonce'],
            'profile'      => $profile,
            'expires'      => time() + 300,
        ];
        return null;
    });

    $sep = strpos((string)$_GET['redirect_uri'], '?') === false ? '?' : '&';
    redirect_to($_GET['redirect_uri'] . $sep . http_build_query([
        'code'  => $code,
        'state' => (string)$_GET['state'],
    ]));
}

if ($path === '/oauth/token') {
    if ($method !== 'POST') { json_out(405, ['error' => 'invalid_request']); }
    $clientId = (string)($_POST['client_id'] ?? '');
    $secret   = (string)($_POST['client_secret'] ?? '');
    $code     = (string)($_POST['code'] ?? '');
    $redirect = (string)($_POST['redirect_uri'] ?? '');
    $verifier = (string)($_POST['code_verifier'] ?? '');
    if ($clientId !== (string)($cfg['client_id'] ?? '') || $secret !== (string)($cfg['client_secret'] ?? '')) {
        json_out(401, ['error' => 'invalid_client']);
    }
    $row = with_store(function (array &$s) use ($code) {
        $row = $s['codes'][$code] ?? null;
        if ($row) { unset($s['codes'][$code]); }
        return $row;
    });
    if (!$row || (int)$row['expires'] < time()) {
        json_out(400, ['error' => 'invalid_grant']);
    }
    if ($redirect !== (string)$row['redirect_uri']) {
        json_out(400, ['error' => 'invalid_grant', 'reason' => 'redirect_uri']);
    }
    $challenge = b64u(hash('sha256', $verifier, true));
    if (!hash_equals((string)$row['challenge'], $challenge)) {
        json_out(400, ['error' => 'invalid_grant', 'reason' => 'pkce']);
    }

    $access = b64u(random_bytes(24));
    $refresh = b64u(random_bytes(24));
    with_store(function (array &$s) use ($access, $refresh, $row) {
        $s['tokens'][$access] = [
            'profile' => $row['profile'],
            'expires' => time() + 7200,
            'active'  => true,
        ];
        $s['refresh'][$refresh] = $row['profile'];
        return null;
    });

    $claims = [
        'iss'                => $BASE,
        'aud'                => (string)($cfg['client_id'] ?? ''),
        'sub'                => (string)$row['profile']['sub'],
        'iat'                => time(),
        'exp'                => time() + 7200,
        'nonce'              => (string)$row['nonce'],
        'name'               => (string)$row['profile']['name'],
        'preferred_username' => (string)$row['profile']['preferred_username'],
        'email'              => (string)$row['profile']['email'],
        'email_verified'     => (bool)$row['profile']['email_verified'],
    ];
    $signedWith = !empty($cfg['bad_signature']) ? $KEY2 : $KEY1;
    json_out(200, [
        'access_token'  => $access,
        'token_type'    => 'Bearer',
        'expires_in'    => 7200,
        'refresh_token' => $refresh,
        'scope'         => 'openid profile email',
        'id_token'      => sign_jwt($claims, $signedWith),
    ]);
}

if ($path === '/oauth/userinfo') {
    $auth = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? '');
    if (stripos($auth, 'Bearer ') !== 0) { json_out(401, ['error' => 'invalid_token']); }
    $token = trim(substr($auth, 7));
    $row = with_store(function (array &$s) use ($token) {
        return $s['tokens'][$token] ?? null;
    });
    if (!$row || (int)$row['expires'] < time()) { json_out(401, ['error' => 'invalid_token']); }
    $p = $row['profile'];
    json_out(200, [
        'sub'                => (string)$p['sub'],
        'name'               => (string)$p['name'],
        'preferred_username' => (string)$p['preferred_username'],
        'picture'            => (string)$p['picture'],
        'email'              => (string)$p['email'],
        'email_verified'     => (bool)$p['email_verified'],
    ]);
}

if ($path === '/oauth/introspect') {
    if ($method !== 'POST') { json_out(405, ['error' => 'invalid_request']); }
    $auth = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? '');
    $okBasic = false;
    if (stripos($auth, 'Basic ') === 0) {
        $pair = base64_decode(substr($auth, 6), true);
        $okBasic = $pair === ((string)($cfg['client_id'] ?? '') . ':' . (string)($cfg['client_secret'] ?? ''));
    }
    if (!$okBasic) { json_out(401, ['error' => 'invalid_client']); }
    $token = (string)($_POST['token'] ?? '');
    $row = with_store(function (array &$s) use ($token) {
        return $s['tokens'][$token] ?? null;
    });
    $active = $row && (int)$row['expires'] > time() && !empty($cfg['introspect_active']);
    json_out(200, [
        'active' => (bool)$active,
        'sub'    => $row ? (string)$row['profile']['sub'] : '',
        'scope'  => 'openid profile email',
    ]);
}

json_out(404, ['error' => 'not_found', 'path' => $path]);
