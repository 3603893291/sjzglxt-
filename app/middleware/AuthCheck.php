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

        // v5.36.13：平台协作会话逐请求校验（授权撤销 / 到期 / 站长变更 / 平台账号失效都会立刻断开）
        if (($supportErr = \app\service\Support::enforce()) !== null) {
            return api_err($supportErr, 401, 401);
        }
        $supportMode = \app\service\Support::isActive();
        if ($supportMode) {
            // 协作只用于管理中心：不允许以站长身份发聊天、改个人资料、绑通行证、上传战绩
            $path = '/' . trim($request->pathinfo(), '/');
            if (!\app\service\Support::pathAllowed($request->method(), $path)) {
                return api_err('平台协作模式仅可操作管理中心', 403, 403);
            }
            // 协作不替站长刷“最后活跃”，避免在线状态失真
            return $next($request);
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

        // v5.33.0：有人打开站点就顺路查一次到点的赛前提醒（按队 60 秒限频，与上面那次写同样省）。
        // 提醒只是附属动作，出任何岔子都不许把这次请求带下水——所以整个包在 try 里。
        try {
            \app\service\EventReminder::tickLazy((int)$user->tenant_id);
        } catch (\Throwable $e) {
            \think\facade\Log::warning('[EventReminder] ' . $e->getMessage());
        }

        // v5.36.14：有人打开站点也顺路查一次到点的 AI 主动发言（每队 5 分钟限频）。
        // 和提醒一样，任何异常都不许拖垮本次请求。
        try {
            \app\service\AiChat::tickLazy((int)$user->tenant_id);
        } catch (\Throwable $e) {
            \think\facade\Log::warning('[AiChat] ' . $e->getMessage());
        }

        // v5.36.34：比赛日集合点名——到点前顺路往 QQ 群喊一次（按队 60 秒限频，一场只喊一次）。
        try {
            \app\service\RollCall::tickLazy((int)$user->tenant_id);
        } catch (\Throwable $e) {
            \think\facade\Log::warning('[RollCall] ' . $e->getMessage());
        }

        return $next($request);
    }
}
