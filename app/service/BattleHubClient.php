<?php
declare(strict_types=1);

namespace app\service;

use think\facade\Cache;

/**
 * 约战中心连接器客户端（v5.4.0）
 *
 * 单租户开源部署接入平台约战总线：凭站点设置里的约战中心地址 + external Key，
 * 调用 Hub 的 /api/open/battles 开放接口。Key 只存服务端，不出前端。
 *
 * 约定：方法返回 ['ok'=>bool, 'data'=>mixed, 'msg'=>string]
 */
class BattleHubClient
{
    /** 本地缓存秒数（列表结果，防高频打爆 Hub） */
    protected const CACHE_TTL = 5;

    /** 读取连接配置（未配置任一项视为未接入） */
    public static function config(): array
    {
        $enabled = Setting::get('battle_hub_enabled') === '1';
        $url     = rtrim(Setting::get('battle_hub_url'), '/');
        $key     = Setting::get('battle_hub_key');
        return ['enabled' => $enabled && $url !== '' && $key !== '', 'url' => $url, 'key' => $key];
    }

    /** 本队相关约战列表（5 秒本地缓存） */
    public static function battles(): array
    {
        $cfg = self::config();
        if (!$cfg['enabled']) return ['ok' => false, 'data' => null, 'msg' => '约战中心未接入'];
        $ck = 'battle_hub_list_' . md5($cfg['url'] . '|' . $cfg['key']);
        $cached = Cache::get($ck);
        if ($cached !== null) {
            return ['ok' => true, 'data' => $cached, 'msg' => 'ok'];
        }
        $r = self::request('GET', '');
        if ($r['ok']) {
            Cache::set($ck, $r['data'], self::CACHE_TTL);
        }
        return $r;
    }

    /** 约战详情 */
    public static function detail(int $id): array
    {
        return self::request('GET', '/' . $id);
    }

    /** 平台地图池 */
    public static function mapPool(): array
    {
        return self::request('GET', '/map-pool');
    }

    /** 动作透传（accept / reject / cancel） */
    public static function action(int $id, string $action): array
    {
        return self::request('POST', '/' . $id . '/' . $action);
    }

    /** 提交禁用地图（0=放弃） */
    public static function ban(int $id, int $mapId): array
    {
        return self::request('POST', '/' . $id . '/ban', ['map_id' => $mapId]);
    }

    /** 名单昵称上报/撤回（外部战队身份） */
    public static function signup(int $id, array $names, string $action = 'add'): array
    {
        return self::request('POST', '/' . $id . '/signup', ['names' => $names, 'action' => $action]);
    }

    /** 名单管理（移除条目；添加昵称建议走 signup） */
    public static function roster(int $id, array $body): array
    {
        return self::request('POST', '/' . $id . '/roster', $body);
    }

    /** 训练赛小局录入（result + 可选统计；开放接口不带截图，截图上传仅 Hub 站内支持） */
    public static function roundResult(int $id, int $rid, array $body): array
    {
        return self::request('POST', '/' . $id . '/round/' . $rid . '/result', $body);
    }

    /** 决胜局选图/选边（choice=pick_map传 map_id / pick_side传 side=attack|defend） */
    public static function decider(int $id, array $body): array
    {
        return self::request('POST', '/' . $id . '/decider', $body);
    }

    // ---------- 底层 ----------

    /**
     * 发起对 Hub 的 HTTP 请求（5 秒超时，失败友好提示）。
     * $path 相对 /api/open/battles，如 '' / '/3' / '/3/accept'
     */
    protected static function request(string $method, string $path, array $body = []): array
    {
        $cfg = self::config();
        if (!$cfg['enabled']) {
            return ['ok' => false, 'data' => null, 'msg' => '约战中心未接入'];
        }
        $url = $cfg['url'] . '/api/open/battles' . $path;
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_HTTPHEADER     => [
                'X-Api-Key: ' . $cfg['key'],
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
            return ['ok' => false, 'data' => null, 'msg' => '约战中心连接失败：' . ($cerr ?: '网络超时')];
        }
        $j = json_decode((string)$raw, true);
        if (!is_array($j)) {
            return ['ok' => false, 'data' => null, 'msg' => '约战中心响应异常（HTTP ' . $code . '）'];
        }
        if ((int)($j['code'] ?? 1) !== 0) {
            return ['ok' => false, 'data' => null, 'msg' => (string)($j['msg'] ?? '约战中心拒绝了该操作')];
        }
        return ['ok' => true, 'data' => $j['data'] ?? [], 'msg' => (string)($j['msg'] ?? 'ok')];
    }
}
