<?php
declare(strict_types=1);

namespace app\controller;

use app\service\TenantContext;
use think\facade\Db;

/**
 * 抽奖大转盘·管理端（v5.7.0，AdminCheck + lottery 权限）
 *
 * 奖池/奖品维护、机会发放（指定成员或全员）、中奖记录核销。
 * 发放机会仅管理员可操作（需求：战队内部管理员才可以发放机会）。
 */
class LotteryAdmin extends AuthBase
{
    protected $middleware = [\app\middleware\AdminCheck::class];

    /** lottery 权限守卫（真超管/站长经 admin_perms_of 恒持） */
    protected function guard()
    {
        if (!admin_can('lottery')) {
            return api_err('无权限：需要抽奖管理权限', 403, 403);
        }
        return null;
    }

    protected function tenantId(): int
    {
        return TenantContext::id();
    }

    /** 管理首页：奖池(含奖品) + 成员机会 + 中奖记录 */
    public function index()
    {
        if ($r = $this->guard()) return $r;
        $tid = $this->tenantId();

        $pools = Db::name('lottery_pools')->where('tenant_id', $tid)->order('id', 'asc')->select()->toArray();
        $poolSum = 0;
        foreach ($pools as $p) {
            if ((int)$p['enabled'] === 1) {
                $poolSum += max(0, (int)$p['weight']);
            }
        }
        $prizes = Db::name('lottery_prizes')->where('tenant_id', $tid)->order('id', 'asc')->select()->toArray();
        $byPool = [];
        foreach ($prizes as $z) {
            $byPool[(int)$z['pool_id']][] = $z;
        }
        foreach ($pools as &$p) {
            $p['prob'] = ((int)$p['enabled'] === 1 && $poolSum > 0)
                ? round(max(0, (int)$p['weight']) / $poolSum, 4) : 0;
            $zs = $byPool[(int)$p['id']] ?? [];
            $zSum = 0;
            foreach ($zs as $z) {
                $zSum += max(0, (int)$z['weight']);
            }
            foreach ($zs as &$z) {
                $z['prob'] = $zSum > 0 ? round(max(0, (int)$z['weight']) / $zSum, 4) : 0;
            }
            unset($z);
            $p['prizes'] = $zs;
        }
        unset($p);

        // 成员机会（有过发放记录的），拼昵称
        $chs = Db::name('lottery_chances')->where('tenant_id', $tid)
            ->where(function ($q) { $q->where('chances', '>', 0)->whereOr('total', '>', 0); })
            ->order('chances', 'desc')->order('id', 'asc')->limit(200)->select()->toArray();
        $uids = array_column($chs, 'user_id');
        $names = $uids ? Db::name('users')->whereIn('id', $uids)->column('nickname', 'id') : [];
        foreach ($chs as &$c) {
            $c['nickname'] = (string)($names[$c['user_id']] ?? ('用户#' . $c['user_id']));
        }
        unset($c);

        // 中奖记录（最近 200 条），拼昵称
        $logs = Db::name('lottery_logs')->where('tenant_id', $tid)
            ->order('id', 'desc')->limit(200)->select()->toArray();
        $luids = array_values(array_unique(array_column($logs, 'user_id')));
        $lnames = $luids ? Db::name('users')->whereIn('id', $luids)->column('nickname', 'id') : [];
        foreach ($logs as &$l) {
            $l['nickname'] = (string)($lnames[$l['user_id']] ?? ('用户#' . $l['user_id']));
        }
        unset($l);

        return api_ok(['pools' => $pools, 'chances' => $chs, 'logs' => $logs]);
    }

    /** 新建/编辑奖池 */
    public function savePool()
    {
        if ($r = $this->guard()) return $r;
        $p = $this->request->post();
        $id = (int)($p['id'] ?? 0);
        $name = trim((string)($p['name'] ?? ''));
        $weight = (int)($p['weight'] ?? 1);
        $enabled = (int)($p['enabled'] ?? 1) === 1 ? 1 : 0;
        if ($name === '' || mb_strlen($name) > 64) {
            return api_err('奖池名称不能为空且不超过 64 字');
        }
        if ($weight < 1 || $weight > 100000) {
            return api_err('奖池权重须为 1-100000 的整数');
        }
        $now = date('Y-m-d H:i:s');
        if ($id > 0) {
            $hit = Db::name('lottery_pools')->where(['id' => $id, 'tenant_id' => $this->tenantId()])->find();
            if (!$hit) {
                return api_err('奖池不存在');
            }
            Db::name('lottery_pools')->where('id', $id)->update([
                'name' => $name, 'weight' => $weight, 'enabled' => $enabled, 'updated_at' => $now,
            ]);
            admin_log('抽奖管理', '编辑奖池 #' . $id . ' ' . $name);
            return api_ok(['id' => $id], '奖池已更新');
        }
        $newId = (int)Db::name('lottery_pools')->insertGetId([
            'tenant_id' => $this->tenantId(), 'name' => $name, 'weight' => $weight,
            'enabled' => $enabled, 'created_at' => $now, 'updated_at' => $now,
        ]);
        admin_log('抽奖管理', '新建奖池 #' . $newId . ' ' . $name);
        return api_ok(['id' => $newId], '奖池已创建');
    }

    /** 删除奖池（级联删奖品） */
    public function removePool($id)
    {
        if ($r = $this->guard()) return $r;
        $id = (int)$id;
        $tid = $this->tenantId();
        $hit = Db::name('lottery_pools')->where(['id' => $id, 'tenant_id' => $tid])->find();
        if (!$hit) {
            return api_err('奖池不存在');
        }
        Db::name('lottery_prizes')->where(['pool_id' => $id, 'tenant_id' => $tid])->delete();
        Db::name('lottery_pools')->where('id', $id)->delete();
        admin_log('抽奖管理', '删除奖池 #' . $id . ' ' . $hit['name']);
        return api_ok([], '奖池已删除');
    }

    /** 新建/编辑奖品 */
    public function savePrize()
    {
        if ($r = $this->guard()) return $r;
        $p = $this->request->post();
        $id = (int)($p['id'] ?? 0);
        $poolId = (int)($p['pool_id'] ?? 0);
        $name = trim((string)($p['name'] ?? ''));
        $weight = (int)($p['weight'] ?? 1);
        $stock = (int)($p['stock'] ?? -1);
        $isThanks = (int)($p['is_thanks'] ?? 0) === 1 ? 1 : 0;
        $tid = $this->tenantId();
        if (!$poolId || !Db::name('lottery_pools')->where(['id' => $poolId, 'tenant_id' => $tid])->find()) {
            return api_err('所属奖池不存在');
        }
        if ($name === '' || mb_strlen($name) > 64) {
            return api_err('奖品名称不能为空且不超过 64 字');
        }
        if ($weight < 1 || $weight > 100000) {
            return api_err('奖品权重须为 1-100000 的整数');
        }
        if ($stock < -1) {
            $stock = -1;
        }
        if ($isThanks === 1) {
            $stock = -1; // 谢谢参与不占库存
        }
        $now = date('Y-m-d H:i:s');
        if ($id > 0) {
            $hit = Db::name('lottery_prizes')->where(['id' => $id, 'tenant_id' => $tid])->find();
            if (!$hit) {
                return api_err('奖品不存在');
            }
            Db::name('lottery_prizes')->where('id', $id)->update([
                'pool_id' => $poolId, 'name' => $name, 'weight' => $weight,
                'stock' => $stock, 'is_thanks' => $isThanks, 'updated_at' => $now,
            ]);
            admin_log('抽奖管理', '编辑奖品 #' . $id . ' ' . $name);
            return api_ok(['id' => $id], '奖品已更新');
        }
        $newId = (int)Db::name('lottery_prizes')->insertGetId([
            'tenant_id' => $tid, 'pool_id' => $poolId, 'name' => $name, 'weight' => $weight,
            'stock' => $stock, 'is_thanks' => $isThanks, 'created_at' => $now, 'updated_at' => $now,
        ]);
        admin_log('抽奖管理', '新建奖品 #' . $newId . ' ' . $name);
        return api_ok(['id' => $newId], '奖品已创建');
    }

    /** 删除奖品 */
    public function removePrize($id)
    {
        if ($r = $this->guard()) return $r;
        $id = (int)$id;
        $hit = Db::name('lottery_prizes')->where(['id' => $id, 'tenant_id' => $this->tenantId()])->find();
        if (!$hit) {
            return api_err('奖品不存在');
        }
        Db::name('lottery_prizes')->where('id', $id)->delete();
        admin_log('抽奖管理', '删除奖品 #' . $id . ' ' . $hit['name']);
        return api_ok([], '奖品已删除');
    }

    /** 发放机会：mode=user 指定成员 / mode=all 全员（仅审核通过成员） */
    public function grant()
    {
        if ($r = $this->guard()) return $r;
        $p = $this->request->post();
        $mode = (string)($p['mode'] ?? 'user');
        $count = (int)($p['count'] ?? 0);
        if ($count < 1 || $count > 10000) {
            return api_err('发放次数须为 1-10000 的整数');
        }
        $tid = $this->tenantId();
        $now = date('Y-m-d H:i:s');

        $upsert = function (int $uid) use ($tid, $count, $now) {
            $hit = Db::name('lottery_chances')->where(['tenant_id' => $tid, 'user_id' => $uid])->find();
            if ($hit) {
                Db::name('lottery_chances')->where('id', $hit['id'])
                    ->inc('chances', $count)->inc('total', $count)
                    ->update(['updated_at' => $now]);
            } else {
                Db::name('lottery_chances')->insert([
                    'tenant_id' => $tid, 'user_id' => $uid, 'chances' => $count,
                    'total' => $count, 'used' => 0, 'updated_at' => $now,
                ]);
            }
        };

        if ($mode === 'all') {
            $uids = Db::name('users')->where(['tenant_id' => $tid, 'status' => 'approved'])
                ->column('id');
            if (!$uids) {
                return api_err('本站暂无审核通过的成员');
            }
            Db::startTrans();
            try {
                foreach ($uids as $uid) {
                    $upsert((int)$uid);
                }
                Db::commit();
            } catch (\Throwable $e) {
                Db::rollback();
                return api_err('发放失败，请稍后再试');
            }
            admin_log('抽奖管理', '全员发放机会 x' . $count . '（' . count($uids) . ' 人）');
            return api_ok(['count' => count($uids)], '已给 ' . count($uids) . ' 名成员各发放 ' . $count . ' 次机会');
        }

        $uid = (int)($p['user_id'] ?? 0);
        $u = Db::name('users')->where(['id' => $uid, 'tenant_id' => $tid])->find();
        if (!$u) {
            return api_err('成员不存在');
        }
        if ($u['status'] !== 'approved') {
            return api_err('该成员未通过审核，不能发放机会');
        }
        $upsert($uid);
        admin_log('抽奖管理', '发放机会 x' . $count . ' → 用户#' . $uid . '(' . $u['nickname'] . ')');
        return api_ok([], '已给 ' . $u['nickname'] . ' 发放 ' . $count . ' 次机会');
    }

    /** 中奖记录核销（标记已发放） */
    public function fulfill($id)
    {
        if ($r = $this->guard()) return $r;
        $id = (int)$id;
        $log = Db::name('lottery_logs')->where(['id' => $id, 'tenant_id' => $this->tenantId()])->find();
        if (!$log) {
            return api_err('记录不存在');
        }
        if ((int)$log['prize_id'] === 0) {
            return api_err('谢谢参与无需核销');
        }
        if ((int)$log['fulfilled'] === 1) {
            return api_err('该记录已核销');
        }
        $u = current_user();
        Db::name('lottery_logs')->where('id', $id)->update([
            'fulfilled' => 1,
            'fulfilled_by' => $u ? (($u->nickname ?: '') . '(' . $u->username . ')') : '',
            'fulfilled_at' => date('Y-m-d H:i:s'),
        ]);
        admin_log('抽奖管理', '核销中奖记录 #' . $id . ' ' . $log['prize_name']);
        return api_ok([], '已标记发放');
    }
}
