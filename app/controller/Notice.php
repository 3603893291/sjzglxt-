<?php
declare(strict_types=1);

namespace app\controller;

use app\model\Announcement;
use app\model\AnnouncementRead;
use app\model\User;
use app\service\GameSide;

/**
 * 公告送达确认（v5.6.0）
 * 成员端：未读角标 / 打开即已读 / 被提醒置顶
 * 管理端：回执统计（已读名单/未读名单）/ 一键提醒未读（announce 权限）
 */
class Notice extends AuthBase
{
    // ---------- 成员端 ----------

    // 成员端公告列表：未读标记 + 被提醒置顶（提醒过的排最前，其余按 id 倒序）
    public function index()
    {
        $user = current_user();
        if (!$user) {
            return api_err('未登录', 401, 401);
        }
        // v5.36.34：按「真正发出来的那一刻」排 —— 定时公告用 scheduled_at，其余用建行时间。
        // 不然一条昨天写好、今天 20:00 才发的公告，会排在今天 10:00 那条下面（建行 id 是昨天的）
        $q = Announcement::orderRaw('COALESCE(scheduled_at, created_at) DESC, id DESC');
        // v5.36.34：定时未到点的、已撤回的都不给成员看（在 SQL 层挡，不能让 limit(50) 里躺着看不见的行）
        $q = Announcement::aliveOnly($q);
        $q = GameSide::scope($q, 'announce');   // v5.36.0：分端时只看本端 + 全队共享
        $list = $q->limit(50)->select()->toArray();
        if (!$list) {
            return api_ok(['list' => [], 'unread_count' => 0]);
        }
        $ids    = array_column($list, 'id');
        $readAt = AnnouncementRead::whereIn('announcement_id', $ids)
            ->where('user_id', $user->id)->column('id', 'announcement_id');
        // 被提醒：公告已点过「一键提醒」且本人尚未读（提醒时间晚于本人已读时间视为再次提醒）
        $unread = 0;
        foreach ($list as &$row) {
            $sched = trim((string)($row['scheduled_at'] ?? ''));
            $row['publish_at'] = $sched !== '' ? $sched : (string)($row['created_at'] ?? '');
            $row['is_read']   = isset($readAt[$row['id']]);
            $row['reminded']  = !$row['is_read'] && !empty($row['reminded_at']);
            if (!$row['is_read']) {
                $unread++;
            }
        }
        unset($row);
        // 被提醒的置顶
        usort($list, function ($a, $b) {
            if ($a['reminded'] !== $b['reminded']) {
                return $a['reminded'] ? -1 : 1;
            }
            $pa = (string)($a['publish_at'] ?? '');
            $pb = (string)($b['publish_at'] ?? '');
            if ($pa !== $pb) {
                return $pa < $pb ? 1 : -1;
            }
            return $b['id'] <=> $a['id'];
        });
        return api_ok(['list' => $list, 'unread_count' => $unread]);
    }

    // 打开公告详情即标记已读（简单可靠，不要求停留时长）
    public function read($id)
    {
        $user = current_user();
        if (!$user) {
            return api_err('未登录', 401, 401);
        }
        $ann = Announcement::find((int)$id);
        // v5.36.34：未到点的定时公告、已撤回的公告都走同一条 404 ——
        // 否则成员手里攥着旧链接就能给一条自己根本看不见的公告标已读，回执的分子被污染
        if (!$ann || !Announcement::isLive($ann) || !GameSide::canSee('announce', (string)$ann->game_side)) {
            return api_err('公告不存在', 404);
        }
        $exists = AnnouncementRead::where('announcement_id', $ann->id)
            ->where('user_id', $user->id)->find();
        if (!$exists) {
            AnnouncementRead::create([
                'announcement_id' => $ann->id,
                'user_id'         => $user->id,
                'reminded'        => !empty($ann->reminded_at) ? 1 : 0,
            ]);
        }
        return api_ok([], '已标记已读');
    }

    // ---------- 管理端（沿用 announce 权限） ----------

    private function noPerm()
    {
        return api_err('您没有权限执行此操作，请联系超级管理员分配权限', 403, 403);
    }

    // 回执统计：已读人数/总人数 + 已读名单 + 未读名单
    public function receipt($id)
    {
        if (!admin_can('announce')) {
            return $this->noPerm();
        }
        $ann = Announcement::find((int)$id);
        if (!$ann) {
            return api_err('公告不存在', 404);
        }
        // 应读总数：正常在册成员（被删除/封禁不计入）。
        // v5.36.0：这条公告只给某一端时，另一端的成员本来就不该收到，也不算未读。
        $mq = User::where('status', 'approved');
        if (GameSide::moduleOn('announce') && (string)$ann->game_side !== '') {
            $mq->whereIn('game_side', [(string)$ann->game_side, '']);
        }
        $members = $mq->field('id, username, nickname')->select()->toArray();
        $reads = AnnouncementRead::where('announcement_id', $ann->id)
            ->field('user_id, reminded, created_at')->select()->toArray();
        $readMap = array_column($reads, null, 'user_id');

        $readList = $unreadList = [];
        foreach ($members as $m) {
            $item = ['id' => $m['id'], 'nickname' => $m['nickname'] ?: $m['username']];
            if (isset($readMap[$m['id']])) {
                $item['read_at']  = $readMap[$m['id']]['created_at'];
                $item['reminded'] = (int)$readMap[$m['id']]['reminded'];
                $readList[] = $item;
            } else {
                $unreadList[] = $item;
            }
        }
        return api_ok([
            'announcement_id' => $ann->id,
            'need_receipt'    => (int)$ann->need_receipt,
            'deadline'        => $ann->deadline,
            'reminded_at'     => $ann->reminded_at,
            'total'           => count($members),
            'read_count'      => count($readList),
            'read_list'       => $readList,
            'unread_list'     => $unreadList,
        ]);
    }

    // 一键提醒未读：标记公告提醒时间 → 对方未读角标变强提醒 + 列表置顶
    public function remind($id)
    {
        if (!admin_can('announce')) {
            return $this->noPerm();
        }
        $ann = Announcement::find((int)$id);
        if (!$ann) {
            return api_err('公告不存在', 404);
        }
        if (!$ann->need_receipt) {
            return api_err('该公告未开启已读回执，无需提醒');
        }
        // v5.36.34：成员现在根本看不见的公告，催读只会催出一片「点了没东西」
        if (!Announcement::isLive($ann)) {
            return api_err(Announcement::stateOf($ann) === 'revoked'
                ? '这条公告已撤回，成员看不到，先用不上了'
                : '这条公告还没到发布时间，到点了再催');
        }
        $ann->save(['reminded_at' => date('Y-m-d H:i:s')]);
        admin_log('公告管理', '提醒未读成员查看公告 #' . $ann->id . ' ' . $ann->title);
        return api_ok([], '已向未读成员发出提醒');
    }
}
