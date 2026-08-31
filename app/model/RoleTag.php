<?php
declare (strict_types = 1);

namespace app\model;

use think\Model;

/**
 * 职责标签（v5.3.0）：管理员维护的标签库（名称 + 自定义颜色）
 * 用户持有关系存 users.role_tags（JSON ID 数组，第一个为默认展示）
 */
class RoleTag extends Model
{
    // 多租户行级隔离
    use \app\model\concern\BelongsToTenant;

    protected $name = 'role_tags';

    protected $autoWriteTimestamp = 'datetime';
    protected $createTime = 'created_at';
    protected $updateTime = 'updated_at';
}
