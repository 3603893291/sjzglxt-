<?php
declare (strict_types = 1);

namespace app\controller;

use app\service\ImageUpload;
use think\facade\Cache;

/**
 * 个人中心：修改昵称、修改密码、更换注册游戏截图
 */
class Profile extends AuthBase
{
    // 修改昵称
    public function updateNickname()
    {
        $user     = current_user();
        $nickname = trim((string)$this->request->post('nickname', ''));

        if ($nickname === '' || mb_strlen($nickname) > 32) {
            return api_err('昵称不能为空且最长 32 个字符');
        }
        if ($nickname === $user->nickname) {
            return api_err('新昵称与当前昵称相同');
        }

        $user->save(['nickname' => $nickname]);
        return api_ok(['nickname' => $nickname], '昵称修改成功');
    }

    // 修改密码（需验证旧密码）
    public function updatePassword()
    {
        $user    = current_user();
        $old     = (string)$this->request->post('old_password', '');
        $new     = (string)$this->request->post('new_password', '');
        $confirm = (string)$this->request->post('confirm', '');

        if (!password_verify($old, $user->password)) {
            return api_err('原密码错误');
        }
        if (mb_strlen($new) < 8 || mb_strlen($new) > 64) {
            return api_err('新密码长度需为 8-64 位');
        }
        if (!preg_match('/[A-Za-z]/', $new) || !preg_match('/\d/', $new)) {
            return api_err('密码需至少包含一个字母和一个数字');
        }
        if ($new !== $confirm) {
            return api_err('两次输入的新密码不一致');
        }
        if ($new === $old) {
            return api_err('新密码不能与原密码相同');
        }
        // v5.7.1(H5)：弱口令黑名单
        if ($weak = is_weak_password($new)) {
            return api_err($weak);
        }

        // v5.7.1(H3)：密码纪元 +1——该账号其它设备的会话与免登录令牌全部失效；
        // 当前会话同步盖上新纪元，改密者本人不掉线
        $newEpoch = (int)$user->session_epoch + 1;
        $user->save([
            'password'      => password_hash($new, PASSWORD_DEFAULT),
            'session_epoch' => $newEpoch,
        ]);
        session('session_epoch', $newEpoch);
        // v5.3.0：改密码后作废全部设备的 7 天免登录令牌（安全起见）
        remember_clear_user((int)$user->id);
        return api_ok([], '密码修改成功');
    }

    // 更换注册游戏截图（v3.9.2）：提交后账号回到 pending，重新走注册审核
    public function updateScreenshot()
    {
        $user = current_user();
        // 内置超管账号没有注册截图，且属于受保护账号，不允许此操作
        if (is_super_admin($user)) {
            return api_err('系统管理员账号无需更换截图');
        }

        // 频率限制：同一账号 30 秒内只能提交一次，防止刷存储
        $rateKey = 'shot_change_rate_' . $user->id;
        if ((int)Cache::get($rateKey, 0) >= 1) {
            return api_err('操作太频繁，请 30 秒后再试', 429, 429);
        }

        // 上传新截图（与注册同一套校验：白名单 + 真实内容检测 + 5MB 限制）
        // 用无参 file() 取数组：未选文件时 file('file') 会抛异常导致 500，这里给友好提示
        $upFiles = $this->request->file();
        [$path, $err] = ImageUpload::save($upFiles['file'] ?? null, 'profiles');
        if ($err !== null) {
            return api_err($err);
        }

        $oldImage = (string)$user->profile_image;

        // 新图落库成功才删旧图，避免中途失败留下空引用
        $user->save([
            'profile_image' => $path,
            'status'        => 'pending',   // 回到待审核，审核通过前无法登录（AuthCheck 统一拦截）
        ]);
        if ($oldImage !== '') {
            ImageUpload::delete($oldImage);
        }

        Cache::set($rateKey, 1, 30);

        // 立即结束当前会话，与 pending 状态保持一致（免登录令牌一并作废）
        remember_clear_user((int)$user->id);
        session(null);
        return api_ok([], '截图已更换，账号已重新进入审核，审核通过后才能再次登录');
    }

    // 职责标签自选（v5.3.0）：普通用户单选，已被管理员分配多个标签的用户保留多选并指定默认；
    // 管理员开关关闭时用户侧只读。请求体：tag_ids[]（按期望顺序，第一个为默认）
    public function updateRoleTags()
    {
        $user = current_user();
        if (!role_tag_user_editable() && $user->role !== 'admin') {
            return api_err('管理员已关闭用户自行修改职责标签，请联系管理员分配', 403, 403);
        }

        $ids = $this->request->post('tag_ids/a', []);
        $map = role_tags_map();
        $clean = [];
        foreach ((array)$ids as $id) {
            $id = (int)$id;
            if ($id > 0 && isset($map[$id]) && !in_array($id, $clean, true)) {
                $clean[] = $id;
            }
        }

        $held = user_role_tag_ids($user);
        if ($user->role !== 'admin' && count($held) <= 1) {
            // 普通用户且名下只有 0/1 个标签：严格单选
            $clean = array_slice($clean, 0, 1);
        } elseif ($user->role !== 'admin' && count($clean) > 1) {
            // 已有多标签的用户：只能在自己已持有的标签里重排默认/保留，
            // 不能自助新增第二个（新增仍由管理员分配）
            $clean = array_values(array_filter($clean, function ($id) use ($held) {
                return in_array($id, $held, true);
            }));
        }

        $user->save(['role_tags' => count($clean) > 0 ? json_encode($clean) : '']);
        return api_ok([
            'role_tag_ids' => $clean,
            'role_tag'     => display_role_tag($user),
        ], count($clean) > 0 ? '职责标签已更新' : '已清除职责标签');
    }
}
