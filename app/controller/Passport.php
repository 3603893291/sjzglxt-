<?php
declare(strict_types=1);

namespace app\controller;

use app\BaseController;
use app\model\PlatformUser;
use app\model\User;
use app\service\AuthSession;
use app\service\PassportClient;
use app\service\PlatformAuth;
use app\service\TenantContext;
use think\facade\Cache;
use think\facade\Cookie;
use think\facade\Db;
use think\facade\Log;
use think\facade\Session;

/**
 * 未晞通行证登录入口（v5.36.6）
 *
 * 本站原有密码注册/登录全部保留；通行证只是登录页与注册页上的额外入口。
 * 回调固定挂在根路径 /passport/callback（通行证应用只登记这一条地址），
 * 但会话是按战队目录隔离的（PHPSESSID_t{id}）——所以回调不直接写登录态，
 * 而是签发一张 2 分钟、一次性的兑换票，跳到 /{slug}/passport/finish 再落会话。
 * 第一次使用的通行证账号没有绑定本站账号时，跳到 /{slug}/passport/claim，
 * 由用户输入本站账号密码确认身份后再绑定，不自动建号、不按邮箱猜账号。
 */
class Passport extends BaseController
{
    /** 登录页提示（只认固定 code，不把外部错误原文带到页面上） */
    public const NOTICES = [
        'denied' => [
            'tone'    => 'warning',
            'title'   => '已取消授权',
            'message' => '你取消了未晞通行证授权，还没有登录。',
        ],
        'state' => [
            'tone'    => 'warning',
            'title'   => '登录请求已过期',
            'message' => '请重新点一次「使用未晞通行证登录」。',
        ],
        'config' => [
            'tone'    => 'error',
            'title'   => '通行证尚未配置',
            'message' => '站点管理员还没有完成未晞通行证对接，请联系平台管理员。',
        ],
        'banned' => [
            'tone'    => 'error',
            'title'   => '账号已被封禁',
            'message' => '当前账号不能登录，请联系战队管理员。',
        ],
        'pending' => [
            'tone'    => 'info',
            'title'   => '账号还在审核中',
            'message' => '队长审核通过后，再用未晞通行证登录即可进入战队。',
        ],
        'link_conflict' => [
            'tone'    => 'error',
            'title'   => '绑定没有完成',
            'message' => '这个通行证账号或当前成员已经绑定了别的账号，请先在个人中心处理。',
        ],
        'login_first' => [
            'tone'    => 'info',
            'title'   => '请先登录',
            'message' => '先用本站账号登录，再到个人中心绑定通行证。',
        ],
        'failed' => [
            'tone'    => 'error',
            'title'   => '登录失败',
            'message' => '未晞通行证校验没有通过，请稍后重试或联系管理员。',
        ],
        // v5.36.8：入驻平台一键登录。通行证要先在某个战队绑成超管，平台才能认出对应的入驻账号。
        'admin_needed' => [
            'tone'    => 'warning',
            'title'   => '这个通行证还没有绑定战队超管',
            'message' => '平台一键登录只对「战队超管账号绑过通行证」对应的入驻平台账号生效；平台超管账号请直接用密码登录。',
        ],
        'admin_multi' => [
            'tone'    => 'warning',
            'title'   => '无法确定要进入哪个入驻账号',
            'message' => '这个通行证绑定在多个战队的超管账号上，请先用平台账号密码登录。',
        ],
        'disabled' => [
            'tone'    => 'error',
            'title'   => '平台账号已被停用',
            'message' => '这个通行证对应的入驻账号已停用，请联系平台管理员。',
        ],
        'session_lost' => [
            'tone'    => 'warning',
            'title'   => '登录状态没有保存',
            'message' => '浏览器没有保住登录 Cookie。请允许本站 Cookie 后重新点一次通行证登录，或改用账号密码登录。',
        ],
    ];

    public static function notice(?string $code): ?array
    {
        $code = (string)$code;
        return $code !== '' && isset(self::NOTICES[$code]) ? self::NOTICES[$code] : null;
    }

    /** 登录/注册页用：是否开启/是否可用（不含密钥）；战队页与平台登录页共用 */
    public function config()
    {
        return api_ok(PassportClient::publicConfig());
    }

    /**
     * 入驻平台「一键登录」（v5.36.8）
     * 通行证必须先绑到某支战队的超管账号；回调时按这层绑定找到对应的平台入驻账号。
     */
    public function platformStart()
    {
        if (!TenantContext::isPlatform()) {
            abort(404);
        }
        if (!PassportClient::configured()) {
            return $this->fail(0, '', 'config');
        }
        if ((int)session('platform_user_id') > 0) {
            return redirect('/app');
        }

        $state    = PassportClient::randomToken(24);
        $nonce    = PassportClient::randomToken(24);
        $verifier = PassportClient::randomToken(48);

        // 防会话固定：在发起授权这个第一方响应里就换好 Session ID。
        // 回调是从通行证站点跳回来的跨站响应，部分浏览器会拦那次 Set-Cookie；
        // 如果等到回调成功再换 ID，新 ID 没存下，/app 就读不到平台登录态。
        Session::setId(bin2hex(random_bytes(16)));
        session('passport_platform_started_at', time());
        $sid = (string)Session::getId();

        Cache::set($this->stateKey($state), [
            'mode'       => 'platform',
            'nonce'      => $nonce,
            'verifier'   => $verifier,
            'sid_hash'   => hash('sha256', $sid),
            'created_at' => time(),
        ], PassportClient::STATE_TTL);

        return redirect(PassportClient::authorizeUrl($state, $nonce, PassportClient::codeChallenge($verifier)));
    }

    /** 发起授权（登录） */
    public function start()
    {
        if (TenantContext::isPlatform()) {
            abort(404);
        }
        return $this->begin(false);
    }

    /** 个人中心「绑定通行证」入口 */
    public function link()
    {
        if (TenantContext::isPlatform()) {
            abort(404);
        }
        // v5.36.13：协作模式不能把平台账号的通行证绑到站长账号上（退出后仍能冒充登录）
        if (\app\service\Support::isActive()) {
            return redirect(t_url('/app#/admin'));
        }
        if (!session('user_id')) {
            return redirect(t_url('/login?pn=login_first'));
        }
        return $this->begin(true);
    }

    protected function begin(bool $linkMode)
    {
        $tid  = TenantContext::id();
        $slug = TenantContext::slug();
        if (!PassportClient::configured()) {
            return $this->fail($tid, $slug, 'config');
        }

        $linkUser = $linkMode ? (int)session('user_id') : 0;
        if (!$linkMode && (int)session('user_id') > 0) {
            // 已经登录的人点登录入口，直接回站内
            return redirect(t_url('/app'));
        }

        $defaultReturn = $linkMode ? t_url('/app#/profile') : '';
        $return = PassportClient::safeReturn((string)$this->request->get('return', $defaultReturn), $tid);
        $remember = (int)$this->request->get('remember', 0) === 1 ? 1 : 0;

        $state    = PassportClient::randomToken(24);
        $nonce    = PassportClient::randomToken(24);
        $verifier = PassportClient::randomToken(48);

        // 先往本战队会话里写一个值，确保浏览器真的拿到 PHPSESSID_t{tid}；
        // 否则回调时读不到会话 cookie，state 的 sid 绑定会把自己判成过期。
        session('passport_started_at', time());
        $sid = (string)Session::getId();

        $data = [
            'tenant_id'    => $tid,
            'slug'         => $slug,
            'nonce'        => $nonce,
            'verifier'     => $verifier,
            'return'       => $return,
            'remember'     => $remember,
            'link_user_id' => $linkUser,
            'sid_hash'     => hash('sha256', $sid),
            'created_at'   => time(),
        ];
        Cache::set($this->stateKey($state), $data, PassportClient::STATE_TTL);
        Cookie::set('passport_tenant', $tid . '.' . $slug, [
            'expire'   => PassportClient::STATE_TTL,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'lax',
        ]);

        return redirect(PassportClient::authorizeUrl($state, $nonce, PassportClient::codeChallenge($verifier)));
    }

    /**
     * 通行证回调（根路径，平台会话）。
     * 战队登录只签发一次性兑换票，不在这个上下文里写登录态；
     * 入驻平台一键登录的会话就在根路径上，所以验完身份直接落平台登录态。
     */
    public function callback()
    {
        if (!TenantContext::isPlatform()) {
            abort(404);
        }

        $state = trim((string)$this->request->get('state', ''));
        $data  = $state !== '' ? Cache::pull($this->stateKey($state)) : null;

        if (!is_array($data)) {
            [$tid, $slug] = $this->tenantFromCookie();
            return $this->fail($tid, $slug, 'state');
        }
        if (time() - (int)($data['created_at'] ?? 0) > PassportClient::STATE_TTL) {
            return $this->fail((int)($data['tenant_id'] ?? 0), (string)($data['slug'] ?? ''), 'state');
        }
        if ((string)($data['mode'] ?? '') === 'platform') {
            return $this->callbackPlatform($data);
        }

        $tid  = (int)($data['tenant_id'] ?? 0);
        $slug = (string)($data['slug'] ?? '');
        if ($tid <= 0 || $slug === '') {
            return $this->fail($tid, $slug, 'state');
        }

        // 授权请求必须由同一个战队会话发起：防止把别人的回调地址塞给受害者（登录 CSRF）
        $sid = (string)$this->request->cookie('PHPSESSID_t' . $tid, '');
        if ($sid === '' || !hash_equals((string)($data['sid_hash'] ?? ''), hash('sha256', $sid))) {
            return $this->fail($tid, $slug, 'state');
        }

        $auth = $this->completeAuthorization($data);
        if (!$auth['ok']) {
            return $this->fail($tid, $slug, (string)$auth['code']);
        }
        $sub    = (string)$auth['sub'];
        $ui     = (array)$auth['ui'];
        $claims = (array)$auth['claims'];

        $tenant = Db::name('tenants')->where('id', $tid)->where('status', 'approved')->find();
        if (!$tenant) {
            return $this->fail($tid, $slug, 'failed');
        }
        TenantContext::set([
            'id'     => $tid,
            'slug'   => (string)$tenant['slug'],
            'name'   => (string)$tenant['name'],
            'logo'   => (string)$tenant['logo'],
            'status' => (string)$tenant['status'],
        ]);

        $linkUid = (int)($data['link_user_id'] ?? 0);
        if ($linkUid > 0) {
            $user = User::find($linkUid);
            if (!$user || $user->status !== 'approved') {
                return $this->fail($tid, $slug, 'failed');
            }
            $err = PassportClient::bindUser($tid, $linkUid, $sub);
            if ($err !== null) {
                Log::warning('[passport] bind: ' . $err);
                return $this->fail($tid, $slug, 'link_conflict');
            }
            return redirect('/' . $slug . '/app#/profile');
        }

        $res = PassportClient::resolveUser($tid, $sub);
        if ($res['ok']) {
            $ticket = $this->issueLoginTicket($tid, $slug, (int)$res['user']->id, (string)($data['return'] ?? ''),
                (int)($data['remember'] ?? 0) === 1 ? 1 : 0, true, $sid);
            return redirect('/' . $slug . '/passport/finish?ticket=' . urlencode($ticket));
        }
        if (!empty($res['needs_bind'])) {
            $claim = PassportClient::randomToken(32);
            Cache::set($this->claimKey($claim), [
                'tenant_id' => $tid,
                'slug'      => $slug,
                'sub'       => $sub,
                'profile'   => [
                    'name'               => (string)($ui['name'] ?? $claims['name'] ?? ''),
                    'preferred_username' => (string)($ui['preferred_username'] ?? $claims['preferred_username'] ?? ''),
                    'email'              => (string)($ui['email'] ?? $claims['email'] ?? ''),
                ],
                'return'    => (string)($data['return'] ?? ''),
                'remember'  => (int)($data['remember'] ?? 0) === 1 ? 1 : 0,
                'sid_hash'  => hash('sha256', $sid),
            ], PassportClient::CLAIM_TTL);
            return redirect('/' . $slug . '/passport/claim?ticket=' . urlencode($claim));
        }

        Log::warning('[passport] resolve: ' . ($res['error'] ?? ''));
        $errMsg = (string)($res['error'] ?? '');
        $codeOut = strpos($errMsg, '审核') !== false ? 'pending'
            : (strpos($errMsg, '封禁') !== false ? 'banned' : 'failed');
        return $this->fail($tid, $slug, $codeOut);
    }

    /**
     * 入驻平台一键登录：通行证 → 某支战队的超管账号 → 该战队的入驻平台账号。
     * 不改平台账号体系，平台超管与还没有战队超管绑定的账号用不了这条路。
     */
    private function callbackPlatform(array $data)
    {
        $sid = (string)$this->request->cookie('PHPSESSID', '');
        if ($sid === '' || !hash_equals((string)($data['sid_hash'] ?? ''), hash('sha256', $sid))) {
            return $this->fail(0, '', 'state');
        }

        $auth = $this->completeAuthorization($data);
        if (!$auth['ok']) {
            return $this->fail(0, '', (string)$auth['code']);
        }

        $res = $this->platformAccountForSub((string)$auth['sub']);
        if ($res['code'] !== '') {
            return $this->fail(0, '', $res['code']);
        }

        // Session ID 已在 platformStart 的第一方响应里换过，这里沿用，不再设置跨站 Set-Cookie
        PlatformAuth::finalize($res['user'], 'passport', false);
        return redirect('/app');
    }

    /** @return array{code:string,user?:PlatformUser} */
    private function platformAccountForSub(string $sub): array
    {
        try {
            $rows = Db::name('passport_links')->alias('l')
                ->join('tenants t', 't.admin_user_id = l.user_id AND t.id = l.tenant_id')
                ->join('users u', 'u.id = l.user_id AND u.tenant_id = l.tenant_id')
                ->where('l.passport_sub', $sub)
                ->where('l.tenant_id', '>', 0)
                ->where('u.status', 'approved')
                ->field('t.applicant_id')
                ->select()->toArray();
        } catch (\Throwable $e) {
            Log::warning('[passport] platform resolve: ' . $e->getMessage());
            return ['code' => 'failed'];
        }

        $ids = [];
        foreach ($rows as $row) {
            $id = (int)($row['applicant_id'] ?? 0);
            if ($id > 0) {
                $ids[$id] = true;
            }
        }
        if (!$ids) {
            return ['code' => 'admin_needed'];
        }
        if (count($ids) > 1) {
            return ['code' => 'admin_multi'];
        }

        $u = PlatformUser::find((int)array_key_first($ids));
        if (!$u || $u->role !== 'team') {
            return ['code' => 'admin_needed'];
        }
        if ($u->status !== 'normal') {
            return ['code' => 'disabled'];
        }
        return ['code' => '', 'user' => $u];
    }

    /** 换码 + 验 id_token + 取 UserInfo + introspection，返回已核验的通行证身份 */
    private function completeAuthorization(array $data): array
    {
        if ((string)$this->request->get('error', '') !== '') {
            return ['ok' => false, 'code' => 'denied'];
        }
        $code = trim((string)$this->request->get('code', ''));
        if ($code === '') {
            return ['ok' => false, 'code' => 'failed'];
        }
        if (!PassportClient::configured()) {
            return ['ok' => false, 'code' => 'config'];
        }

        $tok = PassportClient::exchangeCode($code, (string)($data['verifier'] ?? ''));
        if (!$tok['ok']) {
            Log::warning('[passport] token exchange: ' . $tok['error']);
            return ['ok' => false, 'code' => 'failed'];
        }

        $idt = PassportClient::verifyIdToken((string)$tok['data']['id_token'], (string)($data['nonce'] ?? ''));
        if (!$idt['ok']) {
            Log::warning('[passport] id_token: ' . $idt['error']);
            return ['ok' => false, 'code' => 'failed'];
        }

        $ui = PassportClient::userinfo((string)$tok['data']['access_token']);
        if (!$ui['ok']) {
            Log::warning('[passport] userinfo: ' . $ui['error']);
            return ['ok' => false, 'code' => 'failed'];
        }
        if ((string)$ui['data']['sub'] !== (string)$idt['claims']['sub']) {
            Log::warning('[passport] sub mismatch between id_token and userinfo');
            return ['ok' => false, 'code' => 'failed'];
        }

        $intro = PassportClient::introspect((string)$tok['data']['access_token']);
        if (!$intro['ok']) {
            Log::warning('[passport] introspect: ' . ($intro['error'] ?? ''));
            return ['ok' => false, 'code' => 'failed'];
        }

        return [
            'ok'     => true,
            'sub'    => (string)$idt['claims']['sub'],
            'ui'     => (array)$ui['data'],
            'claims' => (array)$idt['claims'],
        ];
    }

    /** 首次使用：通行证已验证，但还没有绑定本站账号 */
    public function claim()
    {
        if (TenantContext::isPlatform()) {
            abort(404);
        }
        if (\app\service\Support::isActive()) {
            return redirect(t_url('/app#/admin'));
        }
        $ticket = trim((string)$this->request->get('ticket', ''));
        $data   = $ticket !== '' ? Cache::get($this->claimKey($ticket)) : null;
        if (!is_array($data) || (int)($data['tenant_id'] ?? 0) !== TenantContext::id()
            || !hash_equals((string)($data['sid_hash'] ?? ''), hash('sha256', (string)Session::getId()))) {
            return $this->fail(TenantContext::id(), TenantContext::slug(), 'state');
        }
        return $this->claimPage($ticket, $data);
    }

    /** 首次使用：输入本站账号密码，确认身份后绑定并登录 */
    public function claimSubmit()
    {
        if (TenantContext::isPlatform()) {
            abort(404);
        }
        if (\app\service\Support::isActive()) {
            return api_err('平台协作模式下不能绑定通行证', 403, 403);
        }
        $tid    = TenantContext::id();
        $slug   = TenantContext::slug();
        $ticket = trim((string)$this->request->post('ticket', ''));
        $data   = $ticket !== '' ? Cache::get($this->claimKey($ticket)) : null;
        if (!is_array($data) || (int)($data['tenant_id'] ?? 0) !== $tid
            || !hash_equals((string)($data['sid_hash'] ?? ''), hash('sha256', (string)Session::getId()))) {
            return $this->fail($tid, $slug, 'state');
        }

        $username = trim((string)$this->request->post('username', ''));
        $password = (string)$this->request->post('password', '');
        $failKey  = 'passport_claim_fail_' . hash('sha256', $ticket);
        // 绑定时要验本站密码，必须和密码登录一样防爆破：
        // 单张票据 6 次、同 IP 5 次/15 分钟、同账号 5 次/15 分钟
        $ipKey   = 'passport_claim_ip_' . md5(client_ip());
        $userKey = 'passport_claim_user_' . md5($tid . ':' . mb_strtolower($username));
        if ((int)Cache::get($failKey, 0) >= 6
            || (int)Cache::get($ipKey, 0) >= 5
            || ($username !== '' && (int)Cache::get($userKey, 0) >= 5)) {
            return $this->claimPage($ticket, $data, '尝试次数过多，请稍后重试，或直接使用本站账号密码登录', $username);
        }

        $res = PassportClient::bindWithPassword($tid, (string)$data['sub'], $username, $password);
        if (!$res['ok']) {
            // 只有「密码不对」计入爆破次数；待审/封禁/已绑了别的号不算猜密码
            if (!empty($res['bad_credentials'])) {
                rate_bump($failKey, 600);
                rate_bump($ipKey, 900);
                if ($username !== '') {
                    rate_bump($userKey, 900);
                }
            }
            return $this->claimPage($ticket, $data, (string)($res['error'] ?? '绑定失败，请重试'), $username);
        }

        Cache::delete($this->claimKey($ticket));
        Cache::delete($failKey);
        Cache::delete($userKey);
        $user = $res['user'];
        AuthSession::finalize($user, 'passport', true, (int)($data['remember'] ?? 0) === 1 ? 1 : 0);
        $return = PassportClient::safeReturn((string)($data['return'] ?? ''), $tid);
        return redirect($return);
    }

    /** 战队目录内完成登录（这里的会话名已经按租户隔离） */
    public function finish()
    {
        if (TenantContext::isPlatform()) {
            abort(404);
        }
        $tid  = TenantContext::id();
        $slug = TenantContext::slug();
        $ticket = trim((string)$this->request->get('ticket', ''));
        $data   = $ticket !== '' ? Cache::pull($this->ticketKey($ticket)) : null;
        if (!is_array($data)) {
            return $this->fail($tid, $slug, 'state');
        }
        if ((int)($data['tenant_id'] ?? 0) !== $tid) {
            return $this->fail($tid, $slug, 'state');
        }
        $sid = (string)Session::getId();
        if (!hash_equals((string)($data['sid_hash'] ?? ''), hash('sha256', $sid))) {
            return $this->fail($tid, $slug, 'state');
        }

        $user = User::find((int)($data['user_id'] ?? 0));
        if (!$user) {
            return $this->fail($tid, $slug, 'failed');
        }
        if ($user->status === 'pending') {
            return $this->fail($tid, $slug, 'pending');
        }
        if ($user->status !== 'approved') {
            return $this->fail($tid, $slug, 'banned');
        }

        $manage = (bool)($data['manage_remember'] ?? true);
        $remember = (int)($data['remember'] ?? 0) === 1 ? 1 : 0;
        AuthSession::finalize($user, 'passport', $manage, $remember);

        $return = PassportClient::safeReturn((string)($data['return'] ?? ''), $tid);
        return redirect($return);
    }

    /** 个人中心：当前账号的通行证绑定状态 */
    public function binding()
    {
        if (TenantContext::isPlatform()) {
            return api_err('接口不存在', 404, 404);
        }
        $user = current_user();
        if (!$user) {
            return api_err('未登录', 401, 401);
        }
        $binding = PassportClient::bindingOf(TenantContext::id(), (int)$user->id);
        return api_ok([
            'bound'      => $binding !== null,
            'sub_masked' => $binding['sub_masked'] ?? '',
            'created_at' => $binding['created_at'] ?? '',
            'enabled'    => PassportClient::enabled(),
            'ready'      => PassportClient::ready(),
            'table_ready'=> PassportClient::tableReady(),
            'link_url'   => t_url('/passport/link'),
        ]);
    }

    /** 个人中心：解除当前账号的通行证绑定（本站账号密码仍可登录） */
    public function unbind()
    {
        if (TenantContext::isPlatform()) {
            return api_err('接口不存在', 404, 404);
        }
        $user = current_user();
        if (!$user) {
            return api_err('未登录', 401, 401);
        }
        $bound = PassportClient::bindingOf(TenantContext::id(), (int)$user->id);
        if ($bound === null) {
            return api_err('当前账号还没有绑定通行证');
        }
        if (!PassportClient::unbindUser(TenantContext::id(), (int)$user->id)) {
            return api_err('解除绑定失败，请稍后重试');
        }
        return api_ok([], '已解除绑定：以后请用本站账号密码登录');
    }

    // ==================== 内部 ====================

    private function stateKey(string $state): string
    {
        return 'passport_state_' . hash('sha256', $state);
    }

    private function ticketKey(string $ticket): string
    {
        return 'passport_ticket_' . hash('sha256', $ticket);
    }

    private function claimKey(string $ticket): string
    {
        return 'passport_claim_' . hash('sha256', $ticket);
    }

    private function issueLoginTicket(int $tid, string $slug, int $uid, string $return, int $remember, bool $manage, string $tenantSessionId): string
    {
        $ticket = PassportClient::randomToken(32);
        Cache::set($this->ticketKey($ticket), [
            'tenant_id'       => $tid,
            'slug'            => $slug,
            'user_id'         => $uid,
            'return'          => $return,
            'remember'        => $remember,
            'manage_remember' => $manage,
            'sid_hash'        => hash('sha256', $tenantSessionId),
        ], 120);
        return $ticket;
    }

    private function claimPage(string $ticket, array $data, string $error = '', string $username = '')
    {
        $profile = (array)($data['profile'] ?? []);
        $name = trim((string)($profile['name'] ?? ''));
        if ($name === '') {
            $name = trim((string)($profile['preferred_username'] ?? ''));
        }
        if ($name === '') {
            $name = '通行证用户';
        }

        $pref = trim((string)($profile['preferred_username'] ?? ''));
        $hint = '';
        if ($pref !== '' && strlen($pref) <= 32 && User::where('username', $pref)->find()) {
            $hint = $pref;
        }
        if ($username === '') {
            $username = $hint;
        }

        return render_page('passport_claim.html', [
            'name'         => mb_substr($name, 0, 32),
            'tenant'       => TenantContext::name(),
            'ticket'       => $ticket,
            'error'        => $error,
            'username'     => $username,
            'hintUsername' => $hint,
            'action'       => t_url('/passport/claim'),
            'login'        => t_url('/login'),
            'register'     => t_url('/register'),
        ]);
    }

    /** 失败时回战队登录页，只带固定 code（不把外部错误原文反射到页面） */
    private function fail(int $tid, string $slug, string $code)
    {
        if (!isset(self::NOTICES[$code])) {
            $code = 'failed';
        }
        if ($tid > 0 && $slug !== '') {
            return redirect('/' . $slug . '/login?pn=' . urlencode($code));
        }
        return redirect('/login?pn=' . urlencode($code));
    }

    /** 回调失败时可能已经没有 state 数据，用开始授权时写下的 cookie 找回战队目录 */
    private function tenantFromCookie(): array
    {
        $raw = (string)$this->request->cookie('passport_tenant', '');
        if ($raw !== '' && substr_count($raw, '.') === 1) {
            [$tidStr, $slug] = explode('.', $raw, 2);
            $tid = (int)$tidStr;
            if ($tid > 0 && preg_match('/^[a-z0-9][a-z0-9-]{0,28}[a-z0-9]$/', $slug)) {
                return [$tid, $slug];
            }
        }
        return [0, ''];
    }
}
