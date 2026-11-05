<?php
declare(strict_types=1);

namespace app\controller;

use app\model\Team;
use app\model\TrainingMatch;
use app\model\TrainingRound;
use app\model\TrainingSignup;
use app\model\User;
use app\service\BattleService;
use app\service\GameSide;
use think\facade\Db;

/**
 * 队内训练赛（v5.6.0）
 * 补「本队内部对抗」入口：任何登录成员可发起；管理动作（抽签/分组/录分/取消）
 * 需 training 权限或发起人身份（轻量裁决，不走赛事组）。
 * 全程带「训练」标签：不计入任何正式战绩，纯本队数据。
 * 复用约战引擎的地图池（rotation_maps）与双Ban+抽签思路。
 */
class Training extends AuthBase
{
    private function noPerm()
    {
        return api_err('您没有权限执行此操作，请联系超级管理员分配权限', 403, 403);
    }

    /** 是否可执行管理动作（training 权限或发起人） */
    private function canManage(TrainingMatch $m): bool
    {
        $u = current_user();
        return $u && (admin_can('training') || (int)$m->created_by === (int)$u->id);
    }

    // 列表（含小局比分摘要）
    public function index()
    {
        $status = (string)$this->request->get('status', '');
        $query  = TrainingMatch::order('id', 'desc');
        // v5.36.0：分端时成员只看本端 + 全队共享；持 training 权限的管理员要能管两端
        $query  = GameSide::scope($query, 'training', admin_can('training'));
        if (in_array($status, ['signup', 'ongoing', 'finished', 'canceled'], true)) {
            $query->where('status', $status);
        }
        $list = $query->limit(100)->select()->toArray();
        // 附加：队名/发起人名/比分
        $teamIds = $userIds = [];
        foreach ($list as $r) {
            if ($r['team_a_id']) $teamIds[] = $r['team_a_id'];
            if ($r['team_b_id']) $teamIds[] = $r['team_b_id'];
            $userIds[] = $r['created_by'];
        }
        $teams = Team::whereIn('id', array_unique($teamIds))->column('name', 'id');
        $users = User::whereIn('id', array_unique($userIds))->column('nickname, username', 'id');
        foreach ($list as &$r) {
            $r['team_a_name'] = $teams[$r['team_a_id']] ?? '';
            $r['team_b_name'] = $teams[$r['team_b_id']] ?? '';
            $u = $users[$r['created_by']] ?? null;
            $r['creator_name'] = $u ? ($u['nickname'] ?: $u['username']) : '';
            $score = Db::name('training_rounds')->where('match_id', $r['id'])->column('result');
            $r['score_a'] = count(array_filter($score, fn($x) => $x === 'a_win'));
            $r['score_b'] = count(array_filter($score, fn($x) => $x === 'b_win'));
        }
        unset($r);
        return api_ok(['list' => $list]);
    }

    // 详情（含小局与报名名单）
    public function detail($id)
    {
        $m = TrainingMatch::find((int)$id);
        if (!$m || !GameSide::canSee('training', (string)$m->game_side, admin_can('training'))) {
            return api_err('训练赛不存在', 404);
        }
        $rounds  = TrainingRound::where('match_id', $m->id)->order('round_no', 'asc')->select();
        $signups = TrainingSignup::where('match_id', $m->id)->order('id', 'asc')->select()->toArray();
        $uids = array_column($signups, 'user_id');
        $users = User::whereIn('id', $uids)->column('nickname, username', 'id');
        foreach ($signups as &$s) {
            $u = $users[$s['user_id']] ?? null;
            $s['nickname'] = $u ? ($u['nickname'] ?: $u['username']) : '';
        }
        unset($s);
        $teams = Team::whereIn('id', array_filter([$m->team_a_id, $m->team_b_id]))->column('name', 'id');
        $me = current_user();
        return api_ok([
            'match'    => $m,
            'rounds'   => $rounds,
            'signups'  => $signups,
            'teams'    => $teams,
            'map_pool' => BattleService::mapPool(),
            'can_manage' => $me ? $this->canManage($m) : false,
            'my_signup'  => $me ? (bool)TrainingSignup::where('match_id', $m->id)->where('user_id', $me->id)->find() : false,
        ]);
    }

    // 发起训练赛（v5.6.1 起限管理员：超管/站长恒持，普通管理员需 training 权限；成员可报名参与）
    public function create()
    {
        $user = current_user();
        if (!$user) {
            return api_err('未登录', 401, 401);
        }
        if (!admin_can('training')) {
            return api_err('训练赛需由管理员发起，请联系管理员创建', 403, 403);
        }
        $title   = trim((string)$this->request->post('title', ''));
        $mode    = (string)$this->request->post('mode', 'squad');
        $time    = trim((string)$this->request->post('match_time', ''));
        $format  = (string)$this->request->post('series_format', 'bo3');
        $mapMode = (string)$this->request->post('map_mode', 'banpick');
        $fixedMap = (int)$this->request->post('fixed_map_id', 0);
        $deadline = trim((string)$this->request->post('signup_deadline', ''));
        $teamA = (int)$this->request->post('team_a_id', 0);
        $teamB = (int)$this->request->post('team_b_id', 0);
        $side  = GameSide::moduleOn('training') ? GameSide::clean($this->request->post('game_side', '')) : '';

        if (!in_array($mode, ['squad', 'free'], true)) {
            return api_err('对抗模式不正确');
        }
        if (!in_array($format, ['bo1', 'bo3'], true)) {
            return api_err('局数只能是 BO1 或 BO3');
        }
        if (!in_array($mapMode, ['fixed', 'banpick', 'random'], true)) {
            return api_err('地图方式不正确');
        }
        if ($time === '' || strtotime($time) === false) {
            return api_err('请填写开赛时间');
        }
        if (mb_strlen($title) > 50) {
            return api_err('标题过长');
        }
        if ($mode === 'squad') {
            if ($teamA <= 0 || $teamB <= 0 || $teamA === $teamB) {
                return api_err('请选择两个不同的小队');
            }
            if (!Team::find($teamA) || !Team::find($teamB)) {
                return api_err('小队不存在');
            }
            if ($side !== '') {
                // 分端的比赛只能拉本端或全队共享的小队
                $bad = Team::whereIn('id', [$teamA, $teamB])->whereNotIn('game_side', [$side, ''])->find();
                if ($bad) {
                    return api_err('所选小队不属于这个专区');
                }
            }
        } else {
            if ($deadline === '' || strtotime($deadline) === false) {
                return api_err('自由分组需填写报名截止时间');
            }
        }
        if ($mapMode === 'fixed' && $fixedMap <= 0) {
            return api_err('固定地图模式请选择地图');
        }

        $m = TrainingMatch::create([
            'title'           => $title,
            'mode'            => $mode,
            'team_a_id'       => $mode === 'squad' ? $teamA : null,
            'team_b_id'       => $mode === 'squad' ? $teamB : null,
            'match_time'      => date('Y-m-d H:i:s', strtotime($time)),
            'series_format'   => $format,
            'map_mode'        => $mapMode,
            'fixed_map_id'    => $mapMode === 'fixed' ? $fixedMap : null,
            'signup_deadline' => $mode === 'free' ? date('Y-m-d H:i:s', strtotime($deadline)) : null,
            'status'          => 'signup',
            'created_by'      => $user->id,
            'game_side'       => $side,
        ]);
        // 固定地图模式直接生成小局骨架
        if ($mapMode === 'fixed') {
            $this->makeRounds($m, $fixedMap);
        }
        return api_ok(['id' => $m->id], '训练赛已发起');
    }

    /** 生成小局骨架（同图攻防互换；bo3 第三局同图随机攻方） */
    private function makeRounds(TrainingMatch $m, ?int $fixedMapId): void
    {
        $need = $m->series_format === 'bo1' ? 1 : 3;
        $map  = $fixedMapId ? Db::name('rotation_maps')->where('id', $fixedMapId)->field('id, name')->find() : null;
        $side = random_int(0, 1) === 0 ? 'a' : 'b';
        for ($i = 1; $i <= $need; $i++) {
            TrainingRound::create([
                'match_id'      => $m->id,
                'round_no'      => $i,
                'map_id'        => $map['id'] ?? null,
                'map_name'      => $map['name'] ?? '',
                'attacker_side' => $side,
            ]);
            $side = $side === 'a' ? 'b' : 'a';
        }
    }

    // 报名 / 退出（free 模式，报名截止前）
    public function signup($id)
    {
        $user = current_user();
        if (!$user) {
            return api_err('未登录', 401, 401);
        }
        $m = TrainingMatch::find((int)$id);
        if (!$m || !GameSide::canSee('training', (string)$m->game_side)) {
            return api_err('训练赛不存在', 404);
        }
        if ($m->mode !== 'free') {
            return api_err('小队对抗模式无需报名');
        }
        if (!GameSide::canJoin((string)$m->game_side)) {
            return api_err('这场训练赛属于另一个专区，你报名不了');
        }
        if ($m->status !== 'signup') {
            return api_err('当前状态不可报名');
        }
        if ($m->signup_deadline && strtotime((string)$m->signup_deadline) < time()) {
            return api_err('报名已截止');
        }
        $action = (string)$this->request->post('action', 'join');
        $row = TrainingSignup::where('match_id', $m->id)->where('user_id', $user->id)->find();
        if ($action === 'leave') {
            if ($row) {
                $row->delete();
            }
            return api_ok([], '已退出报名');
        }
        if (!$row) {
            TrainingSignup::create(['match_id' => $m->id, 'user_id' => $user->id]);
        }
        return api_ok([], '报名成功');
    }

    // 自由分组（报名截止后）：自动均分或手动指定（发起人/training 权限）
    public function assign($id)
    {
        $m = TrainingMatch::find((int)$id);
        if (!$m) {
            return api_err('训练赛不存在', 404);
        }
        if (!$this->canManage($m)) {
            return $this->noPerm();
        }
        if ($m->mode !== 'free') {
            return api_err('仅自由分组模式需要分组');
        }
        if ($m->status !== 'signup') {
            return api_err('当前状态不可分组');
        }
        $signups = TrainingSignup::where('match_id', $m->id)->order('id', 'asc')->select();
        if (count($signups) < 2) {
            return api_err('报名人数不足 2 人，无法分组');
        }
        // 手动指定优先：sides = {uid: 'a'|'b'}
        $sides = $this->request->post('sides/a', []);
        if ($sides) {
            foreach ($signups as $s) {
                $side = strtolower((string)($sides[(string)$s->user_id] ?? ''));
                if (!in_array($side, ['a', 'b'], true)) {
                    return api_err('存在未分配阵营的成员');
                }
                $s->save(['side' => $side]);
            }
        } else {
            // 自动均分：按报名顺序交替分边
            $side = 'a';
            foreach ($signups as $s) {
                $s->save(['side' => $side]);
                $side = $side === 'a' ? 'b' : 'a';
            }
        }
        $m->save(['status' => 'ongoing']);
        // 自由分组此时才生成小局骨架（随机/双Ban抽签在 draw 里做地图）
        if ($m->map_mode === 'fixed') {
            $this->makeRounds($m, (int)$m->fixed_map_id);
        }
        return api_ok([], '分组完成，训练赛进入进行中');
    }

    // 双Ban + 抽签（banpick 模式；发起人/training 权限）
    public function draw($id)
    {
        $m = TrainingMatch::find((int)$id);
        if (!$m) {
            return api_err('训练赛不存在', 404);
        }
        if (!$this->canManage($m)) {
            return $this->noPerm();
        }
        if ($m->map_mode !== 'banpick') {
            return api_err('仅双Ban抽签模式可抽签');
        }
        if (in_array($m->status, ['finished', 'canceled'], true)) {
            return api_err('训练赛已结束，不能抽签');
        }
        $banA = (int)$this->request->post('ban_a_map_id', 0);
        $banB = (int)$this->request->post('ban_b_map_id', 0);
        $need = $m->series_format === 'bo1' ? 1 : 3;

        // 已录胜负禁止重抽
        $hasResult = TrainingRound::where('match_id', $m->id)->whereNotNull('result')->find();
        if ($m->drawn_at !== null && $hasResult) {
            return api_err('已有小局录入胜负，不能重抽');
        }

        $banned = array_values(array_unique(array_filter([$banA, $banB])));
        $pool = Db::name('rotation_maps')->where('tenant_id', 0)
            ->when($banned !== [], function ($q) use ($banned) { $q->whereNotIn('id', $banned); })
            ->order('sort', 'asc')->order('id', 'asc')
            ->field('id, name')->select()->toArray();
        if (count($pool) < 1) {
            return api_err('地图池剔除禁用图后已无可用地图');
        }

        // 清旧局重建骨架：逐局随机抽图（禁图不可选）+ 随机攻方
        TrainingRound::where('match_id', $m->id)->delete();
        for ($i = 1; $i <= $need; $i++) {
            $pick = $pool[array_rand($pool)];
            TrainingRound::create([
                'match_id'      => $m->id,
                'round_no'      => $i,
                'map_id'        => $pick['id'],
                'map_name'      => $pick['name'],
                'attacker_side' => random_int(0, 1) === 0 ? 'a' : 'b',
            ]);
        }
        $m->save(['ban_a_map_id' => $banA ?: null, 'ban_b_map_id' => $banB ?: null, 'drawn_at' => date('Y-m-d H:i:s')]);
        return api_ok([], '抽签完成，小局地图与攻守已生成');
    }

    // 随机地图（random 模式；发起人/training 权限）
    public function randomMaps($id)
    {
        $m = TrainingMatch::find((int)$id);
        if (!$m) {
            return api_err('训练赛不存在', 404);
        }
        if (!$this->canManage($m)) {
            return $this->noPerm();
        }
        if ($m->map_mode !== 'random') {
            return api_err('仅随机地图模式可用');
        }
        $pool = Db::name('rotation_maps')->where('tenant_id', 0)
            ->order('sort', 'asc')->order('id', 'asc')->field('id, name')->select()->toArray();
        if (!$pool) {
            return api_err('地图池为空');
        }
        TrainingRound::where('match_id', $m->id)->delete();
        $need = $m->series_format === 'bo1' ? 1 : 3;
        for ($i = 1; $i <= $need; $i++) {
            $pick = $pool[array_rand($pool)];
            TrainingRound::create([
                'match_id'      => $m->id,
                'round_no'      => $i,
                'map_id'        => $pick['id'],
                'map_name'      => $pick['name'],
                'attacker_side' => random_int(0, 1) === 0 ? 'a' : 'b',
            ]);
        }
        return api_ok([], '随机地图已生成');
    }

    // 录入小局胜负（发起人/training 权限，轻量裁决）
    public function roundResult($id, $rid)
    {
        $m = TrainingMatch::find((int)$id);
        if (!$m) {
            return api_err('训练赛不存在', 404);
        }
        if (!$this->canManage($m)) {
            return $this->noPerm();
        }
        if ($m->status === 'canceled') {
            return api_err('训练赛已取消');
        }
        $round = TrainingRound::where('match_id', $m->id)->where('id', (int)$rid)->find();
        if (!$round) {
            return api_err('小局不存在', 404);
        }
        $result = (string)$this->request->post('result', '');
        if (!in_array($result, ['a_win', 'b_win'], true)) {
            return api_err('请选择胜方');
        }
        $user = current_user();
        $round->save(['result' => $result, 'result_by' => $user->id]);

        // 全部小局录完 → 按多数判定总胜负并结束
        $all = TrainingRound::where('match_id', $m->id)->order('round_no', 'asc')->select();
        $done = count(array_filter($all->toArray(), fn($r) => $r['result'] !== null));
        if ($done === count($all) && count($all) > 0) {
            $a = count(array_filter($all->toArray(), fn($r) => $r['result'] === 'a_win'));
            $b = count(array_filter($all->toArray(), fn($r) => $r['result'] === 'b_win'));
            $m->save([
                'status' => 'finished',
                'result' => $a >= $b ? 'a_win' : 'b_win',
                'result_note' => $a . ' : ' . $b,
            ]);
        } elseif ($m->status === 'signup') {
            $m->save(['status' => 'ongoing']);
        }
        return api_ok([], '已记录该局胜负');
    }

    // 取消训练赛（发起人/training 权限）
    public function cancel($id)
    {
        $m = TrainingMatch::find((int)$id);
        if (!$m) {
            return api_err('训练赛不存在', 404);
        }
        if (!$this->canManage($m)) {
            return $this->noPerm();
        }
        if ($m->status === 'finished') {
            return api_err('已结束的训练赛不能取消');
        }
        $m->save(['status' => 'canceled']);
        if (admin_can('training')) {
            admin_log('训练赛', '取消训练赛 #' . $m->id . ' ' . $m->title);
        }
        return api_ok([], '训练赛已取消');
    }
}
