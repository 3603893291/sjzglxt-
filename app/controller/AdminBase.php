<?php
declare(strict_types=1);

namespace app\controller;

use app\BaseController;

/**
 * 管理中心控制器基类
 * 控制器级挂载 AdminCheck 中间件，作为纵深防御：
 * 即使路由中间件因某种原因被绕过，控制器本身的中间件仍会生效
 */
class AdminBase extends BaseController
{
    protected $middleware = [\app\middleware\AdminCheck::class];
}
