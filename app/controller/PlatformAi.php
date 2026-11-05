<?php
declare (strict_types = 1);

namespace app\controller;

use app\BaseController;
use app\service\AiChat;
use app\service\AiClient;
use app\service\AiLibrary;
use app\service\AiQuota;
use app\service\Setting;
use think\facade\Db;

/**
 * 平台侧 AI 聊天（v5.36.14）
 *
 *   GET  /api/platform/admin/ai                 平台模型配置 + 各队余额/用量
 *   POST /api/platform/admin/ai/save            首选模型、提示词、总开关
 *   POST /api/platform/admin/ai/test            测试首选模型连接
 *   GET  /api/platform/admin/ai/tenants         各队余额（单独刷新用）
 *   POST /api/platform/admin/ai/tenants/:id/grant   充值
 *   POST /api/platform/admin/ai/tenants/:id/reset   清零余额
 *   POST /api/platform/admin/ai/tenants/:id/disable 停用/恢复某队 AI（含自有模型）
 *   POST /api/platform/admin/ai/tick            扫一遍到点的主动发言
 *
 * 平台只发 Token 和改提示词，不读战队群聊天正文；用量流水只记 Token 数与模型名。
 */
class PlatformAi extends BaseController
{
    private function gate()
    {
        if (!\app\service\TenantContext::isPlatform()) {
            return api_err('该接口仅入驻平台可用', 404, 404);
        }
        if (!function_exists('is_platform_super') || !\is_platform_super()) {
            return api_err('需要平台超级管理员登录态', 403, 403);
        }
        return null;
    }

    public function index()
    {
        if ($r = $this->gate()) {
            return $r;
        }
        $p = AiClient::platformConfig();
        return api_ok([
            'config' => [
                'enabled'               => $p['enabled'] ? 1 : 0,
                'base_url'              => $p['base_url'],
                'model'                 => $p['model'],
                'api_key_mask'          => AiClient::maskKey($p['api_key']),
                'has_key'               => $p['api_key'] !== '',
                'max_tokens'            => $p['max_tokens'],
                'temperature'           => $p['temperature'],
                'prompt'                => $p['prompt'],
                'proactive_prompt'      => $p['proactive_prompt'],
                'allow_links'           => $p['allow_links'] ? 1 : 0,
                'max_proactive_per_day' => $p['max_proactive_per_day'],
                'sticker_enabled'       => $p['sticker_enabled'] ? 1 : 0,
            ],
            'defaults' => [
                'prompt'           => AiClient::DEFAULT_PROMPT,
                'proactive_prompt' => AiClient::DEFAULT_PROACTIVE_PROMPT,
                'safety'           => AiClient::SAFETY_SUFFIX,
            ],
            'prompts'  => AiLibrary::prompts(),
            'stickers' => AiLibrary::stickers(),
            'tenants'  => $this->tenantRows(),
            'stats'    => $this->stats(),
            'grants'   => $this->recentGrants(),
            'usage'    => $this->recentUsage(),
        ]);
    }

    public function save()
    {
        if ($r = $this->gate()) {
            return $r;
        }
        $enabled  = (int)$this->request->post('enabled', 0) === 1;
        $baseUrl  = trim((string)$this->request->post('base_url', ''));
        $model    = trim((string)$this->request->post('model', ''));
        $apiKey   = trim((string)$this->request->post('api_key', ''));
        $clearKey = (int)$this->request->post('clear_key', 0) === 1;

        if ($baseUrl !== '') {
            $err = AiClient::validateBaseUrl($baseUrl);
            if ($err !== null) {
                return api_err('模型地址不可用：' . $err);
            }
        }
        if (mb_strlen($model) > 64) {
            return api_err('模型名最长 64 个字符');
        }
        $oldKey = trim(Setting::getPlatform('ai_platform_api_key', ''));
        $newKey = $clearKey ? '' : ($apiKey !== '' ? $apiKey : $oldKey);
        if ($enabled && ($baseUrl === '' || $newKey === '' || $model === '')) {
            return api_err('开启前请把地址、密钥、模型名三项都填完');
        }
        if ($apiKey !== '' && strlen($apiKey) > 255) {
            return api_err('密钥过长');
        }

        $maxTokens = max(32, min(300, (int)$this->request->post('max_tokens', 120)));
        $temp      = max(0.0, min(2.0, (float)$this->request->post('temperature', 0.7)));
        $prompt    = trim((string)$this->request->post('prompt', ''));
        $proactive = trim((string)$this->request->post('proactive_prompt', ''));
        if (mb_strlen($prompt) > 4000) {
            return api_err('提示词最长 4000 字');
        }
        if (mb_strlen($proactive) > 1000) {
            return api_err('主动发言提示最长 1000 字');
        }

        Setting::setPlatform('ai_global_enabled', $enabled ? '1' : '0');
        Setting::setPlatform('ai_platform_base_url', $baseUrl);
        Setting::setPlatform('ai_platform_api_key', $newKey);
        Setting::setPlatform('ai_platform_model', $model);
        Setting::setPlatform('ai_platform_max_tokens', (string)$maxTokens);
        Setting::setPlatform('ai_platform_temperature', (string)$temp);
        Setting::setPlatform('ai_prompt_template', $prompt);
        Setting::setPlatform('ai_proactive_prompt', $proactive);
        Setting::setPlatform('ai_allow_links', (int)$this->request->post('allow_links', 0) === 1 ? '1' : '0');
        Setting::setPlatform('ai_platform_max_proactive_per_day', (string)max(0, min(10, (int)$this->request->post('max_proactive_per_day', 2))));
        // v5.36.29：不改这一项时保持原值（老面板不带这个字段，不能把它悄悄关掉）
        $posted = (array)$this->request->post();
        if (array_key_exists('sticker_enabled', $posted)) {
            Setting::setPlatform('ai_sticker_enabled', (int)$posted['sticker_enabled'] === 1 ? '1' : '0');
        }

        return api_ok([
            'enabled'      => $enabled ? 1 : 0,
            'api_key_mask' => AiClient::maskKey($newKey),
            'has_key'      => $newKey !== '',
        ], $enabled ? '平台首选模型已保存并开启' : '平台首选模型已保存（当前未开启）');
    }

    public function test()
    {
        if ($r = $this->gate()) {
            return $r;
        }
        $baseUrl = trim((string)$this->request->post('base_url', ''));
        $apiKey  = trim((string)$this->request->post('api_key', ''));
        $model   = trim((string)$this->request->post('model', ''));
        if ($baseUrl === '') { $baseUrl = trim(Setting::getPlatform('ai_platform_base_url', '')); }
        if ($apiKey === '')  { $apiKey  = trim(Setting::getPlatform('ai_platform_api_key', '')); }
        if ($model === '')   { $model   = trim(Setting::getPlatform('ai_platform_model', '')); }
        if ($baseUrl === '' || $apiKey === '' || $model === '') {
            return api_err('请先填完地址、密钥、模型名');
        }
        $err = AiClient::validateBaseUrl($baseUrl);
        if ($err !== null) {
            return api_err('模型地址不可用：' . $err);
        }
        [$ok, $msg] = AiClient::test($baseUrl, $apiKey, $model);
        return $ok ? api_ok([], $msg) : api_err($msg);
    }

    public function tenants()
    {
        if ($r = $this->gate()) {
            return $r;
        }
        return api_ok(['tenants' => $this->tenantRows()]);
    }

    public function grant($id)
    {
        if ($r = $this->gate()) {
            return $r;
        }
        $tid = (int)$id;
        if (!Db::name('tenants')->where('id', $tid)->find()) {
            return api_err('战队不存在', 404);
        }
        $amount = (int)$this->request->post('amount', 0);
        $note   = trim((string)$this->request->post('note', ''));
        [$ok, $res] = AiQuota::grant($tid, $amount, (int)(current_platform_user()->id ?? 0), $note);
        if (!$ok) {
            return api_err((string)$res);
        }
        return api_ok(['balance' => $res], '已充值 ' . number_format($amount) . ' Token，当前余额 ' . number_format($res));
    }

    public function reset($id)
    {
        if ($r = $this->gate()) {
            return $r;
        }
        $tid = (int)$id;
        if (!Db::name('tenants')->where('id', $tid)->find()) {
            return api_err('战队不存在', 404);
        }
        $note = trim((string)$this->request->post('note', ''));
        [$ok, $balance] = AiQuota::reset($tid, (int)(current_platform_user()->id ?? 0), $note);
        if (!$ok) {
            return api_err('清零失败');
        }
        return api_ok(['balance' => $balance], '该战队平台 Token 余额已清零');
    }

    public function disable($id)
    {
        if ($r = $this->gate()) {
            return $r;
        }
        $tid = (int)$id;
        if (!Db::name('tenants')->where('id', $tid)->find()) {
            return api_err('战队不存在', 404);
        }
        $off = (int)$this->request->post('disabled', 1) === 1;
        AiQuota::save($tid, ['platform_disabled' => $off ? 1 : 0]);
        return api_ok(['platform_disabled' => $off ? 1 : 0],
            $off ? '已停用该战队 AI（自有模型也一起停）' : '已恢复该战队 AI');
    }

    public function tick()
    {
        if ($r = $this->gate()) {
            return $r;
        }
        $queued = AiChat::tickDue(5, 0);
        $sent   = $queued > 0 ? AiChat::drain($queued) : 0;
        return api_ok(['queued' => $queued, 'processed' => $sent],
            $queued > 0 ? '已排队 ' . $queued . ' 条主动发言' : '暂时没有到点的主动发言');
    }

    /** 拉上游模型列表：填了 Base URL 就不用再手打模型名（v5.36.29） */
    public function models()
    {
        if ($r = $this->gate()) {
            return $r;
        }
        $baseUrl = trim((string)$this->request->post('base_url', ''));
        $apiKey  = trim((string)$this->request->post('api_key', ''));
        if ($baseUrl === '') { $baseUrl = trim(Setting::getPlatform('ai_platform_base_url', '')); }
        if ($apiKey === '')  { $apiKey  = trim(Setting::getPlatform('ai_platform_api_key', '')); }
        $res = AiClient::listModels($baseUrl, $apiKey);
        if (!$res['ok']) {
            return api_err($res['error']);
        }
        return api_ok(['models' => $res['models']], '已获取 ' . count($res['models']) . ' 个模型');
    }

    // ==================== 人设库（v5.36.29） ====================

    public function prompts()
    {
        if ($r = $this->gate()) {
            return $r;
        }
        return api_ok(['prompts' => AiLibrary::prompts(), 'limit' => AiLibrary::MAX_PROMPTS]);
    }

    public function promptSave()
    {
        if ($r = $this->gate()) {
            return $r;
        }
        [$ok, $msg, $id] = AiLibrary::savePrompt(
            (int)$this->request->post('id', 0),
            (string)$this->request->post('name', ''),
            (string)$this->request->post('body', ''),
            (string)$this->request->post('note', ''),
            (int)$this->request->post('enabled', 1) === 1 ? 1 : 0,
            max(-9999, min(9999, (int)$this->request->post('sort', 0))),
            (int)(current_platform_user()->id ?? 0)
        );
        if (!$ok) {
            return api_err($msg);
        }
        return api_ok(['id' => $id, 'prompts' => AiLibrary::prompts()], $msg);
    }

    public function promptDelete($id)
    {
        if ($r = $this->gate()) {
            return $r;
        }
        $id = (int)$id;
        if (!AiLibrary::deletePrompt($id)) {
            return api_err('这条人设已经不在了');
        }
        return api_ok(['prompts' => AiLibrary::prompts()], '人设已删除；正在用它的战队已自动回落到平台默认模板');
    }

    // ==================== 表情包库（v5.36.29） ====================

    public function stickers()
    {
        if ($r = $this->gate()) {
            return $r;
        }
        return api_ok(['stickers' => AiLibrary::stickers(), 'limit' => AiLibrary::MAX_STICKERS]);
    }

    public function stickerUpload()
    {
        if ($r = $this->gate()) {
            return $r;
        }
        [$ok, $msg, $id] = AiLibrary::addSticker(
            $this->request->file('image'),
            (string)$this->request->post('name', ''),
            (string)$this->request->post('note', ''),
            max(-9999, min(9999, (int)$this->request->post('sort', 0))),
            (int)(current_platform_user()->id ?? 0)
        );
        if (!$ok) {
            return api_err($msg);
        }
        return api_ok(['id' => $id, 'stickers' => AiLibrary::stickers()], $msg);
    }

    public function stickerSave()
    {
        if ($r = $this->gate()) {
            return $r;
        }
        [$ok, $msg] = AiLibrary::saveSticker(
            (int)$this->request->post('id', 0),
            (string)$this->request->post('name', ''),
            (string)$this->request->post('note', ''),
            (int)$this->request->post('enabled', 1) === 1 ? 1 : 0,
            max(-9999, min(9999, (int)$this->request->post('sort', 0)))
        );
        if (!$ok) {
            return api_err($msg);
        }
        return api_ok(['stickers' => AiLibrary::stickers()], $msg);
    }

    public function stickerDelete($id)
    {
        if ($r = $this->gate()) {
            return $r;
        }
        if (!AiLibrary::deleteSticker((int)$id)) {
            return api_err('这张表情包已经不在了');
        }
        return api_ok(['stickers' => AiLibrary::stickers()], '表情包已删除');
    }

    // ==================== 组装 ====================

    private function tenantRows(): array
    {
        $tenants = Db::name('tenants')->field('id,name,slug,status')->order('id', 'asc')->select()->toArray();
        $cfgs = [];
        try {
            foreach (Db::name('tenant_ai_configs')->select()->toArray() as $row) {
                $cfgs[(int)$row['tenant_id']] = $row;
            }
        } catch (\Throwable $e) {
        }
        $bound = [];
        try {
            foreach (Db::name('bot_group_bindings')->field('tenant_id')->select()->toArray() as $b) {
                $bound[(int)$b['tenant_id']] = true;
            }
        } catch (\Throwable $e) {
        }
        $out = [];
        foreach ($tenants as $t) {
            $id = (int)$t['id'];
            $c  = $cfgs[$id] ?? AiClient::defaultTenantRow($id);
            $out[] = [
                'id'              => $id,
                'name'            => (string)$t['name'],
                'slug'            => (string)$t['slug'],
                'status'          => (string)$t['status'],
                'bound'           => isset($bound[$id]),
                'mode'            => (string)($c['mode'] ?? 'off'),
                'platform_disabled' => (int)($c['platform_disabled'] ?? 0),
                'token_granted'   => (int)($c['token_granted'] ?? 0),
                'token_used'      => (int)($c['token_used'] ?? 0),
                'balance'         => AiQuota::balance($c),
                'daily_used'      => (string)($c['daily_date'] ?? '') === date('Y-m-d') ? (int)($c['daily_used'] ?? 0) : 0,
                'daily_limit'     => (int)($c['daily_token_limit'] ?? 0),
                'last_error'      => (string)($c['last_error'] ?? ''),
                'last_used_at'    => (string)($c['last_used_at'] ?? ''),
            ];
        }
        return $out;
    }

    private function stats(): array
    {
        $since = date('Y-m-d 00:00:00', strtotime('-6 days'));
        $rows = Db::name('ai_usage_logs')
            ->where('created_at', '>=', $since)
            ->field('source,status,COUNT(*) AS calls,SUM(total_tokens) AS tokens')
            ->group('source,status')
            ->select()->toArray();
        $out = [
            'calls_7d' => 0, 'tokens_7d' => 0,
            'platform_calls_7d' => 0, 'platform_tokens_7d' => 0,
            'own_calls_7d' => 0, 'own_tokens_7d' => 0,
            'fail_7d' => 0,
        ];
        foreach ($rows as $r) {
            $calls = (int)$r['calls'];
            $tokens = (int)$r['tokens'];
            $out['calls_7d'] += $calls;
            $out['tokens_7d'] += $tokens;
            if ((string)$r['source'] === 'platform') {
                $out['platform_calls_7d'] += $calls;
                $out['platform_tokens_7d'] += $tokens;
            } else {
                $out['own_calls_7d'] += $calls;
                $out['own_tokens_7d'] += $tokens;
            }
            if ((string)$r['status'] !== 'ok') {
                $out['fail_7d'] += $calls;
            }
        }
        $out['pending_jobs'] = (int)Db::name('bot_ai_jobs')->where('status', 'pending')->count();
        $out['failed_jobs_24h'] = (int)Db::name('bot_ai_jobs')
            ->where('status', 'failed')->where('created_at', '>=', date('Y-m-d H:i:s', time() - 86400))->count();
        return $out;
    }

    private function recentGrants(): array
    {
        $rows = Db::name('ai_token_grants')->order('id', 'desc')->limit(20)->select()->toArray();
        $names = Db::name('tenants')->column('name', 'id');
        foreach ($rows as &$r) {
            $r['tenant_name'] = (string)($names[(int)$r['tenant_id']] ?? ('#' . (int)$r['tenant_id']));
        }
        return $rows;
    }

    private function recentUsage(): array
    {
        $rows = Db::name('ai_usage_logs')->order('id', 'desc')->limit(30)->select()->toArray();
        $names = Db::name('tenants')->column('name', 'id');
        foreach ($rows as &$r) {
            $r['tenant_name'] = (string)($names[(int)$r['tenant_id']] ?? ('#' . (int)$r['tenant_id']));
            // 不把群 openid / 成员 openid 回给前端，用量页只关心数字
            unset($r['group_openid'], $r['member_openid']);
        }
        return $rows;
    }
}
