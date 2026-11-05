<?php
declare (strict_types = 1);

namespace app\middleware;

use app\service\Setting;
use app\service\TenantContext;
use think\facade\Cache;
use think\facade\Db;

/**
 * 私有接口密钥鉴权（v5.4.0 引入 / v5.10.0 安全加固）
 *
 * 请求头 X-Api-Key → api_keys 表（只存 sha256，status=1 才放行）：
 *   scope=platform → 等同平台超管（赛事总线最高控制权）
 *   scope=tenant   → 载入绑定管理员身份（TenantContext 切到该租户，current_user() 认绑定用户）
 *   scope=external → 标记外部战队身份（开源单租户站接入，无平台用户）
 *
 * v5.10.0 加固：
 *   ① 全量请求日志（api_logs 表，成功/失败都记）
 *   ② 双层限频：每 Key（默认 60s/60 次）+ 每 IP（默认 60s/300 次）
 *   ③ 认证失败连击锁定：同 IP 5 分钟内 20 次失败 → 15 分钟拒服
 *   ④ hash_equals 常量时间比较（防未来引入的字符串比对分支）
 *   ⑤ 限频参数走 settings，平台超管可在线调优（api_rate_window / api_rate_limit / api_ip_rate_limit）
 */
class ApiKeyAuth
{
    /** 租户 Key 绑定的用户 id（current_user() 优先认它） */
    public static $apiUserId = 0;

    /** 平台 Key 标志（is_platform_super() 优先认它） */
    public static $platformSuper = false;

    /** 外部 Key 绑定的外部战队 id（0=非外部身份） */
    public static $externalTeamId = 0;

    /** 当前命中的 Key 行 id */
    public static $keyId = 0;

    /** 每 Key 限频：窗口秒数 / 窗口内上限（settings 可覆盖） */
    protected const DEFAULT_RATE_WINDOW = 60;
    protected const DEFAULT_RATE_LIMIT  = 60;

    /** 每 IP 限频（防单 IP 用多枚 Key 洗接口） */
    protected const DEFAULT_IP_RATE_LIMIT = 300;

    /** 认证失败连击锁定：窗口 / 阈值 / 锁定时长（秒） */
    protected const FAIL_WINDOW   = 300;   // 5 分钟
    protected const FAIL_LOCK_AT  = 20;    // 20 次失败
    protected const FAIL_LOCK_SEC = 900;   // 锁定 15 分钟

    public function handle($request, \Closure $next)
    {
        // v5.7.1(M7)：常驻内存运行时（Swoole/Workerman 等）静态属性会跨请求残留，
        // 每次进入先无条件清零，杜绝上一请求的平台超管身份串到下一请求
        self::$apiUserId = 0;
        self::$platformSuper = false;
        self::$externalTeamId = 0;
        self::$keyId = 0;

        $startMs = (int)(microtime(true) * 1000);
        $ip      = self::clientIp($request);
        $method  = strtoupper((string)$request->method());
        $path    = self::safePath((string)$request->pathinfo());

        // 与平台 API 一致：仅根路径受理，租户目录下的同路径一律 404
        if (!TenantContext::isPlatform()) {
            self::writeLog(0, '', 'platform', 0, $method, $path, 404, 0, 'not_platform_context', $ip, $startMs);
            return api_err('接口不存在', 404, 404);
        }

        // ① IP 连击锁定检查（无 Key 也拦，防枚举扫描）
        $lockKey = 'openapi_lock_' . md5($ip);
        if (Cache::get($lockKey)) {
            self::writeLog(0, '', 'platform', 0, $method, $path, 423, 0, 'ip_locked', $ip, $startMs);
            return api_err('请求源已被临时锁定，请稍后再试', 423, 423);
        }

        $rawKey = (string)$request->header('X-Api-Key', '');
        $key    = trim($rawKey);
        if ($key === '' || strlen($key) > 128 || !preg_match('/^[A-Za-z0-9_\-\.]+$/', $key)) {
            self::recordFailure($ip);
            self::writeLog(0, self::prefixOf($rawKey), 'platform', 0, $method, $path, 401, 0,
                $key === '' ? 'missing_key' : 'invalid_key_format', $ip, $startMs);
            return api_err('缺少 API Key（请求头 X-Api-Key）', 401, 401);
        }

        // ② IP 级限频（在 Key 校验之前，防被打穿数据库）
        $ipLimit = self::intSetting('api_ip_rate_limit', self::DEFAULT_IP_RATE_LIMIT);
        $ipKey   = 'openapi_ip_' . md5($ip);
        $ipN     = (int)Cache::get($ipKey, 0);
        if ($ipN >= $ipLimit) {
            self::writeLog(0, self::prefixOf($key), 'platform', 0, $method, $path, 429, 0, 'ip_rate_limited', $ip, $startMs);
            return api_err('来源请求过于频繁，请稍后再试', 429, 429);
        }
        rate_bump($ipKey, self::DEFAULT_RATE_WINDOW);   // v5.15.0(S-M4)：原子自增

        // ③ 密钥查询（sha256 索引查表；DB 已用等值比较，此处再 hash_equals 做纵深防御）
        $computed = hash('sha256', $key);
        $row = Db::name('api_keys')->where('key_hash', $computed)->where('status', 1)->find();
        if (!$row || !hash_equals((string)$row['key_hash'], $computed)) {
            self::recordFailure($ip);
            self::writeLog(0, self::prefixOf($key), 'platform', 0, $method, $path, 401, 0,
                $row ? 'key_disabled' : 'key_not_found', $ip, $startMs);
            return api_err('API Key 无效或已停用', 401, 401);
        }

        // ④ 每 Key 限频（可 settings 调优）
        $window   = self::intSetting('api_rate_window', self::DEFAULT_RATE_WINDOW);
        $rateLim  = self::intSetting('api_rate_limit',  self::DEFAULT_RATE_LIMIT);
        $rk       = 'openapi_rate_' . $row['id'];
        $n        = (int)Cache::get($rk, 0);
        if ($n >= $rateLim) {
            self::writeLog((int)$row['id'], self::prefixOf($key), (string)$row['scope'],
                (int)($row['tenant_id'] ?? 0), $method, $path, 429, 0, 'key_rate_limited', $ip, $startMs);
            return api_err('请求过于频繁，请稍后再试', 429, 429);
        }
        rate_bump($rk, $window);   // v5.15.0(S-M4)：原子自增

        // ⑤ 载入身份上下文
        self::$keyId = (int)$row['id'];
        $scope       = (string)$row['scope'];
        $tenantId    = (int)($row['tenant_id'] ?? 0);
        $failReason  = '';
        $httpStatus  = 200;
        $result      = 1;

        if ($scope === 'platform') {
            self::$platformSuper = true;
        } elseif ($scope === 'tenant') {
            $u = Db::name('users')->where('id', (int)$row['bound_user_id'])->find();
            if (!$u || $u['status'] !== 'approved') {
                $failReason = 'bound_user_unavailable';
                $result = 0; $httpStatus = 401;
            } else {
                $tenant = Db::name('tenants')->where('id', $tenantId)->find();
                if (!$tenant || $tenant['status'] !== 'approved') {
                    $failReason = 'bound_tenant_unavailable';
                    $result = 0; $httpStatus = 401;
                } else {
                    TenantContext::set([
                        'id'     => (int)$tenant['id'],
                        'slug'   => (string)$tenant['slug'],
                        'name'   => (string)$tenant['name'],
                        'logo'   => (string)$tenant['logo'],
                        'status' => (string)$tenant['status'],
                    ]);
                    self::$apiUserId = (int)$u['id'];
                }
            }
        } elseif ($scope === 'external') {
            $team = Db::name('external_teams')->where('id', (int)$row['external_team_id'])->find();
            if (!$team || (int)$team['status'] !== 1) {
                $failReason = 'external_team_unavailable';
                $result = 0; $httpStatus = 401;
            } else {
                self::$externalTeamId = (int)$team['id'];
            }
        } else {
            $failReason = 'invalid_scope';
            $result = 0; $httpStatus = 401;
        }

        if ($result === 0) {
            self::writeLog((int)$row['id'], self::prefixOf($key), $scope, $tenantId,
                $method, $path, $httpStatus, 0, $failReason, $ip, $startMs);
            return api_err('API Key 身份不可用', $httpStatus, $httpStatus);
        }

        // 认证成功：清零该 IP 的失败计数（避免正常调用被误锁）
        Cache::delete('openapi_fail_' . md5($ip));

        // 静默写最后使用时间 + 成功日志（失败不阻断）
        try {
            Db::name('api_keys')->where('id', $row['id'])->update(['last_used_at' => date('Y-m-d H:i:s')]);
        } catch (\Throwable $e) {
        }
        self::writeLog((int)$row['id'], self::prefixOf($key), $scope, $tenantId,
            $method, $path, 200, 1, '', $ip, $startMs);

        return $next($request);
    }

    // ---------- 内部工具 ----------

    /** 记录一次认证失败；累计到阈值后触发 IP 锁定 */
    protected static function recordFailure(string $ip): void
    {
        $fk = 'openapi_fail_' . md5($ip);
        $n  = rate_bump($fk, self::FAIL_WINDOW);   // v5.15.0(S-M4)：原子自增
        if ($n >= self::FAIL_LOCK_AT) {
            Cache::set('openapi_lock_' . md5($ip), 1, self::FAIL_LOCK_SEC);
            Cache::delete($fk);
            \think\facade\Log::warning('[ApiKeyAuth] IP locked due to repeated auth failures: ' . $ip);
        }
    }

    /** 写 api_logs（失败静默，绝不阻断业务响应） */
    protected static function writeLog(
        int $keyId, string $prefix, string $scope, int $tenantId,
        string $method, string $path, int $status, int $result,
        string $failReason, string $ip, int $startMs
    ): void {
        try {
            $duration = max(0, (int)(microtime(true) * 1000) - $startMs);
            Db::name('api_logs')->insert([
                'api_key_id'  => $keyId,
                'key_prefix'  => mb_substr($prefix, 0, 16),
                'scope'       => mb_substr($scope, 0, 16),
                'tenant_id'   => $tenantId,
                'method'      => mb_substr($method, 0, 8),
                'path'        => mb_substr($path, 0, 255),
                'status'      => $status,
                'result'      => $result,
                'fail_reason' => mb_substr($failReason, 0, 128),
                'ip'          => mb_substr($ip, 0, 45),
                'duration_ms' => $duration,
                'created_at'  => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            // 日志表可能未创建（老库未升级），静默降级
        }
    }

    /** Key 前 8 位前缀（不落全 Key，仅便于溯源） */
    protected static function prefixOf(string $key): string
    {
        return $key === '' ? '' : substr($key, 0, 8);
    }

    /** 客户端 IP（优先用 common.php 的 client_ip()，兜底 REMOTE_ADDR） */
    protected static function clientIp($request): string
    {
        if (function_exists('client_ip')) {
            $ip = (string)client_ip();
            if ($ip !== '') return $ip;
        }
        return (string)($request->ip() ?: ($_SERVER['REMOTE_ADDR'] ?? ''));
    }

    /** 去掉查询串，防日志表被塞爆 */
    protected static function safePath(string $path): string
    {
        $path = strtok($path, '?') ?: $path;
        return '/' . ltrim($path, '/');
    }

    protected static function intSetting(string $key, int $default): int
    {
        try {
            $v = (int)Setting::getPlatform($key, (string)$default);
            return $v > 0 ? $v : $default;
        } catch (\Throwable $e) {
            return $default;
        }
    }
}
