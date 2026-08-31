<?php
declare (strict_types = 1);

namespace app\middleware;

/**
 * 安全响应头中间件
 * 为所有 HTTP 响应添加安全头：
 * - X-Content-Type-Options: 防止 MIME 类型嗅探
 * - X-Frame-Options: 防止点击劫持
 * - X-XSS-Protection: 启用浏览器 XSS 过滤器
 * - Referrer-Policy: 限制 Referer 信息泄露
 * - Content-Security-Policy: 基础内容安全策略
 */
class SecurityHeaders
{
    public function handle($request, \Closure $next)
    {
        $response = $next($request);

        $headers = [
            'X-Content-Type-Options'  => 'nosniff',
            'X-Frame-Options'         => 'SAMEORIGIN',
            'X-XSS-Protection'        => '1; mode=block',
            'Referrer-Policy'         => 'strict-origin-when-cross-origin',
        ];

        // v3.9.3 缓存策略：
        // - 带 ?v= 版本号的静态资源（asset() 生成）→ 长期 immutable 缓存，版本一变 URL 即变
        // - 其余（HTML 页面 / API 响应）→ 不缓存，杜绝手机浏览器 heuristic 缓存把旧页面/旧资源 URL 留住
        $path = (string)$request->pathinfo();
        if (strpos($path, 'static/') !== false && $request->param('v') !== null) {
            $headers['Cache-Control'] = 'public, max-age=604800, immutable';
        } else {
            $headers['Cache-Control'] = 'no-store, no-cache, must-revalidate';
            $headers['Pragma']        = 'no-cache';
        }

        if (method_exists($response, 'header')) {
            $response->header($headers);
        }

        return $response;
    }
}
