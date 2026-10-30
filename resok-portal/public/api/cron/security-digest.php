<?php
declare(strict_types=1);

/**
 * The security update Moses asked for every 8 hours (16 Oct 2026).
 *
 * Runs the same checks as Admin > Threat Assessment, adds what only shows from outside
 * (does http redirect to https, are the files that must never be served refused), and
 * summarises the security log for the period since the last run: failed sign-ins, lockouts,
 * resets, anything logged as a warning. The result goes out as one email.
 *
 * Every run sends, including the quiet ones. A report that only arrives when something is
 * wrong cannot be told apart from a job that has stopped running, and a stopped job is the
 * thing nobody notices.
 *
 * Run every 8 hours from cPanel > Cron Jobs:
 *
 *     0 0,8,16 * * *   /usr/local/bin/php /home/resokorg/public_html/resok-portal/public/api/cron/security-digest.php
 *
 * Where the host only offers URL-triggered cron, this also accepts ?key=<cron_secret>.
 *
 * Who receives it: 'security_digest_to' in config.local.php (an address, or a list of them),
 * defaulting to Moses. Set it to '' to stop the emails without removing the cron job.
 * Nothing in the report is a secret: checks say that a setting is wrong, never its value.
 */

$isCli = PHP_SAPI === 'cli';
$config = require __DIR__ . '/../config.php';

foreach (['portal-mail', 'throttle', 'crypto', 'mfa', 'security-assessment'] as $module) {
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

if (!function_exists('securityAssessment') || !class_exists('SimpleMailer')) {
    echo "lib/security-assessment.php or the mailer is not deployed.\n";
    exit(1);
}

const DIGEST_HOURS = 8;
const DIGEST_DEFAULT_TO = 'mosesalembiofficial@gmail.com';

$recipients = $config['security_digest_to'] ?? DIGEST_DEFAULT_TO;
$recipients = array_values(array_filter(array_map('trim', is_array($recipients) ? $recipients : [(string)$recipients]),
    fn($a) => filter_var($a, FILTER_VALIDATE_EMAIL) !== false));
if (!$recipients) {
    echo "security_digest_to is empty; nothing sent.\n";
    exit(0);
}

date_default_timezone_set('Africa/Nairobi');
try {
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $config['db_host'], (int)($config['db_port'] ?? 3306), $config['db_name']);
    $pdo = new PDO($dsn, $config['db_user'], $config['db_pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec("SET time_zone = '+03:00'");
} catch (Throwable $e) {
    // The database being down is itself the most important security news of the period.
    $pdo = null;
    $dbError = $e->getMessage();
}

/** Fetches a URL from outside, as a visitor would: status, bytes of body, and where it redirects. */
function digestProbe(string $url): ?array
{
    if (!function_exists('curl_init')) return null;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_USERAGENT => 'ReSoK-security-digest',
    ]);
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $redirect = (string)curl_getinfo($ch, CURLINFO_REDIRECT_URL);
    curl_close($ch);
    if ($body === false || $status === 0) return null;
    return ['status' => $status, 'bytes' => strlen((string)$body), 'redirect' => $redirect];
}

$base = rtrim((string)($config['portal_base_url'] ?? ''), '/');
$site = $base !== '' ? preg_replace('#/resok-portal/public$#', '', $base) : '';
$checks = [];
$activity = [];

if ($pdo !== null) {
    // The checks were written for a page in a browser. From cron there is no request, so the
    // site root is supplied for the upload check, and the HTTPS check (which asks whether this
    // request arrived over TLS) is replaced below by asking the live site the same question.
    if (empty($_SERVER['DOCUMENT_ROOT'])) $_SERVER['DOCUMENT_ROOT'] = realpath(__DIR__ . '/../../../..') ?: '';
    $assessment = securityAssessment($pdo, $config);
    $checks = array_values(array_filter($assessment['checks'], fn($c) => $c['id'] !== 'https'));
} else {
    $checks[] = securityCheck('database', 'Database', 'fail',
        'The database could not be reached: ' . mb_substr((string)$dbError, 0, 160),
        'Check MySQL in cPanel. Members cannot sign in while this lasts.');
}

if ($site !== '' && preg_match('#^https://#i', $site)) {
    $plain = digestProbe(preg_replace('#^https://#i', 'http://', $site) . '/resok-portal/public/login');
    $checks[] = ($plain !== null && in_array($plain['status'], [301, 302, 307, 308], true) && stripos($plain['redirect'], 'https://') === 0)
        ? securityCheck('https', 'HTTPS', 'pass', 'Checked live: plain http redirects to https.')
        : securityCheck('https', 'HTTPS', 'fail',
            'Checked live: plain http did not redirect to https (got ' . ($plain['status'] ?? 'no answer') . ').',
            'Turn on Force HTTPS Redirect in cPanel > Domains.');

    // Files that must never be served. A 200 with a body is a leak; a 200 with an empty body is
    // a PHP file that ran and printed nothing, which is safe but should still be refused.
    $mustRefuse = [
        '/.git/config', '/.env', '/resok-portal/server/schema.sql', '/resok-portal/uploads/',
        '/private/learning.html', '/tools/security/security_guard.py',
    ];
    $leaks = [];
    foreach ($mustRefuse as $path) {
        $r = digestProbe($site . $path . '?nocache=' . bin2hex(random_bytes(6)));
        if ($r !== null && $r['status'] === 200 && $r['bytes'] > 0) $leaks[] = $path;
    }
    $checks[] = $leaks
        ? securityCheck('exposure', 'Private files', 'fail',
            'Checked live: these answer with their contents: ' . implode(', ', $leaks) . '.',
            'Deploy the current .htaccess and purge the host cache.')
        : securityCheck('exposure', 'Private files', 'pass',
            'Checked live: source control, environment, database schema, uploads and members-only pages are all refused.');
}

if ($pdo !== null) {
    try {
        throttleEnsureTables($pdo);
        $stmt = $pdo->prepare('SELECT event_type, severity, COUNT(*) c FROM security_events
                               WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
                               GROUP BY event_type, severity ORDER BY c DESC');
        $stmt->execute([DIGEST_HOURS]);
        $activity['byType'] = $stmt->fetchAll();
        $stmt = $pdo->prepare("SELECT event_type, severity, action, detail, created_at FROM security_events
                               WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
                                 AND severity IN ('warning', 'critical', 'high', 'error')
                               ORDER BY created_at DESC LIMIT 15");
        $stmt->execute([DIGEST_HOURS]);
        $activity['notable'] = $stmt->fetchAll();
        $activity['lockouts'] = (int)($pdo->query('SELECT COUNT(*) c FROM auth_attempts
                                    WHERE locked_until IS NOT NULL AND locked_until > NOW()')->fetch()['c'] ?? 0);
    } catch (Throwable $e) {
        $activity['error'] = $e->getMessage();
    }

    // Emails that gave up after every retry (30 Oct 2026 audit, item 20). The queue kept
    // them, but nobody was told, so a member could wait for a password link that never came.
    // A week's worth is listed in every digest; they are resent from the Emails tab of
    // abstracts-admin.html. A message still queued after a day means the pump has stopped.
    // A resend is a new row, so a failure that a later copy delivered is left out.
    try {
        $failed = "FROM abs_emails a WHERE a.status = 'failed' AND a.created_at >= (NOW() - INTERVAL 7 DAY)
                     AND NOT EXISTS (SELECT 1 FROM abs_emails b WHERE b.id > a.id AND b.status = 'sent'
                                        AND b.to_email = a.to_email AND b.subject = a.subject)";
        $activity['mailFailed'] = $pdo->query("SELECT a.template, a.to_email, a.error, a.created_at $failed ORDER BY a.id DESC LIMIT 10")->fetchAll();
        $activity['mailFailedCount'] = (int)$pdo->query("SELECT COUNT(*) $failed")->fetchColumn();
        $activity['mailStuck'] = (int)$pdo->query("SELECT COUNT(*) FROM abs_emails
                                                WHERE status IN ('queued','sending') AND created_at < (NOW() - INTERVAL 1 DAY)")->fetchColumn();
    } catch (Throwable $e) {
        // No abs_emails table yet: nothing has been queued, so nothing has failed.
    }
}

$counts = ['pass' => 0, 'warn' => 0, 'fail' => 0];
foreach ($checks as $c) {
    if (isset($counts[$c['status']])) $counts[$c['status']]++;
}
$posture = $counts['fail'] > 0 ? 'AT RISK' : ($counts['warn'] > 0 ? 'Needs attention' : 'All clear');
$when = date('j M Y, H:i');

/* ---- The email ---------------------------------------------------------------------- */

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
$p = '<p style="margin:0 0 12px;font-size:14px;line-height:1.6;">';
$colour = ['fail' => '#b42318', 'warn' => '#b54708', 'pass' => '#087539'];
$label = ['fail' => 'Problem', 'warn' => 'Attention', 'pass' => 'OK'];

$html = $p . 'Overall: <strong style="color:' . ($counts['fail'] ? $colour['fail'] : ($counts['warn'] ? $colour['warn'] : $colour['pass'])) . '">'
    . $h($posture) . '</strong> &middot; ' . $counts['pass'] . ' checks passed, ' . $counts['warn'] . ' need attention, '
    . $counts['fail'] . ' failed.</p>';
$text = "ReSoK security update, {$when}\n\nOverall: {$posture} ({$counts['pass']} passed, {$counts['warn']} need attention, {$counts['fail']} failed)\n\n";

$issues = array_filter($checks, fn($c) => $c['status'] === 'fail' || $c['status'] === 'warn');
usort($issues, fn($a, $b) => ($a['status'] === 'fail' ? 0 : 1) <=> ($b['status'] === 'fail' ? 0 : 1));
if ($issues) {
    $html .= '<h2 style="font-size:16px;margin:20px 0 8px;">What needs doing</h2>';
    $text .= "WHAT NEEDS DOING\n";
    foreach ($issues as $c) {
        $html .= '<div style="margin:0 0 10px;padding:10px 12px;border-left:4px solid ' . $colour[$c['status']] . ';background:#f8faf9;">'
            . '<strong>' . $h($c['title']) . '</strong> <span style="color:' . $colour[$c['status']] . ';font-size:12px;font-weight:700;">'
            . $label[$c['status']] . '</span><br><span style="font-size:13px;">' . $h($c['detail']) . '</span>'
            . ($c['action'] !== '' ? '<br><span style="font-size:13px;color:#475467;">To fix: ' . $h($c['action']) . '</span>' : '')
            . '</div>';
        $text .= "- [{$label[$c['status']]}] {$c['title']}: {$c['detail']}" . ($c['action'] !== '' ? " To fix: {$c['action']}" : '') . "\n";
    }
    $text .= "\n";
}

$html .= '<h2 style="font-size:16px;margin:20px 0 8px;">Activity in the last ' . DIGEST_HOURS . ' hours</h2>';
$text .= 'ACTIVITY IN THE LAST ' . DIGEST_HOURS . " HOURS\n";
if (isset($activity['error'])) {
    $html .= $p . 'The security log could not be read: ' . $h($activity['error']) . '</p>';
    $text .= "The security log could not be read: {$activity['error']}\n";
} elseif ($pdo === null) {
    $html .= $p . 'Not available while the database is down.</p>';
    $text .= "Not available while the database is down.\n";
} else {
    if (!$activity['byType']) {
        $html .= $p . 'Nothing logged: no failed sign-ins, lockouts or resets.</p>';
        $text .= "Nothing logged.\n";
    } else {
        $html .= '<table style="border-collapse:collapse;font-size:13px;margin:0 0 12px;">';
        foreach ($activity['byType'] as $r) {
            $html .= '<tr><td style="padding:3px 14px 3px 0;">' . $h(str_replace('_', ' ', $r['event_type'])) . '</td>'
                . '<td style="padding:3px 14px 3px 0;color:#667085;">' . $h($r['severity']) . '</td>'
                . '<td style="padding:3px 0;font-weight:700;">' . (int)$r['c'] . '</td></tr>';
            $text .= '- ' . str_replace('_', ' ', $r['event_type']) . " ({$r['severity']}): {$r['c']}\n";
        }
        $html .= '</table>';
    }
    $html .= $p . 'Accounts locked out right now: <strong>' . $activity['lockouts'] . '</strong></p>';
    $text .= "Accounts locked out right now: {$activity['lockouts']}\n";
    if ($activity['notable']) {
        $html .= $p . '<strong>Warnings logged</strong></p><ul style="margin:0 0 12px;padding-left:18px;font-size:13px;">';
        $text .= "\nWarnings logged:\n";
        foreach ($activity['notable'] as $r) {
            $line = date('j M H:i', strtotime((string)$r['created_at'])) . ' - ' . str_replace('_', ' ', $r['event_type'])
                . ($r['detail'] ? ': ' . $r['detail'] : '');
            $html .= '<li>' . $h($line) . '</li>';
            $text .= "- {$line}\n";
        }
        $html .= '</ul>';
    }
}

if (!empty($activity['mailFailedCount']) || !empty($activity['mailStuck'])) {
    $html .= '<h2 style="font-size:16px;margin:20px 0 8px;">Emails not delivered</h2>';
    $text .= "\nEMAILS NOT DELIVERED\n";
    if (!empty($activity['mailStuck'])) {
        $line = $activity['mailStuck'] . ' still waiting to send after a day. The mail pump may have stopped.';
        $html .= $p . '<strong style="color:' . $colour['fail'] . '">' . $h($line) . '</strong></p>';
        $text .= "{$line}\n";
    }
    if (!empty($activity['mailFailedCount'])) {
        $line = $activity['mailFailedCount'] . ' gave up after four tries in the last 7 days. Resend from the Emails tab of abstracts-admin.html once the cause is fixed.';
        $html .= $p . $h($line) . '</p><ul style="margin:0 0 12px;padding-left:18px;font-size:13px;">';
        $text .= "{$line}\n";
        foreach ($activity['mailFailed'] as $r) {
            $line = date('j M H:i', strtotime((string)$r['created_at'])) . ' - ' . $r['template'] . ' to ' . $r['to_email']
                . ($r['error'] ? ': ' . $r['error'] : '');
            $html .= '<li>' . $h($line) . '</li>';
            $text .= "- {$line}\n";
        }
        $html .= '</ul>';
    }
}

$html .= '<p style="margin:20px 0 0;font-size:12px;color:#667085;">Sent every ' . DIGEST_HOURS . ' hours by the portal\'s security check, '
    . 'including when everything is fine, so a missing email means the check itself has stopped. '
    . 'Full detail: Admin &rarr; Threat Assessment.</p>';
$text .= "\nSent every " . DIGEST_HOURS . " hours, including when everything is fine, so a missing email means the check has stopped.\n";

$subject = "ReSoK security update: {$posture} ({$when})";
$body = brandedEmailHtml('Security update, ' . $when, $html);

$sentTo = [];
foreach ($recipients as $to) {
    $mailer = new SimpleMailer($config);
    if ($mailer->send($to, $subject, $text, [], $body)) {
        $sentTo[] = $to;
    } else {
        error_log("Security digest to {$to} not sent: " . ($mailer->lastError ?? 'unknown error'));
    }
}

echo "{$posture}: {$counts['pass']} pass, {$counts['warn']} warn, {$counts['fail']} fail. Sent to: "
    . ($sentTo ? implode(', ', $sentTo) : 'nobody') . "\n";
exit($sentTo ? 0 : 1);
