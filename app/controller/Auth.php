<?php
declare (strict_types = 1);

namespace app\controller;

use app\BaseController;
use app\model\BlockedIp;
use app\model\User;
use app\service\AuthSession;
use app\service\EmailCode;
use app\service\ImageUpload;
use app\service\IpRegion;
use app\service\Mailer;
use think\facade\Cache;
use think\facade\Db;

/**
 * 认证接口：注册(QQ号+截图) / 登录 / 退出 / 当前用户
 * （v3 起移除邮箱验证码：注册免验证码，邮箱字段仅按 QQ 号自动生成，不再发信）
 * v5.7.1 安全加固：仅战队目录受理（L2）/ 注册限流（H4）/ 账号维度爆破锁（H5）/
 *                  统一登录失败文案（M1）/ 弱口令黑名单 / 找回密码申请（L1）
 */
class Auth extends BaseController
{
    // ============ 注册（multipart：字段 + 截图文件） ============
    public function register()
    {
        // v5.7.1(L2)：战队认证接口仅在战队目录受理——根路径注册会产生 tenant_id=0 的游离账号，无人可审
        if (\app\service\TenantContext::isPlatform()) {
            return api_err('接口不存在', 404, 404);
        }
        // IP 封禁检查（防爆破：自动封禁 + 管理员手动封禁）
        if ($err = $this->checkIpBlocked()) {
            return $err;
        }
        // v5.7.1(H4)：注册限流——同 IP 每小时 3 次、同战队每天 30 次，
        // 防脚本化注册吃满存储配额（2GB≈400 张 5MB 截图）与灌爆审核队列
        if ($err = $this->registerRateLimit()) {
            return $err;
        }

        $qq       = trim((string)$this->request->post('qq', ''));
        $nickname = trim((string)$this->request->post('nickname', ''));
        $password = (string)$this->request->post('password', '');
        $confirm  = (string)$this->request->post('confirm', '');

        if (!is_valid_qq($qq)) {
            return api_err('请输入正确的 QQ 号（5-11 位数字）');
        }
        if ($nickname === '') {
            $nickname = '玩家' . $qq;
        }
        if (mb_strlen($nickname) > 32) {
            return api_err('昵称最长 32 个字符');
        }
        if (mb_strlen($password) < 8 || mb_strlen($password) > 64) {
            return api_err('密码长度需为 8-64 位');
        }
        if ($password !== $confirm) {
            return api_err('两次输入的密码不一致');
        }
        if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
            return api_err('密码需至少包含一个字母和一个数字');
        }
        // v5.7.1(H5)：弱口令黑名单（qq123456 / a1234567 一类极易被爆破的口令）
        if ($weak = is_weak_password($password)) {
            return api_err($weak);
        }
        if (User::where('username', $qq)->find()) {
            return api_err('该 QQ 号已注册，请直接登录');
        }

        // 邮箱地址按 QQ 号自动生成，仅存档，不再发验证码
        $email = $qq . '@qq.com';

        // 上传游戏个人主页截图（必填）
        // 用无参 file() 取数组：未选文件时 file('file') 会抛异常导致 500，这里要给友好提示
        $upFiles = $this->request->file();
        [$path, $err] = ImageUpload::save($upFiles['file'] ?? null, 'profiles');
        if ($err !== null) {
            return api_err($err);
        }

        // 注册 IP 与归属地（v3.9.5：走 CDN 时取真实客户端 IP）
        $ip = client_ip();

        User::create([
            'username'      => $qq,
            'password'      => password_hash($password, PASSWORD_DEFAULT),
            'nickname'      => $nickname,
            'email'         => $email,
            'profile_image' => $path,
            'reg_ip'        => $ip,
            'reg_region'    => IpRegion::lookup($ip),
            'role'          => 'user',
            'status'        => 'pending',   // 注册后需管理员审核
        ]);

        return api_ok([], '注册成功！账号需管理员审核通过后才能登录，请耐心等待');
    }

    // ============ 登录（QQ号 或 admin） ============
    public function login()
    {
        [$resp] = $this->attemptLogin((int)$this->request->post('remember', 0));
        return $resp;
    }

    /**
     * 登录主流程。
     * IP 封禁、5 次/IP 与 10 次/账号的爆破阈值、账号锁定、状态判定、登录日志、
     * 防会话固定必须与表单登录完全一致，否则授权页就成了绕过爆破防护的后门。
     *
     * @param int  $remember       1=签发 7 天免登录令牌
     * @param bool $manageRemember false 时完全不碰免登录令牌——授权登录不该顺手把用户
     *                             其它设备已签发的免登录令牌清掉（那是误伤）
     * @return array [\think\Response, ?\app\model\User] 第二项仅登录成功时非空
     */
    public function attemptLogin(int $remember, bool $manageRemember = true): array
    {
        // v5.7.1(L2)：战队认证接口仅在战队目录受理
        if (\app\service\TenantContext::isPlatform()) {
            return [api_err('接口不存在', 404, 404), null];
        }
        // IP 封禁检查（防爆破：自动封禁 + 管理员手动封禁）
        if ($err = $this->checkIpBlocked()) {
            return [$err, null];
        }

        $username = trim((string)$this->request->post('username', ''));
        $password = (string)$this->request->post('password', '');

        if ($username === '' || $password === '') {
            return [api_err('请输入 QQ 号和密码'), null];
        }

        // 登录频率限制：同一 IP 15 分钟内最多失败 5 次
        $ipKey = 'login_fail_' . md5(client_ip());
        $failCount = (int)Cache::get($ipKey, 0);
        if ($failCount >= 5) {
            // 超过阈值后自动封禁 IP 并落库，交由管理员在后台解封
            $this->autoBlockIp(client_ip(), '登录爆破自动封禁(连续失败超限)');
            return [api_err('尝试次数过多，当前 IP 已被封禁，请联系管理员', 429, 429), null];
        }

        // v5.7.1(H5)：账号维度爆破防护——分布式 IP 也能锁单个账号：
        // 同账号 15 分钟内失败 10 次 → 锁定 30 分钟（锁定期内该账号任何来源都登不进）
        $tid     = \app\service\TenantContext::id();
        $uHash   = md5($tid . ':' . mb_strtolower($username));
        $userKey = 'login_fail_user_' . $uHash;
        $lockKey = 'login_lock_user_' . $uHash;
        if ((int)Cache::get($lockKey, 0) === 1) {
            return [api_err('该账号连续登录失败过多，已临时锁定 30 分钟，请稍后再试', 429, 429), null];
        }

        $user = User::where('username', $username)->find();
        $ok   = $user && password_verify($password, (string)$user->password);
        if (!$ok) {
            // v5.7.1(M1)：统一对外文案为「账号或密码错误」——账号不存在与密码错误不再区分提示，
            // 防止批量枚举 QQ 号探测战队成员名单；内部两分支都计入爆破计数
            // v5.15.0(S-M4)：读—改—写三步由 rate_bump 的 flock 串起来。
            // 原先并发请求都拿同一个旧值 +1 再回写，5 次/IP、10 次/账号的阈值可被一次性穿透
            $newCount = rate_bump($ipKey, 900);
            $userFail = rate_bump($userKey, 900);
            // v5.17.0：管理员账号被密码猜错要留痕（成员账号不记，量大且无取证价值；
            // 也正因为只在"账号存在且是管理员"时记，拿不存在的小号撞库刷不出日志）
            if ($user && $user->role === 'admin') {
                login_log_write($tid, (int)$user->id, $username, client_ip(),
                    IpRegion::lookup(client_ip()), 'pwd', 'fail', false);
            }
            if ($userFail >= 10) {
                Cache::set($lockKey, 1, 1800);
                Cache::delete($userKey);
                $this->logSecurity('账号 ' . $username . ' 连续失败 ' . $userFail . ' 次，已临时锁定 30 分钟');
            }
            if ($newCount >= 5) {
                // 第 5 次失败即触发自动封禁，防止继续爆破
                $this->autoBlockIp(client_ip(), '登录爆破自动封禁(5次密码错误)');
                return [api_err('连续登录失败过多，当前 IP 已被封禁，请联系管理员', 429, 429), null];
            }
            return [api_err('账号或密码错误'), null];
        }
        if ($user->status === 'pending') {
            return [api_err('账号正在审核中，审核通过后才能登录'), null];
        }
        if ($user->status === 'banned') {
            return [api_err('账号已被封禁，请联系管理员'), null];
        }

        // 登录成功后清除失败计数（IP + 账号双维度）
        Cache::delete($ipKey);
        Cache::delete($userKey);

        // v5.36.6：密码登录与通行证登录共用同一段会话收尾，避免两边各写一份后漂移
        AuthSession::finalize($user, 'pwd', $manageRemember, $remember);

        return [api_ok($this->userInfo($user), '登录成功'), $user];
    }

    // 退出登录（v5.3.0：同步作废 7 天免登录令牌并清 cookie）
    public function logout()
    {
        // v5.36.13：协作模式下“退出”只结束协作会话，绝不能按站长账号去清他的免登录令牌
        if (\app\service\Support::isActive()) {
            \app\service\Support::audit(\app\service\TenantContext::id(), '结束协作',
                '平台超管通过退出登录结束协作', \app\service\Support::auditIdentity() ?: '平台协作');
            \app\service\Support::endLocal();
            return api_ok([], '已退出平台协作');
        }
        $uid = (int)session('user_id');
        if ($uid > 0) {
            remember_clear_user($uid);
        } else {
            \think\facade\Cookie::delete(remember_cookie_name());
        }
        session(null);
        return api_ok([], '已退出登录');
    }

    // 当前登录用户信息
    public function me()
    {
        $user = current_user();
        if (!$user) {
            return api_err('未登录', 401, 401);
        }
        return api_ok($this->userInfo($user));
    }

    // IP 是否已被封禁（命中则拒绝登录/注册）
    private function checkIpBlocked()
    {
        $ip = client_ip();
        if (BlockedIp::where('ip', $ip)->find()) {
            return api_err('当前 IP 已被封禁，禁止登录/注册，请联系管理员', 403, 403);
        }
        return null;
    }

    // 自动封禁 IP（幂等：已存在则不重复插入）
    private function autoBlockIp(string $ip, string $reason): void
    {
        // v5.15.0(S-H2)：blocked_ips 是全平台一张表，自动封禁必须能看出是哪支战队触发的，
        // 否则超管在封禁列表里看到一条 IP 完全无从判断该不该解（128 字截断与列宽一致）
        $slug   = \app\service\TenantContext::slug();
        $reason = mb_substr($reason . ($slug !== '' ? '·来源 ' . $slug : ''), 0, 128);
        if (!BlockedIp::where('ip', $ip)->find()) {
            BlockedIp::create([
                'ip'         => $ip,
                'reason'     => $reason,
                'blocked_by' => null,
            ]);
        }
    }

    // v5.7.1(H4)：注册限流——同 IP 每小时最多 3 次、同战队每天最多 30 次，
    // 双维度防脚本批量注册（吃存储配额 / 灌爆审核队列）
    private function registerRateLimit()
    {
        $ipKey = 'reg_rate_ip_' . md5(client_ip());
        if ((int)Cache::get($ipKey, 0) >= 3) {
            return api_err('当前 IP 注册过于频繁，请稍后再试', 429, 429);
        }
        $tKey = 'reg_rate_tenant_' . \app\service\TenantContext::id() . '_' . date('Ymd');
        if ((int)Cache::get($tKey, 0) >= 30) {
            return api_err('本站今日注册申请过多，请明天再试', 429, 429);
        }
        rate_bump($ipKey, 3600);   // v5.15.0(S-M4)：原子自增
        rate_bump($tKey, 86400);
        return null;
    }

    // v5.7.1(H5)：安全事件写管理操作日志（admin_id=0 表示系统自动防护），静默失败不阻断主流程
    private function logSecurity(string $detail, string $action = '登录防护'): void
    {
        try {
            Db::name('admin_logs')->insert([
                'tenant_id'  => \app\service\TenantContext::id(),
                'admin_id'   => 0,
                'admin_name' => '安全防护',
                'action'     => $action,
                'detail'     => mb_substr($detail, 0, 300),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            // 日志表异常不影响主流程
        }
    }

    // ============ 找回密码申请（v5.7.1 L1） ============
    // 站点无邮件通道，改为「重新提交游戏主页截图 + 管理员审核」的自恢复流程：
    // 申请人提交 QQ 号 + 新密码 + 新截图 → 登记 password_resets 待审 → 管理员在注册审核页通过/驳回
    // 防枚举：账号不存在 / 已封禁 / 已有待审申请时，对外一律返回同样的受理文案（不暴露成员名单）
    public function forgot()
    {
        if (\app\service\TenantContext::isPlatform()) {
            return api_err('接口不存在', 404, 404);
        }
        if ($err = $this->checkIpBlocked()) {
            return $err;
        }
        // 限流：同 IP 每小时最多 3 次申请
        $rateKey = 'forgot_rate_ip_' . md5(client_ip());
        if ((int)Cache::get($rateKey, 0) >= 3) {
            return api_err('当前 IP 申请过于频繁，请稍后再试', 429, 429);
        }

        $qq       = trim((string)$this->request->post('qq', ''));
        $password = (string)$this->request->post('password', '');
        $confirm  = (string)$this->request->post('confirm', '');
        if (!is_valid_qq($qq)) {
            return api_err('请输入正确的 QQ 号（5-11 位数字）');
        }
        if (mb_strlen($password) < 8 || mb_strlen($password) > 64) {
            return api_err('新密码长度需为 8-64 位');
        }
        if ($password !== $confirm) {
            return api_err('两次输入的密码不一致');
        }
        if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
            return api_err('密码需至少包含一个字母和一个数字');
        }
        if ($weak = is_weak_password($password)) {
            return api_err($weak);
        }

        Cache::set($rateKey, (int)Cache::get($rateKey, 0) + 1, 3600);

        // 上传新的游戏个人主页截图（必填，作为身份佐证随申请一起审核）
        $upFiles = $this->request->file();
        [$path, $err] = ImageUpload::save($upFiles['file'] ?? null, 'profiles');
        if ($err !== null) {
            return api_err($err);
        }

        // 仅对在册（审核通过）账号落库；其余情形静默丢弃截图，对外文案保持一致
        $user = User::where('username', $qq)->where('status', 'approved')->find();
        if ($user) {
            $pending = Db::name('password_resets')
                ->where('tenant_id', \app\service\TenantContext::id())
                ->where('user_id', $user->id)->find();
            if (!$pending) {
                Db::name('password_resets')->insert([
                    'tenant_id'    => \app\service\TenantContext::id(),
                    'user_id'      => $user->id,
                    'new_password' => password_hash($password, PASSWORD_DEFAULT),
                    'screenshot'   => $path,
                    'request_ip'   => client_ip(),
                    'created_at'   => date('Y-m-d H:i:s'),
                ]);
            } else {
                // 已有待审申请：不重复登记，新截图即时清理防止沉淀垃圾数据
                ImageUpload::delete($path);
            }
        } else {
            ImageUpload::delete($path);
        }
        return api_ok([], '申请已受理，管理员审核通过后即可使用新密码登录');
    }

    // ============ 找回密码·邮箱验证码（v5.36.34） ============
    // 与上面 forgot() 那条「游戏截图 + 管理员审核」并存的第二条路：
    // 邮箱是注册时按 QQ 号自动生成的 QQ号@qq.com，天然属于这个 QQ 号，能收到码就等于自证了身份，
    // 所以这条路当场改密码、不用等审核。平台那套发信账号没配齐时这条路自动关闭
    // （reset-options 回 email_ready=0，界面把验证码那张卡收起来），成员永远还有截图审核那条能走的。
    //
    // 防枚举：索取验证码这一步，账号存在与否、邮件有没有真发出去，对外都是同一句文案。

    /** 这条路现在走不走得通：只看平台发信账号配齐了没有，不暴露任何账号信息 */
    public function resetOptions()
    {
        if (\app\service\TenantContext::isPlatform()) {
            return api_err('接口不存在', 404, 404);
        }
        $ready = Mailer::platformConfigured();
        return api_ok([
            'email_ready' => $ready ? 1 : 0,
            'why'         => $ready ? '' : '平台还没配好发信邮箱，验证码这条路暂时走不通；请提交游戏主页截图等管理员审核',
            'ttl_sec'     => EmailCode::TTL_SEC,
            'again_sec'   => EmailCode::RESEND_AFTER,
        ]);
    }

    // 索取验证码（QQ 号 → 那个 QQ 自己的邮箱）
    public function sendResetCode()
    {
        if (\app\service\TenantContext::isPlatform()) {
            return api_err('接口不存在', 404, 404);
        }
        if ($err = $this->checkIpBlocked()) {
            return $err;
        }
        if (!Mailer::platformConfigured()) {
            return api_err('本站现在发不出验证码邮件，请改用「提交游戏主页截图」那条路');
        }

        $qq = trim((string)$this->request->post('qq', ''));
        if (!is_valid_qq($qq)) {
            return api_err('请输入正确的 QQ 号（5-11 位数字）');
        }

        $tid  = \app\service\TenantContext::id();
        $qHash = md5(mb_strtolower($qq));
        // 限流三个维度：同 IP 每小时 8 次 / 同 QQ 每小时 3 条、每天 6 条 / 两次索取之间隔 60 秒
        // 60 秒那条用缓存而不是查库：库里有没有码取决于账号存在与否，查库会在响应里漏出成员名单
        $ipKey   = 'pwdreset_ip_' . md5(client_ip());
        $hrKey   = 'pwdreset_hr_' . $tid . '_' . $qHash;
        $dayKey  = 'pwdreset_day_' . $tid . '_' . $qHash . '_' . date('Ymd');
        $lastKey = 'pwdreset_last_' . $tid . '_' . $qHash;
        if ((int)Cache::get($ipKey, 0) >= 8) {
            return api_err('当前 IP 索取验证码过于频繁，请稍后再试', 429, 429);
        }
        if ((int)Cache::get($hrKey, 0) >= 3) {
            return api_err('这个 QQ 号索取验证码过于频繁，请 1 小时后再试', 429, 429);
        }
        if ((int)Cache::get($dayKey, 0) >= 6) {
            return api_err('这个 QQ 号今天的验证码次数已用完，请明天再试或提交截图申请', 429, 429);
        }
        $since = time() - (int)Cache::get($lastKey, 0);
        if ((int)Cache::get($lastKey, 0) > 0 && $since < EmailCode::RESEND_AFTER) {
            return api_err('刚发过一条，请 ' . (EmailCode::RESEND_AFTER - $since) . ' 秒后再点', 429, 429);
        }
        rate_bump($ipKey, 3600);
        rate_bump($hrKey, 3600);
        rate_bump($dayKey, 86400);
        Cache::set($lastKey, time(), 3600);

        $user  = User::where('username', $qq)->where('status', 'approved')->find();
        $email = $this->resetEmailFor($user, $qq);
        // 对外永远是同一句：查无此人 / 发信失败都不改口（失败只写进管理日志）
        $sentMsg = '验证码已发到 ' . $email . '，' . intdiv(EmailCode::TTL_SEC, 60) . ' 分钟内有效；'
            . '没收到请翻一下垃圾箱，或改用提交截图那条路';
        if (!$user) {
            return api_ok([], $sentMsg);
        }
        $code = EmailCode::issue($email);
        if ($code === null) {
            $this->logSecurity('找回密码：QQ ' . $qq . ' 的验证码没能生成', '找回密码');
            return api_ok([], $sentMsg);
        }
        $team  = \app\service\TenantContext::name();
        $reply = Mailer::send($email, ($team !== '' ? '【' . $team . '】' : '') . '找回密码验证码',
            $this->resetMailBody($team, $code), Mailer::platformConfig());
        if ($reply !== null) {
            // 码作废掉：邮箱里躺着一条兑不了的码更容易让人干等
            Db::name('email_codes')->where('email', $email)->where('code', $code)->update(['used' => 1]);
            $this->logSecurity('找回密码：发给 ' . \app\service\MailBlast::mask($email)
                . ' 的验证码投递失败（' . mb_substr($reply, 0, 80) . '）', '找回密码');
        } else {
            $this->logSecurity('找回密码：已向 ' . \app\service\MailBlast::mask($email) . ' 发出验证码', '找回密码');
        }
        return api_ok([], $sentMsg);
    }

    // 拿验证码当场改密码（不登录、不经审核）
    public function resetByCode()
    {
        if (\app\service\TenantContext::isPlatform()) {
            return api_err('接口不存在', 404, 404);
        }
        if ($err = $this->checkIpBlocked()) {
            return $err;
        }

        $qq       = trim((string)$this->request->post('qq', ''));
        $code     = trim((string)$this->request->post('code', ''));
        $password = (string)$this->request->post('password', '');
        $confirm  = (string)$this->request->post('confirm', '');
        if (!is_valid_qq($qq)) {
            return api_err('请输入正确的 QQ 号（5-11 位数字）');
        }
        if (mb_strlen($password) < 8 || mb_strlen($password) > 64) {
            return api_err('新密码长度需为 8-64 位');
        }
        if ($password !== $confirm) {
            return api_err('两次输入的密码不一致');
        }
        if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
            return api_err('密码需至少包含一个字母和一个数字');
        }
        if ($weak = is_weak_password($password)) {
            return api_err($weak);
        }

        $tid = \app\service\TenantContext::id();
        // 码可以猜，但只有 8 次机会（10 分钟内）：六位数字撞上的概率本来就很低，加上这道闸就更够不着
        $tryKey = 'pwdreset_try_' . $tid . '_' . md5(mb_strtolower($qq));
        if ((int)Cache::get($tryKey, 0) >= 8) {
            return api_err('验证码输错太多次，请重新获取一条', 429, 429);
        }

        $user  = User::where('username', $qq)->where('status', 'approved')->find();
        $email = $this->resetEmailFor($user, $qq);
        // 查无此人与码不对回同一句：这扇门外的人问不出名单
        if (!$user || !EmailCode::verify($email, $code)) {
            rate_bump($tryKey, EmailCode::TTL_SEC);
            return api_err('验证码不对或已过期，请重新获取');
        }

        // 换密码 + 密码纪元 +1（踢掉该账号全部在线会话）+ 作废全部免登录令牌 —— 与审核通过那条路一致
        $user->save([
            'password'      => password_hash($password, PASSWORD_DEFAULT),
            'session_epoch' => (int)$user->session_epoch + 1,
        ]);
        remember_clear_user((int)$user->id);
        // 人刚证明这是他的邮箱，别再把他锁在登录页外面：清掉这个账号的爆破计数与临时锁
        $uHash = md5($tid . ':' . mb_strtolower($qq));
        Cache::delete('login_fail_user_' . $uHash);
        Cache::delete('login_lock_user_' . $uHash);
        Cache::delete($tryKey);

        // 他自己改过密码了，之前那张截图申请就没有下一步可审了：连材料一起撤掉，别留在那儿占审核列表
        $pending = Db::name('password_resets')
            ->where('tenant_id', $tid)->where('user_id', $user->id)->find();
        if ($pending) {
            if ((string)$pending['screenshot'] !== '') {
                ImageUpload::delete((string)$pending['screenshot']);
            }
            Db::name('password_resets')->where('id', (int)$pending['id'])->delete();
        }

        $this->logSecurity('找回密码：QQ ' . $qq . ' 用邮箱验证码重置了密码（免登录令牌已全部作废）', '找回密码');
        return api_ok([], '密码已重置，请用新密码登录');
    }

    /**
     * 这个账号的收码邮箱：注册时按 QQ 号自动生成，老数据/站长账号没存的那一份在这里补出来。
     * 查不到人时也算一个（同一句对外文案要用它），不代表真的会往这个地址发东西。
     */
    private function resetEmailFor(?User $user, string $qq): string
    {
        $email = $user ? trim((string)$user->email) : '';
        if ($email !== '' && !Mailer::isEmail($email)) {
            $email = '';
        }
        return $email !== '' ? $email : $qq . '@qq.com';
    }

    /** 那封邮件的正文（纯文本，跟 Mailer 的保守判定一致） */
    private function resetMailBody(string $team, string $code): string
    {
        $site = $team !== '' ? $team . '（' . \app\service\TenantContext::slug() . '）' : '战队站点';
        return implode("\r\n", [
            '你在 ' . $site . ' 用邮箱验证码找回密码。',
            '',
            '你的验证码：' . $code,
            '有效期 ' . intdiv(EmailCode::TTL_SEC, 60) . ' 分钟，只用一次。',
            '',
            '回到那个网站的「忘记密码」页，填 QQ 号、这串验证码和新密码就能直接改好，不用等管理员审核。',
            '',
            '如果不是你本人操作，请忽略这封信 —— 只要没人提交这串验证码，你的密码不会有任何变化。',
        ]);
    }

    // v5.7.1(M2)：退出隐身巡查——作废巡查 cookie 与库内登记，跳回平台控制台
    // 仅隐身上下文有意义；普通登录态调用视为无效请求
    public function stealthExit()
    {
        if (!\app\service\TenantContext::isStealth()) {
            return api_err('当前不在巡查模式', 400, 400);
        }
        stealth_exit();
        return redirect('/app');
    }

    // 组装返回给前端的用户信息
    private function userInfo(User $user): array
    {
        $teamName = null;
        if ($user->team_id) {
            $team = $user->team;
            $teamName = $team ? $team->name : null;
        }
        return [
            'id'         => $user->id,
            'username'   => $user->username,
            'nickname'   => $user->nickname,
            'email'      => $user->email,
            'profile_image' => $user->profile_image,  // v3.9.2：仅本人登录信息，供个人中心展示当前注册截图
            'role'       => $user->role,
            'muted'      => (int)$user->muted,
            'muted_by'   => (string)$user->muted_by,  // v5.30.1：这条禁言是谁给的（platform 的本队解不掉）
            'show_public' => (int)$user->show_public,   // v5.20.0：本人是否同意出现在战队对外展示页
            'stats_public' => (int)$user->stats_public,
            'allow_stranger' => ChatDm::allowsStranger((int)$user->id) ? 1 : 0,   // v5.30.1：允许其它战队的人先开口
            'team_name_suffix' => setting('team_name_suffix', '1') !== '0' ? 1 : 0, // v5.30.1：站名自动补「战队」 // v5.30.0：本人是否同意把战绩给本队成员看（另一档，别混用）
            'team_id'    => $user->team_id,
            'team_name'  => $teamName,
            'created_at' => $user->created_at,
            // v3.9：管理员权限列表（超管返回全部），前端据此显隐功能入口
            'is_super'   => is_super_admin($user),
            'admin_perms'=> admin_perms_of($user),
            // v5.3.0：职责标签（全部持有 + 默认展示）
            'role_tag_ids'=> user_role_tag_ids($user),
            'role_tag'   => display_role_tag($user),
            // v5.36.0：端手分离状态（总开关、八块开关、本人端别与能看哪几个专区）
            'game_side'  => \app\service\GameSide::status($user),
            // v5.36.13：平台协作（队长强制弹窗 / 协作模式标记）
            'support'    => \app\service\Support::statusForClient($user),
        ];
    }
}
