<?php
/**
 * v5.27.0 官网首页 + 入驻页两栏外壳 + 平台控制台外壳 —— 端到端
 *
 * 前置：测试服务器已启动，.env 指向 SQLite（.env 里只能用 ; 注释）
 *   php -S 127.0.0.1:8899 -t public public/router.php
 * 用法：php scripts/test_home_page_e2e.php
 *
 * 锁住这几件事：
 *   1. 未登录访问平台根路径是 200 的首页，不再 302 奔登录页（他提的第 3 条）
 *   2. 首页四个数是库里真数，且公开页不出现任何战队名 / 目录名 / 玩家 QQ
 *   3. 平台超管登录后根路径照旧进 /app；/login /register 的登录态跳转没被弄坏
 *   4. 老的静态宣传页 public/index.html 已下线（/index.html 应 404）
 *   5. 战队站点根路径 /{slug}/ 仍回该队登录页，没被平台首页抢走；租户侧 auth.jsx 一行没动
 *   6. 入驻页换成了两栏外壳（AuthFrame/AuthAside），淡紫渐变与居中单卡已清干净
 *   7. 控制台外壳用的就是战队侧那几个令牌（232 侧栏、分组折叠键、Card 描边）
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

$ROOT = dirname(__DIR__);
$BASE = rtrim((string)(getenv('TEST_BASE') ?: 'http://127.0.0.1:8899'), '/');
$DB   = $ROOT . '/runtime/test.sqlite';

$pass = 0; $fail = 0;
function check(string $name, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  [PASS] $name\n"; }
    else { $fail++; echo "  [FAIL] $name" . ($detail !== '' ? " —— $detail\n" : "\n"); }
}

$jars = [];
/** 发一次请求：不跟跳转，返回 [状态, 正文, Location 头]，cookie 在内存里按身份分桶 */
function req(string $who, string $path, ?array $body = null): array
{
    global $BASE, $jars;
    $c = $jars[$who] ?? [];
    $hdr = "Origin: $BASE\r\n";
    if ($body !== null) { $hdr .= "Content-Type: application/json\r\n"; }
    if ($c) {
        $pairs = [];
        foreach ($c as $k => $v) { $pairs[] = $k . '=' . $v; }
        $hdr .= 'Cookie: ' . implode('; ', $pairs) . "\r\n";
    }
    $opts = ['http' => ['ignore_errors' => true, 'timeout' => 30, 'follow_location' => 0,
        'header' => $hdr]];
    if ($body !== null) {
        $opts['http']['method'] = 'POST';
        $opts['http']['content'] = json_encode($body, JSON_UNESCAPED_UNICODE);
    }
    $raw = @file_get_contents($BASE . $path, false, stream_context_create($opts));
    $status = 0; $loc = '';
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) { $status = (int)$m[1]; }
        if (preg_match('/^Location:\s*(.+)$/i', $h, $m)) { $loc = trim($m[1]); }
        if (preg_match('/^Set-Cookie:\s*([^=]+)=([^;]*)/i', $h, $m)) { $c[$m[1]] = trim($m[2]); }
    }
    $jars[$who] = $c;
    return [$status, (string)$raw, $loc];
}
function db(): PDO
{
    global $DB;
    $p = new PDO('sqlite:' . $DB);
    $p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $p->setAttribute(PDO::ATTR_TIMEOUT, 5);
    return $p;
}
function clearCache(): void
{
    global $ROOT;
    $dir = $ROOT . '/runtime/cache';
    if (!is_dir($dir)) { return; }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) { if ($f->isFile()) { @unlink($f->getPathname()); } }
}
/** 按出现顺序取首页 <b> 里的四个数 */
function statNums(string $html): array
{
    preg_match_all('#<div class="stat card"><b>([0-9,]+)</b><span>([^<]+)</span>#', $html, $m, PREG_SET_ORDER);
    $out = [];
    foreach ($m as $x) { $out[] = [(int)str_replace(',', '', $x[1]), trim($x[2])]; }
    return $out;
}

// ============================================================
// 0. 重建测试库与种子：两支正常队 + 一支被封的队 + 待审核/被封的成员
// ============================================================
echo "== 0. 重建测试库与种子 ==\n";
if (is_file($DB)) { @unlink($DB); }
echo shell_exec('"' . PHP_BINARY . '" "' . $ROOT . '/scripts/dev_init_sqlite.php" "' . $DB . '" 2>&1');
clearCache();

$now  = date('Y-m-d H:i:s');
$pdo  = db();
$hash = password_hash('pass123456', PASSWORD_DEFAULT);

$mk = function (string $slug, string $name, string $status, array $people) use ($pdo, $hash, $now): array {
    $pdo->exec("INSERT INTO platform_users (username,password,nickname,role,status,reg_ip,created_at,updated_at)
        VALUES ('{$slug}boss', '{$hash}', '{$name}联络人', 'team', 'normal', '127.0.0.1', '$now', '$now')");
    $pid = (int)$pdo->lastInsertId();
    $pdo->exec("INSERT INTO tenants (applicant_id, slug, name, logo, description, status, admin_username, admin_user_id, created_at, updated_at)
        VALUES ($pid, '$slug', '$name', '', '入驻时填的战队简介', '$status', '{$people[0][0]}', NULL, '2026-01-01 10:00:00', '$now')");
    $tid = (int)$pdo->lastInsertId();
    $ids = [];
    foreach ($people as $p) {
        [$qq, $nick, $role, $ustatus] = $p;
        $pdo->exec("INSERT INTO users (tenant_id, username, password, nickname, role, status, admin_perms, created_at, updated_at)
            VALUES ($tid, '$qq', '$hash', '$nick', '$role', '$ustatus', '[]', '$now', '$now')");
        $ids[$qq] = (int)$pdo->lastInsertId();
    }
    $pdo->exec("UPDATE tenants SET admin_user_id = {$ids[$people[0][0]]} WHERE id = $tid");
    return ['tid' => $tid, 'ids' => $ids];
};
$A = $mk('hpa', '首页甲队', 'approved', [
    ['9627100001', '甲队队长', 'admin', 'approved'],
    ['9627100002', '甲队会员', 'user',  'approved'],
    ['9627100003', '甲队待审', 'user',  'pending'],
]);
$B = $mk('hpb', '首页乙队', 'approved', [
    ['9627200001', '乙队队长', 'admin', 'approved'],
    ['9627200002', '乙队被封', 'user',  'banned'],
]);
$C = $mk('hpc', '首页丙队', 'banned', [
    ['9627300001', '丙队队长', 'admin', 'approved'],
]);

// 跨队约战：3 场真办成了（accepted/finished）+ 1 场没办成（canceled 不计这一格）；枪码：2 条已发布 + 1 条已下架
// v5.32.0：论坛下线，首页那一格原来数的是「论坛累计发帖」，现在数的是这个
foreach ([['在架约战一', 'accepted'], ['在架约战二', 'finished'], ['在架约战三', 'accepted'], ['没办成的约战', 'canceled']] as $b) {
    $pdo->exec("INSERT INTO battles (title, game_mode, battle_time, challenger_type, challenger_id, defender_type, defender_id, status, created_at, updated_at)
        VALUES ('{$b[0]}', '团队竞技', '$now', 'tenant', {$A['tid']}, 'tenant', {$B['tid']}, '{$b[1]}', '$now', '$now')");
}
foreach ([['HP-KK 在架', 'A11', 'published'], ['HP-AR 在架', 'B22', 'published'], ['HP-下架', 'C33', 'offline']] as $p) {
    $pdo->exec("INSERT INTO gun_codes (tenant_id, user_id, title, code, description, status, category, created_at, updated_at)
        VALUES ({$A['tid']}, {$A['ids']['9627100001']}, '{$p[0]}', '{$p[1]}', '', '{$p[2]}', 'smg', '$now', '$now')");
}

$want = [
    'tenants'  => (int)$pdo->query("SELECT COUNT(*) FROM tenants WHERE status='approved'")->fetchColumn(),
    'players'  => (int)$pdo->query("SELECT COUNT(*) FROM users WHERE status='approved'")->fetchColumn(),
    'battles'  => (int)$pdo->query("SELECT COUNT(*) FROM battles WHERE status IN ('accepted','finished')")->fetchColumn(),
    'gunCodes' => (int)$pdo->query("SELECT COUNT(*) FROM gun_codes WHERE status='published'")->fetchColumn(),
];
echo "-- 种子 --\n";
check('库里确实是 2 支已开通站（丙队被封不计）', $want['tenants'] === 2, (string)$want['tenants']);
check('库里确实是 4 名在册玩家（待审/被封不计）', $want['players'] === 4, (string)$want['players']);
check('跨队约战办成 3 场、枪码已发布 2 条', $want['battles'] === 3 && $want['gunCodes'] === 2,
    json_encode($want, JSON_UNESCAPED_UNICODE));

// ============================================================
// 1. 未登录访问平台根路径：给首页，不再跳登录
// ============================================================
echo "\n== 1. 未登录的根路径 ==";
echo "\n";
[$st, $html, $loc] = req('guest', '/');
check('GET / 是 200（不再 302 奔 /login）', $st === 200, "status=$st location=$loc");
check('没有 Location 头', $loc === '', $loc);
check('hero 大标题在页面上', strpos($html, '一个战队，') !== false);
check('首屏配了训练场景图', strpos($html, '/static/img/team-training.webp') !== false);
check('展示页示例配了战队海报', strpos($html, '/static/img/login-poster.webp') !== false);
check('有「现邀请战队入驻」这句', strpos($html, '现邀请战队入驻') !== false);
check('有登录与注册两个入口', strpos($html, 'href="/login"') !== false && strpos($html, 'href="/register"') !== false);
check('有公示大厅入口', strpos($html, 'href="/hall"') !== false);
preg_match('#<title>([^<]*)</title>#', $html, $tm);
$title = (string)($tm[1] ?? '');
check('标题不是「X · X」那种重复', $title !== '' && strpos($title, ' · 战队入驻平台') === false, $title);
check('meta description 是首页自己那句', strpos($html, '现邀请战队入驻') !== false && strpos($html, '<meta name="description"') !== false);

// 四个数
$nums = statNums($html);
check('数据条是四个格子', count($nums) === 4, (string)count($nums));
$read = array_column($nums, 0);
check('四个数与库里一致（站/玩家/约战/枪码）', $read === [$want['tenants'], $want['players'], $want['battles'], $want['gunCodes']],
    implode(',', $read));
check('数字带的是标签文字不是变量名', $nums !== [] && strpos($nums[0][1], '已开通') !== false, $nums[0][1] ?? '');

// 公开页零身份
foreach (['首页甲队', '首页乙队', '首页丙队'] as $n) {
    check("首页不出现战队名「{$n}」", strpos($html, $n) === false);
}
foreach (['hpa', 'hpb', 'hpc'] as $s) {
    check("首页不出现站点目录「/{$s}/」", strpos($html, '/' . $s . '/') === false && strpos($html, $s . 'boss') === false);
}
foreach (['9627100001', '9627100002', '9627200001', '9627300001'] as $q) {
    check("首页不出现玩家 QQ「{$q}」", strpos($html, $q) === false);
}
check('首页不含「入驻时填的战队简介」这种库里原文', strpos($html, '入驻时填的战队简介') === false);

// 运行时噪声
foreach (['Fatal error', 'Warning:', 'Notice:', 'Undefined', '异常'] as $bad) {
    check("首页正文没有「{$bad}」", strpos($html, $bad) === false);
}

// ============================================================
// 1b. 公示大厅被平台关掉时，首页不能摆一个 404 的入口
// ============================================================
echo "\n== 1b. 公示大厅总开关 ==\n";
$pdo->exec("INSERT OR IGNORE INTO settings (tenant_id, `key`, value) VALUES (0, 'hall_enabled', '1')");
$pdo->exec("UPDATE settings SET value='0' WHERE tenant_id=0 AND `key`='hall_enabled'");
clearCache();
[$st, $off] = req('guest', '/');
check('关掉大厅后首页仍 200', $st === 200, "status=$st");
check('关掉大厅后首页没有 /hall 链接', substr_count($off, 'href="/hall"') === 0,
    (string)substr_count($off, 'href="/hall"'));
check('首屏第二个按钮换成「看看怎么入驻」', strpos($off, '看看怎么入驻') !== false);
$pdo->exec("UPDATE settings SET value='1' WHERE tenant_id=0 AND `key`='hall_enabled'");
clearCache();
[, $on] = req('guest', '/');
check('开回来 /hall 又出现', substr_count($on, 'href="/hall"') > 0, (string)substr_count($on, 'href="/hall"'));

// ============================================================
// 1c. 库里某项是 0 时，那一格不摆（新站首页不该一排 0）
// ============================================================
echo "\n== 1c. 数字为 0 的那格 ==\n";
$pdo->exec("UPDATE battles SET status='canceled' WHERE status IN ('accepted','finished')");
$pdo->exec("UPDATE gun_codes SET status='offline'");
clearCache();
[$st, $zero] = req('guest', '/');
$zr = statNums($zero);
check('0 的项不出格子（这里只剩 2 项）', count($zr) === 2, (string)count($zr));
check('格子列数跟着变成两列', strpos($zero, '--n:2') !== false);
check('剩下的两个数还是对的', array_column($zr, 0) === [$want['tenants'], $want['players']], implode(',', array_column($zr, 0)));
$pdo->exec("UPDATE battles SET status='accepted' WHERE title LIKE '在架约战%'");
$pdo->exec("UPDATE gun_codes SET status='published' WHERE title LIKE 'HP-%在架'");
clearCache();
[, $back] = req('guest', '/');
check('数据回来后四格又齐了（--n:4）', strpos($back, '--n:4') !== false && count(statNums($back)) === 4);

// ============================================================
// 2. 平台超管登录后：根路径回控制台，登录页照旧跳走
// ============================================================
echo "\n== 2. 已登录的平台超管 ==\n";
[$st, $raw] = req('admin', '/api/platform/auth/login', ['username' => 'admin', 'password' => 'admin123']);
$j = json_decode($raw, true);
check('超管能登录平台', $st === 200 && (int)($j['code'] ?? 1) === 0, substr($raw, 0, 120));
[$st, $html2, $loc] = req('admin', '/');
check('登录后 GET / 是 302', $st === 302, "status=$st");
check('跳的是 /app', $loc === '/app', $loc);
[$st, , $loc] = req('admin', '/login');
check('登录后 /login 仍跳 /app（旧行为没变）', $st === 302 && $loc === '/app', "status=$st location=$loc");
[$st, $html3] = req('admin', '/app');
check('控制台页能打开', $st === 200 && strpos($html3, 'PLATFORM_MODE') !== false);

// ============================================================
// 3. 老的静态宣传页确实下线了
// ============================================================
echo "\n== 3. 静态宣传页 ==\n";
[$st] = req('guest', '/index.html');
check('GET /index.html 现在是 404', $st === 404, "status=$st");
check('仓库里已经没有 public/index.html', !is_file($ROOT . '/public/index.html'));

// ============================================================
// 4. 战队站点根路径没被平台首页抢走
// ============================================================
echo "\n== 4. 租户上下文 ==\n";
[$st, , $loc] = req('guest', '/hpa/');
check('/{slug}/ 仍按登录态分流（302）', $st === 302, "status=$st");
check('跳回本队登录页 /hpa/login', $loc === '/hpa/login', $loc);
[$st, $t1] = req('guest', '/hpa/login');
check('战队侧登录页照常 200', $st === 200 && strpos($t1, 'AUTH_MODE') !== false);
check('战队侧登录页上没有平台首页那句「现邀请战队入驻」', strpos($t1, '现邀请战队入驻') === false);
[$st, $t2] = req('guest', '/hpa/register');
check('战队侧注册页照常 200', $st === 200 && strpos($t2, 'AUTH_MODE') !== false);

// ============================================================
// 5. 入驻页与控制台外壳（直接看发出去的 JS）
// ============================================================
echo "\n== 5. 入驻页 / 控制台外壳 ==\n";
[$st, $pj] = req('guest', '/static/js/platform.jsx');
check('platform.jsx 取得到', $st === 200 && strlen($pj) > 100000, (string)strlen($pj));
foreach (['function AuthFrame({ mode, children })', 'function AuthAside({ mode })',
          'function ConsoleFrame(', 'const SUPER_GROUPS =', "const DRAWER_WIDTH = 232",
          "yys_platform_nav_collapsed", "const CARD_BORDER = '#e8e8ed'", "const SUB_TEXT = '#5f6b7f'",
          "onPending={setPending}", '<EmptyBox text="暂无入驻申请" />', '<LoadingBox />'] as $must) {
    check("platform.jsx 里有 $must", strpos($pj, $must) !== false);
}
foreach (['ece9f7', '<Logo />', 'function Logo({ size })', "maxWidth: 960, mx: 'auto', px: 2, height: 60",
          "variant=\"scrollable\" allowScrollButtonsMobile\n          TabProps={{ sx: { minWidth: 78",
          "<SuperConsole />"] as $gone) {
    check('platform.jsx 里已经没有 ' . str_replace("\n", ' ', substr($gone, 0, 40)), strpos($pj, $gone) === false);
}
// 战队侧 auth.jsx 一行没动：还是那套令牌与海报
[$st, $aj] = req('guest', '/static/js/auth.jsx');
check('auth.jsx 仍能取到', $st === 200 && strpos($aj, 'APPLE_BLUE') !== false);
check('auth.jsx 里没被塞进平台外壳', strpos($aj, 'AuthFrame') === false && strpos($aj, 'ConsoleFrame') === false);

// 入驻两页的服务端入口没变
[$st, $pl] = req('guest', '/login');
check('未登录 GET /login 是 200 且 mode=login', $st === 200 && strpos($pl, "PLATFORM_MODE = 'login'") !== false);
[$st, $pr] = req('guest', '/register');
check('未登录 GET /register 是 200 且 mode=register', $st === 200 && strpos($pr, "PLATFORM_MODE = 'register'") !== false);

// ============================================================
echo "\n================ 通过 $pass / 失败 $fail ================\n";
exit($fail === 0 ? 0 : 1);
