<?php
declare (strict_types = 1);

namespace app\middleware;

/**
 * v5.7.1（M3）：CSRF 防护中间件
 *
 * 策略（纵深防御，不依赖前端改造）：
 * 1. GET / HEAD / OPTIONS 等安全方法直接放行；
 * 2. 浏览器发起的跨站请求会带 Sec-Fetch-Site 头：
 *    - same-origin / same-site → 放行
 *    - cross-site / 其他 → 拒绝（403）
 *    - none → 放行（地址栏导航/书签等，对 POST 场景几乎不出现）
 * 3. 旧浏览器没有 Sec-Fetch-Site 时回退 Origin / Referer 校验：
 *    主机名与本站不一致 → 拒绝；
 * 4. Origin 与 Referer 均缺失（curl / API 客户端 / 部分隐私模式）：
 *    本站 Cookie 为 SameSite=Strict（v5.7.1 M4 起 Secure 可配），
 *    跨站请求根本不会携带会话 Cookie，故缺失即放行，由会话鉴权兜底。
 *
 * 注意：此中间件只拦"浏览器可发起的跨站请求"，不校验同源请求本身；
 * 同源内的越权由 AuthCheck / AdminCheck / admin_can 负责。
 */
class CsrfGuard
{
    public function handle($request, \Closure $next)
    {
        $method = strtoupper((string)$request->method());
        if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $next($request);
        }

        // ---- 1) Sec-Fetch-Site（现代浏览器，最可靠）----
        $sfs = strtolower(trim((string)$request->header('Sec-Fetch-Site', '')));
        if ($sfs !== '') {
            if (in_array($sfs, ['same-origin', 'same-site', 'none'], true)) {
                return $next($request);
            }
            return $this->deny();
        }

        // ---- 2) 回退：Origin / Referer 主机名比对 ----
        $host = strtolower((string)($request->host(true) ?: $request->header('Host', '')));
        // 去掉端口便于比对
        $hostNoPort = preg_replace('/:\d+$/', '', $host);

        $origin = strtolower(trim((string)$request->header('Origin', '')));
        if ($origin !== '' && $origin !== 'null') {
            $originHost = preg_replace('/:\d+$/', '', (string)parse_url($origin, PHP_URL_HOST));
            if ($originHost !== '' && $originHost === $hostNoPort) {
                return $next($request);
            }
            return $this->deny();
        }

        $referer = strtolower(trim((string)$request->header('Referer', '')));
        if ($referer !== '') {
            $refHost = preg_replace('/:\d+$/', '', (string)parse_url($referer, PHP_URL_HOST));
            if ($refHost !== '' && $refHost === $hostNoPort) {
                return $next($request);
            }
            return $this->deny();
        }

        // ---- 3) 头全缺失：非浏览器客户端，靠 SameSite Cookie + 鉴权兜底 ----
        return $next($request);
    }

    private function deny()
    {
        return json(['code' => 403, 'msg' => '跨站请求已被拒绝', 'data' => []], 403);
    }
}
