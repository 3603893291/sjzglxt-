<?php
declare (strict_types = 1);

namespace app\model;

use think\Model;

class DutySwap extends Model
{
    use \app\model\concern\BelongsToTenant;
    protected $name = 'duty_swaps';

    protected $autoWriteTimestamp = 'datetime';
    protected $createTime = 'created_at';
    protected $updateTime = false;
}
