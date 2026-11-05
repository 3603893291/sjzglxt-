<?php
declare (strict_types = 1);

namespace app\service;

/**
 * 零依赖 SMTP 发信客户端（支持 465 SSL 与 587 STARTTLS）
 * 配置来自 settings 表：smtp_host / smtp_port / smtp_user / smtp_pass / smtp_from
 *
 * v5.31.0 起这套客户端两种用法：
 *   · 战队自己发信 —— 读本站那五个 smtp_*（Setting::get，作用域=当前租户）
 *   · 平台邮件群发 —— 读平台自己的 p_mail_*（platformConfig()，作用域=租户 0），
 *     整组作为 $override 传进 send()，战队那套 smtp_* 在这里一律不生效；
 *     反过来，平台这套凭据也只有平台超管的接口读得到，战队侧碰不到。
 * 正文格式按 looksLikeHtml() 保守判定：判成 HTML 才用 text/html，否则一律 text/plain。
 * 加密方式：没显式给 smtp_secure 时沿用老规矩（465=SSL，其它端口=STARTTLS）；
 * 平台那一套可以多选一种 none（本机中继/自测用），战队那套读不到这个键，行为一字不变。
 */
class Mailer
{
    /**
     * 清洗密码/授权码：去掉复制粘贴常见的空格、换行、全角空格、零宽字符
     * 邮箱授权码本身不会包含空白字符，粘贴时带入的空白是发信失败的头号原因
     */
    public static function normalizeSecret(string $raw): string
    {
        // 零宽字符 / BOM / 全角空格 / 不间断空格
        $raw = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}\x{3000}\x{00A0}]/u', '', $raw) ?? $raw;
        // 常规空白（空格、制表、换行）
        $raw = preg_replace('/\s+/', '', $raw) ?? $raw;
        return trim($raw);
    }

    /** 是否已配置 SMTP */
    public static function configured(): bool
    {
        return Setting::get('smtp_host') !== ''
            && Setting::get('smtp_user') !== ''
            && Setting::get('smtp_pass') !== '';
    }

    /**
     * 平台自己那一套发信账号：settings 里 p_mail_* 那几个键（租户号 0）。
     * 返回的就是 send() 的 $override，键名沿用 smtp_* —— Mailer 内部只认这一套键，
     * 换作用域这件事只发生在「从哪儿读」，不发生在「怎么发」，两条链路共用一份发送实现。
     *
     * @return array{smtp_host:string,smtp_port:string,smtp_user:string,smtp_pass:string,smtp_from:string}
     */
    public static function platformConfig(): array
    {
        return [
            'smtp_host' => trim(Setting::getPlatform('p_mail_host')),
            'smtp_port' => trim(Setting::getPlatform('p_mail_port', '465')),
            'smtp_user' => trim(Setting::getPlatform('p_mail_user')),
            // 已保存的授权码可能当初就是带着空格粘进去的，读出来再清洗一次做双保险
            'smtp_pass' => self::normalizeSecret(Setting::getPlatform('p_mail_pass')),
            'smtp_from' => trim(Setting::getPlatform('p_mail_from')),
            // 这一项不是 settings 里那五个 smtp_* 之一，只有平台这一路会读到它
            'smtp_secure' => strtolower(trim(Setting::getPlatform('p_mail_secure'))),
        ];
    }

    /** 平台这套配齐了没有：服务器 / 账号 / 授权码三项缺一个都不能发 */
    public static function platformConfigured(): bool
    {
        $c = self::platformConfig();
        return $c['smtp_host'] !== '' && $c['smtp_user'] !== '' && $c['smtp_pass'] !== '';
    }

    /**
     * 这封正文要不要按 HTML 发 —— 保守判定。
     *
     * 需求方选的是「混着来，自动判」，而两个判错方向里只有一个是真会毁掉这封信的：
     *   纯文本判成 HTML ⇒ 换行全塌成一行、半截标签把整篇变成一坨，收件人看到的是乱码；
     *   HTML 判成纯文本 ⇒ 只是在邮件里看到几个尖括号，字一个不丢。
     * 所以这里只认「整篇看起来就是一封 HTML 邮件」：要么有 html/body/doctype，
     * 要么块级标签成对出现且数量够多（≥4 个开、≥3 个闭）。
     * 只写了 <b>加粗</b> 这种一句话带过的小标签、或者标签写到一半没闭合的，一律按纯文本发。
     */
    public static function looksLikeHtml(string $body): bool
    {
        if ($body === '') {
            return false;
        }
        if (preg_match('/<\s*(html|body)\b/i', $body) || stripos($body, '<!doctype') !== false) {
            return true;
        }
        $opens  = preg_match_all('/<\s*(p|div|br|li|ul|ol|table|tr|td|th|h[1-6])\b[^>]*\/?\s*>/i', $body, $m1);
        $closes = preg_match_all('/<\s*\/\s*(p|div|li|ul|ol|table|tr|td|th|h[1-6])\s*>/i', $body, $m2);
        return $opens >= 4 && $closes >= 3;
    }

    /**
     * 邮箱地址格式校验（用于信封地址，必须严格，否则服务器回 500 bad syntax）
     */
    public static function isEmail(string $s): bool
    {
        return (bool)preg_match('/^[^\s<>@,;:"\']+@[^\s<>@,;:"\']+\.[^\s<>@,;:"\']+$/', $s);
    }

    /**
     * 拆分"发件人"配置为 [信封地址, 显示名, 回复地址]
     *
     * 关键约束：信封地址（MAIL FROM）必须与 SMTP 登录账号一致。
     *  - 填中文  -> 服务器回 500 bad syntax
     *  - 填别的邮箱 -> 服务器回 550 Invalid User / 553（拒绝代发）
     * 所以信封地址一律锁死为登录账号；smtp_from 只用于显示名，
     * 若里面填的是另一个邮箱地址，则降级为 Reply-To（回复时投递到该地址），不影响发信。
     *
     * @return array{0:string,1:string,2:string}
     */
    public static function resolveFrom(string $fromRaw, string $user): array
    {
        $fromRaw = trim(str_replace(["\r", "\n", "\t"], ' ', $fromRaw));
        if ($fromRaw === '') {
            return [$user, '', ''];
        }

        $name    = '';
        $replyTo = '';

        // 支持 "显示名 <mail@qq.com>" 这种从邮件客户端粘贴来的格式
        if (preg_match('/^(.*?)<\s*([^<>\s]+)\s*>\s*$/u', $fromRaw, $m)) {
            $name = trim($m[1], " \t\"'");
            if (self::isEmail($m[2]) && strcasecmp($m[2], $user) !== 0) {
                $replyTo = $m[2];
            }
        } elseif (self::isEmail($fromRaw)) {
            // 整栏就是一个邮箱地址：与登录账号不同则只作回复地址
            if (strcasecmp($fromRaw, $user) !== 0) {
                $replyTo = $fromRaw;
            }
        } else {
            // 纯名称（含中文）：只作显示名
            $name = $fromRaw;
        }

        // 信封地址恒等于登录账号，杜绝 550 / 553 / bad syntax
        return [$user, $name, $replyTo];
    }

    /**
     * 把 SMTP 原始错误翻译成人话 + 给出下一步建议
     */
    public static function friendlyError(string $err): string
    {
        $tips = '';
        if (strpos($err, '密码/授权码') !== false || strpos($err, '535') !== false) {
            $tips = '（请确认：①填的是"授权码"不是邮箱登录密码；'
                . '②QQ/163 邮箱需先在设置里开启 SMTP 服务并重新获取授权码；'
                . '③授权码不要带空格，系统已自动去空格；④邮箱账号需填完整地址）';
        } elseif (strpos($err, '用户名') !== false) {
            $tips = '（邮箱账号需填写完整地址，如 123456@qq.com）';
        } elseif (strpos($err, '连接 SMTP 服务器失败') !== false) {
            $tips = '（服务器可能封禁了 465/587 出站端口，或服务器地址填错；'
                . '宝塔用户请到"安全"里放行对应端口，也可联系服务器商解封 25/465 端口）';
        } elseif (strpos($err, 'STARTTLS') !== false) {
            $tips = '（该端口不支持 STARTTLS，建议改用 465 端口）';
        } elseif (stripos($err, 'bad syntax') !== false) {
            $tips = '（这是邮箱服务器说"地址格式不对"：'
                . '请检查"发件人显示名"一栏，它只用来显示名称，'
                . '若你在里面填了邮箱地址，必须是完整地址且与登录账号一致；'
                . '同时确认"邮箱账号"填的是 123456@qq.com 这样的完整地址）';
        } elseif (strpos($err, 'MAIL FROM') !== false) {
            if (strpos($err, '550') !== false) {
                $tips = '（服务器不认这个发件地址。请检查"邮箱账号"一栏：'
                    . '必须是你真实在用、且已开启 SMTP 服务的完整邮箱地址，'
                    . 'QQ 号写错一位也会报这个错。'
                    . '另外"发件人显示名"里不要填邮箱地址，只填名称）';
            } else {
                $tips = '（发件邮箱必须与登录的邮箱账号一致，不能用别的地址代发）';
            }
        } elseif (strpos($err, '553') !== false) {
            $tips = '（发件邮箱必须与登录的邮箱账号一致，不能用别的地址代发）';
        } elseif (strpos($err, 'RCPT TO') !== false) {
            $tips = strpos($err, '550') !== false
                ? '（收件邮箱不存在，请检查测试收件地址有没有写错）'
                : '（收件邮箱被服务器拒收，换一个邮箱试试）';
        }
        return $err . $tips;
    }

    /**
     * 剔除非法 UTF-8 字节，保证能安全放进 JSON。
     * 注意：不做整体转码，否则会把消息里本就正确的中文二次编码成乱码
     */
    public static function safeText(string $s): string
    {
        if ($s === '') {
            return '';
        }
        if (!function_exists('mb_check_encoding') || mb_check_encoding($s, 'UTF-8')) {
            return $s;
        }
        $clean = @iconv('UTF-8', 'UTF-8//IGNORE', $s);
        if (is_string($clean) && $clean !== '') {
            return $clean;
        }
        return preg_replace('/[^\x20-\x7E]/', '', $s) ?? '';
    }

    /**
     * 处理"来自操作系统/网络层"的错误串：Windows 下常为 GBK，需要整体转码
     */
    public static function sysText(string $s): string
    {
        if ($s === '' || !function_exists('mb_check_encoding')) {
            return $s;
        }
        if (mb_check_encoding($s, 'UTF-8')) {
            return $s;
        }
        $conv = @mb_convert_encoding($s, 'UTF-8', 'GBK,GB2312,BIG5');
        if (is_string($conv) && $conv !== '' && mb_check_encoding($conv, 'UTF-8')) {
            return $conv;
        }
        return self::safeText($s);
    }

    /**
     * 发送邮件（对外入口，保证返回文本编码安全）
     * @param array $override 临时配置（用于"未保存即测试"、以及平台那套 p_mail_*），键同 settings
     * @param bool  $html     true=按 text/html 发；假则按 text/plain 原样发（正文一律 base64，不会被内容里的词打断）
     * @return string|null 成功返回 null，失败返回错误信息
     */
    public static function send(string $to, string $subject, string $body, array $override = [], bool $html = false): ?string
    {
        $err = self::doSend($to, $subject, $body, $override, $html);
        return $err === null ? null : self::safeText($err);
    }

    protected static function doSend(string $to, string $subject, string $body, array $override = [], bool $html = false): ?string
    {
        $pick = function (string $key, string $default = '') use ($override) {
            if (isset($override[$key]) && $override[$key] !== '') {
                return (string)$override[$key];
            }
            return Setting::get($key, $default);
        };

        $host = trim($pick('smtp_host'));
        $port = (int)$pick('smtp_port', '465');
        $user = trim($pick('smtp_user'));
        // 已保存的旧数据可能带空白，这里再清洗一次做双保险
        $pass = self::normalizeSecret($pick('smtp_pass'));
        // 可能被粘贴成 "名称 <a@qq.com>" 或带尖括号，这里统一清洗
        $fromRaw = trim($pick('smtp_from'));
        $fromRaw = trim(str_replace(["\r", "\n", "\t"], ' ', $fromRaw));

        if ($host === '' || $user === '' || $pass === '') {
            return 'SMTP 未配置完整（服务器 / 账号 / 授权码都不能为空）';
        }
        if (!self::isEmail($user)) {
            return '邮箱账号必须填完整地址（如 123456@qq.com），当前填的是「' . $user . '」';
        }
        if (!self::isEmail($to)) {
            return '收件邮箱格式不正确：「' . $to . '」';
        }

        [$envelope, $fromName, $replyTo] = self::resolveFrom($fromRaw, $user);
        if ($port <= 0) {
            $port = 465;
        }

        // 加密方式：显式给了就照给的走，没给才按端口猜（战队那套永远走这一句的默认分支）
        $secure = strtolower(trim((string)($override['smtp_secure'] ?? '')));
        if ($secure !== 'ssl' && $secure !== 'tls' && $secure !== 'none') {
            $secure = ($port === 465) ? 'ssl' : 'tls';
        }
        $ssl    = ($secure === 'ssl');
        $errno  = 0;
        $errstr = '';

        // 不少服务器（尤其宝塔 PHP）缺少 CA 证书包，严格校验会直接连不上，
        // 这里关闭证书校验：SMTP 通道本身仍是加密的，只是不验证证书链
        $ctx = stream_context_create([
            'ssl' => [
                'verify_peer'       => false,
                'verify_peer_name'  => false,
                'allow_self_signed' => true,
                'SNI_enabled'       => true,
                'peer_name'         => $host,
            ],
        ]);
        $remote = ($ssl ? 'ssl://' : 'tcp://') . $host . ':' . $port;
        $fp = @stream_socket_client($remote, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
        if (!$fp) {
            return '连接 SMTP 服务器失败: ' . self::sysText((string)$errstr) . " ({$errno})";
        }
        stream_set_timeout($fp, 15);

        $read = function () use ($fp) {
            $data = '';
            while ($line = fgets($fp, 515)) {
                $data .= $line;
                // 多行响应以 "250-" 分隔，最后一行是 "250 "
                if (isset($line[3]) && $line[3] === ' ') {
                    break;
                }
            }
            return $data;
        };
        $cmd = function ($fp, $read, $command, $expect) {
            if ($command !== null) {
                fwrite($fp, $command . "\r\n");
            }
            $resp = $read();
            $code = (int)substr($resp, 0, 3);
            if (!in_array($code, (array)$expect, true)) {
                return trim($resp);
            }
            return null;
        };

        // 握手
        if ($err = $cmd($fp, $read, null, 220)) return 'SMTP 握手失败: ' . $err;
        if ($err = $cmd($fp, $read, 'EHLO localhost', 250)) return 'EHLO 失败: ' . $err;

        // STARTTLS（587 这类端口；显式选了 none 就什么都不加，本机中继与自测走这一路）
        if (!$ssl && $secure === 'tls') {
            if ($err = $cmd($fp, $read, 'STARTTLS', 220)) return 'STARTTLS 失败: ' . $err;
            stream_context_set_option($fp, 'ssl', 'verify_peer', false);
            stream_context_set_option($fp, 'ssl', 'verify_peer_name', false);
            stream_context_set_option($fp, 'ssl', 'allow_self_signed', true);
            stream_context_set_option($fp, 'ssl', 'peer_name', $host);
            $crypto = STREAM_CRYPTO_METHOD_TLS_CLIENT;
            if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) {
                $crypto |= STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
            }
            if (!@stream_socket_enable_crypto($fp, true, $crypto)) {
                return 'STARTTLS 加密协商失败';
            }
            if ($err = $cmd($fp, $read, 'EHLO localhost', 250)) return 'EHLO(TLS) 失败: ' . $err;
        }

        // 登录
        if ($err = $cmd($fp, $read, 'AUTH LOGIN', 334)) return 'AUTH 失败: ' . $err;
        if ($err = $cmd($fp, $read, base64_encode($user), 334)) return 'SMTP 用户名错误: ' . $err;
        if ($err = $cmd($fp, $read, base64_encode($pass), 235)) return 'SMTP 密码/授权码错误: ' . $err;

        // 信封
        if ($err = $cmd($fp, $read, "MAIL FROM:<{$envelope}>", 250)) return 'MAIL FROM 失败: ' . $err;
        if ($err = $cmd($fp, $read, "RCPT TO:<{$to}>", 250)) return 'RCPT TO 失败: ' . $err;
        if ($err = $cmd($fp, $read, 'DATA', 354)) return 'DATA 失败: ' . $err;

        // 邮件内容
        $encSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        // 中文显示名必须做 MIME 编码，否则收件箱里会是乱码
        $fromHeader = $fromName !== ''
            ? '=?UTF-8?B?' . base64_encode($fromName) . '?= <' . $envelope . '>'
            : '<' . $envelope . '>';
        $headers = [
            "From: {$fromHeader}",
            "To: {$to}",
            "Subject: {$encSubject}",
        ];
        // 配置里填了另一个邮箱时，不能用它发信（会被判 550），但可以让对方"回复"到那里
        if ($replyTo !== '') {
            $headers[] = "Reply-To: {$replyTo}";
        }
        $headers = array_merge($headers, [
            'MIME-Version: 1.0',
            'Content-Type: ' . ($html ? 'text/html' : 'text/plain') . '; charset=utf-8',
            'Content-Transfer-Encoding: base64',
            'Date: ' . date('r'),
        ]);
        $data = implode("\r\n", $headers) . "\r\n\r\n"
            . chunk_split(base64_encode($body)) . "\r\n.";

        if ($err = $cmd($fp, $read, $data, 250)) return '发送内容失败: ' . $err;
        $cmd($fp, $read, 'QUIT', 221);
        fclose($fp);

        return null;
    }
}
