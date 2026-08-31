<?php
declare (strict_types = 1);

namespace app\service;

/**
 * IP 归属地查询（太平洋网络免费接口，国内 IP 省市区准确，无需密钥）
 * 失败时静默返回空串，不影响注册主流程
 */
class IpRegion
{
    public static function lookup(string $ip): string
    {
        if ($ip === '' || $ip === '127.0.0.1' || $ip === '::1'
            || preg_match('/^(10\.|192\.168\.|172\.(1[6-9]|2\d|3[01])\.)/', $ip)) {
            return '内网/本地';
        }

        $url = 'http://whois.pconline.com.cn/ipJson.jsp?ip=' . urlencode($ip) . '&json=true';
        $ctx = stream_context_create(['http' => ['timeout' => 3]]);
        $resp = @file_get_contents($url, false, $ctx);
        if ($resp === false || $resp === '') {
            return '';
        }

        // 接口返回 GBK 编码
        $resp = mb_convert_encoding($resp, 'UTF-8', 'GBK');
        $json = json_decode($resp, true);
        if (!is_array($json)) {
            return '';
        }

        // 省 + 市 + 区（去重，直辖市省市同名只留一个）
        $parts = [];
        foreach (['pro', 'city', 'region'] as $k) {
            $v = trim((string)($json[$k] ?? ''));
            if ($v !== '' && !in_array($v, $parts, true)) {
                $parts[] = $v;
            }
        }
        return implode('', $parts);
    }
}
