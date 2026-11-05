<?php
declare (strict_types = 1);

namespace app\controller;

use app\BaseController;
use app\service\ForumFeed;
use think\facade\Db;

/**
 * 社区论坛对接（v5.35.0，平台侧）：只有平台超级管理员能用
 *
 *   GET  /api/platform/admin/forum-api            哪些队开了、谁在调、总闸状态
 *   POST /api/platform/admin/forum-api/master     开/关总闸（一把按住全站）
 *   POST /api/platform/admin/forum-api/toggle     单独停用/恢复某一队
 *
 * 为什么平台要管这一格：数据是要出站的。哪天那个论坛被拖库、或者一把 Key 漏了，
 * 需要一个不用挨个通知队长去关的动作 —— 所以总闸 + 按站点停用两条都要有，
 * 而且战队那边读得到"是平台挡的我"，不是对着一个 403 猜。
 * 这里只写"停不停"，不改队长自己那一格：队长设的原样留着，放回去就照旧生效。
 */
class PlatformForumApi extends BaseController
{
    public function config()
    {
        if ($r = $this->gate()) {
            return $r;
        }
        $tenants = Db::name('tenants')->where('status', 'approved')
            ->field('id, slug, name')->order('id', 'asc')->limit(2000)->select()->toArray();
        $ids  = array_column($tenants, 'id');
        $on   = $ids === [] ? [] : Db::name('settings')->whereIn('tenant_id', $ids)
            ->where('key', ForumFeed::KEY_TENANT)->column('value', 'tenant_id');
        $force = ForumFeed::forceOffList();
        $calls = [];
        $last  = [];
        try {
            foreach (Db::name('api_logs')->whereLike('path', '/api/open/forum/%')
                ->where('created_at', '>=', date('Y-m-d H:i:s', time() - 86400))
                ->group('tenant_id')->field('tenant_id, COUNT(*) AS c')->select()->toArray() as $r) {
                $calls[(int)$r['tenant_id']] = (int)$r['c'];
            }
            foreach (Db::name('api_logs')->whereLike('path', '/api/open/forum/%')
                ->order('id', 'desc')->limit(120)->select()->toArray() as $r) {
                $tid = (int)$r['tenant_id'];
                if (!isset($last[$tid])) {
                    $last[$tid] = (string)$r['created_at'];
                }
            }
        } catch (\Throwable $e) {
            // 老库没有 api_logs：下面的数字按 0 显示，不把这一页打挂
        }

        $list = [];
        $serving = 0;
        foreach ($tenants as $t) {
            $tid = (int)$t['id'];
            $selfOn = strtolower(trim((string)($on[$tid] ?? '0'))) === '1';
            $off    = in_array($tid, $force, true);
            $live   = $selfOn && !$off && ForumFeed::platformOn();
            if ($live) {
                $serving++;
            }
            $list[] = [
                'id'         => $tid,
                'slug'       => (string)$t['slug'],
                'name'       => (string)$t['name'],
                'tenant_on'  => $selfOn ? 1 : 0,
                'force_off'  => $off ? 1 : 0,
                'serving'    => $live ? 1 : 0,
                'calls_24h'  => $calls[$tid] ?? 0,
                'last_call'  => $last[$tid] ?? '',
            ];
        }
        return api_ok([
            'master_on' => ForumFeed::platformOn(),
            'serving'   => $serving,
            'listed'    => count($list),
            'list'      => $list,
        ]);
    }

    public function master()
    {
        if ($r = $this->gate()) {
            return $r;
        }
        $on = (string)$this->request->post('on', '1') === '1';
        ForumFeed::setPlatformOn($on);
        admin_log('论坛对接', ($on ? '打开' : '停用') . '全站社区论坛对接总闸');
        return api_ok(['master_on' => ForumFeed::platformOn()],
            $on ? '总闸已打开' : '总闸已停用，所有队现在都读不到数据');
    }

    public function toggle()
    {
        if ($r = $this->gate()) {
            return $r;
        }
        $tid = (int)$this->request->post('tenant_id', 0);
        if ($tid <= 0 || !Db::name('tenants')->where('id', $tid)->value('id')) {
            return api_err('没有这个站点', 404, 404);
        }
        $off = (string)$this->request->post('off', '1') === '1';
        ForumFeed::setForceOff($tid, $off);
        admin_log('论坛对接', ($off ? '停用' : '恢复') . '站点 #' . $tid . ' 的社区论坛对接');
        return api_ok(['force_off' => $off ? 1 : 0,
            'serving' => ForumFeed::gate($tid)['on'] ? 1 : 0],
            $off ? '这一队已停用' : '这一队已恢复（照队长自己那一格的状态走）');
    }

    /** @return \think\Response|null */
    private function gate()
    {
        if (!is_platform_super()) {
            return api_err('需要平台超级管理员登录态', 403, 403);
        }
        return null;
    }
}
