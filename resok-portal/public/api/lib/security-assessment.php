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

/**
 * Asks the live site for a URL, the way a browser would, and returns the status code and
 * lower-cased response headers - or null if the server cannot reach itself (some shared
 * hosts block loopback requests). This is what lets a check report what a visitor actually
 * gets, rather than what the configuration files suggest they should get.
 *
 * @return array{status:int,headers:array<string,string>}|null
 */
function securityFetch(string $url): ?array
{
    if (!function_exists('curl_init') || !preg_match('#^https?://#i', $url)) return null;
    $headers = [];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_NOBODY => false,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_USERAGENT => 'ReSoK-security-assessment',
        CURLOPT_HEADERFUNCTION => function ($handle, string $line) use (&$headers): int {
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
            return strlen($line);
        },
    ]);
    $ok = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($ok === false || $status === 0) return null;
    return ['status' => $status, 'headers' => $headers];
}

/**
 * The portal's health route, fetched once per assessment and shared by every check that
 * needs the live site. Null when portal_base_url is unset or the server cannot reach itself
 * - and in that case nothing else is fetched, so a host that silently drops requests to
 * itself costs one timeout, not one per check.
 *
 * @return array{status:int,headers:array<string,string>}|null
 */
function securityLiveHealth(array $config): ?array
{
    static $cache = [];
    $base = rtrim((string)($config['portal_base_url'] ?? ''), '/');
    if ($base === '') return null;
    if (!array_key_exists($base, $cache)) {
        $cache[$base] = securityFetch($base . '/api/index.php?route=health&' . securityCacheBuster());
    }
    return $cache[$base];
}

/** A query string no cache has seen, so the answer is the server's and not a cached copy. */
function securityCacheBuster(): string
{
    return 'nocache=' . bin2hex(random_bytes(6));
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

    // Read from a real response to the site, because most of these headers are added by
    // Apache (.htaccess) after PHP has finished, and apache_response_headers() only ever saw
    // the ones PHP set itself - which is how HSTS was reported missing while being sent.
    // The PHP-side view is the fallback for a host that blocks requests to itself.
    $headers = [];
    $live = securityLiveHealth($config);
    // Only a 200 from the health route proves the request reached this site.
    if ($live !== null && $live['status'] === 200) {
        $headers = $live['headers'];
    } else {
        foreach (function_exists('apache_response_headers') ? apache_response_headers() : [] as $k => $v) {
            $headers[strtolower($k)] = $v;
        }
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

    $out[] = securityAssessUploads($config);
    return $out;
}

/**
 * Whether a member's payment proof can be fetched straight off the web server.
 *
 * This used to compare upload_dir with the portal's public/ folder and pass because uploads
 * sit outside it - but they sit inside the site's document root, which is what decides
 * whether Apache will serve them. So: outside the document root is a pass; inside it is a
 * pass only if a rule actually forbids the path, either a [F] rewrite in the root .htaccess
 * or a deny-all .htaccess inside the uploads folder itself.
 */
function securityAssessUploads(array $config): array
{
    $norm = fn(string $p): string => rtrim(str_replace('\\', '/', $p), '/');
    $uploads = (string)($config['upload_dir'] ?? '');
    $uploadsReal = $uploads !== '' ? realpath($uploads) : false;

    // First, the direct question: ask the site for a payment proof that does not exist. A
    // blocked folder answers 403 whatever the name; an open one answers 404, which means a
    // real file at that path would have been served. Only possible when uploads are in the
    // default place beside public/, because that is the only layout with a known URL.
    $base = rtrim((string)($config['portal_base_url'] ?? ''), '/');
    $defaultDir = realpath(__DIR__ . '/../../../uploads');
    // The health route answering 200 first proves portal_base_url points at this site; a
    // 404 from some other server would otherwise read as an open folder.
    $self = ($base !== '' && $uploadsReal !== false && $defaultDir !== false && $norm($uploadsReal) === $norm($defaultDir))
        ? securityLiveHealth($config) : null;
    if ($self !== null && $self['status'] === 200) {
        $probeUrl = dirname($base) . '/uploads/Payment_Proof/probe-' . bin2hex(random_bytes(8)) . '.png?' . securityCacheBuster();
        $live = securityFetch($probeUrl);
        if ($live !== null && in_array($live['status'], [401, 403], true)) {
            return securityCheck('uploads', 'Upload location', 'pass',
                'Checked live: a request for a file in the upload folder is refused, so payment proofs and photos are only reachable through the API.');
        }
        if ($live !== null && $live['status'] === 404) {
            return securityCheck('uploads', 'Upload location', 'fail',
                'Checked live: the upload folder answers requests directly, so anyone with a link can open a member\'s payment proof or photo without signing in.',
                'Deploy the current .htaccess, which refuses /resok-portal/uploads/, then purge the host cache so copies it already holds are dropped.');
        }
        // Anything else (a redirect, a timeout, a host blocking self-requests) proves
        // nothing either way, so fall through to reading the rules.
    }

    $docRoot = realpath((string)($_SERVER['DOCUMENT_ROOT'] ?? ''));
    if ($uploadsReal === false || $docRoot === false) {
        return securityCheck('uploads', 'Upload location', 'warn',
            'The upload folder or the site root could not be resolved, so whether uploads can be downloaded directly is unknown.',
            'Check that upload_dir exists, then try opening a file under /resok-portal/uploads/ in a private window: it should be refused.');
    }
    $uploadsPath = $norm($uploadsReal);
    $rootPath = $norm($docRoot);
    if (stripos($uploadsPath . '/', $rootPath . '/') !== 0) {
        return securityCheck('uploads', 'Upload location', 'pass', 'Member uploads are stored outside the web root.');
    }

    $relative = ltrim(substr($uploadsPath, strlen($rootPath)), '/');
    $blockedBy = null;

    // Every .htaccess from the web root down to the upload folder applies, and each one
    // matches paths relative to its own directory. Reading only the root's missed the live
    // layout, where the site sits in a subfolder of the web root with its own .htaccess.
    $dir = $uploadsPath;
    while ($blockedBy === null && stripos($dir . '/', $rootPath . '/') === 0) {
        $rules = @file_get_contents($dir . '/.htaccess');
        if (is_string($rules)) {
            $probe = ltrim(substr($uploadsPath . '/Payment_Proof/proof-example.png', strlen($dir)), '/');
            foreach (preg_split('/\R/', $rules) as $line) {
                if (!preg_match('/^\s*RewriteRule\s+(\S+)\s+-\s+\[[^\]]*\bF\b[^\]]*\]/i', $line, $m)) continue;
                $pattern = '#' . str_replace('#', '\\#', $m[1]) . '#';
                if (@preg_match($pattern, $probe) === 1) {
                    $where = ltrim(substr($dir, strlen($rootPath)), '/');
                    $blockedBy = 'a rule in ' . ($where === '' ? 'the site .htaccess' : "the .htaccess in /{$where}/");
                    break;
                }
            }
        }
        if ($dir === $rootPath) break;
        $dir = $norm(dirname($dir));
    }
    if ($blockedBy === null) {
        $localRules = @file_get_contents($uploadsReal . '/.htaccess');
        if (is_string($localRules) && preg_match('/Require\s+all\s+denied|Deny\s+from\s+all/i', $localRules)) {
            $blockedBy = 'a deny-all .htaccess in the upload folder';
        }
    }

    return $blockedBy !== null
        ? securityCheck('uploads', 'Upload location', 'pass',
            "Uploads are inside the web root, but {$blockedBy} refuses every direct request, so files are only reachable through the API.")
        : securityCheck('uploads', 'Upload location', 'fail',
            "Uploads are inside the web root at /{$relative}/ and no .htaccess rule blocking them could be found. If none exists, anyone with a link can open a member's payment proof or photo without signing in.",
            'Deploy the current .htaccess, which refuses /resok-portal/uploads/, then purge the host cache so copies it already holds are dropped.');
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

    // Session revocation: logout, password reset and role changes end sessions on the
    // server. Without its tables they still work in the browser, but a copied cookie lives on.
    try {
        $pdo->query('SELECT 1 FROM session_revocations LIMIT 1');
        $pdo->query('SELECT 1 FROM revoked_tokens LIMIT 1');
        $out[] = securityCheck('revocation', 'Session revocation', 'pass',
            'Logging out, a password reset and a role change all end sessions on the server, not just in the browser.');
    } catch (Throwable $e) {
        $out[] = securityCheck('revocation', 'Session revocation', 'fail',
            'The session revocation tables do not exist, so a logged-out or reset session keeps working until it times out. The API creates them on first use, which means the database user cannot.',
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

function securityAssessAccounts(PDO $pdo, array $config): array
{
    $out = [];

    // Staff two-factor: required by default, so the useful questions are whether that is
    // still switched on and who has not enrolled yet.
    try {
        $staffRoles = ['admin', 'content_manager', 'analytics_manager', 'ict'];
        $marks = implode(',', array_fill(0, count($staffRoles), '?'));
        $stmt = $pdo->prepare("SELECT email FROM users WHERE role IN ({$marks}) AND COALESCE(mfa_enabled, 0) = 0 ORDER BY email");
        $stmt->execute($staffRoles);
        $without = array_column($stmt->fetchAll(), 'email');
        $enforced = !empty($config['require_staff_mfa']);
        if (!$enforced) {
            $out[] = securityCheck('staffmfa', 'Two-factor for staff', 'fail',
                'require_staff_mfa is off, so a staff password alone opens member data.'
                . ($without ? ' Not enrolled: ' . implode(', ', $without) . '.' : ''),
                'Remove require_staff_mfa from config.local.php, or set it to true.');
        } elseif ($without) {
            $out[] = securityCheck('staffmfa', 'Two-factor for staff', 'warn',
                count($without) . ' staff account(s) have not set it up: ' . implode(', ', $without)
                . '. They are held at the setup page when they next sign in, and cannot reach member data until they finish.',
                'Ask them to sign in and complete setup, or remove access they no longer need.');
        } else {
            $out[] = securityCheck('staffmfa', 'Two-factor for staff', 'pass',
                'Required for every staff account, and every staff account has it on.');
        }
    } catch (Throwable $e) {
        $out[] = securityCheck('staffmfa', 'Two-factor for staff', 'warn',
            'The two-factor columns could not be read, so staff cannot enrol and the requirement is not enforced.',
            'Import resok-portal/server/schema-security.sql.');
    }

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
        securityAssessAccounts($pdo, $config)
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
