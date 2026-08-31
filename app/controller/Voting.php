<?php
declare(strict_types=1);

namespace app\controller;

use app\model\User;
use app\model\Vote;
use app\model\VoteBallot;
use app\model\VoteOption;

/**
 * 轻量投票（v5.6.0）
 * 管理端（vote 权限）：创建/提前结束/删除/查看投票人（非匿名）
 * 成员端：进行中/已结束列表、投票（一经提交不可修改）、结果分布、补充选项
 * 规则：一人一票；被禁言/封禁成员不能投票；匿名投票任何人不可见投票人
 */
class Voting extends AuthBase
{
    private function noPerm()
    {
        return api_err('您没有权限执行此操作，请联系超级管理员分配权限', 403, 403);
    }

    /** 投票是否处于可投状态（进行中且未过截止时间） */
    private function isOpen(Vote $v): bool
    {
        return $v->status === 'open' && strtotime((string)$v->deadline) > time();
    }

    /** 组装投票详情（选项 + 计票；成员视角：匿名时不返回投票人） */
    private function assemble(Vote $v, bool $withVoters): array
    {
        $options = VoteOption::where('vote_id', $v->id)->order('sort', 'asc')->order('id', 'asc')->select()->toArray();
        $ballots = VoteBallot::where('vote_id', $v->id)->select()->toArray();

        $counts = array_fill_keys(array_column($options, 'id'), 0);
        $votersByOption = [];
        foreach ($ballots as $b) {
            foreach (array_filter(explode(',', (string)$b['option_ids'])) as $oid) {
                $oid = (int)$oid;
                if (isset($counts[$oid])) {
                    $counts[$oid]++;
                    $votersByOption[$oid][] = (int)$b['user_id'];
                }
            }
        }
        $total = count($ballots);
        $names = [];
        if ($withVoters && !$v->anonymous) {
            $uids = array_unique(array_column($ballots, 'user_id'));
            $names = User::whereIn('id', $uids)->column('nickname, username', 'id');
        }
        foreach ($options as &$o) {
            $o['count'] = $counts[$o['id']] ?? 0;
            $o['percent'] = $total > 0 ? round($o['count'] * 100 / $total, 1) : 0;
            if ($withVoters && !$v->anonymous) {
                $o['voters'] = array_values(array_map(function ($uid) use ($names) {
                    $u = $names[$uid] ?? null;
                    return ['id' => $uid, 'nickname' => $u ? ($u['nickname'] ?: $u['username']) : ''];
                }, $votersByOption[$o['id']] ?? []));
            }
        }
        unset($o);

        $me = current_user();
        $myBallot = $me ? VoteBallot::where('vote_id', $v->id)->where('user_id', $me->id)->find() : null;
        return [
            'vote'      => $v,
            'options'   => $options,
            'total'     => $total,
            'is_open'   => $this->isOpen($v),
            'my_choice' => $myBallot ? array_map('intval', array_filter(explode(',', (string)$myBallot->option_ids))) : [],
        ];
    }

    // ---------- 成员端 ----------

    // 列表：进行中 / 已结束分开
    public function index()
    {
        $list = Vote::order('id', 'desc')->limit(100)->select()->toArray();
        $open = $closed = [];
        foreach ($list as $v) {
            // 到点自动冻结（惰性）
            if ($v['status'] === 'open' && strtotime((string)$v['deadline']) <= time()) {
                Vote::where('id', $v['id'])->update(['status' => 'closed']);
                $v['status'] = 'closed';
            }
            if ($v['status'] === 'open') {
                $open[] = $v;
            } else {
                $closed[] = $v;
            }
        }
        return api_ok(['open' => $open, 'closed' => $closed]);
    }

    // 详情（含结果分布；匿名投票不返回投票人）
    public function detail($id)
    {
        $v = Vote::find((int)$id);
        if (!$v) {
            return api_err('投票不存在', 404);
        }
        return api_ok($this->assemble($v, true));
    }

    // 投票（一经提交不可修改）
    public function cast($id)
    {
        $user = current_user();
        if (!$user) {
            return api_err('未登录', 401, 401);
        }
        if ($user->muted || $user->status !== 'approved') {
            return api_err('被禁言或封禁的成员不能投票');
        }
        $v = Vote::find((int)$id);
        if (!$v) {
            return api_err('投票不存在', 404);
        }
        if (!$this->isOpen($v)) {
            return api_err('投票已结束');
        }
        if (VoteBallot::where('vote_id', $v->id)->where('user_id', $user->id)->find()) {
            return api_err('您已投过票，一经提交不可修改');
        }
        $oids = array_values(array_unique(array_map('intval', (array)$this->request->post('option_ids/a', []))));
        if (!$oids) {
            return api_err('请至少选择一个选项');
        }
        $valid = VoteOption::where('vote_id', $v->id)->whereIn('id', $oids)->column('id');
        if (count($valid) !== count($oids)) {
            return api_err('包含无效选项');
        }
        $limit = $v->multi ? max(1, (int)$v->max_choices) : 1;
        if (count($oids) > $limit) {
            return api_err('最多只能选 ' . $limit . ' 项');
        }
        VoteBallot::create([
            'vote_id'    => $v->id,
            'user_id'    => $user->id,
            'option_ids' => implode(',', $oids),
        ]);
        return api_ok([], '投票成功');
    }

    // 成员补充选项（需投票开启 allow_add_option）
    public function addOption($id)
    {
        $user = current_user();
        if (!$user) {
            return api_err('未登录', 401, 401);
        }
        $v = Vote::find((int)$id);
        if (!$v) {
            return api_err('投票不存在', 404);
        }
        if (!$v->allow_add_option) {
            return api_err('该投票不允许补充选项');
        }
        if (!$this->isOpen($v)) {
            return api_err('投票已结束');
        }
        $content = trim((string)$this->request->post('content', ''));
        if ($content === '' || mb_strlen($content) > 128) {
            return api_err('请填写选项内容（不超过 128 字）');
        }
        $dup = VoteOption::where('vote_id', $v->id)->where('content', $content)->find();
        if ($dup) {
            return api_err('已有相同选项');
        }
        $max = (int)VoteOption::where('vote_id', $v->id)->count();
        if ($max >= 10) {
            return api_err('选项数已达上限（10 个）');
        }
        VoteOption::create(['vote_id' => $v->id, 'content' => $content, 'added_by' => $user->id, 'sort' => $max]);
        return api_ok([], '选项已添加');
    }

    // ---------- 管理端（vote 权限） ----------

    // 创建投票
    public function create()
    {
        if (!admin_can('vote')) {
            return $this->noPerm();
        }
        $user     = current_user();
        $title    = trim((string)$this->request->post('title', ''));
        $options  = array_values(array_filter(array_map('trim', (array)$this->request->post('options/a', [])), fn($x) => $x !== ''));
        $multi    = $this->request->post('multi') ? 1 : 0;
        $maxChoices = max(1, (int)$this->request->post('max_choices', 2));
        $anon     = $this->request->post('anonymous') ? 1 : 0;
        $allowAdd = $this->request->post('allow_add_option') ? 1 : 0;
        $deadline = trim((string)$this->request->post('deadline', ''));

        if ($title === '' || mb_strlen($title) > 128) {
            return api_err('请填写投票标题（不超过 128 字）');
        }
        if (count($options) < 2 || count($options) > 10) {
            return api_err('选项数量需在 2~10 个之间');
        }
        if ($deadline === '' || strtotime($deadline) === false) {
            return api_err('请填写截止时间');
        }
        if (strtotime($deadline) <= time()) {
            return api_err('截止时间必须晚于当前时间');
        }
        if ($multi && $maxChoices < 2) {
            return api_err('多选时限选数至少为 2');
        }

        $v = Vote::create([
            'title'            => $title,
            'multi'            => $multi,
            'max_choices'      => $multi ? $maxChoices : 1,
            'anonymous'        => $anon,
            'allow_add_option' => $allowAdd,
            'deadline'         => date('Y-m-d H:i:s', strtotime($deadline)),
            'status'           => 'open',
            'created_by'       => $user->id,
        ]);
        foreach ($options as $i => $opt) {
            if (mb_strlen($opt) > 128) {
                $v->delete();
                return api_err('选项内容过长（不超过 128 字）');
            }
            VoteOption::create(['vote_id' => $v->id, 'content' => $opt, 'added_by' => null, 'sort' => $i]);
        }
        admin_log('投票管理', '创建投票 ' . $title);
        return api_ok(['id' => $v->id], '投票已创建');
    }

    // 提前结束
    public function close($id)
    {
        if (!admin_can('vote')) {
            return $this->noPerm();
        }
        $v = Vote::find((int)$id);
        if (!$v) {
            return api_err('投票不存在', 404);
        }
        if ($v->status !== 'open') {
            return api_err('投票已结束');
        }
        $v->save(['status' => 'closed']);
        admin_log('投票管理', '提前结束投票 #' . $v->id . ' ' . $v->title);
        return api_ok([], '投票已结束');
    }

    // 删除投票（连同选项与选票）
    public function remove($id)
    {
        if (!admin_can('vote')) {
            return $this->noPerm();
        }
        $v = Vote::find((int)$id);
        if (!$v) {
            return api_err('投票不存在', 404);
        }
        VoteOption::where('vote_id', $v->id)->delete();
        VoteBallot::where('vote_id', $v->id)->delete();
        admin_log('投票管理', '删除投票 #' . $v->id . ' ' . $v->title);
        $v->delete();
        return api_ok([], '投票已删除');
    }
}
