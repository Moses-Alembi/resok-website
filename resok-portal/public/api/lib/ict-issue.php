<?php
declare(strict_types=1);

/**
 * The issue book.
 *
 * ict_assignments has recorded every handover since the assets module was built. This file
 * is the book you can actually read: the register in date order, the people holding things,
 * and the signature that makes a handover evidence rather than a note somebody typed.
 *
 * Three things shape it.
 *
 * The issue reference is derived, never stored. ISS-2026-0042 is the year of the handover
 * and the row's id, so it cannot drift away from the row it names, and the 172 imported
 * assignments have one without a backfill. A stored reference is one more thing that can be
 * edited into disagreeing with the record it identifies.
 *
 * Holders are grouped by a normalised name, in PHP rather than SQL. The register was typed
 * by hand over years, so CHRISPHNE OKOTH and CHRISPINE are one person and the database
 * cannot know that. Grouping in code keeps the raw name on every row - the evidence - while
 * still letting the page answer "what is Chrispine holding". A stored normalised key would
 * be a derived value that goes stale the moment a name is corrected.
 *
 * Acknowledgement is two-track and says which track it took. Most people issued equipment
 * here have no portal login, so they sign paper and the record points at where that paper is
 * filed. Anyone with an account can confirm in the portal instead. What is never done is
 * recording a click as if it were a signature, or an admin's assertion as if it were either.
 */

require_once __DIR__ . '/ict-assets.php';

const ICT_ISSUE_VIA = ['portal', 'paper'];

/** How far two names may differ before the page stops suggesting they are one person. */
const ICT_ISSUE_NAME_DISTANCE = 2;

/**
 * Whether the acknowledgement columns are present.
 *
 * Separate from ictAssetsEnsureTables because the columns arrive in a later migration: the
 * assets module has to keep working on a database where the issue book has not been applied
 * yet, rather than the whole equipment page going dark over a missing column.
 */
function ictIssueEnsureColumns(PDO $pdo): bool
{
    static $ready = null;
    if ($ready !== null) return $ready;
    if (!ictAssetsEnsureTables($pdo)) return $ready = false;
    try {
        $pdo->query('SELECT acknowledged_at, acknowledged_via, acknowledgement_ref,
                            acknowledged_by
                     FROM ict_assignments LIMIT 1');
        return $ready = true;
    } catch (Throwable $e) {
        error_log('ICT issue book columns unavailable: ' . $e->getMessage());
        return $ready = false;
    }
}

/**
 * The reference printed on a handover form, e.g. ISS-2026-0042.
 *
 * Derived from the year and the row id. The year makes it readable to somebody filing paper;
 * the id makes it unique without a counter that could hand out the same number twice.
 */
function ictIssueRef(int $id, ?string $assignedAt): string
{
    $year = substr((string)$assignedAt, 0, 4);
    if (!preg_match('/^[0-9]{4}$/', $year)) $year = date('Y');
    return sprintf('ISS-%s-%04d', $year, $id);
}

/**
 * A name reduced to what can be compared.
 *
 * Upper case, punctuation gone, runs of space collapsed. Deliberately crude: it exists to
 * bring two spellings of one person together for the page to offer, not to decide that they
 * are the same person. That decision stays with whoever knows the office.
 */
function ictIssueHolderKey(string $name): string
{
    $key = strtoupper(trim($name));
    $key = preg_replace('/[^A-Z0-9 ]+/', ' ', $key) ?? $key;
    $key = preg_replace('/\s+/', ' ', (string)$key) ?? $key;
    return trim((string)$key);
}

/**
 * Whether a holder name names more than one person.
 *
 * BETTY/JOSEPH covers ten items in the imported register, which means that if one of them
 * goes missing there is nobody to ask. A paper book has the same weakness; this one at least
 * says so out loud.
 */
function ictIssueSharedHolder(string $name): bool
{
    return (bool)preg_match('#(/|\s&\s|\s\+\s|\bAND\b)#i', $name);
}

/**
 * Names close enough to be worth asking about.
 *
 * Two tests, both conservative. One name being the start of another catches CHRISPINE
 * against CHRISPHNE OKOTH - a first name recorded without a surname. A small edit distance
 * catches typing slips between names of similar length. Nothing is merged automatically; the
 * pairs are reported for a person to confirm, because two people really can share a name.
 *
 * @param list<string> $keys
 * @return list<array{0:string,1:string}>
 */
function ictIssueSimilarNames(array $keys): array
{
    $pairs = [];
    $keys = array_values(array_unique($keys));
    foreach ($keys as $i => $a) {
        foreach (array_slice($keys, $i + 1) as $b) {
            if ($a === '' || $b === '') continue;
            $shorter = strlen($a) <= strlen($b) ? $a : $b;
            $longer  = $shorter === $a ? $b : $a;

            // A bare first name against the same first name plus a surname. The trailing
            // space matters: without it ANNE would match ANNETTE WANJIKU.
            $prefix = strlen($shorter) >= 4 && str_starts_with($longer, $shorter . ' ');

            // Only compared at similar lengths. BETTY and BETTY AGESA differ by six
            // characters, which is what the prefix test above is for.
            $typo = abs(strlen($a) - strlen($b)) <= ICT_ISSUE_NAME_DISTANCE
                    && strlen($shorter) >= 5
                    && levenshtein($a, $b) <= ICT_ISSUE_NAME_DISTANCE;

            if ($prefix || $typo) $pairs[] = [$a, $b];
        }
    }
    return $pairs;
}

/**
 * @param array<string,mixed> $row
 * @return array<string,mixed>
 */
function ictIssueShape(array $row): array
{
    $id = (int)$row['id'];
    $out = [
        'id'            => $id,
        'reference'     => ictIssueRef($id, isset($row['assigned_at']) ? (string)$row['assigned_at'] : null),
        'assetId'       => (int)$row['asset_id'],
        'assetTag'      => $row['asset_tag'] ?? null,
        'assetName'     => $row['asset_name'] ?? null,
        'category'      => $row['category'] ?? null,
        'serialNumber'  => $row['serial_number'] ?? null,
        'model'         => $row['model'] ?? null,
        'holderName'    => $row['holder_name'],
        'holderKey'     => ictIssueHolderKey((string)$row['holder_name']),
        'holderEmail'   => $row['holder_email'] ?? null,
        'holderUserId'  => isset($row['holder_user_id']) ? (int)$row['holder_user_id'] : null,
        'sharedHolder'  => ictIssueSharedHolder((string)$row['holder_name']),
        'department'    => $row['department'] ?? null,
        'assignedAt'    => $row['assigned_at'],
        'conditionOut'  => $row['condition_out'],
        'handoverNotes' => $row['handover_notes'] ?? null,
        'returnedAt'    => $row['returned_at'] ?? null,
        'conditionIn'   => $row['condition_in'] ?? null,
        'returnNotes'   => $row['return_notes'] ?? null,
        'state'         => empty($row['returned_at']) ? 'out' : 'returned',
    ];
    // Present only where the migration has run, so a page on an older database does not
    // start reporting "not acknowledged" for every row when what it means is "cannot say".
    if (array_key_exists('acknowledged_at', $row)) {
        $out['acknowledgedAt']     = $row['acknowledged_at'];
        $out['acknowledgedVia']    = $row['acknowledged_via'];
        $out['acknowledgementRef'] = $row['acknowledgement_ref'];
        $out['acknowledged']       = !empty($row['acknowledged_at']);
    }
    return $out;
}

/** The columns every issue query selects, so ictIssueShape always gets the same row. */
function ictIssueSelect(bool $withAck): string
{
    $ack = $withAck
        ? ', h.acknowledged_at, h.acknowledged_via, h.acknowledgement_ref, h.acknowledged_by'
        : '';
    return 'SELECT h.id, h.asset_id, h.holder_user_id, h.holder_name, h.holder_email,
                   h.department, h.assigned_at, h.condition_out, h.handover_notes,
                   h.returned_at, h.condition_in, h.return_notes' . $ack . ',
                   a.asset_tag, a.name AS asset_name, a.category, a.serial_number, a.model,
                   a.purchase_cost
            FROM ict_assignments h
            JOIN ict_assets a ON a.id = h.asset_id';
}

/**
 * The register, newest handover first.
 *
 * @param array<string,mixed> $filters
 * @return array{issues:list<array<string,mixed>>,total:int}
 */
function ictIssueList(PDO $pdo, array $filters = []): array
{
    if (!ictAssetsEnsureTables($pdo)) return ['issues' => [], 'total' => 0];
    $ack = ictIssueEnsureColumns($pdo);

    $where = [];
    $args  = [];

    $state = (string)($filters['state'] ?? 'out');
    if ($state === 'out') {
        $where[] = 'h.returned_at IS NULL';
    } elseif ($state === 'returned') {
        $where[] = 'h.returned_at IS NOT NULL';
    } elseif ($state === 'unsigned' && $ack) {
        // Still out and never acknowledged - the rows with nothing behind them.
        $where[] = 'h.returned_at IS NULL AND h.acknowledged_at IS NULL';
    }

    $q = trim((string)($filters['q'] ?? ''));
    if ($q !== '') {
        $where[] = '(h.holder_name LIKE ? OR a.asset_tag LIKE ? OR a.name LIKE ?
                     OR a.serial_number LIKE ? OR h.department LIKE ?)';
        $like = '%' . $q . '%';
        array_push($args, $like, $like, $like, $like, $like);
    }

    $from = trim((string)($filters['from'] ?? ''));
    if (preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/', $from)) {
        $where[] = 'h.assigned_at >= ?';
        $args[]  = $from . ' 00:00:00';
    }
    $to = trim((string)($filters['to'] ?? ''));
    if (preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/', $to)) {
        $where[] = 'h.assigned_at <= ?';
        $args[]  = $to . ' 23:59:59';
    }

    $clause = $where ? ' WHERE ' . implode(' AND ', $where) : '';

    $stmt = $pdo->prepare(ictIssueSelect($ack) . $clause
                          . ' ORDER BY h.assigned_at DESC, h.id DESC');
    $stmt->execute($args);
    $rows = array_map('ictIssueShape', $stmt->fetchAll());

    // Filtered here rather than in SQL because it matches on the normalised key: searching
    // for one spelling of a name has to find the handovers recorded under the other.
    $holder = trim((string)($filters['holder'] ?? ''));
    if ($holder !== '') {
        $key = ictIssueHolderKey($holder);
        $rows = array_values(array_filter($rows, fn($r) => $r['holderKey'] === $key));
    }

    $limit  = max(1, min(500, (int)($filters['limit'] ?? 100)));
    $offset = max(0, (int)($filters['offset'] ?? 0));

    return [
        'issues' => array_slice($rows, $offset, $limit),
        'total'  => count($rows),
    ];
}

/** One handover, with everything a printed form needs. */
function ictIssueFind(PDO $pdo, int $id): ?array
{
    if (!ictAssetsEnsureTables($pdo)) return null;
    $stmt = $pdo->prepare(ictIssueSelect(ictIssueEnsureColumns($pdo)) . ' WHERE h.id = ? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ? ictIssueShape($row) : null;
}

/**
 * Everybody currently holding something.
 *
 * Grouped by normalised name, with the spellings that produced each group kept alongside, so
 * the page can show that CHRISPINE and CHRISPHNE OKOTH were brought together rather than
 * quietly picking one. Sorted by how much each person is holding, because that is the order
 * the question gets asked in when somebody leaves.
 *
 * @return list<array<string,mixed>>
 */
function ictIssueHolders(PDO $pdo): array
{
    if (!ictAssetsEnsureTables($pdo)) return [];
    $ack = ictIssueEnsureColumns($pdo);

    $stmt = $pdo->query(ictIssueSelect($ack)
                        . ' WHERE h.returned_at IS NULL ORDER BY h.holder_name, h.assigned_at');
    $rows = $stmt ? $stmt->fetchAll() : [];

    $groups = [];
    foreach ($rows as $row) {
        $issue = ictIssueShape($row);
        $key   = $issue['holderKey'];
        if (!isset($groups[$key])) {
            $groups[$key] = [
                'holderKey'      => $key,
                'name'           => $issue['holderName'],
                'spellings'      => [],
                'department'     => $issue['department'],
                'email'          => $issue['holderEmail'],
                'userId'         => $issue['holderUserId'],
                'items'          => 0,
                'value'          => 0.0,
                'valueKnown'     => 0,
                'unacknowledged' => 0,
                'sharedHolder'   => $issue['sharedHolder'],
                'oldestSince'    => $issue['assignedAt'],
                'possibleDuplicateOf' => [],
                'assets'         => [],
            ];
        }
        $groups[$key]['items']++;
        if (!in_array($issue['holderName'], $groups[$key]['spellings'], true)) {
            $groups[$key]['spellings'][] = $issue['holderName'];
        }
        if ($row['purchase_cost'] !== null) {
            $groups[$key]['value'] += (float)$row['purchase_cost'];
            $groups[$key]['valueKnown']++;
        }
        if ($ack && empty($issue['acknowledgedAt'])) $groups[$key]['unacknowledged']++;
        if ((string)$issue['assignedAt'] < (string)$groups[$key]['oldestSince']) {
            $groups[$key]['oldestSince'] = $issue['assignedAt'];
        }
        if ($issue['holderEmail'] && !$groups[$key]['email']) {
            $groups[$key]['email'] = $issue['holderEmail'];
        }
        if ($issue['holderUserId'] && !$groups[$key]['userId']) {
            $groups[$key]['userId'] = $issue['holderUserId'];
        }
        $groups[$key]['assets'][] = [
            'issueId'      => $issue['id'],
            'reference'    => $issue['reference'],
            'assetId'      => $issue['assetId'],
            'assetTag'     => $issue['assetTag'],
            'name'         => $issue['assetName'],
            'category'     => $issue['category'],
            'since'        => $issue['assignedAt'],
            'acknowledged' => $issue['acknowledged'] ?? null,
        ];
    }

    // Which groups look like one person written two ways.
    //
    // A shared holder is never offered as a merge. BETTY/JOSEPH starts with BETTY, so the
    // prefix test pairs them - but folding ten jointly-held items into one person's name
    // would answer the wrong problem by destroying the evidence of the right one.
    foreach (ictIssueSimilarNames(array_keys($groups)) as [$a, $b]) {
        if (isset($groups[$a], $groups[$b])
            && !$groups[$a]['sharedHolder'] && !$groups[$b]['sharedHolder']) {
            $groups[$a]['possibleDuplicateOf'][] = $groups[$b]['name'];
            $groups[$b]['possibleDuplicateOf'][] = $groups[$a]['name'];
        }
    }

    $out = array_values($groups);
    usort($out, fn($x, $y) => ($y['items'] <=> $x['items']) ?: strcmp($x['name'], $y['name']));
    foreach ($out as $i => $g) {
        $out[$i]['value'] = round($g['value'], 2);
        $out[$i]['possibleDuplicateOf'] = array_values(array_unique($g['possibleDuplicateOf']));
    }
    return $out;
}

/**
 * Records that a handover was acknowledged.
 *
 * Refuses to acknowledge a returned handover: signing for something given back weeks ago is
 * not evidence of anything, and allowing it would let a gap in the paperwork be closed after
 * the fact by whoever noticed the gap.
 *
 * Refuses to overwrite an existing acknowledgement, for the same reason a return does not
 * edit the assignment it closes - the first record is the one with evidence behind it.
 *
 * @param array<string,mixed> $data
 * @return array{0:?array,1:?string}
 */
function ictIssueAcknowledge(PDO $pdo, int $id, array $data, ?int $adminUserId): array
{
    if (!ictIssueEnsureColumns($pdo)) {
        return [null, 'The issue book columns are not available. Apply schema-ict-issue.sql.'];
    }
    $issue = ictIssueFind($pdo, $id);
    if (!$issue) return [null, 'That handover is not on the register.'];
    if ($issue['state'] === 'returned') {
        return [null, 'This was returned on ' . substr((string)$issue['returnedAt'], 0, 10)
                      . '. A handover is acknowledged when it happens, not afterwards.'];
    }
    if (!empty($issue['acknowledgedAt'])) {
        return [null, 'Already acknowledged on ' . substr((string)$issue['acknowledgedAt'], 0, 10) . '.'];
    }

    $via = (string)($data['via'] ?? '');
    if (!in_array($via, ICT_ISSUE_VIA, true)) {
        return [null, 'Say how it was acknowledged: paper (a signed form) or portal.'];
    }

    $ref = mb_substr(trim((string)($data['reference'] ?? '')), 0, 200);
    if ($via === 'paper' && $ref === '') {
        // A paper acknowledgement with no pointer to the paper is an assertion, not evidence.
        return [null, 'Where is the signed form filed? Record that, so it can be found again.'];
    }

    try {
        $pdo->prepare('UPDATE ict_assignments
                       SET acknowledged_at = NOW(), acknowledged_via = ?,
                           acknowledgement_ref = ?, acknowledged_by = ?
                       WHERE id = ? AND acknowledged_at IS NULL')
            ->execute([$via, $ref ?: null, $adminUserId, $id]);
    } catch (Throwable $e) {
        return [null, 'Nothing was changed: ' . $e->getMessage()];
    }
    return [ictIssueFind($pdo, $id), null];
}

/**
 * The numbers at the top of the issue book.
 *
 * valueUnknown is reported next to value on purpose: a total that silently counts eighty
 * items and ignores the ninety with no recorded cost reads as the value of everything out,
 * and is not.
 *
 * @return array<string,mixed>
 */
function ictIssueSummary(PDO $pdo): array
{
    $empty = ['out' => 0, 'holders' => 0, 'unacknowledged' => 0, 'sharedHolders' => 0,
              'possibleDuplicates' => 0, 'value' => 0.0, 'valueUnknown' => 0,
              'acknowledgementAvailable' => false];
    if (!ictAssetsEnsureTables($pdo)) return $empty;

    $holders = ictIssueHolders($pdo);
    $ack     = ictIssueEnsureColumns($pdo);

    $out = 0; $unack = 0; $shared = 0; $dupes = 0; $value = 0.0; $unknown = 0;
    foreach ($holders as $h) {
        $out     += $h['items'];
        $unack   += $h['unacknowledged'];
        $value   += $h['value'];
        $unknown += $h['items'] - $h['valueKnown'];
        if ($h['sharedHolder']) $shared += $h['items'];
        if ($h['possibleDuplicateOf']) $dupes++;
    }
    return [
        'out'                => $out,
        'holders'            => count($holders),
        'unacknowledged'     => $ack ? $unack : 0,
        'sharedHolders'      => $shared,
        'possibleDuplicates' => $dupes,
        'value'              => round($value, 2),
        'valueUnknown'       => $unknown,
        'acknowledgementAvailable' => $ack,
    ];
}
