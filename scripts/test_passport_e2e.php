<?php
/**
 * 未晞通行证 E2E（v5.36.6：额外登录方式，密码登录/注册保留）
 *
 * 前置：战队站点测试服务器已启动（php -S 127.0.0.1:8899 -t public public/router.php，.env 指向 sqlite）
 * 本脚本会自行拉起 mock 通行证服务（127.0.0.1:8898），跑完自动关掉。
 *
 * 覆盖：
 *   平台配置（secret 只进不出 / 测试连接 / 非超管拒绝 / 保留目录）
 *   开启通行证后密码登录、注册仍然可用
 *   首次使用：不自动建号、不按邮箱绑定，必须输入本站账号密码
 *   已绑定账号直接登录、重复登录幂等、已绑定冲突拒绝、待审/封禁拒绝
 *   个人中心绑定 / 解绑
 *   JWKS 不可用时的兜底、错误签名拒绝、introspection inactive 拒绝
 *   state 重放、跨会话回调（登录 CSRF）、7 天免登录
 *   入驻平台一键登录：未绑超管/多队歧义/停用账号拒绝，绑超管后进对应入驻账号
 *   关闭通行证后入口消失、密码登录不受影响
 *
 * 用法：php scripts/test_passport_e2e.php
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

$ROOT = dirname(__DIR__);
// 默认 8899；本机端口被占时可 TEST_BASE / MOCK_BASE 指到别的回环地址
$BASE = rtrim((string)(getenv('TEST_BASE') ?: 'http://127.0.0.1:8899'), '/');
$MOCK = rtrim((string)(getenv('MOCK_BASE') ?: 'http://127.0.0.1:8898'), '/');
$DB = $ROOT . '/runtime/test.sqlite';
$MOCK_CFG = $ROOT . '/runtime/_mock_passport_config.json';
$CLIENT_ID = 'test-passport-client';
$CLIENT_SECRET = 'test-passport-' . 'secret';
require $ROOT . '/vendor/autoload.php';

$pass = 0; $fail = 0; $failures = [];
function check(string $name, bool $ok, string $detail = ''): void {
    global $pass, $fail, $failures;
    if ($ok) { $pass++; echo "  [PASS] $name\n"; }
    else { $fail++; $failures[] = $name . ($detail !== '' ? " ($detail)" : ''); echo "  [FAIL] $name" . ($detail !== '' ? " —— $detail" : '') . "\n"; }
}

// ==================== mock 通行证服务 ====================
$mockProc = null;
function mockHealth(): bool {
    global $MOCK;
    $h = @file_get_contents($MOCK . '/control/health', false, stream_context_create(['http' => ['timeout' => 1]]));
    return is_string($h) && strpos($h, '"ok":true') !== false;
}
function startMock(): void {
    global $mockProc, $ROOT;
    if (mockHealth()) { return; }
    $log = $ROOT . '/runtime/_mock_passport_8898.log';
    $ini = php_ini_loaded_file();
    $cmd = escapeshellarg(PHP_BINARY) . ($ini ? ' -c ' . escapeshellarg($ini) : '')
        . ' -S 127.0.0.1:8898 ' . escapeshellarg($ROOT . '/scripts/mock_passport_server.php');
    $mockProc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, $ROOT);
    if (is_resource($mockProc)) { fclose($pipes[0]); }
    for ($i = 0; $i < 60; $i++) {
        usleep(100000);
        if (mockHealth()) { return; }
    }
    throw new RuntimeException('mock 通行证服务没起来，看 runtime/_mock_passport_8898.log');
}
register_shutdown_function(function () {
    global $mockProc;
    if (!is_resource($mockProc)) { return; }
    // Windows 上 proc_terminate 只结束 cmd 外壳，php -S 子进程会留下并拖住回归脚本
    $st = @proc_get_status($mockProc);
    if (PHP_OS_FAMILY === 'Windows' && !empty($st['pid'])) {
        @exec('taskkill /PID ' . (int)$st['pid'] . ' /T /F 2>NUL');
    }
    @proc_terminate($mockProc);
});
startMock();
@file_get_contents($MOCK . '/control/reset');

// 和别的套件一样每轮先清缓存：上一轮末尾的「绑定页猜密码限速」会留在 cache 里，
// 不清的话这一轮第一次输入正确密码也会被判「尝试次数过多」。
foreach ([$ROOT . '/runtime/cache/*/*', $ROOT . '/runtime/cache/*/*/*', $ROOT . '/runtime/cache/*'] as $pat) {
    foreach (glob($pat) ?: [] as $f) { if (is_file($f)) { @unlink($f); } }
}

function setMock(array $overrides): void {
    global $MOCK_CFG, $MOCK, $CLIENT_ID, $CLIENT_SECRET, $BASE;
    $base = [
        'client_id' => $CLIENT_ID,
        'client_secret' => $CLIENT_SECRET,
        'redirect_uri' => $BASE . '/passport/callback',
        'sub' => 'sub-default',
        'name' => '通行证用户',
        'preferred_username' => 'passport-user',
        'email' => 'passport@example.com',
        'email_verified' => true,
        'picture' => '',
        'no_jwks' => false,
        'bad_signature' => false,
        'introspect_active' => true,
        'authorize_deny' => false,
    ];
    file_put_contents($MOCK_CFG, json_encode(array_merge($base, $overrides), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    @file_get_contents($MOCK . '/control/reset');
}

// ==================== HTTP ====================
final class Jar { public $c = []; }
function absUrl(string $u): string {
    global $BASE;
    if ($u === '') { return ''; }
    return strpos($u, 'http') === 0 ? $u : $BASE . $u;
}
function http(Jar $jar, string $method, string $url, $body = null, string $mode = ''): array {
    $ch = curl_init($url);
    $resp = [];
    $headers = [];
    $pairs = [];
    foreach ($jar->c as $k => $v) { $pairs[] = $k . '=' . $v; }
    if ($pairs) { $headers[] = 'Cookie: ' . implode('; ', $pairs); }
    if ($body !== null && $mode === 'json') { $headers[] = 'Content-Type: application/json'; }
    if ($body !== null && $mode === 'form') { $headers[] = 'Content-Type: application/x-www-form-urlencoded'; }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_HEADERFUNCTION => function ($ch, $h) use (&$resp) {
            $resp[] = trim($h);
            return strlen($h);
        },
    ]);
    if ($body !== null) {
        if ($mode === 'json') { curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE)); }
        elseif ($mode === 'form') { curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($body)); }
        else { curl_setopt($ch, CURLOPT_POSTFIELDS, $body); }
    }
    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    foreach ($resp as $h) {
        if (preg_match('/^Set-Cookie:\s*([^=]+)=([^;]*)/i', $h, $m)) {
            $name = trim($m[1]);
            $val = trim($m[2]);
            if ($val === '') { unset($jar->c[$name]); } else { $jar->c[$name] = $val; }
        }
    }
    $loc = '';
    foreach ($resp as $h) { if (preg_match('/^Location:\s*(.+)$/i', $h, $m)) { $loc = trim($m[1]); } }
    return [$status, (string)$raw, $loc, $err];
}
function jreq(Jar $j, string $method, string $path, array $body = null): array {
    global $BASE;
    return http($j, $method, $BASE . $path, $body, $body === null ? '' : 'json');
}
function jsonOf(array $r): array {
    $j = json_decode($r[1], true);
    return is_array($j) ? $j : [];
}

// ==================== 重建夹具 ====================
function rrmdir(string $dir): void {
    if (!is_dir($dir)) { return; }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $f) {
        $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
    @rmdir($dir);
}
@unlink($DB);
rrmdir($ROOT . '/runtime/cache');
foreach (glob($ROOT . '/runtime/session/sess_*') ?: [] as $f) { @unlink($f); }
echo shell_exec('"' . PHP_BINARY . '" "' . $ROOT . '/scripts/dev_init_sqlite.php" "' . $DB . '" 2>&1');

$pdo = new PDO('sqlite:' . $DB);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$now = date('Y-m-d H:i:s');
$mk = static function (string $slug, string $name) use ($pdo, $now): int {
    $pdo->exec("INSERT INTO tenants (applicant_id,slug,name,admin_username,status,created_at,updated_at)
                VALUES (1,'$slug','$name','ptomer','approved','$now','$now')");
    return (int)$pdo->lastInsertId();
};
$mkUser = static function (int $tid, string $username, string $nickname, string $status = 'approved', string $role = 'user', string $pwd = 'member123456') use ($pdo, $now): int {
    $hash = password_hash($pwd, PASSWORD_DEFAULT);
    $pdo->exec("INSERT INTO users (tenant_id,username,password,nickname,email,role,status,created_at,updated_at)
                VALUES ($tid,'$username','$hash','$nickname','$username@qq.com','$role','$status','$now','$now')");
    return (int)$pdo->lastInsertId();
};
$T = $mk('ptest', '通行证测试战队');
$ownerUid = $mkUser($T, 'ptomer', '站长', 'approved', 'admin', 'owner123456');
$pdo->exec("UPDATE tenants SET admin_user_id=$ownerUid WHERE id=$T");
$u1 = $mkUser($T, '10001', '老成员甲');
$u2 = $mkUser($T, '10002', '老成员乙');
$u3 = $mkUser($T, '10003', '老成员丙');
$u4 = $mkUser($T, '10004', '待审成员', 'pending');
$u5 = $mkUser($T, '10005', '封禁成员', 'banned');
$u6 = $mkUser($T, '10006', '备用成员');
$mkPu = static function (string $username, string $nickname, string $role = 'team', string $status = 'normal', string $pwd = 'team123456') use ($pdo, $now): int {
    $hash = password_hash($pwd, PASSWORD_DEFAULT);
    $pdo->exec("INSERT INTO platform_users (username,password,nickname,role,status,created_at,updated_at)
                VALUES ('$username','$hash','$nickname','$role','$status','$now','$now')");
    return (int)$pdo->lastInsertId();
};
$platformTeamId = $mkPu('ptteam', '平台普通账号');

// v5.36.8：平台一键登录夹具——通行证 → 战队超管 → 入驻平台账号
$P = $mk('ptplat', '平台一键登录战队');
$pOwner = $mkUser($P, 'powner', '平台站长', 'approved', 'admin', 'owner123456');
$pdo->exec("UPDATE tenants SET admin_user_id=$pOwner, applicant_id=$platformTeamId WHERE id=$P");

$D = $mk('ptoff', '平台停用战队');
$offOwner = $mkUser($D, 'doff', '停用站长', 'approved', 'admin', 'owner123456');
$offPu = $mkPu('ptoff', '已停用入驻账号', 'team', 'disabled');
$pdo->exec("UPDATE tenants SET admin_user_id=$offOwner, applicant_id=$offPu WHERE id=$D");

$A = $mk('ptadm', '平台超管绑定战队');
$admOwner = $mkUser($A, 'aowner', '超管站长', 'approved', 'admin', 'owner123456');
$pdo->exec("UPDATE tenants SET admin_user_id=$admOwner, applicant_id=1 WHERE id=$A");

$M1 = $mk('ptmulti1', '多队绑定战队一');
$mOwner1 = $mkUser($M1, 'mowner1', '多队站长一', 'approved', 'admin', 'owner123456');
$mPu1 = $mkPu('ptmulti1', '多队账号一');
$pdo->exec("UPDATE tenants SET admin_user_id=$mOwner1, applicant_id=$mPu1 WHERE id=$M1");
$M2 = $mk('ptmulti2', '多队绑定战队二');
$mOwner2 = $mkUser($M2, 'mowner2', '多队站长二', 'approved', 'admin', 'owner123456');
$mPu2 = $mkPu('ptmulti2', '多队账号二');
$pdo->exec("UPDATE tenants SET admin_user_id=$mOwner2, applicant_id=$mPu2 WHERE id=$M2");

$pdo->exec("INSERT INTO passport_links (tenant_id,user_id,passport_sub,created_at,updated_at) VALUES
    ($P,$pOwner,'sub-platform','$now','$now'),
    ($D,$offOwner,'sub-platform-off','$now','$now'),
    ($A,$admOwner,'sub-platform-admin','$now','$now'),
    ($M1,$mOwner1,'sub-platform-multi','$now','$now'),
    ($M2,$mOwner2,'sub-platform-multi','$now','$now')");

echo "夹具：tenant=$T owner=$ownerUid members=$u1,$u2,$u3,$u4,$u5,$u6 platform=$platformTeamId\n";

$jarPlatform = new Jar();
$jarTeam = new Jar();
$jarPwd = new Jar();
$jarClaim = new Jar();
$jarLink = new Jar();
$jarNoJwks = new Jar();

// ==================== 1. 平台配置 ====================
$r = jreq($jarPlatform, 'POST', '/api/platform/auth/login', ['username' => 'admin', 'password' => 'admin123']);
check('平台超管密码登录可用', $r[0] === 200 && (jsonOf($r)['code'] ?? -1) === 0, $r[1]);
$r = jreq($jarPlatform, 'GET', '/api/platform/admin/passport');
$cfg0 = jsonOf($r);
check('平台可读通行证配置，初始未开启', $r[0] === 200 && ($cfg0['data']['enabled'] ?? true) === false);
check('配置初始 secret_set=false', ($cfg0['data']['secret_set'] ?? true) === false);
check('passport_links 表已建', ($cfg0['data']['table_ready'] ?? false) === true);

$r = jreq($jarPlatform, 'POST', '/api/platform/admin/passport/save', [
    'issuer' => $MOCK,
    'client_id' => $CLIENT_ID,
    'client_secret' => $CLIENT_SECRET,
    'redirect_uri' => $BASE . '/passport/callback',
    'scope' => 'openid profile email',
    'enabled' => 1,
]);
$cfg = jsonOf($r);
check('平台保存并开启通行证配置', $r[0] === 200 && ($cfg['code'] ?? -1) === 0, $r[1]);
check('secret 不回显（响应里没有明文）', strpos($r[1], $CLIENT_SECRET) === false);
check('secret 回显只有末四位 + 打码', ($cfg['data']['secret_set'] ?? false) === true
    && strpos((string)($cfg['data']['secret_hint'] ?? ''), 'cret') !== false);

$r = jreq($jarPlatform, 'POST', '/api/platform/admin/passport/test');
$test = jsonOf($r);
check('测试连接四个端点都正常', $r[0] === 200 && ($test['code'] ?? -1) === 0
    && ($test['data']['summary']['discovery'] ?? false) === true
    && ($test['data']['summary']['jwks'] ?? false) === true
    && ($test['data']['summary']['authorize'] ?? false) === true
    && ($test['data']['summary']['userinfo'] ?? false) === true, $r[1]);

$r = jreq($jarTeam, 'POST', '/api/platform/auth/login', ['username' => 'ptteam', 'password' => 'team123456']);
check('平台普通账号可登录', $r[0] === 200 && (jsonOf($r)['code'] ?? -1) === 0, $r[1]);
$r = jreq($jarTeam, 'GET', '/api/platform/admin/passport');
check('非平台超管读通行证配置被拒', $r[0] === 403, $r[1]);

$r = jreq($jarPlatform, 'GET', '/api/platform/check-slug?slug=passport');
check('passport 是保留目录，不能注册成战队', $r[0] === 200 && (jsonOf($r)['code'] ?? 0) !== 0
    && strpos((string)(jsonOf($r)['msg'] ?? ''), '保留') !== false, $r[1]);

// ==================== 2. 密码登录 / 注册仍然可用 ====================
$r = jreq($jarPwd, 'POST', '/ptest/api/auth/login', ['username' => '10003', 'password' => 'member123456']);
check('开启通行证后密码登录仍然可用', $r[0] === 200 && (jsonOf($r)['code'] ?? -1) === 0, $r[1]);
$r = jreq($jarPwd, 'GET', '/ptest/api/auth/me');
check('密码会话身份正确', $r[0] === 200 && (jsonOf($r)['data']['username'] ?? '') === '10003', $r[1]);

$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');
$shot = $ROOT . '/runtime/_passport_e2e_shot.png';
file_put_contents($shot, $png);
$r = http(new Jar(), 'POST', "$BASE/ptest/api/auth/register", [
    'qq' => '10009',
    'nickname' => '新注册成员',
    'password' => 'Str0ngPass2026',
    'confirm' => 'Str0ngPass2026',
    'file' => new CURLFile($shot, 'image/png', 'shot.png'),
]);
$reg = jsonOf($r);
check('开启通行证后密码注册仍然可用', $r[0] === 200 && ($reg['code'] ?? -1) === 0, $r[1]);
$newRegStatus = (string)$pdo->query("SELECT status FROM users WHERE tenant_id=$T AND username='10009'")->fetchColumn();
check('新注册账号仍是待审核', $newRegStatus === 'pending', $newRegStatus);

// ==================== 3. 公开配置 ====================
$r = jreq(new Jar(), 'GET', '/ptest/api/passport/config');
$pc = jsonOf($r);
check('登录页配置接口公开可用且不含 secret', $r[0] === 200 && ($pc['data']['enabled'] ?? false) === true
    && ($pc['data']['ready'] ?? false) === true
    && strpos($r[1], $CLIENT_SECRET) === false, $r[1]);
check('公开配置给出通行证入口', ($pc['data']['start_url'] ?? '') === '/ptest/passport/start', $r[1]);

// ==================== 4. 首次使用：必须输入本站账号密码 ====================
setMock(['sub' => 'sub-new', 'name' => '通行证新用户', 'preferred_username' => '10001', 'email' => 'new@example.com']);
$flow = passportFlow($jarClaim, 'ptest', []);
check('未绑定的通行证进入绑定页', $flow['nextType'] === 'claim', json_encode($flow, JSON_UNESCAPED_UNICODE));
[$cs, $cbody, $ticket] = claimPage($jarClaim, $flow['next']);
check('绑定页渲染并回填同名账号', $cs === 200 && strpos($cbody, '绑定本站账号') !== false
    && strpos($cbody, 'value="10001"') !== false, 'ticket=' . $ticket);
check('绑定页没有自动建号', (int)$pdo->query("SELECT COUNT(*) FROM users WHERE tenant_id=$T AND username LIKE 'sp_%'")->fetchColumn() === 0);
check('绑定页没有自动按邮箱绑定', (int)$pdo->query("SELECT COUNT(*) FROM passport_links WHERE tenant_id=$T")->fetchColumn() === 0);

$r = http($jarClaim, 'POST', "$BASE/ptest/passport/claim", [
    'ticket' => $ticket, 'username' => '10001', 'password' => 'wrong-password',
]);
check('本站密码错误：留在绑定页并提示', $r[0] === 200 && strpos($r[1], '账号或密码不正确') !== false, substr(strip_tags($r[1]), 0, 80));
check('密码错误没有写入绑定', (int)$pdo->query("SELECT COUNT(*) FROM passport_links WHERE tenant_id=$T")->fetchColumn() === 0);

$r = http($jarClaim, 'POST', "$BASE/ptest/passport/claim", [
    'ticket' => $ticket, 'username' => '10001', 'password' => 'member123456',
]);
check('输入正确本站密码后绑定并登录', $r[0] === 302 && strpos($r[2], '/ptest/app') !== false, $r[2]);
$r = jreq($jarClaim, 'GET', '/ptest/api/auth/me');
check('绑定后登录身份是原账号 10001', $r[0] === 200 && (jsonOf($r)['data']['username'] ?? '') === '10001', $r[1]);
check('绑定表落在 10001 上', (int)$pdo->query("SELECT COUNT(*) FROM passport_links WHERE tenant_id=$T AND user_id=$u1 AND passport_sub='sub-new'")->fetchColumn() === 1);

// 已绑定后再次用同一通行证登录：幂等，不再要求绑定
$flow = passportFlow(new Jar(), 'ptest', []);
check('已绑定的通行证直接登录', $flow['nextType'] === 'finish' && $flow['next'] === '/ptest/app', json_encode($flow, JSON_UNESCAPED_UNICODE));

// ==================== 5. 冲突 / 待审 / 封禁 ====================
setMock(['sub' => 'sub-conflict', 'name' => '冲突用户', 'preferred_username' => '10001', 'email' => 'conflict@example.com']);
$jarConflict = new Jar();
$flow = passportFlow($jarConflict, 'ptest', []);
[$cs, $cbody, $ticket] = claimPage($jarConflict, $flow['next']);
$r = http($jarConflict, 'POST', "$BASE/ptest/passport/claim", [
    'ticket' => $ticket, 'username' => '10001', 'password' => 'member123456',
]);
check('已绑定通行证的账号拒绝再次绑定', $r[0] === 200 && strpos($r[1], '已经绑定了另一个通行证账号') !== false, substr(strip_tags($r[1]), 0, 120));
check('冲突没有新增绑定', (int)$pdo->query("SELECT COUNT(*) FROM passport_links WHERE tenant_id=$T")->fetchColumn() === 1);

setMock(['sub' => 'sub-pending', 'name' => '待审用户', 'preferred_username' => '10004', 'email' => 'pending@example.com']);
$jarPending = new Jar();
$flow = passportFlow($jarPending, 'ptest', []);
[$cs, $cbody, $ticket] = claimPage($jarPending, $flow['next']);
$r = http($jarPending, 'POST', "$BASE/ptest/passport/claim", [
    'ticket' => $ticket, 'username' => '10004', 'password' => 'member123456',
]);
check('待审核账号不能绑定', $r[0] === 200 && strpos($r[1], '正在等待队长审核') !== false, substr(strip_tags($r[1]), 0, 120));

setMock(['sub' => 'sub-banned', 'name' => '封禁用户', 'preferred_username' => '10005', 'email' => 'banned@example.com']);
$jarBanned = new Jar();
$flow = passportFlow($jarBanned, 'ptest', []);
[$cs, $cbody, $ticket] = claimPage($jarBanned, $flow['next']);
$r = http($jarBanned, 'POST', "$BASE/ptest/passport/claim", [
    'ticket' => $ticket, 'username' => '10005', 'password' => 'member123456',
]);
check('封禁账号不能绑定', $r[0] === 200 && strpos($r[1], '不是正常状态') !== false, substr(strip_tags($r[1]), 0, 120));

// ==================== 6. 已登录成员主动绑定 / 解绑 ====================
$jarLink = new Jar();
$r = jreq($jarLink, 'POST', '/ptest/api/auth/login', ['username' => '10003', 'password' => 'member123456']);
check('绑定前密码登录成功', $r[0] === 200 && (jsonOf($r)['code'] ?? -1) === 0, $r[1]);
$r = jreq($jarLink, 'GET', '/ptest/api/passport/binding');
check('绑定前个人中心显示未绑定', $r[0] === 200 && (jsonOf($r)['data']['bound'] ?? true) === false);

setMock(['sub' => 'sub-link', 'name' => '绑定丙', 'preferred_username' => 'link-user', 'email' => 'link@example.com']);
$flow = passportFlow($jarLink, 'ptest', [], true);
check('已登录成员绑定后回到个人中心', $flow['next'] === '/ptest/app#/profile', json_encode($flow, JSON_UNESCAPED_UNICODE));
$r = jreq($jarLink, 'GET', '/ptest/api/passport/binding');
check('个人中心显示已绑定', $r[0] === 200 && (jsonOf($r)['data']['bound'] ?? false) === true
    && strpos((string)(jsonOf($r)['data']['sub_masked'] ?? ''), 'sub') === 0, $r[1]);
$r = jreq($jarLink, 'GET', '/ptest/api/auth/me');
check('绑定后仍是 10003', $r[0] === 200 && (jsonOf($r)['data']['username'] ?? '') === '10003', $r[1]);

$flow = passportFlow(new Jar(), 'ptest', []);
check('绑定后通行证可直接登录 10003', $flow['nextType'] === 'finish' && $flow['next'] === '/ptest/app', json_encode($flow, JSON_UNESCAPED_UNICODE));

$r = jreq($jarLink, 'POST', '/ptest/api/passport/unbind');
check('个人中心可以解除绑定', $r[0] === 200 && (jsonOf($r)['code'] ?? -1) === 0, $r[1]);
$r = jreq($jarLink, 'GET', '/ptest/api/passport/binding');
check('解除后显示未绑定', $r[0] === 200 && (jsonOf($r)['data']['bound'] ?? true) === false);
$r = jreq($jarLink, 'POST', '/ptest/api/auth/login', ['username' => '10003', 'password' => 'member123456']);
check('解除绑定后密码登录仍然可用', $r[0] === 200 && (jsonOf($r)['code'] ?? -1) === 0, $r[1]);
$flow = passportFlow(new Jar(), 'ptest', []);
check('解除绑定后通行证回到首次绑定页', $flow['nextType'] === 'claim', json_encode($flow, JSON_UNESCAPED_UNICODE));

// ==================== 7. JWKS 不可用 / 错误签名 / 失效令牌 ====================
setMock(['sub' => 'sub-nojwks', 'name' => '无JWKS用户', 'preferred_username' => '10006', 'email' => 'nojwks@example.com', 'no_jwks' => true]);
$flow = passportFlow($jarNoJwks, 'ptest', []);
check('JWKS 不可用仍进入绑定页（introspection + userinfo 兜底）', $flow['nextType'] === 'claim', json_encode($flow, JSON_UNESCAPED_UNICODE));
[$cs, $cbody, $ticket] = claimPage($jarNoJwks, $flow['next']);
$r = http($jarNoJwks, 'POST', "$BASE/ptest/passport/claim", [
    'ticket' => $ticket, 'username' => '10006', 'password' => 'member123456',
]);
check('JWKS 不可用时仍能完成绑定登录', $r[0] === 302 && strpos($r[2], '/ptest/app') !== false, $r[2]);

setMock(['sub' => 'sub-bad', 'name' => '坏签名', 'preferred_username' => 'bad-sig', 'email' => 'bad@example.com', 'bad_signature' => true]);
$jarBad = new Jar();
$flow = passportFlow($jarBad, 'ptest', []);
check('JWKS 可用但签名错误：拒绝登录', $flow['nextType'] === 'notice' && strpos($flow['next'], 'pn=failed') !== false, json_encode($flow, JSON_UNESCAPED_UNICODE));
check('错误签名没有留下绑定', (int)$pdo->query("SELECT COUNT(*) FROM passport_links WHERE tenant_id=$T AND passport_sub='sub-bad'")->fetchColumn() === 0);

setMock(['sub' => 'sub-inactive', 'name' => '失效令牌', 'preferred_username' => 'inactive', 'email' => 'inactive@example.com', 'introspect_active' => false]);
$jarInactive = new Jar();
$flow = passportFlow($jarInactive, 'ptest', []);
check('introspection 返回 inactive：拒绝登录', $flow['nextType'] === 'notice' && strpos($flow['next'], 'pn=failed') !== false, json_encode($flow, JSON_UNESCAPED_UNICODE));

// ==================== 8. state 重放 / 跨会话回调 ====================
setMock(['sub' => 'sub-new', 'name' => '通行证新用户', 'preferred_username' => '10001', 'email' => 'new@example.com']);
$replay = new Jar();
$start = http($replay, 'GET', "$BASE/ptest/passport/start");
$auth = http($replay, 'GET', $start[2]);
$callback = absUrl($auth[2]);
$first = http($replay, 'GET', $callback);
check('state 第一次使用有效', $first[0] === 302 && strpos($first[2], '/passport/finish') !== false, $first[2]);
$again = http($replay, 'GET', $callback);
check('同一 state 重放被拒', $again[0] === 302 && strpos($again[2], 'pn=state') !== false, $again[2]);

$csrfA = new Jar();
$csrfB = new Jar();
$start = http($csrfA, 'GET', "$BASE/ptest/passport/start");
$auth = http($csrfB, 'GET', $start[2]);
$r = http($csrfB, 'GET', absUrl($auth[2]));
check('回调必须由发起授权的同一会话完成（登录 CSRF）', $r[0] === 302 && strpos($r[2], 'pn=state') !== false, $r[2]);

// ==================== 9. 7 天免登录 ====================
$rememberJar = new Jar();
$flow = passportFlow($rememberJar, 'ptest', [], false, true);
check('勾选免登录后通行证登录成功', $flow['nextType'] === 'finish' && $flow['next'] === '/ptest/app', json_encode($flow, JSON_UNESCAPED_UNICODE));
check('签发 7 天免登录 cookie', isset($rememberJar->c['remember_me']) && strpos($rememberJar->c['remember_me'], '.') !== false);
$r = jreq($rememberJar, 'POST', '/ptest/api/auth/logout');
check('退出登录成功', $r[0] === 200 && (jsonOf($r)['code'] ?? -1) === 0, $r[1]);
$r = jreq($rememberJar, 'GET', '/ptest/api/auth/me');
check('退出后会话失效', $r[0] === 401, $r[1]);

// ==================== 10. 关闭通行证：入口消失、密码登录不受影响 ====================
$r = jreq($jarPlatform, 'POST', '/api/platform/admin/passport/save', [
    'issuer' => $MOCK,
    'client_id' => $CLIENT_ID,
    'redirect_uri' => $BASE . '/passport/callback',
    'scope' => 'openid profile email',
    'enabled' => 0,
]);
check('平台关闭通行证', $r[0] === 200 && (jsonOf($r)['code'] ?? -1) === 0, $r[1]);
$r = jreq(new Jar(), 'GET', '/ptest/api/passport/config');
check('关闭后公开配置 enabled=false', $r[0] === 200 && (jsonOf($r)['data']['enabled'] ?? true) === false, $r[1]);
$r = http(new Jar(), 'GET', "$BASE/ptest/passport/start");
check('关闭后通行证入口回登录页', $r[0] === 302 && strpos($r[2], '/ptest/login?pn=config') !== false, $r[2]);
$r = jreq(new Jar(), 'POST', '/ptest/api/auth/login', ['username' => '10002', 'password' => 'member123456']);
check('关闭通行证后密码登录照常', $r[0] === 200 && (jsonOf($r)['code'] ?? -1) === 0, $r[1]);

// 重新开启，留给浏览器实测
jreq($jarPlatform, 'POST', '/api/platform/admin/passport/save', [
    'issuer' => $MOCK,
    'client_id' => $CLIENT_ID,
    'redirect_uri' => $BASE . '/passport/callback',
    'scope' => 'openid profile email',
    'enabled' => 1,
]);

// ==================== 10.5 入驻平台一键登录（v5.36.8） ====================
$r = jreq(new Jar(), 'GET', '/api/passport/config');
$rootCfg = jsonOf($r);
check('平台登录页可读通行证公开配置', $r[0] === 200 && ($rootCfg['data']['enabled'] ?? false) === true
    && ($rootCfg['data']['start_url'] ?? '') === '/passport/platform/start'
    && strpos($r[1], $CLIENT_SECRET) === false, $r[1]);

setMock(['sub' => 'sub-platform-none', 'name' => '未绑超管', 'preferred_username' => 'p-none', 'email' => 'p-none@example.com']);
$flow = platformPassportFlow(new Jar());
check('未绑定战队超管的通行证：提示先去绑定', $flow['nextType'] === 'notice'
    && strpos($flow['next'], 'pn=admin_needed') !== false, json_encode($flow, JSON_UNESCAPED_UNICODE));

setMock(['sub' => 'sub-platform-admin', 'name' => '超管绑定', 'preferred_username' => 'p-admin', 'email' => 'p-admin@example.com']);
$flow = platformPassportFlow(new Jar());
check('只绑到平台超管：不参与一键登录', $flow['nextType'] === 'notice'
    && strpos($flow['next'], 'pn=admin_needed') !== false, json_encode($flow, JSON_UNESCAPED_UNICODE));

setMock(['sub' => 'sub-platform-off', 'name' => '停用账号', 'preferred_username' => 'p-off', 'email' => 'p-off@example.com']);
$flow = platformPassportFlow(new Jar());
check('绑定超管但平台账号已停用：拒绝', $flow['nextType'] === 'notice'
    && strpos($flow['next'], 'pn=disabled') !== false, json_encode($flow, JSON_UNESCAPED_UNICODE));

setMock(['sub' => 'sub-platform-multi', 'name' => '多队绑定', 'preferred_username' => 'p-multi', 'email' => 'p-multi@example.com']);
$flow = platformPassportFlow(new Jar());
check('同一通行证对应多个入驻账号：不擅自挑一个', $flow['nextType'] === 'notice'
    && strpos($flow['next'], 'pn=admin_multi') !== false, json_encode($flow, JSON_UNESCAPED_UNICODE));

setMock(['sub' => 'sub-platform', 'name' => '平台站长', 'preferred_username' => 'p-owner', 'email' => 'p-owner@example.com']);
$jarP = new Jar();
$flow = platformPassportFlow($jarP);
check('通行证已绑战队超管：一键进入平台', $flow['nextType'] === 'app' && $flow['next'] === '/app',
    json_encode($flow, JSON_UNESCAPED_UNICODE));
check('平台回调沿用发起授权时的会话 ID（不在跨站响应里换）', ($flow['sid_same'] ?? false) === true,
    json_encode($flow, JSON_UNESCAPED_UNICODE));
$r = jreq($jarP, 'GET', '/api/platform/auth/me');
check('平台身份是该战队对应的入驻账号', $r[0] === 200 && (jsonOf($r)['data']['username'] ?? '') === 'ptteam', $r[1]);
$via = (string)$pdo->query("SELECT via FROM platform_login_logs WHERE platform_user_id=$platformTeamId AND result=1 ORDER BY id DESC LIMIT 1")->fetchColumn();
check('平台登录日志记为通行证登录', $via === 'passport', $via);
$r = jreq($jarP, 'GET', '/api/platform/admin/passport');
check('通行证进来的入驻账号没有平台超管权限', $r[0] === 403, $r[1]);

// 平台回调同样绑定发起会话，防止登录 CSRF
$pCsrfA = new Jar();
$pCsrfB = new Jar();
$start = http($pCsrfA, 'GET', "$BASE/passport/platform/start");
$auth = http($pCsrfB, 'GET', $start[2]);
$r = http($pCsrfB, 'GET', absUrl($auth[2]));
check('平台回调必须由发起授权的同一会话完成', $r[0] === 302 && strpos($r[2], 'pn=state') !== false, $r[2]);

$r = jreq(new Jar(), 'POST', '/api/platform/auth/login', ['username' => 'ptteam', 'password' => 'team123456']);
check('平台一键登录上线后密码登录照旧', $r[0] === 200 && (jsonOf($r)['code'] ?? -1) === 0, $r[1]);

// ==================== 11. 绑定页猜密码限速（放在最后，避免锁住前面的用例） ====================
setMock(['sub' => 'sub-rate', 'name' => '限速用户', 'preferred_username' => 'rate-user', 'email' => 'rate@example.com']);
$jarRate = new Jar();
$flow = passportFlow($jarRate, 'ptest', []);
[$cs, $cbody, $ticket] = claimPage($jarRate, $flow['next']);
for ($i = 0; $i < 8; $i++) {
    $r = http($jarRate, 'POST', "$BASE/ptest/passport/claim", [
        'ticket' => $ticket, 'username' => '10003', 'password' => 'wrong-password',
    ]);
    if (strpos($r[1], '尝试次数过多') !== false) {
        break;
    }
}
$r = http($jarRate, 'POST', "$BASE/ptest/passport/claim", [
    'ticket' => $ticket, 'username' => '10003', 'password' => 'member123456',
]);
check('连续猜错后绑定页暂时锁住（密码改对也先拦住）', $r[0] === 200 && strpos($r[1], '尝试次数过多') !== false, substr(strip_tags($r[1]), 0, 80));
check('限速期间没有写入绑定', (int)$pdo->query("SELECT COUNT(*) FROM passport_links WHERE tenant_id=$T AND passport_sub='sub-rate'")->fetchColumn() === 0);

@unlink($shot);
@unlink($MOCK_CFG);

echo "\nPASS=$pass FAIL=$fail\n";
if ($fail > 0) {
    echo "失败项：\n  - " . implode("\n  - ", $failures) . "\n";
    exit(1);
}

// ==================== 辅助：完整授权链路 ====================
function passportFlow(Jar $jar, string $slug, array $mock, bool $linkMode = false, bool $remember = false): array {
    global $BASE;
    if ($linkMode) {
        $r = http($jar, 'GET', "$BASE/$slug/passport/link");
    } else {
        $r = http($jar, 'GET', "$BASE/$slug/passport/start" . ($remember ? '?remember=1' : ''));
    }
    if ($r[0] !== 302 || strpos($r[2], '/oauth/authorize') === false) {
        return ['nextType' => 'start_fail', 'next' => $r[2], 'status' => $r[0], 'body' => $r[1]];
    }
    $authUrl = $r[2] . (strpos($r[2], '?') === false ? '?' : '&') . http_build_query($mock);
    $a = http($jar, 'GET', $authUrl);
    if ($a[0] !== 302 || $a[2] === '') {
        return ['nextType' => 'auth_fail', 'next' => $a[2], 'status' => $a[0], 'body' => $a[1]];
    }
    $c = http($jar, 'GET', absUrl($a[2]));
    $next = $c[2];
    if ($next === '') {
        return ['nextType' => 'no_redirect', 'next' => '', 'status' => $c[0], 'body' => $c[1]];
    }
    if (strpos($next, '/passport/claim') !== false) {
        return ['nextType' => 'claim', 'next' => $next, 'status' => $c[0]];
    }
    if (strpos($next, '/passport/finish') !== false) {
        $f = http($jar, 'GET', absUrl($next));
        return ['nextType' => 'finish', 'next' => $f[2], 'status' => $f[0], 'body' => $f[1]];
    }
    if (strpos($next, '/login?pn=') !== false) {
        return ['nextType' => 'notice', 'next' => $next, 'status' => $c[0]];
    }
    return ['nextType' => 'other', 'next' => $next, 'status' => $c[0]];
}
function claimPage(Jar $jar, string $url): array {
    global $BASE;
    $r = http($jar, 'GET', absUrl($url));
    preg_match('/name="ticket" value="([^"]+)"/', $r[1], $m);
    return [$r[0], $r[1], $m[1] ?? ''];
}

// ==================== 辅助：入驻平台一键登录链路 ====================
function platformPassportFlow(Jar $jar, array $mock = []): array {
    global $BASE;
    $r = http($jar, 'GET', "$BASE/passport/platform/start");
    if ($r[0] !== 302 || strpos($r[2], '/oauth/authorize') === false) {
        return ['nextType' => 'start_fail', 'next' => $r[2], 'status' => $r[0], 'body' => $r[1]];
    }
    $sidStart = (string)($jar->c['PHPSESSID'] ?? '');
    $authUrl = $r[2] . (strpos($r[2], '?') === false ? '?' : '&') . http_build_query($mock);
    $a = http($jar, 'GET', $authUrl);
    if ($a[0] !== 302 || $a[2] === '') {
        return ['nextType' => 'auth_fail', 'next' => $a[2], 'status' => $a[0], 'body' => $a[1]];
    }
    $c = http($jar, 'GET', absUrl($a[2]));
    $next = $c[2];
    $sidEnd = (string)($jar->c['PHPSESSID'] ?? '');
    if ($next === '') {
        return ['nextType' => 'no_redirect', 'next' => '', 'status' => $c[0], 'body' => $c[1]];
    }
    if (strpos($next, '/login?pn=') !== false) {
        return ['nextType' => 'notice', 'next' => $next, 'status' => $c[0]];
    }
    if ($next === '/app') {
        return ['nextType' => 'app', 'next' => $next, 'status' => $c[0],
            'sid_same' => $sidStart !== '' && $sidStart === $sidEnd];
    }
    return ['nextType' => 'other', 'next' => $next, 'status' => $c[0], 'body' => $c[1]];
}
