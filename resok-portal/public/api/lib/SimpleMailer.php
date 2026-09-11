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

    /** Why the last send failed, in words fit to show an administrator. Null after a success. */
    public ?string $lastError = null;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /** @param array<int, array{filename:string, content:string, mime:string}> $attachments */
    public function send(string $to, string $subject, string $textBody, array $attachments = [], ?string $htmlBody = null, ?string $replyTo = null): bool
    {
        $this->lastError = null;
        $host = trim((string)($this->config['smtp_host'] ?? ''));
        if ($host !== '') {
            return $this->sendSmtp($host, $to, $subject, $textBody, $attachments, $htmlBody, $replyTo);
        }
        return $this->sendPhpMail($to, $subject, $textBody, $attachments, $htmlBody, $replyTo);
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

    private function buildMime(string $to, string $subject, string $textBody, array $attachments, string $from, ?string $htmlBody = null, ?string $replyTo = null): string
    {
        $headers = [
            'From: Respiratory Society of Kenya <' . $from . '>',
            'To: ' . $to,
            'Subject: ' . $this->encodeHeader($subject),
            'MIME-Version: 1.0'
        ];
        if ($replyTo !== null && $replyTo !== '') {
            $headers[] = 'Reply-To: ' . $replyTo;
        }

        // Every body part is base64 with hard-wrapped lines, because SMTP limits how long a
        // single line may be and the branded HTML is generated as one unbroken string. The
        // host rejected the welcome packet with "message has lines too long for transport
        // (received 2162, limit 2048)" while the shorter verification email passed - which is
        // why one delivered and the other never did. Encoding makes the line length a property
        // of the transfer rather than of whatever the template happens to contain, so no later
        // edit to the copy can bring this back.
        $encodePart = static function (string $content): string {
            return chunk_split(base64_encode($content));
        };

        if ($htmlBody !== null) {
            $altBoundary = 'resok-alt-' . bin2hex(random_bytes(12));
            $bodyContent = "--{$altBoundary}\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . $encodePart($textBody);
            $bodyContent .= "--{$altBoundary}\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . $encodePart($htmlBody);
            $bodyContent .= "--{$altBoundary}--\r\n";
            $bodyContentType = 'multipart/alternative; boundary="' . $altBoundary . '"';
        } else {
            $bodyContent = $encodePart($textBody);
            $bodyContentType = 'text/plain; charset=UTF-8';
        }

        if (!$attachments) {
            $headers[] = 'Content-Type: ' . $bodyContentType;
            if ($htmlBody === null) $headers[] = 'Content-Transfer-Encoding: base64';
            return implode("\r\n", $headers) . "\r\n\r\n" . $bodyContent;
        }

        $boundary = 'resok-' . bin2hex(random_bytes(12));
        $headers[] = 'Content-Type: multipart/mixed; boundary="' . $boundary . '"';

        $body = "--{$boundary}\r\nContent-Type: {$bodyContentType}\r\n";
        if ($htmlBody === null) $body .= "Content-Transfer-Encoding: base64\r\n";
        $body .= "\r\n{$bodyContent}\r\n";

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

    private function sendPhpMail(string $to, string $subject, string $textBody, array $attachments, ?string $htmlBody = null, ?string $replyTo = null): bool
    {
        $from = $this->fromAddress();
        if (!$attachments && $htmlBody === null) {
            $headers = "From: Respiratory Society of Kenya <{$from}>\r\nContent-Type: text/plain; charset=UTF-8";
            if ($replyTo !== null && $replyTo !== '') $headers .= "\r\nReply-To: {$replyTo}";
            return @mail($to, $subject, $textBody, $headers);
        }
        $message = $this->buildMime($to, $subject, $textBody, $attachments, $from, $htmlBody, $replyTo);
        [$headerBlock, $bodyBlock] = explode("\r\n\r\n", $message, 2);
        $extraHeaders = trim((string)preg_replace('/^(To|Subject):.*$/mi', '', $headerBlock));
        return @mail($to, $subject, $bodyBlock, $extraHeaders);
    }

    private function expect(mixed $socket, int $code, string $stage): bool
    {
        $line = '';
        do {
            $line = fgets($socket, 515);
            if ($line === false) {
                $this->lastError = "The server closed the connection during {$stage}.";
                error_log("SMTP {$stage} failed: connection closed / no response");
                return false;
            }
        } while (isset($line[3]) && $line[3] === '-');
        $actual = (int)substr($line, 0, 3);
        if ($actual !== $code) {
            $this->lastError = "Rejected at {$stage}: " . trim($line);
            error_log("SMTP {$stage} failed: expected {$code}, got: " . trim($line));
            return false;
        }
        return true;
    }

    private function sendSmtp(string $host, string $to, string $subject, string $textBody, array $attachments, ?string $htmlBody = null, ?string $replyTo = null): bool
    {
        $port = (int)($this->config['smtp_port'] ?? 587);
        $user = (string)($this->config['smtp_user'] ?? '');
        $pass = (string)($this->config['smtp_pass'] ?? '');
        $from = $this->fromAddress();
        $implicitTls = $port === 465;

        $socket = @fsockopen(($implicitTls ? 'ssl://' : '') . $host, $port, $errno, $errstr, 15);
        if (!$socket) {
            $this->lastError = "Could not reach {$host} on port {$port}: {$errstr} (error {$errno}).";
            error_log("SMTP connect failed to {$host}:{$port} - [{$errno}] {$errstr}");
            return false;
        }
        stream_set_timeout($socket, 15);

        $send = static function (string $command) use ($socket): void {
            fwrite($socket, $command . "\r\n");
        };

        // Greeting can be multi-line (banner + a spam/bulk-mail policy notice); must be
        // consumed the same continuation-aware way as every other response, or the
        // leftover lines get misread as the reply to the next command (EHLO).
        $ok = $this->expect($socket, 220, 'greeting');

        if ($ok) {
            $send('EHLO resok.org');
            $ok = $this->expect($socket, 250, 'EHLO');
        }

        if ($ok && !$implicitTls) {
            $send('STARTTLS');
            $ok = $this->expect($socket, 220, 'STARTTLS');
            if ($ok) {
                $ok = stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
                if (!$ok) error_log('SMTP STARTTLS failed: stream_socket_enable_crypto returned false');
            }
            if ($ok) {
                $send('EHLO resok.org');
                $ok = $this->expect($socket, 250, 'EHLO after STARTTLS');
            }
        }

        if ($ok && $user !== '') {
            $send('AUTH LOGIN');
            $ok = $this->expect($socket, 334, 'AUTH LOGIN');
            if ($ok) { $send(base64_encode($user)); $ok = $this->expect($socket, 334, 'AUTH username'); }
            if ($ok) { $send(base64_encode($pass)); $ok = $this->expect($socket, 235, 'AUTH password'); }
        } elseif ($ok && $user === '') {
            error_log('SMTP warning: smtp_user is empty, skipping AUTH - most servers will reject this');
        }

        if ($ok) { $send('MAIL FROM:<' . $from . '>'); $ok = $this->expect($socket, 250, 'MAIL FROM'); }
        if ($ok) { $send('RCPT TO:<' . $to . '>'); $ok = $this->expect($socket, 250, 'RCPT TO'); }
        if ($ok) { $send('DATA'); $ok = $this->expect($socket, 354, 'DATA'); }

        if ($ok) {
            $message = $this->buildMime($to, $subject, $textBody, $attachments, $from, $htmlBody, $replyTo);
            $escaped = preg_replace('/^\./m', '..', $message);
            // The host scans outbound mail before accepting it, and that scan is charged
            // against this read, not the write. A welcome packet carrying the letter artwork
            // runs to some hundreds of kilobytes, where the 15s that suits a bare verification
            // link is not enough - and a timeout here loses a message the server was still
            // willing to take.
            stream_set_timeout($socket, 90);
            fwrite($socket, $escaped . "\r\n.\r\n");
            $ok = $this->expect($socket, 250, 'message body');
            if (!$ok) {
                $meta = stream_get_meta_data($socket);
                if (!empty($meta['timed_out'])) {
                    error_log(sprintf(
                        'SMTP timed out waiting for the server to accept a %d-byte message to %s. '
                        . 'It may still have been delivered; do not assume it was lost.',
                        strlen($escaped), $to
                    ));
                }
            }
        }

        $send('QUIT');
        fclose($socket);
        if ($ok) error_log("SMTP send OK to {$to}");
        return $ok;
    }
}
