<?php
declare (strict_types = 1);

namespace app\controller;

use app\BaseController;
use app\service\TenantContext;
use think\facade\Db;

/**
 * 页面入口（v5.0.0：平台/租户双上下文）
 *   根路径 → 未登录给官网首页（home.html），已登录进平台控制台
 *   /{slug}/ → 该战队的游戏战队系统页面（login/register/app.html）
 */
class Index extends BaseController
{
    // 根 /：平台侧未登录出首页，已登录进控制台；战队站点仍按登录态分流
    public function index()
    {
        if (TenantContext::isPlatform()) {
            if (session('platform_user_id')) {
                return redirect('/app');
            }
            return render_page('home.html', ['stats' => $this->homeStats()]);
        }
        return redirect(session('user_id') ? t_url('/app') : t_url('/login'));
    }

    /** v5.27.0：首页那四个数——只给全站聚合计数，不出现任何战队名与玩家身份 */
    protected function homeStats(): array
    {
        return [
            'tenants'   => Db::name('tenants')->where('status', 'approved')->count(),
            'players'   => Db::name('users')->where('status', 'approved')->count(),
            // v5.32.0：论坛下线，这一格换成跨队约战真正办成的场次（报名过并打完的，不含被拒与过期）
            'battles'   => Db::name('battles')->whereIn('status', ['accepted', 'finished'])->count(),
            'gunCodes'  => Db::name('gun_codes')->where('status', 'published')->count(),
            // 公示大厅被平台关掉时 /hall 是 404，首页就不摆这个入口
            'hallOpen'  => \app\service\Showcase::hallOpen(),
        ];
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
                // 登录态没有落下来时给个明确 code，避免通行证回调后看起来「没反应」
                return redirect('/login?pn=session_lost');
            }
            return render_page('platform.html', ['mode' => 'app']);
        }
        // v5.1.0：隐身巡查免登录渲染（API 拉数据走 ?_sv=，AuthCheck 放行）
        if (!session('user_id') && !TenantContext::isStealth()) {
            return redirect(t_url('/login'));
        }
        // v5.36.13：协作会话失效（授权撤销/到期）时不再渲染旧壳，直接回平台首页
        if (\app\service\Support::isActive() && \app\service\Support::enforce() !== null) {
            return redirect('/');
        }
        return render_page('app.html');
    }
}
