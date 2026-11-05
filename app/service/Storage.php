<?php
declare (strict_types = 1);

namespace app\service;

use think\facade\Db;

/**
 * 租户存储空间（v5.1.0）
 * 每个战队站点有独立存储配额（tenants.storage_quota，默认 2GB）；
 * 用量按数据库登记的上传文件统计（游戏图片 / 注册截图 / 攻略封面 / 公告配图），
 * 上传前校验剩余空间，管理中心可按分类查看与删除清理。
 */
class Storage
{
    public const DEFAULT_QUOTA = 2147483648; // 2GB

    /** 分类定义：type => [表, 文件列, 展示名] */
    protected static function groups(): array
    {
        return [
            'images'        => ['images', 'file', '游戏图片'],
            'profiles'      => ['users', 'profile_image', '注册审核截图'],
            'guides'        => ['guides', 'image', '攻略封面'],
            'announcements' => ['announcements', 'image', '公告配图'],
            // v5.20.0：对外展示页的队徽/Banner/图集/荣誉配图（一条记录一张图，删记录即删文件）
            'showcase'      => ['showcase_items', 'image', '对外展示页图片'],
        ];
    }

    /**
     * 入驻材料（v5.2.0：队长证明截图 + 战队logo，平台保护文件）
     * 计入存储用量并在管理中心展示，但禁止从存储清理中删除
     * @return array [ [label, webPath], ... ]
     */
    protected static function applicationFiles(int $tid): array
    {
        $applicantId = (int)Db::name('tenants')->where('id', $tid)->value('applicant_id');
        $app = $applicantId > 0
            ? Db::name('tenant_applications')
                ->where('applicant_id', $applicantId)
                ->where('status', 'approved')
                ->order('id', 'desc')->find()
            : null;
        // 兼容：查不到申请记录时退回租户表 logo
        if (!$app) {
            $logo = (string)Db::name('tenants')->where('id', $tid)->value('logo');
            return $logo !== '' ? [['战队logo（入驻材料）', $logo]] : [];
        }
        $files = [];
        if ((string)$app['screenshot'] !== '') {
            $files[] = ['队长证明截图（入驻材料）', (string)$app['screenshot']];
        }
        if ((string)$app['logo'] !== '') {
            $files[] = ['战队logo（入驻材料）', (string)$app['logo']];
        }
        return $files;
    }

    /**
     * 入驻材料文件路径集合（v5.2.1）
     * 队长证明截图开通时会复制到站长的注册截图（users.profile_image），
     * 用路径集合把这些文件在所有分类里标记为受保护，堵住绕过删除
     */
    protected static function protectedPaths(int $tid): array
    {
        $paths = [];
        foreach (self::applicationFiles($tid) as [, $path]) {
            if ($path !== '') {
                $paths[$path] = true;
            }
        }
        return $paths;
    }

    /** web 相对路径 → 字节数（文件不存在按 0 计） */
    public static function fileBytes(string $webPath): int
    {
        if ($webPath === '' || strpos($webPath, '/storage/uploads/') !== 0) {
            return 0;
        }
        $abs = rtrim(public_path(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, ltrim($webPath, '/'));
        return is_file($abs) ? (int)filesize($abs) : 0;
    }

    /** 租户配额（字节） */
    public static function quota(int $tid): int
    {
        $q = Db::name('tenants')->where('id', $tid)->value('storage_quota');
        $q = (int)$q;
        return $q > 0 ? $q : self::DEFAULT_QUOTA;
    }

    /** 租户已用字节数（全分类合计，含入驻材料） */
    public static function used(int $tid): int
    {
        $total = 0;
        foreach (self::groups() as [$table, $col]) {
            $files = Db::name($table)->where('tenant_id', $tid)
                ->where($col, '<>', '')->column($col);
            foreach ($files as $f) {
                $total += self::fileBytes((string)$f);
            }
        }
        foreach (self::applicationFiles($tid) as [, $f]) {
            $total += self::fileBytes($f);
        }
        return $total;
    }

    /** 上传前校验：剩余空间是否够（租户上下文才限制，平台上传不管） */
    public static function ensureRoom(int $tid, int $bytes): ?string
    {
        if ($tid <= TenantContext::PLATFORM_ID) {
            return null;
        }
        $quota = self::quota($tid);
        if (self::used($tid) + $bytes > $quota) {
            return '存储空间不足（配额 ' . round($quota / 1073741824, 2) . ' GB），请先在管理中心清理空间或联系平台扩容';
        }
        return null;
    }

    /**
     * 管理中心视图：配额 / 已用 / 分类明细（每类最多列 100 条最新）
     */
    public static function overview(int $tid): array
    {
        $protectedPaths = self::protectedPaths($tid);   // v5.2.1：入驻材料文件全分类保护
        $groups = [];
        foreach (self::groups() as $type => [$table, $col, $label]) {
            $rows = Db::name($table)->where('tenant_id', $tid)
                ->where($col, '<>', '')->order('id', 'desc')->limit(100)->select()->toArray();
            $count = Db::name($table)->where('tenant_id', $tid)->where($col, '<>', '')->count();

            $items = [];
            $sum   = 0;
            foreach ($rows as $r) {
                $b    = self::fileBytes((string)$r[$col]);
                $sum += $b;
                switch ($type) {
                    case 'images':
                        $lbl = $r['title'] !== '' ? (string)$r['title'] : ('图片#' . $r['id']);
                        break;
                    case 'profiles':
                        $lbl = '用户 ' . ($r['nickname'] !== '' ? $r['nickname'] : $r['username']) . ' 的注册截图';
                        break;
                    case 'guides':
                        $lbl = '攻略《' . $r['title'] . '》封面';
                        break;
                    default:
                        $lbl = '公告《' . $r['title'] . '》配图';
                }
                $item = [
                    'id'    => (int)$r['id'],
                    'label' => $lbl,
                    'thumb' => (string)$r[$col],
                    'bytes' => $b,
                ];
                // v5.2.1：文件与入驻材料同一物理文件时（如站长的注册截图=队长证明截图），
                // 就地标记受保护，前端显示"平台保管"不给删除按钮
                if (isset($protectedPaths[(string)$r[$col]])) {
                    $item['protected'] = true;
                    if ($type === 'profiles') {
                        $item['label'] .= '（入驻材料）';
                    }
                }
                $items[] = $item;
            }
            // 分类总字节按全量文件算（列表只取最近 100 条）
            $allBytes = 0;
            if ($count > count($rows)) {
                $all = Db::name($table)->where('tenant_id', $tid)
                    ->where($col, '<>', '')->column($col);
                foreach ($all as $f) {
                    $allBytes += self::fileBytes((string)$f);
                }
            } else {
                $allBytes = $sum;
            }
            $groups[] = [
                'type'  => $type,
                'label' => $label,
                'count' => (int)$count,
                'bytes' => $allBytes,
                'items' => $items,
            ];
        }

        // v5.2.0：入驻材料（队长证明截图 + 战队logo）——计入用量但受平台保护，不可删除
        $appItems = [];
        $appBytes = 0;
        foreach (self::applicationFiles($tid) as [$label, $path]) {
            $b         = self::fileBytes($path);
            $appBytes += $b;
            $appItems[] = ['id' => 0, 'label' => $label, 'thumb' => $path, 'bytes' => $b, 'protected' => true];
        }
        if ($appItems !== []) {
            $groups[] = [
                'type'      => 'application',
                'label'     => '入驻材料（平台保护）',
                'count'     => count($appItems),
                'bytes'     => $appBytes,
                'items'     => $appItems,
                'protected' => true,
            ];
        }

        return [
            'quota'  => self::quota($tid),
            'used'   => self::used($tid),
            'groups' => $groups,
        ];
    }

    /**
     * 删除一个登记文件：清记录里的文件列（或删除图片记录）+ 删物理文件
     * @return string|null 错误信息，null 表示成功
     */
    public static function removeFile(int $tid, string $type, int $id): ?string
    {
        // v5.2.0：入驻材料（队长证明截图 / 战队logo）由平台保管，禁止从存储清理删除
        if ($type === 'application') {
            return '入驻材料（队长证明截图、战队logo）由平台保管，不允许删除';
        }
        $groups = self::groups();
        if (!isset($groups[$type])) {
            return '文件类型不合法';
        }
        [$table, $col] = $groups[$type];
        $row = Db::name($table)->where('id', $id)->where('tenant_id', $tid)->find();
        if (!$row) {
            return '文件不存在或不属于本站点';
        }

        $file = (string)$row[$col];
        // v5.2.1：该文件同时是入驻材料（如站长注册截图=队长证明截图）时同样禁删，
        // 防止绕过"入驻材料"分组从注册审核截图分类把入驻材料删掉
        if ($file !== '' && isset(self::protectedPaths($tid)[$file])) {
            return '该文件属于入驻材料（队长证明截图、战队logo），由平台保管，不允许删除';
        }
        if ($type === 'images') {
            Db::name('images')->where('id', $id)->delete();   // 图片记录整体删除
        } elseif ($type === 'showcase') {
            // 展示页条目本身就等于一张图，只清引用会留下一条永远上不了页的空壳
            Db::name('showcase_items')->where('id', $id)->delete();
        } else {
            Db::name($table)->where('id', $id)->update([$col => '']);   // 其余只清文件引用
        }
        if ($file !== '') {
            ImageUpload::delete($file);
        }
        return null;
    }
}
