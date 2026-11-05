<?php
declare (strict_types = 1);

namespace app\middleware;

use app\service\TenantContext;
use think\facade\Cookie;
use think\facade\Db;

/**
 * 隐身巡查只读通道守卫（v5.16.0）
 *
 * /api/inspect/* 是平台超管巡查战队站点时唯一能读的后台数据面，本中间件是它的唯一入口条件：
 * 1. 必须是隐身巡查会话（ResolveTenant 已按库内登记 + 只读方法验证过）——普通成员、
 *    站长、甚至平台超管自己的常规登录态都进不来，避免这组接口反过来变成越权入口；
 * 2. 必须落在租户上下文里（平台根路径 id=0 一律拒绝），且只允许 GET/HEAD；
 * 3. 端点白名单写死在这里：新增 inspect 路由若不登记就不放行，防止以后加接口时忘了配审计；
 * 4. 每次调用落一行 admin_logs（action=隐身巡查）。巡查态给的是全量字段（完整 QQ / 邮箱 /
 *    注册 IP），所以不做任何"降噪去重"——被巡查的站长在自己后台的日志里能看到每一次读取，
 *    这一行就是「谁、什么时候、读了哪张表、按什么条件查的」的凭证。
 *
 * 写日志失败不拦请求（最坏情况这一次读取没留痕），但鉴权失败一定拦。
 */
class StealthRead
{
    /** 端点白名单：路由路径最后一段 → 审计里显示的名字 */
    protected const ENDPOINTS = [
        'overview'        => '站点概览',
        'members'         => '成员全量资料',
        'pending'         => '待审核注册',
        'blacklist'       => '黑名单档案',
        'password-resets' => '找回密码申请',
        'logs'            => '站长操作日志',
        'images'          => '图片台账',
        'player-stats'    => '战绩台账',
        'battles'         => '约战台账',
    ];

    /**
     * 单个巡查会话的调用上限（次/小时）：巡查给的是全量数据，限额兜住「脚本化拖库」
     * 和审计表被写爆。默认 600 够 UI 整场巡查用，平台可在设置里按站点调整（inspect_call_limit）。
     */
    protected function callLimit(): int
    {
        return max(1, (int)setting('inspect_call_limit', '600'));
    }

    public function handle($request, \Closure $next)
    {
        if (!TenantContext::isStealth()) {
            return api_err('巡查接口仅对隐身巡查会话开放', 403, 403);
        }
        if (!in_array($request->method(), ['GET', 'HEAD'], true)) {
            return api_err('隐身巡查仅允许读取', 405, 405);
        }
        $tid = TenantContext::id();
        if ($tid <= TenantContext::PLATFORM_ID) {
            return api_err('巡查接口仅可在战队站点内使用', 403, 403);
        }

        $path = strtolower(trim((string)$request->pathinfo(), '/'));
        $endpoint = substr($path, (int)strrpos($path, '/'));
        $endpoint = ltrim($endpoint, '/');
        if (!isset(self::ENDPOINTS[$endpoint])) {
            return api_err('巡查接口不存在', 404, 404);
        }

        // 会话指纹：巡查 cookie 的 sha256 前 8 位。审计行靠它把同一次巡查的多次读取归到一起，
        // 本身不可逆推 cookie，写进日志也不构成新的凭据泄漏。
        $secret = (string)Cookie::get(stealth_cookie_name(), '');
        $fp     = $secret !== '' ? substr(hash('sha256', $secret), 0, 8) : 'unknown';
        // 会话登记里记着签发这枚入口的平台超管 id；查不到记 0（会话已过期或已被撤销）
        $info   = $secret !== '' ? stealth_session_info($tid, $secret) : null;
        $issuer = (int)($info['created_by'] ?? 0);

        $used = rate_bump('inspect_quota_' . $tid . '_' . $fp, 3600);
        $limit = $this->callLimit();
        if ($used > $limit) {
            $this->audit($tid, $endpoint, $fp, $issuer, $used, '', '已拦截：超过 ' . $limit . ' 次/小时');
            return api_err('本次巡查读取次数已达上限，请退出后重新进入', 429, 429);
        }

        $this->audit($tid, $endpoint, $fp, $issuer, $used, $this->params($request));

        return $next($request);
    }

    /** 查询参数摘要：只留标量、去掉下划线开头的内部参数（如一次性兑票令牌 _sv） */
    protected function params($request): string
    {
        $out = [];
        foreach ((array)$request->get() as $k => $v) {
            if (strncmp((string)$k, '_', 1) === 0 || is_array($v)) {
                continue;
            }
            $out[] = $k . '=' . mb_substr((string)$v, 0, 24);
        }
        return $out ? implode('&', $out) : '';
    }

    protected function audit(int $tid, string $endpoint, string $fp, int $issuer, int $seq, string $params, string $note = ''): void
    {
        $detail = '读取「' . self::ENDPOINTS[$endpoint] . '」(' . $endpoint . ')'
            . ($params !== '' ? ' 条件: ' . $params : '')
            . ($note !== '' ? ' · ' . $note : '')
            . ' · 会话 ' . $fp . ' #' . $seq
            . ' · 访客 ' . client_ip();
        try {
            Db::name('admin_logs')->insert([
                'tenant_id'  => $tid,
                'admin_id'   => 0,
                'admin_name' => '平台巡查#' . $issuer,
                'action'     => '隐身巡查',
                'detail'     => mb_substr($detail, 0, 300),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            // 留痕失败不阻断读取：巡查可用性优先，且鉴权与限额都已在本请求内生效
        }
    }
}
