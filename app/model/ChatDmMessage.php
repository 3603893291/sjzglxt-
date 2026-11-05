<?php
declare (strict_types = 1);

namespace app\model;

use think\Model;

/**
 * 一对一私聊消息（v5.19.0）
 * 正文的可见性：只有 from_user_id / to_user_id 双方；
 * 管理员默认读不到（见 app/controller/ChatAdmin.php —— 必须挂着一举报才能展开），
 * 平台隐身巡查通道也不给这张表。
 */
class ChatDmMessage extends Model
{
    use \app\model\concern\BelongsToTenant;
    protected $name = 'chat_dm_messages';

    protected $autoWriteTimestamp = 'datetime';
    protected $createTime = 'created_at';
    protected $updateTime = false;
}
