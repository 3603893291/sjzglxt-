<?php
// +----------------------------------------------------------------------
// | Cookie设置
// +----------------------------------------------------------------------
return [
    // cookie 保存时间
    'expire'    => 0,
    // cookie 保存路径
    'path'      => '/',
    // cookie 有效域名
    'domain'    => '',
    //  cookie 启用安全传输（v5.7.1 M4：由 .env 的 COOKIE_SECURE 控制；
    //  生产环境全站 HTTPS 时设 COOKIE_SECURE = true，本地 HTTP 开发省略即 false）
    'secure'    => filter_var(env('COOKIE_SECURE', 'false'), FILTER_VALIDATE_BOOLEAN),
    // httponly设置
    'httponly'  => true,
    // 是否使用 setcookie
    'setcookie' => true,
    // samesite 设置，支持 'strict' 'lax'
    'samesite'  => 'lax',
];
