<?php
/**
 * v5.36.35 上传顺序 / 撞唯一键 / 租户归属（读写两侧）E2E
 * （scripts/test_auth_upload_guard_v53635_e2e.php）
 *
 * 这一套钉的是外面那份代码审查提的六件事，全部按「真人打接口」验，不靠读代码下结论：
 *   1. forgot 不再为「查无此人」把截图写进磁盘（先查库，再收图）
 *   2. 防枚举没被改坏：没带图 / 坏图 / 好图，在册与查无此人两种 QQ 的文案必须逐字相同
 *   3. register 与 forgot 撞上唯一键时给能看懂的话，不给 500，并且当场删掉那张图
 *   4. 三处读路径（论坛只读流、QQ 群战绩榜、成员导出）带上脏 id 也不许把外队的名字带出来
 *   5. 未登录那两个上传入口，最前面先按 Content-Length 早退（超限的不占限流额度、不落盘、不写库）
 *   6. 按 id 改数据的接口，find() 之后还要显式比一次归属：甲队拿乙队的 id 一律「不存在」，
 *      同一批 id 换乙队站长打必须照常打得动（两份真实账号互打 + 同队对照）
 *
 * 第 3 条要真并发才撞得出来：本机 php -S 一个进程只处理一个请求，所以这套件自己再起一台
 * 127.0.0.1:8897，然后用 curl.exe 打一边、自己打另一边。SQLite 上「同队同 QQ」的第二行
 * 一定被 uk_tenant_username 拒掉，谁先谁后不确定，所以每种判定都试 4 次，只要有一次走到
 * 插入那步（判据：失败那一方的耗时明显长，说明它真做完了解压/压缩才撞键）就算数。
 *
 * 前置：.env 指向 SQLite（php runtime/_env.php fixture）
 * 用法：php scripts/test_auth_upload_guard_v53635_e2e.php
 *      TEST_BASE 默认 http://127.0.0.1:8899，第二台端口用 TEST_BASE2 覆盖
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');

$ROOT = dirname(__DIR__);
$BASE = rtrim((string)(getenv('TEST_BASE') ?: 'http://127.0.0.1:8899'), '/');
$BASE2 = rtrim((string)(getenv('TEST_BASE2') ?: 'http://127.0.0.1:8897'), '/');
$DB   = $ROOT . '/runtime/test.sqlite';
$UP   = $ROOT . '/public/storage/uploads';
// battle_api_make_key() 这些站内助手在 app/common.php 里，建 Key 那段要用
require_once $ROOT . '/app/common.php';

$pass = 0; $fail = 0; $failures = [];
function check(string $name, bool $ok, string $detail = ''): void
{
    global $pass, $fail, $failures;
    if ($ok) { $pass++; echo "  [PASS] $name\n"; }
    else { $fail++; $failures[] = $name . ($detail !== '' ? " ($detail)" : ''); echo "  [FAIL] $name" . ($detail !== '' ? " —— $detail\n" : "\n"); }
}
function say(string $m): void { echo "  · $m\n"; }
function J($a): string { return json_encode($a, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); }

// ---------- HTTP ----------
$jars = [];
function as_req(string $who, string $method, string $path, ?array $body = null, ?string $base = null, array $hdrExtra = []): array
{
    global $jars;
    $base = $base ?? (string)(getenv('TEST_BASE') ?: 'http://127.0.0.1:8899');
    $base = rtrim($base, '/');
    $c = $jars[$who] ?? [];
    $hdr = "Content-Type: application/json\r\n";
    if ($c) {
        $pairs = [];
        foreach ($c as $k => $v) { $pairs[] = $k . '=' . $v; }
        $hdr .= 'Cookie: ' . implode('; ', $pairs) . "\r\n";
    }
    foreach ($hdrExtra as $h) { $hdr .= $h . "\r\n"; }
    $opts = ['http' => ['method' => $method, 'header' => $hdr, 'ignore_errors' => true,
        'timeout' => 120, 'follow_location' => 0]];
    if ($body !== null) { $opts['http']['content'] = json_encode($body, JSON_UNESCAPED_UNICODE); }
    $t0 = microtime(true);
    $raw = @file_get_contents($base . $path, false, stream_context_create($opts));
    $status = 0; $hdrLines = [];
    foreach ($http_response_header ?? [] as $h) {
        $hdrLines[] = $h;
        if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) { $status = (int)$m[1]; }
        if (preg_match('/^Set-Cookie:\s*([^=]+)=([^;]*)/i', $h, $m)) { $c[$m[1]] = trim($m[2]); }
    }
    $jars[$who] = $c;
    $j = json_decode((string)$raw, true);
    return [$status, is_array($j) ? $j : ['raw' => (string)$raw], $hdrLines, (int)round((microtime(true) - $t0) * 1000)];
}
/** 带文件的 multipart 提交（注册与找回密码都要图） */
function post_form(string $base, string $path, array $fields, ?string $fileBytes, string $fileName = 's.png',
                   string $mime = 'image/png'): array
{
    $b = '----g53635' . bin2hex(random_bytes(6));
    $body = '';
    foreach ($fields as $k => $v) {
        $body .= "--$b\r\nContent-Disposition: form-data; name=\"$k\"\r\n\r\n$v\r\n";
    }
    if ($fileBytes !== null) {
        $body .= "--$b\r\nContent-Disposition: form-data; name=\"file\"; filename=\"$fileName\"\r\n"
               . "Content-Type: $mime\r\n\r\n$fileBytes\r\n";
    }
    $body .= "--$b--\r\n";
    $t0 = microtime(true);
    $raw = @file_get_contents(rtrim($base, '/') . $path, false, stream_context_create(['http' => [
        'method' => 'POST', 'header' => "Content-Type: multipart/form-data; boundary=$b\r\n",
        'content' => $body, 'ignore_errors' => true, 'timeout' => 180,
    ]]));
    $status = 0;
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) { $status = (int)$m[1]; }
    }
    $j = json_decode((string)$raw, true);
    return [$status, is_array($j) ? $j : ['raw' => (string)$raw], (int)round((microtime(true) - $t0) * 1000)];
}

// ---------- DB ----------
function db(): PDO
{
    global $DB;
    $p = new PDO('sqlite:' . $DB);
    $p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $p->setAttribute(PDO::ATTR_TIMEOUT, 5);
    return $p;
}
function val(string $sql, array $bind = [])
{
    $st = db()->prepare($sql); $st->execute($bind); $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ? reset($r) : null;
}
function rows(string $sql, array $bind = []): array
{
    $st = db()->prepare($sql); $st->execute($bind);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}
function clearCache(): void
{
    global $ROOT;
    foreach (array_merge(glob($ROOT . '/runtime/cache/*/*') ?: [], glob($ROOT . '/runtime/cache/*') ?: []) as $f) {
        if (is_file($f)) { @unlink($f); }
    }
}
// ---------- 磁盘与引用 ----------
/** uploads 下所有真实图片文件（相对 web 路径） */
function diskFiles(): array
{
    global $UP;
    $out = [];
    foreach (glob("$UP/*/*/*") ?: [] as $f) {
        if (is_file($f) && !in_array(basename($f), ['.gitkeep', '.htaccess'], true)) {
            clearstatcache(true, $f);
            $rel = str_replace('\\', '/', substr($f, strlen($UP)));
            $out['/storage/uploads' . $rel] = (int)filesize($f);
        }
    }
    return $out;
}
/** 库里引用到的图片路径：注册头像、找回密码佐证、公告配图、展示页图 */
function referenced(): array
{
    $ref = [];
    foreach (rows("SELECT profile_image AS p FROM users WHERE profile_image <> ''") as $r) { $ref[(string)$r['p']] = 1; }
    foreach (rows("SELECT screenshot AS p FROM password_resets WHERE screenshot IS NOT NULL AND screenshot <> ''") as $r) { $ref[(string)$r['p']] = 1; }
    foreach (rows("SELECT image AS p FROM announcements WHERE image IS NOT NULL AND image <> ''") as $r) { $ref[(string)$r['p']] = 1; }
    foreach (rows("SELECT value AS p FROM settings WHERE key IN ('showcase_logo','showcase_banner') AND value <> ''") as $r) { $ref[(string)$r['p']] = 1; }
    return $ref;
}
function orphanCount(): int
{
    $ref = referenced();
    $n = 0;
    foreach (diskFiles() as $path => $bytes) { if (!isset($ref[$path])) { $n++; } }
    return $n;
}
/**
 * 本轮新增的孤儿数：uploads 里历来压着别的套件留下的历史文件（本机测试残留，不是这一套弄出来的），
 * 所以只该问「这一套打完，新出现的文件里有没有没人引用的那一张」。
 */
$GLOBALS['scriptBaseline'] = [];
function newOrphanCount(): int
{
    $ref = referenced();
    $n = 0; $new = 0;
    foreach (diskFiles() as $path => $bytes) {
        if (isset($GLOBALS['scriptBaseline'][$path])) { continue; }
        $new++;
        if (!isset($ref[$path])) { $n++; }
    }
    return $n;
}
/** 本轮往磁盘上新增了几个文件 */
function newFileCount(): int
{
    return count(array_diff_key(diskFiles(), $GLOBALS['scriptBaseline']));
}
// ---------- 图片 ----------
function tinyPng(): string
{
    $im = imagecreatetruecolor(32, 32);
    imagefill($im, 0, 0, imagecolorallocate($im, 10, 200, 120));
    ob_start(); imagepng($im); $p = (string)ob_get_clean(); imagedestroy($im);
    return $p;
}
/** 压不动的噪声图：真实最坏情况（一张图到底占多少盘） */
function noisePng(int $target): string
{
    $best = '';
    foreach ([700, 900, 1100, 1300, 1500] as $side) {
        $im = imagecreatetruecolor($side, $side);
        for ($y = 0; $y < $side; $y += 2) {
            for ($x = 0; $x < $side; $x += 2) {
                imagesetpixel($im, $x, $y, (random_int(0, 255) << 16) | (random_int(0, 255) << 8) | random_int(0, 255));
            }
        }
        ob_start(); imagepng($im, null, 0); $p = (string)ob_get_clean(); imagedestroy($im);
        if (strlen($p) > $target) { break; }
        $best = $p;
    }
    return $best;
}

// ============================================================
// 0. 建库与种子
// ============================================================
echo "== 0. 重建测试库与种子 ==\n";
@unlink($DB);
echo (string)shell_exec('"' . PHP_BINARY . '" "' . $ROOT . '/scripts/dev_init_sqlite.php" "' . $DB . '" 2>&1');
clearCache();
foreach (glob($ROOT . '/runtime/bot/*.log') ?: [] as $f) { @unlink($f); }
foreach (glob("$UP/profiles/*/*") ?: [] as $f) { if (is_file($f)) { @unlink($f); } }

$now  = date('Y-m-d H:i:s');
$pdo  = db();
$hash = password_hash('pass123456', PASSWORD_DEFAULT);

$mkTenant = function (string $slug, string $name, string $leaderQq) use ($pdo, $hash, $now): array {
    $pdo->exec("INSERT INTO platform_users (username,password,nickname,role,status,reg_ip,created_at,updated_at)
        VALUES ('{$slug}boss', '{$hash}', '{$name}联络人', 'team', 'normal', '127.0.0.1', '$now', '$now')");
    $pid = (int)$pdo->lastInsertId();
    $pdo->exec("INSERT INTO tenants (applicant_id, slug, name, status, admin_username, admin_user_id, created_at, updated_at)
        VALUES ($pid, '$slug', '$name', 'approved', '$leaderQq', NULL, '$now', '$now')");
    $tid = (int)$pdo->lastInsertId();
    $pdo->exec("INSERT INTO users (tenant_id, username, password, nickname, email, role, status, admin_perms, game_side, show_public, stats_public, created_at, updated_at)
        VALUES ($tid, '$leaderQq', '$hash', '{$name}站长', '$leaderQq@qq.com', 'admin', 'approved', '[]', '', 1, 1, '$now', '$now')");
    $uid = (int)$pdo->lastInsertId();
    $pdo->exec("UPDATE tenants SET admin_user_id = $uid WHERE id = $tid");
    return ['tid' => $tid, 'leader' => $uid];
};
$mkUser = function (int $tid, string $qq, string $nick, string $role = 'user', int $show = 1, int $statsPub = 1, string $status = 'approved') use ($pdo, $hash, $now): int {
    $pdo->exec("INSERT INTO users (tenant_id, username, password, nickname, email, role, status, admin_perms, game_side, show_public, stats_public, reg_ip, created_at, updated_at)
        VALUES ($tid, '$qq', '$hash', '$nick', '$qq@qq.com', '$role', '$status', '[]', '', $show, $statsPub, '9.9.9.9', '$now', '$now')");
    return (int)$pdo->lastInsertId();
};

$A = $mkTenant('aga', '上传甲队', '9960000001');
$B = $mkTenant('bga', '上传乙队', '9961000001');
$aTid = (int)$A['tid']; $bTid = (int)$B['tid'];
$aLeader = (int)$A['leader']; $bLeader = (int)$B['leader'];
// 甲队：一个愿意露脸的在册成员（读侧三条都用他）+ 一个正常成员
$aPub    = $mkUser($aTid, '9960000101', '甲队该露脸的');
$aPlain  = $mkUser($aTid, '9960000102', '甲队普通成员');
// 乙队：一个「绝不该出现在甲队任何输出里」的人，昵称起得独一无二，好比对
$bGhost  = $mkUser($bTid, '9961000101', '乙队绝不外露的人');
// 两队各一个小队，名字互不重叠
$pdo->exec("INSERT INTO teams (tenant_id, name, created_at, updated_at) VALUES ($aTid, '甲队夜袭组', '$now', '$now')");
$aTeam = (int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO teams (tenant_id, name, created_at, updated_at) VALUES ($bTid, '乙队不该出现的队名', '$now', '$now')");
$bTeam = (int)$pdo->lastInsertId();
$pdo->exec("UPDATE users SET team_id = $aTeam WHERE id = $aPub");
$pdo->exec("INSERT INTO player_stats (tenant_id, user_id, matches, win_rate, kd, kills, avg_score, created_at, updated_at)
    VALUES ($aTid, $aPub, 20, 55.00, 1.80, 200, 3.20, '$now', '$now')");
say('甲 tid=' . $aTid . ' leader=' . $aLeader . ' pub=' . $aPub . ' plain=' . $aPlain
    . ' | 乙 tid=' . $bTid . ' ghost=' . $bGhost . ' | 小队 甲=' . $aTeam . ' 乙=' . $bTeam);
$GLOBALS['scriptBaseline'] = diskFiles();
say('基线：uploads 里已有历史文件 ' . count($GLOBALS['scriptBaseline']) . ' 个（别的套件留的，本套件不动它们，只比自己新增的那几个）');

// 甲队自己的租户 Key（论坛只读流用），以及 mock 机器人（QQ 群战绩榜用）
$mkKey = function (int $tid, int $uid, string $name) use ($pdo, $now): array {
    [$plain, $khash, $prefix] = battle_api_make_key();
    $pdo->exec("INSERT INTO api_keys (key_hash, key_prefix, name, scope, tenant_id, bound_user_id, status, created_by, created_at)
        VALUES ('$khash', '$prefix', '$name', 'tenant', $tid, $uid, 1, $uid, '$now')");
    return ['plain' => $plain, 'id' => (int)$pdo->lastInsertId()];
};
$KEY = $mkKey($aTid, $aLeader, '甲队论坛 Key');
$pdo->exec("INSERT INTO settings (tenant_id, key, value) VALUES ($aTid, 'forum_api_on', '1')
            ON CONFLICT(tenant_id, key) DO UPDATE SET value = '1'");
$pdo->exec("INSERT INTO bot_tenant_bots (tenant_id, app_id, app_secret, callback_key, enabled, env, created_at, updated_at)
    VALUES ($aTid, 'mock', 'mock-secret', 'g53635_key', 1, 'prod', '$now', '$now')");
$pdo->exec("INSERT INTO bot_group_bindings (tenant_id, group_openid, bound_by, created_at)
    VALUES ($aTid, 'group-G53635', 0, '$now')");

$login = static function (string $who, string $slug, string $acct) use ($BASE): void {
    global $pass, $fail;
    [$s, $j] = as_req($who, 'POST', '/' . $slug . '/api/auth/login', ['username' => $acct, 'password' => 'pass123456'], $BASE);
    $ok = $s === 200 && (int)($j['code'] ?? 1) === 0;
    check("登录 {$who}（$slug/{$acct}）", $ok, 'http=' . $s . ' ' . J($j));
};
echo "\n== 1. 登录三种身份 ==\n";
$login('aBoss', 'aga', '9960000001');
$login('bBoss', 'bga', '9961000001');

$png = tinyPng();
$BIG = noisePng(3 * 1024 * 1024);
say('小图 ' . strlen($png) . " 字节；后面撞键用的是噪声图 " . strlen($BIG) . ' 字节（压缩要跑一阵，把并发窗口拉开）');

// ============================================================
// 2. forgot：查无此人不再收图
// ============================================================
echo "\n== 2. 查无此人那一路：不再把截图写进磁盘 ==\n";
clearCache();
$before = diskFiles();
[$s, $j, $ms] = post_form($BASE, '/aga/api/auth/forgot',
    ['qq' => '9969990001', 'password' => 'Guard12345', 'confirm' => 'Guard12345'], $BIG);
$after = diskFiles();
printf("  打一次：http=%d code=%d 耗时=%dms 文案=%s\n", $s, (int)($j['code'] ?? -1), $ms, (string)($j['msg'] ?? ''));
check('查无此人仍回同一句受理文案（不暴露账号存在与否）', (int)($j['code'] ?? -1) === 0
    && (string)($j['msg'] ?? '') === '申请已受理，管理员审核通过后即可使用新密码登录', J($j));
check('这一次磁盘上没有新增任何文件', count($after) === count($before),
    'before=' . count($before) . ' after=' . count($after) . ' 新增=' . J(array_keys(array_diff_key($after, $before))));
check('password_resets 里没有这一行（查无此人不登记）',
    (int)val("SELECT COUNT(*) FROM password_resets WHERE tenant_id=$aTid") === 0);

echo "\n== 3. 在册那一路：图和申请照旧一起登记 ==\n";
clearCache();
$before = diskFiles();
[$s, $j, $ms] = post_form($BASE, '/aga/api/auth/forgot',
    ['qq' => '9960000101', 'password' => 'Guard12345', 'confirm' => 'Guard12345'], $BIG);
$after = diskFiles();
$new = array_diff_key($after, $before);
printf("  打一次：http=%d code=%d 耗时=%dms 新增文件=%d 文案=%s\n", $s, (int)($j['code'] ?? -1), $ms, count($new), (string)($j['msg'] ?? ''));
check('在册的这条真的落了申请行', (int)val("SELECT COUNT(*) FROM password_resets WHERE tenant_id=$aTid AND user_id=$aPub") === 1);
check('在册的这条确实收了一张图', count($new) === 1, J($new));
$shot = (string)val("SELECT screenshot FROM password_resets WHERE tenant_id=$aTid AND user_id=$aPub");
check('申请行里存的图与磁盘上那张是同一个路径', $shot !== '' && isset($after[$shot]), "库里=$shot 盘上=" . J(array_keys($after)));
check('零孤儿（本轮每张新落盘的图都被某行引用着）', newOrphanCount() === 0, '本轮新增孤儿=' . newOrphanCount());

echo "\n== 4. 已经有一张待审单：不重复登记、不重复收图 ==\n";
clearCache();
$before = diskFiles();
[$s, $j, $ms] = post_form($BASE, '/aga/api/auth/forgot',
    ['qq' => '9960000101', 'password' => 'Guard99999', 'confirm' => 'Guard99999'], $BIG);
$after = diskFiles();
check('第三次打同一个在册号：仍然同一句受理文案', (int)($j['code'] ?? -1) === 0
    && (string)($j['msg'] ?? '') === '申请已受理，管理员审核通过后即可使用新密码登录', J($j));
check('待审单还是一张（没有第二行）', (int)val("SELECT COUNT(*) FROM password_resets WHERE tenant_id=$aTid AND user_id=$aPub") === 1);
check('磁盘上没有第二张图（这次连收都不收）', count($after) === count($before),
    'before=' . count($before) . ' after=' . count($after));
check('旧单里存的还是第一次那张图（没被覆盖）', (string)val("SELECT screenshot FROM password_resets WHERE tenant_id=$aTid AND user_id=$aPub") === $shot,
    '现在=' . (string)val("SELECT screenshot FROM password_resets WHERE tenant_id=$aTid AND user_id=$aPub"));

// ============================================================
// 5. 防枚举：同一句错误，两种 QQ
// ============================================================
echo "\n== 5. 防枚举：错误文案对两种 QQ 必须逐字相同 ==\n";
$cases = [
    '没带图'      => ['file' => null, 'name' => '', 'mime' => 'image/png'],
    '假图（txt 改名）' => ['file' => str_repeat('hello world ', 400), 'name' => 'notpng.png', 'mime' => 'image/png'],
    '超 5MB 的图' => ['file' => noisePng(6 * 1024 * 1024) ?: str_repeat('x', 6 * 1024 * 1024), 'name' => 'big.png', 'mime' => 'image/png'],
    '声明成 text/plain 的 PNG' => ['file' => $png, 'name' => 's.png', 'mime' => 'text/plain'],
];
foreach ($cases as $label => $c) {
    clearCache();
    [, $exist, $ms1] = post_form($BASE, '/aga/api/auth/forgot',
        ['qq' => '9960000101', 'password' => 'Guard12345', 'confirm' => 'Guard12345'], $c['file'], $c['name'], $c['mime']);
    clearCache();
    [, $none, $ms2] = post_form($BASE, '/aga/api/auth/forgot',
        ['qq' => '9969990002', 'password' => 'Guard12345', 'confirm' => 'Guard12345'], $c['file'], $c['name'], $c['mime']);
    $m1 = (string)($exist['msg'] ?? ''); $m2 = (string)($none['msg'] ?? '');
    printf("  %-26s 在册=%-28s 查无此人=%s\n", $label, $m1, $m2);
    check("「{$label}」这一种输入，两种 QQ 报的字完全一样", $m1 === $m2 && $m1 !== '', "在册=[$m1] 查无此人=[$m2]");
    check("「{$label}」这一种输入没有往磁盘写任何东西", newOrphanCount() === 0 && newFileCount() === 1,
        '本轮新增文件=' . newFileCount() . ' 新增孤儿=' . newOrphanCount());
}
// 好图那条也要成对：两种 QQ 都只回同一句成功
clearCache();
[, $okExist] = post_form($BASE, '/aga/api/auth/forgot', ['qq' => '9960000102', 'password' => 'Guard12345', 'confirm' => 'Guard12345'], $png);
clearCache();
[, $okNone] = post_form($BASE, '/aga/api/auth/forgot', ['qq' => '9969990003', 'password' => 'Guard12345', 'confirm' => 'Guard12345'], $png);
check('好图 + 在册/查无此人：两种都回同一句受理', (string)($okExist['msg'] ?? '') === (string)($okNone['msg'] ?? '')
    && (int)($okExist['code'] ?? -1) === 0 && (int)($okNone['code'] ?? -1) === 0, J([$okExist, $okNone]));

echo "\n== 6. 限流仍然在收图之前（第 4 次连图都不收） ==\n";
clearCache();
$filesAt = newFileCount();
$codes = [];
for ($i = 1; $i <= 4; $i++) {
    [$s, $j] = post_form($BASE, '/aga/api/auth/forgot',
        ['qq' => '996999' . str_pad((string)$i, 4, '0'), 'password' => 'Guard12345', 'confirm' => 'Guard12345'], $png);
    $codes[] = (int)($j['code'] ?? -1) . '/' . $s;
}
say('四次连打的 code/http：' . implode(' ', $codes));
check('前 3 次受理、第 4 次 429（限流在收图之前就判）', substr_count(implode(',', $codes), '0/200') === 3
    && strpos(implode(',', $codes), '429/429') !== false, implode(' ', $codes));
check('这四次没往磁盘上新增一个文件（查无此人 + 被限流都不收图）', newFileCount() === $filesAt
    && newOrphanCount() === 0, '本轮新增文件=' . newFileCount() . '（打之前是 ' . $filesAt . '）');

// ============================================================
// 7. register：查重在收图之前；撞唯一键给人话
// ============================================================
echo "\n== 7. 注册：查重在前，撞键不 500 ==\n";
clearCache();
$before = diskFiles();
[$s, $j] = post_form($BASE, '/aga/api/auth/register',
    ['qq' => '9960000101', 'password' => 'Guard12345', 'confirm' => 'Guard12345'], $BIG);
$after = diskFiles();
check('已注册的 QQ：直接给人话', (int)($j['code'] ?? -1) !== 0 && (string)($j['msg'] ?? '') === '该 QQ 号已注册，请直接登录', J($j));
check('这条连一张图都没收（查重在收图之前）', count($after) === count($before),
    'before=' . count($before) . ' after=' . count($after));

// —— 真并发：两路同时注册同一个 QQ，看插入那一步撞键会不会兜住
echo "\n== 8. 真并发撞 uk_tenant_username（两台服务器 + curl 并行） ==\n";
$raceQqBase = 9968880000;
$secondUp = false;
$probe = @file_get_contents($BASE2 . '/aga/api/auth/login', false, stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true]]));
$secondUp = $probe !== false;
if (!$secondUp) {
    say("第二台服务器不在（$BASE2 不通）。请按下面两条各起一台，再重跑这一套：\n"
        . '  php -d upload_max_filesize=64M -d post_max_size=70M -S 127.0.0.1:8899 -t public public/router.php' . "\n"
        . '  php -d upload_max_filesize=64M -d post_max_size=70M -S 127.0.0.1:8897 -t public public/router.php');
    check('第二台测试服务器在跑（并发这一段的硬前置）', false, $BASE2 . ' 连不上');
} else {
    check('第二台测试服务器可达', true);
    $racePng = $ROOT . '/runtime/_race53635.png';
    file_put_contents($racePng, $BIG);
    $slowFail = 0; $fastFail = 0; $fiveHund = 0; $dupRows = 0; $orphanAt = 0;
    for ($round = 1; $round <= 6 && $secondUp; $round++) {
        $qq = (string)($raceQqBase + $round);
        clearCache();
        $outFile = $ROOT . '/runtime/_race53635_curl.json';
        @unlink($outFile);
        // B 先用 curl 出发（字段全 ASCII，避开 curl 传中文坏字节的坑），-w 把整请求耗时打到 stdout
        $cmd = sprintf('curl.exe -s -m 120 -o "%s" -w "%%{time_total}" -X POST -F "qq=%s" -F "password=Guard12345" '
            . '-F "confirm=Guard12345" -F "file=@%s;type=image/png;filename=s.png" "%s/aga/api/auth/register"',
            addslashes($outFile), $qq, addslashes($racePng), $BASE2);
        $pipes = [];
        $child = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($child)) { check('curl 子进程起得来', false); $secondUp = false; break; }
        // 错开几十到两百毫秒：让 B 先进到「收图 + 压缩」那一段，A 这时才查重，两边都看不见对方，
        // 后写进去的那个就撞在 uk_tenant_username 上
        usleep(30000 + 40000 * $round);
        [$s1, $j1, $ms1] = post_form($BASE, '/aga/api/auth/register',
            ['qq' => $qq, 'password' => 'Guard12345', 'confirm' => 'Guard12345'], $BIG);
        $bRaw = (string)stream_get_contents($pipes[1]);
        foreach ($pipes as $p) { if (is_resource($p)) { fclose($p); } }
        proc_close($child);
        $cj = json_decode((string)@file_get_contents($outFile), true);
        $msg2 = (string)($cj['msg'] ?? ''); $code2 = (int)($cj['code'] ?? -1);
        $bMs = (int)round((float)trim($bRaw) * 1000);
        $rows = (int)val("SELECT COUNT(*) FROM users WHERE tenant_id=$aTid AND username='$qq'");
        // 谁赢谁输：A 的 code=0 就是 A 抢到了，那输的一方是 B（文案与耗时都取 B 那一侧）
        $aWon = (int)($j1['code'] ?? -1) === 0;
        $loserMs = $aWon ? $bMs : $ms1;
        $loserMsg = $aWon ? $msg2 : (string)($j1['msg'] ?? '');
        printf("  第 %d 轮 qq=%s：A code=%d %dms | B code=%d %dms | 输的一方 %dms 文案=%s | 库里=%d 行\n",
            $round, $qq, (int)($j1['code'] ?? -1), $ms1, $code2, $bMs, $loserMs, $loserMsg, $rows);
        if ($rows > 1) { $dupRows++; }
        if ($s1 >= 500 || ($aWon ? 200 : $s1) >= 500) { $fiveHund++; }
        if ($rows === 1 && $loserMsg === '该 QQ 号已注册，请直接登录') {
            // 注册是「先查库再收图」：被前置查重挡住的那一条根本不做压缩，回来很快；
            // 真走到插入才撞键的那一条已经收完并压缩过这张图，必然慢。用这个把两条分支分开。
            if ($loserMs > 400) { $slowFail++; } else { $fastFail++; }
        }
        $orphanAt = max($orphanAt, newOrphanCount());
    }
    @unlink($racePng); @unlink($ROOT . '/runtime/_race53635_curl.json');
    check('六轮里没有任何一轮插出重复行（唯一键兜住了并发）', $dupRows === 0, "插重轮次=$dupRows");
    check('六轮都没有 500（撞键那条给人话，不甩异常页）', $fiveHund === 0, "500 轮次=$fiveHund");
    check('至少有一轮真走到插入才撞键（输的一方是慢的那一条）', $slowFail >= 1,
        '慢失败=' . $slowFail . ' 快失败=' . $fastFail . '（全是快失败说明两边都被前置查重挡住了，没测到 catch 那一步）');
    check('撞键那一路没留下孤儿图片', $orphanAt === 0, "峰值新增孤儿=$orphanAt");
    say('并发窗口统计：真撞插入(慢失败) ' . $slowFail . ' 轮，前置查重挡住(快失败) ' . $fastFail . ' 轮');
}

echo "\n== 9. 注册成功那一路照旧（对照，别把正常路改坏） ==\n";
clearCache();
$newQq = '9967770001';
$before = diskFiles();
[$s, $j] = post_form($BASE, '/aga/api/auth/register',
    ['qq' => $newQq, 'password' => 'Guard12345', 'confirm' => 'Guard12345'], $png);
$after = diskFiles();
check('干净 QQ 注册成功', (int)($j['code'] ?? -1) === 0, J($j));
check('新建的这行是 pending（等审核）', (string)val("SELECT status FROM users WHERE tenant_id=$aTid AND username='$newQq'") === 'pending');
check('它带着一张图，且这张图被它自己引用着', count($after) === count($before) + 1 && newOrphanCount() === 0,
    'before=' . count($before) . ' after=' . count($after) . ' 新增孤儿=' . newOrphanCount());

// ============================================================
// 10. 读侧：脏 id 不许把外队的名字带出来
// ============================================================
echo "\n== 10. 脏 id 注入：外队的小队名 / 外队的昵称 ==\n";
// 故意造脏数据：甲队这个成员的 team_id 指向乙队的小队；甲队这条战绩的 user_id 指向乙队的人
$pdo->exec("UPDATE users SET team_id = $bTeam WHERE id = $aPlain");
$pdo->exec("INSERT INTO player_stats (tenant_id, user_id, matches, win_rate, kd, kills, avg_score, created_at, updated_at)
    VALUES ($aTid, $bGhost, 66, 99.00, 9.90, 999, 9.90, '$now', '$now')");
say("注入：users($aPlain).team_id → 乙队小队 {$bTeam}；player_stats(tenant=$aTid).user_id → 乙队的人 $bGhost");

echo "\n== 11. 论坛只读流 /api/open/forum/members（这四条只挂站点根，不带战队前缀） ==\n";
$ctx = stream_context_create(['http' => ['method' => 'GET', 'header' => "X-Api-Key: {$KEY['plain']}\r\n",
    'ignore_errors' => true, 'timeout' => 60]]);
$rawFeed = (string)@file_get_contents($BASE . '/api/open/forum/members', false, $ctx);
$feed = json_decode($rawFeed, true);
say('信封 code=' . (int)($feed['code'] ?? -1) . ' msg=' . (string)($feed['msg'] ?? '') . ' 正文头=' . mb_substr($rawFeed, 0, 90));
$list = (array)($feed['data']['list'] ?? []);
$blob = (string)json_encode($list, JSON_UNESCAPED_UNICODE);
say('拿到的成员条数=' . count($list) . '；含「乙队不该出现的队名」=' . (strpos($blob, '乙队不该出现的队名') !== false ? '是' : '否'));
check('只读流打得通且给了成员（不是空表糊过去）', count($list) >= 2, '条数=' . count($list) . ' 正文=' . mb_substr($rawFeed, 0, 160));
check('外队的小队名没被带进甲队的只读流', strpos($blob, '乙队不该出现的队名') === false, mb_substr($blob, 0, 200));
check('本队那个被挂脏 team_id 的人仍然在列表里（不是靠少给数据来收口）',
    strpos($blob, '甲队普通成员') !== false, mb_substr($blob, 0, 200));
check('甲队正常那条的小队名照旧出得来（对照）', strpos($blob, '甲队夜袭组') !== false, mb_substr($blob, 0, 200));

echo "\n== 12. 成员导出 CSV（同一条脏 team_id） ==\n";
[$s, $j, $hd, $ms] = as_req('aBoss', 'GET', '/aga/api/admin/export', null, $BASE);
[$s2, $j2, $hd2, $ms2] = as_req('aBoss', 'GET', '/aga/api/admin/export/file?kind=members&fmt=csv', null, $BASE);
$csv = (string)($j2['raw'] ?? '');   // CSV 不是 JSON，as_req 把整份正文塞进 raw
printf("  导出页 http=%d，成员 CSV http=%d，CSV 长度=%d\n", $s, $s2, strlen($csv));
check('CSV 打得开且非空', strlen($csv) > 50, 'len=' . strlen($csv));
check('CSV 里没有外队的小队名', strpos($csv, '乙队不该出现的队名') === false, mb_substr($csv, 0, 200));
check('CSV 里本队小队名照旧（对照）', strpos($csv, '甲队夜袭组') !== false, mb_substr($csv, 0, 300));

echo "\n== 13. QQ 群战绩榜（脏 user_id） ==\n";
foreach (glob($ROOT . '/runtime/bot/*.log') ?: [] as $f) { @unlink($f); }
$evt = ['op' => 0, 't' => 'GROUP_AT_MESSAGE_CREATE', 'd' => [
    'id' => 'g53635-' . bin2hex(random_bytes(6)),
    'group_openid' => 'group-G53635',
    'author' => ['member_openid' => 'OMEM-G53635'],
    'content' => '<@!BOT> 战绩榜',
]];
[$s, $j] = as_req('__bot', 'POST', '/api/bot/callback/g53635_key', $evt, $BASE);
printf("  回调 http=%d code=%d\n", $s, (int)($j['code'] ?? -1));
$logF = $ROOT . '/runtime/bot/bot_' . date('Ymd') . '.log';
$text = '';
foreach (array_slice((array)@file($logF, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES), -50) as $line) {
    $e = json_decode((string)$line, true);
    if (is_array($e) && ($e['type'] ?? '') === 'mock_send' && ($e['key'] ?? '') === 'g53635_key') { $text = (string)($e['text'] ?? ''); }
}
say('群里收到的正文：' . str_replace("\n", ' / ', mb_substr($text, 0, 160)));
check('战绩榜打得出来（不是空回复）', $text !== '', '日志正文为空');
check('外队那个人的昵称没混进甲队的榜', strpos($text, '乙队绝不外露的人') === false, mb_substr($text, 0, 200));
// 加了 u.tenant_id = s.tenant_id 之后，那条挂着外队 user_id 的战绩在 ON 里就配不上人，
// 而 where('u.status')/where('u.stats_public') 又挂在被 join 出来的那一侧 → 这一条整体不上榜。
// 这不是「落回占位」：那个 COALESCE(…, '已注销用户') 的分支本来就轮不到（同一段 WHERE 早就把
// 配不上人的行滤掉了，这是这版之前就有的行为，我没动它）。要验的是脏数据既不冒充本队的人，
// 也不把外队那串夸张数字端进群里。
check('脏那条既不上榜、也不把它的数字带进来', strpos($text, '9.9') === false && strpos($text, '999') === false
    && strpos($text, '66') === false, mb_substr($text, 0, 240));
check('甲队自己那位照旧在榜上（对照）', strpos($text, '甲队该露脸的') !== false, mb_substr($text, 0, 200));
check('榜上只有本队那一位（脏 id 没变成第二条）', substr_count(trim($text), '　平均评分') === 1,
    '匹配数=' . substr_count(trim($text), '　平均评分') . ' 正文=' . mb_substr($text, 0, 240));

echo "\n== 14. 零 DDL 与结构护栏 ==\n";
$colsU = count(rows("PRAGMA table_info(users)"));
$colsP = count(rows("PRAGMA table_info(password_resets)"));
check('users 列数还是 25（这一版不动库）', $colsU === 25, "实际=$colsU");
check('password_resets 列数还是 7（这一版不动库）', $colsP === 7, "实际=$colsP");
check('users 上那个唯一键还在', (bool)array_filter(rows("PRAGMA index_list(users)"),
    static function (array $r): bool {
        if ((int)$r['unique'] !== 1) { return false; }
        $c = array_map(static fn($x) => (string)$x['name'], rows("PRAGMA index_info({$r['name']})"));
        return $c === ['tenant_id', 'username'];
    }));
check('password_resets 上 (tenant_id, user_id) 唯一键还在', (bool)array_filter(rows("PRAGMA index_list(password_resets)"),
    static function (array $r): bool {
        if ((int)$r['unique'] !== 1) { return false; }
        $c = array_map(static fn($x) => (string)$x['name'], rows("PRAGMA index_info({$r['name']})"));
        return $c === ['tenant_id', 'user_id'];
    }));
check('这一套打完仍然是零孤儿', newOrphanCount() === 0, '本轮新增孤儿=' . newOrphanCount());

echo "\n== 15. ImageUpload::validate 与 save 的文案必须同源（防止以后只改一处） ==\n";
$iu = (string)file_get_contents($ROOT . '/app/service/ImageUpload.php');
$posV = strpos($iu, 'public static function validate(');
$posS = strpos($iu, 'public static function save(');
check('两个方法都在，且 save 在 validate 之后（save 里调的是 validate）',
    $posV !== false && $posS !== false && $posS > $posV && strpos($iu, 'self::validate($file)') !== false);
// 校验文案必须只有一个出处：以后有人只改 validate() 不改 save()（或反过来），
// 「查无此人就不报错」那类账号存在性探针就会长回来。按方法体切开逐条数。
$vBody = substr($iu, (int)$posV, (int)$posS - (int)$posV);
$sBody = substr($iu, (int)$posS);
foreach (['请选择要上传的图片', '仅支持 jpg / png / gif / webp 格式图片', '图片大小不能超过 5MB',
          '文件内容不是有效图片，已拒绝', '文件内容与声明的图片格式不符，已拒绝'] as $needle) {
    $inV = substr_count($vBody, "return '" . $needle . "';");
    $inS = substr_count($sBody, "'" . $needle . "'");
    // 「仅支持 jpg / png / gif / webp 格式图片」在 validate() 里有两个出处（声明的类型不对、
    // 文件头嗅出来的类型不对），所以这里问的是「至少一处 + save 里一处都没有」。
    check("「{$needle}」只在 validate() 里 return（save() 里一个字都不出现）", $inV >= 1 && $inS === 0,
        'validate 里=' . $inV . ' save 里=' . $inS);
}
check('save() 里只剩配额、目录与落盘那几条错误（校验确实搬走了）',
    substr_count($sBody, '上传目录创建失败') === 1 && substr_count($sBody, '图片保存失败') === 1
    && substr_count($sBody, 'ensureRoom(') === 1 && substr_count($vBody, 'ensureRoom(') === 0,
    'save 的 ensureRoom=' . substr_count($sBody, 'ensureRoom(') . ' validate 的=' . substr_count($vBody, 'ensureRoom('));

// ============================================================
// 16. 未登录上传接口：最前面那道按 Content-Length 的闸
// ============================================================
echo "\n== 16. 未登录上传接口：先按 Content-Length 早退 ==\n";
clearCache();
// 前面几节已经给 9960000101 / 9960000102 各留了一张待审单，这里要的是「正常那一发真能落库」，
// 所以另起两个干净账号，行数才对得准。
$freshA = $mkUser($aTid, '9960000161', '体积闸对照甲一');
$freshB = $mkUser($aTid, '9960000162', '体积闸对照甲二');
// 造一个「图片完全合规、整份表单却很大」的请求：正文塞 7MB 文本，文件只有 1KB 的合规小图。
// 没有这道闸时它会一路走进后续逻辑（文件本身不到 5MB，ImageUpload 那关根本拦不住它）；
// 有了这道闸就该在限流计数、图片校验、查库、落盘之前直接回同一句超限文案。
$padBig = str_repeat('x', 7 * 1024 * 1024);
$padOk  = str_repeat('x', 1024 * 1024);
$d16 = diskFiles();
$u16 = (int)val('SELECT COUNT(*) FROM users');
$p16 = (int)val('SELECT COUNT(*) FROM password_resets');
$forgotForm = static function (string $qq, string $pad) use ($BASE, $png): array {
    return post_form($BASE, '/aga/api/auth/forgot',
        ['qq' => $qq, 'password' => 'Guard12345', 'confirm' => 'Guard12345', 'filler' => $pad], $png);
};
[$s, $j, $ms] = $forgotForm('9960000161', $padBig);
printf("  7MB 正文 + 1KB 合规小图：http=%d code=%d 耗时=%dms 文案=%s\n", $s, (int)($j['code'] ?? -1), $ms, (string)($j['msg'] ?? ''));
check('超限那一发回的是「图片大小不能超过 5MB」（与 ImageUpload 同一句，不新增可分辨信号）',
    (string)($j['msg'] ?? '') === '图片大小不能超过 5MB', J($j));
check('超限那一发不落盘', count(diskFiles()) === count($d16), '本轮至今新增文件=' . newFileCount());
check('超限那一发不写库（在册账号也一样先被体积挡下）',
    (int)val('SELECT COUNT(*) FROM users') === $u16 && (int)val('SELECT COUNT(*) FROM password_resets') === $p16,
    'users=' . (int)val('SELECT COUNT(*) FROM users') . ' resets=' . (int)val('SELECT COUNT(*) FROM password_resets'));
[$s, $j, $ms] = $forgotForm('9960000161', $padOk);
check('同样的字段、同一张图，只把正文缩到闸下 → 照常受理（拒的是体积，不是这个字段）',
    (string)($j['msg'] ?? '') === '申请已受理，管理员审核通过后即可使用新密码登录', J($j));
check('闸下那一发真走完并落了库（上一条对照才成立）',
    (int)val('SELECT COUNT(*) FROM password_resets') === $p16 + 1,
    'resets=' . (int)val('SELECT COUNT(*) FROM password_resets'));

[$s, $j, $ms] = post_form($BASE, '/aga/api/auth/register',
    ['qq' => '9969990202', 'nickname' => '超限不该建号', 'password' => 'Guard12345', 'confirm' => 'Guard12345',
     'filler' => $padBig], $png);
check('注册那侧同样先按体积早退', (string)($j['msg'] ?? '') === '图片大小不能超过 5MB', J($j));
check('超限的注册没有建号', (int)val("SELECT COUNT(*) FROM users WHERE username='9969990202'") === 0);
check('超限的注册也没留下文件', count(diskFiles()) === count($d16) + 1,
    '期望只多第 16 节那一张，实际本轮至今=' . newFileCount());
clearCache();
$burn = 0;
for ($i = 0; $i < 5; $i++) { $forgotForm('9960000162', $padBig); $burn++; }
[$s, $j, $ms] = $forgotForm('9960000162', $padOk);
$pAfter = (int)val('SELECT COUNT(*) FROM password_resets');
check('连打 ' . $burn . ' 发超限之后，正常那一发仍然受理（闸排在限流计数之前，超限的那些不吃额度）',
    (string)($j['msg'] ?? '') === '申请已受理，管理员审核通过后即可使用新密码登录' && $pAfter === $p16 + 2,
    'resets=' . $pAfter . ' 期望=' . ($p16 + 2) . ' ' . J($j));

// ============================================================
// 17. 按 id 改数据：find() 之后再显式比一次归属（两份真实账号互打）
// ============================================================
echo "\n== 17. 按 id 改数据：显式比一次归属 ==\n";
require_once $ROOT . '/app/service/TenantContext.php';
$stub = static function ($tid) {
    return new class($tid) {
        private $t;
        public function __construct($t) { $this->t = $t; }
        public function getData(?string $name = null) { return $name === 'tenant_id' ? $this->t : null; }
    };
};
\app\service\TenantContext::set(['id' => $aTid, 'slug' => 'aga', 'name' => '上传甲队', 'logo' => '', 'status' => 'approved']);
check('helper：本队那一行 → 判为属于本队', tenant_owns($stub($aTid)) === true);
check('helper：外队那一行 → 判为不属于本队', tenant_owns($stub($bTid)) === false);
check('helper：行上取不到 tenant_id（脏数据/字段没带出来）→ 失败即拒，不放行', tenant_owns($stub(null)) === false);
check('helper：根本没查到行 → 同样判不属于', tenant_owns(null) === false);

// 乙队的一批真实数据：甲队拿 id 打过去必须全被挡；同一批 id 换乙队站长打必须照常成功
$pdo->exec("INSERT INTO blacklist (tenant_id, qq, nickname, reason, created_by, created_at, updated_at)
    VALUES ($bTid, '9961000999', '乙队的黑号', '外队条目甲队碰不到', '乙队站长', '$now', '$now')");
$bBl = (int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO role_tags (tenant_id, name, color, created_at, updated_at)
    VALUES ($bTid, '乙队专有的标签', '#123456', '$now', '$now')");
$bTag = (int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO announcements (tenant_id, title, content, created_at, updated_at)
    VALUES ($bTid, '乙队公告', '正文在这里', '$now', '$now')");
$bAnn = (int)$pdo->lastInsertId();
$imgDay = date('Ymd');
$imgDir = $UP . '/images/' . $imgDay;
if (!is_dir($imgDir)) { @mkdir($imgDir, 0755, true); }
$bImgName = 'b53635_' . bin2hex(random_bytes(6)) . '.png';
$bImgAbs  = $imgDir . '/' . $bImgName;
$bImgPath = '/storage/uploads/images/' . $imgDay . '/' . $bImgName;
file_put_contents($bImgAbs, $png);
$pdo->exec("INSERT INTO images (tenant_id, user_id, title, file, status, created_at, updated_at)
    VALUES ($bTid, $bGhost, '乙队的一张图', '$bImgPath', 'approved', '$now', '$now')");
$bImg = (int)$pdo->lastInsertId();
$pwBefore = (string)val("SELECT password FROM users WHERE id=$bGhost");
$pre = [
    'user'  => (int)val("SELECT COUNT(*) FROM users WHERE id=$bGhost AND tenant_id=$bTid"),
    'bl'    => (int)val("SELECT COUNT(*) FROM blacklist WHERE id=$bBl AND tenant_id=$bTid"),
    'tag'   => (int)val("SELECT COUNT(*) FROM role_tags WHERE id=$bTag AND tenant_id=$bTid"),
    'team'  => (int)val("SELECT COUNT(*) FROM teams WHERE id=$bTeam AND tenant_id=$bTid"),
    'ann'   => (int)val("SELECT COUNT(*) FROM announcements WHERE id=$bAnn AND tenant_id=$bTid"),
    'img'   => (int)val("SELECT COUNT(*) FROM images WHERE id=$bImg AND tenant_id=$bTid"),
];
check('前提：这批 id 在全库里都是真实存在的行、且都挂在乙队名下（下面拒的理由是归属，不是查无此 id）',
    min($pre) === 1, J($pre));

/** 甲队站长拿着乙队的 id 打过来：只许得到「不存在」那一句，且那一行原样不动 */
$denied = static function (string $label, string $method, string $path, ?array $body,
                             string $expectMsg, callable $untouched) use ($BASE): void {
    [$st, $j] = as_req('aBoss', $method, '/aga/api/admin' . $path, $body, $BASE);
    $code = (int)($j['code'] ?? -1);
    check($label . '：甲队站长拿乙队的 id 打过去，只得到「' . $expectMsg . '」',
        $code !== 0 && (string)($j['msg'] ?? '') === $expectMsg, 'http=' . $st . ' ' . J($j));
    check($label . '：乙队那一行没被动过', $untouched() === true);
};
$denied('成员改状态', 'POST', '/users/' . $bGhost . '/status', ['status' => 'banned'], '用户不存在',
    static function () use ($bGhost): bool { return (string)val("SELECT status FROM users WHERE id=$bGhost") === 'approved'; });
$denied('成员改名', 'POST', '/users/' . $bGhost . '/rename', ['nickname' => '被甲队改掉的名字'], '用户不存在',
    static function () use ($bGhost): bool { return (string)val("SELECT nickname FROM users WHERE id=$bGhost") === '乙队绝不外露的人'; });
$denied('成员重置密码', 'POST', '/users/' . $bGhost . '/password', ['password' => 'Hijack12345', 'confirm' => 'Hijack12345'], '用户不存在',
    static function () use ($bGhost, $pwBefore): bool { return (string)val("SELECT password FROM users WHERE id=$bGhost") === $pwBefore; });
$denied('成员禁言', 'POST', '/users/' . $bGhost . '/mute', ['muted' => 1], '用户不存在',
    static function () use ($bGhost): bool { return (int)val("SELECT muted FROM users WHERE id=$bGhost") === 0; });
$denied('删成员', 'DELETE', '/users/' . $bGhost, null, '用户不存在',
    static function () use ($bGhost): bool { return (int)val("SELECT COUNT(*) FROM users WHERE id=$bGhost") === 1; });
$denied('给成员分标签', 'POST', '/users/' . $bGhost . '/role-tags', ['tag_ids' => [$bTag]], '用户不存在',
    static function () use ($bGhost): bool { return (string)val("SELECT role_tags FROM users WHERE id=$bGhost") === ''; });
$denied('移出黑名单', 'DELETE', '/blacklist/' . $bBl, null, '记录不存在',
    static function () use ($bBl): bool { return (int)val("SELECT COUNT(*) FROM blacklist WHERE id=$bBl") === 1; });
$denied('编辑黑名单', 'POST', '/blacklist/save', ['id' => $bBl, 'qq' => '9961000999', 'reason' => '甲队改写的原因'], '记录不存在',
    static function () use ($bBl): bool { return (string)val("SELECT reason FROM blacklist WHERE id=$bBl") === '外队条目甲队碰不到'; });
$denied('删职责标签', 'DELETE', '/role-tags/' . $bTag, null, '标签不存在',
    static function () use ($bTag): bool { return (int)val("SELECT COUNT(*) FROM role_tags WHERE id=$bTag") === 1; });
$denied('解散小队', 'DELETE', '/teams/' . $bTeam, null, '小队不存在',
    static function () use ($bTeam): bool { return (int)val("SELECT COUNT(*) FROM teams WHERE id=$bTeam") === 1; });
$denied('撤回公告', 'POST', '/announcements/' . $bAnn . '/revoke', null, '公告不存在',
    static function () use ($bAnn): bool { $v = val("SELECT revoked_at FROM announcements WHERE id=$bAnn"); return $v === null || (string)$v === ''; });
$denied('删图片（连物理文件）', 'DELETE', '/images/' . $bImg, null, '图片不存在',
    static function () use ($bImg, $bImgAbs): bool {
        return (int)val("SELECT COUNT(*) FROM images WHERE id=$bImg") === 1 && is_file($bImgAbs);
    });

// 同一批 id 换成本队站长来打：必须打得动——「拒」的理由才是租户不同
[$st, $j] = as_req('bBoss', 'POST', '/bga/api/admin/users/' . $bGhost . '/rename', ['nickname' => '乙队自己改的名'], $BASE);
check('对照：乙队站长改乙队的人 → 成功（接口和 id 都没问题，拒的是归属）', (int)($j['code'] ?? 1) === 0, J($j));
check('对照真改到了库里', (string)val("SELECT nickname FROM users WHERE id=$bGhost") === '乙队自己改的名');
[$st, $j] = as_req('bBoss', 'POST', '/bga/api/admin/users/' . $bGhost . '/password',
    ['password' => 'Owner56789', 'confirm' => 'Owner56789'], $BASE);
check('对照：乙队站长重置乙队的人的密码 → 成功', (int)($j['code'] ?? 1) === 0, J($j));
check('密码哈希真的换了', (string)val("SELECT password FROM users WHERE id=$bGhost") !== $pwBefore);
[$st, $j] = as_req('bBoss', 'DELETE', '/bga/api/admin/blacklist/' . $bBl, null, $BASE);
check('对照：乙队站长删得掉自己那条黑名单', (int)($j['code'] ?? 1) === 0, J($j));
check('库里那一条随之没了', (int)val("SELECT COUNT(*) FROM blacklist WHERE id=$bBl") === 0);
[$st, $j] = as_req('bBoss', 'DELETE', '/bga/api/admin/images/' . $bImg, null, $BASE);
check('对照：乙队站长删得掉自己那张图', (int)($j['code'] ?? 1) === 0, J($j));
clearstatcache(true, $bImgAbs);
check('文件也跟着删了（按引用删，不是按目录扫）', !is_file($bImgAbs), '仍在：' . $bImgAbs);
[$st, $j] = as_req('bBoss', 'POST', '/bga/api/admin/announcements/' . $bAnn . '/revoke', null, $BASE);
check('对照：乙队站长撤得掉自己的公告', (int)($j['code'] ?? 1) === 0, J($j));

// 自家人不能被判错：甲队站长对甲队的 id 全部照常
[$st, $j] = as_req('aBoss', 'POST', '/aga/api/admin/users/' . $aPlain . '/rename', ['nickname' => '甲队自己改的名'], $BASE);
check('同队对照：甲队站长改甲队的人照常成功（这道闸没把自家人挡掉）', (int)($j['code'] ?? 1) === 0, J($j));
[$st, $j] = as_req('aBoss', 'POST', '/aga/api/admin/users/' . $aPlain . '/status', ['status' => 'banned'], $BASE);
check('同队对照：甲队站长封甲队的人照常成功', (int)($j['code'] ?? 1) === 0, J($j));
check('封禁落库了', (string)val("SELECT status FROM users WHERE id=$aPlain") === 'banned');
[$st, $j] = as_req('aBoss', 'POST', '/aga/api/admin/users/' . $aPlain . '/status', ['status' => 'approved'], $BASE);
check('同队对照：再解封也照常', (int)($j['code'] ?? 1) === 0, J($j));
[$st, $j] = as_req('aBoss', 'POST', '/aga/api/admin/blacklist/save', ['qq' => '9960000888', 'reason' => '甲队自己加的'], $BASE);
check('同队对照：甲队自己加黑名单照常成功', (int)($j['code'] ?? 1) === 0, J($j));
$aBl = (int)val("SELECT id FROM blacklist WHERE tenant_id=$aTid AND qq='9960000888'");
[$st, $j] = as_req('aBoss', 'DELETE', '/aga/api/admin/blacklist/' . $aBl, null, $BASE);
check('同队对照：甲队自己删也照常', (int)($j['code'] ?? 1) === 0, J($j));
// 这一节自己造的数据不能变成孤儿（乙队那张图已由 bBoss 删掉）
check('第 17 节打完仍是零孤儿', newOrphanCount() === 0, '本轮新增孤儿=' . newOrphanCount());

// ============================================================
// 18. 形态护栏：这一版收的口子不许悄悄退化
// ============================================================
echo "\n== 18. 形态护栏：JOIN 归属、闸的顺序、nginx 示例 ==\n";
$joinLines = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($ROOT . '/app', FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if (!$f->isFile() || strtolower($f->getExtension()) !== 'php') { continue; }
    $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($ROOT) + 1));
    foreach (file($f->getPathname()) as $n => $line) {
        if (strpos($line, 'Join(') !== false || strpos($line, '->join(') !== false) {
            $joinLines[] = $rel . ':' . ($n + 1) . ':' . trim($line);
        }
    }
}
$noTenant = array_values(array_filter($joinLines,
    static fn(string $l): bool => stripos($l, 'canJoin') === false && stripos($l, 'tenant_id') === false));
check('app/ 下 ' . count($joinLines) . ' 处 Join，除 GameSide::canJoin 那几处，每一处的 ON 里都写了 tenant_id',
    $noTenant === [] && count($joinLines) >= 23, '漏掉的那几处：' . implode(' | ', $noTenant));
$ownCnt = 0;
foreach (['app/controller/Admin.php' => 24, 'app/controller/BlacklistAdmin.php' => 2] as $rel => $least) {
    $c = substr_count((string)file_get_contents($ROOT . '/' . $rel), '!tenant_owns(');
    $ownCnt += $c;
    check("$rel 里显式归属断言不少于 $least 处", $c >= $least, "实际=$c");
}
check('tenant_owns() 定义在 app/common.php，且取的是真实列 getData',
    strpos((string)file_get_contents($ROOT . '/app/common.php'), "function tenant_owns(\$row): bool") !== false
    && strpos((string)file_get_contents($ROOT . '/app/common.php'), "getData('tenant_id')") !== false);
$auth = (string)file_get_contents($ROOT . '/app/controller/Auth.php');
$pGate = strpos($auth, 'private function oversizedBody');
$pIp   = strpos($auth, 'private function checkIpBlocked');
check('oversizedBody() 定义在 checkIpBlocked() 之前（两处调用点都能排在最前面）',
    $pGate !== false && $pIp !== false && $pGate < $pIp);
$regBody = substr($auth, (int)strpos($auth, 'public function register()'), (int)strpos($auth, 'public function login()') - (int)strpos($auth, 'public function register()'));
$fgtBody = substr($auth, (int)strpos($auth, 'public function forgot()'), (int)strpos($auth, 'public function resetOptions()') - (int)strpos($auth, 'public function forgot()'));
foreach (['register' => $regBody, 'forgot' => $fgtBody] as $name => $body) {
    $g = strpos($body, '$this->oversizedBody()');
    $r = strpos($body, '$this->checkIpBlocked()');
    check("$name() 里这道体积闸排在最前面（早于 IP 封禁、限流与一切校验）", $g !== false && $r !== false && $g < $r,
        '闸=' . var_export($g, true) . ' IP=' . var_export($r, true));
}
$ng = (string)file_get_contents($ROOT . '/deploy/nginx.conf.example');
check('nginx 示例给了认证接口的速率闸：http 层一条 zone 定义，那条 location 用上它一次',
    preg_match('/^[ \t]*limit_req_zone[ \t]+\\$binary_remote_addr[ \t]+zone=auth_rate:10m[ \t]+rate=/m', $ng) === 1
    && substr_count($ng, 'limit_req zone=auth_rate burst=') === 1);
check('示例里全站默认请求体是 8m，数据包导入单独 128m',
    strpos($ng, 'client_max_body_size 8m;') !== false && strpos($ng, 'client_max_body_size 128m;') !== false);
check('示例自带提醒：这两段本机没实测，改完先 nginx -t', strpos($ng, 'nginx -t') !== false);

// ============================================================
// 19. 改 ON 的真实风险不在「外队的进不来」，而在「本队的也配不上了」
//     所以这一段专做对照：这些列表里，本队那条必须照旧带出真名；
//     同时挂脏 id 的那条只许落回占位「已注销用户」，不许把外队昵称端进来。
// ============================================================
echo "\n== 19. 十处 JOIN 的对照：本队真名照旧出得来 ==\n";
$login('aPub', 'aga', '9960000101');
$today = date('Y-m-d');
$pdo->exec("INSERT INTO chat_messages (tenant_id, user_id, content, created_at) VALUES
    ($aTid, $aPub, '本队正常发言', '$now'), ($aTid, $bGhost, '外队脏数据发言', '$now')");
$pdo->exec("INSERT INTO guides (tenant_id, user_id, title, content, status, created_at, updated_at) VALUES
    ($aTid, $aPub, '本队正常攻略', '正文', 'published', '$now', '$now'),
    ($aTid, $bGhost, '外队脏攻略', '正文', 'published', '$now', '$now')");
$pdo->exec("INSERT INTO gun_codes (tenant_id, user_id, title, code, description, status, category, created_at, updated_at) VALUES
    ($aTid, $aPub, '本队正常枪码', 'AAA111', '说明', 'published', '', '$now', '$now'),
    ($aTid, $bGhost, '外队脏枪码', 'BBB222', '说明', 'published', '', '$now', '$now')");
$gcClean = (int)val("SELECT id FROM gun_codes WHERE tenant_id=$aTid AND title='本队正常枪码'");
$pdo->exec("INSERT INTO gun_code_comments (tenant_id, gun_code_id, user_id, content, created_at) VALUES
    ($aTid, $gcClean, $aPub, '本队正常评论', '$now'), ($aTid, $gcClean, $bGhost, '外队脏评论', '$now')");
$pdo->exec("INSERT INTO checkins (tenant_id, user_id, checkin_date, streak, created_at)
    VALUES ($aTid, $aPub, '$today', 1, '$now')");
$pdo->exec("INSERT INTO images (tenant_id, user_id, title, file, status, created_at, updated_at) VALUES
    ($aTid, $aPub, '本队图只登记不写文件', 'x', 'approved', '$now', '$now'),
    ($aTid, $bGhost, '外队脏图只登记不写文件', 'x', 'approved', '$now', '$now'),
    ($aTid, $aPub, '本队待审图', 'x', 'pending', '$now', '$now'),
    ($aTid, $bGhost, '外队脏待审图', 'x', 'pending', '$now', '$now')");
$pdo->exec("INSERT INTO bot_user_bindings (tenant_id, user_id, member_openid, created_at) VALUES
    ($aTid, $aPub, 'OMEM-CLEAN-53635', '$now'), ($aTid, $bGhost, 'OMEM-DIRTY-53635', '$now')");
// 这几张 images 是「只登记、不写真文件」的行（file 填个占位的 x）：这一节验的是 JOIN 出来的名字，
// 不是磁盘。referenced() 本来也不看 images 表，盘上没有对应文件 → 不会算进孤儿。
$findIn = static function (array $resp, string $field, string $value, string $listKey = 'list'): ?array {
    foreach ((array)($resp['data'][$listKey] ?? []) as $r) {
        if ((string)($r[$field] ?? '') === $value) { return (array)$r; }
    }
    return null;
};
$SPECS = [
    ['战队群聊天',   'aPub', '/aga/api/chat/messages',                        'content', '本队正常发言',   '外队脏数据发言', 'nickname'],
    ['攻略列表',     'aPub', '/aga/api/guides',                               'title',   '本队正常攻略',   '外队脏攻略',     'author'],
    ['枪码列表',     'aPub', '/aga/api/guncodes',                             'title',   '本队正常枪码',   '外队脏枪码',     'author'],
    ['枪码评论',     'aPub', '/aga/api/guncodes/' . $gcClean . '/comments',    'content', '本队正常评论',   '外队脏评论',     'nickname'],
    ['图库列表',     'aPub', '/aga/api/images',                               'title',   '本队图只登记不写文件', '外队脏图只登记不写文件', 'uploader'],
    ['管理端图片',   'aBoss', '/aga/api/admin/images?status=pending',          'title',   '本队待审图',     '外队脏待审图',   'uploader'],
    ['机器人绑定',   'aBoss', '/aga/api/admin/bot/members',                   'user_id', (string)$aPub,    (string)$bGhost,  'nickname'],
];
foreach ($SPECS as $spec) {
    [$label, $who, $path, $key, $cleanVal, $dirtyVal, $nameField] = $spec;
    [$st, $j] = as_req($who, 'GET', $path, null, $BASE);
    $list = (array)($j['data']['list'] ?? []);
    printf("  %-10s http=%d 条数=%d\n", $label, $st, count($list));
    $clean = $findIn($j, $key, $cleanVal);
    check($label . '：本队那条照旧带出真名（补上另一侧租户没把自家人配空）',
        is_array($clean) && (string)($clean[$nameField] ?? '') === '甲队该露脸的',
        is_array($clean) ? ($nameField . '=' . (string)($clean[$nameField] ?? '')) : ('没找到那一行 ' . mb_substr(J($j), 0, 160)));
    $dirty = $findIn($j, $key, $dirtyVal);
    check($label . '：挂外队 id 那条只落回占位，外队昵称一个字都不出现',
        is_array($dirty) && (string)($dirty[$nameField] ?? '') === '已注销用户'
        && strpos(J($j), '乙队绝不外露的人') === false,
        is_array($dirty) ? ($nameField . '=' . (string)($dirty[$nameField] ?? '')) : '没找到脏那一行');
}
[$st, $j] = as_req('aPub', 'GET', '/aga/api/checkin', null, $BASE);
$tt = (array)($j['data']['team_top'] ?? []);
check('签到前三名：本队那位照旧带出真名（checkins 连 users 那处 JOIN 的对照）',
    $tt !== [] && (string)($tt[0]['nickname'] ?? '') === '甲队该露脸的', J($tt));
[$st, $j] = as_req('aBoss', 'GET', '/aga/api/admin/users?size=50', null, $BASE);
$blob = J($j);
check('管理端成员表：本队小队名照旧出得来（teams 那处 JOIN 的对照）', strpos($blob, '甲队夜袭组') !== false,
    mb_substr($blob, 0, 200));
check('管理端成员表：那个被挂上外队 team_id 的人不会带出乙队的队名', strpos($blob, '乙队不该出现的队名') === false,
    mb_substr($blob, 0, 200));

echo "\n== 汇总 ==\n";
printf("PASS %d / FAIL %d\n", $pass, $fail);
foreach ($failures as $i => $f) { echo '  ' . ($i + 1) . ". $f\n"; }
exit($fail > 0 ? 1 : 0);
