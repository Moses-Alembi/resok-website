<?php
declare(strict_types=1);

/**
 * National ILD MDT case submission: a clinician submits a case, a coordinator puts it on a
 * meeting's agenda, the panel discusses it and records a recommendation, and the clinician
 * reads it back as a printable letter.
 *
 * This module holds clinical information about real patients, so five rules shape it:
 *
 * 1. No direct identifiers. The form asks for age, sex and county - never a name, ID number,
 *    phone number or hospital number - and submitting is refused while the free text still
 *    contains something shaped like a phone number, an ID number or an email address.
 *
 * 2. Clinical content is encrypted at rest. Everything a reader could use to recognise a
 *    patient (the history, the investigations, the panel's discussion, uploaded files) goes
 *    through cryptoEncrypt(). Age, sex, county and status stay readable so lists and
 *    statistics work without decrypting anything.
 *
 * 3. Need to see, not role to see. The submitter sees their own cases. Coordinators see
 *    every submitted case. Panellists see only cases on a meeting agenda. Every staff view
 *    of a case is written to mdt_audit.
 *
 * 4. Email carries no clinical detail. Messages say that case MDT-2026-0004 has changed and
 *    link to the portal; the content is only ever read after signing in.
 *
 * 5. Nothing is kept forever. Files and clinical text are purged mdt_retention_months after
 *    a case closes (24 by default), and abandoned drafts after 90 days. What survives is the
 *    anonymous outline - age band, sex, county, diagnosis, pattern - for audit.
 *
 * The whole module is off until 'mdt_enabled' is set in config.local.php. Super
 * administrators and anyone holding an MDT role can use it before then, to set it up.
 */

const MDT_STATUSES = ['draft', 'submitted', 'info_requested', 'scheduled', 'completed', 'declined', 'withdrawn'];
const MDT_ROLES = ['coordinator', 'panellist'];
const MDT_MAX_FILES = 8;
const MDT_IMAGE_MAX_BYTES = 3 * 1024 * 1024;
const MDT_PDF_MAX_BYTES = 5 * 1024 * 1024;
const MDT_DRAFT_DAYS = 90;

/** The clinical fields, all held in the encrypted content blob, with their limits. */
const MDT_CONTENT_FIELDS = [
    'symptoms' => 2000, 'history' => 6000, 'exposures' => 2000, 'smoking' => 300, 'comorbidities' => 2000,
    'medications' => 2000, 'ctdFeatures' => 2000, 'familyHistory' => 1000, 'examination' => 2000,
    'pft' => 2000, 'sixMwt' => 600, 'labs' => 3000, 'echo' => 1000, 'bronchoscopy' => 2000, 'biopsy' => 2000,
    'otherInvestigations' => 2000, 'hrctDate' => 10, 'hrctFindings' => 4000, 'pacsLink' => 500,
    'managementSoFar' => 3000, 'question' => 2000,
];

/** Fields that must be filled in before a case can be submitted. */
const MDT_REQUIRED = [
    'ageYears' => 'the patient\'s age', 'sex' => 'the patient\'s sex', 'county' => 'the county',
    'clinicianName' => 'your name', 'clinicianCouncil' => 'your regulatory council',
    'clinicianRegNo' => 'your registration number', 'facility' => 'your facility',
    'history' => 'the clinical history', 'hrctFindings' => 'the HRCT findings', 'question' => 'the question for the panel',
];

const MDT_CONFIDENCE = [
    'confident' => 'Confident diagnosis',
    'provisional_high' => 'Provisional diagnosis, high confidence',
    'provisional_low' => 'Provisional diagnosis, low confidence',
    'unclassifiable' => 'Unclassifiable ILD',
];
const MDT_PATTERNS = [
    'uip' => 'UIP', 'probable_uip' => 'Probable UIP', 'indeterminate' => 'Indeterminate for UIP',
    'alternative' => 'Alternative diagnosis', 'not_applicable' => 'Not applicable',
];
const MDT_REC_FIELDS = ['summary' => 6000, 'investigations' => 3000, 'treatment' => 3000, 'followUp' => 2000, 'panel' => 1000];

// ---------------------------------------------------------------------------------------
// Tables
// ---------------------------------------------------------------------------------------

function mdtEnsureTables(PDO $pdo): bool
{
    static $ready = null;
    if ($ready !== null) return $ready;
    try {
        foreach (mdtSchema() as $sql) $pdo->exec($sql);
        return $ready = true;
    } catch (Throwable $e) {
        error_log('MDT tables unavailable: ' . $e->getMessage());
        return $ready = false;
    }
}

/**
 * Kept here so the module creates its own tables on first use. The same statements are in
 * server/schema-mdt.sql for phpMyAdmin - keep the two in step.
 *
 * @return string[]
 */
function mdtSchema(): array
{
    return [
        "CREATE TABLE IF NOT EXISTS mdt_roles (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id INT UNSIGNED NOT NULL,
            role VARCHAR(20) NOT NULL,
            granted_by INT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY mdt_roles_user_role (user_id, role)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS mdt_meetings (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            title VARCHAR(160) NOT NULL,
            meets_at DATETIME NOT NULL,
            venue VARCHAR(160) NULL,
            chair_name VARCHAR(160) NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'planned',
            created_by INT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY mdt_meetings_when (meets_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS mdt_cases (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            reference VARCHAR(20) NULL,
            submitter_user_id INT UNSIGNED NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'draft',
            urgency VARCHAR(10) NOT NULL DEFAULT 'routine',
            preferred_meeting_id INT UNSIGNED NULL,
            meeting_id INT UNSIGNED NULL,
            age_years SMALLINT UNSIGNED NULL,
            sex VARCHAR(10) NULL,
            county VARCHAR(60) NULL,
            clinician_name VARCHAR(160) NULL,
            clinician_council VARCHAR(80) NULL,
            clinician_reg_no VARCHAR(40) NULL,
            facility VARCHAR(200) NULL,
            consent_basis VARCHAR(20) NULL,
            consent_confirmed TINYINT(1) NOT NULL DEFAULT 0,
            no_identifiers_confirmed TINYINT(1) NOT NULL DEFAULT 0,
            content MEDIUMTEXT NULL,
            decline_reason TEXT NULL,
            submitted_at DATETIME NULL,
            closed_at DATETIME NULL,
            purge_after DATE NULL,
            purged_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY mdt_cases_reference (reference),
            KEY mdt_cases_submitter (submitter_user_id),
            KEY mdt_cases_status (status),
            KEY mdt_cases_meeting (meeting_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS mdt_files (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            case_id INT UNSIGNED NOT NULL,
            kind VARCHAR(10) NOT NULL,
            file VARCHAR(80) NOT NULL,
            mime VARCHAR(40) NOT NULL,
            bytes INT UNSIGNED NOT NULL,
            caption VARCHAR(300) NULL,
            uploaded_by INT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY mdt_files_case (case_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS mdt_messages (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            case_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            kind VARCHAR(20) NOT NULL,
            body MEDIUMTEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY mdt_messages_case (case_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS mdt_recommendations (
            case_id INT UNSIGNED NOT NULL,
            diagnosis VARCHAR(200) NULL,
            confidence VARCHAR(20) NULL,
            pattern VARCHAR(20) NULL,
            content MEDIUMTEXT NULL,
            chair_name VARCHAR(160) NULL,
            issued_by INT UNSIGNED NULL,
            issued_at DATETIME NULL,
            updated_by INT UNSIGNED NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (case_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS mdt_audit (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            case_id INT UNSIGNED NULL,
            user_id INT UNSIGNED NULL,
            action VARCHAR(40) NOT NULL,
            detail TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY mdt_audit_case (case_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ];
}

// ---------------------------------------------------------------------------------------
// Access
// ---------------------------------------------------------------------------------------

function mdtEnabled(array $config): bool
{
    return filter_var($config['mdt_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
}

function mdtRetentionMonths(array $config): int
{
    return max(1, min(120, (int)($config['mdt_retention_months'] ?? 24)));
}

/** @return array{coordinator: bool, panellist: bool, superAdmin: bool, staff: bool} */
function mdtAccess(PDO $pdo, array $user, array $config): array
{
    $stmt = $pdo->prepare('SELECT role FROM mdt_roles WHERE user_id = ?');
    $stmt->execute([(int)($user['userId'] ?? 0)]);
    $roles = array_column($stmt->fetchAll(), 'role');
    $super = isSuperAdmin($user, $config);
    $coordinator = $super || in_array('coordinator', $roles, true);
    $panellist = $coordinator || in_array('panellist', $roles, true);
    return ['coordinator' => $coordinator, 'panellist' => $panellist, 'superAdmin' => $super, 'staff' => $panellist];
}

/** Whether a staff member may open this case. The submitter is handled separately. */
function mdtStaffSees(array $access, array $case): bool
{
    if ($case['status'] === 'draft') return false;
    if ($access['coordinator']) return true;
    return $access['panellist'] && in_array($case['status'], ['scheduled', 'completed'], true);
}

function mdtAudit(PDO $pdo, ?int $caseId, ?int $userId, string $action, ?array $detail = null): void
{
    $pdo->prepare('INSERT INTO mdt_audit (case_id, user_id, action, detail) VALUES (?, ?, ?, ?)')
        ->execute([$caseId, $userId, $action, $detail !== null ? json_encode($detail, JSON_UNESCAPED_UNICODE) : null]);
}

/** A staff view, logged - but not again for the same person within ten minutes. */
function mdtLogView(PDO $pdo, int $caseId, int $userId): void
{
    $stmt = $pdo->prepare("SELECT 1 FROM mdt_audit WHERE case_id = ? AND user_id = ? AND action = 'viewed'
                           AND created_at > (NOW() - INTERVAL 10 MINUTE) LIMIT 1");
    $stmt->execute([$caseId, $userId]);
    if (!$stmt->fetch()) mdtAudit($pdo, $caseId, $userId, 'viewed');
}

// ---------------------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------------------

function mdtIso(?string $value): ?string
{
    if (!$value) return null;
    try {
        return (new DateTimeImmutable($value, new DateTimeZone('Africa/Nairobi')))->format(DATE_ATOM);
    } catch (Throwable $e) {
        return null;
    }
}

function mdtText($value, int $max): string
{
    $text = str_replace(["\r\n", "\r"], "\n", (string)$value);
    $text = (string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);
    return mb_substr(trim($text), 0, $max);
}

function mdtJsonEncrypt(array $config, array $data): string
{
    return (string)cryptoEncrypt($config, json_encode($data, JSON_UNESCAPED_UNICODE));
}

function mdtJsonDecrypt(array $config, ?string $stored): array
{
    if ($stored === null || $stored === '') return [];
    $plain = cryptoDecrypt($config, $stored);
    $data = $plain !== null ? json_decode($plain, true) : null;
    return is_array($data) ? $data : [];
}

/**
 * What in a piece of free text looks like a direct identifier. Deliberately narrow: a
 * Kenyan phone number, an ID-length run of digits, or an email address. Lab values and
 * dates do not take these shapes, so a match is worth stopping for.
 *
 * @return string[] plain-language descriptions of what was found
 */
function mdtIdentifierFindings(string $text): array
{
    $found = [];
    if (preg_match('/(?<!\d)(?:\+?254|0)\s?[17]\d{2}[\s-]?\d{3}[\s-]?\d{3}(?!\d)/', $text)) $found[] = 'a phone number';
    if (preg_match('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', $text)) $found[] = 'an email address';
    if (preg_match('/(?<!\d)(?<!\d[.,\/-])\d{7,8}(?!\d)(?![.,\/-]\d)/', $text)) $found[] = 'a 7 or 8 digit number (like an ID or hospital number)';
    return $found;
}

function mdtNextReference(PDO $pdo): string
{
    $year = date('Y');
    $stmt = $pdo->prepare("SELECT reference FROM mdt_cases WHERE reference LIKE ? ORDER BY reference DESC LIMIT 1");
    $stmt->execute(["MDT-{$year}-%"]);
    $last = (string)($stmt->fetchColumn() ?: '');
    $n = $last !== '' ? (int)substr($last, -4) + 1 : 1;
    return sprintf('MDT-%s-%04d', $year, $n);
}

function mdtCaseRow(PDO $pdo, int $caseId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM mdt_cases WHERE id = ? LIMIT 1');
    $stmt->execute([$caseId]);
    return $stmt->fetch() ?: null;
}

function mdtMeetingRow(PDO $pdo, int $meetingId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM mdt_meetings WHERE id = ? LIMIT 1');
    $stmt->execute([$meetingId]);
    return $stmt->fetch() ?: null;
}

function mdtMeetingShape(?array $m): ?array
{
    if (!$m) return null;
    return [
        'id' => (int)$m['id'], 'title' => $m['title'], 'meetsAt' => mdtIso($m['meets_at']), 'meetsAtLocal' => $m['meets_at'],
        'venue' => $m['venue'], 'chairName' => $m['chair_name'], 'status' => $m['status'],
    ];
}

function mdtMeetings(PDO $pdo, bool $upcomingOnly): array
{
    $sql = 'SELECT m.*, (SELECT COUNT(*) FROM mdt_cases c WHERE c.meeting_id = m.id) AS cases FROM mdt_meetings m';
    if ($upcomingOnly) $sql .= " WHERE m.status = 'planned' AND m.meets_at >= NOW()";
    $sql .= $upcomingOnly ? ' ORDER BY m.meets_at' : ' ORDER BY m.meets_at DESC';
    return array_map(static fn($m) => mdtMeetingShape($m) + ['cases' => (int)$m['cases']], $pdo->query($sql)->fetchAll());
}

/** The case list a page shows: never any clinical content, so nothing is decrypted. */
function mdtCaseSummary(array $c, ?array $meeting = null): array
{
    return [
        'id' => (int)$c['id'], 'reference' => $c['reference'], 'status' => $c['status'], 'urgency' => $c['urgency'],
        'ageYears' => $c['age_years'] !== null ? (int)$c['age_years'] : null, 'sex' => $c['sex'], 'county' => $c['county'],
        'clinicianName' => $c['clinician_name'], 'facility' => $c['facility'],
        'submittedAt' => mdtIso($c['submitted_at']), 'updatedAt' => mdtIso($c['updated_at']),
        'meeting' => mdtMeetingShape($meeting), 'purged' => $c['purged_at'] !== null,
    ];
}

function mdtFiles(PDO $pdo, int $caseId): array
{
    $stmt = $pdo->prepare('SELECT * FROM mdt_files WHERE case_id = ? ORDER BY id');
    $stmt->execute([$caseId]);
    return array_map(static fn($f) => [
        'id' => (int)$f['id'], 'kind' => $f['kind'], 'mime' => $f['mime'], 'bytes' => (int)$f['bytes'],
        'caption' => (string)$f['caption'], 'url' => 'api/index.php?route=mdt/files/' . (int)$f['id'],
    ], $stmt->fetchAll());
}

function mdtMessages(PDO $pdo, array $config, int $caseId, bool $includeNotes): array
{
    $stmt = $pdo->prepare('SELECT m.*, u.email FROM mdt_messages m LEFT JOIN users u ON u.id = m.user_id WHERE m.case_id = ? ORDER BY m.id');
    $stmt->execute([$caseId]);
    $out = [];
    foreach ($stmt->fetchAll() as $m) {
        if ($m['kind'] === 'note' && !$includeNotes) continue;
        $out[] = ['id' => (int)$m['id'], 'kind' => $m['kind'], 'from' => $m['email'],
                  'body' => (string)cryptoDecrypt($config, $m['body']), 'at' => mdtIso($m['created_at'])];
    }
    return $out;
}

function mdtRecommendation(PDO $pdo, array $config, int $caseId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM mdt_recommendations WHERE case_id = ? LIMIT 1');
    $stmt->execute([$caseId]);
    $r = $stmt->fetch();
    if (!$r) return null;
    return [
        'diagnosis' => $r['diagnosis'], 'confidence' => $r['confidence'],
        'confidenceLabel' => MDT_CONFIDENCE[$r['confidence']] ?? null,
        'pattern' => $r['pattern'], 'patternLabel' => MDT_PATTERNS[$r['pattern']] ?? null,
        'chairName' => $r['chair_name'], 'issuedAt' => mdtIso($r['issued_at']), 'issued' => $r['issued_at'] !== null,
    ] + array_merge(array_fill_keys(array_keys(MDT_REC_FIELDS), ''), mdtJsonDecrypt($config, $r['content']));
}

/** The whole case, decrypted, for someone already allowed to see it. */
function mdtCaseFull(PDO $pdo, array $config, array $c, bool $staff): array
{
    $meeting = $c['meeting_id'] ? mdtMeetingRow($pdo, (int)$c['meeting_id']) : null;
    $preferred = $c['preferred_meeting_id'] ? mdtMeetingRow($pdo, (int)$c['preferred_meeting_id']) : null;
    $content = array_merge(array_fill_keys(array_keys(MDT_CONTENT_FIELDS), ''), mdtJsonDecrypt($config, $c['content']));
    $rec = mdtRecommendation($pdo, $config, (int)$c['id']);
    if (!$staff && $rec && !$rec['issued']) $rec = null;   // a draft recommendation is the panel's, not yet the clinician's
    return mdtCaseSummary($c, $meeting) + [
        'preferredMeeting' => mdtMeetingShape($preferred),
        'clinicianCouncil' => $c['clinician_council'], 'clinicianRegNo' => $c['clinician_reg_no'],
        'consentBasis' => $c['consent_basis'], 'consentConfirmed' => (bool)$c['consent_confirmed'],
        'noIdentifiersConfirmed' => (bool)$c['no_identifiers_confirmed'],
        'declineReason' => $c['decline_reason'],
        'content' => $content,
        'files' => mdtFiles($pdo, (int)$c['id']),
        'messages' => mdtMessages($pdo, $config, (int)$c['id'], $staff),
        'recommendation' => $rec,
        'editable' => in_array($c['status'], ['draft', 'info_requested'], true) && $c['purged_at'] === null,
        'purgeAfter' => $c['purge_after'],
    ];
}

// ---------------------------------------------------------------------------------------
// Cases
// ---------------------------------------------------------------------------------------

function mdtCreateDraft(PDO $pdo, int $userId): array
{
    // Prefilled from the clinician's last case: their name and registration rarely change.
    $last = $pdo->prepare('SELECT clinician_name, clinician_council, clinician_reg_no, facility FROM mdt_cases
                           WHERE submitter_user_id = ? ORDER BY id DESC LIMIT 1');
    $last->execute([$userId]);
    $prev = $last->fetch() ?: [];
    $pdo->prepare('INSERT INTO mdt_cases (submitter_user_id, clinician_name, clinician_council, clinician_reg_no, facility)
                   VALUES (?, ?, ?, ?, ?)')
        ->execute([$userId, $prev['clinician_name'] ?? null, $prev['clinician_council'] ?? null,
                   $prev['clinician_reg_no'] ?? null, $prev['facility'] ?? null]);
    $id = (int)$pdo->lastInsertId();
    mdtAudit($pdo, $id, $userId, 'created');
    return mdtCaseRow($pdo, $id);
}

/**
 * Saves what the clinician sent. Lenient: a draft can be half-finished. The strict checks
 * run in mdtSubmit().
 *
 * @return ?string an error, or null when saved
 */
function mdtSave(PDO $pdo, array $config, array $c, array $data, int $userId): ?string
{
    if (!in_array($c['status'], ['draft', 'info_requested'], true)) return 'This case can no longer be edited.';

    // Only what was sent changes: a page saving one section must not blank the others.
    $has = static fn(string $key): bool => array_key_exists($key, $data);
    $age = $has('ageYears') ? $data['ageYears'] : $c['age_years'];
    $age = ($age === null || $age === '') ? null : (int)$age;
    if ($age !== null && ($age < 0 || $age > 120)) return 'Enter the patient\'s age in years (0 to 120).';
    $sex = $has('sex') ? (in_array($data['sex'], ['female', 'male', 'other'], true) ? $data['sex'] : null) : $c['sex'];
    $urgency = $has('urgency') ? (($data['urgency'] ?? '') === 'urgent' ? 'urgent' : 'routine') : $c['urgency'];
    $basis = $has('consentBasis') ? (in_array($data['consentBasis'], ['consent', 'other_lawful'], true) ? $data['consentBasis'] : null) : $c['consent_basis'];
    $consent = $has('consentConfirmed') ? (!empty($data['consentConfirmed']) ? 1 : 0) : (int)$c['consent_confirmed'];
    $noIds = $has('noIdentifiersConfirmed') ? (!empty($data['noIdentifiersConfirmed']) ? 1 : 0) : (int)$c['no_identifiers_confirmed'];

    $preferred = $has('preferredMeetingId') ? ((int)$data['preferredMeetingId'] ?: null) : ($c['preferred_meeting_id'] !== null ? (int)$c['preferred_meeting_id'] : null);
    if ($preferred !== null && $has('preferredMeetingId')) {
        $m = mdtMeetingRow($pdo, $preferred);
        if (!$m || $m['status'] !== 'planned') $preferred = null;
    }
    $plain = static fn(string $key, string $column, int $max) => mdtText($has($key) ? $data[$key] : $c[$column], $max) ?: null;

    $content = mdtJsonDecrypt($config, $c['content']);
    foreach (MDT_CONTENT_FIELDS as $key => $max) {
        if (array_key_exists($key, $data)) $content[$key] = mdtText($data[$key], $max);
    }
    if (($content['hrctDate'] ?? '') !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $content['hrctDate'])) $content['hrctDate'] = '';
    if (($content['pacsLink'] ?? '') !== '' && !preg_match('#^https://#i', $content['pacsLink'])) {
        return 'The imaging link must start with https://';
    }

    $pdo->prepare('UPDATE mdt_cases SET urgency = ?, preferred_meeting_id = ?, age_years = ?, sex = ?, county = ?,
                   clinician_name = ?, clinician_council = ?, clinician_reg_no = ?, facility = ?, consent_basis = ?,
                   consent_confirmed = ?, no_identifiers_confirmed = ?, content = ? WHERE id = ?')
        ->execute([
            $urgency, $preferred, $age, $sex, $plain('county', 'county', 60),
            $plain('clinicianName', 'clinician_name', 160), $plain('clinicianCouncil', 'clinician_council', 80),
            $plain('clinicianRegNo', 'clinician_reg_no', 40), $plain('facility', 'facility', 200),
            $basis, $consent, $noIds,
            mdtJsonEncrypt($config, $content), (int)$c['id'],
        ]);
    return null;
}

/**
 * Everything that stops a case being submitted, in plain language.
 *
 * @return string[]
 */
function mdtSubmitProblems(PDO $pdo, array $config, array $c): array
{
    $content = mdtJsonDecrypt($config, $c['content']);
    $values = [
        'ageYears' => $c['age_years'], 'sex' => $c['sex'], 'county' => $c['county'], 'clinicianName' => $c['clinician_name'],
        'clinicianCouncil' => $c['clinician_council'], 'clinicianRegNo' => $c['clinician_reg_no'], 'facility' => $c['facility'],
    ] + $content;
    $problems = [];
    foreach (MDT_REQUIRED as $key => $label) {
        if (!isset($values[$key]) || trim((string)$values[$key]) === '') $problems[] = 'Add ' . $label . '.';
    }
    if (!$c['consent_basis'] || !(int)$c['consent_confirmed']) {
        $problems[] = 'Confirm the patient\'s consent, or another lawful basis, for sharing the case.';
    }
    if (!(int)$c['no_identifiers_confirmed']) $problems[] = 'Confirm that the case contains no names or other direct identifiers.';

    $text = implode("\n", array_map('strval', array_diff_key($content, ['hrctDate' => 1, 'pacsLink' => 1])));
    foreach (mdtIdentifierFindings($text) as $what) {
        $problems[] = 'The case text appears to contain ' . $what . '. Remove it: the MDT must not receive patient identifiers.';
    }
    $files = $pdo->prepare('SELECT COUNT(*) FROM mdt_files WHERE case_id = ?');
    $files->execute([(int)$c['id']]);
    if ((int)$files->fetchColumn() === 0 && trim((string)($content['pacsLink'] ?? '')) === '') {
        $problems[] = 'Upload at least one key HRCT image or report, or give a link to the images.';
    }
    return $problems;
}

/** @return array{0: ?array, 1: string[]} [the case after submitting, problems] */
function mdtSubmit(PDO $pdo, array $config, array $c, int $userId): array
{
    if (!in_array($c['status'], ['draft', 'info_requested'], true)) return [null, ['This case has already been submitted.']];
    $problems = mdtSubmitProblems($pdo, $config, $c);
    if ($problems) return [null, $problems];

    $first = $c['status'] === 'draft';
    if ($first) {
        // The reference is taken inside a retry loop: two clinicians submitting in the same
        // second would otherwise compute the same next number.
        for ($attempt = 0; $attempt < 5; $attempt++) {
            try {
                $pdo->prepare("UPDATE mdt_cases SET status = 'submitted', reference = ?, submitted_at = NOW() WHERE id = ?")
                    ->execute([mdtNextReference($pdo), (int)$c['id']]);
                break;
            } catch (PDOException $e) {
                if ($attempt === 4 || ($e->errorInfo[1] ?? 0) !== 1062) throw $e;
            }
        }
    } else {
        $pdo->prepare("UPDATE mdt_cases SET status = 'submitted' WHERE id = ?")->execute([(int)$c['id']]);
    }
    mdtAudit($pdo, (int)$c['id'], $userId, $first ? 'submitted' : 'resubmitted');
    $row = mdtCaseRow($pdo, (int)$c['id']);
    mdtNotifyStaff($pdo, $config, $row, $first
        ? 'A new case, ' . $row['reference'] . ($row['urgency'] === 'urgent' ? ' (urgent)' : '') . ', has been submitted to the ILD MDT.'
        : 'Case ' . $row['reference'] . ' has been updated by the clinician in reply to your request.');
    if ($first) {
        mdtNotifyUser($pdo, $config, (int)$row['submitter_user_id'], 'Case ' . $row['reference'] . ' received',
            'Your case has been received by the National ILD MDT as ' . $row['reference'] . '. A coordinator will review it and tell you which meeting it is scheduled for.');
    }
    return [$row, []];
}

function mdtWithdraw(PDO $pdo, array $config, array $c, int $userId, string $reason): ?string
{
    if (!in_array($c['status'], ['draft', 'submitted', 'info_requested', 'scheduled'], true)) return 'This case cannot be withdrawn now.';
    if ($c['status'] === 'draft') {
        mdtDeleteCase($pdo, $config, $c);
        return null;
    }
    $pdo->prepare("UPDATE mdt_cases SET status = 'withdrawn', closed_at = NOW(), purge_after = ? WHERE id = ?")
        ->execute([mdtPurgeDate($config), (int)$c['id']]);
    mdtAudit($pdo, (int)$c['id'], $userId, 'withdrawn', $reason !== '' ? ['reason' => mb_substr($reason, 0, 500)] : null);
    return null;
}

function mdtPurgeDate(array $config): string
{
    return (new DateTimeImmutable('today'))->modify('+' . mdtRetentionMonths($config) . ' months')->format('Y-m-d');
}

/** A draft that never went anywhere is removed outright, files and all. */
function mdtDeleteCase(PDO $pdo, array $config, array $c): void
{
    foreach ($pdo->query('SELECT file FROM mdt_files WHERE case_id = ' . (int)$c['id'])->fetchAll() as $f) {
        @unlink(mdtFileDir($config) . '/' . basename((string)$f['file']));
    }
    foreach (['mdt_files', 'mdt_messages', 'mdt_recommendations'] as $table) {
        $pdo->prepare("DELETE FROM {$table} WHERE case_id = ?")->execute([(int)$c['id']]);
    }
    $pdo->prepare('DELETE FROM mdt_cases WHERE id = ?')->execute([(int)$c['id']]);
    mdtAudit($pdo, (int)$c['id'], null, 'draft_deleted');
}

// ---------------------------------------------------------------------------------------
// Files
// ---------------------------------------------------------------------------------------

function mdtFileDir(array $config): string
{
    return rtrim((string)$config['upload_dir'], '/\\') . '/mdt-files';
}

/** @return array{0: ?array, 1: ?string} [the case's files after the upload, error] */
function mdtFileUpload(PDO $pdo, array $config, array $c, array $file, string $caption, int $userId): array
{
    if (!in_array($c['status'], ['draft', 'info_requested'], true)) return [null, 'Files can only be added while the case is a draft or more information has been requested.'];
    $count = $pdo->prepare('SELECT COUNT(*) FROM mdt_files WHERE case_id = ?');
    $count->execute([(int)$c['id']]);
    if ((int)$count->fetchColumn() >= MDT_MAX_FILES) return [null, 'A case can have at most ' . MDT_MAX_FILES . ' files. Remove one first.'];

    $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) return [null, 'That file is too large.'];
    if ($error !== UPLOAD_ERR_OK || !is_uploaded_file((string)($file['tmp_name'] ?? ''))) return [null, 'The upload did not arrive. Please try again.'];
    $data = (string)file_get_contents((string)$file['tmp_name']);

    $info = @getimagesizefromstring($data);
    if ($info && in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG], true)) {
        if (strlen($data) > MDT_IMAGE_MAX_BYTES) return [null, 'Images can be at most 3 MB. Export a smaller copy.'];
        if (!function_exists('absStripJpeg')) return [null, 'Image uploads are unavailable: lib/abstracts-figures.php is missing on the server.'];
        // Hidden details in an image file - camera owner, a scanner's hospital name, a
        // timestamp - can identify a patient, so they are cut out before anything is stored.
        $clean = $info[2] === IMAGETYPE_JPEG ? absStripJpeg($data) : absStripPng($data);
        if ($clean === null || !@getimagesizefromstring($clean)) return [null, 'That image file looks damaged. Export it again and retry.'];
        [$kind, $mime, $data] = ['image', $info[2] === IMAGETYPE_JPEG ? 'image/jpeg' : 'image/png', $clean];
    } elseif (strncmp($data, '%PDF-', 5) === 0) {
        if (strlen($data) > MDT_PDF_MAX_BYTES) return [null, 'PDF reports can be at most 5 MB.'];
        // A report is a document, not a program. A PDF that carries script or launches
        // something has no business in a clinical upload.
        if (preg_match('#/(JavaScript|JS|Launch|EmbeddedFile|OpenAction)\b#', $data)) {
            return [null, 'That PDF contains active content (scripts or attachments) and cannot be accepted. Print it to a new PDF and upload that.'];
        }
        [$kind, $mime] = ['pdf', 'application/pdf'];
    } else {
        return [null, 'Upload images as JPG or PNG, and reports as PDF.'];
    }

    $dir = mdtFileDir($config);
    if (!is_dir($dir) && !@mkdir($dir, 0750, true)) return [null, 'Files cannot be stored on the server yet. Please tell the secretariat.'];
    $name = 'mdt-' . bin2hex(random_bytes(16)) . '.bin';
    $stored = cryptoEncrypt($config, $data);
    if (file_put_contents($dir . '/' . $name, $stored) === false) return [null, 'The file could not be saved. Please try again.'];

    $pdo->prepare('INSERT INTO mdt_files (case_id, kind, file, mime, bytes, caption, uploaded_by) VALUES (?, ?, ?, ?, ?, ?, ?)')
        ->execute([(int)$c['id'], $kind, $name, $mime, strlen($data), mb_substr(mdtText($caption, 300), 0, 300) ?: null, $userId]);
    mdtAudit($pdo, (int)$c['id'], $userId, 'file_added', ['file' => (int)$pdo->lastInsertId(), 'kind' => $kind]);
    return [mdtFiles($pdo, (int)$c['id']), null];
}

function mdtFileRow(PDO $pdo, int $fileId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM mdt_files WHERE id = ? LIMIT 1');
    $stmt->execute([$fileId]);
    return $stmt->fetch() ?: null;
}

function mdtFileDelete(PDO $pdo, array $config, array $c, array $f, int $userId): ?string
{
    if (!in_array($c['status'], ['draft', 'info_requested'], true)) return 'Files can no longer be removed from this case.';
    @unlink(mdtFileDir($config) . '/' . basename((string)$f['file']));
    $pdo->prepare('DELETE FROM mdt_files WHERE id = ?')->execute([(int)$f['id']]);
    mdtAudit($pdo, (int)$c['id'], $userId, 'file_removed', ['file' => (int)$f['id']]);
    return null;
}

/**
 * Sends the file and stops. Images display inline in a sandbox; PDFs download, because a
 * sandboxed PDF will not open in most browsers' viewers.
 *
 * @return never
 */
function mdtFileSend(array $config, array $c, array $f): void
{
    $path = mdtFileDir($config) . '/' . basename((string)$f['file']);
    if (!is_file($path)) respond(404, ['error' => 'File not found.']);
    $data = cryptoDecrypt($config, (string)file_get_contents($path));
    if ($data === null) respond(500, ['error' => 'This file could not be decrypted. Check the server\'s data_encryption_key.']);
    $mime = in_array($f['mime'], ['image/jpeg', 'image/png', 'application/pdf'], true) ? $f['mime'] : 'application/octet-stream';
    $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'application/pdf' => 'pdf'][$mime] ?? 'bin';
    $name = preg_replace('/[^A-Za-z0-9-]/', '', (string)($c['reference'] ?: 'mdt-case')) . '-file-' . (int)$f['id'] . '.' . $ext;
    header_remove('Content-Type');
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . strlen($data));
    header('Content-Disposition: ' . ($f['kind'] === 'image' ? 'inline' : 'attachment') . '; filename="' . $name . '"');
    header("Content-Security-Policy: default-src 'none'; sandbox");
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    echo $data;
    exit;
}

// ---------------------------------------------------------------------------------------
// Coordination
// ---------------------------------------------------------------------------------------

/** @return ?string an error, or null when done */
function mdtTriage(PDO $pdo, array $config, array $c, array $data, int $userId): ?string
{
    $action = (string)($data['action'] ?? '');
    if ($action === 'accept') {
        if (!in_array($c['status'], ['submitted', 'scheduled'], true)) return 'Only a submitted case can be scheduled.';
        $meeting = mdtMeetingRow($pdo, (int)($data['meetingId'] ?? 0));
        if (!$meeting || $meeting['status'] !== 'planned') return 'Choose a planned meeting.';
        $pdo->prepare("UPDATE mdt_cases SET status = 'scheduled', meeting_id = ? WHERE id = ?")->execute([(int)$meeting['id'], (int)$c['id']]);
        mdtAudit($pdo, (int)$c['id'], $userId, $c['status'] === 'scheduled' ? 'rescheduled' : 'scheduled', ['meeting' => (int)$meeting['id']]);
        $when = (new DateTimeImmutable($meeting['meets_at']))->format('l j F Y, g:i A');
        mdtNotifyUser($pdo, $config, (int)$c['submitter_user_id'], 'Case ' . $c['reference'] . ' is on the agenda',
            'Case ' . $c['reference'] . ' will be discussed at ' . $meeting['title'] . ' on ' . $when
            . ($meeting['venue'] ? ' (' . $meeting['venue'] . ')' : '') . '. Please be ready to present it.');
        return null;
    }
    if ($action === 'request_info') {
        if (!in_array($c['status'], ['submitted', 'scheduled'], true)) return 'Information can only be requested on a submitted case.';
        $message = mdtText($data['message'] ?? '', 3000);
        if ($message === '') return 'Say what information is needed.';
        $pdo->prepare("UPDATE mdt_cases SET status = 'info_requested', meeting_id = NULL WHERE id = ?")->execute([(int)$c['id']]);
        $pdo->prepare("INSERT INTO mdt_messages (case_id, user_id, kind, body) VALUES (?, ?, 'info_request', ?)")
            ->execute([(int)$c['id'], $userId, cryptoEncrypt($config, $message)]);
        mdtAudit($pdo, (int)$c['id'], $userId, 'info_requested');
        mdtNotifyUser($pdo, $config, (int)$c['submitter_user_id'], 'More information needed for ' . $c['reference'],
            'The MDT coordinator has asked for more information about case ' . $c['reference'] . '. Sign in to read the request, update the case and submit it again.');
        return null;
    }
    if ($action === 'decline') {
        if (!in_array($c['status'], ['submitted', 'info_requested', 'scheduled'], true)) return 'This case cannot be declined now.';
        $reason = mdtText($data['reason'] ?? '', 2000);
        if ($reason === '') return 'Give the clinician a reason.';
        $pdo->prepare("UPDATE mdt_cases SET status = 'declined', decline_reason = ?, meeting_id = NULL, closed_at = NOW(), purge_after = ? WHERE id = ?")
            ->execute([$reason, mdtPurgeDate($config), (int)$c['id']]);
        mdtAudit($pdo, (int)$c['id'], $userId, 'declined');
        mdtNotifyUser($pdo, $config, (int)$c['submitter_user_id'], 'Case ' . $c['reference'] . ' was not accepted',
            'The MDT coordinator was unable to accept case ' . $c['reference'] . ' for discussion. Sign in to read the reason.');
        return null;
    }
    return 'Unknown action.';
}

/** A message on the case: the clinician's reply, or a staff note the clinician never sees. */
function mdtAddMessage(PDO $pdo, array $config, array $c, string $kind, string $body, int $userId): ?string
{
    $body = mdtText($body, 3000);
    if ($body === '') return 'Write a message first.';
    $pdo->prepare('INSERT INTO mdt_messages (case_id, user_id, kind, body) VALUES (?, ?, ?, ?)')
        ->execute([(int)$c['id'], $userId, $kind, cryptoEncrypt($config, $body)]);
    mdtAudit($pdo, (int)$c['id'], $userId, $kind === 'note' ? 'note_added' : 'reply_added');
    return null;
}

/** @return ?string an error, or null when saved */
function mdtSaveRecommendation(PDO $pdo, array $config, array $c, array $data, bool $issue, int $userId): ?string
{
    if (!in_array($c['status'], ['scheduled', 'completed'], true)) return 'A recommendation can only be recorded for a case on a meeting agenda.';
    $confidence = array_key_exists((string)($data['confidence'] ?? ''), MDT_CONFIDENCE) ? $data['confidence'] : null;
    $pattern = array_key_exists((string)($data['pattern'] ?? ''), MDT_PATTERNS) ? $data['pattern'] : null;
    $diagnosis = mdtText($data['diagnosis'] ?? '', 200);
    $content = [];
    foreach (MDT_REC_FIELDS as $key => $max) $content[$key] = mdtText($data[$key] ?? '', $max);

    if ($issue) {
        $missing = [];
        if ($diagnosis === '') $missing[] = 'the working diagnosis';
        if (!$confidence) $missing[] = 'the diagnostic confidence';
        if (!$pattern) $missing[] = 'the radiological pattern';
        if ($content['summary'] === '') $missing[] = 'the panel\'s discussion';
        if ($missing) return 'Before issuing, add ' . implode(', ', $missing) . '.';
    }

    $meeting = $c['meeting_id'] ? mdtMeetingRow($pdo, (int)$c['meeting_id']) : null;
    $existing = mdtRecommendation($pdo, $config, (int)$c['id']);
    $wasIssued = $existing && $existing['issued'];
    $pdo->prepare('INSERT INTO mdt_recommendations (case_id, diagnosis, confidence, pattern, content, chair_name, updated_by, issued_by, issued_at)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                   ON DUPLICATE KEY UPDATE diagnosis = VALUES(diagnosis), confidence = VALUES(confidence), pattern = VALUES(pattern),
                     content = VALUES(content), chair_name = VALUES(chair_name), updated_by = VALUES(updated_by),
                     issued_by = COALESCE(VALUES(issued_by), issued_by), issued_at = COALESCE(VALUES(issued_at), issued_at)')
        ->execute([(int)$c['id'], $diagnosis ?: null, $confidence, $pattern, mdtJsonEncrypt($config, $content),
                   $meeting['chair_name'] ?? null, $userId, $issue ? $userId : null, $issue ? date('Y-m-d H:i:s') : null]);

    if ($issue) {
        $pdo->prepare("UPDATE mdt_cases SET status = 'completed', closed_at = COALESCE(closed_at, NOW()), purge_after = COALESCE(purge_after, ?) WHERE id = ?")
            ->execute([mdtPurgeDate($config), (int)$c['id']]);
        mdtAudit($pdo, (int)$c['id'], $userId, $wasIssued ? 'recommendation_amended' : 'recommendation_issued');
        mdtNotifyUser($pdo, $config, (int)$c['submitter_user_id'],
            ($wasIssued ? 'Amended recommendation for ' : 'MDT recommendation for ') . $c['reference'],
            'The National ILD MDT has ' . ($wasIssued ? 'amended its' : 'issued its') . ' recommendation for case ' . $c['reference']
            . '. Sign in to read it and to print or save the letter.');
    } else {
        mdtAudit($pdo, (int)$c['id'], $userId, 'recommendation_saved');
    }
    return null;
}

/** @return array{0: ?array, 1: ?string} [the meeting, error] */
function mdtMeetingSave(PDO $pdo, ?int $meetingId, array $data, int $userId): array
{
    $title = mdtText($data['title'] ?? '', 160);
    if ($title === '') return [null, 'Give the meeting a title, e.g. National ILD MDT Meeting (2).'];
    $when = str_replace('T', ' ', (string)($data['meetsAt'] ?? ''));
    $at = DateTimeImmutable::createFromFormat('Y-m-d H:i', substr($when, 0, 16), new DateTimeZone('Africa/Nairobi'));
    if (!$at) return [null, 'Give the meeting date and time.'];
    $status = in_array($data['status'] ?? 'planned', ['planned', 'held', 'cancelled'], true) ? ($data['status'] ?? 'planned') : 'planned';
    $values = [$title, $at->format('Y-m-d H:i:00'), mdtText($data['venue'] ?? '', 160) ?: null, mdtText($data['chairName'] ?? '', 160) ?: null, $status];
    if ($meetingId) {
        if (!mdtMeetingRow($pdo, $meetingId)) return [null, 'No such meeting.'];
        $pdo->prepare('UPDATE mdt_meetings SET title = ?, meets_at = ?, venue = ?, chair_name = ?, status = ? WHERE id = ?')
            ->execute([...$values, $meetingId]);
    } else {
        $pdo->prepare('INSERT INTO mdt_meetings (title, meets_at, venue, chair_name, status, created_by) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([...$values, $userId]);
        $meetingId = (int)$pdo->lastInsertId();
    }
    mdtAudit($pdo, null, $userId, 'meeting_saved', ['meeting' => $meetingId, 'status' => $status]);
    return [mdtMeetingShape(mdtMeetingRow($pdo, $meetingId)), null];
}

function mdtRolesList(PDO $pdo): array
{
    return array_map(static fn($r) => ['id' => (int)$r['id'], 'email' => $r['email'], 'role' => $r['role'], 'since' => mdtIso($r['created_at'])],
        $pdo->query('SELECT r.id, r.role, r.created_at, u.email FROM mdt_roles r JOIN users u ON u.id = r.user_id ORDER BY r.role, u.email')->fetchAll());
}

function mdtRoleGrant(PDO $pdo, string $email, string $role, int $by): ?string
{
    if (!in_array($role, MDT_ROLES, true)) return 'Unknown role.';
    $stmt = $pdo->prepare('SELECT id FROM users WHERE LOWER(email) = ? LIMIT 1');
    $stmt->execute([strtolower(trim($email))]);
    $userId = (int)($stmt->fetchColumn() ?: 0);
    if (!$userId) return 'No portal account uses that email. Ask the person to create one first.';
    $pdo->prepare('INSERT IGNORE INTO mdt_roles (user_id, role, granted_by) VALUES (?, ?, ?)')->execute([$userId, $role, $by]);
    mdtAudit($pdo, null, $by, 'role_granted', ['user' => $userId, 'role' => $role]);
    return null;
}

// ---------------------------------------------------------------------------------------
// Email - never any clinical detail
// ---------------------------------------------------------------------------------------

function mdtPortalUrl(array $config, string $page): string
{
    $base = rtrim((string)($config['portal_base_url'] ?? ''), '/') ?: 'https://www.resok.org/resok-portal/public';
    return $base . '/' . $page;
}

function mdtSend(array $config, string $to, string $subject, string $message, string $page): void
{
    if (!class_exists('SimpleMailer') || !function_exists('brandedEmailHtml') || $to === '') return;
    $url = mdtPortalUrl($config, $page);
    $text = $message . "\n\nSign in to the ReSoK portal to view it:\n{$url}\n\nFor patient confidentiality, case details are never sent by email.\n\nNational ILD MDT, Respiratory Society of Kenya";
    $html = brandedEmailHtml($subject,
        '<p style="margin:0 0 14px;font-size:15px;line-height:1.65;">' . htmlspecialchars($message, ENT_QUOTES) . '</p>'
        . '<p style="margin:0;font-size:13px;line-height:1.6;color:#667085;">For patient confidentiality, case details are never sent by email.</p>',
        'Open the case', $url);
    try {
        $mailer = new SimpleMailer($config);
        if (!$mailer->send($to, $subject, $text, [], $html)) error_log('MDT email not sent to ' . $to . ': ' . ($mailer->lastError ?? 'unknown'));
    } catch (Throwable $e) {
        error_log('MDT email failed: ' . $e->getMessage());
    }
}

function mdtNotifyUser(PDO $pdo, array $config, int $userId, string $subject, string $message): void
{
    $stmt = $pdo->prepare('SELECT email FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$userId]);
    mdtSend($config, (string)($stmt->fetchColumn() ?: ''), $subject, $message, 'mdt');
}

/** Coordinators, or the super administrators when no coordinator has been appointed yet. */
function mdtNotifyStaff(PDO $pdo, array $config, array $c, string $message): void
{
    $emails = array_column($pdo->query("SELECT u.email FROM mdt_roles r JOIN users u ON u.id = r.user_id WHERE r.role = 'coordinator'")->fetchAll(), 'email');
    if (!$emails) $emails = (array)($config['super_admins'] ?? []);
    foreach (array_unique(array_map('strtolower', $emails)) as $email) {
        mdtSend($config, $email, 'ILD MDT: ' . $c['reference'], $message, 'mdt-admin');
    }
}

// ---------------------------------------------------------------------------------------
// Retention
// ---------------------------------------------------------------------------------------

/**
 * Purges what is due: clinical text, messages, files and the free text of the
 * recommendation, keeping the anonymous outline (age, sex, county, diagnosis, confidence,
 * pattern, dates) for audit. Abandoned drafts are deleted outright. Safe to run any time.
 *
 * @return array{purged: int, draftsDeleted: int}
 */
function mdtPurgeDue(PDO $pdo, array $config, int $limit = 50): array
{
    $purged = 0;
    $stmt = $pdo->prepare('SELECT * FROM mdt_cases WHERE purge_after IS NOT NULL AND purge_after <= CURDATE() AND purged_at IS NULL LIMIT ' . $limit);
    $stmt->execute();
    foreach ($stmt->fetchAll() as $c) {
        foreach ($pdo->query('SELECT file FROM mdt_files WHERE case_id = ' . (int)$c['id'])->fetchAll() as $f) {
            @unlink(mdtFileDir($config) . '/' . basename((string)$f['file']));
        }
        $pdo->prepare('DELETE FROM mdt_files WHERE case_id = ?')->execute([(int)$c['id']]);
        $pdo->prepare('DELETE FROM mdt_messages WHERE case_id = ?')->execute([(int)$c['id']]);
        $pdo->prepare('UPDATE mdt_recommendations SET content = NULL WHERE case_id = ?')->execute([(int)$c['id']]);
        $pdo->prepare('UPDATE mdt_cases SET content = NULL, decline_reason = NULL, clinician_reg_no = NULL, purged_at = NOW() WHERE id = ?')
            ->execute([(int)$c['id']]);
        mdtAudit($pdo, (int)$c['id'], null, 'purged');
        $purged++;
    }
    $drafts = $pdo->prepare("SELECT * FROM mdt_cases WHERE status = 'draft' AND updated_at < (NOW() - INTERVAL " . MDT_DRAFT_DAYS . " DAY) LIMIT " . $limit);
    $drafts->execute();
    $deleted = 0;
    foreach ($drafts->fetchAll() as $c) {
        mdtDeleteCase($pdo, $config, $c);
        $deleted++;
    }
    return ['purged' => $purged, 'draftsDeleted' => $deleted];
}

function mdtStats(PDO $pdo): array
{
    $count = static fn(string $sql) => array_map(static fn($r) => ['label' => $r['label'] ?? 'Not given', 'count' => (int)$r['n']], $pdo->query($sql)->fetchAll());
    return [
        'byStatus' => $count("SELECT status AS label, COUNT(*) AS n FROM mdt_cases WHERE status <> 'draft' GROUP BY status ORDER BY n DESC"),
        'byPattern' => array_map(static fn($r) => ['label' => MDT_PATTERNS[$r['label']] ?? 'Not recorded', 'count' => $r['count']],
            $count("SELECT r.pattern AS label, COUNT(*) AS n FROM mdt_recommendations r WHERE r.issued_at IS NOT NULL GROUP BY r.pattern ORDER BY n DESC")),
        'byCounty' => $count("SELECT county AS label, COUNT(*) AS n FROM mdt_cases WHERE status <> 'draft' GROUP BY county ORDER BY n DESC"),
    ];
}

// ---------------------------------------------------------------------------------------
// Routes
// ---------------------------------------------------------------------------------------

/**
 * Every mdt/* route. Called from index.php once the route is known to start with "mdt".
 *
 * @return never
 */
function mdtHandle(PDO $pdo, array $config, string $route, string $method): void
{
    if (!mdtEnsureTables($pdo)) {
        respond(503, ['error' => 'The MDT module is unavailable: its tables could not be created. Import resok-portal/server/schema-mdt.sql.']);
    }
    $user = auth($config);
    $userId = (int)$user['userId'];
    $access = mdtAccess($pdo, $user, $config);
    // Before launch, only the people setting it up can reach it - and it answers everyone
    // else the same way as a route that does not exist.
    if (!mdtEnabled($config) && !$access['staff']) respond(404, ['error' => 'Route not found']);

    $requireCoordinator = static function () use ($access): void {
        if (!$access['coordinator']) respond(403, ['error' => 'Only an MDT coordinator can do this.']);
    };
    $ownCase = static function (int $caseId) use ($pdo, $userId): array {
        $c = mdtCaseRow($pdo, $caseId);
        if (!$c || (int)$c['submitter_user_id'] !== $userId) respond(404, ['error' => 'No such case.']);
        return $c;
    };

    // ------------------------------------------------------------- clinician
    if ($route === 'mdt/me' && $method === 'GET') {
        $stmt = $pdo->prepare('SELECT * FROM mdt_cases WHERE submitter_user_id = ? ORDER BY id DESC');
        $stmt->execute([$userId]);
        $cases = [];
        foreach ($stmt->fetchAll() as $c) {
            $cases[] = mdtCaseSummary($c, $c['meeting_id'] ? mdtMeetingRow($pdo, (int)$c['meeting_id']) : null);
        }
        respond(200, [
            'email' => $user['email'] ?? '', 'enabled' => mdtEnabled($config), 'access' => $access,
            'cases' => $cases, 'meetings' => mdtMeetings($pdo, true), 'retentionMonths' => mdtRetentionMonths($config),
        ]);
    }

    if ($route === 'mdt/cases' && $method === 'POST') {
        $drafts = $pdo->prepare("SELECT COUNT(*) FROM mdt_cases WHERE submitter_user_id = ? AND status = 'draft'");
        $drafts->execute([$userId]);
        if ((int)$drafts->fetchColumn() >= 5) respond(400, ['error' => 'You already have 5 unfinished drafts. Finish or delete one first.']);
        $c = mdtCreateDraft($pdo, $userId);
        respond(201, ['case' => mdtCaseFull($pdo, $config, $c, false)]);
    }

    if (preg_match('#^mdt/cases/(\d+)(/[a-z-]+)?$#', $route, $m)) {
        $caseId = (int)$m[1];
        $action = $m[2] ?? '';
        $c = mdtCaseRow($pdo, $caseId);
        if (!$c) respond(404, ['error' => 'No such case.']);
        $isOwner = (int)$c['submitter_user_id'] === $userId;
        $staffSees = mdtStaffSees($access, $c);
        if (!$isOwner && !$staffSees) respond(404, ['error' => 'No such case.']);

        if ($action === '' && $method === 'GET') {
            $staffView = $staffSees && (!$isOwner || isset($_GET['staff']));
            if ($staffView) mdtLogView($pdo, $caseId, $userId);
            $payload = ['case' => mdtCaseFull($pdo, $config, $c, $staffView), 'access' => $access, 'staffView' => $staffView];
            if ($staffView && $access['coordinator']) {
                $audit = $pdo->prepare('SELECT a.action, a.detail, a.created_at, u.email FROM mdt_audit a LEFT JOIN users u ON u.id = a.user_id
                                        WHERE a.case_id = ? ORDER BY a.id DESC LIMIT 200');
                $audit->execute([$caseId]);
                $payload['audit'] = array_map(static fn($a) => ['action' => $a['action'], 'by' => $a['email'], 'at' => mdtIso($a['created_at']),
                                                                 'detail' => json_decode((string)$a['detail'], true)], $audit->fetchAll());
                $payload['meetings'] = mdtMeetings($pdo, true);
            }
            respond(200, $payload);
        }

        if ($action === '' && $method === 'POST') {
            $c = $ownCase($caseId);
            $error = mdtSave($pdo, $config, $c, input(), $userId);
            if ($error) respond(400, ['error' => $error]);
            $c = mdtCaseRow($pdo, $caseId);
            $content = mdtJsonDecrypt($config, $c['content']);
            $warnings = mdtIdentifierFindings(implode("\n", array_map('strval', array_diff_key($content, ['hrctDate' => 1, 'pacsLink' => 1]))));
            respond(200, ['case' => mdtCaseFull($pdo, $config, $c, false), 'identifierWarnings' => $warnings]);
        }

        if ($action === '/submit' && $method === 'POST') {
            $c = $ownCase($caseId);
            if (!mdtEnabled($config) && !$access['staff']) respond(404, ['error' => 'Route not found']);
            [$row, $problems] = mdtSubmit($pdo, $config, $c, $userId);
            if ($problems) respond(400, ['error' => 'The case is not ready to submit.', 'problems' => $problems]);
            respond(200, ['case' => mdtCaseFull($pdo, $config, $row, false)]);
        }

        if ($action === '/withdraw' && $method === 'POST') {
            $c = $ownCase($caseId);
            $error = mdtWithdraw($pdo, $config, $c, $userId, mdtText(input()['reason'] ?? '', 500));
            if ($error) respond(400, ['error' => $error]);
            $after = mdtCaseRow($pdo, $caseId);
            respond(200, ['case' => $after ? mdtCaseFull($pdo, $config, $after, false) : null, 'deleted' => $after === null]);
        }

        if ($action === '/files' && $method === 'POST') {
            $c = $ownCase($caseId);
            [$files, $error] = mdtFileUpload($pdo, $config, $c, $_FILES['file'] ?? [], (string)($_POST['caption'] ?? ''), $userId);
            if ($error) respond(400, ['error' => $error]);
            respond(201, ['files' => $files]);
        }

        if ($action === '/messages' && $method === 'POST') {
            $data = input();
            // The submitting clinician replies; staff write internal notes. A coordinator who
            // submitted the case themselves replies as its clinician unless they ask for a note.
            if ($isOwner && ($c['status'] === 'info_requested' || !$staffSees) && empty($data['note'])) {
                if ($c['status'] !== 'info_requested') respond(400, ['error' => 'You can reply while the coordinator is waiting for more information.']);
                $error = mdtAddMessage($pdo, $config, $c, 'reply', (string)($data['body'] ?? ''), $userId);
            } elseif ($staffSees) {
                $error = mdtAddMessage($pdo, $config, $c, 'note', (string)($data['body'] ?? ''), $userId);
            } else {
                respond(403, ['error' => 'You cannot add a message to this case.']);
            }
            if ($error) respond(400, ['error' => $error]);
            respond(201, ['messages' => mdtMessages($pdo, $config, $caseId, !$isOwner || $staffSees)]);
        }

        if ($action === '/triage' && $method === 'POST') {
            $requireCoordinator();
            $error = mdtTriage($pdo, $config, $c, input(), $userId);
            if ($error) respond(400, ['error' => $error]);
            respond(200, ['case' => mdtCaseFull($pdo, $config, mdtCaseRow($pdo, $caseId), true)]);
        }

        if ($action === '/recommendation' && $method === 'POST') {
            if (!$staffSees) respond(403, ['error' => 'Only the MDT panel can record a recommendation.']);
            $data = input();
            $error = mdtSaveRecommendation($pdo, $config, $c, $data, !empty($data['issue']), $userId);
            if ($error) respond(400, ['error' => $error]);
            respond(200, ['case' => mdtCaseFull($pdo, $config, mdtCaseRow($pdo, $caseId), true)]);
        }

        // The letter: the clinician's own case, or any case the viewer may see as staff.
        if ($action === '/letter' && $method === 'GET') {
            $full = mdtCaseFull($pdo, $config, $c, false);
            if (!$full['recommendation']) respond(404, ['error' => 'The recommendation for this case has not been issued yet.']);
            if (!$isOwner) mdtLogView($pdo, $caseId, $userId);
            respond(200, ['case' => $full]);
        }
        respond(405, ['error' => 'Method not allowed']);
    }

    if (preg_match('#^mdt/files/(\d+)(/delete)?$#', $route, $m)) {
        $f = mdtFileRow($pdo, (int)$m[1]);
        $c = $f ? mdtCaseRow($pdo, (int)$f['case_id']) : null;
        if (!$f || !$c) respond(404, ['error' => 'File not found.']);
        $isOwner = (int)$c['submitter_user_id'] === $userId;
        if (!$isOwner && !mdtStaffSees($access, $c)) respond(404, ['error' => 'File not found.']);
        if (($m[2] ?? '') === '/delete' && $method === 'POST') {
            if (!$isOwner) respond(403, ['error' => 'Only the submitting clinician can remove a file.']);
            $error = mdtFileDelete($pdo, $config, $c, $f, $userId);
            if ($error) respond(400, ['error' => $error]);
            respond(200, ['files' => mdtFiles($pdo, (int)$c['id'])]);
        }
        if (($m[2] ?? '') === '' && $method === 'GET') {
            if (!$isOwner) mdtAudit($pdo, (int)$c['id'], $userId, 'file_viewed', ['file' => (int)$f['id']]);
            mdtFileSend($config, $c, $f);
        }
        respond(405, ['error' => 'Method not allowed']);
    }

    // ------------------------------------------------------------- staff
    if (!$access['staff']) respond(404, ['error' => 'Route not found']);

    if ($route === 'mdt/admin/cases' && $method === 'GET') {
        mdtPurgeDue($pdo, $config, 10);
        $status = (string)($_GET['status'] ?? '');
        $where = $access['coordinator'] ? "c.status <> 'draft'" : "c.status IN ('scheduled','completed')";
        $params = [];
        if ($status !== '' && in_array($status, MDT_STATUSES, true)) { $where .= ' AND c.status = ?'; $params[] = $status; }
        if (!empty($_GET['meeting'])) { $where .= ' AND c.meeting_id = ?'; $params[] = (int)$_GET['meeting']; }
        $stmt = $pdo->prepare("SELECT c.* FROM mdt_cases c WHERE {$where}
                               ORDER BY FIELD(c.status, 'submitted', 'info_requested', 'scheduled', 'completed', 'declined', 'withdrawn'),
                                        c.urgency = 'urgent' DESC, c.submitted_at");
        $stmt->execute($params);
        $meetings = [];
        $rows = [];
        foreach ($stmt->fetchAll() as $c) {
            $mid = (int)($c['meeting_id'] ?? 0);
            if ($mid && !array_key_exists($mid, $meetings)) $meetings[$mid] = mdtMeetingRow($pdo, $mid);
            $rows[] = mdtCaseSummary($c, $mid ? $meetings[$mid] : null);
        }
        respond(200, ['cases' => $rows, 'access' => $access, 'enabled' => mdtEnabled($config), 'email' => $user['email'] ?? '',
                      'meetings' => mdtMeetings($pdo, false), 'stats' => $access['coordinator'] ? mdtStats($pdo) : null,
                      'retentionMonths' => mdtRetentionMonths($config)]);
    }

    if ($route === 'mdt/meetings' && $method === 'POST') {
        $requireCoordinator();
        $data = input();
        [$meeting, $error] = mdtMeetingSave($pdo, (int)($data['id'] ?? 0) ?: null, $data, $userId);
        if ($error) respond(400, ['error' => $error]);
        respond(200, ['meeting' => $meeting, 'meetings' => mdtMeetings($pdo, false)]);
    }

    if ($route === 'mdt/roles' && $method === 'GET') {
        if (!$access['superAdmin']) respond(403, ['error' => 'Only a super administrator can manage MDT roles.']);
        respond(200, ['roles' => mdtRolesList($pdo)]);
    }
    if ($route === 'mdt/roles' && $method === 'POST') {
        if (!$access['superAdmin']) respond(403, ['error' => 'Only a super administrator can manage MDT roles.']);
        $data = input();
        $error = mdtRoleGrant($pdo, (string)($data['email'] ?? ''), (string)($data['role'] ?? ''), $userId);
        if ($error) respond(400, ['error' => $error]);
        respond(200, ['roles' => mdtRolesList($pdo)]);
    }
    if (preg_match('#^mdt/roles/(\d+)/revoke$#', $route, $m) && $method === 'POST') {
        if (!$access['superAdmin']) respond(403, ['error' => 'Only a super administrator can manage MDT roles.']);
        $pdo->prepare('DELETE FROM mdt_roles WHERE id = ?')->execute([(int)$m[1]]);
        mdtAudit($pdo, null, $userId, 'role_revoked', ['role' => (int)$m[1]]);
        respond(200, ['roles' => mdtRolesList($pdo)]);
    }

    respond(404, ['error' => 'Route not found']);
}
