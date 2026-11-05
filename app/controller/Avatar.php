<?php

namespace app\controller;

use think\facade\Db;
use think\Response;

/**
 * 头像代理（v3.4）
 *
 * 前端只传 user_id，后端用完整 QQ 号向 qlogo 拉取头像并本地缓存输出，
 * 普通用户看到的 QQ 号保持打码（****后4位），QQ 号全程不出现在前端，
 * 隐私与头像展示两不误。
 *
 * v5.7.1(M5)：改挂 AuthBase（控制器级 AuthCheck），不再仅依赖路由组中间件。
 *
 * v5.22.0：查人改用 Db::name()（不带 tenant_id 全局作用域）——
 *   跨队私聊与个人主页是公共区，A 队成员要能看到 B 队对方的头像；
 *   原来走 User 模型会在他队的上下文里查不到人，只能回落到首字色块。
 */
class Avatar extends AuthBase
{
    // 磁盘缓存有效期：24 小时
    private const CACHE_TTL = 86400;

    // 文字头像调色板（按 uid 取色，避免花哨渐变）
    private const PALETTE = [
        '#0f766e', '#475569', '#7c3aed', '#b45309',
        '#1d4ed8', '#166534', '#9f1239', '#0369a1',
    ];

    public function show($uid)
    {
        $uid  = (int)$uid;
        $user = $uid > 0 ? Db::name('users')->where('id', $uid)->find() : null;

        // 不在场的账号（待审核/已封/已注销）不向 qlogo 取图，直接走回落
        $alive = $user && (string)($user['status'] ?? '') === 'approved';
        $qq    = ($alive && preg_match('/^\d{5,11}$/', (string)($user['username'] ?? '')))
            ? (string)$user['username']
            : '';

        if ($qq !== '') {
            $dir = runtime_path() . 'avatar';
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
            $file = $dir . DIRECTORY_SEPARATOR . $uid . '.img';
            $mimeFile = $dir . DIRECTORY_SEPARATOR . $uid . '.mime';

            // 命中磁盘缓存直接输出（v3.7：连同真实 MIME 一起缓存，动图 GIF/WebP 不再被当 jpeg 输出）
            if (is_file($file) && filesize($file) > 0
                && (time() - (int)filemtime($file)) < self::CACHE_TTL) {
                $mime = is_file($mimeFile) ? (string)file_get_contents($mimeFile) : 'image/jpeg';
                return $this->output((string)file_get_contents($file), $mime);
            }

            $img = $this->fetchQqAvatar($qq);
            if ($img !== null) {
                $info = @getimagesizefromstring($img);
                $mime = ($info && !empty($info['mime'])) ? $info['mime'] : 'image/jpeg';
                @file_put_contents($file, $img);
                @file_put_contents($mimeFile, $mime);
                return $this->output($img, $mime);
            }
        }

        // 回退：按 uid 生成首字母 SVG 头像（管理员 / 拉取失败 / 用户不存在）
        // 没昵称的人不能拿账号当昵称取首字：那是 QQ 的第一位数字，色块上就等于挂着半个 QQ 号
        $nick = ($user && trim((string)($user['nickname'] ?? '')) !== '') ? (string)$user['nickname'] : '?';
        return $this->output($this->letterSvg($nick, $uid), 'image/svg+xml');
    }

    // 拉取 QQ 头像（v3.7：多镜像源 + curl 跟随 302 跳转，失败返回 null 由调用方回退）
    // qlogo 会对部分账号（尤其设置了动态头像/GIF 头像的账号）返回 302 或更换镜像域名，
    // 旧版 file_get_contents 单源拉取在这类账号上容易失败导致头像不显示
    private function fetchQqAvatar(string $qq): ?string
    {
        $hosts = ['q1.qlogo.cn', 'q2.qlogo.cn', 'thirdqq.qlogo.cn'];
        foreach ($hosts as $host) {
            $url = 'https://' . $host . '/g?b=qq&nk=' . $qq . '&s=100';
            $data = $this->httpGet($url);
            if ($data !== null && strlen($data) >= 100 && @getimagesizefromstring($data)) {
                return $data;
            }
        }
        return null;
    }

    // 优先 curl（支持跟随跳转），回退 stream 包装器
    private function httpGet(string $url): ?string
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 5,
                CURLOPT_TIMEOUT        => 4,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_HTTPHEADER     => [
                    'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
                    'Referer: https://www.qq.com/',
                ],
            ]);
            $data = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            return ($data !== false && $code === 200) ? (string)$data : null;
        }

        $ctx = stream_context_create([
            'http' => [
                'method'        => 'GET',
                'timeout'       => 4,
                'follow_location' => 1,
                'max_redirects' => 5,
                'header'        => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64)\r\n"
                    . "Referer: https://www.qq.com/\r\n",
            ],
            'ssl' => [
                'verify_peer'      => false,
                'verify_peer_name' => false,
            ],
        ]);
        $data = @file_get_contents($url, false, $ctx);
        return $data === false ? null : (string)$data;
    }

    // 首字母文字头像 SVG
    private function letterSvg(string $nick, int $uid): string
    {
        $letter = mb_substr(trim($nick), 0, 1);
        if ($letter === '' || !preg_match('/[一-龥a-zA-Z0-9]/u', $letter)) {
            $letter = '?';
        }
        $bg   = self::PALETTE[$uid % count(self::PALETTE)];
        $char = htmlspecialchars($letter, ENT_QUOTES);
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<svg xmlns="http://www.w3.org/2000/svg" width="100" height="100">'
            . '<rect width="100" height="100" fill="' . $bg . '"/>'
            . '<text x="50" y="50" dy=".36em" text-anchor="middle" font-size="44" '
            . 'font-family="PingFang SC, Microsoft YaHei, sans-serif" fill="#ffffff">'
            . $char . '</text></svg>';
    }

    private function output(string $body, string $type): Response
    {
        return Response::create($body)
            ->header([
                'Content-Type'           => $type,
                'Cache-Control'          => 'public, max-age=86400',
                'X-Content-Type-Options' => 'nosniff',
            ]);
    }
}
