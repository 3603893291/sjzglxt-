<?php
declare (strict_types = 1);

namespace app\service;

use think\facade\Db;

/**
 * 系统设置读取/写入（settings 表，key-value）
 * v4.0.0：按租户隔离——每个租户独立一套 settings（key 作用域 = 当前租户）
 */
class Setting
{
    /** @var array|null 进程内缓存 */
    protected static $cache = null;

    public static function get(string $key, string $default = ''): string
    {
        self::load();
        return array_key_exists($key, self::$cache) ? (string)self::$cache[$key] : $default;
    }

    public static function set(string $key, string $value): void
    {
        $tid    = TenantContext::id();
        $exists = Db::name('settings')->where('tenant_id', $tid)->where('key', $key)->find();
        if ($exists) {
            Db::name('settings')->where('tenant_id', $tid)->where('key', $key)->update(['value' => $value]);
        } else {
            Db::name('settings')->insert(['tenant_id' => $tid, 'key' => $key, 'value' => $value]);
        }
        self::load();
        self::$cache[$key] = $value;
    }

    /** 全部设置（管理端回显用） */
    public static function all(): array
    {
        self::load();
        return self::$cache;
    }

    /**
     * v5.36.0：按租户直读一个键，不走进程内缓存。
     * 定时提醒/机器人这类一次扫很多队的路径必须用它 —— Setting::$cache 记的是当前请求那个租户。
     */
    public static function getFor(int $tenantId, string $key, string $default = ''): string
    {
        $v = Db::name('settings')->where('tenant_id', $tenantId)
            ->where('key', $key)->value('value');
        return $v === null ? $default : (string)$v;
    }

    // ---------- v5.1.0：平台级设置（tenant_id=0，与租户缓存隔离） ----------

    public static function getPlatform(string $key, string $default = ''): string
    {
        $v = Db::name('settings')->where('tenant_id', TenantContext::PLATFORM_ID)
            ->where('key', $key)->value('value');
        return $v === null ? $default : (string)$v;
    }

    public static function setPlatform(string $key, string $value): void
    {
        $tid = TenantContext::PLATFORM_ID;
        $exists = Db::name('settings')->where('tenant_id', $tid)->where('key', $key)->find();
        if ($exists) {
            Db::name('settings')->where('tenant_id', $tid)->where('key', $key)->update(['value' => $value]);
        } else {
            Db::name('settings')->insert(['tenant_id' => $tid, 'key' => $key, 'value' => $value]);
        }
    }

    protected static function load(): void
    {
        if (self::$cache === null) {
            self::$cache = array_column(
                Db::name('settings')->where('tenant_id', TenantContext::id())->select()->toArray(),
                'value',
                'key'
            );
        }
    }
}
