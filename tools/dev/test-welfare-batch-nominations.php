<?php
declare(strict_types=1);

/**
 * Atomic staff nomination form tests using synthetic accounts and a disposable database.
 * Requires MariaDB at 127.0.0.1:33317, root password LocalWelfareTestsOnly!.
 * Never reads config.php or connects to the portal database.
 * Run: php tools/dev/test-welfare-batch-nominations.php
 */
date_default_timezone_set('Africa/Nairobi');
require_once __DIR__ . '/../../resok-portal/public/api/lib/elections.php';
require_once __DIR__ . '/../../resok-portal/public/api/lib/staff.php';
require_once __DIR__ . '/../../resok-portal/public/api/lib/staff-portal.php';
require_once __DIR__ . '/../../resok-portal/public/api/lib/welfare.php';
require_once __DIR__ . '/../../resok-portal/public/api/lib/migrate.php';

function batchConnect(?string $database = null): PDO
{
    if ($database !== null && !preg_match('/^resok_welfare_test_[a-f0-9]+$/', $database)) throw new RuntimeException('Unsafe test database');
    $pdo = new PDO('mysql:host=127.0.0.1;port=33317;' . ($database ? 'dbname=' . $database . ';' : '') . 'charset=utf8mb4',
        'root', 'LocalWelfareTestsOnly!', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    if ((int)$pdo->query('SELECT @@port')->fetchColumn() !== 33317) throw new RuntimeException('Not the isolated test server');
    $pdo->exec("SET time_zone='+03:00'");
    return $pdo;
}

if (($argv[1] ?? '') === '--cold-worker') {
    $pdo = batchConnect($argv[2]);
    echo json_encode(electionNominateBatch($pdo, $argv[3], (int)$argv[4], json_decode($argv[5], true, 512, JSON_THROW_ON_ERROR)), JSON_THROW_ON_ERROR);
    exit;
}

$checks = 0;
function batchCheck(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) throw new RuntimeException('FAIL: ' . $message);
    ++$checks;
    echo 'ok: ' . $message . "\n";
}

function batchApply(PDO $pdo, string $file): void
{
    foreach (migrateSplit((string)file_get_contents(__DIR__ . '/../../resok-portal/server/' . $file)) as $sql) $pdo->exec($sql);
}

function batchFixture(PDO $pdo, string $title): array
{
    [$election, $error] = electionCreate($pdo, ['electorate' => 'staff', 'title' => $title,
        'nominationsOpenAt' => date('Y-m-d H:i:s', time() - 600), 'nominationsCloseAt' => date('Y-m-d H:i:s', time() + 600),
        'opensAt' => date('Y-m-d H:i:s', time() + 1200), 'closesAt' => date('Y-m-d H:i:s', time() + 3600)], null);
    if ($error !== null) throw new RuntimeException($error);
    $positions = [];
    foreach (['Chairperson', 'Secretary', 'Treasurer', 'Welfare Representative'] as $title) {
        [$saved, $error] = electionPositionSave($pdo, $election['id'], ['title' => $title]);
        if ($error !== null) throw new RuntimeException($error);
        foreach ($saved as $post) if ($post['title'] === $title) $positions[$title] = $post['id'];
    }
    $pdo->prepare('INSERT INTO welfare_settings(election_id,staff_target) VALUES(?,0)')->execute([$election['id']]);
    welfareArm($pdo, $election['id'], true);
    welfareRunScheduled($pdo);
    return [$election, $positions];
}

function batchSnapshot(PDO $pdo): array
{
    return [
        'candidates' => $pdo->query('SELECT * FROM election_candidates ORDER BY id')->fetchAll(),
        'records' => $pdo->query('SELECT * FROM election_staff_nominations ORDER BY position_id,nominator_user_id')->fetchAll(),
        'notifications' => $pdo->query('SELECT * FROM staff_notifications ORDER BY id')->fetchAll(),
    ];
}

function batchOwn(PDO $pdo, int $election, int $user): array
{
    return array_column(electionStaffOwnNominations($pdo, $election, $user), null, 'positionId');
}

function batchColdWorker(string $database, string $slug, int $user, array $nominations): array
{
    $pipes = [];
    $process = proc_open([PHP_BINARY, __FILE__, '--cold-worker', $database, $slug, (string)$user, json_encode($nominations, JSON_THROW_ON_ERROR)],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Cannot start cold worker');
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($process) !== 0) throw new RuntimeException('Cold worker failed: ' . $error);
    return json_decode($output, true, 512, JSON_THROW_ON_ERROR);
}

$server = batchConnect();
$database = 'resok_welfare_test_' . bin2hex(random_bytes(6));
$server->exec('CREATE DATABASE ' . $database);
$pdo = batchConnect($database);
try {
    foreach (['schema.sql', 'schema-elections.sql', 'schema-elections-ballot.sql', 'migration-election-nominations.sql',
        'migration-election-external-nominees.sql', 'migration-election-staff.sql', 'migration-2026-11-03-welfare-automation.sql',
        'migration-2026-11-19-welfare-position-review.sql'] as $file) batchApply($pdo, $file);
    batchCheck(staffPortalEnsureTables($pdo), 'synthetic staff schema initializes');
    $users = [];
    for ($index = 0; $index < 8; ++$index) {
        $pdo->prepare("INSERT INTO users(email,password_hash,email_verified,role) VALUES(?,'unused',1,'member')")
            ->execute(['batch-' . $index . '@welfare.test']);
        $users[] = (int)$pdo->lastInsertId();
        $pdo->prepare('INSERT INTO staff_accounts(user_id,full_name,active) VALUES(?,?,1)')
            ->execute([$users[$index], 'Synthetic Batch Staff ' . $index]);
    }
    $pdo->exec("INSERT INTO users(email,password_hash,email_verified,role) VALUES('off-roll@welfare.test','unused',1,'member')");
    $outsider = (int)$pdo->lastInsertId();
    [$main, $posts] = batchFixture($pdo, 'Synthetic Atomic Staff Nominations');
    $id = $main['id'];
    $chair = $posts['Chairperson'];
    $secretary = $posts['Secretary'];
    $treasurer = $posts['Treasurer'];
    $representative = $posts['Welfare Representative'];

    $legacyRoll = electionRollEntry($pdo, $id, $users[1]);
    $pdo->prepare("INSERT INTO election_candidates(position_id,nominee_user_id,nominated_by_roll_id,nominated_at,name,status)
        VALUES(?,?,?,NOW(),'Synthetic Batch Staff 4','approved')")->execute([$chair, $users[4], $legacyRoll['id']]);
    $own = batchOwn($pdo, $id, $users[1]);
    batchCheck(count($own) === 1 && $own[$chair]['userId'] === $users[4], 'own nomination read falls back to the known legacy nominator before migration');
    batchCheck(electionStaffOwnNominations($pdo, $id, $users[2]) === []
        && electionStaffOwnNominations($pdo, $id, $outsider) === [], 'own read reveals neither other voters choices nor off-roll nomination data');
    [$unused, $error] = electionNominateBatch($pdo, $main['slug'], $users[0], [['positionId' => $secretary, 'userId' => $users[5]]]);
    batchCheck($error !== null && (int)$pdo->query('SELECT COUNT(*) FROM election_candidates')->fetchColumn() === 1,
        'missing nomination records migration fails without creating partial batch data');
    batchApply($pdo, 'migration-2026-11-19-welfare-nomination-records.sql');
    batchCheck(count(batchOwn($pdo, $id, $users[1])) === 1, 'legacy and backfilled nomination rows produce one own-choice row');

    $choices = [
        ['positionId' => $chair, 'userId' => $users[4]],
        ['positionId' => $secretary, 'userId' => $users[5], 'headline' => 'Synthetic submitted headline'],
        ['positionId' => $treasurer, 'userId' => $users[0]],
    ];
    [$result, $error] = electionNominateBatch($pdo, $main['slug'], $users[0], $choices);
    batchCheck($error === null && $result['nominated'] && count($result['nominations']) === 3, 'one batch records selected posts with different colleagues and self nomination');
    $encoded = json_encode($result, JSON_THROW_ON_ERROR);
    batchCheck(!str_contains($encoded, '_staffNotification') && !str_contains($encoded, 'nominationCount'),
        'staff batch response exposes neither private notification metadata nor administrative totals');
    $own = batchOwn($pdo, $id, $users[0]);
    batchCheck(count($own) === 3 && $own[$chair]['userId'] === $users[4] && $own[$secretary]['userId'] === $users[5]
        && $own[$treasurer]['userId'] === $users[0], 'own read returns exactly the caller selected posts and nominees');
    batchCheck($own[$secretary]['headline'] === 'Synthetic submitted headline'
        && count(array_filter($own, static fn(array $row): bool => array_keys($row) === ['positionId', 'userId', 'name', 'headline'])) === 3,
        'own-choice rows have only the four documented fields and saved headline');
    batchCheck(count($pdo->query("SELECT id FROM staff_notifications WHERE kind='election'")->fetchAll()) === 1,
        'successful batch sends one notification for the new other-person candidate and none for self or shared candidates');
    $snapshot = batchSnapshot($pdo);
    [$result, $error] = electionNominateBatch($pdo, $main['slug'], $users[0], $choices);
    batchCheck($error === null && batchSnapshot($pdo) === $snapshot, 'repeating the same complete batch is idempotent and sends no duplicate notifications');
    $beforeRead = batchSnapshot($pdo);
    electionStaffOwnNominations($pdo, $id, $users[0]);
    electionStaffOwnNominations($pdo, $id, $users[1]);
    batchCheck(batchSnapshot($pdo) === $beforeRead, 'own nomination reads do not change candidates, records or notifications');

    $invalidLater = [['positionId' => $chair, 'userId' => $users[6]], ['positionId' => $secretary, 'userId' => $users[7]], ['positionId' => $treasurer, 'userId' => $outsider]];
    [$unused, $error] = batchColdWorker($database, $main['slug'], $users[2], $invalidLater);
    batchCheck($error !== null && batchSnapshot($pdo) === $snapshot && batchOwn($pdo, $id, $users[2]) === [],
        'cold worker invalid later choice rolls back all preceding candidate, record and notification writes');
    [$unused, $error] = electionNominateBatch($pdo, $main['slug'], $users[0], [
        ['positionId' => $representative, 'userId' => $users[7]], ['positionId' => $chair, 'userId' => $users[6]],
    ]);
    batchCheck($error !== null && batchSnapshot($pdo) === $snapshot, 'changing an existing final choice rejects the whole batch including an earlier new post');
    [$unused, $error] = electionNominateBatch($pdo, $main['slug'], $users[2], [
        ['positionId' => $chair, 'userId' => $users[6]], ['positionId' => $chair, 'userId' => $users[6]],
    ]);
    batchCheck($error !== null && batchSnapshot($pdo) === $snapshot, 'duplicate positions are rejected even when both entries select the same nominee');
    foreach ([[], array_fill(0, 101, ['positionId' => $chair, 'userId' => $users[4]]),
        [['userId' => $users[4]]], [['positionId' => $chair]], [['positionId' => $chair, 'userId' => 0]],
        ['not an object'], [['positionId' => $chair, 'userId' => $users[4], 'headline' => []]]] as $invalid) {
        [$unused, $error] = electionNominateBatch($pdo, $main['slug'], $users[2], $invalid);
        batchCheck($error !== null && batchSnapshot($pdo) === $snapshot, 'invalid batch size or field shape is rejected without writes');
    }
    [$unused, $error] = electionNominateBatch($pdo, $main['slug'], $outsider, [['positionId' => $chair, 'userId' => $users[4]]]);
    batchCheck($error !== null && batchSnapshot($pdo) === $snapshot, 'off-roll accounts cannot submit a batch');

    // A nested failed batch must preserve pre-existing caller writes while undoing its own.
    $pdo->exec('CREATE TABLE synthetic_outer_writes (id INT NOT NULL PRIMARY KEY) ENGINE=InnoDB');
    $pdo->beginTransaction();
    $pdo->exec('INSERT INTO synthetic_outer_writes(id) VALUES(1)');
    [$unused, $error] = electionNominateBatch($pdo, $main['slug'], $users[2], $invalidLater);
    batchCheck($error !== null && $pdo->inTransaction() && batchSnapshot($pdo) === $snapshot
        && (int)$pdo->query('SELECT COUNT(*) FROM synthetic_outer_writes')->fetchColumn() === 1,
        'nested failed batch rolls back only batch writes and retains caller transaction data');
    $pdo->rollBack();
    $pdo->beginTransaction();
    [$result, $error] = electionNominateBatch($pdo, $main['slug'], $users[3], [
        ['positionId' => $chair, 'userId' => $users[6]], ['positionId' => $secretary, 'userId' => $users[7]],
    ]);
    batchCheck($error === null && $pdo->inTransaction() && count(batchOwn($pdo, $id, $users[3])) === 2,
        'successful nested batch remains inside its caller transaction');
    batchCheck($pdo->query('SELECT * FROM staff_notifications ORDER BY id')->fetchAll() === $snapshot['notifications'],
        'uncommitted nested batch does not send notifications');
    $pdo->rollBack();
    batchCheck(batchSnapshot($pdo) === $snapshot && batchOwn($pdo, $id, $users[3]) === [],
        'rolling back the caller transaction removes the entire successful batch');

    [$result, $error] = electionNominateBatch($pdo, $main['slug'], $users[2], [
        ['positionId' => $chair, 'userId' => $users[4]], ['positionId' => $secretary, 'userId' => $users[4]],
    ]);
    batchCheck($error === null && count(batchOwn($pdo, $id, $users[2])) === 2,
        'one batch may nominate the same colleague for different posts');
    [$result, $error] = electionNominateBatch($pdo, $main['slug'], $users[0], [['positionId' => $representative, 'userId' => $users[0]]]);
    batchCheck($error === null && count(batchOwn($pdo, $id, $users[0])) === 4,
        'a later optional subset can fill an unrecorded post without changing earlier final choices');

    [$other, $otherPosts] = batchFixture($pdo, 'Synthetic Other Batch Election');
    $snapshot = batchSnapshot($pdo);
    [$unused, $error] = electionNominateBatch($pdo, $main['slug'], $users[3], [
        ['positionId' => $chair, 'userId' => $users[7]], ['positionId' => $otherPosts['Chairperson'], 'userId' => $users[7]],
    ]);
    batchCheck($error !== null && batchSnapshot($pdo) === $snapshot, 'a later position from another election rolls back earlier valid selections');
    [$result, $error] = electionNominateBatch($pdo, $other['slug'], $users[0], [['positionId' => $otherPosts['Chairperson'], 'userId' => $users[7]]]);
    batchCheck($error === null && count(batchOwn($pdo, $id, $users[0])) === 4
        && count(batchOwn($pdo, $other['id'], $users[0])) === 1, 'own choices are isolated by election for the same account');

    $pdo->prepare('DELETE FROM election_roll WHERE election_id=? AND user_id=?')->execute([$id, $users[1]]);
    batchCheck(electionStaffOwnNominations($pdo, $id, $users[1]) === [], 'an account outside the current frozen roll receives no own-choice rows');
    $pdo->prepare("INSERT INTO election_roll(election_id,user_id,name,standing_at_cutoff) VALUES(?,?,'Synthetic restored voter','staff')")
        ->execute([$id, $users[1]]);
    batchCheck(count(batchOwn($pdo, $id, $users[1])) === 1, 'ledger-backed own choices survive replacement of a roll row');

    [$board, $error] = electionCreate($pdo, ['title' => 'Synthetic Board Batch Regression', 'eligibilityCutoff' => date('Y-m-d'),
        'nominationsOpenAt' => date('Y-m-d H:i:s', time() - 300), 'nominationsCloseAt' => date('Y-m-d H:i:s', time() + 600),
        'opensAt' => date('Y-m-d H:i:s', time() + 1200), 'closesAt' => date('Y-m-d H:i:s', time() + 2400)], null);
    [$saved, $error] = electionPositionSave($pdo, $board['id'], ['title' => 'Board Chair']);
    $snapshot = batchSnapshot($pdo);
    [$unused, $error] = electionNominateBatch($pdo, $board['slug'], $users[0], [['positionId' => $saved[0]['id'], 'userId' => $users[4]]]);
    batchCheck($error !== null && batchSnapshot($pdo) === $snapshot && electionStaffOwnNominations($pdo, $board['id'], $users[0]) === [],
        'board elections retain their separate single-nomination behavior and have no staff own-choice rows');

    $pdo->prepare("UPDATE elections SET nominations_close_at=DATE_SUB(NOW(),INTERVAL 1 SECOND) WHERE id=?")->execute([$id]);
    [$unused, $error] = electionNominateBatch($pdo, $main['slug'], $users[0], $choices);
    batchCheck($error !== null && batchSnapshot($pdo) === $snapshot, 'a closed nomination window rejects even an identical batch without changing records');
    echo "\n" . $checks . " batch nomination checks passed against a disposable database.\n";
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $pdo = null;
    $server->exec('DROP DATABASE ' . $database);
}
