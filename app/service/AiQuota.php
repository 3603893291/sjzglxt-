<?php
declare (strict_types = 1);

namespace app\service;

use think\facade\Db;

/**
 * AI Token 配额（v5.36.14）
 *
 * 口径：平台模型用「一次性余额」——平台充值累加 token_granted，
 * 每次调用把实际消耗累加 token_used；余额 = granted - used。
 * 清零 = 把 granted 对齐到 used（余额归零），流水记一笔负数。
 * 自有模型不扣平台余额，只计 daily_used（每日上限仍然生效）。
 */
class AiQuota
{
    /** 单次充值上限（防止手滑多打几个零） */
    public const MAX_GRANT = 1000000000;

    /** 确保配置行存在，返回最新一行 */
    public static function ensure(int $tenantId): array
    {
        $row = self::row($tenantId);
        if ($row !== null) {
            return $row;
        }
        $now = date('Y-m-d H:i:s');
        try {
            Db::name('tenant_ai_configs')->insert([
                'tenant_id'  => $tenantId,
                'mode'       => 'off',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } catch (\Throwable $e) {
            // 并发下另一请求已插入，直接读
        }
        return self::row($tenantId) ?? AiClient::defaultTenantRow($tenantId);
    }

    public static function row(int $tenantId): ?array
    {
        try {
            $row = Db::name('tenant_ai_configs')->where('tenant_id', $tenantId)->find();
            return is_array($row) ? $row : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** 保存配置（只接受调用方白名单字段；行不存在则建） */
    public static function save(int $tenantId, array $fields): void
    {
        self::ensure($tenantId);
        $fields['updated_at'] = date('Y-m-d H:i:s');
        Db::name('tenant_ai_configs')->where('tenant_id', $tenantId)->update($fields);
    }

    /** 平台充值（正数） */
    public static function grant(int $tenantId, int $amount, int $by, string $note = ''): array
    {
        if ($amount <= 0 || $amount > self::MAX_GRANT) {
            return [false, '充值数量必须在 1 ~ ' . self::MAX_GRANT . ' 之间'];
        }
        $cfg = self::ensure($tenantId);
        Db::name('tenant_ai_configs')->where('tenant_id', $tenantId)
            ->inc('token_granted', $amount)->update(['updated_at' => date('Y-m-d H:i:s')]);
        $after = max(0, (int)$cfg['token_granted'] + $amount - (int)$cfg['token_used']);
        self::ledger($tenantId, $amount, $after, $note !== '' ? $note : '平台充值', $by);
        return [true, $after];
    }

    /** 平台清零：余额归零，不清历史消耗（流水可复盘） */
    public static function reset(int $tenantId, int $by, string $note = ''): array
    {
        $cfg = self::ensure($tenantId);
        $balance = max(0, (int)$cfg['token_granted'] - (int)$cfg['token_used']);
        $used = (int)$cfg['token_used'];
        Db::name('tenant_ai_configs')->where('tenant_id', $tenantId)
            ->update(['token_granted' => $used, 'updated_at' => date('Y-m-d H:i:s')]);
        if ($balance > 0) {
            self::ledger($tenantId, -$balance, 0, $note !== '' ? $note : '平台清零', $by);
        }
        return [true, 0];
    }

    private static function ledger(int $tenantId, int $amount, int $after, string $note, int $by): void
    {
        try {
            Db::name('ai_token_grants')->insert([
                'tenant_id'     => $tenantId,
                'amount'        => $amount,
                'balance_after' => max(0, $after),
                'note'          => mb_substr($note, 0, 200),
                'granted_by'    => $by,
                'created_at'    => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            // 流水失败不影响余额
        }
    }

    public static function balance(array $cfg): int
    {
        return max(0, (int)($cfg['token_granted'] ?? 0) - (int)($cfg['token_used'] ?? 0));
    }

    /** 今日已用额度检查（0 = 不限） */
    public static function dailyOk(array $cfg): bool
    {
        $limit = (int)($cfg['daily_token_limit'] ?? 0);
        if ($limit <= 0) {
            return true;
        }
        $today = date('Y-m-d');
        if ((string)($cfg['daily_date'] ?? '') !== $today) {
            return true;
        }
        return (int)($cfg['daily_used'] ?? 0) < $limit;
    }

    /**
     * 记一次消耗：平台模型累加 token_used，两种模型都累加 daily_used。
     */
    public static function charge(int $tenantId, int $tokens, bool $platform): void
    {
        $tokens = max(0, $tokens);
        $today = date('Y-m-d');
        $now = date('Y-m-d H:i:s');
        try {
            // 跨天先清零当日用量（NULL / 旧日期都算跨天）
            Db::name('tenant_ai_configs')->where('tenant_id', $tenantId)
                ->where(function ($q) use ($today) {
                    $q->whereNull('daily_date')->whereOr('daily_date', '<>', $today);
                })
                ->update(['daily_used' => 0, 'daily_date' => $today]);
            $q = Db::name('tenant_ai_configs')->where('tenant_id', $tenantId);
            if ($tokens > 0) {
                $q->inc('daily_used', $tokens);
                if ($platform) {
                    $q->inc('token_used', $tokens);
                }
            }
            $q->update([
                'last_used_at' => $now,
                'updated_at'   => $now,
            ]);
        } catch (\Throwable $e) {
            // 记账失败不阻断回复
        }
    }

    /** 写用量流水（失败不影响主流程） */
    public static function usage(array $row): void
    {
        try {
            $now = date('Y-m-d H:i:s');
            Db::name('ai_usage_logs')->insert([
                'tenant_id'         => (int)($row['tenant_id'] ?? 0),
                'group_openid'      => (string)($row['group_openid'] ?? ''),
                'member_openid'     => (string)($row['member_openid'] ?? ''),
                'kind'              => (string)($row['kind'] ?? 'at'),
                'source'            => (string)($row['source'] ?? 'platform'),
                'model'             => mb_substr((string)($row['model'] ?? ''), 0, 64),
                'prompt_tokens'     => max(0, (int)($row['prompt_tokens'] ?? 0)),
                'completion_tokens' => max(0, (int)($row['completion_tokens'] ?? 0)),
                'total_tokens'      => max(0, (int)($row['total_tokens'] ?? 0)),
                'duration_ms'       => max(0, (int)($row['duration_ms'] ?? 0)),
                'status'            => (string)($row['status'] ?? 'ok'),
                'error'             => mb_substr((string)($row['error'] ?? ''), 0, 200),
                'created_at'        => $now,
            ]);
            if (random_int(1, 30) === 1) {
                Db::name('ai_usage_logs')
                    ->where('created_at', '<', date('Y-m-d H:i:s', time() - 90 * 86400))
                    ->delete();
            }
        } catch (\Throwable $e) {
            // 流水失败不影响主流程
        }
    }

    public static function error(int $tenantId, string $message): void
    {
        try {
            Db::name('tenant_ai_configs')->where('tenant_id', $tenantId)->update([
                'last_error' => mb_substr($message, 0, 255),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
        }
    }
}
