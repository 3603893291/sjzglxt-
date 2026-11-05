<?php
declare(strict_types=1);

namespace app\service;

use app\model\User;
use think\facade\Cache;
use think\facade\Db;
use think\facade\Log;

/**
 * 未晞通行证 OAuth 客户端（v5.36.6）
 *
 * 本站是通行证的「接入应用」：授权码 + PKCE + id_token + UserInfo。
 * 成员原有的密码注册/登录/找回/改密全部保留；通行证只是多一种登录方式，
 * 第一次使用时必须先用本站账号密码确认身份，再建立绑定。
 *
 * 配置全部是平台级 settings（tenant_id=0），密钥永远只留在服务端：
 *   passport_enabled / passport_issuer / passport_client_id /
 *   passport_client_secret / passport_redirect_uri / passport_scope
 */
class PassportClient
{
    public const ISSUER_DEFAULT = 'https://sp.dpx1.icu';
    public const SCOPE_DEFAULT  = 'openid profile email';

    /** 授权请求在会话里的有效期（秒） */
    public const STATE_TTL = 600;

    /** 首次进入绑定页的有效期（秒） */
    public const CLAIM_TTL = 600;

    /** 测试夹具：只允许本机回环地址走 http / 自定义端口 */
    public static function testing(): bool
    {
        $v = env('APP_TESTING', false);
        return $v === true || $v === 1 || $v === '1' || $v === 'true';
    }

    public static function enabled(): bool
    {
        return Setting::getPlatform('passport_enabled', '0') === '1';
    }

    public static function issuer(): string
    {
        $v = trim(Setting::getPlatform('passport_issuer', ''));
        return $v !== '' ? rtrim($v, '/') : self::ISSUER_DEFAULT;
    }

    public static function clientId(): string
    {
        return trim(Setting::getPlatform('passport_client_id', ''));
    }

    public static function clientSecret(): string
    {
        return (string)Setting::getPlatform('passport_client_secret', '');
    }

    public static function redirectUri(): string
    {
        $v = trim(Setting::getPlatform('passport_redirect_uri', ''));
        return $v !== '' ? $v : self::defaultCallback();
    }

    public static function scope(): string
    {
        $v = trim(Setting::getPlatform('passport_scope', ''));
        return $v !== '' ? $v : self::SCOPE_DEFAULT;
    }

    /** 根路径回调地址（所有战队共用这一条，租户信息在会话 state 里） */
    public static function defaultCallback(): string
    {
        $https  = request()->isSsl();
        $host   = (string)request()->host(true);
        $port   = (int)request()->port();
        $scheme = $https ? 'https' : 'http';
        if ($port > 0 && !(($https && $port === 443) || (!$https && $port === 80))) {
            $host .= ':' . $port;
        }
        return $scheme . '://' . $host . '/passport/callback';
    }

    /** 字段齐了、表也在（不要求已开启，后台测试连接用） */
    public static function ready(): bool
    {
        return self::clientId() !== ''
            && self::clientSecret() !== ''
            && self::redirectUri() !== ''
            && self::issuer() !== ''
            && self::tableReady();
    }

    public static function configured(): bool
    {
        return self::enabled() && self::ready();
    }

    /** 给登录/注册页的公开信息（不含任何密钥） */
    public static function publicConfig(): array
    {
        $enabled = self::enabled();
        $ready   = self::ready();
        $message = '';
        if ($enabled && !$ready) {
            $message = '未晞通行证登录还没有配置完整，请联系平台管理员。';
        }
        return [
            'enabled'   => $enabled,
            'ready'     => $ready,
            'start_url' => TenantContext::isPlatform()
                ? '/passport/platform/start'
                : TenantContext::base() . '/passport/start',
            'message'   => $message,
        ];
    }

    /** 管理端回显（密钥打码） */
    public static function adminConfig(): array
    {
        $secret = self::clientSecret();
        return [
            'enabled'          => self::enabled(),
            'issuer'           => self::issuer(),
            'client_id'        => self::clientId(),
            'secret_set'       => $secret !== '',
            'secret_hint'      => $secret !== '' ? str_repeat('•', 8) . substr($secret, -4) : '',
            'redirect_uri'     => self::redirectUri(),
            'default_callback' => self::defaultCallback(),
            'scope'            => self::scope(),
            'table_ready'      => self::tableReady(),
            'testing'          => self::testing(),
        ];
    }

    public static function tableReady(): bool
    {
        try {
            Db::name('passport_links')->count();
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** 平台后台「测试连接」：只看各端点是否可达，不读取/输出任何响应正文 */
    public static function probeEndpoints(): array
    {
        $issuer = self::issuer();
        $targets = [
            'discovery' => '/.well-known/openid-configuration',
            'jwks'      => '/.well-known/jwks.json',
            'authorize' => '/oauth/authorize',
            'userinfo'  => '/oauth/userinfo',
        ];
        $out = [];
        foreach ($targets as $key => $path) {
            [$status, $raw, $err] = self::http('GET', $issuer . $path, ['Accept: application/json']);
            $out[$key] = [
                'status' => $status,
                'error'  => $err !== '' ? '连接失败' : '',
            ];
        }
        return $out;
    }

    /** 校验 issuer（保存与使用前都跑一次）：只允许 https，测试夹具放行本机回环 http */
    public static function validateIssuer(string $url): ?string
    {
        $url = rtrim(trim($url), '/');
        if ($url === '') {
            return '请填写通行证站点地址';
        }
        $p = parse_url($url);
        if ($p === false || empty($p['host'])) {
            return '地址无法解析';
        }
        if (!empty($p['user']) || !empty($p['pass']) || !empty($p['query']) || !empty($p['fragment'])) {
            return '地址不能带账号、查询串或锚点';
        }
        $scheme = strtolower((string)($p['scheme'] ?? ''));
        $host   = strtolower((string)$p['host']);
        $port   = (int)($p['port'] ?? 0);
        $loop   = $host === '127.0.0.1' || $host === 'localhost' || $host === '::1' || $host === '[::1]';
        if (self::testing() && $loop && in_array($scheme, ['http', 'https'], true)) {
            return null;
        }
        if ($scheme !== 'https') {
            return '正式环境只允许 https 地址';
        }
        if ($loop || strpos($host, '.local') !== false || strpos($host, '.internal') !== false) {
            return '不允许指向本机或内网地址';
        }
        if (filter_var(trim($host, '[]'), FILTER_VALIDATE_IP)) {
            $ip = trim($host, '[]');
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return '不允许指向内网或保留地址';
            }
        }
        if ($port !== 0 && $port !== 443) {
            return '正式环境只允许 443 端口';
        }
        return null;
    }

    /** 校验回调地址：必须是绝对地址、路径恰为 /passport/callback */
    public static function validateRedirectUri(string $url): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return '请填写回调地址';
        }
        $p = parse_url($url);
        if ($p === false || empty($p['host']) || empty($p['scheme'])) {
            return '回调地址必须是完整的 http(s) 地址';
        }
        $scheme = strtolower((string)$p['scheme']);
        $host   = strtolower((string)$p['host']);
        $loop   = $host === '127.0.0.1' || $host === 'localhost';
        if (!($scheme === 'https' || (self::testing() && $loop && $scheme === 'http'))) {
            return '回调地址必须是 https（测试夹具允许本机 http）';
        }
        if (!empty($p['query']) || !empty($p['fragment']) || !empty($p['user']) || !empty($p['pass'])) {
            return '回调地址不能带账号、查询串或锚点';
        }
        if (($p['path'] ?? '') !== '/passport/callback') {
            return '回调地址的路径必须正好是 /passport/callback';
        }
        return null;
    }

    /** 生成授权跳转地址；$challenge 为 PKCE S256 */
    public static function authorizeUrl(string $state, string $nonce, string $challenge): string
    {
        $qs = http_build_query([
            'client_id'             => self::clientId(),
            'redirect_uri'          => self::redirectUri(),
            'response_type'         => 'code',
            'scope'                 => self::scope(),
            'state'                 => $state,
            'nonce'                 => $nonce,
            'code_challenge'        => $challenge,
            'code_challenge_method' => 'S256',
        ], '', '&', PHP_QUERY_RFC3986);
        return self::issuer() . '/oauth/authorize?' . $qs;
    }

    public static function codeChallenge(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    public static function randomToken(int $bytes = 32): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }

    /**
     * 授权码换令牌。
     * @return array{ok:bool,error?:string,data?:array}
     */
    public static function exchangeCode(string $code, string $verifier): array
    {
        $body = http_build_query([
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'redirect_uri'  => self::redirectUri(),
            'client_id'     => self::clientId(),
            'client_secret' => self::clientSecret(),
            'code_verifier' => $verifier,
        ], '', '&', PHP_QUERY_RFC3986);
        [$status, $raw, $err] = self::http('POST', self::issuer() . '/oauth/token', [
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json',
        ], $body);
        if ($err !== '') {
            return ['ok' => false, 'error' => '通行证站点连接失败：' . $err];
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return ['ok' => false, 'error' => '通行证站点返回了无法解析的令牌响应（HTTP ' . $status . '）'];
        }
        if ($status !== 200 || !empty($data['error'])) {
            $e = (string)($data['error'] ?? ('HTTP ' . $status));
            return ['ok' => false, 'error' => '通行证授权失败：' . $e];
        }
        if (empty($data['access_token']) || !is_string($data['access_token'])) {
            return ['ok' => false, 'error' => '通行证没有返回 access_token'];
        }
        if (empty($data['id_token']) || !is_string($data['id_token'])) {
            return ['ok' => false, 'error' => '通行证没有返回 id_token'];
        }
        return ['ok' => true, 'data' => $data];
    }

    /**
     * 校验 id_token：签名（JWKS 可用时必须是有效 RS256）+ iss/aud/exp/iat/nonce。
     * 线上通行证若尚未发布 JWKS（404），签名无法验证，记录一条告警后
     * 继续用 introspection + UserInfo 的 TLS 结果作身份依据；JWKS 一旦可用就严格验签。
     *
     * @return array{ok:bool,error?:string,claims?:array,signature?:string}
     */
    public static function verifyIdToken(string $jwt, string $nonce): array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            return ['ok' => false, 'error' => 'id_token 格式不正确'];
        }
        $header  = json_decode(self::b64urlDecode($parts[0]), true);
        $claims  = json_decode(self::b64urlDecode($parts[1]), true);
        $sig     = self::b64urlDecode($parts[2]);
        if (!is_array($header) || !is_array($claims) || $sig === '') {
            return ['ok' => false, 'error' => 'id_token 内容无法解析'];
        }
        if (($header['alg'] ?? '') !== 'RS256') {
            return ['ok' => false, 'error' => 'id_token 签名算法不是 RS256'];
        }

        $signature = 'unavailable';
        $kid = (string)($header['kid'] ?? '');
        $keys = self::jwksKeys();
        if ($keys === null) {
            self::warnOnce('jwks_unavailable', '通行证 JWKS 不可用，本次只校验 id_token 声明并用 introspection + UserInfo 兜底');
        } else {
            $candidates = [];
            $pick = static function (array $list, string $kid): array {
                $out = [];
                foreach ($list as $jwk) {
                    if (!is_array($jwk) || ($jwk['kty'] ?? '') !== 'RSA') {
                        continue;
                    }
                    if ($kid !== '' && (string)($jwk['kid'] ?? '') !== $kid) {
                        continue;
                    }
                    $pem = self::jwkToPem($jwk);
                    if ($pem !== null) {
                        $out[] = $pem;
                    }
                }
                return $out;
            };
            $candidates = $pick($keys, $kid);
            // 未知 kid 时强制刷新一次 JWKS（密钥轮换后不能一直用旧缓存）
            if ($candidates === [] && $kid !== '') {
                $fresh = self::jwksKeys(true);
                if (is_array($fresh)) {
                    $keys = $fresh;
                    $candidates = $pick($keys, $kid);
                }
            }
            $okSig = false;
            foreach ($candidates as $pem) {
                if (openssl_verify($parts[0] . '.' . $parts[1], $sig, $pem, OPENSSL_ALGO_SHA256) === 1) {
                    $okSig = true;
                    break;
                }
            }
            if (!$okSig) {
                return ['ok' => false, 'error' => 'id_token 签名校验失败'];
            }
            $signature = 'verified';
        }

        $now = time();
        if (!hash_equals(self::issuer(), rtrim((string)($claims['iss'] ?? ''), '/'))) {
            return ['ok' => false, 'error' => 'id_token 的签发方不匹配'];
        }
        $aud = $claims['aud'] ?? '';
        $audOk = is_array($aud) ? in_array(self::clientId(), $aud, true) : ((string)$aud === self::clientId());
        if (!$audOk) {
            return ['ok' => false, 'error' => 'id_token 的接收方不匹配'];
        }
        if (!isset($claims['exp']) || (int)$claims['exp'] < $now - 30) {
            return ['ok' => false, 'error' => 'id_token 已过期'];
        }
        if (!isset($claims['iat']) || (int)$claims['iat'] > $now + 120) {
            return ['ok' => false, 'error' => 'id_token 签发时间异常'];
        }
        if (isset($claims['nbf']) && (int)$claims['nbf'] > $now + 120) {
            return ['ok' => false, 'error' => 'id_token 尚未生效'];
        }
        if (!hash_equals($nonce, (string)($claims['nonce'] ?? ''))) {
            return ['ok' => false, 'error' => 'id_token 的 nonce 不匹配'];
        }
        $sub = (string)($claims['sub'] ?? '');
        if ($sub === '' || strlen($sub) > 64) {
            return ['ok' => false, 'error' => 'id_token 缺少有效的 sub'];
        }
        return ['ok' => true, 'claims' => $claims, 'signature' => $signature];
    }

    /** @return array{ok:bool,error?:string,data?:array} */
    public static function userinfo(string $accessToken): array
    {
        [$status, $raw, $err] = self::http('GET', self::issuer() . '/oauth/userinfo', [
            'Authorization: Bearer ' . $accessToken,
            'Accept: application/json',
        ]);
        if ($err !== '') {
            return ['ok' => false, 'error' => '通行证用户信息接口连接失败：' . $err];
        }
        $data = json_decode($raw, true);
        if ($status !== 200 || !is_array($data)) {
            return ['ok' => false, 'error' => '通行证用户信息接口返回异常（HTTP ' . $status . '）'];
        }
        if (empty($data['sub']) || !is_string($data['sub']) || strlen($data['sub']) > 64) {
            return ['ok' => false, 'error' => '通行证用户信息缺少有效的 sub'];
        }
        return ['ok' => true, 'data' => $data];
    }

    /**
     * introspection：确认令牌仍有效。接口不可用时只告警不阻断（UserInfo 已证明令牌可用）。
     * @return array{ok:bool,error?:string,skipped?:bool}
     */
    public static function introspect(string $accessToken): array
    {
        $basic = base64_encode(self::clientId() . ':' . self::clientSecret());
        [$status, $raw, $err] = self::http('POST', self::issuer() . '/oauth/introspect', [
            'Authorization: Basic ' . $basic,
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json',
        ], http_build_query(['token' => $accessToken], '', '&', PHP_QUERY_RFC3986));
        if ($err !== '') {
            self::warnOnce('introspect_down', '通行证 introspection 接口不可用：' . $err);
            return ['ok' => true, 'skipped' => true];
        }
        $data = json_decode($raw, true);
        if ($status !== 200 || !is_array($data)) {
            self::warnOnce('introspect_bad', '通行证 introspection 返回异常（HTTP ' . $status . '）');
            return ['ok' => true, 'skipped' => true];
        }
        if (array_key_exists('active', $data) && !$data['active']) {
            return ['ok' => false, 'error' => '通行证令牌已失效'];
        }
        return ['ok' => true];
    }

    /** 按 (tenant, sub) 找已绑定的本站账号；没有绑定返回 null */
    public static function linkedUser(int $tenantId, string $sub): ?User
    {
        try {
            $row = Db::name('passport_links')
                ->where('tenant_id', $tenantId)
                ->where('passport_sub', $sub)->find();
        } catch (\Throwable $e) {
            throw new \RuntimeException('passport_links 表不存在，请先执行 v5.36.6 补库脚本', 0, $e);
        }
        if (!$row) {
            return null;
        }
        return User::find((int)$row['user_id']);
    }

    /**
     * 通行证身份已验过，但还没有绑定本站账号。
     * 不再按邮箱/用户名自动绑定，也不自动建号：由用户在绑定页输入本站账号密码确认。
     *
     * @return array{ok:bool,error?:string,user?:User,needs_bind?:bool}
     */
    public static function resolveUser(int $tenantId, string $sub): array
    {
        $user = self::linkedUser($tenantId, $sub);
        if ($user) {
            return self::usableUser($user);
        }
        return ['ok' => false, 'needs_bind' => true];
    }

    /** 首次进入：输入本站账号密码，确认身份后把这枚通行证绑定上去 */
    public static function bindWithPassword(int $tenantId, string $sub, string $username, string $password): array
    {
        $username = trim($username);
        if ($username === '' || $password === '') {
            return ['ok' => false, 'error' => '请输入本站账号和密码'];
        }
        $user = User::where('username', $username)->find();
        if (!$user || !password_verify($password, (string)$user->password)) {
            return ['ok' => false, 'bad_credentials' => true, 'error' => '账号或密码不正确'];
        }
        if ($user->status === 'pending') {
            return ['ok' => false, 'error' => '该账号正在等待队长审核，通过后再绑定'];
        }
        if ($user->status !== 'approved') {
            return ['ok' => false, 'error' => '该账号当前不是正常状态，请联系队长处理'];
        }
        $err = self::bindUser($tenantId, (int)$user->id, $sub);
        if ($err !== null) {
            return ['ok' => false, 'error' => $err];
        }
        return ['ok' => true, 'user' => $user];
    }

    /** 首绑/已登录绑定：一个通行证账号与一个本站账号在本队内一一对应 */
    public static function bindUser(int $tenantId, int $userId, string $sub): ?string
    {
        if ($sub === '' || strlen($sub) > 64) {
            return '通行证身份标识异常，请联系管理员';
        }
        $bySub = Db::name('passport_links')->where('tenant_id', $tenantId)->where('passport_sub', $sub)->find();
        if ($bySub && (int)$bySub['user_id'] !== $userId) {
            return '这个通行证账号已经绑定了本站另一个成员，请联系管理员处理';
        }
        $byUser = Db::name('passport_links')->where('tenant_id', $tenantId)->where('user_id', $userId)->find();
        if ($byUser) {
            if ((string)$byUser['passport_sub'] === $sub) {
                return null;
            }
            return '当前账号已经绑定了另一个通行证账号，请先在个人中心解除绑定';
        }
        return self::insertLink($tenantId, $userId, $sub);
    }

    public static function unbindUser(int $tenantId, int $userId): bool
    {
        try {
            Db::name('passport_links')->where('tenant_id', $tenantId)->where('user_id', $userId)->delete();
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public static function bindingOf(int $tenantId, int $userId): ?array
    {
        try {
            $row = Db::name('passport_links')->where('tenant_id', $tenantId)->where('user_id', $userId)->find();
        } catch (\Throwable $e) {
            return null;
        }
        if (!$row) {
            return null;
        }
        return [
            'sub_masked' => self::maskSub((string)$row['passport_sub']),
            'created_at' => (string)($row['created_at'] ?? ''),
        ];
    }

    public static function maskSub(string $sub): string
    {
        $len = strlen($sub);
        if ($len <= 4) {
            return str_repeat('•', max(4, $len));
        }
        if ($len <= 8) {
            return substr($sub, 0, 3) . str_repeat('•', max(2, $len - 5)) . substr($sub, -2);
        }
        return substr($sub, 0, 4) . str_repeat('•', 6) . substr($sub, -4);
    }

    /** 安全站内返回地址：只接受本站相对路径 */
    public static function safeReturn(string $return, int $tenantId): string
    {
        $base = $tenantId > 0
            ? (string)Db::name('tenants')->where('id', $tenantId)->value('slug')
            : '';
        $fallback = $base !== '' ? '/' . $base . '/app' : '/login';
        $return = trim($return);
        if ($return === '' || $return[0] !== '/' || substr($return, 0, 2) === '//'
            || strpos($return, '\\') !== false || preg_match('/[\r\n]/', $return)) {
            return $fallback;
        }
        // 只允许落在本租户目录下，避免登录后被带去别的战队/根路径
        if ($base !== '' && $return !== '/' . $base && strpos($return, '/' . $base . '/') !== 0) {
            return $fallback;
        }
        return $return;
    }

    // ==================== 内部 ====================

    /** 已绑定账号按状态给出可登录/等待/封禁 */
    private static function usableUser(User $user): array
    {
        if (!$user->status || $user->status === 'banned') {
            return ['ok' => false, 'error' => '账号已被封禁，请联系管理员'];
        }
        if ($user->status === 'pending') {
            return ['ok' => false, 'error' => '账号正在等待队长审核，通过后即可使用'];
        }
        return ['ok' => true, 'user' => $user];
    }

    private static function insertLink(int $tenantId, int $userId, string $sub): ?string
    {
        try {
            Db::name('passport_links')->insert([
                'tenant_id'    => $tenantId,
                'user_id'      => $userId,
                'passport_sub' => $sub,
                'created_at'   => date('Y-m-d H:i:s'),
                'updated_at'   => date('Y-m-d H:i:s'),
            ]);
            return null;
        } catch (\Throwable $e) {
            // 并发下唯一键冲突：再读一次，已经绑上就算成功
            try {
                $row = Db::name('passport_links')->where('tenant_id', $tenantId)->where('passport_sub', $sub)->find();
                if ($row && (int)$row['user_id'] === $userId) {
                    return null;
                }
            } catch (\Throwable $e2) {
                // 落到下面统一报错
            }
            Log::warning('[passport] insertLink tenant=' . $tenantId . ' user=' . $userId . ' : '
                . get_class($e) . ' ' . $e->getMessage());
            return '绑定失败';
        }
    }

    /** @return array|null 键列表；抓不到返回 null（区别于「抓到了但没有 RSA 键」） */
    private static function jwksKeys(bool $force = false): ?array
    {
        $url = self::issuer() . '/.well-known/jwks.json';
        $cacheKey = 'passport_jwks_' . md5($url);
        if (!$force) {
            $cached = Cache::get($cacheKey);
            if (is_array($cached)) {
                return $cached['keys'] ?? [];
            }
        }
        [$status, $raw, $err] = self::http('GET', $url, ['Accept: application/json']);
        if ($err !== '' || $status !== 200) {
            return null;
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || !isset($data['keys']) || !is_array($data['keys'])) {
            return null;
        }
        Cache::set($cacheKey, ['keys' => $data['keys']], 3600);
        return $data['keys'];
    }

    private static function jwkToPem(array $jwk): ?string
    {
        if (($jwk['kty'] ?? '') !== 'RSA' || empty($jwk['n']) || empty($jwk['e'])) {
            return null;
        }
        $n = self::b64urlDecode((string)$jwk['n']);
        $e = self::b64urlDecode((string)$jwk['e']);
        if ($n === '' || $e === '') {
            return null;
        }
        $modulus  = "\x00" . ltrim($n, "\x00");
        $exponent = ltrim($e, "\x00");
        if ($exponent === '') {
            return null;
        }
        $rsaKey    = self::asn1(0x30, self::asn1(0x02, $modulus) . self::asn1(0x02, $exponent));
        $bitString = "\x00" . $rsaKey;
        $algId     = self::asn1(0x30, "\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00");
        $der       = self::asn1(0x30, $algId . self::asn1(0x03, $bitString));
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    private static function asn1(int $tag, string $value): string
    {
        $len = strlen($value);
        if ($len < 0x80) {
            $l = chr($len);
        } else {
            $tmp = ltrim(pack('N', $len), "\x00");
            $l = chr(0x80 | strlen($tmp)) . $tmp;
        }
        return chr($tag) . $l . $value;
    }

    private static function b64urlDecode(string $s): string
    {
        $s = strtr($s, '-_', '+/');
        $pad = strlen($s) % 4;
        if ($pad > 0) {
            $s .= str_repeat('=', 4 - $pad);
        }
        $out = base64_decode($s, true);
        return $out === false ? '' : $out;
    }

    /** @return array{0:int,1:string,2:string} [状态码, 正文, 错误] */
    private static function http(string $method, string $url, array $headers = [], ?string $body = null): array
    {
        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 12,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT      => 'dpx1-team-passport/5.36.9',
        ];
        if (defined('CURLOPT_PROTOCOLS')) {
            $opts[CURLOPT_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
        }
        if ($method === 'POST') {
            $opts[CURLOPT_POST] = true;
            $opts[CURLOPT_POSTFIELDS] = $body ?? '';
        } elseif ($method !== 'GET') {
            $opts[CURLOPT_CUSTOMREQUEST] = $method;
            if ($body !== null) {
                $opts[CURLOPT_POSTFIELDS] = $body;
            }
        }
        curl_setopt_array($ch, $opts);
        $raw = curl_exec($ch);
        if ($raw === false) {
            $err = curl_error($ch);
            curl_close($ch);
            return [0, '', $err !== '' ? $err : '请求失败'];
        }
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return [$status, (string)$raw, ''];
    }

    /** 同一条告警一小时内只写一次，避免授权服务短暂异常刷满日志 */
    private static function warnOnce(string $key, string $msg): void
    {
        $cacheKey = 'passport_warn_' . $key;
        if (Cache::get($cacheKey)) {
            return;
        }
        Cache::set($cacheKey, 1, 3600);
        try {
            Log::warning('[passport] ' . $msg);
        } catch (\Throwable $e) {
            // 日志失败不影响登录
        }
    }
}
