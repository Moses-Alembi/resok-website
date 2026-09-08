<?php
declare(strict_types=1);

/**
 * ICT assets, assignments and maintenance.
 *
 * Replaces the equipment spreadsheet. Three things the shape of this file is built around:
 *
 * Assignment is history, not a field. "Who has this laptop" is the newest row with no return
 * date, and every previous holder stays on the record. A current_holder column would answer
 * today's question and destroy the answer to "who had it when it was damaged" - which is the
 * one that actually gets asked, usually months later.
 *
 * A holder is a person, not necessarily an account. Cleaners, drivers and interns are issued
 * equipment and will never have a portal login, so the name is stored directly and the user
 * link is filled in only when there is one.
 *
 * The QR code encodes the asset tag alone - never a URL. A QR that resolves to a public page
 * turns a sticker on a laptop into a way to read the inventory; scanning opens the portal,
 * which then asks who you are.
 */

const ICT_ASSET_CATEGORIES = [
    'laptop' => 'LAP', 'desktop' => 'DSK', 'monitor' => 'MON', 'tablet' => 'TAB',
    'phone' => 'PHN', 'printer' => 'PRN', 'scanner' => 'SCN', 'projector' => 'PRJ',
    'server' => 'SRV', 'router' => 'RTR', 'switch' => 'SWT', 'access_point' => 'WAP',
    'ups' => 'UPS', 'cctv' => 'CCT', 'storage' => 'STO', 'accessory' => 'ACC',
    // The organisation's register is not ICT-only - half of it is furniture, appliances and
    // clinical equipment. One register that answers "what do we own" beats two that each
    // answer half.
    'furniture' => 'FUR', 'appliance' => 'APP', 'medical' => 'MED', 'office' => 'OFF',
    'other' => 'GEN',
];

const ICT_CONDITIONS = ['new', 'good', 'fair', 'poor', 'damaged'];
const ICT_ASSET_STATUSES = ['available', 'assigned', 'maintenance', 'damaged', 'lost', 'retired', 'disposed'];

function ictAssetsEnsureTables(PDO $pdo): bool
{
    static $ready = null;
    if ($ready !== null) return $ready;
    try {
        $pdo->query('SELECT 1 FROM ict_assets LIMIT 1');
        $pdo->query('SELECT 1 FROM ict_assignments LIMIT 1');
        $pdo->query('SELECT 1 FROM ict_maintenance LIMIT 1');
        return $ready = true;
    } catch (Throwable $e) {
        error_log('ICT asset tables unavailable: ' . $e->getMessage());
        return $ready = false;
    }
}

/**
 * The next tag for a category, e.g. ICT-LAP-0042.
 *
 * Numbered per category rather than globally, because that is how people read a shelf of
 * stickers - the laptops run 0001 upwards whatever else was bought in between.
 */
function ictAssetNextTag(PDO $pdo, string $category): string
{
    $code = ICT_ASSET_CATEGORIES[$category] ?? 'GEN';
    $prefix = 'ICT-' . $code . '-';

    $stmt = $pdo->prepare('SELECT asset_tag FROM ict_assets WHERE asset_tag LIKE ?
                           ORDER BY LENGTH(asset_tag) DESC, asset_tag DESC LIMIT 1');
    $stmt->execute([$prefix . '%']);
    $last = $stmt->fetch();

    $next = 1;
    if ($last && preg_match('/(\d+)$/', (string)$last['asset_tag'], $m)) {
        $next = (int)$m[1] + 1;
    }
    return $prefix . str_pad((string)$next, 4, '0', STR_PAD_LEFT);
}

/** The open assignment for an asset, or null when nobody holds it. */
function ictAssetHolder(PDO $pdo, int $assetId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM ict_assignments
                           WHERE asset_id = ? AND returned_at IS NULL
                           ORDER BY assigned_at DESC, id DESC LIMIT 1');
    $stmt->execute([$assetId]);
    return $stmt->fetch() ?: null;
}

function ictAssetShape(array $row, ?array $holder = null): array
{
    $warranty = null;
    if (!empty($row['warranty_expires_on']) && function_exists('ictInfraHealth')) {
        // Same banding as infrastructure renewals, so an expiring warranty reads the same
        // way as an expiring domain and there is only one thing to learn.
        $warranty = ictInfraHealth($row['warranty_expires_on']);
    }
    return [
        'id'            => (int)$row['id'],
        'assetTag'      => $row['asset_tag'],
        'category'      => $row['category'],
        'name'          => $row['name'],
        'manufacturer'  => $row['manufacturer'],
        'model'         => $row['model'],
        'serialNumber'  => $row['serial_number'],
        'specifications'=> $row['specifications'],
        'purchaseDate'  => $row['purchase_date'],
        'purchaseCost'  => $row['purchase_cost'] === null ? null : (float)$row['purchase_cost'],
        'currency'      => $row['currency'],
        'supplier'      => $row['supplier'],
        'warrantyExpiresOn' => $row['warranty_expires_on'],
        'warranty'      => $warranty,
        'condition'     => $row['condition'],
        'status'        => $row['status'],
        'location'      => $row['location'],
        'department'    => $row['department'],
        'notes'         => $row['notes'],
        'holder'        => $holder ? [
            'assignmentId' => (int)$holder['id'],
            'name'         => $holder['holder_name'],
            'email'        => $holder['holder_email'],
            'department'   => $holder['department'],
            'since'        => $holder['assigned_at'],
            'conditionOut' => $holder['condition_out'],
        ] : null,
    ];
}

/**
 * The asset list, with the current holder resolved in one query rather than one per row.
 *
 * @param array{search?:string,status?:string,category?:string} $filters
 */
function ictAssetsList(PDO $pdo, array $filters = []): array
{
    if (!ictAssetsEnsureTables($pdo)) return [];

    $where = [];
    $args = [];
    if (!empty($filters['status']) && in_array($filters['status'], ICT_ASSET_STATUSES, true)) {
        $where[] = 'a.status = ?';
        $args[] = $filters['status'];
    }
    if (!empty($filters['category']) && isset(ICT_ASSET_CATEGORIES[$filters['category']])) {
        $where[] = 'a.category = ?';
        $args[] = $filters['category'];
    }
    if (!empty($filters['search'])) {
        // Tag, serial, name, model and the holder's name - the five things someone actually
        // has in front of them when they are looking for a record.
        $where[] = '(a.asset_tag LIKE ? OR a.serial_number LIKE ? OR a.name LIKE ?
                     OR a.model LIKE ? OR h.holder_name LIKE ?)';
        $like = '%' . $filters['search'] . '%';
        array_push($args, $like, $like, $like, $like, $like);
    }
    $clause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $sql = "SELECT a.*,
                   h.id AS h_id, h.holder_name, h.holder_email, h.department AS h_department,
                   h.assigned_at, h.condition_out
            FROM ict_assets a
            LEFT JOIN ict_assignments h
              ON h.asset_id = a.id AND h.returned_at IS NULL
            {$clause}
            ORDER BY a.asset_tag
            LIMIT 500";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($args);

    return array_map(function (array $row): array {
        $holder = $row['h_id'] ? [
            'id' => $row['h_id'], 'holder_name' => $row['holder_name'],
            'holder_email' => $row['holder_email'], 'department' => $row['h_department'],
            'assigned_at' => $row['assigned_at'], 'condition_out' => $row['condition_out'],
        ] : null;
        return ictAssetShape($row, $holder);
    }, $stmt->fetchAll());
}

/** One asset with its full history - what a QR scan opens. */
function ictAssetFind(PDO $pdo, int $id): ?array
{
    if (!ictAssetsEnsureTables($pdo)) return null;
    $stmt = $pdo->prepare('SELECT * FROM ict_assets WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) return null;

    $asset = ictAssetShape($row, ictAssetHolder($pdo, $id));

    $history = $pdo->prepare('SELECT * FROM ict_assignments WHERE asset_id = ?
                              ORDER BY assigned_at DESC, id DESC LIMIT 100');
    $history->execute([$id]);
    $asset['assignments'] = array_map(fn($h) => [
        'id'           => (int)$h['id'],
        'name'         => $h['holder_name'],
        'email'        => $h['holder_email'],
        'department'   => $h['department'],
        'assignedAt'   => $h['assigned_at'],
        'conditionOut' => $h['condition_out'],
        'returnedAt'   => $h['returned_at'],
        'conditionIn'  => $h['condition_in'],
        'notes'        => $h['handover_notes'],
        'returnNotes'  => $h['return_notes'],
    ], $history->fetchAll());

    $maint = $pdo->prepare('SELECT * FROM ict_maintenance WHERE asset_id = ?
                            ORDER BY performed_on DESC, id DESC LIMIT 100');
    $maint->execute([$id]);
    $asset['maintenance'] = array_map(fn($m) => [
        'id'          => (int)$m['id'],
        'performedOn' => $m['performed_on'],
        'kind'        => $m['kind'],
        'problem'     => $m['problem_reported'],
        'diagnosis'   => $m['diagnosis'],
        'workDone'    => $m['work_done'],
        'partsUsed'   => $m['parts_used'],
        'technician'  => $m['technician'],
        'vendor'      => $m['vendor'],
        'cost'        => $m['cost'] === null ? null : (float)$m['cost'],
        'currency'    => $m['currency'],
        'result'      => $m['result'],
        'nextDueOn'   => $m['next_due_on'],
        'notes'       => $m['notes'],
    ], $maint->fetchAll());

    return $asset;
}

/** Look one up by the tag on the sticker - the QR scan path. */
function ictAssetFindByTag(PDO $pdo, string $tag): ?array
{
    if (!ictAssetsEnsureTables($pdo)) return null;
    $stmt = $pdo->prepare('SELECT id FROM ict_assets WHERE asset_tag = ? LIMIT 1');
    $stmt->execute([trim($tag)]);
    $row = $stmt->fetch();
    return $row ? ictAssetFind($pdo, (int)$row['id']) : null;
}

/* ------------------------------------------------------------------------------------- */

function ictAssetWritableFields(): array
{
    return [
        'category'          => ['category', 'enum', array_keys(ICT_ASSET_CATEGORIES)],
        'name'              => ['name', 'string', 160],
        'manufacturer'      => ['manufacturer', 'string', 120],
        'model'             => ['model', 'string', 120],
        'serialNumber'      => ['serial_number', 'string', 120],
        'specifications'    => ['specifications', 'text'],
        'purchaseDate'      => ['purchase_date', 'date'],
        'purchaseCost'      => ['purchase_cost', 'decimal'],
        'supplier'          => ['supplier', 'string', 160],
        'warrantyExpiresOn' => ['warranty_expires_on', 'date'],
        'condition'         => ['condition', 'enum', ICT_CONDITIONS],
        'status'            => ['status', 'enum', ICT_ASSET_STATUSES],
        'location'          => ['location', 'string', 160],
        'department'        => ['department', 'string', 120],
        'notes'             => ['notes', 'text'],
    ];
}

function ictAssetNullable(): array
{
    return ['manufacturer', 'model', 'serial_number', 'specifications', 'purchase_date',
            'purchase_cost', 'supplier', 'warranty_expires_on', 'location', 'department', 'notes'];
}

/** @return array{0:array,1:array<string,string>} */
function ictAssetCollect(array $data, bool $isCreate): array
{
    $columns = [];
    $errors = [];

    foreach (ictAssetWritableFields() as $key => $spec) {
        if (!array_key_exists($key, $data)) continue;
        [$column, $kind] = $spec;
        $value = $data[$key];

        if ($value === null || $value === '') {
            if (in_array($column, ictAssetNullable(), true)) $columns[$column] = null;
            continue;
        }
        switch ($kind) {
            case 'enum':
                if (!in_array($value, $spec[2], true)) $errors[$key] = 'Choose one of: ' . implode(', ', $spec[2]) . '.';
                else $columns[$column] = $value;
                break;
            case 'decimal':
                if (!is_numeric($value) || (float)$value < 0) $errors[$key] = 'Must be an amount of zero or more.';
                else $columns[$column] = round((float)$value, 2);
                break;
            case 'date':
                $time = strtotime((string)$value);
                if ($time === false) $errors[$key] = 'Not a date we could read.';
                else $columns[$column] = date('Y-m-d', $time);
                break;
            case 'text':
                $columns[$column] = mb_substr((string)$value, 0, 4000);
                break;
            default:
                $columns[$column] = mb_substr((string)$value, 0, $spec[2] ?? 255);
        }
    }

    if ($isCreate) {
        if (empty($columns['name']) && !isset($errors['name'])) {
            $errors['name'] = 'Give the asset a name, such as "Dell Latitude 5420".';
        }
        if (empty($columns['category']) && !isset($errors['category'])) {
            $errors['category'] = 'Choose a category.';
        }
    }
    if (!empty($columns['purchase_date']) && !empty($columns['warranty_expires_on'])
        && $columns['warranty_expires_on'] < $columns['purchase_date']) {
        $errors['warrantyExpiresOn'] = 'Warranty cannot expire before the asset was bought.';
    }
    return [$columns, $errors];
}

/** @return array{0:?array,1:array<string,string>} */
function ictAssetCreate(PDO $pdo, array $data, ?int $adminUserId): array
{
    if (!ictAssetsEnsureTables($pdo)) {
        return [null, ['_' => 'The asset tables are not available. Apply schema-ict-assets.sql.']];
    }
    [$columns, $errors] = ictAssetCollect($data, true);
    if ($errors) return [null, $errors];

    // A tag may be supplied when labels already exist on the shelf; otherwise one is issued.
    $tag = trim((string)($data['assetTag'] ?? ''));
    if ($tag === '') {
        $tag = ictAssetNextTag($pdo, (string)$columns['category']);
    } else {
        $exists = $pdo->prepare('SELECT id FROM ict_assets WHERE asset_tag = ? LIMIT 1');
        $exists->execute([$tag]);
        if ($exists->fetch()) return [null, ['assetTag' => 'That asset tag is already in use.']];
    }
    $columns['asset_tag'] = $tag;
    $columns['created_by'] = $adminUserId;

    $names = array_map(fn($c) => $c === 'condition' ? '`condition`' : $c, array_keys($columns));
    $pdo->prepare('INSERT INTO ict_assets (' . implode(', ', $names) . ') VALUES ('
                  . implode(', ', array_fill(0, count($names), '?')) . ')')
        ->execute(array_values($columns));

    return [ictAssetFind($pdo, (int)$pdo->lastInsertId()), []];
}

/** @return array{0:?array,1:array<string,string>,2:array} [asset, errors, before] */
function ictAssetUpdate(PDO $pdo, int $id, array $data): array
{
    if (!ictAssetsEnsureTables($pdo)) return [null, ['_' => 'The asset tables are not available.'], []];
    $existing = ictAssetFind($pdo, $id);
    if (!$existing) return [null, ['_' => 'That asset no longer exists.'], []];

    [$columns, $errors] = ictAssetCollect($data, false);
    $bought = array_key_exists('purchase_date', $columns) ? $columns['purchase_date'] : $existing['purchaseDate'];
    $warranty = array_key_exists('warranty_expires_on', $columns) ? $columns['warranty_expires_on'] : $existing['warrantyExpiresOn'];
    if ($bought && $warranty && $warranty < $bought) {
        $errors['warrantyExpiresOn'] = 'Warranty cannot expire before the asset was bought.';
    }
    if ($errors) return [null, $errors, []];
    if (!$columns) return [$existing, [], []];

    $set = implode(', ', array_map(
        fn($c) => ($c === 'condition' ? '`condition`' : $c) . ' = ?', array_keys($columns)));
    $pdo->prepare("UPDATE ict_assets SET {$set} WHERE id = ?")
        ->execute([...array_values($columns), $id]);

    return [ictAssetFind($pdo, $id), [], $existing];
}

/* ------------------------------------------------------------------------------------- */

/**
 * Hands an asset to someone.
 *
 * Refuses if it is already out. Reassigning without a return would leave two open rows and
 * no honest answer to who is holding it - the record has to be returned first, which is also
 * what should happen physically.
 *
 * @return array{0:?array,1:?string} [asset, error]
 */
function ictAssetAssign(PDO $pdo, int $assetId, array $data, ?int $adminUserId): array
{
    if (!ictAssetsEnsureTables($pdo)) return [null, 'The asset tables are not available.'];

    $asset = ictAssetFind($pdo, $assetId);
    if (!$asset) return [null, 'That asset no longer exists.'];
    if ($asset['holder']) {
        return [null, 'This is currently with ' . $asset['holder']['name'] . '. Record its return first.'];
    }
    if (in_array($asset['status'], ['retired', 'disposed', 'lost'], true)) {
        return [null, 'An asset marked ' . $asset['status'] . ' cannot be assigned.'];
    }

    $name = trim((string)($data['holderName'] ?? ''));
    if ($name === '') return [null, 'Who is receiving it?'];

    $condition = in_array($data['conditionOut'] ?? '', ICT_CONDITIONS, true)
        ? $data['conditionOut'] : $asset['condition'];

    // Linked to an account when the address matches one, so an asset shows up against a
    // member record - but never required, because most holders have no login.
    $userId = null;
    $email = trim((string)($data['holderEmail'] ?? ''));
    if ($email !== '') {
        $stmt = $pdo->prepare('SELECT id FROM users WHERE LOWER(email) = ? LIMIT 1');
        $stmt->execute([strtolower($email)]);
        if ($found = $stmt->fetch()) $userId = (int)$found['id'];
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare('INSERT INTO ict_assignments
                        (asset_id, holder_user_id, holder_name, holder_email, department,
                         assigned_at, assigned_by, condition_out, handover_notes)
                       VALUES (?, ?, ?, ?, ?, NOW(), ?, ?, ?)')
            ->execute([$assetId, $userId, mb_substr($name, 0, 160), $email ?: null,
                       mb_substr(trim((string)($data['department'] ?? '')), 0, 120) ?: null,
                       $adminUserId, $condition,
                       mb_substr(trim((string)($data['notes'] ?? '')), 0, 4000) ?: null]);
        $pdo->prepare("UPDATE ict_assets SET status = 'assigned' WHERE id = ?")->execute([$assetId]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        return [null, 'Nothing was changed: ' . $e->getMessage()];
    }
    return [ictAssetFind($pdo, $assetId), null];
}

/**
 * Records a return.
 *
 * The condition it comes back in sets the asset's condition, and anything worse than fair
 * sends it to maintenance rather than straight back on the shelf - otherwise a damaged
 * laptop gets handed to the next person.
 *
 * @return array{0:?array,1:?string}
 */
function ictAssetReturn(PDO $pdo, int $assetId, array $data, ?int $adminUserId): array
{
    if (!ictAssetsEnsureTables($pdo)) return [null, 'The asset tables are not available.'];

    $holder = ictAssetHolder($pdo, $assetId);
    if (!$holder) return [null, 'Nobody is currently holding this.'];

    $condition = in_array($data['conditionIn'] ?? '', ICT_CONDITIONS, true)
        ? (string)$data['conditionIn'] : 'good';
    $status = in_array($condition, ['poor', 'damaged'], true) ? 'maintenance' : 'available';

    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE ict_assignments
                       SET returned_at = NOW(), received_by = ?, condition_in = ?, return_notes = ?
                       WHERE id = ?')
            ->execute([$adminUserId, $condition,
                       mb_substr(trim((string)($data['notes'] ?? '')), 0, 4000) ?: null,
                       (int)$holder['id']]);
        $pdo->prepare('UPDATE ict_assets SET status = ?, `condition` = ? WHERE id = ?')
            ->execute([$status, $condition, $assetId]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        return [null, 'Nothing was changed: ' . $e->getMessage()];
    }
    return [ictAssetFind($pdo, $assetId), null];
}

/** Everything currently held by one person - what to run when someone leaves. */
function ictAssetsHeldBy(PDO $pdo, string $email): array
{
    if (!ictAssetsEnsureTables($pdo)) return [];
    $stmt = $pdo->prepare('SELECT a.*, h.holder_name, h.assigned_at
                           FROM ict_assignments h
                           JOIN ict_assets a ON a.id = h.asset_id
                           WHERE h.returned_at IS NULL AND LOWER(h.holder_email) = ?
                           ORDER BY h.assigned_at');
    $stmt->execute([strtolower(trim($email))]);
    return array_map(fn($r) => [
        'id'       => (int)$r['id'],
        'assetTag' => $r['asset_tag'],
        'name'     => $r['name'],
        'category' => $r['category'],
        'since'    => $r['assigned_at'],
    ], $stmt->fetchAll());
}

/* ------------------------------------------------------------------------------------- */

/** @return array{0:?array,1:?string} */
function ictMaintenanceAdd(PDO $pdo, int $assetId, array $data, ?int $adminUserId): array
{
    if (!ictAssetsEnsureTables($pdo)) return [null, 'The asset tables are not available.'];
    if (!ictAssetFind($pdo, $assetId)) return [null, 'That asset no longer exists.'];

    $performed = strtotime((string)($data['performedOn'] ?? 'today'));
    if ($performed === false) return [null, 'Not a date we could read.'];

    $kinds = ['preventive', 'repair', 'upgrade', 'inspection', 'decommission'];
    $results = ['resolved', 'unresolved', 'replaced', 'written_off'];
    $kind = in_array($data['kind'] ?? '', $kinds, true) ? $data['kind'] : 'repair';
    $result = in_array($data['result'] ?? '', $results, true) ? $data['result'] : 'resolved';

    $nextDue = null;
    if (!empty($data['nextDueOn'])) {
        $t = strtotime((string)$data['nextDueOn']);
        if ($t === false) return [null, 'The next-due date could not be read.'];
        $nextDue = date('Y-m-d', $t);
    }
    $cost = ($data['cost'] ?? '') === '' ? null : (float)$data['cost'];
    if ($cost !== null && $cost < 0) return [null, 'Cost cannot be negative.'];

    $text = fn($key, $max = 4000) => mb_substr(trim((string)($data[$key] ?? '')), 0, $max) ?: null;

    $pdo->beginTransaction();
    try {
        $pdo->prepare('INSERT INTO ict_maintenance
                        (asset_id, performed_on, kind, problem_reported, diagnosis, work_done,
                         parts_used, technician, vendor, cost, result, next_due_on, notes, recorded_by)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$assetId, date('Y-m-d', $performed), $kind, $text('problem'),
                       $text('diagnosis'), $text('workDone'), $text('partsUsed'),
                       $text('technician', 160), $text('vendor', 160), $cost, $result,
                       $nextDue, $text('notes'), $adminUserId]);

        // A write-off is the end of the asset's life, so the status follows rather than
        // leaving something scrapped sitting in the available pool.
        if ($result === 'written_off') {
            $pdo->prepare("UPDATE ict_assets SET status = 'retired' WHERE id = ?")->execute([$assetId]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        return [null, 'Nothing was recorded: ' . $e->getMessage()];
    }
    return [ictAssetFind($pdo, $assetId), null];
}

/** Counts for the ICT Overview. */
function ictAssetsSummary(PDO $pdo): array
{
    if (!ictAssetsEnsureTables($pdo)) {
        return ['total' => 0, 'byStatus' => [], 'value' => 0.0, 'warrantyExpiring' => 0, 'maintenanceDue' => 0];
    }
    $byStatus = [];
    foreach ($pdo->query('SELECT status, COUNT(*) c FROM ict_assets GROUP BY status')->fetchAll() as $row) {
        $byStatus[$row['status']] = (int)$row['c'];
    }
    $one = fn(string $sql) => (int)($pdo->query($sql)->fetch()['c'] ?? 0);

    return [
        'total'    => array_sum($byStatus),
        'byStatus' => $byStatus,
        'value'    => (float)($pdo->query("SELECT COALESCE(SUM(purchase_cost),0) v FROM ict_assets
                                           WHERE status NOT IN ('disposed','lost')")->fetch()['v'] ?? 0),
        'warrantyExpiring' => $one("SELECT COUNT(*) c FROM ict_assets
                                     WHERE warranty_expires_on IS NOT NULL
                                       AND warranty_expires_on BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 60 DAY)"),
        'maintenanceDue'   => $one("SELECT COUNT(*) c FROM ict_maintenance
                                     WHERE next_due_on IS NOT NULL AND next_due_on <= CURDATE()"),
    ];
}
