<?php
declare(strict_types=1);

namespace app\controller;

use app\model\DutyConfig;
use app\model\DutyMember;
use app\model\DutySwap;
use app\model\User;
use app\service\DutyService;
use app\service\GameSide;

/**
 * 值班表管理（v5.6.0，duty 权限）
 * 房主值班：名单 + 轮换周期（按天/按周）+ 顺序调整 + 换班记录查看
 * 轮值计算复用轮换地图思路：锚点日期 + 周期取模（见 DutyService）
 */
class DutyAdmin extends AdminBase
{
    private function noPerm()
    {
        return api_err('您没有权限执行此操作，请联系超级管理员分配权限', 403, 403);
    }

    // 配置 + 成员名单 + 未来 7 天轮值预览 + 换班记录
    public function index()
    {
        if (!admin_can('duty')) {
            return $this->noPerm();
        }
        // v5.36.0：值班分端时，管理端跟着当前专区走；这一端还没单独配过就沿用共享那份预览
        $split = GameSide::moduleOn('duty');
        $zone  = $split ? GameSide::zone(null, true) : '';
        $cfg   = $split ? DutyService::configExact($zone) : DutyService::config();
        $effective = $cfg ?: DutyService::config($zone);
        $members = [];
        if ($effective) {
            $rows = DutyMember::where('config_id', $effective->id)
                ->order('sort', 'asc')->order('id', 'asc')->select()->toArray();
            $uids = array_column($rows, 'user_id');
            $names = User::whereIn('id', $uids)->column('nickname, username', 'id');
            foreach ($rows as &$r) {
                $u = $names[$r['user_id']] ?? null;
                $r['nickname'] = $u ? ($u['nickname'] ?: $u['username']) : '';
            }
            unset($r);
            $members = $rows;
        }
        // 未来 7 天轮值预览
        $preview = [];
        for ($i = 0; $i < 7; $i++) {
            $date = date('Y-m-d', strtotime('+' . $i . ' day'));
            $preview[] = ['date' => $date] + (DutyService::dutyOn($date, $effective) ?: ['user_id' => 0, 'nickname' => '', 'swapped' => false, 'swapped_from' => '']);
        }
        // 换班记录（最近 30 条）
        $swaps = DutySwap::order('id', 'desc')->limit(30)->select()->toArray();
        $uids2 = [];
        foreach ($swaps as $s) {
            $uids2[] = $s['from_uid'];
            $uids2[] = $s['to_uid'];
        }
        $names2 = User::whereIn('id', array_unique($uids2))->column('nickname, username', 'id');
        foreach ($swaps as &$s) {
            $s['from_name'] = isset($names2[$s['from_uid']]) ? ($names2[$s['from_uid']]['nickname'] ?: $names2[$s['from_uid']]['username']) : '';
            $s['to_name']   = isset($names2[$s['to_uid']]) ? ($names2[$s['to_uid']]['nickname'] ?: $names2[$s['to_uid']]['username']) : '';
        }
        unset($s);

        // 可轮值成员候选（在册正常成员）
        // v5.15.0(S-M2)：username 就是 QQ 号。仅持 duty 权限的副管理员不该拿到全队完整号码，
        // 这里补上与 Admin::teams 名单同一套 qq_full 脱敏口径
        $uq = User::where('status', 'approved');
        if ($split) {
            $uq->whereIn('game_side', [$zone, '']);   // 这一端的值班只排本端与不分类的人
        }
        $candidates = $uq->field('id, username, nickname')->order('id', 'asc')->select()->toArray();
        $canFullQq  = admin_can('qq_full');
        foreach ($candidates as &$c) {
            $c['username'] = $canFullQq ? (string)$c['username'] : mask_qq((string)$c['username']);
        }
        unset($c);

        return api_ok([
            'split'      => $split,
            'zone'       => $zone,
            'zone_label' => $split ? GameSide::zoneLabel($zone) : '全队',
            'config'     => $cfg,
            'inherited'  => $split && !$cfg && $effective ? true : false,
            'members'    => $members,
            'preview'    => $preview,
            'swaps'      => $swaps,
            'candidates' => $candidates,
        ]);
    }

    // 保存配置：轮换周期 / 锚点日期 / 启用开关
    public function saveConfig()
    {
        if (!admin_can('duty')) {
            return $this->noPerm();
        }
        $cycle  = (string)$this->request->post('cycle', 'day');
        $anchor = trim((string)$this->request->post('anchor_date', ''));
        $enabled = $this->request->post('enabled') ? 1 : 0;

        if (!in_array($cycle, ['day', 'week'], true)) {
            return api_err('轮换周期只能是按天或按周');
        }
        if ($anchor === '' || strtotime($anchor) === false) {
            return api_err('请设置轮换锚点日期');
        }
        $anchor = date('Y-m-d', strtotime($anchor));

        $split = GameSide::moduleOn('duty');
        $zone  = $split ? GameSide::zone(null, true) : '';
        $cfg = $split ? DutyService::configExact($zone) : DutyService::config();
        $data = ['duty_type' => 'room_host', 'game_side' => $zone, 'cycle' => $cycle, 'anchor_date' => $anchor, 'enabled' => $enabled];
        if ($cfg) {
            $cfg->save($data);
        } else {
            DutyConfig::create($data);
        }
        admin_log('值班表', '保存值班配置' . ($split ? '（' . GameSide::zoneLabel($zone) . '）' : '') . '：'
            . ($cycle === 'week' ? '按周' : '按天') . '轮换，锚点 ' . $anchor . ($enabled ? '，已启用' : '，已停用'));
        return api_ok([], '值班配置已保存');
    }

    // 保存轮值名单（整体替换，数组顺序即轮值顺序）
    public function saveMembers()
    {
        if (!admin_can('duty')) {
            return $this->noPerm();
        }
        $split = GameSide::moduleOn('duty');
        $zone  = $split ? GameSide::zone(null, true) : '';
        $cfg = $split ? DutyService::configExact($zone) : DutyService::config();
        if (!$cfg) {
            return api_err($split ? '这个专区还没有单独的值班配置，请先保存上面的配置' : '请先保存值班配置');
        }
        $uids = $this->request->post('user_ids/a', []);
        $uids = array_values(array_unique(array_map('intval', $uids)));
        if (!$uids) {
            return api_err('请至少勾选一名轮值成员');
        }
        // 校验成员在册；分端时还要是本端或不分类的人
        $vq = User::whereIn('id', $uids)->where('status', 'approved');
        if ($split) {
            $vq->whereIn('game_side', [$zone, '']);
        }
        $valid = $vq->column('id');
        $bad = array_diff($uids, $valid);
        if ($bad) {
            return api_err('包含无效成员');
        }
        DutyMember::where('config_id', $cfg->id)->delete();
        foreach ($uids as $i => $uid) {
            DutyMember::create(['config_id' => $cfg->id, 'user_id' => $uid, 'sort' => $i]);
        }
        admin_log('值班表', '更新轮值名单，共 ' . count($uids) . ' 人');
        return api_ok([], '轮值名单已保存');
    }

    // 临时移除某人
    public function removeMember($id)
    {
        if (!admin_can('duty')) {
            return $this->noPerm();
        }
        $row = DutyMember::find((int)$id);
        if (!$row) {
            return api_err('记录不存在', 404);
        }
        $u = User::find((int)$row->user_id);
        admin_log('值班表', '移出轮值名单：' . ($u ? ($u->nickname ?: $u->username) : '#' . $row->user_id));
        $row->delete();
        return api_ok([], '已移出轮值名单');
    }
}
