<?php
declare(strict_types=1);

/**
 * ReSoK board elections.
 *
 * The rules an election has to keep are not the ones ordinary CRUD keeps, so most of this
 * file is refusals rather than writes.
 *
 * Nothing about a live election may change. Once opened_at is set, posts, candidates and the
 * roll are closed to editing - an election whose ballot paper can change while people are
 * voting is not an election, and every guard here compares against opened_at rather than
 * status, because a status can be moved back and a timestamp that has been set cannot.
 *
 * The roll is frozen at the moment it is drawn. Eligibility is decided once and written as
 * rows; nothing recomputes it afterwards. A member who renews mid-election does not join the
 * electorate and one who lapses does not leave it, so the number of people entitled to vote
 * is a fact about the election rather than about when you asked.
 *
 * A ballot is one act. Every post a voter answers is written in a single transaction with
 * the mark on the roll that says they have voted; either all of it lands or none of it does.
 * A half-cast ballot - some posts recorded, the voter unable to return - is the failure that
 * would be impossible to put right afterwards without knowing what they had already chosen.
 *
 * Votes are final on submission. Not because editing is technically hard, but because a
 * ballot that can be changed while others are still voting turns a vote into a position that
 * can be revised in response to a running tally.
 */

require_once __DIR__ . '/membership.php';

/**
 * Every status the column allows, in the order an election passes through them.
 *
 * Must stay identical to the enum in the database. A list here that is missing a value the
 * column has means electionSetStatus refuses a legitimate transition; a value here the column
 * lacks means the write fails at the database instead. Both were one edit away when the
 * nomination phase was added.
 */
const ELECTION_STATUSES = ['draft', 'nominations', 'nominations_closed', 'roll_published',
                           'open', 'closed', 'published', 'cancelled'];
const ELECTION_CANDIDATE_STATUSES = ['nominated', 'approved', 'withdrawn', 'disqualified'];

function electionsEnsureTables(PDO $pdo): bool
{
    static $ready = null;
    if ($ready !== null) return $ready;
    try {
        foreach (['elections', 'election_positions', 'election_candidates',
                  'election_roll', 'election_ballots', 'election_results'] as $table) {
            $pdo->query('SELECT 1 FROM ' . $table . ' LIMIT 1');
        }
        return $ready = true;
    } catch (Throwable $e) {
        error_log('Election tables unavailable: ' . $e->getMessage());
        return $ready = false;
    }
}

/** @param array<string,mixed> $row */
function electionShape(array $row): array
{
    $now = new DateTimeImmutable('now');
    $opens = $row['opens_at'] ? new DateTimeImmutable((string)$row['opens_at']) : null;
    $closes = $row['closes_at'] ? new DateTimeImmutable((string)$row['closes_at']) : null;

    // Whether voting is actually possible, which is not the same as the status saying 'open'.
    // A status can be set early or left behind; the window is what decides.
    $live = $row['status'] === 'open' && $opens && $closes && $now >= $opens && $now <= $closes;

    // The same test for the nomination window. An election runs in two parts - members put
    // names forward, then members vote on them - and each part has its own dates.
    $nomOpen = !empty($row['nominations_open_at'])
        ? new DateTimeImmutable((string)$row['nominations_open_at']) : null;
    $nomClose = !empty($row['nominations_close_at'])
        ? new DateTimeImmutable((string)$row['nominations_close_at']) : null;
    $nominating = $row['status'] === 'nominations' && $nomOpen && $nomClose
                  && $now >= $nomOpen && $now <= $nomClose;

    return [
        'id'          => (int)$row['id'],
        'slug'        => $row['slug'],
        'title'       => $row['title'],
        'description' => $row['description'],
        'eligibilityCutoff' => $row['eligibility_cutoff'],
        'nominationsOpenAt'  => $row['nominations_open_at'] ?? null,
        'nominationsCloseAt' => $row['nominations_close_at'] ?? null,
        'nominationsOpen'    => $nominating,
        'opensAt'     => $row['opens_at'],
        'closesAt'    => $row['closes_at'],
        'status'      => $row['status'],
        'openedAt'    => $row['opened_at'],
        'closedAt'    => $row['closed_at'],
        'resultsPublishedAt' => $row['results_published_at'],
        'votingOpen'  => $live,
        // Editing stops the moment voting has ever started, and never resumes.
        'locked'      => !empty($row['opened_at']),
    ];
}

/** @return list<array<string,mixed>> */
function electionsList(PDO $pdo, bool $includeDrafts = false): array
{
    if (!electionsEnsureTables($pdo)) return [];
    $sql = 'SELECT * FROM elections';
    // Everything except a draft, because a draft is the only state members are not meant to
    // see. The nomination statuses were missing from this list when the phase was added,
    // which hid the election from exactly the people it was asking to nominate - the one
    // audience it existed for.
    if (!$includeDrafts) {
        $sql .= " WHERE status IN ('nominations','nominations_closed','roll_published',
                                   'open','closed','published')";
    }
    $sql .= ' ORDER BY opens_at DESC';
    $stmt = $pdo->query($sql);
    return $stmt ? array_map('electionShape', $stmt->fetchAll()) : [];
}

function electionFind(PDO $pdo, string $key): ?array
{
    if (!electionsEnsureTables($pdo)) return null;
    $stmt = $pdo->prepare('SELECT * FROM elections WHERE '
                          . (ctype_digit($key) ? 'id = ?' : 'slug = ?') . ' LIMIT 1');
    $stmt->execute([$key]);
    $row = $stmt->fetch();
    return $row ? electionShape($row) : null;
}

/**
 * Posts and candidates, in ballot order.
 *
 * Withdrawn and disqualified candidates are included with their status so an administrator
 * can see them, and filtered out by the ballot builder rather than here - the same query
 * serves both, and only one of them should be deciding who can be voted for.
 *
 * @return list<array<string,mixed>>
 */
function electionPositions(PDO $pdo, int $electionId): array
{
    $stmt = $pdo->prepare('SELECT * FROM election_positions WHERE election_id = ?
                           ORDER BY position, id');
    $stmt->execute([$electionId]);
    $positions = $stmt->fetchAll();
    if (!$positions) return [];

    $ids = array_map(fn($p) => (int)$p['id'], $positions);
    $stmt = $pdo->prepare('SELECT * FROM election_candidates WHERE position_id IN ('
                          . implode(',', array_fill(0, count($ids), '?')) . ')
                           ORDER BY ballot_order, id');
    $stmt->execute($ids);

    $byPosition = [];
    foreach ($stmt->fetchAll() as $c) {
        $byPosition[(int)$c['position_id']][] = [
            'id'        => (int)$c['id'],
            'name'      => $c['name'],
            'membershipId' => $c['membership_id'],
            'headline'  => $c['headline'],
            'manifesto' => $c['manifesto'],
            'photo'     => $c['photo'],
            'status'    => $c['status'],
            'standing'  => $c['status'] === 'approved',
        ];
    }
    return array_map(fn($p) => [
        'id'           => (int)$p['id'],
        'title'        => $p['title'],
        'description'  => $p['description'],
        'seats'        => (int)$p['seats'],
        'maxChoices'   => (int)$p['max_choices'],
        'allowAbstain' => (bool)(int)$p['allow_abstain'],
        'position'     => (int)$p['position'],
        'candidates'   => $byPosition[(int)$p['id']] ?? [],
    ], $positions);
}

/**
 * Draws the electoral roll and freezes it.
 *
 * Eligibility is "paid for $paidYear", which is the rule ReSoK uses, and the year is recorded
 * on every row rather than assumed - so a roll drawn under one rule can still be read years
 * later by somebody who does not know what the rule was.
 *
 * Refuses to redraw a roll for an election that has ever opened. Redrawing after voting has
 * begun would either strand ballots belonging to removed voters or admit people who could not
 * have voted in the part of the election that already happened.
 *
 * @return array{0:?array,1:?string}
 */
function electionDrawRoll(PDO $pdo, int $electionId, int $paidYear): array
{
    if (!electionsEnsureTables($pdo)) return [null, 'The election tables are not available.'];
    $election = electionFind($pdo, (string)$electionId);
    if (!$election) return [null, 'That election no longer exists.'];
    if ($election['locked']) {
        return [null, 'Voting has already opened. The roll for this election is final.'];
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM election_roll WHERE election_id = ?')->execute([$electionId]);

        // Only members who can actually sign in are put on the roll. An entry for somebody
        // with no way into the portal is not an entitlement to vote, it is a turnout figure
        // that can never be reached and a denominator that makes the result look worse than
        // it is.
        $stmt = $pdo->prepare(
            "INSERT INTO election_roll
               (election_id, member_profile_id, user_id, name, membership_id, email,
                standing_at_cutoff)
             SELECT ?, mp.id, u.id,
                    TRIM(CONCAT_WS(' ', NULLIF(mp.title,''), NULLIF(mp.first_name,''),
                                        NULLIF(mp.surname,''))),
                    mp.membership_id, u.email, ?
             FROM member_profiles mp
             JOIN users u ON u.id = mp.user_id
             WHERE EXISTS (SELECT 1 FROM member_payment_years y
                            WHERE y.member_profile_id = mp.id AND y.year = ?)
               AND u.email_verified = 1"
        );
        $stmt->execute([$electionId, 'paid ' . $paidYear, $paidYear]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        return [null, 'The roll was not drawn: ' . $e->getMessage()];
    }
    return [electionRollSummary($pdo, $electionId, $paidYear), null];
}

/**
 * Who is on the roll, who is missing, and why.
 *
 * The excluded counts matter more than the included one. A roll of 40 out of 89 is not a
 * roll, it is a warning, and the reason each person is off it is what somebody can act on
 * before voting opens.
 *
 * @return array<string,mixed>
 */
function electionRollSummary(PDO $pdo, int $electionId, ?int $paidYear = null): array
{
    $out = ['onRoll' => 0, 'voted' => 0, 'turnout' => 0.0,
            'eligibleButCannotSignIn' => 0, 'noEmail' => 0, 'notPaid' => 0];
    if (!electionsEnsureTables($pdo)) return $out;

    $stmt = $pdo->prepare('SELECT COUNT(*) AS n, COUNT(voted_at) AS v
                           FROM election_roll WHERE election_id = ?');
    $stmt->execute([$electionId]);
    $row = $stmt->fetch() ?: [];
    $out['onRoll'] = (int)($row['n'] ?? 0);
    $out['voted']  = (int)($row['v'] ?? 0);
    $out['turnout'] = $out['onRoll'] > 0 ? round($out['voted'] / $out['onRoll'] * 100, 1) : 0.0;

    if ($paidYear !== null) {
        $stmt = $pdo->prepare(
            "SELECT
               SUM(CASE WHEN paid = 1 AND u.email_verified = 0 THEN 1 ELSE 0 END) AS cannot_sign_in,
               SUM(CASE WHEN paid = 1 AND (u.email IS NULL OR u.email = '') THEN 1 ELSE 0 END) AS no_email,
               SUM(CASE WHEN paid = 0 THEN 1 ELSE 0 END) AS not_paid
             FROM (
               SELECT mp.id, mp.user_id,
                      EXISTS (SELECT 1 FROM member_payment_years y
                               WHERE y.member_profile_id = mp.id AND y.year = ?) AS paid
               FROM member_profiles mp
             ) t JOIN users u ON u.id = t.user_id"
        );
        $stmt->execute([$paidYear]);
        $row = $stmt->fetch() ?: [];
        $out['eligibleButCannotSignIn'] = (int)($row['cannot_sign_in'] ?? 0);
        $out['noEmail'] = (int)($row['no_email'] ?? 0);
        $out['notPaid'] = (int)($row['not_paid'] ?? 0);
    }
    return $out;
}

/** One voter's roll entry for an election, or null if they are not on it. */
function electionRollEntry(PDO $pdo, int $electionId, int $userId): ?array
{
    if (!electionsEnsureTables($pdo)) return null;
    $stmt = $pdo->prepare('SELECT * FROM election_roll WHERE election_id = ? AND user_id = ? LIMIT 1');
    $stmt->execute([$electionId, $userId]);
    $row = $stmt->fetch();
    if (!$row) return null;
    return [
        'id'      => (int)$row['id'],
        'name'    => $row['name'],
        'membershipId' => $row['membership_id'],
        'voted'   => !empty($row['voted_at']),
        'votedAt' => $row['voted_at'],
    ];
}

/**
 * The ballot paper one voter sees.
 *
 * Only approved candidates appear. A withdrawn name stays in the record of the election but
 * must not be markable, and filtering here rather than in the query means the administrative
 * view and the ballot cannot drift apart.
 *
 * @return array{0:?array,1:?string}
 */
function electionBallotFor(PDO $pdo, string $key, int $userId): array
{
    $election = electionFind($pdo, $key);
    if (!$election) return [null, 'No such election.'];

    $entry = electionRollEntry($pdo, $election['id'], $userId);
    if (!$entry) {
        return [null, 'You are not on the electoral roll for this election. '
                    . 'If you believe that is wrong, contact the ReSoK office before voting closes.'];
    }

    $positions = array_map(function ($p) {
        $p['candidates'] = array_values(array_filter($p['candidates'], fn($c) => $c['standing']));
        return $p;
    }, electionPositions($pdo, $election['id']));

    return [[
        'election'  => $election,
        'voter'     => $entry,
        'positions' => $positions,
    ], null];
}

/**
 * Casts a ballot.
 *
 * Everything is checked before anything is written, and everything is written in one
 * transaction. The mark on the roll goes in the same transaction as the votes, so "has
 * voted" and "their votes exist" can never disagree.
 *
 * @param array<int,mixed> $choices position id => candidate id, list of ids, or 'abstain'
 * @return array{0:?array,1:?string}
 */
function electionCastVote(PDO $pdo, string $key, int $userId, array $choices, ?string $clientHash): array
{
    if (!electionsEnsureTables($pdo)) return [null, 'The election tables are not available.'];

    $election = electionFind($pdo, $key);
    if (!$election) return [null, 'No such election.'];
    if (!$election['votingOpen']) {
        $now = new DateTimeImmutable('now');
        $opens = new DateTimeImmutable((string)$election['opensAt']);
        if ($election['status'] !== 'open') return [null, 'Voting is not open for this election.'];
        return [null, $now < $opens
            ? 'Voting has not opened yet. It opens on ' . $opens->format('j F Y \a\t H:i') . '.'
            : 'Voting has closed.'];
    }

    $entry = electionRollEntry($pdo, $election['id'], $userId);
    if (!$entry) return [null, 'You are not on the electoral roll for this election.'];
    if ($entry['voted']) {
        return [null, 'Your ballot has already been recorded. A vote cannot be changed once cast.'];
    }

    $positions = electionPositions($pdo, $election['id']);
    if (!$positions) return [null, 'This election has no posts to vote on.'];

    // Validate the whole ballot first. A voter told about their third mistake only after the
    // first two were accepted has been given a broken form, not a ballot paper.
    $writes = [];
    foreach ($positions as $position) {
        $given = $choices[$position['id']] ?? null;
        $abstain = ($given === 'abstain' || $given === ['abstain']);
        $ids = [];

        if (!$abstain && $given !== null && $given !== '' && $given !== []) {
            $ids = array_values(array_unique(array_map('intval', (array)$given)));
        }

        if (!$ids && !$abstain) {
            if (!$position['allowAbstain']) {
                return [null, 'Choose a candidate for ' . $position['title'] . '.'];
            }
            $abstain = true;
        }
        if ($ids && count($ids) > $position['maxChoices']) {
            return [null, $position['title'] . ': choose at most ' . $position['maxChoices']
                        . ' candidate' . ($position['maxChoices'] === 1 ? '' : 's') . '.'];
        }

        $standing = [];
        foreach ($position['candidates'] as $candidate) {
            if ($candidate['standing']) $standing[$candidate['id']] = true;
        }
        foreach ($ids as $id) {
            if (!isset($standing[$id])) {
                return [null, 'One of the candidates chosen for ' . $position['title']
                            . ' is not standing. Reload the ballot and try again.'];
            }
        }

        if ($abstain) {
            $writes[] = [$position['id'], null, 1];
        } else {
            foreach ($ids as $id) $writes[] = [$position['id'], $id, 0];
        }
    }

    $pdo->beginTransaction();
    try {
        // Re-read the roll row inside the transaction and lock it. Two ballots submitted at
        // once - a double-clicked button, a retried request - would otherwise both pass the
        // check above and both be written.
        $stmt = $pdo->prepare('SELECT voted_at FROM election_roll WHERE id = ? FOR UPDATE');
        $stmt->execute([$entry['id']]);
        $current = $stmt->fetch();
        if (!$current || !empty($current['voted_at'])) {
            $pdo->rollBack();
            return [null, 'Your ballot has already been recorded.'];
        }

        $insert = $pdo->prepare('INSERT INTO election_ballots
                                  (election_id, position_id, roll_id, candidate_id, is_abstain,
                                   cast_at, client_hash)
                                 VALUES (?, ?, ?, ?, ?, NOW(), ?)');
        foreach ($writes as [$positionId, $candidateId, $isAbstain]) {
            $insert->execute([$election['id'], $positionId, $entry['id'],
                              $candidateId, $isAbstain, $clientHash]);
        }
        $pdo->prepare("UPDATE election_roll SET voted_at = NOW(), voted_via = 'portal' WHERE id = ?")
            ->execute([$entry['id']]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        return [null, 'Your ballot was not recorded, and nothing was saved: ' . $e->getMessage()];
    }

    return [[
        'recorded' => true,
        'votedAt'  => (new DateTimeImmutable('now'))->format('Y-m-d H:i:s'),
        'election'  => $election['title'],
    ], null];
}

/**
 * The count.
 *
 * Computed from the ballots, and deliberately not exposed while voting is open: a running
 * tally that candidates or their supporters can see changes how the remaining votes are
 * campaigned for, and there is no way to un-publish a number somebody has already read.
 *
 * @return array{0:?array,1:?string}
 */
function electionTally(PDO $pdo, int $electionId): array
{
    if (!electionsEnsureTables($pdo)) return [null, 'The election tables are not available.'];
    $election = electionFind($pdo, (string)$electionId);
    if (!$election) return [null, 'No such election.'];
    if (in_array($election['status'], ['draft', 'roll_published', 'open'], true)) {
        return [null, 'The count is not available until voting has closed.'];
    }

    $stmt = $pdo->prepare('SELECT position_id, candidate_id, is_abstain, COUNT(*) AS votes
                           FROM election_ballots WHERE election_id = ?
                           GROUP BY position_id, candidate_id, is_abstain');
    $stmt->execute([$electionId]);
    $counts = [];
    foreach ($stmt->fetchAll() as $row) {
        $counts[(int)$row['position_id']][$row['is_abstain'] ? 'abstain' : (int)$row['candidate_id']]
            = (int)$row['votes'];
    }

    $out = [];
    foreach (electionPositions($pdo, $electionId) as $position) {
        $rows = [];
        foreach ($position['candidates'] as $candidate) {
            $rows[] = [
                'candidateId' => $candidate['id'],
                'name'        => $candidate['name'],
                'status'      => $candidate['status'],
                'votes'       => $counts[$position['id']][$candidate['id']] ?? 0,
            ];
        }
        // Sorted by votes, but who is elected is not decided here - a tie has to be seen by
        // a person, and silently electing whichever row sorted first would hide it.
        usort($rows, fn($a, $b) => $b['votes'] <=> $a['votes'] ?: strcmp($a['name'], $b['name']));

        $abstentions = $counts[$position['id']]['abstain'] ?? 0;
        // Judged on the candidates who were standing, for the same reason: a withdrawn name
        // sitting on zero votes must not be able to create a tie, or mask one.
        $contenders = array_values(array_filter($rows, fn($r) => $r['status'] === 'approved'));
        $tie = count($contenders) > $position['seats']
               && $contenders[$position['seats'] - 1]['votes'] === $contenders[$position['seats']]['votes']
               && $contenders[$position['seats']]['votes'] > 0;

        $out[] = [
            'positionId'  => $position['id'],
            'title'       => $position['title'],
            'seats'       => $position['seats'],
            'candidates'  => $rows,
            'abstentions' => $abstentions,
            'ballotsCast' => array_sum(array_values($counts[$position['id']] ?? [])),
            // Reported rather than resolved. A tie for the last seat is a decision for the
            // returning officer under the society's rules, not for a sort order.
            'tieForLastSeat' => $tie,
        ];
    }
    return [['election' => $election, 'positions' => $out,
             'roll' => electionRollSummary($pdo, $electionId)], null];
}

/* ======================================================================================= */
/* Administration                                                                           */
/*                                                                                          */
/* Every function here refuses on a locked election. The lock is opened_at, not status:      */
/* a status can be moved back by whoever set it, a timestamp recording that voting has       */
/* actually happened cannot be un-happened.                                                  */
/* ======================================================================================= */

/** @return array{0:?array,1:?string} */
function electionRequireUnlocked(PDO $pdo, int $electionId): array
{
    $election = electionFind($pdo, (string)$electionId);
    if (!$election) return [null, 'That election no longer exists.'];
    if ($election['locked']) {
        return [null, 'Voting has opened for this election. Nothing about it can be changed now.'];
    }
    return [$election, null];
}

function electionSlug(PDO $pdo, string $title): string
{
    $base = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($title)) ?? '', '-') ?: 'election';
    $base = substr($base, 0, 80);
    $slug = $base;
    for ($n = 2; $n < 200; $n++) {
        $stmt = $pdo->prepare('SELECT id FROM elections WHERE slug = ? LIMIT 1');
        $stmt->execute([$slug]);
        if (!$stmt->fetch()) return $slug;
        $slug = substr($base, 0, 76) . '-' . $n;
    }
    return $base . '-' . bin2hex(random_bytes(3));
}

/**
 * @param array<string,mixed> $data
 * @return array{0:?array,1:?string}
 */
function electionCreate(PDO $pdo, array $data, ?int $adminUserId): array
{
    if (!electionsEnsureTables($pdo)) return [null, 'The election tables are not available.'];

    $title = trim((string)($data['title'] ?? ''));
    if ($title === '') return [null, 'An election needs a title.'];

    $opens = trim((string)($data['opensAt'] ?? ''));
    $closes = trim((string)($data['closesAt'] ?? ''));
    $cutoff = trim((string)($data['eligibilityCutoff'] ?? ''));
    foreach ([[$opens, 'opening'], [$closes, 'closing']] as [$value, $what]) {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}([ T]\d{2}:\d{2})?/', $value)) {
            return [null, 'Give a valid ' . $what . ' date and time.'];
        }
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $cutoff)) {
        return [null, 'Give the date membership is judged on.'];
    }
    if (strtotime(str_replace('T', ' ', $closes)) <= strtotime(str_replace('T', ' ', $opens))) {
        return [null, 'Voting must close after it opens.'];
    }

    // The nomination window. Optional, because an officer entering a slate agreed elsewhere
    // is a legitimate way to run a small election - but where it is given, it has to finish
    // before voting starts. Nominations still open once the ballot is live would mean names
    // arriving for a paper people are already marking.
    $nomOpens = trim((string)($data['nominationsOpenAt'] ?? ''));
    $nomCloses = trim((string)($data['nominationsCloseAt'] ?? ''));
    if (($nomOpens === '') !== ($nomCloses === '')) {
        return [null, 'Give both nomination dates, or neither.'];
    }
    if ($nomOpens !== '') {
        foreach ([[$nomOpens, 'nominations opening'], [$nomCloses, 'nominations closing']] as [$value, $what]) {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}([ T]\d{2}:\d{2})?/', $value)) {
                return [null, 'Give a valid ' . $what . ' date and time.'];
            }
        }
        if (strtotime(str_replace('T', ' ', $nomCloses)) <= strtotime(str_replace('T', ' ', $nomOpens))) {
            return [null, 'Nominations must close after they open.'];
        }
        if (strtotime(str_replace('T', ' ', $nomCloses)) > strtotime(str_replace('T', ' ', $opens))) {
            return [null, 'Nominations must close before voting opens.'];
        }
    }

    $pdo->prepare('INSERT INTO elections
                    (slug, title, description, eligibility_cutoff,
                     nominations_open_at, nominations_close_at, opens_at, closes_at,
                     status, created_by)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([
            electionSlug($pdo, $title), mb_substr($title, 0, 200),
            trim((string)($data['description'] ?? '')) ?: null,
            $cutoff,
            $nomOpens !== '' ? str_replace('T', ' ', $nomOpens) : null,
            $nomCloses !== '' ? str_replace('T', ' ', $nomCloses) : null,
            str_replace('T', ' ', $opens), str_replace('T', ' ', $closes),
            // Always a draft. An election is opened by a deliberate act, never as a side
            // effect of creating it.
            'draft',
            $adminUserId,
        ]);
    return [electionFind($pdo, (string)$pdo->lastInsertId()), null];
}

/** @return array{0:?array,1:?string} */
function electionPositionSave(PDO $pdo, int $electionId, array $data, ?int $positionId = null): array
{
    [$election, $error] = electionRequireUnlocked($pdo, $electionId);
    if ($error) return [null, $error];

    $title = trim((string)($data['title'] ?? ''));
    if ($title === '') return [null, 'A post needs a title.'];

    $seats = max(1, min(50, (int)($data['seats'] ?? 1)));
    $maxChoices = max(1, min(50, (int)($data['maxChoices'] ?? $seats)));
    if ($maxChoices > $seats) {
        // Allowing more marks than seats produces a ballot nobody can count fairly: two
        // voters could each mark three names for one seat and the winner is decided by who
        // spread their votes more thinly.
        return [null, 'A voter cannot be given more choices than there are seats.'];
    }
    $allowAbstain = array_key_exists('allowAbstain', $data) ? (int)(bool)$data['allowAbstain'] : 1;
    $summary = mb_substr(trim((string)($data['description'] ?? '')), 0, 500) ?: null;

    if ($positionId) {
        $pdo->prepare('UPDATE election_positions
                       SET title = ?, description = ?, seats = ?, max_choices = ?, allow_abstain = ?
                       WHERE id = ? AND election_id = ?')
            ->execute([mb_substr($title, 0, 160), $summary, $seats, $maxChoices,
                       $allowAbstain, $positionId, $electionId]);
    } else {
        $stmt = $pdo->prepare('SELECT COALESCE(MAX(position), 0) + 1 AS next
                               FROM election_positions WHERE election_id = ?');
        $stmt->execute([$electionId]);
        $next = (int)($stmt->fetch()['next'] ?? 1);
        $pdo->prepare('INSERT INTO election_positions
                        (election_id, title, description, seats, max_choices, allow_abstain, position)
                       VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([$electionId, mb_substr($title, 0, 160), $summary, $seats,
                       $maxChoices, $allowAbstain, $next]);
    }
    return [electionPositions($pdo, $electionId), null];
}

/**
 * Adds or updates a candidate.
 *
 * A candidate may be linked to a member, and where they are, the link is checked: somebody
 * who is not a member in good standing should not appear on a ballot for a members' board.
 * The name is still stored here, because the ballot must not change if the member record is
 * later edited.
 *
 * @return array{0:?array,1:?string}
 */
function electionCandidateSave(PDO $pdo, int $positionId, array $data, ?int $candidateId = null): array
{
    if (!electionsEnsureTables($pdo)) return [null, 'The election tables are not available.'];

    $stmt = $pdo->prepare('SELECT election_id FROM election_positions WHERE id = ? LIMIT 1');
    $stmt->execute([$positionId]);
    $row = $stmt->fetch();
    if (!$row) return [null, 'That post does not exist.'];
    $electionId = (int)$row['election_id'];

    [$election, $error] = electionRequireUnlocked($pdo, $electionId);
    if ($error) return [null, $error];

    $name = trim((string)($data['name'] ?? ''));
    $memberId = isset($data['memberProfileId']) && $data['memberProfileId'] !== ''
        ? (int)$data['memberProfileId'] : null;

    if ($memberId) {
        $stmt = $pdo->prepare('SELECT mp.id, mp.membership_status, mp.renewal_due, mp.membership_id,
                                      TRIM(CONCAT_WS(" ", NULLIF(mp.title,""), NULLIF(mp.first_name,""),
                                                          NULLIF(mp.surname,""))) AS full_name
                               FROM member_profiles mp WHERE mp.id = ? LIMIT 1');
        $stmt->execute([$memberId]);
        $member = $stmt->fetch();
        if (!$member) return [null, 'That member record does not exist.'];
        if (!membershipHasBenefits($member)) {
            return [null, 'That member is not in good standing and cannot stand for election.'];
        }
        if ($name === '') $name = (string)$member['full_name'];
        if (empty($data['membershipId'])) $data['membershipId'] = $member['membership_id'];
    }

    if ($name === '') return [null, 'A candidate needs a name.'];

    $status = in_array((string)($data['status'] ?? ''), ELECTION_CANDIDATE_STATUSES, true)
        ? (string)$data['status'] : 'nominated';

    $fields = [
        mb_substr($name, 0, 160),
        $memberId,
        mb_substr(trim((string)($data['membershipId'] ?? '')), 0, 80) ?: null,
        mb_substr(trim((string)($data['headline'] ?? '')), 0, 255) ?: null,
        trim((string)($data['manifesto'] ?? '')) ?: null,
        mb_substr(trim((string)($data['photo'] ?? '')), 0, 255) ?: null,
        $status,
        mb_substr(trim((string)($data['withdrawnReason'] ?? '')), 0, 255) ?: null,
    ];

    if ($candidateId) {
        $pdo->prepare('UPDATE election_candidates
                       SET name = ?, member_profile_id = ?, membership_id = ?, headline = ?,
                           manifesto = ?, photo = ?, status = ?, withdrawn_reason = ?
                       WHERE id = ? AND position_id = ?')
            ->execute(array_merge($fields, [$candidateId, $positionId]));
    } else {
        $stmt = $pdo->prepare('SELECT COALESCE(MAX(ballot_order), 0) + 1 AS next
                               FROM election_candidates WHERE position_id = ?');
        $stmt->execute([$positionId]);
        $next = (int)($stmt->fetch()['next'] ?? 1);
        $pdo->prepare('INSERT INTO election_candidates
                        (position_id, name, member_profile_id, membership_id, headline,
                         manifesto, photo, status, withdrawn_reason, ballot_order)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute(array_merge([$positionId], $fields, [$next]));
    }
    return [electionPositions($pdo, $electionId), null];
}

/**
 * Shuffles candidates into a random ballot order.
 *
 * Being first on a ballot paper is worth votes, and alphabetical order hands that advantage
 * to the same surnames every time. Randomised once, before opening, and then frozen with
 * everything else.
 *
 * @return array{0:?array,1:?string}
 */
function electionRandomiseBallot(PDO $pdo, int $electionId): array
{
    [$election, $error] = electionRequireUnlocked($pdo, $electionId);
    if ($error) return [null, $error];

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT c.id, c.position_id FROM election_candidates c
                               JOIN election_positions p ON p.id = c.position_id
                               WHERE p.election_id = ?');
        $stmt->execute([$electionId]);
        $byPosition = [];
        foreach ($stmt->fetchAll() as $row) $byPosition[(int)$row['position_id']][] = (int)$row['id'];

        $update = $pdo->prepare('UPDATE election_candidates SET ballot_order = ? WHERE id = ?');
        foreach ($byPosition as $ids) {
            shuffle($ids);
            foreach ($ids as $i => $id) $update->execute([$i + 1, $id]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        return [null, 'The ballot order was not changed: ' . $e->getMessage()];
    }
    return [electionPositions($pdo, $electionId), null];
}

/**
 * Moves an election through its states.
 *
 * Opening is the one that matters, and it is refused unless the election is actually ready:
 * a roll with people on it, at least one post, and every post with enough approved candidates
 * to fill its seats. An election opened with an empty post produces a ballot that cannot be
 * completed and a result that cannot be declared.
 *
 * @return array{0:?array,1:?string}
 */
function electionSetStatus(PDO $pdo, int $electionId, string $status): array
{
    if (!in_array($status, ELECTION_STATUSES, true)) return [null, 'Unknown status.'];
    $election = electionFind($pdo, (string)$electionId);
    if (!$election) return [null, 'That election no longer exists.'];

    if ($status === 'nominations') {
        if ($election['locked']) return [null, 'This election has already been opened once.'];
        if (!electionPositions($pdo, $electionId)) {
            return [null, 'Add the posts being contested before opening nominations.'];
        }
        if (!$election['nominationsOpenAt'] || !$election['nominationsCloseAt']) {
            return [null, 'Set the nomination opening and closing dates first.'];
        }
        // The roll decides who may nominate as well as who may vote, so it has to exist
        // before nominations open, not just before voting does.
        if (electionRollSummary($pdo, $electionId)['onRoll'] < 1) {
            return [null, 'Draw the electoral roll first - it decides who may nominate.'];
        }
        $pdo->prepare("UPDATE elections SET status = 'nominations' WHERE id = ?")->execute([$electionId]);

    } elseif ($status === 'nominations_closed') {
        if ($election['status'] !== 'nominations') {
            return [null, 'Only an election in its nomination phase can have nominations closed.'];
        }
        $pdo->prepare("UPDATE elections SET status = 'nominations_closed' WHERE id = ?")
            ->execute([$electionId]);

    } elseif ($status === 'open') {
        if ($election['locked']) return [null, 'This election has already been opened once.'];

        // Voting cannot start while names can still be added to the ballot.
        if ($election['status'] === 'nominations') {
            return [null, 'Close nominations before opening the vote.'];
        }

        $roll = electionRollSummary($pdo, $electionId);
        if ($roll['onRoll'] < 1) {
            return [null, 'The electoral roll is empty. Draw the roll before opening voting.'];
        }
        $positions = electionPositions($pdo, $electionId);
        if (!$positions) return [null, 'Add at least one post before opening voting.'];
        foreach ($positions as $position) {
            $approved = count(array_filter($position['candidates'], fn($c) => $c['standing']));
            if ($approved < 1) {
                return [null, $position['title'] . ' has no approved candidates.'];
            }
            if ($approved < $position['seats']) {
                return [null, $position['title'] . ' has ' . $approved . ' candidate(s) for '
                            . $position['seats'] . ' seats. Reduce the seats or approve more candidates.'];
            }
        }
        $pdo->prepare("UPDATE elections SET status = 'open', opened_at = COALESCE(opened_at, NOW())
                       WHERE id = ?")->execute([$electionId]);
    } elseif ($status === 'closed') {
        if ($election['status'] !== 'open') return [null, 'Only an open election can be closed.'];
        $pdo->prepare("UPDATE elections SET status = 'closed', closed_at = NOW() WHERE id = ?")
            ->execute([$electionId]);
    } elseif ($status === 'published') {
        if ($election['status'] !== 'closed') {
            return [null, 'Close voting and declare the result before publishing it.'];
        }
        $stmt = $pdo->prepare('SELECT COUNT(*) AS n FROM election_results WHERE election_id = ?');
        $stmt->execute([$electionId]);
        if ((int)($stmt->fetch()['n'] ?? 0) < 1) {
            return [null, 'No result has been declared for this election yet.'];
        }
        $pdo->prepare("UPDATE elections SET status = 'published', results_published_at = NOW()
                       WHERE id = ?")->execute([$electionId]);
    } else {
        $pdo->prepare('UPDATE elections SET status = ? WHERE id = ?')->execute([$status, $electionId]);
    }
    return [electionFind($pdo, (string)$electionId), null];
}

/**
 * Writes the declared result.
 *
 * The count is taken once, at declaration, and stored. A tally recomputed on every read would
 * change after the fact - a candidate disqualified next week would silently rewrite last
 * week's declared result - so what was declared is what stands, and a recount is a new
 * declaration beside it rather than an edit of the old one.
 *
 * Who is elected is decided here from the seat count, except where the last seat is tied.
 * A tie is left undecided on purpose: the society's rules decide it, not a sort order.
 *
 * @return array{0:?array,1:?string}
 */
function electionDeclareResult(PDO $pdo, int $electionId, ?int $adminUserId, ?string $note): array
{
    [$tally, $error] = electionTally($pdo, $electionId);
    if ($error) return [null, $error];

    foreach ($tally['positions'] as $position) {
        if ($position['tieForLastSeat']) {
            return [null, $position['title'] . ' is tied for the last seat. '
                        . 'That has to be resolved under the society\'s rules before a result '
                        . 'can be declared.'];
        }
    }

    $stmt = $pdo->prepare('SELECT COALESCE(MAX(declaration), 0) + 1 AS next
                           FROM election_results WHERE election_id = ?');
    $stmt->execute([$electionId]);
    $declaration = (int)($stmt->fetch()['next'] ?? 1);

    $pdo->beginTransaction();
    try {
        $insert = $pdo->prepare('INSERT INTO election_results
                                  (election_id, position_id, candidate_id, candidate_name, votes,
                                   is_abstain, elected, declaration, declared_by, declared_at, note)
                                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)');
        foreach ($tally['positions'] as $position) {
            // Only candidates who were actually standing. A withdrawn name recorded with
            // zero votes reads as somebody who stood and was rejected, which is a different
            // and untrue statement - the fact that they were nominated and withdrew belongs
            // in election_candidates, where it already is.
            $standing = array_values(array_filter($position['candidates'],
                                                  fn($c) => $c['status'] === 'approved'));
            foreach ($standing as $rank => $candidate) {
                $elected = $rank < $position['seats'] && $candidate['votes'] > 0;
                $insert->execute([$electionId, $position['positionId'], $candidate['candidateId'],
                                  $candidate['name'], $candidate['votes'], 0, $elected ? 1 : 0,
                                  $declaration, $adminUserId, $note]);
            }
            if ($position['abstentions'] > 0) {
                $insert->execute([$electionId, $position['positionId'], null, 'Abstentions',
                                  $position['abstentions'], 1, 0, $declaration, $adminUserId, $note]);
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        return [null, 'The result was not declared: ' . $e->getMessage()];
    }
    return [electionResults($pdo, $electionId), null];
}

/**
 * The declared result, most recent declaration only.
 *
 * @return array<string,mixed>
 */
function electionResults(PDO $pdo, int $electionId): array
{
    if (!electionsEnsureTables($pdo)) return ['declaration' => 0, 'positions' => []];

    $stmt = $pdo->prepare('SELECT MAX(declaration) AS latest FROM election_results WHERE election_id = ?');
    $stmt->execute([$electionId]);
    $latest = (int)($stmt->fetch()['latest'] ?? 0);
    if (!$latest) return ['declaration' => 0, 'positions' => []];

    $stmt = $pdo->prepare('SELECT r.*, p.title AS position_title, p.seats
                           FROM election_results r
                           JOIN election_positions p ON p.id = r.position_id
                           WHERE r.election_id = ? AND r.declaration = ?
                           ORDER BY p.position, r.is_abstain, r.votes DESC, r.candidate_name');
    $stmt->execute([$electionId, $latest]);

    $positions = [];
    foreach ($stmt->fetchAll() as $row) {
        $pid = (int)$row['position_id'];
        if (!isset($positions[$pid])) {
            $positions[$pid] = ['positionId' => $pid, 'title' => $row['position_title'],
                                'seats' => (int)$row['seats'], 'declaredAt' => $row['declared_at'],
                                'note' => $row['note'], 'candidates' => [], 'abstentions' => 0];
        }
        if ($row['is_abstain']) {
            $positions[$pid]['abstentions'] = (int)$row['votes'];
            continue;
        }
        $positions[$pid]['candidates'][] = [
            'candidateId' => $row['candidate_id'] === null ? null : (int)$row['candidate_id'],
            'name'    => $row['candidate_name'],
            'votes'   => (int)$row['votes'],
            'elected' => (bool)(int)$row['elected'],
        ];
    }
    return ['declaration' => $latest, 'positions' => array_values($positions)];
}

/* ======================================================================================= */
/* Nominations                                                                              */
/*                                                                                          */
/* The first half of an election. Members put names forward during a window, the returning   */
/* officer approves or rejects each one, and voting then opens on the approved list.         */
/* ======================================================================================= */

/**
 * A member nominates somebody for a post.
 *
 * Four things are checked, and the reasons matter more than the rules.
 *
 * The nominator must be on the roll: the electorate nominates its own board, and somebody
 * who cannot vote in an election should not be able to shape its ballot paper either.
 *
 * The nominee must be a member in good standing. A board seat carries obligations that a
 * lapsed member has not kept up, and finding that out after they are elected is worse.
 *
 * One nomination per member per post, which the unique key enforces. Without it a single
 * member could fill a ballot with names and the officer's list becomes a moderation queue
 * rather than a record of what the membership proposed.
 *
 * A nomination of somebody else is not an acceptance. Standing for a board is not something
 * that should happen to a person without their agreement, so a name put forward by another
 * member waits at 'nominated' until they accept; nominating yourself accepts at the same
 * moment, because there is nobody else to ask.
 *
 * @return array{0:?array,1:?string}
 */
function electionNominate(PDO $pdo, string $key, int $userId, array $data): array
{
    if (!electionsEnsureTables($pdo)) return [null, 'The election tables are not available.'];

    $election = electionFind($pdo, $key);
    if (!$election) return [null, 'No such election.'];

    if (!$election['nominationsOpen']) {
        if ($election['status'] !== 'nominations') {
            return [null, 'Nominations are not open for this election.'];
        }
        $now = new DateTimeImmutable('now');
        $opens = $election['nominationsOpenAt']
            ? new DateTimeImmutable((string)$election['nominationsOpenAt']) : null;
        return [null, ($opens && $now < $opens)
            ? 'Nominations open on ' . $opens->format('j F Y \a\t H:i') . '.'
            : 'Nominations have closed.'];
    }

    $nominator = electionRollEntry($pdo, (int)$election['id'], $userId);
    if (!$nominator) {
        return [null, 'Only members on the electoral roll may nominate candidates.'];
    }

    $positionId = (int)($data['positionId'] ?? 0);
    $stmt = $pdo->prepare('SELECT * FROM election_positions WHERE id = ? AND election_id = ? LIMIT 1');
    $stmt->execute([$positionId, (int)$election['id']]);
    $position = $stmt->fetch();
    if (!$position) return [null, 'Choose a post to nominate for.'];

    // Two kinds of nominee. A member is identified by their record, and everything about them
    // is read from it. Somebody outside the membership is described by the nominator, because
    // there is no record to read - and their details stay on this row rather than becoming a
    // member_profiles entry, since a nomination is not an application to join and one should
    // not quietly turn into the other.
    $memberId = (int)($data['memberProfileId'] ?? 0);
    $name = null;
    $membershipId = null;
    $email = null;
    $phone = null;
    $organisation = null;
    $isSelf = false;
    $nomineeUserId = null;

    if ($memberId > 0) {
        $stmt = $pdo->prepare('SELECT mp.id, mp.membership_status, mp.renewal_due, mp.membership_id,
                                      mp.user_id,
                                      TRIM(CONCAT_WS(" ", NULLIF(mp.title,""), NULLIF(mp.first_name,""),
                                                          NULLIF(mp.surname,""))) AS full_name
                               FROM member_profiles mp WHERE mp.id = ? LIMIT 1');
        $stmt->execute([$memberId]);
        $nominee = $stmt->fetch();
        if (!$nominee) return [null, 'That member is not in the register.'];
        if (!membershipHasBenefits($nominee)) {
            return [null, 'That member is not in good standing and cannot be nominated.'];
        }
        $name = (string)$nominee['full_name'];
        $membershipId = $nominee['membership_id'];
        $nomineeUserId = (int)$nominee['user_id'];
        $isSelf = $nomineeUserId === $userId;
    } else {
        $memberId = null;
        $name = trim((string)($data['name'] ?? ''));
        $email = strtolower(trim((string)($data['email'] ?? '')));
        $phone = trim((string)($data['phone'] ?? ''));
        $organisation = trim((string)($data['organisation'] ?? ''));

        // Full details are required for somebody outside the register, because there is
        // nothing else to identify them by and the officer has to be able to reach them to
        // confirm they are willing to stand.
        if ($name === '') return [null, 'Give the full name of the person you are nominating.'];
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return [null, 'Give a valid email address for the person you are nominating.'];
        }
        if ($phone === '') return [null, 'Give a telephone number for the person you are nominating.'];
        if ($organisation === '') {
            return [null, 'Give the institution or organisation they work with.'];
        }
    }

    // One entry per nominee per post. Matched on the member id where there is one and on the
    // name where there is not, because an external nominee has no id to compare.
    if ($memberId !== null) {
        $stmt = $pdo->prepare('SELECT id FROM election_candidates
                               WHERE position_id = ? AND member_profile_id = ? LIMIT 1');
        $stmt->execute([$positionId, $memberId]);
    } else {
        $stmt = $pdo->prepare('SELECT id FROM election_candidates
                               WHERE position_id = ? AND member_profile_id IS NULL
                                 AND LOWER(name) = ? LIMIT 1');
        $stmt->execute([$positionId, strtolower($name)]);
    }
    if ($stmt->fetch()) {
        return [null, $name . ' has already been nominated for ' . $position['title'] . '.'];
    }

    try {
        $pdo->prepare('INSERT INTO election_candidates
                        (position_id, member_profile_id, nominated_by_roll_id, nominated_at,
                         accepted_at, name, membership_id, email, phone, organisation,
                         headline, status, ballot_order)
                       VALUES (?, ?, ?, NOW(), ' . ($isSelf ? 'NOW()' : 'NULL') . ',
                               ?, ?, ?, ?, ?, ?, ?, 0)')
            ->execute([
                $positionId, $memberId, $nominator['id'],
                mb_substr($name, 0, 160), $membershipId,
                $email ?: null, $phone ?: null,
                $organisation ? mb_substr($organisation, 0, 190) : null,
                mb_substr(trim((string)($data['headline'] ?? '')), 0, 255) ?: null,
                'nominated',
            ]);
    } catch (Throwable $e) {
        // The unique key on (position_id, nominated_by_roll_id) is what lands here.
        return [null, 'You have already nominated somebody for ' . $position['title'] . '.'];
    }

    $external = $memberId === null;
    return [[
        'nominated' => true,
        'name'      => $name,
        'position'  => $position['title'],
        'external'  => $external,
        'accepted'  => $isSelf,
        'message'   => $isSelf
            ? 'You have been nominated for ' . $position['title']
              . '. The returning officer will confirm your candidacy.'
            : ($external
                ? $name . ' has been nominated for ' . $position['title']
                  . '. They are not a ReSoK member, so the returning officer will contact them '
                  . 'on the details you gave to confirm they are willing to stand.'
                : $name . ' has been nominated for ' . $position['title']
                  . '. They will be asked to accept before their name goes on the ballot.'),
    ], null];
}

/**
 * A nominee accepts or declines a nomination somebody else made for them.
 *
 * @return array{0:?array,1:?string}
 */
function electionRespondToNomination(PDO $pdo, int $candidateId, int $userId, bool $accept): array
{
    if (!electionsEnsureTables($pdo)) return [null, 'The election tables are not available.'];

    $stmt = $pdo->prepare('SELECT c.*, p.title AS position_title, p.election_id, mp.user_id
                           FROM election_candidates c
                           JOIN election_positions p ON p.id = c.position_id
                           LEFT JOIN member_profiles mp ON mp.id = c.member_profile_id
                           WHERE c.id = ? LIMIT 1');
    $stmt->execute([$candidateId]);
    $candidate = $stmt->fetch();
    if (!$candidate) return [null, 'That nomination no longer exists.'];
    if ((int)$candidate['user_id'] !== $userId) {
        return [null, 'That nomination is not yours to answer.'];
    }

    [$election, $error] = electionRequireUnlocked($pdo, (int)$candidate['election_id']);
    if ($error) return [null, $error];

    if ($accept) {
        $pdo->prepare('UPDATE election_candidates SET accepted_at = NOW() WHERE id = ?')
            ->execute([$candidateId]);
        return [['accepted' => true, 'position' => $candidate['position_title']], null];
    }
    // Declining is recorded, not deleted. That somebody was nominated and chose not to stand
    // is part of the history of the election.
    $pdo->prepare("UPDATE election_candidates
                   SET status = 'withdrawn', withdrawn_reason = 'Declined the nomination'
                   WHERE id = ?")->execute([$candidateId]);
    return [['accepted' => false, 'position' => $candidate['position_title']], null];
}

/**
 * Nominations awaiting the returning officer, and what is blocking each one.
 *
 * @return list<array<string,mixed>>
 */
function electionNominations(PDO $pdo, int $electionId, ?int $viewerUserId = null): array
{
    if (!electionsEnsureTables($pdo)) return [];
    // The nominee's own user id comes along so the page can tell which nominations belong to
    // whoever is reading. Accepting is the nominee's act alone, and a button offered to
    // somebody who cannot use it is a form that lies.
    $stmt = $pdo->prepare('SELECT c.*, p.title AS position_title,
                                  r.name AS nominated_by, mp.user_id AS nominee_user_id
                           FROM election_candidates c
                           JOIN election_positions p ON p.id = c.position_id
                           LEFT JOIN election_roll r ON r.id = c.nominated_by_roll_id
                           LEFT JOIN member_profiles mp ON mp.id = c.member_profile_id
                           WHERE p.election_id = ?
                           ORDER BY p.position, c.nominated_at, c.id');
    $stmt->execute([$electionId]);

    return array_map(fn($c) => [
        'id'           => (int)$c['id'],
        'positionId'   => (int)$c['position_id'],
        'position'     => $c['position_title'],
        'name'         => $c['name'],
        'membershipId' => $c['membership_id'],
        'headline'     => $c['headline'],
        'status'       => $c['status'],
        // Derived, never stored: a candidate with no member record is from outside the
        // membership. Two columns that must agree eventually disagree.
        'external'     => empty($c['member_profile_id']),
        'email'        => $c['email'] ?? null,
        'phone'        => $c['phone'] ?? null,
        'organisation' => $c['organisation'] ?? null,
        'acceptanceNote' => $c['acceptance_note'] ?? null,
        'nominatedBy'  => $c['nominated_by'],
        'nominatedAt'  => $c['nominated_at'],
        'accepted'     => !empty($c['accepted_at']),
        'acceptedAt'   => $c['accepted_at'],
        'mine'         => $viewerUserId !== null
                          && (int)($c['nominee_user_id'] ?? 0) === $viewerUserId,
        // What stops this nomination becoming a candidacy, so the officer sees the reason
        // rather than a name that will not approve and no explanation why.
        // A member accepts in the portal. Somebody from outside has no account to accept in,
        // so the officer has to reach them and record it - and the wording says which of
        // those two things is being waited on.
        'blocker'      => empty($c['accepted_at']) && $c['status'] === 'nominated'
            ? (empty($c['member_profile_id'])
                ? 'Contact them to confirm they will stand'
                : 'Waiting for the nominee to accept')
            : null,
    ], $stmt->fetchAll());
}

/**
 * Members who may be nominated, for the picker on the nomination form.
 *
 * The roll is the source rather than the whole register: only members of this electorate can
 * stand, and offering names that will be refused on submission is a form that lies.
 *
 * @return list<array<string,mixed>>
 */
function electionNominatableMembers(PDO $pdo, int $electionId): array
{
    if (!electionsEnsureTables($pdo)) return [];
    $stmt = $pdo->prepare('SELECT r.member_profile_id, r.name, r.membership_id
                           FROM election_roll r WHERE r.election_id = ?
                           ORDER BY r.name');
    $stmt->execute([$electionId]);
    return array_map(fn($r) => [
        'memberProfileId' => (int)$r['member_profile_id'],
        'name'            => $r['name'],
        'membershipId'    => $r['membership_id'],
    ], $stmt->fetchAll());
}

/**
 * Removes a post, or a candidate, while the election can still be edited.
 *
 * Guarded by the same lock as every other edit: once voting has opened nothing goes, because
 * deleting a post that people have already voted on would destroy their ballots and leave a
 * turnout figure that no longer reconciles with anything.
 *
 * Deleting a post takes its candidates and their nominations with it, which is right - they
 * exist only in relation to a post that is no longer being contested.
 *
 * @return array{0:?array,1:?string}
 */
function electionDelete(PDO $pdo, string $what, int $id): array
{
    if (!electionsEnsureTables($pdo)) return [null, 'The election tables are not available.'];

    if ($what === 'position') {
        $stmt = $pdo->prepare('SELECT election_id FROM election_positions WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) return [null, 'That post does not exist.'];
        $electionId = (int)$row['election_id'];
        [$election, $error] = electionRequireUnlocked($pdo, $electionId);
        if ($error) return [null, $error];
        $pdo->prepare('DELETE FROM election_positions WHERE id = ?')->execute([$id]);
    } elseif ($what === 'candidate') {
        $stmt = $pdo->prepare('SELECT p.election_id FROM election_candidates c
                               JOIN election_positions p ON p.id = c.position_id
                               WHERE c.id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) return [null, 'That candidate does not exist.'];
        $electionId = (int)$row['election_id'];
        [$election, $error] = electionRequireUnlocked($pdo, $electionId);
        if ($error) return [null, $error];
        $pdo->prepare('DELETE FROM election_candidates WHERE id = ?')->execute([$id]);
    } else {
        return [null, 'Unknown thing to delete.'];
    }
    return [electionPositions($pdo, $electionId), null];
}

/**
 * Records that an external nominee has agreed to stand.
 *
 * Somebody outside the membership has no portal account, so they cannot accept the way a
 * member does. The officer reaches them on the details the nominator gave and records the
 * answer here, along with how it was obtained - "the Chair spoke to them on 12 October" is
 * evidence, and "somebody said it was fine" is not.
 *
 * Refused for a member, who has an account and must accept for themselves. An officer
 * accepting on a member's behalf would put a name on a ballot without the one thing the
 * acceptance step exists to establish.
 *
 * @return array{0:?array,1:?string}
 */
function electionRecordExternalAcceptance(PDO $pdo, int $candidateId, ?int $adminUserId, string $note): array
{
    if (!electionsEnsureTables($pdo)) return [null, 'The election tables are not available.'];

    $stmt = $pdo->prepare('SELECT c.*, p.election_id FROM election_candidates c
                           JOIN election_positions p ON p.id = c.position_id
                           WHERE c.id = ? LIMIT 1');
    $stmt->execute([$candidateId]);
    $candidate = $stmt->fetch();
    if (!$candidate) return [null, 'That nomination no longer exists.'];

    if (!empty($candidate['member_profile_id'])) {
        return [null, 'That nominee is a ReSoK member and must accept in the portal themselves.'];
    }
    [$election, $error] = electionRequireUnlocked($pdo, (int)$candidate['election_id']);
    if ($error) return [null, $error];

    $note = trim($note);
    if ($note === '') {
        return [null, 'Record how their agreement was obtained - who spoke to them, and when.'];
    }
    if (!empty($candidate['accepted_at'])) {
        return [null, 'Their acceptance is already recorded.'];
    }

    $pdo->prepare('UPDATE election_candidates
                   SET accepted_at = NOW(), acceptance_note = ? WHERE id = ?')
        ->execute([mb_substr($note, 0, 255), $candidateId]);

    return [electionNominations($pdo, (int)$candidate['election_id']), null];
}
