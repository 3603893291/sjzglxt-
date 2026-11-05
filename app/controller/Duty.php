<?php
declare(strict_types=1);

namespace app\controller;

use app\model\DutyMember;
use app\model\DutySwap;
use app\model\User;
use app\service\DutyService;
use app\service\GameSide;

/**
 * 值班表·成员端（v5.6.0）
 * 今日值班展示（仪表盘卡片数据源）+ 换班发起/确认（发起-确认两步，无审批流）
 */
class Duty extends AuthBase
{
    // 今日值班（含是否轮到自己 + 轮值花名册；供仪表盘卡片与换班对象选择）
    public function today()
    {
        $user  = current_user();
        $today = date('Y-m-d');
        $duty  = DutyService::dutyOn($today);
        return api_ok([
            'date'   => $today,
            'duty'   => $duty,
            'zone'   => GameSide::moduleOn('duty') ? GameSide::zone() : '',
            'is_me'  => $duty && $user && (int)$duty['user_id'] === (int)$user->id,
            'roster' => $this->roster(),
        ]);
    }

    // 轮值花名册（换班对象候选：参与轮值的成员）
    private function roster(): array
    {
        $cfg = DutyService::config();
        if (!$cfg) {
            return [];
        }
        $rows = DutyMember::where('config_id', $cfg->id)
            ->order('sort', 'asc')->order('id', 'asc')->column('user_id');
        if (!$rows) {
            return [];
        }
        $users = User::whereIn('id', $rows)->where('status', 'approved')
            ->field('id, username, nickname')->select()->toArray();
        $map = array_column($users, null, 'id');
        $out = [];
        foreach ($rows as $uid) {
            $u = $map[$uid] ?? null;
            if ($u) {
                $out[] = ['id' => (int)$u['id'], 'nickname' => $u['nickname'] ?: $u['username']];
            }
        }
        return $out;
    }

    // 我的换班：待我确认的请求 + 我发起的请求（附双方昵称，前端直接展示）
    public function swaps()
    {
        $user = current_user();
        if (!$user) {
            return api_err('未登录', 401, 401);
        }
        $pendingMe = DutySwap::where('to_uid', $user->id)->where('status', 'pending')
            ->order('id', 'desc')->select()->toArray();
        $mine = DutySwap::where('from_uid', $user->id)->order('id', 'desc')->limit(20)->select()->toArray();
        $uids = [];
        foreach (array_merge($pendingMe, $mine) as $s) {
            $uids[] = $s['from_uid'];
            $uids[] = $s['to_uid'];
        }
        $users = $uids ? User::whereIn('id', array_unique($uids))->column('nickname, username', 'id') : [];
        $name = function ($uid) use ($users) {
            $u = $users[$uid] ?? null;
            return $u ? ($u['nickname'] ?: $u['username']) : '';
        };
        foreach ($pendingMe as &$s) {
            $s['from_name'] = $name($s['from_uid']);
            $s['to_name']   = $name($s['to_uid']);
        }
        unset($s);
        foreach ($mine as &$s) {
            $s['from_name'] = $name($s['from_uid']);
            $s['to_name']   = $name($s['to_uid']);
        }
        unset($s);
        return api_ok(['pending_me' => $pendingMe, 'mine' => $mine]);
    }

    // 发起换班请求（仅当天值班人可发起，指定换班对象与日期）
    public function requestSwap()
    {
        $user = current_user();
        if (!$user) {
            return api_err('未登录', 401, 401);
        }
        $date  = trim((string)$this->request->post('date', date('Y-m-d')));
        $toUid = (int)$this->request->post('to_uid', 0);
        if (strtotime($date) === false) {
            return api_err('日期格式不正确');
        }
        if ($toUid <= 0 || $toUid === (int)$user->id) {
            return api_err('请选择换班对象');
        }
        $duty = DutyService::dutyOn($date);
        if (!$duty || (int)$duty['user_id'] !== (int)$user->id) {
            return api_err('只有该日值班人才能发起换班');
        }
        $cfg = DutyService::config();
        if (!$cfg) {
            return api_err('值班表还没配置');
        }
        // v5.36.0：换班对象必须在这份值班花名册里；分端时还得是本端或不分类的人
        $inRoster = DutyMember::where('config_id', $cfg->id)->where('user_id', $toUid)->find();
        if (!$inRoster) {
            return api_err('换班对象不在轮值名单里');
        }
        $toUser = User::find($toUid);
        $toSide = $toUser ? GameSide::userSide($toUser) : '';
        if ($toUser && $toSide !== '' && (string)$cfg->game_side !== '' && $toSide !== (string)$cfg->game_side) {
            return api_err('换班对象不在这个专区');
        }
        $dup = DutySwap::where('duty_date', $date)->where('from_uid', $user->id)->where('status', 'pending')->find();
        if ($dup) {
            return api_err('该日已有待确认的换班请求');
        }
        DutySwap::create([
            'config_id' => (int)$cfg->id,
            'duty_date' => $date,
            'from_uid'  => $user->id,
            'to_uid'    => $toUid,
            'status'    => 'pending',
        ]);
        return api_ok([], '换班请求已发出，等待对方确认');
    }

    // 确认/拒绝换班（action=accept|reject）
    public function resolveSwap($id)
    {
        $user = current_user();
        if (!$user) {
            return api_err('未登录', 401, 401);
        }
        $action = (string)$this->request->post('action', '');
        if (!in_array($action, ['accept', 'reject'], true)) {
            return api_err('非法的操作');
        }
        $swap = DutySwap::find((int)$id);
        if (!$swap || (int)$swap->to_uid !== (int)$user->id) {
            return api_err('换班请求不存在', 404);
        }
        if ($swap->status !== 'pending') {
            return api_err('该请求已处理过');
        }
        $swap->save([
            'status'      => $action === 'accept' ? 'accepted' : 'rejected',
            'resolved_at' => date('Y-m-d H:i:s'),
        ]);
        return api_ok([], $action === 'accept' ? '已确认换班，当天值班已对调' : '已拒绝换班');
    }
}
