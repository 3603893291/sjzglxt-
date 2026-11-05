<?php
declare(strict_types=1);

namespace app\controller;

use app\service\PlatformAnnounce;

/**
 * 强制平台公告·成员侧（v5.36.31）
 *
 *   GET  /api/force-notice/current   现在该不该给我弹（该弹就回那一条公告）
 *   POST /api/force-notice/ack       我已知晓（这一条以后不再弹）
 *   POST /api/force-notice/mute      不再提示（以后平台所有公告都不弹给我）
 *
 * 战队自己那套公告栏在 /api/notices（Notice 控制器，v5.6.0），这两套东西别混：
 * 这里只有平台超管写的那一条，登录战队就挡在界面最前面，不点按不走。
 *
 * 表还没建时 current 回「没公告」而不是 500 —— 没补库的站点照样能用。
 */
class ForceNotice extends AuthBase
{
    public function current()
    {
        $me = current_user();
        if (!$me) {
            return api_err('未登录或登录已过期', 401, 401);
        }
        return api_ok(['notice' => PlatformAnnounce::currentForUser((int)$me->id)]);
    }

    public function ack()
    {
        return $this->respond('ack');
    }

    public function mute()
    {
        return $this->respond('mute');
    }

    /** 两个按钮走同一条写库路：一人一条只记一次，重复点安全 */
    private function respond(string $choice)
    {
        $me = current_user();
        if (!$me) {
            return api_err('未登录或登录已过期', 401, 401);
        }
        $id = (int)$this->request->post('id', 0);
        $ok = PlatformAnnounce::respond((int)$me->id, (int)$me->tenant_id, $id, $choice);
        if (!$ok) {
            return $choice === 'mute' && !PlatformAnnounce::muteAllowed()
                ? api_err('平台已经关掉「不再提示」，只能点「我已知晓」')
                : api_err('这条公告不存在，或者已经撤下了');
        }
        return api_ok([], $choice === 'mute' ? '好的，以后平台的强制公告不再弹给你' : '已记下，这条不再弹给你');
    }
}
