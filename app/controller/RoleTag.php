<?php
declare (strict_types = 1);

namespace app\controller;

use app\model\RoleTag as RoleTagModel;

/**
 * 职责标签（v5.3.0）：标签库列表
 * 个人主页据此渲染自选区；聊天/伪军/小队的展示标签由各接口随数据下发
 */
class RoleTag extends AuthBase
{
    // 标签库列表（登录即可读；管理写操作在 Admin 控制器）
    public function all()
    {
        $list = RoleTagModel::order('id', 'asc')->select()->toArray();

        $me = current_user();
        return api_ok([
            'list'         => $list,
            'editable'     => role_tag_user_editable(),          // 用户能否自助改标签
            'my_tags'      => user_role_tag_ids($me),            // 我持有的标签（第一个为默认）
            'is_admin'     => is_admin(),
        ]);
    }
}
