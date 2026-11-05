<?php
declare (strict_types = 1);

namespace app\controller;

use app\service\ImageUpload;
use app\service\Setting;
use app\service\Showcase as Show;
use app\service\TenantContext;
use think\facade\Db;
use think\facade\Request;

/**
 * 战队对外展示页的编辑与收件箱（v5.20.0，权限键 showcase）
 *
 * 队长/被授权的管理员在这里排版展示页、处理访客投递。三条边界写死在本文件：
 *   1) 精选勾选只能勾"本队仍然有效"的内容——公告按 id 勾、改枪码与攻略只认 published 行，
 *      作者事后下架的，展示页自动不再挂（contents() 里再过滤一次，这里不信任前端）；
 *   2) 访客投递的 QQ 只在这个收件箱里给管理员看全（队长要用它联系人），
 *      任何访客页与大厅都不下发；
 *   3) 采纳投稿 = 把内容推到互联网上，所以每一次采纳都写一行本站操作日志，可复盘。
 *
 * 成员是否公开不在这里勾：那是成员自己的事（ProfilePage 的"愿意出现在战队主页"），
 * 队长这一层只有"成员风采"整个模块的开与关——双层都开才公开。
 */
class ShowcaseAdmin extends AdminBase
{
    protected function noPerm()
    {
        return api_err('您没有「对外展示页」权限，请联系超级管理员分配', 403, 403);
    }

    /** 站点级写操作统一守卫：战队上下文 + showcase 权限 */
    protected function guard()
    {
        if (TenantContext::isPlatform()) {
            return api_err('接口不存在', 404, 404);
        }
        return admin_can('showcase') ? null : $this->noPerm();
    }

    /**
     * GET /api/admin/showcase
     * 一次给全：当前配置、条目（含隐藏）、可勾精选、公开成员、待处理计数、平台状态
     */
    public function index()
    {
        if ($r = $this->guard()) {
            return $r;
        }
        $tid = TenantContext::id();
        $cfg = Show::config($tid);

        return api_ok([
            'config'    => $cfg,
            'items'     => [
                'gallery' => Show::items($tid, 'gallery', true),
                'honor'   => Show::items($tid, 'honor', true),
            ],
            'stats'     => Show::stats($tid),
            'members'   => $this->publicMembers($tid),
            'notices'   => $this->noticeOptions($tid),
            'guncodes'  => $this->contentOptions($tid, 'gun_codes', 'title'),
            'guides'    => $this->contentOptions($tid, 'guides', 'title'),
            'pending'   => [
                'post'   => (int)Db::name('show_submissions')->where('tenant_id', $tid)->where('type', 'post')->where('status', 'pending')->count(),
                'intent' => (int)Db::name('show_submissions')->where('tenant_id', $tid)->where('type', 'intent')->where('status', 'pending')->count(),
            ],
            // 平台侧状态回给前端做提示条：被下架时队长会以为是自己的开关坏了
            'platform'  => [
                'force_off'  => Show::isForceOff($tid) ? 1 : 0,
                'hall_open'  => Show::hallOpen() ? 1 : 0,
                'site_live'  => (string)TenantContext::status() === 'approved' ? 1 : 0,
            ],
            'url'         => TenantContext::base() . '/show',
            'enabled'     => Show::visible($tid) ? 1 : 0,
            // 站点状态单独回一份：前端要能说清"是站点被停用了，不是你的开关坏了"
            'site_status' => (string)TenantContext::status(),
        ]);
    }

    /**
     * POST /api/admin/showcase/save
     * 标量与勾选一起存；图片另走 uploadImage（这里只接收已上传好的路径）
     */
    public function save()
    {
        if ($r = $this->guard()) {
            return $r;
        }
        $tid  = TenantContext::id();
        $p    = $this->request;
        $mods = Show::config($tid)['modules'];

        $set = function (string $key, string $val) {
            Setting::set($key, $val);
        };

        // 只改"这次请求里出现过的键"：编辑页分块保存（只挪一张图、只改岗位）时
        // 不能顺手把别的设置打回默认值 —— 尤其不能把展示页开关默默关掉
        $has = function (string $k) use ($p): bool { return $p->post($k) !== null; };

        if ($has('enabled'))     $set('show_enabled', (int)$p->post('enabled') === 1 ? '1' : '0');
        if ($has('slogan'))      $set('show_slogan', mb_substr(trim((string)$p->post('slogan')), 0, 40));
        if ($has('contact'))     $set('show_contact', mb_substr(trim((string)$p->post('contact')), 0, 60));
        if ($has('recruiting'))  $set('show_recruiting', (int)$p->post('recruiting') === 1 ? '1' : '0');
        // 队色存原始 #rrggbb，页面上真正用的那几档深浅由 Showcase::accentTokens 按对比度现算；
        // 传空串＝清除，回落到站内默认青绿。格式不对直接拒，别让脏色上互联网
        if ($has('accent')) {
            $raw = trim((string)$p->post('accent'));
            $hex = Show::normalizeAccent($raw);
            if ($raw !== '' && $hex === '') {
                return api_err('队色要用 #rrggbb 形式，例如 #ea580c');
            }
            $set('show_accent', $hex);
        }
        foreach (array_keys(Show::MODULES) as $k) {
            $v = $p->post('module_' . $k);
            if ($v !== null) {
                $mods[$k] = (int)$v === 1 ? 1 : 0;
            }
        }
        $set('show_modules', json_encode($mods, JSON_UNESCAPED_UNICODE));

        // 块顺序与分栏：前端整份发六个键（key:0|1 逗号串），这里再洗一遍落库，
        // 洗的过程会补漏、去重、丢未知键，所以乱发最多排不回默认，不会把访客页的某块弄没
        if ($has('block_order')) {
            $set('show_block_order', Show::normalizeBlockOrder((string)$p->post('block_order')));
        }

        // 招募岗位：前端传 JSON 数组字符串，归一化后再存（脏数据/超上限直接截，不带着渲染）
        if ($has('positions')) {
            $positions = Show::normalizePositions((string)$p->post('positions'));
            $set('show_positions', json_encode($positions, JSON_UNESCAPED_UNICODE));
        }

        foreach (['notice_ids' => 'show_notice_ids', 'guncode_ids' => 'show_guncode_ids', 'guide_ids' => 'show_guide_ids'] as $field => $key) {
            if ($has($field)) {
                $set($key, implode(',', Show::csvIds((string)$p->post($field))));
            }
        }
        foreach (['logo' => 'show_logo', 'banner' => 'show_banner'] as $field => $key) {
            $path = trim((string)$p->post($field, ''));
            if ($path === '-') {                       // '-' = 前端点了"清除这张图"
                ImageUpload::delete(Setting::get($key, ''));
                $set($key, '');
            } elseif ($this->isShowImage($path)) {
                $set($key, $path);
            }
        }

        $set('show_updated_at', date('Y-m-d H:i:s'));
        admin_log('对外展示页', '保存展示页设置（当前开启='
            . (Setting::get('show_enabled', '0') === '1' ? '是' : '否')
            . '，本次提交键：' . implode(',', array_keys($p->post())) . '）');
        return api_ok(['config' => Show::config($tid)], '已保存');
    }

    /** POST /api/admin/showcase/image —— 上传队徽/Banner（kind=logo|banner）或图集/荣誉配图 */
    public function uploadImage()
    {
        if ($r = $this->guard()) {
            return $r;
        }
        $file = $this->request->file('image');
        [$path, $err] = ImageUpload::save($file, 'showcase');
        if ($err !== null) {
            return api_err($err);
        }
        return api_ok(['path' => $path], '图片已上传');
    }

    /** POST /api/admin/showcase/item-add */
    public function itemAdd()
    {
        if ($r = $this->guard()) {
            return $r;
        }
        $tid  = TenantContext::id();
        $kind = (string)$this->request->post('kind', 'gallery');
        if (!in_array($kind, Show::KINDS, true)) {
            return api_err('条目类型不正确');
        }
        if (count(Show::items($tid, $kind, true)) >= Show::MAX_ITEMS) {
            return api_err('这一类最多放 ' . Show::MAX_ITEMS . ' 条，请先清理');
        }
        $image = trim((string)$this->request->post('image', ''));
        if (!$this->isShowImage($image)) {
            return api_err('请先上传图片');
        }
        $max = (int)Db::name('showcase_items')->where('tenant_id', $tid)->where('kind', $kind)->max('sort');
        $id  = (int)Db::name('showcase_items')->insertGetId([
            'tenant_id'  => $tid,
            'kind'       => $kind,
            'title'      => mb_substr(trim((string)$this->request->post('title', '')), 0, 64),
            'note'       => mb_substr(trim((string)$this->request->post('note', '')), 0, 200),
            'image'      => $image,
            'sort'       => $max + 1,
            'visible'    => 1,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        Setting::set('show_updated_at', date('Y-m-d H:i:s'));
        admin_log('对外展示页', '新增' . ($kind === 'honor' ? '荣誉' : '图集') . '条目 #' . $id);
        return api_ok(['id' => $id], '已添加');
    }

    /** POST /api/admin/showcase/item-save —— 改标题/说明/隐藏，图片换不了（换图请删了重加，避免留下孤儿文件） */
    public function itemSave()
    {
        if ($r = $this->guard()) {
            return $r;
        }
        $tid = TenantContext::id();
        $id  = (int)$this->request->post('id', 0);
        $row = Db::name('showcase_items')->where('tenant_id', $tid)->where('id', $id)->find();
        if (!$row) {
            return api_err('条目不存在');
        }
        Db::name('showcase_items')->where('id', $id)->update([
            'title'      => mb_substr(trim((string)$this->request->post('title', (string)$row['title'])), 0, 64),
            'note'       => mb_substr(trim((string)$this->request->post('note', (string)$row['note'])), 0, 200),
            'visible'    => $this->request->post('visible') === null ? (int)$row['visible'] : ((int)$this->request->post('visible') === 1 ? 1 : 0),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        Setting::set('show_updated_at', date('Y-m-d H:i:s'));
        return api_ok([], '已保存');
    }

    /** POST /api/admin/showcase/item-move —— 与同类的相邻条目交换顺序（前端不给手填数字） */
    public function itemMove()
    {
        if ($r = $this->guard()) {
            return $r;
        }
        $tid  = TenantContext::id();
        $id   = (int)$this->request->post('id', 0);
        $dir  = (int)$this->request->post('dir', -1) === 1 ? 1 : -1;
        $row  = Db::name('showcase_items')->where('tenant_id', $tid)->where('id', $id)->find();
        if (!$row) {
            return api_err('条目不存在');
        }
        // 找同类的"下一个/上一个"，按 sort 再按 id，与 items() 的排序口径一致
        // 先把同类按当前显示顺序重排成 0..n-1，再和相邻那一条换位置。
        // 不这么做的两种历史坑：sort 值相同（并发新增）时"上一个"根本定位不到；
        // 以及只改一条的 sort 会随移动次数漂移，越点越乱。
        $kind = (string)$row['kind'];
        $all  = Db::name('showcase_items')->where('tenant_id', $tid)->where('kind', $kind)
            ->order('sort', 'asc')->order('id', 'asc')->column('id');
        $from = array_search($id, $all, false);
        $from = $from === false ? -1 : (int)$from;
        $to   = $from < 0 ? -1 : $from + $dir;
        if ($to < 0 || $from < 0 || $to >= count($all)) {
            return api_err('已经到头了');
        }
        foreach ($all as $i => $itemId) {
            Db::name('showcase_items')->where('id', (int)$itemId)->update(['sort' => $i]);
        }
        $tmp     = $all[$to];
        $all[$to]   = $all[$from];
        $all[$from] = $tmp;
        foreach ($all as $i => $itemId) {
            Db::name('showcase_items')->where('id', (int)$itemId)->update(['sort' => $i]);
        }
        Db::name('showcase_items')->where('tenant_id', $tid)->where('kind', $kind)->update(['updated_at' => date('Y-m-d H:i:s')]);
        Setting::set('show_updated_at', date('Y-m-d H:i:s'));
        return api_ok(['items' => Show::items($tid, $kind, true)], '顺序已调整');
    }

    /** POST /api/admin/showcase/item-del */
    public function itemDel()
    {
        if ($r = $this->guard()) {
            return $r;
        }
        $tid = TenantContext::id();
        $id  = (int)$this->request->post('id', 0);
        $row = Db::name('showcase_items')->where('tenant_id', $tid)->where('id', $id)->find();
        if (!$row) {
            return api_err('条目不存在');
        }
        ImageUpload::delete((string)$row['image']);
        Db::name('showcase_items')->where('id', $id)->delete();
        Setting::set('show_updated_at', date('Y-m-d H:i:s'));
        admin_log('对外展示页', '删除条目 #' . $id . '（' . (string)$row['kind'] . '）');
        return api_ok([], '已删除');
    }

    /** GET /api/admin/showcase/subs?type=&status=&page= */
    public function subs()
    {
        if ($r = $this->guard()) {
            return $r;
        }
        $tid  = TenantContext::id();
        $type = (string)$this->request->get('type', 'intent');
        if (!in_array($type, Show::TYPES, true)) {
            return api_err('类型不正确');
        }
        $status = (string)$this->request->get('status', 'pending');
        $page   = max(1, (int)$this->request->get('page', 1));
        $size   = 20;

        // 计数与列表各建一条查询：think-orm 的 count() 会把这条 query 的条件消费掉，
        // 复用同一个 $q 再 select() 就成了"总数对、列表是全部"
        $cond = [['tenant_id', '=', $tid], ['type', '=', $type]];
        if (in_array($status, Show::SUB_STATUSES, true)) {
            $cond[] = ['status', '=', $status];
        } else {
            $status = '';
        }
        $total = (int)Db::name('show_submissions')->where($cond)->count();
        $rows  = Db::name('show_submissions')->where($cond)
            ->order('id', 'desc')->page($page, $size)->select()->toArray();

        $list = [];
        foreach ($rows as $s) {
            $list[] = [
                'id'          => (int)$s['id'],
                'type'        => (string)$s['type'],
                // QQ 只在收件箱里给全：队长要顺着它去联系人，且这个接口在 AdminCheck + showcase 权限之后
                'qq'          => (string)$s['qq'],
                'nick'        => (string)$s['nick'],
                'position'    => (string)$s['position'],
                'content'     => (string)$s['content'],
                'image'       => (string)$s['image'],
                'status'      => (string)$s['status'],
                'result_note' => (string)$s['result_note'],
                'region'      => (string)$s['region'],
                'created_at'  => (string)$s['created_at'],
                'reviewed_at' => (string)$s['reviewed_at'],
            ];
        }
        return api_ok([
            'list'  => $list,
            'total' => $total,
            'page'  => $page,
            'size'  => $size,
            'count' => [
                'pending'  => (int)Db::name('show_submissions')->where('tenant_id', $tid)->where('type', $type)->where('status', 'pending')->count(),
                'approved' => (int)Db::name('show_submissions')->where('tenant_id', $tid)->where('type', $type)->where('status', 'approved')->count(),
                'rejected' => (int)Db::name('show_submissions')->where('tenant_id', $tid)->where('type', $type)->where('status', 'rejected')->count(),
            ],
        ]);
    }

    /**
     * POST /api/admin/showcase/sub-review —— 采纳 / 驳回
     * 采纳投稿就是把内容推到互联网上，所以逐次写操作日志；驳回则顺手删掉附图文件。
     */
    public function subReview()
    {
        if ($r = $this->guard()) {
            return $r;
        }
        $tid    = TenantContext::id();
        $id     = (int)$this->request->post('id', 0);
        $action = (string)$this->request->post('action', '');
        if (!in_array($action, ['approve', 'reject'], true)) {
            return api_err('操作不正确');
        }
        $row = Db::name('show_submissions')->where('tenant_id', $tid)->where('id', $id)->find();
        if (!$row) {
            return api_err('这条提交不存在');
        }
        $status = $action === 'approve' ? 'approved' : 'rejected';
        $note   = mb_substr(trim((string)$this->request->post('note', '')), 0, 200);
        Db::name('show_submissions')->where('id', $id)->update([
            'status'      => $status,
            'result_note' => $note,
            'reviewed_by' => (int)(current_user()->id ?? 0),
            'reviewed_at' => date('Y-m-d H:i:s'),
            'updated_at'  => date('Y-m-d H:i:s'),
        ]);
        if ($action === 'reject' && (string)$row['image'] !== '') {
            ImageUpload::delete((string)$row['image']);
            Db::name('show_submissions')->where('id', $id)->update(['image' => '']);
        }
        admin_log('对外展示页', ($action === 'approve' ? '采纳' : '驳回') . '访客'
            . ((string)$row['type'] === 'post' ? '投稿' : '入队意向') . ' #' . $id
            . '（' . (string)$row['nick'] . '）' . ($note !== '' ? '：' . $note : ''));
        return api_ok([], $action === 'approve' ? '已采纳' : '已驳回');
    }

    /** POST /api/admin/showcase/sub-del —— 连文件一起删（灌进来的垃圾不留着占配额） */
    public function subDel()
    {
        if ($r = $this->guard()) {
            return $r;
        }
        $tid = TenantContext::id();
        $id  = (int)$this->request->post('id', 0);
        $row = Db::name('show_submissions')->where('tenant_id', $tid)->where('id', $id)->find();
        if (!$row) {
            return api_err('这条提交不存在');
        }
        if ((string)$row['image'] !== '') {
            ImageUpload::delete((string)$row['image']);
        }
        Db::name('show_submissions')->where('id', $id)->delete();
        admin_log('对外展示页', '删除访客提交 #' . $id . '（' . (string)$row['type'] . '）');
        return api_ok([], '已删除');
    }

    // ==================== 内部 ====================

    /** 已勾选公开自己的成员（队长只看得见"谁同意了"，勾是成员自己在个人主页做的） */
    protected function publicMembers(int $tid): array
    {
        $rows = Db::name('users')->where('tenant_id', $tid)->where('status', 'approved')->where('show_public', 1)
            ->field('id, nickname, role_tags')->order('id', 'asc')->limit(80)->select()->toArray();
        $tags = Db::name('role_tags')->where('tenant_id', $tid)->column('name', 'id');
        $out  = [];
        foreach ($rows as $r) {
            $ids  = json_decode((string)$r['role_tags'], true);
            $out[] = [
                'id'   => (int)$r['id'],
                'name' => (string)$r['nickname'],
                'tag'  => (is_array($ids) && $ids !== []) ? (string)($tags[(int)reset($ids)] ?? '') : '',
            ];
        }
        return $out;
    }

    /** 可勾的动态（公告）候选：最近 30 条，已勾的排前面 */
    protected function noticeOptions(int $tid): array
    {
        // v5.36.34：撤回过的不再给挑（已经撤掉的东西不该再摆到访客脸上）；
        // 定时未到点的仍然列出来并标一句，队长可以提前勾好，到点自动出
        $now  = date('Y-m-d H:i:s');
        $rows = Db::name('announcements')->where('tenant_id', $tid)
            ->where('game_side', '')          // v5.36.0：只给访客页挑全队共享的公告
            ->whereNull('revoked_at')
            ->order('created_at', 'desc')->limit(30)->field('id, title, created_at, scheduled_at')->select()->toArray();
        $out = [];
        foreach ($rows as $r) {
            $sched = (string)($r['scheduled_at'] ?? '');
            $out[] = ['id' => (int)$r['id'], 'title' => (string)$r['title'], 'created_at' => (string)$r['created_at'],
                      'not_live_yet' => ($sched !== '' && $sched > $now) ? 1 : 0];
        }
        return $out;
    }

    /** 可勾的精选内容候选（改枪码/攻略，只列仍然 published 的） */
    protected function contentOptions(int $tid, string $table, string $titleCol): array
    {
        $rows = Db::name($table)->where('tenant_id', $tid)->where('status', 'published')
            ->order('id', 'desc')->limit(30)->field("id, $titleCol AS title")->select()->toArray();
        $out = [];
        foreach ($rows as $r) {
            $out[] = ['id' => (int)$r['id'], 'title' => (string)$r['title']];
        }
        return $out;
    }

    /**
     * 只认本系统上传落盘的相对路径（形如 /storage/uploads/showcase/20260101/32hex.jpg）：
     * 直接把前端传来的字符串写进 settings，等于允许任何人往里塞任意外链或 ../ 路径。
     */
    protected function isShowImage(string $path): bool
    {
        if ($path === '') {
            return false;
        }
        $one = preg_match('#^/storage/uploads/showcase/\d{8}/[0-9a-f]{32}\.(jpg|jpeg|png|gif|webp)$#', $path);
        if ($one === 1) {
            return is_file(public_path() . ltrim(str_replace('/', DIRECTORY_SEPARATOR, $path), DIRECTORY_SEPARATOR));
        }
        return false;
    }
}
