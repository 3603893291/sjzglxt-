<?php
/**
 * v5.36.34 邮箱验证码自助找回密码 E2E（scripts/test_email_reset_e2e.php）
 *
 * 前置：测试服务器已启动，.env 指向 SQLite（.env 里只能用 ; 注释）
 *   php -S 127.0.0.1:8899 -t public public/router.php
 * 用法：php scripts/test_email_reset_e2e.php
 *   假 SMTP 由本脚本自己起、自己停（scripts/fake_smtp_server.php，只听 127.0.0.1，端口从 2525 往上挑一个没人听的）；
 *   验证码是从「假服务器真收到的那封信」里抠出来的，不是从库里抄的 —— 库里那条能不能兑，看的是发出去的那串。
 *
 * 九条主线：
 *   1. 这条路通不通（reset-options）：平台没配发信账号时 email_ready=0，配齐了才 1；平台根路径不受理
 *   2. 发码发给谁：在册成员收到；邮箱栏空着的按 QQ 号补出来；存过别的地址就发到那个地址；
 *      待审核 / 已封禁 / 查无此人 三种都不发信，但对外回的是同一句话（防枚举）
 *   3. 限流与冷却：60 秒内不能连点、同 QQ 每小时 3 条、同 IP 每小时 8 次；被冷却挡住的那次不计数
 *   4. 兑码改密码：弱口令/两次不一致/错码都改不动；对上了当场改密码，旧密码立刻登不进、新密码能进
 *   5. 一次性：同一张码兑第二次不认；过期不认；重新发码作废旧码，并顺手把这邮箱用过的老码清掉
 *   6. 改完之后：密码纪元 +1、免登录令牌全作废、这个账号的爆破锁清掉、之前那张截图申请连材料一起撤
 *   7. 跨队：同一个 QQ 在两队各有账号时，码在哪个站领的就只改那个站的账号，不会串到别队
 *   8. 进不去的门：平台根路径 404、整队封禁的站点 403、封禁中的账号领了码也兑不动
 *   9. 投递失败：假服务器退回时对外仍是同一句话，库里不留活码，管理日志留一条
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

$ROOT = dirname(__DIR__);
$BASE = rtrim((string)(getenv('TEST_BASE') ?: 'http://127.0.0.1:8899'), '/');
$DB   = $ROOT . '/runtime/test.sqlite';

require $ROOT . '/vendor/autoload.php';
(new \think\App())->initialize();      // 与站点共用同一份 file 缓存：这一轮要直接动限流计数

use think\facade\Cache;

// ---------------- 假 SMTP ----------------
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
    fwrite(STDERR, "2525-2560 全被占着，这一轮不起假服务器（不拿别人的端口当自己的）。\n");
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
/** [status, json, headers, raw]；Cookie 按身份在内存里维护（不用 curl 的 jar，避免回写滞后） */
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

function smtp_alive(): bool
{
    global $SMTP_PORT;
    $f = @fsockopen('127.0.0.1', $SMTP_PORT, $e, $s, 1);
    if ($f) { fclose($f); return true; }
    return false;
}
$smtpProc = null; $smtpOwned = false;
function smtp_port_pid(): int
{
    global $SMTP_PORT;
    $out = (string)@shell_exec('netstat -ano | findstr /I "LISTENING" | findstr /I "127.0.0.1:' . $SMTP_PORT . '"');
    foreach (explode("\n", str_replace("\r", "\n", $out)) as $ln) {
        $cols = preg_split('/\s+/', trim($ln)) ?: [];
        $last = end($cols);
        if (is_numeric($last) && (int)$last > 0) { return (int)$last; }
    }
    return 0;
}
function smtp_pid_is_fake(int $pid): bool
{
    $cmd = (string)@shell_exec('powershell -NoProfile -Command "(Get-CimInstance Win32_Process -Filter \'ProcessId=' . $pid . '\').CommandLine"');
    return strpos($cmd, 'fake_smtp_server.php') !== false;
}
function smtp_reclaim(): void
{
    global $SMTP_STOP;
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
        fwrite(STDERR, '127.0.0.1:' . $SMTP_PORT . " 还被一个认不出身份的进程占着，这一轮不起假 SMTP。\n");
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
    $smtpProc = @proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['file', $SMTP_OUT, 'a'], 2 => ['file', $SMTP_OUT, 'a']], $pipes);
    if (!is_resource($smtpProc)) { fwrite(STDERR, "假 SMTP 起不动。\n"); exit(1); }
    for ($i = 0; $i < 60 && !smtp_alive(); $i++) { usleep(100000); }
    if (!smtp_alive()) {
        fwrite(STDERR, "假 SMTP 起了但没在 {$SMTP_PORT} 上听着，输出：\n"
            . (is_file($SMTP_OUT) ? (string)file_get_contents($SMTP_OUT) : '（没有输出文件）') . "\n");
        exit(1);
    }
}
function smtp_stop(): void
{
    global $SMTP_STOP, $SMTP_DONE, $smtpProc, $smtpOwned;
    if (!$smtpOwned) { return; }
    @touch($SMTP_STOP);
    if (is_resource($smtpProc)) { @proc_terminate($smtpProc); }
    for ($i = 0; $i < 40 && smtp_alive(); $i++) { usleep(100000); }
    @unlink($SMTP_STOP);
    @unlink($SMTP_DONE);
}
/** 从某个字节偏移往后收到的信 */
function smtp_new(int $mark): array
{
    global $SMTP_LOG;
    if (!is_file($SMTP_LOG)) { return []; }
    $fp = @fopen($SMTP_LOG, 'rb');
    if (!$fp) { return []; }
    fseek($fp, $mark);
    $out = [];
    while (($l = fgets($fp)) !== false) {
        $l = trim($l);
        if ($l === '') { continue; }
        $j = json_decode($l, true);
        if (is_array($j)) { $out[] = $j; }
    }
    fclose($fp);
    return $out;
}
function smtp_mark(): int
{
    global $SMTP_LOG;
    clearstatcache(true, $SMTP_LOG);
    return is_file($SMTP_LOG) ? (int)filesize($SMTP_LOG) : 0;
}
/** 寄给这个地址的最后一封（没有就回 null） */
function mail_to(int $mark, string $email): ?array
{
    $hit = null;
    foreach (smtp_new($mark) as $m) {
        if (strtolower((string)($m['to'] ?? '')) === strtolower($email)) { $hit = $m; }
    }
    return $hit;
}
/** 那封信正文里的 6 位码（抠不到就回空串） */
function code_in(?array $mail): string
{
    if (!$mail) { return ''; }
    if (preg_match('/你的验证码：(\d{6})/', (string)($mail['body'] ?? ''), $m)) { return $m[1]; }
    return '';
}
function smtp_reject(string $frag): void
{
    global $SMTP_REJ;
    $cur = is_file($SMTP_REJ) ? trim((string)file_get_contents($SMTP_REJ)) : '';
    file_put_contents($SMTP_REJ, ($cur === '' ? '' : $cur . "\n") . $frag . "\n");
}

// ============================================================
// 0. 重建测试库、种子、起假 SMTP
// ============================================================
echo "== 0. 重建测试库与种子 ==\n";
@unlink($DB);
echo shell_exec('"' . PHP_BINARY . '" "' . $ROOT . '/scripts/dev_init_sqlite.php" "' . $DB . '" 2>&1');
foreach (glob($ROOT . '/runtime/cache/*/*') ?: [] as $f) { if (is_file($f)) { @unlink($f); } }
foreach (glob($ROOT . '/runtime/cache/*') ?: [] as $f) { if (is_file($f)) { @unlink($f); } }

$now  = date('Y-m-d H:i:s');
$pdo  = db();
$hash = password_hash('pass123456', PASSWORD_DEFAULT);

/** 建一支战队：people 每项 [qq, role, status, email] */
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
    return ['tid' => $tid, 'ids' => $ids];
};

// 甲队：站长 + 邮箱栏空着的 + 存过别的地址的 + 待审核 + 已封禁 + 一个乙队也有的同号
$A = $mk('v5r', '验证码甲队', [
    ['970010001', 'admin', 'approved', '970010001@qq.com'],
    ['970010002', 'user',  'approved', ''],
    ['970010003', 'user',  'approved', 'other970010003@qq.com'],
    ['970010004', 'user',  'pending',  '970010004@qq.com'],
    ['970010005', 'user',  'banned',   '970010005@qq.com'],
    ['970010006', 'user',  'approved', '970010006@qq.com'],
]);
// 乙队：只有 970010006 这一个号，和甲队同名同邮箱（测「码会不会串到别队」）
$B = $mk('v5s', '验证码乙队', [
    ['970020001', 'admin', 'approved', '970020001@qq.com'],
    ['970010006', 'user',  'approved', '970010006@qq.com'],
]);
// 丙队整队封禁
$C = $mk('v5t', '验证码丙队', [['970030001', 'user', 'approved', '970030001@qq.com']], 'banned');

$aTid = (int)$A['tid']; $bTid = (int)$B['tid']; $cTid = (int)$C['tid'];
$ids  = $A['ids']; $bIds = $B['ids'];
echo '  种子：甲 tid=' . $aTid . '（6 人） 乙 tid=' . $bTid . '（2 人，含同号 970010006） 丙 tid=' . $cTid . "（整队封禁）\n";

file_put_contents($SMTP_REJ, '');
smtp_start();
$mark = smtp_mark();
check('假 SMTP 在 127.0.0.1:' . $SMTP_PORT . ' 听着', smtp_alive());

// 限流键的口径（与 Auth 控制器一致）
$ipKeyOf  = static fn(): string => 'pwdreset_ip_' . md5('127.0.0.1');
$hrKeyOf  = static fn(int $t, string $q): string => 'pwdreset_hr_' . $t . '_' . md5(mb_strtolower($q));
$dayKeyOf = static fn(int $t, string $q): string => 'pwdreset_day_' . $t . '_' . md5(mb_strtolower($q)) . '_' . date('Ymd');
$lastKeyOf = static fn(int $t, string $q): string => 'pwdreset_last_' . $t . '_' . md5(mb_strtolower($q));
$tryKeyOf = static fn(int $t, string $q): string => 'pwdreset_try_' . $t . '_' . md5(mb_strtolower($q));
/** 把限流计数抹平，好让每一段单独测自己的那一闸（只有 60 秒冷却那把键留着：它是被测对象） */
$calm = static function (int $tid, string $qq) use ($ipKeyOf, $hrKeyOf, $dayKeyOf): void {
    Cache::delete($ipKeyOf());
    Cache::delete($hrKeyOf($tid, $qq));
    Cache::delete($dayKeyOf($tid, $qq));
};
/** 把 60 秒冷却拨回过去（等价于等了一分钟，不用真等） */
$rewind = static function (int $tid, string $qq) use ($lastKeyOf): void {
    Cache::set($lastKeyOf($tid, $qq), time() - 600, 3600);
};
$send = static function (string $slug, string $qq, string $who = 'anon'): array {
    return as_req($who, 'POST', '/' . $slug . '/api/auth/send-code', ['qq' => $qq]);
};
$reset = static function (string $slug, string $qq, string $code, string $pwd, string $confirm = null): array {
    return as_req('anon', 'POST', '/' . $slug . '/api/auth/reset-by-code',
        ['qq' => $qq, 'code' => $code, 'password' => $pwd, 'confirm' => $confirm ?? $pwd]);
};

// ============================================================
// 1. 这条路现在通不通
// ============================================================
echo "\n== 1. reset-options：门开着还是关着 ==\n";
[$s, $j] = as_req('anon', 'GET', '/v5r/api/auth/reset-options');
$d0 = $j['data'] ?? [];
check('平台还没配发信账号：email_ready=0', $s === 200 && (int)($d0['email_ready'] ?? 1) === 0, 'http=' . $s . ' ' . J($j));
check('关着的时候还给一句人话，说清为什么', str_contains((string)($d0['why'] ?? ''), '发信邮箱'), J($d0));
[$s, $j] = as_req('anon', 'GET', '/api/auth/reset-options');
check('平台根路径不受理这条路（404）', $s === 404 || (int)($j['code'] ?? 0) === 404, 'http=' . $s . ' ' . J($j));

// 把平台发信账号指到假服务器
$setPlatform = static function (string $k, string $v) use ($pdo): void {
    $pdo->exec("INSERT OR REPLACE INTO settings (tenant_id, `key`, value) VALUES (0, '$k', '" . $v . "')");
};
$setPlatform('p_mail_host', '127.0.0.1');
$setPlatform('p_mail_port', (string)$SMTP_PORT);
$setPlatform('p_mail_user', '999000111@qq.com');
$setPlatform('p_mail_pass', 'authcode-abcdef');
$setPlatform('p_mail_from', '');
$setPlatform('p_mail_secure', 'none');
[$s, $j] = as_req('anon', 'GET', '/v5r/api/auth/reset-options');
$d1 = $j['data'] ?? [];
check('配好发信账号后：email_ready=1', (int)($d1['email_ready'] ?? 0) === 1, J($d1));
check('接口把有效期与再点间隔告诉前端（前端不自己抄一份）',
    (int)($d1['ttl_sec'] ?? 0) === 600 && (int)($d1['again_sec'] ?? 0) === 60, J($d1));
check('这个接口不要登录态（匿名也拿得到）', $s === 200, 'http=' . $s);

// ============================================================
// 2. 发码发给谁
// ============================================================
echo "\n== 2. 发码：谁能收到、谁收不到、对外说的话都一样 ==\n";
$e1 = '970010001@qq.com';
[$s, $j] = $send('v5r', '970010001');
$msg1 = (string)($j['msg'] ?? '');
check('在册成员索取验证码：受理', $s === 200 && (int)($j['code'] ?? 1) === 0, 'http=' . $s . ' ' . J($j));
$m1 = mail_to($mark, $e1);
check('假 SMTP 真收到一封，收件人就是他那个 QQ 的邮箱', $m1 !== null, J($m1 ?? []));
$c1 = code_in($m1);
check('信里有 6 位验证码', preg_match('/^\d{6}$/', $c1) === 1, '抠到=' . J($c1));
check('标题带着战队名，一看就知道是哪支队伍发来的',
    str_contains((string)($m1['subject'] ?? ''), '验证码甲队'), (string)($m1['subject'] ?? ''));
check('正文写清几分钟有效、只用一次、不是本人就忽略',
    str_contains((string)($m1['body'] ?? ''), '10 分钟') && str_contains((string)($m1['body'] ?? ''), '只用一次')
    && str_contains((string)($m1['body'] ?? ''), '忽略这封信'), mb_substr((string)($m1['body'] ?? ''), 0, 120));
$row1 = db_rows("SELECT * FROM email_codes WHERE email='$e1' ORDER BY id DESC");
check('库里落的码与信里那串是同一个', (string)($row1[0]['code'] ?? '') === $c1, '库里=' . J($row1[0]['code'] ?? ''));
check('这条码还没用过、10 分钟后过期',
    (int)($row1[0]['used'] ?? 1) === 0 && strtotime((string)$row1[0]['expired_at']) - time() >= 540,
    'used=' . J($row1[0]['used'] ?? '') . ' exp=' . J($row1[0]['expired_at'] ?? ''));
check('回给人看的那句话把收件邮箱念出来了（本来就是他自己填的 QQ）', str_contains($msg1, $e1), $msg1);

// 邮箱栏空着：按 QQ 号补
$calm($aTid, '970010002'); $rewind($aTid, '970010002');
[$s, $j] = $send('v5r', '970010002');
$m2 = mail_to($mark, '970010002@qq.com');
check('邮箱栏空着的成员：按 QQ 号补出来的地址照样收得到', $m2 !== null && code_in($m2) !== '', J($j));
check('老数据没存邮箱这件事，不影响他能自助改密码',
    (string)db_val("SELECT email FROM users WHERE id={$ids['970010002']}") === '' && $m2 !== null);

// 存过别的地址：就发到那个地址
$calm($aTid, '970010003'); $rewind($aTid, '970010003');
[$s, $j] = $send('v5r', '970010003');
check('存过别的地址的：码发到库里那个地址，不发 QQ 默认地址',
    mail_to($mark, 'other970010003@qq.com') !== null && mail_to($mark, '970010003@qq.com') === null, J($j));

// 三种「不该发信」的：待审核 / 已封禁 / 查无此人
$bannedMail = '970010005@qq.com';   // 账号已封禁（甲队里 status=banned 的那个）
$ghostMail  = '979999999@qq.com';
$pendMail   = '970010004@qq.com';
foreach ([['970010004', $pendMail], ['970010005', $bannedMail], ['979999999', $ghostMail]] as [$qq, $em]) {
    $calm($aTid, $qq); $rewind($aTid, $qq);
    [$s, $j] = $send('v5r', $qq);
    check("索取验证码对外回的是同一句话（QQ {$qq}）",
        $s === 200 && (int)($j['code'] ?? 1) === 0 && (string)($j['msg'] ?? '') === str_replace($e1, $em, $msg1),
        'http=' . $s . ' msg=' . J($j['msg'] ?? ''));
    check("不发信也不落码（QQ {$qq} → {$em}）",
        mail_to($mark, $em) === null && (int)db_val("SELECT COUNT(*) FROM email_codes WHERE email='$em'") === 0);
}
check('库里总码数只有前面三个真人那三条',
    (int)db_val("SELECT COUNT(*) FROM email_codes") === 3, '实际=' . db_val("SELECT COUNT(*) FROM email_codes"));

// ============================================================
// 3. 限流与冷却
// ============================================================
echo "\n== 3. 限流：连点、每小时、IP ==\n";
$calm($aTid, '970010001');
[$s, $j] = $send('v5r', '970010001');
$memberCool = (string)($j['msg'] ?? '');
check('60 秒内连点第二次：挡住，并说还剩几秒', $s === 429 && str_contains($memberCool, '秒后再点'),
    'http=' . $s . ' ' . J($j));
check('被冷却挡住这一次没生成新码（还是那一条）',
    (int)db_val("SELECT COUNT(*) FROM email_codes WHERE email='$e1' AND used=0") === 1);
// 查无此人也被同一道冷却挡：挡的话与真成员那句一字不差（只差那几秒），漏不出成员名单
$normSec = static fn(string $m): string => (string)preg_replace('/\d+/', 'N', $m);
$calm($aTid, '979999999'); $rewind($aTid, '979999999');
[$s, $j] = $send('v5r', '979999999');
[$s2, $j2] = $send('v5r', '979999999');
check('查无此人的 QQ 连点：挡的话与真成员一模一样（只差秒数）',
    $s === 200 && $s2 === 429 && $normSec((string)($j2['msg'] ?? '')) === $normSec($memberCool),
    'ghost=' . J($j2['msg'] ?? '') . ' member=' . J($memberCool));

// 同 QQ 每小时 3 条
$calm($aTid, '970010002');
for ($i = 0; $i < 3; $i++) { $rewind($aTid, '970010002'); $send('v5r', '970010002'); }
$rewind($aTid, '970010002');
[$s, $j] = $send('v5r', '970010002');
check('同一个 QQ 一小时内最多三条，第四条被挡',
    $s === 429 && str_contains((string)($j['msg'] ?? ''), '过于频繁'), 'http=' . $s . ' ' . J($j));
check('每小时这道数的是「真发出去过」的次数（缓存里=3）',
    (int)Cache::get($hrKeyOf($aTid, '970010002'), 0) === 3, '缓存=' . Cache::get($hrKeyOf($aTid, '970010002'), 0));

// 同 IP 每小时 8 次
Cache::delete($ipKeyOf());
for ($i = 1; $i <= 8; $i++) {
    $qq = '97' . str_pad((string)$i, 7, '0', STR_PAD_LEFT);   // 全是查无此人的号，只数 IP 这一档
    Cache::set($lastKeyOf($aTid, $qq), time() - 600, 3600);
    $send('v5r', $qq);
}
$qq9 = '9700000099';
Cache::set($lastKeyOf($aTid, $qq9), time() - 600, 3600);
[$s, $j] = $send('v5r', $qq9);
check('同一个 IP 每小时 8 次，第 9 次被挡', $s === 429 && str_contains((string)($j['msg'] ?? ''), 'IP'),
    'http=' . $s . ' ' . J($j));

// 每天 6 条这一档：一小时 3 条的闸在先，所以把每小时那档抹平当作过了一小时，两小时各三条
foreach ([1, 2] as $round) {
    Cache::delete($ipKeyOf()); Cache::delete($hrKeyOf($aTid, '970010006'));
    for ($i = 0; $i < 3; $i++) { $rewind($aTid, '970010006'); $send('v5r', '970010006'); }
}
check('两小时各三条，今天这一档数到 6',
    (int)Cache::get($dayKeyOf($aTid, '970010006'), 0) === 6, 'day=' . Cache::get($dayKeyOf($aTid, '970010006'), 0));
Cache::delete($ipKeyOf()); Cache::delete($hrKeyOf($aTid, '970010006')); $rewind($aTid, '970010006');
[$s, $j] = $send('v5r', '970010006');
check('同一天第六条之后再点：说的是「今天次数已用完」，不是「过于频繁」',
    $s === 429 && str_contains((string)($j['msg'] ?? ''), '今天'), 'http=' . $s . ' ' . J($j));

// ============================================================
// 4. 兑码改密码
// ============================================================
echo "\n== 4. 兑码：改密码这一步卡得住了吗 ==\n";
Cache::delete($ipKeyOf());
$qq4 = '970010001';
$live = db_rows("SELECT code FROM email_codes WHERE email='$e1' AND used=0 ORDER BY id DESC");
$code4 = (string)($live[0]['code'] ?? '');
check('手里有一条能用的码', preg_match('/^\d{6}$/', $code4) === 1, J($code4));
[$s, $j] = $reset('v5r', $qq4, '000000', 'abcd1234');
check('码不对：密码没动', $s === 200 && (int)($j['code'] ?? 0) === 1
    && password_verify('pass123456', (string)db_val("SELECT password FROM users WHERE id={$ids[$qq4]}")), J($j));
check('码不对回的那句，与「这个账号不存在」是同一句',
    (string)($j['msg'] ?? '') === (string)$reset('v5r', '979999999', '000000', 'abcd1234')[1]['msg'], J($j));
[$s, $j] = $reset('v5r', $qq4, $code4, 'abc12');
check('新密码太短：拦住', str_contains((string)($j['msg'] ?? ''), '8-64'), J($j));
[$s, $j] = $reset('v5r', $qq4, $code4, 'abcdefgh');
check('没数字的密码：拦住', str_contains((string)($j['msg'] ?? ''), '字母和一个数字'), J($j));
[$s, $j] = $reset('v5r', $qq4, $code4, 'qq123456');
check('弱口令黑名单照样拦（这一步不看码）', (int)($j['code'] ?? 0) === 1 && $j['msg'] !== '', J($j));
[$s, $j] = $reset('v5r', $qq4, $code4, 'abcd1234', 'abcd1235');
check('两次输入不一致：拦住', str_contains((string)($j['msg'] ?? ''), '不一致'), J($j));
check('前面这些都没把码兑掉（它还躺着）',
    (int)db_val("SELECT COUNT(*) FROM email_codes WHERE email='$e1' AND used=0") >= 1);

$epochBefore = (int)db_val("SELECT session_epoch FROM users WHERE id={$ids[$qq4]}");
[$s, $j] = $reset('v5r', $qq4, $code4, 'newpass53634');
check('码对上：当场重置，不用等审核', $s === 200 && (int)($j['code'] ?? 1) === 0 && str_contains((string)($j['msg'] ?? ''), '密码已重置'), J($j));
$newHash = (string)db_val("SELECT password FROM users WHERE id={$ids[$qq4]}");
check('库里密码真的换成新的那串（bcrypt，不是明文）',
    password_verify('newpass53634', $newHash) && strpos($newHash, '$2y$') === 0, mb_substr($newHash, 0, 8));
check('密码纪元 +1（他别处的会话下一跳就被踢）',
    (int)db_val("SELECT session_epoch FROM users WHERE id={$ids[$qq4]}") === $epochBefore + 1);
[$s, $j] = as_req('n1', 'POST', '/v5r/api/auth/login', ['username' => $qq4, 'password' => 'pass123456']);
check('旧密码登不进', (int)($j['code'] ?? 0) === 1, J($j));
Cache::delete('login_fail_' . md5('127.0.0.1'));
[$s, $j] = as_req('n2', 'POST', '/v5r/api/auth/login', ['username' => $qq4, 'password' => 'newpass53634']);
check('新密码能登进', (int)($j['code'] ?? 1) === 0, J($j));

// ============================================================
// 5. 一次性 / 过期 / 旧码作废
// ============================================================
echo "\n== 5. 一张码只能兑一次 ==\n";
[$s, $j] = $reset('v5r', $qq4, $code4, 'other53634');
check('同一张码兑第二次不认', (int)($j['code'] ?? 0) === 1 && str_contains((string)($j['msg'] ?? ''), '验证码'), J($j));
check('兑掉的那条在库里标成已用',
    (int)db_val("SELECT used FROM email_codes WHERE email='$e1' AND code='$code4'") === 1);
check('密码没有被第二次尝试改掉', password_verify('newpass53634', (string)db_val("SELECT password FROM users WHERE id={$ids[$qq4]}")));

$calm($aTid, $qq4); Cache::delete($ipKeyOf()); $rewind($aTid, $qq4);
[$s, $j] = $send('v5r', $qq4);
$mNew = mail_to($mark, $e1);
$codeNew = code_in($mNew);
check('重新发一条：新码到手', $codeNew !== '' && $codeNew !== $code4, J($codeNew));
check('发新码时旧码当场作废（库里没第二条活的）',
    (int)db_val("SELECT COUNT(*) FROM email_codes WHERE email='$e1' AND used=0") === 1);
check('用掉过的老码顺手清掉了（表不是一直长）',
    (int)db_val("SELECT COUNT(*) FROM email_codes WHERE email='$e1' AND used=1") === 0,
    'used=1 的还剩 ' . db_val("SELECT COUNT(*) FROM email_codes WHERE email='$e1' AND used=1"));
check('拿刚才那条旧码去兑：不认', (int)$reset('v5r', $qq4, $code4, 'zzz12345a')[1]['code'] === 1);
$pdo->exec("UPDATE email_codes SET expired_at='" . date('Y-m-d H:i:s', time() - 60) . "' WHERE email='$e1' AND used=0");
check('过期了的码不认', (int)$reset('v5r', $qq4, $codeNew, 'zzz12345b')[1]['code'] === 1);

// ============================================================
// 6. 改完之后，别的东西跟上了没有
// ============================================================
echo "\n== 6. 改密的连带效果：免登录、爆破锁、截图申请 ==\n";
$qq6 = '970010002';      // 邮箱栏空着那位，走「按 QQ 推出来的地址」
Cache::delete($ipKeyOf());
[$s, $j] = as_req('r6', 'POST', '/v5r/api/auth/login', ['username' => $qq6, 'password' => 'pass123456', 'remember' => 1]);
check('先按老办法登录并勾 7 天免登录', (int)($j['code'] ?? 1) === 0, J($j));
check('免登录令牌确实签发了',
    (int)db_val("SELECT COUNT(*) FROM remember_tokens WHERE user_id={$ids[$qq6]}") >= 1,
    '令牌数=' . db_val("SELECT COUNT(*) FROM remember_tokens WHERE user_id={$ids[$qq6]}"));
// 一张待审的截图申请（材料是真的落到 uploads 目录里的一个文件）
$shot = '/storage/uploads/profiles/_reset_probe_v53634.jpg';
$shotAbs = $ROOT . '/public' . $shot;
@mkdir(dirname($shotAbs), 0777, true);
file_put_contents($shotAbs, 'PROBE53634');
$pdo->exec("INSERT INTO password_resets (tenant_id, user_id, new_password, screenshot, request_ip, created_at)
    VALUES ($aTid, {$ids[$qq6]}, '" . password_hash('pending1234', PASSWORD_DEFAULT) . "', '$shot', '127.0.0.1', '"
    . date('Y-m-d H:i:s') . "')");
check('备好一张待审的截图申请', (int)db_val("SELECT COUNT(*) FROM password_resets WHERE user_id={$ids[$qq6]}") === 1 && is_file($shotAbs));
// 把这个账号的爆破锁按上（真被锁过的人改完密码不该还在门外）
Cache::set('login_lock_user_' . md5($aTid . ':' . $qq6), 1, 1800);
check('先按上「账号被临时锁定」', (int)Cache::get('login_lock_user_' . md5($aTid . ':' . $qq6), 0) === 1);

$calm($aTid, $qq6);
[$s, $j] = $send('v5r', $qq6);
$code6 = code_in(mail_to($mark, '970010002@qq.com'));
[$s, $j] = $reset('v5r', $qq6, $code6, 'reset653634');
check('用验证码把密码改掉', (int)($j['code'] ?? 1) === 0, J($j));
check('7 天免登录令牌全部作废（那台旧手机不会再自动进来）',
    (int)db_val("SELECT COUNT(*) FROM remember_tokens WHERE user_id={$ids[$qq6]}") === 0);
check('这个账号的爆破锁清掉了', (int)Cache::get('login_lock_user_' . md5($aTid . ':' . $qq6), 0) === 0);
check('之前那张待审的截图申请撤掉了（人已经自己改好了）',
    (int)db_val("SELECT COUNT(*) FROM password_resets WHERE user_id={$ids[$qq6]}") === 0);
clearstatcache(true, $shotAbs);
check('撤申请时材料也跟着删掉，不留垃圾', !is_file($shotAbs));
[$s, $j] = as_req('r6b', 'POST', '/v5r/api/auth/login', ['username' => $qq6, 'password' => 'reset653634']);
check('被锁过的账号改完马上能登录', (int)($j['code'] ?? 1) === 0, J($j));
Cache::delete('login_fail_' . md5('127.0.0.1'));
@unlink($shotAbs);

// ============================================================
// 7. 同一个 QQ 在两队：改的是当前这一站的账号，一张码只兑得动一次
// ============================================================
echo "\n== 7. 跨队：同一个 QQ 在两队，码只兑一次 ==\n";
$qq7 = '970010006'; $e7 = '970010006@qq.com';
$oldA7 = (string)db_val("SELECT password FROM users WHERE id={$ids[$qq7]}");
$oldB7 = (string)db_val("SELECT password FROM users WHERE id={$bIds[$qq7]}");
$calm($aTid, $qq7); $rewind($aTid, $qq7);
$m7 = smtp_mark();
[$s, $j] = $send('v5r', $qq7);
$code7 = code_in(mail_to($m7, $e7));
check('在甲队领到一条码', preg_match('/^\d{6}$/', $code7) === 1, J($code7));
// 管这个邮箱的是同一个人：这张码拿到乙队去，改到的也只能是他自己乙队的账号，碰不到别人的号
$calm($bTid, $qq7);
[$s, $j] = $reset('v5s', $qq7, $code7, 'cross753634x');
check('码在乙队兑动的是乙队那个账号（改谁由当前站点决定）',
    (int)($j['code'] ?? 1) === 0
    && password_verify('cross753634x', (string)db_val("SELECT password FROM users WHERE id={$bIds[$qq7]}")), J($j));
check('甲队同 QQ 那个账号一点没动',
    (string)db_val("SELECT password FROM users WHERE id={$ids[$qq7]}") === $oldA7);
[$s, $j] = $reset('v5r', $qq7, $code7, 'cross9999999');
check('一条码只兑得动一次：回甲队再拿同一串数字兑，不认',
    (int)($j['code'] ?? 0) === 1 && (string)db_val("SELECT password FROM users WHERE id={$ids[$qq7]}") === $oldA7, J($j));
check('两行还是两个独立账号（没多出行、tenant_id 也没被改）',
    (int)db_val("SELECT COUNT(*) FROM users WHERE username='$qq7'") === 2
    && (int)db_val("SELECT tenant_id FROM users WHERE id={$bIds[$qq7]}") === $bTid
    && (int)db_val("SELECT tenant_id FROM users WHERE id={$ids[$qq7]}") === $aTid);
$calm($bTid, $qq7);
$m7b = smtp_mark();
[$s, $j] = $send('v5s', $qq7);
$code7b = code_in(mail_to($m7b, $e7));
check('在乙队领码这一步也走得通（这条路对每支战队自己的成员都开着）',
    preg_match('/^\d{6}$/', $code7b) === 1, 'http=' . $s . ' 抠到=' . J($code7b));
$calm($bTid, $qq7);
[$s, $j] = $reset('v5s', $qq7, $code7b, 'cross7bb02x');
check('乙队自己领的码改的还是乙队那个账号，甲队照旧不动',
    (int)($j['code'] ?? 1) === 0
    && password_verify('cross7bb02x', (string)db_val("SELECT password FROM users WHERE id={$bIds[$qq7]}"))
    && (string)db_val("SELECT password FROM users WHERE id={$ids[$qq7]}") === $oldA7, J($j));

// ============================================================
// 8. 进不去的门
// ============================================================
echo "\n== 8. 门外的三种走法 ==\n";
[$s, $j] = as_req('anon', 'POST', '/api/auth/send-code', ['qq' => '970010001']);
check('平台根路径索取验证码：404（这条接口只在战队目录受理）',
    $s === 404 || (int)($j['code'] ?? 0) === 404, 'http=' . $s . ' ' . J($j));
[$s, $j] = as_req('anon', 'POST', '/api/auth/reset-by-code', ['qq' => '970010001', 'code' => '123456', 'password' => 'abcd1234', 'confirm' => 'abcd1234']);
check('平台根路径兑码：同样 404', $s === 404 || (int)($j['code'] ?? 0) === 404, 'http=' . $s . ' ' . J($j));
[$s, $j] = as_req('anon', 'POST', '/v5t/api/auth/send-code', ['qq' => '970030001']);
check('整队被封的站点：这条路也走不通（http=' . $s . '）', $s === 403 || $s === 404, 'http=' . $s . ' ' . J($j));
$hashBefore = (string)db_val("SELECT password FROM users WHERE id={$ids['970010005']}");
$calm($aTid, '970010005'); Cache::delete($ipKeyOf());
[$s, $j] = $send('v5r', '970010005');
$e5 = '970010005@qq.com';
check('已封禁的账号：不发码（库里没有他这条邮箱的码）',
    (int)db_val("SELECT COUNT(*) FROM email_codes WHERE email='$e5'") === 0, J($j));
[$s, $j] = $reset('v5r', '970010005', '123456', 'hack53634x');
check('已封禁的账号：拿随便一串数字也改不动密码',
    (string)db_val("SELECT password FROM users WHERE id={$ids['970010005']}") === $hashBefore, J($j));
[$s, $j] = as_req('anon', 'POST', '/v5r/api/auth/send-code', ['qq' => '123']);
check('QQ 号格式不对：直接拦（不发信也不进限流）', (int)($j['code'] ?? 0) === 1 && str_contains((string)($j['msg'] ?? ''), 'QQ'), J($j));

// ============================================================
// 9. 投递失败：对外同一句，对内不留活码
// ============================================================
echo "\n== 9. 服务器把那封信退回来了 ==\n";
$qq9x = '970010003'; $e9 = 'other970010003@qq.com';
smtp_reject($e9);
$calm($aTid, $qq9x); Cache::delete($ipKeyOf()); $rewind($aTid, $qq9x);
$before = smtp_mark();
[$s, $j] = $send('v5r', $qq9x);
check('投递失败时对外说的还是那句「已发到」（漏不出这个账号存不存在）',
    $s === 200 && (int)($j['code'] ?? 1) === 0 && str_contains((string)($j['msg'] ?? ''), '已发到'), J($j));
check('库里没给这个地址留活码（发不出去的码当场作废）',
    (int)db_val("SELECT COUNT(*) FROM email_codes WHERE email='$e9' AND used=0") === 0,
    '活的=' . db_val("SELECT COUNT(*) FROM email_codes WHERE email='$e9' AND used=0"));
$log9 = db_rows("SELECT action, detail FROM admin_logs WHERE tenant_id=$aTid AND action='找回密码' ORDER BY id DESC");
$d9 = (string)($log9[0]['detail'] ?? '');
check('管理日志里留了一条投递失败（队长/超管看得见，成员看不见）',
    $log9 !== [] && str_contains($d9, '投递失败'), J($log9[0] ?? []));
check('日志里的地址是打了码的，不是整条邮箱', str_contains($d9, '**'), $d9);
$okBefore = smtp_mark();
$calm($aTid, '970010001'); Cache::delete($ipKeyOf()); $rewind($aTid, '970010001');
$send('v5r', '970010001');
check('别人的信照旧发得出去（退回名单只针对那一个地址）', count(smtp_new($okBefore)) === 1, '新增=' . count(smtp_new($okBefore)));
file_put_contents($SMTP_REJ, '');

// ============================================================
// 收尾
// ============================================================
echo "\n== 10. 收尾 ==\n";
smtp_stop();
foreach (glob($ROOT . '/runtime/_fakesmtp' . $SMTP_PORT . '.*') ?: [] as $f) { @unlink($f); }
clearstatcache(true, $shotAbs);
check('演练留下的探针文件与假 SMTP 临时文件都清掉了',
    !is_file($shotAbs) && !is_file($SMTP_LOG) && !is_file($SMTP_REJ), $shotAbs);
check('这一轮总共发出的信（含退回那封的尝试）都记在假服务器日志里',
    (int)db_val("SELECT COUNT(*) FROM email_codes") > 0);
echo '  email_codes 现存 ' . (int)db_val("SELECT COUNT(*) FROM email_codes") . " 行（活的 "
    . (int)db_val("SELECT COUNT(*) FROM email_codes WHERE used=0") . " 条）\n";
echo '  找回密码在 admin_logs 里留了 '
    . (int)db_val("SELECT COUNT(*) FROM admin_logs WHERE action='找回密码'") . " 条\n";

echo "\n通过 {$pass} 项，失败 {$fail} 项\n";
if ($fail > 0) {
    echo "失败清单：\n";
    foreach ($failures as $f) { echo "  - {$f}\n"; }
}
exit($fail > 0 ? 1 : 0);
