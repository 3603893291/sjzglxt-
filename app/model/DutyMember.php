<?php
declare (strict_types = 1);

namespace app\model;

use think\Model;

class DutyMember extends Model
{
    use \app\model\concern\BelongsToTenant;
    protected $name = 'duty_members';

    protected $autoWriteTimestamp = 'datetime';
    protected $createTime = 'created_at';
    protected $updateTime = false;
}
