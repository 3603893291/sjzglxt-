<?php
declare (strict_types = 1);

namespace app\model;

use think\Model;

class DutyConfig extends Model
{
    use \app\model\concern\BelongsToTenant;
    protected $name = 'duty_configs';

    protected $autoWriteTimestamp = 'datetime';
    protected $createTime = 'created_at';
    protected $updateTime = 'updated_at';
}
