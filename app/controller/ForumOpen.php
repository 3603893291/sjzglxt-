<?php
declare (strict_types = 1);

namespace app\controller;

use app\BaseController;
use app\service\ForumFeed;
use app\service\TenantContext;

/**
 * 社区论坛对接的对外只读接口（v5.35.0）
 *
 *   GET /api/open/forum/team      战队名片 + 招募文案
 *   GET /api/open/forum/members   成员名单 + 公开战绩（只给本人同意对外展示的人）
 *   GET /api/open/forum/activity  近 30 天活动 + 签到榜
 *   GET /api/open/forum/notices   队长在对外展示页勾选过的那几条公告
 *
 * 四条都是 GET：这一版不接论坛写回来的任何东西，所以没有 POST/PUT/DELETE。
 * 鉴权与限频在中间件（X-Api-Key，scope=tenant），这里只把三道闸（战队开关 / 平台总闸 / 平台按站点停用）
 * 过一遍 —— 少任何一道都不给数据，并回一句人话说明是哪一道挡的，别让论坛那边对着 403 猜。
 */
class ForumOpen extends BaseController
{
    /** @return \think\Response|null 挡住就返回这个响应，放行返回 null */
    private function gate(): ?\think\Response
    {
        $tid = (int)TenantContext::id();
        if ($tid <= (int)TenantContext::PLATFORM_ID) {
            return api_err('这条接口要用本队的密钥调用（scope=tenant 的 Key）', 403, 403);
        }
        $g = ForumFeed::gate($tid);
        if (!$g['on']) {
            return api_err($g['reason'], 403, 403);
        }
        return null;
    }

    public function team()
    {
        if ($r = $this->gate()) {
            return $r;
        }
        return api_ok(ForumFeed::team((int)TenantContext::id(), $this->siteBase()));
    }

    public function members()
    {
        if ($r = $this->gate()) {
            return $r;
        }
        return api_ok(ForumFeed::members((int)TenantContext::id()));
    }

    public function activity()
    {
        if ($r = $this->gate()) {
            return $r;
        }
        return api_ok(ForumFeed::activity((int)TenantContext::id()));
    }

    public function notices()
    {
        if ($r = $this->gate()) {
            return $r;
        }
        return api_ok(ForumFeed::notices((int)TenantContext::id()));
    }

    /** 站点根地址：论坛要把人链回战队主页，不能只给一个 /slug/ 让它们自己猜域名 */
    private function siteBase(): string
    {
        $host = (string)$this->request->host();
        if ($host === '') {
            return '';
        }
        return $this->request->scheme() . '://' . $host;
    }
}
