<?php
/**
 * v5.20.2 一次性体检与补迁移工具（平台超管登录态下才输出内容）
 *
 * 它做两件事：
 *   ① 体检——把「所有页面都 500」的真正原因打印出来（框架启动异常 / 最近一条错误日志 /
 *            本次包内文件的字节数与 md5 对照，能直接看出哪个文件没传好）
 *   ② 补迁移——只执行 database/upgrade_v5.19.0_to_v5.20.1.sql 里那三条 DDL，缺什么补什么
 *
 * 用完请删掉这个文件；它对数据库只做「建表/加列」，不改任何已有数据。
 */
declare(strict_types=1);
use think\facade\Db;
// v5.36.3：页面改成 HTML（只是把原来的纯文本套进 <pre>），补迁移按钮才能做成 POST 表单；
// 页面上所有输出仍逐行转义，日志里可能出现用户填的内容，不能当 HTML 直接吐。
header('Content-Type: text/html; charset=utf-8');
echo '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8">'
    . '<meta name="viewport" content="width=device-width,initial-scale=1">'
    . '<title>v5.20.2 体检与补迁移</title><style>'
    . 'body{margin:16px;font:13px/1.65 Consolas,Menlo,monospace;color:#1f2328}'
    . 'pre{white-space:pre-wrap;word-break:break-all;margin:0}'
    . 'form{margin:18px 0;padding:14px;border:1px solid #d0d7de;border-radius:8px;max-width:760px}'
    . 'button{padding:8px 18px;font-size:14px;cursor:pointer}'
    . '</style></head><body><pre>';
error_reporting(E_ALL);
ini_set('display_errors', '0');

$ROOT = dirname(__DIR__);

// 三条 DDL 由打包脚本从 database/upgrade_v5.19.0_to_v5.20.1.sql 原样抄来（不手写，防抄错）
$DDL = array (
  'table showcase_items' => 'CREATE TABLE IF NOT EXISTS `showcase_items` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id`  INT UNSIGNED NOT NULL              COMMENT \'所属租户\',
  `kind`       VARCHAR(12)  NOT NULL              COMMENT \'gallery图集 honor荣誉墙\',
  `title`      VARCHAR(64)  NOT NULL DEFAULT \'\'   COMMENT \'标题(荣誉名/图注)\',
  `note`       VARCHAR(200) NOT NULL DEFAULT \'\'   COMMENT \'说明\',
  `image`      VARCHAR(160) NOT NULL DEFAULT \'\'   COMMENT \'图片(/storage/uploads/showcase/下相对路径)\',
  `sort`       INT          NOT NULL DEFAULT 0    COMMENT \'排序(小的在前)\',
  `visible`    TINYINT      NOT NULL DEFAULT 1     COMMENT \'1=展示页可见 0=隐藏(留着不删)\',
  `created_at` DATETIME              DEFAULT NULL,
  `updated_at` DATETIME              DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_show_item` (`tenant_id`, `kind`, `visible`, `sort`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT=\'展示页条目(v5.20.0,图集/荣誉)\';',
  'table show_submissions' => 'CREATE TABLE IF NOT EXISTS `show_submissions` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id`   INT UNSIGNED NOT NULL              COMMENT \'投递到哪个战队站点\',
  `type`        VARCHAR(8)   NOT NULL DEFAULT \'intent\' COMMENT \'post内容投稿(通过后上墙) intent入队意向\',
  `qq`          VARCHAR(16)  NOT NULL DEFAULT \'\'   COMMENT \'访客QQ号(意向必填,供队长联系)\',
  `nick`        VARCHAR(32)  NOT NULL DEFAULT \'\'   COMMENT \'游戏昵称\',
  `position`    VARCHAR(32)  NOT NULL DEFAULT \'\'   COMMENT \'意向岗位(取自该队招募岗位,可为其他)\',
  `content`     VARCHAR(500) NOT NULL              COMMENT \'投稿说明/自我介绍\',
  `image`       VARCHAR(160) NOT NULL DEFAULT \'\'   COMMENT \'投稿截图(仅 post 采纳后上墙)\',
  `status`      VARCHAR(12)  NOT NULL DEFAULT \'pending\' COMMENT \'pending待处理 approved已采纳 rejected已驳回\',
  `result_note` VARCHAR(200) NOT NULL DEFAULT \'\'   COMMENT \'管理员结论/驳回理由\',
  `reviewed_by` INT UNSIGNED          DEFAULT NULL COMMENT \'处理的管理员\',
  `reviewed_at` DATETIME              DEFAULT NULL,
  `ip`          VARCHAR(45)  NOT NULL DEFAULT \'\'   COMMENT \'提交者IP\',
  `region`      VARCHAR(128) NOT NULL DEFAULT \'\'   COMMENT \'IP归属地(与注册同一口径)\',
  `created_at`  DATETIME              DEFAULT NULL,
  `updated_at`  DATETIME              DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_show_sub` (`tenant_id`, `type`, `status`, `id`),
  KEY `idx_show_sub_ip` (`tenant_id`, `ip`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT=\'展示页投稿与入队意向(v5.20.0)\';',
  'column users.show_public' => 'ALTER TABLE `users`
  ADD COLUMN `show_public` TINYINT NOT NULL DEFAULT 0 COMMENT \'1=同意在本队对外展示页出现(昵称+职责标签,v5.20.0)\';',
);

// 本次包内文件的期望字节数与 md5（由打包脚本从 zip 现算，用于认出没传好的那一个文件）
$EXPECT = array (
  'app/controller/Showcase.php' => 
  array (
    0 => 14252,
    1 => 'e05cc5506cca5fc9c745051d1f6ae9dc',
  ),
  'app/controller/ShowcaseAdmin.php' => 
  array (
    0 => 20987,
    1 => '59a6c3604540f98d9cea368bdae1e753',
  ),
  'app/service/Showcase.php' => 
  array (
    0 => 33164,
    1 => 'bfa6a66ea799b60bb3888ad163f64077',
  ),
  'public/static/css/showcase.css' => 
  array (
    0 => 16539,
    1 => 'd701cc02eddf9fa490bfb058a38d29c8',
  ),
  'view/showcase.html' => 
  array (
    0 => 16968,
    1 => '5c249ccb73cec7181cd3d2588c6ad969',
  ),
  'view/hall.html' => 
  array (
    0 => 8579,
    1 => 'b012397bd13a90625e7de844b75ccdfb',
  ),
  'scripts/test_showcase_e2e.php' => 
  array (
    0 => 58253,
    1 => '64eb7d59bfebce1e20c17b3426fa5c0b',
  ),
  'app/common.php' => 
  array (
    0 => 59332,
    1 => '5ac7a4eca0cca87a6e83de87fd80dad2',
  ),
  'app/controller/Auth.php' => 
  array (
    0 => 19802,
    1 => '017bc141c103a64474e1933de53dffce',
  ),
  'app/controller/Platform.php' => 
  array (
    0 => 144096,
    1 => '9e7f8ac0ff9f731c5db4e467a0918854',
  ),
  'app/controller/Profile.php' => 
  array (
    0 => 6779,
    1 => '193a8cf35bd9b9b0880db829f7286958',
  ),
  'app/service/SitemapGenerator.php' => 
  array (
    0 => 10657,
    1 => '7e12e9a0c9de19d7c44b4870d833625c',
  ),
  'app/service/Storage.php' => 
  array (
    0 => 10700,
    1 => 'abcebebcd98e86f624263ad0269ec48c',
  ),
  'app/service/TenantContext.php' => 
  array (
    0 => 4099,
    1 => '2562544bb0bbc75568381dba4d445c2b',
  ),
  'route/app.php' => 
  array (
    0 => 36945,
    1 => '6b760e156dc52a2db0c62f327f10f6d5',
  ),
  'public/static/js/app.jsx' => 
  array (
    0 => 502564,
    1 => '401972d80afdf112126b589bddb61139',
  ),
  'public/static/js/platform.jsx' => 
  array (
    0 => 204035,
    1 => '0ffd7be7a4272f5b7c9611b77a8b0c57',
  ),
  'scripts/dev_init_sqlite.php' => 
  array (
    0 => 45214,
    1 => '4c16e5e1fc1fa48cd060ed72826e1455',
  ),
  'FIXES.md' => 
  array (
    0 => 179358,
    1 => '5aef8ddb9e13c408fc96cbbe9106bc72',
  ),
);

$say = static function (string $t): void {
    echo htmlspecialchars($t, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), "\n";
};
$h = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

// ---------- ① 框架能不能启动 ----------
try {
    require $ROOT . '/vendor/autoload.php';
    $app = new \think\App();
    $app->initialize();
} catch (\Throwable $e) {
    $say('=== 站点在框架启动阶段就失败了（这就是每个页面都 500 的原因）===');
    $say('异常类型 : ' . get_class($e));
    $say('异常信息 : ' . $e->getMessage());
    $say('出错位置 : ' . $e->getFile() . ':' . $e->getLine());
    $say('');
    $say('下一步：把「出错位置」那个文件重新上传一次覆盖掉；');
    $say('如果那个文件在 vendor/ 或 config/ 里，说明这次 500 不是本包的改动引起的，把这三行发我。');
    exit;
}

// ---------- ② 接着本站自己的会话拿到登录态 ----------
// 不跑整条中间件管道（SessionInit 收尾要 Response），只把会话本身启起来
try {
    $session = $app->session;
    $cookieName = $session->getName();
    if (!empty($_COOKIE[$cookieName])) {
        $session->setId((string) $_COOKIE[$cookieName]);
    }
    $session->init();
} catch (\Throwable $e) {
    $say('=== 会话这一步失败（很可能也是每个页面 500 的原因）===');
    $say('异常类型 : ' . get_class($e));
    $say('异常信息 : ' . $e->getMessage());
    $say('出错位置 : ' . $e->getFile() . ':' . $e->getLine());
    exit;
}

if (!function_exists('is_platform_super') || !\is_platform_super()) {
    $say('需要平台超管登录态：请先在同一个浏览器里登录平台后台，再重新打开本链接。');
    $say('（本文件不输出任何配置内容，未登录时只有这一屏）');
    exit;
}

// v5.36.3：补迁移只认「POST + 本会话派生的 token」。
// 之前是 GET ?do=apply 直接执行 DDL，别的网站可以让已登录的超管顶栏跳过来触发（CSRF）；
// 现在 GET 只出报告和一个按钮，真正的 DDL 走下面这个表单。
$opToken = substr(hash('sha256', $cookieName . '|' . ($session->getId() ?? '') . '|db_repair5202'), 0, 16);
$applied = false;
$still   = [];

$say('=== v5.20.2 体检报告  ' . date('Y-m-d H:i:s') . ' ===');
$say('');

// ---------- ③ 运行环境 ----------
$ext = [];
foreach (['pdo_mysql', 'mbstring', 'gd', 'zip', 'fileinfo', 'json', 'openssl', 'curl'] as $e) {
    $ext[] = $e . '=' . (extension_loaded($e) ? 'Y' : 'N');
}
$say('[环境] PHP ' . PHP_VERSION . ' | memory_limit ' . (string) ini_get('memory_limit')
    . ' | ' . implode(' ', $ext));
$say('       站点根 ' . $ROOT);
$say('');

// ---------- ④ 数据库 ----------

$driver = (string) \think\facade\Config::get('database.default', '');
$say('[数据库] 当前驱动 : ' . $driver);
$dbOk = false;
try {
    Db::query('SELECT 1');
    $dbOk = true;
} catch (\Throwable $e) {
    $say('        连接/查询失败 : ' . get_class($e) . ' —— ' . $e->getMessage());
}
if ($dbOk) {
    $cur = '';
    try {
        $row = Db::query('SELECT DATABASE() AS d');
        $cur = (string) ($row[0]['d'] ?? '');
    } catch (\Throwable $e) {
        $cur = '(取不到)';
    }
    $say('        已连上，当前库 : ' . ($cur === '' ? '(空)' : $cur));
    if ($cur === '') {
        $say('        ★ 库名为空：说明 .env 里 DB_NAME 没生效或被解析失败，请先处理这个');
    }
}
$say('');

// ---------- ⑤ 这次升级的三样东西在不在 ----------
$miss = [];
if ($driver === 'sqlite') {
    $say('[迁移自检] 本机 sqlite 夹具，跳过 MySQL 专用检查');
} elseif (!$dbOk) {
    $say('[迁移自检] 数据库没连上，跳过');
} else {
    $say('[迁移自检] 应返回 3 个「在」：');
    foreach (['showcase_items', 'show_submissions'] as $t) {
        $hit = false;
        try {
            $hit = (bool) Db::query("SHOW TABLES LIKE '" . $t . "'");
        } catch (\Throwable $e) {
            $hit = false;
        }
        $say('   ' . ($hit ? '在  ' : '缺  ') . ' 表 ' . $t);
        if (!$hit) { $miss[] = 'table ' . $t; }
    }
    $col = 0;
    try {
        $r = Db::query("SELECT COUNT(*) AS c FROM information_schema.COLUMNS"
            . " WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'show_public'");
        $col = (int) ($r[0]['c'] ?? 0);
    } catch (\Throwable $e) {
        $col = 0;
    }
    $say('   ' . ($col > 0 ? '在  ' : '缺  ') . ' 列 users.show_public');
    if ($col === 0) { $miss[] = 'column users.show_public'; }
}
$say('');

// ---------- ⑥ 补迁移（只认 POST + 本会话 token，GET 永远不会执行 DDL）----------
$wantsApply = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
    && (string) ($_POST['do'] ?? '') === 'apply'
    && hash_equals($opToken, (string) ($_POST['token'] ?? ''));
if ($wantsApply) {
    $applied = true;
    if ($driver !== 'mysql' || !$dbOk) {
        $say('[补迁移] 只在 MySQL 且已连上库时可用');
    } else {
        $say('[补迁移] 逐条执行 upgrade_v5.19.0_to_v5.20.1.sql 里的三条：');
        foreach ($DDL as $tag => $sql) {
            try {
                Db::execute($sql);
                $say('   成功  ' . $tag);
            } catch (\Throwable $e) {
                $msg = $e->getMessage();
                if (strpos($msg, '1060') !== false || stripos($msg, 'Duplicate column') !== false) {
                    $say('   跳过  ' . $tag . '（列已存在）');
                } elseif (strpos($msg, '1050') !== false || stripos($msg, 'already exists') !== false) {
                    $say('   跳过  ' . $tag . '（表已存在）');
                } else {
                    $say('   失败  ' . $tag . ' —— ' . $msg);
                    $say('   （权限不够就报错信息里那句话：需要数据库账号有 CREATE / ALTER 权限）');
                }
            }
        }
        $say('');
        $say('[补迁移后复查]');
        $still = [];
        foreach (['showcase_items', 'show_submissions'] as $t) {
            $hit = false;
            try {
                $hit = (bool) Db::query("SHOW TABLES LIKE '" . $t . "'");
            } catch (\Throwable $e) {
                $hit = false;
            }
            $say('   ' . ($hit ? '在  ' : '缺  ') . ' 表 ' . $t);
            if (!$hit) { $still[] = $t; }
        }
        $col = 0;
        try {
            $r = Db::query("SELECT COUNT(*) AS c FROM information_schema.COLUMNS"
                . " WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'show_public'");
            $col = (int) ($r[0]['c'] ?? 0);
        } catch (\Throwable $e) {
            $col = 0;
        }
        $say('   ' . ($col > 0 ? '在  ' : '缺  ') . ' 列 users.show_public');
        if ($col === 0) { $still[] = 'users.show_public'; }
        $say('');
        if ($still === []) {
            $say('   三样都齐了，展示页与「对外展示页」页签现在应该不再是 500。');
            $say('   本文件自我删除：' . (@unlink(__FILE__)
                ? '已删掉（它不含密钥，但留着一个能改库的入口没必要）'
                : '没删掉，请手工删除 public/' . basename(__FILE__)));
        } else {
            $say('   还缺：' . implode('、', $still)
                . ' —— 通常是数据库账号没有 CREATE / ALTER 权限，把上面「逐条执行」那几行的原文发我');
        }
    }
    $say('');
} elseif ((string) ($_POST['do'] ?? '') === 'apply') {
    $say('[补迁移] 校验失败：请刷新本页，用页面底部的按钮重新提交。');
    $say('');
}

// ---------- ⑦ 最近一条错误日志（只挑 error 行，最后几条往往就是真因）----------
$logRoot = $ROOT . '/runtime/log/';
$cands = is_dir($logRoot) ? (glob($logRoot . '*' . DIRECTORY_SEPARATOR . '*.log') ?: []) : [];
if ($cands) {
    usort($cands, static fn ($a, $b) => filemtime($b) <=> filemtime($a));
    $f = $cands[0];
    $say('[错误日志] 最近一份：' . str_replace($ROOT, '', $f) . '  ' . date('Y-m-d H:i:s', (int) filemtime($f)));
    $all = array_values(array_filter(explode("\n", (string) file_get_contents($f)),
        static fn ($l) => trim($l) !== ''));
    $errs = array_values(array_filter($all, static function ($l) {
        return stripos($l, '[error]') !== false || stripos($l, 'exception') !== false
            || stripos($l, 'SQLSTATE') !== false || stripos($l, 'not found') !== false;
    }));
    $pick = $errs ? array_slice($errs, -8) : array_slice($all, -6);
    $say('   ' . ($errs ? '（以下是这份日志里最后 ' . count($pick) . ' 条 error，共 ' . count($errs) . ' 条）'
        : '（这份日志里没有 error 行，下面贴最后 6 行）'));
    foreach ($pick as $l) {
        $say('   | ' . mb_substr(trim(str_replace(["\r", "\t"], [' ', ' '], $l)), 0, 240));
    }
    $say('   （日志里可能含访客 IP，只给你自己看，别贴到公开场合）');
} else {
    $say('[错误日志] runtime/log 下没有日志文件（说明异常没到写日志那一步，看上面启动/会话两节）');
}
$say('');

// ---------- ⑦b 这几个新类能不能autoload（composer 的 classmap 没重建会在这儿露馅）----------
$say('[类装载] 逐个 class_exists（触发自动加载）');
foreach ([
    'app\\service\\Showcase',
    'app\\controller\\Showcase',
    'app\\controller\\ShowcaseAdmin',
    'app\\service\\TenantContext',
    'app\\service\\Storage',
] as $cls) {
    try {
        $ok = class_exists($cls);
        $say('   ' . ($ok ? 'OK    ' : '装不到 ') . $cls . ($ok ? '' : '  ← 对应文件没传上来或路径不对'));
    } catch (\Throwable $e) {
        $say('   报错  ' . $cls . ' —— ' . get_class($e) . ': ' . $e->getMessage()
            . ' @ ' . $e->getFile() . ':' . $e->getLine());
    }
}
$say('');

// ---------- ⑧ 包内文件字节级对照（认出没传好的那一个）----------
$say('[文件对照] 与交付包逐字节比对（只比大小和 md5，不输出内容）');
$bad = 0;
foreach ($EXPECT as $path => $want) {
    $disk = $ROOT . '/' . $path;
    if (!is_file($disk)) { $say('   缺文件  ' . $path); $bad++; continue; }
    $sz = filesize($disk);
    if ($sz !== $want[0] || md5_file($disk) !== $want[1]) {
        $say('   不一致  ' . $path . '  期望 ' . $want[0] . ' 字节 / ' . $want[1]
            . '   实际 ' . $sz . ' 字节 / ' . (string) md5_file($disk));
        $bad++;
    }
}
$say('   ' . ($bad === 0
    ? '全部一致（' . count($EXPECT) . ' 个文件）—— 500 不是文件没传好，看上面「错误日志」那一节'
    : '有 ' . $bad . ' 个文件和交付包不一致 —— 重新上传这几个文件即可'));
$say('');
$say('=== 完毕。用完请删除本文件（public/' . basename(__FILE__) . '）===');

// 只有「缺结构 + 已登录超管 + 还没补成功」才给按钮；点了走 POST + token，GET 链接不会改库
$needApplyButton = $driver === 'mysql' && $dbOk && is_file(__FILE__)
    && ($miss !== [] || ($applied && $still !== []));
if ($needApplyButton) {
    echo '</pre>';
    echo '<form method="post" action="">'
        . '<p style="margin:0 0 10px">检测到还缺结构。点下面这一颗按钮补上'
        . '（只建表/加列，不改已有数据；成功后本文件会自删）：</p>'
        . '<input type="hidden" name="do" value="apply">'
        . '<input type="hidden" name="token" value="' . $h($opToken) . '">'
        . '<button type="submit">补上缺的表与列</button>'
        . '</form><pre>';
}
echo '</pre></body></html>';
