<?php
/**
 * 一键导出表格（v5.34.0）功能 E2E
 *
 * 前置：测试服务器已启动，且 .env 指向 sqlite
 *   php -S 127.0.0.1:8899 -t public public/router.php
 * 用法：php scripts/test_data_export_e2e.php
 *
 * 覆盖：
 *   1. 页面那四行：四张表各是什么、能导哪几张、行数是取数本身数出来的（界面写着几条、文件里就几行）
 *   2. 权限分档：普通成员进不来；挂名零权限四张都不能导；只有 stats 的只能导战绩；
 *      只有 training 的能导报名导不了名单；member_view 能导名单与签到但导不了战绩
 *   3. 成员名单：行数、昵称、小队、身份（队长跟着 tenants.admin_username）、职责标签、状态，
 *      待审核的人也在名单里（那是内部管理动作）
 *   4. 战绩：以「人」为主行、与站内战绩页同口径（待审核的不占行）；表头就是 player_stat_defs() 那份；
 *      没上传的项留空而不是补 0
 *   5. 签到：总天数 / 本月 / 最长连签 / 当前连签 / 最近一次
 *   6. 活动报名：训练赛与跨队约战都进表；与本队无关的那场、对方那一侧的报名都不进本队的表；
 *      管理员添加的标出来；报名人注销了也不丢行
 *   7. QQ 复扫：八个文件（四张表 × 两种格式）解到底，一个完整号与邮箱都不出现
 *   8. 跨队：拿第二个队的真登录态导，表里只有这个队的人；空表不产文件、给一句人话
 *   9. 文件格式：xlsx 是六个部件的 zip、行数对得上、表头加粗且冻结、无 <f>；下载头两半都齐；csv 带 BOM
 *  10. 边界：kind 乱填 404、fmt 乱填 400、不填 kind 不产文件、超 5000 行只落前 5000 行并在日志里写明
 *  11. 每次导出记一行操作日志（谁、哪张表、几行、什么格式）
 *  12. 回归：成员名单/战绩/签到/通知/赛前提醒照常；前端产物里确有这一页
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

// ---------- HTTP（内存维护 Cookie；下载要拿原始字节与响应头） ----------
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
/** @return array{0:int,1:array,2:string,3:array} [状态码, JSON, 原始正文, 响应头] */
function req(string $method, string $url, ?array $body, array &$cookies): array {
    $hdr  = "Content-Type: application/json\r\n" . cookie_hdr($cookies);
    $opts = ['http' => ['method' => $method, 'header' => $hdr, 'ignore_errors' => true, 'timeout' => 60]];
    if ($body !== null) { $opts['http']['content'] = json_encode($body, JSON_UNESCAPED_UNICODE); }
    $raw = @file_get_contents($url, false, stream_context_create($opts));
    $raw = (string)($raw === false ? '' : $raw);
    $hs  = $http_response_header ?? [];
    take_cookies($hs, $cookies);
    $s = 0;
    foreach ($hs as $h) { if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) { $s = (int)$m[1]; } }
    $j = json_decode($raw, true);
    return [$s, is_array($j) ? $j : ['raw' => $raw], $raw, $hs];
}
function hdr(array $hs, string $name): string {
    foreach ($hs as $h) {
        if (preg_match('/^' . preg_quote($name, '/') . ':\s*(.*)$/i', $h, $m)) { return trim($m[1]); }
    }
    return '';
}
function J($a): string { return (string)json_encode($a, JSON_UNESCAPED_UNICODE); }
/** 排序断言：$a 那一行必须在 $b 那一行之前，而且两行都得在 */
function firstBefore(array $lines, string $a, string $b): bool {
    $ia = -1; $ib = -1;
    foreach ($lines as $i => $L) {
        if ($ia < 0 && str_contains($L, $a)) { $ia = $i; }
        elseif ($ib < 0 && str_contains($L, $b)) { $ib = $i; }
    }
    return $ia >= 0 && $ib >= 0 && $ia < $ib;
}

$jars = [];
function jar(string $who): array { global $jars; if (!isset($jars[$who])) { $jars[$who] = []; } return $jars[$who]; }
function put_jar(string $who, array $c): void { global $jars; $jars[$who] = $c; }
function as_req(string $who, string $method, string $path, ?array $body = null): array {
    global $BASE;
    $c = jar($who);
    $r = req($method, $BASE . $path, $body, $c);
    put_jar($who, $c);
    return $r;
}
function login(string $who, string $slug, string $user, string $pwd): array {
    return as_req($who, 'POST', "/$slug/api/auth/login", ['username' => $user, 'password' => $pwd]);
}
/** 把下载回来的 xlsx 解成一段可全文搜索的文本（部件全拼起来） */
function xlsx_text(string $body): string {
    global $ROOT;
    $tmp = $ROOT . '/runtime/_dx_' . bin2hex(random_bytes(4)) . '.xlsx';
    file_put_contents($tmp, $body);
    $z = new ZipArchive();
    if ($z->open($tmp) !== true) { @unlink($tmp); return ''; }
    $text = '';
    for ($i = 0; $i < $z->numFiles; $i++) { $text .= (string)$z->getFromName($z->getNameIndex($i)); }
    $z->close();
    @unlink($tmp);
    return $text;
}

// ---------- 直连库 ----------
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
    $pdo->exec("INSERT INTO tenants (applicant_id, slug, name, status, admin_username, admin_user_id, created_at, updated_at)
                VALUES (1, '$slug', '$name', 'approved', '$owner', NULL, '$now', '$now')");
    return (int)$pdo->lastInsertId();
};
$mkUser = function (int $tid, string $username, string $nick, string $role, array $perms = [],
    int $showPublic = 1, string $status = 'approved') use ($pdo, $now): int {
    $hash = password_hash('pass123456', PASSWORD_DEFAULT);
    $pj   = $perms ? json_encode($perms, JSON_UNESCAPED_UNICODE) : '';
    $pdo->exec("INSERT INTO users (tenant_id, username, password, nickname, role, status, admin_perms,
                                   show_public, stats_public, created_at, updated_at)
                VALUES ($tid, '$username', '$hash', '$nick', '$role', '$status', '$pj',
                        $showPublic, $showPublic, '$now', '$now')");
    return (int)$pdo->lastInsertId();
};

$T1 = $mkTenant('dx1', '导出一队', 'dx1boss');
$T2 = $mkTenant('dx2', '导出二队', 'dx2boss');
$T3 = $mkTenant('dx3', '导出大队', 'dx3boss');

$o1  = $mkUser($T1, 'dx1boss', '一队站长', 'admin');
$m1  = $mkUser($T1, '100070001', '一队甲', 'user');
$m2  = $mkUser($T1, '100070002', '一队乙', 'user');
$m3  = $mkUser($T1, '100070003', '一队丙不公开', 'user', [], 0);   // 对外展示关着，内部照样要导得出来
$pSt = $mkUser($T1, '100070004', '只看战绩的', 'admin', ['stats']);
$pTr = $mkUser($T1, '100070005', '只看训练的', 'admin', ['training']);
$pNo = $mkUser($T1, '100070006', '挂名管理员', 'admin', []);
$pMv = $mkUser($T1, '100070007', '看名单的', 'admin', ['member_view']);
$pEn = $mkUser($T1, '100070008', '还没审核的', 'user', [], 1, 'pending');
$o2  = $mkUser($T2, 'dx2boss', '二队站长', 'admin');
$n2  = $mkUser($T2, '200070001', '二队成员', 'user');
$n2b = $mkUser($T2, '200070002', '二队另一个', 'user');
echo "  租户：$T1 / $T2 / $T3\n";
check('种子：一队 9 人（含一个待审核）',
    (int)q("SELECT COUNT(*) AS c FROM users WHERE tenant_id = $T1")[0]['c'] === 9);

// 小队与职责标签
$pdo->exec("INSERT INTO teams (tenant_id, name, created_at, updated_at) VALUES ($T1, '夜袭组', '$now', '$now')");
$teamId = (int)$pdo->lastInsertId();
$pdo->exec("UPDATE users SET team_id = $teamId WHERE id = $m1");
$pdo->exec("INSERT INTO role_tags (tenant_id, name, created_at, updated_at) VALUES ($T1, '指挥', '$now', '$now')");
$tagId = (int)$pdo->lastInsertId();
$pdo->exec("UPDATE users SET role_tags = '" . json_encode([$tagId]) . "' WHERE id = $m2");

// 签到：甲连签三天，乙只有一条四十天前的
$d0 = date('Y-m-d'); $d1 = date('Y-m-d', strtotime('-1 day')); $d2 = date('Y-m-d', strtotime('-2 day'));
$dOld = date('Y-m-d', strtotime('-40 day'));
// 「本月签到」按种子日期现算，不写死 3 —— 每月 1、2 号跑这套时那三天里有一天在上个月
$mon  = date('Y-m-01');
$jiaMonth = ($d0 >= $mon ? 1 : 0) + ($d1 >= $mon ? 1 : 0) + ($d2 >= $mon ? 1 : 0);
foreach ([[$m1, $d2, 1], [$m1, $d1, 2], [$m1, $d0, 3], [$m2, $dOld, 1]] as $c) {
    $pdo->exec("INSERT INTO checkins (tenant_id, user_id, checkin_date, streak, created_at)
                VALUES ($T1, {$c[0]}, '{$c[1]}', {$c[2]}, '{$c[1]} 09:00:00')");
}

// 战绩：甲传满、乙只传三项（其余必须留空而不是 0），丙一行都没传（占行、全空）
$pdo->exec("INSERT INTO player_stats (tenant_id, user_id, matches, win_rate, avg_score, kd, kills, deaths,
                                      score_per_match, updated_at, created_at)
            VALUES ($T1, $m1, 42, 61.90, 3.75, 2.56, 512, 200, 18.40, '$now', '$now')");
$pdo->exec("INSERT INTO player_stats (tenant_id, user_id, matches, kills, updated_at, created_at)
            VALUES ($T1, $m2, 7, 33, '$now', '$now')");

// 训练赛与报名
$pdo->exec("INSERT INTO training_matches (tenant_id, title, mode, match_time, status, created_by, created_at, updated_at)
            VALUES ($T1, '周末冲分训练', 'squad', '" . date('Y-m-d H:i:s', time() + 3600) . "', 'signup', $o1, '$now', '$now')");
$tm1 = (int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO training_matches (tenant_id, title, mode, match_time, status, created_by, created_at, updated_at)
            VALUES ($T1, '周四乱斗（已结束）', 'free', '2026-01-05 20:00:00', 'finished', $o1, '$now', '$now')");
$tm2 = (int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO training_signups (tenant_id, match_id, user_id, side, created_at)
            VALUES ($T1, $tm1, $m1, 'a', '$now')");
$pdo->exec("INSERT INTO training_signups (tenant_id, match_id, user_id, side, created_at)
            VALUES ($T1, $tm2, $m2, NULL, '$now')");
// 报名人已经注销：那一行不能凭空消失，也不能连到别人身上
$pdo->exec("INSERT INTO training_signups (tenant_id, match_id, user_id, side, created_at)
            VALUES ($T1, $tm1, 987654, 'b', '$now')");

// 约战：一场本队发起（甲自己报、乙由管理员加，对方那一侧另有一串）、一场本队应战、还有一场与本队无关
$pdo->exec("INSERT INTO battles (title, game_mode, battle_time, challenger_type, challenger_id, defender_type, defender_id,
                                 status, created_at, updated_at)
            VALUES ('跨队练习一', '标准', '" . date('Y-m-d H:i:s', time() + 7200) . "', 'tenant', $T1, 'tenant', $T2,
                    'accepted', '$now', '$now')");
$b1 = (int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO battles (title, game_mode, battle_time, challenger_type, challenger_id, defender_type, defender_id,
                                 status, created_at, updated_at)
            VALUES ('跨队练习二', '标准', '" . date('Y-m-d H:i:s', time() + 9000) . "', 'external', 1, 'tenant', $T1,
                    'pending', '$now', '$now')");
$b2 = (int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO battles (title, game_mode, battle_time, challenger_type, challenger_id, defender_type, defender_id,
                                 status, created_at, updated_at)
            VALUES ('跟本队无关的一场', '标准', '" . date('Y-m-d H:i:s', time() + 9000) . "', 'tenant', $T2, 'tenant', $T3,
                    'accepted', '$now', '$now')");
$b3 = (int)$pdo->lastInsertId();
foreach ([[$b1, 'a', $m1, '', 0, $T1], [$b1, 'a', $m2, '', 1, $T1], [$b1, 'b', $n2, '', 0, $T2],
    [$b2, 'b', $m3, '', 0, $T1], [$b3, 'a', $n2, '', 0, $T2], [$b3, 'b', 0, '外部昵称丁', 0, $T3]] as $s) {
    $uid = $s[2] ? (int)$s[2] : 'NULL';
    $pdo->exec("INSERT INTO battle_signups (battle_id, side, signup_type, user_id, display_name, tenant_id, manual, created_at)
                VALUES ({$s[0]}, '{$s[1]}', 'user', $uid, '{$s[3]}', {$s[5]}, {$s[4]}, '$now')");
}

// 大队：验那道 5000 行闸（1 站长 + 5004 成员 = 5005，号形如 3 + 8 位，打码后是 300****01）
$o3 = $mkUser($T3, 'dx3boss', '大队站长', 'admin');
$vals = [];
for ($i = 1; $i <= 5004; $i++) {
    $un = '3' . str_pad((string)$i, 8, '0', STR_PAD_LEFT);
    $vals[] = "($T3, '$un', 'x', '大队成员$i', 'user', 'approved', '', 1, 1, '$now', '$now')";
}
foreach (array_chunk($vals, 500) as $chunk) {
    $pdo->exec('INSERT INTO users (tenant_id, username, password, nickname, role, status, admin_perms,
        show_public, stats_public, created_at, updated_at) VALUES ' . implode(',', $chunk));
}
$bigTotal = (int)q("SELECT COUNT(*) AS c FROM users WHERE tenant_id = $T3")[0]['c'];
echo "  大队成员：$bigTotal 人\n";

foreach ([['o1', 'dx1', 'dx1boss'], ['m1', 'dx1', '100070001'], ['pSt', 'dx1', '100070004'],
    ['pTr', 'dx1', '100070005'], ['pNo', 'dx1', '100070006'], ['pMv', 'dx1', '100070007'],
    ['o2', 'dx2', 'dx2boss'], ['o3', 'dx3', 'dx3boss']] as $L) {
    [$s, $j] = login($L[0], $L[1], $L[2], 'pass123456');
    check('登录 ' . $L[0], $s === 200 && ($j['code'] ?? 1) === 0, "HTTP $s " . J($j));
}

// 站内战绩页那份字段口径（表头要与之完全一致，所以从接口拿，不在测试里另抄一份）
[$s, $j] = as_req('pSt', 'GET', '/dx1/api/admin/stats?size=1');
$defsOverHttp = [];
foreach (($j['data']['fields'] ?? []) as $f) { $defsOverHttp[] = (string)$f['label']; }
check('取到站内战绩页的 20 项列名', count($defsOverHttp) === 20, (string)count($defsOverHttp));

// ============================================================
// 1. 页面那四行
// ============================================================
echo "\n== 1. 管理端那一页：四张表与各自行数 ==\n";
[$s, $j, $raw] = as_req('o1', 'GET', '/dx1/api/admin/export');
check('站长能打开这一页', $s === 200 && ($j['code'] ?? 1) === 0, "HTTP $s " . mb_substr($raw, 0, 120));
$kinds = [];
foreach (($j['data']['kinds'] ?? []) as $k) { $kinds[$k['kind']] = $k; }
check('四张表都在：成员名单/战绩/签到/报名',
    count($kinds) === 4 && isset($kinds['members'], $kinds['stats'], $kinds['checkins'], $kinds['signups']),
    J(array_keys($kinds)));
check('每档都带 can 与 rows 两格', isset($kinds['members']['can'], $kinds['members']['rows']));
check('成员名单 9 行（待审核的也算，那是内部管理）', (int)$kinds['members']['rows'] === 9, J($kinds['members']));
check('战绩 8 行（与站内战绩页同口径：待审核的不占行）', (int)$kinds['stats']['rows'] === 8, J($kinds['stats']));
check('签到按人数算：2 人签过', (int)$kinds['checkins']['rows'] === 2, J($kinds['checkins']));
check('报名 = 训练赛 3 + 本队那一侧约战 3 = 6', (int)$kinds['signups']['rows'] === 6, J($kinds['signups']));
check('站长四张都能导', $kinds['members']['can'] && $kinds['stats']['can']
    && $kinds['checkins']['can'] && $kinds['signups']['can']);
check('页面写明一次最多 5000 行', (int)($j['data']['cap'] ?? 0) === 5000, J($j['data']['cap'] ?? null));
check('页面文案里说了 QQ 打码', mb_strpos((string)($j['data']['note'] ?? ''), '打码') !== false, J($j['data']['note'] ?? ''));

// ============================================================
// 2. 权限分档
// ============================================================
echo "\n== 2. 谁能导哪一张 ==\n";
[$s, $j] = as_req('m1', 'GET', '/dx1/api/admin/export');
check('普通成员连这一页都进不来', $s === 403 || ($j['code'] ?? 0) !== 0, "HTTP $s " . J($j));
[$s, $j] = as_req('m1', 'GET', '/dx1/api/admin/export/file?kind=members&fmt=csv');
check('普通成员直接够下载也拿不到文件', $s === 403 || ($j['code'] ?? 0) !== 0, "HTTP $s");

$cnOf = static function (array $j): array {
    $out = [];
    foreach (($j['data']['kinds'] ?? []) as $k) { $out[$k['kind']] = (bool)$k['can']; }
    return $out;
};
[$s, $j] = as_req('pNo', 'GET', '/dx1/api/admin/export');
check('挂名零权限的管理员：四张都标不能导', ($j['code'] ?? 1) === 0 && $cnOf($j) === [
    'members' => false, 'stats' => false, 'checkins' => false, 'signups' => false], J($cnOf($j)));
[$s, $j] = as_req('pNo', 'GET', '/dx1/api/admin/export/file?kind=members&fmt=csv');
check('挂名管理员硬闯名单下载 → 403', $s === 403, "HTTP $s " . J($j));
[$s, $j] = as_req('pNo', 'GET', '/dx1/api/admin/export/file?kind=checkins&fmt=csv');
check('挂名管理员硬闯签到下载 → 403', $s === 403, "HTTP $s");

[$s, $j] = as_req('pSt', 'GET', '/dx1/api/admin/export');
check('只有 stats 权限：只能导战绩', $cnOf($j) === ['members' => false, 'stats' => true,
    'checkins' => false, 'signups' => false], J($cnOf($j)));
[$s, $j, $csv] = as_req('pSt', 'GET', '/dx1/api/admin/export/file?kind=stats&fmt=csv');
check('只有 stats 权限导战绩 → 出得来文件', $s === 200 && str_starts_with($csv, "\xEF\xBB\xBF"), "HTTP $s");
[$s, $j] = as_req('pSt', 'GET', '/dx1/api/admin/export/file?kind=members&fmt=csv');
check('只有 stats 权限导名单 → 403', $s === 403, "HTTP $s");

[$s, $j] = as_req('pTr', 'GET', '/dx1/api/admin/export');
check('只有 training 权限：能导报名、导不了名单与战绩',
    $cnOf($j) === ['members' => false, 'stats' => false, 'checkins' => false, 'signups' => true], J($cnOf($j)));
[$s, $j, $csv] = as_req('pTr', 'GET', '/dx1/api/admin/export/file?kind=signups&fmt=csv');
check('只有 training 权限导报名 → 出得来文件', $s === 200 && strlen($csv) > 10, "HTTP $s");

[$s, $j] = as_req('pMv', 'GET', '/dx1/api/admin/export');
check('member_view：名单与签到能导，战绩不能',
    $cnOf($j)['members'] === true && $cnOf($j)['checkins'] === true && $cnOf($j)['stats'] === false,
    J($cnOf($j)));

// ============================================================
// 3. 成员名单
// ============================================================
echo "\n== 3. 成员名单那张 ==\n";
[$s, $j, $csv, $hs] = as_req('o1', 'GET', '/dx1/api/admin/export/file?kind=members&fmt=csv');
check('名单 CSV 下得来（HTTP 200 带 BOM）', $s === 200 && str_starts_with($csv, "\xEF\xBB\xBF"), "HTTP $s");
$ctCsv = hdr($hs, 'Content-Type');
check('CSV 的 Content-Type 只写了一次 charset（框架那半拼上去的就够）',
    $ctCsv === 'text/csv; charset=utf-8', $ctCsv);
$lines = preg_split("/\r?\n/", trim(substr($csv, 3)));
check('9 人 + 表头 = 10 行', count($lines) === 10, (string)count($lines));
$head = str_getcsv((string)$lines[0]);
check('表头八列齐', $head === ['昵称', 'QQ(打码)', '小队', '身份', '状态', '职责标签', '注册时间', '最后活跃'],
    implode('/', $head));
$joined = implode("\n", $lines);
check('小队列认得出「夜袭组」', str_contains($joined, '夜袭组'), '');
check('没分队的写成「未分组」而不是留空', str_contains($joined, '未分组'), '');
check('职责标签列认得出「指挥」', str_contains($joined, '指挥'), '');
$ownerLine = ''; $pendLine = '';
foreach ($lines as $L) {
    if (str_contains($L, '一队站长')) { $ownerLine = $L; }
    if (str_contains($L, '还没审核的')) { $pendLine = $L; }
}
check('站长那行的身份格写的是「队长」', str_contains($ownerLine, ',队长,'), $ownerLine);
check('待审核那行状态写「待审核」', str_contains($pendLine, '待审核'), $pendLine);
check('关了「对外展示」的丙照样在表里（内部照导）', str_contains($joined, '一队丙不公开'), '');
check('副管理员那行身份写「管理员」（昵称,号,小队,身份）',
    (bool)preg_match('/挂名管理员,[^,]*,[^,]*,管理员,/', $joined), '');

// ============================================================
// 4. 战绩
// ============================================================
echo "\n== 4. 战绩那张 ==\n";
[$s, $j, $csv] = as_req('o1', 'GET', '/dx1/api/admin/export/file?kind=stats&fmt=csv');
$lines = preg_split("/\r?\n/", trim(substr((string)$csv, 3)));
check('战绩 8 人 + 表头 = 9 行', count($lines) === 9, (string)count($lines));
$head = str_getcsv((string)$lines[0]);
check('战绩表头 23 列（昵称 + 打码号 + 20 项 + 最近上传）', count($head) === 23, (string)count($head));
$miss = [];
foreach ($defsOverHttp as $i => $lab) { if (($head[$i + 2] ?? null) !== $lab) { $miss[] = $lab . '≠' . (string)($head[$i + 2] ?? ''); } }
check('二十项的中文列名与顺序都跟站内那份口径一致', $miss === [], implode('/', array_slice($miss, 0, 4)));
check('待审核的人不占战绩那行', !str_contains((string)$csv, '还没审核的'),
    '战绩那张的正文里不该出现这个人');
$kdCol = array_search('KD', $head, true);
$rowJia = ''; $rowYi = ''; $rowBing = '';
foreach ($lines as $L) {
    $c = str_getcsv($L);
    if (($c[0] ?? '') === '一队甲') { $rowJia = $L; }
    if (($c[0] ?? '') === '一队乙') { $rowYi = $L; }
    if (($c[0] ?? '') === '一队丙不公开') { $rowBing = $L; }
}
$cJia = str_getcsv($rowJia); $cYi = str_getcsv($rowYi);
check('甲的 KD 落的是 2.56', isset($cJia[$kdCol]) && (float)$cJia[$kdCol] === 2.56, $rowJia);
check('乙没上传 KD，那一格是空的而不是 0',
    isset($cYi[$kdCol]) && trim((string)$cYi[$kdCol]) === '', 'KD=' . ($cYi[$kdCol] ?? '(没这格)'));
$cBing = str_getcsv($rowBing);
$nonEmpty = 0;
for ($i = 2; $i < 22; $i++) { if (trim((string)($cBing[$i] ?? '')) !== '') { $nonEmpty++; } }
check('一行都没传的丙占一行、二十项全空', $rowBing !== '' && $nonEmpty === 0, $rowBing);
check('甲的场次 42 与胜率 61.9 都在', str_contains($rowJia, '42') && str_contains($rowJia, '61.9'), $rowJia);

// ============================================================
// 5. 签到
// ============================================================
echo "\n== 5. 签到那张 ==\n";
[$s, $j, $csv] = as_req('o1', 'GET', '/dx1/api/admin/export/file?kind=checkins&fmt=csv');
$lines = preg_split("/\r?\n/", trim(substr((string)$csv, 3)));
check('签到 2 人 + 表头', count($lines) === 3, (string)count($lines));
$head = str_getcsv((string)$lines[0]);
check('签到表头七列含「当前连签」与「最长连签」',
    $head === ['昵称', 'QQ(打码)', '签到总天数', '本月签到', '最长连签', '当前连签', '最近一次签到'],
    implode('/', $head));
foreach ($lines as $L) {
    $c = str_getcsv($L);
    if (($c[0] ?? '') === '一队甲') {
        check('甲：总天数 3、本月 ' . $jiaMonth . '、最长 3、当前 3',
            ($c[2] ?? '') === '3' && ($c[3] ?? '') === (string)$jiaMonth
            && ($c[4] ?? '') === '3' && ($c[5] ?? '') === '3', $L);
        check('甲最近一次是今天', ($c[6] ?? '') === date('Y-m-d'), $L);
    }
    if (($c[0] ?? '') === '一队乙') {
        check('乙：总天数 1、本月 0（四十天前那条不算本月）',
            ($c[2] ?? '') === '1' && ($c[3] ?? '') === '0' && ($c[4] ?? '') === '1', $L);
        check('乙最近一次是四十天前那天', ($c[6] ?? '') === $dOld, $L);
    }
}
check('签到那张按天数从多到少排（甲在乙前面）',
    firstBefore($lines, '一队甲', '一队乙'), implode('|', array_map(static fn($l) => mb_substr($l, 0, 12), $lines)));

// ============================================================
// 6. 活动报名
// ============================================================
echo "\n== 6. 活动报名那张 ==\n";
[$s, $j, $csv] = as_req('o1', 'GET', '/dx1/api/admin/export/file?kind=signups&fmt=csv');
$lines = preg_split("/\r?\n/", trim(substr((string)$csv, 3)));
check('报名 6 行 + 表头', count($lines) === 7, (string)count($lines));
$all = implode("\n", $lines);
check('训练赛那两场都进表', str_contains($all, '周末冲分训练') && str_contains($all, '周四乱斗（已结束）'), '');
check('本队参与的两场约战进表', str_contains($all, '跨队练习一') && str_contains($all, '跨队练习二'), '');
check('与本队无关的那场不进表', !str_contains($all, '跟本队无关的一场'), '');
check('对方那一侧的报名（二队成员）不进本队的表', !str_contains($all, '二队成员'), '');
check('外部昵称那一行不进表', !str_contains($all, '外部昵称丁'), '');
check('管理员添加的那行标了「管理员添加」', str_contains($all, '管理员添加'), '');
check('自己报名的那行标了「自己报名」', str_contains($all, '自己报名'), '');
check('已注销账号的报名没丢，写成（已注销账号）', str_contains($all, '(已注销账号)'), '');
check('类别一列分得开训练赛与跨队约战', substr_count($all, '训练赛') >= 2 && str_contains($all, '跨队约战'), '');
check('活动状态跟着源表：已结束的那场写已结束', str_contains($all, '已结束'), '');

// ============================================================
// 7. QQ 复扫
// ============================================================
echo "\n== 7. 一个完整 QQ 号都不许在文件里 ==\n";
$qqList = array_column(q('SELECT username FROM users WHERE tenant_id IN (?,?,?)', [$T1, $T2, $T3]), 'username');
$dirty = [];
foreach (['members', 'stats', 'checkins', 'signups'] as $kind) {
    foreach (['csv', 'xlsx'] as $fmt) {
        [$s, $j, $body] = as_req('o1', 'GET', "/dx1/api/admin/export/file?kind=$kind&fmt=$fmt");
        if ($s !== 200) { $dirty[] = "$kind/$fmt 取不到（HTTP $s）"; continue; }
        $text = $fmt === 'xlsx' ? xlsx_text($body) : $body;
        if ($text === '') { $dirty[] = "$kind/$fmt 解不出内容"; continue; }
        foreach ($qqList as $qq) {
            $qq = (string)$qq;
            if ($qq !== '' && str_contains($text, $qq)) { $dirty[] = "$kind/$fmt 里出现完整号 $qq"; }
        }
        if (preg_match('/\d{5,11}@qq\.com/i', $text)) { $dirty[] = "$kind/$fmt 里出现邮箱"; }
    }
}
check('八个文件（四张表 × 两种格式）解到底都没有完整号与邮箱', $dirty === [], implode('；', array_slice($dirty, 0, 3)));
[$s, $j, $csv] = as_req('o1', 'GET', '/dx1/api/admin/export/file?kind=members&fmt=csv');
check('打码那列是前 3 后 2 的形状（100****01）', str_contains($csv, '100****01'), mb_substr($csv, 0, 160));

// ============================================================
// 8. 跨队隔离
// ============================================================
echo "\n== 8. 换个队来导，只出这个队的人 ==\n";
[$s, $j, $csv] = as_req('o2', 'GET', '/dx2/api/admin/export/file?kind=members&fmt=csv');
$lines = preg_split("/\r?\n/", trim(substr((string)$csv, 3)));
check('二队站长导名单：3 人 + 表头', count($lines) === 4, (string)count($lines));
$two = implode("\n", $lines);
check('二队的表里没有一队的人',
    !str_contains($two, '一队站长') && !str_contains($two, '一队甲') && !str_contains($two, '挂名管理员'), '');
check('二队的表里是自己那三个人',
    str_contains($two, '二队站长') && str_contains($two, '二队成员') && str_contains($two, '二队另一个'), '');
[$s, $j, $csv] = as_req('o2', 'GET', '/dx2/api/admin/export/file?kind=signups&fmt=csv');
check('二队导报名：约战那半串是本队那一侧的人', $s === 200 && str_contains((string)$csv, '二队成员'), "HTTP $s");
check('二队的报名表里没有一队的人', !str_contains((string)$csv, '一队甲'), mb_substr((string)$csv, 0, 200));
check('二队报名里也不含一队那两场训练赛', !str_contains((string)$csv, '周末冲分训练'), '');
[$s, $j, $csv] = as_req('o2', 'GET', '/dx2/api/admin/export/file?kind=checkins&fmt=csv');
check('二队没签到记录：不产文件、给一句人话',
    ($j['code'] ?? 0) !== 0 && str_contains((string)($j['msg'] ?? ''), '没有数据'), J($j));

// ============================================================
// 9. 文件格式
// ============================================================
echo "\n== 9. xlsx 是不是个能打开的 xlsx ==\n";
[$s, $j, $body, $hs] = as_req('o1', 'GET', '/dx1/api/admin/export/file?kind=members&fmt=xlsx');
check('状态 200', $s === 200, "HTTP $s");
check('Content-Type 是 xlsx 那个官方 MIME，且不带 charset（二进制拼 charset 是多余的）',
    hdr($hs, 'Content-Type') === 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    hdr($hs, 'Content-Type'));
$disp = hdr($hs, 'Content-Disposition');
check('Content-Disposition 是 attachment', str_contains($disp, 'attachment'), $disp);
check('ASCII 那半文件名带得上表名（dx1-members-YYYYMMDD.xlsx，不是一串横杠）',
    (bool)preg_match('/filename="dx1-members-\d{8}\.xlsx"/', $disp), $disp);
check('UTF-8 那半带真实中文名（RFC 5987）',
    str_contains($disp, "filename*=UTF-8''") && str_contains($disp, '%E6%88%90%E5%91%98%E5%90%8D%E5%8D%95'), $disp);
check('Cache-Control 说不许缓存这份文件', str_contains(hdr($hs, 'Cache-Control'), 'no-store'), hdr($hs, 'Cache-Control'));
check('开头是 zip 的 PK', str_starts_with($body, 'PK'), bin2hex(substr($body, 0, 4)));
$tmp = $ROOT . '/runtime/_dx_file.xlsx';
file_put_contents($tmp, $body);
$z = new ZipArchive();
check('ZipArchive 打得开', $z->open($tmp) === true, '');
$names = [];
for ($i = 0; $i < $z->numFiles; $i++) { $names[] = $z->getNameIndex($i); }
sort($names);
check('六个部件一个不少', $names === ['[Content_Types].xml', '_rels/.rels', 'xl/_rels/workbook.xml.rels',
    'xl/styles.xml', 'xl/workbook.xml', 'xl/worksheets/sheet1.xml'], implode(',', $names));
$badXml = [];
foreach ($names as $n) {
    $x = libxml_use_internal_errors(true);
    $doc = simplexml_load_string((string)$z->getFromName($n));
    libxml_use_internal_errors($x);
    if ($doc === false) { $badXml[] = $n; }
}
check('六个部件全是合法 XML', $badXml === [], implode(',', $badXml));
$sheet = (string)$z->getFromName('xl/worksheets/sheet1.xml');
check('sheet 行数 = 9 人 + 表头', substr_count($sheet, '<row ') === 10, (string)substr_count($sheet, '<row '));
check('表头那一行用的是加粗样式', (bool)preg_match('/<row r="1">.*s="1"/s', $sheet), '');
check('表头冻在第一行（ySplit="1"）', str_contains($sheet, 'ySplit="1"'), '');
check('工作表名叫「成员名单」', str_contains((string)$z->getFromName('xl/workbook.xml'), 'name="成员名单"'), '');
check('没有写出公式元素 <f>', !str_contains($sheet, '<f>') && !str_contains($sheet, '<f '), '');
$z->close();
@unlink($tmp);
check('列宽那一节按八列给了', substr_count($sheet, '<col min=') === 8, (string)substr_count($sheet, '<col min='));

[$s, $j, $body] = as_req('o1', 'GET', '/dx1/api/admin/export/file?kind=stats&fmt=xlsx');
$text = xlsx_text($body);
check('战绩那张第一行有 23 个单元格',
    (bool)preg_match('/<row r="1">(<c [^>]*>.*?<\/c>|<c [^>]*\/>){23}<\/row>/s', $text), '');
check('战绩里的数字是真数字（<v>42</v> 不是文本）', str_contains($text, '<v>42</v>'), '');
check('战绩工作表名叫「成员战绩」', str_contains($text, 'name="成员战绩"'), '');

// ============================================================
// 10. 边界与 5000 行那道闸
// ============================================================
echo "\n== 10. 乱填与那道 5000 行闸 ==\n";
[$s, $j] = as_req('o1', 'GET', '/dx1/api/admin/export/file?kind=nope&fmt=csv');
check('kind 乱填 → 404', $s === 404 && ($j['code'] ?? 0) !== 0, "HTTP $s " . J($j));
[$s, $j] = as_req('o1', 'GET', '/dx1/api/admin/export/file?kind=members&fmt=pdf');
check('fmt 乱填 → 400（不顺手当 xlsx 发）', $s === 400, "HTTP $s " . J($j));
[$s, $j] = as_req('o1', 'GET', '/dx1/api/admin/export/file?fmt=csv');
check('不填 kind 也不产文件', ($j['code'] ?? 0) !== 0, J($j));
[$s, $j, $body, $hs] = as_req('o1', 'GET', '/dx1/api/admin/export/file?kind=members');
check('不填 fmt 默认给 xlsx（站长点了就下）',
    $s === 200 && str_contains(hdr($hs, 'Content-Disposition'), '.xlsx'), "HTTP $s " . hdr($hs, 'Content-Disposition'));

[$s, $j] = as_req('o3', 'GET', '/dx3/api/admin/export');
$big = null;
foreach (($j['data']['kinds'] ?? []) as $k) { if ($k['kind'] === 'members') { $big = $k; } }
check('大队 5005 人：页面写 5000 条并标 capped（不报假精确的行数）',
    (int)($big['rows'] ?? 0) === 5000 && (bool)($big['capped'] ?? false), J($big));
[$s, $j, $body] = as_req('o3', 'GET', '/dx3/api/admin/export/file?kind=members&fmt=xlsx');
$text = xlsx_text($body);
check('文件里正好落前 5000 行（加表头 5001）', substr_count($text, '<row ') === 5001,
    (string)substr_count($text, '<row '));
check('大队那五千行也是打码的', str_contains($text, '300****01') && !str_contains($text, '300000001'), '');
$lg = q("SELECT action, detail FROM admin_logs WHERE action = ? ORDER BY id DESC LIMIT 1", ['数据导出']);
check('截断这一次在操作日志里写明了「只落了前 5000 行」',
    isset($lg[0]['detail']) && str_contains((string)$lg[0]['detail'], '只落了前 5000 行'), J($lg[0] ?? []));

// ============================================================
// 11. 操作日志
// ============================================================
echo "\n== 11. 每次导出都记一行操作日志 ==\n";
$before = (int)(q('SELECT COUNT(*) AS c FROM admin_logs WHERE action = ?', ['数据导出'])[0]['c'] ?? 0);
[$s] = as_req('o1', 'GET', '/dx1/api/admin/export/file?kind=checkins&fmt=csv');
$after = (int)(q('SELECT COUNT(*) AS c FROM admin_logs WHERE action = ?', ['数据导出'])[0]['c'] ?? 0);
check('导一次多一行', $after === $before + 1, "前 $before 后 $after");
$one = q('SELECT admin_name, detail FROM admin_logs WHERE action = ? ORDER BY id DESC LIMIT 1', ['数据导出']);
check('那一行写着是谁、哪张表、几行、什么格式',
    str_contains((string)($one[0]['detail'] ?? ''), '签到记录')
    && str_contains((string)($one[0]['detail'] ?? ''), '（csv）')
    && str_contains((string)($one[0]['admin_name'] ?? ''), 'dx1boss'), J($one[0] ?? []));
$noLog = (int)(q('SELECT COUNT(*) AS c FROM admin_logs WHERE action = ? AND detail LIKE ?',
    ['数据导出', '%404%'])[0]['c'] ?? 0);
check('被挡掉的那几次没往日志里塞假记录', $noLog === 0, (string)$noLog);

// ============================================================
// 12. 回归
// ============================================================
echo "\n== 12. 没把别的东西带崩 ==\n";
[$s, $j] = as_req('o1', 'GET', '/dx1/api/admin/users?page=1&size=20');
check('成员名单接口照常（9 人）', $s === 200 && ($j['code'] ?? 1) === 0
    && count($j['data']['list'] ?? []) === 9, "HTTP $s n=" . count($j['data']['list'] ?? []));
[$s, $j] = as_req('pSt', 'GET', '/dx1/api/admin/stats?size=20');
check('战绩接口照常（8 个已通过的人）', $s === 200 && ($j['code'] ?? 1) === 0
    && count($j['data']['list'] ?? []) === 8, "HTTP $s n=" . count($j['data']['list'] ?? []));
[$s, $j] = as_req('m1', 'GET', '/dx1/api/checkin');
check('签到页照常', $s === 200 && ($j['code'] ?? 1) === 0, "HTTP $s");
[$s, $j] = as_req('m1', 'GET', '/dx1/api/inbox?limit=20');
check('通知页照常', $s === 200 && ($j['code'] ?? 1) === 0, "HTTP $s");
[$s, $j] = as_req('o1', 'GET', '/dx1/api/admin/remind');
check('赛前提醒那页照常（同组新路由没顶掉老的）', $s === 200 && ($j['code'] ?? 1) === 0, "HTTP $s");
$c0 = [];
[$s, $jj, $raw] = req('GET', $BASE . '/dx1/', null, $c0);
check('战队端首页照常', $s === 200 && strlen($raw) > 2000, "HTTP $s");
[$s, $jj, $raw] = req('GET', $BASE . '/', null, $c0);
check('官网首页照常', $s === 200 && strlen($raw) > 2000, "HTTP $s");

echo "\n== 13. 前端产物里有这一页 ==\n";
$js = (string)file_get_contents($ROOT . '/public/static/js/app.js');
check('编译产物里有「导出表格」这一格', str_contains($js, '导出表格'), '');
check('产物里挂着这两条接口', str_contains($js, '/api/admin/export')
    && str_contains($js, '/api/admin/export/file'), '');
check('产物里没有裸 .jsx 引用', !preg_match('/static\/js\/[a-z-]+\.jsx/', $js), '');
$chk = (string)shell_exec('node ' . escapeshellarg($ROOT . '/scripts/check_jsx.js') . ' 2>&1');
check('check_jsx 两份 jsx 都过', str_contains($chk, 'OK') && !str_contains($chk, 'FAIL'), trim($chk));
$bld = (string)shell_exec('node ' . escapeshellarg($ROOT . '/scripts/build_js.js') . ' --check 2>&1');
// 认「产物核对通过」这句，别认「输出里没有『不一致』」——build_js 的失败话术是「产物比源码旧」，
// 那样量就是恒真的假绿（v5.34.0 这一套第一遍就是这么自骗过去的）
check('预编译产物与源码逐字节一致（build_js --check）', str_contains($bld, '产物核对通过'), trim($bld));

echo "\n================ 结果 ================\n";
echo "通过 $pass 项";
if ($fail > 0) {
    echo "，失败 $fail 项：\n  - " . implode("\n  - ", $failures) . "\n";
    exit(1);
}
echo "，全部通过\n";
exit(0);
