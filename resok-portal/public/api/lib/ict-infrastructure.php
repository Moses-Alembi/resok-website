<?php
declare(strict_types=1);

/**
 * Digital infrastructure: domain, hosting, SSL, backups, repository.
 *
 * The smallest module in the ICT programme and the one with the highest consequence. A
 * missed domain or SSL renewal takes the entire website down, and the whole defence is a
 * date column and a scheduled job.
 *
 * One table with a kind column rather than five tables holding one row each - see
 * schema-ict-infrastructure.sql for why.
 *
 * Nothing secret lives here. account_ref is a username or customer number; passwords are a
 * separate module and a separate decision.
 */

const ICT_KINDS = ['domain', 'hosting', 'ssl', 'backup', 'repository', 'service'];

/**
 * Alert bands, in days remaining.
 *
 * Also used by the cron to decide whether anything needs saying. Emailing "expires in 90
 * days" every morning for ninety mornings teaches people to delete the message unread, so a
 * mail goes out when a record crosses INTO a band, not while it sits in one.
 */
const ICT_BAND_CRITICAL = 14;
const ICT_BAND_WARNING  = 30;
const ICT_BAND_NOTICE   = 90;

function ictInfraEnsureTable(PDO $pdo): bool
{
    static $ready = null;
    if ($ready !== null) return $ready;
    try {
        $pdo->query('SELECT 1 FROM ict_infrastructure LIMIT 1');
        return $ready = true;
    } catch (Throwable $e) {
        // Absent - build it.
    }
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS ict_infrastructure (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            kind ENUM('domain','hosting','ssl','backup','repository','service') NOT NULL,
            name VARCHAR(160) NOT NULL,
            provider VARCHAR(160) NULL,
            account_ref VARCHAR(160) NULL,
            url VARCHAR(500) NULL,
            starts_on DATE NULL,
            expires_on DATE NULL,
            auto_renew TINYINT(1) NOT NULL DEFAULT 0,
            cost DECIMAL(10,2) NULL,
            currency CHAR(3) NOT NULL DEFAULT 'KES',
            billing_cycle VARCHAR(30) NULL,
            owner_user_id INT UNSIGNED NULL,
            status ENUM('active','pending','expired','cancelled') NOT NULL DEFAULT 'active',
            details TEXT NULL,
            notes TEXT NULL,
            last_verified_on DATE NULL,
            last_alert_band VARCHAR(20) NULL,
            last_alert_on DATE NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY ict_infra_kind (kind, status),
            KEY ict_infra_expiry (expires_on)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        return $ready = true;
    } catch (Throwable $e) {
        error_log('ict_infrastructure unavailable: ' . $e->getMessage());
        return $ready = false;
    }
}

/**
 * Days until a record expires, and which band that puts it in.
 *
 * @return array{days:?int,band:string,label:string}
 */
function ictInfraHealth(?string $expiresOn, string $status = 'active'): array
{
    if ($status === 'cancelled') return ['days' => null, 'band' => 'cancelled', 'label' => 'Cancelled'];
    if ($expiresOn === null || $expiresOn === '') {
        // Not a failure - a git repository has no expiry. Reported as unknown rather than
        // healthy, so a domain whose date nobody filled in does not read as fine.
        return ['days' => null, 'band' => 'unknown', 'label' => 'No renewal date'];
    }

    // The leading ! resets the time to midnight. Without it createFromFormat keeps the
    // CURRENT time of day, so a date three days past compares as two days and twenty-two
    // hours - and reports "expired 2 days ago". Off by one, in the direction that matters.
    $today = new DateTimeImmutable(date('Y-m-d'));
    $due = DateTimeImmutable::createFromFormat('!Y-m-d', substr($expiresOn, 0, 10));
    if (!$due) return ['days' => null, 'band' => 'unknown', 'label' => 'No renewal date'];

    $days = (int)$today->diff($due)->format('%r%a');

    if ($days < 0)                     return ['days' => $days, 'band' => 'expired',  'label' => 'Expired ' . abs($days) . ' day(s) ago'];
    if ($days === 0)                   return ['days' => 0,     'band' => 'expired',  'label' => 'Expires today'];
    if ($days <= ICT_BAND_CRITICAL)    return ['days' => $days, 'band' => 'critical', 'label' => 'Expires in ' . $days . ' day(s)'];
    if ($days <= ICT_BAND_WARNING)     return ['days' => $days, 'band' => 'warning',  'label' => 'Expires in ' . $days . ' days'];
    if ($days <= ICT_BAND_NOTICE)      return ['days' => $days, 'band' => 'notice',   'label' => 'Expires in ' . $days . ' days'];
    return ['days' => $days, 'band' => 'ok', 'label' => 'Expires in ' . $days . ' days'];
}

function ictInfraShape(array $row): array
{
    $health = ictInfraHealth($row['expires_on'] ?? null, (string)($row['status'] ?? 'active'));
    return [
        'id'             => (int)$row['id'],
        'kind'           => $row['kind'],
        'name'           => $row['name'],
        'provider'       => $row['provider'],
        'accountRef'     => $row['account_ref'],
        'url'            => $row['url'],
        'startsOn'       => $row['starts_on'],
        'expiresOn'      => $row['expires_on'],
        'autoRenew'      => (bool)(int)$row['auto_renew'],
        'cost'           => $row['cost'] === null ? null : (float)$row['cost'],
        'currency'       => $row['currency'],
        'billingCycle'   => $row['billing_cycle'],
        'ownerUserId'    => $row['owner_user_id'] === null ? null : (int)$row['owner_user_id'],
        'ownerEmail'     => $row['owner_email'] ?? null,
        'status'         => $row['status'],
        'details'        => $row['details'],
        'notes'          => $row['notes'],
        'lastVerifiedOn' => $row['last_verified_on'],
        'health'         => $health,
    ];
}

/** Everything, soonest expiry first - so what needs attention is at the top by default. */
function ictInfraAll(PDO $pdo): array
{
    if (!ictInfraEnsureTable($pdo)) return [];
    $rows = $pdo->query("SELECT i.*, u.email AS owner_email
                         FROM ict_infrastructure i
                         LEFT JOIN users u ON u.id = i.owner_user_id
                         ORDER BY i.status = 'cancelled', i.expires_on IS NULL, i.expires_on ASC, i.kind")->fetchAll();
    return array_map('ictInfraShape', $rows);
}

function ictInfraFind(PDO $pdo, int $id): ?array
{
    if (!ictInfraEnsureTable($pdo)) return null;
    $stmt = $pdo->prepare('SELECT i.*, u.email AS owner_email FROM ict_infrastructure i
                           LEFT JOIN users u ON u.id = i.owner_user_id WHERE i.id = ? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ? ictInfraShape($row) : null;
}

/** The counts and worst-case state, for the ICT Overview. */
function ictInfraSummary(PDO $pdo): array
{
    $all = ictInfraAll($pdo);
    $bands = ['expired' => 0, 'critical' => 0, 'warning' => 0, 'notice' => 0, 'ok' => 0, 'unknown' => 0, 'cancelled' => 0];
    $attention = [];

    foreach ($all as $item) {
        $band = $item['health']['band'];
        if (isset($bands[$band])) $bands[$band]++;
        if (in_array($band, ['expired', 'critical', 'warning'], true)) {
            $attention[] = ['name' => $item['name'], 'kind' => $item['kind'], 'label' => $item['health']['label'], 'band' => $band];
        }
    }

    // The overall light is the worst single item, not an average. One expired domain is not
    // offset by four healthy records, and a dashboard that averages it away is worse than none.
    $overall = 'ok';
    foreach (['expired', 'critical', 'warning', 'notice'] as $band) {
        if ($bands[$band] > 0) { $overall = $band; break; }
    }
    if ($overall === 'ok' && $bands['ok'] === 0 && $bands['unknown'] > 0) $overall = 'unknown';

    return ['total' => count($all), 'bands' => $bands, 'overall' => $overall, 'attention' => $attention];
}

/* ------------------------------------------------------------------------------------- */

function ictInfraWritableFields(): array
{
    return [
        'kind'           => ['kind', 'enum', ICT_KINDS],
        'name'           => ['name', 'string', 160],
        'provider'       => ['provider', 'string', 160],
        'accountRef'     => ['account_ref', 'string', 160],
        'url'            => ['url', 'string', 500],
        'startsOn'       => ['starts_on', 'date'],
        'expiresOn'      => ['expires_on', 'date'],
        'autoRenew'      => ['auto_renew', 'bool'],
        'cost'           => ['cost', 'decimal'],
        'currency'       => ['currency', 'string', 3],
        'billingCycle'   => ['billing_cycle', 'string', 30],
        'ownerUserId'    => ['owner_user_id', 'int'],
        'status'         => ['status', 'enum', ['active', 'pending', 'expired', 'cancelled']],
        'details'        => ['details', 'text'],
        'notes'          => ['notes', 'text'],
        'lastVerifiedOn' => ['last_verified_on', 'date'],
    ];
}

/** Columns that accept NULL; the rest keep their stored value when a field is cleared. */
function ictInfraNullable(): array
{
    return ['provider', 'account_ref', 'url', 'starts_on', 'expires_on', 'cost',
            'billing_cycle', 'owner_user_id', 'details', 'notes', 'last_verified_on'];
}

/** @return array{0:array,1:array<string,string>} [columns, errors] */
function ictInfraCollect(array $data, bool $isCreate): array
{
    $columns = [];
    $errors = [];

    foreach (ictInfraWritableFields() as $key => $spec) {
        if (!array_key_exists($key, $data)) continue;
        [$column, $kind] = $spec;
        $value = $data[$key];

        if ($value === null || $value === '') {
            if (in_array($column, ictInfraNullable(), true)) $columns[$column] = null;
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
                if (!is_numeric($value)) $errors[$key] = 'Must be a number.';
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
            case 'text':
                $columns[$column] = mb_substr((string)$value, 0, 4000);
                break;
            default:
                $columns[$column] = mb_substr((string)$value, 0, $spec[2] ?? 255);
        }
    }

    if ($isCreate) {
        // Only when nothing more specific was already said. A rejected enum value has
        // already produced "Choose one of: domain, hosting, ..." - which tells the person
        // what to do - and overwriting it with "choose what kind of record this is" would
        // replace the useful message with a vaguer one.
        if (empty($columns['kind']) && !isset($errors['kind'])) {
            $errors['kind'] = 'Choose what kind of record this is.';
        }
        if (empty($columns['name']) && !isset($errors['name'])) {
            $errors['name'] = 'A name is required.';
        }
    }
    if (!empty($columns['starts_on']) && !empty($columns['expires_on'])
        && $columns['expires_on'] < $columns['starts_on']) {
        $errors['expiresOn'] = 'The renewal date must come after the start date.';
    }
    return [$columns, $errors];
}

/** @return array{0:?array,1:array<string,string>} */
function ictInfraCreate(PDO $pdo, array $data): array
{
    if (!ictInfraEnsureTable($pdo)) {
        return [null, ['_' => 'The infrastructure table is not available. Import schema-ict-infrastructure.sql.']];
    }
    [$columns, $errors] = ictInfraCollect($data, true);
    if ($errors) return [null, $errors];

    $names = array_keys($columns);
    $pdo->prepare('INSERT INTO ict_infrastructure (' . implode(', ', $names) . ') VALUES ('
                  . implode(', ', array_fill(0, count($names), '?')) . ')')
        ->execute(array_values($columns));

    return [ictInfraFind($pdo, (int)$pdo->lastInsertId()), []];
}

/** @return array{0:?array,1:array<string,string>,2:array} [record, errors, before] */
function ictInfraUpdate(PDO $pdo, int $id, array $data): array
{
    if (!ictInfraEnsureTable($pdo)) {
        return [null, ['_' => 'The infrastructure table is not available.'], []];
    }
    $existing = ictInfraFind($pdo, $id);
    if (!$existing) return [null, ['_' => 'That record no longer exists.'], []];

    [$columns, $errors] = ictInfraCollect($data, false);
    // Dates may arrive one at a time, so the pair is re-checked against what is stored -
    // moving only the start could otherwise silently invert the two.
    $starts = array_key_exists('starts_on', $columns) ? $columns['starts_on'] : $existing['startsOn'];
    $expires = array_key_exists('expires_on', $columns) ? $columns['expires_on'] : $existing['expiresOn'];
    if ($starts && $expires && $expires < $starts) {
        $errors['expiresOn'] = 'The renewal date must come after the start date.';
    }
    if ($errors) return [null, $errors, []];
    if (!$columns) return [$existing, [], []];

    // Changing the renewal date resets the alert band, so moving a date forward re-arms the
    // warning instead of staying quiet because the old date already alerted.
    if (array_key_exists('expires_on', $columns)) {
        $columns['last_alert_band'] = null;
        $columns['last_alert_on'] = null;
    }

    $set = implode(', ', array_map(fn($c) => "{$c} = ?", array_keys($columns)));
    $pdo->prepare("UPDATE ict_infrastructure SET {$set} WHERE id = ?")
        ->execute([...array_values($columns), $id]);

    return [ictInfraFind($pdo, $id), [], $existing];
}

/**
 * Records whose alert band has changed since the last mail - the cron's work list.
 *
 * Returns nothing for a record sitting in the band it was last alerted about, which is what
 * stops a ninety-day warning arriving ninety times.
 */
function ictInfraDueForAlert(PDO $pdo): array
{
    if (!ictInfraEnsureTable($pdo)) return [];

    $rows = $pdo->query("SELECT * FROM ict_infrastructure
                         WHERE status IN ('active','pending') AND expires_on IS NOT NULL")->fetchAll();
    $due = [];
    foreach ($rows as $row) {
        $health = ictInfraHealth($row['expires_on'], (string)$row['status']);
        // Only bands worth a message. 'ok' and 'notice' are visible on the dashboard; mailing
        // about something ninety days out is what makes people filter the sender.
        if (!in_array($health['band'], ['expired', 'critical', 'warning'], true)) continue;
        if ((string)($row['last_alert_band'] ?? '') === $health['band']) continue;
        $due[] = ['row' => $row, 'health' => $health];
    }
    return $due;
}

function ictInfraMarkAlerted(PDO $pdo, int $id, string $band): void
{
    $pdo->prepare('UPDATE ict_infrastructure SET last_alert_band = ?, last_alert_on = CURDATE() WHERE id = ?')
        ->execute([$band, $id]);
}
