<?php
declare (strict_types = 1);

namespace app\service;

use think\facade\Db;

/**
 * 约战成立后自动向本队发公告（v5.13.0）
 *
 * 语义：约战被 b 方管理员接受（成立）后，对阵双方「各向自己的成员」发一条
 * 需要已读回执的公告——成员只看到本队那条，看不到对方队的。
 * 外部战队（连接器站点）在主站侧没有租户身份，由本站 BattleHub::accept 回传成功后本地补发。
 *
 * 公告行直接按 tenant_id 写库，不经 Announcement 模型：
 * 模型的全局作用域会把 tenant_id 强制成当前请求租户，而这里一次接受要写两个租户。
 * 因此也不受调用方上下文影响（站内 / 开放 API / 平台键都走同一套）。
 */
class BattleNotice
{
    /** 本队开关：'0'=关闭成立自动公告，其余（含从未设置）=开（默认开） */
    public const SWITCH_KEY = 'battle_accept_notice';

    /** 站内总线：按 battles 行给双方各自的本队发公告 */
    public static function publishAccept(array $b): void
    {
        $info = [
            'title'        => (string)$b['title'],
            'game_mode'    => (string)($b['game_mode'] ?? ''),
            'battle_time'  => (string)($b['battle_time'] ?? ''),
            'room_info'    => (string)($b['room_info'] ?? ''),
            'remark'       => (string)($b['remark'] ?? ''),
            'series_format' => (string)($b['series_format'] ?? ''),
            'battle_type'  => (string)($b['battle_type'] ?? 'official'),
            'roster_limit' => (int)($b['roster_limit'] ?? 0),
        ];
        self::publishParty((string)$b['challenger_type'], (int)$b['challenger_id'],
            (string)$b['defender_type'], (int)$b['defender_id'], $info);
        self::publishParty((string)$b['defender_type'], (int)$b['defender_id'],
            (string)$b['challenger_type'], (int)$b['challenger_id'], $info);
    }

    /** 连接器模式：本站以外部战队身份应战成功，用主站详情给本站成员补发 */
    public static function publishLocal(array $detail): void
    {
        self::publish(TenantContext::id(), [
            'opponent'      => (string)($detail['challenger']['name'] ?? '对方战队'),
            'title'         => (string)($detail['title'] ?? ''),
            'game_mode'     => (string)($detail['game_mode'] ?? ''),
            'battle_time'   => (string)($detail['battle_time'] ?? ''),
            'room_info'     => (string)($detail['room_info'] ?? ''),
            'remark'        => (string)($detail['remark'] ?? ''),
            'series_format' => (string)($detail['series_format'] ?? ''),
            'battle_type'   => (string)($detail['battle_type'] ?? 'official'),
            'roster_limit'  => (int)($detail['roster_limit'] ?? 0),
        ]);
    }

    /** 一方是本队租户则发；外部战队（无租户）静默跳过 */
    protected static function publishParty(string $myType, int $myId, string $oppType, int $oppId, array $info): void
    {
        if ($myType !== 'tenant') {
            return;
        }
        $info['opponent'] = BattleService::partyName($oppType, $oppId);
        self::publish($myId, $info);
    }

    /** 落一条本队公告（开关关闭、发不出都不影响约战主流程） */
    protected static function publish(int $tenantId, array $info): void
    {
        try {
            if ($tenantId <= TenantContext::PLATFORM_ID || !self::enabledFor($tenantId)) {
                return;
            }
            $opp   = trim((string)($info['opponent'] ?? '')) ?: '对方战队';
            $title = mb_substr('【约战成立】' . ($info['title'] !== '' ? $info['title'] . ' ' : '') . 'vs ' . $opp, 0, 128);
            $now   = date('Y-m-d H:i:s');
            Db::name('announcements')->insert([
                'tenant_id'    => $tenantId,
                'title'        => $title,
                'content'      => self::content($info, $opp),
                'image'        => '',
                'need_receipt' => 1,
                'deadline'     => null,
                'reminded_at'  => null,
                'created_at'   => $now,
                'updated_at'   => $now,
            ]);
        } catch (\Throwable $e) {
            \think\facade\Log::warning('[BattleNotice] ' . $e->getMessage());
        }
    }

    /** 某队的自动公告开关（跨租户读，不经 Setting 的当前租户缓存） */
    public static function enabledFor(int $tenantId): bool
    {
        $v = Db::name('settings')->where('tenant_id', $tenantId)
            ->where('key', self::SWITCH_KEY)->value('value');
        return (string)$v !== '0';
    }

    /** 公告正文：开赛信息 + 报名指引 + 已读回执说明 */
    protected static function content(array $i, string $opp): string
    {
        $lines = [$opp . ' 发起的约战已由管理员确认接受，现在进入报名阶段。', ''];
        if ($i['title'] !== '') {
            $lines[] = '约战主题：' . $i['title'];
        }
        if ($i['game_mode'] !== '') {
            $lines[] = '比赛模式：' . $i['game_mode'];
        }
        if ($i['battle_time'] !== '') {
            $lines[] = '开赛时间：' . $i['battle_time'];
        }
        if ($i['series_format'] !== '') {
            $lines[] = '赛制：' . strtoupper($i['series_format'])
                . '（' . ($i['battle_type'] === 'training' ? '训练赛' : '正式赛事') . '）';
        }
        if ($i['room_info'] !== '') {
            $lines[] = '房间/地点：' . $i['room_info'];
        }
        if ($i['remark'] !== '') {
            $lines[] = '备注：' . $i['remark'];
        }
        $lines[] = '';
        $lines[] = '要出战的成员请尽快到「自建房比赛日程 → 跨战队约战」报名'
            . ($i['roster_limit'] > 0 ? '，本队上限 ' . $i['roster_limit'] . ' 人。' : '。');
        $lines[] = '本条公告需要已读确认，管理员可查看未读名单并一键提醒。';
        return implode("\n", $lines);
    }
}
