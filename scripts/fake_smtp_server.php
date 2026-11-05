<?php
/**
 * 开发/自测用的假 SMTP 服务器（只听 127.0.0.1，不对外；v5.31.0 邮件群发的 e2e 靠它）
 *
 *   php scripts/fake_smtp_server.php 2525              # 端口默认 2525
 *
 * 它做的事就一件：按 SMTP 的样子收一封，把收到的东西按 JSON 一行一行写进
 *   runtime/_fakesmtp<端口>.log    （{at, from, to, subject, body_len, is_html, body}）
 * 然后把「哪些地址要故意退回」写在
 *   runtime/_fakesmtp<端口>.reject （一行一个地址片段，命中就回 550）
 * —— 每次收信时现读，所以测试跑到一半也能改规则，不用重启。
 *
 * 只支持 AUTH LOGIN + 明文（secure=none）：这已经够把 Mailer 那条链路走全，
 * 证书与 TLS 不在这个工具的范围内（要测真 TLS 请对着真邮箱服务器发一封）。
 *
 * 退出条件：收到 600 封、或者跑满 900 秒、或者 runtime/_fakesmtp.stop 这个文件出现。
 * 测试脚本负责把它起起来、用完杀掉。
 */
declare(strict_types=1);

$port = (int)($argv[1] ?? 2525);
$ROOT = dirname(__DIR__);
// 每一只服务器只认自己端口那一份文件：两只不同端口的实例互不踩，
// 别的工作区占着 2525 时也绝不会被当成「我们这一轮收到的信」
$TAG    = $port;
$LOG  = $ROOT . '/runtime/_fakesmtp' . $TAG . '.log';
$REJ  = $ROOT . '/runtime/_fakesmtp' . $TAG . '.reject';
$STOP = $ROOT . '/runtime/_fakesmtp' . $TAG . '.stop';

if (PHP_SAPI !== 'cli') {
    exit;   // 万一被 web 根目录误当页面打开：什么都不做，也别把端口占了
}
if (strpos($REJ, $ROOT) !== 0) {
    exit;
}

@unlink($LOG);
// 一整包测试跑下来不止五分钟：原来 300 秒它自己就下班了，后面的发信全撞在空端口上
$deadline = time() + 900;
$server = @stream_socket_server('tcp://127.0.0.1:' . $port, $errno, $errstr);
if (!$server) {
    fwrite(STDERR, "假 SMTP 起不来：" . $errstr . " ({$errno})\n");
    exit(1);
}
fwrite(STDOUT, "假 SMTP 已在 127.0.0.1:{$port} 监听，日志 {$LOG}\n");

$rejects = static function () use ($REJ): array {
    if (!is_file($REJ)) {
        return [];
    }
    // 同上：名单里混进非 ASCII 字节时 \R 会把一行切成两半，凭空多出假的退信地址
    $lines = array_map('trim', explode("\n", (string)file_get_contents($REJ)));
    return array_values(array_filter($lines, static fn ($l) => $l !== '' && $l[0] !== '#'));
};

$served = 0;
stream_set_timeout($server, 2);
while (!file_exists($STOP) && $served < 600 && time() < $deadline) {
    $conn = @stream_socket_accept($server, 2);
    if (!$conn) {
        continue;
    }
    stream_set_timeout($conn, 8);
    $send = static function (string $line) use ($conn): void {
        @fwrite($conn, $line . "\r\n");
    };
    $readLine = static function () use ($conn): ?string {
        $l = fgets($conn, 4096);
        return $l === false ? null : rtrim($l, "\r\n");
    };

    $send('220 fake-smtp ESMTP ready');
    $from = '';
    $to   = '';
    $state = 'cmd';   // cmd | auth_user | auth_pass | data
    $data = '';
    while (($line = $readLine()) !== null) {
        if ($state === 'data') {
            if ($line === '.') {
                $state = 'cmd';
                $entry = [
                    'at'      => date('Y-m-d H:i:s'),
                    'from'    => $from,
                    'to'      => $to,
                    'subject' => '',
                    'is_html' => 0,
                    'body_len' => 0,
                    'body'    => '',
                ];
                // 头部与正文按 SMTP 的写法用空行分开；base64 的那一段还原回原文
                $parts = preg_split("/\r?\n\r?\n/", $data, 2);
                $head  = $parts[0] ?? '';
                $rest  = $parts[1] ?? '';
                if (preg_match('/^Subject:\s*(.+)$/mi', $head, $m)) {
                    $sub = trim($m[1]);
                    if (preg_match('/^=\?UTF-8\?B\?(.+)\?=$/', $sub, $b)) {
                        $sub = (string)base64_decode($b[1]);
                    }
                    $entry['subject'] = $sub;
                }
                if (preg_match('/^Content-Type:\s*([a-z\/]+);?/mi', $head, $m)) {
                    $entry['content_type'] = strtolower($m[1]);
                    $entry['is_html'] = strpos(strtolower($m[1]), 'html') !== false ? 1 : 0;
                }
                $body = preg_replace('/\s+$/', '', $rest) ?? $rest;
                $flat = str_replace(["\r\n", "\n"], '', $body);
                $dec  = base64_decode($flat, true);
                $entry['body'] = $dec === false ? $body : $dec;
                $entry['body_len'] = mb_strlen($entry['body']);
                $fp = @fopen($LOG, 'a');
                if ($fp) {
                    flock($fp, LOCK_EX);
                    fwrite($fp, json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
                    flock($fp, LOCK_UN);
                    fclose($fp);
                } else {
                    // 上一轮就是这里静默失败，测试那边数出来永远是 0 封：这种情况必须喊出来
                    $e = error_get_last();
                    fwrite(STDERR, '假 SMTP 写不进日志 ' . $LOG . '：' . (string)($e['message'] ?? '未知') . "\n");
                    $send('451 4.3.0 local problem, try later');
                    $state = 'cmd';
                    $from = ''; $to = '';
                    continue;
                }
                $send('250 OK message accepted');
                $served++;
                $from = ''; $to = '';
                continue;
            }
            $data .= $line . "\r\n";
            continue;
        }
        $up = strtoupper($line);
        if (strpos($up, 'EHLO') === 0 || strpos($up, 'HELO') === 0) {
            $send('250 fake-smtp');
        } elseif (strpos($up, 'STARTTLS') === 0) {
            $send('454 TLS not available (这台假服务器只收明文)');
        } elseif (strpos($up, 'AUTH LOGIN') === 0) {
            $state = 'auth_user';
            $send('334 ' . base64_encode('Username?'));
        } elseif ($state === 'auth_user') {
            // 刚问完账号，这一行就是 base64 的账号（这台假服务器不验凭据）
            $state = 'auth_pass';
            $send('334 ' . base64_encode('Password?'));
        } elseif ($state === 'auth_pass') {
            $state = 'cmd';
            $send('235 authentication succeeded');
        } elseif (strpos($up, 'MAIL FROM') === 0) {
            preg_match('/<([^>]*)>/', $line, $m);
            $from = $m[1] ?? '';
            $send('250 OK');
        } elseif (strpos($up, 'RCPT TO') === 0) {
            preg_match('/<([^>]*)>/', $line, $m);
            $to = $m[1] ?? '';
            $hit = false;
            foreach ($rejects() as $frag) {
                if ($frag !== '' && strpos($to, $frag) !== false) { $hit = true; break; }
            }
            if ($hit) {
                $send('550 5.1.1 <' . $to . '> Recipient address rejected: 这台假服务器按名单退回');
            } else {
                $send('250 OK');
            }
        } elseif (strpos($up, 'DATA') === 0) {
            $state = 'data';
            $data  = '';
            $send('354 end with <CR><LF>.<CR><LF>');
        } elseif (strpos($up, 'RSET') === 0) {
            $from = ''; $to = '';
            $send('250 OK');
        } elseif (strpos($up, 'NOOP') === 0) {
            $send('250 OK');
        } elseif (strpos($up, 'QUIT') === 0) {
            $send('221 bye');
            break;
        } else {
            // 没认出来的行一律回 250：这台机器只负责把协议走通，不装严格
            $send('250 OK');
        }
    }
    @fclose($conn);
}
@touch($ROOT . '/runtime/_fakesmtp' . $TAG . '.done');
fwrite(STDOUT, "假 SMTP 收工，共收 {$served} 封\n");
