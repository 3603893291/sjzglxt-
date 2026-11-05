<?php
/**
 * v5.17.0 管理员登录日志与新归属地提醒 E2E
 *
 * 前置：测试服务器已启动，.env 指向 SQLite（.env 里只能用 ; 注释）
 *   php -S 127.0.0.1:8899 -t public public/router.php
 * 用法：php scripts/test_login_log_e2e.php
 *
 * 覆盖：
 *   1. 只记管理员：成员登录/成员密码错误都不入表；队长成功登录入表且首条不标新归属地
 *   2. 失败尝试：管理员密码错误入表；拿不存在的账号撞库不入表（防撞库刷日志）
 *   3. 新归属地判定：与上一条"成功且归属地非空"的记录不同才 flagged；同归属地不报
 *   4. 免登录 cookie 静默恢复单独记 via=cookie
 *   5. 提醒送达：当事人不需要权限键就能看到自己那几条；已读游标按人走，互不影响
 *   6. 权限：副管理未授权读不到全队日志，队长分配 login_log 后可读
 *   7. 跨租户：B 队队长读不到 A 队任何一行
 *   8. 平台侧：超管跨站点汇总 + 按站点/筛选；普通战队账号 403；超管自己的账号同样出提醒
 *   9. 隐身巡查会话不产生登录日志（v5.16.0 口径不回归）
 *  10. 保留上限：超过 LOGIN_LOG_KEEP 行时最旧的被裁掉
 *  11. 回归：仪表盘 / 成员接口 / 平台接口不受影响
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
function J($a): string { return json_encode($a, JSON_UNESCAPED_UNICODE); }

$jars = [];
function jar(string $who): array { global $jars; if (!isset($jars[$who])) { $jars[$who] = []; } return $jars[$who]; }
function as_req(string $who, string $method, string $path, ?array $body = null, array $extraHdr = []): array {
    global $BASE, $jars;
    $c = jar($who);
    $hdr = "Content-Type: application/json\r\n";
    if ($c) {
        $pairs = [];
        foreach ($c as $k => $v) { $pairs[] = $k . '=' . $v; }
        $hdr .= 'Cookie: ' . implode('; ', $pairs) . "\r\n";
    }
    foreach ($extraHdr as $h) { $hdr .= $h . "\r\n"; }
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

function db(): PDO {
    global $DB;
    $p = new PDO('sqlite:' . $DB);
    $p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    return $p;
}
function db_val(string $sql, array $p = []) {
    $st = db()->prepare($sql); $st->execute($p); $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ? reset($r) : null;
}
function db_rows(string $sql, array $p = []): array {
    $st = db()->prepare($sql); $st->execute($p); return $st->fetchAll(PDO::FETCH_ASSOC);
}
/** 某账号在登录日志里的行数 */
function logCount(int $tid, int $uid, string $extra = ''): int {
    $sql = 'SELECT COUNT(*) FROM login_logs WHERE tenant_id = ' . $tid . ' AND user_id = ' . $uid . $extra;
    return (int)db_val($sql);
}

// ============================================================
// 0. 重建 + 种子
// ============================================================
echo "== 0. 重建测试库与种子 ==\n";
@unlink($DB);
echo shell_exec('"' . PHP_BINARY . '" "' . $ROOT . '/scripts/dev_init_sqlite.php" "' . $DB . '" 2>&1');
foreach (glob($ROOT . '/runtime/cache/*/*') ?: [] as $f) { if (is_file($f)) @unlink($f); }
foreach (glob($ROOT . '/runtime/cache/*') ?: [] as $f) { if (is_file($f)) @unlink($f); }

$now  = date('Y-m-d H:i:s');
$pdo  = db();
$KEEP = 5000;   // 与 common.php 的 LOGIN_LOG_KEEP 一致

function seed_team(string $slug, string $name, array $subPerms): array {
    global $pdo, $now;
    $st = $pdo->prepare('INSERT INTO platform_users (username, password, nickname, role, status, reg_ip, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    $st->execute([$slug . 'owner', password_hash('plat123456', PASSWORD_DEFAULT), $name, 'team', 'normal', '127.0.0.1', $now, $now]);
    $pid = (int)$pdo->lastInsertId();
    $owner_qq = $slug === 'lga' ? '900010001' : '900020001';
    $pdo->exec("INSERT INTO tenants (applicant_id, slug, name, status, admin_username, admin_user_id, created_at, updated_at)
        VALUES ($pid, '$slug', '$name', 'approved', '$owner_qq', NULL, '$now', '$now')");
    $tid = (int)$pdo->lastInsertId();
    $mk = function (string $u, string $nick, string $role, array $perms) use ($pdo, $tid, $now) {
        $j = "'" . json_encode($perms, JSON_UNESCAPED_UNICODE) . "'";
        $pdo->exec("INSERT INTO users (tenant_id, username, password, nickname, role, status, admin_perms, created_at, updated_at, last_active_at)
            VALUES ($tid, '$u', '" . password_hash('pass123456', PASSWORD_DEFAULT) . "', '$nick', '$role', 'approved', $j, '$now', '$now',
            '" . date('Y-m-d H:i:s', time() - 600) . "')");
        return (int)$pdo->lastInsertId();
    };
    $owner  = $mk($owner_qq, $slug . '队长', 'admin', []);
    $sub    = $mk($slug . 'sub', $slug . '副管理', 'admin', $subPerms);
    $member = $mk($slug . 'mem', $slug . '队员', 'user', []);
    $pdo->exec("UPDATE tenants SET admin_user_id = $owner WHERE id = $tid");
    return ['pid' => $pid, 'tid' => $tid, 'owner' => $owner, 'sub' => $sub, 'member' => $member, 'owner_qq' => $owner_qq];
}

$A = seed_team('lga', '日志测试A队', ['member_view']);
$B = seed_team('lgb', '日志测试B队', []);
$tidA = (int)$A['tid']; $tidB = (int)$B['tid'];

$pdo->exec("INSERT INTO platform_users (username, password, nickname, role, status, reg_ip, created_at, updated_at)
    VALUES ('logsuper', '" . password_hash('super123456', PASSWORD_DEFAULT) . "', '平台超管', 'admin', 'normal', '127.0.0.1', '$now', '$now')");
$platUid = (int)$pdo->lastInsertId();

check('种子：两队（队长/副管理/成员）+ 平台超管', $tidA > 0 && $tidB !== $tidA && $platUid > 0, "tidA=$tidA tidB=$tidB");
check('login_logs 表与两个已读游标列已就位',
    (int)db_val("SELECT COUNT(*) FROM pragma_table_info('login_logs')") >= 11
    && (int)db_val("SELECT COUNT(*) FROM pragma_table_info('users') WHERE name = 'login_alert_seen_at'") === 1
    && (int)db_val("SELECT COUNT(*) FROM pragma_table_info('platform_users') WHERE name = 'login_alert_seen_at'") === 1);

// ============================================================
// 1. 只记管理员
// ============================================================
echo "\n== 1. 只记管理员，不记成员 ==\n";
$r0m = logCount($tidA, (int)$A['member']);
[$s, $j] = as_req('a_mem', 'POST', '/lga/api/auth/login', ['username' => 'lgamem', 'password' => 'pass123456']);
check('成员登录成功', ($j['code'] ?? 1) === 0, J($j));
check('成员登录不入登录日志表', logCount($tidA, (int)$A['member']) === $r0m, 'before=' . $r0m . ' after=' . logCount($tidA, (int)$A['member']));

$r0 = logCount($tidA, (int)$A['owner']);
[$s, $j] = as_req('a_owner', 'POST', '/lga/api/auth/login', ['username' => $A['owner_qq'], 'password' => 'pass123456']);
check('队长登录成功', ($j['code'] ?? 1) === 0, J($j));
$first = db_rows("SELECT * FROM login_logs WHERE tenant_id = $tidA AND user_id = {$A['owner']} ORDER BY id DESC LIMIT 1");
check('队长登录写入一行（via=pwd / result=success）',
    count($first) === 1 && $first[0]['via'] === 'pwd' && $first[0]['result'] === 'success',
    J($first[0] ?? null));
check('首条登录不标新归属地（没有可比的上一条，不能吓人的），flagged=0',
    isset($first[0]) && (int)$first[0]['flagged'] === 0, J($first[0] ?? null));
check('日志带出 IP 与 UA（内网识别为「内网/本地」）',
    isset($first[0]) && $first[0]['ip'] === '127.0.0.1' && $first[0]['region'] !== '', J($first[0] ?? null));

// ============================================================
// 2. 失败尝试
// ============================================================
echo "\n== 2. 失败尝试只记管理员账号 ==\n";
$rb = logCount($tidA, (int)$A['sub']);
[$s, $j] = as_req('x', 'POST', '/lga/api/auth/login', ['username' => 'lgasub', 'password' => 'WrongPass1']);
check('副管理员密码错误被拒', ($j['code'] ?? 0) !== 0, J($j));
$failRow = db_rows("SELECT * FROM login_logs WHERE tenant_id = $tidA AND user_id = {$A['sub']} AND result = 'fail' ORDER BY id DESC LIMIT 1");
check('管理员账号的失败尝试入表（result=fail，不标新归属地）',
    count($failRow) === 1 && (int)$failRow[0]['flagged'] === 0, J($failRow[0] ?? null));
check('失败尝试不影响该账号的成功行数', logCount($tidA, (int)$A['sub'], " AND result = 'success'") === 0);

$ghost = (int)db_val("SELECT COUNT(*) FROM login_logs WHERE tenant_id = $tidA AND username = 'ghostqq'");
[$s, $j] = as_req('x2', 'POST', '/lga/api/auth/login', ['username' => 'ghostqq', 'password' => 'Whatever1']);
check('不存在的账号登录被拒', ($j['code'] ?? 0) !== 0, J($j));
check('撞库枚举不存在的 QQ 号不写日志（防撞库刷表）',
    (int)db_val("SELECT COUNT(*) FROM login_logs WHERE tenant_id = $tidA AND username = 'ghostqq'") === $ghost);

$memFailBefore = logCount($tidA, (int)$A['member']);
[$s, $j] = as_req('x3', 'POST', '/lga/api/auth/login', ['username' => 'lgamem', 'password' => 'WrongPass1']);
check('成员密码错误也不入表', logCount($tidA, (int)$A['member']) === $memFailBefore);
$pdo->exec('DELETE FROM blocked_ips');   // 前面的失败可能已触发自动封禁，清掉以免影响后续断言

// ============================================================
// 3. 新归属地判定
// ============================================================
echo "\n== 3. 新归属地才标 flagged ==\n";
$before = logCount($tidA, (int)$A['owner']);
[$s, $j] = as_req('a_owner2', 'POST', '/lga/api/auth/login', ['username' => $A['owner_qq'], 'password' => 'pass123456']);
check('同归属地再次登录不标新归属地', ($j['code'] ?? 1) === 0
    && logCount($tidA, (int)$A['owner'], " AND flagged = 1") === 0, J($j));

// 伪造一条"上一次成功登录在别处"的记录（本机测试没法真的换城市）
$farRegion = '上海';
$pdo->exec("INSERT INTO login_logs (tenant_id, user_id, username, ip, region, ua, via, result, flagged, created_at)
    VALUES ($tidA, {$A['owner']}, '{$A['owner_qq']}', '210.10.10.10', '$farRegion', 'seed', 'pwd', 'success', 0,
    '" . date('Y-m-d H:i:s', time() - 60) . "')");
[$s, $j] = as_req('a_owner3', 'POST', '/lga/api/auth/login', ['username' => $A['owner_qq'], 'password' => 'pass123456']);
$new = db_rows("SELECT * FROM login_logs WHERE tenant_id = $tidA AND user_id = {$A['owner']} ORDER BY id DESC LIMIT 1");
check('归属地与上一条成功记录不同 ⇒ flagged=1', ($j['code'] ?? 1) === 0 && isset($new[0]) && (int)$new[0]['flagged'] === 1,
    J($new[0] ?? null));
check('本次登录行的 region 记的是本次归属地，不是上次的',
    isset($new[0]) && $new[0]['region'] !== $farRegion, (string)($new[0]['region'] ?? ''));
check('每条登录各写一行（本段两次成功 + 一条伪造记录，不多写不漏写）',
    logCount($tidA, (int)$A['owner']) === $before + 3, 'before=' . $before . ' now=' . logCount($tidA, (int)$A['owner']));

// ============================================================
// 4. 免登录 cookie 静默恢复
// ============================================================
echo "\n== 4. via=cookie 单独标注 ==\n";
$ckBefore = logCount($tidA, (int)$A['sub'], " AND via = 'cookie'");
[$s, $j] = as_req('sub_rem', 'POST', '/lga/api/auth/login', ['username' => 'lgasub', 'password' => 'pass123456', 'remember' => 1]);
check('副管理员勾选免登录登录成功', ($j['code'] ?? 1) === 0, J($j));
$remCookie = jar('sub_rem')['remember_me'] ?? '';
check('拿到免登录 cookie（用户ID.令牌）', substr_count((string)$remCookie, '.') === 1, (string)$remCookie);
// 只留 remember cookie，模拟会话过期后的第一次访问
$jars['sub_rem'] = ['remember_me' => $remCookie];
[$s, $j] = as_req('sub_rem', 'GET', '/lga/api/auth/me');
check('会话过期后凭 cookie 静默恢复身份', ($j['code'] ?? 1) === 0 && (string)($j['data']['username'] ?? '') === 'lgasub', J($j));
check('恢复登录单独记一行 via=cookie',
    logCount($tidA, (int)$A['sub'], " AND via = 'cookie'") === $ckBefore + 1,
    'before=' . $ckBefore . ' now=' . logCount($tidA, (int)$A['sub'], " AND via = 'cookie'"));

// ============================================================
// 4b. 免登录不再被自己抹掉（v5.19.0：并发误删 / 别处登录清光 两处修复的回归）
// ============================================================
echo "\n== 4b. 同一枚令牌反复可用，别处登录不误伤本机 ==\n";
$subUid  = (int)$A['sub'];
$tokRows = function (int $uid): int {
    return (int)db_val('SELECT COUNT(*) FROM remember_tokens WHERE user_id = ' . $uid);
};
$rowsBase = $tokRows($subUid);
check('勾选「记住我」登录只在库里留下一枚令牌', $rowsBase === 1, 'rows=' . $rowsBase);

$tok = $remCookie; $allOk = true; $sameToken = true;
for ($i = 0; $i < 3; $i++) {
    $jars['sub_rem'] = ['remember_me' => $tok];   // 每次都当作"会话又过期"，仍交同一枚旧 cookie
    [$s, $j] = as_req('sub_rem', 'GET', '/lga/api/auth/me');
    if ((int)($j['code'] ?? 1) !== 0) { $allOk = false; }
    $after = jar('sub_rem')['remember_me'] ?? '';
    if ($after !== $tok) { $sameToken = false; }  // 服务端若换了/删了 cookie，这里就会不一致
    $tok = $after !== '' ? $after : $tok;
}
check('同一枚令牌连续恢复 3 次都成功（旧写法后两次会把 cookie 删掉）', $allOk);
check('恢复用的还是同一枚令牌值，浏览器不会收到删除指令', $sameToken, 'now=' . substr((string)$tok, 0, 12));
check('不轮换 ⇒ 库里没有多出被轮换出来的行', $tokRows($subUid) === $rowsBase, 'rows=' . $tokRows($subUid));
check('续期把这枚令牌的有效期推到 7 天以后',
    (int)db_val("SELECT COUNT(*) FROM remember_tokens WHERE user_id = $subUid AND expires_at > '"
        . date('Y-m-d H:i:s', time() + 6 * 86400) . "'") === 1);

// 另一台设备用同一账号登录但没勾记住我 —— 以前这里会把该账号在本队的全部令牌删光
[$s, $j] = as_req('other_dev', 'POST', '/lga/api/auth/login',
    ['username' => 'lgasub', 'password' => 'pass123456', 'remember' => 0]);
check('另一台设备不勾记住我登录成功', (int)($j['code'] ?? 1) === 0, J($j));
check('本机那枚免登录没被牵连（只撤销当前设备）', $tokRows($subUid) === $rowsBase, 'rows=' . $tokRows($subUid));
$jars['sub_rem'] = ['remember_me' => $tok];
[$s, $j] = as_req('sub_rem', 'GET', '/lga/api/auth/me');
check('本机凭原来那枚 cookie 仍能静默恢复', (int)($j['code'] ?? 1) === 0, J($j));

// 本机自己带着旧 cookie 不勾记住我登录时，应当只撤销本机这一枚，不留无人认领的令牌
$jars['sub_self'] = ['remember_me' => $tok];
[$s, $j] = as_req('sub_self', 'POST', '/lga/api/auth/login',
    ['username' => 'lgasub', 'password' => 'pass123456', 'remember' => 0]);
check('本机不勾记住我重新登录成功', (int)($j['code'] ?? 1) === 0, J($j));
check('本机那枚被撤销', $tokRows($subUid) === $rowsBase - 1, 'rows=' . $tokRows($subUid));

// 主动退出登录仍然全设备失效（这是明确的安全语义，本轮不动）
[$s, $j] = as_req('dev_c', 'POST', '/lga/api/auth/login',
    ['username' => 'lgasub', 'password' => 'pass123456', 'remember' => 1]);
check('第三台设备勾记住我登录，令牌数回到 1', $tokRows($subUid) === 1, 'rows=' . $tokRows($subUid));
[$s, $j] = as_req('dev_c', 'POST', '/lga/api/auth/logout');
check('主动退出登录仍把这个账号的免登录全清（语义不变）', $tokRows($subUid) === 0, 'rows=' . $tokRows($subUid));

// ============================================================
// 5. 提醒：当事人不需要权限键，已读游标按人
// ============================================================
echo "\n== 5. 提醒送达与已读游标 ==\n";
// 给副管理也造一条新归属地登录（先伪造成一条别处的成功记录）
$pdo->exec("INSERT INTO login_logs (tenant_id, user_id, username, ip, region, ua, via, result, flagged, created_at)
    VALUES ($tidA, {$A['sub']}, 'lgasub', '210.20.20.20', '广东 广州', 'seed', 'pwd', 'success', 0,
    '" . date('Y-m-d H:i:s', time() - 30) . "')");
[$s, $j] = as_req('sub_alert', 'POST', '/lga/api/auth/login', ['username' => 'lgasub', 'password' => 'pass123456']);
[$s, $j] = as_req('sub_alert', 'GET', '/lga/api/admin/login-alerts');
check('当事人（未授权登录日志权限）能读到自己的提醒', ($j['code'] ?? 1) === 0 && count($j['data']['list'] ?? []) >= 1, J($j));
check('提醒里只出当事人自己的账号（can_view=false 时不给全队数据）',
    ($j['data']['can_view'] ?? true) === false && (int)$j['data']['team_unread'] === 0, J($j['data'] ?? null));
$ownUnread = (int)($j['data']['unread'] ?? 0);
[$s, $oj] = as_req('a_owner', 'GET', '/lga/api/admin/login-alerts');
$ownerBefore = (int)($oj['data']['unread'] ?? 0);
check('队长的 unread 只是他自己的，全队条数走 team_unread',
    ($oj['code'] ?? 1) === 0 && ($oj['data']['can_view'] ?? false) === true
    && $ownerBefore >= 1 && (int)($oj['data']['team_unread'] ?? 0) >= $ownUnread + $ownerBefore,
    J(['own' => $ownerBefore, 'team' => $oj['data']['team_unread'] ?? null, 'sub' => $ownUnread]));

[$s, $j] = as_req('sub_alert', 'POST', '/lga/api/admin/login-alerts/read');
check('当事人标记已读成功', ($j['code'] ?? 1) === 0, J($j));
[$s, $j] = as_req('sub_alert', 'GET', '/lga/api/admin/login-alerts');
check('标记后当事人未读归零', (int)($j['data']['unread'] ?? -1) === 0, J($j['data'] ?? null));
[$s, $oj2] = as_req('a_owner', 'GET', '/lga/api/admin/login-alerts');
check('当事人的已读不影响队长的游标（按人存）',
    (int)($oj2['data']['unread'] ?? 0) === $ownerBefore, 'owner=' . ($oj2['data']['unread'] ?? '?') . ' before=' . $ownerBefore);
check('游标确实落在当事人这一行上',
    (string)db_val("SELECT login_alert_seen_at FROM users WHERE id = {$A['sub']}") !== ''
    && db_val("SELECT login_alert_seen_at FROM users WHERE id = {$A['owner']}") === null);

// ============================================================
// 6. 权限键 login_log
// ============================================================
echo "\n== 6. 权限键分配 ==\n";
[$s, $j] = as_req('sub_alert', 'GET', '/lga/api/admin/login-logs');
check('未授权副管理读全队日志被拒 403', $s === 403 && ($j['code'] ?? 0) === 403, "http=$s " . J($j));
[$s, $j] = as_req('a_owner', 'GET', '/lga/api/admin/login-logs?size=50');
check('队长无需分配即可读全队日志', ($j['code'] ?? 1) === 0 && count($j['data']['list'] ?? []) >= 3, J($j));
$listed = $j['data']['list'] ?? [];
$hasSubFail = false; $hasOwnerFlag = false;
foreach ($listed as $r) {
    if ((int)$r['user_id'] === (int)$A['sub'] && $r['result'] === 'fail') { $hasSubFail = true; }
    if ((int)$r['user_id'] === (int)$A['owner'] && $r['flagged']) { $hasOwnerFlag = true; }
}
check('列表带出失败尝试与新归属地标记得行', $hasSubFail && $hasOwnerFlag, json_encode([$hasSubFail, $hasOwnerFlag]));
check('列表不外带密码类字段', strpos(json_encode($listed), 'password') === false);

[$s, $j] = as_req('a_owner', 'GET', '/lga/api/admin/perms');
$defKeys = [];
foreach (($j['data']['defs'] ?? []) as $k => $v) {
    $defKeys[] = (is_array($v) && isset($v['key'])) ? (string)$v['key'] : (string)$k;
}
check('权限定义里出现「登录日志」', in_array('login_log', $defKeys, true), J($defKeys));
[$s, $j] = as_req('a_owner', 'POST', '/lga/api/admin/perms/save', ['admin_id' => $A['sub'], 'perms' => ['member_view', 'login_log']]);
check('队长把 login_log 分配给副管理', ($j['code'] ?? 1) === 0, J($j));
[$s, $j] = as_req('sub_alert', 'GET', '/lga/api/admin/login-logs?size=50');
check('授权后副管理能读全队日志', ($j['code'] ?? 1) === 0, J($j));
[$s, $j] = as_req('sub_alert', 'GET', '/lga/api/admin/login-logs?filter=flagged');
$allFlag = true;
foreach (($j['data']['list'] ?? []) as $r) { if (!$r['flagged']) { $allFlag = false; } }
check('按「仅新归属地」筛选结果干净', ($j['code'] ?? 1) === 0 && $allFlag && count($j['data']['list'] ?? []) >= 2, J($j['data'] ?? null));
[$s, $j] = as_req('sub_alert', 'GET', '/lga/api/admin/login-alerts');
check('授权后本人提醒接口也标出 can_view=true', ($j['code'] ?? 1) === 0 && ($j['data']['can_view'] ?? false) === true, J($j['data'] ?? null));

// ============================================================
// 7. 跨租户隔离
// ============================================================
echo "\n== 7. 跨租户隔离 ==\n";
[$s, $bJ] = as_req('b_owner', 'POST', '/lgb/api/auth/login', ['username' => $B['owner_qq'], 'password' => 'pass123456']);
check('B 队队长登录', ($bJ['code'] ?? 1) === 0, J($bJ));
[$s, $j] = as_req('b_owner', 'GET', '/lgb/api/admin/login-logs?size=100');
$leak = false;
foreach (($j['data']['list'] ?? []) as $r) {
    if ((string)$r['qq'] === (string)$A['owner_qq'] || (string)$r['qq'] === 'lgasub') { $leak = true; }
}
check('B 队队长读不到 A 队任何一行', ($j['code'] ?? 1) === 0 && !$leak, J($j['data'] ?? null));
check('B 队只看到自己的行', (int)($j['data']['total'] ?? 0) === logCount($tidB, (int)$B['owner']),
    'total=' . ($j['data']['total'] ?? '?') . ' db=' . logCount($tidB, (int)$B['owner']));
[$s, $j] = as_req('a_mem', 'GET', '/lga/api/admin/login-logs');
check('普通成员调登录日志：拒绝', $s === 401 || $s === 403, "http=$s");
[$s, $j] = as_req('a_mem', 'GET', '/lga/api/admin/login-alerts');
check('普通成员调本人提醒接口：拒绝（不是管理员）', $s === 403 || $s === 401, "http=$s");

// ============================================================
// 8. 平台侧
// ============================================================
echo "\n== 8. 平台超管侧 ==\n";
[$s, $j] = as_req('super', 'POST', '/api/platform/auth/login', ['username' => 'logsuper', 'password' => 'super123456']);
check('平台超管登录', ($j['code'] ?? 1) === 0, J($j));
[$s, $j] = as_req('super', 'GET', '/api/platform/admin/login-logs?limit=200');
check('跨站点登录日志：code 0 且带站点名', ($j['code'] ?? 1) === 0 && count($j['data']['list'] ?? []) > 0
    && ($j['data']['list'][0]['tenant_name'] ?? '') !== '', J($j['data']['list'][0] ?? null));
$both = [];
foreach (($j['data']['list'] ?? []) as $r) { $both[(int)$r['tenant_id']] = true; }
check('两队记录都能汇总到', isset($both[$tidA]) && isset($both[$tidB]), J(array_keys($both)));
check('汇总接口给出全平台新归属地与失败计数',
    (int)($j['data']['flagged_total'] ?? 0) >= 2 && (int)($j['data']['fail_total'] ?? 0) >= 1,
    J(['flagged' => $j['data']['flagged_total'] ?? null, 'fail' => $j['data']['fail_total'] ?? null]));
[$s, $j] = as_req('super', 'GET', '/api/platform/admin/login-logs?tenant_id=' . $tidB . '&limit=200');
$otherTid = false;
foreach (($j['data']['list'] ?? []) as $r) { if ((int)$r['tenant_id'] !== $tidB) { $otherTid = true; } }
check('平台侧按站点筛选不吃别的战队', ($j['code'] ?? 1) === 0 && !$otherTid);
[$s, $j] = as_req('b_owner', 'GET', '/api/platform/admin/login-logs');
check('战队站长调平台跨站接口：拒绝', $s === 403 || $s === 404 || $s === 401, "http=$s");

// 平台超管自己的新归属地提醒
$pdo->exec("INSERT INTO platform_login_logs (platform_user_id, username, ip, region, result, via, flagged, created_at)
    VALUES ($platUid, 'logsuper', '210.30.30.30', '北京', 1, 'pwd', 0, '" . date('Y-m-d H:i:s', time() - 20) . "')");
[$s, $j] = as_req('super2', 'POST', '/api/platform/auth/login', ['username' => 'logsuper', 'password' => 'super123456']);
check('平台超管再次登录', ($j['code'] ?? 1) === 0, J($j));
[$s, $j] = as_req('super', 'GET', '/api/platform/my/login-logs');
$newestP = $j['data']['list'][0] ?? [];
check('平台侧本次登录标为新归属地（超管自己也要提醒）',
    ($j['code'] ?? 1) === 0 && ($newestP['flagged'] ?? false) === true, J($newestP));
check('平台侧未读计数生效', (int)($j['data']['unread'] ?? 0) >= 1, J(['unread' => $j['data']['unread'] ?? null]));
[$s, $j] = as_req('super', 'POST', '/api/platform/my/login-alerts/read');
check('平台侧标记已读', ($j['code'] ?? 1) === 0, J($j));
[$s, $j] = as_req('super', 'GET', '/api/platform/my/login-logs');
check('平台侧已读后未读归零', (int)($j['data']['unread'] ?? -1) === 0, J(['unread' => $j['data']['unread'] ?? null]));
check('平台日志带出 via 字段', isset($j['data']['list'][0]['via']), J($j['data']['list'][0] ?? null));

// ============================================================
// 9. 隐身巡查不产生登录日志
// ============================================================
echo "\n== 9. 巡查会话不写登录日志 ==\n";
$before9 = (int)db_val("SELECT COUNT(*) FROM login_logs WHERE tenant_id = $tidA");
[$s, $j] = as_req('super', 'POST', "/api/platform/admin/tenants/$tidA/stealth");
$svToken = (string)($j['data']['token'] ?? '');
check('签发巡查入口', ($j['code'] ?? 1) === 0 && $svToken !== '', J($j));
[$s, $j] = as_req('sv', 'GET', '/lga/api/inspect/overview?_sv=' . $svToken);
check('巡查会话能读台账', ($j['code'] ?? 1) === 0, J($j));
[$s, $j] = as_req('sv', 'GET', '/lga/api/dashboard');
check('巡查会话仍可读前台仪表盘', ($j['code'] ?? 1) === 0, J($j));
check('巡查浏览不产生任何登录日志行',
    (int)db_val("SELECT COUNT(*) FROM login_logs WHERE tenant_id = $tidA") === $before9,
    'before=' . $before9 . ' after=' . (int)db_val("SELECT COUNT(*) FROM login_logs WHERE tenant_id = $tidA"));

// ============================================================
// 10. 保留上限裁剪
// ============================================================
echo "\n== 10. 超过 " . $KEEP . " 行自动裁最旧 ==\n";
$vals = [];
$baseTs = time() - 90000;
for ($i = 0; $i < $KEEP + 6; $i++) {
    $vals[] = "($tidA, 999001, 'bulk$i', '1.1.1.1', '内网/本地', 'bulk', 'pwd', 'success', 0, "
        . "'" . date('Y-m-d H:i:s', $baseTs + $i) . "')";
}
$pdo->exec('DELETE FROM login_logs WHERE user_id = 999001');
$pdo->exec('INSERT INTO login_logs (tenant_id, user_id, username, ip, region, ua, via, result, flagged, created_at) VALUES '
    . implode(',', $vals));
$oldestId = (int)$pdo->query('SELECT MIN(id) FROM login_logs WHERE tenant_id = ' . $tidA)->fetchColumn();
$cntBefore = (int)db_val("SELECT COUNT(*) FROM login_logs WHERE tenant_id = $tidA");
[$s, $j] = as_req('a_owner_last', 'POST', '/lga/api/auth/login', ['username' => $A['owner_qq'], 'password' => 'pass123456']);
$cntAfter = (int)db_val("SELECT COUNT(*) FROM login_logs WHERE tenant_id = $tidA");
check('灌入后超过上限（' . $cntBefore . ' 行）', $cntBefore > $KEEP && ($j['code'] ?? 1) === 0, 'before=' . $cntBefore);
check('一次登录写完后自动裁到上限', $cntAfter === $KEEP, 'after=' . $cntAfter);
check('被裁掉的是最旧的行',
    (int)db_val("SELECT COUNT(*) FROM login_logs WHERE tenant_id = $tidA AND id = $oldestId") === 0);
$topId = (int)$pdo->query('SELECT MAX(id) FROM login_logs')->fetchColumn();
$topUid = (int)$pdo->query('SELECT user_id FROM login_logs WHERE id = ' . $topId)->fetchColumn();
check('本次登录行仍在（裁剪不会误删最新）', $topUid === (int)$A['owner'], 'top_id=' . $topId . ' user_id=' . $topUid);
$pdo->exec('DELETE FROM login_logs WHERE user_id = 999001');

// ============================================================
// 11. 平台按日期清理各战队日志
// ============================================================
echo "\n== 11. 平台日志清理（按日期 / 巡查审计保护 / 预览） ==\n";
$old = '2025-01-01 10:00:00';
$pdo->exec("INSERT INTO admin_logs (tenant_id, admin_id, admin_name, action, detail, created_at)
    VALUES ($tidA, 1, '队长·老张', '审核图片', '很久以前的一条', '$old')");
$oldAdminId = (int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO admin_logs (tenant_id, admin_id, admin_name, action, detail, created_at)
    VALUES ($tidA, 0, '平台巡查#1', '隐身巡查', '读取「成员全量资料」(members) · 会话 aabbccdd #1 · 访客 127.0.0.1', '$old')");
$inspId = (int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO chat_messages (tenant_id, user_id, content, created_at)
    VALUES ($tidA, {$A['member']}, '很久以前说过的一句话', '$old')");
$oldChatId = (int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO admin_logs (tenant_id, admin_id, admin_name, action, detail, created_at)
    VALUES ($tidB, 1, 'B队长', '审核图片', 'B 队的旧记录', '$old')");
$oldBId = (int)$pdo->lastInsertId();

[$s, $j] = as_req('super', 'GET', '/api/platform/admin/log-stats');
check('统计接口：code 0 且按站点×表给出条数与最早时间',
    ($j['code'] ?? 1) === 0 && count($j['data']['list'] ?? []) >= 2, J($j));
$rowA = null;
foreach (($j['data']['list'] ?? []) as $r) { if ((int)$r['tenant_id'] === $tidA) { $rowA = $r; } }
check('A 队统计里操作日志与聊天消息都数到了旧行',
    $rowA !== null && (int)($rowA['tables']['admin_logs']['n'] ?? 0) >= 1
    && (int)($rowA['tables']['chat_messages']['n'] ?? 0) >= 1, J($rowA));
check('统计里标出受保护的巡查审计条数', ($rowA['inspect'] ?? 0) >= 1, J(['inspect' => $rowA['inspect'] ?? null]));

[$s, $j] = as_req('super', 'POST', '/api/platform/admin/log-cleanup',
    ['tenant_id' => $tidA, 'type' => 'nonsense', 'before' => '2026-01-01']);
check('表名不在白名单里：拒绝', ($j['code'] ?? 0) === 400 || $s === 400, "http=$s " . J($j));
[$s, $j] = as_req('super', 'POST', '/api/platform/admin/log-cleanup',
    ['tenant_id' => $tidA, 'type' => 'admin_logs', 'before' => '2026-1-1']);
check('日期格式不合法：拒绝', ($j['code'] ?? 0) === 400, J($j));
[$s, $j] = as_req('super', 'POST', '/api/platform/admin/log-cleanup',
    ['tenant_id' => $tidA, 'type' => 'admin_logs', 'before' => date('Y-m-d', time() + 86400 * 30)]);
check('截止日期晚于今天：拒绝（防误删整表）', ($j['code'] ?? 0) === 400 && strpos((string)($j['msg'] ?? ''), '不能晚于今天') !== false, J($j));

[$s, $j] = as_req('super', 'POST', '/api/platform/admin/log-cleanup',
    ['tenant_id' => $tidA, 'type' => 'all', 'before' => '2026-01-01', 'apply' => 0]);
check('预览：返回各表将要删除的条数', ($j['code'] ?? 1) === 0
    && (int)($j['data']['plan']['admin_logs']['total'] ?? 0) >= 1
    && (int)($j['data']['plan']['admin_logs']['inspect'] ?? 0) >= 1, J($j['data'] ?? null));
check('预览不写库：旧行仍在',
    (int)db_val("SELECT COUNT(*) FROM admin_logs WHERE id = $oldAdminId") === 1
    && (int)db_val("SELECT COUNT(*) FROM chat_messages WHERE id = $oldChatId") === 1);

[$s, $j] = as_req('a_owner', 'POST', '/lga/api/admin/login-logs');
[$s, $j] = as_req('b_owner', 'POST', '/api/platform/admin/log-cleanup',
    ['tenant_id' => $tidA, 'type' => 'admin_logs', 'before' => '2026-01-01', 'apply' => 1]);
check('战队站长调平台清理接口：拒绝', $s === 403 || $s === 404 || $s === 401, "http=$s " . J($j));

[$s, $j] = as_req('super', 'POST', '/api/platform/admin/log-cleanup',
    ['tenant_id' => $tidA, 'type' => 'all', 'before' => '2026-01-01', 'apply' => 1]);
check('执行清理：返回各表删除条数', ($j['code'] ?? 1) === 0 && (int)($j['data']['deleted']['admin_logs'] ?? 0) >= 1
    && (int)($j['data']['deleted']['chat_messages'] ?? 0) >= 1, J($j['data'] ?? null));
check('旧的操作日志与聊天消息确实被删',
    (int)db_val("SELECT COUNT(*) FROM admin_logs WHERE id = $oldAdminId") === 0
    && (int)db_val("SELECT COUNT(*) FROM chat_messages WHERE id = $oldChatId") === 0);
check('巡查审计行默认保留（清不掉「平台读过什么」的凭证）',
    (int)db_val("SELECT COUNT(*) FROM admin_logs WHERE id = $inspId") === 1);
check('清理只影响指定站点：B 队的旧行仍在',
    (int)db_val("SELECT COUNT(*) FROM admin_logs WHERE id = $oldBId") === 1);
check('清理动作本身写入被清理站点的操作日志',
    (int)db_val("SELECT COUNT(*) FROM admin_logs WHERE tenant_id = $tidA AND action = '平台清理日志'") >= 1);
$audit = db_rows("SELECT detail FROM admin_logs WHERE tenant_id = $tidA AND action = '平台清理日志' ORDER BY id DESC LIMIT 1");
check('清理留痕写明范围与「巡查审计已保留」',
    isset($audit[0]) && strpos((string)$audit[0]['detail'], '2026-01-01') !== false
    && strpos((string)$audit[0]['detail'], '巡查审计已保留') !== false, J($audit[0] ?? null));
check('清理后登录日志的旧行同样被裁（本会话的行日期是今天，应保留）',
    (int)db_val("SELECT COUNT(*) FROM login_logs WHERE tenant_id = $tidA AND created_at < '2026-01-01'") === 0
    && (int)db_val("SELECT COUNT(*) FROM login_logs WHERE tenant_id = $tidA") > 0);

[$s, $j] = as_req('super', 'POST', '/api/platform/admin/log-cleanup',
    ['tenant_id' => $tidA, 'type' => 'admin_logs', 'before' => '2026-01-01', 'include_inspect' => 1, 'apply' => 1]);
check('显式勾选后才能连巡查审计一起清',
    ($j['code'] ?? 1) === 0 && (int)db_val("SELECT COUNT(*) FROM admin_logs WHERE id = $inspId") === 0,
    J($j['data'] ?? null));
check('强制清巡查审计时留痕标注「含巡查审计」',
    (int)db_val("SELECT COUNT(*) FROM admin_logs WHERE tenant_id = $tidA AND action = '平台清理日志' AND detail LIKE '%含巡查审计%'") >= 1);

[$s, $j] = as_req('super', 'POST', '/api/platform/admin/log-cleanup',
    ['tenant_id' => 0, 'type' => 'admin_logs', 'before' => '2026-01-01', 'apply' => 1]);
check('站点选「全部」时按全部战队清理', ($j['code'] ?? 1) === 0
    && (int)db_val("SELECT COUNT(*) FROM admin_logs WHERE id = $oldBId") === 0, J($j['data'] ?? null));

// ============================================================
// 12. 回归
// ============================================================
echo "\n== 12. 回归 ==\n";
[$s, $j] = as_req('a_owner', 'GET', '/lga/api/dashboard');
check('队长仪表盘正常（含在线名单）', ($j['code'] ?? 1) === 0 && isset($j['data']['online_count']), J($j));
[$s, $j] = as_req('a_mem', 'GET', '/lga/api/auth/me');
check('成员身份接口正常', ($j['code'] ?? 1) === 0, J($j));
[$s, $j] = as_req('a_owner', 'GET', '/lga/api/admin/perms');
check('权限分配页正常', ($j['code'] ?? 1) === 0 && is_array($j['data']['defs'] ?? null), J($j));
[$s, $j] = as_req('super', 'GET', '/api/platform/admin/tenants');
check('平台站点列表正常', ($j['code'] ?? 1) === 0, J($j));
[$s, $j] = as_req('sv', 'GET', '/lga/api/inspect/members');
check('v5.16.0 巡查通道仍可用', ($j['code'] ?? 1) === 0 && count($j['data']['list'] ?? []) >= 1, J($j));

foreach (glob($ROOT . '/runtime/cache/*/*') ?: [] as $f) { if (is_file($f)) @unlink($f); }
foreach (glob($ROOT . '/runtime/cache/*') ?: [] as $f) { if (is_file($f)) @unlink($f); }

echo "\n================ 结果 ================\n";
echo "通过 $pass 项 / 失败 $fail 项\n";
if ($failures) { echo "失败项：\n - " . implode("\n - ", $failures) . "\n"; }
exit($fail > 0 ? 1 : 0);
