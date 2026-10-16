<?php
declare(strict_types=1);

/**
 * Sponsors and exhibitors (platform blueprint module 9).
 *
 * A sponsor has a tier, a logo shown on the conference site by tier, a booth, and staff who
 * may scan delegates' badges at that booth. A scan is a lead. What the sponsor learns from it
 * is decided by the delegate, not the sponsor: name and institution always, email only when
 * the delegate ticked the consent box at registration (Kenya Data Protection Act 2019:
 * sharing contact details with a third party needs consent). Each sponsor sees only its own
 * leads.
 */

const CONF_SPONSOR_TIERS = ['platinum' => 'Platinum', 'gold' => 'Gold', 'silver' => 'Silver', 'bronze' => 'Bronze',
                            'exhibitor' => 'Exhibitor', 'partner' => 'Partner'];
const CONF_LOGO_MAX_BYTES = 1048576;

function confSponsorSchema(): array
{
    $tail = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    return [
        "CREATE TABLE IF NOT EXISTS conf_sponsors (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            event_id INT UNSIGNED NOT NULL,
            name VARCHAR(160) NOT NULL,
            tier VARCHAR(20) NOT NULL DEFAULT 'exhibitor',
            website VARCHAR(255) NULL,
            booth VARCHAR(40) NULL,
            contact_name VARCHAR(120) NULL,
            contact_email VARCHAR(190) NULL,
            logo_file VARCHAR(120) NULL,
            logo_mime VARCHAR(40) NULL,
            sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY conf_sponsors_event (event_id, tier, sort_order)
        )" . $tail,
        "CREATE TABLE IF NOT EXISTS conf_sponsor_staff (
            sponsor_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (sponsor_id, user_id),
            KEY conf_sponsor_staff_user (user_id)
        )" . $tail,
        // One lead per sponsor per delegate: scanning the same badge again updates the note.
        "CREATE TABLE IF NOT EXISTS conf_leads (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            sponsor_id INT UNSIGNED NOT NULL,
            registration_id INT UNSIGNED NOT NULL,
            scanned_by INT UNSIGNED NULL,
            note VARCHAR(500) NULL,
            scanned_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY conf_leads_unique (sponsor_id, registration_id)
        )" . $tail,
    ];
}

function confSponsorsEnsureTables(PDO $pdo): bool
{
    static $ready = null;
    if ($ready !== null) return $ready;
    try {
        foreach (confSponsorSchema() as $sql) $pdo->exec($sql);
        return $ready = true;
    } catch (Throwable $e) {
        error_log('Sponsor tables unavailable: ' . $e->getMessage());
        return $ready = false;
    }
}

function confLogoDir(array $config): string
{
    return rtrim((string)$config['upload_dir'], '/\\') . '/conference-logos';
}

/** Sponsors for staff (everything) or the public (active ones, no contact details). */
function confSponsors(PDO $pdo, int $eventId, bool $forStaff = false): array
{
    $stmt = $pdo->prepare('SELECT s.*, (SELECT COUNT(*) FROM conf_leads l WHERE l.sponsor_id = s.id) AS leads
                           FROM conf_sponsors s WHERE s.event_id = ?' . ($forStaff ? '' : ' AND s.active = 1') . ' ORDER BY s.sort_order, s.name');
    $stmt->execute([$eventId]);
    $rows = $stmt->fetchAll();
    $staff = [];
    if ($forStaff && $rows) {
        $ids = implode(',', array_map(fn($r) => (int)$r['id'], $rows));
        foreach ($pdo->query("SELECT ss.sponsor_id, u.id, u.email FROM conf_sponsor_staff ss JOIN users u ON u.id = ss.user_id WHERE ss.sponsor_id IN ($ids) ORDER BY u.email")->fetchAll() as $s) {
            $staff[(int)$s['sponsor_id']][] = ['userId' => (int)$s['id'], 'email' => $s['email']];
        }
    }
    $order = array_flip(array_keys(CONF_SPONSOR_TIERS));
    usort($rows, fn($a, $b) => [($order[$a['tier']] ?? 99), (int)$a['sort_order'], $a['name']] <=> [($order[$b['tier']] ?? 99), (int)$b['sort_order'], $b['name']]);
    return array_map(function ($r) use ($forStaff, $staff) {
        $out = ['id' => (int)$r['id'], 'name' => $r['name'], 'tier' => $r['tier'], 'tierLabel' => CONF_SPONSOR_TIERS[$r['tier']] ?? 'Partner',
                'website' => $r['website'], 'booth' => $r['booth'],
                'logoUrl' => $r['logo_file'] ? 'api/index.php?route=conference/sponsors/' . (int)$r['id'] . '/logo' : null];
        if ($forStaff) {
            $out += ['contactName' => $r['contact_name'], 'contactEmail' => $r['contact_email'], 'sortOrder' => (int)$r['sort_order'],
                     'active' => (bool)$r['active'], 'leads' => (int)$r['leads'], 'staff' => $staff[(int)$r['id']] ?? []];
        }
        return $out;
    }, $rows);
}

/** @return array{0: ?int, 1: ?string} */
function confSaveSponsor(PDO $pdo, int $eventId, array $data): array
{
    $name = trim((string)($data['name'] ?? ''));
    if ($name === '' || mb_strlen($name) > 160) return [null, 'Give the sponsor a name.'];
    $tier = (string)($data['tier'] ?? 'exhibitor');
    if (!array_key_exists($tier, CONF_SPONSOR_TIERS)) return [null, 'Unknown tier.'];
    $website = trim((string)($data['website'] ?? ''));
    if ($website !== '' && !preg_match('#^https?://[^\s<>"]+$#i', $website)) return [null, 'The website must start with https://'];
    $email = trim((string)($data['contactEmail'] ?? ''));
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) return [null, 'The contact email is not valid.'];
    $values = [$name, $tier, $website ?: null, mb_substr(trim((string)($data['booth'] ?? '')), 0, 40) ?: null,
               mb_substr(trim((string)($data['contactName'] ?? '')), 0, 120) ?: null, $email ?: null,
               max(0, (int)($data['sortOrder'] ?? 0)), array_key_exists('active', $data) ? (!empty($data['active']) ? 1 : 0) : 1];
    $id = (int)($data['id'] ?? 0);
    if ($id) {
        $stmt = $pdo->prepare('UPDATE conf_sponsors SET name = ?, tier = ?, website = ?, booth = ?, contact_name = ?, contact_email = ?, sort_order = ?, active = ? WHERE id = ? AND event_id = ?');
        $stmt->execute(array_merge($values, [$id, $eventId]));
    } else {
        $pdo->prepare('INSERT INTO conf_sponsors (name, tier, website, booth, contact_name, contact_email, sort_order, active, event_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute(array_merge($values, [$eventId]));
        $id = (int)$pdo->lastInsertId();
    }
    return [$id, null];
}

function confSponsorRow(PDO $pdo, int $eventId, int $sponsorId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM conf_sponsors WHERE id = ? AND event_id = ?');
    $stmt->execute([$sponsorId, $eventId]);
    return $stmt->fetch() ?: null;
}

/** A JPG or PNG up to 1 MB, stored outside the web root and served through the API. */
function confSponsorLogo(PDO $pdo, array $config, int $eventId, int $sponsorId, ?array $file): ?string
{
    $sponsor = confSponsorRow($pdo, $eventId, $sponsorId);
    if (!$sponsor) return 'No such sponsor.';
    if (!$file || (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string)$file['tmp_name'])) return 'The upload did not arrive.';
    if ((int)$file['size'] > CONF_LOGO_MAX_BYTES) return 'The logo must be 1 MB or smaller.';
    $info = @getimagesize((string)$file['tmp_name']);
    if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG], true)) return 'Upload the logo as a JPG or PNG.';
    $dir = confLogoDir($config);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) return 'Logos cannot be stored on the server yet.';
    $ext = $info[2] === IMAGETYPE_JPEG ? 'jpg' : 'png';
    $name = 'logo-' . bin2hex(random_bytes(12)) . '.' . $ext;
    if (!move_uploaded_file((string)$file['tmp_name'], $dir . '/' . $name)) return 'The logo could not be saved.';
    if ($sponsor['logo_file']) @unlink($dir . '/' . basename((string)$sponsor['logo_file']));
    $pdo->prepare('UPDATE conf_sponsors SET logo_file = ?, logo_mime = ? WHERE id = ?')->execute([$name, $info['mime'], $sponsorId]);
    return null;
}

function confSponsorStaffAdd(PDO $pdo, int $eventId, int $sponsorId, string $email): ?string
{
    if (!confSponsorRow($pdo, $eventId, $sponsorId)) return 'No such sponsor.';
    $stmt = $pdo->prepare('SELECT id FROM users WHERE LOWER(email) = ? LIMIT 1');
    $stmt->execute([strtolower(trim($email))]);
    $userId = (int)($stmt->fetchColumn() ?: 0);
    if (!$userId) return 'No portal account uses that email. Ask them to create one first, then add them.';
    $pdo->prepare('INSERT IGNORE INTO conf_sponsor_staff (sponsor_id, user_id) VALUES (?, ?)')->execute([$sponsorId, $userId]);
    return null;
}

function confSponsorStaffRemove(PDO $pdo, int $eventId, int $sponsorId, int $userId): ?string
{
    if (!confSponsorRow($pdo, $eventId, $sponsorId)) return 'No such sponsor.';
    $pdo->prepare('DELETE FROM conf_sponsor_staff WHERE sponsor_id = ? AND user_id = ?')->execute([$sponsorId, $userId]);
    return null;
}

/** The sponsors this account scans for at this conference. */
function confSponsorsForStaff(PDO $pdo, int $eventId, int $userId): array
{
    $stmt = $pdo->prepare('SELECT s.id, s.name, s.booth FROM conf_sponsor_staff ss JOIN conf_sponsors s ON s.id = ss.sponsor_id
                           WHERE ss.user_id = ? AND s.event_id = ? AND s.active = 1 ORDER BY s.name');
    $stmt->execute([$userId, $eventId]);
    return array_map(fn($r) => ['id' => (int)$r['id'], 'name' => $r['name'], 'booth' => $r['booth']], $stmt->fetchAll());
}

/** What a sponsor is allowed to know about a delegate. */
function confLeadShape(array $r): array
{
    return ['id' => (int)$r['id'], 'name' => $r['badge_name'], 'institution' => $r['institution'], 'country' => $r['country'],
            'profession' => $r['profession'], 'email' => !empty($r['share_contact']) ? $r['email'] : null,
            'shared' => !empty($r['share_contact']), 'note' => $r['note'], 'scannedAt' => $r['scanned_at']];
}

/** @return array{0: ?array, 1: ?string} the lead as the sponsor sees it */
function confRecordLead(PDO $pdo, int $eventId, int $sponsorId, int $userId, string $code, string $note): array
{
    $code = strtoupper(trim($code));
    $stmt = $pdo->prepare('SELECT r.id FROM conf_badges b JOIN conf_registrations r ON r.id = b.registration_id
                           WHERE b.code = ? AND b.status = "active" AND r.status = "confirmed" AND r.event_id = ?');
    $stmt->execute([$code, $eventId]);
    $regId = (int)($stmt->fetchColumn() ?: 0);
    if (!$regId) return [null, 'This is not an active badge for this conference.'];
    $pdo->prepare('INSERT INTO conf_leads (sponsor_id, registration_id, scanned_by, note) VALUES (?, ?, ?, ?)
                   ON DUPLICATE KEY UPDATE note = COALESCE(VALUES(note), note), scanned_by = VALUES(scanned_by)')
        ->execute([$sponsorId, $regId, $userId, mb_substr(trim($note), 0, 500) ?: null]);
    $lead = confLeads($pdo, $sponsorId, $regId);
    return [$lead[0] ?? null, null];
}

function confLeads(PDO $pdo, int $sponsorId, ?int $registrationId = null): array
{
    $sql = 'SELECT l.id, l.note, l.scanned_at, r.badge_name, r.institution, r.country, r.profession, r.share_contact, u.email
            FROM conf_leads l JOIN conf_registrations r ON r.id = l.registration_id JOIN users u ON u.id = r.user_id
            WHERE l.sponsor_id = ?' . ($registrationId ? ' AND l.registration_id = ?' : '') . ' ORDER BY l.scanned_at DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($registrationId ? [$sponsorId, $registrationId] : [$sponsorId]);
    return array_map('confLeadShape', $stmt->fetchAll());
}
