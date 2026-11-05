<?php
declare (strict_types = 1);

namespace app\controller;

use app\model\ChatDmMessage;
use app\service\Block;
use app\service\Setting;
use app\service\TenantContext;
use think\facade\Cache;
use think\facade\Db;

/**
 * 一对一私聊（v5.19.0 上线，v5.30.1 放开到跨队）
 *
 * 可见性口径（与需求方确认后锁死）：
 *   - 正文只有当事双方可读。私聊治理整体搬到平台侧（v5.30.1），
 *     战队队长/管理员这一侧一个字都读不到正文；平台超管也必须先有一条举报挂在会话上才展开。
 *   - 因此这张表不进 DataTransfer 导出、不进隐身巡查白名单，也不给任何"按会话查别人消息"的入口。
 *   - v5.30.1 起不限同队：users.id 本来就全站唯一，pair_key 是「小id-大id」，
 *     所以跨队会话仍然落同一行、同一份游标，只是查询不能再带 tenant_id
 *     （消息行上的 tenant_id 只表示「写这条的人当时属于哪队」）。
 *   - 双层开关：本站 settings.dm_enabled（队长统一关）× chat_dm_prefs.allow_dm（成员自己关），
 *     两个都要"允许"才发得出去；跨队还多一层 chat_dm_prefs.allow_stranger（是否让外队的人先开口）。
 *     三个开关从 v5.30.1 起默认都是开的。
 *   - 防骚扰内置、关不掉：对方没回复你之前最多发 1 条，只对外队的人生效（同队豁免）。
 *   - 黑名单（app/service/Block.php）双向拦截：任一方把对方拉黑了就发不出去。
 */
class ChatDm extends AuthBase
{
    /** 与聊天室同口径：正文最长 500 字 */
    const CONTENT_MAX = 500;
    /** 同一账号 2 秒内最多 1 条（沿用 chat_rate_ 的节流风格，键名分开，两边互不影响） */
    const SEND_TTL = 2;
    /** 会话列表最多扫多少个会话 */
    const SCAN = 30;
    /** 一次轮询最多带回多少条 */
    const PAGE = 100;
    /** 选人/搜索一次最多返回多少人 */
    const PICK = 30;
    /** 防骚扰：对方回复之前最多能发几条（内置，没有开关） */
    const STRANGER_FIRST = 1;

    /** 会话键：小的在前，保证 A→B 与 B→A 落在同一个会话 */
    public static function pairKey(int $a, int $b): string
    {
        return min($a, $b) . '-' . max($a, $b);
    }

    /** 本站是否开放私聊（默认开放；队长在管理中心一键关掉） */
    public static function teamEnabled(): bool
    {
        return Setting::get('dm_enabled', '1') !== '0';
    }

    /**
     * 某账号是否接受私聊：没有记录 = 允许（只有改过才落一行）。
     * 按 user_id 查、不带 tenant_id —— 跨队时要能查到外队那个人那一行，
     * 而 users.id 全站唯一，所以「一个人一行」这个前提本来就够。
     */
    public static function allowsDm(int $userId): bool
    {
        $row = self::pref($userId);
        return !$row || (int)$row['allow_dm'] === 1;
    }

    /** 某账号是否允许其它战队的人先开口（v5.30.1，默认允许） */
    public static function allowsStranger(int $userId): bool
    {
        $row = self::pref($userId);
        return !$row || (int)$row['allow_stranger'] !== 0;
    }

    /** 读一个人的私聊偏好行（请求内缓存：一次会话列表要问几十个人，别问几十次库） */
    protected static function pref(int $userId): ?array
    {
        static $cache = [];
        if (!isset($cache[$userId])) {
            $cache[$userId] = Db::name('chat_dm_prefs')->where('user_id', $userId)->find() ?: null;
        }
        return $cache[$userId];
    }

    // ==================== 成员侧 ====================

    /**
     * 我的会话列表：对方是谁、在不在线、多少条没读、最后一条的摘要。
     * 摘要只给本人看（这就是他自己的对话），治理侧那个接口不会返回任何正文。
     * 被我拉黑的人那一段整个不出现在这里。
     */
    public function conversations()
    {
        $me  = current_user();
        $tid = TenantContext::id();
        $mid = (int)$me->id;

        $pairs = Db::name('chat_dm_messages')
            ->where(function ($q) use ($mid) {
                $q->where('from_user_id', $mid)->whereOr('to_user_id', $mid);
            })
            ->field('pair_key, MAX(id) AS last_id, COUNT(*) AS cnt')
            ->group('pair_key')
            ->order('last_id', 'desc')
            ->limit(self::SCAN)
            ->select()
            ->toArray();

        if (!$pairs) {
            return api_ok(['list' => [], 'online_count' => $this->onlineCount($tid),
                'online_window' => Dashboard::ONLINE_WINDOW, 'dm_enabled' => self::teamEnabled() ? 1 : 0]);
        }

        $peerIds = [];
        foreach ($pairs as $p) {
            $ids = explode('-', (string)$p['pair_key']);
            foreach ($ids as $id) {
                $id = (int)$id;
                if ($id !== $mid) { $peerIds[] = $id; }
            }
        }
        $peerIds = array_values(array_unique($peerIds));
        $peers = $peerIds
            ? Db::name('users')->whereIn('id', $peerIds)->column('id,username,nickname,tenant_id,status,role,last_active_at', 'id')
            : [];

        // 未读一次算完：相关子查询比"每个会话数一遍"少掉几十个往返。
        // 表名走模型的 getTable()，这样将来若真给表加了 DB_PREFIX，这段裸 SQL 也不会突然找不到表
        $tMsg  = (string)(new ChatDmMessage())->getTable();
        $tRead = (string)(new \app\model\ChatDmRead())->getTable();
        $unreadSql = 'SELECT m.from_user_id AS peer_id, COUNT(*) AS c'
            . ' FROM ' . $tMsg . ' m'
            . ' WHERE m.to_user_id = ? AND m.recalled = 0'
            . ' AND m.id > COALESCE((SELECT r.last_read_id FROM ' . $tRead . ' r'
            . '   WHERE r.tenant_id = ? AND r.user_id = ? AND r.peer_id = m.from_user_id), 0)'
            . ' GROUP BY m.from_user_id';
        $unread = [];
        foreach (Db::query($unreadSql, [$mid, $tid, $mid]) as $u) {
            $unread[(int)$u['peer_id']] = (int)$u['c'];
        }

        $lastIds = array_column($pairs, 'last_id');
        $lastMsgs = Db::name('chat_dm_messages')->whereIn('id', $lastIds)
            ->column('content,recalled,from_user_id,created_at', 'id');

        $canFull = admin_can('qq_full');
        $admin   = is_admin();
        // 最后一次活跃时间只给持「查看成员名单」权限的人（队长/被授权的副管理）；普通成员只看在线/离线
        $canActive = $admin && admin_can('member_view');
        $online  = date('Y-m-d H:i:s', time() - Dashboard::ONLINE_WINDOW);
        $blocked = Block::ids($mid);
        $teams   = $this->teamNames();
        $list    = [];
        foreach ($pairs as $p) {
            $ids = array_map('intval', explode('-', (string)$p['pair_key']));
            $peerId = $ids[0] === $mid ? $ids[1] : $ids[0];
            if (in_array($peerId, $blocked, true)) {
                continue;                                   // 我拉黑的人：这段会话从我的列表里整个消失
            }
            $u = $peers[$peerId] ?? null;
            if (!$u) { continue; }                          // 账号已删：不再列出这个会话
            $m = $lastMsgs[(int)$p['last_id']] ?? null;
            $qq   = (string)$u['username'];
            $same = (int)$u['tenant_id'] === $tid;
            $list[] = [
                'peer_id'     => $peerId,
                'peer_nick'   => (string)$u['nickname'],
                // 外队的人：QQ 号一个数字都不出（同队仍沿用原来那三档打码口径）
                'peer_qq'     => $same
                    ? ($canFull ? $qq : ($admin ? mask_qq($qq) : '****' . substr($qq, -4)))
                    : '',
                'peer_name'   => user_display_name($u),
                'same_team'   => $same ? 1 : 0,
                'peer_team'   => $same ? '' : (string)($teams[(int)$u['tenant_id']] ?? '其它战队'),
                'peer_online' => (string)$u['last_active_at'] !== '' && (string)$u['last_active_at'] > $online ? 1 : 0,
                'peer_last_active' => $canActive ? (string)$u['last_active_at'] : null,
                'peer_status' => (string)$u['status'],
                'msgs'        => (int)$p['cnt'],
                'unread'      => $unread[$peerId] ?? 0,
                'last_at'     => $m['created_at'] ?? null,
                // 被撤回的最后一条不给摘要，只标"已撤回"
                'last_snip'   => ($m && (int)$m['recalled'] === 0) ? mb_substr((string)$m['content'], 0, 40) : '',
                'last_mine'   => $m ? ((int)$m['from_user_id'] === $mid ? 1 : 0) : 0,
            ];
        }

        return api_ok(['list' => $list, 'online_count' => $this->onlineCount($tid),
            'online_window' => Dashboard::ONLINE_WINDOW, 'dm_enabled' => self::teamEnabled() ? 1 : 0]);
    }

    /**
     * 与某个人的对话。after_id=0 取最近 50 条，否则增量。
     * 打开即已读：把游标推到本次看到的最大 id，未读角标随之归零。
     * 跨队也能打开（v5.30.1）；被我拉黑的人这段打不开。
     */
    public function thread()
    {
        $me  = current_user();
        $tid = TenantContext::id();
        $mid = (int)$me->id;
        $peerId = (int)$this->request->get('with', 0);
        if ($peerId <= 0 || $peerId === $mid) {
            return api_err('要私聊的对象不对');
        }
        $peer = Db::name('users')->where('id', $peerId)->find();
        if (!$peer || (string)$peer['status'] !== 'approved') {
            return api_err('没有这个人', 404, 404);         // 「不存在」和「没通过审核」合成一句
        }
        if (Block::has($mid, $peerId)) {
            return api_err('这个人已被你拉进黑名单，先放出黑名单才能看这段对话', 403, 403);
        }

        $same   = (int)$peer['tenant_id'] === $tid;
        $pair   = self::pairKey($mid, $peerId);
        $afterId = (int)$this->request->get('after_id', 0);
        $base = Db::name('chat_dm_messages')
            ->where('pair_key', $pair)
            ->where('recalled', 0);

        if ($afterId > 0) {
            $rows = $base->where('id', '>', $afterId)->order('id', 'asc')->limit(self::PAGE)->select()->toArray();
        } else {
            $rows = array_reverse($base->order('id', 'desc')->limit(50)->select()->toArray());
        }

        $canFull = admin_can('qq_full');
        $admin   = is_admin();
        $qq      = (string)$peer['username'];
        $list = [];
        foreach ($rows as $r) {
            $mine = (int)$r['from_user_id'] === $mid;
            $list[] = [
                'id'         => (int)$r['id'],
                'from'       => (int)$r['from_user_id'],
                'mine'       => $mine ? 1 : 0,
                'content'    => (string)$r['content'],
                'created_at' => $r['created_at'],
                // 自己的 QQ 号始终全给；对方的沿用聊天室那套打码档位，外队的人一律不给
                'from_qq'    => $mine ? (string)$me->username
                    : ($same ? ($canFull ? $qq : ($admin ? mask_qq($qq) : '****' . substr($qq, -4))) : ''),
            ];
        }

        if ($rows) {
            $this->touchRead($tid, $mid, $peerId, (int)end($rows)['id']);
        }

        return api_ok([
            'list'  => $list,
            'peer'  => ['id' => (int)$peer['id'], 'nickname' => (string)$peer['nickname'],
                'name' => user_display_name($peer),
                'same_team' => $same ? 1 : 0,
                'peer_team' => $same ? '' : (string)($this->teamNames()[(int)$peer['tenant_id']] ?? '其它战队'),
                'online' => $this->isOnline((string)$peer['last_active_at']) ? 1 : 0,
                // 同上：最后活跃时间只对持 member_view 的管理员出现，普通成员拿到的是 null
                'last_active' => (is_admin() && admin_can('member_view'))
                    ? (string)$peer['last_active_at'] : null,
                'allow_dm' => self::allowsDm($peerId) ? 1 : 0,
                'allow_stranger' => self::allowsStranger($peerId) ? 1 : 0,
                'blocked_by_me' => 0,        // 走到这里必然没拉黑（上面已经拦过一次）
                'blocked_me'    => Block::blockedMe($mid, $peerId) ? 1 : 0,
            ],
            'me_id' => $mid,
            'dm_enabled' => self::teamEnabled() ? 1 : 0,
            'me_allow'   => self::allowsDm($mid) ? 1 : 0,
            'stranger_limit' => self::strangerLimitNotice($mid, (int)$peer['id'], $same, $pair),
        ], 'ok');
    }

    /** 发一条私聊 */
    public function send()
    {
        $me  = current_user();
        $tid = TenantContext::id();
        $mid = (int)$me->id;
        $to  = (int)$this->request->post('to', 0);

        if ($to <= 0 || $to === $mid) {
            return api_err('要私聊的对象不对');
        }
        if (!self::teamEnabled()) {
            return api_err('本站已关闭私聊功能', 403, 403);
        }
        if ((int)$me->muted === 1) {
            return api_err('你已被管理员禁言，暂时不能发言', 403, 403);
        }

        $rateKey = 'dm_rate_' . $mid;
        if (Cache::has($rateKey)) {
            return api_err('发言过于频繁，请稍后再试', 429, 429);
        }

        // 跨队（v5.30.1）：按全局 users.id 找人，不再限定本队
        $peer = Db::name('users')->where('id', $to)->find();
        if (!$peer) {
            return api_err('没有这个人', 404, 404);
        }
        if ((string)$peer['status'] !== 'approved') {
            return api_err('对方账号当前不可用');
        }
        if (Block::has($mid, $to)) {
            return api_err('你把对方拉进了黑名单，先放出黑名单才能私聊', 403, 403);
        }
        if (Block::blockedMe($mid, $to)) {
            return api_err('对方不接受你的消息', 403, 403);       // 不回显"你被拉黑了"这种话
        }
        if (!self::allowsDm($to)) {
            return api_err('对方设置了不接受私聊', 403, 403);
        }

        $content = trim((string)$this->request->post('content', ''));
        if ($content === '') {
            return api_err('消息不能为空');
        }
        if (mb_strlen($content) > self::CONTENT_MAX) {
            return api_err('消息最长 ' . self::CONTENT_MAX . ' 字');
        }

        $same = (int)$peer['tenant_id'] === $tid;
        $pair = self::pairKey($mid, $to);
        if (!$same) {
            // 陌生人这一头要不要开，看对方自己的开关；已经聊起来的（双方都说过话）不再回头看这一档
            if (!self::hasAnyMessage($pair) && !self::allowsStranger($to)) {
                return api_err('对方不接受其它战队的人先发起私聊', 403, 403);
            }
            $blocked = self::strangerBlocked($mid, $to, $pair);
            if ($blocked !== null) {
                return api_err($blocked, 403, 403);
            }
        }

        $msg = ChatDmMessage::create([
            'from_user_id' => $mid,
            'to_user_id'   => $to,
            'pair_key'     => $pair,
            'content'      => $content,
        ]);
        Cache::set($rateKey, 1, self::SEND_TTL);

        return api_ok(['id' => (int)$msg->id, 'to' => $to, 'content' => $content,
            'created_at' => $msg->created_at, 'mine' => 1,
            'from_qq' => (string)$me->username], '发送成功');
    }

    /** 撤回自己发的那一条（管理员不能撤别人的私聊——那是双方之间的事） */
    public function recall()
    {
        $me = current_user();
        $id = (int)$this->request->post('id', 0);
        // 消息行上的 tenant_id 是"写这条的人当时属于哪队"，撤回只认 from_user_id，不带租户查
        $row = Db::name('chat_dm_messages')->where('id', $id)->find();
        if (!$row) {
            return api_err('消息不存在', 404);
        }
        if ((int)$row['from_user_id'] !== (int)$me->id) {
            return api_err('只能撤回自己发的私聊', 403, 403);
        }
        Db::name('chat_dm_messages')->where('id', $id)->update([
            'recalled' => 1, 'recalled_at' => date('Y-m-d H:i:s')]);
        return api_ok([], '已撤回');
    }

    /**
     * 「接受私聊」与「允许其它战队的人先开口」：本人账号设置，不需要权限键。
     * 两个键可以只传一个，另一个不动。
     */
    public function mySetting()
    {
        $me  = current_user();
        $tid = TenantContext::id();
        $h   = $this->request->post();
        $row = Db::name('chat_dm_prefs')->where('user_id', (int)$me->id)->find();

        $data = ['updated_at' => date('Y-m-d H:i:s')];
        if (isset($h['allow_dm'])) {
            $data['allow_dm'] = (int)$h['allow_dm'] === 1 ? 1 : 0;
        }
        if (isset($h['allow_stranger'])) {
            $data['allow_stranger'] = (int)$h['allow_stranger'] === 1 ? 1 : 0;
        }
        if (count($data) === 1) {
            return api_err('没说要改哪个开关');
        }
        if ($row) {
            Db::name('chat_dm_prefs')->where('id', (int)$row['id'])->update($data);
        } else {
            Db::name('chat_dm_prefs')->insert(array_merge([
                'tenant_id' => $tid, 'user_id' => (int)$me->id,
                'allow_dm' => 1, 'allow_stranger' => 1,
            ], $data));
        }
        $tip = [];
        if (isset($data['allow_dm'])) {
            $tip[] = $data['allow_dm'] ? '接受私聊' : '已关掉接受私聊';
        }
        if (isset($data['allow_stranger'])) {
            $tip[] = $data['allow_stranger'] ? '允许其它战队的人先开口' : '已关掉其它战队的人先开口';
        }
        return api_ok($data, '已保存：' . implode('、', $tip));
    }

    /** 我的两个开关当前状态（个人中心那两条要用，不走整份 /api/auth/me） */
    public function mySettings()
    {
        $me = current_user();
        return api_ok([
            'allow_dm'       => self::allowsDm((int)$me->id) ? 1 : 0,
            'allow_stranger' => self::allowsStranger((int)$me->id) ? 1 : 0,
            'dm_enabled'     => self::teamEnabled() ? 1 : 0,
        ]);
    }

    /**
     * 举报一条私聊消息 —— 这是平台超管能看到正文的唯一钥匙（v5.30.1 起战队侧没有这个入口）。
     * 只有当事一方能举报；举报理由里不许塞正文（长度截断，落库的是投诉内容不是对话内容）。
     */
    public function report()
    {
        $me  = current_user();
        $tid = TenantContext::id();
        $mid = (int)$me->id;
        $msgId = (int)$this->request->post('message_id', 0);
        $reason = trim((string)$this->request->post('reason', ''));
        if ($reason === '' || mb_strlen($reason) > 200) {
            return api_err('请填写举报理由（最多 200 字）');
        }

        $row = Db::name('chat_dm_messages')->where('id', $msgId)->find();
        if (!$row) {
            return api_err('消息不存在', 404);
        }
        if ((int)$row['from_user_id'] !== $mid && (int)$row['to_user_id'] !== $mid) {
            return api_err('只能举报自己参与的对话', 403, 403);   // 顺手把"举报别人的私聊"这条路堵死
        }

        $peerId = (int)$row['from_user_id'] === $mid ? (int)$row['to_user_id'] : (int)$row['from_user_id'];
        $exist = Db::name('chat_reports')->where('tenant_id', $tid)->where('message_id', $msgId)->find();
        if ($exist) {
            return api_err('这条已经举报过了，请等平台处理');
        }
        Db::name('chat_reports')->insert([
            'tenant_id'   => $tid,
            'pair_key'    => (string)$row['pair_key'],
            'message_id'  => $msgId,
            'reporter_id' => $mid,
            'peer_id'     => $peerId,
            'reason'      => $reason,
            'status'      => 'pending',
            'created_at'  => date('Y-m-d H:i:s'),
        ]);
        admin_log('私聊举报', '举报私聊消息 #' . $msgId . '（会话 ' . $row['pair_key'] . '），等待平台处理');
        return api_ok([], '已提交举报，只有平台超级管理员能看到这段对话');
    }

    /**
     * 「可以找谁聊」名单：默认给本队已通过的成员（人少、点两下就到）；
     * 带 q（≥2 字）时才跨队搜昵称——跨队搜索按昵称模糊匹配，最多 30 人，
     * 一个 QQ 号、一封邮箱都不出，免得这张名单变成全站账号查询器。
     */
    public function candidates()
    {
        $me  = current_user();
        $tid = TenantContext::id();
        $mid = (int)$me->id;
        $cut = date('Y-m-d H:i:s', time() - Dashboard::ONLINE_WINDOW);
        $q   = trim((string)$this->request->get('q', ''));

        $base = Db::name('users')->where('status', 'approved')->where('id', '<>', $mid);
        if ($q !== '') {
            if (mb_strlen($q) < 2) {
                return api_err('搜索至少要两个字');
            }
            // 跨队搜：昵称模糊，别的字段一律不参与（QQ 号、邮箱、IP 都不给探测）
            $rows = $base->whereLike('nickname', '%' . $q . '%')
                ->order('last_active_at', 'desc')->limit(self::PICK)
                ->field('id, nickname, tenant_id, last_active_at')->select()->toArray();
        } else {
            $rows = $base->where('tenant_id', $tid)
                ->order('last_active_at', 'desc')->limit(200)
                ->field('id, nickname, tenant_id, last_active_at')->select()->toArray();
        }

        $blocked = Block::ids($mid);
        $teams   = $this->teamNames();
        $list = [];
        foreach ($rows as $u) {
            $id   = (int)$u['id'];
            $same = (int)$u['tenant_id'] === $tid;
            if (in_array($id, $blocked, true)) {
                continue;                                   // 我拉黑的人不出现在选人列表里
            }
            if (!$same && mb_strlen((string)$u['nickname']) === 0) {
                continue;                                   // 外队且没填昵称的人：只有打码账号，不给当搜索结果
            }
            $list[] = [
                'id'        => $id,
                'name'      => user_display_name($u),
                'same_team' => $same ? 1 : 0,
                'team'      => $same ? '' : (string)($teams[(int)$u['tenant_id']] ?? '其它战队'),
                'online'    => (string)$u['last_active_at'] !== '' && (string)$u['last_active_at'] > $cut ? 1 : 0,
                'open_dm'   => (self::allowsDm($id) && self::allowsStranger($id)) || $same ? 1 : 0,
            ];
        }
        return api_ok([
            'list'            => $list,
            'dm_enabled'      => self::teamEnabled() ? 1 : 0,
            'allow_me'        => self::allowsDm($mid) ? 1 : 0,
            'allow_stranger'  => self::allowsStranger($mid) ? 1 : 0,
            'searching'       => $q !== '' ? 1 : 0,
        ]);
    }

    // ==================== 私有 ====================

    /** 战队 id => 队名（跨队会话与大厅都要显示"这人哪队的"，一次查完） */
    protected function teamNames(): array
    {
        static $names = null;
        if ($names === null) {
            $names = Db::name('tenants')->column('name', 'id');
        }
        return $names;
    }

    /** 这段会话里说过话没有（防骚扰与「先开口」都看这一个事实） */
    protected static function hasAnyMessage(string $pair): bool
    {
        return (int)Db::name('chat_dm_messages')->where('pair_key', $pair)->count() > 0;
    }

    /**
     * 防骚扰（内置，没有开关）：外队的人，对方没主动回复你之前最多发 1 条。
     * 同队豁免。算的是"我发过几条"，撤回的那条也算 —— 不然发一条撤一条就能无限刷。
     */
    protected static function strangerBlocked(int $mid, int $peerId, string $pair): ?string
    {
        $peerTalked = (int)Db::name('chat_dm_messages')
            ->where('pair_key', $pair)->where('from_user_id', $peerId)->count();
        if ($peerTalked > 0) {
            return null;                                  // 对方开过口，这一档就解除了
        }
        $mine = (int)Db::name('chat_dm_messages')
            ->where('pair_key', $pair)->where('from_user_id', $mid)->count();
        return $mine >= self::STRANGER_FIRST
            ? '对方还没回复你，先最多发 ' . self::STRANGER_FIRST . ' 条（这条防骚扰是内置的，谁也关不掉）'
            : null;
    }

    /** 打开会话时顺手告诉前端还剩几条额度（不发消息也让人看清为什么按钮会拒绝） */
    protected static function strangerLimitNotice(int $mid, int $peerId, bool $same, string $pair)
    {
        if ($same) {
            return null;
        }
        $peerTalked = (int)Db::name('chat_dm_messages')
            ->where('pair_key', $pair)->where('from_user_id', $peerId)->count();
        if ($peerTalked > 0) {
            return null;
        }
        $mine = (int)Db::name('chat_dm_messages')
            ->where('pair_key', $pair)->where('from_user_id', $mid)->count();
        return max(0, self::STRANGER_FIRST - $mine);
    }

    /** 本站在线人数：与仪表盘同一份口径（同一张表、同一个窗口） */
    protected function onlineCount(int $tid): int
    {
        return (int)Db::name('users')
            ->where('tenant_id', $tid)
            ->where('status', 'approved')
            ->where('last_active_at', '>', date('Y-m-d H:i:s', time() - Dashboard::ONLINE_WINDOW))
            ->count();
    }

    protected function isOnline(string $lastActive): bool
    {
        return $lastActive !== '' && $lastActive > date('Y-m-d H:i:s', time() - Dashboard::ONLINE_WINDOW);
    }

    /** 推已读游标（有则改，无则加；uk_dm_read 保证一人一对方只有一行） */
    protected function touchRead(int $tid, int $uid, int $peerId, int $lastReadId): void
    {
        if ($lastReadId <= 0) {
            return;
        }
        $row = Db::name('chat_dm_reads')->where('tenant_id', $tid)
            ->where('user_id', $uid)->where('peer_id', $peerId)->find();
        if ($row) {
            if ((int)$row['last_read_id'] < $lastReadId) {
                Db::name('chat_dm_reads')->where('id', (int)$row['id'])->update(
                    ['last_read_id' => $lastReadId, 'updated_at' => date('Y-m-d H:i:s')]);
            }
            return;
        }
        Db::name('chat_dm_reads')->insert([
            'tenant_id' => $tid, 'user_id' => $uid, 'peer_id' => $peerId,
            'last_read_id' => $lastReadId, 'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }
}
