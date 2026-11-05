<?php
declare (strict_types = 1);

namespace app\service;

/**
 * 当前站点上下文（v5.0.0：入驻平台 + 目录制多租户）
 * 由 ResolveTenant 中间件按 URL 路径第一段解析后写入，整请求生命周期有效。
 *
 * URL 结构：
 *   根路径（/、/login、/api/platform/...）        → 入驻平台（PLATFORM_ID）
 *   目录（/{slug}/login、/{slug}/api/...）        → 该 slug 对应的游戏战队系统
 */
class TenantContext
{
    /** 平台上下文 id（根路径）；租户 id 从 2 起（tenants 表自增，1 已废弃预留） */
    public const PLATFORM_ID = 0;

    /** @var array{id:int,slug:string,name:string,logo:string,status:string}|null */
    protected static $tenant = null;

    /** v5.1.0：隐身巡查标志——平台超管只读查看租户站点，不留任何痕迹 */
    protected static $stealth = false;

    public static function set(array $tenant): void
    {
        self::$tenant = $tenant;
    }

    public static function setStealth(bool $v): void
    {
        self::$stealth = $v;
    }

    /** 是否隐身巡查模式（只读，不写 session / 日志 / last_active） */
    public static function isStealth(): bool
    {
        return self::$stealth;
    }

    /** 标记为平台上下文（根路径） */
    public static function setPlatform(): void
    {
        self::$tenant = [
            'id'     => self::PLATFORM_ID,
            'slug'   => '',
            'name'   => '战队入驻平台',
            'logo'   => '',
            'status' => 'approved',
        ];
    }

    /** 当前租户 id（未解析/平台时为 0） */
    public static function id(): int
    {
        return self::$tenant['id'] ?? self::PLATFORM_ID;
    }

    /** 是否平台上下文（根路径） */
    public static function isPlatform(): bool
    {
        return self::id() === self::PLATFORM_ID;
    }

    public static function slug(): string
    {
        return (string)(self::$tenant['slug'] ?? '');
    }

    public static function name(): string
    {
        return (string)(self::$tenant['name'] ?? '');
    }

    public static function status(): string
    {
        return (string)(self::$tenant['status'] ?? 'approved');
    }

    /** URL 前缀：平台 ''，租户 '/slug'（拼接站内跳转 / 前端请求基址用） */
    public static function base(): string
    {
        return self::isPlatform() ? '' : '/' . self::slug();
    }

    /** 完整信息（供前端输出） */
    public static function info(): array
    {
        return [
            'id'         => self::id(),
            'slug'       => self::slug(),
            'name'       => self::name(),
            'logo'       => (string)(self::$tenant['logo'] ?? ''),
            'status'     => self::status(),
            'is_platform'=> self::isPlatform(),
            'base'       => self::base(),
        ];
    }

    /**
     * 目录标签校验：2-30 位小写字母/数字/连字符，首尾须字母数字
     * 保留字禁止注册，避免与根路径路由/静态目录冲突
     */
    public const RESERVED_SLUGS = [
        'www', 'api', 'app', 'admin', 'login', 'register', 'static',
        'storage', 'uploads', 'mail', 'smtp', 'dev', 'test', 'main',
        'assets', 'index', 'favicon', 'robots', 'apply', 'home',
        // v5.20.0：/show 与 /hall 是新增的根级页面。slug 撞到 show 时，/{show}/show
        // 会被解析成平台上下文再判 404，那支战队永远打不开自己的主页
        'show', 'hall',
        // v5.36.6：/passport/callback 是通行证唯一回调，必须留在根路径；
        // 同名 slug 会把这条路由劫持成战队上下文
        'passport',
    ];

    public static function validSlug(string $slug): bool
    {
        return (bool)preg_match('/^[a-z0-9][a-z0-9-]{0,28}[a-z0-9]$/', $slug);
    }

    public static function isReserved(string $slug): bool
    {
        return in_array($slug, self::RESERVED_SLUGS, true);
    }
}
