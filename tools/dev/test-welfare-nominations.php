<?php
declare(strict_types=1);

/**
 * Synthetic regression tests for repeated staff welfare nominations.
 * Requires the disposable MariaDB test server on 127.0.0.1:33317, with root password
 * LocalWelfareTestsOnly!; never reads config.php or uses the portal database.
 * Run: php tools/dev/test-welfare-nominations.php
 */
date_default_timezone_set('Africa/Nairobi');
require_once __DIR__ . '/../../resok-portal/public/api/lib/elections.php';
require_once __DIR__ . '/../../resok-portal/public/api/lib/staff.php';
require_once __DIR__ . '/../../resok-portal/public/api/lib/staff-portal.php';
require_once __DIR__ . '/../../resok-portal/public/api/lib/welfare.php';
require_once __DIR__ . '/../../resok-portal/public/api/lib/migrate.php';

function nominationTestConnect(?string $database = null): PDO
{
    if ($database !== null && !preg_match('/^resok_welfare_test_[a-f0-9]+$/', $database)) {
        throw new RuntimeException('Unsafe test database name');
    }
    $pdo = new PDO(
        'mysql:host=127.0.0.1;port=33317;' . ($database ? 'dbname=' . $database . ';' : '') . 'charset=utf8mb4',
        'root', 'LocalWelfareTestsOnly!',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
    if ((int)$pdo->query('SELECT @@port')->fetchColumn() !== 33317) {
        throw new RuntimeException('Refusing to use a non-test database server');
    }
    $pdo->exec("SET time_zone='+03:00'");
    return $pdo;
}

if (($argv[1] ?? '') === '--nominate-worker') {
    $pdo = nominationTestConnect($argv[2]);
    // Deliberately keep lazy staff schema cold to exercise notification transaction safety.
    echo "ready\n";
    flush();
    if (trim((string)fgets(STDIN)) !== 'go') throw new RuntimeException('Worker not released');
    echo json_encode(electionNominate($pdo, $argv[3], (int)$argv[4], [
        'positionId' => (int)$argv[5], 'userId' => (int)$argv[6],
    ]), JSON_THROW_ON_ERROR);
    exit;
}

$checks = 0;
function nominationCheck(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) throw new RuntimeException('FAIL: ' . $message);
    ++$checks;
    echo 'ok: ' . $message . "\n";
}

function nominationApply(PDO $pdo, string $filename): void
{
    $path = __DIR__ . '/../../resok-portal/server/' . $filename;
    foreach (migrateSplit((string)file_get_contents($path)) as $statement) $pdo->exec($statement);
}

function nominationCandidateCount(PDO $pdo, int $position, ?int $nominee = null): int
{
    $query = $pdo->prepare('SELECT COUNT(*) FROM election_candidates WHERE position_id=?'
        . ($nominee === null ? '' : ' AND nominee_user_id=?'));
    $query->execute($nominee === null ? [$position] : [$position, $nominee]);
    return (int)$query->fetchColumn();
}

function nominationRecordCount(PDO $pdo, int $position, ?int $nominator = null): int
{
    $query = $pdo->prepare('SELECT COUNT(*) FROM election_staff_nominations WHERE position_id=?'
        . ($nominator === null ? '' : ' AND nominator_user_id=?'));
    $query->execute($nominator === null ? [$position] : [$position, $nominator]);
    return (int)$query->fetchColumn();
}

function nominationWorker(string $database, string $slug, int $user, int $position, int $nominee): array
{
    $pipes = [];
    $process = proc_open([
        PHP_BINARY, __FILE__, '--nominate-worker', $database, $slug,
        (string)$user, (string)$position, (string)$nominee,
    ], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Could not start nomination worker');
    if (trim((string)fgets($pipes[1])) !== 'ready') {
        throw new RuntimeException('Nomination worker failed: ' . stream_get_contents($pipes[2]));
    }
    return [$process, $pipes];
}

function nominationFinishWorker(array $worker): array
{
    $output = stream_get_contents($worker[1][1]);
    $error = stream_get_contents($worker[1][2]);
    fclose($worker[1][1]);
    fclose($worker[1][2]);
    if (proc_close($worker[0]) !== 0) throw new RuntimeException('Nomination worker failed: ' . $error);
    return json_decode($output, true, 512, JSON_THROW_ON_ERROR);
}

$server = nominationTestConnect();
$database = 'resok_welfare_test_' . bin2hex(random_bytes(6));
$server->exec('CREATE DATABASE ' . $database);
$pdo = nominationTestConnect($database);
try {
    foreach ([
        'schema.sql', 'schema-elections.sql', 'schema-elections-ballot.sql',
        'migration-election-nominations.sql', 'migration-election-external-nominees.sql',
        'migration-election-staff.sql', 'migration-2026-11-03-welfare-automation.sql',
    ] as $file) nominationApply($pdo, $file);
    nominationCheck(staffPortalEnsureTables($pdo), 'isolated staff schema initializes');
    $insertUser = $pdo->prepare("INSERT INTO users(email,password_hash,email_verified,role) VALUES(?,'unused',1,'member')");
    $insertStaff = $pdo->prepare('INSERT INTO staff_accounts(user_id,full_name,active) VALUES(?,?,1)');
    $users = [];
    for ($index = 0; $index < 12; ++$index) {
        $insertUser->execute(['nomination-' . $index . '@welfare.test']);
        $users[] = (int)$pdo->lastInsertId();
        $insertStaff->execute([$users[$index], 'Synthetic Staff ' . $index]);
    }
    $insertUser->execute(['outsider@welfare.test']);
    $outsider = (int)$pdo->lastInsertId();
    [$election, $error] = electionCreate($pdo, [
        'electorate' => 'staff', 'title' => 'Synthetic Shared Nomination',
        'nominationsOpenAt' => date('Y-m-d H:i:s', time() - 300),
        'nominationsCloseAt' => date('Y-m-d H:i:s', time() + 1800),
        'opensAt' => date('Y-m-d H:i:s', time() + 2400),
        'closesAt' => date('Y-m-d H:i:s', time() + 3600),
    ], null);
    nominationCheck($error === null, 'synthetic staff election creates');
    $electionId = $election['id'];
    $positions = [];
    foreach (['Chairperson', 'Secretary', 'Treasurer', 'Concurrent Chair', 'Concurrent Secretary', 'Outer Transaction'] as $title) {
        [$saved, $error] = electionPositionSave($pdo, $electionId, ['title' => $title]);
        nominationCheck($error === null, 'synthetic post creates: ' . $title);
        foreach ($saved as $post) if ($post['title'] === $title) $positions[$title] = $post['id'];
    }
    $position = $positions['Chairperson'];
    $pdo->prepare('INSERT INTO welfare_settings(election_id,staff_target) VALUES(?,0)')->execute([$electionId]);
    welfareArm($pdo, $electionId, true);
    welfareRunScheduled($pdo);
    nominationCheck(electionFind($pdo, (string)$electionId)['nominationsOpen'], 'nomination window opens');

    // Existing live candidates remain valid when the additional records table is introduced.
    $legacyRoll = electionRollEntry($pdo, $electionId, $users[0]);
    $pdo->prepare("INSERT INTO election_candidates(position_id,nominee_user_id,nominated_by_roll_id,nominated_at,name,status)
        VALUES(?,?,?,NOW(),'Synthetic Staff 1','approved')")
        ->execute([$position, $users[1], $legacyRoll['id']]);
    $legacyCandidate = (int)$pdo->lastInsertId();
    [$unused, $error] = electionNominate($pdo, $election['slug'], $users[2], ['positionId' => $position, 'userId' => $users[1]]);
    nominationCheck($error !== null && nominationCandidateCount($pdo, $position) === 1,
        'missing records migration fails without adding or changing a candidate');
    nominationApply($pdo, 'migration-2026-11-19-welfare-nomination-records.sql');
    $migratedRecords = nominationRecordCount($pdo, $position);
    nominationApply($pdo, 'migration-2026-11-19-welfare-nomination-records.sql');
    nominationCheck(nominationRecordCount($pdo, $position) === $migratedRecords
        && nominationCandidateCount($pdo, $position) === 1, 'migration can be rerun without duplicating existing data');

    [$unused, $error] = electionNominate($pdo, $election['slug'], $users[0], ['positionId' => $position, 'userId' => $users[3]]);
    nominationCheck($error !== null && nominationCandidateCount($pdo, $position) === 1,
        'legacy first nominator cannot switch to a different nominee');
    [$result, $error] = electionNominate($pdo, $election['slug'], $users[0], ['positionId' => $position, 'userId' => $users[1]]);
    nominationCheck($error === null && $result['nominated'] && $result['final'], 'legacy first nominator can retry the same final nomination');
    [$result, $error] = electionNominate($pdo, $election['slug'], $users[2], ['positionId' => $position, 'userId' => $users[1]]);
    nominationCheck($error === null && $result['nominated'] && $result['approved'], 'another staff member can nominate an already nominated colleague');
    nominationCheck(nominationCandidateCount($pdo, $position) === 1
        && nominationRecordCount($pdo, $position, $users[2]) === 1, 'shared nominee keeps one candidate and stores the additional nominator');
    $recordTotal = nominationRecordCount($pdo, $position);
    [$result, $error] = electionNominate($pdo, $election['slug'], $users[2], ['positionId' => $position, 'userId' => $users[1], 'headline' => 'Must not overwrite']);
    nominationCheck($error === null && nominationRecordCount($pdo, $position) === $recordTotal
        && nominationCandidateCount($pdo, $position) === 1, 'same-nominee retry is idempotent');
    nominationCheck($pdo->query('SELECT headline FROM election_candidates WHERE id=' . $legacyCandidate)->fetchColumn() === null,
        'additional nominations do not overwrite the original candidate details');
    nominationCheck($pdo->query('SELECT accepted_at FROM election_candidates WHERE id=' . $legacyCandidate)->fetchColumn() === null,
        'repeated welfare nominations do not fabricate nominee acceptance');
    [$result, $error] = electionNominate($pdo, $election['slug'], $users[3], ['positionId' => $position, 'userId' => $users[4]]);
    nominationCheck($error === null && nominationCandidateCount($pdo, $position) === 2, 'a new nominee still receives a separate candidate entry');
    [$unused, $error] = electionNominate($pdo, $election['slug'], $users[2], ['positionId' => $position, 'userId' => $users[4]]);
    nominationCheck($error !== null && nominationRecordCount($pdo, $position, $users[2]) === 1,
        'a staff member cannot switch to a different existing candidate');
    [$unused, $error] = electionNominate($pdo, $election['slug'], $users[2], ['positionId' => $position, 'userId' => $users[6]]);
    nominationCheck($error !== null && nominationCandidateCount($pdo, $position, $users[6]) === 0,
        'rejected changes do not create another candidate');
    [$result, $error] = electionNominate($pdo, $election['slug'], $users[5], ['positionId' => $position, 'userId' => $users[5]]);
    nominationCheck($error === null && $result['final'], 'staff can still nominate themselves');
    [$unused, $error] = electionNominate($pdo, $election['slug'], $outsider, ['positionId' => $position, 'userId' => $users[1]]);
    nominationCheck($error !== null, 'off-roll accounts cannot add nominations to an existing candidate');
    [$unused, $error] = electionNominate($pdo, $election['slug'], $users[6], ['positionId' => $position, 'userId' => $outsider]);
    nominationCheck($error !== null && nominationRecordCount($pdo, $position, $users[6]) === 0,
        'off-roll nominees are refused without consuming a nomination');

    foreach (['withdrawn' => 'Secretary', 'disqualified' => 'Treasurer'] as $status => $title) {
        $post = $positions[$title];
        $pdo->prepare('INSERT INTO election_candidates(position_id,nominee_user_id,name,status) VALUES(?,?,?,?)')
            ->execute([$post, $users[1], 'Synthetic unavailable candidate', $status]);
        [$unused, $error] = electionNominate($pdo, $election['slug'], $users[6], ['positionId' => $post, 'userId' => $users[1]]);
        nominationCheck($error !== null && nominationRecordCount($pdo, $post) === 0,
            $status . ' candidates are not revived by an additional nomination');
    }

    // Hold the election lock so both workers queue before either records a nomination.
    foreach ([['Concurrent Chair', $users[6], $users[7], $users[8], $users[8]],
              ['Concurrent Secretary', $users[9], $users[9], $users[10], $users[11]]] as $race) {
        [$title, $firstUser, $secondUser, $firstNominee, $secondNominee] = $race;
        $post = $positions[$title];
        $pdo->beginTransaction();
        $pdo->query('SELECT id FROM elections WHERE id=' . $electionId . ' FOR UPDATE')->fetch();
        $first = nominationWorker($database, $election['slug'], $firstUser, $post, $firstNominee);
        $second = nominationWorker($database, $election['slug'], $secondUser, $post, $secondNominee);
        foreach ([$first, $second] as $worker) {
            fwrite($worker[1][0], "go\n");
            fclose($worker[1][0]);
        }
        $pdo->commit();
        $answers = [nominationFinishWorker($first), nominationFinishWorker($second)];
        $successes = count(array_filter($answers, static fn(array $answer): bool => $answer[1] === null));
        nominationCheck(count(array_filter($answers, static fn(array $answer): bool =>
            is_array($answer[0]) && array_key_exists('_staffNotification', $answer[0]))) === 0,
            'cold nomination workers do not expose private notification details: ' . $title);
        if ($firstUser !== $secondUser) {
            nominationCheck($successes === 2 && nominationCandidateCount($pdo, $post) === 1
                && nominationRecordCount($pdo, $post) === 2, 'concurrent staff nominations share one candidate and retain both records');
        } else {
            nominationCheck($successes === 1 && nominationCandidateCount($pdo, $post) === 1
                && nominationRecordCount($pdo, $post) === 1, 'concurrent different choices by one staff member retain only the first final nomination');
        }
        $notificationQuery = $pdo->prepare('SELECT COUNT(*) FROM staff_notifications WHERE kind=? AND title LIKE ?');
        $notificationQuery->execute(['election', 'You have been nominated for ' . $title . ' in %']);
        nominationCheck((int)$notificationQuery->fetchColumn() === 1,
            'cold workers send exactly one notification for the single new candidate: ' . $title);
    }

    $outerPosition = $positions['Outer Transaction'];
    $pdo->beginTransaction();
    [$result, $error] = electionNominate($pdo, $election['slug'], $users[10], ['positionId' => $outerPosition, 'userId' => $users[11]]);
    nominationCheck($error === null && $pdo->inTransaction() && !array_key_exists('_staffNotification', $result),
        'nomination preserves an outer transaction and hides private notification details');
    nominationCheck(nominationCandidateCount($pdo, $outerPosition) === 1 && nominationRecordCount($pdo, $outerPosition) === 1,
        'candidate and nomination record are present in the caller transaction');
    $notificationQuery = $pdo->prepare('SELECT COUNT(*) FROM staff_notifications WHERE kind=? AND title LIKE ?');
    $notificationQuery->execute(['election', 'You have been nominated for Outer Transaction in %']);
    nominationCheck((int)$notificationQuery->fetchColumn() === 0, 'an uncommitted outer transaction does not dispatch notifications');
    $pdo->rollBack();
    nominationCheck(nominationCandidateCount($pdo, $outerPosition) === 0 && nominationRecordCount($pdo, $outerPosition) === 0,
        'rolling back the caller transaction removes both candidate and nomination record');

    // The separate board-election rules must continue rejecting a duplicate nominee.
    [$board, $error] = electionCreate($pdo, [
        'title' => 'Synthetic Board Nomination Regression', 'eligibilityCutoff' => date('Y-m-d'),
        'nominationsOpenAt' => date('Y-m-d H:i:s', time() - 300),
        'nominationsCloseAt' => date('Y-m-d H:i:s', time() + 1800),
        'opensAt' => date('Y-m-d H:i:s', time() + 2400), 'closesAt' => date('Y-m-d H:i:s', time() + 3600),
    ], null);
    nominationCheck($error === null, 'synthetic board election creates');
    [$saved, $error] = electionPositionSave($pdo, $board['id'], ['title' => 'Board Chair']);
    $boardPosition = $saved[0]['id'];
    $memberIds = [];
    foreach ([0, 1] as $index) {
        $pdo->prepare("INSERT INTO member_profiles(user_id,first_name,surname,mobile,membership_status,renewal_due)
            VALUES(?,'Synthetic','Member','0700000000','active',DATE_ADD(CURDATE(),INTERVAL 1 YEAR))")->execute([$users[$index]]);
        $memberIds[] = (int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO election_roll(election_id,member_profile_id,user_id,name) VALUES(?,?,?,'Synthetic board voter')")
            ->execute([$board['id'], $memberIds[$index], $users[$index]]);
    }
    [$unused, $error] = electionSetStatus($pdo, $board['id'], 'nominations');
    nominationCheck($error === null, 'board nomination window opens');
    [$result, $error] = electionNominate($pdo, $board['slug'], $users[0], ['positionId' => $boardPosition, 'memberProfileId' => $memberIds[1]]);
    nominationCheck($error === null && !$result['accepted'], 'board nominees still need their own acceptance');
    [$unused, $error] = electionNominate($pdo, $board['slug'], $users[1], ['positionId' => $boardPosition, 'memberProfileId' => $memberIds[1]]);
    nominationCheck($error !== null && str_contains($error, 'already been nominated')
        && nominationRecordCount($pdo, $boardPosition) === 0, 'board duplicate rules and staff-record isolation remain unchanged');

    $pdo->exec('UPDATE elections SET nominations_close_at=DATE_SUB(NOW(),INTERVAL 1 SECOND) WHERE id=' . $electionId);
    [$unused, $error] = electionNominate($pdo, $election['slug'], $users[2], ['positionId' => $position, 'userId' => $users[1]]);
    nominationCheck($error !== null, 'closed nomination window rejects retries as well as new nominations');
    echo "\n" . $checks . " focused nomination checks passed against a disposable database.\n";
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $pdo = null;
    $server->exec('DROP DATABASE ' . $database);
}
