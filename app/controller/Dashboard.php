<?php
declare (strict_types = 1);

namespace app\controller;

use app\model\Announcement;
use app\model\CustomRaceEvent;
use app\model\RaceEvent;
use app\model\User;
use app\service\GameSide;

/**
 * 仪表盘：注册人数 / 在线名单 / 公告
 * 撞车比赛时间点（公开）
 */
class Dashboard extends AuthBase
{
    /** 在线判定窗口（秒）：配合 AuthCheck 的 last_active 写库节流（60 秒） */
    const ONLINE_WINDOW = 300;

    /** 名单最多返回多少人；超出时 online_truncated=true，人数按同一份名单出，不会两处打架 */
    const ONLINE_LIMIT = 200;

    // 仪表盘数据
    public function index()
    {
        // 注册人数：不含封禁、不含待审核
        $userCount = User::where('status', 'approved')->count();

        // v5.14.0：在线从"一个数字"变成一份名单，人数与名单由同一次查询产出，
        // 顶部统计卡片与在线成员卡片永远一致
        $online = $this->onlineList();

        // 公告：最多 5 条（最新在前）。v5.36.0：分端时只看本端 + 全队共享
        // v5.36.34：定时未到点的、已撤回的都不算（首页那张卡与铃铛未读数同口径）；
        // 排序按「真正发出来的那一刻」，publish_at 一起给前端显示
        $aq = Announcement::orderRaw('COALESCE(scheduled_at, created_at) DESC, id DESC');
        $aq = Announcement::aliveOnly($aq);
        $aq = GameSide::scope($aq, 'announce');
        $announcements = $aq->limit(5)->select()->toArray();
        foreach ($announcements as &$a) {
            $sched = trim((string)($a['scheduled_at'] ?? ''));
            $a['publish_at'] = $sched !== '' ? $sched : (string)($a['created_at'] ?? '');
        }
        unset($a);

        return api_ok([
            'user_count'       => $userCount,
            'online_count'     => $online['count'],
            'online_admins'    => $online['admins'],
            'online_members'   => $online['count'] - $online['admins'],
            'online_list'      => $online['list'],
            'online_window'    => self::ONLINE_WINDOW,
            'online_truncated' => $online['truncated'],
            'announcements'    => $announcements,
        ]);
    }

    /**
     * 在线成员：5 分钟内有操作且已过审的本队成员（BelongsToTenant 自动限定本战队）。
     * 只出 id / 显示名 / 是否管理员——QQ、邮箱、IP 一律不进响应，这份名单是全员可见的。
     * 隐身巡查不写 last_active_at（见 AuthCheck），巡查中的平台管理员不会出现在名单里。
     */
    protected function onlineList(): array
    {
        $rows = User::where('status', 'approved')
            ->where('last_active_at', '>', date('Y-m-d H:i:s', time() - self::ONLINE_WINDOW))
            ->order('last_active_at', 'desc')
            ->limit(self::ONLINE_LIMIT + 1)
            ->select();
        $truncated = count($rows) > self::ONLINE_LIMIT;

        $list = [];
        $admins = 0;
        foreach ($rows as $u) {
            if (count($list) >= self::ONLINE_LIMIT) {
                break;
            }
            $isAdmin = (string)$u->role === 'admin';
            if ($isAdmin) {
                $admins++;
            }
            $list[] = [
                'id'       => (int)$u->id,
                'name'     => $this->displayName($u),
                'is_admin' => $isAdmin,
            ];
        }
        // 管理员排在前面；PHP 8 的排序稳定，同组内保持"最近活跃在前"
        usort($list, function ($a, $b) {
            return (int)$b['is_admin'] - (int)$a['is_admin'];
        });

        return [
            'list'      => $list,
            'count'     => count($list),
            'admins'    => $admins,
            'truncated' => $truncated,
        ];
    }

    /** 显示名：昵称优先；没昵称时打码账号，全员可见的名单里不出现完整 QQ 或邮箱 */
    protected function displayName($u): string
    {
        return user_display_name($u);   // v5.19.0：与聊天室在线名单共用一份口径
    }

    // 撞车比赛时间点（公开：仅显示未开始的，最多 3 场）
    public function races()
    {
        // 顺便清理已过期的场次
        RaceEvent::where('race_time', '<=', date('Y-m-d H:i:s'))->delete();

        $q = RaceEvent::where('race_time', '>', date('Y-m-d H:i:s'))
            ->order('race_time', 'asc');
        $q = GameSide::scope($q, 'race');
        $list = $q->limit(3)->select();

        return api_ok(['list' => $list, 'server_time' => date('Y-m-d H:i:s')]);
    }

    // 自建房比赛日程（v3.9.6 公开：仅显示未开始的，最多 3 场）
    public function customRaces()
    {
        // 顺便清理已过期的场次
        CustomRaceEvent::where('race_time', '<=', date('Y-m-d H:i:s'))->delete();

        $q = CustomRaceEvent::where('race_time', '>', date('Y-m-d H:i:s'))
            ->order('race_time', 'asc');
        $q = GameSide::scope($q, 'custom_race');
        $list = $q->limit(3)->select();

        return api_ok(['list' => $list, 'server_time' => date('Y-m-d H:i:s')]);
    }
}
