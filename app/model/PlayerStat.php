<?php
declare (strict_types = 1);

namespace app\model;

use think\Model;

/**
 * 成员战绩快照（v5.11.0）
 * 一名成员一行，由成员自己登录后经接口整行覆盖上传；站内只读，无手工录入表单。
 */
class PlayerStat extends Model
{
    // v4.0.0：多租户行级隔离
    use \app\model\concern\BelongsToTenant;
    protected $name = 'player_stats';

    protected $autoWriteTimestamp = 'datetime';
    protected $createTime = 'created_at';
    protected $updateTime = 'updated_at';
}
