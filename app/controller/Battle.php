<?php
declare(strict_types=1);

namespace app\controller;

use app\service\BattleService;
use app\service\TenantContext;
use think\facade\Db;

/**
 * 跨战队约战·站内用户入口（v5.4.0，session 鉴权）
 *
 * 普通成员视角：本队相关约战列表 / 详情（含双方名单与逐局对局）/ 本人报名退出。
 * 发起、应战、禁图等管理动作在 BattleAdmin（battle 权限）。
 */
class Battle extends AuthBase
{
    /** 涉及本队的约战列表（附 my_side） */
    public function index()
    {
        $tid = TenantContext::id();
        return api_ok(BattleService::withMySide(BattleService::listForParty('tenant', $tid), 'tenant', $tid));
    }

    /** 约战详情（仅限本队相关或平台超管） */
    public function detail($id)
    {
        $b = Db::name('battles')->where('id', (int)$id)->find();
        if (!$b) {
            return api_err('约战不存在', 404, 404);
        }
        if (BattleService::sideOfParty($b, 'tenant', TenantContext::id()) === null && !is_platform_super()) {
            return api_err('无权查看与己无关的约战', 403, 403);
        }
        return api_ok(BattleService::detail((int)$id, ['tenant', TenantContext::id()]));
    }

    /** 平台地图池（供禁图选择展示） */
    public function mapPool()
    {
        return api_ok(BattleService::mapPool());
    }

    /** 本人报名出战 / 退出（action=join|leave） */
    public function signup($id)
    {
        $user = current_user();
        if (!$user) {
            return api_err('未登录', 401, 401);
        }
        $action = (string)$this->request->post('action', 'join');
        if ($action === 'leave') {
            if ($e = BattleService::userCancelSignup((int)$id, $user)) return api_err($e);
            return api_ok([], '已退出出战名单');
        }
        if ($e = BattleService::userSignup((int)$id, $user)) return api_err($e);
        return api_ok([], '报名成功，等待开赛');
    }
}
