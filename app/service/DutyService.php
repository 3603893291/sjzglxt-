<?php
declare (strict_types = 1);

namespace app\service;

use app\model\DutyConfig;
use app\model\DutyMember;
use app\model\DutySwap;
use app\model\User;

/**
 * 值班轮值计算（v5.6.0）
 * 轮换机制与轮换地图同思路：锚点日期 + 周期（按天/按周）取模轮转
 * 已接受的换班作为指定日期的例外覆盖
 */
class DutyService
{
    /** 取房主值班配置（首期仅 room_host 一种）。
     *  v5.36.0：值班这块开着分端时，先取当前专区的配置，没有就沿用全队共享那份；
     *  没开分端时只看共享那份（老数据都在 game_side='' 上）。 */
    public static function config(?string $zone = null): ?DutyConfig
    {
        if (!GameSide::moduleOn('duty')) {
            $shared = DutyConfig::where('duty_type', 'room_host')->where('game_side', '')->find();
            return $shared ?: DutyConfig::where('duty_type', 'room_host')->order('id', 'asc')->find();
        }
        $zone = $zone ?: GameSide::zone();
        $exact = DutyConfig::where('duty_type', 'room_host')->where('game_side', $zone)->find();
        if ($exact) {
            return $exact;
        }
        return DutyConfig::where('duty_type', 'room_host')->where('game_side', '')->find();
    }

    /** 管理员页要动的那一份：只认当前专区那一份，没有就是 null（不拿共享的顶替，免得改错） */
    public static function configExact(string $zone): ?DutyConfig
    {
        return DutyConfig::where('duty_type', 'room_host')->where('game_side', $zone)->find();
    }

    /**
     * 计算某日值班。
     * 返回 ['user_id'=>int, 'nickname'=>string, 'swapped'=>bool, 'swapped_from'=>string] 或 null（未启用/无人）
     */
    public static function dutyOn(string $date, ?DutyConfig $cfg = null): ?array
    {
        $cfg = $cfg ?: self::config();
        if (!$cfg || !$cfg->enabled) {
            return null;
        }
        $members = DutyMember::where('config_id', $cfg->id)->order('sort', 'asc')->order('id', 'asc')->select();
        $n = count($members);
        if ($n === 0) {
            return null;
        }

        $anchor = strtotime((string)$cfg->anchor_date);
        $target = strtotime($date);
        if ($anchor === false || $target === false) {
            return null;
        }
        if ($cfg->cycle === 'week') {
            // 锚点所在周的周一为第 0 周期起点
            $anchor -= ((int)date('N', $anchor) - 1) * 86400;
            $period  = intdiv($target - $anchor, 7 * 86400);
        } else {
            $period = intdiv($target - $anchor, 86400);
        }
        $idx  = (($period % $n) + $n) % $n;
        $uid  = (int)$members[$idx]->user_id;
        $info = ['user_id' => $uid, 'nickname' => '', 'swapped' => false, 'swapped_from' => ''];

        // 换班例外：当天有已接受的换班且原值班人是发起人 → 由换班对象顶替
        $swap = DutySwap::where('config_id', $cfg->id)
            ->where('duty_date', $date)
            ->where('status', 'accepted')
            ->where('from_uid', $uid)
            ->find();
        if ($swap) {
            $fromName = self::nickname($uid);
            $uid      = (int)$swap->to_uid;
            $info['swapped']      = true;
            $info['swapped_from'] = $fromName;
        }
        $info['user_id']  = $uid;
        $info['nickname'] = self::nickname($uid);
        return $info;
    }

    protected static function nickname(int $uid): string
    {
        $u = User::find($uid);
        return $u ? (string)($u->nickname ?: $u->username) : '';
    }
}
