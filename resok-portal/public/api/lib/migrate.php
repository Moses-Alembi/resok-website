<?php
declare(strict_types=1);

/**
 * Applies the .sql files in resok-portal/server from the admin panel.
 *
 * Until now every schema change meant opening phpMyAdmin and importing a file by hand. That
 * is the step most likely to be skipped, and skipping it does not announce itself: each
 * module quietly creates its own tables at runtime if the database user happens to hold
 * CREATE, and quietly does without if it does not. Rate limiting could have been switched
 * off for weeks that way, and nothing on the site would have looked different.
 *
 * This makes the state visible and the fix one button.
 *
 * Safety, in order of importance:
 *
 *   - Only files already sitting in resok-portal/server are readable, by name, from that
 *     one directory. Nothing arrives over the request. This is not a way to run SQL.
 *   - Super administrator only, and every run is written to the audit log.
 *   - Applied files are recorded with a checksum, so a file that changes after being
 *     applied shows as changed rather than silently drifting.
 *   - Errors that only mean "already done" - table exists, duplicate column, duplicate key -
 *     are counted as skipped rather than failing the run. MySQL has no ADD COLUMN IF NOT
 *     EXISTS, so a second run of any schema file always produces some of these.
 */

/** MySQL errors that mean the change was already in place. */
const MIGRATE_BENIGN = [
    1050,  // table already exists
    1060,  // duplicate column
    1061,  // duplicate key name
    1091,  // can't DROP; doesn't exist
    1826,  // duplicate foreign key constraint name
];

function migrateDir(): string
{
    return dirname(__DIR__, 3) . '/server';
}

/**
 * The order files must run in.
 *
 * Alphabetical order is wrong and quietly so: schema-security.sql sorts before schema.sql,
 * so it tried to ALTER TABLE users before schema.sql had created it. Everything downstream
 * then failed for a reason that had nothing to do with the file being run.
 *
 * Base tables first, then the files that alter or reference them. Anything not listed runs
 * afterwards in alphabetical order, so a new file still gets applied without being added
 * here - it just cannot claim a place in the ordered part until someone says where it goes.
 */
function migrateOrder(): array
{
    return [
        'schema.sql',                          // users, member_profiles, payments, CPD
        'migration-membership-live-fields.sql',// alters member_profiles
        'migration-membership-renewal.sql',    // alters member_profiles; renewal bookkeeping
        'schema-security.sql',                 // alters users; rate limiting, event log
        'schema-events.sql',                   // cpd_events
        'schema-tokens.sql',                   // attendance and CPD tokens
        'schema-ict.sql',                      // alters users.role; ICT capabilities, audit
        'schema-ict-infrastructure.sql',       // domain, hosting, SSL, backups
        'schema-ict-assets.sql',               // equipment, assignments, maintenance
        'schema-ict-credentials.sql',          // credential register (no secrets)
        'schema-ict-licenses.sql',             // software, licences and seats
        'schema-ict-tickets.sql',              // helpdesk tickets and comments
        'schema-member-years.sql',             // which years each member has paid for
        'schema-blog.sql',                     // blog tables, which reference users
        'schema-elections.sql',                // board elections: posts, candidates, roll
        'schema-elections-ballot.sql',         // the ballot; depends on the roll above
        'migration-election-nominations.sql',   // nomination phase; alters elections
        'schema-academy.sql',                  // Virtual Academy; learners are users rows
    ];
}

function migrateEnsureTable(PDO $pdo): bool
{
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            filename VARCHAR(160) NOT NULL,
            checksum CHAR(40) NOT NULL,
            statements_run SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            statements_skipped SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            applied_by INT UNSIGNED NULL,
            applied_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY schema_migrations_file (filename)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        return true;
    } catch (Throwable $e) {
        error_log('schema_migrations unavailable: ' . $e->getMessage());
        return false;
    }
}

/**
 * Splits a file into statements.
 *
 * Walks the text once, tracking quoting and comments, because a semicolon only ends a
 * statement when it is outside all of them. The first version split on every semicolon and
 * skipped only whole-line comments, which cut a CREATE TABLE in half on this:
 *
 *     credentials JSON NULL,   -- tokens; NULL for sources needing none
 *
 * Comments are dropped rather than preserved: they are for whoever reads the file, and
 * MySQL does not need them.
 */
function migrateSplit(string $sql): array
{
    $statements = [];
    $current = '';
    $length = strlen($sql);
    $quote = '';        // ', " or ` while inside a string
    $lineComment = false;
    $blockComment = false;

    for ($i = 0; $i < $length; $i++) {
        $char = $sql[$i];
        $next = $i + 1 < $length ? $sql[$i + 1] : '';

        if ($lineComment) {
            if ($char === "\n") { $lineComment = false; $current .= $char; }
            continue;
        }
        if ($blockComment) {
            if ($char === '*' && $next === '/') { $blockComment = false; $i++; }
            continue;
        }
        if ($quote !== '') {
            $current .= $char;
            // Doubled quote is an escaped quote, not the end of the string.
            if ($char === $quote) {
                if ($next === $quote) { $current .= $next; $i++; }
                else $quote = '';
            } elseif ($char === '\\' && $quote !== '`') {
                // Backslash escape - take the next character literally.
                if ($next !== '') { $current .= $next; $i++; }
            }
            continue;
        }

        if ($char === '-' && $next === '-') { $lineComment = true; $i++; continue; }
        if ($char === '#') { $lineComment = true; continue; }
        if ($char === '/' && $next === '*') { $blockComment = true; $i++; continue; }

        if ($char === "'" || $char === '"' || $char === '`') {
            $quote = $char;
            $current .= $char;
            continue;
        }
        if ($char === ';') {
            $trimmed = trim($current);
            if ($trimmed !== '') $statements[] = $trimmed;
            $current = '';
            continue;
        }
        $current .= $char;
    }

    $trimmed = trim($current);
    if ($trimmed !== '') $statements[] = $trimmed;
    return $statements;
}

/**
 * Every .sql file and what has happened to it.
 *
 * @return array<int,array{file:string,status:string,appliedAt:?string,isSeed:bool,statements:int}>
 */
function migrateStatus(PDO $pdo): array
{
    if (!migrateEnsureTable($pdo)) return [];

    $applied = [];
    foreach ($pdo->query('SELECT * FROM schema_migrations')->fetchAll() as $row) {
        $applied[$row['filename']] = $row;
    }

    $out = [];
    foreach (glob(migrateDir() . '/*.sql') ?: [] as $path) {
        $name = basename($path);
        $sql = (string)file_get_contents($path);
        $checksum = sha1($sql);
        $record = $applied[$name] ?? null;

        if ($record === null) {
            $status = 'pending';
        } elseif ($record['checksum'] !== $checksum) {
            // The file was edited after being applied. Not necessarily wrong - schema files
            // here grow over time - but it should be visible rather than assumed.
            $status = 'changed';
        } else {
            $status = 'applied';
        }

        $out[] = [
            'file'       => $name,
            'status'     => $status,
            'appliedAt'  => $record['applied_at'] ?? null,
            // Seeds write data rather than structure, so they are never applied by the
            // "apply all" button - re-running one can overwrite records edited by hand.
            'isSeed'     => strncmp($name, 'seed-', 5) === 0,
            'statements' => count(migrateSplit($sql)),
        ];
    }
    // Seeds last, then declared order, then anything unlisted alphabetically.
    $order = migrateOrder();
    usort($out, function ($a, $b) use ($order) {
        $rank = function (array $entry) use ($order): array {
            $i = array_search($entry['file'], $order, true);
            return [$entry['isSeed'] ? 1 : 0, $i === false ? 999 : $i, $entry['file']];
        };
        return $rank($a) <=> $rank($b);
    });
    return $out;
}

/**
 * Runs one file.
 *
 * Not wrapped in a transaction: MySQL commits DDL implicitly, so a rollback would not undo
 * a CREATE TABLE anyway. Instead each statement is independent and the result says exactly
 * what ran, what was already in place, and what failed.
 *
 * @return array{file:string,run:int,skipped:int,errors:array<int,string>}
 */
function migrateApply(PDO $pdo, string $filename, ?int $adminUserId): array
{
    // Resolved against the directory listing rather than concatenated, so nothing that is
    // not already one of these files can be named.
    $allowed = array_map('basename', glob(migrateDir() . '/*.sql') ?: []);
    if (!in_array($filename, $allowed, true)) {
        return ['file' => $filename, 'run' => 0, 'skipped' => 0, 'errors' => ['No such schema file.']];
    }

    $path = migrateDir() . '/' . $filename;
    $sql = (string)file_get_contents($path);
    $run = $skipped = 0;
    $errors = [];

    foreach (migrateSplit($sql) as $statement) {
        try {
            $pdo->exec($statement);
            $run++;
        } catch (PDOException $e) {
            $code = (int)($e->errorInfo[1] ?? 0);
            if (in_array($code, MIGRATE_BENIGN, true)) {
                $skipped++;
                continue;
            }
            // First line only: enough to identify the statement without pasting the schema
            // into an error box.
            $first = strtok(trim($statement), "\n");
            $errors[] = substr((string)$first, 0, 90) . ' -> ' . $e->getMessage();
        }
    }

    if (!$errors) {
        migrateEnsureTable($pdo);
        $pdo->prepare('INSERT INTO schema_migrations
                        (filename, checksum, statements_run, statements_skipped, applied_by, applied_at)
                        VALUES (?, ?, ?, ?, ?, NOW())
                       ON DUPLICATE KEY UPDATE
                        checksum = VALUES(checksum), statements_run = VALUES(statements_run),
                        statements_skipped = VALUES(statements_skipped),
                        applied_by = VALUES(applied_by), applied_at = VALUES(applied_at)')
            ->execute([$filename, sha1($sql), $run, $skipped, $adminUserId]);
    }

    return ['file' => $filename, 'run' => $run, 'skipped' => $skipped, 'errors' => $errors];
}

/** Applies every pending or changed schema file. Seeds are left alone. */
function migrateApplyPending(PDO $pdo, ?int $adminUserId): array
{
    $results = [];
    foreach (migrateStatus($pdo) as $entry) {
        if ($entry['isSeed']) continue;
        if ($entry['status'] === 'applied') continue;
        $results[] = migrateApply($pdo, $entry['file'], $adminUserId);
    }
    return $results;
}
