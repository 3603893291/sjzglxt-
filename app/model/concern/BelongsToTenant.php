<?php
declare (strict_types = 1);

namespace app\model\concern;

use app\service\TenantContext;

/**
 * 多租户行级隔离（v4.0.0）
 * 挂到业务模型上即获得两个能力：
 * 1. 全局查询作用域：所有经模型的查询自动附加 where tenant_id = 当前租户
 * 2. 写入自动补租户：save/create 时若未显式指定 tenant_id 则填当前租户
 *    （显式指定的优先——租户开通时主站后台跨租户建站长账号要用）
 * 全局表（blocked_ips / email_codes / tenants / tenant_applications）不挂此 trait
 */
trait BelongsToTenant
{
    // think-orm 4 全局查询范围：db() 时自动调用 scopeTenant
    protected $globalScope = ['tenant'];

    public function scopeTenant($query)
    {
        // 列名加限定前缀：带 LEFT JOIN 的查询（聊天/图片/攻略/枪码连 users 表）
        // 两表都有 tenant_id，裸列名会报 ambiguous column name。
        // scope 为惰性执行（查询时调用），此时 alias() 已登记，getAlias() 可用
        $alias = $query->getAlias();
        $col   = $alias !== '' ? $alias . '.tenant_id' : $query->getTable() . '.tenant_id';
        $query->where($col, TenantContext::id());
    }

    public function save(array | object $data = [], $where = [], bool $refresh = false): bool
    {
        if (!$this->getData('tenant_id')) {
            $this->set('tenant_id', TenantContext::id());
        }
        return parent::save($data, $where, $refresh);
    }
}
