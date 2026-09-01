<?php
declare(strict_types=1);

/**
 * Minimal dependency-free mailer: raw SMTP (STARTTLS/implicit TLS + AUTH LOGIN) when
 * config supplies smtp_host, otherwise falls back to PHP's mail(). No Composer/vendor
 * libraries are available on the target shared host, so this replaces PHPMailer for
 * the portal's low-volume transactional email (verification, welcome packet, reminders).
 */
class SimpleMailer
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /** @param array<int, array{filename:string, content:string, mime:string}> $attachments */
    public function send(string $to, string $subject, string $textBody, array $attachments = []): bool
    {
        $host = trim((string)($this->config['smtp_host'] ?? ''));
        if ($host !== '') {
            return $this->sendSmtp($host, $to, $subject, $textBody, $attachments);
        }
        return $this->sendPhpMail($to, $subject, $textBody, $attachments);
    }

    private function fromAddress(): string
    {
        $from = trim((string)($this->config['mail_from'] ?? ''));
        return $from !== '' ? $from : 'no-reply@resok.org';
    }

    private function encodeHeader(string $value): string
    {
        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }

    private function buildMime(string $to, string $subject, string $textBody, array $attachments, string $from): string
    {
        $boundary = 'resok-' . bin2hex(random_bytes(12));
        $headers = [
            'From: Respiratory Society of Kenya <' . $from . '>',
            'To: ' . $to,
            'Subject: ' . $this->encodeHeader($subject),
            'MIME-Version: 1.0',
            'Content-Type: multipart/mixed; boundary="' . $boundary . '"'
        ];

        $body = "--{$boundary}\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n{$textBody}\r\n";
        foreach ($attachments as $attachment) {
            $body .= "--{$boundary}\r\n";
            $body .= 'Content-Type: ' . $attachment['mime'] . '; name="' . $attachment['filename'] . "\"\r\n";
            $body .= "Content-Transfer-Encoding: base64\r\n";
            $body .= 'Content-Disposition: attachment; filename="' . $attachment['filename'] . "\"\r\n\r\n";
            $body .= chunk_split(base64_encode($attachment['content']));
        }
        $body .= "--{$boundary}--\r\n";

        return implode("\r\n", $headers) . "\r\n\r\n" . $body;
    }

    private function sendPhpMail(string $to, string $subject, string $textBody, array $attachments): bool
    {
        $from = $this->fromAddress();
        if (!$attachments) {
            $headers = "From: Respiratory Society of Kenya <{$from}>\r\nContent-Type: text/plain; charset=UTF-8";
            return @mail($to, $subject, $textBody, $headers);
        }
        $message = $this->buildMime($to, $subject, $textBody, $attachments, $from);
        [$headerBlock, $bodyBlock] = explode("\r\n\r\n", $message, 2);
        $extraHeaders = trim((string)preg_replace('/^(To|Subject):.*$/mi', '', $headerBlock));
        return @mail($to, $subject, $bodyBlock, $extraHeaders);
    }

    private function expect(mixed $socket, int $code): bool
    {
        $line = '';
        do {
            $line = fgets($socket, 515);
            if ($line === false) return false;
        } while (isset($line[3]) && $line[3] === '-');
        return (int)substr($line, 0, 3) === $code;
    }

    private function sendSmtp(string $host, string $to, string $subject, string $textBody, array $attachments): bool
    {
        $port = (int)($this->config['smtp_port'] ?? 587);
        $user = (string)($this->config['smtp_user'] ?? '');
        $pass = (string)($this->config['smtp_pass'] ?? '');
        $from = $this->fromAddress();
        $implicitTls = $port === 465;

        $socket = @fsockopen(($implicitTls ? 'ssl://' : '') . $host, $port, $errno, $errstr, 15);
        if (!$socket) {
            error_log("SMTP connect failed: {$errstr}");
            return false;
        }
        stream_set_timeout($socket, 15);

        $ok = true;
        $greeting = fgets($socket, 515);
        $ok = $ok && $greeting !== false && substr($greeting, 0, 3) === '220';

        $send = static function (string $command) use ($socket): void {
            fwrite($socket, $command . "\r\n");
        };

        $send('EHLO resok.org');
        $ok = $ok && $this->expect($socket, 250);

        if ($ok && !$implicitTls) {
            $send('STARTTLS');
            $ok = $this->expect($socket, 220);
            $ok = $ok && stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            if ($ok) {
                $send('EHLO resok.org');
                $ok = $this->expect($socket, 250);
            }
        }

        if ($ok && $user !== '') {
            $send('AUTH LOGIN');
            $ok = $this->expect($socket, 334);
            if ($ok) { $send(base64_encode($user)); $ok = $this->expect($socket, 334); }
            if ($ok) { $send(base64_encode($pass)); $ok = $this->expect($socket, 235); }
        }

        if ($ok) { $send('MAIL FROM:<' . $from . '>'); $ok = $this->expect($socket, 250); }
        if ($ok) { $send('RCPT TO:<' . $to . '>'); $ok = $this->expect($socket, 250); }
        if ($ok) { $send('DATA'); $ok = $this->expect($socket, 354); }

        if ($ok) {
            $message = $this->buildMime($to, $subject, $textBody, $attachments, $from);
            $escaped = preg_replace('/^\./m', '..', $message);
            fwrite($socket, $escaped . "\r\n.\r\n");
            $ok = $this->expect($socket, 250);
        }

        $send('QUIT');
        fclose($socket);
        return $ok;
    }
}
