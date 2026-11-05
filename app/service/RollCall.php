<?php
declare (strict_types = 1);

namespace app\service;

use think\facade\Cache;
use think\facade\Db;

/**
 * 比赛日集合点名（v5.36.34）
 *
 * 队长开一张点名单（几点集合、几点截止、可挂一场比赛），成员点「到 / 迟到 / 不来」，
 * 一人一行、改口是整行覆盖（与战绩同一个口径）。到点前 LEAD_MIN 分钟顺路往 QQ 群喊一声，
 * 那一路是 best-effort：这一队没绑群、机器人被停用、群里发不出去，都只影响那一条群消息，
 * 站内的点名单本身照常开、照常算 —— notify_at 记着喊过没有，一张单只喊一次。
 *
 * 生效靠「读的时候顺路查」：与赛前提醒同一套（见 AuthCheck 里的 tickLazy），
 * 平台后台那颗「立即扫描」也会把点名一起扫（PlatformRemind::scan），不需要配 cron。
 */
class RollCall
{
    /** 集合前多少分钟往群里喊一声 */
    public const LEAD_MIN = 15;

    /** 懒触发按队限频（60 秒内同一队只扫一次） */
    public const TICK_GAP = 60;

    /** 一次最多处理多少张单 */
    public const BATCH = 20;

    /** 集合时间过了这么久还没喊，就不再补喊了（喊出来是条过期通知，反而误导） */
    public const STALE_MIN = 30;

    /** 三个按钮的口径，前端与统计都用这一份 */
    public const REPLIES = ['in' => '到了', 'late' => '迟到', 'out' => '不来'];

    public static function replyLabel(string $r): string
    {
        return (string)(self::REPLIES[$r] ?? '');
    }

    /** 懒触发入口：有人打开站点时顺路查一次，按队 60 秒限频 */
    public static function tickLazy(int $tenantId): array
    {
        if ($tenantId <= TenantContext::PLATFORM_ID) {
            return ['tenant' => $tenantId, 'sent' => 0, 'skipped' => 'platform'];
        }
        $lock = 'yys_rollcall_tick_t' . $tenantId;
        if (Cache::get($lock) !== null) {
            return ['tenant' => $tenantId, 'sent' => 0, 'skipped' => 'gap'];
        }
        Cache::set($lock, 1, self::TICK_GAP);
        $r = self::tick($tenantId);
        $r['skipped'] = '';
        return $r;
    }

    /** 平台手动触发：把所有已上线的队各扫一遍（与赛前提醒共用那颗钮） */
    public static function tickAll(int $max = 200): array
    {
        $tids = Db::name('tenants')->where('status', 'approved')
            ->order('id')->limit(max(1, $max))->column('id');
        $sent = 0;
        foreach ($tids as $tid) {
            $sent += (int)self::tick((int)$tid)['sent'];
        }
        return ['tenants' => count($tids), 'sent' => $sent];
    }

    /**
     * 扫这一队「该喊了、还没喊过」的点名单，往 QQ 群喊一声。
     * 发没发出去都写 notify_at：一张单只喊一次，失败的这一次落管理日志。
     */
    public static function tick(int $tenantId): array
    {
        $now = time();
        $until    = date('Y-m-d H:i:s', $now + self::LEAD_MIN * 60);
        $staleAgo = date('Y-m-d H:i:s', $now - self::STALE_MIN * 60);
        $sent = 0;
        $rows = Db::name('roll_calls')
            ->where('tenant_id', $tenantId)
            ->whereNull('notify_at')
            ->whereNull('revoked_at')
            ->where('call_at', '<=', $until)
            ->order('call_at', 'asc')
            ->limit(self::BATCH)
            ->select()->toArray();
        foreach ($rows as $r) {
            $id = (int)$r['id'];
            if ((string)$r['call_at'] < $staleAgo) {
                // 集合时间都过去半小时了才扫到（这一队没人打开站点），再喊是条过期通知
                Db::name('roll_calls')->where('id', $id)->update(['notify_at' => date('Y-m-d H:i:s')]);
                continue;
            }
            $ok = self::pushGroup($tenantId, self::groupText($r, $now));
            Db::name('roll_calls')->where('id', $id)->update(['notify_at' => date('Y-m-d H:i:s')]);
            $sent++;
            self::audit($tenantId, $id, (string)$r['title'], $ok);
        }
        return ['tenant' => $tenantId, 'sent' => $sent, 'group_ok' => $sent];
    }

    /** 群里那一句长什么样 */
    public static function groupText(array $r, int $now): string
    {
        $callAt = (string)($r['call_at'] ?? '');
        $ts     = strtotime($callAt);
        $when   = $ts ? date('m月d日 H:i', $ts) : $callAt;
        $left   = $ts ? intdiv($ts - $now + 59, 60) : 0;
        $line2  = $left > 0 ? ('还有 ' . $left . ' 分钟集合') : ('集合时间 ' . $when . ' 已到');
        $out = [
            '【集合点名】' . mb_substr((string)($r['title'] ?? ''), 0, 40),
            $line2 . '（' . $when . '）',
            '打开战队站点，在首页点「到 / 迟到 / 不来」。',
        ];
        $dl = trim((string)($r['deadline'] ?? ''));
        if ($dl !== '' && strtotime($dl)) {
            $out[] = '报名截至 ' . date('m月d日 H:i', strtotime($dl));
        }
        return implode("\n", $out);
    }

    /** QQ 群那一路：本队有绑群、机器人启用才发；返回到底送没送到（口径与赛前提醒一致） */
    protected static function pushGroup(int $tenantId, string $text): bool
    {
        try {
            $bind = Db::name('bot_group_bindings')->where('tenant_id', $tenantId)->find();
            if (!$bind) {
                return false;
            }
            $bot = Db::name('bot_tenant_bots')->where('tenant_id', $tenantId)->find();
            if (!$bot || (string)$bot['callback_key'] === '') {
                return false;
            }
            return QqBot::sendGroupTextWithBot((string)$bind['group_openid'], $text, (string)$bot['callback_key']);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** 队长手动「再喊一次」也走这一句，返回送没送到 */
    public static function broadcast(int $tenantId, array $row): bool
    {
        return self::pushGroup($tenantId, self::groupText($row, time()));
    }

    /** 这一次群推的结果落管理日志（admin_id=0：系统顺路干的，不是某个人点的钮） */
    protected static function audit(int $tenantId, int $id, string $title, bool $ok): void
    {
        try {
            Db::name('admin_logs')->insert([
                'tenant_id'  => $tenantId,
                'admin_id'   => 0,
                'admin_name' => '集合点名',
                'action'     => '集合点名',
                'detail'     => '点名单 #' . $id . ' ' . mb_substr($title, 0, 60)
                    . ($ok ? '：QQ 群里喊到了' : '：QQ 群那一路没送到（本队没绑群或机器人停用），站内照常'),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            // 日志写不进不影响点名本身
        }
    }

    /**
     * 应到名单：本队已上线成员，跟着这张单的端别走（口径与公告回执的分母一致）。
     * 分端没开、或这张单是全队共享（game_side 空）时，就是全队。
     * 端别跟着「撞车日程」那一块开关走：点名单多数是挂在比赛日上的。
     */
    public static function roster(int $tenantId, string $gameSide): array
    {
        $q = Db::name('users')->where('tenant_id', $tenantId)->where('status', 'approved');
        if (GameSide::moduleOnFor($tenantId, 'race') && $gameSide !== '') {
            $q = $q->whereIn('game_side', [$gameSide, '']);
        }
        $rows = $q->field('id, username, nickname')->order('id')->select()->toArray();
        $out = [];
        foreach ($rows as $r) {
            $out[(int)$r['id']] = [
                'id'       => (int)$r['id'],
                'nickname' => (string)($r['nickname'] ?? '') ?: (string)($r['username'] ?? ''),
            ];
        }
        return $out;
    }

    /**
     * 一张单 + 回执统计 + 指定成员的应答，拼成给前端的形状。
     * $forUserId 传 0 就只出统计不出 my_reply（管理端那种）。
     */
    public static function present(array $row, int $forUserId): array
    {
        $id    = (int)($row['id'] ?? 0);
        $tid   = (int)($row['tenant_id'] ?? TenantContext::id());
        $side  = (string)($row['game_side'] ?? '');
        $roster = self::roster($tid, $side);
        $replies = Db::name('roll_call_replies')->where('call_id', $id)
            ->field('user_id, reply, note, replied_at')->select()->toArray();
        $byUser = [];
        foreach ($replies as $rp) {
            $byUser[(int)$rp['user_id']] = $rp;
        }
        $groups = ['in' => [], 'late' => [], 'out' => [], 'none' => []];
        foreach ($roster as $uid => $m) {
            $rp = $byUser[$uid] ?? null;
            $r  = $rp ? (string)$rp['reply'] : '';
            $item = ['id' => $uid, 'nickname' => $m['nickname']];
            if ($rp && isset(self::REPLIES[$r])) {
                $item['note']       = (string)($rp['note'] ?? '');
                $item['replied_at'] = (string)($rp['replied_at'] ?? '');
                $groups[$r][] = $item;
            } else {
                $groups['none'][] = $item;
            }
        }
        $mine = $byUser[$forUserId] ?? null;
        $now  = date('Y-m-d H:i:s');
        $dl   = trim((string)($row['deadline'] ?? ''));
        return [
            'id'         => $id,
            'title'      => (string)($row['title'] ?? ''),
            'event_id'   => (int)($row['event_id'] ?? 0),
            'game_side'  => $side,
            'call_at'    => (string)($row['call_at'] ?? ''),
            'deadline'   => $dl,
            'notify_at'  => (string)($row['notify_at'] ?? ''),
            'revoked_at' => (string)($row['revoked_at'] ?? ''),
            'created_at' => (string)($row['created_at'] ?? ''),
            'state'      => trim((string)($row['revoked_at'] ?? '')) !== '' ? 'revoked'
                : ((string)($row['call_at'] ?? '') > $now ? 'upcoming' : 'gathering'),
            'closed'     => ($dl !== '' && $dl < $now) ? 1 : 0,
            'total'      => count($roster),
            'counts'     => ['in' => count($groups['in']), 'late' => count($groups['late']),
                             'out' => count($groups['out']), 'none' => count($groups['none'])],
            'my_reply'   => $mine ? (string)($mine['reply'] ?? '') : '',
            'my_note'    => $mine ? (string)($mine['note'] ?? '') : '',
            'list_in'    => $groups['in'],
            'list_late'  => $groups['late'],
            'list_out'   => $groups['out'],
            'list_none'  => $groups['none'],
        ];
    }
}
