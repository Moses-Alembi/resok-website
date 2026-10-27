<?php
declare(strict_types=1);

/**
 * Field work: what staff do outside the office, and the daily, weekly and monthly reports
 * on it.
 *
 * Rules that shape it:
 *
 * 1. Location only at two moments. "Check in" and "Check out" each record the phone's
 *    position once, if the person allows it; nothing is tracked in between, and an activity
 *    logged afterwards carries no position at all and says so ("logged later"). Continuous
 *    tracking was considered and rejected (Moses, 27 Oct 2026): it would need written
 *    consent and an impact assessment under the Data Protection Act, and a web page cannot
 *    do it in the background anyway.
 *
 * 2. Times and positions are the system's, not the person's. A check-in's start and a
 *    check-out's end are stamped by the server; the owner can correct the description of an
 *    activity, never when or where the system recorded it.
 *
 * 3. Need to see. Everyone sees their own. A manager sees the people who report to them,
 *    directly or further down the line (Staff records). Staff managers (HR) and super admins
 *    see everyone. The check is made on every request: knowing someone's id is not enough.
 */

const FIELD_ACTIVITY_TYPES = [
    'training'        => 'Training',
    'outreach'        => 'Outreach / awareness',
    'site_visit'      => 'Site visit',
    'meeting'         => 'Meeting',
    'data_collection' => 'Data collection',
    'clinic_support'  => 'Clinic support',
    'event'           => 'Event',
    'other'           => 'Other',
];

const FIELD_COUNTIES = ['Baringo', 'Bomet', 'Bungoma', 'Busia', 'Elgeyo-Marakwet', 'Embu', 'Garissa', 'Homa Bay', 'Isiolo',
    'Kajiado', 'Kakamega', 'Kericho', 'Kiambu', 'Kilifi', 'Kirinyaga', 'Kisii', 'Kisumu', 'Kitui', 'Kwale', 'Laikipia', 'Lamu',
    'Machakos', 'Makueni', 'Mandera', 'Marsabit', 'Meru', 'Migori', 'Mombasa', "Murang'a", 'Nairobi', 'Nakuru', 'Nandi', 'Narok',
    'Nyamira', 'Nyandarua', 'Nyeri', 'Samburu', 'Siaya', 'Taita-Taveta', 'Tana River', 'Tharaka-Nithi', 'Trans Nzoia', 'Turkana',
    'Uasin Gishu', 'Vihiga', 'Wajir', 'West Pokot', 'Outside Kenya'];

function fieldEnsureTables(PDO $pdo): bool
{
    static $ready = null;
    if ($ready !== null) return $ready;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS staff_field_activities (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id INT UNSIGNED NOT NULL,
            activity_date DATE NOT NULL,
            activity_type VARCHAR(40) NOT NULL,
            title VARCHAR(200) NOT NULL,
            place VARCHAR(160) NULL,
            county VARCHAR(40) NULL,
            project VARCHAR(120) NULL,
            summary TEXT NULL,
            started_at DATETIME NULL,
            ended_at DATETIME NULL,
            checkin_lat DECIMAL(9,6) NULL,
            checkin_lng DECIMAL(9,6) NULL,
            checkin_accuracy_m INT UNSIGNED NULL,
            checkout_lat DECIMAL(9,6) NULL,
            checkout_lng DECIMAL(9,6) NULL,
            checkout_accuracy_m INT UNSIGNED NULL,
            source ENUM('checkin','logged') NOT NULL,
            status ENUM('in_progress','completed') NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY staff_field_person (user_id, activity_date),
            KEY staff_field_date (activity_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        return $ready = true;
    } catch (Throwable $e) {
        error_log('Field activity table unavailable: ' . $e->getMessage());
        return $ready = false;
    }
}

/**
 * Whose field work this user may see: [user id => name]. Themselves; everyone below them in
 * the reporting line; or, for HR and super admins, all staff.
 */
function fieldVisiblePeople(PDO $pdo, int $me, bool $seesAll): array
{
    $rows = $pdo->query('SELECT s.user_id, s.manager_user_id, s.active, COALESCE(NULLIF(s.full_name, \'\'), u.email) AS name
                         FROM staff_accounts s JOIN users u ON u.id = s.user_id')->fetchAll();
    $names = [];
    $reports = [];
    foreach ($rows as $r) {
        $names[(int)$r['user_id']] = ['name' => $r['name'], 'active' => (bool)$r['active']];
        if ($r['manager_user_id'] !== null) $reports[(int)$r['manager_user_id']][] = (int)$r['user_id'];
    }
    if ($seesAll) {
        $out = [];
        foreach ($names as $id => $n) $out[$id] = $n['name'];
        asort($out, SORT_NATURAL | SORT_FLAG_CASE);
        return $out;
    }
    // Down the line, breadth first; the seen-set ends any loop a bad record could make.
    $out = isset($names[$me]) ? [$me => $names[$me]['name']] : [];
    $queue = [$me];
    while ($queue) {
        $id = array_shift($queue);
        foreach ($reports[$id] ?? [] as $child) {
            if (isset($out[$child])) continue;
            $out[$child] = $names[$child]['name'];
            $queue[] = $child;
        }
    }
    return $out;
}

function fieldText($value, int $max): ?string
{
    $text = trim((string)preg_replace('/[ \t]+/u', ' ', is_scalar($value) ? str_replace(["\r\n", "\r"], "\n", (string)$value) : ''));
    return $text === '' ? null : mb_substr($text, 0, $max);
}

/** A position from the browser, or nulls. Out-of-range or absurdly vague readings are dropped. */
function fieldPosition($pos): array
{
    if (!is_array($pos) || !is_numeric($pos['lat'] ?? null) || !is_numeric($pos['lng'] ?? null)) return [null, null, null];
    $lat = (float)$pos['lat'];
    $lng = (float)$pos['lng'];
    $acc = is_numeric($pos['accuracy'] ?? null) ? (int)round((float)$pos['accuracy']) : null;
    if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) return [null, null, null];
    if ($acc !== null && ($acc < 0 || $acc > 100000)) $acc = null;
    return [round($lat, 6), round($lng, 6), $acc];
}

/** Checks the descriptive fields shared by check-in, logging and editing. */
function fieldDescribe(array $data, bool $requireTitle = true): array
{
    $type = (string)($data['activityType'] ?? '');
    if (!isset(FIELD_ACTIVITY_TYPES[$type])) return [null, 'Choose the type of activity.'];
    $title = fieldText($data['title'] ?? '', 200);
    if ($requireTitle && $title === null) return [null, 'Say in a few words what the activity is.'];
    $county = fieldText($data['county'] ?? '', 40);
    if ($county !== null && !in_array($county, FIELD_COUNTIES, true)) return [null, 'Choose the county from the list.'];
    return [[
        'activity_type' => $type, 'title' => $title, 'place' => fieldText($data['place'] ?? '', 160),
        'county' => $county, 'project' => fieldText($data['project'] ?? '', 120),
    ], null];
}

function fieldShape(array $r, bool $withPosition): array
{
    $mins = ($r['started_at'] && $r['ended_at']) ? max(0, (int)round((strtotime($r['ended_at']) - strtotime($r['started_at'])) / 60)) : null;
    $out = [
        'id' => (int)$r['id'], 'userId' => (int)$r['user_id'], 'date' => $r['activity_date'],
        'type' => $r['activity_type'], 'typeLabel' => FIELD_ACTIVITY_TYPES[$r['activity_type']] ?? $r['activity_type'],
        'title' => $r['title'], 'place' => $r['place'], 'county' => $r['county'], 'project' => $r['project'],
        'summary' => $r['summary'], 'startedAt' => $r['started_at'], 'endedAt' => $r['ended_at'], 'minutes' => $mins,
        'source' => $r['source'], 'status' => $r['status'],
    ];
    if ($withPosition) {
        $out['checkin'] = $r['checkin_lat'] !== null ? ['lat' => (float)$r['checkin_lat'], 'lng' => (float)$r['checkin_lng'], 'accuracy' => $r['checkin_accuracy_m'] !== null ? (int)$r['checkin_accuracy_m'] : null] : null;
        $out['checkout'] = $r['checkout_lat'] !== null ? ['lat' => (float)$r['checkout_lat'], 'lng' => (float)$r['checkout_lng'], 'accuracy' => $r['checkout_accuracy_m'] !== null ? (int)$r['checkout_accuracy_m'] : null] : null;
    }
    return $out;
}

/** The first and last day of the period containing $date: a day, a Monday-to-Sunday week, or a month. */
function fieldPeriod(string $period, string $date): ?array
{
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    if (!$d || $d->format('Y-m-d') !== $date) return null;
    if ($period === 'day') return [$date, $date, $d->format('l j F Y')];
    if ($period === 'week') {
        $start = $d->modify('-' . ((int)$d->format('N') - 1) . ' days');
        $end = $start->modify('+6 days');
        return [$start->format('Y-m-d'), $end->format('Y-m-d'), 'Week of ' . $start->format('j M') . ' to ' . $end->format('j M Y')];
    }
    if ($period === 'month') {
        return [$d->format('Y-m-01'), $d->format('Y-m-t'), $d->format('F Y')];
    }
    return null;
}

/** One person's report for a period: every activity, and the totals a manager reads first. */
function fieldReport(PDO $pdo, int $userId, string $from, string $to, bool $withPosition): array
{
    $stmt = $pdo->prepare('SELECT * FROM staff_field_activities WHERE user_id = ? AND activity_date BETWEEN ? AND ?
                           ORDER BY activity_date, COALESCE(started_at, created_at), id');
    $stmt->execute([$userId, $from, $to]);
    $items = array_map(static fn($r) => fieldShape($r, $withPosition), $stmt->fetchAll());
    $byType = [];
    $days = [];
    $counties = [];
    $minutes = 0;
    foreach ($items as $a) {
        $byType[$a['typeLabel']] = ($byType[$a['typeLabel']] ?? 0) + 1;
        $days[$a['date']] = true;
        if ($a['county']) $counties[$a['county']] = true;
        $minutes += (int)($a['minutes'] ?? 0);
    }
    arsort($byType);
    return [
        'activities' => $items,
        'totals' => [
            'activities' => count($items), 'daysInField' => count($days), 'minutes' => $minutes,
            'counties' => array_keys($counties), 'byType' => $byType,
            'loggedLater' => count(array_filter($items, static fn($a) => $a['source'] === 'logged')),
            'stillOpen' => count(array_filter($items, static fn($a) => $a['status'] === 'in_progress')),
        ],
    ];
}

function fieldHandle(PDO $pdo, array $config, string $route, string $method, array $user, array $gate): void
{
    if (!fieldEnsureTables($pdo)) respond(503, ['error' => 'The field work table could not be created.']);
    $me = (int)$user['userId'];
    $seesAll = in_array('staff.manage', $gate['caps'], true) || $gate['super'];
    $people = fieldVisiblePeople($pdo, $me, $seesAll);
    $onList = (bool)$gate['row'];

    $own = function (int $id) use ($pdo, $me): array {
        $stmt = $pdo->prepare('SELECT * FROM staff_field_activities WHERE id = ? AND user_id = ?');
        $stmt->execute([$id, $me]);
        $row = $stmt->fetch();
        if (!$row) respond(404, ['error' => 'No such activity of yours.']);
        return $row;
    };
    $openOne = function () use ($pdo, $me): ?array {
        $stmt = $pdo->prepare("SELECT * FROM staff_field_activities WHERE user_id = ? AND status = 'in_progress' ORDER BY id DESC LIMIT 1");
        $stmt->execute([$me]);
        return $stmt->fetch() ?: null;
    };

    // ----- my field work -----
    if ($route === 'staff/field' && $method === 'GET') {
        $recent = $pdo->prepare('SELECT * FROM staff_field_activities WHERE user_id = ? ORDER BY activity_date DESC, id DESC LIMIT 30');
        $recent->execute([$me]);
        $open = $openOne();
        respond(200, [
            'types' => FIELD_ACTIVITY_TYPES, 'counties' => FIELD_COUNTIES,
            'open' => $open ? fieldShape($open, true) : null,
            'recent' => array_map(static fn($r) => fieldShape($r, true), $recent->fetchAll()),
            'people' => array_map(static fn($id, $name) => ['userId' => $id, 'name' => $name, 'me' => $id === $me], array_keys($people), $people),
            'seesAll' => $seesAll, 'onStaffList' => $onList,
        ]);
    }

    if ($route === 'staff/field/checkin' && $method === 'POST') {
        if (!$onList) respond(403, ['error' => 'Only staff record field work.']);
        if ($openOne()) respond(400, ['error' => 'You are already checked in. Check out of that activity first.']);
        $data = input();
        [$d, $error] = fieldDescribe($data);
        if ($error) respond(400, ['error' => $error]);
        [$lat, $lng, $acc] = fieldPosition($data['position'] ?? null);
        $pdo->prepare("INSERT INTO staff_field_activities (user_id, activity_date, activity_type, title, place, county, project,
                         started_at, checkin_lat, checkin_lng, checkin_accuracy_m, source, status)
                       VALUES (?, CURDATE(), ?, ?, ?, ?, ?, NOW(), ?, ?, ?, 'checkin', 'in_progress')")
            ->execute([$me, $d['activity_type'], $d['title'], $d['place'], $d['county'], $d['project'], $lat, $lng, $acc]);
        respond(201, ['activity' => fieldShape($own((int)$pdo->lastInsertId()), true)]);
    }

    if ($route === 'staff/field/checkout' && $method === 'POST') {
        $open = $openOne();
        if (!$open) respond(400, ['error' => 'You are not checked in to anything.']);
        $data = input();
        $summary = fieldText($data['summary'] ?? '', 4000);
        if ($summary === null) respond(400, ['error' => 'Write a short summary of what was done before checking out.']);
        [$lat, $lng, $acc] = fieldPosition($data['position'] ?? null);
        $pdo->prepare("UPDATE staff_field_activities SET summary = ?, ended_at = NOW(), checkout_lat = ?, checkout_lng = ?,
                         checkout_accuracy_m = ?, status = 'completed' WHERE id = ? AND status = 'in_progress'")
            ->execute([$summary, $lat, $lng, $acc, (int)$open['id']]);
        respond(200, ['activity' => fieldShape($own((int)$open['id']), true)]);
    }

    // An activity recorded afterwards: the person states the times; no position, marked "logged later".
    if ($route === 'staff/field/log' && $method === 'POST') {
        if (!$onList) respond(403, ['error' => 'Only staff record field work.']);
        $data = input();
        [$d, $error] = fieldDescribe($data);
        if ($error) respond(400, ['error' => $error]);
        $date = (string)($data['date'] ?? '');
        $day = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$day || $day->format('Y-m-d') !== $date) respond(400, ['error' => 'Give the date of the activity.']);
        $today = new DateTimeImmutable('today');
        if ($day > $today) respond(400, ['error' => 'An activity cannot be logged for a future date.']);
        if ($day < $today->modify('-31 days')) respond(400, ['error' => 'Activities more than a month old cannot be logged here. Ask HR.']);
        $times = [];
        foreach (['startTime', 'endTime'] as $k) {
            $t = (string)($data[$k] ?? '');
            if ($t === '') { $times[$k] = null; continue; }
            if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t)) respond(400, ['error' => 'Give times as hours and minutes, e.g. 09:30.']);
            $times[$k] = $date . ' ' . $t . ':00';
        }
        if ($times['startTime'] && $times['endTime'] && $times['endTime'] <= $times['startTime']) respond(400, ['error' => 'The activity must end after it starts.']);
        $summary = fieldText($data['summary'] ?? '', 4000);
        if ($summary === null) respond(400, ['error' => 'Write a short summary of what was done.']);
        $pdo->prepare("INSERT INTO staff_field_activities (user_id, activity_date, activity_type, title, place, county, project, summary,
                         started_at, ended_at, source, status)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'logged', 'completed')")
            ->execute([$me, $date, $d['activity_type'], $d['title'], $d['place'], $d['county'], $d['project'], $summary, $times['startTime'], $times['endTime']]);
        respond(201, ['activity' => fieldShape($own((int)$pdo->lastInsertId()), true)]);
    }

    // The owner corrects the description; when and where the system recorded it stays as it was.
    if (preg_match('#^staff/field/(\d+)$#', $route, $m) && $method === 'POST') {
        $row = $own((int)$m[1]);
        $data = input();
        [$d, $error] = fieldDescribe($data);
        if ($error) respond(400, ['error' => $error]);
        $summary = fieldText($data['summary'] ?? '', 4000);
        if ($row['status'] === 'completed' && $summary === null) respond(400, ['error' => 'Keep a short summary of what was done.']);
        $pdo->prepare('UPDATE staff_field_activities SET activity_type = ?, title = ?, place = ?, county = ?, project = ?, summary = ? WHERE id = ?')
            ->execute([$d['activity_type'], $d['title'], $d['place'], $d['county'], $d['project'], $summary, (int)$row['id']]);
        respond(200, ['activity' => fieldShape($own((int)$row['id']), true)]);
    }

    // A mistaken entry can be removed by its owner on the day it was made, not afterwards.
    if (preg_match('#^staff/field/(\d+)/delete$#', $route, $m) && $method === 'POST') {
        $row = $own((int)$m[1]);
        if (substr((string)$row['created_at'], 0, 10) !== date('Y-m-d')) {
            respond(400, ['error' => 'Only an entry made today can be deleted. Correct it instead, or ask HR.']);
        }
        $pdo->prepare('DELETE FROM staff_field_activities WHERE id = ? AND user_id = ?')->execute([(int)$row['id'], $me]);
        staffAudit($pdo, $me, 'field_activity_deleted', 'field', (string)$row['id'], $row['title']);
        respond(200, ['ok' => true]);
    }

    // ----- reports -----
    $periodOf = function () {
        $period = (string)($_GET['period'] ?? 'week');
        $date = (string)($_GET['date'] ?? date('Y-m-d'));
        $p = fieldPeriod($period, $date);
        if (!$p) respond(400, ['error' => 'Choose a day, week or month and a valid date.']);
        return [$period, $p];
    };

    if ($route === 'staff/field/report' && $method === 'GET') {
        [$period, [$from, $to, $label]] = $periodOf();
        $who = (int)($_GET['userId'] ?? $me);
        if (!isset($people[$who])) respond(403, ['error' => 'You can see your own field reports and those of the people who report to you.']);
        $stmt = $pdo->prepare('SELECT s.full_name, s.job_title, s.staff_number, d.name AS department, m.full_name AS manager, u.email
                               FROM staff_accounts s JOIN users u ON u.id = s.user_id
                               LEFT JOIN staff_departments d ON d.id = s.department_id
                               LEFT JOIN staff_accounts m ON m.user_id = s.manager_user_id WHERE s.user_id = ?');
        $stmt->execute([$who]);
        $p = $stmt->fetch() ?: [];
        if ($who !== $me) staffAudit($pdo, $me, 'field_report_viewed', 'staff', (string)$who, $period . ' ' . $from);
        respond(200, [
            'period' => $period, 'from' => $from, 'to' => $to, 'label' => $label,
            'person' => ['userId' => $who, 'name' => $people[$who], 'jobTitle' => $p['job_title'] ?? null, 'department' => $p['department'] ?? null,
                         'manager' => $p['manager'] ?? null, 'staffNumber' => $p['staff_number'] ?? null, 'email' => $p['email'] ?? null],
        ] + fieldReport($pdo, $who, $from, $to, true));
    }

    // Everyone this person may see, side by side for the period: who was out, how much, where.
    if ($route === 'staff/field/team' && $method === 'GET') {
        [$period, [$from, $to, $label]] = $periodOf();
        $ids = array_keys($people);
        $rows = [];
        if ($ids) {
            $stmt = $pdo->prepare('SELECT user_id, COUNT(*) AS n, COUNT(DISTINCT activity_date) AS days,
                                          COALESCE(SUM(CASE WHEN started_at IS NOT NULL AND ended_at IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, started_at, ended_at) END), 0) AS mins,
                                          GROUP_CONCAT(DISTINCT county ORDER BY county SEPARATOR \', \') AS counties,
                                          SUM(source = \'logged\') AS later
                                   FROM staff_field_activities WHERE activity_date BETWEEN ? AND ? AND user_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')
                                   GROUP BY user_id');
            $stmt->execute(array_merge([$from, $to], $ids));
            foreach ($stmt->fetchAll() as $r) $rows[(int)$r['user_id']] = $r;
        }
        $team = [];
        foreach ($people as $id => $name) {
            $r = $rows[$id] ?? null;
            $team[] = ['userId' => $id, 'name' => $name, 'activities' => (int)($r['n'] ?? 0), 'daysInField' => (int)($r['days'] ?? 0),
                       'minutes' => (int)($r['mins'] ?? 0), 'counties' => $r['counties'] ?? '', 'loggedLater' => (int)($r['later'] ?? 0)];
        }
        if (!empty($_GET['csv'])) {
            staffAudit($pdo, $me, 'field_team_exported', 'field', null, $period . ' ' . $from);
            header_remove('Content-Type');
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="field-work-' . $period . '-' . $from . '.csv"');
            header('Cache-Control: private, no-store');
            $fh = fopen('php://temp', 'w+');
            fputcsv($fh, ['Staff', 'Period', 'Activities', 'Days in the field', 'Hours', 'Counties', 'Logged later']);
            $safe = static fn($v) => is_string($v) && $v !== '' && strpbrk($v[0], '=+-@') !== false ? "'" . $v : $v;
            foreach ($team as $t) fputcsv($fh, array_map($safe, [$t['name'], $label, $t['activities'], $t['daysInField'], round($t['minutes'] / 60, 1), $t['counties'], $t['loggedLater']]));
            rewind($fh);
            echo "\xEF\xBB\xBF" . stream_get_contents($fh);
            exit;
        }
        respond(200, ['period' => $period, 'from' => $from, 'to' => $to, 'label' => $label, 'team' => $team]);
    }

    respond(404, ['error' => 'Route not found']);
}
