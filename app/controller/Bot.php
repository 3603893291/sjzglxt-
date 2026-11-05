<?php
declare (strict_types = 1);

namespace app\controller;

use app\service\QqBot;
use app\service\TenantContext;
use think\facade\Cache;
use think\facade\Db;

/**
 * QQ 机器人·成员端（v5.8.0）
 * 个人中心：绑定状态查询 / 生成成员绑定验证码 / 解绑
 * 群内 @机器人 发「绑定 六位码」完成 QQ 身份 ↔ 站点账号关联
 */
class Bot extends AuthBase
{
    /** 我的绑定状态（个人中心展示用） */
    public function status()
    {
        $me  = current_user();
        $tid = TenantContext::id();
        $row = Db::name('bot_user_bindings')
            ->where('tenant_id', $tid)->where('user_id', $me->id)->find();
        return api_ok([
            'bound'       => (bool)$row,
            'bound_at'    => $row ? (string)$row['created_at'] : '',
            'group_bound' => (bool)Db::name('bot_group_bindings')->where('tenant_id', $tid)->find(),
            // v5.28.0：只看本队自己的独立机器人（平台公共机器人已下线）
            'bot_enabled' => QqBot::ownBot((int)$tid) !== null,
        ]);
    }

    /**
     * 生成成员绑定验证码（6 位数字，10 分钟有效；有效期内复用同一码）
     * 码 → 用户 的映射存缓存，群内发「绑定 码」时核销
     */
    public function bindCode()
    {
        $me  = current_user();
        $tid = TenantContext::id();

        if (QqBot::ownBot((int)$tid) === null) {
            return api_err('本战队的 QQ 机器人还没启用，请联系管理员在管理中心「QQ机器人」里配置');
        }
        if (!Db::name('bot_group_bindings')->where('tenant_id', $tid)->find()) {
            return api_err('本战队尚未绑定 QQ 群，请先联系管理员在管理中心完成群绑定');
        }

        $genKey = 'bot_ugen_' . $tid . '_' . $me->id;
        $code   = (string)Cache::get($genKey);
        // 复用前提：码 → 用户映射仍存活（旧码被核销/过期后不能复用，否则群内提示"码不正确"）
        if (preg_match('/^\d{6}$/', $code)
            && (int)Cache::get('bot_ucode_' . $tid . '_' . $code) !== (int)$me->id) {
            $code = '';
        }
        if (!preg_match('/^\d{6}$/', $code)) {
            // 生成不与本租户其他待用码冲突的 6 位码
            $code = '';
            for ($i = 0; $i < 5; $i++) {
                $try = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
                if (!Cache::get('bot_ucode_' . $tid . '_' . $try)) {
                    $code = $try;
                    break;
                }
            }
            if ($code === '') {
                return api_err('验证码生成繁忙，请稍后再试');
            }
            Cache::set($genKey, $code, 600);
            Cache::set('bot_ucode_' . $tid . '_' . $code, $me->id, 600);
        }

        $bound = (bool)Db::name('bot_user_bindings')
            ->where('tenant_id', $tid)->where('user_id', $me->id)->find();
        return api_ok([
            'code' => $code,
            'ttl'  => 600,
            'bound' => $bound,
        ], $bound ? '验证码已生成（重新绑定将更换到你的新 QQ 身份）' : '验证码已生成');
    }

    /** 解除我的成员绑定（换绑前置操作） */
    public function unbind()
    {
        $me  = current_user();
        $tid = TenantContext::id();
        Db::name('bot_user_bindings')
            ->where('tenant_id', $tid)->where('user_id', $me->id)->delete();
        return api_ok([], '已解绑');
    }
}
