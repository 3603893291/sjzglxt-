<?php
// +----------------------------------------------------------------------
// | 应用公共助手函数
// +----------------------------------------------------------------------

use app\model\User;
use think\facade\Db;

// 全局前端资源版本号（v3.9.3）：每次发版改一次，配合 asset() 强制浏览器刷新 JS/CSS
define('ASSET_BUILD', '5.7.1');

/**
 * API 成功响应
 */
function api_ok($data = [], string $msg = 'ok')
{
    // JSON_INVALID_UTF8_SUBSTITUTE: 容错非法 UTF-8 字节，避免 json_encode 抛错
    return json(['code' => 0, 'msg' => $msg, 'data' => $data])
        ->options(['json_encode_param' => JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE]);
}

/**
 * API 失败响应
 */
function api_err(string $msg = '操作失败', int $code = 1, int $httpCode = 200)
{
    return json(['code' => $code, 'msg' => $msg, 'data' => null], $httpCode)
        ->options(['json_encode_param' => JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE]);
}

/**
 * 当前登录用户（未登录返回 null）
 * v5.1.0：隐身巡查模式下以该租户超管视角只读浏览（ResolveTenant 已强制只读）
 */
function current_user(): ?User
{
    // v5.4.0：开放 API 身份注入（ApiKeyAuth 中间件已鉴权，直接认绑定用户）
    if (\app\middleware\ApiKeyAuth::$apiUserId > 0) {
        return User::find(\app\middleware\ApiKeyAuth::$apiUserId);
    }
    if (\app\service\TenantContext::isStealth()) {
        $tid = \app\service\TenantContext::id();
        $aid = $tid > 0 ? (int)\think\facade\Db::name('tenants')->where('id', $tid)->value('admin_user_id') : 0;
        return $aid > 0 ? User::find($aid) : null;
    }
    $uid = session('user_id');
    if (!$uid) {
        return null;
    }
    return User::find($uid);
}

/**
 * 当前用户是否为管理员
 */
function is_admin(): bool
{
    $user = current_user();
    return $user && $user->role === 'admin';
}

/**
 * 当前用户是否为超级管理员
 * 超管只有两类（v5.2.1 收紧）：
 *   1. 内置超管 username = admin
 *   2. 租户开通时创建的站长账号（username = tenants.admin_username）
 * 注意：被超管"分配了全部权限"的管理员不算超管——他只是权限拿满的管理员，
 * 真超管依然可以改他的权限/封禁/删除，避免授权造出第二个动不了的超管
 */
function is_super_admin(?User $user = null): bool
{
    $user = $user ?: current_user();
    if (!$user || $user->role !== 'admin') {
        return false;
    }
    if ($user->username === 'admin') {
        return true;
    }
    return is_tenant_owner($user);
}

/**
 * 是否本站点的租户站长账号（开通站点时按 tenants.admin_username 创建，持全部权限）
 * 平台上下文 / 无租户记录时恒为 false
 */
function is_tenant_owner(?User $user = null): bool
{
    $user = $user ?: current_user();
    if (!$user) {
        return false;
    }
    $tid = \app\service\TenantContext::id();
    if ($tid <= \app\service\TenantContext::PLATFORM_ID) {
        return false;
    }
    $owner = (string)Db::name('tenants')->where('id', $tid)->value('admin_username');
    return $owner !== '' && $user->username === $owner;
}

/**
 * 站内 URL 拼接（v5.0.0：目录制多租户）
 * 租户上下文自动带 /{slug} 前缀，平台上下文原样返回；redirect()/前端跳转统一走它
 */
function t_url(string $path): string
{
    return \app\service\TenantContext::base() . '/' . ltrim($path, '/');
}

/**
 * 当前平台登录用户（v5.0.0：入驻平台账号，未登录返回 null）
 */
function current_platform_user(): ?\app\model\PlatformUser
{
    $uid = session('platform_user_id');
    if (!$uid) {
        return null;
    }
    return \app\model\PlatformUser::find($uid);
}

/**
 * 当前平台用户是否为平台超管（内置 role=admin 账号，负责入驻审核与租户管理）
 */
function is_platform_super(): bool
{
    // v5.4.0：平台级 API Key 等同平台超管（开放接口无 session，由 ApiKeyAuth 标记）
    if (\app\middleware\ApiKeyAuth::$platformSuper) {
        return true;
    }
    $u = current_platform_user();
    return $u && $u->role === 'admin' && $u->status === 'normal';
}

/**
 * 管理员权限定义（v3.9：超管给普通管理员多选分配）
 * 键 => 中文名称，顺序即前端展示顺序
 */
function admin_perm_defs(): array
{
    return [
        'approve'      => '注册审核通过',
        'member_view'  => '查看成员名单',    // v5.7.1(H2)：成员名单/详情接口的最小可见权限
        'qq_full'      => '用户QQ号完整显示',
        'screenshots'  => '用户上传截图可查看',
        'rename'       => '修改用户名字',
        'mute'         => '禁言',
        'ban'          => '封禁',
        'role_manage'  => '设为管理或取消管理',
        'delete_user'  => '删除账号',
        'reset_pwd'    => '重置密码',
        'team'         => '小队创建与编辑',
        'announce'     => '公告管理',
        'race'         => '撞车时间',
        'custom_race'  => '自建房比赛日程',
        'image_review' => '图片审核',
        'ip_ban'       => 'IP封禁',
        'multi'        => '多账户检测',
        'global_mute'  => '全局禁言',
        'role_tag'     => '职责标签',
        'battle'       => '约战管理',
        'tournament'   => '赛事中心',
        'blacklist'    => '黑名单管理',      // v5.6.0
        'duty'         => '值班表管理',      // v5.6.0
        'schedule'     => '日程活动管理',    // v5.6.0
        'vote'         => '投票管理',        // v5.6.0
        'training'     => '训练赛管理',      // v5.6.0
        'lottery'      => '抽奖管理',        // v5.7.0
    ];
}

/**
 * 取指定用户拥有的管理权限键列表
 * 真超管（内置 admin / 租户站长）恒持全部权限；普通管理员取 users.admin_perms JSON
 */
function admin_perms_of(?User $user): array
{
    if (!$user || $user->role !== 'admin') {
        return [];
    }
    $defs = array_keys(admin_perm_defs());
    if ($user->username === 'admin') {
        return $defs;
    }
    // v5.3.1：租户站长（战队超管）恒持全部权限，不依赖 admin_perms 列——
    // 否则新增权限键（如 role_tag）不在其历史授权 JSON 里，超管会看不到新页签/调不动新接口
    if (is_tenant_owner($user)) {
        return $defs;
    }
    $raw = (string)($user->admin_perms ?? '');
    if ($raw === '') {
        return [];
    }
    $list = json_decode($raw, true);
    if (!is_array($list)) {
        return [];
    }
    return array_values(array_intersect($list, $defs));
}

/**
 * 当前登录管理员是否拥有某项权限（超管恒真）
 */
function admin_can(string $perm, ?User $user = null): bool
{
    $user = $user ?: current_user();
    return in_array($perm, admin_perms_of($user), true);
}

/**
 * v5.7.1(H2)：当前登录管理员是否拥有任一给定权限（超管恒真）
 * 用于成员名单类接口的最小权限守卫：持有任一相关权限才可见
 */
function admin_can_any(array $perms, ?User $user = null): bool
{
    $user = $user ?: current_user();
    if (!$user || $user->role !== 'admin') {
        return false;
    }
    if (is_super_admin($user)) {
        return true;
    }
    $owned = admin_perms_of($user);
    foreach ($perms as $p) {
        if (in_array($p, $owned, true)) {
            return true;
        }
    }
    return false;
}

/**
 * v5.7.1(H5)：弱口令黑名单检测——命中返回拒绝原因，否则返回 null
 * 拦截 qq123456 / a1234567 / password123 一类极易被分布式爆破命中的口令
 */
function is_weak_password(string $pwd): ?string
{
    $lower = strtolower($pwd);
    static $blacklist = [
        'password1', 'password123', 'passw0rd1', 'qwerty123', 'qwertyuiop1',
        'abc12345', 'abcd1234', 'abcd12345', 'admin123', 'admin888', 'root1234',
        '123456abc', '123456aa', '123456ab', 'a12345678', 'aa123456', 'aaa12345',
        'woaini1314', 'iloveyou1', 'asdf1234', 'zxcvbnm1', 'qq123456', 'qq888888',
        '1qaz2wsx', 'qwerty12345', 'abc123456', 'qwe123456', 'asd123456',
    ];
    if (in_array($lower, $blacklist, true)) {
        return '该密码过于常见，容易被爆破，请更换更复杂的密码';
    }
    // 短前缀 + 纯数字（qq12345678 / a1234567 / pass12345 等常见套路）
    if (preg_match('/^(qq|q|a|aa|aaa|abc|pwd|pass|admin|root|test|asd|zxc|woaini|iloveyou)\d{5,10}$/i', $pwd)) {
        return '该密码过于常见，容易被爆破，请更换更复杂的密码';
    }
    // 纯数字 + 单个字母收尾（123456a / 12345678x）
    if (preg_match('/^\d{6,11}[a-z]$/i', $pwd)) {
        return '该密码过于常见，容易被爆破，请更换更复杂的密码';
    }
    return null;
}

/**
 * QQ 号脱敏：保留前 3 后 2，中间打码（无 qq_full 权限的管理员看到的脱敏样式）
 */
function mask_qq(string $qq): string
{
    $len = strlen($qq);
    if ($len <= 5) {
        return substr($qq, 0, 1) . str_repeat('*', max(1, $len - 1));
    }
    return substr($qq, 0, 3) . str_repeat('*', $len - 5) . substr($qq, -2);
}

/**
 * 写管理操作日志（v3.7：记录哪个管理员做了什么操作）
 * 静默失败：日志写不进去不影响主业务
 */
function admin_log(string $action, string $detail = ''): void
{
    try {
        $user = current_user();
        if (!$user) {
            return;
        }
        \app\model\AdminLog::create([
            'admin_id'   => $user->id,
            'admin_name' => ($user->nickname ?: '') . '(' . $user->username . ')',
            'action'     => $action,
            'detail'     => mb_substr($detail, 0, 300),
        ]);
    } catch (\Throwable $e) {
        // 日志表异常不影响主流程
    }
}

/**
 * 读取系统设置
 */
function setting(string $key, string $default = ''): string
{
    return \app\service\Setting::get($key, $default);
}

/**
 * QQ 号格式校验（5-11 位数字）
 */
function is_valid_qq(string $qq): bool
{
    return (bool)preg_match('/^\d{5,11}$/', $qq);
}

/**
 * 静态资源带版本号的 URL（取文件修改时间作 ?v=，文件一变浏览器就自动拉新，根治缓存错乱）
 * 用法（模板里）：<script type="text/babel" data-presets="react" src="<?= asset('/static/js/app.jsx') ?>"></script>
 */
function asset(string $path): string
{
    // v3.9.3：不能只靠 filemtime——部署包在服务器解压时会保留文件原修改时间，
    // ?v= 不变导致浏览器（尤其手机）永远命中旧缓存（仪表盘布局陈旧事故）。
    // 拼接全局资源版本号 ASSET_BUILD，每次发版改一次即可强制全端刷新。
    $full = public_path() . ltrim(str_replace('/', DIRECTORY_SEPARATOR, $path), DIRECTORY_SEPARATOR);
    $v    = (is_file($full) ? filemtime($full) : time()) . '.' . ASSET_BUILD;
    return $path . (strpos($path, '?') === false ? '?' : '&') . 'v=' . $v;
}

/**
 * 获取客户端真实 IP（v5.4.0 CDN 场景）
 * 优先级 1：Nginx real_ip 模块生效时 REMOTE_ADDR 即真实 IP（此时无需配 TRUSTED_PROXIES）；
 * 优先级 2：real_ip 未生效（模块没配/未编译/跑的 Apache）时 REMOTE_ADDR 是 CDN 节点 IP，
 *           需从 X-Forwarded-For 链【从右往左】跳过可信代理段取第一个不可信 IP。
 * 为什么不取最左：X-Forwarded-For 左侧可被客户端任意伪造，右侧由可信代理逐级追加；
 * 只有配置了 TRUSTED_PROXIES（CDN 回源段）且链上全是可信代理时才相信链，否则退回 REMOTE_ADDR。
 * 配置（.env）：TRUSTED_PROXIES = 1.1.1.0/24,2.2.2.0/24,3.3.3.3  （逗号分隔，支持 CIDR/单 IP）
 */
function client_ip(): string
{
    $remote = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
    if ($remote === '' || !filter_var($remote, FILTER_VALIDATE_IP)) {
        return '0.0.0.0';
    }

    $proxies = trusted_proxies();
    if (!$proxies || !ip_in_trusted($remote, $proxies)) {
        // 直连请求（或 REMOTE_ADDR 不在可信代理内）：转发头不可信，只认 REMOTE_ADDR
        return $remote;
    }

    // REMOTE_ADDR 是可信代理（CDN 节点）：从右往左剥掉可信代理，取第一个不可信 IP；
    // 链上剩余全部是可信代理时说明链被伪造/残缺，退回 REMOTE_ADDR 不采信
    $xff = trim((string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''));
    $chain = $xff === '' ? [] : array_values(array_filter(array_map('trim', explode(',', $xff)), 'filter_var'));
    for ($i = count($chain) - 1; $i >= 0; $i--) {
        if (!ip_in_trusted($chain[$i], $proxies)) {
            return $chain[$i];
        }
    }
    return $remote;
}

/** 解析 .env 的 TRUSTED_PROXIES 可信代理列表（每请求缓存一次） */
function trusted_proxies(): array
{
    static $list = null;
    if ($list === null) {
        $raw = (string)env('TRUSTED_PROXIES', '');
        $list = [];
        foreach (explode(',', $raw) as $item) {
            $item = trim($item);
            if ($item === '') {
                continue;
            }
            if (strpos($item, '/') !== false) {
                [$cidrIp, $bits] = explode('/', $item, 2);
                if (filter_var($cidrIp, FILTER_VALIDATE_IP) && ctype_digit($bits)) {
                    $list[] = ['cidr', $cidrIp, (int)$bits];
                }
            } elseif (filter_var($item, FILTER_VALIDATE_IP)) {
                $list[] = ['ip', $item];
            }
        }
    }
    return $list;
}

/** IP 是否命中可信代理列表（单 IP 精确匹配 / CIDR 网段匹配，支持 IPv4 与 IPv6） */
function ip_in_trusted(string $ip, array $proxies): bool
{
    $bin = @inet_pton($ip);
    if ($bin === false) {
        return false;
    }
    foreach ($proxies as $p) {
        if ($p[0] === 'ip') {
            if (@inet_pton($p[1]) === $bin) {
                return true;
            }
            continue;
        }
        // CIDR：同族且前缀位匹配即命中（IPv6 的 ::1/8 等写法同样适用）
        $netBin = @inet_pton($p[1]);
        if ($netBin === false || strlen($netBin) !== strlen($bin) || $p[2] > strlen($bin) * 8) {
            continue;
        }
        $bits = $p[2];
        $match = true;
        for ($i = 0; $bits > 0; $i++) {
            $mask = $bits >= 8 ? 0xFF : (0xFF << (8 - $bits)) & 0xFF;
            if ((ord($bin[$i]) & $mask) !== (ord($netBin[$i]) & $mask)) {
                $match = false;
                break;
            }
            $bits -= 8;
        }
        if ($match) {
            return true;
        }
    }
    return false;
}

/**
 * 渲染视图文件（view/ 目录下的模板）
 */
function render_page(string $template, array $vars = [])
{
    $file = app()->getRootPath() . 'view' . DIRECTORY_SEPARATOR . $template;
    if (!is_file($file)) {
        return response('页面不存在: ' . htmlspecialchars($template), 404);
    }
    extract($vars, EXTR_SKIP);
    ob_start();
    include $file;
    $html = ob_get_clean();
    return response($html)->contentType('text/html; charset=utf-8');
}

// ---------- 隐身巡查（v5.1.0 引入，v5.2.0 加固，v5.7.1 一次性兑票） ----------
// 令牌为"数据库签发制"：只能由平台超管经接口签发，每枚令牌随机生成并落库
// （stealth_tokens 表，记录签发人/租户/有效期），校验只认库内登记——
// 即使代码开源泄露也无法伪造，且全部签发记录可审计。
// v5.7.1(M2)：URL 上的 ?_sv= 令牌仅作一次性兑票——ResolveTenant 校验后立即作废，
// 换发 2 小时 HttpOnly cookie（同样落库登记），令牌不再出现在浏览器历史/日志/Referer 里。

/** 签发隐身入口 token：随机串 + 落库登记（绑定租户与签发人，仅平台超管可调用） */
function stealth_make_token(int $tenantId, string $slug, int $createdBy, int $ttl = 7200): string
{
    $token = bin2hex(random_bytes(24));
    Db::name('stealth_tokens')->insert([
        'tenant_id'  => $tenantId,
        'token_hash' => hash('sha256', $token),
        'created_by' => $createdBy,
        'created_at' => date('Y-m-d H:i:s'),
        'expires_at' => date('Y-m-d H:i:s', time() + $ttl),
    ]);
    // 顺带清掉过期登记，防止表无限膨胀
    Db::name('stealth_tokens')->where('expires_at', '<', date('Y-m-d H:i:s'))->delete();
    return $token;
}

/** 校验隐身 token：必须与库内登记一致且未过期（过期顺带删除） */
function stealth_verify_token(int $tenantId, string $token): bool
{
    if (!preg_match('/^[a-f0-9]{48}$/', $token)) {
        return false;
    }
    $row = Db::name('stealth_tokens')
        ->where('tenant_id', $tenantId)
        ->where('token_hash', hash('sha256', $token))
        ->find();
    if (!$row) {
        return false;
    }
    if (strtotime((string)$row['expires_at']) <= time()) {
        Db::name('stealth_tokens')->where('id', $row['id'])->delete();
        return false;
    }
    return true;
}

/** v5.7.1(M2)：作废一枚已兑现的 URL 令牌（一次性：兑票后立即删除登记） */
function stealth_consume_token(int $tenantId, string $token): void
{
    try {
        Db::name('stealth_tokens')
            ->where('tenant_id', $tenantId)
            ->where('token_hash', hash('sha256', $token))
            ->delete();
    } catch (\Throwable $e) {
        // 删除失败不影响兑票主流程（最坏情况令牌可再次兑票一次，2 小时后仍过期）
    }
}

/** v5.7.1(M2)：登记兑票换发的巡查会话 secret（复用 stealth_tokens 表，2 小时有效） */
function stealth_register_session(int $tenantId, string $secret): void
{
    Db::name('stealth_tokens')->insert([
        'tenant_id'  => $tenantId,
        'token_hash' => hash('sha256', $secret),
        'created_by' => 0,   // 0 = 兑票自动生成（签发人记录在原令牌行，已作废）
        'created_at' => date('Y-m-d H:i:s'),
        'expires_at' => date('Y-m-d H:i:s', time() + 7200),
    ]);
}

/** v5.7.1(M2)：巡查会话 cookie 名（path 按租户目录隔离，各战队互不串） */
function stealth_cookie_name(): string
{
    return 'sv_guard';
}

/** v5.7.1(M2)：退出巡查模式——作废 cookie 与库内登记（GET /api/stealth-exit 调用） */
function stealth_exit(): void
{
    $secret = (string)\think\facade\Cookie::get(stealth_cookie_name(), '');
    if ($secret !== '' && preg_match('/^[a-f0-9]{48}$/', $secret)) {
        try {
            Db::name('stealth_tokens')
                ->where('tenant_id', \app\service\TenantContext::id())
                ->where('token_hash', hash('sha256', $secret))
                ->delete();
        } catch (\Throwable $e) {
            // 静默：cookie 清掉即可，库内登记最迟 2 小时过期
        }
    }
    \think\facade\Cookie::delete(stealth_cookie_name());
}

// ---------- 职责标签（v5.3.0） ----------
// 标签库存 role_tags 表；用户持有关系存 users.role_tags（JSON ID 数组，第一个元素为默认展示）
// 全站开关 settings.role_tag_user_editable 控制用户能否自助改标签（无记录默认开）

/** 当前租户全部标签映射（id => ['name','color']），请求内缓存 */
function role_tags_map(): array
{
    static $map = null;
    if ($map === null) {
        $map = [];
        foreach (\app\model\RoleTag::order('id', 'asc')->select() as $t) {
            $map[(int)$t->id] = ['name' => (string)$t->name, 'color' => (string)$t->color];
        }
    }
    return $map;
}

/** 解析用户持有的标签 ID 数组（保持原顺序，过滤非法/已删除标签） */
function user_role_tag_ids($user): array
{
    $raw = is_object($user) ? (string)($user->role_tags ?? '') : (string)($user['role_tags'] ?? '');
    if ($raw === '') {
        return [];
    }
    $ids = json_decode($raw, true);
    if (!is_array($ids)) {
        return [];
    }
    $map = role_tags_map();
    $out = [];
    foreach ($ids as $id) {
        $id = (int)$id;
        if ($id > 0 && isset($map[$id]) && !in_array($id, $out, true)) {
            $out[] = $id;
        }
    }
    return $out;
}

/** 用户默认展示的职责标签（第一个持有标签；无则 null） */
function display_role_tag($user): ?array
{
    $ids = user_role_tag_ids($user);
    if (count($ids) === 0) {
        return null;
    }
    $map = role_tags_map();
    $id  = $ids[0];
    return ['id' => $id, 'name' => $map[$id]['name'], 'color' => $map[$id]['color']];
}

/** 用户是否被允许自助修改职责标签（管理员全局开关，默认开） */
function role_tag_user_editable(): bool
{
    return setting('role_tag_user_editable', '1') !== '0';
}

// ---------- 跨战队约战（v5.4.0） ----------
// 平台级总线：参赛方 = 租户 | 外部战队；battles/battle_signups/battle_rounds 均无租户列
// 私有接入：api_keys 只存 sha256，明文仅生成时显示一次；业务逻辑集中在 app/service/BattleService.php

/** 生成一枚私有接口密钥：返回 [明文, sha256哈希, 前缀]（明文 48 位十六进制） */
function battle_api_make_key(): array
{
    $plain = bin2hex(random_bytes(24));
    return [$plain, hash('sha256', $plain), substr($plain, 0, 8)];
}

// ---------- 7天免密码登录（v5.3.0） ----------
// 令牌随机生成，只存 sha256 到 remember_tokens（参照隐身令牌做法）；
// cookie 值 = 用户ID.令牌原值，httponly，路径按租户目录隔离（平台上下文禁用）

/** 免登录 cookie 名 */
function remember_cookie_name(): string
{
    return 'remember_me';
}

/** 签发一枚 7 天令牌并写 cookie；返回令牌原值（失败静默返回空串，不影响登录主流程） */
function remember_issue(int $userId): string
{
    try {
        $tid = \app\service\TenantContext::id();
        if ($tid <= \app\service\TenantContext::PLATFORM_ID) {
            return '';   // 仅战队站点启用，平台侧不签发
        }
        $token = bin2hex(random_bytes(24));
        Db::name('remember_tokens')->insert([
            'tenant_id'  => $tid,
            'user_id'    => $userId,
            'token_hash' => hash('sha256', $token),
            'created_at' => date('Y-m-d H:i:s'),
            'expires_at' => date('Y-m-d H:i:s', time() + 7 * 86400),
        ]);
        // 顺带清理过期登记，防止表无限膨胀（只清当前租户）
        Db::name('remember_tokens')
            ->where('tenant_id', $tid)
            ->where('expires_at', '<', date('Y-m-d H:i:s'))->delete();

        $path = \app\service\TenantContext::base();
        \think\facade\Cookie::set(remember_cookie_name(), $userId . '.' . $token, [
            'expire'   => 7 * 86400,
            'path'     => $path === '' ? '/' : $path,
            'httponly' => true,
            'samesite' => 'lax',
        ]);
        return $token;
    } catch (\Throwable $e) {
        return '';
    }
}

/** 删除某用户全部免登录令牌并清 cookie（退出/改密码/删号时调用） */
function remember_clear_user(int $userId): void
{
    try {
        Db::name('remember_tokens')
            ->where('tenant_id', \app\service\TenantContext::id())
            ->where('user_id', $userId)->delete();
    } catch (\Throwable $e) {
        // 静默：令牌清理失败不影响主流程
    }
    \think\facade\Cookie::delete(remember_cookie_name());
}

/**
 * 凭免登录 cookie 恢复会话（令牌轮换制），返回恢复出的用户 ID 或 null。
 * cookie 值 = 用户ID.令牌原值；只认库内登记的 sha256，未过期且账号正常才恢复；
 * 命中后删旧行发新令牌（防令牌被离线重放），并换新 session ID 防固定。
 * 任何一步失败都清 cookie 按未登录处理，不阻断请求。
 * （AuthCheck / AdminCheck 共用：会话过期后访问任一受保护接口都能静默恢复）
 */
function remember_try_login(): ?int
{
    if (\app\service\TenantContext::id() <= \app\service\TenantContext::PLATFORM_ID) {
        return null;   // 平台侧无此机制，也禁止跨上下文拿租户令牌
    }
    $raw = (string)\think\facade\Cookie::get(remember_cookie_name(), '');
    if ($raw === '' || substr_count($raw, '.') !== 1) {
        return null;
    }
    [$uidStr, $token] = explode('.', $raw, 2);
    $uid = (int)$uidStr;
    if ($uid <= 0 || !preg_match('/^[a-f0-9]{48}$/', $token)) {
        \think\facade\Cookie::delete(remember_cookie_name());
        return null;
    }

    $row = Db::name('remember_tokens')
        ->where('tenant_id', \app\service\TenantContext::id())
        ->where('user_id', $uid)
        ->where('token_hash', hash('sha256', $token))
        ->find();
    if (!$row || strtotime((string)$row['expires_at']) <= time()) {
        if ($row) {
            Db::name('remember_tokens')->where('id', $row['id'])->delete();  // 过期顺带清
        }
        \think\facade\Cookie::delete(remember_cookie_name());
        return null;
    }

    // 账号状态校验（封禁/待审核/已删除一律不恢复）
    $user = \app\model\User::find($uid);
    if (!$user || $user->status !== 'approved') {
        Db::name('remember_tokens')->where('id', $row['id'])->delete();
        \think\facade\Cookie::delete(remember_cookie_name());
        return null;
    }

    // 令牌轮换：旧令牌一次性作废，重发新令牌续期 7 天（原流程同样落 cookie）
    Db::name('remember_tokens')->where('id', $row['id'])->delete();
    remember_issue($uid);

    // 恢复会话（换新 session ID 防固定）
    session('user_id', $uid);
    // v5.7.1(H3)：免登录恢复同样盖上当前密码纪元
    session('session_epoch', (int)$user->session_epoch);
    \think\facade\Session::setId(bin2hex(random_bytes(16)));
    return $uid;
}
