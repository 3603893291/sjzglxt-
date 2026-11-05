<?php
/**
 * 社区论坛对接（v5.35.0）功能 E2E
 *
 * 前置：测试服务器已启动，且 .env 指向 sqlite
 *   php -S 127.0.0.1:8899 -t public public/router.php
 * 用法：php scripts/test_forum_feed_e2e.php
 *
 * 覆盖：
 *   1. 鉴权：没 Key / 乱 Key → 401；平台作用域的 Key 读不到（这条要求本队的 Key）；
 *      接口只有 GET，POST/PUT/DELETE 进不来，也不许留下写库副作用
 *   2. 三道闸：本队没打开 → 403 并说明是谁挡的；队长在管理端打开后能读；
 *      平台按站点停用 → 403；平台总闸 → 403；放回去照队长原本的设置生效
 *   3. 成员名单：只出本人同意对外展示的人；关了的人一条都不出；每行带 uid 与打码 QQ；
 *      战绩按另一档（stats_public=0 的人给一个空的 stats，落 JSON 是 {} 不是 []）；
 *      stat_labels 二十项；两次调用 uid 一致
 *   4. 活动与签到：时间窗（太旧与太新的都不出）；约战只算本队那一侧的人数；
 *      我们是应战方时对手队名要指向发起方（这里错过一次）；签到榜不含关了对外展示的人
 *   5. 公告：只出队长在对外展示页勾选过的那几条，没勾的不出
 *   6. 名片：队名/slug/招募/联系方式与访客页同一份；计数对得上；站址能把人链回来
 *   7. 复扫：四条响应里一个完整账号（QQ）、邮箱、注册 IP 都不出现
 *   8. 跨队：拿第二队的 Key 读，只出第二队的人，第一队的人一条都不出
 *   9. 限频与留痕：Key 级限频真的会 429；每一次调用（含失败）都进 api_logs
 *  10. 管理端那一页：四条接口地址、Key 只给前缀不给明文、最近调用、开关写操作日志；
 *      普通成员与副管理员都进不来（这一格只给站长）
 *  11. 平台端那一页：列表/总闸/单队停用；战队站长冒充不了平台
 *  12. 零 DDL：这一版不建表，三个状态都落在 settings
 *  13. 回归：同一把 Key 仍能读约战开放接口（这是复用那条路的代价，写在页面上也要断言住）；
 *      导出、通知、公告、赛前提醒、官网首页照常
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

$ROOT = dirname(__DIR__);
$BASE = rtrim((string)(getenv('TEST_BASE') ?: 'http://127.0.0.1:8899'), '/');
$DB   = $ROOT . '/runtime/test.sqlite';
// 建 Key 与打码都直接用站内那两个助手函数，别在测试里另写一份（改了代码测试要跟着红才算数）
require_once $ROOT . '/app/common.php';

$pass = 0; $fail = 0; $failures = [];
function check(string $name, bool $ok, string $detail = ''): void {
    global $pass, $fail, $failures;
    if ($ok) { $pass++; echo "  [PASS] $name\n"; }
    else { $fail++; $failures[] = $name . ($detail !== '' ? " ($detail)" : ''); echo "  [FAIL] $name" . ($detail !== '' ? " —— $detail" : '') . "\n"; }
}

function take_cookies(array $headers, array &$cookies): void {
    foreach ($headers as $h) {
        if (preg_match('/^Set-Cookie:\s*([^=]+)=([^;]*)/i', $h, $m)) { $cookies[$m[1]] = trim($m[2]); }
    }
}
function cookie_hdr(array &$cookies): string {
    if (!$cookies) { return ''; }
    $pairs = [];
    foreach ($cookies as $k => $v) { $pairs[] = $k . '=' . $v; }
    return 'Cookie: ' . implode('; ', $pairs) . "\r\n";
}
/** @return array{0:int,1:array,2:string} */
function req(string $method, string $url, ?array $body, array &$cookies, string $apiKey = ''): array {
    $hdr = "Content-Type: application/json\r\n" . cookie_hdr($cookies);
    if ($apiKey !== '') { $hdr .= 'X-Api-Key: ' . $apiKey . "\r\n"; }
    $opts = ['http' => ['method' => $method, 'header' => $hdr, 'ignore_errors' => true, 'timeout' => 30]];
    if ($body !== null) { $opts['http']['content'] = json_encode($body, JSON_UNESCAPED_UNICODE); }
    $raw = @file_get_contents($url, false, stream_context_create($opts));
    $raw = (string)($raw === false ? '' : $raw);
    $hs  = $http_response_header ?? [];
    take_cookies($hs, $cookies);
    $s = 0;
    foreach ($hs as $h) { if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) { $s = (int)$m[1]; } }
    $j = json_decode($raw, true);
    return [$s, is_array($j) ? $j : ['raw' => $raw], $raw];
}
function J($a): string { return (string)json_encode($a, JSON_UNESCAPED_UNICODE); }

$jars = [];
function jar(string $who): array { global $jars; if (!isset($jars[$who])) { $jars[$who] = []; } return $jars[$who]; }
function put_jar(string $who, array $c): void { global $jars; $jars[$who] = $c; }
function as_req(string $who, string $method, string $path, ?array $body = null, string $apiKey = ''): array {
    global $BASE;
    $c = jar($who);
    $r = req($method, $BASE . $path, $body, $c, $apiKey);
    put_jar($who, $c);
    return $r;
}
function login(string $who, string $slug, string $user, string $pwd): array {
    return as_req($who, 'POST', "/$slug/api/auth/login", ['username' => $user, 'password' => $pwd]);
}
/** 匿名 GET：开放接口不认登录态，页面回归也走这一条（不带任何 Cookie） */
function open_get(string $path, string $key = ''): array {
    global $BASE;
    $c = [];
    $r = req('GET', $BASE . $path, null, $c, $key);
    return $r;
}

function db(): PDO {
    global $DB;
    $p = new PDO('sqlite:' . $DB);
    $p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $p->setAttribute(PDO::ATTR_TIMEOUT, 5);
    $p->exec('PRAGMA journal_mode=WAL');
    return $p;
}
function q(string $sql, array $bind = []): array {
    $st = db()->prepare($sql);
    $st->execute($bind);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}
function settingRow(int $tid, string $key): ?string {
    $r = q('SELECT value FROM settings WHERE tenant_id = ? AND key = ?', [$tid, $key]);
    return $r === [] ? null : (string)$r[0]['value'];
}

// ============================================================
// 0. 重建库 + 种子
// ============================================================
echo "== 0. 重建测试库与种子 ==\n";
@unlink($DB);
echo shell_exec('"' . PHP_BINARY . '" "' . $ROOT . '/scripts/dev_init_sqlite.php" "' . $DB . '" 2>&1');
foreach (array_merge(glob($ROOT . '/runtime/cache/*/*') ?: [], glob($ROOT . '/runtime/cache/*/*/*') ?: []) as $f) {
    if (is_file($f)) { @unlink($f); }
}

$pdo = db();
$now = date('Y-m-d H:i:s');
$at  = static fn(int $min): string => date('Y-m-d H:i:s', time() + $min * 60);

$mkTenant = function (string $slug, string $name, string $owner) use ($pdo, $now): int {
    $pdo->exec("INSERT INTO tenants (applicant_id, slug, name, status, admin_username, created_at, updated_at)
                VALUES (1, '$slug', '$name', 'approved', '$owner', '$now', '$now')");
    return (int)$pdo->lastInsertId();
};
$mkUser = function (int $tid, string $username, string $nick, string $role, array $perms = [],
    int $show = 1, int $statsPub = 1, string $status = 'approved') use ($pdo, $now): int {
    $hash = password_hash('pass123456', PASSWORD_DEFAULT);
    $pj   = $perms ? json_encode($perms, JSON_UNESCAPED_UNICODE) : '';
    $pdo->exec("INSERT INTO users (tenant_id, username, password, nickname, role, status, admin_perms,
                                   show_public, stats_public, reg_ip, created_at, updated_at)
                VALUES ($tid, '$username', '$hash', '$nick', '$role', '$status', '$pj',
                        $show, $statsPub, '9.9.9.9', '$now', '$now')");
    return (int)$pdo->lastInsertId();
};
$mkKey = function (int $tid, int $uid, string $name) use ($pdo, $now): array {
    [$plain, $hash, $prefix] = battle_api_make_key();
    $pdo->exec("INSERT INTO api_keys (key_hash, key_prefix, name, scope, tenant_id, bound_user_id, status, created_by, created_at)
                VALUES ('$hash', '$prefix', '$name', 'tenant', $tid, $uid, 1, $uid, '$now')");
    return ['plain' => $plain, 'prefix' => $prefix, 'id' => (int)$pdo->lastInsertId()];
};

$T1 = $mkTenant('ff1', '对接一队', 'ff1boss');
$T2 = $mkTenant('ff2', '对接二队', 'ff2boss');
$T3 = $mkTenant('ff3', '对接三队', 'ff3boss');

$o1  = $mkUser($T1, 'ff1boss', '一队站长', 'admin');
$pub1 = $mkUser($T1, '100090001', '愿意露脸的', 'user');
$pub2 = $mkUser($T1, '100090002', '露脸但不给战绩', 'user', [], 1, 0);
$pri  = $mkUser($T1, '100090003', '不想露脸的', 'user', [], 0);
$pMv  = $mkUser($T1, '100090004', '看名单的副管', 'admin', ['member_view']);
$pen  = $mkUser($T1, '100090005', '还没审核的', 'user', [], 1, 1, 'pending');
$o2  = $mkUser($T2, 'ff2boss', '二队站长', 'admin');
$q1  = $mkUser($T2, '200090001', '二队甲', 'user');
$boss3 = $mkUser($T3, 'ff3boss', '三队站长', 'admin');

$KEY1 = $mkKey($T1, $o1, '论坛对接');
$KEY2 = $mkKey($T2, $o2, '二队 Key');
// 平台作用域那枚：按设计读不到战队数据（TenantContext 不会切到某一队）
[$pPlain, $pHash, $pPrefix] = battle_api_make_key();
$pdo->exec("INSERT INTO api_keys (key_hash, key_prefix, name, scope, tenant_id, bound_user_id, status, created_at)
            VALUES ('$pHash', '$pPrefix', '平台 Key', 'platform', NULL, NULL, 1, '$now')");

$pdo->exec("INSERT INTO teams (tenant_id, name, created_at, updated_at) VALUES ($T1, '夜袭组', '$now', '$now')");
$teamId = (int)$pdo->lastInsertId();
$pdo->exec("UPDATE users SET team_id = $teamId WHERE id = $pub1");
$pdo->exec("INSERT INTO role_tags (tenant_id, name, created_at, updated_at) VALUES ($T1, '指挥', '$now', '$now')");
$tagId = (int)$pdo->lastInsertId();
$pdo->exec("UPDATE users SET role_tags = '" . json_encode([$tagId]) . "' WHERE id = $pub1");

$pdo->exec("INSERT INTO player_stats (tenant_id, user_id, matches, win_rate, kd, kills, created_at, updated_at)
            VALUES ($T1, $pub1, 42, 61.90, 2.56, 512, '$now', '$now')");
$pdo->exec("INSERT INTO player_stats (tenant_id, user_id, matches, kills, created_at, updated_at)
            VALUES ($T1, $pub2, 7, 33, '$now', '$now')");

$d0 = date('Y-m-d'); $dOld = date('Y-m-d', strtotime('-200 day'));
foreach ([[$pub1, $d0, 1], [$pub1, date('Y-m-d', strtotime('-1 day')), 2], [$pri, $dOld, 1]] as $c) {
    $pdo->exec("INSERT INTO checkins (tenant_id, user_id, checkin_date, streak, created_at)
                VALUES ($T1, {$c[0]}, '{$c[1]}', {$c[2]}, '{$c[1]} 09:00:00')");
}

$pdo->exec("INSERT INTO training_matches (tenant_id, title, mode, match_time, status, created_by, created_at, updated_at)
            VALUES ($T1, '窗口内的训练', 'squad', '" . $at(120) . "', 'signup', $o1, '$now', '$now')");
$tmIn = (int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO training_matches (tenant_id, title, mode, match_time, status, created_by, created_at, updated_at)
            VALUES ($T1, '太旧的训练', 'squad', '" . $at(-90 * 1440) . "', 'finished', $o1, '$now', '$now')");
$tmOld = (int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO training_matches (tenant_id, title, mode, match_time, status, created_by, created_at, updated_at)
            VALUES ($T1, '太新的训练', 'squad', '" . $at(90 * 1440) . "', 'signup', $o1, '$now', '$now')");
$tmNew = (int)$pdo->lastInsertId();
foreach ([$pub1, $pub2, $pri] as $u) {
    $pdo->exec("INSERT INTO training_signups (tenant_id, match_id, user_id, side, created_at)
                VALUES ($T1, $tmIn, $u, 'a', '$now')");
}

// 约战：一场一队发起，一场一队应战（应战那场的对手必须指向发起方）
$pdo->exec("INSERT INTO battles (title, game_mode, battle_time, challenger_type, challenger_id, defender_type, defender_id,
                                 status, created_at, updated_at)
            VALUES ('一队挑战', '标准', '" . $at(180) . "', 'tenant', $T1, 'tenant', $T2, 'accepted', '$now', '$now')");
$B1 = (int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO battles (title, game_mode, battle_time, challenger_type, challenger_id, defender_type, defender_id,
                                 status, created_at, updated_at)
            VALUES ('二队来挑战', '标准', '" . $at(240) . "', 'tenant', $T2, 'tenant', $T1, 'pending', '$now', '$now')");
$B2 = (int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO battle_signups (battle_id, side, signup_type, user_id, display_name, tenant_id, manual, created_at)
            VALUES ($B1, 'a', 'user', $pub1, '', $T1, 0, '$now')");
$pdo->exec("INSERT INTO battle_signups (battle_id, side, signup_type, user_id, display_name, tenant_id, manual, created_at)
            VALUES ($B1, 'b', 'user', $q1, '', $T2, 0, '$now')");
$pdo->exec("INSERT INTO battle_signups (battle_id, side, signup_type, user_id, display_name, tenant_id, manual, created_at)
            VALUES ($B2, 'b', 'user', $pub2, '', $T1, 0, '$now')");
$pdo->exec("INSERT INTO battle_signups (battle_id, side, signup_type, user_id, display_name, tenant_id, manual, created_at)
            VALUES ($B2, 'a', 'user', $q1, '', $T2, 0, '$now')");

$pdo->exec("INSERT INTO custom_events (tenant_id, title, start_time, duration_min, note, created_at, updated_at)
            VALUES ($T1, '窗口内的自建房', '" . $at(60) . "', 90, '房间号 8888', '$now', '$now')");
$pdo->exec("INSERT INTO custom_events (tenant_id, title, start_time, duration_min, note, created_at, updated_at)
            VALUES ($T1, '太旧的自建房', '" . $at(-120 * 1440) . "', 60, '早就完了', '$now', '$now')");

$pdo->exec("INSERT INTO announcements (tenant_id, title, content, created_at, updated_at)
            VALUES ($T1, '勾选过的公告', '这条该被论坛读到', '$now', '$now')");
$anYes = (int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO announcements (tenant_id, title, content, created_at, updated_at)
            VALUES ($T1, '没勾选的公告', '这条是队内的，不该出去', '$now', '$now')");
$anNo = (int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO settings (tenant_id, key, value) VALUES ($T1, 'show_notice_ids', '$anYes')
            ON CONFLICT(tenant_id, key) DO UPDATE SET value = '$anYes'");
$pdo->exec("INSERT INTO settings (tenant_id, key, value) VALUES ($T1, 'show_modules', '"
    . json_encode(['stats' => 1, 'gallery' => 1, 'honor' => 1, 'recruit' => 1, 'members' => 1,
        'notices' => 1, 'contents' => 1, 'post' => 0, 'intent' => 1]) . "')");

foreach (['ff1' => $T1, 'ff2' => $T2, 'ff3' => $T3] as $slug => $tid) {
    [$s, $j] = login($slug . 'boss', $slug, $slug . 'boss', 'pass123456');
    check("登录 $slug", $s === 200 && ($j['code'] ?? 1) === 0, "HTTP $s " . J($j));
}
[$s, $j] = login('pub1', 'ff1', '100090001', 'pass123456');
check('登录普通成员 pub1', $s === 200 && ($j['code'] ?? 1) === 0, J($j));
[$s, $j] = login('pmv', 'ff1', '100090004', 'pass123456');
check('登录副管理员 pmv', $s === 200 && ($j['code'] ?? 1) === 0, J($j));

// ============================================================
// 1. 鉴权
// ============================================================
echo "\n== 1. 鉴权：不认登录态、只认本队 Key，而且只有 GET ==\n";
foreach (['team', 'members', 'activity', 'notices'] as $p) {
    [$s] = open_get('/api/open/forum/' . $p, '');
    check("没带 Key 读 $p → 401", $s === 401, "HTTP $s");
}
[$s, $j] = open_get('/api/open/forum/members', str_repeat('a', 48));
check('乱 Key → 401', $s === 401, "HTTP $s");
[$s, $j] = open_get('/api/open/forum/members', $pPlain);
check('平台作用域的 Key 读不到战队数据（本队开关还没打开也算挡）', $s === 403, "HTTP $s " . J($j));
check('平台 Key 被挡时说的是「要用本队的密钥」',
    str_contains(J($j), '本队的密钥'), J($j));

// 开关没打开时四条都不给
foreach (['team', 'members', 'activity', 'notices'] as $p) {
    [$s, $j] = open_get('/api/open/forum/' . $p, $KEY1['plain']);
    check("本队还没打开时读 $p → 403", $s === 403 && str_contains(J($j), '还没打开'), "HTTP $s " . J($j));
}

// 只读：其它方法进不来，也不写库
$before = (int)q('SELECT COUNT(*) AS c FROM users')[0]['c'];
foreach (['POST', 'PUT', 'DELETE'] as $m) {
    $c = [];
    $ctx = stream_context_create(['http' => ['method' => $m,
        'header' => "X-Api-Key: {$KEY1['plain']}\r\nContent-Type: application/json\r\n",
        'content' => '{}', 'ignore_errors' => true]]);
    $raw = (string)@file_get_contents($BASE . '/api/open/forum/members', false, $ctx);
    $st = 0;
    foreach ($http_response_header ?? [] as $h) { if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $mm)) { $st = (int)$mm[1]; } }
    check("$m 进不了 members", in_array($st, [404, 405], true), "HTTP $st " . mb_substr($raw, 0, 80));
}
$after = (int)q('SELECT COUNT(*) AS c FROM users')[0]['c'];
check('写请求一条数据都没改动', $after === $before, "$before → $after");
// 这四条挂在站点根上：租户目录下不该有第二份（有就是同一份数据两个地址，Key 与限频口径也各算一遍）
foreach (['/ff1/api/open/forum/members', '/ff1/api/open/forum/team'] as $p) {
    $st = (int)(open_get($p, $KEY1['plain'])[0]);
    check("租户目录下 $p 不存在（只认根路径那一条）", $st === 404, "HTTP $st");
}

// ============================================================
// 2. 三道闸
// ============================================================
echo "\n== 2. 三道闸：队长开、平台按队停、平台总闸 ==\n";
[$s, $j] = as_req('pub1', 'POST', '/ff1/api/admin/forum-api/toggle', ['on' => '1']);
check('普通成员点不开这个开关', $s === 403 || ($j['code'] ?? 0) !== 0, "HTTP $s " . J($j));
[$s, $j] = as_req('pmv', 'POST', '/ff1/api/admin/forum-api/toggle', ['on' => '1']);
check('副管理员也点不开（这一格只给站长）', $s === 403 || ($j['code'] ?? 0) !== 0, "HTTP $s " . J($j));
[$s, $j] = as_req('ff1boss', 'POST', '/ff1/api/admin/forum-api/toggle', ['on' => '1']);
check('站长打开成功', $s === 200 && ($j['code'] ?? 1) === 0 && ($j['data']['serving'] ?? false) === true, J($j));
[$s, $j] = open_get('/api/open/forum/members', $KEY1['plain']);
check('打开后 members 读得到', $s === 200 && ($j['code'] ?? 1) === 0, "HTTP $s");
$st = q("SELECT value FROM settings WHERE tenant_id = $T1 AND key = 'forum_api_on'");
check('开关落在 settings 那一行（值 1）', ($st[0]['value'] ?? '') === '1', J($st));

[$s, $j] = as_req('ff2boss', 'POST', '/ff2/api/admin/forum-api/toggle', ['on' => '1']);
check('二队站长自己开自己那一格', ($j['code'] ?? 1) === 0, J($j));
[$s, $j] = open_get('/api/open/forum/members', $KEY2['plain']);
check('二队打开后也能读', $s === 200, "HTTP $s");

// 平台按站点停用
[$s, $j] = as_req('plat', 'POST', '/api/platform/auth/login', ['username' => 'admin', 'password' => 'admin123']);
check('平台超管登录', $s === 200 && ($j['code'] ?? 1) === 0, "HTTP $s " . J($j));
[$s, $j] = as_req('plat', 'POST', '/api/platform/admin/forum-api/toggle', ['tenant_id' => $T1, 'off' => '1']);
check('平台停用一队', ($j['code'] ?? 1) === 0 && (int)($j['data']['serving'] ?? 1) === 0, J($j));
[$s, $j] = open_get('/api/open/forum/members', $KEY1['plain']);
check('被平台停用后一队读不到', $s === 403 && str_contains(J($j), '平台已单独停用'), "HTTP $s " . J($j));
[$s, $j] = open_get('/api/open/forum/members', $KEY2['plain']);
check('二队不受影响（只停了一队）', $s === 200, "HTTP $s");
$fo = settingRow(0, 'forum_api_force_off');
check('停用名单就是一串租户号', $fo !== null && str_contains($fo, (string)$T1), (string)$fo);
$stillOn = settingRow($T1, 'forum_api_on');
check('平台停用没改队长自己那一格（放回去照旧）', $stillOn === '1', (string)$stillOn);

[$s] = as_req('plat', 'POST', '/api/platform/admin/forum-api/toggle', ['tenant_id' => $T1, 'off' => '0']);
[$s, $j] = open_get('/api/open/forum/members', $KEY1['plain']);
check('放出来就恢复', $s === 200, "HTTP $s");

[$s, $j] = as_req('plat', 'POST', '/api/platform/admin/forum-api/master', ['on' => '0']);
check('平台总闸关掉', ($j['code'] ?? 1) === 0 && ($j['data']['master_on'] ?? true) === false, J($j));
[$s, $j] = open_get('/api/open/forum/members', $KEY1['plain']);
check('总闸关着一队读不到', $s === 403 && str_contains(J($j), '平台已停用'), J($j));
[$s, $j] = open_get('/api/open/forum/members', $KEY2['plain']);
check('总闸关着二队也读不到', $s === 403, "HTTP $s");
[$s, $j] = as_req('ff1boss', 'GET', '/ff1/api/admin/forum-api');
check('战队那一页看得到「是平台挡的」', ($j['data']['platform']['master_on'] ?? true) === false
    && str_contains((string)($j['data']['reason'] ?? ''), '平台已停用论坛对接'), J($j['data']['reason'] ?? ''));
[$s, $j] = as_req('plat', 'POST', '/api/platform/admin/forum-api/master', ['on' => '1']);
[$s] = open_get('/api/open/forum/members', $KEY1['plain']);
check('总闸打开后恢复', $s === 200, "HTTP $s");

// ============================================================
// 3. members
// ============================================================
echo "\n== 3. 成员名单那一档 ==\n";
[$s, $j] = open_get('/api/open/forum/members', $KEY1['plain']);
$d = $j['data'] ?? [];
$nicks = array_column($d['list'] ?? [], 'nickname');
check('只出本人同意对外展示的那四个（站长与副管也勾过，所以他们在——与访客页同一口径）',
    count($d['list'] ?? []) === 4, J($nicks));
check('名单就是那四个人',
    $nicks === ['一队站长', '愿意露脸的', '露脸但不给战绩', '看名单的副管'], J($nicks));
check('「不想露脸的」不出现', !in_array('不想露脸的', $nicks, true), J($nicks));
check('待审核的人不出现', !in_array('还没审核的', $nicks, true), J($nicks));
check('站长自己也同意过（默认开），所以他在', in_array('一队站长', $nicks, true), J($nicks));
$byUid = [];
foreach ($d['list'] ?? [] as $r) { $byUid[$r['nickname']] = $r; }
$one = $byUid['愿意露脸的'] ?? [];
check('每行带 uid（论坛拿它绑账号）', isset($one['uid']) && (int)$one['uid'] > 0, J($one));
check('每行带打码 QQ，且不是完整号', ($one['qq_masked'] ?? '') === mask_qq('100090001')
    && ($one['qq_masked'] ?? '') !== '100090001', (string)($one['qq_masked'] ?? ''));
check('打码是前 3 后 2 那个形状', (bool)preg_match('/^100\*+01$/', (string)($one['qq_masked'] ?? '')),
    (string)($one['qq_masked'] ?? ''));
check('小队与职责标签带上了', ($one['team'] ?? '') === '夜袭组' && ($one['tag'] ?? '') === '指挥', J($one));
check('愿意露脸的这个人 stats_public=1', (int)($one['stats_public'] ?? 0) === 1, J($one));
check('他的场次 42、KD 2.56 出得来', (int)($one['stats']['matches'] ?? 0) === 42
    && (float)($one['stats']['kd'] ?? 0) === 2.56, J($one['stats'] ?? []));
$two = $byUid['露脸但不给战绩'] ?? [];
check('另一档关着的人：名单里在，但 stats 是空的',
    isset($two['uid']) && (int)$two['stats_public'] === 0 && $two['stats'] === [], J($two));
// 类型得稳定：不公开那档落的是 JSON 里的 {} 而不是 []，论坛那边（Python 或 JS）拿到的 stats
// 永远是一个对象，不会这一行是数组、下一行是对象
[, , $mraw] = open_get('/api/open/forum/members', $KEY1['plain']);
check('原始 JSON 里那一行是 "stats":{}（不是空数组）',
    str_contains($mraw, '"stats":{}') && !str_contains($mraw, '"stats":[]'), mb_substr($mraw, 0, 200));
check('他没传过的项是 null 而不是 0（这里不能用 ?? —— ?? 把 null 当缺失，正好把要验的东西吃掉）',
    array_key_exists('revives', $one['stats'] ?? []) && $one['stats']['revives'] === null,
    J($one['stats'] ?? []));
check('stat_labels 给出二十项中文名', count($d['stat_labels'] ?? []) === 20,
    (string)count($d['stat_labels'] ?? []));
check('total 与 list 长度一致', (int)($d['total'] ?? -1) === count($d['list'] ?? []), J($d['total'] ?? null));
$uidBefore = (int)($one['uid'] ?? 0);
[$s, $j2] = open_get('/api/open/forum/members', $KEY1['plain']);
$again = 0;
foreach (($j2['data']['list'] ?? []) as $r) { if ($r['nickname'] === '愿意露脸的') { $again = (int)$r['uid']; } }
check('两次调用 uid 一样（能当账号绑）', $again === $uidBefore && $uidBefore > 0, "$uidBefore → $again");
$note = (string)($d['note'] ?? '');
check('响应里写清楚「认人用 uid，打码那串会撞」',
    str_contains($note, 'uid') && str_contains($note, '撞'), $note);
// 这一条是故意拍的：队长在「对外展示页」可以把「成员风采」那一块关掉，但那是他自己网站上的版式，
// 不是「这份名单能不能给论坛」—— 论坛对接这一格开了就该给，不然他会问「接口开了怎么名单是空的」。
$pdo = db();
$pdo->exec("INSERT INTO settings (tenant_id, key, value) VALUES ($T1, 'show_modules', '{\"members\":0}')
            ON CONFLICT(tenant_id, key) DO UPDATE SET value = '{\"members\":0}'");
[$s, $j] = open_get('/api/open/forum/members', $KEY1['plain']);
check('访客页那块「成员风采」关掉不影响这条路（开了接口就给名单）',
    $s === 200 && count($j['data']['list'] ?? []) === 4, (string)count($j['data']['list'] ?? []));
$pdo->exec("DELETE FROM settings WHERE tenant_id = $T1 AND key = 'show_modules'");

// ============================================================
// 4. activity
// ============================================================
echo "\n== 4. 活动与签到那一档 ==\n";
[$s, $j] = open_get('/api/open/forum/activity', $KEY1['plain']);
$a = $j['data'] ?? [];
$titles = array_column($a['events'] ?? [], 'title');
check('窗口内的训练赛出得来', in_array('窗口内的训练', $titles, true), J($titles));
check('太旧与太新的训练赛都不出', !in_array('太旧的训练', $titles, true) && !in_array('太新的训练', $titles, true),
    J($titles));
check('窗口内的自建房出得来、太旧的不出',
    in_array('窗口内的自建房', $titles, true) && !in_array('太旧的自建房', $titles, true), J($titles));
check('两场约战都在（一场我们是发起方、一场是应战方）',
    in_array('一队挑战', $titles, true) && in_array('二队来挑战', $titles, true), J($titles));
$byTitle = [];
foreach ($a['events'] ?? [] as $e) { $byTitle[(string)$e['title']] = $e; }
$b1 = $byTitle['一队挑战'] ?? [];
$b2 = $byTitle['二队来挑战'] ?? [];
check('发起方那场：对手是二队、本队侧报名 1 人',
    ($b1['opponent'] ?? '') === '对接二队' && (int)($b1['signups'] ?? 0) === 1 && ($b1['our_side'] ?? '') === 'a',
    J($b1));
check('应战那场：对手要指向发起方（一队看到的是二队）',
    ($b2['opponent'] ?? '') === '对接二队' && ($b2['our_side'] ?? '') === 'b', J($b2));
check('应战那场本队侧报名 1 人（对方那 1 个不算）', (int)($b2['signups'] ?? 0) === 1, J($b2));
$ce = $byTitle['窗口内的自建房'] ?? [];
check('自建房那条带说明（房间号这种）', ($ce['note'] ?? '') === '房间号 8888', J($ce));
$ck = $a['checkins'] ?? [];
$ckNames = array_column($ck, 'nickname');
check('签到榜只有 1 个人（另一个关了对外展示）', count($ck) === 1 && $ckNames === ['愿意露脸的'], J($ck));
$ckOne = $ck[0] ?? [];
check('签到榜给的是天数与连签，不含账号', (int)($ckOne['days'] ?? 0) === 2
    && !isset($ckOne['username']) && !isset($ckOne['qq']), J($ckOne));
$w = $a['window'] ?? [];
check('时间窗写在响应里（前后各 30 天）',
    strtotime((string)($w['to'] ?? '')) - strtotime((string)($w['from'] ?? '')) === 60 * 86400, J($w));

// ============================================================
// 5. notices 与 team
// ============================================================
echo "\n== 5. 公告与名片 ==\n";
[$s, $j] = open_get('/api/open/forum/notices', $KEY1['plain']);
$nl = $j['data']['list'] ?? [];
$nTitles = array_column($nl, 'title');
check('勾选过的公告出得来', $nTitles === ['勾选过的公告'], J($nTitles));
check('没勾的那条队内公告不出去', !in_array('没勾选的公告', $nTitles, true), J($nTitles));
check('公告说明写着「只出勾选过的」', str_contains((string)($j['data']['note'] ?? ''), '勾选'),
    (string)($j['data']['note'] ?? ''));

[$s, $j] = open_get('/api/open/forum/team', $KEY1['plain']);
$t = $j['data'] ?? [];
check('名片带队名与 slug', ($t['name'] ?? '') === '对接一队' && ($t['slug'] ?? '') === 'ff1', J($t));
check('site_url 能把人链回本队主页', str_ends_with((string)($t['site_url'] ?? ''), '/ff1/'),
    (string)($t['site_url'] ?? ''));
check('公开成员计数=4（与 members 那一档同一判据，不是两套算法）', (int)($t['stats']['members_public'] ?? 0) === 4,
    J($t['stats'] ?? []));
check('办过的活动数认得训练赛那一场 finished', (int)($t['stats']['events_done'] ?? 0) === 1, J($t['stats'] ?? []));
$pdo->exec("INSERT INTO settings (tenant_id, key, value) VALUES ($T1, 'show_contact', '群号 12345')
            ON CONFLICT(tenant_id, key) DO UPDATE SET value = '群号 12345'");
[$s, $j] = open_get('/api/open/forum/team', $KEY1['plain']);
check('联系方式给的是对外展示页那一串（本来就在公开页上）',
    ($j['data']['contact'] ?? '') === '群号 12345', (string)($j['data']['contact'] ?? ''));

// ============================================================
// 6. QQ / 邮箱 / IP 复扫
// ============================================================
echo "\n== 6. 复扫：一个完整账号都不许出去 ==\n";
$qq = array_column(q('SELECT username FROM users'), 'username');
$dirty = [];
foreach (['team', 'members', 'activity', 'notices'] as $p) {
    [$s, $j, $raw] = open_get('/api/open/forum/' . $p, $KEY1['plain']);
    foreach ($qq as $u) {
        $u = (string)$u;
        if ($u !== '' && str_contains($raw, $u)) { $dirty[] = "$p 里有完整号 $u"; }
    }
    if (preg_match('/\d{5,11}@qq\.com/i', $raw)) { $dirty[] = "$p 里有邮箱"; }
    if (str_contains($raw, '9.9.9.9')) { $dirty[] = "$p 里有注册 IP"; }
    if (str_contains($raw, 'password') || str_contains($raw, '$2y$')) { $dirty[] = "$p 里像有密码哈希"; }
}
check('四条响应里没有完整账号、邮箱、IP、密码哈希', $dirty === [], implode('；', $dirty));
[$s, $j] = open_get('/api/open/forum/members', $KEY2['plain']);
$d2 = $j['data']['list'] ?? [];
$n2 = array_column($d2, 'nickname');
check('二队的 Key 只读到二队的人', $n2 === ['二队站长', '二队甲'], J($n2));
check('二队读不到一队的人（连昵称都不出）',
    !in_array('愿意露脸的', $n2, true) && !in_array('一队站长', $n2, true), J($n2));
[$s, $j] = open_get('/api/open/forum/activity', $KEY2['plain']);
$t2 = array_column($j['data']['events'] ?? [], 'title');
check('二队的活动里只有一队参与那两场的对手视角', in_array('一队挑战', $t2, true)
    && !in_array('窗口内的训练', $t2, true) && !in_array('窗口内的自建房', $t2, true), J($t2));

// ============================================================
// 7. 限频与留痕
// ============================================================
echo "\n== 7. 每把 Key 限频真的会挡、每次调用都留痕 ==\n";
$logsBefore = (int)q("SELECT COUNT(*) AS c FROM api_logs WHERE tenant_id = ? AND path LIKE '/api/open/forum/%'", [$T1])[0]['c'];
$pdo->exec("INSERT INTO settings (tenant_id, key, value) VALUES (0, 'api_rate_limit', '4')
            ON CONFLICT(tenant_id, key) DO UPDATE SET value = '4'");
foreach (glob($ROOT . '/runtime/cache/*/*') ?: [] as $f) { if (is_file($f)) { @unlink($f); } }
$codes = [];
for ($i = 0; $i < 6; $i++) {
    [$s] = open_get('/api/open/forum/notices', $KEY1['plain']);
    $codes[] = $s;
}
check('同一把 Key 连打六次：前面通、后面被限频（429）',
    $codes[0] === 200 && in_array(429, $codes, true), J($codes));
$pdo->exec("DELETE FROM settings WHERE tenant_id = 0 AND key = 'api_rate_limit'");
foreach (glob($ROOT . '/runtime/cache/*/*') ?: [] as $f) { if (is_file($f)) { @unlink($f); } }
$logsAfter = (int)q("SELECT COUNT(*) AS c FROM api_logs WHERE tenant_id = ? AND path LIKE '/api/open/forum/%'", [$T1])[0]['c'];
check('这些调用都进了 api_logs（含被限频的那几次）', $logsAfter >= $logsBefore + 6,
    "$logsBefore → $logsAfter");
$byKey = (int)q('SELECT COUNT(*) AS c FROM api_logs WHERE api_key_id = ?', [$KEY1['id']])[0]['c'];
check('日志挂得上是哪把 Key 干的', $byKey > 0, (string)$byKey);
$failRows = q("SELECT fail_reason FROM api_logs WHERE status = 401 AND tenant_id = 0 ORDER BY id DESC LIMIT 3");
check('认证失败那几次也留了痕（谁在乱试 Key 看得见）', count($failRows) >= 1, J($failRows));

// ============================================================
// 8. 管理端那一页
// ============================================================
echo "\n== 8. 队长那一页 ==\n";
[$s, $j] = as_req('ff1boss', 'GET', '/ff1/api/admin/forum-api');
$ad = $j['data'] ?? [];
check('站长打得开这一页', $s === 200 && ($j['code'] ?? 1) === 0, "HTTP $s");
check('页面上是「已打开、正在给读数」', ($ad['on'] ?? false) === true && ($ad['serving'] ?? false) === true,
    J([$ad['on'] ?? null, $ad['serving'] ?? null]));
check('四条接口地址都在页面上', count($ad['endpoints'] ?? []) === 4, (string)count($ad['endpoints'] ?? []));
check('每条都写着干什么用',
    str_contains(J($ad['endpoints'] ?? []), '战队名片') && str_contains(J($ad['endpoints'] ?? []), '签到榜'),
    J($ad['endpoints'] ?? []));
$keys = $ad['keys'] ?? [];
check('Key 列表认得出刚才那枚（按名字与前缀）',
    count($keys) === 1 && ($keys[0]['name'] ?? '') === '论坛对接'
    && ($keys[0]['key_prefix'] ?? '') === $KEY1['prefix'], J($keys));
check('页面上绝不出现明文 Key',
    !str_contains(J($ad), $KEY1['plain']), '');
$recent = $ad['recent'] ?? [];
$paths  = array_values(array_unique(array_column($recent, 'path')));
check('最近调用那一叠只列论坛对接这四条（约战那些调用不混进来）',
    count($recent) > 0 && array_reduce($paths, static fn($c, $p) => $c && str_starts_with((string)$p, '/api/open/forum/'), true),
    J($paths));
check('被限频那几次也在页面上（429 也留痕，队长看得见是谁在猛打）',
    count(array_filter($recent, static fn($r) => (int)$r['status'] === 429)) > 0, J($recent));
check('每条都带着时间与来源 IP', (bool)preg_match('/^\d{4}-\d{2}-\d{2}/', (string)($recent[0]['created_at'] ?? ''))
    && ($recent[0]['ip'] ?? '') !== '', J($recent[0] ?? []));
check('上限也回给前端（人数与时间窗）',
    (int)($ad['caps']['members'] ?? 0) === 500 && (int)($ad['caps']['window_days'] ?? 0) === 30, J($ad['caps'] ?? []));

[$s, $j] = as_req('pub1', 'GET', '/ff1/api/admin/forum-api');
check('普通成员看不到这一页', $s === 403 || ($j['code'] ?? 0) !== 0, "HTTP $s");
[$s, $j] = as_req('pmv', 'GET', '/ff1/api/admin/forum-api');
check('持有 member_view 的副管理员也看不到（这一格只给站长）',
    $s === 403 || ($j['code'] ?? 0) !== 0, "HTTP $s");

$log = q("SELECT admin_name, detail FROM admin_logs WHERE action = '论坛对接' AND tenant_id = $T1
          ORDER BY id DESC LIMIT 1");
check('开开关那一下记进行操作日志',
    isset($log[0]['detail']) && str_contains((string)$log[0]['detail'], '打开')
    && str_contains((string)$log[0]['admin_name'], 'ff1boss'), J($log[0] ?? []));

[$s, $j] = as_req('ff1boss', 'POST', '/ff1/api/admin/forum-api/toggle', ['on' => '0']);
check('站长能关掉', ($j['code'] ?? 1) === 0 && ($j['data']['serving'] ?? true) === false, J($j));
[$s] = open_get('/api/open/forum/members', $KEY1['plain']);
check('关掉之后立刻读不到', $s === 403, "HTTP $s");
[$s, $j] = as_req('ff1boss', 'POST', '/ff1/api/admin/forum-api/toggle', ['on' => '1']);
[$s] = open_get('/api/open/forum/members', $KEY1['plain']);
check('再打开又读得到（开关不是单向门）', $s === 200, "HTTP $s");
$nLog = (int)q("SELECT COUNT(*) AS c FROM admin_logs WHERE action = '论坛对接' AND tenant_id = $T1")[0]['c'];
check('开与关各记一行（谁动的、动成什么样，事后查得到）', $nLog >= 3, (string)$nLog);

// ============================================================
// 9. 平台端那一页
// ============================================================
echo "\n== 9. 平台那一页 ==\n";
[$s, $j] = as_req('plat', 'GET', '/api/platform/admin/forum-api');
$pd = $j['data'] ?? [];
check('平台超管打得开', $s === 200 && ($j['code'] ?? 1) === 0, "HTTP $s");
$map = [];
foreach ($pd['list'] ?? [] as $r) { $map[(int)$r['id']] = $r; }
check('列表里认得出三个队', isset($map[$T1], $map[$T2], $map[$T3]), J(array_keys($map)));
check('一与二队显示正在给读数，三队没打开',
    (int)$map[$T1]['serving'] === 1 && (int)$map[$T2]['serving'] === 1 && (int)$map[$T3]['serving'] === 0,
    J(array_map(static fn($r) => [$r['slug'], $r['serving'], $r['tenant_on'], $r['force_off']], $map)));
check('三队那一行写的是「本队没打开」而不是被平台停',
    (int)$map[$T3]['tenant_on'] === 0 && (int)$map[$T3]['force_off'] === 0, J($map[$T3] ?? []));
check('调用次数按队算得出来（一队被调过）', (int)$map[$T1]['calls_24h'] > 0, J($map[$T1] ?? []));
check('最近一次调用时间也带着', ($map[$T1]['last_call'] ?? '') !== '', J($map[$T1] ?? []));
check('汇总那两数对得上（serving 计数 = 列表里为 1 的行数）',
    (int)$pd['serving'] === count(array_filter($map, static fn($r) => (int)$r['serving'] === 1)),
    J([$pd['serving'] ?? null, array_map(static fn($r) => $r['serving'], $map)]));
[$s, $j] = as_req('ff1boss', 'GET', '/api/platform/admin/forum-api');
check('战队站长冒充不了平台', $s === 403 || ($j['code'] ?? 0) !== 0, "HTTP $s");
$anon = [];
[$s, $j] = req('GET', $BASE . '/api/platform/admin/forum-api', null, $anon);
check('没登录也进不去平台那一页', $s === 403 || $s === 401 || ($j['code'] ?? 0) !== 0, "HTTP $s");

// ============================================================
// 10. 零 DDL
// ============================================================
echo "\n== 10. 这一版不动库 ==\n";
$names = array_column(q("SELECT name FROM sqlite_master WHERE type='table'"), 'name');
$newTables = array_values(array_filter($names, static fn($n) => (bool)preg_match('/forum_api|feed|forum_feed/i', (string)$n)));
check('没有为这一版新建任何表', $newTables === [], implode(',', $newTables));
check('三个状态确实都落在 settings 里',
    settingRow($T1, 'forum_api_on') !== null && settingRow(0, 'forum_api_force_off') !== null,
    J([settingRow($T1, 'forum_api_on'), settingRow(0, 'forum_api_force_off')]));
check('没给 settings 插默认行也照样工作：三队那一格根本不存在',
    settingRow($T3, 'forum_api_on') === null, (string)settingRow($T3, 'forum_api_on'));

// ============================================================
// 11. 回归
// ============================================================
echo "\n== 11. 没把别的东西带崩 ==\n";
[$s, $j] = open_get('/api/open/battles', $KEY1['plain']);
check('同一把 Key 仍能读约战开放接口（复用那条路的代价，页面上写明白了）',
    $s === 200 && ($j['code'] ?? 1) === 0, "HTTP $s");
[$s, $j] = as_req('ff1boss', 'GET', '/ff1/api/admin/export');
check('导出表格那一页照常', $s === 200 && ($j['code'] ?? 1) === 0, "HTTP $s");
[$s, $j] = as_req('ff1boss', 'GET', '/ff1/api/admin/api-keys');
check('API 密钥那一页照常（列的还是那几枚）', $s === 200 && ($j['code'] ?? 1) === 0
    && count($j['data'] ?? []) === 1, J($j['data'] ?? []));
[$s, $j] = as_req('pub1', 'GET', '/ff1/api/inbox?limit=20');
check('通知页照常', $s === 200 && ($j['code'] ?? 1) === 0, "HTTP $s");
[$s, $j] = as_req('ff1boss', 'GET', '/ff1/api/admin/announcements');
check('公告栏照常（那张表还是公告栏的，不在这条路上）',
    $s === 200 && ($j['code'] ?? 1) === 0 && isset($j['data']['list']), "HTTP $s");
[$s, $j] = as_req('ff1boss', 'GET', '/ff1/api/admin/remind');
check('赛前提醒那页照常', $s === 200 && ($j['code'] ?? 1) === 0, "HTTP $s");
[$s, $jj, $raw] = open_get('/ff1/', '');
check('战队端首页照常', $s === 200 && strlen($raw) > 2000, "HTTP $s");
[$s, $jj, $raw] = open_get('/', '');
check('官网首页照常', $s === 200 && strlen($raw) > 2000, "HTTP $s");

echo "\n== 12. 前端两页与产物 ==\n";
$aj = (string)file_get_contents($ROOT . '/public/static/js/app.jsx');
check('app.jsx 里有「论坛对接」这一格', str_contains($aj, "['forumapi', '论坛对接', '__super__']"), '');
check('app.jsx 走的是这两条管理端接口',
    str_contains($aj, "'/api/admin/forum-api'") && str_contains($aj, "'/api/admin/forum-api/toggle'"), '');
check('管理端页面上写着「不另发一枚 Key」', str_contains($aj, '不另发一枚'), '');
check('管理端页面上写着这把 Key 也能读约战', str_contains($aj, '也能读本队的约战接口'), '');
check('管理端把「认人请用 uid」这条也写在界面上', str_contains($aj, '打码那串同队会撞'), '');
$pj = (string)file_get_contents($ROOT . '/public/static/js/platform.jsx');
check('platform.jsx 挂了这一页与两条接口',
    str_contains($pj, "['forumapi', '论坛对接']") && str_contains($pj, '/api/platform/admin/forum-api/master')
    && str_contains($pj, "<PlatForumApi />"), '');
// 这一条是浏览器里量出来的：总闸关下去之后服务端改了，但界面只把开关那一格抹掉，
// 汇总还挂着「正在给读数：1」、每一行还写着「正在给读数」——同一屏自相矛盾。
// 所以 setMaster 成功之后必须重取一次（setOne 那条一开始就是这么写的）。
$setMaster = (string)preg_replace('/^.*?const setMaster/s', '', $pj);
$setMaster = (string)preg_replace('/const setOne.*$/s', '', $setMaster);
check('平台总闸改完会重取一次（开关那一格与下面每行同一个口径）',
    str_contains($setMaster, 'load();'), trim(mb_substr($setMaster, 0, 120)));
$js = (string)file_get_contents($ROOT . '/public/static/js/app.js');
check('编译产物 app.js 里有这一页', str_contains($js, 'AdminForumApi') && str_contains($js, '论坛对接'), '');
$pjs = (string)file_get_contents($ROOT . '/public/static/js/platform.js');
check('编译产物 platform.js 里有那一页', str_contains($pjs, 'PlatForumApi'), '');
$chk = (string)shell_exec('node ' . escapeshellarg($ROOT . '/scripts/check_jsx.js') . ' 2>&1');
check('check_jsx 两份 jsx 都过', str_contains($chk, 'OK') && !str_contains($chk, 'FAIL'), trim($chk));
$bld = (string)shell_exec('node ' . escapeshellarg($ROOT . '/scripts/build_js.js') . ' --check 2>&1');
check('预编译产物与源码逐字节一致（认「产物核对通过」这句）', str_contains($bld, '产物核对通过'), trim($bld));

echo "\n================ 结果 ================\n";
echo "通过 $pass 项";
if ($fail > 0) {
    echo "，失败 $fail 项：\n  - " . implode("\n  - ", $failures) . "\n";
    exit(1);
}
echo "，全部通过\n";
exit(0);
