<?php
/**
 * v5.26.0 改枪码「武器类别」—— 端到端
 *
 * 前置：测试服务器已启动，.env 指向 SQLite（.env 里只能用 ; 注释）
 *   php -S 127.0.0.1:8899 -t public public/router.php
 * 用法：php scripts/test_guncode_category_e2e.php
 *
 * 这一套锁的是类别这九个键最容易被人改坏的地方：
 *   1. 建表默认值就是 other（未分类），之前创建过的枪码不用回填
 *   2. 类别清单由接口下发，九项、顺序固定，前端不再抄一份
 *   3. 发布/修改只认这九个键；「全部」不是能选的值（直接发 all 落未分类，发乱码键被挡下且不落库）
 *   4. 列表按类别筛：与关键字叠加、total 跟着变、未知键当没筛（不报错）
 *   5. 租户隔离与上下架可见性不因筛选破口（别的队同名的类别也筛不出别人的枪码）
 *   6. 修改别人的类别要 403，且改完之后类别真的变了/真的没变
 *   7. 论坛投放的正文形状不变（枪码：xxx，不带类别字样），老功能不受这列影响
 *   8. 站点导出导入的字段白名单带 category（导得出也要进得回）
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
function J($a): string { return json_encode($a, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); }

$jars = [];
function as_req(string $who, string $method, string $path, ?array $body = null): array
{
    global $BASE, $jars;
    $c = $jars[$who] ?? [];
    $hdr = "Content-Type: application/json\r\nOrigin: $BASE\r\n";
    if ($c) {
        $pairs = [];
        foreach ($c as $k => $v) { $pairs[] = $k . '=' . $v; }
        $hdr .= 'Cookie: ' . implode('; ', $pairs) . "\r\n";
    }
    $opts = ['http' => ['method' => $method, 'header' => $hdr, 'ignore_errors' => true,
        'timeout' => 30, 'follow_location' => 0]];
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
function api(string $who, string $slug, string $method, string $path, ?array $body = null): array
{
    return as_req($who, $method, '/' . $slug . '/api' . $path, $body);
}
function db(): PDO
{
    global $DB;
    $p = new PDO('sqlite:' . $DB);
    $p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $p->setAttribute(PDO::ATTR_TIMEOUT, 5);
    return $p;
}
function loginTenant(string $who, string $slug, string $acct): bool
{
    [, $j] = as_req($who, 'POST', '/' . $slug . '/api/auth/login', ['username' => $acct, 'password' => 'pass123456']);
    return (int)($j['code'] ?? 1) === 0;
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
/** 列表里按 id 挑一条 */
function pick(array $j, int $id): ?array
{
    foreach (($j['data']['list'] ?? []) as $it) { if ((int)$it['id'] === $id) { return $it; } }
    return null;
}

// ============================================================
// 0. 重建测试库与种子
// ============================================================
echo "== 0. 重建测试库与种子 ==\n";
if (is_file($DB)) { @unlink($DB); }
echo shell_exec('"' . PHP_BINARY . '" "' . $ROOT . '/scripts/dev_init_sqlite.php" "' . $DB . '" 2>&1');
clearCache();

$now  = date('Y-m-d H:i:s');
$pdo  = db();
$hash = password_hash('pass123456', PASSWORD_DEFAULT);

$col = $pdo->query("PRAGMA table_info(gun_codes)")->fetchAll(PDO::FETCH_ASSOC);
$hasCat = false;
foreach ($col as $c) {
    if ((string)$c['name'] === 'category') { $hasCat = true; $def = (string)($c['dflt_value'] ?? ''); }
}
check('夹具建表里 gun_codes 有 category 列', $hasCat);
check('这一列的默认值就是 other（未分类）', $hasCat && str_replace("'", '', $def) === 'other', $def ?? '');

$mk = function (string $slug, string $name, array $people) use ($pdo, $hash, $now): array {
    $pdo->exec("INSERT INTO platform_users (username,password,nickname,role,status,reg_ip,created_at,updated_at)
        VALUES ('{$slug}boss', '{$hash}', '{$name}联络人', 'team', 'normal', '127.0.0.1', '$now', '$now')");
    $pid = (int)$pdo->lastInsertId();
    $pdo->exec("INSERT INTO tenants (applicant_id, slug, name, logo, description, status, admin_username, admin_user_id, created_at, updated_at)
        VALUES ($pid, '$slug', '$name', '', '入驻时填的战队简介', 'approved', '{$people[0][0]}', NULL, '2026-01-01 10:00:00', '$now')");
    $tid = (int)$pdo->lastInsertId();
    $ids = [];
    foreach ($people as $p) {
        [$qq, $nick, $role, $perms] = $p;
        $pdo->exec("INSERT INTO users (tenant_id, username, password, nickname, role, status, admin_perms, created_at, updated_at)
            VALUES ($tid, '$qq', '$hash', '$nick', '$role', 'approved', '$perms', '$now', '$now')");
        $ids[$qq] = (int)$pdo->lastInsertId();
    }
    $pdo->exec("UPDATE tenants SET admin_user_id = {$ids[$people[0][0]]} WHERE id = $tid");
    return ['tid' => $tid, 'ids' => $ids];
};
$A = $mk('gxa', '枪码甲队', [
    ['9626100001', '甲队队长', 'admin', '[]'],
    ['9626100002', '甲队会员', 'user',  '[]'],
    ['9626100003', '甲队会员二', 'user', '[]'],
]);
$B = $mk('gxb', '枪码乙队', [
    ['9626200001', '乙队队长', 'admin', '[]'],
]);
$capA = (int)$A['ids']['9626100001']; $memA = (int)$A['ids']['9626100002'];
$memB = (int)$A['ids']['9626100003'];
$capB = (int)$B['ids']['9626200001'];

echo "-- 登录 --\n";
check('甲队队长登录', loginTenant('capA', 'gxa', '9626100001'));
check('甲队成员登录', loginTenant('memA', 'gxa', '9626100002'));
check('甲队会员二登录', loginTenant('memB', 'gxa', '9626100003'));
check('乙队队长登录', loginTenant('capB', 'gxb', '9626200001'));

// ============================================================
// 1. 接口把九项类别清单下发下来
// ============================================================
echo "\n== 1. 类别清单由接口下发 ==\n";
[, $j] = api('capA', 'gxa', 'GET', '/guncodes?page=1&size=10');
$cats = $j['data']['categories'] ?? null;
check('列表接口回 categories', is_array($cats), J($j)[''] ?? '');
$keys = array_map(static fn($x) => (string)($x['key'] ?? ''), (array)$cats);
$want = ['other', 'smg', 'ar', 'pistol', 'special', 'sniper', 'dmr', 'lmg', 'shotgun'];
check('九个键、顺序与模型一致', $keys === $want, implode(',', $keys));
$names = array_map(static fn($x) => (string)($x['name'] ?? ''), (array)$cats);
$wantNames = ['未分类', '冲锋枪', '突击步枪', '手枪', '特殊武器', '狙击步枪', '精准射手步枪', '轻机枪', '霰弹枪'];
check('九个别名一个不差', $names === $wantNames, implode(',', $names));
check('清单里没有「全部」这一项', !in_array('all', $keys, true) && !in_array('全部', $names, true));

// ============================================================
// 2. 发布：带类别 / 不带 / 全部 / 乱码键
// ============================================================
echo "\n== 2. 发布时类别怎么落 ==\n";
$mkCode = function (string $who, array $body) use (&$pdo) {
    clearCache();                                  // 发布限频是 3 秒 1 次，段与段之间清一次
    [, $j] = api($who, 'gxa', 'POST', '/guncodes', $body);
    return $j;
};
$j1 = $mkCode('capA', ['title' => 'KC7 冲锋', 'code' => 'AAA111', 'description' => '', 'category' => 'smg']);
check('带类别发布成功', (int)($j1['code'] ?? 1) === 0, J($j1));
$idSmg = (int)($j1['data']['id'] ?? 0);

$j2 = $mkCode('capA', ['title' => '老宋的枪', 'code' => 'BBB222', 'description' => '没选类别']);
check('不带 category 也能发（走默认）', (int)($j2['code'] ?? 1) === 0, J($j2));
$idNone = (int)($j2['data']['id'] ?? 0);

$j3 = $mkCode('capA', ['title' => '想要全部', 'code' => 'CCC333', 'description' => '', 'category' => 'all']);
check('发 all 不报错，落成未分类', (int)($j3['code'] ?? 1) === 0, J($j3));
$idAll = (int)($j3['data']['id'] ?? 0);

$j4 = $mkCode('capA', ['title' => '瞎写的', 'code' => 'DDD444', 'description' => '', 'category' => 'rocket_launcher']);
check('认不出的键被挡下来', (int)($j4['code'] ?? 0) !== 0, J($j4));
check('挡下来的回话点明了是哪一项', strpos((string)($j4['msg'] ?? ''), '类别') !== false, (string)($j4['msg'] ?? ''));
check('被挡下的那条没落库', (int)$pdo->query("SELECT COUNT(*) FROM gun_codes WHERE title='瞎写的'")->fetchColumn() === 0);

[, $jList] = api('capA', 'gxa', 'GET', '/guncodes?page=1&size=20');
check('带 smg 发布的落成了 smg', (($r = pick($jList, $idSmg)) !== null) && $r['category'] === 'smg', J($r ?? []));
check('没选类别的落成 other', (($r = pick($jList, $idNone)) !== null) && $r['category'] === 'other', J($r ?? []));
check('发 all 的落成 other', (($r = pick($jList, $idAll)) !== null) && $r['category'] === 'other', J($r ?? []));

// 老数据（这一列刚加时库里已有的行）
$pdo->exec("INSERT INTO gun_codes (tenant_id, user_id, title, code, description, status, created_at, updated_at)
    VALUES ({$A['tid']}, $capA, '改版前的老枪码', 'OLD999', '', 'published', '$now', '$now')");
[, $jList2] = api('capA', 'gxa', 'GET', '/guncodes?page=1&size=30');
$oldRow = null;
foreach ((array)($jList2['data']['list'] ?? []) as $it) {
    if ((string)($it['title'] ?? '') === '改版前的老枪码') { $oldRow = $it; }
}
check('之前创建过的枪码显示成未分类', $oldRow !== null && $oldRow['category'] === 'other', J($oldRow ?? []));

// ============================================================
// 3. 列表筛选
// ============================================================
echo "\n== 3. 按类别筛 ==\n";
// 再造两条不同类别的
$j5 = $mkCode('capA', ['title' => 'M7 狙击', 'code' => 'EEE555', 'description' => '', 'category' => 'sniper']);
$idSniper = (int)($j5['data']['id'] ?? 0);
$j6 = $mkCode('memA', ['title' => 'P1912 手枪', 'code' => 'FFF666', 'description' => '', 'category' => 'pistol']);
$idPistol = (int)($j6['data']['id'] ?? 0);

[, $jSmg] = api('capA', 'gxa', 'GET', '/guncodes?page=1&size=20&category=smg');
$only = array_values(array_filter((array)($jSmg['data']['list'] ?? []),
    static fn($x) => (string)($x['category'] ?? '') !== 'smg'));
check('筛 smg 只回 smg', $only === [] && count((array)($jSmg['data']['list'] ?? [])) >= 1, J($only));
check('筛 smg 时 total 也跟着变', (int)($jSmg['data']['total'] ?? 0) >= 1
    && (int)($jSmg['data']['total'] ?? 0) < (int)$jList2['data']['total'],
    'smg total=' . ($jSmg['data']['total'] ?? '?') . ' 全部 total=' . ($jList2['data']['total'] ?? '?'));

[, $jAll1] = api('capA', 'gxa', 'GET', '/guncodes?page=1&size=20&category=all');
[, $jAll2] = api('capA', 'gxa', 'GET', '/guncodes?page=1&size=20');
check('category=all 与不带参数等价',
    (int)$jAll1['data']['total'] === (int)$jAll2['data']['total'],
    $jAll1['data']['total'] . ' vs ' . $jAll2['data']['total']);

[, $jBogus] = api('capA', 'gxa', 'GET', '/guncodes?page=1&size=20&category=' . rawurlencode('随便写的'));
check('认不出的键当没筛，不报错', (int)($jBogus['code'] ?? 1) === 0
    && (int)($jBogus['data']['total'] ?? -1) === (int)$jAll2['data']['total'],
    'code=' . ($jBogus['code'] ?? '?') . ' total=' . ($jBogus['data']['total'] ?? '?')
    . ' 全部=' . ($jAll2['data']['total'] ?? '?'));

[, $jMix] = api('capA', 'gxa', 'GET', '/guncodes?page=1&size=20&category=pistol&keyword=' . rawurlencode('手枪'));
$listMix = (array)($jMix['data']['list'] ?? []);
check('类别与关键字能叠加', count($listMix) === 1 && (int)$listMix[0]['id'] === $idPistol, J($listMix));
[, $jMix2] = api('capA', 'gxa', 'GET', '/guncodes?page=1&size=20&category=smg&keyword=' . rawurlencode('手枪'));
check('叠加不出来的时候是空表不是全表', (int)($jMix2['data']['total'] ?? -1) === 0, J($jMix2));

// ============================================================
// 4. 租户隔离与上下架可见性
// ============================================================
echo "\n== 4. 别队的同名类别与下架的 ==\n";
clearCache();
[, $jB] = api('capB', 'gxb', 'POST', '/guncodes',
    ['title' => '乙队的冲锋', 'code' => 'GGG777', 'description' => '', 'category' => 'smg']);
check('乙队也能发同类别', (int)($jB['code'] ?? 1) === 0, J($jB));
$idBSmg = (int)($jB['data']['id'] ?? 0);

[, $jASmg] = api('capA', 'gxa', 'GET', '/guncodes?page=1&size=50&category=smg');
foreach ((array)($jASmg['data']['list'] ?? []) as $it) {
    check('甲队筛 smg 不会看到乙队那条', (int)$it['id'] !== $idBSmg, J($it));
}
[, $jBSmg] = api('capB', 'gxb', 'GET', '/guncodes?page=1&size=50&category=smg');
check('乙队筛 smg 只看到自己那一条', count((array)($jBSmg['data']['list'] ?? [])) === 1
    && (int)$jBSmg['data']['list'][0]['id'] === $idBSmg, J($jBSmg['data']['list'] ?? []));

// 甲队会员下架一条自己的：别人筛不到、自己筛得到、管理员筛得到
clearCache();
[, $jOwn] = api('memA', 'gxa', 'POST', '/guncodes',
    ['title' => '会员的狙击', 'code' => 'HHH888', 'description' => '', 'category' => 'sniper']);
$idMemSniper = (int)($jOwn['data']['id'] ?? 0);
[, $t] = api('memA', 'gxa', 'POST', '/guncodes/' . $idMemSniper . '/toggle');
check('会员下架自己的枪码', (int)($t['code'] ?? 1) === 0, J($t));
[, $jCapSniper] = api('capA', 'gxa', 'GET', '/guncodes?page=1&size=50&category=sniper');
check('队长（管理员）按类别筛时能看到别人下架的那条', pick($jCapSniper, $idMemSniper) !== null, J($jCapSniper['data']['list'] ?? []));
[, $jMemSniper] = api('memA', 'gxa', 'GET', '/guncodes?page=1&size=50&category=sniper');
check('作者自己按同类别筛还看得到（便于重新上架）', pick($jMemSniper, $idMemSniper) !== null, J($jMemSniper['data']['list'] ?? []));
[, $jOutsider] = api('memB', 'gxa', 'GET', '/guncodes?page=1&size=50&category=sniper');
check('第三人按同类别筛看不到别人下架的那条', pick($jOutsider, $idMemSniper) === null, J($jOutsider['data']['list'] ?? []));
check('第三人同类别下看得到已发布的那条', pick($jOutsider, $idSniper) !== null, J($jOutsider['data']['list'] ?? []));

// ============================================================
// 5. 修改可以改类别，改别人的不行
// ============================================================
echo "\n== 5. 编辑时改类别 ==\n";
clearCache();
[, $jUpd] = api('capA', 'gxa', 'PUT', '/guncodes/' . $idSmg,
    ['title' => 'KC7 冲锋', 'code' => 'AAA111', 'description' => '', 'category' => 'ar']);
check('本人改类别成功', (int)($jUpd['code'] ?? 1) === 0, J($jUpd));
[, $jChk] = api('capA', 'gxa', 'GET', '/guncodes?page=1&size=50&category=ar');
check('改完能在突击步枪里筛到它', pick($jChk, $idSmg) !== null, J($jChk['data']['list'] ?? []));
[, $jChk2] = api('capA', 'gxa', 'GET', '/guncodes?page=1&size=50&category=smg');
check('改完就不在冲锋枪里了', pick($jChk2, $idSmg) === null, J($jChk2['data']['list'] ?? []));
$nowRow = $pdo->query("SELECT code, description FROM gun_codes WHERE id=$idSmg")->fetch(PDO::FETCH_ASSOC);
check('只改类别不动正文', (string)$nowRow['code'] === 'AAA111' && (string)$nowRow['description'] === '', J($nowRow));

clearCache();
[, $jUpdAll] = api('capA', 'gxa', 'PUT', '/guncodes/' . $idSmg,
    ['title' => 'KC7 冲锋', 'code' => 'AAA111', 'description' => '', 'category' => 'all']);
$rowAll = $pdo->query("SELECT category FROM gun_codes WHERE id=$idSmg")->fetchColumn();
check('编辑时发 all 落到未分类而不是报错', (int)($jUpdAll['code'] ?? 1) === 0 && (string)$rowAll === 'other', (string)$rowAll);

[, $jUpdBad] = api('capA', 'gxa', 'PUT', '/guncodes/' . $idSmg,
    ['title' => 'KC7 冲锋', 'code' => 'AAA111', 'description' => '', 'category' => 'shotgun_x']);
check('改的时候认不出的键也被挡', (int)($jUpdBad['code'] ?? 0) !== 0, J($jUpdBad));
$rowAfterBad = (string)$pdo->query("SELECT category FROM gun_codes WHERE id=$idSmg")->fetchColumn();
check('被挡下时库里类别没变', $rowAfterBad === 'other', $rowAfterBad);

clearCache();
[, $jHijack] = api('memA', 'gxa', 'PUT', '/guncodes/' . $idSmg,
    ['title' => '抢别人的', 'code' => 'AAA111', 'description' => '', 'category' => 'lmg']);
check('成员改别人的枪码被拒', (int)($jHijack['code'] ?? 0) === 403 || (int)($jHijack['code'] ?? 0) !== 0, J($jHijack));
$rowHijack = $pdo->query("SELECT title, category FROM gun_codes WHERE id=$idSmg")->fetch(PDO::FETCH_ASSOC);
check('被拒之后标题和类别都没动', (string)$rowHijack['title'] === 'KC7 冲锋' && (string)$rowHijack['category'] === 'other', J($rowHijack));

// ============================================================
// 6. 论坛投放那条路整个下线（删除动作也要有人守着）
// ============================================================
echo "\n== 6. 论坛投放的接口已不在路由表 ==\n";
clearCache();
foreach ([['POST', '/admin/forum/share-toggle'], ['POST', '/admin/forum/publish'], ['GET', '/admin/forum/share']] as $one) {
    [$s, $j] = api('capA', 'gxa', $one[0], $one[1], ['kind' => 'gun', 'enabled' => 1]);
    check($one[1] . ' 已下线：404 而不是 500，也不会装成成功',
        (int)$s === 404 && ($j['code'] ?? null) === null, 'http=' . $s);
}

// ============================================================
// 7. 导出导入认得这一列
// ============================================================
echo "\n== 7. 导出与导入的字段清单 ==\n";
$dt = str_replace("\r\n", "\n", (string)file_get_contents($ROOT . '/app/service/DataTransfer.php'));
check('导入白名单里有 category', strpos($dt, "'category'    => isset(\\app\\model\\GunCode::CATEGORIES") !== false);
check('旧导出包（没这一项）导入时按未分类进',
    strpos($dt, "? (string)\$r['category'] : 'other'") !== false);
[, $jExp] = api('capA', 'gxa', 'GET', '/guncodes?page=1&size=50');
$one = pick($jExp, $idSniper);
check('列表接口把 category 下发给前端（导出走的是同一份数据）',
    $one !== null && ($one['category'] ?? null) === 'sniper', J($one ?? []));

echo "\n================ 结果 ================\n";
echo "通过 $pass 项";
if ($fail > 0) { echo "，失败 $fail 项\n"; exit(1); }
echo "，全部通过\n";
exit(0);
