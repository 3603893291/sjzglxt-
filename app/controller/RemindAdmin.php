<?php
declare (strict_types = 1);

namespace app\controller;

use app\service\EventReminder;
use app\service\Setting;
use app\service\TenantContext;
use think\facade\Db;

/**
 * 赛前提醒（v5.33.0，战队侧设置）：训练赛 / 队内日程 / 跨队约战 开打前推给本队
 *
 *   GET  /api/admin/remind        这一队三类的提前量 + 最近推过的 + 本队机器人绑没绑
 *   POST /api/admin/remind/save   改某一类的提前量（0/10/30/60 分钟，0＝这一类不提醒）
 *   POST /api/admin/remind/run    现在按一次（只推真正到点的，撞过去重的不会重复推）
 *
 * 权限键沿用「日程活动管理」（schedule）：提醒管的就是这三类时间安排，
 * 不另开一档权限，免得第二管理员要重新勾一遍才发现少了格。
 *
 * 三条口径：
 * ① 提前量默认 30 分钟、默认开 —— 装完当晚就会开始推，这是需求方要的「从现在起大家都开启」；
 *    嫌吵的队长在自己站点这一格关掉，平台另有一个总开关能一把按住（见 PlatformRemind）。
 * ② 这一格看不到别人的队，也不给看正文以外的东西：推过什么从最近记录里看标题就够。
 * ③ 「现在按一次」不绕过去重表：它的作用是让队长确认「到点的会不会真发出去」，
 *    不是测试发送 —— 一场训练赛一辈子只推一条公告，这是这套机制最不烦人的前提。
 */
class RemindAdmin extends AdminBase
{
    protected function noPerm()
    {
        return api_err('您没有「日程活动管理」权限，请联系超级管理员分配', 403, 403);
    }

    /** 当前设置 + 最近推过的 */
    public function index()
    {
        if (!admin_can('schedule')) {
            return $this->noPerm();
        }
        $tid = TenantContext::id();
        return api_ok([
            'leads'       => EventReminder::leadsFor($tid),
            'options'     => EventReminder::LEADS,
            'platform_on' => EventReminder::platformOn() ? 1 : 0,
            'bot_bound'   => Db::name('bot_group_bindings')->where('tenant_id', $tid)->count() > 0 ? 1 : 0,
            'recent'      => $this->recent($tid),
            'default'     => EventReminder::LEAD_DEFAULT,
        ]);
    }

    /** 改提前量 */
    public function save()
    {
        if (!admin_can('schedule')) {
            return $this->noPerm();
        }
        $kind = (string)$this->request->post('kind', '');
        if (!isset(EventReminder::KINDS[$kind])) {
            return api_err('没有这一类提醒');
        }
        $lead = EventReminder::normalizeLead($this->request->post('lead', EventReminder::LEAD_DEFAULT));
        Setting::set(EventReminder::leadKey($kind), (string)$lead);
        admin_log('赛前提醒', EventReminder::KINDS[$kind] . ' 提前量改为 ' . ($lead === 0 ? '关' : $lead . ' 分钟'));
        return api_ok(['kind' => $kind, 'lead' => $lead],
            $lead === 0 ? '已关闭' . EventReminder::KINDS[$kind] . '的赛前提醒'
                        : EventReminder::KINDS[$kind] . '将在到点前 ' . $lead . ' 分钟提醒本队');
    }

    /** 现在扫一次这一队 */
    public function run()
    {
        if (!admin_can('schedule')) {
            return $this->noPerm();
        }
        if (!EventReminder::platformOn()) {
            return api_err('平台已停用赛前提醒，本队按不动');
        }
        $r    = EventReminder::tick(TenantContext::id());
        $sent = (int)($r['sent'] ?? 0);
        admin_log('赛前提醒', '手动检查一次，推出 ' . $sent . ' 条');
        return api_ok(['sent' => $sent, 'group_ok' => (int)($r['group_ok'] ?? 0)],
            $sent === 0 ? '现在没有到点的活动（只推你选的提前量以内的那些）'
                        : '已推出 ' . $sent . ' 条'
                            . ((int)($r['group_ok'] ?? 0) === 0 ? '；QQ 群那一路没送到（官方已限制机器人主动消息），公告与通知照发' : ''));
    }

    /** 最近推过的记录，带上事件标题（表里只存 kind+ref_id，标题从各自那张表回查） */
    protected function recent(int $tid): array
    {
        $rows = EventReminder::recent($tid, 20);
        if ($rows === []) {
            return [];
        }
        $by = [];
        foreach ($rows as $r) {
            $by[(string)$r['kind']][] = (int)$r['ref_id'];
        }
        $titles = [];
        $table  = ['training' => ['training_matches', 'title'], 'schedule' => ['custom_events', 'title'],
                   'battle' => ['battles', 'title']];
        foreach ($by as $kind => $ids) {
            if (!isset($table[$kind])) {
                continue;
            }
            [$t, $col] = $table[$kind];
            $titles[$kind] = Db::name($t)->whereIn('id', $ids)->column($col, 'id');
        }
        $out = [];
        foreach ($rows as $r) {
            $kind = (string)$r['kind'];
            $out[] = [
                'kind'     => $kind,
                'kindName' => EventReminder::KINDS[$kind] ?? $kind,
                'title'    => (string)($titles[$kind][(int)$r['ref_id']] ?? '（这条已经删了）'),
                'at'       => (string)$r['event_at'],
                'lead'     => (int)$r['lead_min'],
                'groupOk'  => (int)$r['group_ok'],
                'sentAt'   => (string)$r['sent_at'],
            ];
        }
        return $out;
    }
}
