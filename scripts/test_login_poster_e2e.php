<?php
/**
 * 登录页配图（v5.12.0）功能 E2E 测试
 *
 * 前置：测试服务器已启动
 *   php -S 127.0.0.1:8899 -t public public/router.php      （.env 指向 sqlite）
 * 覆盖：
 *   1. 权限：未登录 401；普通成员 / 拿满权限的副管理员 403；只有站长可读能写
 *   2. 上传换图：落盘路径形态、物理文件存在、settings 值、登录/注册/找回密码三页都注入
 *   3. 语义细节：不选文件=保持不变；再次上传=换图并删掉旧文件；恢复默认=清值并删文件
 *   4. 非法输入：非图片扩展名、伪造扩展名的非图片内容都被拒
 *   5. 健壮性：库里的值被改坏时回落内置海报，坏值不进 HTML
 *   6. 多租户隔离：A 队换图不影响 B 队；既有接口回归
 *
 * 用法：php scripts/test_login_poster_e2e.php
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

$ROOT = dirname(__DIR__);
$BASE = rtrim((string)(getenv('TEST_BASE') ?: 'http://127.0.0.1:8899'), '/');
$DB   = $ROOT . '/runtime/test.sqlite';
$DEF  = '/static/img/login-poster.webp';

$pass = 0; $fail = 0; $failures = [];
function check(string $name, bool $ok, string $detail = ''): void {
    global $pass, $fail, $failures;
    if ($ok) { $pass++; echo "  [PASS] $name\n"; }
    else { $fail++; $failures[] = $name . ($detail !== '' ? " ($detail)" : ''); echo "  [FAIL] $name" . ($detail !== '' ? " —— $detail" : '') . "\n"; }
}

// ---------- HTTP：JSON / 原始 HTML / multipart ----------
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
    $hdr = '';
    if ($cookies) {
        $pairs = [];
        foreach ($cookies as $k => $v) { $pairs[] = $k . '=' . $v; }
        $hdr = 'Cookie: ' . implode('; ', $pairs) . "\r\n";
    }
    return $hdr;
}
/** @return array{0:int,1:array,2:string} [状态码, JSON, 原始文本] */
function req(string $method, string $url, ?array $body, array &$cookies): array {
    $hdr = "Content-Type: application/json\r\n" . cookie_hdr($cookies);
    $opts = ['http' => ['method' => $method, 'header' => $hdr, 'ignore_errors' => true, 'timeout' => 15]];
    if ($body !== null) { $opts['http']['content'] = json_encode($body, JSON_UNESCAPED_UNICODE); }
    $raw = @file_get_contents($url, false, stream_context_create($opts)) ?: '';
    take_cookies($http_response_header ?? [], $cookies);
    $json = json_decode((string)$raw, true);
    return [status_of($http_response_header ?? []), is_array($json) ? $json : ['raw' => (string)$raw], (string)$raw];
}
/** 取页面原文（登录页这类 HTML 直接读，不解析 JSON） */
function get_html(string $url, array &$cookies): array {
    $opts = ['http' => ['method' => 'GET', 'header' => cookie_hdr($cookies), 'ignore_errors' => true, 'timeout' => 15,
                        'follow_location' => 0]];
    $raw = @file_get_contents($url, false, stream_context_create($opts)) ?: '';
    take_cookies($http_response_header ?? [], $cookies);
    return [status_of($http_response_header ?? []), (string)$raw];
}
/** multipart POST：$file = [文件名, MIME, 字节] 或 null */
function post_form(string $url, array $fields, ?array $file, array &$cookies): array {
    $b = '----poster' . bin2hex(random_bytes(6));
    $body = '';
    foreach ($fields as $k => $v) {
        $body .= "--$b\r\nContent-Disposition: form-data; name=\"$k\"\r\n\r\n$v\r\n";
    }
    if ($file !== null) {
        $body .= "--$b\r\nContent-Disposition: form-data; name=\"file\"; filename=\"{$file[0]}\"\r\n"
               . "Content-Type: {$file[1]}\r\nContent-Transfer-Encoding: binary\r\n\r\n{$file[2]}\r\n";
    }
    $body .= "--$b--\r\n";
    $opts = ['http' => ['method' => 'POST',
        'header' => "Content-Type: multipart/form-data; boundary=$b\r\n" . cookie_hdr($cookies),
        'content' => $body, 'ignore_errors' => true, 'timeout' => 20]];
    $raw = @file_get_contents($url, false, stream_context_create($opts)) ?: '';
    take_cookies($http_response_header ?? [], $cookies);
    $json = json_decode((string)$raw, true);
    return [status_of($http_response_header ?? []), is_array($json) ? $json : ['raw' => (string)$raw]];
}

$dbj = []; $ownerJar = []; $fullJar = []; $memberJar = []; $otherJar = []; $htmlJar = [];
function owner_req($m, $p, $b = null)  { global $ownerJar, $BASE; return req($m, $BASE . $p, $b, $ownerJar); }
function full_req($m, $p, $b = null)   { global $fullJar, $BASE;  return req($m, $BASE . $p, $b, $fullJar); }
function mem_req($m, $p, $b = null)    { global $memberJar, $BASE; return req($m, $BASE . $p, $b, $memberJar); }
function other_req($m, $p, $b = null)  { global $otherJar, $BASE;  return req($m, $BASE . $p, $b, $otherJar); }
function owner_post($p, $fields = [], $file = null) { global $ownerJar, $BASE; return post_form($BASE . $p, $fields, $file, $ownerJar); }
function other_post($p, $fields = [], $file = null) { global $otherJar, $BASE; return post_form($BASE . $p, $fields, $file, $otherJar); }

function db(): PDO { global $DB; $p = new PDO('sqlite:' . $DB); $p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); return $p; }
function db_one(string $sql, array $p = []): ?array {
    $st = db()->prepare($sql); $st->execute($p); $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r === false ? null : $r;
}
/** settings 里本队的 login_poster 原值（行不存在返回 null） */
function setting_val(int $tid): ?string {
    $r = db_one('SELECT value FROM settings WHERE tenant_id = ? AND `key` = \'login_poster\'', [$tid]);
    return $r === null ? null : (string)$r['value'];
}
function abs_of(string $webPath): string { global $ROOT; return $ROOT . '/public' . str_replace('/', DIRECTORY_SEPARATOR, $webPath); }
/**
 * 文件是否已不存在。删除动作发生在测试服务器进程里，本进程的 stat 缓存会把它读成"还在"，
 * 必须先 clearstatcache 再判断，否则换图/恢复默认的清理断言会假失败。
 */
function gone(string $webPath): bool {
    $abs = abs_of($webPath);
    clearstatcache(true, $abs);
    return !is_file($abs);
}
/** 1x1 合法 PNG（服务端 finfo + getimagesize 都会认可） */
function png_bytes(): string {
    return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8AABQAB/79B0QAAAABJRU5ErkJggg==');
}

// ============================================================
// 0. 重建测试库 + 种子数据
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

$T1 = $mkTenant('pstmain', '配图主测战队', 'pstowner');
$T2 = $mkTenant('pstother', '配图干扰战队', 'pstother2');
$ownerUid = $mkUser($T1, 'pstowner',   'owner123456', '站长', 'admin');
$fullUid  = $mkUser($T1, 'pstfull',    'owner123456', '满权限管理', 'admin',
                    ['member_view', 'approve', 'ban', 'role_manage', 'rename', 'mute', 'reset_pwd',
                     'delete_user', 'multi', 'team', 'lottery', 'duty', 'role_tag', 'stats', 'announce']);
$memUid   = $mkUser($T1, '100030001',  'member123456', '普通成员', 'user');
$oOwner   = $mkUser($T2, 'pstother2',  'owner123456', '干扰队站长', 'admin');
$pdo->exec("UPDATE tenants SET admin_user_id = $ownerUid WHERE id = $T1");
$pdo->exec("UPDATE tenants SET admin_user_id = $oOwner WHERE id = $T2");
check('种子：两战队 + 站长 + 拿满权限的副管理员 + 普通成员', $T1 > 0 && $T2 > 0 && $ownerUid > 0 && $fullUid > 0 && $memUid > 0);

$P = '/pstmain/api/admin/poster';

// ============================================================
// 1. 权限
// ============================================================
echo "\n== 1. 谁能读写配图 ==\n";
[$s, $j] = req('GET', $BASE . $P, null, $dbj);
check('未登录读配图配置 → 401', ($j['code'] ?? 0) === 401 || $s === 401, "HTTP $s " . json_encode($j, JSON_UNESCAPED_UNICODE));

[$s, $j] = mem_req('POST', '/pstmain/api/auth/login', ['username' => '100030001', 'password' => 'member123456']);
check('普通成员登录', ($j['code'] ?? 1) === 0, $j['msg'] ?? '');
[$s, $j] = mem_req('GET', $P);
check('普通成员读配图被拒 403', ($j['code'] ?? 0) === 403 || ($j['code'] ?? 0) === 401, json_encode($j, JSON_UNESCAPED_UNICODE));

[$s, $j] = full_req('POST', '/pstmain/api/auth/login', ['username' => 'pstfull', 'password' => 'owner123456']);
check('拿满权限的副管理员登录', ($j['code'] ?? 1) === 0, $j['msg'] ?? '');
[$s, $j] = full_req('GET', $P);
check('权限拿满也不是真超管 → 读配图 403', ($j['code'] ?? 0) === 403, json_encode($j, JSON_UNESCAPED_UNICODE));
[$s, $j] = full_req('POST', $P, ['reset' => '1']);
check('副管理员改配图被拒（403，不是只挡读）', ($j['code'] ?? 0) === 403, json_encode($j, JSON_UNESCAPED_UNICODE));
check('被拒的写没有落库', setting_val($T1) === null);

[$s, $j] = owner_req('POST', '/pstmain/api/auth/login', ['username' => 'pstowner', 'password' => 'owner123456']);
check('站长登录', ($j['code'] ?? 1) === 0, $j['msg'] ?? '');
[$s, $j] = owner_req('GET', $P);
$d = $j['data'] ?? [];
check('站长读配置 code=0 且回 poster/custom/default 三项',
    ($j['code'] ?? 1) === 0 && ($d['poster'] ?? '') === $DEF && ($d['custom'] ?? 'x') === '' && ($d['default'] ?? '') === $DEF,
    json_encode($d, JSON_UNESCAPED_UNICODE));

// ============================================================
// 2. 上传换图
// ============================================================
echo "\n== 2. 站长上传换图 ==\n";
[$s, $j] = owner_post($P);
check('既没选文件也没要求恢复 → 保持不变而非清空',
    ($j['code'] ?? 1) === 0 && str_contains((string)($j['msg'] ?? ''), '保持不变') && ($j['data']['poster'] ?? '') === $DEF,
    json_encode($j, JSON_UNESCAPED_UNICODE));

[$s, $j] = owner_post($P, [], ['a.png', 'image/png', png_bytes()]);
$p1 = (string)($j['data']['poster'] ?? '');
check('上传 1x1 PNG 成功', ($j['code'] ?? 1) === 0 && $p1 !== '' && $p1 !== $DEF, json_encode($j, JSON_UNESCAPED_UNICODE));
check('落盘路径是 posters 子目录的 32位随机名(.png)',
    (bool)preg_match('#^/storage/uploads/posters/\d{8}/[a-f0-9]{32}\.png$#', $p1), $p1);
check('物理文件真的存在', is_file(abs_of($p1)), abs_of($p1));
check('settings 写入的是同一个路径（战队级键 login_poster）', setting_val($T1) === $p1, (string)setting_val($T1));
[$s, $j] = owner_req('GET', $P);
check('读配置回显自定义图', ($j['data']['custom'] ?? '') === $p1 && ($j['data']['poster'] ?? '') === $p1,
    json_encode($j['data'] ?? $j, JSON_UNESCAPED_UNICODE));

// 三个页面都要注入（登录 / 注册 / 找回密码——同一张海报）
foreach (['/pstmain/login' => '登录页', '/pstmain/register' => '注册页', '/pstmain/forgot' => '找回密码页'] as $u => $label) {
    [$hs, $html] = get_html($BASE . $u, $htmlJar);
    check("$label HTML 注入了自定义海报地址", $hs === 200 && str_contains($html, "LOGIN_POSTER = '$p1'"),
        "HTTP $hs " . (str_contains($html, 'LOGIN_POSTER') ? substr($html, strpos($html, 'LOGIN_POSTER'), 60) : 'no LOGIN_POSTER'));
}
[$hs, $html] = get_html($BASE . '/pstmain/login', $htmlJar);
check('登录页不再引用内置默认图（已被自定义图替换）', !str_contains($html, "LOGIN_POSTER = '$DEF'"));

// ============================================================
// 3. 换第二张 + 恢复默认
// ============================================================
echo "\n== 3. 换图与恢复默认 ==\n";
[$s, $j] = owner_post($P, [], ['b.png', 'image/png', png_bytes()]);
$p2 = (string)($j['data']['poster'] ?? '');
check('再传一张即换图', ($j['code'] ?? 1) === 0 && $p2 !== '' && $p2 !== $p1, json_encode($j, JSON_UNESCAPED_UNICODE));
check('新图已落盘', is_file(abs_of($p2)), abs_of($p2));
check('旧图物理文件已删除（不占配额）', gone($p1), $p1);
check('settings 只留最新一份', setting_val($T1) === $p2, (string)setting_val($T1));

[$s, $j] = owner_post($P, ['reset' => '1']);
check('恢复默认：返回内置图路径', ($j['code'] ?? 1) === 0 && ($j['data']['poster'] ?? '') === $DEF, json_encode($j, JSON_UNESCAPED_UNICODE));
check('恢复默认：自定义图物理文件已删除', gone($p2), $p2);
check('恢复默认：settings 值清空', setting_val($T1) === '', var_export(setting_val($T1), true));
[$hs, $html] = get_html($BASE . '/pstmain/login', $htmlJar);
check('登录页回到内置海报', str_contains($html, "LOGIN_POSTER = '$DEF'"));
[$s, $j] = owner_post($P, ['reset' => '1']);
check('本来就是默认时再点恢复 → 明确提示不做无谓写库',
    ($j['code'] ?? 1) === 0 && str_contains((string)($j['msg'] ?? ''), '默认配图'), json_encode($j, JSON_UNESCAPED_UNICODE));

// ============================================================
// 4. 非法输入
// ============================================================
echo "\n== 4. 非法图片 ==\n";
[$s, $j] = owner_post($P, [], ['bad.txt', 'text/plain', 'hello world']);
check('非图片扩展名被拒', ($j['code'] ?? 0) !== 0 && str_contains((string)($j['msg'] ?? ''), '格式'), json_encode($j, JSON_UNESCAPED_UNICODE));
[$s, $j] = owner_post($P, [], ['fake.png', 'image/png', '这不是图片内容']);
check('伪装成 png 的非图片内容被拒（服务端真实校验）',
    ($j['code'] ?? 0) !== 0 && str_contains((string)($j['msg'] ?? ''), '不是有效图片'), json_encode($j, JSON_UNESCAPED_UNICODE));
check('两次非法上传都没改动配置', setting_val($T1) === '', var_export(setting_val($T1), true));

// ============================================================
// 5. 库值被改坏的兜底
// ============================================================
echo "\n== 5. 脏值兜底 ==\n";
$pdo->exec("UPDATE settings SET value = 'javascript:alert(1)' WHERE tenant_id = $T1 AND `key` = 'login_poster'");
[$s, $j] = owner_req('GET', $P);
check('脏值 → 接口回内置默认且 custom 为空',
    ($j['data']['poster'] ?? '') === $DEF && ($j['data']['custom'] ?? 'x') === '', json_encode($j['data'] ?? $j, JSON_UNESCAPED_UNICODE));
[$hs, $html] = get_html($BASE . '/pstmain/login', $htmlJar);
check('脏值不进登录页 HTML（不会被当背景图地址渲染）',
    !str_contains($html, 'javascript:alert') && str_contains($html, "LOGIN_POSTER = '$DEF'"));
$pdo->exec("UPDATE settings SET value = '' WHERE tenant_id = $T1 AND `key` = 'login_poster'");

// ============================================================
// 6. 多租户隔离与回归
// ============================================================
echo "\n== 6. 隔离与回归 ==\n";
[$s, $j] = other_req('POST', '/pstother/api/auth/login', ['username' => 'pstother2', 'password' => 'owner123456']);
check('干扰队站长登录', ($j['code'] ?? 1) === 0, $j['msg'] ?? '');
[$s, $j] = other_req('GET', '/pstother/api/admin/poster');
check('干扰队读到的仍是默认（未受主测队影响）',
    ($j['code'] ?? 1) === 0 && ($j['data']['poster'] ?? '') === $DEF && ($j['data']['custom'] ?? 'x') === '',
    json_encode($j['data'] ?? $j, JSON_UNESCAPED_UNICODE));
[$s, $j] = other_post('/pstother/api/admin/poster', [], ['c.png', 'image/png', png_bytes()]);
$p3 = (string)($j['data']['poster'] ?? '');
check('干扰队站长只能配自己战队的图', ($j['code'] ?? 1) === 0 && $p3 !== '' && setting_val($T2) === $p3, json_encode($j, JSON_UNESCAPED_UNICODE));
[$hs, $htmlA] = get_html($BASE . '/pstmain/login', $htmlJar);
[$hs, $htmlB] = get_html($BASE . '/pstother/login', $htmlJar);
check('两支队登录页各自注入自己的图（互不串）',
    str_contains($htmlA, "LOGIN_POSTER = '$DEF'") && str_contains($htmlB, "LOGIN_POSTER = '$p3'"), $p3);
check('配置文件里两条记录互不覆盖', setting_val($T1) === '' && setting_val($T2) === $p3);

[$s, $j] = owner_req('GET', '/pstmain/api/auth/me');
check('站长身份判定未受影响（is_super 为真）',
    ($j['code'] ?? 1) === 0 && ($j['data']['is_super'] ?? false) === true, json_encode($j['data']['is_super'] ?? null));
[$s, $j] = owner_req('GET', '/pstmain/api/admin/stats');
check('既有管理端接口不受影响（战绩列表 200）', ($j['code'] ?? 1) === 0, json_encode($j, JSON_UNESCAPED_UNICODE));
[$s, $j] = owner_req('GET', '/pstmain/api/admin/blacklist');
check('既有管理端接口不受影响（黑名单 200）', ($j['code'] ?? 1) === 0, json_encode($j, JSON_UNESCAPED_UNICODE));

// 测试上传的图片属于跑测残留：留在最后清掉，不污染 public/storage/uploads
foreach (array_values(array_filter([$p1, $p2, $p3])) as $p) {
    $abs = abs_of((string)$p);
    if (is_file($abs)) { @unlink($abs); }
    @rmdir(dirname($abs));          // 空的日子目录一并收掉
}

echo "\n================ 结果 ================\n";
echo "通过 $pass / 失败 $fail\n";
if ($failures) { echo "失败项：\n - " . implode("\n - ", $failures) . "\n"; }
exit($fail > 0 ? 1 : 0);

