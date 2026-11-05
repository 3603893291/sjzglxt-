<?php
declare (strict_types = 1);

namespace app\controller;

use app\BaseController;
use think\facade\Db;

/**
 * 站点活跃（v5.35.1，平台侧）：哪个战队还在真的用这个站点
 *
 *   GET /api/platform/admin/active
 *
 * 口径是他拍的：**按「有人在用」算**，不看内容产出；今日 / 7 天 / 30 天三个数都给；
 * **只看每队的数字，不展开成员昵称**（跨队人名不上平台这一页）。
 *
 * 数据只有一个源：`users.last_active_at`（AuthCheck 在每次带登录态的请求上顺手写一次）。
 * 所以这一页回答的是"这队最近还有没有人在连"，不是"这队发了多少帖"。
 * 边界也在这儿：页面/客户端的自动轮询同样会刷新它，所以它是"有人在连"而不是"有人在点"；
 * 逐日历史没有存（只保留最后一次），所以**不提供趋势图**，别在这里画一条假的。
 *
 * 零 DDL：不建表、不加列、不写 settings —— 全是现成列上的聚合。
 */
class PlatformActive extends BaseController
{
    public function index()
    {
        if ($r = $this->superOnly()) {
            return $r;
        }

        $todayFrom = date('Y-m-d 00:00:00');
        $d7From    = date('Y-m-d H:i:s', time() - 7 * 86400);
        $d30From   = date('Y-m-d H:i:s', time() - 30 * 86400);
        $now       = time();

        $tenants = Db::name('tenants')->where('status', 'approved')
            ->field('id, slug, name, created_at')->order('id', 'asc')->select()->toArray();

        // 每队的总人数与最近一次活跃：一次分组查询，不按队循环打 N 次库
        $base = [];
        $rows = Db::name('users')->where('status', 'approved')
            ->field('tenant_id, COUNT(*) AS n, MAX(last_active_at) AS last_at')
            ->group('tenant_id')->select()->toArray();
        foreach ($rows as $r) {
            $base[(int)$r['tenant_id']] = [
                'members' => (int)$r['n'],
                'last_at' => (string)($r['last_at'] ?? ''),
            ];
        }

        // 三个时间窗各数一遍「有多少人在这段时间里活跃过」
        $win = [];
        foreach (['today' => $todayFrom, 'd7' => $d7From, 'd30' => $d30From] as $k => $from) {
            $win[$k] = [];
            $rs = Db::name('users')->where('status', 'approved')
                ->where('last_active_at', '>=', $from)
                ->field('tenant_id, COUNT(*) AS n')->group('tenant_id')->select()->toArray();
            foreach ($rs as $r) {
                $win[$k][(int)$r['tenant_id']] = (int)$r['n'];
            }
        }

        $list = [];
        foreach ($tenants as $t) {
            $tid  = (int)$t['id'];
            $last = (string)($base[$tid]['last_at'] ?? '');
            $idle = null;
            if ($last !== '') {
                $idle = max(0, (int)floor(($now - strtotime($last)) / 86400));
            }
            $list[] = [
                'id'       => $tid,
                'slug'     => (string)$t['slug'],
                'name'     => (string)$t['name'],
                'members'  => (int)($base[$tid]['members'] ?? 0),
                'today'    => (int)($win['today'][$tid] ?? 0),
                'd7'       => (int)($win['d7'][$tid] ?? 0),
                'd30'      => (int)($win['d30'][$tid] ?? 0),
                'last_at'  => $last,
                'idle_days' => $idle,
            ];
        }

        // 活跃的排前面：出事时要一眼看到"还有谁在"，而不是从列表第一行开始找
        usort($list, static function ($a, $b) {
            return [$b['today'], $b['d7'], $b['d30']] <=> [$a['today'], $a['d7'], $a['d30']]
                ?: strcmp((string)$b['last_at'], (string)$a['last_at'])
                ?: ($a['id'] <=> $b['id']);
        });

        $live = ['today' => 0, 'd7' => 0, 'd30' => 0];
        foreach ($list as $r) {
            foreach (['today', 'd7', 'd30'] as $k) {
                if ($r[$k] > 0) { $live[$k]++; }
            }
        }

        return api_ok([
            'sites'   => count($list),
            'live'    => $live,
            'list'    => $list,
            'as_of'   => date('Y-m-d H:i:s'),
            'note'    => '按账号最后一次带登录态请求的时间算，所以是「还有没有人在连」而不是「发了多少东西」；'
                . '页面与客户端的自动轮询也会刷新它。这里只给每队的数字，不列成员昵称。',
        ]);
    }

    /** @return \think\Response|null */
    private function superOnly()
    {
        if (!is_platform_super()) {
            return api_err('需要平台超级管理员登录态', 403, 403);
        }
        return null;
    }
}
