<?php
declare(strict_types=1);

namespace app\controller;

use app\BaseController;

/**
 * 需登录的控制器基类
 * 控制器级挂载 AuthCheck 中间件，作为纵深防御
 */
class AuthBase extends BaseController
{
    protected $middleware = [\app\middleware\AuthCheck::class];
}
