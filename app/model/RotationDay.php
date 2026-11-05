<?php
declare (strict_types = 1);

namespace app\model;

use think\Model;

/**
 * 轮换每日组合（v3.8.1：一天一组多张地图，每天 9 点切换）
 */
class RotationDay extends Model
{
    // v4.0.0：多租户行级隔离
    use \app\model\concern\BelongsToTenant;
    protected $name = 'rotation_days';

    protected $autoWriteTimestamp = 'datetime';
    protected $createTime = 'created_at';
    protected $updateTime = 'updated_at';
}
