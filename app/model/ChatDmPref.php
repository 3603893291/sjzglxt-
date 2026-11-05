<?php
declare (strict_types = 1);

namespace app\model;

use think\Model;

/**
 * 私聊接收开关（v5.19.0）：成员自己管自己，不需要权限键。
 * 行不存在时视为允许（默认开），所以这里只在"改过"时落一行。
 */
class ChatDmPref extends Model
{
    use \app\model\concern\BelongsToTenant;
    protected $name = 'chat_dm_prefs';

    protected $autoWriteTimestamp = 'datetime';
    protected $createTime = 'updated_at';
    protected $updateTime = 'updated_at';
}
