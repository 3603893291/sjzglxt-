<?php
declare (strict_types = 1);

namespace app\controller;

use app\BaseController;
use app\service\TenantContext;

/**
 * 页面入口（v5.0.0：平台/租户双上下文）
 *   根路径 → 入驻平台页面（platform.html）
 *   /{slug}/ → 该战队的游戏战队系统页面（login/register/app.html）
 */
class Index extends BaseController
{
    // 根 / 兜底：按登录态分流（静态首页由 public/index.html 直接服务）
    public function index()
    {
        if (TenantContext::isPlatform()) {
            return redirect(session('platform_user_id') ? '/app' : '/login');
        }
        return redirect(session('user_id') ? t_url('/app') : t_url('/login'));
    }

    // 登录页：平台登录 / 战队系统登录
    public function loginPage()
    {
        if (TenantContext::isPlatform()) {
            if (session('platform_user_id')) {
                return redirect('/app');
            }
            return render_page('platform.html', ['mode' => 'login']);
        }
        if (session('user_id')) {
            return redirect(t_url('/app'));
        }
        return render_page('login.html');
    }

    // 注册页：平台注册（入驻战队）/ 战队系统注册（成员）
    public function registerPage()
    {
        if (TenantContext::isPlatform()) {
            if (session('platform_user_id')) {
                return redirect('/app');
            }
            return render_page('platform.html', ['mode' => 'register']);
        }
        if (session('user_id')) {
            return redirect(t_url('/app'));
        }
        return render_page('register.html');
    }

    // v5.7.1(L1)：忘记密码页（仅战队站点；复用登录页外壳，AUTH_MODE=forgot）
    public function forgotPage()
    {
        if (TenantContext::isPlatform()) {
            abort(404);
        }
        if (session('user_id')) {
            return redirect(t_url('/app'));
        }
        return render_page('login.html', ['mode' => 'forgot']);
    }

    // 主界面：平台控制台 / 战队系统主界面（均需登录）
    public function appPage()
    {
        if (TenantContext::isPlatform()) {
            if (!session('platform_user_id')) {
                return redirect('/login');
            }
            return render_page('platform.html', ['mode' => 'app']);
        }
        // v5.1.0：隐身巡查免登录渲染（API 拉数据走 ?_sv=，AuthCheck 放行）
        if (!session('user_id') && !TenantContext::isStealth()) {
            return redirect(t_url('/login'));
        }
        return render_page('app.html');
    }
}
