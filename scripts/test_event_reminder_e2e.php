<?php
/**
 * 赛前提醒（v5.33.0）功能 E2E
 *
 * 前置：测试服务器已启动，且 .env 指向 sqlite
 *   php -S 127.0.0.1:8899 -t public public/router.php
 * 用法：php scripts/test_event_reminder_e2e.php
 *
 * 覆盖：
 *   1. 窗口与判据：提前量以内才推；窗口外、状态不对（训练赛已结束、约战还没成立）都不推
 *   2. 三类各走一遍：训练赛 / 队内日程 / 跨队约战；约战两个队各推各的，内容互不串
 *   3. 一场只推一次：重复触发不多出公告，也不重复发站内信（去重表 + dedupe 两道）
 *   4. 收件人：报名的人收到 event 那一档；没报名的成员收不到；日程不给个人发信（只出公告）
 *   5. 开关：某一类设成「不提醒」就一条都不推；平台总闸关掉时懒触发与手动检查都不干活
 *   6. 触发路：懒触发按队 60 秒限频（清缓存才认下一次）；管理端「现在检查一次」；平台全站点扫
 *   7. 权限：普通成员与只有 member_view 的副管理员都碰不到这一格；平台接口认平台超管
 *   8. QQ 群那一路：没绑群 / 机器人没启用 → group_ok=0，但公告与站内信照发（不因为发不出而少推）
 *   9. 通知页：kind=event 出现在 /api/inbox 里、计入红点、进页标读后落回 0
 *  10. 回归：约战成立自动公告（【约战成立】）与公告管理编辑不受影响
 *  11. 前端预编译：产物与源码一致（build_js.js --check）、五份页面不再下载 babel、js 都能取到
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

// ---------- HTTP（内存维护 Cookie） ----------
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
/** @return array{0:int,1:array,2:string} [状态码, JSON, 原始正文] */
function req(string $method, string $url, ?array $body, array &$cookies): array {
    $hdr = "Content-Type: application/json\r\n" . cookie_hdr($cookies);
    $opts = ['http' => ['method' => $method, 'header' => $hdr, 'ignore_errors' => true, 'timeout' => 20]];
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
function as_req(string $who, string $method, string $path, ?array $body = null): array {
    global $BASE;
    $c = jar($who);
    [$s, $j, $raw] = req($method, $BASE . $path, $body, $c);
    put_jar($who, $c);
    return [$s, $j, $raw];
}
function login(string $who, string $slug, string $user, string $pwd): array {
    return as_req($who, 'POST', "/$slug/api/auth/login", ['username' => $user, 'password' => $pwd]);
}

// ---------- 直连库 ----------
function db(): PDO {
    global $DB;
    $p = new PDO('sqlite:' . $DB);
    $p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $p->setAttribute(PDO::ATTR_TIMEOUT, 5);
    return $p;
}
// 三类公告的标题前缀不一样（【赛前提醒】/【日程提醒】），所以按「提醒】」这一段认自家的那几条
function anns(int $tid, string $like = '%提醒】%'): array {
    $st = db()->prepare('SELECT * FROM announcements WHERE tenant_id = ? AND title LIKE ? ORDER BY id ASC');
    $st->execute([$tid, $like]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}
function remindRows(int $tid = 0): array {
    if ($tid > 0) {
        $st = db()->prepare('SELECT * FROM event_reminders WHERE tenant_id = ? ORDER BY id ASC');
        $st->execute([$tid]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }
    return db()->query('SELECT * FROM event_reminders ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC);
}
function noticesOf(int $uid, string $kind = 'event'): array {
    $st = db()->prepare('SELECT * FROM forum_notices WHERE to_user_id = ? AND kind = ? ORDER BY id ASC');
    $st->execute([$uid, $kind]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}
function clearCache(): int {
    global $ROOT;
    $n = 0;
    foreach ([$ROOT . '/runtime/cache/*/*', $ROOT . '/runtime/cache/*'] as $pat) {
        foreach (glob($pat) ?: [] as $f) { if (is_file($f)) { @unlink($f); $n++; } }
    }
    return $n;
}

// ============================================================
// 0. 重建库 + 种子
// ============================================================
echo "== 0. 重建测试库与种子 ==\n";
@unlink($DB);
echo shell_exec('"' . PHP_BINARY . '" "' . $ROOT . '/scripts/dev_init_sqlite.php" "' . $DB . '" 2>&1');
clearCache();

$pdo = db();
check('新表 event_reminders 在测试库里',
    (bool)$pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='event_reminders'")->fetchColumn());
$uCols = $pdo->query('PRAGMA table_info(event_reminders)')->fetchAll(PDO::FETCH_COLUMN);
check('八列齐（kind/ref_id/tenant_id/event_at/lead_min/group_ok/sent_at + id）', count($uCols) === 8, implode(',', $uCols));

$now  = date('Y-m-d H:i:s');
$mins = static function (int $m): string { return date('Y-m-d H:i:s', time() + $m * 60); };

$mkTenant = function (string $slug, string $name, string $owner) use ($pdo, $now): int {
    $pdo->exec("INSERT INTO tenants (applicant_id, slug, name, status, admin_username, admin_user_id, created_at, updated_at)
                VALUES (1, '$slug', '$name', 'approved', '$owner', NULL, '$now', '$now')");
    return (int)$pdo->lastInsertId();
};
$mkUser = function (int $tid, string $username, string $pwd, string $nick, string $role, array $perms = []) use ($pdo, $now): int {
    $hash = password_hash($pwd, PASSWORD_DEFAULT);
    $pj   = $perms ? json_encode($perms, JSON_UNESCAPED_UNICODE) : '';
    $pdo->exec("INSERT INTO users (tenant_id, username, password, nickname, role, status, admin_perms, created_at, updated_at)
                VALUES ($tid, '$username', '$hash', '$nick', '$role', 'approved', '$pj', '$now', '$now')");
    return (int)$pdo->lastInsertId();
};

$T1 = $mkTenant('er1', '提醒一队', 'er1boss');
$T2 = $mkTenant('er2', '提醒二队', 'er2boss');
$T3 = $mkTenant('er3', '提醒三队', 'er3boss');
$o1  = $mkUser($T1, 'er1boss', 'pass123456', '一队站长', 'admin');
$m1  = $mkUser($T1, '100060001', 'pass123456', '一队报名成员', 'user');
$m1b = $mkUser($T1, '100060002', 'pass123456', '一队没报名的成员', 'user');
$p1  = $mkUser($T1, 'er1plain', 'pass123456', '一队点名管理员', 'admin', ['member_view']);
$o2  = $mkUser($T2, 'er2boss', 'pass123456', '二队站长', 'admin');
$m2  = $mkUser($T2, '100060003', 'pass123456', '二队报名成员', 'user');
$o3  = $mkUser($T3, 'er3boss', 'pass123456', '三队站长', 'admin');
foreach ([[$T1, $o1], [$T2, $o2], [$T3, $o3]] as [$t, $u]) {
    $pdo->exec("UPDATE tenants SET admin_user_id = $u WHERE id = $t");
}

// 事件：一队三类各一条到点的，外加三条「不该推」的
$mkTraining = function (int $tid, string $title, string $at, string $status, int $by) use ($pdo): int {
    $pdo->exec("INSERT INTO training_matches (tenant_id, title, mode, match_time, series_format, status, created_by, created_at, updated_at)
                VALUES ($tid, '$title', 'squad', '$at', 'bo3', '$status', $by, datetime('now'), datetime('now'))");
    return (int)$pdo->lastInsertId();
};
$trA = $mkTraining($T1, '周末冲分训练', $mins(12), 'signup', $o1);      // 该推
$trB = $mkTraining($T1, '下周训练', $mins(180), 'signup', $o1);          // 窗口外
$trC = $mkTraining($T1, '已经打过的训练', $mins(8), 'finished', $o1);     // 状态不对
$pdo->exec("INSERT INTO custom_events (tenant_id, title, start_time, duration_min, note, created_at, updated_at)
            VALUES ($T1, '队内开会', '" . $mins(20) . "', 45, '对一下本周安排', datetime('now'), datetime('now'))");
$evA = (int)$pdo->lastInsertId();

$mkBattle = function (int $a, int $b, string $at, string $status) use ($pdo): int {
    $pdo->exec("INSERT INTO battles (title, game_mode, battle_time, room_info, remark, challenger_type, challenger_id,
                defender_type, defender_id, status, battle_type, series_format, created_at, updated_at)
                VALUES ('跨队练习', '三角洲', '$at', '房号 12345', '带好麦', 'tenant', $a, 'tenant', $b,
                        '$status', 'official', 'bo3', datetime('now'), datetime('now'))");
    return (int)$pdo->lastInsertId();
};
$btA = $mkBattle($T1, $T2, $mins(25), 'accepted');   // 两个队都该各推一条
$btB = $mkBattle($T1, $T3, $mins(15), 'pending');    // 没成立，不推

$pdo->exec("INSERT INTO training_signups (tenant_id, match_id, user_id, created_at) VALUES ($T1, $trA, $m1, datetime('now'))");
$pdo->exec("INSERT INTO battle_signups (battle_id, side, signup_type, user_id, tenant_id, created_at)
            VALUES ($btA, 'a', 'user', $m1, $T1, datetime('now'))");
$pdo->exec("INSERT INTO battle_signups (battle_id, side, signup_type, user_id, tenant_id, created_at)
            VALUES ($btA, 'b', 'user', $m2, $T2, datetime('now'))");
check('种子：3 个队、8 个账号、5 场事件与 3 条报名',
    $T1 > 0 && $T2 > 0 && $T3 > 0 && $trA > 0 && $evA > 0 && $btA > 0);

foreach ([
    'o1' => ['er1', 'er1boss'], 'm1' => ['er1', '100060001'], 'm1b' => ['er1', '100060002'],
    'p1' => ['er1', 'er1plain'], 'o2' => ['er2', 'er2boss'], 'm2' => ['er2', '100060003'],
    'o3' => ['er3', 'er3boss'],
] as $who => $cfg) {
    [$s, $j] = login($who, $cfg[0], $cfg[1], 'pass123456');
    check("登录 $who", ($j['code'] ?? 1) === 0, J($j));
}

// ============================================================
// 1. 窗口与判据（管理端「现在检查一次」）
// ============================================================
echo "\n== 1. 到点才推、状态不对不推 ==\n";
[$s, $j] = as_req('o1', 'POST', '/er1/api/admin/remind/run', []);
check('一队站长点「现在检查一次」成功', ($j['code'] ?? 1) === 0, J($j));
check('推出 3 条（训练赛 + 日程 + 约战）', (int)($j['data']['sent'] ?? 0) === 3, J($j['data'] ?? []));
$a1 = anns($T1);
check('一队公告 3 条', count($a1) === 3, '现在 ' . count($a1) . ' 条');
$titles = implode('|', array_column($a1, 'title'));
check('窗口外那场（3 小时后）没被推', strpos($titles, '下周训练') === false, $titles);
check('状态 finished 那场没被推', strpos($titles, '已经打过的训练') === false, $titles);
check('status=pending 的约战没被推', strpos($titles, '跨队练习 vs 提醒三队') === false, $titles);
$tr = null; $sc = null; $bt = null;
foreach ($a1 as $r) {
    if (strpos((string)$r['title'], '周末冲分训练') !== false) { $tr = $r; }
    if (strpos((string)$r['title'], '队内开会') !== false) { $sc = $r; }
    if (strpos((string)$r['title'], '跨队练习') !== false) { $bt = $r; }
}
check('三类各出一条', $tr !== null && $sc !== null && $bt !== null);
check('公告都带送达确认（need_receipt=1）',
    $tr !== null && (int)$tr['need_receipt'] === 1 && (int)$sc['need_receipt'] === 1 && (int)$bt['need_receipt'] === 1);
$body = (string)($tr['content'] ?? '');
check('训练赛正文写了开赛时间与模式',
    strpos($body, '今天') !== false || strpos($body, date('Y-m-d', strtotime((string)$tr['title'] . ''))) !== false);
check('训练赛正文写了报名截止这类指引或模式', strpos($body, '四人小队') !== false || strpos($body, '报名') !== false, $body);
check('日程那条把备注带进正文', strpos((string)($sc['content'] ?? ''), '对一下本周安排') !== false, (string)($sc['content'] ?? ''));
check('约战那条写了对手队名与房间号',
    strpos((string)($bt['content'] ?? ''), '提醒二队') !== false && strpos((string)($bt['content'] ?? ''), '12345') !== false,
    (string)($bt['content'] ?? ''));

$r1 = remindRows($T1);
check('去重表一队记了 3 行', count($r1) === 3, '现在 ' . count($r1) . ' 行');
check('group_ok 记 0（本队没绑群）', $r1 !== [] && (int)$r1[0]['group_ok'] === 0);
check('lead_min 落了当时设的 30', $r1 !== [] && (int)$r1[0]['lead_min'] === 30, J($r1[0] ?? []));

[$s, $j] = as_req('o1', 'POST', '/er1/api/admin/remind/run', []);
check('再点一次：一条都不重复推', ($j['code'] ?? 1) === 0 && (int)($j['data']['sent'] ?? 1) === 0, J($j['data'] ?? []));
check('再点一次后公告还是 3 条', count(anns($T1)) === 3);
check('再点一次后去重表还是 3 行', count(remindRows($T1)) === 3);

// ============================================================
// 2. 约战是两个队各推各的
// ============================================================
echo "\n== 2. 跨队约战两边各推各的 ==\n";
check('二队这会儿还没公告（二队没人触发过）', anns($T2) === []);
[$s, $j] = as_req('o2', 'POST', '/er2/api/admin/remind/run', []);
check('二队站长点检查：推出 1 条', ($j['code'] ?? 1) === 0 && (int)($j['data']['sent'] ?? 0) === 1, J($j['data'] ?? []));
$b2 = anns($T2);
check('二队那条的对手写的是「提醒一队」',
    count($b2) === 1 && strpos((string)$b2[0]['content'], '提醒一队') !== false, J(array_column($b2, 'content')));
check('二队公告标题与一队那条不是同一行', count(anns($T1)) === 3 && count($b2) === 1);
$all = remindRows();
$btRows = array_values(array_filter($all, static fn($r) => (string)$r['kind'] === 'battle' && (int)$r['ref_id'] === $btA));
check('同一场约战在去重表里有两个队的两行（唯一键带租户号）', count($btRows) === 2, '现在 ' . count($btRows) . ' 行');

// ============================================================
// 3. 站内信只给报了名的人
// ============================================================
echo "\n== 3. 谁收到站内信 ==\n";
$n_m1  = noticesOf($m1); $n_m1b = noticesOf($m1b); $n_o1 = noticesOf($o1); $n_m2 = noticesOf($m2);
check('报了名的成员收到 2 条（训练赛 + 他报名的那场约战）', count($n_m1) === 2, '现在 ' . count($n_m1) . ' 条');
check('训练赛那条也给了创建人（队长自己）', count($n_o1) >= 1, '现在 ' . count($n_o1) . ' 条');
check('没报名的成员一条都不收', count($n_m1b) === 0, '现在 ' . count($n_m1b) . ' 条');
check('对方队报名的人收到的是自己队那一条', count($n_m2) === 1, '现在 ' . count($n_m2) . ' 条');
$scRows = array_values(array_filter($all, static fn($r) => (string)$r['kind'] === 'schedule'));
check('日程只出公告、不给个人发信', count($scRows) === 1 && noticesOf($o1) !== []
    && strpos(implode('|', array_column(noticesOf($o1), 'note')), '队内开会') === false,
    implode('|', array_column(noticesOf($o1), 'note')));
$dedupe = array_column($n_m1, 'dedupe');
check('同一个收件人同一场不会撞 dedupe（串里带收件人）',
    count($dedupe) === 2 && $dedupe[0] !== $dedupe[1], J($dedupe));

[$s, $j] = as_req('m1', 'GET', '/er1/api/inbox?limit=50');
$list = $j['data']['list'] ?? [];
check('/api/inbox 里看得到 event 那一档', ($j['code'] ?? 1) === 0 && count($list) >= 2, J($j['data'] ?? []));
$kinds = array_values(array_unique(array_map(static fn($t) => (string)($t['kind'] ?? ''), $list)));
check('这一档的 kindName 是「赛前提醒」',
    in_array('event', $kinds, true) && (function ($list) {
        foreach ($list as $t) { if ((string)($t['kind'] ?? '') === 'event') { return (string)($t['kindName'] ?? ''); } }
        return '';
    })($list) === '赛前提醒', J($kinds));
check('红点数把赛前提醒算进去', (int)($j['data']['unread'] ?? 0) === count($n_m1), J($j['data'] ?? []));
[$s, $j] = as_req('m1', 'POST', '/er1/api/inbox/read', []);
check('进通知页标读后红点落回 0', ($j['code'] ?? 1) === 0 && (int)($j['data']['read'] ?? 0) >= 2, J($j));
check('库里 read_at 写上了', (int)$pdo->query("SELECT COUNT(*) FROM forum_notices WHERE to_user_id = $m1 AND read_at IS NULL")->fetchColumn() === 0);

// ============================================================
// 4. 开关：某类关掉 / 平台总闸
// ============================================================
echo "\n== 4. 提前量与总闸 ==\n";
[$s, $j] = as_req('o1', 'GET', '/er1/api/admin/remind');
check('管理端能读三类提前量', ($j['code'] ?? 1) === 0 && count($j['data']['leads'] ?? []) === 3, J($j['data'] ?? []));
check('默认都是 30 分钟', ($j['data']['leads']['training']['lead'] ?? 0) === 30
    && ($j['data']['leads']['schedule']['lead'] ?? 0) === 30
    && ($j['data']['leads']['battle']['lead'] ?? 0) === 30, J($j['data']['leads'] ?? []));
check('三档选项是 0/10/30/60', ($j['data']['options'] ?? []) === [0, 10, 30, 60], J($j['data']['options'] ?? []));
check('列表把最近推过的带回来了', count($j['data']['recent'] ?? []) === 3 &&
    ($j['data']['recent'][0]['title'] ?? '') !== '' && ($j['data']['recent'][0]['kindName'] ?? '') !== '',
    J($j['data']['recent'] ?? []));

$trD = $mkTraining($T1, '关掉训练赛之后这场', $mins(10), 'signup', $o1);
[$s, $j] = as_req('o1', 'POST', '/er1/api/admin/remind/save', ['kind' => 'training', 'lead' => 0]);
check('把训练赛设成不提醒', ($j['code'] ?? 1) === 0 && (int)($j['data']['lead'] ?? 1) === 0, J($j));
$before = count(anns($T1));
[$s, $j] = as_req('o1', 'POST', '/er1/api/admin/remind/run', []);
check('设成不提醒后这场一条都不推', ($j['code'] ?? 1) === 0 && (int)($j['data']['sent'] ?? 1) === 0
    && count(anns($T1)) === $before, J($j['data'] ?? []));
[$s, $j] = as_req('o1', 'POST', '/er1/api/admin/remind/save', ['kind' => 'training', 'lead' => 10]);
check('改成提前 10 分钟', ($j['code'] ?? 1) === 0 && (int)($j['data']['lead'] ?? 1) === 10, J($j));
[$s, $j] = as_req('o1', 'POST', '/er1/api/admin/remind/run', []);
check('改成 10 分钟后这场推了（它在 10 分钟以内）', (int)($j['data']['sent'] ?? 0) === 1, J($j['data'] ?? []));
[$s, $j] = as_req('o1', 'POST', '/er1/api/admin/remind/save', ['kind' => '乱填', 'lead' => 30]);
check('乱填的类别被挡下', ($j['code'] ?? 0) !== 0, J($j));
[$s, $j] = as_req('o1', 'POST', '/er1/api/admin/remind/save', ['kind' => 'battle', 'lead' => 999]);
$back = as_req('o1', 'GET', '/er1/api/admin/remind')[1]['data']['leads']['battle']['lead'] ?? null;
check('不在档位里的值回落到默认 30（不是存进去 999）',
    ($j['code'] ?? 1) === 0 && (int)$back === 30, '库里是 ' . J($back));

// 平台总闸
[$s, $j] = as_req('plat', 'POST', '/api/platform/auth/login', ['username' => 'admin', 'password' => 'admin123']);
check('平台超管登录', ($j['code'] ?? 1) === 0, J($j));
[$s, $j] = as_req('plat', 'GET', '/api/platform/admin/remind/config');
check('平台端能读总闸配置', ($j['code'] ?? 1) === 0 && (int)($j['data']['enabled'] ?? 0) === 1, J($j['data'] ?? []));
check('平台端报出已上线站点数与今天推的条数',
    (int)($j['data']['tenants'] ?? 0) >= 3 && (int)($j['data']['sent_today'] ?? 0) >= 4, J($j['data'] ?? []));
check('平台端把「有多少队关掉哪一类」也算出来了', isset($j['data']['closed']['training']['off']), J($j['data']['closed'] ?? []));

[$s, $j] = as_req('plat', 'POST', '/api/platform/admin/remind/config', ['enabled' => 0]);
check('总闸关掉', ($j['code'] ?? 1) === 0 && (int)($j['data']['enabled'] ?? 1) === 0, J($j));
$trE = $mkTraining($T1, '总闸关着时新增的这场', $mins(5), 'signup', $o1);
$before = count(anns($T1));
[$s, $j] = as_req('o1', 'POST', '/er1/api/admin/remind/run', []);
check('总闸关着时管理端「检查一次」不推', ($j['code'] ?? 0) !== 0 && count(anns($T1)) === $before, J($j));
clearCache();
[$s, $j] = as_req('m1b', 'GET', '/er1/api/auth/me');
check('总闸关着时懒触发也不推', count(anns($T1)) === $before, '公告变成 ' . count(anns($T1)) . ' 条');
[$s, $j] = as_req('plat', 'POST', '/api/platform/admin/remind/tick', []);
check('总闸关着时平台「全站点检查」一条都不推',
    ($j['code'] ?? 1) === 0 && (int)($j['data']['sent'] ?? 1) === 0 && (string)($j['data']['skipped'] ?? '') === 'off', J($j['data'] ?? []));
[$s, $j] = as_req('plat', 'POST', '/api/platform/admin/remind/config', ['enabled' => 1]);
check('总闸重新打开', ($j['code'] ?? 1) === 0 && (int)($j['data']['enabled'] ?? 0) === 1, J($j));

// ============================================================
// 5. 触发路：懒触发 + 60 秒限频 + 平台全站点扫
// ============================================================
echo "\n== 5. 两条触发路 ==\n";
$trF = $mkTraining($T2, '二队到点的训练', $mins(9), 'signup', $o2);
$before2 = count(anns($T2));
clearCache();
[$s, $j] = as_req('m2', 'GET', '/er2/api/auth/me');       // 成员随便打一个接口
check('成员打开站点顺路把到点的推了', count(anns($T2)) === $before2 + 1, '公告 ' . count(anns($T2)) . ' 条');
$trG = $mkTraining($T2, '限频窗口内新增的这场', $mins(9), 'signup', $o2);
[$s, $j] = as_req('m2', 'GET', '/er2/api/auth/me');
check('同一队 60 秒内第二次请求不再扫库（这场先不推）', count(anns($T2)) === $before2 + 1,
    '公告 ' . count(anns($T2)) . ' 条');
clearCache();
[$s, $j] = as_req('m2', 'GET', '/er2/api/auth/me');
check('过了限频（清了缓存）这场就补上了', count(anns($T2)) === $before2 + 2, '公告 ' . count(anns($T2)) . ' 条');

$trH = $mkTraining($T3, '三队没人点过的这场', $mins(7), 'signup', $o3);
$before3 = count(anns($T3));
[$s, $j] = as_req('plat', 'POST', '/api/platform/admin/remind/tick', []);
check('平台「全站点检查一次」扫到三队那条',
    ($j['code'] ?? 1) === 0 && (int)($j['data']['sent'] ?? 0) >= 1 && count(anns($T3)) === $before3 + 1,
    J($j['data'] ?? []) . ' / 三队公告 ' . count(anns($T3)) . ' 条');
check('平台那条返回了扫了几个队', (int)($j['data']['tenants'] ?? 0) >= 3, J($j['data'] ?? []));
foreach ([$T1, $T2, $T3] as $t) { clearCache(); }
[$s, $j] = as_req('plat', 'POST', '/api/platform/admin/remind/tick', []);
check('扫完再扫一次：0 条（都推过了）', ($j['code'] ?? 1) === 0 && (int)($j['data']['sent'] ?? 1) === 0, J($j['data'] ?? []));

// ============================================================
// 6. 权限
// ============================================================
echo "\n== 6. 谁能碰这一格 ==\n";
[$s, $j] = as_req('m1', 'GET', '/er1/api/admin/remind');
check('普通成员读不到提醒设置', ($j['code'] ?? 0) === 403 || ($j['code'] ?? 0) === 401, J($j));
[$s, $j] = as_req('p1', 'GET', '/er1/api/admin/remind');
check('只有 member_view 的副管理员读不到', ($j['code'] ?? 0) === 403, J($j));
[$s, $j] = as_req('p1', 'POST', '/er1/api/admin/remind/save', ['kind' => 'training', 'lead' => 60]);
check('副管理员不能改提前量', ($j['code'] ?? 0) === 403, J($j));
[$s, $j] = as_req('p1', 'POST', '/er1/api/admin/remind/run', []);
check('副管理员不能点「现在检查一次」', ($j['code'] ?? 0) === 403, J($j));
[$s, $j] = as_req('m1', 'POST', '/er1/api/admin/remind/run', []);
check('普通成员也点不动检查', ($j['code'] ?? 0) === 403 || ($j['code'] ?? 0) === 401, J($j));
$anon = [];
[$s, $j] = req('GET', $BASE . '/api/platform/admin/remind/config', null, $anon);
check('未登录读平台总闸 → 401/403', $s === 401 || $s === 403 || ($j['code'] ?? 0) === 403, "HTTP $s " . J($j));
[$s, $j] = as_req('o1', 'POST', '/api/platform/admin/remind/config', ['enabled' => 0]);
check('战队站长不是平台超管 → 平台那页动不了', ($j['code'] ?? 0) === 403, J($j));
[$s, $j, $raw] = req('POST', $BASE . '/api/open/remind/tick', [], $anon);
check('不带 X-Api-Key 打开放接口 → 401', $s === 401 || ($j['code'] ?? 0) === 401, "HTTP $s " . J($j));

// ============================================================
// 7. QQ 群那一路发不出去也不影响前两条
// ============================================================
echo "\n== 7. 群消息送不达时 ==\n";
$trI = $mkTraining($T3, '三队绑了机器人也发不出这场', $mins(6), 'signup', $o3);
$pdo->exec("INSERT INTO bot_group_bindings (tenant_id, group_openid, bound_by, created_at)
            VALUES ($T3, 'ErOu-group-fake-1', $o3, datetime('now'))");
$pdo->exec("INSERT INTO bot_tenant_bots (tenant_id, app_id, app_secret, callback_key, enabled, platform_disabled, env, created_at, updated_at)
            VALUES ($T3, 'fake-appid', 'fake-secret', 'fakekey5330', 0, 0, 'prod', datetime('now'), datetime('now'))");
clearCache();
$b3 = count(anns($T3));
[$s, $j] = as_req('o3', 'POST', '/er3/api/admin/remind/run', []);
check('机器人没启用时照样推公告', ($j['code'] ?? 1) === 0
    && count(anns($T3)) === $b3 + 1
    && strpos(implode('|', array_column(anns($T3), 'title')), '三队绑了机器人也发不出这场') !== false,
    J($j['data'] ?? []) . ' / 公告 ' . count(anns($T3)) . ' 条');
$last = remindRows($T3);
check('group_ok 记 0（这条去重表行留得下来）', $last !== [] && (int)end($last)['group_ok'] === 0, J(end($last) ?: []));
$pdo->exec("UPDATE bot_tenant_bots SET enabled = 1 WHERE tenant_id = $T3");
$trJ = $mkTraining($T3, '机器人启用但接口是假的这场', $mins(6), 'signup', $o3);
clearCache();
$b3b = count(anns($T3));
[$s, $j] = as_req('o3', 'POST', '/er3/api/admin/remind/run', []);
check('机器人「启用」但凭据是假的：公告照发、不报错',
    ($j['code'] ?? 1) === 0 && count(anns($T3)) === $b3b + 1
    && strpos(implode('|', array_column(anns($T3), 'title')), '机器人启用但接口是假的这场') !== false,
    J($j['data'] ?? []) . ' / 公告 ' . count(anns($T3)) . ' 条');
$after = remindRows($T3);
check('假凭据那条 group_ok 还是 0', (int)end($after)['group_ok'] === 0, J(end($after) ?: []));
check('三队一共推了 3 条公告', count(anns($T3)) === 3, '现在 ' . count(anns($T3)) . ' 条');

// ============================================================
// 8. 回归：老功能没被带动
// ============================================================
echo "\n== 8. 回归 ==\n";
[$s, $j] = as_req('o1', 'GET', '/er1/api/notices');
check('公告栏接口照常', ($j['code'] ?? 1) === 0, J($j));
$annId = (int)(anns($T1)[0]['id'] ?? 0);
[$s, $j] = as_req('o1', 'POST', '/er1/api/admin/announcements/save', ['id' => $annId, 'title' => '改了标题', 'content' => '改了正文']);
check('管理员能编辑这条自动公告（need_receipt 那条链没坏）', ($j['code'] ?? 1) === 0, "HTTP $s " . J($j));
[$s, $j] = as_req('o1', 'GET', '/er1/api/admin/battles');
check('约战列表接口照常', ($j['code'] ?? 1) === 0, J($j));
[$s, $j] = as_req('m1', 'GET', '/er1/api/schedule');
check('成员端日程接口照常', ($j['code'] ?? 1) === 0, J($j));
[$s, $j] = as_req('m1', 'GET', '/er1/api/inbox?limit=1');
check('通知页接口照常', ($j['code'] ?? 1) === 0, J($j));
check('赛前提醒的公告与约战成立公告用的是同一张表（【约战成立】没被算进来）',
    count(anns($T1, '【约战成立】%')) === 0);

// ============================================================
// 9. 前端预编译产物
// ============================================================
echo "\n== 9. js 预编译 ==\n";
$rr = [];   // 静态资源请求用的空 Cookie
$chk = (string)shell_exec('node "' . $ROOT . '/scripts/build_js.js" --check 2>&1');
check('node scripts/build_js.js --check 通过（产物与 .jsx 源码一致）',
    strpos($chk, '产物核对通过') !== false && strpos($chk, 'FAIL') === false, $chk);
$views = ['app', 'login', 'platform', 'register'];
foreach ($views as $v) {
    $html = (string)file_get_contents($ROOT . '/view/' . $v . '.html');
    check("view/$v.html 不再在浏览器里编译 JSX",
        strpos($html, 'text/babel') === false && strpos($html, 'babel.min.js') === false);
    check("view/$v.html 引用的都是 .js", strpos($html, '/static/js/') !== false
        && !preg_match('/\/static\/js\/[A-Za-z0-9_-]+\.jsx/', $html));
}
foreach (['app', 'auth', 'platform', 'compliance-admin', 'cookie-consent'] as $b) {
    $p = $ROOT . '/public/static/js/' . $b . '.js';
    check("产物 $b.js 存在且不是空文件", is_file($p) && filesize($p) > 2000, $b);
    $head = (string)file_get_contents($p, false, null, 0, 4000);
    check("产物 $b.js 里是 React.createElement（classic runtime）", strpos($head, 'React.createElement') !== false
        || strpos((string)file_get_contents($p), 'React.createElement') !== false);
    [$s, $j, $raw] = req('GET', $BASE . '/static/js/' . $b . '.js', null, $rr);
    check("线上路径 $b.js 能取到（HTTP 200）", $s === 200 && strlen($raw) > 2000, "HTTP $s len=" . strlen($raw));
}
[$s, $j, $raw] = req('GET', $BASE . '/', null, $rr);
check('根路径官网页照常（没被这次改动带崩）', $s === 200 && strlen($raw) > 2000, "HTTP $s len=" . strlen($raw));

echo "\n================ 结果 ================\n";
echo "通过 $pass 项";
if ($fail > 0) {
    echo "，失败 $fail 项：\n  - " . implode("\n  - ", $failures) . "\n";
    exit(1);
}
echo "，全部通过\n";
exit(0);
