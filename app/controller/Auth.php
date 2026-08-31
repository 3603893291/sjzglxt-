<?php
declare (strict_types = 1);

namespace app\controller;

use app\BaseController;
use app\model\BlockedIp;
use app\model\User;
use app\service\ImageUpload;
use app\service\IpRegion;
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
        // v5.7.1(L2)：战队认证接口仅在战队目录受理
        if (\app\service\TenantContext::isPlatform()) {
            return api_err('接口不存在', 404, 404);
        }
        // IP 封禁检查（防爆破：自动封禁 + 管理员手动封禁）
        if ($err = $this->checkIpBlocked()) {
            return $err;
        }

        $username = trim((string)$this->request->post('username', ''));
        $password = (string)$this->request->post('password', '');

        if ($username === '' || $password === '') {
            return api_err('请输入 QQ 号和密码');
        }

        // 登录频率限制：同一 IP 15 分钟内最多失败 5 次
        $ipKey = 'login_fail_' . md5(client_ip());
        $failCount = (int)Cache::get($ipKey, 0);
        if ($failCount >= 5) {
            // 超过阈值后自动封禁 IP 并落库，交由管理员在后台解封
            $this->autoBlockIp(client_ip(), '登录爆破自动封禁(连续失败超限)');
            return api_err('尝试次数过多，当前 IP 已被封禁，请联系管理员', 429, 429);
        }

        // v5.7.1(H5)：账号维度爆破防护——分布式 IP 也能锁单个账号：
        // 同账号 15 分钟内失败 10 次 → 锁定 30 分钟（锁定期内该账号任何来源都登不进）
        $tid     = \app\service\TenantContext::id();
        $uHash   = md5($tid . ':' . mb_strtolower($username));
        $userKey = 'login_fail_user_' . $uHash;
        $lockKey = 'login_lock_user_' . $uHash;
        if ((int)Cache::get($lockKey, 0) === 1) {
            return api_err('该账号连续登录失败过多，已临时锁定 30 分钟，请稍后再试', 429, 429);
        }

        $user = User::where('username', $username)->find();
        $ok   = $user && password_verify($password, (string)$user->password);
        if (!$ok) {
            // v5.7.1(M1)：统一对外文案为「账号或密码错误」——账号不存在与密码错误不再区分提示，
            // 防止批量枚举 QQ 号探测战队成员名单；内部两分支都计入爆破计数
            $newCount = $failCount + 1;
            Cache::set($ipKey, $newCount, 900);
            $userFail = (int)Cache::get($userKey, 0) + 1;
            Cache::set($userKey, $userFail, 900);
            if ($userFail >= 10) {
                Cache::set($lockKey, 1, 1800);
                Cache::delete($userKey);
                $this->logSecurity('账号 ' . $username . ' 连续失败 ' . $userFail . ' 次，已临时锁定 30 分钟');
            }
            if ($newCount >= 5) {
                // 第 5 次失败即触发自动封禁，防止继续爆破
                $this->autoBlockIp(client_ip(), '登录爆破自动封禁(5次密码错误)');
                return api_err('连续登录失败过多，当前 IP 已被封禁，请联系管理员', 429, 429);
            }
            return api_err('账号或密码错误');
        }
        if ($user->status === 'pending') {
            return api_err('账号正在审核中，审核通过后才能登录');
        }
        if ($user->status === 'banned') {
            return api_err('账号已被封禁，请联系管理员');
        }

        // 登录成功后清除失败计数（IP + 账号双维度）
        Cache::delete($ipKey);
        Cache::delete($userKey);

        // v3.9.5：每次登录成功刷新用户 IP 与归属地（CDN 场景 client_ip() 取真实 IP）
        $loginIp = client_ip();
        if ((string)$user->reg_ip !== $loginIp) {
            $user->save(['reg_ip' => $loginIp, 'reg_region' => IpRegion::lookup($loginIp)]);
        } elseif ((string)$user->reg_region === '') {
            // IP 没变但上次归属地查询失败（空串）时补查一次
            $user->save(['reg_region' => IpRegion::lookup($loginIp)]);
        }

        // 防会话固定：ThinkPHP 使用自研 Session Store（非原生 PHP session），
        // session_regenerate_id 不生效，必须用 Session::setId 换新 ID（数据保留在内存，随新 ID 落盘）
        session('user_id', $user->id);
        // v5.7.1(H3)：记录本次会话对应的密码纪元，改密后 AuthCheck 比对不一致即强制下线
        session('session_epoch', (int)$user->session_epoch);
        \think\facade\Session::setId(bin2hex(random_bytes(16)));

        // v5.3.0：7天免密码登录——勾选后签发令牌写 cookie，会话过期后凭令牌自动恢复登录；
        // 未勾选时顺带清掉旧令牌，避免旧设备残留免登录
        if ((int)$this->request->post('remember', 0) === 1) {
            remember_issue((int)$user->id);
        } else {
            remember_clear_user((int)$user->id);
        }

        return api_ok($this->userInfo($user), '登录成功');
    }

    // 退出登录（v5.3.0：同步作废 7 天免登录令牌并清 cookie）
    public function logout()
    {
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
        Cache::set($ipKey, (int)Cache::get($ipKey, 0) + 1, 3600);
        Cache::set($tKey, (int)Cache::get($tKey, 0) + 1, 86400);
        return null;
    }

    // v5.7.1(H5)：安全事件写管理操作日志（admin_id=0 表示系统自动防护），静默失败不阻断主流程
    private function logSecurity(string $detail): void
    {
        try {
            Db::name('admin_logs')->insert([
                'tenant_id'  => \app\service\TenantContext::id(),
                'admin_id'   => 0,
                'admin_name' => '安全防护',
                'action'     => '登录防护',
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
            'team_id'    => $user->team_id,
            'team_name'  => $teamName,
            'created_at' => $user->created_at,
            // v3.9：管理员权限列表（超管返回全部），前端据此显隐功能入口
            'is_super'   => is_super_admin($user),
            'admin_perms'=> admin_perms_of($user),
            // v5.3.0：职责标签（全部持有 + 默认展示）
            'role_tag_ids'=> user_role_tag_ids($user),
            'role_tag'   => display_role_tag($user),
        ];
    }
}
