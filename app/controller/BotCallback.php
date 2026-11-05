<?php
declare (strict_types = 1);

namespace app\controller;

use app\service\AiChat;
use app\service\BotCommand;
use app\service\QqBot;
use think\facade\Cache;
use think\facade\Db;
use think\Request;

/**
 * QQ 官方机器人 Webhook 回调（v5.8.0）
 *
 * 根级路由（无登录/租户中间件），安全性由以下机制保证：
 * - 回调地址里的随机密钥：官方只对 op:13 的 URL 校验要求应答签名，事件推送（op:0）
 *   没有任何入站验签头，所以「地址带密钥」是本站唯一能辨识来源的手段；
 *   v5.28.0 起密钥只有战队独立机器人那一份（bot_tenant_bots.callback_key），
 *   平台公共机器人与无密钥旧地址都已下线；
 * - op:13 配置验证：以 AppSecret 为 ed25519 种子对 (event_ts + plain_token) 签名，
 *   只有持有 AppSecret 的站点能通过机器人后台的回调地址验证；
 * - 事件 ID 去重：QQ 平台可能重试推送，重复事件直接 ACK；
 * - 业务守卫：指令只在「已绑定群」内生效，绑定本身需网站管理中心回填验证码。
 *
 * 机器人后台「消息推送」配置：
 *   URL 填  战队管理中心「QQ机器人」那一页给出的完整地址
 *           （https://你的域名/api/bot/callback/{32位密钥}）
 *   事件勾选 群聊 @机器人（GROUP_AT_MESSAGE_CREATE）
 */
class BotCallback
{
    /**
     * 带密钥的回调处理（战队机器人专用）
     * @param Request $request
     * @param string $key 回调密钥
     */
    public function callbackWithKey(Request $request, string $key)
    {
        $payload = json_decode((string)$request->getContent(), true);
        if (!is_array($payload)) {
            return json(['msg' => 'invalid payload'], 400);
        }
        $op = (int)($payload['op'] ?? -1);

        // ---- op 13：Webhook 配置验证（机器人后台保存回调地址时发起） ----
        if ($op === 13) {
            $plain  = (string)($payload['d']['plain_token'] ?? '');
            // 官方算法：待签名串 = Payload.d.event_ts + Payload.d.plain_token
            $ts     = (string)($payload['d']['event_ts'] ?? '');

            // 根据密钥查找战队机器人配置。
            // 注意：此处不要求 enabled=1——配置回调地址的自然顺序是
            // 「先保存 AppID/AppSecret 拿到回调地址 → 再去 QQ 后台配置 Webhook（触发本验证）」，
            // 验证时战队可能尚未打开启用开关；签名本身即凭据持有证明，不构成安全风险。
            $botConfig = Db::name('bot_tenant_bots')
                ->where('callback_key', $key)
                ->find();

            // v5.28.0：只有战队独立机器人的密钥能应答 URL 校验（平台公共机器人已下线）
            $secret = $botConfig ? trim((string)$botConfig['app_secret']) : '';

            if ($secret === '') {
                QqBot::debugLog(['type' => 'verify_fail', 'key' => substr($key, 0, 8), 'reason' => 'bot not found or no secret']);
                return json(['msg' => 'bot not configured'], 500);
            }
            $sig    = $this->sign($secret, $ts . $plain);
            if ($sig === '' || $plain === '') {
                QqBot::debugLog(['type' => 'verify_fail', 'key' => $key, 'has_secret' => $secret !== '']);
                return json(['msg' => 'bot not configured'], 500);
            }
            return json(['plain_token' => $plain, 'signature' => $sig])
                ->contentType('application/json');
        }

        // ---- op 0：事件推送 ----
        if ($op === 0) {
            QqBot::debugLog(['type' => 'event', 't' => $payload['t'] ?? '', 'd' => $payload['d'] ?? null, 'key' => $key]);

            $d       = (array)($payload['d'] ?? []);
            $type    = (string)($payload['t'] ?? '');
            $eventId = (string)($d['id'] ?? '');

            // 事件去重（平台重试推送时不重复处理）
            if ($eventId !== '') {
                if (Cache::get('bot_evt_' . $eventId)) {
                    return json(['op' => 12]);
                }
                Cache::set('bot_evt_' . $eventId, 1, 600);
            }

            // v5.15.0(S-H3)：事件必须来自已登记的密钥；v5.28.0 起这份密钥只有战队独立机器人的
            $botConfig = Db::name('bot_tenant_bots')->where('callback_key', $key)->find();
            if (!$botConfig) {
                QqBot::debugLog(['type' => 'event_rejected', 'reason' => 'unknown_key', 'key' => substr($key, 0, 8)]);
                return json(['msg' => 'unknown callback key'], 403);
            }

            // 纵深防御（不是鉴权）：官方推送会带 X-Bot-Appid，本侧配置里也有 AppID 时二者须一致
            $wantAppid = trim((string)$botConfig['app_id']);
            $gotAppid  = trim((string)$request->header('X-Bot-Appid', ''));
            if ($wantAppid !== '' && $gotAppid !== '' && $gotAppid !== $wantAppid) {
                QqBot::debugLog(['type' => 'event_rejected', 'reason' => 'appid_mismatch', 'key' => substr($key, 0, 8)]);
                return json(['msg' => 'app id mismatch'], 403);
            }

            $isAt   = $type === 'GROUP_AT_MESSAGE_CREATE';
            $isFull = $type === 'GROUP_MESSAGE_CREATE';
            // v5.36.17：不同版本推的 bot 标记可能是 bool / 0/1 / "true"/"false"，
            // 之前用 empty() 判断，遇到字符串 "false" 会被当成机器人自己的消息整条丢掉（群里完全静默）。
            $fromBot = filter_var($d['author']['bot'] ?? false, FILTER_VALIDATE_BOOLEAN);
            if ($isAt || $isFull) {
                // 字段名防御：不同版本文档中群/成员 openid 命名略有差异，两者都接
                $group   = (string)($d['group_id'] ?? $d['group_openid'] ?? '');
                $author  = (string)($d['author']['member_openid'] ?? $d['author']['id'] ?? '');
                $content = (string)($d['content'] ?? '');
                if ($fromBot) {
                    QqBot::debugLog(['type' => 'event_skip', 'reason' => 'from_bot', 't' => $type,
                        'bot_raw' => $d['author']['bot'] ?? null, 'key' => substr($key, 0, 8)]);
                } elseif ($group === '' || trim($content) === '') {
                    QqBot::debugLog(['type' => 'event_skip', 'reason' => 'empty_field', 't' => $type,
                        'has_group' => $group !== '', 'content_len' => mb_strlen($content), 'key' => substr($key, 0, 8)]);
                } else {
                    try {
                        if ($isAt) {
                            self::handleAt($group, $author, $content, $key, $eventId);
                        } else {
                            self::handleFull($group, $author, $content, $key, $eventId,
                                self::mentionsBot($d), !empty($d['mentions']));
                        }
                    } catch (\Throwable $e) {
                        QqBot::debugLog(['type' => 'cmd_error', 'error' => $e->getMessage(), 'event' => $eventId, 'key' => substr($key, 0, 8)]);
                    }
                }
            }
            return json(['op' => 12]);
        }

        return json(['op' => 12]);
    }

    /** @群消息：先走指令；没命中指令再交给 AI 纯聊天 */
    private static function handleAt(string $group, string $author, string $content, string $key, string $eventId, string $source = 'at'): void
    {
        // 同一句话可能同时以「@事件」和「全量事件」推两次；不同类型只处理一次，
        // 同一种类型仍照常处理（用户自己重复发同一条指令不会被吞）
        $dedupe = 'bot_cmd_' . md5($group . '|' . $author . '|' . $content);
        $seen   = (string)Cache::get($dedupe, '');
        if ($seen !== '' && $seen !== $source) {
            return;
        }
        Cache::set($dedupe, $source, 60);

        $r = BotCommand::handleWithBotEx($group, $author, $content, $key);
        if (!$r['accepted']) {
            return;
        }

        // 命中指令 / 未绑定群 / 站点停用：照旧立即被动回复
        if ($r['cmd'] !== '(未知)' && $r['reply'] !== '') {
            self::reply($group, $r['reply'], $key, $eventId);
            if ($r['bound'] && $r['active']) {
                AiChat::runAfterResponse();
            }
            return;
        }

        if ($r['cmd'] === '(未知)' && $r['bound'] && $r['active']) {
            $ai = AiChat::offerAt((int)$r['tenant_id'], $group, $author, $eventId, $r['text']);
            // v5.36.25：这次到底走了指令还是 AI、AI 为什么没回，都落一条日志（自检页「最近报错」能直接看到）
            QqBot::debugLog([
                'type'    => 'ai_offer',
                'off'     => !empty($ai['off']) ? 1 : 0,
                'queued'  => !empty($ai['queued']) ? 1 : 0,
                'reply'   => mb_substr((string)($ai['reply'] ?? ''), 0, 60),
                'len'     => mb_strlen((string)$r['text']),
                'key'     => substr($key, 0, 8),
            ]);
            if (!empty($ai['off'])) {
                // AI 确实没开：说清楚「这句不是指令 / 去哪开」，同时给出最常用的指令名
                self::reply($group, "这句我没当成指令，也没法当聊天回你——本队 AI 聊天没开：管理中心 → AI 聊天 → 选模型并打开。\n"
                    . '指令可以直接发：「帮助」「签到」「地图」「枪码」「日程」。', $key, $eventId);
            } elseif (!empty($ai['queued'])) {
                AiChat::runAfterResponse();
            } elseif (($ai['reply'] ?? '') !== '') {
                self::reply($group, (string)$ai['reply'], $key, $eventId);
            } else {
                self::reply($group, $r['reply'], $key, $eventId);
            }
        }
    }

    /** 全量事件里是否 @ 了机器人（官方 content 已去掉 @ 前缀，只能看 mentions） */
    private static function mentionsBot(array $d): bool
    {
        foreach ((array)($d['mentions'] ?? []) as $m) {
            if (is_array($m) && filter_var($m['bot'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                return true;
            }
        }
        return false;
    }

    /**
     * 回复优先走被动（引用原消息，不占主动额度）；
     * 被动被 QQ 拒（常见：msg_id 过期 / 已回复次数用完）时退一次主动发送，
     * 否则群里就完全静默——v5.36.16 之前指令不做这个回退。
     */
    private static function reply(string $group, string $text, string $key, string $msgId): void
    {
        if (QqBot::sendGroupTextWithBot($group, $text, $key, $msgId)) {
            return;
        }
        if ($msgId !== '') {
            QqBot::sendGroupTextWithBot($group, $text, $key, '');
        }
    }

    /** 群全量消息：@ 到机器人的按 @ 处理（有的机器人只推全量、不推 @ 事件），其余只做 AI 自动接话 */
    private static function handleFull(string $group, string $author, string $content, string $key, string $eventId, bool $mentioned = false, bool $hasMentions = false): void
    {
        // 官方文档写明 content 已去掉 @机器人前缀，所以不能靠正文判断；
        // 全量事件里的 mentions 若出现 bot=true 的用户，才是真的 @ 了机器人。
        // v5.36.28：正文里带 '<@!' 的兜底只在**完全没给 mentions** 的老推送里用，
        // 否则「@张三 签到」也会被当成 @机器人，替别人执行指令。
        if ($mentioned || (!$hasMentions && str_starts_with(ltrim($content), '<@!'))) {
            self::handleAt($group, $author, $content, $key, $eventId, 'full');
            return;
        }
        $scope = BotCommand::botScope($group, $key);
        if (!$scope['accepted'] || !$scope['bound'] || !$scope['active']) {
            return;
        }
        $text = BotCommand::clean($content);
        if ($text === '') {
            return;
        }
        // 同一条消息先到的是 @ 事件时，全量事件别再入一次 AI 队列（会双调模型、双扣 Token、回两条）
        if ((string)Cache::get('bot_cmd_' . md5($group . '|' . $author . '|' . $content), '') !== '') {
            return;
        }
        AiChat::offerAuto((int)$scope['tenant_id'], $group, $author, $eventId, $text);
        AiChat::runAfterResponse();
    }

    /**
     * Webhook 验证签名：ed25519（种子 = AppSecret，消息 = event_ts + plain_token）
     * 依赖 PHP sodium 扩展（PHP 7.2+ 内置）。
     * 种子派生与官方 Golang 示例一致：不足 32 字节时反复加倍，再截取前 32 字节。
     */
    private function sign(string $secret, string $message): string
    {
        if ($secret === '' || !function_exists('sodium_crypto_sign_seed_keypair')) {
            return '';
        }
        $seed = $secret;
        while (strlen($seed) < SODIUM_CRYPTO_SIGN_SEEDBYTES) {
            $seed = str_repeat($seed, 2);
        }
        $seed = substr($seed, 0, SODIUM_CRYPTO_SIGN_SEEDBYTES);
        $kp = sodium_crypto_sign_seed_keypair($seed);
        return bin2hex(sodium_crypto_sign_detached($message, sodium_crypto_sign_secretkey($kp)));
    }
}
