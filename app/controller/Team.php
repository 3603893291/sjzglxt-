<?php
declare (strict_types = 1);

namespace app\controller;

use app\model\Team as TeamModel;
use app\model\User;

/**
 * 战队小队（v2）：自由加入 / 自由退出，无需审批
 */
class Team extends AuthBase
{
    // 小队列表（含成员数、队长昵称）
    public function all()
    {
        $teams = TeamModel::order('id', 'asc')->select();
        $list  = [];
        foreach ($teams as $team) {
            $leaderName = null;
            if ($team->leader_id) {
                $leader = User::find($team->leader_id);
                $leaderName = $leader ? $leader->nickname : null;
            }
            $list[] = [
                'id'           => $team->id,
                'name'         => $team->name,
                'description'  => $team->description,
                'leader_id'    => $team->leader_id,
                'leader_name'  => $leaderName,
                'member_count' => User::where('team_id', $team->id)->where('status', 'approved')->count(),
                'created_at'   => $team->created_at,
            ];
        }
        $me = current_user();
        return api_ok(['list' => $list, 'my_team_id' => $me->team_id]);
    }

    // 我的小队（含成员列表）
    public function my()
    {
        $user = current_user();
        if (!$user->team_id) {
            return api_ok(['team' => null]);
        }
        $team = TeamModel::find($user->team_id);
        if (!$team) {
            return api_ok(['team' => null]);
        }
        $members = User::where('team_id', $team->id)
            ->where('status', 'approved')
            ->field('id, username, nickname, role, created_at, role_tags')
            ->order('id', 'asc')
            ->select()
            ->toArray();

        // 安全：队员之间也互相隐藏完整 QQ——非管理员仅见后 4 位（与全站脱敏策略一致）
        // v3.9：管理员也要看 qq_full 权限，未分配的管理员与用户管理列表同样打码（前3后2）；超管恒有该权限
        $canFullQq = admin_can('qq_full');
        $admin     = is_admin();
        foreach ($members as &$m) {
            $qq = $m['username'] ?? '';
            $m['username'] = $canFullQq ? $qq : ($admin
                ? mask_qq((string)$qq)
                : ((strlen($qq) >= 4) ? '****' . substr($qq, -4) : '****'));
            // v5.3.0：成员行展示默认职责标签
            $m['role_tag'] = display_role_tag($m);
            unset($m['role_tags']);
        }
        unset($m);
        $leaderName = null;
        if ($team->leader_id) {
            $leader = User::find($team->leader_id);
            $leaderName = $leader ? $leader->nickname : null;
        }
        return api_ok([
            'team' => [
                'id'          => $team->id,
                'name'        => $team->name,
                'description' => $team->description,
                'leader_id'   => $team->leader_id,
                'leader_name' => $leaderName,
                'members'     => $members,
            ],
        ]);
    }

    // 加入小队（直接加入，无需审批）
    public function join()
    {
        $user   = current_user();
        $teamId = (int)$this->request->post('team_id', 0);

        if ($user->team_id === $teamId && $teamId > 0) {
            return api_err('你已在该小队中');
        }
        if (!$teamId || !TeamModel::find($teamId)) {
            return api_err('请选择要加入的小队');
        }

        $user->save(['team_id' => $teamId]);
        return api_ok([], '加入成功');
    }

    // 退出小队
    public function leave()
    {
        $user = current_user();
        if (!$user->team_id) {
            return api_err('你当前不在任何小队中');
        }
        $teamId = $user->team_id;
        $user->save(['team_id' => null]);

        // 如果是队长退出，清空队长标记（小队保留，由管理员重新指定）
        $team = TeamModel::find($teamId);
        // 注意用宽松比较：不同数据库驱动下字段可能返回 int/string，严格 === 会误判
        if ($team && $team->leader_id == $user->id) {
            $team->save(['leader_id' => null]);
        }

        return api_ok([], '已退出小队');
    }
}
