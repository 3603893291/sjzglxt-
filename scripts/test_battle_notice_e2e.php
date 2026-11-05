<?php
/**
 * 约战成立自动公告 + 管理员强制弹窗（v5.13.0）功能 E2E 测试
 *
 * 前置：测试服务器已启动
 *   php -S 127.0.0.1:8899 -t public public/router.php      （.env 指向 sqlite）
 * 覆盖：
 *   1. 数据源权限：未登录 / 普通成员 / 无 battle 权限的副管理员都拿不到待同意列表
 *   2. 可见范围：只有「本队是被约战方(b) 且仍 pending」的单子会弹；自己发起的、无关队都不弹；过期惰性
 *   3. 成立自动公告：接受后双方各一条本队公告，need_receipt=1、对手与开赛时间进正文、两队内容互不串
 *   4. 成员端：各自只看到本队那条并计入未读；打开即写已读
 *   5. 幂等：重复接受被状态机挡下，公告不会翻倍
 *   6. 本队开关：关掉的那队不发，另一队照发；开关读写只影响本队
 *   7. 拒绝 / 取消路径不发公告
 *   8. 回归：约战列表、公告管理编辑既有公告不受影响
 *
 * 用法：php scripts/test_battle_notice_e2e.php
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

// ---------- HTTP（内存维护 Cookie，避开 curl cookie jar 回写滞后） ----------
function take_cookies(array $headers, array &$cookies): void {
    foreach ($headers as $h) {
        if (preg_match('/^Set-Cookie:\s*([^=]+)=([^;]*)/i', $h, $m)) { $cookies[$m[1]] = trim($m[2]); }
    }
}
function status_of(array $headers): int {
    $s = 0;
    foreach ($headers as $h) { if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) { $s = (int)$m[1]; } }
    return $s;
}
function cookie_hdr(array &$cookies): string {
    if (!$cookies) return '';
    $pairs = [];
    foreach ($cookies as $k => $v) { $pairs[] = $k . '=' . $v; }
    return 'Cookie: ' . implode('; ', $pairs) . "\r\n";
}
/** @return array{0:int,1:array} [状态码, JSON] */
function req(string $method, string $url, ?array $body, array &$cookies): array {
    $hdr = "Content-Type: application/json\r\n" . cookie_hdr($cookies);
    $opts = ['http' => ['method' => $method, 'header' => $hdr, 'ignore_errors' => true, 'timeout' => 15]];
    if ($body !== null) { $opts['http']['content'] = json_encode($body, JSON_UNESCAPED_UNICODE); }
    $raw = @file_get_contents($url, false, stream_context_create($opts)) ?: '';
    take_cookies($http_response_header ?? [], $cookies);
    $json = json_decode((string)$raw, true);
    return [status_of($http_response_header ?? []), is_array($json) ? $json : ['raw' => (string)$raw]];
}
function J(array $a): string { return json_encode($a, JSON_UNESCAPED_UNICODE); }

$jars = [];
/** 取（并复用）某个身份的 Cookie 数组 */
function jar(string $who): array { global $jars; if (!isset($jars[$who])) { $jars[$who] = []; } return $jars[$who]; }
function put_jar(string $who, array $c): void { global $jars; $jars[$who] = $c; }
/** 以某身份请求站内路径 */
function as_req(string $who, string $method, string $path, ?array $body = null): array {
    global $BASE;
    $c = jar($who);
    [$s, $j] = req($method, $BASE . $path, $body, $c);
    put_jar($who, $c);
    return [$s, $j];
}
function login(string $who, string $slug, string $user, string $pwd): array {
    return as_req($who, 'POST', "/$slug/api/auth/login", ['username' => $user, 'password' => $pwd]);
}

// ---------- 直连库断言 ----------
function db(): PDO { global $DB; $p = new PDO('sqlite:' . $DB); $p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); return $p; }
function auto_anns(int $tid): array {
    $st = db()->prepare("SELECT * FROM announcements WHERE tenant_id = ? AND title LIKE '【约战成立】%' ORDER BY id ASC");
    $st->execute([$tid]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}
function ann_count(): int { return (int)db()->query('SELECT COUNT(*) FROM announcements')->fetchColumn(); }
function battle_row(int $id): ?array {
    $st = db()->prepare('SELECT * FROM battles WHERE id = ?'); $st->execute([$id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r === false ? null : $r;
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
$mkUser = function (int $tid, string $username, string $pwd, string $nick, string $role, array $perms = []) use ($pdo, $now) {
    $hash   = password_hash($pwd, PASSWORD_DEFAULT);
    $permsJ = $perms ? json_encode($perms, JSON_UNESCAPED_UNICODE) : '';
    $pdo->exec("INSERT INTO users (tenant_id, username, password, nickname, role, status, admin_perms, created_at, updated_at)
                VALUES ($tid, '$username', '$hash', '$nick', '$role', 'approved', '$permsJ', '$now', '$now')");
    return (int)$pdo->lastInsertId();
};

$T1 = $mkTenant('bn1', '约战发起战队', 'bn1owner');
$T2 = $mkTenant('bn2', '约战应战战队', 'bn2owner');
$T3 = $mkTenant('bn3', '无关战队', 'bn3owner');
$u1owner  = $mkUser($T1, 'bn1owner', 'owner123456', '发起队站长', 'admin');
$u1mem    = $mkUser($T1, '100040001', 'member123456', '发起队成员', 'user');
$u2owner  = $mkUser($T2, 'bn2owner', 'owner123456', '应战队站长', 'admin');
$u2battle = $mkUser($T2, 'bn2battle', 'owner123456', '约战管理员', 'admin', ['battle']);
$u2plain  = $mkUser($T2, 'bn2plain', 'owner123456', '点名管理员', 'admin', ['member_view']);
$u2mem    = $mkUser($T2, '100040002', 'member123456', '应战队成员', 'user');
$u3owner  = $mkUser($T3, 'bn3owner', 'owner123456', '无关队站长', 'admin');
$u3mem    = $mkUser($T3, '100040003', 'member123456', '无关队成员', 'user');
$pdo->exec("UPDATE tenants SET admin_user_id = $u1owner WHERE id = $T1");
$pdo->exec("UPDATE tenants SET admin_user_id = $u2owner WHERE id = $T2");
$pdo->exec("UPDATE tenants SET admin_user_id = $u3owner WHERE id = $T3");
check('种子：3 个战队 + 各身份管理员与成员', $T1 > 0 && $T2 > 0 && $T3 > 0 && $u2battle > 0);

foreach ([
    'o1' => ['bn1', 'bn1owner', 'owner123456'],
    'm1' => ['bn1', '100040001', 'member123456'],
    'o2' => ['bn2', 'bn2owner', 'owner123456'],
    'b2' => ['bn2', 'bn2battle', 'owner123456'],
    'p2' => ['bn2', 'bn2plain', 'owner123456'],
    'm2' => ['bn2', '100040002', 'member123456'],
    'o3' => ['bn3', 'bn3owner', 'owner123456'],
    'm3' => ['bn3', '100040003', 'member123456'],
] as $who => $cfg) {
    [$s, $j] = login($who, $cfg[0], $cfg[1], $cfg[2]);
    check("登录 $who（{$cfg[1]}）", ($j['code'] ?? 1) === 0, J($j));
}

$PEND = '/bn2/api/admin/battles/pending';

// ============================================================
// 1. 待同意列表的权限
// ============================================================
echo "\n== 1. 谁能看到待同意约战 ==\n";
$anon = [];
[$s, $j] = req('GET', $BASE . $PEND, null, $anon);
check('未登录读待同意列表 → 401', ($j['code'] ?? 0) === 401 || $s === 401, "HTTP $s " . J($j));
[$s, $j] = as_req('m2', 'GET', $PEND);
check('普通成员读待同意列表被拒', ($j['code'] ?? 0) === 403 || ($j['code'] ?? 0) === 401, J($j));
[$s, $j] = as_req('p2', 'GET', $PEND);
check('只有 member_view 的副管理员无 battle 权限 → 403', ($j['code'] ?? 0) === 403, J($j));
[$s, $j] = as_req('b2', 'GET', $PEND);
check('持 battle 权限的副管理员可读', ($j['code'] ?? 1) === 0 && isset($j['data']['list']), J($j));
check('站长（真超管）也可读', (as_req('o2', 'GET', $PEND)[1]['code'] ?? 1) === 0);
[$s, $j] = as_req('p2', 'POST', '/bn2/api/admin/battle-notice', ['enabled' => 0]);
check('无 battle 权限的管理员不能改自动公告开关', ($j['code'] ?? 0) === 403, J($j));

// ============================================================
// 2. 发起 → 只有被约战方会弹
// ============================================================
echo "\n== 2. 待同意列表的可见范围 ==\n";
check('开局前双方待同意列表都是空的',
    (as_req('b2', 'GET', $PEND)[1]['data']['list'] ?? null) === []
    && (as_req('o1', 'GET', '/bn1/api/admin/battles/pending')[1]['data']['list'] ?? null) === []);

$future = date('Y-m-d H:i:s', time() + 6 * 3600);
[$s, $j] = as_req('o1', 'POST', '/bn1/api/admin/battles', [
    'title' => '周末全面战场 BO3', 'defender_type' => 'tenant', 'defender_id' => $T2,
    'series_format' => 'bo3', 'battle_type' => 'training', 'battle_time' => $future,
    'game_mode' => '全面战场 攻防', 'roster_limit' => 12, 'room' => '', 'remark' => '晚上八点集合',
    'room_info' => 'YY 12345',
]);
$B1 = (int)($j['data']['id'] ?? 0);
check('发起队站长向应战队发起约战', ($j['code'] ?? 1) === 0 && $B1 > 0, J($j));

[$s, $j] = as_req('b2', 'GET', $PEND);
$list = $j['data']['list'] ?? [];
check('应战队的待同意列表出现这一条', count($list) === 1 && (int)$list[0]['id'] === $B1, J($j));
$b = $list[0] ?? [];
check('弹窗所需字段齐：对手队名 / 开赛时间 / 赛制 / 类型 / 人数上限',
    ($b['challenger'] ?? '') === '约战发起战队' && ($b['series_format'] ?? '') === 'bo3'
    && ($b['battle_type'] ?? '') === 'training' && (int)$b['roster_limit'] === 12
    && (string)($b['battle_time'] ?? '') === $future, J($b));
check('发起方自己不会被这条弹窗（他不是在等谁同意）',
    (as_req('o1', 'GET', '/bn1/api/admin/battles/pending')[1]['data']['list'] ?? ['x']) === []);
check('无关战队看不到', (as_req('o3', 'GET', '/bn3/api/admin/battles/pending')[1]['data']['list'] ?? ['x']) === []);

// 过期惰性：把另一场 pending 的开赛时间改到过去，读取待同意列表时应顺手置过期
$pdo->exec("INSERT INTO battles (title, game_mode, battle_time, room_info, remark, challenger_type, challenger_id,
    defender_type, defender_id, status, battle_type, series_format, roster_limit, created_by, created_at, updated_at)
    VALUES ('过期单', '', '" . date('Y-m-d H:i:s', time() - 3600) . "', '', '', 'tenant', $T1, 'tenant', $T2,
    'pending', 'official', 'bo1', 20, 'seed', '$now', '$now')");
$deadId = (int)$pdo->lastInsertId();
[$s, $j] = as_req('b2', 'GET', $PEND);
$ids = array_column($j['data']['list'] ?? [], 'id');
check('开赛时间已过的待同意单不再弹窗', count($ids) === 1 && !in_array($deadId, $ids), J($ids));
check('过期单被惰性置为 expired', (string)(battle_row($deadId)['status'] ?? '') === 'expired',
    (string)(battle_row($deadId)['status'] ?? ''));

// ============================================================
// 3. 接受 → 双方各一条本队公告
// ============================================================
echo "\n== 3. 约战成立自动发公告 ==\n";
$before = ann_count();
[$s, $j] = as_req('b2', 'POST', '/bn2/api/admin/battles/' . $B1 . '/accept');
check('应战队管理员接受约战', ($j['code'] ?? 1) === 0, J($j));
check('公告总数正好 +2（双方各一条）', ann_count() === $before + 2, "before=$before now=" . ann_count());
$a2 = auto_anns($T2); $a1 = auto_anns($T1);
check('应战队有 1 条、发起队有 1 条', count($a2) === 1 && count($a1) === 1, count($a2) . '/' . count($a1));
$r2 = $a2[0] ?? []; $r1 = $a1[0] ?? [];
check('公告标记为需已读回执', (int)$r2['need_receipt'] === 1 && (int)$r1['need_receipt'] === 1,
    $r2['need_receipt'] . '/' . $r1['need_receipt']);
check('公告不带配图、不设回执截止、未被提醒过',
    (string)$r2['image'] === '' && $r2['deadline'] === null && $r2['reminded_at'] === null, J($r2));
check('标题含约战主题与对手队名（应战队侧看到发起队名）',
    strpos((string)$r2['title'], '周末全面战场 BO3') !== false
    && strpos((string)$r2['title'], '约战发起战队') !== false, (string)$r2['title']);
check('标题含对手队名（发起队侧看到应战队名）',
    strpos((string)$r1['title'], '约战应战战队') !== false, (string)$r1['title']);
$c2 = (string)$r2['content'];
check('正文有开赛时间 / 赛制 / 训练赛 / 房间 / 备注 / 人数上限',
    strpos($c2, date('Y-m-d', time() + 6 * 3600)) !== false && strpos($c2, 'BO3') !== false
    && strpos($c2, '训练赛') !== false && strpos($c2, 'YY 12345') !== false
    && strpos($c2, '晚上八点集合') !== false && strpos($c2, '上限 12 人') !== false, $c2);
check('正文指引到成员报名入口并说明需已读',
    strpos($c2, '跨战队约战') !== false && strpos($c2, '已读') !== false, $c2);
check('两队的公告内容不会串（各自写自己那条，正文里对手相反）',
    strpos($c2, '约战发起战队') !== false && strpos($c2, '约战应战战队') === false
    && strpos((string)$r1['content'], '约战应战战队') !== false);
check('约战状态已是 accepted', (string)battle_row($B1)['status'] === 'accepted');
check('处理完应战队待同意列表重新为空（弹窗自然消失）',
    (as_req('b2', 'GET', $PEND)[1]['data']['list'] ?? ['x']) === []);

// ============================================================
// 4. 幂等
// ============================================================
echo "\n== 4. 重复接受不会重复发公告 ==\n";
$cnt = ann_count();
[$s, $j] = as_req('b2', 'POST', '/bn2/api/admin/battles/' . $B1 . '/accept');
check('已成立的约战再次接受被挡下', ($j['code'] ?? 0) !== 0 && ($j['msg'] ?? '') !== '', J($j));
check('公告条数没有增加', ann_count() === $cnt, "cnt=$cnt now=" . ann_count());
check('应战队侧仍只有 1 条自动公告', count(auto_anns($T2)) === 1);

// ============================================================
// 5. 成员端可见性与已读
// ============================================================
echo "\n== 5. 成员端只看到本队那条 ==\n";
[$s, $j] = as_req('m2', 'GET', '/bn2/api/notices');
$rows = $j['data']['list'] ?? [];
$hit = null;
foreach ($rows as $r) { if (strpos((string)$r['title'], '【约战成立】') === 0) { $hit = $r; } }
check('应战队成员能读到本队的成立公告（tenant_id 是本队）',
    $hit !== null && (int)$hit['tenant_id'] === $T2,
    $hit === null ? J($j['data'] ?? $j) : 'tenant_id=' . $hit['tenant_id']);
check('该公告对成员显示为未读且计入未读数',
    $hit !== null && (int)$hit['need_receipt'] === 1 && (int)$hit['is_read'] === 0 && (int)($j['data']['unread_count'] ?? 0) >= 1,
    $hit === null ? '' : J($hit));
[$s, $j] = as_req('m1', 'GET', '/bn1/api/notices');
$mine = [];
foreach (($j['data']['list'] ?? []) as $r) {
    if (strpos((string)$r['title'], '【约战成立】') === 0) { $mine[] = (int)$r['tenant_id']; }
}
check('发起队成员也只看到一条成立公告，且是自己队那条',
    $mine === [$T1], J($mine) . ' 期望 [' . $T1 . ']');
[$s, $j] = as_req('m3', 'GET', '/bn3/api/notices');
$none3 = true;
foreach (($j['data']['list'] ?? []) as $r) { if (strpos((string)$r['title'], '【约战成立】') === 0) { $none3 = false; } }
check('无关战队成员完全看不到', $none3);
if ($hit !== null) {
    [$s, $j] = as_req('m2', 'POST', '/bn2/api/notices/' . (int)$hit['id'] . '/read');
    check('成员打开公告即记已读', ($j['code'] ?? 1) === 0, J($j));
    $n = (int)$pdo->query('SELECT COUNT(*) FROM announcement_reads WHERE announcement_id = ' . (int)$hit['id'])->fetchColumn();
    check('announcement_reads 落库一行', $n === 1, "n=$n");
    // 管理端回执统计能看到未读名单（自动公告同样纳入统计）
    [$s, $j] = as_req('o2', 'GET', '/bn2/api/admin/announcements/' . (int)$hit['id'] . '/receipt');
    check('管理员可查看这条自动公告的已读/未读回执',
        ($j['code'] ?? 1) === 0
        && count($j['data']['unread_list'] ?? []) >= 1
        && count($j['data']['read_list'] ?? []) >= 1, J($j['data'] ?? $j));
}

// ============================================================
// 6. 本队开关
// ============================================================
echo "\n== 6. 站长可关：本队开关默认开 ==\n";
[$s, $j] = as_req('b2', 'GET', '/bn2/api/admin/battle-notice');
check('默认状态为开', ($j['code'] ?? 1) === 0 && ($j['data']['enabled'] ?? false) === true, J($j));
[$s, $j] = as_req('b2', 'POST', '/bn2/api/admin/battle-notice', ['enabled' => 0]);
check('关闭后回显 false', ($j['code'] ?? 1) === 0 && ($j['data']['enabled'] ?? true) === false, J($j));
[$s, $j] = as_req('b2', 'GET', '/bn2/api/admin/battle-notice');
check('开关值已持久化（不是只改前端）', ($j['data']['enabled'] ?? true) === false, J($j));
[$s, $j] = as_req('o1', 'GET', '/bn1/api/admin/battle-notice');
check('关的是本队，发起队仍是开', ($j['data']['enabled'] ?? false) === true, J($j));

// 上一场已成立、仍在进行中——同双方进行中的约战唯一（既有规则），先由发起方取消它
[$s, $j] = as_req('o1', 'POST', '/bn1/api/admin/battles/' . $B1 . '/cancel');
check('前置：发起方取消上一场已成立的约战（腾出同双方唯一位）', ($j['code'] ?? 1) === 0, J($j));

// 再来一场：这次应战队发起、发起队应战并同意
[$s, $j] = as_req('o2', 'POST', '/bn2/api/admin/battles', [
    'title' => '反向约战', 'defender_type' => 'tenant', 'defender_id' => $T1,
    'series_format' => 'bo1', 'battle_type' => 'official',
    'battle_time' => date('Y-m-d H:i:s', time() + 86400), 'roster_limit' => 0,
]);
$B2 = (int)($j['data']['id'] ?? 0);
check('第二场约战发起成功（应战队→发起队）', ($j['code'] ?? 1) === 0 && $B2 > 0, J($j));
$cnt = ann_count();
[$s, $j] = as_req('o1', 'GET', '/bn1/api/admin/battles/pending');
check('待同意列表落在被约战的一方（这次是发起队）',
    count($j['data']['list'] ?? []) === 1 && (int)$j['data']['list'][0]['id'] === $B2, J($j));
[$s, $j] = as_req('o1', 'POST', '/bn1/api/admin/battles/' . $B2 . '/accept');
check('发起队接受约战', ($j['code'] ?? 1) === 0, J($j));
check('关闭开关的那队没发公告，另一队照发',
    count(auto_anns($T2)) === 1 && count(auto_anns($T1)) === 2,
    'T2=' . count(auto_anns($T2)) . ' T1=' . count(auto_anns($T1)));
check('开关未打开时公告总数只 +1', ann_count() === $cnt + 1, "cnt=$cnt now=" . ann_count());
$tx = auto_anns($T1);
$lastT1 = end($tx);
check('人数上限 0（不限）时正文不写出上限',
    strpos((string)$lastT1['content'], '上限') === false, (string)$lastT1['content']);
[$s, $j] = as_req('b2', 'POST', '/bn2/api/admin/battle-notice', ['enabled' => 1]);
check('开关可再打开', ($j['code'] ?? 1) === 0 && ($j['data']['enabled'] ?? false) === true, J($j));

// ============================================================
// 7. 拒绝 / 取消不发公告
// ============================================================
echo "\n== 7. 拒绝与取消都不发公告 ==\n";
[$s, $j] = as_req('o1', 'POST', '/bn1/api/admin/battles', [
    'title' => '会被拒绝的约战', 'defender_type' => 'tenant', 'defender_id' => $T3,
    'series_format' => 'bo3', 'battle_type' => 'official',
    'battle_time' => date('Y-m-d H:i:s', time() + 7200), 'roster_limit' => 8,
]);
$B3 = (int)($j['data']['id'] ?? 0);
check('第三场约战发起成功（发起队→无关队）', ($j['code'] ?? 1) === 0 && $B3 > 0, J($j));
check('无关队也会收到这条待同意弹窗',
    (int)(as_req('o3', 'GET', '/bn3/api/admin/battles/pending')[1]['data']['list'][0]['id'] ?? 0) === $B3);
$cnt = ann_count();
[$s, $j] = as_req('o3', 'POST', '/bn3/api/admin/battles/' . $B3 . '/reject');
check('被约战队站长拒绝约战', ($j['code'] ?? 1) === 0, J($j));
check('拒绝后双方都没有新公告', ann_count() === $cnt, "cnt=$cnt now=" . ann_count());
check('拒绝后不再弹窗', (as_req('o3', 'GET', '/bn3/api/admin/battles/pending')[1]['data']['list'] ?? ['x']) === []);
[$s, $j] = as_req('o3', 'POST', '/bn3/api/admin/battles/' . $B3 . '/accept');
check('已拒绝的约战不能再被接受', ($j['code'] ?? 0) !== 0, J($j));

[$s, $j] = as_req('o1', 'POST', '/bn1/api/admin/battles', [
    'title' => '会被撤回的约战', 'defender_type' => 'tenant', 'defender_id' => $T3,
    'series_format' => 'bo1', 'battle_type' => 'training',
    'battle_time' => date('Y-m-d H:i:s', time() + 7200), 'roster_limit' => 8,
]);
$B4 = (int)($j['data']['id'] ?? 0);
check('第四场约战发起成功', ($j['code'] ?? 1) === 0 && $B4 > 0, J($j));
$cnt = ann_count();
[$s, $j] = as_req('o1', 'POST', '/bn1/api/admin/battles/' . $B4 . '/cancel');
check('发起方撤回 pending 约战', ($j['code'] ?? 1) === 0, J($j));
check('撤回同样不发公告', ann_count() === $cnt, "cnt=$cnt now=" . ann_count());
check('撤回后不再弹窗', (as_req('o3', 'GET', '/bn3/api/admin/battles/pending')[1]['data']['list'] ?? ['x']) === []);

// ============================================================
// 8. 回归
// ============================================================
echo "\n== 8. 既有功能回归 ==\n";
[$s, $j] = as_req('o1', 'GET', '/bn1/api/admin/battles');
$stat = [];
foreach (($j['data']['list'] ?? []) as $r) { $stat[(int)$r['id']] = (string)($r['status'] ?? ''); }
check('约战管理列表仍正常（成立过的显示 accepted、被取消的显示 canceled）',
    ($j['code'] ?? 1) === 0 && ($stat[$B2] ?? '') === 'accepted' && ($stat[$B1] ?? '') === 'canceled', J($stat));
[$s, $j] = as_req('o1', 'GET', '/bn1/api/admin/announcements');
$auto = null;
foreach (($j['data']['list'] ?? []) as $r) { if (strpos((string)$r['title'], '【约战成立】') === 0) { $auto = $r; } }
check('公告管理里能看到并管理自动公告', $auto !== null && (int)$auto['need_receipt'] === 1, J($j['data'] ?? $j));
if ($auto !== null) {
    [$s, $j] = as_req('o1', 'POST', '/bn1/api/admin/announcements/save', [
        'id' => (int)$auto['id'], 'title' => (string)$auto['title'], 'content' => '手动补一句：迟到就别报名了',
    ]);
    check('自动公告可被管理员编辑（与普通公告同权）', ($j['code'] ?? 1) === 0, J($j));
    [$s, $j] = as_req('o1', 'DELETE', '/bn1/api/admin/announcements/' . (int)$auto['id']);
    check('自动公告也可被删除', ($j['code'] ?? 1) === 0, J($j));
}
[$s, $j] = as_req('m1', 'GET', '/bn1/api/battles');
check('成员端约战看板接口正常', ($j['code'] ?? 1) === 0, J($j));
[$s, $j] = as_req('m2', 'GET', '/bn2/api/auth/me');
check('成员身份接口未受影响', ($j['code'] ?? 1) === 0 && ($j['data']['role'] ?? '') === 'user', J($j['data'] ?? $j));

echo "\n================ 结果 ================\n";
echo "通过 $pass 项";
if ($fail > 0) {
    echo "，失败 $fail 项：\n  - " . implode("\n  - ", $failures) . "\n";
    exit(1);
}
echo "，全部通过\n";
exit(0);
