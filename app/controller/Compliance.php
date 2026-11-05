<?php
declare (strict_types = 1);

namespace app\controller;

use app\BaseController;
use app\service\Setting;
use app\service\TenantContext;
use think\facade\Db;

/**
 * v5.10.0：合规公开接口（隐私政策 / 服务条款 / Cookie 同意）
 *
 * 路由：
 *   GET  /privacy             平台隐私政策页
 *   GET  /terms               平台服务条款页
 *   GET  /{slug}/privacy      战队隐私政策页（未配置回落平台）
 *   GET  /{slug}/terms        战队服务条款页
 *   GET  /api/cookie-consent  查询横幅配置 + 当前访客已做的选择
 *   POST /api/cookie-consent  提交访客选择
 *
 * 数据源：
 *   平台级：settings 表 tenant_id=0，键 policies_privacy / policies_terms / cookie_consent_*
 *   战队级：settings 表 tenant_id=当前租户，同键；缺省则回落到平台值
 *
 * 隐私政策/服务条款正文按纯文本存储，输出时 nl2br + htmlspecialchars，不接受 HTML。
 */
class Compliance extends BaseController
{
    // ==================== 公开政策页面 ====================

    /** 隐私政策页 */
    public function privacy()
    {
        return $this->renderPolicy('privacy');
    }

    /** 服务条款页 */
    public function terms()
    {
        return $this->renderPolicy('terms');
    }

    // ==================== Cookie 同意 API ====================

    /**
     * GET /api/cookie-consent
     * 返回：{enabled, text, choice, policy_version, privacy_url, terms_url}
     * 无需登录；choice 从 cookie_consent 表按访客标识查询
     */
    public function consentGet()
    {
        $enabled = Setting::getPlatform('cookie_consent_enabled', '1') === '1';
        $text    = Setting::getPlatform('cookie_consent_text', '本网站使用必要的 Cookie 保障登录与安全，并在您同意后使用统计类 Cookie 改善体验。');
        $ver     = Setting::getPlatform('policies_updated_at', '');
        $base    = TenantContext::base();

        $choice = '';
        if ($enabled) {
            $choice = $this->lookupConsentChoice();
        }
        return api_ok([
            'enabled'        => $enabled,
            'text'           => $text,
            'choice'         => $choice,
            'policy_version' => $ver,
            'privacy_url'    => $base . '/privacy',
            'terms_url'      => $base . '/terms',
            'is_platform'    => TenantContext::isPlatform(),
            'site_name'      => TenantContext::isPlatform()
                ? (Setting::getPlatform('seo_site_title', '战队入驻平台'))
                : TenantContext::name(),
        ]);
    }

    /**
     * POST /api/cookie-consent
     * body: {choice: 'accepted'|'rejected'|'necessary_only'}
     * 无需登录；同 (tenant_id, user_id, user_hash) 重复提交走 UPDATE
     */
    public function consentSubmit()
    {
        if (Setting::getPlatform('cookie_consent_enabled', '1') !== '1') {
            return api_err('Cookie 同意功能已停用', 400, 400);
        }
        $choice = strtolower(trim((string)$this->request->post('choice', '')));
        if (!in_array($choice, ['accepted', 'rejected', 'necessary_only'], true)) {
            return api_err('choice 参数不合法（accepted / rejected / necessary_only）');
        }

        $ip       = client_ip();
        $ua       = mb_substr((string)$this->request->header('user-agent', ''), 0, 255);
        $tenantId = TenantContext::id();
        $userId   = $this->currentUserId();
        $hash     = $userId > 0 ? '' : hash('sha256', $ip . '|' . $ua);
        $ver      = Setting::getPlatform('policies_updated_at', '');
        $now      = date('Y-m-d H:i:s');

        try {
            $exists = Db::name('cookie_consent')
                ->where('tenant_id', $tenantId)
                ->where('user_id', $userId)
                ->where('user_hash', $hash)
                ->find();
            if ($exists) {
                Db::name('cookie_consent')->where('id', $exists['id'])->update([
                    'choice'     => $choice,
                    'ip'         => $ip,
                    'user_agent' => $ua,
                    'policy_ver' => $ver,
                    'updated_at' => $now,
                ]);
            } else {
                Db::name('cookie_consent')->insert([
                    'tenant_id'  => $tenantId,
                    'user_id'    => $userId,
                    'user_hash'  => $hash,
                    'choice'     => $choice,
                    'ip'         => $ip,
                    'user_agent' => $ua,
                    'policy_ver' => $ver,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        } catch (\Throwable $e) {
            // 表未建（未升级）不阻断响应，前端本地 Cookie 依然生效
            \think\facade\Log::warning('[CookieConsent] ' . $e->getMessage());
        }

        return api_ok(['choice' => $choice], '感谢您的选择');
    }

    // ==================== 内部工具 ====================

    /** 渲染 policy.html（privacy / terms 通用） */
    protected function renderPolicy(string $kind)
    {
        $isPrivacy = $kind === 'privacy';
        $key       = $isPrivacy ? 'policies_privacy' : 'policies_terms';
        $title     = $isPrivacy ? '隐私政策' : '服务条款';

        // 战队上下文：先看战队自己的 settings，未设回落平台
        $content = '';
        $siteName = '';
        $updatedAt = Setting::getPlatform('policies_updated_at', '');
        if (TenantContext::isPlatform()) {
            $content  = Setting::getPlatform($key, '');
            $siteName = Setting::getPlatform('seo_site_title', '战队入驻平台');
        } else {
            $content  = Setting::get($key, '');
            if ($content === '') {
                $content = Setting::getPlatform($key, '');
            }
            $siteName = TenantContext::name();
        }

        $base = TenantContext::base();
        return render_page('policy.html', [
            'title'       => $title . ' · ' . $siteName,
            'siteName'    => $siteName,
            'updatedAt'   => $updatedAt !== '' ? substr($updatedAt, 0, 10) : '',
            'contentHtml' => $this->renderPlainText($content),
            'backUrl'     => $base === '' ? '/' : $base . '/',
            'privacyUrl'  => $base . '/privacy',
            'termsUrl'    => $base . '/terms',
        ]);
    }

    /** 纯文本转安全 HTML：先转义再换行；空文本返回空串 */
    protected function renderPlainText(string $text): string
    {
        $text = trim($text);
        if ($text === '') return '';
        return nl2br(htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /** 查当前访客的已存选择（有则回，无则空串） */
    protected function lookupConsentChoice(): string
    {
        try {
            $ip       = client_ip();
            $ua       = mb_substr((string)$this->request->header('user-agent', ''), 0, 255);
            $tenantId = TenantContext::id();
            $userId   = $this->currentUserId();
            $hash     = $userId > 0 ? '' : hash('sha256', $ip . '|' . $ua);
            $row = Db::name('cookie_consent')
                ->where('tenant_id', $tenantId)
                ->where('user_id', $userId)
                ->where('user_hash', $hash)
                ->field('choice')
                ->find();
            return $row ? (string)$row['choice'] : '';
        } catch (\Throwable $e) {
            return '';
        }
    }

    /** 当前登录用户 ID（兼容平台账号与战队账号；未登录=0） */
    protected function currentUserId(): int
    {
        if (TenantContext::isPlatform()) {
            return (int)(session('platform_user_id') ?: 0);
        }
        return (int)(session('user_id') ?: 0);
    }
}
