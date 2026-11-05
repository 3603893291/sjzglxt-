<?php
/**
 * v5.19.0 聊天室拆成「战队群 + 私聊」E2E
 *
 * 前置：测试服务器已启动，.env 指向 SQLite（.env 里只能用 ; 注释）
 *   php -S 127.0.0.1:8899 -t public public/router.php
 * 用法：php scripts/test_chat_dm_e2e.php
 *
 * 覆盖：
 *   1. 战队群（原聊天室）零回归，且群接口物理读不到私聊正文
 *   2. 私聊双向可见、会话列表、未读角标随打开归零
 *   3. 越权：跨队发不到、别人之间的对话读不到、未登录进不来
 *   4. 双层开关：成员自己关 / 站长关全站，关的是"新发送"而不是历史
 *   5. 治理：管理员默认只见元数据（条数与时间），正文一个字节都不给
 *   6. 举报才展开：非当事方举报不了；展开逐次写操作日志；忽略留痕
 *   7. 撤回只限本人、限频、长度
 *   8. 在线人数与仪表盘同口径，且不把完整 QQ 号带进响应
 *   9. 清理：站长按日期清得掉私聊、清不掉举报留痕；平台白名单含私聊表
 *  10. 守卫：私聊不进导出清单、不进巡查白名单
 *  11. 回归：群消息 / 仪表盘 / 登录日志 / 管理中心不受影响
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
/** 返回 [status, json, headers, raw]；Cookie 按身份在内存里维护（不用 curl 的 cookie jar） */
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
function countRows(string $table): int { return (int)db_val("SELECT COUNT(*) FROM $table"); }
/** 战队侧登录（登录名就是 QQ 号） */
function loginTenant(string $who, string $slug, string $acct): bool {
    [$s, $j] = as_req($who, 'POST', '/' . $slug . '/api/auth/login',
        ['username' => $acct, 'password' => 'pass123456']);
    return (int)($j['code'] ?? 1) === 0;
}
/**
 * 一定要成功的发送：私聊与聊天室一样有 2 秒节流，撞上限频就等一秒再试。
 * 失败重试最多 6 次，避免测试里因为限频把后面整段断言带偏。
 */
function dmSend(string $who, int $to, string $content): array {
    for ($i = 0; $i < 6; $i++) {
        [$s, $j] = as_req($who, 'POST', '/cdm/api/chat/dm/send', ['to' => $to, 'content' => $content]);
        if ((int)($j['code'] ?? 1) === 0) { return [$s, $j]; }
        if ((int)($j['code'] ?? 0) !== 429 && (int)$s !== 429) { return [$s, $j]; }
        sleep(1);
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

/**
 * 建一支战队：platform_users 入驻材料 + tenants + users
 * 约定：people[0] 是站长；最后一个人是"挂名管理员"（role=admin 但只有 mute 权限）
 */
$mk = function (string $slug, string $name, array $people) use ($pdo, $hash, $now): array {
    $pdo->exec("INSERT INTO platform_users (username,password,nickname,role,status,reg_ip,created_at,updated_at)
        VALUES ('{$slug}boss', '{$hash}', '{$name}联络人', 'team', 'normal', '127.0.0.1', '$now', '$now')");
    $pid = (int)$pdo->lastInsertId();
    $ownerQq = $people[0];
    $pdo->exec("INSERT INTO tenants (applicant_id, slug, name, status, admin_username, admin_user_id, created_at, updated_at)
        VALUES ($pid, '$slug', '$name', 'approved', '$ownerQq', NULL, '$now', '$now')");
    $tid = (int)$pdo->lastInsertId();
    $ids = [];
    $last = count($people) - 1;
    foreach ($people as $i => $qq) {
        $nick  = $slug . '成员' . ($i + 1);
        $role  = 'user';
        $perms = '[]';
        if ($i === 0)  { $role = 'admin'; $nick = $slug . '队长'; }
        if ($i === $last) { $role = 'admin'; $perms = '["mute"]'; $nick = $slug . '挂名管理'; }
        $pdo->exec("INSERT INTO users (tenant_id, username, password, nickname, role, status, admin_perms, created_at, updated_at)
            VALUES ($tid, '$qq', '$hash', '$nick', '$role', 'approved', '$perms', '$now', '$now')");
        $ids[$qq] = (int)$pdo->lastInsertId();
    }
    $pdo->exec("UPDATE tenants SET admin_user_id = {$ids[$ownerQq]} WHERE id = $tid");
    return ['tid' => $tid, 'ids' => $ids];
};

$A = $mk('cdm', '聊天测试A队', ['940010001', '940010011', '940010012', '940010013', '940010014']);
$B = $mk('cdb', '聊天测试B队', ['940020001', '940020002', '940020003']);
$tidA  = (int)$A['tid'];
$u1    = (int)$A['ids']['940010011'];   // 甲：私聊发起方
$u2    = (int)$A['ids']['940010012'];   // 乙：接收方
$u3    = (int)$A['ids']['940010013'];   // 丙：无关第三人
$bMem  = (int)$B['ids']['940020002'];   // B 队成员
echo '  种子：A队 tid=' . $tidA . " u1=$u1 u2=$u2 u3=$u3 ｜ B队 mem=$bMem\n";

foreach ([['a1', 'cdm', '940010011'], ['a2', 'cdm', '940010012'], ['a3', 'cdm', '940010013'],
          ['asub', 'cdm', '940010014'], ['aown', 'cdm', '940010001'],
          ['bmem', 'cdb', '940020002']] as $p) {
    check('登录 ' . $p[0] . '（' . $p[2] . '）', loginTenant($p[0], $p[1], $p[2]));
}

$SECRET  = '私聊内容S1绝不给管理员';
$SECRET2 = '第二条未读测试';
$GROUP   = '群消息G1';
$pair    = min($u1, $u2) . '-' . max($u1, $u2);

// ============================================================
// 1. 战队群零回归 + 群接口读不到私聊
// ============================================================
echo "\n== 1. 战队群（原聊天室）零回归，且与私聊结构隔离 ==\n";
[$s, $j] = as_req('a1', 'POST', '/cdm/api/chat/send', ['content' => $GROUP]);
check('群里发一条仍正常', (int)($j['code'] ?? 1) === 0, J($j));
[$s, $j, $h, $raw] = as_req('a1', 'GET', '/cdm/api/chat/messages');
check('群消息列表里能看到那条群消息', strpos($raw, $GROUP) !== false, substr($raw, 0, 120));
check('群接口响应结构没被改动（list/me_id/is_admin/global_mute）',
    isset($j['data']['list']) && isset($j['data']['global_mute']) && isset($j['data']['me_id']),
    J(array_keys($j['data'] ?? [])));

[$s, $j] = dmSend('a1', $u2, $SECRET);
$dmId1 = (int)($j['data']['id'] ?? 0);
check('甲给乙发一条私聊', (int)($j['code'] ?? 1) === 0 && $dmId1 > 0, J($j));
check('私聊只进私聊表，一条都没混进 chat_messages',
    (int)db_val("SELECT COUNT(*) FROM chat_dm_messages WHERE id = $dmId1") === 1
    && (int)db_val("SELECT COUNT(*) FROM chat_messages WHERE content = '$SECRET'") === 0);
check('会话键把两人锁成同一个 pair',
    (string)db_val("SELECT pair_key FROM chat_dm_messages WHERE id = $dmId1") === $pair);
[$s, $j, $h, $raw] = as_req('a1', 'GET', '/cdm/api/chat/messages');
check('群消息接口一个字的私聊都读不到', strpos($raw, $SECRET) === false, substr($raw, 0, 200));

// ============================================================
// 2. 私聊收发、会话列表与未读
// ============================================================
echo "\n== 2. 私聊双向可见 / 会话列表 / 未读角标 ==\n";
[$s, $j, $h, $raw] = as_req('a2', 'GET', '/cdm/api/chat/dm/thread?with=' . $u1);
check('乙打开对话能看到这条', strpos($raw, $SECRET) !== false, substr($raw, 0, 160));
check('乙看到的是对方发的（mine=0）',
    (int)($j['data']['list'][0]['mine'] ?? 1) === 0 && (int)($j['data']['list'][0]['from'] ?? 0) === $u1,
    J($j['data']['list'] ?? null));
check('thread 带出对方信息与在线标记',
    (int)($j['data']['peer']['id'] ?? 0) === $u1 && array_key_exists('online', $j['data']['peer'] ?? []),
    J($j['data']['peer'] ?? null));
check('对方"接受私聊"状态一并回给前端（发之前就能提示）',
    array_key_exists('allow_dm', $j['data']['peer'] ?? []), J($j['data']['peer'] ?? null));

[$s, $j, $h, $raw] = as_req('a1', 'GET', '/cdm/api/chat/dm/thread?with=' . $u2);
check('甲打开同一个对话看到同一条（双向同一会话）',
    strpos($raw, $SECRET) !== false && (int)($j['data']['list'][0]['mine'] ?? 0) === 1,
    J($j['data']['list'] ?? null));

[$s, $j] = as_req('a2', 'GET', '/cdm/api/chat/dm/conversations');
$cv   = $j['data']['list'] ?? [];
$rowA = array_values(array_filter($cv, fn($r) => (int)$r['peer_id'] === $u1));
check('乙的会话列表里正好有甲这一个会话', count($cv) === 1 && count($rowA) === 1, J($cv));
check('乙刚打开过，未读是 0', (int)($rowA[0]['unread'] ?? -1) === 0, J($rowA[0] ?? null));

[$s, $j] = dmSend('a1', $u2, $SECRET2);
check('甲再发一条', (int)($j['code'] ?? 1) === 0, J($j));
[$s, $j] = as_req('a2', 'GET', '/cdm/api/chat/dm/conversations');
$rowA = array_values(array_filter($j['data']['list'] ?? [], fn($r) => (int)$r['peer_id'] === $u1));
check('新来一条时未读角标加到 1', (int)($rowA[0]['unread'] ?? 0) === 1, J($rowA[0] ?? null));
check('列表摘要就是最后一条（本人看自己的对话）',
    (string)($rowA[0]['last_snip'] ?? '') === $SECRET2, J($rowA[0] ?? null));
check('列表里带最后发言方，前端才能分左右', (int)($rowA[0]['last_mine'] ?? -1) === 0, J($rowA[0] ?? null));
[$s, $j] = as_req('a2', 'GET', '/cdm/api/chat/dm/thread?with=' . $u1);
[$s, $j] = as_req('a2', 'GET', '/cdm/api/chat/dm/conversations');
$rowA = array_values(array_filter($j['data']['list'] ?? [], fn($r) => (int)$r['peer_id'] === $u1));
check('打开对话后未读归零', (int)($rowA[0]['unread'] ?? -1) === 0, J($rowA[0] ?? null));
check('累计条数到 2', (int)($rowA[0]['msgs'] ?? 0) === 2, J($rowA[0] ?? null));
check('游标写在 chat_dm_reads 而不是消息表上',
    (int)db_val("SELECT COUNT(*) FROM chat_dm_reads WHERE tenant_id = $tidA AND user_id = $u2 AND peer_id = $u1") === 1);

// ============================================================
// 3. 越权
// ============================================================
echo "\n== 3. 跨队与横向越权 ==\n";
[$s, $j, $h, $raw] = as_req('a3', 'GET', '/cdm/api/chat/dm/thread?with=' . $u1);
check('丙查甲：没有他们的对话 ⇒ 空列表', (int)($j['code'] ?? 1) === 0 && count($j['data']['list'] ?? []) === 0, substr($raw, 0, 120));
check('丙这次请求的响应里不带出甲乙的内容', strpos($raw, $SECRET) === false, substr($raw, 0, 200));
[$s, $j, $h, $raw] = as_req('a1', 'GET', '/cdm/api/chat/dm/thread?with=' . $u3);
check('甲查丙同样是空（不会因为"第三人"多给什么）', strpos($raw, $SECRET) === false && count($j['data']['list'] ?? []) === 0);
sleep(3);   // 先让甲的发送节流过去，否则拿到的是 429 而不是越权答复
[$s, $j] = as_req('a1', 'POST', '/cdm/api/chat/dm/send', ['to' => $bMem, 'content' => '跨队试试']);
$crossId = (int)($j['data']['id'] ?? 0);
check('v5.30.1 起跨队私聊放开：甲能发给 B 队成员', (int)($j['code'] ?? 1) === 0 && $crossId > 0, J($j));
check('这条落在同一个会话键上（pair_key 只认全局 user id，不按队分）',
    (string)db_val("SELECT pair_key FROM chat_dm_messages WHERE id = $crossId") === min($u1, $bMem) . '-' . max($u1, $bMem),
    (string)db_val("SELECT pair_key FROM chat_dm_messages WHERE id = $crossId"));
[$s, $j, $h, $raw] = as_req('bmem', 'GET', '/cdb/api/chat/dm/thread?with=' . $u1);
check('B 队成员是当事人，读得到这一条', (int)($j['code'] ?? 1) === 0 && strpos($raw, '跨队试试') !== false, substr($raw, 0, 200));
check('跨队会话告诉前端对方是哪队的，完整 QQ 号一个都不给',
    (string)($j['data']['peer']['peer_team'] ?? '') === '聊天测试A队'
    && (int)($j['data']['peer']['same_team'] ?? 1) === 0
    && strpos($raw, '940010011') === false, J($j['data']['peer'] ?? null));
check('被对方拒绝之前最多发 1 条这条防骚扰内置：甲的第二条在 B 队那人回话之前发不出去',
    (function () use ($bMem) { sleep(3);
        [, $jj] = as_req('a1', 'POST', '/cdm/api/chat/dm/send', ['to' => $bMem, 'content' => '第二条没人回还想发']);
        return (int)($jj['code'] ?? 0) === 403 && strpos(J($jj), '防骚扰') !== false;
    })(), '见 ChatDm::strangerBlocked');

[$s, $j] = as_req('a1', 'POST', '/cdm/api/chat/dm/send', ['to' => $u1, 'content' => '自言自语']);
check('不能给自己发私聊', (int)($j['code'] ?? 0) !== 0, J($j));
$jars['anon'] = [];
[$s, $j] = as_req('anon', 'GET', '/cdm/api/chat/dm/conversations');
check('未登录进不了私聊接口', (int)$s !== 200 || (int)($j['code'] ?? 0) !== 0, "http=$s " . substr(J($j), 0, 120));

// ============================================================
// 4. 双层开关
// ============================================================
echo "\n== 4. 成员自己关 / 站长关全站 ==\n";
[$s, $j] = as_req('a2', 'POST', '/cdm/api/chat/dm/my-setting', ['allow_dm' => 0]);
check('乙关掉"接受本队成员私聊"', (int)($j['code'] ?? 1) === 0 && (int)($j['data']['allow_dm'] ?? 1) === 0, J($j));
check('开关只落在本人那一行',
    (int)db_val("SELECT allow_dm FROM chat_dm_prefs WHERE tenant_id = $tidA AND user_id = $u2") === 0);
sleep(3);   // 清掉甲的发送节流，确保下面拿到的是"被关"而不是"限频"
[$s, $j] = as_req('a1', 'POST', '/cdm/api/chat/dm/send', ['to' => $u2, 'content' => '关掉了还发得到吗']);
check('对方关闭后发送被拒并说明原因',
    (int)($j['code'] ?? 0) === 403 && strpos(J($j), '不接受私聊') !== false, J($j));
[$s, $j, $h, $raw] = as_req('a2', 'GET', '/cdm/api/chat/dm/thread?with=' . $u1);
check('关的是新发送，历史仍归本人可读', strpos($raw, $SECRET) !== false);
[$s, $j] = as_req('a2', 'POST', '/cdm/api/chat/dm/my-setting', ['allow_dm' => 1]);
check('乙重新打开', (int)($j['data']['allow_dm'] ?? 0) === 1, J($j));
[$s, $j] = dmSend('a1', $u2, '重开之后又能发了');
check('重新开放后甲发得出去', (int)($j['code'] ?? 1) === 0, J($j));

[$s, $j] = as_req('asub', 'POST', '/cdm/api/admin/chat/dm-toggle', ['enabled' => 0]);
check('没有 chat_report 权限的挂名管理员管不了开关', (int)($j['code'] ?? 0) === 403, J($j));
[$s, $j] = as_req('aown', 'POST', '/cdm/api/admin/chat/dm-toggle', ['enabled' => 0]);
check('队长可以关掉本站私聊', (int)($j['code'] ?? 1) === 0 && (int)($j['data']['dm_enabled'] ?? 1) === 0, J($j));
check('全队开关写在 settings 的 dm_enabled 键上',
    (string)db_val("SELECT value FROM settings WHERE tenant_id = $tidA AND key = 'dm_enabled'") === '0');
sleep(3);
[$s, $j] = as_req('a1', 'POST', '/cdm/api/chat/dm/send', ['to' => $u2, 'content' => '关了还发吗']);
check('全队关闭后任何人都发不出去',
    (int)($j['code'] ?? 0) === 403 && strpos(J($j), '关闭') !== false, J($j));
[$s, $j] = as_req('a1', 'GET', '/cdm/api/chat/dm/conversations');
check('列表如实告诉前端本站已关闭', (int)($j['data']['dm_enabled'] ?? 1) === 0, J($j['data'] ?? null));
[$s, $j] = as_req('aown', 'POST', '/cdm/api/admin/chat/dm-toggle', ['enabled' => 1]);
check('队长重新开放', (int)($j['data']['dm_enabled'] ?? 0) === 1, J($j));
check('开关动作进了本队操作日志',
    (int)db_val("SELECT COUNT(*) FROM admin_logs WHERE tenant_id = $tidA AND action = '私聊开关'") >= 2);

// ============================================================
// 5. 治理整体搬到平台侧（v5.30.1）：战队侧那四条入口下架，只剩一个功能开关
// ============================================================
echo "\n== 5. 战队侧读不到正文，举报与禁言只在平台处理 ==\n";
foreach ([
    ['/cdm/api/admin/chat/dm-stats', 'GET'], ['/cdm/api/admin/chat/reports', 'GET'],
    ['/cdm/api/admin/chat/report-open', 'POST'], ['/cdm/api/admin/chat/report-dismiss', 'POST'],
] as $gone) {
    [$s] = as_req('aown', $gone[1], $gone[0], $gone[1] === 'POST' ? ['id' => 1] : null);
    check('队长调已下架的 ' . $gone[0] . ' 直接 404（不是 200 也不是 500）', (int)$s === 404, "http=$s");
}
[$s, $j] = as_req('aown', 'POST', '/cdm/api/admin/chat/dm-toggle', ['enabled' => 1]);
check('战队这一侧只剩「本站要不要开放私聊」这一个开关，仍然能使', (int)($j['code'] ?? 1) === 0, J($j));

[$s, $j] = as_req('plat', 'POST', '/api/platform/auth/login', ['username' => 'admin', 'password' => 'admin123']);
check('平台超管登录', (int)($j['code'] ?? 1) === 0, J($j));
[$s, $j] = as_req('plat', 'GET', '/api/platform/admin/chat/reports?status=pending');
check('平台侧此刻还没有任何举报', (int)($j['code'] ?? 1) === 0 && count($j['data']['list'] ?? []) === 0, J($j));
[$s, $j] = as_req('plat', 'POST', '/api/platform/admin/chat/report/open', ['id' => 99999]);
check('没有举报编号就展开不了任何正文', (int)($j['code'] ?? 0) !== 0, J($j));
[$s, $j] = as_req('a3', 'GET', '/api/platform/admin/chat/reports');
check('普通成员冒充不了平台：这条入口要平台超级管理员登录态', (int)$s !== 200 || (int)($j['code'] ?? 0) !== 0, "http=$s");

// ============================================================
// 6. 举报才展开
// ============================================================
echo "\n== 6. 当事人举报 → 平台展开正文 → 平台结案回话 ==\n";
[$s, $j] = as_req('a3', 'POST', '/cdm/api/chat/dm/report', ['message_id' => $dmId1, 'reason' => '我想举报别人的对话']);
check('非当事方举报不了别人的私聊',
    (int)($j['code'] ?? 0) === 403 && strpos(J($j), '自己参与') !== false, J($j));
[$s, $j] = as_req('a1', 'POST', '/cdm/api/chat/dm/report', ['message_id' => $dmId1, 'reason' => '对方说话很难听，请队长看看']);
check('当事方举报成功', (int)($j['code'] ?? 1) === 0, J($j));
$repId = (int)db_val("SELECT id FROM chat_reports WHERE tenant_id = $tidA AND message_id = $dmId1");
check('举报落的是理由与锚点，不落正文',
    $repId > 0 && (int)db_val("SELECT COUNT(*) FROM chat_reports WHERE id = $repId AND reason LIKE '%很难听%'") === 1);
[$s, $j] = as_req('a1', 'POST', '/cdm/api/chat/dm/report', ['message_id' => $dmId1, 'reason' => '重复举报']);
check('同一条不能重复举报', (int)($j['code'] ?? 0) !== 0, J($j));
[$s, $j, $h, $raw] = as_req('plat', 'GET', '/api/platform/admin/chat/reports?status=pending');
$one = array_values(array_filter($j['data']['list'] ?? [], fn($r) => (int)$r['id'] === $repId));
check('平台举报列表里有这条且状态 pending', count($one) === 1 && (string)($one[0]['status'] ?? '') === 'pending', J($one));
check('列表本身不含私聊正文（只有理由与当事人）', strpos($raw, $SECRET) === false, substr($raw, 0, 220));
check('列表带上两人各是哪队的，跨队举报才分得清',
    (string)($one[0]['reporter']['team'] ?? '') === '聊天测试A队' && (int)($one[0]['cross_team'] ?? -1) === 0,
    J($one[0] ?? null));
check('列表里也不出完整 QQ 号', strpos($raw, '940010011') === false && strpos($raw, '940010012') === false, substr($raw, 0, 220));

$logBefore = (int)db_val("SELECT COUNT(*) FROM admin_logs WHERE tenant_id = 0 AND action = '私聊正文查看'");
[$s, $j, $h, $raw] = as_req('plat', 'POST', '/api/platform/admin/chat/report/open', ['id' => $repId]);
check('顺着举报展开，平台才第一次读到正文',
    (int)($j['code'] ?? 1) === 0 && strpos($raw, $SECRET) !== false, substr($raw, 0, 200));
check('展开的对话里被举报那条打了标记',
    count(array_filter($j['data']['list'] ?? [], fn($r) => (int)$r['reported'] === 1)) === 1, J($j['data']['list'] ?? null));
check('展开同时给出说话人的显示名与战队名',
    (string)($j['data']['list'][0]['from_name'] ?? '') !== '' && array_key_exists('from_team', $j['data']['list'][0] ?? []),
    J($j['data']['list'][0] ?? null));
check('展开出来的正文同样不带完整 QQ 号', strpos($raw, '940010011') === false, substr($raw, 0, 220));
check('每次展开往平台操作日志写一行（tenant_id=0，可与本队日志分开数）',
    (int)db_val("SELECT COUNT(*) FROM admin_logs WHERE tenant_id = 0 AND action = '私聊正文查看'") === $logBefore + 1);
check('举报状态从 pending 变 opened', (string)db_val("SELECT status FROM chat_reports WHERE id = $repId") === 'opened');
[$s, $j] = as_req('a1', 'POST', '/api/platform/admin/chat/report/open', ['id' => $repId]);
check('展开只对平台超管开放：当事人自己的战队登录态调不动', (int)$s !== 200 || (int)($j['code'] ?? 0) !== 0, "http=$s");

[$s, $j] = as_req('plat', 'POST', '/api/platform/admin/chat/report/handle',
    ['id' => $repId, 'action' => 'handled', 'note' => '']);
check('不写结果说明就结不了案', (int)($j['code'] ?? 0) !== 0 && strpos(J($j), '结果说明') !== false, J($j));
[$s, $j] = as_req('plat', 'POST', '/api/platform/admin/chat/report/handle',
    ['id' => $repId, 'action' => '瞎写', 'note' => '随便']);
check('结案只认 handled / dismissed 两种结果', (int)($j['code'] ?? 0) !== 0, J($j));
[$s, $j] = as_req('plat', 'POST', '/api/platform/admin/chat/report/handle',
    ['id' => $repId, 'action' => 'handled', 'note' => '已提醒双方注意言辞']);
check('结案并写结论', (int)($j['code'] ?? 1) === 0
    && (string)db_val("SELECT status FROM chat_reports WHERE id = $repId") === 'handled', J($j));
check('结论原样回给举报人（一个红点里那三种通知同一条路）',
    (int)db_val("SELECT COUNT(*) FROM forum_notices WHERE to_user_id = $u1 AND kind = 'report' AND note LIKE '%注意言辞%'") === 1);
check('结案同样留痕',
    (int)db_val("SELECT COUNT(*) FROM admin_logs WHERE tenant_id = 0 AND action = '私聊举报处理'") >= 1);

// ============================================================
// 7. 撤回、限频、长度
// ============================================================
echo "\n== 7. 撤回只限本人 / 限频 / 长度 ==\n";
sleep(3);
[$s, $j] = dmSend('a1', $u2, '这条我要自己撤回');
$dmRecall = (int)($j['data']['id'] ?? 0);
check('发一条待会儿要撤回的', $dmRecall > 0, J($j));
[$s, $j] = as_req('a1', 'POST', '/cdm/api/chat/dm/send', ['to' => $u2, 'content' => '限频测试']);
check('同一人 2 秒内连发第二条被限频', (int)($j['code'] ?? 0) === 429 || (int)$s === 429, J($j));
sleep(3);   // 长度与空白要在"没被限频"的状态下测，否则三件事测到的是同一道门
[$s, $j] = as_req('a1', 'POST', '/cdm/api/chat/dm/send', ['to' => $u2, 'content' => str_repeat('长', 501)]);
check('超过 500 字被拒（限频已过，说明长度校验独立生效）',
    (int)($j['code'] ?? 0) !== 0 && strpos(J($j), '500') !== false, J($j));
[$s, $j] = as_req('a1', 'POST', '/cdm/api/chat/dm/send', ['to' => $u2, 'content' => '   ']);
check('空白消息被拒', (int)($j['code'] ?? 0) !== 0, J($j));
[$s, $j] = as_req('a2', 'POST', '/cdm/api/chat/dm/recall', ['id' => $dmRecall]);
check('乙不能撤甲发的那条', (int)($j['code'] ?? 0) === 403 && strpos(J($j), '自己发的') !== false, J($j));
[$s, $j] = as_req('a1', 'POST', '/cdm/api/chat/dm/recall', ['id' => $dmRecall]);
check('甲能撤自己发的那条', (int)($j['code'] ?? 1) === 0, J($j));
[$s, $j, $h, $raw] = as_req('a2', 'GET', '/cdm/api/chat/dm/thread?with=' . $u1);
check('撤回后乙那边也不再显示这条', strpos($raw, '这条我要自己撤回') === false, substr($raw, 0, 200));
check('撤回是打标记不是物理删（留条数与时间）',
    (int)db_val("SELECT recalled FROM chat_dm_messages WHERE id = $dmRecall") === 1);

// ============================================================
// 8. 在线人数
// ============================================================
echo "\n== 8. 在线人数与仪表盘同口径 ==\n";
[$s, $j, $h, $raw] = as_req('a1', 'GET', '/cdm/api/chat/online');
check('在线人数接口正常返回', (int)($j['code'] ?? 1) === 0 && isset($j['data']['count']), substr($raw, 0, 160));
check('窗口就是仪表盘的 300 秒', (int)($j['data']['window'] ?? 0) === 300, J($j['data']['window'] ?? null));
check('最近有请求的身份都算在线', (int)($j['data']['count'] ?? 0) >= 3, (string)($j['data']['count'] ?? 0));
check('在线名单里出现的是昵称而不是 QQ 号',
    strpos($raw, 'cdm成员2') !== false && strpos($raw, '940010011') === false, substr($raw, 0, 200));
$dash = as_req('a1', 'GET', '/cdm/api/dashboard');
check('与仪表盘的 online_count 完全一致（同一张表同一个窗口）',
    (int)($j['data']['count'] ?? -1) === (int)($dash[1]['data']['online_count'] ?? -2),
    'chat=' . ($j['data']['count'] ?? '?') . ' dash=' . ($dash[1]['data']['online_count'] ?? '?'));
check('总数是本队已开通人数，不是在线数',
    (int)($j['data']['total'] ?? 0) === (int)db_val("SELECT COUNT(*) FROM users WHERE tenant_id = $tidA AND status = 'approved'"),
    J(['total' => $j['data']['total'] ?? null]));
$oCnt = (int)($j['data']['count'] ?? 0);   // 上面 chat/online 返回的那个值
[$s, $j] = as_req('a1', 'GET', '/cdm/api/chat/dm/conversations');
check('会话列表头部也带在线人数（两个页签一份口径）',
    (int)($j['data']['online_count'] ?? 0) >= 3 && (int)($j['data']['online_window'] ?? 0) === 300,
    J(['c' => $j['data']['online_count'] ?? null]));
check('会话列表的在线人数与 chat/online 是同一个值',
    (int)($j['data']['online_count'] ?? -1) === $oCnt,
    J(['list' => $j['data']['online_count'] ?? null, 'online' => $oCnt]));

// ============================================================
// 8b. 选人名单：前端「发起私聊」的数据源
// ============================================================
echo "\n== 8b. 可私聊名单 ==\n";
[$s, $j, $h, $raw] = as_req('a1', 'GET', '/cdm/api/chat/dm/candidates');
check('名单接口正常返回', (int)($j['code'] ?? 1) === 0 && isset($j['data']['list']), substr($raw, 0, 160));
$cds = $j['data']['list'] ?? [];
$hasSelf = false;
foreach ($cds as $c) { if ((int)($c['id'] ?? 0) === $u1) { $hasSelf = true; } }
check('名单里不含自己', !$hasSelf);
check('名单正好是本队其他已开通成员（排除自己）',
    count($cds) === (int)db_val("SELECT COUNT(*) FROM users WHERE tenant_id = $tidA AND status = 'approved'") - 1,
    J(['got' => count($cds)]));
check('跨队的成员不会出现在名单里', strpos($raw, '940020002') === false && strpos($raw, 'cdb成员') === false,
    substr($raw, 0, 200));
check('名单只有 id/显示名/在线，不吐完整 QQ 号',
    strpos($raw, '9400100') === false && strpos($raw, 'username') === false, substr($raw, 0, 200));
check('每一项都带在线标记', $cds && isset($cds[0]['online']) && isset($cds[0]['name']));
check('名单顺带告诉前端本站私聊开着、我自己允许被私聊',
    (int)($j['data']['dm_enabled'] ?? 0) === 1 && (int)($j['data']['allow_me'] ?? 0) === 1,
    J(['e' => $j['data']['dm_enabled'] ?? null, 'a' => $j['data']['allow_me'] ?? null]));
[$s, $j] = as_req('anon', 'GET', '/cdm/api/chat/dm/candidates');
check('未登录拿不到名单', (int)$s !== 200 || (int)($j['code'] ?? 0) !== 0, "http=$s " . substr(J($j), 0, 120));

// ============================================================
// 8c. 最后活跃时间：只给持 member_view 的队长/管理员
// ============================================================
echo "\n== 8c. 最后活跃时间的可见范围 ==\n";
// 给乙钉一个可辨认的最后活跃时间（1 小时前 ⇒ 同时是"离线"），再比较三种身份各自看到什么
$marker = date('Y-m-d H:i:s', time() - 3600);
db()->exec("UPDATE users SET last_active_at = '$marker' WHERE id = $u2");
[$s, $j, $h, $raw] = as_req('a1', 'GET', '/cdm/api/chat/dm/thread?with=' . $u2);
check('普通成员读对话只拿到在线/离线，拿不到对方最后活跃时间',
    (int)($j['code'] ?? 1) === 0 && array_key_exists('last_active', $j['data']['peer'])
    && $j['data']['peer']['last_active'] === null, J($j['data']['peer'] ?? null));
check('对方的活跃时间值一个字都没进普通成员的响应体', strpos($raw, $marker) === false, substr($raw, 0, 120));
check('普通成员这边看到的是离线', (int)($j['data']['peer']['online'] ?? 1) === 0, J($j['data']['peer'] ?? null));
[$s, $j] = as_req('a1', 'GET', '/cdm/api/chat/dm/conversations');
$rowA = $j['data']['list'][0] ?? [];
check('会话列表同样不给普通成员这个字段',
    array_key_exists('peer_last_active', $rowA) && $rowA['peer_last_active'] === null, J($rowA));
[$s, $j] = as_req('aown', 'GET', '/cdm/api/chat/dm/thread?with=' . $u2);
$act = $j['data']['peer']['last_active'] ?? null;
check('队长（持 member_view）能看到对方最后活跃时间', (string)$act === $marker, var_export($act, true));
check('但队长从这条路读不到任何消息正文（他不是当事人）',
    (int)($j['code'] ?? 1) === 0 && count($j['data']['list'] ?? [-1]) === 0,
    J(['n' => count($j['data']['list'] ?? [-1])]));
[$s, $j] = as_req('asub', 'GET', '/cdm/api/chat/dm/thread?with=' . $u2);
check('只有 mute 权限的挂名管理员也看不到这个时间',
    array_key_exists('last_active', $j['data']['peer'] ?? [])
    && $j['data']['peer']['last_active'] === null, J($j['data']['peer'] ?? null));
$dmSrc = (string)file_get_contents($ROOT . '/app/controller/ChatDm.php');
check('两道 gate 都写死了 member_view，不是"是管理员就给"',
    substr_count($dmSrc, "admin_can('member_view')") === 2
    && strpos($dmSrc, "'last_active' => (is_admin() && admin_can('member_view'))") !== false);

// ============================================================
// 9. 清理
// ============================================================
echo "\n== 9. 清理：私聊清得掉，举报留痕清不掉 ==\n";
$old = date('Y-m-d H:i:s', time() - 200 * 86400);
db()->exec("UPDATE chat_dm_messages SET created_at = '$old' WHERE id = $dmId1");
$repBefore = countRows('chat_reports');
[$s, $j] = as_req('aown', 'POST', '/cdm/api/admin/chat/cleanup', ['days' => 30]);
check('站长按天数清理把那条 200 天前的私聊一起清掉了',
    (int)($j['code'] ?? 1) === 0 && (int)($j['data']['dm_deleted'] ?? 0) >= 1, J($j));
check('被清的私聊确实从库里没了', (int)db_val("SELECT COUNT(*) FROM chat_dm_messages WHERE id = $dmId1") === 0);
check('举报记录不跟着清（治理留痕）', countRows('chat_reports') === $repBefore);
[$s, $j] = as_req('plat', 'POST', '/api/platform/auth/login', ['username' => 'admin', 'password' => 'admin123']);
check('平台超管登录', (int)($j['code'] ?? 1) === 0, J($j));
[$s, $j, $h, $raw] = as_req('plat', 'GET', '/api/platform/admin/log-stats');
check('平台"日志清理"的表清单里加了私聊',
    strpos($raw, 'chat_dm_messages') !== false && strpos($raw, '私聊消息') !== false, substr($raw, 0, 200));
[$s, $j] = as_req('plat', 'POST', '/api/platform/admin/log-cleanup',
    ['tenant_id' => $tidA, 'type' => 'chat_dm_messages', 'before' => date('Y-m-d'), 'apply' => 0]);
check('平台侧预览私聊条数不需要改代码就能算', (int)($j['code'] ?? 1) === 0, J($j));

// ============================================================
// 10. 守卫：私聊不进导出、不进巡查白名单
// ============================================================
echo "\n== 10. 守卫 ==\n";
$dt = (string)file_get_contents($ROOT . '/app/service/DataTransfer.php');
check('站点导出清单里没有私聊表（导出包不该变成读私聊的后门）',
    !preg_match("/'chat_dm_messages'\s*=>/", $dt) && strpos($dt, "Db::name('chat_dm_messages')") === false);
$routes = (string)file_get_contents($ROOT . '/route/app.php');
$insp = substr($routes, (int)strpos($routes, "Route::group('/api/inspect'"));
check('隐身巡查白名单里没有任何私聊路由', strpos($insp, 'ChatDm') === false && strpos($insp, 'dm-') === false);
check('管理中心那张表也没有把私聊正文塞进任何巡查接口',
    strpos((string)file_get_contents($ROOT . '/app/controller/Inspect.php'), 'chat_dm_messages') === false);

// 前端那两份"谁在线"必须共用一个来源，不然改了窗口口径只改到一半
$fe = (string)file_get_contents($ROOT . '/public/static/js/app.jsx');
check('私聊面板的在线名单用的就是 /api/chat/online 那一份，全仓库不另起口径',
    strpos($fe, 'function DmPanel({ me, dmInfo, onlineList') !== false
        && strpos($fe, 'onlineList={online}') !== false
        && substr_count($fe, "'/api/chat/online'") === 1);
check('在线队友条按名单排除自己（count 含本人，直接减会多算一个）',
    strpos($fe, 'const onlineOthers = ((onlineList && onlineList.list) || [])') !== false
        && strpos($fe, 'u.id !== me.id') !== false
        && strpos($fe, 'onlineTotal - onlinePeers.length') !== false);

// ============================================================
// 11. 回归
// ============================================================
echo "\n== 11. 回归：群 / 仪表盘 / 登录日志 / 管理中心 ==\n";
[$s, $j, $h, $raw] = as_req('a2', 'GET', '/cdm/api/chat/messages');
check('群消息接口仍能读到自己与别人的群消息', (int)($j['code'] ?? 1) === 0 && strpos($raw, $GROUP) !== false);
[$s, $j] = as_req('a2', 'GET', '/cdm/api/dashboard');
check('仪表盘正常', (int)($j['code'] ?? 1) === 0);
[$s, $j] = as_req('aown', 'GET', '/cdm/api/admin/login-logs');
check('v5.18.0 的登录日志页签仍正常', (int)($j['code'] ?? 1) === 0);
[$s, $j] = as_req('a1', 'GET', '/cdm/api/stats/my');
check('成员战绩接口正常', (int)($j['code'] ?? 1) === 0);
[$s, $j] = as_req('a3', 'GET', '/cdm/api/chat/online');
check('普通成员也能看在线人数（这份名单本来就全员可见）', (int)($j['code'] ?? 1) === 0);

echo "\n================ 结果 ================\n";
echo "通过 $pass 项 / 失败 $fail 项\n";
if ($failures) {
    echo "失败明细：\n";
    foreach ($failures as $f) { echo "  - $f\n"; }
    exit(1);
}
exit(0);
