<?php
declare (strict_types = 1);

namespace app\model;

use think\Model;

/**
 * 私聊举报（v5.19.0）：管理员读取私聊正文的唯一入口。
 * 只有会话当事方能举报；管理员展开正文必须挂在一个举报上，且逐次写进本站操作日志。
 */
class ChatReport extends Model
{
    use \app\model\concern\BelongsToTenant;
    protected $name = 'chat_reports';

    protected $autoWriteTimestamp = 'datetime';
    protected $createTime = 'created_at';
    protected $updateTime = false;
}
