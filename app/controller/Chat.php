<?php
declare (strict_types = 1);

namespace app\controller;

use app\model\ChatMessage;
use app\model\User;
use think\facade\Cache;

/**
 * 聊天室：轮询拉取 + 发送（含禁言/全员禁言/撤回过滤）
 */
class Chat extends AuthBase
{
    // 拉取消息（自动过滤被撤回的消息）
    // after_id = 0: 取最新 50 条；after_id > 0: 取比它新的消息（增量轮询）
    public function messages()
    {
        $afterId = (int)$this->request->get('after_id', 0);

        $query = ChatMessage::alias('m')
            ->leftJoin('users u', 'u.id = m.user_id')
            ->where('m.recalled', 0)
            ->field('m.id, m.user_id, m.content, m.created_at, COALESCE(u.nickname, "已注销用户") AS nickname, u.username, u.role AS user_role, u.role_tags');

        if ($afterId > 0) {
            $list = $query->where('m.id', '>', $afterId)
                ->order('m.id', 'asc')
                ->limit(100)
                ->select();
        } else {
            $list = $query->order('m.id', 'desc')
                ->limit(50)
                ->select();
            $list = array_reverse($list->toArray());
        }

        $me = current_user();
        $admin = is_admin();

        // 安全：统一输出 qq 字段并删除原始 username——
        // 有 qq_full 权限看完整 QQ，管理员无权限打码（前3后2），普通用户仅看后 4 位
        // 必须先 toArray()——Collection 上按引用循环改不到原对象
        $canFullQq = admin_can('qq_full');
        $list = $list instanceof \think\Collection ? $list->toArray() : $list;
        foreach ($list as &$item) {
            $qq = $item['username'] ?? '';
            $item['qq'] = $canFullQq ? $qq : ($admin
                ? mask_qq((string)$qq)
                : ((strlen($qq) >= 4) ? '****' . substr($qq, -4) : '****'));
            unset($item['username']);
            // v5.3.0：昵称旁展示该用户默认的职责标签（无则 null）
            $item['role_tag'] = display_role_tag($item);
            unset($item['role_tags']);
        }
        unset($item);

        return api_ok([
            'list'        => $list,
            'me_id'       => $me->id,
            'is_admin'    => $admin,
            'global_mute' => setting('global_mute', '0') === '1',
        ]);
    }

    // 发送消息（校验：个人禁言 / 全员禁言 / XSS 过滤）
    public function send()
    {
        $user = current_user();

        // 聊天频率限制：同一用户 2 秒内最多 1 条消息
        $chatKey = 'chat_rate_' . $user->id;
        if (Cache::has($chatKey)) {
            return api_err('发言过于频繁，请稍后再试', 429, 429);
        }

        if ((int)$user->muted === 1) {
            return api_err('你已被管理员禁言，暂时不能发言', 403, 403);
        }
        if (setting('global_mute', '0') === '1' && $user->role !== 'admin') {
            return api_err('当前为全员禁言状态，仅管理员可发言', 403, 403);
        }

        $content = trim((string)$this->request->post('content', ''));
        if ($content === '') {
            return api_err('消息不能为空');
        }
        if (mb_strlen($content) > 500) {
            return api_err('消息最长 500 字');
        }

        $msg = ChatMessage::create([
            'user_id' => $user->id,
            'content' => $content,
        ]);

        Cache::set($chatKey, 1, 2);

        return api_ok([
            'id'         => $msg->id,
            'user_id'    => $user->id,
            'nickname'   => $user->nickname,
            'content'    => $msg->content,
            'created_at' => $msg->created_at,
            'role_tag'   => display_role_tag($user),   // v5.3.0：新消息即时渲染标签，不等下轮轮询
        ], '发送成功');
    }

    // 管理员：撤回消息
    public function recall($id)
    {
        // v5.15.0(S-L3)：撤回他人消息与「禁言」同属聊天治理动作，不该只凭 role=admin 就能做
        // ——此前零权限的挂名管理员可以撤回任意成员的消息
        if (!admin_can('mute') && !admin_can('global_mute')) {
            return api_err('无权限：撤回消息需要禁言或全局禁言权限', 403, 403);
        }
        $msg = ChatMessage::find((int)$id);
        if (!$msg) {
            return api_err('消息不存在', 404);
        }
        $msg->save(['recalled' => 1, 'recalled_by' => current_user()->id]);
        return api_ok([], '消息已撤回');
    }

    // 管理员：切换全员禁言（v3.9：需 global_mute 权限，超管恒有）
    public function toggleGlobalMute()
    {
        if (!admin_can('global_mute')) {
            return api_err('您没有全员禁言权限，请联系超级管理员分配', 403, 403);
        }
        $cur = setting('global_mute', '0') === '1';
        \app\service\Setting::set('global_mute', $cur ? '0' : '1');
        return api_ok(['global_mute' => !$cur], $cur ? '已解除全员禁言' : '已开启全员禁言');
    }

    /**
     * 在线人数（v5.19.0：聊天室拆成「战队群 + 私聊」，两个页签头部共用这一份数据）
     * 口径与仪表盘的在线名单完全一致：同一张表、同一个 Dashboard::ONLINE_WINDOW、
     * 同样只出 id / 显示名 / 是否管理员——QQ、邮箱、IP 一律不进响应（这份名单全员可见）。
     * 隐身巡查不写 last_active_at（见 AuthCheck），巡查中的平台管理员不会出现在这里。
     */
    public function online()
    {
        $cut = date('Y-m-d H:i:s', time() - Dashboard::ONLINE_WINDOW);
        $count = (int)User::where('status', 'approved')->where('last_active_at', '>', $cut)->count();
        $rows = User::where('status', 'approved')->where('last_active_at', '>', $cut)
            ->order('last_active_at', 'desc')->limit(80)->select();
        $list = [];
        foreach ($rows as $u) {
            $list[] = ['id' => (int)$u->id, 'name' => user_display_name($u),
                'is_admin' => (string)$u->role === 'admin'];
        }
        return api_ok([
            'count'     => $count,
            'total'     => (int)User::where('status', 'approved')->count(),
            'window'    => Dashboard::ONLINE_WINDOW,
            'list'      => $list,
            'truncated' => $count > count($list),
        ]);
    }
}
