<?php
declare (strict_types = 1);

namespace app\service;

use think\facade\Db;
use ZipArchive;

/**
 * 站点数据导入导出（v5.2.0）
 *
 * 面向使用开源版本（如 v3.9.6.1）自建站点或想迁移数据的战队：
 *   导出：把本站数据（成员/小队/图片/枪码/聊天/公告/攻略/日程等，含图片文件）
 *         打包成 zip；不含今日轮换地图数据（轮换归平台统一配置）
 *   导入：上传平台导出的 zip 包，审核通过后整站覆盖导入
 * 规则：任务需平台超管审核同意后才执行；每天各 1 次机会；
 *       站点成员 5 人以上才可导出，新站点只可导入。
 */
class DataTransfer
{
    public const FORMAT  = 'game-team-export';
    public const VERSION = 1;
    public const MIN_EXPORT_MEMBERS = 5;

    // v5.2.4：数据包内文件安全约束（投毒防护）
    // 包内文件最终会落到 /storage/uploads/ 公网目录，只允许图片扩展名，
    // 防止恶意包夹带 .php/.html/.svg 等可执行/可脚本文件形成 webshell 或 XSS
    public const FILE_EXT_ALLOW   = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    public const MAX_ENTRY_BYTES  = 20971520;    // 单文件解压后 ≤ 20MB
    public const MAX_IMPORT_BYTES = 536870912;   // 包内文件解压后总量 ≤ 512MB（防 zip 炸弹）

    /** 数据包内单个文件条目的问题原因；null 表示安全（路径穿越 / 扩展名白名单 / 大小上限） */
    protected static function entryReason(string $entry, int $size): ?string
    {
        if ($entry === '' || strpos($entry, 'files/') !== 0) {
            return '非法条目';
        }
        $rel = substr($entry, strlen('files/'));
        if ($rel === '' || strpos($rel, '..') !== false) {
            return '含目录穿越路径';
        }
        $base = strtolower(basename($rel));
        if ($base === '' || $base[0] === '.') {
            return '含隐藏文件';   // 空名或 .htaccess 等
        }
        $ext = strtolower(pathinfo($base, PATHINFO_EXTENSION));
        if (!in_array($ext, self::FILE_EXT_ALLOW, true)) {
            return '含不允许的文件类型（仅允许 jpg/png/gif/webp 图片）';
        }
        if ($size < 0 || $size > self::MAX_ENTRY_BYTES) {
            return '包内文件过大（单文件上限 ' . round(self::MAX_ENTRY_BYTES / 1048576) . 'MB）';
        }
        return null;
    }

    protected static function entrySafe(string $entry, int $size): bool
    {
        return self::entryReason($entry, $size) === null;
    }

    /** 任务包存放目录（不走 web 直出，只能经鉴权接口下载） */
    public static function taskDir(): string
    {
        $dir = app()->getRootPath() . 'runtime' . DIRECTORY_SEPARATOR . 'data_tasks';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return $dir;
    }

    // ==================== 导出 ====================

    /**
     * 生成导出包（平台审核通过时调用）
     * @return array [文件绝对路径, 错误信息]，成功时错误为 null
     */
    public static function buildExport(int $tid): array
    {
        $tenant = Db::name('tenants')->where('id', $tid)->find();
        if (!$tenant) {
            return [null, '租户不存在'];
        }
        if (!class_exists(ZipArchive::class)) {
            return [null, '服务器未安装 zip 扩展，无法打包'];
        }

        $data = [];

        // 用户（含密码哈希，导入后可直接登录；保留旧 id 供导入时重建关联）
        $users = Db::name('users')->where('tenant_id', $tid)->order('id', 'asc')->select()->toArray();
        foreach ($users as &$u) {
            unset($u['tenant_id'], $u['last_active_at'], $u['updated_at']);
        }
        unset($u);
        $data['users'] = $users;

        $teams = Db::name('teams')->where('tenant_id', $tid)->order('id', 'asc')->select()->toArray();
        foreach ($teams as &$t) {
            unset($t['tenant_id'], $t['updated_at']);
        }
        unset($t);
        $data['teams'] = $teams;

        foreach ([
            'images'            => ['reviewed_at'],
            'gun_codes'         => [],
            'gun_code_comments' => [],
            'chat_messages'     => [],
            'announcements'     => [],
            'race_events'       => [],
            'custom_race_events'=> [],
            'guides'            => [],
        ] as $table => $unsetExtra) {
            $rows = Db::name($table)->where('tenant_id', $tid)->order('id', 'asc')->select()->toArray();
            foreach ($rows as &$r) {
                unset($r['tenant_id']);
                if (isset($r['updated_at'])) {
                    unset($r['updated_at']);
                }
                foreach ($unsetExtra as $x) {
                    unset($r[$x]);
                }
            }
            unset($r);
            $data[$table] = $rows;
        }

        // 站点设置（排除轮换地图相关——轮换归平台统一配置）
        $settings = Db::name('settings')->where('tenant_id', $tid)
            ->where('key', 'not like', 'map_rotation%')->select()->toArray();
        foreach ($settings as &$s) {
            unset($s['tenant_id']);
        }
        unset($s);
        $data['settings'] = $settings;

        // 收集图片文件：把 web 路径换成包内相对路径，物理文件打进 zip
        $zipPath = self::taskDir() . DIRECTORY_SEPARATOR
            . preg_replace('/[^a-z0-9_-]/', '', strtolower((string)$tenant['slug']))
            . '_export_' . date('YmdHis') . '_' . substr(bin2hex(random_bytes(3)), 0, 4) . '.zip';
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return [null, '打包文件创建失败'];
        }

        $publicRoot = rtrim(public_path(), DIRECTORY_SEPARATOR);
        $packFile = function (string $webPath) use ($zip, $publicRoot): string {
            if ($webPath === '' || strpos($webPath, '/storage/uploads/') !== 0) {
                return '';
            }
            $rel = ltrim(str_replace('/storage/uploads/', '', $webPath), '/');
            $abs = $publicRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, ltrim($webPath, '/'));
            if (!is_file($abs)) {
                return '';
            }
            $entry = 'files/' . $rel;
            $zip->addFile($abs, $entry);
            return $entry;
        };

        foreach ($data['users'] as &$u) {
            $u['profile_image'] = $packFile((string)$u['profile_image']);
        }
        unset($u);
        foreach ($data['images'] as &$r) {
            $r['file'] = $packFile((string)$r['file']);
        }
        unset($r);
        foreach ($data['guides'] as &$r) {
            $r['image'] = $packFile((string)$r['image']);
        }
        unset($r);
        foreach ($data['announcements'] as &$r) {
            $r['image'] = $packFile((string)$r['image']);
        }
        unset($r);

        // 品牌文件（战队logo / 队长证明截图）：随包携带，导入时恢复站点 logo
        $branding = ['logo' => '', 'screenshot' => ''];
        if ((string)$tenant['logo'] !== '') {
            $branding['logo'] = $packFile((string)$tenant['logo']);
        }
        $app = Db::name('tenant_applications')
            ->where('applicant_id', (int)$tenant['applicant_id'])
            ->where('status', 'approved')->order('id', 'desc')->find();
        if ($app && (string)$app['screenshot'] !== '') {
            $branding['screenshot'] = $packFile((string)$app['screenshot']);
        }

        $manifest = [
            'format'      => self::FORMAT,
            'version'     => self::VERSION,
            'tenant_name' => (string)$tenant['name'],
            'exported_at' => date('Y-m-d H:i:s'),
            'branding'    => $branding,
        ];
        $zip->addFromString('manifest.json', json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        $zip->addFromString('data.json', json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
        $zip->close();

        return [$zipPath, null];
    }

    // ==================== 导入 ====================

    /**
     * 快速校验上传的包是否合法（提交审核时检查，避免无效包占审核位）
     * @return string|null 错误信息，null 表示合法
     */
    public static function validatePackage(string $absPath): ?string
    {
        if (!class_exists(ZipArchive::class)) {
            return '服务器未安装 zip 扩展，无法解析数据包';
        }
        $zip = new ZipArchive();
        if ($zip->open($absPath) !== true) {
            return '文件不是有效的 zip 数据包';
        }
        $raw = $zip->getFromName('manifest.json');
        $manifest = $raw !== false ? json_decode($raw, true) : null;
        if (!is_array($manifest) || ($manifest['format'] ?? '') !== self::FORMAT) {
            $zip->close();
            return '不是本平台导出的数据包（缺少合法清单）';
        }
        if ($zip->getFromName('data.json') === false) {
            $zip->close();
            return '数据包缺少数据文件，可能已损坏';
        }
        // v5.2.4：上传即扫描包内文件，拒绝夹带可执行/可脚本文件或超大文件（投毒防护）
        $totalBytes = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (strpos($name, 'files/') !== 0) {
                continue;
            }
            $stat = $zip->statIndex($i) ?: [];
            $size = (int)($stat['size'] ?? 0);
            $reason = self::entryReason($name, $size);
            if ($reason !== null) {
                $zip->close();
                return '数据包' . $reason . '：' . $name;
            }
            $totalBytes += $size;
            if ($totalBytes > self::MAX_IMPORT_BYTES) {
                $zip->close();
                return '数据包解压后过大（上限 ' . round(self::MAX_IMPORT_BYTES / 1048576) . 'MB）';
            }
        }
        $zip->close();
        return null;
    }

    /**
     * 执行导入（平台审核通过时调用）：整站覆盖，保留当前站长账号防锁定
     * @return string|null 错误信息，null 表示成功
     */
    public static function runImport(int $tid, string $absPath): ?string
    {
        $zip = new ZipArchive();
        if ($zip->open($absPath) !== true) {
            return '数据包打开失败';
        }
        $manifest = json_decode((string)$zip->getFromName('manifest.json'), true);
        $data     = json_decode((string)$zip->getFromName('data.json'), true);
        if (!is_array($manifest) || ($manifest['format'] ?? '') !== self::FORMAT || !is_array($data)) {
            $zip->close();
            return '数据包内容不合法';
        }

        // 预估空间：覆盖导入后用量 = 入驻材料 + 包内文件
        $importBytes = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (strpos($name, 'files/') === 0) {
                $stat = $zip->statIndex($i);
                $importBytes += (int)($stat['size'] ?? 0);
            }
        }
        $appBytes = 0;
        foreach (Storage::overview($tid)['groups'] as $g) {
            if ($g['type'] === 'application') {
                $appBytes = $g['bytes'];
            }
        }
        if ($appBytes + $importBytes > Storage::quota($tid)) {
            $zip->close();
            return '导入后存储用量将超出配额，请先联系平台扩容';
        }

        // 解出包内文件到上传目录（保留原相对路径，重名自动加后缀防覆盖保护文件）
        $publicRoot = rtrim(public_path(), DIRECTORY_SEPARATOR);
        $restoreFile = function (string $entry) use ($zip, $publicRoot): string {
            // v5.2.5：空引用（数据包里没有对应附件的行，如无头像的成员）直接返回空，
            // 否则 statName('') 在 PHP 8 会抛 ValueError 导致整个导入失败
            if ($entry === '' || strpos($entry, 'files/') !== 0) {
                return '';
            }
            // v5.2.4：纵深防御——执行导入时再校验一次（扩展名白名单/路径穿越/大小），
            // 即使包绕过了上传校验也无法落盘可执行文件
            $stat = $zip->statName($entry) ?: [];
            if (!self::entrySafe($entry, (int)($stat['size'] ?? 0))) {
                return '';
            }
            $rel = substr($entry, strlen('files/'));
            $abs = $publicRoot . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'uploads'
                . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
            if (is_file($abs)) {
                $dir  = dirname($abs);
                $info = pathinfo($abs);
                $abs  = $dir . DIRECTORY_SEPARATOR . $info['filename'] . '_' . substr(bin2hex(random_bytes(3)), 0, 4)
                    . (isset($info['extension']) ? '.' . $info['extension'] : '');
            }
            if (!is_dir(dirname($abs))) {
                @mkdir(dirname($abs), 0755, true);
            }
            $content = $zip->getFromName($entry);
            if ($content === false || file_put_contents($abs, $content) === false) {
                return '';
            }
            return '/storage/uploads/' . str_replace(DIRECTORY_SEPARATOR, '/', $rel);
        };

        Db::startTrans();
        try {
            $tenant = Db::name('tenants')->where('id', $tid)->find();
            if (!$tenant) {
                throw new \RuntimeException('租户不存在');
            }

            // 1) 清场（保留入驻材料与当前站长账号）
            foreach (['gun_code_comments', 'gun_codes', 'chat_messages', 'images', 'guides',
                      'announcements', 'race_events', 'custom_race_events', 'teams'] as $table) {
                Db::name($table)->where('tenant_id', $tid)->delete();
            }
            Db::name('settings')->where('tenant_id', $tid)->delete();
            Db::name('admin_logs')->where('tenant_id', $tid)->delete();

            $ownerName = (string)$tenant['admin_username'];
            $owner = Db::name('users')->where('tenant_id', $tid)
                ->where('username', $ownerName)->where('role', 'admin')->find();
            Db::name('users')->where('tenant_id', $tid)
                ->where('id', '<>', $owner ? (int)$owner['id'] : 0)->delete();

            // 2) 用户（旧ID→新ID 映射；与站长同名的记录并入党站长，防锁定）
            $userMap = [];
            if ($owner) {
                $userMap[0] = (int)$owner['id'];   // 占位：未知引用兜底为站长
            }
            foreach ((array)($data['users'] ?? []) as $u) {
                $oldId = (int)($u['id'] ?? 0);
                if ((string)$u['username'] === $ownerName && $owner) {
                    $userMap[$oldId] = (int)$owner['id'];
                    continue;
                }
                $row = array_intersect_key((array)$u, array_flip([
                    'username', 'password', 'nickname', 'email', 'profile_image',
                    'reg_ip', 'reg_region', 'role', 'status', 'muted', 'admin_perms', 'created_at',
                ]));
                // 导入的管理身份一律降为普通成员：目标站点的管理员体系由本站站长重新分配，
                // 避免外站数据包带入第二个超管（越权风险）
                $row['role']        = 'user';
                $row['admin_perms'] = '';
                $row['tenant_id'] = $tid;
                $row['profile_image'] = $restoreFile((string)($row['profile_image'] ?? ''));
                if (Db::name('users')->where('tenant_id', $tid)->where('username', $row['username'])->find()) {
                    continue;   // 用户名冲突（非站长）跳过，避免唯一约束报错
                }
                $row['team_id'] = null;
                $newId = (int)Db::name('users')->insertGetId($row);
                $userMap[$oldId] = $newId;
            }

            // 3) 小队 + 成员归属
            $teamMap = [];
            foreach ((array)($data['teams'] ?? []) as $t) {
                $oldId = (int)($t['id'] ?? 0);
                $row = array_intersect_key((array)$t, array_flip(['name', 'description', 'created_at']));
                $row['tenant_id']  = $tid;
                $row['leader_id']  = $userMap[(int)($t['leader_id'] ?? 0)] ?? null;
                $newId = (int)Db::name('teams')->insertGetId($row);
                $teamMap[$oldId] = $newId;
            }

            // 用户小队归属（users.team_id 引用旧小队ID）——读取导出时的原始映射
            foreach ((array)($data['users'] ?? []) as $u) {
                $newUid = $userMap[(int)($u['id'] ?? 0)] ?? null;
                $newTid = $teamMap[(int)($u['team_id'] ?? 0)] ?? null;
                if ($newUid && $newTid) {
                    Db::name('users')->where('id', $newUid)->update(['team_id' => $newTid]);
                }
            }

            // 4) 其余业务表
            $mapRef = function ($v) use ($userMap) {
                return $v ? ($userMap[(int)$v] ?? null) : null;
            };
            foreach ((array)($data['images'] ?? []) as $r) {
                Db::name('images')->insert([
                    'tenant_id'   => $tid,
                    'user_id'     => $userMap[(int)($r['user_id'] ?? 0)] ?? ($userMap[0] ?? 0),
                    'title'       => (string)($r['title'] ?? ''),
                    'file'        => $restoreFile((string)($r['file'] ?? '')),
                    'status'      => (string)($r['status'] ?? 'approved'),
                    'reviewed_by' => $mapRef($r['reviewed_by'] ?? null),
                    'created_at'  => (string)($r['created_at'] ?? date('Y-m-d H:i:s')),
                ]);
            }
            $codeMap = [];
            foreach ((array)($data['gun_codes'] ?? []) as $r) {
                $oldId = (int)($r['id'] ?? 0);
                $newId = (int)Db::name('gun_codes')->insertGetId([
                    'tenant_id'   => $tid,
                    'user_id'     => $userMap[(int)($r['user_id'] ?? 0)] ?? ($userMap[0] ?? 0),
                    'title'       => (string)($r['title'] ?? ''),
                    'code'        => (string)($r['code'] ?? ''),
                    'description' => (string)($r['description'] ?? ''),
                    'status'      => (string)($r['status'] ?? 'published'),
                    'created_at'  => (string)($r['created_at'] ?? date('Y-m-d H:i:s')),
                ]);
                $codeMap[$oldId] = $newId;
            }
            foreach ((array)($data['gun_code_comments'] ?? []) as $r) {
                $gcId = $codeMap[(int)($r['gun_code_id'] ?? 0)] ?? null;
                if (!$gcId) {
                    continue;
                }
                Db::name('gun_code_comments')->insert([
                    'tenant_id'   => $tid,
                    'gun_code_id' => $gcId,
                    'user_id'     => $userMap[(int)($r['user_id'] ?? 0)] ?? ($userMap[0] ?? 0),
                    'content'     => (string)($r['content'] ?? ''),
                    'created_at'  => (string)($r['created_at'] ?? date('Y-m-d H:i:s')),
                ]);
            }
            foreach ((array)($data['chat_messages'] ?? []) as $r) {
                Db::name('chat_messages')->insert([
                    'tenant_id'   => $tid,
                    'user_id'     => $userMap[(int)($r['user_id'] ?? 0)] ?? ($userMap[0] ?? 0),
                    'content'     => (string)($r['content'] ?? ''),
                    'recalled'    => (int)($r['recalled'] ?? 0),
                    'recalled_by' => $mapRef($r['recalled_by'] ?? null),
                    'created_at'  => (string)($r['created_at'] ?? date('Y-m-d H:i:s')),
                ]);
            }
            foreach ((array)($data['announcements'] ?? []) as $r) {
                Db::name('announcements')->insert([
                    'tenant_id'  => $tid,
                    'title'      => (string)($r['title'] ?? ''),
                    'content'    => (string)($r['content'] ?? ''),
                    'image'      => $restoreFile((string)($r['image'] ?? '')),
                    'created_at' => (string)($r['created_at'] ?? date('Y-m-d H:i:s')),
                ]);
            }
            foreach ((['race_events' => 'race_events', 'custom_race_events' => 'custom_race_events']) as $src => $dst) {
                foreach ((array)($data[$src] ?? []) as $r) {
                    Db::name($dst)->insert([
                        'tenant_id'  => $tid,
                        'title'      => (string)($r['title'] ?? ''),
                        'race_time'  => (string)($r['race_time'] ?? date('Y-m-d H:i:s')),
                        'created_at' => (string)($r['created_at'] ?? date('Y-m-d H:i:s')),
                    ]);
                }
            }
            foreach ((array)($data['guides'] ?? []) as $r) {
                Db::name('guides')->insertGetId([
                    'tenant_id'  => $tid,
                    'user_id'    => $userMap[(int)($r['user_id'] ?? 0)] ?? ($userMap[0] ?? 0),
                    'title'      => (string)($r['title'] ?? ''),
                    'content'    => (string)($r['content'] ?? ''),
                    'image'      => $restoreFile((string)($r['image'] ?? '')),
                    'status'     => (string)($r['status'] ?? 'published'),
                    'created_at' => (string)($r['created_at'] ?? date('Y-m-d H:i:s')),
                ]);
            }
            foreach ((array)($data['settings'] ?? []) as $s) {
                $key = (string)($s['key'] ?? '');
                if ($key === '' || strpos($key, 'map_rotation') === 0) {
                    continue;   // 轮换归平台统一配置，不导入
                }
                Db::name('settings')->insert([
                    'tenant_id' => $tid,
                    'key'       => $key,
                    'value'     => (string)($s['value'] ?? ''),
                ]);
            }

            // 5) 恢复站点 logo
            $logoEntry = (string)($manifest['branding']['logo'] ?? '');
            if ($logoEntry !== '') {
                $logoPath = $restoreFile($logoEntry);
                if ($logoPath !== '') {
                    Db::name('tenants')->where('id', $tid)->update(['logo' => $logoPath]);
                }
            }

            Db::commit();
            $zip->close();
            return null;
        } catch (\Throwable $e) {
            Db::rollback();
            $zip->close();
            return '导入失败：' . $e->getMessage();
        }
    }
}
