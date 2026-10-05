<?php
declare(strict_types=1);

/**
 * Abstract submission for the society's yearly conference (KISLHC).
 *
 * Phase 1 of the requirements: setting up an event and its call, the author's account and
 * profile, drafting, submitting, editing until the deadline and withdrawing. Review and
 * decisions build on the same tables later.
 *
 * Three rules run through the file:
 *
 * 1. Deadlines are the event's, in the event's own time zone. Every datetime an
 *    administrator types is stored as wall-clock time in that zone and compared there, so
 *    "closes 30 April 23:59" means 23:59 in Nairobi whatever the server or the author's
 *    laptop thinks the time is.
 *
 * 2. A draft is lenient, a submission is strict. An author saving half an abstract must not
 *    lose it because the keywords are still missing; the full checks run when they submit,
 *    and again on every later edit of a submitted abstract, so it can never drift out of
 *    the event's rules after the fact.
 *
 * 3. Authors only ever reach their own abstracts. Every author route goes through
 *    absOwnAbstract(), which matches the submitter, so an abstract id in a URL is never
 *    enough on its own.
 *
 * An event stays invisible to authors until an administrator publishes it. That is the
 * launch switch: nothing about the call exists for the public before then.
 */

const ABS_STATUSES = ['draft', 'submitted', 'under_review', 'decision_pending', 'accepted',
                      'waitlisted', 'rejected', 'withdrawn', 'confirmed'];

/** Statuses from which an author may withdraw (spec: under review or after acceptance too). */
const ABS_WITHDRAWABLE = ['draft', 'submitted', 'under_review', 'decision_pending', 'accepted',
                          'waitlisted', 'confirmed'];

const ABS_EVENT_ROLES = ['reviewer', 'track_chair', 'programme_chair', 'administrator'];

function abstractsEnsureTables(PDO $pdo): bool
{
    static $ready = null;
    if ($ready !== null) return $ready;
    try {
        foreach (abstractsSchema() as $sql) $pdo->exec($sql);
        abstractsUpgrade($pdo);
        return $ready = true;
    } catch (Throwable $e) {
        error_log('Abstract tables unavailable: ' . $e->getMessage());
        return $ready = false;
    }
}

/**
 * The tables, kept here so the module can create them on first use. The same statements
 * are in server/schema-abstracts.sql for the admin Migrations page and phpMyAdmin - keep
 * the two in step.
 *
 * @return string[]
 */
function abstractsSchema(): array
{
    $tail = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    return [
        "CREATE TABLE IF NOT EXISTS abs_events (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            slug VARCHAR(80) NOT NULL,
            name VARCHAR(200) NOT NULL,
            ref_prefix VARCHAR(20) NOT NULL,
            timezone VARCHAR(64) NOT NULL DEFAULT 'Africa/Nairobi',
            starts_on DATE NULL,
            ends_on DATE NULL,
            venue VARCHAR(200) NULL,
            call_opens_at DATETIME NULL,
            call_closes_at DATETIME NULL,
            review_deadline DATETIME NULL,
            settings TEXT NULL,
            status ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
            created_by INT UNSIGNED NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY abs_events_slug (slug)
        )" . $tail,
        "CREATE TABLE IF NOT EXISTS abs_tracks (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            event_id INT UNSIGNED NOT NULL,
            name VARCHAR(160) NOT NULL,
            description VARCHAR(500) NULL,
            sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            active TINYINT(1) NOT NULL DEFAULT 1,
            PRIMARY KEY (id),
            KEY abs_tracks_event (event_id, sort_order)
        )" . $tail,
        // One per person, carried across years (INT-3). Separate from member_profiles because
        // most authors are not members, and from academy_learners because an affiliation is
        // what a programme needs and a learner record does not hold one.
        "CREATE TABLE IF NOT EXISTS abs_profiles (
            user_id INT UNSIGNED NOT NULL,
            title VARCHAR(30) NULL,
            first_name VARCHAR(80) NOT NULL,
            last_name VARCHAR(80) NOT NULL,
            affiliation VARCHAR(200) NOT NULL,
            country VARCHAR(60) NOT NULL,
            orcid VARCHAR(19) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id)
        )" . $tail,
        "CREATE TABLE IF NOT EXISTS abs_abstracts (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            event_id INT UNSIGNED NOT NULL,
            submitter_user_id INT UNSIGNED NOT NULL,
            reference VARCHAR(40) NULL,
            track_id INT UNSIGNED NULL,
            title VARCHAR(400) NOT NULL DEFAULT '',
            body MEDIUMTEXT NULL,
            sections TEXT NULL,
            keywords TEXT NULL,
            preferred_type VARCHAR(30) NULL,
            final_type VARCHAR(30) NULL,
            status ENUM('draft','submitted','under_review','decision_pending','accepted',
                        'waitlisted','rejected','withdrawn','confirmed') NOT NULL DEFAULT 'draft',
            declarations TEXT NULL,
            reopened_until DATETIME NULL,
            submitted_at DATETIME NULL,
            withdrawn_at DATETIME NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY abs_abstracts_reference (reference),
            KEY abs_abstracts_event (event_id, status),
            KEY abs_abstracts_submitter (submitter_user_id)
        )" . $tail,
        "CREATE TABLE IF NOT EXISTS abs_authors (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            abstract_id INT UNSIGNED NOT NULL,
            sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            first_name VARCHAR(80) NOT NULL,
            last_name VARCHAR(80) NOT NULL,
            email VARCHAR(190) NOT NULL,
            affiliation VARCHAR(200) NOT NULL DEFAULT '',
            country VARCHAR(60) NULL,
            is_presenting TINYINT(1) NOT NULL DEFAULT 0,
            is_corresponding TINYINT(1) NOT NULL DEFAULT 0,
            user_id INT UNSIGNED NULL,
            PRIMARY KEY (id),
            KEY abs_authors_abstract (abstract_id, sort_order),
            KEY abs_authors_email (email)
        )" . $tail,
        // The abstract as it stood before each change made after submission - the edit
        // history the data requirements ask for. Drafts are not versioned: nobody has
        // relied on a draft yet.
        "CREATE TABLE IF NOT EXISTS abs_versions (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            abstract_id INT UNSIGNED NOT NULL,
            snapshot MEDIUMTEXT NOT NULL,
            saved_by INT UNSIGNED NULL,
            saved_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY abs_versions_abstract (abstract_id, saved_at)
        )" . $tail,
        // Roles are per event (a reviewer this year is not one next year until invited).
        // Super administrators are administrators of every event without a row here.
        "CREATE TABLE IF NOT EXISTS abs_roles (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            event_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            role ENUM('reviewer','track_chair','programme_chair','administrator') NOT NULL,
            track_id INT UNSIGNED NULL,
            granted_by INT UNSIGNED NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY abs_roles_unique (event_id, user_id, role, track_id),
            KEY abs_roles_user (user_id)
        )" . $tail,
        "CREATE TABLE IF NOT EXISTS abs_audit (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            event_id INT UNSIGNED NULL,
            abstract_id INT UNSIGNED NULL,
            user_id INT UNSIGNED NULL,
            action VARCHAR(60) NOT NULL,
            from_status VARCHAR(30) NULL,
            to_status VARCHAR(30) NULL,
            detail TEXT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY abs_audit_abstract (abstract_id, created_at),
            KEY abs_audit_event (event_id, created_at)
        )" . $tail,
        // Every email the module sends, and the queue it is sent from: a row is written
        // first and sent when the hourly budget allows (see absMailPump). An administrator
        // can see whether each one reached the mail server, and resend it.
        "CREATE TABLE IF NOT EXISTS abs_emails (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            event_id INT UNSIGNED NULL,
            abstract_id INT UNSIGNED NULL,
            template VARCHAR(40) NOT NULL,
            to_email VARCHAR(190) NOT NULL,
            subject VARCHAR(300) NOT NULL,
            status ENUM('queued','sending','sent','failed') NOT NULL DEFAULT 'queued',
            error VARCHAR(500) NULL,
            text_body MEDIUMTEXT NULL,
            html_body MEDIUMTEXT NULL,
            attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
            dedupe_key VARCHAR(160) NULL,
            sent_at DATETIME NULL,
            next_attempt_at DATETIME NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY abs_emails_dedupe (dedupe_key),
            KEY abs_emails_queue (status, id),
            KEY abs_emails_abstract (abstract_id),
            KEY abs_emails_event (event_id, created_at)
        )" . $tail,
        // Review (phase 2). A reviewer's standing for one event: the tracks they cover,
        // their expertise in their own words, and how many abstracts they will take.
        "CREATE TABLE IF NOT EXISTS abs_reviewers (
            event_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            tracks TEXT NULL,
            expertise VARCHAR(500) NULL,
            max_load SMALLINT UNSIGNED NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (event_id, user_id)
        )" . $tail,
        "CREATE TABLE IF NOT EXISTS abs_invitations (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            event_id INT UNSIGNED NOT NULL,
            email VARCHAR(190) NOT NULL,
            name VARCHAR(160) NULL,
            token_hash CHAR(64) NOT NULL,
            status ENUM('pending','accepted','declined','revoked') NOT NULL DEFAULT 'pending',
            invited_by INT UNSIGNED NULL,
            user_id INT UNSIGNED NULL,
            message VARCHAR(1000) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            responded_at DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY abs_invitations_token (token_hash),
            KEY abs_invitations_event (event_id, email)
        )" . $tail,
        // One row per reviewer per abstract: the assignment and the review in one, so an
        // abstract can never carry two reviews from the same person.
        "CREATE TABLE IF NOT EXISTS abs_reviews (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            abstract_id INT UNSIGNED NOT NULL,
            reviewer_user_id INT UNSIGNED NOT NULL,
            status ENUM('assigned','in_progress','submitted','declined','cancelled') NOT NULL DEFAULT 'assigned',
            scores TEXT NULL,
            total DECIMAL(5,2) NULL,
            comments_authors TEXT NULL,
            comments_committee TEXT NULL,
            recommendation VARCHAR(20) NULL,
            recommended_type VARCHAR(30) NULL,
            decline_reason VARCHAR(500) NULL,
            assigned_by INT UNSIGNED NULL,
            assigned_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            submitted_at DATETIME NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY abs_reviews_pair (abstract_id, reviewer_user_id),
            KEY abs_reviews_reviewer (reviewer_user_id, status)
        )" . $tail,
    ];
}

/**
 * Brings a table created by an earlier version of this file up to date. MySQL has no
 * ADD COLUMN IF NOT EXISTS, so each change is checked first and skipped when present.
 */
function abstractsUpgrade(PDO $pdo): void
{
    $columns = static function (string $table) use ($pdo): array {
        return array_column($pdo->query('SHOW COLUMNS FROM ' . $table)->fetchAll(), 'Type', 'Field');
    };
    $emails = $columns('abs_emails');
    if (strpos((string)($emails['status'] ?? ''), 'queued') === false) {
        $pdo->exec("ALTER TABLE abs_emails MODIFY status ENUM('queued','sending','sent','failed') NOT NULL DEFAULT 'queued'");
    }
    $adds = [
        'text_body' => 'ADD COLUMN text_body MEDIUMTEXT NULL',
        'html_body' => 'ADD COLUMN html_body MEDIUMTEXT NULL',
        'attempts' => 'ADD COLUMN attempts TINYINT UNSIGNED NOT NULL DEFAULT 0',
        'dedupe_key' => 'ADD COLUMN dedupe_key VARCHAR(160) NULL, ADD UNIQUE KEY abs_emails_dedupe (dedupe_key)',
        'sent_at' => 'ADD COLUMN sent_at DATETIME NULL, ADD KEY abs_emails_queue (status, id)',
        'next_attempt_at' => 'ADD COLUMN next_attempt_at DATETIME NULL',
    ];
    foreach ($adds as $column => $sql) {
        if (!array_key_exists($column, $emails)) $pdo->exec('ALTER TABLE abs_emails ' . $sql);
    }
}

// ---------------------------------------------------------------------------------------
// Settings
// ---------------------------------------------------------------------------------------

/** What a new event's submission form and review start from (ADM-2, ADM-3). */
function absDefaultSettings(): array
{
    return [
        'titleWords' => 20,
        'bodyWords' => 300,
        'keywordsMin' => 3,
        'keywordsMax' => 6,
        'structured' => true,
        'sections' => ['Background', 'Methods', 'Results', 'Conclusion'],
        'presentationTypes' => ['oral' => 'Oral presentation', 'poster' => 'Poster', 'either' => 'Either'],
        'maxAuthors' => 20,
        'maxPerSubmitter' => 0,
        'declarations' => [
            ['key' => 'original', 'label' => 'This work is original and has not been published elsewhere.'],
            ['key' => 'consent', 'label' => 'All co-authors have seen this abstract and agree to its submission.'],
            ['key' => 'conflicts', 'label' => 'Any conflicts of interest have been disclosed to the organisers.'],
        ],
        'blind' => 'double',
        'reviewsPerAbstract' => 2,
        // Scored 1..scaleMax each; the total is the weighted mean, on the same scale.
        'criteria' => [
            ['key' => 'originality', 'label' => 'Originality', 'weight' => 1],
            ['key' => 'methods', 'label' => 'Methods', 'weight' => 1],
            ['key' => 'relevance', 'label' => 'Relevance to lung health in the region', 'weight' => 1],
            ['key' => 'clarity', 'label' => 'Clarity', 'weight' => 1],
        ],
        'scaleMax' => 5,
        // Reviews of one abstract further apart than this (on the total) are flagged.
        'discrepancyThreshold' => 1.5,
    ];
}

/** Merges stored settings over the defaults and clamps everything to sane values. */
function absSettings(?string $stored): array
{
    $defaults = absDefaultSettings();
    $s = $stored ? json_decode($stored, true) : null;
    $s = is_array($s) ? array_merge($defaults, $s) : $defaults;

    $int = static fn($v, int $min, int $max, int $fallback) =>
        is_numeric($v) ? max($min, min($max, (int)$v)) : $fallback;
    $s['titleWords'] = $int($s['titleWords'], 5, 60, $defaults['titleWords']);
    $s['bodyWords'] = $int($s['bodyWords'], 50, 2000, $defaults['bodyWords']);
    $s['keywordsMin'] = $int($s['keywordsMin'], 0, 15, $defaults['keywordsMin']);
    $s['keywordsMax'] = $int($s['keywordsMax'], max(1, $s['keywordsMin']), 20, $defaults['keywordsMax']);
    $s['maxAuthors'] = $int($s['maxAuthors'], 1, 50, $defaults['maxAuthors']);
    $s['maxPerSubmitter'] = $int($s['maxPerSubmitter'], 0, 50, 0);
    $s['reviewsPerAbstract'] = $int($s['reviewsPerAbstract'], 1, 5, $defaults['reviewsPerAbstract']);
    $s['structured'] = (bool)$s['structured'];
    $s['blind'] = in_array($s['blind'], ['double', 'single', 'open'], true) ? $s['blind'] : 'double';

    $sections = array_values(array_filter(array_map(
        static fn($x) => mb_substr(trim((string)$x), 0, 40), is_array($s['sections']) ? $s['sections'] : []
    ), static fn($x) => $x !== ''));
    $s['sections'] = $sections ?: $defaults['sections'];

    $types = [];
    foreach (is_array($s['presentationTypes']) ? $s['presentationTypes'] : [] as $key => $label) {
        $key = preg_replace('/[^a-z0-9_]/', '', strtolower((string)$key));
        if ($key !== '' && trim((string)$label) !== '') $types[$key] = mb_substr(trim((string)$label), 0, 60);
    }
    $s['presentationTypes'] = $types ?: $defaults['presentationTypes'];

    $declarations = [];
    foreach (is_array($s['declarations']) ? $s['declarations'] : [] as $i => $d) {
        $label = trim((string)($d['label'] ?? ''));
        if ($label === '') continue;
        $key = preg_replace('/[^a-z0-9_]/', '', strtolower((string)($d['key'] ?? ''))) ?: 'd' . ($i + 1);
        $declarations[] = ['key' => $key, 'label' => mb_substr($label, 0, 300)];
    }
    $s['declarations'] = $declarations;

    $criteria = [];
    foreach (is_array($s['criteria']) ? $s['criteria'] : [] as $i => $c) {
        $label = trim((string)($c['label'] ?? ''));
        if ($label === '') continue;
        $key = preg_replace('/[^a-z0-9_]/', '', strtolower((string)($c['key'] ?? ''))) ?: 'c' . ($i + 1);
        $weight = is_numeric($c['weight'] ?? null) ? max(0.0, min(10.0, (float)$c['weight'])) : 1.0;
        $criteria[] = ['key' => $key, 'label' => mb_substr($label, 0, 120), 'weight' => $weight];
    }
    $s['criteria'] = $criteria ?: $defaults['criteria'];
    $s['scaleMax'] = $int($s['scaleMax'], 3, 10, $defaults['scaleMax']);
    $s['discrepancyThreshold'] = is_numeric($s['discrepancyThreshold'])
        ? max(0.5, min((float)$s['scaleMax'], (float)$s['discrepancyThreshold'])) : $defaults['discrepancyThreshold'];
    return $s;
}

// ---------------------------------------------------------------------------------------
// Time
// ---------------------------------------------------------------------------------------

function absZone(array $eventRow): DateTimeZone
{
    try {
        return new DateTimeZone((string)($eventRow['timezone'] ?: 'Africa/Nairobi'));
    } catch (Throwable $e) {
        return new DateTimeZone('Africa/Nairobi');
    }
}

/** A stored wall-clock value as a moment in the event's zone, or null. */
function absAt(?string $value, DateTimeZone $zone): ?DateTimeImmutable
{
    if ($value === null || $value === '') return null;
    try {
        return new DateTimeImmutable($value, $zone);
    } catch (Throwable $e) {
        return null;
    }
}

/** ISO 8601 with the offset, which is what a browser countdown needs to be right anywhere. */
function absIso(?string $value, DateTimeZone $zone): ?string
{
    $at = absAt($value, $zone);
    return $at ? $at->format(DATE_ATOM) : null;
}

/** Accepts "2027-04-30T23:59" or "2027-04-30 23:59[:00]"; returns a storable value or null. */
function absParseLocal($value): ?string
{
    $value = trim((string)$value);
    if ($value === '') return null;
    $value = str_replace('T', ' ', $value);
    foreach (['Y-m-d H:i:s', 'Y-m-d H:i'] as $format) {
        $d = DateTimeImmutable::createFromFormat('!' . $format, $value);
        if ($d && $d->format($format) === $value) return $d->format('Y-m-d H:i:s');
    }
    return null;
}

/** 'not_open', 'open' or 'closed', decided by the window, not by any stored flag. */
function absCallState(array $eventRow): string
{
    $zone = absZone($eventRow);
    $now = new DateTimeImmutable('now', $zone);
    $opens = absAt($eventRow['call_opens_at'] ?? null, $zone);
    $closes = absAt($eventRow['call_closes_at'] ?? null, $zone);
    if (!$opens || !$closes || $now < $opens) return 'not_open';
    return $now <= $closes ? 'open' : 'closed';
}

// ---------------------------------------------------------------------------------------
// Events
// ---------------------------------------------------------------------------------------

function absTracks(PDO $pdo, int $eventId, bool $activeOnly = true): array
{
    $stmt = $pdo->prepare('SELECT * FROM abs_tracks WHERE event_id = ?' . ($activeOnly ? ' AND active = 1' : '')
                          . ' ORDER BY sort_order, id');
    $stmt->execute([$eventId]);
    return array_map(static fn($t) => [
        'id' => (int)$t['id'],
        'name' => $t['name'],
        'description' => $t['description'],
        'active' => (bool)(int)$t['active'],
    ], $stmt->fetchAll());
}

function absEventShape(PDO $pdo, array $row, bool $forAdmin = false): array
{
    $zone = absZone($row);
    $settings = absSettings($row['settings'] ?? null);
    $shape = [
        'id' => (int)$row['id'],
        'slug' => $row['slug'],
        'name' => $row['name'],
        'timezone' => $zone->getName(),
        'startsOn' => $row['starts_on'],
        'endsOn' => $row['ends_on'],
        'venue' => $row['venue'],
        'callOpensAt' => absIso($row['call_opens_at'], $zone),
        'callClosesAt' => absIso($row['call_closes_at'], $zone),
        'callState' => absCallState($row),
        'status' => $row['status'],
        'tracks' => absTracks($pdo, (int)$row['id'], !$forAdmin),
        'form' => [
            'titleWords' => $settings['titleWords'],
            'bodyWords' => $settings['bodyWords'],
            'keywordsMin' => $settings['keywordsMin'],
            'keywordsMax' => $settings['keywordsMax'],
            'structured' => $settings['structured'],
            'sections' => $settings['sections'],
            'presentationTypes' => $settings['presentationTypes'],
            'maxAuthors' => $settings['maxAuthors'],
            'maxPerSubmitter' => $settings['maxPerSubmitter'],
            'declarations' => $settings['declarations'],
        ],
    ];
    if ($forAdmin) {
        $shape['refPrefix'] = $row['ref_prefix'];
        // The raw wall-clock values, for the admin form's datetime inputs.
        $shape['callOpensLocal'] = $row['call_opens_at'];
        $shape['callClosesLocal'] = $row['call_closes_at'];
        $shape['reviewDeadlineLocal'] = $row['review_deadline'];
        $shape['reviewDeadline'] = absIso($row['review_deadline'], $zone);
        $shape['review'] = ['blind' => $settings['blind'], 'reviewsPerAbstract' => $settings['reviewsPerAbstract'],
                            'criteria' => $settings['criteria'], 'scaleMax' => $settings['scaleMax'],
                            'discrepancyThreshold' => $settings['discrepancyThreshold']];
    }
    return $shape;
}

function absEventRow(PDO $pdo, int $eventId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM abs_events WHERE id = ? LIMIT 1');
    $stmt->execute([$eventId]);
    return $stmt->fetch() ?: null;
}

/**
 * The event authors are working with: the most recent published one. With $includeDraft a
 * draft counts too - for a super administrator previewing the call before launch.
 */
function absCurrentEventRow(PDO $pdo, bool $includeDraft = false): ?array
{
    $statuses = $includeDraft ? "'published','draft'" : "'published'";
    $row = $pdo->query("SELECT * FROM abs_events WHERE status IN ($statuses)
                        ORDER BY (status = 'published') DESC, COALESCE(call_closes_at, created_at) DESC, id DESC LIMIT 1")
               ->fetch();
    return $row ?: null;
}

function absEventsList(PDO $pdo): array
{
    $rows = $pdo->query('SELECT e.*, (SELECT COUNT(*) FROM abs_abstracts a WHERE a.event_id = e.id) AS abstract_count,
                                (SELECT COUNT(*) FROM abs_abstracts a WHERE a.event_id = e.id AND a.status <> "draft" AND a.status <> "withdrawn") AS submitted_count
                         FROM abs_events e ORDER BY e.created_at DESC, e.id DESC')->fetchAll();
    return array_map(static function ($r) use ($pdo) {
        $shape = absEventShape($pdo, $r, true);
        $shape['abstractCount'] = (int)$r['abstract_count'];
        $shape['submittedCount'] = (int)$r['submitted_count'];
        return $shape;
    }, $rows);
}

function absSlug(PDO $pdo, string $name, ?int $exceptId = null): string
{
    $base = trim((string)preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-') ?: 'event';
    $base = substr($base, 0, 70);
    for ($i = 1; $i < 100; $i++) {
        $candidate = $i === 1 ? $base : $base . '-' . $i;
        $stmt = $pdo->prepare('SELECT id FROM abs_events WHERE slug = ? AND id <> ? LIMIT 1');
        $stmt->execute([$candidate, (int)$exceptId]);
        if (!$stmt->fetch()) return $candidate;
    }
    return $base . '-' . bin2hex(random_bytes(3));
}

/**
 * Creates or updates an event (ADM-1..3). With copyFromEventId on a create, the other
 * event's settings and tracks are the starting point (ADM-10) and anything sent overrides.
 *
 * @return array{0: ?array, 1: ?string} [event, error]
 */
function absEventSave(PDO $pdo, ?int $eventId, array $data, ?int $userId): array
{
    $existing = $eventId ? absEventRow($pdo, $eventId) : null;
    if ($eventId && !$existing) return [null, 'No such event.'];

    $source = null;
    if (!$existing && !empty($data['copyFromEventId'])) {
        $source = absEventRow($pdo, (int)$data['copyFromEventId']);
        if (!$source) return [null, 'The event to copy from was not found.'];
    }

    $name = trim((string)($data['name'] ?? ($existing['name'] ?? '')));
    if ($name === '' || mb_strlen($name) > 200) return [null, 'Give the event a name (up to 200 characters).'];

    $prefix = strtoupper(trim((string)($data['refPrefix'] ?? ($existing['ref_prefix'] ?? ''))));
    if (!preg_match('/^[A-Z0-9][A-Z0-9-]{1,18}$/', $prefix)) {
        return [null, 'The reference prefix must be 2-19 letters, digits or dashes, for example KISLHC27.'];
    }
    $clash = $pdo->prepare('SELECT id FROM abs_events WHERE ref_prefix = ? AND id <> ? LIMIT 1');
    $clash->execute([$prefix, (int)$eventId]);
    if ($clash->fetch()) return [null, 'Another event already uses that reference prefix.'];

    $timezone = trim((string)($data['timezone'] ?? ($existing['timezone'] ?? 'Africa/Nairobi')));
    if (!in_array($timezone, DateTimeZone::listIdentifiers(), true)) return [null, 'Unknown time zone.'];

    $dates = [];
    foreach (['startsOn' => 'starts_on', 'endsOn' => 'ends_on'] as $key => $col) {
        $value = array_key_exists($key, $data) ? trim((string)$data[$key]) : (string)($existing[$col] ?? '');
        if ($value !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) return [null, 'Conference dates must be YYYY-MM-DD.'];
        $dates[$col] = $value !== '' ? $value : null;
    }
    $moments = [];
    foreach (['callOpensAt' => 'call_opens_at', 'callClosesAt' => 'call_closes_at', 'reviewDeadline' => 'review_deadline'] as $key => $col) {
        if (array_key_exists($key, $data)) {
            $raw = trim((string)$data[$key]);
            $parsed = absParseLocal($raw);
            if ($raw !== '' && $parsed === null) return [null, 'Dates and times must look like 2027-04-30 23:59.'];
            $moments[$col] = $parsed;
        } else {
            $moments[$col] = $existing[$col] ?? null;
        }
    }
    if ($moments['call_opens_at'] && $moments['call_closes_at'] && $moments['call_closes_at'] <= $moments['call_opens_at']) {
        return [null, 'The call must close after it opens.'];
    }

    $status = (string)($data['status'] ?? ($existing['status'] ?? 'draft'));
    if (!in_array($status, ['draft', 'published', 'archived'], true)) return [null, 'Unknown event status.'];
    if ($status === 'published' && (!$moments['call_opens_at'] || !$moments['call_closes_at'])) {
        return [null, 'Set when the call opens and closes before publishing it.'];
    }

    $baseSettings = absSettings($existing['settings'] ?? ($source['settings'] ?? null));
    $incoming = [];
    if (isset($data['form']) && is_array($data['form'])) $incoming += $data['form'];
    if (isset($data['review']) && is_array($data['review'])) $incoming += $data['review'];
    $settings = absSettings(json_encode(array_merge($baseSettings, $incoming)));

    $venue = trim((string)($data['venue'] ?? ($existing['venue'] ?? '')));

    $pdo->beginTransaction();
    try {
        $values = [$name, $prefix, $timezone, $dates['starts_on'], $dates['ends_on'], $venue !== '' ? mb_substr($venue, 0, 200) : null,
                   $moments['call_opens_at'], $moments['call_closes_at'], $moments['review_deadline'],
                   json_encode($settings), $status];
        if ($existing) {
            $pdo->prepare('UPDATE abs_events SET name = ?, ref_prefix = ?, timezone = ?, starts_on = ?, ends_on = ?, venue = ?,
                                  call_opens_at = ?, call_closes_at = ?, review_deadline = ?, settings = ?, status = ? WHERE id = ?')
                ->execute(array_merge($values, [$eventId]));
        } else {
            $pdo->prepare('INSERT INTO abs_events (name, ref_prefix, timezone, starts_on, ends_on, venue, call_opens_at, call_closes_at,
                                                   review_deadline, settings, status, slug, created_by)
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute(array_merge($values, [absSlug($pdo, $name), $userId]));
            $eventId = (int)$pdo->lastInsertId();
        }

        if (isset($data['tracks']) && is_array($data['tracks'])) {
            $error = absSyncTracks($pdo, $eventId, $data['tracks']);
            if ($error) { $pdo->rollBack(); return [null, $error]; }
        } elseif ($source) {
            foreach (absTracks($pdo, (int)$source['id']) as $i => $t) {
                $pdo->prepare('INSERT INTO abs_tracks (event_id, name, description, sort_order) VALUES (?, ?, ?, ?)')
                    ->execute([$eventId, $t['name'], $t['description'], $i]);
            }
        }

        absAudit($pdo, $eventId, null, $userId, $existing ? 'event_updated' : 'event_created', null, null,
                 $source ? ['copiedFrom' => (int)$source['id']] : ($existing && $existing['status'] !== $status
                     ? ['status' => [$existing['status'], $status]] : null));
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return [absEventShape($pdo, absEventRow($pdo, $eventId), true), null];
}

/**
 * Brings the event's tracks in line with the list sent. Tracks are never deleted, because
 * abstracts point at them: one left out of the list is switched off instead, and comes
 * back if it is sent again.
 */
function absSyncTracks(PDO $pdo, int $eventId, array $tracks): ?string
{
    $keep = [];
    $order = 0;
    foreach ($tracks as $t) {
        $name = trim((string)($t['name'] ?? ''));
        if ($name === '') continue;
        if (mb_strlen($name) > 160) return 'Track names can be up to 160 characters.';
        $description = trim((string)($t['description'] ?? ''));
        $description = $description !== '' ? mb_substr($description, 0, 500) : null;
        $id = (int)($t['id'] ?? 0);
        if ($id) {
            $stmt = $pdo->prepare('UPDATE abs_tracks SET name = ?, description = ?, sort_order = ?, active = 1 WHERE id = ? AND event_id = ?');
            $stmt->execute([$name, $description, $order, $id, $eventId]);
            if ($stmt->rowCount() === 0) {
                $check = $pdo->prepare('SELECT id FROM abs_tracks WHERE id = ? AND event_id = ?');
                $check->execute([$id, $eventId]);
                if (!$check->fetch()) return 'A track in the list does not belong to this event.';
            }
        } else {
            $pdo->prepare('INSERT INTO abs_tracks (event_id, name, description, sort_order) VALUES (?, ?, ?, ?)')
                ->execute([$eventId, $name, $description, $order]);
            $id = (int)$pdo->lastInsertId();
        }
        $keep[] = $id;
        $order++;
    }
    if (!$keep) return 'An event needs at least one track.';
    $placeholders = implode(',', array_fill(0, count($keep), '?'));
    $pdo->prepare("UPDATE abs_tracks SET active = 0 WHERE event_id = ? AND id NOT IN ($placeholders)")
        ->execute(array_merge([$eventId], $keep));
    return null;
}

// ---------------------------------------------------------------------------------------
// Roles
// ---------------------------------------------------------------------------------------

/** Super administrators administer every event; others need an administrator row for it. */
function absIsEventAdmin(PDO $pdo, array $user, array $config, ?int $eventId): bool
{
    if (function_exists('isSuperAdmin') && isSuperAdmin($user, $config)) return true;
    if (!$eventId) return false;
    $stmt = $pdo->prepare("SELECT 1 FROM abs_roles WHERE event_id = ? AND user_id = ? AND role = 'administrator' LIMIT 1");
    $stmt->execute([$eventId, (int)($user['userId'] ?? 0)]);
    return (bool)$stmt->fetch();
}

// ---------------------------------------------------------------------------------------
// Profiles
// ---------------------------------------------------------------------------------------

/**
 * The author's profile. A member or learner who has never opened the abstracts page gets
 * one prefilled from what the portal already knows, so they confirm rather than retype it.
 */
function absProfile(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare('SELECT * FROM abs_profiles WHERE user_id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    if ($row) {
        return ['complete' => true, 'title' => $row['title'], 'firstName' => $row['first_name'],
                'lastName' => $row['last_name'], 'affiliation' => $row['affiliation'],
                'country' => $row['country'], 'orcid' => $row['orcid']];
    }
    $prefill = ['complete' => false, 'title' => null, 'firstName' => '', 'lastName' => '',
                'affiliation' => '', 'country' => 'Kenya', 'orcid' => null];
    try {
        $m = $pdo->prepare('SELECT title, first_name, surname, institution, country FROM member_profiles WHERE user_id = ? LIMIT 1');
        $m->execute([$userId]);
        if ($member = $m->fetch()) {
            return array_merge($prefill, ['title' => $member['title'], 'firstName' => (string)$member['first_name'],
                'lastName' => (string)$member['surname'], 'affiliation' => (string)($member['institution'] ?? ''),
                'country' => (string)($member['country'] ?: 'Kenya')]);
        }
        $l = $pdo->prepare('SELECT display_name, country FROM academy_learners WHERE user_id = ? LIMIT 1');
        $l->execute([$userId]);
        if ($learner = $l->fetch()) {
            $parts = preg_split('/\s+/u', trim((string)$learner['display_name']), 2) ?: [''];
            return array_merge($prefill, ['firstName' => $parts[0] ?? '', 'lastName' => $parts[1] ?? '',
                'country' => (string)($learner['country'] ?: 'Kenya')]);
        }
    } catch (Throwable $e) {
        // A database without the membership or academy tables simply has nothing to prefill.
    }
    return $prefill;
}

/** @return ?string an error, or null on success */
function absProfileSave(PDO $pdo, int $userId, array $data): ?string
{
    $clean = [];
    foreach (['firstName' => 80, 'lastName' => 80, 'affiliation' => 200, 'country' => 60] as $key => $max) {
        $value = trim((string)preg_replace('/\s+/u', ' ', (string)($data[$key] ?? '')));
        if ($value === '') return 'Please fill in your first name, last name, affiliation and country.';
        if (mb_strlen($value) > $max) return 'One of your profile fields is too long.';
        $clean[$key] = $value;
    }
    $title = trim((string)($data['title'] ?? ''));
    $title = $title !== '' ? mb_substr($title, 0, 30) : null;
    $orcid = strtoupper(trim((string)($data['orcid'] ?? '')));
    if ($orcid !== '' && !preg_match('/^\d{4}-\d{4}-\d{4}-\d{3}[\dX]$/', $orcid)) {
        return 'An ORCID iD looks like 0000-0002-1825-0097.';
    }
    $pdo->prepare('INSERT INTO abs_profiles (user_id, title, first_name, last_name, affiliation, country, orcid)
                   VALUES (?, ?, ?, ?, ?, ?, ?)
                   ON DUPLICATE KEY UPDATE title = VALUES(title), first_name = VALUES(first_name), last_name = VALUES(last_name),
                                           affiliation = VALUES(affiliation), country = VALUES(country), orcid = VALUES(orcid)')
        ->execute([$userId, $title, $clean['firstName'], $clean['lastName'], $clean['affiliation'], $clean['country'],
                   $orcid !== '' ? $orcid : null]);
    return null;
}

// ---------------------------------------------------------------------------------------
// Abstracts: reading
// ---------------------------------------------------------------------------------------

/** Words as a reader counts them. The page counts the same way, so the two never disagree. */
function absWordCount(string $text): int
{
    $text = trim($text);
    if ($text === '') return 0;
    return count(preg_split('/\s+/u', $text) ?: []);
}

function absAuthors(PDO $pdo, int $abstractId): array
{
    $stmt = $pdo->prepare('SELECT * FROM abs_authors WHERE abstract_id = ? ORDER BY sort_order, id');
    $stmt->execute([$abstractId]);
    return array_map(static fn($a) => [
        'firstName' => $a['first_name'],
        'lastName' => $a['last_name'],
        'email' => $a['email'],
        'affiliation' => $a['affiliation'],
        'country' => $a['country'],
        'presenting' => (bool)(int)$a['is_presenting'],
        'corresponding' => (bool)(int)$a['is_corresponding'],
    ], $stmt->fetchAll());
}

function absAbstractRow(PDO $pdo, int $abstractId): ?array
{
    $stmt = $pdo->prepare('SELECT a.*, t.name AS track_name FROM abs_abstracts a
                           LEFT JOIN abs_tracks t ON t.id = a.track_id WHERE a.id = ? LIMIT 1');
    $stmt->execute([$abstractId]);
    return $stmt->fetch() ?: null;
}

/** The author's own abstract, or null - never someone else's. */
function absOwnAbstract(PDO $pdo, int $abstractId, int $userId): ?array
{
    $row = absAbstractRow($pdo, $abstractId);
    return ($row && (int)$row['submitter_user_id'] === $userId) ? $row : null;
}

/**
 * Whether the author may still change the abstract, and until when.
 *
 * @return array{0: bool, 1: ?string} [editable, why not]
 */
function absEditable(array $eventRow, array $abstract): array
{
    if (!in_array($abstract['status'], ['draft', 'submitted'], true)) {
        return [false, 'This abstract is ' . str_replace('_', ' ', $abstract['status']) . ' and can no longer be changed.'];
    }
    $zone = absZone($eventRow);
    $now = new DateTimeImmutable('now', $zone);
    $reopened = absAt($abstract['reopened_until'] ?? null, $zone);
    if ($reopened && $now <= $reopened) return [true, null];
    $state = absCallState($eventRow);
    if ($state === 'open') return [true, null];
    return [false, $state === 'not_open' ? 'The call for abstracts has not opened yet.'
                                         : 'The submission deadline has passed, so this abstract is locked.'];
}

function absAbstractShape(PDO $pdo, array $row, array $eventRow, bool $forAdmin = false): array
{
    $zone = absZone($eventRow);
    [$editable, $lockedReason] = absEditable($eventRow, $row);
    $sections = $row['sections'] ? json_decode((string)$row['sections'], true) : null;
    $shape = [
        'id' => (int)$row['id'],
        'eventId' => (int)$row['event_id'],
        'reference' => $row['reference'],
        'status' => $row['status'],
        'trackId' => $row['track_id'] !== null ? (int)$row['track_id'] : null,
        'trackName' => $row['track_name'] ?? null,
        'title' => $row['title'],
        'body' => (string)($row['body'] ?? ''),
        'sections' => is_array($sections) ? $sections : null,
        'keywords' => json_decode((string)($row['keywords'] ?? '[]'), true) ?: [],
        'preferredType' => $row['preferred_type'],
        'finalType' => $row['final_type'],
        'authors' => absAuthors($pdo, (int)$row['id']),
        'declarations' => json_decode((string)($row['declarations'] ?? 'null'), true),
        'submittedAt' => absIso($row['submitted_at'], $zone),
        'withdrawnAt' => absIso($row['withdrawn_at'], $zone),
        'updatedAt' => absIso($row['updated_at'], $zone),
        'reopenedUntil' => absIso($row['reopened_until'], $zone),
        'editable' => $editable,
        'lockedReason' => $lockedReason,
        'withdrawable' => in_array($row['status'], ABS_WITHDRAWABLE, true),
    ];
    if ($forAdmin) {
        $shape['submitterUserId'] = (int)$row['submitter_user_id'];
        $shape['reopenedUntilLocal'] = $row['reopened_until'];
    }
    return $shape;
}

/** The author's dashboard (SUB-14): their abstracts in this event, and earlier years (INT-3). */
function absMyAbstracts(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare('SELECT a.*, t.name AS track_name, e.name AS event_name, e.timezone, e.call_opens_at,
                                  e.call_closes_at, e.status AS event_status
                           FROM abs_abstracts a
                           JOIN abs_events e ON e.id = a.event_id
                           LEFT JOIN abs_tracks t ON t.id = a.track_id
                           WHERE a.submitter_user_id = ?
                           ORDER BY a.created_at DESC, a.id DESC');
    $stmt->execute([$userId]);
    $out = [];
    foreach ($stmt->fetchAll() as $row) {
        $event = ['timezone' => $row['timezone'], 'call_opens_at' => $row['call_opens_at'], 'call_closes_at' => $row['call_closes_at']];
        [$editable] = absEditable($event, $row);
        $zone = absZone($event);
        $out[] = [
            'id' => (int)$row['id'],
            'eventId' => (int)$row['event_id'],
            'eventName' => $row['event_name'],
            'reference' => $row['reference'],
            'title' => $row['title'] !== '' ? $row['title'] : 'Untitled draft',
            'trackName' => $row['track_name'],
            'status' => $row['status'],
            'submittedAt' => absIso($row['submitted_at'], $zone),
            'updatedAt' => absIso($row['updated_at'], $zone),
            'editable' => $editable,
        ];
    }
    return $out;
}

// ---------------------------------------------------------------------------------------
// Abstracts: writing
// ---------------------------------------------------------------------------------------

/**
 * Cleans what the form sent and checks it against the event's rules.
 *
 * Length caps always apply - nobody saves a 50,000-word draft. With $strict, everything a
 * submission needs is required too (SUB-1..3, SUB-5).
 *
 * @return array{0: array, 1: array<string,string>} [clean values, errors by field]
 */
function absValidate(PDO $pdo, array $eventRow, array $data, bool $strict): array
{
    $s = absSettings($eventRow['settings'] ?? null);
    $errors = [];
    $clean = [];

    $title = trim((string)preg_replace('/\s+/u', ' ', (string)($data['title'] ?? '')));
    $clean['title'] = mb_substr($title, 0, 400);
    $titleWords = absWordCount($title);
    if ($strict && $title === '') $errors['title'] = 'Add a title.';
    elseif ($titleWords > $s['titleWords']) $errors['title'] = "The title is {$titleWords} words; the limit is {$s['titleWords']}.";

    // The body: either structured sections or one block of text, counted together.
    $clean['sections'] = null;
    $clean['body'] = '';
    if ($s['structured']) {
        $incoming = is_array($data['sections'] ?? null) ? $data['sections'] : [];
        $sections = [];
        foreach ($s['sections'] as $heading) {
            $text = trim(str_replace("\r\n", "\n", (string)($incoming[$heading] ?? '')));
            $sections[$heading] = mb_substr($text, 0, 20000);
            if ($strict && $text === '') $errors['section:' . $heading] = "Fill in the {$heading} section.";
        }
        $clean['sections'] = $sections;
        $clean['body'] = implode("\n\n", array_map(static fn($h, $t) => $h . ': ' . $t, array_keys($sections), $sections));
        $bodyWords = array_sum(array_map('absWordCount', $sections));
    } else {
        $body = trim(str_replace("\r\n", "\n", (string)($data['body'] ?? '')));
        $clean['body'] = mb_substr($body, 0, 20000);
        $bodyWords = absWordCount($body);
        if ($strict && $body === '') $errors['body'] = 'Write the abstract.';
    }
    if ($bodyWords > $s['bodyWords']) $errors['body'] = "The abstract is {$bodyWords} words; the limit is {$s['bodyWords']}.";

    // Keywords: trimmed, de-duplicated without regard to case.
    $keywords = [];
    foreach (is_array($data['keywords'] ?? null) ? $data['keywords'] : [] as $k) {
        $k = trim((string)preg_replace('/\s+/u', ' ', (string)$k));
        if ($k === '') continue;
        $k = mb_substr($k, 0, 60);
        $keywords[mb_strtolower($k)] = $k;
    }
    $clean['keywords'] = array_values($keywords);
    $count = count($clean['keywords']);
    if ($count > $s['keywordsMax']) $errors['keywords'] = "Use at most {$s['keywordsMax']} keywords.";
    elseif ($strict && $count < $s['keywordsMin']) $errors['keywords'] = "Add at least {$s['keywordsMin']} keywords.";

    // Track: must be an active track of this event.
    $trackId = (int)($data['trackId'] ?? 0);
    $clean['track_id'] = null;
    if ($trackId) {
        $stmt = $pdo->prepare('SELECT id FROM abs_tracks WHERE id = ? AND event_id = ? AND active = 1');
        $stmt->execute([$trackId, (int)$eventRow['id']]);
        if ($stmt->fetch()) $clean['track_id'] = $trackId;
        else $errors['trackId'] = 'Choose one of the listed tracks.';
    } elseif ($strict) {
        $errors['trackId'] = 'Choose a track.';
    }

    $type = (string)($data['preferredType'] ?? '');
    $clean['preferred_type'] = array_key_exists($type, $s['presentationTypes']) ? $type : null;
    if ($strict && $clean['preferred_type'] === null) $errors['preferredType'] = 'Choose a presentation type.';

    // Authors (SUB-2).
    $authors = [];
    foreach (is_array($data['authors'] ?? null) ? $data['authors'] : [] as $a) {
        if (!is_array($a)) continue;
        $row = [
            'first_name' => mb_substr(trim((string)($a['firstName'] ?? '')), 0, 80),
            'last_name' => mb_substr(trim((string)($a['lastName'] ?? '')), 0, 80),
            'email' => mb_substr(strtolower(trim((string)($a['email'] ?? ''))), 0, 190),
            'affiliation' => mb_substr(trim((string)($a['affiliation'] ?? '')), 0, 200),
            'country' => mb_substr(trim((string)($a['country'] ?? '')), 0, 60),
            'is_presenting' => !empty($a['presenting']) ? 1 : 0,
            'is_corresponding' => !empty($a['corresponding']) ? 1 : 0,
        ];
        if (implode('', [$row['first_name'], $row['last_name'], $row['email'], $row['affiliation']]) === '') continue;
        $authors[] = $row;
    }
    if (count($authors) > $s['maxAuthors']) $errors['authors'] = "List at most {$s['maxAuthors']} authors.";
    $emails = [];
    foreach ($authors as $i => $a) {
        $n = $i + 1;
        if ($a['email'] !== '' && !filter_var($a['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['authors'] = "Author {$n} has an email address that does not look right.";
        } elseif ($a['email'] !== '' && isset($emails[$a['email']])) {
            $errors['authors'] = "Author {$n} has the same email address as another author.";
        } elseif ($strict && ($a['first_name'] === '' || $a['last_name'] === '' || $a['email'] === '' || $a['affiliation'] === '')) {
            $errors['authors'] = "Author {$n} needs a first name, last name, email and affiliation.";
        }
        if ($a['email'] !== '') $emails[$a['email']] = true;
    }
    if ($strict && !isset($errors['authors'])) {
        $presenting = array_sum(array_column($authors, 'is_presenting'));
        $corresponding = array_sum(array_column($authors, 'is_corresponding'));
        if (!$authors) $errors['authors'] = 'List at least one author.';
        elseif ($presenting !== 1) $errors['authors'] = 'Mark exactly one presenting author.';
        elseif ($corresponding !== 1) $errors['authors'] = 'Mark exactly one corresponding author.';
    }
    $clean['authors'] = $authors;

    return [$clean, $errors];
}

/** Everything about an abstract that an edit can change, for the version history. */
function absSnapshot(PDO $pdo, array $row): string
{
    return json_encode([
        'title' => $row['title'], 'body' => $row['body'], 'sections' => json_decode((string)($row['sections'] ?? 'null'), true),
        'keywords' => json_decode((string)($row['keywords'] ?? '[]'), true), 'trackId' => $row['track_id'],
        'preferredType' => $row['preferred_type'], 'authors' => absAuthors($pdo, (int)$row['id']),
        'status' => $row['status'],
    ], JSON_UNESCAPED_UNICODE);
}

function absWriteContent(PDO $pdo, int $abstractId, array $clean): void
{
    $pdo->prepare('UPDATE abs_abstracts SET title = ?, body = ?, sections = ?, keywords = ?, track_id = ?, preferred_type = ? WHERE id = ?')
        ->execute([$clean['title'], $clean['body'], $clean['sections'] !== null ? json_encode($clean['sections'], JSON_UNESCAPED_UNICODE) : null,
                   json_encode($clean['keywords'], JSON_UNESCAPED_UNICODE), $clean['track_id'], $clean['preferred_type'], $abstractId]);
    $pdo->prepare('DELETE FROM abs_authors WHERE abstract_id = ?')->execute([$abstractId]);
    $insert = $pdo->prepare('INSERT INTO abs_authors (abstract_id, sort_order, first_name, last_name, email, affiliation, country,
                                                      is_presenting, is_corresponding, user_id)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, (SELECT id FROM users WHERE LOWER(email) = ? LIMIT 1))');
    foreach ($clean['authors'] as $i => $a) {
        $insert->execute([$abstractId, $i, $a['first_name'], $a['last_name'], $a['email'], $a['affiliation'],
                          $a['country'] !== '' ? $a['country'] : null, $a['is_presenting'], $a['is_corresponding'], $a['email']]);
    }
}

/**
 * Starts a draft for the signed-in author (SUB-4, SUB-13).
 *
 * @return array{0: ?int, 1: ?string} [abstract id, error]
 */
function absCreateDraft(PDO $pdo, array $eventRow, int $userId): array
{
    if (absCallState($eventRow) !== 'open') return [null, 'The call for abstracts is not open.'];
    $s = absSettings($eventRow['settings'] ?? null);
    if ($s['maxPerSubmitter'] > 0) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM abs_abstracts WHERE event_id = ? AND submitter_user_id = ? AND status <> 'withdrawn'");
        $stmt->execute([(int)$eventRow['id'], $userId]);
        if ((int)$stmt->fetchColumn() >= $s['maxPerSubmitter']) {
            return [null, "Each person may submit up to {$s['maxPerSubmitter']} abstract(s) to this event."];
        }
    }
    $pdo->prepare('INSERT INTO abs_abstracts (event_id, submitter_user_id) VALUES (?, ?)')->execute([(int)$eventRow['id'], $userId]);
    $id = (int)$pdo->lastInsertId();

    // The submitter is usually the first author, so the list starts with them.
    $p = absProfile($pdo, $userId);
    $email = $pdo->prepare('SELECT email FROM users WHERE id = ?');
    $email->execute([$userId]);
    $pdo->prepare('INSERT INTO abs_authors (abstract_id, sort_order, first_name, last_name, email, affiliation, country,
                                            is_presenting, is_corresponding, user_id) VALUES (?, 0, ?, ?, ?, ?, ?, 1, 1, ?)')
        ->execute([$id, (string)$p['firstName'], (string)$p['lastName'], strtolower((string)$email->fetchColumn()),
                   (string)$p['affiliation'], $p['country'] ?: null, $userId]);
    absAudit($pdo, (int)$eventRow['id'], $id, $userId, 'draft_created', null, 'draft');
    return [$id, null];
}

/**
 * Saves the author's changes. A submitted abstract stays submitted but is held to the full
 * rules, and its previous state is kept as a version.
 *
 * @return array{0: bool, 1: ?string, 2: array} [saved, error, field errors]
 */
function absSave(PDO $pdo, array $eventRow, array $row, array $data, int $userId): array
{
    [$editable, $why] = absEditable($eventRow, $row);
    if (!$editable) return [false, $why, []];
    $strict = $row['status'] === 'submitted';
    [$clean, $errors] = absValidate($pdo, $eventRow, $data, $strict);
    if ($errors) {
        return [false, $strict ? 'This abstract is already submitted, so every change must still meet the rules.'
                               : 'Some fields need attention before this draft can be saved.', $errors];
    }
    $pdo->beginTransaction();
    try {
        if ($strict) {
            $pdo->prepare('INSERT INTO abs_versions (abstract_id, snapshot, saved_by) VALUES (?, ?, ?)')
                ->execute([(int)$row['id'], absSnapshot($pdo, $row), $userId]);
        }
        absWriteContent($pdo, (int)$row['id'], $clean);
        if ($strict) absAudit($pdo, (int)$row['event_id'], (int)$row['id'], $userId, 'edited_after_submission');
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return [true, null, []];
}

/** The next reference for the event: PREFIX-001, PREFIX-002 ... */
function absNextReference(PDO $pdo, array $eventRow): string
{
    $prefix = (string)$eventRow['ref_prefix'];
    $stmt = $pdo->prepare('SELECT reference FROM abs_abstracts WHERE event_id = ? AND reference IS NOT NULL
                           ORDER BY CAST(SUBSTRING_INDEX(reference, "-", -1) AS UNSIGNED) DESC LIMIT 1 FOR UPDATE');
    $stmt->execute([(int)$eventRow['id']]);
    $last = (string)$stmt->fetchColumn();
    $next = $last !== '' ? ((int)substr($last, strrpos($last, '-') + 1)) + 1 : 1;
    return $prefix . '-' . str_pad((string)$next, 3, '0', STR_PAD_LEFT);
}

/**
 * Submits (SUB-5, SUB-6). The data on the request is saved first, so what the author saw in
 * the preview is exactly what is submitted.
 *
 * @return array{0: bool, 1: ?string, 2: array, 3: bool} [ok, error, field errors, first submission]
 */
function absSubmit(PDO $pdo, array $eventRow, array $row, array $data, int $userId): array
{
    [$editable, $why] = absEditable($eventRow, $row);
    if (!$editable) return [false, $why, [], false];
    if ($row['status'] !== 'draft') return [false, 'This abstract has already been submitted.', [], false];

    [$clean, $errors] = absValidate($pdo, $eventRow, $data, true);
    $s = absSettings($eventRow['settings'] ?? null);
    $accepted = is_array($data['declarations'] ?? null) ? $data['declarations'] : [];
    foreach ($s['declarations'] as $d) {
        if (empty($accepted[$d['key']])) { $errors['declarations'] = 'Please confirm every declaration before submitting.'; break; }
    }
    if ($errors) return [false, 'Some fields need attention before you can submit.', $errors, false];

    $zone = absZone($eventRow);
    $now = (new DateTimeImmutable('now', $zone))->format('Y-m-d H:i:s');
    $first = $row['reference'] === null;
    $pdo->beginTransaction();
    try {
        absWriteContent($pdo, (int)$row['id'], $clean);
        $reference = $first ? absNextReference($pdo, $eventRow) : $row['reference'];
        $record = ['accepted' => array_column($s['declarations'], 'label', 'key'), 'at' => $now];
        $pdo->prepare("UPDATE abs_abstracts SET status = 'submitted', reference = ?, declarations = ?, submitted_at = ?,
                              reopened_until = NULL WHERE id = ?")
            ->execute([$reference, json_encode($record, JSON_UNESCAPED_UNICODE), $now, (int)$row['id']]);
        absAudit($pdo, (int)$row['event_id'], (int)$row['id'], $userId, $first ? 'submitted' : 'resubmitted', 'draft', 'submitted',
                 ['reference' => $reference]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return [true, null, [], $first];
}

/** @return ?string an error, or null when withdrawn */
function absWithdraw(PDO $pdo, array $eventRow, array $row, int $userId, string $reason): ?string
{
    if (!in_array($row['status'], ABS_WITHDRAWABLE, true)) return 'This abstract cannot be withdrawn.';
    $now = (new DateTimeImmutable('now', absZone($eventRow)))->format('Y-m-d H:i:s');
    $pdo->prepare("UPDATE abs_abstracts SET status = 'withdrawn', withdrawn_at = ?, reopened_until = NULL WHERE id = ?")->execute([$now, (int)$row['id']]);
    // Reviewers stop being asked for an abstract that is no longer in the running.
    $pdo->prepare("UPDATE abs_reviews SET status = 'cancelled' WHERE abstract_id = ? AND status IN ('assigned','in_progress')")
        ->execute([(int)$row['id']]);
    absAudit($pdo, (int)$row['event_id'], (int)$row['id'], $userId, 'withdrawn', $row['status'], 'withdrawn',
             $reason !== '' ? ['reason' => mb_substr($reason, 0, 500)] : null);
    return null;
}

/**
 * An administrator sends a submitted abstract back to Draft for corrections (spec section 3),
 * open to the author until the given time even if the call has closed.
 *
 * @return ?string an error, or null when reopened
 */
function absReopen(PDO $pdo, array $eventRow, array $row, int $adminUserId, ?string $until, string $note): ?string
{
    if (!in_array($row['status'], ['submitted', 'draft'], true)) {
        return 'Only a submitted abstract (or a draft) can be returned for corrections.';
    }
    $zone = absZone($eventRow);
    $untilValue = absParseLocal((string)$until);
    if ($untilValue === null) $untilValue = (new DateTimeImmutable('now', $zone))->modify('+7 days')->format('Y-m-d H:i:s');
    if (absAt($untilValue, $zone) <= new DateTimeImmutable('now', $zone)) return 'Choose a time in the future.';
    $pdo->prepare("UPDATE abs_abstracts SET status = 'draft', reopened_until = ? WHERE id = ?")->execute([$untilValue, (int)$row['id']]);
    absAudit($pdo, (int)$row['event_id'], (int)$row['id'], $adminUserId, 'reopened', $row['status'], 'draft',
             ['until' => $untilValue, 'note' => $note !== '' ? mb_substr($note, 0, 500) : null]);
    return null;
}

// ---------------------------------------------------------------------------------------
// Audit (ADM-9)
// ---------------------------------------------------------------------------------------

function absAudit(PDO $pdo, ?int $eventId, ?int $abstractId, ?int $userId, string $action,
                  ?string $from = null, ?string $to = null, ?array $detail = null): void
{
    $pdo->prepare('INSERT INTO abs_audit (event_id, abstract_id, user_id, action, from_status, to_status, detail)
                   VALUES (?, ?, ?, ?, ?, ?, ?)')
        ->execute([$eventId, $abstractId, $userId, $action, $from, $to,
                   $detail !== null ? json_encode($detail, JSON_UNESCAPED_UNICODE) : null]);
}

function absAuditFor(PDO $pdo, ?int $eventId, ?int $abstractId, int $limit = 200): array
{
    $where = $abstractId ? 'l.abstract_id = ?' : 'l.event_id = ?';
    $stmt = $pdo->prepare("SELECT l.*, u.email FROM abs_audit l LEFT JOIN users u ON u.id = l.user_id
                           WHERE $where ORDER BY l.created_at DESC, l.id DESC LIMIT " . max(1, min(1000, $limit)));
    $stmt->execute([$abstractId ?: $eventId]);
    return array_map(static fn($r) => [
        'action' => $r['action'], 'from' => $r['from_status'], 'to' => $r['to_status'],
        'by' => $r['email'], 'detail' => json_decode((string)($r['detail'] ?? 'null'), true),
        'abstractId' => $r['abstract_id'] !== null ? (int)$r['abstract_id'] : null, 'at' => $r['created_at'],
    ], $stmt->fetchAll());
}

// ---------------------------------------------------------------------------------------
// Administration
// ---------------------------------------------------------------------------------------

function absAdminList(PDO $pdo, array $eventRow): array
{
    $stmt = $pdo->prepare('SELECT a.*, t.name AS track_name, u.email AS submitter_email,
                                  (SELECT CONCAT(au.first_name, " ", au.last_name) FROM abs_authors au
                                    WHERE au.abstract_id = a.id AND au.is_presenting = 1 LIMIT 1) AS presenter
                           FROM abs_abstracts a
                           LEFT JOIN abs_tracks t ON t.id = a.track_id
                           LEFT JOIN users u ON u.id = a.submitter_user_id
                           WHERE a.event_id = ?
                           ORDER BY (a.reference IS NULL), a.reference, a.id');
    $stmt->execute([(int)$eventRow['id']]);
    $zone = absZone($eventRow);
    $counts = array_fill_keys(ABS_STATUSES, 0);
    $rows = [];
    foreach ($stmt->fetchAll() as $r) {
        $counts[$r['status']]++;
        $rows[] = [
            'id' => (int)$r['id'], 'reference' => $r['reference'], 'title' => $r['title'] !== '' ? $r['title'] : 'Untitled draft',
            'status' => $r['status'], 'trackName' => $r['track_name'], 'preferredType' => $r['preferred_type'],
            'presenter' => $r['presenter'], 'submitterEmail' => $r['submitter_email'],
            'submittedAt' => absIso($r['submitted_at'], $zone), 'updatedAt' => absIso($r['updated_at'], $zone),
        ];
    }
    return ['abstracts' => $rows, 'counts' => $counts];
}

function absVersions(PDO $pdo, int $abstractId): array
{
    $stmt = $pdo->prepare('SELECT v.snapshot, v.saved_at, u.email FROM abs_versions v LEFT JOIN users u ON u.id = v.saved_by
                           WHERE v.abstract_id = ? ORDER BY v.saved_at DESC, v.id DESC');
    $stmt->execute([$abstractId]);
    return array_map(static fn($v) => ['savedAt' => $v['saved_at'], 'by' => $v['email'],
                                      'snapshot' => json_decode((string)$v['snapshot'], true)], $stmt->fetchAll());
}

// ---------------------------------------------------------------------------------------
// Email
// ---------------------------------------------------------------------------------------

/**
 * Sends through the queue (NFR-10, and the deadline rush).
 *
 * Shared hosting caps outgoing mail per hour, and most abstracts arrive in the last two
 * days, each with a confirmation and co-author notices. Sending every message the moment it
 * is triggered would hit that cap exactly when it matters, and the messages the host then
 * refuses are confirmations authors are waiting for. So every message is written to the
 * queue first and sent at once only while the hour's budget lasts; the rest go out on later
 * requests, or from the cron job, as the budget frees up.
 *
 * A $dedupeKey makes the message once-only (reminders): a second call with the same key is
 * ignored rather than queued again.
 *
 * Returns true when the message has been handed to the mail server already, false when it
 * is waiting in the queue or failed.
 */
function absSendLogged(PDO $pdo, array $config, ?int $eventId, ?int $abstractId, string $template,
                       string $to, string $subject, string $text, string $html, ?string $dedupeKey = null): bool
{
    try {
        $stmt = $pdo->prepare('INSERT IGNORE INTO abs_emails (event_id, abstract_id, template, to_email, subject, status, text_body, html_body, dedupe_key)
                               VALUES (?, ?, ?, ?, ?, "queued", ?, ?, ?)');
        $stmt->execute([$eventId, $abstractId, $template, mb_substr($to, 0, 190), mb_substr($subject, 0, 300), $text, $html,
                        $dedupeKey !== null ? mb_substr($dedupeKey, 0, 160) : null]);
        if ($stmt->rowCount() === 0) return false; // already queued or sent under this key
        $id = (int)$pdo->lastInsertId();
    } catch (Throwable $e) {
        error_log('Could not queue abstract email: ' . $e->getMessage());
        return false;
    }
    absMailPump($pdo, $config, 5);
    $check = $pdo->prepare('SELECT status FROM abs_emails WHERE id = ?');
    $check->execute([$id]);
    return $check->fetchColumn() === 'sent';
}

/** Messages the host allows per hour, from config (abstracts_mail_hourly_limit). */
function absMailHourlyLimit(array $config): int
{
    return max(10, (int)($config['abstracts_mail_hourly_limit'] ?? 150));
}

/**
 * Sends up to $max queued messages, never more than the hour's remaining budget. Each row is
 * claimed before it is sent, so two requests pumping at once cannot send the same message
 * twice. A message that fails is retried on later pumps, up to three attempts.
 *
 * @return array{sent:int, failed:int, waiting:int}
 */
function absMailPump(PDO $pdo, array $config, int $max = 20): array
{
    $result = ['sent' => 0, 'failed' => 0, 'waiting' => 0];
    try {
        // A request that died mid-send leaves its row claimed (sent_at holds the claim time
        // while 'sending'); release it after ten minutes.
        $pdo->exec("UPDATE abs_emails SET status = 'queued', sent_at = NULL
                    WHERE status = 'sending' AND sent_at < (NOW() - INTERVAL 10 MINUTE)");
        $used = (int)$pdo->query("SELECT COUNT(*) FROM abs_emails WHERE sent_at >= (NOW() - INTERVAL 1 HOUR)")->fetchColumn();
        $budget = min($max, absMailHourlyLimit($config) - $used);
        if ($budget > 0) {
            $rows = $pdo->query("SELECT id FROM abs_emails WHERE status = 'queued'
                                 AND (next_attempt_at IS NULL OR next_attempt_at <= NOW()) ORDER BY id LIMIT " . (int)$budget)->fetchAll();
            foreach ($rows as $r) {
                $claim = $pdo->prepare("UPDATE abs_emails SET status = 'sending', attempts = attempts + 1, sent_at = NOW() WHERE id = ? AND status = 'queued'");
                $claim->execute([(int)$r['id']]);
                if ($claim->rowCount() === 0) continue;
                $msg = $pdo->prepare('SELECT * FROM abs_emails WHERE id = ?');
                $msg->execute([(int)$r['id']]);
                $m = $msg->fetch();
                $ok = false;
                $error = null;
                try {
                    $ok = (new SimpleMailer($config))->send((string)$m['to_email'], (string)$m['subject'], (string)$m['text_body'], [], $m['html_body'] ?: null);
                    if (!$ok) $error = 'The mail server did not accept the message.';
                } catch (Throwable $e) {
                    $error = mb_substr($e->getMessage(), 0, 500);
                }
                if ($ok) {
                    // sent_at is what the hourly budget counts (claimed rows count too, briefly).
                    $pdo->prepare("UPDATE abs_emails SET status = 'sent', error = NULL, sent_at = NOW() WHERE id = ?")->execute([(int)$m['id']]);
                    $result['sent']++;
                } else {
                    // Back in the queue until the third attempt fails, waiting 10 then 20 minutes
                    // first, so a short outage at the mail server does not use up every try.
                    $final = (int)$m['attempts'] >= 3;
                    $pdo->prepare('UPDATE abs_emails SET status = ?, error = ?, sent_at = NULL,
                                          next_attempt_at = NOW() + INTERVAL ? MINUTE WHERE id = ?')
                        ->execute([$final ? 'failed' : 'queued', $error, 10 * (int)$m['attempts'], (int)$m['id']]);
                    $result['failed']++;
                }
            }
        }
        $result['waiting'] = (int)$pdo->query("SELECT COUNT(*) FROM abs_emails WHERE status IN ('queued','sending')")->fetchColumn();
    } catch (Throwable $e) {
        error_log('Abstract mail pump failed: ' . $e->getMessage());
    }
    return $result;
}

/** Queues a fresh copy of a logged message (ADM: "administrators can resend any of them"). */
function absMailResend(PDO $pdo, array $config, int $emailId): bool
{
    $stmt = $pdo->prepare('SELECT * FROM abs_emails WHERE id = ?');
    $stmt->execute([$emailId]);
    $m = $stmt->fetch();
    if (!$m || $m['text_body'] === null) return false;
    absSendLogged($pdo, $config, $m['event_id'] !== null ? (int)$m['event_id'] : null,
                  $m['abstract_id'] !== null ? (int)$m['abstract_id'] : null, (string)$m['template'],
                  (string)$m['to_email'], (string)$m['subject'], (string)$m['text_body'], (string)$m['html_body']);
    return true;
}

function absEmailUrl(array $config): string
{
    return rtrim((string)($config['portal_base_url'] ?? ''), '/') . '/abstracts';
}

function absPara(string $text): string
{
    return '<p style="margin:0 0 14px;font-size:15px;line-height:1.65;">' . htmlspecialchars($text, ENT_QUOTES) . '</p>';
}

/** Confirmation to the submitter and corresponding author, and a notice to every other co-author. */
function absSendSubmissionEmails(PDO $pdo, array $config, array $eventRow, int $abstractId, bool $first): void
{
    $row = absAbstractRow($pdo, $abstractId);
    if (!$row) return;
    $authors = absAuthors($pdo, $abstractId);
    $submitter = $pdo->prepare('SELECT email FROM users WHERE id = ?');
    $submitter->execute([(int)$row['submitter_user_id']]);
    $submitterEmail = strtolower((string)$submitter->fetchColumn());
    $corresponding = '';
    foreach ($authors as $a) if ($a['corresponding']) $corresponding = strtolower($a['email']);

    $event = (string)$eventRow['name'];
    $ref = (string)$row['reference'];
    $title = (string)$row['title'];
    $names = implode(', ', array_map(static fn($a) => trim($a['firstName'] . ' ' . $a['lastName']), $authors));
    $closes = absAt($eventRow['call_closes_at'], absZone($eventRow));
    $deadline = $closes ? $closes->format('j F Y, H:i') . ' (' . absZone($eventRow)->getName() . ')' : '';
    $url = absEmailUrl($config);

    $subject = "Abstract received: {$ref}";
    $text = "Thank you for submitting your abstract to {$event}.\n\nReference: {$ref}\nTitle: {$title}\n"
        . "Track: " . ($row['track_name'] ?? '-') . "\nAuthors: {$names}\n\n"
        . ($deadline !== '' ? "You can edit or withdraw it until the submission deadline, {$deadline}.\n" : '')
        . "Track its status at {$url}\n\nRespiratory Society of Kenya";
    $html = brandedEmailHtml('Your abstract has been received',
        absPara("Thank you for submitting your abstract to {$event}.")
        . '<table style="width:100%;border-collapse:collapse;font-size:14px;margin:0 0 16px;">'
        . '<tr><td style="padding:6px 0;color:#667085;width:90px;">Reference</td><td style="padding:6px 0;font-weight:700;">' . htmlspecialchars($ref, ENT_QUOTES) . '</td></tr>'
        . '<tr><td style="padding:6px 0;color:#667085;">Title</td><td style="padding:6px 0;">' . htmlspecialchars($title, ENT_QUOTES) . '</td></tr>'
        . '<tr><td style="padding:6px 0;color:#667085;">Track</td><td style="padding:6px 0;">' . htmlspecialchars((string)($row['track_name'] ?? '-'), ENT_QUOTES) . '</td></tr>'
        . '<tr><td style="padding:6px 0;color:#667085;">Authors</td><td style="padding:6px 0;">' . htmlspecialchars($names, ENT_QUOTES) . '</td></tr>'
        . '</table>'
        . ($deadline !== '' ? absPara("You can edit or withdraw it until the submission deadline, {$deadline}.") : ''),
        'View my abstracts', $url);

    $confirmTo = array_unique(array_filter([$submitterEmail, $corresponding]));
    foreach ($confirmTo as $to) {
        absSendLogged($pdo, $config, (int)$eventRow['id'], $abstractId, 'submission_confirmation', $to, $subject, $text, $html);
    }
    if (!$first) return;

    // Co-author notice (spec section 7): everyone listed who has not already had the confirmation.
    foreach ($authors as $a) {
        $to = strtolower($a['email']);
        if ($to === '' || in_array($to, $confirmTo, true)) continue;
        $greeting = 'Dear ' . trim($a['firstName']) . ',';
        $coText = "{$greeting}\n\nYou are listed as a co-author on an abstract submitted to {$event}.\n\nReference: {$ref}\nTitle: {$title}\nAuthors: {$names}\n\n"
            . "If you did not agree to be listed, please contact the corresponding author or reply to the ReSoK secretariat.\n\nRespiratory Society of Kenya";
        $coHtml = brandedEmailHtml('You are listed as a co-author',
            absPara($greeting)
            . absPara("You are listed as a co-author on an abstract submitted to {$event}.")
            . absPara("Reference: {$ref}")
            . absPara("Title: {$title}")
            . absPara("Authors: {$names}")
            . '<p style="margin:0;font-size:13px;line-height:1.6;color:#667085;">If you did not agree to be listed, please contact the corresponding author or the ReSoK secretariat.</p>');
        absSendLogged($pdo, $config, (int)$eventRow['id'], $abstractId, 'coauthor_notice', $to,
                      "Co-author notice: {$ref}", $coText, $coHtml);
    }
}

function absSendWithdrawalEmails(PDO $pdo, array $config, array $eventRow, int $abstractId): void
{
    $row = absAbstractRow($pdo, $abstractId);
    if (!$row || $row['reference'] === null) return; // A draft nobody else has heard of needs no email.
    $submitter = $pdo->prepare('SELECT email FROM users WHERE id = ?');
    $submitter->execute([(int)$row['submitter_user_id']]);
    $to = [strtolower((string)$submitter->fetchColumn())];
    foreach (absAuthors($pdo, $abstractId) as $a) if ($a['corresponding']) $to[] = strtolower($a['email']);
    $event = (string)$eventRow['name'];
    $ref = (string)$row['reference'];
    $text = "Your abstract {$ref}, \"{$row['title']}\", has been withdrawn from {$event}.\n\n"
        . "If this was a mistake, contact the ReSoK secretariat.\n\nRespiratory Society of Kenya";
    $html = brandedEmailHtml('Abstract withdrawn',
        absPara("Your abstract {$ref}, \"{$row['title']}\", has been withdrawn from {$event}.")
        . '<p style="margin:0;font-size:13px;line-height:1.6;color:#667085;">If this was a mistake, contact the ReSoK secretariat.</p>');
    foreach (array_unique(array_filter($to)) as $address) {
        absSendLogged($pdo, $config, (int)$eventRow['id'], $abstractId, 'withdrawal_confirmation', $address,
                      "Abstract withdrawn: {$ref}", $text, $html);
    }
}

/** Account verification for an author who registered from the abstracts page. */
function absSendVerificationEmail(PDO $pdo, array $config, string $email, string $token, string $firstName): bool
{
    $baseUrl = rtrim((string)($config['portal_base_url'] ?? ''), '/');
    $url = $baseUrl !== '' ? $baseUrl . '/api/index.php?route=' . rawurlencode('auth/verify/' . $token) : '';
    $greeting = trim($firstName) !== '' ? 'Dear ' . trim($firstName) : 'Hello';
    $text = "{$greeting},\n\nThank you for creating a ReSoK account to submit an abstract. Please confirm your email address:\n{$url}\n\n"
        . "If you did not create this account, you can ignore this email.\n\nRespiratory Society of Kenya";
    $html = brandedEmailHtml('Confirm your email to submit an abstract',
        absPara($greeting . ',')
        . absPara('Thank you for creating a ReSoK account to submit an abstract. Confirm your email address, then sign in to start your submission.')
        . '<p style="margin:0;font-size:13px;line-height:1.6;color:#667085;">If you did not create this account, you can safely ignore this email.</p>',
        'Confirm my email', $url);
    return absSendLogged($pdo, $config, null, null, 'account_verification', $email,
                         'Confirm your ReSoK account', $text, $html);
}
