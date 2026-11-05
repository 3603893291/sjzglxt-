<?php
/**
 * 战队销毁（v5.36.32）功能 E2E
 *
 * 前置：.env 指向 sqlite 夹具，测试服务器已启动
 *   php runtime/_env.php fixture
 *   php scripts/dev_init_sqlite.php runtime/test.sqlite
 *   php -S 127.0.0.1:8899 -t public public/router.php
 * 用法：php scripts/test_tenant_purge_e2e.php
 *
 * 覆盖：
 *   1. 15 天那条线：封满的/还差的/没有起点的/没被封的，四种说法各是哪一句
 *   2. 封禁与恢复真的把 tenants.banned_at 写上、清零（中途恢复重新数）
 *   3. 越权：匿名、平台战队账号(role=team)、战队侧会话、/{slug}/ 下的平台路径、
 *      平台作用域 API Key、没预览直接删、拿别的站的码、同一枚码用第二次、
 *      目录名打错、没到 15 天 —— 每一样都要落到「数据一条没少」
 *   4. 备份包：zip 打得开、manifest/data.json/files 三样齐、条目用正斜杠、字节数对得上
 *   5. 真删：本队各表清零、tenants 行没了、图片按引用删；
 *      别的站共用的、平台表情包库的、平台地图池的、约战小局截图 —— 一张都不能跟着走
 *   6. 跨队约战留着，对面看到的那格回显「已注销战队」；本队那一侧的报名跟着删
 *   7. 登记表与下载：purgedList 有这条、备份包按登记下发、没登记的文件名不下发
 *   8. 幂等：同一个站再点一次销毁说「已经不在了」
 *   9. 库没升级（缺 tenant_purges / 缺 banned_at 列）时报的是一句人话，不是 500
 *  10. 回归：站点列表、另一队自己的登录与图片页照常
 *
 * 这个套件会在 public/storage/uploads/_purgetest/ 下写真文件并在结束时删掉，
 * 只删自己建的那几个文件名，不动目录里的任何东西。
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

// ---------- HTTP ----------
$jars = [];
function jar(string $who): array { global $jars; return $jars[$who] ?? []; }
function put_jar(string $who, array $c): void { global $jars; $jars[$who] = $c; }
function take_cookies(array $headers, array &$cookies): void {
    foreach ($headers as $h) {
        if (preg_match('/^Set-Cookie:\s*([^=]+)=([^;]*)/i', $h, $m)) { $cookies[$m[1]] = trim($m[2]); }
    }
}
function cookie_hdr(array &$cookies): string {
    if (!$cookies) return '';
    $pairs = [];
    foreach ($cookies as $k => $v) $pairs[] = $k . '=' . $v;
    return 'Cookie: ' . implode('; ', $pairs) . "\r\n";
}
/** @return array{0:int,1:array,2:string,3:array} */
function req(string $method, string $url, ?array $body, array &$cookies, array $extraHdr = []): array {
    $hdr = "Content-Type: application/json\r\n" . cookie_hdr($cookies);
    foreach ($extraHdr as $h) $hdr .= $h . "\r\n";
    $opts = ['http' => ['method' => $method, 'header' => $hdr, 'ignore_errors' => true, 'timeout' => 120]];
    if ($body !== null) $opts['http']['content'] = json_encode($body, JSON_UNESCAPED_UNICODE);
    $raw = @file_get_contents($url, false, stream_context_create($opts));
    $raw = (string)($raw === false ? '' : $raw);
    $hs = $http_response_header ?? [];
    take_cookies($hs, $cookies);
    $s = 0;
    foreach ($hs as $h) if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) $s = (int)$m[1];
    $j = json_decode($raw, true);
    return [$s, is_array($j) ? $j : ['raw' => $raw], $raw, $hs];
}
function as_req(string $who, string $method, string $path, ?array $body = null, array $extraHdr = []): array {
    global $BASE;
    $c = jar($who);
    $r = req($method, $BASE . $path, $body, $c, $extraHdr);
    put_jar($who, $c);
    return $r;
}
function hdr(array $hs, string $name): string {
    foreach ($hs as $h) if (preg_match('/^' . preg_quote($name, '/') . ':\s*(.*)$/i', $h, $m)) return trim($m[1]);
    return '';
}
function J($a): string { return (string)json_encode($a, JSON_UNESCAPED_UNICODE); }

// ---------- 库 ----------
function db(): PDO {
    global $DB;
    $p = new PDO('sqlite:' . $DB);
    $p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $p->setAttribute(PDO::ATTR_TIMEOUT, 5);
    $p->exec('PRAGMA journal_mode=WAL');
    return $p;
}
function q(string $sql, array $bind = []): array {
    $st = db()->prepare($sql); $st->execute($bind); return $st->fetchAll(PDO::FETCH_ASSOC);
}
function one(string $sql, array $bind = []): ?array { $r = q($sql, $bind); return $r ? $r[0] : null; }
function cnt(string $sql, array $bind = []): int {
    $st = db()->prepare($sql); $st->execute($bind); return (int)$st->fetchColumn();
}

// ============================================================
// 0. 重建库 + 种子
// ============================================================
echo "== 0. 重建测试库与种子 ==\n";
@unlink($DB);
echo shell_exec('"' . PHP_BINARY . '" "' . $ROOT . '/scripts/dev_init_sqlite.php" "' . $DB . '" 2>&1');
foreach (array_merge(glob($ROOT . '/runtime/cache/*/*') ?: [], glob($ROOT . '/runtime/cache/*/*/*') ?: []) as $f) {
    if (is_file($f)) @unlink($f);
}
$run = q("SELECT name FROM sqlite_master WHERE type='table' AND name='tenant_purges'");
check('夹具里有 tenant_purges 这张表', $run !== []);
$col = q("PRAGMA table_info(tenants)");
$hasBan = false;
foreach ($col as $c) if ($c['name'] === 'banned_at') $hasBan = true;
check('夹具里 tenants.banned_at 这一列在', $hasBan);

$pdo = db();
$now = date('Y-m-d H:i:s');
$ago = static fn(int $d): string => date('Y-m-d H:i:s', time() - $d * 86400);
$hash = password_hash('pass123456', PASSWORD_DEFAULT);

// 上传目录：这次测试专用的一批真文件
$UP = $ROOT . '/public/storage/uploads/_purgetest';
@mkdir($UP . '/images', 0777, true);
@mkdir($UP . '/stickers', 0777, true);
@mkdir($UP . '/rotation', 0777, true);
@mkdir($UP . '/battles', 0777, true);
$made = [];
$mkfile = function (string $rel, int $kb = 4) use ($UP, &$made): string {
    $abs = $UP . '/' . $rel;
    file_put_contents($abs, str_repeat('PURGETEST-', $kb * 100));
    $made[] = $abs;
    return '/storage/uploads/_purgetest/' . $rel;
};
$F_teamOnly   = $mkfile('images/team_only.jpg');      // 只有 doomed 引用 → 该删
$F_shared     = $mkfile('images/shared_with_other.jpg'); // doomed + other 都引用 → 该留
$F_guide      = $mkfile('images/guide_cover.jpg');    // doomed 的攻略封面 → 该删
$F_ann        = $mkfile('images/ann_cover.jpg');      // doomed 的公告配图 → 该删
$F_profile    = $mkfile('images/member_shot.jpg');    // doomed 成员注册截图 → 该删
$F_logo       = $mkfile('images/team_logo.jpg');      // doomed 的 logo（tenants + settings 两处）→ 该删
$F_app        = $mkfile('images/apply_shot.jpg');     // 入驻材料截图 → 该删
$F_sticker    = $mkfile('stickers/pack_a.jpg');       // 平台表情包库 + doomed 的一张 image 同时引用 → 该留
$F_map        = $mkfile('rotation/pool_map.jpg');     // 平台地图池 + doomed 的一张 image 同时引用 → 该留
$F_battlerd   = $mkfile('battles/proof.jpg');         // 约战小局截图（那行留着）→ 该留

$mkTenant = function (string $slug, string $name, string $status, ?string $bannedAt) use ($pdo, $now): int {
    $ba = $bannedAt === null ? 'NULL' : "'$bannedAt'";
    $pdo->exec("INSERT INTO tenants (applicant_id, slug, name, logo, status, banned_at, admin_username, created_at, updated_at)
                VALUES (1, '$slug', '$name', '', '$status', $ba, '$slug" . "_cap', '$now', '$now')");
    return (int)$pdo->lastInsertId();
};

$DOOMED = $mkTenant('doomed', '该注销的队', 'banned', $ago(16));   // 封满 16 天 → 可以删
$YOUNG  = $mkTenant('youngban', '刚封的队', 'banned', $ago(3));    // 封了 3 天 → 还差 12 天
$NOSTART= $mkTenant('nostart', '老封禁队', 'banned', null);        // 升级前就被封 → 没有起点
$OTHER  = $mkTenant('other', '隔壁的队', 'approved', null);        // 正常在营
$LIVE   = $mkTenant('justban', '待会封的队', 'approved', null);    // 用来验 banned_at 真的写/清

echo "  租户：doomed=$DOOMED youngban=$YOUNG nostart=$NOSTART other=$OTHER justban=$LIVE\n";

$mkUser = function (int $tid, string $username, string $nick, string $img = '', string $role = 'member') use ($pdo, $now, $hash): int {
    $pdo->exec("INSERT INTO users (tenant_id, username, password, nickname, profile_image, role, status,
                                   admin_perms, show_public, stats_public, created_at, updated_at)
                VALUES ($tid, '$username', '$hash', '$nick', '$img', '$role', 'approved', '', 1, 1, '$now', '$now')");
    return (int)$pdo->lastInsertId();
};
$u1 = $mkUser($DOOMED, 'doomed_cap', '队长甲', $F_profile, 'admin');
$mkUser($DOOMED, 'doomed_m2', '成员乙');
$mkUser($DOOMED, 'doomed_m3', '成员丙');
$ou1 = $mkUser($OTHER, 'other_cap', '隔壁队长', '', 'admin');
$pdo->exec("UPDATE tenants SET admin_user_id = $u1 WHERE id = $DOOMED");
$pdo->exec("UPDATE tenants SET admin_user_id = $ou1 WHERE id = $OTHER");

// doomed 名下的图片引用
$ins = $pdo->prepare("INSERT INTO images (tenant_id, user_id, title, file, status, created_at, updated_at)
                      VALUES (?, ?, ?, ?, 'approved', ?, ?)");
$ins->execute([$DOOMED, $u1, '本队独有一张', $F_teamOnly, $now, $now]);
$ins->execute([$DOOMED, $u1, '和隔壁共用一张', $F_shared, $now, $now]);
$ins->execute([$DOOMED, $u1, '顺手贴的表情包', $F_sticker, $now, $now]);
$ins->execute([$DOOMED, $u1, '顺手贴的地图', $F_map, $now, $now]);
$ins->execute([$DOOMED, $u1, '约战截图也存了一份', $F_battlerd, $now, $now]);
$ins->execute([$OTHER, $ou1, '隔壁的那张', $F_shared, $now, $now]);
$pdo->exec("INSERT INTO guides (tenant_id, user_id, title, content, image, created_at, updated_at)
            VALUES ($DOOMED, $u1, '攻略一篇', '正文', '$F_guide', '$now', '$now')");
$pdo->exec("INSERT INTO announcements (tenant_id, title, content, image, need_receipt, created_at, updated_at)
            VALUES ($DOOMED, '公告一篇', '正文', '$F_ann', 0, '$now', '$now')");
$pdo->exec("UPDATE tenants SET logo = '$F_logo' WHERE id = $DOOMED");
$pdo->exec("INSERT INTO settings (tenant_id, key, value) VALUES ($DOOMED, 'show_logo', '$F_logo')");

// 入驻材料（按 applicant_id 关联，这张表没有 tenant_id）
$pdo->exec("INSERT INTO tenant_applications (applicant_id, slug, name, logo, description, admin_username,
                                             admin_password, screenshot, status, created_at, updated_at)
            VALUES (1, 'doomed', '该注销的队', '$F_logo', '', 'doomed_cap', 'x', '$F_app', 'approved', '$now', '$now')");

// 平台侧资源：表情包库 + 地图池（租户 0）
$pdo->exec("INSERT INTO ai_stickers (name, note, path, bytes, enabled, sort, sent_count, created_by, created_at)
            VALUES ('表情包甲', '', '$F_sticker', 100, 1, 0, 0, 1, '$now')");
$pdo->exec("INSERT INTO rotation_maps (tenant_id, name, image, sort, created_at, updated_at)
            VALUES (0, '地图甲', '$F_map', 0, '$now', '$now')");

// 站内通知历史（forum_notices 按人走，没有 tenant_id）
$pdo->exec("INSERT INTO forum_notices (to_user_id, from_user_id, kind, post_id, comment_id, note, created_at)
            VALUES ($u1, $u1, 'mention', 0, 0, '测试通知', '$now')");

// 跨队约战：doomed 打 other；本队和对面各有一条报名；一场小局带截图
$pdo->exec("INSERT INTO battles (title, game_mode, battle_time, challenger_type, challenger_id,
                                 defender_type, defender_id, status, battle_type, series_format, created_at, updated_at)
            VALUES ('销毁回归赛', '占点', '$now', 'tenant', $DOOMED, 'tenant', $OTHER, 'finished', 'scrm', 'bo1', '$now', '$now')");
$battleId = (int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO battle_rounds (battle_id, round_no, map_id, map_name, attacker_side, result, result_by, evidence, created_at)
            VALUES ($battleId, 1, 0, '测试图', 'a', 'a', 1, '$F_battlerd', '$now')");
$pdo->exec("INSERT INTO battle_signups (battle_id, side, signup_type, user_id, display_name, tenant_id, manual, created_at)
            VALUES ($battleId, 'challenger', 'user', $u1, '队长甲', $DOOMED, 0, '$now')");
$pdo->exec("INSERT INTO battle_signups (battle_id, side, signup_type, user_id, display_name, tenant_id, manual, created_at)
            VALUES ($battleId, 'defender', 'user', $ou1, '隔壁队长', $OTHER, 0, '$now')");

// doomed 自己提交过的导入导出包（在 runtime/data_tasks 里，销毁时连行一起清）
$taskDir = $ROOT . '/runtime/data_tasks';
@mkdir($taskDir, 0777, true);
$taskFile = $taskDir . '/purgetest_task.zip';
file_put_contents($taskFile, 'purgetest');
$pdo->exec("INSERT INTO tenant_data_tasks (tenant_id, type, status, file, note, created_at)
            VALUES ($DOOMED, 'export', 'done', '$taskFile', '测试残留', '$now')");

// 一个平台侧的战队账号（role=team）：它也登录着平台，但不是超管
$pdo->exec("INSERT INTO platform_users (username, password, nickname, role, status, created_at, updated_at)
            VALUES ('teamowner', '$hash', '战队队长账号', 'team', 'normal', '$now', '$now')");

// 一把平台作用域的 API Key（它按 is_platform_super() 算超管，但不该够到销毁）
$plainKey = bin2hex(random_bytes(24));
$pdo->exec("INSERT INTO api_keys (key_hash, key_prefix, name, scope, status, created_by, created_at)
            VALUES ('" . hash('sha256', $plainKey) . "', '" . substr($plainKey, 0, 8) . "',
                    '销毁回归用平台密钥', 'platform', 1, 1, '$now')");

echo "  种子：约战 #$battleId，成员 " . cnt("SELECT COUNT(*) FROM users") . " 人，图片文件 " . count($made) . " 张\n";

// ============================================================
// 1. 平台超管登录 + 15 天那条线
// ============================================================
echo "\n== 1. 列表里那四种说法 ==\n";
[$s, $j] = as_req('plat', 'POST', '/api/platform/auth/login', ['username' => 'admin', 'password' => 'admin123']);
check('平台超管登录', $s === 200 && ($j['code'] ?? 1) === 0, J($j));

$byId = [];
foreach (($j['data']['list'] ?? []) as $t) $byId[(int)$t['id']] = $t;
$load = static function (): array {
    [, $jj] = as_req('plat', 'GET', '/api/platform/admin/tenants');
    $map = [];
    foreach (($jj['data']['list'] ?? []) as $t) $map[(int)$t['id']] = $t;
    return $map;
};
$byId = $load();
$v = static fn(int $id): array => $byId[$id]['purge'] ?? [];

check('封满 16 天的：可以销毁', ($v($DOOMED)['ready'] ?? false) === true, J($v($DOOMED)));
check('封满 16 天的：天数在 15 以上', (int)($v($DOOMED)['days'] ?? 0) >= 15, 'days=' . ($v($DOOMED)['days'] ?? '-'));
check('封满 16 天的：那句是「封满 15 天，可以销毁」',
    ($v($DOOMED)['reason'] ?? '') === '封满 15 天，可以销毁', ($v($DOOMED)['reason'] ?? ''));
check('封了 3 天的：还不能删', ($v($YOUNG)['ready'] ?? true) === false);
check('封了 3 天的：还差 12 天', (int)($v($YOUNG)['need_days'] ?? 0) === 12, 'need=' . ($v($YOUNG)['need_days'] ?? '-'));
check('封了 3 天的：说的是「还差 12 天」', ($v($YOUNG)['reason'] ?? '') === '还差 12 天', ($v($YOUNG)['reason'] ?? ''));
check('升级前就被封的：没有起点，不能删', ($v($NOSTART)['ready'] ?? true) === false);
check('升级前就被封的：说的是「没有封禁起点」',
    str_contains((string)($v($NOSTART)['reason'] ?? ''), '没有封禁起点'), ($v($NOSTART)['reason'] ?? ''));
check('在营的：现在没被封，不能删', str_contains((string)($v($OTHER)['reason'] ?? ''), '没被封'), ($v($OTHER)['reason'] ?? ''));

// ============================================================
// 2. 封禁/恢复真的写 banned_at（中途恢复清零）
// ============================================================
echo "\n== 2. 封禁起点：写得上、也清零 ==\n";
[, $j] = as_req('plat', 'POST', "/api/platform/admin/tenants/$LIVE/status", ['status' => 'banned']);
check('封禁这个站点', ($j['code'] ?? 1) === 0, J($j));
$ba = (string)(one("SELECT banned_at FROM tenants WHERE id=$LIVE")['banned_at'] ?? '');
check('封禁后 banned_at 有了起点', $ba !== '' && abs(strtotime($ba) - time()) < 120, "banned_at=$ba");

[, $j] = as_req('plat', 'POST', "/api/platform/admin/tenants/$LIVE/status", ['status' => 'approved']);
check('恢复这个站点', ($j['code'] ?? 1) === 0, J($j));
$ba2 = one("SELECT banned_at FROM tenants WHERE id=$LIVE");
check('中途恢复就把起点清零（再封重新数）', $ba2 !== null && ($ba2['banned_at'] === null || $ba2['banned_at'] === ''),
    'banned_at=' . var_export($ba2['banned_at'] ?? '(没这列)', true));

[, $j] = as_req('plat', 'POST', "/api/platform/admin/tenants/$LIVE/status", ['status' => 'banned']);
$ba3 = (string)(one("SELECT banned_at FROM tenants WHERE id=$LIVE")['banned_at'] ?? '');
check('再封一次，起点是这一次的时间', $ba3 !== '' && abs(strtotime($ba3) - time()) < 120, "banned_at=$ba3");
as_req('plat', 'POST', "/api/platform/admin/tenants/$LIVE/status", ['status' => 'approved']);

// ============================================================
// 3. 越权：十种进不去的走法，每一种都要「数据一条没少」
// ============================================================
echo "\n== 3. 越权闸 ==\n";
function snapshot(): string {
    global $DOOMED;
    $n = [];
    $n[] = 'tenant=' . cnt("SELECT COUNT(*) FROM tenants WHERE id=$DOOMED");
    $n[] = 'users=' . cnt("SELECT COUNT(*) FROM users WHERE tenant_id=$DOOMED");
    $n[] = 'images=' . cnt("SELECT COUNT(*) FROM images WHERE tenant_id=$DOOMED");
    $n[] = 'battles=' . cnt("SELECT COUNT(*) FROM battles");
    $n[] = 'purges=' . cnt("SELECT COUNT(*) FROM tenant_purges");
    return implode(' ', $n);
}
$base = snapshot();
$prev = "/api/platform/admin/tenants/$DOOMED/purge-preview";
$runR = "/api/platform/admin/tenants/$DOOMED/purge";

[$s, $j] = as_req('anon', 'POST', $prev, []);
check('没登录：预览进不去', $s === 403, "http=$s " . J($j));
check('没登录：数据没动', snapshot() === $base, snapshot());

[$s, $j] = as_req('team', 'POST', '/api/platform/auth/login', ['username' => 'teamowner', 'password' => 'pass123456']);
check('平台上的战队账号能登录（它不是超管）', ($j['code'] ?? 1) === 0, J($j));
[$s, $j] = as_req('team', 'POST', $prev, []);
check('平台上的战队账号：销毁进不去', $s === 403, "http=$s " . J($j));

[$s, $j] = as_req('othercap', 'POST', '/other/api/auth/login', ['username' => 'other_cap', 'password' => 'pass123456']);
check('战队侧队长登录（拿一支在营的队，被封的队自己也进不去）', ($j['code'] ?? 1) === 0, J($j));
[$s, $j] = as_req('othercap', 'POST', $prev, []);
check('战队侧会话打平台路径：销毁进不去', $s === 403, "http=$s " . J($j));
[$s, $j] = as_req('othercap', 'POST', '/other/api/platform/admin/tenants/1/purge', []);
check('在 /{slug}/ 下面根本没有平台接口（404）', $s === 404, "http=$s");
[$s, $j] = as_req('othercap', 'GET', '/other/api/platform/admin/purged');
check('在 /{slug}/ 下面也列不出已销毁的站（404）', $s === 404, "http=$s");

[$s, $j] = as_req('apikey', 'POST', $prev, [], ['X-Api-Key: ' . $plainKey]);
check('平台作用域的 API Key 打到销毁接口：挡在门外', $s === 403, "http=$s " . J($j));
check('平台作用域的 API Key：报的是人话不是 500', $s !== 500 && ($j['code'] ?? 1) === 403, J($j));
[$s, $j] = as_req('apikey', 'GET', '/api/platform/admin/purged', null, ['X-Api-Key: ' . $plainKey]);
check('平台作用域的 API Key：连已销毁列表都不给（下载也要本人登录）', $s === 403, "http=$s");

check('上面这些越权：数据一条没少', snapshot() === $base, snapshot());

[$s, $j] = as_req('plat', 'POST', $runR, ['token' => 'deadbeef', 'confirm_slug' => 'doomed']);
check('没预览就想删：要确认码', ($j['code'] ?? 1) === 403 && $s === 403, "http=$s " . J($j));
check('没预览就想删：数据没动', snapshot() === $base, snapshot());

[$s, $j] = as_req('plat', 'POST', $prev, []);
check('预览封满 16 天的：拿到确认码', ($j['code'] ?? 1) === 0 && !empty($j['data']['token']), J($j));
$tokDoomed = (string)($j['data']['token'] ?? '');
$slugAsked = (string)($j['data']['confirm_slug'] ?? '');
check('预览回显要逐字打回的目录名', $slugAsked === 'doomed', $slugAsked);
check('预览说清删掉多少行', (int)($j['data']['preview']['row_count'] ?? 0) > 0, J($j['data']['preview'] ?? []));
check('预览说清留着多少场约战（那些不删）', (int)($j['data']['preview']['battles_kept'] ?? 0) >= 1, J($j['data']['preview'] ?? []));

[$s, $j] = as_req('plat', 'POST', $runR, ['token' => $tokDoomed, 'confirm_slug' => 'doomed2']);
check('目录名打错一个字符：中止', ($j['code'] ?? 1) === 400, J($j));
check('目录名打错：数据没动', snapshot() === $base, snapshot());

// 上一步因为 slug 不对没走到校验令牌那步，所以 $tokDoomed 还在
[, $j] = as_req('plat', 'POST', "/api/platform/admin/tenants/$OTHER/purge-preview", []);
$tokOther = (string)($j['data']['token'] ?? '');
check('给在营的站点预览：直接被拒（没被封）', ($j['code'] ?? 1) !== 0 && $tokOther === '', J($j));

[, $j] = as_req('plat', 'POST', "/api/platform/admin/tenants/$YOUNG/purge-preview", []);
check('给封了 3 天的预览：还差 12 天', str_contains((string)($j['msg'] ?? ''), '还差 12 天'), J($j));

[, $j] = as_req('plat', 'POST', "/api/platform/admin/tenants/$NOSTART/purge-preview", []);
check('给没有起点的预览：说清是没起点', str_contains((string)($j['msg'] ?? ''), '没有封禁起点'), J($j));

// 拿 doomed 的码去删别的站：先给别的站造一个可删的
$DOOMED2 = $mkTenant('doomed2', '第二支该注销的队', 'banned', $ago(20));
[$s, $j] = as_req('plat', 'POST', "/api/platform/admin/tenants/$DOOMED2/purge-preview", []);
check('第二支封满的队：预览得到码', ($j['code'] ?? 1) === 0 && !empty($j['data']['token']), J($j));
$tok2 = (string)($j['data']['token'] ?? '');
[, $j] = as_req('plat', 'POST', $runR, ['token' => $tok2, 'confirm_slug' => 'doomed']);
check('拿别的站的确认码来删：不认', ($j['code'] ?? 1) === 403 && str_contains((string)($j['msg'] ?? ''), '别的站点'), J($j));
check('拿别的站的确认码来删：两支队都还在',
    cnt("SELECT COUNT(*) FROM tenants WHERE slug IN ('doomed','doomed2')") === 2);

// 一枚码只能用一次
[, $j] = as_req('plat', 'POST', "/api/platform/admin/tenants/$DOOMED2/purge-preview", []);
$tok3 = (string)($j['data']['token'] ?? '');
[$s, $j] = as_req('plat', 'POST', $runR2 = "/api/platform/admin/tenants/$DOOMED2/purge", ['token' => $tok3, 'confirm_slug' => 'doomed2']);
check('封满 20 天的第二支队：删得掉', ($j['code'] ?? 1) === 0, J($j));
[, $j] = as_req('plat', 'POST', $runR2, ['token' => $tok3, 'confirm_slug' => 'doomed2']);
check('同一枚确认码用第二次：作废不认', ($j['code'] ?? 1) === 403 || str_contains((string)($j['msg'] ?? ''), '已经不在了'), J($j));

// 换一个人拿码、另一个人来点
[$s, $j] = as_req('team2', 'POST', '/api/platform/auth/login', ['username' => 'admin', 'password' => 'admin123']);
check('第二个浏览器登录同一个超管（令牌绑会话）', ($j['code'] ?? 1) === 0, J($j));
$DOOMED3 = $mkTenant('doomed3', '第三支该注销的队', 'banned', $ago(31));
[, $j] = as_req('team2', 'POST', "/api/platform/admin/tenants/$DOOMED3/purge-preview", []);
$tok4 = (string)($j['data']['token'] ?? '');
check('新会话重新预览拿到新码', $tok4 !== '' && $tok4 !== $tokDoomed, $tok4);

echo "\n== 3b. 库没升级时不炸 ==\n";
$pdo->exec("ALTER TABLE tenant_purges RENAME TO tenant_purges_hidden");
[$s, $j] = as_req('plat', 'POST', $prev, []);
check('缺 tenant_purges 表：报一句人话', $s === 200 && str_contains((string)($j['msg'] ?? ''), '数据库还没补'), "http=$s " . J($j));
check('缺 tenant_purges 表：不是 500', $s !== 500, "http=$s");
[$s, $j] = as_req('plat', 'GET', '/api/platform/admin/tenants');
check('缺 tenant_purges 表：站点列表照常出', ($j['code'] ?? 1) === 0 && !empty($j['data']['list']), "http=$s");
$pv = [];
foreach (($j['data']['list'] ?? []) as $t) if ((int)$t['id'] === $DOOMED) $pv = $t['purge'] ?? [];
check('缺 tenant_purges 表：那一行说「库还没补」', str_contains((string)($pv['reason'] ?? ''), '还没补'), J($pv));
[$s, $j] = as_req('plat', 'POST', $runR, ['token' => $tokDoomed, 'confirm_slug' => 'doomed']);
check('缺 tenant_purges 表：想硬删也被挡在门外', str_contains((string)($j['msg'] ?? ''), '数据库还没补'), J($j));
$pdo->exec("ALTER TABLE tenant_purges_hidden RENAME TO tenant_purges");

echo "\n== 3c. 缺 banned_at 这一列时，封禁按钮不能被一起弄坏 ==\n";
// 这一节要把那一列真删掉再补回来；SQLite 补回来的列是全表 NULL 的新列，
// 所以先给每一站的封禁起点拍照，跑完原样贴回去 —— 否则后面「封满 15 天」那场戏就没起点了
$banSnap = q("SELECT id, banned_at FROM tenants WHERE banned_at IS NOT NULL AND banned_at <> ''");
try {
    $pdo->exec("ALTER TABLE tenants DROP COLUMN banned_at");
    $dropped = true;
} catch (\Throwable $e) {
    $dropped = false;
    echo "  (这台 sqlite 不支持 DROP COLUMN，跳过这一节)\n";
}
if ($dropped) {
    [$s, $j] = as_req('plat', 'POST', "/api/platform/admin/tenants/$LIVE/status", ['status' => 'banned']);
    check('没有 banned_at 列：封禁还是成功', ($j['code'] ?? 1) === 0, "http=$s " . J($j));
    check('没有 banned_at 列：状态真改成了 banned',
        (string)(one("SELECT status FROM tenants WHERE id=$LIVE")['status'] ?? '') === 'banned');
    [, $j] = as_req('plat', 'GET', '/api/platform/admin/tenants');
    check('没有 banned_at 列：站点列表照常出', ($j['code'] ?? 1) === 0 && !empty($j['data']['list']), J($j));
    $pdo->exec("ALTER TABLE tenants ADD COLUMN banned_at DATETIME DEFAULT NULL");
    foreach ($banSnap as $bs) {
        if ((int)$bs['id'] === $LIVE) continue;   // 这一站是这一节自己封的，不算原有起点
        $pdo->exec("UPDATE tenants SET banned_at = '" . (string)$bs['banned_at'] . "' WHERE id = " . (int)$bs['id']);
    }
    as_req('plat', 'POST', "/api/platform/admin/tenants/$LIVE/status", ['status' => 'approved']);
    $back = cnt("SELECT COUNT(*) FROM tenants WHERE banned_at IS NOT NULL AND banned_at <> ''");
    check('起点照片贴回去了（后面那几场戏还要用）', $back === count($banSnap),
        "现在 $back 个 / 照片 " . count($banSnap) . " 个");
}

// ============================================================
// 4. 真的销毁 doomed：备份 → 删行 → 按引用删图
// ============================================================
echo "\n== 4. 销毁 doomed ==\n";
[, $j] = as_req('plat', 'POST', $prev, []);
check('销毁前最后一次预览', ($j['code'] ?? 1) === 0 && !empty($j['data']['token']), J($j));
$tok = (string)$j['data']['token'];
$pre = $j['data']['preview'] ?? [];
check('预览：成员数 3', (int)($pre['member_count'] ?? 0) === 3, J($pre));
check('预览：图片引用 10 条（去重后）', (int)($pre['file_count'] ?? 0) === 10, 'file_count=' . ($pre['file_count'] ?? '-'));
check('预览：这些文件都在盘上', (int)($pre['file_present'] ?? 0) === (int)($pre['file_count'] ?? 0), J($pre));
check('预览：占用字节不为 0', (int)($pre['bytes'] ?? 0) > 0, 'bytes=' . ($pre['bytes'] ?? '-'));
check('预览：约战留 1 场', (int)($pre['battles_kept'] ?? 0) === 1, 'battles_kept=' . ($pre['battles_kept'] ?? '-'));

[$s, $j] = as_req('plat', 'POST', $runR, ['token' => $tok, 'confirm_slug' => 'doomed']);
check('销毁成功', ($j['code'] ?? 1) === 0, "http=$s " . J($j));
$stat = $j['data'] ?? [];
$backupName = (string)($stat['backup'] ?? '');
check('回了一个备份包文件名', $backupName !== '' && str_starts_with($backupName, 'purge_'), $backupName);
check('登记的删除行数与预览一致', (int)($stat['rows'] ?? 0) > 0, J($stat));

check('tenants 里这一行没了', cnt("SELECT COUNT(*) FROM tenants WHERE id=$DOOMED") === 0);
foreach (['users', 'images', 'guides', 'announcements', 'settings', 'tenant_data_tasks', 'battle_signups', 'admin_logs'] as $t) {
    check("这张表里这一队的行清零：$t", cnt("SELECT COUNT(*) FROM $t WHERE tenant_id=$DOOMED") === 0, (string)cnt("SELECT COUNT(*) FROM $t WHERE tenant_id=$DOOMED"));
}
check('站内通知历史（按人挂）跟着清掉', cnt("SELECT COUNT(*) FROM forum_notices WHERE to_user_id=$u1") === 0);

check('跨队约战那一场留着', cnt("SELECT COUNT(*) FROM battles WHERE id=$battleId") === 1);
check('约战小局留着', cnt("SELECT COUNT(*) FROM battle_rounds WHERE battle_id=$battleId") === 1);
check('对面那一侧的报名留着', cnt("SELECT COUNT(*) FROM battle_signups WHERE battle_id=$battleId AND tenant_id=$OTHER") === 1);
check('本队那一侧的报名删了', cnt("SELECT COUNT(*) FROM battle_signups WHERE battle_id=$battleId AND tenant_id=$DOOMED") === 0);

// 图片：按引用走
$alive = static function (string $rel): bool { return is_file($GLOBALS['UP'] . '/' . $rel); };
check('本队独有的一张图删掉了', !$alive('images/team_only.jpg'));
check('本队攻略封面删掉了', !$alive('images/guide_cover.jpg'));
check('本队公告配图删掉了', !$alive('images/ann_cover.jpg'));
check('本队成员注册截图删掉了', !$alive('images/member_shot.jpg'));
check('本队 logo（两处引用）删掉了', !$alive('images/team_logo.jpg'));
check('入驻材料截图删掉了', !$alive('images/apply_shot.jpg'));
check('隔壁也在用的那张：留着', $alive('images/shared_with_other.jpg'));
check('平台表情包库那张：留着', $alive('stickers/pack_a.jpg'));
check('平台地图池那张：留着', $alive('rotation/pool_map.jpg'));
check('约战小局截图（那行留着）：图片也留着', $alive('battles/proof.jpg'));
check('表情包库那一行还在', cnt("SELECT COUNT(*) FROM ai_stickers WHERE path='" . $F_sticker . "'") === 1);
check('地图池那一行还在', cnt("SELECT COUNT(*) FROM rotation_maps WHERE image='" . $F_map . "'") === 1);
check('隔壁队的图片行没被牵连', cnt("SELECT COUNT(*) FROM images WHERE tenant_id=$OTHER") === 1);
check('隔壁队的成员没被牵连', cnt("SELECT COUNT(*) FROM users WHERE tenant_id=$OTHER") === 1);
check('这一队自己的导入导出包文件也清掉了', !is_file($taskFile));

// 入驻材料那张申请行：历史留着，路径抹掉
$ap = one("SELECT logo, screenshot FROM tenant_applications WHERE slug='doomed'");
check('入驻申请那行历史留着', $ap !== null);
check('入驻材料里的图片路径抹掉了（不然那张图永远删不掉）',
    $ap !== null && (string)$ap['logo'] === '' && (string)$ap['screenshot'] === '', J($ap));

// ============================================================
// 5. 登记表、备份包与下载
// ============================================================
echo "\n== 5. 备份包与登记表 ==\n";
$reg = one("SELECT * FROM tenant_purges WHERE tenant_id=$DOOMED");
check('登记表里有这一条', $reg !== null);
check('登记的是被删的那一站', ($reg['slug'] ?? '') === 'doomed' && ($reg['name'] ?? '') === '该注销的队', J($reg));
check('登记了成员数 3', (int)($reg['member_count'] ?? 0) === 3, (string)($reg['member_count'] ?? '-'));
check('登记了封禁起点与已封天数', ($reg['banned_at'] ?? '') !== '' && (int)($reg['banned_days'] ?? 0) >= 15,
    ($reg['banned_at'] ?? '') . ' / ' . ($reg['banned_days'] ?? '-'));
check('登记了操作人是这个超管', (string)($reg['actor_name'] ?? '') !== '' && (int)($reg['actor_id'] ?? 0) > 0, J($reg));
check('登记了备份包名与字节数', ($reg['backup_file'] ?? '') === $backupName && (int)($reg['backup_bytes'] ?? 0) > 0, J($reg));
check('登记的行数与真删的一致', (int)($reg['row_count'] ?? 0) === (int)($stat['rows'] ?? -1),
    ($reg['row_count'] ?? '-') . ' vs ' . ($stat['rows'] ?? '-'));
$log = one("SELECT * FROM admin_logs WHERE tenant_id=0 AND action='战队销毁' ORDER BY id DESC LIMIT 1");
check('平台侧留痕写了一条（挂 0 号租户，不会被牵连删掉）', $log !== null, '');
check('留痕里说清了删了多少行、几张图、备份包叫什么',
    $log !== null && str_contains((string)$log['detail'], 'doomed') && str_contains((string)$log['detail'], $backupName),
    (string)($log['detail'] ?? ''));

$backupAbs = $ROOT . '/runtime/data_tasks/' . $backupName;
check('备份包真在 runtime/data_tasks 里（不在 web 目录）', is_file($backupAbs));
check('备份包不在 public/ 下', strpos(realpath($backupAbs) ?: '', realpath($ROOT . '/public') ?: "\0") !== 0);
$z = new ZipArchive();
check('备份包是打得开的 zip', $z->open($backupAbs) === true);
if ($z->open($backupAbs) === true) {
    $names = [];
    for ($i = 0; $i < $z->numFiles; $i++) $names[] = $z->getNameIndex($i);
    $man = json_decode((string)$z->getFromName('manifest.json'), true) ?: [];
    $dat = json_decode((string)$z->getFromName('data.json'), true) ?: [];
    check('包里有 manifest.json / data.json', in_array('manifest.json', $names, true) && in_array('data.json', $names, true), J($names));
    check('清单里写明是哪一站、什么时候删的',
        ($man['slug'] ?? '') === 'doomed' && ($man['name'] ?? '') === '该注销的队' && ($man['purged_at'] ?? '') !== '', J($man));
    check('清单里的图片数与真收进包的一致',
        (int)($man['file_count'] ?? 0) === count(array_filter($names, static fn($n) => str_starts_with($n, 'files/'))),
        ($man['file_count'] ?? '-') . ' vs 包内 ' . count(array_filter($names, static fn($n) => str_starts_with($n, 'files/'))));
    check('包内条目一律用正斜杠（没有反斜杠的怪名字）',
        count(array_filter($names, static fn($n) => str_contains($n, '\\'))) === 0, J($names));
    check('清单里写了解法（图片放回哪、数据怎么导）', isset($man['how_to_restore']) && is_array($man['how_to_restore']));
    check('清单提醒了这个包里有密码哈希（要当机密收好）',
        str_contains((string)($man['app_debug'] ?? ''), '哈希'), J($man));
    check('data.json 里有这一队的 tenants 那一行',
        isset($dat['tenants'][0]['slug']) && $dat['tenants'][0]['slug'] === 'doomed', J(array_keys($dat)));
    check('data.json 里有成员行', isset($dat['users']) && count($dat['users']) === 3,
        (string)count($dat['users'] ?? []));
    check('data.json 里存的是成员的原样（能照着导回去）',
        isset($dat['users'][0]['username']), J(array_keys($dat['users'][0] ?? [])));
    check('备份包收进的是「留着的那几张」以外的图',
        count(array_filter($names, static fn($n) => str_contains($n, 'team_only.jpg'))) === 1
        && count(array_filter($names, static fn($n) => str_contains($n, 'shared_with_other.jpg'))) === 1, J($names));
    check('SQLite 夹具里不生成 data.sql（那是 MySQL 那台才给的路）',
        !in_array('data.sql', $names, true), J($names));
    $z->close();
}

[$s, $j] = as_req('plat', 'GET', '/api/platform/admin/purged');
check('已销毁列表出得来', ($j['code'] ?? 1) === 0, J($j));
$pList = $j['data']['list'] ?? [];
check('列表里有 doomed，且备份包标记为还在',
    count(array_filter($pList, static fn($r) => $r['slug'] === 'doomed' && $r['backup_alive'] === true)) === 1, J($pList));
check('列表也记着 doomed2 那条',
    count(array_filter($pList, static fn($r) => $r['slug'] === 'doomed2')) === 1, J(array_column($pList, 'slug')));

[$s, $body, $raw, $hs] = as_req('plat', 'GET', '/api/platform/admin/purged/download?file=' . urlencode($backupName));
check('下载备份包：拿到的字节数和登记的一致', strlen($raw) === (int)$reg['backup_bytes'], strlen($raw) . ' vs ' . $reg['backup_bytes']);
check('下载备份包：文件名就是这个包', str_contains(hdr($hs, 'Content-Disposition'), $backupName), hdr($hs, 'Content-Disposition'));

[$s, $j] = as_req('plat', 'GET', '/api/platform/admin/purged/download?file=' . urlencode('../data_tasks/' . $backupName));
check('带路径的文件名：不下发', ($j['code'] ?? 1) === 404 || $s === 404, "http=$s " . J($j));
[$s, $j] = as_req('plat', 'GET', '/api/platform/admin/purged/download?file=' . urlencode('purge_not_registered.zip'));
check('没在登记表里的文件名：不下发', ($j['code'] ?? 1) === 404 || $s === 404, "http=$s " . J($j));
[$s, $j] = as_req('othercap', 'GET', '/api/platform/admin/purged');
check('战队侧会话：列不出已销毁的站', $s === 403, "http=$s");
[$s, $j] = as_req('othercap', 'GET', '/api/platform/admin/purged/download?file=' . urlencode($backupName));
check('战队侧会话：下载不了备份包', $s === 403, "http=$s");

// ============================================================
// 6. 对面看到的那格：已注销战队
// ============================================================
echo "\n== 6. 约战那头的显示 ==\n";
[$s, $j] = as_req('othercap', 'POST', '/other/api/auth/login', ['username' => 'other_cap', 'password' => 'pass123456']);
check('隔壁队长登录', ($j['code'] ?? 1) === 0, J($j));
[$s, $bj] = as_req('othercap', 'GET', '/other/api/admin/battles');
$btxt = J($bj);
check('隔壁的管理端约战列表里那场还在', str_contains($btxt, '销毁回归赛'), mb_substr($btxt, 0, 300));
check('对面那一格显示成「已注销战队」', str_contains($btxt, '已注销战队'), mb_substr($btxt, 0, 300));
check('不再出现被删掉的队名', !str_contains($btxt, '该注销的队'), mb_substr($btxt, 0, 300));
[$s, $mj] = as_req('othercap', 'GET', '/other/api/battles');
check('隔壁的成员端约战列表也照常（少了一队不报错）', ($mj['code'] ?? 1) === 0, mb_substr(J($mj), 0, 200));
[$s, $pj] = as_req('plat', 'GET', '/api/platform/admin/battles');
check('平台侧约战总览也照常（不因为少了一队就报错）', ($pj['code'] ?? 1) === 0, mb_substr(J($pj), 0, 200));
check('平台侧那场也回显「已注销战队」', str_contains(J($pj), '已注销战队'), '');

// ============================================================
// 7. 幂等与回归
// ============================================================
echo "\n== 7. 幂等与回归 ==\n";
[, $j] = as_req('plat', 'POST', $prev, []);
check('同一个站再点预览：说它已经不在了', ($j['code'] ?? 1) !== 0 && str_contains((string)($j['msg'] ?? ''), '不存在'), J($j));
[$s, $j] = as_req('plat', 'POST', "/api/platform/admin/tenants/$DOOMED/purge", ['token' => $tok4, 'confirm_slug' => 'doomed']);
check('同一个站硬发销毁：不执行第二遍', ($j['code'] ?? 1) !== 0, J($j));
check('第二遍没有多登记一条', cnt("SELECT COUNT(*) FROM tenant_purges WHERE tenant_id=$DOOMED") === 1);

$byId = $load();
check('站点列表里少了这一队', !isset($byId[$DOOMED]) && isset($byId[$OTHER]), implode(',', array_keys($byId)));
check('还封着的两支（youngban/nostart）不能删', ($byId[$YOUNG]['purge']['ready'] ?? true) === false
    && ($byId[$NOSTART]['purge']['ready'] ?? true) === false);
[$s, $j] = as_req('othercap', 'GET', '/other/api/images');
check('隔壁站点的图片页照常', ($j['code'] ?? 1) === 0, mb_substr(J($j), 0, 200));
[$s, $j] = as_req('othercap', 'GET', '/other/api/auth/me');
check('隔壁队长的会话照常', ($j['code'] ?? 1) === 0, mb_substr(J($j), 0, 200));
[$s, $j] = as_req('ghost', 'GET', '/doomed/api/auth/me');
check('被删那一队的目录现在进不去（404）', $s === 404, "http=$s " . mb_substr(J($j), 0, 160));
[$s, $j] = as_req('ghost', 'GET', '/doomed/app');
check('被删那一队的页面也不再有', in_array($s, [404, 302], true), "http=$s");

// 收尾：删掉这次建的测试文件（只删自己建的那几个名字）
foreach ($made as $f) if (is_file($f)) @unlink($f);
foreach (['images', 'stickers', 'rotation', 'battles'] as $d) @rmdir($UP . '/' . $d);
@rmdir($UP);
@unlink($taskFile);

echo "\n== 结果 ==\n";
echo "通过 $pass 项，失败 $fail 项\n";
if ($fail) {
    echo "失败清单：\n";
    foreach ($failures as $f) echo "  - $f\n";
    exit(1);
}
exit(0);
