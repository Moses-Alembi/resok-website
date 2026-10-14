<?php
declare(strict_types=1);

/**
 * Find a Specialist: a public directory of ReSoK members who have chosen to be listed, so
 * patients and referring clinicians can find respiratory care near them.
 *
 * Four rules shape it:
 *
 * 1. Opt-in, and only while a member. Nobody appears until they switch their listing on,
 *    and a listing drops out by itself when the membership is no longer active.
 *
 * 2. The member writes the listing. Name and title come from the profile; everything else
 *    shown - category, services, practice locations, public contact details, photo - is
 *    entered for the directory, so nothing private (ID number, personal mobile, home address)
 *    can leak into it.
 *
 * 3. Contact details resist harvesting. Search results never include them; a visitor asks
 *    for one listing's contacts at a time, and that is rate limited per visitor.
 *
 * 4. Admins can hide a listing, with a reason the member sees.
 */

const DIRECTORY_CATEGORIES = [
    'pulmonologist' => 'Pulmonologist / Chest physician',
    'paediatric_pulmonologist' => 'Paediatric pulmonologist',
    'physician' => 'Physician (internal medicine)',
    'paediatrician' => 'Paediatrician',
    'thoracic_surgeon' => 'Thoracic surgeon',
    'clinical_officer' => 'Clinical officer',
    'respiratory_nurse' => 'Respiratory nurse',
    'respiratory_therapist' => 'Respiratory therapist',
    'physiotherapist' => 'Physiotherapist',
    'radiologist' => 'Radiologist',
    'pathologist' => 'Pathologist',
    'other' => 'Other health professional',
];
const DIRECTORY_PAGE_SIZE = 20;
const DIRECTORY_MAX_LOCATIONS = 3;

function directoryEnsureTables(PDO $pdo): bool
{
    static $ready = null;
    if ($ready !== null) return $ready;
    try {
        $pdo->exec(directorySchema());
        return $ready = true;
    } catch (Throwable $e) {
        error_log('Directory table unavailable: ' . $e->getMessage());
        return $ready = false;
    }
}

/** Kept in step with server/schema-directory.sql. */
function directorySchema(): string
{
    return "CREATE TABLE IF NOT EXISTS directory_listings (
            member_profile_id INT UNSIGNED NOT NULL,
            listed TINYINT(1) NOT NULL DEFAULT 0,
            category VARCHAR(40) NULL,
            specialties VARCHAR(400) NULL,
            locations TEXT NULL,
            counties VARCHAR(300) NULL,
            public_phone VARCHAR(30) NULL,
            public_email VARCHAR(190) NULL,
            website VARCHAR(300) NULL,
            bio VARCHAR(800) NULL,
            languages VARCHAR(200) NULL,
            accepting_referrals TINYINT(1) NOT NULL DEFAULT 1,
            show_photo TINYINT(1) NOT NULL DEFAULT 0,
            hidden_by_admin TINYINT(1) NOT NULL DEFAULT 0,
            hidden_reason VARCHAR(300) NULL,
            hidden_by INT UNSIGNED NULL,
            consent_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (member_profile_id),
            KEY directory_listings_visible (listed, hidden_by_admin, category)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
}

function directoryText($value, int $max): string
{
    $text = (string)preg_replace('/[\x00-\x1F\x7F]/u', ' ', (string)$value);
    return mb_substr(trim((string)preg_replace('/\s+/u', ' ', $text)), 0, $max);
}

/** The condition every public query adds: switched on, not hidden, and a current member. */
const DIRECTORY_VISIBLE = "d.listed = 1 AND d.hidden_by_admin = 0 AND mp.membership_status = 'active'";

function directoryName(array $row): string
{
    return trim(implode(' ', array_filter([$row['title'] ?? '', $row['first_name'] ?? '', $row['surname'] ?? ''])));
}

function directoryPhotoUrl(array $row): ?string
{
    return ((int)$row['show_photo'] === 1 && !empty($row['profile_image']))
        ? 'resok-portal/public/api/index.php?route=directory/photo/' . (int)$row['member_profile_id'] : null;
}

/** A listing as the public sees it in search results: never any contact details. */
function directoryPublicShape(array $row): array
{
    $locations = json_decode((string)$row['locations'], true);
    return [
        'id' => (int)$row['member_profile_id'],
        'name' => directoryName($row),
        'category' => $row['category'], 'categoryLabel' => DIRECTORY_CATEGORIES[$row['category']] ?? null,
        'specialties' => $row['specialties'], 'bio' => $row['bio'], 'languages' => $row['languages'],
        'locations' => is_array($locations) ? $locations : [],
        'acceptingReferrals' => (bool)$row['accepting_referrals'],
        'hasContact' => ($row['public_phone'] ?? '') !== '' || ($row['public_email'] ?? '') !== '' || ($row['website'] ?? '') !== '',
        'photo' => directoryPhotoUrl($row),
    ];
}

/** @return array{results: array, total: int, page: int, pages: int} */
function directorySearch(PDO $pdo, array $query): array
{
    $where = [DIRECTORY_VISIBLE];
    $params = [];
    $q = directoryText($query['q'] ?? '', 80);
    if ($q !== '') {
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
        $where[] = '(CONCAT_WS(" ", mp.title, mp.first_name, mp.surname) LIKE ? OR d.specialties LIKE ? OR d.locations LIKE ? OR d.bio LIKE ?)';
        array_push($params, $like, $like, $like, $like);
    }
    if (!empty($query['category']) && array_key_exists((string)$query['category'], DIRECTORY_CATEGORIES)) {
        $where[] = 'd.category = ?';
        $params[] = (string)$query['category'];
    }
    $county = directoryText($query['county'] ?? '', 60);
    if ($county !== '') {
        $where[] = 'd.counties LIKE ?';
        $params[] = '%|' . str_replace(['%', '_'], ['\\%', '\\_'], $county) . '|%';
    }
    $sqlWhere = implode(' AND ', $where);
    $count = $pdo->prepare("SELECT COUNT(*) FROM directory_listings d JOIN member_profiles mp ON mp.id = d.member_profile_id WHERE {$sqlWhere}");
    $count->execute($params);
    $total = (int)$count->fetchColumn();
    $pages = max(1, (int)ceil($total / DIRECTORY_PAGE_SIZE));
    $page = max(1, min($pages, (int)($query['page'] ?? 1)));
    $offset = ($page - 1) * DIRECTORY_PAGE_SIZE;
    $stmt = $pdo->prepare("SELECT d.*, mp.title, mp.first_name, mp.surname, mp.profile_image
                           FROM directory_listings d JOIN member_profiles mp ON mp.id = d.member_profile_id
                           WHERE {$sqlWhere} ORDER BY mp.surname, mp.first_name LIMIT " . DIRECTORY_PAGE_SIZE . " OFFSET {$offset}");
    $stmt->execute($params);
    return ['results' => array_map('directoryPublicShape', $stmt->fetchAll()), 'total' => $total, 'page' => $page, 'pages' => $pages];
}

function directoryVisibleRow(PDO $pdo, int $memberId): ?array
{
    $stmt = $pdo->prepare('SELECT d.*, mp.title, mp.first_name, mp.surname, mp.profile_image FROM directory_listings d
                           JOIN member_profiles mp ON mp.id = d.member_profile_id WHERE d.member_profile_id = ? AND ' . DIRECTORY_VISIBLE . ' LIMIT 1');
    $stmt->execute([$memberId]);
    return $stmt->fetch() ?: null;
}

/** The counties that have at least one visible listing, for the filter. */
function directoryCounties(PDO $pdo): array
{
    $all = [];
    foreach ($pdo->query('SELECT d.counties FROM directory_listings d JOIN member_profiles mp ON mp.id = d.member_profile_id WHERE ' . DIRECTORY_VISIBLE)->fetchAll() as $r) {
        foreach (array_filter(explode('|', (string)$r['counties'])) as $c) $all[$c] = true;
    }
    $list = array_keys($all);
    sort($list);
    return $list;
}

// ---------------------------------------------------------------------------------------
// The member's own listing
// ---------------------------------------------------------------------------------------

function directoryOwn(PDO $pdo, array $member): array
{
    $stmt = $pdo->prepare('SELECT * FROM directory_listings WHERE member_profile_id = ? LIMIT 1');
    $stmt->execute([(int)$member['id']]);
    $row = $stmt->fetch() ?: null;
    $locations = $row ? json_decode((string)$row['locations'], true) : null;
    return [
        'name' => directoryName($member),
        'membershipActive' => $member['membership_status'] === 'active',
        'hasPhoto' => !empty($member['profile_image']),
        // Suggestions from the profile, for a member setting up a listing for the first time.
        'suggest' => ['specialties' => $member['specialization'], 'facility' => $member['institution'], 'county' => $member['county']],
        'listing' => $row ? [
            'listed' => (bool)$row['listed'], 'category' => $row['category'], 'specialties' => $row['specialties'],
            'locations' => is_array($locations) ? $locations : [], 'publicPhone' => $row['public_phone'], 'publicEmail' => $row['public_email'],
            'website' => $row['website'], 'bio' => $row['bio'], 'languages' => $row['languages'],
            'acceptingReferrals' => (bool)$row['accepting_referrals'], 'showPhoto' => (bool)$row['show_photo'],
            'hidden' => (bool)$row['hidden_by_admin'], 'hiddenReason' => $row['hidden_reason'],
        ] : null,
        'categories' => DIRECTORY_CATEGORIES,
    ];
}

/** @return ?string an error, or null when saved */
function directorySave(PDO $pdo, array $member, array $data): ?string
{
    $listed = !empty($data['listed']);
    $category = array_key_exists((string)($data['category'] ?? ''), DIRECTORY_CATEGORIES) ? $data['category'] : null;

    $locations = [];
    $counties = [];
    foreach (array_slice((array)($data['locations'] ?? []), 0, DIRECTORY_MAX_LOCATIONS) as $loc) {
        if (!is_array($loc)) continue;
        $l = ['facility' => directoryText($loc['facility'] ?? '', 160), 'town' => directoryText($loc['town'] ?? '', 80), 'county' => directoryText($loc['county'] ?? '', 60)];
        if ($l['facility'] === '' && $l['town'] === '' && $l['county'] === '') continue;
        $locations[] = $l;
        if ($l['county'] !== '') $counties[$l['county']] = true;
    }

    $phone = directoryText($data['publicPhone'] ?? '', 30);
    if ($phone !== '') {
        $digits = preg_replace('/[\s()-]/', '', $phone);
        if (preg_match('/^0[17]\d{8}$/', $digits)) $digits = '+254' . substr($digits, 1);
        if (!preg_match('/^\+?\d{7,15}$/', $digits)) return 'Enter the public phone number as digits, for example 0712 345 678 or +254 20 123 4567.';
        $phone = $digits;
    }
    $email = strtolower(directoryText($data['publicEmail'] ?? '', 190));
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) return 'The public email address is not valid.';
    $website = directoryText($data['website'] ?? '', 300);
    if ($website !== '' && !preg_match('#^https?://[^\s]+\.[^\s]+#i', $website)) return 'The website must start with https://';

    if ($listed) {
        if ($member['membership_status'] !== 'active') return 'Only active members can be listed. Renew your membership first.';
        if (!$category) return 'Choose how you want to be listed.';
        if (!$locations) return 'Add at least one place where you practise.';
        if (!$counties) return 'Give the county for each place where you practise.';
        if (empty($data['consent'])) return 'Please confirm that these details may be published on resok.org.';
    }

    $pdo->prepare('INSERT INTO directory_listings (member_profile_id, listed, category, specialties, locations, counties, public_phone, public_email,
                   website, bio, languages, accepting_referrals, show_photo, consent_at)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, IF(?, NOW(), NULL))
                   ON DUPLICATE KEY UPDATE listed = VALUES(listed), category = VALUES(category), specialties = VALUES(specialties),
                     locations = VALUES(locations), counties = VALUES(counties), public_phone = VALUES(public_phone),
                     public_email = VALUES(public_email), website = VALUES(website), bio = VALUES(bio), languages = VALUES(languages),
                     accepting_referrals = VALUES(accepting_referrals), show_photo = VALUES(show_photo),
                     consent_at = IF(VALUES(listed) = 1, COALESCE(consent_at, NOW()), consent_at)')
        ->execute([
            (int)$member['id'], $listed ? 1 : 0, $category, directoryText($data['specialties'] ?? '', 400) ?: null,
            json_encode($locations, JSON_UNESCAPED_UNICODE), $counties ? '|' . implode('|', array_keys($counties)) . '|' : null,
            $phone ?: null, $email ?: null, $website ?: null, directoryText($data['bio'] ?? '', 800) ?: null,
            directoryText($data['languages'] ?? '', 200) ?: null, !empty($data['acceptingReferrals']) ? 1 : 0,
            !empty($data['showPhoto']) ? 1 : 0, $listed ? 1 : 0,
        ]);
    return null;
}

// ---------------------------------------------------------------------------------------
// Routes
// ---------------------------------------------------------------------------------------

/**
 * Every directory/* route. Called from index.php.
 *
 * @return never
 */
function directoryHandle(PDO $pdo, array $config, string $route, string $method): void
{
    if (!directoryEnsureTables($pdo)) {
        respond(503, ['error' => 'The directory is unavailable: its table could not be created. Import resok-portal/server/schema-directory.sql.']);
    }

    // ------------------------------------------------------------- public
    if ($route === 'directory/search' && $method === 'GET') {
        respond(200, directorySearch($pdo, $_GET) + ['categories' => DIRECTORY_CATEGORIES, 'counties' => directoryCounties($pdo)]);
    }

    if (preg_match('#^directory/(\d+)/contact$#', $route, $m) && $method === 'GET') {
        // One listing's contacts per request, counted per visitor, so a script cannot walk
        // the whole directory and leave with every member's phone number.
        throttleCheck($pdo, $config, 'directory-contact', 'listing:' . (int)$m[1]);
        $row = directoryVisibleRow($pdo, (int)$m[1]);
        if (!$row) respond(404, ['error' => 'This listing is no longer available.']);
        throttleFailure($pdo, $config, 'directory-contact', 'listing:' . (int)$m[1]);
        respond(200, ['phone' => $row['public_phone'], 'email' => $row['public_email'], 'website' => $row['website']]);
    }

    if (preg_match('#^directory/photo/(\d+)$#', $route, $m) && $method === 'GET') {
        // Only for a visible listing whose member chose to show it. Everything else is the
        // same 404, whether the member exists or not.
        $row = directoryVisibleRow($pdo, (int)$m[1]);
        if (!$row || (int)$row['show_photo'] !== 1 || empty($row['profile_image'])) respond(404, ['error' => 'Not found']);
        $file = rtrim((string)$config['upload_dir'], '/\\') . '/profile-images/' . basename((string)$row['profile_image']);
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $types = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
        if (!is_file($file) || !isset($types[$ext])) respond(404, ['error' => 'Not found']);
        header_remove('Content-Type');
        header('Content-Type: ' . $types[$ext]);
        header('Content-Length: ' . filesize($file));
        header("Content-Security-Policy: default-src 'none'; sandbox");
        header('X-Content-Type-Options: nosniff');
        // Short and revalidated: a member who turns the photo off should not stay pictured
        // in a cache for a day.
        header('Cache-Control: public, max-age=600, must-revalidate');
        readfile($file);
        exit;
    }

    // ------------------------------------------------------------- member
    $user = auth($config);
    if ($route === 'directory/me') {
        $member = memberRow($pdo, (int)$user['userId']);
        if (!$member) respond(404, ['error' => 'The directory is for ReSoK members. No membership is linked to this account.']);
        if ($method === 'POST') {
            $error = directorySave($pdo, $member, input());
            if ($error) respond(400, ['error' => $error]);
        }
        respond(200, directoryOwn($pdo, $member));
    }

    // ------------------------------------------------------------- admin
    requireAdmin($user);
    if ($route === 'directory/admin' && $method === 'GET') {
        $rows = $pdo->query("SELECT d.*, mp.title, mp.first_name, mp.surname, mp.profile_image, mp.membership_status, mp.membership_id, u.email AS account_email
                             FROM directory_listings d JOIN member_profiles mp ON mp.id = d.member_profile_id JOIN users u ON u.id = mp.user_id
                             WHERE d.listed = 1 OR d.hidden_by_admin = 1 ORDER BY d.updated_at DESC")->fetchAll();
        respond(200, ['listings' => array_map(static fn($r) => directoryPublicShape($r) + [
            'membershipId' => $r['membership_id'], 'accountEmail' => $r['account_email'], 'membershipActive' => $r['membership_status'] === 'active',
            'hidden' => (bool)$r['hidden_by_admin'], 'hiddenReason' => $r['hidden_reason'], 'updatedAt' => $r['updated_at'],
            'publicPhone' => $r['public_phone'], 'publicEmail' => $r['public_email'], 'website' => $r['website'],
        ], $rows)]);
    }
    if (preg_match('#^directory/admin/(\d+)/hide$#', $route, $m) && $method === 'POST') {
        $data = input();
        $hide = !empty($data['hidden']);
        $reason = directoryText($data['reason'] ?? '', 300);
        if ($hide && $reason === '') respond(400, ['error' => 'Give the member a reason for hiding the listing.']);
        $pdo->prepare('UPDATE directory_listings SET hidden_by_admin = ?, hidden_reason = ?, hidden_by = ? WHERE member_profile_id = ?')
            ->execute([$hide ? 1 : 0, $hide ? $reason : null, (int)$user['userId'], (int)$m[1]]);
        logAdminAction($pdo, (int)$user['userId'], $hide ? 'directory_hide' : 'directory_unhide', (int)$m[1], $hide ? $reason : null);
        respond(200, ['ok' => true]);
    }

    respond(404, ['error' => 'Route not found']);
}
