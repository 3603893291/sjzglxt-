<?php
declare (strict_types = 1);

namespace app\controller;

use app\service\Setting;
use think\facade\Db;

/**
 * 今天轮换地图（v3.8.1）
 * 规则：管理员维护"每日组合"（一组多张地图，从地图池多选），
 *       每天 9:00 切换到下一个组合循环轮换；游戏时间 9:00-24:00，
 *       凌晨 0 点到 9 点为未开始状态（预告今天的组合）
 * v5.1.0：配置收归入驻平台超管统一管理（数据落 tenant_id=0），全部战队共用一套
 * 全局开关：平台设置 map_rotation_enabled = 1 时功能才可用
 * 锚点：平台设置 map_rotation_start = 第 1 个组合对应的日期（YYYY-MM-DD）
 */
class Rotation extends AuthBase
{
    public function index()
    {
        $enabled = Setting::getPlatform('map_rotation_enabled', '0') === '1';
        $start   = trim(Setting::getPlatform('map_rotation_start', ''));

        // 兼容旧数据（datetime 存法），只取日期部分
        $startDate = $start !== '' ? substr($start, 0, 10) : '';
        if ($startDate === '' || strtotime($startDate) === false) {
            $startDate = date('Y-m-d');   // 未设置锚点默认今天，保证开箱可用
        }

        // 组合 + 关联地图组装（平台统一数据：tenant_id=0）
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

        // 轮换边界：每天 9:00。0 点-9 点属于"今日未开始"，展示今天的组合预告
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

        // 未来预告：接下来 5 天的组合
        $upcoming = [];
        if ($count > 0) {
            for ($i = 1; $i <= 5; $i++) {
                $upcoming[] = [
                    'after_days' => $i,
                    'name'       => $days[($index + $i) % $count]['name'],
                    'maps'       => $days[($index + $i) % $count]['maps'],
                ];
            }
        }

        return api_ok([
            'enabled'        => $enabled,
            'start_date'     => $startDate,
            'status'         => $status,          // active 进行中 / pending 今日未开始(9点前)
            'today_index'    => $index,
            'today'          => $count > 0 ? $days[$index] : null,
            'next_rotate_at' => $count > 0 ? date('Y-m-d H:i:s', time() >= $today9 ? $today9 + 86400 : $today9) : null,
            'upcoming'       => $upcoming,
        ]);
    }
}
