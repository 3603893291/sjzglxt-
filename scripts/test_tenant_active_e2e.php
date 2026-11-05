<?php
/**
 * 站点活跃（v5.35.1）功能 E2E
 *
 * 前置：测试服务器已启动，且 .env 指向 sqlite
 *   php -S 127.0.0.1:8899 -t public public/router.php
 * 用法：php scripts/test_tenant_active_e2e.php
 *
 * 覆盖：
 *   1. 权限：没登录 / 战队站长 都进不来；平台超管能读
 *   2. 三个时间窗：今日 / 7 天 / 30 天各数一遍，昨天、8 天前、31 天前都不许混进短窗口
 *   3. 只数在职账号：待审核与被封禁的人不算（哪怕他今天刚连过）
 *   4. 空的队：三零、最近一次为空、idle_days 为 null（不是 0 —— 0 会被读成"今天用过"）
 *   5. 汇总与排序：有动静的排前面，一个月没动静的沉底
 *   6. 不出人名：响应里只有每队的数字，成员昵称 / 登录名一个都不带
 *   7. 零 DDL：不建表、不往 settings 插键
 *   8. 回归：平台其它页与战队端照常
 *   9. 前端：平台侧那一页与导航、编译产物、产物核对
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
function req(string $method, string $url, ?array $body, array &$cookies): array {
    $hdr = "Content-Type: application/json\r\n" . cookie_hdr($cookies);
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
function as_req(string $who, string $method, string $path, ?array $body = null): array {
    global $BASE, $jars;
    $c = $jars[$who] ?? [];
    $r = req($method, $BASE . $path, $body, $c);
    $jars[$who] = $c;
    return $r;
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
    global $DB;
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
$at  = static fn(int $days): string => date('Y-m-d 12:00:00', time() - $days * 86400);

$mkTenant = function (string $slug, string $name, string $owner) use ($pdo, $now): int {
    $pdo->exec("INSERT INTO tenants (applicant_id, slug, name, status, admin_username, created_at, updated_at)
                VALUES (1, '$slug', '$name', 'approved', '$owner', '$now', '$now')");
    return (int)$pdo->lastInsertId();
};
$mkUser = function (int $tid, string $username, string $nick, string $role = 'user',
    string $status = 'approved', ?string $active = null) use ($pdo, $now): int {
    $hash = password_hash('pass123456', PASSWORD_DEFAULT);
    $act  = $active === null ? 'NULL' : "'" . $active . "'";
    $pdo->exec("INSERT INTO users (tenant_id, username, password, nickname, role, status, admin_perms,
                                   show_public, stats_public, last_active_at, created_at, updated_at)
                VALUES ($tid, '$username', '$hash', '$nick', '$role', '$status', '',
                        1, 1, $act, '$now', '$now')");
    return (int)$pdo->lastInsertId();
};

$T1 = $mkTenant('av1', '今天有动静的队', 'av1boss');
$T2 = $mkTenant('av2', '八天前活跃的队', 'av2boss');
$T3 = $mkTenant('av3', '没人碰过的队', 'av3boss');
$T4 = $mkTenant('av4', '只有待审的队', 'av4boss');
// 一支没通过审核的站点：连列表都不该进（"活跃"只对已开通站点有意义）
$pdo->exec("INSERT INTO tenants (applicant_id, slug, name, status, admin_username, created_at, updated_at)
            VALUES (1, 'av5', '还没开通的队', 'pending', 'av5boss', '$now', '$now')");

$o1 = $mkUser($T1, 'av1boss', '一队站长', 'admin', 'approved', $now);
$u1a = $mkUser($T1, 'av1u1', '今天来过', 'user', 'approved', $now);
$u1b = $mkUser($T1, 'av1u2', '三天前来过', 'user', 'approved', $at(3));
$u1c = $mkUser($T1, 'av1u3', '八天前来过', 'user', 'approved', $at(8));
$u1d = $mkUser($T1, 'av1u4', '三十一天前来过', 'user', 'approved', $at(31));
$u1e = $mkUser($T1, 'av1u5', '从没来过', 'user', 'approved', null);
$u1p = $mkUser($T1, 'av1p1', '待审核的', 'user', 'pending', $now);
$u1x = $mkUser($T1, 'av1x1', '被封禁的', 'user', 'banned', $now);
$o2 = $mkUser($T2, 'av2boss', '二队站长', 'admin', 'approved', $at(8));
$u2a = $mkUser($T2, 'av2u1', '八天前来过', 'user', 'approved', $at(8));
$o3 = $mkUser($T3, 'av3boss', '三队站长', 'admin', 'approved', null);
$o4 = $mkUser($T4, 'av4boss', '四队站长', 'admin', 'approved', null);
$u4p = $mkUser($T4, 'av4p1', '待审核的', 'user', 'pending', $now);

check('平台超管登录', login('plat', '', 'admin', 'admin123')[1]['code'] === 0);
check('一队站长登录', login('av1', 'av1', 'av1boss', 'pass123456')[1]['code'] === 0);
check('二队站长登录', login('av2', 'av2', 'av2boss', 'pass123456')[1]['code'] === 0);

// ============================================================
// 1. 权限
// ============================================================
echo "\n== 1. 只有平台超管能看 ==\n";
$anon = [];
[$s, $j] = req('GET', $BASE . '/api/platform/admin/active', null, $anon);
check('没登录进不来', $s === 401 || $s === 403 || ($j['code'] ?? 0) !== 0, "HTTP $s");
[$s, $j] = as_req('av1', 'GET', '/api/platform/admin/active');
check('战队站长冒充不了平台', $s === 403 || ($j['code'] ?? 0) !== 0, "HTTP $s");
[$s, $j, $raw] = as_req('plat', 'GET', '/api/platform/admin/active');
$d = $j['data'] ?? [];
check('平台超管读得到', $s === 200 && ($j['code'] ?? 1) === 0, "HTTP $s");
$map = [];
foreach ($d['list'] ?? [] as $r) { $map[(int)$r['id']] = $r; }

// ============================================================
// 2. 三个时间窗
// ============================================================
echo "\n== 2. 今日 / 7 天 / 30 天 ==\n";
$one = $map[$T1] ?? [];
check('今天有两个人来过（站长 + 今天来过那位）→ 今日 2 人', (int)($one['today'] ?? -1) === 2, J($one));
check('7 天内 3 人（再加 3 天前那位；8 天前那个不算）', (int)($one['d7'] ?? -1) === 3, J($one));
check('30 天内 4 人（再加 8 天前那位；31 天前那个不算）', (int)($one['d30'] ?? -1) === 4, J($one));
check('在职成员 6 人（站长 + 五个成员；待审核与被封禁的不算）', (int)($one['members'] ?? -1) === 6, J($one));
$two = $map[$T2] ?? [];
check('八天前活跃的队：今日 0 / 7 天 0 / 30 天 2（站长与那一位都停在八天前）', (int)($two['today'] ?? -1) === 0
    && (int)($two['d7'] ?? -1) === 0 && (int)($two['d30'] ?? -1) === 2, J($two));
check('「八天前」没被算进 7 天（短窗口不是长窗口的别名）', (int)($two['d7'] ?? -1) === 0, J($two));
$three = $map[$T3] ?? [];
check('没人碰过的队：三档全 0', (int)($three['today'] ?? -1) === 0
    && (int)($three['d7'] ?? -1) === 0 && (int)($three['d30'] ?? -1) === 0, J($three));
$four = $map[$T4] ?? [];
check('只有待审账号的队：三档全 0、成员 1（站长在职但从没连过，待审那位不算）', (int)($four['today'] ?? -1) === 0
    && (int)($four['members'] ?? -1) === 1, J($four));
check('待审站点（未开通）根本不在列表里', !isset($map[5]) && count($map) === 4, (string)count($map));

// ============================================================
// 3. 最近一次 / 空闲天数
// ============================================================
echo "\n== 3. 最近一次有人用 ==\n";
check('最近一次就是刚才那一刻（不是"今天 00:00"那种粗粒度）',
    ($one['last_at'] ?? '') === $now, (string)($one['last_at'] ?? ''));
check('八天前那个队的 idle_days = 8', (int)($two['idle_days'] ?? -1) === 8, J($two));
check('从没用过的队：last_at 是空串、idle_days 是 null（不是 0）',
    ($three['last_at'] ?? 'x') === '' && $three['idle_days'] === null, J($three));
check('没成员也只有待审的队：last_at 与 idle_days 都是空', ($four['last_at'] ?? 'x') === ''
    && $four['idle_days'] === null, J($four));

// ============================================================
// 4. 汇总与排序
// ============================================================
echo "\n== 4. 汇总和排序 ==\n";
check('汇总：今天有动静 1 队 / 7 天 1 队 / 30 天 2 队 / 站点共 4 个',
    (int)($d['live']['today'] ?? -1) === 1 && (int)($d['live']['d7'] ?? -1) === 1
    && (int)($d['live']['d30'] ?? -1) === 2 && (int)($d['sites'] ?? -1) === 4,
    J([$d['live'] ?? null, $d['sites'] ?? null]));
$order = array_column($d['list'] ?? [], 'id');
check('排序：今天有动静的排第一、八天前那个第二、两个没动静的沉底',
    $order === [$T1, $T2, $T3, $T4], J($order));
check('响应带着 as_of 与一句口径说明',
    ($d['as_of'] ?? '') !== '' && str_contains((string)($d['note'] ?? ''), '自动轮询'), J($d['note'] ?? ''));

// ============================================================
// 5. 不出人名
// ============================================================
echo "\n== 5. 只有数字，没有人名 ==\n";
$dirty = [];
foreach (['一队站长', '今天来过', '三天前来过', '八天前来过', 'av1boss', 'av1u1', 'av2u1', '待审核的', '被封禁的'] as $needle) {
    if (str_contains($raw, $needle)) { $dirty[] = $needle; }
}
check('响应里一个昵称 / 登录名都不带', $dirty === [], implode('，', $dirty));
check('也没有 nickname / username 这些键', !str_contains($raw, '"nickname"') && !str_contains($raw, '"username"'));

// ============================================================
// 6. 零 DDL
// ============================================================
echo "\n== 6. 这一版不动库 ==\n";
$names = array_column(q("SELECT name FROM sqlite_master WHERE type='table'"), 'name');
$newTables = array_values(array_filter($names, static fn($n) => (bool)preg_match('/active|visit|online_log|daily/i', (string)$n)));
check('没有为这一页新建任何表', $newTables === [], implode(',', $newTables));
$keys = array_column(q("SELECT key FROM settings WHERE tenant_id = 0 AND key LIKE '%active%'"), 'key');
check('没往 settings 插键', $keys === [], implode(',', $keys));

// ============================================================
// 7. 回归
// ============================================================
echo "\n== 7. 没把别的东西带崩 ==\n";
[$s, $j] = as_req('plat', 'GET', '/api/platform/admin/forum-api');
check('平台「论坛对接」照常', $s === 200 && ($j['code'] ?? 1) === 0, "HTTP $s");
[$s, $j] = as_req('plat', 'GET', '/api/platform/admin/tenants');
check('平台「已开通站点」照常', $s === 200 && ($j['code'] ?? 1) === 0, "HTTP $s");
[$s, $j] = as_req('plat', 'GET', '/api/platform/admin/log-stats');
check('平台「日志清理」照常', $s === 200 && ($j['code'] ?? 1) === 0, "HTTP $s");
[$s, $j] = as_req('av1', 'GET', '/av1/api/admin/export');
check('战队「导出表格」照常', $s === 200 && ($j['code'] ?? 1) === 0, "HTTP $s");
[$s, $j] = as_req('av1', 'GET', '/av1/api/admin/forum-api');
check('战队「论坛对接」照常', $s === 200 && ($j['code'] ?? 1) === 0, "HTTP $s");
$anon = [];
[$s, , $raw2] = req('GET', $BASE . '/', null, $anon);
check('官网首页照常', $s === 200 && strlen($raw2) > 2000, "HTTP $s");

// ============================================================
// 8. 前端
// ============================================================
echo "\n== 8. 平台那一页与产物 ==\n";
$pj = (string)file_get_contents($ROOT . '/public/static/js/platform.jsx');
foreach (["['active', '站点活跃']", "['apps', 'tenants', 'purged', 'active', 'showcase', 'rotation']", 'function PlatActive()',
          "'/api/platform/admin/active'", '{tab === \'active\' && <PlatActive />}'] as $n) {
    check("platform.jsx 里有 {$n}", str_contains($pj, $n));
}
check('页面写着「不列成员昵称」（这一页的边界）', str_contains($pj, '不列成员昵称'));
$pjs = (string)file_get_contents($ROOT . '/public/static/js/platform.js');
check('编译产物 platform.js 里有这一页', str_contains($pjs, 'PlatActive') && str_contains($pjs, '站点活跃'));
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
