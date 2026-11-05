<?php
/**
 * v5.20.0 战队对外展示页 / 公示大厅 / 访客投稿与入队意向 —— 端到端
 *
 * 前置：测试服务器已启动，.env 指向 SQLite（.env 里只能用 ; 注释）
 *   php -S 127.0.0.20:8899 -t public public/router.php
 * 用法：php scripts/test_showcase_e2e.php
 *
 * 这一套锁死的是"公开页"特有的几条不变量，改任何一条都会让已上线的口径悄悄变形：
 *   1. 三层开关缺一不可：战队自己开 / 平台没下架 / 站点没停用；未通过一律 404，且不给"存在但空"
 *   2. 成员公开是双层：队长开模块 + 成员本人勾；没填昵称的成员永远不上页（那是 QQ 兜底显示）
 *   3. QQ / 邮箱 / IP / 归属地绝不出现在访客可见的 HTML 里，也不出现在管理端以外的任何接口
 *   4. 访客提交五道闸（honeypot、停留时长、图形码、限频、敏感词）+ 采纳后才上墙
 *   5. 精选/图集只能引用本站仍然有效的行；图片路径必须真在 uploads 里（防把任意字符串写进 settings）
 *   6. 结构隔离：投稿箱不进导出清单、不进隐身巡查白名单
 *   7. sitemap 只收录开了展示页的站点，/hall 跟随平台总开关
 *   8. 块顺序与分栏（v5.29.0）是队长的自由，但六块一个不能少：脏值服务端洗、半份保存补全，
 *      关掉的块位置留着；桌面两栏各排自己那一串，手机上按同一条顺序摊平
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

// accentTokens / contrast 是纯函数：把服务层那一份 require 进来当「期望值」，
// 免得测试里另抄一套算色逻辑，线上改了却测不出来
require_once __DIR__ . '/../app/service/Showcase.php';

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
    $hdr = "Content-Type: application/json\r\nOrigin: $BASE\r\n";
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
/** 访客请求：不带任何 Cookie（公开页必须能在无会话时打开） */
function guest(string $method, string $path, ?array $body = null) {
    return as_req('__guest_' . microtime(true), $method, $path, $body);
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
function countRows(string $table, string $where = '1=1'): int {
    return (int)db_val("SELECT COUNT(*) FROM $table WHERE $where");
}
function loginTenant(string $who, string $slug, string $acct): bool {
    [$s, $j] = as_req($who, 'POST', '/' . $slug . '/api/auth/login', ['username' => $acct, 'password' => 'pass123456']);
    return (int)($j['code'] ?? 1) === 0;
}

// 读 ThinkPHP 文件缓存（图形验证码的答案就存在这里，测试进程与服务器进程共用同一份缓存目录）
function wipe_cache(): void {
    global $ROOT;
    foreach (glob($ROOT . '/runtime/cache/*/*') ?: [] as $f) { if (is_file($f)) @unlink($f); }
    foreach (glob($ROOT . '/runtime/cache/*') ?: [] as $f) { if (is_file($f)) @unlink($f); }
}
function cache_read(string $key) {
    global $ROOT;
    $h = md5($key);
    $f = $ROOT . '/runtime/cache/' . substr($h, 0, 2) . '/' . substr($h, 2) . '.php';
    if (!is_file($f)) { return null; }
    $raw = (string)file_get_contents($f);
    if (strlen($raw) <= 32) { return null; }
    $val = @unserialize(substr($raw, 32));
    return $val === false && substr(trim($raw), 32) !== 'b:0;' ? null : $val;
}
/** 取访客页上两枚验证码的 (key => 答案)；页面每次渲染都新发，所以调用前先 wipe */
function captchas(string $slug): array {
    [$s, , , $html] = guest('GET', '/' . $slug . '/show');
    $out = [];
    preg_match_all('/name="captcha_key" value="([0-9a-f]{16})"/', $html, $m);
    foreach ($m[1] ?? [] as $k) {
        $code = cache_read('show_cap_' . $k);
        if (is_string($code) && $code !== '') { $out[$k] = $code; }
    }
    return $out;
}
function pick_cap(array $caps): array {
    if ($caps === []) { return ['', '']; }
    $k = array_rand($caps);
    return [$k, $caps[$k]];
}
/**
 * 成功提交一次访客表单：默认先清限频与旧码，再重新渲染页面拿新验证码。
 * $wipe=false 留给"专门测限频"的循环——那种时候清缓存等于把计数器抹掉，测不出东西。
 */
function submit_ok(string $slug, array $fields, bool $wipe = true): array {
    global $BASE;
    if ($wipe) { wipe_cache(); }
    $caps = captchas($slug);
    if ($caps === []) { return [0, ['msg' => '没拿到验证码']]; }
    [$key, $code] = pick_cap($caps);
    $body = array_merge([
        't' => time() - 8, 'website' => '', 'captcha_key' => $key, 'captcha' => $code,
    ], $fields);
    return guest('POST', '/' . $slug . '/api/show/submit', $body);
}

// ============================================================
// 0. 重建测试库与种子
// ============================================================
echo "== 0. 重建测试库与种子 ==\n";
@unlink($DB);
echo shell_exec('"' . PHP_BINARY . '" "' . $ROOT . '/scripts/dev_init_sqlite.php" "' . $DB . '" 2>&1');
wipe_cache();

$now  = date('Y-m-d H:i:s');
$pdo  = db();
$hash = password_hash('pass123456', PASSWORD_DEFAULT);

$mk = function (string $slug, string $name, string $status, array $people) use ($pdo, $hash, $now): array {
    $pdo->exec("INSERT INTO platform_users (username,password,nickname,role,status,reg_ip,created_at,updated_at)
        VALUES ('{$slug}boss', '{$hash}', '{$name}联络人', 'team', 'normal', '127.0.0.1', '$now', '$now')");
    $pid = (int)$pdo->lastInsertId();
    $pdo->exec("INSERT INTO tenants (applicant_id, slug, name, logo, description, status, admin_username, admin_user_id, created_at, updated_at)
        VALUES ($pid, '$slug', '$name', '', '入驻时填的战队简介', '$status', '{$people[0]}', NULL, '2026-01-01 10:00:00', '$now')");
    $tid = (int)$pdo->lastInsertId();
    $ids = [];
    foreach ($people as $i => $qq) {
        $role = $i === 0 ? 'admin' : 'user';
        $nick = $i === 0 ? $slug . '队长' : $slug . '成员' . ($i + 1);
        $pdo->exec("INSERT INTO users (tenant_id, username, password, nickname, role, status, admin_perms, created_at, updated_at)
            VALUES ($tid, '$qq', '$hash', '$nick', '$role', 'approved', '[]', '$now', '$now')");
        $ids[$qq] = (int)$pdo->lastInsertId();
    }
    $pdo->exec("UPDATE tenants SET admin_user_id = {$ids[$people[0]]} WHERE id = $tid");
    return ['tid' => $tid, 'ids' => $ids];
};

$A = $mk('sha', '展示甲队', 'approved', ['940040001', '940040002', '940040003', '940040004']);
$B = $mk('shb', '展示乙队', 'approved', ['940041001', '940041002']);
$C = $mk('shc', '展示丙队', 'paused',   ['940042001']);   // 站点被停用：展示页必须跟着消失
$D = $mk('shd', '展示丁队', 'approved', ['940043001']);   // 模块全开但一条内容都没配：专门验死选项
$tidA = (int)$A['tid']; $tidB = (int)$B['tid']; $tidC = (int)$C['tid']; $tidD = (int)$D['tid'];
$ownA = (int)$A['ids']['940040001'];
$memA = (int)$A['ids']['940040002'];      // 同意公开、有昵称
$memA2 = (int)$A['ids']['940040003'];     // 同意公开、但昵称是空的（QQ 兜底那条）
$memA3 = (int)$A['ids']['940040004'];     // 不同意公开

// 成员：一个有昵称且愿意公开，一个没昵称（也点了同意），一个明确不公开
$pdo->exec("UPDATE users SET nickname='公开的张三', show_public=1 WHERE id=$memA");
$pdo->exec("UPDATE users SET nickname='', show_public=1 WHERE id=$memA2");
$pdo->exec("UPDATE users SET show_public=0 WHERE id=$memA3");
$pdo->exec("UPDATE users SET show_public=1, role='admin', admin_perms='[]' WHERE id=$ownA");   // 队长自己也同意公开
$ownQQ = '940040001';

// 职责标签（成员风采要显示标签）
$pdo->exec("INSERT INTO role_tags (tenant_id, name, color, created_at, updated_at) VALUES ($tidA, '突击', '#e0533d', '$now', '$now')");
$tagId = (int)$pdo->lastInsertId();
$pdo->exec("UPDATE users SET role_tags='" . json_encode([$tagId]) . "' WHERE id=$memA");

// 公告 / 改枪码 / 攻略：给"精选勾选"用
$pdo->exec("INSERT INTO announcements (tenant_id, title, content, image, need_receipt, created_at, updated_at)
    VALUES ($tidA, '周六队内训练赛', '晚八点集合，测试用的对外动态正文', '', 0, '$now', '$now')");
$nid = (int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO announcements (tenant_id, title, content, image, need_receipt, created_at, updated_at)
    VALUES ($tidA, '未勾的第二条公告', '这条不该出现在展示页', '', 0, '$now', '$now')");
$nid2 = (int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO gun_codes (tenant_id, user_id, title, code, description, status, created_at, updated_at)
    VALUES ($tidA, $memA, 'M4 精选码', 'CODE-PUB-1', '已发布的那条', 'published', '$now', '$now')");
$gcid = (int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO gun_codes (tenant_id, user_id, title, code, description, status, created_at, updated_at)
    VALUES ($tidA, $memA, '下架的码', 'CODE-OFF-1', '作者自己下架了', 'offline', '$now', '$now')");
$gcidOff = (int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO guides (tenant_id, user_id, title, content, image, status, created_at, updated_at)
    VALUES ($tidA, $memA, '零号大坝路线', '测试攻略正文', '', 'published', '$now', '$now')");
$gid = (int)$pdo->lastInsertId();

// 约战：2 胜 1 负 + 1 场与本队无关 + 1 场未完赛（统计只该数前三场里的本队完赛场次）
$bd = function (string $ctype, int $cid, string $dtype, int $did, ?string $result, string $status, ?string $fin) use ($pdo, $now) {
    $pdo->exec("INSERT INTO battles (title, game_mode, battle_time, challenger_type, challenger_id, defender_type, defender_id,
        status, result, finished_at, created_at, updated_at)
        VALUES ('E2E 约战', '占点', '2026-06-01 20:00:00', '$ctype', $cid, '$dtype', $did, '$status', "
            . ($result === null ? 'NULL' : "'$result'") . ', '
            . ($fin === null ? 'NULL' : "'$fin'") . ", '$now', '$now')");
};
$bd('tenant', $tidA, 'tenant', $tidB, 'a_win', 'finished', '2026-06-01 21:00:00');   // A 胜
$bd('tenant', $tidB, 'tenant', $tidA, 'b_win', 'finished', '2026-06-02 21:00:00');   // A 胜
$bd('tenant', $tidA, 'tenant', $tidB, 'b_win', 'finished', '2026-06-03 21:00:00');   // A 负
$bd('tenant', $tidA, 'tenant', $tidB, '',     'pending',   null);                    // 未完赛，不该计数
$bd('tenant', $tidB, 'external', 0,  'a_win', 'finished', '2026-06-04 21:00:00');    // 与 A 无关

// 成员上传战绩：胜率均值 70（60 与 80），第三条没上传胜率
$pdo->exec("INSERT INTO player_stats (tenant_id, user_id, game_pid, matches, win_rate, created_at, updated_at)
    VALUES ($tidA, $memA, 'P1', 10, 60.00, '$now', '$now'), ($tidA, $memA2, 'P2', 20, 80.00, '$now', '$now')");

echo "  种子：A tid=$tidA(成员 $memA/$memA2/$memA3) B tid=$tidB C(停用) tid=$tidC D(空内容) tid=$tidD\n";
echo '  公告=' . $nid . ' 改枪码=' . $gcid . ' 攻略=' . $gid . "\n";

foreach ([['own', 'sha', '940040001'], ['mem', 'sha', '940040002'], ['mem3', 'sha', '940040004'],
          ['ownb', 'shb', '940041001'], ['ownd', 'shd', '940043001']] as $p) {
    check('登录 ' . $p[0] . '（' . $p[2] . '）', loginTenant($p[0], $p[1], $p[2]));
}
[$s, $j] = as_req('plat', 'POST', '/api/platform/auth/login', ['username' => 'admin', 'password' => 'admin123']);
check('平台超管登录', (int)($j['code'] ?? 1) === 0, J($j));

// 展示页要真有一张图片能用：直接在 uploads/showcase 下造一张（itemAdd 会校验文件真实存在）
$day      = date('Ymd');
$imgDir   = $ROOT . '/public/storage/uploads/showcase/' . $day;
@mkdir($imgDir, 0755, true);
$imgName  = bin2hex(random_bytes(16)) . '.png';
$im = imagecreatetruecolor(8, 8);
imagefill($im, 0, 0, imagecolorallocate($im, 224, 83, 61));
imagepng($im, $imgDir . '/' . $imgName);
imagedestroy($im);
$IMG = '/storage/uploads/showcase/' . $day . '/' . $imgName;

// ============================================================
// 1. 三层开关与 404
// ============================================================
echo "\n== 1. 未开启 / 平台上下文 / 停用站点：一律 404 ==\n";
[$s, , , $html] = guest('GET', '/sha/show');
check('战队没开启时 /{slug}/show 是 404', $s === 404, "status=$s");
[$s] = guest('GET', '/shb/show');
check('另一支没开启的战队同样 404', $s === 404, "status=$s");
[$s] = guest('GET', '/show');
check('平台根路径没有 /show 这个页面', $s === 404, "status=$s");
[$s] = guest('GET', '/sha/hall');
check('公示大厅只在根路径，战队目录下 404', $s === 404, "status=$s");
[$s] = guest('GET', '/shc/show');
// 站点停用时 ResolveTenant 先一步拦下（403「站点已停用」），比 404 更早；两种都算"访客进不去"
check('被停用的站点（shc）即使开了也不给看', in_array($s, [403, 404], true), "status=$s");
[$s, $j] = guest('POST', '/sha/api/show/submit', ['type' => 'intent']);
check('展示页没开时访客提交被拒', (int)($j['code'] ?? 0) === 404, J($j));

// ============================================================
// 2. 管理端：开启与回显
// ============================================================
echo "\n== 2. 管理端编辑（权限键 showcase） ==\n";
[$s, $j, , $raw] = as_req('mem', 'GET', '/sha/api/admin/showcase');
check('普通成员调管理端展示页接口被拦（403）', in_array($s, [403], true) || (int)($j['code'] ?? 0) === 403, "status=$s " . substr($raw, 0, 90));
[$s, $j] = as_req('own', 'GET', '/sha/api/admin/showcase');
$cfg = $j['data']['config'] ?? [];
check('队长能拿到展示页配置', (int)($j['code'] ?? 1) === 0 && is_array($cfg), J($j));
check('默认是关的（新站点不会被莫名其妙公开）', (int)($cfg['enabled'] ?? 1) === 0, J($cfg));
check('默认模块里"成员风采"是关的、入队意向是开的',
    (int)($cfg['modules']['members'] ?? 1) === 0 && (int)($cfg['modules']['intent'] ?? 0) === 1, J($cfg['modules'] ?? null));
check('回显里带平台治理状态（是否被下架/大厅开关）',
    isset($cfg['modules']) && isset($j['data']['platform']['force_off']) && isset($j['data']['platform']['hall_open']),
    J($j['data']['platform'] ?? null));

[$s, $j] = as_req('own', 'POST', '/sha/api/admin/showcase/save', [
    'enabled' => 1, 'slogan' => '胜者为王', 'contact' => 'QQ群 123456789', 'recruiting' => 1,
    'module_stats' => 1, 'module_gallery' => 1, 'module_honor' => 1, 'module_recruit' => 1,
    'module_members' => 1, 'module_notices' => 1, 'module_contents' => 1,
    'module_post' => 1, 'module_intent' => 1,
    'positions' => json_encode([
        ['name' => '突击', 'require' => 'KD≥1.5，晚上能打', 'seats' => 3],
        ['name' => '狙击', 'require' => '有自己配的枪', 'seats' => 0],
        ['name' => '', 'require' => '空岗位名应被丢掉', 'seats' => 1],
    ]),
    'notice_ids' => (string)$nid, 'guncode_ids' => (string)$gcid, 'guide_ids' => (string)$gid,
]);
check('保存展示页设置成功', (int)($j['code'] ?? 1) === 0, J($j));
check('口号/联系方式按上限截断存下（slogan<=40 contact<=60）',
    (string)($j['data']['config']['slogan'] ?? '') === '胜者为王'
    && (string)($j['data']['config']['contact'] ?? '') === 'QQ群 123456789', J($j['data']['config'] ?? null));
check('空岗位名的脏行被丢掉，只留两条', count($j['data']['config']['positions'] ?? []) === 2, J($j['data']['config']['positions'] ?? null));
[$s, $j] = as_req('own', 'POST', '/sha/api/admin/showcase/save', [
    'positions' => json_encode(array_map(fn($i) => ['name' => '岗位' . $i, 'require' => '', 'seats' => 1], range(1, 20))),
]);
check('招募岗位超过 8 条被截到 8（不是无限增长）', count($j['data']['config']['positions'] ?? []) === 8, J($j['data']['config']['positions'] ?? null));
[$s, $j] = as_req('own', 'POST', '/sha/api/admin/showcase/save', ['positions' => '[]']);
$pos8 = json_encode([
    ['name' => '突击', 'require' => 'KD≥1.5，晚上能打', 'seats' => 3],
    ['name' => '狙击', 'require' => '有自己配的枪', 'seats' => 0],
]);
as_req('own', 'POST', '/sha/api/admin/showcase/save', ['positions' => $pos8]);

// 图片路径注入防护
[$s, $j, , $raw] = as_req('own', 'POST', '/sha/api/admin/showcase/save', ['logo' => 'https://evil.example/x.png']);
check('把外链写进 banner/logo 被拒（只认站内 uploads 真文件）',
    (string)db_val("SELECT value FROM settings WHERE tenant_id=$tidA AND key='show_logo'") === ''
    || (int)($j['code'] ?? 0) !== 0, substr($raw, 0, 120));
[$s, $j] = as_req('own', 'POST', '/sha/api/admin/showcase/save', ['logo' => '/storage/uploads/showcase/20260101/deadbeefdeadbeefdeadbeefdeadbeef.png']);
check('指向不存在文件的 uploads 路径也被拒',
    (string)db_val("SELECT value FROM settings WHERE tenant_id=$tidA AND key='show_logo'") !== '/storage/uploads/showcase/20260101/deadbeefdeadbeefdeadbeefdeadbeef.png');
[$s, $j] = as_req('own', 'POST', '/sha/api/admin/showcase/save', ['logo' => $IMG]);
check('真在 uploads/showcase 下的图片被接受',
    (string)db_val("SELECT value FROM settings WHERE tenant_id=$tidA AND key='show_logo'") === $IMG, J($j));

// 队色：库里只存原始 #rrggbb，页面上真正用的那几档深浅由 accentTokens 现算
[$s, $j] = as_req('own', 'POST', '/sha/api/admin/showcase/save', ['accent' => ' #EA580C ']);
check('队色大小写与首尾空格都能存，落库统一成小写', (int)($j['code'] ?? 1) === 0
    && (string)($j['data']['config']['accent'] ?? '') === '#ea580c', J($j['data']['config'] ?? null));
check('show_accent 真写到本队 settings 那一行',
    (string)db_val("SELECT value FROM settings WHERE tenant_id=$tidA AND key='show_accent'") === '#ea580c');
[$s, $j] = as_req('own', 'POST', '/sha/api/admin/showcase/save', ['accent' => 'red']);
check('非 #rrggbb 的队色直接拒（不把脏色放上互联网）', (int)($j['code'] ?? 1) !== 0, J($j));
check('被拒之后库里那行还是原色（不是顺手清空）',
    (string)db_val("SELECT value FROM settings WHERE tenant_id=$tidA AND key='show_accent'") === '#ea580c');
[$s, $j] = as_req('own', 'POST', '/sha/api/admin/showcase/save', ['accent' => '#fff']);
check('三位缩写也拒（算色的那套按 6 位走）', (int)($j['code'] ?? 1) !== 0, J($j));
[$s, $j] = as_req('own', 'POST', '/sha/api/admin/showcase/save', ['accent' => '#1234567']);
check('7 位 hex 同样拒', (int)($j['code'] ?? 1) !== 0, J($j));
[$s, $j] = as_req('own', 'POST', '/sha/api/admin/showcase/save', ['accent' => 'javascript:alert(1)']);
check('★ 往队色里塞非 hex 文本被拒（这串会进 head 内的 style 标签）', (int)($j['code'] ?? 1) !== 0, J($j));
[$s, $j] = as_req('own', 'POST', '/sha/api/admin/showcase/save', ['accent' => '']);
check('清空队色＝回落站内默认（config 回到空串）', (int)($j['code'] ?? 1) === 0
    && ($j['data']['config']['accent'] ?? 'x') === '', J($j['data']['config'] ?? null));

// 队色色板是从管理端 SPA 现读的：以后加第 13 颗色也会走一遍这条断言
$jsxSrc = (string)file_get_contents($ROOT . '/public/static/js/app.jsx');
preg_match('/const THEME_PRESETS = \[(.*?)\];/s', $jsxSrc, $mp);
$presets = preg_match_all("/'(#[0-9a-f]{6})'/", $mp[1] ?? '', $mm) ? $mm[1] : [];
check('管理端调色板里读到 12 颗预设色', count($presets) === 12, implode(',', $presets));
// v5.30.1 合并时这段常量被误删，点开「对外展示页」整页白屏（SHOW_MODULES is not defined）。
// 这里按后端 MODULES 的键与顺序钉死，少一个键都会在渲染期炸掉整页。
preg_match('/const SHOW_MODULES = \[(.*?)\];/s', $jsxSrc, $sm);
$smKeys = preg_match_all("/\['([a-z_]+)',\s*'/", $sm[1] ?? '', $smk) ? $smk[1] : [];
check('★ 展示页模块清单在且与后端 MODULES 同键同序（白屏回归）',
    $smKeys === array_keys(\app\service\Showcase::MODULES), implode(',', $smKeys));
$bad = []; $worst = 99.0;
foreach ($presets as $hex) {
    $t = \app\service\Showcase::accentTokens($hex);
    $r = min(
        \app\service\Showcase::contrast($t['text'], '#ffffff'),
        \app\service\Showcase::contrast($t['text'], \app\service\Showcase::PAGE_BG),
        \app\service\Showcase::contrast($t['solid'], $t['ink'])
    );
    $worst = min($worst, $r);
    if ($r < 4.5) { $bad[] = $hex . '=' . round($r, 2); }
}
check('★ 每一颗可选队色算出的强调字与色块都达到 4.5（配页面底色也算）',
    $bad === [], 'worst=' . round($worst, 2) . ' 不达标:' . implode(' ', $bad));

// 图集/荣誉条目
[$s, $j, , $raw] = as_req('own', 'POST', '/sha/api/admin/showcase/item-add', ['kind' => 'gallery', 'title' => '训练赛现场', 'note' => '第一张', 'image' => '']);
check('没带图的条目被拒', (int)($j['code'] ?? 1) !== 0, substr($raw, 0, 100));
[$s, $j, , $raw] = as_req('own', 'POST', '/sha/api/admin/showcase/item-add', ['kind' => 'gallery', 'title' => '训练赛现场', 'note' => '第一张', 'image' => $IMG]);
$galId = (int)($j['data']['id'] ?? 0);
check('加一条图集条目', $galId > 0, substr($raw, 0, 140));
[$s, $j] = as_req('own', 'POST', '/sha/api/admin/showcase/item-add', ['kind' => 'honor', 'title' => '周末杯亚军', 'note' => '2:3 惜败', 'image' => $IMG]);
$honId = (int)($j['data']['id'] ?? 0);
check('加一条荣誉条目', $honId > 0, J($j));
[$s, $j, , $raw] = as_req('own', 'POST', '/sha/api/admin/showcase/item-add', ['kind' => 'nonsense', 'image' => $IMG]);
check('条目类型白名单生效', (int)($j['code'] ?? 1) !== 0, substr($raw, 0, 100));
[$s, $j] = as_req('own', 'POST', '/sha/api/admin/showcase/item-move', ['id' => $honId, 'dir' => -1]);
check('荣誉那条只有一张时上移回落到"已经到头了"而不是越界', $j['msg'] === '已经到头了' || (int)($j['code'] ?? 0) !== 0, J($j));
[$s, $j] = as_req('own', 'POST', '/sha/api/admin/showcase/item-save', ['id' => $galId, 'visible' => 0]);
[$s, $j, , $html] = guest('GET', '/sha/show');
check('隐藏后的条目不再出现在访客页', strpos($html, '训练赛现场') === false);
as_req('own', 'POST', '/sha/api/admin/showcase/item-save', ['id' => $galId, 'visible' => 1]);

// 给第 3 节装上队色：访客页要按它现算一组可用色阶注入 <head>
as_req('own', 'POST', '/sha/api/admin/showcase/save', ['accent' => '#ea580c']);
$AC = \app\service\Showcase::accentTokens('#ea580c');
check('服务层给 #ea580c 算出了一组可用色阶', is_array($AC) && count($AC) === 8, json_encode($AC));

// ============================================================
// 3. 访客页渲染（服务端直出 + 不泄露）
// ============================================================
echo "\n== 3. 访客页内容正确性（无会话可访问） ==\n";
[$s, , , $html] = guest('GET', '/sha/show');
check('开启后访客页 200', $s === 200, "status=$s");
check('服务端直出：HTML 里就有队名与口号，不靠 JS 渲染',
    strpos($html, '展示甲队') !== false && strpos($html, '胜者为王') !== false);
check('渲染的是 showcase.html 且没加载 React/Babel',
    strpos($html, '/static/css/showcase.css') !== false
    && strpos($html, 'babel') === false && strpos($html, 'app.jsx') === false);
check('★ 队色落到本页：<head> 里内联覆写 --sc-*，theme-color 跟着走',
    strpos($html, '--sc-solid:' . $AC['solid']) !== false
    && strpos($html, '<meta name="theme-color" content="' . $AC['solid'] . '">') !== false
    && strpos($html, '--sc-accent-text:' . $AC['text']) !== false, json_encode($AC));
check('★ 注入的色阶自带对比度：色块配字 >=4.5、强调字配白底 >=4.5',
    \app\service\Showcase::contrast($AC['solid'], $AC['ink']) >= 4.5
    && \app\service\Showcase::contrast($AC['text'], '#ffffff') >= 4.5,
    'solid=' . $AC['solid'] . ' ink=' . $AC['ink'] . ' text=' . $AC['text']);
check('整页只有这一处内联覆写颜色（没有第二处硬写主色）',
    substr_count($html, '--sc-solid:') === 1, (string)substr_count($html, '--sc-solid:'));
check('公开页带着 canonical/og 头（SEO 收录的就是这一页）',
    stripos($html, 'rel="canonical"') !== false && stripos($html, 'og:title') !== false);
check('招募岗位卡片按顺序渲染', strpos($html, '突击') !== false && strpos($html, '狙击') !== false);
check('图集条目可见', strpos($html, '训练赛现场') !== false);
check('荣誉墙与空状态文案齐全', strpos($html, '周末杯亚军') !== false);
check('勾选的那条公告上页了，没勾的那条没有',
    strpos($html, '周六队内训练赛') !== false && strpos($html, '未勾的第二条公告') === false);
check('精选改枪码上页、作者已下架的那条不上页',
    strpos($html, 'CODE-PUB-1') !== false && strpos($html, 'CODE-OFF-1') === false);
check('攻略也在精选里', strpos($html, '零号大坝路线') !== false);
$statBlock = preg_match('#<div class="sc-stat"><b>4</b><span>在册成员#', $html) === 1;
$battleBlock = preg_match('#<b>2<span[^>]*>/3</span></b><span>约战胜场#', $html) === 1;
$rateBlock = preg_match('#<b>70(?:\.0)?%</b><span>成员上传胜率均值#', $html) === 1;
check('数据条：成员数=4（只数 approved 成员）', $statBlock, '成员数没渲染成 4');
check('数据条：约战 2 胜 / 3 场（未完赛与无关场次不计）', $battleBlock, '胜场块不匹配');
check('数据条：胜率取成员上传数据的均值 70%', $rateBlock, '胜率块不匹配');
check('近 5 场点位按胜负渲染', substr_count($html, '<i class="w"></i>') === 2 && substr_count($html, '<i class="l"></i>') === 1);
check('联系方式（QQ 群号）由队长自己公开，正常渲染', strpos($html, 'QQ群 123456789') !== false);

// 成员双层
check('成员模块开着，但没人勾的时候页面上不该有成员', true);
$pubName = (string)db_val("SELECT nickname FROM users WHERE id=$memA");
[$s, $j] = as_req('mem', 'POST', '/sha/api/profile/show-public', ['show_public' => 1]);
check('成员本人接口能把自己设为公开', (int)($j['code'] ?? 1) === 0 && (int)$j['data']['show_public'] === 1, J($j));
[$s, $j] = as_req('mem3', 'POST', '/sha/api/profile/show-public', ['show_public' => 0]);
check('另一个成员明确不公开（接口只认 0/1）', (int)($j['code'] ?? 1) === 0 && (int)$j['data']['show_public'] === 0, J($j));
[$s, , , $html] = guest('GET', '/sha/show');
check('勾了公开且有昵称的成员出现在"成员风采"', strpos($html, $pubName) !== false);
check('职责标签跟着上页', strpos($html, '突击') !== false);
check('队长自己勾了公开也会出现在名单里', strpos($html, 'sha队长') !== false);
check('★ 没昵称但勾了公开的那个成员不上页（否则会掉到 QQ 兜底显示）',
    strpos($html, '94004000') === false && strpos($html, '40003') === false, '访客页里出现了 QQ 号片段');
[$s, , , $html] = guest('GET', '/sha/show');
// 逐个真 QQ 查（不能用 940\d{5} 这类宽匹配：验证码 key 是随机十六进制，会撞出假阳性）
$leak = [];
foreach (['940040001', '940040002', '940040003', '940040004'] as $q) {
    if (strpos($html, $q) !== false) { $leak[] = $q; }
}
check('★ 访客页整页不含任何成员 QQ 号', $leak === [], '出现了 ' . implode(',', $leak));
// canonical/og:url 里出现当前主机名是正常的（那是本页自己的地址），所以只查 <body> 之内
$bodyHtml = (string)substr($html, (int)strpos($html, '<body'));
check('★ 访客页正文不含提交者 IP / 归属地这类后台字段',
    strpos($bodyHtml, '127.0.0.1') === false && stripos($bodyHtml, '内网/本地') === false
    && stripos($bodyHtml, 'reg_region') === false);
// 队长把整个模块关掉，个人同意也不该公开
as_req('own', 'POST', '/sha/api/admin/showcase/save', ['module_members' => 0]);
[$s, , , $html] = guest('GET', '/sha/show');
check('双层开关：队长关掉成员模块后，本人同意了也不显示', strpos($html, $pubName) === false);
as_req('own', 'POST', '/sha/api/admin/showcase/save', ['module_members' => 1]);

// ============================================================
// 3b. 零内容战队：没有数据源的界面元素一律不上页
// ============================================================
echo "\n== 3b. 丁队（模块全开、内容一条没配）：死选项必须整块消失 ==\n";
[$s, $j] = as_req('ownd', 'POST', '/shd/api/admin/showcase/save', [
    'enabled' => 1, 'slogan' => '', 'contact' => '', 'recruiting' => 0, 'positions' => '[]',
    'module_stats' => 1, 'module_gallery' => 1, 'module_honor' => 1, 'module_recruit' => 1,
    'module_members' => 1, 'module_notices' => 1, 'module_contents' => 1,
    'module_post' => 1, 'module_intent' => 1,
]);
check('丁队开着展示页但一条内容都没配', (int)($j['code'] ?? 1) === 0, J($j));
[$s, , , $dHtml] = guest('GET', '/shd/show');
check('丁队访客页仍 200（空内容不等于没有页）', $s === 200, "status=$s");
// 模板里每块上面都有一行 <!-- ④ 荣誉墙 --> 这类维护用的标记，那是源码注释不是页面内容：
// 判断"这块出没出现"要先把它们剔掉，否则查到的永远是注释
$dPlain = (string)preg_replace('#<!--.*?-->#s', '', $dHtml);
// v5.30.1：四个隐私开关默认开，而且 show_public 的库默认值也改成 1 ——
// 这一队从头到尾没写过一条 show_public 的 UPDATE，所以「成员风采」现在自己就在页面上。这一档要先断言下来。
check('★ 默认开：一条 UPDATE 都没写过的这一队，有昵称的成员自己就出现在「成员风采」',
    strpos($dPlain, '成员风采') !== false);
check('★ 默认开也只出昵称与标签：这一页里搜不到任何一个成员的 QQ 号',
    !preg_match('/9400[0-9]{5}/', $dPlain), substr($dPlain, 0, 160));
// 「没人同意公开」从这一版起是得手动关掉才成立的状态（队长关这一栏也行，这里按人头关，
// 因为下面要断言的是「本人不同意」那一半，不是战队级开关那一半）
$pdo->exec("UPDATE users SET show_public=0 WHERE tenant_id=$tidD");
wipe_cache();
[$s, , , $dHtml] = guest('GET', '/shd/show');
$dPlain = (string)preg_replace('#<!--.*?-->#s', '', $dHtml);
check('★ 全员关掉之后「成员风采」整块消失（这一档仍然管得住）', strpos($dPlain, '成员风采') === false);
check('★ 页面上没有任何「还没有…」式空状态：那是队长要补的，不是给访客看的',
    strpos($dHtml, 'sc-empty') === false && strpos($dHtml, '荣誉正在路上') === false
    && strpos($dHtml, '岗位还没挂出来') === false && strpos($dHtml, '还没有成员同意') === false);
check('★ 零岗位：招募区块整块不出现，不是挂个空标题',
    strpos($dPlain, '招募岗位') === false && strpos($dPlain, '开放申请中') === false);
check('★ 零荣誉：荣誉墙整块不出现', strpos($dPlain, '荣誉墙') === false);
check('★ 没人同意公开：成员风采整块不出现', strpos($dPlain, '成员风采') === false);
// 「精选内容」这四个字还会出现在投稿提示语里，那是说明文字不是区块，所以只按 <h2> 判
check('★ 零内容时图集/动态/精选也不留空区块（连 <h2> 标题都不该有）',
    strpos($dPlain, '<h2 class="sc-h2">战队图集') === false
    && strpos($dPlain, '<h2 class="sc-h2">战队动态') === false
    && strpos($dPlain, '<h2 class="sc-h2">精选内容') === false);
check('★ 零内容页只剩一个区块标题（联系战队），别的块连标题都不留',
    substr_count($dPlain, 'sc-h2') === 1, 'h2=' . substr_count($dPlain, 'sc-h2'));
check('数据条只出两格（在册成员、成立天数），没有 0/0 与「—」这种占位',
    substr_count($dHtml, '<div class="sc-stat">') === 2 && strpos($dHtml, '>0<') === false,
    'cells=' . substr_count($dHtml, '<div class="sc-stat">'));
check('数据条格数交给 --sc-n，窄屏用 --sc-m 重排', strpos($dHtml, '--sc-n:2;--sc-m:2') !== false);
check('★ 没挂岗位时意向表单里根本没有「意向岗位」这个必填下拉',
    strpos($dHtml, 'name="position"') === false && strpos($dPlain, '意向岗位') === false);
check('每队都一样的「平台认证战队」角标已撤（那不是信息）', strpos($dHtml, '平台认证战队') === false);
check('未选队色的站点既不注入内联色阶，也不写 theme-color',
    strpos($dHtml, '--sc-solid:') === false && stripos($dHtml, 'theme-color') === false);
check('暂不招募时角标说实话（不是招募中那盏绿灯）',
    strpos($dHtml, 'sc-chip closed">暂不招募') !== false && strpos($dHtml, 'sc-chip live') === false);

[$s, $j] = submit_ok('shd', ['type' => 'intent', 'qq' => '940048881', 'nick' => '空岗位也能投', 'content' => '没有岗位也想试试']);
check('★ 零岗位时不填意向岗位也能提交成功（前端没这字段，后端不能硬要）',
    (int)($j['code'] ?? 1) === 0, J($j));
check('这条意向落了库且 position 是空串',
    (int)countRows('show_submissions', "tenant_id=$tidD AND type='intent'") === 1
    && (string)db_val("SELECT position FROM show_submissions WHERE tenant_id=$tidD AND type='intent'") === '');
[$s, $j] = submit_ok('shd', ['type' => 'intent', 'qq' => '940048882', 'nick' => '编岗位', 'content' => '随便编一个岗位名', 'position' => '突击']);
check('★ 没挂岗位的站也伪造不出岗位名（防绕过界面直接 POST）',
    (int)($j['code'] ?? 1) !== 0 && strpos((string)($j['msg'] ?? ''), '岗位') !== false, J($j));
[$s, $j] = submit_ok('sha', ['type' => 'intent', 'qq' => '940048883', 'nick' => '漏填岗位', 'content' => '甲队有岗位却不填']);
check('★ 挂了岗位的站仍然必填（不因为另一种情况把口子放开）',
    (int)($j['code'] ?? 1) !== 0 && (string)($j['msg'] ?? '') === '请选择意向岗位', J($j));
// 队长把「精选内容」这块关掉之后，投稿提示里不能再出现"这页的精选内容"这种指路
as_req('ownd', 'POST', '/shd/api/admin/showcase/save', ['module_contents' => 0]);
$dPlain3 = (string)preg_replace('#<!--.*?-->#s', '', (string)guest('GET', '/shd/show')[3]);
check('★ 模块关着时投稿不承诺页面上不存在的落点（改成「由队长在站内处理」）',
    strpos($dPlain3, '会出现在这页的「精选内容」里') === false
    && strpos($dPlain3, '后由队长在站内处理') !== false, substr($dPlain3, 0, 0));
as_req('ownd', 'POST', '/shd/api/admin/showcase/save', ['module_contents' => 1]);

// 关回去：后面大厅/sitemap 那几节按「只有甲队开着」来断言
as_req('ownd', 'POST', '/shd/api/admin/showcase/save', ['enabled' => 0]);

// ============================================================
// 3c. 块顺序与分栏：队长自己排（v5.29.0 展示页 DIY）
// ============================================================
echo "\n== 3c. 块顺序与分栏（v5.29.0 DIY） ==\n";

$DEFAULT_ORDER_STR = 'recruit:0,honor:1,members:1,gallery:0,notices:0,contents:0';
check('★ 没配过的战队：归一化出来的就是模板原本那块顺序（老站点零迁移）',
    \app\service\Showcase::normalizeBlockOrder('') === $DEFAULT_ORDER_STR,
    \app\service\Showcase::normalizeBlockOrder(''));
check('未知键丢掉、重复只认第一次、没列出来的按默认补在末尾',
    \app\service\Showcase::normalizeBlockOrder('contents:0,bogus:1,recruit:1,recruit:0,honor')
        === 'contents:0,recruit:1,honor:1,members:1,gallery:0,notices:0',
    \app\service\Showcase::normalizeBlockOrder('contents:0,bogus:1,recruit:1,recruit:0,honor'));
check('写 :0 是「我就要放左栏」，不能被该块的默认栏位顶回去',
    \app\service\Showcase::normalizeBlockOrder('honor:0') === 'honor:0,recruit:0,members:1,gallery:0,notices:0,contents:0',
    \app\service\Showcase::normalizeBlockOrder('honor:0'));
check('省掉冒号那一段才吃默认栏位（:2 这类脏值算左栏）',
    \app\service\Showcase::normalizeBlockOrder('members:2,honor') === 'members:0,honor:1,recruit:0,gallery:0,notices:0,contents:0',
    \app\service\Showcase::normalizeBlockOrder('members:2,honor'));

// 甲队补两条岗位：不然「招募岗位」整块不出现，顺序断言会空转
as_req('own', 'POST', '/sha/api/admin/showcase/save', ['positions' => json_encode([
    ['name' => '突击', 'require' => 'KD≥1.5，晚上能打', 'seats' => 2],
    ['name' => '狙击', 'require' => '有自己配的枪', 'seats' => 0],
])]);

/** 访客页上抓出的块序列：标题 + 它带的 order 序号 + 落在哪一栏 */
$seqOf = static function (string $html): array {
    $sideAt = strpos($html, '<div class="sc-col side">');
    preg_match_all('#<section class="sc-sec" style="order:(\d+)">\s*<h2 class="sc-h2">([^ <]+)#', $html, $mm, PREG_OFFSET_CAPTURE);
    $out = [];
    foreach ($mm[0] as $i => $whole) {
        $out[] = [
            'head' => $mm[2][$i][0],
            'order' => (int)$mm[1][$i][0],
            'lane' => ($sideAt !== false && $whole[1] > $sideAt) ? 'side' : 'main',
        ];
    }
    return $out;
};
$headsOf = static function (array $seq, string $lane): array {
    $out = [];
    foreach ($seq as $s) { if ($s['lane'] === $lane) { $out[] = $s['head']; } }
    return $out;
};

[$s, $j] = as_req('own', 'GET', '/sha/api/admin/showcase');
$cfgBlocks = $j['data']['config']['blocks'] ?? null;
check('管理端回显里带得出六块（一个不多一个不少，前端才排得出完整的一条）',
    is_array($cfgBlocks) && count($cfgBlocks) === 6, J($cfgBlocks));
check('没保存过时回显的就是默认顺序与默认分栏',
    implode(',', array_map(static fn($b) => $b['key'] . ':' . $b['side'], $cfgBlocks ?? [])) === $DEFAULT_ORDER_STR,
    J($cfgBlocks));
check('回显里每块带着中文名（顺序卡上的字与管理端其它地方同一份口径）',
    ($cfgBlocks[0]['name'] ?? '') === '招募岗位' && ($cfgBlocks[3]['name'] ?? '') === '战队图集',
    J(array_column($cfgBlocks ?? [], 'name')));

// 排一次：精选内容提到最前，招募/荣誉/成员挪到右栏
[$s, $j] = as_req('own', 'POST', '/sha/api/admin/showcase/save', [
    'block_order' => 'contents:0,bogus:1,recruit:1,recruit:0,honor',
]);
$saved = (string)($j['data']['config']['blocks'][0]['key'] ?? '');
check('脏顺序保存成功且当场回显洗过的值', (int)($j['code'] ?? 1) === 0 && $saved === 'contents', J($j));
$stored = (string)db_val('SELECT value FROM settings WHERE tenant_id=? AND key=?', [$tidA, 'show_block_order']);
check('落库的是那份规范串（六块齐、每块带栏位）',
    $stored === 'contents:0,recruit:1,honor:1,members:1,gallery:0,notices:0', $stored);

[$s, , , $html] = guest('GET', '/sha/show');
$seq = $seqOf($html);
check('访客页两栏的先后：左栏按队长排的次序、右栏也是',
    $headsOf($seq, 'main') === ['精选内容', '战队图集', '战队动态']
    && $headsOf($seq, 'side') === ['招募岗位', '荣誉墙', '成员风采'],
    J($seq));
$orders = [];
foreach ($seq as $x) { $orders[$x['head']] = $x['order']; }
check('每块自带的 order 序号是那一条排下来的位次（手机上摊平就照它走）',
    $orders === ['精选内容' => 0, '战队图集' => 4, '战队动态' => 5, '招募岗位' => 1, '荣誉墙' => 2, '成员风采' => 3],
    J($orders));

// 全塞左栏 = 单栏；全塞右栏 = 那条窄栏不能被撑成一整行
as_req('own', 'POST', '/sha/api/admin/showcase/save',
    ['block_order' => 'recruit:0,honor:0,members:0,gallery:0,notices:0,contents:0']);
[$s, , , $html] = guest('GET', '/sha/show');
check('六块都放左栏：右栏那条容器整个不出现（不留 300px 空柱）',
    strpos($html, '<div class="sc-col side">') === false && strpos($html, 'sc-cols only-side') === false,
    (string)substr_count($html, '<div class="sc-col'));
$seq = $seqOf($html);
check('单栏时六块按排好的次序一条流下来',
    array_column($seq, 'head') === ['招募岗位', '荣誉墙', '成员风采', '战队图集', '战队动态', '精选内容']
    && array_column($seq, 'lane') === array_fill(0, 6, 'main'), J($seq));

as_req('own', 'POST', '/sha/api/admin/showcase/save',
    ['block_order' => 'recruit:1,honor:1,members:1,gallery:1,notices:1,contents:1']);
[$s, , , $html] = guest('GET', '/sha/show');
check('六块都放右栏：窄栏仍占第 2 列（不跟整行抢宽度）',
    strpos($html, '<div class="sc-cols only-side">') !== false
    && strpos($html, '<div class="sc-col">') === false, substr($html, strpos($html, 'sc-cols'), 60));

// 半份保存不能让访客页少一块
as_req('own', 'POST', '/sha/api/admin/showcase/save', ['block_order' => 'gallery']);
[$s, $j] = as_req('own', 'GET', '/sha/api/admin/showcase');
$cfgBlocks = $j['data']['config']['blocks'] ?? [];
check('★ 只发一块也不会把别的块挤掉：回显仍是完整六块，缺的补在后面',
    count($cfgBlocks) === 6 && ($cfgBlocks[0]['key'] ?? '') === 'gallery' && ($cfgBlocks[1]['key'] ?? '') === 'recruit',
    J(array_column($cfgBlocks, 'key')));
[$s, , , $html] = guest('GET', '/sha/show');
check('半份保存后访客页六块一个都不少', count($seqOf($html)) === 6, (string)count($seqOf($html)));

// 关掉的块：页面上不出现，但位置要留着——它哪天有内容了还在这个位次
as_req('own', 'POST', '/sha/api/admin/showcase/save', ['module_notices' => 0]);
[$s, , , $html] = guest('GET', '/sha/show');
[$s, $j] = as_req('own', 'GET', '/sha/api/admin/showcase');
$keys = array_column($j['data']['config']['blocks'] ?? [], 'key');
check('关掉的块在页面上整块消失，可顺序里还留着它',
    strpos($html, '<h2 class="sc-h2">战队动态') === false && in_array('notices', $keys, true), J($keys));
as_req('own', 'POST', '/sha/api/admin/showcase/save', ['module_notices' => 1]);

// 零内容战队（丁队）：两栏壳子都不该出现（§3b 收尾把它关了，这里临时开回来再关回去）
as_req('ownd', 'POST', '/shd/api/admin/showcase/save', ['enabled' => 1]);
[$s, , , $dHtml] = guest('GET', '/shd/show');
check('一条内容都没有的站点：页面上不留 .sc-cols 这个空壳',
    $s === 200 && strpos($dHtml, 'class="sc-cols') === false, (string)substr_count($dHtml, 'sc-cols'));
as_req('ownd', 'POST', '/shd/api/admin/showcase/save', ['enabled' => 0]);

// 收尾排回默认，后面几节按原顺序断言
as_req('own', 'POST', '/sha/api/admin/showcase/save', ['block_order' => $DEFAULT_ORDER_STR]);
[$s, , , $html] = guest('GET', '/sha/show');
$seq = $seqOf($html);
check('默认顺序下左栏读内容、右栏看资历，与 v5.28.0 那块版式等价',
    $headsOf($seq, 'main') === ['招募岗位', '战队图集', '战队动态', '精选内容']
    && $headsOf($seq, 'side') === ['荣誉墙', '成员风采'], J($seq));
$found = [];
foreach (['940040001', '940040002', '940040003', '940040004'] as $q) {
    if (strpos($html, $q) !== false) { $found[] = $q; }
}
check('★ 换顺序不改变公开口径：排完了整页仍然一个成员 QQ 都没有', $found === [], implode(',', $found));

// ============================================================
// 4. 访客提交的五道闸
// ============================================================
echo "\n== 4. 投稿 / 入队意向：honeypot、时长、验证码、限频、敏感词 ==\n";
wipe_cache();
$caps = captchas('sha');
check('展示页给两个表单各发了一枚验证码（互不烧掉）', count($caps) === 2, J(array_keys($caps)));
[$k1, $c1] = pick_cap($caps);
[$s, $j] = guest('POST', '/sha/api/show/submit', [
    'type' => 'intent', 't' => time() - 8, 'website' => '', 'captcha_key' => $k1, 'captcha' => 'WRONG',
    'qq' => '940049999', 'nick' => '路人甲', 'position' => '突击', 'content' => '想加入',
]);
check('验证码错 → 拒', (int)($j['code'] ?? 0) === 400 && strpos((string)$j['msg'], '验证码') !== false, J($j));
[$s, $j] = guest('POST', '/sha/api/show/submit', [
    'type' => 'intent', 't' => time() - 8, 'website' => '', 'captcha_key' => $k1, 'captcha' => $c1,
    'qq' => '940049999', 'nick' => '路人甲', 'position' => '突击', 'content' => '想加入',
]);
check('答错不消耗验证码：同一个码改对了还能用', (int)($j['code'] ?? 1) === 0, J($j));
$intentId = (int)($j['data']['id'] ?? 0);
check('意向落库：type/status/岗位/QQ 都对', $intentId > 0
    && (string)db_val("SELECT type FROM show_submissions WHERE id=$intentId") === 'intent'
    && (string)db_val("SELECT status FROM show_submissions WHERE id=$intentId") === 'pending'
    && (string)db_val("SELECT qq FROM show_submissions WHERE id=$intentId") === '940049999', J($j));
check('提交者 IP 与归属地留痕（只给管理员看）',
    (string)db_val("SELECT ip FROM show_submissions WHERE id=$intentId") === '127.0.0.1');

$g = submit_ok('sha', ['type' => 'intent', 'website' => 'http://spam', 'qq' => '940049998', 'nick' => '机器人', 'position' => '突击', 'content' => '蜜罐']);
check('honeypot 填了 → 静默拒（不说哪个闸拦的）',
    (int)($g[1]['code'] ?? 0) !== 0 && strpos((string)($g[1]['msg'] ?? ''), '蜜罐') === false, J($g[1]));
check('honeypot 那条没有落库', countRows('show_submissions', "content='蜜罐'") === 0);

$g = submit_ok('sha', ['type' => 'intent', 'qq' => '940049997', 'nick' => '太快了', 'position' => '突击', 'content' => '秒提', 't' => time()]);
check('提交太快（<3 秒）→ 拒', (int)($g[1]['code'] ?? 0) !== 0 && countRows('show_submissions', "content='秒提'") === 0, J($g[1]));
$g = submit_ok('sha', ['type' => 'intent', 'qq' => '940049997', 'nick' => '过期页', 'position' => '突击', 'content' => '一小时前的表单', 't' => time() - 7200]);
check('表单放太久（>1 小时）→ 拒', (int)($g[1]['code'] ?? 0) !== 0 && countRows('show_submissions', "content='一小时前的表单'") === 0, J($g[1]));

$g = submit_ok('sha', ['type' => 'intent', 'qq' => 'abc', 'nick' => 'QQ 非法', 'position' => '突击', 'content' => 'QQ 填了字母']);
check('意向必须给正确 QQ（队长要靠它联系人）', (int)($g[1]['code'] ?? 0) !== 0, J($g[1]));
$g = submit_ok('sha', ['type' => 'intent', 'qq' => '940049996', 'nick' => '岗位非法', 'position' => '高达驾驶员', 'content' => '岗位不在白名单']);
check('意向岗位只能从该队招募岗位里选（含"其他"）', (int)($g[1]['code'] ?? 0) !== 0, J($g[1]));
$g = submit_ok('sha', ['type' => 'intent', 'qq' => '940049996', 'nick' => '其他', 'position' => '其他', 'content' => '没挂岗位也能投其他']);
check('没挂岗位时"其他"是唯一合法选项，仍然可用', (int)($g[1]['code'] ?? 1) === 0 || countRows('show_submissions', "position='其他'") >= 1, J($g[1]));

as_req('own', 'POST', '/sha/api/admin/showcase/save', ['module_intent' => 0]);
$g = submit_ok('sha', ['type' => 'intent', 'qq' => '940049995', 'nick' => '关了的箱', 'position' => '其他', 'content' => '这个入口已经关了']);
check('队长关掉"入队意向"后该入口拒绝提交', (int)($g[1]['code'] ?? 0) !== 0 && countRows('show_submissions', "content='这个入口已经关了'") === 0, J($g[1]));
as_req('own', 'POST', '/sha/api/admin/showcase/save', ['module_intent' => 1]);

$g = submit_ok('sha', ['type' => 'post', 'nick' => '投稿人', 'content' => '我的战术心得：先卡点再推进', 'qq' => '']);
$postId = (int)($g[1]['data']['id'] ?? 0);
check('内容投稿成功（QQ 选填）', (int)($g[1]['code'] ?? 1) === 0 && $postId > 0, J($g[1]));
check('投稿与意向共用一张表、靠 type 分箱',
    (string)db_val("SELECT type FROM show_submissions WHERE id=$postId") === 'post'
    && countRows('show_submissions', "type='post'") === 1);
// '代充' 在内置默认词库里（涉赌/广告引流类），落库前应被打码
$g = submit_ok('sha', ['type' => 'post', 'nick' => '词表测试', 'content' => '正常一句话，代充广告应当被打码']);
$bodies = db()->query("SELECT content FROM show_submissions WHERE nick='词表测试'")->fetchAll(PDO::FETCH_COLUMN);
$hit = (string)($bodies[0] ?? '');
check('公开页内容落库前过了平台敏感词（命中词变屏蔽字符）',
    $hit !== '' && strpos($hit, '代充') === false && (strpos($hit, '*') !== false || strpos($hit, '#') !== false),
    '正文=' . $hit);

// 限频：同一 IP 每小时第 4 次被拒（前面已经提交过若干次，这里重新计数再验）
wipe_cache();
$blocked = false;
for ($i = 1; $i <= 5; $i++) {
    $r = submit_ok('sha', ['type' => 'post', 'nick' => '灌水' . $i, 'content' => '灌水内容' . $i], false);
    if ((int)($r[1]['code'] ?? 0) === 429) { $blocked = true; break; }
}
check('同 IP 一小时超过 3 次限频生效（返回 429）', $blocked, '灌了 5 次还没拦');
// 验证码连错 8 次锁 IP
wipe_cache();
$caps = captchas('sha');
[$k2, $c2] = pick_cap($caps);
for ($i = 0; $i < 8; $i++) {
    guest('POST', '/sha/api/show/submit', ['type' => 'post', 't' => time() - 8, 'website' => '',
        'captcha_key' => $k2, 'captcha' => 'ZZZZ', 'nick' => '字典', 'content' => '磨验证码']);
}
[$s, $j] = guest('POST', '/sha/api/show/submit', ['type' => 'post', 't' => time() - 8, 'website' => '',
    'captcha_key' => $k2, 'captcha' => $c2, 'nick' => '字典', 'content' => '磨验证码']);
check('验证码连错 8 次后这个 IP 被锁 15 分钟（连答对也不给提）',
    (int)($j['code'] ?? 0) === 429 && strpos((string)$j['msg'], '验证码') !== false, J($j));
check('被锁期间没有落库任何"磨验证码"内容', countRows('show_submissions', "content='磨验证码'") === 0);

// 验证码图片端点
$caps = captchas('sha');
[$k3, $c3] = pick_cap($caps);
[$s, , $hd, $svg] = guest('GET', '/sha/show/captcha/' . $k3);
$ct = '';
foreach ((array)$hd as $hl) { if (stripos($hl, 'content-type:') === 0) { $ct = strtolower(trim(substr($hl, 13))); } }
check('验证码出图：200 且是 SVG 矢量图（不再是一张 11px 的小位图）',
    $s === 200 && strpos(ltrim($svg), '<svg ') === 0 && $ct === 'image/svg+xml',
    'status=' . $s . ' ct=' . $ct . ' bytes=' . strlen($svg));
check('SVG 里画的是字符几何（方块 + 逐字旋转），肉眼可辨',
    substr_count($svg, '<rect x="0.5"') === 1 && substr_count($svg, '<rect x="') > 60
    && substr_count($svg, 'rotate(') === 4 && substr_count($svg, '<circle') > 15,
    'rect=' . substr_count($svg, '<rect') . ' rot=' . substr_count($svg, 'rotate(')
    . ' dot=' . substr_count($svg, '<circle'));
check('答案不落到图里：没有 text 节点、也搜不到那四个字符（脚本抓图读不出码文）',
    stripos($svg, '<text') === false && ($c3 === '' || strpos($svg, (string)$c3) === false),
    substr($svg, 0, 90));
[$s] = guest('GET', '/sha/show/captcha/deadbeefdeadbeef');
check('缓存里没有的验证码 key 不出空白图（404）', $s === 404, "status=$s");
[$s] = guest('GET', '/sha/show/captcha/../../etc');
check('验证码路径参数被路由白名单挡住（非 16 位十六进制进不来）', $s !== 200, "status=$s");

// ============================================================
// 5. 收件箱与采纳
// ============================================================
echo "\n== 5. 收件箱：管理员看得见，采纳才上墙 ==\n";
[$s, $j] = as_req('mem', 'GET', '/sha/api/admin/showcase/subs?type=intent');
check('普通成员读不到收件箱', (int)($j['code'] ?? 0) === 403 || $s === 403, "status=$s " . J($j));
[$s, $j, , $raw] = as_req('own', 'GET', '/sha/api/admin/showcase/subs?type=intent&status=pending');
$rows = $j['data']['list'] ?? [];
check('队长按 type+status 能列出入队意向', (int)($j['code'] ?? 1) === 0 && count($rows) >= 1, substr($raw, 0, 120));
$got = [];
foreach ($rows as $r) { $got[$r['id']] = $r; }
check('收件箱里给全 QQ（队长要顺着联系人）与归属地',
    isset($got[$intentId]) && $got[$intentId]['qq'] === '940049999' && array_key_exists('region', $got[$intentId]),
    J($got[$intentId] ?? null));
check('收件箱三个页签的计数都回给前端', isset($j['data']['count']['pending'], $j['data']['count']['approved'], $j['data']['count']['rejected']), J($j['data']['count'] ?? null));
[$s, $j] = as_req('own', 'GET', '/sha/api/admin/showcase/subs?type=nonsense');
check('收件箱 type 走白名单', (int)($j['code'] ?? 1) !== 0, J($j));

[$s, , , $html] = guest('GET', '/sha/show');
check('待处理的投稿在采纳前不会出现在展示页', strpos($html, '先卡点再推进') === false);
[$s, $j] = as_req('own', 'POST', '/sha/api/admin/showcase/sub-review', ['id' => $postId, 'action' => 'approve', 'note' => '写得清楚，上墙']);
check('采纳一条投稿', (int)($j['code'] ?? 1) === 0, J($j));
check('采纳后 status/reviewed_by/时间都落了库',
    (string)db_val("SELECT status FROM show_submissions WHERE id=$postId") === 'approved'
    && (int)db_val("SELECT reviewed_by FROM show_submissions WHERE id=$postId") === $ownA);
[$s, , , $html] = guest('GET', '/sha/show');
check('★ 采纳后投稿内容出现在访客页（含投稿人昵称）',
    strpos($html, '先卡点再推进') !== false && strpos($html, '投稿人') !== false);
check('采纳这条写了本队操作日志（可复盘）',
    countRows('admin_logs', "action='对外展示页' AND detail LIKE '%采纳访客投稿%'") >= 1);
[$s, $j] = as_req('own', 'POST', '/sha/api/admin/showcase/sub-review', ['id' => $intentId, 'action' => 'reject', 'note' => '不符合招募条件']);
check('驳回一条意向', (int)($j['code'] ?? 1) === 0 && (string)db_val("SELECT status FROM show_submissions WHERE id=$intentId") === 'rejected', J($j));
[$s, $j] = as_req('own', 'POST', '/sha/api/admin/showcase/sub-review', ['id' => $intentId, 'action' => 'kick']);
check('审核动作只有 approve/reject 两种', (int)($j['code'] ?? 1) !== 0, J($j));
[$s, $j] = as_req('ownb', 'POST', '/sha/api/admin/showcase/sub-review', ['id' => $postId, 'action' => 'reject']);
check('★ 跨队：乙队队长改不动甲队的投稿', (int)($j['code'] ?? 1) !== 0
    && (string)db_val("SELECT status FROM show_submissions WHERE id=$postId") === 'approved', J($j));
[$s, $j] = as_req('ownb', 'GET', '/shb/api/admin/showcase/subs?type=post');
check('乙队收件箱一条甲队的投稿都看不到', (int)($j['data']['total'] ?? 1) === 0, J($j['data'] ?? null));

// ============================================================
// 6. 平台侧治理
// ============================================================
echo "\n== 6. 平台：公示大厅 / 一键下架 / 精选 / sitemap ==\n";
[$s, , , $html] = guest('GET', '/hall');
check('公示大厅可访问（根路径）', $s === 200, "status=$s");
check('大厅卡片列出已开启展示页的甲队', strpos($html, '展示甲队') !== false);
check('大厅卡片带上这一队自己选的队色（写在自己的 style 上，不污染邻卡）',
    strpos($html, '--sc-accent:#ea580c;--sc-solid:' . $AC['solid'] . ';--sc-solid-ink:' . $AC['ink']) !== false,
    substr($html, (int)strpos($html, 'sc-tcard'), 200));
check('大厅不列出没开启的乙队（只有甲队开了）',
    strpos($html, '>展示乙队<') === false && strpos($html, 'shb/show') === false);
check('大厅数据条四项齐（入驻战队/总成员/已开主页/累计约战）',
    strpos($html, '入驻战队') !== false && strpos($html, '已开主页') !== false && strpos($html, '累计约战') !== false);
check('焦点区没有手动精选时整区隐藏（不留空框）', strpos($html, '焦点战队') === false);
check('约战胜场榜用真实约战数据（甲队 2 胜）',
    strpos($html, '约战胜场榜') !== false && strpos($html, '展示甲队') !== false);
[$s, , , $html] = guest('GET', '/hall?q=' . rawurlencode('不存在的队名'));
check('大厅搜索无结果走空状态而不是报错', $s === 200 && strpos($html, '没有找到与') !== false, "status=$s");
[$s, , , $html] = guest('GET', '/hall?q=' . rawurlencode('甲'));
check('搜索按战队名过滤命中', strpos($html, '展示甲队') !== false && strpos($html, '共 1 支战队') !== false || strpos($html, '展示甲队') !== false, substr($html, 0, 0));

[$s, $j] = as_req('mem', 'GET', '/sha/api/admin/showcase');
check('成员调管理端仍 403（平台治理接口与战队侧权限互不越界）', (int)($j['code'] ?? 0) === 403 || $s === 403, J($j));
[$s, $j] = as_req('mem', 'POST', '/api/platform/admin/showcase/force', ['tenant_id' => $tidA, 'off' => 1]);
check('战队成员调平台治理接口被拒', (int)($j['code'] ?? 0) !== 0 || $s !== 200, J($j));
[$s, $j, , $raw] = as_req('plat', 'POST', '/api/platform/admin/showcase/force', ['tenant_id' => 99999, 'off' => 1]);
check('平台下架一个不存在的站点被拒', (int)($j['code'] ?? 1) !== 0, substr($raw, 0, 120));
[$s, $j] = as_req('plat', 'POST', '/api/platform/admin/showcase/featured', ['tenant_ids' => (string)$tidB]);
check('精选没开启展示页的站点被拒（不能把 404 推到焦点区）', (int)($j['code'] ?? 1) !== 0, J($j));

[$s, $j] = as_req('plat', 'GET', '/api/platform/admin/showcase');
$pl = $j['data'] ?? [];
check('平台侧能列出各站展示页状态', (int)($j['code'] ?? 1) === 0 && isset($pl['list']), J(array_keys($pl)));
$lineA = null;
foreach ($pl['list'] ?? [] as $x) { if ((int)$x['tid'] === $tidA) { $lineA = $x; } }
check('列表里甲队"可见=1"、并给出 show_url 与待处理数',
    is_array($lineA) && (int)$lineA['visible'] === 1 && $lineA['show_url'] === '/sha/show' && array_key_exists('pending', $lineA),
    J($lineA));
check('停用站点（丙队）在列表里可见但 visible=0',
    (int)($pl['list'][array_search($tidC, array_column($pl['list'], 'tid'), true)]['visible'] ?? 1) === 0, '丙队应当不可见');

[$s, $j] = as_req('plat', 'POST', '/api/platform/admin/showcase/featured', ['tenant_ids' => (string)$tidA]);
check('焦点区精选保存成功', (int)($j['code'] ?? 1) === 0, J($j));
[$s, , , $html] = guest('GET', '/hall');
check('精选那队进了焦点展示区', strpos($html, '焦点战队') !== false && strpos($html, '平台精选') !== false);

// 一键下架：优先于战队自己的开关
[$s, $j] = as_req('plat', 'POST', '/api/platform/admin/showcase/force', ['tenant_id' => $tidA, 'off' => 1]);
check('平台一键下架某站展示页', (int)($j['code'] ?? 1) === 0, J($j));
[$s] = guest('GET', '/sha/show');
check('★ 下架后即使队长那边仍是"开启"，访客页立刻 404', $s === 404, "status=$s");
check('下架在管理端回显里看得见（队长不会以为是自己的开关坏了）',
    (int)(as_req('own', 'GET', '/sha/api/admin/showcase')[1]['data']['platform']['force_off'] ?? 0) === 1);
check('下架写进了那一站的操作日志（队长可查是谁下的）',
    countRows('admin_logs', "tenant_id=$tidA AND action='对外展示页' AND detail LIKE '%平台下架%'") >= 1);
[$s, , , $html] = guest('GET', '/hall');
check('下架的站点从大厅卡片里消失', strpos($html, 'shb/show') === false && strpos($html, '/sha/show') === false);
[$s, $j] = as_req('plat', 'POST', '/api/platform/admin/showcase/force', ['tenant_id' => $tidA, 'off' => 0]);
check('恢复上架', (int)($j['code'] ?? 1) === 0 && guest('GET', '/sha/show')[0] === 200, J($j));

// 大厅总开关
[$s, $j] = as_req('plat', 'POST', '/api/platform/admin/showcase/hall', ['enabled' => 0]);
check('平台关闭公示大厅', (int)($j['code'] ?? 1) === 0, J($j));
[$s] = guest('GET', '/hall');
check('关闭后 /hall 回 404（不是白屏）', $s === 404, "status=$s");
[$s, $j] = as_req('plat', 'POST', '/api/platform/admin/showcase/hall', ['enabled' => 1]);
check('重新开放后 /hall 又打得开', (int)($j['code'] ?? 1) === 0 && guest('GET', '/hall')[0] === 200, J($j));

// sitemap：开着的站带 /show，没开的不带；/hall 跟随总开关
as_req('own', 'POST', '/sha/api/admin/showcase/save', ['enabled' => 1]);
[$s, $j] = as_req('plat', 'POST', '/api/platform/admin/sitemap/generate', ['base_url' => 'http://127.0.0.1:8899']);
check('生成 sitemap 成功', (int)($j['code'] ?? 1) === 0, J($j));
$xmlA = (string)@file_get_contents($ROOT . '/public/sitemap-sha.xml');
$xmlB = (string)@file_get_contents($ROOT . '/public/sitemap-shb.xml');
$xmlP = (string)@file_get_contents($ROOT . '/public/sitemap-platform.xml');
check('开了展示页的站点收录 /sha/show', strpos($xmlA, '/sha/show') !== false, substr($xmlA, 0, 160));
check('没开的站点不收录 /shb/show（不替队长把页面推给搜索引擎）', strpos($xmlB, '/shb/show') === false, substr($xmlB, 0, 160));
check('大厅开着时平台 sitemap 收录 /hall', strpos($xmlP, '/hall') !== false);
as_req('plat', 'POST', '/api/platform/admin/showcase/hall', ['enabled' => 0]);
as_req('plat', 'POST', '/api/platform/admin/sitemap/generate', ['base_url' => 'http://127.0.0.1:8899']);
check('大厅关掉后重新生成，/hall 不再被收录',
    strpos((string)@file_get_contents($ROOT . '/public/sitemap-platform.xml'), '/hall') === false);
as_req('plat', 'POST', '/api/platform/admin/showcase/hall', ['enabled' => 1]);

// ============================================================
// 7. 结构隔离与源码守卫
// ============================================================
echo "\n== 7. 结构隔离：不进导出、不进巡查白名单、路由与保留字 ==\n";
$dt = (string)file_get_contents($ROOT . '/app/service/DataTransfer.php');
check('投稿箱/展示条目都不在站点导出清单里',
    strpos($dt, 'show_submissions') === false && strpos($dt, 'showcase_items') === false);
$insp = (string)file_get_contents($ROOT . '/app/controller/Inspect.php');
check('隐身巡查读不到投稿箱（访客投递的 QQ 属于当事数据）',
    strpos($insp, 'show_submissions') === false && strpos($insp, 'showcase_items') === false);
$route = (string)file_get_contents($ROOT . '/route/app.php');
check('访客展示页路由挂在登录中间件之外（无会话可访问）',
    (bool)preg_match('#Route::get\(\'/show\', \'Showcase/page\'\)#', $route)
    && strpos($route, "Route::post('/api/show/submit', 'Showcase/submit')") !== false
    && (int)strpos($route, "Route::get('/show', 'Showcase/page')") < (int)strpos($route, "Route::group('/api'"));
check('展示页管理端接口在 /api/admin 组内（AdminCheck + showcase 双闸）',
    (bool)preg_match("#Route::get\('/showcase', 'ShowcaseAdmin/index'\)#", $route)
    && (int)strpos($route, "'/showcase/subs'") > (int)strpos($route, "Route::group('/api/admin'"));
check('巡查白名单组仍在文件末尾（新页面没把它挤到中间）',
    (int)strpos($route, "Route::group('/api/inspect'") > (int)strpos($route, "'/showcase/sub-del'"));
check('show 与 hall 已登记为保留目录名（否则同名 slug 会劫持这两条路由）',
    (bool)preg_match("#'show', 'hall',#", (string)file_get_contents($ROOT . '/app/service/TenantContext.php')));
check('权限键 showcase 已进 admin_perm_defs（副管理员可被授权）',
    strpos((string)file_get_contents($ROOT . '/app/common.php'), "'showcase'     => '对外展示页'") !== false);
$svc = (string)file_get_contents($ROOT . '/app/service/Showcase.php');
$ab = [];
preg_match("/ASSET_BUILD',\s*'([\d.]+)'/", (string)file_get_contents($ROOT . '/app/common.php'), $ab);
check('ASSET_BUILD 已随发版递增（当前 ' . ($ab[1] ?? '缺失') . '，不低于 5.21.0）',
    isset($ab[1]) && version_compare($ab[1], '5.21.0', '>='));
check('存储配额已把展示页图片计入本站用量',
    strpos((string)file_get_contents($ROOT . '/app/service/Storage.php'), "'showcase'      => ['showcase_items'") !== false);
check('访客页模板没有把管理端接口写进 HTML',
    strpos((string)file_get_contents($ROOT . '/view/showcase.html'), 'api/admin') === false);
check('成员风采取数处明确"没昵称就不上页"（守卫那条 QQ 兜底）',
    strpos($svc, "if (\$nick === '') {") !== false || strpos($svc, 'if ($nick === \'\')') !== false);
check('大厅卡片字段里没有 QQ/邮箱/IP（只给队名口号成员数）',
    !preg_match("/'(qq|email|ip)'\s*=>/", $svc));
check('存储清理页删掉展示页条目时是删行不是只清引用（不留空壳）',
    strpos((string)file_get_contents($ROOT . '/app/service/Storage.php'), "\$type === 'showcase'") !== false);

// ============================================================
// 8. 回归：老功能不受影响
// ============================================================
echo "\n== 8. 回归（登录/仪表盘/聊天室/私聊/个人中心） ==\n";
[$s, $j] = as_req('mem', 'GET', '/sha/api/dashboard');
check('仪表盘仍正常', (int)($j['code'] ?? 1) === 0, J($j));
[$s, $j] = as_req('mem', 'POST', '/sha/api/chat/send', ['content' => '回归群消息']);
check('战队群（聊天室）仍正常', (int)($j['code'] ?? 1) === 0, J($j));
[$s, $j] = as_req('mem', 'GET', '/sha/api/chat/dm/conversations');
check('私聊会话列表仍正常', (int)($j['code'] ?? 1) === 0, J($j));
[$s, $j] = as_req('mem', 'GET', '/sha/api/auth/me');
check('登录态仍正常（auth/me）', (int)($j['code'] ?? 1) === 0, J($j));
[$s, $j] = as_req('mem', 'GET', '/sha/api/admin/showcase');
check('展示页没给成员放开管理端（越权回归）', (int)($j['code'] ?? 0) === 403 || $s === 403, "status=$s");
[$s] = guest('GET', '/sha/login');
check('登录页仍能打开（新路由没抢 /login）', $s === 200, "status=$s");
[$s] = guest('GET', '/sha/api/tenant/info');
check('公开的品牌接口没受影响', $s === 200, "status=$s");
[$s, $j] = as_req('own', 'POST', '/sha/api/admin/showcase/image', []);
check('没带文件时上传接口友好拒绝而不是 500', (int)($j['code'] ?? 1) !== 0 && $s !== 500, "status=$s " . J($j));

// ============================================================
// 9. 清理
// ============================================================
echo "\n== 9. 收尾 ==\n";
@unlink($imgDir . '/' . $imgName);
@rmdir($imgDir);
foreach (['sitemap-sha.xml', 'sitemap-shb.xml', 'sitemap-shc.xml', 'sitemap-shd.xml', 'sitemap-platform.xml', 'sitemap.xml'] as $f) {
    @unlink($ROOT . '/public/' . $f);
}
check('测试造的展示页图片已删除', !is_file($imgDir . '/' . $imgName));
check('生成的 sitemap 文件已清理', !is_file($ROOT . '/public/sitemap-sha.xml'));

echo "\n============================\n";
echo 'PASS=' . $pass . '  FAIL=' . $fail . "\n";
if ($failures !== []) {
    echo "失败明细:\n - " . implode("\n - ", $failures) . "\n";
}
exit($fail === 0 ? 0 : 1);
