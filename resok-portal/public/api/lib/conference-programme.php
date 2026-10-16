<?php
declare(strict_types=1);

/**
 * The conference programme (platform blueprint module 7; schematic: Abstracts to programme).
 *
 * Rooms, sessions on each day, and the items inside a session. An item is either an accepted
 * abstract, placed as an oral presentation or on a numbered poster board, or an invited talk
 * with a speaker. The programme is built privately and shown to delegates only once it is
 * published, so a half-built timetable is never mistaken for the final one.
 *
 * An abstract can be placed once. The builder lists accepted abstracts not yet placed, so the
 * committee can see what is left, and marks those whose presenter has not registered: the
 * schematic confirms an abstract in the programme only once its presenter is registered.
 */

const CONF_SESSION_KINDS = ['plenary' => 'Plenary', 'symposium' => 'Symposium', 'oral' => 'Oral abstracts',
                            'poster' => 'Posters', 'workshop' => 'Workshop', 'break' => 'Break', 'other' => 'Other'];

function confProgrammeSchema(): array
{
    $tail = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    return [
        "CREATE TABLE IF NOT EXISTS conf_programme_state (
            event_id INT UNSIGNED NOT NULL,
            published TINYINT(1) NOT NULL DEFAULT 0,
            published_at DATETIME NULL,
            PRIMARY KEY (event_id)
        )" . $tail,
        "CREATE TABLE IF NOT EXISTS conf_rooms (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            event_id INT UNSIGNED NOT NULL,
            name VARCHAR(120) NOT NULL,
            capacity INT UNSIGNED NULL,
            sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            KEY conf_rooms_event (event_id, sort_order)
        )" . $tail,
        "CREATE TABLE IF NOT EXISTS conf_sessions (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            event_id INT UNSIGNED NOT NULL,
            day DATE NOT NULL,
            starts_at TIME NOT NULL,
            ends_at TIME NOT NULL,
            room_id INT UNSIGNED NULL,
            kind VARCHAR(20) NOT NULL DEFAULT 'other',
            title VARCHAR(200) NOT NULL,
            chairs VARCHAR(300) NULL,
            description TEXT NULL,
            PRIMARY KEY (id),
            KEY conf_sessions_event_day (event_id, day, starts_at)
        )" . $tail,
        // abstract_id is unique: an abstract is presented once.
        "CREATE TABLE IF NOT EXISTS conf_session_items (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            session_id INT UNSIGNED NOT NULL,
            abstract_id INT UNSIGNED NULL,
            title VARCHAR(300) NULL,
            speaker VARCHAR(200) NULL,
            starts_at TIME NULL,
            board VARCHAR(12) NULL,
            sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY conf_session_items_abstract (abstract_id),
            KEY conf_session_items_session (session_id, sort_order)
        )" . $tail,
    ];
}

function confProgrammeEnsureTables(PDO $pdo): bool
{
    static $ready = null;
    if ($ready !== null) return $ready;
    try {
        foreach (confProgrammeSchema() as $sql) $pdo->exec($sql);
        return $ready = true;
    } catch (Throwable $e) {
        error_log('Programme tables unavailable: ' . $e->getMessage());
        return $ready = false;
    }
}

function confProgrammePublished(PDO $pdo, int $eventId): bool
{
    $stmt = $pdo->prepare('SELECT published FROM conf_programme_state WHERE event_id = ?');
    $stmt->execute([$eventId]);
    return (bool)$stmt->fetchColumn();
}

function confProgrammeSetPublished(PDO $pdo, int $eventId, bool $published, int $by): void
{
    $pdo->prepare('INSERT INTO conf_programme_state (event_id, published, published_at) VALUES (?, ?, IF(?, NOW(), NULL))
                   ON DUPLICATE KEY UPDATE published = VALUES(published), published_at = VALUES(published_at)')
        ->execute([$eventId, $published ? 1 : 0, $published ? 1 : 0]);
    if (function_exists('confAudit')) confAudit($pdo, $eventId, null, $by, $published ? 'programme_published' : 'programme_unpublished');
}

function confRooms(PDO $pdo, int $eventId): array
{
    $stmt = $pdo->prepare('SELECT * FROM conf_rooms WHERE event_id = ? ORDER BY sort_order, id');
    $stmt->execute([$eventId]);
    return array_map(fn($r) => ['id' => (int)$r['id'], 'name' => $r['name'], 'capacity' => $r['capacity'] !== null ? (int)$r['capacity'] : null,
                                'sortOrder' => (int)$r['sort_order']], $stmt->fetchAll());
}

/** @return ?string an error, or null */
function confSaveRoom(PDO $pdo, int $eventId, array $data): ?string
{
    $name = trim((string)($data['name'] ?? ''));
    if ($name === '' || mb_strlen($name) > 120) return 'Give the room a name.';
    $cap = isset($data['capacity']) && $data['capacity'] !== '' && $data['capacity'] !== null ? max(0, (int)$data['capacity']) : null;
    $id = (int)($data['id'] ?? 0);
    if ($id) {
        $pdo->prepare('UPDATE conf_rooms SET name = ?, capacity = ?, sort_order = ? WHERE id = ? AND event_id = ?')
            ->execute([$name, $cap, max(0, (int)($data['sortOrder'] ?? 0)), $id, $eventId]);
    } else {
        $pdo->prepare('INSERT INTO conf_rooms (event_id, name, capacity, sort_order) VALUES (?, ?, ?, ?)')
            ->execute([$eventId, $name, $cap, max(0, (int)($data['sortOrder'] ?? 0))]);
    }
    return null;
}

/**
 * The whole programme, day by day, sessions in time order with their items. Abstract titles
 * and presenters are read live from the abstract, so a correction there shows here.
 */
function confProgramme(PDO $pdo, int $eventId, bool $forStaff = false): array
{
    $rooms = [];
    foreach (confRooms($pdo, $eventId) as $r) $rooms[$r['id']] = $r['name'];
    $s = $pdo->prepare('SELECT * FROM conf_sessions WHERE event_id = ? ORDER BY day, starts_at, room_id, id');
    $s->execute([$eventId]);
    $sessions = $s->fetchAll();
    $items = [];
    if ($sessions) {
        $ids = implode(',', array_map(fn($x) => (int)$x['id'], $sessions));
        $rows = $pdo->query("SELECT i.*, a.reference, a.title AS abstract_title, a.status AS abstract_status,
                                    (SELECT CONCAT_WS(' ', au.first_name, au.last_name) FROM abs_authors au WHERE au.abstract_id = a.id AND au.is_presenting = 1 LIMIT 1) AS presenter,
                                    (SELECT au.affiliation FROM abs_authors au WHERE au.abstract_id = a.id AND au.is_presenting = 1 LIMIT 1) AS affiliation
                             FROM conf_session_items i LEFT JOIN abs_abstracts a ON a.id = i.abstract_id
                             WHERE i.session_id IN ($ids) ORDER BY i.sort_order, i.starts_at, i.id")->fetchAll();
        foreach ($rows as $i) {
            if (!$forStaff && $i['abstract_id'] !== null && $i['abstract_status'] === 'withdrawn') continue;
            $items[(int)$i['session_id']][] = [
                'id' => (int)$i['id'],
                'abstractId' => $i['abstract_id'] !== null ? (int)$i['abstract_id'] : null,
                'reference' => $i['reference'],
                'title' => $i['abstract_id'] !== null ? (function_exists('absPlain') ? absPlain($i['abstract_title']) : (string)$i['abstract_title']) : $i['title'],
                'speaker' => $i['abstract_id'] !== null ? $i['presenter'] : $i['speaker'],
                'affiliation' => $i['abstract_id'] !== null ? $i['affiliation'] : null,
                'startsAt' => $i['starts_at'] ? substr((string)$i['starts_at'], 0, 5) : null,
                'board' => $i['board'],
                'withdrawn' => $i['abstract_status'] === 'withdrawn',
            ];
        }
    }
    $attended = [];
    if ($forStaff && $sessions) {
        try {
            $ids = implode(',', array_map(fn($x) => (int)$x['id'], $sessions));
            foreach ($pdo->query("SELECT session_id, COUNT(DISTINCT registration_id) n FROM conf_scans WHERE session_id IN ($ids) AND result = 'ok' GROUP BY session_id")->fetchAll() as $a) {
                $attended[(int)$a['session_id']] = (int)$a['n'];
            }
        } catch (Throwable $e) {
            // Scans table missing: no counts.
        }
    }
    $days = [];
    foreach ($sessions as $x) {
        $days[$x['day']][] = [
            'id' => (int)$x['id'], 'day' => $x['day'], 'startsAt' => substr((string)$x['starts_at'], 0, 5), 'endsAt' => substr((string)$x['ends_at'], 0, 5),
            'roomId' => $x['room_id'] !== null ? (int)$x['room_id'] : null, 'room' => $x['room_id'] !== null ? ($rooms[(int)$x['room_id']] ?? null) : null,
            'kind' => $x['kind'], 'kindLabel' => CONF_SESSION_KINDS[$x['kind']] ?? 'Other', 'title' => $x['title'],
            'chairs' => $x['chairs'], 'description' => $x['description'], 'items' => $items[(int)$x['id']] ?? [],
        ] + ($forStaff ? ['attended' => $attended[(int)$x['id']] ?? 0] : []);
    }
    $out = [];
    foreach ($days as $day => $list) $out[] = ['day' => $day, 'sessions' => $list];
    return $out;
}

/** @return array{0: ?int, 1: ?string} */
function confSaveSession(PDO $pdo, int $eventId, array $data): array
{
    $day = trim((string)($data['day'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) return [null, 'Choose the day.'];
    $start = trim((string)($data['startsAt'] ?? ''));
    $end = trim((string)($data['endsAt'] ?? ''));
    if (!preg_match('/^\d{2}:\d{2}$/', $start) || !preg_match('/^\d{2}:\d{2}$/', $end)) return [null, 'Times must look like 09:30.'];
    if ($end <= $start) return [null, 'The session must end after it starts.'];
    $title = trim((string)($data['title'] ?? ''));
    if ($title === '' || mb_strlen($title) > 200) return [null, 'Give the session a title.'];
    $kind = (string)($data['kind'] ?? 'other');
    if (!array_key_exists($kind, CONF_SESSION_KINDS)) return [null, 'Unknown session type.'];
    $roomId = (int)($data['roomId'] ?? 0) ?: null;
    if ($roomId) {
        $check = $pdo->prepare('SELECT 1 FROM conf_rooms WHERE id = ? AND event_id = ?');
        $check->execute([$roomId, $eventId]);
        if (!$check->fetch()) return [null, 'Unknown room.'];
        // Two sessions in one room at the same time is the mistake worth catching here.
        $clash = $pdo->prepare('SELECT title FROM conf_sessions WHERE event_id = ? AND room_id = ? AND day = ? AND id <> ?
                                AND starts_at < ? AND ends_at > ? LIMIT 1');
        $clash->execute([$eventId, $roomId, $day, (int)($data['id'] ?? 0), $end . ':00', $start . ':00']);
        if ($other = $clash->fetchColumn()) return [null, "That room already has \"{$other}\" at that time."];
    }
    $values = [$day, $start . ':00', $end . ':00', $roomId, $kind, $title,
               mb_substr(trim((string)($data['chairs'] ?? '')), 0, 300) ?: null, trim((string)($data['description'] ?? '')) ?: null];
    $id = (int)($data['id'] ?? 0);
    if ($id) {
        $stmt = $pdo->prepare('UPDATE conf_sessions SET day = ?, starts_at = ?, ends_at = ?, room_id = ?, kind = ?, title = ?, chairs = ?, description = ? WHERE id = ? AND event_id = ?');
        $stmt->execute(array_merge($values, [$id, $eventId]));
    } else {
        $pdo->prepare('INSERT INTO conf_sessions (day, starts_at, ends_at, room_id, kind, title, chairs, description, event_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute(array_merge($values, [$eventId]));
        $id = (int)$pdo->lastInsertId();
    }
    return [$id, null];
}

function confDeleteSession(PDO $pdo, int $eventId, int $sessionId): ?string
{
    $check = $pdo->prepare('SELECT 1 FROM conf_sessions WHERE id = ? AND event_id = ?');
    $check->execute([$sessionId, $eventId]);
    if (!$check->fetch()) return 'No such session.';
    // Its abstracts go back to the unplaced list.
    $pdo->prepare('DELETE FROM conf_session_items WHERE session_id = ?')->execute([$sessionId]);
    $pdo->prepare('DELETE FROM conf_sessions WHERE id = ?')->execute([$sessionId]);
    return null;
}

/** @return ?string an error, or null */
function confAddItem(PDO $pdo, int $eventId, int $sessionId, array $data): ?string
{
    $check = $pdo->prepare('SELECT kind FROM conf_sessions WHERE id = ? AND event_id = ?');
    $check->execute([$sessionId, $eventId]);
    $kind = $check->fetchColumn();
    if ($kind === false) return 'No such session.';
    $abstractId = (int)($data['abstractId'] ?? 0) ?: null;
    $title = trim((string)($data['title'] ?? ''));
    $speaker = trim((string)($data['speaker'] ?? ''));
    $start = trim((string)($data['startsAt'] ?? ''));
    if ($start !== '' && !preg_match('/^\d{2}:\d{2}$/', $start)) return 'Times must look like 09:30.';
    $board = mb_substr(trim((string)($data['board'] ?? '')), 0, 12) ?: null;
    if ($abstractId) {
        $a = $pdo->prepare("SELECT a.id FROM abs_abstracts a JOIN abs_decisions d ON d.abstract_id = a.id
                            WHERE a.id = ? AND a.event_id = ? AND d.outcome = 'accept' AND a.status <> 'withdrawn'");
        $a->execute([$abstractId, $eventId]);
        if (!$a->fetch()) return 'Only accepted abstracts can be placed in the programme.';
        if ($kind === 'poster' && !$board) return 'Give the poster a board number.';
        $title = null;
        $speaker = null;
    } elseif ($title === '') {
        return 'Choose an accepted abstract, or give the talk a title.';
    }
    try {
        $pdo->prepare('INSERT INTO conf_session_items (session_id, abstract_id, title, speaker, starts_at, board, sort_order)
                       VALUES (?, ?, ?, ?, ?, ?, (SELECT COALESCE(MAX(x.sort_order), -1) + 1 FROM conf_session_items x WHERE x.session_id = ?))')
            ->execute([$sessionId, $abstractId, $title !== '' ? mb_substr((string)$title, 0, 300) : null, $speaker !== '' ? mb_substr((string)$speaker, 0, 200) : null,
                       $start !== '' ? $start . ':00' : null, $board, $sessionId]);
    } catch (PDOException $e) {
        if ((int)($e->errorInfo[1] ?? 0) === 1062) return 'That abstract is already placed in the programme.';
        throw $e;
    }
    return null;
}

function confRemoveItem(PDO $pdo, int $eventId, int $itemId): ?string
{
    $stmt = $pdo->prepare('DELETE i FROM conf_session_items i JOIN conf_sessions s ON s.id = i.session_id WHERE i.id = ? AND s.event_id = ?');
    $stmt->execute([$itemId, $eventId]);
    return $stmt->rowCount() ? null : 'No such item.';
}

/**
 * Accepted abstracts not yet in the programme, with the type they were accepted for and
 * whether the presenter has registered.
 */
function confUnplacedAbstracts(PDO $pdo, int $eventId): array
{
    $stmt = $pdo->prepare("SELECT a.id, a.reference, a.title, d.presentation_type, t.name AS track,
                                  au.first_name, au.last_name, LOWER(au.email) AS email,
                                  (SELECT r.status FROM conf_registrations r JOIN users u ON u.id = r.user_id
                                   WHERE r.event_id = a.event_id AND r.status <> 'cancelled' AND LOWER(u.email) = LOWER(au.email) LIMIT 1) AS reg_status
                           FROM abs_abstracts a
                           JOIN abs_decisions d ON d.abstract_id = a.id
                           LEFT JOIN abs_tracks t ON t.id = a.track_id
                           LEFT JOIN abs_authors au ON au.abstract_id = a.id AND au.is_presenting = 1
                           WHERE a.event_id = ? AND d.outcome = 'accept' AND a.status <> 'withdrawn'
                             AND NOT EXISTS (SELECT 1 FROM conf_session_items i WHERE i.abstract_id = a.id)
                           ORDER BY d.presentation_type, t.sort_order, a.reference");
    $stmt->execute([$eventId]);
    return array_map(fn($r) => [
        'id' => (int)$r['id'], 'reference' => $r['reference'],
        'title' => function_exists('absPlain') ? absPlain($r['title']) : (string)$r['title'],
        'type' => $r['presentation_type'], 'track' => $r['track'],
        'presenter' => trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')),
        'presenterRegistration' => $r['reg_status'] ?? 'none',
    ], $stmt->fetchAll());
}
