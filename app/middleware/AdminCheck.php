<?php
declare (strict_types = 1);

namespace app\middleware;

use app\model\User;

/**
 * 管理员校验中间件：保护管理中心 API
 */
class AdminCheck
{
    public function handle($request, \Closure $next)
    {
        // v5.1.0：隐身巡查仅可浏览前台数据，管理接口一律拒绝（防数据越权）
        if (\app\service\TenantContext::isStealth()) {
            return api_err('隐身模式仅供浏览，管理接口不可用', 403, 403);
        }
        $uid = session('user_id');
        if (!$uid) {
            // v5.3.0：会话过期后凭免登录 cookie 静默恢复（与 AuthCheck 同机制）
            $uid = remember_try_login();
        }
        if (!$uid) {
            return api_err('未登录或登录已过期', 401, 401);
        }
        $user = User::find($uid);
        if (!$user || $user->status !== 'approved') {
            session(null);
            return api_err('账号不可用，请重新登录', 401, 401);
        }
        if ($user->role !== 'admin') {
            return api_err('无权限：需要管理员身份', 403, 403);
        }

        // v5.7.1(H3)：密码纪元校验（与 AuthCheck 同机制）——改密后旧会话强制登出
        if (session('session_epoch') === null) {
            session('session_epoch', (int)$user->session_epoch);
        } elseif ((int)session('session_epoch') !== (int)$user->session_epoch) {
            session(null);
            return api_err('账号密码已变更，请重新登录', 401, 401);
        }

        // v5.36.13：平台协作会话逐请求校验（撤销/到期/站长变更立即断开）
        if (($supportErr = \app\service\Support::enforce()) !== null) {
            return api_err($supportErr, 401, 401);
        }

        return $next($request);
    }
}
