<?php
declare (strict_types = 1);

namespace app\model;

use think\Model;

class TrainingRound extends Model
{
    use \app\model\concern\BelongsToTenant;
    protected $name = 'training_rounds';

    protected $autoWriteTimestamp = 'datetime';
    protected $createTime = 'created_at';
    protected $updateTime = false;
}
