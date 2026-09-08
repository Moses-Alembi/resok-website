<?php
declare(strict_types=1);

/**
 * ICT credential register - a register, not a vault.
 *
 * Nothing here stores a password, and no function in this file accepts one. If a field for
 * it ever appears, that is a mistake and not a feature: see schema-ict-credentials.sql for
 * why the portal is the wrong place to hold the keys to the domain, the hosting and the
 * database at once.
 *
 * What it answers instead - and what nobody could answer before - is which accounts exist,
 * who owns each one, whether two-factor is on, when the password was last changed, and where
 * the credential itself is kept.
 *
 * Two things fall out of that which are worth more than a vault would be:
 *
 *   A critical account with no second factor is now a visible number rather than something
 *   somebody has to remember to check.
 *
 *   A password nobody has rotated in three years is a date, not a feeling.
 */

const ICT_CREDENTIAL_KINDS = ['domain', 'hosting', 'server', 'database', 'email', 'cms',
                              'repository', 'api_key', 'ssh_key', 'payment', 'social',
                              'service', 'other'];
const ICT_CRITICALITY = ['critical', 'high', 'normal'];

function ictCredentialsEnsureTables(PDO $pdo): bool
{
    static $ready = null;
    if ($ready !== null) return $ready;
    try {
        $pdo->query('SELECT 1 FROM ict_credentials LIMIT 1');
        $pdo->query('SELECT 1 FROM ict_credential_access LIMIT 1');
        return $ready = true;
    } catch (Throwable $e) {
        error_log('ICT credential tables unavailable: ' . $e->getMessage());
        return $ready = false;
    }
}

/**
 * When the next rotation is due.
 *
 * Derived rather than stored as typed input, so the date can never disagree with the
 * interval it is supposed to follow.
 */
function ictCredentialNextRotation(?string $lastRotated, int $months): ?string
{
    if ($months <= 0 || $lastRotated === null || $lastRotated === '') return null;
    $from = DateTimeImmutable::createFromFormat('!Y-m-d', substr($lastRotated, 0, 10));
    if (!$from) return null;
    return $from->add(new DateInterval('P' . $months . 'M'))->format('Y-m-d');
}

function ictCredentialShape(array $row): array
{
    $rotation = null;
    if (!empty($row['next_rotation_on']) && function_exists('ictInfraHealth')) {
        // Same banding as domain and SSL renewals, so an overdue password reads exactly like
        // an expiring certificate and there is only one thing to learn.
        $rotation = ictInfraHealth($row['next_rotation_on']);
    }
    return [
        'id'            => (int)$row['id'],
        'name'          => $row['name'],
        'kind'          => $row['kind'],
        'provider'      => $row['provider'],
        'accountId'     => $row['account_identifier'],
        'consoleUrl'    => $row['console_url'],
        'vaultUrl'      => $row['vault_url'],
        'vaultReference'=> $row['vault_reference'],
        'ownerUserId'   => $row['owner_user_id'] === null ? null : (int)$row['owner_user_id'],
        'ownerName'     => $row['owner_name'],
        'ownerEmail'    => $row['owner_email'] ?? null,
        'backupContact' => $row['backup_contact'],
        'mfaEnabled'    => (bool)(int)$row['mfa_enabled'],
        'mfaNotes'      => $row['mfa_notes'],
        'criticality'   => $row['criticality'],
        'lastRotatedOn' => $row['last_rotated_on'],
        'rotationMonths'=> (int)$row['rotation_months'],
        'nextRotationOn'=> $row['next_rotation_on'],
        'rotation'      => $rotation,
        'status'        => $row['status'],
        'notes'         => $row['notes'],
    ];
}

function ictCredentialsList(PDO $pdo): array
{
    if (!ictCredentialsEnsureTables($pdo)) return [];
    // Critical first, then anything without a second factor, then by name - so the register
    // opens on what needs attention rather than in alphabetical order.
    $rows = $pdo->query("SELECT c.*, u.email AS owner_email
                         FROM ict_credentials c
                         LEFT JOIN users u ON u.id = c.owner_user_id
                         ORDER BY c.status = 'retired',
                                  FIELD(c.criticality,'critical','high','normal'),
                                  c.mfa_enabled, c.name")->fetchAll();
    return array_map('ictCredentialShape', $rows);
}

function ictCredentialFind(PDO $pdo, int $id): ?array
{
    if (!ictCredentialsEnsureTables($pdo)) return null;
    $stmt = $pdo->prepare('SELECT c.*, u.email AS owner_email FROM ict_credentials c
                           LEFT JOIN users u ON u.id = c.owner_user_id WHERE c.id = ? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ? ictCredentialShape($row) : null;
}

/**
 * The numbers worth putting on the Overview.
 *
 * All of it comes from metadata. A vault would not have made any of these answerable; a
 * register does.
 */
function ictCredentialsSummary(PDO $pdo): array
{
    if (!ictCredentialsEnsureTables($pdo)) {
        return ['total' => 0, 'critical' => 0, 'noMfa' => 0, 'criticalNoMfa' => 0,
                'rotationOverdue' => 0, 'noVaultLink' => 0, 'noOwner' => 0];
    }
    $one = fn(string $sql) => (int)($pdo->query($sql)->fetch()['c'] ?? 0);
    $active = "status = 'active'";

    return [
        'total'    => $one("SELECT COUNT(*) c FROM ict_credentials WHERE {$active}"),
        'critical' => $one("SELECT COUNT(*) c FROM ict_credentials WHERE {$active} AND criticality = 'critical'"),
        'noMfa'    => $one("SELECT COUNT(*) c FROM ict_credentials WHERE {$active} AND mfa_enabled = 0"),
        // The single most useful number in the module.
        'criticalNoMfa' => $one("SELECT COUNT(*) c FROM ict_credentials
                                  WHERE {$active} AND mfa_enabled = 0
                                    AND criticality IN ('critical','high')"),
        'rotationOverdue' => $one("SELECT COUNT(*) c FROM ict_credentials
                                    WHERE {$active} AND next_rotation_on IS NOT NULL
                                      AND next_rotation_on < CURDATE()"),
        // An account whose password nobody can find is an account nobody can use.
        'noVaultLink' => $one("SELECT COUNT(*) c FROM ict_credentials
                                WHERE {$active} AND (vault_url IS NULL OR vault_url = '')
                                  AND (vault_reference IS NULL OR vault_reference = '')"),
        'noOwner'     => $one("SELECT COUNT(*) c FROM ict_credentials
                                WHERE {$active} AND owner_user_id IS NULL
                                  AND (owner_name IS NULL OR owner_name = '')"),
    ];
}

/* ------------------------------------------------------------------------------------- */

function ictCredentialWritableFields(): array
{
    return [
        'name'           => ['name', 'string', 160],
        'kind'           => ['kind', 'enum', ICT_CREDENTIAL_KINDS],
        'provider'       => ['provider', 'string', 160],
        'accountId'      => ['account_identifier', 'string', 190],
        'consoleUrl'     => ['console_url', 'url', 500],
        'vaultUrl'       => ['vault_url', 'url', 500],
        'vaultReference' => ['vault_reference', 'string', 160],
        'ownerName'      => ['owner_name', 'string', 160],
        'backupContact'  => ['backup_contact', 'string', 160],
        'mfaEnabled'     => ['mfa_enabled', 'bool'],
        'mfaNotes'       => ['mfa_notes', 'string', 200],
        'criticality'    => ['criticality', 'enum', ICT_CRITICALITY],
        'lastRotatedOn'  => ['last_rotated_on', 'date'],
        'rotationMonths' => ['rotation_months', 'int'],
        'status'         => ['status', 'enum', ['active', 'retired']],
        'notes'          => ['notes', 'text'],
    ];
}

function ictCredentialNullable(): array
{
    return ['provider', 'account_identifier', 'console_url', 'vault_url', 'vault_reference',
            'owner_name', 'backup_contact', 'mfa_notes', 'last_rotated_on', 'notes'];
}

/**
 * @return array{0:array,1:array<string,string>}
 */
function ictCredentialCollect(array $data, bool $isCreate): array
{
    $columns = [];
    $errors = [];

    // The one rule this module exists to hold. Caught here rather than trusted to a habit,
    // because the pressure to "just put it in the notes for now" is exactly how a register
    // becomes an unencrypted vault.
    foreach (['password', 'secret', 'passphrase', 'apiKey', 'privateKey', 'token'] as $forbidden) {
        if (array_key_exists($forbidden, $data) && trim((string)$data[$forbidden]) !== '') {
            $errors['_'] = 'This register does not store passwords or keys - only where to find them. '
                         . 'Put the secret in the password manager and link to it.';
            return [[], $errors];
        }
    }

    foreach (ictCredentialWritableFields() as $key => $spec) {
        if (!array_key_exists($key, $data)) continue;
        [$column, $kind] = $spec;
        $value = $data[$key];

        if ($value === null || $value === '') {
            if (in_array($column, ictCredentialNullable(), true)) $columns[$column] = null;
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
                if (!is_numeric($value) || (int)$value < 0 || (int)$value > 120) {
                    $errors[$key] = 'Months must be between 0 and 120.';
                } else {
                    $columns[$column] = (int)$value;
                }
                break;
            case 'date':
                $time = strtotime((string)$value);
                if ($time === false) $errors[$key] = 'Not a date we could read.';
                elseif ($time > time() + 86400) $errors[$key] = 'That date is in the future.';
                else $columns[$column] = date('Y-m-d', $time);
                break;
            case 'url':
                $url = trim((string)$value);
                // A link that is not a link sends someone to a 404 at the worst moment.
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
        $errors['name'] = 'Name the account, for example "Domain registrar - Namecheap".';
    }
    return [$columns, $errors];
}

/** Recomputes next_rotation_on from whichever of the two inputs is current. */
function ictCredentialApplyRotation(array &$columns, ?array $existing): void
{
    $last = array_key_exists('last_rotated_on', $columns)
        ? $columns['last_rotated_on'] : ($existing['lastRotatedOn'] ?? null);
    $months = array_key_exists('rotation_months', $columns)
        ? (int)$columns['rotation_months'] : (int)($existing['rotationMonths'] ?? 0);
    $columns['next_rotation_on'] = ictCredentialNextRotation($last, $months);
}

/** @return array{0:?array,1:array<string,string>} */
function ictCredentialCreate(PDO $pdo, array $data, ?int $adminUserId): array
{
    if (!ictCredentialsEnsureTables($pdo)) {
        return [null, ['_' => 'The credential tables are not available. Apply schema-ict-credentials.sql.']];
    }
    [$columns, $errors] = ictCredentialCollect($data, true);
    if ($errors) return [null, $errors];

    ictCredentialApplyRotation($columns, null);
    $columns['created_by'] = $adminUserId;

    $names = array_keys($columns);
    $pdo->prepare('INSERT INTO ict_credentials (' . implode(', ', $names) . ') VALUES ('
                  . implode(', ', array_fill(0, count($names), '?')) . ')')
        ->execute(array_values($columns));

    return [ictCredentialFind($pdo, (int)$pdo->lastInsertId()), []];
}

/** @return array{0:?array,1:array<string,string>,2:array} */
function ictCredentialUpdate(PDO $pdo, int $id, array $data): array
{
    if (!ictCredentialsEnsureTables($pdo)) return [null, ['_' => 'The credential tables are not available.'], []];
    $existing = ictCredentialFind($pdo, $id);
    if (!$existing) return [null, ['_' => 'That record no longer exists.'], []];

    [$columns, $errors] = ictCredentialCollect($data, false);
    if ($errors) return [null, $errors, []];
    if (!$columns) return [$existing, [], []];

    ictCredentialApplyRotation($columns, $existing);

    $set = implode(', ', array_map(fn($c) => "{$c} = ?", array_keys($columns)));
    $pdo->prepare("UPDATE ict_credentials SET {$set} WHERE id = ?")
        ->execute([...array_values($columns), $id]);

    return [ictCredentialFind($pdo, $id), [], $existing];
}

/**
 * Records that someone went to collect a credential, and hands back where it is kept.
 *
 * Reading the register is not sensitive - it holds nothing secret. Following the link to the
 * password manager is, because that is the moment a real password is fetched. Logging that
 * gives the audit trail the brief asked for without the portal ever holding the credential.
 *
 * @return array{0:?array,1:?string}
 */
function ictCredentialOpen(PDO $pdo, int $id, ?int $userId, string $reason = ''): array
{
    if (!ictCredentialsEnsureTables($pdo)) return [null, 'The credential tables are not available.'];
    $credential = ictCredentialFind($pdo, $id);
    if (!$credential) return [null, 'That record no longer exists.'];

    if (empty($credential['vaultUrl']) && empty($credential['vaultReference'])) {
        return [null, 'No vault entry is recorded for this account yet.'];
    }

    try {
        $client = function_exists('throttleClientFingerprint') ? throttleClientFingerprint() : '';
        $pdo->prepare('INSERT INTO ict_credential_access (credential_id, user_id, reason, client_hash, accessed_at)
                       VALUES (?, ?, ?, ?, NOW())')
            ->execute([$id, $userId, mb_substr(trim($reason), 0, 200) ?: null,
                       $client !== '' ? hash('sha256', $client) : null]);
    } catch (Throwable $e) {
        // The log failing must not stop someone reaching a credential during an incident.
        error_log('Credential access log failed: ' . $e->getMessage());
    }

    return [[
        'vaultUrl'       => $credential['vaultUrl'],
        'vaultReference' => $credential['vaultReference'],
        'name'           => $credential['name'],
    ], null];
}

/** Who has gone looking for credentials lately. */
function ictCredentialAccessLog(PDO $pdo, ?int $credentialId = null, int $limit = 50): array
{
    if (!ictCredentialsEnsureTables($pdo)) return [];
    $sql = 'SELECT a.*, c.name AS credential_name, u.email AS actor
            FROM ict_credential_access a
            JOIN ict_credentials c ON c.id = a.credential_id
            LEFT JOIN users u ON u.id = a.user_id';
    $args = [];
    if ($credentialId !== null) {
        $sql .= ' WHERE a.credential_id = ?';
        $args[] = $credentialId;
    }
    $sql .= ' ORDER BY a.id DESC LIMIT ' . max(1, min($limit, 200));

    $stmt = $pdo->prepare($sql);
    $stmt->execute($args);
    return array_map(fn($r) => [
        'credential' => $r['credential_name'],
        'actor'      => $r['actor'],
        'reason'     => $r['reason'],
        'at'         => $r['accessed_at'],
    ], $stmt->fetchAll());
}
