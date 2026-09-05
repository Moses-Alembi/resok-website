<?php
declare(strict_types=1);

/**
 * Abuse protection: rate limiting for any endpoint, and the security event log that the
 * admin threat assessment reads.
 *
 * Every limited action uses the same two-counter shape, because one counter is never
 * enough:
 *
 *   subject - keyed on what is being attacked (an email, a member id) plus the client, so
 *             hammering one target is stopped quickly. Keyed on the target ALONE, anyone
 *             could lock a member out of their own account by failing logins on their
 *             behalf - trading a break-in risk for a denial-of-service one.
 *
 *   client  - keyed on the client alone, catching the attack the first cannot see: one
 *             password sprayed across many emails, where no single account fails twice.
 *
 * Nothing identifying is stored. Both keys are salted HMACs of the email and client, so the
 * tables cannot be mined if they leak and hold nothing that needs a retention policy.
 *
 * The client fingerprint mixes in the user agent because a hospital or a shared office will
 * NAT many legitimate members behind one address, and an IP-only counter locks them out
 * together.
 */

// Per-action limits: [subject failures, client failures, window seconds, lock seconds].
// Login is tightest. Registration and password reset are looser but still bounded, since
// both send email and are therefore also a way to use the site as a spam relay.
const THROTTLE_RULES = [
    'login'          => [8,  25, 900, 900],
    'register'       => [5,  15, 3600, 1800],
    'password-reset' => [5,  15, 3600, 1800],
    'payment'        => [10, 30, 3600, 900],
    'upload'         => [20, 60, 3600, 900],
];
const THROTTLE_DEFAULT = [15, 40, 900, 900];

function throttleEnsureTables(PDO $pdo): void
{
    static $done = false;
    if ($done) return;
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS auth_attempts (
            scope_key CHAR(64) NOT NULL,
            action VARCHAR(40) NOT NULL DEFAULT "login",
            failures SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            first_failure_at DATETIME NOT NULL,
            last_failure_at DATETIME NOT NULL,
            locked_until DATETIME NULL,
            PRIMARY KEY (scope_key),
            KEY auth_attempts_locked (locked_until),
            KEY auth_attempts_action (action, last_failure_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
    // The event log is what the admin threat assessment reads. It records that something
    // happened and roughly where, never who: an event carries a client hash, not an address.
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS security_events (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            event_type VARCHAR(40) NOT NULL,
            action VARCHAR(40) NULL,
            severity ENUM("info","warning","critical") NOT NULL DEFAULT "info",
            client_hash CHAR(64) NULL,
            user_id INT UNSIGNED NULL,
            detail VARCHAR(300) NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY security_events_time (created_at),
            KEY security_events_type (event_type, created_at),
            KEY security_events_severity (severity, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
    $done = true;
}

function throttleScopeKey(array $config, string $action, string $scope, string $value): string
{
    return hash_hmac('sha256', $action . '|' . $scope . '|' . strtolower(trim($value)), (string)$config['jwt_secret']);
}

function throttleClientFingerprint(): string
{
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $agent = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 120);
    return $ip . '|' . $agent;
}

function throttleClientHash(array $config): string
{
    return throttleScopeKey($config, 'client', 'fingerprint', throttleClientFingerprint());
}

/** Writes one line to the security log. Never throws - logging must not break a request. */
function securityLog(PDO $pdo, array $config, string $type, string $severity = 'info',
                     ?string $action = null, ?string $detail = null, ?int $userId = null): void
{
    try {
        throttleEnsureTables($pdo);
        $pdo->prepare('INSERT INTO security_events (event_type, action, severity, client_hash, user_id, detail, created_at)
                       VALUES (?, ?, ?, ?, ?, ?, NOW())')
            ->execute([$type, $action, $severity, throttleClientHash($config), $userId,
                       $detail !== null ? mb_substr($detail, 0, 300) : null]);
    } catch (Throwable $e) {
        error_log('securityLog failed: ' . $e->getMessage());
    }
}

/**
 * Refuses the request when either counter is over its limit. Call before doing the work,
 * so a blocked attempt costs nothing and reveals nothing about whether the target exists.
 */
function throttleCheck(PDO $pdo, array $config, string $action, string $subject): void
{
    throttleEnsureTables($pdo);
    [, , $window, ] = THROTTLE_RULES[$action] ?? THROTTLE_DEFAULT;

    // Stale rows are cleared here rather than by a cron: these endpoints are the only
    // things that read the table, so they are the natural place to tidy it.
    $pdo->prepare('DELETE FROM auth_attempts WHERE last_failure_at < DATE_SUB(NOW(), INTERVAL ? SECOND)
                   AND (locked_until IS NULL OR locked_until < NOW())')->execute([$window]);

    $client = throttleClientFingerprint();
    $keys = [
        throttleScopeKey($config, $action, 'subject', $subject . '|' . $client),
        throttleScopeKey($config, $action, 'client', $client),
    ];
    $stmt = $pdo->prepare('SELECT locked_until FROM auth_attempts
                            WHERE scope_key IN (?, ?) AND locked_until IS NOT NULL AND locked_until > NOW()
                            ORDER BY locked_until DESC LIMIT 1');
    $stmt->execute($keys);
    if ($row = $stmt->fetch()) {
        $seconds = max(1, strtotime((string)$row['locked_until']) - time());
        $minutes = (int)ceil($seconds / 60);
        securityLog($pdo, $config, 'rate_limit_blocked', 'warning', $action, 'Request refused while locked out');
        header('Retry-After: ' . $seconds);
        respond(429, [
            'error' => "Too many attempts. Please try again in {$minutes} minute" . ($minutes === 1 ? '' : 's') . '.',
            'reason' => 'rate_limited',
        ]);
    }
}

function throttleFailure(PDO $pdo, array $config, string $action, string $subject): void
{
    throttleEnsureTables($pdo);
    [$subjectLimit, $clientLimit, $window, $lock] = THROTTLE_RULES[$action] ?? THROTTLE_DEFAULT;
    $client = throttleClientFingerprint();

    foreach ([['subject', $subject . '|' . $client, $subjectLimit], ['client', $client, $clientLimit]] as [$scope, $value, $limit]) {
        $key = throttleScopeKey($config, $action, $scope, $value);
        $pdo->prepare(
            'INSERT INTO auth_attempts (scope_key, action, failures, first_failure_at, last_failure_at)
             VALUES (?, ?, 1, NOW(), NOW())
             ON DUPLICATE KEY UPDATE
               failures = IF(first_failure_at < DATE_SUB(NOW(), INTERVAL ? SECOND), 1, failures + 1),
               first_failure_at = IF(first_failure_at < DATE_SUB(NOW(), INTERVAL ? SECOND), NOW(), first_failure_at),
               last_failure_at = NOW()'
        )->execute([$key, $action, $window, $window]);

        $locked = $pdo->prepare('UPDATE auth_attempts SET locked_until = DATE_ADD(NOW(), INTERVAL ? SECOND)
                                  WHERE scope_key = ? AND failures >= ? AND (locked_until IS NULL OR locked_until < NOW())');
        $locked->execute([$lock, $key, $limit]);
        if ($locked->rowCount() > 0) {
            securityLog($pdo, $config, 'lockout', $scope === 'client' ? 'critical' : 'warning', $action,
                $scope === 'client'
                    ? 'Client locked out after repeated failures across multiple targets'
                    : 'Target locked out after repeated failures');
        }
    }
    securityLog($pdo, $config, 'failed_attempt', 'info', $action);
}

/** A success clears the subject counter. The client counter survives, so a spray is not
 *  reset by happening to guess one account correctly. */
function throttleSuccess(PDO $pdo, array $config, string $action, string $subject): void
{
    throttleEnsureTables($pdo);
    $pdo->prepare('DELETE FROM auth_attempts WHERE scope_key = ?')
        ->execute([throttleScopeKey($config, $action, 'subject', $subject . '|' . throttleClientFingerprint())]);
}

/* Names kept from when this only guarded login, so the login route reads plainly. */
function authThrottleCheck(PDO $pdo, array $config, string $email): void
{
    throttleCheck($pdo, $config, 'login', $email);
}

function authThrottleFailure(PDO $pdo, array $config, string $email): void
{
    throttleFailure($pdo, $config, 'login', $email);
}

function authThrottleSuccess(PDO $pdo, array $config, string $email): void
{
    throttleSuccess($pdo, $config, 'login', $email);
    securityLog($pdo, $config, 'login_success', 'info', 'login');
}
