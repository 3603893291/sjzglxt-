<?php
// 全局中间件定义文件
return [
    // v5.0.0：租户解析（按路径首段 /{slug}/ 分流，必须最先执行）
    \app\middleware\ResolveTenant::class,
    // Session初始化（登录态依赖，必须开启）
    \think\middleware\SessionInit::class,
    // 安全响应头（X-Content-Type-Options / X-Frame-Options / Referrer-Policy / X-XSS-Protection）
    \app\middleware\SecurityHeaders::class,
    // v5.7.1（M3）：CSRF 防护（Sec-Fetch-Site / Origin / Referer 校验，安全方法跳过）
    \app\middleware\CsrfGuard::class,
];
