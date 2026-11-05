<?php
declare (strict_types = 1);

namespace app\service;

use think\facade\Db;

/**
 * 强制平台公告（v5.36.31）：平台超管写一条，全体已上线成员登录后自动弹出。
 *
 * 四件事要说清楚，都能从代码里验，别再靠注释推断：
 * ① 「全体」和邮件群发用的是同一批人：users.status=approved，且所在战队没被平台封（tenants.status=banned）。
 *    应读人数每次现算，不落快照 —— 战队随时在上人，快照第二天就不准了。
 * ② 一次只弹一条：取「在线的公告里我最久没处理的那一条」。有几条就分几次登录点完，不会糊一脸。
 * ③ 两个按钮语义不同：
 *      我已知晓 = 这一条我读过了（这一条以后不再弹，新的公告照样弹）
 *      不再提示 = 以后平台所有公告都不弹给我（除了这一条，再写一条 announcement_id=0 的全局静音）
 *    平台侧可以把「不再提示」这颗钮整个关掉（settings p_notice_allow_mute=0）——
 *    否则一个人点一下就永久收不到强制通知，这功能等于没有。
 * ④ 阅读计数只数 platform_announcement_reads 那张表：ack 与 mute 都算「处理过了」，
 *    但分两个数回给平台，因为「点了不再提示」和「认真读了并确认」是两回事。
 *
 * 表没建（升级 SQL 没跑）时所有读接口安静退回空，不抛异常：不能让一个没补库的站点整页 500。
 */
class PlatformAnnounce
{
    public const TITLE_MAX   = 120;
    public const CONTENT_MAX = 2000;

    /** 同时在线（还在弹）的公告最多几条：再多就变成每次登录点不完的弹窗串 */
    public const ONLINE_MAX = 3;

    /** 静音开关的 settings 键（tenant_id=0） */
    public const MUTE_KEY = 'p_notice_allow_mute';

    // ==================== 读 ====================

    /** 在线（还在弹）的公告，按 id 正序 —— 「最久没处理的那条」直接取第一行 */
    public static function online(): array
    {
        try {
            return Db::name('platform_announcements')->where('status', 1)
                ->order('id', 'asc')->limit(50)->select()->toArray();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** 全部公告（平台侧列表用，倒序，带每条的处理人数） */
    public static function all(int $limit = 50): array
    {
        try {
            return Db::name('platform_announcements')->order('id', 'desc')->limit($limit)->select()->toArray();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * 现在该不该给这个人弹：返回他最久没处理的那一条在线公告，没就不返回。
     * 全局静音（announcement_id=0 那一行）在 → 一条都不弹。
     */
    public static function currentForUser(int $uid): ?array
    {
        if ($uid <= 0) {
            return null;
        }
        try {
            $muted = Db::name('platform_announcement_reads')
                ->where('announcement_id', 0)->where('user_id', $uid)->find();
            if ($muted) {
                return null;
            }
            $online = self::online();
            if ($online === []) {
                return null;
            }
            $done = Db::name('platform_announcement_reads')->where('user_id', $uid)
                ->whereIn('announcement_id', array_column($online, 'id'))
                ->column('announcement_id');
            foreach ($online as $a) {
                if (!in_array((int)$a['id'], $done, true)) {
                    return [
                        'id'      => (int)$a['id'],
                        'title'   => (string)$a['title'],
                        'content' => (string)$a['content'],
                        'at'      => (string)($a['created_at'] ?? ''),
                        'actor'   => (string)($a['actor_name'] ?? ''),
                        'mute'    => self::muteAllowed() ? 1 : 0,
                    ];
                }
            }
        } catch (\Throwable $e) {
            // 表还没建：不弹，也别把成员端接口打崩
        }
        return null;
    }

    /** 「不再提示」这颗按钮还在不在（关掉之后成员端只有「我已知晓」） */
    public static function muteAllowed(): bool
    {
        return Setting::getPlatform(self::MUTE_KEY, '1') !== '0';
    }

    public static function setMuteAllowed(bool $on): void
    {
        Setting::setPlatform(self::MUTE_KEY, $on ? '1' : '0');
    }

    // ==================== 写 ====================

    /**
     * 新建或修改一条公告。$id>0 是改（改了算同一条，已读记录保留）。
     * @return array [公告 id, 错误说明]，失败时 id=0
     */
    public static function save(int $id, string $title, string $content, int $online, int $actorId, string $actorName): array
    {
        $title   = trim($title);
        $content = trim($content);
        if ($title === '') {
            return [0, '公告标题不能空着'];
        }
        if (mb_strlen($title) > self::TITLE_MAX) {
            return [0, '标题最长 ' . self::TITLE_MAX . ' 字，现在 ' . mb_strlen($title) . ' 字'];
        }
        if ($content === '') {
            return [0, '公告正文不能空着'];
        }
        if (mb_strlen($content) > self::CONTENT_MAX) {
            return [0, '正文最长 ' . self::CONTENT_MAX . ' 字，现在 ' . mb_strlen($content) . ' 字'];
        }
        $want = $online === 1 ? 1 : 0;
        if ($want === 1) {
            $others = (int)Db::name('platform_announcements')->where('status', 1)
                ->where('id', '<>', $id)->count();
            if ($others >= self::ONLINE_MAX) {
                return [0, '同时在线的公告最多 ' . self::ONLINE_MAX . ' 条，已经有 ' . $others . ' 条在弹：'
                    . '先把旧的「下线」再发新的（下线不删记录，已读人数也留着）'];
            }
        }
        $now = date('Y-m-d H:i:s');
        try {
            if ($id > 0) {
                $row = Db::name('platform_announcements')->where('id', $id)->find();
                if (!$row) {
                    return [0, '要改的公告不存在'];
                }
                Db::name('platform_announcements')->where('id', $id)->update([
                    'title'      => $title,
                    'content'    => $content,
                    'status'     => $want,
                    'updated_at' => $now,
                ]);
                return [$id, ''];
            }
            $new = (int)Db::name('platform_announcements')->insertGetId([
                'title'      => $title,
                'content'    => $content,
                'status'     => $want,
                'actor_id'   => $actorId,
                'actor_name' => mb_substr($actorName, 0, 64),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            return [$new, ''];
        } catch (\Throwable $e) {
            return [0, '写库失败：' . mb_substr($e->getMessage(), 0, 120)];
        }
    }

    /** 上线 / 下线。下线不删记录、不删已读人数 —— 事后要能对上账 */
    public static function setStatus(int $id, bool $online): bool
    {
        try {
            if ($online) {
                $others = (int)Db::name('platform_announcements')->where('status', 1)
                    ->where('id', '<>', $id)->count();
                if ($others >= self::ONLINE_MAX) {
                    return false;
                }
            }
            $n = Db::name('platform_announcements')->where('id', $id)->update([
                'status'     => $online ? 1 : 0,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            return $n > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** 删一条公告，连它的送达确认一起删（留着没人对得上的数字没意义） */
    public static function remove(int $id): bool
    {
        try {
            Db::name('platform_announcement_reads')->where('announcement_id', $id)->delete();
            return Db::name('platform_announcements')->where('id', $id)->delete() > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * 成员点按钮。$choice = ack（我已知晓）或 mute（不再提示）。
     * mute 多写一条 announcement_id=0 的全局静音；两条都靠 UNIQUE(announcement_id,user_id) 幂等。
     * @return bool 这条公告是不是真的存在（不存在时不写，免得给已删的公告补记录）
     */
    public static function respond(int $uid, int $tid, int $id, string $choice): bool
    {
        if ($uid <= 0 || $id <= 0 || !in_array($choice, ['ack', 'mute'], true)) {
            return false;
        }
        if ($choice === 'mute' && !self::muteAllowed()) {
            return false;
        }
        try {
            if (!Db::name('platform_announcements')->where('id', $id)->find()) {
                return false;
            }
            $now = date('Y-m-d H:i:s');
            self::put($uid, $tid, $id, $choice, $now);
            if ($choice === 'mute') {
                self::put($uid, $tid, 0, 'mute', $now);
            }
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** 一人一条只记一次：撞唯一键就当已经记过了（重复点、两个标签页一起点都不该报错） */
    private static function put(int $uid, int $tid, int $id, string $choice, string $now): void
    {
        $exists = Db::name('platform_announcement_reads')
            ->where('announcement_id', $id)->where('user_id', $uid)->find();
        if ($exists) {
            return;
        }
        try {
            Db::name('platform_announcement_reads')->insert([
                'announcement_id' => $id,
                'user_id'         => $uid,
                'tenant_id'       => $tid,
                'choice'          => $choice,
                'created_at'      => $now,
            ]);
        } catch (\Throwable $e) {
            // 唯一键撞上＝另一头已经记过了，正好
        }
    }

    // ==================== 阅读计数 ====================

    /**
     * 应读的人：已上线成员、所在战队没被平台封（与邮件群发同一批人，别自己再造一套口径）。
     * 返回 uid => tenant_id，按战队汇总时直接用它分组。
     */
    public static function eligible(): array
    {
        $banned = Db::name('tenants')->where('status', 'banned')->column('id');
        $q = Db::name('users')->where('status', 'approved');
        if ($banned !== []) {
            $q->whereNotIn('tenant_id', $banned);
        }
        $rows = $q->field('id, tenant_id')->select()->toArray();
        $out = [];
        foreach ($rows as $r) {
            $out[(int)$r['id']] = (int)$r['tenant_id'];
        }
        return $out;
    }

    /**
     * 一条公告的汇总：总数 + 按战队。
     * 应读人数是「现在」的人数 —— 公告发出去之后新注册的人也会被算进未读，
     * 这是对的：强制公告本来就该让每一个活着的人看到。
     */
    public static function stats(int $id): array
    {
        $need = self::eligible();
        try {
            $rows = Db::name('platform_announcement_reads')->where('announcement_id', $id)
                ->field('user_id, tenant_id, choice')->select()->toArray();
        } catch (\Throwable $e) {
            $rows = [];
        }
        $ack = 0;
        $mute = 0;
        $perTenant = [];
        foreach ($rows as $r) {
            $uid = (int)$r['user_id'];
            if (!isset($need[$uid])) {
                continue;   // 这人已经退队/被封了：他当初点过，但不该再算进分母
            }
            if ((string)$r['choice'] === 'mute') { $mute++; } else { $ack++; }
            $perTenant[$need[$uid]] = ($perTenant[$need[$uid]] ?? 0) + 1;
        }
        $needPerTenant = [];
        foreach ($need as $tid) {
            $needPerTenant[$tid] = ($needPerTenant[$tid] ?? 0) + 1;
        }
        $teams = $needPerTenant === [] ? [] : array_column(
            Db::name('tenants')->whereIn('id', array_keys($needPerTenant))
                ->field('id, name, slug')->select()->toArray(), null, 'id'
        );
        $byTeam = [];
        foreach ($needPerTenant as $tid => $n) {
            $done = $perTenant[$tid] ?? 0;
            $byTeam[] = [
                'tenant_id' => $tid,
                'name'      => (string)($teams[$tid]['name'] ?? '已删除的站点'),
                'slug'      => (string)($teams[$tid]['slug'] ?? ''),
                'need'      => $n,
                'read'      => $done,
                'unread'    => max(0, $n - $done),
            ];
        }
        // 没人点的队也要出现在汇总里，所以按未读人数多的排前面、都没人点时大队在前，一眼看出该去催谁
        usort($byTeam, static function (array $a, array $b): int {
            return [$b['unread'], $b['need']] <=> [$a['unread'], $a['need']];
        });
        $totalNeed = count($need);
        $totalRead = array_sum($perTenant);
        return [
            'announcement_id' => $id,
            'need'            => $totalNeed,
            'read'            => $totalRead,
            'unread'          => max(0, $totalNeed - $totalRead),
            'ack'             => $ack,
            'muted'           => $mute,
            'rate'            => $totalNeed > 0 ? (int)floor($totalRead * 100 / $totalNeed) : 0,
            'global_muted'    => self::globalMuted(),
            'by_team'         => $byTeam,
        ];
    }

    /** 全站有多少人点了「不再提示」（那条 announcement_id=0 的静音行） */
    public static function globalMuted(): int
    {
        try {
            $need = self::eligible();
            $ids = Db::name('platform_announcement_reads')->where('announcement_id', 0)
                ->column('user_id');
            $n = 0;
            foreach ($ids as $uid) {
                if (isset($need[(int)$uid])) { $n++; }
            }
            return $n;
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /** 表在不在（平台侧列表用来决定是提示补库还是显示空列表） */
    public static function available(): bool
    {
        try {
            Db::name('platform_announcements')->where('id', 0)->find();
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
