<?php
/**
 * v5.36.31 强制平台公告补库工具（平台超级管理员登录态下才输出内容）
 *
 * 打开这一个链接、点一个按钮，库就补上两张表：
 *   platform_announcements        平台超管发的公告（标题 + 正文 + 在不在弹）
 *   platform_announcement_reads   每个人对每一条的回应（我已知晓 / 不再提示）
 *
 * 只建表，不删列、不改任何已有行；邮件群发、战队自己的公告栏、站内信原样保留。
 * 重复点安全：先查 information_schema，表已在就只做自检。
 * 跑齐了本文件会自己删掉自己。
 *
 * 也可以不点按钮，直接执行 database/upgrade_v5.36.30_to_v5.36.31.sql，两件事完全一样。
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
$say('=== v5.36.31 强制平台公告补库工具  ' . date('Y-m-d H:i:s') . ' ===');
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
$idxCols = static function (string $t, string $idx) use ($cur): int {
    $r = Db::query('SELECT COUNT(*) AS c FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?', [$cur, $t, $idx]);
    return (int) ($r[0]['c'] ?? 0);
};

// 建表语句一份（与 database/game_team.sql、upgrade_v5.36.30_to_v5.36.31.sql 逐字节相同）
$DDL = [
    'platform_announcements' => <<<'SQL1'
CREATE TABLE IF NOT EXISTS `platform_announcements` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `title`      VARCHAR(120) NOT NULL              COMMENT '公告标题(弹窗大标题)',
  `content`    TEXT         NOT NULL              COMMENT '公告正文(纯文本,换行原样显示,应用层限 2000 字)',
  `status`     TINYINT      NOT NULL DEFAULT 1    COMMENT '1=在线弹出 0=已下线(下线后不再弹,已读记录保留)',
  `actor_id`   INT UNSIGNED NOT NULL DEFAULT 0    COMMENT '发布人(platform_users.id)',
  `actor_name` VARCHAR(64)  NOT NULL DEFAULT ''   COMMENT '发布人登录名快照',
  `created_at` DATETIME              DEFAULT NULL,
  `updated_at` DATETIME              DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_pna_status` (`status`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='强制平台公告(v5.36.31)'
SQL1
    ,
    'platform_announcement_reads' => <<<'SQL2'
CREATE TABLE IF NOT EXISTS `platform_announcement_reads` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `announcement_id` INT UNSIGNED NOT NULL              COMMENT '哪一条公告;0=这个人点了「不再提示」,对以后所有公告静音',
  `user_id`         INT UNSIGNED NOT NULL              COMMENT '哪个人(users.id,全站唯一)',
  `tenant_id`       INT UNSIGNED NOT NULL DEFAULT 0    COMMENT '点这条的人当时在哪个队(汇总按人现在的队算,这列留档)',
  `choice`          VARCHAR(6)   NOT NULL DEFAULT 'ack' COMMENT 'ack=我已知晓 mute=不再提示',
  `created_at`      DATETIME              DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_pnar_ann_user` (`announcement_id`, `user_id`),
  KEY `idx_pnar_ann` (`announcement_id`),
  KEY `idx_pnar_tenant` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='强制平台公告送达确认(v5.36.31)'
SQL2
];

$gaps = [];
foreach ($DDL as $t => $sql) {
    if (!$hasTable($t)) { $gaps[] = 'table:' . $t; }
}
if ($hasTable('platform_announcements') && $idxCols('platform_announcements', 'idx_pna_status') === 0) {
    $gaps[] = 'idx:idx_pna_status';
}
if ($hasTable('platform_announcement_reads') && $idxCols('platform_announcement_reads', 'uk_pnar_ann_user') === 0) {
    $gaps[] = 'idx:uk_pnar_ann_user';
}

$say('');
$say('[体检] 现在还缺：' . ($gaps === [] ? '什么都不缺' : implode('、', $gaps)));

$token = substr(hash('sha256', $cookieNm . '|' . ($session->getId() ?? '') . '|notice53631'), 0, 16);
$wants = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
    && (string) ($_POST['do'] ?? '') === 'apply'
    && hash_equals($token, (string) ($_POST['token'] ?? ''));

$selfDel = static function () use ($say): void {
    $deleted = @unlink(__FILE__);
    $say($deleted ? '       本文件已自动删掉自己（public/notice_install_v53631.php）。'
        : '       本文件删不掉自己（权限），请手动删除 public/notice_install_v53631.php。');
};

if ($gaps === []) {
    $say('');
    $say('[结果] 两张表与索引都在，库这一版已经齐了。');
    $say('       传代码、强刷（资源版本号 5.36.31）：平台后台「强制公告」能发，成员登录后自动弹出，');
    $say('       邮件群发里写 {team} {name} {qq} {role} {date} 也会按每个人自己替换。');
    $selfDel();
    $stop();
}

if ($wants) {
    $say('');
    $say('[执行] 开始补 ' . count($gaps) . ' 项…');
    foreach ($DDL as $t => $sql) {
        if (in_array('table:' . $t, $gaps, true) || !$hasTable($t)) {
            try {
                Db::execute($sql);
                $say('   好了     ' . $t);
            } catch (\Throwable $e) {
                $msg = $e->getMessage();
                if (strpos($msg, '1050') !== false || stripos($msg, 'already exists') !== false) {
                    $say('   跳过     ' . $t . '（已经在了）');
                } else {
                    $say('   失败     ' . $t . ' : ' . $msg);
                }
            }
        }
    }
    foreach ([['platform_announcements', 'idx_pna_status', 'ADD KEY `idx_pna_status` (`status`, `id`)'],
              ['platform_announcement_reads', 'uk_pnar_ann_user', 'ADD UNIQUE KEY `uk_pnar_ann_user` (`announcement_id`, `user_id`)']] as $one) {
        [$t, $idx, $clause] = $one;
        if ($hasTable($t) && $idxCols($t, $idx) === 0) {
            try {
                Db::execute('ALTER TABLE `' . $t . '` ' . $clause);
                $say('   好了     ' . $idx);
            } catch (\Throwable $e) {
                $say('   跳过     ' . $idx . '（已经在了）');
            }
        }
    }
    $still = [];
    foreach ($DDL as $t => $sql) {
        if (!$hasTable($t)) { $still[] = 'table:' . $t; }
    }
    if ($hasTable('platform_announcements') && $idxCols('platform_announcements', 'idx_pna_status') === 0) {
        $still[] = 'idx:idx_pna_status';
    }
    if ($hasTable('platform_announcement_reads') && $idxCols('platform_announcement_reads', 'uk_pnar_ann_user') === 0) {
        $still[] = 'idx:uk_pnar_ann_user';
    }
    $say('');
    if ($still === []) {
        $say('[结果] 齐了，自检通过。');
        $selfDel();
    } else {
        $say('[结果] 还缺 ' . implode('、', $still)
            . ' —— 请再点一次，或改用 database/upgrade_v5.36.30_to_v5.36.31.sql');
    }
    $stop();
}

$say('');
$say('下面这个按钮只做一件事：新建强制平台公告那两张表。');
$say('不删列、不删数据、不改任何已有行；已有的邮件群发记录与战队公告栏不受影响。');
echo '<pre>' . implode("\n", array_map($h, $out)) . '</pre>';
echo '<form method="post" enctype="application/x-www-form-urlencoded">';
echo '<input type="hidden" name="do" value="apply">';
echo '<input type="hidden" name="token" value="' . $h($token) . '">';
echo '<button type="submit" style="font-size:16px;padding:10px 22px">执行补库（强制平台公告）</button>';
echo '</form>';
