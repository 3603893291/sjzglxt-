<?php
declare (strict_types = 1);

namespace app\controller;

use app\BaseController;
use app\service\Support as SupportSvc;
use app\service\TenantContext;

/**
 * 平台协作（v5.36.13）
 *
 * 战队端：队长授权/撤销、本次登录暂不授权、退出协作。
 * 进入协作的入口 /{slug}/support/enter 是一次性票据兑票，不需要本站登录态。
 */
class Support extends BaseController
{
    /** 当前协作状态（队长弹窗与「管理中心 → 平台协作」共用） */
    public function status()
    {
        if (TenantContext::isPlatform()) {
            return api_err('接口不存在', 404, 404);
        }
        $user = current_user();
        if (!$user) {
            return api_err('未登录', 401, 401);
        }
        return api_ok(SupportSvc::statusForClient($user));
    }

    /** 队长同意授权（长期有效，可随时撤销） */
    public function grant()
    {
        if (TenantContext::isPlatform()) {
            return api_err('接口不存在', 404, 404);
        }
        $user = current_user();
        if (!$user || $user->role !== 'admin' || !is_tenant_owner($user)) {
            return api_err('只有战队队长本人可以授权平台协作', 403, 403);
        }
        if (SupportSvc::isActive()) {
            return api_err('平台协作模式下不能修改授权', 403, 403);
        }
        $info = SupportSvc::grantInfo(TenantContext::id());
        if (!$info['available']) {
            return api_err('平台协作数据表尚未安装，请先执行升级 SQL', 500);
        }
        SupportSvc::grant(TenantContext::id(), (int)$user->id);
        session('support_prompt_dismissed', false);
        admin_log('平台协作授权', '同意平台超管进入本站管理中心协助操作（长期有效，可随时撤销）');
        return api_ok(SupportSvc::statusForClient($user), '已授权：平台超管可以进入本站管理中心协助操作');
    }

    /** 队长撤销授权：立即结束所有在用的协作会话 */
    public function revoke()
    {
        if (TenantContext::isPlatform()) {
            return api_err('接口不存在', 404, 404);
        }
        $user = current_user();
        if (!$user || $user->role !== 'admin' || !is_tenant_owner($user)) {
            return api_err('只有战队队长本人可以撤销平台协作', 403, 403);
        }
        if (SupportSvc::isActive()) {
            return api_err('平台协作模式下不能修改授权', 403, 403);
        }
        $info = SupportSvc::grantInfo(TenantContext::id());
        if (!$info['available']) {
            return api_err('平台协作数据表尚未安装，请先执行升级 SQL', 500);
        }
        if (!$info['granted']) {
            return api_err('当前没有生效中的平台协作授权');
        }
        $n = SupportSvc::revoke(TenantContext::id());
        session('support_prompt_dismissed', true);
        admin_log('平台协作撤销', '撤销平台超管协作授权' . ($n > 0 ? '，结束 ' . $n . ' 个在用协作会话' : ''));
        return api_ok(SupportSvc::statusForClient($user),
            '已撤销授权' . ($n > 0 ? '，正在协作的会话已立即失效' : ''));
    }

    /** 本次登录暂不授权（只记本次会话，下次登录仍会弹） */
    public function dismiss()
    {
        if (TenantContext::isPlatform()) {
            return api_err('接口不存在', 404, 404);
        }
        $user = current_user();
        if (!$user || $user->role !== 'admin' || !is_tenant_owner($user)) {
            return api_err('只有战队队长本人可以处理平台协作授权', 403, 403);
        }
        session('support_prompt_dismissed', true);
        return api_ok([], '本次登录不再提示；下次登录仍会询问');
    }

    /** 平台超管主动退出协作 */
    public function exitSupport()
    {
        if (TenantContext::isPlatform()) {
            return api_err('接口不存在', 404, 404);
        }
        if (!SupportSvc::isActive()) {
            return api_err('当前不在平台协作模式', 400, 400);
        }
        SupportSvc::audit(TenantContext::id(), '结束协作', '平台超管主动退出本站管理中心',
            SupportSvc::auditIdentity() ?: '平台协作');
        SupportSvc::endLocal();
        return api_ok(['url' => '/'], '已退出平台协作');
    }

    /**
     * 入口：/ {slug}/support/enter?token=...
     * 兑票 → 换成站长身份 + 协作会话标记 → 302 到管理中心（令牌不留在浏览器历史里）。
     */
    public function enter()
    {
        if (TenantContext::isPlatform()) {
            return $this->enterError('入口地址不完整，请回到平台控制台重新点「进入协作」');
        }
        $token = trim((string)$this->request->get('token', ''));
        if ($token === '') {
            return $this->enterError('缺少协作票据，请回到平台控制台重新点「进入协作」');
        }
        try {
            $redeemed = SupportSvc::redeemTicket(TenantContext::id(), $token);
        } catch (\Throwable $e) {
            return $this->enterError('平台协作数据表尚未安装或读取失败，请先执行升级 SQL');
        }
        if ($redeemed === null) {
            return $this->enterError('协作票据无效、已使用或已过期（有效期 5 分钟），请回到平台控制台重新进入');
        }
        $owner = SupportSvc::ownerUser(TenantContext::id());
        if (!$owner || (string)$owner->status !== 'approved') {
            return $this->enterError('战队站长账号当前不可用，无法进入协作');
        }

        $tid = TenantContext::id();
        $sid = SupportSvc::startSession($tid, (int)$redeemed['platform_user_id']);
        SupportSvc::beginInTenant($sid, (int)$redeemed['platform_user_id'], $owner);
        SupportSvc::audit($tid, '开始协作',
            '平台超管#' . (int)$redeemed['platform_user_id'] . ' 进入本站管理中心 · 访客 ' . client_ip(),
            SupportSvc::auditIdentity() ?: '平台协作');

        return redirect('/' . TenantContext::slug() . '/app#/admin');
    }

    private function enterError(string $msg)
    {
        $safe = htmlspecialchars($msg, ENT_QUOTES, 'UTF-8');
        return response(
            '<!DOCTYPE html><html lang="zh"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>平台协作</title></head>'
            . '<body style="margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;'
            . 'background:#f5f5f7;color:#1d1d1f;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',sans-serif">'
            . '<div style="max-width:520px;margin:24px;padding:28px;background:#fff;border:1px solid #e8e8ed;'
            . 'border-radius:16px;text-align:center">'
            . '<h1 style="font-size:20px;margin:0 0 12px">无法进入平台协作</h1>'
            . '<p style="color:#6e6e73;margin:0 0 20px;line-height:1.7">' . $safe . '</p>'
            . '<a href="/" style="display:inline-block;padding:10px 22px;border-radius:999px;background:#0066cc;'
            . 'color:#fff;text-decoration:none">返回平台控制台</a>'
            . '</div></body></html>',
            403
        )->contentType('text/html; charset=utf-8');
    }
}
