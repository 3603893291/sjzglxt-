<?php
/**
 * 仪表盘「在线成员」卡片（v5.14.0）功能 E2E 测试
 *
 * 前置：测试服务器已启动
 *   php -S 127.0.0.1:8899 -t public public/router.php      （.env 指向 sqlite）
 * 覆盖：
 *   1. 口径：在线判定沿用 5 分钟窗口，人数与名单同源、管理员+成员=总数
 *   2. 谁算在线：刚活跃/4 分钟前在线，6 分钟前与 31 分钟前不算；待审核与封禁一律不出现
 *   3. 排序：管理员排在成员前面，同组内按最近活跃
 *   4. 脱敏：只出昵称；无昵称的 QQ 打码、无昵称的邮箱只留首字符；
 *      响应里不出现账号明文、密码、邮箱、注册 IP、封禁原因等任何其它字段
 *   5. 隔离：本队名单不含别的战队的成员
 *   6. 上限：超过 200 人在线时名单截断到 200 且 online_truncated=true，人数与名单仍然一致
 *   7. 回归：/api/dashboard 既有字段（注册人数/公告）与其它仪表盘接口不受影响
 *
 * 用法：php scripts/test_online_card_e2e.php
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
/** 详情文本：容忍标量与 null，别让断言辅助函数自己先炸 */
function J($a): string { return json_encode($a, JSON_UNESCAPED_UNICODE); }

// ---------- HTTP（内存维护 Cookie） ----------
function take_cookies(array $headers, array &$cookies): void {
    foreach ($headers as $h) {
        if (preg_match('/^Set-Cookie:\s*([^=]+)=([^;]*)/i', $h, $m)) { $cookies[$m[1]] = trim($m[2]); }
    }
}
function cookie_hdr(array &$cookies): string {
    if (!$cookies) return '';
    $pairs = [];
    foreach ($cookies as $k => $v) { $pairs[] = $k . '=' . $v; }
    return 'Cookie: ' . implode('; ', $pairs) . "\r\n";
}
/** @return array{0:int,1:array,2:string} [状态码, JSON, 原始文本] */
function req(string $method, string $url, ?array $body, array &$cookies): array {
    $hdr = "Content-Type: application/json\r\n" . cookie_hdr($cookies);
    $opts = ['http' => ['method' => $method, 'header' => $hdr, 'ignore_errors' => true, 'timeout' => 20]];
    if ($body !== null) { $opts['http']['content'] = json_encode($body, JSON_UNESCAPED_UNICODE); }
    $raw = @file_get_contents($url, false, stream_context_create($opts)) ?: '';
    take_cookies($http_response_header ?? [], $cookies);
    $json = json_decode((string)$raw, true);
    return [0, is_array($json) ? $json : ['raw' => (string)$raw], (string)$raw];
}

$jars = [];
function jar(string $who): array { global $jars; if (!isset($jars[$who])) { $jars[$who] = []; } return $jars[$who]; }
function as_req(string $who, string $method, string $path, ?array $body = null): array {
    global $BASE, $jars;
    $c = jar($who);
    [$s, $j, $raw] = req($method, $BASE . $path, $body, $c);
    $jars[$who] = $c;
    return [$j, $raw];
}

// ---------- 直连库 ----------
function db(): PDO { global $DB; $p = new PDO('sqlite:' . $DB); $p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); return $p; }
function set_active(int $uid, int $agoSec): void {
    db()->exec('UPDATE users SET last_active_at = ' . db()->quote(date('Y-m-d H:i:s', time() - $agoSec)) . ' WHERE id = ' . $uid);
}

// ============================================================
// 0. 重建测试库 + 种子
// ============================================================
echo "== 0. 重建测试库 ==\n";
@unlink($DB);
echo shell_exec('"' . PHP_BINARY . '" "' . $ROOT . '/scripts/dev_init_sqlite.php" "' . $DB . '" 2>&1');

$pdo = db();
$now = date('Y-m-d H:i:s');
$mkTenant = function (string $slug, string $name, string $ownerName) use ($pdo, $now) {
    $pdo->exec("INSERT INTO tenants (applicant_id, slug, name, status, admin_username, admin_user_id, created_at, updated_at)
                VALUES (1, '$slug', '$name', 'approved', '$ownerName', NULL, '$now', '$now')");
    return (int)$pdo->lastInsertId();
};
$mkUser = function (int $tid, string $username, string $pwd, string $nick, string $role,
                    string $status = 'approved', ?int $ago = null, string $email = '', string $ip = '') use ($pdo, $now) {
    $hash = password_hash($pwd, PASSWORD_DEFAULT);
    $act  = $ago === null ? 'NULL' : db()->quote(date('Y-m-d H:i:s', time() - $ago));
    $pdo->exec("INSERT INTO users (tenant_id, username, password, nickname, email, role, status, reg_ip,
                                  last_active_at, created_at, updated_at)
                VALUES ($tid, " . db()->quote($username) . ", '$hash', " . db()->quote($nick) . ", " . db()->quote($email) . ",
                        '$role', '$status', " . db()->quote($ip) . ", $act, '$now', '$now')");
    return (int)$pdo->lastInsertId();
};

$TA = $mkTenant('ocmain', '在线主测战队', 'ocowner');
$TB = $mkTenant('uchother', '隔壁战队', 'ucowner');
$TC = $mkTenant('ocbig', '超员战队', 'ocbigowner');

$uOwner  = $mkUser($TA, 'ocowner', 'Owner123456', '在线站长', 'admin', 'approved', 5);
$uAdm2   = $mkUser($TA, 'ocadmin2', 'Owner123456', '摸鱼管理', 'admin', 'approved', 240);
$uMem1   = $mkUser($TA, '1000600001', 'Member123456', '活跃成员', 'user', 'approved', 60);
$uMem2   = $mkUser($TA, '1000600002', 'Member123456', '刚下线', 'user', 'approved', 360);
$uNoNick = $mkUser($TA, '1000600003', 'Member123456', '', 'user', 'approved', 120);
$uMail   = $mkUser($TA, 'nicky@example.com', 'Member123456', '', 'user', 'approved', 180, 'nicky@example.com');
$uStale  = $mkUser($TA, '1000600005', 'Member123456', '半小时前', 'user', 'approved', 1860);
$uPend   = $mkUser($TA, '1000600006', 'Member123456', '待审核', 'user', 'pending', 30);
$uBan    = $mkUser($TA, '1000600007', 'Member123456', '被封的', 'user', 'banned', 30, '', '203.0.113.9');
$uNever  = $mkUser($TA, '1000600008', 'Member123456', '从没上过', 'user', 'approved', null);
$uOther  = $mkUser($TB, 'ucowner', 'Owner123456', '隔壁站长', 'admin', 'approved', 10);
$pdo->exec("UPDATE tenants SET admin_user_id = $uOwner WHERE id = $TA");
$pdo->exec("UPDATE tenants SET admin_user_id = $uOther WHERE id = $TB");

$uCBig = $mkUser($TC, 'ocbigowner', 'Owner123456', '超员站长', 'admin', 'approved', 5);
$pdo->exec("UPDATE tenants SET admin_user_id = $uCBig WHERE id = $TC");
for ($i = 0; $i < 205; $i++) {
    $mkUser($TC, 'big' . str_pad((string)$i, 4, '0', STR_PAD_LEFT), 'Member123456',
            '成员' . $i, 'user', 'approved', 20);
}
check('种子：3 个战队、各状态与各种活跃时间的成员（含 205 人的超员战队）',
    $TA > 0 && $TB > 0 && $TC > 0 && $uOwner > 0 && $uCBig > 0);

// ============================================================
// 1. 登录与未登录
// ============================================================
echo "\n== 1. 谁能拿到在线名单 ==\n";
$anon = [];
[, $j, $raw] = req('GET', $BASE . '/ocmain/api/dashboard', null, $anon);
check('未登录访问仪表盘 → 401', ($j['code'] ?? 0) === 401, J($j));
[$j, $raw] = as_req('owner', 'POST', '/ocmain/api/auth/login', ['username' => 'ocowner', 'password' => 'Owner123456']);
check('站长登录', ($j['code'] ?? 1) === 0, J($j));

// ============================================================
// 2. 在线口径
// ============================================================
echo "\n== 2. 谁算在线 ==\n";
// 登录把站长自己刷成"刚刚活跃"，其余人的时间戳是种子里写死的
set_active($uAdm2, 240); set_active($uMem1, 60); set_active($uMem2, 360);
set_active($uNoNick, 120); set_active($uMail, 180); set_active($uStale, 1860);
set_active($uPend, 30); set_active($uBan, 30); set_active($uOther, 10);
[$j, $raw] = as_req('owner', 'GET', '/ocmain/api/dashboard');
$d = $j['data'] ?? [];
check('接口返回 code=0 且带在线名单字段', ($j['code'] ?? 1) === 0
    && isset($d['online_list']) && isset($d['online_admins']) && isset($d['online_members']), J($d));
check('在线窗口仍是 5 分钟（300 秒，与既有「在线人数」同源）', (int)($d['online_window'] ?? 0) === 300, J($d['online_window'] ?? null));
check('人数=名单长度，管理员+成员=总数',
    (int)$d['online_count'] === count($d['online_list'])
    && (int)$d['online_admins'] + (int)$d['online_members'] === (int)$d['online_count'], J($d['online_count']));
$ids = array_column($d['online_list'], 'id');
check('站长（刚登录）在名单里', in_array($uOwner, $ids), J($ids));
check('4 分钟前的管理员算在线', in_array($uAdm2, $ids));
check('1 分钟前的成员算在线', in_array($uMem1, $ids));
check('2 分钟前无昵称成员算在线', in_array($uNoNick, $ids));
check('3 分钟前邮箱账号成员算在线', in_array($uMail, $ids));
check('6 分钟前不算在线（窗口外）', !in_array($uMem2, $ids));
check('31 分钟前不算在线', !in_array($uStale, $ids));
check('从没登录过（last_active 为空）不算在线', !in_array($uNever, $ids));
check('待审核成员即使刚有记录也不出现', !in_array($uPend, $ids));
check('被封禁成员不出现', !in_array($uBan, $ids));
check('响应里也不含那个成员的注册 IP', strpos($raw, '203.0.113.9') === false);
check('总数正好是 5 人（站长+摸鱼管理 两个管理员，活跃/无昵称/邮箱 三个成员）',
    (int)$d['online_count'] === 5 && (int)$d['online_admins'] === 2 && (int)$d['online_members'] === 3,
    J([$d['online_count'], $d['online_admins'], $d['online_members']]));

// ============================================================
// 3. 排序与显示名
// ============================================================
echo "\n== 3. 排序与显示名 ==\n";
$seq = array_column($d['online_list'], 'id');
$adminIdx = []; $memIdx = [];
foreach ($d['online_list'] as $k => $row) {
    if ($row['is_admin']) { $adminIdx[] = $k; } else { $memIdx[] = $k; }
}
check('管理员全部排在成员之前',
    max($adminIdx) < min($memIdx), '管理员位置 ' . J($adminIdx) . ' 成员位置 ' . J($memIdx));
check('同组内按最近活跃排（站长刚登录在最前，其次是 4 分钟前的管理）',
    $seq[0] === $uOwner && $seq[1] === $uAdm2, J($seq));
check('成员组内也是最近活跃在前（1 分钟 > 2 分钟 > 3 分钟）',
    $seq[2] === $uMem1 && $seq[3] === $uNoNick && $seq[4] === $uMail, J($seq));
$names = array_column($d['online_list'], 'name');
check('有昵称的显示昵称', in_array('在线站长', $names) && in_array('活跃成员', $names), J($names));
$nn = null; $mm = null;
foreach ($d['online_list'] as $row) {
    if ((int)$row['id'] === $uNoNick) { $nn = $row['name']; }
    if ((int)$row['id'] === $uMail) { $mm = $row['name']; }
}
check('无昵称的 QQ 被打码（1000600003 → 100*****03 形态，不含完整号码）',
    is_string($nn) && $nn !== '1000600003' && strpos($nn, '*') !== false
    && strpos($nn, '100') === 0 && substr($nn, -2) === '03', (string)$nn);
check('无昵称的邮箱只留首字符', $mm === 'n***', (string)$mm);

// ============================================================
// 4. 脱敏：整个响应里不该出现的字段
// ============================================================
echo "\n== 4. 名单不外泄账号信息 ==\n";
check('响应里没有完整 QQ 号', strpos($raw, '1000600003') === false);
check('响应里没有邮箱账号明文', strpos($raw, 'nicky@example.com') === false);
check('响应里没有管理员账号明文（ocowner）', strpos($raw, 'ocowner') === false);
check('响应里没有 password 字段', strpos($raw, 'password') === false);
check('响应里没有 email / reg_ip / status 等 users 表字段名',
    strpos($raw, '"email"') === false && strpos($raw, '"reg_ip"') === false
    && strpos($raw, '"status"') === false && strpos($raw, '"username"') === false, $raw);
check('名单条目只有 id / name / is_admin 三个键',
    count($d['online_list']) > 0
    && array_keys($d['online_list'][0]) === ['id', 'name', 'is_admin'],
    J(array_keys($d['online_list'][0])));

// ============================================================
// 5. 战队隔离
// ============================================================
echo "\n== 5. 各队只看得到自己人 ==\n";
[$j2, $raw2] = as_req('other', 'POST', '/uchother/api/auth/login', ['username' => 'ucowner', 'password' => 'Owner123456']);
check('隔壁战队站长登录', ($j2['code'] ?? 1) === 0, J($j2));
[$j2, $raw2] = as_req('other', 'GET', '/uchother/api/dashboard');
$d2 = $j2['data'] ?? [];
$ids2 = array_column($d2['online_list'] ?? [], 'id');
check('隔壁战队的在线名单只有自己 1 人',
    ($d2['online_count'] ?? 0) == 1 && $ids2 === [$uOther], J($d2));
check('主测战队的人不出现在隔壁名单里',
    !in_array($uOwner, $ids2) && !in_array($uMem1, $ids2) && strpos($raw2, '在线站长') === false, $raw2);
check('隔壁站长看到的花名是自己战队的昵称', ($ids2[0] ?? 0) === $uOther
    && (string)($d2['online_list'][0]['name'] ?? '') === '隔壁站长', J($d2['online_list'] ?? []));

// ============================================================
// 6. 上限截断
// ============================================================
echo "
== 6. 超过 200 人在线 ==
";
[$j3, $raw3] = as_req('big', 'POST', '/ocbig/api/auth/login', ['username' => 'ocbigowner', 'password' => 'Owner123456']);
check('超员战队站长登录', ($j3['code'] ?? 1) === 0, J($j3));
[$j3, $raw3] = as_req('big', 'GET', '/ocbig/api/dashboard');
$d3 = $j3['data'] ?? [];
check('名单截断到 200 人且给出 truncated 标记',
    ($d3['online_truncated'] ?? false) === true && count($d3['online_list'] ?? []) === 200,
    'len=' . count($d3['online_list'] ?? []) . ' flag=' . var_export($d3['online_truncated'] ?? null, true));
check('截断时 online_count 与名单长度仍一致（两处不会打架）',
    (int)($d3['online_count'] ?? 0) === 200 && (int)$d3['online_admins'] + (int)$d3['online_members'] === 200,
    J([$d3['online_count'] ?? null, $d3['online_admins'] ?? null, $d3['online_members'] ?? null]));
check('站长本人（最近活跃）在截断后的名单里', in_array($uCBig, array_column($d3['online_list'] ?? [], 'id')));

// ============================================================
// 7. 回归
// ============================================================
echo "\n== 7. 既有仪表盘字段与其它接口 ==\n";
check('注册人数仍在，且只算已过审（本队 8 个已过审）',
    (int)($d['user_count'] ?? 0) === 8, 'user_count=' . var_export($d['user_count'] ?? null, true));
check('在线人数不可能超过注册人数', (int)$d['online_count'] <= (int)$d['user_count']);
check('公告字段原样返回（数组、不受本次改动影响）', isset($d['announcements']) && is_array($d['announcements']));
[$jr, $rawr] = as_req('owner', 'GET', '/ocmain/api/races');
check('撞车时间点接口正常', ($jr['code'] ?? 1) === 0 && isset($jr['data']['server_time']), J($jr));
[$jc, $rawc] = as_req('owner', 'GET', '/ocmain/api/custom-races');
check('自建房日程接口正常', ($jc['code'] ?? 1) === 0, J($jc));
[$jn, $rawn] = as_req('owner', 'GET', '/ocmain/api/notices');
check('公告未读接口正常', ($jn['code'] ?? 1) === 0, J($jn));
[$jm, $rawm] = as_req('owner', 'GET', '/ocmain/api/auth/me');
check('身份接口正常且没被这次改动波及', ($jm['code'] ?? 1) === 0 && ($jm['data']['role'] ?? '') === 'admin', J($jm['data'] ?? $jm));

echo "\n================ 结果 ================\n";
echo "通过 $pass 项";
if ($fail > 0) {
    echo "，失败 $fail 项：\n  - " . implode("\n  - ", $failures) . "\n";
    exit(1);
}
echo "，全部通过\n";
exit(0);
