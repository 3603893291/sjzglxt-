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
        // 服务端内容校验：客户端声明的 MIME 可伪造，必须验证文件真实内容
        $tmpPath  = $file->getPathname();
        $realMime = (new \finfo(FILEINFO_MIME_TYPE))->file($tmpPath);
        if (!in_array($realMime, self::ALLOW_MIME, true) || @getimagesize($tmpPath) === false) {
            return [null, '文件内容不是有效图片，已拒绝'];
        }

        // v5.1.0：租户存储配额校验——上传前检查剩余空间是否够
        $err = Storage::ensureRoom(TenantContext::id(), (int)$file->getSize());
        if ($err !== null) {
            return [null, $err];
        }

        $saveName = bin2hex(random_bytes(16)) . '.' . $ext;
        $savePath = public_path() . 'storage' . DIRECTORY_SEPARATOR . 'uploads'
            . DIRECTORY_SEPARATOR . $subDir . DIRECTORY_SEPARATOR . date('Ymd');
        if (!is_dir($savePath)) {
            mkdir($savePath, 0755, true);
        }
        $file->move($savePath, $saveName);

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
