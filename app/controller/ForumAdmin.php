<?php
declare (strict_types = 1);

namespace app\controller;

use app\service\ForumFeed;
use app\service\TenantContext;
use think\facade\Db;

/**
 * 社区论坛对接（v5.35.0，战队侧）：只有这一队的站长/超管能用
 *
 *   GET  /api/admin/forum-api      现在开没开、哪一道闸挡着、四条接口地址、用哪把 Key、最近谁来调过
 *   POST /api/admin/forum-api/toggle  开或关
 *
 * 为什么不发新 Key：站内从 v5.4.0 起就有私有接口密钥那一格（约战在用），库里只存 sha256、
 * 每 Key 每 IP 都限频、认证失败连击会锁 IP、每次调用都进 api_logs。再发一把同类 Key 只是多一个要管的东西。
 * 代价说清楚：scope=tenant 那把 Key 本来也能读约战接口，所以别把它交给这支队之外的第三方。
 */
class ForumAdmin extends AdminBase
{
    /** 这一页的四条只读接口（路径写死在这儿，前端照着列出来，不让两边各拼一遍） */
    private const ENDPOINTS = [
        ['/api/open/forum/team', '战队名片与招募文案'],
        ['/api/open/forum/members', '成员名单与公开战绩'],
        ['/api/open/forum/activity', '近 30 天活动与签到榜'],
        ['/api/open/forum/notices', '对外展示页勾选过的公告'],
    ];

    public function index()
    {
        if ($r = $this->superOnly()) {
            return $r;
        }
        $tid = (int)TenantContext::id();
        $g   = ForumFeed::gate($tid);

        $keys = Db::name('api_keys')->where('scope', 'tenant')->where('tenant_id', $tid)
            ->field('id, key_prefix, name, status, last_used_at, created_at')
            ->order('id', 'desc')->limit(20)->select()->toArray();

        $recent = [];
        try {
            $recent = Db::name('api_logs')->where('tenant_id', $tid)
                ->whereLike('path', '/api/open/forum/%')
                ->field('method, path, status, result, fail_reason, ip, duration_ms, created_at')
                ->order('id', 'desc')->limit(10)->select()->toArray();
        } catch (\Throwable $e) {
            // 老库没升 api_logs 那张表：这一格空着就行，别把整页打挂
        }

        return api_ok([
            'on'        => ForumFeed::tenantOn($tid),
            'serving'   => $g['on'],
            'reason'    => (string)$g['reason'],
            'platform'  => [
                'master_on' => ForumFeed::platformOn(),
                'force_off' => in_array($tid, ForumFeed::forceOffList(), true),
            ],
            'endpoints' => self::ENDPOINTS,
            'slug'      => TenantContext::slug(),
            'keys'      => $keys,
            'key_count' => count($keys),
            'recent'    => $recent,
            'caps'      => ['members' => ForumFeed::CAP_MEMBERS, 'events' => ForumFeed::CAP_EVENTS,
                'window_days' => ForumFeed::WINDOW_DAYS],
        ]);
    }

    public function toggle()
    {
        if ($r = $this->superOnly()) {
            return $r;
        }
        $tid = (int)TenantContext::id();
        $on  = (string)$this->request->post('on', '0') === '1';
        ForumFeed::setTenantOn($tid, $on);
        admin_log('论坛对接', ($on ? '打开' : '关闭') . '本队的社区论坛对接接口');
        $g = ForumFeed::gate($tid);
        return api_ok(['on' => $on, 'serving' => $g['on'], 'reason' => (string)$g['reason']],
            $on ? ($g['on'] ? '已打开，论坛那边现在就能读' : '已打开，但现在被平台挡着') : '已关闭');
    }

    /** @return \think\Response|null */
    private function superOnly()
    {
        if (!is_super_admin()) {
            return api_err('仅站长本人可管理论坛对接', 403, 403);
        }
        return null;
    }
}
