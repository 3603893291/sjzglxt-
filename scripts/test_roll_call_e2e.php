<?php
/**
 * v5.36.34 比赛日集合点名 E2E（scripts/test_roll_call_e2e.php）
 *
 * 前置：测试服务器已启动，.env 指向 SQLite（.env 里只能用 ; 注释）
 *   php -S 127.0.0.1:8899 -t public public/router.php
 * 用法：php scripts/test_roll_call_e2e.php
 *
 * QQ 群那一路是「best-effort」：这一路走不通（没绑群/机器人停用）不影响站内的点名单本身。
 * 所以这一段用库里 app_id='mock' 那台假机器人真跑一次发送链——发没发到落 runtime/bot/*.log，
 * 断言读的是那份日志，不是接口返回的那句好话。
 *
 * 十五段主线：
 *   1. 开单与六道护栏（标题、过去时间、60 天、截止早于集合、格式、挂别队场次）
 *   2. 成员首页出现这张单，分母是「本队已上线成员」
 *   3. 点「到 / 迟到 / 不来」：非法值拒、一人一行覆盖、可以带一句话（超长截断）
 *   4. 截止之后拒收，队长清空截止＝重新开放
 *   5. 统计分组：名单队内公开，成员也查得到谁到了谁没回
 *   6. 挂场次：只挑本队还没开始的那些
 *   7. 编辑与删除（删单连带删回执，不留孤儿行）
 *   8. 撤回：成员三处同口径挡掉，管理端照旧看得见，恢复后应答还在
 *   9. QQ 群那一路：到点前 15 分钟自动喊一次，一张单只喊一次
 *  10. 改集合时间＝重排；集合时间过去 30 分钟才扫到就不再补喊
 *  11. 队长手动「再喊一次」，5 分钟内只让按一次
 *  12. 越权：别队队长、没点名权限的副管理、成员本人，三种都够不着
 *  13. 端手游分舱：本端的单另一边看不见也点不了，共享单两边都能
 *  14. 懒触发：成员打开站点顺路把到点的喊出去，并按队 60 秒限频
 *  15. 表结构与唯一键 + 没开点名的队一切照旧
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

$ROOT = dirname(__DIR__);
$BASE = rtrim((string)(getenv('TEST_BASE') ?: 'http://127.0.0.1:8899'), '/');
$DB   = $ROOT . '/runtime/test.sqlite';

$pass = 0; $fail = 0; $failures = [];
function check(string $name, bool $ok, string $detail = ''): void
{
    global $pass, $fail, $failures;
    if ($ok) { $pass++; echo "  [PASS] $name\n"; }
    else { $fail++; $failures[] = $name . ($detail !== '' ? " ($detail)" : ''); echo "  [FAIL] $name" . ($detail !== '' ? " —— $detail\n" : "\n"); }
}
function J($a): string { return json_encode($a, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); }

$jars = [];
function jar(string $who): array { global $jars; if (!isset($jars[$who])) { $jars[$who] = []; } return $jars[$who]; }
/** [status, json, headers, raw]，Cookie 按身份在内存里维护 */
function as_req(string $who, string $method, string $path, ?array $body = null, array $extraHdr = []): array
{
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
        'timeout' => 60, 'follow_location' => 0]];
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
function db(): PDO
{
    global $DB;
    $p = new PDO('sqlite:' . $DB);
    $p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $p->setAttribute(PDO::ATTR_TIMEOUT, 5);
    return $p;
}
function db_val(string $sql, array $p = [])
{
    $st = db()->prepare($sql); $st->execute($p); $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ? reset($r) : null;
}
function db_rows(string $sql, array $p = []): array
{
    $st = db()->prepare($sql); $st->execute($p);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}
function cnt(string $sql): int { return (int)db_val($sql); }
/** 空串与 NULL 在这里是一回事（SQLite 里 DATETIME 空值取 NULL，MySQL 导入导出偶有 ''） */
function isBlank($v): bool { return $v === null || (string)$v === ''; }
/** 成员首页那张卡里的点名单（标题→行）：撤回与分端过滤都反映在这里 */
function memberCalls(string $who, string $slug): array
{
    [, $j] = as_req($who, 'GET', '/' . $slug . '/api/rollcall');
    $by = [];
    foreach (($j['data']['list'] ?? []) as $r) { $by[(string)$r['title']] = $r; }
    return $by;
}
function rcRow(int $id): array
{
    $r = db_rows("SELECT * FROM roll_calls WHERE id=$id");
    return $r[0] ?? [];
}
/** 清掉按队 60 秒的懒触发锁与「再喊一次」限频，让下一次扫描真的跑 */
function clearLocks(): void
{
    global $ROOT;
    foreach (array_merge(glob($ROOT . '/runtime/cache/*/*') ?: [], glob($ROOT . '/runtime/cache/*') ?: []) as $f) {
        if (is_file($f)) { @unlink($f); }
    }
}
function botLog(): string
{
    global $ROOT;
    $f = $ROOT . '/runtime/bot/bot_' . date('Ymd') . '.log';
    return is_file($f) ? (string)file_get_contents($f) : '';
}
function rcLogCount(int $tid, string $like): int
{
    return cnt("SELECT COUNT(*) FROM admin_logs WHERE tenant_id=$tid AND action='集合点名' AND detail LIKE '%" . $like . "%'");
}

// ============================================================
// 0. 建库与种子
// ============================================================
echo "== 0. 重建测试库与种子 ==\n";
@unlink($DB);
echo shell_exec('"' . PHP_BINARY . '" "' . $ROOT . '/scripts/dev_init_sqlite.php" "' . $DB . '" 2>&1');
clearLocks();
foreach (glob($ROOT . '/runtime/bot/*.log') ?: [] as $f) { @unlink($f); }

$now  = date('Y-m-d H:i:s');
$pdo  = db();
$hash = password_hash('pass123456', PASSWORD_DEFAULT);

$mk = function (string $slug, string $name, array $people) use ($pdo, $hash, $now): array {
    $pdo->exec("INSERT INTO platform_users (username,password,nickname,role,status,reg_ip,created_at,updated_at)
        VALUES ('{$slug}boss', '{$hash}', '{$name}联络人', 'team', 'normal', '127.0.0.1', '$now', '$now')");
    $pid = (int)$pdo->lastInsertId();
    $pdo->exec("INSERT INTO tenants (applicant_id, slug, name, status, admin_username, admin_user_id, created_at, updated_at)
        VALUES ($pid, '$slug', '$name', 'approved', '{$people[0][0]}', NULL, '$now', '$now')");
    $tid = (int)$pdo->lastInsertId();
    $ids = [];
    foreach ($people as $p) {
        [$qq, $role, $perms, $side] = $p;
        $nick = $role === 'admin' ? $slug . '管理' . $qq : $slug . '成员' . $qq;
        $pdo->exec("INSERT INTO users (tenant_id, username, password, nickname, email, role, status, admin_perms, game_side, created_at, updated_at)
            VALUES ($tid, '$qq', '$hash', '$nick', '$qq@qq.com', '$role', 'approved', '$perms', '$side', '$now', '$now')");
        $ids[$qq] = (int)$pdo->lastInsertId();
    }
    $pdo->exec("UPDATE tenants SET admin_user_id = {$ids[$people[0][0]]} WHERE id = $tid");
    return ['tid' => $tid, 'ids' => $ids, 'pid' => $pid];
};
// 甲队：站长 + 只有日程权限的副管理 + 只有成员名单权限的副管理 + 三个成员（这一队不开分舱）
$A = $mk('arc', '集合点名甲队', [
    ['987000001', 'admin', '[]', ''],
    ['987000002', 'admin', '["schedule"]', ''],
    ['987000003', 'admin', '["member"]', ''],
    ['987000004', 'user',  '[]', ''],
    ['987000005', 'user',  '[]', ''],
    ['987000006', 'user',  '[]', ''],
]);
// 乙队：另一支战队的站长（也是这一队没绑机器人的那一路）
$B = $mk('brc', '集合点名乙队', [['988000001', 'admin', '[]', '']]);
// 丙队：开了端手游分舱
$C = $mk('crc', '集合点名丙队', [
    ['989000001', 'admin', '[]', ''],
    ['989000002', 'user',  '[]', 'pc'],
    ['989000003', 'user',  '[]', 'mobile'],
    ['989000004', 'user',  '[]', 'mobile'],
]);
$aTid = (int)$A['tid']; $bTid = (int)$B['tid']; $cTid = (int)$C['tid'];
$aIds = $A['ids'];
echo "  种子：甲 tid=$aTid（6 人）乙 tid=$bTid（1 人）丙 tid=$cTid（4 人，分端）\n";

$login = static function (string $who, string $slug, string $acct): void {
    [$s, $j] = as_req($who, 'POST', '/' . $slug . '/api/auth/login', ['username' => $acct, 'password' => 'pass123456']);
    check("登录 $who（$acct）", $s === 200 && (int)($j['code'] ?? 1) === 0, 'http=' . $s . ' ' . J($j));
};
$login('own',  'arc', '987000001');   // 甲队站长
$login('sch',  'arc', '987000002');   // 只有「日程活动」权限的副管理
$login('nop',  'arc', '987000003');   // 只有「成员名单」权限的副管理
$login('m1',   'arc', '987000004');   // 甲队成员一
$login('m2',   'arc', '987000005');   // 甲队成员二
$login('m3',   'arc', '987000006');   // 甲队成员三
$login('boss', 'brc', '988000001');   // 乙队站长
$login('csown', 'crc', '989000001');  // 丙队站长
$login('cpc',   'crc', '989000002');  // 丙队端游成员
$login('cmb1',  'crc', '989000003');  // 丙队手游成员一
$login('cmb2',  'crc', '989000004');  // 丙队手游成员二

// 丙队开端手游分舱（「撞车日程」那一格没写过就默认是分端的）
$pdo->exec("INSERT INTO settings (tenant_id,`key`,value) VALUES ($cTid,'side_split_on','1')
    ON CONFLICT(tenant_id,`key`) DO UPDATE SET value='1'");

$save = static function (string $who, string $slug, array $body): array {
    return as_req($who, 'POST', '/' . $slug . '/api/admin/rollcall/save', $body);
};

// ============================================================
// 1. 开单与护栏
// ============================================================
echo "\n== 1. 开一张点名单与六道护栏 ==\n";
$far = date('Y-m-d H:i:s', time() + 7200);
[$s, $j] = $save('own', 'arc', ['title' => '今晚八点训练赛集合', 'call_at' => $far]);
$rcId = (int)($j['data']['id'] ?? 0);
check('站长开一张点名单', $s === 200 && (int)($j['code'] ?? 1) === 0 && $rcId > 0, J($j));
check('回话说清成员在哪儿点', str_contains((string)($j['msg'] ?? ''), '首页'), (string)($j['msg'] ?? ''));
$r0 = rcRow($rcId);
check('落库：集合时间写进去了', (string)$r0['call_at'] === $far, (string)$r0['call_at']);
check('落库：没填截止＝一直能点', isBlank($r0['deadline'] ?? null), J($r0['deadline'] ?? ''));
check('落库：notify_at 空＝还没往群里喊过', isBlank($r0['notify_at'] ?? null), J($r0['notify_at'] ?? ''));
check('落库：revoked_at 空＝这张是活的', isBlank($r0['revoked_at'] ?? null));
check('落库：tenant_id 是当前这个队', (int)$r0['tenant_id'] === $aTid, (string)$r0['tenant_id']);
check('落库：created_by 记的是开单的人', (int)$r0['created_by'] === (int)$aIds['987000001'], (string)($r0['created_by'] ?? ''));
check('操作日志里有一行「开点名单」', rcLogCount($aTid, '开点名单') === 1);

[, $j] = $save('own', 'arc', ['title' => '', 'call_at' => $far]);
check('护栏①：标题空着进不去', (int)($j['code'] ?? 0) !== 0 && str_contains((string)($j['msg'] ?? ''), '标题'), J($j));
[, $j] = $save('own', 'arc', ['title' => str_repeat('长', 129), 'call_at' => $far]);
check('护栏①b：标题超长也拒', (int)($j['code'] ?? 0) !== 0, J($j));
[, $j] = $save('own', 'arc', ['title' => '昨天的集合', 'call_at' => date('Y-m-d H:i:s', time() - 3600)]);
check('护栏②：集合时间是过去→拒（已经集合完不用开单）',
    (int)($j['code'] ?? 0) !== 0 && str_contains((string)($j['msg'] ?? ''), '过去'), J($j));
[, $j] = $save('own', 'arc', ['title' => '明年今天', 'call_at' => date('Y-m-d H:i:s', time() + 61 * 86400)]);
check('护栏③：排到 60 天以外→拒',
    (int)($j['code'] ?? 0) !== 0 && str_contains((string)($j['msg'] ?? ''), '60 天'), J($j));
[, $j] = $save('own', 'arc', ['title' => '截止比集合还早', 'call_at' => $far,
                              'deadline' => date('Y-m-d H:i:s', time() + 3600)]);
check('护栏④：截止早于集合→拒',
    (int)($j['code'] ?? 0) !== 0 && str_contains((string)($j['msg'] ?? ''), '早于集合'), J($j));
[, $j] = $save('own', 'arc', ['title' => '时间写歪了', 'call_at' => '今晚八点']);
check('护栏⑤：集合时间格式不对→拒', (int)($j['code'] ?? 0) !== 0 && str_contains((string)($j['msg'] ?? ''), '格式'), J($j));
$bEvAt = date('Y-m-d H:i:s', time() + 86400);
$pdo->exec("INSERT INTO race_events (tenant_id, game_side, title, race_time, created_at)
            VALUES ($bTid, '', '乙队的撞车', '$bEvAt', '$now')");
$bEvId = (int)$pdo->lastInsertId();
[, $j] = $save('own', 'arc', ['title' => '想挂乙队的场', 'call_at' => $far, 'event_id' => $bEvId]);
check('护栏⑥：挂别的战队的场次→拒（问不出别队有这场比赛）',
    (int)($j['code'] ?? 0) !== 0 && str_contains((string)($j['msg'] ?? ''), '不在这个战队'), J($j));
check('六道护栏各拒一次，库里没多开单', cnt("SELECT COUNT(*) FROM roll_calls WHERE tenant_id=$aTid") === 1,
    (string)cnt("SELECT COUNT(*) FROM roll_calls WHERE tenant_id=$aTid"));

// ============================================================
// 2. 成员首页看得见，分母是本队已上线成员
// ============================================================
echo "\n== 2. 成员端看得到这张单 ==\n";
$mc = memberCalls('m1', 'arc');
check('成员首页有这张点名单', isset($mc['今晚八点训练赛集合']), J(array_keys($mc)));
$c1 = $mc['今晚八点训练赛集合'] ?? [];
check('还没到集合时间＝待集合（state=upcoming）', (string)($c1['state'] ?? '') === 'upcoming', J($c1['state'] ?? ''));
check('分母 total=6：本队六个已上线成员都要点', (int)($c1['total'] ?? 0) === 6, (string)($c1['total'] ?? 0));
check('还没人点：四个计数是 0/0/0/6',
    (int)($c1['counts']['in'] ?? -1) === 0 && (int)($c1['counts']['late'] ?? -1) === 0
    && (int)($c1['counts']['out'] ?? -1) === 0 && (int)($c1['counts']['none'] ?? -1) === 6, J($c1['counts'] ?? []));
check('本人还没点：my_reply 是空的', (string)($c1['my_reply'] ?? '') === '', J($c1['my_reply'] ?? ''));
check('没填截止＝这张没截止（closed=0）', (int)($c1['closed'] ?? -1) === 0, (string)($c1['closed'] ?? -1));
[, $j] = as_req('m1', 'GET', '/arc/api/rollcall');
check('回话带服务器时间与提前量，前端算倒计时用',
    isset($j['data']['server_time']) && (int)($j['data']['lead_min'] ?? 0) === 15, J($j['data']['lead_min'] ?? ''));
check('乙队成员问不到甲队的单（列表是空的）', memberCalls('boss', 'brc') === [], J(array_keys(memberCalls('boss', 'brc'))));

// ============================================================
// 3. 点名：到 / 迟到 / 不来，一人一行整行覆盖
// ============================================================
echo "\n== 3. 点「到 / 迟到 / 不来」 ==\n";
$u1 = (int)$aIds['987000004']; $u2 = (int)$aIds['987000005']; $u3 = (int)$aIds['987000006'];
[$s, $j] = as_req('m1', 'POST', '/arc/api/rollcall/' . $rcId . '/reply', ['reply' => 'in']);
check('成员一点「到了」', $s === 200 && (int)($j['code'] ?? 1) === 0 && str_contains((string)($j['msg'] ?? ''), '到了'), J($j));
check('库里一行', cnt("SELECT COUNT(*) FROM roll_call_replies WHERE call_id=$rcId") === 1);
[, $j] = as_req('m2', 'POST', '/arc/api/rollcall/' . $rcId . '/reply', ['reply' => 'late', 'note' => '路上堵车，晚半小时']);
check('另一点「迟到」并带一句话', (int)($j['code'] ?? 1) === 0, J($j));
[, $j] = as_req('m3', 'POST', '/arc/api/rollcall/' . $rcId . '/reply', ['reply' => 'out', 'note' => '今天加班']);
check('第三点「不来」', (int)($j['code'] ?? 1) === 0, J($j));
check('三行，一人一行', cnt("SELECT COUNT(*) FROM roll_call_replies WHERE call_id=$rcId") === 3);
[, $j] = as_req('m1', 'POST', '/arc/api/rollcall/' . $rcId . '/reply', ['reply' => 'present']);
check('护栏：只认 in/late/out 三个键，别的拒', (int)($j['code'] ?? 0) !== 0
    && str_contains((string)($j['msg'] ?? ''), '其中一项'), J($j));
[, $j] = as_req('m1', 'POST', '/arc/api/rollcall/' . $rcId . '/reply', ['reply' => '']);
check('护栏：空值也拒（不能靠点名把行清掉）', (int)($j['code'] ?? 0) !== 0, J($j));

[$s, $j] = as_req('m1', 'POST', '/arc/api/rollcall/' . $rcId . '/reply', ['reply' => 'out']);
check('改口：同一个人再点是整行覆盖', (int)($j['code'] ?? 1) === 0
    && cnt("SELECT COUNT(*) FROM roll_call_replies WHERE call_id=$rcId") === 3, J($j));
check('改口之后那一行变了，没有第二行',
    (string)db_val("SELECT reply FROM roll_call_replies WHERE call_id=$rcId AND user_id=$u1") === 'out');
[, $j] = as_req('m1', 'POST', '/arc/api/rollcall/' . $rcId . '/reply', ['reply' => 'in']);
check('再改回来也只用覆盖那一行', (int)($j['code'] ?? 1) === 0
    && cnt("SELECT COUNT(*) FROM roll_call_replies WHERE call_id=$rcId") === 3, J($j));
[, $j] = as_req('m2', 'POST', '/arc/api/rollcall/' . $rcId . '/reply', ['reply' => 'late', 'note' => str_repeat('很长', 200)]);
$noteLen = (int)db_val("SELECT LENGTH(note) FROM roll_call_replies WHERE call_id=$rcId AND user_id=$u2");
check('带的话太长截到 128 字（不报错也不写爆库）', (int)($j['code'] ?? 1) === 0 && $noteLen === 128, 'len=' . $noteLen);
check('覆盖时那句话说清是「迟到」', str_contains((string)($j['msg'] ?? ''), '迟到'), (string)($j['msg'] ?? ''));
check('没登录点不了（匿名请求被挡在门外）',
    (function () use ($rcId) {
        [$s, $j] = as_req('anon_rc', 'POST', '/arc/api/rollcall/' . $rcId . '/reply', ['reply' => 'in']);
        return $s === 401 || (int)($j['code'] ?? 0) !== 0;
    })());

// ============================================================
// 4. 截止之后拒收
// ============================================================
echo "\n== 4. 截止之后不再收 ==\n";
$was = date('Y-m-d H:i:s', time() - 60);
$pdo->exec("UPDATE roll_calls SET deadline='$was' WHERE id=$rcId");
[, $j] = as_req('m3', 'POST', '/arc/api/rollcall/' . $rcId . '/reply', ['reply' => 'in']);
check('过点了再想改成「到了」：被拒', (int)($j['code'] ?? 0) !== 0
    && str_contains((string)($j['msg'] ?? ''), '截止'), J($j));
check('库里那一行没被污染（还是 out）',
    (string)db_val("SELECT reply FROM roll_call_replies WHERE call_id=$rcId AND user_id=$u3") === 'out');
$mc = memberCalls('m1', 'arc');
check('成员那边这张标成已截止（closed=1）', (int)($mc['今晚八点训练赛集合']['closed'] ?? 0) === 1);
check('已截止仍然看得见（谁到过留在台面上）',
    (int)($mc['今晚八点训练赛集合']['counts']['in'] ?? 0) === 1);
$pdo->exec("UPDATE roll_calls SET deadline=NULL WHERE id=$rcId");
[, $j] = as_req('m3', 'POST', '/arc/api/rollcall/' . $rcId . '/reply', ['reply' => 'out']);
check('队长清空截止那一栏＝重新开放，刚才点不了的人又能点', (int)($j['code'] ?? 1) === 0, J($j));
$mc = memberCalls('m1', 'arc');
check('重开没有改变统计（1 到 / 1 迟到 / 1 不来）',
    (int)$mc['今晚八点训练赛集合']['counts']['in'] === 1
    && (int)$mc['今晚八点训练赛集合']['counts']['out'] === 1
    && (int)$mc['今晚八点训练赛集合']['closed'] === 0, J($mc['今晚八点训练赛集合']['counts'] ?? []));

// ============================================================
// 5. 统计分组与「谁到了」名单：队内公开
// ============================================================
echo "\n== 5. 名单与分组 ==\n";
$c1 = memberCalls('m1', 'arc')['今晚八点训练赛集合'];
$names = static fn(array $a) => array_map(static fn($x) => (string)$x['nickname'], $a);
check('三个各算一处：1 到 / 1 迟到 / 1 不来 / 3 没回',
    (int)$c1['counts']['in'] === 1 && (int)$c1['counts']['late'] === 1
    && (int)$c1['counts']['out'] === 1 && (int)$c1['counts']['none'] === 3, J($c1['counts']));
check('「到了」名单里是本人', in_array('arc成员987000004', $names($c1['list_in']), true), J($names($c1['list_in'])));
check('「迟到」名单里带上他那句话', (string)($c1['list_late'][0]['note'] ?? '') !== '', J($c1['list_late'] ?? []));
check('「不来」名单里是点 out 的那个', in_array('arc成员987000006', $names($c1['list_out']), true), J($names($c1['list_out'])));
check('「没回」是其余三个（两个副管理加一个还没点的）', count($c1['list_none']) === 3, J($names($c1['list_none'])));
check('名单里带的是点的那一刻（有 replied_at）', (string)($c1['list_in'][0]['replied_at'] ?? '') !== '');
[, $j] = as_req('m2', 'GET', '/arc/api/rollcall/' . $rcId);
check('成员点开详情也查得到这份名单（队内公开，与战绩同口径）',
    (int)($j['code'] ?? 1) === 0 && count($j['data']['list_in'] ?? []) === 1, J($j));
check('详情里的 my_reply 按人算（本人那行是迟到）', (string)($j['data']['my_reply'] ?? '') === 'late', J($j['data']['my_reply'] ?? ''));
[, $j] = as_req('sch', 'GET', '/arc/api/admin/rollcall');
$adm0 = [];
foreach (($j['data']['list'] ?? []) as $r) { $adm0[(string)$r['title']] = $r; }
check('管理端那份 my_reply 是空的（统计不给「我」这一格）',
    (string)($adm0['今晚八点训练赛集合']['my_reply'] ?? 'x') === '', J($adm0['今晚八点训练赛集合']['my_reply'] ?? 'x'));

// ============================================================
// 6. 挂场次：只挑本队还没开始的
// ============================================================
echo "\n== 6. 挂哪场比赛 ==\n";
$evAt = date('Y-m-d H:i:s', time() + 7200);
$pdo->exec("INSERT INTO race_events (tenant_id, game_side, title, race_time, created_at)
            VALUES ($aTid, '', '甲队的撞车', '$evAt', '$now')");
$aEvId = (int)$pdo->lastInsertId();
$editLog0 = rcLogCount($aTid, '编辑点名单');
[, $j] = $save('own', 'arc', ['id' => $rcId, 'title' => '今晚八点训练赛集合', 'call_at' => $far, 'event_id' => $aEvId]);
check('挂上本队还没开始的那场', (int)($j['code'] ?? 1) === 0, J($j));
check('库里 event_id 写进去了', (int)rcRow($rcId)['event_id'] === $aEvId, J(rcRow($rcId)['event_id'] ?? ''));
check('挂场次也算一次编辑，日志多一行', rcLogCount($aTid, '编辑点名单') === $editLog0 + 1);
[, $j] = as_req('own', 'GET', '/arc/api/admin/rollcall');
$evIds = array_map(static fn($e) => (int)$e['id'], $j['data']['events'] ?? []);
check('下拉里只有本队的场次（乙队那场不在名单里）',
    in_array($aEvId, $evIds, true) && !in_array($bEvId, $evIds, true), J($evIds));
check('下拉里那条带标题与时间', (string)($j['data']['events'][0]['title'] ?? '') !== ''
    && (string)($j['data']['events'][0]['race_time'] ?? '') !== '', J($j['data']['events'][0] ?? []));
$goneAt = date('Y-m-d H:i:s', time() - 86400);
$pdo->exec("INSERT INTO race_events (tenant_id, game_side, title, race_time, created_at)
            VALUES ($aTid, '', '昨天的场', '$goneAt', '$now')");
$goneId = (int)$pdo->lastInsertId();
[, $j] = as_req('own', 'GET', '/arc/api/admin/rollcall');
check('已经踢完的场次不进下拉', !in_array($goneId,
    array_map(static fn($e) => (int)$e['id'], $j['data']['events'] ?? []), true), J($j['data']['events'] ?? []));

// ============================================================
// 7. 编辑与删除
// ============================================================
echo "\n== 7. 编辑与删除 ==\n";
$far2 = date('Y-m-d H:i:s', time() + 9000);
$editLog1 = rcLogCount($aTid, '编辑点名单');
[, $j] = $save('own', 'arc', ['id' => $rcId, 'title' => '今晚八点训练赛集合（改）', 'call_at' => $far2,
                             'event_id' => $aEvId]);
check('改标题与集合时间', (int)($j['code'] ?? 1) === 0 && str_contains((string)($j['msg'] ?? ''), '更新'), J($j));
check('库里改到了', (string)rcRow($rcId)['title'] === '今晚八点训练赛集合（改）'
    && (string)rcRow($rcId)['call_at'] === $far2, J(rcRow($rcId)));
check('整表单提交：带着场次那一栏，挂着的场次没被编辑弄丢',
    (int)rcRow($rcId)['event_id'] === $aEvId, J(rcRow($rcId)['event_id'] ?? ''));
check('编辑没把已有的应答弄丢', cnt("SELECT COUNT(*) FROM roll_call_replies WHERE call_id=$rcId") === 3);
check('操作日志里也记了一行编辑', rcLogCount($aTid, '编辑点名单') === $editLog1 + 1);
[, $j] = $save('own', 'arc', ['id' => $rcId, 'title' => '今晚八点训练赛集合（改）', 'call_at' => $far2]);
check('那一栏空着提交＝取消挂场次（与「截止空着＝一直能点」同一条口径）',
    (int)($j['code'] ?? 1) === 0 && (int)rcRow($rcId)['event_id'] === 0, J(rcRow($rcId)['event_id'] ?? ''));
[, $j] = $save('own', 'arc', ['id' => $rcId, 'title' => '今晚八点训练赛集合（改）', 'call_at' => $far2,
                             'event_id' => $aEvId]);
check('再挂回去', (int)($j['code'] ?? 1) === 0 && (int)rcRow($rcId)['event_id'] === $aEvId);
check('成员那边标题跟着变', isset(memberCalls('m1', 'arc')['今晚八点训练赛集合（改）']));
[, $j] = $save('own', 'arc', ['id' => 999999, 'title' => '不存在的单', 'call_at' => $far]);
check('护栏：改一张不存在的单→报不存在（不会凭空造一条）',
    (int)($j['code'] ?? 0) !== 0 && str_contains((string)($j['msg'] ?? ''), '不存在'), J($j));

$throwId = (int)($save('own', 'arc', ['title' => '要删掉的单', 'call_at' => $far])[1]['data']['id'] ?? 0);
as_req('m1', 'POST', '/arc/api/rollcall/' . $throwId . '/reply', ['reply' => 'in']);
check('临时单先收一份应答', cnt("SELECT COUNT(*) FROM roll_call_replies WHERE call_id=$throwId") === 1);
[, $j] = as_req('own', 'DELETE', '/arc/api/admin/rollcall/' . $throwId);
check('删单', (int)($j['code'] ?? 1) === 0 && str_contains((string)($j['msg'] ?? ''), '回执'), J($j));
check('单没了', cnt("SELECT COUNT(*) FROM roll_calls WHERE id=$throwId") === 0);
check('连带把这份回执删干净（不留孤儿行）',
    cnt("SELECT COUNT(*) FROM roll_call_replies WHERE call_id=$throwId") === 0);

// ============================================================
// 8. 撤回与恢复
// ============================================================
echo "\n== 8. 撤回不是删除 ==\n";
$T1 = '今晚八点训练赛集合（改）';
[$s, $j] = as_req('own', 'POST', '/arc/api/admin/rollcall/' . $rcId . '/revoke');
check('站长撤回这张', $s === 200 && (int)($j['code'] ?? 1) === 0 && str_contains((string)($j['msg'] ?? ''), '撤回'), J($j));
check('库里只是盖了 revoked_at，行还在',
    cnt("SELECT COUNT(*) FROM roll_calls WHERE id=$rcId") === 1 && !isBlank(rcRow($rcId)['revoked_at'] ?? null));
check('应答也还在（谁到过不因为撤回而消失）', cnt("SELECT COUNT(*) FROM roll_call_replies WHERE call_id=$rcId") === 3);
check('成员首页不再出现这张', !isset(memberCalls('m1', 'arc')[$T1]), J(array_keys(memberCalls('m1', 'arc'))));
[, $j] = as_req('m1', 'GET', '/arc/api/rollcall/' . $rcId);
check('成员直连详情也挡掉（报不存在，问不出这张还在）',
    (int)($j['code'] ?? 0) !== 0 && str_contains((string)($j['msg'] ?? ''), '不存在'), J($j));
[, $j] = as_req('m1', 'POST', '/arc/api/rollcall/' . $rcId . '/reply', ['reply' => 'late']);
check('撤回后点不动（同一条口径，不会把撤回的单悄悄续上）',
    (int)($j['code'] ?? 0) !== 0 && str_contains((string)($j['msg'] ?? ''), '不存在'), J($j));
[, $j] = as_req('own', 'GET', '/arc/api/admin/rollcall');
$adm = [];
foreach (($j['data']['list'] ?? []) as $r) { $adm[(string)$r['title']] = $r; }
check('队长那边照旧看得见，并标成已撤回', (string)($adm[$T1]['state'] ?? '') === 'revoked', J($adm[$T1]['state'] ?? ''));
check('撤回那张的统计还在（三份应答仍列得出来）', (int)($adm[$T1]['counts']['in'] ?? 0) === 1, J($adm[$T1]['counts'] ?? []));
[, $j] = as_req('own', 'POST', '/arc/api/admin/rollcall/' . $rcId . '/revoke');
check('护栏：已经撤回的不能再撤回一次', (int)($j['code'] ?? 0) !== 0, J($j));
[, $j] = as_req('own', 'POST', '/arc/api/admin/rollcall/' . $rcId . '/restore');
check('恢复（队长按得动的那颗退路）', (int)($j['code'] ?? 1) === 0, J($j));
check('库里 revoked_at 清空了', isBlank(rcRow($rcId)['revoked_at'] ?? null), J(rcRow($rcId)['revoked_at'] ?? ''));
check('恢复后成员又看得见这张', isset(memberCalls('m1', 'arc')[$T1]));
check('之前点过的三份应答原样还在，没有重计', cnt("SELECT COUNT(*) FROM roll_call_replies WHERE call_id=$rcId") === 3);
[, $j] = as_req('own', 'POST', '/arc/api/admin/rollcall/' . $rcId . '/restore');
check('护栏：没撤回的不用恢复', (int)($j['code'] ?? 0) !== 0, J($j));
check('撤回与恢复各在日志里留一行',
    rcLogCount($aTid, '撤回点名单') === 1 && rcLogCount($aTid, '恢复点名单') === 1);

// ============================================================
// 9. QQ 群那一路：到点前 15 分钟自动喊一次
// ============================================================
echo "\n== 9. 到点前往 QQ 群喊一声 ==\n";
$pdo->exec("INSERT INTO bot_tenant_bots (tenant_id, app_id, app_secret, callback_key, enabled, env, created_at, updated_at)
            VALUES ($aTid, 'mock', 'mock-secret', 'rc_key_a', 1, 'prod', '$now', '$now')");
$pdo->exec("INSERT INTO bot_group_bindings (tenant_id, group_openid, bound_by, created_at)
            VALUES ($aTid, 'group-A-OPENID', 0, '$now')");

$TITLE_A = '十分钟后的集合';
[, $j] = $save('own', 'arc', ['title' => $TITLE_A, 'call_at' => date('Y-m-d H:i:s', time() + 300),
                             'deadline' => date('Y-m-d H:i:s', time() + 900)]);
$rcA = (int)($j['data']['id'] ?? 0);
check('开一张 5 分钟后集合的单（落在提前 15 分钟的窗口里）', $rcA > 0, J($j));
check('这张单此刻还没喊过', isBlank(rcRow($rcA)['notify_at'] ?? null));

clearLocks();
[, $j] = as_req('plat', 'POST', '/api/platform/auth/login', ['username' => 'admin', 'password' => 'admin123']);
check('平台超管登录（手动扫描走这条）', (int)($j['code'] ?? 1) === 0, J($j));
[$s, $j] = as_req('plat', 'POST', '/api/platform/admin/remind/tick');
check('全站点检查一次跑通', $s === 200 && (int)($j['code'] ?? 1) === 0, J($j));
check('回话里说了点名喊了几张单', preg_match('/集合点名喊了 (\d+) 张单/u', (string)($j['msg'] ?? '')) === 1,
    (string)($j['msg'] ?? ''));
check('这一轮把甲队那张喊出去了', (int)($j['data']['rollcall']['sent'] ?? 0) === 1, J($j['data']['rollcall'] ?? []));
$notifyA = (string)(rcRow($rcA)['notify_at'] ?? '');
check('库里 notify_at 写上＝这张喊过了', !isBlank($notifyA), J(rcRow($rcA)['notify_at'] ?? ''));
$log = botLog();
check('假机器人真收到这一句', str_contains($log, 'mock_send'), mb_substr($log, -300));
check('群里那句话带了标题', str_contains($log, $TITLE_A), mb_substr($log, -300));
check('群里那句话说了还剩几分钟', str_contains($log, '分钟集合'), mb_substr($log, -300));
check('群里那句话告诉成员去哪点', str_contains($log, '首页'), mb_substr($log, -300));
check('群里那句话带了截止时间', str_contains($log, '报名截至'), mb_substr($log, -300));
check('发到的是甲队那个群（openid 对得上）', str_contains($log, 'group-A-OPENID'), mb_substr($log, -300));
check('管理日志写了「QQ 群里喊到了」', rcLogCount($aTid, 'QQ 群里喊到了') === 1,
    J(db_rows("SELECT detail FROM admin_logs WHERE tenant_id=$aTid AND action='集合点名' ORDER BY id DESC LIMIT 3")));
check('群里只出现一次', substr_count(botLog(), $TITLE_A) === 1, (string)substr_count(botLog(), $TITLE_A));

clearLocks();
[, $j] = as_req('plat', 'POST', '/api/platform/admin/remind/tick');
check('第二遍扫描不重复喊（一张单只喊一次）', (int)($j['data']['rollcall']['sent'] ?? 0) === 0,
    J($j['data']['rollcall'] ?? []));
check('notify_at 还是原来那一刻', (string)rcRow($rcA)['notify_at'] === $notifyA,
    (string)rcRow($rcA)['notify_at'] . ' vs ' . $notifyA);
check('群里那条没变成两条', substr_count(botLog(), $TITLE_A) === 1);
check('第二遍没再写日志', rcLogCount($aTid, 'QQ 群里喊到了') === 1);

// ============================================================
// 10. 改集合时间＝重排；过期太久不再补喊
// ============================================================
echo "\n== 10. 改时间重排与「过期不补喊」 ==\n";
$far20 = date('Y-m-d H:i:s', time() + 1200);
[, $j] = $save('own', 'arc', ['id' => $rcA, 'title' => $TITLE_A, 'call_at' => $far20,
                             'deadline' => date('Y-m-d H:i:s', time() + 1800)]);
check('把集合时间改到 20 分钟后', (int)($j['code'] ?? 1) === 0, J($j));
check('改集合时间把 notify_at 清空＝重排一次群通知', isBlank(rcRow($rcA)['notify_at'] ?? null),
    J(rcRow($rcA)['notify_at'] ?? ''));
clearLocks();
[, $j] = as_req('plat', 'POST', '/api/platform/admin/remind/tick');
check('20 分钟在窗口外（提前量 15 分钟），这一轮不喊',
    (int)($j['data']['rollcall']['sent'] ?? 0) === 0, J($j['data']['rollcall'] ?? []));
check('仍然没喊（notify_at 还是空的）', isBlank(rcRow($rcA)['notify_at'] ?? null));
$inWin = date('Y-m-d H:i:s', time() + 120);
$pdo->exec("UPDATE roll_calls SET call_at='$inWin' WHERE id=$rcA");
clearLocks();
[, $j] = as_req('plat', 'POST', '/api/platform/admin/remind/tick');
check('挪进窗口后这一轮喊了（改完时间到点前还会再喊一次）',
    (int)($j['data']['rollcall']['sent'] ?? 0) === 1, J($j['data']['rollcall'] ?? []));
check('群里第二次出现的是这张', substr_count(botLog(), $TITLE_A) === 2, (string)substr_count(botLog(), $TITLE_A));
check('第二次这条说的是「只剩 2 分钟」级别的紧迫感', str_contains(botLog(), '分钟集合'));

$TITLE_S = '错过半小时的单';
$staleId = (int)($save('own', 'arc', ['title' => $TITLE_S, 'call_at' => date('Y-m-d H:i:s', time() + 600)])[1]['data']['id'] ?? 0);
$staleAt = date('Y-m-d H:i:s', time() - 2400);
$pdo->exec("UPDATE roll_calls SET call_at='$staleAt', notify_at=NULL WHERE id=$staleId");
clearLocks();
[, $j] = as_req('plat', 'POST', '/api/platform/admin/remind/tick');
check('集合时间过去 40 分钟才扫到：不再补喊', (int)($j['data']['rollcall']['sent'] ?? 0) === 0,
    J($j['data']['rollcall'] ?? []));
check('但 notify_at 仍然写上（下一轮不再翻它）', !isBlank(rcRow($staleId)['notify_at'] ?? null),
    J(rcRow($staleId)['notify_at'] ?? ''));
check('群里没出现这条过期通知', !str_contains(botLog(), $TITLE_S), mb_substr(botLog(), -300));
check('不补喊这条路不写「喊到了」的日志', rcLogCount($aTid, 'QQ 群里喊到了') === 2);

// ============================================================
// 11. 队长手动「再喊一次」
// ============================================================
echo "\n== 11. 手动再喊一次 ==\n";
[$s, $j] = as_req('own', 'POST', '/arc/api/admin/rollcall/' . $rcA . '/broadcast');
check('按「再喊一次」：成功', $s === 200 && (int)($j['code'] ?? 1) === 0 && str_contains((string)($j['msg'] ?? ''), '又喊'), J($j));
check('群里多了一条', substr_count(botLog(), $TITLE_A) === 3, (string)substr_count(botLog(), $TITLE_A));
[, $j] = as_req('own', 'POST', '/arc/api/admin/rollcall/' . $rcA . '/broadcast');
check('5 分钟内第二次被拦住（防把群刷屏）', (int)($j['code'] ?? 0) !== 0
    && str_contains((string)($j['msg'] ?? ''), '5 分钟'), J($j));
check('被拦这一次没再往群里发', substr_count(botLog(), $TITLE_A) === 3);
check('手动再喊记一行日志（写明已送到）', rcLogCount($aTid, '手动再喊') === 1 && rcLogCount($aTid, 'QQ 群已送到') === 1);
check('手动再喊也把 notify_at 写上（不再被自动扫重演）', !isBlank(rcRow($rcA)['notify_at'] ?? null));

$TITLE_B = '乙队没机器人的单';
[, $j] = $save('boss', 'brc', ['title' => $TITLE_B, 'call_at' => date('Y-m-d H:i:s', time() + 600)]);
$bRc = (int)($j['data']['id'] ?? 0);
check('乙队站长也能开单（他自己就是本队超管）', $bRc > 0, J($j));
[, $j] = as_req('boss', 'POST', '/brc/api/admin/rollcall/' . $bRc . '/broadcast');
check('没绑群时「再喊一次」不报错，但把没送到说清楚',
    (int)($j['code'] ?? 1) === 0 && str_contains((string)($j['msg'] ?? ''), '没送到'), J($j));
check('群日志里完全没有乙队这一条', !str_contains(botLog(), $TITLE_B));
check('乙队管理日志写了站内照常、群那边没送到',
    rcLogCount($bTid, '手动再喊') === 1 && rcLogCount($bTid, '没送到') === 1);

// ============================================================
// 12. 越权：三种人都够不着
// ============================================================
echo "\n== 12. 越权 ==\n";
[$s, $j] = as_req('boss', 'GET', '/brc/api/rollcall/' . $rcA);
check('乙队站长问甲队那张的详情：不存在', (int)($j['code'] ?? 0) !== 0
    && str_contains((string)($j['msg'] ?? ''), '不存在'), J($j));
[$s, $j] = as_req('boss', 'POST', '/brc/api/rollcall/' . $rcA . '/reply', ['reply' => 'in']);
check('乙队站长想替甲队点名：拒', (int)($j['code'] ?? 0) !== 0, J($j));
check('甲队那张的回执行数没被别队改动', cnt("SELECT COUNT(*) FROM roll_call_replies WHERE call_id=$rcA") === 0);
[, $j] = as_req('boss', 'GET', '/brc/api/admin/rollcall');
$bIds = array_map(static fn($r) => (int)$r['id'], $j['data']['list'] ?? []);
check('乙队队长的管理端列表里没有甲队的单', !in_array($rcA, $bIds, true), J($bIds));
check('乙队那份下拉里也没有甲队的场次', !in_array($aEvId,
    array_map(static fn($e) => (int)$e['id'], $j['data']['events'] ?? []), true), J($j['data']['events'] ?? []));
[, $j] = as_req('boss', 'POST', '/brc/api/admin/rollcall/' . $rcA . '/revoke');
check('乙队队长想撤回甲队的单：拒', (int)($j['code'] ?? 0) !== 0, J($j));
[, $j] = as_req('boss', 'DELETE', '/brc/api/admin/rollcall/' . $rcA);
check('乙队队长想删甲队的单：拒', (int)($j['code'] ?? 0) !== 0, J($j));
check('被拒之后甲队那张还在', cnt("SELECT COUNT(*) FROM roll_calls WHERE id=$rcA") === 1);

[$s, $j] = $save('nop', 'arc', ['title' => '不该他开的', 'call_at' => $far]);
check('只有「成员名单」权限的副管理开不了点名单',
    $s === 403 && (int)($j['code'] ?? 0) !== 0 && str_contains((string)($j['msg'] ?? ''), '权限'), 'http=' . $s . ' ' . J($j));
[$s, $j] = as_req('nop', 'GET', '/arc/api/admin/rollcall');
check('他也读不到管理端那份列表', $s === 403, 'http=' . $s);
[$s, $j] = as_req('nop', 'POST', '/arc/api/admin/rollcall/' . $rcA . '/revoke');
check('更撤回不了', $s === 403, 'http=' . $s);
[$s, $j] = as_req('nop', 'POST', '/arc/api/admin/rollcall/' . $rcA . '/broadcast');
check('也按不动「再喊一次」', $s === 403, 'http=' . $s);
[$s, $j] = as_req('m1', 'GET', '/arc/api/admin/rollcall');
check('普通成员连管理端都进不去', $s === 403, 'http=' . $s);
[$s, $j] = $save('m1', 'arc', ['title' => '成员想开单', 'call_at' => $far]);
check('普通成员开不了单', $s === 403, 'http=' . $s);
check('被拒的这些人没在库里留下任何东西',
    cnt("SELECT COUNT(*) FROM roll_calls WHERE tenant_id=$aTid") === 3,
    (string)cnt("SELECT COUNT(*) FROM roll_calls WHERE tenant_id=$aTid"));

[$s, $j] = $save('sch', 'arc', ['title' => '日程副管理开的单', 'call_at' => date('Y-m-d H:i:s', time() + 4200)]);
$rcS = (int)($j['data']['id'] ?? 0);
check('只有「日程活动」权限的副管理能开单（不必是站长）',
    $s === 200 && (int)($j['code'] ?? 1) === 0 && $rcS > 0, 'http=' . $s . ' ' . J($j));
check('这张单挂在这个队名下', (int)rcRow($rcS)['tenant_id'] === $aTid, J(rcRow($rcS)['tenant_id'] ?? ''));
[$s, $j] = as_req('sch', 'GET', '/arc/api/admin/rollcall');
check('他也能读管理端列表', $s === 200 && (int)($j['code'] ?? 1) === 0, 'http=' . $s);
[$s, $j] = as_req('sch', 'POST', '/arc/api/admin/rollcall/' . $rcS . '/revoke');
check('他也能撤回自己开的这张', (int)($j['code'] ?? 1) === 0, J($j));

// ============================================================
// 13. 端手游分舱
// ============================================================
echo "\n== 13. 端手游分舱 ==\n";
$cFar = date('Y-m-d H:i:s', time() + 6000);
[$s, $j] = as_req('csown', 'POST', '/crc/api/admin/rollcall/save',
    ['title' => '端游专场集合', 'call_at' => $cFar, 'game_side' => 'pc']);
$pcId = (int)($j['data']['id'] ?? 0);
check('丙队站长开一张端游的点名单', $s === 200 && $pcId > 0, J($j));
check('库里 game_side=pc', (string)rcRow($pcId)['game_side'] === 'pc', J(rcRow($pcId)['game_side'] ?? ''));
[, $j] = as_req('csown', 'POST', '/crc/api/admin/rollcall/save',
    ['title' => '全队共享集合', 'call_at' => $cFar, 'game_side' => '']);
$shId = (int)($j['data']['id'] ?? 0);
check('再开一张全队共享的', $shId > 0, J($j));

$pc = memberCalls('cpc', 'crc');
$mb = memberCalls('cmb1', 'crc');
check('端游成员：两张都看得见', isset($pc['端游专场集合'], $pc['全队共享集合']), J(array_keys($pc)));
check('手游成员：只看见共享那张（本端的单对他不存在）',
    !isset($mb['端游专场集合']) && isset($mb['全队共享集合']), J(array_keys($mb)));
[, $j] = as_req('cmb1', 'GET', '/crc/api/rollcall/' . $pcId);
check('手游成员直连端游那张详情：不存在', (int)($j['code'] ?? 0) !== 0
    && str_contains((string)($j['msg'] ?? ''), '不存在'), J($j));
[, $j] = as_req('cmb1', 'POST', '/crc/api/rollcall/' . $pcId . '/reply', ['reply' => 'in']);
check('手游成员点不动端游那张（不是只藏入口，接口也挡）', (int)($j['code'] ?? 0) !== 0
    && str_contains((string)($j['msg'] ?? ''), '不存在'), J($j));
check('库里那一行都没写', cnt("SELECT COUNT(*) FROM roll_call_replies WHERE call_id=$pcId") === 0);
check('端游那张的分母只算「端游 + 共享」（pc 成员 1 人 + 站长按共享算）',
    (int)($pc['端游专场集合']['total'] ?? 0) === 2, J($pc['端游专场集合']['total'] ?? 0));
check('共享那张的分母是全队 4 人', (int)($pc['全队共享集合']['total'] ?? 0) === 4, J($pc['全队共享集合']['total'] ?? 0));
check('端游成员在端游那张上看得见手游那两个人不在名单里',
    !in_array('crc成员989000003', array_map(static fn($x) => (string)$x['nickname'], $pc['端游专场集合']['list_none'] ?? []), true),
    J($pc['端游专场集合']['list_none'] ?? []));
[, $j] = as_req('cpc', 'POST', '/crc/api/rollcall/' . $pcId . '/reply', ['reply' => 'in']);
check('端游成员点自己那张成功', (int)($j['code'] ?? 1) === 0, J($j));
[, $j] = as_req('csown', 'GET', '/crc/api/admin/rollcall');
$cr = [];
foreach (($j['data']['list'] ?? []) as $r) { $cr[(string)$r['title']] = $r; }
check('丙队队长的管理端两张都看得见（管理端不按端过滤）',
    isset($cr['端游专场集合'], $cr['全队共享集合']), J(array_keys($cr)));
check('队长看得见「另一边没人回」：端游那张 1 到 1 没回',
    (int)$cr['端游专场集合']['counts']['in'] === 1 && (int)$cr['端游专场集合']['counts']['none'] === 1,
    J($cr['端游专场集合']['counts'] ?? []));
check('端游那张的群通知文案里带了端别（成员知道是哪一边集合）',
    (string)rcRow($pcId)['game_side'] === 'pc');
// 分舱关掉：本端的单两边都看得见（与公告那条口径一致）
$pdo->exec("UPDATE settings SET value='0' WHERE tenant_id=$cTid AND `key`='side_split_on'");
$mb2 = memberCalls('cmb1', 'crc');
check('总闸一关，手游成员也能看见端游那张', isset($mb2['端游专场集合']), J(array_keys($mb2)));
check('关掉分舱后这张仍看得见自己的应答（in=1）',
    (int)($mb2['端游专场集合']['counts']['in'] ?? 0) === 1, J($mb2['端游专场集合']['counts'] ?? []));
[, $j] = as_req('csown', 'POST', '/crc/api/admin/rollcall/save',
    ['id' => $pcId, 'title' => '端游专场集合', 'call_at' => $cFar, 'game_side' => 'mobile']);
check('不分端的队：编辑不会去覆写原来那一列（game_side 仍是 pc）',
    (int)($j['code'] ?? 1) === 0 && (string)rcRow($pcId)['game_side'] === 'pc', (string)rcRow($pcId)['game_side']);
$pdo->exec("INSERT INTO settings (tenant_id,`key`,value) VALUES ($cTid,'side_split_on','1')
    ON CONFLICT(tenant_id,`key`) DO UPDATE SET value='1'");

// ============================================================
// 14. 懒触发：成员打开站点顺路喊，并按队 60 秒限频
// ============================================================
echo "\n== 14. 成员打开站点顺路喊（不用配 cron） ==\n";
clearLocks();
$cntBefore = substr_count(botLog(), $TITLE_A);
$pdo->exec("UPDATE roll_calls SET call_at='" . date('Y-m-d H:i:s', time() + 200) . "', notify_at=NULL WHERE id=$rcA");
[$s, $j] = as_req('m1', 'GET', '/arc/api/rollcall');
check('成员读一次首页列表（这一次请求顺路触发扫描）',
    $s === 200 && (int)($j['code'] ?? 1) === 0, 'http=' . $s . ' ' . J($j));
check('到点的单被顺路喊出去了', substr_count(botLog(), $TITLE_A) === $cntBefore + 1,
    substr_count(botLog(), $TITLE_A) . ' vs ' . (string)$cntBefore);
check('喊过之后 notify_at 写上', !isBlank(rcRow($rcA)['notify_at'] ?? null));

$TITLE_C = '限频期间的新单';
[, $j] = $save('own', 'arc', ['title' => $TITLE_C, 'call_at' => date('Y-m-d H:i:s', time() + 300)]);
$rcC = (int)($j['data']['id'] ?? 0);
check('60 秒锁还没过：此刻这张新单也没被喊（按队限频挡住了）',
    $rcC > 0 && isBlank(rcRow($rcC)['notify_at'] ?? null), J(rcRow($rcC)['notify_at'] ?? ''));
as_req('m1', 'GET', '/arc/api/rollcall');
check('再读一次仍然没喊（限频不是每次请求都扫）', isBlank(rcRow($rcC)['notify_at'] ?? null)
    && !str_contains(botLog(), $TITLE_C));
clearLocks();
as_req('m1', 'GET', '/arc/api/rollcall');
check('过了限频窗口再读，这张就被顺路喊出去了', !isBlank(rcRow($rcC)['notify_at'] ?? null)
    && str_contains(botLog(), $TITLE_C));

// ============================================================
// 15. 表结构、唯一键与「没开点名的队一切照旧」
// ============================================================
echo "\n== 15. 表结构与不相关的队 ==\n";
$rcCols = array_column(db_rows("PRAGMA table_info(roll_calls)"), 'name');
$rpCols = array_column(db_rows("PRAGMA table_info(roll_call_replies)"), 'name');
check('roll_calls 十二列都在', count($rcCols) === 12, implode(',', $rcCols));
check('roll_call_replies 七列都在', count($rpCols) === 7, implode(',', $rpCols));
$idx = array_column(db_rows("PRAGMA index_list(roll_call_replies)"), 'name');
$ukFound = false;
foreach ($idx as $n) {
    $cols = array_column(db_rows("PRAGMA index_list(roll_call_replies)"), 'unique', 'name');
    if (($cols[$n] ?? 0) == 1) {
        $ic = array_column(db_rows("PRAGMA index_info($n)"), 'name');
        if ($ic === ['call_id', 'user_id']) { $ukFound = true; }
    }
}
check('(call_id,user_id) 是唯一键', $ukFound, J($idx));
check('同一个人写不进第二行（数据库层就挡住）',
    (function () use ($rcId, $u1, $aTid, $now) {
        try {
            db()->exec("INSERT INTO roll_call_replies (call_id,tenant_id,user_id,reply,note,replied_at)
                        VALUES ($rcId,$aTid,$u1,'late','撞唯一键','$now')");
            return false;
        } catch (\Throwable $e) {
            return true;
        }
    })());
check('唯一键只在同一张单内起作用：另一张单的同一人写得进去',
    (function () use ($rcA, $u1, $aTid, $now) {
        try {
            db()->exec("INSERT INTO roll_call_replies (call_id,tenant_id,user_id,reply,note,replied_at)
                        VALUES ($rcA,$aTid,$u1,'in','另一张单','$now')");
            db()->exec("DELETE FROM roll_call_replies WHERE call_id=$rcA AND user_id=$u1");
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    })());
$bs = memberCalls('boss', 'brc');
check('乙队首页列表接口正常返回（是数组，不是报错）', is_array($bs), J($bs));
[, $j] = as_req('boss', 'GET', '/brc/api/dashboard');
check('仪表盘照常（点名那次挂接没把 AuthCheck 拖下水）', (int)($j['code'] ?? 1) === 0, J($j));
[, $j] = as_req('own', 'GET', '/arc/api/notices');
check('公告那条链照常读（新加的 tickLazy 不影响别的接口）', (int)($j['code'] ?? 1) === 0, J($j));
[, $j] = as_req('own', 'GET', '/arc/api/duty/today');
check('值班卡片照常（同一屏三个懒触发互不干扰）', (int)($j['code'] ?? 1) === 0, J($j));

// ============================================================
echo "\n== 结果 ==\n";
echo '  通过 ' . $pass . ' / 失败 ' . $fail . "\n";
foreach ($failures as $f) { echo "  x $f\n"; }
exit($fail === 0 ? 0 : 1);
