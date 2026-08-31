<?php
declare (strict_types = 1);

namespace app\model;

use think\Model;

class TrainingSignup extends Model
{
    use \app\model\concern\BelongsToTenant;
    protected $name = 'training_signups';

    protected $autoWriteTimestamp = 'datetime';
    protected $createTime = 'created_at';
    protected $updateTime = false;
}
