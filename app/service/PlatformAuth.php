<?php
declare(strict_types=1);

namespace app\service;

use app\model\PlatformUser;
use think\facade\Cache;
use think\facade\Db;
use think\facade\Session;

/**
 * 入驻平台登录收尾（v5.36.8）
 *
 * 密码登录与未晞通行证登录共用同一套：清失败计数、换 Session ID、写平台登录日志。
 * 通行证只是多一种登录方式，平台账号体系本身不变。
 */
class PlatformAuth
{
    /**
     * @param bool $rotate 是否在这里轮换 Session ID。
     *                     密码登录在第一方请求里，正常轮换；
     *                     通行证回调是通行证站点跳回来的跨站响应，部分浏览器会拦那次 Set-Cookie，
     *                     所以平台通行证在发起授权时（第一方）就换好 ID，回调不再换。
     */
    public static function finalize(PlatformUser $u, string $via = 'pwd', bool $rotate = true): void
    {
        Cache::delete('plat_login_fail_' . md5(client_ip()));
        Cache::delete('plat_login_fail_user_' . md5(mb_strtolower((string)$u->username)));

        session('platform_user_id', $u->id);
        // 防会话固定：换新 Session ID（与战队登录 Auth::login 同一套做法）
        if ($rotate) {
            Session::setId(bin2hex(random_bytes(16)));
        }
        self::logLogin((int)$u->id, (string)$u->username, 1, $via);
    }

    // 写平台登录日志（静默失败，不影响登录主流程）
    // 口径与战队侧一致：新归属地提醒、只保留最近 LOGIN_LOG_KEEP 行
    public static function logLogin(int $uid, string $username, int $result, string $via = 'pwd'): void
    {
        try {
            $ip     = client_ip();
            $region = IpRegion::lookup($ip);
            $flagged = 0;
            if ($result === 1 && $uid > 0 && $region !== '') {
                $prev = Db::name('platform_login_logs')
                    ->where('platform_user_id', $uid)->where('result', 1)->where('region', '<>', '')
                    ->order('id', 'desc')->value('region');
                $flagged = ($prev !== null && (string)$prev !== $region) ? 1 : 0;
            }
            Db::name('platform_login_logs')->insert([
                'platform_user_id' => $uid,
                'username'         => mb_substr($username, 0, 32),
                'ip'               => $ip,
                'region'           => $region,
                'result'           => $result,
                'via'              => $via,
                'flagged'          => $flagged,
                'created_at'       => date('Y-m-d H:i:s'),
            ]);
            $over = (int)Db::name('platform_login_logs')->count() - LOGIN_LOG_KEEP;
            if ($over > 0) {
                // 同 login_log_write：limit+offset 配 value() 会被 ThinkPHP 忽略，改成取最旧的 N 个 id
                $old = Db::name('platform_login_logs')->order('id', 'asc')->limit($over)->column('id');
                if ($old) {
                    Db::name('platform_login_logs')->whereIn('id', $old)->delete();
                }
            }
        } catch (\Throwable $e) {
            // 日志表异常不影响登录
        }
    }
}
