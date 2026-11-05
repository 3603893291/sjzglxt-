<?php
declare (strict_types = 1);

namespace app\service;

use think\file\UploadedFile;

/**
 * 图片上传通用校验与保存（游戏图片 / 注册截图 / 公告配图共用）
 */
class ImageUpload
{
    public const ALLOW_EXT  = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    public const ALLOW_MIME = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    public const MAX_SIZE   = 5 * 1024 * 1024; // 5MB

    /**
     * 校验并保存上传图片
     * @param UploadedFile|null $file 上传文件
     * @param string $subDir 子目录名（如 images / profiles / announcements）
     * @return array [相对路径, 错误信息] —— 成功时错误信息为 null
     */
    public static function save($file, string $subDir): array
    {
        if (!$file) {
            return [null, '请选择要上传的图片'];
        }

        $ext  = strtolower($file->getOriginalExtension());
        $mime = $file->getOriginalMime();
        if (!in_array($ext, self::ALLOW_EXT, true) || !in_array($mime, self::ALLOW_MIME, true)) {
            return [null, '仅支持 jpg / png / gif / webp 格式图片'];
        }
        if ($file->getSize() > self::MAX_SIZE) {
            return [null, '图片大小不能超过 5MB'];
        }
        // 服务端内容校验：客户端声明的 MIME 可伪造，必须验证文件真实内容。
        // v5.15.1：判定以「文件头」为准——getimagesize() 只认签名，把 txt 改名成 .png 照样过不去；
        // fileinfo 降级为「它自己认得出时」的交叉核对。此前它是唯一凭据，于是三种常见环境
        // 会把用户的真图片一起拒掉（返回「文件内容不是有效图片，已拒绝」甚至直接 500）：
        //   ① 没装 fileinfo 扩展 → Class "finfo" not found；
        //   ② magic.mgc 找不到 / 站点开了 open_basedir 挡读 → 一律 application/octet-stream；
        //   ③ libmagic 版本偏旧认不出 webp → 同样 application/octet-stream。
        $tmpPath = $file->getPathname();
        $info   = @getimagesize($tmpPath);
        if ($info === false || !isset($info[2])) {
            return [null, '文件内容不是有效图片，已拒绝'];
        }
        $sniffed = image_type_to_mime_type((int)$info[2]);   // 由文件头得出的真实类型
        if (!in_array($sniffed, self::ALLOW_MIME, true)) {
            return [null, '仅支持 jpg / png / gif / webp 格式图片'];
        }
        try {
            if (class_exists('finfo')) {
                $detected = @(new \finfo(FILEINFO_MIME_TYPE))->file($tmpPath);
                // fileinfo 认得出、却与文件头给的类型不一致（嵌套内容/伪装）时才拒；
                // 返回 false 或 application/octet-stream 属于环境识别力问题，不作为拒绝依据
                if (is_string($detected) && $detected !== $sniffed
                    && in_array($detected, self::ALLOW_MIME, true)) {
                    return [null, '文件内容与声明的图片格式不符，已拒绝'];
                }
            }
        } catch (\Throwable $e) {
            // fileinfo 不可用时退回纯文件头判定，不阻断上传
        }

        // v5.1.0：租户存储配额校验——上传前检查剩余空间是否够
        $err = Storage::ensureRoom(TenantContext::id(), (int)$file->getSize());
        if ($err !== null) {
            return [null, $err];
        }

        $saveName = bin2hex(random_bytes(16)) . '.' . $ext;
        $savePath = public_path() . 'storage' . DIRECTORY_SEPARATOR . 'uploads'
            . DIRECTORY_SEPARATOR . $subDir . DIRECTORY_SEPARATOR . date('Ymd');
        // 目录不可写此前会让运维类故障直接显示成 500，这里换成能看懂的提示
        if (!is_dir($savePath) && !@mkdir($savePath, 0755, true)) {
            return [null, '上传目录创建失败：请检查 public/storage/uploads 的权限与磁盘空间'];
        }
        try {
            $file->move($savePath, $saveName);
        } catch (\Throwable $e) {
            return [null, '图片保存失败：上传目录不可写或磁盘已满'];
        }

        // v5.10.0：落盘后自动压缩（GD）。失败不阻断上传，仅记录日志
        // 存储配额无需手动 release：Storage::used() 每次按磁盘真实 filesize 重新汇总，
        // 压缩后下次查询自然得到新值
        $absPath = $savePath . DIRECTORY_SEPARATOR . $saveName;
        try {
            ImageCompressor::compressFile($absPath);
        } catch (\Throwable $e) {
            \think\facade\Log::warning('[ImageCompressor] ' . $e->getMessage());
        }

        return ['/storage/uploads/' . $subDir . '/' . date('Ymd') . '/' . $saveName, null];
    }

    /**
     * 删除已保存图片的物理文件（按数据库存的 web 相对路径）
     * 只允许删 uploads 目录内的文件，防路径穿越；文件不存在则静默跳过
     * @param string $webPath 如 /storage/uploads/images/20260802/img_xxx.jpg
     */
    public static function delete(string $webPath): void
    {
        if ($webPath === '' || strpos($webPath, '/storage/uploads/') !== 0) {
            return;
        }
        $rel = str_replace('/', DIRECTORY_SEPARATOR, ltrim($webPath, '/')); // storage\uploads\...
        $abs = rtrim(public_path(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $rel;
        if (is_file($abs)) {
            @unlink($abs);
        }
    }
}
