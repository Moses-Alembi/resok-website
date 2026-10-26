<?php
declare(strict_types=1);

/**
 * Newsletter signup for the footer and home-page forms.
 *
 *   POST email=...              record the address and email a confirmation link
 *   GET  ?confirm=TOKEN         the link in that email: the address is now subscribed
 *   GET  ?unsubscribe=TOKEN     leave the list
 *   GET  ?leave=ID.SIGNATURE    leave the list, from the link in a newsletter (see lib/newsletter.php)
 *
 * Double opt-in on purpose. Anyone can type anyone's address into a footer box, so an
 * address only joins the list once its owner clicks the link - which is also the consent
 * the Data Protection Act asks us to be able to show.
 *
 * One token per subscriber serves both links. Only its SHA-256 is stored, so a copy of
 * the table cannot be used to confirm or unsubscribe anybody.
 */

header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

function respond(int $status, array $payload): void {
    header('Content-Type: application/json');
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

/** The small page shown after a confirm or unsubscribe link is opened. */
function page(int $status, string $heading, string $message): void {
    header('Content-Type: text/html; charset=utf-8');
    http_response_code($status);
    $h = htmlspecialchars($heading, ENT_QUOTES);
    $m = htmlspecialchars($message, ENT_QUOTES);
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>' . $h . ' - ReSoK</title><link rel="icon" href="favicon.ico">'
        . '<style>body{margin:0;min-height:100vh;display:grid;place-items:center;background:#eef6f1;font-family:"Segoe UI",Arial,sans-serif;color:#253141;padding:16px;box-sizing:border-box}'
        . '.box{background:#fff;max-width:460px;width:100%;border-radius:14px;padding:32px 28px;text-align:center;box-shadow:0 6px 24px rgba(15,23,42,.08)}'
        . 'img{width:240px;max-width:80%;height:auto}h1{font-size:24px;margin:22px 0 10px}p{color:#5b6672;line-height:1.6;margin:0 0 22px}'
        . 'a.btn{display:inline-block;background:#087539;color:#fff;text-decoration:none;font-weight:700;padding:12px 22px;border-radius:8px}</style></head>'
        . '<body><div class="box"><img src="resok-portal/public/assets/img/logo.png" alt="ReSoK - Respiratory Society of Kenya">'
        . '<h1>' . $h . '</h1><p>' . $m . '</p><a class="btn" href="./">Back to resok.org</a></div></body></html>';
    exit;
}

function db(array $config): PDO {
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            (string)$config['db_host'], (int)($config['db_port'] ?? 3306), (string)$config['db_name']),
        (string)$config['db_user'], (string)$config['db_pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
    // Same timezone pairing as the portal API and contact.php.
    date_default_timezone_set('Africa/Nairobi');
    $pdo->exec("SET time_zone = '+03:00'");
    // Created on first use, like the portal's other tables; nothing to run by hand. The
    // definition lives with the admin side, in lib/newsletter.php.
    if (!newsletterEnsureTables($pdo)) throw new RuntimeException('Newsletter tables unavailable');
    return $pdo;
}

/** This script's own address, so the links work at the site root and under /resok-new/. */
function selfUrl(): string {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    $host = preg_replace('/[^A-Za-z0-9.:\-]/', '', (string)($_SERVER['HTTP_HOST'] ?? 'www.resok.org'));
    $path = (string)($_SERVER['SCRIPT_NAME'] ?? '/newsletter.php');
    return ($https ? 'https' : 'http') . '://' . $host . $path;
}

$configPath = __DIR__ . '/resok-portal/public/api/config.php';
if (!file_exists($configPath)) respond(500, ['error' => 'The newsletter signup is not configured yet. Please email info@resok.org.']);
$config = require $configPath;
require_once __DIR__ . '/resok-portal/public/api/lib/newsletter.php';

// ---- Links from the confirmation email ----------------------------------------------------

// A PDF newsletter and the picture of its first page, as linked from the email. The key is
// random and new for every upload, so nobody can guess a newsletter before it is sent.
if ($_SERVER['REQUEST_METHOD'] === 'GET' && (isset($_GET['pdf']) || isset($_GET['cover']))) {
    $isPdf = isset($_GET['pdf']);
    $key = (string)($isPdf ? $_GET['pdf'] : $_GET['cover']);
    if (!preg_match('/^[a-f0-9]{32}$/', $key)) page(404, 'Newsletter not found', 'This link is incomplete. Please open it straight from the email.');
    try {
        $stmt = db($config)->prepare('SELECT pdf_name, pdf_ready, cover_ready FROM newsletter_campaigns WHERE pdf_key = ? LIMIT 1');
        $stmt->execute([$key]);
        $row = $stmt->fetch();
    } catch (Throwable $error) {
        error_log('Newsletter file lookup failed: ' . $error->getMessage());
        page(500, 'Something went wrong', 'Please try the link again in a few minutes.');
    }
    $file = newsletterDir($config) . '/' . $key . ($isPdf ? '.pdf' : '.jpg');
    if (!$row || !$row['pdf_ready'] || (!$isPdf && !$row['cover_ready']) || !is_file($file)) {
        page(404, 'Newsletter not found', 'This newsletter is no longer available. Visit resok.org for the latest from ReSoK.');
    }
    header('Content-Type: ' . ($isPdf ? 'application/pdf' : 'image/jpeg'));
    header('Content-Length: ' . filesize($file));
    // The file behind a key never changes (a new upload gets a new key), so it can be cached.
    header('Cache-Control: public, max-age=604800');
    if ($isPdf) header('Content-Disposition: inline; filename="' . str_replace('"', '', (string)$row['pdf_name']) . '"');
    readfile($file);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['leave'])) {
    // Signed rather than a token, because only the hash of each subscriber's token is kept.
    $leave = (string)$_GET['leave'];
    if (!preg_match('/^(\d{1,10})\.([a-f0-9]{32})$/', $leave, $m) || newsletterSigningKey($config) === '') {
        page(400, 'Link not recognised', 'This link is incomplete. Please open it straight from the email, or write to info@resok.org.');
    }
    try {
        $pdo = db($config);
        $stmt = $pdo->prepare('SELECT id, email FROM newsletter_subscribers WHERE id = ? LIMIT 1');
        $stmt->execute([(int)$m[1]]);
        $row = $stmt->fetch();
        if (!$row || !hash_equals(newsletterLeaveSignature($config, (int)$row['id'], (string)$row['email']), $m[2])) {
            page(404, 'Link not recognised', 'This link is not valid. Please open it straight from the email, or write to info@resok.org.');
        }
        $pdo->prepare("UPDATE newsletter_subscribers SET status = 'unsubscribed', unsubscribed_at = NOW() WHERE id = ? AND status <> 'unsubscribed'")
            ->execute([(int)$row['id']]);
        page(200, "You've been unsubscribed", 'You will no longer receive the ReSoK newsletter. You can sign up again at any time on resok.org.');
    } catch (Throwable $error) {
        error_log('Newsletter leave link failed: ' . $error->getMessage());
        page(500, 'Something went wrong', 'Please try the link again in a few minutes, or email info@resok.org.');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $confirm = (string)($_GET['confirm'] ?? '');
    $leave = (string)($_GET['unsubscribe'] ?? '');
    $token = $confirm !== '' ? $confirm : $leave;
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        page(400, 'Link not recognised', 'This link is incomplete. Please open it straight from the email, or sign up again on resok.org.');
    }
    try {
        $pdo = db($config);
        $stmt = $pdo->prepare('SELECT id, status FROM newsletter_subscribers WHERE token_hash = ? LIMIT 1');
        $stmt->execute([hash('sha256', $token)]);
        $row = $stmt->fetch();
        if (!$row) page(404, 'Link not recognised', 'This link has expired or was replaced by a newer one. Please sign up again on resok.org.');
        if ($confirm !== '') {
            if ($row['status'] !== 'confirmed') {
                $pdo->prepare("UPDATE newsletter_subscribers SET status = 'confirmed', confirmed_at = NOW(), unsubscribed_at = NULL WHERE id = ?")
                    ->execute([(int)$row['id']]);
            }
            page(200, "You're subscribed", 'Thank you. You will now receive ReSoK updates on CMEs, webinars, projects and announcements.');
        }
        $pdo->prepare("UPDATE newsletter_subscribers SET status = 'unsubscribed', unsubscribed_at = NOW() WHERE id = ?")
            ->execute([(int)$row['id']]);
        page(200, "You've been unsubscribed", 'You will no longer receive the ReSoK newsletter. You can sign up again at any time on resok.org.');
    } catch (Throwable $error) {
        error_log('Newsletter link failed: ' . $error->getMessage());
        page(500, 'Something went wrong', 'Please try the link again in a few minutes, or email info@resok.org.');
    }
}

// ---- Signup from the forms ----------------------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] !== 'POST') respond(405, ['error' => 'Method not allowed']);

// The same reply whatever the address's state, so the form cannot be used to find out
// who is already on the list.
$done = ['message' => 'Almost done! Check your inbox for a link to confirm your subscription.'];

if (!empty($_POST['website'])) respond(200, $done); // honeypot

$email = strtolower(trim((string)($_POST['email'] ?? '')));
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 254) {
    respond(400, ['error' => 'Please enter a valid email address.']);
}
$source = mb_substr(preg_replace('/[^\w\/.\-]/', '', (string)($_POST['source'] ?? '')), 0, 120) ?: null;

try {
    $pdo = db($config);
} catch (Throwable $error) {
    error_log('Newsletter signup database unavailable: ' . $error->getMessage());
    respond(500, ['error' => 'Could not sign you up right now. Please try again later.']);
}

// Proof of work and rate limit, as on the contact form. Every accepted attempt counts:
// each one sends an email, so an unlimited form would be a way to flood someone's inbox.
try {
    $proofFile = __DIR__ . '/resok-portal/public/api/lib/proof.php';
    if (is_file($proofFile)) {
        require_once $proofFile;
        proofRequire($pdo, $config, 'newsletter', 'POST');
    }
    $throttleFile = __DIR__ . '/resok-portal/public/api/lib/throttle.php';
    if (is_file($throttleFile)) {
        require_once $throttleFile;
        throttleCheck($pdo, $config, 'newsletter', $email);
        throttleFailure($pdo, $config, 'newsletter', $email);
    }
} catch (Throwable $error) {
    error_log('Newsletter rate limit unavailable: ' . $error->getMessage());
}

$stmt = $pdo->prepare('SELECT id, status FROM newsletter_subscribers WHERE email = ? LIMIT 1');
$stmt->execute([$email]);
$existing = $stmt->fetch();

// Already confirmed: nothing to send, and the reply gives nothing away.
if ($existing && $existing['status'] === 'confirmed') respond(200, $done);

$token = bin2hex(random_bytes(32));
if ($existing) {
    // Pending or unsubscribed: a fresh token replaces the old one, so only the newest link works.
    $pdo->prepare("UPDATE newsletter_subscribers SET token_hash = ?, status = 'pending', source = COALESCE(?, source), last_sent_at = NOW() WHERE id = ?")
        ->execute([hash('sha256', $token), $source, (int)$existing['id']]);
} else {
    $pdo->prepare("INSERT INTO newsletter_subscribers (email, token_hash, status, source, last_sent_at) VALUES (?, ?, 'pending', ?, NOW())")
        ->execute([$email, hash('sha256', $token), $source]);
}

require_once __DIR__ . '/resok-portal/public/api/lib/portal-mail.php';
$confirmUrl = selfUrl() . '?confirm=' . $token;
$leaveUrl = selfUrl() . '?unsubscribe=' . $token;

$text = "Hello,\n\n"
    . "Thank you for signing up for the Respiratory Society of Kenya newsletter. Please confirm your email address to start receiving updates on CMEs, webinars, projects and announcements.\n\n"
    . "Confirm your subscription:\n{$confirmUrl}\n\n"
    . "If you did not sign up, you can ignore this email and you will not be added.\n\n"
    . "Thanks,\nReSoK\n";
$html = cardEmailHtml(
    'Confirm your subscription',
    'Hello,',
    cardEmailParagraph('Thank you for signing up for the Respiratory Society of Kenya newsletter. Please confirm your email address to start receiving updates on CMEs, webinars, projects and announcements.')
        . cardEmailParagraph('If you did not sign up, you can ignore this email and you will not be added.', true),
    'Confirm subscription',
    $confirmUrl,
    'Changed your mind? <a href="' . htmlspecialchars($leaveUrl, ENT_QUOTES) . '" style="color:#6b8f78;">Unsubscribe</a>.'
);

$sent = false;
try {
    // The archive inbox gets its copy with the token blanked out, as password links do.
    $sent = (new SimpleMailer($config))->withRedactedArchiveCopy($token)
        ->send($email, 'Confirm your ReSoK newsletter subscription', $text, [], $html);
} catch (Throwable $error) {
    error_log('Newsletter confirmation send threw: ' . $error->getMessage());
}
if (!$sent) {
    error_log("Newsletter confirmation failed to send to {$email}");
    respond(500, ['error' => 'Could not send the confirmation email right now. Please try again later.']);
}

respond(200, $done);
