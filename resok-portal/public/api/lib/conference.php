<?php
declare(strict_types=1);

/**
 * Conference registration, badges and check-in (KISLHC platform blueprint, 16 Oct 2026).
 *
 * Built to the schematics: a conference offers registration categories, each priced by date
 * window (early bird, standard, onsite) and optionally at a member rate; delegates add paid
 * extras such as workshops; a registration waits for payment, is confirmed when payment is
 * confirmed (or straight away for a complimentary place), and only then gets a badge. The
 * badge carries a random code, never a membership number, so it cannot be forged by printing
 * someone else's number; one badge per registration is active at a time, and a reprint or a
 * transfer cancels the old one. Door scans are recorded per day and become the attendance
 * list that CPD tokens and certificates are drawn from.
 *
 * The conference itself is the abstract system's event (abs_events), so abstracts and
 * registration share one record, one reference prefix and one email queue.
 *
 * Staff roles live in their own table rather than in abs_roles: extending that table's ENUM
 * needs an ALTER TABLE, which this host has refused before.
 */

const CONF_PROOF_MAX_BYTES = 5242880;
const CONF_ROLES = ['finance', 'registration', 'scanner'];
// Unambiguous characters only: a code read aloud at a desk must not confuse 0/O or 1/I/L.
const CONF_CODE_ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';
const CONF_CODE_PREFIX = 'RSK-';

function confSchema(): array
{
    $tail = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    return [
        "CREATE TABLE IF NOT EXISTS conf_settings (
            event_id INT UNSIGNED NOT NULL,
            open TINYINT(1) NOT NULL DEFAULT 0,
            opens_at DATETIME NULL,
            closes_at DATETIME NULL,
            notes TEXT NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (event_id)
        )" . $tail,
        // member_rate: only members whose membership is in good standing may choose it.
        // admin_only: never offered to delegates (faculty, organisers, sponsor staff); staff
        // place people in it. needs_proof: e.g. students upload proof of status.
        "CREATE TABLE IF NOT EXISTS conf_categories (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            event_id INT UNSIGNED NOT NULL,
            name VARCHAR(120) NOT NULL,
            description VARCHAR(400) NULL,
            currency CHAR(3) NOT NULL DEFAULT 'KES',
            member_rate TINYINT(1) NOT NULL DEFAULT 0,
            admin_only TINYINT(1) NOT NULL DEFAULT 0,
            needs_proof TINYINT(1) NOT NULL DEFAULT 0,
            capacity INT UNSIGNED NULL,
            sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            active TINYINT(1) NOT NULL DEFAULT 1,
            PRIMARY KEY (id),
            KEY conf_categories_event (event_id, sort_order)
        )" . $tail,
        // A window with no start applies from the beginning; with no end, until the end.
        "CREATE TABLE IF NOT EXISTS conf_fee_windows (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            category_id INT UNSIGNED NOT NULL,
            label VARCHAR(60) NOT NULL,
            price DECIMAL(10,2) NOT NULL DEFAULT 0,
            starts_on DATE NULL,
            ends_on DATE NULL,
            PRIMARY KEY (id),
            KEY conf_fee_windows_category (category_id)
        )" . $tail,
        "CREATE TABLE IF NOT EXISTS conf_addons (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            event_id INT UNSIGNED NOT NULL,
            name VARCHAR(160) NOT NULL,
            description VARCHAR(400) NULL,
            price DECIMAL(10,2) NOT NULL DEFAULT 0,
            currency CHAR(3) NOT NULL DEFAULT 'KES',
            capacity INT UNSIGNED NULL,
            sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            active TINYINT(1) NOT NULL DEFAULT 1,
            PRIMARY KEY (id),
            KEY conf_addons_event (event_id, sort_order)
        )" . $tail,
        "CREATE TABLE IF NOT EXISTS conf_registrations (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            event_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            ref VARCHAR(30) NOT NULL,
            category_id INT UNSIGNED NOT NULL,
            status ENUM('awaiting_payment','confirmed','cancelled') NOT NULL DEFAULT 'awaiting_payment',
            fee_label VARCHAR(60) NULL,
            amount_due DECIMAL(10,2) NOT NULL DEFAULT 0,
            currency CHAR(3) NOT NULL DEFAULT 'KES',
            member_rate TINYINT(1) NOT NULL DEFAULT 0,
            badge_name VARCHAR(120) NOT NULL,
            institution VARCHAR(200) NULL,
            country VARCHAR(60) NULL,
            profession VARCHAR(120) NULL,
            phone VARCHAR(30) NULL,
            dietary VARCHAR(200) NULL,
            accessibility VARCHAR(300) NULL,
            share_contact TINYINT(1) NOT NULL DEFAULT 0,
            transferred_from INT UNSIGNED NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            confirmed_at DATETIME NULL,
            confirmed_by INT UNSIGNED NULL,
            cancelled_at DATETIME NULL,
            cancel_reason VARCHAR(300) NULL,
            PRIMARY KEY (id),
            UNIQUE KEY conf_registrations_ref (ref),
            KEY conf_registrations_event (event_id, status),
            KEY conf_registrations_user (user_id)
        )" . $tail,
        "CREATE TABLE IF NOT EXISTS conf_registration_addons (
            registration_id INT UNSIGNED NOT NULL,
            addon_id INT UNSIGNED NOT NULL,
            price DECIMAL(10,2) NOT NULL DEFAULT 0,
            PRIMARY KEY (registration_id, addon_id),
            KEY conf_registration_addons_addon (addon_id)
        )" . $tail,
        // An M-Pesa code or bank reference can pay for one registration only, which is what
        // the unique key enforces: a code copied from someone else's SMS is refused.
        "CREATE TABLE IF NOT EXISTS conf_payments (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            registration_id INT UNSIGNED NOT NULL,
            method ENUM('mpesa','bank','card','onsite','waiver') NOT NULL,
            reference VARCHAR(80) NULL,
            amount DECIMAL(10,2) NOT NULL DEFAULT 0,
            currency CHAR(3) NOT NULL DEFAULT 'KES',
            proof_file VARCHAR(120) NULL,
            proof_mime VARCHAR(60) NULL,
            status ENUM('pending','confirmed','rejected') NOT NULL DEFAULT 'pending',
            note VARCHAR(300) NULL,
            submitted_by INT UNSIGNED NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            reviewed_by INT UNSIGNED NULL,
            reviewed_at DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY conf_payments_reference (method, reference),
            KEY conf_payments_registration (registration_id),
            KEY conf_payments_status (status)
        )" . $tail,
        "CREATE TABLE IF NOT EXISTS conf_badges (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            registration_id INT UNSIGNED NOT NULL,
            code VARCHAR(40) NOT NULL,
            status ENUM('active','cancelled') NOT NULL DEFAULT 'active',
            printed_at DATETIME NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            cancelled_at DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY conf_badges_code (code),
            KEY conf_badges_registration (registration_id, status)
        )" . $tail,
        // client_id is generated on the scanning phone, so a scan queued offline and uploaded
        // twice (a retry after a dropped connection) is stored once.
        "CREATE TABLE IF NOT EXISTS conf_scans (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            event_id INT UNSIGNED NOT NULL,
            client_id VARCHAR(40) NOT NULL,
            code VARCHAR(40) NOT NULL,
            badge_id INT UNSIGNED NULL,
            registration_id INT UNSIGNED NULL,
            scan_day DATE NOT NULL,
            gate VARCHAR(60) NULL,
            session_id INT UNSIGNED NULL,
            result ENUM('ok','duplicate','unknown','cancelled') NOT NULL,
            scanned_by INT UNSIGNED NULL,
            scanned_at DATETIME NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY conf_scans_client (client_id),
            KEY conf_scans_event_day (event_id, scan_day, result),
            KEY conf_scans_registration (registration_id, scan_day)
        )" . $tail,
        "CREATE TABLE IF NOT EXISTS conf_staff (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            event_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            role ENUM('finance','registration','scanner') NOT NULL,
            granted_by INT UNSIGNED NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY conf_staff_unique (event_id, user_id, role),
            KEY conf_staff_user (user_id)
        )" . $tail,
        // Which CPD event (cpd_events, where the KMPDC tokens live) each conference day's
        // attendance is sent to. One accredited day, one CPD event.
        "CREATE TABLE IF NOT EXISTS conf_cpd_links (
            event_id INT UNSIGNED NOT NULL,
            scan_day DATE NOT NULL,
            cpd_event_id INT UNSIGNED NOT NULL,
            pushed_count INT UNSIGNED NULL,
            pushed_at DATETIME NULL,
            pushed_by INT UNSIGNED NULL,
            PRIMARY KEY (event_id, scan_day)
        )" . $tail,
        "CREATE TABLE IF NOT EXISTS conf_audit (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            event_id INT UNSIGNED NOT NULL,
            registration_id INT UNSIGNED NULL,
            user_id INT UNSIGNED NULL,
            action VARCHAR(40) NOT NULL,
            detail TEXT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY conf_audit_registration (registration_id),
            KEY conf_audit_event (event_id, created_at)
        )" . $tail,
    ];
}

function confEnsureTables(PDO $pdo): bool
{
    static $ready = null;
    if ($ready !== null) return $ready;
    try {
        foreach (confSchema() as $sql) $pdo->exec($sql);
        return $ready = true;
    } catch (Throwable $e) {
        error_log('Conference tables unavailable: ' . $e->getMessage());
        return $ready = false;
    }
}

function confAudit(PDO $pdo, int $eventId, ?int $registrationId, ?int $userId, string $action, ?array $detail = null): void
{
    try {
        $pdo->prepare('INSERT INTO conf_audit (event_id, registration_id, user_id, action, detail) VALUES (?, ?, ?, ?, ?)')
            ->execute([$eventId, $registrationId, $userId, $action, $detail ? json_encode($detail) : null]);
    } catch (Throwable $e) {
        error_log('conf audit failed: ' . $e->getMessage());
    }
}

/** Today's date where the conference is, which is what fee windows and scan days mean. */
function confToday(array $event): DateTimeImmutable
{
    $zone = function_exists('absZone') ? absZone($event) : new DateTimeZone('Africa/Nairobi');
    return new DateTimeImmutable('today', $zone);
}

// ---------------------------------------------------------------------------------------
// Staff roles
// ---------------------------------------------------------------------------------------

/**
 * What this user may do at this conference. Super administrators and the conference's own
 * administrators (from the abstract system) hold every role.
 *
 * @return string[] some of: admin, finance, registration, scanner
 */
function confRoles(PDO $pdo, array $user, array $config, int $eventId): array
{
    if (function_exists('absIsEventAdmin') && absIsEventAdmin($pdo, $user, $config, $eventId)) {
        return array_merge(['admin'], CONF_ROLES);
    }
    $stmt = $pdo->prepare('SELECT role FROM conf_staff WHERE event_id = ? AND user_id = ?');
    $stmt->execute([$eventId, (int)($user['userId'] ?? 0)]);
    $roles = $stmt->fetchAll(PDO::FETCH_COLUMN);
    // Registration desk staff take payments and fix records; they also need to scan.
    if (in_array('registration', $roles, true)) $roles[] = 'scanner';
    return array_values(array_unique($roles));
}

function confHasRole(PDO $pdo, array $user, array $config, int $eventId, string $role): bool
{
    return in_array($role, confRoles($pdo, $user, $config, $eventId), true);
}

function confStaffList(PDO $pdo, int $eventId): array
{
    $stmt = $pdo->prepare('SELECT s.id, s.role, s.created_at, u.email, u.id AS user_id
                           FROM conf_staff s JOIN users u ON u.id = s.user_id
                           WHERE s.event_id = ? ORDER BY u.email, s.role');
    $stmt->execute([$eventId]);
    return array_map(fn($r) => ['id' => (int)$r['id'], 'role' => $r['role'], 'email' => $r['email'],
                                'userId' => (int)$r['user_id'], 'since' => $r['created_at']], $stmt->fetchAll());
}

/** @return ?string an error, or null */
function confStaffGrant(PDO $pdo, int $eventId, string $email, string $role, int $grantedBy): ?string
{
    if (!in_array($role, CONF_ROLES, true)) return 'Unknown role.';
    $stmt = $pdo->prepare('SELECT id FROM users WHERE LOWER(email) = ? LIMIT 1');
    $stmt->execute([strtolower(trim($email))]);
    $userId = (int)($stmt->fetchColumn() ?: 0);
    if (!$userId) return 'No portal account uses that email. Ask them to create one first, then add them.';
    $pdo->prepare('INSERT IGNORE INTO conf_staff (event_id, user_id, role, granted_by) VALUES (?, ?, ?, ?)')
        ->execute([$eventId, $userId, $role, $grantedBy]);
    confAudit($pdo, $eventId, null, $grantedBy, 'staff_granted', ['email' => $email, 'role' => $role]);
    return null;
}

function confStaffRevoke(PDO $pdo, int $eventId, int $staffId, int $by): void
{
    $pdo->prepare('DELETE FROM conf_staff WHERE id = ? AND event_id = ?')->execute([$staffId, $eventId]);
    confAudit($pdo, $eventId, null, $by, 'staff_revoked', ['staffId' => $staffId]);
}

// ---------------------------------------------------------------------------------------
// Settings, categories, fee windows, add-ons
// ---------------------------------------------------------------------------------------

function confSettings(PDO $pdo, int $eventId): array
{
    $stmt = $pdo->prepare('SELECT * FROM conf_settings WHERE event_id = ?');
    $stmt->execute([$eventId]);
    $row = $stmt->fetch() ?: [];
    return [
        'open' => !empty($row['open']),
        'opensAt' => $row['opens_at'] ?? null,
        'closesAt' => $row['closes_at'] ?? null,
        'notes' => $row['notes'] ?? '',
    ];
}

function confSaveSettings(PDO $pdo, int $eventId, array $data, int $by): ?string
{
    $parse = static function ($v): ?string {
        $v = trim((string)$v);
        if ($v === '') return null;
        $v = str_replace('T', ' ', $v);
        foreach (['Y-m-d H:i:s', 'Y-m-d H:i'] as $f) {
            $d = DateTimeImmutable::createFromFormat('!' . $f, $v);
            if ($d && $d->format($f) === $v) return $d->format('Y-m-d H:i:s');
        }
        return 'bad';
    };
    // Only the fields sent are changed: a call that switches registration on must not wipe
    // the payment instructions or the dates.
    $current = confSettings($pdo, $eventId);
    $opens = array_key_exists('opensAt', $data) ? $parse($data['opensAt']) : $current['opensAt'];
    $closes = array_key_exists('closesAt', $data) ? $parse($data['closesAt']) : $current['closesAt'];
    if ($opens === 'bad' || $closes === 'bad') return 'Dates and times must look like 2027-02-01 09:00.';
    if ($opens && $closes && $closes <= $opens) return 'Registration must close after it opens.';
    $pdo->prepare('INSERT INTO conf_settings (event_id, open, opens_at, closes_at, notes) VALUES (?, ?, ?, ?, ?)
                   ON DUPLICATE KEY UPDATE open = VALUES(open), opens_at = VALUES(opens_at), closes_at = VALUES(closes_at), notes = VALUES(notes)')
        ->execute([$eventId, array_key_exists('open', $data) ? (!empty($data['open']) ? 1 : 0) : ($current['open'] ? 1 : 0), $opens, $closes,
                   array_key_exists('notes', $data) ? mb_substr(trim((string)$data['notes']), 0, 4000) : $current['notes']]);
    confAudit($pdo, $eventId, null, $by, 'settings_saved', ['open' => array_key_exists('open', $data) ? !empty($data['open']) : $current['open']]);
    return null;
}

/** 'not_open', 'open' or 'closed': the switch first, then the window if one is set. */
function confRegistrationState(array $event, array $settings): string
{
    if (!$settings['open']) return 'not_open';
    $zone = function_exists('absZone') ? absZone($event) : new DateTimeZone('Africa/Nairobi');
    $now = new DateTimeImmutable('now', $zone);
    if ($settings['opensAt'] && $now < new DateTimeImmutable($settings['opensAt'], $zone)) return 'not_open';
    if ($settings['closesAt'] && $now > new DateTimeImmutable($settings['closesAt'], $zone)) return 'closed';
    return 'open';
}

function confCategories(PDO $pdo, int $eventId, bool $includeHidden = false): array
{
    $stmt = $pdo->prepare('SELECT c.*, (SELECT COUNT(*) FROM conf_registrations r WHERE r.category_id = c.id AND r.status <> "cancelled") AS taken
                           FROM conf_categories c WHERE c.event_id = ?' . ($includeHidden ? '' : ' AND c.active = 1 AND c.admin_only = 0') . '
                           ORDER BY c.sort_order, c.id');
    $stmt->execute([$eventId]);
    $rows = $stmt->fetchAll();
    if (!$rows) return [];
    $ids = array_map(fn($r) => (int)$r['id'], $rows);
    $w = $pdo->query('SELECT * FROM conf_fee_windows WHERE category_id IN (' . implode(',', $ids) . ') ORDER BY COALESCE(starts_on, "1000-01-01"), id')->fetchAll();
    $windows = [];
    foreach ($w as $row) {
        $windows[(int)$row['category_id']][] = ['id' => (int)$row['id'], 'label' => $row['label'], 'price' => (float)$row['price'],
                                                'startsOn' => $row['starts_on'], 'endsOn' => $row['ends_on']];
    }
    return array_map(fn($r) => [
        'id' => (int)$r['id'], 'name' => $r['name'], 'description' => $r['description'], 'currency' => $r['currency'],
        'memberRate' => (bool)$r['member_rate'], 'adminOnly' => (bool)$r['admin_only'], 'needsProof' => (bool)$r['needs_proof'],
        'capacity' => $r['capacity'] !== null ? (int)$r['capacity'] : null, 'taken' => (int)$r['taken'],
        'sortOrder' => (int)$r['sort_order'], 'active' => (bool)$r['active'],
        'windows' => $windows[(int)$r['id']] ?? [],
    ], $rows);
}

function confCategoryRow(PDO $pdo, int $eventId, int $categoryId): ?array
{
    foreach (confCategories($pdo, $eventId, true) as $c) {
        if ($c['id'] === $categoryId) return $c;
    }
    return null;
}

/**
 * The fee that applies to a category on a day: the window covering that day, or null when
 * none does (the category is not on sale today).
 *
 * @return ?array{price: float, label: string}
 */
function confPriceOn(array $category, DateTimeImmutable $day): ?array
{
    $d = $day->format('Y-m-d');
    foreach ($category['windows'] as $w) {
        if ($w['startsOn'] && $d < $w['startsOn']) continue;
        if ($w['endsOn'] && $d > $w['endsOn']) continue;
        return ['price' => (float)$w['price'], 'label' => $w['label']];
    }
    return null;
}

/** @return array{0: ?int, 1: ?string} [category id, error] */
function confSaveCategory(PDO $pdo, int $eventId, array $data, int $by): array
{
    $name = trim((string)($data['name'] ?? ''));
    if ($name === '' || mb_strlen($name) > 120) return [null, 'Give the category a name (up to 120 characters).'];
    $currency = strtoupper(trim((string)($data['currency'] ?? 'KES')));
    if (!preg_match('/^[A-Z]{3}$/', $currency)) return [null, 'The currency must be a three-letter code such as KES or USD.'];
    $capacity = isset($data['capacity']) && $data['capacity'] !== '' && $data['capacity'] !== null ? max(0, (int)$data['capacity']) : null;

    $windows = [];
    foreach ((array)($data['windows'] ?? []) as $w) {
        $label = trim((string)($w['label'] ?? ''));
        if ($label === '') continue;
        $price = (float)($w['price'] ?? 0);
        if ($price < 0) return [null, 'A price cannot be negative.'];
        $starts = trim((string)($w['startsOn'] ?? '')) ?: null;
        $ends = trim((string)($w['endsOn'] ?? '')) ?: null;
        foreach ([$starts, $ends] as $d) {
            if ($d !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) return [null, 'Fee dates must look like 2027-06-30.'];
        }
        if ($starts && $ends && $ends < $starts) return [null, "The \"{$label}\" fee ends before it starts."];
        $windows[] = [mb_substr($label, 0, 60), $price, $starts, $ends];
    }
    if (!$windows) return [null, 'Add at least one fee (use 0 for a free or complimentary category).'];

    $values = [$name, mb_substr(trim((string)($data['description'] ?? '')), 0, 400) ?: null, $currency,
               !empty($data['memberRate']) ? 1 : 0, !empty($data['adminOnly']) ? 1 : 0, !empty($data['needsProof']) ? 1 : 0,
               $capacity, max(0, (int)($data['sortOrder'] ?? 0)), array_key_exists('active', $data) ? (!empty($data['active']) ? 1 : 0) : 1];
    $id = (int)($data['id'] ?? 0);
    $pdo->beginTransaction();
    try {
        if ($id) {
            $stmt = $pdo->prepare('UPDATE conf_categories SET name = ?, description = ?, currency = ?, member_rate = ?, admin_only = ?, needs_proof = ?,
                                   capacity = ?, sort_order = ?, active = ? WHERE id = ? AND event_id = ?');
            $stmt->execute(array_merge($values, [$id, $eventId]));
            $check = $pdo->prepare('SELECT id FROM conf_categories WHERE id = ? AND event_id = ?');
            $check->execute([$id, $eventId]);
            if (!$check->fetch()) { $pdo->rollBack(); return [null, 'No such category.']; }
            $pdo->prepare('DELETE FROM conf_fee_windows WHERE category_id = ?')->execute([$id]);
        } else {
            $pdo->prepare('INSERT INTO conf_categories (name, description, currency, member_rate, admin_only, needs_proof, capacity, sort_order, active, event_id)
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute(array_merge($values, [$eventId]));
            $id = (int)$pdo->lastInsertId();
        }
        $insert = $pdo->prepare('INSERT INTO conf_fee_windows (category_id, label, price, starts_on, ends_on) VALUES (?, ?, ?, ?, ?)');
        foreach ($windows as $w) $insert->execute(array_merge([$id], $w));
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    confAudit($pdo, $eventId, null, $by, 'category_saved', ['category' => $id, 'name' => $name]);
    return [$id, null];
}

function confAddons(PDO $pdo, int $eventId, bool $includeHidden = false): array
{
    $stmt = $pdo->prepare('SELECT a.*, (SELECT COUNT(*) FROM conf_registration_addons ra JOIN conf_registrations r ON r.id = ra.registration_id
                                        WHERE ra.addon_id = a.id AND r.status <> "cancelled") AS taken
                           FROM conf_addons a WHERE a.event_id = ?' . ($includeHidden ? '' : ' AND a.active = 1') . ' ORDER BY a.sort_order, a.id');
    $stmt->execute([$eventId]);
    return array_map(fn($r) => [
        'id' => (int)$r['id'], 'name' => $r['name'], 'description' => $r['description'], 'price' => (float)$r['price'],
        'currency' => $r['currency'], 'capacity' => $r['capacity'] !== null ? (int)$r['capacity'] : null,
        'taken' => (int)$r['taken'], 'sortOrder' => (int)$r['sort_order'], 'active' => (bool)$r['active'],
    ], $stmt->fetchAll());
}

/** @return array{0: ?int, 1: ?string} */
function confSaveAddon(PDO $pdo, int $eventId, array $data, int $by): array
{
    $name = trim((string)($data['name'] ?? ''));
    if ($name === '' || mb_strlen($name) > 160) return [null, 'Give the add-on a name (up to 160 characters).'];
    $price = (float)($data['price'] ?? 0);
    if ($price < 0) return [null, 'A price cannot be negative.'];
    $currency = strtoupper(trim((string)($data['currency'] ?? 'KES')));
    if (!preg_match('/^[A-Z]{3}$/', $currency)) return [null, 'The currency must be a three-letter code such as KES or USD.'];
    $capacity = isset($data['capacity']) && $data['capacity'] !== '' && $data['capacity'] !== null ? max(0, (int)$data['capacity']) : null;
    $values = [$name, mb_substr(trim((string)($data['description'] ?? '')), 0, 400) ?: null, $price, $currency, $capacity,
               max(0, (int)($data['sortOrder'] ?? 0)), array_key_exists('active', $data) ? (!empty($data['active']) ? 1 : 0) : 1];
    $id = (int)($data['id'] ?? 0);
    if ($id) {
        $pdo->prepare('UPDATE conf_addons SET name = ?, description = ?, price = ?, currency = ?, capacity = ?, sort_order = ?, active = ? WHERE id = ? AND event_id = ?')
            ->execute(array_merge($values, [$id, $eventId]));
    } else {
        $pdo->prepare('INSERT INTO conf_addons (name, description, price, currency, capacity, sort_order, active, event_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute(array_merge($values, [$eventId]));
        $id = (int)$pdo->lastInsertId();
    }
    confAudit($pdo, $eventId, null, $by, 'addon_saved', ['addon' => $id, 'name' => $name]);
    return [$id, null];
}

// ---------------------------------------------------------------------------------------
// Registrations
// ---------------------------------------------------------------------------------------

function confRegistrationRow(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM conf_registrations WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

/** The user's live registration for the conference (cancelled ones do not count). */
function confRegistrationFor(PDO $pdo, int $eventId, int $userId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM conf_registrations WHERE event_id = ? AND user_id = ? AND status <> "cancelled" ORDER BY id DESC LIMIT 1');
    $stmt->execute([$eventId, $userId]);
    return $stmt->fetch() ?: null;
}

function confNextRef(PDO $pdo, array $event): string
{
    $prefix = (string)($event['ref_prefix'] ?? 'CONF');
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM conf_registrations WHERE event_id = ?');
    $stmt->execute([(int)$event['id']]);
    $n = (int)$stmt->fetchColumn() + 1;
    for ($i = 0; $i < 50; $i++, $n++) {
        $ref = sprintf('%s-R%04d', $prefix, $n);
        $check = $pdo->prepare('SELECT 1 FROM conf_registrations WHERE ref = ?');
        $check->execute([$ref]);
        if (!$check->fetch()) return $ref;
    }
    return $prefix . '-R' . strtoupper(bin2hex(random_bytes(3)));
}

/** Money confirmed against a registration, and what is still owed. */
function confBalance(PDO $pdo, array $reg): array
{
    $stmt = $pdo->prepare('SELECT status, SUM(amount) total FROM conf_payments WHERE registration_id = ? GROUP BY status');
    $stmt->execute([(int)$reg['id']]);
    $sums = ['pending' => 0.0, 'confirmed' => 0.0, 'rejected' => 0.0];
    foreach ($stmt->fetchAll() as $r) $sums[$r['status']] = (float)$r['total'];
    return ['paid' => $sums['confirmed'], 'pending' => $sums['pending'], 'due' => max(0.0, (float)$reg['amount_due'] - $sums['confirmed'])];
}

function confActiveBadge(PDO $pdo, int $registrationId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM conf_badges WHERE registration_id = ? AND status = "active" ORDER BY id DESC LIMIT 1');
    $stmt->execute([$registrationId]);
    return $stmt->fetch() ?: null;
}

/**
 * A registration as the delegate or staff see it. The badge code goes only to the owner and
 * to staff: it is the key to the door.
 */
function confShape(PDO $pdo, array $reg, bool $forStaff = false): array
{
    $cat = confCategoryRow($pdo, (int)$reg['event_id'], (int)$reg['category_id']);
    $addons = $pdo->prepare('SELECT a.id, a.name, ra.price, a.currency FROM conf_registration_addons ra JOIN conf_addons a ON a.id = ra.addon_id WHERE ra.registration_id = ?');
    $addons->execute([(int)$reg['id']]);
    $payments = $pdo->prepare('SELECT id, method, reference, amount, currency, status, note, proof_file, created_at, reviewed_at FROM conf_payments WHERE registration_id = ? ORDER BY id');
    $payments->execute([(int)$reg['id']]);
    $badge = confActiveBadge($pdo, (int)$reg['id']);
    $days = $pdo->prepare('SELECT DISTINCT scan_day FROM conf_scans WHERE registration_id = ? AND result = "ok" ORDER BY scan_day');
    $days->execute([(int)$reg['id']]);
    $shape = [
        'id' => (int)$reg['id'], 'ref' => $reg['ref'], 'status' => $reg['status'],
        'category' => $cat ? ['id' => $cat['id'], 'name' => $cat['name'], 'needsProof' => $cat['needsProof']] : null,
        'feeLabel' => $reg['fee_label'], 'amountDue' => (float)$reg['amount_due'], 'currency' => $reg['currency'],
        'memberRate' => (bool)$reg['member_rate'], 'badgeName' => $reg['badge_name'], 'institution' => $reg['institution'],
        'country' => $reg['country'], 'profession' => $reg['profession'], 'phone' => $reg['phone'], 'dietary' => $reg['dietary'],
        'accessibility' => $reg['accessibility'], 'shareContact' => (bool)$reg['share_contact'],
        'addons' => array_map(fn($a) => ['id' => (int)$a['id'], 'name' => $a['name'], 'price' => (float)$a['price'], 'currency' => $a['currency']], $addons->fetchAll()),
        'payments' => array_map(fn($p) => ['id' => (int)$p['id'], 'method' => $p['method'], 'reference' => $p['reference'],
            'amount' => (float)$p['amount'], 'currency' => $p['currency'], 'status' => $p['status'], 'note' => $p['note'],
            'hasProof' => $p['proof_file'] !== null, 'submittedAt' => $p['created_at'], 'reviewedAt' => $p['reviewed_at']], $payments->fetchAll()),
        'balance' => confBalance($pdo, $reg),
        'badge' => $badge ? ['code' => $badge['code'], 'printed' => $badge['printed_at'] !== null] : null,
        'group' => ($g = function_exists('confGroupOf') ? confGroupOf($pdo, (int)$reg['id']) : null)
            ? ['id' => (int)$g['id'], 'name' => $g['name'], 'invoiceRef' => $g['invoice_ref'], 'status' => $g['status']] : null,
        'checkedInDays' => $days->fetchAll(PDO::FETCH_COLUMN),
        'createdAt' => $reg['created_at'], 'confirmedAt' => $reg['confirmed_at'],
    ];
    if ($forStaff) {
        $u = $pdo->prepare('SELECT email FROM users WHERE id = ?');
        $u->execute([(int)$reg['user_id']]);
        $shape['email'] = (string)$u->fetchColumn();
        $shape['userId'] = (int)$reg['user_id'];
        $shape['cancelReason'] = $reg['cancel_reason'];
        $log = $pdo->prepare('SELECT a.action, a.detail, a.created_at, u.email FROM conf_audit a LEFT JOIN users u ON u.id = a.user_id
                              WHERE a.registration_id = ? ORDER BY a.id DESC LIMIT 30');
        $log->execute([(int)$reg['id']]);
        $shape['history'] = array_map(fn($h) => ['action' => $h['action'], 'detail' => $h['detail'] ? json_decode($h['detail'], true) : null,
                                                 'at' => $h['created_at'], 'by' => $h['email']], $log->fetchAll());
    }
    return $shape;
}

/**
 * A registration still waiting for payment, with nothing paid or pending, is re-priced when
 * the window it was priced in has ended (schematic: Expired, then re-opened at the current
 * rate). Without this, early-bird prices would hold for anyone who registered early and
 * paid late.
 */
function confRepriceIfLapsed(PDO $pdo, array $event, array $reg): array
{
    if ($reg['status'] !== 'awaiting_payment') return $reg;
    $bal = confBalance($pdo, $reg);
    if ($bal['paid'] > 0 || $bal['pending'] > 0) return $reg;
    $cat = confCategoryRow($pdo, (int)$event['id'], (int)$reg['category_id']);
    if (!$cat) return $reg;
    $now = confPriceOn($cat, confToday($event));
    if (!$now || ($now['label'] === $reg['fee_label'])) return $reg;
    $addons = $pdo->prepare('SELECT COALESCE(SUM(price), 0) FROM conf_registration_addons WHERE registration_id = ?');
    $addons->execute([(int)$reg['id']]);
    $amount = $now['price'] + (float)$addons->fetchColumn();
    $pdo->prepare('UPDATE conf_registrations SET fee_label = ?, amount_due = ? WHERE id = ?')->execute([$now['label'], $amount, (int)$reg['id']]);
    confAudit($pdo, (int)$event['id'], (int)$reg['id'], null, 'repriced',
              ['from' => [$reg['fee_label'], (float)$reg['amount_due']], 'to' => [$now['label'], $amount]]);
    return confRegistrationRow($pdo, (int)$reg['id']);
}

/**
 * Registers the signed-in user. The price is decided here, from the category and today's
 * window, never taken from the browser.
 *
 * @return array{0: ?array, 1: ?string}
 */
function confRegister(PDO $pdo, array $config, array $event, array $user, array $data): array
{
    $eventId = (int)$event['id'];
    $userId = (int)$user['userId'];
    if (confRegistrationState($event, confSettings($pdo, $eventId)) !== 'open') return [null, 'Registration is not open.'];
    if (confRegistrationFor($pdo, $eventId, $userId)) return [null, 'You are already registered for this conference.'];

    $cat = confCategoryRow($pdo, $eventId, (int)($data['categoryId'] ?? 0));
    if (!$cat || !$cat['active'] || $cat['adminOnly']) return [null, 'Choose one of the registration categories.'];
    if ($cat['capacity'] !== null && $cat['taken'] >= $cat['capacity']) return [null, 'That category is full.'];
    if ($cat['memberRate']) {
        $standing = function_exists('membershipStandingForUser') ? membershipStandingForUser($pdo, $userId) : ['benefits' => false];
        if (empty($standing['benefits'])) {
            return [null, 'The member rate is for ReSoK members whose membership is active. Renew your membership first, or choose another category.'];
        }
    }
    $price = confPriceOn($cat, confToday($event));
    if (!$price) return [null, 'That category is not on sale today.'];

    $badgeName = trim((string)preg_replace('/\s+/u', ' ', (string)($data['badgeName'] ?? '')));
    if ($badgeName === '' || mb_strlen($badgeName) > 60) return [null, 'Enter the name to print on your badge (up to 60 characters).'];
    $institution = trim((string)($data['institution'] ?? ''));
    if ($institution === '') return [null, 'Enter your institution or organisation.'];
    $country = trim((string)($data['country'] ?? ''));
    if ($country === '') return [null, 'Enter your country.'];
    $phone = trim((string)($data['phone'] ?? ''));
    if ($phone !== '' && !preg_match('/^\+?[0-9 ()-]{7,20}$/', $phone)) return [null, 'Enter a valid phone number, for example +254 712 345 678.'];

    $addonIds = array_values(array_unique(array_map('intval', (array)($data['addons'] ?? []))));
    $chosen = [];
    $addonTotal = 0.0;
    foreach (confAddons($pdo, $eventId) as $a) {
        if (!in_array($a['id'], $addonIds, true)) continue;
        if ($a['currency'] !== $cat['currency']) return [null, "\"{$a['name']}\" is priced in {$a['currency']}, which does not match your category."];
        if ($a['capacity'] !== null && $a['taken'] >= $a['capacity']) return [null, "\"{$a['name']}\" is full."];
        $chosen[] = $a;
        $addonTotal += $a['price'];
    }
    if (count($chosen) !== count($addonIds)) return [null, 'One of the add-ons you chose is not available.'];

    // Paid by an institution: the group code is checked before anything is saved.
    $group = null;
    $groupCode = trim((string)($data['groupCode'] ?? ''));
    if ($groupCode !== '') {
        $group = function_exists('confGroupByCode') ? confGroupByCode($pdo, $eventId, $groupCode) : null;
        if (!$group) return [null, 'That group code is not recognised, or the group has already been invoiced and paid. Check it with your institution.'];
    }

    $amount = $price['price'] + $addonTotal;
    $pdo->beginTransaction();
    try {
        $ref = confNextRef($pdo, $event);
        $pdo->prepare('INSERT INTO conf_registrations (event_id, user_id, ref, category_id, status, fee_label, amount_due, currency, member_rate,
                       badge_name, institution, country, profession, phone, dietary, accessibility, share_contact)
                       VALUES (?, ?, ?, ?, "awaiting_payment", ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$eventId, $userId, $ref, $cat['id'], $price['label'], $amount, $cat['currency'], $cat['memberRate'] ? 1 : 0,
                       $badgeName, mb_substr($institution, 0, 200), mb_substr($country, 0, 60),
                       mb_substr(trim((string)($data['profession'] ?? '')), 0, 120) ?: null, $phone ?: null,
                       mb_substr(trim((string)($data['dietary'] ?? '')), 0, 200) ?: null,
                       mb_substr(trim((string)($data['accessibility'] ?? '')), 0, 300) ?: null,
                       !empty($data['shareContact']) ? 1 : 0]);
        $regId = (int)$pdo->lastInsertId();
        $ins = $pdo->prepare('INSERT INTO conf_registration_addons (registration_id, addon_id, price) VALUES (?, ?, ?)');
        foreach ($chosen as $a) $ins->execute([$regId, $a['id'], $a['price']]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    confAudit($pdo, $eventId, $regId, $userId, 'registered', ['category' => $cat['name'], 'fee' => $price['label'], 'amount' => $amount]);
    $reg = confRegistrationRow($pdo, $regId);
    if ($group && $amount > 0) {
        $joinError = confGroupAdd($pdo, $event, $group, $reg, $userId);
        if ($joinError) error_log("Registration {$reg['ref']} could not join group {$group['id']}: {$joinError}");
    }
    // A free category needs no payment step (schematic: complimentary goes straight to Confirmed).
    if ($amount <= 0 && !$cat['needsProof']) {
        confConfirm($pdo, $config, $event, $reg, null, 'free_category');
        $reg = confRegistrationRow($pdo, $regId);
    }
    return [$reg, null];
}

// ---------------------------------------------------------------------------------------
// Payments
// ---------------------------------------------------------------------------------------

function confProofDir(array $config): string
{
    return rtrim((string)$config['upload_dir'], '/\\') . '/conference-proofs';
}

/**
 * Records a payment the delegate says they made. It stays pending until finance confirms it
 * against the M-Pesa statement or the bank account (schematic: Payment, the manual route).
 *
 * @return ?string an error, or null
 */
function confSubmitPayment(PDO $pdo, array $config, array $event, array $reg, int $userId, array $data, ?array $file): ?string
{
    if ($reg['status'] !== 'awaiting_payment') return 'This registration is not waiting for payment.';
    $bal = confBalance($pdo, $reg);
    if ($bal['due'] <= 0) return 'Nothing is owed on this registration.';
    if ($bal['pending'] > 0) return 'A payment is already waiting to be confirmed. You will hear from us once finance has checked it.';
    if (function_exists('confGroupOf') && ($g = confGroupOf($pdo, (int)$reg['id']))) {
        return 'Your place is being paid for by ' . $g['name'] . ' on invoice ' . $g['invoice_ref'] . '. You do not need to pay yourself.';
    }

    $method = (string)($data['method'] ?? '');
    $reference = strtoupper(trim((string)($data['reference'] ?? '')));
    if ($method === 'mpesa') {
        if (!preg_match('/^[A-Z0-9]{10}$/', $reference)) return 'Enter the 10-character M-Pesa transaction code from your SMS, for example SGH4K2L9PQ.';
    } elseif ($method === 'bank') {
        if ($reference === '' || mb_strlen($reference) > 80) return 'Enter the bank reference or transaction number.';
    } else {
        return 'Choose how you paid.';
    }

    $proofName = null;
    $proofMime = null;
    $cat = confCategoryRow($pdo, (int)$event['id'], (int)$reg['category_id']);
    $proofRequired = $method === 'bank' || ($cat && $cat['needsProof']);
    if ($file && (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $err = (int)$file['error'];
        if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) return 'That file is larger than 5 MB.';
        if ($err !== UPLOAD_ERR_OK || !is_uploaded_file((string)$file['tmp_name'])) return 'The upload did not arrive. Please try again.';
        if ((int)$file['size'] > min(CONF_PROOF_MAX_BYTES, (int)($config['max_file_size'] ?? CONF_PROOF_MAX_BYTES))) return 'That file is larger than 5 MB.';
        $head = (string)file_get_contents((string)$file['tmp_name'], false, null, 0, 8);
        if (strncmp($head, '%PDF', 4) === 0) { $proofMime = 'application/pdf'; $ext = 'pdf'; }
        elseif (strncmp($head, "\xFF\xD8\xFF", 3) === 0) { $proofMime = 'image/jpeg'; $ext = 'jpg'; }
        elseif (strncmp($head, "\x89PNG", 4) === 0) { $proofMime = 'image/png'; $ext = 'png'; }
        else return 'Upload the proof as a PDF, JPG or PNG.';
        $dir = confProofDir($config);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) return 'Proofs cannot be stored on the server yet. Please tell the secretariat.';
        $proofName = 'proof-' . bin2hex(random_bytes(16)) . '.' . $ext;
        if (!move_uploaded_file((string)$file['tmp_name'], $dir . '/' . $proofName)) return 'The proof could not be saved. Please try again.';
    } elseif ($proofRequired) {
        return $method === 'bank' ? 'Attach the bank slip or transfer confirmation.' : 'This category needs proof of eligibility (for example a student ID). Attach it with your payment.';
    }

    try {
        $pdo->prepare('INSERT INTO conf_payments (registration_id, method, reference, amount, currency, proof_file, proof_mime, status, submitted_by)
                       VALUES (?, ?, ?, ?, ?, ?, ?, "pending", ?)')
            ->execute([(int)$reg['id'], $method, $reference, $bal['due'], $reg['currency'], $proofName, $proofMime, $userId]);
    } catch (PDOException $e) {
        if ((int)($e->errorInfo[1] ?? 0) === 1062) {
            if ($proofName) @unlink(confProofDir($config) . '/' . $proofName);
            return 'That transaction code has already been used for a registration. Check the code, or contact the secretariat.';
        }
        throw $e;
    }
    confAudit($pdo, (int)$event['id'], (int)$reg['id'], $userId, 'payment_submitted', ['method' => $method, 'reference' => $reference, 'amount' => $bal['due']]);
    return null;
}

/** @return ?string an error, or null */
function confReviewPayment(PDO $pdo, array $config, array $event, int $paymentId, int $staffId, string $decision, string $note): ?string
{
    $stmt = $pdo->prepare('SELECT p.*, r.event_id FROM conf_payments p JOIN conf_registrations r ON r.id = p.registration_id WHERE p.id = ?');
    $stmt->execute([$paymentId]);
    $p = $stmt->fetch();
    if (!$p || (int)$p['event_id'] !== (int)$event['id']) return 'No such payment.';
    if ($p['status'] !== 'pending') return 'This payment has already been reviewed.';
    if (!in_array($decision, ['confirm', 'reject'], true)) return 'Choose confirm or reject.';
    if ($decision === 'reject' && trim($note) === '') return 'Say why the payment is rejected. The delegate sees this.';

    $pdo->prepare('UPDATE conf_payments SET status = ?, note = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ?')
        ->execute([$decision === 'confirm' ? 'confirmed' : 'rejected', mb_substr(trim($note), 0, 300) ?: null, $staffId, $paymentId]);
    $reg = confRegistrationRow($pdo, (int)$p['registration_id']);
    confAudit($pdo, (int)$event['id'], (int)$reg['id'], $staffId, $decision === 'confirm' ? 'payment_confirmed' : 'payment_rejected',
              ['payment' => $paymentId, 'reference' => $p['reference'], 'note' => $note]);

    if ($decision === 'reject') {
        confEmail($pdo, $config, $event, $reg, 'payment_rejected', ['note' => trim($note)]);
        return null;
    }
    if (confBalance($pdo, $reg)['due'] <= 0) {
        confConfirm($pdo, $config, $event, $reg, $staffId, 'payment');
    }
    return null;
}

/**
 * Staff record money taken at the desk, or waive the fee (complimentary place, sponsor staff,
 * faculty). Both settle the balance and confirm the registration.
 */
function confStaffSettle(PDO $pdo, array $config, array $event, array $reg, int $staffId, string $method, string $reference, string $note): ?string
{
    if ($reg['status'] !== 'awaiting_payment') return 'This registration is not waiting for payment.';
    if (!in_array($method, ['onsite', 'waiver', 'bank', 'mpesa'], true)) return 'Choose how it was settled.';
    if ($method === 'waiver' && trim($note) === '') return 'Say why the fee is waived, for example "Faculty" or "Sponsor staff".';
    if ($method !== 'waiver' && trim($reference) === '') return 'Enter the receipt or transaction reference.';
    $due = confBalance($pdo, $reg)['due'];
    try {
        $pdo->prepare('INSERT INTO conf_payments (registration_id, method, reference, amount, currency, status, note, submitted_by, reviewed_by, reviewed_at)
                       VALUES (?, ?, ?, ?, ?, "confirmed", ?, ?, ?, NOW())')
            ->execute([(int)$reg['id'], $method, $method === 'waiver' ? 'WAIVER-' . $reg['ref'] : strtoupper(trim($reference)),
                       $method === 'waiver' ? 0 : $due, $reg['currency'], mb_substr(trim($note), 0, 300) ?: null, $staffId, $staffId]);
    } catch (PDOException $e) {
        if ((int)($e->errorInfo[1] ?? 0) === 1062) return 'That reference has already been used for another registration.';
        throw $e;
    }
    if ($method === 'waiver') {
        $pdo->prepare('UPDATE conf_registrations SET amount_due = 0 WHERE id = ?')->execute([(int)$reg['id']]);
    }
    confAudit($pdo, (int)$event['id'], (int)$reg['id'], $staffId, $method === 'waiver' ? 'fee_waived' : 'payment_taken',
              ['method' => $method, 'reference' => $reference, 'note' => $note, 'amount' => $method === 'waiver' ? 0 : $due]);
    confConfirm($pdo, $config, $event, confRegistrationRow($pdo, (int)$reg['id']), $staffId, $method);
    return null;
}

/** Confirmed: the badge is issued and the delegate is told. */
function confConfirm(PDO $pdo, array $config, array $event, array $reg, ?int $staffId, string $how): void
{
    $pdo->prepare('UPDATE conf_registrations SET status = "confirmed", confirmed_at = NOW(), confirmed_by = ? WHERE id = ? AND status = "awaiting_payment"')
        ->execute([$staffId, (int)$reg['id']]);
    $badge = confActiveBadge($pdo, (int)$reg['id']) ?? confIssueBadge($pdo, (int)$event['id'], (int)$reg['id'], $staffId, 'issued');
    confAudit($pdo, (int)$event['id'], (int)$reg['id'], $staffId, 'confirmed', ['how' => $how]);
    confEmail($pdo, $config, $event, confRegistrationRow($pdo, (int)$reg['id']), 'confirmed', ['badgeId' => $badge['id']]);
}

// ---------------------------------------------------------------------------------------
// Badges
// ---------------------------------------------------------------------------------------

function confNewCode(): string
{
    $out = '';
    $alphabet = CONF_CODE_ALPHABET;
    $n = strlen($alphabet);
    // 16 characters from 31: about 79 bits, far beyond guessing at a door.
    for ($i = 0; $i < 16; $i++) $out .= $alphabet[random_int(0, $n - 1)];
    return CONF_CODE_PREFIX . $out;
}

/** Issues a fresh badge, cancelling any active one (a reprint or a transfer). */
function confIssueBadge(PDO $pdo, int $eventId, int $registrationId, ?int $by, string $why): array
{
    $pdo->prepare('UPDATE conf_badges SET status = "cancelled", cancelled_at = NOW() WHERE registration_id = ? AND status = "active"')->execute([$registrationId]);
    for ($i = 0; $i < 5; $i++) {
        try {
            $code = confNewCode();
            $pdo->prepare('INSERT INTO conf_badges (registration_id, code) VALUES (?, ?)')->execute([$registrationId, $code]);
            $id = (int)$pdo->lastInsertId();
            confAudit($pdo, $eventId, $registrationId, $by, 'badge_' . $why, ['badge' => $id]);
            return ['id' => $id, 'code' => $code];
        } catch (PDOException $e) {
            if ((int)($e->errorInfo[1] ?? 0) !== 1062) throw $e;
        }
    }
    throw new RuntimeException('Could not create a unique badge code.');
}

/** Staff actions on a registration: reprint, cancel, transfer, change details. */
function confReprint(PDO $pdo, array $config, array $event, array $reg, int $staffId): ?string
{
    if ($reg['status'] !== 'confirmed') return 'Only a confirmed registration has a badge.';
    $badge = confIssueBadge($pdo, (int)$event['id'], (int)$reg['id'], $staffId, 'reprinted');
    confEmail($pdo, $config, $event, $reg, 'confirmed', ['badgeId' => $badge['id'], 'reprint' => true]);
    return null;
}

function confCancel(PDO $pdo, array $event, array $reg, int $staffId, string $reason): ?string
{
    if ($reg['status'] === 'cancelled') return 'Already cancelled.';
    if (trim($reason) === '') return 'Give a reason for the cancellation.';
    $pdo->prepare('UPDATE conf_registrations SET status = "cancelled", cancelled_at = NOW(), cancel_reason = ? WHERE id = ?')
        ->execute([mb_substr(trim($reason), 0, 300), (int)$reg['id']]);
    $pdo->prepare('UPDATE conf_badges SET status = "cancelled", cancelled_at = NOW() WHERE registration_id = ? AND status = "active"')->execute([(int)$reg['id']]);
    confAudit($pdo, (int)$event['id'], (int)$reg['id'], $staffId, 'cancelled', ['reason' => $reason]);
    return null;
}

/**
 * Passes a registration to a colleague (schematic: Transferred, then the new delegate gets a
 * new QR). The colleague needs a portal account; the old badge stops working at once.
 */
function confTransfer(PDO $pdo, array $config, array $event, array $reg, int $staffId, string $toEmail, string $badgeName): ?string
{
    if ($reg['status'] === 'cancelled') return 'A cancelled registration cannot be transferred.';
    $stmt = $pdo->prepare('SELECT id FROM users WHERE LOWER(email) = ? LIMIT 1');
    $stmt->execute([strtolower(trim($toEmail))]);
    $toUser = (int)($stmt->fetchColumn() ?: 0);
    if (!$toUser) return 'No portal account uses that email. The colleague needs to create one first.';
    if ($toUser === (int)$reg['user_id']) return 'That is the same person.';
    if (confRegistrationFor($pdo, (int)$event['id'], $toUser)) return 'That person already has a registration.';
    $badgeName = trim($badgeName);
    if ($badgeName === '' || mb_strlen($badgeName) > 60) return 'Enter the name to print on the new badge.';
    $pdo->prepare('UPDATE conf_registrations SET user_id = ?, badge_name = ?, transferred_from = ? WHERE id = ?')
        ->execute([$toUser, $badgeName, (int)$reg['user_id'], (int)$reg['id']]);
    confAudit($pdo, (int)$event['id'], (int)$reg['id'], $staffId, 'transferred', ['from' => (int)$reg['user_id'], 'to' => $toUser]);
    $reg = confRegistrationRow($pdo, (int)$reg['id']);
    if ($reg['status'] === 'confirmed') {
        $badge = confIssueBadge($pdo, (int)$event['id'], (int)$reg['id'], $staffId, 'transferred');
        confEmail($pdo, $config, $event, $reg, 'confirmed', ['badgeId' => $badge['id']]);
    }
    return null;
}

function confUpdateDetails(PDO $pdo, array $event, array $reg, int $staffId, array $data): ?string
{
    $fields = ['badgeName' => ['badge_name', 60, true], 'institution' => ['institution', 200, true], 'country' => ['country', 60, true],
               'profession' => ['profession', 120, false], 'phone' => ['phone', 30, false], 'dietary' => ['dietary', 200, false],
               'accessibility' => ['accessibility', 300, false]];
    $sets = [];
    $values = [];
    foreach ($fields as $key => [$col, $max, $required]) {
        if (!array_key_exists($key, $data)) continue;
        $v = trim((string)$data[$key]);
        if ($required && $v === '') return 'Badge name, institution and country cannot be empty.';
        $sets[] = "{$col} = ?";
        $values[] = $v === '' ? null : mb_substr($v, 0, $max);
    }
    if (!$sets) return null;
    $values[] = (int)$reg['id'];
    $pdo->prepare('UPDATE conf_registrations SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($values);
    confAudit($pdo, (int)$event['id'], (int)$reg['id'], $staffId, 'details_changed', array_intersect_key($data, $fields));
    return null;
}

/** Badges for printing: confirmed registrations' active badges, optionally only unprinted. */
function confBadgesForPrint(PDO $pdo, int $eventId, bool $unprintedOnly, ?array $ids = null): array
{
    $sql = 'SELECT b.id, b.code, b.printed_at, r.ref, r.badge_name, r.institution, r.country, c.name AS category
            FROM conf_badges b JOIN conf_registrations r ON r.id = b.registration_id JOIN conf_categories c ON c.id = r.category_id
            WHERE r.event_id = ? AND r.status = "confirmed" AND b.status = "active"';
    $args = [$eventId];
    if ($unprintedOnly) $sql .= ' AND b.printed_at IS NULL';
    if ($ids) {
        $ids = array_map('intval', $ids);
        $sql .= ' AND r.id IN (' . implode(',', $ids ?: [0]) . ')';
    }
    // Sorted by surname-ish (last word of the badge name), the order desks file badges in.
    $sql .= ' ORDER BY SUBSTRING_INDEX(r.badge_name, " ", -1), r.badge_name';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($args);
    return array_map(fn($r) => ['badgeId' => (int)$r['id'], 'code' => $r['code'], 'ref' => $r['ref'], 'name' => $r['badge_name'],
                                'institution' => $r['institution'], 'country' => $r['country'], 'category' => $r['category'],
                                'printed' => $r['printed_at'] !== null], $stmt->fetchAll());
}

function confMarkPrinted(PDO $pdo, int $eventId, array $badgeIds, int $by): int
{
    $ids = array_values(array_filter(array_map('intval', $badgeIds)));
    if (!$ids) return 0;
    $stmt = $pdo->prepare('UPDATE conf_badges b JOIN conf_registrations r ON r.id = b.registration_id
                           SET b.printed_at = NOW() WHERE r.event_id = ? AND b.id IN (' . implode(',', $ids) . ') AND b.printed_at IS NULL');
    $stmt->execute([$eventId]);
    confAudit($pdo, $eventId, null, $by, 'badges_printed', ['count' => $stmt->rowCount()]);
    return $stmt->rowCount();
}

// ---------------------------------------------------------------------------------------
// Check-in
// ---------------------------------------------------------------------------------------

/**
 * Everything a scanning phone needs to decide on its own: active badges with the name to
 * show, cancelled codes (grey), and who is already in today (orange). Kept to what the door
 * needs; no email, phone or payment details leave the server.
 */
function confRoster(PDO $pdo, array $event): array
{
    $eventId = (int)$event['id'];
    $active = $pdo->prepare('SELECT b.code, r.id AS reg_id, r.ref, r.badge_name, r.institution, c.name AS category
                             FROM conf_badges b JOIN conf_registrations r ON r.id = b.registration_id JOIN conf_categories c ON c.id = r.category_id
                             WHERE r.event_id = ? AND r.status = "confirmed" AND b.status = "active"');
    $active->execute([$eventId]);
    $cancelled = $pdo->prepare('SELECT b.code FROM conf_badges b JOIN conf_registrations r ON r.id = b.registration_id
                                WHERE r.event_id = ? AND (b.status = "cancelled" OR r.status = "cancelled")');
    $cancelled->execute([$eventId]);
    $today = confToday($event)->format('Y-m-d');
    $inToday = $pdo->prepare('SELECT s.registration_id, MIN(s.scanned_at) first_at FROM conf_scans s
                              WHERE s.event_id = ? AND s.scan_day = ? AND s.result = "ok" GROUP BY s.registration_id');
    $inToday->execute([$eventId, $today]);
    $firstAt = [];
    foreach ($inToday->fetchAll() as $r) $firstAt[(int)$r['registration_id']] = $r['first_at'];
    return [
        'day' => $today,
        'badges' => array_map(fn($r) => ['code' => $r['code'], 'ref' => $r['ref'], 'name' => $r['badge_name'],
                                         'institution' => $r['institution'], 'category' => $r['category'],
                                         'inTodayAt' => $firstAt[(int)$r['reg_id']] ?? null], $active->fetchAll()),
        'cancelled' => $cancelled->fetchAll(PDO::FETCH_COLUMN),
        'generatedAt' => gmdate('c'),
    ];
}

/**
 * Stores scans uploaded from phones, in the order they were made, and returns the server's
 * verdict on each. The server decides again rather than trusting the phone: two phones that
 * were both offline can each have let the same badge in, and only here is that visible.
 */
function confSyncScans(PDO $pdo, array $event, int $staffId, array $scans): array
{
    $eventId = (int)$event['id'];
    $zone = function_exists('absZone') ? absZone($event) : new DateTimeZone('Africa/Nairobi');
    usort($scans, fn($a, $b) => strcmp((string)($a['scannedAt'] ?? ''), (string)($b['scannedAt'] ?? '')));
    $results = [];
    foreach (array_slice($scans, 0, 500) as $s) {
        $clientId = substr(preg_replace('/[^A-Za-z0-9-]/', '', (string)($s['clientId'] ?? '')), 0, 40);
        $code = strtoupper(substr(trim((string)($s['code'] ?? '')), 0, 40));
        if ($clientId === '' || $code === '') continue;
        $existing = $pdo->prepare('SELECT result FROM conf_scans WHERE client_id = ?');
        $existing->execute([$clientId]);
        if ($prior = $existing->fetchColumn()) { $results[] = ['clientId' => $clientId, 'result' => $prior]; continue; }

        try {
            $at = new DateTimeImmutable((string)($s['scannedAt'] ?? 'now'));
        } catch (Throwable $e) {
            $at = new DateTimeImmutable('now');
        }
        $at = $at->setTimezone($zone);
        $day = $at->format('Y-m-d');
        $b = $pdo->prepare('SELECT b.id, b.status, b.registration_id, r.status AS reg_status, r.event_id
                            FROM conf_badges b JOIN conf_registrations r ON r.id = b.registration_id WHERE b.code = ?');
        $b->execute([$code]);
        $badge = $b->fetch();
        $result = 'unknown';
        if ($badge && (int)$badge['event_id'] === $eventId) {
            if ($badge['status'] !== 'active' || $badge['reg_status'] !== 'confirmed') {
                $result = 'cancelled';
            } else {
                $dup = $pdo->prepare('SELECT 1 FROM conf_scans WHERE registration_id = ? AND scan_day = ? AND result = "ok" LIMIT 1');
                $dup->execute([(int)$badge['registration_id'], $day]);
                $result = $dup->fetch() ? 'duplicate' : 'ok';
            }
        }
        $pdo->prepare('INSERT IGNORE INTO conf_scans (event_id, client_id, code, badge_id, registration_id, scan_day, gate, result, scanned_by, scanned_at)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$eventId, $clientId, $code, $badge['id'] ?? null, $badge ? (int)$badge['registration_id'] : null, $day,
                       mb_substr(trim((string)($s['gate'] ?? '')), 0, 60) ?: null, $result, $staffId, $at->format('Y-m-d H:i:s')]);
        $results[] = ['clientId' => $clientId, 'result' => $result];
    }
    return $results;
}

// ---------------------------------------------------------------------------------------
// Overview and lists for staff
// ---------------------------------------------------------------------------------------

function confStats(PDO $pdo, array $event): array
{
    $eventId = (int)$event['id'];
    $byStatus = $pdo->prepare('SELECT status, COUNT(*) n FROM conf_registrations WHERE event_id = ? GROUP BY status');
    $byStatus->execute([$eventId]);
    $byCategory = $pdo->prepare('SELECT c.name, r.status, COUNT(*) n FROM conf_registrations r JOIN conf_categories c ON c.id = r.category_id
                                 WHERE r.event_id = ? AND r.status <> "cancelled" GROUP BY c.name, r.status ORDER BY c.name');
    $byCategory->execute([$eventId]);
    $money = $pdo->prepare('SELECT p.currency, p.status, SUM(p.amount) total, COUNT(*) n FROM conf_payments p
                            JOIN conf_registrations r ON r.id = p.registration_id WHERE r.event_id = ? GROUP BY p.currency, p.status');
    $money->execute([$eventId]);
    $scans = $pdo->prepare('SELECT scan_day, COUNT(DISTINCT registration_id) people FROM conf_scans WHERE event_id = ? AND result = "ok" GROUP BY scan_day ORDER BY scan_day');
    $scans->execute([$eventId]);
    $printed = $pdo->prepare('SELECT SUM(b.printed_at IS NOT NULL) printed, COUNT(*) total FROM conf_badges b JOIN conf_registrations r ON r.id = b.registration_id
                              WHERE r.event_id = ? AND r.status = "confirmed" AND b.status = "active"');
    $printed->execute([$eventId]);
    $p = $printed->fetch();
    return [
        'byStatus' => array_column($byStatus->fetchAll(), 'n', 'status'),
        'byCategory' => $byCategory->fetchAll(),
        'money' => $money->fetchAll(),
        'checkinsByDay' => $scans->fetchAll(),
        'badges' => ['printed' => (int)($p['printed'] ?? 0), 'total' => (int)($p['total'] ?? 0)],
    ];
}

function confList(PDO $pdo, array $event, array $filters): array
{
    $sql = 'SELECT r.*, u.email, c.name AS category,
                   (SELECT COUNT(*) FROM conf_payments p WHERE p.registration_id = r.id AND p.status = "pending") AS pending_payments,
                   (SELECT COUNT(DISTINCT s.scan_day) FROM conf_scans s WHERE s.registration_id = r.id AND s.result = "ok") AS days_in
            FROM conf_registrations r JOIN users u ON u.id = r.user_id JOIN conf_categories c ON c.id = r.category_id
            WHERE r.event_id = ?';
    $args = [(int)$event['id']];
    $status = (string)($filters['status'] ?? '');
    if ($status === 'pending_payment') {
        $sql .= ' AND EXISTS (SELECT 1 FROM conf_payments p WHERE p.registration_id = r.id AND p.status = "pending")';
    } elseif (in_array($status, ['awaiting_payment', 'confirmed', 'cancelled'], true)) {
        $sql .= ' AND r.status = ?';
        $args[] = $status;
    }
    $q = trim((string)($filters['q'] ?? ''));
    if ($q !== '') {
        $sql .= ' AND (r.badge_name LIKE ? OR u.email LIKE ? OR r.ref LIKE ? OR r.institution LIKE ? OR r.phone LIKE ?)';
        $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
        array_push($args, $like, $like, $like, $like, $like);
    }
    $sql .= ' ORDER BY r.id DESC LIMIT 1000';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($args);
    return array_map(fn($r) => [
        'id' => (int)$r['id'], 'ref' => $r['ref'], 'name' => $r['badge_name'], 'email' => $r['email'],
        'institution' => $r['institution'], 'country' => $r['country'], 'phone' => $r['phone'], 'category' => $r['category'],
        'status' => $r['status'], 'amountDue' => (float)$r['amount_due'], 'currency' => $r['currency'], 'feeLabel' => $r['fee_label'],
        'pendingPayments' => (int)$r['pending_payments'], 'daysIn' => (int)$r['days_in'], 'createdAt' => $r['created_at'],
    ], $stmt->fetchAll());
}

// ---------------------------------------------------------------------------------------
// Email
// ---------------------------------------------------------------------------------------

/** Sends through the abstract system's paced queue, so every conference email shares one hourly budget. */
function confEmail(PDO $pdo, array $config, array $event, array $reg, string $kind, array $vars = []): bool
{
    if (!function_exists('absSendLogged') || !function_exists('cardEmailHtml')) return false;
    $u = $pdo->prepare('SELECT u.email, mp.title, mp.first_name, mp.surname FROM users u LEFT JOIN member_profiles mp ON mp.user_id = u.id WHERE u.id = ?');
    $u->execute([(int)$reg['user_id']]);
    $who = $u->fetch() ?: [];
    $to = (string)($who['email'] ?? '');
    if ($to === '') return false;
    $salutation = function_exists('emailSalutation')
        ? emailSalutation($who['title'] ?? null, $who['first_name'] ?? null, $who['surname'] ?? null)
        : 'Dear delegate,';
    if (empty($who['first_name']) && function_exists('emailSalutation')) {
        $parts = preg_split('/\s+/u', trim((string)$reg['badge_name'])) ?: [];
        $salutation = emailSalutation(null, $parts[0] ?? null, null);
    }
    $portal = rtrim((string)($config['portal_base_url'] ?? ''), '/') ?: 'https://www.resok.org/resok-portal/public';
    $link = $portal . '/conference';
    $name = (string)$event['name'];
    $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
    $p = fn($html, $last = false) => cardEmailParagraph($html, $last);

    if ($kind === 'confirmed') {
        $subject = "You're registered: {$name}";
        $heading = "You're registered";
        $body = $p('Your registration for <strong>' . $h($name) . '</strong> is confirmed. Your reference is <strong>' . $h($reg['ref']) . '</strong>.')
              . $p('Your personal QR code is in your account. Open it on your phone at the badge desk to collect your badge; the same code is printed on the badge and scanned at each session, which records your attendance for CPD points.'
                   . (!empty($vars['reprint']) ? ' <strong>A new badge has been issued; your earlier badge no longer works.</strong>' : ''), true);
        $text = "{$salutation}\n\nYour registration for {$name} is confirmed. Reference: {$reg['ref']}.\n\n"
              . "Your personal QR code is in your account: {$link}\nShow it at the badge desk to collect your badge.\n\nReSoK ICT";
        $cta = 'Show my QR code';
    } elseif ($kind === 'payment_rejected') {
        $subject = "Payment not confirmed: {$name}";
        $heading = 'We could not confirm your payment';
        $body = $p('We could not confirm the payment you submitted for registration <strong>' . $h($reg['ref']) . '</strong>.')
              . $p('Reason: ' . $h($vars['note'] ?? ''))
              . $p('Please check the details and submit the payment again from your account, or reply to the secretariat if you think this is a mistake.', true);
        $text = "{$salutation}\n\nWe could not confirm the payment you submitted for registration {$reg['ref']}.\nReason: " . ($vars['note'] ?? '')
              . "\n\nPlease submit it again from your account: {$link}\n\nReSoK ICT";
        $cta = 'Open my registration';
    } elseif ($kind === 'payment_reminder') {
        $due = number_format((float)$reg['amount_due'], 0) . ' ' . $reg['currency'];
        $subject = "Complete your registration: {$name}";
        $heading = 'Your registration is waiting for payment';
        $body = $p('Thank you for registering for <strong>' . $h($name) . '</strong>. Your place is not confirmed until payment is received.')
              . $p('Reference <strong>' . $h($reg['ref']) . '</strong>, amount due <strong>' . $h($due) . '</strong>. Once you have paid, enter the M-Pesa code or upload the bank slip on your registration page and your QR code will follow.', true);
        $text = "{$salutation}\n\nThank you for registering for {$name}. Your place is not confirmed until payment is received.\n"
              . "Reference {$reg['ref']}, amount due {$due}. Once you have paid, enter the M-Pesa code or upload the bank slip here: {$link}\n\nReSoK ICT";
        $cta = 'Complete my registration';
    } else {
        return false;
    }
    $html = cardEmailHtml($heading, $salutation, $body, $cta, $link, 'Questions? Contact the conference secretariat.');
    $key = match ($kind) {
        'confirmed' => 'conf-confirmed-' . $reg['id'] . '-' . ($vars['badgeId'] ?? 0),
        'payment_reminder' => 'conf-payrem-' . $reg['id'] . '-' . (int)($vars['stage'] ?? 1),
        default => null,
    };
    return absSendLogged($pdo, $config, (int)$event['id'], null, 'conf_' . $kind, $to, $subject, $text, $html, $key)
        || ($key === null);
}

// ---------------------------------------------------------------------------------------
// CPD: door scans become the attendance list
// ---------------------------------------------------------------------------------------

/**
 * The days on which anyone was scanned in, each with the CPD event it is linked to (if any),
 * and the CPD events that could be chosen. Schematic: "Nobody types attendance lists after
 * the conference: the scans are the list."
 */
function confCpdOverview(PDO $pdo, array $event): array
{
    $eventId = (int)$event['id'];
    $days = $pdo->prepare('SELECT scan_day, COUNT(DISTINCT registration_id) people FROM conf_scans
                           WHERE event_id = ? AND result = "ok" GROUP BY scan_day ORDER BY scan_day');
    $days->execute([$eventId]);
    $links = $pdo->prepare('SELECT * FROM conf_cpd_links WHERE event_id = ?');
    $links->execute([$eventId]);
    $byDay = [];
    foreach ($links->fetchAll() as $l) $byDay[$l['scan_day']] = $l;
    $cpdEvents = [];
    try {
        $rows = $pdo->query('SELECT id, title, starts_at, approved_points, approval_ref FROM cpd_events ORDER BY starts_at DESC LIMIT 200')->fetchAll();
        foreach ($rows as $r) {
            $cpdEvents[] = ['id' => (int)$r['id'], 'title' => $r['title'], 'date' => substr((string)$r['starts_at'], 0, 10),
                            'points' => $r['approved_points'] !== null ? (float)$r['approved_points'] : null, 'approvalRef' => $r['approval_ref']];
        }
    } catch (Throwable $e) {
        // No CPD events table on this server yet: nothing to link to.
    }
    return [
        'days' => array_map(fn($d) => [
            'day' => $d['scan_day'], 'people' => (int)$d['people'],
            'cpdEventId' => isset($byDay[$d['scan_day']]) ? (int)$byDay[$d['scan_day']]['cpd_event_id'] : null,
            'pushedCount' => isset($byDay[$d['scan_day']]['pushed_count']) ? (int)$byDay[$d['scan_day']]['pushed_count'] : null,
            'pushedAt' => $byDay[$d['scan_day']]['pushed_at'] ?? null,
        ], $days->fetchAll()),
        'cpdEvents' => $cpdEvents,
    ];
}

/**
 * Sends everyone scanned in on a day to that day's CPD event as an in-person attendee, through
 * the same attendanceRecord() a pasted register uses. Safe to send again: attendees are kept
 * per event and email, so a second send after late scans adds only the new people.
 *
 * @return array{0: ?array, 1: ?string}
 */
function confPushToCpd(PDO $pdo, array $event, string $day, int $cpdEventId, int $by): array
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) return [null, 'Choose a day.'];
    if (!function_exists('attendanceRecord') || !function_exists('attendanceEnsureTables') || !attendanceEnsureTables($pdo)) {
        return [null, 'The CPD attendance module is not available on this server.'];
    }
    $check = $pdo->prepare('SELECT id, title FROM cpd_events WHERE id = ?');
    $check->execute([$cpdEventId]);
    $cpd = $check->fetch();
    if (!$cpd) return [null, 'Choose the CPD event for this day.'];

    $stmt = $pdo->prepare('SELECT DISTINCT r.id, r.badge_name, u.email FROM conf_scans s
                           JOIN conf_registrations r ON r.id = s.registration_id JOIN users u ON u.id = r.user_id
                           WHERE s.event_id = ? AND s.scan_day = ? AND s.result = "ok" AND r.status = "confirmed"');
    $stmt->execute([(int)$event['id'], $day]);
    $rows = array_map(fn($r) => ['name' => $r['badge_name'], 'email' => strtolower((string)$r['email']), 'kmpdc' => null, 'minutes' => null],
                      $stmt->fetchAll());
    if (!$rows) return [null, 'Nobody was scanned in on that day.'];
    $result = attendanceRecord($pdo, $cpdEventId, $rows, 'in_person', 'register', $by, true);
    $pdo->prepare('INSERT INTO conf_cpd_links (event_id, scan_day, cpd_event_id, pushed_count, pushed_at, pushed_by) VALUES (?, ?, ?, ?, NOW(), ?)
                   ON DUPLICATE KEY UPDATE cpd_event_id = VALUES(cpd_event_id), pushed_count = VALUES(pushed_count), pushed_at = NOW(), pushed_by = VALUES(pushed_by)')
        ->execute([(int)$event['id'], $day, $cpdEventId, count($rows), $by]);
    confAudit($pdo, (int)$event['id'], null, $by, 'cpd_sent', ['day' => $day, 'cpdEvent' => $cpdEventId] + $result);
    return [$result + ['sent' => count($rows), 'cpdEvent' => $cpd['title']], null];
}

// ---------------------------------------------------------------------------------------
// Certificate of attendance
// ---------------------------------------------------------------------------------------

/** The days a registration was scanned in, each with the CPD points of its linked CPD event. */
function confAttendedDays(PDO $pdo, array $reg): array
{
    try {
        $stmt = $pdo->prepare('SELECT DISTINCT s.scan_day, e.approved_points FROM conf_scans s
                               LEFT JOIN conf_cpd_links l ON l.event_id = s.event_id AND l.scan_day = s.scan_day
                               LEFT JOIN cpd_events e ON e.id = l.cpd_event_id
                               WHERE s.registration_id = ? AND s.result = "ok" ORDER BY s.scan_day');
        $stmt->execute([(int)$reg['id']]);
        return array_map(fn($r) => ['day' => $r['scan_day'], 'points' => $r['approved_points'] !== null ? (float)$r['approved_points'] : null], $stmt->fetchAll());
    } catch (Throwable $e) {
        // cpd_events missing on this server: days without points.
        $plain = $pdo->prepare('SELECT DISTINCT scan_day FROM conf_scans WHERE registration_id = ? AND result = "ok" ORDER BY scan_day');
        $plain->execute([(int)$reg['id']]);
        return array_map(fn($d) => ['day' => $d, 'points' => null], $plain->fetchAll(PDO::FETCH_COLUMN));
    }
}

/**
 * A4 landscape certificate in the same frame as the Academy's, so the Society's certificates
 * look like one family. Issued only for days actually scanned in at the venue.
 */
function confCertificatePdf(PDO $pdo, array $event, array $reg): ?string
{
    $days = confAttendedDays($pdo, $reg);
    if (!$days || $reg['status'] !== 'confirmed') return null;
    if (!class_exists('SimplePdf')) require_once __DIR__ . '/SimplePdf.php';
    $clean = static function (string $t, bool $unicode): string {
        return function_exists('academyCertificatePdfText') ? academyCertificatePdfText($t, $unicode) : $t;
    };

    $w = 842.0; $h = 595.0; $cx = $w / 2;
    $pdf = new SimplePdf($w, $h);
    $fonts = dirname(__DIR__) . '/fonts/';
    $unicode = $pdf->useUnicodeFonts($fonts . 'NotoSans-Regular.ttf', $fonts . 'NotoSans-Bold.ttf');

    $pdf->setFillColor(255, 255, 255);
    $pdf->rect(0, 0, $w, $h);
    $pdf->setFillColor(6, 48, 26);
    $pdf->rect(0, 0, $w, 16);
    $pdf->rect(0, $h - 16, $w, 16);
    $pdf->setFillColor(0, 147, 46);
    $pdf->rect(0, 16, $w, 4);
    $pdf->rect(0, $h - 20, $w, 4);
    $pdf->setStrokeColor(207, 218, 211);
    $pdf->rect(36, 44, $w - 72, $h - 88, 'S');

    $y = 70.0;
    $logo = dirname(__DIR__, 2) . '/assets/img/certificate-resok-logo.jpg';
    $size = @getimagesize($logo);
    $logoW = 240.0;
    $logoH = $size ? $logoW * $size[1] / $size[0] : 64.0;
    if ($pdf->image($logo, $cx - $logoW / 2, $y, $logoW, $logoH)) {
        $y += $logoH + 30;
    } else {
        $pdf->setTextColor(6, 48, 26);
        $pdf->text($cx, $y + 26, 'RESPIRATORY SOCIETY OF KENYA', 16, true, 'C');
        $y += 56;
    }
    $pdf->setTextColor(19, 32, 25);
    $pdf->text($cx, $y, 'CERTIFICATE OF ATTENDANCE', 30, true, 'C');
    $y += 16;
    $pdf->setStrokeColor(188, 11, 34);
    $pdf->line($cx - 60, $y, $cx + 60, $y, 1.5);
    $y += 36;
    $pdf->setTextColor(109, 127, 116);
    $pdf->text($cx, $y, 'This is to certify that', 13, false, 'C');
    $y += 40;
    $pdf->setTextColor(6, 48, 26);
    $pdf->text($cx, $y, $clean((string)$reg['badge_name'], $unicode), 28, true, 'C');
    $y += 34;
    $pdf->setTextColor(109, 127, 116);
    $pdf->text($cx, $y, 'attended', 13, false, 'C');
    $y += 30;
    $pdf->setTextColor(19, 32, 25);
    $title = $clean((string)$event['name'], $unicode);
    $lines = function_exists('academyCertificatePdfLines')
        ? academyCertificatePdfLines($title, 640, 2, fn($t) => $pdf->measure($t, 17, true))
        : [$title];
    foreach ($lines as $line) { $pdf->text($cx, $y, $line, 17, true, 'C'); $y += 22; }

    $fmt = fn($d) => (new DateTimeImmutable((string)$d))->format('j F Y');
    $dayList = implode(', ', array_map(fn($d) => $fmt($d['day']), $days));
    $points = array_sum(array_map(fn($d) => (float)($d['points'] ?? 0), $days));
    $y += 6;
    $pdf->setTextColor(52, 64, 84);
    $where = trim((string)($event['venue'] ?? ''));
    $pdf->text($cx, $y, $clean('on ' . $dayList . ($where !== '' ? ' at ' . $where : ''), $unicode), 12.5, false, 'C');
    if ($points > 0) {
        $y += 22;
        $pdf->setTextColor(6, 48, 26);
        $pdf->text($cx, $y, rtrim(rtrim(number_format($points, 1), '0'), '.') . ' KMPDC CPD point' . ($points == 1.0 ? '' : 's'), 13, true, 'C');
    }

    $pdf->setTextColor(109, 127, 116);
    $pdf->text(60, $h - 52, 'Registration ' . $reg['ref'], 9.5);
    $pdf->text($w - 60, $h - 52, 'Issued ' . date('j F Y') . ' by the Respiratory Society of Kenya', 9.5, false, 'R');
    return $pdf->output();
}

// ---------------------------------------------------------------------------------------
// Reminders (run from cron/abstracts.php every 15 minutes)
// ---------------------------------------------------------------------------------------

/**
 * Delegates who registered but have not paid, 3 and then 10 days after registering, while
 * registration is open. Nobody with a payment waiting to be checked is chased. Each reminder
 * carries a once-only key, so running this every 15 minutes sends each one once.
 */
function confSendPaymentReminders(PDO $pdo, array $config): int
{
    $sent = 0;
    $groupsReady = function_exists('confGroupsEnsureTables') && confGroupsEnsureTables($pdo);
    $events = $pdo->query("SELECT * FROM abs_events WHERE status = 'published'")->fetchAll();
    foreach ($events as $event) {
        if (confRegistrationState($event, confSettings($pdo, (int)$event['id'])) !== 'open') continue;
        $stmt = $pdo->prepare("SELECT r.*, DATEDIFF(NOW(), r.created_at) AS age FROM conf_registrations r
                               WHERE r.event_id = ? AND r.status = 'awaiting_payment' AND r.amount_due > 0
                                 AND NOT EXISTS (SELECT 1 FROM conf_payments p WHERE p.registration_id = r.id AND p.status IN ('pending','confirmed'))
                                 AND r.created_at <= (NOW() - INTERVAL 3 DAY)"
                               // An institution paying on an invoice is chased by finance, not each delegate.
                               . ($groupsReady ? ' AND NOT EXISTS (SELECT 1 FROM conf_group_members gm WHERE gm.registration_id = r.id)' : ''));
        $stmt->execute([(int)$event['id']]);
        foreach ($stmt->fetchAll() as $reg) {
            $stage = (int)$reg['age'] >= 10 ? 2 : 1;
            if (confEmail($pdo, $config, $event, $reg, 'payment_reminder', ['stage' => $stage])) $sent++;
        }
    }
    return $sent;
}

/**
 * Presenting authors of accepted abstracts, once the decision is released, who have not
 * registered: 3 days after release and again 14 days after. Platform schematic: an accepted
 * abstract is confirmed in the programme only once its presenter is registered.
 */
function confSendPresenterReminders(PDO $pdo, array $config): int
{
    $sent = 0;
    $events = $pdo->query("SELECT * FROM abs_events WHERE status = 'published'")->fetchAll();
    foreach ($events as $event) {
        if (confRegistrationState($event, confSettings($pdo, (int)$event['id'])) !== 'open') continue;
        $stmt = $pdo->prepare("SELECT a.id, a.reference, a.title, au.first_name, au.last_name, au.email, DATEDIFF(NOW(), d.released_at) AS age
                               FROM abs_abstracts a
                               JOIN abs_decisions d ON d.abstract_id = a.id
                               JOIN abs_authors au ON au.abstract_id = a.id AND au.is_presenting = 1
                               WHERE a.event_id = ? AND d.outcome = 'accept' AND d.released_at IS NOT NULL
                                 AND d.released_at <= (NOW() - INTERVAL 3 DAY) AND a.status NOT IN ('withdrawn')
                                 AND NOT EXISTS (SELECT 1 FROM conf_registrations r JOIN users u ON u.id = r.user_id
                                                 WHERE r.event_id = a.event_id AND r.status <> 'cancelled' AND LOWER(u.email) = LOWER(au.email))");
        $stmt->execute([(int)$event['id']]);
        foreach ($stmt->fetchAll() as $row) {
            $stage = (int)$row['age'] >= 14 ? 2 : 1;
            $to = (string)$row['email'];
            if ($to === '' || !function_exists('absSendLogged') || !function_exists('cardEmailHtml')) continue;
            $portal = rtrim((string)($config['portal_base_url'] ?? ''), '/') ?: 'https://www.resok.org/resok-portal/public';
            $salutation = function_exists('emailSalutation') ? emailSalutation(null, $row['first_name'], null) : 'Dear colleague,';
            $plainTitle = function_exists('absPlain') ? absPlain($row['title']) : strip_tags((string)$row['title']);
            $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
            $html = cardEmailHtml('Please register to present', $salutation,
                cardEmailParagraph('Congratulations again on the acceptance of your abstract <strong>' . $h($row['reference']) . '</strong>, "' . $h($plainTitle) . '".')
                . cardEmailParagraph('As the presenting author you need to register for <strong>' . $h($event['name']) . '</strong> so that your presentation can be confirmed in the programme. If you have not registered when the programme is finalised, the slot may be offered to an abstract on the waiting list.', true),
                'Register now', $portal . '/conference', 'If someone else is now presenting, please tell the secretariat.');
            $text = "{$salutation}\n\nYour abstract {$row['reference']} was accepted. As the presenting author, please register for {$event['name']} so your presentation can be confirmed in the programme: {$portal}/conference\n\nReSoK ICT";
            $key = 'conf-presenter-' . $row['id'] . '-' . $stage;
            if (absSendLogged($pdo, $config, (int)$event['id'], (int)$row['id'], 'conf_presenter_reminder', $to,
                              'Please register to present: ' . $event['name'], $text, $html, $key)) {
                $sent++;
            }
        }
    }
    return $sent;
}

// ---------------------------------------------------------------------------------------
// Visa invitation letter
// ---------------------------------------------------------------------------------------

/**
 * A letter an international delegate can take to an embassy. Passport details are typed by
 * the delegate when they download it and are printed, never stored.
 */
function confInvitationLetterPdf(array $event, array $reg, string $passport, string $nationality): string
{
    if (!class_exists('SimplePdf')) require_once __DIR__ . '/SimplePdf.php';
    $clean = static fn(string $t, bool $u): string => function_exists('academyCertificatePdfText') ? academyCertificatePdfText($t, $u) : $t;
    $pdf = new SimplePdf(595, 842);
    $fonts = dirname(__DIR__) . '/fonts/';
    $u = $pdf->useUnicodeFonts($fonts . 'NotoSans-Regular.ttf', $fonts . 'NotoSans-Bold.ttf');

    $logo = dirname(__DIR__, 2) . '/assets/img/certificate-resok-logo.jpg';
    $size = @getimagesize($logo);
    $lw = 190.0;
    $lh = $size ? $lw * $size[1] / $size[0] : 50.0;
    $pdf->image($logo, 56, 48, $lw, $lh);
    $pdf->setFillColor(0, 147, 46);
    $pdf->rect(56, 48 + $lh + 12, 483, 2);
    $pdf->setTextColor(102, 112, 133);
    $pdf->text(539, 70, 'Respiratory Society of Kenya', 9, false, 'R');
    $pdf->text(539, 83, 'www.resok.org', 9, false, 'R');
    $pdf->text(539, 96, 'kislcsecretariat@resok.org', 9, false, 'R');

    $y = 48 + $lh + 50;
    $pdf->setTextColor(22, 32, 44);
    $pdf->text(56, $y, date('j F Y'), 11);
    $y += 22;
    $pdf->text(56, $y, 'Ref: ' . $reg['ref'], 11);
    $y += 34;
    $pdf->text(56, $y, 'To the Visa Officer', 11);
    $y += 30;
    $pdf->text(56, $y, 'LETTER OF INVITATION', 11.5, true);
    $y += 17;
    $y = $pdf->multilineText(56, $y, 483, $clean((string)$event['name'], $u), 11.5, 16, true) + 14;

    $fmt = fn($d) => $d ? (new DateTimeImmutable((string)$d))->format('j F Y') : null;
    $dates = $event['starts_on'] ? ($fmt($event['starts_on']) . ($event['ends_on'] && $event['ends_on'] !== $event['starts_on'] ? ' to ' . $fmt($event['ends_on']) : '')) : 'dates to be confirmed';
    $venue = trim((string)($event['venue'] ?? '')) !== '' ? trim((string)$event['venue']) . ', Kenya' : 'Kenya';
    $who = $clean((string)$reg['badge_name'], $u);
    $passportLine = $passport !== '' ? ', holder of passport number ' . $clean($passport, $u) . ($nationality !== '' ? ' (' . $clean($nationality, $u) . ')' : '') : '';
    $paras = [
        "The Respiratory Society of Kenya is pleased to invite {$who}{$passportLine}, of " . $clean((string)$reg['institution'], $u)
            . ', ' . $clean((string)$reg['country'], $u) . ', to attend the ' . $clean((string)$event['name'], $u) . " in {$venue}, {$dates}.",
        'The conference brings together clinicians, researchers, public health practitioners and policymakers working in lung health from Kenya and the region. The delegate is registered under reference ' . $reg['ref'] . '.',
        'This letter is issued to support a visa application. It does not commit the Society to meeting any travel, accommodation, subsistence or other costs, which remain the responsibility of the delegate or their sponsoring institution.',
        'Please contact the conference secretariat at kislcsecretariat@resok.org should you need to verify this invitation.',
    ];
    foreach ($paras as $para) {
        $y = $pdf->multilineText(56, $y, 483, $para, 11, 16) + 12;
    }
    $y += 20;
    $pdf->text(56, $y, 'Yours faithfully,', 11);
    $y += 56;
    $pdf->text(56, $y, 'Conference Secretariat', 11, true);
    $y += 15;
    $pdf->text(56, $y, 'Respiratory Society of Kenya', 11);

    $pdf->setFillColor(6, 48, 26);
    $pdf->rect(0, 826, 595, 16);
    return $pdf->output();
}
