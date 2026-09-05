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
$missingModules = [];
foreach (['portal-mail', 'mpesa', 'throttle', 'mfa', 'security-assessment', 'blog', 'social-ingest'] as $module) {
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
if (!function_exists('blogRequireTables')) {
    // Reached only if the guards above ever change order; the module check fires first.
    function blogRequireTables(PDO $pdo): void {}
}

if (!function_exists('mfaEnsureColumns')) {
    function mfaEnsureColumns(PDO $pdo): bool { return false; }
    function mfaRequiredForRole(string $role): bool { return false; }
}

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
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
}

function clearAuthCookie(): void {
    setcookie('resok_token', '', [
        'expires' => time() - 3600,
        'path' => '/',
        'secure' => true,
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
        'idNumber' => $row['id_number'],
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
    if ($row && $member) {
        try { sendWelcomePacketEmail($config, array_merge($member, ['email' => $row['email']])); } catch (Throwable $mailError) { error_log('Welcome email failed: ' . $mailError->getMessage()); }
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
            $data['idNumber'],
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
        mfaEnsureColumns($pdo);
        $stmt = $pdo->prepare('SELECT u.id, u.email, u.role, u.mfa_secret, u.mfa_recovery, mp.membership_status, mp.membership_id, mp.cpd_points
                                 FROM users u LEFT JOIN member_profiles mp ON mp.user_id = u.id WHERE u.id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        if (!$user) respond(401, ['error' => 'That sign-in attempt is no longer valid.']);

        $code = (string)($data['code'] ?? '');
        $ok = mfaVerifyCode((string)$user['mfa_secret'], $code);
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
        mfaEnsureColumns($pdo);
        $secret = mfaGenerateSecret();
        $pdo->prepare('UPDATE users SET mfa_secret = ?, mfa_enabled = 0 WHERE id = ?')
            ->execute([$secret, (int)$user['userId']]);
        respond(200, [
            'secret' => $secret,
            'uri' => mfaProvisioningUri($secret, (string)$user['email']),
        ]);
    }

    if ($route === 'auth/mfa/enable' && $method === 'POST') {
        requireModule('mfaGenerateSecret', 'lib/mfa.php');
        $user = auth($config);
        mfaEnsureColumns($pdo);
        $stmt = $pdo->prepare('SELECT mfa_secret FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([(int)$user['userId']]);
        $row = $stmt->fetch();
        if (!$row || empty($row['mfa_secret'])) respond(400, ['error' => 'Start the setup again.']);
        if (!mfaVerifyCode((string)$row['mfa_secret'], (string)(input()['code'] ?? ''))) {
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
        mfaEnsureColumns($pdo);
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
        mfaEnsureColumns($pdo);
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

    if ($route === 'auth/forgot-password' && $method === 'POST') {
        $data = input();
        if (empty($data['email'])) respond(400, ['error' => 'Email is required']);
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
                    $values[] = $data[$key] ?: null;
                }
            }
            if (!$sets) respond(400, ['error' => 'No profile fields provided']);
            $values[] = (int)$user['userId'];
            $pdo->prepare('UPDATE member_profiles SET ' . implode(', ', $sets) . ' WHERE user_id = ?')->execute($values);
            respond(200, mapMember(memberRow($pdo, (int)$user['userId'])) ?? []);
        }
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
        respond(200, array_map(fn($event) => array_merge($event, ['registered' => in_array($event['id'], $registeredIds, true)]), eventCatalog()));
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

