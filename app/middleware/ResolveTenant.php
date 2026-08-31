<?php
declare (strict_types = 1);

namespace app\middleware;

use app\model\Tenant;
use app\service\TenantContext;
use app\service\Transfer;
use think\facade\Config;

/**
 * 站点解析中间件（v5.0.0：入驻平台 + 目录制多租户，全局最先执行）
 *
 * 按 URL 路径第一段解析：
 *   /{slug}/xxx 且 slug 命中 tenants 表 → 租户上下文，剥离前缀后路由照常；
 *     session name 改为 PHPSESSID_{slug}（同域名下各租户登录态互不串）
 *   其余（根路径、未命中的第一段）→ 平台上下文，路径不动
 * 租户状态非 approved 时整站拦截：API 返回 403，页面渲染提示页
 *   v5.1.0：transferring（转移冷静期，惰性结算到期自动交接）/ paused（战队主自助暂停）
 *   v5.1.0：?_sv= 隐身巡查令牌——平台超管只读进入，不写 session / 日志，仅 GET 有效
 */
class ResolveTenant
{
    public function handle($request, \Closure $next)
    {
        // pathinfo() 惰性解析；先取出再按需覆写（setPathinfo 写入缓存值，后续路由读到的即新值）
        $path  = trim($request->pathinfo(), '/');
        $first = $path === '' ? '' : strtolower(explode('/', $path)[0]);

        $tenant = null;
        if ($first !== '' && !TenantContext::isReserved($first)) {
            $tenant = Tenant::where('slug', $first)->find();
        }

        if ($tenant) {
            // v5.1.0：转移冷静期惰性结算——到期请求顺带完成交接并重读状态
            if ((string)$tenant->status === 'transferring') {
                Transfer::settle();
                $tenant = Tenant::where('slug', $first)->find();
            }

            TenantContext::set([
                'id'     => (int)$tenant->id,
                'slug'   => (string)$tenant->slug,
                'name'   => (string)$tenant->name,
                'logo'   => (string)$tenant->logo,
                'status' => (string)$tenant->status,
            ]);

            // v5.7.1(M2)：隐身巡查令牌不再直接走 URL——?_sv= 仅作一次性兑票：
            // 校验通过后立即作废令牌，换发 2 小时 HttpOnly cookie，再 302 到不带令牌的干净地址，
            // 避免令牌落入浏览器历史 / Referer / Nginx 与 CDN 日志
            $sv = (string)$request->get('_sv', '');
            if ($sv !== ''
                && stealth_verify_token((int)$tenant->id, $sv)
                && in_array((string)$tenant->status, ['approved', 'paused', 'banned'], true)) {
                stealth_consume_token((int)$tenant->id, $sv);
                $secret = bin2hex(random_bytes(24));
                stealth_register_session((int)$tenant->id, $secret);
                \think\facade\Cookie::set(stealth_cookie_name(), $secret, [
                    'expire'   => 7200,
                    'path'     => '/' . (string)$tenant->slug,
                    'httponly' => true,
                    'samesite' => 'lax',
                ]);
                return redirect('/' . (string)$tenant->slug . '/app');
            }

            // 已兑票的巡查会话：cookie 内登记有效 + 只读请求才放行（banned/paused 站点也允许超管进入巡查）
            $svCookie = (string)\think\facade\Cookie::get(stealth_cookie_name(), '');
            $stealth = $svCookie !== ''
                && stealth_verify_token((int)$tenant->id, $svCookie)
                && in_array($request->method(), ['GET', 'HEAD'], true)
                && in_array((string)$tenant->status, ['approved', 'paused', 'banned'], true);

            if ((string)$tenant->status !== 'approved' && !$stealth) {
                switch ((string)$tenant->status) {
                    case 'pending':
                        $msg = '站点审核中，暂未开放';
                        break;
                    case 'paused':
                        $msg = '站点已被战队主暂停，暂不可访问';
                        break;
                    case 'banned':
                        $msg = '站点已被平台封禁';
                        break;
                    case 'transferring':
                        $cool = Transfer::active((int)$tenant->id);
                        $date = $cool && $cool['cooling_until'] ? date('Y年m月d日', strtotime((string)$cool['cooling_until'])) : '';
                        $msg  = '战队正在转移中（冷静期），预计 ' . $date . ' 恢复访问';
                        break;
                    default:
                        $msg = '站点已停用';
                }
                $rest = trim(substr($path, strlen($first)), '/');
                if (str_starts_with($rest, 'api')) {
                    return api_err($msg, 403, 403);
                }
                $name = htmlspecialchars((string)$tenant->name, ENT_QUOTES, 'UTF-8');
                $msg  = htmlspecialchars($msg, ENT_QUOTES, 'UTF-8');
                return response(
                    '<!DOCTYPE html><html lang="zh"><head><meta charset="utf-8">'
                    . '<meta name="viewport" content="width=device-width,initial-scale=1">'
                    . '<title>' . $name . '</title></head>'
                    . '<body style="margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;'
                    . 'background:#0f172a;color:#e2e8f0;font-family:system-ui,sans-serif">'
                    . '<div style="text-align:center"><h1 style="font-size:22px;margin:0 0 12px">' . $name . '</h1>'
                    . '<p style="color:#94a3b8;margin:0">' . $msg . '</p></div></body></html>',
                    403
                )->contentType('text/html; charset=utf-8');
            }

            if ($stealth) {
                // 只读模式：后续控制器以租户超管视角渲染，但不写 session / 不记日志（见 AuthCheck/current_user）
                TenantContext::setStealth(true);
            }

            // session 按目录隔离：各租户独立 cookie 名，登录态互不干扰
            // v5.7.1(M6)：改用租户 ID 命名——slug 归一化（剥离 - 等符号）会让 my-team 与 myteam 撞名
            Config::set(['name' => 'PHPSESSID_t' . (int)$tenant->id], 'session');

            // 剥离 slug 前缀，后续路由/控制器对前缀无感知（根路径为空串，与 ThinkPHP 原生一致）
            $rest = trim(substr($path, strlen($first)), '/');
            $request->setPathinfo($rest);
        } else {
            TenantContext::setPlatform();
        }

        return $next($request);
    }
}
