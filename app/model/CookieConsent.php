<?php
declare (strict_types = 1);

namespace app\model;

use think\Model;

/**
 * v5.10.0：Cookie 同意日志（GDPR 合规追踪）
 * 跨租户表，不使用 BelongsToTenant trait —— 平台超管需要跨租户查询
 * 唯一键：(tenant_id, user_id, user_hash)；同用户重复选择走 UPDATE 覆盖
 */
class CookieConsent extends Model
{
    protected $name = 'cookie_consent';
    protected $autoWriteTimestamp = 'datetime';
    protected $createTime = 'created_at';
    protected $updateTime = 'updated_at';

    /** 允许的 choice 枚举 */
    public const CHOICES = ['accepted', 'rejected', 'necessary_only'];
}
