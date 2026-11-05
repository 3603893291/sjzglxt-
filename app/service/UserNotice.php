<?php
declare (strict_types = 1);

namespace app\service;

use think\facade\Db;

/**
 * 站内信（v5.22.0 起长论坛里，v5.32.0 论坛下线后独立成这一处）
 *
 * 一个红点装三件事：自己举报的东西有了结果、平台群发给自己的那一封、本队到点的赛前提醒（v5.33.0）。
 * 三种都写同一张表、前端一处列表，别再为每种通知各开一个入口。
 *
 * 几条口径：
 * ① 表名仍叫 forum_notices —— 论坛没了但线上库里这张表有真实数据，改名要动库与五处 DDL 拷贝，
 *    换来的只有名字好听；下面每一处读表都注明它现在装的是什么。
 * ② 举报结果这一档不写 dedupe（NULL），同一件事反复有结果就该反复各记一行。
 * ③ 平台通知（kind=platform）带 dedupe='blast:批次:人'，所以同一批重复点发送也只给这个人记一条；
 *    note 里只放摘要，整篇正文留在 mail_blasts，本人点开才回查。这一档的 ref_id 存的是批次号。
 * ④ 通知正文里不出现 QQ 号，也不出现任何头像地址；显示名一律走 user_display_name() 那套脱敏。
 */
class UserNotice
{
    public const KINDS = [
        'report'   => '举报有结果',
        'platform' => '平台通知',
        'event'    => '赛前提醒',
    ];

    /**
     * 给当事人留一句话。$dedupe 留空＝允许同一目标反复记；
     * 给了值就走 forum_notices 那个唯一键，撞上了算「这一批已经给这个人记过」，不再重复记。
     *
     * @param int $refId kind=platform 时是邮件群发的批次号，其余为 0
     * @return bool 这次真插进去一条（false＝参数不合，或被 dedupe 挡了）
     */
    public static function push(int $toUid, string $kind, int $refId, string $note, int $fromUid = 0, string $dedupe = ''): bool
    {
        if ($toUid <= 0 || !isset(self::KINDS[$kind])) {
            return false;
        }
        try {
            Db::name('forum_notices')->insert([
                'to_user_id'   => $toUid,
                'from_user_id' => $fromUid,
                'kind'         => $kind,
                'post_id'      => $refId,
                'comment_id'   => 0,
                'note'         => mb_substr(trim($note), 0, 200),
                'dedupe'       => $dedupe === '' ? null : mb_substr($dedupe, 0, 64),
                'created_at'   => date('Y-m-d H:i:s'),
            ]);
            return true;
        } catch (\Throwable $e) {
            // dedupe 撞了就是同一批已经给这个人记过一条，本来就该只有一条
            return false;
        }
    }

    /** 红点数：只数未读 */
    public static function unread(int $uid): int
    {
        if ($uid <= 0) {
            return 0;
        }
        return (int)Db::name('forum_notices')->where('to_user_id', $uid)->whereNull('read_at')->count();
    }

    /**
     * 列表：倒序取最近若干条。顶栏红点与「通知」页共用这一份。
     * 论坛下线后不再回查 forum_posts 补标题 —— 那一档数据已经没了，
     * 而且以前拿批次号去帖子表里查会撞号段、给成员挂错别人的标题。
     */
    public static function listOf(int $uid, int $limit = 30): array
    {
        if ($uid <= 0) {
            return [];
        }
        $limit = max(1, min(50, $limit));
        $rows = Db::name('forum_notices')->where('to_user_id', $uid)
            ->order('id', 'desc')->limit($limit)->select()->toArray();
        if ($rows === []) {
            return [];
        }
        $froms = self::names(array_column($rows, 'from_user_id'));
        $out   = [];
        foreach ($rows as $r) {
            $isBlast = (string)$r['kind'] === 'platform';
            $out[] = [
                'id'       => (int)$r['id'],
                'kind'     => (string)$r['kind'],
                'kindName' => self::KINDS[(string)$r['kind']] ?? (string)$r['kind'],
                'note'     => (string)$r['note'],
                'from'     => $froms[(int)$r['from_user_id']] ?? null,
                // 平台通知那一档存的是邮件群发的批次号，前端拿它去 /api/inbox/blast 回查整篇正文
                'blast'    => $isBlast ? (int)$r['post_id'] : 0,
                'read'     => $r['read_at'] === null ? 0 : 1,
                'at'       => (string)$r['created_at'],
            ];
        }
        return $out;
    }

    /** 全标已读（打开通知页就清红点，不玩逐条点） */
    public static function markRead(int $uid): int
    {
        if ($uid <= 0) {
            return 0;
        }
        return (int)Db::name('forum_notices')->where('to_user_id', $uid)->whereNull('read_at')
            ->update(['read_at' => date('Y-m-d H:i:s')]);
    }

    /** 显示名（脱敏口径），一次查一批 */
    protected static function names(array $uids): array
    {
        $uids = array_values(array_unique(array_filter(array_map('intval', $uids))));
        if ($uids === []) {
            return [];
        }
        $rows = Db::name('users')->whereIn('id', $uids)->field('id, nickname, username, status')->select()->toArray();
        $out  = [];
        foreach ($rows as $r) {
            $out[(int)$r['id']] = user_display_name((object)$r);
        }
        return $out;
    }
}
