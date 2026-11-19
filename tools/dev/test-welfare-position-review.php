<?php
declare(strict_types=1);

/**
 * Staff welfare one-post review integration tests using synthetic accounts only.
 * Requires the disposable MariaDB test server at 127.0.0.1:33317 with root password
 * LocalWelfareTestsOnly!. Does not load config.php or connect to the portal database.
 * Run: php tools/dev/test-welfare-position-review.php
 */
date_default_timezone_set('Africa/Nairobi');
require_once __DIR__ . '/../../resok-portal/public/api/lib/elections.php';
require_once __DIR__ . '/../../resok-portal/public/api/lib/staff.php';
require_once __DIR__ . '/../../resok-portal/public/api/lib/staff-portal.php';
require_once __DIR__ . '/../../resok-portal/public/api/lib/welfare.php';
require_once __DIR__ . '/../../resok-portal/public/api/lib/migrate.php';

function reviewConnect(?string $database = null): PDO
{
    if ($database !== null && !preg_match('/^resok_welfare_test_[a-f0-9]+$/', $database)) {
        throw new RuntimeException('Unsafe synthetic database name');
    }
    $pdo = new PDO('mysql:host=127.0.0.1;port=33317;' . ($database ? 'dbname=' . $database . ';' : '') . 'charset=utf8mb4',
        'root', 'LocalWelfareTestsOnly!',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    if ((int)$pdo->query('SELECT @@port')->fetchColumn() !== 33317) throw new RuntimeException('Not a test server');
    $pdo->exec("SET time_zone='+03:00'");
    return $pdo;
}

if (($argv[1] ?? '') === '--choice-worker') {
    $pdo = reviewConnect($argv[2]);
    echo "ready\n";
    flush();
    if (trim((string)fgets(STDIN)) !== 'go') throw new RuntimeException('Worker not released');
    echo json_encode(electionRecordStaffPositionChoice($pdo, (int)$argv[3], (int)$argv[4], (int)$argv[5],
        'Synthetic concurrent confirmation', (int)$argv[6]), JSON_THROW_ON_ERROR);
    exit;
}

$checks = 0;
function reviewCheck(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) throw new RuntimeException('FAIL: ' . $message);
    ++$checks;
    echo 'ok: ' . $message . "\n";
}

function reviewApply(PDO $pdo, string $file): void
{
    foreach (migrateSplit((string)file_get_contents(__DIR__ . '/../../resok-portal/server/' . $file)) as $sql) $pdo->exec($sql);
}

function reviewFixture(PDO $pdo, array $users, string $title, array $postTitles = ['Chairperson', 'Secretary', 'Treasurer']): array
{
    [$election, $error] = electionCreate($pdo, [
        'electorate' => 'staff', 'title' => $title,
        'nominationsOpenAt' => date('Y-m-d H:i:s', time() - 600),
        'nominationsCloseAt' => date('Y-m-d H:i:s', time() + 600),
        'opensAt' => date('Y-m-d H:i:s', time() + 1200),
        'closesAt' => date('Y-m-d H:i:s', time() + 3600),
    ], null);
    if ($error !== null) throw new RuntimeException($error);
    $positions = [];
    foreach ($postTitles as $postTitle) {
        [$saved, $error] = electionPositionSave($pdo, $election['id'], ['title' => $postTitle, 'allowAbstain' => false]);
        if ($error !== null) throw new RuntimeException($error);
        foreach ($saved as $post) if ($post['title'] === $postTitle) $positions[$postTitle] = $post['id'];
    }
    $pdo->prepare('INSERT INTO welfare_settings(election_id,staff_target) VALUES(?,0)')->execute([$election['id']]);
    welfareArm($pdo, $election['id'], true);
    welfareRunScheduled($pdo);
    return [$election, $positions];
}

function reviewNominate(PDO $pdo, array $election, int $nominator, int $position, int $nominee): int
{
    [$result, $error] = electionNominate($pdo, $election['slug'], $nominator, ['positionId' => $position, 'userId' => $nominee]);
    if ($error !== null) throw new RuntimeException($error);
    $query = $pdo->prepare('SELECT id FROM election_candidates WHERE position_id=? AND nominee_user_id=?');
    $query->execute([$position, $nominee]);
    return (int)$query->fetchColumn();
}

function reviewDue(PDO $pdo, int $election): void
{
    $pdo->prepare("UPDATE elections SET status='nominations_closed',nominations_close_at=DATE_SUB(NOW(),INTERVAL 5 MINUTE),
        opens_at=DATE_SUB(NOW(),INTERVAL 1 MINUTE) WHERE id=?")->execute([$election]);
}

function reviewCandidate(PDO $pdo, int $election, int $candidate): array
{
    foreach (electionPositions($pdo, $election) as $post) foreach ($post['candidates'] as $entry) {
        if ($entry['id'] === $candidate) return $entry;
    }
    throw new RuntimeException('Expected synthetic candidate missing');
}

function reviewCounts(PDO $pdo, int $election): array
{
    return array_column(electionNominations($pdo, $election), 'nominationCount', 'id');
}

function reviewSnapshot(PDO $pdo, int $election): array
{
    $query = $pdo->prepare('SELECT c.* FROM election_candidates c JOIN election_positions p ON p.id=c.position_id
        WHERE p.election_id=? ORDER BY c.id');
    $query->execute([$election]);
    return $query->fetchAll();
}

function reviewEventRows(PDO $pdo): array
{
    return $pdo->query('SELECT * FROM election_staff_position_choice_events ORDER BY id')->fetchAll();
}

function reviewWorker(string $database, int $election, int $nominee, int $position, int $admin): array
{
    $pipes = [];
    $process = proc_open([PHP_BINARY, __FILE__, '--choice-worker', $database, (string)$election,
        (string)$nominee, (string)$position, (string)$admin],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Cannot start review worker');
    if (trim((string)fgets($pipes[1])) !== 'ready') throw new RuntimeException('Review worker failed: ' . stream_get_contents($pipes[2]));
    return [$process, $pipes];
}

function reviewFinishWorker(array $worker): array
{
    $result = stream_get_contents($worker[1][1]);
    $error = stream_get_contents($worker[1][2]);
    fclose($worker[1][1]);
    fclose($worker[1][2]);
    if (proc_close($worker[0]) !== 0) throw new RuntimeException('Review worker failed: ' . $error);
    return json_decode($result, true, 512, JSON_THROW_ON_ERROR);
}

$server = reviewConnect();
$database = 'resok_welfare_test_' . bin2hex(random_bytes(6));
$server->exec('CREATE DATABASE ' . $database);
$pdo = reviewConnect($database);
try {
    foreach (['schema.sql', 'schema-elections.sql', 'schema-elections-ballot.sql',
        'migration-election-nominations.sql', 'migration-election-external-nominees.sql', 'migration-election-staff.sql',
        'migration-2026-11-03-welfare-automation.sql', 'migration-2026-11-19-welfare-nomination-records.sql'] as $file) reviewApply($pdo, $file);
    reviewCheck(staffPortalEnsureTables($pdo), 'synthetic staff schema initializes');
    $users = [];
    for ($index = 0; $index < 10; ++$index) {
        $pdo->prepare("INSERT INTO users(email,password_hash,email_verified,role) VALUES(?,'unused',1,'member')")
            ->execute(['position-' . $index . '@welfare.test']);
        $users[] = (int)$pdo->lastInsertId();
        $pdo->prepare('INSERT INTO staff_accounts(user_id,full_name,active) VALUES(?,?,1)')
            ->execute([$users[$index], 'Synthetic Position Staff ' . $index]);
    }
    $pdo->exec("INSERT INTO users(email,password_hash,email_verified,role) VALUES('officer@welfare.test','unused',1,'admin')");
    $admin = (int)$pdo->lastInsertId();
    $pdo->exec("INSERT INTO users(email,password_hash,email_verified,role) VALUES('off-roll@welfare.test','unused',1,'member')");
    $outsider = (int)$pdo->lastInsertId();

    [$main, $posts] = reviewFixture($pdo, $users, 'Synthetic Welfare Position Review');
    $id = $main['id'];
    $chair = $posts['Chairperson'];
    $secretary = $posts['Secretary'];
    $treasurer = $posts['Treasurer'];
    $chairA = reviewNominate($pdo, $main, $users[0], $chair, $users[5]);
    reviewNominate($pdo, $main, $users[1], $chair, $users[5]);
    $secretaryA = reviewNominate($pdo, $main, $users[0], $secretary, $users[5]);
    $secretaryB = reviewNominate($pdo, $main, $users[2], $secretary, $users[6]);
    $treasurerC = reviewNominate($pdo, $main, $users[3], $treasurer, $users[7]);
    reviewCheck(reviewCounts($pdo, $id)[$chairA] === 2 && reviewCounts($pdo, $id)[$secretaryA] === 1,
        'same staff member can receive separate nomination totals for multiple posts');
    $snapshot = reviewSnapshot($pdo, $id);
    reviewDue($pdo, $id);
    $review = electionStaffPositionReview($pdo, $id);
    reviewCheck(!$review['available'] && !$review['ready'], 'missing review migration is visible and blocks readiness');
    [$unused, $error] = electionSetStatus($pdo, $id, 'open');
    reviewCheck($error !== null && !electionFind($pdo, (string)$id)['openedAt'], 'manual opening fails safely without review migration');
    welfareRunScheduled($pdo);
    reviewCheck(!electionFind($pdo, (string)$id)['openedAt'] && welfareSettings($pdo, $id)['last_error'] !== null,
        'cron opening fails safely without review migration');
    reviewCheck($pdo->query("SHOW TABLES LIKE 'election_staff_position%'")->fetchAll() === [], 'review reads and blocked opening do not create schema');
    reviewApply($pdo, 'migration-2026-11-19-welfare-position-review.sql');
    reviewApply($pdo, 'migration-2026-11-19-welfare-position-review.sql');
    $review = electionStaffPositionReview($pdo, $id);
    reviewCheck($review['available'] && !$review['ready'] && count($review['conflicts']) === 1,
        'review identifies the nominee with approved candidacies in two posts');
    [$unused, $error] = electionSetStatus($pdo, $id, 'open');
    reviewCheck($error !== null && !electionFind($pdo, (string)$id)['openedAt'], 'manual opening is blocked until a conflicting nominee chooses a post');
    welfareRunScheduled($pdo);
    reviewCheck(!electionFind($pdo, (string)$id)['openedAt'], 'automatic opening is blocked by the same unresolved conflict');

    $pdo->prepare("UPDATE elections SET status='nominations',nominations_close_at=DATE_ADD(NOW(),INTERVAL 5 MINUTE),
        opens_at=DATE_ADD(NOW(),INTERVAL 10 MINUTE) WHERE id=?")->execute([$id]);
    [$unused, $error] = electionRecordStaffPositionChoice($pdo, $id, $users[5], $chair, 'Synthetic early attempt', $admin);
    reviewCheck($error !== null && reviewEventRows($pdo) === [], 'position choice cannot be recorded while nominations are open');
    $pdo->prepare("UPDATE elections SET status='nominations_closed' WHERE id=?")->execute([$id]);
    [$unused, $error] = electionRecordStaffPositionChoice($pdo, $id, $users[5], $chair, 'Synthetic premature close', $admin);
    reviewCheck($error !== null, 'closing the phase early does not bypass the actual nomination closing time');
    reviewDue($pdo, $id);
    [$unused, $error] = electionRecordStaffPositionChoice($pdo, $id, $users[5], $treasurer, 'Synthetic wrong post', $admin);
    reviewCheck($error !== null, 'a nominee cannot choose a post for which they were not nominated');
    [$unused, $error] = electionRecordStaffPositionChoice($pdo, $id, $outsider, $chair, 'Synthetic non-roll nominee', $admin);
    reviewCheck($error !== null, 'non-roll accounts cannot receive a recorded position choice');
    [$unused, $error] = electionRecordStaffPositionChoice($pdo, $id, $users[5], $chair, '   ', $admin);
    reviewCheck($error !== null && reviewEventRows($pdo) === [], 'a confirmation note is required and rejected attempts leave no audit events');

    $note = 'Synthetic nominee confirmed Chairperson to the returning officer';
    [$result, $error] = electionRecordStaffPositionChoice($pdo, $id, $users[5], $chair, $note, $admin);
    reviewCheck($error === null && electionStaffPositionReview($pdo, $id)['ready'], 'officer records one chosen post and resolves the conflict');
    reviewCheck(reviewSnapshot($pdo, $id) === $snapshot, 'recording a choice preserves all candidate rows and original statuses');
    reviewCheck(reviewCounts($pdo, $id)[$chairA] === 2 && reviewCounts($pdo, $id)[$secretaryA] === 1,
        'nomination counts for selected and unselected posts remain unchanged');
    reviewCheck(reviewCandidate($pdo, $id, $chairA)['standing'] && !reviewCandidate($pdo, $id, $secretaryA)['standing']
        && reviewCandidate($pdo, $id, $secretaryA)['status'] === 'approved'
        && reviewCandidate($pdo, $id, $secretaryA)['ballotStatus'] === 'not_contesting',
        'selected post stands while other approved candidacies are marked not contesting');
    $events = reviewEventRows($pdo);
    reviewCheck(count($events) === 1 && $events[0]['confirmation_note'] === $note
        && (int)$events[0]['actor_id'] === $admin && (int)$events[0]['nominee_user_id'] === $users[5]
        && (int)$events[0]['new_position_id'] === $chair && $events[0]['previous_position_id'] === null
        && strlen($events[0]['candidate_set_hash']) === 64,
        'choice records nominee, officer, selected post, fingerprint and confirmation evidence');
    [$result, $error] = electionRecordStaffPositionChoice($pdo, $id, $users[5], $chair, $note, $admin);
    reviewCheck($error === null && reviewEventRows($pdo) === $events, 'an identical choice retry is idempotent and preserves its audit history');

    // Simulate a permitted pre-opening candidacy addition after an earlier confirmation.
    $pdo->prepare("INSERT INTO election_candidates(position_id,nominee_user_id,name,status)
        VALUES(?,?,'Synthetic Added Candidacy','approved')")->execute([$treasurer, $users[5]]);
    $addedA = (int)$pdo->lastInsertId();
    reviewCheck(!electionStaffPositionReview($pdo, $id)['ready'], 'a new approved candidacy invalidates the previous confirmation fingerprint');
    [$unused, $error] = electionSetStatus($pdo, $id, 'open');
    reviewCheck($error !== null, 'an outdated choice cannot authorize voting opening');
    [$result, $error] = electionRecordStaffPositionChoice($pdo, $id, $users[5], $chair, 'Synthetic reaffirmation after new candidacy', $admin);
    reviewCheck($error === null && electionStaffPositionReview($pdo, $id)['ready'] && count(reviewEventRows($pdo)) === 2,
        'reaffirming the choice after candidacy change appends new evidence');
    $pdo->exec("UPDATE election_candidates SET status='withdrawn' WHERE id=" . $addedA);
    reviewCheck(!electionStaffPositionReview($pdo, $id)['ready'], 'a later candidacy status change invalidates its prior confirmation');
    [$result, $error] = electionRecordStaffPositionChoice($pdo, $id, $users[5], $chair, 'Synthetic reaffirmation after withdrawal', $admin);
    reviewCheck($error === null && electionStaffPositionReview($pdo, $id)['ready'] && count(reviewEventRows($pdo)) === 3,
        'updated confirmation preserves all earlier choice events');
    // Keep this ready fixture from auto-opening while other fixtures advance their clocks.
    welfareArm($pdo, $id, false);

    [$empty, $emptyPosts] = reviewFixture($pdo, $users, 'Synthetic Welfare Vacant Remaining Post', ['Chairperson', 'Secretary']);
    reviewNominate($pdo, $empty, $users[0], $emptyPosts['Chairperson'], $users[5]);
    reviewNominate($pdo, $empty, $users[0], $emptyPosts['Secretary'], $users[5]);
    reviewDue($pdo, $empty['id']);
    [$result, $error] = electionRecordStaffPositionChoice($pdo, $empty['id'], $users[5], $emptyPosts['Chairperson'],
        'Synthetic nominee chooses Chairperson leaving Secretary vacant', $admin);
    reviewCheck($error === null && !electionStaffPositionReview($pdo, $empty['id'])['ready'],
        'a recorded choice does not hide a post left without a standing candidate');
    [$unused, $error] = electionSetStatus($pdo, $empty['id'], 'open');
    reviewCheck($error !== null && !electionFind($pdo, (string)$empty['id'])['openedAt'], 'remaining candidate sufficiency is enforced before manual opening');
    welfareRunScheduled($pdo);
    reviewCheck(!electionFind($pdo, (string)$empty['id'])['openedAt'], 'remaining candidate sufficiency is enforced by cron');

    [$changed, $changedPosts] = reviewFixture($pdo, $users, 'Synthetic Chosen Position Becomes Unavailable', ['Chairperson', 'Secretary']);
    $changedChair = reviewNominate($pdo, $changed, $users[0], $changedPosts['Chairperson'], $users[5]);
    $changedSecretary = reviewNominate($pdo, $changed, $users[0], $changedPosts['Secretary'], $users[5]);
    reviewNominate($pdo, $changed, $users[1], $changedPosts['Chairperson'], $users[6]);
    reviewNominate($pdo, $changed, $users[1], $changedPosts['Secretary'], $users[7]);
    reviewDue($pdo, $changed['id']);
    [$result, $error] = electionRecordStaffPositionChoice($pdo, $changed['id'], $users[5], $changedPosts['Chairperson'],
        'Synthetic first choice of Chairperson', $admin);
    if ($error !== null) throw new RuntimeException($error);
    [$unused, $error] = electionCandidateSave($pdo, $changedPosts['Chairperson'], ['status' => 'disqualified'], $changedChair);
    if ($error !== null) throw new RuntimeException($error);
    $changedReview = electionStaffPositionReview($pdo, $changed['id']);
    reviewCheck(!$changedReview['ready'] && count($changedReview['conflicts']) === 1
        && count($changedReview['conflicts'][0]['positions']) === 1,
        'losing the selected post requires reconfirmation even when one eligible post remains');
    [$unused, $error] = electionSetStatus($pdo, $changed['id'], 'open');
    reviewCheck($error !== null, 'a formerly excluded post cannot silently authorize voting after the chosen post changes');
    [$result, $error] = electionRecordStaffPositionChoice($pdo, $changed['id'], $users[5], $changedPosts['Secretary'],
        'Synthetic nominee explicitly confirms the remaining Secretary position', $admin);
    reviewCheck($error === null && electionStaffPositionReview($pdo, $changed['id'])['ready']
        && reviewCandidate($pdo, $changed['id'], $changedSecretary)['standing'],
        'officer can record a new explicit choice for the remaining eligible position');
    [$unused, $error] = electionDelete($pdo, 'candidate', $changedSecretary);
    reviewCheck($error !== null && reviewCounts($pdo, $changed['id'])[$changedSecretary] === 1,
        'candidate deletion cannot erase a recorded staff nomination');
    [$unused, $error] = electionDelete($pdo, 'position', $changedPosts['Secretary']);
    reviewCheck($error !== null && reviewCandidate($pdo, $changed['id'], $changedSecretary)['standing'],
        'post deletion cannot erase recorded staff nominations');
    welfareArm($pdo, $changed['id'], false);

    [$cronReview, $cronPosts] = reviewFixture($pdo, $users, 'Synthetic Scheduled Nomination Closure', ['Chairperson', 'Secretary']);
    reviewNominate($pdo, $cronReview, $users[0], $cronPosts['Chairperson'], $users[5]);
    reviewNominate($pdo, $cronReview, $users[0], $cronPosts['Secretary'], $users[5]);
    $pdo->prepare("UPDATE elections SET nominations_close_at=DATE_SUB(NOW(),INTERVAL 5 MINUTE),
        opens_at=DATE_SUB(NOW(),INTERVAL 1 MINUTE) WHERE id=?")->execute([$cronReview['id']]);
    welfareRunScheduled($pdo);
    reviewCheck(electionFind($pdo, (string)$cronReview['id'])['status'] === 'nominations_closed'
        && welfareSettings($pdo, $cronReview['id'])['last_error'] !== null,
        'cron commits nomination closure while recording the conflict that blocks voting');

    // Repeated resolution requests waiting behind opening must see its irreversible lock.
    $pdo->beginTransaction();
    $pdo->query('SELECT id FROM elections WHERE id=' . $id . ' FOR UPDATE')->fetch();
    $worker = reviewWorker($database, $id, $users[5], $secretary, $admin);
    fwrite($worker[1][0], "go\n");
    fclose($worker[1][0]);
    [$opened, $error] = electionSetStatus($pdo, $id, 'open');
    if ($error !== null) throw new RuntimeException($error);
    $pdo->commit();
    [$unused, $workerError] = reviewFinishWorker($worker);
    reviewCheck($workerError !== null && electionFind($pdo, (string)$id)['openedAt'] !== null,
        'a concurrent choice queued behind voting opening is rejected by the election lock');
    [$unused, $error] = electionRecordStaffPositionChoice($pdo, $id, $users[5], $chair, 'Synthetic attempt after voting opened', $admin);
    reviewCheck($error !== null, 'even an identical recorded choice cannot be edited after voting has opened');
    [$ballot, $error] = electionBallotFor($pdo, $main['slug'], $users[0]);
    $ballotIds = [];
    foreach ($ballot['positions'] as $post) foreach ($post['candidates'] as $candidate) $ballotIds[] = $candidate['id'];
    reviewCheck($error === null && in_array($chairA, $ballotIds, true) && !in_array($secretaryA, $ballotIds, true)
        && !in_array($addedA, $ballotIds, true), 'voter ballot includes only the selected standing candidacy for a conflicting nominee');
    [$unused, $error] = electionCastVote($pdo, $main['slug'], $users[0], [$chair => [$chairA], $secretary => [$secretaryA], $treasurer => [$treasurerC]], null);
    reviewCheck($error !== null && (int)$pdo->query('SELECT COUNT(*) FROM election_ballots WHERE election_id=' . $id)->fetchColumn() === 0,
        'vote validation rejects the excluded candidacy and writes no partial ballot');
    [$receipt, $error] = electionCastVote($pdo, $main['slug'], $users[0], [$chair => [$chairA], $secretary => [$secretaryB], $treasurer => [$treasurerC]], null);
    reviewCheck($error === null && $receipt['recorded'], 'valid choices across the remaining standing candidates record a ballot');
    [$closed, $error] = electionSetStatus($pdo, $id, 'closed');
    [$result, $error] = electionDeclareResult($pdo, $id, $admin, 'Synthetic reviewed declaration');
    reviewCheck($error === null, 'a reviewed one-post ballot can be declared');
    $declared = $pdo->query('SELECT candidate_id,elected FROM election_results WHERE election_id=' . $id)->fetchAll();
    $declaredIds = array_map(static fn(array $row): int => (int)$row['candidate_id'], $declared);
    reviewCheck(!in_array($secretaryA, $declaredIds, true) && !in_array($addedA, $declaredIds, true)
        && count(array_filter($declared, static fn(array $row): bool => (int)$row['elected'] === 1)) === 3,
        'declaration excludes non-contesting candidacies and elects the nominee in only their chosen post');

    [$equal, $equalPosts] = reviewFixture($pdo, $users, 'Synthetic Welfare Missing Review Gap', ['Chairperson']);
    reviewNominate($pdo, $equal, $users[0], $equalPosts['Chairperson'], $users[5]);
    $pdo->prepare("UPDATE elections SET status='nominations_closed',nominations_close_at=DATE_SUB(NOW(),INTERVAL 1 MINUTE),
        opens_at=nominations_close_at WHERE id=?")->execute([$equal['id']]);
    $review = electionStaffPositionReview($pdo, $equal['id']);
    [$unused, $error] = electionSetStatus($pdo, $equal['id'], 'open');
    reviewCheck(!$review['reviewPeriodValid'] && !$review['ready'] && $error !== null,
        'equal nomination-closing and voting-opening times cannot bypass the review interval');

    [$board, $error] = electionCreate($pdo, ['title' => 'Synthetic Board Review Regression', 'eligibilityCutoff' => date('Y-m-d'),
        'nominationsOpenAt' => date('Y-m-d H:i:s', time() - 600), 'nominationsCloseAt' => date('Y-m-d H:i:s', time() - 300),
        'opensAt' => date('Y-m-d H:i:s', time() - 300), 'closesAt' => date('Y-m-d H:i:s', time() + 1800)], $admin);
    reviewCheck($error === null, 'board elections retain their existing schedule validation');
    [$saved, $error] = electionPositionSave($pdo, $board['id'], ['title' => 'Board Chair']);
    [$unused, $error] = electionRecordStaffPositionChoice($pdo, $board['id'], $users[5], $saved[0]['id'], 'Synthetic board misuse', $admin);
    reviewCheck($error !== null, 'staff position choices cannot be recorded against a board election');

    [$legacy, $legacyPosts] = reviewFixture($pdo, $users, 'Synthetic Already Open Legacy Election', ['Chairperson', 'Secretary']);
    $legacyChair = reviewNominate($pdo, $legacy, $users[0], $legacyPosts['Chairperson'], $users[5]);
    $legacySecretary = reviewNominate($pdo, $legacy, $users[0], $legacyPosts['Secretary'], $users[5]);
    $pdo->prepare("UPDATE elections SET status='open',opened_at=NOW(),nominations_close_at=DATE_SUB(NOW(),INTERVAL 5 MINUTE),
        opens_at=DATE_SUB(NOW(),INTERVAL 1 MINUTE) WHERE id=?")->execute([$legacy['id']]);
    reviewCheck(reviewCandidate($pdo, $legacy['id'], $legacyChair)['standing']
        && reviewCandidate($pdo, $legacy['id'], $legacySecretary)['standing'],
        'already opened legacy election retains both original standing candidacies');
    [$unused, $error] = electionRecordStaffPositionChoice($pdo, $legacy['id'], $users[5], $legacyPosts['Chairperson'],
        'Synthetic legacy alteration attempt', $admin);
    reviewCheck($error !== null && !electionStaffPositionReview($pdo, $legacy['id'])['canRecord'],
        'already opened legacy election cannot acquire a new position choice');

    echo "\n" . $checks . " welfare position review checks passed against a disposable database.\n";
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $pdo = null;
    $server->exec('DROP DATABASE ' . $database);
}
