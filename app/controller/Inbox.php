<?php
declare (strict_types = 1);

namespace app\controller;

use app\service\MailBlast;
use app\service\UserNotice;

/**
 * 站内信·成员侧接口（v5.32.0：论坛下线后从 Forum 控制器里搬出来独立成一条）
 *
 *   GET  /api/inbox          我的通知（举报有结果 / 平台通知 / 赛前提醒）+ 未读数，倒序
 *   POST /api/inbox/read     全标已读，清红点
 *   GET  /api/inbox/blast    平台邮件群发给我的那一封整篇正文（只有这一批的收件人读得到）
 *
 * 路径用 inbox 不用 notices —— /api/notices 那三条是公告栏（Notice 控制器，v5.6.0），
 * 两个东西不是一回事，别再往同一路由上挂。
 */
class Inbox extends AuthBase
{
    public function index()
    {
        $me = current_user();
        if (!$me) {
            return api_err('未登录或登录已过期', 401, 401);
        }
        $uid = (int)$me->id;
        return api_ok([
            'list'   => UserNotice::listOf($uid, (int)$this->request->get('limit', 30)),
            'unread' => UserNotice::unread($uid),
        ]);
    }

    public function read()
    {
        $me = current_user();
        if (!$me) {
            return api_err('未登录或登录已过期', 401, 401);
        }
        return api_ok(['read' => UserNotice::markRead((int)$me->id)], '红点清了');
    }

    /**
     * 平台邮件群发同时补的那条站内信，点开看整篇正文（列表里那句 note 只有 200 字）。
     * 谁能读到：只有这一批的收件人本人 —— 判定在 MailBlast::bodyFor 里，靠我自己那条通知行。
     */
    public function blast()
    {
        $me = current_user();
        if (!$me) {
            return api_err('未登录或登录已过期', 401, 401);
        }
        $body = MailBlast::bodyFor((int)$me->id, (int)$this->request->get('blast_id', 0));
        if ($body === null) {
            return api_err('这封通知不是发给你的，或者原文已经不在了', 404);
        }
        return api_ok($body);
    }
}
