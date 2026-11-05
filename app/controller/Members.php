<?php
declare (strict_types = 1);

namespace app\controller;

use app\model\PlayerStat;
use app\model\User;
use app\service\Block;
use app\service\PlayerStats;
use app\service\TenantContext;
use think\facade\Db;

/**
 * 成员名录与成员主页（v5.30.0 上线，v5.30.1 起外队的人也能点开主页）
 *
 * 这是给**普通成员**看的两块：一列自家战队的人，头像上带在线标志，点进去是 TA 的主页。
 * 管理端那份「用户管理」（/api/admin/users，要 member_view 等权限）这里一个字都不复用——
 * 那份带账号、IP、归属地、状态、封禁与截图，全都不是队员之间该看到的东西。
 *
 * 四条边界写死在这里：
 *   1. **只出 id / 显示名 / 职责标签 / 在线与否**。QQ 号、邮箱、注册 IP 与归属地、
 *      注册游戏截图一律不下发；显示名走全局 user_display_name()，没昵称的人拿到的是脱敏账号，
 *      与聊天室、仪表盘在线名单同一份口径（三处各写一遍迟早漂）。
 *   2. **只有「在线 / 离线」这个布尔，没有最后活跃时刻**。`last_active_at` 精确值仍归
 *      持 member_view 的队长与副管理（v5.26.0 定的那条，见 ChatDm::thread），这里不因为
 *      "反正只有五分钟"就顺手多发一份。
 *   3. **战绩只对「同一队 + TA 勾了 stats_public」或本人出现**。v5.30.1 主页放开到跨队，
 *      但那一档开关的名字就叫「对队友可见」——外队的人点开只能看到"TA 没对队友公开"这句话，
 *      不会因为跨队反而多看见东西。
 *   4. **能不能私聊 TA 只报结论**，判断全在 ChatDm 那一处（本站开关 × 本人开关 × 跨队还多看
 *      一层 allow_stranger × 黑名单），这里不另立一份口径。
 *
 * 本队范围内由 `User` / `PlayerStat` 上的 BelongsToTenant 结构给：名单接口查不到别队的人；
 * 主页接口（show）v5.30.1 起按全局 users.id 找人，「不存在」与「没通过审核」合成同一句话。
 */
class Members extends AuthBase
{
    /**
     * 名单上限。战队规模有限，这个数只是防脚本把一次查询变成大响应；
     * 超了会照实回 total 与 truncated，前端说清"只显示前 N 人"，不让人以为队里就这么点人。
     */
    const LIST_LIMIT = 300;


    /** 平台控制台没有"本队成员"这回事，这两条接口在平台上下文里就是不存在 */
    protected function guard()
    {
        return TenantContext::isPlatform() ? api_err('接口不存在', 404, 404) : null;
    }

    /** GET /api/members —— 本队已通过的成员，按最近活跃排（在线的自然在最前） */
    public function index()
    {
        if ($r = $this->guard()) {
            return $r;
        }
        $me  = current_user();
        $mid = (int)$me->id;
        $cut = date('Y-m-d H:i:s', time() - Dashboard::ONLINE_WINDOW);

        $rows = User::where('status', 'approved')
            ->order('last_active_at', 'desc')
            ->limit(self::LIST_LIMIT + 1)
            ->select();

        $list = [];
        foreach ($rows as $u) {
            if (count($list) >= self::LIST_LIMIT) {
                break;
            }
            $tag  = display_role_tag($u);
            $list[] = [
                'id'       => (int)$u->id,
                'name'     => user_display_name($u),
                'tag'      => $tag ? $tag['name'] : '',
                'color'    => $tag ? (string)$tag['color'] : '',
                'online'   => (string)$u->last_active_at !== '' && (string)$u->last_active_at > $cut ? 1 : 0,
                'is_admin' => (string)$u->role === 'admin' ? 1 : 0,
                'is_me'    => (int)$u->id === $mid ? 1 : 0,
            ];
        }

        return api_ok([
            'list'      => $list,
            'total'     => User::where('status', 'approved')->count(),
            'window'    => Dashboard::ONLINE_WINDOW,
            'truncated' => count($rows) > self::LIST_LIMIT ? 1 : 0,
        ]);
    }

    /**
     * GET /api/members/:id —— 单个成员的主页：身份 + 战绩（只对同队且 TA 勾了才出）
     * v5.30.1：外队的人也能点开主页，所以这里按全局 users.id 找人，不再限定本队。
     * v5.32.0：论坛下线，主页里「TA 在论坛发的」那一串跟着没了。
     */
    public function show($id)
    {
        if ($r = $this->guard()) {
            return $r;
        }
        $me  = current_user();
        $mid = (int)$me->id;
        $tid = (int)TenantContext::id();
        $uid = (int)$id;
        // 查不到只有两种可能：没这个人 / 没通过审核。合成一句话（同私聊那条口径），不拿来当探测
        $u = Db::name('users')->where('id', $uid)->where('status', 'approved')->find();
        if (!$u) {
            return api_err('没有这个人', 404, 404);
        }

        $cut  = date('Y-m-d H:i:s', time() - Dashboard::ONLINE_WINDOW);
        $same = (int)$u['tenant_id'] === $tid;
        $isMe = $uid === $mid;
        // 职责标签是各队自己的表（BelongsToTenant），外队那几个 id 拿到本队来映射会串味，所以跨队不给标签
        $tag  = $same ? display_role_tag($u) : null;
        // 战绩这一档叫「对队友可见」：跨队就是不看，连"有没有战绩行"都不透出
        $shared = $same && (int)$u['stats_public'] === 1;

        // 能不能私聊 TA：结论在这里算，判断全在 ChatDm 那一处（跨队还多看一层 allow_stranger）
        $dmOpen = $same
            ? (ChatDm::teamEnabled() && ChatDm::allowsDm($uid))
            : (ChatDm::teamEnabled() && ChatDm::allowsDm($uid) && ChatDm::allowsStranger($uid));
        $iBlocked = Block::has($mid, $uid);
        $blockedMe = Block::blockedMe($mid, $uid);

        $fields = [];
        $stat   = null;
        $upAt   = null;
        if ($shared || $isMe) {
            $row = PlayerStat::where('user_id', $uid)->find();
            if ($row) {
                $fields = PlayerStats::fields();
                $stat   = PlayerStats::metrics($row);
                $upAt   = (string)$row->updated_at;
            }
        }

        return api_ok([
            'member' => [
                'id'           => $uid,
                'name'         => user_display_name($u),
                'tag'          => $tag ? $tag['name'] : '',
                'color'        => $tag ? (string)$tag['color'] : '',
                'online'       => (string)$u['last_active_at'] !== '' && (string)$u['last_active_at'] > $cut ? 1 : 0,
                'is_admin'     => (string)$u['role'] === 'admin' ? 1 : 0,
                'joined_at'    => mb_substr((string)$u['created_at'], 0, 10),
                'stats_shared' => $shared ? 1 : 0,
                'same_team'    => $same ? 1 : 0,
                // 外队的人要看得清是哪队的：队名本来就是公开信息
                'team'         => $same ? '' : (string)(Db::name('tenants')->where('id', (int)$u['tenant_id'])->value('name') ?? '其它战队'),
                'dm_open'      => ($dmOpen && !$iBlocked && !$blockedMe) ? 1 : 0,
                'blocked_by_me' => $iBlocked ? 1 : 0,
                'blocked_me'   => $blockedMe ? 1 : 0,
                'can_block'    => (!$same && !$isMe) ? 1 : 0,   // 黑名单只拉其它战队的人，本队成员不给这个按钮
            ],
            'is_me'     => $isMe ? 1 : 0,
            // 没勾时连"有没有战绩行"都不透出：fields/stat 一起空着，前端只说"TA 没公开"
            'fields'    => $fields,
            'stat'      => $stat,
            'uploaded'  => $upAt,
        ]);
    }
}
