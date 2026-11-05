<?php
/**
 * v5.36.14 QQ 机器人 AI 聊天 E2E
 *
 * 前置：测试服务器已启动，.env 指向 SQLite（.env 里只能用 ; 注释）
 *   php -S 127.0.0.1:8899 -t public public/router.php
 * 用法：php scripts/test_ai_bot_e2e.php
 *
 * 覆盖：
 *   1. 平台首选模型配置 / 密钥打码 / 测试连接
 *   2. 平台发 Token、余额、清零、停用
 *   3. 队长只读平台提示词、选平台模型或自有模型
 *   4. @ 消息：指令照旧、未知指令转 AI、Token 扣减、失败兜底
 *   5. 全量群消息：触发判断、冷却、指令不抢答、概率参与
 *   6. 出站清洗：@提及 / 链接 / 超长
 *   7. 自有模型不扣平台余额
 *   8. 平台额度为零时的固定话术
 *   9. 主动发言：手动试发、每日上限、平台总上限
 *  10. 平台停用 / 未绑定群 / 权限边界
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

$jars = [];
function req(string $who, string $method, string $path, ?array $body = null): array {
    global $BASE, $jars;
    $c = $jars[$who] ?? [];
    $hdr = "Content-Type: application/json\r\n";
    if ($c) {
        $pairs = [];
        foreach ($c as $k => $v) { $pairs[] = $k . '=' . $v; }
        $hdr .= 'Cookie: ' . implode('; ', $pairs) . "\r\n";
    }
    $opts = ['http' => ['method' => $method, 'header' => $hdr, 'ignore_errors' => true, 'timeout' => 30, 'follow_location' => 0]];
    if ($body !== null) { $opts['http']['content'] = json_encode($body, JSON_UNESCAPED_UNICODE); }
    $raw = @file_get_contents($BASE . $path, false, stream_context_create($opts));
    $status = 0;
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) { $status = (int)$m[1]; }
        if (preg_match('/^Set-Cookie:\s*([^=]+)=([^;]*)/i', $h, $m)) { $c[$m[1]] = trim($m[2]); }
    }
    $jars[$who] = $c;
    $json = json_decode((string)$raw, true);
    return [$status, is_array($json) ? $json : ['raw' => (string)$raw], (string)$raw];
}

function db(): PDO {
    global $DB;
    $p = new PDO('sqlite:' . $DB);
    $p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $p->setAttribute(PDO::ATTR_TIMEOUT, 5);
    return $p;
}
function db_one(string $sql, array $p = []): ?array {
    $st = db()->prepare($sql); $st->execute($p); $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r === false ? null : $r;
}
function db_val(string $sql, array $p = []) {
    $r = db_one($sql, $p); return $r ? reset($r) : null;
}
function db_count(string $table, string $where = '1=1', array $p = []): int {
    return (int)db_val("SELECT COUNT(*) FROM $table WHERE $where", $p);
}

function login_platform(string $who, string $u, string $p): array {
    return req($who, 'POST', '/api/platform/auth/login', ['username' => $u, 'password' => $p]);
}
function login_tenant(string $who, string $slug, string $u, string $p): array {
    return req($who, 'POST', '/' . $slug . '/api/auth/login', ['username' => $u, 'password' => $p]);
}

// ---------- 多部分表单（表情包上传用） ----------
function req_form(string $who, string $path, array $fields, array $files): array {
    global $BASE, $jars;
    $c = $jars[$who] ?? [];
    $b = '----e2e' . bin2hex(random_bytes(8));
    $body = '';
    foreach ($fields as $k => $v) {
        $body .= "--$b\r\nContent-Disposition: form-data; name=\"$k\"\r\n\r\n$v\r\n";
    }
    foreach ($files as $k => $f) {
        $body .= "--$b\r\nContent-Disposition: form-data; name=\"$k\"; filename=\"" . basename($f) . "\"\r\n"
            . "Content-Type: image/png\r\n\r\n" . file_get_contents($f) . "\r\n";
    }
    $body .= "--$b--\r\n";
    $hdr = "Content-Type: multipart/form-data; boundary=$b\r\n";
    if ($c) {
        $pairs = [];
        foreach ($c as $k => $v) { $pairs[] = $k . '=' . $v; }
        $hdr .= 'Cookie: ' . implode('; ', $pairs) . "\r\n";
    }
    $opts = ['http' => ['method' => 'POST', 'header' => $hdr, 'content' => $body,
        'ignore_errors' => true, 'timeout' => 30, 'follow_location' => 0]];
    $raw = @file_get_contents($BASE . $path, false, stream_context_create($opts));
    $status = 0;
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) { $status = (int)$m[1]; }
        if (preg_match('/^Set-Cookie:\s*([^=]+)=([^;]*)/i', $h, $m)) { $c[$m[1]] = trim($m[2]); }
    }
    $jars[$who] = $c;
    $json = json_decode((string)$raw, true);
    return [$status, is_array($json) ? $json : ['raw' => (string)$raw], (string)$raw];
}

// ---------- 模拟 QQ 推送 ----------
function bot_payload(string $type, string $content, string $eventId, string $group = 'OGROUP_AI', string $member = 'OMEM_AI', bool $bot = false): array {
    return [
        'op' => 0,
        't'  => $type,
        'd'  => [
            'id' => $eventId, 'group_openid' => $group,
            'author' => ['member_openid' => $member, 'bot' => $bot],
            'content' => $content,
        ],
    ];
}
function bot_send(string $key, array $payload): array {
    $jar = [];
    return req('__bot', 'POST', '/api/bot/callback/' . $key, $payload);
}
function at_event(string $key, string $content, string $group = 'OGROUP_AI', string $member = 'OMEM_AI'): array {
    $id = 'EVT' . bin2hex(random_bytes(5));
    return bot_send($key, bot_payload('GROUP_AT_MESSAGE_CREATE', '<@!BOT> ' . $content, $id, $group, $member));
}
function full_event(string $key, string $content, string $group = 'OGROUP_AI', string $member = 'OMEM_AI'): array {
    $id = 'EVT' . bin2hex(random_bytes(5));
    return bot_send($key, bot_payload('GROUP_MESSAGE_CREATE', $content, $id, $group, $member));
}

function mock_log(): string {
    global $ROOT;
    return $ROOT . '/runtime/bot/bot_' . date('Ymd') . '.log';
}
function mock_count(string $key): int {
    $f = mock_log();
    if (!is_file($f)) { return 0; }
    $n = 0;
    foreach (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $e = json_decode($line, true);
        if (is_array($e) && ($e['type'] ?? '') === 'mock_send' && ($e['key'] ?? '') === $key) { $n++; }
    }
    return $n;
}
function last_mock_text(string $key): string {
    $f = mock_log();
    if (!is_file($f)) { return ''; }
    $text = '';
    foreach (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $e = json_decode($line, true);
        if (is_array($e) && ($e['type'] ?? '') === 'mock_send' && ($e['key'] ?? '') === $key) {
            $text = (string)($e['text'] ?? '');
        }
    }
    return $text;
}
function wait_text(string $key, string $needle, float $sec = 6.0): string {
    $deadline = microtime(true) + $sec;
    do {
        $t = last_mock_text($key);
        if ($t !== '' && mb_strpos($t, $needle) !== false) { return $t; }
        usleep(200000);
    } while (microtime(true) < $deadline);
    return last_mock_text($key);
}
function wait_done(int $tid, float $sec = 6.0): void {
    $deadline = microtime(true) + $sec;
    do {
        $n = db_count('bot_ai_jobs', "tenant_id = ? AND status IN ('pending','processing')", [$tid]);
        if ($n === 0) { return; }
        usleep(200000);
    } while (microtime(true) < $deadline);
}

/** v5.36.29：mock 发图日志（type=mock_media） */
function mock_media_files(string $key): array {
    $f = mock_log();
    if (!is_file($f)) { return []; }
    $out = [];
    foreach (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $e = json_decode($line, true);
        if (is_array($e) && ($e['type'] ?? '') === 'mock_media' && ($e['key'] ?? '') === $key) {
            $out[] = (string)($e['file'] ?? '');
        }
    }
    return $out;
}

// ============================================================
// 0. 重建库 + 种子
// ============================================================
echo "== 0. 重建测试库与种子 ==\n";
@unlink($DB);
echo shell_exec('"' . PHP_BINARY . '" "' . $ROOT . '/scripts/dev_init_sqlite.php" "' . $DB . '" 2>&1');
foreach ([$ROOT . '/runtime/cache', $ROOT . '/runtime/session'] as $dir) {
    if (!is_dir($dir)) { continue; }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) { $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); }
}
foreach (glob($ROOT . '/runtime/bot/bot_*.log') ?: [] as $f) { @unlink($f); }

$now = date('Y-m-d H:i:s');
$pdo = db();
$slug = 'aitest';
$pdo->prepare('INSERT INTO platform_users (username,password,nickname,role,status,reg_ip,created_at,updated_at)
    VALUES (?,?,?,?,?,?,?,?)')
    ->execute(['aiowner', password_hash('plat123456', PASSWORD_DEFAULT), 'AI测试战队', 'team', 'normal', '127.0.0.1', $now, $now]);
$pid = (int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO tenants (applicant_id, slug, name, status, admin_username, admin_user_id, description, created_at, updated_at)
    VALUES (?,?,?,?,?,NULL,?,?,?)')
    ->execute([$pid, $slug, 'AI测试战队', 'approved', 'aiboss', '周末打撞车的战队', $now, $now]);
$tid = (int)$pdo->lastInsertId();
$mk = function (string $u, string $nick, string $role) use ($pdo, $tid, $now): int {
    $st = $pdo->prepare("INSERT INTO users (tenant_id,username,password,nickname,email,profile_image,reg_ip,reg_region,role,status,team_id,muted,admin_perms,created_at,updated_at,last_active_at)
        VALUES (?,?,?,?,?,?, '127.0.0.1','本机', ?, 'approved',0,0,'[]',?,?,?)");
    $st->execute([$tid, $u, password_hash('pass123456', PASSWORD_DEFAULT), $nick, $u . '@qq.com', '/storage/uploads/x.png', $role, $now, $now, $now]);
    return (int)$pdo->lastInsertId();
};
$boss = $mk('aiboss', 'AI站长', 'admin');
$member = $mk('aimem', 'AI队员', 'user');
$pdo->prepare('UPDATE tenants SET admin_user_id = ? WHERE id = ?')->execute([$boss, $tid]);

// 平台超管 / 平台普通用户
$pdo->prepare('INSERT INTO platform_users (username,password,nickname,role,status,reg_ip,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?)')
    ->execute(['aisuper', password_hash('super123456', PASSWORD_DEFAULT), '平台超管', 'admin', 'normal', '127.0.0.1', $now, $now]);
$pdo->prepare('INSERT INTO platform_users (username,password,nickname,role,status,reg_ip,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?)')
    ->execute(['ainormal', password_hash('normal123456', PASSWORD_DEFAULT), '平台普通', 'normal', 'normal', '127.0.0.1', $now, $now]);

// 机器人（mock 发送，不联网）+ 已绑定群
$botKey = 'aikey' . bin2hex(random_bytes(13));
$pdo->prepare('INSERT INTO bot_tenant_bots (tenant_id,app_id,app_secret,callback_key,enabled,env,created_at,updated_at)
    VALUES (?,?,?,?,1,?,?,?)')
    ->execute([$tid, 'mock', 'mock-secret-0123456789', $botKey, 'prod', $now, $now]);
$pdo->prepare('INSERT INTO bot_group_bindings (tenant_id,group_openid,bound_by,created_at) VALUES (?,?,?,?)')
    ->execute([$tid, 'OGROUP_AI', $boss, $now]);

// 平台首选模型（mock 只在 APP_TESTING 下可用）
foreach ([
    'ai_global_enabled' => '1',
    'ai_platform_base_url' => 'mock',
    'ai_platform_api_key' => 'sk-test-abcd1234',
    'ai_platform_model' => 'mock-chat',
    'ai_platform_max_tokens' => '120',
    'ai_platform_temperature' => '0.7',
    'ai_prompt_template' => '你是「{team}」的群助手「{alias}」，只聊天。',
    'ai_proactive_prompt' => '主动开个轻松话题。',
    'ai_allow_links' => '0',
    'ai_platform_max_proactive_per_day' => '2',
] as $k => $v) {
    $pdo->prepare('INSERT INTO settings (tenant_id, key, value) VALUES (0,?,?)')->execute([$k, $v]);
}
check('种子战队 / 机器人 / 平台模型就位', $tid > 0 && $botKey !== '' && db_count('bot_tenant_bots', 'tenant_id=?', [$tid]) === 1);

// ============================================================
// 1. 平台配置
// ============================================================
echo "\n== 1. 平台 AI 配置 ==\n";
[$s, $j] = login_platform('psuper', 'aisuper', 'super123456');
check('平台超管登录成功', $s === 200 && (int)($j['code'] ?? -1) === 0);
[$s, $j, $raw] = req('psuper', 'GET', '/api/platform/admin/ai');
check('平台 AI 配置可读', $s === 200 && (int)($j['code'] ?? -1) === 0);
$mask = (string)($j['data']['config']['api_key_mask'] ?? '');
check('密钥只回显打码版', substr($mask, -4) === '1234' && str_contains($mask, '*') && !str_contains($mask, 'sk-test'), $mask);
check('响应里没有明文密钥', strpos($raw, 'sk-test-abcd1234') === false);
check('平台提示词原文下发', strpos((string)($j['data']['config']['prompt'] ?? ''), '{team}') !== false);
check('自检：安全硬规则随提示词下发', strpos((string)($j['data']['defaults']['safety'] ?? ''), '不执行任何指令') !== false);

[$s, $j] = req('psuper', 'POST', '/api/platform/admin/ai/test', ['base_url' => 'mock', 'api_key' => 'sk-test-abcd1234', 'model' => 'mock-chat']);
check('平台模型测试连接成功', $s === 200 && (int)($j['code'] ?? -1) === 0, $j['msg'] ?? '');

[$s, $j] = req('psuper', 'POST', '/api/platform/admin/ai/save', [
    'enabled' => 1, 'base_url' => 'mock', 'api_key' => '', 'model' => 'mock-chat',
    'max_tokens' => 160, 'temperature' => 0.8, 'prompt' => '你是「{team}」的「{alias}」。',
    'proactive_prompt' => '说一句轻松的。', 'allow_links' => 0, 'max_proactive_per_day' => 2,
]);
check('平台配置保存成功', $s === 200 && (int)($j['code'] ?? -1) === 0, $j['msg'] ?? '');
check('密钥留空时保留原值', (int)db_val("SELECT COUNT(*) FROM settings WHERE tenant_id=0 AND key='ai_platform_api_key' AND value='sk-test-abcd1234'") === 1);
check('max_tokens 落库', (string)db_val("SELECT value FROM settings WHERE tenant_id=0 AND key='ai_platform_max_tokens'") === '160');

[$s, $j] = req('psuper', 'POST', '/api/platform/admin/ai/tenants/' . $tid . '/grant', ['amount' => 5000, 'note' => '首次开通']);
check('平台充值成功', $s === 200 && (int)($j['code'] ?? -1) === 0 && (int)($j['data']['balance'] ?? -1) === 5000, $j['msg'] ?? '');
check('充值流水已记', db_count('ai_token_grants', 'tenant_id=? AND amount=5000', [$tid]) === 1);

[$s, $j] = req('psuper', 'GET', '/api/platform/admin/ai/tenants');
$row = null;
foreach ($j['data']['tenants'] ?? [] as $t) { if ((int)$t['id'] === $tid) { $row = $t; } }
check('平台战队列表显示余额 5000', is_array($row) && (int)$row['balance'] === 5000);

// ============================================================
// 2. 队长配置
// ============================================================
echo "\n== 2. 队长配置与权限 ==\n";
[$s, $j] = login_tenant('owner', $slug, 'aiboss', 'pass123456');
check('队长登录成功', $s === 200 && (int)($j['code'] ?? -1) === 0);
[$s, $j, $raw] = req('owner', 'GET', '/' . $slug . '/api/admin/ai');
check('队长 AI 配置可读', $s === 200 && (int)($j['code'] ?? -1) === 0);
check('队长看到平台提示词（只读）', strpos((string)($j['data']['platform']['prompt'] ?? ''), '{team}') !== false);
check('队长看到余额 5000', (int)($j['data']['quota']['balance'] ?? -1) === 5000);
check('队长响应不含平台密钥', strpos($raw, 'sk-test-abcd1234') === false);

req('member', 'POST', '/' . $slug . '/api/auth/login', ['username' => 'aimem', 'password' => 'pass123456']);
[$s, $j] = req('member', 'GET', '/' . $slug . '/api/admin/ai');
check('普通成员读不到 AI 配置', (int)($j['code'] ?? 0) === 403, $j['msg'] ?? '');
[$s, $j] = req('member', 'POST', '/' . $slug . '/api/admin/ai/save', ['mode' => 'platform']);
check('普通成员改不了 AI 配置', (int)($j['code'] ?? 0) === 403);

[$s, $j] = req('owner', 'POST', '/' . $slug . '/api/admin/ai/save', [
    'mode' => 'platform', 'auto_reply' => 1, 'reply_mode' => 'all', 'bot_alias' => '小助手',
    'cooldown_seconds' => 60, 'hourly_limit' => 20, 'daily_token_limit' => 100000,
    'chat_probability' => 100, 'proactive_enabled' => 0,
    'proactive_interval_minutes' => 10, 'proactive_daily_limit' => 2,
    'proactive_start_hour' => 0, 'proactive_end_hour' => 24,
]);
check('队长保存平台模式成功', $s === 200 && (int)($j['code'] ?? -1) === 0, $j['msg'] ?? '');
[$s, $j] = req('owner', 'POST', '/' . $slug . '/api/admin/ai/save', [
    'mode' => 'own', 'own_base_url' => '', 'own_api_key' => '', 'own_model' => '',
]);
check('自有模型缺项被拒', (int)($j['code'] ?? 0) !== 0, $j['msg'] ?? '');

// ============================================================
// 3. @ 指令与 AI
// ============================================================
echo "\n== 3. @ 消息：指令保留 / 未知转 AI ==\n";
$n0 = mock_count($botKey);
at_event($botKey, '帮助');
wait_text($botKey, '指令菜单');
check('@帮助 仍返回指令菜单', mb_strpos(last_mock_text($botKey), '指令菜单') !== false);
check('指令不产生 AI 用量', db_count('ai_usage_logs', 'tenant_id=?', [$tid]) === 0);

$used0 = (int)db_val("SELECT token_used FROM tenant_ai_configs WHERE tenant_id=?", [$tid]);
at_event($botKey, '你好呀，今天怎么样');
$t = wait_text($botKey, '收到：你好呀');
wait_done($tid);
check('未知指令转 AI 且回复送达', mb_strpos($t, '收到：你好呀') !== false, $t);
check('平台模型产生用量流水', db_count('ai_usage_logs', 'tenant_id=? AND source="platform" AND status="ok"', [$tid]) >= 1);
check('平台 Token 已扣减', (int)db_val("SELECT token_used FROM tenant_ai_configs WHERE tenant_id=?", [$tid]) > $used0);
check('短期上下文写入 user+assistant', db_count('bot_chat_messages', 'tenant_id=?', [$tid]) >= 2);
check('成功回复后任务 done', db_count('bot_ai_jobs', 'tenant_id=? AND status="done"', [$tid]) >= 1);

$usedBeforeFail = (int)db_val("SELECT token_used FROM tenant_ai_configs WHERE tenant_id=?", [$tid]);
at_event($botKey, '__TEST_FAIL__');
$t = wait_text($botKey, '没连上模型');
wait_done($tid);
check('上游失败有兜底话术', mb_strpos($t, '没连上模型') !== false, $t);
check('失败任务落 failed', db_count('bot_ai_jobs', 'tenant_id=? AND status="failed"', [$tid]) >= 1);
check('失败不扣平台 Token', (int)db_val("SELECT token_used FROM tenant_ai_configs WHERE tenant_id=?", [$tid]) === $usedBeforeFail);

// ============================================================
// 4. 全量群消息自动接话
// ============================================================
echo "\n== 4. 全自动接话 ==\n";
// 先关冷却：上一条 @ 成功回复已经写过 60 秒冷却，不关会误判为「自动接话失效」
$saveAuto = function (int $cooldown) use ($slug): array {
    return req('owner', 'POST', '/' . $slug . '/api/admin/ai/save', [
        'mode' => 'platform', 'auto_reply' => 1, 'reply_mode' => 'all', 'bot_alias' => '小助手',
        'cooldown_seconds' => $cooldown, 'hourly_limit' => 20, 'daily_token_limit' => 100000,
        'chat_probability' => 100, 'proactive_enabled' => 0,
        'proactive_interval_minutes' => 10, 'proactive_daily_limit' => 2,
        'proactive_start_hour' => 0, 'proactive_end_hour' => 24,
    ]);
};
$saveAuto(0);
full_event($botKey, '今晚训练赛几点开始呢？');
$t = wait_text($botKey, '收到：今晚训练赛');
wait_done($tid);
check('全量消息命中问句自动接话', mb_strpos($t, '收到：今晚训练赛') !== false, $t);
check('全量任务类型记为 auto', db_count('bot_ai_jobs', 'tenant_id=? AND kind="auto"', [$tid]) >= 1);

$saveAuto(60);
full_event($botKey, '今晚有人一起打吗？');
wait_text($botKey, '收到：今晚有人');
wait_done($tid);
$n2 = mock_count($botKey);
full_event($botKey, '今晚再来一把吗？');
usleep(800000);
check('冷却期内第二条不接', mock_count($botKey) === $n2);

$saveAuto(0);
$n3 = mock_count($botKey);
full_event($botKey, '地图');
usleep(800000);
check('指令式群消息不抢答', mock_count($botKey) === $n3);

$n4 = mock_count($botKey);
full_event($botKey, '今晚八点集合');
$t = wait_text($botKey, '收到：今晚八点');
wait_done($tid);
check('reply_mode=all + 概率 100 时普通陈述也接话', mb_strpos($t, '收到：今晚八点') !== false, $t);

// ============================================================
// 5. 出站清洗
// ============================================================
echo "\n== 5. 出站清洗 ==\n";
at_event($botKey, '__TEST_LONG__');
$t = wait_text($botKey, '链接已省略');
wait_done($tid);
check('超长回复被截断', mb_strlen($t) <= 181, 'len=' . mb_strlen($t));
check('回复里没有 @提及', mb_strpos($t, '<@') === false);
check('回复里没有链接', mb_strpos($t, 'http') === false);

// ============================================================
// 6. 限额 / 自有模型 / 平台清零
// ============================================================
echo "\n== 6. 限额与自有模型 ==\n";
req('owner', 'POST', '/' . $slug . '/api/admin/ai/save', [
    'mode' => 'platform', 'auto_reply' => 0, 'reply_mode' => 'at', 'bot_alias' => '',
    'cooldown_seconds' => 0, 'hourly_limit' => 0, 'daily_token_limit' => 1,
    'chat_probability' => 0, 'proactive_enabled' => 0,
    'proactive_interval_minutes' => 10, 'proactive_daily_limit' => 2,
    'proactive_start_hour' => 0, 'proactive_end_hour' => 24,
]);
// 先把今日用量归零：前面的用例已经用过不少 Token，否则这里第一条就会被上限挡住
db()->prepare("UPDATE tenant_ai_configs SET daily_used=0, daily_date=? WHERE tenant_id=?")
    ->execute([date('Y-m-d'), $tid]);
at_event($botKey, '额度测试一');
wait_done($tid);
$n5 = mock_count($botKey);
at_event($botKey, '额度测试二');
$t = wait_text($botKey, '额度用完');
check('每日 Token 上限触发固定话术', mb_strpos($t, '额度用完') !== false, $t);

// 恢复每日上限；清零平台余额
req('owner', 'POST', '/' . $slug . '/api/admin/ai/save', [
    'mode' => 'platform', 'auto_reply' => 0, 'reply_mode' => 'at', 'bot_alias' => '',
    'cooldown_seconds' => 0, 'hourly_limit' => 0, 'daily_token_limit' => 100000,
    'chat_probability' => 0, 'proactive_enabled' => 0,
    'proactive_interval_minutes' => 10, 'proactive_daily_limit' => 2,
    'proactive_start_hour' => 0, 'proactive_end_hour' => 24,
]);
[$s, $j] = req('psuper', 'POST', '/api/platform/admin/ai/tenants/' . $tid . '/reset', ['note' => '测试清零']);
check('平台余额清零成功', $s === 200 && (int)($j['data']['balance'] ?? -1) === 0, $j['msg'] ?? '');
at_event($botKey, '清零后再聊');
$t = wait_text($botKey, '额度已经用完');
check('平台余额为 0 时不能使用首选模型', mb_strpos($t, '额度已经用完') !== false, $t);

// 自有模型：不扣平台 Token
$usedBeforeOwn = (int)db_val("SELECT token_used FROM tenant_ai_configs WHERE tenant_id=?", [$tid]);
[$s, $j] = req('owner', 'POST', '/' . $slug . '/api/admin/ai/test', ['own_base_url' => 'mock', 'own_api_key' => 'own-key-1234', 'own_model' => 'own-model']);
check('自有模型测试连接成功', $s === 200 && (int)($j['code'] ?? -1) === 0, $j['msg'] ?? '');
[$s, $j] = req('owner', 'POST', '/' . $slug . '/api/admin/ai/save', [
    'mode' => 'own', 'own_base_url' => 'mock', 'own_api_key' => 'own-key-1234', 'own_model' => 'own-model',
    'auto_reply' => 0, 'reply_mode' => 'at', 'bot_alias' => '', 'cooldown_seconds' => 0,
    'hourly_limit' => 0, 'daily_token_limit' => 100000, 'chat_probability' => 0,
    'proactive_enabled' => 0, 'proactive_interval_minutes' => 10, 'proactive_daily_limit' => 2,
    'proactive_start_hour' => 0, 'proactive_end_hour' => 24,
]);
check('切到自有模型成功', $s === 200 && (int)($j['code'] ?? -1) === 0, $j['msg'] ?? '');
at_event($botKey, '自有模型你好');
$t = wait_text($botKey, '收到：自有模型你好');
wait_done($tid);
check('自有模型能正常回复', mb_strpos($t, '收到：自有模型你好') !== false, $t);
check('自有模型不扣平台 Token', (int)db_val("SELECT token_used FROM tenant_ai_configs WHERE tenant_id=?", [$tid]) === $usedBeforeOwn);
check('自有模型用量 source=own', db_count('ai_usage_logs', 'tenant_id=? AND source="own" AND status="ok"', [$tid]) >= 1);
[$s, $j, $raw] = req('owner', 'GET', '/' . $slug . '/api/admin/ai');
check('自有密钥只回显打码版', strpos($raw, 'own-key-1234') === false && strpos((string)($j['data']['own']['api_key_mask'] ?? ''), '1234') !== false);

// 平台关闭首选模型不应连累自有模型
db()->exec("UPDATE settings SET value='0' WHERE tenant_id=0 AND key='ai_global_enabled'");
at_event($botKey, '平台关了自有还能聊吗');
$t = wait_text($botKey, '收到：平台关了自有还能聊吗');
wait_done($tid);
check('平台首选模型关闭时自有模型仍可用', mb_strpos($t, '收到：平台关了自有还能聊吗') !== false, $t);
db()->exec("UPDATE settings SET value='1' WHERE tenant_id=0 AND key='ai_global_enabled'");

// ============================================================
// 7. 主动发言
// ============================================================
echo "\n== 7. 主动发言 ==\n";
req('psuper', 'POST', '/api/platform/admin/ai/tenants/' . $tid . '/grant', ['amount' => 10000]);
req('owner', 'POST', '/' . $slug . '/api/admin/ai/save', [
    'mode' => 'platform', 'auto_reply' => 0, 'reply_mode' => 'at', 'bot_alias' => '',
    'cooldown_seconds' => 0, 'hourly_limit' => 0, 'daily_token_limit' => 100000,
    'chat_probability' => 0, 'proactive_enabled' => 1,
    'proactive_interval_minutes' => 10, 'proactive_daily_limit' => 2,
    'proactive_start_hour' => 0, 'proactive_end_hour' => 24,
]);
$before = mock_count($botKey);
[$s, $j] = req('owner', 'POST', '/' . $slug . '/api/admin/ai/proactive-test');
check('手动试发主动发言成功', $s === 200 && (int)($j['code'] ?? -1) === 0, $j['msg'] ?? '');
check('主动发言已送达', mock_count($botKey) > $before);
check('主动发言用量 kind=proactive', db_count('ai_usage_logs', 'tenant_id=? AND kind="proactive"', [$tid]) >= 1);
check('今日主动条数 +1', (int)db_val("SELECT proactive_sent_today FROM tenant_ai_configs WHERE tenant_id=?", [$tid]) === 1);

[$s, $j] = req('owner', 'POST', '/' . $slug . '/api/admin/ai/proactive-test');
check('主动发言每日上限内第二次成功', (int)($j['code'] ?? -1) === 0, $j['msg'] ?? '');
[$s, $j] = req('owner', 'POST', '/' . $slug . '/api/admin/ai/proactive-test');
check('超过每日上限被拒', (int)($j['code'] ?? 0) !== 0, $j['msg'] ?? '');

// ============================================================
// 8. 平台停用 / 未绑定群 / 权限
// ============================================================
echo "\n== 8. 停用与边界 ==\n";
// 上面第 7 节切回平台模式时没有带自有模型字段，这里确认没有被清空
[$s, $j] = req('owner', 'GET', '/' . $slug . '/api/admin/ai');
check('切回平台模式不清空自有模型配置', (string)($j['data']['own']['base_url'] ?? '') === 'mock'
    && (string)($j['data']['own']['model'] ?? '') === 'own-model' && !empty($j['data']['own']['has_key']),
    json_encode($j['data']['own'] ?? []));

[$s, $j] = req('psuper', 'POST', '/api/platform/admin/ai/tenants/' . $tid . '/disable', ['disabled' => 1]);
check('平台停用成功', $s === 200 && (int)($j['code'] ?? -1) === 0, $j['msg'] ?? '');
$n = mock_count($botKey);
at_event($botKey, '停用后还能聊吗');
$t = wait_text($botKey, 'AI 聊天没接通');
check('平台停用后把真实原因说出来（不再只说「无法识别」）',
    mb_strpos($t, 'AI 聊天没接通') !== false && mb_strpos($t, '平台已停用') !== false, $t);
check('平台停用后不产生 AI 任务', db_count('bot_ai_jobs', 'tenant_id=? AND content LIKE "%停用后还能聊吗%"', [$tid]) === 0);
req('psuper', 'POST', '/api/platform/admin/ai/tenants/' . $tid . '/disable', ['disabled' => 0]);

$n = mock_count($botKey);
at_event($botKey, '你好', 'OGROUP_UNBOUND', 'OMEM_X');
$t = wait_text($botKey, '尚未绑定');
check('未绑定群只给绑定提示', mb_strpos($t, '尚未绑定') !== false, $t);
check('未绑定群不产生 AI 任务', db_count('bot_ai_jobs', 'group_openid=?', ['OGROUP_UNBOUND']) === 0);

login_platform('pnormal', 'ainormal', 'normal123456');
[$s, $j] = req('pnormal', 'GET', '/api/platform/admin/ai');
check('平台普通用户读不到 AI 配置', (int)($j['code'] ?? 0) === 403);

// 带 author.bot=true 的回声不触发 AI
$nBot = mock_count($botKey);
$payload = bot_payload('GROUP_AT_MESSAGE_CREATE', '<@!BOT> 机器人回声', 'EVTBOT' . bin2hex(random_bytes(3)), 'OGROUP_AI', 'OMEM_AI', true);
bot_send($botKey, $payload);
usleep(500000);
check('机器人自己的消息不回复也不入队', mock_count($botKey) === $nBot && db_count('bot_ai_jobs', 'content LIKE "%机器人回声%"') === 0);

[$s, $j] = req('__nokey', 'POST', '/api/open/ai/tick');
check('开放 tick 缺 Key 返回 401', $s === 401, 'HTTP ' . $s);

// ============================================================
// 10. 人设库 / 表情包库 / 模型名（v5.36.29）
// ============================================================
echo "\n== 9. 人设库 / 表情包库 / 模型名 ==\n";

// 平台配置整份保存（少传字段会被默认值覆盖，不能只传一个开关）
$platSave = function (array $extra) use ($BASE) : array {
    return req('psuper', 'POST', '/api/platform/admin/ai/save', array_merge([
        'enabled' => 1, 'base_url' => 'mock', 'api_key' => '', 'model' => 'mock-chat',
        'max_tokens' => 160, 'temperature' => 0.8,
        'prompt' => '你是「{team}」的「{alias}」。', 'proactive_prompt' => '说一句轻松的。',
        'allow_links' => 0, 'max_proactive_per_day' => 2, 'sticker_enabled' => 1,
    ], $extra));
};
$cfgSave = function (array $extra) : array {
    global $slug;
    return req('owner', 'POST', '/' . $slug . '/api/admin/ai/save', array_merge([
        'mode' => 'platform', 'auto_reply' => 1, 'reply_mode' => 'all', 'bot_alias' => '小助手',
        'cooldown_seconds' => 0, 'hourly_limit' => 100, 'daily_token_limit' => 100000,
        'chat_probability' => 100, 'sticker_probability' => 0, 'prompt_preset_id' => 0,
        'proactive_enabled' => 0, 'proactive_interval_minutes' => 10,
        'proactive_daily_limit' => 2, 'proactive_start_hour' => 0, 'proactive_end_hour' => 24,
    ], $extra));
};

// ---- 10.1 模型名自动获取 ----
[$s, $j] = req('psuper', 'POST', '/api/platform/admin/ai/models', ['base_url' => 'mock', 'api_key' => 'sk-test-abcd1234']);
check('平台：填 Base URL 就能列出模型名', $s === 200 && (int)($j['code'] ?? -1) === 0
    && in_array('mock-chat', (array)($j['data']['models'] ?? []), true), $j['msg'] ?? '');
[$s, $j] = req('owner', 'POST', '/' . $slug . '/api/admin/ai/models', ['own_base_url' => 'mock', 'own_api_key' => 'own-key']);
check('队长：自有模型也能列出模型名', $s === 200 && (int)($j['code'] ?? -1) === 0
    && in_array('mock-chat', (array)($j['data']['models'] ?? []), true), $j['msg'] ?? '');
[$s, $j] = req('psuper', 'POST', '/api/platform/admin/ai/models', ['base_url' => 'http://127.0.0.1/v1']);
check('模型列表：非 https 地址被拒', (int)($j['code'] ?? 0) !== 0);
[$s, $j] = req('member', 'POST', '/' . $slug . '/api/admin/ai/models', ['own_base_url' => 'mock']);
check('普通成员拿不到模型列表', (int)($j['code'] ?? 0) === 403);

// ---- 10.2 人设库 ----
[$s, $j] = req('psuper', 'POST', '/api/platform/admin/ai/prompts/save', [
    'id' => 0, 'name' => '沙雕网友', 'body' => '你是「{team}」的「{alias}」，说话很沙雕，爱用梗。',
    'note' => '爱开玩笑', 'enabled' => 1, 'sort' => 1,
]);
check('平台新增人设成功', $s === 200 && (int)($j['code'] ?? -1) === 0, $j['msg'] ?? '');
$presetId = (int)($j['data']['id'] ?? 0);
check('人设已落库', $presetId > 0 && db_count('ai_prompt_presets', 'id=?', [$presetId]) === 1);
[$s, $j] = req('psuper', 'POST', '/api/platform/admin/ai/prompts/save', [
    'id' => 0, 'name' => '沙雕网友', 'body' => '重复的', 'enabled' => 1,
]);
check('人设重名被拒', (int)($j['code'] ?? 0) !== 0, $j['msg'] ?? '');
[$s, $j] = req('psuper', 'POST', '/api/platform/admin/ai/prompts/save', ['id' => 0, 'name' => '', 'body' => 'x']);
check('人设名称为空被拒', (int)($j['code'] ?? 0) !== 0);
[$s, $j] = req('pnormal', 'POST', '/api/platform/admin/ai/prompts/save', [
    'id' => 0, 'name' => '越权', 'body' => 'x',
]);
check('平台普通用户改不了人设', (int)($j['code'] ?? 0) === 403);

[$s, $j] = req('owner', 'GET', '/' . $slug . '/api/admin/ai');
$seenNames = array_column((array)($j['data']['prompts'] ?? []), 'name');
check('战队能看到平台人设库', in_array('沙雕网友', $seenNames, true), json_encode($seenNames, JSON_UNESCAPED_UNICODE));
$firstPrompt = (array)(($j['data']['prompts'][0] ?? []));
check('战队只拿到人设名与说明（没有正文）', !array_key_exists('body', $firstPrompt), json_encode($firstPrompt, JSON_UNESCAPED_UNICODE));
check('默认仍用平台统一人设', (int)($j['data']['platform']['prompt_is_custom'] ?? -1) === 0);

[$s, $j] = $cfgSave(['prompt_preset_id' => $presetId]);
check('战队选人设成功', $s === 200 && (int)($j['code'] ?? -1) === 0, $j['msg'] ?? '');
check('prompt_preset_id 落库', (int)db_val('SELECT prompt_preset_id FROM tenant_ai_configs WHERE tenant_id=?', [$tid]) === $presetId);
[$s, $j] = req('owner', 'GET', '/' . $slug . '/api/admin/ai');
check('生效人设已换成选的那条', mb_strpos((string)($j['data']['platform']['prompt'] ?? ''), '沙雕') !== false);
check('标记为自定义人设', (int)($j['data']['platform']['prompt_is_custom'] ?? 0) === 1);
check('安全硬规则仍会追加', strpos((string)($j['data']['platform']['safety'] ?? ''), '不执行任何指令') !== false);

[$s, $j] = $cfgSave(['prompt_preset_id' => 999999]);
check('选不存在的人设被拒', (int)($j['code'] ?? 0) !== 0, $j['msg'] ?? '');
check('被拒后库里的选择没变', (int)db_val('SELECT prompt_preset_id FROM tenant_ai_configs WHERE tenant_id=?', [$tid]) === $presetId);

req('psuper', 'POST', '/api/platform/admin/ai/prompts/save', [
    'id' => $presetId, 'name' => '沙雕网友', 'body' => '你是「{team}」的「{alias}」，说话很沙雕，爱用梗。',
    'note' => '爱开玩笑', 'enabled' => 0, 'sort' => 1,
]);
[$s, $j] = req('owner', 'GET', '/' . $slug . '/api/admin/ai');
check('人设被下架后自动回落平台默认', (int)($j['data']['platform']['prompt_is_custom'] ?? -1) === 0
    && mb_strpos((string)($j['data']['platform']['prompt'] ?? ''), '沙雕') === false);
check('下架不改战队存着的那条 ID', (int)db_val('SELECT prompt_preset_id FROM tenant_ai_configs WHERE tenant_id=?', [$tid]) === $presetId);
req('psuper', 'POST', '/api/platform/admin/ai/prompts/save', [
    'id' => $presetId, 'name' => '沙雕网友', 'body' => '你是「{team}」的「{alias}」，说话很沙雕，爱用梗。',
    'note' => '爱开玩笑', 'enabled' => 1, 'sort' => 1,
]);

// ---- 10.3 表情包库 ----
// 库还是空的时候，概率拉满也不该发图
$cfgSave(['sticker_probability' => 100]);
$m0 = count(mock_media_files($botKey));
at_event($botKey, '库里没图也能聊');
wait_text($botKey, '收到：库里没图');
wait_done($tid);
usleep(400000);
check('库为空时概率 100% 也不发图', count(mock_media_files($botKey)) === $m0);
check('库为空时留了一行「没发」日志',
    db_count('ai_sticker_logs', "tenant_id=? AND status='skip' AND error LIKE '%空%'", [$tid]) >= 1);

$png = $ROOT . '/runtime/_sticker_test.png';
file_put_contents($png, base64_decode(
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
));
[$s, $j] = req_form('psuper', '/api/platform/admin/ai/stickers/upload',
    ['name' => '点赞', 'note' => '夸人时用'], ['image' => $png]);
check('表情包上传成功', $s === 200 && (int)($j['code'] ?? -1) === 0, $j['msg'] ?? '');
$stickerRows = (array)($j['data']['stickers'] ?? []);
$sticker = (array)($stickerRows[0] ?? []);
$sPath = (string)($sticker['path'] ?? '');
$sId = (int)($sticker['id'] ?? 0);
check('表情包已入库', $sId > 0 && db_count('ai_stickers', 'name=?', ['点赞']) === 1);
check('表情包文件真的落盘', $sPath !== '' && is_file($ROOT . '/public' . $sPath), $sPath);
check('表情包路径只给站内相对路径', strpos($sPath, '/storage/uploads/stickers/') === 0, $sPath);

[$s, $j] = req_form('psuper', '/api/platform/admin/ai/stickers/upload',
    ['name' => '点赞', 'note' => '重复'], ['image' => $png]);
check('表情包重名被拒', (int)($j['code'] ?? 0) !== 0, $j['msg'] ?? '');
$bad = $ROOT . '/runtime/_sticker_bad.png';
file_put_contents($bad, 'not an image');
[$s, $j] = req_form('psuper', '/api/platform/admin/ai/stickers/upload',
    ['name' => '假图'], ['image' => $bad]);
check('不是真图片的上传被拒', (int)($j['code'] ?? 0) !== 0, $j['msg'] ?? '');
@unlink($bad);
[$s, $j] = req_form('pnormal', '/api/platform/admin/ai/stickers/upload',
    ['name' => '越权'], ['image' => $png]);
check('平台普通用户不能传表情包', (int)($j['code'] ?? 0) === 403);
[$s, $j] = req('member', 'POST', '/' . $slug . '/api/admin/ai/save', ['mode' => 'platform']);
check('普通成员改不了表情包概率', (int)($j['code'] ?? 0) === 403);

// 概率 100 → 文字后追发一张
$cfgSave(['sticker_probability' => 100]);
$m0 = count(mock_media_files($botKey));
at_event($botKey, '夸我一句');
$t = wait_text($botKey, '收到：夸我一句');
wait_done($tid);
usleep(600000);
$media = mock_media_files($botKey);
check('命中概率后追发了表情包', count($media) > $m0, count($media) . ' vs ' . $m0);
check('发出去的就是库里那张图', count($media) > 0 && end($media) === basename($sPath), json_encode($media));
check('表情包发送计数 +1', (int)db_val('SELECT sent_count FROM ai_stickers WHERE id=?', [$sId]) === 1);
check('回复正文里没有 [图:N] 标记', mb_strpos($t, '[图') === false, $t);

// v5.36.30：发送日志
$logRow = db_one("SELECT * FROM ai_sticker_logs WHERE tenant_id=? AND status='ok' ORDER BY id DESC LIMIT 1", [$tid]);
check('成功发图记了日志', is_array($logRow) && (string)$logRow['sticker_name'] === '点赞', json_encode($logRow, JSON_UNESCAPED_UNICODE));
check('日志记了触发方式与谁挑的',
    is_array($logRow) && (string)$logRow['trigger_kind'] === 'at' && (string)$logRow['pick_mode'] === 'ai');
[$s, $j, $rawLog] = req('owner', 'GET', '/' . $slug . '/api/admin/ai');
check('后台能读到表情包日志', count((array)($j['data']['sticker_log'] ?? [])) >= 1);
check('日志里群 openid 已打码', strpos($rawLog, 'OGROUP_AI') === false);

[$s, $j] = req('owner', 'POST', '/' . $slug . '/api/admin/ai/sticker-test', []);
check('立刻发一张成功', $s === 200 && (int)($j['code'] ?? -1) === 0, $j['msg'] ?? '');
check('手动测试也记了日志', db_count('ai_sticker_logs', "tenant_id=? AND trigger_kind='test' AND status='ok'", [$tid]) === 1);
[$s, $j] = req('member', 'POST', '/' . $slug . '/api/admin/ai/sticker-test', []);
check('普通成员不能手动发图', (int)($j['code'] ?? 0) === 403);
[$s, $j] = req('member', 'POST', '/' . $slug . '/api/admin/ai/sticker-logs/clear', []);
check('普通成员不能清空日志', (int)($j['code'] ?? 0) === 403);

// 概率 0 → 不再发
$cfgSave(['sticker_probability' => 0]);
$m0 = count(mock_media_files($botKey));
at_event($botKey, '再夸我一句');
wait_text($botKey, '收到：再夸我');
wait_done($tid);
usleep(400000);
check('概率 0 时只发文字', count(mock_media_files($botKey)) === $m0);

// 平台总闸关 → 概率 100 也不发
$cfgSave(['sticker_probability' => 100]);
$platSave(['sticker_enabled' => 0]);
$m0 = count(mock_media_files($botKey));
at_event($botKey, '总闸关掉还能发图吗');
wait_text($botKey, '收到：总闸关掉');
wait_done($tid);
usleep(400000);
check('平台总闸关掉后不发图', count(mock_media_files($botKey)) === $m0);
check('总闸关掉也留了「没发」日志',
    db_count('ai_sticker_logs', "tenant_id=? AND status='skip' AND error LIKE '%总闸%'", [$tid]) >= 1);
$platSave(['sticker_enabled' => 1]);

// 自检里能看到表情包状态（他排障就是用这一屏）
[$s, $j] = req('owner', 'GET', '/' . $slug . '/api/admin/bot/diagnose');
$aiBox = (array)($j['data']['ai'] ?? []);
check('自检带表情包信息', (int)($aiBox['sticker_probability'] ?? -1) === 100
    && (int)($aiBox['sticker_count'] ?? -1) === 1, json_encode($aiBox, JSON_UNESCAPED_UNICODE));
check('自检记录最近一次表情包结果', mb_strpos((string)($aiBox['sticker_last'] ?? ''), '点赞') !== false,
    (string)($aiBox['sticker_last'] ?? ''));
check('自检里带最近一条表情包日志', (string)($aiBox['sticker_log_last'] ?? '') !== '', (string)($aiBox['sticker_log_last'] ?? ''));

// 人设优先于平台默认：整条链路里真的用了选的那条
$cfgSave(['prompt_preset_id' => $presetId, 'sticker_probability' => 0]);
at_event($botKey, '你的人设是什么');
wait_text($botKey, '收到：你的人设');
wait_done($tid);
check('选了人设后仍能正常回复', true);

// ---- 10.4 删除：素材没了不能把机器人搞哑 ----
[$s, $j] = req('psuper', 'POST', '/api/platform/admin/ai/stickers/' . $sId . '/delete', []);
check('表情包删除成功', (int)($j['code'] ?? -1) === 0, $j['msg'] ?? '');
check('表情包文件一起删掉', !is_file($ROOT . '/public' . $sPath));
check('表情包记录已删', db_count('ai_stickers', 'id=?', [$sId]) === 0);

[$s, $j] = req('psuper', 'POST', '/api/platform/admin/ai/prompts/' . $presetId . '/delete', []);
check('人设删除成功', (int)($j['code'] ?? -1) === 0, $j['msg'] ?? '');
check('删人设后战队自动回落到平台默认', (int)db_val('SELECT prompt_preset_id FROM tenant_ai_configs WHERE tenant_id=?', [$tid]) === 0);
[$s, $j] = req('owner', 'GET', '/' . $slug . '/api/admin/ai');
check('删除后 AI 配置照样能读', (int)($j['code'] ?? -1) === 0);
$cfgSave(['prompt_preset_id' => 0, 'sticker_probability' => 0]);
@unlink($png);

// ---- 10.5 日志能一键清空 ----
$beforeLogs = db_count('ai_sticker_logs', 'tenant_id=?', [$tid]);
[$s, $j] = req('owner', 'POST', '/' . $slug . '/api/admin/ai/sticker-logs/clear', []);
check('清空表情包日志', (int)($j['code'] ?? -1) === 0 && (int)($j['data']['deleted'] ?? 0) === $beforeLogs,
    'before=' . $beforeLogs . ' resp=' . json_encode($j, JSON_UNESCAPED_UNICODE));
check('清空后一条不剩', db_count('ai_sticker_logs', 'tenant_id=?', [$tid]) === 0);
[$s, $j] = req('owner', 'GET', '/' . $slug . '/api/admin/ai');
check('清空后 AI 配置照样能读', (int)($j['code'] ?? -1) === 0 && count((array)($j['data']['sticker_log'] ?? [])) === 0);

// ============================================================
// 10. 收尾
// ============================================================
echo "\n== 10. 账目一致性 ==\n";
$cfg = db_one('SELECT token_granted, token_used, daily_used FROM tenant_ai_configs WHERE tenant_id=?', [$tid]);
check('余额不为负', (int)$cfg['token_granted'] - (int)$cfg['token_used'] >= 0, json_encode($cfg));
check('用量流水不含聊天正文字段', (int)db_val("SELECT COUNT(*) FROM pragma_table_info('ai_usage_logs') WHERE name='content'") === 0);
$jobKinds = db_val('SELECT COUNT(DISTINCT kind) FROM bot_ai_jobs WHERE tenant_id=?', [$tid]);
check('队列覆盖 at/auto/proactive 三类', (int)$jobKinds === 3, 'kinds=' . $jobKinds);

echo "\n============================================================\n";
echo '通过 ' . $pass . ' 项，失败 ' . $fail . " 项\n";
if ($fail) {
    echo "失败项：\n";
    foreach ($failures as $f) { echo '  - ' . $f . "\n"; }
}
exit($fail ? 1 : 0);
