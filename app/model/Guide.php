<?php
declare (strict_types = 1);

namespace app\model;

use think\Model;

/**
 * 游戏攻略（v3.7：用户自发布文章，支持封面图 + 上下架）
 */
class Guide extends Model
{
    // v4.0.0：多租户行级隔离
    use \app\model\concern\BelongsToTenant;
    protected $name = 'guides';

    protected $autoWriteTimestamp = 'datetime';
    protected $createTime = 'created_at';
    protected $updateTime = 'updated_at';
}
