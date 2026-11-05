<?php
declare (strict_types = 1);

namespace app\model;

use think\Model;

/**
 * 私聊已读游标（v5.19.0）：每人 × 每个对方 一行，记「已读到哪条」
 * 未读数 = 该会话里对方发给我的、比 last_read_id 新的、未被撤回的条数
 */
class ChatDmRead extends Model
{
    use \app\model\concern\BelongsToTenant;
    protected $name = 'chat_dm_reads';

    protected $autoWriteTimestamp = 'datetime';
    protected $createTime = 'updated_at';
    protected $updateTime = 'updated_at';
}
