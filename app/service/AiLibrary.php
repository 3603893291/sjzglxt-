<?php
declare (strict_types = 1);

namespace app\service;

use think\facade\Db;

/**
 * AI 人设库 / 表情包库（v5.36.29）
 *
 * 两份素材都归平台：超管增删改，战队只能选一条人设、调自己发表情包的概率。
 * 表情包只存站内路径，发送时由服务端读文件上传给 QQ，模型永远拿不到 URL。
 */
class AiLibrary
{
    /** 人设最多几条（太多下拉翻不动） */
    public const MAX_PROMPTS = 50;

    /** 表情包最多几张（每张都要塞进系统提示词，太多会挤掉正文） */
    public const MAX_STICKERS = 60;

    /** 表情包单张上限：QQ 富媒体走 base64，太大容易整条发不出去 */
    public const MAX_STICKER_BYTES = 2 * 1024 * 1024;

    public const STICKER_EXT  = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    public const STICKER_MIME = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    // ==================== 人设 ====================

    /** @return array 每条含 id/name/body/note/enabled/sort/updated_at */
    public static function prompts(bool $onlyEnabled = false): array
    {
        try {
            $q = Db::name('ai_prompt_presets');
            if ($onlyEnabled) {
                $q->where('enabled', 1);
            }
            return $q->order('sort', 'asc')->order('id', 'asc')->select()->toArray();
        } catch (\Throwable $e) {
            // 升级 SQL 未跑时当作没有额外人设，回落到平台默认模板
            return [];
        }
    }

    /** 取一条可用人设的正文；不存在或被下架都返回 ''（调用方回落平台默认） */
    public static function promptBody(int $id): string
    {
        if ($id <= 0) {
            return '';
        }
        try {
            $row = Db::name('ai_prompt_presets')->where('id', $id)->where('enabled', 1)->find();
        } catch (\Throwable $e) {
            return '';
        }
        return is_array($row) ? trim((string)$row['body']) : '';
    }

    /** 某条人设是否存在且可选 */
    public static function promptUsable(int $id): bool
    {
        return $id > 0 && self::promptBody($id) !== '';
    }

    /**
     * 新增或修改一条人设（$id=0 为新增）
     * @return array [ok, msg, id]
     */
    public static function savePrompt(int $id, string $name, string $body, string $note, int $enabled, int $sort, int $by): array
    {
        $name = trim($name);
        $body = trim($body);
        $note = trim($note);
        if (mb_strlen($name) < 1 || mb_strlen($name) > 32) {
            return [false, '人设名称要 1-32 个字', 0];
        }
        if ($body === '') {
            return [false, '提示词正文不能为空', 0];
        }
        if (mb_strlen($body) > 4000) {
            return [false, '提示词最长 4000 字', 0];
        }
        if (mb_strlen($note) > 200) {
            return [false, '说明最长 200 字', 0];
        }
        $now = date('Y-m-d H:i:s');
        try {
            if ($id <= 0) {
                $n = (int)Db::name('ai_prompt_presets')->count();
                if ($n >= self::MAX_PROMPTS) {
                    return [false, '人设最多 ' . self::MAX_PROMPTS . ' 条，请先删掉不用的', 0];
                }
                $id = (int)Db::name('ai_prompt_presets')->insertGetId([
                    'name' => $name, 'body' => $body, 'note' => $note,
                    'enabled' => $enabled ? 1 : 0, 'sort' => $sort,
                    'created_by' => $by, 'created_at' => $now, 'updated_at' => $now,
                ]);
                return [true, '人设已添加', $id];
            }
            $exists = Db::name('ai_prompt_presets')->where('id', $id)->find();
            if (!$exists) {
                return [false, '这条人设已经不在了', 0];
            }
            Db::name('ai_prompt_presets')->where('id', $id)->update([
                'name' => $name, 'body' => $body, 'note' => $note,
                'enabled' => $enabled ? 1 : 0, 'sort' => $sort, 'updated_at' => $now,
            ]);
            return [true, '人设已保存', $id];
        } catch (\Throwable $e) {
            if (str_contains($e->getMessage(), 'Duplicate') || str_contains($e->getMessage(), 'UNIQUE')) {
                return [false, '已经有同名的人设了，换一个名字', 0];
            }
            return [false, '保存失败：' . mb_substr($e->getMessage(), 0, 120), 0];
        }
    }

    /** 删掉一条人设；正在用它的战队自动回落到平台默认模板 */
    public static function deletePrompt(int $id): bool
    {
        if ($id <= 0) {
            return false;
        }
        try {
            Db::name('ai_prompt_presets')->where('id', $id)->delete();
            Db::name('tenant_ai_configs')->where('prompt_preset_id', $id)
                ->update(['prompt_preset_id' => 0, 'updated_at' => date('Y-m-d H:i:s')]);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    // ==================== 表情包 ====================

    /** @return array 每条含 id/name/note/path/enabled/sort/sent_count */
    public static function stickers(bool $onlyEnabled = false): array
    {
        try {
            $q = Db::name('ai_stickers');
            if ($onlyEnabled) {
                $q->where('enabled', 1);
            }
            return $q->order('sort', 'asc')->order('id', 'asc')->select()->toArray();
        } catch (\Throwable $e) {
            return [];
        }
    }

    public static function stickerCount(bool $onlyEnabled = true): int
    {
        return count(self::stickers($onlyEnabled));
    }

    /**
     * 上传一张表情包并入库
     * @param mixed $file think\file\UploadedFile
     * @return array [ok, msg, id]
     */
    public static function addSticker($file, string $name, string $note, int $sort, int $by): array
    {
        if (!$file) {
            return [false, '请选择要上传的图片', 0];
        }
        $name = trim($name);
        if (mb_strlen($name) < 1 || mb_strlen($name) > 32) {
            return [false, '表情包名称要 1-32 个字（AI 靠这个名字挑图）', 0];
        }
        if (mb_strlen($note) > 200) {
            return [false, '适用场景说明最长 200 字', 0];
        }
        if ((int)Db::name('ai_stickers')->count() >= self::MAX_STICKERS) {
            return [false, '表情包最多 ' . self::MAX_STICKERS . ' 张，请先删掉不用的', 0];
        }
        [$path, $bytes, $err] = self::saveStickerFile($file);
        if ($err !== null) {
            return [false, $err, 0];
        }
        $now = date('Y-m-d H:i:s');
        try {
            $id = (int)Db::name('ai_stickers')->insertGetId([
                'name' => $name, 'note' => trim($note), 'path' => $path, 'bytes' => $bytes,
                'enabled' => 1, 'sort' => $sort, 'sent_count' => 0,
                'created_by' => $by, 'created_at' => $now, 'updated_at' => $now,
            ]);
            return [true, '表情包已上传', $id];
        } catch (\Throwable $e) {
            ImageUpload::delete($path);
            if (str_contains($e->getMessage(), 'Duplicate') || str_contains($e->getMessage(), 'UNIQUE')) {
                return [false, '已经有同名表情包了，换一个名字', 0];
            }
            return [false, '保存失败：' . mb_substr($e->getMessage(), 0, 120), 0];
        }
    }

    /** 改名 / 改说明 / 启停 / 排序 */
    public static function saveSticker(int $id, string $name, string $note, int $enabled, int $sort): array
    {
        $name = trim($name);
        if ($id <= 0) {
            return [false, '表情包不存在'];
        }
        if (mb_strlen($name) < 1 || mb_strlen($name) > 32) {
            return [false, '表情包名称要 1-32 个字'];
        }
        if (mb_strlen($note) > 200) {
            return [false, '适用场景说明最长 200 字'];
        }
        try {
            if (!Db::name('ai_stickers')->where('id', $id)->find()) {
                return [false, '表情包不存在'];
            }
            Db::name('ai_stickers')->where('id', $id)->update([
                'name' => $name, 'note' => trim($note),
                'enabled' => $enabled ? 1 : 0, 'sort' => $sort,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            return [true, '表情包已保存'];
        } catch (\Throwable $e) {
            if (str_contains($e->getMessage(), 'Duplicate') || str_contains($e->getMessage(), 'UNIQUE')) {
                return [false, '已经有同名表情包了，换一个名字'];
            }
            return [false, '保存失败：' . mb_substr($e->getMessage(), 0, 120)];
        }
    }

    public static function deleteSticker(int $id): bool
    {
        if ($id <= 0) {
            return false;
        }
        try {
            $row = Db::name('ai_stickers')->where('id', $id)->find();
            if (!$row) {
                return false;
            }
            Db::name('ai_stickers')->where('id', $id)->delete();
            ImageUpload::delete((string)$row['path']);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * 校验并落盘（平台素材，不占任何战队的存储配额）
     * @return array [站内路径|null, 字节数, 错误]
     */
    private static function saveStickerFile($file): array
    {
        $ext  = strtolower((string)$file->getOriginalExtension());
        $mime = (string)$file->getOriginalMime();
        if (!in_array($ext, self::STICKER_EXT, true) || !in_array($mime, self::STICKER_MIME, true)) {
            return [null, 0, '仅支持 jpg / png / gif / webp 格式'];
        }
        $size = (int)$file->getSize();
        if ($size <= 0) {
            return [null, 0, '图片是空的'];
        }
        if ($size > self::MAX_STICKER_BYTES) {
            return [null, 0, '表情包不能超过 2MB（大了 QQ 那边容易整条发不出去）'];
        }
        $tmp  = $file->getPathname();
        $info = @getimagesize($tmp);
        if ($info === false || !isset($info[2])) {
            return [null, 0, '文件内容不是有效图片，已拒绝'];
        }
        $sniffed = image_type_to_mime_type((int)$info[2]);
        if (!in_array($sniffed, self::STICKER_MIME, true)) {
            return [null, 0, '仅支持 jpg / png / gif / webp 格式'];
        }
        try {
            if (class_exists('finfo')) {
                $detected = @(new \finfo(FILEINFO_MIME_TYPE))->file($tmp);
                if (is_string($detected) && $detected !== $sniffed && in_array($detected, self::STICKER_MIME, true)) {
                    return [null, 0, '文件内容与声明的图片格式不符，已拒绝'];
                }
            }
        } catch (\Throwable $e) {
            // fileinfo 不可用时退回文件头判定
        }

        $saveName = bin2hex(random_bytes(16)) . '.' . $ext;
        $dir = public_path() . 'storage' . DIRECTORY_SEPARATOR . 'uploads'
            . DIRECTORY_SEPARATOR . 'stickers' . DIRECTORY_SEPARATOR . date('Ymd');
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            return [null, 0, '上传目录创建失败：请检查 public/storage/uploads 的权限与磁盘空间'];
        }
        try {
            $file->move($dir, $saveName);
        } catch (\Throwable $e) {
            return [null, 0, '图片保存失败：上传目录不可写或磁盘已满'];
        }
        $abs = $dir . DIRECTORY_SEPARATOR . $saveName;
        $real = (int)@filesize($abs);
        return ['/storage/uploads/stickers/' . date('Ymd') . '/' . $saveName, $real > 0 ? $real : $size, null];
    }

    /** 表情包绝对路径（发给 QQ 上传用），不存在返回 '' */
    public static function absPath(string $webPath): string
    {
        if ($webPath === '' || strpos($webPath, '/storage/uploads/') !== 0) {
            return '';
        }
        $rel = str_replace('/', DIRECTORY_SEPARATOR, ltrim($webPath, '/'));
        $abs = rtrim(public_path(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $rel;
        return is_file($abs) ? $abs : '';
    }

    public static function touchSent(int $id): void
    {
        try {
            Db::name('ai_stickers')->where('id', $id)->inc('sent_count', 1)->update(['updated_at' => date('Y-m-d H:i:s')]);
        } catch (\Throwable $e) {
            // 计数失败不影响已经发出的图
        }
    }

    // ==================== 给模型用 ====================

    /**
     * 塞进系统提示词的表情包清单。
     * 只说编号和名字，不暴露任何路径；明确要求「不确定就别写」。
     */
    public static function stickerPromptBlock(array $list): string
    {
        if ($list === []) {
            return '';
        }
        $lines = [];
        foreach ($list as $i => $s) {
            $note = trim((string)($s['note'] ?? ''));
            $lines[] = ($i + 1) . '. ' . (string)$s['name'] . ($note !== '' ? '（' . $note . '）' : '');
        }
        return "【表情包】聊天系统有一批表情包，可以跟着你这条回复一起发出去（图由系统发，你别写链接、别描述图片）：\n"
            . implode("\n", $lines) . "\n"
            . '如果你觉得刚才那句话配一张图更合适，就在回复的最后单独另起一行写 [图:编号]（编号就是上面的数字）；'
            . '不确定或没有合适的就别写，不要为了发表情而发表情。';
    }

    /** 从模型输出里抠出 [图:N]；没有返回 0 */
    public static function parseMark(string $text): int
    {
        if (preg_match('/\[图\s*[:：]\s*(\d{1,3})\]/u', $text, $m)) {
            return (int)$m[1];
        }
        return 0;
    }

    /** 把 [图:N] 从正文里去掉（模型可能把标记写进了正文中间） */
    public static function stripMark(string $text): string
    {
        $text = preg_replace('/\[图\s*[:：]\s*\d{1,3}\]/u', '', $text) ?? $text;
        return trim($text);
    }

    /**
     * 决定这一条发哪张：模型点到的编号优先，点不到 / 点错了就随机一张。
     * 列表为空返回 null（调用方就不发）。
     */
    public static function pick(int $mark, array $list): ?array
    {
        $list = array_values($list);
        if ($list === []) {
            return null;
        }
        if ($mark >= 1 && $mark <= count($list)) {
            return $list[$mark - 1];
        }
        return $list[random_int(0, count($list) - 1)];
    }

    // ==================== 发送日志（v5.36.30） ====================
    // 表情包这条链路横跨「模型挑图 → 上传文件 → 发消息」三步，任何一步失败在群里都只是「没有图」。
    // 所以每一步都落一行，战队后台能直接看到原因，也能一键清空。

    /** 日志保留天数 */
    public const LOG_KEEP_DAYS = 7;

    public static function log(array $row): void
    {
        try {
            $now = date('Y-m-d H:i:s');
            $status   = (string)($row['status'] ?? 'ok');
            $pickMode = (string)($row['pick_mode'] ?? 'ai');
            if (!in_array($pickMode, ['ai', 'random'], true)) {
                $pickMode = 'ai';
            }
            Db::name('ai_sticker_logs')->insert([
                'tenant_id'    => (int)($row['tenant_id'] ?? 0),
                'group_openid' => mb_substr((string)($row['group_openid'] ?? ''), 0, 128),
                'sticker_id'   => max(0, (int)($row['sticker_id'] ?? 0)),
                'sticker_name' => mb_substr((string)($row['sticker_name'] ?? ''), 0, 32),
                'pick_mode'    => $pickMode,
                'trigger_kind' => mb_substr((string)($row['trigger_kind'] ?? 'at'), 0, 16),
                'stage'        => mb_substr((string)($row['stage'] ?? 'send'), 0, 16),
                'status'       => in_array($status, ['ok', 'fail', 'skip'], true) ? $status : 'ok',
                'error'        => mb_substr((string)($row['error'] ?? ''), 0, 200),
                'duration_ms'  => max(0, (int)($row['duration_ms'] ?? 0)),
                'created_at'   => $now,
            ]);
            if (random_int(1, 20) === 1) {
                self::pruneLogs();
            }
        } catch (\Throwable $e) {
            // 日志失败不影响发图
        }
    }

    /** 最近 N 条（新的在前），群 openid 打码后返回 */
    public static function logs(int $tenantId, int $limit = 30): array
    {
        try {
            $rows = Db::name('ai_sticker_logs')->where('tenant_id', $tenantId)
                ->order('id', 'desc')->limit(max(1, min(100, $limit)))->select()->toArray();
        } catch (\Throwable $e) {
            return [];
        }
        foreach ($rows as &$r) {
            $r['group_mask'] = self::maskOpenid((string)($r['group_openid'] ?? ''));
            unset($r['group_openid']);
        }
        return $rows;
    }

    /** 清空本队日志，返回删除条数 */
    public static function clearLogs(int $tenantId): int
    {
        try {
            return (int)Db::name('ai_sticker_logs')->where('tenant_id', $tenantId)->delete();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /** 只保留最近 LOG_KEEP_DAYS 天（写日志时 1/20 概率顺路清一次） */
    public static function pruneLogs(): void
    {
        try {
            Db::name('ai_sticker_logs')
                ->where('created_at', '<', date('Y-m-d H:i:s', time() - self::LOG_KEEP_DAYS * 86400))
                ->delete();
        } catch (\Throwable $e) {
        }
    }

    /** openid 打码：只留前 6 位与后 4 位 */
    public static function maskOpenid(string $id): string
    {
        $id = trim($id);
        $len = strlen($id);
        if ($len <= 10) {
            return $len <= 4 ? str_repeat('*', $len) : substr($id, 0, 2) . str_repeat('*', $len - 4) . substr($id, -2);
        }
        return substr($id, 0, 6) . str_repeat('*', 6) . substr($id, -4);
    }
}
