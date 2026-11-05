<?php
declare(strict_types=1);

namespace app\controller;

use app\service\ImageUpload;
use app\service\Setting;

/**
 * 登录页配图（v5.12.0，仅内置超管 / 租户站长）
 *
 * 战队站点登录/注册/找回密码页左侧那张大海报，默认是内置的一张静态图；
 * 站长可以换成自己战队的图，只影响本战队（值存在战队级 settings 的 login_poster）。
 * 平台站点读不到战队的行，天然还是内置默认，不需要额外分支。
 */
class PosterAdmin extends AdminBase
{
    /** 战队级配置键；值只允许是 login_poster_url() 认得的那个形态 */
    private const SETTING_KEY = 'login_poster';

    /** 读：当前生效的图 + 是否已自定义 + 内置默认图，供前端预览与「恢复默认」按钮判断可用性 */
    public function index()
    {
        if ($r = $this->guard()) {
            return $r;
        }
        return api_ok([
            'poster'  => login_poster_url(),
            'custom'  => login_poster_custom(),
            'default' => login_poster_default(),
        ]);
    }

    /**
     * 写：带 file 就换图；reset=1 恢复内置默认；两者都没有则原样返回。
     * 与公告配图一致——「没选文件」不等于「要清空图片」，避免顺手点一下就把配图清掉。
     */
    public function save()
    {
        if ($r = $this->guard()) {
            return $r;
        }
        $old = login_poster_custom();

        if ((string)$this->request->post('reset', '') === '1') {
            if ($old === '') {
                return api_ok(['poster' => login_poster_default()], '当前用的就是默认配图');
            }
            Setting::set(self::SETTING_KEY, '');
            ImageUpload::delete($old);   // 只删本战队上传目录里的旧海报，配额随之释放
            admin_log('站点外观', '恢复登录页默认配图');
            return api_ok(['poster' => login_poster_default(), 'custom' => ''], '已恢复默认配图');
        }

        // 必须用无参 file() 取数组：写成 file('file') 时，浏览器提交的空文件框会让
        // ThinkPHP 抛 "no file to uploaded" 异常（HTTP 500）
        $file = ($this->request->file())['file'] ?? null;
        if (!$file) {
            return api_ok(['poster' => login_poster_url(), 'custom' => $old], '未选择图片，配图保持不变');
        }
        [$path, $err] = ImageUpload::save($file, 'posters');
        if ($err !== null) {
            return api_err($err);
        }

        Setting::set(self::SETTING_KEY, (string)$path);
        if ($old !== '') {
            ImageUpload::delete($old);
        }
        admin_log('站点外观', '更新登录页配图 ' . $path);

        return api_ok(['poster' => (string)$path, 'custom' => (string)$path], '登录页配图已更新');
    }

    /** 只有真超管（内置 admin / 租户站长）能动；被授权拿满权限的普通管理员也不行 */
    private function guard()
    {
        if (!is_super_admin()) {
            return api_err('只有站长或超级管理员可以设置登录页配图', 403, 403);
        }
        return null;
    }
}
