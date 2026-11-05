<?php
declare (strict_types = 1);

namespace app\service;

/**
 * v5.10.0：图片压缩服务（GD 库，无外部依赖）
 *
 * 使用场景：
 *   1. 上传落盘后由 ImageUpload::save() 调用 compressFile() 就地压缩
 *   2. 图片下载接口（Image::raw）可选调用 compressForView() 输出压缩流
 *
 * 配置项（settings 表 tenant_id=0）：
 *   image_compress_enabled    '0'/'1' 默认 '1'
 *   image_compress_quality    1-95    默认 82
 *   image_compress_max_width  像素    默认 1920
 *   image_compress_max_height 像素    默认 1920
 *
 * 策略：
 *   - 只处理 jpg/jpeg/png/webp（gif 保持原样，避免破坏动画帧）
 *   - 保留 PNG/WebP 透明通道
 *   - 小于 SKIP_MIN_SIZE 的文件不压（避免反而变大）
 *   - 任何异常都返回原文件不阻塞上传
 */
class ImageCompressor
{
    /** 小于此字节数直接跳过（默认 30KB） */
    public const SKIP_MIN_SIZE = 30 * 1024;

    /** 支持的输出扩展名 */
    protected const COMPRESSIBLE_EXT = ['jpg', 'jpeg', 'png', 'webp'];

    /**
     * 就地压缩磁盘文件（保留原路径）
     * @param string $absPath 绝对路径
     * @return array{ok:bool,skipped:string,original:int,final:int,ratio:float,error:string}
     */
    public static function compressFile(string $absPath): array
    {
        $result = [
            'ok' => false, 'skipped' => '', 'original' => 0, 'final' => 0,
            'ratio' => 0.0, 'error' => '',
        ];

        if (!is_file($absPath) || !is_readable($absPath) || !is_writable($absPath)) {
            $result['skipped'] = 'unreadable';
            return $result;
        }
        if (!self::enabled()) {
            $result['skipped'] = 'disabled';
            return $result;
        }
        if (!extension_loaded('gd')) {
            $result['skipped'] = 'no_gd';
            return $result;
        }

        $original = (int)filesize($absPath);
        $result['original'] = $original;
        if ($original < self::SKIP_MIN_SIZE) {
            $result['skipped'] = 'too_small';
            $result['final']   = $original;
            $result['ratio']   = 1.0;
            return $result;
        }

        $ext = strtolower(pathinfo($absPath, PATHINFO_EXTENSION));
        if (!in_array($ext, self::COMPRESSIBLE_EXT, true)) {
            $result['skipped'] = 'unsupported_ext';
            $result['final']   = $original;
            $result['ratio']   = 1.0;
            return $result;
        }

        try {
            $info = @getimagesize($absPath);
            if ($info === false) {
                $result['skipped'] = 'invalid_image';
                $result['final']   = $original;
                $result['ratio']   = 1.0;
                return $result;
            }
            [$srcW, $srcH, $type] = $info;

            $src = self::createFromFile($absPath, $type);
            if ($src === null) {
                $result['skipped'] = 'gd_load_failed';
                $result['final']   = $original;
                $result['ratio']   = 1.0;
                return $result;
            }

            [$dstW, $dstH] = self::fitSize($srcW, $srcH);
            $needResize    = ($dstW !== $srcW || $dstH !== $srcH);
            $dst = $needResize
                ? self::resize($src, $srcW, $srcH, $dstW, $dstH)
                : $src;

            // 写临时文件，成功后再替换（避免半途失败破坏原文件）
            $tmp = $absPath . '.tmp_' . bin2hex(random_bytes(4));
            $ok  = self::writeImage($dst, $tmp, $type, $ext);
            imagedestroy($dst);
            if ($dst !== $src) imagedestroy($src);

            if (!$ok || !is_file($tmp)) {
                @unlink($tmp);
                $result['skipped'] = 'write_failed';
                $result['final']   = $original;
                $result['ratio']   = 1.0;
                return $result;
            }

            $newSize = (int)filesize($tmp);
            // 压得更大就丢弃临时文件（罕见，但保护原质量）
            if ($newSize >= $original) {
                @unlink($tmp);
                $result['skipped'] = 'no_gain';
                $result['final']   = $original;
                $result['ratio']   = 1.0;
                return $result;
            }

            if (!@rename($tmp, $absPath)) {
                // Windows 上目标已存在时 rename 可能失败，退化为 unlink+rename
                @unlink($absPath);
                if (!@rename($tmp, $absPath)) {
                    @unlink($tmp);
                    $result['skipped'] = 'rename_failed';
                    $result['final']   = $original;
                    $result['ratio']   = 1.0;
                    return $result;
                }
            }

            $result['ok']    = true;
            $result['final'] = $newSize;
            $result['ratio'] = $original > 0 ? round($newSize / $original, 4) : 1.0;
            return $result;
        } catch (\Throwable $e) {
            $result['skipped'] = 'exception';
            $result['error']   = mb_substr($e->getMessage(), 0, 200);
            $result['final']   = (int)@filesize($absPath) ?: $result['original'];
            $result['ratio']   = 1.0;
            return $result;
        }
    }

    /**
     * 视图压缩：按 max_width 缩放并输出 JPEG/WebP 二进制
     * 用于 Image::raw 之类下载接口的 ?thumb=1 场景，不修改磁盘文件
     * @return array{body:string,mime:string}|null  null 表示不压缩（回退原文件）
     */
    public static function compressForView(string $absPath, int $maxWidth = 1280, int $quality = 78): ?array
    {
        if (!self::enabled() || !extension_loaded('gd') || !is_file($absPath)) return null;
        $ext = strtolower(pathinfo($absPath, PATHINFO_EXTENSION));
        if (!in_array($ext, self::COMPRESSIBLE_EXT, true)) return null;

        $info = @getimagesize($absPath);
        if ($info === false) return null;
        [$srcW, $srcH, $type] = $info;

        // 输出统一走 webp（浏览器兼容性好、体积小）；不支持则回落 jpeg
        $outWebp = function_exists('imagewebp');
        $outMime = $outWebp ? 'image/webp' : 'image/jpeg';

        // 已经比目标窄且原文件小，无需处理
        $origSize = (int)filesize($absPath);
        if ($srcW <= $maxWidth && $origSize < 200 * 1024) return null;

        $src = self::createFromFile($absPath, $type);
        if ($src === null) return null;

        $ratio = min(1.0, $maxWidth / max(1, $srcW));
        $dstW  = (int)round($srcW * $ratio);
        $dstH  = (int)round($srcH * $ratio);
        $dst   = self::resize($src, $srcW, $srcH, $dstW, $dstH);
        imagedestroy($src);

        ob_start();
        if ($outWebp) {
            imagewebp($dst, null, $quality);
        } else {
            imagejpeg($dst, null, $quality);
        }
        $body = (string)ob_get_clean();
        imagedestroy($dst);

        if ($body === '' || strlen($body) >= $origSize) return null;
        return ['body' => $body, 'mime' => $outMime];
    }

    // ---------- 内部工具 ----------

    protected static function enabled(): bool
    {
        return Setting::getPlatform('image_compress_enabled', '1') === '1';
    }

    protected static function quality(): int
    {
        $q = (int)Setting::getPlatform('image_compress_quality', '82');
        return max(30, min(95, $q));
    }

    /** @return array{0:int,1:int} 按配置的最大宽高缩放，等比 */
    protected static function fitSize(int $srcW, int $srcH): array
    {
        $maxW = max(320, (int)Setting::getPlatform('image_compress_max_width',  '1920'));
        $maxH = max(320, (int)Setting::getPlatform('image_compress_max_height', '1920'));
        $ratio = min(1.0, $maxW / max(1, $srcW), $maxH / max(1, $srcH));
        return [(int)round($srcW * $ratio), (int)round($srcH * $ratio)];
    }

    /** @return \GdImage|null */
    protected static function createFromFile(string $path, int $type)
    {
        switch ($type) {
            case IMAGETYPE_JPEG: return @imagecreatefromjpeg($path) ?: null;
            case IMAGETYPE_PNG:  return @imagecreatefrompng($path)  ?: null;
            case IMAGETYPE_WEBP: return function_exists('imagecreatefromwebp') ? (@imagecreatefromwebp($path) ?: null) : null;
            case IMAGETYPE_GIF:  return @imagecreatefromgif($path)  ?: null;
            default:             return null;
        }
    }

    /** @return \GdImage */
    protected static function resize($src, int $srcW, int $srcH, int $dstW, int $dstH)
    {
        $dst = imagecreatetruecolor($dstW, $dstH);
        // 保留透明：PNG/WebP/GIF
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        $transparent = imagecolorallocatealpha($dst, 255, 255, 255, 127);
        imagefilledrectangle($dst, 0, 0, $dstW, $dstH, $transparent);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $dstW, $dstH, $srcW, $srcH);
        return $dst;
    }

    /** 按目标扩展名写盘 */
    protected static function writeImage($img, string $tmpPath, int $srcType, string $ext): bool
    {
        $q = self::quality();
        switch ($ext) {
            case 'jpg':
            case 'jpeg':
                // JPEG 不支持透明，先铺白底避免透明区变黑
                if (in_array($srcType, [IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP], true)) {
                    $w = imagesx($img); $h = imagesy($img);
                    $flat = imagecreatetruecolor($w, $h);
                    $white = imagecolorallocate($flat, 255, 255, 255);
                    imagefilledrectangle($flat, 0, 0, $w, $h, $white);
                    imagecopy($flat, $img, 0, 0, 0, 0, $w, $h);
                    $ok = imagejpeg($flat, $tmpPath, $q);
                    imagedestroy($flat);
                    return $ok;
                }
                return imagejpeg($img, $tmpPath, $q);
            case 'png':
                // PNG 的 compression level 0-9（越高越慢，取中值 6）
                return imagepng($img, $tmpPath, 6);
            case 'webp':
                return function_exists('imagewebp') ? imagewebp($img, $tmpPath, $q) : false;
            default:
                return false;
        }
    }
}
