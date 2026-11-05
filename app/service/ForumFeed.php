<?php
declare (strict_types = 1);

namespace app\service;

use think\facade\Db;

/**
 * 社区论坛对接（v5.35.0）：给队长的另一个项目 —— 那个社区论坛 —— 只读拉数
 *
 * 四条口径是他拍的：
 * ① 给什么：成员名单 + 公开战绩、招募文案与队内公告、活动与签到（没勾「全量成员」，
 *    所以本人关了「对外展示」的人不在这条路上出现）；
 * ② 靠什么认人：打码 QQ（前 3 后 2）。但打码串同队会撞，所以每行再带一个本站内部号 uid，
 *    论坛拿它绑账号 —— 这条在升级说明里写明白，他要摘就摘；
 * ③ 谁能开：队长（注册的战队队长）自己在管理端开，平台随时能关（总闸 + 按站点停用）；
 * ④ 方向：只读往外给，本站不接论坛写回来的任何东西，也不做免登录跳转。
 *
 * 鉴权不新造一套：走站内 v5.4.0 起就在用的私有接口通道（X-Api-Key、库里只存 sha256、
 * 每 Key 与每 IP 限频、认证失败连击锁定、api_logs 全量留痕）。代价说清楚：
 * scope=tenant 的那把 Key 本来也能读约战接口，同一支队、都是只读、都是他自己的系统，
 * 所以这一版不为此加列；把 Key 交给论坛之外的第三方就是另一回事了。
 *
 * 这一版不动库 —— 三个状态都落在 settings（战队开关 / 平台总闸 / 平台停用名单），
 * 老站点零迁移；读不到键就走内置默认值（默认关，不因为升级就往外送数据）。
 */
class ForumFeed
{
    /** 战队自己那一格开关（默认关：装完不传数据，队长点了才给） */
    public const KEY_TENANT = 'forum_api_on';
    /** 平台总闸（默认开：正常情况不挡人，出事时一处掐全站） */
    public const KEY_PLATFORM = 'forum_api_platform_on';
    /** 平台按站点停用名单：逗号分隔的租户号 */
    public const KEY_FORCE_OFF = 'forum_api_force_off';

    public const CAP_MEMBERS = 500;
    public const CAP_EVENTS  = 40;
    public const WINDOW_DAYS = 30;

    // ---------------- 三道闸 ----------------

    /**
     * 现在这一队能不能给论坛读数：三个条件都得过。
     * 返回 reason 是给管理端那一页照抄的人话，不是给错误码用的。
     */
    public static function gate(int $tenantId): array
    {
        if (!self::platformOn()) {
            return ['on' => false, 'reason' => '平台已停用论坛对接，要恢复得让平台后台把总闸打开'];
        }
        if (in_array($tenantId, self::forceOffList(), true)) {
            return ['on' => false, 'reason' => '平台已单独停用本队的论坛对接，请联系平台超管'];
        }
        if (strtolower(trim(self::tenantRaw($tenantId, self::KEY_TENANT, '0'))) !== '1') {
            return ['on' => false, 'reason' => '本队还没打开这个开关'];
        }
        return ['on' => true, 'reason' => ''];
    }

    public static function tenantOn(int $tenantId): bool
    {
        return strtolower(trim(self::tenantRaw($tenantId, self::KEY_TENANT, '0'))) === '1';
    }

    public static function setTenantOn(int $tenantId, bool $on): void
    {
        self::writeSetting($tenantId, self::KEY_TENANT, $on ? '1' : '0');
    }

    public static function platformOn(): bool
    {
        $v = Setting::getPlatform(self::KEY_PLATFORM, '1');
        // 读不到键就走「开」：这一版不给 settings 插默认行，老站点不该因为升级被挡
        return trim($v) === '' || strtolower(trim($v)) === '1';
    }

    public static function setPlatformOn(bool $on): void
    {
        Setting::setPlatform(self::KEY_PLATFORM, $on ? '1' : '0');
    }

    /** 平台按站点停用的名单（脏值丢掉，非数字的一段不进列表） */
    public static function forceOffList(): array
    {
        $v = Setting::getPlatform(self::KEY_FORCE_OFF, '');
        $out = [];
        foreach (explode(',', $v) as $one) {
            $one = trim($one);
            if ($one !== '' && ctype_digit($one) && (int)$one > 0) {
                $out[] = (int)$one;
            }
        }
        return array_values(array_unique($out));
    }

    public static function setForceOff(int $tenantId, bool $off): void
    {
        $list = self::forceOffList();
        $list = array_values(array_filter($list, static fn($x) => $x !== $tenantId));
        if ($off) {
            $list[] = $tenantId;
        }
        sort($list);
        Setting::setPlatform(self::KEY_FORCE_OFF, implode(',', $list));
    }

    // ---------------- 四组数据 ----------------

    /** 战队名片：论坛的球队页照这一份排，联系方式给的是队长在对外展示页里填的那串（本来就在公开页上） */
    public static function team(int $tenantId, string $siteBase = ''): array
    {
        $cfg = Showcase::config($tenantId);
        $t   = Db::name('tenants')->where('id', $tenantId)
            ->field('id, slug, name, logo, description')->find() ?: [];
        $slug = (string)($t['slug'] ?? '');
        $public = Db::name('users')->where('tenant_id', $tenantId)->where('status', 'approved')
            ->where('show_public', 1)->count();
        $done = 0;
        foreach (self::finishedCount($tenantId) as $n) {
            $done += $n;
        }
        return [
            'slug'        => $slug,
            'name'        => (string)($t['name'] ?? ''),
            'logo'        => (string)($cfg['logo'] !== '' ? $cfg['logo'] : (string)($t['logo'] ?? '')),
            'intro'       => (string)($t['description'] ?? ''),
            'slogan'      => (string)$cfg['slogan'],
            'contact'     => (string)$cfg['contact'],
            'recruiting'  => (int)$cfg['recruiting'],
            'positions'   => $cfg['positions'],
            'site_url'    => $siteBase . '/' . $slug . '/',
            'stats'       => [
                'members_public' => (int)$public,
                'events_done'    => $done,
            ],
            'showcase_on' => (int)$cfg['enabled'],
            'as_of'       => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * 成员名单 + 公开战绩。
     * 两道本人开关各管一半：show_public 决定这个人出不出现，stats_public 决定他这行的战绩给不给
     * （与站内那两档同一个口径，不在这里另立一套视野）。
     */
    public static function members(int $tenantId): array
    {
        $defs = player_stat_defs();
        $keys = array_keys($defs);
        $rows = Db::name('users')->alias('u')
            ->leftJoin('player_stats p', 'p.user_id = u.id AND p.tenant_id = u.tenant_id')
            ->leftJoin('teams t', 't.id = u.team_id AND t.tenant_id = u.tenant_id')
            ->where('u.tenant_id', $tenantId)
            ->where('u.status', 'approved')
            ->where('u.show_public', 1)
            ->field('u.id, u.nickname, u.username, u.role_tags, u.stats_public, u.team_id, u.last_active_at,
                    t.name AS team_name, '
                . implode(', ', array_map(static fn($k) => 'p.' . $k, $keys)))
            ->order('u.id', 'asc')
            ->limit(self::CAP_MEMBERS)
            ->select()->toArray();

        $tags = Db::name('role_tags')->where('tenant_id', $tenantId)->column('name', 'id');
        $list = [];
        foreach ($rows as $r) {
            $ids   = json_decode((string)($r['role_tags'] ?? ''), true);
            $first = (is_array($ids) && $ids !== []) ? (int)reset($ids) : 0;
            $one   = [
                'uid'      => (int)$r['id'],
                'nickname' => trim((string)$r['nickname']),
                'qq_masked' => mask_qq((string)$r['username']),
                'team'     => (string)($r['team_name'] ?? ''),
                'tag'      => (string)($tags[$first] ?? ''),
                'active_at' => (string)($r['last_active_at'] ?? ''),
            ];
            $stats = [];
            if ((int)$r['stats_public'] === 1) {
                foreach ($keys as $k) {
                    $v = $r[$k] ?? null;
                    $stats[$k] = ($v === null || $v === '') ? null : 0 + $v;
                }
            }
            // 不公开的那档给一个空对象而不是空数组：论坛那边不管是 Python 还是 JS，
            // stats 的类型都得是稳定的一个「对象」，不能这一行是 [] 下一行是 {}
            $one['stats']        = $stats === [] ? new \stdClass() : $stats;
            $one['stats_public'] = (int)$r['stats_public'] === 1 ? 1 : 0;
            $list[] = $one;
        }
        $labels = [];
        foreach ($defs as $k => $d) {
            $labels[$k] = (string)$d['label'];
        }
        return [
            'list'  => $list,
            'total' => count($list),
            'stat_labels' => $labels,
            'note'  => '只包含本人同意对外展示的成员；stats_public=0 的那些人给的是一个空的 stats（不给就是不给，别当 0 读）。'
                . '认人请用 uid（本站内部号，稳定不变）；打码 QQ 只是给人看一眼的，同队会撞号，别拿它当主键。',
        ];
    }

    /**
     * 活动与签到：论坛要排「这队多活跃」就用这一份。
     * 时间窗：过去 30 天办过的 + 未来 30 天要办的；签到汇总只统计本人同意对外展示的那些人。
     */
    public static function activity(int $tenantId): array
    {
        $from = date('Y-m-d H:i:s', time() - self::WINDOW_DAYS * 86400);
        $to   = date('Y-m-d H:i:s', time() + self::WINDOW_DAYS * 86400);
        $events = [];

        $tq = Db::name('training_matches')->where('tenant_id', $tenantId)
            ->where('match_time', '>=', $from)->where('match_time', '<=', $to);
        // v5.36.0：外部论坛没有端别，分端的训练赛不外发
        if (GameSide::moduleOnFor($tenantId, 'training')) {
            $tq->where('game_side', '');
        }
        $tr = $tq->field('id, title, match_time, status')->order('match_time', 'asc')
            ->limit(self::CAP_EVENTS)->select()->toArray();
        $ids  = array_column($tr, 'id');
        $cnt  = $ids === [] ? [] : Db::name('training_signups')->where('tenant_id', $tenantId)
            ->whereIn('match_id', $ids)->group('match_id')
            ->field('match_id, COUNT(*) AS c')->select()->toArray();
        $cmap = [];
        foreach ($cnt as $c) {
            $cmap[(int)$c['match_id']] = (int)$c['c'];
        }
        foreach ($tr as $e) {
            $events[] = [
                'kind'    => '训练赛',
                'title'   => (string)$e['title'],
                'at'      => (string)$e['match_time'],
                'status'  => (string)$e['status'],
                'signups' => $cmap[(int)$e['id']] ?? 0,
            ];
        }

        $ce = Db::name('custom_events')->where('tenant_id', $tenantId)
            ->where('start_time', '>=', $from)->where('start_time', '<=', $to)
            ->field('title, start_time, note')->order('start_time', 'asc')
            ->limit(self::CAP_EVENTS)->select()->toArray();
        foreach ($ce as $e) {
            $events[] = [
                'kind'   => '队内日程',
                'title'  => (string)$e['title'],
                'at'     => (string)$e['start_time'],
                'status' => '',
                'note'   => mb_substr((string)$e['note'], 0, 200),
            ];
        }

        $bt = self::battlesOf($tenantId, $from, $to);
        foreach ($bt['rows'] as $b) {
            $events[] = [
                'kind'      => '跨队约战',
                'title'     => (string)$b['title'],
                'at'        => (string)($b['battle_time'] ?? ''),
                'status'    => (string)($b['status'] ?? ''),
                'opponent'  => $bt['names'][(int)$b['id']] ?? '',
                'our_side'  => (string)($b['our_side'] ?? ''),
                'signups'   => (int)($b['our_signups'] ?? 0),
            ];
        }

        usort($events, static fn($a, $c) => strcmp((string)$a['at'], (string)$c['at']));

        return [
            'window'   => ['from' => $from, 'to' => $to],
            'events'   => $events,
            'checkins' => self::checkinBoard($tenantId),
            'note'     => '签到只统计本人同意对外展示的成员；断一天连签就清零，所以 streak 是当前值不是历史最高。',
        ];
    }

    /**
     * 队内公告：给出去的就是队长在「对外展示页」里勾选的那几条（战队动态那一块）。
     * 没勾的队内公告不在这条路上 —— 勾一遍是队长的动作，接口不该替他决定哪条能出网站。
     */
    public static function notices(int $tenantId): array
    {
        $rows = Showcase::notices($tenantId);
        $out  = [];
        foreach ($rows as $r) {
            $out[] = [
                'title'      => (string)$r['title'],
                'content'    => (string)$r['content'],
                'created_at' => (string)$r['created_at'],
            ];
        }
        return ['list' => $out, 'total' => count($out),
            'note' => '这里只有队长在对外展示页勾选过的公告；想多给几条去那一页勾。'];
    }

    // ---------------- 内部 ----------------

    /** 签到榜（按人汇总），口径与站内导出那张一致，但只统计 show_public=1 的人 */
    protected static function checkinBoard(int $tenantId): array
    {
        $agg = Db::name('checkins')->where('tenant_id', $tenantId)
            ->field('user_id, COUNT(*) AS days, MAX(checkin_date) AS last_date, MAX(streak) AS best')
            ->group('user_id')->select()->toArray();
        if ($agg === []) {
            return [];
        }
        $ids  = array_map(static fn($r) => (int)$r['user_id'], $agg);
        $live = Db::name('users')->where('tenant_id', $tenantId)->whereIn('id', $ids)
            ->where('show_public', 1)->where('status', 'approved')
            ->column('nickname', 'id');
        $out  = [];
        foreach ($agg as $r) {
            $uid = (int)$r['user_id'];
            if (!isset($live[$uid])) {
                continue;   // 关了对外展示的、或已封禁注销的人不出这条榜
            }
            $nick = trim((string)$live[$uid]);
            $out[] = [
                'uid'      => $uid,
                'nickname' => $nick,
                'days'     => (int)$r['days'],
                'streak'   => (int)$r['best'],
                'last'     => (string)$r['last_date'],
            ];
        }
        usort($out, static fn($a, $b) => $b['days'] <=> $a['days']);
        return array_slice($out, 0, self::CAP_MEMBERS);
    }

    /** 本队参与的约战（含对手队名与本队报名人数）；对方那一侧的名单一律不读 */
    protected static function battlesOf(int $tenantId, string $from, string $to): array
    {
        $rows = Db::name('battles')
            ->where(function ($q) use ($tenantId) {
                $q->where('challenger_type', 'tenant')->where('challenger_id', $tenantId);
            })
            ->whereOr(function ($q) use ($tenantId) {
                $q->where('defender_type', 'tenant')->where('defender_id', $tenantId);
            })
            ->where('battle_time', '>=', $from)->where('battle_time', '<=', $to)
            ->field('id, title, battle_time, status, challenger_type, challenger_id, defender_type, defender_id')
            ->order('battle_time', 'asc')->limit(self::CAP_EVENTS)->select()->toArray();

        $names = [];
        $tids  = [];
        $exIds = [];
        foreach ($rows as $b) {
            // 先定「我们是哪一方」，对手就是那一方 —— 反过来用条件判断类型会把「我们是应战方」那种算错，
            // 症状是应战的时候对手队名显示成自己
            $our = ((string)$b['challenger_type'] === 'tenant' && (int)$b['challenger_id'] === $tenantId) ? 'a' : 'b';
            $theirType = $our === 'a' ? (string)$b['defender_type'] : (string)$b['challenger_type'];
            $theirId   = $our === 'a' ? (int)$b['defender_id'] : (int)$b['challenger_id'];
            if ($theirType === 'tenant') {
                $tids[] = $theirId;
                $names[(int)$b['id']] = ['tenant', $theirId];
            } else {
                $exIds[] = $theirId;
                $names[(int)$b['id']] = ['external', $theirId];
            }
        }
        $tNames = $tids === [] ? [] : Db::name('tenants')->whereIn('id', array_unique($tids))
            ->column('name', 'id');
        $eNames = $exIds === [] ? [] : Db::name('external_teams')->whereIn('id', array_unique($exIds))
            ->column('name', 'id');

        $bids = array_column($rows, 'id');
        $signs = [];
        if ($bids !== []) {
            $q = Db::name('battle_signups')->whereIn('battle_id', $bids)
                ->group('battle_id, side')->field('battle_id, side, COUNT(*) AS c')->select()->toArray();
            foreach ($q as $s) {
                $signs[(int)$s['battle_id'] . ':' . (string)$s['side']] = (int)$s['c'];
            }
        }

        $out = [];
        foreach ($rows as $b) {
            $id  = (int)$b['id'];
            $our = ((string)$b['challenger_type'] === 'tenant' && (int)$b['challenger_id'] === $tenantId) ? 'a' : 'b';
            $pair = $names[$id] ?? null;
            $out[] = $b + [
                'our_side'    => $our,
                'our_signups' => $signs[$id . ':' . $our] ?? 0,
            ];
        }
        $nameOut = [];
        foreach ($out as $b) {
            $id = (int)$b['id'];
            $pair = $names[$id] ?? null;
            $nameOut[$id] = $pair === null ? '' : (string)(($pair[0] === 'tenant' ? ($tNames[$pair[1]] ?? '')
                : ($eNames[$pair[1]] ?? '')));
        }
        return ['rows' => $out, 'names' => $nameOut];
    }

    /** 办过的活动数（论坛球队页那句「一起打过 N 场」），只数已结束/已完赛的 */
    protected static function finishedCount(int $tenantId): array
    {
        $tr = (int)Db::name('training_matches')->where('tenant_id', $tenantId)
            ->where('status', 'finished')->count();
        $bt = 0;
        foreach (['a' => 'challenger', 'b' => 'defender'] as $side) {
            $bt += (int)Db::name('battles')->where($side . '_type', 'tenant')
                ->where($side . '_id', $tenantId)->where('status', 'finished')->count();
        }
        return ['training' => $tr, 'battles' => $bt];
    }

    /**
     * 读这一队那一格：一律直查库，不走 Setting 的进程内缓存。
     * 原因很实在 —— 开放接口这条路上 `TenantContext` 是中间件（ApiKeyAuth）半路切过来的，
     * 而 `Setting::$cache` 是按第一次读取时的租户装的、切上下文不重刷。
     * 走缓存就有两种错：要么读到平台那一份（永远关着），要么读到上一支队那一份（把别人的开关当成自己的）。
     * 一次带主键的等值查询换这个确定性，值。
     */
    protected static function tenantRaw(int $tenantId, string $key, string $default): string
    {
        $v = Db::name('settings')->where('tenant_id', $tenantId)->where('key', $key)->value('value');
        return $v === null ? $default : (string)$v;
    }

    /**
     * 写这一队的开关。当前租户走 Setting（顺带刷进程内缓存），
     * 平台侧改别队那一格时才直写库 —— settings 表只有 tenant_id/key/value 三列，别多塞。
     */
    protected static function writeSetting(int $tenantId, string $key, string $value): void
    {
        if ($tenantId === TenantContext::id()) {
            Setting::set($key, $value);
            return;
        }
        if (Db::name('settings')->where('tenant_id', $tenantId)->where('key', $key)->count() > 0) {
            Db::name('settings')->where('tenant_id', $tenantId)->where('key', $key)
                ->update(['value' => $value]);
            return;
        }
        Db::name('settings')->insert([
            'tenant_id' => $tenantId, 'key' => $key, 'value' => $value,
        ]);
    }
}
