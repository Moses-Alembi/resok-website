<?php
declare(strict_types=1);

/**
 * Warns about infrastructure that is about to expire.
 *
 * This is the point of the infrastructure module. A domain or SSL certificate that lapses
 * takes the entire website down, and the only thing standing between the organisation and
 * that is somebody remembering a date written in a notebook.
 *
 * Run daily. On a host with real cron:
 *
 *     php /home/<account>/public_html/resok-portal/public/api/cron/ict-expiry-alerts.php
 *
 * Where the host only offers URL-triggered cron, this also accepts
 * ?key=<cron_secret> as a GET request - set cron_secret in config.local.php first.
 *
 * A record is mailed when it crosses INTO an alert band, not while it sits in one. Sending
 * "expires in 30 days" every morning for thirty mornings is how a warning becomes something
 * people filter, and then the one that mattered goes unread with all the others.
 */

$isCli = PHP_SAPI === 'cli';
$config = require __DIR__ . '/../config.php';

foreach (['portal-mail', 'ict', 'ict-infrastructure', 'throttle'] as $module) {
    $path = __DIR__ . '/../lib/' . $module . '.php';
    if (is_file($path)) require_once $path;
}

if (!$isCli) {
    $cronSecret = (string)($config['cron_secret'] ?? '');
    if ($cronSecret === '' || !hash_equals($cronSecret, (string)($_GET['key'] ?? ''))) {
        http_response_code(403);
        echo "Forbidden\n";
        exit;
    }
    header('Content-Type: text/plain');
}

if (!function_exists('ictInfraDueForAlert')) {
    echo "lib/ict-infrastructure.php is not deployed.\n";
    exit(1);
}

try {
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $config['db_host'], (int)($config['db_port'] ?? 3306), $config['db_name']);
    $pdo = new PDO($dsn, $config['db_user'], $config['db_pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    // Same offset the API uses, so "days remaining" here means what it means on screen.
    $pdo->exec("SET time_zone = '+03:00'");
} catch (Throwable $e) {
    echo "Database unavailable: " . $e->getMessage() . "\n";
    exit(1);
}
date_default_timezone_set('Africa/Nairobi');

$due = ictInfraDueForAlert($pdo);
if (!$due) {
    echo "Nothing to report.\n";
    exit(0);
}

/**
 * Who hears about it.
 *
 * The record's named owner first, because they are the person who can actually renew it.
 * Super admins are copied so a warning is never sitting unread in one inbox belonging to
 * someone on leave.
 */
$recipients = [];
foreach ((array)($config['super_admins'] ?? []) as $email) {
    $email = strtolower(trim((string)$email));
    if ($email !== '') $recipients[$email] = true;
}

$sent = 0;
$failed = 0;

foreach ($due as $entry) {
    $row = $entry['row'];
    $health = $entry['health'];

    $to = $recipients;
    if (!empty($row['owner_user_id'])) {
        $owner = $pdo->prepare('SELECT email FROM users WHERE id = ? LIMIT 1');
        $owner->execute([(int)$row['owner_user_id']]);
        if ($ownerRow = $owner->fetch()) $to[strtolower((string)$ownerRow['email'])] = true;
    }
    if (!$to) {
        echo "No recipient for {$row['name']} - set super_admins in config, or name an owner.\n";
        $failed++;
        continue;
    }

    $urgency = $health['band'] === 'expired' ? 'HAS EXPIRED' : 'expires soon';
    $kind = ucfirst((string)$row['kind']);
    $subject = $health['band'] === 'expired'
        ? "EXPIRED: {$row['name']}"
        : "{$kind} renewal due: {$row['name']} ({$health['days']} days)";

    $lines = [
        "{$kind}: {$row['name']}",
        $health['label'] . '.',
        '',
        'Renewal date : ' . $row['expires_on'],
        'Provider     : ' . ($row['provider'] ?: 'not recorded'),
        'Account      : ' . ($row['account_ref'] ?: 'not recorded'),
        'Auto-renew   : ' . (((int)$row['auto_renew']) === 1 ? 'yes' : 'NO'),
    ];
    if (!empty($row['url'])) $lines[] = 'Control panel: ' . $row['url'];
    $lines[] = '';
    $lines[] = ((int)$row['auto_renew']) === 1
        ? 'Auto-renew is on, so this should renew itself. Worth confirming the card on file is still valid.'
        : 'Auto-renew is OFF. This will not renew by itself.';
    $text = implode("\n", $lines);

    $html = '<p style="margin:0 0 14px;font-size:15px;line-height:1.65;"><strong>'
          . htmlspecialchars($kind . ': ' . $row['name'], ENT_QUOTES) . '</strong> '
          . htmlspecialchars($urgency, ENT_QUOTES) . '.</p>'
          . '<table style="font-size:14px;line-height:1.7;border-collapse:collapse;">'
          . '<tr><td style="padding-right:16px;color:#667085;">Renewal date</td><td><strong>'
          . htmlspecialchars((string)$row['expires_on'], ENT_QUOTES) . '</strong></td></tr>'
          . '<tr><td style="padding-right:16px;color:#667085;">Provider</td><td>'
          . htmlspecialchars((string)($row['provider'] ?: 'not recorded'), ENT_QUOTES) . '</td></tr>'
          . '<tr><td style="padding-right:16px;color:#667085;">Account</td><td>'
          . htmlspecialchars((string)($row['account_ref'] ?: 'not recorded'), ENT_QUOTES) . '</td></tr>'
          . '<tr><td style="padding-right:16px;color:#667085;">Auto-renew</td><td>'
          . (((int)$row['auto_renew']) === 1 ? 'Yes' : '<strong style="color:#bc0b22;">No</strong>') . '</td></tr>'
          . '</table>'
          . '<p style="margin:16px 0 0;font-size:13.5px;line-height:1.6;color:#667085;">'
          . (((int)$row['auto_renew']) === 1
              ? 'Auto-renew is on, so this should renew itself &mdash; worth confirming the card on file is still valid.'
              : 'Auto-renew is off. This will not renew by itself.')
          . '</p>';

    $body = function_exists('brandedEmailHtml')
        ? brandedEmailHtml($health['band'] === 'expired' ? 'Something has expired' : 'Renewal due', $html)
        : $html;

    $ok = false;
    foreach (array_keys($to) as $address) {
        try {
            $mailer = new SimpleMailer($config);
            if ($mailer->send($address, $subject, $text, [], $body)) $ok = true;
        } catch (Throwable $e) {
            echo "Mail to {$address} failed: " . $e->getMessage() . "\n";
        }
    }

    if ($ok) {
        // Recorded only on success, so a mail server outage means the warning is retried
        // tomorrow rather than silently marked as delivered and never sent again.
        ictInfraMarkAlerted($pdo, (int)$row['id'], $health['band']);
        if (function_exists('ictAudit')) {
            ictAudit($pdo, null, 'expiry_alert_sent', 'infrastructure', (string)$row['id'],
                     $row['name'] . ': ' . $health['label']);
        }
        echo "Alerted: {$row['name']} ({$health['band']}, {$health['days']} days)\n";
        $sent++;
    } else {
        echo "FAILED to alert about {$row['name']} - will retry tomorrow.\n";
        $failed++;
    }
}

echo "\n{$sent} alert(s) sent, {$failed} failed.\n";
exit($failed > 0 ? 1 : 0);
