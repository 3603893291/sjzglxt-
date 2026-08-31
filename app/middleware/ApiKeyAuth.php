<?php
declare (strict_types = 1);

namespace app\middleware;

use app\service\TenantContext;
use think\facade\Cache;
use think\facade\Db;

/**
 * 私有接口密钥鉴权（v5.4.0 跨战队约战开放 API）
 *
 * 请求头 X-Api-Key → api_keys 表（只存 sha256，status=1 才放行）：
 *   scope=platform → 等同平台超管（赛事总线最高控制权）
 *   scope=tenant   → 载入绑定管理员身份（TenantContext 切到该租户，current_user() 认绑定用户）
 *   scope=external → 标记外部战队身份（开源单租户站接入，无平台用户）
 *
 * 限频：每枚 Key 10 秒窗口内最多 30 次请求（防轮询滥用），超限 429。
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

    /** 每 Key 限频：窗口秒数 / 窗口内上限 */
    protected const RATE_WINDOW = 10;
    protected const RATE_LIMIT  = 30;

    public function handle($request, \Closure $next)
    {
        // v5.7.1(M7)：常驻内存运行时（Swoole/Workerman 等）静态属性会跨请求残留，
        // 每次进入先无条件清零，杜绝上一请求的平台超管身份串到下一请求
        self::$apiUserId = 0;
        self::$platformSuper = false;
        self::$externalTeamId = 0;
        self::$keyId = 0;

        // 与平台 API 一致：仅根路径受理，租户目录下的同路径一律 404
        if (!TenantContext::isPlatform()) {
            return api_err('接口不存在', 404, 404);
        }
        $key = trim((string)$request->header('X-Api-Key', ''));
        if ($key === '' || strlen($key) > 128) {
            return api_err('缺少 API Key（请求头 X-Api-Key）', 401, 401);
        }

        $row = Db::name('api_keys')->where('key_hash', hash('sha256', $key))->where('status', 1)->find();
        if (!$row) {
            return api_err('API Key 无效或已停用', 401, 401);
        }

        // 每 Key 限频（计数窗口）
        $rk = 'openapi_rate_' . $row['id'];
        $n  = (int)Cache::get($rk, 0);
        if ($n >= self::RATE_LIMIT) {
            return api_err('请求过于频繁，请稍后再试', 429, 429);
        }
        Cache::set($rk, $n + 1, self::RATE_WINDOW);

        // 载入身份上下文
        self::$keyId = (int)$row['id'];
        if ($row['scope'] === 'platform') {
            self::$platformSuper = true;
        } elseif ($row['scope'] === 'tenant') {
            // 此时还是平台上下文，User 模型的租户作用域会滤掉用户，直接按 id 查库校验；
            // 下面 TenantContext::set 后再 new User 建实例（模型内取数仍走租户作用域）
            $u = Db::name('users')->where('id', (int)$row['bound_user_id'])->find();
            if (!$u || $u['status'] !== 'approved') {
                return api_err('Key 绑定的用户账号不可用', 401, 401);
            }
            $tenant = Db::name('tenants')->where('id', (int)$row['tenant_id'])->find();
            if (!$tenant || $tenant['status'] !== 'approved') {
                return api_err('Key 绑定的战队站点不可用', 401, 401);
            }
            // 切到该租户上下文（模型租户作用域 / settings / 权限判定随之生效）
            TenantContext::set([
                'id'     => (int)$tenant['id'],
                'slug'   => (string)$tenant['slug'],
                'name'   => (string)$tenant['name'],
                'logo'   => (string)$tenant['logo'],
                'status' => (string)$tenant['status'],
            ]);
            self::$apiUserId = (int)$u['id'];
        } elseif ($row['scope'] === 'external') {
            $team = Db::name('external_teams')->where('id', (int)$row['external_team_id'])->find();
            if (!$team || (int)$team['status'] !== 1) {
                return api_err('Key 绑定的外部战队已停用', 401, 401);
            }
            self::$externalTeamId = (int)$team['id'];
        } else {
            return api_err('Key 类型无效', 401, 401);
        }

        // 静默写最后使用时间（失败不阻断）
        try {
            Db::name('api_keys')->where('id', $row['id'])->update(['last_used_at' => date('Y-m-d H:i:s')]);
        } catch (\Throwable $e) {
        }

        return $next($request);
    }
}
