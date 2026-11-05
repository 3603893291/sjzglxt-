<?php
declare (strict_types = 1);

namespace app\model;

use think\Model;

// 自建房比赛日程（v3.9.6：结构与撞车 race_events 一致）
class CustomRaceEvent extends Model
{
    // v4.0.0：多租户行级隔离
    use \app\model\concern\BelongsToTenant;
    protected $name = 'custom_race_events';

    // 只有创建时间
    protected $autoWriteTimestamp = 'datetime';
    protected $createTime = 'created_at';
    protected $updateTime = false;
}
