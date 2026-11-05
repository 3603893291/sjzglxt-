<?php
declare (strict_types = 1);

namespace app\controller;

use app\BaseController;
use app\service\GameSide;
use app\service\RollCall as Svc;
use app\service\TenantContext;
use think\facade\Cache;
use think\facade\Db;

/**
 * 比赛日集合点名（v5.36.34）
 *
 * 成员端：GET /api/rollcall 看今天与接下来的几张、GET /api/rollcall/:id 看谁到了谁没回、
 *         POST /api/rollcall/:id/reply 点「到 / 迟到 / 不来」（一人一行，改口是整行覆盖）。
 * 管理端：/api/admin/rollcall*（要「撞车时间」或「日程活动」任一权限）——
 *         开单、改时间、撤回/恢复、删单、手动再往群里喊一次。
 *
 * 一张单上的「谁到了」是队内公开的（与战绩、在线名单同一口径）；跨队一律隔死，
 * 所有查询都带 tenant_id，别的战队连这张单存不存在都问不出来。
 */
class RollCall extends BaseController
{
    /** 开点名单的两种人：管比赛时间的、管日程活动的（站长恒真） */
    private function canManage(): bool
    {
        return admin_can_any(['race', 'schedule']);
    }

    private function noPerm()
    {
        return api_err('您没有权限执行此操作，请联系超级管理员分配权限', 403, 403);
    }

    /** 按 id + 当前战队取单：问不出别队的内容 */
    private function find(int $id): ?array
    {
        $row = Db::name('roll_calls')->where('tenant_id', TenantContext::id())
            ->where('id', $id)->find();
        return $row ?: null;
    }

    // ==================== 成员端 ====================

    /** 今天与接下来的几张单（撤回的不出；不是你这个端的也不出） */
    public function index()
    {
        $user = current_user();
        if (!$user) {
            return api_err('未登录', 401, 401);
        }
        $rows = Db::name('roll_calls')->where('tenant_id', TenantContext::id())
            ->whereNull('revoked_at')
            ->where('call_at', '>=', date('Y-m-d 00:00:00'))
            ->order('call_at', 'asc')->limit(20)->select()->toArray();
        $out = [];
        foreach ($rows as $r) {
            if (GameSide::canSee('race', (string)($r['game_side'] ?? ''))) {
                $out[] = Svc::present($r, (int)$user->id);
            }
        }
        return api_ok(['list' => $out, 'server_time' => date('Y-m-d H:i:s'),
                       'lead_min' => Svc::LEAD_MIN]);
    }

    /** 单张明细：谁到了、谁没回（队内公开） */
    public function detail($id)
    {
        $user = current_user();
        if (!$user) {
            return api_err('未登录', 401, 401);
        }
        $row = $this->find((int)$id);
        if (!$row || (string)($row['revoked_at'] ?? '') !== ''
            || !GameSide::canSee('race', (string)($row['game_side'] ?? ''))) {
            return api_err('点名单不存在', 404);
        }
        return api_ok(Svc::present($row, (int)$user->id));
    }

    /** 点「到 / 迟到 / 不来」，可以附一句话；改口是整行覆盖 */
    public function reply($id)
    {
        $user = current_user();
        if (!$user) {
            return api_err('未登录', 401, 401);
        }
        $reply = trim((string)$this->request->post('reply', ''));
        if (!isset(Svc::REPLIES[$reply])) {
            return api_err('请点「到」「迟到」或「不来」其中一项');
        }
        $note = mb_substr(trim((string)$this->request->post('note', '')), 0, 128);
        $row  = $this->find((int)$id);
        if (!$row || (string)($row['revoked_at'] ?? '') !== ''
            || !GameSide::canSee('race', (string)($row['game_side'] ?? ''))) {
            return api_err('点名单不存在', 404);
        }
        $dl = trim((string)($row['deadline'] ?? ''));
        if ($dl !== '' && $dl < date('Y-m-d H:i:s')) {
            return api_err('这张点名单已经截止了（截至 ' . $dl . '）');
        }
        $tid = TenantContext::id();
        $uid = (int)$user->id;
        $now = date('Y-m-d H:i:s');
        $old = Db::name('roll_call_replies')->where('call_id', (int)$row['id'])
            ->where('user_id', $uid)->find();
        if ($old) {
            Db::name('roll_call_replies')->where('id', (int)$old['id'])->update([
                'reply' => $reply, 'note' => $note, 'replied_at' => $now,
            ]);
        } else {
            Db::name('roll_call_replies')->insert([
                'call_id'    => (int)$row['id'],
                'tenant_id'  => $tid,
                'user_id'    => $uid,
                'reply'      => $reply,
                'note'       => $note,
                'replied_at' => $now,
            ]);
        }
        return api_ok([], '已记下：' . Svc::REPLIES[$reply]);
    }

    // ==================== 管理端 ====================

    /** 全部单（含撤回与历史的），队长要看得见 */
    public function adminList()
    {
        if (!$this->canManage()) {
            return $this->noPerm();
        }
        $rows = Db::name('roll_calls')->where('tenant_id', TenantContext::id())
            ->order('call_at', 'desc')->limit(50)->select()->toArray();
        $out = [];
        foreach ($rows as $r) {
            $out[] = Svc::present($r, 0);
        }
        // 开单时想挂哪场撞车：这一份只给「还没开始」的场次，队长有 race 或有 schedule 都能挑，
        // 所以不复用 /api/admin/races（那条只认 race 权限，会把这个页签卡住）
        $events = Db::name('race_events')->where('tenant_id', TenantContext::id())
            ->where('race_time', '>', date('Y-m-d H:i:s'))
            ->order('race_time', 'asc')->limit(20)
            ->field('id, title, race_time')->select()->toArray();
        return api_ok(['list' => $out, 'events' => $events,
                       'server_time' => date('Y-m-d H:i:s'),
                       'lead_min' => Svc::LEAD_MIN,
                       'replies'  => Svc::REPLIES]);
    }

    /** 开一张 / 改一张（multipart 不走，普通 post 字段就够） */
    public function save()
    {
        if (!$this->canManage()) {
            return $this->noPerm();
        }
        $id    = (int)$this->request->post('id', 0);
        $title = trim((string)$this->request->post('title', ''));
        $callAt = trim((string)$this->request->post('call_at', ''));
        $dl     = trim((string)$this->request->post('deadline', ''));
        $evId   = (int)$this->request->post('event_id', 0);
        $side   = GameSide::moduleOn('race') ? GameSide::clean($this->request->post('game_side', '')) : '';

        if ($title === '' || mb_strlen($title) > 128) {
            return api_err('请填写集合标题（不超过 128 字）');
        }
        $callTs = strtotime($callAt);
        if ($callTs === false) {
            return api_err('集合时间格式不正确');
        }
        $callVal = date('Y-m-d H:i:s', $callTs);
        $nowTs   = time();
        if ($id === 0 && $callTs < $nowTs - 60) {
            return api_err('集合时间不能是过去；已经集合完了就不用开这张单了');
        }
        if ($callTs > $nowTs + 60 * 86400) {
            return api_err('集合时间最多排到 60 天以后');
        }
        $dlVal = null;
        if ($dl !== '') {
            $dlTs = strtotime($dl);
            if ($dlTs === false) {
                return api_err('截止时间格式不正确');
            }
            if ($dlTs < $callTs) {
                return api_err('截止时间不能早于集合时间');
            }
            $dlVal = date('Y-m-d H:i:s', $dlTs);
        }
        if ($evId > 0 && !Db::name('race_events')->where('tenant_id', TenantContext::id())
            ->where('id', $evId)->find()) {
            return api_err('想挂的那场比赛不在这个战队里');
        }

        $tid = TenantContext::id();
        if ($id > 0) {
            $row = $this->find($id);
            if (!$row) {
                return api_err('点名单不存在', 404);
            }
            $data = ['title' => $title, 'call_at' => $callVal, 'deadline' => $dlVal,
                     'event_id' => $evId, 'updated_at' => date('Y-m-d H:i:s')];
            if (GameSide::moduleOn('race')) {
                $data['game_side'] = $side;
            }
            // 改了集合时间＝重新排一次群通知：不然队长把 20:00 改成明晚，群里却再也不喊
            if ((string)($row['call_at'] ?? '') !== $callVal) {
                $data['notify_at'] = null;
            }
            Db::name('roll_calls')->where('id', $id)->update($data);
            admin_log('集合点名', '编辑点名单 #' . $id . ' ' . $title . '（集合 ' . $callVal . '）');
            return api_ok(['id' => $id], '点名单已更新');
        }

        $new = date('Y-m-d H:i:s');
        $id = (int)Db::name('roll_calls')->insertGetId([
            'tenant_id'  => $tid,
            'game_side'  => $side,
            'title'      => $title,
            'event_id'   => $evId,
            'call_at'    => $callVal,
            'deadline'   => $dlVal,
            'created_by' => (int)(current_user()->id ?? 0),
            'created_at' => $new,
            'updated_at' => $new,
        ]);
        admin_log('集合点名', '开点名单 #' . $id . ' ' . $title . '（集合 ' . $callVal . '）');
        return api_ok(['id' => $id], '点名单已开，成员在首页就能点「到 / 迟到 / 不来」');
    }

    /** 撤回：成员那边立刻不出，回执与单子都留着，能恢复 */
    public function revoke($id)
    {
        if (!$this->canManage()) {
            return $this->noPerm();
        }
        $row = $this->find((int)$id);
        if (!$row) {
            return api_err('点名单不存在', 404);
        }
        if ((string)($row['revoked_at'] ?? '') !== '') {
            return api_err('这张已经是撤回状态了');
        }
        Db::name('roll_calls')->where('id', (int)$id)->update(['revoked_at' => date('Y-m-d H:i:s')]);
        admin_log('集合点名', '撤回点名单 #' . $id . ' ' . (string)$row['title']);
        return api_ok([], '已撤回，成员那边看不到了（谁到过仍然留着，可以恢复）');
    }

    public function restore($id)
    {
        if (!$this->canManage()) {
            return $this->noPerm();
        }
        $row = $this->find((int)$id);
        if (!$row) {
            return api_err('点名单不存在', 404);
        }
        if ((string)($row['revoked_at'] ?? '') === '') {
            return api_err('这张没被撤回，不用恢复');
        }
        Db::name('roll_calls')->where('id', (int)$id)->update(['revoked_at' => null]);
        admin_log('集合点名', '恢复点名单 #' . $id . ' ' . (string)$row['title']);
        return api_ok([], '已恢复，成员又能看到这张点名单');
    }

    public function remove($id)
    {
        if (!$this->canManage()) {
            return $this->noPerm();
        }
        $row = $this->find((int)$id);
        if (!$row) {
            return api_err('点名单不存在', 404);
        }
        Db::name('roll_call_replies')->where('call_id', (int)$id)->where('tenant_id', TenantContext::id())->delete();
        Db::name('roll_calls')->where('id', (int)$id)->where('tenant_id', TenantContext::id())->delete();
        admin_log('集合点名', '删除点名单 #' . $id . ' ' . (string)$row['title']);
        return api_ok([], '点名单与这份回执都删掉了');
    }

    /** 队长手动「再喊一次」：往本队 QQ 群再推一句，5 分钟内只让按一次 */
    public function broadcast($id)
    {
        if (!$this->canManage()) {
            return $this->noPerm();
        }
        $row = $this->find((int)$id);
        if (!$row) {
            return api_err('点名单不存在', 404);
        }
        $key = 'rollcall_bc_' . TenantContext::id() . '_' . (int)$id;
        if (Cache::get($key) !== null) {
            return api_err('刚喊过一次了，请 5 分钟后再按');
        }
        $ok = Svc::broadcast(TenantContext::id(), $row);
        Cache::set($key, 1, 300);
        Db::name('roll_calls')->where('id', (int)$id)->update(['notify_at' => date('Y-m-d H:i:s')]);
        admin_log('集合点名', '手动再喊 #' . (int)$id . ' ' . (string)$row['title']
            . ($ok ? '（QQ 群已送到）' : '（QQ 群没送到：本队没绑群或机器人停用）'));
        return $ok ? api_ok([], '已在 QQ 群里又喊了一遍')
            : api_ok([], '站内这条照常，但 QQ 群那一路没送到（这一队没绑群或机器人已停用）');
    }
}
