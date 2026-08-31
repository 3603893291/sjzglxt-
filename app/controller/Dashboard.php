<?php
declare (strict_types = 1);

namespace app\controller;

use app\model\Announcement;
use app\model\CustomRaceEvent;
use app\model\RaceEvent;
use app\model\User;

/**
 * 仪表盘：注册人数 / 在线人数 / 公告
 * 撞车比赛时间点（公开）
 */
class Dashboard extends AuthBase
{
    // 仪表盘数据
    public function index()
    {
        // 注册人数：不含封禁、不含待审核
        $userCount = User::where('status', 'approved')->count();

        // 在线人数：5 分钟内有活跃的正常用户
        $onlineCount = User::where('status', 'approved')
            ->where('last_active_at', '>', date('Y-m-d H:i:s', time() - 300))
            ->count();

        // 公告：最多 5 条（最新在前）
        $announcements = Announcement::order('id', 'desc')->limit(5)->select();

        return api_ok([
            'user_count'    => $userCount,
            'online_count'  => $onlineCount,
            'announcements' => $announcements,
        ]);
    }

    // 撞车比赛时间点（公开：仅显示未开始的，最多 3 场）
    public function races()
    {
        // 顺便清理已过期的场次
        RaceEvent::where('race_time', '<=', date('Y-m-d H:i:s'))->delete();

        $list = RaceEvent::where('race_time', '>', date('Y-m-d H:i:s'))
            ->order('race_time', 'asc')
            ->limit(3)
            ->select();

        return api_ok(['list' => $list, 'server_time' => date('Y-m-d H:i:s')]);
    }

    // 自建房比赛日程（v3.9.6 公开：仅显示未开始的，最多 3 场）
    public function customRaces()
    {
        // 顺便清理已过期的场次
        CustomRaceEvent::where('race_time', '<=', date('Y-m-d H:i:s'))->delete();

        $list = CustomRaceEvent::where('race_time', '>', date('Y-m-d H:i:s'))
            ->order('race_time', 'asc')
            ->limit(3)
            ->select();

        return api_ok(['list' => $list, 'server_time' => date('Y-m-d H:i:s')]);
    }
}
