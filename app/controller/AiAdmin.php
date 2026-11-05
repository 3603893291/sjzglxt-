<?php
declare (strict_types = 1);

namespace app\controller;

use app\service\AiChat;
use app\service\AiClient;
use app\service\AiLibrary;
use app\service\AiQuota;
use app\service\TenantContext;
use think\facade\Db;

/**
 * 战队管理中心·AI 聊天（v5.36.14，沿用 bot 权限）
 *
 *   GET  /api/admin/ai                 当前模式 / 余额 / 行为参数 / 平台人设清单（只能选）
 *   POST /api/admin/ai/save            选平台模型或自有模型，调冷却/上限/主动发言/表情包概率
 *   POST /api/admin/ai/test            测试自有模型连接（平台模型的密钥不下发战队）
 *   POST /api/admin/ai/models          拉自有模型的模型列表（省得手打模型名）
 *   POST /api/admin/ai/sticker-test    立刻发一张表情包（排障，直接回 QQ 的报错）
 *   POST /api/admin/ai/sticker-logs/clear 清空表情包发送日志
 *   POST /api/admin/ai/clear-context   清空本队短期聊天上下文
 *   POST /api/admin/ai/proactive-test  立刻试发一条主动发言
 *
 * 人设和表情包素材都归平台：人设只能从平台库里选一条，表情包只能调自己发的概率。
 * 硬规则由服务端始终追加。
 */
class AiAdmin extends AuthBase
{
    private function noPerm()
    {
        return api_err('您没有权限执行此操作，请联系超级管理员分配权限', 403, 403);
    }

    public function index()
    {
        if (!admin_can('bot')) {
            return $this->noPerm();
        }
        $tid = TenantContext::id();
        $cfg = AiQuota::ensure($tid);
        $p   = AiClient::platformConfig();

        $bot = Db::name('bot_tenant_bots')->where('tenant_id', $tid)->where('enabled', 1)->find();
        $bound = Db::name('bot_group_bindings')->where('tenant_id', $tid)->find();
        $balance = AiQuota::balance($cfg);
        $platformReady = $p['enabled'] && $p['base_url'] !== '' && $p['api_key'] !== '' && $p['model'] !== '';
        $presetId = (int)($cfg['prompt_preset_id'] ?? 0);
        $effective = AiClient::effectivePrompt($cfg, $p);
        $prompts = AiLibrary::prompts(true);
        $stickerCount = AiLibrary::stickerCount(true);

        $today = date('Y-m-d 00:00:00');
        $t = Db::name('ai_usage_logs')->where('tenant_id', $tid)->where('created_at', '>=', $today)
            ->field('COUNT(*) AS calls,SUM(total_tokens) AS tokens')->find() ?: [];
        $week = Db::name('ai_usage_logs')->where('tenant_id', $tid)
            ->where('created_at', '>=', date('Y-m-d 00:00:00', strtotime('-6 days')))
            ->field('COUNT(*) AS calls,SUM(total_tokens) AS tokens')->find() ?: [];

        $recent = Db::name('ai_usage_logs')->where('tenant_id', $tid)
            ->field('kind,source,model,total_tokens,status,created_at')
            ->order('id', 'desc')->limit(15)->select()->toArray();

        return api_ok([
            'config' => [
                'mode'                      => (string)$cfg['mode'],
                'auto_reply'                => (int)$cfg['auto_reply'],
                'reply_mode'                => (string)$cfg['reply_mode'],
                'bot_alias'                 => (string)$cfg['bot_alias'],
                'prompt_preset_id'          => $presetId,
                'cooldown_seconds'          => (int)$cfg['cooldown_seconds'],
                'hourly_limit'              => (int)$cfg['hourly_limit'],
                'daily_token_limit'         => (int)$cfg['daily_token_limit'],
                'chat_probability'          => (int)$cfg['chat_probability'],
                'sticker_probability'       => (int)$cfg['sticker_probability'],
                'proactive_enabled'         => (int)$cfg['proactive_enabled'],
                'proactive_interval_minutes'=> (int)$cfg['proactive_interval_minutes'],
                'proactive_daily_limit'     => (int)$cfg['proactive_daily_limit'],
                'proactive_start_hour'      => (int)$cfg['proactive_start_hour'],
                'proactive_end_hour'        => (int)$cfg['proactive_end_hour'],
            ],
            'own' => [
                'base_url'    => (string)$cfg['own_base_url'],
                'model'       => (string)$cfg['own_model'],
                'api_key_mask'=> AiClient::maskKey((string)$cfg['own_api_key']),
                'has_key'     => (string)$cfg['own_api_key'] !== '',
            ],
            'platform' => [
                'enabled'      => $p['enabled'] ? 1 : 0,
                'ready'        => $platformReady ? 1 : 0,
                'model'        => $p['model'],
                'max_tokens'   => $p['max_tokens'],
                'prompt'       => $effective,
                'prompt_base'  => $p['prompt'],
                'prompt_preset_id' => $presetId,
                'prompt_is_custom' => $presetId > 0 && AiLibrary::promptUsable($presetId) ? 1 : 0,
                'safety'       => AiClient::SAFETY_SUFFIX,
                'allow_links'  => $p['allow_links'] ? 1 : 0,
                'platform_disabled' => (int)$cfg['platform_disabled'],
                'max_proactive_per_day' => $p['max_proactive_per_day'],
                'sticker_enabled' => $p['sticker_enabled'] ? 1 : 0,
                'sticker_count'   => $stickerCount,
            ],
            'prompts' => array_map(static function (array $row): array {
                return ['id' => (int)$row['id'], 'name' => (string)$row['name'], 'note' => (string)$row['note']];
            }, $prompts),
            'quota' => [
                'granted'      => (int)$cfg['token_granted'],
                'used'         => (int)$cfg['token_used'],
                'balance'      => $balance,
                'daily_used'   => (string)($cfg['daily_date'] ?? '') === date('Y-m-d') ? (int)$cfg['daily_used'] : 0,
                'daily_limit'  => (int)$cfg['daily_token_limit'],
                'last_error'   => (string)$cfg['last_error'],
                'last_used_at' => (string)($cfg['last_used_at'] ?? ''),
                'last_proactive_at' => (string)($cfg['last_proactive_at'] ?? ''),
            ],
            'bot' => [
                'enabled' => $bot ? 1 : 0,
                'bound'   => $bound ? 1 : 0,
            ],
            'usage' => [
                'today_calls'  => (int)($t['calls'] ?? 0),
                'today_tokens' => (int)($t['tokens'] ?? 0),
                'week_calls'   => (int)($week['calls'] ?? 0),
                'week_tokens'  => (int)($week['tokens'] ?? 0),
                'recent'       => $recent,
            ],
            'jobs' => [
                'pending' => (int)Db::name('bot_ai_jobs')->where('tenant_id', $tid)->where('status', 'pending')->count(),
                'failed_24h' => (int)Db::name('bot_ai_jobs')->where('tenant_id', $tid)->where('status', 'failed')
                    ->where('created_at', '>=', date('Y-m-d H:i:s', time() - 86400))->count(),
            ],
            'sticker_log' => AiLibrary::logs($tid, 30),
        ]);
    }

    public function save()
    {
        if (!admin_can('bot')) {
            return $this->noPerm();
        }
        $tid = TenantContext::id();
        $cfg = AiQuota::ensure($tid);

        $mode = (string)$this->request->post('mode', 'off');
        if (!in_array($mode, ['off', 'platform', 'own'], true)) {
            return api_err('AI 模式不合法');
        }
        $replyMode = (string)$this->request->post('reply_mode', 'at');
        if (!in_array($replyMode, ['at', 'all'], true)) {
            $replyMode = 'at';
        }

        // 切换模式时没带的字段保持原值，避免「选平台模型」把自有模型的地址/模型名清空
        $posted   = (array)$this->request->post();
        $ownBase  = array_key_exists('own_base_url', $posted)
            ? trim((string)$posted['own_base_url']) : (string)($cfg['own_base_url'] ?? '');
        $ownModel = array_key_exists('own_model', $posted)
            ? trim((string)$posted['own_model']) : (string)($cfg['own_model'] ?? '');
        $ownKey   = trim((string)$this->request->post('own_api_key', ''));
        $clearKey = (int)$this->request->post('clear_own_key', 0) === 1;
        if ($clearKey) {
            $ownKey = '';
        } elseif ($ownKey === '') {
            $ownKey = (string)($cfg['own_api_key'] ?? '');
        }
        if (mb_strlen($ownBase) > 255 || mb_strlen($ownModel) > 64 || mb_strlen($ownKey) > 255) {
            return api_err('自有模型配置过长');
        }
        if ($ownBase !== '') {
            $err = AiClient::validateBaseUrl($ownBase);
            if ($err !== null) {
                return api_err('自有模型地址不可用：' . $err);
            }
        }
        if ($mode === 'own' && ($ownBase === '' || $ownKey === '' || $ownModel === '')) {
            return api_err('使用自有模型前，请把地址、密钥、模型名三项都填完');
        }

        $alias = trim((string)$this->request->post('bot_alias', ''));
        if (mb_strlen($alias) > 32) {
            return api_err('机器人称呼最长 32 个字符');
        }

        $cooldown = max(0, min(3600, (int)$this->request->post('cooldown_seconds', 60)));
        $hourly   = max(0, min(1000, (int)$this->request->post('hourly_limit', 20)));
        $daily    = max(0, min(10000000, (int)$this->request->post('daily_token_limit', 100000)));
        $prob     = max(0, min(100, (int)$this->request->post('chat_probability', 20)));
        $sticker  = max(0, min(100, (int)$this->request->post('sticker_probability', 0)));
        // 人设只能选平台库里现有且没下架的那条；0 = 用平台默认模板
        $preset   = max(0, (int)$this->request->post('prompt_preset_id', 0));
        if ($preset > 0 && !AiLibrary::promptUsable($preset)) {
            return api_err('这条人设已经被平台下架或删除了，请重新选一条（或选「平台默认」）');
        }
        $pInterval= max(10, min(1440, (int)$this->request->post('proactive_interval_minutes', 180)));
        $pDaily   = max(0, min(10, (int)$this->request->post('proactive_daily_limit', 2)));
        $pStart   = max(0, min(23, (int)$this->request->post('proactive_start_hour', 8)));
        $pEnd     = max(0, min(24, (int)$this->request->post('proactive_end_hour', 23)));
        $autoReply= (int)$this->request->post('auto_reply', 0) === 1 && $mode !== 'off';
        $proactive= (int)$this->request->post('proactive_enabled', 0) === 1 && $mode !== 'off';

        AiQuota::save($tid, [
            'mode'                       => $mode,
            'auto_reply'                 => $autoReply ? 1 : 0,
            'reply_mode'                 => $replyMode,
            'bot_alias'                  => $alias,
            'prompt_preset_id'           => $preset,
            'cooldown_seconds'           => $cooldown,
            'hourly_limit'               => $hourly,
            'daily_token_limit'          => $daily,
            'chat_probability'           => $prob,
            'sticker_probability'        => $sticker,
            'proactive_enabled'          => $proactive ? 1 : 0,
            'proactive_interval_minutes' => $pInterval,
            'proactive_daily_limit'      => $pDaily,
            'proactive_start_hour'       => $pStart,
            'proactive_end_hour'         => $pEnd,
            'own_base_url'               => $ownBase,
            'own_api_key'                => $ownKey,
            'own_model'                  => $ownModel,
        ]);
        admin_log('AI聊天', '保存 AI 配置：模式=' . $mode . '，自动接话=' . ($autoReply ? '开' : '关')
            . '，主动发言=' . ($proactive ? '开' : '关'));
        return api_ok(['mode' => $mode], 'AI 聊天设置已保存');
    }

    public function test()
    {
        if (!admin_can('bot')) {
            return $this->noPerm();
        }
        $tid = TenantContext::id();
        $cfg = AiQuota::ensure($tid);
        $base  = trim((string)$this->request->post('own_base_url', ''));
        $key   = trim((string)$this->request->post('own_api_key', ''));
        $model = trim((string)$this->request->post('own_model', ''));
        if ($base === '')  { $base  = (string)($cfg['own_base_url'] ?? ''); }
        if ($key === '')   { $key   = (string)($cfg['own_api_key'] ?? ''); }
        if ($model === '') { $model = (string)($cfg['own_model'] ?? ''); }
        if ($base === '' || $key === '' || $model === '') {
            return api_err('请先填完自有模型的地址、密钥、模型名');
        }
        $err = AiClient::validateBaseUrl($base);
        if ($err !== null) {
            return api_err('自有模型地址不可用：' . $err);
        }
        [$ok, $msg] = AiClient::test($base, $key, $model);
        admin_log('AI聊天', '测试自有模型连接：' . ($ok ? '成功' : '失败'));
        return $ok ? api_ok([], $msg) : api_err($msg);
    }

    /** 拉自有模型的模型列表：填了 Base URL 就不用再手打模型名（v5.36.29） */
    public function models()
    {
        if (!admin_can('bot')) {
            return $this->noPerm();
        }
        $cfg = AiQuota::ensure(TenantContext::id());
        $base = trim((string)$this->request->post('own_base_url', ''));
        $key  = trim((string)$this->request->post('own_api_key', ''));
        if ($base === '') { $base = (string)($cfg['own_base_url'] ?? ''); }
        if ($key === '')  { $key  = (string)($cfg['own_api_key'] ?? ''); }
        $res = AiClient::listModels($base, $key);
        if (!$res['ok']) {
            return api_err($res['error']);
        }
        return api_ok(['models' => $res['models']], '已获取 ' . count($res['models']) . ' 个模型');
    }

    /**
     * 立刻发一张表情包（不经过模型），直接把 QQ 的真实返回带回来。
     * 排障用：发得出去说明图、权限、额度都没问题；发不出去这里就是那句话。
     */
    public function stickerTest()
    {
        if (!admin_can('bot')) {
            return $this->noPerm();
        }
        $tid = TenantContext::id();
        $r = AiChat::sendStickerNow($tid, (int)$this->request->post('sticker_id', 0));
        admin_log('AI聊天', '手动发一张表情包：' . ($r['ok'] ? '成功' : '失败')
            . ($r['sticker'] !== '' ? '（' . $r['sticker'] . '）' : ''));
        return $r['ok'] ? api_ok(['sticker' => $r['sticker']], $r['msg']) : api_err($r['msg']);
    }

    public function stickerLogsClear()
    {
        if (!admin_can('bot')) {
            return $this->noPerm();
        }
        $n = AiLibrary::clearLogs(TenantContext::id());
        admin_log('AI聊天', '清空表情包发送日志 ' . $n . ' 条');
        return api_ok(['deleted' => $n], $n > 0 ? '已清空 ' . $n . ' 条日志' : '日志本来就是空的');
    }

    public function clearContext()
    {
        if (!admin_can('bot')) {
            return $this->noPerm();
        }
        $tid = TenantContext::id();
        $n = Db::name('bot_chat_messages')->where('tenant_id', $tid)->delete();
        admin_log('AI聊天', '清空短期聊天上下文 ' . $n . ' 条');
        return api_ok(['deleted' => $n], '已清空本队 AI 的短期聊天记忆');
    }

    public function proactiveTest()
    {
        if (!admin_can('bot')) {
            return $this->noPerm();
        }
        $tid = TenantContext::id();
        $ok = AiChat::tickTenant($tid, true);
        if (!$ok) {
            return api_err('现在不满足主动发言条件：请检查 AI 模式、主动发言开关、时段、每日上限和 Token 余额');
        }
        $n = AiChat::drain(3);
        admin_log('AI聊天', '手动试发主动发言');
        return api_ok(['processed' => $n], $n > 0 ? '已尝试发送一条主动发言' : '已排队，等下一次队列处理');
    }
}
