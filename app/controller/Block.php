<?php
declare (strict_types = 1);

namespace app\controller;

use app\service\Block as BlockService;
use app\service\TenantContext;

/**
 * 个人黑名单（v5.30.1）：成员自己的名单，不需要权限键，也只有本人读得到。
 *
 *   GET  /api/blocks            我的名单（显示名 + 队名 + 在不在线）+ 上限
 *   POST /api/blocks/add        拉黑（只能拉其它战队的人，最多 10 个）
 *   POST /api/blocks/remove     放出
 *
 * 与「本队黑名单」（BlacklistAdmin，注册审核预警那份）是两回事：
 * 那份是战队公用的登记簿，写的是 QQ 号，队长和管理员都看得到；
 * 这份是私人视角，只有本人知道拉了谁，对被拉黑的人一个字都不说。
 */
class Block extends AuthBase
{
    /** 平台控制台没有"我"这个成员账号，这三条接口在平台上下文里就是不存在 */
    protected function guard()
    {
        return TenantContext::isPlatform() ? api_err('接口不存在', 404, 404) : null;
    }

    /** GET /api/blocks */
    public function index()
    {
        if ($r = $this->guard()) {
            return $r;
        }
        $me = current_user();
        return api_ok([
            'list' => BlockService::listOf((int)$me->id),
            'max'  => BlockService::MAX,
        ]);
    }

    /** POST /api/blocks/add {user_id} */
    public function add()
    {
        if ($r = $this->guard()) {
            return $r;
        }
        $me = current_user();
        [$err, $msg] = BlockService::add(
            (int)$me->id,
            (int)TenantContext::id(),
            (int)$this->request->post('user_id', 0)
        );
        if ($err !== null) {
            return api_err($err);
        }
        return api_ok(['list' => BlockService::listOf((int)$me->id), 'max' => BlockService::MAX], $msg);
    }

    /** POST /api/blocks/remove {user_id} */
    public function remove()
    {
        if ($r = $this->guard()) {
            return $r;
        }
        $me = current_user();
        [$err, $msg] = BlockService::remove(
            (int)$me->id,
            (int)$this->request->post('user_id', 0)
        );
        if ($err !== null) {
            return api_err($err);
        }
        return api_ok(['list' => BlockService::listOf((int)$me->id), 'max' => BlockService::MAX], $msg);
    }
}
