<?php
declare(strict_types=1);

namespace app\service;

/**
 * 赛事中心站客户端（v5.5.0）
 *
 * 主站代理租户/连接器代理外部战队调赛事站 /api/open 开放接口。
 * 配置：平台级 settings（tournament_site_url / tournament_site_key），仅平台超管可改。
 * Key 只存服务端，不出前端。
 *
 * 约定：方法返回 ['ok'=>bool, 'data'=>mixed, 'msg'=>string]
 */
class TournamentClient
{
    /** 本地缓存秒数（赛事列表，防高频打爆赛事站） */
    protected const CACHE_TTL = 5;

    /** 读取历史单站点连接配置（v5.5.0 兼容层；多站点请用 TournamentBinding::site()） */
    public static function config(): array
    {
        $url = rtrim(Setting::getPlatform('tournament_site_url'), '/');
        $key = Setting::getPlatform('tournament_site_key');
        return ['enabled' => $url !== '' && $key !== '', 'url' => $url, 'key' => $key];
    }

    /** 归一化站点数组；$site 为空则回退默认配置 */
    protected static function stance(?array $site): ?array
    {
        if ($site !== null && (isset($site['url']) || isset($site['base_url']))) {
            return $site;
        }
        $cfg = self::config();
        return $cfg['enabled'] ? $site ?? $cfg : null;
    }

    /** 赛事列表（5 秒本地缓存；$site = ['url'=>, 'key'=>] 指定站点，空则默认） */
    public static function tournaments(?array $site = null): array
    {
        $cfg = self::stance($site);
        if (!$cfg) return ['ok' => false, 'data' => null, 'msg' => '未接入赛事中心'];
        $ck = 'tournament_site_list_' . md5(($cfg['url'] ?? $cfg['base_url']) . '|' . ($cfg['key'] ?? ''));
        $cached = \think\facade\Cache::get($ck);
        if ($cached !== null) {
            return ['ok' => true, 'data' => $cached, 'msg' => 'ok'];
        }
        $r = self::request('GET', '/tournaments', [], $site);
        if ($r['ok']) {
            \think\facade\Cache::set($ck, $r['data'], self::CACHE_TTL);
        }
        return $r;
    }

    /** 赛事公开详情（含对阵树，脱敏视图） */
    public static function detail(int $id, ?array $site = null): array
    {
        return self::request('GET', '/tournament/' . $id, [], $site);
    }

    /** 按三元组查报名状态 */
    public static function entryStatus(int $tid, string $type, int $partyId, ?array $site = null): array
    {
        return self::request('GET', '/entry-status?tournament_id=' . $tid
            . '&entry_type=' . urlencode($type) . '&party_id=' . $partyId, [], $site);
    }

    /** 代报名（entry_type 仅 tenant/external，由调用方注入身份） */
    public static function apply(array $body, ?array $site = null): array
    {
        return self::request('POST', '/apply', $body, $site);
    }

    /** 撤回报名 */
    public static function withdraw(int $tid, string $type, int $partyId, ?array $site = null): array
    {
        return self::request('POST', '/withdraw', [
            'tournament_id' => $tid, 'entry_type' => $type, 'party_id' => $partyId,
        ], $site);
    }

    /** 名单保存（roster = 昵称数组） */
    public static function roster(int $tid, string $type, int $partyId, array $roster, ?array $site = null): array
    {
        return self::request('POST', '/roster', [
            'tournament_id' => $tid, 'entry_type' => $type, 'party_id' => $partyId, 'roster' => $roster,
        ], $site);
    }

    /** 小队保存（squads = [{name, members(名单条目id)}, ...]） */
    public static function squads(int $tid, string $type, int $partyId, array $squads, ?array $site = null): array
    {
        return self::request('POST', '/squads', [
            'tournament_id' => $tid, 'entry_type' => $type, 'party_id' => $partyId, 'squads' => $squads,
        ], $site);
    }

    /** 上场小队勾选（manual 赛制，赛事站自动定位该主体所在边） */
    public static function matchSquads(int $tid, string $type, int $partyId, int $matchId, array $squadIds, ?array $site = null): array
    {
        return self::request('POST', '/match-squads', [
            'tournament_id' => $tid, 'entry_type' => $type, 'party_id' => $partyId,
            'match_id' => $matchId, 'squad_ids' => $squadIds,
        ], $site);
    }

    // ---------- 底层 ----------

    /**
     * 发起对赛事站的 HTTP 请求（20 秒超时，失败友好提示）。
     * 超时需大于赛事站回调主站的 15 秒（报名核验等链路：主站→赛事站→主站）。
     * $path 相对 /api/open。$site = ['url'=>, 'key'=>] 指定站点，空则走默认配置。
     */
    protected static function request(string $method, string $path, array $body = [], ?array $site = null): array
    {
        $cfg = self::stance($site);
        if (!$cfg) {
            return ['ok' => false, 'data' => null, 'msg' => '未接入赛事中心'];
        }
        $url = rtrim((string)($cfg['url'] ?? $cfg['base_url']), '/') . '/api/open' . $path;
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_HTTPHEADER     => [
                'X-Api-Key: ' . (string)($cfg['key'] ?? ''),
                'Content-Type: application/json',
                'Accept: application/json',
            ],
        ]);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
        }
        $raw  = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $cerr = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            return ['ok' => false, 'data' => null, 'msg' => '赛事中心连接失败：' . ($cerr ?: '网络超时')];
        }
        $j = json_decode((string)$raw, true);
        if (!is_array($j)) {
            return ['ok' => false, 'data' => null, 'msg' => '赛事中心响应异常（HTTP ' . $code . '）'];
        }
        if ((int)($j['code'] ?? 1) !== 0) {
            return ['ok' => false, 'data' => null, 'msg' => (string)($j['msg'] ?? '赛事中心拒绝了该操作')];
        }
        return ['ok' => true, 'data' => $j['data'] ?? [], 'msg' => (string)($j['msg'] ?? 'ok')];
    }
}
