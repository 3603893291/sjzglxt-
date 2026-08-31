<?php
declare (strict_types = 1);

namespace app\model;

use think\Model;

// 战队入驻申请（v5.0.0：平台登录后提交，平台超管审核后开通租户站点）
// 注意：本表是平台全局表，不挂 BelongsToTenant
class TenantApplication extends Model
{
    protected $name = 'tenant_applications';

    protected $autoWriteTimestamp = 'datetime';
    protected $createTime = 'created_at';
    protected $updateTime = 'updated_at';

    // 密码哈希永不输出到 JSON
    protected $hidden = ['admin_password'];
}
