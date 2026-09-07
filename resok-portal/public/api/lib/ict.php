<?php
declare(strict_types=1);

/**
 * ICT operations: capabilities and audit.
 *
 * This is the gate every other ICT module sits behind, so it is deliberately the first thing
 * built. Adding permissions to modules that already exist means retrofitting them onto code
 * written without them, and something always gets missed.
 *
 * The access model, in full:
 *
 *   member       their own portal. Nothing administrative.
 *   ict          ICT modules, and only the ones granted. NOT member records, ID numbers,
 *                payments or approvals.
 *   admin        members, payments, approvals, CPD - exactly as before, unchanged.
 *   super admin  everything, including granting capabilities.
 *
 * The important line is the second. Before this file the only way to give an ICT officer
 * access to anything administrative was to make them an admin, which also handed them every
 * member's national ID number and the power to approve or reject a membership. They need
 * none of that to log a repair on a laptop.
 *
 * Note that 'admin' does NOT imply ICT access. An admin manages members; an ICT officer
 * manages equipment. Neither needs the other's work, and least privilege means the roles do
 * not leak into one another. A super admin holds everything because someone has to.
 */

/**
 * The capability vocabulary. A grant naming anything outside this list is refused, so a typo
 * cannot silently create a permission that no check will ever match - and that nobody can
 * revoke because it does not appear in any list.
 */
function ictCapabilityList(): array
{
    return [
        'assets.view'          => 'View assets',
        'assets.manage'        => 'Add and edit assets',
        'assets.assign'        => 'Assign assets to people',
        'maintenance.view'     => 'View maintenance history',
        'maintenance.manage'   => 'Record maintenance and repairs',
        'infrastructure.view'  => 'View domain, hosting, SSL and backups',
        'infrastructure.manage'=> 'Edit infrastructure records',
        'credentials.view'     => 'View stored credentials',
        'credentials.manage'   => 'Add and edit credentials',
        'licenses.view'        => 'View software and licences',
        'licenses.manage'      => 'Edit software and licences',
        'tickets.view'         => 'View support tickets',
        'tickets.manage'       => 'Assign and resolve tickets',
        'tasks.view'           => 'View ICT tasks',
        'tasks.manage'         => 'Create and assign tasks',
        'security.view'        => 'View security records',
        'security.manage'      => 'Manage security records',
        'reports.view'         => 'View ICT reports',
    ];
}

function ictEnsureTables(PDO $pdo): bool
{
    static $ready = null;
    if ($ready !== null) return $ready;

    try {
        $pdo->query('SELECT 1 FROM ict_capabilities LIMIT 1');
        $pdo->query('SELECT 1 FROM ict_audit LIMIT 1');
        return $ready = true;
    } catch (Throwable $e) {
        // Absent - build them.
    }
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS ict_capabilities (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id INT UNSIGNED NOT NULL,
            capability VARCHAR(40) NOT NULL,
            granted_by INT UNSIGNED NULL,
            granted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY ict_capabilities_unique (user_id, capability),
            KEY ict_capabilities_cap (capability)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS ict_audit (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            actor_user_id INT UNSIGNED NULL,
            action VARCHAR(60) NOT NULL,
            target_type VARCHAR(40) NULL,
            target_id VARCHAR(60) NULL,
            summary VARCHAR(300) NULL,
            before_json TEXT NULL,
            after_json TEXT NULL,
            client_hash CHAR(64) NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY ict_audit_time (created_at),
            KEY ict_audit_actor (actor_user_id, created_at),
            KEY ict_audit_target (target_type, target_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // The role column may already allow 'ict' - the schema file adds it. Attempted here
        // too so a portal whose database user can ALTER does not need the manual import,
        // and ignored when it cannot.
        try {
            $pdo->exec("ALTER TABLE users MODIFY COLUMN role ENUM('member','admin','ict') NOT NULL DEFAULT 'member'");
        } catch (Throwable $e) {
            error_log('Could not widen the role column; import schema-ict.sql: ' . $e->getMessage());
        }
        return $ready = true;
    } catch (Throwable $e) {
        error_log('ICT tables unavailable: ' . $e->getMessage());
        return $ready = false;
    }
}

/** Every capability held by one user, as a plain list. */
function ictCapabilitiesFor(PDO $pdo, int $userId): array
{
    if (!ictEnsureTables($pdo)) return [];
    $stmt = $pdo->prepare('SELECT capability FROM ict_capabilities WHERE user_id = ?');
    $stmt->execute([$userId]);
    return array_column($stmt->fetchAll(), 'capability');
}

/**
 * Whether this user may do this thing.
 *
 * Super admins hold everything implicitly - they are named in the server configuration, and
 * a super admin who has not granted themselves a capability should not be locked out of the
 * screen that grants capabilities.
 */
function ictCan(PDO $pdo, array $user, array $config, string $capability): bool
{
    if (function_exists('isSuperAdmin') && isSuperAdmin($user, $config)) return true;

    $role = (string)($user['role'] ?? '');
    // Deliberately not 'admin'. An admin manages members; ICT access is granted, not implied.
    if ($role !== 'ict' && $role !== 'admin') return false;

    static $cache = [];
    $userId = (int)($user['userId'] ?? 0);
    if ($userId <= 0) return false;
    if (!isset($cache[$userId])) $cache[$userId] = ictCapabilitiesFor($pdo, $userId);

    return in_array($capability, $cache[$userId], true);
}

/** Refuses the request unless the capability is held. */
function ictRequire(PDO $pdo, array $user, array $config, string $capability): void
{
    if (ictCan($pdo, $user, $config, $capability)) return;

    // Named in the message. An ICT officer told only "forbidden" has no way to ask for the
    // right thing, and ends up being made an admin to make the problem go away - which is
    // the outcome this whole file exists to prevent.
    respond(403, [
        'error' => 'You do not have the ICT permission needed for this.',
        'capability' => $capability,
    ]);
}

/** True if the user can reach the ICT area at all. */
function ictHasAnyAccess(PDO $pdo, array $user, array $config): bool
{
    if (function_exists('isSuperAdmin') && isSuperAdmin($user, $config)) return true;
    $role = (string)($user['role'] ?? '');
    if ($role !== 'ict' && $role !== 'admin') return false;
    return ictCapabilitiesFor($pdo, (int)($user['userId'] ?? 0)) !== [];
}

/* ------------------------------------------------------------------------------------- */

/**
 * Records an ICT action.
 *
 * $before and $after carry only the fields that changed - storing a copy of the whole record
 * on every edit makes the log enormous and the actual change harder to find, not easier.
 *
 * Never throws. An audit failure must not roll back the work it was recording; the work
 * happened, and losing it because the note about it failed would be worse.
 */
function ictAudit(PDO $pdo, ?int $actorUserId, string $action, ?string $targetType = null,
                  ?string $targetId = null, ?string $summary = null,
                  ?array $before = null, ?array $after = null): void
{
    try {
        if (!ictEnsureTables($pdo)) return;

        $client = function_exists('throttleClientFingerprint') ? throttleClientFingerprint() : '';
        $pdo->prepare('INSERT INTO ict_audit
                (actor_user_id, action, target_type, target_id, summary, before_json, after_json, client_hash, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())')
            ->execute([
                $actorUserId, $action, $targetType, $targetId,
                $summary === null ? null : mb_substr($summary, 0, 300),
                $before === null ? null : json_encode($before, JSON_UNESCAPED_SLASHES),
                $after === null ? null : json_encode($after, JSON_UNESCAPED_SLASHES),
                $client !== '' ? hash('sha256', $client) : null,
            ]);
    } catch (Throwable $e) {
        error_log('ICT audit write failed: ' . $e->getMessage());
    }
}

/**
 * Only the fields that actually changed, for the audit record.
 *
 * @return array{0:array,1:array} [before, after] - both empty when nothing changed
 */
function ictDiff(array $before, array $after): array
{
    $wasChanged = [];
    $nowIs = [];
    foreach ($after as $key => $value) {
        $old = $before[$key] ?? null;
        // Loose comparison on purpose: a form posts "5" where the database holds int 5, and
        // recording that as a change would fill the log with edits nobody made.
        if ((string)$old !== (string)$value) {
            $wasChanged[$key] = $old;
            $nowIs[$key] = $value;
        }
    }
    return [$wasChanged, $nowIs];
}

/** Recent ICT activity, for the timeline. */
function ictAuditRecent(PDO $pdo, int $limit = 40): array
{
    if (!ictEnsureTables($pdo)) return [];
    $stmt = $pdo->prepare('SELECT a.*, u.email AS actor_email
                           FROM ict_audit a
                           LEFT JOIN users u ON u.id = a.actor_user_id
                           ORDER BY a.id DESC LIMIT ' . max(1, min($limit, 200)));
    $stmt->execute();

    return array_map(fn($row) => [
        'id'         => (int)$row['id'],
        'action'     => $row['action'],
        'targetType' => $row['target_type'],
        'targetId'   => $row['target_id'],
        'summary'    => $row['summary'],
        'actor'      => $row['actor_email'],
        'at'         => $row['created_at'],
    ], $stmt->fetchAll());
}
