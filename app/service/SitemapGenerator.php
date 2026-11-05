<?php
declare (strict_types = 1);

namespace app\service;

use think\facade\Db;

/**
 * v5.10.0：Sitemap 生成器
 *
 * 输出：
 *   public/sitemap.xml             —— 索引，指向 platform + 每个 tenant 的子 sitemap
 *   public/sitemap-platform.xml    —— 平台自身公开路径
 *   public/sitemap-{slug}.xml      —— 每个战队的公开路径（仅 approved 状态）
 *
 * 触发：
 *   - 平台超管手动点击「生成 Sitemap」按钮 → generateAll()
 *   - 建议每周由外部 cron 定时刷新（可选）
 *
 * 静态文件由 nginx/apache 直接吐出，不占用 PHP 进程。
 */
class SitemapGenerator
{
    /** 平台自身公开路径（相对根路径，无 slug 前缀） */
    protected const PLATFORM_PATHS = [
        ['/',          'daily',  '1.0'],
        ['/login',     'monthly','0.3'],
        ['/register',  'monthly','0.3'],
        ['/apply',     'monthly','0.4'],
        ['/privacy',   'yearly', '0.2'],
        ['/terms',     'yearly', '0.2'],
        // v5.20.0：公示大厅（只在平台总开关开着时收录，见 buildPlatformXml）
        ['/hall',      'daily',  '0.7'],
    ];

    /** 战队站点公开路径（相对 /{slug}/） */
    protected const TENANT_PATHS = [
        ['',           'daily',  '1.0'],
        ['/privacy',   'yearly', '0.2'],
        ['/terms',     'yearly', '0.2'],
        // v5.20.0：对外展示页（按站点开启状态下发，见 buildTenantXml）
        ['/show',      'weekly', '0.8'],
    ];

    /**
     * 一键生成全部 sitemap 文件
     * @param string $baseUrl 站点根 URL（如 https://yys.example.com），传空则用 seo_canonical_base 或 HTTP_HOST
     * @return array{ok:bool,files:array,count:int,tenant_count:int,error:string}
     */
    public static function generateAll(string $baseUrl = ''): array
    {
        $result = ['ok' => false, 'files' => [], 'count' => 0, 'tenant_count' => 0, 'error' => ''];

        if (Setting::getPlatform('sitemap_enabled', '1') !== '1') {
            $result['error'] = 'Sitemap 已停用（sitemap_enabled=0）';
            return $result;
        }

        $baseUrl = self::resolveBase($baseUrl);
        if ($baseUrl === '') {
            $result['error'] = '无法确定站点根 URL，请在 SEO 设置里配置 seo_canonical_base';
            return $result;
        }

        $publicDir = rtrim(public_path(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if (!is_dir($publicDir) || !is_writable($publicDir)) {
            $result['error'] = 'public 目录不可写';
            return $result;
        }

        try {
            // 1) 拉取全部 approved 战队
            $tenants = Db::name('tenants')
                ->where('status', 'approved')
                ->field('id, slug, name, updated_at')
                ->order('id', 'asc')
                ->select()->toArray();

            // 2) 为每个战队生成子 sitemap
            $sitemapUrls = [];
            $nowIso      = self::nowIso();
            foreach ($tenants as $t) {
                $slug = (string)$t['slug'];
                if (!TenantContext::validSlug($slug)) continue;
                $file = 'sitemap-' . $slug . '.xml';
                $xml  = self::buildTenantXml($baseUrl, $slug, $t);
                if (file_put_contents($publicDir . $file, $xml) === false) {
                    $result['error'] = '写入 ' . $file . ' 失败';
                    return $result;
                }
                $result['files'][] = $file;
                $result['count']++;
                $sitemapUrls[] = [
                    'loc'        => rtrim($baseUrl, '/') . '/' . $file,
                    'lastmod'    => !empty($t['updated_at']) ? self::toIso((string)$t['updated_at']) : $nowIso,
                ];
            }
            $result['tenant_count'] = count($sitemapUrls);

            // 3) 平台自身 sitemap
            $platformXml = self::buildPlatformXml($baseUrl);
            if (file_put_contents($publicDir . 'sitemap-platform.xml', $platformXml) === false) {
                $result['error'] = '写入 sitemap-platform.xml 失败';
                return $result;
            }
            $result['files'][] = 'sitemap-platform.xml';
            $result['count']++;
            array_unshift($sitemapUrls, [
                'loc'     => rtrim($baseUrl, '/') . '/sitemap-platform.xml',
                'lastmod' => $nowIso,
            ]);

            // 4) sitemap 索引
            $indexXml = self::buildIndexXml($sitemapUrls);
            if (file_put_contents($publicDir . 'sitemap.xml', $indexXml) === false) {
                $result['error'] = '写入 sitemap.xml 失败';
                return $result;
            }
            $result['files'][] = 'sitemap.xml';
            $result['count']++;

            Setting::setPlatform('sitemap_last_generated', date('Y-m-d H:i:s'));
            $result['ok'] = true;
            return $result;
        } catch (\Throwable $e) {
            $result['error'] = mb_substr($e->getMessage(), 0, 200);
            return $result;
        }
    }

    /** 平台超管查看当前 Sitemap 状态（未生成/文件数/最近生成时间） */
    public static function status(): array
    {
        $publicDir = rtrim(public_path(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $files = ['sitemap.xml', 'sitemap-platform.xml'];
        $exists = [];
        foreach ($files as $f) {
            $p = $publicDir . $f;
            $exists[$f] = is_file($p) ? [
                'size' => (int)filesize($p),
                'mtime' => date('Y-m-d H:i:s', (int)filemtime($p)),
            ] : null;
        }
        // 战队 sitemap
        $tenantFiles = glob($publicDir . 'sitemap-*.xml') ?: [];
        $tenantFiles = array_values(array_filter($tenantFiles, fn($p) => basename($p) !== 'sitemap-platform.xml'));
        return [
            'enabled'       => Setting::getPlatform('sitemap_enabled', '1') === '1',
            'last_generated'=> Setting::getPlatform('sitemap_last_generated'),
            'base_url'      => self::resolveBase(''),
            'index'         => $exists['sitemap.xml'],
            'platform'      => $exists['sitemap-platform.xml'],
            'tenant_files'  => count($tenantFiles),
        ];
    }

    /** 停用/启用 Sitemap（停用后不会自动生成，但已生成文件保留可手动删） */
    public static function setEnabled(bool $on): void
    {
        Setting::setPlatform('sitemap_enabled', $on ? '1' : '0');
    }

    // ---------- XML 构造 ----------

    protected static function buildIndexXml(array $sitemaps): string
    {
        $h = function (string $s): string { return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8'); };
        $out  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $out .= '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach ($sitemaps as $s) {
            $out .= "  <sitemap>\n";
            $out .= "    <loc>" . $h($s['loc']) . "</loc>\n";
            $out .= "    <lastmod>" . $h($s['lastmod']) . "</lastmod>\n";
            $out .= "  </sitemap>\n";
        }
        $out .= '</sitemapindex>' . "\n";
        return $out;
    }

    protected static function buildPlatformXml(string $baseUrl): string
    {
        // 公示大厅被平台超管关掉时不收录：留着一条恒 404 的地址等于告诉爬虫这里有队伍列表
        $paths = self::PLATFORM_PATHS;
        if (!Showcase::hallOpen()) {
            $paths = array_values(array_filter($paths, function ($p) { return $p[0] !== '/hall'; }));
        }
        return self::buildUrlset($baseUrl, $paths, '');
    }

    protected static function buildTenantXml(string $baseUrl, string $slug, array $tenant): string
    {
        $prefix = '/' . $slug;
        $tid    = (int)($tenant['id'] ?? 0);
        $paths  = self::TENANT_PATHS;
        // 展示页只有"战队自己开了且没被平台下架"才收录：
        // 未开启的站点 /show 恒 404，替队长把它推给搜索引擎是越过他的意愿做公开
        if (!Showcase::teamOpen($tid) || Showcase::isForceOff($tid)) {
            $paths = array_values(array_filter($paths, function ($p) { return $p[0] !== '/show'; }));
        }
        return self::buildUrlset($baseUrl, $paths, $prefix);
    }

    protected static function buildUrlset(string $baseUrl, array $paths, string $prefix): string
    {
        $h = function (string $s): string { return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8'); };
        $base = rtrim($baseUrl, '/');
        $now  = self::nowIso();

        $out  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $out .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach ($paths as [$path, $freq, $prio]) {
            $loc = $base . $prefix . ($path === '' ? '/' : $path);
            $out .= "  <url>\n";
            $out .= "    <loc>" . $h($loc) . "</loc>\n";
            $out .= "    <lastmod>" . $h($now) . "</lastmod>\n";
            $out .= "    <changefreq>" . $h($freq) . "</changefreq>\n";
            $out .= "    <priority>" . $h($prio) . "</priority>\n";
            $out .= "  </url>\n";
        }
        $out .= '</urlset>' . "\n";
        return $out;
    }

    // ---------- 工具 ----------

    /** 站点根 URL：优先入参 > seo_canonical_base > HTTP_HOST */
    protected static function resolveBase(string $baseUrl): string
    {
        if ($baseUrl !== '') return rtrim($baseUrl, '/');
        $configured = trim(Setting::getPlatform('seo_canonical_base', ''));
        if ($configured !== '') {
            return rtrim(preg_replace('#^https?://#i', '', $configured) === ''
                ? $configured
                : (preg_match('#^https?://#i', $configured) ? $configured : 'https://' . $configured), '/');
        }
        $host = (string)($_SERVER['HTTP_HOST'] ?? '');
        if ($host === '') return '';
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        return rtrim($scheme . '://' . $host, '/');
    }

    protected static function nowIso(): string
    {
        return date('c');
    }

    protected static function toIso(string $datetime): string
    {
        $ts = strtotime($datetime);
        return $ts ? date('c', $ts) : self::nowIso();
    }
}
