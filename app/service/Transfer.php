<?php
declare (strict_types = 1);

namespace app\service;

use think\facade\Db;

/**
 * 战队转移（v5.1.0）
 *
 * 流程：原战队主发起（pending，站点仍可访问）→ 接收方同意 → 进入 7 天冷静期
 * （cooling，tenant.status='transferring'，站点整站不可访问）→ 冷静期结束惰性完成
 * （tenants.applicant_id 转给接收方，站点恢复）。发起方可在冷静期结束前撤回。
 *
 * from_uid / to_uid 均为 platform_users 平台账号 id。
 */
class Transfer
{
    /** 冷静期天数 */
    public const COOL_DAYS = 7;

    /** 指定租户当前进行中的转移单（pending 或 cooling），无则 null */
    public static function active(int $tenantId): ?array
    {
        $row = Db::name('tenant_transfers')
            ->where('tenant_id', $tenantId)
            ->whereIn('status', ['pending', 'cooling'])
            ->order('id', 'desc')->find();
        return $row ?: null;
    }

    /** 指定平台账号待接收的转移单（pending），无则 null */
    public static function incoming(int $uid): ?array
    {
        $row = Db::name('tenant_transfers')
            ->where('to_uid', $uid)->where('status', 'pending')
            ->order('id', 'desc')->find();
        return $row ?: null;
    }

    /**
     * 惰性结算：冷静期已过的 cooling 单完成交接
     * 在租户访问入口与平台 my 接口顺带调用，无需定时任务
     */
    public static function settle(): void
    {
        $rows = Db::name('tenant_transfers')
            ->where('status', 'cooling')
            ->where('cooling_until', '<=', date('Y-m-d H:i:s'))
            ->select()->toArray();
        foreach ($rows as $tr) {
            Db::startTrans();
            try {
                Db::name('tenants')->where('id', $tr['tenant_id'])->update([
                    'applicant_id' => $tr['to_uid'],
                    'status'       => 'approved',
                ]);
                Db::name('tenant_transfers')->where('id', $tr['id'])
                    ->update(['status' => 'completed']);
                Db::commit();
            } catch (\Throwable $e) {
                Db::rollback();   // 单次失败不阻塞其余结算
            }
        }
    }

    /**
     * 撤销/拒绝进行中转移：站点恢复原主可访问
     * @param array $tr 转移单行
     * @param string $finalStatus cancelled（发起方撤回）或 rejected（接收方拒绝）
     */
    public static function abort(array $tr, string $finalStatus): void
    {
        Db::startTrans();
        try {
            Db::name('tenants')->where('id', $tr['tenant_id'])->update(['status' => 'approved']);
            Db::name('tenant_transfers')->where('id', $tr['id'])
                ->update(['status' => $finalStatus]);
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }
    }

    /** 带双方昵称的转移单视图（平台前端展示用） */
    public static function view(array $tr): array
    {
        $tenant = Db::name('tenants')->where('id', $tr['tenant_id'])->find();
        $from   = Db::name('platform_users')->where('id', $tr['from_uid'])->find();
        $to     = Db::name('platform_users')->where('id', $tr['to_uid'])->find();
        return [
            'id'            => (int)$tr['id'],
            'tenant_name'   => $tenant ? (string)$tenant['name'] : '-',
            'tenant_slug'   => $tenant ? (string)$tenant['slug'] : '',
            'from_name'     => $from ? (string)$from['nickname'] . '(' . $from['username'] . ')' : '-',
            'to_name'       => $to ? (string)$to['nickname'] . '(' . $to['username'] . ')' : '-',
            'status'        => (string)$tr['status'],
            'cooling_until' => (string)$tr['cooling_until'],
            'created_at'    => (string)$tr['created_at'],
        ];
    }
}
