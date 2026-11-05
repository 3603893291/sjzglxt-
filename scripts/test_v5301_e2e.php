<?php
/**
 * v5.30.1 合并包 E2E（成员名录与主页 / 跨队私聊 / 个人黑名单 / 防骚扰 /
 *            举报与禁言归平台 / 四个开关默认开 / 队名「战队」后缀可关）
 *
 * 前置：测试服务器已启动，.env 指向 SQLite（.env 里只能用 ; 注释），库已按
 *       scripts/dev_init_sqlite.php 建好（本脚本第 0 步会自己重建）
 *   php -S 127.0.0.1:8899 -t public public/router.php
 * 用法：php scripts/test_v5301_e2e.php
 *
 * 十条主线，全按「真实 HTTP 请求 + 落库复核」来断言，不读代码当结果：
 *   1. 四个隐私开关默认开（库里默认值 + 接口口径）
 *   2. 成员名录只出本队、只有显示名与在线，不带 QQ 也不带最后活跃时刻
 *   3. 成员主页：战绩按本人开关、跨队看得到人看不到战绩、能不能私聊与能不能拉黑的结论
 *   4. 跨队私聊放开：一条会话键、对方战队名、按昵称外队搜人
 *   5. 防骚扰内置：对方回话前最多 1 条，同队豁免，关不掉
 *   6. 个人黑名单：只拉外队、最多 10 个、只对本人视角生效、双向挡私聊、不通知对方
 *   7. 举报与禁言归平台：来源分明，队长解不掉平台那条，平台也不越俎代庖
 *   8. 左上角队名后缀：默认补「战队」，站长能关，别人关不了
 *   9. 守卫与回归：QQ 不出前端、下线的那九张表不再被建、老功能一条没坏
 *  （v5.32.0：论坛与全站大厅整体下线，原来那条「全站大厅」主线一并撤了）
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
function J($a): string { return json_encode($a, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); }

$jars = [];
function jar(string $who): array { global $jars; if (!isset($jars[$who])) { $jars[$who] = []; } return $jars[$who]; }
/** 返回 [status, json, headers, raw]；Cookie 按身份在内存里维护 */
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
    $opts = ['http' => ['method' => $method, 'header' => $hdr, 'ignore_errors' => true,
        'timeout' => 25, 'follow_location' => 0]];
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
function loginTenant(string $who, string $slug, string $acct): bool {
    [$s, $j] = as_req($who, 'POST', '/' . $slug . '/api/auth/login',
        ['username' => $acct, 'password' => 'pass123456']);
    return (int)($j['code'] ?? 1) === 0;
}
/** 一定要发出去的发送：撞 2 秒限频就等 3 秒再试（最多 6 次），别让限频把后面的断言带偏 */
function sendOk(string $who, string $url, array $body): array {
    for ($i = 0; $i < 6; $i++) {
        [$s, $j] = as_req($who, 'POST', $url, $body);
        if ((int)($j['code'] ?? 1) === 0) { return [$s, $j]; }
        if ((int)($j['code'] ?? 0) !== 429 && (int)$s !== 429) { return [$s, $j]; }
        sleep(3);
    }
    return [$s, $j];
}

// ============================================================
// 0. 重建测试库与种子
// ============================================================
echo "== 0. 重建测试库与种子 ==\n";
@unlink($DB);
echo shell_exec('"' . PHP_BINARY . '" "' . $ROOT . '/scripts/dev_init_sqlite.php" "' . $DB . '" 2>&1');
foreach (glob($ROOT . '/runtime/cache/*/*') ?: [] as $f) { if (is_file($f)) @unlink($f); }
foreach (glob($ROOT . '/runtime/cache/*') ?: [] as $f) { if (is_file($f)) @unlink($f); }

$now  = date('Y-m-d H:i:s');
$pdo  = db();
$hash = password_hash('pass123456', PASSWORD_DEFAULT);

/** 建一支战队：platform_users + tenants + users，people[0] 是站长 */
$mk = function (string $slug, string $name, array $people) use ($pdo, $hash, $now): array {
    $pdo->exec("INSERT INTO platform_users (username,password,nickname,role,status,reg_ip,created_at,updated_at)
        VALUES ('{$slug}boss', '{$hash}', '{$name}联络人', 'team', 'normal', '127.0.0.1', '$now', '$now')");
    $pid = (int)$pdo->lastInsertId();
    $pdo->exec("INSERT INTO tenants (applicant_id, slug, name, status, admin_username, admin_user_id, created_at, updated_at)
        VALUES ($pid, '$slug', '$name', 'approved', '{$people[0]}', NULL, '$now', '$now')");
    $tid = (int)$pdo->lastInsertId();
    $ids = [];
    $last = count($people) - 1;
    foreach ($people as $i => $qq) {
        $role  = $i === 0 ? 'admin' : 'user';
        $perms = '[]';
        $nick  = $slug . '成员' . ($i + 1);
        if ($i === 0) { $nick = $slug . '站长'; }
        $pdo->exec("INSERT INTO users (tenant_id, username, password, nickname, role, status, admin_perms, created_at, updated_at)
            VALUES ($tid, '$qq', '$hash', '$nick', '$role', 'approved', '$perms', '$now', '$now')");
        $ids[$qq] = (int)$pdo->lastInsertId();
        if ($i === $last) {
            // 最后一个挂成「只有 mute 权限的管理员」，用来验「只有站长能改」的那几条
            $uid = $ids[$qq];
            $pdo->exec("UPDATE users SET role='admin', admin_perms='[\"mute\"]', nickname='{$slug}挂名管理' WHERE id=$uid");
        }
    }
    $pdo->exec("UPDATE tenants SET admin_user_id = {$ids[$people[0]]} WHERE id = $tid");
    return ['tid' => $tid, 'ids' => $ids];
};

$A = $mk('v5a', '合并包A队', ['950010001', '950010002', '950010003', '950010004']);
$B = $mk('v5b', '合并包B队', ['950020001', '950020002', '950020003', '950020004']);
$C = $mk('v5c', '合并包C队', ['950030001', '950030002', '950030003', '950030004', '950030005',
    '950030006', '950030007', '950030008', '950030009', '950030010', '950030011', '950030012']);
$aTid = (int)$A['tid']; $bTid = (int)$B['tid'];
$a0 = (int)$A['ids']['950010001'];   // A 站长
$a1 = (int)$A['ids']['950010002'];   // 主角
$a2 = (int)$A['ids']['950010003'];   // A 队友
$b0 = (int)$B['ids']['950020001'];   // B 站长
$b1 = (int)$B['ids']['950020002'];   // 被跨队私聊、后被拉黑的那个
$b2 = (int)$B['ids']['950020003'];
$b3 = (int)$B['ids']['950020004'];   // B 挂名管理
$cIds = array_values($C['ids']);     // 12 个：拿来撑满 10 人黑名单上限
echo "  种子：A tid=$aTid a1=$a1 a2=$a2 ｜ B tid=$bTid b1=$b1 b2=$b2 ｜ C 成员 " . count($cIds) . " 人\n";

// 给 a2 一条战绩行（成员主页的「战绩要不要给队友看」要有东西可看）
$pdo->exec("INSERT INTO player_stats (tenant_id, user_id, game_pid, matches, win_rate, avg_score, kd, score_per_match,
    kills, deaths, created_at, updated_at)
    VALUES ($aTid, $a2, '700020003', 42, 61.0, 12.5, 1.8, 199, 900, 500, '$now', '$now')");

foreach ([['a0', 'v5a', '950010001'], ['a1', 'v5a', '950010002'], ['a2', 'v5a', '950010003'],
          ['b0', 'v5b', '950020001'], ['b1', 'v5b', '950020002'], ['b2', 'v5b', '950020003'],
          ['b3', 'v5b', '950020004'], ['c1', 'v5c', '950030002']] as $p) {
    check('登录 ' . $p[0] . '（' . $p[2] . '）', loginTenant($p[0], $p[1], $p[2]));
}
// A 队那个「只有 mute 权限」的挂名管理
$aSubQq = '950010004'; $aSub = (int)$A['ids'][$aSubQq];
check('登录 a3（A 队挂名管理 ' . $aSubQq . '）', loginTenant('a3', 'v5a', $aSubQq));

[$s, $j] = as_req('plat', 'POST', '/api/platform/auth/login', ['username' => 'admin', 'password' => 'admin123']);
check('平台超管登录', (int)($j['code'] ?? 1) === 0, J($j));

// ============================================================
// 1. 四个开关默认开
// ============================================================
echo "\n== 1. 四个隐私开关默认开 ==\n";
check('新建账号落库就是 show_public=1', (int)db_val("SELECT show_public FROM users WHERE id=$a1") === 1);
check('新建账号落库就是 stats_public=1', (int)db_val("SELECT stats_public FROM users WHERE id=$a1") === 1);
[$s, $j] = as_req('a1', 'GET', '/v5a/api/chat/dm/my-settings');
check('没落偏好行时两个私聊开关也回「开」（默认开是读的时候给的）',
    (int)($j['code'] ?? 1) === 0 && (int)$j['data']['allow_dm'] === 1 && (int)$j['data']['allow_stranger'] === 1, J($j['data'] ?? null));
[$s, $j] = as_req('a1', 'GET', '/v5a/api/auth/me');
check('auth/me 带出 allow_stranger 与 muted_by 与新口径的 team_name_suffix',
    array_key_exists('allow_stranger', $j['data'] ?? []) && array_key_exists('muted_by', $j['data'] ?? [])
    && array_key_exists('team_name_suffix', $j['data'] ?? []), J(array_keys($j['data'] ?? [])));
check('auth/me 里的 team_name_suffix 默认是开', (int)($j['data']['team_name_suffix'] ?? 0) === 1, J($j['data']['team_name_suffix'] ?? null));
[$s, $j] = as_req('a1', 'POST', '/v5a/api/chat/dm/my-setting', ['allow_stranger' => 0]);
check('本人可以关掉「允许其它战队的人先开口」',
    (int)($j['code'] ?? 1) === 0 && (int)$j['data']['allow_stranger'] === 0, J($j));
check('这一条落进 chat_dm_prefs 自己的那行，allow_dm 不受牵连',
    (int)db_val("SELECT allow_stranger FROM chat_dm_prefs WHERE user_id=$a1") === 0
    && (int)db_val("SELECT allow_dm FROM chat_dm_prefs WHERE user_id=$a1") === 1, '两个键各写各的');
[$s, $j] = as_req('a1', 'POST', '/v5a/api/chat/dm/my-setting', ['allow_stranger' => 1]);
check('重新打开', (int)($j['data']['allow_stranger'] ?? 0) === 1, J($j));
[$s, $j] = as_req('a1', 'POST', '/v5a/api/profile/stats-public', ['stats_public' => 0]);
check('战绩开关只认 stats_public 这一个键', (int)($j['code'] ?? 1) === 0 && (int)$j['data']['stats_public'] === 0, J($j));
[$s, $j] = as_req('a1', 'POST', '/v5a/api/profile/stats-public', ['stats_public' => 1]);
check('再打开', (int)$j['data']['stats_public'] === 1, J($j));
check('库里这一行跟着变', (int)db_val("SELECT stats_public FROM users WHERE id=$a1") === 1);

// ============================================================
// 2. 成员名录
// ============================================================
echo "\n== 2. 成员名录只出本队 ==\n";
[$s, $j, $h, $raw] = as_req('a1', 'GET', '/v5a/api/members');
$list = $j['data']['list'] ?? [];
check('名录接口正常', (int)($j['code'] ?? 1) === 0 && count($list) === 4, J(['n' => count($list)]));
check('正好是本队四个已通过的账号（含自己）',
    count($list) === 4 && in_array($a1, array_column($list, 'id'), false)
    && in_array($a0, array_column($list, 'id'), false), J(array_column($list, 'id')));
$keysOk = true;
foreach ($list as $m) {
    if (array_keys($m) !== ['id', 'name', 'tag', 'color', 'online', 'is_admin', 'is_me']) { $keysOk = false; }
}
check('每一项只有 id/name/tag/color/online/is_admin/is_me 这七个键', $keysOk, J(array_keys($list[0] ?? [])));
check('响应体里一个 QQ 号都没有', strpos($raw, '95001000') === false, substr($raw, 0, 200));
check('别队的人不出现在这份名单里',
    !in_array($b1, array_column($list, 'id'), false) && strpos($raw, '合并包B队') === false);
check('在线只有布尔，没有最后活跃时刻',
    !array_key_exists('last_active', $list[0] ?? []) && array_key_exists('online', $list[0] ?? []),
    J(array_keys($list[0] ?? [])));
$mineRow = array_values(array_filter($list, fn($m) => (int)$m['id'] === $a1));
check('自己那一行 is_me=1 而且是在线', (int)($mineRow[0]['is_me'] ?? 0) === 1 && (int)($mineRow[0]['online'] ?? 0) === 1,
    J($mineRow[0] ?? null));
$adminRow = array_values(array_filter($list, fn($m) => (int)$m['id'] === $a0));
check('站长那一行 is_admin=1', (int)($adminRow[0]['is_admin'] ?? 0) === 1, J($adminRow[0] ?? null));
check('total 是本队已通过人数、没截断', (int)($j['data']['total'] ?? 0) === 4 && (int)($j['data']['truncated'] ?? 1) === 0,
    J(['total' => $j['data']['total'] ?? null]));
[$s, $j2] = as_req('b1', 'GET', '/v5b/api/members');
check('B 队的人拿到的名单里没有一个 A 队的 id（结构隔离）',
    !array_intersect(array_column($j2['data']['list'] ?? [], 'id'), [$a0, $a1, $a2, $aSub]),
    J(array_column($j2['data']['list'] ?? [], 'id')));

// ============================================================
// 3. 成员主页
// ============================================================
echo "\n== 3. 成员主页：战绩按本人开关，跨队看得到人看不到战绩 ==\n";
[$s, $j, $h, $raw] = as_req('a1', 'GET', '/v5a/api/members/' . $a2);
check('队友的主页打得开', (int)($j['code'] ?? 1) === 0 && (int)($j['data']['member']['id'] ?? 0) === $a2, J($j['data']['member'] ?? null));
check('战绩公开着 ⇒ fields 与 stat 都给（本队 + 本人勾了）',
    (int)$j['data']['member']['stats_shared'] === 1 && count($j['data']['fields'] ?? []) > 0
    && is_array($j['data']['stat'] ?? null), J(['f' => count($j['data']['fields'] ?? [])]));
check('主页不再带「TA 在论坛发的」那一串（v5.32.0 论坛下线，两个键一起摘了）',
    !array_key_exists('posts', $j['data']) && !array_key_exists('post_total', $j['data']),
    J(array_keys($j['data'])));
check('看本队的人：can_block 不存在或为 0，不给拉本队人这条路',
    (int)($j['data']['member']['can_block'] ?? 0) === 0, J($j['data']['member'] ?? null));
[$s, $j] = as_req('a2', 'POST', '/v5a/api/profile/stats-public', ['stats_public' => 0]);
check('a2 自己把战绩收回', (int)($j['data']['stats_public'] ?? 1) === 0, J($j));
[$s, $j, $h, $raw] = as_req('a1', 'GET', '/v5a/api/members/' . $a2);
$prof = $j['data'] ?? [];
check('收回后队友视角：fields/stat 一起空着，连有没有战绩行都不透出',
    (int)($prof['member']['stats_shared'] ?? 1) === 0 && ($prof['fields'] ?? ['x']) === []
    && array_key_exists('stat', $prof) && $prof['stat'] === null,
    J(['shared' => $prof['member']['stats_shared'] ?? null,
       'stat' => array_key_exists('stat', $prof) ? gettype($prof['stat']) : '缺键']));
[$s, $j] = as_req('a2', 'GET', '/v5a/api/members/' . $a2);
check('自己看自己永远看得到（这份本来就是本人接口里的数据）',
    (int)$j['data']['is_me'] === 1 && count($j['data']['fields'] ?? []) > 0, J(['me' => $j['data']['is_me'] ?? null]));
[$s, $j] = as_req('a2', 'POST', '/v5a/api/profile/stats-public', ['stats_public' => 1]);
check('重新公开', (int)($j['data']['stats_public'] ?? 0) === 1, J($j));

[$s, $j, $h, $raw] = as_req('a1', 'GET', '/v5a/api/members/' . $b1);
check('跨队也打得开主页（v5.30.1 放开）', (int)($j['code'] ?? 1) === 0 && (int)$j['data']['member']['same_team'] === 0, J($j['data']['member'] ?? null));
check('外队的人：告诉你 TA 是哪队的', (string)($j['data']['member']['team'] ?? '') === '合并包B队', J($j['data']['member']['team'] ?? null));
check('但战绩一律不给（跨队不看别人队的战绩，哪怕 TA 勾了公开）',
    ($j['data']['fields'] ?? ['x']) === [] && array_key_exists('stat', $j['data'] ?? [])
    && $j['data']['stat'] === null, J(['f' => $j['data']['fields'] ?? null]));
check('外队非本人 ⇒ can_block=1（拉黑只对其它战队的人开放）',
    (int)($j['data']['member']['can_block'] ?? 0) === 1, J($j['data']['member'] ?? null));
[$s, $j] = as_req('a1', 'GET', '/v5a/api/members/' . $a1);
check('看自己：is_me=1 且 can_block=0', (int)($j['data']['is_me'] ?? 0) === 1 && (int)($j['data']['member']['can_block'] ?? 1) === 0,
    J($j['data']['member'] ?? null));
[$s, $j, $h, $raw] = as_req('a1', 'GET', '/v5a/api/members/999999');
check('没这个人 / 不在本队 / 没通过审核 合成同一句 404',
    (int)$s === 404 && (int)($j['code'] ?? 0) === 404 && strpos(J($j), '没有这个人') !== false, "http=$s " . substr($raw, 0, 120));
$pendingQq = '9500999999';
db()->exec("INSERT INTO users (tenant_id, username, password, nickname, role, status, admin_perms, created_at, updated_at)
    VALUES ($bTid, '$pendingQq', '$hash', '待审核的那个', 'user', 'pending', '[]', '$now', '$now')");
$pendId = (int)db_val("SELECT id FROM users WHERE username='$pendingQq'");
[$s, $j] = as_req('a1', 'GET', '/v5a/api/members/' . $pendId);
check('没通过审核的账号走的也是同一句（不拿错误信息当探测）', (int)$s === 404 && strpos(J($j), '没有这个人') !== false, "http=$s");

// 能不能私聊：跟着对方那两层开关走
[$s, $j] = as_req('b1', 'POST', '/v5b/api/chat/dm/my-setting', ['allow_stranger' => 0]);
check('b1 关掉「允许其它战队的人先开口」', (int)($j['data']['allow_stranger'] ?? 1) === 0, J($j));
[$s, $j] = as_req('a1', 'GET', '/v5a/api/members/' . $b1);
check('于是 a1 看 b1 主页时 dm_open=0（发之前就能提示）', (int)($j['data']['member']['dm_open'] ?? 1) === 0, J($j['data']['member'] ?? null));
[$s, $j] = as_req('b1', 'POST', '/v5b/api/chat/dm/my-setting', ['allow_stranger' => 1]);
[$s, $j] = as_req('a1', 'GET', '/v5a/api/members/' . $b1);
check('b1 重新打开后 dm_open 又变 1', (int)($j['data']['member']['dm_open'] ?? 0) === 1, J($j['data']['member'] ?? null));

// ============================================================
// 4. 跨队私聊
// ============================================================
echo "\n== 4. 跨队私聊放开 ==\n";
$X = '跨队私聊X1';
[$s, $j] = sendOk('a1', '/v5a/api/chat/dm/send', ['to' => $b1, 'content' => $X]);
$crossId = (int)($j['data']['id'] ?? 0);
check('A 队的人能直接私聊 B 队的人（不再要求同队）', (int)($j['code'] ?? 1) === 0 && $crossId > 0, J($j));
check('会话键是 min-max 的全局 user id（跨队也只在一条会话里）',
    (string)db_val("SELECT pair_key FROM chat_dm_messages WHERE id=$crossId") === min($a1, $b1) . '-' . max($a1, $b1),
    (string)db_val("SELECT pair_key FROM chat_dm_messages WHERE id=$crossId"));
[$s, $j, $h, $raw] = as_req('b1', 'GET', '/v5b/api/chat/dm/conversations');
$row = array_values(array_filter($j['data']['list'] ?? [], fn($r) => (int)$r['peer_id'] === $a1));
check('b1 的会话列表里出现 a1，并标出是同队还是跨队',
    count($row) === 1 && (int)$row[0]['same_team'] === 0, J($row));
check('列表带对方战队名', (string)($row[0]['peer_team'] ?? '') === '合并包A队', J($row[0]['peer_team'] ?? null));
check('跨队会话不吐对方 QQ 号', strpos($raw, '950010002') === false, substr($raw, 0, 200));
[$s, $j, $h, $raw] = as_req('b1', 'GET', '/v5b/api/chat/dm/thread?with=' . $a1);
check('b1 读这段对话能看到正文', (int)($j['code'] ?? 1) === 0 && strpos($raw, $X) !== false, substr($raw, 0, 160));
[$s, $j, $h, $raw] = as_req('a1', 'GET', '/v5a/api/chat/dm/candidates');
check('不带 q 时候选人仍只有本队（默认那张名单不长成全站通讯录）',
    (int)($j['code'] ?? 1) === 0 && !in_array($b1, array_column($j['data']['list'] ?? [], 'id'), false),
    J(array_column($j['data']['list'] ?? [], 'id')));
[$s, $j, $h, $raw] = as_req('a1', 'GET', '/v5a/api/chat/dm/candidates?q=' . urlencode('v5b成员2'));
$hit = array_values(array_filter($j['data']['list'] ?? [], fn($r) => (int)$r['id'] === $b1));
check('按昵称搜可以搜到外队的人，并给出 TA 的队名',
    count($hit) === 1 && (string)($hit[0]['team'] ?? '') === '合并包B队' && (int)$hit[0]['same_team'] === 0, J($hit));
check('搜索结果里带「现在能不能发起」的结论', (int)($hit[0]['open_dm'] ?? 0) === 1, J($hit[0] ?? null));
[$s, $j] = as_req('a1', 'GET', '/v5a/api/chat/dm/candidates?q=X');
check('只给一个字不让你按名字全站捞人', (int)($j['code'] ?? 0) !== 0 && strpos(J($j), '两个字') !== false, J($j));
check('搜索响应里也不出完整 QQ 号', strpos($raw, '950020002') === false, substr($raw, 0, 200));

// 关了 allow_stranger：不影响已有往来，只挡「先开口」
[$s, $j] = sendOk('b1', '/v5b/api/chat/dm/send', ['to' => $a1, 'content' => 'b1先回一句Y1']);
check('先让 b1 回一句（把「对方还没回复」那一档解掉，好把 allow_stranger 这一层单独测）', (int)($j['code'] ?? 1) === 0, J($j));
[$s, $j] = as_req('b1', 'POST', '/v5b/api/chat/dm/my-setting', ['allow_stranger' => 0]);
[$s, $j] = sendOk('a1', '/v5a/api/chat/dm/send', ['to' => $b1, 'content' => '已经聊过的不受牵连Y2']);
check('b1 关掉陌生人开关后，已有往来的这段仍能发（这一层只挡「先开口」）', (int)($j['code'] ?? 1) === 0, J($j));
[$s, $j] = as_req('b2', 'POST', '/v5b/api/chat/dm/my-setting', ['allow_stranger' => 0]);
sleep(3);
[$s, $j] = as_req('a1', 'POST', '/v5a/api/chat/dm/send', ['to' => $b2, 'content' => '从没聊过还想先开口Z3']);
check('换一个关了开关的外队人就被挡，并说清是「不接受其它战队的人先发起」',
    (int)($j['code'] ?? 0) === 403 && strpos(J($j), '其它战队') !== false, J($j));
[$s, $j] = as_req('b2', 'POST', '/v5b/api/chat/dm/my-setting', ['allow_stranger' => 1]);
[$s, $j] = sendOk('a1', '/v5a/api/chat/dm/send', ['to' => $b2, 'content' => '开关打开之后再发W4']);
check('对方重新打开后这条先开口发得出去', (int)($j['code'] ?? 1) === 0, J($j));

// ============================================================
// 5. 防骚扰：内置、关不掉、同队豁免
// ============================================================
echo "\n== 5. 防骚扰内置 ==\n";
$P3 = min($a1, $b3) . '-' . max($a1, $b3);
[$s, $j] = as_req('a1', 'GET', '/v5a/api/chat/dm/thread?with=' . $b3);
check('还没开口时额度就是 1 条（打开会话看得见，不用等按钮报错）',
    (int)($j['code'] ?? 1) === 0 && ($j['data']['stranger_limit'] ?? -1) === 1, J($j['data']['stranger_limit'] ?? 'x'));
[$s, $j] = sendOk('a1', '/v5a/api/chat/dm/send', ['to' => $b3, 'content' => '先开口的第一条S5']);
check('对没聊过的外队人第一条发得出去', (int)($j['code'] ?? 1) === 0, J($j));
sleep(3);
[$s, $j] = as_req('a1', 'POST', '/v5a/api/chat/dm/send', ['to' => $b3, 'content' => '没人回还想发第二条S6']);
check('对方回话之前第二条被挡（内置，谁也关不掉）',
    (int)($j['code'] ?? 0) === 403 && strpos(J($j), '防骚扰') !== false, J($j));
[$s, $j, $h, $raw] = as_req('a1', 'GET', '/v5a/api/chat/dm/thread?with=' . $b3);
check('那 1 条用掉之后额度变成 0（整数，不是数组）',
    (int)($j['code'] ?? 1) === 0 && ($j['data']['stranger_limit'] ?? -1) === 0,
    J($j['data']['stranger_limit'] ?? 'x'));
[$s, $j] = sendOk('b3', '/v5b/api/chat/dm/send', ['to' => $a1, 'content' => 'b3回一句S7']);
check('b3 回了一句', (int)($j['code'] ?? 1) === 0, J($j));
[$s, $j, $h, $raw] = as_req('a1', 'GET', '/v5a/api/chat/dm/thread?with=' . $b3);
check('对方回过之后这一档就解除了（stranger_limit 不再给）',
    ($j['data']['stranger_limit'] ?? null) === null, J($j['data']['stranger_limit'] ?? 'x'));
sleep(3);
[$s, $j] = sendOk('a1', '/v5a/api/chat/dm/send', ['to' => $b3, 'content' => '回过之后连发第二条S8']);
check('回过之后 a1 能接着发', (int)($j['code'] ?? 1) === 0, J($j));
sleep(3);
[$s, $j] = sendOk('a1', '/v5a/api/chat/dm/send', ['to' => $b3, 'content' => '回过之后连发第三条S9']);
check('同一段回过话的对话连发第三条也不受 1 条限制', (int)($j['code'] ?? 1) === 0, J($j));
[$s, $j] = sendOk('a1', '/v5a/api/chat/dm/send', ['to' => $a2, 'content' => '同队豁免的第一条T1']);
sleep(3);
[$s, $j] = as_req('a1', 'POST', '/v5a/api/chat/dm/send', ['to' => $a2, 'content' => '同队豁免的第二条T2']);
check('同队之间不受这条限制（还是只受 2 秒限频）', (int)($j['code'] ?? 1) === 0, J($j));
check('这一段会话在库里已经攒到 3 条（撤回只是打标记，不影响「说过话」这件事）',
    (int)db_val("SELECT COUNT(*) FROM chat_dm_messages WHERE pair_key='$P3'") >= 3);

// ============================================================
// 6. 个人黑名单
// ============================================================
echo "\n== 6. 个人黑名单 ==\n";
[$s, $j] = as_req('a1', 'POST', '/v5a/api/blocks/add', ['user_id' => $a1]);
check('不能拉自己', (int)($j['code'] ?? 0) !== 0, J($j));
[$s, $j] = as_req('a1', 'POST', '/v5a/api/blocks/add', ['user_id' => $a2]);
check('拉本队的人被拒，并让你去找队长或管理员',
    (int)($j['code'] ?? 0) !== 0 && strpos(J($j), '只拉其它战队的人') !== false, J($j));
[$s, $j] = as_req('a1', 'POST', '/v5a/api/blocks/add', ['user_id' => 987654]);
check('拉一个不存在的账号被拒', (int)($j['code'] ?? 0) !== 0, J($j));
[$s, $j] = as_req('a1', 'POST', '/v5a/api/blocks/add', ['user_id' => $b1]);
check('拉黑 b1 成功', (int)($j['code'] ?? 1) === 0, J($j));
check('名单里就多这一个，max 是 10',
    count($j['data']['list'] ?? []) === 1 && (int)($j['data']['max'] ?? 0) === 10, J($j['data'] ?? null));
check('库里这一行记下拉黑者与对象', (int)db_val("SELECT COUNT(*) FROM user_blocks WHERE user_id=$a1 AND blocked_user_id=$b1") === 1);
[$s, $j] = as_req('a1', 'POST', '/v5a/api/blocks/add', ['user_id' => $b1]);
check('同一个人不能重复拉', (int)($j['code'] ?? 0) !== 0 && strpos(J($j), '已经在你的黑名单里') !== false, J($j));
[$s, $j] = as_req('b1', 'GET', '/v5b/api/members/' . $a1);
check('被拉黑的一方视角一切正常（b1 照样打得开 a1 的主页）',
    (int)($j['code'] ?? 1) === 0 && (int)($j['data']['member']['id'] ?? 0) === $a1, substr(J($j), 0, 200));
check('被拉黑的一方收不到任何通知（不写 forum_notices、也不反写一行）',
    (int)db_val("SELECT COUNT(*) FROM forum_notices WHERE to_user_id=$b1") === 0
    && (int)db_val("SELECT COUNT(*) FROM user_blocks WHERE user_id=$b1 AND blocked_user_id=$a1") === 0);

// 论坛与大厅下线后，「只对本人隐藏」还剩两处：私聊会话列表与搜人候选（候选那处在本节末尾验）。
// 先让 b2 给 a1 开口一条，这段会话两边都有，拉黑之后才看得出只少了 a1 那半。
[$s, $j] = sendOk('b2', '/v5b/api/chat/dm/send', ['to' => $a1, 'content' => '私聊这条b2路过W7']);
check('外队的 b2 第一条开得口（防骚扰是「回话前最多一条」，不是不让发）', (int)($j['code'] ?? 1) === 0, J($j));
[$s, $j] = as_req('a1', 'GET', '/v5a/api/chat/dm/conversations');
check('拉黑之前 a1 的会话列表里有 b2 这段',
    in_array($b2, array_column($j['data']['list'] ?? [], 'peer_id'), false),
    J(array_column($j['data']['list'] ?? [], 'peer_id')));
[$s, $j] = as_req('a1', 'POST', '/v5a/api/blocks/add', ['user_id' => $b2]);
check('把 b2 也拉了', (int)($j['code'] ?? 1) === 0, J($j));
[$s, $j] = as_req('a1', 'GET', '/v5a/api/chat/dm/conversations');
check('拉黑之后这段会话从 a1 的列表里整个消失',
    (int)($j['code'] ?? 1) === 0 && !in_array($b2, array_column($j['data']['list'] ?? [], 'peer_id'), false),
    J(array_column($j['data']['list'] ?? [], 'peer_id')));
[$s, $j] = as_req('b2', 'GET', '/v5b/api/chat/dm/conversations');
check('b2 那半不受牵连（黑名单是他自己的，不是全球的）',
    (int)($j['code'] ?? 1) === 0 && in_array($a1, array_column($j['data']['list'] ?? [], 'peer_id'), false),
    J(array_column($j['data']['list'] ?? [], 'peer_id')));

sleep(3);
[$s, $j] = as_req('a1', 'POST', '/v5a/api/chat/dm/send', ['to' => $b1, 'content' => '拉黑了还想发']);
check('拉黑后 a1 发不出去，话说得很直白（是自己的名单）',
    (int)($j['code'] ?? 0) === 403 && strpos(J($j), '黑名单') !== false, J($j));
[$s, $j] = as_req('b1', 'POST', '/v5b/api/chat/dm/send', ['to' => $a1, 'content' => '我被人拉黑了还在发']);
check('对方给 a1 发也被挡，但不告诉他「你被拉黑了」',
    (int)($j['code'] ?? 0) === 403 && strpos(J($j), '拉黑') === false, J($j));
[$s, $j] = as_req('a1', 'GET', '/v5a/api/chat/dm/thread?with=' . $b1);
check('a1 想看这段被拉黑的对话也被拒（先放出黑名单）',
    (int)($j['code'] ?? 0) === 403 && strpos(J($j), '先放出黑名单') !== false, J($j));
[$s, $j, $h, $raw] = as_req('b1', 'GET', '/v5b/api/chat/dm/thread?with=' . $a1);
check('被拉黑的一方仍读得到这段历史（屏蔽只是本人视角）', strpos($raw, '跨队私聊X1') !== false, substr($raw, 0, 160));
[$s, $j] = as_req('a1', 'GET', '/v5a/api/members/' . $b1);
check('主页如实标出「是你自己拉的」，并给结论 dm_open=0（按钮该摆成「放出」靠这两个字段）',
    (int)($j['data']['member']['blocked_by_me'] ?? 0) === 1
    && (int)($j['data']['member']['blocked_me'] ?? 1) === 0
    && (int)($j['data']['member']['dm_open'] ?? 1) === 0
    && (int)($j['data']['member']['can_block'] ?? 0) === 1, J($j['data']['member'] ?? null));
[$s, $j] = as_req('a1', 'GET', '/v5a/api/chat/dm/candidates?q=' . urlencode('v5b成员2'));
check('按昵称搜人也搜不到被拉黑的那个', (int)($j['code'] ?? 1) === 0
    && !in_array($b1, array_column($j['data']['list'] ?? [], 'id'), false), J($j['data']['list'] ?? null));

// 上限：10 个人，只能往外队拉
for ($i = 1; $i < 9; $i++) {   // 已有 b1、b2 两个，再拉 C 队 8 个凑满 10
    [$s, $j] = as_req('a1', 'POST', '/v5a/api/blocks/add', ['user_id' => $cIds[$i]]);
    if ((int)($j['code'] ?? 1) !== 0) { break; }
}
[$s, $j] = as_req('a1', 'GET', '/v5a/api/blocks');
check('名单能凑满 10 个', count($j['data']['list'] ?? []) === 10, J(['n' => count($j['data']['list'] ?? [])]));
[$s, $j] = as_req('a1', 'POST', '/v5a/api/blocks/add', ['user_id' => $cIds[9]]);
check('第 11 个被拒并说清上限', (int)($j['code'] ?? 0) !== 0 && strpos(J($j), '最多 10 个') !== false, J($j));
[$s, $j] = as_req('a1', 'POST', '/v5a/api/blocks/remove', ['user_id' => $cIds[8]]);
check('放出一个', (int)($j['code'] ?? 1) === 0 && count($j['data']['list'] ?? []) === 9, J(['n' => count($j['data']['list'] ?? [])]));
[$s, $j] = as_req('a1', 'POST', '/v5a/api/blocks/add', ['user_id' => $cIds[9]]);
check('放出去之后才拉得进新的（名额是真在数的）', (int)($j['code'] ?? 1) === 0, J($j));
[$s, $j] = as_req('a1', 'POST', '/v5a/api/blocks/remove', ['user_id' => $cIds[9]]);
for ($i = 1; $i < 9; $i++) { as_req('a1', 'POST', '/v5a/api/blocks/remove', ['user_id' => $cIds[$i]]); }
[$s, $j] = as_req('a1', 'POST', '/v5a/api/blocks/remove', ['user_id' => $b2]);
check('b2 也放出来', (int)($j['code'] ?? 1) === 0, J($j));
[$s, $j] = as_req('a1', 'POST', '/v5a/api/blocks/remove', ['user_id' => $b1]);
check('放出 b1', (int)($j['code'] ?? 1) === 0 && count($j['data']['list'] ?? []) === 0, J(['n' => count($j['data']['list'] ?? [])]));
[$s, $j] = as_req('a1', 'POST', '/v5a/api/blocks/remove', ['user_id' => $b1]);
check('放出第二次就说他没在名单里（不假装成功）', (int)($j['code'] ?? 0) !== 0, J($j));
[$s, $j] = as_req('a1', 'GET', '/v5a/api/chat/dm/conversations');
check('放出 b2 之后那段会话又回到 a1 的列表里（拉黑不是删数据）',
    (int)($j['code'] ?? 1) === 0 && in_array($b2, array_column($j['data']['list'] ?? [], 'peer_id'), false),
    J(array_column($j['data']['list'] ?? [], 'peer_id')));
[$s, $j] = as_req('a1', 'GET', '/v5a/api/members/' . $b1);
check('放出之后 b1 主页上「是你自己拉的」那个标记落回 0',
    (int)($j['code'] ?? 1) === 0 && (int)($j['data']['member']['blocked_by_me'] ?? 1) === 0,
    J($j['data']['member'] ?? null));
sleep(3);
[$s, $j] = sendOk('b1', '/v5b/api/chat/dm/send', ['to' => $a1, 'content' => '放出之后b1能回话U1']);
check('放出之后对方那条立刻进得来（拉黑只是本人视角，解开不欠什么）', (int)($j['code'] ?? 1) === 0, J($j));
[$s, $j, $h, $raw] = as_req('a1', 'GET', '/v5a/api/chat/dm/thread?with=' . $b1);
check('a1 也能重新打开这段对话，不再提黑名单',
    (int)($j['code'] ?? 1) === 0 && strpos($raw, '黑名单') === false, substr($raw, 0, 120));

// ============================================================
// 7. 禁言归平台：来源分明
// ============================================================
echo "\n== 7. 禁言分来源，队长解不掉平台那条 ==\n";
[$s, $j] = as_req('plat', 'POST', '/api/platform/admin/mute', ['user_id' => $b2, 'reason' => '']);
check('平台禁言必须写理由', (int)($j['code'] ?? 0) !== 0, J($j));
[$s, $j] = as_req('plat', 'POST', '/api/platform/admin/mute', ['user_id' => $b2, 'reason' => '在群里辱骂他人']);
check('平台给 b2 下了禁言', (int)($j['code'] ?? 1) === 0, J($j));
check('库里 muted=1 且来源写的是 platform',
    (int)db_val("SELECT muted FROM users WHERE id=$b2") === 1 && (string)db_val("SELECT muted_by FROM users WHERE id=$b2") === 'platform');
[$s, $j] = as_req('plat', 'GET', '/api/platform/admin/mute-state?user_id=' . $b2);
check('平台自己能看清这条能不能解', (int)($j['data']['can_lift'] ?? 0) === 1, J($j['data'] ?? null));
[$s, $j] = as_req('b2', 'POST', '/v5b/api/chat/send', ['content' => '被平台禁言了还想说']);
check('被平台禁言的人在自己队的战队群里说不了话',
    (int)($j['code'] ?? 0) === 403 && strpos(J($j), '禁言') !== false, J($j));
[$s, $j] = as_req('b2', 'POST', '/v5b/api/chat/dm/send', ['to' => $b1, 'content' => '被禁言了还想私聊']);
check('跨队私聊也一起挡掉', (int)($j['code'] ?? 0) === 403, J($j));
[$s, $j, $h, $raw] = as_req('b2', 'GET', '/v5b/api/chat/messages');
check('禁言只挡写：他仍能读自己队的群', (int)($j['code'] ?? 1) === 0, substr($raw, 0, 120));
[$s, $j] = as_req('b0', 'POST', "/v5b/api/admin/users/$b2/mute", ['muted' => 0]);
check('本队队长解不掉平台下的那条，并被告知联系平台',
    (int)($j['code'] ?? 0) === 403 && strpos(J($j), '平台') !== false, J($j));
[$s, $j] = as_req('a0', 'POST', "/v5a/api/admin/users/$b2/mute", ['muted' => 1]);
check('A 队队长根本管不到 B 队的人（跨队结构隔离）', (int)($j['code'] ?? 0) !== 0, J($j));
[$s, $j] = as_req('b0', 'POST', "/v5b/api/admin/users/$b1/mute", ['muted' => 1]);
check('队长仍可自设本队禁言', (int)($j['code'] ?? 1) === 0, J($j));
check('这一条的来源写的是 team', (string)db_val("SELECT muted_by FROM users WHERE id=$b1") === 'team');
[$s, $j] = as_req('plat', 'POST', '/api/platform/admin/unmute', ['user_id' => $b1]);
check('平台不越俎代庖去解本队自设的那条',
    (int)($j['code'] ?? 0) !== 0 && strpos(J($j), '本队自己设的') !== false, J($j));
[$s, $j] = as_req('b0', 'POST', "/v5b/api/admin/users/$b1/mute", ['muted' => 0]);
check('本队自设的那条由本队自己解', (int)($j['code'] ?? 1) === 0, J($j));
[$s, $j] = as_req('plat', 'POST', '/api/platform/admin/unmute', ['user_id' => $b2]);
check('平台下的那条由平台解', (int)($j['code'] ?? 1) === 0, J($j));
check('解完来源一起清空',
    (int)db_val("SELECT muted FROM users WHERE id=$b2") === 0 && (string)db_val("SELECT muted_by FROM users WHERE id=$b2") === '');
[$s, $j] = as_req('plat', 'POST', '/api/platform/admin/unmute', ['user_id' => $b2]);
check('没被禁言的人谈不上「解除」', (int)($j['code'] ?? 0) !== 0, J($j));
[$s, $j] = sendOk('b2', '/v5b/api/chat/send', ['content' => '解禁后重新说话V6']);
check('解禁后自己队的战队群恢复能说话', (int)($j['code'] ?? 1) === 0, J($j));
check('平台的每一次禁言与解禁都落在平台操作日志（tenant_id=0）',
    (int)db_val("SELECT COUNT(*) FROM admin_logs WHERE tenant_id=0 AND action='平台禁言'") >= 1
    && (int)db_val("SELECT COUNT(*) FROM admin_logs WHERE tenant_id=0 AND action='平台解禁'") >= 1);
$authSrc = (string)file_get_contents($ROOT . '/app/controller/Auth.php');
check('战队侧接口把来源一起下发，前端才敢把「解除」按钮摆出来',
    strpos($authSrc, "'muted_by'") !== false);

// ============================================================
// 8. 左上角队名后缀
// ============================================================
echo "\n== 8. 队名「战队」后缀站长可关 ==\n";
[$s, $j] = as_req('a3', 'POST', '/v5a/api/admin/brand-suffix', ['suffix' => 0]);
check('只有 mute 权限的挂名管理员改不了站名显示', (int)($j['code'] ?? 0) === 403, J($j));
[$s, $j] = as_req('a0', 'POST', '/v5a/api/admin/brand-suffix', ['suffix' => 0]);
check('站长可以关掉自动补「战队」', (int)($j['code'] ?? 1) === 0 && (int)$j['data']['team_name_suffix'] === 0, J($j));
check('写在本队的 settings 键上',
    (string)db_val("SELECT value FROM settings WHERE tenant_id=$aTid AND key='team_name_suffix'") === '0');
[$s, $j] = as_req('a1', 'GET', '/v5a/api/auth/me');
check('成员这边立刻拿到「关」的结论（左上角跟着变）',
    (int)($j['data']['team_name_suffix'] ?? 1) === 0, J($j['data']['team_name_suffix'] ?? null));
[$s, $j] = as_req('b1', 'GET', '/v5b/api/auth/me');
check('另一支队不受牵连（各队各的一档）', (int)($j['data']['team_name_suffix'] ?? 0) === 1, J($j['data']['team_name_suffix'] ?? null));
[$s, $j] = as_req('a0', 'POST', '/v5a/api/admin/brand-suffix', ['suffix' => 1]);
check('站长再打开', (int)($j['data']['team_name_suffix'] ?? 0) === 1, J($j));
check('这个动作进了本队操作日志',
    (int)db_val("SELECT COUNT(*) FROM admin_logs WHERE tenant_id=$aTid AND action='站名显示'") >= 2);

// ============================================================
// 9. 守卫与回归
// ============================================================
echo "\n== 9. 守卫与回归 ==\n";
$dt = (string)file_get_contents($ROOT . '/app/service/DataTransfer.php');
check('站点导出不带论坛与大厅内容、也不带黑名单（那是公共区与私人视角，不是战队资产）',
    strpos($dt, 'lobby_messages') === false && strpos($dt, 'forum_posts') === false
    && strpos($dt, 'user_blocks') === false);
$routes = (string)file_get_contents($ROOT . '/route/app.php');
$insp = substr($routes, (int)strpos($routes, "Route::group('/api/inspect'"));
check('隐身巡查白名单里没有论坛、大厅、成员名录与黑名单',
    strpos($insp, 'Forum') === false && strpos($insp, 'Lobby') === false
    && strpos($insp, 'Members') === false && strpos($insp, 'Block') === false);
foreach (['/v5a/api/members', '/v5a/api/blocks', '/v5a/api/inbox', '/v5a/api/chat/dm/conversations'] as $p) {
    [$s, $j, $h, $raw] = as_req('a1', 'GET', $p);
    check($p . ' 的响应里不出现任何 QQ 号', !preg_match('/9500[0-9]{6}/', $raw), substr($raw, 0, 120));
}
// 论坛与大厅的接口整批从路由表摘了：要的是 404（路由不存在），不是 500（代码还在但炸了）
foreach (['/v5a/api/forum/feed', '/v5a/api/lobby', '/v5a/api/admin/forum/share', '/v5b/api/admin/forum/activity'] as $p) {
    [$s] = as_req('a1', 'GET', $p);
    check($p . ' 已随论坛与全站大厅下线（404 而不是 500）', (int)$s === 404, "http=$s");
}
[$s, $j] = as_req('a1', 'GET', '/v5a/api/inbox');
check('站内信搬去 /api/inbox 之后读得通（顶栏红点与「通知」页共用这一条）',
    (int)($j['code'] ?? 1) === 0 && array_key_exists('list', $j['data'] ?? [])
    && array_key_exists('unread', $j['data'] ?? []), J(array_keys($j['data'] ?? [])));
[$s, $j] = as_req('a1', 'GET', '/v5a/api/dashboard');
check('仪表盘正常', (int)($j['code'] ?? 1) === 0);
[$s, $j] = as_req('a1', 'GET', '/v5a/api/chat/messages');
check('战队群正常', (int)($j['code'] ?? 1) === 0);
[$s, $j] = as_req('a1', 'GET', '/v5a/api/auth/me');
check('个人中心那份本人数据正常（昵称、开关、来源都在这一个接口里）',
    (int)($j['code'] ?? 1) === 0 && isset($j['data']['id']), J(array_keys($j['data'] ?? [])));
[$s, $j, $h, $raw] = as_req('a1', 'GET', '/v5a/show');
check('访客展示页这条路由没被这轮碰坏（没开展示页的站出 404 而不是 500）',
    (int)$s === 200 || (int)$s === 404, "http=$s");

$schema = db();
$idx = [];
foreach ($schema->query("PRAGMA index_list('chat_dm_messages')") as $r) { $idx[] = (string)$r['name']; }
check('开发库里私聊那三条跨队索引都在',
    in_array('idx_dm_pair_g', $idx, true) && in_array('idx_dm_from', $idx, true) && in_array('idx_dm_to_g', $idx, true),
    J($idx));
foreach (['user_blocks', 'forum_notices', 'chat_dm_messages', 'chat_messages', 'announcements', 'mail_blasts'] as $t) {
    check('该留的表在库里（' . $t . '）', (int)db_val("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='$t'") === 1);
}
// 已下线的这九张在开发库里一张都不建：五处 DDL 拷贝漏了一处，这里就红
foreach (['lobby_messages', 'lobby_reports', 'forum_posts', 'forum_comments', 'forum_likes',
          'forum_bans', 'forum_digest', 'forum_reports', 'forum_votes'] as $t) {
    check('已下线的表不再被建（' . $t . '）', (int)db_val("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='$t'") === 0);
}
$full = (string)file_get_contents($ROOT . '/database/game_team.sql');
$pma  = (string)file_get_contents($ROOT . '/database/game_team_phpmyadmin.sql');
check('两份全量 SQL 里两个隐私开关的默认值都已是 1（新装机不用刷）',
    preg_match('/`show_public`\s+TINYINT\s+NOT NULL DEFAULT 1/', $full) === 1
    && preg_match('/`stats_public`\s+TINYINT\s+NOT NULL DEFAULT 1/', $full) === 1
    && preg_match('/`show_public`\s+TINYINT\s+NOT NULL DEFAULT 1/', $pma) === 1
    && preg_match('/`stats_public`\s+TINYINT\s+NOT NULL DEFAULT 1/', $pma) === 1,
    '两份全量 SQL 的 DEFAULT 没跟上');
check('两份全量 SQL 不再建论坛与大厅那九张表，该留的两张还在（DDL 五处拷贝齐了）',
    substr_count($full, 'CREATE TABLE `lobby_messages`') === 0
    && substr_count($full, 'CREATE TABLE `forum_posts`') === 0
    && substr_count($pma, 'CREATE TABLE `lobby_reports`') === 0
    && substr_count($pma, 'CREATE TABLE `forum_digest`') === 0
    && substr_count($full, 'CREATE TABLE `user_blocks`') === 1
    && substr_count($pma, 'CREATE TABLE `forum_notices`') === 1
    && strpos($full, '`muted_by`') !== false && strpos($pma, '`allow_stranger`') !== false);
check('增量 SQL 只有合并那一份，被取代的分版脚本已删',
    file_exists($ROOT . '/database/upgrade_v5.28.0_to_v5.30.1.sql')
    && !file_exists($ROOT . '/database/upgrade_v5.29.0_to_v5.30.0.sql')
    && !file_exists($ROOT . '/database/upgrade_v5.30.0_to_v5.30.1.sql'));
preg_match("/ASSET_BUILD',\\s*'([\\d.]+)'/", (string)file_get_contents($ROOT . '/app/common.php'), $ab);
check('资源版本号不低于这一功能落地时的 5.30.1（现在是 ' . ($ab[1] ?? '缺失') . '，发新版只往上涨不回头）',
    isset($ab[1]) && version_compare($ab[1], '5.30.1', '>='));

echo "\n================ 结果 ================\n";
echo "通过 $pass 项 / 失败 $fail 项\n";
if ($failures) {
    echo "失败明细：\n";
    foreach ($failures as $f) { echo "  - $f\n"; }
    exit(1);
}
exit(0);
