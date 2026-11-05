<?php
declare(strict_types=1);

namespace app\service;

use think\facade\Db;

/**
 * 轮换地图快照（v5.36.5）
 *
 * 平台维护「每日组合」，每天 9:00 切到下一组；0-9 点为今日预告。
 * 网页与 QQ 机器人共用这一份计算，避免两边各写一套轮换公式后漂移。
 */
class MapRotation
{
    /** 网页最多给到多少天（再远没有意义，一轮通常也就一周） */
    public const MAX_UPCOMING = 14;

    /** 机器人只带接下来 3 天，别把消息刷太长 */
    public const BOT_UPCOMING = 3;

    public static function enabled(): bool
    {
        return Setting::getPlatform('map_rotation_enabled', '0') === '1';
    }

    /**
     * @param int $upcomingDays 想要几天预告；实际还会受轮换组数限制（最多一轮，且不超过 14 天）
     */
    public static function snapshot(int $upcomingDays = self::MAX_UPCOMING): array
    {
        $enabled = self::enabled();
        $start   = trim(Setting::getPlatform('map_rotation_start', ''));
        $startDate = $start !== '' ? substr($start, 0, 10) : '';
        if ($startDate === '' || strtotime($startDate) === false) {
            $startDate = date('Y-m-d');
        }

        $days = Db::name('rotation_days')->where('tenant_id', 0)->order('id', 'asc')->select()->toArray();
        $dayIds = array_column($days, 'id');
        $links = $dayIds !== []
            ? Db::name('rotation_day_maps')->where('tenant_id', 0)->whereIn('day_id', $dayIds)->select()->toArray()
            : [];
        $mapIds = array_values(array_unique(array_map('intval', array_column($links, 'map_id'))));
        $maps = $mapIds !== []
            ? Db::name('rotation_maps')->where('tenant_id', 0)->whereIn('id', $mapIds)->select()->toArray()
            : [];
        $mapById = [];
        foreach ($maps as $m) {
            $mapById[(int)$m['id']] = $m;
        }
        $mapsOfDay = [];
        foreach ($links as $l) {
            $mid = (int)$l['map_id'];
            if (isset($mapById[$mid])) {
                $mapsOfDay[(int)$l['day_id']][] = $mapById[$mid];
            }
        }
        foreach ($days as &$d) {
            $d['maps'] = $mapsOfDay[(int)$d['id']] ?? [];
        }
        unset($d);

        $count = count($days);
        $today9 = strtotime(date('Y-m-d') . ' 09:00:00');
        $anchorTs = strtotime($startDate . ' 09:00:00');
        $status = time() >= $today9 ? 'active' : 'pending';

        $index = 0;
        if ($count > 0) {
            $elapsed = (int)round(($today9 - $anchorTs) / 86400);
            if ($elapsed < 0) {
                $elapsed = 0;   // 锚点在未来：先按第 1 组展示
            }
            $index = $elapsed % $count;
        }

        $upcoming = [];
        if ($count > 0) {
            // 最多看完整一轮：组合少于请求天数时按组合数截断，不把同一轮重复贴两遍
            $limit = min(self::MAX_UPCOMING, max(0, $upcomingDays), $count);
            for ($i = 1; $i <= $limit; $i++) {
                $d = $days[($index + $i) % $count];
                $ts = $today9 + $i * 86400;
                $upcoming[] = [
                    'after_days' => $i,
                    'date'       => date('Y-m-d', $ts),
                    'name'       => $d['name'],
                    'maps'       => $d['maps'],
                ];
            }
        }

        return [
            'enabled'        => $enabled,
            'start_date'     => $startDate,
            'status'         => $status,
            'today_index'    => $index,
            'today'          => $count > 0 ? $days[$index] : null,
            'next_rotate_at' => $count > 0 ? date('Y-m-d H:i:s', time() >= $today9 ? $today9 + 86400 : $today9) : null,
            'cycle_size'     => $count,
            'upcoming'       => $upcoming,
        ];
    }
}
