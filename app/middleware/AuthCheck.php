<?php
declare (strict_types = 1);

namespace app\middleware;

use app\model\User;
use think\facade\Db;

/**
 * 登录校验中间件：保护需要登录的 API
 * 顺带：校验账号状态（待审核/封禁踢出）、刷新最后活跃时间（在线统计）
 */
class AuthCheck
{
    public function handle($request, \Closure $next)
    {
        // v5.1.0：隐身巡查直接放行（ResolveTenant 已保证只读；current_user() 返回租户超管视角）
        // 不查库不刷 last_active——巡查不留任何痕迹
        if (\app\service\TenantContext::isStealth()) {
            return $next($request);
        }

        $uid = session('user_id');
        if (!$uid) {
            // v5.3.0：7天免密码登录——会话过期后凭 remember_me 令牌自动恢复登录，
            // 已登录过的用户无需反复输密码（仅战队站点启用，平台上下文不处理）
            $uid = remember_try_login();
        }
        if (!$uid) {
            return api_err('未登录或登录已过期', 401, 401);
        }
        $user = User::find($uid);
        if (!$user) {
            session(null);
            return api_err('账号不存在，请重新登录', 401, 401);
        }
        if ($user->status !== 'approved') {
            session(null);
            $msg = $user->status === 'pending' ? '账号正在审核中' : '账号已被封禁';
            return api_err($msg . '，请联系管理员', 401, 401);
        }

        // v5.7.1(H3)：密码纪元校验——账号改密后 session_epoch 自增，
        // 持旧纪元的会话（可能是攻击者已建立的会话）在这里被强制登出
        if (session('session_epoch') === null) {
            // 升级前的旧会话无纪元：首次访问时补盖当前值，避免升级瞬间全员掉线
            session('session_epoch', (int)$user->session_epoch);
        } elseif ((int)session('session_epoch') !== (int)$user->session_epoch) {
            session(null);
            return api_err('账号密码已变更，请重新登录', 401, 401);
        }

        // 刷新最后活跃时间（60 秒内只写一次，降低写库频率）
        $activeRefresh = true;
        if ($user->last_active_at) {
            $activeRefresh = (time() - strtotime((string)$user->last_active_at)) > 60;
        }
        if ($activeRefresh) {
            Db::name('users')->where('id', $user->id)
                ->update(['last_active_at' => date('Y-m-d H:i:s')]);
        }

        return $next($request);
    }
}
