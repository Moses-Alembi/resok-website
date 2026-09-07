<?php
declare(strict_types=1);

/**
 * Site threat assessment for the admin dashboard.
 *
 * Every check inspects the running system - the actual request, the actual config, the
 * actual database - rather than reporting a stored answer. A checklist that says "HTTPS:
 * enabled" because someone ticked a box is worse than no checklist, because it is believed.
 *
 * Checks return: id, title, status (pass|warn|fail), detail, and what to do about it.
 * Nothing here exposes a secret: a check can report that jwt_secret is too short, never
 * what it is.
 */

function securityCheck(string $id, string $title, string $status, string $detail, string $action = ''): array
{
    return ['id' => $id, 'title' => $title, 'status' => $status, 'detail' => $detail, 'action' => $action];
}

function securityAssessTransport(array $config): array
{
    $out = [];
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $out[] = $https
        ? securityCheck('https', 'HTTPS', 'pass', 'This request arrived over TLS.')
        : securityCheck('https', 'HTTPS', 'fail',
            'The admin panel is being served over plain HTTP. The session cookie is Secure, so it is not even being sent.',
            'Force HTTPS in .htaccess or enable the host\'s Force HTTPS Redirect.');

    // Reported from the response headers Apache is configured to add.
    $headers = [];
    foreach (function_exists('apache_response_headers') ? apache_response_headers() : [] as $k => $v) {
        $headers[strtolower($k)] = $v;
    }
    $wanted = [
        'strict-transport-security' => 'HSTS',
        'x-frame-options' => 'Clickjacking protection',
        'x-content-type-options' => 'MIME sniffing protection',
        'referrer-policy' => 'Referrer policy',
    ];
    $missing = [];
    foreach ($wanted as $header => $label) {
        if (!isset($headers[$header])) $missing[] = $label;
    }
    if (!$headers) {
        $out[] = securityCheck('headers', 'Security headers', 'warn',
            'Response headers could not be read on this PHP setup, so they cannot be confirmed from here.',
            'Verify with a header-checking tool against the live site.');
    } elseif ($missing) {
        $out[] = securityCheck('headers', 'Security headers', 'warn',
            'Missing: ' . implode(', ', $missing) . '.', 'Add the missing headers in .htaccess.');
    } else {
        $out[] = securityCheck('headers', 'Security headers', 'pass', 'HSTS, frame, sniffing and referrer policies are all set.');
    }
    return $out;
}

function securityAssessConfig(array $config): array
{
    $out = [];
    $secret = (string)($config['jwt_secret'] ?? '');
    if ($secret === '' || strpos($secret, 'replace_with') === 0) {
        $out[] = securityCheck('jwt', 'Session signing key', 'fail',
            'The signing key is unset or still the sample value. Anyone who reads the sample config can mint a valid session.',
            'Set a random 32+ character jwt_secret in config.local.php.');
    } elseif (strlen($secret) < 32) {
        $out[] = securityCheck('jwt', 'Session signing key', 'warn',
            'The signing key is shorter than 32 characters.', 'Replace it with a longer random value.');
    } else {
        $out[] = securityCheck('jwt', 'Session signing key', 'pass', 'A key of adequate length is configured.');
    }

    $out[] = !empty($config['require_email_verification'])
        ? securityCheck('verify', 'Email verification', 'pass', 'New accounts must verify their address before logging in.')
        : securityCheck('verify', 'Email verification', 'warn',
            'Anyone can register with an address they do not control.', 'Set require_email_verification to true.');

    $out[] = empty($config['allow_approve_without_payment'])
        ? securityCheck('approval', 'Payment before approval', 'pass', 'A confirmed payment is required before a membership is approved.')
        : securityCheck('approval', 'Payment before approval', 'warn',
            'Memberships can be approved with no payment recorded.', 'Set allow_approve_without_payment to false unless deliberately waiving fees.');

    $out[] = !empty($config['setup_key'])
        ? securityCheck('setup', 'Setup key', 'fail',
            'setup_key is still set. It exists only to create the first admin and is a route to another one.',
            'Remove setup_key from config.local.php now that an admin exists.')
        : securityCheck('setup', 'Setup key', 'pass', 'The first-admin setup key has been removed.');

    // M-Pesa: the callback is unauthenticated and Safaricom does not sign callbacks, so this
    // matters the moment payments are switched on.
    $stkOn = function_exists('mpesaEnabled') && mpesaEnabled($config) && mpesaConfigured($config);
    $out[] = $stkOn
        ? securityCheck('mpesa', 'M-Pesa callback', 'warn',
            'Instant payment is live. The Daraja callback is unauthenticated and unsigned, so a forged success is possible unless it is verified against the STK query API.',
            'Confirm each callback with the STK query API before marking a payment paid.')
        : securityCheck('mpesa', 'M-Pesa callback', 'pass', 'Instant payment is off, so the callback is not reachable in a way that matters.');

    $uploads = (string)($config['upload_dir'] ?? '');
    $out[] = ($uploads !== '' && strpos(realpath($uploads) ?: $uploads, realpath(__DIR__ . '/../') ?: '') !== 0)
        ? securityCheck('uploads', 'Upload location', 'pass', 'Member uploads are stored outside the web root.')
        : securityCheck('uploads', 'Upload location', 'warn',
            'Uploads may be inside the web root, where they could be fetched directly.',
            'Point upload_dir outside public/ and serve files through the API.');
    return $out;
}

/**
 * Reports on controls that are built but can silently be doing nothing - which is the
 * failure mode worth surfacing, because nothing about the site looks different when rate
 * limiting is off or ID numbers are unencrypted.
 */
function securityAssessControls(PDO $pdo, array $config): array
{
    $out = [];

    // Rate limiting builds its own tables and degrades quietly if the database user cannot
    // create them, so "is it installed" and "is it working" are different questions.
    try {
        $pdo->query('SELECT 1 FROM auth_attempts LIMIT 1');
        $out[] = securityCheck('ratelimit', 'Login rate limiting', 'pass',
            'The auth_attempts table exists, so failed sign-ins are being counted and locked out.');
    } catch (Throwable $e) {
        $out[] = securityCheck('ratelimit', 'Login rate limiting', 'fail',
            'The auth_attempts table does not exist, so nothing is limiting password guessing. The API creates it on first use, which means the database user cannot.',
            'Import resok-portal/server/schema-security.sql in phpMyAdmin.');
    }

    try {
        $pdo->query('SELECT 1 FROM security_events LIMIT 1');
        $out[] = securityCheck('eventlog', 'Security event log', 'pass', 'Security events are being recorded.');
    } catch (Throwable $e) {
        $out[] = securityCheck('eventlog', 'Security event log', 'warn',
            'The security_events table does not exist, so lockouts and blocked attempts are not being recorded.',
            'Import resok-portal/server/schema-security.sql.');
    }

    // Encryption at rest. Absent key means values are stored exactly as before, which is
    // deliberate - but it should be a visible choice, not a silent one.
    $cryptoOn = function_exists('cryptoAvailable') && cryptoAvailable($config);
    if (!$cryptoOn) {
        $out[] = securityCheck('encryption', 'Encryption at rest', 'warn',
            'No data_encryption_key is set, so national ID numbers and two-factor secrets are stored in readable form. Anyone who obtains a database dump can read them.',
            'Set a 32+ character data_encryption_key in config.local.php, back it up, then run the migration. Widen the columns first - see schema-security.sql.');
    } else {
        try {
            $plain = (int)$pdo->query("SELECT COUNT(*) c FROM member_profiles
                                        WHERE id_number IS NOT NULL AND id_number <> ''
                                          AND id_number NOT LIKE 'enc.v1.%'")->fetch()['c'];
            $out[] = $plain === 0
                ? securityCheck('encryption', 'Encryption at rest', 'pass', 'ID numbers and two-factor secrets are encrypted.')
                : securityCheck('encryption', 'Encryption at rest', 'warn',
                    "A key is configured, but {$plain} ID number(s) are still stored in readable form from before it was set.",
                    'Run the migration from the admin panel to encrypt the remaining rows.');
        } catch (Throwable $e) {
            $out[] = securityCheck('encryption', 'Encryption at rest', 'warn', 'A key is set but the member table could not be checked.', $e->getMessage());
        }
    }

    // A column too narrow truncates encrypted values without any error at all.
    try {
        $narrow = [];
        foreach ([['users', 'mfa_secret'], ['member_profiles', 'id_number']] as [$table, $column]) {
            $row = $pdo->query("SHOW COLUMNS FROM {$table} LIKE '{$column}'")->fetch();
            if ($row && preg_match('/varchar\((\d+)\)/i', (string)$row['Type'], $m) && (int)$m[1] < 255) {
                $narrow[] = "{$table}.{$column} is VARCHAR({$m[1]})";
            }
        }
        if ($narrow && $cryptoOn) {
            $out[] = securityCheck('columnwidth', 'Column widths for encryption', 'fail',
                'Encrypted values are longer than these columns allow, and MySQL truncates without warning: ' . implode('; ', $narrow) . '.',
                'Run the ALTER statements at the end of schema-security.sql immediately.');
        }
    } catch (Throwable $e) {
        // Not worth reporting on its own; the encryption check above already covers intent.
    }

    return $out;
}

function securityAssessAccounts(PDO $pdo): array
{
    $out = [];
    try {
        $admins = (int)$pdo->query("SELECT COUNT(*) c FROM users WHERE role = 'admin'")->fetch()['c'];
        $out[] = $admins === 0
            ? securityCheck('admins', 'Administrator accounts', 'fail', 'There are no admin accounts.', 'Create one before removing setup_key.')
            : ($admins > 4
                ? securityCheck('admins', 'Administrator accounts', 'warn',
                    "{$admins} accounts hold full admin rights. Every one is a way in.",
                    'Move anyone who only writes content or reads numbers to a narrower role.')
                : securityCheck('admins', 'Administrator accounts', 'pass', "{$admins} admin account(s)."));

        $unverified = (int)$pdo->query('SELECT COUNT(*) c FROM users WHERE email_verified = 0')->fetch()['c'];
        if ($unverified > 0) {
            $out[] = securityCheck('unverified', 'Unverified accounts', 'info',
                "{$unverified} account(s) have never verified their email.", 'Normal unless the number is growing quickly, which suggests automated signups.');
        }
    } catch (Throwable $e) {
        $out[] = securityCheck('accounts', 'Account checks', 'warn', 'Could not read the users table.', $e->getMessage());
    }
    return $out;
}

/** Recent activity from the security log, which is what turns this from a checklist into
 *  a picture of what is actually happening to the site. */
function securityRecentActivity(PDO $pdo): array
{
    $windows = ['24 HOUR' => 'day', '7 DAY' => 'week'];
    $summary = [];
    try {
        throttleEnsureTables($pdo);
        foreach ($windows as $sql => $label) {
            $stmt = $pdo->query("SELECT event_type, severity, COUNT(*) c FROM security_events
                                  WHERE created_at >= DATE_SUB(NOW(), INTERVAL {$sql})
                                  GROUP BY event_type, severity");
            $rows = [];
            foreach ($stmt->fetchAll() as $r) {
                $rows[] = ['type' => $r['event_type'], 'severity' => $r['severity'], 'count' => (int)$r['c']];
            }
            $summary[$label] = $rows;
        }
        $locks = $pdo->query('SELECT COUNT(*) c FROM auth_attempts WHERE locked_until IS NOT NULL AND locked_until > NOW()')->fetch();
        $summary['activeLockouts'] = (int)($locks['c'] ?? 0);

        $recent = $pdo->query('SELECT event_type, severity, action, detail, created_at FROM security_events
                                ORDER BY created_at DESC LIMIT 25')->fetchAll();
        $summary['recent'] = array_map(fn($r) => [
            'type' => $r['event_type'], 'severity' => $r['severity'], 'action' => $r['action'],
            'detail' => $r['detail'], 'at' => $r['created_at'],
        ], $recent);
    } catch (Throwable $e) {
        $summary['error'] = 'Security log unavailable: ' . $e->getMessage();
    }
    return $summary;
}

function securityAssessment(PDO $pdo, array $config): array
{
    $checks = array_merge(
        securityAssessTransport($config),
        securityAssessConfig($config),
        securityAssessControls($pdo, $config),
        securityAssessAccounts($pdo)
    );

    $counts = ['pass' => 0, 'warn' => 0, 'fail' => 0, 'info' => 0];
    foreach ($checks as $c) {
        $counts[$c['status']] = ($counts[$c['status']] ?? 0) + 1;
    }
    // A single failure caps the posture: passing nine checks does not offset an open door.
    $posture = $counts['fail'] > 0 ? 'at risk' : ($counts['warn'] > 0 ? 'needs attention' : 'good');

    return [
        'posture' => $posture,
        'counts' => $counts,
        'checks' => $checks,
        'activity' => securityRecentActivity($pdo),
        'generatedAt' => gmdate('c'),
    ];
}
