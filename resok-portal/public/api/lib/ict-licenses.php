<?php
declare(strict_types=1);

/**
 * Software and licences.
 *
 * The smallest module left, because it reuses two things already built: the renewal banding
 * from ict_infrastructure, so an expiring licence reads exactly like an expiring domain, and
 * the append-only allocation pattern from asset assignments, so a released seat keeps its
 * row.
 *
 * That second choice is what makes the useful question answerable. "We pay for ten seats and
 * four people use them" is only knowable if seats are counted rather than typed - and a
 * used_seats column would be wrong within a month.
 *
 * Licence keys are not stored here. A key is a secret and the reasoning from the credential
 * register applies unchanged: it would sit on the same server as the key protecting it.
 * There is a vault link instead.
 */

const ICT_LICENSE_KINDS = ['subscription', 'perpetual', 'open_source', 'trial', 'oem'];

function ictLicensesEnsureTables(PDO $pdo): bool
{
    static $ready = null;
    if ($ready !== null) return $ready;
    try {
        $pdo->query('SELECT 1 FROM ict_licenses LIMIT 1');
        $pdo->query('SELECT 1 FROM ict_license_seats LIMIT 1');
        return $ready = true;
    } catch (Throwable $e) {
        error_log('ICT licence tables unavailable: ' . $e->getMessage());
        return $ready = false;
    }
}

function ictLicenseShape(array $row, int $seatsUsed = 0): array
{
    $health = null;
    if (!empty($row['expires_on']) && function_exists('ictInfraHealth')) {
        $health = ictInfraHealth($row['expires_on'], $row['status'] === 'cancelled' ? 'cancelled' : 'active');
    }
    $total = (int)$row['seats_total'];
    return [
        'id'          => (int)$row['id'],
        'name'        => $row['name'],
        'vendor'      => $row['vendor'],
        'kind'        => $row['kind'],
        'category'    => $row['category'],
        'vaultUrl'    => $row['vault_url'],
        'vaultReference' => $row['vault_reference'],
        'consoleUrl'  => $row['console_url'],
        'accountId'   => $row['account_identifier'],
        'seatsTotal'  => $total,
        'seatsUsed'   => $seatsUsed,
        // Null when the licence is not seat-based; otherwise how many are going spare, which
        // is the number that turns into money at renewal.
        'seatsFree'   => $total > 0 ? max(0, $total - $seatsUsed) : null,
        'seatsOver'   => $total > 0 && $seatsUsed > $total,
        'purchasedOn' => $row['purchased_on'],
        'expiresOn'   => $row['expires_on'],
        'autoRenew'   => (bool)(int)$row['auto_renew'],
        'cost'        => $row['cost'] === null ? null : (float)$row['cost'],
        'currency'    => $row['currency'],
        'billingCycle'=> $row['billing_cycle'],
        'ownerName'   => $row['owner_name'],
        'ownerEmail'  => $row['owner_email'] ?? null,
        'status'      => $row['status'],
        'notes'       => $row['notes'],
        'health'      => $health,
    ];
}

/** Every licence, with seats counted rather than trusted. */
function ictLicensesList(PDO $pdo): array
{
    if (!ictLicensesEnsureTables($pdo)) return [];
    $rows = $pdo->query("SELECT l.*, u.email AS owner_email,
                                (SELECT COUNT(*) FROM ict_license_seats s
                                  WHERE s.license_id = l.id AND s.released_at IS NULL) AS seats_used
                         FROM ict_licenses l
                         LEFT JOIN users u ON u.id = l.owner_user_id
                         ORDER BY l.status <> 'active',
                                  l.expires_on IS NULL, l.expires_on, l.name")->fetchAll();
    return array_map(fn($r) => ictLicenseShape($r, (int)$r['seats_used']), $rows);
}

/** One licence with who currently holds a seat, and who used to. */
function ictLicenseFind(PDO $pdo, int $id): ?array
{
    if (!ictLicensesEnsureTables($pdo)) return null;
    $stmt = $pdo->prepare("SELECT l.*, u.email AS owner_email,
                                  (SELECT COUNT(*) FROM ict_license_seats s
                                    WHERE s.license_id = l.id AND s.released_at IS NULL) AS seats_used
                           FROM ict_licenses l
                           LEFT JOIN users u ON u.id = l.owner_user_id
                           WHERE l.id = ? LIMIT 1");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) return null;

    $licence = ictLicenseShape($row, (int)$row['seats_used']);

    $seats = $pdo->prepare('SELECT * FROM ict_license_seats WHERE license_id = ?
                            ORDER BY released_at IS NOT NULL, assigned_at DESC LIMIT 200');
    $seats->execute([$id]);
    $licence['seats'] = array_map(fn($s) => [
        'id'         => (int)$s['id'],
        'name'       => $s['holder_name'],
        'email'      => $s['holder_email'],
        'department' => $s['department'],
        'assignedAt' => $s['assigned_at'],
        'releasedAt' => $s['released_at'],
        'notes'      => $s['notes'],
    ], $seats->fetchAll());

    return $licence;
}

/** The figures worth putting on the Overview. */
function ictLicensesSummary(PDO $pdo): array
{
    if (!ictLicensesEnsureTables($pdo)) {
        return ['total' => 0, 'expiringSoon' => 0, 'expired' => 0,
                'annualCost' => 0.0, 'seatsIdle' => 0, 'overAllocated' => 0];
    }
    $one = fn(string $sql) => (int)($pdo->query($sql)->fetch()['c'] ?? 0);

    // Seats paid for and not in use. The one figure here that turns directly into money.
    $idle = 0;
    $over = 0;
    foreach (ictLicensesList($pdo) as $licence) {
        if ($licence['status'] !== 'active' || $licence['seatsTotal'] <= 0) continue;
        $idle += max(0, $licence['seatsTotal'] - $licence['seatsUsed']);
        if ($licence['seatsOver']) $over++;
    }

    return [
        'total'        => $one("SELECT COUNT(*) c FROM ict_licenses WHERE status = 'active'"),
        'expiringSoon' => $one("SELECT COUNT(*) c FROM ict_licenses
                                 WHERE status = 'active' AND expires_on IS NOT NULL
                                   AND expires_on BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 60 DAY)"),
        'expired'      => $one("SELECT COUNT(*) c FROM ict_licenses
                                 WHERE status = 'active' AND expires_on IS NOT NULL AND expires_on < CURDATE()"),
        'annualCost'   => (float)($pdo->query("SELECT COALESCE(SUM(cost),0) v FROM ict_licenses
                                               WHERE status = 'active'")->fetch()['v'] ?? 0),
        'seatsIdle'    => $idle,
        'overAllocated'=> $over,
    ];
}

/* ------------------------------------------------------------------------------------- */

function ictLicenseWritableFields(): array
{
    return [
        'name'           => ['name', 'string', 160],
        'vendor'         => ['vendor', 'string', 160],
        'kind'           => ['kind', 'enum', ICT_LICENSE_KINDS],
        'category'       => ['category', 'string', 80],
        'vaultUrl'       => ['vault_url', 'url', 500],
        'vaultReference' => ['vault_reference', 'string', 160],
        'consoleUrl'     => ['console_url', 'url', 500],
        'accountId'      => ['account_identifier', 'string', 190],
        'seatsTotal'     => ['seats_total', 'int'],
        'purchasedOn'    => ['purchased_on', 'date'],
        'expiresOn'      => ['expires_on', 'date'],
        'autoRenew'      => ['auto_renew', 'bool'],
        'cost'           => ['cost', 'decimal'],
        'billingCycle'   => ['billing_cycle', 'string', 30],
        'ownerName'      => ['owner_name', 'string', 160],
        'status'         => ['status', 'enum', ['active', 'expired', 'cancelled']],
        'notes'          => ['notes', 'text'],
    ];
}

function ictLicenseNullable(): array
{
    return ['vendor', 'category', 'vault_url', 'vault_reference', 'console_url',
            'account_identifier', 'purchased_on', 'expires_on', 'cost', 'billing_cycle',
            'owner_name', 'notes'];
}

/** @return array{0:array,1:array<string,string>} */
function ictLicenseCollect(array $data, bool $isCreate): array
{
    $columns = [];
    $errors = [];

    // Same rule as the credential register. A licence key is a secret, and the place it does
    // not belong is a database whose encryption key sits beside it.
    foreach (['licenseKey', 'key', 'serial', 'activationCode', 'productKey'] as $forbidden) {
        if (array_key_exists($forbidden, $data) && trim((string)$data[$forbidden]) !== '') {
            $errors['_'] = 'Licence keys are not stored here - only where to find them. '
                         . 'Put the key in the password manager and link to it.';
            return [[], $errors];
        }
    }

    foreach (ictLicenseWritableFields() as $key => $spec) {
        if (!array_key_exists($key, $data)) continue;
        [$column, $kind] = $spec;
        $value = $data[$key];

        if ($value === null || $value === '') {
            if (in_array($column, ictLicenseNullable(), true)) $columns[$column] = null;
            continue;
        }
        switch ($kind) {
            case 'enum':
                if (!in_array($value, $spec[2], true)) $errors[$key] = 'Choose one of: ' . implode(', ', $spec[2]) . '.';
                else $columns[$column] = $value;
                break;
            case 'bool':
                $columns[$column] = filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
                break;
            case 'int':
                if (!is_numeric($value) || (int)$value < 0) $errors[$key] = 'Must be zero or more.';
                else $columns[$column] = (int)$value;
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
            case 'url':
                $url = trim((string)$value);
                if (!preg_match('#^https?://#i', $url)) $errors[$key] = 'Must start with http:// or https://';
                else $columns[$column] = mb_substr($url, 0, $spec[2] ?? 500);
                break;
            case 'text':
                $columns[$column] = mb_substr((string)$value, 0, 4000);
                break;
            default:
                $columns[$column] = mb_substr((string)$value, 0, $spec[2] ?? 255);
        }
    }

    if ($isCreate && empty($columns['name']) && !isset($errors['name'])) {
        $errors['name'] = 'Name the software, for example "Microsoft 365 Business".';
    }
    if (!empty($columns['purchased_on']) && !empty($columns['expires_on'])
        && $columns['expires_on'] < $columns['purchased_on']) {
        $errors['expiresOn'] = 'A licence cannot expire before it was bought.';
    }
    // Editing the renewal date re-arms the alert, so moving a date forward warns again
    // rather than staying quiet because the old date already did.
    if (array_key_exists('expires_on', $columns)) {
        $columns['last_alert_band'] = null;
        $columns['last_alert_on'] = null;
    }
    return [$columns, $errors];
}

/** @return array{0:?array,1:array<string,string>} */
function ictLicenseCreate(PDO $pdo, array $data, ?int $adminUserId): array
{
    if (!ictLicensesEnsureTables($pdo)) {
        return [null, ['_' => 'The licence tables are not available. Apply schema-ict-licenses.sql.']];
    }
    [$columns, $errors] = ictLicenseCollect($data, true);
    if ($errors) return [null, $errors];
    $columns['created_by'] = $adminUserId;

    $names = array_keys($columns);
    $pdo->prepare('INSERT INTO ict_licenses (' . implode(', ', $names) . ') VALUES ('
                  . implode(', ', array_fill(0, count($names), '?')) . ')')
        ->execute(array_values($columns));

    return [ictLicenseFind($pdo, (int)$pdo->lastInsertId()), []];
}

/** @return array{0:?array,1:array<string,string>,2:array} */
function ictLicenseUpdate(PDO $pdo, int $id, array $data): array
{
    if (!ictLicensesEnsureTables($pdo)) return [null, ['_' => 'The licence tables are not available.'], []];
    $existing = ictLicenseFind($pdo, $id);
    if (!$existing) return [null, ['_' => 'That licence no longer exists.'], []];

    [$columns, $errors] = ictLicenseCollect($data, false);
    $bought = array_key_exists('purchased_on', $columns) ? $columns['purchased_on'] : $existing['purchasedOn'];
    $expires = array_key_exists('expires_on', $columns) ? $columns['expires_on'] : $existing['expiresOn'];
    if ($bought && $expires && $expires < $bought) {
        $errors['expiresOn'] = 'A licence cannot expire before it was bought.';
    }
    if ($errors) return [null, $errors, []];
    if (!$columns) return [$existing, [], []];

    $set = implode(', ', array_map(fn($c) => "{$c} = ?", array_keys($columns)));
    $pdo->prepare("UPDATE ict_licenses SET {$set} WHERE id = ?")
        ->execute([...array_values($columns), $id]);

    return [ictLicenseFind($pdo, $id), [], $existing];
}

/* ------------------------------------------------------------------------------------- */

/**
 * Gives someone a seat.
 *
 * Refuses when every seat is taken. Silently exceeding a seat count is how an organisation
 * discovers it is out of compliance during an audit rather than at the moment it happened.
 *
 * @return array{0:?array,1:?string}
 */
function ictLicenseAssignSeat(PDO $pdo, int $licenseId, array $data, ?int $adminUserId): array
{
    if (!ictLicensesEnsureTables($pdo)) return [null, 'The licence tables are not available.'];
    $licence = ictLicenseFind($pdo, $licenseId);
    if (!$licence) return [null, 'That licence no longer exists.'];
    if ($licence['status'] !== 'active') return [null, 'That licence is ' . $licence['status'] . '.'];

    $name = trim((string)($data['holderName'] ?? ''));
    if ($name === '') return [null, 'Who is the seat for?'];

    if ($licence['seatsTotal'] > 0 && $licence['seatsUsed'] >= $licence['seatsTotal']) {
        return [null, 'All ' . $licence['seatsTotal'] . ' seats are in use. Release one first, or buy another.'];
    }

    $email = trim((string)($data['holderEmail'] ?? ''));
    // The same person twice on one licence is one seat, not two.
    if ($email !== '') {
        $dupe = $pdo->prepare('SELECT id FROM ict_license_seats
                               WHERE license_id = ? AND released_at IS NULL AND LOWER(holder_email) = ? LIMIT 1');
        $dupe->execute([$licenseId, strtolower($email)]);
        if ($dupe->fetch()) return [null, $name . ' already holds a seat on this licence.'];
    }

    $userId = null;
    if ($email !== '') {
        $stmt = $pdo->prepare('SELECT id FROM users WHERE LOWER(email) = ? LIMIT 1');
        $stmt->execute([strtolower($email)]);
        if ($found = $stmt->fetch()) $userId = (int)$found['id'];
    }

    $pdo->prepare('INSERT INTO ict_license_seats
                    (license_id, holder_user_id, holder_name, holder_email, department,
                     assigned_at, assigned_by, notes)
                   VALUES (?, ?, ?, ?, ?, NOW(), ?, ?)')
        ->execute([$licenseId, $userId, mb_substr($name, 0, 160), $email ?: null,
                   mb_substr(trim((string)($data['department'] ?? '')), 0, 120) ?: null,
                   $adminUserId, mb_substr(trim((string)($data['notes'] ?? '')), 0, 300) ?: null]);

    return [ictLicenseFind($pdo, $licenseId), null];
}

/** @return array{0:?array,1:?string} */
function ictLicenseReleaseSeat(PDO $pdo, int $seatId, ?int $adminUserId): array
{
    if (!ictLicensesEnsureTables($pdo)) return [null, 'The licence tables are not available.'];
    $stmt = $pdo->prepare('SELECT * FROM ict_license_seats WHERE id = ? LIMIT 1');
    $stmt->execute([$seatId]);
    $seat = $stmt->fetch();
    if (!$seat) return [null, 'That seat no longer exists.'];
    if ($seat['released_at'] !== null) return [null, 'That seat was already released.'];

    // Closed, never deleted - the row is how "who used this last year" stays answerable.
    $pdo->prepare('UPDATE ict_license_seats SET released_at = NOW(), released_by = ? WHERE id = ?')
        ->execute([$adminUserId, $seatId]);

    return [ictLicenseFind($pdo, (int)$seat['license_id']), null];
}

/** Licences whose alert band has changed since the last mail - the cron's work list. */
function ictLicensesDueForAlert(PDO $pdo): array
{
    if (!ictLicensesEnsureTables($pdo)) return [];
    $rows = $pdo->query("SELECT * FROM ict_licenses
                         WHERE status = 'active' AND expires_on IS NOT NULL")->fetchAll();
    $due = [];
    foreach ($rows as $row) {
        $health = ictInfraHealth($row['expires_on']);
        if (!in_array($health['band'], ['expired', 'critical', 'warning'], true)) continue;
        if ((string)($row['last_alert_band'] ?? '') === $health['band']) continue;
        $due[] = ['row' => $row, 'health' => $health];
    }
    return $due;
}

function ictLicenseMarkAlerted(PDO $pdo, int $id, string $band): void
{
    $pdo->prepare('UPDATE ict_licenses SET last_alert_band = ?, last_alert_on = CURDATE() WHERE id = ?')
        ->execute([$band, $id]);
}
