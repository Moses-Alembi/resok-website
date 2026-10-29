<?php
/**
 * The HR employee list, pasted from Excel into Staff records (28 Oct 2026).
 *
 * Moses: "this is the real list of employees and their titles"; "the other without counties
 * all are from HQ Nairobi"; "these are the persons eligible for welfare elections".
 *
 * Each row (employee number, name, job title - copied straight from the spreadsheet, with or
 * without its row-number column) is matched to an account by name, and on Apply sets that
 * account's employee number, job title and counties of operation. The county is read from the
 * title ("Project Officer - Kilifi"); a title naming none is HQ, Nairobi. Every match and
 * county can be changed in the preview before anything is written.
 *
 * Having an employee number is what puts someone on the welfare election roll
 * (lib/elections.php), so the list decides who votes. Accounts not on it keep their portal
 * access but cannot vote or stand.
 */

/** "WANGUI, JOSEPH NJUGUNA" -> "Joseph Njuguna Wangui"; "ANTONNETTE ADUOL J.OTIENO" -> "Antonnette Aduol J. Otieno". */
function hrImportName(string $raw): string
{
    $raw = trim(preg_replace('/\s+/u', ' ', $raw) ?? '');
    if (str_contains($raw, ',')) {
        [$surname, $rest] = array_map('trim', explode(',', $raw, 2));
        $raw = trim($rest . ' ' . $surname);
    }
    $raw = preg_replace('/\.(?=\S)/u', '. ', $raw) ?? $raw;
    return preg_replace_callback("/[\\p{L}']+/u", static function ($m) {
        $w = mb_strtolower($m[0]);
        return mb_strtoupper(mb_substr($w, 0, 1)) . mb_substr($w, 1);
    }, $raw) ?? $raw;
}

/** A title typed in capitals is tidied ("FIELD OFFICER" -> "Field Officer"); otherwise kept as HR wrote it. */
function hrImportTitle(string $raw): string
{
    $raw = trim(preg_replace('/\s+/u', ' ', $raw) ?? '');
    // "Project Officer -MERU" -> "Project Officer - MERU"; a hyphen inside a word ("Co-ordinator") stays.
    $raw = preg_replace('/\s+-\s*|\s*-\s+/u', ' - ', $raw) ?? $raw;
    if ($raw !== '' && mb_strtoupper($raw) === $raw && preg_match('/\p{L}{5,}/u', $raw)) {
        $raw = mb_convert_case(mb_strtolower($raw), MB_CASE_TITLE);
    }
    return $raw;
}

/** Counties named in a title; HQ (Nairobi) when it names none. */
function hrImportCounties(string $title): array
{
    $found = [];
    $flat = geoNorm($title);
    foreach (GEO_KENYA_COUNTIES as $c) {
        if (str_contains($flat, geoNorm($c))) $found[] = $c;
    }
    return $found ?: ['Nairobi'];
}

/** Rows from the pasted text: [number, name, title]. Header and blank lines are skipped. */
function hrImportParse(string $text): array
{
    $rows = [];
    foreach (preg_split('/\r\n|\r|\n/', $text) as $line) {
        if (trim($line) === '') continue;
        $cells = array_map('trim', str_contains($line, "\t") ? explode("\t", $line) : preg_split('/\s{2,}|;/', trim($line)));
        $cells = array_values(array_filter($cells, static fn($c) => $c !== ''));
        // The spreadsheet's own row-number column, when it was copied too.
        if (count($cells) >= 4 && preg_match('/^\d{1,3}$/', $cells[0]) && preg_match('/^\d+$/', $cells[1])) array_shift($cells);
        if (count($cells) < 3 || !preg_match('/^\d{1,10}$/', $cells[0])) continue;   // header or stray line
        $rows[] = ['number' => $cells[0], 'raw' => $cells[1], 'name' => hrImportName($cells[1]), 'title' => hrImportTitle(implode(' ', array_slice($cells, 2)))];
    }
    return $rows;
}

/**
 * Accounts a row can be matched to: staff, plus administrators (the ICT officer may not be on
 * the staff list) and the super admins named in config (Moses is on the HR list too).
 */
function hrImportPeople(PDO $pdo, array $config = []): array
{
    $supers = array_values(array_filter(array_map(static fn($e) => strtolower(trim((string)$e)), (array)($config['super_admins'] ?? []))));
    $orSuper = $supers ? ' OR LOWER(u.email) IN (' . implode(',', array_map([$pdo, 'quote'], $supers)) . ')' : '';
    return $pdo->query("SELECT u.id AS user_id, COALESCE(NULLIF(s.full_name, ''), NULLIF(TRIM(CONCAT_WS(' ', NULLIF(m.first_name, ''), NULLIF(m.surname, ''))), ''), '') AS name,
                               u.email, s.user_id IS NOT NULL AS on_staff, s.staff_number, COALESCE(s.active, 1) AS active
                          FROM users u LEFT JOIN staff_accounts s ON s.user_id = u.id LEFT JOIN member_profiles m ON m.user_id = u.id
                         WHERE s.user_id IS NOT NULL OR u.role = 'admin'$orSuper
                         ORDER BY name = '', name, u.email")->fetchAll();
}

/** How many words of the HR name are found in this account's name or email. */
function hrImportScore(string $hrName, array $p): float
{
    $words = staffNameTokens((string)$p['name']);
    $local = strtoupper((string)strstr((string)$p['email'], '@', true));
    $score = 0.0;
    foreach (staffNameTokens($hrName) as $t) {
        $best = 0.0;
        foreach ($words as $w) $best = max($best, staffWordLike($t, $w));
        if ($best < 0.75 && strlen($t) >= 4 && str_contains($local, $t)) $best = 0.8;
        if ($best >= 0.75) $score += $best;
    }
    return $score;
}

/** staff/people/import: preview (apply false) or write (apply true). staff.manage only. */
function hrImportHandle(PDO $pdo, array $config, string $route, string $method, array $user, array $gate): void
{
    if ($route !== 'staff/people/import' || $method !== 'POST') return;
    staffNeed($gate, 'staff.manage');
    if (!function_exists('geoEnsureTables') || !geoEnsureTables($pdo)) respond(503, ['error' => 'Counties are not available on this server yet.']);
    $data = input();
    $rows = hrImportParse((string)($data['text'] ?? ''));
    if (!$rows) respond(400, ['error' => 'No rows found. Copy the Employee Number, Name and Job Title columns from the spreadsheet and paste them here.']);
    if (count($rows) > 300) respond(400, ['error' => 'That is more than 300 rows. Paste the employee list only.']);
    $people = hrImportPeople($pdo, $config);
    $byId = [];
    foreach ($people as $p) $byId[(int)$p['user_id']] = $p;

    if (empty($data['apply'])) {
        $out = [];
        foreach ($rows as $i => $r) {
            $scores = [];
            foreach ($people as $p) {
                // The employee number already on a record is the surest match of all.
                $s = ($p['staff_number'] !== null && $p['staff_number'] === $r['number']) ? 10.0 : hrImportScore($r['name'], $p);
                if ($s > 0) $scores[(int)$p['user_id']] = $s;
            }
            arsort($scores);
            $ids = array_keys($scores);
            $best = $ids[0] ?? null;
            $top = $best !== null ? $scores[$best] : 0;
            $second = isset($ids[1]) ? $scores[$ids[1]] : 0;
            $sure = $best !== null && $top >= 1.6 && $top - $second >= 0.75;
            $out[] = $r + [
                'row' => $i,
                'counties' => hrImportCounties($r['title']),
                'userId' => $best !== null && $top >= 0.75 ? $best : null,
                'match' => $sure ? 'sure' : ($best !== null && $top >= 0.75 ? 'check' : 'none'),
            ];
        }
        $matched = array_filter(array_column($out, 'userId'));
        $notListed = array_values(array_filter($people, static fn($p) => $p['on_staff'] && (int)$p['active'] === 1 && !in_array((int)$p['user_id'], $matched, true)));
        respond(200, [
            'rows' => $out,
            'people' => array_map(static fn($p) => ['userId' => (int)$p['user_id'], 'name' => $p['name'] ?: null, 'email' => $p['email'], 'onStaff' => (bool)$p['on_staff']], $people),
            'notListed' => array_map(static fn($p) => ['userId' => (int)$p['user_id'], 'name' => $p['name'] ?: null, 'email' => $p['email']], $notListed),
            'counties' => GEO_KENYA_COUNTIES,
        ]);
    }

    // Apply: the choices made in the preview, row by row.
    $choices = (array)($data['choices'] ?? []);
    $seenUsers = [];
    $seenNumbers = [];
    $plan = [];
    foreach ($rows as $i => $r) {
        $c = (array)($choices[$i] ?? $choices[(string)$i] ?? []);
        $uid = (int)($c['userId'] ?? 0);
        if ($uid <= 0) continue;
        if (!isset($byId[$uid])) respond(400, ['error' => 'Row ' . ($i + 1) . ' (' . $r['name'] . '): that account is not staff or an administrator.']);
        if (isset($seenUsers[$uid])) respond(400, ['error' => $r['name'] . ' and ' . $seenUsers[$uid] . ' are matched to the same account. Fix one of them.']);
        if (isset($seenNumbers[$r['number']])) respond(400, ['error' => 'Employee number ' . $r['number'] . ' appears twice in the list.']);
        $counties = [];
        foreach ((array)($c['counties'] ?? hrImportCounties($r['title'])) as $name) {
            $n = geoCounty(is_scalar($name) ? (string)$name : '');
            if ($n === null) respond(400, ['error' => 'Row ' . ($i + 1) . ' (' . $r['name'] . '): "' . $name . '" is not a county.']);
            $counties[$n] = true;
        }
        $seenUsers[$uid] = $r['name'];
        $seenNumbers[$r['number']] = true;
        $plan[] = [$uid, $r, array_keys($counties)];
    }
    if (!$plan) respond(400, ['error' => 'Nobody is matched to an account, so there is nothing to apply.']);

    $me = (int)$user['userId'];
    $pdo->beginTransaction();
    try {
        // Numbers move with the list: clear any that now belong to someone else first, so the
        // unique key never trips on a swap.
        $clear = $pdo->prepare('UPDATE staff_accounts SET staff_number = NULL WHERE staff_number = ? AND user_id <> ?');
        foreach ($plan as [$uid, $r]) $clear->execute([$r['number'], $uid]);
        $add = $pdo->prepare('INSERT IGNORE INTO staff_accounts (user_id, full_name, added_by) VALUES (?, ?, ?)');
        $set = $pdo->prepare("UPDATE staff_accounts SET staff_number = ?, job_title = ?, work_counties = ?, full_name = COALESCE(NULLIF(full_name, ''), ?), active = 1, deactivated_at = NULL WHERE user_id = ?");
        foreach ($plan as [$uid, $r, $counties]) {
            $add->execute([$uid, $r['name'], $me]);   // an administrator not yet on the staff list
            $set->execute([$r['number'], mb_substr($r['title'], 0, 120), $counties ? implode(',', $counties) : null, $r['name'], $uid]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        respond(500, ['error' => 'Nothing was changed: ' . $e->getMessage()]);
    }
    staffAudit($pdo, $me, 'hr_list_imported', 'staff', null, count($plan) . ' employees from the HR list');
    respond(200, ['applied' => count($plan), 'message' => count($plan) . ' staff records updated from the HR list.']);
}
