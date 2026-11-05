<?php
/**
 * v5.15.0 安全加固 E2E 测试（越权 / 非法获取登录 / 限流与出站）
 *
 * 前置：测试服务器已启动，.env 指向 SQLite
 *   php -S 127.0.0.1:8899 -t public public/router.php
 * 用法：php scripts/test_security_v5150_e2e.php
 *
 * 覆盖（对应审计报告编号，均为「修复前能被利用、修复后被挡住」的可复现断言）：
 *   1. H1 约战出战名单：发起方不能删应战方的条目，反之亦然
 *   2. H2 IP 封禁：三处入口只认真超管，旧 ip_ban 授权一律失效；自动封禁标注来源战队
 *   3. H3 机器人回调：无密钥地址不受理事件；未知密钥不受理；平台密钥 op:13 可验签
 *   4. M1 平台改站长密码：跨租户清空免登录令牌，旧 cookie 恢复不了身份
 *   5. M2 值班候选 / 图片审核列表的完整 QQ 脱敏（qq_full 例外）
 *   6. M3 群绑定码回填有尝试计数（不再能硬撞 6 位码）
 *   7. M4 rate_bump 并发不丢更新
 *   8. M5 client_ip：不可信来源忽略 XFF、非法段丢弃、/0 不再恒真
 *   9. M6 约战中心地址拒绝内网与非常用端口
 *  10. L1 平台注册重名探测要吃配额
 *  11. L2 找回密码申请超 72 小时作废
 *  12. L3 零权限挂名管理员不能撤回他人消息、不能看他人待审截图
 *  13. L4 隐身 URL 只能兑出一个巡查会话
 *  14. 回归：仪表盘 / 身份 / 图片审核 / 战绩 / 约战列表 / 权限分配
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

$ROOT = dirname(__DIR__);
$BASE = rtrim((string)(getenv('TEST_BASE') ?: 'http://127.0.0.1:8899'), '/');
$DB   = $ROOT . '/runtime/test.sqlite';

require $ROOT . '/vendor/autoload.php';
(new \think\App())->initialize();          // 让测试进程与站点共用同一份 file 缓存与 runtime 目录

$pass = 0; $fail = 0; $failures = [];
function check(string $name, bool $ok, string $detail = ''): void {
    global $pass, $fail, $failures;
    if ($ok) { $pass++; echo "  [PASS] $name\n"; }
    else { $fail++; $failures[] = $name . ($detail !== '' ? " ($detail)" : ''); echo "  [FAIL] $name" . ($detail !== '' ? " —— $detail\n" : "\n"); }
}
function J($a): string { return json_encode($a, JSON_UNESCAPED_UNICODE); }

// ---------- HTTP（内存维护 Cookie） ----------
$jars = [];
function jar(string $who): array { global $jars; if (!isset($jars[$who])) { $jars[$who] = []; } return $jars[$who]; }
function as_req(string $who, string $method, string $path, ?array $body = null, array $extraHdr = []): array {
    global $BASE, $jars;
    $c = jar($who);
    $hdr = "Content-Type: application/json\r\n";
    if ($c) {
        $pairs = [];
        foreach ($c as $k => $v) { $pairs[] = $k . '=' . $v; }
        $hdr .= 'Cookie: ' . implode('; ', $pairs) . "\r\n";
    }
    foreach ($extraHdr as $h) { $hdr .= $h . "\r\n"; }
    $opts = ['http' => ['method' => $method, 'header' => $hdr, 'ignore_errors' => true, 'timeout' => 25, 'follow_location' => 0]];
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

// ---------- 直连库 ----------
function db(): PDO {
    global $DB;
    $p = new PDO('sqlite:' . $DB);
    $p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    return $p;
}
function db_one(string $sql, array $p = []): ?array {
    $st = db()->prepare($sql); $st->execute($p); $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r === false ? null : $r;
}

// ============================================================
// 0. 重建测试库 + 种子
// ============================================================
echo "== 0. 重建测试库与种子 ==\n";
@unlink($DB);
echo shell_exec('"' . PHP_BINARY . '" "' . $ROOT . '/scripts/dev_init_sqlite.php" "' . $DB . '" 2>&1');
foreach (glob($ROOT . '/runtime/cache/*/*') ?: [] as $f) { @unlink($f); }
foreach (glob($ROOT . '/runtime/cache/*') ?: [] as $f) { if (is_file($f)) @unlink($f); }

$now = date('Y-m-d H:i:s');
$pdo = db();

/** 建一支战队：站长 + 副管理员（指定权限）+ 普通成员，返回各 id */
function seed_team(string $slug, string $name, array $subPerms): array {
    global $pdo, $now;
    $pa = $pdo->prepare('INSERT INTO platform_users (username, password, nickname, role, status, reg_ip, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    $pa->execute([$slug . 'owner', password_hash('plat123456', PASSWORD_DEFAULT), $name, 'team', 'normal', '127.0.0.1', $now, $now]);
    $pid = (int)$pdo->lastInsertId();
    $pdo->exec("INSERT INTO tenants (applicant_id, slug, name, status, admin_username, admin_user_id, created_at, updated_at)
        VALUES ($pid, '$slug', '$name', 'approved', '$slug' || 'boss', NULL, '$now', '$now')");
    $tid = (int)$pdo->lastInsertId();
    $mk = function (string $u, string $nick, string $role, $perms) use ($pdo, $tid, $now) {
        // admin_perms 列 NOT NULL：站长传 null 时按「空数组」落库（真超管恒持全部权限，不看这列）
        $j = "'" . json_encode($perms ?? []) . "'";
        $pdo->exec("INSERT INTO users (tenant_id, username, password, nickname, role, status, admin_perms, created_at, updated_at)
            VALUES ($tid, '$u', '" . password_hash('pass123456', PASSWORD_DEFAULT) . "', '$nick', '$role', 'approved', $j, '$now', '$now')");
        return (int)$pdo->lastInsertId();
    };
    $boss   = $mk($slug . 'boss', $slug . '站长', 'admin', null);
    $sub    = $mk($slug . 'sub', $slug . '副管理', 'admin', $subPerms);
    $zero   = $mk($slug . 'zero', $slug . '挂名管理', 'admin', []);
    $member = $mk($slug . 'mem', $slug . '队员', 'user', []);
    $pdo->exec("UPDATE tenants SET admin_user_id = $boss WHERE id = $tid");
    return ['pid' => $pid, 'tid' => $tid, 'boss' => $boss, 'sub' => $sub, 'zero' => $zero, 'member' => $member];
}

// 副管理员：历史遗留的 ip_ban + duty + bot + image_review（第 5 节要用图片审核列表验证脱敏）
$A = seed_team('seca', '安全测试A队', ['ip_ban', 'duty', 'bot', 'image_review']);
$B = seed_team('secb', '安全测试B队', []);
$platUser = 'secsuper';
$pdo->exec("INSERT INTO platform_users (username, password, nickname, role, status, reg_ip, created_at, updated_at)
    VALUES ('$platUser', '" . password_hash('super123456', PASSWORD_DEFAULT) . "', '平台超管', 'admin', 'normal', '127.0.0.1', '$now', '$now')");
$platUid = (int)$pdo->lastInsertId();

check('两支战队 + 平台超管种子就位', $A['tid'] > 0 && $B['tid'] > 0 && $platUid > 0
    && $A['tid'] !== $B['tid'], "tidA={$A['tid']} tidB={$B['tid']}");

$login = function (string $who, string $slug, string $u, string $p, int $remember = 0) {
    return as_req($who, 'POST', "/$slug/api/auth/login", ['username' => $u, 'password' => $p, 'remember' => $remember]);
};
[$s, $j] = $login('a_boss', 'seca', 'secaboss', 'pass123456');
check('A 队站长登录', ($j['code'] ?? 1) === 0, J($j));
[$s, $j] = $login('a_sub', 'seca', 'secasub', 'pass123456');
check('A 队副管理员（历史 ip_ban 授权）登录', ($j['code'] ?? 1) === 0, J($j));
[$s, $j] = $login('a_zero', 'seca', 'secazero', 'pass123456');
check('A 队零权限挂名管理员登录', ($j['code'] ?? 1) === 0, J($j));
[$s, $j] = $login('a_mem', 'seca', 'secamem', 'pass123456');
check('A 队成员登录', ($j['code'] ?? 1) === 0, J($j));
[$s, $j] = $login('b_boss', 'secb', 'secbboss', 'pass123456');
check('B 队站长登录', ($j['code'] ?? 1) === 0, J($j));
[$s, $j] = $login('b_mem', 'secb', 'secbmem', 'pass123456');
check('B 队成员登录', ($j['code'] ?? 1) === 0, J($j));

// ============================================================
// 1. H1 约战出战名单：只能删本方
// ============================================================
echo "\n== 1. H1 约战出战名单跨队删除 ==\n";
$future = date('Y-m-d H:i:s', time() + 6 * 3600);
[$s, $j] = as_req('a_boss', 'POST', '/seca/api/admin/battles', [
    'title' => '安全回归约战', 'defender_type' => 'tenant', 'defender_id' => $B['tid'],
    'series_format' => 'bo3', 'battle_type' => 'training', 'battle_time' => $future,
    'game_mode' => '全面战场 攻防', 'roster_limit' => 8, 'room_info' => '', 'remark' => '',
]);
$BATTLE = (int)($j['data']['id'] ?? 0);
check('A 队向 B 队发起约战', ($j['code'] ?? 1) === 0 && $BATTLE > 0, J($j));
[$s, $j] = as_req('b_boss', 'POST', "/secb/api/admin/battles/$BATTLE/accept");
check('B 队接受，进入报名阶段', ($j['code'] ?? 1) === 0, J($j));

[$s, $j] = as_req('a_boss', 'POST', "/seca/api/admin/battles/$BATTLE/roster", ['add_user_ids' => [$A['member']]]);
check('A 队把自己的成员加进出战名单', ($j['code'] ?? 1) === 0, J($j));
[$s, $j] = as_req('b_boss', 'POST', "/secb/api/admin/battles/$BATTLE/roster", ['add_user_ids' => [$B['member']]]);
check('B 队把自己的成员加进出战名单', ($j['code'] ?? 1) === 0, J($j));

$rows = db()->query("SELECT id, side FROM battle_signups WHERE battle_id = $BATTLE ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
$sideA = 0; $sideB = 0;
foreach ($rows as $r) { if ($r['side'] === 'a') { $sideA = (int)$r['id']; } else { $sideB = (int)$r['id']; } }
check('双方各有一条名单（a=' . $sideA . ' b=' . $sideB . '）', $sideA > 0 && $sideB > 0);

[$s, $j] = as_req('a_boss', 'POST', "/seca/api/admin/battles/$BATTLE/roster", ['remove_id' => $sideB]);
check('A 队拿 B 队的条目 id 删除被拒（403）', ($j['code'] ?? 0) !== 0 && str_contains((string)($j['msg'] ?? ''), '本方'), J($j));
check('B 队的名单条目仍在', db_one("SELECT COUNT(*) c FROM battle_signups WHERE id = $sideB")['c'] == 1);
[$s, $j] = as_req('b_boss', 'POST', "/secb/api/admin/battles/$BATTLE/roster", ['remove_id' => $sideA]);
check('B 队反过来删 A 队条目同样被拒', ($j['code'] ?? 0) !== 0 && ($j['msg'] ?? '') !== '已从名单移除', J($j));
check('A 队条目也仍在', db_one("SELECT COUNT(*) c FROM battle_signups WHERE id = $sideA")['c'] == 1);
[$s, $j] = as_req('a_boss', 'POST', "/seca/api/admin/battles/$BATTLE/roster", ['remove_id' => $sideA]);
check('A 队删除自己的条目仍然正常', ($j['code'] ?? 1) === 0, J($j));
check('自删后条目消失', db_one("SELECT COUNT(*) c FROM battle_signups WHERE id = $sideA")['c'] == 0);
[$s, $j] = as_req('a_mem', 'POST', "/seca/api/admin/battles/$BATTLE/roster", ['remove_id' => $sideB]);
check('普通成员调管理端名单接口被拒', ($j['code'] ?? 0) !== 0 || in_array($s, [401, 403], true), "HTTP $s " . J($j));

// ============================================================
// 2. H2 IP 封禁只认真超管
// ============================================================
echo "\n== 2. H2 IP 封禁门禁 ==\n";
[$s, $j] = as_req('a_sub', 'GET', '/seca/api/admin/blocked-ips');
check('副管理员（授权里带着历史 ip_ban）读封禁列表 → 403', ($j['code'] ?? 0) !== 0 && str_contains((string)($j['msg'] ?? ''), '超级管理员'), J($j));
[$s, $j] = as_req('a_sub', 'POST', '/seca/api/admin/blocked-ips', ['ip' => '203.0.113.77', 'reason' => '越权尝试']);
check('副管理员手动封禁 → 403', ($j['code'] ?? 0) !== 0 && ($j['msg'] ?? '') !== '已封禁 IP: 203.0.113.77', J($j));
check('副管理员的封禁没有落库', db_one("SELECT COUNT(*) c FROM blocked_ips WHERE ip='203.0.113.77'")['c'] == 0);
$anyBan = db_one("SELECT id FROM blocked_ips ORDER BY id LIMIT 1")['id'] ?? null;
if (!$anyBan) {
    $pdo->exec("INSERT INTO blocked_ips (ip, reason, blocked_by, created_at, updated_at) VALUES ('203.0.113.88', '造一条别队来源的记录', NULL, '$now', '$now')");
    $anyBan = (int)$pdo->lastInsertId();
}
[$s, $j] = as_req('a_sub', 'DELETE', '/seca/api/admin/blocked-ips/' . $anyBan);
check('副管理员解封 → 403', ($j['code'] ?? 0) !== 0, J($j));
check('被封记录仍在', db_one("SELECT COUNT(*) c FROM blocked_ips WHERE id = " . (int)$anyBan)['c'] == 1);
[$s, $j] = as_req('a_boss', 'GET', '/seca/api/admin/blocked-ips');
check('站长读封禁列表正常（200 + list）', ($j['code'] ?? 1) === 0 && is_array($j['data']['list'] ?? null), J($j));
check('响应里带着本次请求 IP（current_ip 未被 XFF 干扰）', ($j['data']['current_ip'] ?? '') === '127.0.0.1', (string)($j['data']['current_ip'] ?? ''));
[$s, $j] = as_req('a_boss', 'DELETE', '/seca/api/admin/blocked-ips/' . $anyBan);
check('站长解封正常', ($j['code'] ?? 1) === 0, J($j));

[$s, $j] = as_req('a_boss', 'GET', '/seca/api/admin/perms');
$defsJson = json_encode($j['data']['defs'] ?? [], JSON_UNESCAPED_UNICODE);
check('权限定义里已经没有 ip_ban（旧授权自然失效）', $defsJson !== '' && !str_contains($defsJson, 'ip_ban'), mb_substr($defsJson, 0, 80));

// 自动封禁要能看出来源战队
$beforeBan = (int)db_one("SELECT COUNT(*) c FROM blocked_ips")['c'];
for ($i = 0; $i < 5; $i++) { $login('badactor', 'seca', 'secamem', 'wrongpass999'); }
$auto = db_one("SELECT reason FROM blocked_ips ORDER BY id DESC LIMIT 1");
check('连续失败 5 次触发自动封禁', (int)db_one("SELECT COUNT(*) c FROM blocked_ips")['c'] === $beforeBan + 1, json_encode($auto));
check('自动封禁原因标注了来源战队', $auto !== null && str_contains((string)$auto['reason'], '来源 seca'), (string)($auto['reason'] ?? ''));
[$s, $j] = $login('a_mem2', 'seca', 'secamem', 'pass123456');
check('被封 IP 上的正常登录被拦下', ($j['code'] ?? 0) !== 0 && str_contains((string)($j['msg'] ?? ''), 'IP'), J($j));
// 收尾：清掉封禁与失败计数，别影响后面的用例
$pdo->exec('DELETE FROM blocked_ips');
\think\facade\Cache::delete('login_fail_' . md5('127.0.0.1'));
unset($jars['badactor'], $jars['a_mem2']);
[$s, $j] = $login('a_mem', 'seca', 'secamem', 'pass123456');
check('解封后成员仍可登录', ($j['code'] ?? 1) === 0, J($j));

// ============================================================
// 3. H3 机器人回调鉴权
// ============================================================
echo "\n== 3. H3 机器人回调鉴权 ==\n";
$evt = ['op' => 0, 't' => 'GROUP_AT_MESSAGE_CREATE', 'd' => [
    'id' => 'evt-' . bin2hex(random_bytes(4)), 'group_openid' => 'OGROUP_X',
    'author' => ['member_openid' => 'OMEM_X'], 'content' => '<@!BOT> 帮助']];
[$s, $j] = as_req('anon', 'POST', '/api/bot/callback', $evt);
check('无密钥的旧公共回调地址已下线（404）', $s === 404, "HTTP $s " . J($j));
$botLogs0 = (int)db_one("SELECT COUNT(*) c FROM bot_call_logs")['c'];
[$s, $j] = as_req('anon', 'POST', '/api/bot/callback/deadbeef0000000000000000000000ff', $evt);
check('未知密钥的事件推送 → 403', $s === 403, "HTTP $s");
check('两次伪造都没有产生机器人指令日志', (int)db_one("SELECT COUNT(*) c FROM bot_call_logs")['c'] === $botLogs0);

[$s, $j] = as_req('plat', 'POST', '/api/platform/auth/login', ['username' => $platUser, 'password' => 'super123456']);
check('平台超管登录入驻平台', ($j['code'] ?? 1) === 0, J($j));
// v5.28.0：平台不再持有机器人凭据，回调地址与 AppSecret 只有各战队那一份 —— 给 A 队落一行
$secret = 'sec-test-secret-0123456789abcdef';
$botKey = substr(md5('seca-bot-' . $A['tid']), 0, 32);
$pdo->exec("INSERT OR REPLACE INTO bot_tenant_bots
    (tenant_id, app_id, app_secret, callback_key, enabled, env, panel_id, created_at, updated_at)
    VALUES ({$A['tid']}, 'mock', '$secret', '$botKey', 1, 'prod', '', '$now', '$now')");
[$s, $j] = as_req('plat', 'GET', '/api/platform/admin/bot-config');
check('平台端机器人凭据接口已下线（404）', $s === 404 || ($j['code'] ?? 0) === 404, "HTTP $s");
$cb = '/api/bot/callback/' . $botKey;
check('战队回调密钥形如 32 位十六进制', preg_match('/^[0-9a-f]{32}$/', $botKey) === 1, $botKey);
[$s, $j] = as_req('anon', 'POST', $cb, ['op' => 13, 'd' => ['plain_token' => 'pt-abc', 'event_ts' => '1786000000']]);
$want = bot_sign_ed($secret, '1786000000pt-abc');
check('战队密钥地址 op:13 通过，签名用本队 AppSecret 算',
    $s === 200 && ($j['plain_token'] ?? '') === 'pt-abc' && ($j['signature'] ?? '') === $want, "HTTP $s " . J($j));
[$s, $j] = as_req('anon', 'POST', $cb, $evt);
check('战队密钥地址的事件推送被受理（不再 403）', $s === 200, "HTTP $s");
[$s, $j] = as_req('anon', 'POST', $cb, $evt + ['t' => 'GROUP_AT_MESSAGE_CREATE',
    'd' => $evt['d'] + ['id' => 'evt-' . bin2hex(random_bytes(4) . random_bytes(4))]]);
check('未带 X-Bot-Appid 时不拦（宽松兼容既有推送）', $s === 200, "HTTP $s");
[$s, $j] = as_req('anon', 'POST', $cb, [
    'op' => 0, 't' => 'GROUP_AT_MESSAGE_CREATE',
    'd' => ['id' => 'evt-' . bin2hex(random_bytes(8)), 'group_openid' => 'OGROUP_Y',
            'author' => ['member_openid' => 'OMEM_Y'], 'content' => '<@!BOT> 帮助']],
    ['X-Bot-Appid: 9999999']);
check('X-Bot-Appid 与配置不符时拒绝（纵深防御）', $s === 403, "HTTP $s");
[$s, $j] = as_req('anon2', 'POST', '/api/bot/callback', ['op' => 13, 'd' => ['plain_token' => 'pt2', 'event_ts' => '1786000001']]);
check('无密钥地址不再应答 op:13（公共机器人下线后这条路彻底没有）', $s === 404, "HTTP $s");

/** 与 BotCallback::sign 同算法（种子加倍填充到 32 字节） */
function bot_sign_ed(string $secret, string $message): string {
    $seed = $secret;
    while (strlen($seed) < SODIUM_CRYPTO_SIGN_SEEDBYTES) { $seed = str_repeat($seed, 2); }
    $seed = substr($seed, 0, SODIUM_CRYPTO_SIGN_SEEDBYTES);
    $kp = sodium_crypto_sign_seed_keypair($seed);
    return bin2hex(sodium_crypto_sign_detached($message, sodium_crypto_sign_secretkey($kp)));
}

// ============================================================
// 4. M1 平台改站长密码能驱逐免登录
// ============================================================
echo "\n== 4. M1 改密驱逐免登录 ==\n";
unset($jars['a_boss']);
[$s, $j] = $login('a_boss_rm', 'seca', 'secaboss', 'pass123456', 1);
check('A 队站长勾选「免登录」重新登录', ($j['code'] ?? 1) === 0, J($j));
$tokRow = db_one("SELECT COUNT(*) c FROM remember_tokens WHERE tenant_id = {$A['tid']} AND user_id = {$A['boss']}");
check('免登录令牌已落库', (int)$tokRow['c'] === 1, json_encode($tokRow));
// 立刻记下这枚令牌：后面平台侧改密时服务端会把 cookie 清掉，事后再读 jar 只能读到 deleted
$rmSnapshot = (string)($jars['a_boss_rm']['remember_me'] ?? '');
[$s, $j] = as_req('a_boss_rm', 'POST', '/api/platform/auth/login', ['username' => 'secaowner', 'password' => 'plat123456']);
check('以 A 队入驻账号登录平台', ($j['code'] ?? 1) === 0, J($j));
[$s, $j] = as_req('a_boss_rm', 'POST', '/api/platform/my/admin-password', ['password' => 'newpass123', 'confirm' => 'newpass123']);
check('平台侧重置本队站长密码', ($j['code'] ?? 1) === 0, J($j));
$left = (int)db_one("SELECT COUNT(*) c FROM remember_tokens WHERE user_id = {$A['boss']}")['c'];
check('该站长的免登录令牌被跨租户清空', $left === 0, "left=$left");
// 只带那枚免登录 cookie（不带会话 cookie），证明被驱逐的确实是免登录凭据本身
$jars['evict'] = ['remember_me' => $rmSnapshot];      // 只带免登录 cookie，不带会话 cookie
[$s, $j] = as_req('evict', 'GET', '/seca/api/admin/stats');
check('旧免登录 cookie 换不来身份（管理端 401）', in_array($s, [401, 403], true) || ($j['code'] ?? 0) !== 0, "HTTP $s");
check('请求里确实带着一枚结构完整的免登录凭据', substr_count($rmSnapshot, '.') === 1, $rmSnapshot);
check('服务端顺手把失效的免登录 cookie 清掉',
    in_array((string)($jars['evict']['remember_me'] ?? 'deleted'), ['', 'deleted'], true),
    (string)($jars['evict']['remember_me'] ?? '(无)'));
[$s, $j] = $login('a_new', 'seca', 'secaboss', 'newpass123');
check('新密码可正常登录', ($j['code'] ?? 1) === 0, J($j));
$pdo->exec("UPDATE users SET password = '" . password_hash('pass123456', PASSWORD_DEFAULT) . "', session_epoch = session_epoch + 1 WHERE id = {$A['boss']}");
unset($jars['a_boss'], $jars['a_boss_rm'], $jars['a_after'], $jars['a_new']);
[$s, $j] = $login('a_boss', 'seca', 'secaboss', 'pass123456');
check('还原站长口令并重新登录', ($j['code'] ?? 1) === 0, J($j));

// ============================================================
// 5. M2 值班候选与图片审核的 QQ 脱敏
// ============================================================
echo "\n== 5. M2 完整 QQ 脱敏 ==\n";
$qqFull = '12345678';
$pdo->exec("INSERT INTO users (tenant_id, username, password, nickname, role, status, admin_perms, created_at, updated_at)
    VALUES ({$A['tid']}, '$qqFull', '" . password_hash('pass123456', PASSWORD_DEFAULT) . "', '', 'user', 'approved', '[]', '$now', '$now')");
$noNick = (int)$pdo->lastInsertId();
[$s, $j] = as_req('a_sub', 'GET', '/seca/api/admin/duty');
$cand = $j['data']['candidates'] ?? [];
$hit = null;
foreach ($cand as $c) { if ((int)($c['id'] ?? 0) === $noNick) { $hit = $c; } }
check('值班候选里出现新成员', $hit !== null, 'count=' . count($cand));
check('副管理员（无 qq_full）看到的 QQ 已打码', $hit !== null && $hit['username'] !== $qqFull && str_contains((string)$hit['username'], '*'), J($hit));
[$s, $j] = as_req('a_boss', 'GET', '/seca/api/admin/duty');
$hit2 = null;
foreach (($j['data']['candidates'] ?? []) as $c) { if ((int)($c['id'] ?? 0) === $noNick) { $hit2 = $c; } }
check('站长（恒持 qq_full）仍可看到完整 QQ', ($hit2['username'] ?? '') === $qqFull, J($hit2));

$upfile = $ROOT . '/public/storage/uploads/_sec_probe.png';
@mkdir(dirname($upfile), 0775, true);
file_put_contents($upfile, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8DwHwAFAAH/q842iQAAAABJRU5ErkJggg=='));
$pdo->exec("INSERT INTO images (tenant_id, user_id, title, file, status, created_at, updated_at)
    VALUES ({$A['tid']}, $noNick, '安全探针图', '/storage/uploads/_sec_probe.png', 'pending', '$now', '$now')");
$imgId = (int)$pdo->lastInsertId();
[$s, $j] = as_req('a_sub', 'GET', '/seca/api/admin/images?status=pending');
$up = null;
foreach (($j['data']['list'] ?? []) as $it) { if ((int)($it['id'] ?? 0) === $imgId) { $up = $it; } }
check('图片审核列表出现探针图', $up !== null, 'count=' . count($j['data']['list'] ?? []));
check('副管理员看到的上传人 QQ 已打码', $up !== null && (string)$up['uploader_qq'] !== $qqFull, J($up));
[$s, $j] = as_req('a_boss', 'GET', '/seca/api/admin/images?status=pending');
$up2 = null;
foreach (($j['data']['list'] ?? []) as $it) { if ((int)($it['id'] ?? 0) === $imgId) { $up2 = $it; } }
check('站长可看到完整上传人 QQ', ($up2['uploader_qq'] ?? '') === $qqFull, J($up2));

// ============================================================
// 6. M3 群绑定码回填尝试计数
// ============================================================
echo "\n== 6. M3 群绑定码尝试计数 ==\n";
// 回填群绑定码的前置只剩「本队有启用的独立机器人」（平台授权白名单已在 v5.28.0 取消）
$pdo->exec("INSERT OR REPLACE INTO bot_tenant_bots
    (tenant_id, app_id, app_secret, callback_key, enabled, env, panel_id, created_at, updated_at)
    VALUES ({$A['tid']}, 'mock', '$secret', '$botKey', 1, 'prod', '', '$now', '$now')");
$refused = 0; $limited = 0;
for ($i = 0; $i < 12; $i++) {
    [$s, $j] = as_req('a_sub', 'POST', '/seca/api/admin/bot/verify', ['code' => '123456']);
    if (str_contains((string)($j['msg'] ?? ''), '不存在')) { $refused++; }
    if ($s === 429 || str_contains((string)($j['msg'] ?? ''), '尝试次数过多')) { $limited++; }
}
check('错误验证码逐次被拒（前 8 次给明确提示）', $refused >= 6, "refused=$refused");
check('同一枚码撞够次数后被限流（不再能硬撞 10^6）', $limited >= 3, "limited=$limited");
\think\facade\Cache::delete('bot_bind_try_t_' . $A['tid']);
\think\facade\Cache::delete('bot_bind_try_c_' . $A['tid'] . '_123456');

// ============================================================
// 7. M4 rate_bump 并发不丢更新
// ============================================================
echo "\n== 7. M4 限流计数并发 ==\n";
$child = $ROOT . '/runtime/sec_child.php';
file_put_contents($child, <<<'PHP'
<?php
$ROOT = dirname(__DIR__);
require $ROOT . '/vendor/autoload.php';
(new \think\App())->initialize();
$mode = $argv[1] ?? '';
if ($mode === 'bump') {
    $key = $argv[2] ?? 'conc_key';
    $times = (int)($argv[3] ?? 5);
    for ($i = 0; $i < $times; $i++) { rate_bump($key, 600); }
    echo 'BUMPED';
    exit(0);
}
if ($mode === 'read') { echo (string)\think\facade\Cache::get($argv[2] ?? 'conc_key', 0); exit(0); }
// ---- client_ip 各分支：在子进程里设定 $_SERVER / Env，避免 static 缓存互相污染 ----
if ($mode === 'xff_untrusted') {
    $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
    $_SERVER['HTTP_X_FORWARDED_FOR'] = '8.8.8.8, 1.2.3.4';
    echo client_ip(); exit(0);
}
if ($mode === 'xff_trusted') {
    \think\facade\Env::set('TRUSTED_PROXIES', '203.0.113.0/24');
    $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
    $_SERVER['HTTP_X_FORWARDED_FOR'] = '9.9.9.9, 203.0.113.9';
    echo client_ip(); exit(0);
}
if ($mode === 'xff_garbage') {
    \think\facade\Env::set('TRUSTED_PROXIES', '203.0.113.0/24');
    $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
    $_SERVER['HTTP_X_FORWARDED_FOR'] = '<script>, not-an-ip, 9.9.9.9';
    echo client_ip(); exit(0);
}
if ($mode === 'cidr0') {
    \think\facade\Env::set('TRUSTED_PROXIES', '0.0.0.0/0');
    $_SERVER['REMOTE_ADDR'] = '198.51.100.7';
    $_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.5';
    echo client_ip() . '|' . json_encode(trusted_proxies());
    exit(0);
}
echo 'UNKNOWN';
PHP);
$run = function (array $args) use ($child) {
    return trim((string)shell_exec('"' . PHP_BINARY . '" ' . escapeshellarg($child) . ' ' . implode(' ', $args) . ' 2>&1'));
};
\think\facade\Cache::delete('conc_key');
$kids = [];
for ($i = 0; $i < 8; $i++) { $kids[] = popen('"' . PHP_BINARY . '" ' . escapeshellarg($child) . ' bump conc_key 5', 'r'); }
foreach ($kids as $h) { pclose($h); }
$final = (int)$run(['read', 'conc_key']);
check('8 进程 × 5 次自增，计数不丢（应为 40）', $final === 40, "final=$final");
check('限流锁文件已生成', is_dir($ROOT . '/runtime/lock') && count(glob($ROOT . '/runtime/lock/*.lock') ?: []) > 0);
\think\facade\Cache::delete('conc_key');

check('来源不可信时忽略 XFF，取 REMOTE_ADDR', $run(['xff_untrusted']) === '203.0.113.9', $run(['xff_untrusted']));
check('来源可信时按 XFF 取第一个不可信 IP', $run(['xff_trusted']) === '9.9.9.9', $run(['xff_trusted']));
check('XFF 里的非法段被丢弃而不是当 IP 用', $run(['xff_garbage']) === '9.9.9.9', $run(['xff_garbage']));
$c0 = $run(['cidr0']);
check('/0 这类过窄前缀不再被采信（列表为空且取 REMOTE_ADDR）',
    str_starts_with($c0, '198.51.100.7|[]'), $c0);
@unlink($child);

// ============================================================
// 8. M6 约战中心地址拒绝内网
// ============================================================
echo "\n== 8. M6 约战中心地址（SSRF） ==\n";
$hub = function (string $url) {
    [$s, $j] = as_req('a_boss', 'POST', '/seca/api/admin/battle-hub-config', ['enabled' => 1, 'url' => $url, 'key' => 'k']);
    return ($j['code'] ?? 1) === 0 ? 'ACCEPT' : (string)($j['msg'] ?? 'reject');
};
foreach ([
    'http://127.0.0.1:8899'          => '内网/回环',
    'http://10.0.0.7/'               => '内网/回环',
    'http://192.168.1.1:8080/'       => '内网/回环',
    'http://169.254.169.254/latest'  => '内网/回环',
    'http://localhost:6379'          => '内网/回环',
] as $u => $why) {
    $r = $hub($u);
    check("拒绝 {$u}（{$why}）", $r !== 'ACCEPT', $r);
}
$r = $hub('http://8.8.8.8:8111/');
check('拒绝非常用端口 8111', $r !== 'ACCEPT', $r);
$r = $hub('https://8.8.8.8/');
check('公网 https 地址放行', $r === 'ACCEPT', $r);
[$s, $j] = as_req('a_sub', 'POST', '/seca/api/admin/battle-hub-config', ['enabled' => 1, 'url' => 'https://hub.example.com', 'key' => '']);
check('约战中心配置仍只认真超管（副管理员 403）', ($j['code'] ?? 0) !== 0, J($j));
[$s, $j] = as_req('a_boss', 'POST', '/seca/api/admin/battle-hub-config', ['enabled' => 0, 'url' => '', 'key' => '']);
check('收尾：关闭约战中心连接', ($j['code'] ?? 1) === 0, J($j));

// ============================================================
// 9. L1 平台注册重名探测吃配额
// ============================================================
echo "\n== 9. L1 平台注册配额 ==\n";
$pdo->exec("INSERT INTO platform_users (username, password, nickname, role, status, reg_ip, created_at, updated_at)
    VALUES ('takenacct', 'x', '已占用', 'team', 'normal', '127.0.0.1', '$now', '$now')");
$msgs = [];
for ($i = 0; $i < 5; $i++) {
    [$s, $j] = as_req('anon' . $i, 'POST', '/api/platform/auth/register', [
        'username' => 'takenacct', 'password' => 'Kx92mvp7Qz31', 'confirm' => 'Kx92mvp7Qz31', 'nickname' => '探测' . $i]);
    $msgs[] = (string)($j['msg'] ?? '');
}
$dup = count(array_filter($msgs, fn($m) => str_contains($m, '已被注册')));
$rate = count(array_filter($msgs, fn($m) => str_contains($m, '过于频繁')));
check('重名探测不再是无限次数（前 3 次给重名提示，之后转为限流）', $dup >= 1 && $rate >= 1, "dup=$dup rate=$rate " . J($msgs));
\think\facade\Cache::delete('preg_rate_ip_' . md5('127.0.0.1'));

// ============================================================
// 10. L2 找回密码申请超 72 小时作废
// ============================================================
echo "\n== 10. L2 找回密码时效 ==\n";
$old = date('Y-m-d H:i:s', time() - 8 * 86400);
$hashOld = password_hash('stale12345', PASSWORD_DEFAULT);
$pdo->exec("INSERT INTO password_resets (tenant_id, user_id, new_password, screenshot, request_ip, created_at)
    VALUES ({$A['tid']}, $noNick, '$hashOld', '', '127.0.0.1', '$old')");
$prId = (int)$pdo->lastInsertId();
[$s, $j] = as_req('a_boss', 'POST', '/seca/api/admin/pwd-resets/' . $prId, ['action' => 'approve']);
check('超 72 小时的申请被拒绝', ($j['code'] ?? 0) !== 0 && str_contains((string)($j['msg'] ?? ''), '72'), J($j));
check('过期申请已连同记录一并清掉', db_one("SELECT COUNT(*) c FROM password_resets WHERE id = $prId")['c'] == 0);
$pwNow = (string)db_one("SELECT password FROM users WHERE id = $noNick")['password'];
check('成员原口令没有被旧申请覆盖', password_verify('pass123456', $pwNow));
$recent = date('Y-m-d H:i:s', time() - 3600);
$hashNew = password_hash('fresh12345', PASSWORD_DEFAULT);
$pdo->exec("INSERT INTO password_resets (tenant_id, user_id, new_password, screenshot, request_ip, created_at)
    VALUES ({$A['tid']}, $noNick, '$hashNew', '', '127.0.0.1', '$recent')");
$prId2 = (int)$pdo->lastInsertId();
[$s, $j] = as_req('a_boss', 'POST', '/seca/api/admin/pwd-resets/' . $prId2, ['action' => 'approve']);
check('72 小时内的申请仍正常通过', ($j['code'] ?? 1) === 0, J($j));
check('新口令已生效', password_verify('fresh12345', (string)db_one("SELECT password FROM users WHERE id = $noNick")['password']));

// ============================================================
// 11. L3 零权限挂名管理员的聊天/截图门禁
// ============================================================
echo "\n== 11. L3 挂名管理员门禁 ==\n";
$pdo->exec("INSERT INTO chat_messages (tenant_id, user_id, content, recalled, created_at)
    VALUES ({$A['tid']}, {$A['member']}, '被挂名管理员想撤回的话', 0, '$now')");
$msgId = (int)$pdo->lastInsertId();
[$s, $j] = as_req('a_zero', 'POST', '/seca/api/chat/recall/' . $msgId);
check('零权限挂名管理员撤回他人消息 → 403', ($j['code'] ?? 0) !== 0 && str_contains((string)($j['msg'] ?? ''), '禁言'), J($j));
check('消息仍未被撤回', (int)db_one("SELECT recalled FROM chat_messages WHERE id = $msgId")['recalled'] === 0);
[$s, $j] = as_req('a_zero', 'GET', '/seca/api/images/raw/' . $imgId);
check('挂名管理员看他人待审截图 → 403/404', in_array($s, [403, 404], true), "HTTP $s");
$pdo->exec("UPDATE users SET admin_perms = '[\"mute\"]' WHERE id = {$A['zero']}");
unset($jars['a_zero']);
[$s, $j] = $login('a_zero', 'seca', 'secazero', 'pass123456');
[$s, $j] = as_req('a_zero', 'POST', '/seca/api/chat/recall/' . $msgId);
check('授 mute 权限后可撤回（200）', ($j['code'] ?? 1) === 0, J($j));
check('消息已标记撤回', (int)db_one("SELECT recalled FROM chat_messages WHERE id = $msgId")['recalled'] === 1);
$pdo->exec("UPDATE users SET admin_perms = '[\"screenshots\"]' WHERE id = {$A['zero']}");
unset($jars['a_zero']);
[$s, $j] = $login('a_zero', 'seca', 'secazero', 'pass123456');
[$s, $j] = as_req('a_zero', 'GET', '/seca/api/images/raw/' . $imgId);
check('授 screenshots 权限后可查看该截图', $s === 200, "HTTP $s");
[$s, $j] = as_req('a_mem', 'GET', '/seca/api/images/raw/' . $imgId);
check('非上传者的普通成员看他人待审图 → 403（水平隔离）', $s === 403, "HTTP $s");
$ownerJar = jar('noNickOwner');
[$s, $j] = $login('noNickOwner', 'seca', $qqFull, 'fresh12345');
[$s, $j] = as_req('noNickOwner', 'GET', '/seca/api/images/raw/' . $imgId);
check('无昵称成员的图本人也能取回', $s === 200, "HTTP $s");

// ============================================================
// 12. L4 隐身 URL 一次性兑票
// ============================================================
echo "\n== 12. L4 隐身兑票一次性 ==\n";
$pdo->exec("UPDATE tenants SET status = 'approved' WHERE id = {$B['tid']}");
[$s, $j] = as_req('plat', 'POST', '/api/platform/admin/tenants/' . $B['tid'] . '/stealth');
$rawTxt = (string)($j['data']['url'] ?? '') . ' ' . (string)($j['data']['token'] ?? '') . ' ' . J($j);
preg_match('/[0-9a-f]{48}/', $rawTxt, $mm);
$sv = $mm[0] ?? '';
check('平台超管签发了巡查令牌', strlen($sv) === 48, mb_substr($rawTxt, 0, 120));
$cntBefore = (int)db_one("SELECT COUNT(*) c FROM stealth_tokens WHERE tenant_id = {$B['tid']}")['c'];
[$s1] = as_req('sv1', 'GET', '/secb/app?_sv=' . $sv);
$cookie1 = isset(jar('sv1')['sv_guard']);
[$s2, $j2, $raw2] = as_req('sv2', 'GET', '/secb/app?_sv=' . $sv);
$cookie2 = isset(jar('sv2')['sv_guard']);
check('首次兑票换发巡查 cookie', $cookie1, "HTTP $s1");
check('同一枚令牌第二次兑不出第二个会话', !$cookie2, "HTTP $s2 " . ($cookie2 ? 'issued' : ''));
$cntAfter = (int)db_one("SELECT COUNT(*) c FROM stealth_tokens WHERE tenant_id = {$B['tid']}")['c'];
check('库内登记只多出一条（会话 secret），没有第二枚', $cntAfter === $cntBefore, "before=$cntBefore after=$cntAfter");

// ============================================================
// 13. 回归
// ============================================================
echo "\n== 13. 既有功能回归 ==\n";
[$s, $j] = as_req('a_boss', 'GET', '/seca/api/dashboard');
check('仪表盘正常（含在线名单）', ($j['code'] ?? 1) === 0 && isset($j['data']['online_count']), J($j));
[$s, $j] = as_req('a_mem', 'GET', '/seca/api/auth/me');
check('成员身份接口正常', ($j['code'] ?? 1) === 0, J($j));
[$s, $j] = as_req('a_boss', 'GET', '/seca/api/admin/images?status=pending');
check('图片审核列表正常', ($j['code'] ?? 1) === 0, J($j));
[$s, $j] = as_req('a_boss', 'GET', '/seca/api/admin/battles');
check('约战管理列表正常', ($j['code'] ?? 1) === 0, J($j));
[$s, $j] = as_req('a_boss', 'GET', '/seca/api/admin/blocked-ips');
check('站长封禁列表仍可用', ($j['code'] ?? 1) === 0, J($j));
[$s, $j] = as_req('a_boss', 'GET', '/seca/api/admin/perms');
check('权限分配页正常', ($j['code'] ?? 1) === 0 && is_array($j['data']['defs'] ?? null), J($j));
[$s, $j] = as_req('a_mem', 'GET', '/seca/api/chat/messages');
check('聊天室正常', ($j['code'] ?? 1) === 0, J($j));

// ============================================================
// 14. v5.36.3：旧体检页 CSRF + 死链巡检 SSRF
// ============================================================
echo "\n== 14. v5.36.3 安全修复 ==\n";
// 表单编码 POST（体检页读 $_POST，as_req() 发的是 JSON，这里单独来一份）
function form_req(string $who, string $path, array $body): array {
    global $BASE, $jars;
    $c = jar($who);
    $hdr = "Content-Type: application/x-www-form-urlencoded\r\n";
    if ($c) {
        $pairs = [];
        foreach ($c as $k => $v) { $pairs[] = $k . '=' . $v; }
        $hdr .= 'Cookie: ' . implode('; ', $pairs) . "\r\n";
    }
    $opts = ['http' => ['method' => 'POST', 'header' => $hdr, 'ignore_errors' => true,
        'timeout' => 25, 'follow_location' => 0, 'content' => http_build_query($body)]];
    $raw = @file_get_contents($BASE . $path, false, stream_context_create($opts));
    $status = 0;
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) { $status = (int)$m[1]; }
        if (preg_match('/^Set-Cookie:\s*([^=]+)=([^;]*)/i', $h, $m)) { $c[$m[1]] = trim($m[2]); }
    }
    $jars[$who] = $c;
    return [$status, (string)$raw];
}
[$s, , $rawPage] = as_req('plat', 'GET', '/db_repair_v5202.php?do=apply');
check('旧体检页 GET ?do=apply 不再执行补迁移', $s === 200 && !str_contains($rawPage, '[补迁移]'), "HTTP $s");
[$s, $rawPage] = form_req('plat', '/db_repair_v5202.php', ['do' => 'apply']);
check('旧体检页 POST 缺 token → 拒绝且不执行', $s === 200 && str_contains($rawPage, '校验失败')
    && !str_contains($rawPage, '[补迁移] 逐条执行'), "HTTP $s");
$rpSrc = (string)file_get_contents($ROOT . '/public/db_repair_v5202.php');
check('补迁移只认 POST + 会话 token，页面输出逐行转义',
    str_contains($rpSrc, 'hash_equals($opToken') && !str_contains($rpSrc, '$_GET')
    && str_contains($rpSrc, 'htmlspecialchars($t, ENT_QUOTES'));
$r = \app\service\LinkChecker::addManual('http://127.0.0.1/x', 0);
check('死链巡检拒绝回环地址', ($r['ok'] ?? true) === false
    && str_contains((string)($r['error'] ?? ''), '内网'), J($r));
$r = \app\service\LinkChecker::addManual('http://169.254.169.254/latest/meta-data/', 0);
check('死链巡检拒绝链路本地/云元数据地址', ($r['ok'] ?? true) === false, J($r));
$lcSrc = (string)file_get_contents($ROOT . '/app/service/LinkChecker.php');
check('死链探测关闭 curl 自动跳转、每一跳过公网校验',
    str_contains($lcSrc, 'CURLOPT_FOLLOWLOCATION => false')
    && substr_count($lcSrc, 'safe_outbound_url') >= 3);

// 收尾：清掉探针文件与测试数据痕迹
@unlink($upfile);
$pdo->exec("DELETE FROM images WHERE id = $imgId");
$pdo->exec("DELETE FROM bot_group_codes WHERE code LIKE '1234%'");
$pdo->exec('DELETE FROM blocked_ips');
echo "\n================ 结果 ================\n";
echo "通过 $pass 项 / 失败 $fail 项\n";
if ($failures) { echo "失败项：\n - " . implode("\n - ", $failures) . "\n"; }
exit($fail > 0 ? 1 : 0);
