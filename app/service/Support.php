<?php
declare (strict_types = 1);

namespace app\service;

use app\model\PlatformUser;
use app\model\User;
use think\facade\Cookie;
use think\facade\Db;
use think\facade\Session;

/**
 * 平台协作（v5.36.13）
 *
 * 队长授权后，平台超管可以进入本队管理中心协助操作；授权长期有效、队长可随时撤销。
 * 三条硬边界：
 *   1. 只有租户站长本人能授权/撤销，协作模式内不能改授权；
 *   2. 协作会话逐请求回库校验（授权仍有效、平台账号仍是超管、站长账号未变、未到期、未退出）；
 *   3. 协作模式只能走管理中心（/api/admin/*），不能改站长账号、不能绑通行证、不会碰站长免登录令牌。
 */
class Support
{
    /** 单次协作会话有效期：4 小时（授权本身长期有效，随时可再进） */
    public const SESSION_TTL = 14400;

    /** 一次性入口票据有效期：5 分钟 */
    public const TICKET_TTL = 300;

    // ==================== 授权 ====================

    public static function grantRow(int $tenantId): ?array
    {
        try {
            $row = Db::name('support_grants')->where('tenant_id', $tenantId)->find();
            return is_array($row) ? $row : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    public static function grantActive(int $tenantId): bool
    {
        $row = self::grantRow($tenantId);
        return $row !== null && empty($row['revoked_at']);
    }

    /** 队长授权：长期有效；已有记录则恢复并刷新授权时间 */
    public static function grant(int $tenantId, int $ownerUserId): void
    {
        $now = date('Y-m-d H:i:s');
        $row = self::grantRow($tenantId);
        if ($row !== null) {
            Db::name('support_grants')->where('tenant_id', $tenantId)->update([
                'granted_by' => $ownerUserId,
                'granted_at' => $now,
                'revoked_at' => null,
                'updated_at' => $now,
            ]);
            return;
        }
        Db::name('support_grants')->insert([
            'tenant_id'  => $tenantId,
            'granted_by' => $ownerUserId,
            'granted_at' => $now,
            'revoked_at' => null,
            'updated_at' => $now,
        ]);
    }

    /** 队长撤销授权：同时结束全部在用的协作会话，返回结束条数 */
    public static function revoke(int $tenantId): int
    {
        $now = date('Y-m-d H:i:s');
        $row = self::grantRow($tenantId);
        if ($row !== null) {
            Db::name('support_grants')->where('tenant_id', $tenantId)->update([
                'revoked_at' => $now,
                'updated_at' => $now,
            ]);
        }
        return Db::name('support_sessions')->where('tenant_id', $tenantId)
            ->whereNull('ended_at')->update(['ended_at' => $now]);
    }

    /** 授权状态（表不存在时 available=false，前端据此不弹窗、不报错） */
    public static function grantInfo(int $tenantId): array
    {
        $now = date('Y-m-d H:i:s');
        try {
            $row = Db::name('support_grants')->where('tenant_id', $tenantId)->find();
            $active = is_array($row) && empty($row['revoked_at']);
            $sessions = (int)Db::name('support_sessions')->where('tenant_id', $tenantId)
                ->whereNull('ended_at')->where('expires_at', '>', $now)->count();
            return [
                'available'       => true,
                'granted'         => $active,
                'granted_at'      => $active ? (string)$row['granted_at'] : '',
                'revoked_at'      => is_array($row) ? (string)($row['revoked_at'] ?? '') : '',
                'active_sessions' => $sessions,
            ];
        } catch (\Throwable $e) {
            return [
                'available'       => false,
                'granted'         => false,
                'granted_at'      => '',
                'revoked_at'      => '',
                'active_sessions' => 0,
            ];
        }
    }

    // ==================== 一次性入口票据 ====================

    public static function issueTicket(int $tenantId, int $platformUserId, int $ttl = self::TICKET_TTL): string
    {
        $token = bin2hex(random_bytes(32));
        $now = time();
        Db::name('support_tickets')->insert([
            'tenant_id'        => $tenantId,
            'platform_user_id' => $platformUserId,
            'token_hash'       => hash('sha256', $token),
            'created_at'       => date('Y-m-d H:i:s', $now),
            'expires_at'       => date('Y-m-d H:i:s', $now + $ttl),
            'used_at'          => null,
        ]);
        // 顺手清掉过期票据，避免表无限增长
        Db::name('support_tickets')->where('expires_at', '<', date('Y-m-d H:i:s'))->delete();
        return $token;
    }

    /**
     * 兑票：只认库内登记 + 未用过 + 未过期 + 授权仍有效 + 平台账号仍是超管。
     * 作废用带条件的 UPDATE，抢不到行（并发第二次 / 已用过）就返回 null。
     */
    public static function redeemTicket(int $tenantId, string $token): ?array
    {
        if ($token === '' || !preg_match('/^[0-9a-f]{64}$/i', $token)) {
            return null;
        }
        $row = Db::name('support_tickets')->where('tenant_id', $tenantId)
            ->where('token_hash', hash('sha256', $token))->find();
        if (!is_array($row) || !empty($row['used_at']) || strtotime((string)$row['expires_at']) <= time()) {
            return null;
        }
        $pu = PlatformUser::find((int)$row['platform_user_id']);
        if (!$pu || $pu->role !== 'admin' || $pu->status !== 'normal') {
            return null;
        }
        if (!self::grantActive($tenantId)) {
            return null;
        }
        $now = date('Y-m-d H:i:s');
        $n = Db::name('support_tickets')->where('id', (int)$row['id'])->whereNull('used_at')
            ->update(['used_at' => $now]);
        if ($n < 1) {
            return null;
        }
        return ['platform_user_id' => (int)$pu->id, 'platform_user' => $pu];
    }

    // ==================== 协作会话 ====================

    public static function startSession(int $tenantId, int $platformUserId): int
    {
        $now = time();
        return (int)Db::name('support_sessions')->insertGetId([
            'tenant_id'        => $tenantId,
            'platform_user_id' => $platformUserId,
            'started_at'       => date('Y-m-d H:i:s', $now),
            'last_seen_at'     => date('Y-m-d H:i:s', $now),
            'expires_at'       => date('Y-m-d H:i:s', $now + self::SESSION_TTL),
            'ended_at'         => null,
            'ip'               => client_ip(),
            'ua'               => mb_substr(self::ua(), 0, 200),
        ]);
    }

    /** 主动结束一条会话（退出协作） */
    public static function endSession(int $sessionId): void
    {
        if ($sessionId <= 0) {
            return;
        }
        Db::name('support_sessions')->where('id', $sessionId)->whereNull('ended_at')
            ->update(['ended_at' => date('Y-m-d H:i:s')]);
    }

    public static function isActive(): bool
    {
        return (int)session('support_session_id') > 0;
    }

    /**
     * 每条受保护请求都要过这一关。返回 null=不是协作模式；返回字符串=会话已失效（调用方应 401）。
     * 失效原因会清空会话，前端随后跳回平台首页。
     */
    public static function enforce(): ?string
    {
        $sid = (int)session('support_session_id');
        if ($sid <= 0) {
            return null;
        }
        $tid = TenantContext::id();
        $row = Db::name('support_sessions')->where('id', $sid)->where('tenant_id', $tid)->find();
        if (!is_array($row) || !empty($row['ended_at']) || strtotime((string)$row['expires_at']) <= time()) {
            session(null);
            return '平台协作会话已结束，请回到平台控制台重新进入';
        }
        if (!self::grantActive($tid)) {
            session(null);
            return '战队队长已撤销平台协作授权，协作会话已结束';
        }
        $pu = PlatformUser::find((int)$row['platform_user_id']);
        if (!$pu || $pu->role !== 'admin' || $pu->status !== 'normal') {
            session(null);
            return '平台账号权限已变更，协作会话已结束';
        }
        $ownerId = self::ownerId($tid);
        if ($ownerId <= 0 || (int)session('user_id') !== $ownerId) {
            session(null);
            return '战队站长账号已变更，协作会话已结束';
        }
        // 最后活跃时间 60 秒写一次
        if (strtotime((string)$row['last_seen_at']) < time() - 60) {
            Db::name('support_sessions')->where('id', $sid)
                ->update(['last_seen_at' => date('Y-m-d H:i:s')]);
        }
        return null;
    }

    /**
     * 协作模式只放行管理中心与少数壳接口：
     * 不允许以站长身份发聊天、改个人资料、绑通行证、上传战绩等——那是冒用本人，不是协助管理。
     */
    public static function pathAllowed(string $method, string $path): bool
    {
        $path = '/' . ltrim($path, '/');
        if ($path === '/api/admin' || str_starts_with($path, '/api/admin/')) {
            return true;
        }
        if (str_starts_with($path, '/api/support/')) {
            return true;
        }
        if (in_array($path, ['/api/auth/me', '/api/auth/logout'], true)) {
            return true;
        }
        // 「私聊开关」页签工作区读的是这一条；只读
        if ($method === 'GET' && $path === '/api/chat/dm/candidates') {
            return true;
        }
        // 约战管理页签读 Hub 状态；只读
        if ($method === 'GET' && $path === '/api/battle-hub/status') {
            return true;
        }
        // 用户管理/成员头像走这个代理；只读，成员之间本来也互相可见
        if ($method === 'GET' && str_starts_with($path, '/api/avatar/')) {
            return true;
        }
        // 赛事中心页签（本队报名/绑定赛事站）不在 /api/admin 前缀下，但确属管理中心功能
        if (str_starts_with($path, '/api/tournament-center')) {
            return true;
        }
        // 投票管理页签读活动列表；只读，投票/加选项等写操作仍被挡
        if ($method === 'GET' && ($path === '/api/votes' || str_starts_with($path, '/api/votes/'))) {
            return true;
        }
        return false;
    }

    /** 进入协作：清掉本站原有登录态，换成站长身份 + 协作会话标记（不签发免登录令牌） */
    public static function beginInTenant(int $sessionId, int $platformUserId, User $owner): void
    {
        session(null);
        // 只删这台浏览器自己的免登录 cookie；不能按用户清，否则会把站长的其它设备一起踢下线
        self::clearRememberCookie();
        session('user_id', (int)$owner->id);
        session('session_epoch', (int)$owner->session_epoch);
        session('support_session_id', $sessionId);
        session('support_platform_user_id', $platformUserId);
        session('support_started_at', date('Y-m-d H:i:s'));
        Session::setId(bin2hex(random_bytes(16)));
    }

    /** 退出协作：结束会话 + 清本站登录态 + 删本机免登录 cookie */
    public static function endLocal(): void
    {
        self::endSession((int)session('support_session_id'));
        session(null);
        self::clearRememberCookie();
    }

    /** 免登录 cookie 是按租户目录写路径的，删除时也要带同一个 path 才清得掉 */
    private static function clearRememberCookie(): void
    {
        try {
            $base = TenantContext::base();
            Cookie::delete(remember_cookie_name(), ['path' => '/']);
            if ($base !== '' && $base !== '/') {
                Cookie::delete(remember_cookie_name(), ['path' => $base]);
            }
        } catch (\Throwable $e) {
            // 没有 cookie 时忽略
        }
    }

    /** 队长本人（tenants.admin_user_id 优先，缺 id 时按 admin_username 回查） */
    public static function ownerId(int $tenantId): int
    {
        $row = Db::name('tenants')->where('id', $tenantId)->field('admin_user_id, admin_username')->find();
        if (!is_array($row)) {
            return 0;
        }
        $uid = (int)($row['admin_user_id'] ?? 0);
        if ($uid > 0) {
            return $uid;
        }
        $name = (string)($row['admin_username'] ?? '');
        if ($name === '') {
            return 0;
        }
        return (int)Db::name('users')->where('tenant_id', $tenantId)->where('username', $name)->value('id');
    }

    public static function ownerUser(int $tenantId): ?User
    {
        $uid = self::ownerId($tenantId);
        return $uid > 0 ? User::find($uid) : null;
    }

    // ==================== 前端状态 / 审计 ====================

    /** /api/auth/me 里带出去的协作状态（队长强制弹窗靠 prompt） */
    public static function statusForClient(User $user): array
    {
        $tid = TenantContext::id();
        $grant = self::grantInfo($tid);
        $mode = self::isActive();
        $owner = is_tenant_owner($user);
        $prompt = $grant['available'] && $owner && !$mode && !$grant['granted']
            && !session('support_prompt_dismissed');
        return [
            'available'       => $grant['available'],
            'mode'            => $mode,
            'owner'           => $owner,
            'prompt'          => (bool)$prompt,
            'granted'         => $grant['granted'],
            'granted_at'      => $grant['granted_at'],
            'active_sessions' => $grant['active_sessions'],
            'platform_name'   => $mode ? self::platformLabel() : '',
            'started_at'      => $mode ? (string)session('support_started_at', '') : '',
        ];
    }

    /** 协作模式下的操作人标签（admin_log 用它把「谁在操作」写清楚） */
    public static function auditIdentity(): ?string
    {
        if (!self::isActive()) {
            return null;
        }
        return self::platformLabel((int)session('support_platform_user_id'));
    }

    private static function platformLabel(int $platformUserId = 0): string
    {
        if ($platformUserId <= 0) {
            $platformUserId = (int)session('support_platform_user_id');
        }
        if ($platformUserId <= 0) {
            return '平台超管';
        }
        $pu = PlatformUser::find($platformUserId);
        if (!$pu) {
            return '平台超管#' . $platformUserId;
        }
        $name = trim((string)($pu->nickname ?: $pu->username));
        return ($name !== '' ? $name : '平台超管') . '(平台#' . $platformUserId . ')';
    }

    /** 平台侧动作写进目标站点日志（站长在「操作日志」里看得到） */
    public static function audit(int $tenantId, string $action, string $detail, string $adminName = '平台协作'): void
    {
        try {
            Db::name('admin_logs')->insert([
                'tenant_id'  => $tenantId,
                'admin_id'   => 0,
                'admin_name' => $adminName,
                'action'     => $action,
                'detail'     => mb_substr($detail, 0, 300),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            // 审计失败不阻断协作本身
        }
    }

    private static function ua(): string
    {
        return (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    }
}
