<?php
declare(strict_types=1);

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

function respond(int $status, array $payload): void {
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') respond(405, ['error' => 'Method not allowed']);

// Honeypot: a field real visitors never see (hidden via CSS) or fill in. Bots that fill
// every field get a fake success response instead of an error that would teach them to
// skip it.
if (!empty($_POST['website'])) respond(200, ['message' => 'Thanks for reaching out! We will get back to you shortly.']);

// Line breaks are removed from the one-line fields: the subject becomes an email header,
// and a header with a line break in it can carry extra headers of the sender's choosing.
$oneLine = static fn(string $v): string => trim((string)preg_replace('/[
	]+/', ' ', $v));
$name = $oneLine((string)($_POST['name'] ?? ''));
$email = trim((string)($_POST['email'] ?? ''));
$subject = $oneLine((string)($_POST['subject'] ?? ''));
$message = trim((string)($_POST['message'] ?? ''));

if ($name === '' || $subject === '') respond(400, ['error' => 'Please fill in all required fields.']);
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) respond(400, ['error' => 'Please enter a valid email address.']);
if (mb_strlen($message) < 20) respond(400, ['error' => 'Your message must be at least 20 characters.']);
if (mb_strlen($name) > 120 || mb_strlen($subject) > 200 || mb_strlen($email) > 254) {
    respond(400, ['error' => 'Please shorten your name, email or subject.']);
}
if (mb_strlen($message) > 5000) respond(400, ['error' => 'Please keep your message under 5,000 characters.']);

$configPath = __DIR__ . '/resok-portal/public/api/config.php';
if (!file_exists($configPath)) respond(500, ['error' => 'The contact form is not configured yet. Please email info@resok.org directly.']);
$config = require $configPath;

require_once __DIR__ . '/resok-portal/public/api/lib/SimpleMailer.php';

// Rate limit, shared with the portal's: 3 messages an hour from one address, 8 from one
// device. Best effort - if the database is unreachable the form still works, because a
// contact form that silently stops is worse than one that can be spammed for an hour.
$pdo = null;
$throttleFile = __DIR__ . '/resok-portal/public/api/lib/throttle.php';
if (is_file($throttleFile)) {
    try {
        require_once $throttleFile;
        $pdo = new PDO(
            sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                (string)$config['db_host'], (int)($config['db_port'] ?? 3306), (string)$config['db_name']),
            (string)$config['db_user'], (string)$config['db_pass'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
        // Same timezone pairing as the portal API (index.php), or the wait quoted to the
        // visitor is off by the hours between PHP's default zone and MySQL's.
        date_default_timezone_set('Africa/Nairobi');
        $pdo->exec("SET time_zone = '+03:00'");
        throttleCheck($pdo, $config, 'contact', $email);
        // Counted on every accepted attempt, sent or not, so a mail outage is no loophole.
        throttleFailure($pdo, $config, 'contact', $email);
    } catch (Throwable $error) {
        error_log('Contact form rate limit unavailable: ' . $error->getMessage());
        $pdo = null;
    }
}

$to = 'info@resok.org';
$text = "New message from the ReSoK website contact form.\n\n"
    . "Name: {$name}\n"
    . "Email: {$email}\n"
    . "Subject: {$subject}\n\n"
    . "Message:\n{$message}\n\n"
    . "---\nReply directly to this email to respond to {$name}.";

$mailer = new SimpleMailer($config);
$sent = false;
try {
    $sent = $mailer->send($to, "Contact form: {$subject}", $text, [], null, $email);
} catch (Throwable $error) {
    error_log('Contact form send threw: ' . $error->getMessage());
}

if (!$sent) {
    error_log("Contact form email failed to send (from {$email}, subject: {$subject})");
    respond(500, ['error' => 'Could not send your message right now. Please email info@resok.org directly.']);
}

respond(200, ['message' => 'Thanks for reaching out! We will get back to you shortly.']);
