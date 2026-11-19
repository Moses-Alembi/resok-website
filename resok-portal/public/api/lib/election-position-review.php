<?php
declare(strict_types=1);

/**
 * Staff nominees may be put forward for several posts but stand for only one.
 * All original candidacies and nomination records remain untouched. The selected
 * ballot is derived from a recorded choice, whose candidate snapshot locks at opening.
 * This module reads existing schema only; request-time DDL would break election locks.
 */

/** @return array{hash:string,json:string,pairs:list<array{candidateId:int,positionId:int}>} */
function electionStaffPositionCandidateSet(array $candidates): array
{
    $pairs = array_map(static fn(array $candidate): array => [
        'candidateId' => (int)$candidate['id'],
        'positionId' => (int)$candidate['position_id'],
    ], $candidates);
    usort($pairs, static fn(array $a, array $b): int =>
        $a['candidateId'] <=> $b['candidateId'] ?: $a['positionId'] <=> $b['positionId']);
    $json = json_encode($pairs, JSON_THROW_ON_ERROR);
    return ['hash' => hash('sha256', $json), 'json' => $json, 'pairs' => $pairs];
}

/** @return list<array{candidateId:int,positionId:int}>|null */
function electionStaffPositionSavedSet(array $choice): ?array
{
    try {
        $pairs = json_decode((string)$choice['candidate_set_json'], true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $error) {
        return null;
    }
    if (!is_array($pairs) || !$pairs || array_keys($pairs) !== range(0, count($pairs) - 1)) return null;
    $canonical = [];
    $seen = [];
    foreach ($pairs as $pair) {
        if (!is_array($pair) || !isset($pair['candidateId'], $pair['positionId'])
            || !is_int($pair['candidateId']) || !is_int($pair['positionId'])
            || $pair['candidateId'] < 1 || $pair['positionId'] < 1 || isset($seen[$pair['candidateId']])) return null;
        $seen[$pair['candidateId']] = true;
        $canonical[] = ['candidateId' => $pair['candidateId'], 'positionId' => $pair['positionId']];
    }
    usort($canonical, static fn(array $a, array $b): int =>
        $a['candidateId'] <=> $b['candidateId'] ?: $a['positionId'] <=> $b['positionId']);
    if (!in_array((int)$choice['position_id'], array_column($canonical, 'positionId'), true)) return null;
    return hash_equals((string)$choice['candidate_set_hash'], hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR)))
        ? $canonical : null;
}

/** @return array<string,mixed> */
function electionStaffPositionReview(PDO $pdo, int $electionId): array
{
    $review = [
        'available' => true, 'required' => false, 'electionId' => $electionId,
        'phase' => 'setup', 'canRecord' => false, 'reviewPeriodValid' => true,
        'ready' => true, 'conflicts' => [], 'blockedPosts' => [],
        'blockingReasons' => [], 'excludedCandidateIds' => [],
    ];
    $statement = $pdo->prepare('SELECT * FROM elections WHERE id = ?');
    $statement->execute([$electionId]);
    $election = $statement->fetch(PDO::FETCH_ASSOC);
    if (!$election) {
        $review['ready'] = false;
        $review['blockingReasons'][] = 'No such election.';
        return $review;
    }
    if (($election['electorate'] ?? 'members') !== 'staff') return $review;
    $review['required'] = true;
    $locked = !empty($election['opened_at']);
    $now = new DateTimeImmutable('now');
    $nominationClose = !empty($election['nominations_close_at'])
        ? new DateTimeImmutable((string)$election['nominations_close_at']) : null;
    $opens = !empty($election['opens_at']) ? new DateTimeImmutable((string)$election['opens_at']) : null;
    $closes = !empty($election['closes_at']) ? new DateTimeImmutable((string)$election['closes_at']) : null;
    $review['reviewPeriodValid'] = $nominationClose !== null && $opens !== null && $opens > $nominationClose;
    $review['phase'] = $locked ? 'locked'
        : ($election['status'] === 'nominations_closed' && $nominationClose !== null && $now >= $nominationClose
            ? 'review' : ($election['status'] === 'nominations' ? 'nominations' : 'setup'));

    $choices = [];
    try {
        $statement = $pdo->prepare('SELECT election_id, nominee_user_id, position_id, candidate_set_hash,
                                          candidate_set_json, confirmed_by, confirmed_at, note
                                     FROM election_staff_position_choices WHERE election_id = ?');
        $statement->execute([$electionId]);
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $choice) $choices[(int)$choice['nominee_user_id']] = $choice;
    } catch (PDOException $error) {
        if (!in_array((int)($error->errorInfo[1] ?? 0), [1146, 1054], true)) throw $error;
        $review['available'] = false;
    }
    try {
        $pdo->query('SELECT id, election_id, nominee_user_id, previous_position_id, new_position_id,
                            previous_candidate_set_hash, candidate_set_hash, previous_candidate_set_json,
                            candidate_set_json, actor_id, confirmation_note, created_at
                       FROM election_staff_position_choice_events LIMIT 0');
    } catch (PDOException $error) {
        if (!in_array((int)($error->errorInfo[1] ?? 0), [1146, 1054], true)) throw $error;
        $review['available'] = false;
    }
    $statement = $pdo->prepare('SELECT id, title, seats FROM election_positions WHERE election_id = ? ORDER BY position, id');
    $statement->execute([$electionId]);
    $posts = [];
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $position) $posts[(int)$position['id']] = $position;
    $statement = $pdo->prepare('SELECT c.id, c.position_id, c.nominee_user_id, c.name, c.status
                                 FROM election_candidates c
                                 JOIN election_positions p ON p.id = c.position_id
                                WHERE p.election_id = ? ORDER BY c.position_id, c.id');
    $statement->execute([$electionId]);
    $rawCandidates = $statement->fetchAll(PDO::FETCH_ASSOC);
    $statement = $pdo->prepare("SELECT user_id FROM election_roll WHERE election_id = ? AND standing_at_cutoff = 'staff'");
    $statement->execute([$electionId]);
    $eligibleUsers = array_fill_keys(array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN)), true);
    $approvedByUser = [];
    $candidateById = [];
    foreach ($rawCandidates as $candidate) {
        $candidateById[(int)$candidate['id']] = $candidate;
        if ($candidate['status'] !== 'approved') continue;
        $userId = (int)($candidate['nominee_user_id'] ?? 0);
        if ($userId < 1) {
            if (!$locked) $review['blockingReasons'][] = $candidate['name'] . ' has no linked staff account.';
            continue;
        }
        $approvedByUser[$userId][] = $candidate;
        if (!$locked && !isset($eligibleUsers[$userId])) {
            $review['blockingReasons'][] = $candidate['name'] . ' is not on the frozen staff voter roll.';
        }
    }

    $counts = function_exists('electionStaffNominationCounts') ? electionStaffNominationCounts($pdo, $electionId) : null;
    $excluded = [];
    $duplicateUsers = [];
    foreach ($approvedByUser as $userId => $candidates) {
        $byPost = [];
        foreach ($candidates as $candidate) $byPost[(int)$candidate['position_id']][] = $candidate;
        foreach ($byPost as $positionId => $entries) {
            if (count($entries) <= 1) continue;
            $duplicateUsers[$userId] = true;
            if (!$locked) $review['blockingReasons'][] = $candidates[0]['name'] . ' has duplicate candidate entries for ' . $posts[$positionId]['title'] . '.';
        }
        $stored = $choices[$userId] ?? null;
        $choice = $stored ? [
            'positionId' => (int)$stored['position_id'],
            'positionTitle' => $posts[(int)$stored['position_id']]['title'] ?? 'Position unavailable',
            'confirmedAt' => $stored['confirmed_at'], 'confirmedBy' => (int)$stored['confirmed_by'],
            'confirmationNote' => $stored['note'],
        ] : null;
        $set = electionStaffPositionCandidateSet($candidates);
        $savedSet = $stored ? electionStaffPositionSavedSet($stored) : null;
        $valid = $stored !== null && $savedSet !== null && isset($byPost[(int)$stored['position_id']])
            && !isset($duplicateUsers[$userId]) && isset($eligibleUsers[$userId])
            && hash_equals((string)$stored['candidate_set_hash'], $set['hash']);
        if (!$locked && $valid) {
            foreach ($candidates as $candidate) {
                if ((int)$candidate['position_id'] !== (int)$stored['position_id']) $excluded[(int)$candidate['id']] = true;
            }
        }
        // A previous choice still needs explicit reconfirmation if changes leave
        // just one eligible post. Never silently promote a formerly excluded post.
        if (count($byPost) > 1 || $stored !== null) {
            if ($locked) {
                $valid = $stored !== null && $savedSet !== null && !empty($stored['confirmed_at'])
                    && new DateTimeImmutable((string)$stored['confirmed_at']) <= new DateTimeImmutable((string)$election['opened_at']);
            }
            $review['conflicts'][] = [
                'userId' => (int)$userId, 'name' => $candidates[0]['name'],
                'positions' => array_map(static fn(array $candidate): array => [
                    'positionId' => (int)$candidate['position_id'],
                    'title' => $posts[(int)$candidate['position_id']]['title'],
                    'candidateId' => (int)$candidate['id'],
                    'nominationCount' => $counts === null ? null : ($counts[(int)$candidate['id']] ?? 0),
                ], $candidates),
                'choice' => $choice, 'resolved' => $valid,
            ];
            if (!$locked && !$valid) $review['blockingReasons'][] = $candidates[0]['name'] . ' needs one confirmed ballot position.';
        }
    }
    if ($locked) {
        // Use the saved pre-opening candidate IDs, even if a later raw record changes.
        // Old elections without a pre-opening choice keep their original full ballot.
        foreach ($choices as $userId => $choice) {
            if (empty($choice['confirmed_at']) || new DateTimeImmutable((string)$choice['confirmed_at']) > new DateTimeImmutable((string)$election['opened_at'])) continue;
            $saved = electionStaffPositionSavedSet($choice);
            if ($saved === null) continue;
            foreach ($saved as $pair) {
                $candidate = $candidateById[$pair['candidateId']] ?? null;
                if ($pair['positionId'] !== (int)$choice['position_id'] && $candidate !== null
                    && (int)$candidate['position_id'] === $pair['positionId']
                    && (int)($candidate['nominee_user_id'] ?? 0) === $userId) {
                    $excluded[$pair['candidateId']] = true;
                }
            }
        }
    }
    $review['excludedCandidateIds'] = array_map('intval', array_keys($excluded));
    sort($review['excludedCandidateIds'], SORT_NUMERIC);
    foreach ($posts as $positionId => $position) {
        $standing = [];
        foreach ($rawCandidates as $candidate) {
            if ((int)$candidate['position_id'] !== $positionId || $candidate['status'] !== 'approved' || isset($excluded[(int)$candidate['id']])) continue;
            $userId = (int)($candidate['nominee_user_id'] ?? 0);
            if ($locked || ($userId > 0 && isset($eligibleUsers[$userId]))) $standing[$userId ?: -(int)$candidate['id']] = true;
        }
        if (count($standing) < (int)$position['seats']) {
            $review['blockedPosts'][] = [
                'positionId' => $positionId, 'title' => $position['title'],
                'seats' => (int)$position['seats'], 'standing' => count($standing),
            ];
            if (!$locked) $review['blockingReasons'][] = $position['title'] . ' has ' . count($standing)
                . ' eligible standing candidate(s) for ' . (int)$position['seats'] . ' seat(s).';
        }
    }
    if (!$locked) {
        if (!$review['available']) $review['blockingReasons'][] = 'Complete the welfare position review database update before opening voting.';
        if (!$review['reviewPeriodValid']) $review['blockingReasons'][] = 'Voting must open after nominations close, with a separate position review interval.';
        if ($review['phase'] !== 'review') $review['blockingReasons'][] = 'Nominations must close before the position review can be completed.';
        if (!$posts) $review['blockingReasons'][] = 'Add the contested positions before opening voting.';
        if ($closes === null || $now >= $closes) $review['blockingReasons'][] = 'The voting window has ended. Review the timetable before proceeding.';
    }
    $review['blockingReasons'] = array_values(array_unique($review['blockingReasons']));
    $review['canRecord'] = !$locked && $review['available'] && $review['reviewPeriodValid']
        && $review['phase'] === 'review' && $closes !== null && $now < $closes;
    $review['ready'] = $locked || !$review['blockingReasons'];
    return $review;
}

/** @return array{0:?array,1:?string} */
function electionRecordStaffPositionChoice(PDO $pdo, int $electionId, int $nomineeUserId, int $positionId, string $confirmationNote, int $adminId): array
{
    return electionWithLock($pdo, (string)$electionId, static function () use ($pdo, $electionId, $nomineeUserId, $positionId, $confirmationNote, $adminId): array {
        $review = electionStaffPositionReview($pdo, $electionId);
        if (!$review['required']) return [null, 'Choose a staff welfare election.'];
        if (!$review['available']) return [null, 'Complete the welfare position review database update before recording choices.'];
        if (!$review['canRecord']) return [null, $review['phase'] === 'locked'
            ? 'Voting has already opened. Confirmed ballot positions cannot change.'
            : 'Record position choices only after nominations have closed, during a valid pre-voting review interval.'];
        $note = trim($confirmationNote);
        if ($note === '') return [null, 'Record how the nominee confirmed this ballot position.'];
        if (mb_strlen($note) > 2000) return [null, 'Keep the confirmation note within 2000 characters.'];
        if ($nomineeUserId < 1 || $positionId < 1 || $adminId < 1) return [null, 'Choose a nominee, ballot position and recording administrator.'];
        $conflict = null;
        foreach ($review['conflicts'] as $entry) if ($entry['userId'] === $nomineeUserId) $conflict = $entry;
        if ($conflict === null) return [null, 'This staff member does not need a choice between multiple ballot positions.'];
        $eligiblePositions = array_column($conflict['positions'], 'positionId');
        if (!in_array($positionId, $eligiblePositions, true)) return [null, 'Choose one of this nominee\'s eligible ballot positions.'];
        $statement = $pdo->prepare("SELECT user_id FROM election_roll WHERE election_id = ? AND user_id = ? AND standing_at_cutoff = 'staff' LIMIT 1");
        $statement->execute([$electionId, $nomineeUserId]);
        if (!$statement->fetchColumn()) return [null, 'The nominee is not on the frozen staff voter roll.'];
        $statement = $pdo->prepare("SELECT c.id, c.position_id FROM election_candidates c
                                     JOIN election_positions p ON p.id = c.position_id
                                    WHERE p.election_id = ? AND c.nominee_user_id = ? AND c.status = 'approved'
                                    ORDER BY c.id");
        $statement->execute([$electionId, $nomineeUserId]);
        $candidates = $statement->fetchAll(PDO::FETCH_ASSOC);
        $postIds = array_map('intval', array_column($candidates, 'position_id'));
        if (count($postIds) !== count(array_unique($postIds))) return [null, 'Resolve duplicate candidate entries for this nominee before recording a ballot position.'];
        $set = electionStaffPositionCandidateSet($candidates);
        $statement = $pdo->prepare('SELECT * FROM election_staff_position_choices WHERE election_id = ? AND nominee_user_id = ? FOR UPDATE');
        $statement->execute([$electionId, $nomineeUserId]);
        $previous = $statement->fetch(PDO::FETCH_ASSOC);
        if ($previous && (int)$previous['position_id'] === $positionId
            && hash_equals((string)$previous['candidate_set_hash'], $set['hash']) && $previous['note'] === $note) {
            return [$review, null];
        }
        $pdo->prepare('INSERT INTO election_staff_position_choices
                        (election_id, nominee_user_id, position_id, candidate_set_hash, candidate_set_json, confirmed_by, confirmed_at, note)
                       VALUES (?, ?, ?, ?, ?, ?, NOW(), ?)
                       ON DUPLICATE KEY UPDATE position_id=VALUES(position_id), candidate_set_hash=VALUES(candidate_set_hash),
                           candidate_set_json=VALUES(candidate_set_json), confirmed_by=VALUES(confirmed_by), confirmed_at=NOW(), note=VALUES(note)')
            ->execute([$electionId, $nomineeUserId, $positionId, $set['hash'], $set['json'], $adminId, $note]);
        $pdo->prepare('INSERT INTO election_staff_position_choice_events
                        (election_id, nominee_user_id, previous_position_id, new_position_id,
                         previous_candidate_set_hash, candidate_set_hash, previous_candidate_set_json,
                         candidate_set_json, actor_id, confirmation_note, created_at)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())')
            ->execute([$electionId, $nomineeUserId, $previous ? (int)$previous['position_id'] : null, $positionId,
                       $previous['candidate_set_hash'] ?? null, $set['hash'], $previous['candidate_set_json'] ?? null,
                       $set['json'], $adminId, $note]);
        return [electionStaffPositionReview($pdo, $electionId), null];
    });
}
