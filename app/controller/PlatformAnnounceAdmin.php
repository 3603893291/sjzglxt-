<?php
declare (strict_types = 1);

namespace app\controller;

use app\BaseController;
use app\service\PlatformAnnounce;
use think\facade\Db;

/**
 * 强制平台公告·平台侧（v5.36.31）：只有平台超级管理员能用。
 *
 *   GET  /api/platform/admin/announcements          列表（每条带阅读计数：应读/已读/未读/静音）
 *   GET  /api/platform/admin/announcement?id=       一条公告的正文 + 按战队的阅读汇总
 *   POST /api/platform/admin/announcement/save      新建或修改（{id?,title,content,status}）
 *   POST /api/platform/admin/announcement/status    上线 / 下线（{id,status}）
 *   POST /api/platform/admin/announcement/delete    删除（连送达确认一起删）
 *   POST /api/platform/admin/announcement/mute      「不再提示」这颗按钮给不给成员（{allow}）
 *
 * 四条口径（与 app\service\PlatformAnnounce 顶部那四条对得上）：
 *   ① 弹给「全体已上线成员」，与邮件群发同一批人；应读数现算，不落快照。
 *   ② 同时最多 3 条在线；改标题正文算同一条，已读人数不清零。
 *   ③ 「我已知晓」只停这一条，「不再提示」停以后所有 —— 后者可以在这里整颗钮关掉。
 *   ④ 这一页不发邮件也不发站内信：公告只在成员登录战队时弹那一个框，别的地方不重复打扰。
 */
class PlatformAnnounceAdmin extends BaseController
{
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

    /** 平台侧动作落 admin_logs(tenant_id=0)：谁发的、谁下的线、谁删的 */
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
            // 审计写不进去不能反过来把公告卡住
        }
    }

    /** GET /api/platform/admin/announcements */
    public function index()
    {
        if ($r = $this->gate()) {
            return $r;
        }
        $ok = PlatformAnnounce::available();
        $rows = $ok ? PlatformAnnounce::all(50) : [];
        $list = [];
        foreach ($rows as $b) {
            $id = (int)$b['id'];
            $st = $ok ? PlatformAnnounce::stats($id) : ['need' => 0, 'read' => 0, 'unread' => 0, 'ack' => 0, 'muted' => 0, 'rate' => 0];
            $content = (string)$b['content'];
            $list[] = [
                'id'           => $id,
                'title'        => (string)$b['title'],
                'content_head' => mb_substr($content, 0, 80),
                'content_len'  => mb_strlen($content),
                'status'       => (int)$b['status'],
                'actor'        => (string)$b['actor_name'],
                'created_at'   => (string)($b['created_at'] ?? ''),
                'updated_at'   => (string)($b['updated_at'] ?? ''),
                'need'         => (int)$st['need'],
                'read'         => (int)$st['read'],
                'unread'       => (int)$st['unread'],
                'ack'          => (int)$st['ack'],
                'muted'        => (int)$st['muted'],
                'rate'         => (int)$st['rate'],
            ];
        }
        return api_ok([
            'list'       => $list,
            'available'  => $ok ? 1 : 0,
            'allow_mute' => PlatformAnnounce::muteAllowed() ? 1 : 0,
            'online_max' => PlatformAnnounce::ONLINE_MAX,
            'title_max'  => PlatformAnnounce::TITLE_MAX,
            'content_max' => PlatformAnnounce::CONTENT_MAX,
            'global_muted' => $ok ? PlatformAnnounce::globalMuted() : 0,
        ]);
    }

    /** GET /api/platform/admin/announcement?id= —— 一条的正文 + 按战队汇总 */
    public function detail()
    {
        if ($r = $this->gate()) {
            return $r;
        }
        if (!PlatformAnnounce::available()) {
            return api_err('强制公告的表还没建：先执行 database/upgrade_v5.36.30_to_v5.36.31.sql（或打开 notice_install_v53631.php）', 500);
        }
        $id = (int)$this->request->get('id', 0);
        $b = Db::name('platform_announcements')->where('id', $id)->find();
        if (!$b) {
            return api_err('公告不存在', 404, 404);
        }
        return api_ok([
            'id'      => $id,
            'title'   => (string)$b['title'],
            'content' => (string)$b['content'],
            'status'  => (int)$b['status'],
            'actor'   => (string)$b['actor_name'],
            'created_at' => (string)($b['created_at'] ?? ''),
            'stats'   => PlatformAnnounce::stats($id),
        ]);
    }

    /** POST /api/platform/admin/announcement/save */
    public function save()
    {
        if ($r = $this->gate()) {
            return $r;
        }
        if (!PlatformAnnounce::available()) {
            return api_err('强制公告的表还没建：先执行 database/upgrade_v5.36.30_to_v5.36.31.sql（或打开 notice_install_v53631.php）', 500);
        }
        $id     = (int)$this->request->post('id', 0);
        $title  = (string)$this->request->post('title', '');
        $content = (string)$this->request->post('content', '');
        $status = (int)$this->request->post('status', 1) === 0 ? 0 : 1;
        [$actorId, $actorName] = $this->actor();
        [$newId, $err] = PlatformAnnounce::save($id, $title, $content, $status, $actorId, $actorName);
        if ($newId <= 0) {
            return api_err($err);
        }
        $this->audit('强制公告-' . ($id > 0 ? '修改' : '发布'),
            '#' . $newId . '「' . mb_substr(trim($title), 0, 40) . '」'
            . ($status === 1 ? '（在线弹出）' : '（不弹，只存草稿）')
            . '，正文 ' . mb_strlen(trim($content)) . ' 字');
        return api_ok(['id' => $newId, 'stats' => PlatformAnnounce::stats($newId)],
            $id > 0 ? '已保存（算同一条，已读人数保留）' : ($status === 1 ? '已发布，成员登录战队就会看到' : '已存好，还没上线'));
    }

    /** POST /api/platform/admin/announcement/status {id, status} */
    public function status()
    {
        if ($r = $this->gate()) {
            return $r;
        }
        $id = (int)$this->request->post('id', 0);
        $on = (int)$this->request->post('status', 0) === 1;
        if (!PlatformAnnounce::setStatus($id, $on)) {
            return api_err($on
                ? '上线没成：这条不存在，或同时在线的公告已经满 ' . PlatformAnnounce::ONLINE_MAX . ' 条了（先下线一条）'
                : '这条公告不存在');
        }
        $this->audit('强制公告-' . ($on ? '上线' : '下线'), '#' . $id . ($on ? ' 开始弹给全体成员' : ' 停止弹出（记录与已读人数都保留）'));
        return api_ok(['stats' => PlatformAnnounce::stats($id)], $on ? '上线了，成员下次登录就弹' : '下线了，不再弹给新的人');
    }

    /** POST /api/platform/admin/announcement/delete {id} */
    public function remove()
    {
        if ($r = $this->gate()) {
            return $r;
        }
        $id = (int)$this->request->post('id', 0);
        if (!PlatformAnnounce::remove($id)) {
            return api_err('这条公告不存在');
        }
        $this->audit('强制公告-删除', '#' . $id . ' 连送达确认一起删掉了（不可恢复）');
        return api_ok([], '已删除');
    }

    /** POST /api/platform/admin/announcement/mute {allow} —— 「不再提示」这颗按钮给不给 */
    public function mute()
    {
        if ($r = $this->gate()) {
            return $r;
        }
        $allow = (string)$this->request->post('allow', '1') !== '0';
        PlatformAnnounce::setMuteAllowed($allow);
        $muted = PlatformAnnounce::globalMuted();
        $this->audit('强制公告-静音开关', $allow
            ? '成员可以点「不再提示」（当前已有 ' . $muted . ' 人静音）'
            : '关掉「不再提示」：成员只能点「我已知晓」，已静音的 ' . $muted . ' 人不受这次改动影响');
        return api_ok([
            'allow_mute' => $allow ? 1 : 0,
            'global_muted' => $muted,
        ], $allow ? '「不再提示」放开了' : '「不再提示」关掉了（已经点过的人要他自己回来点才行，这里救不回来）');
    }
}
