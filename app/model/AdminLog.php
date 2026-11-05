<?php
declare (strict_types = 1);

namespace app\model;

use think\Model;

/**
 * 管理操作日志（v3.7：记录管理员的每次管理操作）
 */
class AdminLog extends Model
{
    // v4.0.0：多租户行级隔离
    use \app\model\concern\BelongsToTenant;

    protected $name = 'admin_logs';

    // 只有创建时间，没有更新时间
    protected $autoWriteTimestamp = 'datetime';
    protected $createTime = 'created_at';
    protected $updateTime = false;
}
