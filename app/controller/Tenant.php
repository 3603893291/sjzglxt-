<?php
declare (strict_types = 1);

namespace app\controller;

use app\BaseController;
use app\service\TenantContext;

/**
 * 站点信息接口（v5.0.0：平台/租户前端各自拉取自身品牌信息，公开无需登录）
 */
class Tenant extends BaseController
{
    // 当前站点信息（平台：name=战队入驻平台；租户：名称/logo/目录前缀）
    public function info()
    {
        return api_ok(TenantContext::info());
    }
}
