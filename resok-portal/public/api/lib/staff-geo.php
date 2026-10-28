<?php
/**
 * Where staff may sign in from, and the other sign-in protections for staff accounts.
 *
 * Moses (28 Oct 2026): "no staff is allowed to login outside Kenya or outside their county of
 * operations unless they request for it", and "after three failed attempts you are locked out".
 *
 * Who this covers: every active staff account and every administrator. Members are unaffected.
 *
 *  Location. Cloudflare tells the server where each visitor's internet address is: the
 *  CF-IPCountry header always, and cf-region (the county) once "Add visitor location headers"
 *  is switched on in Cloudflare. The check runs when a staff member signs in (after the
 *  password and the second factor, so only the real person can ask for an exception) and
 *  again on every request, so a session cookie taken abroad stops working too.
 *    - Outside Kenya: refused, unless an approved request covers today.
 *    - Outside their counties of operation (set on their staff record): refused, unless an
 *      approved request covers today. County is 'monitor' by default - logged, not refused -
 *      because a mobile-data address is often placed in Nairobi wherever the phone is.
 *      Switch it to 'enforce' once the log shows the counties are read correctly.
 *  Someone refused can ask for access on the spot. Only a super administrator approves it
 *  (Moses, 28 Oct: "super admin should be the one giving approval"), in the staff portal under
 *  Location access. A super admin may approve their own - there may be no other - and that is
 *  logged as a warning; it has to be done before travelling, since abroad they cannot sign in.
 *
 *  Trust. The headers are believed only when the request really came through Cloudflare:
 *  with config 'edge_secret' set, only when Cloudflare's Transform Rule added that secret
 *  header (X-Resok-Edge); without it, only when the address is one of Cloudflare's. A request
 *  that cannot be trusted is refused once edge_secret is set, and only logged before, so the
 *  rule cannot lock everyone out on the day it is deployed.
 *
 *  Working hours (Moses, 28 Oct: "you can login during working hours only from 8-6pm"):
 *  08:00 to 18:00 Nairobi time, Monday to Friday by default, both changeable. Someone still
 *  signed in at 18:00 is signed out at their next click. After-hours access is asked for and
 *  approved like location access. Super admins are exempt, so ICT can answer an emergency.
 *
 *  Lockout. Three wrong passwords for a staff or admin account lock that account for 15
 *  minutes, from anywhere, and email its owner. A completed password reset unlocks it.
 *
 *  New sign-in alert. The first sign-in from a new device or place emails the owner.
 *
 *  Break glass: 'staff_geo' => 'off' in config.local.php turns the location rules off.
 */

const GEO_KENYA_COUNTIES = [
    'Baringo', 'Bomet', 'Bungoma', 'Busia', 'Elgeyo-Marakwet', 'Embu', 'Garissa', 'Homa Bay', 'Isiolo', 'Kajiado',
    'Kakamega', 'Kericho', 'Kiambu', 'Kilifi', 'Kirinyaga', 'Kisii', 'Kisumu', 'Kitui', 'Kwale', 'Laikipia', 'Lamu',
    'Machakos', 'Makueni', 'Mandera', 'Marsabit', 'Meru', 'Migori', 'Mombasa', "Murang'a", 'Nairobi', 'Nakuru', 'Nandi',
    'Narok', 'Nyamira', 'Nyandarua', 'Nyeri', 'Samburu', 'Siaya', 'Taita-Taveta', 'Tana River', 'Tharaka-Nithi',
    'Trans Nzoia', 'Turkana', 'Uasin Gishu', 'Vihiga', 'Wajir', 'West Pokot',
];
const GEO_LOCK_AFTER = 3;          // wrong passwords
const GEO_LOCK_SECONDS = 15 * 60;
const GEO_MAX_EXCEPTION_DAYS = 90;

function geoEnsureTables(PDO $pdo): bool
{
    static $ready = null;
    if ($ready !== null) return $ready;
    try {
        $tail = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        $pdo->exec('CREATE TABLE IF NOT EXISTS staff_geo_settings (name VARCHAR(40) NOT NULL PRIMARY KEY, value VARCHAR(40) NOT NULL,
            updated_by INT UNSIGNED NULL, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP)' . $tail);
        $pdo->exec("CREATE TABLE IF NOT EXISTS staff_geo_requests (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            kind ENUM('abroad','county','hours') NOT NULL,
            country CHAR(2) NULL,
            county VARCHAR(40) NULL,
            from_date DATE NOT NULL,
            to_date DATE NOT NULL,
            reason VARCHAR(500) NOT NULL,
            status ENUM('pending','approved','declined','cancelled') NOT NULL DEFAULT 'pending',
            asked_from VARCHAR(120) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            decided_by INT UNSIGNED NULL,
            decided_at DATETIME NULL,
            decision_note VARCHAR(300) NULL,
            KEY staff_geo_requests_user (user_id, status)
        )" . $tail);
        $pdo->exec('CREATE TABLE IF NOT EXISTS staff_geo_events (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            kind VARCHAR(20) NOT NULL,
            outcome VARCHAR(20) NOT NULL,
            country VARCHAR(8) NULL,
            region VARCHAR(80) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY staff_geo_events_when (created_at),
            KEY staff_geo_events_user (user_id, created_at)
        )' . $tail);
        $pdo->exec('CREATE TABLE IF NOT EXISTS staff_login_seen (
            user_id INT UNSIGNED NOT NULL, sig CHAR(64) NOT NULL,
            first_seen DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, last_seen DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id, sig)
        )' . $tail);
        $pdo->exec('CREATE TABLE IF NOT EXISTS staff_login_locks (
            user_id INT UNSIGNED NOT NULL PRIMARY KEY,
            failures INT UNSIGNED NOT NULL DEFAULT 0,
            first_failure_at DATETIME NULL,
            locked_until DATETIME NULL
        )' . $tail);
        // A table made before working hours existed learns the new kind.
        $pdo->exec("ALTER TABLE staff_geo_requests MODIFY kind ENUM('abroad','county','hours') NOT NULL");
        if (function_exists('staffPortalEnsureTables') && staffPortalEnsureTables($pdo)) {
            $have = array_column($pdo->query('SHOW COLUMNS FROM staff_accounts')->fetchAll(), 'Field');
            if (!in_array('work_counties', $have, true)) {
                $pdo->exec('ALTER TABLE staff_accounts ADD COLUMN work_counties VARCHAR(600) NULL AFTER office');
            }
        }
        return $ready = true;
    } catch (Throwable $e) {
        error_log('Staff location tables unavailable: ' . $e->getMessage());
        return $ready = false;
    }
}

// ---------------------------------------------------------------------------------------
// Counties and settings
// ---------------------------------------------------------------------------------------

function geoNorm(string $s): string
{
    $s = strtolower($s);
    $s = preg_replace('/\b(county|city|district|province)\b/', '', $s);
    return preg_replace('/[^a-z]/', '', (string)$s);
}

/** The county's proper name from anything that spells it ("Nairobi City", "murang'a county"), or null. */
function geoCounty(?string $s): ?string
{
    $n = geoNorm((string)$s);
    if ($n === '') return null;
    foreach (GEO_KENYA_COUNTIES as $c) if (geoNorm($c) === $n) return $c;
    return null;
}

/**
 * country, county and hours are each 'off', 'monitor' or 'enforce'; hoursFrom/hoursTo are
 * HH:MM; days is ISO weekday numbers (1 = Monday), e.g. '12345'.
 */
function geoSettings(PDO $pdo, array $config): array
{
    $s = ['country' => 'enforce', 'county' => 'monitor', 'hours' => 'enforce', 'hoursFrom' => '08:00', 'hoursTo' => '18:00', 'days' => '12345'];
    // The laptop works evenings; there the hours rule is off unless switched on in the portal.
    if (geoLocalRequest()) $s['hours'] = 'off';
    if (strtolower((string)($config['staff_geo'] ?? '')) === 'off') return ['country' => 'off', 'county' => 'off', 'hours' => 'off'] + $s;
    if (!geoEnsureTables($pdo)) return $s;
    foreach ($pdo->query('SELECT name, value FROM staff_geo_settings')->fetchAll() as $r) {
        $n = $r['name']; $val = (string)$r['value'];
        if (in_array($n, ['country', 'county', 'hours'], true) && in_array($val, ['off', 'monitor', 'enforce'], true)) $s[$n] = $val;
        if (in_array($n, ['hoursFrom', 'hoursTo'], true) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $val)) $s[$n] = $val;
        if ($n === 'days' && preg_match('/^[1-7]{1,7}$/', $val)) $s[$n] = $val;
    }
    return $s;
}

/** "8:00 am to 6:00 pm, Monday to Friday" */
function geoHoursLabel(array $s): string
{
    $t = static fn($hm) => date('g:i a', strtotime('2000-01-01 ' . $hm));
    $names = [1 => 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
    $d = str_split($s['days']);
    sort($d);
    $run = implode('', $d);
    $days = strlen($run) > 2 && str_contains('1234567', $run) ? $names[(int)$d[0]] . ' to ' . $names[(int)end($d)]
          : implode(', ', array_map(static fn($x) => $names[(int)$x], $d));
    return $t($s['hoursFrom']) . ' to ' . $t($s['hoursTo']) . ', ' . $days;
}

/** Inside working hours right now (Nairobi time; index.php sets the zone). */
function geoInHours(array $s, ?int $now = null): bool
{
    $now = $now ?? time();
    if (!str_contains($s['days'], date('N', $now))) return false;
    $hm = date('H:i', $now);
    return $hm >= $s['hoursFrom'] && $hm < $s['hoursTo'];
}

// ---------------------------------------------------------------------------------------
// Where the visitor is
// ---------------------------------------------------------------------------------------

/** The developer's laptop: the local vhost sets RESOK_LOCAL and the request is from this machine. */
function geoLocalRequest(): bool
{
    $flag = (string)($_SERVER['RESOK_LOCAL'] ?? $_SERVER['REDIRECT_RESOK_LOCAL'] ?? getenv('RESOK_LOCAL') ?: '');
    return $flag === '1' && in_array((string)($_SERVER['REMOTE_ADDR'] ?? ''), ['127.0.0.1', '::1'], true);
}

/**
 * @return array{trusted: bool, country: string, region: ?string, county: ?string, city: ?string, label: string}
 */
function geoVisitor(array $config): array
{
    $local = geoLocalRequest();
    $secret = (string)($config['edge_secret'] ?? '');
    if ($local) {
        $trusted = true;
    } elseif ($secret !== '') {
        $trusted = hash_equals($secret, (string)($_SERVER['HTTP_X_RESOK_EDGE'] ?? ''));
    } else {
        $trusted = false;
        $remote = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        $ranges = array_merge(defined('CLOUDFLARE_RANGES') ? CLOUDFLARE_RANGES : [], (array)($config['trusted_proxy_ranges'] ?? []));
        foreach ($ranges as $range) {
            if (function_exists('ipInRange') && ipInRange($remote, (string)$range)) { $trusted = true; break; }
        }
    }
    $country = strtoupper(trim((string)($_SERVER['HTTP_CF_IPCOUNTRY'] ?? '')));
    if ($local && $country === '') $country = 'KE';   // the laptop is in Kenya
    $region = trim((string)($_SERVER['HTTP_CF_REGION'] ?? '')) ?: null;
    $city = trim((string)($_SERVER['HTTP_CF_IPCITY'] ?? '')) ?: null;
    $county = $country === 'KE' ? geoCounty($region) : null;
    $label = $country === '' ? 'an unknown place' : geoCountryName($country);
    if ($county) $label = $county . ' County, ' . $label;
    elseif ($city) $label = $city . ', ' . $label;
    return ['trusted' => $trusted, 'country' => $country, 'region' => $region, 'county' => $county, 'city' => $city, 'label' => $label];
}

function geoCountryName(string $code): string
{
    $names = ['KE' => 'Kenya', 'UG' => 'Uganda', 'TZ' => 'Tanzania', 'RW' => 'Rwanda', 'ET' => 'Ethiopia', 'SO' => 'Somalia',
              'SS' => 'South Sudan', 'ZA' => 'South Africa', 'NG' => 'Nigeria', 'GB' => 'the United Kingdom', 'US' => 'the United States',
              'IN' => 'India', 'AE' => 'the United Arab Emirates', 'T1' => 'the Tor network', 'XX' => 'an unknown country'];
    if (isset($names[$code])) return $names[$code];
    if (class_exists('Locale')) {
        $n = Locale::getDisplayRegion('-' . $code, 'en');
        if ($n && $n !== $code) return $n;
    }
    return $code;
}

// ---------------------------------------------------------------------------------------
// Who is covered, and the decision
// ---------------------------------------------------------------------------------------

/** Staff or administrator: their counties of operation, or null when the rules do not apply. */
function geoSubject(PDO $pdo, int $userId, string $role, array $config = []): ?array
{
    $row = null;
    try {
        $stmt = $pdo->prepare('SELECT * FROM staff_accounts WHERE user_id = ? AND (active IS NULL OR active = 1)');
        $stmt->execute([$userId]);
        $row = $stmt->fetch() ?: null;
    } catch (Throwable $e) {
        $row = null;   // no staff table yet
    }
    if (!$row && $role !== 'admin') return null;
    $counties = [];
    foreach (explode(',', (string)($row['work_counties'] ?? '')) as $c) if ($n = geoCounty($c)) $counties[] = $n;
    $super = false;
    if ($role === 'admin' && !empty($config['super_admins']) && function_exists('isSuperAdmin')) {
        $e = $pdo->prepare('SELECT email FROM users WHERE id = ?');
        $e->execute([$userId]);
        $super = isSuperAdmin(['role' => $role, 'email' => (string)$e->fetchColumn()], $config);
    }
    return ['counties' => array_values(array_unique($counties)), 'staff' => (bool)$row, 'super' => $super];
}

/** An approved request covering today for this kind of absence. */
function geoExceptionCovers(PDO $pdo, int $userId, string $kind, array $v): bool
{
    $stmt = $pdo->prepare("SELECT country, county FROM staff_geo_requests WHERE user_id = ? AND kind = ? AND status = 'approved'
                             AND from_date <= CURDATE() AND to_date >= CURDATE()");
    $stmt->execute([$userId, $kind]);
    foreach ($stmt->fetchAll() as $r) {
        if ($kind === 'hours') return true;
        if ($kind === 'abroad' && ($r['country'] === null || $r['country'] === $v['country'])) return true;
        if ($kind === 'county' && ($r['county'] === null || $r['county'] === $v['county'])) return true;
    }
    return false;
}

function geoEvent(PDO $pdo, int $userId, string $kind, string $outcome, array $v): void
{
    try {
        // Monitoring is a daily fact per person, not a line per click.
        if ($outcome !== 'refused') {
            $seen = $pdo->prepare('SELECT 1 FROM staff_geo_events WHERE user_id = ? AND kind = ? AND outcome = ? AND COALESCE(region, \'\') = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 DAY) LIMIT 1');
            $seen->execute([$userId, $kind, $outcome, (string)($v['county'] ?? $v['region'] ?? '')]);
            if ($seen->fetchColumn()) return;
        }
        $pdo->prepare('INSERT INTO staff_geo_events (user_id, kind, outcome, country, region) VALUES (?, ?, ?, ?, ?)')
            ->execute([$userId, $kind, $outcome, $v['country'] ?: null, $v['county'] ?? $v['region']]);
    } catch (Throwable $e) {
        error_log('Staff location event not recorded: ' . $e->getMessage());
    }
}

/**
 * Null when this person may be here; otherwise why not. Records what it sees either way.
 *
 * @return null|array{kind: string, message: string, where: string, visitor: array}
 */
function geoCheck(PDO $pdo, array $config, int $userId, string $role): ?array
{
    // Members first and cheaply: they are nearly every request, and none of this is about them.
    $subject = geoSubject($pdo, $userId, $role, $config);
    if ($subject === null) return null;
    $settings = geoSettings($pdo, $config);
    if ($settings['country'] === 'off' && $settings['county'] === 'off' && $settings['hours'] === 'off') return null;
    if (!geoEnsureTables($pdo)) return null;
    $v = geoVisitor($config);

    // Working hours first: it needs no location, so it holds even where location cannot be read.
    if ($settings['hours'] !== 'off' && !$subject['super'] && !geoInHours($settings)) {
        if (geoExceptionCovers($pdo, $userId, 'hours', $v)) {
            geoEvent($pdo, $userId, 'hours', 'approved', $v);
        } elseif ($settings['hours'] === 'enforce') {
            geoEvent($pdo, $userId, 'hours', 'refused', $v);
            return ['kind' => 'hours', 'where' => 'outside working hours', 'visitor' => $v,
                    'message' => 'Staff accounts can be used ' . geoHoursLabel($settings) . ' (Nairobi time). If you need to work outside those hours, ask below and ICT will be notified.'];
        } else {
            geoEvent($pdo, $userId, 'hours', 'noted', $v);
        }
    }

    if (!$v['trusted']) {
        // Behind Cloudflare and still untrusted: somebody went round it. Before edge_secret is
        // set this is how every request looks on some hosts, so it is only noted.
        if (!empty($config['edge_secret']) && $settings['country'] === 'enforce') {
            geoEvent($pdo, $userId, 'untrusted', 'refused', $v);
            return ['kind' => 'untrusted', 'where' => 'an unverified connection', 'visitor' => $v,
                    'message' => 'Staff sign-in is only possible through the ReSoK website. Open resok.org and sign in again.'];
        }
        geoEvent($pdo, $userId, 'untrusted', 'noted', $v);
        return null;
    }

    if ($settings['country'] !== 'off' && $v['country'] !== 'KE') {
        if (geoExceptionCovers($pdo, $userId, 'abroad', $v)) { geoEvent($pdo, $userId, 'abroad', 'approved', $v); return null; }
        if ($settings['country'] === 'enforce') {
            geoEvent($pdo, $userId, 'abroad', 'refused', $v);
            return ['kind' => 'abroad', 'where' => $v['label'], 'visitor' => $v,
                    'message' => 'Staff accounts can only be used in Kenya. You appear to be in ' . $v['label'] . '. If you need access while away, ask for it below and ICT will be notified.'];
        }
        geoEvent($pdo, $userId, 'abroad', 'noted', $v);
        return null;
    }

    if ($settings['county'] !== 'off' && $subject['counties'] && $v['country'] === 'KE') {
        if ($v['county'] === null) { geoEvent($pdo, $userId, 'county-unknown', 'noted', $v); return null; }
        if (in_array($v['county'], $subject['counties'], true)) return null;
        if (geoExceptionCovers($pdo, $userId, 'county', $v)) { geoEvent($pdo, $userId, 'county', 'approved', $v); return null; }
        if ($settings['county'] === 'enforce') {
            geoEvent($pdo, $userId, 'county', 'refused', $v);
            return ['kind' => 'county', 'where' => $v['label'], 'visitor' => $v,
                    'message' => 'Your account is set up for ' . implode(', ', $subject['counties']) . ', and you appear to be in ' . $v['label'] . '. If you are working away, ask for access below and ICT will be notified.'];
        }
        geoEvent($pdo, $userId, 'county', 'noted', $v);
    }
    return null;
}

/** Answers a refused sign-in: the reason, and a short-lived pass to ask for an exception. */
function geoRefuse(PDO $pdo, array $config, int $userId, array $block): void
{
    securityLog($pdo, $config, 'staff_location_refused', 'warning', 'login', 'Staff sign-in refused from ' . $block['where'], $userId);
    $body = b64url(json_encode(['u' => $userId, 'k' => $block['kind'], 'c' => $block['visitor']['country'] ?? '',
                                'r' => $block['visitor']['county'] ?? null, 'w' => $block['where'], 'exp' => time() + 1800]));
    respond(403, [
        'error' => $block['message'],
        'geoBlocked' => true,
        'where' => $block['where'],
        // Untrusted connections are not something an exception can fix.
        'requestToken' => $block['kind'] === 'untrusted' ? null : $body . '.' . b64url(hash_hmac('sha256', $body, geoKey($config), true)),
    ]);
}

function geoKey(array $config): string
{
    return hash_hmac('sha256', 'resok-staff-geo', (string)$config['jwt_secret']);
}

/** Called by auth() on every request with a session: the same rule as at sign-in. */
function geoGuardSession(PDO $pdo, array $config, array $session): void
{
    if (in_array((string)($GLOBALS['route'] ?? ''), ['auth/logout'], true)) return;
    $block = geoCheck($pdo, $config, (int)$session['userId'], (string)($session['role'] ?? ''));
    if ($block === null) return;
    // The cookie is ended here, not just refused: one that crossed a border stays dead.
    if (!empty($session['jti'])) sessionRevokeToken($pdo, (string)$session['jti'], (int)($session['exp'] ?? time() + 3600));
    clearAuthCookie();
    geoRefuse($pdo, $config, (int)$session['userId'], $block);
}

/** The sign-in gate: run once both factors have passed, before the session is issued. */
function geoGuardSignIn(PDO $pdo, array $config, int $userId, string $role): void
{
    $block = geoCheck($pdo, $config, $userId, $role);
    if ($block !== null) geoRefuse($pdo, $config, $userId, $block);
    geoNewSignInAlert($pdo, $config, $userId, $role);
}

// ---------------------------------------------------------------------------------------
// Lockout after three wrong passwords
// ---------------------------------------------------------------------------------------

/** Refuses a staff or admin account that is locked. Same words as the general rate limit. */
function geoLockCheck(PDO $pdo, array $config, array $user): void
{
    if (geoSubject($pdo, (int)$user['id'], (string)$user['role']) === null || !geoEnsureTables($pdo)) return;
    $stmt = $pdo->prepare('SELECT locked_until FROM staff_login_locks WHERE user_id = ? AND locked_until > NOW()');
    $stmt->execute([(int)$user['id']]);
    $until = $stmt->fetchColumn();
    if (!$until) return;
    $minutes = max(1, (int)ceil((strtotime((string)$until) - time()) / 60));
    securityLog($pdo, $config, 'staff_locked_attempt', 'warning', 'login', 'Sign-in tried on a locked staff account', (int)$user['id']);
    header('Retry-After: ' . ($minutes * 60));
    respond(429, ['error' => "This account is locked after " . GEO_LOCK_AFTER . " wrong passwords. Try again in {$minutes} minute" . ($minutes === 1 ? '' : 's') . ', or reset your password to unlock it now.',
                  'reason' => 'locked']);
}

function geoLockFailure(PDO $pdo, array $config, array $user): void
{
    if (geoSubject($pdo, (int)$user['id'], (string)$user['role']) === null || !geoEnsureTables($pdo)) return;
    $id = (int)$user['id'];
    // Failures count within the lock window; an old one does not add up with today's typo.
    $pdo->prepare('INSERT INTO staff_login_locks (user_id, failures, first_failure_at) VALUES (?, 1, NOW())
                   ON DUPLICATE KEY UPDATE
                     failures = IF(first_failure_at IS NULL OR first_failure_at < DATE_SUB(NOW(), INTERVAL ? SECOND), 1, failures + 1),
                     first_failure_at = IF(first_failure_at IS NULL OR first_failure_at < DATE_SUB(NOW(), INTERVAL ? SECOND), NOW(), first_failure_at)')
        ->execute([$id, GEO_LOCK_SECONDS, GEO_LOCK_SECONDS]);
    $lock = $pdo->prepare('UPDATE staff_login_locks SET locked_until = DATE_ADD(NOW(), INTERVAL ? SECOND), failures = 0, first_failure_at = NULL
                           WHERE user_id = ? AND failures >= ?');
    $lock->execute([GEO_LOCK_SECONDS, $id, GEO_LOCK_AFTER]);
    if ($lock->rowCount() === 0) return;
    securityLog($pdo, $config, 'staff_account_locked', 'critical', 'login', 'Staff account locked after ' . GEO_LOCK_AFTER . ' wrong passwords', $id);
    $v = geoVisitor($config);
    geoMail($pdo, $config, $id, 'Your ReSoK account was locked',
        'Someone entered the wrong password for your ReSoK account ' . GEO_LOCK_AFTER . ' times, so it is locked for 15 minutes.',
        ['When' => date('j M Y, H:i') . ' (Nairobi time)', 'From' => $v['label']],
        'If this was you, wait 15 minutes or reset your password. If it was not you, reset your password now and tell ICT: someone may be trying to get into your account.');
}

function geoLockClear(PDO $pdo, int $userId): void
{
    try {
        if (geoEnsureTables($pdo)) $pdo->prepare('DELETE FROM staff_login_locks WHERE user_id = ?')->execute([$userId]);
    } catch (Throwable $e) {
        error_log('Could not clear a staff lock: ' . $e->getMessage());
    }
}

// ---------------------------------------------------------------------------------------
// New sign-in alert, and stronger passwords
// ---------------------------------------------------------------------------------------

/** "Chrome on Windows" from a user agent, roughly - enough to recognise one's own device. */
function geoDevice(): string
{
    $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    $browser = preg_match('/Edg\//', $ua) ? 'Edge' : (preg_match('/OPR\/|Opera/', $ua) ? 'Opera' : (preg_match('/Firefox\//', $ua) ? 'Firefox'
             : (preg_match('/SamsungBrowser/', $ua) ? 'Samsung Internet' : (preg_match('/Chrome\//', $ua) ? 'Chrome' : (preg_match('/Safari\//', $ua) ? 'Safari' : 'a browser')))));
    $os = preg_match('/Android/', $ua) ? 'Android' : (preg_match('/iPhone|iPad/', $ua) ? 'iPhone/iPad' : (preg_match('/Windows/', $ua) ? 'Windows'
        : (preg_match('/Mac OS X/', $ua) ? 'Mac' : (preg_match('/Linux/', $ua) ? 'Linux' : 'an unknown system'))));
    return $browser . ' on ' . $os;
}

function geoNewSignInAlert(PDO $pdo, array $config, int $userId, string $role): void
{
    try {
        if (geoSubject($pdo, $userId, $role) === null || !geoEnsureTables($pdo)) return;
        $v = geoVisitor($config);
        $device = geoDevice();
        $sig = hash_hmac('sha256', $device . '|' . $v['country'] . '|' . ($v['county'] ?? $v['region'] ?? ''), geoKey($config));
        $known = $pdo->prepare('SELECT COUNT(*) AS n, SUM(sig = ?) AS same FROM staff_login_seen WHERE user_id = ?');
        $known->execute([$sig, $userId]);
        $k = $known->fetch();
        $pdo->prepare('INSERT INTO staff_login_seen (user_id, sig) VALUES (?, ?) ON DUPLICATE KEY UPDATE last_seen = NOW()')->execute([$userId, $sig]);
        // The very first sign-in sets the baseline; telling someone about it tells them nothing.
        if ((int)$k['n'] === 0 || (int)$k['same'] > 0) return;
        geoMail($pdo, $config, $userId, 'New sign-in to your ReSoK account',
            'Your ReSoK account was just signed in to from a device or place it has not been used from before.',
            ['When' => date('j M Y, H:i') . ' (Nairobi time)', 'Device' => $device, 'Approximate location' => $v['label']],
            'If this was you, there is nothing to do. If it was not, reset your password at once and tell ICT.');
    } catch (Throwable $e) {
        error_log('New sign-in alert failed: ' . $e->getMessage());
    }
}

/** Why a staff or admin password is not good enough, or null. Members keep the ordinary rule. */
function geoStaffPasswordProblem(PDO $pdo, int $userId, string $role, string $email, string $password): ?string
{
    if (geoSubject($pdo, $userId, $role) === null) return null;
    if (mb_strlen($password) < 12) return 'Staff passwords must be at least 12 characters. A short sentence you can remember works well.';
    $local = strtolower((string)strstr($email, '@', true));
    $lower = strtolower($password);
    if (($local !== '' && strlen($local) >= 4 && str_contains($lower, $local)) || str_contains($lower, 'resok') || str_contains($lower, 'password')) {
        return 'Choose a password that does not contain your name, email, "resok" or "password".';
    }
    if (geoPasswordBreached($password)) {
        return 'That password has appeared in a data breach elsewhere, so attackers try it first. Please choose a different one.';
    }
    return null;
}

/**
 * Whether a password is in the public breach lists, by k-anonymity: only the first five
 * characters of its SHA-1 leave the server, never the password. Fails open - an unreachable
 * service must not stop a reset.
 */
function geoPasswordBreached(string $password): bool
{
    $hash = strtoupper(sha1($password));
    $ctx = stream_context_create(['http' => ['timeout' => 3, 'header' => "User-Agent: ReSoK-portal\r\nAdd-Padding: true\r\n"]]);
    $body = @file_get_contents('https://api.pwnedpasswords.com/range/' . substr($hash, 0, 5), false, $ctx);
    if (!is_string($body)) return false;
    foreach (explode("\n", $body) as $line) {
        [$suffix, $count] = array_pad(explode(':', trim($line)), 2, '0');
        if ($suffix === substr($hash, 5) && (int)$count > 0) return true;
    }
    return false;
}

// ---------------------------------------------------------------------------------------
// Mail
// ---------------------------------------------------------------------------------------

/** One security email to an account's owner: an opening line, a few facts, and what to do. */
function geoMail(PDO $pdo, array $config, int $userId, string $subject, string $lead, array $facts, string $advice): bool
{
    try {
        $stmt = $pdo->prepare('SELECT u.email, mp.title, mp.first_name, mp.surname, s.full_name FROM users u
                               LEFT JOIN member_profiles mp ON mp.user_id = u.id LEFT JOIN staff_accounts s ON s.user_id = u.id WHERE u.id = ?');
        $stmt->execute([$userId]);
        $r = $stmt->fetch();
        if (!$r) return false;
        $first = $r['first_name'] ?: (trim((string)$r['full_name']) !== '' ? strtok((string)$r['full_name'], ' ') : '');
        $surname = $r['surname'] ?: (trim((string)$r['full_name']) !== '' ? trim((string)strrchr(' ' . $r['full_name'], ' ')) : '');
        $greeting = function_exists('emailSalutation') ? emailSalutation($r['title'], $first, $surname) : 'Dear colleague,';
        $text = $greeting . "\n\n" . $lead . "\n\n";
        $rows = '';
        foreach ($facts as $k => $val) {
            $text .= $k . ': ' . $val . "\n";
            $rows .= '<tr><td style="padding:4px 12px 4px 0;color:#667085;font-size:13px">' . htmlspecialchars($k) . '</td><td style="padding:4px 0;font-size:14px;font-weight:600">' . htmlspecialchars((string)$val) . '</td></tr>';
        }
        $text .= "\n" . $advice . "\n\nReSoK ICT";
        $html = brandedEmailHtml($subject,
            '<p style="margin:0 0 12px;font-size:15px;line-height:1.6">' . htmlspecialchars($greeting) . '</p>'
            . '<p style="margin:0 0 12px;font-size:15px;line-height:1.6">' . htmlspecialchars($lead) . '</p>'
            . '<table role="presentation" style="margin:0 0 14px;border-collapse:collapse">' . $rows . '</table>'
            . '<p style="margin:0;font-size:14px;line-height:1.6">' . htmlspecialchars($advice) . '</p>');
        return (new SimpleMailer($config))->send((string)$r['email'], $subject, $text, [], $html);
    } catch (Throwable $e) {
        error_log('Security email failed: ' . $e->getMessage());
        return false;
    }
}

// ---------------------------------------------------------------------------------------
// Requests for access from elsewhere
// ---------------------------------------------------------------------------------------

/** Who decides requests: the super administrators. */
function geoDeciders(PDO $pdo, array $config, int $userId): array
{
    $ids = [];
    $supers = array_map('strtolower', (array)($config['super_admins'] ?? []));
    if ($supers) {
        $in = implode(',', array_fill(0, count($supers), '?'));
        $s = $pdo->prepare("SELECT id FROM users WHERE role = 'admin' AND LOWER(email) IN ($in)");
        $s->execute($supers);
        foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $id) $ids[] = (int)$id;
    }
    return array_values(array_unique($ids));
}

function geoMayDecide(PDO $pdo, array $config, array $gate, int $me, int $requester): bool
{
    return (bool)$gate['super'];
}

function geoDate(?string $s): ?string
{
    $s = trim((string)$s);
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $s);
    return $d && $d->format('Y-m-d') === $s ? $s : null;
}

/** Validates and stores a request, then tells the people who can decide it. Returns the new id. */
function geoCreateRequest(PDO $pdo, array $config, int $userId, array $data, ?string $askedFrom): int
{
    $kind = in_array($data['kind'] ?? '', ['county', 'hours'], true) ? (string)$data['kind'] : 'abroad';
    $from = geoDate($data['fromDate'] ?? date('Y-m-d'));
    $to = geoDate($data['toDate'] ?? '');
    $reason = trim(preg_replace('/\s+/u', ' ', is_scalar($data['reason'] ?? null) ? (string)$data['reason'] : '') ?? '');
    if (!$from || !$to) respond(400, ['error' => 'Give the first and last day you need access.']);
    if ($to < $from) respond(400, ['error' => 'The last day cannot be before the first.']);
    if ($to < date('Y-m-d')) respond(400, ['error' => 'Those dates are already past.']);
    if ((new DateTimeImmutable($from))->diff(new DateTimeImmutable($to))->days >= GEO_MAX_EXCEPTION_DAYS) respond(400, ['error' => 'Ask for at most ' . GEO_MAX_EXCEPTION_DAYS . ' days at a time.']);
    if (mb_strlen($reason) < 5) respond(400, ['error' => 'Say briefly why you need access, e.g. "ERS congress in Vienna".']);
    $country = null;
    $county = null;
    if ($kind === 'hours') {
        // the dates say it all
    } elseif ($kind === 'abroad') {
        $c = strtoupper(trim((string)($data['country'] ?? '')));
        $country = preg_match('/^[A-Z]{2}$/', $c) && $c !== 'KE' ? $c : null;
    } else {
        $county = geoCounty((string)($data['county'] ?? ''));
    }
    $pdo->prepare('INSERT INTO staff_geo_requests (user_id, kind, country, county, from_date, to_date, reason, asked_from) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$userId, $kind, $country, $county, $from, $to, mb_substr($reason, 0, 500), $askedFrom ? mb_substr($askedFrom, 0, 120) : null]);
    $id = (int)$pdo->lastInsertId();

    $n = $pdo->prepare('SELECT COALESCE(NULLIF(s.full_name, \'\'), u.email) FROM users u LEFT JOIN staff_accounts s ON s.user_id = u.id WHERE u.id = ?');
    $n->execute([$userId]);
    $name = (string)$n->fetchColumn();
    $where = $kind === 'hours' ? 'outside working hours' : ($kind === 'abroad' ? ($country ? geoCountryName($country) : 'outside Kenya') : ($county ? $county . ' County' : 'another county'));
    $title = $name . ' asks to use their account ' . ($kind === 'hours' ? '' : ($kind === 'abroad' ? 'in ' : 'from ')) . $where . ', ' . date('j M', strtotime($from)) . ' to ' . date('j M', strtotime($to));
    foreach (geoDeciders($pdo, $config, $userId) as $d) {
        if (function_exists('staffNotify')) staffNotify($pdo, $d, 'location', $title, 'staff#location', 'geo-' . $id . '-' . $d);
        geoMail($pdo, $config, $d, 'Location access request: ' . $name, $title . '.',
            ['Reason' => $reason, 'Asked from' => $askedFrom ?: 'the staff portal'],
            'Approve or decline it in the staff portal under Location access. If you do not recognise this, decline it and tell ICT.');
    }
    securityLog($pdo, $config, 'staff_location_requested', 'info', 'staff', $title, $userId);
    return $id;
}

function geoRequestRow(array $r): array
{
    return [
        'id' => (int)$r['id'], 'userId' => (int)$r['user_id'], 'name' => $r['name'] ?? null, 'kind' => $r['kind'],
        'where' => $r['kind'] === 'hours' ? 'Outside working hours' : ($r['kind'] === 'abroad' ? ($r['country'] ? geoCountryName($r['country']) : 'Anywhere outside Kenya') : ($r['county'] ? $r['county'] . ' County' : 'Any county in Kenya')),
        'fromDate' => $r['from_date'], 'toDate' => $r['to_date'], 'reason' => $r['reason'], 'status' => $r['status'],
        'askedFrom' => $r['asked_from'], 'createdAt' => $r['created_at'], 'decidedBy' => $r['decider'] ?? null,
        'decidedAt' => $r['decided_at'], 'note' => $r['decision_note'],
    ];
}

/** auth/geo-request: asked from the refused sign-in, with the pass that refusal handed out. */
function geoPublicRequest(PDO $pdo, array $config): void
{
    if (!geoEnsureTables($pdo)) respond(503, ['error' => 'Requests cannot be taken just now. Please call ICT.']);
    $data = input();
    [$body, $sig] = array_pad(explode('.', (string)($data['token'] ?? ''), 2), 2, '');
    if ($body === '' || !hash_equals(b64url(hash_hmac('sha256', $body, geoKey($config), true)), $sig)) respond(400, ['error' => 'This request link is not valid. Sign in again to ask.']);
    $t = json_decode(base64_decode(strtr($body, '-_', '+/')), true);
    if (!is_array($t) || (int)($t['exp'] ?? 0) < time()) respond(400, ['error' => 'This request has expired. Sign in again to ask.']);
    $userId = (int)$t['u'];
    $open = $pdo->prepare("SELECT COUNT(*) FROM staff_geo_requests WHERE user_id = ? AND status = 'pending'");
    $open->execute([$userId]);
    if ((int)$open->fetchColumn() >= 3) respond(409, ['error' => 'You already have requests waiting. ICT has been told; please call them.']);
    // What was seen fixes what is asked for: someone refused in Uganda asks for Uganda.
    $data['kind'] = in_array($t['k'], ['county', 'hours'], true) ? $t['k'] : 'abroad';
    $data['country'] = $t['c'] ?? '';
    $data['county'] = $t['r'] ?? '';
    geoCreateRequest($pdo, $config, $userId, $data, 'A refused sign-in from ' . (string)($t['w'] ?? 'elsewhere'));
    respond(200, ['message' => 'Your request has been sent. You will be able to sign in from there once it is approved.']);
}

/** staff/location... routes, behind the staff gate. */
function geoHandle(PDO $pdo, array $config, string $route, string $method, array $user, array $gate): void
{
    if (!geoEnsureTables($pdo)) respond(503, ['error' => 'Location access is not available yet.']);
    $me = (int)$user['userId'];
    $list = static function (string $where, array $args) use ($pdo): array {
        $stmt = $pdo->prepare("SELECT r.*, COALESCE(NULLIF(s.full_name, ''), u.email) AS name, COALESCE(NULLIF(ds.full_name, ''), du.email) AS decider
                                 FROM staff_geo_requests r JOIN users u ON u.id = r.user_id LEFT JOIN staff_accounts s ON s.user_id = r.user_id
                                 LEFT JOIN users du ON du.id = r.decided_by LEFT JOIN staff_accounts ds ON ds.user_id = r.decided_by
                                WHERE $where ORDER BY r.created_at DESC LIMIT 200");
        $stmt->execute($args);
        return array_map('geoRequestRow', $stmt->fetchAll());
    };

    if ($route === 'staff/location' && $method === 'GET') {
        $subject = geoSubject($pdo, $me, (string)$user['role'], $config);
        $v = geoVisitor($config);
        // Only super admins decide, and they see every request.
        $broad = (bool)$gate['super'];
        $queue = $broad ? $list("r.status = 'pending'", []) : [];
        $out = [
            'mine' => $list('r.user_id = ?', [$me]),
            'myCounties' => $subject['counties'] ?? [],
            'seenAs' => ['label' => $v['label'], 'trusted' => $v['trusted'], 'country' => $v['country'], 'county' => $v['county']],
            'settings' => geoSettings($pdo, $config),
            'hoursLabel' => geoHoursLabel(geoSettings($pdo, $config)),
            'exemptFromHours' => (bool)($subject['super'] ?? false),
            'counties' => GEO_KENYA_COUNTIES,
            'queue' => $queue,
            'canDecide' => $broad,
            'isSuper' => $gate['super'],
            'edgeSecret' => !empty($config['edge_secret']),
            // Set-up checks for super admins: what Cloudflare is actually sending on this request,
            // so edge_secret is only put in the config once the header is seen arriving.
            'cloudflare' => !$gate['super'] ? null : (static function () use ($config) {
                $got = (string)($_SERVER['HTTP_X_RESOK_EDGE'] ?? '');
                $want = (string)($config['edge_secret'] ?? '');
                return [
                    'country' => (string)($_SERVER['HTTP_CF_IPCOUNTRY'] ?? ''),
                    'region' => (string)($_SERVER['HTTP_CF_REGION'] ?? ''),
                    'city' => (string)($_SERVER['HTTP_CF_IPCITY'] ?? ''),
                    'edgeHeader' => $got === '' ? 'missing' : ($want === '' ? 'arriving' : (hash_equals($want, $got) ? 'matches' : 'different')),
                    // The last characters only, to confirm the right value was pasted.
                    'edgeTail' => $got === '' ? null : substr($got, -5),
                ];
            })(),
        ];
        if ($broad) {
            $out['recent'] = $list("r.status <> 'pending'", []);
            $ev = $pdo->query("SELECT e.*, COALESCE(NULLIF(s.full_name, ''), u.email) AS name FROM staff_geo_events e JOIN users u ON u.id = e.user_id
                                 LEFT JOIN staff_accounts s ON s.user_id = e.user_id WHERE e.created_at > DATE_SUB(NOW(), INTERVAL 30 DAY) ORDER BY e.id DESC LIMIT 200");
            $out['events'] = array_map(static fn($e) => ['name' => $e['name'], 'kind' => $e['kind'], 'outcome' => $e['outcome'],
                'country' => $e['country'] ? geoCountryName($e['country']) : null, 'region' => $e['region'], 'at' => $e['created_at']], $ev->fetchAll());
        }
        respond(200, $out);
    }

    if ($route === 'staff/location/request' && $method === 'POST') {
        if (!$gate['row'] && $user['role'] !== 'admin') respond(403, ['error' => 'Only staff accounts are covered by location rules.']);
        $id = geoCreateRequest($pdo, $config, $me, input(), null);
        staffAudit($pdo, $me, 'location_requested', 'location', (string)$id);
        respond(200, ['id' => $id, 'message' => 'Sent. ICT has been notified and will approve or decline it.']);
    }

    if ($route === 'staff/location/settings' && $method === 'POST') {
        if (!$gate['super']) respond(403, ['error' => 'Only a super administrator can change the location rules.']);
        $data = input();
        $v = geoVisitor($config);
        if (isset($data['hoursFrom']) || isset($data['hoursTo']) || isset($data['days'])) {
            $cur = geoSettings($pdo, $config);
            $from = (string)($data['hoursFrom'] ?? $cur['hoursFrom']);
            $to = (string)($data['hoursTo'] ?? $cur['hoursTo']);
            $days = implode('', array_unique(str_split(preg_replace('/[^1-7]/', '', (string)($data['days'] ?? $cur['days'])))));
            if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $from) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $to) || $from >= $to) respond(400, ['error' => 'The start must be before the end, as HH:MM.']);
            if ($days === '') respond(400, ['error' => 'Choose at least one working day.']);
            foreach (['hoursFrom' => $from, 'hoursTo' => $to, 'days' => $days] as $n => $val) {
                $pdo->prepare('INSERT INTO staff_geo_settings (name, value, updated_by) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value), updated_by = VALUES(updated_by)')
                    ->execute([$n, $val, $me]);
            }
            staffAudit($pdo, $me, 'location_rule', 'location', 'hours', $from . '-' . $to . ' days ' . $days);
        }
        foreach (['country', 'county', 'hours'] as $name) {
            if (!isset($data[$name])) continue;
            $value = (string)$data[$name];
            if (!in_array($value, ['off', 'monitor', 'enforce'], true)) respond(400, ['error' => 'Choose off, monitor or enforce.']);
            // Never switch on a rule the server cannot yet see well enough to apply to the person switching it on.
            if ($value === 'enforce' && $name !== 'hours' && (!$v['trusted'] || $v['country'] === '')) {
                respond(409, ['error' => 'The server cannot yet see reliably where you are connecting from, so enforcing now could lock everyone out. Finish the Cloudflare set-up first.']);
            }
            $pdo->prepare('INSERT INTO staff_geo_settings (name, value, updated_by) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value), updated_by = VALUES(updated_by)')
                ->execute([$name, $value, $me]);
            staffAudit($pdo, $me, 'location_rule', 'location', $name, $name . ' => ' . $value);
            securityLog($pdo, $config, 'staff_location_rule', 'warning', 'staff', 'Location rule ' . $name . ' set to ' . $value, $me);
        }
        respond(200, ['settings' => geoSettings($pdo, $config)]);
    }

    if (preg_match('#^staff/location/(\d+)/(approve|decline|cancel)$#', $route, $mm) && $method === 'POST') {
        $stmt = $pdo->prepare('SELECT * FROM staff_geo_requests WHERE id = ?');
        $stmt->execute([(int)$mm[1]]);
        $r = $stmt->fetch();
        if (!$r) respond(404, ['error' => 'No such request.']);
        $action = $mm[2];
        if ($action === 'cancel') {
            if ((int)$r['user_id'] !== $me) respond(403, ['error' => 'Only the person who asked can cancel it.']);
            if (!in_array($r['status'], ['pending', 'approved'], true)) respond(409, ['error' => 'This request is already closed.']);
            $pdo->prepare("UPDATE staff_geo_requests SET status = 'cancelled', decided_at = NOW() WHERE id = ?")->execute([(int)$r['id']]);
            staffAudit($pdo, $me, 'location_cancelled', 'location', (string)$r['id']);
            respond(200, ['ok' => true]);
        }
        if (!geoMayDecide($pdo, $config, $gate, $me, (int)$r['user_id'])) {
            respond(403, ['error' => 'Only a super administrator can approve or decline location and hours requests.']);
        }
        if ((int)$r['user_id'] === $me) securityLog($pdo, $config, 'staff_location_self_decided', 'warning', 'staff', 'A super admin decided their own location request', $me);
        if ($r['status'] !== 'pending') respond(409, ['error' => 'This request has already been decided.']);
        $note = mb_substr(trim((string)(input()['note'] ?? '')), 0, 300);
        if ($action === 'decline' && $note === '') respond(400, ['error' => 'Say why, so they know what to do instead.']);
        $pdo->prepare('UPDATE staff_geo_requests SET status = ?, decided_by = ?, decided_at = NOW(), decision_note = ? WHERE id = ?')
            ->execute([$action === 'approve' ? 'approved' : 'declined', $me, $note !== '' ? $note : null, (int)$r['id']]);
        $row = geoRequestRow($r);
        $msg = 'Your request to use your account ' . ($r['kind'] === 'hours' ? 'outside working hours' : ($r['kind'] === 'abroad' ? 'in ' : 'from ') . $row['where']) . ' was ' . ($action === 'approve' ? 'approved' : 'declined') . '.';
        if (function_exists('staffNotify')) staffNotify($pdo, (int)$r['user_id'], 'location', $msg, 'staff#location');
        geoMail($pdo, $config, (int)$r['user_id'], $action === 'approve' ? 'Location access approved' : 'Location access declined', $msg,
            ['Dates' => date('j M Y', strtotime($r['from_date'])) . ' to ' . date('j M Y', strtotime($r['to_date']))] + ($note !== '' ? ['Note' => $note] : []),
            $action === 'approve' ? 'You can sign in as approved on those dates.' : 'Speak to ICT if you still need access.');
        staffAudit($pdo, $me, 'location_' . ($action === 'approve' ? 'approved' : 'declined'), 'location', (string)$r['id'], $row['where']);
        securityLog($pdo, $config, 'staff_location_' . ($action === 'approve' ? 'approved' : 'declined'), 'info', 'staff', $msg, (int)$r['user_id']);
        respond(200, ['ok' => true]);
    }
}
