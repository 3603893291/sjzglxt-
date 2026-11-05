<?php
declare (strict_types = 1);

namespace app\controller;

use app\BaseController;
use app\model\AdminLog;
use app\model\Announcement;
use app\model\Blacklist;
use app\model\BlockedIp;
use app\model\ChatMessage;
use app\model\CustomRaceEvent;
use app\model\Image as ImageModel;
use app\model\RaceEvent;
use app\model\RoleTag as RoleTagModel;
use app\model\Team as TeamModel;
use app\model\User;
use app\service\GameSide;
use app\service\ImageUpload;
use app\service\TenantContext;
use think\facade\Cache;
use think\facade\Db;

/**
 * 管理中心（仅管理员）v3
 * 注册审核 / 用户管理(含禁言) / 战队小队管理 / 图片审核 /
 * 公告管理 / 撞车时间管理
 * （v3 起移除发信设置：不再发送邮件）
 */
class Admin extends AdminBase
{
    // v3.9：细粒度权限拒绝响应（配合 admin_can 使用）
    private function noPerm()
    {
        return api_err('您没有权限执行此操作，请联系超级管理员分配权限', 403, 403);
    }

    // ==================== 注册审核 ====================

    // 待审核用户列表
    public function reviewList()
    {
        if (!admin_can('approve')) {
            return $this->noPerm();
        }
        $list = User::where('status', 'pending')
            ->field('id, username, nickname, email, profile_image, reg_ip, reg_region, created_at')
            ->order('id', 'asc')
            ->select()
            ->toArray();

        // v3.9：无对应权限时 QQ 号脱敏 / 截图不下发
        $canQq  = admin_can('qq_full');
        $canImg = admin_can('screenshots');
        // v5.6.0：黑名单自动预警——申请者 QQ 命中本队黑名单时附原因（命中不自动拒绝）
        $hitQqs = Blacklist::whereIn('qq', array_column($list, 'username'))->column('reason', 'qq');
        foreach ($list as &$row) {
            $row['blacklist_reason'] = $hitQqs[(string)$row['username']] ?? '';
            if (!$canQq) {
                $row['username'] = mask_qq((string)$row['username']);
            }
            if (!$canImg) {
                $row['profile_image'] = '';
            }
        }
        unset($row);

        return api_ok(['list' => $list]);
    }

    // 处理注册审核：approve 通过 / reject 拒绝（拒绝=删除账号，可重新注册）
    public function reviewUser($id)
    {
        if (!admin_can('approve')) {
            return $this->noPerm();
        }
        $action = (string)$this->request->post('action', '');
        $user   = User::find((int)$id);

        if (!$user) {
            return api_err('用户不存在', 404);
        }
        if ($user->status !== 'pending') {
            return api_err('该用户已审核过');
        }
        if (!in_array($action, ['approve', 'reject'], true)) {
            return api_err('非法的操作');
        }

        if ($action === 'approve') {
            // v5.36.0：端手分离开着时，通过前必须先定端——否则新成员会以「未分类」漂在两个专区
            $data = ['status' => 'approved'];
            if (GameSide::on()) {
                $side = GameSide::clean($this->request->post('game_side', ''));
                if ($side === '') {
                    return api_err('端手分离已打开，通过前请先给这位成员选端游或手游');
                }
                $data['game_side'] = $side;
            }
            $user->save($data);
            admin_log('注册审核', '通过注册用户 ' . $user->username
                . (isset($data['game_side']) ? '（' . GameSide::sideLabel($data['game_side']) . '）' : ''));
            return api_ok([], '已通过，该用户现在可以登录了');
        }

        // 拒绝：删除账号（连同截图文件，统一走 ImageUpload::delete 带上传目录白名单防护）
        if ($user->profile_image) {
            ImageUpload::delete((string)$user->profile_image);
        }
        admin_log('注册审核', '拒绝并删除注册用户 ' . $user->username);
        $user->delete();
        return api_ok([], '已拒绝并删除该注册申请');
    }

    // ==================== 找回密码申请审核（v5.7.1 L1） ====================

    // 待审的找回密码申请列表（沿用 approve 权限，与注册审核同页展示）
    public function pwdResetList()
    {
        if (!admin_can('approve')) {
            return $this->noPerm();
        }
        $rows = Db::name('password_resets')
            ->where('tenant_id', TenantContext::id())
            ->order('id', 'asc')->select()->toArray();
        $canImg = admin_can('screenshots');
        $list   = [];
        foreach ($rows as $r) {
            $u = User::find((int)$r['user_id']);
            if (!$u) {
                // 账号已被删除的申请顺带清掉（含截图文件）
                if ($r['screenshot'] !== '') {
                    ImageUpload::delete((string)$r['screenshot']);
                }
                Db::name('password_resets')->where('id', $r['id'])->delete();
                continue;
            }
            $list[] = [
                'id'            => (int)$r['id'],
                'user_id'       => (int)$r['user_id'],
                'username'      => admin_can('qq_full') ? (string)$u->username : mask_qq((string)$u->username),
                'nickname'      => (string)$u->nickname,
                'screenshot'    => $canImg ? (string)$r['screenshot'] : '',
                'request_ip'    => (string)$r['request_ip'],
                'created_at'    => (string)$r['created_at'],
            ];
        }
        return api_ok(['list' => $list]);
    }

    // 通过 / 驳回找回密码申请（JSON：action = approve | reject）
    public function pwdResetReview($id)
    {
        if (!admin_can('approve')) {
            return $this->noPerm();
        }
        $row = Db::name('password_resets')
            ->where('tenant_id', TenantContext::id())
            ->where('id', (int)$id)->find();
        if (!$row) {
            return api_err('申请不存在或已处理', 404);
        }
        $action = (string)$this->request->post('action', '');
        $user   = User::find((int)$row['user_id']);

        if ($action === 'approve') {
            // v5.15.0(S-L2)：新密码哈希是申请当时就落库的，表里没有过期列；
            // 拖到几个月后再通过等于把一个可能早已外泄的旧口令重新激活，超 72 小时一律作废
            if (time() - strtotime((string)$row['created_at']) > 72 * 3600) {
                if ((string)$row['screenshot'] !== '') {
                    ImageUpload::delete((string)$row['screenshot']);
                }
                Db::name('password_resets')->where('id', $row['id'])->delete();
                admin_log('找回密码', '申请 #' . $row['id'] . ' 超过 72 小时未审核，已作废并清理材料');
                return api_err('该申请已超过 72 小时，请让成员重新提交一次');
            }
            if (!$user) {
                Db::name('password_resets')->where('id', $row['id'])->delete();
                return api_err('该账号已不存在，申请已清理');
            }
            // 换密码 + 密码纪元 +1（踢掉该账号全部在线会话）+ 作废全部免登录令牌
            $user->save([
                'password'      => (string)$row['new_password'],
                'session_epoch' => (int)$user->session_epoch + 1,
            ]);
            remember_clear_user((int)$user->id);
            Db::name('password_resets')->where('id', $row['id'])->delete();
            admin_log('找回密码', '通过用户 ' . $user->username . ' 的找回密码申请');
            return api_ok([], '已通过，该成员可用新密码登录');
        }
        if ($action === 'reject') {
            if ((string)$row['screenshot'] !== '') {
                ImageUpload::delete((string)$row['screenshot']);
            }
            Db::name('password_resets')->where('id', $row['id'])->delete();
            admin_log('找回密码', '驳回找回密码申请 #' . $row['id']);
            return api_ok([], '已驳回并清理申请材料');
        }
        return api_err('非法的操作');
    }

    // ==================== 用户管理 ====================

    // 用户列表（含 QQ号/邮箱/注册IP/归属地/禁言状态/截图）
    public function users()
    {
        // v5.7.1(H2)：最小权限守卫——此前任何管理员（哪怕零权限）都能拉到全队成员的
        // QQ/邮箱/注册IP/归属地。现仅超管或持有任一成员管理相关权限者可见
        if (!admin_can_any(['member_view', 'approve', 'ban', 'role_manage', 'rename', 'mute',
            'reset_pwd', 'delete_user', 'multi', 'team', 'lottery', 'duty', 'role_tag'])) {
            return $this->noPerm();
        }
        $page    = max(1, (int)$this->request->get('page', 1));
        $size    = min(100, max(1, (int)$this->request->get('size', 10)));
        $keyword = trim((string)$this->request->get('keyword', ''));

        $query = User::alias('u')
            ->leftJoin('teams t', 't.id = u.team_id')
            ->field('u.id, u.username, u.nickname, u.email, u.role, u.status, u.muted, u.muted_by, u.team_id,
                     u.game_side, u.game_side_lock,
                     t.name AS team_name, u.profile_image, u.reg_ip, u.reg_region, u.created_at, u.role_tags');

        if ($keyword !== '') {
            $query->whereLike('u.username|u.nickname|u.email', '%' . $keyword . '%');
        }
        // v5.36.0：用户管理可按端别筛
        $gs = strtolower(trim((string)$this->request->get('game_side', '')));
        if ($gs === 'none') {
            $query->where('u.game_side', '');
        } elseif (in_array($gs, ['pc', 'mobile'], true)) {
            $query->where('u.game_side', $gs);
        }

        $total = (clone $query)->count();
        $list  = $query->order('u.id', 'asc')->page($page, $size)->select()->toArray();

        // v3.9：无 qq_full 权限 QQ 号脱敏；无 screenshots 权限截图不下发
        $canQq  = admin_can('qq_full');
        $canImg = admin_can('screenshots');
        // v5.6.0：现有成员 QQ 命中黑名单的打标记（历史追溯用）
        $hitQqs = Blacklist::whereIn('qq', array_column($list, 'username'))->column('reason', 'qq');
        foreach ($list as &$row) {
            $row['in_blacklist'] = isset($hitQqs[(string)$row['username']]);
            if (!$canQq) {
                $row['username'] = mask_qq((string)$row['username']);
            }
            if (!$canImg) {
                $row['profile_image'] = '';
            }
        }
        unset($row);

        return api_ok(['list' => $list, 'total' => $total, 'page' => $page, 'size' => $size]);
    }

    // 是否内置超级管理员（受保护，禁止被封禁/禁言/降权/删除）
    private function isSuperAdmin($user): bool
    {
        return $user && $user->username === 'admin';
    }

    // 封禁 / 解封用户
    public function setUserStatus($id)
    {
        if (!admin_can('ban')) {
            return $this->noPerm();
        }
        $me     = current_user();
        $status = (string)$this->request->post('status', 'approved');
        $user   = User::find((int)$id);

        if (!$user) {
            return api_err('用户不存在', 404);
        }
        if ($this->isSuperAdmin($user)) {
            return api_err('系统管理员账号受保护，不能进行此操作');
        }
        // v5.2.1：真超管（内置 admin / 租户站长）受保护；授满权限的管理员不受此限
        if (is_super_admin($user)) {
            return api_err('超级管理员账号受保护，不能进行此操作');
        }
        if ($user->id === $me->id) {
            return api_err('不能封禁自己的账号');
        }
        if (!in_array($status, ['approved', 'banned'], true)) {
            return api_err('非法的状态');
        }
        if ($user->status === 'pending') {
            return api_err('该用户待审核，请先在注册审核中处理');
        }
        $user->save(['status' => $status]);
        admin_log('用户封禁', ($status === 'approved' ? '解封' : '封禁') . '用户 ' . $user->username);
        return api_ok([], $status === 'approved' ? '已解封' : '已封禁');
    }

    // 权限组设置：设为管理员 / 普通用户
    public function setUserRole($id)
    {
        if (!admin_can('role_manage')) {
            return $this->noPerm();
        }
        $me   = current_user();
        $role = (string)$this->request->post('role', 'user');
        $user = User::find((int)$id);

        if (!$user) {
            return api_err('用户不存在', 404);
        }
        if ($this->isSuperAdmin($user)) {
            return api_err('系统管理员账号受保护，不能进行此操作');
        }
        // v5.2.1：真超管禁止降级为普通用户；授满权限的管理员不受此限
        if (is_super_admin($user)) {
            return api_err('超级管理员账号受保护，不能进行此操作');
        }
        if (!in_array($role, ['user', 'admin'], true)) {
            return api_err('非法的角色');
        }
        if ($user->id === $me->id) {
            return api_err('不能修改自己的权限');
        }
        $user->save(['role' => $role]);
        admin_log('权限调整', '将用户 ' . $user->username . ($role === 'admin' ? ' 设为管理员' : ' 设为普通用户'));
        return api_ok([], $role === 'admin' ? '已设为管理员' : '已设为普通用户');
    }

    // 禁言 / 解除禁言
    public function setUserMute($id)
    {
        if (!admin_can('mute')) {
            return $this->noPerm();
        }
        $me    = current_user();
        $muted = (int)$this->request->post('muted', 0);
        $user  = User::find((int)$id);

        if (!$user) {
            return api_err('用户不存在', 404);
        }
        if ($this->isSuperAdmin($user)) {
            return api_err('系统管理员账号受保护，不能进行此操作');
        }
        // v5.2.1：真超管禁止禁言；授满权限的管理员不受此限
        if (is_super_admin($user)) {
            return api_err('超级管理员账号受保护，不能进行此操作');
        }
        if ($user->id === $me->id) {
            return api_err('不能禁言自己');
        }
        // v5.30.1：禁言分来源。平台下的那条战队解不掉（要联系平台），本队自设的照旧自己解
        if ($muted !== 1 && (string)$user->muted_by === 'platform') {
            return api_err('这条禁言是平台超级管理员下的，本队解不掉，请联系平台', 403, 403);
        }
        $user->save(['muted' => $muted === 1 ? 1 : 0, 'muted_by' => $muted === 1 ? 'team' : '']);
        admin_log('用户禁言', ($muted === 1 ? '禁言' : '解除禁言') . '用户 ' . $user->username);
        return api_ok([], $muted === 1 ? '已禁言' : '已解除禁言');
    }

    // 删除用户
    /**
     * v5.30.1：左上角站名要不要自动补「战队」两个字。
     * 以前是无条件补的（显示层补齐，不动库里的真实队名），现在交给队长：默认还补，想按原名显示就关掉。
     */
    public function brandSuffix()
    {
        if (!is_super_admin()) {
            return api_err('只有站长能改站名显示', 403, 403);
        }
        $on = (int)$this->request->post('suffix', 1) === 1 ? 1 : 0;
        \app\service\Setting::set('team_name_suffix', (string)$on);
        admin_log('站名显示', $on ? '恢复自动补「战队」后缀' : '关掉自动补「战队」后缀');
        return api_ok(['team_name_suffix' => $on],
            $on ? '站名会自动补上「战队」' : '站名按登记的原名显示');
    }

    public function removeUser($id)
    {
        if (!admin_can('delete_user')) {
            return $this->noPerm();
        }
        $me   = current_user();
        $user = User::find((int)$id);

        if (!$user) {
            return api_err('用户不存在', 404);
        }
        if ($this->isSuperAdmin($user)) {
            return api_err('系统管理员账号受保护，不能进行此操作');
        }
        // v5.2.1：真超管禁止删除；授满权限的管理员不受此限
        if (is_super_admin($user)) {
            return api_err('超级管理员账号受保护，不能进行此操作');
        }
        if ($user->id === $me->id) {
            return api_err('不能删除自己的账号');
        }

        TeamModel::where('leader_id', $user->id)->update(['leader_id' => null]);
        admin_log('删除用户', '删除用户 ' . $user->username);
        $user->delete();
        return api_ok([], '用户已删除');
    }

    // 修改用户名字（v3.9：管理员改昵称，仅展示名不影响登录）
    public function renameUser($id)
    {
        if (!admin_can('rename')) {
            return $this->noPerm();
        }
        $nickname = trim((string)$this->request->post('nickname', ''));
        $user     = User::find((int)$id);

        if (!$user) {
            return api_err('用户不存在', 404);
        }
        if ($this->isSuperAdmin($user)) {
            return api_err('系统管理员账号受保护，不能进行此操作');
        }
        // v5.3.1：真超管（内置 admin / 租户站长）同样受保护，非超管不能改超管名字
        if (is_super_admin($user)) {
            return api_err('超级管理员账号受保护，不能进行此操作');
        }
        if ($nickname === '' || mb_strlen($nickname) > 32) {
            return api_err('请填写新名字（不超过 32 字）');
        }

        $old = (string)$user->nickname;
        $user->save(['nickname' => $nickname]);
        admin_log('修改名字', '将用户 ' . $user->username . ' 的名字由「' . $old . '」改为「' . $nickname . '」');
        return api_ok([], '名字已修改');
    }

    // ==================== 职责标签管理（v5.3.0：role_tag 权限） ====================

    // 标签库列表（含各标签已分配人数 + 用户可自改开关）
    public function roleTags()
    {
        if (!admin_can('role_tag')) {
            return $this->noPerm();
        }
        $tags = RoleTagModel::order('id', 'asc')->select()->toArray();

        // 统计各标签持有数（users.role_tags 为 JSON ID 数组）
        $counts = [];
        $rows   = Db::name('users')
            ->where('tenant_id', TenantContext::id())
            ->where('role_tags', '<>', '')
            ->column('role_tags');
        foreach ($rows as $raw) {
            $ids = json_decode((string)$raw, true);
            if (!is_array($ids)) {
                continue;
            }
            foreach ($ids as $tid) {
                $tid = (int)$tid;
                $counts[$tid] = ($counts[$tid] ?? 0) + 1;
            }
        }
        foreach ($tags as &$t) {
            $t['user_count'] = $counts[(int)$t['id']] ?? 0;
        }
        unset($t);

        return api_ok(['list' => $tags, 'user_editable' => role_tag_user_editable()]);
    }

    // 新增 / 编辑标签（名称 ≤16 字，颜色 #rrggbb，同租户内名称唯一）
    public function roleTagSave()
    {
        if (!admin_can('role_tag')) {
            return $this->noPerm();
        }
        $id    = (int)$this->request->post('id', 0);
        $name  = trim((string)$this->request->post('name', ''));
        $color = trim((string)$this->request->post('color', ''));

        if ($name === '' || mb_strlen($name) > 16) {
            return api_err('请填写标签名称（不超过 16 字）');
        }
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
            return api_err('颜色格式不正确，请选择颜色');
        }
        $dup = RoleTagModel::where('name', $name);
        if ($id > 0) {
            $dup = $dup->where('id', '<>', $id);
        }
        if ($dup->find()) {
            return api_err('已存在同名标签');
        }

        if ($id > 0) {
            $tag = RoleTagModel::find($id);
            if (!$tag) {
                return api_err('标签不存在', 404);
            }
            $tag->save(['name' => $name, 'color' => $color]);
            admin_log('职责标签', '修改标签「' . $name . '」');
            return api_ok([], '标签已保存');
        }

        RoleTagModel::create(['name' => $name, 'color' => $color]);
        admin_log('职责标签', '新增标签「' . $name . '」');
        return api_ok([], '标签已添加');
    }

    // 删除标签：联动清理所有用户 role_tags 中的该 ID（用 Db 直连，避开模型对象缓存）
    public function roleTagRemove($id)
    {
        if (!admin_can('role_tag')) {
            return $this->noPerm();
        }
        $tag = RoleTagModel::find((int)$id);
        if (!$tag) {
            return api_err('标签不存在', 404);
        }
        $tagId = (int)$id;
        $name  = (string)$tag->name;
        $tag->delete();

        $rows = Db::name('users')
            ->where('tenant_id', TenantContext::id())
            ->where('role_tags', '<>', '')
            ->field('id, role_tags')->select()->toArray();
        foreach ($rows as $row) {
            $ids = json_decode((string)$row['role_tags'], true);
            if (!is_array($ids)) {
                continue;
            }
            $clean = array_values(array_filter($ids, fn($v) => (int)$v !== $tagId));
            if (count($clean) !== count($ids)) {
                Db::name('users')->where('id', $row['id'])
                    ->update(['role_tags' => $clean ? json_encode($clean) : '']);
            }
        }

        admin_log('职责标签', '删除标签「' . $name . '」并清理用户引用');
        return api_ok([], '标签已删除');
    }

    // 全局开关：是否允许用户自行修改标签（写 settings 表）
    public function roleTagToggleUserEdit()
    {
        if (!admin_can('role_tag')) {
            return $this->noPerm();
        }
        $cur = role_tag_user_editable();
        \app\service\Setting::set('role_tag_user_editable', $cur ? '0' : '1');
        admin_log('职责标签', $cur ? '关闭用户自行修改标签' : '开启用户自行修改标签');
        return api_ok(['user_editable' => !$cur], $cur ? '已禁止用户自行修改标签' : '已允许用户自行修改标签');
    }

    // 给用户分配标签（管理员多选，第一个为默认展示）
    public function assignUserTags($id)
    {
        if (!admin_can('role_tag')) {
            return $this->noPerm();
        }
        $user = User::find((int)$id);
        if (!$user) {
            return api_err('用户不存在', 404);
        }
        // v5.3.1：真超管（内置 admin / 租户站长）受保护，非超管不能给超管分配标签；
        // 超管本人可自改（自己给自己分标签无害），其余管理员一律拦截
        if (is_super_admin($user) && $user->id !== current_user()->id && !is_super_admin()) {
            return api_err('超级管理员账号受保护，不能进行此操作');
        }

        $tagIds = $this->request->post('tag_ids', []);
        if (!is_array($tagIds)) {
            $tagIds = [];
        }
        // 清洗：只保留本租户存在的标签，去重保序（第一个为默认展示）
        $valid = array_keys(role_tags_map());
        $clean = [];
        foreach ($tagIds as $t) {
            $t = (int)$t;
            if (in_array($t, $valid, true) && !in_array($t, $clean, true)) {
                $clean[] = $t;
            }
        }

        // 注意：同 permsSave，必须用静态 where()->save() 生成独立 UPDATE，
        // 避免 ThinkPHP 请求内对象缓存把 role_tags 覆写回旧值
        User::where('id', $user->id)->save(['role_tags' => $clean ? json_encode($clean) : '']);
        admin_log('职责标签', '为用户 ' . $user->username . ' 分配标签 ' . count($clean) . ' 个');
        return api_ok(['role_tags' => $clean], '标签已保存');
    }

    // ==================== 战队小队管理 ====================

    // 小队列表（v3.9.6：附带已审核成员名单，供成员分配用）
    public function teams()
    {
        // v5.7.1(H2)：全量成员名单属敏感数据，仅小队管理/成员名单权限持有者可见
        if (!admin_can_any(['team', 'member_view'])) {
            return $this->noPerm();
        }
        $tq = TeamModel::order('id', 'asc');
        $tq = GameSide::scope($tq, 'team', true);   // v5.36.0：管理端跟着当前专区走
        $teams = $tq->select();
        $list  = [];
        foreach ($teams as $team) {
            $leaderName = null;
            if ($team->leader_id) {
                $leader = User::find($team->leader_id);
                $leaderName = $leader ? $leader->nickname : null;
            }
            $list[] = [
                'id'           => $team->id,
                'name'         => $team->name,
                'description'  => $team->description,
                'leader_id'    => $team->leader_id,
                'leader_name'  => $leaderName,
                'member_count' => User::where('team_id', $team->id)->where('status', 'approved')->count(),
                'game_side'    => (string)$team->game_side,
                'created_at'   => $team->created_at,
            ];
        }

        // 已审核用户名单（含当前所属小队）；QQ 号按 qq_full 权限脱敏，与全站策略一致
        $users = User::where('status', 'approved')
            ->field('id, username, nickname, team_id, game_side')
            ->order('id', 'asc')
            ->select()
            ->toArray();
        $canFullQq = admin_can('qq_full');
        foreach ($users as &$u) {
            $u['username'] = $canFullQq ? (string)$u['username'] : mask_qq((string)$u['username']);
        }
        unset($u);

        return api_ok(['list' => $list, 'users' => $users]);
    }

    // 新建 / 编辑小队（传 id 为编辑，否则新建）
    public function saveTeam()
    {
        if (!admin_can('team')) {
            return $this->noPerm();
        }
        $id       = (int)$this->request->post('id', 0);
        $name     = trim((string)$this->request->post('name', ''));
        $desc     = trim((string)$this->request->post('description', ''));
        $leaderId = (int)$this->request->post('leader_id', 0);

        if ($name === '' || mb_strlen($name) > 64) {
            return api_err('请填写小队名称（不超过 64 字）');
        }
        if (mb_strlen($desc) > 500) {
            return api_err('小队简介最长 500 字');
        }

        $dup = TeamModel::where('name', $name);
        if ($id > 0) {
            $dup->where('id', '<>', $id);
        }
        if ($dup->find()) {
            return api_err('小队名称已存在');
        }

        // v5.36.0：小队这块开着分端才认端别；关着时编辑保留旧值
        $sideSet = GameSide::moduleOn('team');
        $side    = $sideSet ? GameSide::clean($this->request->post('game_side', '')) : '';

        if ($leaderId > 0) {
            $leader = User::find($leaderId);
            if (!$leader) {
                return api_err('指定的队长不存在');
            }
            if ($side !== '') {
                $ls = (string)($leader->game_side ?? '');
                if ($ls !== '' && $ls !== $side) {
                    return api_err('这位队长的端别与小队不一致');
                }
            }
        }

        if ($id > 0) {
            $team = TeamModel::find($id);
            if (!$team) {
                return api_err('小队不存在', 404);
            }
            $tdata = [
                'name'        => $name,
                'description' => $desc,
                'leader_id'   => $leaderId > 0 ? $leaderId : null,
            ];
            if ($sideSet) {
                $tdata['game_side'] = $side;
            }
            $team->save($tdata);
            if ($leaderId > 0) {
                User::where('id', $leaderId)->update(['team_id' => $team->id]);
            }
            admin_log('小队管理', '编辑小队 ' . $name);
            return api_ok([], '小队已更新');
        }

        $team = TeamModel::create([
            'name'        => $name,
            'description' => $desc,
            'leader_id'   => $leaderId > 0 ? $leaderId : null,
            'game_side'   => $side,
        ]);
        if ($leaderId > 0) {
            User::where('id', $leaderId)->update(['team_id' => $team->id]);
        }
        admin_log('小队管理', '创建小队 ' . $name);
        return api_ok(['id' => $team->id], '小队已创建');
    }

    // 解散小队
    public function removeTeam($id)
    {
        if (!admin_can('team')) {
            return $this->noPerm();
        }
        $team = TeamModel::find((int)$id);
        if (!$team) {
            return api_err('小队不存在', 404);
        }
        User::where('team_id', $team->id)->update(['team_id' => null]);
        admin_log('小队管理', '解散小队 ' . $team->name);
        $team->delete();
        return api_ok([], '小队已解散');
    }

    // v3.9.6：管理员把指定成员分配到某小队（team_id=0 表示移出小队）
    public function assignTeam()
    {
        if (!admin_can('team')) {
            return $this->noPerm();
        }
        $userId = (int)$this->request->post('user_id', 0);
        $teamId = (int)$this->request->post('team_id', 0);

        $user = User::find($userId);
        if (!$user || $user->status !== 'approved') {
            return api_err('目标用户不存在或未通过审核', 404);
        }

        $teamName = '无小队';
        if ($teamId > 0) {
            $team = TeamModel::find($teamId);
            if (!$team) {
                return api_err('目标小队不存在', 404);
            }
            $teamName = $team->name;
            if ((int)$user->team_id === $teamId) {
                return api_err('该成员已在该小队中');
            }
            if (GameSide::moduleOn('team')) {
                $us = (string)($user->game_side ?? '');
                $ts = (string)$team->game_side;
                if ($us !== '' && $ts !== '' && $us !== $ts) {
                    return api_err('这位成员的端别与小队不一致');
                }
            }
        }

        // 若该成员是某小队的队长且被调去别处，清空原小队队长标记（与自行退队逻辑一致）
        $leadTeam = TeamModel::where('leader_id', $userId)->find();
        if ($leadTeam && (int)$leadTeam->id !== $teamId) {
            $leadTeam->save(['leader_id' => null]);
        }

        User::where('id', $userId)->save(['team_id' => $teamId > 0 ? $teamId : null]);
        admin_log('小队管理', '把成员 ' . $user->nickname . ' 分配到 ' . $teamName);
        return api_ok([], '分配成功');
    }

    // ==================== 图片审核 ====================

    // 图片列表（默认待审核）
    public function images()
    {
        if (!admin_can('image_review')) {
            return $this->noPerm();
        }
        $status = (string)$this->request->get('status', 'pending');
        $query  = ImageModel::alias('i')
            ->leftJoin('users u', 'u.id = i.user_id')
            ->field('i.*, COALESCE(u.nickname, "已注销用户") AS uploader, u.username AS uploader_qq');

        if (in_array($status, ['pending', 'approved', 'rejected'], true)) {
            $query->where('i.status', $status);
        }

        $list = $query->order('i.id', 'desc')->limit(200)->select()->toArray();
        // v5.15.0(S-M2)：与全站同一口径——未持 qq_full 权限时 uploader_qq 一律打码
        $canFullQq = admin_can('qq_full');
        foreach ($list as &$it) {
            $it['uploader_qq'] = $canFullQq ? (string)$it['uploader_qq'] : mask_qq((string)$it['uploader_qq']);
        }
        unset($it);
        return api_ok(['list' => $list]);
    }

    // 审核图片：approve / reject
    public function reviewImage($id)
    {
        if (!admin_can('image_review')) {
            return $this->noPerm();
        }
        $me     = current_user();
        $action = (string)$this->request->post('action', '');
        $image  = ImageModel::find((int)$id);

        if (!$image) {
            return api_err('图片不存在', 404);
        }
        if (!in_array($action, ['approve', 'reject'], true)) {
            return api_err('非法的操作');
        }

        $image->save([
            'status'      => $action === 'approve' ? 'approved' : 'rejected',
            'reviewed_by' => $me->id,
            'reviewed_at' => date('Y-m-d H:i:s'),
        ]);

        admin_log('图片审核', ($action === 'approve' ? '通过' : '拒绝') . '图片 #' . $image->id . '（上传者 #' . $image->user_id . '）');
        return api_ok([], $action === 'approve' ? '已通过' : '已拒绝');
    }

    // 删除图片（连物理文件一起删，任何状态都可删）
    public function removeImage($id)
    {
        if (!admin_can('image_review')) {
            return $this->noPerm();
        }
        $image = ImageModel::find((int)$id);
        if (!$image) {
            return api_err('图片不存在', 404);
        }

        // 先删物理文件，再删数据库记录
        ImageUpload::delete((string)$image->file);
        admin_log('图片删除', '删除图片 #' . $image->id . '（上传者 #' . $image->user_id . '）');
        $image->delete();

        return api_ok([], '图片已删除');
    }

    // ==================== 公告管理 ====================

    // 公告列表（全部，管理端）
    public function announcements()
    {
        if (!admin_can('announce')) {
            return $this->noPerm();
        }
        $q = Announcement::order('id', 'desc');
        $q = GameSide::scope($q, 'announce', true);   // v5.36.0：管理端跟着当前专区走，全队共享的两边都看得到
        // v5.36.34：管理端看得见全部行（包括还没到点的定时、已经撤回的），
        // 每行带一个 state 给前端出徽标：live=成员现在能看到 / scheduled=待发 / revoked=已撤回
        $rows = $q->select()->toArray();
        foreach ($rows as &$r) {
            $r['state'] = Announcement::stateOf($r);
        }
        unset($r);
        return api_ok(['list' => $rows]);
    }

    // 新建 / 编辑公告（multipart：文字 + 可选配图）
    public function saveAnnouncement()
    {
        if (!admin_can('announce')) {
            return $this->noPerm();
        }
        $id      = (int)$this->request->post('id', 0);
        $title   = trim((string)$this->request->post('title', ''));
        $content = trim((string)$this->request->post('content', ''));
        // v5.6.0：已读回执选项（不需要回执的公告保持现状，不统计）
        $needReceipt = $this->request->post('need_receipt') ? 1 : 0;
        $deadline    = trim((string)$this->request->post('deadline', ''));

        if ($title === '' || mb_strlen($title) > 128) {
            return api_err('请填写公告标题（不超过 128 字）');
        }
        if ($content === '') {
            return api_err('请填写公告内容');
        }
        $deadlineVal = null;
        if ($needReceipt && $deadline !== '') {
            if (strtotime($deadline) === false) {
                return api_err('回执截止时间格式不正确');
            }
            $deadlineVal = date('Y-m-d H:i:s', strtotime($deadline));
        }
        // v5.36.34：定时发布。留空=立即发；填了就得是「往后 30 天内」的一个时间点
        $schedRaw = trim((string)$this->request->post('scheduled_at', ''));
        $schedVal = null;
        if ($schedRaw !== '') {
            $ts = strtotime($schedRaw);
            if ($ts === false) {
                return api_err('定时发布时间格式不正确');
            }
            if ($ts <= time()) {
                return api_err('定时发布的时间不能是过去；要马上发就别填那一栏');
            }
            if ($ts > time() + 30 * 86400) {
                return api_err('定时发布最多排到 30 天以后');
            }
            $schedVal = date('Y-m-d H:i:s', $ts);
        }
        // v5.36.0：公告这块开着分端才认端别；关着就别改旧数据的端别
        $sideSet = GameSide::moduleOn('announce');
        $side    = $sideSet ? GameSide::clean($this->request->post('game_side', '')) : '';

        // 配图（可选）：有上传则校验保存，无则保持原图
        // 注意：必须用无参 file() 取数组。若写成 file('file')，浏览器提交的空文件框
        // 会让 ThinkPHP 抛出 "no file to uploaded" 异常（HTTP 500），导致公告完全无法保存
        $imagePath = null;
        $files = $this->request->file();
        $file  = $files['file'] ?? null;
        if ($file) {
            [$imagePath, $err] = ImageUpload::save($file, 'announcements');
            if ($err !== null) {
                return api_err($err);
            }
        }

        if ($id > 0) {
            $ann = Announcement::find($id);
            if (!$ann) {
                return api_err('公告不存在', 404);
            }
            $data = ['title' => $title, 'content' => $content];
            if ($sideSet) {
                $data['game_side'] = $side;
            }
            if ($imagePath !== null) {
                $data['image'] = $imagePath;
            }
            // 回执选项仅发布时确定；编辑时允许补开/改截止时间，不追溯历史已读
            $data['need_receipt'] = $needReceipt;
            $data['deadline']     = $deadlineVal;
            // v5.36.34：定时那一栏只有「还没发出去」的行能改（改时间或清空=立即发都算）；
            // 成员已经看到的行不再碰这一列 —— 否则改个错字会把当初的定时时间抹掉，
            // 撤回状态同理，只走「撤回 / 恢复」那两个动作，不在常规编辑里顺手覆盖
            if ((string)$ann->scheduled_at !== '' && strtotime((string)$ann->scheduled_at) > time()) {
                $data['scheduled_at'] = $schedVal;
            }
            $ann->save($data);
            admin_log('公告管理', '编辑公告 ' . $title
                . (isset($data['scheduled_at']) ? '（定时改为 ' . ($data['scheduled_at'] ?? '立即发') . '）' : ''));
            return api_ok([], '公告已更新');
        }

        $ann = Announcement::create([
            'title'        => $title,
            'content'      => $content,
            'image'        => $imagePath ?? '',
            'need_receipt' => $needReceipt,
            'deadline'     => $deadlineVal,
            'game_side'    => $side,
            'scheduled_at' => $schedVal,
        ]);
        admin_log('公告管理', ($schedVal !== null ? '排定时公告 ' . $schedVal . ' ' : '发布公告 ') . $title);
        return api_ok(['id' => $ann->id],
            $schedVal !== null ? '已排好，到 ' . date('m月d日 H:i', strtotime($schedVal)) . ' 自动发给成员' : '公告已发布');
    }

    // 删除公告
    public function removeAnnouncement($id)
    {
        if (!admin_can('announce')) {
            return $this->noPerm();
        }
        $ann = Announcement::find((int)$id);
        if (!$ann) {
            return api_err('公告不存在', 404);
        }
        admin_log('公告管理', '删除公告 #' . $ann->id . ' ' . $ann->title);
        $ann->delete();
        return api_ok([], '公告已删除');
    }

    // v5.36.34：撤回 / 恢复公告。撤回只盖一个时间戳，行、配图、已读记录都留着 ——
    // 撤回不是删除：队长反悔要能恢复，事后也要能在回执里看到「当时有谁读过」。
    // 撤回期间成员端读不到（列表/详情/催读三处同一个口径），对外展示页也跟着不出。
    public function revokeAnnouncement($id)
    {
        if (!admin_can('announce')) {
            return $this->noPerm();
        }
        $ann = Announcement::find((int)$id);
        if (!$ann) {
            return api_err('公告不存在', 404);
        }
        if ((string)$ann->revoked_at !== '') {
            return api_err('这条已经是撤回状态了');
        }
        $ann->save(['revoked_at' => date('Y-m-d H:i:s')]);
        admin_log('公告管理', '撤回公告 #' . $ann->id . ' ' . $ann->title);
        return api_ok([], '已撤回，成员那边立刻看不到了（行和已读记录都留着，随时能恢复）');
    }

    // 撤销撤回：恢复后成员又能看到，之前谁读过还在
    public function restoreAnnouncement($id)
    {
        if (!admin_can('announce')) {
            return $this->noPerm();
        }
        $ann = Announcement::find((int)$id);
        if (!$ann) {
            return api_err('公告不存在', 404);
        }
        if ((string)$ann->revoked_at === '') {
            return api_err('这条没被撤回，不用恢复');
        }
        $ann->save(['revoked_at' => null]);
        admin_log('公告管理', '恢复公告 #' . $ann->id . ' ' . $ann->title);
        return api_ok([], '已恢复，成员又能看到这条公告了');
    }

    // ==================== 撞车时间管理 ====================

    // 全部时间点（含已过期的，管理端）
    public function races()
    {
        if (!admin_can('race')) {
            return $this->noPerm();
        }
        $q = RaceEvent::order('race_time', 'asc');
        $q = GameSide::scope($q, 'race', true);
        $list = $q->select();
        return api_ok(['list' => $list, 'server_time' => date('Y-m-d H:i:s')]);
    }

    // 发布时间点（最多 3 场未开始的）
    public function saveRace()
    {
        if (!admin_can('race')) {
            return $this->noPerm();
        }
        $title    = trim((string)$this->request->post('title', '撞车比赛')) ?: '撞车比赛';
        $raceTime = trim((string)$this->request->post('race_time', ''));

        $ts = strtotime($raceTime);
        if ($raceTime === '' || $ts === false) {
            return api_err('请选择有效的比赛时间');
        }
        if ($ts <= time()) {
            return api_err('比赛时间必须是将来的时间');
        }
        if (mb_strlen($title) > 128) {
            return api_err('标题最长 128 字');
        }

        // 先清理过期场次，再校验数量上限
        RaceEvent::where('race_time', '<=', date('Y-m-d H:i:s'))->delete();
        $cq = RaceEvent::where('race_time', '>', date('Y-m-d H:i:s'));
        $cq = GameSide::scope($cq, 'race', true);   // v5.36.0：分端后每端各 3 场
        $count = $cq->count();
        if ($count >= 3) {
            return api_err('最多只能发布 3 场未开始的比赛，请先删除一场');
        }

        $race = RaceEvent::create([
            'title'     => $title,
            'race_time' => date('Y-m-d H:i:s', $ts),
            'game_side' => GameSide::moduleOn('race') ? GameSide::clean($this->request->post('game_side', '')) : '',
        ]);
        admin_log('撞车时间', '发布撞车场次 ' . $title . '（' . date('Y-m-d H:i:s', $ts) . '）');
        return api_ok(['id' => $race->id], '发布时间成功');
    }

    // 删除时间点
    public function removeRace($id)
    {
        if (!admin_can('race')) {
            return $this->noPerm();
        }
        $race = RaceEvent::find((int)$id);
        if (!$race) {
            return api_err('时间点不存在', 404);
        }
        admin_log('撞车时间', '删除撞车场次 #' . $race->id . '（' . $race->race_time . '）');
        $race->delete();
        return api_ok([], '已删除');
    }

    // ==================== 自建房比赛日程管理（v3.9.6） ====================

    // 全部日程（含已过期的，管理端）
    public function customRaces()
    {
        if (!admin_can('custom_race')) {
            return $this->noPerm();
        }
        $q = CustomRaceEvent::order('race_time', 'asc');
        $q = GameSide::scope($q, 'custom_race', true);
        $list = $q->select();
        return api_ok(['list' => $list, 'server_time' => date('Y-m-d H:i:s')]);
    }

    // 发布日程（最多 3 场未开始的）
    public function saveCustomRace()
    {
        if (!admin_can('custom_race')) {
            return $this->noPerm();
        }
        $title    = trim((string)$this->request->post('title', '自建房比赛')) ?: '自建房比赛';
        $raceTime = trim((string)$this->request->post('race_time', ''));

        $ts = strtotime($raceTime);
        if ($raceTime === '' || $ts === false) {
            return api_err('请选择有效的比赛时间');
        }
        if ($ts <= time()) {
            return api_err('比赛时间必须是将来的时间');
        }
        if (mb_strlen($title) > 128) {
            return api_err('标题最长 128 字');
        }

        // 先清理过期场次，再校验数量上限
        CustomRaceEvent::where('race_time', '<=', date('Y-m-d H:i:s'))->delete();
        $cq = CustomRaceEvent::where('race_time', '>', date('Y-m-d H:i:s'));
        $cq = GameSide::scope($cq, 'custom_race', true);   // v5.36.0：分端后每端各 3 场
        $count = $cq->count();
        if ($count >= 3) {
            return api_err('最多只能发布 3 场未开始的比赛，请先删除一场');
        }

        $race = CustomRaceEvent::create([
            'title'     => $title,
            'race_time' => date('Y-m-d H:i:s', $ts),
            'game_side' => GameSide::moduleOn('custom_race') ? GameSide::clean($this->request->post('game_side', '')) : '',
        ]);
        admin_log('自建房日程', '发布自建房场次 ' . $title . '（' . date('Y-m-d H:i:s', $ts) . '）');
        return api_ok(['id' => $race->id], '发布日程成功');
    }

    // 删除日程
    public function removeCustomRace($id)
    {
        if (!admin_can('custom_race')) {
            return $this->noPerm();
        }
        $race = CustomRaceEvent::find((int)$id);
        if (!$race) {
            return api_err('日程不存在', 404);
        }
        admin_log('自建房日程', '删除自建房场次 #' . $race->id . '（' . $race->race_time . '）');
        $race->delete();
        return api_ok([], '已删除');
    }

    // ==================== IP 封禁管理（v3.2：防爆破） ====================

    // 封禁列表（含自动封禁与手动封禁）
    public function blockedIps()
    {
        // v5.15.0(S-H2)：blocked_ips 全平台共用一张表（无租户列、无过期），
        // 挂一根可分配的权限键就能看/封/解别队成员的 IP，一律只认真超管
        if (!is_super_admin()) {
            return api_err('IP 封禁全平台生效，仅超级管理员可管理', 403, 403);
        }
        $list = BlockedIp::alias('b')
            ->leftJoin('users u', 'u.id = b.blocked_by')
            ->field('b.*, COALESCE(u.nickname, "") AS admin_name')
            ->order('b.id', 'desc')
            ->select()
            ->toArray();

        // 自动封禁(blocked_by=NULL)显示为“系统”，同时给出当前请求 IP 供前端提示
        foreach ($list as &$item) {
            $item['blocked_by_name'] = $item['blocked_by'] ? ($item['admin_name'] ?: '管理员#' . $item['blocked_by']) : '系统自动';
            unset($item['admin_name']);
        }
        unset($item);

        return api_ok(['list' => $list, 'current_ip' => client_ip()]);
    }

    // 手动封禁 IP
    public function blockIp()
    {
        // v5.15.0(S-H2)：同 blockedIps —— 手动封禁写的是全平台表
        if (!is_super_admin()) {
            return api_err('IP 封禁全平台生效，仅超级管理员可管理', 403, 403);
        }
        $ip     = trim((string)$this->request->post('ip', ''));
        $reason = trim((string)$this->request->post('reason', '管理员手动封禁'));

        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return api_err('请输入有效的 IP 地址');
        }
        // 防护：禁止封禁自己当前所在 IP，避免管理员把自己锁在外面
        if ($ip === client_ip()) {
            return api_err('不能封禁自己当前所在的 IP');
        }
        if (BlockedIp::where('ip', $ip)->find()) {
            return api_err('该 IP 已在封禁列表中');
        }
        if (mb_strlen($reason) > 128) {
            return api_err('封禁原因最长 128 字');
        }

        BlockedIp::create([
            'ip'         => $ip,
            'reason'     => $reason,
            'blocked_by' => current_user()->id,
        ]);
        admin_log('IP 封禁', '封禁 IP ' . $ip);
        return api_ok([], '已封禁 IP: ' . $ip);
    }

    // 解封 IP
    public function unblockIp($id)
    {
        // v5.15.0(S-H2)：同 blockedIps —— 解封按 id 唯一定位，能解掉别队或系统自动下的封禁
        if (!is_super_admin()) {
            return api_err('IP 封禁全平台生效，仅超级管理员可管理', 403, 403);
        }
        $row = BlockedIp::find((int)$id);
        if (!$row) {
            return api_err('封禁记录不存在', 404);
        }
        $ip = (string)$row->ip;   // 先取出：delete() 后模型数据会被清空
        $row->delete();
        // 同步清除该 IP 的登录失败计数，否则解封后仍会被限流拦截
        Cache::delete('login_fail_' . md5($ip));
        admin_log('IP 封禁', '解封 IP ' . $ip);
        return api_ok([], '已解封 IP: ' . $ip);
    }

    // ==================== 多账户检测（v3.5：按注册 IP 查小号） ====================

    // 按注册 IP 聚合：列出同一 IP 注册 ≥2 个账号的分组（管理员账号不参与检测）
    public function multiAccounts()
    {
        if (!admin_can('multi')) {
            return $this->noPerm();
        }
        $canQq = admin_can('qq_full');   // v3.9：无权限时 QQ 号脱敏
        $rows = User::where('role', '<>', 'admin')
            ->where('reg_ip', '<>', '')
            ->field('id, username, nickname, reg_ip, status, muted, created_at')
            ->order('reg_ip', 'asc')
            ->order('id', 'asc')
            ->select();

        $groups = [];
        foreach ($rows as $u) {
            $groups[(string)$u->reg_ip][] = [
                'id'         => $u->id,
                'username'   => $canQq ? $u->username : mask_qq((string)$u->username),
                'nickname'   => $u->nickname,
                'status'     => $u->status,
                'muted'      => (int)$u->muted,
                'created_at' => $u->created_at,
            ];
        }

        $list = [];
        foreach ($groups as $ip => $users) {
            if (count($users) >= 2) {
                $list[] = ['ip' => $ip, 'count' => count($users), 'users' => $users];
            }
        }
        // 小号最多的 IP 排最前
        usort($list, function ($a, $b) { return $b['count'] <=> $a['count']; });
        return api_ok(['list' => $list]);
    }

    // ==================== 重置用户密码（v3.7） ====================

    // 管理员重置普通用户密码；不能改其他管理员 / 超级管理员（含自己）
    public function setUserPassword($id)
    {
        if (!admin_can('reset_pwd')) {
            return $this->noPerm();
        }
        $user = User::find((int)$id);
        if (!$user) {
            return api_err('用户不存在', 404);
        }
        // 管理员角色一律不可改密码（isSuperAdmin 双保险）
        if ($user->role === 'admin' || $this->isSuperAdmin($user)) {
            return api_err('不能修改管理员或超级管理员的密码');
        }

        $new     = (string)$this->request->post('password', '');
        $confirm = (string)$this->request->post('confirm', '');
        if (mb_strlen($new) < 8 || mb_strlen($new) > 64) {
            return api_err('新密码长度需为 8-64 位');
        }
        if (!preg_match('/[A-Za-z]/', $new) || !preg_match('/\d/', $new)) {
            return api_err('密码需至少包含一个字母和一个数字');
        }
        if ($new !== $confirm) {
            return api_err('两次输入的密码不一致');
        }
        // v5.7.1(H5)：弱口令黑名单
        if ($weak = is_weak_password($new)) {
            return api_err($weak);
        }

        // v5.7.1(H3)：改密即全量失效——session_epoch +1 踢掉该账号所有在线会话，
        // 同时清空全部设备的 7 天免登录令牌（此前两者都漏了，失陷账号重置密码也踢不掉攻击者）
        $user->save([
            'password'      => password_hash($new, PASSWORD_DEFAULT),
            'session_epoch' => (int)$user->session_epoch + 1,
        ]);
        remember_clear_user((int)$user->id);
        admin_log('重置密码', '重置用户 ' . $user->username . ' 的登录密码');
        return api_ok([], '密码已重置，该账号已强制下线，请通知用户使用新密码登录');
    }

    // ==================== 管理操作日志（v3.7） ====================

    // 日志列表（v5.7.1(H2)：管理轨迹属敏感信息，收紧为仅超级管理员可看）
    public function logs()
    {
        if (!is_super_admin()) {
            return api_err('只有超级管理员可以查看操作日志', 403, 403);
        }
        $page = max(1, (int)$this->request->get('page', 1));
        $size = min(100, max(1, (int)$this->request->get('size', 20)));

        $query = AdminLog::order('id', 'desc');
        $total = (clone $query)->count();
        $list  = $query->page($page, $size)->select();

        // is_super 供前端决定是否显示清理按钮（仅超管可清理）
        return api_ok(['list' => $list, 'total' => $total, 'page' => $page, 'size' => $size, 'is_super' => is_super_admin()]);
    }

    // 清空日志（仅超管）：先清空再记一条“清空日志”，保留追溯
    public function clearLogs()
    {
        if (!is_super_admin()) {
            return api_err('只有超级管理员可以清理日志', 403, 403);
        }
        // v5.36.13：协作模式必须留下审计轨迹，不允许把日志一键清空
        if (\app\service\Support::isActive()) {
            return api_err('平台协作模式不能清空操作日志', 403, 403);
        }
        AdminLog::where('id', '>', 0)->delete();
        admin_log('清空日志', '清空全部管理操作日志');
        return api_ok([], '日志已清空');
    }

    // ==================== 聊天消息清理（v3.7：仅超管） ====================

    // 删除 N 天前的旧聊天消息
    public function chatCleanup()
    {
        if (!is_super_admin()) {
            return api_err('只有超级管理员可以清理聊天消息', 403, 403);
        }
        $days = (int)$this->request->post('days', 30);
        if ($days < 1 || $days > 3650) {
            return api_err('请输入 1-3650 之间的天数');
        }
        $before  = date('Y-m-d H:i:s', time() - $days * 86400);
        $deleted = ChatMessage::where('created_at', '<', $before)->delete();
        // v5.19.0：私聊也是聊天数据，按同一个日期一起清；举报记录不清（那是治理留痕，不是聊天内容）
        $dmDeleted = (int)Db::name('chat_dm_messages')->where('tenant_id', TenantContext::id())
            ->where('created_at', '<', $before)->delete();
        $deleted += $dmDeleted;
        admin_log('聊天清理', '清理 ' . $days . ' 天前的旧聊天消息，共删除 ' . $deleted
            . ' 条（其中私聊 ' . $dmDeleted . ' 条）');
        return api_ok(['deleted' => $deleted, 'dm_deleted' => $dmDeleted], '已清理 ' . $deleted . ' 条旧消息');
    }

    // ==================== 存储空间（v5.1.0：配额查看 + 清理） ====================
    // 轮换地图管理已收归入驻平台超管统一配置（/api/platform/admin/rotation*）

    // 存储空间概览：配额 / 已用 / 分类明细（仅超管）
    public function storage()
    {
        if (!is_super_admin()) {
            return api_err('只有管理员可以查看存储空间', 403, 403);
        }
        return api_ok(\app\service\Storage::overview(\app\service\TenantContext::id()));
    }

    // 删除登记文件释放空间（仅超管；JSON：type + id）
    public function storageDelete()
    {
        if (!is_super_admin()) {
            return api_err('只有管理员可以清理存储空间', 403, 403);
        }
        $type = (string)$this->request->post('type', '');
        $id   = (int)$this->request->post('id', 0);
        if ($id <= 0) {
            return api_err('参数不合法');
        }
        $err = \app\service\Storage::removeFile(\app\service\TenantContext::id(), $type, $id);
        if ($err !== null) {
            return api_err($err, 404);
        }
        admin_log('存储清理', '删除 ' . $type . ' 分类下的文件 #' . $id);
        return api_ok([], '已删除，空间已释放');
    }

    // ==================== 登录日志与新归属地提醒（v5.17.0） ====================

    /** 本队登录日志的可见性：队长恒可见，副管理需 login_log 权限键 */
    protected function canViewLoginLog(): bool
    {
        return is_super_admin() || admin_can('login_log');
    }

    // 登录日志列表（只记 role=admin）：支持按结果/仅新归属地/按账号筛选
    public function loginLogs()
    {
        if (!$this->canViewLoginLog()) {
            return api_err('无权限：需要「登录日志」权限', 403, 403);
        }
        $tid   = \app\service\TenantContext::id();
        $page  = max(1, (int)$this->request->get('page', 1));
        $size  = min(100, max(1, (int)$this->request->get('size', 20)));
        $uid   = max(0, (int)$this->request->get('user_id', 0));
        $only  = (string)$this->request->get('filter', '');

        $query = Db::name('login_logs')->where('tenant_id', $tid);
        if ($uid > 0) {
            $query->where('user_id', $uid);
        }
        if ($only === 'fail') {
            $query->where('result', 'fail');
        } elseif ($only === 'flagged') {
            $query->where('flagged', 1);
        } elseif ($only === 'cookie') {
            $query->where('via', 'cookie');
        }
        $total = (clone $query)->count();
        $rows  = $query->order('id', 'desc')->page($page, $size)->select()->toArray();

        $names = Db::name('users')->where('tenant_id', $tid)->column('nickname, role', 'id');
        $list  = [];
        foreach ($rows as $r) {
            $u = $names[(int)$r['user_id']] ?? null;
            $list[] = [
                'id'         => (int)$r['id'],
                'user_id'    => (int)$r['user_id'],
                'qq'         => (string)$r['username'],
                'nickname'   => $u ? (string)$u['nickname'] : '',
                'ip'         => (string)$r['ip'],
                'region'     => (string)$r['region'],
                'ua'         => (string)$r['ua'],
                'via'        => (string)$r['via'],
                'result'     => (string)$r['result'],
                'flagged'    => (int)$r['flagged'] === 1,
                'created_at' => (string)$r['created_at'],
            ];
        }
        return api_ok([
            'list'    => $list,
            'total'   => $total,
            'page'    => $page,
            'size'    => $size,
            'unread'  => login_alert_unread($tid, $this->alertSeenAt()),   // 全队未读（按人游标）
            'keep'    => LOGIN_LOG_KEEP,
        ]);
    }

    /**
     * 我的新归属地提醒（任何 role=admin 都能读自己的，不需要权限键——
     * 这是本人账号的安全信息，不是管理数据）
     * 带 can_view：有登录日志权限时前端才提示"全队的也在页签里"
     */
    public function loginAlerts()
    {
        $user = current_user();
        if (!$user || $user->role !== 'admin') {
            return api_err('仅管理员可见', 403, 403);
        }
        $tid  = \app\service\TenantContext::id();
        $seen = $this->alertSeenAt();
        $mine = [];
        foreach (login_alert_rows($tid, 10, (int)$user->id) as $r) {
            $mine[] = [
                'id'         => (int)$r['id'],
                'ip'         => (string)$r['ip'],
                'region'     => (string)$r['region'],
                'ua'         => (string)$r['ua'],
                'via'        => (string)$r['via'],
                'unread'     => $seen === null || (string)$r['created_at'] > $seen,
                'created_at' => (string)$r['created_at'],
            ];
        }
        return api_ok([
            'list'      => $mine,
            'unread'    => login_alert_unread($tid, $seen, (int)$user->id),
            'team_unread' => $this->canViewLoginLog() ? login_alert_unread($tid, $seen) : 0,
            'can_view'  => $this->canViewLoginLog(),
        ]);
    }

    // 标记已读：只推进"我自己"的游标，不影响别的管理员
    public function loginAlertsRead()
    {
        $user = current_user();
        if (!$user || $user->role !== 'admin') {
            return api_err('仅管理员可见', 403, 403);
        }
        Db::name('users')->where('id', (int)$user->id)
            ->update(['login_alert_seen_at' => date('Y-m-d H:i:s')]);
        return api_ok([], '已标记为已阅');
    }

    /** 我自己的提醒已读游标（null = 从没点过，即全部未读） */
    protected function alertSeenAt(): ?string
    {
        $me  = current_user();
        $uid = $me ? (int)$me->id : 0;
        if ($uid <= 0) {
            return null;
        }
        $v = Db::name('users')->where('id', $uid)->value('login_alert_seen_at');
        return ($v === null || $v === '') ? null : (string)$v;
    }

    // ==================== 平台通知（v5.1.0，v5.2.0 已阅标记） ====================

    // 入驻平台超管发给本站管理员的通知（倒序最近 50 条，含已阅状态与未读数）
    public function platformNotices()
    {
        // v5.7.1(H2)：零权限的管理员角色账号不应读取任何管理端数据
        if (!is_super_admin() && admin_perms_of(current_user()) === []) {
            return $this->noPerm();
        }
        $list = \think\facade\Db::name('tenant_notices')
            ->where('tenant_id', \app\service\TenantContext::id())
            ->order('id', 'desc')->limit(50)->select()->toArray();
        $unread = 0;
        foreach ($list as &$n) {
            $n['read'] = !empty($n['read_at']);
            if (!$n['read']) {
                $unread++;
            }
        }
        unset($n);
        return api_ok(['list' => $list, 'unread' => $unread]);
    }

    // v5.2.0：把本站未读通知全部标记为已阅（打开通知栏即视为已阅）
    public function platformNoticesRead()
    {
        // v5.7.1(H2)：与 platformNotices 同守卫
        if (!is_super_admin() && admin_perms_of(current_user()) === []) {
            return $this->noPerm();
        }
        $marked = \think\facade\Db::name('tenant_notices')
            ->where('tenant_id', \app\service\TenantContext::id())
            ->where('read_at', null)
            ->update(['read_at' => date('Y-m-d H:i:s')]);
        return api_ok(['marked' => $marked], '已标记为已阅');
    }

    // ==================== 管理员权限分配（v3.9：仅超管） ====================

    // 普通管理员名单 + 权限定义 + 各自已分配的权限
    public function perms()
    {
        if (!is_super_admin()) {
            return api_err('只有超级管理员可以分配管理员权限', 403, 403);
        }
        $defs = [];
        foreach (admin_perm_defs() as $key => $label) {
            $defs[] = ['key' => $key, 'label' => $label];
        }

        $admins = User::where('role', 'admin')
            ->where('username', '<>', 'admin')
            // 注意：field 必须含 role，否则 admin_perms_of() 判 role 时读到 null 直接返回空
            ->field('id, username, nickname, role, admin_perms')
            ->order('id', 'asc')
            ->select();

        $list = [];
        foreach ($admins as $a) {
            // v5.2.1：只有真超管（内置 admin / 租户站长）不进分配列表；
            // 被授满权限的管理员仍在此列表，真超管可随时调整其权限
            if (is_super_admin($a)) {
                continue;
            }
            $list[] = [
                'id'       => $a->id,
                'username' => $a->username,
                'nickname' => $a->nickname,
                'perms'    => admin_perms_of($a),
            ];
        }

        return api_ok(['defs' => $defs, 'admins' => $list]);
    }

    // 保存某位普通管理员的权限（JSON：admin_id + perms 数组，多选）
    public function permsSave()
    {
        if (!is_super_admin()) {
            return api_err('只有超级管理员可以分配管理员权限', 403, 403);
        }
        $adminId = (int)$this->request->post('admin_id', 0);
        $perms   = $this->request->post('perms', []);
        if (!is_array($perms)) {
            $perms = [];
        }

        $user = User::find($adminId);
        if (!$user || $user->role !== 'admin') {
            return api_err('目标用户不存在或不是管理员', 404);
        }
        if ($this->isSuperAdmin($user)) {
            return api_err('系统管理员账号无需分配权限');
        }
        // v5.2.1：真超管（内置 admin / 租户站长）权限不可削减；
        // 被授满权限的普通管理员不算超管，仍可在此被调整权限
        if (is_super_admin($user)) {
            return api_err('超级管理员恒拥有全部权限，不可修改其权限');
        }

        // 只保留合法权限键，去重
        $perms = array_values(array_unique(array_intersect($perms, array_keys(admin_perm_defs()))));
        // 注意：必须用静态 where()->save()（生成独立 UPDATE）。
        // 若用 $user->save()，模型实例会被写入 ThinkPHP 请求内对象缓存，
        // 随后 admin_log 里 current_user() 取回的是旧数据实例，
        // 其析构/保存会把 admin_perms 覆写回旧值，导致分配静默失效
        User::where('id', $adminId)->save(['admin_perms' => json_encode($perms)]);
        admin_log('权限分配', '为管理员 ' . $user->username . ' 分配权限 ' . count($perms) . ' 项');
        return api_ok(['perms' => $perms], '权限已保存，该管理员刷新页面后生效');
    }
}
