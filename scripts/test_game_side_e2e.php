<?php
/**
 * 端手分离 + 战绩排行（v5.36.0）功能 E2E
 *
 * 前置：测试服务器已启动，且 .env 指向 sqlite
 *   php -S 127.0.0.1:8899 -t public public/router.php
 * 用法：php scripts/test_game_side_e2e.php
 *
 * 覆盖：
 *   1. 默认关闭：不看端别列、旧内容照旧、不插 settings 默认行
 *   2. 只有队长能开与改模块；成员必须选端游或手游（没有「不分类」），
 *      有人没选时开不了总开关；新成员审批时也要选端
 *   3. 两种限制：按端默认 + 按人例外
 *   4. 八块内容各自分端：公告/撞车/自建房/活动/训练赛/投票/小队/值班
 *   5. 成员端越权：另一端的详情/已读/报名/投票/入队都进不去
 *   6. 赛前提醒跟随事件端别，个人通知只给本端的人
 *   7. 战绩排行：四个指标、只算公开、管理员看全部、跨队隔离
 *   8. 库：十列九索引 + 值班唯一键；四份 DDL 与增量脚本同步
 *   9. 前端：专区切换、八块设置页、两处排行、编译产物
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
function req(string $method, string $url, ?array $body, array &$cookies, array $headers = []): array {
    $hdr = "Content-Type: application/json\r\n" . cookie_hdr($cookies);
    foreach ($headers as $h) { $hdr .= $h . "\r\n"; }
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
function as_req(string $who, string $method, string $path, ?array $body = null, array $headers = []): array {
    global $BASE, $jars;
    $c = $jars[$who] ?? [];
    $r = req($method, $BASE . $path, $body, $c, $headers);
    $jars[$who] = $c;
    return $r;
}
/** 带专区请求头的调用（模拟前端顶栏切到哪一端） */
function gs_req(string $who, string $side, string $method, string $path, ?array $body = null): array {
    return as_req($who, $method, $path, $body, ['X-Game-Side: ' . $side]);
}
function login(string $who, string $slug, string $user, string $pwd): array {
    $path = $slug === '' ? '/api/platform/auth/login' : "/$slug/api/auth/login";
    return as_req($who, 'POST', $path, ['username' => $user, 'password' => $pwd]);
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
function q1(string $sql, array $bind = []) {
    $r = q($sql, $bind);
    return $r ? reset($r[0]) : null;
}
function titles(array $rows, string $key = 'title'): array {
    $out = [];
    foreach ($rows as $r) { $out[] = (string)($r[$key] ?? ''); }
    sort($out);
    return $out;
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

$mkTenant = function (string $slug, string $name, string $owner) use ($pdo, $now): int {
    $st = $pdo->prepare("INSERT INTO tenants (applicant_id, slug, name, status, admin_username, created_at, updated_at)
                         VALUES (1, ?, ?, 'approved', ?, ?, ?)");
    $st->execute([$slug, $name, $owner, $now, $now]);
    return (int)$pdo->lastInsertId();
};
$mkUser = function (int $tid, string $username, string $nick, string $role = 'user', string $status = 'approved',
    string $side = '', string $lock = '', string $perms = '', int $public = 1) use ($pdo, $now): int {
    $st = $pdo->prepare("INSERT INTO users (tenant_id, username, password, nickname, role, status, admin_perms,
                                           game_side, game_side_lock, show_public, stats_public, created_at, updated_at)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?)");
    $st->execute([$tid, $username, password_hash('pass123456', PASSWORD_DEFAULT), $nick, $role, $status,
        $perms, $side, $lock, $public, $now, $now]);
    return (int)$pdo->lastInsertId();
};
$mkStats = function (int $tid, int $uid, int $matches, float $win, float $score, float $kpm) use ($pdo, $now): void {
    $st = $pdo->prepare("INSERT INTO player_stats (tenant_id, user_id, matches, win_rate, avg_score, kills_per_match, created_at, updated_at)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $st->execute([$tid, $uid, $matches, $win, $score, $kpm, $now, $now]);
};

$T1 = $mkTenant('gs1', '端手分离主队', 'gs1boss');
$T2 = $mkTenant('gs2', '隔壁队', 'gs2boss');

$boss = $mkUser($T1, 'gs1boss', '一队队长', 'admin', 'approved', 'pc');
$sub  = $mkUser($T1, 'gs1sub', '一队副管', 'admin', 'approved', 'mobile', '', '["member_view","stats"]');
$p1   = $mkUser($T1, 'gs1p1', '端游甲', 'user', 'approved', 'pc');
$p2   = $mkUser($T1, 'gs1p2', '端游乙', 'user', 'approved', 'pc');
$m1   = $mkUser($T1, 'gs1m1', '手游甲', 'user', 'approved', 'mobile');
$m2   = $mkUser($T1, 'gs1m2', '手游乙', 'user', 'approved', 'mobile', '', '', 0);
$n1   = $mkUser($T1, 'gs1n1', '小七', 'user', 'approved', '');
$lp1  = $mkUser($T1, 'gs1lp1', '锁死端游', 'user', 'approved', 'pc', '1');
$fp1  = $mkUser($T1, 'gs1fp1', '端游例外', 'user', 'approved', 'pc', '0');
$pend = $mkUser($T1, 'gs1pend', '待审核端游', 'user', 'pending', 'pc');$boss2 = $mkUser($T2, 'gs2boss', '隔壁队长', 'admin', 'approved', 'mobile');
$u2    = $mkUser($T2, 'gs2u1', '隔壁成员', 'user', 'approved', 'mobile');

$mkStats($T1, $p1, 10, 50, 70, 5);
$mkStats($T1, $p2, 20, 60, 65, 4);
$mkStats($T1, $m1, 5, 80, 60, 6);
$mkStats($T1, $m2, 30, 90, 50, 3);
$mkStats($T1, $n1, 8, 40, 75, 2);
$mkStats($T1, $lp1, 12, 55, 68, 4.5);
$mkStats($T2, $u2, 99, 99, 99, 99);

check('队长登录', login('boss', 'gs1', 'gs1boss', 'pass123456')[1]['code'] === 0);
check('副管登录（member_view + stats）', login('sub', 'gs1', 'gs1sub', 'pass123456')[1]['code'] === 0);
check('端游成员登录', login('p1', 'gs1', 'gs1p1', 'pass123456')[1]['code'] === 0);
check('手游成员登录', login('m1', 'gs1', 'gs1m1', 'pass123456')[1]['code'] === 0);
check('不分类成员登录', login('n1', 'gs1', 'gs1n1', 'pass123456')[1]['code'] === 0);
check('锁死端游成员登录', login('lp1', 'gs1', 'gs1lp1', 'pass123456')[1]['code'] === 0);
check('按人允许看两端的成员登录', login('fp1', 'gs1', 'gs1fp1', 'pass123456')[1]['code'] === 0);
check('隔壁队长登录', login('boss2', 'gs2', 'gs2boss', 'pass123456')[1]['code'] === 0);

// ============================================================
// 1. 默认关闭：一切与升级前一样
// ============================================================
echo "\n== 1. 默认关闭 ==\n";
[$s, $j] = as_req('p1', 'GET', '/gs1/api/auth/me');
check('成员端拿到 game_side 状态（默认关）', $s === 200 && ($j['data']['game_side']['on'] ?? null) === false, J($j['data']['game_side'] ?? null));
check('关着时两端都能看（allowed 两项）', ($j['data']['game_side']['allowed'] ?? []) === ['pc', 'mobile'], J($j['data']['game_side']['allowed'] ?? null));
check('关着时八块都报 false（不按默认值算开着）', array_sum($j['data']['game_side']['modules'] ?? [1]) === 0, J($j['data']['game_side']['modules'] ?? null));

[$s, $j] = as_req('boss', 'GET', '/gs1/api/admin/game-side');
check('队长读端手设置：关闭、八个模块键、三类人数', $s === 200 && ($j['data']['on'] ?? null) === false
    && count($j['data']['labels'] ?? []) === 8, J($j['data'] ?? null));
check('人数：端游 5 / 手游 3 / 不分类 1（待审核不算）',
    (int)($j['data']['counts']['pc'] ?? -1) === 5 && (int)($j['data']['counts']['mobile'] ?? -1) === 3
    && (int)($j['data']['counts']['none'] ?? -1) === 1, J($j['data']['counts'] ?? null));

check('没有 side_split_on 这一行（默认关不靠插默认行）',
    (int)q1('SELECT COUNT(*) FROM settings WHERE tenant_id=? AND key IN (?,?,?,?)', [$T1, 'side_split_on', 'side_split_modules', 'side_lock_pc', 'side_lock_mobile']) === 0);

// 关着时端别一律不记：公告 body 里写 mobile，库里仍是空串
[$s, $j] = as_req('boss', 'POST', '/gs1/api/admin/announcements/save',
    ['title' => '开分离前的公告', 'content' => '旧数据', 'game_side' => 'mobile']);
check('关闭时发公告成功', $s === 200 && ($j['code'] ?? 1) === 0, J($j));
$A_old = (int)q1('SELECT id FROM announcements WHERE tenant_id=? AND title=?', [$T1, '开分离前的公告']);
check('关闭时 game_side 被忽略（落空串＝全队共享）', (string)q1('SELECT game_side FROM announcements WHERE id=?', [$A_old]) === '');

[$s, $j] = as_req('boss', 'POST', '/gs1/api/admin/races/save',
    ['title' => '开分离前的撞车', 'race_time' => date('Y-m-d H:i:s', strtotime('+1 day 12:00')), 'game_side' => 'pc']);
check('关闭时发撞车成功', $s === 200 && ($j['code'] ?? 1) === 0, J($j));
$R_old = (int)q1('SELECT id FROM race_events WHERE tenant_id=? AND title=?', [$T1, '开分离前的撞车']);
check('关闭时撞车端别也落空串', (string)q1('SELECT game_side FROM race_events WHERE id=?', [$R_old]) === '');

[$s, $j] = as_req('p1', 'GET', '/gs1/api/notices');
check('关闭时端游成员看得到那条公告', in_array('开分离前的公告', titles($j['data']['list'] ?? []), true), J($j['data']['list'] ?? null));

// ============================================================
// 2. 只有队长能开；成员分类走用户管理
// ============================================================
echo "\n== 2. 权限：开关只给队长，分类给成员名单权限 ==\n";
[$s, $j] = as_req('sub', 'GET', '/gs1/api/admin/game-side');
check('副管读不到端手设置（403）', $s === 403 || ($j['code'] ?? 0) !== 0, "HTTP $s");
[$s, $j] = as_req('sub', 'POST', '/gs1/api/admin/game-side/save', ['on' => 1]);
check('副管开不了总开关（403）', $s === 403 || ($j['code'] ?? 0) !== 0, "HTTP $s");
[$s, $j] = as_req('p1', 'GET', '/gs1/api/admin/game-side');
check('普通成员连管理端都进不来', $s === 403 || $s === 401 || ($j['code'] ?? 0) !== 0, "HTTP $s");

[$s, $j] = as_req('boss', 'POST', '/gs1/api/admin/game-side/save', ['on' => 1]);
check('还有成员没分端时总开关打不开（强制先选端）', $s === 400 || ($j['code'] ?? 0) !== 0, J($j));
check('挡下来时说清还有几个人、该去哪里分', str_contains((string)($j['msg'] ?? ''), '没选端游/手游'), (string)($j['msg'] ?? ''));
check('被挡时开关没有写进 settings', (int)q1('SELECT COUNT(*) FROM settings WHERE tenant_id=? AND key=?', [$T1, 'side_split_on']) === 0);

// 用户管理按端别筛（此时 n1 还是未分类）
[$s, $j] = as_req('sub', 'GET', '/gs1/api/admin/users?game_side=mobile&size=100');
$names = titles($j['data']['list'] ?? [], 'nickname');
check('用户管理按手游筛只出手游的人', in_array('手游甲', $names, true) && !in_array('端游甲', $names, true), J($names));
[$s, $j] = as_req('sub', 'GET', '/gs1/api/admin/users?game_side=none&size=100');
check('按「未分类」筛可以找到待分的人', in_array('小七', titles($j['data']['list'] ?? [], 'nickname'), true), J($j['data']['list'] ?? null));

[$s, $j] = as_req('sub', 'POST', '/gs1/api/admin/users/' . $n1 . '/game-side', ['game_side' => '', 'lock' => '']);
check('未分类不能被保存为空（必须选端游或手游）', $s === 400 || ($j['code'] ?? 0) !== 0, J($j));
check('空值没有改动库里那一行', (string)q1('SELECT game_side FROM users WHERE id=?', [$n1]) === '');
[$s, $j] = as_req('sub', 'POST', '/gs1/api/admin/users/' . $n1 . '/game-side', ['game_side' => 'mobile', 'lock' => '']);
check('选成手游才存得下去', $s === 200 && ($j['code'] ?? 1) === 0 && (string)q1('SELECT game_side FROM users WHERE id=?', [$n1]) === 'mobile', J($j));

[$s, $j] = as_req('sub', 'POST', '/gs1/api/admin/users/' . $p2 . '/game-side', ['game_side' => 'mobile', 'lock' => '']);
check('持成员名单权限的副管能改端别', $s === 200 && ($j['code'] ?? 1) === 0, J($j));
check('库里端别真的变了', (string)q1('SELECT game_side FROM users WHERE id=?', [$p2]) === 'mobile');
check('后台记了一行操作日志', (int)q1("SELECT COUNT(*) FROM admin_logs WHERE tenant_id=? AND action='端手游分类'", [$T1]) >= 1);
[$s, $j] = as_req('sub', 'POST', '/gs1/api/admin/users/' . $p2 . '/game-side', ['game_side' => 'pc', 'lock' => '']);
check('改回端游并落库', $s === 200 && ($j['code'] ?? 1) === 0 && (string)q1('SELECT game_side FROM users WHERE id=?', [$p2]) === 'pc');
[$s, $j] = as_req('sub', 'POST', '/gs1/api/admin/users/' . $p2 . '/game-side', ['game_side' => 'pc', 'lock' => '2']);
check('非法跨端取值被拒', $s === 400 || ($j['code'] ?? 0) !== 0, J($j));
[$s, $j] = as_req('sub', 'POST', '/gs1/api/admin/users/' . $fp1 . '/game-side', ['game_side' => 'pc', 'lock' => '1']);
check('能按人设为「只能看本端」', $s === 200 && ($j['code'] ?? 1) === 0
    && (string)q1('SELECT game_side_lock FROM users WHERE id=?', [$fp1]) === '1', J($j));
[$s, $j] = as_req('sub', 'POST', '/gs1/api/admin/users/' . $fp1 . '/game-side', ['game_side' => 'pc', 'lock' => '0']);
check('再把它设回「允许看两端」', $s === 200 && (string)q1('SELECT game_side_lock FROM users WHERE id=?', [$fp1]) === '0', J($j));
[$s, $j] = as_req('p1', 'POST', '/gs1/api/admin/users/' . $p1 . '/game-side', ['game_side' => 'pc']);
check('普通成员不能给自己分类', $s === 403 || $s === 401 || ($j['code'] ?? 0) !== 0, "HTTP $s");

// 全员都选好端了，再开总开关
[$s, $j] = as_req('boss', 'POST', '/gs1/api/admin/game-side/save', ['on' => 1]);
check('全员分好端后队长能打开总开关', $s === 200 && ($j['code'] ?? 1) === 0 && ($j['data']['on'] ?? null) === true, J($j));
[$s, $j] = as_req('p1', 'GET', '/gs1/api/auth/me');
$gsd = $j['data']['game_side'] ?? [];
check('成员刷新后拿到开着的状态与八块默认全分端', ($gsd['on'] ?? null) === true && array_sum($gsd['modules'] ?? []) === 8, J($gsd));
check('开关写进了本队 settings', (string)q1('SELECT value FROM settings WHERE tenant_id=? AND key=?', [$T1, 'side_split_on']) === '1');
[$s, $j] = as_req('boss2', 'GET', '/gs2/api/auth/me');
check('隔壁队没被带着打开（按队隔离）', ($j['data']['game_side']['on'] ?? null) === false, J($j['data']['game_side'] ?? null));
[$s, $j] = as_req('boss', 'GET', '/gs1/api/admin/game-side');
check('分好端后页面上未分类归零', (int)($j['data']['counts']['none'] ?? -1) === 0
    && (int)($j['data']['counts']['pc'] ?? -1) === 5 && (int)($j['data']['counts']['mobile'] ?? -1) === 4, J($j['data']['counts'] ?? null));

// 分端开着时，新成员审批必须先选端
[$s, $j] = as_req('boss', 'POST', '/gs1/api/admin/review/' . $pend, ['action' => 'approve']);
check('分端时审批不选端不给过', $s === 400 || ($j['code'] ?? 0) !== 0, J($j));
check('待审账号还没有变成在职', (string)q1('SELECT status FROM users WHERE id=?', [$pend]) === 'pending');
[$s, $j] = as_req('boss', 'POST', '/gs1/api/admin/review/' . $pend, ['action' => 'approve', 'game_side' => 'mobile']);
check('审批时选好端就能通过并落库', $s === 200 && ($j['code'] ?? 1) === 0
    && (string)q1('SELECT status FROM users WHERE id=?', [$pend]) === 'approved'
    && (string)q1('SELECT game_side FROM users WHERE id=?', [$pend]) === 'mobile', J($j));

// ============================================================
// 3. 两种限制：按端默认 + 按人例外
// ============================================================
echo "\n== 3. 跨端可见 ==\n";
$allowed = function (string $who): array {
    [, $j] = as_req($who, 'GET', '/gs1/api/auth/me');
    return $j['data']['game_side']['allowed'] ?? [];
};
check('默认两端都能看：端游成员', $allowed('p1') === ['pc', 'mobile'], J($allowed('p1')));
check('按人「只能看本端」不用等全队锁也生效', $allowed('lp1') === ['pc'], J($allowed('lp1')));
check('默认两端都能看：手游成员（分好端的小七）', $allowed('n1') === ['pc', 'mobile'], J($allowed('n1')));

[$s, $j] = as_req('boss', 'POST', '/gs1/api/admin/game-side/save', ['lock_pc' => 1]);
check('打开「只限端游，不能看手游」', $s === 200 && ($j['data']['locks']['pc'] ?? false) === true, J($j));
check('端游普通成员被限制到端游', $allowed('p1') === ['pc'], J($allowed('p1')));
check('手游成员不受端游那把锁影响', $allowed('m1') === ['pc', 'mobile'], J($allowed('m1')));
check('按人「允许看两端」的端游成员仍然两端', $allowed('fp1') === ['pc', 'mobile'], J($allowed('fp1')));
check('手游成员在端游锁打开时仍然两端（另一把锁才管他）', $allowed('n1') === ['pc', 'mobile'], J($allowed('n1')));

[$s, $j] = as_req('boss', 'POST', '/gs1/api/admin/game-side/save', ['lock_mobile' => 1]);
check('打开「只限手游，不能看端游」', $s === 200 && ($j['data']['locks']['mobile'] ?? false) === true, J($j));
check('手游普通成员被限制到手游', $allowed('m1') === ['mobile'], J($allowed('m1')));
check('端游被上一把锁限制后仍只有端游', $allowed('p1') === ['pc'], J($allowed('p1')));
check('副管（手游）也只剩手游', $allowed('sub') === ['mobile'], J($allowed('sub')));

// 关回去，后面内容测试要能自由切换专区
as_req('boss', 'POST', '/gs1/api/admin/game-side/save', ['lock_pc' => 0]);
as_req('boss', 'POST', '/gs1/api/admin/game-side/save', ['lock_mobile' => 0]);
check('两把按端锁关掉后恢复两端（按人例外还留着）',
    $allowed('p1') === ['pc', 'mobile'] && $allowed('lp1') === ['pc'] && $allowed('fp1') === ['pc', 'mobile'],
    J([$allowed('p1'), $allowed('lp1'), $allowed('fp1')]));

// ============================================================
// 4. 八块内容各自分端
// ============================================================
echo "\n== 4. 八块内容分端 ==\n";

// ---------- 4.1 公告 ----------
echo "-- 公告 --\n";
$mkAnn = function (string $title, string $side): int {
    [, $j] = as_req('boss', 'POST', '/gs1/api/admin/announcements/save',
        ['title' => $title, 'content' => '正文 ' . $title, 'game_side' => $side, 'need_receipt' => $side === 'pc' ? 1 : 0]);
    return (int)($j['data']['id'] ?? 0);
};
$A_pc = $mkAnn('端游公告甲', 'pc');
$A_mo = $mkAnn('手游公告乙', 'mobile');
$A_all = $mkAnn('全队公告丙', '');
check('三条公告都建出来了，端别落库正确',
    $A_pc > 0 && $A_mo > 0 && $A_all > 0
    && (string)q1('SELECT game_side FROM announcements WHERE id=?', [$A_pc]) === 'pc'
    && (string)q1('SELECT game_side FROM announcements WHERE id=?', [$A_mo]) === 'mobile'
    && (string)q1('SELECT game_side FROM announcements WHERE id=?', [$A_all]) === '');

[, $j] = as_req('p1', 'GET', '/gs1/api/notices');
$t = titles($j['data']['list'] ?? []);
check('端游成员只看到端游 + 共享（看不到手游专属）', in_array('端游公告甲', $t, true) && in_array('全队公告丙', $t, true)
    && !in_array('手游公告乙', $t, true), J($t));
[, $j] = as_req('m1', 'GET', '/gs1/api/notices');
$t = titles($j['data']['list'] ?? []);
check('手游成员只看到手游 + 共享', in_array('手游公告乙', $t, true) && !in_array('端游公告甲', $t, true), J($t));
[, $j] = as_req('n1', 'GET', '/gs1/api/notices');
$t = titles($j['data']['list'] ?? []);
check('小七（手游）默认落在手游专区：看到手游 + 共享', in_array('手游公告乙', $t, true) && !in_array('端游公告甲', $t, true), J($t));
[, $j] = gs_req('n1', 'pc', 'GET', '/gs1/api/notices');
$t = titles($j['data']['list'] ?? []);
check('小七切到端游专区：看到端游 + 共享', in_array('端游公告甲', $t, true) && !in_array('手游公告乙', $t, true), J($t));
[, $j] = gs_req('p1', 'mobile', 'GET', '/gs1/api/notices');
$t = titles($j['data']['list'] ?? []);
check('成员自己切到手游专区就看不到端游专属', in_array('手游公告乙', $t, true) && !in_array('端游公告甲', $t, true), J($t));

[$s, $j] = as_req('p1', 'POST', '/gs1/api/notices/' . $A_mo . '/read');
check('另一端的公告点不进去（404，不是已读成功）', $s === 404 || ($j['code'] ?? 0) !== 0, "HTTP $s");
check('另一端的公告也没被标已读', (int)q1('SELECT COUNT(*) FROM announcement_reads WHERE announcement_id=? AND user_id=?', [$A_mo, $p1]) === 0);

[$s, $j] = gs_req('boss', 'pc', 'GET', '/gs1/api/admin/announcements/' . $A_pc . '/receipt');
check('回执只数端游的人（5 人）', $s === 200 && (int)($j['data']['total'] ?? -1) === 5, J($j['data']['total'] ?? null));
$rraw = J($j['data'] ?? []);
check('回执名单里没有手游的人', !str_contains($rraw, '手游甲') && !str_contains($rraw, '手游乙') && !str_contains($rraw, '一队副管'), $rraw);

[$s, $j] = gs_req('boss', 'pc', 'GET', '/gs1/api/admin/announcements');
$t = titles($j['data']['list'] ?? []);
check('管理端在端游专区只列端游与共享', in_array('端游公告甲', $t, true) && in_array('全队公告丙', $t, true)
    && !in_array('手游公告乙', $t, true), J($t));
[$s, $j] = gs_req('boss', 'mobile', 'GET', '/gs1/api/admin/announcements');
$t = titles($j['data']['list'] ?? []);
check('管理端切到手游专区只列手游与共享', in_array('手游公告乙', $t, true) && !in_array('端游公告甲', $t, true), J($t));

// ---------- 4.2 撞车 ----------
echo "-- 撞车日程 --\n";
$mkRace = function (string $title, string $side) use (&$R): int {
    [, $j] = gs_req('boss', $side === 'mobile' ? 'mobile' : 'pc', 'POST', '/gs1/api/admin/races/save',
        ['title' => $title, 'race_time' => date('Y-m-d H:i:s', strtotime('+1 day 12:30')), 'game_side' => $side]);
    return (int)($j['data']['id'] ?? 0);
};
$R_pc = $mkRace('端游撞车', 'pc');
$R_mo = $mkRace('手游撞车', 'mobile');
$R_all = $mkRace('全队撞车', '');
check('三条撞车建出来且端别正确',
    $R_pc > 0 && $R_mo > 0 && $R_all > 0
    && (string)q1('SELECT game_side FROM race_events WHERE id=?', [$R_pc]) === 'pc'
    && (string)q1('SELECT game_side FROM race_events WHERE id=?', [$R_mo]) === 'mobile');

[, $j] = as_req('p1', 'GET', '/gs1/api/races');
$t = titles($j['data']['list'] ?? []);
check('端游成员看到端游 + 共享撞车', in_array('端游撞车', $t, true) && in_array('全队撞车', $t, true)
    && !in_array('手游撞车', $t, true), J($t));
[, $j] = as_req('m1', 'GET', '/gs1/api/races');
$t = titles($j['data']['list'] ?? []);
check('手游成员看到手游 + 共享撞车', in_array('手游撞车', $t, true) && !in_array('端游撞车', $t, true), J($t));
[, $j] = gs_req('n1', 'pc', 'GET', '/gs1/api/races');
check('小七切到端游：三条（端游 + 两条共享）', count($j['data']['list'] ?? []) === 3
    && in_array('端游撞车', titles($j['data']['list'] ?? []), true), J(titles($j['data']['list'] ?? [])));
[, $j] = as_req('n1', 'GET', '/gs1/api/races');
check('小七默认手游：三条（手游 + 两条共享）', count($j['data']['list'] ?? []) === 3
    && in_array('手游撞车', titles($j['data']['list'] ?? []), true), J(titles($j['data']['list'] ?? [])));
[$s, $j] = gs_req('boss', 'pc', 'GET', '/gs1/api/admin/races');
$t = titles($j['data']['list'] ?? []);
check('管理端端游专区只列端游与共享', in_array('端游撞车', $t, true) && !in_array('手游撞车', $t, true), J($t));
[$s, $j] = gs_req('boss', 'pc', 'POST', '/gs1/api/admin/races/save',
    ['title' => '第四场端游撞车', 'race_time' => date('Y-m-d H:i:s', strtotime('+1 day 13:00')), 'game_side' => 'pc']);
check('每端各 3 场的上限按专区算（端游满了就拒）', $s === 400 || ($j['code'] ?? 0) !== 0, J($j));

// ---------- 4.3 自建房 ----------
echo "-- 自建房日程 --\n";
$mkCR = function (string $title, string $side) use (&$C): int {
    [, $j] = gs_req('boss', $side === 'mobile' ? 'mobile' : 'pc', 'POST', '/gs1/api/admin/custom-races/save',
        ['title' => $title, 'race_time' => date('Y-m-d H:i:s', strtotime('+1 day 14:00')), 'game_side' => $side]);
    return (int)($j['data']['id'] ?? 0);
};
$C_pc = $mkCR('端游自建房', 'pc');
$C_mo = $mkCR('手游自建房', 'mobile');
$C_all = $mkCR('全队自建房', '');
check('三条自建房建出来且端别正确', $C_pc > 0 && $C_mo > 0 && $C_all > 0
    && (string)q1('SELECT game_side FROM custom_race_events WHERE id=?', [$C_mo]) === 'mobile');
[, $j] = as_req('p1', 'GET', '/gs1/api/custom-races');
$t = titles($j['data']['list'] ?? []);
check('端游成员只看到端游 + 共享自建房', in_array('端游自建房', $t, true) && !in_array('手游自建房', $t, true), J($t));
[, $j] = as_req('m1', 'GET', '/gs1/api/custom-races');
$t = titles($j['data']['list'] ?? []);
check('手游成员只看到手游 + 共享自建房', in_array('手游自建房', $t, true) && !in_array('端游自建房', $t, true), J($t));

// ---------- 4.4 日常活动 ----------
echo "-- 日常活动 --\n";
$mkEv = function (string $title, string $side) use ($T1): int {
    [, $j] = as_req('boss', 'POST', '/gs1/api/admin/schedule/events/save',
        ['title' => $title, 'start_time' => date('Y-m-d H:i:s', time() + 7200), 'duration_min' => 60,
         'note' => '', 'game_side' => $side]);
    return (int)q1('SELECT id FROM custom_events WHERE tenant_id=? AND title=?', [$T1, $title]);
};
$mkEv('端游活动', 'pc');
$mkEv('手游活动', 'mobile');
$mkEv('全队活动', '');
check('三条活动建出来且端别正确',
    (string)q1('SELECT game_side FROM custom_events WHERE tenant_id=? AND title=?', [$T1, '端游活动']) === 'pc'
    && (string)q1('SELECT game_side FROM custom_events WHERE tenant_id=? AND title=?', [$T1, '手游活动']) === 'mobile');

$eventTitles = function (string $who) {
    [, $j] = as_req($who, 'GET', '/gs1/api/schedule?scope=week');
    $out = [];
    foreach (($j['data']['items'] ?? []) as $it) {
        if (($it['type'] ?? '') === 'custom') { $out[] = (string)$it['title']; }
    }
    sort($out);
    return $out;
};
$t = $eventTitles('p1');
check('端游成员的日程里只有端游 + 共享活动', in_array('端游活动', $t, true) && in_array('全队活动', $t, true)
    && !in_array('手游活动', $t, true), J($t));
$t = $eventTitles('m1');
check('手游成员的日程里只有手游 + 共享活动', in_array('手游活动', $t, true) && !in_array('端游活动', $t, true), J($t));
[$s, $j] = gs_req('boss', 'mobile', 'GET', '/gs1/api/admin/schedule/events');
$t = titles($j['data']['list'] ?? []);
check('管理端手游专区只列手游与共享活动', in_array('手游活动', $t, true) && !in_array('端游活动', $t, true), J($t));

// ---------- 4.5 小队 ----------
echo "-- 战队小队 --\n";
$mkTeam = function (string $name, string $side, int $leader = 0) use (&$TM): array {
    $r = as_req('boss', 'POST', '/gs1/api/admin/teams/save',
        ['name' => $name, 'description' => '', 'leader_id' => $leader, 'game_side' => $side]);
    return $r;
};
$mkTeam('端游小队', 'pc');
$mkTeam('手游小队', 'mobile');
$mkTeam('共享小队', '');
$TM_pc = (int)q1('SELECT id FROM teams WHERE tenant_id=? AND name=?', [$T1, '端游小队']);
$TM_mo = (int)q1('SELECT id FROM teams WHERE tenant_id=? AND name=?', [$T1, '手游小队']);
check('三条小队建出来且端别正确', $TM_pc > 0 && $TM_mo > 0
    && (string)q1('SELECT game_side FROM teams WHERE id=?', [$TM_pc]) === 'pc'
    && (string)q1('SELECT game_side FROM teams WHERE id=?', [$TM_mo]) === 'mobile');
check('「全队共享」小队落空串', (string)q1('SELECT game_side FROM teams WHERE tenant_id=? AND name=?', [$T1, '共享小队']) === '');

[, $j] = as_req('p1', 'GET', '/gs1/api/teams');
$t = titles($j['data']['list'] ?? [], 'name');
check('端游成员只看到端游 + 共享小队', in_array('端游小队', $t, true) && in_array('共享小队', $t, true)
    && !in_array('手游小队', $t, true), J($t));
[$s, $j] = as_req('p1', 'POST', '/gs1/api/teams/join', ['team_id' => $TM_mo]);
check('端游成员加不了手游小队', $s === 400 || ($j['code'] ?? 0) !== 0, J($j));
[$s, $j] = as_req('p1', 'POST', '/gs1/api/teams/join', ['team_id' => $TM_pc]);
check('端游成员能加端游小队', $s === 200 && ($j['code'] ?? 1) === 0, J($j));
[$s, $j] = as_req('m1', 'POST', '/gs1/api/teams/join', ['team_id' => $TM_pc]);
check('手游成员加不了端游小队', $s === 400 || ($j['code'] ?? 0) !== 0, J($j));
[$s, $j] = as_req('m1', 'POST', '/gs1/api/teams/join', ['team_id' => $TM_mo]);
check('手游成员能加手游小队', $s === 200 && ($j['code'] ?? 1) === 0, J($j));
[$s, $j] = as_req('n1', 'POST', '/gs1/api/teams/join', ['team_id' => $TM_mo]);
check('小七（手游）能加手游小队', $s === 200 && ($j['code'] ?? 1) === 0, J($j));
[$s, $j] = gs_req('n1', 'pc', 'POST', '/gs1/api/teams/join', ['team_id' => $TM_pc]);
check('分好手游后，切到端游专区也加不了端游小队', $s === 400 || ($j['code'] ?? 0) !== 0, J($j));
[$s, $j] = as_req('boss', 'POST', '/gs1/api/admin/teams/save',
    ['name' => '端游队长错配', 'description' => '', 'leader_id' => $m1, 'game_side' => 'pc']);
check('队长端别与小队不一致时拒绝', $s === 400 || ($j['code'] ?? 0) !== 0, J($j));
[$s, $j] = as_req('boss', 'POST', '/gs1/api/admin/teams/assign', ['user_id' => $m1, 'team_id' => $TM_pc]);
check('管理员也不能把跨端的人硬塞进小队', $s === 400 || ($j['code'] ?? 0) !== 0, J($j));
[$s, $j] = gs_req('boss', 'pc', 'GET', '/gs1/api/admin/teams');
$t = titles($j['data']['list'] ?? [], 'name');
check('管理端端游专区只列端游与共享小队', in_array('端游小队', $t, true) && !in_array('手游小队', $t, true), J($t));

// ---------- 4.6 投票 ----------
echo "-- 投票 --\n";
$mkVote = function (string $title, string $side) use (&$V): int {
    [, $j] = as_req('boss', 'POST', '/gs1/api/admin/votes/create',
        ['title' => $title, 'options' => ['赞成', '反对'], 'multi' => 0, 'max_choices' => 1,
         'anonymous' => 0, 'allow_add_option' => 0,
         'deadline' => date('Y-m-d H:i:s', strtotime('+2 days')), 'game_side' => $side]);
    return (int)($j['data']['id'] ?? 0);
};
$V_pc = $mkVote('端游投票', 'pc');
$V_mo = $mkVote('手游投票', 'mobile');
check('两条投票建出来且端别正确', $V_pc > 0 && $V_mo > 0
    && (string)q1('SELECT game_side FROM votes WHERE id=?', [$V_mo]) === 'mobile');
$voteTitles = function (string $who) {
    [, $j] = as_req($who, 'GET', '/gs1/api/votes');
    $out = array_merge($j['data']['open'] ?? [], $j['data']['closed'] ?? []);
    return titles($out);
};
$t = $voteTitles('p1');
check('端游成员只看到端游投票', in_array('端游投票', $t, true) && !in_array('手游投票', $t, true), J($t));
$t = $voteTitles('m1');
check('手游成员只看到手游投票', in_array('手游投票', $t, true) && !in_array('端游投票', $t, true), J($t));
[$s, $j] = as_req('p1', 'GET', '/gs1/api/votes/' . $V_mo);
check('端游成员打开手游投票详情 404', $s === 404 || ($j['code'] ?? 0) !== 0, "HTTP $s");
[, $j] = as_req('p1', 'GET', '/gs1/api/votes/' . $V_pc);
$optPc = (int)($j['data']['options'][0]['id'] ?? 0);
[$s, $j] = as_req('p1', 'POST', '/gs1/api/votes/' . $V_pc . '/cast', ['option_ids' => [$optPc]]);
check('端游成员能给端游投票', $s === 200 && ($j['code'] ?? 1) === 0 && $optPc > 0, J($j));
[$s, $j] = as_req('p1', 'POST', '/gs1/api/votes/' . $V_mo . '/cast', ['option_ids' => [1]]);
check('端游成员投不了手游的票', $s === 404 || ($j['code'] ?? 0) !== 0, "HTTP $s");
[, $j] = as_req('n1', 'GET', '/gs1/api/votes/' . $V_mo);
$optMo = (int)($j['data']['options'][0]['id'] ?? 0);
[$s, $j] = as_req('n1', 'POST', '/gs1/api/votes/' . $V_mo . '/cast', ['option_ids' => [$optMo]]);
check('小七（手游）能投手游的票', $s === 200 && ($j['code'] ?? 1) === 0, J($j));

// ---------- 4.7 训练赛 ----------
echo "-- 队内训练赛 --\n";
$mkTraining = function (string $title, string $side, string $mode = 'free', int $a = 0, int $b = 0) use (&$TR): array {
    $body = ['title' => $title, 'mode' => $mode, 'match_time' => date('Y-m-d H:i:s', strtotime('+2 days')),
             'series_format' => 'bo1', 'map_mode' => 'random', 'game_side' => $side];
    if ($mode === 'free') { $body['signup_deadline'] = date('Y-m-d H:i:s', strtotime('+1 day')); }
    else { $body['team_a_id'] = $a; $body['team_b_id'] = $b; }
    $r = as_req('boss', 'POST', '/gs1/api/trainings/create', $body);
    return $r;
};
$tr = $mkTraining('端游训练赛', 'pc');
$TR_pc = (int)($tr[1]['data']['id'] ?? 0);
$tr = $mkTraining('手游训练赛', 'mobile');
$TR_mo = (int)($tr[1]['data']['id'] ?? 0);
check('两场训练赛建出来且端别正确', $TR_pc > 0 && $TR_mo > 0
    && (string)q1('SELECT game_side FROM training_matches WHERE id=?', [$TR_pc]) === 'pc'
    && (string)q1('SELECT game_side FROM training_matches WHERE id=?', [$TR_mo]) === 'mobile');
$trainingTitles = function (string $who) {
    [, $j] = as_req($who, 'GET', '/gs1/api/trainings');
    return titles($j['data']['list'] ?? []);
};
$t = $trainingTitles('p1');
check('端游成员只看到端游训练赛', in_array('端游训练赛', $t, true) && !in_array('手游训练赛', $t, true), J($t));
$t = $trainingTitles('m1');
check('手游成员只看到手游训练赛', in_array('手游训练赛', $t, true) && !in_array('端游训练赛', $t, true), J($t));
[$s, $j] = as_req('p1', 'GET', '/gs1/api/trainings/' . $TR_mo);
check('端游成员打开手游训练赛 404', $s === 404 || ($j['code'] ?? 0) !== 0, "HTTP $s");
[$s, $j] = as_req('p1', 'POST', '/gs1/api/trainings/' . $TR_pc . '/signup', ['action' => 'join']);
check('端游成员能报名端游训练赛', $s === 200 && ($j['code'] ?? 1) === 0, J($j));
[$s, $j] = as_req('p1', 'POST', '/gs1/api/trainings/' . $TR_mo . '/signup', ['action' => 'join']);
check('端游成员报不了手游训练赛', $s === 404 || ($j['code'] ?? 0) !== 0, "HTTP $s");
[$s, $j] = as_req('m1', 'POST', '/gs1/api/trainings/' . $TR_mo . '/signup', ['action' => 'join']);
check('手游成员能报名手游训练赛', $s === 200 && ($j['code'] ?? 1) === 0, J($j));
[$s, $j] = gs_req('n1', 'pc', 'POST', '/gs1/api/trainings/' . $TR_pc . '/signup', ['action' => 'join']);
check('分好手游后，切到端游专区也报不了端游训练赛', $s === 400 || ($j['code'] ?? 0) !== 0, J($j));
[$s, $j] = as_req('n1', 'POST', '/gs1/api/trainings/' . $TR_mo . '/signup', ['action' => 'join']);
check('小七（手游）能报名手游训练赛', $s === 200 && ($j['code'] ?? 1) === 0, J($j));
$tr = $mkTraining('跨端小队对抗', 'pc', 'squad', $TM_pc, $TM_mo);
check('分端训练赛不能拉另一端的小队', ($tr[1]['code'] ?? 0) !== 0, J($tr[1]));
$tr = $mkTraining('端游小队对抗', 'pc', 'squad', $TM_pc, (int)q1('SELECT id FROM teams WHERE tenant_id=? AND name=?', [$T1, '共享小队']));
check('同端与共享小队可以打对抗', ($tr[1]['code'] ?? 1) === 0, J($tr[1]));

// ---------- 4.8 值班表 ----------
echo "-- 值班表 --\n";
[$s, $j] = gs_req('boss', 'pc', 'POST', '/gs1/api/admin/duty/config',
    ['cycle' => 'day', 'anchor_date' => date('Y-m-d'), 'enabled' => 1]);
check('端游专区保存值班配置', $s === 200 && ($j['code'] ?? 1) === 0, J($j));
[$s, $j] = gs_req('boss', 'pc', 'POST', '/gs1/api/admin/duty/members', ['user_ids' => [$p1, $fp1]]);
check('端游轮值名单只能是端游成员', $s === 200 && ($j['code'] ?? 1) === 0, J($j));
[$s, $j] = gs_req('boss', 'pc', 'POST', '/gs1/api/admin/duty/members', ['user_ids' => [$p1, $m1]]);
check('端游名单里塞手游成员被拒', $s === 400 || ($j['code'] ?? 0) !== 0, J($j));
[$s, $j] = gs_req('boss', 'mobile', 'POST', '/gs1/api/admin/duty/config',
    ['cycle' => 'day', 'anchor_date' => date('Y-m-d'), 'enabled' => 1]);
check('手游专区有自己的一份值班配置', $s === 200 && ($j['code'] ?? 1) === 0, J($j));
[$s, $j] = gs_req('boss', 'mobile', 'POST', '/gs1/api/admin/duty/members', ['user_ids' => [$m1, $n1]]);
check('手游轮值名单只能是手游成员', $s === 200 && ($j['code'] ?? 1) === 0, J($j));
check('库里真有两份配置（端游 / 手游各一行）',
    (int)q1("SELECT COUNT(*) FROM duty_configs WHERE tenant_id=? AND duty_type='room_host'", [$T1]) === 2);

[, $j] = as_req('p1', 'GET', '/gs1/api/duty/today');
check('端游成员看到端游那份值班（今天是端游甲）', ($j['data']['zone'] ?? '') === 'pc'
    && (int)($j['data']['duty']['user_id'] ?? 0) === $p1, J($j['data'] ?? null));
[, $j] = as_req('m1', 'GET', '/gs1/api/duty/today');
check('手游成员看到手游那份值班（今天是手游甲）', ($j['data']['zone'] ?? '') === 'mobile'
    && (int)($j['data']['duty']['user_id'] ?? 0) === $m1, J($j['data'] ?? null));
[, $j] = as_req('n1', 'GET', '/gs1/api/duty/today');
check('小七（手游）看的是手游那一份', ($j['data']['zone'] ?? '') === 'mobile', J($j['data'] ?? null));
$dutyItemOf = function (string $who) {
    [, $j] = as_req($who, 'GET', '/gs1/api/schedule?scope=day');
    foreach (($j['data']['items'] ?? []) as $it) {
        if (($it['type'] ?? '') === 'duty') { return $it; }
    }
    return null;
};
check('日程里的值班条目带端别（端游）', (string)($dutyItemOf('p1')['game_side'] ?? '') === 'pc', J($dutyItemOf('p1')));
check('日程里的值班条目带端别（手游）', (string)($dutyItemOf('m1')['game_side'] ?? '') === 'mobile', J($dutyItemOf('m1')));
[$s, $j] = as_req('p1', 'POST', '/gs1/api/duty/swaps/request', ['date' => date('Y-m-d'), 'to_uid' => $m1]);
check('换班不能换到另一端的人', $s === 400 || ($j['code'] ?? 0) !== 0, J($j));
[$s, $j] = as_req('p1', 'POST', '/gs1/api/duty/swaps/request', ['date' => date('Y-m-d'), 'to_uid' => $fp1]);
check('同端的成员可以换班', $s === 200 && ($j['code'] ?? 1) === 0, J($j));

// ---------- 4.9 单块关掉就不分端 ----------
echo "-- 单块开关 --\n";
$allOn = ['announce' => 1, 'race' => 1, 'custom_race' => 1, 'event' => 1,
          'training' => 1, 'vote' => 1, 'team' => 1, 'duty' => 1];
$mods = $allOn; $mods['announce'] = 0; $mods['race'] = 0;
[$s, $j] = as_req('boss', 'POST', '/gs1/api/admin/game-side/save', ['modules' => $mods]);
check('只关掉公告与撞车两块', $s === 200 && ($j['data']['modules']['announce'] ?? 1) === false
    && ($j['data']['modules']['race'] ?? 1) === false && ($j['data']['modules']['vote'] ?? 0) === true, J($j['data']['modules'] ?? null));
[, $j] = as_req('p1', 'GET', '/gs1/api/notices');
$t = titles($j['data']['list'] ?? []);
check('公告那块关掉后成员两端都看得到（不再按端筛）', in_array('手游公告乙', $t, true), J($t));
[, $j] = as_req('p1', 'GET', '/gs1/api/races');
$t = titles($j['data']['list'] ?? []);
check('撞车那块关掉后也不按端筛', in_array('手游撞车', $t, true), J($t));
[, $j] = as_req('p1', 'GET', '/gs1/api/votes');
$t = titles(array_merge($j['data']['open'] ?? [], $j['data']['closed'] ?? []));
check('投票那块还开着，仍然看不到手游投票', !in_array('手游投票', $t, true), J($t));
[$s, $j] = as_req('boss', 'POST', '/gs1/api/admin/game-side/save', ['modules' => $allOn]);
check('八块全开回来', $s === 200 && array_sum($j['data']['modules'] ?? []) === 8, J($j['data']['modules'] ?? null));

// ============================================================
// 5. 赛前提醒跟随端别
// ============================================================
echo "\n== 5. 赛前提醒 ==\n";
[$s, $j] = as_req('boss', 'POST', '/gs1/api/trainings/create', [
    'title' => '提醒实验', 'mode' => 'free', 'match_time' => date('Y-m-d H:i:s', time() + 20 * 60),
    'series_format' => 'bo1', 'map_mode' => 'random', 'game_side' => 'pc',
    'signup_deadline' => date('Y-m-d H:i:s', time() + 10 * 60),
]);
$TR_rem = (int)($j['data']['id'] ?? 0);
check('建一场 20 分钟后开打的端游训练赛', $s === 200 && $TR_rem > 0, J($j));
// 报名直接用库落（避免中间请求先触发懒扫描、把公告发在报名之前）
$ins = db()->prepare("INSERT INTO training_signups (tenant_id, match_id, user_id, created_at) VALUES (?,?,?,?)");
$ins->execute([$T1, $TR_rem, $p1, $now]);
$ins->execute([$T1, $TR_rem, $n1, $now]);
[$s, $j] = as_req('boss', 'POST', '/gs1/api/admin/remind/run', []);
check('现在扫一次成功', $s === 200 && ($j['code'] ?? 1) === 0, J($j));
$annRem = q("SELECT * FROM announcements WHERE tenant_id=? AND title LIKE ?", [$T1, '%提醒实验%']);
check('推出来一条公告', count($annRem) === 1, J($annRem));
check('公告继承了训练赛的端别（手游成员看不到）', (string)($annRem[0]['game_side'] ?? '') === 'pc', J($annRem[0] ?? null));
check('分端提醒不发 QQ 群（group_ok=0）', (int)q1('SELECT group_ok FROM event_reminders WHERE tenant_id=? AND kind=? AND ref_id=?', [$T1, 'training', $TR_rem]) === 0);
$toP1 = (int)q1("SELECT COUNT(*) FROM forum_notices WHERE to_user_id=? AND kind='event' AND post_id=?", [$p1, $TR_rem]);
$toN1 = (int)q1("SELECT COUNT(*) FROM forum_notices WHERE to_user_id=? AND kind='event' AND post_id=?", [$n1, $TR_rem]);
$toM1 = (int)q1("SELECT COUNT(*) FROM forum_notices WHERE to_user_id=? AND kind='event' AND post_id=?", [$m1, $TR_rem]);
check('站内信只发给本端的报名人（另一端混进来的报名行被过滤）', $toP1 === 1 && $toN1 === 0, "p1=$toP1 n1=$toN1");
check('另一端的报名人收不到（他没报名也收不到）', $toM1 === 0, "m1=$toM1");
[, $j] = as_req('p1', 'GET', '/gs1/api/notices');
check('端游成员看得到这条赛前提醒', count(array_filter($j['data']['list'] ?? [], fn($r) => str_contains((string)$r['title'], '提醒实验'))) === 1);
[, $j] = as_req('m1', 'GET', '/gs1/api/notices');
check('手游成员看不到这条赛前提醒', count(array_filter($j['data']['list'] ?? [], fn($r) => str_contains((string)$r['title'], '提醒实验'))) === 0);

// ============================================================
// 6. 战绩排行
// ============================================================
echo "\n== 6. 战绩排行 ==\n";
$rank = function (string $who, string $metric, string $scope = '') {
    $q = $metric === '' ? 'metric=win_rate' : 'metric=' . $metric;
    if ($scope !== '') { $q .= '&game_side=' . $scope; }
    return as_req($who, 'GET', '/gs1/api/stats/ranking?' . $q);
};
$anons = [];
[$s, $j] = req('GET', $BASE . '/gs1/api/stats/ranking', null, $anons);
check('没登录看不了排行', $s === 401 || $s === 403 || ($j['code'] ?? 0) !== 0, "HTTP $s");
[$s, $j] = $rank('p1', 'win_rate');
$rows = $j['data']['list'] ?? [];
check('成员看排行成功，指标与标签都对', $s === 200 && ($j['data']['metric'] ?? '') === 'win_rate'
    && ($j['data']['metric_label'] ?? '') === '胜率', J($j['data']['metric'] ?? null));
check('四个人指标都在（胜率/场均评分/场均击杀/场次）', array_keys($j['data']['metrics'] ?? []) === ['win_rate', 'avg_score', 'kills_per_match', 'matches'], J($j['data']['metrics'] ?? null));
$n = array_column($rows, 'nickname');
check('只算公开战绩的人：手游乙（不公开）不在榜上', !in_array('手游乙', $n, true) && in_array('端游甲', $n, true), J($n));
check('默认按胜率降序：手游甲 80 > 端游乙 60 > 锁死端游 55', array_slice($n, 0, 3) === ['手游甲', '端游乙', '锁死端游'], J($n));
check('自己那一行标了 is_me', count(array_filter($rows, fn($r) => !empty($r['is_me']) && (int)$r['user_id'] === $p1)) === 1);
check('每行带名次与端别标签', (int)($rows[0]['rank'] ?? 0) === 1 && ($rows[0]['game_side_label'] ?? '') !== '', J($rows[0] ?? null));
[$s, $j] = $rank('p1', 'avg_score');
$n = array_column($j['data']['list'] ?? [], 'nickname');
check('换成场均评分排序：小七（75）> 端游甲（70）', array_slice($n, 0, 2) === ['小七', '端游甲'], J($n));
[$s, $j] = $rank('p1', 'kills_per_match');
$n = array_column($j['data']['list'] ?? [], 'nickname');
check('换成场均击杀排序：手游甲（6）第一', ($n[0] ?? '') === '手游甲', J($n));
[$s, $j] = $rank('p1', 'matches');
$n = array_column($j['data']['list'] ?? [], 'nickname');
check('换成场次排序：端游乙（20 场）第一', ($n[0] ?? '') === '端游乙', J($n));
[$s, $j] = $rank('p1', 'nonsense');
check('乱填指标回落胜率', ($j['data']['metric'] ?? '') === 'win_rate', J($j['data']['metric'] ?? null));

[$s, $j] = $rank('lp1', 'win_rate');
$n = array_column($j['data']['list'] ?? [], 'nickname');
check('按人锁死端游的成员只看得到端游的人', !in_array('手游甲', $n, true) && in_array('端游甲', $n, true)
    && in_array('小七', $n, true) === false, J($n));
[$s, $j] = $rank('lp1', 'win_rate', 'mobile');
$n = array_column($j['data']['list'] ?? [], 'nickname');
check('越权要手游范围会被拉回端游（不是空榜也不是手游榜）', in_array('端游甲', $n, true) && !in_array('手游甲', $n, true), J($n));
[$s, $j] = $rank('p1', 'win_rate', 'mobile');
$n = array_column($j['data']['list'] ?? [], 'nickname');
check('切到手游范围只剩手游成员（含分到手游的小七）', in_array('手游甲', $n, true) && in_array('小七', $n, true)
    && !in_array('端游甲', $n, true), J($n));
[$s, $j] = $rank('p1', 'win_rate', 'all');
check('切回全队范围又是全部公开的人', count($j['data']['list'] ?? []) === 5, J(count($j['data']['list'] ?? [])));

[$s, $j] = as_req('sub', 'GET', '/gs1/api/admin/stats/ranking?metric=win_rate');
$rows = $j['data']['list'] ?? [];
$n = array_column($rows, 'nickname');
check('管理员排行不受「战绩公开」限制（手游乙也在）', in_array('手游乙', $n, true), J($n));
check('管理员榜按胜率：手游乙 90 第一', ($n[0] ?? '') === '手游乙', J($n));
check('管理员榜每行还带「公开 / 不公开」', isset($rows[0]['stats_public']), J($rows[0] ?? null));
[$s, $j] = as_req('sub', 'GET', '/gs1/api/admin/stats/ranking?metric=win_rate&game_side=pc');
$n = array_column($j['data']['list'] ?? [], 'nickname');
check('管理员按端游筛：只出端游的人', !in_array('手游甲', $n, true) && in_array('端游甲', $n, true), J($n));
[$s, $j] = as_req('sub', 'GET', '/gs1/api/admin/stats/ranking?metric=win_rate&game_side=mobile');
$n = array_column($j['data']['list'] ?? [], 'nickname');
check('管理员按手游筛：只出手游的人', in_array('手游甲', $n, true) && !in_array('端游甲', $n, true), J($n));
[$s, $j] = as_req('sub', 'GET', '/gs1/api/admin/stats/ranking');
$c = $j['data']['counts'] ?? [];
check('筛选条上的分端人数：全部 6 / 端游 3 / 手游 3 / 未分类 0',
    (int)($c['all'] ?? -1) === 6 && (int)($c['pc'] ?? -1) === 3 && (int)($c['mobile'] ?? -1) === 3
    && (int)($c['none'] ?? -1) === 0, J($c));
[$s, $j] = as_req('p1', 'GET', '/gs1/api/admin/stats/ranking');
check('普通成员进不了管理端排行', $s === 403 || $s === 401 || ($j['code'] ?? 0) !== 0, "HTTP $s");

[$s, $j] = as_req('boss2', 'GET', '/gs2/api/stats/ranking?metric=win_rate');
$n = array_column($j['data']['list'] ?? [], 'nickname');
check('隔壁队的排行只有隔壁的人（跨队隔离）', $n === ['隔壁成员'], J($n));

// ============================================================
// 7. 库结构与 DDL 同步
// ============================================================
echo "\n== 7. 库结构 ==\n";
$cols = [
    ['users', 'game_side'], ['users', 'game_side_lock'],
    ['teams', 'game_side'], ['announcements', 'game_side'], ['race_events', 'game_side'],
    ['custom_race_events', 'game_side'], ['custom_events', 'game_side'],
    ['training_matches', 'game_side'], ['votes', 'game_side'], ['duty_configs', 'game_side'],
    ['roll_calls', 'game_side'],   // v5.36.34 集合点名也按端发
];
$missing = [];
foreach ($cols as [$tbl, $col]) {
    $has = array_filter(q("PRAGMA table_info($tbl)"), fn($r) => (string)$r['name'] === $col);
    if (!$has) { $missing[] = "$tbl.$col"; }
}
check('十一列都在（users 两个 + 八张内容表 + v5.36.34 点名单）', $missing === [], implode('，', $missing));
$idxTables = ['users', 'teams', 'announcements', 'race_events', 'custom_race_events', 'custom_events', 'training_matches', 'votes', 'duty_configs'];
$noIdx = [];
foreach ($idxTables as $tbl) {
    $has = array_filter(q("PRAGMA index_list($tbl)"), fn($r) => str_contains((string)$r['name'], 'game_side'));
    if (!$has) { $noIdx[] = $tbl; }
}
check('九张表都有 game_side 索引', $noIdx === [], implode('，', $noIdx));
$uk = null;
foreach (q("PRAGMA index_list(duty_configs)") as $r) {
    if (!empty($r['unique'])) { $uk = (string)$r['name']; }
}
$ukCols = $uk ? array_column(q("PRAGMA index_info(" . $uk . ")"), 'name') : [];
check('值班唯一键是 (tenant_id, duty_type, game_side)', $ukCols === ['tenant_id', 'duty_type', 'game_side'], J($ukCols));

$sqlM = (string)file_get_contents($ROOT . '/database/game_team.sql');
$sqlP = (string)file_get_contents($ROOT . '/database/game_team_phpmyadmin.sql');
$init = (string)file_get_contents($ROOT . '/scripts/dev_init_sqlite.php');
$upg  = (string)file_get_contents($ROOT . '/database/upgrade_v5.35.1_to_v5.36.0.sql');
$inst = (string)file_get_contents($ROOT . '/public/side_install_v5360.php');
// 列定义按「`game_side` VARCHAR」数，索引里的列引用不算
$colCount = function (string $s): int {
    preg_match_all('/`game_side`\s+VARCHAR/', $s, $m);
    return count($m[0]);
};
// v5.36.34 起点名单 roll_calls 也带一份 game_side（点名按端发），列数从九到十；
// 它那两条索引按 (tenant_id, call_at) 与 (notify_at, call_at) 走，不带 game_side，所以 idx_game_side 仍是九处。
check('game_team.sql 有十处 game_side + 一处 game_side_lock + 九索引',
    $colCount($sqlM) === 10 && str_contains($sqlM, '`game_side_lock`') && substr_count($sqlM, '`idx_game_side`') === 9,
    'cols=' . $colCount($sqlM) . ' idx=' . substr_count($sqlM, '`idx_game_side`'));
check('phpMyAdmin 版同样同步',
    $colCount($sqlP) === 10 && str_contains($sqlP, '`game_side_lock`') && substr_count($sqlP, '`idx_game_side`') === 9,
    'cols=' . $colCount($sqlP) . ' idx=' . substr_count($sqlP, '`idx_game_side`'));
check('SQLite 初始化脚本同样同步', substr_count($init, 'game_side') >= 24, (string)substr_count($init, 'game_side'));
check('增量脚本十句 ADD COLUMN 且都查 information_schema',
    substr_count($upg, 'ADD COLUMN') === 10 && substr_count($upg, 'information_schema.COLUMNS') >= 10,
    'add=' . substr_count($upg, 'ADD COLUMN') . ' info=' . substr_count($upg, 'information_schema.COLUMNS'));
check('增量脚本带唯一键重建与三项自检', str_contains($upg, 'uk_tenant_type') && str_contains($upg, 'c_cols')
    && str_contains($upg, 'c_idx') && str_contains($upg, 'c_uk'));
$instOk = is_file($ROOT . '/public/side_install_v5360.php')
    && str_contains($inst, "['users', 'game_side'") && str_contains($inst, "['users', 'game_side_lock'")
    && str_contains($inst, 'uk_tenant_type');
foreach (['announcements', 'race_events', 'custom_race_events', 'custom_events', 'training_matches', 'votes', 'teams', 'duty_configs'] as $tbl) {
    if (!str_contains($inst, "'" . $tbl . "'")) { $instOk = false; }
}
check('装机页在、十列与值班唯一键都写着', $instOk);

// ============================================================
// 8. 前端与预编译
// ============================================================
echo "\n== 8. 前端 ==\n";
$aj = (string)file_get_contents($ROOT . '/public/static/js/app.jsx');
foreach ([
    "X-Game-Side", "yys_game_zone", "端手游分离", "全队排行", 'StatsRankingPanel',
    "['gameside', '端手游分离', '__super__']", "只限手机端，不能看端游", "只限端游，不能看手机端",
    '小于', "game_side_lock", "全队共享", "端游专区", "手游专区",
    "每位成员必须选端游或手游", "端别（必选）", "未分类（待分）",
] as $n) {
    if ($n === '小于') { continue; }
    check("app.jsx 里有 {$n}", str_contains($aj, $n));
}
check('app.jsx 的 api() 会带专区请求头', str_contains($aj, "gsHeaders") && str_contains($aj, "'X-Game-Side': GS_ZONE"));
check('四个排行指标都在前端', str_contains($aj, "RANK_FMT")
    && str_contains($aj, "win_rate") && str_contains($aj, "avg_score")
    && str_contains($aj, "kills_per_match") && str_contains($aj, "matches"));
$ajs = (string)file_get_contents($ROOT . '/public/static/js/app.js');
check('编译产物 app.js 里有专区与排行', str_contains($ajs, 'X-Game-Side') && str_contains($ajs, 'StatsRankingPanel'));
$chk = (string)shell_exec('node ' . escapeshellarg($ROOT . '/scripts/check_jsx.js') . ' 2>&1');
check('check_jsx 两份 jsx 都过', str_contains($chk, 'OK') && !str_contains($chk, 'FAIL'), trim($chk));
$bld = (string)shell_exec('node ' . escapeshellarg($ROOT . '/scripts/build_js.js') . ' --check 2>&1');
check('预编译产物与源码逐字节一致（认「产物核对通过」这句）', str_contains($bld, '产物核对通过'), trim($bld));

// ============================================================
// 9. 回归
// ============================================================
echo "\n== 9. 回归 ==\n";
[$s, $j] = as_req('p1', 'GET', '/gs1/api/dashboard');
check('仪表盘照常', $s === 200 && ($j['code'] ?? 1) === 0, "HTTP $s");
[$s, $j] = as_req('p1', 'GET', '/gs1/api/schedule?scope=day');
check('统一日程照常', $s === 200 && ($j['code'] ?? 1) === 0, "HTTP $s");
[$s, $j] = as_req('p1', 'GET', '/gs1/api/inbox');
check('通知页照常', $s === 200 && ($j['code'] ?? 1) === 0, "HTTP $s");
[$s, $j] = as_req('boss', 'GET', '/gs1/api/admin/users?size=1');
check('用户管理照常', $s === 200 && ($j['code'] ?? 1) === 0, "HTTP $s");
[$s, $j] = as_req('boss', 'GET', '/gs1/api/admin/stats?size=1');
check('成员战绩列表照常', $s === 200 && ($j['code'] ?? 1) === 0, "HTTP $s");
[$s, $j] = as_req('boss', 'GET', '/gs1/api/admin/remind');
check('赛前提醒设置照常', $s === 200 && ($j['code'] ?? 1) === 0, "HTTP $s");
[$s, $j] = as_req('boss', 'GET', '/gs1/api/admin/export');
check('导出表格页照常', $s === 200 && ($j['code'] ?? 1) === 0, "HTTP $s");
[$s, $j] = as_req('boss', 'GET', '/gs1/api/admin/forum-api');
check('论坛对接页照常', $s === 200 && ($j['code'] ?? 1) === 0, "HTTP $s");
check('这一版没有建新表（表数与初始化脚本一致）',
    (int)q1("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'") ===
    substr_count((string)file_get_contents($ROOT . '/scripts/dev_init_sqlite.php'), 'CREATE TABLE '),
    (string)q1("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'"));

echo "\n================ 结果 ================\n";
echo "通过 $pass 项";
if ($fail > 0) {
    echo "，失败 $fail 项：\n  - " . implode("\n  - ", $failures) . "\n";
    exit(1);
}
echo "，全部通过\n";
exit(0);
