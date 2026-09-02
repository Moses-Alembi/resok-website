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

$name = trim((string)($_POST['name'] ?? ''));
$email = trim((string)($_POST['email'] ?? ''));
$subject = trim((string)($_POST['subject'] ?? ''));
$message = trim((string)($_POST['message'] ?? ''));

if ($name === '' || $subject === '') respond(400, ['error' => 'Please fill in all required fields.']);
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) respond(400, ['error' => 'Please enter a valid email address.']);
if (mb_strlen($message) < 20) respond(400, ['error' => 'Your message must be at least 20 characters.']);

$configPath = __DIR__ . '/resok-portal/public/api/config.php';
if (!file_exists($configPath)) respond(500, ['error' => 'The contact form is not configured yet. Please email info@resok.org directly.']);
$config = require $configPath;

require_once __DIR__ . '/resok-portal/public/api/lib/SimpleMailer.php';

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
