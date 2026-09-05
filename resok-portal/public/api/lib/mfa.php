<?php
declare(strict_types=1);

/**
 * Two-factor authentication (TOTP, RFC 6238) for portal accounts.
 *
 * Modelled on the control US federal agencies are required to run under OMB M-22-09: a
 * password alone must not be enough to reach anything that matters. On this site the thing
 * that matters is the admin panel - one stolen or reused admin password otherwise exposes
 * every member's name, address, phone number and payment history at once.
 *
 * TOTP rather than SMS deliberately. NIST SP 800-63B restricts SMS as an authenticator
 * because it can be intercepted by SIM swap, which is not a theoretical attack in Kenya.
 * An authenticator app needs no network, no airtime, and no telco cooperation.
 *
 * Implemented here rather than pulled in: TOTP is an HMAC, a truncation and a base32
 * alphabet, and a dependency for that is more supply chain than it is worth.
 *
 * What is stored: the shared secret, and bcrypt hashes of single-use recovery codes. The
 * secret must be readable to verify a code, so it is protected by database access rather
 * than by hashing - which is why the assessment page reports on database exposure too.
 */

const MFA_DIGITS = 6;
const MFA_PERIOD = 30;
// One step either side of now, to tolerate the clock drift a phone accumulates. Wider than
// this starts to matter: each extra step is another valid code at any moment.
const MFA_WINDOW = 1;
const MFA_CHALLENGE_SECONDS = 300;
const MFA_RECOVERY_CODES = 8;

/**
 * Adds the columns on first use, returning false if it cannot - adding them at request time
 * needs ALTER privilege, which shared hosting often withholds. Callers treat false as "not
 * available": no member can have enrolled without the columns, so nobody is let past a
 * second factor they were relying on.
 */
function mfaEnsureColumns(PDO $pdo): bool
{
    static $state = null;
    if ($state !== null) return $state;
    try {
        mfaAddColumns($pdo);
        $state = true;
    } catch (Throwable $e) {
        error_log('Two-factor unavailable - could not add its columns: ' . $e->getMessage());
        $state = false;
    }
    return $state;
}

function mfaAddColumns(PDO $pdo): void
{
    $columns = [];
    foreach ($pdo->query('SHOW COLUMNS FROM users')->fetchAll() as $column) {
        $columns[$column['Field']] = true;
    }
    $required = [
        'mfa_secret' => 'ALTER TABLE users ADD COLUMN mfa_secret VARCHAR(64) NULL',
        'mfa_enabled' => 'ALTER TABLE users ADD COLUMN mfa_enabled TINYINT(1) NOT NULL DEFAULT 0',
        'mfa_enrolled_at' => 'ALTER TABLE users ADD COLUMN mfa_enrolled_at DATETIME NULL',
        'mfa_recovery' => 'ALTER TABLE users ADD COLUMN mfa_recovery TEXT NULL',
    ];
    foreach ($required as $column => $sql) {
        if (empty($columns[$column])) $pdo->exec($sql);
    }
}

function mfaBase32Encode(string $bytes): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $out = '';
    $buffer = 0;
    $bits = 0;
    foreach (str_split($bytes) as $char) {
        $buffer = ($buffer << 8) | ord($char);
        $bits += 8;
        while ($bits >= 5) {
            $bits -= 5;
            $out .= $alphabet[($buffer >> $bits) & 31];
        }
    }
    if ($bits > 0) $out .= $alphabet[($buffer << (5 - $bits)) & 31];
    return $out;
}

function mfaBase32Decode(string $secret): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $secret = strtoupper(preg_replace('/[^A-Z2-7]/i', '', $secret) ?? '');
    $buffer = 0;
    $bits = 0;
    $out = '';
    foreach (str_split($secret) as $char) {
        $buffer = ($buffer << 5) | strpos($alphabet, $char);
        $bits += 5;
        if ($bits >= 8) {
            $bits -= 8;
            $out .= chr(($buffer >> $bits) & 255);
        }
    }
    return $out;
}

function mfaGenerateSecret(): string
{
    return mfaBase32Encode(random_bytes(20));   // 160 bits, as RFC 4226 recommends
}

/** The code an authenticator would show for a given 30-second step. */
function mfaCodeAt(string $secret, int $counter): string
{
    $binary = pack('N*', 0) . pack('N*', $counter);
    $hash = hash_hmac('sha1', $binary, mfaBase32Decode($secret), true);
    $offset = ord($hash[19]) & 0x0f;
    $value = ((ord($hash[$offset]) & 0x7f) << 24)
        | ((ord($hash[$offset + 1]) & 0xff) << 16)
        | ((ord($hash[$offset + 2]) & 0xff) << 8)
        | (ord($hash[$offset + 3]) & 0xff);
    return str_pad((string)($value % (10 ** MFA_DIGITS)), MFA_DIGITS, '0', STR_PAD_LEFT);
}

/** Compares in constant time, and accepts one step either side for clock drift. */
function mfaVerifyCode(string $secret, string $code): bool
{
    $code = preg_replace('/\D/', '', $code) ?? '';
    if (strlen($code) !== MFA_DIGITS) return false;
    $counter = (int)floor(time() / MFA_PERIOD);
    for ($i = -MFA_WINDOW; $i <= MFA_WINDOW; $i++) {
        if (hash_equals(mfaCodeAt($secret, $counter + $i), $code)) return true;
    }
    return false;
}

/** @return array{plain:string[],hashed:string} Recovery codes, shown once and never again. */
function mfaGenerateRecoveryCodes(): array
{
    $plain = [];
    $hashed = [];
    for ($i = 0; $i < MFA_RECOVERY_CODES; $i++) {
        // Grouped for transcription by hand, which is how these actually get used.
        $code = strtoupper(bin2hex(random_bytes(2)) . '-' . bin2hex(random_bytes(2)));
        $plain[] = $code;
        $hashed[] = password_hash($code, PASSWORD_DEFAULT);
    }
    return ['plain' => $plain, 'hashed' => json_encode($hashed)];
}

/** Consumes a recovery code if it matches. Each one works exactly once. */
function mfaConsumeRecoveryCode(PDO $pdo, int $userId, string $stored, string $candidate): bool
{
    $codes = json_decode($stored ?: '[]', true);
    if (!is_array($codes)) return false;
    $candidate = strtoupper(trim($candidate));
    foreach ($codes as $index => $hash) {
        if (password_verify($candidate, (string)$hash)) {
            unset($codes[$index]);
            $pdo->prepare('UPDATE users SET mfa_recovery = ? WHERE id = ?')
                ->execute([json_encode(array_values($codes)), $userId]);
            return true;
        }
    }
    return false;
}

/** The URI an authenticator app scans. The issuer is what the member sees in their app. */
function mfaProvisioningUri(string $secret, string $email, string $issuer = 'ReSoK Members Portal'): string
{
    return 'otpauth://totp/' . rawurlencode($issuer) . ':' . rawurlencode($email)
        . '?secret=' . $secret
        . '&issuer=' . rawurlencode($issuer)
        . '&algorithm=SHA1&digits=' . MFA_DIGITS . '&period=' . MFA_PERIOD;
}

/**
 * A short-lived token proving the password step already passed, so the second factor can
 * be a separate request without holding server-side state. It is scoped to mfa only, so it
 * cannot be presented anywhere a session token is expected.
 */
function mfaIssueChallenge(array $config, int $userId): string
{
    $body = rtrim(strtr(base64_encode((string)json_encode([
        'purpose' => 'mfa',
        'userId' => $userId,
        'exp' => time() + MFA_CHALLENGE_SECONDS,
    ])), '+/', '-_'), '=');
    $sig = rtrim(strtr(base64_encode(hash_hmac('sha256', $body, (string)$config['jwt_secret'] . '|mfa', true)), '+/', '-_'), '=');
    return $body . '.' . $sig;
}

function mfaVerifyChallenge(array $config, string $token): ?int
{
    [$body, $sig] = array_pad(explode('.', $token, 2), 2, '');
    if ($body === '' || $sig === '') return null;
    $expected = rtrim(strtr(base64_encode(hash_hmac('sha256', $body, (string)$config['jwt_secret'] . '|mfa', true)), '+/', '-_'), '=');
    if (!hash_equals($expected, $sig)) return null;
    $decoded = base64_decode(strtr($body, '-_', '+/'), true);
    $payload = $decoded === false ? null : json_decode($decoded, true);
    if (!is_array($payload) || ($payload['purpose'] ?? '') !== 'mfa') return null;
    if ((int)($payload['exp'] ?? 0) < time()) return null;
    return (int)($payload['userId'] ?? 0) ?: null;
}

/** Roles that must not be reachable with a password alone. */
function mfaRequiredForRole(string $role): bool
{
    return in_array($role, ['admin', 'content_manager', 'analytics_manager'], true);
}
