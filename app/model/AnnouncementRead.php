<?php
declare (strict_types = 1);

namespace app\model;

use think\Model;

class AnnouncementRead extends Model
{
    use \app\model\concern\BelongsToTenant;
    protected $name = 'announcement_reads';

    protected $autoWriteTimestamp = 'datetime';
    protected $createTime = 'created_at';
    protected $updateTime = false;
}
