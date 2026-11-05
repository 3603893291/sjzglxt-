<?php
/**
 * 数据包导入审核（v5.36.33 起有这一套）功能 E2E
 *
 * 前置：.env 指向 sqlite 夹具，测试服务器已启动
 *   php runtime/_env.php fixture
 *   php scripts/dev_init_sqlite.php runtime/test.sqlite
 *   php -S 127.0.0.1:8899 -t public public/router.php
 * 用法：php scripts/test_data_transfer_e2e.php
 *
 * 覆盖：
 *   1. 整条审核链：甲队申请导出 → 平台同意生成包 → 乙队上传这个包申请导入 → 平台同意执行覆盖导入
 *   2. 导入的归属：带进来的人全部降为普通成员，乙队队长还是 admin（不会被外站包夺权）
 *   3. v5.36.33 那一处修复：撞名的图片必须「落在哪就写哪」——
 *      乙队/丙队的行指向的是自己那份真副本，不再是甲队的文件；盘上零孤儿
 *   4. 封禁与导入互不干扰（口径 2026-11-04 拍板）：封禁中不能再提交新导入，
 *      但封禁前提交、封禁后才批准的那张照旧执行；banned_at 与销毁倒计时一个字都不动
 *   5. 销毁：导入进来的图片按引用删得掉；甲队注销后它的原图不再被任何人引用，一张都不留
 *   6. 站点早没了、单子还挂着：列表不 500、点同意说人话且不写一行数据、失败不删上传包、还能驳回
 *   7. 孤儿巡检页 public/orphan_clean_v53633.php：未登录不吐信息、列得准、
 *      码错/张数不符/盘上数目变了 三种都不动手，全对才删并且自删（原件留着）
 *
 * 这个套件会在 public/storage/uploads/_dttest/ 下写真文件，结束时只删自己建的那些。
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

$ROOT = dirname(__DIR__);
$BASE = rtrim((string)(getenv('TEST_BASE') ?: 'http://127.0.0.1:8899'), '/');
$DB   = $ROOT . '/runtime/test.sqlite';

$pass = 0; $fail = 0; $fails = [];
function check(string $name, bool $ok, string $detail = ''): void {
    global $pass, $fail, $fails;
    if ($ok) { $pass++; echo "  [PASS] $name\n"; }
    else { $fail++; $fails[] = $name; echo "  [FAIL] $name" . ($detail !== '' ? " —— $detail" : '') . "\n"; }
}

// ---------- HTTP ----------
$jars = [];
function jar(string $w): array { global $jars; return $jars[$w] ?? []; }
function put_jar(string $w, array $c): void { global $jars; $jars[$w] = $c; }
function take_cookies(array $hs, array &$c): void {
    foreach ($hs as $h) if (preg_match('/^Set-Cookie:\s*([^=]+)=([^;]*)/i', $h, $m)) $c[$m[1]] = trim($m[2]);
}
function cookie_hdr(array &$c): string {
    if (!$c) return '';
    $p = []; foreach ($c as $k => $v) $p[] = $k . '=' . $v;
    return 'Cookie: ' . implode('; ', $p) . "\r\n";
}
function status_of(array $hs): int {
    $s = 0; foreach ($hs as $h) if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) $s = (int)$m[1];
    return $s;
}
function send(string $who, string $method, string $url, string $body, string $contentType): array {
    $c = jar($who);
    $hdr = "Content-Type: $contentType\r\n" . cookie_hdr($c);
    $opts = ['http' => ['method' => $method, 'header' => $hdr, 'content' => $body,
                        'ignore_errors' => true, 'timeout' => 180]];
    $raw = (string)@file_get_contents($url, false, stream_context_create($opts));
    $hs = $http_response_header ?? []; take_cookies($hs, $c); put_jar($who, $c);
    return [status_of($hs), $raw];
}
function as_req(string $w, string $method, string $path, ?array $body = null): array {
    global $BASE;
    [$s, $raw] = send($w, $method, $BASE . $path, $body === null ? '' : json_encode($body, JSON_UNESCAPED_UNICODE),
        'application/json');
    return [$s, (array)json_decode($raw, true), $raw];
}
function as_form(string $w, string $path, array $fields): array {
    global $BASE;
    return send($w, 'POST', $BASE . $path, http_build_query($fields), 'application/x-www-form-urlencoded');
}
function as_upload(string $w, string $path, string $field, string $absPath): array {
    global $BASE;
    $b = '----dtt' . bin2hex(random_bytes(8));
    $body = "--$b\r\nContent-Disposition: form-data; name=\"$field\"; filename=\"" . basename($absPath)
        . "\"\r\nContent-Type: application/zip\r\n\r\n" . (string)file_get_contents($absPath) . "\r\n--$b--\r\n";
    [$s, $raw] = send($w, 'POST', $BASE . $path, $body, "multipart/form-data; boundary=$b");
    return [$s, (array)json_decode($raw, true), $raw];
}
function J($a): string { return (string)json_encode($a, JSON_UNESCAPED_UNICODE); }
/** 断言磁盘用这个：web 路径 → 绝对路径，并且绕开 PHP 的 stat 缓存（删除发生在服务器那个进程里） */
function onDisk(string $web): bool {
    clearstatcache(true);
    return is_file(dirname(__DIR__) . '/public' . $web);
}

// ---------- 库 ----------
function db(): PDO {
    global $DB;
    $p = new PDO('sqlite:' . $DB);
    $p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $p->setAttribute(PDO::ATTR_TIMEOUT, 5);
    $p->exec('PRAGMA journal_mode=WAL');
    return $p;
}
function q(string $sql, array $b = []): array { $st = db()->prepare($sql); $st->execute($b); return $st->fetchAll(PDO::FETCH_ASSOC); }
function val(string $sql, array $b = []) { $st = db()->prepare($sql); $st->execute($b); return $st->fetchColumn(); }
function cnt(string $sql, array $b = []): int { return (int)val($sql, $b); }

/** 全站量引用用的那 15 处（与 TenantPurge::referencedNames() 同一份） */
function refCols(): array {
    return [['users', 'profile_image'], ['images', 'file'], ['guides', 'image'],
            ['announcements', 'image'], ['showcase_items', 'image'], ['show_submissions', 'image'],
            ['password_resets', 'screenshot'], ['training_rounds', 'evidence'],
            ['battle_rounds', 'evidence'], ['tenants', 'logo'], ['tenant_applications', 'logo'],
            ['tenant_applications', 'screenshot'], ['ai_stickers', 'path'],
            ['rotation_maps', 'image'], ['settings', 'value']];
}
/** 全站还在被引用的图片文件名 */
function referencedNames(): array {
    $set = [];
    foreach (refCols() as [$t, $c]) {
        try {
            foreach (q("SELECT $c AS v FROM $t WHERE $c LIKE '%/storage/uploads/%'") as $r) {
                $v = trim((string)$r['v']);
                if ($v === '') continue;
                $base = basename(str_replace('\\', '/', $v));
                if ($base !== '') $set[$base] = true;
            }
        } catch (\Throwable $e) { /* 这张表不在这个库里就当没有 */ }
    }
    return array_keys($set);
}
/** 哪个表的哪一行还写着这个文件名（失败时给个人看） */
function whoRefs(string $webPath): string {
    $base = basename(str_replace('\\', '/', $webPath));
    $hits = [];
    foreach (refCols() as [$t, $c]) {
        try {
            foreach (q("SELECT id, $c AS v FROM $t WHERE $c LIKE ?", ['%' . $base . '%']) as $r) {
                $hits[] = $t . '#' . $r['id'];
            }
        } catch (\Throwable $e) {
        }
    }
    return $hits === [] ? '没人引用（那就是没删掉）' : implode(' | ', $hits);
}
/** 演练目录下没人引用的图片 */
function orphansIn(string $dir): array {
    $map = []; foreach (referencedNames() as $n) $map[$n] = true;
    $out = [];
    foreach (glob($dir . '/*.jpg') ?: [] as $f) {
        if (!isset($map[basename((string)$f)])) $out[] = basename((string)$f);
    }
    return $out;
}

$now = date('Y-m-d H:i:s');
$ago = static fn(int $d): string => date('Y-m-d H:i:s', time() - $d * 86400);

// ============================================================
echo "== 0. 重建夹具与种子 ==\n";
@unlink($DB);
echo (string)shell_exec('"' . PHP_BINARY . '" "' . $ROOT . '/scripts/dev_init_sqlite.php" "' . $DB . '" 2>&1');
foreach (array_merge(glob($ROOT . '/runtime/cache/*/*') ?: [], glob($ROOT . '/runtime/cache/*/*/*') ?: []) as $f) {
    if (is_file($f)) @unlink($f);
}

$UP = $ROOT . '/public/storage/uploads/_dttest';
@mkdir($UP . '/images', 0777, true);
$made = [];
$mk = static function (string $rel, string $tag) use ($UP, &$made): string {
    $abs = $UP . '/' . $rel;
    file_put_contents($abs, $tag . '-' . str_repeat('X', 4096));
    $made[] = $abs;
    return '/storage/uploads/_dttest/' . $rel;
};
$F_A = $mk('images/aaa.jpg', 'SRC-A');
$F_B = $mk('images/bbb.jpg', 'SRC-B');
$F_P = $mk('images/ppp.jpg', 'SRC-P');

$hash = password_hash('pass123456', PASSWORD_DEFAULT);
$pdo = db();
$mkPU = static function (string $u) use ($pdo, $now, $hash): int {
    $pdo->exec("INSERT INTO platform_users (username,password,nickname,role,status,created_at,updated_at)
                VALUES ('$u','$hash','导入审核演练','team','normal','$now','$now')");
    return (int)$pdo->lastInsertId();
};
$mkTenant = static function (int $applicant, string $slug, string $name) use ($pdo, $now): int {
    $pdo->exec("INSERT INTO tenants (applicant_id,slug,name,logo,status,banned_at,admin_username,created_at,updated_at)
                VALUES ($applicant,'$slug','$name','','approved',NULL,'$slug','$now','$now')");
    return (int)$pdo->lastInsertId();
};
$mkUser = static function (int $tid, string $u, string $nick, string $img = '', string $role = 'member') use ($pdo, $now, $hash): int {
    $pdo->exec("INSERT INTO users (tenant_id,username,password,nickname,profile_image,role,status,admin_perms,
                                   show_public,stats_public,created_at,updated_at)
                VALUES ($tid,'$u','$hash','$nick','$img','$role','approved','',1,1,'$now','$now')");
    return (int)$pdo->lastInsertId();
};
$mkImage = static function (int $tid, int $uid, string $file, string $title) use ($pdo, $now): int {
    $pdo->exec("INSERT INTO images (tenant_id,user_id,title,file,status,created_at,updated_at)
                VALUES ($tid,$uid,'$title','$file','approved','$now','$now')");
    return (int)$pdo->lastInsertId();
};

$puSrc = $mkPU('src_cap'); $SRC = $mkTenant($puSrc, 'src_cap', '甲队');
$puDst = $mkPU('dst_cap'); $DST = $mkTenant($puDst, 'dst_cap', '乙队');
$srcOwner = $mkUser($SRC, 'src_cap', '甲队长', $F_P, 'admin');
for ($i = 2; $i <= 6; $i++) $mkUser($SRC, 'src_m' . $i, '甲成员' . $i);
$dstOwner = $mkUser($DST, 'dst_cap', '乙队长', '', 'admin');
$mkImage($SRC, $srcOwner, $F_A, '甲图一');
$mkImage($SRC, $srcOwner, $F_B, '甲图二');
// v5.36.34：两条公告带进包里 —— 一条定时还没到点、一条已经撤回，导入之后必须还是这个样子
$annSched = date('Y-m-d H:i:s', time() + 3 * 86400);
$annRevok = date('Y-m-d H:i:s', time() - 60);
$insAnn = static function (int $tid, string $title, ?string $sched, ?string $rev) use ($pdo, $now): void {
    $s = $sched === null ? 'NULL' : "'$sched'";
    $r = $rev   === null ? 'NULL' : "'$rev'";
    $pdo->exec("INSERT INTO announcements (tenant_id,title,content,image,scheduled_at,revoked_at,created_at,updated_at)
                VALUES ($tid,'$title','正文','',$s,$r,'$now','$now')");
};
$insAnn($SRC, '甲队定时公告', $annSched, null);
$insAnn($SRC, '甲队撤回公告', null, $annRevok);
echo "  甲队 #$SRC（6 人 2 图 2 公告：一条未到点、一条已撤回）／乙队 #$DST（1 人 0 图）\n";

// ============================================================
echo "\n== 1. 甲队申请导出 → 平台同意生成包 ==\n";
[$s, $j] = as_req('plat', 'POST', '/api/platform/auth/login', ['username' => 'admin', 'password' => 'admin123']);
check('平台超管登录', $s === 200 && (int)($j['code'] ?? 1) === 0, J($j));
[$s, $j] = as_req('src', 'POST', '/api/platform/auth/login', ['username' => 'src_cap', 'password' => 'pass123456']);
check('甲队队长登录平台侧', $s === 200 && (int)($j['code'] ?? 1) === 0, J($j));
[$s, $j] = as_req('src', 'POST', '/api/platform/my/data/export');
$expTask = (int)($j['data']['id'] ?? 0);
check('甲队提交导出申请', (int)($j['code'] ?? 1) === 0 && $expTask > 0, J($j));
[, $j] = as_req('plat', 'POST', "/api/platform/admin/data/$expTask/approve");
check('平台同意导出', (int)($j['code'] ?? 1) === 0, J($j));
$expFile = (string)val("SELECT file FROM tenant_data_tasks WHERE id=$expTask");
check('导出包真落盘（runtime/data_tasks 里）', $expFile !== '' && is_file($expFile), $expFile);

// ============================================================
echo "\n== 2. 乙队上传这个包申请导入 ==\n";
[$s, $j] = as_req('dst', 'POST', '/api/platform/auth/login', ['username' => 'dst_cap', 'password' => 'pass123456']);
check('乙队队长登录平台侧', $s === 200 && (int)($j['code'] ?? 1) === 0, J($j));
[$s, $j, $raw] = as_upload('dst', '/api/platform/my/data/import', 'file', $expFile);
$impTask = (int)($j['data']['id'] ?? 0);
check('乙队提交导入申请', $s === 200 && (int)($j['code'] ?? 1) === 0 && $impTask > 0, mb_substr($raw, 0, 160));
[$s, $j] = as_req('plat', 'POST', '/api/platform/auth/login', ['username' => 'admin', 'password' => 'admin123']);
[$s, $j] = as_req('plat', 'GET', '/api/platform/admin/data/tasks');
$inList = false;
foreach ((array)($j['data']['list'] ?? []) as $r) if ((int)$r['id'] === $impTask) $inList = true;
check('审核列表里能看到这张待审的导入单', $inList, J($j));

// ============================================================
echo "\n== 3. 平台点同意：覆盖导入执行 ==\n";
[, $j] = as_req('plat', 'POST', "/api/platform/admin/data/$impTask/approve");
check('导入成功（界面那句「导入完成」）', (int)($j['code'] ?? 1) === 0
    && str_contains((string)($j['msg'] ?? ''), '导入完成'), J($j));
check('乙队成员变成 7 人（甲队的 6 个进来了）', cnt("SELECT COUNT(*) FROM users WHERE tenant_id=$DST") === 7,
    (string)cnt("SELECT COUNT(*) FROM users WHERE tenant_id=$DST"));
check('带进来的人一律降为普通成员（不许外站包塞第二个超管）',
    cnt("SELECT COUNT(*) FROM users WHERE tenant_id=$DST AND role='admin'") === 1);
check('乙队队长还是 admin，没被夺权', (string)val("SELECT role FROM users WHERE id=$dstOwner") === 'admin');
$dstImg = array_map(static fn($r) => (string)$r['file'], q("SELECT file FROM images WHERE tenant_id=$DST ORDER BY id"));
check('乙队导入后有两张图', count($dstImg) === 2, J($dstImg));
$allExist = true;
foreach ($dstImg as $w) if (!onDisk($w)) $allExist = false;
check('乙队行里写的路径，文件真的在盘上（v5.36.33 这一处修复）', $allExist, J($dstImg));
check('乙队的图是自己那一份，不再是甲队盘上的同名文件',
    !array_intersect($dstImg, [$F_A, $F_B]), J($dstImg) . ' vs ' . J([$F_A, $F_B]));
// v5.36.34：定时与撤回这两列必须跟着包走 —— 不能导完就把队长撤掉的东西发给成员，也不能把还没到点的提前发
$impSched = (string)val("SELECT scheduled_at FROM announcements WHERE tenant_id=$DST AND title='甲队定时公告'");
$impRev   = (string)val("SELECT revoked_at FROM announcements WHERE tenant_id=$DST AND title='甲队撤回公告'");
check('导入把「还没到点」的定时原样带过去（时刻还是三天后，不是导完就发）',
    $impSched === $annSched && strtotime($impSched) > time(), '库里=' . J($impSched));
check('导入把「已撤回」原样带过去（不是借导入重新发布给成员）',
    $impRev === $annRevok, '库里=' . J($impRev));
check('两条都进了乙队的公告表（行数对得上）',
    cnt("SELECT COUNT(*) FROM announcements WHERE tenant_id=$DST") === 2,
    '实际=' . cnt("SELECT COUNT(*) FROM announcements WHERE tenant_id=$DST"));
$dstProf = (string)val("SELECT profile_image FROM users WHERE tenant_id=$DST AND username='src_cap'");
check('连成员头像也写的是实际落盘那一条', $dstProf !== '' && onDisk($dstProf)
    && $dstProf !== $F_P, $dstProf);
$orphan = orphansIn($UP . '/images');
check('盘上零孤儿：没有任何图片不被数据库引用', $orphan === [], implode(' , ', $orphan));

// ============================================================
echo "\n== 4. 同一个包再导入丙队，丙队隔天再导一次（撞名最密的走法）==\n";
$puThd = $mkPU('thd_cap'); $THD = $mkTenant($puThd, 'thd_cap', '丙队');
$mkUser($THD, 'thd_cap', '丙队长', '', 'admin');
[$s, $j] = as_req('thd', 'POST', '/api/platform/auth/login', ['username' => 'thd_cap', 'password' => 'pass123456']);
check('丙队队长登录平台侧', (int)($j['code'] ?? 1) === 0, J($j));
[$s, $j, $raw] = as_upload('thd', '/api/platform/my/data/import', 'file', $expFile);
$impTask3 = (int)($j['data']['id'] ?? 0);
check('丙队提交导入申请', (int)($j['code'] ?? 1) === 0 && $impTask3 > 0, mb_substr($raw, 0, 160));
[, $j] = as_req('plat', 'POST', "/api/platform/admin/data/$impTask3/approve");
check('丙队导入成功', (int)($j['code'] ?? 1) === 0, J($j));
$pdo->exec("UPDATE tenant_data_tasks SET created_at='" . $ago(2) . "' WHERE tenant_id=$THD");
[$s, $j, $raw] = as_upload('thd', '/api/platform/my/data/import', 'file', $expFile);
$impTask2 = (int)($j['data']['id'] ?? 0);
check('丙队第二次提交同一个包（同队对自己导一次）',
    (int)($j['code'] ?? 1) === 0 && $impTask2 > 0, mb_substr($raw, 0, 160));
[, $j] = as_req('plat', 'POST', "/api/platform/admin/data/$impTask2/approve");
check('第二次导入也成功', (int)($j['code'] ?? 1) === 0, J($j));
$thdImg = array_map(static fn($r) => (string)$r['file'], q("SELECT file FROM images WHERE tenant_id=$THD ORDER BY id"));
$exists = true; foreach ($thdImg as $w) if (!onDisk($w)) $exists = false;
check('丙队的两张图存在且是它自己那份', $exists && !array_intersect($thdImg, [$F_A, $F_B]), J($thdImg));
check('三家各导各的，互相不共用一个文件',
    count(array_intersect($thdImg, $dstImg)) === 0, J($thdImg) . ' vs ' . J($dstImg));
check('导了三次，盘上仍然零孤儿', orphansIn($UP . '/images') === [], implode(' , ', orphansIn($UP . '/images')));

// ============================================================
echo "\n== 5. 封禁与导入互不干扰（2026-11-04 拍板：保持这样）==\n";
$pdo->exec("UPDATE tenant_data_tasks SET created_at='" . $ago(2) . "' WHERE tenant_id=$DST");
[$s, $j, $raw] = as_upload('dst', '/api/platform/my/data/import', 'file', $expFile);
check('乙队这张新的导入单提交上了', (int)($j['code'] ?? 1) === 0, mb_substr($raw, 0, 160));
$impTask4 = (int)($j['data']['id'] ?? 0);
[$s, $j] = as_req('plat', 'POST', "/api/platform/admin/tenants/$DST/status", ['status' => 'banned']);
check('平台封禁乙队', (int)($j['code'] ?? 1) === 0, J($j));
$baBanned = (string)val("SELECT banned_at FROM tenants WHERE id=$DST");
check('封禁把起点写上了', $baBanned !== '' && abs(strtotime($baBanned) - time()) < 120, $baBanned);
$pdo->exec("UPDATE tenants SET banned_at='" . $ago(16) . "' WHERE id=$DST");
$baBefore = (string)val("SELECT banned_at FROM tenants WHERE id=$DST");
[$s, $j, $raw] = as_upload('dst', '/api/platform/my/data/import', 'file', $expFile);
check('封禁中的队不能再提交新导入', (int)($j['code'] ?? 0) !== 0
    && str_contains((string)($j['msg'] ?? ''), '状态不允许'), mb_substr($raw, 0, 160));
[, $j] = as_req('plat', 'POST', "/api/platform/admin/data/$impTask4/approve");
check('封禁前提交、封禁后才批准的那张：照旧执行导入（这条是拍板口径）', (int)($j['code'] ?? 1) === 0, J($j));
$dstImg2 = array_map(static fn($r) => (string)$r['file'], q("SELECT file FROM images WHERE tenant_id=$DST ORDER BY id"));
$firstGone = true;
foreach ($dstImg as $w) if (onDisk($w)) $firstGone = false;
check('第二次导入把上一批换下来的文件也删了（清场不只删行）', $firstGone, J($dstImg));
$secondOk = true;
foreach ($dstImg2 as $w) if (!onDisk($w)) $secondOk = false;
check('这一批新的两张照样存在且写的是自己那份', $secondOk && !array_intersect($dstImg2, [$F_A, $F_B]), J($dstImg2));
check('换完一轮之后盘上仍然零孤儿', orphansIn($UP . '/images') === [], implode(' , ', orphansIn($UP . '/images')));
check('导入没把封禁起点洗掉', (string)val("SELECT banned_at FROM tenants WHERE id=$DST") === $baBefore,
    $baBefore . ' → ' . (string)val("SELECT banned_at FROM tenants WHERE id=$DST"));
check('导入没顺手解封', (string)val("SELECT status FROM tenants WHERE id=$DST") === 'banned');
$byId = [];
[, $jj] = as_req('plat', 'GET', '/api/platform/admin/tenants');
foreach ((array)($jj['data']['list'] ?? []) as $t) $byId[(int)$t['id']] = $t;
$purge = (array)($byId[$DST]['purge'] ?? []);
check('导入之后销毁口径照旧：封满 15 天，可以销毁',
    ($purge['ready'] ?? false) === true && (string)($purge['reason'] ?? '') === '封满 15 天，可以销毁', J($purge));
check('导入之后盘上仍然零孤儿', orphansIn($UP . '/images') === [], implode(' , ', orphansIn($UP . '/images')));

// ============================================================
echo "\n== 6. 销毁：导入进来的图片删得掉，甲队的原图也一张不留 ==\n";
$dstBefore = $dstImg2;
[, $j] = as_req('plat', 'POST', "/api/platform/admin/tenants/$DST/purge-preview");
$tok = (string)($j['data']['token'] ?? '');
$pre = (array)($j['data']['preview'] ?? []);
check('预览的行数图片数是导入之后的数', (int)($pre['file_count'] ?? 0) >= 3, J($pre));
[, $j] = as_req('plat', 'POST', "/api/platform/admin/tenants/$DST/purge", ['confirm_slug' => 'dst_cap', 'token' => $tok]);
check('销毁乙队成功', (int)($j['code'] ?? 1) === 0, J($j));
$dstLeft = [];
foreach ($dstBefore as $w) if (onDisk($w)) $dstLeft[] = $w . ' ← ' . whoRefs($w);
check('乙队那两张导入进来的图，跟着删干净了', $dstLeft === [], implode(' ； ', $dstLeft));
check('乙队的审核单也跟着清', cnt("SELECT COUNT(*) FROM tenant_data_tasks WHERE tenant_id=$DST") === 0);

$srcImg = array_map(static fn($r) => (string)$r['file'], q("SELECT file FROM images WHERE tenant_id=$SRC ORDER BY id"));
$pdo->exec("UPDATE tenants SET status='banned', banned_at='" . $ago(16) . "' WHERE id=$SRC");
[, $j] = as_req('plat', 'POST', "/api/platform/admin/tenants/$SRC/purge-preview");
$tok2 = (string)($j['data']['token'] ?? '');
[, $j] = as_req('plat', 'POST', "/api/platform/admin/tenants/$SRC/purge", ['confirm_slug' => 'src_cap', 'token' => $tok2]);
check('销毁甲队成功', (int)($j['code'] ?? 1) === 0, J($j));
$srcGone = true;
foreach ($srcImg as $w) if (onDisk($w)) $srcGone = false;
check('甲队自己的原图没了（不再被别队的行共用，v5.36.33 之后才是这个结果）', $srcGone, J($srcImg));

$pdo->exec("UPDATE tenants SET status='banned', banned_at='" . $ago(16) . "' WHERE id=$THD");
[, $j] = as_req('plat', 'POST', "/api/platform/admin/tenants/$THD/purge-preview");
$tok3 = (string)($j['data']['token'] ?? '');
[, $j] = as_req('plat', 'POST', "/api/platform/admin/tenants/$THD/purge", ['confirm_slug' => 'thd_cap', 'token' => $tok3]);
check('销毁丙队成功', (int)($j['code'] ?? 1) === 0, J($j));
check('三支队走完，演练目录里一张图片都不剩', (glob($UP . '/images/*.jpg') ?: []) === [],
    implode(' , ', array_map('basename', (array)glob($UP . '/images/*.jpg'))));

// ============================================================
echo "\n== 7. 站点早没了、单子还挂着：审核页不能炸 ==\n";
$ghostZip = $ROOT . '/runtime/data_tasks/ghost_' . substr(md5((string)mt_rand()), 0, 6) . '.zip';
$made[] = $ghostZip;
$gz = new ZipArchive();
$gz->open($ghostZip, ZipArchive::CREATE | ZipArchive::OVERWRITE);
$gz->addFromString('manifest.json', json_encode(['format' => 'game-team-export', 'tenant' => 'x', 'tables' => []]));
$gz->addFromString('data.json', json_encode(new stdClass()));
$gz->close();
$pdo->exec("INSERT INTO tenant_data_tasks (tenant_id,type,status,file,note,created_at) VALUES (999999,'import','pending','$ghostZip','站点早没了','$now')");
$pdo->exec("INSERT INTO tenant_data_tasks (tenant_id,type,status,file,note,created_at) VALUES (999998,'import','pending','','包也丢了','$now')");
$pdo->exec("INSERT INTO tenant_data_tasks (tenant_id,type,status,file,note,created_at) VALUES (999997,'export','pending','','只验驳回','$now')");
$gA = (int)val("SELECT id FROM tenant_data_tasks WHERE tenant_id=999999");
$gB = (int)val("SELECT id FROM tenant_data_tasks WHERE tenant_id=999998");
$gC = (int)val("SELECT id FROM tenant_data_tasks WHERE tenant_id=999997");
[$s, $j] = as_req('plat', 'GET', '/api/platform/admin/data/tasks');
check('列表照常 200', $s === 200 && (int)($j['code'] ?? 1) === 0, 'http=' . $s);
$names = [];
foreach ((array)($j['data']['list'] ?? []) as $r) $names[(int)$r['id']] = (string)$r['tenant_name'];
check('没站的单子显示成「站点#ID」而不是报错', ($names[$gA] ?? '') === '站点#999999', J($names));
[$s, $j] = as_req('plat', 'POST', "/api/platform/admin/data/$gA/approve");
check('点同意：不是 500，是一句人话', $s !== 500 && (int)($j['code'] ?? 1) !== 0
    && str_contains((string)($j['msg'] ?? ''), '不存在'), 'http=' . $s . ' ' . J($j));
check('一行数据都没写进那个不存在的站', cnt("SELECT COUNT(*) FROM users WHERE tenant_id=999999") === 0);
check('失败时不删上传包（战队还能改完重交）', is_file($ghostZip));
check('这单被标成 failed', (string)val("SELECT status FROM tenant_data_tasks WHERE id=$gA") === 'failed');
[$s, $j] = as_req('plat', 'POST', "/api/platform/admin/data/$gB/approve");
check('包也丢了的那种同样是句人话', $s !== 500 && str_contains((string)($j['msg'] ?? ''), '丢失'), J($j));
[$s, $j] = as_req('plat', 'POST', "/api/platform/admin/data/$gC/reject", ['reason' => '站点早没了']);
check('幽灵任务还能驳回（不会卡住审核页）', (int)($j['code'] ?? 1) === 0, J($j));

// ============================================================
echo "\n== 8. 孤儿巡检页：未登录不吐信息 / 列得准 / 三种不对都不动手 ==\n";
$probeSrc = $ROOT . '/public/orphan_clean_v53633.php';
$probeDst = $ROOT . '/public/_orphan_probe_v53633.php';
check('巡检页在 public/ 下（原件）', is_file($probeSrc));
copy($probeSrc, $probeDst);
// 只量演练自己那个子目录（本机上传目录里有历史残留，整目录扫会把它们一起列出来）
$PROBE_Q = '/_orphan_probe_v53633.php?dir=' . rawurlencode('_dttest/images');
$made[] = $probeDst;
$P1 = $mk('images/orphan_one.jpg', 'ORPHAN');
$P2 = $mk('images/orphan_two.jpg', 'ORPHAN');
$P3 = $mk('images/orphan_three.jpg', 'ORPHAN');
// 再造一张「还有人引用」的：这一张必须不在清单里，也必须在清理之后还活着
$FK = $mk('images/keeper.jpg', 'KEEPER');
$puKee = $mkPU('kee_cap'); $KEE = $mkTenant($puKee, 'kee_cap', '丁队（在册）');
$keeU = $mkUser($KEE, 'kee_cap', '丁队长', '', 'admin');
$mkImage($KEE, $keeU, $FK, '还在用的一张');
[$s, $raw] = send('nobody', 'GET', $BASE . $PROBE_Q, '', '');
check('没登录：只说需要平台超管登录态，不列任何文件',
    $s === 200 && str_contains($raw, '需要平台超级管理员登录态') && !str_contains($raw, 'orphan_one'),
    mb_substr($raw, 0, 120));
[$s, $raw] = send('plat', 'GET', $BASE . $PROBE_Q, '', '');
check('登录后：列出这 3 张孤儿，在册的那张不列', str_contains($raw, '没有任何行引用的 : 3 张')
    && str_contains($raw, 'orphan_one.jpg') && str_contains($raw, 'orphan_three.jpg')
    && !str_contains($raw, 'keeper.jpg'), mb_substr($raw, -400));
preg_match('/确认码：([0-9a-f]{32})/', $raw, $m);
$tokPage = $m[1] ?? '';
check('页面上给了 32 位确认码', $tokPage !== '', $tokPage);
preg_match('/必须一字不差填 (\d+)/', $raw, $cm);
$needCount = (string)($cm[1] ?? '');
check('页面上要求一字不差填的张数是 3', $needCount === '3', $needCount);
[$s, $raw] = as_form('plat', $PROBE_Q, ['act' => 'clean', 'dir' => '_dttest/images', 'token' => 'wrongwrong', 'typed' => '3']);
check('确认码不对：一张都没删', str_contains($raw, '确认码不对') && onDisk($P1) && onDisk($P3), mb_substr($raw, -200));
[$s, $raw] = send('plat', 'GET', $BASE . $PROBE_Q, '', '');
preg_match('/确认码：([0-9a-f]{32})/', $raw, $m2);
$tokPage2 = $m2[1] ?? '';
[$s, $raw] = as_form('plat', $PROBE_Q, ['act' => 'clean', 'dir' => '_dttest/images', 'token' => $tokPage2, 'typed' => '2']);
check('张数填错：什么都没删', str_contains($raw, '不一致，什么都没删') && onDisk($P2), mb_substr($raw, -200));
// 拿码之后盘上又多一张 → 提交时必须中止
$P4 = $mk('images/orphan_four.jpg', 'ORPHAN');
[$s, $raw] = send('plat', 'GET', $BASE . $PROBE_Q, '', '');
preg_match('/确认码：([0-9a-f]{32})/', $raw, $m3);
$tokPage3 = $m3[1] ?? '';
$P5 = $mk('images/orphan_five.jpg', 'ORPHAN');
[$s, $raw] = as_form('plat', $PROBE_Q, ['act' => 'clean', 'dir' => '_dttest/images', 'token' => $tokPage3, 'typed' => '4']);
check('拿码之后盘上又多了：说「盘上的情况变了」，一张都没删',
    str_contains($raw, '盘上的情况变了') && onDisk($P4) && onDisk($P5), mb_substr($raw, -260));
// 拿的是「另一个范围」的码 → 也一样不动手
[$s, $raw] = send('plat', 'GET', $BASE . '/_orphan_probe_v53633.php?dir=' . rawurlencode('_dttest'), '', '');
preg_match('/确认码：([0-9a-f]{32})/', $raw, $mW);
[$s, $raw] = as_form('plat', $PROBE_Q, ['act' => 'clean', 'dir' => '_dttest/images',
    'token' => (string)($mW[1] ?? ''), 'typed' => '5']);
check('拿的是别的范围的确认码：换了范围，什么都没删',
    str_contains($raw, '换了范围') && onDisk($P4) && onDisk($P5), mb_substr($raw, -260));
[$s, $raw] = send('plat', 'GET', $BASE . $PROBE_Q, '', '');
preg_match('/确认码：([0-9a-f]{32})/', $raw, $m4);
$tokPage4 = $m4[1] ?? '';
check('重看一次，页面上变成 5 张', str_contains($raw, '没有任何行引用的 : 5 张'), mb_substr($raw, -200));
[$s, $raw] = as_form('plat', $PROBE_Q, ['act' => 'clean', 'dir' => '_dttest/images', 'token' => $tokPage4, 'typed' => '5']);
check('码对、张数也对：删掉 5 张', str_contains($raw, '删掉 : 5 张') && !onDisk($P1) && !onDisk($P5),
    mb_substr($raw, -300));
check('还在被引用的那张没被伤到', onDisk($FK), $FK);
check('用完自删的是那一份副本，public/ 下的原件还在', !is_file($probeDst) && is_file($probeSrc),
    'probe=' . (is_file($probeDst) ? '还在' : '没了') . ' origin=' . (is_file($probeSrc) ? '还在' : '没了'));

// ============================================================
echo "\n== 9. 收尾：演练留下的东西清干净 ==\n";
if (is_file($probeDst)) @unlink($probeDst);   // 前面哪一条断了，这份副本也不能留着
$pdo->exec("DELETE FROM tenant_data_tasks WHERE tenant_id IN (999999,999998,999997)");
foreach ($made as $f) if (is_file($f)) @unlink($f);
foreach (glob($UP . '/images/*') ?: [] as $f) @unlink((string)$f);
@rmdir($UP . '/images'); @rmdir($UP);
foreach (glob($ROOT . '/runtime/data_tasks/*.zip') ?: [] as $f) @unlink((string)$f);
$pdo->exec("DELETE FROM tenants WHERE id IN ($SRC,$DST,$THD,$KEE)");
$pdo->exec("DELETE FROM users WHERE tenant_id IN ($SRC,$DST,$THD,$KEE)");
$pdo->exec("DELETE FROM images WHERE tenant_id IN ($SRC,$DST,$THD,$KEE)");
$pdo->exec("DELETE FROM platform_users WHERE id IN ($puSrc,$puDst,$puThd,$puKee)");
$pdo->exec("DELETE FROM tenant_purges WHERE tenant_id IN ($SRC,$DST,$THD)");
check('演练目录已清', !is_dir($UP));
check('runtime/data_tasks 里没有残留的包', (glob($ROOT . '/runtime/data_tasks/*.zip') ?: []) === []);

echo "\n================ 结果 ================\n";
echo "通过 $pass 项";
if ($fail > 0) {
    echo "，失败 $fail 项：\n  - " . implode("\n  - ", $fails) . "\n";
    exit(1);
}
echo "，全部通过\n";
exit(0);
