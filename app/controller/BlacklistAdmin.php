<?php
declare(strict_types=1);

namespace app\controller;

use app\model\Blacklist;

/**
 * 黑名单管理（v5.6.0，blacklist 权限）
 * 本队级「前科档案」：核心作用在注册审核环节自动预警（见 Admin::reviewList）
 * 命中不自动拒绝，最终决定权在管理员
 */
class BlacklistAdmin extends AdminBase
{
    private function noPerm()
    {
        return api_err('您没有权限执行此操作，请联系超级管理员分配权限', 403, 403);
    }

    // 列表（支持按 QQ/昵称搜索）
    public function index()
    {
        if (!admin_can('blacklist')) {
            return $this->noPerm();
        }
        $keyword = trim((string)$this->request->get('keyword', ''));
        $query   = Blacklist::order('id', 'desc');
        if ($keyword !== '') {
            $query->whereLike('qq|nickname|reason', '%' . $keyword . '%');
        }
        return api_ok(['list' => $query->select()]);
    }

    // 新增 / 编辑
    public function save()
    {
        if (!admin_can('blacklist')) {
            return $this->noPerm();
        }
        $id       = (int)$this->request->post('id', 0);
        $qq       = trim((string)$this->request->post('qq', ''));
        $nickname = trim((string)$this->request->post('nickname', ''));
        $reason   = trim((string)$this->request->post('reason', ''));

        if ($qq === '' || strlen($qq) > 16) {
            return api_err('请填写 QQ 号');
        }
        if ($reason === '' || mb_strlen($reason) > 200) {
            return api_err('请填写拉黑原因（不超过 200 字）');
        }
        if (mb_strlen($nickname) > 32) {
            return api_err('昵称过长');
        }

        // 同租户 QQ 唯一（排除自身）
        $dup = Blacklist::where('qq', $qq);
        if ($id > 0) {
            $dup->where('id', '<>', $id);
        }
        if ($dup->find()) {
            return api_err('该 QQ 已在黑名单中');
        }

        $me = current_user();
        if ($id > 0) {
            $row = Blacklist::find($id);
            if (!$row) {
                return api_err('记录不存在', 404);
            }
            $row->save(['qq' => $qq, 'nickname' => $nickname, 'reason' => $reason]);
            admin_log('黑名单', '编辑黑名单条目 QQ=' . $qq);
            return api_ok([], '已更新');
        }

        Blacklist::create([
            'qq'         => $qq,
            'nickname'   => $nickname,
            'reason'     => $reason,
            'created_by' => $me ? (string)($me->nickname ?: $me->username) : '',
        ]);
        admin_log('黑名单', '添加黑名单 QQ=' . $qq . ' 原因=' . $reason);
        return api_ok([], '已加入黑名单');
    }

    // 移出黑名单（解封）
    public function remove($id)
    {
        if (!admin_can('blacklist')) {
            return $this->noPerm();
        }
        $row = Blacklist::find((int)$id);
        if (!$row) {
            return api_err('记录不存在', 404);
        }
        admin_log('黑名单', '移出黑名单 QQ=' . $row->qq);
        $row->delete();
        return api_ok([], '已移出黑名单');
    }
}
