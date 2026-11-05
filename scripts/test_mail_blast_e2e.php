<?php
/**
 * v5.31.0 平台邮件群发 E2E（scripts/test_mail_blast_e2e.php）
 *
 * 前置：测试服务器已启动，.env 指向 SQLite（.env 里只能用 ; 注释）
 *   php -S 127.0.0.1:8899 -t public public/router.php
 * 用法：php scripts/test_mail_blast_e2e.php
 *   假 SMTP 由本脚本自己起、自己停（scripts/fake_smtp_server.php，只听 127.0.0.1，端口从 2525 往上挑一个没人听的）；
 *   端口上已经有一个在跑就沿用，不重复起。
 *
 * 十二条主线，全按「真实 HTTP 请求 + 落库复核 + 假服务器收到的那封信」来断言：
 *   1. 只平台超管能用：没登录、战队站长、平台的入驻账号，三种都进不来
 *   2. 平台那套发信账号：授权码不回明文、留空＝不改、CLEAR 才是清空、非法值拦住、与战队的 smtp_* 互不相干
 *   3. 收件人两组怎么算：站长与管理员 / 全部已上线成员 / 两组一起勾不重复 / 封禁队与待审核不算
 *   4. 名单不出服务器：只回人数与脱敏抽样，一个完整 QQ 都不带
 *   5. 预览门禁：没预览建不了批、改一个字作废、上限拦下来不吃门禁、同一份不能建两个批
 *   6. 正文「混着来自动判」：整篇像 HTML 才按 HTML 发，标签写一半的按纯文本发
 *   7. 限速队列：一次一步、每步 batch 封、跑完才 done、再点一次不重发
 *   8. 逐封结果：服务器退回的记 failed 并留下那句话，没地址的记 skipped，两种都照补站内信
 *   9. 暂停 / 继续 / 卡在「已认领」的死行重排（重排后同一个人不会多一条站内信）
 *  10. 发送记录与明细：计数对得上、能筛能翻页，读明细每次都留痕
 *  11. 战队侧：成员在站内信里看到「平台通知」，只有收件人本人读得到整篇正文，且不回可执行标签
 *  12. 销毁站点前的告知（v5.36.32）：被封站点默认一个都不算，平台显式勾上 include_banned 才带上；
 *      这颗勾只认 1、不粘在下一次，建批时在分组串里留记号，操作日志写清带了几支几个人
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

$ROOT = dirname(__DIR__);
$BASE = rtrim((string)(getenv('TEST_BASE') ?: 'http://127.0.0.1:8899'), '/');
$DB   = $ROOT . '/runtime/test.sqlite';
// 端口是挑的，不是写死的：这台机器上随时可能有别的项目的假服务器占着 2525
function smtp_free_port(): int
{
    for ($p = 2525; $p <= 2560; $p++) {
        $f = @fsockopen('127.0.0.1', $p, $e, $s2, 0.2);
        if ($f) { fclose($f); continue; }
        return $p;
    }
    return 0;
}
$SMTP_PORT = smtp_free_port();
if ($SMTP_PORT <= 0) {
    fwrite(STDERR, "2525-2560 全被占着，这一轮不起假服务器（不拿别人的端口当自己的）。
");
    exit(1);
}
$SMTP_LOG  = $ROOT . '/runtime/_fakesmtp' . $SMTP_PORT . '.log';
$SMTP_REJ  = $ROOT . '/runtime/_fakesmtp' . $SMTP_PORT . '.reject';
$SMTP_STOP = $ROOT . '/runtime/_fakesmtp' . $SMTP_PORT . '.stop';
$SMTP_OUT  = $ROOT . '/runtime/_fakesmtp' . $SMTP_PORT . '.out';
$SMTP_DONE = $ROOT . '/runtime/_fakesmtp' . $SMTP_PORT . '.done';

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
/** 返回 [status, json, headers, raw]；Cookie 按身份在内存里维护（不用 curl 的 jar，避免回写滞后） */
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

// ---------------- 假 SMTP ----------------
function smtp_alive(): bool
{
    global $SMTP_PORT;
    $f = @fsockopen('127.0.0.1', $SMTP_PORT, $e, $s, 1);
    if ($f) { fclose($f); return true; }
    return false;
}
$smtpProc = null;
$smtpOwned = false;
/** 现在占着这个端口的是哪个进程号（拿不到就回 0） */
function smtp_port_pid(): int
{
    global $SMTP_PORT;
    $out = (string)@shell_exec('netstat -ano | findstr /I "LISTENING" | findstr /I "127.0.0.1:' . $SMTP_PORT . '"');
    foreach (array_filter(preg_split('/\R/', $out) ?: []) as $ln) {
        $cols = preg_split('/\s+/', trim($ln)) ?: [];
        $last = end($cols);
        if (is_numeric($last) && (int)$last > 0) { return (int)$last; }
    }
    return 0;
}
/** 只认这一台假 SMTP：命令行里带 fake_smtp_server.php 才敢杀，别的进程一律不动 */
function smtp_pid_is_fake(int $pid): bool
{
    $cmd = (string)@shell_exec('powershell -NoProfile -Command "(Get-CimInstance Win32_Process -Filter \'ProcessId=' . $pid . '\').CommandLine"');
    return strpos($cmd, 'fake_smtp_server.php') !== false;
}
/** 起自己那一台之前，先把端口上那只请下去（递 stop 文件 → 等 → 确认身份才杀） */
function smtp_reclaim(): void
{
    global $ROOT, $SMTP_PORT, $SMTP_STOP;
    @touch($SMTP_STOP);
    for ($i = 0; $i < 60 && smtp_alive(); $i++) { usleep(100000); }
    @unlink($SMTP_STOP);
    if (!smtp_alive()) { return; }
    $pid = smtp_port_pid();
    if ($pid > 0 && smtp_pid_is_fake($pid)) {
        @exec('taskkill /PID ' . $pid . ' /F');
        for ($i = 0; $i < 60 && smtp_alive(); $i++) { usleep(100000); }
    }
    if (smtp_alive()) {
        fwrite(STDERR, '127.0.0.1:' . $SMTP_PORT . " 还被一个认不出身份的进程占着，这一轮不起假 SMTP —— "
            . "把占着端口的进程停掉再跑（宁可不出结果，也不能拿别人家的端口当自己的）。\n");
        exit(1);
    }
}
function smtp_start(): void
{
    global $ROOT, $SMTP_PORT, $SMTP_STOP, $SMTP_OUT, $SMTP_DONE, $smtpProc, $smtpOwned;
    @unlink($SMTP_DONE);
    smtp_reclaim();
    $smtpOwned = true;
    $cmd = '"' . PHP_BINARY . '" "' . $ROOT . '/scripts/fake_smtp_server.php" ' . $SMTP_PORT;
    $smtpProc = @proc_open($cmd, [
        0 => ['pipe', 'r'],
        1 => ['file', $SMTP_OUT, 'a'],
        2 => ['file', $SMTP_OUT, 'a'],
    ], $pipes);
    if (!is_resource($smtpProc)) {
        fwrite(STDERR, "假 SMTP 起不动（proc_open 失败），这一轮不跑。
");
        exit(1);
    }
    for ($i = 0; $i < 60 && !smtp_alive(); $i++) { usleep(100000); }
    if (!smtp_alive()) {
        // 起了却没听着：把它的输出原样带出来，别让人对着「收到 0 封」猜半天
        fwrite(STDERR, "假 SMTP 起了但没在 {$SMTP_PORT} 上听着，它的输出：\n"
            . (is_file($SMTP_OUT) ? (string)file_get_contents($SMTP_OUT) : '（没有输出文件）') . "
");
        exit(1);
    }
}
function smtp_stop(): void
{
    global $ROOT, $SMTP_STOP, $SMTP_DONE, $smtpProc, $smtpOwned;
    if (!$smtpOwned) { return; }
    @touch($SMTP_STOP);
    if (is_resource($smtpProc)) {
        @proc_terminate($smtpProc);
    }
    for ($i = 0; $i < 40 && smtp_alive(); $i++) { usleep(100000); }
    @unlink($SMTP_STOP);
    @unlink($SMTP_DONE);
}
function smtp_mails(): array
{
    global $SMTP_LOG;
    if (!is_file($SMTP_LOG)) { return []; }
    $out = [];
    // 必须按 \n 切：\R 在无 /u 时认单字节 0x85，中文正文会把一行 JSON 从汉字中间切断
    foreach (explode("\n", trim((string)file_get_contents($SMTP_LOG))) as $l) {
        if ($l === '') { continue; }
        $j = json_decode($l, true);
        if (is_array($j)) { $out[] = $j; }
    }
    return $out;
}
/** 基线：现在日志有多少字节（后面只数新增的行，不去动这个文件） */
function smtp_mark(): int
{
    global $SMTP_LOG;
    // filesize() 走 PHP 的 stat 缓存：不发这封就不知道文件长了，基线会停在旧位置（数出假的封数）
    clearstatcache(true, $SMTP_LOG);
    return is_file($SMTP_LOG) ? (int)filesize($SMTP_LOG) : 0;
}
/** 从某个字节偏移往后收到的信（这一轮新写的都在里面） */
function smtp_new(int $mark): array
{
    global $SMTP_LOG;
    if (!is_file($SMTP_LOG)) {
        return $mark === 0 ? [] : [];
    }
    $fp = @fopen($SMTP_LOG, 'rb');
    if (!$fp) {
        return [];
    }
    fseek($fp, $mark);
    $out = [];
    while (($l = fgets($fp)) !== false) {
        $l = trim($l);
        if ($l === '') {
            continue;
        }
        $j = json_decode($l, true);
        if (is_array($j)) {
            $out[] = $j;
        }
    }
    fclose($fp);
    return $out;
}
/** 往退回名单里加一个地址片段（假服务器每次收信都现读这个文件，跑到一半也能改） */
function smtp_reject(string $frag): void
{
    global $SMTP_REJ;
    $cur = is_file($SMTP_REJ) ? trim((string)file_get_contents($SMTP_REJ)) : '';
    file_put_contents($SMTP_REJ, ($cur === '' ? '' : $cur . "\n") . $frag . "\n");
}
/** 一路 step 到这批跑完（或最多 n 步），回最后一次的进度 */
function smtp_run_queue(int $bid, int $cap = 30): array
{
    $pr = [];
    for ($i = 0; $i < $cap; $i++) {
        [$s, $j] = as_req('plat', 'POST', '/api/platform/admin/mail/step', ['blast_id' => $bid]);
        $pr = $j['data'] ?? [];
        if ((int)($pr['left'] ?? 1) === 0 || (string)($pr['status'] ?? '') === 'done') { break; }
        if (!isset($pr['tried'])) { break; }
    }
    return $pr;
}

// ============================================================
// 0. 重建测试库、种子、起假 SMTP
// ============================================================
echo "== 0. 重建测试库与种子 ==\n";
@unlink($DB);
echo shell_exec('"' . PHP_BINARY . '" "' . $ROOT . '/scripts/dev_init_sqlite.php" "' . $DB . '" 2>&1');
foreach (glob($ROOT . '/runtime/cache/*/*') ?: [] as $f) { if (is_file($f)) @unlink($f); }
foreach (glob($ROOT . '/runtime/cache/*') ?: [] as $f) { if (is_file($f)) @unlink($f); }

$now  = date('Y-m-d H:i:s');
$pdo  = db();
$hash = password_hash('pass123456', PASSWORD_DEFAULT);

/** 建一支战队：people 每项 [qq, role, status, email]；email 留空就是要测「能不能从 QQ 号推出来」 */
$mk = function (string $slug, string $name, array $people, string $tstatus = 'approved') use ($pdo, $hash, $now): array {
    $pdo->exec("INSERT INTO platform_users (username,password,nickname,role,status,reg_ip,created_at,updated_at)
        VALUES ('{$slug}boss', '{$hash}', '{$name}联络人', 'team', 'normal', '127.0.0.1', '$now', '$now')");
    $pid = (int)$pdo->lastInsertId();
    $pdo->exec("INSERT INTO tenants (applicant_id, slug, name, status, admin_username, admin_user_id, created_at, updated_at)
        VALUES ($pid, '$slug', '$name', '$tstatus', '{$people[0][0]}', NULL, '$now', '$now')");
    $tid = (int)$pdo->lastInsertId();
    $ids = [];
    foreach ($people as $p) {
        [$qq, $role, $st, $email] = $p;
        $nick = $role === 'admin' ? $slug . '站长' : $slug . '成员' . $qq;
        $pdo->exec("INSERT INTO users (tenant_id, username, password, nickname, email, role, status, admin_perms, created_at, updated_at)
            VALUES ($tid, '$qq', '$hash', '$nick', '$email', '$role', '$st', '[]', '$now', '$now')");
        $ids[$qq] = (int)$pdo->lastInsertId();
    }
    $pdo->exec("UPDATE tenants SET admin_user_id = {$ids[$people[0][0]]} WHERE id = $tid");
    return ['tid' => $tid, 'ids' => $ids, 'pid' => $pid];
};

// A 队：站长 + 副管理 + 3 个已上线成员（其中一人邮箱栏空着靠 QQ 推、一人两头都空）+ 1 个待审核
$A = $mk('v5m', '邮件群发A队', [
    ['960010001', 'admin', 'approved', '960010001@qq.com'],
    ['960010002', 'admin', 'approved', '960010002@qq.com'],
    ['960010003', 'user',  'approved', '960010003@qq.com'],
    ['960010004', 'user',  'approved', ''],
    ['nomail05',  'user',  'approved', ''],
    ['960010006', 'user',  'pending',  '960010006@qq.com'],
]);
// B 队：站长 + 1 个成员 + 1 个已封禁账号
$B = $mk('v5n', '邮件群发B队', [
    ['960020001', 'admin', 'approved', '960020001@qq.com'],
    ['960020002', 'user',  'approved', '960020002@qq.com'],
    ['960020003', 'user',  'banned',   '960020003@qq.com'],
]);
// C 队整队封禁：这一队的人一个都不该进收件人
$C = $mk('v5o', '邮件群发C队', [
    ['960030001', 'admin', 'approved', '960030001@qq.com'],
    ['960030002', 'user',  'approved', '960030002@qq.com'],
], 'banned');

$aTid = (int)$A['tid']; $bTid = (int)$B['tid']; $cTid = (int)$C['tid'];
$a0   = (int)$A['ids']['960010001'];   // A 站长
$aSub = (int)$A['ids']['960010002'];   // A 副管理（也在 owner 组）
$a1   = (int)$A['ids']['960010003'];   // A 普通成员
$a2   = (int)$A['ids']['960010004'];   // 邮箱栏空着，但 QQ 推得出
$a3   = (int)$A['ids']['nomail05'];    // 两头都空 → 没可用地址
$b0   = (int)$B['ids']['960020001'];
$b1   = (int)$B['ids']['960020002'];   // 故意让假服务器按名单退回
echo "  种子：A tid=" . $aTid . "（5 已上线，2 个管理） B tid=" . $bTid . "（2 已上线） C tid=" . $cTid . "（整队封禁）\n";

// 退回名单先清空（写空文件而不是删：这个文件正被假服务器反复读，删了再建在 Windows 上会撞分享冲突）
file_put_contents($SMTP_REJ, '');
smtp_start();
// 日志不删：起完服务器取一个字节基线，往后每一轮都只数「从这个位置之后新增的行」
$mark = smtp_mark();
check('假 SMTP 在 127.0.0.1:' . $SMTP_PORT . ' 听着（这一轮自己起的那只）', smtp_alive());

[$s, $j] = as_req('plat', 'POST', '/api/platform/auth/login', ['username' => 'admin', 'password' => 'admin123']);
check('平台超管登录', (int)($j['code'] ?? 1) === 0, J($j));
$login = function (string $who, string $slug, string $acct) : void {
    [$s, $j] = as_req($who, 'POST', '/' . $slug . '/api/auth/login', ['username' => $acct, 'password' => 'pass123456']);
    check('登录 ' . $who . '（' . $acct . '）', (int)($j['code'] ?? 1) === 0, J($j));
};
$login('a0', 'v5m', '960010001');
$login('a1', 'v5m', '960010003');
$login('b1', 'v5n', '960020002');
$login('a3', 'v5m', 'nomail05');

// ============================================================
// 1. 只平台超管能用
// ============================================================
echo "\n== 1. 权限守卫：这一页只属于平台超管 ==\n";
foreach (['config' => 'GET', 'blasts' => 'GET'] as $path => $m) {
    [$s, $j] = as_req('nobody', $m, '/api/platform/admin/mail/' . $path);
    check("没登录访问 /mail/{$path} 被挡（http={$s}）", $s === 401 || $s === 403, J($j));
}
[$s, $j] = as_req('a0', 'GET', '/api/platform/admin/mail/config');
check('A 队站长（战队超管）读不到平台群发配置',
    $s === 403 && str_contains((string)($j['msg'] ?? ''), '平台超级管理员'), 'http=' . $s . ' ' . J($j));
[$s, $j] = as_req('a0', 'POST', '/api/platform/admin/mail/blast', ['subject' => '越权', 'body' => '越权正文', 'groups' => 'owner,member']);
check('战队站长建不了批', $s === 403, 'http=' . $s);
[$s, $j] = as_req('a1', 'GET', '/api/platform/admin/mail/blasts');
check('普通成员也读不到批次列表', $s === 403, 'http=' . $s);
[$s, $j] = as_req('a1', 'POST', '/api/platform/admin/mail/step', ['blast_id' => 1]);
check('普通成员推不动队列', $s === 403, 'http=' . $s);

[$s, $j] = as_req('boss', 'POST', '/api/platform/auth/login', ['username' => 'v5mboss', 'password' => 'pass123456']);
check('平台入驻账号（role=team）能登录平台', (int)($j['code'] ?? 1) === 0, J($j));
[$s, $j] = as_req('boss', 'GET', '/api/platform/admin/mail/config');
check('平台入驻账号读平台群发配置被挡', $s === 403, 'http=' . $s . ' ' . J($j));
[$s, $j] = as_req('boss', 'POST', '/api/platform/admin/mail/preview', ['subject' => '越权', 'body' => '越权正文']);
check('平台入驻账号发不了预览', $s === 403, 'http=' . $s);

// ============================================================
// 2. 平台自己那一套发信账号
// ============================================================
echo "\n== 2. 平台 SMTP 配置（与战队的 smtp_* 完全分开）==\n";
[$s, $j] = as_req('plat', 'GET', '/api/platform/admin/mail/config');
$cfg0 = $j['data'] ?? [];
check('刚装机时平台还没配好发信账号', (int)($cfg0['ready'] ?? 1) === 0, J($cfg0));
check('配置里带着两个收件分组给人看的名字', isset($cfg0['groups']['owner'], $cfg0['groups']['member']), J($cfg0));
check('标题与正文的上限从配置接口一起给（前端不再自己抄一份）',
    (int)($cfg0['subject_max'] ?? 0) === 120 && (int)($cfg0['body_max'] ?? 0) === 20000, J($cfg0));

$smtp = ['host' => '127.0.0.1', 'port' => $SMTP_PORT, 'secure' => 'none',
    'user' => '999000111@qq.com', 'pass' => 'authcode-abcdef', 'from' => '平台运营 <999000111@qq.com>',
    'preview' => '999000111@qq.com', 'batch' => 2, 'gap' => 100, 'max' => 3000, 'enabled' => 1];
[$s, $j] = as_req('plat', 'POST', '/api/platform/admin/mail/config', $smtp);
check('保存平台发信账号', (int)($j['code'] ?? 1) === 0, J($j));
[$s, $j] = as_req('plat', 'GET', '/api/platform/admin/mail/config');
$d = $j['data'] ?? [];
check('保存后就绪了', (int)($d['ready'] ?? 0) === 1 && (int)($d['has_pass'] ?? 0) === 1, J($d));
check('配置接口不把授权码回显到前端', !str_contains(J($j), 'authcode-abcdef'), J($d));
check('端口/每批封数/间隔/上限都按保存值回读',
    (int)($d['port'] ?? 0) === $SMTP_PORT && (int)($d['batch'] ?? 0) === 2 && (int)($d['gap'] ?? 0) === 100 && (int)($d['max'] ?? 0) === 3000, J($d));
check('加密方式存成 none（本机假服务器只收明文）', (string)($d['secure'] ?? '') === 'none', J($d));

$p2 = $smtp; $p2['pass'] = '';
[$s, $j] = as_req('plat', 'POST', '/api/platform/admin/mail/config', $p2);
$still = (string)db_val("SELECT value FROM settings WHERE tenant_id=0 AND key='p_mail_pass'");
check('授权码留空保存＝保持原样（不会把自己清空）', $still === 'authcode-abcdef', '库里是 ' . J($still));
$p3 = $smtp; $p3['pass'] = 'CLEAR';
[$s, $j] = as_req('plat', 'POST', '/api/platform/admin/mail/config', $p3);
check('填 CLEAR 才是真清空授权码', (string)db_val("SELECT value FROM settings WHERE tenant_id=0 AND key='p_mail_pass'") === '');
[$s, $j] = as_req('plat', 'POST', '/api/platform/admin/mail/config', $smtp);
check('重新填回授权码', (string)db_val("SELECT value FROM settings WHERE tenant_id=0 AND key='p_mail_pass'") === 'authcode-abcdef', J($j));

$p5 = $smtp; $p5['port'] = 70000;
[$s, $j] = as_req('plat', 'POST', '/api/platform/admin/mail/config', $p5);
check('端口越界被拒', (int)($j['code'] ?? 0) !== 0 && str_contains((string)($j['msg'] ?? ''), '1-65535'), J($j));
$p6 = $smtp; $p6['preview'] = '不是邮箱';
[$s, $j] = as_req('plat', 'POST', '/api/platform/admin/mail/config', $p6);
check('预览收件地址不是邮箱被拒', (int)($j['code'] ?? 0) !== 0 && str_contains((string)($j['msg'] ?? ''), '合法'), J($j));
$p7 = $smtp; $p7['secure'] = 'warp';
[$s, $j] = as_req('plat', 'POST', '/api/platform/admin/mail/config', $p7);
check('加密方式只有三种，乱填被拒', (int)($j['code'] ?? 0) !== 0, J($j));
$p8 = $smtp; $p8['user'] = '999000111';
[$s, $j] = as_req('plat', 'POST', '/api/platform/admin/mail/config', $p8);
check('账号不是完整邮箱被拒', (int)($j['code'] ?? 0) !== 0 && str_contains((string)($j['msg'] ?? ''), '完整地址'), J($j));
[$s, $j] = as_req('plat', 'POST', '/api/platform/admin/mail/config', $smtp);
check('按原值再保存一次（把上面几次的坏值盖回去）', (int)($j['code'] ?? 1) === 0, J($j));

// 平台这套跟战队那套互不相干：给 A 队塞一份自己的 smtp_*，平台读不到也用不上
$pdo->exec("INSERT INTO settings (tenant_id,key,value) VALUES ($aTid,'smtp_host','team-server.invalid'),($aTid,'smtp_user','team@qq.com'),($aTid,'smtp_pass','teampass')");
[$s, $j] = as_req('plat', 'GET', '/api/platform/admin/mail/config');
$d = $j['data'] ?? [];
check('战队自己配的 smtp_host 没渗进平台这套（host 还是 127.0.0.1）',
    (string)($d['host'] ?? '') === '127.0.0.1' && !str_contains(J($d), 'team-server'), J($d));
check('平台这套配置也没写进 A 队的 smtp_*（两份各留各的）',
    (string)db_val("SELECT value FROM settings WHERE tenant_id=$aTid AND key='smtp_host'") === 'team-server.invalid');
check('配置动作记了一行平台操作日志',
    (int)db_val("SELECT COUNT(*) FROM admin_logs WHERE tenant_id=0 AND action='邮件群发-配置'") >= 1,
    '实际 ' . db_val("SELECT COUNT(*) FROM admin_logs WHERE tenant_id=0 AND action='邮件群发-配置'"));

// ============================================================
// 3-4. 收件人两组与「名单不出服务器」
// ============================================================
echo "\n== 3. 收件人两组怎么算 ==\n";
[$s, $j] = as_req('plat', 'POST', '/api/platform/admin/mail/recipients', ['groups' => 'owner']);
$o = $j['data'] ?? [];
check('只勾「站长与管理员」＝3 人（A 队 2 + B 队 1）',
    (int)($o['total'] ?? 0) === 3 && (int)($o['owner'] ?? 0) === 3, J($o));
check('C 队整队封禁，它的站长不在收件人里（覆盖 2 支队）', (int)($o['teams'] ?? 0) === 2, J($o));

[$s, $j] = as_req('plat', 'POST', '/api/platform/admin/mail/recipients', ['groups' => 'member']);
$m = $j['data'] ?? [];
check('只勾「全部已上线成员」＝7 人（待审核与已封禁账号不算）', (int)($m['total'] ?? 0) === 7, J($m));

[$s, $j] = as_req('plat', 'POST', '/api/platform/admin/mail/recipients', ['groups' => 'owner,member']);
$b = $j['data'] ?? [];
check('两组一起勾＝还是 7 人（站长不会因为两组各来一封）',
    (int)($b['total'] ?? 0) === 7 && (int)($b['owner'] ?? 0) === 3 && (int)($b['member'] ?? 0) === 4, J($b));
check('分组串存的是固定顺序（勾选先后不影响）', $b['groups'] === ['owner', 'member'], J($b['groups'] ?? null));
[$s, $j2] = as_req('plat', 'POST', '/api/platform/admin/mail/recipients', ['groups' => 'member,owner']);
check('先勾成员再勾站长，算出来一模一样', J($j2['data'] ?? []) !== '' && (int)($j2['data']['total'] ?? 0) === 7
    && $j2['data']['groups'] === ['owner', 'member'], J($j2['data']['groups'] ?? null));
check('没邮箱的一个人如实算出来（nomail05 两头都空）', (int)($b['no_mail'] ?? 0) === 1, J($b));
check('能发出去的 = 总数 - 没邮箱', (int)($b['will_send'] ?? 0) === 6, J($b));
[$s, $j] = as_req('plat', 'POST', '/api/platform/admin/mail/recipients', ['groups' => 'admin,all']);
check('一个合法分组都没勾就被拒', (int)($j['code'] ?? 0) !== 0, J($j));
[$s, $j] = as_req('plat', 'POST', '/api/platform/admin/mail/recipients', ['groups' => '']);
check('空分组被拒', (int)($j['code'] ?? 0) !== 0, J($j));

echo "\n== 4. 整本名单不出服务器 ==\n";
$qqleak = [];
foreach (['960010001', '960010002', '960010003', '960010004', '960020001', '960020002', '960010006'] as $qq) {
    if (str_contains(J($b), $qq)) { $qqleak[] = $qq; }
}
check('收件人接口里没有一个人的完整 QQ（只有脱敏抽样）', $qqleak === [], '泄漏：' . J($qqleak));
check('抽样最多三条', count($b['sample'] ?? []) <= 3, J($b['sample'] ?? []));
$samp = (string)($b['sample'][0] ?? '');
check('抽样是打了码的地址形（12****8@qq.com 这种）', (bool)preg_match('/^\d{1,2}\*+\d?@qq\.com$/', $samp), J($b['sample'] ?? []));

// ============================================================
// 5. 预览门禁
// ============================================================
echo "\n== 5. 正式发送前必须先预览这一份 ==\n";
$subject = '平台维护通知：本周六 20:00 停机两小时';
$body    = "各位站长与队友：\n\n本周六 20:00-22:00 停机升级，期间站点打不开。\n有问题在本队群里说。\nhttps://example.com/hall";
[$s, $j] = as_req('plat', 'POST', '/api/platform/admin/mail/blast', ['subject' => $subject, 'body' => $body, 'groups' => 'owner,member']);
check('没预览过就点正式发送：被拦且说清要预览', (int)($j['code'] ?? 0) !== 0 && str_contains((string)($j['msg'] ?? ''), '预览'), J($j));
[$s, $j] = as_req('plat', 'POST', '/api/platform/admin/mail/preview', ['subject' => '', 'body' => $body]);
check('标题空着被拒', (int)($j['code'] ?? 0) !== 0, J($j));
[$s, $j] = as_req('plat', 'POST', '/api/platform/admin/mail/preview', ['subject' => $subject, 'body' => "   \n  "]);
check('正文空着被拒', (int)($j['code'] ?? 0) !== 0, J($j));
[$s, $j] = as_req('plat', 'POST', '/api/platform/admin/mail/preview', ['subject' => str_repeat('长', 121), 'body' => 'x']);
check('标题超长被拒并报了 120 这个数', (int)($j['code'] ?? 0) !== 0 && str_contains((string)($j['msg'] ?? ''), '120'), J($j));

$mark = smtp_mark();
[$s, $j] = as_req('plat', 'POST', '/api/platform/admin/mail/preview', ['subject' => $subject, 'body' => $body]);
check('预览发出去了', (int)($j['code'] ?? 1) === 0, J($j));
check('预览回的是收件地址与格式判定', (string)($j['data']['to'] ?? '') === '999000111@qq.com'
    && (string)($j['data']['format'] ?? '') === '纯文本', J($j['data'] ?? []));
$mails = smtp_new($mark);
check('假服务器确实收到这一封预览（就一封）', count($mails) === 1, '收到 ' . count($mails));
$pv = $mails[0] ?? [];
check('预览的标题与写的一致', (string)($pv['subject'] ?? '') === $subject, J($pv));
check('预览正文带了那句「这是平台自己看的预览」', str_contains((string)($pv['body'] ?? ''), '这是平台自己看的预览'), mb_substr((string)($pv['body'] ?? ''), 0, 60));
check('预览只发给平台自己（没有顺手发给收件人）', (string)($pv['to'] ?? '') === '999000111@qq.com', J($pv));
check('预览正文里那行网址还在', str_contains((string)($pv['body'] ?? ''), 'https://example.com/hall'));
check('预览记了一行操作日志', (int)db_val("SELECT COUNT(*) FROM admin_logs WHERE tenant_id=0 AND action='邮件群发-预览'") >= 1);

[$s, $j] = as_req('plat', 'POST', '/api/platform/admin/mail/blast', ['subject' => $subject, 'body' => $body . "\n改一个字试试", 'groups' => 'owner,member']);
check('正文加一个字后建批被拒（预览作废）', (int)($j['code'] ?? 0) !== 0 && str_contains((string)($j['msg'] ?? ''), '预览'), J($j));
[$s, $j] = as_req('plat', 'POST', '/api/platform/admin/mail/blast', ['subject' => $subject . '（改了标题）', 'body' => $body, 'groups' => 'owner,member']);
check('标题改一个字后建批也被拒', (int)($j['code'] ?? 0) !== 0, J($j));
[$s, $j] = as_req('plat', 'POST', '/api/platform/admin/mail/blast', ['subject' => $subject, 'body' => $body, 'groups' => '']);
check('分组没勾就建不了批', (int)($j['code'] ?? 0) !== 0 && str_contains((string)($j['msg'] ?? ''), '分组'), J($j));

// ============================================================
// 6. 正文「混着来自动判」
// ============================================================
echo "\n== 6. 格式自动判：整篇像 HTML 才按 HTML 发 ==\n";
$mixed   = "本周六维护\n<b>停机两小时</b>\n地址 https://example.com";
$htmlDoc = "<html><body><h2>维护通知</h2><p>本周六 20:00 停机。</p><p>期间站点打不开。</p><p>有事群里说。</p></body></html>";
$htmlFew = "<p>一</p><p>二</p><p>三</p>";
$htmlFour = "<p>一</p><p>二</p><p>三</p><p>四</p>";
$unclosed = "<div><p>第一段\n<p>第二段<p>第三段<p>第四段";
$mark = smtp_mark();
foreach ([
    [$mixed, '纯文本', '一句话里带 <b> 的按纯文本发'],
    [$htmlDoc, 'HTML', '<html><body> 开头的按 HTML 发'],
    [$htmlFew, '纯文本', '块级标签只成对 3 组还不够（保守判）'],
    [$htmlFour, 'HTML', '块级标签成对到 4 组就按 HTML 发'],
    [$unclosed, '纯文本', '标签写到一半没闭合的按纯文本发（不发成一坨）'],
] as $t) {
    [$s, $j] = as_req('plat', 'POST', '/api/platform/admin/mail/preview', ['subject' => '判定：' . $t[2], 'body' => $t[0]]);
    check($t[2], (int)($j['code'] ?? 1) === 0 && (string)($j['data']['format'] ?? '') === $t[1],
        '回的是 ' . J($j['data'] ?? []));
}
$got = smtp_new($mark);
$bySubject = [];
foreach ($got as $g) { $bySubject[(string)$g['subject']] = $g; }
check('五份判定各收到一封（预览不会串）', count($got) === 5, '收到 ' . count($got));
check('判成纯文本的那封 Content-Type 是 text/plain',
    (string)($bySubject['判定：一句话里带 <b> 的按纯文本发']['content_type'] ?? '') === 'text/plain',
    J($bySubject['判定：一句话里带 <b> 的按纯文本发'] ?? []));
check('判成 HTML 的那封 Content-Type 是 text/html',
    (string)($bySubject['判定：<html><body> 开头的按 HTML 发']['content_type'] ?? '') === 'text/html',
    J($bySubject['判定：<html><body> 开头的按 HTML 发'] ?? []));
check('HTML 那封收到的正文里尖括号还在（收件人那边才有排版）',
    str_contains((string)($bySubject['判定：<html><body> 开头的按 HTML 发']['body'] ?? ''), '<h2>维护通知</h2>'));
$mixedGot = (string)($bySubject['判定：一句话里带 <b> 的按纯文本发']['body'] ?? '');
check('纯文本那封的换行原样保留（没塌成一行）', substr_count($mixedGot, "\n") >= 2, mb_substr($mixedGot, 0, 60));
check('纯文本那封里 <b> 就是字面几个字符（没被偷偷执行）', str_contains($mixedGot, '<b>'), mb_substr($mixedGot, 0, 60));

// ============================================================
// 7. 建批与限速队列
// ============================================================
echo "\n== 7. 建批 + 逐封限速 ==\n";
[$s, $j] = as_req('plat', 'POST', '/api/platform/admin/mail/preview', ['subject' => $subject, 'body' => $body]);
check('重新预览正式那一份（拿回门禁）', (int)($j['code'] ?? 1) === 0, J($j));
$small = $smtp; $small['max'] = 3;
[$s, $j] = as_req('plat', 'POST', '/api/platform/admin/mail/config', $small);
[$s, $j] = as_req('plat', 'POST', '/api/platform/admin/mail/blast', ['subject' => $subject, 'body' => $body, 'groups' => 'owner,member']);
check('7 人超过单批上限 3 被拦，且说清怎么调',
    (int)($j['code'] ?? 0) !== 0 && str_contains((string)($j['msg'] ?? ''), '上限'), J($j));
$big = $smtp; $big['max'] = 3000;
[$s, $j] = as_req('plat', 'POST', '/api/platform/admin/mail/config', $big);
[$s, $j] = as_req('plat', 'POST', '/api/platform/admin/mail/blast', ['subject' => $subject, 'body' => $body, 'groups' => 'owner,member']);
check('上限拦下来不吃门禁：调回 3000 后同一份还能建批', (int)($j['code'] ?? 1) === 0, J($j));
$bid = (int)($j['data']['blast_id'] ?? 0);
check('批次号拿到了', $bid > 0, J($j));
[$s, $j] = as_req('plat', 'POST', '/api/platform/admin/mail/blast', ['subject' => $subject, 'body' => $body, 'groups' => 'owner,member']);
check('同一份预览不能建第二个批（门禁是一次性的）', (int)($j['code'] ?? 0) !== 0 && str_contains((string)($j['msg'] ?? ''), '预览'), J($j));

$items = db_rows("SELECT uid, tenant_id, email, who, status, notice FROM mail_blast_items WHERE blast_id=$bid ORDER BY id");
check('建批就落好逐封明细：7 行', count($items) === 7, '实际 ' . count($items));
check('新批次是「待发送」', (string)db_val("SELECT status FROM mail_blasts WHERE id=$bid") === 'queued');
check('这一批的格式判定按纯文本存下了', (int)db_val("SELECT is_html FROM mail_blasts WHERE id=$bid") === 0);
check('分组按固定顺序落库', (string)db_val("SELECT recv_groups FROM mail_blasts WHERE id=$bid") === 'owner,member');
check('明细里 3 行标成站长/管理员', (int)db_val("SELECT COUNT(*) FROM mail_blast_items WHERE blast_id=$bid AND who='owner'") === 3);
check('没邮箱那个人也留了一行（email 就是空的）', (string)db_val("SELECT email FROM mail_blast_items WHERE blast_id=$bid AND uid=$a3") === '');
check('邮箱栏空着但登录名是 QQ 号时按 QQ号@qq.com 推出来',
    (string)db_val("SELECT email FROM mail_blast_items WHERE blast_id=$bid AND uid=$a2") === '960010004@qq.com');
check('封禁战队的两个人一行都没落',
    (int)db_val("SELECT COUNT(*) FROM mail_blast_items WHERE blast_id=$bid AND tenant_id=$cTid") === 0);
check('待审核的那个账号一行都没落', (int)db_val("SELECT COUNT(*) FROM mail_blast_items WHERE blast_id=$bid AND uid=" . $A['ids']['960010006']) === 0);

smtp_reject('960020002');
$mark = smtp_mark();
[$s, $j] = as_req('plat', 'POST', '/api/platform/admin/mail/step', ['blast_id' => $bid]);
$p1 = $j['data'] ?? [];
check('第一步发 2 封（每批封数按配置走）', (int)($p1['tried'] ?? 0) === 2, J($p1));
check('这一步之后还剩 5 封待发', (int)($p1['pending'] ?? -1) === 5 && (int)($p1['left'] ?? -1) === 5, J($p1));
check('批次转成「发送中」', (string)($p1['status'] ?? '') === 'running', J($p1));
check('假服务器这边确实只收到 2 封（没一口气发完）', count(smtp_new($mark)) === 2, '收到 ' . count(smtp_new($mark)));
check('开跑记了一行操作日志',
    (int)db_val("SELECT COUNT(*) FROM admin_logs WHERE tenant_id=0 AND action='邮件群发-开跑' AND detail LIKE '%#$bid%'") === 1);

[$s, $j] = as_req('plat', 'POST', '/api/platform/admin/mail/stop', ['blast_id' => $bid]);
check('暂停成功', (int)($j['code'] ?? 1) === 0, J($j));
[$s, $j] = as_req('plat', 'POST', '/api/platform/admin/mail/step', ['blast_id' => $bid]);
check('暂停中再点发送被拒（不会偷发）', str_contains((string)($j['data']['msg'] ?? ''), '暂停'), J($j['data'] ?? []));
check('暂停期间假服务器一封都没多', count(smtp_new($mark)) === 2, '收到 ' . count(smtp_new($mark)));
check('暂停时计划数还在（不是被抹掉了）', (int)($j['data']['target'] ?? 0) === 7, J($j['data'] ?? []));
[$s, $j] = as_req('plat', 'POST', '/api/platform/admin/mail/resume', ['blast_id' => $bid]);
check('继续成功', (int)($j['code'] ?? 1) === 0, J($j));

$pr = smtp_run_queue($bid);
check('按每批 2 封一路跑到发完', (string)($pr['status'] ?? '') === 'done', J($pr));
check('发完时没有 pending 也没有 sending', (int)($pr['pending'] ?? -1) === 0 && (int)($pr['sending'] ?? -1) === 0, J($pr));
// 7 个人里只有 5 封真的落到 DATA：1 封被收件名单在 RCPT 阶段退回（那种收信服务器不会开始收正文），1 人没地址压根没发
check('假服务器落日志的是 5 封（退回与没地址的两封不该出现在这里）', count(smtp_new($mark)) === 5, '收到 ' . count(smtp_new($mark)));
$nSent = (int)db_val("SELECT COUNT(*) FROM mail_blast_items WHERE blast_id=$bid AND status='sent'");
$nFail = (int)db_val("SELECT COUNT(*) FROM mail_blast_items WHERE blast_id=$bid AND status='failed'");
$nSkip = (int)db_val("SELECT COUNT(*) FROM mail_blast_items WHERE blast_id=$bid AND status='skipped'");
check('成功 5 封：服务器按名单退回 1 封、没地址跳过 1 封',
    $nSent === 5 && $nFail === 1 && $nSkip === 1, "sent=$nSent failed=$nFail skipped=$nSkip");
$fails = db_rows("SELECT email, status, err FROM mail_blast_items WHERE blast_id=$bid AND status='failed'");
check('退回那封如实记了服务器回的话（里面有 550）',
    isset($fails[0]) && str_contains((string)$fails[0]['err'], '550'), J($fails[0] ?? []));
check('退回那封记的地址就是被拒的那个', isset($fails[0]) && (string)$fails[0]['email'] === '960020002@qq.com', J($fails[0] ?? []));
check('失败原因里不带平台自己的授权码', !str_contains((string)($fails[0]['err'] ?? ''), 'authcode-abcdef'), J($fails[0] ?? []));
check('没地址那封记 skipped 且写了为什么',
    (int)db_val("SELECT COUNT(*) FROM mail_blast_items WHERE blast_id=$bid AND uid=$a3 AND status='skipped' AND err<>''") === 1);
check('跑完的批次写了 finished_at', db_val("SELECT finished_at FROM mail_blasts WHERE id=$bid") !== null);
check('跑完记了一行操作日志',
    (int)db_val("SELECT COUNT(*) FROM admin_logs WHERE tenant_id=0 AND action='邮件群发-跑完' AND detail LIKE '%#$bid%'") === 1);

// 站内信那一份
$notices = (int)db_val("SELECT COUNT(*) FROM mail_blast_items WHERE blast_id=$bid AND notice=1");
check('7 个人都补到了站内信（连没邮箱与被退回的也算）', $notices === 7, '实际 ' . $notices);
$fnRows = db_rows("SELECT to_user_id, dedupe, kind, post_id, note FROM forum_notices WHERE kind='platform' AND post_id=$bid");
check('站内信落在 forum_notices 且 dedupe 是 blast:批次:人',
    count($fnRows) === 7 && (string)$fnRows[0]['dedupe'] === 'blast:' . $bid . ':' . $fnRows[0]['to_user_id'], J($fnRows[0] ?? []));
check('摘要那句带着标题', str_contains((string)$fnRows[0]['note'], '平台维护通知'), mb_substr((string)$fnRows[0]['note'], 0, 120));
check('摘要不超过 200 字', mb_strlen((string)$fnRows[0]['note']) <= 200);
check('被退回那封的站内信也补了（邮箱无效的人照样看得到）',
    (int)db_val("SELECT notice FROM mail_blast_items WHERE blast_id=$bid AND uid=$b1") === 1);
check('没地址那封的站内信也补了', (int)db_val("SELECT notice FROM mail_blast_items WHERE blast_id=$bid AND uid=$a3") === 1);

$mark = smtp_mark();
[$s, $j] = as_req('plat', 'POST', '/api/platform/admin/mail/step', ['blast_id' => $bid]);
check('发完的批次再点不会被重发', count(smtp_new($mark)) === 0 && str_contains((string)($j['data']['msg'] ?? ''), '发完'), J($j['data'] ?? []));
check('再点也不会给同一个人补第二条站内信',
    (int)db_val("SELECT COUNT(*) FROM forum_notices WHERE kind='platform' AND post_id=$bid") === 7);
[$s, $j] = as_req('plat', 'POST', '/api/platform/admin/mail/stop', ['blast_id' => $bid]);
check('发完的批次不能再「暂停」', (int)($j['code'] ?? 0) !== 0, J($j));
[$s, $j] = as_req('plat', 'POST', '/api/platform/admin/mail/step', ['blast_id' => 999999]);
check('不存在的批次推不动', $s === 404 || (int)($j['code'] ?? 0) !== 0, 'http=' . $s . ' ' . J($j));

// ============================================================
// 9. 卡在「已认领」的死行
// ============================================================
echo "\n== 9. 死行重排（要人明确点）==\n";
$body2 = '这一批专门用来试卡在已认领的那一行';
[$s, $j] = as_req('plat', 'POST', '/api/platform/admin/mail/preview', ['subject' => '死行测试', 'body' => $body2]);
check('死行测试：预览通过', (int)($j['code'] ?? 1) === 0, J($j));
[$s, $j] = as_req('plat', 'POST', '/api/platform/admin/mail/blast', ['subject' => '死行测试', 'body' => $body2, 'groups' => 'owner']);
$bid2 = (int)($j['data']['blast_id'] ?? 0);
check('死行测试：建批成功（3 个站长/管理员）', $bid2 > 0 && (int)db_val("SELECT target FROM mail_blasts WHERE id=$bid2") === 3, 'bid=' . $bid2);
$pdo->exec("UPDATE mail_blast_items SET status='sending' WHERE blast_id=$bid2 AND uid=$a0");
$mark = smtp_mark();
[$s, $j] = as_req('plat', 'POST', '/api/platform/admin/mail/step', ['blast_id' => $bid2]);
$pr = $j['data'] ?? [];
check('死行不会被自动重发（这一步只发剩下的 2 封）', (int)($pr['tried'] ?? 0) === 2, J($pr));
check('那行还挂在「已认领」上，left 不为 0', (int)($pr['sending'] ?? 0) === 1 && (int)($pr['left'] ?? 0) === 1, J($pr));
check('因为有死行，批次还不算发完', (string)($pr['status'] ?? '') === 'running', J($pr));
[$s, $j] = as_req('plat', 'POST', '/api/platform/admin/mail/requeue', ['blast_id' => $bid2]);
check('点「重排」把那行退回待发', (int)($j['data']['moved'] ?? 0) === 1, J($j['data'] ?? []));
$mark = smtp_mark();
$pr = smtp_run_queue($bid2);
check('重排后这一步把它补发出去了', (int)($pr['sent'] ?? 0) === 3 && (string)($pr['status'] ?? '') === 'done', J($pr));
check('基线之后只多发这 1 封补发的（前面那 2 封没被重发）', count(smtp_new($mark)) === 1, '收到 ' . count(smtp_new($mark)));
check('同一个人的站内信还是每人一条（dedupe 挡住了第二条）',
    (int)db_val("SELECT COUNT(*) FROM forum_notices WHERE kind='platform' AND post_id=$bid2") === 3);
[$s, $j] = as_req('plat', 'POST', '/api/platform/admin/mail/requeue', ['blast_id' => $bid2]);
check('没有在飞的行时重排回 0 并说清楚', (int)($j['data']['moved'] ?? -1) === 0 && str_contains((string)($j['msg'] ?? ''), '没有在飞'), J($j));
check('重排记了一行操作日志',
    (int)db_val("SELECT COUNT(*) FROM admin_logs WHERE tenant_id=0 AND action='邮件群发-重排' AND detail LIKE '%#$bid2%'") >= 1);

// ============================================================
// 10. 发送记录与逐封明细
// ============================================================
echo "\n== 10. 发送记录与逐封明细 ==\n";
[$s, $j] = as_req('plat', 'GET', '/api/platform/admin/mail/blasts');
$ls = $j['data']['list'] ?? [];
check('记录里能看到这两批', count($ls) >= 2, '共 ' . count($ls));
$row1 = null;
foreach ($ls as $r) { if ((int)$r['id'] === $bid) { $row1 = $r; } }
check('批次行带着结果数与状态名', $row1 !== null && (int)$row1['n_sent'] === 5 && (string)$row1['statusName'] === '已发完', J($row1));
check('批次行写了谁点的', $row1 !== null && (string)$row1['actor'] === 'admin', J($row1 ?? []));
check('分组名给人看的说法', $row1 !== null && str_contains((string)$row1['groupNames'], '站长'), J($row1 ?? []));
check('记录列表不把整篇正文带出去（只给长度）',
    $row1 !== null && !str_contains(J($ls), '停机升级') && (int)$row1['body_len'] > 0, J($row1 ?? []));

[$s, $j] = as_req('plat', 'GET', '/api/platform/admin/mail/blast?blast_id=' . $bid . '&size=3');
$d = $j['data'] ?? [];
check('明细能翻页且总数对得上', count($d['list'] ?? []) === 3 && (int)($d['total'] ?? 0) === 7,
    'rows=' . count($d['list'] ?? []) . ' total=' . (int)($d['total'] ?? 0));
check('明细每行带队名与显示名', isset($d['list'][0]['team'], $d['list'][0]['name']), J($d['list'][0] ?? []));
check('明细里收件地址是完整的（这一页就是给平台核对地址用的）', str_contains(J($d['list'] ?? []), '@qq.com'), J($d['list'][0] ?? []));
check('批次详情里正文是原样一份（事后回看）', str_contains((string)($d['blast']['body'] ?? ''), '停机升级'), J($d['blast'] ?? []));
[$s, $j] = as_req('plat', 'GET', '/api/platform/admin/mail/blast?blast_id=' . $bid . '&page=3&size=3');
check('第三页只剩 1 行', count($j['data']['list'] ?? []) === 1, 'rows=' . count($j['data']['list'] ?? []));
[$s, $j] = as_req('plat', 'GET', '/api/platform/admin/mail/blast?blast_id=' . $bid . '&status=failed');
$fl = $j['data']['list'] ?? [];
check('按「失败」筛只剩那一封', count($fl) === 1 && (string)$fl[0]['status'] === 'failed', J($fl));
[$s, $j] = as_req('plat', 'GET', '/api/platform/admin/mail/blast?blast_id=' . $bid . '&status=;DROP');
check('状态乱填就当不筛（不报错、也不拼进 SQL）', (int)($j['data']['total'] ?? 0) === 7, J($j['data'] ?? []));
[$s, $j] = as_req('plat', 'GET', '/api/platform/admin/mail/blast?blast_id=999999');
check('不存在的批次回 404', $s === 404, 'http=' . $s);
$before = (int)db_val("SELECT COUNT(*) FROM admin_logs WHERE tenant_id=0 AND action='邮件群发-看明细'");
[$s, $j] = as_req('plat', 'GET', '/api/platform/admin/mail/blast?blast_id=' . $bid);
$after = (int)db_val("SELECT COUNT(*) FROM admin_logs WHERE tenant_id=0 AND action='邮件群发-看明细'");
check('每一次读明细都新记一行日志', $after === $before + 1, "before=$before after=$after");
check('日志里写着看的是哪一批第几页',
    (int)db_val("SELECT COUNT(*) FROM admin_logs WHERE tenant_id=0 AND action='邮件群发-看明细' AND detail LIKE '%#$bid%'") >= 1);

// ============================================================
// 11. 战队侧：平台通知进站内信
// ============================================================
echo "\n== 11. 成员在自己的站点里看平台通知 ==\n";
[$s, $j] = as_req('a1', 'GET', '/v5m/api/inbox');
$list = $j['data']['list'] ?? [];
$mine = null;
foreach ($list as $n) { if ((string)($n['kind'] ?? '') === 'platform') { $mine = $n; } }
check('成员的通知列表里出现「平台通知」', $mine !== null && (string)$mine['kindName'] === '平台通知', J($mine));
check('列表里带的是批次号（点开靠它回查）', $mine !== null && (int)$mine['blast'] === $bid, J($mine));
check('列表那句是「【标题】+ 正文开头」这一句（整篇要点开才回查）',
    $mine !== null && str_starts_with((string)$mine['note'], '【平台维护通知'), mb_substr((string)($mine['note'] ?? ''), 0, 120));
check('普通成员的未读红点数把平台通知算进去（这一批只有他 1 条）',
    count(array_filter($list, static fn($n) => (string)($n['kind'] ?? '') === 'platform')) === 1,
    'unread=' . (int)($j['data']['unread'] ?? 0));

[$s, $j] = as_req('a1', 'GET', '/v5m/api/inbox/blast?blast_id=' . $bid);
check('收件人本人读得到整篇正文', (int)($j['code'] ?? 1) === 0 && str_contains((string)($j['data']['body'] ?? ''), '停机升级'), J($j['data'] ?? []));
check('正文带着标题', (string)($j['data']['subject'] ?? '') === $subject, J($j['data'] ?? []));
check('回的是纯文本口径（没有 Content-Type 之类的外泄）', !str_contains(J($j['data'] ?? []), 'text/html'), J($j['data'] ?? []));
[$s, $j] = as_req('a1', 'GET', '/v5m/api/inbox/blast?blast_id=' . $bid2);
check('不是这一批的收件人就读不到（批次号猜对也没用）', (int)($j['code'] ?? 0) !== 0, J($j));
[$s, $j] = as_req('a1', 'GET', '/v5m/api/inbox/blast?blast_id=0');
check('批次号乱填被拒', (int)($j['code'] ?? 0) !== 0, J($j));
[$s, $j] = as_req('nobody', 'GET', '/v5m/api/inbox/blast?blast_id=' . $bid);
check('没登录读不到平台通知', $s === 401 || (int)($j['code'] ?? 0) !== 0, 'http=' . $s);
[$s, $j] = as_req('a3', 'GET', '/v5m/api/inbox/blast?blast_id=' . $bid);
check('没邮箱那个人照样读得到（站内信这一份就是给他的）', (int)($j['code'] ?? 1) === 0, J($j));
[$s, $j] = as_req('b1', 'GET', '/v5n/api/inbox/blast?blast_id=' . $bid);
check('被服务器退回的那个人也读得到', (int)($j['code'] ?? 1) === 0, J($j));

// HTML 那一批：邮件带标签，站内信只给可读文本
[$s, $j] = as_req('plat', 'POST', '/api/platform/admin/mail/preview', ['subject' => 'HTML 排版这一批', 'body' => $htmlDoc]);
check('HTML 正文预览判定为 HTML', (int)($j['code'] ?? 1) === 0 && (string)($j['data']['format'] ?? '') === 'HTML', J($j['data'] ?? []));
[$s, $j] = as_req('plat', 'POST', '/api/platform/admin/mail/blast', ['subject' => 'HTML 排版这一批', 'body' => $htmlDoc, 'groups' => 'owner']);
$bid3 = (int)($j['data']['blast_id'] ?? 0);
check('HTML 那一批建好了且 is_html=1', $bid3 > 0 && (int)db_val("SELECT is_html FROM mail_blasts WHERE id=$bid3") === 1, 'bid=' . $bid3);
$mark = smtp_mark();
$pr = smtp_run_queue($bid3);
check('HTML 那一批也发完了', (string)($pr['status'] ?? '') === 'done', J($pr));
$got = smtp_new($mark);
check('邮件里 HTML 标签原样带走', count($got) === 3 && str_contains((string)$got[0]['body'], '<h2>'), mb_substr((string)($got[0]['body'] ?? ''), 0, 60));
[$s, $j] = as_req('a0', 'GET', '/v5m/api/inbox/blast?blast_id=' . $bid3);
$bodyBack = (string)($j['data']['body'] ?? '');
check('站内信里回的是剥掉标签的可读文本', $bodyBack !== '' && !str_contains($bodyBack, '<h2>') && str_contains($bodyBack, '维护通知'), mb_substr($bodyBack, 0, 80));
check('剥标签后段落还在（不是挤成一行）', substr_count($bodyBack, "\n") >= 2, mb_substr($bodyBack, 0, 80));
check('批次里存的正文还是原样那份 HTML（站内信不改动原文）',
    str_contains((string)db_val("SELECT body FROM mail_blasts WHERE id=$bid3"), '<h2>维护通知</h2>'));

// 带脚本的正文：邮件原样（是平台自己写的），站内信这路一律剥成文本
$scripty = "<html><body><h2>通知</h2><p>正常一句</p><p>第二段</p><p>第三段</p><script>alert(1)</script></body></html>";
[$s, $j] = as_req('plat', 'POST', '/api/platform/admin/mail/preview', ['subject' => '带脚本的正文', 'body' => $scripty]);
check('带 <script> 的正文预览判定为 HTML 并发出', (int)($j['code'] ?? 1) === 0, J($j['data'] ?? []));
[$s, $j] = as_req('plat', 'POST', '/api/platform/admin/mail/blast', ['subject' => '带脚本的正文', 'body' => $scripty, 'groups' => 'owner']);
$bid4 = (int)($j['data']['blast_id'] ?? 0);
$pr = smtp_run_queue($bid4);
check('带脚本那一批也发完了', (string)($pr['status'] ?? '') === 'done', J($pr));
[$s, $j] = as_req('a0', 'GET', '/v5m/api/inbox/blast?blast_id=' . $bid4);
check('站内信这一路把 <script> 剥掉了（前端拿不到可执行标签）',
    (int)($j['code'] ?? 1) === 0 && !str_contains((string)($j['data']['body'] ?? ''), '<script'), mb_substr((string)($j['data']['body'] ?? ''), 0, 80));
check('剥完还在的是那句话', str_contains((string)($j['data']['body'] ?? ''), '正常一句'), J($j['data']['body'] ?? ''));

// ============================================================
// 12. 总开关与「这两张表只属于平台」
// ============================================================
echo "\n== 12. 总开关与隔离 ==\n";
$swSub = '总开关测试：这一封要停在关掉那一刻';
$swBody = "关掉之后队列一步都不该再走。\n第二段。";
[$s, $j] = as_req('plat', 'POST', '/api/platform/admin/mail/preview', ['subject' => $swSub, 'body' => $swBody]);
check('为总开关测试先预览这一份', (int)($j['code'] ?? 1) === 0, J($j));
[$s, $j] = as_req('plat', 'POST', '/api/platform/admin/mail/blast', ['subject' => $swSub, 'body' => $swBody, 'groups' => 'owner']);
$bidOff = (int)($j['data']['blast_id'] ?? 0);
check('为总开关测试建一批（建完先不点开始）', $bidOff > 0, J($j));
$beforeOff = smtp_mark();
$off = $smtp; $off['enabled'] = 0;
[$s, $j] = as_req('plat', 'POST', '/api/platform/admin/mail/config', $off);
check('关掉总开关', (int)($j['code'] ?? 1) === 0, J($j));
[$s, $j] = as_req('plat', 'POST', '/api/platform/admin/mail/preview', ['subject' => '关掉还想发', 'body' => '关了就别发']);
check('关掉后预览被拒并说清去哪打开', (int)($j['code'] ?? 0) !== 0 && str_contains((string)($j['msg'] ?? ''), '打开'), J($j));
[$s, $j] = as_req('plat', 'POST', '/api/platform/admin/mail/preview', ['subject' => '关掉还想发', 'body' => '关了就别发', 'groups' => 'owner']);
check('关掉后连预览都发不出去（假服务器一封都没收到）', smtp_mark() === $beforeOff, '见上一条');
[$s, $j] = as_req('plat', 'POST', '/api/platform/admin/mail/blast', ['subject' => '关掉还想建', 'body' => '关了就别建', 'groups' => 'owner']);
check('关掉后建批也被拒', $s === 403, 'http=' . $s . ' ' . J($j));
[$s, $j] = as_req('plat', 'POST', '/api/platform/admin/mail/resume', ['blast_id' => $bid]);
check('关掉后暂停中的批次也不能继续', $s === 403, 'http=' . $s);
[$s, $j] = as_req('plat', 'POST', '/api/platform/admin/mail/step', ['blast_id' => $bidOff]);
check('关掉后队列一步都不许推（不是只挡新建）', $s === 403 && (int)($j['code'] ?? 0) !== 0, 'http=' . $s . ' ' . J($j));
check('关掉后再推也没多发一封（假服务器收数没变）', smtp_mark() === $beforeOff, '见上一条');
[$s, $j] = as_req('plat', 'POST', '/api/platform/admin/mail/stop', ['blast_id' => $bidOff]);
check('关着的时候【暂停】照样点得动（急停不能被开关挡住）', (int)($j['code'] ?? 1) === 0, J($j));
[$s, $j] = as_req('plat', 'GET', '/api/platform/admin/mail/config');
check('关着的时候接口如实回 enabled=0', (int)($j['data']['enabled'] ?? 1) === 0, J($j['data'] ?? []));
[$s, $j] = as_req('plat', 'POST', '/api/platform/admin/mail/config', $smtp);
check('重新打开总开关', (int)($j['code'] ?? 1) === 0, J($j));
[$s, $j] = as_req('plat', 'POST', '/api/platform/admin/mail/resume', ['blast_id' => $bidOff]);
check('重新打开后这一批能从暂停里继续', (int)($j['code'] ?? 1) === 0, J($j));
$m1 = smtp_mark();
$pr = smtp_run_queue($bidOff, 30);
check('重新打开后队列走得动、并且真跑完了', (int)($pr['left'] ?? 1) === 0 && (string)($pr['status'] ?? '') === 'done', J($pr));
check('这一批只发了站长与管理员那 3 封（没顺手把成员也带上）',
    (int)($pr['sent'] ?? 0) === 3 && (int)($pr['skipped'] ?? 0) === 0 && smtp_new($m1) !== [] && count(smtp_new($m1)) === 3,
    'sent=' . ($pr['sent'] ?? '?') . ' 新收到=' . count(smtp_new($m1)));
check('这一批的站内信也是 3 条（一人一条不多不少）', (int)($pr['notices'] ?? 0) === 3, J($pr));

$srcAdmin = (string)file_get_contents($ROOT . '/app/controller/Admin.php');
$srcMembers = (string)file_get_contents($ROOT . '/app/controller/Members.php');
$srcDt = (string)file_get_contents($ROOT . '/app/service/DataTransfer.php');
$srcInsp = (string)file_get_contents($ROOT . '/app/controller/Inspect.php');
foreach (['Admin.php' => $srcAdmin, 'Members.php' => $srcMembers, 'DataTransfer.php' => $srcDt, 'Inspect.php' => $srcInsp] as $nm => $src) {
    check("{$nm} 里没有这两张群发表（战队侧接口/导出/巡查都碰不到）", !str_contains($src, 'mail_blast'), '');
}
$tables = array_column(db_rows("SELECT name FROM sqlite_master WHERE type='table'"), 'name');
check('库里确实建了这两张表（不是没跑就过去了）',
    in_array('mail_blasts', $tables, true) && in_array('mail_blast_items', $tables, true));
check('群发不发任何战队侧的 setting：平台键都以 p_mail_ 开头',
    count(db_rows("SELECT key FROM settings WHERE tenant_id=0 AND key LIKE 'p_mail%'")) >= 8);
check('p_mail_* 那几行都没落到某个战队名下',
    (int)db_val("SELECT COUNT(*) FROM settings WHERE tenant_id<>0 AND key LIKE 'p_mail%'") === 0);

// 老功能没被带坏
[$s, $j] = as_req('a0', 'GET', '/v5m/api/members');
check('A 队队内成员名单照常出', (int)($j['code'] ?? 1) === 0 && count($j['data']['list'] ?? []) >= 5, 'http=' . $s);
check('成员名单里一个邮箱都不带', !str_contains(J($j), '@qq.com'), mb_substr(J($j), 0, 160));
[$s, $j] = as_req('a0', 'GET', '/v5m/api/admin/users?page=1&size=50');
check('站长在自家用户管理里照旧看得到本队邮箱（这一版没动那块）', (int)($j['code'] ?? 1) === 0, 'http=' . $s);
[$s, $j] = as_req('a1', 'GET', '/v5m/api/inbox');
check('站内信接口搬到 /api/inbox 后照常（新那一档没把老几档挤掉）', (int)($j['code'] ?? 1) === 0, 'http=' . $s);

// ============================================================
// 13. 按收件人替换变量（v5.36.31）
// ============================================================
echo "\n== 13. 标题与正文里的 {team} {name} 这类变量 ==\n";
// 加一个昵称里带尖括号的账号：它会被换进别人的邮件里，必须收干净
$pdo2 = db();
$nickBad = '尖<b>括>号';
$pdo2->exec("INSERT INTO users (tenant_id, username, password, nickname, email, role, status, admin_perms, created_at, updated_at)
    VALUES ($aTid, '960010077', '$hash', '$nickBad', '960010077@qq.com', 'user', 'approved', '[]', '$now', '$now')");
$badUid = (int)$pdo2->lastInsertId();

[$s, $j] = as_req('plat', 'GET', '/api/platform/admin/mail/config');
$cvar = $j['data']['vars'] ?? [];
check('配置接口把变量表带给前端（七个）', count($cvar) === 7 && isset($cvar['{team}'], $cvar['{name}'], $cvar['{qq}'], $cvar['{role}'], $cvar['{date}'], $cvar['{site}'], $cvar['{slug}']), J($cvar));

$vSubject = '{name}：{team} 的{role}，{date} 生效';
$vBody = "你好 {name}，\n你所在战队：{team}（短码 {slug}）\n你的 QQ：{qq}\n站点：{site}\n身份：{role}　日期：{date}";
[$s, $j] = as_req('plat', 'POST', '/api/platform/admin/mail/render', ['subject' => $vSubject, 'body' => $vBody, 'groups' => 'owner,member']);
$sm = $j['data']['list'] ?? [];
check('渲染示例回两条（一站长一成员）', (int)($j['code'] ?? 1) === 0 && count($sm) === 2 && (string)$sm[0]['who'] === 'owner' && (string)$sm[1]['who'] === 'member', J($sm));
check('示例里 {team} 换成真队名、没有残留的花括号',
    $sm !== [] && str_contains((string)$sm[0]['subject'], '邮件群发A队') && !preg_match('/\{(team|name|qq|role|date|site|slug)\}/u', (string)$sm[0]['body']),
    mb_substr((string)($sm[0]['subject'] ?? ''), 0, 80));
check('示例带着站点地址与短码', $sm !== [] && str_contains((string)$sm[0]['body'], $BASE . '/v5m/') && str_contains((string)$sm[0]['body'], '短码 v5m'), mb_substr((string)($sm[0]['body'] ?? ''), 0, 160));
[$s, $j] = as_req('plat', 'POST', '/api/platform/admin/mail/render', ['subject' => '', 'body' => 'x']);
check('空标题被拦（这条也要过同一套形校验）', (int)($j['code'] ?? 0) !== 0, J($j));

$mark = smtp_mark();
[$s, $j] = as_req('plat', 'POST', '/api/platform/admin/mail/preview', ['subject' => $vSubject, 'body' => $vBody, 'groups' => 'owner,member']);
check('带变量的预览发得出去', (int)($j['code'] ?? 1) === 0, J($j['data'] ?? []));
$pv = smtp_new($mark);
check('预览那封末尾附了「换成这个人之后」的示例',
    count($pv) === 1 && str_contains((string)$pv[0]['body'], '这一段只有预览里有') && str_contains((string)$pv[0]['body'], '邮件群发A队'),
    mb_substr((string)($pv[0]['body'] ?? ''), 0, 120));
check('预览里主体还是运营写的那一份（示例只是附在末尾）',
    count($pv) === 1 && str_contains((string)$pv[0]['body'], '你好 {name}'), mb_substr((string)($pv[0]['body'] ?? ''), 0, 200));

[$s, $j] = as_req('plat', 'POST', '/api/platform/admin/mail/blast', ['subject' => $vSubject, 'body' => $vBody, 'groups' => 'owner,member']);
$bid5 = (int)($j['data']['blast_id'] ?? 0);
check('带变量的这一批建好了', $bid5 > 0, 'bid=' . $bid5 . ' ' . J($j));
check('批次里存的还是模板那一份（不落个性化正文）',
    (int)db_val("SELECT COUNT(*) FROM mail_blasts WHERE id=$bid5") === 1
    && str_contains((string)db_val("SELECT body FROM mail_blasts WHERE id=$bid5"), '你好 {name}')
    && str_contains((string)db_val("SELECT subject FROM mail_blasts WHERE id=$bid5"), '{team}'),
    (string)db_val("SELECT subject FROM mail_blasts WHERE id=$bid5"));

$mark = smtp_mark();
$pr = smtp_run_queue($bid5, 40);
check('带变量的这一批跑完了', (string)($pr['status'] ?? '') === 'done', J($pr));
$got5 = smtp_new($mark);
$byTo = [];
foreach ($got5 as $m) { $byTo[(string)$m['to']] = $m; }
check('站长那封的标题带着他自己的昵称与队名',
    isset($byTo['960010001@qq.com']) && str_contains((string)$byTo['960010001@qq.com']['subject'], 'v5m站长')
    && str_contains((string)$byTo['960010001@qq.com']['subject'], '邮件群发A队 的管理员'),
    (string)($byTo['960010001@qq.com']['subject'] ?? '（没收到）'));
check('成员那封的身份写的是成员（不是管理员）',
    isset($byTo['960010003@qq.com']) && str_contains((string)$byTo['960010003@qq.com']['subject'], 'v5m成员960010003')
    && str_contains((string)$byTo['960010003@qq.com']['body'], '身份：成员'),
    mb_substr((string)($byTo['960010003@qq.com']['body'] ?? ''), 0, 120));
check('B 队那两封换成 B 队自己的队名',
    isset($byTo['960020001@qq.com']) && str_contains((string)$byTo['960020001@qq.com']['subject'], '邮件群发B队'),
    (string)($byTo['960020001@qq.com']['subject'] ?? '（没收到）'));
check('QQ 号就是登录名那个号', isset($byTo['960010003@qq.com'])
    && str_contains((string)$byTo['960010003@qq.com']['body'], '你的 QQ：960010003'));
check('日期落的是今天', isset($byTo['960010003@qq.com'])
    && str_contains((string)$byTo['960010003@qq.com']['body'], '日期：' . date('Y-m-d')));
check('昵称里的尖括号被剥掉了（别人填的东西塞不进 HTML）',
    isset($byTo['960010077@qq.com']) && str_contains((string)$byTo['960010077@qq.com']['subject'], '尖b括号')
    && !str_contains((string)$byTo['960010077@qq.com']['subject'], '<b>'),
    (string)($byTo['960010077@qq.com']['subject'] ?? '（没收到）'));
$left = 0;
foreach ($byTo as $m) { if (preg_match('/\{(team|name|qq|role|date|site|slug)\}/u', (string)$m['subject'] . (string)$m['body'])) { $left++; } }
check('每一封都没有残留的花括号（该换的全换了）', $left === 0 && count($byTo) >= 6, '残留=' . $left . ' 收到=' . count($byTo));

[$s, $j] = as_req('a1', 'GET', '/v5m/api/inbox');
$note5 = '';
foreach (($j['data']['list'] ?? []) as $n) {
    if ((string)($n['kind'] ?? '') === 'platform' && (int)($n['blast'] ?? 0) === $bid5) { $note5 = (string)$n['note']; }
}
check('站内信那句也是拼好的（不是 {name} 原样）',
    $note5 !== '' && str_contains($note5, '邮件群发A队') && !str_contains($note5, '{team}'), mb_substr($note5, 0, 120));
[$s, $j] = as_req('a1', 'GET', '/v5m/api/inbox/blast?blast_id=' . $bid5);
$b5 = (string)($j['data']['body'] ?? '');
check('点开整篇正文同样按他自己渲染',
    str_contains($b5, '你所在战队：邮件群发A队') && !str_contains($b5, '{team}'), mb_substr($b5, 0, 120));
[$s, $j] = as_req('a3', 'GET', '/v5m/api/inbox');
$noteNo = '';
foreach (($j['data']['list'] ?? []) as $n) {
    if ((int)($n['blast'] ?? 0) === $bid5) { $noteNo = (string)$n['note']; }
}
check('登录名不是 QQ 号的那个人，{qq} 落成「未登记」而不是空的',
    $noteNo !== '' && str_contains($noteNo, '未登记'), mb_substr($noteNo, 0, 120));

// ============================================================
// 14. 销毁站点前的告知：显式带上被封站点（v5.36.32）
// ============================================================
echo "\n== 14. 带上被封站点这颗勾（销毁前的告知）==\n";
file_put_contents($SMTP_REJ, '');   // 上一轮留的退回名单不该影响这一轮的收信数

[$s, $j] = as_req('plat', 'POST', '/api/platform/admin/mail/recipients', ['groups' => 'owner']);
$o14 = $j['data'] ?? [];
[$s, $j] = as_req('plat', 'POST', '/api/platform/admin/mail/recipients', ['groups' => 'owner', 'include_banned' => '1']);
$o14b = $j['data'] ?? [];
check('不勾还是 3 人（被封站点默认一个都不算）', (int)($o14['total'] ?? 0) === 3, J($o14));
check('不勾时也如实报出「勾上会多出 1 人 1 支队」',
    (int)($o14['banned_people'] ?? 0) === 1 && (int)($o14['banned_teams'] ?? 0) === 1
    && (int)($o14['include_banned'] ?? 1) === 0, J($o14));
check('勾上之后多出 C 队站长那 1 人（4 人 3 支队）',
    (int)($o14b['total'] ?? 0) === 4 && (int)($o14b['owner'] ?? 0) === 4 && (int)($o14b['teams'] ?? 0) === 3
    && (int)($o14b['include_banned'] ?? 0) === 1, J($o14b));

[$s, $j] = as_req('plat', 'POST', '/api/platform/admin/mail/recipients', ['groups' => 'member']);
$m14 = $j['data'] ?? [];
[$s, $j] = as_req('plat', 'POST', '/api/platform/admin/mail/recipients', ['groups' => 'member', 'include_banned' => '1']);
$m14b = $j['data'] ?? [];
check('只勾成员时也说清「多出 2 人」（站长也是成员之一）', (int)($m14['banned_people'] ?? 0) === 2, J($m14));
check('这一组勾上后正好多出接口报的那 2 人',
    (int)($m14b['total'] ?? 0) === (int)($m14['total'] ?? 0) + (int)($m14['banned_people'] ?? -1),
    (int)($m14['total'] ?? 0) . ' → ' . (int)($m14b['total'] ?? 0));

[$s, $j] = as_req('plat', 'POST', '/api/platform/admin/mail/recipients', ['groups' => 'owner,member']);
$base14 = $j['data'] ?? [];
[$s, $j] = as_req('plat', 'POST', '/api/platform/admin/mail/recipients', ['groups' => 'owner,member', 'include_banned' => '1']);
$b14 = $j['data'] ?? [];
$baseTotal = (int)($base14['total'] ?? 0);
$withTotal = (int)($b14['total'] ?? 0);
check('两组一起勾：多出来的人正好等于「被封站点里的人数」，且那 2 人就是 C 队 1 支',
    $baseTotal > 0 && $withTotal === $baseTotal + (int)($b14['banned_people'] ?? -1)
    && (int)($b14['banned_people'] ?? 0) === 2 && (int)($b14['banned_teams'] ?? 0) === 1,
    J($base14) . ' → ' . J($b14));
check('勾上后站长那一组也多出被封站点的站长（不勾时他是被整队滤掉的）',
    (int)($b14['owner'] ?? 0) === (int)($base14['owner'] ?? 0) + 1,
    ($base14['owner'] ?? '?') . ' → ' . ($b14['owner'] ?? '?'));
check('被封站点那两个账号没在名单里露出来（只给数字与脱敏抽样）',
    !str_contains(J($b14), '960030001') && !str_contains(J($b14), '960030002'), J($b14['sample'] ?? []));

foreach (['yes', '0', '', 'true', '2'] as $bad) {
    [$s, $j] = as_req('plat', 'POST', '/api/platform/admin/mail/recipients', ['groups' => 'owner', 'include_banned' => $bad]);
    check('include_banned 传「' . ($bad === '' ? '空串' : $bad) . '」不算勾（还是 3 人）',
        (int)($j['data']['total'] ?? 0) === 3 && (int)($j['data']['include_banned'] ?? 1) === 0, J($j['data'] ?? []));
}
[$s, $j] = as_req('a0', 'POST', '/api/platform/admin/mail/recipients', ['groups' => 'owner', 'include_banned' => '1']);
check('战队站长勾不动这颗（他连这一页都进不来）', $s === 403, 'http=' . $s);

$gSub = '站点即将注销：请在时限内导出自己的数据';
$gBody = "{team} 的各位：\n\n本站已被平台封禁，满 15 天后将被注销并删除全部数据（含图片）。\n要留的东西尽快导出：{site}\n（{date}）";
$mark14 = smtp_mark();
[$s, $j] = as_req('plat', 'POST', '/api/platform/admin/mail/preview',
    ['subject' => $gSub, 'body' => $gBody, 'groups' => 'owner,member', 'include_banned' => '1']);
check('告知这一份先预览（还是发给平台自己那个邮箱）', (int)($j['code'] ?? 1) === 0, J($j));
[$s, $j] = as_req('plat', 'POST', '/api/platform/admin/mail/blast',
    ['subject' => $gSub, 'body' => $gBody, 'groups' => 'owner,member', 'include_banned' => '1']);
$bid14 = (int)($j['data']['blast_id'] ?? 0);
check('带上被封站点建批成功', $bid14 > 0, J($j));
check('明细落好的行数就是统计报的那个数，C 队那两个人各一行',
    (int)db_val("SELECT COUNT(*) FROM mail_blast_items WHERE blast_id=$bid14") === $withTotal
    && (int)db_val("SELECT COUNT(*) FROM mail_blast_items WHERE blast_id=$bid14 AND tenant_id=$cTid") === 2,
    '接口=' . $withTotal
    . ' 实际=' . db_val("SELECT COUNT(*) FROM mail_blast_items WHERE blast_id=$bid14")
    . ' C队=' . db_val("SELECT COUNT(*) FROM mail_blast_items WHERE blast_id=$bid14 AND tenant_id=$cTid"));
$rg14 = (string)db_val("SELECT recv_groups FROM mail_blasts WHERE id=$bid14");
check('这一批的分组串里留了记号（事后看得出是销毁前告知）',
    $rg14 === 'owner,member' . '|include_banned', J($rg14));
check('记号不会被当成分组读（分组还是那两组）',
    (string)db_val("SELECT target FROM mail_blasts WHERE id=$bid14") === (string)$withTotal, 'target+总数对上');
check('建批那一行操作日志写清了被封站点 1 支 2 人',
    (int)db_val("SELECT COUNT(*) FROM admin_logs WHERE tenant_id=0 AND action='邮件群发-建批' AND detail LIKE '%被封站点 1 支 2 人%' AND detail LIKE '%#$bid14%'") === 1);

[$s, $j] = as_req('plat', 'GET', '/api/platform/admin/mail/blasts');
$one14 = null;
foreach (($j['data']['list'] ?? []) as $r14) {
    if ((int)($r14['id'] ?? 0) === $bid14) { $one14 = $r14; }
}
check('发送记录里这一批标出「含被封站点」，分组名照旧两句、不带记号',
    is_array($one14) && (int)($one14['include_banned'] ?? 0) === 1 && (string)$one14['groups'] === 'owner,member'
    && (string)$one14['groupNames'] === '各战队的站长与管理员 + 全部已上线成员', J($one14 ?? []));

$pr14 = smtp_run_queue($bid14, 40);
check('告知这一批一路跑到发完', (string)($pr14['status'] ?? '') === 'done', J($pr14));
$by14 = [];
foreach (smtp_new($mark14) as $m) { $by14[(string)$m['to']] = $m; }
check('C 队站长与成员各收到一封（被封站点这回收到了）',
    isset($by14['960030001@qq.com']) && isset($by14['960030002@qq.com']), '收到 ' . count($by14) . ' 封');
check('那两封里的 {team} 换成的是 C 队自己的队名',
    isset($by14['960030001@qq.com']) && str_contains((string)$by14['960030001@qq.com']['subject'], '站点即将注销')
    && str_contains((string)$by14['960030002@qq.com']['body'], '邮件群发C队'),
    mb_substr((string)($by14['960030002@qq.com']['body'] ?? '（没收到）'), 0, 80));
check('被封站点那两封也各补了一条站内信（人不在站点里，信先记着）',
    (int)db_val("SELECT COUNT(*) FROM mail_blast_items WHERE blast_id=$bid14 AND tenant_id=$cTid AND notice=1") === 2);

[$s, $j] = as_req('plat', 'POST', '/api/platform/admin/mail/preview', ['subject' => $gSub, 'body' => $gBody, 'groups' => 'owner,member']);
check('同一份不勾那颗再预览一次', (int)($j['code'] ?? 1) === 0, J($j));
[$s, $j] = as_req('plat', 'POST', '/api/platform/admin/mail/blast', ['subject' => $gSub, 'body' => $gBody, 'groups' => 'owner,member']);
$bid14c = (int)($j['data']['blast_id'] ?? 0);
check('不勾的那一批行数回到不勾时的数、C 队一行都没有（这颗勾不粘在下一次）',
    $bid14c > 0 && (int)db_val("SELECT COUNT(*) FROM mail_blast_items WHERE blast_id=$bid14c") === $baseTotal
    && $baseTotal === $withTotal - 2
    && (int)db_val("SELECT COUNT(*) FROM mail_blast_items WHERE blast_id=$bid14c AND tenant_id=$cTid") === 0,
    'bid=' . $bid14c . ' 行数=' . db_val("SELECT COUNT(*) FROM mail_blast_items WHERE blast_id=$bid14c") . ' 基线=' . $baseTotal);
check('不勾的那一批分组串里没有记号',
    (string)db_val("SELECT recv_groups FROM mail_blasts WHERE id=$bid14c") === 'owner,member');

// smtp_stop 之后不再有发送

smtp_stop();
echo "\n== 收尾 ==\n";
echo '  批次 1#' . $bid . '：计划 ' . (int)db_val("SELECT target FROM mail_blasts WHERE id=$bid")
    . ' 封，成功 ' . $nSent . '，退回 ' . $nFail . '，没地址 ' . $nSkip . '，站内信 ' . $notices . " 条\n";
echo '  批次 2#' . $bid2 . '（死行重排）、3#' . $bid3 . '（HTML）、4#' . $bid4 . "（带脚本）各跑了一遍\n";
echo '  批次 5#' . $bid14 . '（带上被封站点的销毁前告知）跑了一遍，6#' . $bid14c
    . "（同一份不勾那颗）只建了批没发\n";
// 退回名单清空留下一次用；日志留给下一次（基线会跳过旧内容），只把这一轮的名单擦掉
file_put_contents($SMTP_REJ, '');

echo "\n通过 {$pass} 项，失败 {$fail} 项\n";
if ($fail > 0) {
    echo "失败清单：\n";
    foreach ($failures as $f) { echo "  - {$f}\n"; }
}
exit($fail > 0 ? 1 : 0);
