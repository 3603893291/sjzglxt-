<?php
declare (strict_types = 1);

namespace app\model;

use think\Model;

// 入驻平台账号（v5.0.0：面向入驻战队的独立注册登录体系）
// role=admin 为内置平台超管（审核入驻/管理租户）；role=team 为入驻战队账号
// 注意：本表是平台全局表，不挂 BelongsToTenant
class PlatformUser extends Model
{
    protected $name = 'platform_users';

    protected $autoWriteTimestamp = 'datetime';
    protected $createTime = 'created_at';
    protected $updateTime = 'updated_at';

    // 密码哈希永不输出到 JSON
    protected $hidden = ['password'];
}
