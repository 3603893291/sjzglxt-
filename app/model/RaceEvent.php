<?php
declare (strict_types = 1);

namespace app\model;

use think\Model;

class RaceEvent extends Model
{
    // v4.0.0：多租户行级隔离
    use \app\model\concern\BelongsToTenant;
    protected $name = 'race_events';

    // 只有创建时间
    protected $autoWriteTimestamp = 'datetime';
    protected $createTime = 'created_at';
    protected $updateTime = false;
}
