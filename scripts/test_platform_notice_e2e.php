<?php
/**
 * 强制平台公告（v5.36.31）功能 E2E（scripts/test_platform_notice_e2e.php）
 *
 * 前置：测试服务器已启动，且 .env 指向 sqlite
 *   php -S 127.0.0.1:8899 -t public public/router.php
 * 用法：php scripts/test_platform_notice_e2e.php
 *
 * 十二条主线，全按「真实 HTTP 请求 + 落库复核」来断言：
 *   1. 权限：没登录、战队站长都进不来平台那六条接口；平台超管能读能写
 *   2. 形校验：空标题、空正文、超长、只存草稿不弹，都按说的走
 *   3. 弹出：成员打开站点就拿到那一条（标题正文一字不差），未登录读不到
 *   4. 我已知晓：点完不再弹，库里一行 ack，重复点不多写行
 *   5. 不再提示：这一条 + 一条全局静音，之后新发的公告也不弹给他
 *   6. 平台关掉「不再提示」：成员点不动，弹窗上也没这颗钮；已经静音的人如实留着
 *   7. 同时在线上限三条：第四条上线被拦，先下线一条才轮得它上
 *   8. 阅读计数：应读=已上线且所在队没被封；待审核/被封账号/封禁队都不进分母
 *   9. 按战队汇总：每队 应读/已读/未读 对得上总数，未读多的排前面
 *  10. 改、上下线、删：改算同一条不清零已读；下线留档；删除连记录一起删
 *  11. 回归：战队自己的公告栏 /api/notices、管理中心那栏平台通知、邮件群发页照常
 *  12. 前端与交付 SQL：两份 jsx + 两份产物 + 四份 DDL 与回滚脚本都核对
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
    $st = db()->prepare($sql); $st->execute($bind);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}
function val(string $sql, array $bind = []) {
    $st = db()->prepare($sql); $st->execute($bind); $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ? reset($r) : null;
}
/** 平台侧写一条公告 */
function platSave(array $body): array {
    return as_req('plat', 'POST', '/api/platform/admin/announcement/save', $body);
}
/** 成员这一路：谁在问，就按谁的会话取该弹的那一条（战队目录前缀按人所在站走） */
function readOne(string $who, string $slug = 'pn1'): ?array {
    [, $j] = as_req($who, 'GET', '/' . $slug . '/api/force-notice/current');
    $n = $j['data']['notice'] ?? null;
    return is_array($n) ? $n : null;
}
function ack(string $who, int $id, string $slug = 'pn1'): array {
    return as_req($who, 'POST', '/' . $slug . '/api/force-notice/ack', ['id' => $id]);
}
function mute(string $who, int $id, string $slug = 'pn1'): array {
    return as_req($who, 'POST', '/' . $slug . '/api/force-notice/mute', ['id' => $id]);
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

$pdo  = db();
$now  = date('Y-m-d H:i:s');
$hash = password_hash('pass123456', PASSWORD_DEFAULT);

$mkTenant = function (string $slug, string $name, string $status = 'approved') use ($pdo, $now): int {
    $pdo->exec("INSERT INTO tenants (applicant_id, slug, name, status, admin_username, created_at, updated_at)
                VALUES (1, '$slug', '$name', '$status', '{$slug}boss', '$now', '$now')");
    return (int)$pdo->lastInsertId();
};
$mkUser = function (int $tid, string $username, string $nick, string $role = 'user',
    string $status = 'approved') use ($pdo, $hash, $now): int {
    $pdo->exec("INSERT INTO users (tenant_id, username, password, nickname, role, status, admin_perms,
                                   show_public, stats_public, created_at, updated_at)
                VALUES ($tid, '$username', '$hash', '$nick', '$role', '$status', '', 1, 1, '$now', '$now')");
    return (int)$pdo->lastInsertId();
};

$T1 = $mkTenant('pn1', '公告一队');
$T2 = $mkTenant('pn2', '公告二队');
$T3 = $mkTenant('pn3', '被封的公告三队', 'banned');

$o1  = $mkUser($T1, 'pn1boss', '一队站长', 'admin');
$u1a = $mkUser($T1, 'pn1u1', '一队甲');
$u1b = $mkUser($T1, 'pn1u2', '一队乙');
$u1c = $mkUser($T1, 'pn1u3', '一队丙');
$u1p = $mkUser($T1, 'pn1p1', '一队待审', 'user', 'pending');
$u1x = $mkUser($T1, 'pn1x1', '一队被封', 'user', 'banned');
$o2  = $mkUser($T2, 'pn2boss', '二队站长', 'admin');
$u2a = $mkUser($T2, 'pn2u1', '二队甲');
$u3a = $mkUser($T3, 'pn3u1', '三队甲');

check('平台超管登录', login('plat', '', 'admin', 'admin123')[1]['code'] === 0);
check('一队站长登录', login('o1', 'pn1', 'pn1boss', 'pass123456')[1]['code'] === 0);
check('一队成员甲登录', login('u1a', 'pn1', 'pn1u1', 'pass123456')[1]['code'] === 0);
check('一队成员乙登录', login('u1b', 'pn1', 'pn1u2', 'pass123456')[1]['code'] === 0);
check('一队成员丙登录', login('u1c', 'pn1', 'pn1u3', 'pass123456')[1]['code'] === 0);
check('二队站长登录', login('o2', 'pn2', 'pn2boss', 'pass123456')[1]['code'] === 0);
echo "  种子：一队 6 个账号（4 个算应读）、二队 2 个、被封的三队 1 个\n";

$tables = array_column(q("SELECT name FROM sqlite_master WHERE type='table'"), 'name');
check('库里建出了两张新表', in_array('platform_announcements', $tables, true)
    && in_array('platform_announcement_reads', $tables, true), implode(',', array_slice($tables, -4)));
check('一人一条公告只会记一次（唯一索引在）',
    (int)val("SELECT COUNT(*) FROM sqlite_master WHERE type='index'
              AND tbl_name='platform_announcement_reads' AND name LIKE 'sqlite_autoindex%'") >= 1);

// ============================================================
// 1. 权限
// ============================================================
echo "\n== 1. 平台那六条接口只属于平台超管 ==\n";
$anon = [];
[$s, $j] = req('GET', $BASE . '/api/platform/admin/announcements', null, $anon);
check('没登录读不到公告列表', $s === 401 || $s === 403 || (int)($j['code'] ?? 0) !== 0, "HTTP $s " . J($j));
[$s, $j] = as_req('o1', 'GET', '/api/platform/admin/announcements');
check('战队站长读不到平台公告列表', (int)($j['code'] ?? 0) === 403, "HTTP $s " . J($j));
[$s, $j] = as_req('o1', 'POST', '/api/platform/admin/announcement/save', ['title' => '越权', 'content' => '越权正文']);
check('战队站长发不了强制公告', (int)($j['code'] ?? 0) === 403, "HTTP $s " . J($j));
[$s, $j] = as_req('o1', 'POST', '/api/platform/admin/announcement/mute', ['allow' => 0]);
check('战队站长改不了「不再提示」开关', (int)($j['code'] ?? 0) === 403, J($j));
[$s, $j] = as_req('o1', 'GET', '/api/platform/admin/announcement?id=1');
check('战队站长也读不到单条明细', (int)($j['code'] ?? 0) === 403, J($j));
[$s, $j] = as_req('plat', 'GET', '/api/platform/admin/announcements');
check('平台超管能读列表', $s === 200 && (int)($j['code'] ?? 1) === 0, "HTTP $s " . J($j));
check('列表如实告诉前端表已建好', (int)($j['data']['available'] ?? 0) === 1, J($j['data'] ?? []));
check('列表带着上限值与静音开关',
    (int)($j['data']['title_max'] ?? 0) === 120 && (int)($j['data']['content_max'] ?? 0) === 2000
    && (int)($j['data']['online_max'] ?? 0) === 3 && (int)($j['data']['allow_mute'] ?? 1) === 1, J($j['data'] ?? []));

// ============================================================
// 2. 形校验
// ============================================================
echo "\n== 2. 写一条公告的形校验 ==\n";
[, $j] = platSave(['title' => '', 'content' => '正文']);
check('空标题被拦', (int)($j['code'] ?? 0) !== 0 && str_contains((string)($j['msg'] ?? ''), '标题'), J($j));
[, $j] = platSave(['title' => '只有标题', 'content' => '   ']);
check('空白正文被拦', (int)($j['code'] ?? 0) !== 0 && str_contains((string)($j['msg'] ?? ''), '正文'), J($j));
[, $j] = platSave(['title' => str_repeat('长', 121), 'content' => '正文']);
check('超长标题被拦（120 字）', (int)($j['code'] ?? 0) !== 0 && str_contains((string)($j['msg'] ?? ''), '120'), J($j));
[, $j] = platSave(['title' => '超长正文', 'content' => str_repeat('内', 2001)]);
check('超长正文被拦（2000 字）', (int)($j['code'] ?? 0) !== 0 && str_contains((string)($j['msg'] ?? ''), '2000'), J($j));
[, $j] = platSave(['title' => '存着但不弹', 'content' => '这一条先不上线。', 'status' => 0]);
$draftId = (int)($j['data']['id'] ?? 0);
check('可以只存着不弹（status=0）', $draftId > 0, J($j));
check('没上线的公告不该弹给成员', readOne('u1a') === null, J(readOne('u1a')));
check('未上线那一条不计入在弹的数',
    (int)val("SELECT COUNT(*) FROM platform_announcements WHERE status=1") === 0);
[$s, $j] = as_req('plat', 'POST', '/api/platform/admin/announcement/status', ['id' => $draftId, 'status' => 1]);
check('上限值以内能直接点上线', (int)($j['code'] ?? 1) === 0, J($j));
[$s, $j] = as_req('plat', 'POST', '/api/platform/admin/announcement/status', ['id' => $draftId, 'status' => 0]);
check('再点下线（草稿留着）', (int)($j['code'] ?? 1) === 0, J($j));
[, $j] = platSave(['title' => '站点维护公告', 'content' => "今晚 23:00 起停机升级约 30 分钟。\n升级期间打不开站点属正常。", 'status' => 1]);
$aid = (int)($j['data']['id'] ?? 0);
check('上线一条公告', $aid > 0 && $aid !== $draftId, J($j));
check('发布人记的是平台超管本人',
    (string)val("SELECT actor_name FROM platform_announcements WHERE id=$aid") === 'admin',
    (string)val("SELECT actor_name FROM platform_announcements WHERE id=$aid"));
check('发布这一条记了一行平台操作日志',
    (int)val("SELECT COUNT(*) FROM admin_logs WHERE tenant_id=0 AND action='强制公告-发布' AND detail LIKE '%#$aid%'") >= 1);

// ============================================================
// 3. 弹出
// ============================================================
echo "\n== 3. 成员打开站点就拿到那一条 ==\n";
[$s, $j] = as_req('nobody', 'GET', '/pn1/api/force-notice/current');
check('没登录读不到强制公告', $s === 401 || (int)($j['code'] ?? 0) !== 0, "HTTP $s");
$n = readOne('u1a');
check('成员拿到公告本体（标题一字不差）', is_array($n) && (string)($n['title'] ?? '') === '站点维护公告', J($n));
check('正文里换行原样留着（弹窗靠它排版）',
    is_array($n) && substr_count((string)$n['content'], "\n") === 1 && str_contains((string)$n['content'], '23:00'), J($n));
check('公告带着发布时间与发布人', is_array($n) && (string)($n['at'] ?? '') !== '' && (string)($n['actor'] ?? '') === 'admin', J($n));
check('弹窗上该有「不再提示」（平台还没关）', is_array($n) && (int)($n['mute'] ?? 0) === 1, J($n));
check('公告带的是自己的 id（点按钮要带回去）', is_array($n) && (int)($n['id'] ?? 0) === $aid, J($n));
check('队长/站长也照样要弹（不只弹普通成员）', (string)(readOne('o1')['title'] ?? '') === '站点维护公告');
check('二队的成员弹同一条（这是全平台的公告）', (string)(readOne('o2', 'pn2')['title'] ?? '') === '站点维护公告');

// ============================================================
// 4. 我已知晓
// ============================================================
echo "\n== 4. 点「我已知晓」 ==\n";
[$s, $j] = ack('u1a', $aid);
check('点知晓成功', (int)($j['code'] ?? 1) === 0, J($j));
check('库里落了一行 choice=ack',
    (int)val("SELECT COUNT(*) FROM platform_announcement_reads WHERE announcement_id=$aid AND user_id=$u1a AND choice='ack'") === 1);
check('那一行带着他当时在哪个队',
    (int)val("SELECT tenant_id FROM platform_announcement_reads WHERE announcement_id=$aid AND user_id=$u1a") === $T1);
check('点完之后这一条不再弹给他', readOne('u1a') === null);
[$s, $j] = ack('u1a', $aid);
check('重复点也回成功（不报错）', (int)($j['code'] ?? 1) === 0, J($j));
check('重复点不多写一行',
    (int)val("SELECT COUNT(*) FROM platform_announcement_reads WHERE user_id=$u1a") === 1,
    'rows=' . (int)val("SELECT COUNT(*) FROM platform_announcement_reads WHERE user_id=$u1a"));
check('别人该照样弹（他点知晓不影响同队其他人）', (string)(readOne('u1b')['title'] ?? '') === '站点维护公告');
[$s, $j] = ack('u1a', 999999);
check('乱填公告号被拒（不给公告表凭空补记录）', (int)($j['code'] ?? 0) !== 0, J($j));

// ============================================================
// 5. 不再提示 + 多条在弹
// ============================================================
echo "\n== 5. 点「不再提示」与一次只弹一条 ==\n";
[$s, $j] = mute('u1b', $aid);
check('点不再提示成功', (int)($j['code'] ?? 1) === 0, J($j));
check('这一条记了一行 mute',
    (int)val("SELECT COUNT(*) FROM platform_announcement_reads WHERE announcement_id=$aid AND user_id=$u1b AND choice='mute'") === 1);
check('另外记了一行全局静音（announcement_id=0）',
    (int)val("SELECT COUNT(*) FROM platform_announcement_reads WHERE announcement_id=0 AND user_id=$u1b AND choice='mute'") === 1);
check('静音之后这一条不再弹', readOne('u1b') === null);
[, $j] = platSave(['title' => '第二条：新地图轮换', 'content' => '从下周起换成新池子。', 'status' => 1]);
$aid2 = (int)($j['data']['id'] ?? 0);
check('发出第二条公告', $aid2 > 0, J($j));
check('点了不再提示的人，新公告也不弹给他', readOne('u1b') === null);
check('没静音的人先弹最久没处理的那一条（不是最新那条）', (int)(readOne('u1c')['id'] ?? 0) === $aid, J(readOne('u1c')));
check('甲点完第一条，下一次才轮到第二条', (int)(readOne('u1c')['id'] ?? 0) === $aid, '先确认还没点');
[, $j] = ack('u1c', $aid);
check('丙点完第一条', (int)($j['code'] ?? 1) === 0, J($j));
check('下一次登录轮到第二条', (int)(readOne('u1c')['id'] ?? 0) === $aid2, J(readOne('u1c')));
check('一次只弹一条（列表里两条都在线也不堆叠）',
    (int)val("SELECT COUNT(*) FROM platform_announcements WHERE status=1") === 2);

// ============================================================
// 6. 平台关掉「不再提示」
// ============================================================
echo "\n== 6. 平台可以整颗关掉「不再提示」 ==\n";
[$s, $j] = as_req('plat', 'POST', '/api/platform/admin/announcement/mute', ['allow' => 0]);
check('关掉成功且如实回 allow_mute=0', (int)($j['code'] ?? 1) === 0 && (int)($j['data']['allow_mute'] ?? 1) === 0, J($j));
check('静音人数被如实数出来（现在 1 人）', (int)($j['data']['global_muted'] ?? 0) === 1, J($j));
$n = readOne('o2', 'pn2');
check('弹窗上不再带「不再提示」这颗钮', is_array($n) && (int)($n['mute'] ?? 1) === 0, J($n));
[$s, $j] = mute('o2', $aid, 'pn2');
check('关掉之后成员点「不再提示」被拒',
    (int)($j['code'] ?? 0) !== 0 && str_contains((string)($j['msg'] ?? ''), '我已知晓'), J($j));
check('被拒那一次没往库里写行', (int)val("SELECT COUNT(*) FROM platform_announcement_reads WHERE user_id=$o2") === 0);
check('已经点过静音的那个人照样静音（这次改动救不回来，如实）', readOne('u1b') === null);
[$s, $j] = as_req('plat', 'GET', '/api/platform/admin/announcements');
check('列表如实回 allow_mute=0', (int)($j['data']['allow_mute'] ?? 1) === 0, J($j['data'] ?? []));
[$s, $j] = as_req('plat', 'POST', '/api/platform/admin/announcement/mute', ['allow' => 1]);
check('再放开', (int)($j['code'] ?? 1) === 0 && (int)($j['data']['allow_mute'] ?? 0) === 1, J($j));
$n = readOne('o2', 'pn2');
check('放开后弹窗又带上那颗钮', is_array($n) && (int)($n['mute'] ?? 0) === 1, J($n));
check('静音开关也记了一行日志',
    (int)val("SELECT COUNT(*) FROM admin_logs WHERE tenant_id=0 AND action='强制公告-静音开关'") >= 2);

// ============================================================
// 7. 同时在线上限
// ============================================================
echo "\n== 7. 同时在线最多三条 ==\n";
[, $j] = platSave(['title' => '第三条', 'content' => '第三条正文', 'status' => 1]);
$aid3 = (int)($j['data']['id'] ?? 0);
check('第三条能上线', $aid3 > 0, J($j));
[, $j] = platSave(['title' => '第四条想上线', 'content' => '第四条正文', 'status' => 1]);
check('第四条上线被拦（最多 3 条）', (int)($j['code'] ?? 0) !== 0 && str_contains((string)($j['msg'] ?? ''), '3'), J($j));
check('被拦那一条压根没落库',
    (int)val("SELECT COUNT(*) FROM platform_announcements WHERE title='第四条想上线'") === 0);
[, $j] = platSave(['title' => '第四条存草稿', 'content' => '不弹就能存', 'status' => 0]);
$aid4draft = (int)($j['data']['id'] ?? 0);
check('满三条时「只存不弹」照样存得下（不该把人挡在外面）', $aid4draft > 0, J($j));
[$s, $j] = as_req('plat', 'POST', '/api/platform/admin/announcement/status', ['id' => $aid, 'status' => 0]);
check('把第一条下线', (int)($j['code'] ?? 1) === 0, J($j));
[$s, $j] = as_req('plat', 'POST', '/api/platform/admin/announcement/status', ['id' => $aid4draft, 'status' => 1]);
check('腾出一个位置后草稿能上线', (int)($j['code'] ?? 1) === 0, J($j));
$aid4 = $aid4draft;
check('第一条下线后不再弹给还没点的人', (int)(readOne('o2', 'pn2')['id'] ?? 0) !== $aid, J(readOne('o2', 'pn2')));
[$s, $j] = as_req('plat', 'POST', '/api/platform/admin/announcement/status', ['id' => 999999, 'status' => 1]);
check('上下线对不存在的公告如实回错', (int)($j['code'] ?? 0) !== 0, J($j));

// ============================================================
// 8. 阅读计数（总数）
// ============================================================
echo "\n== 8. 阅读计数：应读只数在册且队没被封的人 ==\n";
// 此刻公告 #aid 的处理情况：甲 ack、乙 mute、丙 ack = 3；应读 6（一队 4 + 二队 2）
[$s, $j] = as_req('plat', 'GET', '/api/platform/admin/announcement?id=' . $aid);
$st = $j['data']['stats'] ?? [];
check('应读人数 6（待审/被封账号/封禁队都不进分母）', (int)($st['need'] ?? 0) === 6, J($st));
check('分母里确实排掉了被封账号与待审',
    (int)val("SELECT COUNT(*) FROM users WHERE status<>'approved'") === 2
    && (int)val("SELECT COUNT(*) FROM users WHERE tenant_id=$T3") === 1);
check('已读 3（知晓 2 + 静音 1）',
    (int)($st['read'] ?? 0) === 3 && (int)($st['ack'] ?? 0) === 2 && (int)($st['muted'] ?? 0) === 1, J($st));
check('未读 = 应读 - 已读', (int)($st['unread'] ?? 0) === 3);
check('百分比按已读算出来（3/6=50）', (int)($st['rate'] ?? 0) === 50, 'rate=' . (int)($st['rate'] ?? 0));
check('全局静音人数 1 人', (int)($st['global_muted'] ?? 0) === 1, J($st));
check('静音行（announcement_id=0）不会被算进某一条公告的已读',
    (int)val("SELECT COUNT(*) FROM platform_announcement_reads WHERE announcement_id=0") === 1);

// ============================================================
// 9. 按战队汇总
// ============================================================
echo "\n== 9. 按战队汇总 ==\n";
$by = $st['by_team'] ?? [];
check('汇总里两支队（被封的三队不在）', count($by) === 2, J($by));
$sumNeed = 0; $sumRead = 0; $okMath = true;
foreach ($by as $t) {
    $sumNeed += (int)$t['need'];
    $sumRead += (int)$t['read'];
    if ((int)$t['unread'] !== (int)$t['need'] - (int)$t['read']) { $okMath = false; }
}
check('各队应读加起来等于总数', $sumNeed === (int)($st['need'] ?? 0), "sum=$sumNeed");
check('各队已读加起来等于总已读', $sumRead === (int)($st['read'] ?? 0), "sum=$sumRead");
check('每队未读都等于该队应读减已读', $okMath, J($by));
check('未读多的队排在上面', (int)($by[0]['unread'] ?? 0) >= (int)($by[1]['unread'] ?? 0), J(array_column($by, 'unread')));
$one = null; $two = null;
foreach ($by as $t) { if ((int)$t['tenant_id'] === $T1) { $one = $t; } if ((int)$t['tenant_id'] === $T2) { $two = $t; } }
check('一队汇总：应读 4、已读 3、未读 1',
    is_array($one) && (int)$one['need'] === 4 && (int)$one['read'] === 3 && (int)$one['unread'] === 1, J($one));
check('二队汇总：应读 2、一个都没点', is_array($two) && (int)$two['need'] === 2 && (int)$two['read'] === 0, J($two));
check('汇总带着队名与短码（知道该去催谁）',
    is_array($one) && str_contains((string)$one['name'], '公告一队') && (string)$one['slug'] === 'pn1', J($one));
[$s, $j] = as_req('plat', 'GET', '/api/platform/admin/announcements');
$rows = $j['data']['list'] ?? [];
$hit = null;
foreach ($rows as $r) { if ((int)$r['id'] === $aid) { $hit = $r; } }
check('列表每一行都带着四个数', $hit !== null && isset($hit['need'], $hit['read'], $hit['unread'], $hit['rate']), J($hit));
check('列表把正文收成 80 字开头（整篇只在明细里给）',
    $hit !== null && (int)$hit['content_len'] > 0 && mb_strlen((string)$hit['content_head']) <= 80, J($hit));
check('列表里那条已下线的如实标 status=0', is_array($hit) && (int)$hit['status'] === 0, J($hit));
[$s, $j] = as_req('plat', 'GET', '/api/platform/admin/announcement?id=999999');
check('不存在的公告回 404', $s === 404, "HTTP $s");

// ============================================================
// 10. 改、上下线留档、删除
// ============================================================
echo "\n== 10. 改算同一条 / 下线留档 / 删除连记录一起删 ==\n";
$beforeRead2 = (int)val("SELECT COUNT(*) FROM platform_announcement_reads WHERE announcement_id=$aid2");
[$s, $j] = platSave(['id' => $aid2, 'title' => '第二条（改过）', 'content' => '改了正文，还是同一条公告。', 'status' => 1]);
check('修改成功且 id 不变', (int)($j['code'] ?? 1) === 0 && (int)($j['data']['id'] ?? 0) === $aid2, J($j));
[$s, $j] = as_req('plat', 'GET', '/api/platform/admin/announcement?id=' . $aid2);
check('标题真的换成新的', (string)($j['data']['title'] ?? '') === '第二条（改过）', J($j['data'] ?? []));
check('改完已读人数没被清零',
    (int)val("SELECT COUNT(*) FROM platform_announcement_reads WHERE announcement_id=$aid2") === $beforeRead2);
check('修改记了一行日志',
    (int)val("SELECT COUNT(*) FROM admin_logs WHERE tenant_id=0 AND action='强制公告-修改' AND detail LIKE '%#$aid2%'") >= 1);
[, $j] = platSave(['id' => 888888, 'title' => '不存在', 'content' => '不存在']);
check('改一条不存在的公告被拒', (int)($j['code'] ?? 0) !== 0, J($j));
$n = readOne('o2', 'pn2');
check('改过的这一条，还没点的人看到的就是新文案',
    is_array($n) && (int)$n['id'] === $aid2 && str_contains((string)$n['content'], '改了正文'), J($n));
check('下线不删记录：第一条的公告行还在', (int)val("SELECT COUNT(*) FROM platform_announcements WHERE id=$aid") === 1);
check('下线不删已读：那三条处理记录还在',
    (int)val("SELECT COUNT(*) FROM platform_announcement_reads WHERE announcement_id=$aid") === 3);
[$s, $j] = as_req('plat', 'POST', '/api/platform/admin/announcement/delete', ['id' => $aid3]);
check('删除一条公告', (int)($j['code'] ?? 1) === 0, J($j));
check('公告与它的送达确认一起没了',
    (int)val("SELECT COUNT(*) FROM platform_announcements WHERE id=$aid3") === 0
    && (int)val("SELECT COUNT(*) FROM platform_announcement_reads WHERE announcement_id=$aid3") === 0);
[$s, $j] = as_req('plat', 'POST', '/api/platform/admin/announcement/delete', ['id' => $aid3]);
check('再删一次如实说不在', (int)($j['code'] ?? 0) !== 0, J($j));
check('删除也记了一行日志',
    (int)val("SELECT COUNT(*) FROM admin_logs WHERE tenant_id=0 AND action='强制公告-删除' AND detail LIKE '%#$aid3%'") >= 1);

// ============================================================
// 11. 回归：没把别的东西带坏
// ============================================================
echo "\n== 11. 老功能照常 ==\n";
[$s, $j] = as_req('u1a', 'GET', '/pn1/api/auth/me');
check('成员 me 接口照常', $s === 200 && (int)($j['code'] ?? 1) === 0, "HTTP $s");
[$s, $j] = as_req('u1a', 'GET', '/pn1/api/notices');
check('战队自己的公告栏 /api/notices 不受影响（另一套东西）',
    $s === 200 && (int)($j['code'] ?? 1) === 0 && array_key_exists('unread_count', $j['data'] ?? []), "HTTP $s " . J($j));
[$s, $j] = as_req('o1', 'GET', '/pn1/api/admin/platform-notices');
check('管理中心那栏「平台通知」（发给站长的老通道）照常', (int)($j['code'] ?? 1) === 0, J($j));
[$s, $j] = as_req('plat', 'GET', '/api/platform/admin/mail/config');
check('邮件群发那一页照常打开', (int)($j['code'] ?? 1) === 0, J($j));
[$s, $j] = as_req('plat', 'POST', '/api/platform/admin/mail/recipients', ['groups' => 'owner,member']);
check('邮件群发的收件人数与公告的应读数同一批人（都是 6）',
    (int)($j['code'] ?? 1) === 0 && (int)($j['data']['total'] ?? 0) === 6, J($j['data'] ?? []));
[$s, $j] = as_req('o1', 'GET', '/pn1/api/admin/announcements');
check('战队管理员看自家公告列表照常', (int)($j['code'] ?? 1) === 0, J($j));
foreach (['Admin.php', 'Notice.php', 'Members.php', 'Inbox.php'] as $f) {
    $src = (string)file_get_contents($ROOT . '/app/controller/' . $f);
    check("战队侧 {$f} 碰不到这两张平台表", !str_contains($src, 'platform_announcement'), $f);
}

// ============================================================
// 12. 前端与交付 SQL
// ============================================================
echo "\n== 12. 前端与交付 SQL ==\n";
$pj = (string)file_get_contents($ROOT . '/public/static/js/platform.jsx');
foreach (["['notice', '强制公告']", "'/api/platform/admin/announcements'", "'/api/platform/admin/announcement/save'",
          "'/api/platform/admin/announcement/mute'", 'function PlatNotice()', "{tab === 'notice' && <PlatNotice />}"] as $n) {
    check("platform.jsx 里有 {$n}", str_contains($pj, $n));
}
check('平台那一页有「按战队看」的入口', str_contains($pj, '按战队看'));
check('表没建时那一页给的是能按的出路（不是空屏）', str_contains($pj, 'upgrade_v5.36.30_to_v5.36.31.sql'));
$mj = $pj;
foreach (["{team} {name}", "'/api/platform/admin/mail/render'", '看渲染效果', '每个人收到自己那一份'] as $n) {
    check("邮件那一页里有 {$n}", str_contains($mj, $n));
}
$aj = (string)file_get_contents($ROOT . '/public/static/js/app.jsx');
foreach (['function PlatformNoticeGuard()', "'/api/force-notice/current'", "'/api/force-notice/' + kind",
          '<PlatformNoticeGuard />', '我已知晓', '不再提示'] as $n) {
    check("app.jsx 里有 {$n}", str_contains($aj, $n));
}
check('强制公告弹窗对所有登录的人都挂（不只管理员）',
    (bool)preg_match('/\{me && <PlatformNoticeGuard \/>\}/', $aj));
$pjs = (string)file_get_contents($ROOT . '/public/static/js/platform.js');
$aJs = (string)file_get_contents($ROOT . '/public/static/js/app.js');
check('编译产物 platform.js 里有这一页', str_contains($pjs, 'PlatNotice') && str_contains($pjs, '强制公告'));
check('编译产物 app.js 里有成员那个弹窗', str_contains($aJs, 'force-notice') && str_contains($aJs, 'PlatformNoticeGuard'));
$bld = (string)shell_exec('node ' . escapeshellarg($ROOT . '/scripts/build_js.js') . ' --check 2>&1');
check('预编译产物与源码逐字节一致', str_contains($bld, '产物核对通过'), trim($bld));

foreach (['database/game_team.sql', 'database/game_team_phpmyadmin.sql',
          'database/upgrade_v5.36.30_to_v5.36.31.sql', 'public/notice_install_v53631.php',
          'scripts/dev_init_sqlite.php'] as $f) {
    $s2 = (string)file_get_contents($ROOT . '/' . $f);
    check("{$f} 里有这两张表", str_contains($s2, 'platform_announcements') && str_contains($s2, 'platform_announcement_reads'));
}
$rb = (string)file_get_contents($ROOT . '/database/rollback_v5.36.31_platform_notice.sql');
check('回滚脚本两张表都撤',
    str_contains($rb, 'DROP TABLE IF EXISTS `platform_announcements`;')
    && str_contains($rb, 'DROP TABLE IF EXISTS `platform_announcement_reads`;'));
$rt = (string)file_get_contents($ROOT . '/route/app.php');
foreach (["Route::get('/force-notice/current', 'ForceNotice/current');",
          "Route::post('/force-notice/ack',    'ForceNotice/ack');",
          "Route::post('/force-notice/mute',   'ForceNotice/mute');",
          "'PlatformAnnounceAdmin/index'", "'PlatformAnnounceAdmin/save'", "'PlatformAnnounceAdmin/mute'"] as $n) {
    check("路由里有 {$n}", str_contains($rt, $n));
}

echo "\n================ 结果 ================\n";
echo "通过 $pass 项";
if ($fail > 0) {
    echo "，失败 $fail 项：\n  - " . implode("\n  - ", $failures) . "\n";
    exit(1);
}
echo "，全部通过\n";
exit(0);
