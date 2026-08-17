<?php
/**
 * 邮件发送（纯 PHP SMTP 客户端，无第三方依赖）
 * -------------------------------------------------
 * 通过后台「邮件通知」配置发信，默认适配 QQ 邮箱。
 * 用法：
 *   require_once __DIR__ . '/mailer.php';
 *   $ok = mailSend('收件人@example.com', '邮件主题', '邮件正文HTML');
 *   if ($ok !== true) // $ok 为错误信息字符串
 *
 * 依赖 settings 表配置（后台「邮件通知」页可填）：
 *   smtp_host       SMTP 服务器，默认 smtp.qq.com
 *   smtp_port       端口，默认 465
 *   smtp_secure     ssl | tls | none，默认 ssl
 *   smtp_user       邮箱账号（QQ 邮箱填完整邮箱）
 *   smtp_pass       授权码（QQ 邮箱在设置→账户→开启 SMTP 获取）
 *   smtp_from       发件人地址（留空用 smtp_user）
 *   smtp_from_name  发件人显示名（默认「Auth Center」）
 */

/**
 * 组装发信所需的 SMTP 配置（从 settings 表读取，带默认值）
 */
function mailConfig(): array
{
    $host = cfg('smtp_host', 'smtp.qq.com');
    $port = (int)cfg('smtp_port', '465');
    $user = cfg('smtp_user', '');
    $pass = cfg('smtp_pass', '');
    $from = cfg('smtp_from', $user);
    $name = cfg('smtp_from_name', 'Auth Center');

    $secure = strtolower(cfg('smtp_secure', 'ssl'));
    if (!in_array($secure, ['ssl', 'tls', 'none'], true)) {
        $secure = 'ssl';
    }

    return [
        'host'   => $host,
        'port'   => $port,
        'secure' => $secure,
        'user'   => $user,
        'pass'   => $pass,
        'from'   => $from,
        'from_name' => $name,
    ];
}

/**
 * 与 SMTP 服务器读写一行（简单封装）
 */
function smtpLine($fp, string $cmd = null): ?string
{
    if ($cmd !== null) {
        fwrite($fp, $cmd . "\r\n");
    }
    $resp = fgets($fp, 1024);
    // 多行响应（以 - 结尾）需读完
    while ($resp !== false && strlen($resp) >= 4 && substr($resp, 3, 1) === '-') {
        $resp = fgets($fp, 1024);
    }
    return $resp === false ? null : rtrim($resp, "\r\n");
}

/**
 * 发送邮件
 * 成功返回 true，失败返回错误信息字符串
 */
function mailSend(string $to, string $subject, string $bodyHtml): mixed
{
    $c = mailConfig();

    if ($c['user'] === '' || $c['pass'] === '') {
        return '未配置 SMTP 账号/授权码，请先在后台「邮件通知」里填写';
    }

    // 建立连接
    $prefix = '';
    $host   = $c['host'];
    if ($c['secure'] === 'ssl') {
        $prefix = 'ssl://';
    } elseif ($c['secure'] === 'tls') {
        $host   = 'tcp://' . $c['host'];
        $prefix = '';
    }
    $errno = 0;
    $errstr = '';
    $fp = @stream_socket_client(
        $prefix . $host . ':' . $c['port'],
        $errno, $errstr, 15,
        STREAM_CLIENT_CONNECT,
        stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]])
    );
    if (!$fp) {
        return "无法连接 SMTP 服务器 {$c['host']}:{$c['port']}（$errstr）";
    }
    stream_set_timeout($fp, 15);

    // 读 banner
    smtpLine($fp);

    // EHLO
    $helo = smtpLine($fp, 'EHLO ' . ($_SERVER['SERVER_NAME'] ?? 'localhost'));
    if ($helo === null || !preg_match('/^2\d\d/', (string)$helo)) {
        fclose($fp);
        return 'EHLO 失败: ' . (string)$helo;
    }

    // STARTTLS（tls 模式）
    if ($c['secure'] === 'tls') {
        $r = smtpLine($fp, 'STARTTLS');
        if (!preg_match('/^2\d\d/', (string)$r)) {
            fclose($fp);
            return 'STARTTLS 失败: ' . (string)$r;
        }
        stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
        // TLS 后重新 EHLO
        smtpLine($fp, 'EHLO ' . ($_SERVER['SERVER_NAME'] ?? 'localhost'));
    }

    // AUTH LOGIN
    $r = smtpLine($fp, 'AUTH LOGIN');
    if (!preg_match('/^3\d\d/', (string)$r)) {
        fclose($fp);
        return 'AUTH 失败: ' . (string)$r;
    }
    smtpLine($fp, base64_encode($c['user']));
    $r = smtpLine($fp, base64_encode($c['pass']));
    if (!preg_match('/^2\d\d|^3\d\d/', (string)$r)) {
        fclose($fp);
        return '登录失败（账号或授权码错误）: ' . (string)$r;
    }

    // MAIL FROM / RCPT TO / DATA
    $fromAddr = $c['from'] !== '' ? $c['from'] : $c['user'];
    if (!smtpOk($fp, 'MAIL FROM:<' . $fromAddr . '>')) {
        fclose($fp);
        return 'MAIL FROM 失败';
    }
    if (!smtpOk($fp, 'RCPT TO:<' . $to . '>')) {
        fclose($fp);
        return 'RCPT TO 失败（收件人可能不存在或格式错误）';
    }
    // DATA 成功响应是 354（服务器就绪，等待正文），需要接受 3xx
    $dataReply = smtpLine($fp, 'DATA');
    if (!preg_match('/^3\d\d/', (string)$dataReply)) {
        fclose($fp);
        return 'DATA 失败: ' . (string)$dataReply;
    }

    $fromName = $c['from_name'] !== '' ? $c['from_name'] : 'Auth Center';
    $headers  = "From: =?UTF-8?B?" . base64_encode($fromName) . "?= <{$fromAddr}>\r\n";
    $headers .= "To: <{$to}>\r\n";
    $headers .= "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "Content-Transfer-Encoding: base64\r\n";

    $body = chunk_split(base64_encode($bodyHtml), 76, "\r\n");

    fwrite($fp, $headers . "\r\n" . $body . "\r\n.\r\n");
    $r = smtpLine($fp);
    fclose($fp);

    if (preg_match('/^2\d\d/', (string)$r)) {
        return true;
    }
    return '发送失败: ' . (string)$r;
}

/** SMTP 命令是否返回 2xx */
function smtpOk($fp, string $cmd): bool
{
    $r = smtpLine($fp, $cmd);
    return preg_match('/^2\d\d/', (string)$r) === 1;
}
