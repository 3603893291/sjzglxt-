<?php
declare (strict_types = 1);

namespace app\model;

use think\Model;

/**
 * v5.10.0：API 请求日志（ApiKeyAuth 全量审计）
 * 跨租户表，不使用 BelongsToTenant trait
 * 只记录元数据，不落请求体，避免敏感数据泄漏
 */
class ApiLog extends Model
{
    protected $name = 'api_logs';
    protected $autoWriteTimestamp = false;   // 只有 created_at，无 updated_at
    protected $createTime = 'created_at';
    protected $updateTime = false;

    /** 保留天数（清理任务用） */
    public const RETAIN_DAYS = 90;
}
