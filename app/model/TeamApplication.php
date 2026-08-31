<?php
declare (strict_types = 1);

namespace app\model;

use think\Model;

class TeamApplication extends Model
{
    protected $name = 'team_applications';

    protected $autoWriteTimestamp = 'datetime';
    protected $createTime = 'created_at';
    protected $updateTime = 'updated_at';
}
