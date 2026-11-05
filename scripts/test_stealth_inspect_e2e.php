<?php
/**
 * v5.16.0 隐身巡查白名单通道 E2E（/api/inspect/* + 审计 + 撤销 + 限额）
 *
 * 前置：测试服务器已启动，.env 指向 SQLite（注意 .env 里只能用 ; 注释）
 *   php -S 127.0.0.1:8899 -t public public/router.php
 * 用法：php scripts/test_stealth_inspect_e2e.php
 *
 * 覆盖：
 *   1. 签发与一次性：入口有效期 30 分钟、cookie 绑定租户目录且 HttpOnly、一枚令牌只能兑一次
 *   2. 通道能力：9 个白名单端点各自返回预期台账，巡查态给全量（完整 QQ / 邮箱 / 注册 IP）
 *   3. 不泄漏：响应里没有密码字段、没有找回密码的新密码、图片台账不带文件路径字段
 *   4. 只看不动：巡查读取不改 last_active、不把到期约战刷成 expired、不增删业务行
 *   5. 鉴权边界：匿名 / 成员 / 站长 / 平台超管常规登录态一律 403；POST 405；白名单外 404；
 *      跨租户拿不到对面数据；巡查会话仍被管理接口拒
 *   6. 审计：每次读取落一行 admin_logs（端点、会话指纹、序号、查询条件、访客 IP、签发人），
 *      签发与撤销同样留痕；平台侧可汇总与按站点筛
 *   7. 限额：inspect_call_limit 触发 429；每人每小时签发上限触发 429
 *   8. 撤销：一键撤销后，在用的巡查 cookie 与未兑票的令牌同时失效，站点列表计数归零
 *   9. 回归：站长/成员常规接口不受影响
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

// ---------- HTTP（内存维护 Cookie，保留响应头） ----------
$jars = [];
function jar(string $who): array { global $jars; if (!isset($jars[$who])) { $jars[$who] = []; } return $jars[$who]; }
/** @return array{0:int,1:array,2:array,3:string} [状态码, JSON, 响应头, 原始文本] */
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
function hdr_has(array $headers, string $needle): bool {
    foreach ($headers as $h) { if (stripos($h, $needle) !== false) return true; }
    return false;
}
function set_cookie_of(array $headers, string $name): string {
    foreach ($headers as $h) {
        if (stripos($h, $name . '=') === 0 || preg_match('/^Set-Cookie:\s*' . preg_quote($name, '/') . '=/i', $h)) { return $h; }
    }
    return '';
}

// ---------- 直连库 ----------
function db(): PDO {
    global $DB;
    $p = new PDO('sqlite:' . $DB);
    $p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
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
// 0. 重建测试库 + 种子 + 清缓存（限流键是 file 缓存，必须从零开始）
// ============================================================
echo "== 0. 重建测试库与种子 ==\n";
@unlink($DB);
echo shell_exec('"' . PHP_BINARY . '" "' . $ROOT . '/scripts/dev_init_sqlite.php" "' . $DB . '" 2>&1');
foreach (glob($ROOT . '/runtime/cache/*/*') ?: [] as $f) { if (is_file($f)) @unlink($f); }
foreach (glob($ROOT . '/runtime/cache/*') ?: [] as $f) { if (is_file($f)) @unlink($f); }

$now = date('Y-m-d H:i:s');
$pdo = db();

/** 建一支战队：站长 + 成员 + 待审 + 被封，返回各 id */
function seed_team(string $slug, string $name): array {
    global $pdo, $now;
    $st = $pdo->prepare('INSERT INTO platform_users (username, password, nickname, role, status, reg_ip, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    $st->execute([$slug . 'owner', password_hash('plat123456', PASSWORD_DEFAULT), $name, 'team', 'normal', '127.0.0.1', $now, $now]);
    $pid = (int)$pdo->lastInsertId();
    $pdo->exec("INSERT INTO tenants (applicant_id, slug, name, status, admin_username, admin_user_id, created_at, updated_at)
        VALUES ($pid, '$slug', '$name', 'approved', '$slug' || 'boss', NULL, '$now', '$now')");
    $tid = (int)$pdo->lastInsertId();
    $mk = function (string $u, string $nick, string $role, string $status, string $email, string $ip, string $region, string $shot) use ($pdo, $tid, $now) {
        $stmt = $pdo->prepare("INSERT INTO users (tenant_id, username, password, nickname, email, profile_image, reg_ip, reg_region,
            role, status, team_id, muted, admin_perms, created_at, updated_at, last_active_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0, '[]', ?, ?, ?)");
        $stmt->execute([$tid, $u, password_hash('pass123456', PASSWORD_DEFAULT), $nick, $email, $shot, $ip, $region,
            $role, $status, $now, $now, date('Y-m-d H:i:s', time() - 120)]);
        return (int)$pdo->lastInsertId();
    };
    $boss   = $mk($slug . 'boss', $slug . '站长', 'admin', 'approved', 'boss@qq.com', '10.1.1.1', '本机', '/storage/uploads/shot_boss.png');
    $member = $mk($slug . 'mem', $slug . '队员甲', 'user', 'approved', 'mem@qq.com', '10.2.2.2', '本机', '/storage/uploads/shot_mem.png');
    $pend   = $mk($slug . 'new', $slug . '待审新人', 'user', 'pending', 'new@qq.com', '10.3.3.3', '外地', '/storage/uploads/shot_new.png');
    $ban    = $mk($slug . 'bad', $slug . '被封的', 'user', 'banned', 'bad@qq.com', '10.4.4.4', '外地', '');
    $pdo->exec("UPDATE tenants SET admin_user_id = $boss WHERE id = $tid");
    return ['pid' => $pid, 'tid' => $tid, 'boss' => $boss, 'member' => $member, 'pending' => $pend, 'banned' => $ban];
}

$A = seed_team('insa', '巡查测试A队');
$B = seed_team('insb', '巡查测试B队');
$tidA = (int)$A['tid']; $tidB = (int)$B['tid'];

$platUser = 'inssuper';
$pdo->exec("INSERT INTO platform_users (username, password, nickname, role, status, reg_ip, created_at, updated_at)
    VALUES ('$platUser', '" . password_hash('super123456', PASSWORD_DEFAULT) . "', '平台超管', 'admin', 'normal', '127.0.0.1', '$now', '$now')");
$platUid = (int)$pdo->lastInsertId();

// 换成完整 QQ 账号：巡查通道应原样给出，而不是打码
$qqMember = '123456789'; $qqBoss = '987654321';
$pdo->exec("UPDATE users SET username = '$qqBoss' WHERE id = {$A['boss']}");
$pdo->exec("UPDATE users SET username = '$qqMember' WHERE id = {$A['member']}");
$pdo->exec("UPDATE users SET username = '55566677' WHERE id = {$A['pending']}");
$pdo->exec("UPDATE users SET username = '11122233' WHERE id = {$B['member']}");
// 站长身份按 tenants.admin_username 认定（is_tenant_owner），改名后同步过去，否则第 9 节回归拿不到站长权限
$pdo->exec("UPDATE tenants SET admin_username = '$qqBoss' WHERE id = $tidA");

$pdo->exec("INSERT INTO blacklist (tenant_id, qq, nickname, reason, created_by, created_at)
    VALUES ($tidA, '77788899', '恶意玩家', '开挂', '站长', '$now')");
$pdo->exec("INSERT INTO password_resets (tenant_id, user_id, new_password, screenshot, request_ip, created_at)
    VALUES ($tidA, {$A['member']}, 'ResetPwd123', '/storage/uploads/pwd_mem.png', '10.9.9.9', '" . date('Y-m-d H:i:s', time() - 100 * 3600) . "')");
$pdo->exec("INSERT INTO images (tenant_id, user_id, title, file, status, created_at, updated_at)
    VALUES ($tidA, {$A['member']}, '测试截图', '/storage/uploads/images/20260101/a.jpg', 'pending', '$now', '$now')");
$pdo->exec("INSERT INTO player_stats (tenant_id, user_id, game_pid, matches, win_rate, kd, created_at, updated_at)
    VALUES ($tidA, {$A['member']}, 'PID1', 42, '58%', 2.3, '$now', '$now')");
$pdo->exec("INSERT INTO admin_logs (tenant_id, admin_id, admin_name, action, detail, created_at)
    VALUES ($tidA, {$A['boss']}, '站长($qqBoss)', '审核图片', '通过 1 张', '$now')");
// 一场已到期但未结算的约战：巡查读取绝不能把它刷成 expired
$pdo->exec("INSERT INTO battles (title, game_mode, battle_time, challenger_type, challenger_id, defender_type, defender_id,
    status, battle_type, roster_limit, created_by, created_at, updated_at)
    VALUES ('到期未结算', 'team', '" . date('Y-m-d H:i:s', time() - 3600) . "', 'tenant', $tidA, 'tenant', $tidB,
    'pending', 'official', 8, '平台', '$now', '$now')");
$battleId = (int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO battle_signups (battle_id, side, signup_type, user_id, display_name, tenant_id, created_at)
    VALUES ($battleId, 'a', 'user', {$A['member']}, '巡查测试A队·队员甲', $tidA, '$now')");

check('种子：两支战队 / 平台超管 / 各类台账', $tidA > 0 && $tidB !== $tidA && $platUid > 0 && $battleId > 0,
    "tidA=$tidA tidB=$tidB plat=$platUid battle=$battleId");

$issued = 0;   // 本进程内成功签发的入口数（限频断言用）

// ============================================================
// 1. 签发 + 一次性兑票
// ============================================================
echo "\n== 1. 签发与一次性兑票 ==\n";
[$s, $j] = as_req('super', 'POST', '/api/platform/auth/login', ['username' => $platUser, 'password' => 'super123456']);
check('平台超管登录', ($j['code'] ?? 1) === 0, J($j));

[$s, $j] = as_req('super', 'POST', "/api/platform/admin/tenants/$tidA/stealth");
$token = (string)($j['data']['token'] ?? '');
check('签发巡查入口：code 0 + 有效期 30 分钟', ($j['code'] ?? 1) === 0 && (int)($j['data']['expires'] ?? 0) === 1800, J($j));
check('入口提示不再宣称「不留痕迹」，改为写明留痕',
    strpos((string)($j['msg'] ?? ''), '不留痕迹') === false && strpos((string)($j['msg'] ?? ''), '操作日志') !== false,
    (string)($j['msg'] ?? ''));
check('令牌为 48 位十六进制', (bool)preg_match('/^[a-f0-9]{48}$/', $token), $token);
check('签发动作写入该站点操作日志',
    (int)db_val("SELECT COUNT(*) FROM admin_logs WHERE tenant_id = $tidA AND action = '隐身巡查' AND detail LIKE '签发巡查入口%'") >= 1);
$issued++;

// 巡查通道首条请求带 ?_sv=：就地兑票，不重定向
[$s, $j, $hd] = as_req('sv', 'GET', '/insa/api/inspect/overview?_sv=' . $token);
check('API 请求带令牌：就地兑票返回 JSON（不再 302）', $s === 200 && ($j['code'] ?? 1) === 0, "http=$s " . J($j));
$svHdr = set_cookie_of($hd, 'sv_guard');
check('换发巡查 cookie sv_guard，绑定本租户目录且 HttpOnly',
    $svHdr !== '' && stripos($svHdr, 'path=/insa') !== false && stripos($svHdr, 'httponly') !== false, $svHdr);
check('巡查会话登记为 30 分钟有效',
    (int)db_val("SELECT ROUND((julianday(expires_at) - julianday(created_at)) * 86400) FROM stealth_tokens WHERE tenant_id = $tidA ORDER BY id DESC LIMIT 1") === 1800);
check('会话登记带出签发人（可溯源到具体超管）',
    (int)db_val("SELECT created_by FROM stealth_tokens WHERE tenant_id = $tidA ORDER BY id DESC LIMIT 1") === $platUid);
check('概览带出巡查留痕提示', is_string($j['data']['notice'] ?? null) && $j['data']['notice'] !== '', J($j['data'] ?? null));

[$s2, $j2] = as_req('sv2', 'GET', '/insa/api/inspect/overview?_sv=' . $token);
check('令牌一次性：第二次兑票被拒', $s2 === 403 && ($j2['code'] ?? 0) === 403, "http=$s2 " . J($j2));
check('兑票即删登记',
    (int)db_val("SELECT COUNT(*) FROM stealth_tokens WHERE token_hash = '" . hash('sha256', $token) . "'") === 0);

// 页面请求仍 302 到干净地址（v5.7.1 行为不回归）
[$s, $j] = as_req('super', 'POST', "/api/platform/admin/tenants/$tidA/stealth");
$token2 = (string)($j['data']['token'] ?? ''); $issued++;
[$s, $hj, $hd] = as_req('svpage', 'GET', '/insa/app?_sv=' . $token2);
check('页面请求兑票仍 302 到 /insa/app', $s === 302 && hdr_has($hd, '/insa/app'), "http=$s " . implode(' | ', $hd));

// ============================================================
// 2. 九个白名单端点（巡查态给全量）
// ============================================================
echo "\n== 2. 白名单端点能力 ==\n";
[$s, $j, $hd, $raw] = as_req('sv', 'GET', '/insa/api/inspect/members?size=200');
$list = $j['data']['list'] ?? [];
$byId = [];
foreach ($list as $r) { $byId[(int)$r['id']] = $r; }
$mm = $byId[$A['member']] ?? [];
check('成员台账：code 0 且覆盖全部成员', $s === 200 && ($j['code'] ?? 1) === 0 && count($list) >= 4, "http=$s " . J($j));
check('巡查态给完整 QQ（不打码）',
    (string)($mm['qq'] ?? '') === $qqMember && (string)($byId[$A['boss']]['qq'] ?? '') === $qqBoss, J($mm));
check('成员台账含邮箱 / 注册 IP / 归属地 / 注册截图',
    (string)($mm['email'] ?? '') === 'mem@qq.com' && (string)($mm['reg_ip'] ?? '') === '10.2.2.2'
    && (string)($mm['reg_region'] ?? '') === '本机'
    && (string)($mm['profile_image'] ?? '') === '/storage/uploads/shot_mem.png', J($mm));
check('成员台账不外带密码散列', strpos($raw, '"password"') === false && !isset($mm['password']), $raw === '' ? 'empty' : 'has-password');

[$s, $j] = as_req('sv', 'GET', '/insa/api/inspect/pending');
$pl = $j['data']['list'][0] ?? [];
check('待审核注册：只出 pending，给完整 QQ 与佐证截图',
    ($j['code'] ?? 1) === 0 && count($j['data']['list'] ?? []) === 1
    && (string)($pl['username'] ?? '') === '55566677'
    && (string)($pl['profile_image'] ?? '') === '/storage/uploads/shot_new.png', J($j));

[$s, $j] = as_req('sv', 'GET', '/insa/api/inspect/blacklist');
check('黑名单：完整 QQ 与拉黑原因', ($j['code'] ?? 1) === 0
    && (string)($j['data']['list'][0]['qq'] ?? '') === '77788899'
    && (string)($j['data']['list'][0]['reason'] ?? '') === '开挂', J($j));

[$s, $j, $hd, $raw] = as_req('sv', 'GET', '/insa/api/inspect/password-resets');
$pr = $j['data']['list'][0] ?? [];
check('找回密码申请：给到 IP / 截图事实与超期标记', ($j['code'] ?? 1) === 0
    && (string)($pr['request_ip'] ?? '') === '10.9.9.9' && (bool)($pr['has_screenshot'] ?? false) === true
    && (bool)($pr['stale'] ?? false) === true, J($pr));
check('找回密码申请：绝不外带新密码', !isset($pr['new_password']) && strpos($raw, 'ResetPwd123') === false, $raw);

[$s, $j] = as_req('sv', 'GET', '/insa/api/inspect/logs?limit=100');
$acts = array_column($j['data']['list'] ?? [], 'action');
check('站长操作日志：读到既有日志', ($j['code'] ?? 1) === 0 && in_array('审核图片', $acts, true), J($acts));
check('站长操作日志：不混入巡查自身噪声', !in_array('隐身巡查', $acts, true), J($acts));

[$s, $j] = as_req('sv', 'GET', '/insa/api/inspect/images');
$im = $j['data']['list'][0] ?? [];
check('图片台账：元信息 + 上传者完整 QQ', ($j['code'] ?? 1) === 0 && (string)($im['status'] ?? '') === 'pending'
    && (string)($im['uploader_qq'] ?? '') === $qqMember, J($im));
check('图片台账：不给文件路径字段（不开新的文件输出通道）',
    !isset($im['file']) && !isset($im['path']) && !isset($im['abs']), J($im));

[$s, $j] = as_req('sv', 'GET', '/insa/api/inspect/player-stats');
$ps = $j['data']['list'][0] ?? [];
check('战绩台账：指标原值 + 成员完整 QQ', ($j['code'] ?? 1) === 0 && (int)($ps['stats']['matches'] ?? 0) === 42
    && (string)($ps['qq'] ?? '') === $qqMember, J($ps));

[$s, $j] = as_req('sv', 'GET', '/insa/api/inspect/battles');
$bt = null;
foreach ($j['data']['list'] ?? [] as $r) { if ((int)$r['id'] === $battleId) { $bt = $r; } }
check('约战台账：本队相关约战 + 双方队名 + 名单人数',
    $bt !== null && (string)($bt['team_a'] ?? '') === '巡查测试A队' && (string)($bt['team_b'] ?? '') === '巡查测试B队'
    && (int)($bt['count_a'] ?? 0) === 1, J($bt));

// ============================================================
// 3. 只看不动
// ============================================================
echo "\n== 3. 巡查读取只读性 ==\n";
$snap = function (): array {
    global $battleId, $A, $tidA;
    return [
        'battle'  => (string)db_val("SELECT status FROM battles WHERE id = $battleId"),
        'active'  => (string)db_val("SELECT last_active_at FROM users WHERE id = {$A['boss']}"),
        'images'  => (string)db_val("SELECT COUNT(*) FROM images WHERE tenant_id = $tidA"),
        'stats'   => (string)db_val("SELECT COUNT(*) FROM player_stats WHERE tenant_id = $tidA"),
        'pwd'     => (string)db_val("SELECT new_password FROM password_resets WHERE tenant_id = $tidA LIMIT 1"),
    ];
};
$before = $snap();
foreach (['overview', 'members', 'pending', 'blacklist', 'password-resets', 'logs', 'images', 'player-stats', 'battles'] as $ep) {
    as_req('sv', 'GET', '/insa/api/inspect/' . $ep);
}
$after = $snap();
check('到期约战没被巡查刷成 expired（特意绕开 BattleService）',
    $before['battle'] === 'pending' && $after['battle'] === 'pending',
    $before['battle'] . ' -> ' . $after['battle']);
check('巡查不改 last_active_at（巡查者不会混进在线名单）',
    $before['active'] === $after['active'], $before['active'] . ' -> ' . $after['active']);
check('巡查不增删业务行', $before['images'] === $after['images'] && $before['stats'] === $after['stats'],
    J($before) . ' -> ' . J($after));
check('找回密码申请未被巡查顺带处理', $before['pwd'] === $after['pwd']);

// ============================================================
// 4. 鉴权边界
// ============================================================
echo "\n== 4. 鉴权边界 ==\n";
[$s, $j] = as_req('nobody', 'GET', '/insa/api/inspect/members');
check('匿名访问巡查通道：403 且不返回数据',
    $s === 403 && ($j['code'] ?? 0) === 403 && array_key_exists('data', $j) && $j['data'] === null, "http=$s " . J($j));

$login = as_req('a_mem', 'POST', '/insa/api/auth/login', ['username' => $qqMember, 'password' => 'pass123456']);
check('A 队成员登录（回归用）', ($login[1]['code'] ?? 1) === 0, J($login[1]));
[$s, $j] = as_req('a_mem', 'GET', '/insa/api/inspect/members');
check('成员调巡查通道：403', $s === 403, "http=$s " . J($j));

$login = as_req('a_boss', 'POST', '/insa/api/auth/login', ['username' => $qqBoss, 'password' => 'pass123456']);
check('A 队站长登录（回归用）', ($login[1]['code'] ?? 1) === 0, J($login[1]));
[$s, $j] = as_req('a_boss', 'GET', '/insa/api/inspect/members');
check('站长调巡查通道：403（这组接口不属于租户账号）', $s === 403, "http=$s " . J($j));
[$s, $j] = as_req('a_boss', 'GET', '/insa/api/inspect/overview');
check('站长调概览端点同样 403', $s === 403, "http=$s");

[$s, $j] = as_req('super', 'GET', '/insa/api/inspect/members');
check('平台超管常规登录态（未兑票）：403', $s === 403, "http=$s " . J($j));
[$s, $j] = as_req('super', 'GET', '/api/inspect/members');
check('平台根路径调巡查通道：403（无租户上下文）', $s === 403, "http=$s " . J($j));

[$s, $j, $hd, $raw] = as_req('sv', 'POST', '/insa/api/inspect/members');
check('巡查会话 POST：不被受理（路由只注册 GET）', $s === 405 || $s === 403 || $s === 404, "http=$s");
check('POST 响应里没有任何成员数据', strpos($raw, $qqMember) === false && strpos($raw, '123456789') === false);

// 写方法不得兑票：否则任何人用一个带令牌的 POST 就能白烧掉这枚一次性入口
[$s, $j] = as_req('super', 'POST', "/api/platform/admin/tenants/$tidA/stealth");
$tokenBurn = (string)($j['data']['token'] ?? ''); $issued++;
[$s, $j] = as_req('burn', 'POST', '/insa/api/inspect/members?_sv=' . $tokenBurn);
check('POST 携带令牌不兑票', $s !== 200 && (int)db_val("SELECT COUNT(*) FROM stealth_tokens WHERE token_hash = '" . hash('sha256', $tokenBurn) . "'") === 1);
[$s, $j] = as_req('burnget', 'GET', '/insa/api/inspect/overview?_sv=' . $tokenBurn);
check('同一枚令牌随后仍能正常兑票（未被写请求烧掉）', $s === 200 && ($j['code'] ?? 1) === 0, "http=$s " . J($j));

[$s, $j] = as_req('sv', 'GET', '/insa/api/inspect/export-all');
check('白名单外端点：404（新增路由不登记就不放行）', $s === 404, "http=$s " . J($j));

$svSecret = (string)(jar('sv')['sv_guard'] ?? '');
check('拿到巡查会话 cookie 值', (bool)preg_match('/^[a-f0-9]{48}$/', $svSecret), $svSecret);
[$s, $j] = as_req('xtenant', 'GET', '/insb/api/inspect/members', null, ['Cookie: sv_guard=' . $svSecret]);
check('A 站巡查会话硬发 B 站：403', $s === 403 && ($j['code'] ?? 0) === 403, "http=$s " . J($j));
check('跨租户没有漏出 B 队成员', strpos(json_encode($j, JSON_UNESCAPED_UNICODE), '11122233') === false, J($j));

[$s, $j] = as_req('sv', 'GET', '/insa/api/admin/images?status=pending');
check('巡查会话调管理接口：仍被拒（v5.1.0 约束不回归）', $s === 403, "http=$s " . J($j));

// ============================================================
// 5. 审计逐次落库
// ============================================================
echo "\n== 5. 巡查审计 ==\n";
$rows  = db_all("SELECT admin_name, detail FROM admin_logs WHERE tenant_id = $tidA AND action = '隐身巡查' ORDER BY id ASC");
$reads = array_values(array_filter($rows, fn($r) => strpos((string)$r['detail'], '读取「') === 0));
check('每次读取各落一行（本轮累计读取次数）', count($reads) >= 18, (string)count($reads));
$one = db_one("SELECT admin_name, detail FROM admin_logs WHERE tenant_id = $tidA AND action = '隐身巡查'
    AND detail LIKE '%成员全量资料%' ORDER BY id DESC LIMIT 1");
check('审计行标明端点中文名与路径', $one !== null && strpos((string)$one['detail'], 'members') !== false, J($one));
check('审计行标明会话指纹与调用序号', $one !== null && preg_match('/会话 [a-f0-9]{8} #\d+/', (string)$one['detail']) === 1,
    (string)($one['detail'] ?? ''));
check('审计行标明访客 IP', $one !== null && strpos((string)$one['detail'], '访客 127.0.0.1') !== false,
    (string)($one['detail'] ?? ''));
check('审计行标明签发人（可追到具体超管）', $one !== null && (string)$one['admin_name'] === '平台巡查#' . $platUid, J($one));
check('审计行带上查询条件（可复盘查了哪一页）',
    db_one("SELECT id FROM admin_logs WHERE tenant_id = $tidA AND action = '隐身巡查' AND detail LIKE '%size=200%'") !== null);

[$s, $j] = as_req('super', 'GET', '/api/platform/admin/stealth-audits?tenant_id=' . $tidA . '&limit=300');
$alist = $j['data']['list'] ?? [];
check('平台巡查记录接口：code 0 且有内容', ($j['code'] ?? 1) === 0 && count($alist) > 0, J($j));
$hasIssue = false; $hasRead = false;
foreach ($alist as $r) {
    if (strpos((string)$r['detail'], '签发巡查入口') === 0) { $hasIssue = true; }
    if (strpos((string)$r['detail'], '读取「') === 0) { $hasRead = true; }
}
check('平台侧能看到签发记录', $hasIssue);
check('平台侧能逐条看到读取记录', $hasRead);
check('平台侧审计带出站点名', (string)($alist[0]['tenant_name'] ?? '') === '巡查测试A队', J($alist[0] ?? null));
check('按站点筛选不会混入别的战队',
    count(array_filter($alist, fn($r) => (int)$r['tenant_id'] !== $tidA)) === 0, (string)count($alist));
[$s, $j] = as_req('super', 'GET', '/api/platform/admin/stealth-audits?tenant_id=' . $tidB);
check('B 站无巡查记录', ($j['code'] ?? 1) === 0 && count($j['data']['list'] ?? []) === 0, J($j));
[$s, $j] = as_req('a_boss', 'GET', '/api/platform/admin/stealth-audits');
check('非平台超管读不到巡查汇总', $s === 403 || $s === 404 || $s === 401, "http=$s " . J($j));

// ============================================================
// 6. 读取次数上限
// ============================================================
echo "\n== 6. 巡查读取限额 ==\n";
$pdo->exec("INSERT OR REPLACE INTO settings (tenant_id, key, value) VALUES ($tidA, 'inspect_call_limit', '3')");
[$s, $j] = as_req('super', 'POST', "/api/platform/admin/tenants/$tidA/stealth");
$token3 = (string)($j['data']['token'] ?? ''); $issued++;
$okCnt = 0; $blocked = 0;
for ($i = 1; $i <= 6; $i++) {
    $u = '/insa/api/inspect/logs?limit=5' . ($i === 1 ? '&_sv=' . $token3 : '');
    [$s, $j] = as_req('svlimit', 'GET', $u);
    if ($s === 200 && ($j['code'] ?? 1) === 0) { $okCnt++; } elseif ($s === 429) { $blocked++; }
}
check('限额内正常读取（3 次）', $okCnt === 3, "ok=$okCnt");
check('超过上限后 429', $blocked === 3, "blocked=$blocked");
$over = db_one("SELECT detail FROM admin_logs WHERE tenant_id = $tidA AND action = '隐身巡查' AND detail LIKE '%已拦截%' ORDER BY id DESC LIMIT 1");
check('超限被拦也落审计', $over !== null && strpos((string)$over['detail'], '超过 3 次') !== false, J($over));
$pdo->exec("DELETE FROM settings WHERE tenant_id = $tidA AND key = 'inspect_call_limit'");

// ============================================================
// 7. 一键撤销
// ============================================================
echo "\n== 7. 撤销巡查 ==\n";
[$s, $j] = as_req('super', 'POST', "/api/platform/admin/tenants/$tidA/stealth");
$token4 = (string)($j['data']['token'] ?? ''); $issued++;
[$s, $j] = as_req('svfresh', 'GET', '/insa/api/inspect/overview?_sv=' . $token4);
check('新会话能读概览', ($j['code'] ?? 1) === 0, J($j));
$freshSecret = (string)(jar('svfresh')['sv_guard'] ?? '');
[$s, $j] = as_req('super', 'POST', "/api/platform/admin/tenants/$tidA/stealth");
$tokenDead = (string)($j['data']['token'] ?? ''); $issued++;

[$s, $j] = as_req('super', 'POST', "/api/platform/admin/tenants/$tidA/stealth-revoke");
check('撤销接口：code 0 且清掉多条登记', ($j['code'] ?? 1) === 0 && (int)($j['data']['revoked'] ?? 0) >= 3, J($j));
check('撤销动作写入操作日志',
    (int)db_val("SELECT COUNT(*) FROM admin_logs WHERE tenant_id = $tidA AND detail LIKE '撤销全部巡查入口%'") >= 1);
check('库内该站点巡查登记清零', (int)db_val("SELECT COUNT(*) FROM stealth_tokens WHERE tenant_id = $tidA") === 0);

[$s, $j] = as_req('xold', 'GET', '/insa/api/inspect/members', null, ['Cookie: sv_guard=' . $freshSecret]);
check('撤销后在用的巡查 cookie 立即失效', $s === 403, "http=$s " . J($j));
[$s, $j] = as_req('xold2', 'GET', '/insa/api/inspect/members', null, ['Cookie: sv_guard=' . $svSecret]);
check('撤销后更早的会话 cookie 也失效', $s === 403, "http=$s");
[$s, $j] = as_req('xdead', 'GET', '/insa/api/inspect/overview?_sv=' . $tokenDead);
check('撤销后未兑票的令牌一并作废', $s === 403, "http=$s " . J($j));

[$s, $j] = as_req('super', 'GET', '/api/platform/admin/tenants');
$ta = null;
foreach ($j['data']['list'] ?? [] as $r) { if ((int)$r['id'] === $tidA) { $ta = $r; } }
check('站点列表带出活跃巡查登记数（撤销后为 0）', $ta !== null && (int)$ta['stealth_active'] === 0, J($ta));
[$s, $j] = as_req('super', 'POST', "/api/platform/admin/tenants/$tidB/stealth"); $issued++;
[$s, $j2] = as_req('super', 'GET', '/api/platform/admin/tenants');
foreach ($j2['data']['list'] ?? [] as $r) { if ((int)$r['id'] === $tidB) { $ta = $r; } }
check('新签发的入口计入活跃登记', (int)($ta['stealth_active'] ?? 0) >= 1, J($ta));

// ============================================================
// 8. 签发限频（每人每小时 20 枚）
// ============================================================
echo "\n== 8. 签发限频 ==\n";
$succ = 0; $limited = 0; $lastQuota = null; $mono = true;
for ($i = 0; $i < 25; $i++) {
    [$s, $jq] = as_req('super', 'POST', "/api/platform/admin/tenants/$tidA/stealth");
    if (($jq['code'] ?? 1) === 0) {
        $succ++;
        $q = (int)($jq['data']['quota'] ?? -1);
        if ($lastQuota !== null && $q > $lastQuota) { $mono = false; }
        $lastQuota = $q;
    } elseif (($jq['code'] ?? 0) === 429) { $limited++; }
}
check('达到上限后签发被限流 429', $limited > 0, "succ=$succ limited=$limited");
check('本小时成功签发总数不超过 20', ($issued + $succ) <= 20, 'issued=' . ($issued + $succ));
check('响应里的剩余额度单调递减到 0', $mono && $lastQuota === 0, "last=$lastQuota");
[$s, $j] = as_req('a_boss', 'POST', "/api/platform/admin/tenants/$tidA/stealth");
check('站长不能自己签巡查入口', $s === 403 || $s === 404 || $s === 401, "http=$s " . J($j));

// ============================================================
// 9. 回归
// ============================================================
echo "\n== 9. 回归 ==\n";
[$s, $j] = as_req('a_boss', 'GET', '/insa/api/dashboard');
check('站长仪表盘正常（含在线名单）', ($j['code'] ?? 1) === 0 && isset($j['data']['online_count']), J($j));
[$s, $j] = as_req('a_boss', 'GET', '/insa/api/admin/images?status=pending');
check('站长图片审核列表正常', ($j['code'] ?? 1) === 0, J($j));
[$s, $j] = as_req('a_mem', 'GET', '/insa/api/auth/me');
check('成员身份接口正常', ($j['code'] ?? 1) === 0, J($j));
[$s, $j] = as_req('nobody', 'GET', '/insa/api/tenant/info');
check('公开站点信息接口不受影响', ($j['code'] ?? 1) === 0 && ($j['data']['slug'] ?? '') === 'insa', J($j));
[$s, $j] = as_req('xold', 'GET', '/insa/api/inspect/overview', null, ['Cookie: sv_guard=' . $freshSecret]);
check('已撤销的会话不会被再次放行', $s === 403, "http=$s");

// 收尾：清掉测试痕迹与限流缓存
$pdo->exec('DELETE FROM stealth_tokens');
$pdo->exec("DELETE FROM admin_logs WHERE action = '隐身巡查'");
foreach (glob($ROOT . '/runtime/cache/*/*') ?: [] as $f) { if (is_file($f)) @unlink($f); }
foreach (glob($ROOT . '/runtime/cache/*') ?: [] as $f) { if (is_file($f)) @unlink($f); }

echo "\n================ 结果 ================\n";
echo "通过 $pass 项 / 失败 $fail 项\n";
if ($failures) { echo "失败项：\n - " . implode("\n - ", $failures) . "\n"; }
exit($fail > 0 ? 1 : 0);
