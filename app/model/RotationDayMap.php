<?php
declare (strict_types = 1);

namespace app\model;

use think\Model;

/**
 * 每日组合 ↔ 地图 多选关联（v3.8.1）
 */
class RotationDayMap extends Model
{
    // v4.0.0：多租户行级隔离
    use \app\model\concern\BelongsToTenant;
    protected $name = 'rotation_day_maps';

    // 纯关联表，无需自动时间戳
    protected $autoWriteTimestamp = false;
}
