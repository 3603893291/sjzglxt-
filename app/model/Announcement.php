<?php
declare (strict_types = 1);

namespace app\model;

use think\Model;

class Announcement extends Model
{
    // v4.0.0：多租户行级隔离
    use \app\model\concern\BelongsToTenant;
    protected $name = 'announcements';

    protected $autoWriteTimestamp = 'datetime';
    protected $createTime = 'created_at';
    protected $updateTime = 'updated_at';

    /**
     * v5.36.34：成员看得见的那些 —— 定时已到点、且没被撤回。
     * 生效是「读的时候算」，不配定时任务：到点前这条一直躺在库里，时间一到下一次读就出来了。
     * 管理端不要过这一句（队长要看得见未发与已撤回的行）。
     *
     * @param \think\db\Query $q 指向 announcements 的查询（模型查询与 Db::name 查询都认）
     */
    public static function aliveOnly($q)
    {
        $now = date('Y-m-d H:i:s');
        return $q->where(function ($w) use ($now) {
            $w->whereNull('scheduled_at')->whereOr('scheduled_at', '<=', $now);
        })->whereNull('revoked_at');
    }

    /** 某一行的现时可见性（详情、标已读、催读都用它，口径只这一处） */
    public static function isLive($ann): bool
    {
        if (!$ann) {
            return false;
        }
        $sched = trim((string)(is_object($ann) ? $ann->scheduled_at : ($ann['scheduled_at'] ?? '')));
        if ($sched !== '' && strtotime($sched) > time()) {
            return false;
        }
        $rev = trim((string)(is_object($ann) ? $ann->revoked_at : ($ann['revoked_at'] ?? '')));
        return $rev === '';
    }

    /** 这一行还归队长管、但成员看不见 —— 用来在管理列表上标「待 X 点发」 */
    public static function stateOf($ann): string
    {
        $rev = trim((string)(is_object($ann) ? $ann->revoked_at : ($ann['revoked_at'] ?? '')));
        if ($rev !== '') {
            return 'revoked';
        }
        $sched = trim((string)(is_object($ann) ? $ann->scheduled_at : ($ann['scheduled_at'] ?? '')));
        return ($sched !== '' && strtotime($sched) > time()) ? 'scheduled' : 'live';
    }
}
