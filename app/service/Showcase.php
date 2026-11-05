<?php
declare (strict_types = 1);

namespace app\service;

use think\facade\Cache;
use think\facade\Db;

/**
 * 战队对外展示页（v5.20.0）
 *
 * 这一层是"访客能看到什么"的唯一口径：/{slug}/show 访客页、管理端编辑回显、
 * 平台公示大厅三处都从这里取数，各写一遍迟早漂（参照 user_display_name 的教训）。
 *
 * 三层开关，缺一不可：
 *   ① 战队侧 show_enabled —— 默认关。新入驻战队不会莫名其妙被互联网抓到；
 *   ② 平台侧 show_force_off —— 超管一键下架名单，优先级高于①（违规内容要能立刻断）；
 *   ③ 站点本身 tenants.status='approved' —— 被停用/暂停的站点，展示页跟着消失。
 * 成员是否出现在"成员风采"是第四层：users.show_public 本人勾 + 队长的 members 模块开关，
 * 双层都开才公开（与私聊那两个开关同思路）。
 *
 * 落库只有一张 showcase_items（图集/荣誉）和一张 show_submissions（投稿/意向）；
 * 口号、图片路径、招募岗位、精选勾选、块顺序与分栏（v5.29.0）全部写在 settings 键值里，不再加列。
 */
class Showcase
{
    /** 展示页模块：键 => 中文名，同时也是管理端的开关列表 */
    public const MODULES = [
        'stats'    => '战绩数据条',
        'gallery'  => '图集',
        'honor'    => '荣誉墙',
        'recruit'  => '招募岗位',
        'members'  => '成员风采',
        'notices'  => '战队动态',
        'contents' => '精选内容',
        'post'     => '接受内容投稿',
        'intent'   => '接受入队意向',
    ];

    /**
     * 访客页上可以随便挪的六个内容块：键 => [中文名, 默认落哪一栏]（0=左栏读内容，1=右栏看资历）
     * 战绩数据条坐在首屏里、投稿与入队意向是页尾那张表单，这三块位置固定不参与排序。
     */
    public const BLOCKS = [
        'recruit'  => ['招募岗位', 0],
        'honor'    => ['荣誉墙',   1],
        'members'  => ['成员风采', 1],
        'gallery'  => ['战队图集', 0],
        'notices'  => ['战队动态', 0],
        'contents' => ['精选内容', 0],
    ];

    public const KINDS     = ['gallery', 'honor'];
    public const TYPES     = ['post', 'intent'];
    public const SUB_STATUSES = ['pending', 'approved', 'rejected'];

    public const MAX_ITEMS     = 24;   // 图集/荣誉各自上限
    public const MAX_POSITIONS = 8;    // 招募岗位上限
    public const MAX_SELECT    = 6;    // 精选公告/改枪码/攻略上限
    public const KEEP_SUBS     = 1000; // 每站点投稿+意向保留上限（爆破式灌提交不至把表写爆）

    /** 首字色块调色板（公开页不放照片，也就不需要给 QQ 头像开匿名出口） */
    // 头像/队徽占位的底色：浅色系里的柔和色块，配 --sc-text 深色字可读（与站内 app.css 同一挂浅色基调）
    public const COLORS = ['#f6ded8', '#f3e6cd', '#d9ecdd', '#d8e5f5', '#e2dcf4', '#f2d9e8', '#d6ecec', '#e7ebd2'];

    /** 展示页的页面底色（showcase.css 的 --sc-bg）：页脚链接这类强调字就坐在它上面，比纯白更严 */
    public const PAGE_BG = '#f4f6f8';

    // ==================== 配置读取 ====================

    /** 按租户读一条 settings（当前上下文走 Setting 的进程内缓存，跨租户直查库） */
    protected static function raw(int $tid, string $key, string $default = ''): string
    {
        if ($tid === TenantContext::id()) {
            return Setting::get($key, $default);
        }
        $v = Db::name('settings')->where('tenant_id', $tid)->where('key', $key)->value('value');
        return $v === null ? $default : (string)$v;
    }

    /** 归一化后的整份页面配置（管理端回显与访客页共用） */
    public static function config(int $tid): array
    {
        $mods = self::MODULES;
        $on   = json_decode(self::raw($tid, 'show_modules', ''), true);
        if (!is_array($on)) {
            // 没配置过时的默认展示：内容模块全开，两个投稿入口只开"入队意向"
            $on = ['stats' => 1, 'gallery' => 1, 'honor' => 1, 'recruit' => 1,
                   'members' => 0, 'notices' => 1, 'contents' => 1, 'post' => 0, 'intent' => 1];
        }
        $modules = [];
        foreach ($mods as $k => $label) {
            $modules[$k] = isset($on[$k]) && (int)$on[$k] === 1 ? 1 : 0;
        }
        return [
            'enabled'    => self::raw($tid, 'show_enabled', '0') === '1' ? 1 : 0,
            'slogan'     => self::raw($tid, 'show_slogan', ''),
            'logo'       => self::raw($tid, 'show_logo', ''),
            'banner'     => self::raw($tid, 'show_banner', ''),
            'contact'    => self::raw($tid, 'show_contact', ''),
            'accent'     => self::normalizeAccent(self::raw($tid, 'show_accent', '')),
            'recruiting' => self::raw($tid, 'show_recruiting', '1') === '1' ? 1 : 0,
            'modules'    => $modules,
            'blocks'     => self::blocks($tid),
            'positions'  => self::normalizePositions(self::raw($tid, 'show_positions', '')),
            'notice_ids'   => self::csvIds(self::raw($tid, 'show_notice_ids', '')),
            'guncode_ids'  => self::csvIds(self::raw($tid, 'show_guncode_ids', '')),
            'guide_ids'    => self::csvIds(self::raw($tid, 'show_guide_ids', '')),
            'updated_at'   => self::raw($tid, 'show_updated_at', ''),
        ];
    }

    /**
     * 块顺序与分栏（show_block_order = "recruit:0,honor:1,..."）。
     * 脏键丢掉、重复只认第一次、没列出来的按默认顺序补在末尾——半份保存不能让访客页少一块；
     * 老战队没有这一行，回落的就是模板原本那块顺序，不用回填。
     */
    public static function blocks(int $tid): array
    {
        return self::parseBlocks(self::raw($tid, 'show_block_order', ''));
    }

    /** 管理端落库前洗成规范串：六块一个不少，每块带 :0/:1，顺序就是队长排的那个 */
    public static function normalizeBlockOrder(string $raw): string
    {
        $out = [];
        foreach (self::parseBlocks($raw) as $b) {
            $out[] = $b['key'] . ':' . $b['side'];
        }
        return implode(',', $out);
    }

    protected static function parseBlocks(string $raw): array
    {
        $out  = [];
        $seen = [];
        foreach (explode(',', $raw) as $item) {
            $item = trim($item);
            if ($item === '') {
                continue;
            }
            $pair = explode(':', $item, 2);
            $key  = trim($pair[0]);
            if (!isset(self::BLOCKS[$key]) || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = 1;
            // 只有 :1 算右栏；省掉冒号那一段才用该块的默认栏位（写 :0 是"我就是要放左栏"）
            $out[] = [
                'key'  => $key,
                'name' => self::BLOCKS[$key][0],
                'side' => isset($pair[1]) ? ((int)trim($pair[1]) === 1 ? 1 : 0) : self::BLOCKS[$key][1],
            ];
        }
        foreach (self::BLOCKS as $key => $b) {
            if (!isset($seen[$key])) {
                $out[] = ['key' => $key, 'name' => $b[0], 'side' => $b[1]];
            }
        }
        return $out;
    }

    /**
     * 队色只认 #rrggbb；不合法就当没用过（回落到 CSS 里的站内默认色）。
     * 存这一份不需要动数据库——它和口号、队徽一样是 settings 里的一个键。
     */
    public static function normalizeAccent(string $hex): string
    {
        $hex = strtolower(trim($hex));
        return preg_match('/^#[0-9a-f]{6}$/', $hex) ? $hex : '';
    }

    /** sRGB 相对亮度（0..1），用来决定主色上该配深色字还是白字 */
    public static function luminance(string $hex): float
    {
        [$r, $g, $b] = self::hexRgb($hex);
        $f = function (int $v): float {
            $v = $v / 255;
            return $v <= 0.03928 ? $v / 12.92 : pow(($v + 0.055) / 1.055, 2.4);
        };
        return 0.2126 * $f($r) + 0.7152 * $f($g) + 0.0722 * $f($b);
    }

    /** WCAG 对比度 */
    public static function contrast(string $a, string $b): float
    {
        $la = self::luminance($a);
        $lb = self::luminance($b);
        $hi = max($la, $lb);
        $lo = min($la, $lb);
        return ($hi + 0.05) / ($lo + 0.05);
    }

    /** 主色上该用哪种字色：白字与深色字谁对比更高就用谁 */
    public static function inkOn(string $hex): string
    {
        return self::contrast($hex, '#ffffff') >= self::contrast($hex, '#17233d') ? '#ffffff' : '#17233d';
    }

    public static function hexRgb(string $hex): array
    {
        $hex = ltrim(self::normalizeAccent($hex), '#');
        if ($hex === '') {
            return [15, 118, 110];
        }
        return [
            (int)hexdec(substr($hex, 0, 2)),
            (int)hexdec(substr($hex, 2, 2)),
            (int)hexdec(substr($hex, 4, 2)),
        ];
    }

    /** 把同一个主色按系数压暗（悬停用），不引入第二个色相 */
    public static function shade(string $hex, float $k): string
    {
        [$r, $g, $b] = self::hexRgb($hex);
        return sprintf('#%02x%02x%02x', max(0, min(255, (int)round($r * $k))), max(0, min(255, (int)round($g * $k))), max(0, min(255, (int)round($b * $k))));
    }

    /** 同色往白里掺（浅底 hover / 禁用态），k=0.12 表示 12% 主色 */
    public static function tint(string $hex, float $k): string
    {
        [$r, $g, $b] = self::hexRgb($hex);
        $mix = function (int $v) use ($k): int {
            return max(0, min(255, (int)round($v * $k + 255 * (1 - $k))));
        };
        return sprintf('#%02x%02x%02x', $mix($r), $mix($g), $mix($b));
    }

    /**
     * 战队自选主色 → 访客页要用的那组 CSS 变量。
     * 队色可以自选得很大胆，但颜色一旦上到界面上就要能读：
     *   text  — 在白底上写的强调字/链接，加深到对页面底色 ≥4.5
     *   solid — 按钮、角标那种"色块底"，加深到对配的字色 ≥4.5
     *   main  — 原始色只用于描边、色条、焦点环这些不承载文字的地方
     * 返回空数组表示这队没选色，用 showcase.css 里的站内默认色。
     */
    public static function accentTokens(string $hex): array
    {
        $hex = self::normalizeAccent($hex);
        if ($hex === '') {
            return [];
        }
        [$r, $g, $b] = self::hexRgb($hex);

        // 强调字：只能往深走（往浅走在浅底上更看不清）；基准取页面底色而非纯白，
        // 因为页脚那类文字链接就直接写在 --sc-bg 上，按白底算会给出刚够又实际不够的色
        $text = $hex;
        for ($k = 1.0; $k > 0.30; $k -= 0.04) {
            $text = self::shade($hex, $k);
            if (self::contrast($text, self::PAGE_BG) >= 4.5) {
                break;
            }
        }

        // 色块底：先试原色配白字/深字，都不够就逐级压暗，直到有一种字达标
        $solid = $hex;
        $ink   = self::inkOn($hex);
        for ($k = 1.0; $k > 0.39; $k -= 0.06) {
            $c    = self::shade($hex, $k);
            $w    = self::contrast($c, '#ffffff');
            $dark = self::contrast($c, '#17233d');
            if (max($w, $dark) >= 4.5) {
                $solid = $c;
                $ink   = $w >= $dark ? '#ffffff' : '#17233d';
                break;
            }
            $solid = $c;
            $ink   = $w >= $dark ? '#ffffff' : '#17233d';
        }

        return [
            'main'  => $hex,
            'text'  => $text,
            'solid' => $solid,
            'ink'   => $ink,
            'dark'  => self::shade($solid, 0.86),
            'soft'  => self::tint($hex, 0.1),
            'off'   => self::tint($hex, 0.45),
            'rgb'   => $r . ',' . $g . ',' . $b,
        ];
    }

    /** 招募岗位 JSON 归一：[{name,require,seats}]，脏数据直接丢掉而不是带着渲染 */
    public static function normalizePositions(string $json): array
    {
        $rows = json_decode($json, true);
        if (!is_array($rows)) {
            return [];
        }
        $out = [];
        foreach ($rows as $r) {
            if (!is_array($r)) {
                continue;
            }
            $name = mb_substr(trim((string)($r['name'] ?? '')), 0, 20);
            if ($name === '') {
                continue;
            }
            $out[] = [
                'name'    => $name,
                'require' => mb_substr(trim((string)($r['require'] ?? '')), 0, 60),
                'seats'   => max(0, (int)($r['seats'] ?? 0)),
            ];
            if (count($out) >= self::MAX_POSITIONS) {
                break;
            }
        }
        return $out;
    }

    /** CSV id 列表归一（保序去重，上限 MAX_SELECT） */
    public static function csvIds(string $csv): array
    {
        $out = [];
        foreach (explode(',', $csv) as $id) {
            $id = (int)trim($id);
            if ($id > 0 && !in_array($id, $out, true)) {
                $out[] = $id;
            }
            if (count($out) >= self::MAX_SELECT) {
                break;
            }
        }
        return $out;
    }

    // ==================== 三层开关 ====================

    /** 平台下架名单（tenant_id CSV，平台级 settings） */
    public static function forceOffIds(): array
    {
        $out = [];
        foreach (explode(',', Setting::getPlatform('show_force_off', '')) as $id) {
            if ((int)$id > 0) {
                $out[] = (int)$id;
            }
        }
        return $out;
    }

    public static function isForceOff(int $tid): bool
    {
        return in_array($tid, self::forceOffIds(), true);
    }

    /** 平台公示大厅总开关（默认开：这是平台自己的运营位） */
    public static function hallOpen(): bool
    {
        return Setting::getPlatform('hall_enabled', '1') === '1';
    }

    /** 焦点展示区的手动精选 */
    public static function featuredIds(): array
    {
        $out = [];
        foreach (explode(',', Setting::getPlatform('show_featured', '')) as $id) {
            if ((int)$id > 0 && !in_array($id, $out, true)) {
                $out[] = (int)$id;
            }
            if (count($out) >= 3) {
                break;
            }
        }
        return $out;
    }

    /**
     * 战队侧是否已开启（只看 ①，不含平台下架与站点状态）——
     * 管理端与 sitemap 生成用它判断"要不要收录 /show"，访客页用 visible()
     */
    public static function teamOpen(int $tid): bool
    {
        return self::raw($tid, 'show_enabled', '0') === '1';
    }

    /** 访客实际能不能打开这一页：① + ② + ③ */
    public static function visible(int $tid): bool
    {
        if ($tid <= TenantContext::PLATFORM_ID || !self::teamOpen($tid) || self::isForceOff($tid)) {
            return false;
        }
        return (string)Db::name('tenants')->where('id', $tid)->value('status') === 'approved';
    }

    // ==================== 页面内容 ====================

    /** 展示页条目；$all=true 给管理端（含隐藏项） */
    public static function items(int $tid, string $kind, bool $all = false): array
    {
        $q = Db::name('showcase_items')->where('tenant_id', $tid)->where('kind', $kind);
        if (!$all) {
            $q = $q->where('visible', 1);
        }
        $rows = $q->order('sort', 'asc')->order('id', 'asc')->limit(self::MAX_ITEMS)->select()->toArray();
        $out  = [];
        foreach ($rows as $r) {
            $img = (string)$r['image'];
            if ($img === '') {
                continue;   // 没图的空条目不进页面（草稿只在管理端可见）
            }
            // 站长在「存储清理」里删了文件、条目还留着时，访客页不能出现裂图；
            // 管理端仍要看得见，才好让他重新配图
            if (!$all && !self::alive($img)) {
                continue;
            }
            $out[] = [
                'id'      => (int)$r['id'],
                'title'   => (string)$r['title'],
                'note'    => (string)$r['note'],
                'image'   => (string)$r['image'],
                'sort'    => (int)$r['sort'],
                'visible' => (int)$r['visible'],
            ];
        }
        return $out;
    }

    /** 按优先级取第一张文件还在的图片，全都没有就返回空串（模板据此不出 <img>） */
    public static function firstAlive(array $paths): string
    {
        foreach ($paths as $p) {
            if (self::alive((string)$p)) {
                return (string)$p;
            }
        }
        return '';
    }

    /**
     * 站内图片路径当前是否真有文件。图片一律是 ImageUpload 落在 public/storage 下的
     * 相对路径，所以判存在只需拼绝对路径 stat 一次；外链直接当不可用（展示页不认外链）。
     */
    public static function alive(string $webPath): bool
    {
        if ($webPath === '' || strpos($webPath, '/storage/') !== 0) {
            return false;
        }
        $rel = str_replace('/', DIRECTORY_SEPARATOR, ltrim($webPath, '/'));
        return is_file(rtrim(public_path(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $rel);
    }

    /**
     * 自动统计（不手填）：成员数、成立天数、约战胜负与近 5 场、成员上传胜率的均值。
     * 战绩是成员自己登录上传的（见 stats-self-upload 口径），这里只是同一份数据的只读视图；
     * 没有样本就返回 null，前端显示空状态而不是 0%。
     */
    public static function stats(int $tid): array
    {
        $members = (int)Db::name('users')->where('tenant_id', $tid)->where('status', 'approved')->count();
        $since   = (string)Db::name('tenants')->where('id', $tid)->value('created_at');
        $days    = $since !== '' && $since !== null ? max(1, (int)floor((time() - strtotime($since)) / 86400)) : 0;

        // 分成两条查再在 PHP 侧合并：ThinkPHP 的 whereOr 是接在已有条件之后的，
        // 写成 status AND (挑战方) OR (防守方) 会让防守方那半漏掉 status 过滤，捞进未完赛的行
        $cols = 'id, result, finished_at, challenger_type, challenger_id, defender_type, defender_id';
        $battles = array_merge(
            Db::name('battles')->where('status', 'finished')
                ->where('challenger_type', 'tenant')->where('challenger_id', $tid)
                ->field($cols)->limit(50)->select()->toArray(),
            Db::name('battles')->where('status', 'finished')
                ->where('defender_type', 'tenant')->where('defender_id', $tid)
                ->field($cols)->limit(50)->select()->toArray()
        );
        $seen = [];
        foreach ($battles as $i => $b) {
            $bid = (int)$b['id'];
            if (isset($seen[$bid])) {
                unset($battles[$i]);
                continue;
            }
            $seen[$bid] = 1;
        }
        $battles = array_values($battles);
        usort($battles, function ($a, $b) {
            return strcmp((string)$b['finished_at'], (string)$a['finished_at']);
        });
        $battles = array_slice($battles, 0, 50);

        $win = $lose = $draw = 0;
        $recent = [];
        foreach ($battles as $b) {
            $side = BattleService::sideOfParty($b, 'tenant', $tid);
            $r    = (string)$b['result'];
            if ($side === null || $r === '') {
                continue;
            }
            $got = ($r === 'draw') ? 'd' : (($r === 'a_win') === ($side === 'a') ? 'w' : 'l');
            if ($got === 'w') {
                $win++;
            } elseif ($got === 'l') {
                $lose++;
            } else {
                $draw++;
            }
            if (count($recent) < 5) {
                $recent[] = $got;
            }
        }

        $ps = Db::name('player_stats')->where('tenant_id', $tid)
            ->field('win_rate, matches')->select()->toArray();
        $sum = 0.0;
        $n   = 0;
        foreach ($ps as $p) {
            if ($p['win_rate'] === null) {
                continue;
            }
            $sum += (float)$p['win_rate'];
            $n++;
        }

        return [
            'members'         => $members,
            'since_days'      => $days,
            'battles'         => $win + $lose + $draw,
            'wins'            => $win,
            'losses'          => $lose,
            'draws'           => $draw,
            'recent'          => $recent,
            'win_rate'        => $n > 0 ? round($sum / $n, 1) : null,
            'win_rate_sample' => $n,
        ];
    }

    /**
     * 成员风采：队长开了 members 模块 + 成员本人勾了 show_public 才出现。
     * ★ 公开页只给昵称与职责标签，昵称空的成员直接跳过——
     *   user_display_name() 没昵称时会退回脱敏 QQ，那也是 QQ 的一部分，互联网页面上不要出现。
     *   同理不给头像：现有头像代理走 q1.qlogo.cn 且 URL 里带 QQ 号，公开页放它等于把 QQ 写进 URL。
     */
    public static function members(int $tid): array
    {
        $cfg = self::config($tid);
        if ((int)$cfg['modules']['members'] !== 1) {
            return [];
        }
        $rows = Db::name('users')
            ->where('tenant_id', $tid)
            ->where('status', 'approved')
            ->where('show_public', 1)
            ->order('id', 'asc')
            ->field('id, nickname, role_tags')
            ->limit(60)->select()->toArray();
        $tags  = self::tagNameMap($tid);
        $out   = [];
        foreach ($rows as $r) {
            $nick = trim((string)$r['nickname']);
            if ($nick === '') {
                continue;
            }
            $ids  = json_decode((string)$r['role_tags'], true);
            $first = (is_array($ids) && $ids !== []) ? (int)reset($ids) : 0;
            $out[] = [
                'name'  => $nick,
                'tag'   => $tags[$first] ?? '',
                'color' => self::COLORS[((int)$r['id']) % count(self::COLORS)],
                'word'  => mb_substr($nick, 0, 1),
            ];
        }
        return $out;
    }

    /** 职责标签 id=>name（role_tags 表按租户隔离，跨租户读取不能走模型的全局作用域） */
    protected static function tagNameMap(int $tid): array
    {
        return Db::name('role_tags')->where('tenant_id', $tid)->column('name', 'id');
    }

    /** 战队动态 = 队长在展示页里勾选的几条公告（公告本身没有"对外"列，勾选关系存 settings） */
    public static function notices(int $tid): array
    {
        $cfg = self::config($tid);
        if ((int)$cfg['modules']['notices'] !== 1 || $cfg['notice_ids'] === []) {
            return [];
        }
        // v5.36.34：定时未到点的、被队长撤回的都不出对外页（队长挑过了也一样，读的时候才算生效）
        $rows = \app\model\Announcement::aliveOnly(Db::name('announcements'))
            ->where('tenant_id', $tid)
            ->where('game_side', '')          // v5.36.0：访客没有端别，分端公告不出现在对外展示页
            ->whereIn('id', $cfg['notice_ids'])
            ->field('id, title, content, image, created_at')
            ->select()->toArray();
        $byId = [];
        foreach ($rows as $r) {
            $byId[(int)$r['id']] = $r;
        }
        $out = [];
        foreach ($cfg['notice_ids'] as $id) {           // 按队长勾选的顺序出，不按数据库顺序
            if (!isset($byId[$id])) {
                continue;
            }
            $r     = $byId[$id];
            $out[] = [
                'title'      => (string)$r['title'],
                'content'    => mb_substr((string)$r['content'], 0, 160),
                'image'      => (string)$r['image'],
                'created_at' => (string)$r['created_at'],
            ];
        }
        return $out;
    }

    /**
     * 精选内容 = 队长勾选的改枪码/攻略 + 已采纳的内容投稿（投稿通过才上墙）。
     * 改枪码/攻略只取仍然 published 的行——作者后来下架了，展示页不能继续挂。
     */
    public static function contents(int $tid): array
    {
        $cfg = self::config($tid);
        if ((int)$cfg['modules']['contents'] !== 1) {
            return [];
        }
        $out = [];
        if ($cfg['guncode_ids'] !== []) {
            $rows = Db::name('gun_codes')->where('tenant_id', $tid)->where('status', 'published')
                ->whereIn('id', $cfg['guncode_ids'])->field('id, title, code, description')->select()->toArray();
            $byId = array_column($rows, null, 'id');
            foreach ($cfg['guncode_ids'] as $id) {
                if (isset($byId[$id])) {
                    $out[] = ['kind' => 'gun', 'title' => (string)$byId[$id]['title'],
                              'text' => (string)$byId[$id]['code'], 'image' => '', 'nick' => ''];
                }
            }
        }
        if ($cfg['guide_ids'] !== []) {
            $rows = Db::name('guides')->where('tenant_id', $tid)->where('status', 'published')
                ->whereIn('id', $cfg['guide_ids'])->field('id, title, content, image')->select()->toArray();
            $byId = array_column($rows, null, 'id');
            foreach ($cfg['guide_ids'] as $id) {
                if (isset($byId[$id])) {
                    $out[] = ['kind' => 'guide', 'title' => (string)$byId[$id]['title'],
                              'text' => mb_substr((string)$byId[$id]['content'], 0, 160),
                              'image' => (string)$byId[$id]['image'], 'nick' => ''];
                }
            }
        }
        $subs = Db::name('show_submissions')
            ->where('tenant_id', $tid)->where('type', 'post')->where('status', 'approved')
            ->order('reviewed_at', 'desc')->order('id', 'desc')
            ->field('nick, content, image')->limit(self::MAX_SELECT)->select()->toArray();
        foreach ($subs as $s) {
            $out[] = ['kind' => 'post', 'title' => '', 'text' => (string)$s['content'],
                      'image' => (string)$s['image'], 'nick' => (string)$s['nick']];
        }
        return $out;
    }

    /** 访客页整包（服务端直出用；不含任何 QQ / IP / 邮箱字段） */
    public static function pageData(int $tid): array
    {
        $cfg = self::config($tid);
        $t   = Db::name('tenants')->where('id', $tid)->field('id, slug, name, logo, description')->find();
        return [
            'team' => [
                'name'    => (string)($t['name'] ?? ''),
                'slug'    => (string)($t['slug'] ?? ''),
                'slogan'  => $cfg['slogan'] !== '' ? $cfg['slogan'] : (string)($t['description'] ?? ''),
                'logo'    => self::firstAlive([$cfg['logo'], (string)($t['logo'] ?? '')]),
                'banner'  => self::firstAlive([$cfg['banner']]),
                'contact' => $cfg['contact'],
            ],
            'stats'    => self::stats($tid),
            'modules'  => $cfg['modules'],
            'blocks'   => $cfg['blocks'],
            'gallery'  => self::items($tid, 'gallery'),
            'honor'    => self::items($tid, 'honor'),
            'positions'=> $cfg['positions'],
            'recruiting' => $cfg['recruiting'],
            'members'  => self::members($tid),
            'notices'  => self::notices($tid),
            'contents' => self::contents($tid),
            'updated'  => $cfg['updated_at'],
            'accent'   => self::accentTokens($cfg['accent']),
        ];
    }

    // ==================== 公示大厅（平台级） ====================

    /**
     * 大厅卡片：只取"已开启且未被平台下架"的站点。
     * 站点多时逐个算统计会退化成一堆子查询，所以卡片里只放便宜字段
     * （成员数/招募状态/口号/队徽），近5场与胜率这类要扫表的留给单队页。
     */
    public static function hallCards(string $kw = ''): array
    {
        $tids = [];
        $rows = Db::name('tenants')->where('status', 'approved')
            ->field('id, slug, name, logo, description')->select()->toArray();
        foreach ($rows as $t) {
            if (self::teamOpen((int)$t['id']) && !self::isForceOff((int)$t['id'])) {
                $tids[(int)$t['id']] = $t;
            }
        }
        if ($tids === []) {
            return [];
        }
        $counts = Db::name('users')->whereIn('tenant_id', array_keys($tids))->where('status', 'approved')
            ->group('tenant_id')->column('COUNT(*)', 'tenant_id');
        $out = [];
        foreach ($tids as $tid => $t) {
            $cfg = self::config($tid);
            if ($kw !== '' && mb_stripos($t['name'] . ' ' . $cfg['slogan'], $kw) === false) {
                continue;
            }
            $logo = self::firstAlive([$cfg['logo'], (string)($t['logo'] ?? '')]);
            $out[] = [
                'tid'        => $tid,
                'slug'       => (string)$t['slug'],
                'name'       => (string)$t['name'],
                // 队长没写口号时用入驻时填的战队简介兜底，卡片不至于空一块
                'slogan'     => $cfg['slogan'] !== '' ? $cfg['slogan'] : (string)($t['description'] ?? ''),
                'logo'       => $logo,
                'recruiting' => $cfg['recruiting'],
                'members'    => (int)($counts[$tid] ?? 0),
                'positions'  => count($cfg['positions']),
                'accent'     => self::accentTokens($cfg['accent']),
                'word'       => mb_substr((string)$t['name'], 0, 1),
                'color'      => self::COLORS[$tid % count(self::COLORS)],
            ];
        }
        // 招募中的排前面，其次成员数多的排前面——找队的人第一眼看这两项
        usort($out, function ($a, $b) {
            return [$b['recruiting'], $b['members']] <=> [$a['recruiting'], $a['members']];
        });
        return $out;
    }

    /**
     * 风云榜（大厅右下角那张小榜）：各队约战胜场 TOP5。
     * 只有两条聚合查询——按"作为挑战方赢"和"作为防守方赢"各数一次再合并；
     * 没有独立的赛事名次数据（赛事在外部站点），所以这里只敢写"胜场"，不写"冠军"。
     */
    public static function winBoard(int $limit = 5): array
    {
        $wins = [];
        $a = Db::name('battles')->where('status', 'finished')->where('result', 'a_win')
            ->where('challenger_type', 'tenant')->group('challenger_id')->column('COUNT(*)', 'challenger_id');
        $b = Db::name('battles')->where('status', 'finished')->where('result', 'b_win')
            ->where('defender_type', 'tenant')->group('defender_id')->column('COUNT(*)', 'defender_id');
        foreach ([$a, $b] as $rows) {
            foreach ($rows as $tid => $n) {
                $tid = (int)$tid;
                if ($tid > 0) {
                    $wins[$tid] = ($wins[$tid] ?? 0) + (int)$n;
                }
            }
        }
        arsort($wins);
        $out  = [];
        $names = Db::name('tenants')->whereIn('id', array_slice(array_keys($wins), 0, $limit))->column('name, slug', 'id');
        foreach (array_slice($wins, 0, $limit, true) as $tid => $n) {
            if (!isset($names[$tid]) || !self::visible($tid)) {
                continue;   // 未开启或被下架的站点不上榜（榜单也是公开页）
            }
            $out[] = ['tid' => $tid, 'name' => (string)$names[$tid]['name'], 'wins' => $n];
        }
        return $out;
    }

    /** 平台数据条（大厅 Hero）；$shown = 已开启展示页的站点数，由调用方拿 hallCards() 的个数传进来 */
    public static function hallTotals(int $shown = 0): array
    {
        $teams = (int)Db::name('tenants')->where('status', 'approved')->count();
        return [
            'teams'    => $teams,
            'members'  => (int)Db::name('users')->where('status', 'approved')->count(),
            'shown'    => $shown,
            'battles'  => (int)Db::name('battles')->where('status', 'finished')->count(),
        ];
    }

    // ==================== 投稿与意向 ====================

    /**
     * 图形验证码：4 位字符，Cache 存 10 分钟，校验一次即作废（防拿同一张图枚举）。
     * 键由调用方生成并回传给页面，不走 cookie——展示页是公开页，不想给它发会话。
     */
    public static function captchaIssue(): array
    {
        $alphabet = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';   // 去掉易混的 0O1I
        $code     = '';
        for ($i = 0; $i < 4; $i++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        $key = bin2hex(random_bytes(8));
        Cache::set('show_cap_' . $key, $code, 600);
        return [$key, $code];
    }

    public static function captchaCheck(string $key, string $code): bool
    {
        if (!preg_match('/^[0-9a-f]{16}$/', $key) || $code === '') {
            return false;
        }
        // 出图走的是另一份缓存（show_capimg_），这里只认答案：
        // 答错不作废这张码——公开页没有"刷新验证码"以外的重试通道，
        // 一次失败就把码烧掉会让人只能整页重刷；连错 8 次由调用方按 IP 限住
        $want = (string)Cache::get('show_cap_' . $key, '');
        if ($want === '') {
            return false;
        }
        if (strtoupper(trim($code)) !== strtoupper($want)) {
            return false;
        }
        Cache::delete('show_cap_' . $key);
        return true;
    }

    /**
     * 访客提交前的三道闸：IP 封禁 → 每小时 3 次 → 每天 10 次。
     * 与注册限流同口径（同 IP 每小时 3 次），因为这就是同一类攻击面：匿名写库。
     * @return string|null null=放行
     */
    public static function gateSubmit(int $tid, string $ip): ?string
    {
        if (Db::name('blocked_ips')->where('ip', $ip)->find()) {
            return '当前 IP 不可提交，请联系管理员';
        }
        $hour = rate_bump('show_sub_h_' . md5($tid . ':' . $ip), 3600);
        if ($hour > 3) {
            return '提交过于频繁，请稍后再试';
        }
        $day = rate_bump('show_sub_d_' . md5($tid . ':' . $ip), 86400);
        if ($day > 10) {
            return '今天这个网络的提交次数已达上限，请明天再来';
        }
        return null;
    }

    /** 保留上限裁剪（投稿+意向合计，超出的最旧已处理条目先删；未处理的不动，避免误吞待审内容） */
    public static function trimSubmissions(int $tid): void
    {
        try {
            $total = (int)Db::name('show_submissions')->where('tenant_id', $tid)->count();
            if ($total <= self::KEEP_SUBS) {
                return;
            }
            $drain = $total - self::KEEP_SUBS;
            $rows  = Db::name('show_submissions')->where('tenant_id', $tid)
                ->where('status', '<>', 'pending')
                ->order('reviewed_at', 'asc')->limit($drain)
                ->field('id, image')->select()->toArray();
            foreach ($rows as $r) {
                if ((string)$r['image'] !== '') {
                    ImageUpload::delete((string)$r['image']);
                }
                Db::name('show_submissions')->where('id', (int)$r['id'])->delete();
            }
        } catch (\Throwable $e) {
            // 裁剪失败不影响提交主流程
        }
    }
}
