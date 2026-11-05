<?php
declare (strict_types = 1);

namespace app\controller;

use app\BaseController;
use app\service\EventReminder;
use app\service\RollCall;
use think\facade\Db;

/**
 * 赛前提醒（v5.33.0，平台侧总闸）：只有平台超级管理员能用
 *
 *   GET  /api/platform/admin/remind/config   总开关 + 有多少队把哪一类关了 + 今天推了几条
 *   POST /api/platform/admin/remind/config   开/关总闸
 *   POST /api/platform/admin/remind/tick     把所有已上线的队各扫一遍（懒触发之外的准时路）
 *   POST /api/open/remind/tick               同一条动作，走 X-Api-Key（平台作用域）——计划任务打这条
 *
 * 为什么要有这一页：提醒会往每个队的公告里写东西，出问题时（时间算错、文案不对、某天推得太多）
 * 需要一个一把按住的开关，而不是让 30 个队长各自去关自己那一格。
 * 关掉的只是「推」这一动作，队长设的提前量还在，重新打开就照原样继续。
 */
class PlatformRemind extends BaseController
{
    /** @return \think\Response|null 不是平台超管就返回这个 403 */
    private function gate()
    {
        if (!function_exists('is_platform_super') || !\is_platform_super()) {
            return api_err('需要平台超级管理员登录态', 403, 403);
        }
        return null;
    }

    public function config()
    {
        if ($r = $this->gate()) {
            return $r;
        }
        $total = (int)Db::name('tenants')->where('status', 'approved')->count();
        $closed = [];
        foreach (EventReminder::KINDS as $kind => $name) {
            $closed[$kind] = ['name' => $name,
                'off' => (int)Db::name('settings')->where('key', EventReminder::leadKey($kind))
                    ->where('value', '0')->count()];
        }
        $today = (int)Db::name('event_reminders')->where('sent_at', '>=', date('Y-m-d 00:00:00'))->count();
        return api_ok([
            'enabled'       => EventReminder::platformOn() ? 1 : 0,
            'tenants'       => $total,
            'closed'        => $closed,
            'sent_today'    => $today,
            'default_lead'  => EventReminder::LEAD_DEFAULT,
            'lead_options'  => EventReminder::LEADS,
            'note'          => '公告与站内信一定会发；QQ 群那一路按官方对机器人主动消息的限制，'
                . '多半送不达，去重表里记成 group_ok=0，不重试第二遍。',
        ]);
    }

    public function configSave()
    {
        if ($r = $this->gate()) {
            return $r;
        }
        $on = (int)$this->request->post('enabled', 1) === 1;
        EventReminder::setPlatformOn($on);
        admin_log('赛前提醒', $on ? '开放全站赛前提醒' : '停用全站赛前提醒');
        return api_ok(['enabled' => $on ? 1 : 0], $on ? '已开放赛前提醒' : '已停用：所有队都不再推，队长设的提前量保留');
    }

    /** 手动扫一遍所有已上线的队（会话与 API Key 两条路都走这里） */
    public function tick()
    {
        if ($r = $this->gate()) {
            return $r;
        }
        $res  = EventReminder::tickAll(200);
        $sent = (int)$res['sent'];
        // v5.36.34：同一颗钮顺路把集合点名的群推也扫一遍（这一路不看赛前提醒总闸）
        $rc = RollCall::tickAll(200);
        admin_log('赛前提醒', '全站点检查一次，扫 ' . (int)$res['tenants'] . ' 个队，推出 ' . $sent . ' 条；'
            . '集合点名喊了 ' . (int)$rc['sent'] . ' 张单');
        return api_ok(['event' => $res, 'rollcall' => $rc, 'sent' => $sent, 'tenants' => (int)$res['tenants'],
                       'skipped' => (string)$res['skipped']],
            $res['skipped'] === 'off'
                ? '赛前提醒总闸是关着的，一条都没推（集合点名喊了 ' . (int)$rc['sent'] . ' 张单）'
                : '扫了 ' . (int)$res['tenants'] . ' 个队，推出 ' . $sent . ' 条；集合点名喊了 ' . (int)$rc['sent'] . ' 张单');
    }
}
