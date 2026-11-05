<?php
/**
 * v5.36.33 上传图片孤儿巡检（平台超级管理员登录态下才输出内容）
 *
 * 干什么：把 public/storage/uploads 里「没有任何数据库行引用」的图片列出来，
 *          你看完清单再决定删不删。一次一码，删之前会再扫一遍，数目对不上就中止。
 *
 * 为什么会有这种东西：v5.36.33 之前，战队数据包被平台「同意导入」时，包里跟站内已有文件
 *          撞名的那几张会另存成 xxx_ab12.jpg，可数据库行写的还是没有后缀的老路径 ——
 *          于是每一次导入都在盘上留下一整份没人引用的副本，销毁战队也删不掉它们。
 *          代码这一处已经修好了；这一页负责把以前堆下的那些清掉。
 *
 * 判定口径：按「文件名」比，全站一共量这 15 处引用（跟删除战队用的是同一份清单）：
 *   users.profile_image / images.file / guides.image / announcements.image /
 *   showcase_items.image / show_submissions.image / password_resets.screenshot /
 *   training_rounds.evidence / battle_rounds.evidence / tenants.logo /
 *   tenant_applications.logo / tenant_applications.screenshot / ai_stickers.path /
 *   rotation_maps.image / settings.value
 *   引用清单一条都取不到时，这一页拒绝删任何一张（宁可什么都不动）。
 * 只处理 jpg / jpeg / png / gif / webp；目录、.htaccess、.gitkeep 和非图片一概不碰。
 *
 * ★ 这一页判断「有没有人引用」靠的是当前连着的这个库。请先确认 .env 连的是线上库；
 *   连着测试库跑它，会把线上的真图片全当成孤儿。稳妥起见可以先只清一个子目录：
 *   在链接后面加 ?dir=images/20261104（uploads 下面的相对目录），页面就只量那一片。
 * 删干净之后本文件会自己删掉自己；要再用就从热更包里再传一次上来。
 */
declare(strict_types=1);

use app\service\TenantPurge;
use think\facade\Db;

error_reporting(E_ALL);
ini_set('display_errors', '0');

$ROOT = dirname(__DIR__);

$h = static function (string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); };
$out = [];
$say = static function (string $line) use (&$out): void { $out[] = $line; };
$stop = static function () use (&$out, $h): void {
    echo '<pre>' . implode("\n", array_map($h, $out)) . '</pre>';
    exit;
};

try {
    require $ROOT . '/vendor/autoload.php';
    $app = new \think\App();
    $app->initialize();
} catch (\Throwable $e) {
    $say('框架起不来：' . get_class($e) . ' —— ' . $e->getMessage());
    $stop();
}

try {
    $session  = $app->session;
    $cookieNm = $session->getName();
    if (!empty($_COOKIE[$cookieNm])) {
        $session->setId((string) $_COOKIE[$cookieNm]);
    }
    $session->init();
    // 这一页不走框架的正常收尾，会话必须自己存一次，否则刚发的确认码下一个请求就没了
    register_shutdown_function(static function () use ($session): void {
        try { $session->save(); } catch (\Throwable $e) { }
    });
} catch (\Throwable $e) {
    $say('会话起不来：' . $e->getMessage());
    $stop();
}

if (!function_exists('is_platform_super') || !\is_platform_super()) {
    echo '<pre>需要平台超级管理员登录态：请先在同一个浏览器里登录平台后台，再重新打开本链接。'
        . "\n（未登录时本文件不输出任何其它信息）</pre>";
    exit;
}

$IMG_EXT = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
$UP      = rtrim(public_path(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
        . 'storage' . DIRECTORY_SEPARATOR . 'uploads';
$UPREAL  = realpath($UP);
$TTL     = 300;

$kb = static function (int $b): string {
    if ($b >= 1048576) return number_format($b / 1048576, 2) . ' MB';
    if ($b >= 1024)    return number_format($b / 1024, 1) . ' KB';
    return $b . ' B';
};

$say('=== v5.36.33 上传图片孤儿巡检  ' . date('Y-m-d H:i:s') . ' ===');
$say('[数据库] 驱动 : ' . (string) \think\facade\Config::get('database.default', ''));
if ($UPREAL === false || !is_dir($UPREAL)) {
    $say('');
    $say('看不到上传目录（' . $UP . '），这一页什么都不做。');
    $stop();
}

// 可以只量一个子目录：?dir=images/20261104（不给就是整个上传目录）
$dirIn    = trim((string) ($_REQUEST['dir'] ?? ''));
$scopeDir = $UPREAL;
$scopeRel  = '';
$scopeLabel = '整个上传目录';
if ($dirIn !== '') {
    $try  = $UPREAL . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, ltrim($dirIn, '/\\'));
    $real = realpath($try);
    if ($real === false || !is_dir($real) || strpos($real, $UPREAL . DIRECTORY_SEPARATOR) !== 0) {
        $say('');
        $say('dir= 指的目录不在上传目录里（' . $dirIn . '），这一页什么都不做。');
        $stop();
    }
    $scopeDir  = $real;
    $scopeRel  = str_replace('\\', '/', substr($real, strlen($UPREAL) + 1));
    $scopeLabel = '只看 ' . $scopeRel;
}
$say('[范围] ' . $scopeLabel);

/** 扫一遍：返回 [孤儿清单, 扫过的图片数, 引用清单条数] */
$sweep = static function () use ($scopeDir, $IMG_EXT): array {
    $refs = TenantPurge::referencedNames();
    $map  = [];
    foreach ($refs as $n) $map[$n] = true;
    $orphans = [];
    $scanned = 0;
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($scopeDir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($it as $f) {
        if (!$f->isFile()) {
            continue;
        }
        $name = $f->getFilename();
        $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, $IMG_EXT, true)) {
            continue;   // 只认图片，别的一概不碰
        }
        $scanned++;
        if (isset($map[$name])) {
            continue;   // 按文件名比：有任何一行还引用着这个文件名，就不算孤儿
        }
        $orphans[] = [(string) $f->getPathname(), (int) $f->getSize(), (int) $f->getMTime()];
    }
    return [$orphans, $scanned, count($refs)];
};

$act = (string) ($_POST['act'] ?? ($_GET['act'] ?? 'scan'));
$bytesTotal = 0;

if ($act === 'clean') {
    $token = (string) ($_POST['token'] ?? '');
    $typed = trim((string) ($_POST['typed'] ?? ''));
    $s     = session('orphan_token');
    if (!is_array($s) || empty($s['hash'])) {
        $say('');
        $say('没有确认码（或者已经用过了）。先打开这一页看清单，拿到确认码再回来。');
        $stop();
    }
    if ((int) ($s['exp'] ?? 0) < time()) {
        session('orphan_token', null);
        $say('');
        $say('确认码超时了（' . $TTL . ' 秒），请重新看一次清单。');
        $stop();
    }
    if ($token === '' || !hash_equals((string) $s['hash'], hash('sha256', $token))) {
        $say('');
        $say('确认码不对，什么都没删。');
        $stop();
    }
    session('orphan_token', null);           // 一次性的，验完就作废
    if ((string) ($s['scope'] ?? '') !== $scopeRel) {
        $say('');
        $say('确认码是给「' . (string) ($s['scope'] === '' ? '整个上传目录' : $s['scope']) . '」拿的，'
            . '现在的范围是「' . ($scopeRel === '' ? '整个上传目录' : $scopeRel) . '」—— 换了范围，什么都没删。');
        $stop();
    }
    if ($typed !== (string) ($s['count'] ?? '')) {
        $say('');
        $say('你要删的个数填的是「' . $typed . '」，刚才那份清单是 ' . $s['count'] . ' 张 —— 不一致，什么都没删。');
        $stop();
    }

    [$orphans, $scanned, $refCount] = $sweep();
    if ($refCount === 0) {
        $say('');
        $say('引用清单一条都没取到（数据库有问题？），这一页拒绝删任何一张。什么都没动。');
        $stop();
    }
    if (count($orphans) !== (int) $s['count']) {
        $say('');
        $say('盘上的情况变了：刚才是 ' . $s['count'] . ' 张，现在扫出来 ' . count($orphans) . ' 张。'
            . '为稳妥起见什么都没删，请重新看一次清单。');
        $stop();
    }

    $refs = TenantPurge::referencedNames();
    $map  = [];
    foreach ($refs as $n) $map[$n] = true;
    $killed = 0;
    $killedBytes = 0;
    $skipped = 0;
    $failed = 0;
    foreach ($orphans as [$abs, $sz]) {
        $real = realpath($abs);
        if ($real === false || strpos($real, $UPREAL . DIRECTORY_SEPARATOR) !== 0) {
            $skipped++;
            continue;                        // 不在上传目录里的，一张都不动
        }
        if (isset($map[basename(str_replace('\\', '/', $real))])) {
            $skipped++;                       // 删之前再确认一次没人引用
            continue;
        }
        if (@unlink($real)) {
            $killed++;
            $killedBytes += $sz;
        } else {
            $failed++;
            $say('删不动：' . $real . '（目录不可写？请检查权限）');
        }
    }
    $say('');
    $say('=== 清完了 ===');
    $say('删掉 : ' . $killed . ' 张 / ' . $kb($killedBytes));
    $say('留下 : ' . $skipped . ' 张（扫描后又被人引用上了，或在上传目录外）');
    $say('失败 : ' . $failed . ' 张');
    if ($failed === 0 && is_file(__FILE__)) {
        @unlink(__FILE__);
        $say(is_file(__FILE__) ? '本文件删不掉（权限），用完了请手动删除 public/orphan_clean_v53633.php。'
            : '本文件已自删。要再用请从 v5.36.33 的热更包里再传一次上来。');
    } elseif ($failed > 0) {
        $say('有删不动的，本文件留着没自删；修好权限后可以再跑一次。');
    }
    $stop();
}

// —— 默认：只看清单，不动任何东西 ——
[$orphans, $scanned, $refCount] = $sweep();
foreach ($orphans as [, $sz]) $bytesTotal += $sz;
$say('[引用清单] 全站还在被引用的图片文件名 : ' . $refCount . ' 个');
$say('[扫过] 上传目录里的图片 : ' . $scanned . ' 张');
$say('[结论] 没有任何行引用的 : ' . count($orphans) . ' 张 / ' . $kb($bytesTotal));
if ($refCount === 0) {
    $say('');
    $say('引用清单一条都没取到 —— 这种情况这一页拒绝删任何一张（否则会把整站图片当孤儿删掉）。');
    $say('先确认数据库连得上、上面那张引用列表里的表都在，再重开这一页。');
    $stop();
}
if ($orphans === []) {
    $say('');
    $say('没有孤儿，什么都不用删。本文件可以删掉了（它不会自删，因为没干活）。');
    $stop();
}

$plain = bin2hex(random_bytes(16));
session('orphan_token', [
    'hash'  => hash('sha256', $plain),
    'count' => count($orphans),
    'scope' => $scopeRel,
    'exp'   => time() + $TTL,
    'actor' => (int) (current_platform_user()->id ?? 0),
]);

$say('');
$say('下面这些就是没人引用的图片（最近留下的排最前面），最多列 200 行：');
$list = $orphans;
usort($list, static fn($a, $b) => $b[2] <=> $a[2]);
foreach (array_slice($list, 0, 200) as [$abs, $sz, $mt]) {
    $say('  · ' . date('Y-m-d', $mt) . '  ' . $kb($sz) . '  ' . str_replace('\\', '/', substr($abs, strlen($UPREAL) + 1)));
}
if (count($list) > 200) {
    $say('  …还有 ' . (count($list) - 200) . ' 张没列出来，删除时一起算。');
}
$say('');
$say('要删就填下面两个东西，确认码 ' . $TTL . ' 秒内有效、只能用一次：');
$say('  确认码：' . $plain);
$say('  要删的张数：必须一字不差填 ' . count($orphans));
$say('');
echo '<pre>' . implode("\n", array_map($h, $out)) . '</pre>';
echo '<form method="post" action="">'
    . '<input type="hidden" name="act" value="clean">'
    . '<input type="hidden" name="dir" value="' . $h($dirIn) . '">'
    . '确认码 <input type="text" name="token" size="34"> '
    . '要删的张数 <input type="text" name="typed" size="8"> '
    . '<button type="submit">删除这 ' . count($orphans) . ' 张孤儿图片</button>'
    . '</form>';
