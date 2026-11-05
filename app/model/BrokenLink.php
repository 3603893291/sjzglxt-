<?php
declare (strict_types = 1);

namespace app\model;

use think\Model;

/**
 * v5.10.0：死链检测记录
 * 跨租户表，不使用 BelongsToTenant trait —— 平台超管统一巡检
 * 唯一键：(tenant_id, url_hash)；同一 URL 只保留一行，重复检测走 UPDATE 累加 hit_count
 */
class BrokenLink extends Model
{
    protected $name = 'broken_links';
    protected $autoWriteTimestamp = 'datetime';
    protected $createTime = 'created_at';
    protected $updateTime = 'updated_at';

    /** 允许的 source_type 枚举 */
    public const SOURCES = ['gun_code', 'announcement', 'guide', 'chat', 'manual'];
}
