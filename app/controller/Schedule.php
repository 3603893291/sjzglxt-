<?php
declare(strict_types=1);

namespace app\controller;

use app\model\CustomEvent;
use app\model\CustomRaceEvent;
use app\model\RaceEvent;
use app\service\BattleService;
use app\service\DutyService;
use app\service\Setting;
use app\service\TenantContext;
use app\service\TournamentClient;
use think\facade\Db;

/**
 * 统一日程（v5.6.0）
 * 成员端：聚合撞车/自建房/约战/赛事/自定义活动/值班，今日时间轴 + 本周列表
 * 管理端：自定义活动增删改 + 类别隐藏开关（schedule 权限）
 * 时间一律以服务器时间为准；约战/赛事条目只读展示
 */
class Schedule extends AuthBase
{
    /** 类别隐藏开关（settings.key=schedule_hide，逗号分隔类别名） */
    private function hidden(): array
    {
        $raw = Setting::get('schedule_hide', '');
        return $raw === '' ? [] : array_filter(explode(',', $raw));
    }

    /**
     * 聚合日程。scope=day 取当天，scope=week 取本周（周一至周日）
     * v5.6.1：已结束的条目直接不返回（全天条目如值班除外；
     * 时间点事件以开始时间为准，自定义活动以「开始时间+时长」为准）
     */
    public function index()
    {
        $scope = (string)$this->request->get('scope', 'day');
        $now   = date('Y-m-d H:i:s');
        if ($scope === 'week') {
            $start = date('Y-m-d 00:00:00', strtotime('monday this week'));
            $end   = date('Y-m-d 23:59:59', strtotime('sunday this week'));
        } else {
            $start = date('Y-m-d 00:00:00');
            $end   = date('Y-m-d 23:59:59');
        }
        $hidden = $this->hidden();
        $items  = [];

        // 撞车比赛
        if (!in_array('race', $hidden, true)) {
            foreach (RaceEvent::whereBetween('race_time', [$start, $end])->select() as $r) {
                $items[] = ['type' => 'race', 'type_label' => '撞车比赛', 'ref_id' => $r->id,
                    'title' => $r->title, 'start_time' => $r->race_time, 'note' => ''];
            }
        }
        // 自建房日程
        if (!in_array('custom_race', $hidden, true)) {
            foreach (CustomRaceEvent::whereBetween('race_time', [$start, $end])->select() as $r) {
                $items[] = ['type' => 'custom_race', 'type_label' => '自建房比赛', 'ref_id' => $r->id,
                    'title' => $r->title, 'start_time' => $r->race_time, 'note' => ''];
            }
        }
        // 约战（本队参与，含总线）
        if (!in_array('battle', $hidden, true)) {
            foreach (BattleService::listForParty('tenant', TenantContext::id()) as $b) {
                if ($b['battle_time'] >= $start && $b['battle_time'] <= $end && !in_array($b['status'], ['rejected', 'canceled'], true)) {
                    $items[] = ['type' => 'battle', 'type_label' => '约战', 'ref_id' => $b['id'],
                        'title' => $b['title'] ?: '约战', 'start_time' => $b['battle_time'], 'note' => ''];
                }
            }
        }
        // 赛事（经赛事中心接口取，未接入/失败静默跳过）
        if (!in_array('tournament', $hidden, true)) {
            $r = TournamentClient::tournaments();
            if ($r['ok'] && is_array($r['data'])) {
                foreach ((array)($r['data']['tournaments'] ?? []) as $t) {
                    $st = (string)($t['start_date'] ?? $t['start_time'] ?? '');
                    if ($st === '' || strtotime($st) === false) {
                        continue;
                    }
                    $stFmt = date('Y-m-d 00:00:00', strtotime($st));
                    if ($stFmt >= $start && $stFmt <= $end) {
                        $items[] = ['type' => 'tournament', 'type_label' => '赛事', 'ref_id' => (int)$t['id'],
                            'title' => (string)($t['name'] ?? '赛事'), 'start_time' => $stFmt, 'note' => ''];
                    }
                }
            }
        }
        // 自定义活动
        if (!in_array('custom', $hidden, true)) {
            foreach (CustomEvent::whereBetween('start_time', [$start, $end])->select() as $e) {
                $items[] = ['type' => 'custom', 'type_label' => '活动', 'ref_id' => $e->id,
                    'title' => $e->title, 'start_time' => $e->start_time, 'note' => $e->note,
                    'duration_min' => (int)$e->duration_min];
            }
        }
        // 值班（每日一条，全天性质）
        if (!in_array('duty', $hidden, true)) {
            $d0 = new \DateTime($start);
            $d1 = new \DateTime($end);
            for ($d = clone $d0; $d <= $d1; $d->modify('+1 day')) {
                $date = $d->format('Y-m-d');
                $duty = DutyService::dutyOn($date);
                if ($duty) {
                    $items[] = ['type' => 'duty', 'type_label' => '值班', 'ref_id' => 0,
                        'title' => '房主值班：' . $duty['nickname'] . ($duty['swapped'] ? '（与 ' . $duty['swapped_from'] . ' 换班）' : ''),
                        'start_time' => $date . ' 00:00:00', 'note' => '', 'all_day' => true];
                }
            }
        }

        // v5.6.1：已结束条目直接不显示（全天条目除外）
        $nowTs = strtotime($now);
        $items = array_values(array_filter($items, function ($it) use ($nowTs) {
            if (!empty($it['all_day'])) {
                return true;
            }
            $endTs = strtotime((string)$it['start_time']);
            if ($endTs === false) {
                return true;
            }
            if (!empty($it['duration_min'])) {
                $endTs += (int)$it['duration_min'] * 60;
            }
            return $endTs >= $nowTs;
        }));

        usort($items, fn($a, $b) => strcmp($a['start_time'], $b['start_time']));
        return api_ok(['items' => $items, 'server_time' => $now, 'scope' => $scope]);
    }

    // ---------- 管理端（schedule 权限） ----------

    private function noPerm()
    {
        return api_err('您没有权限执行此操作，请联系超级管理员分配权限', 403, 403);
    }

    // 自定义活动列表（管理端，全部）
    public function events()
    {
        if (!admin_can('schedule')) {
            return $this->noPerm();
        }
        return api_ok([
            'list'   => CustomEvent::order('start_time', 'desc')->limit(100)->select(),
            'hidden' => $this->hidden(),
        ]);
    }

    // 新增 / 编辑自定义活动
    public function saveEvent()
    {
        if (!admin_can('schedule')) {
            return $this->noPerm();
        }
        $id      = (int)$this->request->post('id', 0);
        $title   = trim((string)$this->request->post('title', ''));
        $start   = trim((string)$this->request->post('start_time', ''));
        $minutes = (int)$this->request->post('duration_min', 60);
        $note    = trim((string)$this->request->post('note', ''));

        if ($title === '' || mb_strlen($title) > 128) {
            return api_err('请填写活动标题（不超过 128 字）');
        }
        if ($start === '' || strtotime($start) === false) {
            return api_err('请填写开始时间');
        }
        if ($minutes < 5 || $minutes > 24 * 60) {
            return api_err('时长需在 5 分钟 ~ 24 小时之间');
        }
        if (mb_strlen($note) > 500) {
            return api_err('说明过长（不超过 500 字）');
        }
        $data = [
            'title'        => $title,
            'start_time'   => date('Y-m-d H:i:s', strtotime($start)),
            'duration_min' => $minutes,
            'note'         => $note,
        ];
        if ($id > 0) {
            $ev = CustomEvent::find($id);
            if (!$ev) {
                return api_err('活动不存在', 404);
            }
            $ev->save($data);
            admin_log('日程活动', '编辑自定义活动 ' . $title);
            return api_ok([], '活动已更新');
        }
        CustomEvent::create($data);
        admin_log('日程活动', '新增自定义活动 ' . $title);
        return api_ok([], '活动已添加');
    }

    // 删除自定义活动
    public function removeEvent($id)
    {
        if (!admin_can('schedule')) {
            return $this->noPerm();
        }
        $ev = CustomEvent::find((int)$id);
        if (!$ev) {
            return api_err('活动不存在', 404);
        }
        admin_log('日程活动', '删除自定义活动 ' . $ev->title);
        $ev->delete();
        return api_ok([], '活动已删除');
    }

    // 类别隐藏开关（hidden 为逗号分隔类别名：race,custom_race,battle,tournament,custom,duty）
    public function saveHidden()
    {
        if (!admin_can('schedule')) {
            return $this->noPerm();
        }
        $hidden = trim((string)$this->request->post('hidden', ''));
        $valid  = ['race', 'custom_race', 'battle', 'tournament', 'custom', 'duty'];
        $list   = array_values(array_intersect(array_filter(explode(',', $hidden)), $valid));
        Setting::set('schedule_hide', implode(',', $list));
        admin_log('日程活动', '更新日程类别隐藏：' . ($list ? implode(',', $list) : '无'));
        return api_ok([], '已保存');
    }
}
