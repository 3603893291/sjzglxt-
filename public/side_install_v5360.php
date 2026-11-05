<?php
/**
 * v5.36.0 端手分离补库工具（平台超级管理员登录态下才输出内容）
 *
 * 打开这一个链接、点一个按钮，库就补上这一版要的列与索引：
 *   users.game_side / users.game_side_lock
 *   八张内容表的 game_side
 *   duty_configs 唯一键改成 (tenant_id, duty_type, game_side)
 *
 * 只加列与索引，不建表、不删数据、不刷任何已有行；所有老数据的 game_side 都是空串
 * （含义：全队共享 / 不分类），不开总开关时行为和升级前完全一样。
 * 重复点安全：每一步先查 information_schema，缺了才 ALTER。
 * 跑齐了本文件会自己删掉自己。
 *
 * 也可以不点按钮，直接执行 database/upgrade_v5.35.1_to_v5.36.0.sql，两件事完全一样。
 */
declare(strict_types=1);

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
} catch (\Throwable $e) {
    $say('会话起不来：' . $e->getMessage());
    $stop();
}

if (!function_exists('is_platform_super') || !\is_platform_super()) {
    echo '<pre>需要平台超级管理员登录态：请先在同一个浏览器里登录平台后台，再重新打开本链接。'
        . "\n（未登录时本文件不输出任何其它信息）</pre>";
    exit;
}

$driver = (string) \think\facade\Config::get('database.default', '');
$say('=== v5.36.0 端手分离补库工具  ' . date('Y-m-d H:i:s') . ' ===');
$say('[数据库] 驱动 : ' . $driver);
if ($driver !== 'mysql') {
    $say('');
    $say('当前驱动不是 mysql，本工具不执行任何语句。');
    $say('本地开发/测试库请跑：php scripts/dev_init_sqlite.php runtime/test.sqlite');
    $stop();
}

$cur = '';
try {
    $row = Db::query('SELECT DATABASE() AS d');
    $cur = (string) ($row[0]['d'] ?? '');
} catch (\Throwable $e) {
    $say('连接失败 : ' . $e->getMessage());
    $stop();
}
$say('        库名 : ' . ($cur === '' ? '(没选中数据库)' : $cur));
if ($cur === '') {
    $say('没选中数据库（#1046），本工具一条语句都不执行。');
    $stop();
}

$hasTable = static function (string $t) use ($cur): bool {
    $r = Db::query('SELECT COUNT(*) AS c FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?', [$cur, $t]);
    return (int) ($r[0]['c'] ?? 0) > 0;
};
$hasCol = static function (string $t, string $col) use ($cur): bool {
    $r = Db::query('SELECT COUNT(*) AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$cur, $t, $col]);
    return (int) ($r[0]['c'] ?? 0) > 0;
};
$idxCols = static function (string $t, string $idx) use ($cur): int {
    $r = Db::query('SELECT COUNT(*) AS c FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?', [$cur, $t, $idx]);
    return (int) ($r[0]['c'] ?? 0);
};

if (!$hasTable('users')) {
    $say('');
    $say('[结果] 当前库里没有 users 表 —— 这套库根本还没装。');
    $say('       请先执行 database/game_team.sql（宝塔/phpMyAdmin 用 game_team_phpmyadmin.sql）。');
    $stop();
}
foreach (['announcements', 'race_events', 'custom_race_events', 'custom_events', 'training_matches', 'votes', 'teams', 'duty_configs'] as $need) {
    if (!$hasTable($need)) {
        $say('[注意] 当前库里没有 ' . $need . ' 表 —— 对应那块分端功能用不了，但不会报错。');
    }
}

$colComment = " COMMENT '端别: pc端游 mobile手游 空=全队共享(v5.36.0)'";
$cols = [
    ['users', 'game_side', "ALTER TABLE `users` ADD COLUMN `game_side` VARCHAR(8) NOT NULL DEFAULT '' COMMENT '端别: pc端游 mobile手游 空=老数据未分类(升级后必须补选)(v5.36.0)' AFTER `stats_public`"],
    ['users', 'game_side_lock', "ALTER TABLE `users` ADD COLUMN `game_side_lock` VARCHAR(8) NOT NULL DEFAULT '' COMMENT '跨端可见: 空=跟随全队 1=只能看本端 0=允许看两端(v5.36.0)' AFTER `game_side`"],
];
foreach (['announcements', 'race_events', 'custom_race_events', 'custom_events', 'training_matches', 'votes', 'teams', 'duty_configs'] as $t) {
    $cols[] = [$t, 'game_side', "ALTER TABLE `{$t}` ADD COLUMN `game_side` VARCHAR(8) NOT NULL DEFAULT ''{$colComment} AFTER `tenant_id`"];
}
$idxs = [];
foreach (['users', 'announcements', 'race_events', 'custom_race_events', 'custom_events', 'training_matches', 'votes', 'teams', 'duty_configs'] as $t) {
    $idxs[] = [$t, 'idx_game_side', "ALTER TABLE `{$t}` ADD KEY `idx_game_side` (`tenant_id`, `game_side`)"];
}

$gaps = static function () use ($cols, $idxs, $hasTable, $hasCol, $idxCols): array {
    $g = [];
    foreach ($cols as [$t, $c, $ddl]) {
        if ($hasTable($t) && !$hasCol($t, $c)) { $g[] = "col:{$t}.{$c}"; }
    }
    foreach ($idxs as [$t, $i, $ddl]) {
        if ($hasTable($t) && $idxCols($t, $i) === 0) { $g[] = "idx:{$t}.{$i}"; }
    }
    if ($hasTable('duty_configs')) {
        $n = $idxCols('duty_configs', 'uk_tenant_type');
        if ($n === 2) { $g[] = 'uk:drop2'; }
        if ($n === 0) { $g[] = 'uk:add3'; }
    }
    return $g;
};

$gapsNow = $gaps();
$say('');
$say('[体检] 现在还缺：' . ($gapsNow === [] ? '什么都不缺' : (string) count($gapsNow) . ' 项'));

$token = substr(hash('sha256', $cookieNm . '|' . ($session->getId() ?? '') . '|side5360'), 0, 16);
$wants = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
    && (string) ($_POST['do'] ?? '') === 'apply'
    && hash_equals($token, (string) ($_POST['token'] ?? ''));

$selfDel = static function () use ($say): void {
    $deleted = @unlink(__FILE__);
    $say($deleted ? '       本文件已自动删掉自己（public/side_install_v5360.php）。'
        : '       本文件删不掉自己（权限），请手动删除 public/side_install_v5360.php。');
};

if ($gapsNow === []) {
    $say('');
    $say('[结果] 十列、九条索引、值班三列唯一键都在，库这一版已经齐了。');
    $say('       传代码、强刷（资源版本号 5.36.0），队长在管理中心打开「端手游分离」就行。');
    $selfDel();
    $stop();
}

if ($wants) {
    $say('');
    $say('[执行] 开始补 ' . count($gapsNow) . ' 项…');
    $run = static function (string $sql, string $label) use ($say): void {
        try {
            Db::execute($sql);
            $say('   好了     ' . $label);
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            if (strpos($msg, '1060') !== false || stripos($msg, 'Duplicate column') !== false
                || strpos($msg, '1061') !== false || stripos($msg, 'Duplicate key name') !== false) {
                $say('   跳过     ' . $label . '（已经在了）');
            } else {
                $say('   失败     ' . $label . ' : ' . $msg);
            }
        }
    };
    foreach ($gapsNow as $gap) {
        if (strpos($gap, 'col:') === 0) {
            [, $tc] = explode(':', $gap, 2);
            [$t, $c] = explode('.', $tc, 2);
            foreach ($cols as [$ct, $cc, $ddl]) {
                if ($ct === $t && $cc === $c) { $run($ddl, $gap); }
            }
        } elseif (strpos($gap, 'idx:') === 0) {
            [, $tc] = explode(':', $gap, 2);
            [$t, $i] = explode('.', $tc, 2);
            foreach ($idxs as [$it, $ii, $ddl]) {
                if ($it === $t && $ii === $i) { $run($ddl, $gap); }
            }
        } elseif ($gap === 'uk:drop2') {
            $run('ALTER TABLE `duty_configs` DROP INDEX `uk_tenant_type`', $gap);
        } elseif ($gap === 'uk:add3') {
            $run('ALTER TABLE `duty_configs` ADD UNIQUE KEY `uk_tenant_type` (`tenant_id`, `duty_type`, `game_side`)', $gap);
        }
    }
    $still = $gaps();
    $say('');
    if ($still === []) {
        $say('[结果] 齐了，自检通过。');
        $selfDel();
    } else {
        $say('[结果] 还缺 ' . count($still) . ' 项：' . implode('、', array_slice($still, 0, 8))
            . ' —— 请再点一次，或改用 database/upgrade_v5.35.1_to_v5.36.0.sql');
    }
    $stop();
}

$say('');
$say('下面这个按钮做三件事：加十列、加九条索引、把值班唯一键换成带端别的三列。');
$say('不建表、不删数据、不刷任何已有行。所有老数据的 game_side 都是空串，行为与升级前一致。');
echo '<pre>' . implode("\n", array_map($h, $out)) . '</pre>';
echo '<form method="post" enctype="application/x-www-form-urlencoded">';
echo '<input type="hidden" name="do" value="apply">';
echo '<input type="hidden" name="token" value="' . $h($token) . '">';
echo '<button type="submit" style="font-size:16px;padding:10px 22px">执行补库（端手分离）</button>';
echo '</form>';
