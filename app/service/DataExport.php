<?php
declare (strict_types = 1);

namespace app\service;

use think\facade\Db;

/**
 * 一键导出表格的取数（v5.34.0）
 *
 * 四张表：成员名单 / 成员战绩 / 签到记录 / 活动报名。
 *
 * 三条他定过的口径，写在这儿防后来人改错：
 * ① QQ 号永远打码 —— 哪怕导出的人自己持有 qq_full 权限也不给完整号。
 *    理由是文件要发出去、要转手，站内那条「谁能看全号」的视野管不到下载之后的事。
 * ② 本人的「对外展示 / 战绩公开」两个开关不影响内部导出 —— 那两个开关管的是访客页与队内视野，
 *    队长导自己队的花名册是内部管理动作，内部照导。
 * ③ 权限按现有分档，不另立一套 —— 名单这一路直接沿用成员名单接口那份权限键清单，
 *    两处各写一遍迟早漂（漂了就是「界面上看不到的人能从表格裡导出」）。
 */
class DataExport
{
    /** kind => 表名（也是文件名与工作表名） */
    public const KINDS = [
        'members'  => '成员名单',
        'stats'    => '成员战绩',
        'checkins' => '签到记录',
        'signups'  => '活动报名',
    ];

    /** 一次最多导这么多行：再多就是导错了东西，宁可让他加筛选条件分次导 */
    public const ROW_CAP = 5000;

    /** 名单类接口那份权限清单（app/controller/Admin.php 的 users() 同一份） */
    private const MEMBER_PERMS = ['member_view', 'approve', 'ban', 'role_manage', 'rename', 'mute',
        'reset_pwd', 'delete_user', 'multi', 'team', 'lottery', 'duty', 'role_tag'];

    /** 这一档现在这个人能不能导 */
    public static function can(string $kind): bool
    {
        if (!isset(self::KINDS[$kind])) {
            return false;
        }
        if ($kind === 'stats') {
            return admin_can('stats');
        }
        if ($kind === 'signups') {
            return admin_can_any(['training', 'battle', 'schedule']);
        }
        return admin_can_any(self::MEMBER_PERMS);
    }

    /** 表头 */
    public static function cols(string $kind): array
    {
        switch ($kind) {
            case 'stats':
                $cols = [
                    ['label' => '昵称', 'width' => 16],
                    ['label' => 'QQ(打码)', 'width' => 14],
                ];
                foreach (player_stat_defs() as $def) {
                    $cols[] = ['label' => (string)$def['label'], 'width' => 12, 'num' => true];
                }
                $cols[] = ['label' => '最近上传', 'width' => 20];
                return $cols;

            case 'checkins':
                return [
                    ['label' => '昵称', 'width' => 16],
                    ['label' => 'QQ(打码)', 'width' => 14],
                    ['label' => '签到总天数', 'width' => 12, 'num' => true],
                    ['label' => '本月签到', 'width' => 10, 'num' => true],
                    ['label' => '最长连签', 'width' => 10, 'num' => true],
                    ['label' => '当前连签', 'width' => 10, 'num' => true],
                    ['label' => '最近一次签到', 'width' => 16],
                ];

            case 'signups':
                return [
                    ['label' => '活动', 'width' => 26],
                    ['label' => '类别', 'width' => 12],
                    ['label' => '时间', 'width' => 20, 'num' => false],
                    ['label' => '活动状态', 'width' => 12],
                    ['label' => '昵称', 'width' => 16],
                    ['label' => 'QQ(打码)', 'width' => 14],
                    ['label' => '分组', 'width' => 10],
                    ['label' => '报名时间', 'width' => 20],
                ];

            default:
                return [
                    ['label' => '昵称', 'width' => 16],
                    ['label' => 'QQ(打码)', 'width' => 14],
                    ['label' => '小队', 'width' => 14],
                    ['label' => '身份', 'width' => 10],
                    ['label' => '状态', 'width' => 10],
                    ['label' => '职责标签', 'width' => 18],
                    ['label' => '注册时间', 'width' => 20],
                    ['label' => '最后活跃', 'width' => 20],
                ];
        }
    }

    /**
     * 取一份表：['label' => 表名, 'cols' => 表头, 'rows' => 数据, 'total' => 落进文件的行数, 'capped' => 库里不止这些]
     *
     * $tenantId 由调用方显式传进来（不从上下文猜），这样跨队取数在测试里能单独断言。
     */
    public static function build(string $kind, int $tenantId): array
    {
        $all    = self::rows($kind, $tenantId);
        $capped = count($all) > self::ROW_CAP;
        $rows   = $capped ? array_slice($all, 0, self::ROW_CAP) : $all;
        return ['label' => self::KINDS[$kind], 'cols' => self::cols($kind), 'rows' => $rows,
            'total' => count($rows), 'capped' => $capped];
    }

    /** 一张表的行（未截断；取数里每人只占一行的那三张按人数封顶，报名那张按记录数） */
    public static function rows(string $kind, int $tenantId): array
    {
        return match ($kind) {
            'stats'    => self::statsRows($tenantId),
            'checkins' => self::checkinRows($tenantId),
            'signups'  => self::signupRows($tenantId),
            default    => self::memberRows($tenantId),
        };
    }

    /**
     * 页面上那四行「共 N 条」——直接用取数本身来数，不另写一条 COUNT。
     * 两处各算一遍迟早漂，漂了就是「界面写着 30 条、打开文件只有 28 行」。
     * 超过上限时界面写上限那个数并标 capped，让他知道后面还有，而不是报一个假精确的行数。
     */
    public static function summary(int $tenantId): array
    {
        $out = [];
        foreach (self::KINDS as $kind => $label) {
            $n     = count(self::rows($kind, $tenantId));
            $cap   = $n > self::ROW_CAP;
            $out[] = ['kind' => $kind, 'label' => $label, 'rows' => $cap ? self::ROW_CAP : $n,
                'capped' => $cap, 'can' => self::can($kind)];
        }
        return $out;
    }

    // ---------------- 四类取数 ----------------

    private static function memberRows(int $tenantId): array
    {
        $owner  = (string)Db::name('tenants')->where('id', $tenantId)->value('admin_username');
        $list   = Db::name('users')->alias('u')
            ->leftJoin('teams t', 't.id = u.team_id AND t.tenant_id = u.tenant_id')
            ->where('u.tenant_id', $tenantId)
            ->field('u.id, u.username, u.nickname, u.role, u.status, u.role_tags, u.created_at,
                     u.last_active_at, t.name AS team_name')
            ->order('u.id', 'asc')
            ->limit(self::ROW_CAP + 1)
            ->select()->toArray();

        $tagIds = [];
        foreach ($list as $r) {
            foreach (self::jsonList((string)($r['role_tags'] ?? '')) as $id) {
                $tagIds[] = $id;
            }
        }
        $tags = $tagIds === [] ? [] : Db::name('role_tags')->where('tenant_id', $tenantId)
            ->whereIn('id', array_unique($tagIds))->column('name', 'id');

        $rows = [];
        foreach ($list as $r) {
            $names = [];
            foreach (self::jsonList((string)($r['role_tags'] ?? '')) as $id) {
                if (isset($tags[$id])) {
                    $names[] = (string)$tags[$id];
                }
            }
            $rows[] = [
                user_display_name($r),
                mask_qq((string)$r['username']),
                (string)($r['team_name'] ?? '') !== '' ? (string)$r['team_name'] : '未分组',
                self::duty($r, $owner),
                self::status((string)$r['status']),
                implode('、', $names),
                (string)($r['created_at'] ?? ''),
                (string)($r['last_active_at'] ?? ''),
            ];
        }
        return $rows;
    }

    /**
     * 战绩那张以「人」为主行：与站内战绩页同一口径（已通过审核的成员，一人一行），
     * 没上传的人照样占一行、二十项留空 —— 队长要的就是「谁还没传」这张底。
     */
    private static function statsRows(int $tenantId): array
    {
        $defs = player_stat_defs();
        $keys = array_keys($defs);
        $list = Db::name('users')->alias('u')
            ->leftJoin('player_stats p', 'p.user_id = u.id AND p.tenant_id = u.tenant_id')
            ->where('u.tenant_id', $tenantId)
            ->where('u.status', 'approved')
            ->field('u.id, u.username, u.nickname, p.updated_at, '
                . implode(', ', array_map(static fn($k) => 'p.' . $k, $keys)))
            ->order('u.id', 'asc')
            ->limit(self::ROW_CAP + 1)
            ->select()->toArray();

        $rows = [];
        foreach ($list as $r) {
            $line = [user_display_name($r), mask_qq((string)($r['username'] ?? ''))];
            foreach ($keys as $k) {
                $v = $r[$k] ?? null;
                // 没上传过的项留空而不是补 0：补 0 会被当成「这个人这局真的 0 击杀」
                $line[] = ($v === null || $v === '') ? '' : 0 + $v;
            }
            $rows[] = array_merge($line, [(string)($r['updated_at'] ?? '')]);
        }
        return $rows;
    }

    private static function checkinRows(int $tenantId): array
    {
        // 全部走查询构造器而不是裸 SQL：DB_PREFIX 一旦设了，裸 SQL 里的表名会找不到表
        $agg = Db::name('checkins')->where('tenant_id', $tenantId)
            ->field('user_id, COUNT(*) AS days, MAX(checkin_date) AS last_date, MAX(streak) AS best')
            ->group('user_id')->select()->toArray();
        if ($agg === []) {
            return [];
        }
        // streak 只在连着的日子里往上加，断一天就回到 1，所以每行取自己那条最新的号即是当前连签
        $latest = Db::name('checkins')->where('tenant_id', $tenantId)
            ->field('user_id, MAX(id) AS mid')->group('user_id')->select()->toArray();
        $byMid  = [];
        foreach ($latest as $r) {
            $byMid[(int)$r['mid']] = (int)$r['user_id'];
        }
        $streak = [];
        if ($byMid !== []) {
            foreach (Db::name('checkins')->where('tenant_id', $tenantId)->whereIn('id', array_keys($byMid))
                ->field('id, streak')->select()->toArray() as $r) {
                $streak[$byMid[(int)$r['id']]] = (int)$r['streak'];
            }
        }
        $monthN = [];
        foreach (Db::name('checkins')->where('tenant_id', $tenantId)
            ->where('checkin_date', '>=', date('Y-m-01'))
            ->field('user_id, COUNT(*) AS c')->group('user_id')->select()->toArray() as $r) {
            $monthN[(int)$r['user_id']] = (int)$r['c'];
        }

        $ids  = array_map(static fn($r) => (int)$r['user_id'], $agg);
        $user = self::usersOf($tenantId, $ids);
        $rows = [];
        foreach ($agg as $r) {
            $uid    = (int)$r['user_id'];
            $u      = $user[$uid] ?? null;
            $rows[] = [
                $u === null ? '(已注销账号)' : user_display_name($u),
                $u === null ? '' : mask_qq((string)$u['username']),
                (int)$r['days'],
                $monthN[$uid] ?? 0,
                (int)$r['best'],
                $streak[$uid] ?? 0,
                (string)$r['last_date'],
            ];
        }
        usort($rows, static fn($a, $b) => $b[2] <=> $a[2]);
        return $rows;
    }

    private static function signupRows(int $tenantId): array
    {
        $rows  = [];

        $matches = Db::name('training_matches')->where('tenant_id', $tenantId)
            ->field('id, title, match_time, status')
            ->order('match_time', 'desc')->limit(200)->select()->toArray();
        $tMap    = [];
        foreach ($matches as $m) {
            $tMap[(int)$m['id']] = $m;
        }
        if ($tMap !== []) {
            $signups = Db::name('training_signups')->where('tenant_id', $tenantId)
                ->whereIn('match_id', array_keys($tMap))
                ->field('match_id, user_id, side, created_at')
                ->order('id', 'asc')->limit(self::ROW_CAP + 1)->select()->toArray();
            $user    = self::usersOf($tenantId, array_column($signups, 'user_id'));
            foreach ($signups as $s) {
                $m     = $tMap[(int)$s['match_id']] ?? null;
                $u     = $user[(int)$s['user_id']] ?? null;
                $rows[] = [
                    (string)($m['title'] ?? ''),
                    '训练赛',
                    (string)($m['match_time'] ?? ''),
                    self::trainingStatus((string)($m['status'] ?? '')),
                    $u === null ? '(已注销账号)' : user_display_name($u),
                    $u === null ? '' : mask_qq((string)$u['username']),
                    $s['side'] === 'a' ? 'A 队' : ($s['side'] === 'b' ? 'B 队' : '未分组'),
                    (string)($s['created_at'] ?? ''),
                ];
            }
        }

        $battles = Db::name('battles')
            ->where(function ($q) use ($tenantId) {
                $q->where('challenger_type', 'tenant')->where('challenger_id', $tenantId);
            })
            ->whereOr(function ($q) use ($tenantId) {
                $q->where('defender_type', 'tenant')->where('defender_id', $tenantId);
            })
            ->field('id, title, battle_time, status, challenger_type, challenger_id, defender_type, defender_id')
            ->order('battle_time', 'desc')->limit(200)->select()->toArray();
        $mine = [];
        foreach ($battles as $b) {
            $side = null;
            if ($b['challenger_type'] === 'tenant' && (int)$b['challenger_id'] === $tenantId) {
                $side = 'a';
            } elseif ($b['defender_type'] === 'tenant' && (int)$b['defender_id'] === $tenantId) {
                $side = 'b';
            }
            if ($side !== null) {
                $mine[(int)$b['id']] = $b + ['own_side' => $side];
            }
        }
        if ($mine !== []) {
            $signs = Db::name('battle_signups')->whereIn('battle_id', array_keys($mine))
                ->field('battle_id, side, signup_type, user_id, display_name, manual, created_at')
                ->order('id', 'asc')->limit(self::ROW_CAP + 1)->select()->toArray();
            $user  = self::usersOf($tenantId, array_column($signs, 'user_id'));
            foreach ($signs as $s) {
                $b = $mine[(int)$s['battle_id']] ?? null;
                if (!$b || (string)$s['side'] !== (string)$b['own_side']) {
                    continue;   // 对方那一串报名名单不是本队的东西，不进本队的表
                }
                $u     = $s['user_id'] === null ? null : ($user[(int)$s['user_id']] ?? null);
                $who   = $u === null
                    ? trim((string)$s['display_name'])
                    : user_display_name($u);
                $rows[] = [
                    (string)$b['title'],
                    '跨队约战',
                    (string)($b['battle_time'] ?? ''),
                    self::battleStatus((string)$b['status']),
                    $who === '' ? '(没填昵称)' : $who,
                    $u === null ? '' : mask_qq((string)$u['username']),
                    (int)$s['manual'] === 1 ? '管理员添加' : '自己报名',
                    (string)($s['created_at'] ?? ''),
                ];
            }
        }

        return $rows;
    }

    // ---------------- 小工具 ----------------

    /** 身份那一列：队长 / 管理员 / 成员（tenants.admin_username 就是队长那个号） */
    private static function duty(array $r, string $owner): string
    {
        if ($owner !== '' && (string)$r['username'] === $owner) {
            return '队长';
        }
        return (string)$r['role'] === 'admin' ? '管理员' : '成员';
    }

    private static function status(string $s): string
    {
        return ['approved' => '已通过', 'pending' => '待审核', 'banned' => '已封禁'][$s] ?? $s;
    }

    private static function trainingStatus(string $s): string
    {
        return ['signup' => '报名中', 'ongoing' => '进行中', 'finished' => '已结束',
            'canceled' => '已取消'][$s] ?? $s;
    }

    private static function battleStatus(string $s): string
    {
        return ['pending' => '待接受', 'accepted' => '报名中', 'finished' => '已完赛',
            'rejected' => '被拒绝', 'canceled' => '已取消', 'expired' => '已过期'][$s] ?? $s;
    }

    /** users.id => 那一行（昵称与号只从这里取，四类表共用一份口径） */
    private static function usersOf(int $tenantId, array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if ($ids === []) {
            return [];
        }
        $list = Db::name('users')->where('tenant_id', $tenantId)->whereIn('id', $ids)
            ->field('id, username, nickname')->select()->toArray();
        $out  = [];
        foreach ($list as $r) {
            $out[(int)$r['id']] = $r;
        }
        return $out;
    }

    private static function jsonList(string $raw): array
    {
        $v = json_decode($raw, true);
        if (!is_array($v)) {
            return [];
        }
        return array_values(array_filter(array_map('intval', $v)));
    }
}
