<?php
declare(strict_types=1);

namespace app\service;

use app\model\User;
use think\facade\Session;

/**
 * 登录成功后的会话收尾（v5.36.6）
 *
 * 表单密码登录与未晞通行证登录必须走同一段：刷新 IP/归属地、管理员登录日志、
 * 防会话固定、可选的 7 天免登录令牌。两边各写一份迟早漂。
 */
class AuthSession
{
    /**
     * @param string $via            pwd / passport（写进登录日志）
     * @param bool   $manageRemember 是否处理免登录令牌
     * @param int    $remember       1=签发 7 天免登录令牌
     */
    public static function finalize(User $user, string $via = 'pwd', bool $manageRemember = false, int $remember = 0): void
    {
        $tid = TenantContext::id();

        // v3.9.5：每次登录成功刷新用户 IP 与归属地（CDN 场景 client_ip() 取真实 IP）
        $loginIp = client_ip();
        // v5.17.0：归属地只查一次，同时喂 users.reg_region 与登录日志——IpRegion 走外网，别重复请求
        if ((string)$user->reg_ip !== $loginIp) {
            $region = IpRegion::lookup($loginIp);
            $user->save(['reg_ip' => $loginIp, 'reg_region' => $region]);
        } elseif ((string)$user->reg_region === '') {
            $region = IpRegion::lookup($loginIp);
            $user->save(['reg_region' => $region]);
        } else {
            $region = (string)$user->reg_region;
        }
        if ($user->role === 'admin') {
            login_log_write($tid, (int)$user->id, (string)$user->username, $loginIp, $region, $via, 'success',
                login_log_region_changed($tid, (int)$user->id, $region));
        }

        // 防会话固定：ThinkPHP 使用自研 Session Store（非原生 PHP session），
        // session_regenerate_id 不生效，必须用 Session::setId 换新 ID（数据保留在内存，随新 ID 落盘）
        session('user_id', $user->id);
        // v5.7.1(H3)：记录本次会话对应的密码纪元，改密后 AuthCheck 比对不一致即强制下线
        session('session_epoch', (int)$user->session_epoch);
        // v5.36.13：每次重新登录都重新问一次队长是否授权平台协作（本次登录内点“暂不授权”才不再弹）
        session('support_prompt_dismissed', false);
        Session::setId(bin2hex(random_bytes(16)));

        // v5.3.0：7天免密码登录——勾选后签发令牌写 cookie，会话过期后凭令牌自动恢复登录；
        // 未勾选时顺带清掉旧令牌，避免旧设备残留免登录
        if ($manageRemember) {
            if ($remember === 1) {
                remember_issue((int)$user->id);
            } else {
                // v5.19.0：只撤销这台设备自己那枚，别把其它设备已签发的免登录一起删光
                remember_revoke_current((int)$user->id);
            }
        }
    }
}
