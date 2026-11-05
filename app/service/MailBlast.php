<?php
declare (strict_types = 1);

namespace app\service;

use think\facade\Cache;
use think\facade\Db;

/**
 * 平台邮件群发（v5.31.0）：收件人解析 + 预览门禁 + 逐封限速的发送队列。
 *
 * 只有平台超管能用这套东西（守卫在 PlatformMail 控制器里，每一条接口都先过 is_platform_super()）。
 * 这里只管「发什么、发给谁、怎么发」，不管 HTTP 也不管界面。
 *
 * 五条口径，都是需求方当场点的，别靠代码推断就改：
 * ① 收件人就两组：owner=各战队的站长与管理员（role=admin 且已上线），member=全部已上线成员。
 *    一个人同时属于两组时只留一行、按 owner 记 —— 站长不该收到两封同样的通知。
 * ② 发信用平台自己那一套（p_mail_*，租户号 0），与各战队的 smtp_* 完全分开：
 *    战队读不到平台的凭据，平台这套也不改变战队自己发信的行为。
 * ③ 正式发送前必须先给自己发一封预览，且预览的就是这一份标题+正文；
 *    改一个字预览就失效，得重新预览。没预览过的正文建不了批次。
 * ④ 每一封同时补一条站内信（forum_notices kind=platform），邮箱填得不对的人照样看得到通知；
 *    哪几封没送出去、服务器回的那句话是什么，明细里如实记着。
 * ⑤ 收件地址就按注册时那个 QQ号@qq.com（这版不给个人中心加「自己填邮箱」），
 *    地址不在接口里整本下发：浏览器只拿到人数 + 脱敏抽样，逐封明细只有平台超管这一页看得到。
 * ⑥ 标题与正文里可以写 {team} {name} {qq} {role} {date} {site} {slug}（v5.36.31），
 *    发的那一刻按收件人自己那一行现拼；库里存的还是运营写的那一份模板，不存个性化正文。
 *    换进去的值先剥掉尖括号与控制字符 —— 昵称和队名是别人自己填的，正文可能是 HTML。
 *
 * 队列为什么是「先认领再发」：pending → sending → sent/failed/skipped。
 * 一次 HTTP 只推进一步（一批 batch 封，每封之间隔 gap 毫秒），页面关了只是暂停；
 * 同一批次同一时刻被两个标签页点也不会重发，因为认领那句 UPDATE 带着 status='pending' 条件。
 * 真被中途打断留下的 sending 死行不自动回收 —— 页面上给一颗明确的按钮，让平台自己决定重排。
 */
class MailBlast
{
    public const SUBJECT_MAX = 120;
    public const BODY_MAX    = 20000;

    /** 收件分组：键存库，值是给人看的那句。被封站点不是一个分组，是建批那一刻的记号，见 BANNED_MARK */
    public const GROUPS = [
        'owner'  => '各战队的站长与管理员',
        'member' => '全部已上线成员',
    ];

    /** 逐封间隔与每批封数的取值范围（防手滑填成 60000 毫秒把队列冻住） */
    public const BATCH_RANGE = [1, 20];
    public const GAP_RANGE  = [0, 5000];
    public const MAX_RANGE  = [1, 5000];

    /**
     * 可以按收件人替换的变量（v5.36.31）：标题与正文里写这一串，发的那一刻换成这个人自己的值。
     * 库里存的还是你写的那一份模板，逐封明细不落个性化正文 —— 事后回看与站内信都按同一份模板现拼。
     */
    public const VARS = [
        '{name}' => '成员昵称（没填昵称就用登录名）',
        '{team}' => '战队名',
        '{qq}'   => '成员 QQ 号（就是登录名）',
        '{role}' => '身份：管理员 / 成员',
        '{date}' => '发出这一封那天的日期',
        '{site}' => '这个战队的站点地址',
        '{slug}' => '站点短码（地址里那截目录名）',
    ];

    /** 变量值里最多留多少字（昵称 32、队名 64，留点余量；超长一律截断，免得正文被撑爆） */
    public const VAR_VALUE_MAX = 64;

    /** 预览确认后多久内必须点正式发送（超时就得重新预览，避免"预览的是上周那份"） */
    public const PREVIEW_TTL = 1800;

    public const STATUS = [
        'queued'  => '待发送',
        'running' => '发送中',
        'done'    => '已发完',
        'stopped' => '已暂停',
    ];

    // ==================== 配置 ====================

    /** 数值型配置：读不到键走内置默认，越界一律夹到范围内（半份配置也不能把队列打崩） */
    public static function num(string $key, int $min, int $max): int
    {
        $v = (int)Setting::getPlatform($key, (string)self::defaultOf($key));
        if ($v < $min) { return $min; }
        if ($v > $max) { return $max; }
        return $v;
    }

    public static function defaultOf(string $key): string
    {
        switch ($key) {
            case 'p_mail_port':    return '465';
            case 'p_mail_batch':   return '8';
            case 'p_mail_gap_ms':  return '300';
            case 'p_mail_max':     return '3000';
            default:               return '';
        }
    }

    /** 平台群发总开关（默认开；关掉后这一页只留历史，不能预览也不能发） */
    public static function enabled(): bool
    {
        return Setting::getPlatform('p_mail_enabled', '1') !== '0';
    }

    /** 预览寄到哪儿：p_mail_preview 没填就寄回平台自己的发信账号（那个邮箱在收件箱里就能看） */
    public static function previewTo(): string
    {
        $to = trim(Setting::getPlatform('p_mail_preview'));
        if (Mailer::isEmail($to)) {
            return $to;
        }
        return trim(Setting::getPlatform('p_mail_user'));
    }

    // ==================== 收件人 ====================

    /** 建批时挂在 recv_groups 末尾的记号。不是分组键，所以 normalizeGroups() 天然把它滤掉 */
    public const BANNED_MARK = '|include_banned';

    /** 这一批当初有没有显式带上被封站点（只在建批那一刻写进去，事后改不了） */
    public static function hadBannedMark(string $recvGroups): bool
    {
        return strpos($recvGroups, self::BANNED_MARK) !== false;
    }

    /** 勾选的分组：只认 GROUPS 里那两个键，去重保序；一个都没选就是空数组（控制器据此拒绝） */
    public static function normalizeGroups($raw): array
    {
        if (is_string($raw)) {
            // 建批时挂在末尾的那个记号先剥掉：它跟着 CSV 一起存，不剥的话最后一组会被它带脏
            $raw = array_filter(array_map('trim', explode(',', str_replace(self::BANNED_MARK, '', $raw))));
        }
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $g) {
            $g = trim((string)$g);
            if (isset(self::GROUPS[$g]) && !in_array($g, $out, true)) {
                $out[] = $g;
            }
        }
        // 固定按 owner、member 的顺序存，同一组勾选不会因点击顺序不同而留下两份不一样的串
        usort($out, static function (string $a, string $b): int {
            return array_search($a, array_keys(self::GROUPS), true) <=> array_search($b, array_keys(self::GROUPS), true);
        });
        return $out;
    }

    /**
     * 收件人名单：uid => ['uid','tid','email','who','name']。
     * 站长与管理员（owner）先落，再把普通成员补进来，已经在 owner 里的人不重复 —— 见口径①。
     * 封禁战队（tenants.status=banned）默认整体不发：那是要处理的事，不是通知的事。
     * 唯一的例外是销毁站点前那封告知信（v5.36.32）：平台超管在这一趟显式勾上 $includeBanned
     * 才把被封站点的人算进来。这颗勾只管「这一次算哪些人」，不改分组定义、也不动任何批次的历史。
     */
    public static function people(array $groups, bool $includeBanned = false): array
    {
        $groups = self::normalizeGroups($groups);
        if ($groups === []) {
            return [];
        }
        $base = Db::name('users')->where('status', 'approved');
        if (!$includeBanned) {
            $banned = self::bannedTids();
            if ($banned !== []) {
                $base->whereNotIn('tenant_id', $banned);
            }
        }
        $rows = $base->field('id, tenant_id, username, email, nickname, role')->select()->toArray();

        $out = [];
        foreach ($rows as $r) {
            $uid = (int)$r['id'];
            $isOwner = (string)$r['role'] === 'admin';
            if ($isOwner && in_array('owner', $groups, true)) {
                $out[$uid] = self::person($r, 'owner');
            } elseif (in_array('member', $groups, true)) {
                if (!isset($out[$uid])) {
                    $out[$uid] = self::person($r, 'member');
                }
            }
        }
        ksort($out);
        return $out;
    }

    private static function person(array $r, string $who): array
    {
        return [
            'uid'   => (int)$r['id'],
            'tid'   => (int)$r['tenant_id'],
            'email' => self::address((string)$r['email'], (string)$r['username']),
            'who'   => $who,
            'name'  => user_display_name($r),
        ];
    }

    /**
     * 这个账号能发到哪个邮箱：就按注册时那个 QQ号@qq.com（口径⑤）。
     * email 列是空的老实账号（导入的、改名过的）退回用登录名推一次，推不出就算「没有可用地址」。
     */
    public static function address(string $email, string $username): string
    {
        $email = trim($email);
        if (Mailer::isEmail($email)) {
            return $email;
        }
        $name = trim($username);
        if (preg_match('/^[1-9]\d{4,10}$/', $name) && Mailer::isEmail($name . '@qq.com')) {
            return $name . '@qq.com';
        }
        return '';
    }

    /** 被封站点的 id：这一列查坏了也不能把整页收件统计弄没，所以吞掉异常回空数组 */
    public static function bannedTids(): array
    {
        try {
            return array_map('intval', Db::name('tenants')->where('status', 'banned')->column('id'));
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * 勾上「带上被封站点」会多出几个人、几支队。不勾的时候也要给平台看到这个数，
     * 不然那颗勾是按了什么下去的没人知道。算法和 people() 一模一样，只是范围只圈被封的那几支队。
     */
    public static function bannedReach(array $groups): array
    {
        $groups = self::normalizeGroups($groups);
        if ($groups === []) {
            return ['people' => 0, 'teams' => 0];
        }
        $tids = self::bannedTids();
        if ($tids === []) {
            return ['people' => 0, 'teams' => 0];
        }
        $rows = Db::name('users')->where('status', 'approved')->whereIn('tenant_id', $tids)
            ->field('id, tenant_id, role')->select()->toArray();
        $wantOwner  = in_array('owner', $groups, true);
        $wantMember = in_array('member', $groups, true);
        $people = [];
        $teams  = [];
        foreach ($rows as $r) {
            $isOwner = (string)$r['role'] === 'admin';
            if (($isOwner && $wantOwner) || $wantMember) {
                $people[(int)$r['id']] = 1;
                $teams[(int)$r['tenant_id']] = 1;
            }
        }
        return ['people' => count($people), 'teams' => count($teams)];
    }

    /** 统计 + 脱敏抽样：接口只回这些，整本名单不出服务器 */
    public static function stats(array $groups, bool $includeBanned = false): array
    {
        $people = self::people($groups, $includeBanned);
        $owner = 0;
        $member = 0;
        $noMail = 0;
        $sample = [];
        $teams  = [];
        foreach ($people as $p) {
            if ($p['who'] === 'owner') { $owner++; } else { $member++; }
            if ($p['email'] === '') { $noMail++; }
            elseif (count($sample) < 3) { $sample[] = self::mask($p['email']); }
            $teams[$p['tid']] = 1;
        }
        $reach = self::bannedReach($groups);
        return [
            'groups'   => array_values($groups),
            'owner'    => $owner,
            'member'   => $member,
            'total'    => count($people),
            'teams'    => count($teams),
            'no_mail'  => $noMail,
            'will_send' => count($people) - $noMail,
            'sample'   => $sample,
            'include_banned' => $includeBanned ? 1 : 0,
            'banned_people'  => $reach['people'],
            'banned_teams'   => $reach['teams'],
        ];
    }

    /** 12345678@qq.com → 12****8@qq.com：够看出是哪一批地址，又不至于把名单念一遍 */
    public static function mask(string $email): string
    {
        $at = strpos($email, '@');
        if ($at === false) {
            return '';
        }
        $local = substr($email, 0, $at);
        $host  = substr($email, $at + 1);
        $len   = strlen($local);
        if ($len <= 2) {
            $shown = substr($local, 0, 1) . '**';
        } elseif ($len <= 4) {
            $shown = substr($local, 0, 1) . '**' . substr($local, -1);
        } else {
            $shown = substr($local, 0, 2) . '****' . substr($local, -1);
        }
        return $shown . '@' . $host;
    }

    // ==================== 按收件人替换变量（v5.36.31） ====================

    /**
     * 拼出「这一个人」的变量表。传进来的都是已经查好的行，不在这儿发查询
     * （队列一步最多 20 封，用户行与战队行各查一次就够，别一封一趟查）。
     */
    public static function vars(array $item, ?array $user, ?array $tenant, string $host, ?string $date = null): array
    {
        $username = trim((string)($user['username'] ?? ''));
        $nickname = trim((string)($user['nickname'] ?? ''));
        $qq = preg_match('/^[1-9]\d{4,10}$/', $username) ? $username : '';
        $slug = trim((string)($tenant['slug'] ?? ''));
        $site = rtrim($host, '/') . '/';
        if ($slug !== '') {
            $site .= $slug . '/';
        }
        return [
            '{name}' => self::safeVar($nickname !== '' ? $nickname : $username, '本人'),
            '{team}' => self::safeVar((string)($tenant['name'] ?? ''), '本站'),
            '{qq}'   => self::safeVar($qq, '未登记'),
            '{role}' => (string)($item['who'] ?? 'member') === 'owner' ? '管理员' : '成员',
            '{date}' => self::safeVar($date !== null ? $date : date('Y-m-d'), date('Y-m-d')),
            '{site}' => self::safeVar($site, ''),
            '{slug}' => self::safeVar($slug, ''),
        ];
    }

    /**
     * 把模板里的 {xxx} 换成这个人的值。
     * 不用 str_replace 的搜索/替换配对，是因为变量名彼此不是子串、strtr 一次扫完更稳（也不会二次替换）。
     */
    public static function renderVars(string $text, array $vars): string
    {
        return $text === '' ? '' : strtr($text, $vars);
    }

    /** 模板里到底用没用变量（预览那封要不要附一段渲染示例，就按这个判） */
    public static function usesVars(string $text): bool
    {
        foreach (array_keys(self::VARS) as $k) {
            if (strpos($text, $k) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * 替换进去的值先收一遍：昵称与队名是注册时别人自己填的，正文可能是 HTML，
     * 尖括号与不可见字符要剥掉 —— 不然一个人可以用昵称往别人收到的邮件里塞标签。
     */
    private static function safeVar(string $v, string $fallback): string
    {
        $v = preg_replace('/[\x00-\x1f\x7f<>]/u', '', $v) ?? '';
        $v = trim(preg_replace('/\s+/u', ' ', $v) ?? $v);
        if ($v === '') {
            return $fallback;
        }
        return mb_substr($v, 0, self::VAR_VALUE_MAX);
    }

    /**
     * 渲染示例：给平台看「这一封发出去到底长什么样」，取名单里前 N 个人（默认一站长一成员）。
     * 名单不出服务器 —— 这里只回示例，人数与抽样另走 stats 那一条。
     *
     * @return array<int, array{who:string,name:string,team:string,subject:string,body:string}>
     */
    public static function samples(string $subject, string $body, array $groups, int $limit = 2, string $host = '', bool $includeBanned = false): array
    {
        $people = self::people($groups, $includeBanned);
        if ($people === []) {
            return [];
        }
        $want = [];
        $seen = [];
        foreach ($people as $p) {
            if (isset($seen[$p['who']]) && $seen[$p['who']] >= 1) {
                continue;
            }
            $seen[$p['who']] = (int)($seen[$p['who']] ?? 0) + 1;
            $want[$p['uid']] = $p;
            if (count($want) >= max(1, $limit)) {
                break;
            }
        }
        $rows = Db::name('users')->whereIn('id', array_keys($want))
            ->field('id, username, nickname, role')->select()->toArray();
        $users = array_column($rows, null, 'id');
        $tids  = array_column($want, 'tid');
        $tenants = $tids === [] ? [] : array_column(
            Db::name('tenants')->whereIn('id', $tids)->field('id, slug, name')->select()->toArray(), null, 'id'
        );
        $out = [];
        foreach ($want as $uid => $p) {
            $v = self::vars(
                ['who' => $p['who']],
                is_array($users[$uid] ?? null) ? $users[$uid] : null,
                is_array($tenants[$p['tid']] ?? null) ? $tenants[$p['tid']] : null,
                $host
            );
            $out[] = [
                'who'     => $p['who'],
                'name'    => $v['{name}'],
                'team'    => $v['{team}'],
                'subject' => self::renderVars($subject, $v),
                'body'    => self::renderVars($body, $v),
            ];
        }
        return $out;
    }

    /**
     * 站点根地址（拼 {site} 用）：群发这一步永远是 HTTP 请求里跑的，拿当前域名就对；
     * 拿不到（命令行里手动跑队列）就退回相对路径，不成串。
     */
    public static function siteHost(): string
    {
        try {
            if (function_exists('request')) {
                $d = trim((string)request()->domain());
                if ($d !== '' && strpos($d, '://') !== false) {
                    return $d;
                }
            }
        } catch (\Throwable $e) {
            // 拿不到域名就按空串处理，{site} 退成 /slug/
        }
        return '';
    }

    // ==================== 预览门禁 ====================

    /** 门禁认的是「这一份标题+正文」：改一个字就是另一个键，必须重新预览（口径③） */
    public static function previewKey(int $actorId, string $subject, string $body): string
    {
        return 'mail_prev_' . sha1($actorId . '|' . $subject . '|' . md5($body));
    }

    public static function markPreviewed(int $actorId, string $subject, string $body): void
    {
        Cache::set(self::previewKey($actorId, $subject, $body), date('Y-m-d H:i:s'), self::PREVIEW_TTL);
    }

    public static function previewedBefore(int $actorId, string $subject, string $body): bool
    {
        return Cache::get(self::previewKey($actorId, $subject, $body)) !== null;
    }

    public static function clearPreview(int $actorId, string $subject, string $body): void
    {
        Cache::delete(self::previewKey($actorId, $subject, $body));
    }

    // ==================== 站内信那一份 ====================

    /**
     * 站内信列表里那一句话：标题 + 正文开头。整篇正文留在 mail_blasts，点开才回查。
     * 标签在这里剥掉 —— 站内信只做可读文本展示，不执行邮件里的 HTML（排版以邮件里看到的为准）。
     */
    public static function noteOf(string $subject, string $body): string
    {
        $plain = self::toPlainText($body);
        $note = '【' . trim($subject) . '】' . ($plain === '' ? '' : ' ' . $plain);
        return mb_substr(preg_replace('/\s+/u', ' ', $note) ?? $note, 0, 200);
    }

    /** 把正文收成给人读的纯文本（HTML 那几行标签换成换行，实体只认最常见的几个） */
    public static function toPlainText(string $body): string
    {
        if (self::looksLikeHtmlBody($body)) {
            $t = preg_replace('/<\s*(br|\/p|\/div|\/li|\/tr|\/h[1-6])\s*\/?\s*>/i', "\n", $body) ?? $body;
            $t = preg_replace('/<[^>]+>/', '', $t) ?? $t;
            $t = str_replace(['&nbsp;', '&amp;', '&lt;', '&gt;', '&quot;', '&#39;'], [' ', '&', '<', '>', '"', "'"], $t);
        } else {
            $t = $body;
        }
        $t = preg_replace('/[ \t]+/u', ' ', $t) ?? $t;
        $t = preg_replace('/\n{3,}/', "\n\n", $t) ?? $t;
        return trim($t);
    }

    /** 判「是不是 HTML」只有一个入口：Mailer 那套保守判定，别让两处自己算 */
    private static function looksLikeHtmlBody(string $body): bool
    {
        return Mailer::looksLikeHtml($body);
    }

    // ==================== 建批与队列 ====================

    /**
     * 建批次：把名单落成逐封明细（一次一封），同时记下正文原样与格式判定结果。
     * 调用前必须已经过预览门禁 —— 这个函数不查缓存，是控制器的事，别绕过去。
     *
     * @return int 批次 id
     */
    public static function create(array $groups, string $subject, string $body, int $actorId, string $actorName, bool $includeBanned = false): int
    {
        $people = self::people($groups, $includeBanned);
        $now    = date('Y-m-d H:i:s');
        $blastId = 0;
        Db::startTrans();
        try {
            $blastId = (int)Db::name('mail_blasts')->insertGetId([
                'subject'     => mb_substr(trim($subject), 0, self::SUBJECT_MAX),
                'body'        => $body,
                'is_html'     => Mailer::looksLikeHtml($body) ? 1 : 0,
                // 带上被封站点这一趟在这里留个记号：批次历史与明细页都读得出「这批是销毁前的告知」
                'recv_groups' => implode(',', self::normalizeGroups($groups))
                    . ($includeBanned ? self::BANNED_MARK : ''),
                'target'      => count($people),
                'status'      => 'queued',
                'actor_id'    => $actorId,
                'actor_name'  => mb_substr($actorName, 0, 64),
                'created_at'  => $now,
            ]);
            $rows = [];
            foreach ($people as $p) {
                $rows[] = [
                    'blast_id'  => $blastId,
                    'uid'       => $p['uid'],
                    'tenant_id' => $p['tid'],
                    'email'     => $p['email'],
                    'who'       => $p['who'],
                    'status'    => 'pending',
                    'notice'    => 0,
                    'err'       => '',
                ];
            }
            // 分批插：一次全塞几千行会顶到语句上限，500 行一批稳
            foreach (array_chunk($rows, 500) as $chunk) {
                Db::name('mail_blast_items')->insertAll($chunk);
            }
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            if ($blastId > 0) {
                Db::name('mail_blasts')->where('id', $blastId)->delete();
            }
            throw $e;
        }
        return $blastId;
    }

    /** 进度：状态各几封 + 站内信补了几条。批次页与队列接口都回这份 */
    public static function progress(int $blastId): array
    {
        $rows = Db::name('mail_blast_items')->where('blast_id', $blastId)
            ->field('status, COUNT(*) AS n')->group('status')->select()->toArray();
        $by = ['pending' => 0, 'sending' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0];
        foreach ($rows as $r) {
            $k = (string)$r['status'];
            if (isset($by[$k])) { $by[$k] = (int)$r['n']; }
        }
        $blast = Db::name('mail_blasts')->where('id', $blastId)
            ->field('id,status,target,subject,is_html,created_at,started_at,finished_at')->find();
        return [
            'blast_id'  => $blastId,
            'pending'   => $by['pending'],
            'sending'   => $by['sending'],
            'sent'      => $by['sent'],
            'failed'    => $by['failed'],
            'skipped'   => $by['skipped'],
            'notices'   => (int)Db::name('mail_blast_items')->where('blast_id', $blastId)->where('notice', 1)->count(),
            'status'    => (string)($blast['status'] ?? ''),
            'target'    => (int)($blast['target'] ?? 0),
            'subject'   => (string)($blast['subject'] ?? ''),
            'is_html'   => (int)($blast['is_html'] ?? 0),
            'created_at' => (string)($blast['created_at'] ?? ''),
            'started_at' => (string)($blast['started_at'] ?? ''),
            'finished_at' => (string)($blast['finished_at'] ?? ''),
            'left'      => $by['pending'] + $by['sending'],
            'done'      => ($by['pending'] + $by['sending']) === 0 ? 1 : 0,
        ];
    }

    /**
     * 推进一步：认领最多 batch 封，逐封发、逐封隔 gap 毫秒，每封都补一条站内信。
     * 只处理自己认领到的行，别的数据一行不碰。
     *
     * @return array progress + ['tried' => 这几封, 'msg' => 给人看的一句]
     */
    public static function step(int $blastId): array
    {
        $blast = Db::name('mail_blasts')->where('id', $blastId)->find();
        if (!$blast) {
            return ['error' => 1, 'msg' => '批次不存在'];
        }
        $status = (string)$blast['status'];
        if ($status === 'done' || $status === 'stopped') {
            $p = self::progress($blastId);
            $p['msg'] = $status === 'done' ? '这一批已经发完了' : '这一批暂停中，点「继续」才往下走';
            $p['tried'] = 0;
            return $p;
        }
        if ($status === 'queued') {
            Db::name('mail_blasts')->where('id', $blastId)->update([
                'status' => 'running', 'started_at' => date('Y-m-d H:i:s'),
            ]);
        }

        $batch = self::num('p_mail_batch', self::BATCH_RANGE[0], self::BATCH_RANGE[1]);
        $gap   = self::num('p_mail_gap_ms', self::GAP_RANGE[0], self::GAP_RANGE[1]);
        $ids   = Db::name('mail_blast_items')->where('blast_id', $blastId)
            ->where('status', 'pending')->order('id', 'asc')->limit($batch)->column('id');

        // 先认领再发：这句 UPDATE 带着 status='pending'，两个标签页同时点也只有一边拿得到
        $claimed = [];
        foreach ($ids as $id) {
            $got = Db::name('mail_blast_items')->where('id', (int)$id)
                ->where('status', 'pending')->update(['status' => 'sending']);
            if ($got > 0) {
                $claimed[] = (int)$id;
            }
        }

        $subject = (string)$blast['subject'];
        $body    = (string)$blast['body'];
        $isHtml  = (int)$blast['is_html'] === 1;
        $config  = Mailer::platformConfig();
        $today   = date('Y-m-d');
        $host    = self::siteHost();

        // 这一步要用的人行与队行各查一次：逐封再查就是 20 封 × 2 次查询，且变量替换要的字段都在里头
        $claimedIds = $claimed === [] ? [] : $claimed;
        $items = $claimedIds === [] ? [] : Db::name('mail_blast_items')->whereIn('id', $claimedIds)->select()->toArray();
        $uids = array_unique(array_column($items, 'uid'));
        $users = $uids === [] ? [] : array_column(
            Db::name('users')->whereIn('id', $uids)->field('id, username, nickname, role')->select()->toArray(), null, 'id'
        );
        $tids = array_unique(array_column($items, 'tenant_id'));
        $tenants = $tids === [] ? [] : array_column(
            Db::name('tenants')->whereIn('id', $tids)->field('id, slug, name')->select()->toArray(), null, 'id'
        );
        $byId = array_column($items, null, 'id');

        foreach (array_keys($claimed) as $n) {
            $id = (int)$claimed[$n];
            $it = $byId[$id] ?? null;
            if (!is_array($it)) {
                continue;
            }
            // 标题与正文按这个人自己的值现拼（库里存的还是模板那一份）
            $vars    = self::vars($it, $users[(int)$it['uid']] ?? null, $tenants[(int)$it['tenant_id']] ?? null, $host, $today);
            $toSubj  = self::renderVars($subject, $vars);
            $toBody  = self::renderVars($body, $vars);
            $email = (string)$it['email'];
            if ($email === '' || !Mailer::isEmail($email)) {
                self::finishItem($id, 'skipped', '没有可用的邮箱地址（注册时那个 QQ号@qq.com 推不出来）');
            } else {
                $err = Mailer::send($email, $toSubj, $toBody, $config, $isHtml);
                if ($err === null) {
                    self::finishItem($id, 'sent', '');
                } else {
                    self::finishItem($id, 'failed', self::cleanError($err, $config));
                }
            }
            // 邮件成没成都补一条站内信 —— 邮箱无效的人靠这个看到通知（口径④）
            // 这一句也按同一个人的变量拼：站内信是他自己看的，不该出现 {name} 这种字样
            $pushed = UserNotice::push((int)$it['uid'], 'platform', $blastId,
                self::noteOf($toSubj, $toBody), 0, 'blast:' . $blastId . ':' . (int)$it['uid']);
            if ($pushed) {
                Db::name('mail_blast_items')->where('id', $id)->update(['notice' => 1]);
            }
            if ($n < count($claimed) - 1 && $gap > 0) {
                usleep($gap * 1000);
            }
        }

        $p = self::progress($blastId);
        if ($p['left'] === 0) {
            Db::name('mail_blasts')->where('id', $blastId)->update([
                'status' => 'done', 'finished_at' => date('Y-m-d H:i:s'),
            ]);
            $p['status'] = 'done';
            $p['msg'] = '这一批跑完了：成功 ' . $p['sent'] . ' 封，失败 ' . $p['failed']
                . ' 封，没有地址跳过 ' . $p['skipped'] . ' 封，站内信 ' . $p['notices'] . ' 条';
        } else {
            $p['msg'] = '这一步发了 ' . count($claimed) . ' 封，还剩 ' . $p['left'] . ' 封';
        }
        $p['tried'] = count($claimed);
        $p['batch'] = $batch;
        $p['gap']   = $gap;
        return $p;
    }

    private static function finishItem(int $id, string $status, string $err): void
    {
        Db::name('mail_blast_items')->where('id', $id)->update([
            'status'   => $status,
            'err'      => mb_substr($err, 0, 200),
            'sent_at'  => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * 邮件服务器回的那句话要如实记，但不能把自家账号连着记一串：
     * 授权码本来就不会出现在报错里（Mailer 只写错误码和文字），账号名可能出现在 "MAIL FROM" 那句里。
     */
    public static function cleanError(string $err, array $config): string
    {
        $err = trim(preg_replace('/\s+/u', ' ', Mailer::safeText($err)) ?? $err);
        foreach ([$config['smtp_pass'] ?? '', $config['smtp_user'] ?? ''] as $secret) {
            if ($secret !== '' && strlen($secret) > 3) {
                $err = str_replace($secret, '***', $err);
            }
        }
        return mb_substr($err, 0, 200);
    }

    /** 手动暂停：正在飞的那一封还是会跑完，只是不再认领新的 */
    public static function stop(int $blastId): bool
    {
        $n = Db::name('mail_blasts')->where('id', $blastId)
            ->where('status', 'in', ['queued', 'running'])->update(['status' => 'stopped']);
        return $n > 0;
    }

    /** 继续：只有暂停中的批次能回到发送中（发完的那批不给重开，重开该重新建一批） */
    public static function resume(int $blastId): bool
    {
        $n = Db::name('mail_blasts')->where('id', $blastId)
            ->where('status', 'stopped')->update(['status' => 'running']);
        return $n > 0;
    }

    /**
     * 把卡在「已认领」的死行重排回待发。
     * 只在页面上点这颗按钮时才做 —— 自动回收就要给「在飞多久算死」定一个阈值，
     * 一封邮件慢一点就被判死、又被重发一遍，那才是真会打扰收件人的事。
     */
    public static function requeueStuck(int $blastId): int
    {
        return (int)Db::name('mail_blast_items')->where('blast_id', $blastId)
            ->where('status', 'sending')->update(['status' => 'pending']);
    }

    /**
     * 收件人本人回查这一封通知的整篇正文（战队侧 Inbox::blast 用）。
     *
     * 只认「这一批是发给我的」：forum_notices 里有我这一行 kind=platform 且 post_id=批次号才给读，
     * 所以这不是一个「按批次号读平台邮件」的口子 —— 没被通知到的人，猜对批次号也拿不到。
     * 正文一律收成纯文本再回：站内信只做可读展示，不执行邮件里的 HTML。
     *
     * @return array|null 拿不到就是 null（不是收件人、或批次已经不在了）
     */
    public static function bodyFor(int $uid, int $blastId): ?array
    {
        if ($uid <= 0 || $blastId <= 0) {
            return null;
        }
        $mine = Db::name('forum_notices')->where('to_user_id', $uid)
            ->where('kind', 'platform')->where('post_id', $blastId)->find();
        if (!$mine) {
            return null;
        }
        $b = Db::name('mail_blasts')->where('id', $blastId)->find();
        if (!$b) {
            return null;
        }
        // 库里存的是模板，点开看到的要和他邮件里那一份一致 —— 按读的人自己那行明细现拼
        $item = Db::name('mail_blast_items')->where('blast_id', $blastId)->where('uid', $uid)->find();
        $vars = self::vars(
            is_array($item) ? $item : ['who' => 'member'],
            Db::name('users')->where('id', $uid)->field('id, username, nickname, role')->find() ?: null,
            is_array($item) ? (Db::name('tenants')->where('id', (int)$item['tenant_id'])->field('id, slug, name')->find() ?: null) : null,
            self::siteHost()
        );
        return [
            'blast_id'   => $blastId,
            'subject'    => self::renderVars((string)$b['subject'], $vars),
            'body'       => self::toPlainText(self::renderVars((string)$b['body'], $vars)),
            'is_html'    => (int)$b['is_html'],
            'at'         => (string)($mine['created_at'] ?? ''),
        ];
    }
}
