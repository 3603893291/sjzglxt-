<?php
declare (strict_types = 1);

namespace app\model;

use think\Model;

class TrainingMatch extends Model
{
    use \app\model\concern\BelongsToTenant;
    protected $name = 'training_matches';

    protected $autoWriteTimestamp = 'datetime';
    protected $createTime = 'created_at';
    protected $updateTime = 'updated_at';
}
