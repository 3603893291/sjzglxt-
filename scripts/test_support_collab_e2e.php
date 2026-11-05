<?php
/**
 * v5.36.13 平台协作 E2E
 *
 * 前置：测试服务器已启动，.env 指向 SQLite（注意 .env 里只能用 ; 注释）
 *   php -S 127.0.0.1:8899 -t public public/router.php
 * 用法：php scripts/test_support_collab_e2e.php
 *
 * 覆盖：
 *   1. 队长登录后 me.support.prompt=true；成员不弹；点「暂不授权」本次不再弹，重新登录再弹
 *   2. 只有队长能授权/撤销；成员调用 403
 *   3. 授权后平台租户列表显示已授权；非平台超管不能进入协作
 *   4. 一次性票据：进入成功、302 到管理中心、同票再用一次 403
 *   5. 协作身份：me 是队长账号 + support.mode=true
 *   6. 协作能力：管理中心接口可读可写，操作日志 admin_name 带「平台协作」
 *   7. 协作边界：聊天/个人资料/通行证/清空日志/改授权 全部拒绝
 *   8. 撤销授权：在用协作会话下一步 401；之后再进入 403
 *   9. 主动退出：协作会话结束，平台登录态不受影响
 *  10. 审计：授权/撤销/请求/开始/结束协作都有 admin_logs 行
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

$ROOT = dirname(__DIR__);
$BASE = rtrim((string)(getenv('TEST_BASE') ?: 'http://127.0.0.1:8899'), '/');
$DB   = $ROOT . '/runtime/test.sqlite';

$pass = 0; $fail = 0; $failures = [];
function check(string $name, bool $ok, string $detail = ''): void {
    global $pass, $fail, $failures;
    if ($ok) { $pass++; echo "  [PASS] $name\n"; }
    else { $fail++; $failures[] = $name . ($detail !== '' ? " ($detail)" : ''); echo "  [FAIL] $name" . ($detail !== '' ? " —— $detail\n" : "\n"); }
}

// ---------- HTTP（内存维护 Cookie） ----------
$jars = [];
function jar(string $who): array { global $jars; if (!isset($jars[$who])) { $jars[$who] = []; } return $jars[$who]; }
/** @return array{0:int,1:array,2:array,3:string} [状态码, JSON, 响应头, 原始文本] */
function req(string $who, string $method, string $path, ?array $body = null): array {
    global $BASE, $jars;
    $c = jar($who);
    $hdr = "Content-Type: application/json\r\n";
    if ($c) {
        $pairs = [];
        foreach ($c as $k => $v) { $pairs[] = $k . '=' . $v; }
        $hdr .= 'Cookie: ' . implode('; ', $pairs) . "\r\n";
    }
    $opts = ['http' => ['method' => $method, 'header' => $hdr, 'ignore_errors' => true, 'timeout' => 25, 'follow_location' => 0]];
    if ($body !== null) { $opts['http']['content'] = json_encode($body, JSON_UNESCAPED_UNICODE); }
    $raw = @file_get_contents($BASE . $path, false, stream_context_create($opts));
    $status = 0; $headers = [];
    foreach ($http_response_header ?? [] as $h) {
        $headers[] = $h;
        if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) { $status = (int)$m[1]; }
        if (preg_match('/^Set-Cookie:\s*([^=]+)=([^;]*)/i', $h, $m)) { $c[$m[1]] = trim($m[2]); }
    }
    $jars[$who] = $c;
    $json = json_decode((string)$raw, true);
    return [$status, is_array($json) ? $json : ['raw' => (string)$raw], $headers, (string)$raw];
}
function header_value(array $headers, string $name): string {
    foreach ($headers as $h) {
        if (stripos($h, $name . ':') === 0) { return trim(substr($h, strlen($name) + 1)); }
    }
    return '';
}

// ---------- 直连库 ----------
function db(): PDO {
    global $DB;
    $p = new PDO('sqlite:' . $DB);
    $p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $p->setAttribute(PDO::ATTR_TIMEOUT, 5);
    return $p;
}
function db_all(string $sql, array $p = []): array {
    $st = db()->prepare($sql); $st->execute($p); return $st->fetchAll(PDO::FETCH_ASSOC);
}
function db_one(string $sql, array $p = []): ?array {
    $r = db_all($sql, $p); return $r ? $r[0] : null;
}
function db_val(string $sql, array $p = []) {
    $r = db_one($sql, $p); return $r ? reset($r) : null;
}

// ============================================================
// 0. 重建测试库 + 种子 + 清缓存
// ============================================================
echo "== 0. 重建测试库与种子 ==\n";
@unlink($DB);
echo shell_exec('"' . PHP_BINARY . '" "' . $ROOT . '/scripts/dev_init_sqlite.php" "' . $DB . '" 2>&1');
foreach (glob($ROOT . '/runtime/cache/*/*') ?: [] as $f) { if (is_file($f)) @unlink($f); }
foreach (glob($ROOT . '/runtime/cache/*') ?: [] as $f) { if (is_file($f)) @unlink($f); }

$now = date('Y-m-d H:i:s');
$pdo = db();
$pdo->setAttribute(PDO::ATTR_TIMEOUT, 5);

$slug = 'supa';
$pdo->prepare('INSERT INTO platform_users (username, password, nickname, role, status, reg_ip, created_at, updated_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
    ->execute([$slug . 'owner', password_hash('plat123456', PASSWORD_DEFAULT), '协作测试战队', 'team', 'normal', '127.0.0.1', $now, $now]);
$pid = (int)$pdo->lastInsertId();

$pdo->prepare('INSERT INTO tenants (applicant_id, slug, name, status, admin_username, admin_user_id, created_at, updated_at)
    VALUES (?, ?, ?, ?, ?, NULL, ?, ?)')
    ->execute([$pid, $slug, '协作测试战队', 'approved', $slug . 'boss', $now, $now]);
$tid = (int)$pdo->lastInsertId();

$mkUser = function (string $u, string $nick, string $role, string $status) use ($pdo, $tid, $now): int {
    $st = $pdo->prepare("INSERT INTO users (tenant_id, username, password, nickname, email, profile_image, reg_ip, reg_region,
        role, status, team_id, muted, admin_perms, created_at, updated_at, last_active_at)
        VALUES (?, ?, ?, ?, ?, ?, '127.0.0.1', '本机', ?, ?, 0, 0, '[]', ?, ?, ?)");
    $st->execute([$tid, $u, password_hash('pass123456', PASSWORD_DEFAULT), $nick, $u . '@qq.com',
        '/storage/uploads/shot_' . $u . '.png', $role, $status, $now, $now, date('Y-m-d H:i:s', time() - 120)]);
    return (int)$pdo->lastInsertId();
};
$boss   = $mkUser($slug . 'boss', '协作站长', 'admin', 'approved');
$member = $mkUser($slug . 'mem', '协作队员', 'user', 'approved');
$pdo->prepare('UPDATE tenants SET admin_user_id = ? WHERE id = ?')->execute([$boss, $tid]);

$pdo->prepare('INSERT INTO platform_users (username, password, nickname, role, status, reg_ip, created_at, updated_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
    ->execute(['supportsuper', password_hash('super123456', PASSWORD_DEFAULT), '平台超管', 'admin', 'normal', '127.0.0.1', $now, $now]);
$superPid = (int)$pdo->lastInsertId();

$pdo->prepare('INSERT INTO platform_users (username, password, nickname, role, status, reg_ip, created_at, updated_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
    ->execute(['supportnormal', password_hash('normal123456', PASSWORD_DEFAULT), '平台普通', 'normal', 'normal', '127.0.0.1', $now, $now]);
$normalPid = (int)$pdo->lastInsertId();

function login_platform(string $who, string $username, string $password): array {
    return req($who, 'POST', '/api/platform/auth/login', ['username' => $username, 'password' => $password]);
}
function login_tenant(string $who, string $slug, string $username, string $password): array {
    return req($who, 'POST', '/' . $slug . '/api/auth/login', ['username' => $username, 'password' => $password]);
}

// ============================================================
// 1. 队长提示 / 成员不弹 / 暂不授权
// ============================================================
echo "\n== 1. 队长登录提示 ==\n";
[$s, $j] = login_tenant('owner', $slug, $slug . 'boss', 'pass123456');
check('队长密码登录成功', $s === 200 && (int)($j['code'] ?? -1) === 0, 'HTTP ' . $s);
[$s, $j] = req('owner', 'GET', '/' . $slug . '/api/auth/me');
check('队长 me.support.owner=true', !empty($j['data']['support']['owner']));
check('队长首次登录 prompt=true', !empty($j['data']['support']['prompt']));
check('授权表可用', !empty($j['data']['support']['available']));

[$s, $j] = login_tenant('member', $slug, $slug . 'mem', 'pass123456');
check('成员登录成功', $s === 200 && (int)($j['code'] ?? -1) === 0);
[$s, $j] = req('member', 'GET', '/' . $slug . '/api/auth/me');
check('成员不弹授权窗', empty($j['data']['support']['prompt']));
check('成员不是 owner', empty($j['data']['support']['owner']));

[$s, $j] = req('member', 'POST', '/' . $slug . '/api/support/grant');
check('成员不能授权', (int)($j['code'] ?? 0) === 403, 'HTTP ' . $s . ' / ' . ($j['msg'] ?? ''));

[$s, $j] = req('owner', 'POST', '/' . $slug . '/api/support/dismiss');
check('队长点暂不授权成功', $s === 200 && (int)($j['code'] ?? -1) === 0);
[$s, $j] = req('owner', 'GET', '/' . $slug . '/api/auth/me');
check('暂不授权后本次不再弹', empty($j['data']['support']['prompt']));

req('owner', 'POST', '/' . $slug . '/api/auth/logout');
[$s, $j] = login_tenant('owner', $slug, $slug . 'boss', 'pass123456');
[$s, $j] = req('owner', 'GET', '/' . $slug . '/api/auth/me');
check('重新登录后又弹', !empty($j['data']['support']['prompt']));

// ============================================================
// 2. 授权
// ============================================================
echo "\n== 2. 队长授权 ==\n";
[$s, $j] = req('owner', 'POST', '/' . $slug . '/api/support/grant');
check('队长授权成功', $s === 200 && (int)($j['code'] ?? -1) === 0, $j['msg'] ?? '');
check('授权状态 granted=true', !empty($j['data']['granted']));
[$s, $j] = req('owner', 'GET', '/' . $slug . '/api/auth/me');
check('授权后不再弹窗', empty($j['data']['support']['prompt']));
check('授权后 me.support.granted=true', !empty($j['data']['support']['granted']));

$g = db_one('SELECT * FROM support_grants WHERE tenant_id = ?', [$tid]);
check('授权记录已落库', $g !== null && empty($g['revoked_at']));
check('授权人是队长', (int)($g['granted_by'] ?? 0) === $boss);

// ============================================================
// 3. 平台侧：非超管不能进；超管拿到一次性入口
// ============================================================
echo "\n== 3. 平台侧入口 ==\n";
[$s, $j] = login_platform('platnormal', 'supportnormal', 'normal123456');
check('平台普通账号登录成功', $s === 200 && (int)($j['code'] ?? -1) === 0);
[$s, $j] = req('platnormal', 'POST', '/api/platform/admin/tenants/' . $tid . '/support');
check('平台普通账号不能进入协作', (int)($j['code'] ?? 0) === 403, $j['msg'] ?? '');

[$s, $j] = login_platform('plat', 'supportsuper', 'super123456');
check('平台超管登录成功', $s === 200 && (int)($j['code'] ?? -1) === 0);
[$s, $j] = req('plat', 'GET', '/api/platform/admin/tenants');
$row = null;
foreach (($j['data']['list'] ?? []) as $t) { if ((int)$t['id'] === $tid) { $row = $t; break; } }
check('租户列表带已授权标记', $row !== null && !empty($row['support_granted']));
check('租户列表带授权时间', $row !== null && !empty($row['support_granted_at']));

[$s, $j] = req('plat', 'POST', '/api/platform/admin/tenants/' . $tid . '/support');
check('平台超管取得协作入口', $s === 200 && (int)($j['code'] ?? -1) === 0, $j['msg'] ?? '');
$enterUrl = (string)($j['data']['url'] ?? '');
check('入口是 5 分钟一次性地址', strpos($enterUrl, '/' . $slug . '/support/enter?token=') !== false);

// ============================================================
// 4. 兑票进入 + 一次性
// ============================================================
echo "\n== 4. 一次性票据进入 ==\n";
[$s, $j, $h] = req('plat', 'GET', $enterUrl);
$loc = header_value($h, 'Location');
check('兑票后 302', $s === 302, 'HTTP ' . $s);
check('302 到管理中心', strpos($loc, '/' . $slug . '/app#/admin') !== false, $loc);
check('协作会话已落库', (int)db_val('SELECT COUNT(*) FROM support_sessions WHERE tenant_id = ? AND ended_at IS NULL', [$tid]) === 1);
check('票据已标记使用', db_val('SELECT used_at FROM support_tickets ORDER BY id DESC LIMIT 1') !== null);

[$s, $j] = req('plat', 'GET', $enterUrl);
check('同一张票不能再用', $s === 403 && strpos((string)($j['raw'] ?? ''), '无法进入平台协作') !== false, 'HTTP ' . $s);

// ============================================================
// 5. 协作身份与能力
// ============================================================
echo "\n== 5. 协作身份与能力 ==\n";
[$s, $j] = req('plat', 'GET', '/' . $slug . '/api/auth/me');
check('协作会话的 me 是队长账号', (int)($j['data']['id'] ?? 0) === $boss, 'id=' . ($j['data']['id'] ?? '-'));
check('协作模式标记 mode=true', !empty($j['data']['support']['mode']));
check('协作模式不弹授权窗', empty($j['data']['support']['prompt']));

[$s, $j] = req('plat', 'GET', '/' . $slug . '/api/admin/logs');
check('协作可读管理中心日志', $s === 200 && (int)($j['code'] ?? -1) === 0, 'HTTP ' . $s);

[$s, $j] = req('plat', 'POST', '/' . $slug . '/api/admin/brand-suffix', ['suffix' => 0]);
check('协作可改管理中心设置', $s === 200 && (int)($j['code'] ?? -1) === 0, $j['msg'] ?? '');
$log = db_one("SELECT * FROM admin_logs WHERE tenant_id = ? AND action = '站名显示' ORDER BY id DESC LIMIT 1", [$tid]);
check('操作日志写明平台协作', $log !== null && strpos((string)$log['admin_name'], '平台协作') !== false, $log['admin_name'] ?? '');
check('操作日志带协作前缀', $log !== null && strpos((string)$log['detail'], '[平台协作]') === 0, $log['detail'] ?? '');

// ============================================================
// 6. 协作边界
// ============================================================
echo "\n== 6. 协作边界 ==\n";
[$s, $j] = req('plat', 'GET', '/' . $slug . '/api/chat/messages');
check('协作不能读聊天', (int)($j['code'] ?? 0) === 403, $j['msg'] ?? '');
[$s, $j] = req('plat', 'GET', '/' . $slug . '/api/chat/dm/conversations');
check('协作不能读私聊会话', (int)($j['code'] ?? 0) === 403);
[$s, $j] = req('plat', 'POST', '/' . $slug . '/api/profile/password',
    ['old_password' => 'pass123456', 'new_password' => 'newpass123', 'confirm' => 'newpass123']);
check('协作不能改站长密码', (int)($j['code'] ?? 0) === 403, $j['msg'] ?? '');
[$s, $j] = req('plat', 'POST', '/' . $slug . '/api/support/revoke');
check('协作不能撤销自己的授权', (int)($j['code'] ?? 0) === 403, $j['msg'] ?? '');
[$s, $j] = req('plat', 'POST', '/' . $slug . '/api/admin/logs/clear');
check('协作不能清空操作日志', (int)($j['code'] ?? 0) === 403, $j['msg'] ?? '');
[$s, $j, $h] = req('plat', 'GET', '/' . $slug . '/passport/link');
check('协作不能绑定通行证', $s === 302 && strpos(header_value($h, 'Location'), '/app#/admin') !== false,
    'HTTP ' . $s . ' ' . header_value($h, 'Location'));

[$s, $j] = req('plat', 'POST', '/' . $slug . '/api/admin/users/' . $boss . '/status', ['status' => 'banned']);
check('协作不能封禁站长账号', (int)($j['code'] ?? 0) === 1 && strpos((string)($j['msg'] ?? ''), '受保护') !== false, $j['msg'] ?? '');

// ============================================================
// 7. 主动退出
// ============================================================
echo "\n== 7. 主动退出 ==\n";
[$s, $j] = req('plat', 'POST', '/' . $slug . '/api/support/exit');
check('退出协作成功', $s === 200 && (int)($j['code'] ?? -1) === 0, $j['msg'] ?? '');
[$s, $j] = req('plat', 'GET', '/' . $slug . '/api/auth/me');
check('退出后本站会话失效', $s === 401, 'HTTP ' . $s);
check('退出会话已结束', (int)db_val('SELECT COUNT(*) FROM support_sessions WHERE tenant_id = ? AND ended_at IS NULL', [$tid]) === 0);
[$s, $j] = req('plat', 'GET', '/api/platform/auth/me');
check('平台登录态不受影响', $s === 200 && (int)($j['code'] ?? -1) === 0);

// ============================================================
// 8. 撤销授权立即断开
// ============================================================
echo "\n== 8. 撤销授权 ==\n";
[$s, $j] = req('plat', 'POST', '/api/platform/admin/tenants/' . $tid . '/support');
$enterUrl2 = (string)($j['data']['url'] ?? '');
check('再次取得协作入口', $s === 200 && $enterUrl2 !== '');
[$s, $j] = req('plat', 'GET', $enterUrl2);
check('再次兑票进入', $s === 302);
[$s, $j] = req('plat', 'GET', '/' . $slug . '/api/auth/me');
check('撤销前协作会话有效', !empty($j['data']['support']['mode']));

[$s, $j] = req('owner', 'POST', '/' . $slug . '/api/support/revoke');
check('队长撤销授权成功', $s === 200 && (int)($j['code'] ?? -1) === 0, $j['msg'] ?? '');
check('撤销后授权记录 revoked_at 非空', db_val('SELECT revoked_at FROM support_grants WHERE tenant_id = ?', [$tid]) !== null);

[$s, $j] = req('plat', 'GET', '/' . $slug . '/api/auth/me');
check('撤销后用协作会话再请求 401', $s === 401, 'HTTP ' . $s);
[$s, $j] = req('plat', 'POST', '/api/platform/admin/tenants/' . $tid . '/support');
check('撤销后不能重新进入', (int)($j['code'] ?? 0) === 403, $j['msg'] ?? '');

// ============================================================
// 9. 审计
// ============================================================
echo "\n== 9. 审计 ==\n";
$actions = array_column(db_all('SELECT action FROM admin_logs WHERE tenant_id = ?', [$tid]), 'action');
foreach (['平台协作授权', '平台协作撤销', '请求协作', '开始协作', '结束协作'] as $a) {
    check('日志含「' . $a . '」', in_array($a, $actions, true));
}
check('授权/撤销日志可被站长按人检索', (int)db_val("SELECT COUNT(*) FROM admin_logs WHERE tenant_id = ? AND admin_name LIKE '%平台协作%'", [$tid]) >= 1);

// ============================================================
echo "\n============================================\n";
echo "通过 $pass 项，失败 $fail 项\n";
if ($fail > 0) {
    echo "失败列表：\n";
    foreach ($failures as $f) { echo ' - ' . $f . "\n"; }
    exit(1);
}
echo "全部通过\n";
