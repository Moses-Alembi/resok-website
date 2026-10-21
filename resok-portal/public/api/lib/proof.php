<?php
declare(strict_types=1);

/**
 * "Verifying your browser": a small proof of work in front of the actions bots go for -
 * signing in, creating accounts, password resets, the contact form, job applications.
 *
 * The server hands out a signed puzzle; the browser finds a number that makes
 * sha256(puzzle + ":" + number) start with N zero bits (about a second on a phone, done in
 * the background) and sends it with the request. A person never notices. A bot trying
 * thousands of passwords or spamming the forms pays that second for every single attempt,
 * which turns a cheap attack into an expensive one.
 *
 * Puzzles are stateless (HMAC-signed, five minutes) and single-use: a solved puzzle is
 * recorded until it expires, so one solution cannot be replayed. This is a second layer
 * behind the per-account and per-device rate limits, not a replacement for them, and it is
 * no defence against a flood of traffic - that is what Cloudflare is for.
 *
 * Off unless config 'proof_of_work' is true. The difficulty is 'proof_of_work_bits'.
 */

const PROOF_TTL_SECONDS = 300;
const PROOF_HEADER = 'HTTP_X_RESOK_PROOF';

function proofEnabled(array $config): bool
{
    return !empty($config['proof_of_work']) && (string)($config['jwt_secret'] ?? '') !== '';
}

function proofBits(array $config): int
{
    return max(8, min(24, (int)($config['proof_of_work_bits'] ?? 16)));
}

/** The routes that need a solved puzzle. */
function proofProtectedRoute(string $route, string $method): bool
{
    if ($method !== 'POST') return false;
    if (in_array($route, ['auth/login', 'auth/register', 'auth/register-learner', 'auth/forgot-password', 'auth/resend-verification',
                          'auth/mfa/verify', 'abstracts/register', 'careers/apply', 'contact', 'newsletter'], true)) {
        return true;
    }
    return (bool)preg_match('#^events/[^/]+/token/request$#', $route);
}

function proofKey(array $config): string
{
    // Derived, so the session-signing key itself never signs anything else.
    return hash_hmac('sha256', 'resok-proof-of-work-v1', (string)$config['jwt_secret']);
}

function proofIssue(array $config): array
{
    $payload = 'v1.' . (time() + PROOF_TTL_SECONDS) . '.' . bin2hex(random_bytes(16));
    $sig = substr(hash_hmac('sha256', $payload, proofKey($config)), 0, 32);
    return ['challenge' => $payload . '.' . $sig, 'bits' => proofBits($config), 'expiresIn' => PROOF_TTL_SECONDS];
}

function proofLeadingZeroBits(string $binary): int
{
    $bits = 0;
    for ($i = 0, $n = strlen($binary); $i < $n; $i++) {
        $byte = ord($binary[$i]);
        if ($byte === 0) { $bits += 8; continue; }
        for ($mask = 0x80; $mask > 0 && !($byte & $mask); $mask >>= 1) $bits++;
        break;
    }
    return $bits;
}

function proofEnsureTable(PDO $pdo): bool
{
    static $ready = null;
    if ($ready !== null) return $ready;
    try {
        $pdo->exec('CREATE TABLE IF NOT EXISTS proof_used (
            id CHAR(64) NOT NULL,
            expires_at INT UNSIGNED NOT NULL,
            PRIMARY KEY (id),
            KEY proof_used_expires (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=ascii');
        return $ready = true;
    } catch (Throwable $e) {
        error_log('Proof table unavailable: ' . $e->getMessage());
        return $ready = false;
    }
}

/** Null when the request carries a valid, unused solution; otherwise why not. */
function proofCheck(?PDO $pdo, array $config, ?string $header): ?string
{
    if ($header === null || $header === '' || strlen($header) > 200) return 'missing';
    $parts = explode('|', $header);
    if (count($parts) !== 2) return 'malformed';
    [$challenge, $nonce] = $parts;
    if (!preg_match('/^v1\.(\d{10})\.([0-9a-f]{32})\.([0-9a-f]{32})$/', $challenge, $m)) return 'malformed';
    if (!preg_match('/^[0-9]{1,12}$/', $nonce)) return 'malformed';
    $payload = 'v1.' . $m[1] . '.' . $m[2];
    if (!hash_equals(substr(hash_hmac('sha256', $payload, proofKey($config)), 0, 32), $m[3])) return 'forged';
    $expires = (int)$m[1];
    if ($expires < time() || $expires > time() + PROOF_TTL_SECONDS + 5) return 'expired';
    if (proofLeadingZeroBits(hash('sha256', $challenge . ':' . $nonce, true)) < proofBits($config)) return 'unsolved';
    if ($pdo && proofEnsureTable($pdo)) {
        // Single use. INSERT IGNORE affects no row when this puzzle was already spent.
        $stmt = $pdo->prepare('INSERT IGNORE INTO proof_used (id, expires_at) VALUES (?, ?)');
        $stmt->execute([hash('sha256', $challenge), $expires]);
        if ($stmt->rowCount() === 0) return 'reused';
        if (random_int(1, 50) === 1) $pdo->prepare('DELETE FROM proof_used WHERE expires_at < ?')->execute([time()]);
    }
    return null;
}

/** Stops the request with 428 unless it carries a solved puzzle. The browser helper retries. */
function proofRequire(?PDO $pdo, array $config, string $route, string $method): void
{
    if (!proofEnabled($config) || !proofProtectedRoute($route, $method)) return;
    $problem = proofCheck($pdo, $config, isset($_SERVER[PROOF_HEADER]) ? (string)$_SERVER[PROOF_HEADER] : null);
    if ($problem === null) return;
    respond(428, ['error' => 'Your browser could not be verified. Please refresh the page and try again.',
                  'powRequired' => true, 'reason' => $problem]);
}
