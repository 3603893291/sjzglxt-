<?php
declare (strict_types = 1);

namespace app\service;

use think\facade\Cache;
use think\facade\Db;

/**
 * 群聊 AI 调度（v5.36.14）
 *
 * 两条硬约束：
 * 1) 纯聊天：模型输出只走 sendGroupTextWithBot，绝不回到 BotCommand::dispatch；
 *    不传 tools / functions，提示词里再追加不可越权的硬规则。
 * 2) 先回 QQ 再干活：回调里只入队并 ACK，真正的模型调用放到响应结束后的
 *    shutdown（FPM 用 fastcgi_finish_request 提前断开），避免 QQ 回调超时。
 *
 * 队列 bot_ai_jobs 用 (tenant_id, message_id, kind) 唯一键去重，平台重推不重复扣 Token。
 */
class AiChat
{
    /** 上下文只带最近 4 条、每条最多 300 字，够接话又不烧 Token */
    private const CONTEXT_LIMIT = 4;
    private const CONTEXT_CHARS = 300;
    private const CONTEXT_TTL    = 21600;

    /** QQ 被动回复窗口 5 分钟，留 20 秒余量 */
    private const REPLY_WINDOW = 280;

    /** 处理中的任务超过这个时间视为卡死 */
    private const JOB_STALE = 300;

    private const TRIGGER_WORDS = '/(?:[?？]|吗|呢|怎么|怎样|为什么|为啥|如何|什么|啥|谁|哪里|哪个|多少|'
        . '能不能|可不可以|可以吗|帮我|求推荐|有没有|是不是|好不好|行不行)/u';

    /** 入队一条 @ 消息；返回 [queued, reply]（reply 非空时由调用方直接回） */
    public static function offerAt(int $tenantId, string $group, string $member, string $messageId, string $content): array
    {
        $cfg = AiClient::tenantConfig($tenantId);
        // v5.36.25：这里不能先拿 aiOn() 一把梭——平台关掉总开关 / 停用本队时 aiOn 也是 false，
        // 那样只会回「去管理中心开 AI」，把真实原因（平台侧没开）藏起来。
        // 先只判断「本队自己选没选模型」，其余交给 resolveProvider 说清楚。
        if ((string)($cfg['mode'] ?? 'off') === 'off') {
            return ['queued' => false, 'reply' => '', 'off' => true];
        }
        $provider = AiClient::resolveProvider($tenantId);
        if (!$provider['ok']) {
            if ($provider['error_code'] === 'quota') {
                return ['queued' => false, 'reply' => 'AI 聊天额度已经用完啦，让队长改用自有模型，或联系平台管理员充值后再聊。'];
            }
            return ['queued' => false, 'reply' => 'AI 聊天没接通：' . (string)$provider['error'] . '。指令照常可用，发「帮助」看菜单。'];
        }
        if (!AiQuota::dailyOk($cfg)) {
            return ['queued' => false, 'reply' => '今天的 AI 聊天额度用完啦，明天再来聊。'];
        }
        if (!self::hourlyOk($tenantId, $group, $cfg)) {
            return ['queued' => false, 'reply' => '我先歇一会儿，稍后再喊我。'];
        }
        $queued = self::queue($tenantId, $group, $member, $messageId, $content, 'at');
        if (!$queued) {
            // v5.36.26：入队失败以前是「静默回一句没法识别」，看起来像 AI 根本没配；
            // 现在把真实原因带出去（积压 / 表写不进去），自检里也有对应一行。
            $why = self::lastQueueError();
            return ['queued' => false, 'reply' => 'AI 这条没排上队：' . ($why !== '' ? $why : '未知原因') . '。稍后再 @ 我一次。'];
        }
        return ['queued' => true, 'reply' => ''];
    }

    /** 非 @ 群消息的全自动接话；只返回是否入队，失败保持安静 */
    public static function offerAuto(int $tenantId, string $group, string $member, string $messageId, string $content): bool
    {
        $cfg = AiClient::tenantConfig($tenantId);
        if (!self::aiOn($cfg) || (int)($cfg['auto_reply'] ?? 0) !== 1) {
            return false;
        }
        $text = BotCommand::clean($content);
        if (!self::shouldAuto($cfg, $text)) {
            return false;
        }
        $provider = AiClient::resolveProvider($tenantId);
        if (!$provider['ok'] || !AiQuota::dailyOk($cfg)) {
            return false;
        }
        if (!self::hourlyOk($tenantId, $group, $cfg)) {
            return false;
        }
        $cool = (int)($cfg['cooldown_seconds'] ?? 60);
        if ($cool > 0 && Cache::get(self::coolKey($tenantId, $group))) {
            return false;
        }
        $queued = self::queue($tenantId, $group, $member, $messageId, $text, 'auto');
        if ($queued) {
            // 入队即压冷却：模型还没回的时候来的一串消息不能全排进队列烧 Token
            self::setCooldown($tenantId, $group);
        }
        return $queued;
    }

    /** AI 总开关 + 队内模式 */
    public static function aiOn(array $cfg): bool
    {
        return (int)($cfg['platform_disabled'] ?? 0) === 0
            && in_array((string)($cfg['mode'] ?? 'off'), ['platform', 'own'], true);
    }

    /**
     * 触发判断（冷却之外的第二道闸）：@ / 称呼 / 问句 / 全量概率。
     * 指令、纯链接、图片占位、空消息一律不接。
     */
    public static function shouldAuto(array $cfg, string $text): bool
    {
        $text = trim($text);
        $len = mb_strlen($text);
        if ($len < 2 || $len > 200) {
            return false;
        }
        if (preg_match('/^\[(图片|表情|语音|视频|文件|红包|动画表情)\]/u', $text)) {
            return false;
        }
        if (preg_match('#^https?://\S+$#iu', $text)) {
            return false;
        }
        if (BotCommand::looksLikeCommand($text)) {
            return false;
        }
        $alias = trim((string)($cfg['bot_alias'] ?? ''));
        if ($alias !== '' && mb_stripos($text, $alias) !== false) {
            return true;
        }
        if (preg_match(self::TRIGGER_WORDS, $text)) {
            return true;
        }
        if ((string)($cfg['reply_mode'] ?? 'at') !== 'all') {
            return false;
        }
        $p = max(0, min(100, (int)($cfg['chat_probability'] ?? 20)));
        return $p > 0 && random_int(1, 100) <= $p;
    }

    /** 最近一次入队失败的原因（offerAt 会把它放进群里那句提示，自检也能看到） */
    private static string $lastQueueError = '';

    public static function lastQueueError(): string
    {
        return self::$lastQueueError;
    }

    private static function queue(int $tenantId, string $group, string $member, string $messageId, string $content, string $kind): bool
    {
        self::$lastQueueError = '';
        $content = trim(mb_substr($content, 0, 500));
        if ($group === '' || $content === '') {
            self::$lastQueueError = '群或正文为空';
            return false;
        }
        try {
            // 积压保护之前先把「排队超时」的清掉：后台没在跑时不能让旧的把新的堵死
            self::expirePending();
            $busy = (int)Db::name('bot_ai_jobs')->where('tenant_id', $tenantId)
                ->whereIn('status', ['pending', 'processing'])->count();
            if ($busy >= 20) {
                self::$lastQueueError = '队列积压（还有 ' . $busy . ' 条没处理）';
                QqBot::debugLog(['type' => 'ai_queue_fail', 'why' => 'backlog', 'busy' => $busy]);
                return false;
            }
            $now = date('Y-m-d H:i:s');
            $deadline = $kind === 'proactive' ? null : date('Y-m-d H:i:s', time() + self::REPLY_WINDOW);
            Db::name('bot_ai_jobs')->insert([
                'tenant_id'      => $tenantId,
                'group_openid'   => $group,
                'member_openid'  => $member,
                'message_id'     => $messageId !== '' ? self::midStore($messageId) : null,
                'content'        => $content,
                'kind'           => $kind,
                'status'         => 'pending',
                'attempts'       => 0,
                'error'          => '',
                'reply_deadline' => $deadline,
                'created_at'     => $now,
            ]);
            return true;
        } catch (\Throwable $e) {
            // 唯一键冲突 = 平台重推同一条消息，视为已入队
            if (str_contains($e->getMessage(), 'UNIQUE') || str_contains($e->getMessage(), 'Duplicate')) {
                return true;
            }
            self::$lastQueueError = mb_substr($e->getMessage(), 0, 160);
            QqBot::debugLog(['type' => 'ai_queue_fail', 'why' => 'insert', 'error' => self::$lastQueueError]);
            return false;
        }
    }

    /**
     * QQ 的事件 id 比 bot_ai_jobs.message_id 的 VARCHAR(64) 长，直接写会报
     * 「1406 Data too long for column 'message_id'」，任务永远排不进队列。
     * 所以表里只存 40 位摘要（去重仍按摘要，稳定），真实 id 放缓存 1 小时，
     * 处理时取出来做被动回复；缓存没了就退回主动发送，绝不会发错 msg_id。
     */
    private static function midStore(string $messageId): string
    {
        $mid = substr(sha1($messageId), 0, 40);
        Cache::set('ai_mid_' . $mid, $messageId, 3600);
        return $mid;
    }

    private static function midReal(string $stored): string
    {
        if ($stored === '') {
            return '';
        }
        $hit = (string)Cache::get('ai_mid_' . $stored, '');
        if ($hit !== '') {
            return $hit;
        }
        // 40 位 = 摘要但缓存过期了 → 不能再拿它当 msg_id；更长的 = 老记录里的真实 id
        return strlen($stored) === 40 ? '' : $stored;
    }

    /** 排队超过 15 分钟还没人处理的，判失败（后台进程没跑时队列不会无限膨胀） */
    private static function expirePending(): void
    {
        try {
            Db::name('bot_ai_jobs')
                ->where('status', 'pending')
                ->where('created_at', '<', date('Y-m-d H:i:s', time() - 900))
                ->update(['status' => 'failed', 'error' => '排队超时：后台没有在处理队列']);
        } catch (\Throwable $e) {
        }
    }

    /** 处理队列（回调响应结束后调用；也可由开放 tick 接口触发） */
    public static function drain(int $limit = 2): int
    {
        self::releaseStale();
        self::expirePending();
        $jobs = Db::name('bot_ai_jobs')
            ->where('status', 'pending')
            ->order('id', 'asc')
            ->limit($limit)
            ->select()->toArray();
        $done = 0;
        foreach ($jobs as $job) {
            $aff = Db::name('bot_ai_jobs')
                ->where('id', (int)$job['id'])
                ->where('status', 'pending')
                ->update([
                    'status'     => 'processing',
                    'attempts'   => (int)$job['attempts'] + 1,
                    'started_at' => date('Y-m-d H:i:s'),
                ]);
            if (!$aff) {
                continue;
            }
            $job['attempts'] = (int)$job['attempts'] + 1;
            try {
                self::process($job);
            } catch (\Throwable $e) {
                self::fail($job, '处理异常：' . mb_substr($e->getMessage(), 0, 160));
            }
            $done++;
        }
        return $done;
    }

    private static function process(array $job): void
    {
        $tenantId = (int)$job['tenant_id'];
        $group    = (string)$job['group_openid'];
        $member   = (string)$job['member_openid'];
        $kind     = (string)$job['kind'];

        $tenant = Db::name('tenants')->where('id', $tenantId)->find();
        if (!$tenant || (string)$tenant['status'] !== 'approved') {
            self::fail($job, '站点不可用');
            return;
        }
        $cfg = AiClient::tenantConfig($tenantId);
        if (!self::aiOn($cfg)) {
            self::fail($job, 'AI 已关闭');
            return;
        }
        // 入队后机器人可能被停用或群被解绑；这里再确认一次，别白花模型钱
        if (QqBot::ownBot($tenantId) === null) {
            self::fail($job, '机器人未启用');
            return;
        }
        $bound = Db::name('bot_group_bindings')->where('tenant_id', $tenantId)
            ->where('group_openid', $group)->find();
        if (!$bound) {
            self::fail($job, '群已解绑');
            return;
        }
        $provider = AiClient::resolveProvider($tenantId);
        if (!$provider['ok']) {
            self::fail($job, $provider['error']);
            if ($kind === 'at' && $provider['error_code'] === 'quota') {
                self::send($tenantId, $group, 'AI 聊天额度已经用完啦，让队长改用自有模型，或联系平台管理员充值后再聊。', self::midReal((string)($job['message_id'] ?? '')));
            }
            return;
        }
        if (!AiQuota::dailyOk($cfg)) {
            self::fail($job, '今日 Token 上限已用完');
            return;
        }
        if (!self::hourlyOk($tenantId, $group, $cfg)) {
            self::fail($job, '本群每小时回复上限已用完');
            return;
        }

        $alias = trim((string)($cfg['bot_alias'] ?? ''));
        // v5.36.29：概率命中才把表情包清单塞进提示词，没命中就不提（省 Token，也不会误发）
        $stickerDec  = self::stickerDecide($cfg, $kind);
        $stickerList = [];
        if ($stickerDec['hit']) {
            $stickerList = AiLibrary::stickers(true);
            if ($stickerList === []) {
                self::logStickerSkip($tenantId, $group, '平台表情包库是空的', $kind);
            }
        } elseif ($stickerDec['skip'] !== '') {
            self::logStickerSkip($tenantId, $group, $stickerDec['skip'], $kind);
        }
        $messages = [[
            'role'    => 'system',
            'content' => AiClient::buildSystemPrompt($provider, (string)$tenant['name'], $alias, $kind === 'proactive', $stickerList),
        ]];
        if ($kind !== 'proactive') {
            foreach (self::context($tenantId, $group) as $row) {
                $messages[] = ['role' => $row['role'], 'content' => $row['content']];
            }
            $messages[] = ['role' => 'user', 'content' => (string)$job['content']];
        } else {
            $messages[] = ['role' => 'user', 'content' => '（群里现在比较安静，请主动说一句轻松的话，不要提问太多。）'];
        }

        $r = AiClient::chat($provider, $messages);
        if (!$r['ok']) {
            AiQuota::error($tenantId, $r['error']);
            AiQuota::usage([
                'tenant_id' => $tenantId, 'group_openid' => $group, 'member_openid' => $member,
                'kind' => $kind, 'source' => $provider['source'], 'model' => $provider['model'],
                'total_tokens' => 0, 'duration_ms' => $r['duration_ms'], 'status' => 'fail',
                'error' => $r['error'],
            ]);
            self::fail($job, $r['error']);
            if ($kind === 'at') {
                self::send($tenantId, $group, '我这边暂时没连上模型，稍后再试。', self::midReal((string)($job['message_id'] ?? '')));
            }
            return;
        }

        // 标记要在清洗前抠出来：sanitize 会把 [图:1] 当正文一起压掉
        $mark = AiLibrary::parseMark((string)$r['text']);
        $text = AiClient::sanitize($r['text'], (bool)$provider['allow_links']);
        if ($text === '') {
            self::fail($job, '模型输出为空');
            return;
        }

        AiQuota::charge($tenantId, (int)$r['total_tokens'], $provider['source'] === 'platform');
        AiQuota::usage([
            'tenant_id' => $tenantId, 'group_openid' => $group, 'member_openid' => $member,
            'kind' => $kind, 'source' => $provider['source'], 'model' => $provider['model'],
            'prompt_tokens' => $r['prompt_tokens'], 'completion_tokens' => $r['completion_tokens'],
            'total_tokens' => $r['total_tokens'], 'duration_ms' => $r['duration_ms'], 'status' => 'ok',
        ]);
        self::remember($tenantId, $group, $member, $kind === 'proactive' ? '' : (string)$job['content'], $text, self::midReal((string)($job['message_id'] ?? '')));

        $msgId = $kind === 'proactive' ? '' : self::midReal((string)($job['message_id'] ?? ''));
        // 被动回复窗口已过就不要再引用旧 msg_id；QqBot 会回退成主动发送
        $deadline = (string)($job['reply_deadline'] ?? '');
        if ($msgId !== '' && $deadline !== '' && strtotime($deadline) < time()) {
            $msgId = '';
        }
        $sent = self::send($tenantId, $group, $text, $msgId);
        self::markCooldown($tenantId, $group);
        if (!$sent) {
            AiQuota::usage([
                'tenant_id' => $tenantId, 'group_openid' => $group, 'member_openid' => $member,
                'kind' => $kind, 'source' => $provider['source'], 'model' => $provider['model'],
                'total_tokens' => 0, 'status' => 'send_fail', 'error' => 'QQ 消息发送失败',
            ]);
            AiQuota::error($tenantId, 'QQ 消息发送失败（可能没有主动消息额度或群权限不足）');
            self::fail($job, 'QQ 消息发送失败');
            return;
        }
        Db::name('bot_ai_jobs')->where('id', (int)$job['id'])->update([
            'status'      => 'done',
            'finished_at' => date('Y-m-d H:i:s'),
        ]);
        AiQuota::error($tenantId, '');

        // 文字已经发出去了，表情包是第二条；它失败不能把这个任务判失败
        if ($stickerList !== []) {
            self::sendSticker($tenantId, $group, $mark, $stickerList, $msgId);
        }
    }

    /** 发一张表情包，结果写进日志表 + 缓存给「运行自检」看 */
    private static function sendSticker(int $tenantId, string $group, int $mark, array $list, string $msgId): void
    {
        $pick = AiLibrary::pick($mark, $list);
        if ($pick === null) {
            return;
        }
        $mode = ($mark >= 1 && $mark <= count($list)) ? 'ai' : 'random';
        $ck = 'ai_sticker_last_' . $tenantId;
        $name = (string)($pick['name'] ?? '');
        $abs = AiLibrary::absPath((string)($pick['path'] ?? ''));
        if ($abs === '') {
            QqBot::debugLog(['type' => 'ai_sticker_missing_file', 'tenant' => $tenantId,
                'id' => (int)$pick['id'], 'path' => (string)($pick['path'] ?? '')]);
            Cache::set($ck, '图片文件不在（' . $name . '）', 3600);
            AiLibrary::log([
                'tenant_id' => $tenantId, 'group_openid' => $group,
                'sticker_id' => (int)$pick['id'], 'sticker_name' => $name, 'pick_mode' => $mode,
                'trigger_kind' => 'at', 'stage' => 'pick', 'status' => 'skip',
                'error' => '服务端找不到这张图片文件（可能被手动删掉了）',
            ]);
            return;
        }
        $key = self::botKey($tenantId);
        if ($key === '') {
            Cache::set($ck, '找不到机器人凭据', 3600);
            AiLibrary::log([
                'tenant_id' => $tenantId, 'group_openid' => $group,
                'sticker_id' => (int)$pick['id'], 'sticker_name' => $name, 'pick_mode' => $mode,
                'trigger_kind' => 'at', 'stage' => 'pick', 'status' => 'fail',
                'error' => '找不到本队机器人的回调密钥',
            ]);
            return;
        }
        $res = QqBot::sendGroupImageWithBot($group, $abs, $key, $msgId);
        if ($res['ok']) {
            AiLibrary::touchSent((int)$pick['id']);
            Cache::set($ck, '已发「' . $name . '」' . ($mode === 'ai' ? '（AI 选的）' : '（随机）'), 3600);
        } else {
            QqBot::debugLog(['type' => 'ai_sticker_fail', 'tenant' => $tenantId, 'err' => $res['error']]);
            Cache::set($ck, '发送失败：' . $res['error'], 3600);
        }
        AiLibrary::log([
            'tenant_id' => $tenantId, 'group_openid' => $group,
            'sticker_id' => (int)$pick['id'], 'sticker_name' => $name, 'pick_mode' => $mode,
            'trigger_kind' => 'at', 'stage' => $res['stage'],
            'status' => $res['ok'] ? 'ok' : 'fail', 'error' => $res['error'],
            'duration_ms' => (int)$res['ms'],
        ]);
    }

    /** 概率命中但发不出去（库空 / 总闸关）时记一行；同因同队 10 分钟只记一次，别刷屏 */
    private static function logStickerSkip(int $tenantId, string $group, string $why, string $kind): void
    {
        $ck = 'ai_sticker_skip_' . $tenantId . '_' . md5($why);
        if (Cache::get($ck)) {
            return;
        }
        Cache::set($ck, 1, 600);
        AiLibrary::log([
            'tenant_id' => $tenantId, 'group_openid' => $group,
            'trigger_kind' => $kind, 'stage' => 'pick', 'status' => 'skip', 'error' => $why,
        ]);
    }

    /**
     * 立刻发一张（不经过模型），给后台「立刻发一张」按钮排障用。
     * 主动发送，不占被动回复窗口；失败原因原样返回，方便直接看到 QQ 的报错。
     * @return array{ok:bool,msg:string,sticker:string}
     */
    public static function sendStickerNow(int $tenantId, int $stickerId = 0): array
    {
        $group = (string)Db::name('bot_group_bindings')->where('tenant_id', $tenantId)->value('group_openid');
        if ($group === '') {
            return ['ok' => false, 'msg' => '本队还没有绑定 QQ 群，先到「QQ机器人」页绑定', 'sticker' => ''];
        }
        $list = AiLibrary::stickers(true);
        if ($list === []) {
            return ['ok' => false, 'msg' => '平台表情包库是空的，先让平台管理员上传几张', 'sticker' => ''];
        }
        $pick = null;
        if ($stickerId > 0) {
            foreach ($list as $s) {
                if ((int)$s['id'] === $stickerId) { $pick = $s; break; }
            }
        }
        if ($pick === null) {
            $pick = $list[random_int(0, count($list) - 1)];
        }
        $name = (string)($pick['name'] ?? '');
        $abs  = AiLibrary::absPath((string)($pick['path'] ?? ''));
        if ($abs === '') {
            AiLibrary::log([
                'tenant_id' => $tenantId, 'group_openid' => $group,
                'sticker_id' => (int)$pick['id'], 'sticker_name' => $name, 'pick_mode' => 'random',
                'trigger_kind' => 'test', 'stage' => 'pick', 'status' => 'skip',
                'error' => '服务端找不到这张图片文件（可能被手动删掉了）',
            ]);
            return ['ok' => false, 'msg' => '这张图在服务器上找不到了，重新上传一次', 'sticker' => $name];
        }
        $key = self::botKey($tenantId);
        if ($key === '') {
            AiLibrary::log([
                'tenant_id' => $tenantId, 'group_openid' => $group,
                'sticker_id' => (int)$pick['id'], 'sticker_name' => $name, 'pick_mode' => 'random',
                'trigger_kind' => 'test', 'stage' => 'pick', 'status' => 'fail',
                'error' => '找不到本队机器人的回调密钥',
            ]);
            return ['ok' => false, 'msg' => '本队机器人还没保存凭据', 'sticker' => $name];
        }
        $res = QqBot::sendGroupImageWithBot($group, $abs, $key, '');
        if ($res['ok']) {
            AiLibrary::touchSent((int)$pick['id']);
            Cache::set('ai_sticker_last_' . $tenantId, '已发「' . $name . '」（手动测试）', 3600);
        } else {
            Cache::set('ai_sticker_last_' . $tenantId, '发送失败：' . $res['error'], 3600);
        }
        AiLibrary::log([
            'tenant_id' => $tenantId, 'group_openid' => $group,
            'sticker_id' => (int)$pick['id'], 'sticker_name' => $name, 'pick_mode' => 'random',
            'trigger_kind' => 'test', 'stage' => $res['stage'],
            'status' => $res['ok'] ? 'ok' : 'fail', 'error' => $res['error'],
            'duration_ms' => (int)$res['ms'],
        ]);
        return $res['ok']
            ? ['ok' => true, 'msg' => '已发出「' . $name . '」，去群里看一眼', 'sticker' => $name]
            : ['ok' => false, 'msg' => '发不出去：' . $res['error'] . '（' . $res['stage'] . '）', 'sticker' => $name];
    }

    /**
     * 这一条要不要追发表情包（平台总闸 + 本队概率；主动发言不发，省主动额度）
     * @return array{hit:bool,skip:string} skip 非空 = 本该发但发不了，需要记一行日志
     */
    private static function stickerDecide(array $cfg, string $kind): array
    {
        if ($kind === 'proactive' || (int)($cfg['platform_disabled'] ?? 0) === 1) {
            return ['hit' => false, 'skip' => ''];
        }
        $p = max(0, min(100, (int)($cfg['sticker_probability'] ?? 0)));
        if ($p <= 0) {
            return ['hit' => false, 'skip' => ''];
        }
        if (!AiClient::platformConfig()['sticker_enabled']) {
            return ['hit' => false, 'skip' => '平台把表情包总闸关掉了'];
        }
        return ['hit' => random_int(1, 100) <= $p, 'skip' => ''];
    }

    /** 本队机器人的回调密钥 */
    private static function botKey(int $tenantId): string
    {
        return (string)Db::name('bot_tenant_bots')->where('tenant_id', $tenantId)->value('callback_key');
    }

    /** 发送：优先被动回复（msg_id），失败再试主动；主动额度不足时由 QQ 侧拒绝 */
    private static function send(int $tenantId, string $group, string $text, string $msgId): bool
    {
        $key = self::botKey($tenantId);
        if ($key === '') {
            return false;
        }
        if ($msgId !== '') {
            if (QqBot::sendGroupTextWithBot($group, $text, $key, $msgId)) {
                return true;
            }
        }
        return QqBot::sendGroupTextWithBot($group, $text, $key, '');
    }

    private static function remember(int $tenantId, string $group, string $member, string $userText, string $reply, string $messageId): void
    {
        try {
            $now = date('Y-m-d H:i:s');
            $rows = [];
            if ($userText !== '') {
                $rows[] = [
                    'tenant_id' => $tenantId, 'group_openid' => $group, 'message_id' => mb_substr($messageId, 0, 64),
                    'member_openid' => $member, 'role' => 'user', 'content' => mb_substr($userText, 0, 500),
                    'created_at' => $now,
                ];
            }
            $rows[] = [
                'tenant_id' => $tenantId, 'group_openid' => $group, 'message_id' => '',
                'member_openid' => $member, 'role' => 'assistant', 'content' => mb_substr($reply, 0, 500),
                'created_at' => $now,
            ];
            Db::name('bot_chat_messages')->insertAll($rows);
            // 每次写上下文都顺手清 3 天前的，页面敢写「最多 3 天」就必须真的清
            Db::name('bot_chat_messages')
                ->where('created_at', '<', date('Y-m-d H:i:s', time() - 3 * 86400))
                ->delete();
        } catch (\Throwable $e) {
            // 上下文写失败不影响已发出的回复
        }
    }

    private static function context(int $tenantId, string $group): array
    {
        try {
            $rows = Db::name('bot_chat_messages')
                ->where('tenant_id', $tenantId)
                ->where('group_openid', $group)
                ->where('created_at', '>', date('Y-m-d H:i:s', time() - self::CONTEXT_TTL))
                ->order('id', 'desc')
                ->limit(self::CONTEXT_LIMIT)
                ->select()->toArray();
        } catch (\Throwable $e) {
            return [];
        }
        $rows = array_reverse($rows);
        $out = [];
        foreach ($rows as $r) {
            $role = (string)($r['role'] ?? 'user');
            if ($role !== 'user' && $role !== 'assistant') {
                continue;
            }
            $out[] = ['role' => $role, 'content' => mb_substr((string)($r['content'] ?? ''), 0, self::CONTEXT_CHARS)];
        }
        return $out;
    }

    private static function releaseStale(): void
    {
        try {
            Db::name('bot_ai_jobs')
                ->where('status', 'processing')
                ->where('started_at', '<', date('Y-m-d H:i:s', time() - self::JOB_STALE))
                ->update([
                    'status' => 'failed', 'error' => '处理超时', 'finished_at' => date('Y-m-d H:i:s'),
                ]);
        } catch (\Throwable $e) {
        }
    }

    private static function fail(array $job, string $error): void
    {
        try {
            Db::name('bot_ai_jobs')->where('id', (int)$job['id'])->update([
                'status'      => 'failed',
                'error'       => mb_substr($error, 0, 200),
                'finished_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
        }
    }

    private static function coolKey(int $tenantId, string $group): string
    {
        return 'ai_cool_' . $tenantId . '_' . md5($group);
    }

    private static function hourKey(int $tenantId, string $group): string
    {
        return 'ai_hour_' . $tenantId . '_' . md5($group) . '_' . date('YmdH');
    }

    private static function hourlyOk(int $tenantId, string $group, array $cfg): bool
    {
        $limit = (int)($cfg['hourly_limit'] ?? 0);
        if ($limit <= 0) {
            return true;
        }
        return (int)Cache::get(self::hourKey($tenantId, $group), 0) < $limit;
    }

    private static function setCooldown(int $tenantId, string $group): void
    {
        $cfg = AiClient::tenantConfig($tenantId);
        $cool = (int)($cfg['cooldown_seconds'] ?? 60);
        if ($cool > 0) {
            Cache::set(self::coolKey($tenantId, $group), 1, $cool);
        }
    }

    private static function markCooldown(int $tenantId, string $group): void
    {
        self::setCooldown($tenantId, $group);
        $hourKey = self::hourKey($tenantId, $group);
        $ttl = 3600 - (time() % 3600) + 5;
        Cache::set($hourKey, (int)Cache::get($hourKey, 0) + 1, $ttl);
    }

    // ==================== 定时主动发言 ====================

    /** 单队检查：开关 / 时段 / 间隔 / 每日上限 / 平台总上限 */
    public static function tickTenant(int $tenantId, bool $force = false): bool
    {
        $cfg = AiQuota::row($tenantId);
        if (!$cfg || !self::aiOn($cfg) || (int)($cfg['proactive_enabled'] ?? 0) !== 1) {
            return false;
        }
        $platform = AiClient::platformConfig();
        // 平台总开关只关首选模型；自有模型的主动发言不跟着停
        if ((string)($cfg['mode'] ?? 'off') === 'platform' && !$platform['enabled']) {
            return false;
        }
        $platformCap = (int)$platform['max_proactive_per_day'];
        if ($platformCap <= 0) {
            return false;
        }
        $dailyCap = min($platformCap, max(0, (int)($cfg['proactive_daily_limit'] ?? 0)));
        if ($dailyCap <= 0) {
            return false;
        }
        $hour = (int)date('G');
        $start = max(0, min(23, (int)($cfg['proactive_start_hour'] ?? 8)));
        $end = max(0, min(24, (int)($cfg['proactive_end_hour'] ?? 23)));
        if ($start !== $end) {
            $inWindow = $start < $end ? ($hour >= $start && $hour < $end) : ($hour >= $start || $hour < $end);
            if (!$inWindow) {
                return false;
            }
        }
        $interval = max(10, (int)($cfg['proactive_interval_minutes'] ?? 180));
        $last = (string)($cfg['last_proactive_at'] ?? '');
        if (!$force && $last !== '' && strtotime($last) > time() - $interval * 60) {
            return false;
        }
        $today = date('Y-m-d');
        $sent = (string)($cfg['proactive_date'] ?? '') === $today ? (int)($cfg['proactive_sent_today'] ?? 0) : 0;
        if ($sent >= $dailyCap) {
            return false;
        }
        $provider = AiClient::resolveProvider($tenantId);
        if (!$provider['ok'] || !AiQuota::dailyOk($cfg)) {
            return false;
        }
        $group = (string)Db::name('bot_group_bindings')->where('tenant_id', $tenantId)->value('group_openid');
        if ($group === '') {
            return false;
        }
        $lock = 'ai_pro_lock_' . $tenantId;
        if (Cache::get($lock)) {
            return false;
        }
        Cache::set($lock, 1, 120);
        try {
            if (!self::queue($tenantId, $group, '', '', '（主动发言）', 'proactive')) {
                return false;
            }
            Db::name('tenant_ai_configs')->where('tenant_id', $tenantId)->update([
                'last_proactive_at'    => date('Y-m-d H:i:s'),
                'proactive_date'       => $today,
                'proactive_sent_today' => $sent + 1,
                'updated_at'           => date('Y-m-d H:i:s'),
            ]);
            return true;
        } catch (\Throwable $e) {
            AiQuota::error($tenantId, '主动发言入队失败：' . $e->getMessage());
            return false;
        } finally {
            // 锁只防并发重复入队，不是冷却；操作完立刻释放，否则第二次试发会被误挡
            Cache::delete($lock);
        }
    }

    /** 有人打开战队站点时顺路查一次（每队 5 分钟一次，避免每个请求都扫） */
    public static function tickLazy(int $tenantId): bool
    {
        if ($tenantId <= 0) {
            return false;
        }
        $lock = 'ai_lazy_t' . $tenantId;
        if (Cache::get($lock) !== null) {
            return false;
        }
        Cache::set($lock, 1, 300);
        $queued = self::tickTenant($tenantId);
        // v5.36.26：不只是主动发言——队列里堆着的聊天任务也在这里顺手处理掉。
        // 有些主机会把回调结束后的后台任务掐断，队员/队长只要打开一次站点，积压就能被消化。
        self::runAfterResponse();
        return $queued;
    }

    /** 扫描到期战队（建议由回调 shutdown / 登录 tick 触发，带 60 秒节流） */
    public static function tickDue(int $limit = 5, int $throttle = 60): int
    {
        if ($throttle > 0) {
            $key = 'ai_tick_last';
            if (Cache::get($key)) {
                return 0;
            }
            Cache::set($key, 1, $throttle);
        }
        try {
            $rows = Db::name('tenant_ai_configs')
                ->where('proactive_enabled', 1)
                ->where('platform_disabled', 0)
                ->whereIn('mode', ['platform', 'own'])
                ->order('last_proactive_at', 'asc')
                ->limit($limit)
                ->select()->toArray();
        } catch (\Throwable $e) {
            return 0;
        }
        $n = 0;
        foreach ($rows as $row) {
            try {
                if (self::tickTenant((int)$row['tenant_id'])) {
                    $n++;
                }
            } catch (\Throwable $e) {
                AiQuota::error((int)$row['tenant_id'], '主动发言检查失败：' . $e->getMessage());
            }
        }
        return $n;
    }

    /** 响应结束后按需清理队列；FPM 上先断开 QQ 连接再干活 */
    public static function runAfterResponse(): void
    {
        static $registered = false;
        if ($registered) {
            return;
        }
        $registered = true;
        register_shutdown_function(static function () {
            if (function_exists('fastcgi_finish_request')) {
                @fastcgi_finish_request();
            }
            @set_time_limit(60);
            try {
                self::drain(3);
                Cache::set('ai_drain_beat', time(), 86400);   // 自检页用它判断「回调结束后到底有没有人处理队列」
            } catch (\Throwable $e) {
                QqBot::debugLog(['type' => 'ai_drain_error', 'error' => $e->getMessage()]);
            }
            try {
                // 主动发言排在用户回复之后，避免一次模型调用占掉被动回复窗口
                self::tickDue(2, 60);
                self::drain(1);
            } catch (\Throwable $e) {
                QqBot::debugLog(['type' => 'ai_tick_error', 'error' => $e->getMessage()]);
            }
        });
    }
}
