<?php
declare(strict_types=1);

/**
 * Brute-force protection for the login endpoint.
 *
 * Without this, auth/login accepts unlimited password guesses at whatever rate the network
 * allows - the single most valuable thing an attacker can have against a membership portal.
 *
 * Two counters, deliberately:
 *
 *   account - keyed on (email + client), so guessing one member's password locks that
 *             attempt path quickly. Keying on the email ALONE would let anyone lock a
 *             member out of their own account by failing eight logins on their behalf,
 *             which trades a break-in risk for a denial-of-service one.
 *
 *   client  - keyed on the client alone, so spraying one common password across many
 *             different emails is caught too. That is the attack the account counter
 *             cannot see, because each individual email only fails once.
 *
 * No email or IP is stored, only a salted hash of them, so the table cannot be mined if it
 * leaks and holds nothing that needs a retention policy. Attempts are cleared on a
 * successful login, so a member who simply mistyped is not punished afterwards.
 */

const AUTH_MAX_ACCOUNT_FAILURES = 8;      // per email+client within the window
const AUTH_MAX_CLIENT_FAILURES = 25;      // per client across all emails
const AUTH_WINDOW_SECONDS = 900;          // 15 minutes
const AUTH_LOCK_SECONDS = 900;

function authEnsureThrottleTable(PDO $pdo): void
{
    static $done = false;
    if ($done) return;
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS auth_attempts (
            scope_key CHAR(64) NOT NULL,
            failures SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            first_failure_at DATETIME NOT NULL,
            last_failure_at DATETIME NOT NULL,
            locked_until DATETIME NULL,
            PRIMARY KEY (scope_key),
            KEY auth_attempts_locked (locked_until)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
    $done = true;
}

/** Salted so the table cannot be checked against a guessed email or address offline. */
function authScopeKey(array $config, string $scope, string $value): string
{
    return hash_hmac('sha256', $scope . '|' . strtolower(trim($value)), (string)$config['jwt_secret']);
}

function authClientFingerprint(): string
{
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    // A shared paybill office or hospital will NAT many members behind one address, so the
    // user agent is mixed in to avoid one busy site locking out everyone behind it.
    $agent = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 120);
    return $ip . '|' . $agent;
}

/**
 * Refuses the request when either counter is over its limit. Called before the password is
 * checked, so a locked-out attempt costs nothing and reveals nothing.
 */
function authThrottleCheck(PDO $pdo, array $config, string $email): void
{
    authEnsureThrottleTable($pdo);

    // Expired locks and stale windows are cleared here rather than by a cron: the login
    // endpoint is the only thing that reads this table, so it is the natural place.
    $pdo->prepare('DELETE FROM auth_attempts WHERE last_failure_at < DATE_SUB(NOW(), INTERVAL ? SECOND)
                   AND (locked_until IS NULL OR locked_until < NOW())')
        ->execute([AUTH_WINDOW_SECONDS]);

    $keys = [
        authScopeKey($config, 'account', $email . '|' . authClientFingerprint()),
        authScopeKey($config, 'client', authClientFingerprint()),
    ];
    $stmt = $pdo->prepare('SELECT scope_key, locked_until FROM auth_attempts
                            WHERE scope_key IN (?, ?) AND locked_until IS NOT NULL AND locked_until > NOW()');
    $stmt->execute($keys);
    if ($row = $stmt->fetch()) {
        $seconds = max(1, strtotime((string)$row['locked_until']) - time());
        $minutes = (int)ceil($seconds / 60);
        header('Retry-After: ' . $seconds);
        respond(429, [
            'error' => "Too many failed login attempts. Please try again in {$minutes} minute"
                . ($minutes === 1 ? '' : 's') . '.',
            'reason' => 'rate_limited',
        ]);
    }
}

function authThrottleFailure(PDO $pdo, array $config, string $email): void
{
    authEnsureThrottleTable($pdo);
    $client = authClientFingerprint();
    foreach ([
        ['account', $email . '|' . $client, AUTH_MAX_ACCOUNT_FAILURES],
        ['client', $client, AUTH_MAX_CLIENT_FAILURES],
    ] as [$scope, $value, $limit]) {
        $key = authScopeKey($config, $scope, $value);
        $pdo->prepare(
            'INSERT INTO auth_attempts (scope_key, failures, first_failure_at, last_failure_at)
             VALUES (?, 1, NOW(), NOW())
             ON DUPLICATE KEY UPDATE
               failures = IF(first_failure_at < DATE_SUB(NOW(), INTERVAL ? SECOND), 1, failures + 1),
               first_failure_at = IF(first_failure_at < DATE_SUB(NOW(), INTERVAL ? SECOND), NOW(), first_failure_at),
               last_failure_at = NOW()'
        )->execute([$key, AUTH_WINDOW_SECONDS, AUTH_WINDOW_SECONDS]);

        $pdo->prepare('UPDATE auth_attempts SET locked_until = DATE_ADD(NOW(), INTERVAL ? SECOND)
                        WHERE scope_key = ? AND failures >= ?')
            ->execute([AUTH_LOCK_SECONDS, $key, $limit]);
    }
}

/** A correct password clears the account counter; the client counter stays, so a spray is
 *  not reset by happening to guess one account correctly. */
function authThrottleSuccess(PDO $pdo, array $config, string $email): void
{
    authEnsureThrottleTable($pdo);
    $pdo->prepare('DELETE FROM auth_attempts WHERE scope_key = ?')
        ->execute([authScopeKey($config, 'account', $email . '|' . authClientFingerprint())]);
}
