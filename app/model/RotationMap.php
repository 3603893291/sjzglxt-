<?php
declare (strict_types = 1);

namespace app\model;

use think\Model;

/**
 * 轮换地图池（v3.8：管理员维护，按轮换天数循环展示）
 */
class RotationMap extends Model
{
    // v4.0.0：多租户行级隔离
    use \app\model\concern\BelongsToTenant;
    protected $name = 'rotation_maps';

    protected $autoWriteTimestamp = 'datetime';
    protected $createTime = 'created_at';
    protected $updateTime = 'updated_at';
}
