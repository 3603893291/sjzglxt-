<?php
declare (strict_types = 1);

namespace app\model;

use think\Model;

class BlockedIp extends Model
{
    protected $name = 'blocked_ips';

    protected $autoWriteTimestamp = 'datetime';
    protected $createTime = 'created_at';
    protected $updateTime = 'updated_at';
}
