<?php
declare (strict_types = 1);

namespace app\controller;

use app\BaseController;
use app\model\PlatformUser;
use app\model\Tenant;
use app\model\TenantApplication;
use app\model\User;
use app\service\DataTransfer;
use app\service\ImageUpload;
use app\service\IpRegion;
use app\service\Setting;
use app\service\Storage;
use app\service\TenantContext;
use app\service\TournamentBinding;
use app\service\Transfer;
use think\facade\Cache;
use think\facade\Db;

/**
 * 入驻平台接口（v5.0.0：仅根路径受理，/{slug}/ 下全部 404）
 *
 * 账号体系：platform_users 表
 *   role=team  → 入驻战队账号（注册登录、提交入驻、管理自己的站点与超管账号）
 *   role=admin → 内置平台超管（审核入驻申请、管理所有租户）
 */
class Platform extends BaseController
{
    // ---------- 上下文 / 登录守卫 ----------

    protected function platformOnly()
    {
        if (!TenantContext::isPlatform()) {
            return api_err('该接口仅入驻平台可用', 404, 404);
        }
        return null;
    }

    protected function requireTeam()
    {
        $u = current_platform_user();
        if (!$u || $u->status !== 'normal') {
            return null;
        }
        return $u;
    }

    // ---------- 公开：注册 / 登录 ----------

    // 平台注册（面向想入驻的战队）
    public function register()
    {
        if ($r = $this->platformOnly()) return $r;

        // v5.7.1(H4)：平台注册限流——同 IP 每小时最多 3 次，防脚本批量注册投毒
        $rateKey = 'preg_rate_ip_' . md5(client_ip());
        if ((int)Cache::get($rateKey, 0) >= 3) {
            return api_err('当前 IP 注册过于频繁，请稍后再试', 429, 429);
        }

        $username = trim((string)$this->request->post('username', ''));
        $nickname = trim((string)$this->request->post('nickname', ''));
        $password = (string)$this->request->post('password', '');
        $confirm  = (string)$this->request->post('confirm', '');

        if (!preg_match('/^[a-zA-Z0-9_]{3,20}$/', $username)) {
            return api_err('账号需为 3-20 位字母/数字/下划线（建议直接用 QQ 号）');
        }
        if (strtolower($username) === 'admin') {
            return api_err('该账号为系统保留，请换一个');
        }
        if ($nickname === '' || mb_strlen($nickname) > 32) {
            return api_err('请填写昵称/战队称呼（最长 32 字符）');
        }
        if (mb_strlen($password) < 8 || mb_strlen($password) > 64) {
            return api_err('密码长度需为 8-64 位');
        }
        if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
            return api_err('密码需至少包含一个字母和一个数字');
        }
        // v5.7.1(H5)：弱口令黑名单
        if ($weak = is_weak_password($password)) {
            return api_err($weak);
        }
        if ($password !== $confirm) {
            return api_err('两次输入的密码不一致');
        }
        if (PlatformUser::where('username', $username)->find()) {
            return api_err('该账号已被注册');
        }

        Cache::set($rateKey, (int)Cache::get($rateKey, 0) + 1, 3600);
        PlatformUser::create([
            'username' => $username,
            'password' => password_hash($password, PASSWORD_DEFAULT),
            'nickname' => $nickname,
            'role'     => 'team',
            'status'   => 'normal',
            'reg_ip'   => client_ip(),
        ]);
        return api_ok([], '注册成功，请登录');
    }

    // 平台登录
    public function login()
    {
        if ($r = $this->platformOnly()) return $r;

        $username = trim((string)$this->request->post('username', ''));
        $password = (string)$this->request->post('password', '');
        if ($username === '' || $password === '') {
            return api_err('请输入账号和密码');
        }

        // 防爆破：同 IP 连续失败 5 次锁 15 分钟（与战队系统同一套 blocked_ips 机制互不影响，独立计数）
        $failKey = 'plat_login_fail_' . md5(client_ip());
        if ((int)Cache::get($failKey, 0) >= 5) {
            return api_err('失败次数过多，请 15 分钟后再试', 429, 429);
        }

        // v5.7.1(H5)：账号维度爆破防护——同账号 15 分钟内失败 10 次锁 30 分钟
        $uLockKey = 'plat_login_lock_' . md5(mb_strtolower($username));
        $uFailKey = 'plat_login_fail_user_' . md5(mb_strtolower($username));
        if ((int)Cache::get($uLockKey, 0) === 1) {
            return api_err('该账号连续登录失败过多，已临时锁定 30 分钟', 429, 429);
        }

        $u = PlatformUser::where('username', $username)->find();
        if (!$u || !password_verify($password, (string)$u->password)) {
            Cache::set($failKey, (int)Cache::get($failKey, 0) + 1, 900);
            $uFail = (int)Cache::get($uFailKey, 0) + 1;
            Cache::set($uFailKey, $uFail, 900);
            if ($uFail >= 10) {
                Cache::set($uLockKey, 1, 1800);
                Cache::delete($uFailKey);
            }
            $this->logLogin($u ? (int)$u->id : 0, $username, 0);   // v5.1.0：失败也记日志
            return api_err('账号或密码错误');
        }
        if ($u->status !== 'normal') {
            $this->logLogin((int)$u->id, $username, 0);
            return api_err('账号已被停用', 403, 403);
        }

        Cache::delete($failKey);
        Cache::delete($uFailKey);
        session('platform_user_id', $u->id);
        // v5.7.1(H1)：防会话固定——登录成功后必须换新 Session ID（与战队登录 Auth::login 同一套做法）
        \think\facade\Session::setId(bin2hex(random_bytes(16)));
        $this->logLogin((int)$u->id, $username, 1);
        return api_ok(['username' => $u->username, 'nickname' => $u->nickname, 'role' => $u->role], '登录成功');
    }

    // v5.1.0：写平台登录日志（静默失败，不影响登录主流程）
    protected function logLogin(int $uid, string $username, int $result): void
    {
        try {
            $ip = client_ip();
            Db::name('platform_login_logs')->insert([
                'platform_user_id' => $uid,
                'username'         => mb_substr($username, 0, 32),
                'ip'               => $ip,
                'region'           => IpRegion::lookup($ip),
                'result'           => $result,
                'created_at'       => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            // 日志表异常不影响登录
        }
    }

    public function logout()
    {
        if ($r = $this->platformOnly()) return $r;
        session('platform_user_id', null);
        return api_ok([], '已退出登录');
    }

    // 当前平台账号信息
    public function me()
    {
        if ($r = $this->platformOnly()) return $r;
        $u = current_platform_user();
        if (!$u) {
            return api_err('未登录', 401, 401);
        }
        return api_ok(['username' => $u->username, 'nickname' => $u->nickname, 'role' => $u->role]);
    }

    // ---------- 公开：slug 查重 ----------

    public function checkSlug()
    {
        if ($r = $this->platformOnly()) return $r;
        $slug = strtolower(trim((string)$this->request->get('slug', '')));
        if (!TenantContext::validSlug($slug)) {
            return api_err('目录格式不合法：2-30 位小写字母/数字/连字符，首尾须为字母或数字');
        }
        if (TenantContext::isReserved($slug)) {
            return api_err('该目录为系统保留，请换一个');
        }
        if (Tenant::where('slug', $slug)->find()) {
            return api_err('该目录已被占用');
        }
        if (TenantApplication::where('slug', $slug)->where('status', 'pending')->find()) {
            return api_err('该目录已有待审核的申请');
        }
        return api_ok(['available' => true], '目录可用');
    }

    // ---------- 战队账号：入驻申请 / 我的站点 / 改超管 ----------

    // 提交入驻申请（multipart：字段 + 游戏截图 + 可选 logo；需平台登录）
    public function apply()
    {
        if ($r = $this->platformOnly()) return $r;
        $me = $this->requireTeam();
        if (!$me) {
            return api_err('请先登录平台账号', 401, 401);
        }

        // 已有租户或待审申请时不可重复提交
        if (Tenant::where('applicant_id', $me->id)->find()) {
            return api_err('你已开通过战队站点，每个账号限入驻一个战队');
        }
        if (TenantApplication::where('applicant_id', $me->id)->where('status', 'pending')->find()) {
            return api_err('你的入驻申请正在审核中，请勿重复提交');
        }

        $slug         = strtolower(trim((string)$this->request->post('slug', '')));
        $name         = trim((string)$this->request->post('name', ''));
        $description  = trim((string)$this->request->post('description', ''));
        $adminUser    = trim((string)$this->request->post('admin_username', ''));
        $adminPass    = (string)$this->request->post('admin_password', '');
        $adminConfirm = (string)$this->request->post('admin_confirm', '');

        if (!TenantContext::validSlug($slug)) {
            return api_err('目录格式不合法：2-30 位小写字母/数字/连字符，首尾须为字母或数字');
        }
        if (TenantContext::isReserved($slug)) {
            return api_err('该目录为系统保留，请换一个');
        }
        if ($name === '' || mb_strlen($name) > 64) {
            return api_err('请填写战队名称（最长 64 字符）');
        }
        if (mb_strlen($description) > 500) {
            return api_err('战队简介最长 500 字符');
        }
        if (!preg_match('/^[a-zA-Z0-9_]{3,20}$/', $adminUser)) {
            return api_err('超管账号需为 3-20 位字母/数字/下划线（建议用队长 QQ 号）');
        }
        if (strtolower($adminUser) === 'admin') {
            return api_err('超管账号不能使用 admin，请换一个');
        }
        if (mb_strlen($adminPass) < 8 || mb_strlen($adminPass) > 64) {
            return api_err('超管密码长度需为 8-64 位');
        }
        if (!preg_match('/[A-Za-z]/', $adminPass) || !preg_match('/\d/', $adminPass)) {
            return api_err('超管密码需至少包含一个字母和一个数字');
        }
        if ($adminPass !== $adminConfirm) {
            return api_err('两次输入的超管密码不一致');
        }
        // v5.7.1(H5)：弱口令黑名单
        if ($weak = is_weak_password($adminPass)) {
            return api_err('超管' . $weak);
        }

        if (Tenant::where('slug', $slug)->find()) {
            return api_err('该目录已被占用，请换一个');
        }
        if (TenantApplication::where('slug', $slug)->where('status', 'pending')->find()) {
            return api_err('该目录已有待审核的申请');
        }

        $upFiles = $this->request->file();
        // 战队队长证明截图（必填：如何证明你是战队队长，供平台审核）
        [$screenshot, $err] = ImageUpload::save($upFiles['file'] ?? null, 'profiles');
        if ($err !== null) {
            return api_err('队长证明截图：' . $err);
        }
        // 战队 logo（可选）
        $logo = '';
        if (!empty($upFiles['logo'])) {
            [$logo, $err] = ImageUpload::save($upFiles['logo'], 'profiles');
            if ($err !== null) {
                return api_err('战队logo：' . $err);
            }
        }

        TenantApplication::create([
            'applicant_id'   => $me->id,
            'slug'           => $slug,
            'name'           => $name,
            'logo'           => $logo,
            'description'    => $description,
            'admin_username' => $adminUser,
            'admin_password' => password_hash($adminPass, PASSWORD_DEFAULT),
            'screenshot'     => $screenshot,
            'status'         => 'pending',
        ]);
        return api_ok([], '入驻申请已提交，请等待平台管理员审核');
    }

    // 我的入驻状态 + 已开通站点信息（战队账号）
    public function my()
    {
        if ($r = $this->platformOnly()) return $r;
        $me = $this->requireTeam();
        if (!$me) {
            return api_err('未登录', 401, 401);
        }

        // v5.1.0：顺带结算到期的转移冷静期
        Transfer::settle();

        $tenant = Tenant::where('applicant_id', $me->id)->find();
        if ($tenant) {
            $tid    = (int)$tenant->id;
            $active = Transfer::active($tid);
            return api_ok([
                'tenant'      => [
                    'slug'           => (string)$tenant->slug,
                    'name'           => (string)$tenant->name,
                    'url'            => '/' . $tenant->slug . '/',
                    'admin_username' => (string)$tenant->admin_username,
                    'status'         => (string)$tenant->status,
                    'storage_quota'  => Storage::quota($tid),   // v5.1.0：控制台展示用量
                    'storage_used'   => Storage::used($tid),
                ],
                'application' => null,
                // v5.1.0：战队转移——我发起的（pending 可撤回 / cooling 冷静期）
                'transfer_out' => $active && (int)$active['from_uid'] === (int)$me->id ? Transfer::view($active) : null,
            ]);
        }

        // v5.1.0：别人转给我的待接收单
        $incoming = Transfer::incoming((int)$me->id);
        $app = TenantApplication::where('applicant_id', $me->id)->order('id', 'desc')->find();
        return api_ok([
            'tenant'      => null,
            'application' => $app ? [
                'status'        => (string)$app->status,
                'slug'          => (string)$app->slug,
                'name'          => (string)$app->name,
                'admin_username'=> (string)$app->admin_username,
                'reject_reason' => (string)$app->reject_reason,
                'created_at'    => (string)$app->created_at,
            ] : null,
            'transfer_in' => $incoming ? Transfer::view($incoming) : null,
        ]);
    }

    // v5.1.0：我的登录日志（最近 50 条，仅本人）
    public function loginLogs()
    {
        if ($r = $this->platformOnly()) return $r;
        $me = $this->requireTeam();
        if (!$me) {
            return api_err('未登录', 401, 401);
        }
        $list = Db::name('platform_login_logs')
            ->where('platform_user_id', $me->id)
            ->order('id', 'desc')->limit(50)->select()->toArray();
        foreach ($list as &$l) {
            $l['result'] = (int)$l['result'] === 1 ? '成功' : '失败';
        }
        unset($l);
        return api_ok(['list' => $list]);
    }

    // v5.1.0：自助暂停 / 恢复自己的站点（action: pause / resume）
    public function myStatus()
    {
        if ($r = $this->platformOnly()) return $r;
        $me = $this->requireTeam();
        if (!$me) {
            return api_err('未登录', 401, 401);
        }
        $tenant = Tenant::where('applicant_id', $me->id)->find();
        if (!$tenant) {
            return api_err('你还没有开通的战队站点');
        }
        if (in_array((string)$tenant->status, ['banned', 'transferring'], true)) {
            return api_err('当前站点状态不允许此操作');
        }
        $action = (string)$this->request->post('action', '');
        if ($action === 'pause') {
            if ((string)$tenant->status === 'paused') {
                return api_err('站点已处于暂停状态');
            }
            Tenant::where('id', $tenant->id)->update(['status' => 'paused']);
            return api_ok(['status' => 'paused'], '站点已暂停，成员暂时无法访问；可随时恢复');
        }
        if ($action === 'resume') {
            if ((string)$tenant->status !== 'paused') {
                return api_err('站点未处于暂停状态');
            }
            Tenant::where('id', $tenant->id)->update(['status' => 'approved']);
            return api_ok(['status' => 'approved'], '站点已恢复访问');
        }
        return api_err('操作不合法');
    }

    // ---------- v5.1.0：战队转移 ----------

    // 发起转移：把战队转给另一个平台账号（对方需为已注册的战队账号且名下无站点）
    public function transferStart()
    {
        if ($r = $this->platformOnly()) return $r;
        $me = $this->requireTeam();
        if (!$me) {
            return api_err('未登录', 401, 401);
        }
        $tenant = Tenant::where('applicant_id', $me->id)->find();
        if (!$tenant) {
            return api_err('你还没有开通的战队站点');
        }
        if ((string)$tenant->status !== 'approved') {
            return api_err('站点当前状态不允许转移');
        }
        if (Transfer::active((int)$tenant->id)) {
            return api_err('该战队已有进行中的转移单，请先处理');
        }

        $toName = trim((string)$this->request->post('to_username', ''));
        if ($toName === '') {
            return api_err('请输入接收方的平台账号');
        }
        $to = PlatformUser::where('username', $toName)->find();
        if (!$to || $to->role !== 'team' || $to->status !== 'normal') {
            return api_err('接收方账号不存在或不可用（需为正常状态的战队账号）');
        }
        if ((int)$to->id === (int)$me->id) {
            return api_err('不能转移给自己');
        }
        if (Tenant::where('applicant_id', $to->id)->find()) {
            return api_err('对方名下已有战队站点，无法接收转移');
        }

        Db::name('tenant_transfers')->insert([
            'tenant_id' => $tenant->id,
            'from_uid'  => $me->id,
            'to_uid'    => $to->id,
            'status'    => 'pending',
        ]);
        return api_ok([], '转移申请已发出，等待对方确认');
    }

    // 接收方同意：进入 7 天冷静期（期间站点整站不可访问），到期自动完成交接
    public function transferAccept($id)
    {
        if ($r = $this->platformOnly()) return $r;
        $me = $this->requireTeam();
        if (!$me) {
            return api_err('未登录', 401, 401);
        }
        $tr = Db::name('tenant_transfers')->where('id', (int)$id)->find();
        if (!$tr || (string)$tr['status'] !== 'pending') {
            return api_err('转移单不存在或已处理', 404);
        }
        if ((int)$tr['to_uid'] !== (int)$me->id) {
            return api_err('只有接收方可以确认', 403, 403);
        }
        if (Tenant::where('applicant_id', $me->id)->find()) {
            return api_err('你名下已有战队站点，无法接收');
        }

        $cool = date('Y-m-d H:i:s', time() + Transfer::COOL_DAYS * 86400);
        Db::startTrans();
        try {
            Db::name('tenant_transfers')->where('id', (int)$id)
                ->update(['status' => 'cooling', 'cooling_until' => $cool]);
            Db::name('tenants')->where('id', $tr['tenant_id'])->update(['status' => 'transferring']);
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            return api_err('操作失败，请重试', 500, 500);
        }
        return api_ok(['cooling_until' => $cool], '已确认接收，进入 ' . Transfer::COOL_DAYS . ' 天冷静期，期间站点暂停访问');
    }

    // 接收方拒绝：转移作废，站点不受影响
    public function transferReject($id)
    {
        if ($r = $this->platformOnly()) return $r;
        $me = $this->requireTeam();
        if (!$me) {
            return api_err('未登录', 401, 401);
        }
        $tr = Db::name('tenant_transfers')->where('id', (int)$id)->find();
        if (!$tr || (string)$tr['status'] !== 'pending') {
            return api_err('转移单不存在或已处理', 404);
        }
        if ((int)$tr['to_uid'] !== (int)$me->id) {
            return api_err('只有接收方可以拒绝', 403, 403);
        }
        Transfer::abort($tr, 'rejected');
        return api_ok([], '已拒绝该转移申请');
    }

    // 发起方撤回（pending 或冷静期内均可，站点恢复原主）
    public function transferCancel($id)
    {
        if ($r = $this->platformOnly()) return $r;
        $me = $this->requireTeam();
        if (!$me) {
            return api_err('未登录', 401, 401);
        }
        $tr = Db::name('tenant_transfers')->where('id', (int)$id)->find();
        if (!$tr || !in_array((string)$tr['status'], ['pending', 'cooling'], true)) {
            return api_err('转移单不存在或已结束', 404);
        }
        if ((int)$tr['from_uid'] !== (int)$me->id) {
            return api_err('只有发起方可以撤回', 403, 403);
        }
        Transfer::abort($tr, 'cancelled');
        return api_ok([], '已撤回转移，站点恢复正常');
    }

    // 更改战队系统的超管账号（用户名）
    public function adminAccount()
    {
        if ($r = $this->platformOnly()) return $r;
        $me = $this->requireTeam();
        if (!$me) {
            return api_err('未登录', 401, 401);
        }
        $tenant = Tenant::where('applicant_id', $me->id)->find();
        if (!$tenant) {
            return api_err('你还没有开通的战队站点');
        }

        $new = trim((string)$this->request->post('username', ''));
        if (!preg_match('/^[a-zA-Z0-9_]{3,20}$/', $new)) {
            return api_err('账号需为 3-20 位字母/数字/下划线');
        }
        if (strtolower($new) === 'admin') {
            return api_err('不能使用 admin 作为账号');
        }
        if ($new === (string)$tenant->admin_username) {
            return api_err('新账号与当前一致，无需修改');
        }
        if (Db::name('users')->where('tenant_id', $tenant->id)->where('username', $new)->find()) {
            return api_err('该账号在你的战队系统中已存在');
        }

        Db::name('users')->where('id', $tenant->admin_user_id)->update(['username' => $new]);
        Tenant::where('id', $tenant->id)->update(['admin_username' => $new]);
        return api_ok(['admin_username' => $new], '超管账号已更新');
    }

    // 更改战队系统的超管密码
    public function adminPassword()
    {
        if ($r = $this->platformOnly()) return $r;
        $me = $this->requireTeam();
        if (!$me) {
            return api_err('未登录', 401, 401);
        }
        $tenant = Tenant::where('applicant_id', $me->id)->find();
        if (!$tenant) {
            return api_err('你还没有开通的战队站点');
        }

        $pass    = (string)$this->request->post('password', '');
        $confirm = (string)$this->request->post('confirm', '');
        if (mb_strlen($pass) < 8 || mb_strlen($pass) > 64) {
            return api_err('密码长度需为 8-64 位');
        }
        if (!preg_match('/[A-Za-z]/', $pass) || !preg_match('/\d/', $pass)) {
            return api_err('密码需至少包含一个字母和一个数字');
        }
        if ($pass !== $confirm) {
            return api_err('两次输入的密码不一致');
        }
        // v5.7.1(H5)：弱口令黑名单
        if ($weak = is_weak_password($pass)) {
            return api_err($weak);
        }

        // v5.7.1(H3)：密码纪元 +1 → 超管账号所有在线会话立即失效；
        // 同时清空该账号全部免登录令牌（此前两者都漏了）
        Db::name('users')->where('id', $tenant->admin_user_id)
            ->inc('session_epoch', 1)
            ->update(['password' => password_hash($pass, PASSWORD_DEFAULT)]);
        remember_clear_user((int)$tenant->admin_user_id);
        return api_ok([], '超管密码已更新，原会话与免登录已失效');
    }

    // ---------- 平台超管：入驻审核 / 租户管理 ----------

    // 申请列表（含截图查看）
    public function applications()
    {
        if ($r = $this->platformOnly()) return $r;
        if (!is_platform_super()) {
            return api_err('仅平台超管可操作', 403, 403);
        }
        $list = TenantApplication::order('id', 'desc')->limit(50)->select()->toArray();
        foreach ($list as &$a) {
            $pu = PlatformUser::find((int)$a['applicant_id']);
            $a['applicant_name'] = $pu ? (string)$pu->nickname . '(' . $pu->username . ')' : '-';
        }
        unset($a);
        return api_ok(['list' => $list]);
    }

    // 通过申请：事务内建租户 + 战队超管账号 + 默认 settings
    public function approve($id)
    {
        if ($r = $this->platformOnly()) return $r;
        if (!is_platform_super()) {
            return api_err('仅平台超管可操作', 403, 403);
        }
        $app = TenantApplication::find((int)$id);
        if (!$app || $app->status !== 'pending') {
            return api_err('申请不存在或已处理', 404);
        }
        if (Tenant::where('slug', $app->slug)->find()) {
            return api_err('该目录已被占用，无法开通');
        }

        Db::startTrans();
        try {
            $tenant = Tenant::create([
                'applicant_id'   => $app->applicant_id,
                'slug'           => $app->slug,
                'name'           => $app->name,
                'logo'           => $app->logo,
                'description'    => $app->description,
                'admin_username' => $app->admin_username,
                'status'         => 'approved',
            ]);

            // 战队超管归新租户；显式 tenant_id 优先（BelongsToTenant 不覆盖）
            // v5.1.0：昵称用超管账号本身（个人中心展示个人昵称；战队名在管理中心头部展示）
            $admin = User::create([
                'tenant_id'     => $tenant->id,
                'username'      => $app->admin_username,
                'password'      => $app->admin_password,   // 申请提交时已 bcrypt 哈希
                'nickname'      => $app->admin_username,
                'profile_image' => $app->screenshot,
                'reg_ip'        => client_ip(),
                'role'          => 'admin',
                'status'        => 'approved',
                'admin_perms'   => json_encode(array_keys(admin_perm_defs())),
            ]);

            // 新租户默认 settings（key 作用域=该租户；轮换地图 v5.1.0 起归平台统一配置，不再下发）
            foreach ([
                'smtp_host' => '', 'smtp_port' => '465', 'smtp_user' => '', 'smtp_pass' => '', 'smtp_from' => '',
                'mail_tpl_code' => '', 'global_mute' => '0',
            ] as $k => $v) {
                Db::name('settings')->insert(['tenant_id' => $tenant->id, 'key' => $k, 'value' => $v]);
            }

            Tenant::where('id', $tenant->id)->update(['admin_user_id' => $admin->id]);
            // 静态 where()->save() 生成独立 UPDATE，避免实例缓存回写问题（同 permsSave）
            TenantApplication::where('id', $app->id)->save(['status' => 'approved']);
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            return api_err('开通失败：' . $e->getMessage(), 500, 500);
        }

        return api_ok([
            'tenant_id' => $tenant->id,
            'url'       => '/' . $tenant->slug . '/',
        ], '已通过，战队站点已开通');
    }

    // 驳回申请：必须填写原因（申请人登录平台可见）
    public function reject($id)
    {
        if ($r = $this->platformOnly()) return $r;
        if (!is_platform_super()) {
            return api_err('仅平台超管可操作', 403, 403);
        }
        $app = TenantApplication::find((int)$id);
        if (!$app || $app->status !== 'pending') {
            return api_err('申请不存在或已处理', 404);
        }
        $reason = trim((string)$this->request->post('reason', ''));
        if ($reason === '' || mb_strlen($reason) > 200) {
            return api_err('请填写驳回原因（最长 200 字符）');
        }
        TenantApplication::where('id', $app->id)->save(['status' => 'rejected', 'reject_reason' => $reason]);
        return api_ok([], '已驳回');
    }

    // 租户列表（v5.1.0：附带成员数 / 存储用量 / 进行中转移单）
    public function tenants()
    {
        if ($r = $this->platformOnly()) return $r;
        if (!is_platform_super()) {
            return api_err('仅平台超管可操作', 403, 403);
        }
        $list = Tenant::order('id', 'asc')->select()->toArray();
        foreach ($list as &$t) {
            $pu = PlatformUser::find((int)$t['applicant_id']);
            $t['applicant_name'] = $pu ? (string)$pu->nickname . '(' . $pu->username . ')' : '-';
            $t['url'] = '/' . $t['slug'] . '/';
            $tid = (int)$t['id'];
            $t['member_count']  = Db::name('users')->where('tenant_id', $tid)->count();
            $t['storage_quota'] = (int)($t['storage_quota'] ?? 0) > 0 ? (int)$t['storage_quota'] : Storage::DEFAULT_QUOTA;
            $t['storage_used']  = Storage::used($tid);
            $active = Transfer::active($tid);
            $t['transfer'] = $active ? Transfer::view($active) : null;
        }
        unset($t);
        return api_ok(['list' => $list]);
    }

    // 停用 / 恢复租户
    public function setTenantStatus($id)
    {
        if ($r = $this->platformOnly()) return $r;
        if (!is_platform_super()) {
            return api_err('仅平台超管可操作', 403, 403);
        }
        $tenant = Tenant::find((int)$id);
        if (!$tenant) {
            return api_err('租户不存在', 404);
        }
        $status = (string)$this->request->post('status', '');
        if (!in_array($status, ['approved', 'banned'], true)) {
            return api_err('状态不合法');
        }
        if ((string)$tenant->status === $status) {
            return api_err('状态未变化');
        }
        Tenant::where('id', $tenant->id)->update(['status' => $status]);
        return api_ok([], $status === 'banned' ? '已封禁该战队站点' : '已恢复该战队站点');
    }

    // v5.1.0：修改租户存储配额（quota_gb：GB 数，0 = 恢复默认 2GB）
    public function setQuota($id)
    {
        if ($r = $this->platformOnly()) return $r;
        if (!is_platform_super()) {
            return api_err('仅平台超管可操作', 403, 403);
        }
        $tenant = Tenant::find((int)$id);
        if (!$tenant) {
            return api_err('租户不存在', 404);
        }
        $gb = (float)$this->request->post('quota_gb', 0);
        if ($gb < 0 || $gb > 1024) {
            return api_err('配额需在 0-1024 GB 之间（0 = 默认 2GB）');
        }
        $bytes = $gb > 0 ? (int)round($gb * 1073741824) : 0;
        Tenant::where('id', $tenant->id)->update(['storage_quota' => $bytes]);
        return api_ok(['storage_quota' => $bytes > 0 ? $bytes : Storage::DEFAULT_QUOTA], '存储配额已更新');
    }

    // v5.1.0：隐身巡查入口——签发 2 小时只读令牌，进入该战队系统不留任何痕迹
    public function stealth($id)
    {
        if ($r = $this->platformOnly()) return $r;
        if (!is_platform_super()) {
            return api_err('仅平台超管可操作', 403, 403);
        }
        $tenant = Tenant::find((int)$id);
        if (!$tenant) {
            return api_err('租户不存在', 404);
        }
        if ((string)$tenant->status === 'transferring') {
            return api_err('该站点处于转移冷静期，暂无法巡查');
        }
        $token = stealth_make_token((int)$tenant->id, (string)$tenant->slug, (int)current_platform_user()->id);
        return api_ok([
            'url'       => '/' . $tenant->slug . '/app?_sv=' . $token,
            'expires'   => 7200,
        ], '隐身入口已生成（2 小时内有效，只读、不留痕迹）');
    }

    // v5.1.0：给该战队管理员发通知（展示在对方管理中心"平台通知"栏）
    public function notify($id)
    {
        if ($r = $this->platformOnly()) return $r;
        if (!is_platform_super()) {
            return api_err('仅平台超管可操作', 403, 403);
        }
        $tenant = Tenant::find((int)$id);
        if (!$tenant) {
            return api_err('租户不存在', 404);
        }
        $content = trim((string)$this->request->post('content', ''));
        if ($content === '' || mb_strlen($content) > 500) {
            return api_err('请填写通知内容（最长 500 字符）');
        }
        Db::name('tenant_notices')->insert([
            'tenant_id'  => $tenant->id,
            'content'    => $content,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        return api_ok([], '通知已发送，对方管理员登录后台即可看到');
    }

    // ---------- v5.1.0：轮换地图统一管理（原战队管理系统后台收归平台，全战队共用） ----------

    // 轮换设置 + 地图池 + 每日组合（管理端回显）
    public function rotation()
    {
        if ($r = $this->platformOnly()) return $r;
        if (!is_platform_super()) {
            return api_err('仅平台超管可操作', 403, 403);
        }
        $maps = Db::name('rotation_maps')->where('tenant_id', 0)->order('sort', 'asc')->order('id', 'asc')->select()->toArray();
        $days = Db::name('rotation_days')->where('tenant_id', 0)->order('id', 'asc')->select()->toArray();

        $dayIds = array_column($days, 'id');
        $links = $dayIds !== []
            ? Db::name('rotation_day_maps')->where('tenant_id', 0)->whereIn('day_id', $dayIds)->select()->toArray()
            : [];
        $mapIdsOfDay = [];
        foreach ($links as $l) {
            $mapIdsOfDay[(int)$l['day_id']][] = (int)$l['map_id'];
        }
        foreach ($days as &$d) {
            $d['map_ids'] = $mapIdsOfDay[(int)$d['id']] ?? [];
        }
        unset($d);

        return api_ok([
            'enabled' => Setting::getPlatform('map_rotation_enabled', '0') === '1',
            'start'   => Setting::getPlatform('map_rotation_start', ''),
            'maps'    => $maps,
            'days'    => $days,
        ]);
    }

    // 保存轮换设置（全局开关 / 锚点日期：第 1 个组合对应的日期）
    public function rotationSave()
    {
        if ($r = $this->platformOnly()) return $r;
        if (!is_platform_super()) {
            return api_err('仅平台超管可操作', 403, 403);
        }
        $enabled = (int)$this->request->post('enabled', 0) === 1 ? '1' : '0';
        $start   = trim((string)$this->request->post('start', ''));

        if ($start !== '') {
            $start = substr($start, 0, 10);
            if (strtotime($start) === false) {
                return api_err('锚点日期格式无效');
            }
        }

        Setting::setPlatform('map_rotation_enabled', $enabled);
        Setting::setPlatform('map_rotation_start', $start);
        return api_ok([], '轮换设置已保存，全部战队立即生效');
    }

    // 新增 / 编辑地图（multipart：名称 + 可选图片；传 id 为编辑）
    public function rotationMapSave()
    {
        if ($r = $this->platformOnly()) return $r;
        if (!is_platform_super()) {
            return api_err('仅平台超管可操作', 403, 403);
        }
        $id   = (int)$this->request->post('id', 0);
        $name = trim((string)$this->request->post('name', ''));
        if ($name === '' || mb_strlen($name) > 64) {
            return api_err('请填写地图名称（不超过 64 字）');
        }

        $imagePath = null;
        $files = $this->request->file();
        if ($files['file'] ?? null) {
            [$imagePath, $err] = ImageUpload::save($files['file'], 'rotation');
            if ($err !== null) {
                return api_err($err);
            }
        }

        if ($id > 0) {
            $map = Db::name('rotation_maps')->where('id', $id)->where('tenant_id', 0)->find();
            if (!$map) {
                return api_err('地图不存在', 404);
            }
            $data = ['name' => $name];
            if ($imagePath !== null) {
                if ($map['image'] !== '') {
                    ImageUpload::delete((string)$map['image']);
                }
                $data['image'] = $imagePath;
            }
            Db::name('rotation_maps')->where('id', $id)->update($data);
            return api_ok([], '地图已更新');
        }

        $newId = Db::name('rotation_maps')->insertGetId([
            'tenant_id' => 0,
            'name'      => $name,
            'image'     => $imagePath ?? '',
            'sort'      => 0,
        ]);
        return api_ok(['id' => $newId], '地图已添加');
    }

    // 删除地图（连图片物理文件一起删，并级联清理每日组合关联）
    public function rotationMapRemove($id)
    {
        if ($r = $this->platformOnly()) return $r;
        if (!is_platform_super()) {
            return api_err('仅平台超管可操作', 403, 403);
        }
        $map = Db::name('rotation_maps')->where('id', (int)$id)->where('tenant_id', 0)->find();
        if (!$map) {
            return api_err('地图不存在', 404);
        }
        if ($map['image'] !== '') {
            ImageUpload::delete((string)$map['image']);
        }
        Db::name('rotation_maps')->where('id', (int)$id)->delete();
        Db::name('rotation_day_maps')->where('map_id', (int)$id)->delete();
        return api_ok([], '地图已删除');
    }

    // 新增 / 编辑每日组合（JSON：name 可选，map_ids 数组为当天多选的地图）
    public function rotationDaySave()
    {
        if ($r = $this->platformOnly()) return $r;
        if (!is_platform_super()) {
            return api_err('仅平台超管可操作', 403, 403);
        }
        $id     = (int)$this->request->post('id', 0);
        $name   = trim((string)$this->request->post('name', ''));
        $mapIds = $this->request->post('map_ids', []);

        if (mb_strlen($name) > 32) {
            return api_err('组合名称不超过 32 字');
        }
        if (!is_array($mapIds)) {
            $mapIds = [];
        }
        $mapIds = array_values(array_unique(array_map('intval', $mapIds)));
        $mapIds = array_values(array_filter($mapIds, function ($v) { return $v > 0; }));
        if ($mapIds !== []) {
            $exists = Db::name('rotation_maps')->where('tenant_id', 0)->whereIn('id', $mapIds)->column('id');
            $exists = array_map('intval', $exists);
            $mapIds = array_values(array_intersect($mapIds, $exists));
        }
        if ($mapIds === []) {
            return api_err('请至少选择一张地图');
        }

        if ($id > 0) {
            $day = Db::name('rotation_days')->where('id', $id)->where('tenant_id', 0)->find();
            if (!$day) {
                return api_err('组合不存在', 404);
            }
            Db::name('rotation_days')->where('id', $id)->update(['name' => $name]);
            Db::name('rotation_day_maps')->where('day_id', $id)->delete();   // 全量替换关联
            foreach ($mapIds as $mid) {
                Db::name('rotation_day_maps')->insert(['tenant_id' => 0, 'day_id' => $id, 'map_id' => $mid]);
            }
            return api_ok([], '组合已更新');
        }

        $dayId = Db::name('rotation_days')->insertGetId(['tenant_id' => 0, 'name' => $name]);
        foreach ($mapIds as $mid) {
            Db::name('rotation_day_maps')->insert(['tenant_id' => 0, 'day_id' => $dayId, 'map_id' => $mid]);
        }
        return api_ok(['id' => $dayId], '组合已添加');
    }

    // 删除每日组合（关联一起删）
    public function rotationDayRemove($id)
    {
        if ($r = $this->platformOnly()) return $r;
        if (!is_platform_super()) {
            return api_err('仅平台超管可操作', 403, 403);
        }
        $day = Db::name('rotation_days')->where('id', (int)$id)->where('tenant_id', 0)->find();
        if (!$day) {
            return api_err('组合不存在', 404);
        }
        Db::name('rotation_days')->where('id', (int)$id)->delete();
        Db::name('rotation_day_maps')->where('day_id', (int)$id)->delete();
        return api_ok([], '组合已删除');
    }

    // ---------- v5.2.0：站点数据导入导出（提交审核，平台超管同意后执行） ----------
    // 导出：成员≥5 才可导出；导入：任意站点（含新建站点）；每天各 1 次机会；
    // 数据含图片文件，不含今日轮换地图（轮换归平台统一配置）；导入为整站覆盖。

    /** 我的站点 + 登录守卫（返回 [租户行, 错误响应]，错误时租户为 null） */
    protected function myTenantForData()
    {
        if ($r = $this->platformOnly()) return [null, $r];
        $me = $this->requireTeam();
        if (!$me) {
            return [null, api_err('未登录', 401, 401)];
        }
        $tenant = Tenant::where('applicant_id', $me->id)->find();
        if (!$tenant) {
            return [null, api_err('你还没有开通的战队站点')];
        }
        return [$tenant, null];
    }

    /** 当天已占用的机会数（rejected/cancelled/failed 不算占用；
     *  v5.2.2：执行失败的任务不消耗机会，战队可当天修正后重新提交 */
    protected function dataTaskCountToday(int $tid, string $type): int
    {
        return (int)Db::name('tenant_data_tasks')
            ->where('tenant_id', $tid)->where('type', $type)
            ->whereNotIn('status', ['rejected', 'cancelled', 'failed'])
            ->where('created_at', '>=', date('Y-m-d 00:00:00'))
            ->count();
    }

    // 战队端：我的导入导出任务列表 + 资格信息
    public function dataTasks()
    {
        [$tenant, $err] = $this->myTenantForData();
        if ($err) return $err;
        $tid = (int)$tenant->id;

        $members = (int)Db::name('users')->where('tenant_id', $tid)->where('status', 'approved')->count();
        $list = Db::name('tenant_data_tasks')->where('tenant_id', $tid)
            ->order('id', 'desc')->limit(20)->select()->toArray();
        // v5.2.4：把 PHP 上传体积上限告诉前端，超大文件直接拦在浏览器侧，
        // 避免传完 100% 后服务端丢弃请求导致界面卡在"校验中"
        $toBytes = function ($v) {
            $v = trim((string)$v);
            $n = (float)$v;
            $unit = strtolower(substr($v, -1));
            if ($unit === 'g') $n *= 1073741824;
            elseif ($unit === 'm') $n *= 1048576;
            elseif ($unit === 'k') $n *= 1024;
            return (int)$n;
        };
        $up  = $toBytes(ini_get('upload_max_filesize'));
        $post = $toBytes(ini_get('post_max_size'));
        $maxUpload = min($up > 0 ? $up : PHP_INT_MAX, $post > 0 ? $post : PHP_INT_MAX, DataTransfer::MAX_IMPORT_BYTES);
        return api_ok([
            'member_count' => $members,
            'can_export'   => $members >= DataTransfer::MIN_EXPORT_MEMBERS,
            'min_export_members' => DataTransfer::MIN_EXPORT_MEMBERS,
            'export_left'  => max(0, 1 - $this->dataTaskCountToday($tid, 'export')),
            'import_left'  => max(0, 1 - $this->dataTaskCountToday($tid, 'import')),
            'max_upload_bytes' => $maxUpload,
            'list'         => $list,
        ]);
    }

    // 战队端：申请导出（提交后等平台超管审核）
    public function dataExportRequest()
    {
        [$tenant, $err] = $this->myTenantForData();
        if ($err) return $err;
        $tid = (int)$tenant->id;
        if ((string)$tenant->status !== 'approved') {
            return api_err('站点当前状态不允许导出');
        }
        $members = (int)Db::name('users')->where('tenant_id', $tid)->where('status', 'approved')->count();
        if ($members < DataTransfer::MIN_EXPORT_MEMBERS) {
            return api_err('站点成员满 ' . DataTransfer::MIN_EXPORT_MEMBERS . ' 人才可导出（当前 ' . $members . ' 人）；新建站点请先导入数据');
        }
        if ($this->dataTaskCountToday($tid, 'export') >= 1) {
            return api_err('每天的导出机会只有 1 次，请明天再试');
        }
        if (Db::name('tenant_data_tasks')->where('tenant_id', $tid)->where('type', 'export')
            ->whereIn('status', ['pending', 'approved'])->find()) {
            return api_err('已有进行中的导出任务，请先处理或等待完成');
        }
        $id = Db::name('tenant_data_tasks')->insertGetId([
            'tenant_id'  => $tid,
            'type'       => 'export',
            'status'     => 'pending',
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        return api_ok(['id' => $id], '导出申请已提交，平台管理员审核通过后即可下载');
    }

    // 战队端：上传数据包申请导入（multipart: file=zip，提交后等平台超管审核）
    public function dataImportRequest()
    {
        [$tenant, $err] = $this->myTenantForData();
        if ($err) return $err;
        $tid = (int)$tenant->id;
        if ((string)$tenant->status !== 'approved') {
            return api_err('站点当前状态不允许导入');
        }
        if ($this->dataTaskCountToday($tid, 'import') >= 1) {
            return api_err('每天的导入机会只有 1 次，请明天再试');
        }
        if (Db::name('tenant_data_tasks')->where('tenant_id', $tid)->where('type', 'import')
            ->whereIn('status', ['pending'])->find()) {
            return api_err('已有待审核的导入任务，请等待审核结果');
        }

        $file = $this->request->file('file');
        if (!$file) {
            return api_err('请上传数据包文件（zip）');
        }
        $name = strtolower((string)$file->getOriginalName());
        if (substr($name, -4) !== '.zip') {
            return api_err('数据包必须是 .zip 格式');
        }
        $src = $file->getPathname();
        if (!is_file($src)) {
            return api_err('文件读取失败，请重新上传');
        }
        $dst = DataTransfer::taskDir() . DIRECTORY_SEPARATOR
            . 'import_' . $tid . '_' . date('YmdHis') . '_' . substr(bin2hex(random_bytes(3)), 0, 4) . '.zip';
        if ($err2 = DataTransfer::validatePackage($src)) {
            return api_err($err2);
        }
        if (!@copy($src, $dst)) {
            return api_err('文件保存失败，请重试');
        }
        $id = Db::name('tenant_data_tasks')->insertGetId([
            'tenant_id'  => $tid,
            'type'       => 'import',
            'status'     => 'pending',
            'file'       => $dst,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        return api_ok(['id' => $id], '数据包已上传，导入将覆盖当前站点数据，平台管理员审核同意后执行');
    }

    // 战队端：撤回待审核的任务
    public function dataTaskCancel($id)
    {
        [$tenant, $err] = $this->myTenantForData();
        if ($err) return $err;
        $task = Db::name('tenant_data_tasks')->where('id', (int)$id)
            ->where('tenant_id', (int)$tenant->id)->find();
        if (!$task) {
            return api_err('任务不存在', 404);
        }
        if ((string)$task['status'] !== 'pending') {
            return api_err('仅待审核的任务可以撤回');
        }
        if ((string)$task['type'] === 'import' && (string)$task['file'] !== '' && is_file((string)$task['file'])) {
            @unlink((string)$task['file']);
        }
        Db::name('tenant_data_tasks')->where('id', (int)$id)->update(['status' => 'cancelled']);
        return api_ok([], '已撤回');
    }

    // 战队端：下载审核通过的导出包
    public function dataTaskDownload($id)
    {
        [$tenant, $err] = $this->myTenantForData();
        if ($err) return $err;
        $task = Db::name('tenant_data_tasks')->where('id', (int)$id)
            ->where('tenant_id', (int)$tenant->id)->find();
        if (!$task || (string)$task['type'] !== 'export'
            || !in_array((string)$task['status'], ['approved', 'done'], true)
            || (string)$task['file'] === '' || !is_file((string)$task['file'])) {
            return api_err('导出包不存在或未通过审核', 404);
        }
        return download((string)$task['file'], basename((string)$task['file']));
    }

    // 平台超管：数据任务审核列表（最新 50 条）
    public function dataTasksAdmin()
    {
        if ($r = $this->platformOnly()) return $r;
        if (!is_platform_super()) {
            return api_err('仅平台超管可操作', 403, 403);
        }
        $list = Db::name('tenant_data_tasks')->order('id', 'desc')->limit(50)->select()->toArray();
        $names = Tenant::whereIn('id', array_unique(array_column($list, 'tenant_id')))->column('name,slug', 'id');
        foreach ($list as &$t) {
            $info = $names[(int)$t['tenant_id']] ?? null;
            $t['tenant_name'] = $info ? $info['name'] : ('站点#' . $t['tenant_id']);
            $t['tenant_slug'] = $info ? $info['slug'] : '';
            $t['file_size']   = ((string)$t['file'] !== '' && is_file((string)$t['file'])) ? filesize((string)$t['file']) : 0;
        }
        unset($t);
        return api_ok(['list' => $list]);
    }

    // 平台超管：同意任务（导出→生成包；导入→执行覆盖导入）
    public function dataTaskApprove($id)
    {
        if ($r = $this->platformOnly()) return $r;
        if (!is_platform_super()) {
            return api_err('仅平台超管可操作', 403, 403);
        }
        $task = Db::name('tenant_data_tasks')->where('id', (int)$id)->find();
        if (!$task) {
            return api_err('任务不存在', 404);
        }
        if ((string)$task['status'] !== 'pending') {
            return api_err('该任务已处理过');
        }
        $me  = current_platform_user();
        $tid = (int)$task['tenant_id'];

        if ((string)$task['type'] === 'export') {
            [$file, $err] = DataTransfer::buildExport($tid);
            if ($err !== null) {
                Db::name('tenant_data_tasks')->where('id', (int)$id)->update([
                    'status' => 'failed', 'note' => $err,
                    'reviewed_at' => date('Y-m-d H:i:s'), 'reviewed_by' => (int)$me->id,
                ]);
                return api_err('生成失败：' . $err);
            }
            Db::name('tenant_data_tasks')->where('id', (int)$id)->update([
                'status' => 'approved', 'file' => $file,
                'reviewed_at' => date('Y-m-d H:i:s'), 'reviewed_by' => (int)$me->id,
            ]);
            return api_ok([], '已同意并生成导出包，战队方可下载');
        }

        // import：执行覆盖导入
        if ((string)$task['file'] === '' || !is_file((string)$task['file'])) {
            Db::name('tenant_data_tasks')->where('id', (int)$id)->update(['status' => 'failed', 'note' => '上传的数据包文件丢失']);
            return api_err('上传的数据包文件已丢失，请让战队重新提交');
        }
        $err = DataTransfer::runImport($tid, (string)$task['file']);
        if ($err !== null) {
            Db::name('tenant_data_tasks')->where('id', (int)$id)->update([
                'status' => 'failed', 'note' => mb_substr($err, 0, 200),
                'reviewed_at' => date('Y-m-d H:i:s'), 'reviewed_by' => (int)$me->id,
            ]);
            return api_err($err);
        }
        @unlink((string)$task['file']);   // 导入成功后清掉上传包
        Db::name('tenant_data_tasks')->where('id', (int)$id)->update([
            'status' => 'done', 'note' => '',
            'reviewed_at' => date('Y-m-d H:i:s'), 'reviewed_by' => (int)$me->id,
            'finished_at' => date('Y-m-d H:i:s'),
        ]);
        return api_ok([], '导入完成，站点数据已被数据包覆盖');
    }

    // 平台超管：驳回任务（附原因）
    public function dataTaskReject($id)
    {
        if ($r = $this->platformOnly()) return $r;
        if (!is_platform_super()) {
            return api_err('仅平台超管可操作', 403, 403);
        }
        $task = Db::name('tenant_data_tasks')->where('id', (int)$id)->find();
        if (!$task) {
            return api_err('任务不存在', 404);
        }
        if ((string)$task['status'] !== 'pending') {
            return api_err('该任务已处理过');
        }
        $reason = trim((string)$this->request->post('reason', ''));
        if ((string)$task['type'] === 'import' && (string)$task['file'] !== '' && is_file((string)$task['file'])) {
            @unlink((string)$task['file']);
        }
        Db::name('tenant_data_tasks')->where('id', (int)$id)->update([
            'status' => 'rejected', 'note' => mb_substr($reason, 0, 200),
            'reviewed_at' => date('Y-m-d H:i:s'), 'reviewed_by' => (int)current_platform_user()->id,
        ]);
        return api_ok([], '已驳回');
    }

    // 平台超管：下载任务包检查（导入包 / 导出包）
    public function dataTaskDownloadAdmin($id)
    {
        if ($r = $this->platformOnly()) return $r;
        if (!is_platform_super()) {
            return api_err('仅平台超管可操作', 403, 403);
        }
        $task = Db::name('tenant_data_tasks')->where('id', (int)$id)->find();
        if (!$task || (string)$task['file'] === '' || !is_file((string)$task['file'])) {
            return api_err('任务包不存在', 404);
        }
        return download((string)$task['file'], basename((string)$task['file']));
    }

    // ==================== 跨战队约战总线（v5.4.0：平台超管为最高控制者） ====================

    /** 平台超管守卫（约战总线专用简写） */
    protected function battleSuper()
    {
        if ($r = $this->platformOnly()) return $r;
        if (!is_platform_super()) {
            return api_err('仅平台超管可操作', 403, 403);
        }
        return null;
    }

    // 全平台约战总览（含外部战队）+ 地图池与候选参赛方，供前端一屏展示
    public function battles()
    {
        if ($r = $this->battleSuper()) return $r;
        $tenants = Db::name('tenants')->where('status', 'approved')->order('id', 'asc')
            ->field('id, name')->select()->toArray();
        $externals = Db::name('external_teams')->where('status', 1)->order('id', 'asc')
            ->field('id, name')->select()->toArray();
        return api_ok([
            'list'      => \app\service\BattleService::listAll(),
            'map_pool'  => \app\service\BattleService::mapPool(),
            'tenants'   => $tenants,
            'externals' => $externals,
        ]);
    }

    // 代发起约战（双方均可指定为租户或外部战队）
    public function battleCreate()
    {
        if ($r = $this->battleSuper()) return $r;
        $p = $this->request->post();
        [$id, $err] = \app\service\BattleService::create($p, '平台超管代发');
        if ($err) return api_err($err);
        return api_ok(['id' => $id], '约战已发起');
    }

    // 约战详情（含双方名单与逐局对局）
    public function battleDetail($id)
    {
        if ($r = $this->battleSuper()) return $r;
        $d = \app\service\BattleService::detail((int)$id);
        if (!$d) return api_err('约战不存在', 404);
        return api_ok($d);
    }

    // 抽签：地图池排除双 Ban 后随机抽各局地图 + 随机攻守方（可重抽，未录胜负前）
    public function battleDraw($id)
    {
        if ($r = $this->battleSuper()) return $r;
        if ($e = \app\service\BattleService::draw((int)$id, (int)current_platform_user()->id)) {
            return api_err($e);
        }
        return api_ok(\app\service\BattleService::detail((int)$id)['rounds'] ?? [], '抽签完成');
    }

    // 逐局录入胜负（result=a_win|b_win，达胜场数自动结算；bo3 决胜统计随录入提交）
    public function battleRoundResult($id, $rid)
    {
        if ($r = $this->battleSuper()) return $r;
        $result = (string)$this->request->post('result', '');
        $extra = [];
        foreach (['attack_secs', 'occupy_count', 'occupy_ratio'] as $k) {
            $v = $this->request->post($k, '');
            if ($v !== '' && $v !== null) $extra[$k] = (int)$v;
        }
        if ($e = \app\service\BattleService::recordRound((int)$id, (int)$rid, $result, (int)current_platform_user()->id, $extra)) {
            return api_err($e);
        }
        $d = \app\service\BattleService::detail((int)$id);
        return api_ok([
            'score_a' => $d['score_a'], 'score_b' => $d['score_b'],
            'status'  => $d['status'],  'result'  => $d['result'],
            'adv_side' => $d['adv_side'], 'adv_reason' => $d['adv_reason'],
        ], $d['status'] === 'finished' ? '系列赛已结算' : '胜负已录入');
    }

    // 手动指定决胜局优势方（正式赛赛事组裁决入口，本版本由平台超管代管）
    public function battleAdvantage($id)
    {
        if ($r = $this->battleSuper()) return $r;
        $side = (string)$this->request->post('side', '');
        if ($e = \app\service\BattleService::setAdvantage((int)$id, $side)) return api_err($e);
        return api_ok([], '优势方已指定，等待其选图/选边');
    }

    // 平台超管代优势方完成决胜局选图/选边（choice=pick_map传map_id / pick_side传attacker_side a|b）
    public function battleDecider($id)
    {
        if ($r = $this->battleSuper()) return $r;
        $e = \app\service\BattleService::deciderPick(
            (int)$id,
            (string)$this->request->post('choice', ''),
            (int)$this->request->post('map_id', 0),
            (string)$this->request->post('attacker_side', ''),
            ['platform', 0]
        );
        if ($e) return api_err($e);
        return api_ok(\app\service\BattleService::detail((int)$id)['rounds'] ?? [], '决胜局已确定');
    }

    // 强制取消（任意阶段）
    public function battleCancel($id)
    {
        if ($r = $this->battleSuper()) return $r;
        if ($e = \app\service\BattleService::cancel((int)$id, '平台超管强制取消')) return api_err($e);
        return api_ok([], '约战已强制取消');
    }

    // 任意队名单调配（指定 side；超管添加不受上限约束）
    public function battleRoster($id)
    {
        if ($r = $this->battleSuper()) return $r;
        $side = (string)$this->request->post('side', '');
        if (!in_array($side, ['a', 'b'], true)) return api_err('请指定 side(a/b)');
        $removeId = (int)$this->request->post('remove_id', 0);
        if ($removeId > 0) {
            if ($e = \app\service\BattleService::removeSignup((int)$id, $removeId)) return api_err($e);
            return api_ok([], '已从名单移除');
        }
        $userIds = array_values(array_filter(array_map('intval', (array)$this->request->post('add_user_ids', []))));
        $names   = array_values(array_filter(array_map('trim', (array)$this->request->post('add_names', []))));
        if ($userIds !== []) {
            $tid = (int)$this->request->post('tenant_id', 0);
            if ($tid <= 0) return api_err('添加用户名单须指定 tenant_id');
            if ($e = \app\service\BattleService::addUsers((int)$id, $side, $userIds, $tid, true)) return api_err($e);
        }
        if ($names !== []) {
            $extId = (int)$this->request->post('external_team_id', 0);
            if ($extId <= 0) return api_err('上报昵称名单须指定 external_team_id');
            if ($e = \app\service\BattleService::addNames((int)$id, $side, $names, $extId, true)) return api_err($e);
        }
        if ($userIds === [] && $names === []) return api_err('请提供要添加的名单内容');
        return api_ok([], '名单已调配');
    }

    // ==================== 外部战队登记（v5.4.0） ====================

    // 外部战队列表（开源单租户接入登记）
    public function externalTeams()
    {
        if ($r = $this->battleSuper()) return $r;
        $rows = Db::name('external_teams')->order('id', 'desc')->select()->toArray();
        // 附：各队已签发的 external Key 数
        foreach ($rows as &$r) {
            $r['key_count'] = (int)Db::name('api_keys')->where('scope', 'external')
                ->where('external_team_id', (int)$r['id'])->count();
        }
        return api_ok($rows);
    }

    // 登记/编辑外部战队（有 id=更新，无=新增）
    public function externalTeamSave()
    {
        if ($r = $this->battleSuper()) return $r;
        $id      = (int)$this->request->post('id', 0);
        $name    = trim((string)$this->request->post('name', ''));
        $contact = trim((string)$this->request->post('contact_name', ''));
        $remark  = trim((string)$this->request->post('remark', ''));
        $status  = $this->request->post('status', '1') ? 1 : 0;
        if ($name === '' || mb_strlen($name) > 50) return api_err('请填写战队名称（不超过 50 字）');
        $data = [
            'name'         => $name,
            'contact_name' => mb_substr($contact, 0, 50),
            'remark'       => mb_substr($remark, 0, 200),
            'status'       => $status,
        ];
        if ($id > 0) {
            Db::name('external_teams')->where('id', $id)->update($data);
            return api_ok(['id' => $id], '已更新');
        }
        $data['created_at'] = date('Y-m-d H:i:s');
        $id = Db::name('external_teams')->insertGetId($data);
        return api_ok(['id' => $id], '外部战队已登记，可为其签发 external Key');
    }

    // ==================== API 密钥管理（v5.4.0：平台侧） ====================

    // 平台侧密钥列表（platform / external 两类；租户自建键在各自管理中心）
    public function apiKeys()
    {
        if ($r = $this->battleSuper()) return $r;
        $rows = Db::name('api_keys')->whereIn('scope', ['platform', 'external'])
            ->order('id', 'desc')->select()->toArray();
        foreach ($rows as &$r) {
            unset($r['key_hash']);
            $r['team_name'] = $r['scope'] === 'external' && $r['external_team_id']
                ? (string)(Db::name('external_teams')->where('id', (int)$r['external_team_id'])->value('name') ?: '已删除')
                : '';
        }
        return api_ok($rows);
    }

    // 签发密钥：scope=platform 或 external（后者须指定外部战队；明文仅此次返回）
    public function apiKeyIssue()
    {
        if ($r = $this->battleSuper()) return $r;
        $scope = (string)$this->request->post('scope', 'external');
        if (!in_array($scope, ['platform', 'external'], true)) return api_err('密钥类型无效');
        $extId = 0;
        if ($scope === 'external') {
            $extId = (int)$this->request->post('external_team_id', 0);
            if (!Db::name('external_teams')->where('id', $extId)->find()) {
                return api_err('请先登记外部战队');
            }
        }
        [$plain, $hash, $prefix] = battle_api_make_key();
        $id = Db::name('api_keys')->insertGetId([
            'key_hash'         => $hash,
            'key_prefix'       => $prefix,
            'name'             => mb_substr(trim((string)$this->request->post('name', '')), 0, 50)
                ?: ($scope === 'platform' ? '平台密钥' : '外部战队密钥'),
            'scope'            => $scope,
            'external_team_id' => $extId,
            'status'           => 1,
            'created_by'       => (int)current_platform_user()->id,
            'created_at'       => date('Y-m-d H:i:s'),
        ]);
        return api_ok(['id' => $id, 'key' => $plain], '密钥已生成，请立即保存，之后不再显示');
    }

    // 停用/启用平台侧密钥
    public function apiKeyStatus($id)
    {
        if ($r = $this->battleSuper()) return $r;
        $row = Db::name('api_keys')->where('id', (int)$id)->whereIn('scope', ['platform', 'external'])->find();
        if (!$row) return api_err('密钥不存在', 404);
        Db::name('api_keys')->where('id', (int)$id)->update(['status' => (int)$row['status'] === 1 ? 0 : 1]);
        return api_ok([], '密钥状态已更新');
    }

    // 删除平台侧密钥
    public function apiKeyRemove($id)
    {
        if ($r = $this->battleSuper()) return $r;
        $n = Db::name('api_keys')->where('id', (int)$id)->whereIn('scope', ['platform', 'external'])->delete();
        return $n > 0 ? api_ok([], '密钥已删除') : api_err('密钥不存在', 404);
    }

    // ==================== 赛事中心互通配置（v5.5.0） ====================

    /** 读取赛事中心连接配置（平台超管；Key 打码回显） */
    public function tournamentConfig()
    {
        if ($r = $this->battleSuper()) return $r;
        $key = Setting::getPlatform('tournament_site_key');
        return api_ok([
            'url'        => Setting::getPlatform('tournament_site_url'),
            'key_set'    => $key !== '',
            'key_masked' => $key !== '' ? substr($key, 0, 8) . str_repeat('*', 16) : '',
        ]);
    }

    /** 保存赛事中心连接配置（key 留空=保持原值；清空地址即视为断开） */
    public function tournamentConfigSave()
    {
        if ($r = $this->battleSuper()) return $r;
        $url = rtrim(trim((string)$this->request->post('url', '')), '/');
        $key = trim((string)$this->request->post('key', ''));
        if ($url !== '' && !preg_match('#^https?://#i', $url)) {
            return api_err('请填写赛事中心地址（http/https 开头）');
        }
        Setting::setPlatform('tournament_site_url', $url);
        if ($key !== '') {
            Setting::setPlatform('tournament_site_key', $key);
        }
        return api_ok([], '赛事中心配置已保存');
    }

    // ==================== 赛事中心多站点绑定（v5.5.1，平台共享库） ====================

    /** 平台共享库：所有赛事中心绑定（Key 打码回显） */
    public function tournamentBindings()
    {
        if ($r = $this->battleSuper()) return $r;
        $rows = TournamentBinding::platformList();
        foreach ($rows as &$b) {
            $b['key_set'] = (string)$b['api_key'] !== '';
            $b['key_masked'] = $b['key_set'] ? substr((string)$b['api_key'], 0, 8) . str_repeat('*', 10) : '';
            $b['api_key'] = ''; // 不出服务端
        }
        return api_ok(['bindings' => $rows]);
    }

    /** 新增/编辑平台共享库绑定（$id>0 编辑；key 留空=编辑时保持原值） */
    public function tournamentBindingSave($id = 0)
    {
        if ($r = $this->battleSuper()) return $r;
        $rs = TournamentBinding::platformSave($this->request->post(), $id > 0 ? (int)$id : null);
        if ((int)$rs[0] <= 0) return api_err($rs[1] ?? '保存失败');
        return api_ok(['binding_id' => (int)$rs[0]], '绑定已保存');
    }

    /** 删除平台共享库绑定 */
    public function tournamentBindingRemove($id)
    {
        if ($r = $this->battleSuper()) return $r;
        if (!TournamentBinding::platformRemove((int)$id)) {
            return api_err('绑定不存在', 404);
        }
        return api_ok([], '绑定已删除');
    }

    // ==================== 赛事站身份核验开放端点（v5.5.0：赛事站持 platform Key 调） ====================

    /** 租户列表（供赛事站代录/审核时核验租户身份） */
    public function tournamentPartiesTenants()
    {
        if ($r = $this->battleSuper()) return $r;
        $rows = Db::name('tenants')->where('status', 'approved')->order('id', 'asc')
            ->field('id, name')->select()->toArray();
        return api_ok(array_values($rows));
    }

    /** 外部战队列表（供赛事站代录/审核时核验外部战队身份） */
    public function tournamentPartiesExternal()
    {
        if ($r = $this->battleSuper()) return $r;
        $rows = Db::name('external_teams')->where('status', 1)->order('id', 'asc')
            ->field('id, name')->select()->toArray();
        return api_ok(array_values($rows));
    }
}
