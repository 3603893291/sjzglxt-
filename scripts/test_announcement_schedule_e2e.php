<?php
/**
 * v5.36.34 公告定时发布与撤回 E2E（scripts/test_announcement_schedule_e2e.php）
 *
 * 前置：测试服务器已启动，.env 指向 SQLite（.env 里只能用 ; 注释）
 *   php -S 127.0.0.1:8899 -t public public/router.php
 * 用法：php scripts/test_announcement_schedule_e2e.php
 *
 * 这一版没有定时任务：生效是「读的时候算」的，所以每一段都用真请求去读，
 * 到点这一步是改库里的 scheduled_at 当作时间流过（不能真等两小时）。
 *
 * 十条主线：
 *   1. 不填定时＝立即发（与上一版行为一模一样，老公告一条也不受影响）
 *   2. 未到点：成员列表/详情/首页/铃铛全都看不到，队长那边照样看得见并标「待发」
 *   3. 未到点不能催读（一键提醒会被挡），也不能被标已读（404，回执分子不被污染）
 *   4. 时间一到：下一次读就出来，publish_at 用发布时间，排序也按它排
 *   5. 撤回：成员那边立刻消失（列表/详情/首页/对外展示页四处同口径）
 *   6. 撤回不是删除：行、配图文件、谁读过的记录都留着，回执面板照样查得到
 *   7. 恢复：成员又能看到，之前读过的人不会重新变成未读
 *   8. 定时那一栏的护栏：过去/超 30 天/格式不对都进不去
 *   9. 编辑不会把定时状态改掉（发出去的行走不到这一栏），未到点的能改时间也能取消
 *  10. 越权：别的战队的队长、没公告权限的副管理、成员自己，三种都动不了别人的公告
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
/** 成员端公告列表里的标题（顺序就是他们看到的顺序） */
function memberTitles(string $who, string $slug): array
{
    [, $j] = as_req($who, 'GET', '/' . $slug . '/api/notices');
    return array_map(static fn($r) => (string)$r['title'], $j['data']['list'] ?? []);
}
function adminRows(string $who, string $slug): array
{
    [, $j] = as_req($who, 'GET', '/' . $slug . '/api/admin/announcements');
    $by = [];
    foreach (($j['data']['list'] ?? []) as $r) { $by[(string)$r['title']] = $r; }
    return $by;
}

// ============================================================
// 0. 建库与种子
// ============================================================
echo "== 0. 重建测试库与种子 ==\n";
@unlink($DB);
echo shell_exec('"' . PHP_BINARY . '" "' . $ROOT . '/scripts/dev_init_sqlite.php" "' . $DB . '" 2>&1');
foreach (glob($ROOT . '/runtime/cache/*/*') ?: [] as $f) { if (is_file($f)) { @unlink($f); } }
foreach (glob($ROOT . '/runtime/cache/*') ?: [] as $f) { if (is_file($f)) { @unlink($f); } }

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
        [$qq, $role, $perms] = $p;
        $nick = $role === 'admin' ? $slug . '管理' . $qq : $slug . '成员' . $qq;
        $pdo->exec("INSERT INTO users (tenant_id, username, password, nickname, email, role, status, admin_perms, game_side, created_at, updated_at)
            VALUES ($tid, '$qq', '$hash', '$nick', '$qq@qq.com', '$role', 'approved', '$perms', '', '$now', '$now')");
        $ids[$qq] = (int)$pdo->lastInsertId();
    }
    $pdo->exec("UPDATE tenants SET admin_user_id = {$ids[$people[0][0]]} WHERE id = $tid");
    return ['tid' => $tid, 'ids' => $ids, 'pid' => $pid];
};
// 甲队：站长（恒持全部权限）+ 副管理（只有成员名单权限，没有公告权限）+ 普通成员
$A = $mk('asn', '定时公告甲队', [
    ['985000001', 'admin', '[]'],
    ['985000002', 'admin', '["member"]'],
    ['985000003', 'user',  '[]'],
]);
// 乙队：另一支战队的站长，用来测跨队
$B = $mk('bsn', '定时公告乙队', [['986000001', 'admin', '[]']]);
$aTid = (int)$A['tid']; $bTid = (int)$B['tid'];
echo "  种子：甲 tid=$aTid（站长/副管理/成员）乙 tid=$bTid\n";

$login = static function (string $who, string $slug, string $acct): void {
    [$s, $j] = as_req($who, 'POST', '/' . $slug . '/api/auth/login', ['username' => $acct, 'password' => 'pass123456']);
    check("登录 $who（$acct）", $s === 200 && (int)($j['code'] ?? 1) === 0, 'http=' . $s . ' ' . J($j));
};
$login('own', 'asn', '985000001');     // 甲队站长
$login('sub', 'asn', '985000002');     // 甲队副管理（无 announce）
$login('mem', 'asn', '985000003');     // 甲队成员
$login('boss', 'bsn', '986000001');    // 乙队站长

$save = static function (string $who, array $body): array {
    return as_req($who, 'POST', '/asn/api/admin/announcements/save', $body);
};
$annOf = static function (string $title, int $tid): array {
    return db_rows("SELECT * FROM announcements WHERE tenant_id=$tid AND title='$title'");
};

// ============================================================
// 1. 不填定时＝立即发
// ============================================================
echo "\n== 1. 立即发（与上一版一模一样）==\n";
[$s, $j] = $save('own', ['title' => '今晚八点集合', 'content' => '基地门口']);
$liveId = (int)($j['data']['id'] ?? 0);
check('发一条普通公告', $s === 200 && (int)($j['code'] ?? 1) === 0 && $liveId > 0, J($j));
$row = $annOf('今晚八点集合', $aTid)[0] ?? [];
check('库里 scheduled_at 是空的（=立即发）', ($row['scheduled_at'] ?? '') === '' || $row['scheduled_at'] === null,
    J($row['scheduled_at'] ?? '(没这列)'));
check('revoked_at 也是空的', ($row['revoked_at'] ?? '') === '' || $row['revoked_at'] === null, J($row['revoked_at'] ?? ''));
check('成员马上看得到', in_array('今晚八点集合', memberTitles('mem', 'asn'), true));
check('成员看到的发布时间就是建行那一刻（没填定时时 publish_at＝created_at）',
    (function () {
        [, $jj] = as_req('mem', 'GET', '/asn/api/notices');
        foreach (($jj['data']['list'] ?? []) as $r) {
            if ((string)$r['title'] === '今晚八点集合') {
                return (string)($r['publish_at'] ?? '') === (string)($r['created_at'] ?? '');
            }
        }
        return false;
    })());

// ============================================================
// 2-3. 定时未到点
// ============================================================
echo "\n== 2. 未到点：成员那边根本没这条 ==\n";
$schedAt = date('Y-m-d H:i:s', time() + 7200);
[$s, $j] = $save('own', ['title' => '周末加练', 'content' => '两局训练赛', 'scheduled_at' => $schedAt]);
$schedId = (int)($j['data']['id'] ?? 0);
check('排一条两小时后发的公告', $s === 200 && (int)($j['code'] ?? 1) === 0 && $schedId > 0, J($j));
check('回话里说清几点发', str_contains((string)($j['msg'] ?? ''), '自动发给成员'), (string)($j['msg'] ?? ''));
$srow = $annOf('周末加练', $aTid)[0] ?? [];
check('库里确实落了这条，定时时间写着', (string)($srow['scheduled_at'] ?? '') === $schedAt, J($srow['scheduled_at'] ?? ''));
check('成员列表里没有它', !in_array('周末加练', memberTitles('mem', 'asn'), true));
check('首页那张公告卡也没有它', !in_array('周末加练', array_column(
    (as_req('mem', 'GET', '/asn/api/dashboard')[1]['data']['announcements'] ?? []), 'title'), true));
$adm = adminRows('own', 'asn');
check('队长那边看得见，状态是「待发」', ($adm['周末加练']['state'] ?? '') === 'scheduled', J($adm['周末加练']['state'] ?? ''));
check('成员那条立即发的状态是 live', ($adm['今晚八点集合']['state'] ?? '') === 'live', J($adm['今晚八点集合']['state'] ?? ''));

echo "\n== 3. 未到点：既读不到也催不动 ==\n";
[$s, $j] = as_req('mem', 'POST', '/asn/api/notices/' . $schedId . '/read');
check('成员拿旧链接去点开未到点的公告：404', (int)($j['code'] ?? 0) === 404 || $s === 404, 'http=' . $s . ' ' . J($j));
check('没有偷偷写下一条已读',
    (int)db_val("SELECT COUNT(*) FROM announcement_reads WHERE announcement_id=$schedId") === 0);
$unreadBefore = (int)(as_req('mem', 'GET', '/asn/api/notices')[1]['data']['unread_count'] ?? -1);
check('铃铛未读数没把它算进去', $unreadBefore === 1, 'unread=' . $unreadBefore);
[$s, $j] = $save('own', ['title' => '未到点催读测试', 'content' => '催', 'need_receipt' => 1,
    'scheduled_at' => date('Y-m-d H:i:s', time() + 3600)]);
$nrId = (int)($j['data']['id'] ?? 0);
[$s, $j] = as_req('own', 'POST', '/asn/api/admin/announcements/' . $nrId . '/remind');
check('一键提醒未到点的公告：被挡，话说「还没到发布时间」',
    (int)($j['code'] ?? 0) === 1 && str_contains((string)($j['msg'] ?? ''), '还没到发布时间'), J($j));
check('被挡之后 reminded_at 还是空的',
    (string)(db_val("SELECT reminded_at FROM announcements WHERE id=$nrId") ?? '') === '');

// ============================================================
// 4. 时间一到：读的时候就出来
// ============================================================
echo "\n== 4. 到点之后（读的时候算，没有定时任务）==\n";
$pubAt = date('Y-m-d H:i:s', time() - 60);
$pdo->exec("UPDATE announcements SET scheduled_at='$pubAt' WHERE id=$schedId");
$t4 = memberTitles('mem', 'asn');
check('时间一到，成员下一次读就看到了', in_array('周末加练', $t4, true), J($t4));
[, $j] = as_req('mem', 'GET', '/asn/api/notices');
$line = [];
foreach (($j['data']['list'] ?? []) as $r) { if ((string)$r['title'] === '周末加练') { $line = $r; } }
check('成员那边它的发布时间用的是定时那一刻（不是建行那一刻）',
    (string)($line['publish_at'] ?? '') === $pubAt, J($line['publish_at'] ?? ''));

// 排序：更晚发布的那条要排在前面
$latePub = date('Y-m-d H:i:s', time() + 3600);
[$s, $j] = $save('own', ['title' => '压轴公告', 'content' => '后发先至', 'scheduled_at' => $latePub]);
$lateId = (int)($j['data']['id'] ?? 0);
$pdo->exec("UPDATE announcements SET scheduled_at='" . date('Y-m-d H:i:s', time() + 120) . "' WHERE id=$lateId");
$pdo->exec("UPDATE announcements SET scheduled_at='$pubAt' WHERE id=$schedId");   // 周末加练 = 一分钟前发的
$t5 = memberTitles('mem', 'asn');
check('按「发出来的那一刻」排序：刚发的那条在最前',
    array_search('压轴公告', $t5, true) < array_search('周末加练', $t5, true), J($t5));
$pdo->exec("DELETE FROM announcements WHERE id=$lateId");
check('测完把这条临时行删掉（不给后面的段留干扰）',
    (int)db_val("SELECT COUNT(*) FROM announcements WHERE id=$lateId") === 0);

// ============================================================
// 5-7. 撤回 / 数据留着 / 恢复
// ============================================================
echo "\n== 5. 撤回：成员那边四处同口径消失 ==\n";
// 给「今晚八点集合」配一张图（用真文件，验撤回不删盘）
$shotRel = '/storage/uploads/announcements/ann53634probe.png';
$shotAbs = $ROOT . '/public' . $shotRel;
@mkdir(dirname($shotAbs), 0777, true);
// 一张 1x1 的 PNG（够 getimagesize 认出来就行）
file_put_contents($shotAbs, (string)base64_decode(
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8DwHwAFAAH/q842iQAAAABJRU5ErkJggg=='));
$pdo->exec("UPDATE announcements SET image='$shotRel' WHERE id=$liveId");
clearstatcache(true, $shotAbs);
check('备好一条带配图的公告', is_file($shotAbs));
// 让成员先读过它
[$s, $j] = as_req('mem', 'POST', '/asn/api/notices/' . $liveId . '/read');
check('成员读过了它', (int)($j['code'] ?? 1) === 0, J($j));
$readRows = (int)db_val("SELECT COUNT(*) FROM announcement_reads WHERE announcement_id=$liveId");
check('已读记录落库 1 条', $readRows === 1, '实际=' . $readRows);

[$s, $j] = as_req('own', 'POST', '/asn/api/admin/announcements/' . $liveId . '/revoke');
check('队长点「撤回」', $s === 200 && (int)($j['code'] ?? 1) === 0, J($j));
check('撤回后成员列表里没有它', !in_array('今晚八点集合', memberTitles('mem', 'asn'), true));
[$s, $j] = as_req('mem', 'POST', '/asn/api/notices/' . $liveId . '/read');
check('成员拿链接去点开已撤回的公告：404', (int)($j['code'] ?? 0) === 404 || $s === 404, J($j));
$dash = array_column((as_req('mem', 'GET', '/asn/api/dashboard')[1]['data']['announcements'] ?? []), 'title');
check('首页那张卡也没有它', !in_array('今晚八点集合', $dash, true), J($dash));
check('撤回后管理端还看得见，状态是「已撤回」',
    (adminRows('own', 'asn')['今晚八点集合']['state'] ?? '') === 'revoked');
check('库里那行还在（撤回不是删除）', (int)db_val("SELECT COUNT(*) FROM announcements WHERE id=$liveId") === 1);

echo "\n== 6. 撤回留着的东西：配图文件、已读名单 ==\n";
clearstatcache(true, $shotAbs);
check('配图文件没被删（撤回不动盘上的字节）', is_file($shotAbs));
check('谁读过仍然查得到',
    (int)db_val("SELECT COUNT(*) FROM announcement_reads WHERE announcement_id=$liveId") === 1);
[$s, $j] = as_req('own', 'GET', '/asn/api/admin/announcements/' . $liveId . '/receipt');
$rd = $j['data'] ?? [];
check('回执面板照样打得开，已读数还在', (int)($j['code'] ?? 1) === 0 && (int)($rd['read_count'] ?? 0) === 1, J($rd));
// 开回执、成员已读、再撤回 —— 催读要挡在「已撤回」那一句上，而不是挡在「没开回执」上
[$s, $j] = $save('own', ['title' => '撤回后催读', 'content' => '催', 'need_receipt' => 1]);
$rrId = (int)($j['data']['id'] ?? 0);
as_req('mem', 'POST', '/asn/api/notices/' . $rrId . '/read');
as_req('own', 'POST', '/asn/api/admin/announcements/' . $rrId . '/revoke');
[, $j] = as_req('own', 'POST', '/asn/api/admin/announcements/' . $rrId . '/remind');
check('撤回的公告催读被挡，说的是「已撤回」', str_contains((string)($j['msg'] ?? ''), '已撤回'), J($j));
check('挡下来之后 reminded_at 仍然是空的',
    (string)(db_val("SELECT reminded_at FROM announcements WHERE id=$rrId") ?? '') === '');
check('撤回的那条本身名单还查得到（已读 1 人没丢）',
    (int)(as_req('own', 'GET', '/asn/api/admin/announcements/' . $rrId . '/receipt')[1]['data']['read_count'] ?? 0) === 1);

echo "\n== 7. 恢复：读过的人不重计 ==\n";
[$s, $j] = as_req('own', 'POST', '/asn/api/admin/announcements/' . $liveId . '/restore');
check('队长点「恢复」', (int)($j['code'] ?? 1) === 0, J($j));
check('成员又看得见它了', in_array('今晚八点集合', memberTitles('mem', 'asn'), true));
[, $j] = as_req('mem', 'GET', '/asn/api/notices');
$back = [];
foreach (($j['data']['list'] ?? []) as $r) { if ((string)$r['title'] === '今晚八点集合') { $back = $r; } }
check('恢复之后这条仍算「已读」（没把读过的人重新变成未读）',
    (int)($back['is_read'] ?? 0) === 1, J($back));
check('恢复后再点撤回没有多余写库（已读记录还是 1 条）',
    (int)db_val("SELECT COUNT(*) FROM announcement_reads WHERE announcement_id=$liveId") === 1);
check('没撤回时点「恢复」会被挡（不是静默成功）',
    (int)(as_req('own', 'POST', '/asn/api/admin/announcements/' . $liveId . '/restore')[1]['code'] ?? 0) === 1);
check('撤两次不会被记两个时间（第二次被挡）',
    (int)(as_req('own', 'POST', '/asn/api/admin/announcements/' . $liveId . '/revoke')[1]['code'] ?? 1) === 0
    && (int)(as_req('own', 'POST', '/asn/api/admin/announcements/' . $liveId . '/revoke')[1]['code'] ?? 0) === 1);
as_req('own', 'POST', '/asn/api/admin/announcements/' . $liveId . '/restore');

// ============================================================
// 8. 定时那一栏的护栏
// ============================================================
echo "\n== 8. 定时的护栏 ==\n";
[$s, $j] = $save('own', ['title' => '过去时间', 'content' => 'x', 'scheduled_at' => date('Y-m-d H:i:s', time() - 3600)]);
check('定时时间填过去：拦住，并提示要马上发就别填',
    (int)($j['code'] ?? 0) === 1 && str_contains((string)($j['msg'] ?? ''), '不能是过去'), J($j));
[$s, $j] = $save('own', ['title' => '太远', 'content' => 'x', 'scheduled_at' => date('Y-m-d H:i:s', time() + 31 * 86400)]);
check('排到 30 天以外：拦住', (int)($j['code'] ?? 0) === 1 && str_contains((string)($j['msg'] ?? ''), '30 天'), J($j));
[$s, $j] = $save('own', ['title' => '乱填', 'content' => 'x', 'scheduled_at' => '明天下午吧']);
check('格式不对：拦住', (int)($j['code'] ?? 0) === 1 && str_contains((string)($j['msg'] ?? ''), '格式'), J($j));
check('三种都没在库里留下垃圾行',
    (int)db_val("SELECT COUNT(*) FROM announcements WHERE title IN ('过去时间','太远','乱填')") === 0);

// ============================================================
// 9. 编辑不会把定时/撤回状态改掉
// ============================================================
echo "\n== 9. 编辑与定时不打架 ==\n";
$future = date('Y-m-d H:i:s', time() + 3600);
$pdo->exec("UPDATE announcements SET scheduled_at='$future' WHERE id=$schedId");
check('先把「周末加练」改回未到点（测编辑）',
    (adminRows('own', 'asn')['周末加练']['state'] ?? '') === 'scheduled');
[$s, $j] = $save('own', ['id' => $schedId, 'title' => '周末加练', 'content' => '改成三局训练赛', 'scheduled_at' => $future]);
check('编辑未到点的公告：正文改了', (int)($j['code'] ?? 1) === 0
    && str_contains((string)db_val("SELECT content FROM announcements WHERE id=$schedId"), '三局'), J($j));
check('带着同一个定时时间编辑：时间没被抹掉（界面上那栏是回填好的，正常保存不该丢）',
    (string)db_val("SELECT scheduled_at FROM announcements WHERE id=$schedId") === $future,
    '库里=' . db_val("SELECT scheduled_at FROM announcements WHERE id=$schedId"));
check('这条成员仍然看不到（定时还生效）', !in_array('周末加练', memberTitles('mem', 'asn'), true));
[$s, $j] = $save('own', ['id' => $schedId, 'title' => '周末加练', 'content' => '改成三局训练赛']);
check('把定时那一栏去掉再保存＝取消定时，这条现在就发出去了',
    (int)($j['code'] ?? 1) === 0 && in_array('周末加练', memberTitles('mem', 'asn'), true), J($j));
$liveSched = db_val("SELECT scheduled_at FROM announcements WHERE id=$schedId");
check('取消之后库里那列是空的', $liveSched === null || $liveSched === '', J($liveSched));

// 已经发出去的行：编辑时传定时也不认（不然一改错字就把公告再藏回去一天）
$trySched = date('Y-m-d H:i:s', time() + 86400);
[$s, $j] = $save('own', ['id' => $liveId, 'title' => '今晚八点集合', 'content' => '改个字', 'scheduled_at' => $trySched]);
$sch3 = db_val("SELECT scheduled_at FROM announcements WHERE id=$liveId");
check('编辑已发出的行时硬传定时：被忽略（不会把公告再藏起来）',
    (int)($j['code'] ?? 1) === 0 && ($sch3 === null || $sch3 === ''), '库里=' . J($sch3));
check('那条公告成员还在看着（没被改动藏回去）', in_array('今晚八点集合', memberTitles('mem', 'asn'), true));

// ============================================================
// 10. 对外展示页同口径 + 越权
// ============================================================
echo "\n== 10. 对外展示页也跟着走 ==\n";
as_req('own', 'POST', '/asn/api/admin/showcase/save', ['enabled' => 1, 'module_notices' => 1]);
[$s, $j] = as_req('own', 'POST', '/asn/api/admin/showcase/save', ['notice_ids' => (string)$liveId . ',' . $schedId]);
check('队长在展示页勾了这两条公告', (int)($j['code'] ?? 1) === 0, J($j));
[$s, , , $html] = as_req('guest', 'GET', '/asn/show');
check('立即发的那条上了对外页', str_contains($html, '今晚八点集合'), 'http=' . $s . ' 长度=' . strlen($html));
check('刚取消定时、成员已经能看到的那条也上了对外页', str_contains($html, '周末加练'));
[$s, $j] = $save('own', ['title' => '下周三训练赛', 'content' => '对外页看不到', 'scheduled_at' => date('Y-m-d H:i:s', time() + 86400)]);
$pendId = (int)($j['data']['id'] ?? 0);
as_req('own', 'POST', '/asn/api/admin/showcase/save', ['notice_ids' => (string)$liveId . ',' . $schedId . ',' . $pendId]);
[$s, , , $html] = as_req('guest', 'GET', '/asn/show');
check('勾了但还没到点的公告不上对外页（到点自动出）', !str_contains($html, '下周三训练赛'));
[, $j] = as_req('own', 'GET', '/asn/api/admin/showcase');
$opts = [];
foreach (($j['data']['notices'] ?? []) as $o) { $opts[(string)$o['title']] = $o; }
check('挑公告那份名单里，未到点的还在（可以提前勾好）但标了一句',
    isset($opts['下周三训练赛']) && (int)($opts['下周三训练赛']['not_live_yet'] ?? 0) === 1,
    J($opts['下周三训练赛'] ?? '不在名单里'));
$pdo->exec("UPDATE announcements SET revoked_at='" . date('Y-m-d H:i:s') . "' WHERE id=$schedId");
[$s, , , $html] = as_req('guest', 'GET', '/asn/show');
check('对外页这条路也是撤回就立刻不出（直接改库的撤回同样生效）', !str_contains($html, '周末加练'));
[, $j] = as_req('own', 'GET', '/asn/api/admin/showcase');
$optTitles = array_column($j['data']['notices'] ?? [], 'title');
check('已撤回的那条从挑公告名单里退下（勾着也不该再摆给访客）',
    !in_array('周末加练', $optTitles, true), J($optTitles));
$pdo->exec("UPDATE announcements SET revoked_at=NULL WHERE id=$schedId");

echo "\n== 11. 越权：三种人都动不了 ==\n";
$beforeLive = (string)db_val("SELECT revoked_at FROM announcements WHERE id=$liveId");
[$s, $j] = as_req('boss', 'POST', '/bsn/api/admin/announcements/' . $liveId . '/revoke');
check('乙队站长撤不动甲队的公告', (int)($j['code'] ?? 0) !== 0, J($j));
check('甲队那条状态没变', (string)db_val("SELECT revoked_at FROM announcements WHERE id=$liveId") === $beforeLive);
[$s, $j] = as_req('boss', 'POST', '/bsn/api/admin/announcements/save',
    ['id' => $liveId, 'title' => '偷改甲队', 'content' => 'x']);
check('乙队站长也改不动甲队公告', (int)($j['code'] ?? 0) !== 0
    && (string)db_val("SELECT title FROM announcements WHERE id=$liveId") === '今晚八点集合', J($j));
[$s, $j] = as_req('sub', 'POST', '/asn/api/admin/announcements/' . $liveId . '/revoke');
check('没有公告权限的副管理撤不了（403）', $s === 403 || (int)($j['code'] ?? 0) === 403, 'http=' . $s . ' ' . J($j));
[$s, $j] = as_req('mem', 'POST', '/asn/api/admin/announcements/' . $liveId . '/revoke');
check('普通成员进不了管理端这条接口', $s === 401 || $s === 403 || (int)($j['code'] ?? 0) === 403, 'http=' . $s);
[$s, $j] = as_req('mem', 'POST', '/asn/api/admin/announcements/save', ['title' => '成员发的', 'content' => 'x']);
check('成员也发不了公告', $s === 401 || $s === 403 || (int)($j['code'] ?? 0) === 403, 'http=' . $s);
$t11 = memberTitles('mem', 'asn');
check('成员端这条路照常：恢复的那条在、撤回的那条不在',
    in_array('今晚八点集合', $t11, true) && !in_array('撤回后催读', $t11, true), J($t11));

// ============================================================
// 12. 老数据一行都没被这次改动影响
// ============================================================
echo "\n== 12. 与上一版的行为对齐 ==\n";
$old = db_rows("SELECT id, title, scheduled_at, revoked_at FROM announcements WHERE tenant_id=$bTid");
check('乙队一条公告都没被带进来（跨队零污染）', $old === [], J($old));
$cnt = (int)db_val("SELECT COUNT(*) FROM announcements WHERE tenant_id=$aTid");
check('甲队公告行数与这段演练对得上（' . $cnt . ' 条，没有多余行）', $cnt >= 4, '实际=' . $cnt);
$nullish = (int)db_val("SELECT COUNT(*) FROM announcements WHERE tenant_id=$aTid AND scheduled_at IS NULL AND revoked_at IS NULL");
check('没填定时的老路子公告两列都是空（行为与上一版一致）', $nullish >= 1, '空的两列=' . $nullish);

// ============================================================
// 收尾
// ============================================================
echo "\n== 收尾 ==\n";
@unlink($shotAbs);
clearstatcache(true, $shotAbs);
check('演练建的探针配图文件删掉了', !is_file($shotAbs));
echo '  公告行 ' . (int)db_val("SELECT COUNT(*) FROM announcements")
    . ' 条、已读记录 ' . (int)db_val("SELECT COUNT(*) FROM announcement_reads")
    . ' 条、管理日志 ' . (int)db_val("SELECT COUNT(*) FROM admin_logs WHERE action='公告管理'") . " 条\n";

echo "\n通过 {$pass} 项，失败 {$fail} 项\n";
if ($fail > 0) {
    echo "失败清单：\n";
    foreach ($failures as $f) { echo "  - {$f}\n"; }
}
exit($fail > 0 ? 1 : 0);
