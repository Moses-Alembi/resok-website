<?php
declare(strict_types=1);

/**
 * Events: CMEs, webinars, conferences and workshops.
 *
 * This replaces eventCatalog(), which held three events hardcoded in PHP. Two things went
 * wrong with that. Adding an event meant a code change and a deploy, so in practice events
 * were advertised on the marketing site instead - and the two lists drifted until the public
 * pages and the member portal named entirely different events. And nothing could hang off an
 * event that only existed as a PHP literal: no attendance register, no KMPDC token batch.
 *
 * One row here is the single source for the public listing, the member portal, and later
 * attendance and token assignment.
 *
 * The public payload is built by a separate function from the admin one, deliberately. The
 * joining link is the asset a paid webinar actually sells; a shared shape with a flag to
 * strip it is one forgotten flag away from publishing it.
 */

/**
 * Creates the table if it is absent, and reports whether it can be used.
 *
 * Fails soft for the same reason as the other modules here: on shared hosting the database
 * user often lacks CREATE, and a public events page that 500s is worse than one that says
 * there is nothing scheduled. Callers check the return.
 */
function eventsEnsureTable(PDO $pdo): bool
{
    static $ready = null;
    if ($ready !== null) return $ready;

    try {
        $pdo->query('SELECT 1 FROM cpd_events LIMIT 1');
        return $ready = true;
    } catch (Throwable $e) {
        // Absent - try to build it.
    }
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS cpd_events (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            slug VARCHAR(80) NOT NULL,
            title VARCHAR(200) NOT NULL,
            summary VARCHAR(500) NULL,
            description TEXT NULL,
            event_type ENUM('CME','Webinar','Conference','Workshop','Symposium') NOT NULL DEFAULT 'CME',
            format ENUM('in_person','online','hybrid') NOT NULL DEFAULT 'online',
            starts_at DATETIME NOT NULL,
            ends_at DATETIME NULL,
            venue VARCHAR(200) NULL,
            online_url VARCHAR(500) NULL,
            member_fee INT UNSIGNED NOT NULL DEFAULT 0,
            nonmember_fee INT UNSIGNED NOT NULL DEFAULT 0,
            currency CHAR(3) NOT NULL DEFAULT 'KES',
            capacity INT UNSIGNED NULL,
            regulator VARCHAR(40) NOT NULL DEFAULT 'KMPDC',
            approval_ref VARCHAR(80) NULL,
            approved_points DECIMAL(4,1) NULL,
            min_attendance_pct TINYINT UNSIGNED NOT NULL DEFAULT 80,
            status ENUM('draft','published','closed','cancelled') NOT NULL DEFAULT 'draft',
            banner_image VARCHAR(255) NULL,
            created_by INT UNSIGNED NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY cpd_events_slug (slug),
            KEY cpd_events_listing (status, starts_at),
            KEY cpd_events_starts (starts_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        return $ready = true;
    } catch (Throwable $e) {
        error_log('cpd_events unavailable: ' . $e->getMessage());
        return $ready = false;
    }
}

/** URL-safe name derived from the title, made unique against what is already stored. */
function eventSlug(PDO $pdo, string $title, ?int $ignoreId = null): string
{
    $base = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $title) ?? '', '-'));
    if ($base === '') $base = 'event';
    $base = substr($base, 0, 70);

    $slug = $base;
    for ($n = 2; $n < 100; $n++) {
        $sql = 'SELECT id FROM cpd_events WHERE slug = ?' . ($ignoreId ? ' AND id <> ?' : '') . ' LIMIT 1';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($ignoreId ? [$slug, $ignoreId] : [$slug]);
        if (!$stmt->fetch()) return $slug;
        $slug = $base . '-' . $n;
    }
    return $base . '-' . substr((string)time(), -5);
}

/**
 * What anyone may see, member or not.
 *
 * Note what is absent: online_url. Everything else about an event is a reason to attend, so
 * it is public - including the KMPDC reference and points, which are the whole reason a
 * practitioner picks one CME over another.
 */
function eventPublicShape(array $row): array
{
    $points = $row['approved_points'];
    return [
        'id'            => (int)$row['id'],
        'slug'          => $row['slug'],
        'title'         => $row['title'],
        'summary'       => $row['summary'],
        'type'          => $row['event_type'],
        'format'        => $row['format'],
        'startsAt'      => $row['starts_at'],
        'endsAt'        => $row['ends_at'],
        'venue'         => $row['venue'],
        'memberFee'     => (int)$row['member_fee'],
        'nonmemberFee'  => (int)$row['nonmember_fee'],
        'currency'      => $row['currency'],
        'regulator'     => $row['regulator'],
        'approvalRef'   => $row['approval_ref'],
        // null means not yet accredited. The page says "points pending approval" rather than
        // showing a number nobody has approved.
        'points'        => $points === null ? null : (float)$points,
        'bannerImage'   => $row['banner_image'],
    ];
}

/** Everything, for the admin screens. */
function eventAdminShape(array $row): array
{
    return eventPublicShape($row) + [
        'description'      => $row['description'],
        'onlineUrl'        => $row['online_url'],
        'capacity'         => $row['capacity'] === null ? null : (int)$row['capacity'],
        'minAttendancePct' => (int)$row['min_attendance_pct'],
        'status'           => $row['status'],
        'createdAt'        => $row['created_at'],
    ];
}

/**
 * Published events that have not finished yet.
 *
 * Compared against ends_at where there is one, so a two-day conference stays listed on its
 * second morning instead of vanishing overnight.
 */
function eventsUpcoming(PDO $pdo, int $limit = 50): array
{
    if (!eventsEnsureTable($pdo)) return [];
    $stmt = $pdo->prepare("SELECT * FROM cpd_events
                            WHERE status = 'published'
                              AND COALESCE(ends_at, starts_at) >= NOW()
                            ORDER BY starts_at ASC
                            LIMIT " . max(1, min($limit, 200)));
    $stmt->execute();
    return array_map('eventPublicShape', $stmt->fetchAll());
}

/** Published events that have finished, newest first - the "past events" list. */
function eventsPast(PDO $pdo, int $limit = 12): array
{
    if (!eventsEnsureTable($pdo)) return [];
    $stmt = $pdo->prepare("SELECT * FROM cpd_events
                            WHERE status IN ('published','closed')
                              AND COALESCE(ends_at, starts_at) < NOW()
                            ORDER BY starts_at DESC
                            LIMIT " . max(1, min($limit, 100)));
    $stmt->execute();
    $rows = $stmt->fetchAll();

    // Collection stays locked until the admin has loaded that event's attendees and assigned
    // their tokens. "Ready" therefore means at least one token on this event is tied to an
    // attendee - before that, nobody can collect, so the page should not offer to. The token
    // table may not exist on a fresh install, in which case nothing is ready.
    $ready = [];
    try {
        foreach ($pdo->query('SELECT DISTINCT event_id FROM cpd_tokens WHERE attendee_id IS NOT NULL') as $r) {
            $ready[(int)$r['event_id']] = true;
        }
    } catch (Throwable $tokenTableAbsent) {
        // Leave $ready empty - every event reads as not-yet-ready.
    }

    return array_map(function (array $row) use ($ready): array {
        $shape = eventPublicShape($row);
        $shape['tokensReady'] = isset($ready[(int)$row['id']]);
        return $shape;
    }, $rows);
}

/** Every event including drafts, for admins. */
function eventsAll(PDO $pdo): array
{
    if (!eventsEnsureTable($pdo)) return [];
    return array_map('eventAdminShape', $pdo->query('SELECT * FROM cpd_events ORDER BY starts_at DESC LIMIT 500')->fetchAll());
}

function eventFind(PDO $pdo, string $slug): ?array
{
    if (!eventsEnsureTable($pdo)) return null;
    $stmt = $pdo->prepare('SELECT * FROM cpd_events WHERE slug = ? LIMIT 1');
    $stmt->execute([$slug]);
    return $stmt->fetch() ?: null;
}

function eventFindById(PDO $pdo, int $id): ?array
{
    if (!eventsEnsureTable($pdo)) return null;
    $stmt = $pdo->prepare('SELECT * FROM cpd_events WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

/**
 * The writable fields, mapped from the API's names to the column names, with each value
 * coerced to what the column will accept.
 *
 * Anything not listed here cannot be written through the API at all - id, slug, timestamps
 * and created_by are set by the server, not by whatever arrives in the request body.
 */
function eventWritableFields(): array
{
    return [
        'title'            => ['title', 'string'],
        'summary'          => ['summary', 'string'],
        'description'      => ['description', 'string'],
        'type'             => ['event_type', 'enum', ['CME', 'Webinar', 'Conference', 'Workshop', 'Symposium']],
        'format'           => ['format', 'enum', ['in_person', 'online', 'hybrid']],
        'startsAt'         => ['starts_at', 'datetime'],
        'endsAt'           => ['ends_at', 'datetime'],
        'venue'            => ['venue', 'string'],
        'onlineUrl'        => ['online_url', 'string'],
        'memberFee'        => ['member_fee', 'int'],
        'nonmemberFee'     => ['nonmember_fee', 'int'],
        'capacity'         => ['capacity', 'int'],
        'approvalRef'      => ['approval_ref', 'string'],
        'points'           => ['approved_points', 'decimal'],
        'minAttendancePct' => ['min_attendance_pct', 'int'],
        'status'           => ['status', 'enum', ['draft', 'published', 'closed', 'cancelled']],
        'bannerImage'      => ['banner_image', 'string'],
    ];
}

/** Columns that accept NULL. Everything else is NOT NULL with a default in the schema. */
function eventNullableColumns(): array
{
    return ['summary', 'description', 'ends_at', 'venue', 'online_url',
            'capacity', 'approval_ref', 'approved_points', 'banner_image'];
}

/** @return array{0:array<string,mixed>,1:array<string,string>} [columns to write, errors] */
function eventCollectFields(array $data, bool $isCreate): array
{
    $columns = [];
    $errors = [];

    foreach (eventWritableFields() as $key => $spec) {
        if (!array_key_exists($key, $data)) continue;
        [$column, $kind] = $spec;
        $value = $data[$key];

        if ($value === null || $value === '') {
            // Only columns that actually accept NULL are cleared. The rest are left alone,
            // so an admin who empties the fee box gets the stored value (or the column
            // default) rather than an insert that fails against a NOT NULL column.
            if (in_array($column, eventNullableColumns(), true)) $columns[$column] = null;
            continue;
        }
        switch ($kind) {
            case 'enum':
                if (!in_array($value, $spec[2], true)) {
                    $errors[$key] = 'Choose one of: ' . implode(', ', $spec[2]) . '.';
                } else {
                    $columns[$column] = $value;
                }
                break;
            case 'int':
                if (!is_numeric($value) || (int)$value < 0) $errors[$key] = 'Must be a number of zero or more.';
                else $columns[$column] = (int)$value;
                break;
            case 'decimal':
                if (!is_numeric($value) || (float)$value < 0) $errors[$key] = 'Must be a number of zero or more.';
                else $columns[$column] = round((float)$value, 1);
                break;
            case 'datetime':
                $time = strtotime((string)$value);
                if ($time === false) $errors[$key] = 'Not a date we could read.';
                else $columns[$column] = date('Y-m-d H:i:s', $time);
                break;
            default:
                $columns[$column] = (string)$value;
        }
    }

    if ($isCreate) {
        if (empty($columns['title'])) $errors['title'] = 'A title is required.';
        if (empty($columns['starts_at'])) $errors['startsAt'] = 'A start date and time are required.';
    }
    // Caught here rather than at the database, which would accept it and produce a listing
    // that sorts strangely and a duration nobody can compute.
    if (!empty($columns['ends_at']) && !empty($columns['starts_at']) && $columns['ends_at'] < $columns['starts_at']) {
        $errors['endsAt'] = 'The end must come after the start.';
    }
    if (isset($columns['min_attendance_pct']) && $columns['min_attendance_pct'] > 100) {
        $errors['minAttendancePct'] = 'Must be 100 or less.';
    }
    return [$columns, $errors];
}

/** @return array{0:?array,1:array<string,string>} [created event, errors] */
function eventCreate(PDO $pdo, array $data, ?int $adminUserId): array
{
    if (!eventsEnsureTable($pdo)) {
        return [null, ['_' => 'The events table is not available. Import schema-events.sql.']];
    }
    [$columns, $errors] = eventCollectFields($data, true);
    if ($errors) return [null, $errors];

    $columns['slug'] = eventSlug($pdo, (string)$columns['title']);
    $columns['created_by'] = $adminUserId;

    $names = array_keys($columns);
    $sql = 'INSERT INTO cpd_events (' . implode(', ', $names) . ') VALUES ('
         . implode(', ', array_fill(0, count($names), '?')) . ')';
    $pdo->prepare($sql)->execute(array_values($columns));

    return [eventAdminShape(eventFindById($pdo, (int)$pdo->lastInsertId()) ?? []), []];
}

/** @return array{0:?array,1:array<string,string>} [updated event, errors] */
function eventUpdate(PDO $pdo, int $id, array $data): array
{
    if (!eventsEnsureTable($pdo)) {
        return [null, ['_' => 'The events table is not available. Import schema-events.sql.']];
    }
    $existing = eventFindById($pdo, $id);
    if (!$existing) return [null, ['_' => 'That event no longer exists.']];

    [$columns, $errors] = eventCollectFields($data, false);
    // Start and end may arrive one at a time, so the pair is re-checked against what is
    // already stored - otherwise moving only the start could silently invert the two.
    $starts = $columns['starts_at'] ?? $existing['starts_at'];
    $ends = array_key_exists('ends_at', $columns) ? $columns['ends_at'] : $existing['ends_at'];
    if ($starts && $ends && $ends < $starts) $errors['endsAt'] = 'The end must come after the start.';
    if ($errors) return [null, $errors];
    if (!$columns) return [eventAdminShape($existing), []];

    // The slug is deliberately left alone. It is in links that may already be shared, and a
    // renamed event should not break them.
    $set = implode(', ', array_map(fn($c) => "{$c} = ?", array_keys($columns)));
    $pdo->prepare("UPDATE cpd_events SET {$set} WHERE id = ?")
        ->execute([...array_values($columns), $id]);

    return [eventAdminShape(eventFindById($pdo, $id) ?? []), []];
}

/**
 * Bridges to the shape the member portal's events page already expects, so that page keeps
 * working while it is served from the database instead of the PHP literal it used to read.
 */
function eventLegacyShape(array $public): array
{
    $start = strtotime((string)$public['startsAt']) ?: time();
    $end = $public['endsAt'] ? strtotime((string)$public['endsAt']) : null;

    $time = date('g:ia', $start);
    if ($end) {
        // A multi-day event is described in days; within one day, as a span of hours.
        $days = (int)((strtotime(date('Y-m-d', $end)) - strtotime(date('Y-m-d', $start))) / 86400) + 1;
        $time = $days > 1 ? $days . ' Days' : $time . ' - ' . date('g:ia', $end);
    }
    $location = $public['venue'] ?: ($public['format'] === 'online' ? 'Online' : 'To be confirmed');
    if ($public['format'] === 'hybrid' && $public['venue']) $location .= ', Hybrid';

    return [
        'id'        => $public['slug'],
        'title'     => $public['title'],
        'type'      => $public['type'],
        'date'      => date('Y-m-d', $start),
        'time'      => $time,
        'location'  => $location,
        'cpdPoints' => (int)round((float)($public['points'] ?? 0)),
        'fee'       => $public['memberFee'],
        'currency'  => $public['currency'],
    ];
}
