<?php
declare (strict_types = 1);

namespace app\model;

use think\Model;

class GunCodeComment extends Model
{
    // v4.0.0：多租户行级隔离
    use \app\model\concern\BelongsToTenant;
    protected $name = 'gun_code_comments';

    protected $autoWriteTimestamp = 'datetime';
    protected $createTime = 'created_at';
    protected $updateTime = false;
}
