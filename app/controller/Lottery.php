<?php
declare(strict_types=1);

namespace app\controller;

use app\service\TenantContext;
use think\facade\Db;

/**
 * 抽奖大转盘·成员端（v5.7.0）
 *
 * 单转盘两级抽取：先按奖池权重抽中奖池，再按池内奖品权重抽出具体奖励；
 * 机会由管理员发放（LotteryAdmin::grant），成员仅可查看与消耗。
 * 概率模型为权重制：前端展示的实际概率 = 权重 / 同级权重和（两级相乘）。
 */
class Lottery extends AuthBase
{
    protected function tenantId(): int
    {
        return TenantContext::id();
    }

    /** 按权重随机抽取一行（权重和须 > 0，调用方保证） */
    public static function weightedPick(array $rows, string $field = 'weight'): ?array
    {
        $sum = 0;
        foreach ($rows as $r) {
            $sum += max(0, (int)$r[$field]);
        }
        if ($sum <= 0) {
            return null;
        }
        $roll = random_int(1, $sum);
        foreach ($rows as $r) {
            $roll -= max(0, (int)$r[$field]);
            if ($roll <= 0) {
                return $r;
            }
        }
        return $rows[count($rows) - 1];
    }

    /** 成员端首页：我的机会 + 开启奖池（含奖品与实际概率） + 我的最近记录 */
    public function index()
    {
        $user = current_user();
        if (!$user) {
            return api_err('未登录', 401, 401);
        }
        $tid = $this->tenantId();
        $uid = (int)$user->id;

        $ch = Db::name('lottery_chances')->where(['tenant_id' => $tid, 'user_id' => $uid])->find();

        $pools = Db::name('lottery_pools')->where(['tenant_id' => $tid, 'enabled' => 1])
            ->order('id', 'asc')->select()->toArray();
        $poolSum = 0;
        foreach ($pools as $p) {
            $poolSum += max(0, (int)$p['weight']);
        }
        $prizes = Db::name('lottery_prizes')->where('tenant_id', $tid)
            ->order('id', 'asc')->select()->toArray();
        $byPool = [];
        foreach ($prizes as $z) {
            $byPool[(int)$z['pool_id']][] = $z;
        }
        $out = [];
        foreach ($pools as $p) {
            if ((int)$p['weight'] <= 0 || $poolSum <= 0) {
                continue;
            }
            $poolProb = (int)$p['weight'] / $poolSum;
            $zs = $byPool[(int)$p['id']] ?? [];
            $zSum = 0;
            foreach ($zs as $z) {
                $zSum += max(0, (int)$z['weight']);
            }
            $pOut = [];
            foreach ($zs as $z) {
                if ((int)$z['weight'] <= 0 || $zSum <= 0) {
                    continue;
                }
                $pOut[] = [
                    'id'        => (int)$z['id'],
                    'name'      => (string)$z['name'],
                    'is_thanks' => (int)$z['is_thanks'],
                    // 实际概率 = 奖池概率 × 池内概率，保留 4 位小数
                    'prob'      => round($poolProb * ((int)$z['weight'] / $zSum), 4),
                ];
            }
            if (!$pOut) {
                continue;
            }
            $out[] = [
                'id'     => (int)$p['id'],
                'name'   => (string)$p['name'],
                'prob'   => round($poolProb, 4),
                'prizes' => $pOut,
            ];
        }

        $logs = Db::name('lottery_logs')->where(['tenant_id' => $tid, 'user_id' => $uid])
            ->order('id', 'desc')->limit(20)->select()->toArray();

        return api_ok([
            'chances' => $ch ? (int)$ch['chances'] : 0,
            'used'    => $ch ? (int)$ch['used'] : 0,
            'total'   => $ch ? (int)$ch['total'] : 0,
            'pools'   => $out,
            'logs'    => $logs,
        ]);
    }

    /** 转一次：扣机会 → 两级加权抽取 → 扣库存 → 落记录（原子） */
    public function spin()
    {
        $user = current_user();
        if (!$user) {
            return api_err('未登录', 401, 401);
        }
        $tid = $this->tenantId();
        $uid = (int)$user->id;

        Db::startTrans();
        try {
            // 原子扣机会（where chances>0 防超扣，DB 无关无锁写法）
            $now = date('Y-m-d H:i:s');
            $aff = Db::name('lottery_chances')
                ->where(['tenant_id' => $tid, 'user_id' => $uid])
                ->where('chances', '>', 0)
                ->dec('chances', 1)->inc('used', 1)
                ->update(['updated_at' => $now]);
            if (!$aff) {
                Db::rollback();
                return api_err('没有抽奖机会，请联系管理员发放');
            }

            $pools = Db::name('lottery_pools')->where(['tenant_id' => $tid, 'enabled' => 1])
                ->where('weight', '>', 0)->order('id', 'asc')->select()->toArray();
            if (!$pools) {
                Db::rollback();
                return api_err('暂无开启的奖池');
            }
            $pool = self::weightedPick($pools);

            $prizes = Db::name('lottery_prizes')->where(['tenant_id' => $tid, 'pool_id' => $pool['id']])
                ->where('weight', '>', 0)->order('id', 'asc')->select()->toArray();
            // 候选：谢谢参与恒在；实物奖品库存为 0 时剔除
            $cands = array_values(array_filter($prizes, function ($z) {
                return (int)$z['is_thanks'] === 1 || (int)$z['stock'] !== 0;
            }));
            $prize = $cands ? self::weightedPick($cands) : null;

            // 实物奖品扣库存（并发兜底：扣不到即视为谢谢参与）
            if ($prize && (int)$prize['is_thanks'] !== 1 && (int)$prize['stock'] > 0) {
                $ok = Db::name('lottery_prizes')->where('id', $prize['id'])
                    ->where('stock', '>', 0)->dec('stock', 1)
                    ->update(['updated_at' => $now]);
                if (!$ok) {
                    $prize = null;
                }
            }

            $logId = (int)Db::name('lottery_logs')->insertGetId([
                'tenant_id'  => $tid,
                'user_id'    => $uid,
                'pool_id'    => (int)$pool['id'],
                'prize_id'   => $prize ? (int)$prize['id'] : 0,
                'prize_name' => $prize ? (string)$prize['name'] : '谢谢参与',
                'created_at' => $now,
            ]);
            Db::commit();

            $win = $prize && (int)$prize['is_thanks'] !== 1;
            return api_ok([
                'log_id'    => $logId,
                'pool_id'   => (int)$pool['id'],
                'pool_name' => (string)$pool['name'],
                'prize_id'  => $prize ? (int)$prize['id'] : 0,
                'prize_name'=> $win ? (string)$prize['name'] : '谢谢参与',
                'is_thanks' => $win ? 0 : 1,
            ], $win ? '恭喜中奖：' . $prize['name'] : '谢谢参与');
        } catch (\Throwable $e) {
            Db::rollback();
            return api_err('抽奖失败，请稍后再试');
        }
    }
}
