<?php
declare(strict_types=1);

namespace app\controller;

use app\service\GameSide;
use app\service\PlayerStats;
use app\service\TenantContext;
use think\facade\Db;

/**
 * 战绩信息（v5.11.0，stats 权限）
 *
 * 管理中心「战绩信息」页签的数据源：全战队成员 + 各自经接口上传的 20 项指标。
 * 一人一行、只留最新一份，所以这里只做只读列表 + 任意指标列排序，管理员不能改删。
 * 「哪些人还没上传」由 filter=missing 单独列出，头部同时给出三类人数统计。
 */
class StatsAdmin extends AdminBase
{
    private function noPerm()
    {
        return api_err('您没有权限执行此操作，请联系超级管理员分配权限', 403, 403);
    }

    public function index()
    {
        if (!admin_can('stats')) {
            return $this->noPerm();
        }
        $tid  = TenantContext::id();
        $page = max(1, (int)$this->request->get('page', 1));
        $size = min(100, max(1, (int)$this->request->get('size', 20)));
        $kw   = trim((string)$this->request->get('keyword', ''));
        $cond = trim((string)$this->request->get('filter', 'all'));   // all/uploaded/missing

        $query = $this->baseQuery($tid, $kw, $cond);
        $total = (clone $query)->count();
        $this->applySort($query);

        $rows = $query->field($this->selectFields())
            ->page($page, $size)
            ->select()
            ->toArray();

        // QQ 脱敏沿用全站约定：无 qq_full 权限的管理员看到的是打码号
        $canFullQq = admin_can('qq_full');
        $list = [];
        foreach ($rows as $r) {
            $list[] = [
                'user_id'   => (int)$r['id'],
                'nickname'  => (string)($r['nickname'] ?: '未设置昵称'),
                'username'  => $canFullQq ? (string)$r['username'] : mask_qq((string)$r['username']),
                'team_id'   => (int)$r['team_id'],
                'role'      => (string)$r['role'],
                'game_side' => (string)($r['game_side'] ?? ''),
                'game_side_label' => GameSide::sideLabel((string)($r['game_side'] ?? '')),
                'game_pid'  => (string)($r['game_pid'] ?? ''),
                'uploaded_at' => $r['uploaded_at'] ?? null,
                'has_stats' => ($r['stat_id'] ?? null) !== null,
                'stats'     => PlayerStats::metrics($r),
            ];
        }

        return api_ok([
            'list'   => $list,
            'total'  => $total,
            'page'   => $page,
            'size'   => $size,
            'fields' => PlayerStats::fields(),
            'counts' => $this->counts($tid),
            'sort'   => $this->sortField(),
            'order'  => $this->sortDir(),
            'me_id'  => (int)current_user()->id,
        ]);
    }

    // ---------- 全队排行（v5.36.0，stats 权限） ----------

    /**
     * 管理端排名：与成员页同一份算法，区别只在于这里不受「战绩公开」限制（管理员本来就有 stats 权限），
     * 并可按端游 / 手游 / 全部筛。只列出上传过战绩的人。
     */
    public function ranking()
    {
        if (!admin_can('stats')) {
            return $this->noPerm();
        }
        $metric = (string)$this->request->get('metric', 'win_rate');
        if (!isset(\app\controller\Stats::RANK_METRICS[$metric])) {
            $metric = 'win_rate';
        }
        $split = GameSide::on();
        $scope = strtolower(trim((string)$this->request->get('game_side', 'all')));
        if (!in_array($scope, ['all', 'pc', 'mobile'], true)) {
            $scope = 'all';
        }
        if (!$split) {
            $scope = 'all';
        }
        $tid = TenantContext::id();

        $q = Db::name('users')->alias('u')
            ->join('player_stats p', 'p.user_id = u.id AND p.tenant_id = u.tenant_id')
            ->where('u.tenant_id', $tid)
            ->where('u.status', 'approved')
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
                'stats_public'    => (int)$r['stats_public'],
                'matches'         => $r['matches'] === null ? null : (int)$r['matches'],
                'win_rate'        => $r['win_rate'] === null ? null : (float)$r['win_rate'],
                'avg_score'       => $r['avg_score'] === null ? null : (float)$r['avg_score'],
                'kills_per_match' => $r['kills_per_match'] === null ? null : (float)$r['kills_per_match'],
                'updated_at'      => $r['updated_at'],
            ];
        }
        return api_ok([
            'list'         => $list,
            'total'        => count($list),
            'metric'       => $metric,
            'metric_label' => \app\controller\Stats::RANK_METRICS[$metric],
            'metrics'      => \app\controller\Stats::RANK_METRICS,
            'scope'        => $scope,
            'split'        => $split,
            'counts'       => $this->sideCounts($tid),
        ]);
    }

    /** 已上传战绩的人按端别分一下（排行页的筛选条用） */
    private function sideCounts(int $tid): array
    {
        $rows = Db::name('users')->alias('u')
            ->join('player_stats p', 'p.user_id = u.id AND p.tenant_id = u.tenant_id')
            ->where('u.tenant_id', $tid)->where('u.status', 'approved')->whereNotNull('p.id')
            ->field("u.game_side, COUNT(*) AS n")->group('u.game_side')->select()->toArray();
        $out = ['all' => 0, 'pc' => 0, 'mobile' => 0, 'none' => 0];
        foreach ($rows as $r) {
            $n = (int)$r['n'];
            $out['all'] += $n;
            $s = (string)$r['game_side'];
            $out[in_array($s, ['pc', 'mobile'], true) ? $s : 'none'] += $n;
        }
        return $out;
    }

    // ---------- 内部工具 ----------

    /** 成员表左连战绩快照：未同步的成员也要出现在列表里（has_stats=false），便于催更 */
    private function baseQuery(int $tid, string $kw, string $cond)
    {
        $query = Db::name('users')->alias('u')
            ->leftJoin('player_stats p', 'p.user_id = u.id AND p.tenant_id = u.tenant_id')
            ->where('u.tenant_id', $tid)
            ->where('u.status', 'approved');

        if ($cond === 'uploaded') {
            $query->whereNotNull('p.id');
        } elseif ($cond === 'missing') {
            $query->whereNull('p.id');
        }
        if ($kw !== '') {
            $query->where(function ($q) use ($kw) {
                $q->whereLike('u.nickname', '%' . $kw . '%')
                    ->whereOr('u.username', 'like', '%' . $kw . '%')
                    ->whereOr('p.game_pid', 'like', '%' . $kw . '%');
            });
        }
        return $query;
    }

    /** 成员总数 / 已同步 / 未同步（页签顶部一眼看清覆盖情况） */
    private function counts(int $tid): array
    {
        $total = (int)Db::name('users')->where('tenant_id', $tid)->where('status', 'approved')->count();
        $uploaded = (int)Db::name('player_stats')->alias('p')
            ->join('users u', 'u.id = p.user_id AND u.tenant_id = p.tenant_id')
            ->where('p.tenant_id', $tid)->where('u.status', 'approved')->count();
        return [
            'total'    => $total,
            'uploaded' => $uploaded,
            'missing'  => max(0, $total - $uploaded),
        ];
    }

    /**
     * 排序列白名单：20 个指标列 + 昵称 + 上传时间，其余一律回落默认（场次降序）。
     * 「未同步」的行取值为 NULL，用 col IS NULL 前置排序键压到列表末尾。
     */
    private function applySort($query): void
    {
        $sort  = $this->sortField();
        $order = $this->sortDir();
        if ($sort === 'nickname') {
            $query->orderRaw('u.nickname ' . $order);
        } elseif ($sort === 'uploaded_at') {
            $query->orderRaw('p.updated_at IS NULL, p.updated_at ' . $order);
        } else {
            $query->orderRaw('p.' . $sort . ' IS NULL, p.' . $sort . ' ' . $order);
        }
        $query->orderRaw('u.id ASC');   // 同值时的稳定次序，翻页才不会重复/漏行
    }

    private function sortField(): string
    {
        $sort = trim((string)$this->request->get('sort', 'matches'));
        if ($sort === 'nickname' || $sort === 'uploaded_at' || isset(player_stat_defs()[$sort])) {
            return $sort;
        }
        return 'matches';
    }

    private function sortDir(): string
    {
        return strtolower((string)$this->request->get('order', 'desc')) === 'asc' ? 'asc' : 'desc';
    }

    private function selectFields(): string
    {
        $cols = ['u.id', 'u.username', 'u.nickname', 'u.team_id', 'u.role', 'u.game_side',
            'p.id AS stat_id', 'p.game_pid', 'p.updated_at AS uploaded_at'];
        foreach (array_keys(player_stat_defs()) as $c) {
            $cols[] = 'p.' . $c;
        }
        return implode(', ', $cols);
    }
}
