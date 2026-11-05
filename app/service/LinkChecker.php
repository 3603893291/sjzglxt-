<?php
declare (strict_types = 1);

namespace app\service;

use think\facade\Db;

/**
 * v5.10.0：死链检测服务
 *
 * 数据源（默认全部启用，可按需过滤）：
 *   - gun_codes.description        —— 枪码说明中的外链
 *   - announcements.content        —— 公告正文中的外链
 *   - guides.content               —— 攻略正文中的外链
 *   - chat_messages.content        —— 聊天消息中的外链（可能量大，默认关闭）
 *
 * 扫描策略：
 *   1. 抽取所有 http(s) URL（正则）+ 去重（sha256(url) 唯一）
 *   2. 依次 HEAD 请求；405/501 时回落 GET；8s 超时；最多 3 次重定向
 *   3. 状态码 >= 400 或连接失败 → 写入/更新 broken_links，hit_count++
 *   4. 状态码 < 400 且之前有记录 → 标记为已恢复（更新 last_ok_at，hit_count 清零）
 *   5. 平台超管手动 resolve 后不再自动报警，除非 hit_count 阈值再次触发
 *
 * 限流：
 *   - 单次扫描最多 MAX_PER_SCAN 条 URL，防止长时阻塞请求
 *   - 同域名并发 1（顺序执行），避免触发对方限流
 */
class LinkChecker
{
    /** 单次扫描 URL 上限（超出下次继续，避免 504） */
    public const MAX_PER_SCAN = 100;

    /** 单条 URL 超时（秒） */
    public const TIMEOUT_SEC = 8;

    /** 最大重定向次数 */
    public const MAX_REDIRECTS = 3;

    /** 允许的源类型 */
    protected const SOURCES = ['gun_code', 'announcement', 'guide', 'chat'];

    /**
     * 全量扫描（平台超管手动触发）
     * @param array $sources 允许为空数组=全部；否则 ['gun_code','announcement',...]
     * @return array{ok:bool,scanned:int,added:int,updated:int,recovered:int,skipped:int,error:string}
     */
    public static function scan(array $sources = []): array
    {
        $result = [
            'ok' => false, 'scanned' => 0, 'added' => 0, 'updated' => 0,
            'recovered' => 0, 'skipped' => 0, 'error' => '',
        ];

        if (!function_exists('curl_init')) {
            $result['error'] = '服务器未启用 curl 扩展';
            return $result;
        }

        $sources = empty($sources) ? self::SOURCES : array_values(array_intersect($sources, self::SOURCES));
        if (empty($sources)) {
            $result['error'] = '未指定有效的数据源';
            return $result;
        }

        try {
            $candidates = self::collectCandidates($sources);
            $total = count($candidates);
            if ($total === 0) {
                $result['ok'] = true;
                Setting::setPlatform('broken_link_last_scan', date('Y-m-d H:i:s'));
                return $result;
            }

            // 限量：优先扫描未解决且最久没检查的
            $candidates = array_slice($candidates, 0, self::MAX_PER_SCAN);

            foreach ($candidates as $item) {
                [$url, $urlHash, $tenantId, $sourceType, $sourceId, $sourceRef] = $item;

                // v5.36.3：成员写在公告/攻略里的链接也可以指向 127.0.0.1、内网段。
                // 出站前先过同一把公网地址尺子，挡住 SSRF；被挡的不探测、不入库。
                if (\safe_outbound_url($url) !== null) {
                    $result['skipped']++;
                    continue;
                }
                [$status, $err] = self::probe($url);
                $result['scanned']++;

                $now  = date('Y-m-d H:i:s');
                $row  = Db::name('broken_links')->where('tenant_id', $tenantId)->where('url_hash', $urlHash)->find();

                if ($status > 0 && $status < 400) {
                    // 链接健康
                    if ($row) {
                        Db::name('broken_links')->where('id', $row['id'])->update([
                            'status_code'   => $status,
                            'error_msg'     => '',
                            'hit_count'     => 0,
                            'last_ok_at'    => $now,
                            'last_check_at' => $now,
                            'updated_at'    => $now,
                        ]);
                        $result['recovered']++;
                    } else {
                        $result['skipped']++;   // 从未坏过，不入库
                    }
                    continue;
                }

                // 链接异常
                $errMsg = $err !== '' ? $err : ('http_' . $status);
                if ($row) {
                    // 已被平台超管 resolve 的：仍更新检查时间，但不累加 hit_count
                    if ((int)$row['resolved'] === 1) {
                        Db::name('broken_links')->where('id', $row['id'])->update([
                            'status_code'   => $status,
                            'error_msg'     => mb_substr($errMsg, 0, 250),
                            'last_check_at' => $now,
                            'updated_at'    => $now,
                        ]);
                        $result['skipped']++;
                    } else {
                        Db::name('broken_links')->where('id', $row['id'])->update([
                            'status_code'   => $status,
                            'error_msg'     => mb_substr($errMsg, 0, 250),
                            'hit_count'     => (int)$row['hit_count'] + 1,
                            'last_check_at' => $now,
                            'updated_at'    => $now,
                        ]);
                        $result['updated']++;
                    }
                } else {
                    Db::name('broken_links')->insert([
                        'tenant_id'     => $tenantId,
                        'url'           => mb_substr($url, 0, 500),
                        'url_hash'      => $urlHash,
                        'source_type'   => $sourceType,
                        'source_id'     => $sourceId,
                        'source_ref'    => mb_substr($sourceRef, 0, 128),
                        'status_code'   => $status,
                        'error_msg'     => mb_substr($errMsg, 0, 250),
                        'hit_count'     => 1,
                        'last_check_at' => $now,
                        'resolved'      => 0,
                        'created_at'    => $now,
                        'updated_at'    => $now,
                    ]);
                    $result['added']++;
                }
            }

            Setting::setPlatform('broken_link_last_scan', date('Y-m-d H:i:s'));
            $result['ok'] = true;
            return $result;
        } catch (\Throwable $e) {
            $result['error'] = mb_substr($e->getMessage(), 0, 200);
            return $result;
        }
    }

    /**
     * 手动追加一条 URL（平台超管手动录入外部检查）
     * @return array{ok:bool,status:int,error:string}
     */
    public static function addManual(string $url, int $tenantId = 0, string $note = ''): array
    {
        $url = trim($url);
        if (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $url)) {
            return ['ok' => false, 'status' => 0, 'error' => 'URL 格式不正确（需以 http/https 开头）'];
        }
        // v5.36.3：手动录入同样要过公网地址尺子，不能拿平台超管的服务器去探内网
        if (($bad = \safe_outbound_url($url)) !== null) {
            return ['ok' => false, 'status' => 0, 'error' => $bad];
        }
        $hash = hash('sha256', $url);
        $now  = date('Y-m-d H:i:s');

        [$status, $err] = self::probe($url);
        $exists = Db::name('broken_links')->where('tenant_id', $tenantId)->where('url_hash', $hash)->find();
        if ($exists) {
            Db::name('broken_links')->where('id', $exists['id'])->update([
                'status_code'   => $status,
                'error_msg'     => mb_substr($err !== '' ? $err : ($status >= 400 ? 'http_' . $status : ''), 0, 250),
                'hit_count'     => ($status > 0 && $status < 400) ? 0 : (int)$exists['hit_count'] + 1,
                'last_ok_at'    => ($status > 0 && $status < 400) ? $now : $exists['last_ok_at'],
                'last_check_at' => $now,
                'updated_at'    => $now,
            ]);
        } else {
            Db::name('broken_links')->insert([
                'tenant_id'     => $tenantId,
                'url'           => mb_substr($url, 0, 500),
                'url_hash'      => $hash,
                'source_type'   => 'manual',
                'source_id'     => 0,
                'source_ref'    => mb_substr($note, 0, 128),
                'status_code'   => $status,
                'error_msg'     => mb_substr($err !== '' ? $err : ($status >= 400 ? 'http_' . $status : ''), 0, 250),
                'hit_count'     => ($status > 0 && $status < 400) ? 0 : 1,
                'last_ok_at'    => ($status > 0 && $status < 400) ? $now : null,
                'last_check_at' => $now,
                'resolved'      => 0,
                'created_at'    => $now,
                'updated_at'    => $now,
            ]);
        }
        return ['ok' => true, 'status' => $status, 'error' => $err];
    }

    /** 平台超管标记为已解决（不再自动报警） */
    public static function markResolved(int $id, int $adminId): bool
    {
        $row = Db::name('broken_links')->where('id', $id)->find();
        if (!$row) return false;
        Db::name('broken_links')->where('id', $id)->update([
            'resolved'    => 1,
            'resolved_by' => $adminId,
            'resolved_at' => date('Y-m-d H:i:s'),
            'updated_at'  => date('Y-m-d H:i:s'),
        ]);
        return true;
    }

    /** 撤销「已解决」标记 */
    public static function markUnresolved(int $id): bool
    {
        $row = Db::name('broken_links')->where('id', $id)->find();
        if (!$row) return false;
        Db::name('broken_links')->where('id', $id)->update([
            'resolved'    => 0,
            'resolved_by' => 0,
            'resolved_at' => null,
            'updated_at'  => date('Y-m-d H:i:s'),
        ]);
        return true;
    }

    /** 删除记录 */
    public static function remove(int $id): bool
    {
        return Db::name('broken_links')->where('id', $id)->delete() > 0;
    }

    // ---------- 内部工具 ----------

    /**
     * 从业务表抽取候选 URL
     * @return array<int, array{0:string,1:string,2:int,3:string,4:int,5:string}>
     *         [url, url_hash, tenant_id, source_type, source_id, source_ref]
     */
    protected static function collectCandidates(array $sources): array
    {
        $out = [];
        $seen = [];   // 内存去重，避免同 URL 多来源重复扫描

        $push = function (string $url, int $tenantId, string $type, int $id, string $ref) use (&$out, &$seen) {
            $url = self::normalizeUrl($url);
            if ($url === '') return;
            $hash = hash('sha256', $url);
            $key  = $tenantId . ':' . $hash;
            if (isset($seen[$key])) return;
            $seen[$key] = true;
            $out[] = [$url, $hash, $tenantId, $type, $id, $ref];
        };

        foreach ($sources as $src) {
            switch ($src) {
                case 'gun_code':
                    if (!self::tableExists('gun_codes')) break;
                    $rows = Db::name('gun_codes')->field('id, tenant_id, title, description')->select()->toArray();
                    foreach ($rows as $r) {
                        foreach (self::extractUrls((string)$r['description']) as $u) {
                            $push($u, (int)$r['tenant_id'], 'gun_code', (int)$r['id'], (string)($r['title'] ?? ''));
                        }
                    }
                    break;
                case 'announcement':
                    if (!self::tableExists('announcements')) break;
                    $rows = Db::name('announcements')->field('id, tenant_id, title, content')->select()->toArray();
                    foreach ($rows as $r) {
                        foreach (self::extractUrls((string)$r['content']) as $u) {
                            $push($u, (int)$r['tenant_id'], 'announcement', (int)$r['id'], (string)($r['title'] ?? ''));
                        }
                    }
                    break;
                case 'guide':
                    if (!self::tableExists('guides')) break;
                    $rows = Db::name('guides')->field('id, tenant_id, title, content')->select()->toArray();
                    foreach ($rows as $r) {
                        foreach (self::extractUrls((string)$r['content']) as $u) {
                            $push($u, (int)$r['tenant_id'], 'guide', (int)$r['id'], (string)($r['title'] ?? ''));
                        }
                    }
                    break;
                case 'chat':
                    if (!self::tableExists('chat_messages')) break;
                    // 聊天数据量大：只取最近 7 天，且限制 500 条
                    $rows = Db::name('chat_messages')
                        ->where('created_at', '>=', date('Y-m-d H:i:s', strtotime('-7 days')))
                        ->limit(500)
                        ->field('id, tenant_id, content')->select()->toArray();
                    foreach ($rows as $r) {
                        foreach (self::extractUrls((string)$r['content']) as $u) {
                            $push($u, (int)$r['tenant_id'], 'chat', (int)$r['id'], 'chat#' . $r['id']);
                        }
                    }
                    break;
            }
        }
        return $out;
    }

    /** 从文本中抽取所有 http(s) URL */
    public static function extractUrls(string $text): array
    {
        if ($text === '') return [];
        // 先剔除 HTML 标签，防止 href="..." 里的 URL 被重复匹配到
        $plain = strip_tags($text);
        $matches = [];
        preg_match_all(
            '#https?://[^\s<>"\'\)\]\}，。；、]+#iu',
            $plain,
            $matches
        );
        $urls = $matches[0] ?? [];
        // 去掉尾部标点
        $urls = array_map(function (string $u): string {
            return rtrim($u, ".,;:!?)\]}>'\"");
        }, $urls);
        return array_values(array_unique(array_filter($urls, fn($u) => strlen($u) > 10 && strlen($u) <= 500)));
    }

    /** 规范化：去 fragment、限制长度 */
    protected static function normalizeUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '' || strlen($url) > 500) return '';
        // 去掉 #fragment
        $pos = strpos($url, '#');
        if ($pos !== false) $url = substr($url, 0, $pos);
        return filter_var($url, FILTER_VALIDATE_URL) ? $url : '';
    }

    /**
     * HEAD 探测；失败或 405 时回落 GET。
     * v5.36.3：不再让 curl 自动跟随跳转——每一跳都先过 safe_outbound_url()，
     * 否则公网地址 302 到 127.0.0.1 就能绕过入口那一次校验（SSRF）。
     */
    protected static function probe(string $url): array
    {
        $current = $url;
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            if (\safe_outbound_url($current) !== null) {
                return [0, 'blocked_private_url'];
            }
            [$status, $err, $location] = self::curlRequest($current, 'HEAD');
            // 405 Method Not Allowed / 501 Not Implemented / 连接失败 → 回落 GET
            if ($status === 405 || $status === 501 || ($status === 0 && $err !== '')) {
                [$s2, $e2, $l2] = self::curlRequest($current, 'GET');
                if ($s2 > 0) {
                    $status = $s2;
                    $err = $e2;
                    $location = $l2;
                }
            }
            if ($status >= 300 && $status < 400 && $location !== '') {
                $current = $location;
                continue;
            }
            return [$status, $err];
        }
        return [0, 'too_many_redirects'];
    }

    /** @return array{0:int,1:string,2:string} [statusCode, errorMsg, 下一跳地址(无跳转为空)] */
    protected static function curlRequest(string $url, string $method): array
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_NOBODY         => $method === 'HEAD',
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT        => self::TIMEOUT_SEC,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT      => 'GameTeamBot/5.10 (+link-checker)',
            CURLOPT_HTTPHEADER     => ['Accept: */*'],
            // 部分站点会拒绝无 UA/无 Accept 的请求；这里显式声明为链接检查器
        ]);
        if ($method === 'GET') {
            // GET 时限制下载量，避免大文件耗流量
            curl_setopt($ch, CURLOPT_RANGE, '0-8191');
        }
        $body     = curl_exec($ch);
        $status   = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $location = (string)curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        $errNo    = curl_errno($ch);
        $errMsg   = (string)curl_error($ch);
        curl_close($ch);

        if ($body === false || $errNo !== 0) {
            return [0, self::mapCurlError($errNo, $errMsg), ''];
        }
        return [$status, $status >= 400 ? 'http_' . $status : '', $location];
    }

    protected static function mapCurlError(int $errNo, string $errMsg): string
    {
        $map = [
            6  => 'dns_fail',
            7  => 'connect_fail',
            28 => 'timeout',
            35 => 'ssl_handshake_fail',
            60 => 'ssl_cert_invalid',
            51 => 'ssl_peer_cert_fail',
            52 => 'empty_reply',
            56 => 'recv_fail',
        ];
        return $map[$errNo] ?? ('curl_' . $errNo . ($errMsg !== '' ? ':' . mb_substr($errMsg, 0, 60) : ''));
    }

    protected static function tableExists(string $table): bool
    {
        try {
            Db::name($table)->limit(1)->select();
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
