<?php
declare (strict_types = 1);

namespace app\service;

use think\facade\Db;

/**
 * 战队销毁（v5.36.32）：被封满 15 天的站点，平台超级管理员可以整队删掉。
 *
 * 六条口径：
 *   ① 15 天从「最后一次被封」起算 —— 恢复站点就把 banned_at 置空，再封重新数。
 *      这一列是本版加的，老的封禁站点没起点时一律按「没到 15 天」处理，那颗钮不会亮。
 *   ② 只有平台超级管理员在平台根路径下能删；协作会话与隐身巡查都够不到这条路。
 *   ③ 删之前强制打一个备份包（全部行 + 它引用的图片）落 runtime/data_tasks，
 *      打包失败就一条语句都不执行。tenant_purges 那一行是这个包唯一的登记处。
 *   ④ 图片按「数据库里的引用」删，不按目录删：只删这一队引用、且删完之后全库再没人
 *      引用的文件。别人的、平台表情包库/轮换地图池里的、目录里落单的，一律不动。
 *   ⑤ 跨队约战（battles / battle_rounds）留着：那场约战有一半属于对面战队，
 *      对面看到的那一格由 BattleService::partyName() 回显成「已注销战队」。
 *      本队那一方的报名（battle_signups 带 tenant_id）随本队一起删。
 *   ⑥ 删完只剩 tenant_purges 与 admin_logs(tenant_id=0) 两条痕迹；库没升级时
 *      所有读路径先 available() 再查，界面不会报表不存在。
 */
class TenantPurge
{
    /** 封满这么多天才允许销毁 */
    public const AFTER_DAYS = 15;

    /** prepare 到 confirm 之间的有效秒数（一次一次性令牌只够点一次） */
    public const TOKEN_TTL = 300;

    /** 销毁登记表中备份包名的前缀，下载时按这个形状挡一眼 */
    public const BACKUP_PREFIX = 'purge_';

    /** 这张表是销毁登记本身，绝不能跟着被删的站一起删掉 */
    private const KEEP_TABLES = ['tenant_purges'];

    /** 「这个站的图片」的引用位置：[表, 列, 说明]（settings 只认下面那三个图片键） */
    public const SETTING_IMAGE_KEYS = ['login_poster', 'show_logo', 'show_banner'];

    // ==================== 库没升级时不炸 ====================

    /** tenants.banned_at 与 tenant_purges 都在，才认为这一版可以干活 */
    public static function available(): bool
    {
        try {
            return self::hasColumn('tenants', 'banned_at') && self::hasTable('tenant_purges');
        } catch (\Throwable $e) {
            return false;
        }
    }

    private static function driver(): string
    {
        return (string) \think\facade\Config::get('database.default', '');
    }

    private static function hasTable(string $t): bool
    {
        if (self::driver() === 'mysql') {
            $r = Db::query('SELECT COUNT(*) AS c FROM information_schema.TABLES
                            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$t]);
            return (int) ($r[0]['c'] ?? 0) > 0;
        }
        $r = Db::query("SELECT COUNT(*) AS c FROM sqlite_master WHERE type IN ('table','view') AND name = ?", [$t]);
        return (int) ($r[0]['c'] ?? 0) > 0;
    }

    private static function hasColumn(string $t, string $c): bool
    {
        if (self::driver() === 'mysql') {
            $r = Db::query('SELECT COUNT(*) AS n FROM information_schema.COLUMNS
                            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$t, $c]);
            return (int) ($r[0]['n'] ?? 0) > 0;
        }
        foreach (Db::query('PRAGMA table_info(' . $t . ')') as $row) {
            if ((string) ($row['name'] ?? '') === $c) {
                return true;
            }
        }
        return false;
    }

    /** 所有带 tenant_id 列的表（不含 tenants 自己；这张表按主键删） */
    public static function tables(): array
    {
        if (self::driver() === 'mysql') {
            $rows = Db::query('SELECT TABLE_NAME AS t FROM information_schema.COLUMNS
                               WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = ?
                               ORDER BY TABLE_NAME', ['tenant_id']);
            $list = array_column($rows, 't');
        } else {
            $list = [];
            foreach (Db::query("SELECT name FROM sqlite_master WHERE type = 'table' ORDER BY name") as $r) {
                $n = (string) ($r['name'] ?? '');
                if ($n === '' || strpos($n, 'sqlite_') === 0) {
                    continue;
                }
                foreach (Db::query('PRAGMA table_info(' . $n . ')') as $col) {
                    if ((string) ($col['name'] ?? '') === 'tenant_id') {
                        $list[] = $n;
                        break;
                    }
                }
            }
        }
        $out = [];
        foreach ($list as $t) {
            $t = (string) $t;
            if ($t !== '' && $t !== 'tenants' && !in_array($t, self::KEEP_TABLES, true)) {
                $out[] = $t;
            }
        }
        return $out;
    }

    // ==================== 15 天那条线 ====================

    /** 已经封了几天（不满一天算 0 天）；没封或没有起点都按 0 */
    public static function daysBanned(array $tenant): int
    {
        if ((string) ($tenant['status'] ?? '') !== 'banned') {
            return 0;
        }
        $start = self::banStart((string) ($tenant['banned_at'] ?? ''), (string) ($tenant['updated_at'] ?? ''));
        if ($start === 0) {
            return 0;
        }
        $d = intdiv(time() - $start, 86400);
        return $d > 0 ? $d : 0;
    }

    /**
     * 封禁起点。老站点这一列是空的 —— 这里刻意不做兜底：
     * 拿 updated_at 猜一个起点，等于让一次升级把某些站直接推到「可以删」，那是不可逆的。
     * 所以没起点就是没起点，界面会说清楚，补起点只认升级脚本那一条 UPDATE。
     */
    private static function banStart(string $bannedAt, string $fallback): int
    {
        $bannedAt = trim($bannedAt);
        if ($bannedAt === '' || $bannedAt === '0000-00-00 00:00:00' || strpos($bannedAt, '0000-00') === 0) {
            return 0;
        }
        $ts = strtotime($bannedAt);
        return $ts === false ? 0 : $ts;
    }

    /** 给列表用的一行摘要：封了多少天、还差几天、能不能删、为什么不能 */
    public static function view(array $tenant): array
    {
        $status = (string) ($tenant['status'] ?? '');
        $days   = self::daysBanned($tenant);
        $start  = self::banStart((string) ($tenant['banned_at'] ?? ''), '');
        $out = [
            'banned'      => $status === 'banned',
            'banned_at'   => $start > 0 ? date('Y-m-d H:i', $start) : '',
            'days'        => $days,
            'need_days'   => max(0, self::AFTER_DAYS - $days),
            'ready'       => false,
            'reason'      => '',
        ];
        if (!self::available()) {
            $out['reason'] = '这一版的库还没补（缺 tenants.banned_at 或 tenant_purges 表）';
            return $out;
        }
        if ($status !== 'banned') {
            $out['reason'] = '这个站点现在没被封';
            return $out;
        }
        if ($start === 0) {
            $out['reason'] = '这个站点是在加这一列之前封的，没有封禁起点';
            return $out;
        }
        if ($out['need_days'] > 0) {
            $out['reason'] = '还差 ' . $out['need_days'] . ' 天';
            return $out;
        }
        $blocked = self::blocked($tenant);
        if ($blocked !== '') {
            $out['reason'] = $blocked;
            return $out;
        }
        $out['ready'] = true;
        $out['reason'] = '封满 ' . self::AFTER_DAYS . ' 天，可以销毁';
        return $out;
    }

    /** 时间到了还不许删的情形：转移流程正卡着、或已经销毁过 */
    private static function blocked(array $tenant): string
    {
        $tid = (int) ($tenant['id'] ?? 0);
        if ($tid <= 0) {
            return '这个站点没有 ID';
        }
        try {
            $n = (int) Db::name('tenant_transfers')->where('tenant_id', $tid)
                ->whereIn('status', ['pending', 'cooling'])->count();
            if ($n > 0) {
                return '这个站点有一张没走完的转移单，先把它撤掉或走完';
            }
        } catch (\Throwable $e) {
            // 转移单表不在就当没有这一项检查，后面 purge 里还会再问一次
        }
        try {
            if (self::available() && (int) Db::name('tenant_purges')->where('tenant_id', $tid)->count() > 0) {
                return '这个 ID 之前已经销毁过一次';
            }
        } catch (\Throwable $e) {
            // 读不到登记表时不拦，真销毁前还会再查一次
        }
        return '';
    }

    /** 能不能动手；能就返回 null，不能就返回给用户看的那句话 */
    public static function refuse(array $tenant): ?string
    {
        $v = self::view($tenant);
        return $v['ready'] ? null : ($v['reason'] !== '' ? $v['reason'] : '现在还不能销毁这个站点');
    }

    // ==================== 引用到的图片 ====================

    /** 这个站引用的图片 web 路径（去重、只留 /storage/uploads/ 开头的） */
    public static function filesOf(int $tid): array
    {
        $paths = [];
        $add = static function ($p) use (&$paths): void {
            $p = trim((string) $p);
            if ($p !== '' && strpos($p, '/storage/uploads/') === 0 && strpos($p, '..') === false) {
                $paths[$p] = true;
            }
        };
        try {
            $add((string) Db::name('tenants')->where('id', $tid)->value('logo'));
        } catch (\Throwable $e) {
        }
        // 这张表里是「这个站引用到的上传图」。不含 rotation_maps（那是平台池子，
        // tenant_id 恒为 0）与 checkins.fortune_img（那是表情包编号，不是路径）。
        foreach ([['users', 'profile_image'], ['images', 'file'], ['guides', 'image'],
                  ['announcements', 'image'], ['showcase_items', 'image'],
                  ['show_submissions', 'image'], ['password_resets', 'screenshot'],
                  ['training_rounds', 'evidence']] as [$t, $c]) {
            try {
                foreach (Db::name($t)->where('tenant_id', $tid)->where($c, '<>', '')->column($c) as $p) {
                    $add($p);
                }
            } catch (\Throwable $e) {
                // 某张表不在这库里就当没有这一类文件
            }
        }
        try {
            foreach (Db::name('settings')->where('tenant_id', $tid)
                     ->whereIn('key', self::SETTING_IMAGE_KEYS)
                     ->where('value', '<>', '')->column('value') as $p) {
                $add($p);
            }
        } catch (\Throwable $e) {
        }
        // 入驻材料里那两张（logo / 队长证明截图）是这一队的，销毁时一并收走
        $applicant = (int) Db::name('tenants')->where('id', $tid)->value('applicant_id');
        if ($applicant > 0) {
            try {
                $rows = Db::name('tenant_applications')->where('applicant_id', $applicant)
                    ->where('slug', (string) Db::name('tenants')->where('id', $tid)->value('slug'))
                    ->select()->toArray();
                foreach ($rows as $a) {
                    $add((string) ($a['logo'] ?? ''));
                    $add((string) ($a['screenshot'] ?? ''));
                }
            } catch (\Throwable $e) {
            }
        }
        return array_keys($paths);
    }

    /** web 路径 → 磁盘绝对路径（不在这目录外面就返回 null） */
    public static function absOf(string $webPath): ?string
    {
        if ($webPath === '' || strpos($webPath, '/storage/uploads/') !== 0 || strpos($webPath, '..') !== false) {
            return null;
        }
        $root = rtrim((string) public_path(), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'uploads';
        $abs  = $root . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, substr($webPath, strlen('/storage/uploads/')));
        $real = @realpath($abs);
        $realRoot = @realpath($root);
        if ($real === false || $realRoot === false || strpos($real, $realRoot . DIRECTORY_SEPARATOR) !== 0) {
            return null;
        }
        return is_file($real) ? $real : null;
    }

    /**
     * 全库还在被引用的文件名集合（删完这个站的行之后再取，别拿删之前的当依据）。
     * 按「文件名」而不是整条路径比：同一张图可能被写成带不带前导斜杠两种形式。
     * 这一份里必须包含不会被删掉的那些引用源 —— 约战小局截图 battle_rounds（那场比赛有一半
     * 属于对面战队）、平台地图池 rotation_maps、平台表情包库 ai_stickers：它们留着的行
     * 引用到的图，一张都不许跟着这一队走。
     */
    public static function referencedNames(): array
    {
        $names = [];
        $take = static function (array $rows) use (&$names): void {
            foreach ($rows as $row) {
                $v = trim((string) ($row['v'] ?? ''));
                if ($v === '' || strpos($v, '/storage/uploads/') === false) {
                    continue;
                }
                $base = basename(str_replace('\\', '/', $v));
                if ($base !== '' && $base !== '.') {
                    $names[$base] = true;
                }
            }
        };
        $cols = [['users', 'profile_image'], ['images', 'file'], ['guides', 'image'],
                 ['announcements', 'image'], ['showcase_items', 'image'],
                 ['show_submissions', 'image'], ['password_resets', 'screenshot'],
                 ['training_rounds', 'evidence'], ['battle_rounds', 'evidence'],
                 ['tenants', 'logo'], ['tenant_applications', 'logo'],
                 ['tenant_applications', 'screenshot'], ['ai_stickers', 'path'],
                 ['rotation_maps', 'image'], ['settings', 'value']];
        foreach ($cols as [$t, $c]) {
            try {
                $take(Db::query('SELECT ' . $c . ' AS v FROM ' . $t . ' WHERE ' . $c . " LIKE '%/storage/uploads/%'"));
            } catch (\Throwable $e) {
            }
        }
        return array_keys($names);
    }

    /**
     * 给一组「曾经被引用」的上传图，把其中现在全站再没有任何行引用的那些删掉。
     * 用的就是上面那份引用清单（referencedNames），所以别的队还在用的一张都不会动。
     * 调用时机：那些行已经从库里删掉、事务也已经提交之后（提交前来删，回滚了图就真没了）。
     * @return array{0:int,1:int} [删掉的张数, 留下的张数]
     */
    public static function dropUnreferenced(array $paths): array
    {
        $ref = [];
        foreach (self::referencedNames() as $n) {
            $ref[$n] = true;
        }
        $killed = 0;
        $kept   = 0;
        foreach ($paths as $p) {
            $abs = self::absOf((string) $p);
            if ($abs === null) {
                continue;   // 不在这目录里 / 文件本来就不在
            }
            $base = basename(str_replace('\\', '/', $abs));
            if ($base !== '' && isset($ref[$base])) {
                $kept++;     // 还有别人在用，留着
                continue;
            }
            if (@unlink($abs)) {
                $killed++;
            } else {
                $kept++;
            }
        }
        return [$killed, $kept];
    }

    // ==================== 预览 ====================

    /** 点「删除战队」时先看的那一眼：成员数、各表行数、图片数、占用字节 */
    public static function preview(int $tid): array
    {
        $tables = [];
        $rows = 0;
        foreach (self::tables() as $t) {
            try {
                $n = (int) Db::name($t)->where('tenant_id', $tid)->count();
            } catch (\Throwable $e) {
                continue;
            }
            if ($n > 0) {
                $tables[$t] = $n;
                $rows += $n;
            }
        }
        try {
            $uids = Db::name('users')->where('tenant_id', $tid)->column('id');
            $notices = $uids === [] ? 0 : (int) Db::name('forum_notices')->whereIn('to_user_id', $uids)->count();
        } catch (\Throwable $e) {
            $notices = 0;
        }
        if ($notices > 0) {
            $tables['forum_notices'] = $notices;
            $rows += $notices;
        }
        $files = self::filesOf($tid);
        $bytes = 0;
        $live = [];
        foreach ($files as $p) {
            $abs = self::absOf($p);
            if ($abs !== null) {
                $bytes += (int) filesize($abs);
                $live[] = $p;
            }
        }
        $member = 0;
        try {
            $member = (int) Db::name('users')->where('tenant_id', $tid)->count();
        } catch (\Throwable $e) {
        }
        $battles = 0;
        try {
            $battles = (int) Db::name('battles')->where(function ($q) use ($tid) {
                $q->where('challenger_type', 'tenant')->where('challenger_id', $tid);
            })->whereOr(function ($q) use ($tid) {
                $q->where('defender_type', 'tenant')->where('defender_id', $tid);
            })->count();
        } catch (\Throwable $e) {
        }
        // tenants 自己那一行也算进去：deleteRows 是连着它一起删的，两个数字要能对上
        $rows++;
        return [
            'tenant_id'    => $tid,
            'member_count' => $member,
            'row_count'    => $rows,
            'table_count'  => count($tables),
            'tables'       => $tables,
            'file_count'   => count($files),
            'file_present' => count($live),
            'bytes'        => $bytes,
            // 这些是「留在库里、但对面看不到队名」的约战，说清楚免得以为也删了
            'battles_kept' => $battles,
        ];
    }

    // ==================== 一次性令牌 ====================

    /** 预览通过才发令牌：绑死「哪个站、哪个人、哪一次会话」，5 分钟过期 */
    public static function issueToken(int $tid, int $actorId): array
    {
        $plain = bin2hex(random_bytes(16));
        session('purge_token', [
            'hash'  => hash('sha256', $plain),
            'tid'   => $tid,
            'actor' => $actorId,
            'exp'   => time() + self::TOKEN_TTL,
        ]);
        return ['token' => $plain, 'expires_in' => self::TOKEN_TTL];
    }

    /** 校验令牌；通过返回 null，否则返回该说给用户的那句话 */
    public static function verifyToken(int $tid, string $token, int $actorId): ?string
    {
        $s = session('purge_token');
        if (!is_array($s) || empty($s['hash'])) {
            return '请先点「预览要删掉多少东西」拿到确认码，再点销毁';
        }
        if ((int) ($s['tid'] ?? 0) !== $tid) {
            session('purge_token', null);
            return '确认码是别的站点拿的，不能用在这个站点上';
        }
        if ((int) ($s['actor'] ?? -1) !== $actorId) {
            session('purge_token', null);
            return '确认码不是你这个账号拿的';
        }
        if ((int) ($s['exp'] ?? 0) < time()) {
            session('purge_token', null);
            return '确认码超时了（' . self::TOKEN_TTL . ' 秒），请重新预览一次';
        }
        if ($token === '' || !hash_equals((string) $s['hash'], hash('sha256', $token))) {
            return '确认码不对';
        }
        // 一次性的：用完就作废，重复点第二下拿不到东西
        session('purge_token', null);
        return null;
    }

    // ==================== 备份包 ====================

    /**
     * 打备份包：manifest.json + data.json +（MySQL 才有的）data.sql + files/ 图片原件
     * @return array [absPath, bytes, 说明数组, 错误]
     */
    public static function buildBackup(array $tenant): array
    {
        $tid = (int) $tenant['id'];
        if (!class_exists(\ZipArchive::class)) {
            return [null, 0, [], '服务器未安装 zip 扩展，打不出备份包 —— 这一版规定「备份失败就不删」，请先装 zip'];
        }
        $dir = DataTransfer::taskDir();
        $slug = preg_replace('/[^a-z0-9_-]/', '', strtolower((string) $tenant['slug']));
        $abs = $dir . DIRECTORY_SEPARATOR . self::BACKUP_PREFIX . $slug . '_' . date('YmdHis')
            . '_' . substr(bin2hex(random_bytes(3)), 0, 4) . '.zip';
        $zip = new \ZipArchive();
        $op = $zip->open($abs, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        if ($op !== true) {
            return [null, 0, [], '备份包写不到 runtime/data_tasks（错误码 ' . $op . '），请检查该目录权限'];
        }

        $data = [];
        $rows = 0;
        $data['tenants'] = [$tenant];
        $rows++;
        foreach (self::tables() as $t) {
            try {
                $rs = Db::name($t)->where('tenant_id', $tid)->order('id', 'asc')->select()->toArray();
            } catch (\Throwable $e) {
                continue;
            }
            if ($rs !== []) {
                $data[$t] = $rs;
                $rows += count($rs);
            }
        }
        try {
            $uids = Db::name('users')->where('tenant_id', $tid)->column('id');
            if ($uids !== []) {
                $fn = Db::name('forum_notices')->whereIn('to_user_id', $uids)->order('id', 'asc')->select()->toArray();
                if ($fn !== []) {
                    $data['forum_notices'] = $fn;
                    $rows += count($fn);
                }
            }
        } catch (\Throwable $e) {
        }

        $files = [];
        $missed = [];
        foreach (self::filesOf($tid) as $p) {
            $abs2 = self::absOf($p);
            if ($abs2 === null) {
                $missed[] = $p;
                continue;
            }
            // 包内条目一律用正斜杠：反斜杠会让解压出来是一堆带 \ 的怪文件名
            $zip->addFile($abs2, 'files/' . substr($p, strlen('/storage/uploads/')));
            $files[$p] = (int) filesize($abs2);
        }

        $zip->addFromString('data.json', json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
        $sql = self::dumpSql($data);
        if ($sql !== null) {
            $zip->addFromString('data.sql', $sql);
        }
        $manifest = [
            'format'       => 'game-team-purge',
            'version'      => 1,
            'purged_at'    => date('Y-m-d H:i:s'),
            'tenant_id'    => $tid,
            'slug'         => (string) $tenant['slug'],
            'name'         => (string) $tenant['name'],
            'status'       => (string) $tenant['status'],
            'banned_at'    => (string) ($tenant['banned_at'] ?? ''),
            'banned_days'  => self::daysBanned($tenant),
            'row_count'    => $rows,
            'table_count'  => count($data),
            'file_count'   => count($files),
            'file_bytes'   => array_sum($files),
            'files'        => $files,
            'files_missing'=> $missed,
            'app_debug'    => '包里有 members 的密码哈希、QQ 机器人密钥与私聊正文，请当机密收好',
            'how_to_restore' => [
                '图片：把包里的 files/ 目录整个放回站点的 public/storage/ 下（files → public/storage/uploads），路径原样。',
                '数据：MySQL 的包里有 data.sql，用 phpMyAdmin 选中库直接执行即可（表结构还在，只是行被删了）。',
                '只有 data.json 时：那是权威副本，逐行导入需要有人代跑，界面里没有这条路。',
                '注意：这个站的 ID 与 slug 已经空出来了，重新入驻会拿到新的 ID；data.sql 里写的是老 ID。',
            ],
        ];
        $zip->addFromString('manifest.json', json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        $zip->close();

        if (!is_file($abs) || (int) filesize($abs) <= 0) {
            return [null, 0, [], '备份包打完了但文件不在或为空，销毁中止（一行数据都没动）'];
        }
        return [$abs, (int) filesize($abs), $manifest, null];
    }

    /**
     * MySQL 才生成 data.sql（线上那台能用 phpMyAdmin 直接跑）；
     * 本地 SQLite 夹具跳过，避免把 MySQL 的引号习惯当还原依据。
     */
    private static function dumpSql(array $data): ?string
    {
        if (self::driver() !== 'mysql') {
            return null;
        }
        $esc = static function ($v): string {
            if ($v === null) {
                return 'NULL';
            }
            if (is_int($v) || is_float($v)) {
                return (string) $v;
            }
            if (is_bool($v)) {
                return $v ? '1' : '0';
            }
            if (is_array($v)) {
                $v = json_encode($v, JSON_UNESCAPED_UNICODE);
            }
            return "'" . str_replace(
                ['\\', "'", "\n", "\r", "\x00", "\x1A"],
                ['\\\\', "\\'", '\\n', '\\r', '\\0', '\\Z'],
                (string) $v
            ) . "'";
        };
        $lines = [
            '-- v5.36.32 销毁备份：这个站点的行数据（表结构请已经存在）',
            '-- 用法：phpMyAdmin 里选中你的库，整份执行；图片请另外从包里的 files/ 放回 public/storage/uploads/',
            '',
            'SET NAMES utf8mb4;',
            'SET FOREIGN_KEY_CHECKS = 0;',
            '',
        ];
        foreach ($data as $table => $rows) {
            $rows = array_values((array) $rows);
            if ($rows === []) {
                continue;
            }
            $cols = array_keys((array) $rows[0]);
            $lines[] = '-- ' . $table . '（' . count($rows) . ' 行）';
            $head = 'INSERT INTO `' . $table . '` (`' . implode('`, `', $cols) . '`) VALUES';
            $chunk = [];
            foreach ($rows as $r) {
                $vals = [];
                foreach ($cols as $c) {
                    $vals[] = $esc($r[$c] ?? null);
                }
                $chunk[] = '(' . implode(', ', $vals) . ')';
                if (count($chunk) >= 50) {
                    $lines[] = $head . "\n  " . implode(",\n  ", $chunk) . ';';
                    $chunk = [];
                }
            }
            if ($chunk !== []) {
                $lines[] = $head . "\n  " . implode(",\n  ", $chunk) . ';';
            }
            $lines[] = '';
        }
        $lines[] = 'SET FOREIGN_KEY_CHECKS = 1;';
        return implode("\r\n", $lines) . "\r\n";
    }

    // ==================== 真删 ====================

    /** runtime/data_tasks 里这个站点的导入导出包（销毁时连行一起删） */
    private static function taskFiles(int $tid): array
    {
        $out = [];
        try {
            foreach (Db::name('tenant_data_tasks')->where('tenant_id', $tid)->column('file') as $f) {
                $f = (string) $f;
                if ($f === '' || !is_file($f)) {
                    continue;
                }
                $dir = realpath(DataTransfer::taskDir());
                $real = realpath($f);
                if ($dir !== false && $real !== false && strpos($real, $dir . DIRECTORY_SEPARATOR) === 0) {
                    $out[] = $real;
                }
            }
        } catch (\Throwable $e) {
        }
        return $out;
    }

    /**
     * 执行销毁。调用方必须已经把「是不是平台超管、令牌对不对、slug 输入对不对」问完了，
     * 这里只再确认一次「库里的现状还允许不允许删」。
     * @return array{ok:bool,msg:string,stat:array}
     */
    public static function purge(int $tid, int $actorId, string $actorName): array
    {
        $stat = ['rows' => 0, 'tables' => 0, 'files' => 0, 'file_bytes' => 0, 'backup' => '', 'backup_bytes' => 0];
        $tenant = Db::name('tenants')->where('id', $tid)->find();
        if (!$tenant) {
            return ['ok' => false, 'msg' => '这个站点已经不在了（可能刚被删过）', 'stat' => $stat];
        }
        $deny = self::refuse($tenant);
        if ($deny !== null) {
            return ['ok' => false, 'msg' => $deny, 'stat' => $stat];
        }
        $blocked = self::blocked($tenant);
        if ($blocked !== '') {
            return ['ok' => false, 'msg' => $blocked, 'stat' => $stat];
        }

        // 先备份，备份不成一条语句都不执行 —— 这一版没有「删了再说」这条路
        [$backupAbs, $backupBytes, , $err] = self::buildBackup($tenant);
        if ($backupAbs === null) {
            return ['ok' => false, 'msg' => '备份包没打出来，销毁中止：' . $err, 'stat' => $stat];
        }
        $stat['backup'] = basename($backupAbs);
        $stat['backup_bytes'] = $backupBytes;

        $paths = self::filesOf($tid);
        $tasks = self::taskFiles($tid);
        $uids = [];
        try {
            $uids = Db::name('users')->where('tenant_id', $tid)->column('id');
        } catch (\Throwable $e) {
        }
        // 登记的数字要在动手之前定格：preview 里那些 count 一旦行删掉就再也问不出来了
        $pre = self::preview($tid) + [
            'slug'         => (string) $tenant['slug'],
            'name'         => (string) $tenant['name'],
            'applicant_id' => (int) ($tenant['applicant_id'] ?? 0),
            'banned_at'    => trim((string) ($tenant['banned_at'] ?? '')),
            'days'         => self::daysBanned($tenant),
        ];
        $rowsDeleted = 0;

        try {
            Db::transaction(function () use ($tid, $uids, $actorId, $actorName, $pre, $stat, &$rowsDeleted) {
                // ① 先登记：这条写不进去就不许往下删
                Db::name('tenant_purges')->insert([
                    'tenant_id'    => $tid,
                    'slug'         => $pre['slug'],
                    'name'         => $pre['name'],
                    'member_count' => (int) $pre['member_count'],
                    'row_count'    => (int) $pre['row_count'],
                    'file_count'   => (int) $pre['file_count'],
                    'bytes'        => (int) $pre['bytes'],
                    'banned_at'    => $pre['banned_at'] !== '' ? $pre['banned_at'] : null,
                    'banned_days'  => (int) $pre['days'],
                    'backup_file'  => (string) $stat['backup'],
                    'backup_bytes' => (int) $stat['backup_bytes'],
                    'actor_id'     => $actorId,
                    'actor_name'   => mb_substr($actorName, 0, 64),
                    'created_at'   => date('Y-m-d H:i:s'),
                ]);
                $rowsDeleted = self::deleteRows($tid, $uids);
                // ② 入驻材料那两张图：路径抹掉，申请审核的历史留着（不然文件永远「还被引用着」删不掉）
                if ((int) $pre['applicant_id'] > 0) {
                    try {
                        Db::name('tenant_applications')
                            ->where('applicant_id', (int) $pre['applicant_id'])
                            ->where('slug', $pre['slug'])
                            ->update(['logo' => '', 'screenshot' => '']);
                    } catch (\Throwable $e) {
                        // 抹不掉就留着：下一次 referencedNames() 会看见它，那张图就不会被删
                    }
                }
            });
        } catch (\Throwable $e) {
            @unlink($backupAbs);
            return ['ok' => false, 'msg' => '删除过程中出错，已回滚（数据一条没少，备份包也撤掉了）：'
                . $e->getMessage(), 'stat' => $stat];
        }

        // ③ 行没了再删文件：这时候全库还有谁引用同一张图，才看得见
        $keep = array_flip(self::referencedNames());
        $killed = 0;
        $killedBytes = 0;
        foreach ($paths as $p) {
            $abs = self::absOf($p);
            if ($abs === null) {
                continue;
            }
            $base = basename(str_replace('\\', '/', $p));
            if (isset($keep[$base])) {
                continue;   // 还有别人在用，留着
            }
            $sz = (int) @filesize($abs);
            if (@unlink($abs)) {
                $killed++;
                $killedBytes += $sz;
            }
        }
        // 这个站自己提交过的导入导出包（runtime/data_tasks 里那些）跟着一起清
        foreach ($tasks as $f) {
            @unlink($f);
        }

        $stat['rows'] = $rowsDeleted;
        $stat['tables'] = (int) $pre['table_count'];
        $stat['files'] = $killed;
        $stat['file_bytes'] = $killedBytes;

        try {
            Db::name('admin_logs')->insert([
                'tenant_id'  => 0,
                'admin_id'   => $actorId,
                'admin_name' => mb_substr($actorName, 0, 64),
                'action'     => '战队销毁',
                'detail'     => mb_substr('销毁 #' . $tid . ' ' . $pre['slug'] . '（' . $pre['name'] . '）：'
                    . $rowsDeleted . ' 行 / ' . $pre['member_count'] . ' 个成员 / 删掉 ' . $killed . ' 张图，'
                    . '备份包 ' . $stat['backup'], 0, 300),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            // 留痕写不进去不能说这次销毁失败：东西已经真删了，登记也在 tenant_purges 里
        }

        return ['ok' => true, 'msg' => '这个站点已经销毁，备份包已生成', 'stat' => $stat];
    }

    /** 删这个 tenant 在各表里的行；返回删掉的总行数 */
    private static function deleteRows(int $tid, array $uids): int
    {
        $rows = 0;
        foreach (self::tables() as $t) {
            try {
                $rows += (int) Db::name($t)->where('tenant_id', $tid)->delete();
            } catch (\Throwable $e) {
                throw new \RuntimeException('删 ' . $t . ' 的行时出错：' . $e->getMessage());
            }
        }
        if ($uids !== []) {
            try {
                $rows += (int) Db::name('forum_notices')->whereIn('to_user_id', $uids)->delete();
            } catch (\Throwable $e) {
                throw new \RuntimeException('删站内通知历史时出错：' . $e->getMessage());
            }
        }
        try {
            $rows += (int) Db::name('tenants')->where('id', $tid)->delete();
        } catch (\Throwable $e) {
            throw new \RuntimeException('删 tenants 那一行时出错：' . $e->getMessage());
        }
        return $rows;
    }

    // ==================== 登记与下载 ====================

    /** 已销毁的站点列表（平台后台那一节） */
    public static function listPurged(int $limit = 50): array
    {
        if (!self::available()) {
            return [];
        }
        try {
            $rows = Db::name('tenant_purges')->order('id', 'desc')->limit($limit)->select()->toArray();
        } catch (\Throwable $e) {
            return [];
        }
        $out = [];
        foreach ($rows as $r) {
            $f = (string) ($r['backup_file'] ?? '');
            $abs = self::backupAbs($f);
            $out[] = [
                'id'           => (int) $r['id'],
                'tenant_id'    => (int) $r['tenant_id'],
                'slug'         => (string) $r['slug'],
                'name'         => (string) $r['name'],
                'member_count' => (int) $r['member_count'],
                'row_count'    => (int) $r['row_count'],
                'file_count'   => (int) $r['file_count'],
                'bytes'        => (int) $r['bytes'],
                'banned_at'    => (string) ($r['banned_at'] ?? ''),
                'banned_days'  => (int) $r['banned_days'],
                'actor_name'   => (string) $r['actor_name'],
                'created_at'   => (string) ($r['created_at'] ?? ''),
                'backup_file'  => $f,
                'backup_bytes' => (int) ($r['backup_bytes'] ?? 0),
                'backup_alive' => $abs !== null,
            ];
        }
        return $out;
    }

    /** 备份包绝对路径：只认 runtime/data_tasks 里的文件名，不接受带路径的输入 */
    public static function backupAbs(string $file): ?string
    {
        $file = trim($file);
        if ($file === '' || basename($file) !== $file || strpos($file, self::BACKUP_PREFIX) !== 0
            || substr($file, -4) !== '.zip') {
            return null;
        }
        $abs = DataTransfer::taskDir() . DIRECTORY_SEPARATOR . $file;
        $real = @realpath($abs);
        $dir = @realpath(DataTransfer::taskDir());
        if ($real === false || $dir === false || $real !== $dir . DIRECTORY_SEPARATOR . $file) {
            return null;
        }
        return is_file($real) ? $real : null;
    }
}
