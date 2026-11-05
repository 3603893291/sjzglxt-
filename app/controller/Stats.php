<?php
declare(strict_types=1);

namespace app\controller;

use app\model\PlayerStat;
use app\service\GameSide;
use app\service\PlayerStats;
use app\service\Setting;
use app\service\TenantContext;
use think\facade\Cache;
use think\facade\Db;

/**
 * 我的战绩（v5.11.0）
 *
 * 成员用自己的账号密码登录后，把自己在游戏里的战绩上传进来——
 * 一个账号一行，重复上传整行覆盖，永远只有最新一份。
 * 写入目标恒取 current_user()，请求体里没有 user_id 这种东西，传了也不认，
 * 所以任何人都只能写到自己名下。
 *
 * 可见性：仅本人与持 stats 权限的管理员（StatsAdmin 只读列表）。
 * 限频：参数由入驻平台「SEO与合规 → 限频 / 压缩」面板统一下发（settings tenant_id=0），
 *      所有战队继承同一套配置，平台可一键关停写入。
 */
class Stats extends AuthBase
{
    /** 平台配置缺失时的兜底值，与 Platform::apiRateConfig 的默认保持一致 */
    protected const DEFAULTS = [
        'stats_upload_window'     => 60,
        'stats_upload_limit'      => 10,
        'stats_ip_upload_limit'   => 60,
    ];

    public function my()
    {
        $me  = current_user();
        // PlayerStat 挂 BelongsToTenant，查询自动限定当前战队，取不到别人的行
        $row = PlayerStat::where('user_id', $me->id)->find();

        return api_ok([
            'fields'      => PlayerStats::fields(),
            'stat'        => $row ? PlayerStats::metrics($row) : null,
            'game_pid'    => $row ? (string)$row->game_pid : '',
            'uploaded_at' => $row ? (string)$row->updated_at : null,
            'first_at'    => $row ? (string)$row->created_at : null,
        ]);
    }

    /** 排行的四个指标（前端页签与排序白名单同一份） */
    public const RANK_METRICS = [
        'win_rate'        => '胜率',
        'avg_score'       => '场均评分',
        'kills_per_match' => '场均击杀',
        'matches'         => '场次',
    ];

    // ---------- 全队排行（v5.36.0） ----------

    /**
     * 成员能看的全队排名：只算上传过战绩、且本人「战绩公开」开着的已审核成员。
     * 开了端手分离时按专区筛：不分类的人跟着队走；被按端/按人锁住的成员只能看本端 + 不分类。
     * 指标四选一：胜率 / 场均评分 / 场均击杀 / 场次；同值先看场次，再看 id，翻页与截图都稳定。
     */
    public function ranking()
    {
        $me = current_user();
        if (!$me) {
            return api_err('未登录', 401, 401);
        }
        $metric = (string)$this->request->get('metric', 'win_rate');
        if (!isset(self::RANK_METRICS[$metric])) {
            $metric = 'win_rate';
        }
        [$scope, $split] = $this->rankScope($me);

        $q = Db::name('users')->alias('u')
            ->join('player_stats p', 'p.user_id = u.id AND p.tenant_id = u.tenant_id')
            ->where('u.tenant_id', TenantContext::id())
            ->where('u.status', 'approved')
            ->where('u.stats_public', 1)
            ->whereNotNull('p.id');
        if ($scope !== 'all') {
            $q->whereIn('u.game_side', [$scope, '']);
        }
        $order = $metric === 'matches' ? 'p.matches' : 'p.' . $metric;
        $rows = $q->field('u.id, u.nickname, u.game_side, u.stats_public, p.matches, p.win_rate, p.avg_score, p.kills_per_match, p.updated_at')
            ->orderRaw($order . ' IS NULL, ' . $order . ' DESC, p.matches DESC, u.id ASC')
            ->limit(200)
            ->select()->toArray();

        $list = [];
        foreach ($rows as $i => $r) {
            $list[] = [
                'rank'            => $i + 1,
                'user_id'         => (int)$r['id'],
                'nickname'        => (string)($r['nickname'] ?: '未设置昵称'),
                'game_side'       => (string)$r['game_side'],
                'game_side_label' => GameSide::sideLabel((string)$r['game_side']),
                'matches'         => $r['matches'] === null ? null : (int)$r['matches'],
                'win_rate'        => $r['win_rate'] === null ? null : (float)$r['win_rate'],
                'avg_score'       => $r['avg_score'] === null ? null : (float)$r['avg_score'],
                'kills_per_match' => $r['kills_per_match'] === null ? null : (float)$r['kills_per_match'],
                'updated_at'      => $r['updated_at'],
                'is_me'           => (int)$r['id'] === (int)$me->id,
            ];
        }
        return api_ok([
            'list'         => $list,
            'total'        => count($list),
            'metric'       => $metric,
            'metric_label' => self::RANK_METRICS[$metric],
            'metrics'      => self::RANK_METRICS,
            'scope'        => $scope,
            'split'        => $split,
            'allowed'      => GameSide::allowed($me),
            'note'         => '只统计上传过战绩、且本人开着「战绩公开」的已审核成员；未上传的人不占名次。',
        ]);
    }

    /** 排名页能看哪个范围：没开分端=全队；开了=被锁住的人只能看本端 + 不分类 */
    private function rankScope($me): array
    {
        if (!GameSide::on()) {
            return ['all', false];
        }
        $allowed = GameSide::allowed($me);
        $req = strtolower(trim((string)$this->request->get('game_side', '')));
        if ($req === 'all') {
            return in_array('pc', $allowed, true) && in_array('mobile', $allowed, true) ? ['all', true] : [$allowed[0], true];
        }
        if (in_array($req, ['pc', 'mobile'], true) && in_array($req, $allowed, true)) {
            return [$req, true];
        }
        return count($allowed) === 2 ? ['all', true] : [$allowed[0], true];
    }

    /**
     * 上传（覆盖）本人战绩
     * 入参：20 项指标平铺在请求体里，外加可选 game_pid
     */
    public function upload()
    {
        $me = current_user();

        if ($limitMsg = $this->rateGuard($me->id)) {
            return api_err($limitMsg, 429, 429);
        }

        $input = $this->request->post();
        [$values, $errs] = PlayerStats::parse($input);
        if ($errs) {
            return api_err(implode('；', $errs));
        }
        // 整行覆盖意味着空提交会清空全部指标，这里挡掉误操作（真要把某项置空，单项传 null 即可）
        if (count(array_filter($values, fn($v) => $v !== null)) === 0) {
            return api_err('请至少提交一项战绩指标');
        }

        $pid = mb_substr(trim((string)($input['game_pid'] ?? '')), 0, 64);
        if ($pid !== '') {
            $values['game_pid'] = $pid;
        }
        // 显式盖上传时间：ORM 在「所有字段值都没变」时会跳过 UPDATE，而每天推同一份数据很常见。
        // 秒级足够——比较在 PHP 侧完成，跨秒必然产生变化；同一秒内的重复推送显示同一时间本来就对。
        // 不写微秒是为了不把「DATETIME 列能不能收 .123456」留到线上去验证，返回格式也与文档一致。
        $values['updated_at'] = date('Y-m-d H:i:s');

        $row = PlayerStat::where('user_id', $me->id)->find();
        if ($row) {
            $row->save($values);
            $action = 'updated';
        } else {
            // tenant_id 由 BelongsToTenant 自动补当前战队
            try {
                // created_at 也显式给秒级值：think-orm 的自动时间戳带微秒，
                // 落到 DATETIME 列上 dev 与生产会显示成两种精度
                PlayerStat::create(array_merge($values, [
                    'user_id'    => $me->id,
                    'created_at' => date('Y-m-d H:i:s'),
                ]));
                $action = 'created';
            } catch (\Throwable $e) {
                // 同一账号首次并发上传会撞 uk_tenant_user（一人一行的唯一键）。
                // 表不存在之类的真故障在下面的 find() 里同样会抛出，不会被这里吞掉。
                $again = PlayerStat::where('user_id', $me->id)->find();
                if (!$again) {
                    throw $e;
                }
                $again->save($values);
                $action = 'updated';
            }
        }

        $fresh = PlayerStat::where('user_id', $me->id)->find();
        return api_ok([
            'action'      => $action,
            'user_id'     => (int)$me->id,
            'nickname'    => (string)($me->nickname ?: $me->username),
            'game_pid'    => (string)$fresh->game_pid,
            'uploaded_at' => (string)$fresh->updated_at,
            'stat'        => PlayerStats::metrics($fresh),
        ], $action === 'created' ? '战绩上传成功' : '战绩已更新');
    }

    // ---------- 限频 ----------

    /**
     * 账号 + 来源 IP 两个维度各计一次数（与 ApiKeyAuth 的双层限频同一思路）。
     * 每次调用即视为一次尝试并累加，参数取平台 settings，改完下次请求即生效。
     * @return null|string 命中限制时返回给成员的中文提示
     */
    private function rateGuard($uid): ?string
    {
        if (Setting::getPlatform('stats_api_enabled', '1') === '0') {
            return '战绩上传功能已由入驻平台关闭，请稍后再试';
        }
        $window = $this->conf('stats_upload_window');
        $rules  = [
            ['stats_up_u_' . (int)$uid, 'stats_upload_limit', '该账号'],
        ];
        // IP 维度只在拿得到可信来源时启用：若解析出的「客户端 IP」本身就落在可信代理段里
        // （CDN 没回传 X-Forwarded-For 的退化情形），全站成员会被算成同一个 IP，
        // 这时按 IP 计数等于把自己人集体 429，所以退化为只按账号限。
        $ip = client_ip();
        if ($ip !== '0.0.0.0' && !ip_in_trusted($ip, trusted_proxies())) {
            $rules[] = ['stats_up_ip_' . md5($ip), 'stats_ip_upload_limit', '当前网络'];
        }
        foreach ($rules as [$key, $setKey, $label]) {
            $limit = $this->conf($setKey);
            if ((int)Cache::get($key, 0) >= $limit) {
                return $label . '上传过于频繁：' . $window . ' 秒内最多 ' . $limit . ' 次，请稍后再试';
            }
        }
        foreach ($rules as [$key]) {
            Cache::set($key, (int)Cache::get($key, 0) + 1, $window);
        }
        return null;
    }

    /** 读平台限频参数（非正数一律回落默认，防被填成 0 后彻底封死接口） */
    private function conf(string $key): int
    {
        $def = self::DEFAULTS[$key];
        $v   = (int)Setting::getPlatform($key, (string)$def);
        return $v > 0 ? $v : $def;
    }
}
