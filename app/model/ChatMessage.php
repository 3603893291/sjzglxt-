<?php
declare (strict_types = 1);

namespace app\model;

use think\Model;

class ChatMessage extends Model
{
    // v4.0.0：多租户行级隔离
    use \app\model\concern\BelongsToTenant;
    protected $name = 'chat_messages';

    // 只有创建时间，没有更新时间
    protected $autoWriteTimestamp = 'datetime';
    protected $createTime = 'created_at';
    protected $updateTime = false;
}
