<?php
declare (strict_types = 1);

namespace app\controller;

use app\BaseController;
use app\service\UserNotice;
use app\service\Setting;
use think\facade\Db;

/**
 * 私聊·平台治理（v5.30.1 起，v5.32.0 全站大厅下线后只剩私聊）：只有平台超级管理员能用
 *
 *   GET  /api/platform/admin/chat/reports        私聊举报列表（跨队，可按状态筛）
 *   POST /api/platform/admin/chat/report/open    顺着一条举报展开那段对话的正文（每次读都留痕）
 *   POST /api/platform/admin/chat/report/handle  结案：handled 已处理 / dismissed 不成立，结果回举报人
 *   POST /api/platform/admin/mute                下发禁言：users.muted=1 + muted_by=platform
 *   POST /api/platform/admin/unmute              解除：只有给这条禁言的一方（平台）能解
 *   GET  /api/platform/admin/mute-state          某个人现在被谁禁着、平台这条能不能解
 *   GET  /api/platform/admin/mutes               平台自己下的那批禁言名单（v5.32.0 补的，见 mutes()）
 *
 * 三条口径：
 *   ① 这一版把私聊治理从战队侧整体搬到这里：战队管理中心的「私聊治理」页签已经撤掉，
 *      队长既读不到正文，也解不了平台给的禁言（users.muted_by='platform' 那几条）。
 *      队长仍可自设本队禁言（muted_by='team'），那条仍由本队自己解。
 *   ② 私聊正文只有挂着举报才读得到，每读一次往 admin_logs(tenant_id=0) 写一行，可复盘；
 *      举报没落下来之前，平台侧也只看得到条数与时间这类元数据。
 *   ③ 结案必须写一句结果说明，这句会作为通知回给举报人 —— 「举报了就石沉大海」是这套机制最不能留的样子。
 */
class PlatformChat extends BaseController
{
    private const PAGE_MAX = 50;
    /** 一次展开最多读多少条私聊 */
    private const OPEN_LIMIT = 100;
    /** 禁言名单一次最多列多少人（真被平台禁到 200 人，这套治理就已经不是靠这一页了） */
    private const MUTE_MAX = 200;

    /** @return \think\Response|null 不是平台超管就返回这个 403 */
    private function gate()
    {
        if (!function_exists('is_platform_super') || !\is_platform_super()) {
            return api_err('需要平台超级管理员登录态', 403, 403);
        }
        return null;
    }

    private function actor(): array
    {
        $u = \current_platform_user();
        if (!$u) {
            return [0, '平台超管'];
        }
        return [(int)$u->id, mb_substr((string)($u->username ?: $u->nickname ?: '平台超管'), 0, 64)];
    }

    /** 平台侧动作落 admin_logs(tenant_id=0)，与被处理的行互为凭据 */
    private function audit(string $action, string $detail): void
    {
        try {
            [$actorId, $actorName] = $this->actor();
            Db::name('admin_logs')->insert([
                'tenant_id'  => 0,
                'admin_id'   => $actorId,
                'admin_name' => $actorName,
                'action'     => mb_substr($action, 0, 32),
                'detail'     => mb_substr($detail, 0, 300),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            // 审计写不进去不能反过来把治理动作做一半：日志失败只影响复盘，不阻断处理
        }
    }

    /** 战队 id => 队名（跨队的行都要带上"这人哪队的"） */
    private function teams(): array
    {
        static $names = null;
        if ($names === null) {
            $names = Db::name('tenants')->column('name', 'id');
        }
        return $names;
    }

    /** 一批 users.id => 显示名 + 队名。QQ 号一律不出（这是平台侧，同样不发完整账号） */
    private function who(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if ($ids === []) {
            return [];
        }
        $rows  = Db::name('users')->whereIn('id', $ids)
            ->column('id,nickname,username,tenant_id,status,muted,muted_by', 'id');
        $teams = $this->teams();
        $out   = [];
        foreach ($ids as $id) {
            $u = $rows[$id] ?? null;
            $out[$id] = $u ? [
                'id'    => $id,
                'name'  => user_display_name($u),
                'team'  => (string)($teams[(int)$u['tenant_id']] ?? '未知战队'),
                'tid'   => (int)$u['tenant_id'],
                'state' => (string)$u['status'],
                'muted' => (int)($u['muted'] ?? 0),
                // 这条禁言是谁给的：platform 的那条这一页能解，team 的那条只能由本队自己解
                'mby'   => (string)($u['muted_by'] ?? ''),
            ] : ['id' => $id, 'name' => '已注销用户', 'team' => '', 'tid' => 0, 'state' => 'gone'];
        }
        return $out;
    }

    // ==================== 私聊举报 ====================

    /**
     * 私聊举报列表：跨所有战队。这里只有理由与当事人，正文必须走 open。
     * 待处理的条数一并返回，页签上的红点与列表永远同一个口径。
     */
    public function reports()
    {
        if ($r = $this->gate()) {
            return $r;
        }
        $status = (string)$this->request->get('status', 'pending');
        if (!in_array($status, ['pending', 'opened', 'dismissed', 'handled', 'all'], true)) {
            $status = 'pending';
        }
        $page = max(1, (int)$this->request->get('page', 1));
        $size = min(self::PAGE_MAX, max(1, (int)$this->request->get('size', 20)));

        $q = Db::name('chat_reports');
        if ($status !== 'all') {
            $q->where('status', $status);
        }
        $total = (int)(clone $q)->count();
        $rows  = $q->order('id', 'desc')->page($page, $size)->select()->toArray();

        $ids = [];
        foreach ($rows as $x) {
            $ids[] = (int)$x['reporter_id'];
            $ids[] = (int)$x['peer_id'];
        }
        $by   = $this->who($ids);
        $list = [];
        foreach ($rows as $x) {
            $anchor = Db::name('chat_dm_messages')->where('id', (int)$x['message_id'])
                ->field('id, tenant_id, recalled, created_at')->find();
            $list[] = [
                'id'          => (int)$x['id'],
                'pair_key'    => (string)$x['pair_key'],
                'message_id'  => (int)$x['message_id'],
                'reporter'    => $by[(int)$x['reporter_id']] ?? null,
                'peer'        => $by[(int)$x['peer_id']] ?? null,
                'cross_team'  => ((int)($by[(int)$x['reporter_id']]['tid'] ?? 0)
                    !== (int)($by[(int)$x['peer_id']]['tid'] ?? -1)) ? 1 : 0,
                'reason'      => (string)$x['reason'],
                'status'      => (string)$x['status'],
                'msg_alive'   => $anchor ? 1 : 0,
                'msg_at'      => $anchor['created_at'] ?? null,
                'created_at'  => (string)$x['created_at'],
                'reviewed_at' => $x['reviewed_at'],
                'result_note' => (string)$x['result_note'],
            ];
        }
        return api_ok([
            'list'   => $list,
            'total'  => $total,
            'page'   => $page,
            'size'   => $size,
            'counts' => [
                'pending'   => (int)Db::name('chat_reports')->where('status', 'pending')->count(),
                'opened'    => (int)Db::name('chat_reports')->where('status', 'opened')->count(),
                'handled'   => (int)Db::name('chat_reports')->where('status', 'handled')->count(),
                'dismissed' => (int)Db::name('chat_reports')->where('status', 'dismissed')->count(),
            ],
            'notice' => '这里是各战队的私聊举报。正文只有顺着一条举报才读得到，每次读取都记一行平台操作日志。',
        ]);
    }

    /**
     * 顺着一条举报展开那段对话 —— 这是私聊正文唯一能被治理侧读到的路径。
     * 跨队会话的行落在「写这条的人那队」，所以这里按 pair_key 取，不带租户过滤。
     */
    public function openReport()
    {
        if ($r = $this->gate()) {
            return $r;
        }
        $rid = (int)$this->request->post('id', 0);
        $rep = Db::name('chat_reports')->where('id', $rid)->find();
        if (!$rep) {
            return api_err('举报记录不存在', 404, 404);
        }
        $msgs = Db::name('chat_dm_messages')
            ->where('pair_key', (string)$rep['pair_key'])
            ->order('id', 'desc')->limit(self::OPEN_LIMIT)->select()->toArray();

        $ids = [];
        foreach ($msgs as $m) { $ids[] = (int)$m['from_user_id']; $ids[] = (int)$m['to_user_id']; }
        $by   = $this->who($ids);
        $teams = $this->teams();
        $list = [];
        foreach (array_reverse($msgs) as $m) {
            $from = (int)$m['from_user_id'];
            $list[] = [
                'id'         => (int)$m['id'],
                'from'       => $from,
                'from_name'  => $by[$from]['name'] ?? '已注销用户',
                'from_team'  => (string)($teams[$by[$from]['tid'] ?? 0] ?? ''),
                'to'         => (int)$m['to_user_id'],
                'content'    => (string)$m['content'],
                'recalled'   => (int)$m['recalled'],
                'created_at' => $m['created_at'],
                'reported'   => (int)$m['id'] === (int)$rep['message_id'] ? 1 : 0,
            ];
        }
        $now = date('Y-m-d H:i:s');
        Db::name('chat_reports')->where('id', $rid)->update([
            'status'      => (string)$rep['status'] === 'pending' ? 'opened' : (string)$rep['status'],
            'reviewed_by' => (int)($this->actor()[0]),
            'reviewed_at' => $now,
        ]);
        $this->audit('私聊正文查看', '因举报 #' . $rid . ' 展开会话 ' . $rep['pair_key']
            . '，读取 ' . count($list) . ' 条私聊（最多 ' . self::OPEN_LIMIT . ' 条）');
        return api_ok([
            'report_id' => $rid,
            'pair_key'  => (string)$rep['pair_key'],
            'reason'    => (string)$rep['reason'],
            'status'    => (string)$rep['status'] === 'pending' ? 'opened' : (string)$rep['status'],
            'list'      => $list,
        ], '已展开这段对话，本次读取已记入平台操作日志');
    }

    /**
     * 结案一条私聊举报：handled（确实处理了）/ dismissed（不成立）。
     * 必须写一句结果说明，这句回给举报人 —— 举报没有下文是这套机制最不能留的样子。
     */
    public function handleReport()
    {
        if ($r = $this->gate()) {
            return $r;
        }
        $id   = (int)$this->request->post('id', 0);
        $act  = (string)$this->request->post('action', '');
        $note = trim((string)$this->request->post('note', ''));
        if (!in_array($act, ['handled', 'dismissed'], true)) {
            return api_err('只有 handled（已处理）和 dismissed（不成立）两种结果');
        }
        if ($note === '' || mb_strlen($note) > 200) {
            return api_err('要写一句结果说明（最多 200 字），这句会回给举报人');
        }
        $rep = Db::name('chat_reports')->where('id', $id)->find();
        if (!$rep) {
            return api_err('举报记录不存在', 404, 404);
        }
        [$actorId] = $this->actor();
        Db::name('chat_reports')->where('id', $id)->update([
            'status'      => $act,
            'result_note' => $note,
            'reviewed_by' => $actorId,
            'reviewed_at' => date('Y-m-d H:i:s'),
        ]);
        UserNotice::push((int)$rep['reporter_id'], 'report', 0,
            ($act === 'handled' ? '你举报的私聊已处理：' : '这条私聊举报不成立：') . $note, $actorId);
        $this->audit('私聊举报处理', '举报 #' . $id . '（会话 ' . $rep['pair_key'] . '）→ '
            . $act . '：' . mb_substr($note, 0, 120));
        return api_ok(['id' => $id, 'status' => $act],
            $act === 'handled' ? '已结案，举报人会收到结果' : '已驳回，举报人会收到说明');
    }

    // ==================== 禁言（来源分明） ====================

    /**
     * 平台下发禁言：users.muted=1 + muted_by='platform'。
     * 这条禁言战队队长解不掉（见 Admin::setUserMute），只有这里能解。
     */
    public function mute()
    {
        if ($r = $this->gate()) {
            return $r;
        }
        $uid    = (int)$this->request->post('user_id', 0);
        $reason = trim((string)$this->request->post('reason', ''));
        if ($reason === '' || mb_strlen($reason) > 200) {
            return api_err('要写一句禁言理由（最多 200 字）');
        }
        $u = Db::name('users')->where('id', $uid)->find();
        if (!$u) {
            return api_err('没有这个账号', 404, 404);
        }
        Db::name('users')->where('id', $uid)->update(['muted' => 1, 'muted_by' => 'platform']);
        $this->audit('平台禁言', '禁言成员 #' . $uid . '（' . (string)$u['tenant_id'] . ' 队，'
            . user_display_name($u) . '）：' . mb_substr($reason, 0, 120));
        return api_ok(['user_id' => $uid, 'muted' => 1, 'muted_by' => 'platform'],
            '已禁言：他在自己队的群、跨队私聊与枪码评论里都发不了言，本队队长解不掉这条');
    }

    /** 解除平台给的禁言（muted_by=team 的那条本来就该由本队自己解，这里不越俎代庖） */
    public function unmute()
    {
        if ($r = $this->gate()) {
            return $r;
        }
        $uid = (int)$this->request->post('user_id', 0);
        $u   = Db::name('users')->where('id', $uid)->find();
        if (!$u) {
            return api_err('没有这个账号', 404, 404);
        }
        if ((int)$u['muted'] !== 1) {
            return api_err('他没被禁言');
        }
        if ((string)$u['muted_by'] !== 'platform') {
            return api_err('这条禁言是本队自己设的，请由该战队管理员解除');
        }
        Db::name('users')->where('id', $uid)->update(['muted' => 0, 'muted_by' => '']);
        $this->audit('平台解禁', '解除成员 #' . $uid . ' 的平台禁言');
        return api_ok(['user_id' => $uid, 'muted' => 0], '已解除禁言');
    }

    /** 某个人现在的禁言状态（页面上"这条是谁给的"要显示清楚） */
    public function muteState()
    {
        if ($r = $this->gate()) {
            return $r;
        }
        $uid = (int)$this->request->get('user_id', 0);
        $u   = Db::name('users')->where('id', $uid)->field('id,muted,muted_by,nickname,tenant_id')->find();
        if (!$u) {
            return api_err('没有这个账号', 404, 404);
        }
        return api_ok([
            'user_id'  => (int)$u['id'],
            'muted'    => (int)$u['muted'],
            'muted_by' => (string)$u['muted_by'],
            'can_lift' => (int)$u['muted'] === 1 && (string)$u['muted_by'] === 'platform' ? 1 : 0,
        ]);
    }

    /**
     * 平台自己下的那批禁言（muted_by='platform'）名单。
     *
     * 为什么这一版要加这一页：以前「谁还被平台禁着、怎么把人放出来」是顺路挂在全站大厅那张消息表上的
     * （每行一个解禁按钮）。大厅下线之后那张表没了，只剩下按人下发的那只手，放人得手改 SQL —— 那不叫治理。
     * 这一页只解决「看得见 + 放得掉」：理由与操作人仍然只在那一行平台操作日志里，这里不另存一份。
     * 输出只有显示名与队名，一个 QQ 号都不出（与 who() 同一套脱敏）。
     */
    public function mutes()
    {
        if ($r = $this->gate()) {
            return $r;
        }
        $rows  = Db::name('users')->where('muted', 1)->where('muted_by', 'platform')
            ->field('id, nickname, username, tenant_id, status')->order('id', 'desc')->limit(self::MUTE_MAX)
            ->select()->toArray();
        $teams = $this->teams();
        $list  = [];
        foreach ($rows as $u) {
            $list[] = [
                'id'    => (int)$u['id'],
                'name'  => user_display_name($u),
                'team'  => (string)($teams[(int)$u['tenant_id']] ?? '未知战队'),
                'state' => (string)$u['status'],
            ];
        }
        return api_ok([
            'list'   => $list,
            'total'  => count($list),
            'capped' => count($list) >= self::MUTE_MAX ? 1 : 0,
        ]);
    }

}
