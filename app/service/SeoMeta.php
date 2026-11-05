<?php
declare (strict_types = 1);

namespace app\service;

use think\facade\Db;

/**
 * v5.10.0：SEO 元数据聚合服务
 *
 * 数据源：
 *   - 平台上下文（tenant_id=0）：settings 表 seo_site_title / seo_site_description /
 *     seo_keywords / seo_og_image / seo_canonical_base
 *   - 战队上下文：tenants.name / tenants.description / tenants.logo + 战队自身 settings 表
 *     seo_site_title / seo_site_description / seo_keywords / seo_og_image / seo_canonical_base
 *     （战队未设置时回落到平台默认值，保证任何页面都有 meta description）
 *
 * 用法：
 *   echo \app\service\SeoMeta::head();   // 直接输出 <title>+<meta>+OG+Twitter
 *   $data = \app\service\SeoMeta::current();  // 拿数组用于 JSON API
 */
class SeoMeta
{
    /** @var array|null 请求内缓存，避免重复查库 */
    protected static $cache = null;

    /**
     * 采集当前上下文的 SEO 数据
     * @return array{title:string,description:string,keywords:string,og_image:string,canonical:string,site_name:string,is_platform:bool,slug:string}
     */
    public static function current(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $isPlatform = TenantContext::isPlatform();
        $base       = self::platformDefaults();

        if ($isPlatform) {
            $title       = $base['seo_site_title'];
            $description = $base['seo_site_description'];
            $keywords    = $base['seo_keywords'];
            $ogImage     = $base['seo_og_image'];
            $canonical   = $base['seo_canonical_base'];
            $siteName    = $title !== '' ? $title : '战队入驻平台';
            $slug        = '';
        } else {
            // 战队上下文：先看战队自己的 settings（tenant_id=当前租户），未设则回落平台默认
            $tid        = TenantContext::id();
            $tenantRows = Db::name('settings')->where('tenant_id', $tid)
                ->whereIn('key', [
                    'seo_site_title', 'seo_site_description',
                    'seo_keywords', 'seo_og_image', 'seo_canonical_base',
                ])->column('value', 'key');
            $tenant     = Db::name('tenants')->where('id', $tid)->field('name, description, logo')->find();

            $title       = (string)($tenantRows['seo_site_title']       ?? '');
            $description = (string)($tenantRows['seo_site_description'] ?? '');
            $keywords    = (string)($tenantRows['seo_keywords']        ?? '');
            $ogImage     = (string)($tenantRows['seo_og_image']        ?? '');
            $canonical   = (string)($tenantRows['seo_canonical_base']  ?? '');
            $slug        = TenantContext::slug();

            // 战队级默认：name + description + logo
            if ($title === '' && !empty($tenant['name'])) {
                $title = $tenant['name'] . ' · ' . $base['seo_site_title'];
            }
            if ($description === '' && !empty($tenant['description'])) {
                $description = mb_substr((string)$tenant['description'], 0, 200);
            }
            if ($ogImage === '' && !empty($tenant['logo'])) {
                $ogImage = self::absUrl((string)$tenant['logo']);
            }
            // 平台级兜底
            if ($title === '')       $title       = $base['seo_site_title'];
            if ($description === '') $description = $base['seo_site_description'];
            if ($keywords === '')    $keywords    = $base['seo_keywords'];
            if ($ogImage === '')     $ogImage     = $base['seo_og_image'];
            if ($canonical === '')   $canonical   = $base['seo_canonical_base'];
            $siteName = !empty($tenant['name']) ? (string)$tenant['name'] : $title;
        }

        // description 兜底：任何页面必须有 meta description，避免搜索引擎抓取空白
        if ($description === '') {
            $description = $isPlatform
                ? '战队入驻平台，提供三角洲行动战队管理、约战赛事、成员协作等一站式服务。'
                : ($siteName . ' 战队主页，加入我们一起征战三角洲行动。');
        }

        self::$cache = [
            'title'       => self::clip($title, 70),
            'description' => self::clip($description, 200),
            'keywords'    => self::clip($keywords, 200),
            'og_image'    => $ogImage,
            'canonical'   => $canonical,
            'site_name'   => self::clip($siteName, 60),
            'is_platform' => $isPlatform,
            'slug'        => $slug,
        ];
        return self::$cache;
    }

    /** 输出 <title>+<meta>+OG+Twitter+canonical 整块，模板 <?= SeoMeta::head() ?> 直接用 */
    public static function head(?string $overrideTitle = null, ?string $overrideDesc = null): string
    {
        $d     = self::current();
        $title = $overrideTitle !== null && $overrideTitle !== ''
            ? self::clip($overrideTitle . ' · ' . $d['site_name'], 80)
            : $d['title'];
        $desc  = $overrideDesc !== null && $overrideDesc !== ''
            ? self::clip($overrideDesc, 200)
            : $d['description'];

        $canonical = self::canonicalUrl($d['canonical']);
        $ogImage   = $d['og_image'] !== '' ? self::absUrl($d['og_image']) : '';

        $h = function (string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8'); };
        $out = [];
        $out[] = '<title>' . $h($title) . '</title>';
        $out[] = '<meta name="description" content="' . $h($desc) . '">';
        if ($d['keywords'] !== '') {
            $out[] = '<meta name="keywords" content="' . $h($d['keywords']) . '">';
        }
        $out[] = '<meta name="robots" content="index,follow,max-image-preview:large">';
        if ($canonical !== '') {
            $out[] = '<link rel="canonical" href="' . $h($canonical) . '">';
        }
        // Open Graph（微信/QQ/Facebook/LinkedIn 分享预览）
        $out[] = '<meta property="og:type" content="website">';
        $out[] = '<meta property="og:site_name" content="' . $h($d['site_name']) . '">';
        $out[] = '<meta property="og:title" content="' . $h($title) . '">';
        $out[] = '<meta property="og:description" content="' . $h($desc) . '">';
        if ($canonical !== '') {
            $out[] = '<meta property="og:url" content="' . $h($canonical) . '">';
        }
        if ($ogImage !== '') {
            $out[] = '<meta property="og:image" content="' . $h($ogImage) . '">';
            $out[] = '<meta property="og:image:alt" content="' . $h($d['site_name']) . '">';
        }
        $out[] = '<meta property="og:locale" content="zh_CN">';
        // Twitter Card
        $out[] = '<meta name="twitter:card" content="' . ($ogImage !== '' ? 'summary_large_image' : 'summary') . '">';
        $out[] = '<meta name="twitter:title" content="' . $h($title) . '">';
        $out[] = '<meta name="twitter:description" content="' . $h($desc) . '">';
        if ($ogImage !== '') {
            $out[] = '<meta name="twitter:image" content="' . $h($ogImage) . '">';
        }
        return implode("\n    ", $out);
    }

    /** 强制清空进程内缓存（保存 SEO 设置后调用，让同进程后续请求读到新值） */
    public static function flush(): void
    {
        self::$cache = null;
    }

    // ---------- 内部工具 ----------

    /** 平台级 SEO 默认值（settings tenant_id=0） */
    protected static function platformDefaults(): array
    {
        $rows = Db::name('settings')->where('tenant_id', TenantContext::PLATFORM_ID)
            ->whereIn('key', [
                'seo_site_title', 'seo_site_description',
                'seo_keywords', 'seo_og_image', 'seo_canonical_base',
            ])->column('value', 'key');
        return [
            'seo_site_title'       => (string)($rows['seo_site_title']       ?? '战队入驻平台'),
            'seo_site_description' => (string)($rows['seo_site_description'] ?? ''),
            'seo_keywords'         => (string)($rows['seo_keywords']         ?? ''),
            'seo_og_image'         => (string)($rows['seo_og_image']         ?? ''),
            'seo_canonical_base'   => (string)($rows['seo_canonical_base']   ?? ''),
        ];
    }

    /** 相对路径转绝对 URL（用于 og:image / og:url） */
    protected static function absUrl(string $path): string
    {
        if ($path === '') return '';
        if (preg_match('#^https?://#i', $path)) return $path;
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host   = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
        return $scheme . '://' . $host . (strpos($path, '/') === 0 ? $path : '/' . $path);
    }

    /** 生成当前请求的 canonical URL（未显式配置 base 时按当前请求推导） */
    protected static function canonicalUrl(string $base): string
    {
        $uri = (string)($_SERVER['REQUEST_URI'] ?? '/');
        // 去掉查询串，保留路径（分页参数另由业务侧决定是否加入）
        $path = strtok($uri, '?') ?: '/';
        if ($base !== '') {
            return rtrim($base, '/') . $path;
        }
        return self::absUrl($path);
    }

    protected static function clip(string $s, int $max): string
    {
        $s = trim($s);
        return mb_strlen($s) > $max ? mb_substr($s, 0, $max - 1) . '…' : $s;
    }
}
