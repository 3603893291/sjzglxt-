<?php
declare (strict_types = 1);

namespace app\service;

use think\facade\Cache;
use think\facade\Db;

/**
 * 赛前提醒（v5.33.0）：训练赛 / 队内日程 / 跨队约战 到点前推给本队
 *
 * 三条落点（需求方拍的）：① 本队公告（带送达确认，管理员能看到谁还没点）；
 * ② 站内信「通知」页那一档 event，只发给报了名的人与创建人；③ QQ 群机器人，best-effort。
 * 群那一路按 QQ 官方 2025-04 起对机器人主动消息的限制，多半发得出、群里没有（站内那份代码已经
 * 认过这件事，见 app/controller/BotAdmin.php 绑群那段），所以送不达只把 event_reminders.group_ok
 * 记成 0，绝不影响前两条，也不重试第二次 —— 重复推送比漏推更烦人。
 *
 * 三条口径写在前面：
 * ① 一场只推一次：去重靠 event_reminders 的 UNIQUE(kind, ref_id)，不是靠缓存 —— 缓存会被清、
 *    进程会重启，靠它就会同一个训练赛推第二遍公告。
 * ② 提前量按「这一队这一个类别」存 settings（0=关，10/30/60 分钟，默认 30），队长自己选；
 *    平台另有 tenant_id=0 的一个总开关，关掉后两条触发路都不干活。
 * ③ 队内日程（custom_events）没有报名，所以那一档只出公告、不给个人发信 ——
 *    给全队每人插一条站内信只是把公告复制一遍，还会把红点撑大。
 */
class EventReminder
{
    /** 提醒类别 => 事件表 */
    public const KINDS = [
        'training' => '训练赛',
        'schedule' => '队内日程',
        'battle'   => '跨队约战',
    ];

    /** 队长能选的提前量（分钟）；0 = 这一类不提醒 */
    public const LEADS = [0, 10, 30, 60];
    public const LEAD_DEFAULT = 30;

    /** 平台总开关（settings 租户号 0）：'0' 才是关，其余含从未设置都算开 */
    public const PLATFORM_KEY = 'remind_enabled';

    /** 站内信那一档的键（UserNotice::KINDS 里要有同名一项） */
    public const NOTICE_KIND = 'event';

    /** 一次 tick 每个类别最多推几条：防止积压一晚后一把涌出十几条公告 */
    private const BATCH = 5;

    /** 懒触发的按队限频（秒）：同一队一分钟内只扫一次库 */
    private const TICK_GAP = 60;

    public static function leadKey(string $kind): string
    {
        return 'remind_lead_' . $kind;
    }

    /** 落进来的值先洗：不在档位里的（手改接口、脏数据）一律回到默认档 */
    public static function normalizeLead($raw): int
    {
        $v = (int)trim((string)$raw);
        return in_array($v, self::LEADS, true) ? $v : self::LEAD_DEFAULT;
    }

    /** 平台总开关 */
    public static function platformOn(): bool
    {
        return Setting::getPlatform(self::PLATFORM_KEY, '1') !== '0';
    }

    public static function setPlatformOn(bool $on): void
    {
        Setting::setPlatform(self::PLATFORM_KEY, $on ? '1' : '0');
    }

    /**
     * 这一队这一类提前几分钟（跨租户直读 settings，不经 Setting 的当前租户缓存 ——
     * 平台端那个手动触发要一次扫很多队，用缓存会串味）
     */
    public static function leadFor(int $tenantId, string $kind): int
    {
        if (!isset(self::KINDS[$kind])) {
            return 0;
        }
        $v = Db::name('settings')->where('tenant_id', $tenantId)
            ->where('key', self::leadKey($kind))->value('value');
        if ($v === null || $v === '') {
            return self::LEAD_DEFAULT;
        }
        return self::normalizeLead($v);
    }

    /** 这一队三类提前量的当前值（管理端那一格的数据源） */
    public static function leadsFor(int $tenantId): array
    {
        $out = [];
        foreach (self::KINDS as $kind => $name) {
            $out[$kind] = ['name' => $name, 'lead' => self::leadFor($tenantId, $kind)];
        }
        return $out;
    }

    /** 懒触发入口：登录后顺路查一次，按队 60 秒限频；返回推了几条 */
    public static function tickLazy(int $tenantId): array
    {
        if ($tenantId <= TenantContext::PLATFORM_ID || !self::platformOn()) {
            return ['tenant' => $tenantId, 'sent' => 0, 'skipped' => 'off'];
        }
        $lock = 'yys_remind_tick_t' . $tenantId;
        if (Cache::get($lock) !== null) {
            return ['tenant' => $tenantId, 'sent' => 0, 'skipped' => 'gap'];
        }
        Cache::set($lock, 1, self::TICK_GAP);
        $r = self::tick($tenantId);
        $r['skipped'] = '';
        return $r;
    }

    /**
     * 平台手动触发：把所有已上线的队各扫一遍（宝塔计划任务打那条端点走这里）。
     * 队多时一次别超过 200 队，剩下的下一次一分钟后再打。
     */
    public static function tickAll(int $max = 200): array
    {
        if (!self::platformOn()) {
            return ['tenants' => 0, 'sent' => 0, 'skipped' => 'off'];
        }
        $tids = Db::name('tenants')->where('status', 'approved')
            ->order('id')->limit(max(1, $max))->column('id');
        $sent = 0;
        foreach ($tids as $tid) {
            $sent += (int)self::tick((int)$tid)['sent'];
        }
        return ['tenants' => count($tids), 'sent' => $sent, 'skipped' => ''];
    }

    /** 扫一个队到点的事件并推出去 */
    public static function tick(int $tenantId): array
    {
        $now   = date('Y-m-d H:i:s');
        $sent  = 0;
        $group = 0;
        foreach (self::KINDS as $kind => $_name) {
            $lead = self::leadFor($tenantId, $kind);
            if ($lead <= 0) {
                continue;
            }
            $until = date('Y-m-d H:i:s', time() + $lead * 60);
            foreach (self::due($tenantId, $kind, $now, $until) as $ev) {
                $ok = self::publish($tenantId, $kind, $ev, $lead);
                if ($ok !== null) {
                    $sent++;
                    $group += $ok;
                }
            }
        }
        return ['tenant' => $tenantId, 'sent' => $sent, 'group_ok' => $group, 'skipped' => ''];
    }

    /**
     * 取这一类里「现在到窗口末尾」之间开打、且还没推过的事件行。
     * 状态判据各表不一样，都只认「还没开始」那一档：训练赛 signup、约战 accepted，
     * 日程表没有状态列（管理员改了时间就按新时间重算，删了自然就不推）。
     */
    protected static function due(int $tenantId, string $kind, string $now, string $until): array
    {
        $rows = [];
        if ($kind === 'training') {
            $rows = Db::name('training_matches')->where('tenant_id', $tenantId)
                ->where('status', 'signup')
                ->where('match_time', '>=', $now)->where('match_time', '<=', $until)
                ->order('match_time')->limit(self::BATCH)->select()->toArray();
            $field = 'match_time';
        } elseif ($kind === 'schedule') {
            $rows = Db::name('custom_events')->where('tenant_id', $tenantId)
                ->where('start_time', '>=', $now)->where('start_time', '<=', $until)
                ->order('start_time')->limit(self::BATCH)->select()->toArray();
            $field = 'start_time';
        } elseif ($kind === 'battle') {
            $rows = Db::name('battles')->where('status', 'accepted')
                ->where('battle_time', '>=', $now)->where('battle_time', '<=', $until)
                ->where(function ($q) use ($tenantId) {
                    $q->where('challenger_type', 'tenant')->where('challenger_id', $tenantId)
                        ->whereOr(function ($q2) use ($tenantId) {
                            $q2->where('defender_type', 'tenant')->where('defender_id', $tenantId);
                        });
                })
                ->order('battle_time')->limit(self::BATCH)->select()->toArray();
            $field = 'battle_time';
        } else {
            return [];
        }
        if ($rows === []) {
            return [];
        }
        $done = Db::name('event_reminders')->where('kind', $kind)->where('tenant_id', $tenantId)
            ->whereIn('ref_id', array_column($rows, 'id'))->column('ref_id');
        $done = array_map('intval', $done);
        $out  = [];
        foreach ($rows as $r) {
            if (in_array((int)$r['id'], $done, true)) {
                continue;
            }
            $out[] = ['id' => (int)$r['id'], 'at' => (string)$r[$field], 'row' => $r];
        }
        return $out;
    }

    /**
     * 推一条：公告必发，站内信给报了名的人，群那一路尽力。
     * 先落 event_reminders 再发，是为了让「发到最后一步抛异常」不至于明天又推一遍同一场 ——
     * 宁可少推一条，不可重复推一条。
     *
     * @return int|null null＝这一场这条队列早就推过了；0/1＝推出去了，1 代表 QQ 群里也收到了
     */
    protected static function publish(int $tenantId, string $kind, array $ev, int $lead): ?int
    {
        $row = $ev['row'];
        [$title, $body, $uids] = self::text($tenantId, $kind, $row, $lead);
        // v5.36.0：事件本身分端时，公告跟着那一端走；这一块没开分端就落全队共享
        $side = '';
        if ($kind === 'training' && GameSide::moduleOnFor($tenantId, 'training')) {
            $side = GameSide::clean($row['game_side'] ?? '');
        } elseif ($kind === 'schedule' && GameSide::moduleOnFor($tenantId, 'event')) {
            $side = GameSide::clean($row['game_side'] ?? '');
        }
        if ($side !== '') {
            // 个人站内信也只给本端与不分类的人（创建人可能是另一端的副管理员）
            $ok = Db::name('users')->whereIn('id', $uids)->where('status', 'approved')
                ->whereIn('game_side', [$side, ''])->column('id');
            $uids = array_values(array_intersect($uids, array_map('intval', $ok)));
        }
        $now = date('Y-m-d H:i:s');
        try {
            Db::name('event_reminders')->insert([
                'kind'      => $kind,
                'ref_id'    => (int)$row['id'],
                'tenant_id' => $tenantId,
                'event_at'  => $ev['at'],
                'lead_min'  => $lead,
                'group_ok'  => 0,
                'sent_at'   => $now,
            ]);
        } catch (\Throwable $e) {
            return null;   // 唯一键撞了＝这一场这个队已经推过
        }

        try {
            Db::name('announcements')->insert([
                'tenant_id'    => $tenantId,
                'title'        => mb_substr($title, 0, 128),
                'content'      => $body,
                'image'        => '',
                'need_receipt' => 1,
                'deadline'     => null,
                'reminded_at'  => null,
                'game_side'    => $side,
                'created_at'   => $now,
                'updated_at'   => $now,
            ]);
        } catch (\Throwable $e) {
            \think\facade\Log::warning('[EventReminder] 公告落库失败 ' . $e->getMessage());
        }

        // forum_notices 的 dedupe 是全局唯一键，所以这一串必须带收件人 ——
        // 只写「哪一场」的话，同队第二个人就收不到站内信了（第一封把键占了）
        foreach ($uids as $uid) {
            UserNotice::push((int)$uid, self::NOTICE_KIND, (int)$row['id'],
                mb_substr($title, 0, 200), 0,
                self::NOTICE_KIND . ':' . $kind . ':' . (int)$row['id'] . ':' . (int)$uid);
        }

        // 分端的提醒不发 QQ 群：群里两端的人都在，发出去就等于把另一端的内容也给他们看了
        $ok = $side === '' ? self::sendGroup($tenantId, $title) : false;
        if ($ok) {
            // 约战是两个队各推各的（同 kind+ref_id 两行），所以这里必须带上租户号
            Db::name('event_reminders')->where('kind', $kind)->where('ref_id', (int)$row['id'])
                ->where('tenant_id', $tenantId)->update(['group_ok' => 1]);
        }
        return $ok ? 1 : 0;
    }

    /** 拼标题、正文与站内信收件人（报名的人 + 创建人） */
    protected static function text(int $tenantId, string $kind, array $r, int $lead): array
    {
        $left = '还有约 ' . $lead . ' 分钟';
        if ($kind === 'training') {
            $title = '【赛前提醒】训练赛 ' . self::t((string)$r['title']) . ' ' . $r['match_time'];
            $body  = self::timeLine($r['match_time']) . $left . "。\n\n"
                . '模式：' . self::mode((string)$r['mode']) . '　赛制：' . strtoupper((string)$r['series_format']) . "\n"
                . ((string)$r['signup_deadline'] !== '' && $r['signup_deadline'] !== null
                    ? '报名截止：' . $r['signup_deadline'] . "\n" : '')
                . '要参加的请在站点「训练赛」里报名，没时间就早点说一声。';
            return [$title, $body, self::signups('training_signups', (int)$r['id'], (int)($r['created_by'] ?? 0))];
        }
        if ($kind === 'schedule') {
            $dur = (int)($r['duration_min'] ?? 0);
            $title = '【日程提醒】' . self::t((string)$r['title']) . ' ' . $r['start_time'];
            $body  = self::timeLine($r['start_time']) . $left
                . ($dur > 0 ? '，预计 ' . $dur . ' 分钟' : '') . "。\n"
                . ((string)$r['note'] !== '' ? "\n备注：" . $r['note'] . "\n" : '');
            return [$title, $body, []];   // 日程没有报名，只出公告
        }
        $opp  = self::opponent($r, $tenantId);
        $room = trim((string)($r['room_info'] ?? ''));
        $title = '【赛前提醒】约战 ' . self::t((string)$r['title']) . ' vs ' . $opp . '　' . $r['battle_time'];
        $body  = '对阵 ' . $opp . ' 的约战' . self::timeLine($r['battle_time']) . $left . "。\n\n"
            . '赛制：' . strtoupper((string)$r['series_format']) . "\n"
            . ($room !== '' ? '房间信息：' . $room . "\n" : '')
            . ((string)$r['remark'] !== '' ? '备注：' . mb_substr((string)$r['remark'], 0, 200) . "\n" : '');
        return [$title, $body, self::battleRoster((int)$r['id'], $tenantId)];
    }

    /** 「今晚 20:00」「明天 15:30」这种说法，比 ISO 时间好读 */
    protected static function timeLine(string $at): string
    {
        $t = strtotime($at);
        if (!$t) {
            return '';
        }
        $day = date('Y-m-d', $t);
        $pre = $day === date('Y-m-d') ? '今天 ' : ($day === date('Y-m-d', strtotime('+1 day')) ? '明天 ' : $day . ' ');
        return $pre . date('H:i', $t) . ' 开赛，';
    }

    protected static function t(string $s): string
    {
        $s = trim($s);
        return $s === '' ? '（未命名）' : mb_substr($s, 0, 40);
    }

    protected static function mode(string $m): string
    {
        return ['squad' => '四人小队', 'trio' => '三人组', 'duo' => '双人', 'solo' => '单人'][$m] ?? $m;
    }

    /** 本队在这条约战里是哪一方，对手就是另一方 */
    protected static function opponent(array $r, int $tenantId): string
    {
        $cType = (string)$r['challenger_type'];
        $cId   = (int)$r['challenger_id'];
        $dType = (string)$r['defender_type'];
        $dId   = (int)$r['defender_id'];
        if ($cType === 'tenant' && $cId === $tenantId) {
            return BattleService::partyName($dType, $dId);
        }
        return BattleService::partyName($cType, $cId);
    }

    /** 报了名的人（+ 创建人），去重去零 */
    protected static function signups(string $table, int $refId, int $extra = 0): array
    {
        try {
            $ids = array_map('intval', Db::name($table)->where('match_id', $refId)->column('user_id'));
        } catch (\Throwable $e) {
            $ids = [];
        }
        if ($extra > 0) {
            $ids[] = $extra;
        }
        return array_values(array_unique(array_filter($ids)));
    }

    /** 约战本队名单（外部战队的行没有本站 user_id，自然过滤掉） */
    protected static function battleRoster(int $battleId, int $tenantId): array
    {
        try {
            $ids = Db::name('battle_signups')->where('battle_id', $battleId)
                ->where('signup_type', 'user')->where('tenant_id', $tenantId)
                ->whereNotNull('user_id')->column('user_id');
        } catch (\Throwable $e) {
            return [];
        }
        return array_values(array_unique(array_filter(array_map('intval', $ids))));
    }

    /** QQ 群那一路：本队有绑群、机器人启用才发；返回到底送没送到 */
    protected static function sendGroup(int $tenantId, string $text): bool
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

    /** 谁被推过（管理端那一格用来解释「这条为什么没再推第二遍」） */
    public static function recent(int $tenantId, int $limit = 20): array
    {
        $limit = max(1, min(50, $limit));
        return Db::name('event_reminders')->where('tenant_id', $tenantId)
            ->order('id', 'desc')->limit($limit)->select()->toArray();
    }
}
