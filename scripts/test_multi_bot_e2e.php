<?php
/**
 * 战队独立机器人功能 E2E 测试
 *
 * v5.28.0：平台公共机器人已下线——机器人只有各战队自己填的那一份凭据，
 * 连接测试与群指令面板也搬进了战队管理中心，平台侧只留敏感词与调用日志。
 *
 * 前置：测试服务器已启动（php -S 127.0.0.1:8899 -t public，.env 指向 sqlite）
 * 本脚本会：
 *   1. 重建 SQLite 测试库并直插一个已通过审核的战队 + 站长/成员账号
 *   2. 通过 HTTP（模拟真实前端调用）覆盖完整链路：
 *      配置保存 → op:13 Webhook 验签 → 群绑定 → 指令（带斜杠与不带斜杠等价）
 *      → 成员绑定 → 签到 → 新指令（战绩/成员/介绍）→ 测试连接与指令面板
 *      → 平台侧只剩敏感词与调用日志 → 删除配置后群内彻底静默
 *
 * 用法：php scripts/test_multi_bot_e2e.php
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

$ROOT = dirname(__DIR__);
$BASE = rtrim((string)(getenv('TEST_BASE') ?: 'http://127.0.0.1:8899'), '/');
$DB   = $ROOT . '/runtime/test.sqlite';
require $ROOT . '/vendor/autoload.php';

$pass = 0; $fail = 0; $failures = [];
function check(string $name, bool $ok, string $detail = ''): void {
    global $pass, $fail, $failures;
    if ($ok) { $pass++; echo "  [PASS] $name\n"; }
    else { $fail++; $failures[] = $name . ($detail !== '' ? " ($detail)" : ''); echo "  [FAIL] $name" . ($detail !== '' ? " —— $detail" : '') . "\n"; }
}

// ---------- HTTP 封装（含 Cookie 管理；按 name 去重，避免重复 cookie 打断会话） ----------
function http_req(string $method, string $url, ?array $body, array &$cookies): array {
    $opts = ['http' => [
        'method' => $method,
        'header' => "Content-Type: application/json\r\n",
        'ignore_errors' => true,
        'timeout' => 15,
    ]];
    if ($body !== null) {
        $opts['http']['content'] = json_encode($body, JSON_UNESCAPED_UNICODE);
    }
    if ($cookies) {
        $pairs = [];
        foreach ($cookies as $k => $v) { $pairs[] = $k . '=' . $v; }
        $opts['http']['header'] .= 'Cookie: ' . implode('; ', $pairs) . "\r\n";
    }
    $ctx = stream_context_create($opts);
    $raw = @file_get_contents($url, false, $ctx);
    // 收集 Set-Cookie：按 name 覆盖，确保同名 cookie 只保留最新值
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('/^Set-Cookie:\s*([^=]+)=([^;]*)/i', $h, $m)) {
            $cookies[$m[1]] = trim($m[2]);
        }
    }
    $status = 0;
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) { $status = (int)$m[1]; }
    }
    $json = json_decode((string)$raw, true);
    return [$status, is_array($json) ? $json : ['raw' => (string)$raw]];
}

$jarAdmin = []; $jarMember = []; $jarPlatform = [];
function admin_req(string $m, string $path, ?array $body = null): array {
    global $jarAdmin, $BASE; return http_req($m, $BASE . $path, $body, $jarAdmin);
}
function member_req(string $m, string $path, ?array $body = null): array {
    global $jarMember, $BASE; return http_req($m, $BASE . $path, $body, $jarMember);
}
function platform_req(string $m, string $path, ?array $body = null): array {
    global $jarPlatform, $BASE; return http_req($m, $BASE . $path, $body, $jarPlatform);
}

function db(): PDO {
    global $DB;
    return new PDO('sqlite:' . $DB);
}
function db_one(string $sql, array $p = []): ?array {
    $st = db()->prepare($sql); $st->execute($p); $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r === false ? null : $r;
}

// ---------- ed25519 签名（与 BotCallback::sign 同算法，用于交叉验证） ----------
function bot_sign(string $secret, string $message): string {
    if (!function_exists('sodium_crypto_sign_seed_keypair')) return '';
    $seed = $secret;
    while (strlen($seed) < SODIUM_CRYPTO_SIGN_SEEDBYTES) { $seed = str_repeat($seed, 2); }
    $seed = substr($seed, 0, SODIUM_CRYPTO_SIGN_SEEDBYTES);
    $kp = sodium_crypto_sign_seed_keypair($seed);
    return bin2hex(sodium_crypto_sign_detached($message, sodium_crypto_sign_secretkey($kp)));
}

// ---------- 模拟 QQ 平台推送 ----------
function bot_event(string $key, string $content, string $eventId, string $group = 'OGROUP1', string $member = 'OMEM1'): array {
    global $BASE;
    // eventId 唯一化：规避服务端 600s 事件去重 / 60s 群限流对本轮测试的干扰（测试不验证去重）
    $eventId .= '-' . bin2hex(random_bytes(4));
    $payload = [
        'op' => 0,
        't'  => 'GROUP_AT_MESSAGE_CREATE',
        'd'  => ['id' => $eventId, 'group_openid' => $group, 'author' => ['member_openid' => $member], 'content' => '<@!BOT> ' . $content],
    ];
    $jar = []; // 回调无会话
    return http_req('POST', $BASE . '/api/bot/callback/' . $key, $payload, $jar);
}
function bot_op13(string $key, string $plain = 'plain-token-abc', string $ts = '1786000000'): array {
    global $BASE;
    $jar = [];
    return http_req('POST', $BASE . '/api/bot/callback/' . $key,
        ['op' => 13, 'd' => ['plain_token' => $plain, 'event_ts' => $ts]], $jar);
}

/** 取本队机器人最后一次 mock 发送的正文（AppID=mock 时不外呼，只落运行日志） */
function last_mock_text(string $key): string {
    global $ROOT;
    $file = $ROOT . '/runtime/bot/bot_' . date('Ymd') . '.log';
    if (!is_file($file)) {
        return '';
    }
    $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    $text  = '';
    foreach (array_slice($lines, -400) as $line) {
        $e = json_decode($line, true);
        if (is_array($e) && ($e['type'] ?? '') === 'mock_send' && ($e['key'] ?? '') === $key) {
            $text = (string)($e['text'] ?? '');
        }
    }
    return $text;
}

// ============================================================
// 0. 重建测试库 + 种子数据
// ============================================================
echo "== 0. 重建测试库 ==\n";
// 清空文件缓存与会话：避免上一轮遗留的「事件去重 600s / 群限流 60s」干扰本次（eventId 会被复用）。
// 缓存是按 hash 分子目录落的，glob('runtime/cache/*') 只会拿到目录、unlink 删不掉，必须递归清。
foreach ([$ROOT . '/runtime/cache', $ROOT . '/runtime/session'] as $cacheDir) {
    if (!is_dir($cacheDir)) { continue; }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($cacheDir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) {
        $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
}
// mock 发送的正文落在运行日志里，删掉旧日志才好逐条比对
foreach (glob($ROOT . '/runtime/bot/bot_*.log') ?: [] as $f) { @unlink($f); }
@unlink($DB);
echo shell_exec('"' . PHP_BINARY . '" "' . $ROOT . '/scripts/dev_init_sqlite.php" "' . $DB . '" 2>&1');
$pdo = db();
$now = date('Y-m-d H:i:s');
$pdo->exec("INSERT INTO tenants (applicant_id, slug, name, description, status, admin_username, admin_user_id, created_at, updated_at)
            VALUES (1, 'bttest', '机器人测试战队', '周末打撞车的老牌战队', 'approved', 'btowner', NULL, '$now', '$now')");
$tid = (int)$pdo->lastInsertId();
$hash = password_hash('owner123456', PASSWORD_DEFAULT);
$pdo->exec("INSERT INTO users (tenant_id, username, password, nickname, role, status, created_at, updated_at)
            VALUES ($tid, 'btowner', '$hash', '测试站长', 'admin', 'approved', '$now', '$now')");
$ownerUid = (int)$pdo->lastInsertId();
$hash2 = password_hash('member123456', PASSWORD_DEFAULT);
$pdo->exec("INSERT INTO users (tenant_id, username, password, nickname, role, status, created_at, updated_at)
            VALUES ($tid, 'btmember', '$hash2', '测试队员', 'user', 'approved', '$now', '$now')");
$memberUid = (int)$pdo->lastInsertId();
$pdo->exec("UPDATE tenants SET admin_user_id = $ownerUid WHERE id = $tid");
check('种子战队/站长/成员就位', $tid > 0 && $ownerUid > 0 && $memberUid > 0);

// 轮换地图：平台级 7 组合（网页应能看到整轮 7 天，机器人只带接下来 3 天）
$pdo->exec("INSERT INTO settings (tenant_id, `key`, `value`) VALUES (0, 'map_rotation_enabled', '1')");
$pdo->exec("INSERT INTO settings (tenant_id, `key`, `value`) VALUES (0, 'map_rotation_start', '" . date('Y-m-d') . "')");
$rotMapIds = [];
for ($d = 1; $d <= 7; $d++) {
    $pdo->exec("INSERT INTO rotation_days (tenant_id, name, created_at, updated_at) VALUES (0, '轮换第{$d}组', '$now', '$now')");
    $dayId = (int)$pdo->lastInsertId();
    // 第 2 组给 8 张，验证机器人单日地图名截断（6 张 + 等N张）
    $mapCount = $d === 2 ? 8 : 1;
    for ($k = 1; $k <= $mapCount; $k++) {
        $pdo->exec("INSERT INTO rotation_maps (tenant_id, name, image, sort, created_at, updated_at)
                    VALUES (0, '第{$d}组地图{$k}', '', 0, '$now', '$now')");
        $mid = (int)$pdo->lastInsertId();
        $rotMapIds[$d][] = $mid;
        $pdo->exec("INSERT INTO rotation_day_maps (tenant_id, day_id, map_id) VALUES (0, $dayId, $mid)");
    }
}
check('种子 7 组轮换地图就位', count($rotMapIds) === 7 && count($rotMapIds[2]) === 8);

// ============================================================
// 1. 路由与鉴权
// ============================================================
echo "\n== 1. 路由与鉴权 ==\n";
[$s, $j] = http_req('GET', $BASE . '/bttest/api/admin/bot/config', null, $jarAdmin);
check('GET /api/admin/bot/config 路由存在（未登录应 401 而非 404）', $s !== 404, "HTTP $s");

[$s, $j] = admin_req('POST', '/bttest/api/auth/login', ['username' => 'btowner', 'password' => 'owner123456']);
check('站长登录', $s === 200 && ($j['code'] ?? 1) === 0, $j['msg'] ?? '');

[$s, $j] = admin_req('GET', '/bttest/api/admin/bot');
check('机器人状态概览：本队还没配机器人 → bot_enabled=false', ($j['data']['bot_enabled'] ?? true) === false && ($j['data']['bound'] ?? true) === false);

[$s, $j] = admin_req('GET', '/bttest/api/admin/bot/config');
check('配置读取：初始无配置', ($j['data']['has_config'] ?? true) === false);

[$s, $j] = admin_req('POST', '/bttest/api/admin/bot/verify', ['code' => '123456']);
check('没有本队机器人时绑群被拒', ($j['code'] ?? 0) !== 0, $j['msg'] ?? '');

// v5.28.0：无密钥的旧公共回调地址彻底下线
$jarRoot = [];
[$s, $j] = http_req('POST', $BASE . '/api/bot/callback', ['op' => 0, 't' => 'GROUP_AT_MESSAGE_CREATE', 'd' => []], $jarRoot);
check('无密钥回调地址 /api/bot/callback 已下线（404）', $s === 404, "HTTP $s");

// ============================================================
// 2. 独立机器人配置（含接口环境）
// ============================================================
echo "\n== 2. 独立机器人配置 ==\n";
[$s, $j] = admin_req('POST', '/bttest/api/admin/bot/config', ['app_id' => 'mock', 'app_secret' => '', 'enabled' => 1]);
check('启用但缺 Secret 被拒', ($j['code'] ?? 0) !== 0, $j['msg'] ?? '');

// 先保存但【不启用】—— 模拟自然配置顺序（Bug 2 场景）
[$s, $j] = admin_req('POST', '/bttest/api/admin/bot/config', ['app_id' => 'mock', 'app_secret' => 'own-secret-abc123', 'enabled' => 0]);
check('保存凭据（未启用）', ($j['code'] ?? 1) === 0, $j['msg'] ?? '');

[$s, $j] = admin_req('GET', '/bttest/api/admin/bot/config');
$cfg = $j['data'] ?? [];
check('配置回读：has_config + secret_set + callback_url', ($cfg['has_config'] ?? false) === true && ($cfg['secret_set'] ?? false) === true && !empty($cfg['callback_url']));
check('Secret 不回显明文（无 app_secret 字段值）', !isset($cfg['app_secret']) || $cfg['app_secret'] !== 'own-secret-abc123');
check('配置里没有平台紧急停用字段（v5.28.0 界面不再用）', !array_key_exists('platform_disabled', $cfg), json_encode(array_keys($cfg)));
$key = (string)($cfg['callback_url'] ? basename($cfg['callback_url']) : '');
check('回调密钥格式（32位hex）', (bool)preg_match('/^[0-9a-f]{32}$/', $key), "key=$key");

// 接口环境跟着战队自己走（原来挂在平台机器人配置上）
[$s, $j] = admin_req('POST', '/bttest/api/admin/bot/config', ['app_id' => 'mock', 'app_secret' => '', 'enabled' => 0, 'env' => 'sandbox']);
[$s, $j] = admin_req('GET', '/bttest/api/admin/bot/config');
check('沙箱环境保存并回读', ($j['data']['env'] ?? '') === 'sandbox', json_encode($j['data'] ?? null));
[$s, $j] = admin_req('POST', '/bttest/api/admin/bot/config', ['app_id' => 'mock', 'app_secret' => '', 'enabled' => 0, 'env' => '别的值']);
[$s, $j] = admin_req('GET', '/bttest/api/admin/bot/config');
check('环境只认 prod/sandbox（乱填回落正式）', ($j['data']['env'] ?? '') === 'prod');

// ============================================================
// 3. op:13 Webhook 验签（未启用状态下也应可验证 —— Bug 2 修复）
// ============================================================
echo "\n== 3. op:13 Webhook 验签 ==\n";
[$s, $j] = bot_op13($key);
$expectSig = bot_sign('own-secret-abc123', '1786000000plain-token-abc');
check('未启用状态下 op:13 验证通过（HTTP 200）', $s === 200, "HTTP $s " . substr(json_encode($j), 0, 120));
check('op:13 签名与本地 ed25519 计算一致', ($j['signature'] ?? '') === $expectSig && ($j['plain_token'] ?? '') === 'plain-token-abc');
// 错误密钥 → 500
[$s, $j] = bot_op13(str_repeat('f', 32));
check('未知回调密钥 op:13 → 500', $s === 500);

// 正式启用
[$s, $j] = admin_req('POST', '/bttest/api/admin/bot/config', ['app_id' => 'mock', 'app_secret' => '', 'enabled' => 1]);
check('启用（Secret 留空沿用原值）', ($j['code'] ?? 1) === 0, $j['msg'] ?? '');
$row = db_one('SELECT app_secret FROM bot_tenant_bots WHERE tenant_id = ?', [$tid]);
check('Secret 留空保存未丢失原值', ($row['app_secret'] ?? '') === 'own-secret-abc123');
[$s, $j] = bot_op13($key);
check('启用后 op:13 仍可验证', $s === 200);

// 事件推送同样只认已登记的密钥
[$s, $j] = bot_event(str_repeat('a', 32), '帮助', 'evt-unknown-key');
check('未知密钥的事件推送被拒（403）', $s === 403, "HTTP $s");

// ============================================================
// 4. 群绑定（不再依赖平台机器人，也不再需要平台授权）
// ============================================================
echo "\n== 4. 群绑定 ==\n";
[$s, $j] = bot_event($key, '绑定', 'evt-bind-1');
check('未绑定群发「绑定」产生群验证码', $s === 200 && ($j['op'] ?? 0) === 12);   // 官方回包 op=12
$codeRow = db_one('SELECT code FROM bot_group_codes WHERE group_openid = ? AND used_at IS NULL ORDER BY id DESC', ['OGROUP1']);
$groupCode = (string)($codeRow['code'] ?? '');
check('群验证码已落库（6位数字）', (bool)preg_match('/^\d{6}$/', $groupCode), "code=$groupCode");

[$s, $j] = admin_req('POST', '/bttest/api/admin/bot/verify', ['code' => $groupCode]);
check('回填验证码完成群绑定（无需平台侧任何开关）', ($j['code'] ?? 1) === 0, $j['msg'] ?? '');
$bind = db_one('SELECT * FROM bot_group_bindings WHERE tenant_id = ?', [$tid]);
check('群绑定落库', $bind !== null && $bind['group_openid'] === 'OGROUP1');
$botRow = db_one('SELECT panel_id FROM bot_tenant_bots WHERE tenant_id = ?', [$tid]);
check('绑群时自动建本队指令面板（mock）', ($botRow['panel_id'] ?? '') === 'mock-panel-own', "panel={$botRow['panel_id']}");

// 重复绑定被拒
[$s, $j] = admin_req('POST', '/bttest/api/admin/bot/verify', ['code' => $groupCode]);
check('重复绑群被拒', ($j['code'] ?? 0) !== 0);

// ============================================================
// 5. 群内指令：带斜杠与不带斜杠等价（本轮修的那个 bug）
// ============================================================
echo "\n== 5. 群内指令与斜杠写法 ==\n";
[$s, $j] = bot_event($key, '帮助', 'evt-help-1');
$log = db_one('SELECT * FROM bot_call_logs WHERE tenant_id = ? ORDER BY id DESC', [$tid]);
check('裸词「帮助」识别为帮助指令', $s === 200 && ($log['command'] ?? '') === '帮助');
check('「帮助」回复里含指令菜单', str_contains(last_mock_text($key), '指令菜单'), substr(last_mock_text($key), 0, 40));

[$s, $j] = bot_event($key, '/帮助', 'evt-help-slash');
$log = db_one('SELECT * FROM bot_call_logs WHERE tenant_id = ? ORDER BY id DESC', [$tid]);
check('「/帮助」同样识别为帮助指令', ($log['command'] ?? '') === '帮助', json_encode($log));
check('「/帮助」日志正文已剥掉斜杠', ($log['content'] ?? '') === '帮助', "content={$log['content']}");
check('「/帮助」真的回到菜单', str_contains(last_mock_text($key), '指令菜单'));

[$s, $j] = bot_event($key, '／地图', 'evt-map-slash');
$log = db_one('SELECT * FROM bot_call_logs WHERE tenant_id = ? ORDER BY id DESC', [$tid]);
check('全角斜杠「／地图」也算地图指令', ($log['command'] ?? '') === '地图', json_encode($log));

[$s, $j] = bot_event($key, '/绑定 000000', 'evt-bind-bad');
check('带斜杠的「/绑定 码」走同一条绑核销（错误码有回复）', $s === 200);
$log = db_one('SELECT * FROM bot_call_logs WHERE tenant_id = ? ORDER BY id DESC', [$tid]);
check('「/绑定 000000」日志正文为「绑定 000000」', ($log['content'] ?? '') === '绑定 000000', "content={$log['content']}");

[$s, $j] = bot_event($key, '/这玩意不存在', 'evt-unknown-cmd');
$log = db_one('SELECT * FROM bot_call_logs WHERE tenant_id = ? ORDER BY id DESC', [$tid]);
$unk = last_mock_text($key);
check('带斜杠的未知指令直接说没有这个指令并提示「/帮助」', ($log['command'] ?? '') === '(指令不存在)'
    && str_contains($unk, '没有这个指令') && str_contains($unk, '/帮助'), json_encode($log) . ' | ' . mb_substr($unk, 0, 60));

// ============================================================
// 5.1 轮换地图：网页能看整轮，机器人只看接下来 3 天
// ============================================================
echo "\n== 5.1 轮换地图预告 ==\n";
[$s, $j] = admin_req('GET', '/bttest/api/rotation');
$rot = $j['data'] ?? [];
check('网页轮换返回整轮 7 组（原来固定只给 5 天）',
    ($rot['cycle_size'] ?? 0) === 7 && count($rot['upcoming'] ?? []) === 7,
    json_encode(['cycle' => $rot['cycle_size'] ?? null, 'upcoming' => count($rot['upcoming'] ?? [])]));
check('预告项带日期（第一项 = 明天）',
    ($rot['upcoming'][0]['date'] ?? '') === date('Y-m-d', strtotime('+1 day')), $rot['upcoming'][0]['date'] ?? '');
check('预告按轮换顺序给出组合名', ($rot['upcoming'][0]['name'] ?? '') === '轮换第2组'
    && ($rot['upcoming'][6]['name'] ?? '') === '轮换第1组', json_encode(array_column($rot['upcoming'] ?? [], 'name')));

[$s, $j] = bot_event($key, '地图', 'evt-map-upcoming');
$txt = last_mock_text($key);
check('机器人「地图」含今日 + 接下来 3 天',
    str_contains($txt, '今日轮换') && str_contains($txt, '明天') && str_contains($txt, '后天')
    && str_contains($txt, '3天后') && str_contains($txt, '轮换第2组') && str_contains($txt, '轮换第4组'),
    mb_substr($txt, 0, 120));
check('机器人单日地图名截到 6 个并标「等8张」',
    str_contains($txt, '第2组地图6') && str_contains($txt, '等8张') && !str_contains($txt, '第2组地图7'),
    mb_substr($txt, 0, 160));
check('机器人不展示第 5 天及以后（不刷屏）',
    !str_contains($txt, '轮换第5组') && !str_contains($txt, '第5组地图1'), mb_substr($txt, 0, 160));

[$s, $j] = bot_event($key, '帮助', 'evt-help-rotation');
check('帮助文案写明地图含接下来 3 天', str_contains(last_mock_text($key), '接下来 3 天'));

// ============================================================
// 6. 成员绑定（个人中心取码 —— Bug 1 修复验证）
// ============================================================
echo "\n== 6. 成员绑定 ==\n";
[$s, $j] = member_req('POST', '/bttest/api/auth/login', ['username' => 'btmember', 'password' => 'member123456']);
check('成员登录', $s === 200 && ($j['code'] ?? 1) === 0, $j['msg'] ?? '');

[$s, $j] = member_req('GET', '/bttest/api/bot/status');
check('个人中心状态：本队独立机器人已启用 → bot_enabled=true', ($j['data']['bot_enabled'] ?? false) === true, json_encode($j['data'] ?? null));

[$s, $j] = member_req('POST', '/bttest/api/bot/bind-code');
$memberCode = (string)($j['data']['code'] ?? '');
check('成员取码成功', ($j['code'] ?? 1) === 0 && (bool)preg_match('/^\d{6}$/', $memberCode), $j['msg'] ?? '');

[$s, $j] = bot_event($key, '绑定 ' . $memberCode, 'evt-bind-m1', 'OGROUP1', 'OMEM1');
$ub = db_one('SELECT * FROM bot_user_bindings WHERE tenant_id = ?', [$tid]);
check('群内「绑定 六位码」完成身份关联', $ub !== null && (int)$ub['user_id'] === $memberUid && $ub['member_openid'] === 'OMEM1');

[$s, $j] = bot_event($key, '签到', 'evt-ck-1', 'OGROUP1', 'OMEM1');
$ck = db_one('SELECT * FROM checkins WHERE tenant_id = ? AND user_id = ?', [$tid, $memberUid]);
check('成员签到落库（独立机器人链路）', $ck !== null);

// ============================================================
// 7. 机器人指令：战绩 / 成员 / 介绍；论坛那两条（热帖·精选）要确认已经不再回话
// ============================================================
echo "\n== 7. 指令：战绩·成员·介绍（论坛那两条已随论坛下线） ==\n";
$pdo->exec("INSERT INTO player_stats (tenant_id, user_id, matches, win_rate, avg_score, kd, kda, kills,
                kills_per_match, score_per_match, revives, max_win_streak, win_rate_last10, created_at, updated_at)
             VALUES ($tid, $memberUid, 42, 61.50, 1.85, 2.40, 3.10, 310, 7.38, 128.00, 7, 5, 70.00, '$now', '$now')");
$pdo->exec("INSERT INTO player_stats (tenant_id, user_id, matches, win_rate, avg_score, kd, kda, kills,
                kills_per_match, score_per_match, revives, max_win_streak, win_rate_last10, created_at, updated_at)
             VALUES ($tid, $ownerUid, 88, 55.00, 2.60, 1.90, 2.20, 500, 5.68, 99.00, 3, 4, 50.00, '$now', '$now')");

[$s, $j] = bot_event($key, '/我的战绩', 'evt-mystats');
$txt = last_mock_text($key);
check('「我的战绩」回本人战绩（场次与胜率同源）', ($s === 200 && str_contains($txt, '我的战绩') && str_contains($txt, '42') && str_contains($txt, '61.5')), mb_substr($txt, 0, 60));

[$s, $j] = bot_event($key, '战绩榜', 'evt-board');
$txt = last_mock_text($key);
$posTop = mb_strpos($txt, '测试站长');
$posOwn = mb_strpos($txt, '测试队员');
check('「战绩榜」按平均评分排出队内名次（评分高的在前）',
    str_contains($txt, '队内战绩榜') && $posTop !== false && $posOwn !== false && $posTop < $posOwn,
    mb_substr($txt, 0, 80));

bot_event($key, '热帖', 'evt-hot-gone');
check('「热帖」不再回话（论坛下线，这条指令跟着撤）',
    str_contains(last_mock_text($key), '没当成指令'), mb_substr(last_mock_text($key), 0, 60));
bot_event($key, '精选', 'evt-digest-gone');
check('「精选」同样不再回话', str_contains(last_mock_text($key), '没当成指令'), mb_substr(last_mock_text($key), 0, 60));

[$s, $j] = bot_event($key, '成员', 'evt-members');
$txt = last_mock_text($key);
check('「成员」回成员数与此刻在线（含今日值班口径）', str_contains($txt, '队内成员') && str_contains($txt, '今日房主值班'), mb_substr($txt, 0, 80));

[$s, $j] = bot_event($key, '介绍', 'evt-intro');
$txt = last_mock_text($key);
check('「介绍」回战队名与简介', str_contains($txt, '机器人测试战队') && str_contains($txt, '周末打撞车'), mb_substr($txt, 0, 80));
check('展示页没开时不提链接', !str_contains($txt, '/bttest/show'));

$pdo->exec("INSERT INTO settings (tenant_id, `key`, `value`) VALUES ($tid, 'show_enabled', '1')");
[$s, $j] = bot_event($key, '/介绍', 'evt-intro-open');
check('战队开了对外展示页后，「介绍」给出访问链接', str_contains(last_mock_text($key), '/bttest/show'));

// ============================================================
// 8. 战队管理中心：连接测试与群指令面板（从平台端搬来）
// ============================================================
echo "\n== 8. 测试连接与指令面板 ==\n";
[$s, $j] = admin_req('POST', '/bttest/api/admin/bot/config/test');
check('连接测试可用（mock 走本地调试分支）', ($j['code'] ?? 1) === 0 && str_contains((string)($j['msg'] ?? ''), '本地调试模式'), $j['msg'] ?? '');
for ($i = 0; $i < 5; $i++) { admin_req('POST', '/bttest/api/admin/bot/config/test'); }
[$s, $j] = admin_req('POST', '/bttest/api/admin/bot/config/test');
check('连接测试限频（每分钟 6 次）', ($j['code'] ?? 0) !== 0, $j['msg'] ?? '');

[$s, $j] = admin_req('POST', '/bttest/api/admin/bot/panel/sync');
check('生成/更新本队指令面板', ($j['code'] ?? 1) === 0 && ($j['data']['panel_id'] ?? '') !== '', json_encode($j));
[$s, $j] = admin_req('POST', '/bttest/api/admin/bot/panel/remove');
check('删除本队指令面板', ($j['code'] ?? 1) === 0, $j['msg'] ?? '');
$row = db_one('SELECT panel_id FROM bot_tenant_bots WHERE tenant_id = ?', [$tid]);
check('删面板后 panel_id 已清空', ($row['panel_id'] ?? '') === '', "panel={$row['panel_id']}");
[$s, $j] = admin_req('POST', '/bttest/api/admin/bot/panel/remove');
check('没有面板时再删被拒', ($j['code'] ?? 0) !== 0, $j['msg'] ?? '');
[$s, $j] = admin_req('POST', '/bttest/api/admin/bot/panel/sync');
check('面板可重新生成', ($j['code'] ?? 1) === 0 && ($j['data']['panel_id'] ?? '') !== '', json_encode($j));

// v5.36.4：DELETE 不再带 [] 包体；QQ 侧已不存在时删除/更新按幂等处理
$pm = new ReflectionMethod(\app\service\QqBot::class, 'panelMissing');
$pm->setAccessible(true);
check('40030006（面板不存在）按已删除处理', $pm->invoke(null, 400, ['code' => 40030006]) === true);
check('40011000（请求数据异常）不误判为已删除', $pm->invoke(null, 400, ['code' => 40011000]) === false);
$qsrc = (string)file_get_contents($ROOT . '/app/service/QqBot.php');
check('DELETE 空包体：只有有字段时才带 JSON body',
    str_contains($qsrc, '$hasBody = $body !== []') && str_contains($qsrc, 'if ($hasBody) {')
    && str_contains($qsrc, 'own_panel_delete_fail'));

// ============================================================
// 9. 平台侧只留两件事：出站敏感词与调用日志
// ============================================================
echo "\n== 9. 平台侧残留能力 ==\n";
[$s, $j] = platform_req('POST', '/api/platform/auth/login', ['username' => 'admin', 'password' => 'admin123']);
check('平台超管登录', $s === 200 && ($j['code'] ?? 1) === 0, $j['msg'] ?? '');

foreach (['/api/platform/admin/bot-config', '/api/platform/admin/bot-config/save',
          '/api/platform/admin/bot-test', '/api/platform/admin/bot-teams',
          '/api/platform/admin/bot-panel/sync', '/api/platform/admin/bot-panel/remove'] as $gone) {
    [$s, $j] = platform_req('GET', $gone);
    check('平台端旧机器人接口已下线：' . $gone, $s === 404 || ($j['code'] ?? 0) === 404, "HTTP $s");
}

[$s, $j] = platform_req('GET', '/api/platform/admin/bot-words');
check('敏感词词库仍可读', ($j['code'] ?? 1) === 0 && array_key_exists('words', $j['data'] ?? []), json_encode($j));

[$s, $j] = platform_req('POST', '/api/platform/admin/bot-words', ['words' => '机器人测试战队', 'mask_char' => '*#']);
check('敏感词保存', ($j['code'] ?? 1) === 0, $j['msg'] ?? '');
[$s, $j] = bot_event($key, '介绍', 'evt-intro-word');
check('出站文本按平台词库打码', !str_contains(last_mock_text($key), '机器人测试战队'));
platform_req('POST', '/api/platform/admin/bot-words', ['words' => '', 'mask_char' => '*#']);
[$s, $j] = bot_event($key, '介绍', 'evt-intro-word2');
check('清空词库后正文恢复正常', str_contains(last_mock_text($key), '机器人测试战队'));

[$s, $j] = platform_req('GET', '/api/platform/admin/bot-logs');
check('调用日志仍可在平台侧查', ($j['code'] ?? 1) === 0 && (int)($j['data']['total'] ?? 0) > 0, json_encode($j['data'] ?? null));
$logsBefore = (int)db()->query('SELECT COUNT(*) FROM bot_call_logs')->fetchColumn();

// ============================================================
// 10. 删除配置：本队机器人整条链路静默
// ============================================================
echo "\n== 10. 删除配置 ==\n";
[$s, $j] = admin_req('POST', '/bttest/api/admin/bot/config', ['clear' => 1]);
check('彻底删除独立机器人配置', ($j['code'] ?? 1) === 0, $j['msg'] ?? '');
check('bot_tenant_bots 已清空', db_one('SELECT * FROM bot_tenant_bots WHERE tenant_id = ?', [$tid]) === null);

[$s, $j] = bot_event($key, '/帮助', 'evt-after-clear');
$logsAfter = (int)db()->query('SELECT COUNT(*) FROM bot_call_logs')->fetchColumn();
// 密钥随行配置一起删掉了：回调侧直接认不出这台机器人，事件被拒且不留日志
check('删除配置后群指令不再响应（回调 403 且无新调用日志）', $s === 403 && $logsAfter === $logsBefore, "http=$s before=$logsBefore after=$logsAfter");
[$s, $j] = bot_op13($key);
check('删除配置后回调地址验证不再应答（500）', $s === 500);

[$s, $j] = member_req('GET', '/bttest/api/bot/status');
check('删除后成员端 bot_enabled=false', ($j['data']['bot_enabled'] ?? true) === false);
[$s, $j] = member_req('POST', '/bttest/api/bot/bind-code');
check('删除后成员取码被拒（没有平台公共机器人可回退）', ($j['code'] ?? 0) !== 0, $j['msg'] ?? '');
[$s, $j] = admin_req('GET', '/bttest/api/admin/bot');
check('删除后管理端 has_config=false', ($j['data']['has_config'] ?? true) === false);

// ============================================================
echo "\n==========================================\n";
echo "结果：$pass 通过 / $fail 失败\n";
if ($failures) { echo "失败项：\n - " . implode("\n - ", $failures) . "\n"; }
exit($fail > 0 ? 1 : 0);
