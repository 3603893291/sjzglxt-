<?php
/**
 * 成员战绩信息（v5.11.0）功能 E2E 测试
 *
 * 前置：测试服务器已启动
 *   php -S 127.0.0.1:8899 -t public public/router.php      （.env 指向 sqlite）
 * 本脚本覆盖：
 *   1. 成员自助上传：用账号密码登录自己战队 → 上传 20 项指标 → 一人一行、重复上传整行覆盖
 *   2. 上传边界：取值校验、空提交拦截、只能写到自己名下、未登录/跨队拒绝
 *   3. 旧「战队密钥批量同步」通道确认已下线
 *   4. 管理员只读列表：人数统计、任意指标列排序（空值恒末尾）、筛选、搜索、分页、QQ 脱敏
 *   5. 可见性矩阵与多租户隔离 + 既有功能回归
 *   6. 战绩上传限频跟随入驻平台：配置读写与校验、账号限额触发 429、一键关停只挡写入不挡读、
 *      限额对所有战队同时生效（平台级配置，战队不可各自覆盖）
 *
 * 用法：php scripts/test_player_stats_e2e.php
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

// ---------- HTTP 封装（含 Cookie 管理；按 name 去重，避免重复 cookie 打断会话） ----------
function http_req(string $method, string $url, ?array $body, array &$cookies): array {
    $hdr = "Content-Type: application/json\r\n";
    if ($cookies) {
        $pairs = [];
        foreach ($cookies as $k => $v) { $pairs[] = $k . '=' . $v; }
        $hdr .= 'Cookie: ' . implode('; ', $pairs) . "\r\n";
    }
    $opts = ['http' => ['method' => $method, 'header' => $hdr, 'ignore_errors' => true, 'timeout' => 15]];
    if ($body !== null) { $opts['http']['content'] = json_encode($body, JSON_UNESCAPED_UNICODE); }
    $raw = @file_get_contents($url, false, stream_context_create($opts));
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('/^Set-Cookie:\s*([^=]+)=([^;]*)/i', $h, $m)) { $cookies[$m[1]] = trim($m[2]); }
    }
    $status = 0;
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) { $status = (int)$m[1]; }
    }
    $json = json_decode((string)$raw, true);
    return [$status, is_array($json) ? $json : ['raw' => (string)$raw]];
}

$jarOwner = []; $jarSub = []; $jarM1 = []; $jarM2 = []; $jarNoStat = []; $jarOther = []; $jar = [];
function owner_req($m, $p, $b = null)  { global $jarOwner, $BASE;  return http_req($m, $BASE . $p, $b, $jarOwner); }
function sub_req($m, $p, $b = null)    { global $jarSub, $BASE;    return http_req($m, $BASE . $p, $b, $jarSub); }
function m1_req($m, $p, $b = null)     { global $jarM1, $BASE;     return http_req($m, $BASE . $p, $b, $jarM1); }
function m2_req($m, $p, $b = null)     { global $jarM2, $BASE;     return http_req($m, $BASE . $p, $b, $jarM2); }
function nostat_req($m, $p, $b = null) { global $jarNoStat, $BASE; return http_req($m, $BASE . $p, $b, $jarNoStat); }
function other_req($m, $p, $b = null)  { global $jarOther, $BASE;  return http_req($m, $BASE . $p, $b, $jarOther); }

function db(): PDO { global $DB; $p = new PDO('sqlite:' . $DB); $p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); return $p; }
function db_one(string $sql, array $p = []): ?array {
    $st = db()->prepare($sql); $st->execute($p); $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r === false ? null : $r;
}
function stat_row(int $uid): ?array { return db_one('SELECT * FROM player_stats WHERE user_id = ?', [$uid]); }
function row_count(): int { return (int)db_one('SELECT COUNT(*) c FROM player_stats')['c']; }
/** 清掉文件缓存（主要用于重置战绩限频的账号/IP 计数，让脚本不必真等窗口过期） */
function reset_cache(): void {
    global $ROOT;
    foreach (glob($ROOT . '/runtime/cache/*/*') ?: [] as $f) { if (is_file($f)) @unlink($f); }
    foreach (glob($ROOT . '/runtime/cache/*') ?: [] as $f) { if (is_file($f)) @unlink($f); }
}
/** 20 项指标（一次上传即一张完整快照） */
function full_metrics(): array {
    return [
        'matches' => 120, 'win_rate' => 65.5, 'avg_score' => 8.42, 'kd' => 2.31,
        'score_per_match' => 1520.5, 'score_per_minute' => 12.8, 'kills' => 3400, 'deaths' => 1472,
        'kda' => 3.12, 'kills_per_match' => 28.3, 'assists_per_match' => 9.7, 'sites_per_match' => 3.4,
        'healing_per_match' => 812.6, 'revives' => 260, 'best_match_kills' => 52, 'kill_duration' => 40,
        'quit_rate' => 1.6, 'max_win_streak' => 9, 'win_rate_last10' => 70, 'win_rate_last30' => 63.3,
    ];
}

// ============================================================
// 0. 重建测试库 + 种子数据
// ============================================================
echo "== 0. 重建测试库 ==\n";
reset_cache();
foreach (glob($ROOT . '/runtime/session/*') ?: [] as $f) { @unlink($f); }
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

$T1 = $mkTenant('psmain', '战绩主测战队', 'psowner');
$T2 = $mkTenant('psother', '战绩干扰战队', 'psother2');
$ownerUid    = $mkUser($T1, 'psowner',   'owner123456',  '站长',     'admin');                    // 站长=超管，恒持全部权限
$qq1         = $mkUser($T1, '100010001', 'member123456', '一号队员', 'user');
$qq2         = $mkUser($T1, '100010002', 'member123456', '二号队员', 'user');
$subUid      = $mkUser($T1, 'pssub',     'member123456', '副管理',   'admin', ['member_view', 'stats']); // 有 stats、无 qq_full
$noStatUid   = $mkUser($T1, 'psnostat',  'member123456', '无战绩权', 'admin', ['member_view']);         // 无 stats 权限
$pendingUid  = $mkUser($T1, '100010003', 'member123456', '待审核员', 'user');
$pdo->exec("UPDATE users SET status='pending' WHERE id=$pendingUid");
$otherOwner  = $mkUser($T2, 'psother2',  'owner123456',  '干扰队站长', 'admin');
$otherUid    = $mkUser($T2, '200020001', 'member123456', '干扰队成员', 'user');
$pdo->exec("UPDATE tenants SET admin_user_id = $ownerUid WHERE id = $T1");
$pdo->exec("UPDATE tenants SET admin_user_id = $otherOwner WHERE id = $T2");
check('种子：两战队 + 站长/两名副管理/成员/待审核/干扰队', $T1 > 0 && $T2 > 0 && $qq1 > 0 && $qq2 > 0 && $subUid > 0 && $pendingUid > 0);

// ============================================================
// 1. 成员自助上传
// ============================================================
echo "\n== 1. 成员登录并上传自己的战绩 ==\n";
[$s, $j] = m1_req('GET', '/psmain/api/stats/upload', null);
[$s, $j] = http_req('POST', $BASE . '/psmain/api/stats/upload', full_metrics(), $jar);
check('未登录上传 → 401', ($j['code'] ?? 0) === 401 || $s === 401, "HTTP $s " . json_encode($j, JSON_UNESCAPED_UNICODE));

[$s, $j] = m1_req('POST', '/psmain/api/auth/login', ['username' => '100010001', 'password' => 'member123456']);
check('一号队员登录', ($j['code'] ?? 1) === 0, $j['msg'] ?? '');
[$s, $j] = m1_req('POST', '/psmain/api/stats/upload', array_merge(['game_pid' => 'PID-A-001'], full_metrics()));
check('上传 20 项战绩成功（action=created）', ($j['code'] ?? 1) === 0 && ($j['data']['action'] ?? '') === 'created', json_encode($j, JSON_UNESCAPED_UNICODE));
$r1 = stat_row($qq1);
check('落库归属＝登录者本人（无需也无法指定 user_id）', (int)($r1['user_id'] ?? 0) === $qq1 && (int)$r1['tenant_id'] === $T1);
check('场次/胜率/KD 正确入库', (int)$r1['matches'] === 120 && abs((float)$r1['win_rate'] - 65.5) < 0.01 && abs((float)$r1['kd'] - 2.31) < 0.01);
check('整数列取整、救治与击杀时长按整数入库', (int)$r1['revives'] === 260 && (int)$r1['kill_duration'] === 40 && (int)$r1['best_match_kills'] === 52);
check('game_pid 自报值已存', $r1['game_pid'] === 'PID-A-001');
check('首次上传写入 created_at / updated_at', !empty($r1['created_at']) && !empty($r1['updated_at']));

[$s, $j] = m1_req('GET', '/psmain/api/stats/my');
$d1 = $j['data'] ?? [];
check('本人读回 20 项与入库一致', ($d1['stat']['matches'] ?? 0) === 120 && count($d1['fields'] ?? []) === 20 && ($d1['game_pid'] ?? '') === 'PID-A-001', json_encode($d1, JSON_UNESCAPED_UNICODE));
check('读回含上次上传时间', !empty($d1['uploaded_at']));

// 重复上传：一人一行、整行覆盖
reset_cache();
$upd = array_merge(full_metrics(), ['matches' => 150, 'kd' => 1.9, 'kills' => 4000]);
[$s, $j] = m1_req('POST', '/psmain/api/stats/upload', $upd);
$r1b = stat_row($qq1);
check('再次上传为整行覆盖（action=updated）', ($j['data']['action'] ?? '') === 'updated' && row_count() === 1 && (int)$r1b['id'] === (int)$r1['id']);
check('覆盖后新值生效（场次 150 / KD 1.9）', (int)$r1b['matches'] === 150 && abs((float)$r1b['kd'] - 1.9) < 0.01);
check('未重新提交的 game_pid 保留原值', $r1b['game_pid'] === 'PID-A-001');
usleep(1100000);
reset_cache();
[$s, $j] = m1_req('POST', '/psmain/api/stats/upload', $upd);
check('提交完全相同的数据也会刷新上传时间', strtotime((string)stat_row($qq1)['updated_at']) > strtotime((string)$r1b['updated_at']), $r1b['updated_at'] . ' → ' . stat_row($qq1)['updated_at']);

// 整行覆盖语义：少传的指标被置空
reset_cache();
$partial = array_merge($upd, ['kd' => 2.0]);
unset($partial['kills'], $partial['win_rate']);
[$s, $j] = m1_req('POST', '/psmain/api/stats/upload', $partial);
$r1c = stat_row($qq1);
check('少传的指标列被置空（整行覆盖）', $r1c['kills'] === null && $r1c['win_rate'] === null && abs((float)$r1c['kd'] - 2.0) < 0.01);
reset_cache();
[$s, $j] = m1_req('POST', '/psmain/api/stats/upload', array_merge(['game_pid' => 'PID-A-001'], full_metrics()));
check('恢复现场：重新提交全量后 20 项齐了', (int)stat_row($qq1)['kills'] === 3400 && ($j['code'] ?? 1) === 0);

// 校验与拒绝
reset_cache();
[$s, $j] = m1_req('POST', '/psmain/api/stats/upload', array_merge(full_metrics(), ['win_rate' => 130]));
check('胜率>100 被拒并提示上限', ($j['code'] ?? 0) !== 0 && str_contains((string)($j['msg'] ?? ''), '超出上限'), json_encode($j, JSON_UNESCAPED_UNICODE));
[$s, $j] = m1_req('POST', '/psmain/api/stats/upload', array_merge(full_metrics(), ['kills' => -5]));
check('负数被拒', str_contains((string)($j['msg'] ?? ''), '不能为负数'));
[$s, $j] = m1_req('POST', '/psmain/api/stats/upload', array_merge(full_metrics(), ['kd' => '很厉害']));
check('非数字被拒', str_contains((string)($j['msg'] ?? ''), '必须是数字'));
[$s, $j] = m1_req('POST', '/psmain/api/stats/upload', array_merge(full_metrics(), ['avg_score' => 'abc', 'kda' => -1]));
check('多项同时出错一次全报出来', substr_count((string)($j['msg'] ?? ''), '；') >= 1, (string)($j['msg'] ?? ''));
check('校验失败的上传没有破坏原有数据', (int)stat_row($qq1)['matches'] === 120);
reset_cache();
[$s, $j] = m1_req('POST', '/psmain/api/stats/upload', ['game_pid' => '只有游戏ID没有指标']);
check('空提交被拦（防误清空自己的战绩）', ($j['code'] ?? 0) !== 0 && str_contains((string)($j['msg'] ?? ''), '至少提交一项'), json_encode($j, JSON_UNESCAPED_UNICODE));
check('空提交后原战绩完好', (int)stat_row($qq1)['matches'] === 120);

// 只能写到自己名下
reset_cache();
[$s, $j] = m2_req('POST', '/psmain/api/auth/login', ['username' => '100010002', 'password' => 'member123456']);
check('二号队员登录', ($j['code'] ?? 1) === 0, $j['msg'] ?? '');
[$s, $j] = m2_req('POST', '/psmain/api/stats/upload', array_merge(
    ['user_id' => $qq1, 'username' => '100010001', 'nickname' => '伪造', 'game_pid' => 'PID-B-002'],
    full_metrics(), ['matches' => 30, 'kd' => 0.8, 'kills' => 400]));
check('上传时塞别人的 user_id/username 被忽略（仍写自己）', (int)($j['data']['user_id'] ?? 0) === $qq2 && row_count() === 2, json_encode($j['data'] ?? $j, JSON_UNESCAPED_UNICODE));
check('别人的战绩未被篡改（一号队员场次仍是 120）', (int)stat_row($qq1)['matches'] === 120);
check('自己的行才落到自己名下（场次取本次提交值 30）', (int)stat_row($qq2)['matches'] === 30);

// 限频参数已改由入驻平台下发（默认 60 秒 / 单账号 10 次），默认额度内连续两次上传都应放行；
// 命中上限的 429 放到第 6 节收紧平台限额后再验
[$s, $j] = m2_req('POST', '/psmain/api/stats/upload', array_merge(full_metrics(), ['matches' => 30, 'kd' => 0.8, 'kills' => 400]));
check('默认限额内连续第二次上传不再被硬编码冷却打回', ($j['code'] ?? 1) === 0, json_encode($j, JSON_UNESCAPED_UNICODE));
check('限频全走平台配置，控制器内无写死秒数与旧缓存键',
    !preg_match('/UPLOAD_COOLDOWN|ps_upload_/', (string)file_get_contents($ROOT . '/app/controller/Stats.php')));
reset_cache();

// 待审核账号
[$s, $j] = http_req('POST', $BASE . '/psmain/api/auth/login', ['username' => '100010003', 'password' => 'member123456'], $jar);
check('待审核账号登录被拒（自然也无法上传）', ($j['code'] ?? 0) !== 0 && str_contains((string)($j['msg'] ?? ''), '审核'), json_encode($j, JSON_UNESCAPED_UNICODE));

// ============================================================
// 2. 旧密钥批量通道已下线
// ============================================================
echo "\n== 2. 旧开放通道确认下线 ==\n";
[$s, $j] = http_req('GET', $BASE . '/api/open/stats/members', null, $jar, ['X-Api-Key: ' . str_repeat('a', 48)]);
check('旧 /api/open/stats/* 路由已移除（404）', $s === 404 || ($j['code'] ?? 0) === 404, "HTTP $s " . json_encode($j, JSON_UNESCAPED_UNICODE));
$hasFile = file_exists($ROOT . '/app/controller/OpenStats.php');
check('OpenStats 控制器文件已删除', $hasFile === false);
check('路由表内不再有 open/stats 与批量同步字样',
    !preg_match('/open\/stats|OpenStats/', (string)file_get_contents($ROOT . '/route/app.php')));

// ============================================================
// 3. 管理员只读列表
// ============================================================
echo "\n== 3. 管理中心读取 ==\n";
[$s, $j] = owner_req('POST', '/psmain/api/auth/login', ['username' => 'psowner', 'password' => 'owner123456']);
check('站长登录', ($j['code'] ?? 1) === 0, $j['msg'] ?? '');
[$s, $j] = owner_req('GET', '/psmain/api/admin/stats');
$d = $j['data'] ?? [];
$memTotal = 5;   // T1 在职 approved：站长 + 一号队员 + 二号队员 + 副管理 + 无战绩权
check('战绩列表 code=0', ($j['code'] ?? 1) === 0, json_encode($j, JSON_UNESCAPED_UNICODE));
check('人数统计：已上传 2 人、未上传＝其余在职成员', ($d['counts']['uploaded'] ?? 0) === 2 && ($d['counts']['missing'] ?? 0) === $d['counts']['total'] - 2, json_encode($d['counts'] ?? []));
check('待审核账号不出现在名单里', !in_array('待审核员', array_column((array)($d['list'] ?? []), 'nickname'), true));
check('默认按场次降序（120 → 30 → 空值）', (int)($d['list'][0]['stats']['matches'] ?? 0) === 120 && (int)($d['list'][1]['stats']['matches'] ?? 0) === 30, implode(',', array_map(fn($r) => var_export($r['stats']['matches'], true), (array)($d['list'] ?? []))));
check('未上传的 3 名成员排在末尾且指标全空',
    ($d['list'][2]['has_stats'] ?? true) === false && ($d['list'][4]['has_stats'] ?? true) === false && $d['list'][4]['stats']['matches'] === null);
check('返回 20 项指标口径供前端渲染表头', count($d['fields'] ?? []) === 20);
check('站长（超管恒持全部权限）→ QQ 明文', (string)($d['list'][0]['username'] ?? '') === '100010001', (string)($d['list'][0]['username'] ?? ''));
check('列表带 game_pid 供管理员核对游戏账号', ($d['list'][0]['game_pid'] ?? '') === 'PID-A-001');

[$s, $j] = owner_req('GET', '/psmain/api/admin/stats?sort=kd&order=asc');
$kds = array_map(fn($r) => $r['stats']['kd'], $j['data']['list']);
check('按 KD 升序：0.8 → 2.31 → 空值恒末尾', $kds[0] === 0.8 && $kds[1] === 2.31 && $kds[2] === null && end($kds) === null, json_encode($kds));
[$s, $j] = owner_req('GET', '/psmain/api/admin/stats?sort=kd&order=desc');
check('按 KD 降序：首行 2.31 且空值仍在末尾', $j['data']['list'][0]['stats']['kd'] === 2.31
    && $j['data']['list'][1]['stats']['kd'] === 0.8 && $j['data']['list'][3]['stats']['kd'] === null && $j['data']['list'][4]['stats']['kd'] === null);
[$s, $j] = owner_req('GET', '/psmain/api/admin/stats?sort=' . urlencode('kd;drop table users') . '&order=desc');
check('非白名单/注入式排序列回落默认且不报错', ($j['code'] ?? 1) === 0 && (int)($j['data']['list'][0]['stats']['matches'] ?? 0) === 120);
check('注入尝试后数据表仍在', (int)db_one('SELECT COUNT(*) c FROM player_stats')['c'] === 2);
[$s, $j] = owner_req('GET', '/psmain/api/admin/stats?filter=missing');
check('filter=missing 只列出未上传成员', count($j['data']['list'] ?? []) === $d['counts']['missing'] && !in_array(true, array_column($j['data']['list'], 'has_stats'), true));
[$s, $j] = owner_req('GET', '/psmain/api/admin/stats?filter=uploaded');
check('filter=uploaded 只列出已上传成员', count($j['data']['list'] ?? []) === 2 && !in_array(false, array_column($j['data']['list'], 'has_stats'), true));
[$s, $j] = owner_req('GET', '/psmain/api/admin/stats?keyword=' . urlencode('一号'));
check('按昵称搜索命中 1 条', count($j['data']['list'] ?? []) === 1 && ($j['data']['list'][0]['nickname'] ?? '') === '一号队员');
[$s, $j] = owner_req('GET', '/psmain/api/admin/stats?keyword=PID-A-001');
check('按游戏ID搜索命中 1 条', count($j['data']['list'] ?? []) === 1);
[$s, $j] = owner_req('GET', '/psmain/api/admin/stats?page=3&size=2');
check('分页 page=3 size=2 返回末 1 条、total=5', count($j['data']['list'] ?? []) === 1 && (int)($j['data']['total'] ?? 0) === $memTotal, 'total=' . ($j['data']['total'] ?? 0) . ' n=' . count($j['data']['list'] ?? []));
[$s, $j] = owner_req('DELETE', '/psmain/api/admin/stats/1');
check('管理员无删除入口（路由不存在）', $s === 404 || in_array($j['code'] ?? 0, [404, 405], true), "HTTP $s");
[$s, $j] = owner_req('POST', '/psmain/api/admin/stats', ['matches' => 1]);
check('管理员无写入/修改入口（路由不存在）', $s === 404 || in_array($j['code'] ?? 0, [404, 405], true), "HTTP $s");

// ============================================================
// 4. 可见性与隔离
// ============================================================
echo "\n== 4. 可见性与权限边界 ==\n";
[$s, $j] = sub_req('POST', '/psmain/api/auth/login', ['username' => 'pssub', 'password' => 'member123456']);
check('副管理员（已授予 stats）登录', ($j['code'] ?? 1) === 0, $j['msg'] ?? '');
[$s, $j] = sub_req('GET', '/psmain/api/admin/stats');
check('已授予 stats 的副管理员可读战绩', ($j['code'] ?? 1) === 0, json_encode($j, JSON_UNESCAPED_UNICODE));
check('无 qq_full 权限 → 成员 QQ 打码', str_contains((string)($j['data']['list'][0]['username'] ?? ''), '*'), (string)($j['data']['list'][0]['username'] ?? ''));
[$s, $j] = nostat_req('POST', '/psmain/api/auth/login', ['username' => 'psnostat', 'password' => 'member123456']);
check('未授予战绩权限的管理员登录', ($j['code'] ?? 1) === 0, $j['msg'] ?? '');
[$s, $j] = nostat_req('GET', '/psmain/api/admin/stats');
check('无 stats 权限的管理员被拒 403', ($j['code'] ?? 0) === 403, json_encode($j, JSON_UNESCAPED_UNICODE));

[$s, $j] = m1_req('GET', '/psmain/api/admin/stats');
check('普通成员访问管理端战绩 → 403/401', in_array($j['code'] ?? 0, [401, 403], true), json_encode($j, JSON_UNESCAPED_UNICODE));
[$s, $j] = m1_req('GET', '/psmain/api/stats/my');
check('队友之间互不可见（只返回本人行）', (int)($j['data']['stat']['matches'] ?? 0) === 120 && ($j['data']['game_pid'] ?? '') === 'PID-A-001');
[$s, $j] = m2_req('GET', '/psmain/api/stats/my');
check('另一成员读到的是他自己的行', ($j['data']['game_pid'] ?? 'x') === 'PID-B-002' && (int)($j['data']['stat']['matches'] ?? 0) === 30);

// 干扰队：成员只能写进自己战队，管理员也只能看自己战队
[$s, $j] = other_req('POST', '/psother/api/auth/login', ['username' => '200020001', 'password' => 'member123456']);
check('干扰队成员登录', ($j['code'] ?? 1) === 0, $j['msg'] ?? '');
[$s, $j] = other_req('POST', '/psother/api/stats/upload', array_merge(['game_pid' => 'PID-B-001'], full_metrics()));
check('干扰队成员上传成功（多租户各自可用）', ($j['code'] ?? 1) === 0, json_encode($j, JSON_UNESCAPED_UNICODE));
check('跨队写入被隔离（干扰队成员的行属于 T2）', (int)stat_row($otherUid)['tenant_id'] === $T2);
[$s, $j] = http_req('POST', $BASE . '/psother/api/auth/login', ['username' => 'psother2', 'password' => 'owner123456'], $jarOther);
check('干扰队站长登录', ($j['code'] ?? 1) === 0, $j['msg'] ?? '');
[$s, $j] = other_req('GET', '/psother/api/admin/stats');
check('跨队读取隔离：干扰队只看到自己 2 名成员', (int)($j['data']['counts']['total'] ?? 0) === 2 && (int)($j['data']['counts']['uploaded'] ?? 0) === 1, json_encode($j['data']['counts'] ?? $j, JSON_UNESCAPED_UNICODE));
check('干扰队列表里没有任何主测队成员', !in_array('一号队员', array_column((array)($j['data']['list'] ?? []), 'nickname'), true));

// ============================================================
// 5. 既有功能回归
// ============================================================
echo "\n== 5. 既有功能回归 ==\n";
[$s, $j] = owner_req('GET', '/psmain/api/admin/blacklist');
check('既有管理端接口不受影响（黑名单 200）', ($j['code'] ?? 1) === 0, json_encode($j, JSON_UNESCAPED_UNICODE));
[$s, $j] = owner_req('GET', '/psmain/api/auth/me');
check('登录态与权限列表正常（me.admin_perms 含 stats）',
    ($j['code'] ?? 1) === 0 && in_array('stats', (array)($j['data']['admin_perms'] ?? []), true), json_encode($j['data']['admin_perms'] ?? [], JSON_UNESCAPED_UNICODE));
[$s, $j] = http_req('GET', $BASE . '/api/stats/my', null, $jarOwner);
check('根路径（平台上下文）无战队战绩接口会话 → 401', ($j['code'] ?? 0) === 401, json_encode($j, JSON_UNESCAPED_UNICODE));

// ============================================================
// 6. 战绩上传限频跟随入驻平台
// ============================================================
echo "\n== 6. 平台限频：配置读写 / 上限生效 / 一键关停 ==\n";
$jarPlat = [];
function plat_req($m, $p, $b = null) { global $jarPlat, $BASE; return http_req($m, $BASE . $p, $b, $jarPlat); }
/** 保存接口一次校验并落库全部字段：只改要测的那几项，其余沿用当前回显值 */
function plat_rate_body(array $override) {
    [$s, $j] = plat_req('GET', '/api/platform/admin/api-rate');
    $r = $j['data'] ?? [];
    return array_merge([
        'rate_window'    => (int)($r['rate_window'] ?? 60),
        'rate_limit'     => (int)($r['rate_limit'] ?? 60),
        'ip_rate_limit'  => (int)($r['ip_rate_limit'] ?? 300),
        'stats_window'   => (int)($r['stats_window'] ?? 60),
        'stats_limit'    => (int)($r['stats_limit'] ?? 10),
        'stats_ip_limit' => (int)($r['stats_ip_limit'] ?? 60),
        'stats_enabled'  => ($r['stats_enabled'] ?? true) ? '1' : '0',
    ], $override);
}
function plat_save(array $override) {
    return plat_req('POST', '/api/platform/admin/api-rate', plat_rate_body($override));
}

[$s, $j] = plat_req('POST', '/api/platform/auth/login', ['username' => 'admin', 'password' => 'admin123']);
check('平台超管登录（入驻平台站点）', ($j['code'] ?? 1) === 0, json_encode($j, JSON_UNESCAPED_UNICODE));
[$s, $j] = plat_req('GET', '/api/platform/admin/api-rate');
$r = $j['data'] ?? [];
check('限频接口回显战绩 4 项（默认 开启 / 60 秒 / 账号 10 次 / IP 60 次）',
    ($r['stats_enabled'] ?? null) === true && (int)($r['stats_window'] ?? 0) === 60
    && (int)($r['stats_limit'] ?? 0) === 10 && (int)($r['stats_ip_limit'] ?? 0) === 60,
    json_encode($r, JSON_UNESCAPED_UNICODE));

[$s, $j] = plat_save(['stats_limit' => 0]);
check('单账号上限填 0 被拒（防把接口填死后救不回来）',
    ($j['code'] ?? 0) !== 0 && str_contains((string)($j['msg'] ?? ''), '战绩单账号上限'), json_encode($j, JSON_UNESCAPED_UNICODE));
[$s, $j] = plat_save(['stats_window' => 99999]);
check('上传窗口超出 5-3600 被拒', ($j['code'] ?? 0) !== 0 && str_contains((string)($j['msg'] ?? ''), '战绩上传窗口'), json_encode($j, JSON_UNESCAPED_UNICODE));
[$s, $j] = plat_req('GET', '/api/platform/admin/api-rate');
$r = $j['data'] ?? [];
check('两次非法保存都没有改动库里的既有配置', (int)($r['stats_limit'] ?? 0) === 10 && (int)($r['stats_window'] ?? 0) === 60, json_encode($r, JSON_UNESCAPED_UNICODE));

[$s, $j] = owner_req('POST', '/api/platform/admin/api-rate', plat_rate_body(['stats_limit' => 999]));
check('战队站长改不动平台限频（请求被拒）', ($j['code'] ?? 0) !== 0, "HTTP $s " . json_encode($j, JSON_UNESCAPED_UNICODE));
[$s, $j] = plat_req('GET', '/api/platform/admin/api-rate');
check('被拒的请求确实没落库（上限仍是 10 而不是 999）', (int)($j['data']['stats_limit'] ?? 0) === 10, json_encode($j['data'] ?? $j, JSON_UNESCAPED_UNICODE));

// 收紧到「每账号每 3600 秒 2 次」：不重启、不改代码，下一个请求就按新额度算
[$s, $j] = plat_save(['stats_window' => 3600, 'stats_limit' => 2, 'stats_ip_limit' => 100]);
check('平台侧收紧战绩限额成功', ($j['code'] ?? 1) === 0, json_encode($j, JSON_UNESCAPED_UNICODE));
reset_cache();
[$s, $j] = m1_req('POST', '/psmain/api/stats/upload', array_merge(full_metrics(), ['matches' => 71]));
check('收紧后第 1 次上传放行', ($j['code'] ?? 1) === 0, json_encode($j, JSON_UNESCAPED_UNICODE));
[$s, $j] = m1_req('POST', '/psmain/api/stats/upload', array_merge(full_metrics(), ['matches' => 72]));
check('第 2 次仍在限额内放行', ($j['code'] ?? 1) === 0, json_encode($j, JSON_UNESCAPED_UNICODE));
[$s, $j] = m1_req('POST', '/psmain/api/stats/upload', array_merge(full_metrics(), ['matches' => 73]));
$msg = (string)($j['msg'] ?? '');
check('第 3 次命中账号维度限额 → 429', ($j['code'] ?? 0) === 429 && $s === 429, "HTTP $s $msg");
check('429 提示回显平台真实窗口与上限（不是写死话术）', str_contains($msg, '3600') && str_contains($msg, '2 次'), $msg);
check('被限频的那次没有落库（场次仍是 72）', (int)(stat_row($qq1)['matches'] ?? 0) === 72, (string)(stat_row($qq1)['matches'] ?? 'null'));
[$s, $j] = m1_req('GET', '/psmain/api/stats/my');
check('限频只挡写入：本人仍可读回战绩', ($j['code'] ?? 1) === 0 && (int)($j['data']['stat']['matches'] ?? 0) === 72, json_encode($j['data']['stat'] ?? $j, JSON_UNESCAPED_UNICODE));

// 另一支战队的成员同时受同一份配置约束 —— 证明这是平台级参数、战队无从覆盖
[$s, $j] = other_req('POST', '/psother/api/stats/upload', array_merge(full_metrics(), ['matches' => 61]));
check('干扰队第 1 次上传放行', ($j['code'] ?? 1) === 0, json_encode($j, JSON_UNESCAPED_UNICODE));
[$s, $j] = other_req('POST', '/psother/api/stats/upload', array_merge(full_metrics(), ['matches' => 62]));
check('干扰队第 2 次上传放行', ($j['code'] ?? 1) === 0, json_encode($j, JSON_UNESCAPED_UNICODE));
[$s, $j] = other_req('POST', '/psother/api/stats/upload', array_merge(full_metrics(), ['matches' => 63]));
check('干扰队同样在第 3 次被限（同一份平台配置对所有战队生效）', ($j['code'] ?? 0) === 429, json_encode($j, JSON_UNESCAPED_UNICODE));

// 一键关停：没超额的账号也拒；读取与页面完全不受影响
[$s, $j] = plat_save(['stats_enabled' => '0']);
check('平台侧关闭战绩写入', ($j['code'] ?? 1) === 0, json_encode($j, JSON_UNESCAPED_UNICODE));
reset_cache();
[$s, $j] = m2_req('POST', '/psmain/api/stats/upload', array_merge(full_metrics(), ['matches' => 88]));
$msg = (string)($j['msg'] ?? '');
check('关停后计数为零也照样拒写（429 + 中文说明）', ($j['code'] ?? 0) === 429 && str_contains($msg, '入驻平台关闭'), "HTTP $s $msg");
[$s, $j] = owner_req('GET', '/psmain/api/admin/stats');
check('关停期间管理员仍可读列表（只挡写入）',
    ($j['code'] ?? 1) === 0 && (int)($j['data']['counts']['uploaded'] ?? 0) === 2, json_encode($j['data']['counts'] ?? $j, JSON_UNESCAPED_UNICODE));

// 恢复默认值，确认改回来同样立即生效
[$s, $j] = plat_save(['stats_enabled' => '1', 'stats_window' => 60, 'stats_limit' => 10, 'stats_ip_limit' => 60]);
reset_cache();
[$s, $j] = m2_req('POST', '/psmain/api/stats/upload', array_merge(full_metrics(), ['matches' => 30, 'kd' => 0.8, 'kills' => 400]));
check('重新开启后写入立刻恢复（无需重启进程）', ($j['code'] ?? 1) === 0, json_encode($j, JSON_UNESCAPED_UNICODE));
[$s, $j] = plat_req('GET', '/api/platform/admin/api-rate');
$r = $j['data'] ?? [];
check('限频配置已回到默认（升级脚本补种的那套值）',
    ($r['stats_enabled'] ?? null) === true && (int)$r['stats_window'] === 60 && (int)$r['stats_limit'] === 10 && (int)$r['stats_ip_limit'] === 60,
    json_encode($r, JSON_UNESCAPED_UNICODE));

echo "\n================ 结果 ================\n";
echo "通过 $pass / 失败 $fail\n";
if ($failures) { echo "失败项：\n - " . implode("\n - ", $failures) . "\n"; }
exit($fail > 0 ? 1 : 0);
