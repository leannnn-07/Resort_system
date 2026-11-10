<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

/**
 * Tiny dependency-free mailer (no Composer needed).
 *   driver 'log'  -> appends the email to storage/mail.log
 *   driver 'smtp' -> sends through the SMTP account in config.php
 * send_mail() never throws: it returns false and writes to the PHP error log, so a mail problem
 * can never lose a booking.
 */

/** Strip anything that could inject extra headers. */
function mail_clean_header(string $value): string
{
    return trim(preg_replace('/[\r\n\x00]+/', ' ', $value));
}

function mail_encode_header(string $value): string
{
    $value = mail_clean_header($value);
    return preg_match('/^[\x20-\x7E]*$/', $value) ? $value : '=?UTF-8?B?' . base64_encode($value) . '?=';
}

/** Public base URL of the site, no trailing slash. Uses config 'base_url' when set. */
function site_base_url(): string
{
    global $config;
    $set = rtrim((string)($config['base_url'] ?? ''), '/');
    if ($set !== '') {
        return $set;
    }
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $host  = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
    if (!preg_match('/^[A-Za-z0-9.\-]+(:\d{1,5})?$/', $host)) {
        $host = 'localhost';
    }
    // Folder of the public site (strip /admin when called from the staff area).
    $dir = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
    $dir = preg_replace('#/admin$#', '', $dir);
    return ($https ? 'https://' : 'http://') . $host . $dir;
}

/**
 * Wrap content in a simple, email-safe HTML layout.
 * $bodyHtml must already be escaped/safe HTML.
 */
function mail_html(string $heading, string $bodyHtml): string
{
    global $config;
    return '<!doctype html><html><body style="margin:0;padding:24px;background:#0b1620;font-family:Arial,Helvetica,sans-serif;color:#10222e">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td align="center">'
        . '<table role="presentation" width="560" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:12px;overflow:hidden">'
        . '<tr><td style="background:#0e7490;color:#ffffff;padding:18px 28px;font-size:18px;font-weight:bold">' . h($config['resort']) . '</td></tr>'
        . '<tr><td style="padding:28px;font-size:15px;line-height:1.55">'
        . '<h1 style="margin:0 0 14px;font-size:20px">' . h($heading) . '</h1>' . $bodyHtml
        . '</td></tr>'
        . '<tr><td style="padding:16px 28px;background:#f1f5f7;color:#5b6b75;font-size:12px">'
        . h($config['resort']) . ' · ' . h($config['contact_email']) . ' · ' . h($config['contact_phone'])
        . '</td></tr></table></td></tr></table></body></html>';
}

/** Send one email. $text is the plain-text version, $html the optional HTML version. */
function send_mail(string $to, string $subject, string $text, ?string $html = null): bool
{
    global $config;
    $m = $config['mail'] ?? [];
    $to = mail_clean_header($to);
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    $fromEmail = mail_clean_header((string)($m['from_email'] ?? $config['contact_email']));
    $fromName  = (string)($m['from_name'] ?? $config['resort']);
    $driver    = (string)($m['driver'] ?? 'log');

    if ($driver !== 'smtp') {
        $dir = __DIR__ . '/../storage';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $entry = "==== " . date('Y-m-d H:i:s') . " ====\nTo: $to\nSubject: " . mail_clean_header($subject) . "\n\n" . $text . "\n\n";
        return @file_put_contents($dir . '/mail.log', $entry, FILE_APPEND | LOCK_EX) !== false;
    }

    $boundary = 'b' . bin2hex(random_bytes(12));
    $headers  = [
        'Date: ' . date('r'),
        'From: ' . mail_encode_header($fromName) . ' <' . $fromEmail . '>',
        'To: <' . $to . '>',
        'Subject: ' . mail_encode_header($subject),
        'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . (preg_replace('/^.*@/', '', $fromEmail) ?: 'localhost') . '>',
        'MIME-Version: 1.0',
    ];
    if ($html !== null) {
        $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';
        $body = "--$boundary\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
              . chunk_split(base64_encode($text))
              . "--$boundary\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
              . chunk_split(base64_encode($html))
              . "--$boundary--\r\n";
    } else {
        $headers[] = 'Content-Type: text/plain; charset=UTF-8';
        $headers[] = 'Content-Transfer-Encoding: base64';
        $body = chunk_split(base64_encode($text));
    }
    $message = implode("\r\n", $headers) . "\r\n\r\n" . $body;

    try {
        smtp_deliver($m, $fromEmail, $to, $message);
        return true;
    } catch (Throwable $e) {
        error_log('[resort] mail to ' . $to . ' failed: ' . $e->getMessage());
        return false;
    }
}

/** Read one (possibly multi-line) SMTP reply; returns [code, text]. */
function smtp_read($fp): array
{
    $text = '';
    while (($line = fgets($fp, 1024)) !== false) {
        $text .= $line;
        if (strlen($line) < 4 || $line[3] === ' ') {
            break;
        }
    }
    if ($text === '') {
        throw new RuntimeException('No reply from mail server');
    }
    return [(int)substr($text, 0, 3), trim($text)];
}

function smtp_cmd($fp, string $cmd, array $okCodes): string
{
    fwrite($fp, $cmd . "\r\n");
    [$code, $text] = smtp_read($fp);
    if (!in_array($code, $okCodes, true)) {
        // Never log the command itself: it can contain the password.
        throw new RuntimeException('SMTP error ' . $code . ': ' . $text);
    }
    return $text;
}

function smtp_deliver(array $m, string $from, string $to, string $message): void
{
    $host = (string)($m['host'] ?? '');
    $port = (int)($m['port'] ?? 587);
    $enc  = (string)($m['encryption'] ?? 'tls');
    if ($host === '') {
        throw new RuntimeException('SMTP host not set in config.php');
    }

    $target = ($enc === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
    $fp = @stream_socket_client($target, $errno, $errstr, 15);
    if (!$fp) {
        throw new RuntimeException("Cannot connect to $host:$port ($errstr)");
    }
    stream_set_timeout($fp, 15);

    try {
        [$code, $banner] = smtp_read($fp);
        if ($code !== 220) {
            throw new RuntimeException('Bad greeting: ' . $banner);
        }
        $ehloHost = preg_match('/^[A-Za-z0-9.\-]+$/', (string)($_SERVER['SERVER_NAME'] ?? '')) ? $_SERVER['SERVER_NAME'] : 'localhost';
        smtp_cmd($fp, 'EHLO ' . $ehloHost, [250]);

        if ($enc === 'tls') {
            smtp_cmd($fp, 'STARTTLS', [220]);
            if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('Could not start TLS');
            }
            smtp_cmd($fp, 'EHLO ' . $ehloHost, [250]);
        }

        $user = (string)($m['username'] ?? '');
        if ($user !== '') {
            smtp_cmd($fp, 'AUTH LOGIN', [334]);
            smtp_cmd($fp, base64_encode($user), [334]);
            smtp_cmd($fp, base64_encode((string)($m['password'] ?? '')), [235]);
        }

        smtp_cmd($fp, 'MAIL FROM:<' . $from . '>', [250]);
        smtp_cmd($fp, 'RCPT TO:<' . $to . '>', [250, 251]);
        smtp_cmd($fp, 'DATA', [354]);

        // Dot-stuff and normalise line endings, then terminate with <CRLF>.<CRLF>.
        $message = preg_replace('/\r\n|\r|\n/', "\r\n", $message);
        $message = preg_replace('/^\./m', '..', $message);
        smtp_cmd($fp, $message . "\r\n.", [250]);
        @smtp_cmd($fp, 'QUIT', [221]);
    } finally {
        fclose($fp);
    }
}
