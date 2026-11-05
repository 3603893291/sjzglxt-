<?php
declare (strict_types = 1);

namespace app\controller;

use app\service\Setting;

/**
 * 战队侧的私聊开关（v5.19.0 上线，v5.30.1 只留这一个动作）
 *
 * 原来这一层还有「会话元数据 / 举报列表 / 顺着举报展开正文 / 忽略举报」四件事，
 * 权限键 chat_report。v5.30.1 按需求方拍板把私聊治理整体搬到平台侧
 * （app/controller/PlatformChat.php）：战队队长既读不到正文，也不再处理举报，
 * 平台给的禁言同样解不掉。那四条接口已从路由下架，本类里也不再留方法。
 *
 * 留在这里的只有一件事：本站要不要开放私聊。这是队长对自己站点的功能开关，
 * 不是治理动作，所以仍然归战队侧（成员自己的两个开关在个人中心，见 ChatDm::mySetting）。
 */
class ChatAdmin extends AdminBase
{
    protected function noPerm()
    {
        return api_err('您没有「私聊开关」权限，请联系超级管理员分配', 403, 403);
    }

    /** 全队私聊开关（关了对谁都发不出去，也进不了会话列表） */
    public function dmToggle()
    {
        if (!admin_can('chat_report')) {
            return $this->noPerm();
        }
        $enabled = (int)$this->request->post('enabled', 1) === 1 ? 1 : 0;
        Setting::set('dm_enabled', (string)$enabled);
        admin_log('私聊开关', ($enabled ? '开放' : '关闭') . '本站私聊功能');
        return api_ok(['dm_enabled' => $enabled], $enabled ? '已开放私聊' : '已关闭本站私聊');
    }
}
