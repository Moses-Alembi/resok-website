<?php
declare(strict_types=1);

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');

$configPath = __DIR__ . '/config.php';
if (!file_exists($configPath)) {
    respond(500, ['error' => 'API config.php is missing. Copy config.sample.php to config.php and add database credentials.']);
}

$config = require $configPath;

/**
 * Modules are loaded defensively because this site is deployed by uploading files, and a
 * partial upload is normal rather than exceptional. A bare require_once on a file that did
 * not make it up is a fatal error before any handler runs, which takes the ENTIRE API down
 * - every route, including ones that do not use the missing module - and returns an empty
 * 500 that says nothing about why. That has happened; this makes it impossible.
 *
 * Anything missing is recorded instead. Routes that need it fail individually with a
 * message naming the file, and everything else keeps working.
 */
/**
 * One timezone for everything.
 *
 * PHP and MySQL each default to whatever the host decided, and they disagreed by an hour on
 * both the live server and this laptop. Anything written by one and compared by the other -
 * a lockout expiry, an event's start time deciding whether it is upcoming or past - was
 * wrong by that hour.
 *
 * Set here rather than in php.ini because php.ini is not reliably editable on shared
 * hosting. Nairobi because that is where the society and its events are: a date an admin
 * types should mean what it says.
 */
date_default_timezone_set('Africa/Nairobi');

$missingModules = [];
foreach (['portal-mail', 'mpesa', 'throttle', 'mfa', 'security-assessment', 'blog', 'social-ingest', 'invites', 'crypto', 'input-guard', 'events', 'attendance', 'ict', 'ict-infrastructure', 'ict-assets', 'ict-credentials', 'ict-licenses', 'ict-tickets', 'migrate'] as $module) {
    $modulePath = __DIR__ . '/lib/' . $module . '.php';
    if (is_file($modulePath)) {
        require_once $modulePath;
    } else {
        $missingModules[] = 'lib/' . $module . '.php';
        error_log("API module not deployed: lib/{$module}.php");
    }
}

/**
 * Rate limiting degrades to the previous behaviour when its module is absent - no limiting,
 * but authentication still works. A portal nobody can log into is a worse outcome than one
 * missing a control it did not have last week, and the threat assessment reports the module
 * as missing so it cannot pass unnoticed.
 */
if (!function_exists('throttleCheck')) {
    function throttleCheck(PDO $pdo, array $config, string $action, string $subject): void {}
    function throttleFailure(PDO $pdo, array $config, string $action, string $subject): void {}
    function throttleSuccess(PDO $pdo, array $config, string $action, string $subject): void {}
    function authThrottleCheck(PDO $pdo, array $config, string $email): void {}
    function authThrottleFailure(PDO $pdo, array $config, string $email): void {}
    function authThrottleSuccess(PDO $pdo, array $config, string $email): void {}
    function securityLog(PDO $pdo, array $config, string $type, string $severity = 'info',
                         ?string $action = null, ?string $detail = null, ?int $userId = null): void {}
}

/**
 * Two-factor degrades differently, and deliberately so. Skipping the second factor because
 * its file is missing would turn a deployment slip into an authentication bypass for
 * exactly the accounts that enrolled to be safest. So these shims cover only the parts that
 * are safe to skip; the login route refuses outright if an enrolled member arrives while
 * the module is absent.
 */
if (!function_exists('botScreen')) {
    function botScreen(array $data, int $minSeconds = 0): ?string { return null; }
    function botScreenOrFakeSuccess(PDO $pdo, array $config, array $data, string $action, array $pretend, int $minSeconds = 0): void {}
    function validateInput(array $data, array $rules): void {}
}

if (!function_exists('cryptoEncrypt')) {
    function cryptoEncrypt(array $config, ?string $plain): ?string { return $plain; }
    function cryptoDecrypt(array $config, ?string $stored): ?string { return $stored; }
    function cryptoAvailable(array $config): bool { return false; }
    // cryptoConfig is shimmed too. mapMember() calls it with no guard of its own, so
    // without this a missing crypto.php would fatal on every member the admin panel lists.
    function cryptoConfig(?array $set = null): array { return []; }
}

if (!function_exists('ictTicketsSummary')) {
    function ictTicketsSummary(PDO $pdo): array {
        return ['open' => 0, 'unassigned' => 0, 'overdue' => 0, 'resolvedThisMonth' => 0,
                'medianResponseHours' => null, 'medianResolutionHours' => null];
    }
}

if (!function_exists('ictLicensesSummary')) {
    function ictLicensesSummary(PDO $pdo): array {
        return ['total' => 0, 'expiringSoon' => 0, 'expired' => 0,
                'annualCost' => 0.0, 'seatsIdle' => 0, 'overAllocated' => 0];
    }
}

if (!function_exists('ictCredentialsSummary')) {
    function ictCredentialsSummary(PDO $pdo): array {
        return ['total' => 0, 'critical' => 0, 'noMfa' => 0, 'criticalNoMfa' => 0,
                'rotationOverdue' => 0, 'noVaultLink' => 0, 'noOwner' => 0];
    }
}

if (!function_exists('ictAssetsSummary')) {
    function ictAssetsSummary(PDO $pdo): array {
        return ['total' => 0, 'byStatus' => [], 'value' => 0.0,
                'warrantyExpiring' => 0, 'maintenanceDue' => 0];
    }
}

if (!function_exists('ictInfraSummary')) {
    function ictInfraSummary(PDO $pdo): array {
        return ['total' => 0, 'bands' => [], 'overall' => 'unknown', 'attention' => []];
    }
}

if (!function_exists('ictCan')) {
    function ictEnsureTables(PDO $pdo): bool { return false; }
    function ictCan(PDO $pdo, array $user, array $config, string $capability): bool { return false; }
    function ictHasAnyAccess(PDO $pdo, array $user, array $config): bool { return false; }
    function ictCapabilitiesFor(PDO $pdo, int $userId): array { return []; }
    function ictCapabilityList(): array { return []; }
    function ictAudit(PDO $pdo, ?int $a, string $b, ?string $c = null, ?string $d = null,
                      ?string $e = null, ?array $f = null, ?array $g = null): void {}
    function ictAuditRecent(PDO $pdo, int $limit = 40): array { return []; }
    function ictRequire(PDO $pdo, array $user, array $config, string $capability): void {
        respond(503, ['error' => 'The ICT module is not deployed.', 'missing' => 'lib/ict.php']);
    }
}

if (!function_exists('attendanceEnsureTables')) {
    function attendanceEnsureTables(PDO $pdo): bool { return false; }
    function tokensSummary(PDO $pdo, int $eventId): array { return []; }
}

if (!function_exists('eventsUpcoming')) {
    function eventsUpcoming(PDO $pdo, int $limit = 50): array { return []; }
    function eventsPast(PDO $pdo, int $limit = 12): array { return []; }
    function eventLegacyShape(array $public): array { return $public; }
}

if (!function_exists('blogRequireTables')) {
    // Reached only if the guards above ever change order; the module check fires first.
    function blogRequireTables(PDO $pdo): void {}
}

if (!function_exists('mfaEnsureColumns')) {
    function mfaEnsureColumns(PDO $pdo): bool { return false; }
    function mfaRequiredForRole(string $role): bool { return false; }
}

// Give the crypto helpers the config once, so decryption works from anywhere. Always
// defined by this point - the real one, or the shim above.
cryptoConfig($config);

/** Refuses a route whose module is absent, saying which file to upload. */
function requireModule(string $function, string $file): void
{
    if (!function_exists($function)) {
        respond(503, [
            'error' => 'This feature is not available: a required file is missing on the server.',
            'missing' => $file,
        ]);
    }
}

$debugValue = array_key_exists('debug', $config) ? $config['debug'] : getenv('RESOK_DEBUG');
$isDebug = filter_var($debugValue ?: false, FILTER_VALIDATE_BOOLEAN);

/**
 * Sends a JSON response and stops. Nothing after a call to this runs.
 *
 * Documented as @return never so static analysis knows execution ends here. The native
 * never type is deliberately not used: it needs PHP 8.1, and if the shared host is on 8.0
 * it would be a parse error - taking down every route in the API, which is the exact
 * failure this project has already had twice. A docblock costs nothing at runtime.
 *
 * @return never
 */
function respond(int $status, array $payload): void {
    // JSON only - never a document. This says so explicitly, so a browser coaxed into
    // rendering a response cannot run anything from it.
    header("Content-Security-Policy: default-src 'none'");
    header('X-Content-Type-Options: nosniff');
    http_response_code($status);
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

// Used for links people land on by clicking an email (verification, etc.) - a browser
// visits these directly, so they get a branded page instead of raw JSON.
function respondHtmlPage(int $status, string $title, string $message, bool $isError = false, ?string $ctaText = null, ?string $ctaUrl = null): void {
    http_response_code($status);
    header_remove('Content-Type');
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-store');
    $accent = $isError ? '#bc0b22' : '#00932e';
    $icon = $isError ? '&#10060;' : '&#9989;';
    $cta = '';
    if ($ctaText !== null && $ctaUrl !== null) {
        $cta = '<a href="' . htmlspecialchars($ctaUrl, ENT_QUOTES) . '" style="display:inline-block;margin-top:24px;background:' . $accent . ';color:#fff;text-decoration:none;font-weight:700;padding:14px 32px;border-radius:6px;font-size:15px;font-family:Segoe UI,Arial,sans-serif;">' . htmlspecialchars($ctaText, ENT_QUOTES) . '</a>';
    }
    echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . htmlspecialchars($title, ENT_QUOTES) . ' - ReSoK</title></head>'
        . '<body style="margin:0;padding:0;background:#f5f7fa;font-family:Segoe UI,Arial,sans-serif;color:#0f172a;display:flex;align-items:center;justify-content:center;min-height:100vh;">'
        . '<div style="max-width:440px;width:90%;background:#fff;border-radius:12px;box-shadow:0 14px 40px rgba(15,23,42,.10);overflow:hidden;text-align:center;">'
        . '<div style="background:' . $accent . ';height:6px;"></div>'
        . '<div style="padding:40px 32px;">'
        . '<div style="font-size:44px;line-height:1;margin-bottom:16px;">' . $icon . '</div>'
        . '<h1 style="margin:0 0 12px;font-size:22px;">' . htmlspecialchars($title, ENT_QUOTES) . '</h1>'
        . '<p style="margin:0;color:#667085;font-size:15px;line-height:1.6;">' . htmlspecialchars($message, ENT_QUOTES) . '</p>'
        . $cta
        . '</div></div></body></html>';
    exit;
}

function input(): array {
    $raw = file_get_contents('php://input');
    if (!$raw) return $_POST ?: [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : ($_POST ?: []);
}

function db(array $config): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $config['db_host'],
        (int)($config['db_port'] ?? 3306),
        $config['db_name']
    );
    $pdo = new PDO($dsn, $config['db_user'], $config['db_pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);

    // Pins this connection to the same offset PHP is using, so NOW() and PHP's date() agree.
    // A fixed offset rather than 'Africa/Nairobi', because the named-timezone tables are
    // often not loaded on shared MySQL and setting an unknown name is an error. Kenya has
    // never observed daylight saving, so +03:00 is correct year round.
    try {
        $pdo->exec("SET time_zone = '+03:00'");
    } catch (Throwable $e) {
        // Not fatal. Worth knowing about, but not worth refusing to serve the site over.
        error_log('Could not set the database session timezone: ' . $e->getMessage());
    }
    return $pdo;
}

function requireConfig(array $config): void {
    foreach (['db_name', 'db_user', 'jwt_secret'] as $key) {
        if (empty($config[$key])) respond(500, ['error' => 'Portal API is not configured.']);
    }
    if (strlen((string)$config['jwt_secret']) < 32 || strpos((string)$config['jwt_secret'], 'replace_with') === 0) {
        respond(500, ['error' => 'Portal API is not configured.']);
    }
}

function b64url(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

// Absolute cap on a session: even a member who never stops clicking has to log in again
// after this. In practice the idle timeout below almost always ends the session first.
const RESOK_SESSION_MAX_LIFETIME = 7 * 24 * 60 * 60;

// A session ends after this long with no authenticated request. Every call through auth()
// slides the window forward, so "activity" means any portal page that talks to the API,
// plus /learning on the main site (member-gate.php enforces the same window - keep the
// two constants in step if this changes).
const RESOK_SESSION_IDLE_TIMEOUT = 20 * 60;

// Re-signing the cookie on literally every request would put a Set-Cookie header on every
// JSON response for no benefit. Refreshing at most this often keeps the sliding window
// accurate to within a minute, which is plenty against a 20-minute timeout.
const RESOK_SESSION_REFRESH_INTERVAL = 60;

/**
 * `exp` is the absolute end of the session and is carried over unchanged when a token is
 * refreshed; `seen` is the last-activity stamp that auth() moves forward. Pass $expiresAt
 * to preserve an existing session's cap; omit it when minting a brand new session.
 */
function token(array $payload, string $secret, ?int $expiresAt = null): string {
    $payload['exp'] = $expiresAt ?? (time() + RESOK_SESSION_MAX_LIFETIME);
    $payload['seen'] = time();
    $body = b64url(json_encode($payload));
    $sig = b64url(hash_hmac('sha256', $body, $secret, true));
    return $body . '.' . $sig;
}

/**
 * Whether this request arrived over a secure connection.
 *
 * Checks X-Forwarded-Proto as well as HTTPS, because TLS is terminated at the host's proxy
 * and Apache frequently never sees HTTPS=on - the force-HTTPS rule in .htaccess and the HSTS
 * header both had to learn the same thing.
 *
 * Only a plain-HTTP request on localhost returns false, and that is the one case where a
 * Secure cookie is worse than useless: the browser will not store it, so the session is lost
 * the moment the page makes its first API call.
 */
function requestIsSecure(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') return true;
    if (strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https') return true;
    if ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443) return true;
    return false;
}

// httpOnly cookie is the primary session for browser clients (see issueAuthCookie());
// the Authorization header stays supported as a fallback for any non-browser API caller.
// SameSite=Lax + HttpOnly + Secure is treated as sufficient CSRF protection here — every
// state-changing call in this API is a fetch() POST, which Lax already blocks cross-site,
// so no separate CSRF token is issued. Don't "fix" that without re-adding one.
// The cookie itself is given the idle window, not the 7-day cap, so a browser left closed
// past the timeout discards the session on its own instead of sending a token the server
// is only going to reject. Each refresh in auth() extends it again.
function issueAuthCookie(string $token): void {
    setcookie('resok_token', $token, [
        'expires' => time() + RESOK_SESSION_IDLE_TIMEOUT,
        'path' => '/',
        'secure' => requestIsSecure(),
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
}

function clearAuthCookie(): void {
    setcookie('resok_token', '', [
        'expires' => time() - 3600,
        'path' => '/',
        'secure' => requestIsSecure(),
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
}

function auth(array $config): array {
    $fromCookie = true;
    $token = $_COOKIE['resok_token'] ?? '';
    if (!$token) {
        $fromCookie = false;
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (!$header && function_exists('apache_request_headers')) {
            $headers = apache_request_headers();
            $header = $headers['Authorization'] ?? $headers['authorization'] ?? '';
        }
        if (!preg_match('/^Bearer\s+(.+)$/', $header, $m)) {
            respond(401, ['error' => 'Missing token']);
        }
        $token = $m[1];
    }
    [$body, $sig] = array_pad(explode('.', $token, 2), 2, '');
    $expected = b64url(hash_hmac('sha256', $body, $config['jwt_secret'], true));
    if (!$body || !$sig || !hash_equals($expected, $sig)) {
        respond(401, ['error' => 'Invalid token']);
    }
    $payload = json_decode(base64_decode(strtr($body, '-_', '+/')), true);
    if (!is_array($payload) || (($payload['exp'] ?? 0) < time())) {
        respond(401, ['error' => 'Expired token']);
    }

    // Idle timeout. Tokens minted before this existed carry no `seen` claim; those are
    // grandfathered (their window starts on this request) rather than logging out every
    // signed-in member the moment this deploys. The refresh below stamps them.
    $seen = (int)($payload['seen'] ?? 0);
    $idleFor = time() - $seen;
    if ($seen > 0 && $idleFor > RESOK_SESSION_IDLE_TIMEOUT) {
        clearAuthCookie();
        respond(401, [
            'error' => 'You were signed out after 20 minutes of inactivity. Please log in again.',
            'reason' => 'idle'
        ]);
    }

    // Slide the window forward. The original `exp` is carried over untouched, so an active
    // session still ends at the absolute cap rather than renewing itself forever. Header
    // callers have no cookie to refresh - they just re-authenticate.
    if ($fromCookie && $idleFor >= RESOK_SESSION_REFRESH_INTERVAL) {
        $refreshed = $payload;
        unset($refreshed['exp'], $refreshed['seen']);
        issueAuthCookie(token($refreshed, $config['jwt_secret'], (int)$payload['exp']));
    }

    return $payload;
}

function memberRow(PDO $pdo, int $userId): ?array {
    $stmt = $pdo->prepare('SELECT mp.*, u.email FROM member_profiles mp JOIN users u ON u.id = mp.user_id WHERE mp.user_id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function memberRowByProfileId(PDO $pdo, int $profileId): ?array {
    $stmt = $pdo->prepare('SELECT mp.*, u.email FROM member_profiles mp JOIN users u ON u.id = mp.user_id WHERE mp.id = ? LIMIT 1');
    $stmt->execute([$profileId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function mapMember(?array $row): ?array {
    if (!$row) return null;
    $profileImage = $row['profile_image'] ?? null;
    return [
        'id' => (int)$row['id'],
        'userId' => (int)$row['user_id'],
        'email' => $row['email'] ?? '',
        'title' => $row['title'],
        'firstName' => $row['first_name'],
        'middleName' => $row['middle_name'],
        'surname' => $row['surname'],
        'country' => $row['country'],
        'county' => $row['county'],
        'division' => $row['division'],
        'profession' => $row['profession'] ?? null,
        'specialization' => $row['specialization'] ?? null,
        'institution' => $row['institution'] ?? null,
        'physicalAddress' => $row['physical_address'] ?? null,
        'payerType' => $row['payer_type'] ?? 'Individual',
        'category' => $row['category'],
        'idType' => $row['id_type'],
        // Decrypts when encrypted, passes through when the row predates the key.
        'idNumber' => cryptoDecrypt(cryptoConfig(), $row['id_number']),
        'mobile' => $row['mobile'],
        'profileImage' => $profileImage,
        'profileImageUrl' => !empty($profileImage) ? 'api/index.php?route=profile-images/' . rawurlencode(basename((string)$profileImage)) : null,
        'membershipStatus' => $row['membership_status'],
        'membershipId' => $row['membership_id'],
        'cpdPoints' => (int)$row['cpd_points'],
        'renewalDue' => $row['renewal_due'],
        'reviewReason' => $row['review_reason']
    ];
}

function requireAdmin(array $user): void {
    if (($user['role'] ?? '') !== 'admin') respond(403, ['error' => 'Admin access required']);
}

/**
 * A super administrator is named by email in config, not by a database column.
 *
 * That is deliberate on two counts. It needs no ALTER TABLE, which this host may not permit
 * and which has already broken login once. And it means the highest privilege on the site
 * can only be granted by someone with server access - not by anyone who reaches the admin
 * panel, which is precisely the account an attacker would be sitting in.
 *
 * With no list configured, every admin keeps exactly the access they have today. Silently
 * locking the only admin out of a page they rely on would be a worse failure than the one
 * this prevents, so the threat assessment reports the unset list as a warning instead.
 */
function isSuperAdmin(array $user, array $config): bool {
    if (($user['role'] ?? '') !== 'admin') return false;
    $list = $config['super_admins'] ?? [];
    if (!$list) return true;
    $email = strtolower(trim((string)($user['email'] ?? '')));
    foreach ($list as $candidate) {
        if ($email !== '' && $email === strtolower(trim((string)$candidate))) return true;
    }
    return false;
}

function requireSuperAdmin(array $user, array $config): void {
    requireAdmin($user);
    if (!isSuperAdmin($user, $config)) {
        respond(403, ['error' => 'This area is restricted to super administrators.']);
    }
}

function requireFields(array $data, array $fields): void {
    foreach ($fields as $field) {
        if (!isset($data[$field]) || trim((string)$data[$field]) === '') {
            respond(400, ['error' => 'Required registration fields are missing']);
        }
    }
}

function uploadedMime(array $file): string {
    $tmpName = (string)($file['tmp_name'] ?? '');
    if ($tmpName !== '' && is_file($tmpName) && function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $mime = finfo_file($finfo, $tmpName);
            finfo_close($finfo);
            if (is_string($mime) && $mime !== '') return $mime;
        }
    }
    return (string)($file['type'] ?? '');
}

function generateMembershipId(PDO $pdo): string {
    $stmt = $pdo->query(
        "SELECT membership_id
         FROM member_profiles
         WHERE membership_id REGEXP '^RESOK[0-9]+$'
         ORDER BY CAST(SUBSTRING(membership_id, 6) AS UNSIGNED) DESC
         LIMIT 1"
    );
    $latest = $stmt->fetchColumn();
    $next = $latest ? ((int)substr((string)$latest, 5) + 1) : 1;

    for ($i = 0; $i < 20; $i++, $next++) {
        $candidate = 'RESOK' . str_pad((string)$next, 4, '0', STR_PAD_LEFT);
        $stmt = $pdo->prepare('SELECT id FROM member_profiles WHERE membership_id = ? LIMIT 1');
        $stmt->execute([$candidate]);
        if (!$stmt->fetch()) return $candidate;
    }

    return 'RESOK' . str_pad((string)$next, 4, '0', STR_PAD_LEFT);
}

function sendPortalMail(array $config, string $to, string $subject, string $message): bool {
    $from = trim((string)($config['mail_from'] ?? ''));
    if ($from === '' || !filter_var($from, FILTER_VALIDATE_EMAIL)) return false;
    $headers = [
        'From: ReSoK Members Portal <' . $from . '>',
        'Reply-To: ' . $from,
        'Content-Type: text/plain; charset=UTF-8'
    ];
    return mail($to, $subject, $message, implode("\r\n", $headers));
}

function ensurePaymentProofColumns(PDO $pdo): void {
    $columns = [];
    foreach ($pdo->query('SHOW COLUMNS FROM payments')->fetchAll() as $column) {
        $columns[$column['Field']] = true;
    }

    $required = [
        'provider_reference' => 'ALTER TABLE payments ADD COLUMN provider_reference VARCHAR(120) NULL AFTER reference',
        'proof_filename' => 'ALTER TABLE payments ADD COLUMN proof_filename VARCHAR(255) NULL AFTER provider_reference',
        'proof_original_name' => 'ALTER TABLE payments ADD COLUMN proof_original_name VARCHAR(255) NULL AFTER proof_filename',
        'proof_mime_type' => 'ALTER TABLE payments ADD COLUMN proof_mime_type VARCHAR(120) NULL AFTER proof_original_name',
        'proof_file_size' => 'ALTER TABLE payments ADD COLUMN proof_file_size INT UNSIGNED NULL AFTER proof_mime_type'
    ];

    foreach ($required as $column => $sql) {
        if (empty($columns[$column])) {
            $pdo->exec($sql);
        }
    }
}

/**
 * Server-defined event catalog. Members can't award themselves arbitrary CPD points,
 * so registration always credits exactly the points listed here. Update this list as
 * ReSoK schedules real events -- this replaces the old hardcoded/broken demo cards
 * that used to live directly in events.html.
 */
function eventCatalog(): array {
    return [
        [
            'id' => 'asthma-guidelines-2026',
            'title' => 'Asthma Management Guidelines 2026',
            'type' => 'CME',
            'date' => '2026-06-15',
            'time' => '9AM - 4PM',
            'location' => 'Nairobi, Hybrid',
            'cpdPoints' => 6,
            'fee' => 2500,
            'currency' => 'KES'
        ],
        [
            'id' => 'copd-updates-webinar',
            'title' => 'Webinar: COPD Updates',
            'type' => 'Webinar',
            'date' => '2026-06-28',
            'time' => '7PM - 8:30PM',
            'location' => 'Online (Zoom)',
            'cpdPoints' => 2,
            'fee' => 0,
            'currency' => 'KES'
        ],
        [
            'id' => 'annual-conference-2026',
            'title' => 'Annual ReSoK Conference 2026',
            'type' => 'Conference',
            'date' => '2026-07-10',
            'time' => '2 Days',
            'location' => 'Mombasa',
            'cpdPoints' => 15,
            'fee' => 8500,
            'currency' => 'KES'
        ]
    ];
}

function ensureEventRegistrationsTable(PDO $pdo): void {
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS event_registrations (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            member_profile_id INT UNSIGNED NOT NULL,
            event_id VARCHAR(60) NOT NULL,
            event_title VARCHAR(160) NOT NULL,
            cpd_points INT NOT NULL DEFAULT 0,
            registered_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY event_registrations_unique (member_profile_id, event_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
}

function ensureCpdTable(PDO $pdo): void {
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS cpd_activities (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            member_profile_id INT UNSIGNED NOT NULL,
            description VARCHAR(255) NOT NULL,
            points INT NOT NULL DEFAULT 0,
            occurred_on DATE NULL,
            added_by INT UNSIGNED NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY cpd_activities_member_idx (member_profile_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
}

function ensureAuditTable(PDO $pdo): void {
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS admin_actions (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            admin_user_id INT UNSIGNED NULL,
            action VARCHAR(60) NOT NULL,
            target_member_profile_id INT UNSIGNED NULL,
            reason TEXT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY admin_actions_target_idx (target_member_profile_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
}

function logAdminAction(PDO $pdo, ?int $adminUserId, string $action, ?int $targetMemberProfileId, ?string $reason = null): void {
    ensureAuditTable($pdo);
    $stmt = $pdo->prepare('INSERT INTO admin_actions (admin_user_id, action, target_member_profile_id, reason) VALUES (?, ?, ?, ?)');
    $stmt->execute([$adminUserId, $action, $targetMemberProfileId, $reason]);
}

function approveMemberById(PDO $pdo, array $config, int $memberId, ?int $adminUserId): array {
    if (empty($config['allow_approve_without_payment'])) {
        $stmt = $pdo->prepare('SELECT COUNT(*) AS c FROM payments WHERE member_profile_id = ? AND status = "paid"');
        $stmt->execute([$memberId]);
        if (!(int)$stmt->fetch()['c']) {
            throw new RuntimeException('A confirmed payment is required before approval.');
        }
    }
    $membershipId = generateMembershipId($pdo);
    $pdo->prepare('UPDATE member_profiles SET membership_status = "active", membership_id = COALESCE(membership_id, ?), renewal_due = DATE_ADD(CURDATE(), INTERVAL 1 YEAR), review_reason = NULL, reviewed_at = NOW() WHERE id = ?')->execute([$membershipId, $memberId]);
    $row = memberRowByProfileId($pdo, $memberId);
    $member = mapMember($row);
    logAdminAction($pdo, $adminUserId, 'approve', $memberId, null);
    // The outcome is reported back rather than only logged. A silent failure here means an
    // approved member never receives their letter and card, while the admin sees a success
    // and has no reason to look - which is precisely how this went unnoticed.
    $welcomeEmailSent = null;
    $welcomeEmailError = null;
    if ($row && $member) {
        try {
            $welcomeEmailSent = (bool)sendWelcomePacketEmail($config, array_merge($member, ['email' => $row['email']]));
            if (!$welcomeEmailSent) $welcomeEmailError = 'The mail server did not accept the message.';
        } catch (Throwable $mailError) {
            $welcomeEmailSent = false;
            $welcomeEmailError = $mailError->getMessage();
            error_log('Welcome email failed: ' . $mailError->getMessage());
        }
    }
    if ($member) {
        $member['welcomeEmailSent'] = $welcomeEmailSent;
        $member['welcomeEmailError'] = $welcomeEmailError;
    }
    return $member ?? [];
}

function rejectMemberById(PDO $pdo, int $memberId, string $reason, ?int $adminUserId): array {
    $pdo->prepare('UPDATE member_profiles SET membership_status = "rejected", review_reason = ?, reviewed_at = NOW() WHERE id = ?')->execute([$reason, $memberId]);
    $row = memberRowByProfileId($pdo, $memberId);
    $member = mapMember($row);
    logAdminAction($pdo, $adminUserId, 'reject', $memberId, $reason);
    return $member ?? [];
}

$route = trim($_GET['route'] ?? '', '/');
$method = $_SERVER['REQUEST_METHOD'];

try {
    requireConfig($config);
    $pdo = db($config);

    if ($route === 'health') {
        respond(200, ['status' => 'OK', 'runtime' => 'php', 'timestamp' => gmdate('c')]);
    }

    // ---------------------------------------------------------------------------------
    // Blog - public reading endpoints. No auth: these serve the public site, and the
    // queries themselves only ever return published (or due-to-publish) articles.
    // ---------------------------------------------------------------------------------
    if ($route === 'blog/articles' && $method === 'GET') {
        requireModule('blogListPublic', 'lib/blog.php');
        blogRequireTables($pdo);
        respond(200, blogListPublic($pdo, $_GET));
    }

    if ($route === 'blog/featured' && $method === 'GET') {
        requireModule('blogFeatured', 'lib/blog.php');
        blogRequireTables($pdo);
        respond(200, blogFeatured($pdo) ?? []);
    }

    if ($route === 'blog/categories' && $method === 'GET') {
        requireModule('blogCategories', 'lib/blog.php');
        blogRequireTables($pdo);
        respond(200, blogCategories($pdo));
    }

    if (preg_match('#^blog/articles/([A-Za-z0-9\-]+)$#', $route, $m) && $method === 'GET') {
        $row = blogBySlug($pdo, $m[1]);
        if (!$row) respond(404, ['error' => 'Article not found']);
        respond(200, [
            'article' => blogPublicArticle($row, true),
            'related' => blogRelated($pdo, (int)$row['id'], $row['category_id'] !== null ? (int)$row['category_id'] : null),
        ]);
    }

    // ---------------------------------------------------------------------------------
    // Blog - editorial endpoints. Permission is enforced inside lib/blog.php by role, so
    // adding a route here cannot accidentally skip the check.
    // ---------------------------------------------------------------------------------
    if ($route === 'blog/admin/articles' && $method === 'GET') {
        requireModule('blogListAdmin', 'lib/blog.php');
        blogRequireTables($pdo);
        respond(200, blogListAdmin($pdo, auth($config), $_GET));
    }

    if ($route === 'blog/admin/articles' && $method === 'POST') {
        requireModule('blogSaveArticle', 'lib/blog.php');
        blogRequireTables($pdo);
        respond(201, blogSaveArticle($pdo, auth($config), input()));
    }

    // Social ingestion queue. Fetching is deliberately separate from importing: the cron
    // fills the queue, an editor decides what becomes an article.
    if ($route === 'blog/admin/social' && $method === 'GET') {
        requireModule('socialIngestAll', 'lib/social-ingest.php');
        blogRequireTables($pdo);
        $user = auth($config);
        blogRequireEdit($user);
        $status = $_GET['status'] ?? 'new';
        $stmt = $pdo->prepare('SELECT i.*, s.platform, s.label AS source_label
                                 FROM blog_social_items i
                                 JOIN blog_social_sources s ON s.id = i.source_id
                                WHERE i.status = ? ORDER BY i.posted_at DESC LIMIT 100');
        $stmt->execute([$status]);
        respond(200, array_map(fn($r) => [
            'id' => (int)$r['id'],
            'platform' => $r['platform'],
            'source' => $r['source_label'],
            'title' => $r['title'],
            'body' => $r['body'],
            'permalink' => $r['permalink'],
            'media' => $r['media_url'],
            'mediaType' => $r['media_type'],
            'postedAt' => $r['posted_at'],
            'status' => $r['status'],
            'articleId' => $r['article_id'] !== null ? (int)$r['article_id'] : null,
        ], $stmt->fetchAll()));
    }

    if ($route === 'blog/admin/social/refresh' && $method === 'POST') {
        requireModule('socialIngestAll', 'lib/social-ingest.php');
        blogRequireTables($pdo);
        $user = auth($config);
        blogRequireEdit($user);
        respond(200, ['sources' => socialIngestAll($pdo)]);
    }

    if (preg_match('#^blog/admin/social/(\d+)/import$#', $route, $m) && $method === 'POST') {
        respond(201, socialImportItem($pdo, auth($config), (int)$m[1], input()));
    }

    if (preg_match('#^blog/admin/social/(\d+)/ignore$#', $route, $m) && $method === 'POST') {
        $user = auth($config);
        blogRequireEdit($user);
        $pdo->prepare('UPDATE blog_social_items SET status = "ignored", reviewed_by = ?, reviewed_at = NOW() WHERE id = ?')
            ->execute([(int)$user['userId'], (int)$m[1]]);
        respond(200, ['message' => 'Post ignored']);
    }

    if (preg_match('#^blog/admin/articles/(\d+)$#', $route, $m)) {
        $user = auth($config);
        if ($method === 'GET') {
            blogRequireEdit($user);
            $stmt = $pdo->prepare(BLOG_ARTICLE_SELECT . ' WHERE a.id = ? LIMIT 1');
            $stmt->execute([(int)$m[1]]);
            $row = $stmt->fetch();
            if (!$row) respond(404, ['error' => 'Article not found']);
            respond(200, blogAdminArticle($row));
        }
        if ($method === 'PATCH' || $method === 'PUT') {
            respond(200, blogSaveArticle($pdo, $user, input(), (int)$m[1]));
        }
        if ($method === 'DELETE') {
            blogDeleteArticle($pdo, $user, (int)$m[1]);
            respond(200, ['message' => 'Article deleted']);
        }
    }

    // Threat assessment for the admin dashboard. Admin only - it names weaknesses, which is
    // precisely the list an attacker would want, so it is not exposed to other roles.
    // Who am I, and what may I see? The admin panel asks this to decide whether to show
    // the Threat Assessment link and the Administrators panel at all.
    // Invitations. Any admin may invite members - that is membership work, not privilege
    // granting - but the claim endpoint below is public, because the person using it does
    // not have an account yet. That is the whole point of it.
    if ($route === 'invites' && $method === 'GET') {
        $user = auth($config);
        requireAdmin($user);
        requireModule('inviteList', 'lib/invites.php');
        invitesRequire($pdo);
        respond(200, inviteList($pdo));
    }

    if ($route === 'invites' && $method === 'POST') {
        $user = auth($config);
        requireAdmin($user);
        requireModule('inviteCreate', 'lib/invites.php');
        invitesRequire($pdo);

        $data = input();
        $entries = $data['invites'] ?? null;
        if (!is_array($entries) || !$entries) respond(400, ['error' => 'Provide at least one email address.']);
        if (count($entries) > 200) respond(400, ['error' => 'Send at most 200 invitations at a time.']);

        $sent = [];
        $failed = [];
        foreach ($entries as $entry) {
            $email = is_array($entry) ? (string)($entry['email'] ?? '') : (string)$entry;
            $name = is_array($entry) ? (string)($entry['name'] ?? '') : '';
            try {
                $invite = inviteCreate($pdo, $config, $email, $name, (int)$user['userId']);
                if (inviteSend($pdo, $config, $invite)) {
                    $sent[] = $invite['email'];
                } else {
                    $failed[] = ['email' => $invite['email'], 'reason' => 'The email could not be sent.'];
                }
            } catch (Throwable $e) {
                // One bad address must not stop the batch; it is reported back instead.
                $failed[] = ['email' => trim($email), 'reason' => $e->getMessage()];
            }
        }
        logAdminAction($pdo, (int)$user['userId'], 'invites_sent', null, count($sent) . ' sent, ' . count($failed) . ' failed');
        respond(200, ['sent' => $sent, 'failed' => $failed]);
    }

    if (preg_match('#^invites/(\d+)/(resend|revoke)$#', $route, $m) && $method === 'POST') {
        $user = auth($config);
        requireAdmin($user);
        requireModule('inviteSend', 'lib/invites.php');
        invitesRequire($pdo);

        $stmt = $pdo->prepare('SELECT * FROM member_invites WHERE id = ? LIMIT 1');
        $stmt->execute([(int)$m[1]]);
        $invite = $stmt->fetch();
        if (!$invite) respond(404, ['error' => 'Invitation not found.']);

        if ($m[2] === 'revoke') {
            $pdo->prepare('UPDATE member_invites SET revoked_at = NOW() WHERE id = ?')->execute([(int)$invite['id']]);
            respond(200, ['message' => 'Invitation revoked.']);
        }
        // Resending issues a fresh token, so an old forwarded link stops working.
        $refreshed = inviteCreate($pdo, $config, (string)$invite['email'], $invite['name'], (int)$user['userId']);
        respond(200, ['sent' => inviteSend($pdo, $config, $refreshed)]);
    }

    // Public: the holder of a valid invitation has no account yet, so this cannot require
    // one. It answers with the invited email and nothing else, and only for a token that is
    // still claimable - so it reveals nothing about addresses that were never invited.
    if (preg_match('#^invites/claim/([a-f0-9]{64})$#', $route, $m) && $method === 'GET') {
        requireModule('inviteByToken', 'lib/invites.php');
        if (!invitesEnsureTable($pdo)) respond(404, ['error' => 'That invitation link is not valid.']);
        $invite = inviteByToken($pdo, $m[1]);
        if (!$invite) respond(404, ['error' => 'That invitation link has expired or has already been used.']);
        respond(200, ['email' => $invite['email'], 'name' => $invite['name']]);
    }

    if ($route === 'admin/whoami' && $method === 'GET') {
        $user = auth($config);
        requireAdmin($user);
        respond(200, [
            'email' => $user['email'] ?? '',
            'role' => $user['role'] ?? '',
            'isSuperAdmin' => isSuperAdmin($user, $config),
            'superAdminsConfigured' => !empty($config['super_admins']),
        ]);
    }

    // Administrator management. Listing is open to any admin - knowing who else holds the
    // keys is not a secret from the people who hold them - but changing a role is not.
    // Find an account by email so it can be promoted. Super administrator only, and it
    // answers with an id or nothing - never with a list, so it cannot be walked to enumerate
    // who holds an account here.
    // Create an administrator outright, rather than asking them to self-register first.
    // Super administrator only, for the same reason promotion is: an admin who could mint
    // admins could hand out their own level of access.
    if ($route === 'admins' && $method === 'POST') {
        $user = auth($config);
        requireSuperAdmin($user, $config);
        $data = input();

        $email = strtolower(trim((string)($data['email'] ?? '')));
        $name = trim((string)($data['name'] ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) respond(400, ['error' => 'Enter a valid email address.']);

        $stmt = $pdo->prepare('SELECT id, role FROM users WHERE LOWER(email) = ? LIMIT 1');
        $stmt->execute([$email]);
        if ($existing = $stmt->fetch()) {
            respond(409, [
                'error' => $existing['role'] === 'admin'
                    ? 'That email is already an administrator.'
                    : 'That email already has an account. Use "Make admin" instead of creating a new one.',
            ]);
        }

        // Generated rather than chosen by the person creating it: an issued password picked
        // by a colleague tends to be guessable and tends to get reused. Groups of four are
        // for reading aloud or typing from a note, which is how this actually gets handed
        // over. The alphabet omits O/0 and I/l, which is where transcription goes wrong.
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
        $password = '';
        for ($i = 0; $i < 16; $i++) {
            if ($i > 0 && $i % 4 === 0) $password .= '-';
            $password .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        // Guarantee the mix the registration rule requires, so this password stays valid if
        // it is ever put through that validator.
        $password .= 'A' . 'a' . random_int(0, 9);

        $pdo->prepare('INSERT INTO users (email, password_hash, email_verified, role) VALUES (?, ?, 1, "admin")')
            ->execute([$email, password_hash($password, PASSWORD_DEFAULT)]);
        $newId = (int)$pdo->lastInsertId();

        // Verified on creation: a super administrator vouching for the address is a stronger
        // signal than an emailed link, and an unverified admin could not log in at all.
        logAdminAction($pdo, (int)$user['userId'], 'admin_created', null, 'user #' . $newId . ' (' . $email . ')');
        securityLog($pdo, $config, 'admin_created', 'warning', 'admins', $email, (int)$user['userId']);

        // Best effort, and never fatal: the password is handed over by the super admin, so
        // this only tells the person an account exists and where to sign in.
        try {
            $portal = rtrim((string)($config['portal_base_url'] ?? ''), '/') ?: 'https://www.resok.org/resok-portal/public';
            if (class_exists('SimpleMailer')) {
                (new SimpleMailer($config))->send($email, 'Your ReSoK administrator account',
                    "An administrator account has been created for you on the ReSoK members' portal.

"
                    . "Sign in at {$portal}/login

"
                    . "Your password will be given to you separately - it is deliberately not in this email. "
                    . "Please change it after your first sign-in, and turn on two-factor authentication from "
                    . "your profile page.

Respiratory Society of Kenya");
            }
        } catch (Throwable $mailError) {
            error_log('Admin welcome email failed: ' . $mailError->getMessage());
        }

        respond(201, [
            'id' => $newId,
            'email' => $email,
            'name' => $name ?: null,
            'role' => 'admin',
            'password' => $password,
            'notice' => 'This password is shown once. Give it to them directly, and ask them to change it and turn on two-factor.',
        ]);
    }

    if ($route === 'admins/lookup' && $method === 'GET') {
        $user = auth($config);
        requireSuperAdmin($user, $config);
        $email = strtolower(trim((string)($_GET['email'] ?? '')));
        if ($email === '') respond(400, ['error' => 'An email is required.']);
        $stmt = $pdo->prepare("SELECT u.id, u.email, u.role,
                                      TRIM(CONCAT(COALESCE(mp.first_name,''), ' ', COALESCE(mp.surname,''))) AS name
                                 FROM users u
                                 LEFT JOIN member_profiles mp ON mp.user_id = u.id
                                WHERE LOWER(u.email) = ? LIMIT 1");
        $stmt->execute([$email]);
        $row = $stmt->fetch();
        if (!$row) respond(404, ['error' => 'No account is registered with that email.']);
        respond(200, [
            'id' => (int)$row['id'],
            'email' => $row['email'],
            'name' => trim((string)$row['name']) ?: null,
            'role' => $row['role'],
        ]);
    }

    if ($route === 'admins' && $method === 'GET') {
        $user = auth($config);
        requireAdmin($user);
        $rows = $pdo->query("SELECT u.id, u.email, u.role, u.created_at,
                                    TRIM(CONCAT(COALESCE(mp.first_name,''), ' ', COALESCE(mp.surname,''))) AS name
                               FROM users u
                               LEFT JOIN member_profiles mp ON mp.user_id = u.id
                              WHERE u.role = 'admin' ORDER BY u.id")->fetchAll();
        respond(200, array_map(fn($r) => [
            'id' => (int)$r['id'],
            'email' => $r['email'],
            'name' => trim((string)$r['name']) ?: null,
            'role' => $r['role'],
            'isSuperAdmin' => isSuperAdmin(['role' => $r['role'], 'email' => $r['email']], $config),
            'createdAt' => $r['created_at'],
        ], $rows));
    }

    // Promote a member to admin, or demote one back. Super administrator only: an admin who
    // could appoint other admins could hand out their own level of access, which makes the
    // distinction this route exists to enforce meaningless.
    if (preg_match('#^admins/(\d+)/role$#', $route, $m) && $method === 'POST') {
        $user = auth($config);
        requireSuperAdmin($user, $config);

        $targetId = (int)$m[1];
        $role = (string)(input()['role'] ?? '');
        if (!in_array($role, ['member', 'admin'], true)) {
            respond(400, ['error' => 'Role must be member or admin.']);
        }
        if ($targetId === (int)$user['userId']) {
            // Without this a super admin could demote themselves and leave the site with no
            // one able to promote anybody back.
            respond(400, ['error' => 'You cannot change your own role.']);
        }

        $stmt = $pdo->prepare('SELECT id, email, role FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$targetId]);
        $target = $stmt->fetch();
        if (!$target) respond(404, ['error' => 'That account does not exist.']);

        if ($role === 'member' && isSuperAdmin(['role' => $target['role'], 'email' => $target['email']], $config)) {
            respond(400, ['error' => 'That account is named as a super administrator in the server configuration. Remove it there first.']);
        }

        $pdo->prepare('UPDATE users SET role = ? WHERE id = ?')->execute([$role, $targetId]);
        // Target is null: that column holds a member_profile id, and this acts on a user
        // account, which is a different key entirely. The detail carries who was changed.
        logAdminAction($pdo, (int)$user['userId'], 'role_change', null,
            'user #' . $targetId . ' (' . $target['email'] . ') set to ' . $role);
        securityLog($pdo, $config, 'admin_role_changed', 'warning', 'admins',
            $target['email'] . ' set to ' . $role, (int)$user['userId']);
        respond(200, ['id' => $targetId, 'email' => $target['email'], 'role' => $role]);
    }

    // Encrypts rows written before a key existed. Batched and idempotent, so it can be run
    // repeatedly and interrupted without leaving the table half-converted in a way that
    // needs untangling - rows already carrying the marker are skipped.
    if ($route === 'security/encrypt-existing' && $method === 'POST') {
        $user = auth($config);
        requireSuperAdmin($user, $config);
        requireModule('cryptoMigrateColumn', 'lib/crypto.php');

        if (!cryptoAvailable($config)) {
            respond(400, ['error' => 'No data_encryption_key is configured, so there is nothing to encrypt to.']);
        }
        try {
            $ids = cryptoMigrateColumn($pdo, $config, 'member_profiles', 'id', 'id_number');
            $secrets = cryptoMigrateColumn($pdo, $config, 'users', 'id', 'mfa_secret');
        } catch (Throwable $e) {
            respond(500, ['error' => $e->getMessage()]);
        }
        securityLog($pdo, $config, 'encryption_migration', 'info', 'security',
            $ids['encrypted'] . ' id numbers, ' . $secrets['encrypted'] . ' secrets', (int)$user['userId']);
        respond(200, [
            'idNumbers' => $ids,
            'mfaSecrets' => $secrets,
            'message' => 'Run again if either scanned count reached the batch limit of 500.',
        ]);
    }

    if ($route === 'security/assessment' && $method === 'GET') {
        requireModule('securityAssessment', 'lib/security-assessment.php');
        $user = auth($config);
        requireAdmin($user);
        respond(200, securityAssessment($pdo, $config));
    }

    if ($route === 'payment-instructions' && $method === 'GET') {
        // Methods shown to members as planned but not yet accepted. Announcing them is a
        // commitment, so keep this list to things actually being pursued - and move an entry
        // out of here only when its flow genuinely works end to end, never just because the
        // integration exists. M-Pesa Express lists itself here until mpesa_enabled is on.
        // Each tile renders, in order of preference: `logo` (a file under assets/img/payments/),
        // then `icon` (a Font Awesome brand glyph tinted with `color`), then `wordmark` (the
        // name set in the brand's colour). Drop an official SVG from a brand's press kit into
        // assets/img/payments/ and add `logo` to switch that tile to the real mark - which is
        // the right way to show a trademark, rather than approximating one in CSS.
        $comingSoon = [];
        if (!(mpesaEnabled($config) && mpesaConfigured($config))) {
            $comingSoon[] = ['name' => 'M-Pesa Express', 'note' => 'Instant payment prompt on your phone', 'wordmark' => 'M-PESA', 'color' => '#00A651'];
        }
        $comingSoon[] = ['name' => 'Visa', 'note' => 'Debit and credit cards', 'icon' => 'fab fa-cc-visa', 'color' => '#1A1F71'];
        $comingSoon[] = ['name' => 'Mastercard', 'note' => 'Debit and credit cards', 'icon' => 'fab fa-cc-mastercard', 'color' => '#EB001B'];
        $comingSoon[] = ['name' => 'Apple Pay', 'note' => 'Pay from iPhone, iPad, or Mac', 'icon' => 'fab fa-cc-apple-pay', 'color' => '#000000'];
        $comingSoon[] = ['name' => 'Pesapal', 'note' => 'Cards, mobile money, and bank options', 'wordmark' => 'Pesapal', 'color' => '#253141'];
        $comingSoon[] = ['name' => 'Bank transfer', 'note' => 'Interbank transfer and cheque payment', 'icon' => 'fas fa-building-columns', 'color' => '#475467'];

        respond(200, [
            'method' => 'M-Pesa Paybill',
            'paybillNumber' => (string)($config['paybill_number'] ?? ''),
            'accountNumber' => (string)($config['paybill_account'] ?? '2038334878'),
            'amount' => (int)($config['membership_fee'] ?? 5000),
            'currency' => 'KES',
            // M-Pesa only for now. Cheque/interbank was offered here but nothing supported
            // it: the confirmation form asks for an M-Pesa code and a phone number, both
            // required, and the code is validated as ^[A-Z0-9-]{6,24}$, which a bank
            // reference need not match. Adding a mode back means adapting that form first.
            'paymentModes' => ['M-PESA Paybill'],
            'categories' => $config['membership_categories'] ?? [],
            // Lets the portal decide what to offer. Two conditions, deliberately: the keys
            // have to be present AND mpesa_enabled has to be switched on. Credentials alone
            // are not consent - they can be sandbox keys, or leftovers from testing, and
            // neither should put a "Pay Now" button in front of members. Set mpesa_enabled
            // to true in config.local.php once a real STK payment has been tested end to end.
            'stkEnabled' => mpesaEnabled($config) && mpesaConfigured($config),
            'comingSoon' => $comingSoon
        ]);
    }

    if (preg_match('#^profile-images/([^/]+)$#', $route, $m) && $method === 'GET') {
        // A member's photograph is personal data. This route previously served it to anyone
        // who knew the filename, which is not access control - it is a URL that happens to
        // be hard to guess, built around a predictable timestamp. A session is now required,
        // and a member may only fetch their own; admins may fetch any, because the review
        // queue has to display them.
        $viewer = auth($config);
        $filename = basename(rawurldecode($m[1]));

        $owner = $pdo->prepare('SELECT user_id FROM member_profiles WHERE profile_image = ? LIMIT 1');
        $owner->execute([$filename]);
        $ownerRow = $owner->fetch();
        $isOwner = $ownerRow && (int)$ownerRow['user_id'] === (int)$viewer['userId'];
        if (!$isOwner && ($viewer['role'] ?? '') !== 'admin') {
            // Deliberately 404 rather than 403: a 403 confirms the file exists, which tells
            // someone probing filenames that their guess was correct.
            respond(404, ['error' => 'Profile photo not found']);
        }

        $file = rtrim($config['upload_dir'], '/\\') . '/profile-images/' . $filename;
        if (!is_file($file)) respond(404, ['error' => 'Profile photo not found']);

        $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $mimeTypes = [
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp'
        ];
        if (!isset($mimeTypes[$extension])) respond(400, ['error' => 'Unsupported profile photo type']);

        header_remove('Content-Type');
        header('Content-Type: ' . $mimeTypes[$extension]);
        header('Content-Length: ' . filesize($file));
        header('Cache-Control: public, max-age=86400');
        readfile($file);
        exit;
    }

    if ($route === 'setup/admin') {
        if ($method !== 'POST') {
            respond(405, ['error' => 'Use POST to create or update the admin user.']);
        }
        $data = input();
        $setupKey = $config['setup_key'] ?? '';
        $providedKey = $_SERVER['HTTP_X_SETUP_KEY'] ?? ($data['key'] ?? '');
        if (!$setupKey || !hash_equals((string)$setupKey, (string)$providedKey)) {
            respond(403, ['error' => 'Admin setup is disabled']);
        }
        $email = trim((string)($data['email'] ?? ''));
        $password = (string)($data['password'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) respond(400, ['error' => 'Valid email is required']);
        if (!preg_match('/^(?=.*[A-Z])(?=.*[a-z])(?=.*\d).{12,64}$/', $password)) {
            respond(400, ['error' => 'Password must be 12-64 characters and include uppercase, lowercase, and a number']);
        }
        $stmt = $pdo->prepare(
            'INSERT INTO users (email, password_hash, email_verified, role)
             VALUES (?, ?, TRUE, "admin")
             ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash), email_verified = TRUE, role = "admin"'
        );
        $stmt->execute([$email, password_hash($password, PASSWORD_DEFAULT)]);
        respond(200, ['message' => 'Admin user is ready. Remove setup_key from config.php now.']);
    }

    if ($route === 'auth/register' && $method === 'POST') {
        $data = input();
        requireFields($data, ['email', 'password', 'firstName', 'surname', 'mobile', 'country', 'county', 'division', 'profession', 'specialization', 'institution', 'physicalAddress', 'payerType', 'category', 'idNumber']);
        if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) respond(400, ['error' => 'Please enter a valid email address']);
        // Answers as though it worked, so a script learns nothing about which signal caught
        // it. A real member never reaches this: the honeypot is invisible and nobody
        // completes fifteen fields in three seconds.
        botScreenOrFakeSuccess($pdo, $config, $data, 'register', [
            'message' => 'Registration successful! Please check your email to verify your account.',
        ]);

        // Fifteen fields were accepted and four were checked. These are not the security
        // boundary - queries are bound and output escaped - but unbounded input is how a
        // name column ends up holding a kilobyte of pasted text.
        validateInput($data, [
            'firstName'       => ['First name', 'name'],
            'surname'         => ['Surname', 'name'],
            'middleName'      => ['Middle name', 'name', false],
            'title'           => ['Title', 'text', false, ['max' => 20]],
            'mobile'          => ['Mobile number', 'phone'],
            'country'         => ['Country', 'text', true, ['max' => 60]],
            'county'          => ['County', 'text', true, ['max' => 60]],
            'division'        => ['Division', 'text', true, ['max' => 80]],
            'profession'      => ['Profession', 'text', true, ['max' => 100]],
            'specialization'  => ['Specialization', 'text', true, ['max' => 120]],
            'institution'     => ['Institution', 'text', true, ['max' => 160]],
            'physicalAddress' => ['Physical address', 'address'],
            'category'        => ['Membership category', 'text', true, ['max' => 80]],
            'idNumber'        => ['ID number', 'id'],
        ]);
        if (!preg_match('/^\+[1-9]\d{7,14}$/', $data['mobile'])) respond(400, ['error' => 'Please enter a valid mobile number with country code']);
        if (!preg_match('/^(?=.*[A-Z])(?=.*[a-z])(?=.*\d).{8,64}$/', $data['password'])) respond(400, ['error' => 'Password must be 8-64 characters and include uppercase, lowercase, and a number']);

        $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$data['email']]);
        throttleCheck($pdo, $config, 'register', (string)($data['email'] ?? ''));
        if ($stmt->fetch()) {
            throttleFailure($pdo, $config, 'register', (string)($data['email'] ?? ''));
            respond(400, ['error' => 'Email already registered']);
        }

        $verified = empty($config['require_email_verification']) ? 1 : 0;
        $verificationToken = $verified ? null : bin2hex(random_bytes(32));
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('INSERT INTO users (email, password_hash, email_verified, role, verification_token) VALUES (?, ?, ?, "member", ?)');
        $stmt->execute([$data['email'], password_hash($data['password'], PASSWORD_DEFAULT), $verified, $verificationToken]);
        $userId = (int)$pdo->lastInsertId();
        $stmt = $pdo->prepare(
            'INSERT INTO member_profiles (user_id, title, first_name, middle_name, surname, country, county, division, profession, specialization, institution, physical_address, payer_type, category, id_type, id_number, mobile, membership_status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "payment_required")'
        );
        $stmt->execute([
            $userId,
            $data['title'] ?? null,
            $data['firstName'],
            $data['middleName'] ?? null,
            $data['surname'],
            $data['country'],
            $data['county'],
            $data['division'],
            $data['profession'],
            $data['specialization'],
            $data['institution'],
            $data['physicalAddress'],
            $data['payerType'],
            $data['category'],
            $data['idType'] ?? 'ID',
            cryptoEncrypt($config, (string)$data['idNumber']),
            $data['mobile']
        ]);
        $pdo->commit();

        if (!$verified && $verificationToken) {
            try {
                if (!sendVerificationEmail($config, (string)$data['email'], $verificationToken)) {
                    error_log('Verification email to ' . $data['email'] . ' returned false - see SMTP log lines above for the specific stage that failed.');
                }
            } catch (Throwable $mailError) {
                error_log('Verification email threw: ' . $mailError->getMessage());
            }
        }

        // An invited member who completed the form: mark their invitation used so it cannot
        // be claimed twice and the admin list shows who has actually joined. Guarded and
        // never fatal - the account exists at this point, and losing the bookkeeping must
        // not undo a registration that succeeded.
        if (!empty($data['inviteToken']) && function_exists('inviteMarkClaimed')) {
            inviteMarkClaimed($pdo, (string)$data['inviteToken'], $userId);
        }

        $payload = ['message' => $verified ? 'Registration successful.' : 'Registration successful! Please check your email to verify your account before logging in.', 'userId' => $userId, 'requiresVerification' => !$verified];
        if ($verified) {
            $registerToken = token(['userId' => $userId, 'email' => $data['email'], 'role' => 'member'], $config['jwt_secret']);
            issueAuthCookie($registerToken);
            $payload['token'] = $registerToken;
            $payload['user'] = ['id' => $userId, 'email' => $data['email'], 'role' => 'member', 'membershipStatus' => 'payment_required', 'membershipId' => null, 'cpdPoints' => 0];
        }
        respond(201, $payload);
    }

    if ($route === 'auth/logout' && $method === 'POST') {
        clearAuthCookie();
        respond(200, ['message' => 'Logged out']);
    }

    if (preg_match('#^auth/verify/([A-Za-z0-9]+)$#', $route, $m) && $method === 'GET') {
        $loginUrl = rtrim((string)($config['portal_base_url'] ?? ''), '/') . '/login';
        $stmt = $pdo->prepare('SELECT id FROM users WHERE verification_token = ? LIMIT 1');
        $stmt->execute([$m[1]]);
        $user = $stmt->fetch();
        if (!$user) {
            respondHtmlPage(400, 'Invalid or Expired Link', 'This verification link is invalid or has already been used. If you still need to verify your account, try registering again or contact support.', true, 'Go to Login', $loginUrl);
        }
        $pdo->prepare('UPDATE users SET email_verified = 1, verification_token = NULL WHERE id = ?')->execute([(int)$user['id']]);
        respondHtmlPage(200, 'Email Verified', 'Your ReSoK account is now active. You can log in and continue your membership application.', false, 'Log In Now', $loginUrl);
    }

    if ($route === 'auth/login' && $method === 'POST') {
        $data = input();
        $stmt = $pdo->prepare(
            'SELECT u.id, u.email, u.password_hash, u.email_verified, u.role, mp.membership_status, mp.membership_id, mp.cpd_points
             FROM users u LEFT JOIN member_profiles mp ON mp.user_id = u.id WHERE u.email = ? LIMIT 1'
        );
        $loginEmail = (string)($data['email'] ?? '');
        // Screened before the password is even looked at, so an automated run costs nothing
        // and reveals nothing. Answers with the same message a wrong password gets.
        if (botScreen($data, 0) !== null) {   // honeypot only - see input-guard.php
            securityLog($pdo, $config, 'bot_blocked', 'info', 'login', 'Login screened');
            respond(401, ['error' => 'Invalid credentials']);
        }
        authThrottleCheck($pdo, $config, $loginEmail);
        $stmt->execute([$data['email'] ?? '']);
        $user = $stmt->fetch();
        if (!$user || !password_verify($data['password'] ?? '', $user['password_hash'])) {
            authThrottleFailure($pdo, $config, $loginEmail);
            respond(401, ['error' => 'Invalid credentials']);
        }
        if (!$user['email_verified']) respond(403, ['error' => 'Please verify your email before logging in']);
        authThrottleSuccess($pdo, $config, $loginEmail);

        // Second factor. The password is only the first step for anyone whose account can
        // reach member data; the challenge token proves this step passed and nothing more.
        //
        // Read separately rather than joined into the query above. That query must work on a
        // database where the mfa_ columns were never added - if it names a column that does
        // not exist, the SELECT fails and nobody can log in at all, which is how this broke.
        $mfaEnabled = false;
        if (mfaEnsureColumns($pdo)) {
            try {
                $mfaStmt = $pdo->prepare('SELECT mfa_enabled FROM users WHERE id = ? LIMIT 1');
                $mfaStmt->execute([(int)$user['id']]);
                $mfaEnabled = (bool)(int)($mfaStmt->fetch()['mfa_enabled'] ?? 0);
            } catch (Throwable $e) {
                error_log('Could not read two-factor state: ' . $e->getMessage());
            }
        }
        if ($mfaEnabled) {
            // Refuse rather than wave them through: this member enrolled precisely so that
            // a password alone would not be enough.
            requireModule('mfaIssueChallenge', 'lib/mfa.php');
            securityLog($pdo, $config, 'mfa_challenge_issued', 'info', 'login', null, (int)$user['id']);
            respond(200, [
                'mfaRequired' => true,
                'challenge' => mfaIssueChallenge($config, (int)$user['id']),
                'message' => 'Enter the 6-digit code from your authenticator app.',
            ]);
        }
        if (mfaRequiredForRole((string)$user['role'])) {
            // Not a refusal: an admin who has not enrolled yet still gets in, but the portal
            // is told to make them set it up. Locking them out of their own site would be a
            // worse outcome than a short window where the control is pending.
            securityLog($pdo, $config, 'mfa_missing_privileged_login', 'warning', 'login',
                'Privileged account signed in without two-factor enabled', (int)$user['id']);
        }
        $loginToken = token(['userId' => (int)$user['id'], 'email' => $user['email'], 'role' => $user['role']], $config['jwt_secret']);
        issueAuthCookie($loginToken);
        respond(200, [
            'token' => $loginToken,
            'user' => [
                'id' => (int)$user['id'],
                'email' => $user['email'],
                'role' => $user['role'],
                'membershipStatus' => $user['membership_status'],
                'membershipId' => $user['membership_id'],
                'cpdPoints' => (int)($user['cpd_points'] ?? 0)
            ]
        ]);
    }

    // Second factor: exchange a challenge token plus a code for a session.
    if ($route === 'auth/mfa/verify' && $method === 'POST') {
        requireModule('mfaVerifyChallenge', 'lib/mfa.php');
        $data = input();
        $userId = mfaVerifyChallenge($config, (string)($data['challenge'] ?? ''));
        if (!$userId) respond(401, ['error' => 'That sign-in attempt expired. Please log in again.']);

        // The code is guessable in six digits, so it is rate limited harder than a password.
        throttleCheck($pdo, $config, 'login', 'mfa:' . $userId);
        if (!mfaEnsureColumns($pdo)) respond(503, ['error' => 'Two-factor authentication is unavailable on this server: its columns are missing and could not be created. Import resok-portal/server/schema-security.sql.']);
        $stmt = $pdo->prepare('SELECT u.id, u.email, u.role, u.mfa_secret, u.mfa_recovery, mp.membership_status, mp.membership_id, mp.cpd_points
                                 FROM users u LEFT JOIN member_profiles mp ON mp.user_id = u.id WHERE u.id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        if (!$user) respond(401, ['error' => 'That sign-in attempt is no longer valid.']);

        $code = (string)($data['code'] ?? '');
        $ok = mfaVerifyCode((string)cryptoDecrypt($config, $user['mfa_secret']), $code);
        $usedRecovery = false;
        if (!$ok && strlen(trim($code)) >= 8) {
            $ok = mfaConsumeRecoveryCode($pdo, (int)$user['id'], (string)$user['mfa_recovery'], $code);
            $usedRecovery = $ok;
        }
        if (!$ok) {
            throttleFailure($pdo, $config, 'login', 'mfa:' . $userId);
            securityLog($pdo, $config, 'mfa_failed', 'warning', 'login', null, (int)$user['id']);
            respond(401, ['error' => 'That code was not correct.']);
        }
        throttleSuccess($pdo, $config, 'login', 'mfa:' . $userId);
        securityLog($pdo, $config, $usedRecovery ? 'mfa_recovery_used' : 'mfa_success',
            $usedRecovery ? 'warning' : 'info', 'login', null, (int)$user['id']);

        $loginToken = token(['userId' => (int)$user['id'], 'email' => $user['email'], 'role' => $user['role']], $config['jwt_secret']);
        issueAuthCookie($loginToken);
        respond(200, [
            'token' => $loginToken,
            'usedRecoveryCode' => $usedRecovery,
            'user' => [
                'id' => (int)$user['id'],
                'email' => $user['email'],
                'role' => $user['role'],
                'membershipStatus' => $user['membership_status'],
                'membershipId' => $user['membership_id'],
                'cpdPoints' => (int)($user['cpd_points'] ?? 0)
            ]
        ]);
    }

    // Enrolment. The secret is generated but not activated until a code proves the app
    // holds it - otherwise a mistyped setup locks the member out of their own account.
    if ($route === 'auth/mfa/setup' && $method === 'POST') {
        requireModule('mfaGenerateSecret', 'lib/mfa.php');
        $user = auth($config);
        if (!mfaEnsureColumns($pdo)) respond(503, ['error' => 'Two-factor authentication is unavailable on this server: its columns are missing and could not be created. Import resok-portal/server/schema-security.sql.']);
        $secret = mfaGenerateSecret();
        $pdo->prepare('UPDATE users SET mfa_secret = ?, mfa_enabled = 0 WHERE id = ?')
            ->execute([cryptoEncrypt($config, $secret), (int)$user['userId']]);
        respond(200, [
            'secret' => $secret,
            'uri' => mfaProvisioningUri($secret, (string)$user['email']),
        ]);
    }

    if ($route === 'auth/mfa/enable' && $method === 'POST') {
        requireModule('mfaGenerateSecret', 'lib/mfa.php');
        $user = auth($config);
        if (!mfaEnsureColumns($pdo)) respond(503, ['error' => 'Two-factor authentication is unavailable on this server: its columns are missing and could not be created. Import resok-portal/server/schema-security.sql.']);
        $stmt = $pdo->prepare('SELECT mfa_secret FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([(int)$user['userId']]);
        $row = $stmt->fetch();
        if (!$row || empty($row['mfa_secret'])) respond(400, ['error' => 'Start the setup again.']);
        if (!mfaVerifyCode((string)cryptoDecrypt($config, $row['mfa_secret']), (string)(input()['code'] ?? ''))) {
            securityLog($pdo, $config, 'mfa_enrol_failed', 'info', 'mfa', null, (int)$user['userId']);
            respond(400, ['error' => 'That code was not correct. Check your app and try again.']);
        }
        $recovery = mfaGenerateRecoveryCodes();
        $pdo->prepare('UPDATE users SET mfa_enabled = 1, mfa_enrolled_at = NOW(), mfa_recovery = ? WHERE id = ?')
            ->execute([$recovery['hashed'], (int)$user['userId']]);
        securityLog($pdo, $config, 'mfa_enabled', 'info', 'mfa', null, (int)$user['userId']);
        respond(200, ['enabled' => true, 'recoveryCodes' => $recovery['plain']]);
    }

    // Turning it off requires the password again: a hijacked session must not be able to
    // quietly remove the control that would have stopped it.
    if ($route === 'auth/mfa/disable' && $method === 'POST') {
        requireModule('mfaGenerateSecret', 'lib/mfa.php');
        $user = auth($config);
        if (!mfaEnsureColumns($pdo)) respond(503, ['error' => 'Two-factor authentication is unavailable on this server: its columns are missing and could not be created. Import resok-portal/server/schema-security.sql.']);
        $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([(int)$user['userId']]);
        $row = $stmt->fetch();
        if (!$row || !password_verify((string)(input()['password'] ?? ''), (string)$row['password_hash'])) {
            securityLog($pdo, $config, 'mfa_disable_refused', 'warning', 'mfa', 'Wrong password', (int)$user['userId']);
            respond(401, ['error' => 'That password was not correct.']);
        }
        $pdo->prepare('UPDATE users SET mfa_enabled = 0, mfa_secret = NULL, mfa_recovery = NULL WHERE id = ?')
            ->execute([(int)$user['userId']]);
        securityLog($pdo, $config, 'mfa_disabled', 'warning', 'mfa', null, (int)$user['userId']);
        respond(200, ['enabled' => false]);
    }

    if ($route === 'auth/mfa/status' && $method === 'GET') {
        requireModule('mfaGenerateSecret', 'lib/mfa.php');
        $user = auth($config);
        // Reported as simply "off" when the columns are absent, rather than failing. This
        // is what the security page calls on load; a page whose job is to report state
        // should say "unavailable", not break.
        if (!mfaEnsureColumns($pdo)) {
            respond(200, [
                'enabled' => false, 'enrolledAt' => null, 'recoveryRemaining' => 0,
                'available' => false,
                'requiredForRole' => mfaRequiredForRole((string)($user['role'] ?? 'member')),
            ]);
        }
        $stmt = $pdo->prepare('SELECT mfa_enabled, mfa_enrolled_at, mfa_recovery FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([(int)$user['userId']]);
        $row = $stmt->fetch() ?: [];
        $remaining = json_decode((string)($row['mfa_recovery'] ?? '[]'), true);
        respond(200, [
            'enabled' => (bool)(int)($row['mfa_enabled'] ?? 0),
            'enrolledAt' => $row['mfa_enrolled_at'] ?? null,
            'recoveryRemaining' => is_array($remaining) ? count($remaining) : 0,
            'requiredForRole' => mfaRequiredForRole((string)($user['role'] ?? 'member')),
        ]);
    }

    // Resend a verification email. Without this, a member whose verification landed in spam
    // is permanently stuck: they cannot sign in because they are unverified, and they cannot
    // register again because the address is taken.
    if ($route === 'auth/resend-verification' && $method === 'POST') {
        $data = input();
        $email = strtolower(trim((string)($data['email'] ?? '')));
        if ($email === '') respond(400, ['error' => 'An email address is required.']);

        // Costs an email every time, so it is throttled like the reset endpoint, and the
        // reply is identical either way - this must not become a way to test which addresses
        // are registered.
        throttleCheck($pdo, $config, 'password-reset', $email);
        throttleFailure($pdo, $config, 'password-reset', $email);

        $stmt = $pdo->prepare('SELECT id, email_verified FROM users WHERE LOWER(email) = ? LIMIT 1');
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && !(int)$user['email_verified']) {
            // A fresh token each time, so an older link stops working.
            $token = bin2hex(random_bytes(32));
            $pdo->prepare('UPDATE users SET verification_token = ? WHERE id = ?')->execute([$token, (int)$user['id']]);
            try {
                if (function_exists('sendVerificationEmail')) sendVerificationEmail($config, $email, $token);
            } catch (Throwable $e) {
                error_log('Resend verification failed: ' . $e->getMessage());
            }
            securityLog($pdo, $config, 'verification_resent', 'info', 'register', null, (int)$user['id']);
        }

        respond(200, ['message' => 'If that address needs verifying, a new link is on its way.']);
    }

    if ($route === 'auth/forgot-password' && $method === 'POST') {
        $data = input();
        if (empty($data['email'])) respond(400, ['error' => 'Email is required']);
        botScreenOrFakeSuccess($pdo, $config, $data, 'password-reset', [
            'message' => 'If the email exists, a reset link has been queued.',
        ], 0);   // honeypot only - one field, often autofilled

        // Every request here sends mail, so each one consumes budget whether or not the
        // address exists - this endpoint is the classic way to mailbomb someone, and the
        // reply is deliberately identical either way so it cannot be used to test emails.
        throttleCheck($pdo, $config, 'password-reset', (string)$data['email']);
        throttleFailure($pdo, $config, 'password-reset', (string)$data['email']);
        $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$data['email']]);
        $user = $stmt->fetch();
        if ($user) {
            $resetToken = bin2hex(random_bytes(32));
            $expires = date('Y-m-d H:i:s', time() + 3600);
            $pdo->prepare('UPDATE users SET reset_token = ?, reset_expires = ? WHERE id = ?')->execute([$resetToken, $expires, (int)$user['id']]);
            $baseUrl = rtrim((string)($config['portal_base_url'] ?? ''), '/');
            if ($baseUrl !== '') {
                $resetUrl = $baseUrl . '/forgot-password?token=' . rawurlencode($resetToken);
                $sent = sendPortalMail(
                    $config,
                    (string)$data['email'],
                    'Reset your ReSoK members portal password',
                    "Use this link to reset your ReSoK members portal password:\n\n{$resetUrl}\n\nThis link expires in 1 hour. If you did not request this, you can ignore this email."
                );
                if (!$sent) error_log('Password reset email could not be sent.');
            } else {
                error_log('Password reset token generated, but portal_base_url is not configured.');
            }
        }
        securityLog($pdo, $config, 'password_reset_requested', 'info', 'password-reset');
        respond(200, ['message' => 'If the email exists, a reset link has been queued.']);
    }

    if ($route === 'auth/reset-password' && $method === 'POST') {
        $data = input();
        if (empty($data['token']) || empty($data['password'])) respond(400, ['error' => 'Token and password are required']);
        if (!preg_match('/^(?=.*[A-Z])(?=.*[a-z])(?=.*\d).{8,64}$/', $data['password'])) respond(400, ['error' => 'Password must be 8-64 characters and include uppercase, lowercase, and a number']);
        $stmt = $pdo->prepare('SELECT id FROM users WHERE reset_token = ? AND reset_expires > NOW() LIMIT 1');
        $stmt->execute([$data['token']]);
        $user = $stmt->fetch();
        if (!$user) respond(400, ['error' => 'Invalid or expired reset link']);
        $pdo->prepare('UPDATE users SET password_hash = ?, reset_token = NULL, reset_expires = NULL WHERE id = ?')->execute([password_hash($data['password'], PASSWORD_DEFAULT), (int)$user['id']]);
        respond(200, ['message' => 'Password updated. You can now log in.']);
    }

    if ($route === 'members/me') {
        $user = auth($config);
        if ($method === 'GET') respond(200, mapMember(memberRow($pdo, (int)$user['userId'])) ?? []);
        if ($method === 'PATCH') {
            $data = input();
            $allowed = ['title' => 'title', 'firstName' => 'first_name', 'middleName' => 'middle_name', 'surname' => 'surname', 'country' => 'country', 'county' => 'county', 'division' => 'division', 'profession' => 'profession', 'specialization' => 'specialization', 'institution' => 'institution', 'physicalAddress' => 'physical_address', 'payerType' => 'payer_type', 'category' => 'category', 'idType' => 'id_type', 'idNumber' => 'id_number', 'mobile' => 'mobile'];
            $sets = [];
            $values = [];
            foreach ($allowed as $key => $column) {
                if (array_key_exists($key, $data)) {
                    $sets[] = "$column = ?";
                    // The ID number is encrypted at rest, so an edit must be written
                    // in the same form or the column would end up half plaintext.
                    $values[] = $column === 'id_number'
                        ? cryptoEncrypt($config, (string)($data[$key] ?: ''))
                        : ($data[$key] ?: null);
                }
            }
            if (!$sets) respond(400, ['error' => 'No profile fields provided']);
            $values[] = (int)$user['userId'];
            $pdo->prepare('UPDATE member_profiles SET ' . implode(', ', $sets) . ' WHERE user_id = ?')->execute($values);
            respond(200, mapMember(memberRow($pdo, (int)$user['userId'])) ?? []);
        }
    }

    /**
     * The public listing. No login - this is what the marketing site renders, and the whole
     * point is that a doctor who is not yet a member can find a CME and come to it.
     *
     * Returns upcoming by default; ?include=past adds the finished ones for an archive view.
     */
    // ----- Database schema ----------------------------------------------------------------

    /**
     * What has been applied and what has not. Super administrator only: this reveals the
     * shape of the database, and it is the screen that can change it.
     */
    if ($route === 'admin/migrations' && $method === 'GET') {
        $user = auth($config);
        requireSuperAdmin($user, $config);
        requireModule('migrateStatus', 'lib/migrate.php');
        respond(200, ['migrations' => migrateStatus($pdo)]);
    }

    /**
     * Applies one file by name, or every pending schema file when none is named.
     *
     * The filename is checked against the directory listing rather than trusted, so this
     * cannot be pointed at anything that is not already one of these files. Nothing about
     * the SQL itself comes from the request.
     */
    if ($route === 'admin/migrations' && $method === 'POST') {
        $user = auth($config);
        requireSuperAdmin($user, $config);
        requireModule('migrateApply', 'lib/migrate.php');

        $file = trim((string)(input()['file'] ?? ''));
        $results = $file !== ''
            ? [migrateApply($pdo, $file, (int)$user['userId'])]
            : migrateApplyPending($pdo, (int)$user['userId']);

        $failed = array_values(array_filter($results, fn($r) => $r['errors'] !== []));
        $applied = count($results) - count($failed);

        logAdminAction($pdo, (int)$user['userId'], 'schema_applied', null,
                       $applied . ' file(s), ' . count($failed) . ' with errors');
        securityLog($pdo, $config, 'schema_applied', 'warning', 'admin',
                    $applied . ' file(s)', (int)$user['userId']);

        respond($failed ? 207 : 200, [
            'results'   => $results,
            'applied'   => $applied,
            'failed'    => count($failed),
            'migrations'=> migrateStatus($pdo),
        ]);
    }

    // ----- ICT: helpdesk ---------------------------------------------------------------------

    /**
     * Raising a ticket needs no ICT permission at all - anyone signed in can report a
     * problem. That is the whole point: a helpdesk harder to use than a WhatsApp message
     * gets a WhatsApp message instead, and then shows an empty queue while the real requests
     * arrive somewhere nobody can measure.
     */
    if ($route === 'ict/tickets' && $method === 'POST') {
        $user = auth($config);
        requireModule('ictTicketCreate', 'lib/ict-tickets.php');

        // Rate limited on the same machinery as everything else, so a script cannot flood
        // the queue - but generously, because a bad morning genuinely produces three tickets.
        throttleCheck($pdo, $config, 'ticket', (string)($user['email'] ?? ''));

        [$ticket, $errors] = ictTicketCreate($pdo, input(), $user);
        if ($errors) {
            respond(400, ['error' => $errors['_'] ?? reset($errors), 'fields' => $errors]);
        }
        throttleSuccess($pdo, $config, 'ticket', (string)($user['email'] ?? ''));
        ictAudit($pdo, (int)$user['userId'], 'ticket_raised', 'ticket', (string)$ticket['id'],
                 $ticket['reference'] . ': ' . $ticket['subject']);
        respond(201, ['ticket' => $ticket]);
    }

    /** Who a ticket can be assigned to. Declared before the numeric route below, because
     *  "assignees" would otherwise be read as a ticket id. */
    if ($route === 'ict/tickets/assignees' && $method === 'GET') {
        $user = auth($config);
        requireModule('ictTicketsList', 'lib/ict-tickets.php');
        ictRequire($pdo, $user, $config, 'tickets.manage');

        $rows = $pdo->query("SELECT u.id, u.email FROM users u
                              WHERE u.role IN ('ict','admin')
                                 OR u.id IN (SELECT user_id FROM ict_capabilities
                                              WHERE capability = 'tickets.manage')
                              ORDER BY u.email")->fetchAll();
        respond(200, ['assignees' => array_map(fn($r) => [
            'id' => (int)$r['id'], 'email' => $r['email'],
        ], $rows)]);
    }

    /**
     * The queue for ICT staff, or your own tickets if you are not one. Both are the same
     * route deliberately - a member should not have to know a different address to see what
     * they reported.
     */
    if ($route === 'ict/tickets' && $method === 'GET') {
        $user = auth($config);
        requireModule('ictTicketsList', 'lib/ict-tickets.php');

        $isStaff = ictCan($pdo, $user, $config, 'tickets.view');
        $filters = [
            'status' => (string)($_GET['status'] ?? ''),
            'search' => trim((string)($_GET['search'] ?? '')),
        ];
        if (!$isStaff) {
            $filters['requester'] = (int)$user['userId'];
        } elseif (!empty($_GET['mine'])) {
            $filters['assignee'] = (int)$user['userId'];
        }

        respond(200, [
            'tickets'    => ictTicketsList($pdo, $filters),
            'summary'    => $isStaff ? ictTicketsSummary($pdo) : null,
            'isStaff'    => $isStaff,
            'categories' => ICT_TICKET_CATEGORIES,
            'priorities' => ICT_TICKET_PRIORITIES,
            'statuses'   => ICT_TICKET_STATUSES,
        ]);
    }

    if (preg_match('#^ict/tickets/(\d+)$#', $route, $m) && $method === 'GET') {
        $user = auth($config);
        requireModule('ictTicketFind', 'lib/ict-tickets.php');

        $isStaff = ictCan($pdo, $user, $config, 'tickets.view');
        $ticket = ictTicketFind($pdo, (int)$m[1], $isStaff);
        if (!$ticket) respond(404, ['error' => 'No such ticket.']);

        // Someone who is neither staff nor the person who raised it has no business reading
        // it - a ticket can describe an account problem or a security incident.
        if (!$isStaff && $ticket['requesterEmail'] !== null
            && strcasecmp($ticket['requesterEmail'], (string)$user['email']) !== 0) {
            respond(403, ['error' => 'That ticket is not yours.']);
        }
        respond(200, ['ticket' => $ticket, 'isStaff' => $isStaff]);
    }

    /**
     * Working a ticket. Staff may change anything; the requester may only add a comment,
     * because chasing your own ticket is legitimate and raising its priority is not.
     */
    if (preg_match('#^ict/tickets/(\d+)$#', $route, $m) && in_array($method, ['PATCH', 'PUT'], true)) {
        $user = auth($config);
        requireModule('ictTicketUpdate', 'lib/ict-tickets.php');

        $existing = ictTicketFind($pdo, (int)$m[1]);
        if (!$existing) respond(404, ['error' => 'No such ticket.']);

        $data = input();
        if (!ictCan($pdo, $user, $config, 'tickets.manage')) {
            $isOwner = $existing['requesterEmail'] !== null
                && strcasecmp($existing['requesterEmail'], (string)$user['email']) === 0;
            if (!$isOwner) {
                respond(403, ['error' => 'You do not have the ICT permission needed for this.',
                              'capability' => 'tickets.manage']);
            }
            // Everything except a comment is dropped rather than refused, so adding a note
            // still works and nothing silently takes effect that should not.
            $data = ['comment' => (string)($data['comment'] ?? '')];
            if (trim($data['comment']) === '') {
                respond(400, ['error' => 'Add a comment to update your ticket.']);
            }
        }

        [$ticket, $error] = ictTicketUpdate($pdo, (int)$m[1], $data, $user);
        if ($error) respond(400, ['error' => $error]);
        ictAudit($pdo, (int)$user['userId'], 'ticket_updated', 'ticket', (string)$ticket['id'],
                 $ticket['reference'] . ': ' . $ticket['status']);
        respond(200, ['ticket' => $ticket]);
    }

    // ----- ICT: software and licences --------------------------------------------------------

    if ($route === 'ict/licenses' && $method === 'GET') {
        $user = auth($config);
        requireModule('ictLicensesList', 'lib/ict-licenses.php');
        ictRequire($pdo, $user, $config, 'licenses.view');
        respond(200, [
            'licenses' => ictLicensesList($pdo),
            'summary'  => ictLicensesSummary($pdo),
            'kinds'    => ICT_LICENSE_KINDS,
        ]);
    }

    if ($route === 'ict/licenses' && $method === 'POST') {
        $user = auth($config);
        requireModule('ictLicenseCreate', 'lib/ict-licenses.php');
        ictRequire($pdo, $user, $config, 'licenses.manage');

        [$licence, $errors] = ictLicenseCreate($pdo, input(), (int)$user['userId']);
        if ($errors) {
            respond(400, ['error' => $errors['_'] ?? 'Please check the highlighted fields.', 'fields' => $errors]);
        }
        ictAudit($pdo, (int)$user['userId'], 'license_added', 'license', (string)$licence['id'],
                 $licence['name'], null, ['name' => $licence['name'], 'seats' => $licence['seatsTotal']]);
        respond(201, ['license' => $licence]);
    }

    if (preg_match('#^ict/licenses/(\d+)$#', $route, $m) && $method === 'GET') {
        $user = auth($config);
        requireModule('ictLicenseFind', 'lib/ict-licenses.php');
        ictRequire($pdo, $user, $config, 'licenses.view');
        $licence = ictLicenseFind($pdo, (int)$m[1]);
        if (!$licence) respond(404, ['error' => 'No such licence.']);
        respond(200, ['license' => $licence]);
    }

    if (preg_match('#^ict/licenses/(\d+)$#', $route, $m) && in_array($method, ['PATCH', 'PUT'], true)) {
        $user = auth($config);
        requireModule('ictLicenseUpdate', 'lib/ict-licenses.php');
        ictRequire($pdo, $user, $config, 'licenses.manage');

        [$licence, $errors, $before] = ictLicenseUpdate($pdo, (int)$m[1], input());
        if ($errors) {
            respond(400, ['error' => $errors['_'] ?? 'Please check the highlighted fields.', 'fields' => $errors]);
        }
        [$was, $now] = ictDiff($before ?: [], $licence);
        $flat = fn(array $a) => array_filter($a, fn($v) => !is_array($v));
        ictAudit($pdo, (int)$user['userId'], 'license_updated', 'license', (string)$licence['id'],
                 $licence['name'], $flat($was), $flat($now));
        respond(200, ['license' => $licence]);
    }

    if (preg_match('#^ict/licenses/(\d+)/seats$#', $route, $m) && $method === 'POST') {
        $user = auth($config);
        requireModule('ictLicenseAssignSeat', 'lib/ict-licenses.php');
        ictRequire($pdo, $user, $config, 'licenses.manage');

        [$licence, $error] = ictLicenseAssignSeat($pdo, (int)$m[1], input(), (int)$user['userId']);
        if ($error) respond(400, ['error' => $error]);
        ictAudit($pdo, (int)$user['userId'], 'license_seat_assigned', 'license', (string)$licence['id'],
                 $licence['name'] . ': ' . $licence['seatsUsed'] . ' of ' . ($licence['seatsTotal'] ?: 'unlimited'));
        respond(200, ['license' => $licence]);
    }

    if (preg_match('#^ict/licenses/seats/(\d+)$#', $route, $m) && $method === 'DELETE') {
        $user = auth($config);
        requireModule('ictLicenseReleaseSeat', 'lib/ict-licenses.php');
        ictRequire($pdo, $user, $config, 'licenses.manage');

        [$licence, $error] = ictLicenseReleaseSeat($pdo, (int)$m[1], (int)$user['userId']);
        if ($error) respond(400, ['error' => $error]);
        ictAudit($pdo, (int)$user['userId'], 'license_seat_released', 'license', (string)$licence['id'],
                 $licence['name'] . ': ' . $licence['seatsUsed'] . ' now in use');
        respond(200, ['license' => $licence]);
    }

    // ----- ICT: credential register ---------------------------------------------------------

    if ($route === 'ict/credentials' && $method === 'GET') {
        $user = auth($config);
        requireModule('ictCredentialsList', 'lib/ict-credentials.php');
        ictRequire($pdo, $user, $config, 'credentials.view');
        respond(200, [
            'credentials' => ictCredentialsList($pdo),
            'summary'     => ictCredentialsSummary($pdo),
            'kinds'       => ICT_CREDENTIAL_KINDS,
        ]);
    }

    if ($route === 'ict/credentials' && $method === 'POST') {
        $user = auth($config);
        requireModule('ictCredentialCreate', 'lib/ict-credentials.php');
        ictRequire($pdo, $user, $config, 'credentials.manage');

        [$credential, $errors] = ictCredentialCreate($pdo, input(), (int)$user['userId']);
        if ($errors) {
            respond(400, ['error' => $errors['_'] ?? 'Please check the highlighted fields.', 'fields' => $errors]);
        }
        ictAudit($pdo, (int)$user['userId'], 'credential_added', 'credential', (string)$credential['id'],
                 $credential['name'], null, ['name' => $credential['name'], 'kind' => $credential['kind']]);
        respond(201, ['credential' => $credential]);
    }

    if (preg_match('#^ict/credentials/(\d+)$#', $route, $m) && in_array($method, ['PATCH', 'PUT'], true)) {
        $user = auth($config);
        requireModule('ictCredentialUpdate', 'lib/ict-credentials.php');
        ictRequire($pdo, $user, $config, 'credentials.manage');

        [$credential, $errors, $before] = ictCredentialUpdate($pdo, (int)$m[1], input());
        if ($errors) {
            respond(400, ['error' => $errors['_'] ?? 'Please check the highlighted fields.', 'fields' => $errors]);
        }
        [$was, $now] = ictDiff($before ?: [], $credential);
        $flat = fn(array $a) => array_filter($a, fn($v) => !is_array($v));
        ictAudit($pdo, (int)$user['userId'], 'credential_updated', 'credential', (string)$credential['id'],
                 $credential['name'], $flat($was), $flat($now));
        respond(200, ['credential' => $credential]);
    }

    /**
     * Records that someone went to collect a credential and returns where it is kept.
     *
     * Reading the register is not sensitive - it holds nothing secret. Following the link to
     * the password manager is, so that is what gets logged.
     */
    if (preg_match('#^ict/credentials/(\d+)/open$#', $route, $m) && $method === 'POST') {
        $user = auth($config);
        requireModule('ictCredentialOpen', 'lib/ict-credentials.php');
        ictRequire($pdo, $user, $config, 'credentials.view');

        [$where, $error] = ictCredentialOpen($pdo, (int)$m[1], (int)$user['userId'],
                                             (string)(input()['reason'] ?? ''));
        if ($error) respond(404, ['error' => $error]);
        securityLog($pdo, $config, 'credential_opened', 'warning', 'ict',
                    $where['name'], (int)$user['userId']);
        respond(200, $where);
    }

    if ($route === 'ict/credentials/access-log' && $method === 'GET') {
        $user = auth($config);
        requireModule('ictCredentialAccessLog', 'lib/ict-credentials.php');
        ictRequire($pdo, $user, $config, 'credentials.manage');
        respond(200, ['entries' => ictCredentialAccessLog($pdo, null, 60)]);
    }

    // ----- ICT: assets --------------------------------------------------------------------

    if ($route === 'ict/assets' && $method === 'GET') {
        $user = auth($config);
        requireModule('ictAssetsList', 'lib/ict-assets.php');
        ictRequire($pdo, $user, $config, 'assets.view');
        respond(200, [
            'assets'  => ictAssetsList($pdo, [
                'search'   => trim((string)($_GET['search'] ?? '')),
                'status'   => (string)($_GET['status'] ?? ''),
                'category' => (string)($_GET['category'] ?? ''),
            ]),
            'summary'    => ictAssetsSummary($pdo),
            'categories' => ICT_ASSET_CATEGORIES,
        ]);
    }

    if ($route === 'ict/assets' && $method === 'POST') {
        $user = auth($config);
        requireModule('ictAssetCreate', 'lib/ict-assets.php');
        ictRequire($pdo, $user, $config, 'assets.manage');

        [$asset, $errors] = ictAssetCreate($pdo, input(), (int)$user['userId']);
        if ($errors) {
            respond(400, ['error' => $errors['_'] ?? 'Please check the highlighted fields.', 'fields' => $errors]);
        }
        ictAudit($pdo, (int)$user['userId'], 'asset_added', 'asset', (string)$asset['id'],
                 $asset['assetTag'] . ' ' . $asset['name'], null,
                 ['assetTag' => $asset['assetTag'], 'name' => $asset['name']]);
        respond(201, ['asset' => $asset]);
    }

    /** Everything one person is holding - what to run before somebody leaves. */
    if ($route === 'ict/assets/held-by' && $method === 'GET') {
        $user = auth($config);
        requireModule('ictAssetsHeldBy', 'lib/ict-assets.php');
        ictRequire($pdo, $user, $config, 'assets.view');
        respond(200, ['assets' => ictAssetsHeldBy($pdo, (string)($_GET['email'] ?? ''))]);
    }

    /**
     * One asset with its whole history. This is what a QR scan opens, so it accepts either
     * the numeric id or the tag printed on the sticker.
     */
    if (preg_match('#^ict/assets/([A-Za-z0-9-]+)$#', $route, $m) && $method === 'GET') {
        $user = auth($config);
        requireModule('ictAssetFind', 'lib/ict-assets.php');
        ictRequire($pdo, $user, $config, 'assets.view');

        $asset = ctype_digit($m[1])
            ? ictAssetFind($pdo, (int)$m[1])
            : ictAssetFindByTag($pdo, $m[1]);
        if (!$asset) respond(404, ['error' => 'No asset with that tag or id.']);
        respond(200, ['asset' => $asset]);
    }

    if (preg_match('#^ict/assets/(\d+)$#', $route, $m) && in_array($method, ['PATCH', 'PUT'], true)) {
        $user = auth($config);
        requireModule('ictAssetUpdate', 'lib/ict-assets.php');
        ictRequire($pdo, $user, $config, 'assets.manage');

        [$asset, $errors, $before] = ictAssetUpdate($pdo, (int)$m[1], input());
        if ($errors) {
            respond(400, ['error' => $errors['_'] ?? 'Please check the highlighted fields.', 'fields' => $errors]);
        }
        [$was, $now] = ictDiff($before ?: [], $asset);
        // Nested arrays are the holder and the history, not fields anyone edited here.
        $flat = fn(array $a) => array_filter($a, fn($v) => !is_array($v));
        ictAudit($pdo, (int)$user['userId'], 'asset_updated', 'asset', (string)$asset['id'],
                 $asset['assetTag'], $flat($was), $flat($now));
        respond(200, ['asset' => $asset]);
    }

    // ----- ICT: assignment ------------------------------------------------------------------

    if (preg_match('#^ict/assets/(\d+)/assign$#', $route, $m) && $method === 'POST') {
        $user = auth($config);
        requireModule('ictAssetAssign', 'lib/ict-assets.php');
        ictRequire($pdo, $user, $config, 'assets.assign');

        [$asset, $error] = ictAssetAssign($pdo, (int)$m[1], input(), (int)$user['userId']);
        if ($error) respond(400, ['error' => $error]);
        ictAudit($pdo, (int)$user['userId'], 'asset_assigned', 'asset', (string)$asset['id'],
                 $asset['assetTag'] . ' to ' . $asset['holder']['name'],
                 null, ['holder' => $asset['holder']['name']]);
        respond(200, ['asset' => $asset]);
    }

    if (preg_match('#^ict/assets/(\d+)/return$#', $route, $m) && $method === 'POST') {
        $user = auth($config);
        requireModule('ictAssetReturn', 'lib/ict-assets.php');
        ictRequire($pdo, $user, $config, 'assets.assign');

        $was = ictAssetFind($pdo, (int)$m[1]);
        [$asset, $error] = ictAssetReturn($pdo, (int)$m[1], input(), (int)$user['userId']);
        if ($error) respond(400, ['error' => $error]);
        ictAudit($pdo, (int)$user['userId'], 'asset_returned', 'asset', (string)$asset['id'],
                 $asset['assetTag'] . ' from ' . ($was['holder']['name'] ?? 'unknown'),
                 ['condition' => $was['condition'] ?? null], ['condition' => $asset['condition']]);
        respond(200, ['asset' => $asset]);
    }

    // ----- ICT: maintenance -----------------------------------------------------------------

    if (preg_match('#^ict/assets/(\d+)/maintenance$#', $route, $m) && $method === 'POST') {
        $user = auth($config);
        requireModule('ictMaintenanceAdd', 'lib/ict-assets.php');
        ictRequire($pdo, $user, $config, 'maintenance.manage');

        [$asset, $error] = ictMaintenanceAdd($pdo, (int)$m[1], input(), (int)$user['userId']);
        if ($error) respond(400, ['error' => $error]);
        ictAudit($pdo, (int)$user['userId'], 'maintenance_recorded', 'asset', (string)$asset['id'],
                 $asset['assetTag'] . ': ' . ($asset['maintenance'][0]['kind'] ?? 'repair'));
        respond(201, ['asset' => $asset]);
    }

    // ----- ICT: digital infrastructure -----------------------------------------------------

    if ($route === 'ict/infrastructure' && $method === 'GET') {
        $user = auth($config);
        requireModule('ictInfraAll', 'lib/ict-infrastructure.php');
        ictRequire($pdo, $user, $config, 'infrastructure.view');
        respond(200, [
            'items'   => ictInfraAll($pdo),
            'summary' => ictInfraSummary($pdo),
        ]);
    }

    if ($route === 'ict/infrastructure' && $method === 'POST') {
        $user = auth($config);
        requireModule('ictInfraCreate', 'lib/ict-infrastructure.php');
        ictRequire($pdo, $user, $config, 'infrastructure.manage');

        [$item, $errors] = ictInfraCreate($pdo, input());
        if ($errors) {
            respond(400, ['error' => $errors['_'] ?? 'Please check the highlighted fields.', 'fields' => $errors]);
        }
        ictAudit($pdo, (int)$user['userId'], 'infrastructure_added', 'infrastructure', (string)$item['id'],
                 $item['kind'] . ': ' . $item['name'], null, ['name' => $item['name'], 'expiresOn' => $item['expiresOn']]);
        respond(201, ['item' => $item]);
    }

    if (preg_match('#^ict/infrastructure/(\d+)$#', $route, $m) && in_array($method, ['PATCH', 'PUT'], true)) {
        $user = auth($config);
        requireModule('ictInfraUpdate', 'lib/ict-infrastructure.php');
        ictRequire($pdo, $user, $config, 'infrastructure.manage');

        [$item, $errors, $before] = ictInfraUpdate($pdo, (int)$m[1], input());
        if ($errors) {
            respond(400, ['error' => $errors['_'] ?? 'Please check the highlighted fields.', 'fields' => $errors]);
        }
        // Only what actually changed goes into the log, so the entry answers "what did it say
        // before" without storing a copy of the whole record on every edit.
        [$was, $now] = ictDiff($before ?: [], $item);
        ictAudit($pdo, (int)$user['userId'], 'infrastructure_updated', 'infrastructure', (string)$item['id'],
                 $item['kind'] . ': ' . $item['name'], $was, $now);
        respond(200, ['item' => $item]);
    }

    /**
     * The ICT Overview.
     *
     * Assembled from whatever modules are actually deployed - each block is guarded, so this
     * keeps working as later phases land rather than needing a rewrite each time. The CPD
     * figures are read-only counts against the existing tables: no new storage, no duplicated
     * logic, and removing this block tomorrow would leave CPD untouched.
     */
    if ($route === 'ict/overview' && $method === 'GET') {
        $user = auth($config);
        requireModule('ictCan', 'lib/ict.php');
        if (!ictHasAnyAccess($pdo, $user, $config)) {
            respond(403, ['error' => 'You do not have access to the ICT area.']);
        }

        $overview = ['generatedAt' => date('c')];

        if (ictCan($pdo, $user, $config, 'infrastructure.view') && function_exists('ictInfraSummary')) {
            $overview['infrastructure'] = ictInfraSummary($pdo);
        }

        // Read-only CPD visibility. The existing system stays the source of truth.
        if (ictCan($pdo, $user, $config, 'reports.view')) {
            try {
                $one = function (string $sql) use ($pdo): int {
                    return (int)($pdo->query($sql)->fetch()['c'] ?? 0);
                };
                $overview['cpd'] = [
                    'publishedEvents'  => $one("SELECT COUNT(*) c FROM cpd_events WHERE status = 'published'"),
                    'upcomingEvents'   => $one("SELECT COUNT(*) c FROM cpd_events WHERE status = 'published' AND COALESCE(ends_at, starts_at) >= NOW()"),
                    'tokensAwaiting'   => $one("SELECT COUNT(*) c FROM cpd_tokens WHERE status = 'assigned'"),
                    'tokensCollected'  => $one("SELECT COUNT(*) c FROM cpd_tokens WHERE status = 'collected'"),
                ];
            } catch (Throwable $e) {
                // CPD tables absent on this deployment. Not an ICT problem; the block is
                // simply omitted rather than failing the whole overview.
                $overview['cpd'] = null;
            }
        }

        if (ictCan($pdo, $user, $config, 'security.view')) {
            try {
                $stmt = $pdo->query("SELECT COUNT(*) c FROM security_events
                                     WHERE severity IN ('warning','critical') AND created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)");
                $locked = $pdo->query("SELECT COUNT(*) c FROM auth_attempts WHERE locked_until > NOW()");
                $overview['security'] = [
                    'recentAlerts'    => (int)($stmt->fetch()['c'] ?? 0),
                    'lockedOutNow'    => (int)($locked->fetch()['c'] ?? 0),
                ];
            } catch (Throwable $e) {
                $overview['security'] = null;
            }
        }

        if (ictCan($pdo, $user, $config, 'reports.view') && function_exists('ictAuditRecent')) {
            $overview['activity'] = ictAuditRecent($pdo, 12);
        }

        respond(200, $overview);
    }

    // ----- ICT: access and capabilities ---------------------------------------------------

    /**
     * What the signed-in user may do. The ICT page calls this on load to decide which
     * sections to show - so a section nobody can use is never rendered, rather than being
     * rendered and then failing on click.
     */
    if ($route === 'ict/me' && $method === 'GET') {
        $user = auth($config);
        requireModule('ictCapabilitiesFor', 'lib/ict.php');
        respond(200, [
            'role'         => $user['role'] ?? '',
            'isSuperAdmin' => isSuperAdmin($user, $config),
            'hasAccess'    => ictHasAnyAccess($pdo, $user, $config),
            // A super admin holds everything implicitly, so the list is reported as complete
            // rather than as whatever rows happen to exist for them.
            'capabilities' => isSuperAdmin($user, $config)
                ? array_keys(ictCapabilityList())
                : ictCapabilitiesFor($pdo, (int)$user['userId']),
        ]);
    }

    /** The vocabulary, so the granting screen is never out of step with the server. */
    if ($route === 'ict/capabilities' && $method === 'GET') {
        $user = auth($config);
        requireSuperAdmin($user, $config);
        requireModule('ictCapabilityList', 'lib/ict.php');
        respond(200, ['capabilities' => ictCapabilityList()]);
    }

    /** Everyone who holds any ICT capability, plus every admin and ICT account. */
    if ($route === 'ict/staff' && $method === 'GET') {
        $user = auth($config);
        requireSuperAdmin($user, $config);
        requireModule('ictCapabilitiesFor', 'lib/ict.php');
        if (!ictEnsureTables($pdo)) {
            respond(503, ['error' => 'The ICT tables are not available. Import resok-portal/server/schema-ict.sql.']);
        }

        $rows = $pdo->query("SELECT u.id, u.email, u.role,
                                    TRIM(CONCAT(COALESCE(mp.first_name,''), ' ', COALESCE(mp.surname,''))) AS name
                               FROM users u
                               LEFT JOIN member_profiles mp ON mp.user_id = u.id
                              WHERE u.role IN ('admin','ict')
                                 OR u.id IN (SELECT user_id FROM ict_capabilities)
                              ORDER BY u.role, u.id")->fetchAll();

        respond(200, ['staff' => array_map(fn($r) => [
            'id'           => (int)$r['id'],
            'email'        => $r['email'],
            'name'         => trim((string)$r['name']) ?: null,
            'role'         => $r['role'],
            'isSuperAdmin' => isSuperAdmin(['role' => $r['role'], 'email' => $r['email']], $config),
            'capabilities' => ictCapabilitiesFor($pdo, (int)$r['id']),
        ], $rows)]);
    }

    /**
     * Sets one person's ICT capabilities, as a complete list rather than one grant at a time.
     * Sending the whole set makes the screen's state the intended state - there is no way to
     * tick a box, miss a request, and be left with a permission nobody meant to give.
     */
    if (preg_match('#^ict/staff/(\d+)/capabilities$#', $route, $m) && $method === 'PUT') {
        $user = auth($config);
        requireSuperAdmin($user, $config);
        requireModule('ictCapabilityList', 'lib/ict.php');
        if (!ictEnsureTables($pdo)) {
            respond(503, ['error' => 'The ICT tables are not available. Import resok-portal/server/schema-ict.sql.']);
        }

        $targetId = (int)$m[1];
        $target = $pdo->prepare('SELECT id, email, role FROM users WHERE id = ? LIMIT 1');
        $target->execute([$targetId]);
        $targetRow = $target->fetch();
        if (!$targetRow) respond(404, ['error' => 'That account no longer exists.']);

        $wanted = input()['capabilities'] ?? [];
        if (!is_array($wanted)) respond(400, ['error' => 'Send capabilities as a list.']);

        // Anything outside the vocabulary is refused rather than stored. A typo that is
        // saved becomes a permission no check will ever match and nobody can find to revoke.
        $known = array_keys(ictCapabilityList());
        $unknown = array_values(array_diff($wanted, $known));
        if ($unknown) {
            respond(400, ['error' => 'Unknown capability: ' . implode(', ', array_slice($unknown, 0, 3))]);
        }

        $before = ictCapabilitiesFor($pdo, $targetId);

        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM ict_capabilities WHERE user_id = ?')->execute([$targetId]);
            if ($wanted) {
                $insert = $pdo->prepare('INSERT INTO ict_capabilities (user_id, capability, granted_by) VALUES (?, ?, ?)');
                foreach (array_unique($wanted) as $capability) {
                    $insert->execute([$targetId, $capability, (int)$user['userId']]);
                }
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            respond(500, ['error' => 'Nothing was changed: ' . $e->getMessage()]);
        }

        sort($before);
        $after = array_values(array_unique($wanted));
        sort($after);
        ictAudit($pdo, (int)$user['userId'], 'capabilities_changed', 'user', (string)$targetId,
                 $targetRow['email'] . ': ' . count($after) . ' capability(ies)',
                 ['capabilities' => $before], ['capabilities' => $after]);
        securityLog($pdo, $config, 'ict_capabilities_changed', 'warning', 'ict',
                    $targetRow['email'], (int)$user['userId']);

        respond(200, ['capabilities' => $after]);
    }

    /**
     * Moves an account between member, admin and ict.
     *
     * Separate from the existing admin role route because that one only accepts member and
     * admin, and widening it would let a super admin turn an ICT officer into an admin from
     * a screen that does not say that is what it is doing.
     */
    if (preg_match('#^ict/staff/(\d+)/role$#', $route, $m) && $method === 'PUT') {
        $user = auth($config);
        requireSuperAdmin($user, $config);
        requireModule('ictCapabilityList', 'lib/ict.php');

        $targetId = (int)$m[1];
        $role = (string)(input()['role'] ?? '');
        if (!in_array($role, ['member', 'ict'], true)) {
            // Promotion to admin stays on the Administrators panel. Granting access to every
            // member's ID number should happen on the screen that says so, not this one.
            respond(400, ['error' => 'From here an account can be set to member or ict. Use the Administrators panel for admin access.']);
        }
        if ($targetId === (int)$user['userId']) {
            respond(400, ['error' => 'You cannot change your own role.']);
        }

        $target = $pdo->prepare('SELECT id, email, role FROM users WHERE id = ? LIMIT 1');
        $target->execute([$targetId]);
        $targetRow = $target->fetch();
        if (!$targetRow) respond(404, ['error' => 'That account no longer exists.']);

        if ($targetRow['role'] === 'admin') {
            respond(400, ['error' => 'That account is an administrator. Remove admin access first.']);
        }
        if (isSuperAdmin(['role' => $targetRow['role'], 'email' => $targetRow['email']], $config)) {
            respond(400, ['error' => 'That account is named as a super administrator in the server configuration.']);
        }

        $pdo->prepare('UPDATE users SET role = ? WHERE id = ?')->execute([$role, $targetId]);
        ictAudit($pdo, (int)$user['userId'], 'role_changed', 'user', (string)$targetId,
                 $targetRow['email'], ['role' => $targetRow['role']], ['role' => $role]);
        securityLog($pdo, $config, 'ict_role_changed', 'warning', 'ict',
                    $targetRow['email'] . ' -> ' . $role, (int)$user['userId']);

        respond(200, ['role' => $role]);
    }

    /** The ICT activity timeline. */
    if ($route === 'ict/audit' && $method === 'GET') {
        $user = auth($config);
        requireModule('ictAuditRecent', 'lib/ict.php');
        ictRequire($pdo, $user, $config, 'reports.view');
        respond(200, ['entries' => ictAuditRecent($pdo, 60)]);
    }

    // ----- CPD token collection (public) --------------------------------------------------

    /**
     * Step one: send a six-digit code to the address attendance was recorded against.
     *
     * The reply is identical whether or not the address is on the list. Saying "you did not
     * attend" would turn this into a way to work through a delegate list and learn who was
     * there and who still has an uncollected token waiting - and the delegate list is exactly
     * what somebody misusing this would already have.
     */
    if (preg_match('#^events/([a-z0-9-]+)/token/request$#', $route, $m) && $method === 'POST') {
        requireModule('attendeeFor', 'lib/attendance.php');
        $data = input();
        $email = strtolower(trim((string)($data['email'] ?? '')));

        // Two counters: this address, and this browser. The second is what stops one client
        // walking a list of addresses.
        throttleCheck($pdo, $config, 'token-request', $email);

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            respond(400, ['error' => 'Please enter the email address you registered with.']);
        }
        $event = eventFind($pdo, $m[1]);
        if (!$event) respond(404, ['error' => 'That event could not be found.']);

        $sameEither = ['message' => 'If that address attended this event, a six-digit code is on its way. It expires in 15 minutes.'];

        $attendee = attendeeFor($pdo, (int)$event['id'], $email);
        if (!$attendee || !(int)$attendee['attended']) {
            throttleFailure($pdo, $config, 'token-request', $email);
            respond(200, $sameEither);
        }

        // No token assigned yet - the admin has not loaded the batch, or it ran short. Still
        // answered the same way; the office follows up rather than the page explaining.
        $hasToken = $pdo->prepare('SELECT id FROM cpd_tokens WHERE attendee_id = ? LIMIT 1');
        $hasToken->execute([(int)$attendee['id']]);
        if (!$hasToken->fetch()) {
            error_log('Token requested by attendee ' . $attendee['id'] . ' but none is assigned.');
            respond(200, $sameEither);
        }

        $code = tokenCodeIssue($pdo, (int)$attendee['id']);
        if (function_exists('sendTokenAccessCodeEmail')) {
            sendTokenAccessCodeEmail($config, $email, (string)$attendee['full_name'], (string)$event['title'], $code);
        }
        throttleSuccess($pdo, $config, 'token-request', $email);
        securityLog($pdo, $config, 'token_code_sent', 'info', 'token-request', 'Event ' . $event['slug']);
        respond(200, $sameEither);
    }

    /** Step two: check the code, then release the token. */
    if (preg_match('#^events/([a-z0-9-]+)/token/collect$#', $route, $m) && $method === 'POST') {
        requireModule('tokenCodeVerify', 'lib/attendance.php');
        $data = input();
        $email = strtolower(trim((string)($data['email'] ?? '')));
        $code = trim((string)($data['code'] ?? ''));

        throttleCheck($pdo, $config, 'token-collect', $email);

        $event = eventFind($pdo, $m[1]);
        if (!$event) respond(404, ['error' => 'That event could not be found.']);

        $attendee = $email !== '' ? attendeeFor($pdo, (int)$event['id'], $email) : null;
        if (!$attendee) {
            // The address had to be right to receive a code at all, so this is a wrong
            // address rather than a wrong code - reported the same way either way.
            throttleFailure($pdo, $config, 'token-collect', $email);
            respond(400, ['error' => 'That code is not right.']);
        }

        $check = tokenCodeVerify($pdo, (int)$attendee['id'], $code);
        if (!$check['ok']) {
            throttleFailure($pdo, $config, 'token-collect', $email);
            securityLog($pdo, $config, 'token_code_failed', 'info', 'token-collect', 'Event ' . $event['slug']);
            respond(400, ['error' => $check['error']]);
        }

        $released = tokenRelease($pdo, $config, (int)$attendee['id']);
        if (!$released) {
            respond(409, ['error' => 'Your attendance is recorded, but no token is available yet. Please contact the ReSoK office.']);
        }
        throttleSuccess($pdo, $config, 'token-collect', $email);
        securityLog($pdo, $config, 'token_collected', 'info', 'token-collect', 'Event ' . $event['slug']);

        respond(200, [
            'token'  => $released['token'],
            'name'   => $attendee['full_name'],
            'event'  => $event['title'],
            'points' => $event['approved_points'] === null ? null : (float)$event['approved_points'],
            'approvalRef' => $event['approval_ref'],
            'regulator'   => $event['regulator'],
        ]);
    }

    // ----- CPD tokens (member) ------------------------------------------------------------

    /**
     * A signed-in member's tokens. No code: being signed in is a stronger proof than an
     * email round-trip, so asking for one as well would be theatre.
     */
    if ($route === 'cpd/tokens' && $method === 'GET') {
        $user = auth($config);
        requireModule('tokensForMember', 'lib/attendance.php');
        $member = memberRow($pdo, (int)$user['userId']);
        if (!$member) respond(200, ['tokens' => []]);

        $tokens = tokensForMember($pdo, $config, (int)$member['id']);
        // Seeing it in the dashboard is the collection, so it is recorded as such - otherwise
        // the admin reconciliation could never tell a collected member token from a waiting one.
        $pdo->prepare("UPDATE cpd_tokens t
                       JOIN event_attendees a ON a.id = t.attendee_id
                       SET t.status = 'collected', t.collected_at = NOW()
                       WHERE a.member_profile_id = ? AND t.status = 'assigned'")
            ->execute([(int)$member['id']]);
        respond(200, ['tokens' => $tokens]);
    }

    // ----- Attendance and tokens (admin) --------------------------------------------------

    if (preg_match('#^admin/events/(\d+)/attendees$#', $route, $m) && $method === 'GET') {
        $user = auth($config);
        requireAdmin($user);
        requireModule('attendanceList', 'lib/attendance.php');
        respond(200, [
            'attendees' => attendanceList($pdo, (int)$m[1]),
            'summary'   => tokensSummary($pdo, (int)$m[1]),
        ]);
    }

    /** Paste a register, a Zoom participant export, or a typed list. */
    if (preg_match('#^admin/events/(\d+)/attendees$#', $route, $m) && $method === 'POST') {
        $user = auth($config);
        requireAdmin($user);
        requireModule('attendanceParseList', 'lib/attendance.php');
        if (!attendanceEnsureTables($pdo)) {
            respond(503, ['error' => 'The attendance tables are not available. Import schema-tokens.sql.']);
        }

        $data = input();
        $parsed = attendanceParseList((string)($data['list'] ?? ''));
        if (!$parsed['rows']) {
            respond(400, ['error' => 'No email addresses were found in that list.']);
        }
        $channel = in_array($data['channel'] ?? '', ['in_person', 'online', 'unknown'], true) ? $data['channel'] : 'unknown';
        $method_ = in_array($data['method'] ?? '', ['register', 'zoom_report', 'venue_code', 'manual'], true) ? $data['method'] : 'manual';

        $result = attendanceRecord($pdo, (int)$m[1], $parsed['rows'], $channel, $method_,
                                   (int)$user['userId'], !empty($data['markAttended']));
        logAdminAction($pdo, (int)$user['userId'], 'attendance_recorded', null,
                       $result['added'] . ' added, ' . $result['updated'] . ' updated');
        respond(200, [
            'result'  => $result,
            'skipped' => count($parsed['skipped']),
            'summary' => tokensSummary($pdo, (int)$m[1]),
        ]);
    }

    /** Load the batch of tokens generated on the KMPDC portal. */
    if (preg_match('#^admin/events/(\d+)/tokens$#', $route, $m) && $method === 'POST') {
        $user = auth($config);
        requireAdmin($user);
        requireModule('tokensLoad', 'lib/attendance.php');
        if (!attendanceEnsureTables($pdo)) {
            respond(503, ['error' => 'The attendance tables are not available. Import schema-tokens.sql.']);
        }
        if (!cryptoAvailable($config)) {
            // Refused rather than stored in the clear. A table of readable CPD tokens is
            // worth stealing, and this is the one moment where saying no still costs nothing.
            respond(400, ['error' => 'Set a data_encryption_key before loading tokens, so they are not stored in readable form.']);
        }

        $data = input();
        $result = tokensLoad($pdo, $config, (int)$m[1], (string)($data['tokens'] ?? ''));
        logAdminAction($pdo, (int)$user['userId'], 'tokens_loaded', null, $result['added'] . ' tokens');
        respond(200, ['result' => $result, 'summary' => tokensSummary($pdo, (int)$m[1])]);
    }

    /** Hand one token to each attendee marked present who does not have one. */
    if (preg_match('#^admin/events/(\d+)/tokens/assign$#', $route, $m) && $method === 'POST') {
        $user = auth($config);
        requireAdmin($user);
        requireModule('tokensAssign', 'lib/attendance.php');
        try {
            $result = tokensAssign($pdo, (int)$m[1]);
        } catch (Throwable $e) {
            respond(500, ['error' => 'Assignment failed and nothing was changed: ' . $e->getMessage()]);
        }
        logAdminAction($pdo, (int)$user['userId'], 'tokens_assigned', null, $result['assigned'] . ' assigned');
        respond(200, ['result' => $result, 'summary' => tokensSummary($pdo, (int)$m[1])]);
    }

    /**
     * The override for a mistyped address.
     *
     * Someone will register as name@gmail.con and then be unable to collect anything. Without
     * this every typo becomes a phone call. Logged against the admin who did it, because a
     * route that reveals a token on request is exactly the one worth being able to audit.
     */
    if (preg_match('#^admin/attendees/(\d+)/token$#', $route, $m) && $method === 'GET') {
        $user = auth($config);
        requireAdmin($user);
        requireModule('tokenRelease', 'lib/attendance.php');

        $released = tokenRelease($pdo, $config, (int)$m[1]);
        if (!$released) respond(404, ['error' => 'No token is assigned to that attendee.']);

        logAdminAction($pdo, (int)$user['userId'], 'token_revealed', null, 'Attendee ' . $m[1]);
        securityLog($pdo, $config, 'token_revealed_by_admin', 'warning', 'admin',
                    'Attendee ' . $m[1], (int)$user['userId']);
        respond(200, ['token' => $released['token']]);
    }

    // ----- Event management (admin) -----------------------------------------------------

    if ($route === 'admin/events' && $method === 'GET') {
        $user = auth($config);
        requireAdmin($user);
        requireModule('eventsAll', 'lib/events.php');
        respond(200, ['events' => eventsAll($pdo)]);
    }

    if ($route === 'admin/events' && $method === 'POST') {
        $user = auth($config);
        requireAdmin($user);
        requireModule('eventCreate', 'lib/events.php');

        [$event, $errors] = eventCreate($pdo, input(), (int)$user['userId']);
        if ($errors) {
            respond(400, ['error' => $errors['_'] ?? 'Please check the highlighted fields.', 'fields' => $errors]);
        }
        logAdminAction($pdo, (int)$user['userId'], 'event_created', null, $event['title']);
        respond(201, ['event' => $event]);
    }

    if (preg_match('#^admin/events/(\d+)$#', $route, $m) && in_array($method, ['PATCH', 'PUT'], true)) {
        $user = auth($config);
        requireAdmin($user);
        requireModule('eventUpdate', 'lib/events.php');

        [$event, $errors] = eventUpdate($pdo, (int)$m[1], input());
        if ($errors) {
            respond(400, ['error' => $errors['_'] ?? 'Please check the highlighted fields.', 'fields' => $errors]);
        }
        logAdminAction($pdo, (int)$user['userId'], 'event_updated', null, $event['title']);
        respond(200, ['event' => $event]);
    }

    if ($route === 'events/public' && $method === 'GET') {
        $payload = ['events' => eventsUpcoming($pdo)];
        if (($_GET['include'] ?? '') === 'past') $payload['past'] = eventsPast($pdo);
        respond(200, $payload);
    }

    if ($route === 'events' && $method === 'GET') {
        $user = auth($config);
        ensureEventRegistrationsTable($pdo);
        $member = memberRow($pdo, (int)$user['userId']);
        $registeredIds = [];
        if ($member) {
            $stmt = $pdo->prepare('SELECT event_id FROM event_registrations WHERE member_profile_id = ?');
            $stmt->execute([(int)$member['id']]);
            $registeredIds = array_column($stmt->fetchAll(), 'event_id');
        }
        // Served from the database now. eventCatalog() remains as the fallback for a portal
        // whose events table has not been created yet, so this page never comes up empty
        // during the changeover.
        $catalog = array_map('eventLegacyShape', eventsUpcoming($pdo));
        if (!$catalog) $catalog = eventCatalog();
        respond(200, array_map(fn($event) => array_merge($event, ['registered' => in_array($event['id'], $registeredIds, true)]), $catalog));
    }

    if (preg_match('#^events/([a-z0-9-]+)/register$#', $route, $m) && $method === 'POST') {
        $user = auth($config);
        ensureEventRegistrationsTable($pdo);
        ensureCpdTable($pdo);
        $member = memberRow($pdo, (int)$user['userId']);
        if (!$member) respond(404, ['error' => 'Member profile not found']);

        $event = null;
        foreach (eventCatalog() as $candidate) {
            if ($candidate['id'] === $m[1]) { $event = $candidate; break; }
        }
        if (!$event) respond(404, ['error' => 'Event not found']);

        $stmt = $pdo->prepare('SELECT id FROM event_registrations WHERE member_profile_id = ? AND event_id = ? LIMIT 1');
        $stmt->execute([(int)$member['id'], $event['id']]);
        if ($stmt->fetch()) respond(409, ['error' => 'You are already registered for this event']);

        $pdo->beginTransaction();
        $pdo->prepare('INSERT INTO event_registrations (member_profile_id, event_id, event_title, cpd_points) VALUES (?, ?, ?, ?)')
            ->execute([(int)$member['id'], $event['id'], $event['title'], $event['cpdPoints']]);
        $pdo->prepare('INSERT INTO cpd_activities (member_profile_id, description, points, occurred_on, added_by) VALUES (?, ?, ?, ?, NULL)')
            ->execute([(int)$member['id'], 'Registered: ' . $event['title'], $event['cpdPoints'], $event['date']]);
        $pdo->prepare('UPDATE member_profiles SET cpd_points = cpd_points + ? WHERE id = ?')->execute([$event['cpdPoints'], (int)$member['id']]);
        $pdo->commit();

        respond(201, ['message' => 'Registered for ' . $event['title'], 'event' => array_merge($event, ['registered' => true])]);
    }

    if ($route === 'cpd/me' && $method === 'GET') {
        $user = auth($config);
        ensureCpdTable($pdo);
        $member = memberRow($pdo, (int)$user['userId']);
        if (!$member) respond(200, []);
        $stmt = $pdo->prepare('SELECT * FROM cpd_activities WHERE member_profile_id = ? ORDER BY occurred_on DESC, created_at DESC LIMIT 200');
        $stmt->execute([(int)$member['id']]);
        respond(200, array_map(fn($row) => [
            'id' => (int)$row['id'],
            'description' => $row['description'],
            'points' => (int)$row['points'],
            'date' => $row['occurred_on'] ?? $row['created_at']
        ], $stmt->fetchAll()));
    }

    if (preg_match('#^members/(\d+)/cpd$#', $route, $m) && $method === 'GET') {
        $user = auth($config);
        requireAdmin($user);
        ensureCpdTable($pdo);
        $stmt = $pdo->prepare('SELECT * FROM cpd_activities WHERE member_profile_id = ? ORDER BY occurred_on DESC, created_at DESC LIMIT 200');
        $stmt->execute([(int)$m[1]]);
        respond(200, array_map(fn($row) => [
            'id' => (int)$row['id'],
            'description' => $row['description'],
            'points' => (int)$row['points'],
            'date' => $row['occurred_on'] ?? $row['created_at']
        ], $stmt->fetchAll()));
    }

    if (preg_match('#^members/(\d+)/cpd$#', $route, $m) && $method === 'POST') {
        $user = auth($config);
        requireAdmin($user);
        ensureCpdTable($pdo);
        $data = input();
        $description = trim((string)($data['description'] ?? ''));
        $points = (int)($data['points'] ?? 0);
        if ($description === '' || $points === 0) respond(400, ['error' => 'A description and non-zero points are required']);
        $occurredOn = !empty($data['occurredOn']) ? $data['occurredOn'] : date('Y-m-d');
        $pdo->prepare('INSERT INTO cpd_activities (member_profile_id, description, points, occurred_on, added_by) VALUES (?, ?, ?, ?, ?)')
            ->execute([(int)$m[1], $description, $points, $occurredOn, (int)$user['userId']]);
        $pdo->prepare('UPDATE member_profiles SET cpd_points = cpd_points + ? WHERE id = ?')->execute([$points, (int)$m[1]]);
        logAdminAction($pdo, (int)$user['userId'], 'cpd_add', (int)$m[1], "{$description} ({$points} pts)");
        $stmt = $pdo->prepare('SELECT * FROM member_profiles WHERE id = ? LIMIT 1');
        $stmt->execute([(int)$m[1]]);
        respond(201, mapMember($stmt->fetch()));
    }

    if ($route === 'members/me/profile-image' && $method === 'POST') {
        $user = auth($config);
        if (empty($_FILES['profileImage'])) respond(400, ['error' => 'No profile photo uploaded']);
        $file = $_FILES['profileImage'];
        if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) respond(400, ['error' => 'Profile photo upload failed']);
        if (($file['size'] ?? 0) > (int)$config['max_file_size']) respond(413, ['error' => 'Uploaded file is too large']);

        $name = (string)($file['name'] ?? '');
        $type = uploadedMime($file);
        if (!preg_match('/\.(jpe?g|png|webp)$/i', $name) || !preg_match('#^image/(jpe?g|png|webp)$#i', $type)) {
            respond(400, ['error' => 'Only JPG, PNG, and WebP profile photos are allowed']);
        }

        $dir = rtrim($config['upload_dir'], '/\\') . '/profile-images';
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $filename = 'profileImage-' . bin2hex(random_bytes(16)) . '.' . $extension;
        if (!move_uploaded_file((string)$file['tmp_name'], $dir . '/' . $filename)) {
            respond(500, ['error' => 'Could not save profile photo']);
        }

        $profileImage = 'profile-images/' . $filename;
        $pdo->prepare('UPDATE member_profiles SET profile_image = ? WHERE user_id = ?')->execute([$profileImage, (int)$user['userId']]);
        respond(200, mapMember(memberRow($pdo, (int)$user['userId'])) ?? []);
    }

    if ($route === 'payments/stk-push' && $method === 'POST') {
        $user = auth($config);
        ensurePaymentProofColumns($pdo);
        $data = input();
        $amount = (float)($data['amount'] ?? 0);
        $phone = trim((string)($data['phone'] ?? ''));
        if ($amount <= 0) respond(400, ['error' => 'Valid amount is required']);
        if (!preg_match('/^(?:\+?254|0)7\d{8}$|^(?:\+?254|0)1\d{8}$/', $phone)) respond(400, ['error' => 'Enter a valid Safaricom M-Pesa number']);

        $member = memberRow($pdo, (int)$user['userId']);
        $reference = 'RESOK-' . strtoupper(base_convert((string)time(), 10, 36)) . strtoupper(bin2hex(random_bytes(2)));
        $stmt = $pdo->prepare('INSERT INTO payments (user_id, member_profile_id, amount, currency, method, payment_type, phone, status, reference) VALUES (?, ?, ?, "KES", "M-Pesa STK Push", ?, ?, "pending", ?)');
        $stmt->execute([(int)$user['userId'], $member['id'] ?? null, $amount, $data['type'] ?? 'Membership Application/Renewal', $phone, $reference]);
        $paymentId = (int)$pdo->lastInsertId();

        try {
            $stk = initiateStkPush($config, $amount, $phone, $reference);
            error_log("STK push initiated OK: paymentId={$paymentId} checkoutRequestId={$stk['checkoutRequestId']} callbackUrl=" . ($config['mpesa_callback_url'] ?? '(not set)'));
        } catch (Throwable $stkError) {
            error_log('STK push failed to initiate: ' . $stkError->getMessage());
            $pdo->prepare('UPDATE payments SET status = "failed" WHERE id = ?')->execute([$paymentId]);
            respond(502, ['error' => $stkError->getMessage()]);
        }

        $pdo->prepare('UPDATE payments SET provider_reference = ? WHERE id = ?')->execute([$stk['checkoutRequestId'], $paymentId]);
        respond(201, ['id' => $paymentId, 'status' => 'pending', 'reference' => $reference, 'checkoutRequestId' => $stk['checkoutRequestId'], 'message' => 'Enter your M-Pesa PIN on your phone to complete payment.']);
    }

    if (preg_match('#^payments/(\d+)/status$#', $route, $m) && $method === 'GET') {
        $user = auth($config);
        $stmt = $pdo->prepare('SELECT id, status, amount, reference FROM payments WHERE id = ? AND user_id = ? LIMIT 1');
        $stmt->execute([(int)$m[1], (int)$user['userId']]);
        $payment = $stmt->fetch();
        if (!$payment) respond(404, ['error' => 'Payment not found']);
        respond(200, ['id' => (int)$payment['id'], 'status' => $payment['status'], 'amount' => (float)$payment['amount'], 'reference' => $payment['reference']]);
    }

    if ($route === 'payments/mpesa/callback' && $method === 'POST') {
        ensurePaymentProofColumns($pdo);
        $rawBody = file_get_contents('php://input');
        error_log('M-Pesa callback received: ' . $rawBody);
        $raw = json_decode($rawBody, true);
        $callback = $raw['Body']['stkCallback'] ?? null;
        if (!is_array($callback) || empty($callback['CheckoutRequestID'])) {
            error_log('M-Pesa callback ignored: no CheckoutRequestID found in payload');
            respond(200, ['message' => 'Ignored']);
        }

        $checkoutRequestId = (string)$callback['CheckoutRequestID'];
        $stmt = $pdo->prepare('SELECT * FROM payments WHERE provider_reference = ? LIMIT 1');
        $stmt->execute([$checkoutRequestId]);
        $payment = $stmt->fetch();
        if (!$payment) {
            error_log("M-Pesa callback: no payment row found for provider_reference={$checkoutRequestId}");
            respond(200, ['message' => 'Already processed']);
        }
        if ($payment['status'] !== 'pending') {
            error_log("M-Pesa callback: payment {$payment['id']} already in status {$payment['status']}, ignoring");
            respond(200, ['message' => 'Already processed']);
        }

        if ((int)($callback['ResultCode'] ?? 1) === 0) {
            $pdo->prepare('UPDATE payments SET status = "paid" WHERE id = ?')->execute([(int)$payment['id']]);
            if (!empty($payment['member_profile_id'])) {
                $pdo->prepare('UPDATE member_profiles SET membership_status = "under_review", review_reason = NULL, reviewed_at = NOW() WHERE id = ?')->execute([(int)$payment['member_profile_id']]);
            }
        } else {
            $pdo->prepare('UPDATE payments SET status = "failed" WHERE id = ?')->execute([(int)$payment['id']]);
        }
        respond(200, ['message' => 'Processed']);
    }

    if ($route === 'payments' && $method === 'GET') {
        $user = auth($config);
        $stmt = $pdo->prepare('SELECT * FROM payments WHERE user_id = ? ORDER BY created_at DESC LIMIT 100');
        $stmt->execute([(int)$user['userId']]);
        respond(200, array_map(fn($p) => [
            'id' => (int)$p['id'],
            'amount' => (float)$p['amount'],
            'currency' => $p['currency'],
            'method' => $p['method'],
            'type' => $p['payment_type'],
            'phone' => $p['phone'],
            'status' => $p['status'],
            'reference' => $p['reference'],
            'date' => $p['created_at']
        ], $stmt->fetchAll()));
    }

    if ($route === 'payments' && $method === 'POST') {
        $user = auth($config);
        $data = input();
        if (empty($data['amount']) || (float)$data['amount'] <= 0) respond(400, ['error' => 'Valid amount is required']);
        $member = memberRow($pdo, (int)$user['userId']);
        $reference = 'RESOK-' . strtoupper(base_convert((string)time(), 10, 36)) . strtoupper(bin2hex(random_bytes(2)));
        $stmt = $pdo->prepare('INSERT INTO payments (user_id, member_profile_id, amount, currency, method, payment_type, phone, status, reference) VALUES (?, ?, ?, "KES", "Manual review", ?, ?, "pending", ?)');
        $stmt->execute([(int)$user['userId'], $member['id'] ?? null, (float)$data['amount'], $data['type'] ?? 'Membership', $data['phone'] ?? null, $reference]);
        respond(201, ['id' => (int)$pdo->lastInsertId(), 'amount' => (float)$data['amount'], 'method' => 'Manual review', 'status' => 'pending', 'reference' => $reference, 'date' => gmdate('c')]);
    }

    if ($route === 'payments/proof' && $method === 'POST') {
        $user = auth($config);
        ensurePaymentProofColumns($pdo);
        if (empty($_POST['amount']) || (float)$_POST['amount'] <= 0) respond(400, ['error' => 'Valid amount is required']);
        $mpesaCode = strtoupper(trim((string)($_POST['mpesaCode'] ?? '')));
        $paymentMode = trim((string)($_POST['paymentMode'] ?? 'M-PESA Paybill'));
        $allowedPaymentModes = ['M-PESA Paybill', 'Cheque/interbank Funds Transfer'];
        if (!in_array($paymentMode, $allowedPaymentModes, true)) $paymentMode = 'M-PESA Paybill';
        if (!preg_match('/^[A-Z0-9-]{6,24}$/', $mpesaCode)) respond(400, ['error' => 'A valid payment confirmation code is required']);
        if (empty($_FILES['proof'])) respond(400, ['error' => 'Please upload proof of payment']);

        $file = $_FILES['proof'];
        if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) respond(400, ['error' => 'Proof upload failed']);
        if (($file['size'] ?? 0) > (int)$config['max_file_size']) respond(413, ['error' => 'Uploaded file is too large']);
        $name = (string)($file['name'] ?? '');
        $type = uploadedMime($file);
        if (!preg_match('/\.(pdf|jpe?g|png)$/i', $name) || !preg_match('#^(application/pdf|image/jpe?g|image/png)$#i', $type)) {
            respond(400, ['error' => 'Only PDF, JPG, and PNG files are allowed']);
        }

        $member = memberRow($pdo, (int)$user['userId']);
        if (!$member) respond(404, ['error' => 'Member profile not found']);

        $stmt = $pdo->prepare('SELECT id FROM payments WHERE provider_reference = ? LIMIT 1');
        $stmt->execute([$mpesaCode]);
        if ($stmt->fetch()) respond(409, ['error' => 'This payment confirmation code has already been submitted']);

        $dir = rtrim($config['upload_dir'], '/\\') . '/Payment_Proof';
        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            respond(500, ['error' => 'Could not create the payment proof folder']);
        }
        if (!is_writable($dir)) {
            respond(500, ['error' => 'The payment proof folder is not writable']);
        }
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $filename = 'proof-' . bin2hex(random_bytes(16)) . '.' . $extension;
        if (!move_uploaded_file((string)$file['tmp_name'], $dir . '/' . $filename)) {
            respond(500, ['error' => 'Could not save proof of payment']);
        }

        $reference = 'PAY-' . $mpesaCode;
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('INSERT INTO payments (user_id, member_profile_id, amount, currency, method, payment_type, phone, status, reference, provider_reference, proof_filename, proof_original_name, proof_mime_type, proof_file_size) VALUES (?, ?, ?, "KES", ?, ?, ?, "paid", ?, ?, ?, ?, ?, ?)');
        $stmt->execute([(int)$user['userId'], (int)$member['id'], (float)$_POST['amount'], $paymentMode, $_POST['type'] ?? 'Membership Application/Renewal', $_POST['phone'] ?? $member['mobile'], $reference, $mpesaCode, $filename, $name, $type, (int)$file['size']]);
        $paymentId = (int)$pdo->lastInsertId();
        $stmt = $pdo->prepare('UPDATE member_profiles SET membership_status = "under_review", review_reason = NULL, reviewed_at = NOW() WHERE id = ?');
        $stmt->execute([(int)$member['id']]);
        $pdo->commit();

        $stmt = $pdo->prepare('SELECT * FROM payments WHERE id = ? LIMIT 1');
        $stmt->execute([$paymentId]);
        $p = $stmt->fetch();
        respond(201, [
            'message' => 'Payment proof received. Your membership is under admin review.',
            'payment' => [
                'id' => (int)$p['id'],
                'amount' => (float)$p['amount'],
                'currency' => $p['currency'],
                'method' => $p['method'],
                'type' => $p['payment_type'],
                'phone' => $p['phone'],
                'status' => $p['status'],
                'reference' => $p['reference'],
                'date' => $p['created_at']
            ],
            'member' => mapMember(memberRow($pdo, (int)$user['userId']))
        ]);
    }

    if (preg_match('#^payments/member/(\d+)$#', $route, $m) && $method === 'GET') {
        $user = auth($config);
        requireAdmin($user);
        $stmt = $pdo->prepare('SELECT * FROM payments WHERE member_profile_id = ? ORDER BY created_at DESC LIMIT 100');
        $stmt->execute([(int)$m[1]]);
        respond(200, array_map(fn($p) => [
            'id' => (int)$p['id'],
            'amount' => (float)$p['amount'],
            'currency' => $p['currency'],
            'method' => $p['method'],
            'type' => $p['payment_type'],
            'phone' => $p['phone'],
            'status' => $p['status'],
            'reference' => $p['reference'],
            'date' => $p['created_at'],
            'proofName' => $p['proof_original_name'],
            'proofDownloadUrl' => !empty($p['proof_filename']) ? 'api/index.php?route=payments/proof/' . (int)$p['id'] : null
        ], $stmt->fetchAll()));
    }

    if (preg_match('#^payments/proof/(\d+)$#', $route, $m) && $method === 'GET') {
        $user = auth($config);
        requireAdmin($user);
        $stmt = $pdo->prepare('SELECT proof_filename, proof_original_name, proof_mime_type FROM payments WHERE id = ? LIMIT 1');
        $stmt->execute([(int)$m[1]]);
        $payment = $stmt->fetch();
        if (!$payment || empty($payment['proof_filename'])) respond(404, ['error' => 'Payment proof not found']);

        $filename = basename((string)$payment['proof_filename']);
        $file = rtrim($config['upload_dir'], '/\\') . '/Payment_Proof/' . $filename;
        if (!is_file($file)) respond(404, ['error' => 'Payment proof file not found']);

        $mime = $payment['proof_mime_type'] ?: 'application/octet-stream';
        header_remove('Content-Type');
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($file));
        $downloadName = preg_replace('/[^A-Za-z0-9._ -]/', '_', (string)($payment['proof_original_name'] ?: $filename));
        header('Content-Disposition: inline; filename="' . addslashes($downloadName) . '"');
        readfile($file);
        exit;
    }

    if (preg_match('#^payments/(\d+)/confirm$#', $route, $m) && $method === 'POST') {
        $user = auth($config);
        requireAdmin($user);
        $stmt = $pdo->prepare('SELECT * FROM payments WHERE id = ? LIMIT 1');
        $stmt->execute([(int)$m[1]]);
        $payment = $stmt->fetch();
        if (!$payment) respond(404, ['error' => 'Payment not found']);

        $pdo->prepare('UPDATE payments SET status = "paid" WHERE id = ?')->execute([(int)$m[1]]);
        if (!empty($payment['member_profile_id'])) {
            $pdo->prepare('UPDATE member_profiles SET membership_status = CASE WHEN membership_status IN ("payment_required", "rejected") THEN "under_review" ELSE membership_status END WHERE id = ?')->execute([(int)$payment['member_profile_id']]);
        }

        $stmt = $pdo->prepare('SELECT * FROM payments WHERE id = ? LIMIT 1');
        $stmt->execute([(int)$m[1]]);
        $p = $stmt->fetch();
        respond(200, [
            'id' => (int)$p['id'],
            'amount' => (float)$p['amount'],
            'currency' => $p['currency'],
            'method' => $p['method'],
            'type' => $p['payment_type'],
            'phone' => $p['phone'],
            'status' => $p['status'],
            'reference' => $p['reference'],
            'date' => $p['created_at']
        ]);
    }

    if ($route === 'members/review-queue' && $method === 'GET') {
        $user = auth($config);
        requireAdmin($user);
        $rows = $pdo->query('SELECT mp.*, u.email FROM member_profiles mp JOIN users u ON u.id = mp.user_id WHERE mp.membership_status IN ("under_review", "payment_required", "rejected") ORDER BY mp.updated_at DESC LIMIT 100')->fetchAll();
        respond(200, array_map(fn($row) => array_merge(mapMember($row), ['email' => $row['email']]), $rows));
    }

    if ($route === 'members' && $method === 'GET') {
        $user = auth($config);
        requireAdmin($user);
        $rows = $pdo->query(
            'SELECT mp.*, u.email,
                    COUNT(p.id) AS payment_count,
                    COALESCE(SUM(CASE WHEN p.status = "paid" THEN p.amount ELSE 0 END), 0) AS paid_total,
                    MAX(p.created_at) AS latest_payment_at
             FROM member_profiles mp
             JOIN users u ON u.id = mp.user_id
             LEFT JOIN payments p ON p.member_profile_id = mp.id
             GROUP BY mp.id, u.email
             ORDER BY mp.created_at DESC
             LIMIT 500'
        )->fetchAll();
        respond(200, array_map(function ($row) {
            return array_merge(mapMember($row), [
                'email' => $row['email'],
                'paymentCount' => (int)$row['payment_count'],
                'paidTotal' => (float)$row['paid_total'],
                'latestPaymentAt' => $row['latest_payment_at']
            ]);
        }, $rows));
    }

    if (preg_match('#^members/(\d+)/approve$#', $route, $m) && $method === 'POST') {
        $user = auth($config);
        requireAdmin($user);
        try {
            respond(200, approveMemberById($pdo, $config, (int)$m[1], (int)$user['userId']));
        } catch (RuntimeException $approveError) {
            respond(409, ['error' => $approveError->getMessage()]);
        }
    }

    if (preg_match('#^members/(\d+)/reject$#', $route, $m) && $method === 'POST') {
        $user = auth($config);
        requireAdmin($user);
        $data = input();
        respond(200, rejectMemberById($pdo, (int)$m[1], (string)($data['reason'] ?? 'Please contact ReSoK support for assistance.'), (int)$user['userId']));
    }

    if ($route === 'members/bulk-approve' && $method === 'POST') {
        $user = auth($config);
        requireAdmin($user);
        $data = input();
        $ids = array_map('intval', is_array($data['ids'] ?? null) ? $data['ids'] : []);
        if (!$ids) respond(400, ['error' => 'No members selected']);
        $results = [];
        $errors = [];
        foreach ($ids as $id) {
            try {
                $results[] = approveMemberById($pdo, $config, $id, (int)$user['userId']);
            } catch (RuntimeException $bulkError) {
                $errors[] = ['id' => $id, 'error' => $bulkError->getMessage()];
            }
        }
        respond(200, ['approved' => $results, 'errors' => $errors]);
    }

    if ($route === 'members/bulk-reject' && $method === 'POST') {
        $user = auth($config);
        requireAdmin($user);
        $data = input();
        $ids = array_map('intval', is_array($data['ids'] ?? null) ? $data['ids'] : []);
        $reason = (string)($data['reason'] ?? 'Please contact ReSoK support for assistance.');
        if (!$ids) respond(400, ['error' => 'No members selected']);
        $results = [];
        foreach ($ids as $id) {
            $results[] = rejectMemberById($pdo, $id, $reason, (int)$user['userId']);
        }
        respond(200, ['rejected' => $results]);
    }

    if ($route === 'admin/audit-log' && $method === 'GET') {
        $user = auth($config);
        requireAdmin($user);
        ensureAuditTable($pdo);
        $rows = $pdo->query(
            'SELECT a.*, u.email AS admin_email
             FROM admin_actions a
             LEFT JOIN users u ON u.id = a.admin_user_id
             ORDER BY a.created_at DESC LIMIT 200'
        )->fetchAll();
        respond(200, array_map(fn($row) => [
            'id' => (int)$row['id'],
            'adminEmail' => $row['admin_email'] ?? 'Unknown admin',
            'action' => $row['action'],
            'targetMemberProfileId' => $row['target_member_profile_id'] !== null ? (int)$row['target_member_profile_id'] : null,
            'reason' => $row['reason'],
            'date' => $row['created_at']
        ], $rows));
    }

    respond(404, ['error' => 'Route not found']);
} catch (Throwable $error) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    error_log($error->getMessage());
    respond(500, ['error' => $isDebug ? $error->getMessage() : 'The portal API could not complete this request.']);
}

