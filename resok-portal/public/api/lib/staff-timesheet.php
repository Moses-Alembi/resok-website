<?php
declare(strict_types=1);

/**
 * Timesheets: the hours each member of staff works in a week, by project or grant, approved
 * by their manager. The monthly report is the level-of-effort record donors ask for.
 *
 * Rules that shape it:
 *
 * 1. One sheet per person per week, Monday to Sunday. Hours go against a charge code (a
 *    project, a grant, or an internal code such as administration or leave), in quarter hours,
 *    never more than 24 in a day.
 *
 * 2. Draft, submitted, then approved or returned. The person's manager (Staff records)
 *    decides; someone with no manager on record is decided by HR (staff.manage). Nobody
 *    decides their own sheet. Only a draft or a returned sheet can be changed; an approved
 *    one is reopened only by HR, with a reason, and that is logged.
 *
 * 3. Need to see, as for field work: your own; your reporting line's; everyone's for HR and
 *    super admins. Checked on every request.
 *
 * Charge codes are shared with procurement (lib/staff-procurement.php): a purchase is charged
 * to the same project or grant as the hours.
 */

const TIMESHEET_INTERNAL_CODES = [
    ['ADMIN', 'General administration'],
    ['LEAVE', 'Annual leave'],
    ['SICK', 'Sick leave'],
    ['HOLIDAY', 'Public holiday'],
];

function timesheetEnsureTables(PDO $pdo): bool
{
    static $ready = null;
    if ($ready !== null) return $ready;
    try {
        $tail = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        $pdo->exec("CREATE TABLE IF NOT EXISTS staff_charge_codes (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            code VARCHAR(30) NOT NULL,
            name VARCHAR(160) NOT NULL,
            kind ENUM('project','internal') NOT NULL DEFAULT 'project',
            donor VARCHAR(120) NULL,
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY staff_charge_codes_code (code)
        )" . $tail);
        $pdo->exec("CREATE TABLE IF NOT EXISTS staff_timesheets (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id INT UNSIGNED NOT NULL,
            week_start DATE NOT NULL,
            status ENUM('draft','submitted','approved','returned') NOT NULL DEFAULT 'draft',
            note VARCHAR(500) NULL,
            total_minutes INT UNSIGNED NOT NULL DEFAULT 0,
            submitted_at DATETIME NULL,
            decided_by INT UNSIGNED NULL,
            decided_at DATETIME NULL,
            return_reason VARCHAR(500) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY staff_timesheets_week (user_id, week_start),
            KEY staff_timesheets_status (status, week_start)
        )" . $tail);
        $pdo->exec("CREATE TABLE IF NOT EXISTS staff_timesheet_lines (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            timesheet_id INT UNSIGNED NOT NULL,
            charge_code_id INT UNSIGNED NOT NULL,
            m1 SMALLINT UNSIGNED NOT NULL DEFAULT 0, m2 SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            m3 SMALLINT UNSIGNED NOT NULL DEFAULT 0, m4 SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            m5 SMALLINT UNSIGNED NOT NULL DEFAULT 0, m6 SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            m7 SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            note VARCHAR(200) NULL,
            PRIMARY KEY (id),
            KEY staff_timesheet_lines_sheet (timesheet_id)
        )" . $tail);
        if (!(int)$pdo->query('SELECT COUNT(*) FROM staff_charge_codes')->fetchColumn()) {
            $add = $pdo->prepare("INSERT IGNORE INTO staff_charge_codes (code, name, kind) VALUES (?, ?, 'internal')");
            foreach (TIMESHEET_INTERNAL_CODES as [$code, $name]) $add->execute([$code, $name]);
        }
        return $ready = true;
    } catch (Throwable $e) {
        error_log('Timesheet tables unavailable: ' . $e->getMessage());
        return $ready = false;
    }
}

function staffChargeCodes(PDO $pdo, bool $includeInactive = false): array
{
    timesheetEnsureTables($pdo);
    $rows = $pdo->query('SELECT * FROM staff_charge_codes' . ($includeInactive ? '' : ' WHERE active = 1') . " ORDER BY kind = 'internal', code")->fetchAll();
    return array_map(static fn($r) => ['id' => (int)$r['id'], 'code' => $r['code'], 'name' => $r['name'], 'kind' => $r['kind'],
        'donor' => $r['donor'], 'active' => (bool)$r['active']], $rows);
}

/** The Monday of a valid date, or null. */
function timesheetMonday(string $date): ?string
{
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    if (!$d || $d->format('Y-m-d') !== $date) return null;
    return $d->modify('-' . ((int)$d->format('N') - 1) . ' days')->format('Y-m-d');
}

/** Who decides this person's sheets: their manager, or (with no manager) everyone holding staff.manage. */
function timesheetDeciders(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare('SELECT s.manager_user_id FROM staff_accounts s JOIN staff_accounts m ON m.user_id = s.manager_user_id AND m.active = 1 WHERE s.user_id = ?');
    $stmt->execute([$userId]);
    $manager = $stmt->fetchColumn();
    if ($manager) return [(int)$manager];
    $hr = $pdo->query("SELECT g.user_id FROM staff_grants g JOIN staff_accounts s ON s.user_id = g.user_id AND s.active = 1 WHERE g.capability = 'staff.manage'")->fetchAll(PDO::FETCH_COLUMN);
    return array_values(array_diff(array_map('intval', $hr), [$userId]));
}

function timesheetMayDecide(PDO $pdo, array $gate, int $me, int $owner): bool
{
    if ($owner === $me) return false;
    if (in_array($me, timesheetDeciders($pdo, $owner), true)) return true;
    return $gate['super'];
}

function timesheetShape(PDO $pdo, array $sheet, bool $withLines = true): array
{
    $out = [
        'id' => (int)$sheet['id'], 'userId' => (int)$sheet['user_id'], 'weekStart' => $sheet['week_start'],
        'weekEnd' => date('Y-m-d', strtotime($sheet['week_start'] . ' +6 days')),
        'status' => $sheet['status'], 'note' => $sheet['note'], 'totalMinutes' => (int)$sheet['total_minutes'],
        'submittedAt' => $sheet['submitted_at'], 'decidedAt' => $sheet['decided_at'], 'returnReason' => $sheet['return_reason'],
        'name' => $sheet['person_name'] ?? null, 'decidedBy' => $sheet['decider_name'] ?? null,
    ];
    if ($withLines) {
        $stmt = $pdo->prepare('SELECT l.*, c.code, c.name FROM staff_timesheet_lines l JOIN staff_charge_codes c ON c.id = l.charge_code_id WHERE l.timesheet_id = ? ORDER BY l.id');
        $stmt->execute([(int)$sheet['id']]);
        $out['lines'] = array_map(static fn($l) => [
            'codeId' => (int)$l['charge_code_id'], 'code' => $l['code'], 'codeName' => $l['name'], 'note' => $l['note'],
            'minutes' => [(int)$l['m1'], (int)$l['m2'], (int)$l['m3'], (int)$l['m4'], (int)$l['m5'], (int)$l['m6'], (int)$l['m7']],
        ], $stmt->fetchAll());
    }
    return $out;
}

function timesheetFetch(PDO $pdo, string $where, array $args): array
{
    $stmt = $pdo->prepare("SELECT t.*, COALESCE(NULLIF(s.full_name, ''), u.email) AS person_name, d.full_name AS decider_name
                           FROM staff_timesheets t JOIN users u ON u.id = t.user_id LEFT JOIN staff_accounts s ON s.user_id = t.user_id
                           LEFT JOIN staff_accounts d ON d.user_id = t.decided_by WHERE $where");
    $stmt->execute($args);
    return $stmt->fetchAll();
}

/** Sheets waiting for this person's decision. */
function timesheetQueue(PDO $pdo, array $gate, int $me): array
{
    if (!timesheetEnsureTables($pdo)) return [];
    $rows = timesheetFetch($pdo, "t.status = 'submitted' AND t.user_id <> ? ORDER BY t.week_start, t.submitted_at", [$me]);
    return array_values(array_filter($rows, static fn($r) => timesheetMayDecide($pdo, $gate, $me, (int)$r['user_id'])));
}

function timesheetTodo(PDO $pdo, array $gate, int $me): int
{
    return count(timesheetQueue($pdo, $gate, $me));
}

/** Converts the browser's hours (numbers or "7.5") into minutes per day; null on bad input. */
function timesheetMinutes($hours): ?array
{
    if (!is_array($hours) || count($hours) !== 7) return null;
    $out = [];
    foreach (array_values($hours) as $h) {
        if ($h === '' || $h === null) { $out[] = 0; continue; }
        if (!is_numeric($h)) return null;
        $mins = (int)round((float)$h * 60);
        if ($mins < 0 || $mins > 1440 || $mins % 15 !== 0) return null;
        $out[] = $mins;
    }
    return $out;
}

function timesheetCsv(string $filename, array $header, array $rows): void
{
    header_remove('Content-Type');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: private, no-store');
    $fh = fopen('php://temp', 'w+');
    fputcsv($fh, $header);
    // A cell starting = + - @ would run as a formula in Excel.
    $safe = static fn($v) => is_string($v) && $v !== '' && strpbrk($v[0], '=+-@') !== false ? "'" . $v : $v;
    foreach ($rows as $r) fputcsv($fh, array_map($safe, $r));
    rewind($fh);
    echo "\xEF\xBB\xBF" . stream_get_contents($fh);
    exit;
}

function timesheetHandle(PDO $pdo, array $config, string $route, string $method, array $user, array $gate): void
{
    if (!timesheetEnsureTables($pdo)) respond(503, ['error' => 'The timesheet tables could not be created.']);
    $me = (int)$user['userId'];
    $isHr = in_array('staff.manage', $gate['caps'], true) || $gate['super'];
    $people = fieldVisiblePeople($pdo, $me, $isHr);
    $codesManager = $isHr || in_array('procurement.approve', $gate['caps'], true) || in_array('payroll.manage', $gate['caps'], true);

    $sheetById = function (int $id) use ($pdo): array {
        $rows = timesheetFetch($pdo, 't.id = ?', [$id]);
        if (!$rows) respond(404, ['error' => 'No such timesheet.']);
        return $rows[0];
    };

    // ----- my week -----
    if ($route === 'staff/timesheets' && $method === 'GET') {
        $week = timesheetMonday((string)($_GET['week'] ?? date('Y-m-d')));
        if (!$week) respond(400, ['error' => 'Give a valid date.']);
        $rows = timesheetFetch($pdo, 't.user_id = ? AND t.week_start = ?', [$me, $week]);
        // Field work already recorded that week, as a reminder of where the hours went.
        $fieldMinutes = 0;
        if (function_exists('fieldEnsureTables') && fieldEnsureTables($pdo)) {
            $f = $pdo->prepare('SELECT COALESCE(SUM(TIMESTAMPDIFF(MINUTE, started_at, ended_at)), 0) FROM staff_field_activities
                                WHERE user_id = ? AND activity_date BETWEEN ? AND DATE_ADD(?, INTERVAL 6 DAY) AND started_at IS NOT NULL AND ended_at IS NOT NULL');
            $f->execute([$me, $week, $week]);
            $fieldMinutes = (int)$f->fetchColumn();
        }
        $recent = timesheetFetch($pdo, 't.user_id = ? ORDER BY t.week_start DESC LIMIT 12', [$me]);
        respond(200, [
            'week' => $week, 'sheet' => $rows ? timesheetShape($pdo, $rows[0]) : null,
            'codes' => staffChargeCodes($pdo), 'fieldMinutes' => $fieldMinutes,
            'recent' => array_map(static fn($r) => timesheetShape($pdo, $r, false), $recent),
            'onStaffList' => (bool)$gate['row'], 'toDecide' => count(timesheetQueue($pdo, $gate, $me)),
            'people' => array_map(static fn($id, $name) => ['userId' => $id, 'name' => $name, 'me' => $id === $me], array_keys($people), $people),
            'canManageCodes' => $codesManager, 'isHr' => $isHr,
        ]);
    }

    if ($route === 'staff/timesheets/save' && $method === 'POST') {
        if (!$gate['row']) respond(403, ['error' => 'Only staff keep timesheets.']);
        $data = input();
        $week = timesheetMonday((string)($data['week'] ?? ''));
        if (!$week || $week !== (string)$data['week']) respond(400, ['error' => 'A timesheet week starts on a Monday.']);
        if ($week > date('Y-m-d', strtotime('monday next week'))) respond(400, ['error' => 'A timesheet cannot be filled that far ahead.']);
        $existing = timesheetFetch($pdo, 't.user_id = ? AND t.week_start = ?', [$me, $week]);
        if ($existing && !in_array($existing[0]['status'], ['draft', 'returned'], true)) {
            respond(400, ['error' => $existing[0]['status'] === 'approved' ? 'This week is approved and locked. Ask HR to reopen it.' : 'This week is waiting for approval. Withdraw it to change it.']);
        }
        $active = array_column(staffChargeCodes($pdo), null, 'id');
        $lines = [];
        $perDay = array_fill(0, 7, 0);
        foreach ((array)($data['lines'] ?? []) as $line) {
            if (!is_array($line)) continue;
            $codeId = (int)($line['codeId'] ?? 0);
            $mins = timesheetMinutes($line['hours'] ?? null);
            if ($mins === null) respond(400, ['error' => 'Hours must be in quarter hours between 0 and 24.']);
            if (!array_sum($mins)) continue;
            if (!isset($active[$codeId])) respond(400, ['error' => 'Choose a project or code for every line with hours.']);
            foreach ($mins as $i => $m) $perDay[$i] += $m;
            $lines[] = [$codeId, $mins, fieldText($line['note'] ?? '', 200)];
        }
        if (count($lines) > 20) respond(400, ['error' => 'Use at most 20 lines.']);
        foreach ($perDay as $m) if ($m > 1440) respond(400, ['error' => 'No day can have more than 24 hours.']);
        $submit = !empty($data['submit']);
        if ($submit && !$lines) respond(400, ['error' => 'Enter some hours before submitting.']);
        $total = array_sum($perDay);
        $note = fieldText($data['note'] ?? '', 500);

        $pdo->beginTransaction();
        if ($existing) {
            $id = (int)$existing[0]['id'];
            $pdo->prepare('UPDATE staff_timesheets SET note = ?, total_minutes = ?, status = ?, submitted_at = ? WHERE id = ?')
                ->execute([$note, $total, $submit ? 'submitted' : $existing[0]['status'], $submit ? date('Y-m-d H:i:s') : null, $id]);
            $pdo->prepare('DELETE FROM staff_timesheet_lines WHERE timesheet_id = ?')->execute([$id]);
        } else {
            $pdo->prepare('INSERT INTO staff_timesheets (user_id, week_start, note, total_minutes, status, submitted_at) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([$me, $week, $note, $total, $submit ? 'submitted' : 'draft', $submit ? date('Y-m-d H:i:s') : null]);
            $id = (int)$pdo->lastInsertId();
        }
        $add = $pdo->prepare('INSERT INTO staff_timesheet_lines (timesheet_id, charge_code_id, m1, m2, m3, m4, m5, m6, m7, note) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ($lines as [$codeId, $mins, $lnote]) $add->execute(array_merge([$id, $codeId], $mins, [$lnote]));
        $pdo->commit();
        if ($submit) {
            $name = $people[$me] ?? 'A colleague';
            foreach (timesheetDeciders($pdo, $me) as $d) {
                staffNotify($pdo, $d, 'timesheet', $name . ' submitted a timesheet for the week of ' . date('j M', strtotime($week)), '#timesheet-approvals', 'ts:' . $id . ':' . time());
            }
        }
        respond(200, ['sheet' => timesheetShape($pdo, $sheetById($id))]);
    }

    // The owner takes back a sheet nobody has decided yet.
    if (preg_match('#^staff/timesheets/(\d+)/withdraw$#', $route, $m) && $method === 'POST') {
        $s = $sheetById((int)$m[1]);
        if ((int)$s['user_id'] !== $me) respond(404, ['error' => 'No such timesheet of yours.']);
        if ($s['status'] !== 'submitted') respond(400, ['error' => 'Only a sheet waiting for approval can be withdrawn.']);
        $pdo->prepare("UPDATE staff_timesheets SET status = 'draft', submitted_at = NULL WHERE id = ? AND status = 'submitted'")->execute([(int)$s['id']]);
        respond(200, ['sheet' => timesheetShape($pdo, $sheetById((int)$s['id']))]);
    }

    // ----- approvals -----
    if ($route === 'staff/timesheets/approvals' && $method === 'GET') {
        respond(200, ['sheets' => array_map(static fn($r) => timesheetShape($pdo, $r), timesheetQueue($pdo, $gate, $me))]);
    }
    if (preg_match('#^staff/timesheets/(\d+)/(approve|return)$#', $route, $m) && $method === 'POST') {
        $s = $sheetById((int)$m[1]);
        if (!timesheetMayDecide($pdo, $gate, $me, (int)$s['user_id'])) respond(403, ['error' => (int)$s['user_id'] === $me ? 'You cannot approve your own timesheet.' : 'Only the person\'s manager, or HR if they have none, decides their timesheet.']);
        if ($s['status'] !== 'submitted') respond(400, ['error' => 'This sheet is not waiting for a decision.']);
        $reason = fieldText(input()['reason'] ?? '', 500);
        if ($m[2] === 'return' && $reason === null) respond(400, ['error' => 'Say what needs correcting.']);
        $pdo->prepare('UPDATE staff_timesheets SET status = ?, decided_by = ?, decided_at = NOW(), return_reason = ? WHERE id = ? AND status = \'submitted\'')
            ->execute([$m[2] === 'approve' ? 'approved' : 'returned', $me, $m[2] === 'return' ? $reason : null, (int)$s['id']]);
        $week = date('j M', strtotime($s['week_start']));
        staffNotify($pdo, (int)$s['user_id'], 'timesheet', $m[2] === 'approve' ? "Your timesheet for the week of $week was approved" : "Your timesheet for the week of $week was returned: $reason",
            '#timesheets', 'ts-d:' . $s['id'] . ':' . time());
        staffAudit($pdo, $me, 'timesheet_' . ($m[2] === 'approve' ? 'approved' : 'returned'), 'timesheet', (string)$s['id'], $s['person_name'] . ' week ' . $s['week_start']);
        respond(200, ['sheet' => timesheetShape($pdo, $sheetById((int)$s['id']))]);
    }
    // HR reopens an approved week (a correction after approval), with a reason on the record.
    if (preg_match('#^staff/timesheets/(\d+)/reopen$#', $route, $m) && $method === 'POST') {
        if (!$isHr) respond(403, ['error' => 'Only HR can reopen an approved timesheet.']);
        $s = $sheetById((int)$m[1]);
        if ($s['status'] !== 'approved') respond(400, ['error' => 'Only an approved sheet is reopened; the others can still be changed.']);
        $reason = fieldText(input()['reason'] ?? '', 500);
        if ($reason === null) respond(400, ['error' => 'Give the reason for reopening.']);
        $pdo->prepare("UPDATE staff_timesheets SET status = 'returned', return_reason = ?, decided_by = ?, decided_at = NOW() WHERE id = ?")->execute(['Reopened by HR: ' . $reason, $me, (int)$s['id']]);
        staffNotify($pdo, (int)$s['user_id'], 'timesheet', 'HR reopened your timesheet for the week of ' . date('j M', strtotime($s['week_start'])) . ': ' . $reason, '#timesheets', 'ts-r:' . $s['id'] . ':' . time());
        staffAudit($pdo, $me, 'timesheet_reopened', 'timesheet', (string)$s['id'], $s['person_name'] . ' week ' . $s['week_start'] . ': ' . $reason);
        respond(200, ['ok' => true]);
    }

    // One sheet, for its owner, their line and HR.
    if (preg_match('#^staff/timesheets/(\d+)$#', $route, $m) && $method === 'GET') {
        $s = $sheetById((int)$m[1]);
        if (!isset($people[(int)$s['user_id']]) && !timesheetMayDecide($pdo, $gate, $me, (int)$s['user_id'])) respond(404, ['error' => 'No such timesheet.']);
        respond(200, ['sheet' => timesheetShape($pdo, $s)]);
    }

    // ----- monthly report: hours by person and charge code -----
    if ($route === 'staff/timesheets/report' && $method === 'GET') {
        $month = (string)($_GET['month'] ?? date('Y-m'));
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) respond(400, ['error' => 'Choose a month.']);
        $first = $month . '-01';
        $last = date('Y-m-t', strtotime($first));
        $ids = array_keys($people);
        $data = [];
        $status = [];
        if ($ids) {
            // A week straddling two months is split by day, so each month gets only its own days.
            $in = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $pdo->prepare("SELECT t.user_id, t.week_start, t.status, c.code, c.name, l.m1, l.m2, l.m3, l.m4, l.m5, l.m6, l.m7
                                   FROM staff_timesheets t JOIN staff_timesheet_lines l ON l.timesheet_id = t.id JOIN staff_charge_codes c ON c.id = l.charge_code_id
                                   WHERE t.user_id IN ($in) AND t.week_start BETWEEN DATE_SUB(?, INTERVAL 6 DAY) AND ?");
            $stmt->execute(array_merge($ids, [$first, $last]));
            foreach ($stmt->fetchAll() as $r) {
                for ($i = 0; $i < 7; $i++) {
                    $day = date('Y-m-d', strtotime($r['week_start'] . " +$i days"));
                    if ($day < $first || $day > $last) continue;
                    $mins = (int)$r['m' . ($i + 1)];
                    if (!$mins) continue;
                    $uid = (int)$r['user_id'];
                    $key = $r['code'];
                    $data[$uid][$key]['name'] = $r['name'];
                    $data[$uid][$key][$r['status'] === 'approved' ? 'approved' : 'pending'] = ($data[$uid][$key][$r['status'] === 'approved' ? 'approved' : 'pending'] ?? 0) + $mins;
                }
                $status[(int)$r['user_id']][$r['status']] = true;
            }
        }
        $rows = [];
        foreach ($people as $uid => $name) {
            $codes = [];
            foreach ($data[$uid] ?? [] as $code => $v) $codes[] = ['code' => $code, 'name' => $v['name'], 'approved' => $v['approved'] ?? 0, 'pending' => $v['pending'] ?? 0];
            usort($codes, static fn($a, $b) => strcmp($a['code'], $b['code']));
            $rows[] = ['userId' => $uid, 'name' => $name, 'codes' => $codes,
                       'approved' => array_sum(array_column($codes, 'approved')), 'pending' => array_sum(array_column($codes, 'pending'))];
        }
        if (!empty($_GET['csv'])) {
            staffAudit($pdo, $me, 'timesheet_report_exported', 'timesheet', null, $month);
            $out = [];
            foreach ($rows as $r) foreach ($r['codes'] as $c) $out[] = [$r['name'], $month, $c['code'], $c['name'], round($c['approved'] / 60, 2), round($c['pending'] / 60, 2)];
            timesheetCsv('timesheets-' . $month . '.csv', ['Staff', 'Month', 'Code', 'Project / code', 'Approved hours', 'Not yet approved hours'], $out);
        }
        respond(200, ['month' => $month, 'label' => date('F Y', strtotime($first)), 'people' => $rows]);
    }

    // ----- charge codes -----
    if ($route === 'staff/timesheets/codes' && $method === 'GET') {
        respond(200, ['codes' => staffChargeCodes($pdo, $codesManager), 'canManage' => $codesManager]);
    }
    if ($route === 'staff/timesheets/codes' && $method === 'POST') {
        if (!$codesManager) respond(403, ['error' => 'Projects and codes are kept by HR and finance.']);
        $data = input();
        $id = (int)($data['id'] ?? 0);
        $code = strtoupper((string)fieldText($data['code'] ?? '', 30));
        $name = fieldText($data['name'] ?? '', 160);
        if (!preg_match('/^[A-Z0-9][A-Z0-9._\/-]{0,29}$/', $code)) respond(400, ['error' => 'Give a short code: letters, numbers, dots, dashes.']);
        if ($name === null) respond(400, ['error' => 'Give the project or code a name.']);
        $kind = ($data['kind'] ?? 'project') === 'internal' ? 'internal' : 'project';
        $dup = $pdo->prepare('SELECT id FROM staff_charge_codes WHERE code = ? AND id <> ?');
        $dup->execute([$code, $id]);
        if ($dup->fetchColumn()) respond(400, ['error' => 'That code is already in use.']);
        $args = [$code, $name, $kind, fieldText($data['donor'] ?? '', 120), empty($data['inactive']) ? 1 : 0];
        if ($id) $pdo->prepare('UPDATE staff_charge_codes SET code = ?, name = ?, kind = ?, donor = ?, active = ? WHERE id = ?')->execute(array_merge($args, [$id]));
        else $pdo->prepare('INSERT INTO staff_charge_codes (code, name, kind, donor, active) VALUES (?, ?, ?, ?, ?)')->execute($args);
        staffAudit($pdo, $me, 'charge_code_saved', 'charge_code', (string)($id ?: $pdo->lastInsertId()), $code . ' ' . $name);
        respond(200, ['codes' => staffChargeCodes($pdo, true)]);
    }

    respond(404, ['error' => 'Route not found']);
}
