<?php
declare (strict_types = 1);

namespace app\service;

use think\facade\Cache;
use think\facade\Db;

/**
 * QQ 官方机器人 API v2 客户端（v5.8.0）
 *
 * v5.28.0：平台公共机器人已下线——凭据只有各战队自己填的那一份（bot_tenant_bots 一行/战队），
 * 接口环境（正式/沙箱）也跟着搬到战队自己那一行，平台侧只剩出站敏感词词库与调用日志。
 *
 * 战队机器人凭据（bot_tenant_bots）：
 *   app_id / app_secret / env(prod|sandbox) / enabled(0|1) / callback_key / panel_id
 *
 * 能力：
 * - Access Token 按凭据缓存复用（到期前 5 分钟自动换新）
 * - 群文本消息发送：带 msg_id 为被动回复（引用触发事件，不占主动额度）；
 *   不带为主动发送（绑定成功通知等低频场景）
 * - 出站文本统一过 BotText 敏感词屏蔽，避免整条消息被风控吞掉
 * - 本地调试模式：AppID 填 'mock' 时不外呼，发送内容落日志（联调用）
 */
class QqBot
{
    // 官方 2026-08-10 起接口域名统一为 api.bot.qq.com（原 bots.qq.com / api.sgroup.qq.com 已迁移）。
    // 新旧域名都保留：优先新域名，连接级失败时自动回退旧域名（过渡期双保险）。
    private const TOKEN_URLS = [
        'https://api.bot.qq.com/app/getAppAccessToken',
        'https://bots.qq.com/app/getAppAccessToken',
    ];

    /**
     * 凭据配置（战队机器人）
     * @param array $botConfig bot_tenant_bots 那一行
     * @return array {app_id, app_secret, env, enabled}
     */
    public static function configWithBot(array $botConfig): array
    {
        return [
            'app_id'     => trim((string)($botConfig['app_id'] ?? '')),
            'app_secret' => trim((string)($botConfig['app_secret'] ?? '')),
            'env'        => strtolower(trim((string)($botConfig['env'] ?? ''))) === 'sandbox' ? 'sandbox' : 'prod',
            'enabled'    => (int)($botConfig['enabled'] ?? 0) === 1,
        ];
    }

    /**
     * 战队独立机器人配置（已启用 + 凭据齐全），满足条件返回配置行，否则返回 null。
     * 各处（管理端/个人中心/回调）统一用本方法判定。
     * v5.28.0：不再看平台授权白名单，也不看 platform_disabled——所有战队都能用自己的机器人。
     */
    public static function ownBot(int $tid): ?array
    {
        $row = Db::name('bot_tenant_bots')
            ->where('tenant_id', $tid)
            ->where('enabled', 1)
            ->find();
        if ($row && trim((string)$row['app_id']) !== '' && trim((string)$row['app_secret']) !== '') {
            return $row;
        }
        return null;
    }

    /**
     * API 基础域名：统一域名 api.bot.qq.com 优先，连接级失败时回退旧域名。
     * BOT_API_URL 环境变量可整体覆盖（本地联调桩服务器用）。
     */
    public static function apiBase(string $env = 'prod'): string
    {
        return self::bases($env)[0];
    }

    /**
     * 发消息要用的域名顺序：新域名优先，上一次成功的排最前（只是优先，不是只用）。
     * v5.36.24：以前「连接失败就把旧域名锁 24 小时」，旧域名哪天解析不动了，机器人会静默一整天。
     */
    private static function bases(string $env = 'prod'): array
    {
        $ov = \think\facade\Env::get('BOT_API_URL', '');
        if ($ov !== '') {
            return [rtrim((string)$ov, '/')];
        }
        $new  = 'https://api.bot.qq.com';
        $old  = self::legacyBase($env);
        $last = (string)Cache::get('qqbot_base_ok', '');
        return $last === $old ? [$old, $new] : [$new, $old];
    }

    /** 旧域名（2026-08 域名统一前）：正式 / 沙箱 */
    private static function legacyBase(string $env = 'prod'): string
    {
        return $env === 'sandbox'
            ? 'https://sandbox.api.sgroup.qq.com'
            : 'https://api.sgroup.qq.com';
    }

    /** 最近一次出站实际连到的 IP（写进日志，排障用） */
    private static string $lastIp = '';

    public static function lastIp(): string
    {
        return self::$lastIp;
    }

    /**
     * 主机名 → IPv4，失败返回 ''。
     * 顺序：缓存 → 系统解析（gethostbynamel / dns_get_record）→ 公共 DNS（IP 直连，绕开本机 DNS）。
     * 有些主机 resolv.conf 解析 QQ 接口域名会超时，curl 等不到就报
     * 「Resolving timed out after 4007 milliseconds」，整条回复发不出去；
     * 自己解析出来再用 CURLOPT_RESOLVE 钉住，curl 就不再查 DNS。
     */
    public static function resolveHost(string $host, bool $fresh = false): string
    {
        if ($host === '' || filter_var($host, FILTER_VALIDATE_IP)) {
            return '';
        }
        $ck = 'qqbot_dns_' . md5($host);
        if (!$fresh) {
            $hit = (string)Cache::get($ck, '');
            if ($hit !== '') {
                return $hit;
            }
        }
        $ip = '';
        $list = @gethostbynamel($host);
        if (is_array($list)) {
            foreach ($list as $cand) {
                if (filter_var($cand, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    $ip = $cand;
                    break;
                }
            }
        }
        if ($ip === '') {
            try {
                foreach ((array)@dns_get_record($host, DNS_A) as $rec) {
                    if (!empty($rec['ip']) && filter_var($rec['ip'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                        $ip = (string)$rec['ip'];
                        break;
                    }
                }
            } catch (\Throwable $e) {
            }
        }
        if ($ip === '') {
            $ip = self::dnsOverHttps($host);
        }
        Cache::set($ck, $ip, $ip !== '' ? 300 : 30);
        return $ip;
    }

    /**
     * 本机 DNS 坏掉时的兜底：用 IP 直连的公共 DNS 查一次 A 记录。
     * 这里关掉证书校验只是为了让 IP 形式的 DoH 地址能访问；真正连 QQ 时仍是完整 TLS 校验。
     */
    private static function dnsOverHttps(string $host): string
    {
        $urls = [
            'https://223.5.5.5/resolve?name=' . rawurlencode($host) . '&type=1',
            'https://120.53.53.53/resolve?name=' . rawurlencode($host) . '&type=1',
        ];
        foreach ($urls as $url) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 3,
                CURLOPT_CONNECTTIMEOUT => 2,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => 0,
                CURLOPT_HTTPHEADER     => ['Accept: application/dns-json'],
            ]);
            $raw = curl_exec($ch);
            curl_close($ch);
            if (!is_string($raw) || $raw === '') {
                continue;
            }
            $j = json_decode($raw, true);
            foreach ((array)($j['Answer'] ?? []) as $ans) {
                if ((int)($ans['type'] ?? 0) === 1 && filter_var($ans['data'] ?? '', FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    return (string)$ans['data'];
                }
            }
        }
        return '';
    }

    /** 最近一次 token 失败的上游详情（供连接测试回显，便于排障） */
    private static string $lastError = '';

    public static function lastError(): string
    {
        return self::$lastError;
    }

    /**
     * 发送群文本消息（战队机器人）
     * $msgId 非空 = 被动回复（引用触发事件，不占主动额度）；空 = 主动发送
     *
     * @param string $groupOpenid 群 openid
     * @param string $text        消息内容
     * @param string $key         回调密钥（定位是哪个战队的机器人）
     * @param string $msgId       触发事件的消息 ID（被动回复用）
     */
    public static function sendGroupTextWithBot(string $groupOpenid, string $text, string $key, string $msgId = ''): bool
    {
        if ($groupOpenid === '' || $key === '') {
            return false;
        }
        $botConfig = Db::name('bot_tenant_bots')->where('callback_key', $key)->find();
        if (!$botConfig) {
            return false;
        }
        $c = self::configWithBot($botConfig);
        if (!$c['enabled'] || $c['app_id'] === '' || $c['app_secret'] === '') {
            return false;
        }

        $text = BotText::filter($text);
        if ($text === '') {
            return false;
        }

        if ($c['app_id'] === 'mock') {
            self::debugLog(['type' => 'mock_send', 'group' => $groupOpenid, 'text' => $text, 'msg_id' => $msgId, 'key' => $key]);
            return true;
        }

        $token = self::tokenWithBot($botConfig);
        if ($token === '') {
            return false;
        }

        $body = ['content' => $text, 'msg_type' => 0];
        if ($msgId !== '') {
            $body['msg_id'] = $msgId;
        } else {
            $body['msg_seq'] = self::nextSeq($key);
        }

        $path  = '/v2/groups/' . rawurlencode($groupOpenid) . '/messages';
        $bases = self::bases($c['env']);
        $dnsRetried = false;
        $ok = false;
        // 逐个域名试：连接级失败（HTTP 0，多半是域名解析不动）时换域名；
        // 并对同一域名用「新鲜解析结果」再试一次（缓存里的旧 IP / 失败结果不算数）
        for ($i = 0; $i < count($bases); $i++) {
            $base = $bases[$i];
            [$ok, $code, $resp] = self::http('POST', $base . $path, $body, $token);
            if ($ok) {
                Cache::set('qqbot_base_ok', $base, 600);
                break;
            }
            self::debugLog(['type' => 'send_fail', 'http' => $code, 'resp' => $resp,
                            'base' => $base, 'ip' => self::lastIp(), 'group' => $groupOpenid, 'key' => $key]);
            // 有 HTTP 响应说明域名可达（是业务/鉴权错误），不再换域名重试
            if ($code > 0) {
                break;
            }
            if (!$dnsRetried) {
                $dnsRetried = true;
                $host = (string)(parse_url($base, PHP_URL_HOST) ?: '');
                if ($host !== '') {
                    self::resolveHost($host, true);
                    $bases[] = $base;   // 同一域名带新解析再试一次
                }
            }
        }
        return $ok;
    }

    /** 主动消息 msg_seq：按机器人各自每日自增（官方要求用于主动消息排序/频控） */
    private static function nextSeq(string $key): int
    {
        $cacheKey = 'qqbot_seq_' . $key . '_' . date('Ymd');
        $n = ((int)Cache::get($cacheKey) + 1) % 100000;
        Cache::set($cacheKey, $n, 90000);
        return $n;
    }

    // ==================== 富媒体（表情包，v5.36.29） ====================
    // QQ 官方发图分两步：先把文件传上去拿 file_info，再发一条 msg_type=7 的消息引用它。
    // 用 file_data(base64) 而不是 url：站点不一定对 QQ 的公网回源可达，本机 DNS 又出过问题。

    /** 单张表情包上限（与 AiLibrary::MAX_STICKER_BYTES 对齐；再大 base64 容易整条失败） */
    private const MEDIA_MAX_BYTES = 2 * 1024 * 1024;

    /** 上传超时放宽：2MB base64 走慢速上行 12 秒不够 */
    private const MEDIA_TIMEOUT = 40;

    /**
     * 发送一张本地图片（上传 + 发消息）
     * @return array{ok:bool,error:string,stage:string,ms:int}
     */
    public static function sendGroupImageWithBot(string $groupOpenid, string $absPath, string $key, string $msgId = ''): array
    {
        $start = microtime(true);
        $fail = static function (string $msg, string $stage) use ($start): array {
            return ['ok' => false, 'error' => $msg, 'stage' => $stage,
                'ms' => (int)round((microtime(true) - $start) * 1000)];
        };
        $done = static function () use ($start): array {
            return ['ok' => true, 'error' => '', 'stage' => 'send',
                'ms' => (int)round((microtime(true) - $start) * 1000)];
        };

        if ($groupOpenid === '' || $key === '' || $absPath === '') {
            return $fail('参数不全', 'pick');
        }
        $botConfig = Db::name('bot_tenant_bots')->where('callback_key', $key)->find();
        if (!$botConfig) {
            return $fail('机器人不存在', 'pick');
        }
        if (!is_file($absPath)) {
            return $fail('图片文件不在', 'pick');
        }
        $size = (int)@filesize($absPath);
        if ($size <= 0 || $size > self::MEDIA_MAX_BYTES) {
            return $fail('图片大小不合适（上限 2MB）', 'pick');
        }

        $c = self::configWithBot($botConfig);
        if ($c['app_id'] === 'mock') {
            self::debugLog(['type' => 'mock_media', 'group' => $groupOpenid,
                'file' => basename($absPath), 'bytes' => $size, 'msg_id' => $msgId, 'key' => $key]);
            return $done();
        }
        if (!$c['enabled'] || $c['app_id'] === '' || $c['app_secret'] === '') {
            return $fail('机器人未启用', 'pick');
        }
        $token = self::tokenWithBot($botConfig);
        if ($token === '') {
            return $fail('取不到机器人 Token', 'upload');
        }

        $raw = @file_get_contents($absPath);
        if (!is_string($raw) || $raw === '') {
            return $fail('读不到图片内容', 'pick');
        }
        [$ok, $code, $resp] = self::groupRequest(
            'POST',
            '/v2/groups/' . rawurlencode($groupOpenid) . '/files',
            ['file_type' => 1, 'file_data' => base64_encode($raw), 'srv_send_msg' => false],
            $token, $c['env'], self::MEDIA_TIMEOUT
        );
        if (!$ok) {
            self::debugLog(['type' => 'ai_sticker_upload_fail', 'http' => $code, 'resp' => $resp,
                'base' => self::lastIp(), 'key' => $key]);
            return $fail(self::errMsg($code, $resp), 'upload');
        }
        $fileInfo = (string)($resp['file_info'] ?? $resp['files'][0]['file_info'] ?? '');
        if ($fileInfo === '') {
            self::debugLog(['type' => 'ai_sticker_upload_fail', 'why' => 'no file_info', 'resp' => $resp, 'key' => $key]);
            return $fail('上传成功但上游没返回 file_info', 'upload');
        }

        $body = ['content' => '', 'msg_type' => 7, 'media' => ['file_info' => $fileInfo]];
        if ($msgId !== '') {
            // 关键：同一条群消息的第二次回复必须递增 msg_seq，否则 QQ 按 (msg_id, msg_seq) 去重，
            // 表现就是「文字发出去了、图没出现」，而且接口不一定报错。
            $body['msg_id']  = $msgId;
            $body['msg_seq'] = self::nextMsgSeq($key, $msgId);
        } else {
            $body['msg_seq'] = self::nextSeq($key);
        }
        [$ok2, $code2, $resp2] = self::groupRequest(
            'POST',
            '/v2/groups/' . rawurlencode($groupOpenid) . '/messages',
            $body, $token, $c['env'], 15
        );
        if (!$ok2 && $msgId !== '') {
            // 被动窗口过了 / msg_id 失效：退回主动发送（主动额度不足会由 QQ 侧拒绝）
            unset($body['msg_id']);
            $body['msg_seq'] = self::nextSeq($key);
            [$ok3, $code3, $resp3] = self::groupRequest(
                'POST',
                '/v2/groups/' . rawurlencode($groupOpenid) . '/messages',
                $body, $token, $c['env'], 15
            );
            if ($ok3) {
                return $done();
            }
            self::debugLog(['type' => 'ai_sticker_send_fail', 'http' => $code3, 'resp' => $resp3, 'key' => $key]);
            return $fail(self::errMsg($code3, $resp3), 'send');
        }
        if (!$ok2) {
            self::debugLog(['type' => 'ai_sticker_send_fail', 'http' => $code2, 'resp' => $resp2, 'key' => $key]);
            return $fail(self::errMsg($code2, $resp2), 'send');
        }
        return $done();
    }

    /** 被动回复序号：QQ 按 (msg_id, msg_seq) 去重，第二条起必须递增；文字那条已占用 1 */
    private static function nextMsgSeq(string $key, string $msgId): int
    {
        $ck = 'qqbot_mseq_' . md5($key . '|' . $msgId);
        $n  = max(2, (int)Cache::get($ck, 1) + 1);
        Cache::set($ck, $n, 600);
        return $n;
    }

    /**
     * 群接口请求（域名回退 + DNS 钉 IP 重试），只给富媒体用。
     * 文本发送仍走 sendGroupTextWithBot 里那段老逻辑，不动它能少一次回归风险。
     */
    private static function groupRequest(string $method, string $path, array $body, string $token, string $env, int $timeout): array
    {
        $bases = self::bases($env);
        $dnsRetried = false;
        $last = [false, 0, []];
        for ($i = 0; $i < count($bases); $i++) {
            $base = $bases[$i];
            [$ok, $code, $resp] = self::http($method, $base . $path, $body, $token, $timeout);
            if ($ok) {
                Cache::set('qqbot_base_ok', $base, 600);
                return [$ok, $code, $resp];
            }
            $last = [$ok, $code, $resp];
            if ($code > 0) {
                break;
            }
            if (!$dnsRetried) {
                $dnsRetried = true;
                $host = (string)(parse_url($base, PHP_URL_HOST) ?: '');
                if ($host !== '') {
                    self::resolveHost($host, true);
                    $bases[] = $base;
                }
            }
        }
        return $last;
    }

    /**
     * Access Token（按凭据缓存；失败返回 ''）
     * POST …/app/getAppAccessToken {"appId": "…", "clientSecret": "…"}
     * 注意：官方字段名是驼峰 appId / clientSecret（曾误用 appid/client_secret 导致"核对凭据"）
     */
    public static function tokenWithBot(array $botConfig): string
    {
        $c = self::configWithBot($botConfig);
        if ($c['app_id'] === '' || $c['app_secret'] === '') {
            return '';
        }
        if ($c['app_id'] === 'mock') {
            return 'mock-token';
        }

        $cacheKey = 'qqbot_token_' . md5($c['app_id'] . $c['app_secret']);
        $hit = Cache::get($cacheKey);
        if (is_string($hit) && $hit !== '') {
            return $hit;
        }

        $ov = \think\facade\Env::get('BOT_TOKEN_URL', '');
        $urls = $ov !== '' ? [rtrim((string)$ov, '/')] : self::TOKEN_URLS;
        $last = [0, ['error' => 'no attempt']];
        foreach ($urls as $url) {
            [$ok, $code, $body] = self::http('POST', $url, [
                'appId'        => $c['app_id'],
                'clientSecret' => $c['app_secret'],
            ], '');
            if ($ok) {
                $token = (string)($body['access_token'] ?? '');
                if ($token !== '') {
                    $ttl = max(60, (int)($body['expires_in'] ?? 7200) - 300);
                    Cache::set($cacheKey, $token, $ttl);
                    return $token;
                }
            }
            $last = [$code, $body];
            // HTTP 层有响应（如 401）说明域名可达、是凭据问题，没必要再试别的域名
            if ($code > 0) {
                break;
            }
        }
        self::$lastError = 'HTTP ' . $last[0] . ' ' . json_encode($last[1], JSON_UNESCAPED_UNICODE);
        self::debugLog(['type' => 'token_fail', 'http' => $last[0], 'resp' => $last[1], 'bot' => $c['app_id']]);
        return '';
    }

    /**
     * curl JSON 请求封装：返回 [是否成功, HTTP状态码, 解码后的数组]
     *
     * v5.36.4：空数组不再发 `[]` body。QQ 的 DELETE /v2/panels/{id} 只接受空包体，
     * 之前统一发 `[]` + Content-Type: application/json，会被判成「请求数据异常」（40011000）。
     * 只有真有字段时才带 Content-Type 和 POSTFIELDS。
     */
    private static function http(string $method, string $url, array $body, string $token, int $timeout = 12): array
    {
        $hasBody = $body !== [];
        $headers = [];
        if ($hasBody) {
            $headers[] = 'Content-Type: application/json';
        }
        $headers[] = 'User-Agent: game-team-bot/5.8';
        if ($token !== '') {
            // 官方格式：Authorization: QQBot {ACCESS_TOKEN}
            $headers[] = 'Authorization: QQBot ' . $token;
        }
        $ch = curl_init($url);
        $host   = (string)(parse_url($url, PHP_URL_HOST) ?: '');
        $scheme = strtolower((string)(parse_url($url, PHP_URL_SCHEME) ?: 'https'));
        $port   = (int)(parse_url($url, PHP_URL_PORT) ?: ($scheme === 'https' ? 443 : 80));
        $opts = [
            CURLOPT_CUSTOMREQUEST     => $method,
            CURLOPT_RETURNTRANSFER    => true,
            CURLOPT_TIMEOUT           => $timeout,
            CURLOPT_CONNECTTIMEOUT    => 6,
            CURLOPT_DNS_CACHE_TIMEOUT => 120,
            CURLOPT_SSL_VERIFYPEER    => true,
            CURLOPT_SSL_VERIFYHOST    => 2,
            CURLOPT_HTTPHEADER        => $headers,
        ];
        self::$lastIp = '';
        if ($host !== '' && !filter_var($host, FILTER_VALIDATE_IP)) {
            $ip = self::resolveHost($host);
            if ($ip !== '') {
                // 钉住解析结果：curl 不再自己查 DNS（本机 DNS 坏掉时这是唯一能发出去的办法）
                $opts[CURLOPT_RESOLVE] = [$host . ':' . $port . ':' . $ip];
                self::$lastIp = $ip;
            }
        }
        if ($hasBody) {
            $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE);
        }
        curl_setopt_array($ch, $opts);
        $raw  = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($raw === false) {
            return [false, 0, ['error' => $err]];
        }
        $json = json_decode((string)$raw, true);
        return [$code >= 200 && $code < 300, $code, is_array($json) ? $json : ['raw' => (string)$raw]];
    }

    /**
     * 运行日志（runtime/bot/bot_日期.log，单文件超 1MB 轮转）
     * 记录：mock 发送 / 发送失败 / token 失败 / 指令异常 / 回调事件
     */
    public static function debugLog(array $data): void
    {
        try {
            $dir = runtime_path() . 'bot';
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
            $file = $dir . DIRECTORY_SEPARATOR . 'bot_' . date('Ymd') . '.log';
            if (is_file($file) && filesize($file) > 1048576) {
                @rename($file, $file . '.old');
            }
            $data['ts'] = date('Y-m-d H:i:s');
            @file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
        } catch (\Throwable $e) {
            // 日志失败不影响主流程
        }
    }

    // ==================== 指令面板（QQ 官方 /v2/panels） ====================
    // 面板里的 command 项点击后会把指令文本填入群输入框，方便成员发现指令。
    // 面板是「机器人级」资源（最多 20 个）：每个独立机器人维护自己那一份，
    // panel_id 存 bot_tenant_bots.panel_id。

    /** 面板元素（指令清单，与群指令一一对应；name ≤14 字符） */
    public static function panelItems(): array
    {
        return [
            ['type' => 'command', 'name' => '签到',     'desc' => '每日签到记连签'],
            ['type' => 'command', 'name' => '地图',     'desc' => '今日与后续轮换'],
            ['type' => 'command', 'name' => '改枪码',   'desc' => '队内已上架枪码'],
            ['type' => 'command', 'name' => '日程',     'desc' => '今日日程一览'],
            ['type' => 'command', 'name' => '训练赛',   'desc' => '报名中/进行中'],
            ['type' => 'command', 'name' => '撞车',     'desc' => '近期撞车时间'],
            ['type' => 'command', 'name' => '值班',     'desc' => '今日房主值班'],
            ['type' => 'command', 'name' => '我的战绩', 'desc' => '我上传的战绩'],
            ['type' => 'command', 'name' => '战绩榜',   'desc' => '队内战绩排名'],
            ['type' => 'command', 'name' => '成员',     'desc' => '成员与此刻在线'],
            ['type' => 'command', 'name' => '介绍',     'desc' => '战队简介与展示页'],
            ['type' => 'command', 'name' => '帮助',     'desc' => '查看指令菜单'],
        ];
    }

    /**
     * 同步本战队的指令面板：没有 panel_id 就建一个（并把已绑定群关联进去），
     * 已有就更新面板内容并补关联。返回 [是否成功, 说明文案, panel_id]。
     */
    public static function syncPanelWithBot(array $botConfig, array $groupOpenids): array
    {
        $groupOpenids = array_values(array_filter(array_unique($groupOpenids)));
        $panelId      = trim((string)($botConfig['panel_id'] ?? ''));

        if ($panelId === '') {
            $newId = self::panelCreateWithBot($botConfig, $groupOpenids);
            if ($newId === '') {
                return [false, '创建指令面板失败，请稍后再试（详情见运行日志）。', ''];
            }
            Db::name('bot_tenant_bots')->where('id', (int)$botConfig['id'])->update(['panel_id' => $newId]);
            return [true, '指令面板已创建（panel_id=' . $newId . '）', $newId];
        }

        $c = self::configWithBot($botConfig);
        if ($c['app_id'] === '') {
            return [false, '请先填写 AppID 与 AppSecret。', $panelId];
        }
        if ($c['app_id'] === 'mock') {
            self::debugLog(['type' => 'mock_api', 'method' => 'PUT', 'path' => '/v2/panels/' . $panelId, 'bot' => 'own']);
            return [true, '本地调试模式：指令面板按已登记内容算作更新', $panelId];
        }
        $token = self::tokenWithBot($botConfig);
        if ($token === '') {
            return [false, '更新指令面板失败：' . self::$lastError, $panelId];
        }
        [$ok, $code, $resp] = self::http('PUT', self::apiBase($c['env']) . '/v2/panels/' . rawurlencode($panelId),
            ['panel' => ['items' => self::panelItems(), 'remark' => '战队独立机器人指令面板']], $token);
        if (!$ok) {
            // 面板在 QQ 侧被删掉、但本地还留着 panel_id 时，PUT 会一直失败；
            // 这里把旧引用清掉并重建一个，别让「重新生成面板」永远报错。
            if (self::panelMissing($code, $resp)) {
                Db::name('bot_tenant_bots')->where('id', (int)$botConfig['id'])->update(['panel_id' => '']);
                $newId = self::panelCreateWithBot($botConfig, $groupOpenids);
                if ($newId !== '') {
                    Db::name('bot_tenant_bots')->where('id', (int)$botConfig['id'])->update(['panel_id' => $newId]);
                    self::debugLog(['type' => 'own_panel_recreate', 'old' => $panelId, 'new' => $newId]);
                    return [true, '旧指令面板在 QQ 侧已不存在，已重新创建（panel_id=' . $newId . '）', $newId];
                }
            }
            self::debugLog(['type' => 'own_panel_update_fail', 'panel' => $panelId, 'http' => $code, 'resp' => $resp]);
            return [false, '更新指令面板失败：' . self::errMsg($code, $resp), $panelId];
        }
        if ($groupOpenids !== []) {
            [$ok2, $err] = self::panelTargetWithBot($botConfig, $panelId, 'add', $groupOpenids);
            if (!$ok2) {
                self::debugLog(['type' => 'own_panel_target_fail', 'panel' => $panelId,
                    'groups' => $groupOpenids, 'err' => $err]);
                return [false, '面板已更新，但关联群失败：' . $err, $panelId];
            }
        }
        return [true, '指令面板已更新，关联 ' . count($groupOpenids) . ' 个群', $panelId];
    }

    /**
     * 创建指令面板（成功返回 panel_id，失败返回 ''）
     * 面板是机器人级资源，用该机器人自己的 Token 创建。
     */
    public static function panelCreateWithBot(array $botConfig, array $groupOpenids): string
    {
        $c = self::configWithBot($botConfig);
        if ($c['app_id'] === '' || $c['app_secret'] === '') {
            return '';
        }
        if ($c['app_id'] === 'mock') {
            self::debugLog(['type' => 'mock_api', 'method' => 'POST', 'path' => '/v2/panels', 'bot' => 'own']);
            return 'mock-panel-own';
        }
        $token = self::tokenWithBot($botConfig);
        if ($token === '') {
            return '';
        }
        [$ok, $code, $resp] = self::http('POST', self::apiBase($c['env']) . '/v2/panels', [
            'scope'         => 'group',
            'target_type'   => 'specific',
            'group_openids' => array_values(array_filter($groupOpenids)),
            'panel'         => ['items' => self::panelItems(), 'remark' => '战队独立机器人指令面板'],
        ], $token);
        if (!$ok || empty($resp['panel_id'])) {
            self::debugLog(['type' => 'own_panel_create_fail', 'http' => $code, 'resp' => $resp]);
            return '';
        }
        return (string)$resp['panel_id'];
    }

    /** 面板关联群（op=add/del） */
    public static function panelTargetWithBot(array $botConfig, string $panelId, string $op, array $groupOpenids): array
    {
        if ($panelId === '' || $groupOpenids === []) {
            return [true, ''];
        }
        $c = self::configWithBot($botConfig);
        if ($c['app_id'] === '') {
            return [false, '机器人凭据不完整'];
        }
        if ($c['app_id'] === 'mock') {
            self::debugLog(['type' => 'mock_api', 'method' => 'PUT', 'path' => '/v2/panels/' . $panelId . '/target', 'bot' => 'own']);
            return [true, ''];
        }
        $token = self::tokenWithBot($botConfig);
        if ($token === '') {
            return [false, '获取机器人 Token 失败'];
        }
        [$ok, $code, $resp] = self::http('PUT', self::apiBase($c['env']) . '/v2/panels/' . rawurlencode($panelId) . '/target', [
            'op'            => $op,
            'group_openids' => array_values($groupOpenids),
        ], $token);
        return [$ok, $ok ? '' : self::errMsg($code, $resp)];
    }

    /** 删除本战队的指令面板（同时清掉 panel_id 记录） */
    public static function panelDeleteWithBot(array $botConfig, string $panelId): array
    {
        if ($panelId === '') {
            return [true, ''];
        }
        $c = self::configWithBot($botConfig);
        if ($c['app_id'] === '') {
            return [false, '机器人凭据不完整'];
        }
        if ($c['app_id'] === 'mock') {
            self::debugLog(['type' => 'mock_api', 'method' => 'DELETE', 'path' => '/v2/panels/' . $panelId, 'bot' => 'own']);
            $ok = true; $code = 200; $resp = [];
        } else {
            $token = self::tokenWithBot($botConfig);
            if ($token === '') {
                return [false, '获取机器人 Token 失败'];
            }
            [$ok, $code, $resp] = self::http('DELETE', self::apiBase($c['env']) . '/v2/panels/' . rawurlencode($panelId), [], $token);
        }
        // QQ 侧已经不存在（40030006）也算删成功：否则本地 panel_id 永远清不掉，
        // 管理端会一直显示「已有面板」，再点删除还是同一条错误。
        if (!$ok && self::panelMissing($code, $resp)) {
            $ok = true;
            $code = 0;
            $resp = [];
        }
        if (!$ok) {
            self::debugLog(['type' => 'own_panel_delete_fail', 'panel' => $panelId, 'http' => $code, 'resp' => $resp]);
        }
        // 删成功就把本地引用清掉：否则管理端仍显示「已有面板」，再点删除会走一遍空删除
        if ($ok) {
            Db::name('bot_tenant_bots')->where('id', (int)($botConfig['id'] ?? 0))->update(['panel_id' => '']);
        }
        return [$ok, $ok ? '' : self::errMsg($code, $resp)];
    }

    /** QQ 侧返回「指令面板不存在」（删除/更新时都可能遇到） */
    private static function panelMissing(int $code, array $resp): bool
    {
        if ($code === 40030006 || (int)($resp['code'] ?? 0) === 40030006) {
            return true;
        }
        $msg = strtolower(trim((string)($resp['message'] ?? $resp['msg'] ?? $resp['error'] ?? '')));
        return $msg !== '' && (strpos($msg, '不存在') !== false || strpos($msg, 'not found') !== false);
    }

    /** 上游错误信息拼接（尽量把官方 message 透出，便于排障） */
    private static function errMsg(int $code, array $resp): string
    {
        $msg = (string)($resp['message'] ?? $resp['msg'] ?? $resp['error'] ?? '');
        $extra = isset($resp['code']) ? '（code=' . $resp['code'] . '）' : '';
        return ($msg !== '' ? $msg : '请求失败') . $extra . ($code > 0 ? ' [HTTP ' . $code . ']' : '');
    }
}
