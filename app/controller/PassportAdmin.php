<?php
declare(strict_types=1);

namespace app\controller;

use app\BaseController;
use app\service\PassportClient;
use app\service\Setting;
use app\service\TenantContext;
use think\facade\Db;

/**
 * 平台后台：未晞通行证对接配置（v5.36.6）
 * 只有平台超管能读写；client_secret 只进不出（回显永远打码）。
 * 成员原有的密码登录不受影响，开启后只是登录/注册页多一个通行证入口。
 */
class PassportAdmin extends BaseController
{
    public function index()
    {
        if ($deny = $this->guard()) {
            return $deny;
        }
        $cfg = PassportClient::adminConfig();
        $cfg['probe'] = null;
        return api_ok($cfg);
    }

    public function save()
    {
        if ($deny = $this->guard()) {
            return $deny;
        }

        $issuer    = rtrim(trim((string)$this->request->post('issuer', '')), '/');
        $clientId  = trim((string)$this->request->post('client_id', ''));
        $secret    = trim((string)$this->request->post('client_secret', ''));
        $redirect  = trim((string)$this->request->post('redirect_uri', ''));
        $scope     = trim((string)$this->request->post('scope', ''));
        $enabled   = (int)$this->request->post('enabled', 0) === 1 ? '1' : '0';

        if ($issuer !== '' && ($e = PassportClient::validateIssuer($issuer))) {
            return api_err($e);
        }
        if ($clientId !== '' && (strlen($clientId) > 128 || !preg_match('/^[A-Za-z0-9._:-]+$/', $clientId))) {
            return api_err('Client ID 需为 1-128 位字母、数字或 . _ : -');
        }
        if ($secret !== '' && strlen($secret) > 255) {
            return api_err('Client Secret 最长 255 个字符');
        }
        if ($redirect === '') {
            $redirect = PassportClient::defaultCallback();
        }
        if ($e = PassportClient::validateRedirectUri($redirect)) {
            return api_err($e);
        }
        if ($scope === '') {
            $scope = PassportClient::SCOPE_DEFAULT;
        }
        if (strlen($scope) > 128 || !preg_match('/^[A-Za-z0-9_:. -]+$/', $scope)
            || !in_array('openid', preg_split('/\s+/', $scope), true)) {
            return api_err('Scope 必须包含 openid，且只能由字母、数字、下划线、冒号、点、空格与减号组成');
        }

        if ($enabled === '1') {
            if ($issuer === '') {
                return api_err('开启前请填写通行证站点地址');
            }
            if ($clientId === '') {
                return api_err('开启前请填写 Client ID');
            }
            if ($secret === '' && PassportClient::clientSecret() === '') {
                return api_err('第一次配置必须填写 Client Secret');
            }
            if (!PassportClient::tableReady()) {
                return api_err('passport_links 表还没建：请先打开 /passport_install_v5366.php 点一次补库');
            }
        }

        Setting::setPlatform('passport_issuer', $issuer);
        Setting::setPlatform('passport_client_id', $clientId);
        if ($secret !== '') {
            Setting::setPlatform('passport_client_secret', $secret);
        }
        Setting::setPlatform('passport_redirect_uri', $redirect);
        Setting::setPlatform('passport_scope', $scope);
        Setting::setPlatform('passport_enabled', $enabled);

        $this->log($enabled === '1' ? '开启未晞通行证' : '关闭未晞通行证', 'issuer=' . $issuer);
        return api_ok(PassportClient::adminConfig(), $enabled === '1' ? '已保存并开启' : '已保存（未开启）');
    }

    /** 测试连接：只报端点状态码，不读取任何响应内容 */
    public function test()
    {
        if ($deny = $this->guard()) {
            return $deny;
        }
        if (!PassportClient::ready()) {
            return api_err('请先填写完整的通行证配置（站点地址 / Client ID / Client Secret / 回调地址），并确认补库完成');
        }
        $probe = PassportClient::probeEndpoints();
        $ok = static function (array $r, array $allowed): bool {
            return $r['error'] === '' && in_array($r['status'], $allowed, true);
        };
        $summary = [
            'discovery' => $ok($probe['discovery'], [200]),
            'jwks'      => $ok($probe['jwks'], [200]),
            'authorize' => $ok($probe['authorize'], [200, 302, 400]),
            'userinfo'  => $ok($probe['userinfo'], [401, 403]),
        ];
        $msg = '通行证站点可达';
        if (!$summary['discovery'] || !$summary['jwks']) {
            $msg = '站点可达，但 OIDC discovery / JWKS 还没发布（当前仍可登录，签名只能靠 introspection + UserInfo 兜底）';
        }
        if (!$summary['authorize'] || !$summary['userinfo']) {
            $msg = '授权或用户信息端点不可达，请检查站点地址与网络';
        }
        return api_ok(['probe' => $probe, 'summary' => $summary, 'message' => $msg], $msg);
    }

    private function log(string $action, string $detail): void
    {
        try {
            Db::name('admin_logs')->insert([
                'tenant_id'  => 0,
                'admin_id'   => 0,
                'admin_name' => '平台超管',
                'action'     => $action,
                'detail'     => mb_substr($detail, 0, 300),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            // 日志失败不影响主流程
        }
    }

    private function guard()
    {
        if (!TenantContext::isPlatform()) {
            return api_err('接口不存在', 404, 404);
        }
        if (!is_platform_super()) {
            return api_err('仅平台超级管理员可以配置未晞通行证', 403, 403);
        }
        return null;
    }
}
