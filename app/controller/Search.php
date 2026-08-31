<?php
declare (strict_types = 1);

namespace app\controller;

use app\model\User;

/**
 * 查询伪军（v3.8：按昵称查询是否为本战队收录成员）
 * 结果只有两种：是（能查到）/ 不确定（未收录）
 */
class Search extends AuthBase
{
    public function traitor()
    {
        $name = trim((string)$this->request->get('name', ''));
        if ($name === '') {
            return api_err('请输入要查询的名字');
        }
        if (mb_strlen($name) > 32) {
            return api_err('名字最长 32 字');
        }

        // 只查已通过审核的用户昵称；封禁/待审核账号不算收录（v5.3.0：附带默认展示的职责标签）
        $users = User::where('status', 'approved')
            ->whereLike('nickname', '%' . $name . '%')
            ->field('nickname, role_tags')
            ->limit(10)
            ->select();

        if (count($users) === 0) {
            return api_ok(['found' => false, 'list' => []]);
        }
        $list = [];
        foreach ($users as $u) {
            $list[] = ['nickname' => $u->nickname, 'role_tag' => display_role_tag($u)];
        }
        return api_ok(['found' => true, 'list' => $list]);
    }
}
