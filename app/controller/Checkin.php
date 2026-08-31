<?php
declare (strict_types = 1);

namespace app\controller;

use app\service\TenantContext;
use think\facade\Cache;
use think\facade\Db;

/**
 * 每日签到（v5.3.0）
 * - 每人每天一条（唯一键兜底并发重复提交）
 * - 连续天数：昨天有签到则 +1，断签归 1
 * - 今日运势：签到时随机一张表情包（/static/meme/N.jpg），当天固定展示这张
 * - 每日一言：服务器每自然日只调一次外部接口，结果按日期缓存全站统一
 */
class Checkin extends AuthBase
{
    // 签到状态（仪表盘签到卡片加载时调用）
    public function index()
    {
        $me    = current_user();
        $tid   = TenantContext::id();
        $today = date('Y-m-d');

        $mine = Db::name('checkins')
            ->where('tenant_id', $tid)->where('user_id', $me->id)
            ->where('checkin_date', $today)->find();

        // 未签到时展示"昨天累计的连续天数"（今天签了就续上）
        $streak = 0;
        if ($mine) {
            $streak = (int)$mine['streak'];
        } else {
            $yest = Db::name('checkins')
                ->where('tenant_id', $tid)->where('user_id', $me->id)
                ->where('checkin_date', date('Y-m-d', strtotime('-1 day')))
                ->value('streak');
            $streak = (int)$yest;
        }

        // 全站（本租户）今日签到人数
        $siteCount = (int)Db::name('checkins')
            ->where('tenant_id', $tid)->where('checkin_date', $today)->count();

        // 本队今日最先签到前 3 名
        $teamTop = [];
        if ($me->team_id) {
            $teamTop = Db::name('checkins')->alias('c')
                ->leftJoin('users u', 'u.id = c.user_id')
                ->where('c.tenant_id', $tid)
                ->where('c.checkin_date', $today)
                ->where('u.team_id', $me->team_id)
                ->field('c.user_id, c.created_at, COALESCE(u.nickname, "已注销用户") AS nickname')
                ->order('c.id', 'asc')
                ->limit(3)
                ->select()->toArray();
        }

        return api_ok([
            'checked'     => (bool)$mine,
            'streak'      => $streak,
            'site_count'  => $siteCount,
            'team_top'    => $teamTop,
            'saying'      => self::dailySaying(),
            'fortune_img' => $mine ? self::memeUrl((int)$mine['fortune_img']) : '',
        ]);
    }

    // 执行签到
    public function checkin()
    {
        $me    = current_user();
        $tid   = TenantContext::id();
        $today = date('Y-m-d');

        $exists = Db::name('checkins')
            ->where('tenant_id', $tid)->where('user_id', $me->id)
            ->where('checkin_date', $today)->find();
        if ($exists) {
            return api_err('今天已经签到过啦');
        }

        // 连续天数：昨天签过就续上，否则从 1 开始
        $yestStreak = (int)Db::name('checkins')
            ->where('tenant_id', $tid)->where('user_id', $me->id)
            ->where('checkin_date', date('Y-m-d', strtotime('-1 day')))
            ->value('streak');
        $streak = $yestStreak > 0 ? $yestStreak + 1 : 1;

        // 今日运势：随机一张表情包（当天再打开固定显示这张）
        $imgNo = random_int(1, self::memeCount());

        try {
            Db::name('checkins')->insert([
                'tenant_id'    => $tid,
                'user_id'      => $me->id,
                'checkin_date' => $today,
                'streak'       => $streak,
                'fortune_img'  => $imgNo,
                'created_at'   => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            // 唯一键冲突 = 并发重复提交（同一秒点了两下）
            return api_err('今天已经签到过啦');
        }

        return api_ok([
            'streak'      => $streak,
            'fortune_img' => self::memeUrl($imgNo),
        ], '签到成功');
    }

    // ---------- 每日一言：服务器当天只调取一次，全站统一 ----------

    public static function dailySaying(): string
    {
        $key = 'saying_' . date('Ymd');
        $hit = Cache::get($key);
        if (is_string($hit) && $hit !== '') {
            return $hit;
        }

        $text = self::fetchSaying();
        if ($text !== '') {
            // 成功才长效缓存（当天不再请求外部接口）
            Cache::set($key, $text, 86400 * 2);
            return $text;
        }
        // 失败短缓存：10 分钟内不重复请求，过后自动重试；当日始终有兜底文案
        Cache::set($key . '_cooldown', '1', 600);
        return self::fallbackSaying();
    }

    // 拉取外部一言接口（5 秒超时；只认成功结果，任何异常都当失败）
    private static function fetchSaying(): string
    {
        if (Cache::get('saying_' . date('Ymd') . '_cooldown')) {
            return '';
        }
        try {
            $ctx = stream_context_create(['http' => [
                'timeout'         => 5,
                'ignore_errors'   => true,
                'header'          => "User-Agent: game-team-system\r\nAccept: application/json\r\n",
            ]]);
            $body = @file_get_contents('https://uapis.cn/api/v1/saying', false, $ctx);
            if ($body === false || trim($body) === '') {
                return '';
            }
            $json = json_decode($body, true);
            if (is_array($json)) {
                foreach (['text', 'saying', 'content', 'message', 'result'] as $k) {
                    if (isset($json[$k]) && is_string($json[$k]) && trim($json[$k]) !== '') {
                        return mb_substr(trim($json[$k]), 0, 200);
                    }
                }
                if (isset($json['data']) && is_string($json['data']) && trim($json['data']) !== '') {
                    return mb_substr(trim($json['data']), 0, 200);
                }
            }
            // 非 JSON 时也接受纯文本响应
            $plain = trim(strip_tags($body));
            return $plain !== '' ? mb_substr($plain, 0, 200) : '';
        } catch (\Throwable $e) {
            return '';
        }
    }

    // 外部接口不可用时的兜底一言（保证页面不空）
    private static function fallbackSaying(): string
    {
        $pool = [
            '今天也要元气满满地开黑呀！',
            '枪法练起来，车队等你带飞。',
            '稳住，我们能赢！',
            '胜者为王，祝你今天好运。',
        ];
        return $pool[(int)date('j') % count($pool)];
    }

    // ---------- 表情包 ----------

    // 表情包张数（扫描 /static/meme/ 下连号 jpg，不写死）
    public static function memeCount(): int
    {
        static $count = null;
        if ($count === null) {
            $count = 0;
            $dir   = public_path() . 'static' . DIRECTORY_SEPARATOR . 'meme';
            if (is_dir($dir)) {
                for ($i = 1; $i <= 100; $i++) {
                    if (is_file($dir . DIRECTORY_SEPARATOR . $i . '.jpg')) {
                        $count = $i;
                    } else {
                        break;
                    }
                }
            }
            if ($count === 0) {
                $count = 1;   // 目录缺失时兜底，避免 random_int 报错
            }
        }
        return $count;
    }

    public static function memeUrl(int $no): string
    {
        return $no > 0 ? '/static/meme/' . $no . '.jpg' : '';
    }
}
