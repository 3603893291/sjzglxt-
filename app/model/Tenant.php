<?php
declare (strict_types = 1);

namespace app\model;

use think\Model;

// 租户（v5.0.0：入驻战队，一个租户 = 主域名下一个目录站点 /{slug}/）
// 注意：本表是平台全局表，不挂 BelongsToTenant
class Tenant extends Model
{
    protected $name = 'tenants';

    protected $autoWriteTimestamp = 'datetime';
    protected $createTime = 'created_at';
    protected $updateTime = 'updated_at';
}
