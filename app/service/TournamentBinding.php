<?php
declare(strict_types=1);

namespace app\service;

use think\facade\Db;

/**
 * 赛事中心多站点绑定（v5.5.1）
 *
 * 两种来源并存：
 *   source=platform（tenant_id=0）：平台超管维护的共享库，全体租户可用，无需确认。
 *   source=tenant（tenant_id>0）  ：租户自行添加，须 confirmed=1（授权确认）后才启用。
 * 身份通道（channel）：
 *   external：以「我方为该租户维护的外部战队身份」(entry_type=external) 活动，通用第三方站。
 *   tenant  ：以租户身份 (entry_type=tenant) 活动，仅对接能回调核验租户的站（默认/历史站点）。
 */
class TournamentBinding
{
    /** 平台共享库：某租户可见的所有启用绑定 */
    public static function listFor(int $tenantId): array
    {
        return Db::name('tournament_bindings')
            ->where('enabled', 1)
            ->where(function ($q) use ($tenantId) {
                $q->where('source', 'platform')
                  ->whereOr(function ($q2) use ($tenantId) {
                      $q2->where('source', 'tenant')->where('tenant_id', $tenantId)->where('confirmed', 1);
                  });
            })
            ->order('id', 'asc')
            ->select()->toArray();
    }

    /** 解析绑定为站点参数；无效或不可用于该租户时返回 null */
    public static function site(int $id, int $tenantId): ?array
    {
        $b = Db::name('tournament_bindings')->where('id', $id)->find();
        if (!$b || (int)$b['enabled'] !== 1) return null;
        if ((string)$b['source'] === 'platform') {
            // 平台共享库全体可见
        } elseif ((int)$b['tenant_id'] === $tenantId && (int)$b['confirmed'] === 1) {
            // 租户自增且已确认
        } else {
            return null;
        }
        return [
            'id'      => (int)$b['id'],
            'name'    => (string)$b['name'],
            'url'     => rtrim((string)$b['base_url'], '/'),
            'key'     => (string)$b['api_key'],
            'channel' => (string)$b['channel'],
            'source'  => (string)$b['source'],
        ];
    }

    /** 绑定身份三元组（按通道） */
    public static function party(array $binding, int $tenantId): array
    {
        if (($binding['channel'] ?? 'external') === 'tenant') {
            return ['type' => 'tenant', 'id' => $tenantId, 'name' => self::tenantName($tenantId)];
        }
        return self::externalParty($tenantId);
    }

    // ---------- 平台共享库（平台超管） ----------

    public static function platformList(): array
    {
        return Db::name('tournament_bindings')->where('source', 'platform')->order('id', 'asc')->select()->toArray();
    }

    public static function platformSave(array $p, ?int $id = null): array
    {
        $name   = mb_substr(trim((string)($p['name'] ?? '')), 0, 64);
        $url    = rtrim(trim((string)($p['base_url'] ?? $p['url'] ?? '')), '/');
        $key    = trim((string)($p['key'] ?? ''));
        $enable = (int)($p['enabled'] ?? 1) === 1;
        if ($name === '')            return [0, '请填写站点名称'];
        if ($url === '' || !preg_match('#^https?://#i', $url)) return [0, '请填写赛事中心地址（http/https 开头）'];
        $channel = (string)($p['channel'] ?? 'external');
        if (!in_array($channel, ['external', 'tenant'], true)) {
            return [0, '身份通道无效'];
        }
        $data = [
            'name' => $name, 'base_url' => $url, 'channel' => $channel,
            'source' => 'platform', 'tenant_id' => 0, 'confirmed' => 1, 'enabled' => $enable ? 1 : 0,
            'updated_at' => date('Y-m-d H:i:s'),
        ];
        if ($id !== null) {
            $row = Db::name('tournament_bindings')->where('id', (int)$id)->where('source', 'platform')->find();
            if (!$row) return [0, '绑定不存在'];
            if ($key !== '') $data['api_key'] = $key; // key 留空=保持原值
            Db::name('tournament_bindings')->where('id', (int)$id)->update($data);
            return [(int)$id, 'ok', $data];
        }
        if ($key === '') return [0, '新增绑定必须填写 API Key'];
        $data['api_key'] = $key;
        $data['created_at'] = $data['updated_at'];
        $id = Db::name('tournament_bindings')->insertGetId($data);
        return [$id, 'ok', $data];
    }

    public static function platformRemove(int $id): bool
    {
        return Db::name('tournament_bindings')
            ->where('id', (int)$id)->where('source', 'platform')->delete() > 0;
    }

    // ---------- 租户自增绑定 ----------

    public static function addTenant(array $p, int $tenantId): array
    {
        $name = mb_substr(trim((string)($p['name'] ?? '')), 0, 64);
        $url  = rtrim(trim((string)($p['base_url'] ?? $p['url'] ?? '')), '/');
        $key  = trim((string)($p['key'] ?? ''));
        if ($name === '')            return [0, '请填写站点名称'];
        if ($url === '' || !preg_match('#^https?://#i', $url)) return [0, '请填写赛事中心地址（http/https 开头）'];
        if ($key === '') return [0, '请填写赛事站 API Key'];
        $channel = (string)($p['channel'] ?? 'external');
        if (!in_array($channel, ['external', 'tenant'], true)) {
            return [0, '身份通道无效'];
        }
        $now = date('Y-m-d H:i:s');
        $id = Db::name('tournament_bindings')->insertGetId([
            'name' => $name, 'base_url' => $url, 'api_key' => $key, 'channel' => $channel,
            'enabled' => 0, 'source' => 'tenant', 'tenant_id' => $tenantId, 'confirmed' => 0,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        return [$id, 'ok'];
    }

    /** 授权确认：勾选确认即可启用 */
    public static function confirmTenant(int $id, int $tenantId): array
    {
        $b = Db::name('tournament_bindings')->where('id', (int)$id)
            ->where('source', 'tenant')->where('tenant_id', $tenantId)->find();
        if (!$b) return [0, '绑定不存在'];
        if ((int)$b['confirmed'] === 1) return [(int)$id, '已启用'];
        Db::name('tournament_bindings')->where('id', (int)$id)
            ->update(['confirmed' => 1, 'enabled' => 1, 'updated_at' => date('Y-m-d H:i:s')]);
        return [(int)$id, '已启用'];
    }

    public static function removeTenant(int $id, int $tenantId): bool
    {
        return Db::name('tournament_bindings')->where('id', (int)$id)
            ->where('source', 'tenant')->where('tenant_id', $tenantId)->delete() > 0;
    }

    // ---------- 身份辅助 ----------

    protected static function tenantName(int $tenantId): string
    {
        return (string)(Db::name('tenants')->where('id', $tenantId)->value('name') ?: '租户#' . $tenantId);
    }

    /** 为租户解析/创建一个外部战队身份（通用第三方站通道），remark 锚定租户避免重复 */
    protected static function externalParty(int $tenantId): array
    {
        $anchor = 'binding-tenant:' . (int)$tenantId;
        $row = Db::name('external_teams')->where('remark', $anchor)->find();
        if ($row) {
            return ['type' => 'external', 'id' => (int)$row['id'], 'name' => (string)$row['name']];
        }
        $name = self::tenantName($tenantId);
        $now  = date('Y-m-d H:i:s');
        $id = Db::name('external_teams')->insertGetId([
            'name' => $name, 'contact_name' => '', 'remark' => $anchor, 'status' => 1,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        return ['type' => 'external', 'id' => (int)$id, 'name' => $name];
    }
}