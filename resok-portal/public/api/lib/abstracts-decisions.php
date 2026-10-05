<?php
declare(strict_types=1);

/**
 * Abstract decisions and reporting (phase 3): the ranked list, recommendations and
 * decisions, the release, attendance confirmation, exports, the abstract book and the
 * statistics. Builds on lib/abstracts.php and lib/abstracts-review.php.
 *
 * The rule that matters most: no author learns an outcome early (DEC-3). Recording a
 * decision moves the abstract to Decision pending, which the author's page shows as
 * "Under review"; only absRelease() turns it into Accepted, Rejected or Waitlisted, and it
 * sends the decision emails in the same step, so what the page says and what the email says
 * change together.
 */

const ABS_OUTCOMES = ['accept', 'reject', 'waitlist'];
const ABS_DECIDABLE = ['under_review', 'decision_pending', 'accepted', 'rejected', 'waitlisted', 'submitted'];
const ABS_RELEASED = ['accepted', 'rejected', 'waitlisted', 'confirmed'];

function absDecisionRow(PDO $pdo, int $abstractId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM abs_decisions WHERE abstract_id = ?');
    $stmt->execute([$abstractId]);
    return $stmt->fetch() ?: null;
}

/** Presentation types a decision can give: everything offered except "either". */
function absFinalTypes(array $settings): array
{
    $types = $settings['presentationTypes'];
    unset($types['either']);
    return $types ?: ['oral' => 'Oral presentation', 'poster' => 'Poster'];
}

// ---------------------------------------------------------------------------------------
// The ranked list (DEC-1)
// ---------------------------------------------------------------------------------------

function absDecisionBoard(PDO $pdo, array $eventRow, array $access): array
{
    $s = absSettings($eventRow['settings'] ?? null);
    $stmt = $pdo->prepare("SELECT a.id, a.reference, a.title, a.status, a.track_id, a.preferred_type, a.final_type, t.name AS track_name,
                                  t.sort_order, d.outcome, d.presentation_type, d.recommended_outcome, d.recommended_type, d.note,
                                  d.released_at, d.attendance, d.decided_at
                           FROM abs_abstracts a
                           LEFT JOIN abs_tracks t ON t.id = a.track_id
                           LEFT JOIN abs_decisions d ON d.abstract_id = a.id
                           WHERE a.event_id = ? AND a.status NOT IN ('draft')
                           ORDER BY t.sort_order, a.reference");
    $stmt->execute([(int)$eventRow['id']]);
    $reviews = $pdo->prepare("SELECT reviewer_user_id, total, recommendation, recommended_type, comments_authors, comments_committee
                              FROM abs_reviews WHERE abstract_id = ? AND status = 'submitted' ORDER BY id");
    $presenter = $pdo->prepare('SELECT first_name, last_name, affiliation FROM abs_authors WHERE abstract_id = ? AND is_presenting = 1 LIMIT 1');
    $names = [];
    $rows = [];
    foreach ($stmt->fetchAll() as $a) {
        if (!absReaches($access, $a['track_id'])) continue;
        $reviews->execute([(int)$a['id']]);
        $list = [];
        $totals = [];
        foreach ($reviews->fetchAll() as $r) {
            $uid = (int)$r['reviewer_user_id'];
            $names[$uid] = $names[$uid] ?? absPersonName($pdo, $uid)['name'];
            $list[] = ['reviewer' => $names[$uid], 'total' => $r['total'] !== null ? (float)$r['total'] : null,
                       'recommendation' => $r['recommendation'], 'recommendedType' => $r['recommended_type'],
                       'commentsAuthors' => $r['comments_authors'], 'commentsCommittee' => $r['comments_committee']];
            if ($r['total'] !== null) $totals[] = (float)$r['total'];
        }
        $presenter->execute([(int)$a['id']]);
        $p = $presenter->fetch();
        $rows[] = [
            'id' => (int)$a['id'], 'reference' => $a['reference'], 'title' => $a['title'], 'status' => $a['status'],
            'trackId' => $a['track_id'] !== null ? (int)$a['track_id'] : null, 'trackName' => $a['track_name'],
            'preferredType' => $a['preferred_type'], 'presenter' => $p ? trim($p['first_name'] . ' ' . $p['last_name']) : null,
            'presenterAffiliation' => $p['affiliation'] ?? null,
            'reviews' => $list, 'completed' => count($list), 'wanted' => $s['reviewsPerAbstract'],
            'average' => $totals ? round(array_sum($totals) / count($totals), 2) : null,
            'spread' => count($totals) >= 2 ? round(max($totals) - min($totals), 2) : null,
            'acceptVotes' => count(array_filter($list, static fn($r) => $r['recommendation'] === 'accept')),
            'decision' => [
                'outcome' => $a['outcome'], 'type' => $a['presentation_type'], 'note' => $a['note'],
                'recommendedOutcome' => $a['recommended_outcome'], 'recommendedType' => $a['recommended_type'],
                'released' => $a['released_at'] !== null, 'releasedAt' => $a['released_at'], 'attendance' => $a['attendance'],
            ],
        ];
    }
    // Ranked within each track by average score; abstracts not yet scored go last.
    usort($rows, static function ($x, $y) {
        if ($x['trackId'] !== $y['trackId']) return 0;
        return ($y['average'] ?? -1) <=> ($x['average'] ?? -1);
    });
    $byTrack = [];
    foreach ($rows as $r) $byTrack[$r['trackId'] ?? 0][] = $r;
    $out = [];
    foreach ($byTrack as $list) {
        usort($list, static fn($x, $y) => ($y['average'] ?? -1) <=> ($x['average'] ?? -1));
        foreach ($list as $i => $r) { $r['rank'] = $r['average'] !== null ? $i + 1 : null; $out[] = $r; }
    }
    return $out;
}

// ---------------------------------------------------------------------------------------
// Recommending and deciding (DEC-2)
// ---------------------------------------------------------------------------------------

/** A track chair's recommendation; the programme chair decides. */
function absRecommend(PDO $pdo, array $eventRow, array $access, int $abstractId, string $outcome, ?string $type, int $by): ?string
{
    $row = absAbstractRow($pdo, $abstractId);
    if (!$row || (int)$row['event_id'] !== (int)$eventRow['id']) return 'No such abstract.';
    if (!absReaches($access, $row['track_id'])) return 'That abstract is outside the tracks you chair.';
    $s = absSettings($eventRow['settings'] ?? null);
    if ($outcome !== '' && !in_array($outcome, ABS_OUTCOMES, true)) return 'Unknown recommendation.';
    if ($outcome !== 'accept' || !array_key_exists((string)$type, absFinalTypes($s))) $type = null;
    $pdo->prepare('INSERT INTO abs_decisions (abstract_id, recommended_outcome, recommended_type, recommended_by, recommended_at)
                   VALUES (?, ?, ?, ?, NOW())
                   ON DUPLICATE KEY UPDATE recommended_outcome = VALUES(recommended_outcome), recommended_type = VALUES(recommended_type),
                                           recommended_by = VALUES(recommended_by), recommended_at = NOW()')
        ->execute([$abstractId, $outcome !== '' ? $outcome : null, $type, $by]);
    absAudit($pdo, (int)$row['event_id'], $abstractId, $by, 'decision_recommended', null, null, ['outcome' => $outcome ?: null, 'type' => $type]);
    return null;
}

/**
 * Records the programme chair's decision for one or many abstracts. An abstract short of
 * its reviews is refused unless $override says the chair means it. Changing a decision that
 * was already released takes it back to pending: it has to be released again, which sends
 * the author the new outcome.
 *
 * @return array{decided:int, errors: string[]}
 */
function absDecide(PDO $pdo, array $eventRow, array $access, array $abstractIds, string $outcome, ?string $type, string $note, bool $override, int $by): array
{
    $s = absSettings($eventRow['settings'] ?? null);
    $errors = [];
    $decided = 0;
    $clear = $outcome === '';
    if (!$clear && !in_array($outcome, ABS_OUTCOMES, true)) return ['decided' => 0, 'errors' => ['Unknown decision.']];
    if ($outcome === 'accept' && !array_key_exists((string)$type, absFinalTypes($s))) {
        return ['decided' => 0, 'errors' => ['Choose how an accepted abstract will be presented.']];
    }
    if ($outcome !== 'accept') $type = null;
    $completed = $pdo->prepare("SELECT COUNT(*) FROM abs_reviews WHERE abstract_id = ? AND status = 'submitted'");
    foreach (array_unique(array_map('intval', $abstractIds)) as $id) {
        $row = absAbstractRow($pdo, $id);
        if (!$row || (int)$row['event_id'] !== (int)$eventRow['id']) { $errors[] = "#{$id}: no such abstract."; continue; }
        $label = $row['reference'] ?? '#' . $id;
        if (!absReaches($access, $row['track_id'])) { $errors[] = "{$label}: outside the tracks you chair."; continue; }
        if (!in_array($row['status'], ABS_DECIDABLE, true)) { $errors[] = "{$label} is " . str_replace('_', ' ', $row['status']) . '.'; continue; }
        $completed->execute([$id]);
        $done = (int)$completed->fetchColumn();
        if (!$clear && !$override && $done < $s['reviewsPerAbstract']) {
            $errors[] = "{$label} has {$done} of {$s['reviewsPerAbstract']} reviews.";
            continue;
        }
        $pdo->prepare('INSERT INTO abs_decisions (abstract_id, outcome, presentation_type, decided_by, decided_at, note, released_at, released_by, attendance)
                       VALUES (?, ?, ?, ?, NOW(), ?, NULL, NULL, NULL)
                       ON DUPLICATE KEY UPDATE outcome = VALUES(outcome), presentation_type = VALUES(presentation_type),
                              decided_by = VALUES(decided_by), decided_at = NOW(), note = COALESCE(VALUES(note), note),
                              released_at = NULL, released_by = NULL, attendance = NULL, attendance_at = NULL')
            ->execute([$id, $clear ? null : $outcome, $type, $by, $note !== '' ? mb_substr($note, 0, 1000) : null]);
        // Clearing puts it back where it was before any decision: in review, or merely
        // submitted if it never reached a reviewer.
        $to = $clear ? ($row['status'] === 'submitted' ? 'submitted' : 'under_review') : 'decision_pending';
        if ($row['status'] !== $to) {
            $pdo->prepare('UPDATE abs_abstracts SET status = ?, final_type = NULL WHERE id = ?')->execute([$to, $id]);
        }
        absAudit($pdo, (int)$row['event_id'], $id, $by, $clear ? 'decision_cleared' : 'decision_recorded', $row['status'], $to,
                 ['outcome' => $clear ? null : $outcome, 'type' => $type, 'override' => $override && $done < $s['reviewsPerAbstract'] ? true : null]);
        $decided++;
    }
    return ['decided' => $decided, 'errors' => $errors];
}

// ---------------------------------------------------------------------------------------
// Release (DEC-3, DEC-4)
// ---------------------------------------------------------------------------------------

/**
 * Releases pending decisions - all of them, one track's, or a chosen few (a waitlist
 * promotion) - and emails each author the outcome with the reviewers' comments for authors.
 *
 * @return array{released:int}
 */
function absRelease(PDO $pdo, array $config, array $eventRow, array $access, ?int $trackId, ?array $abstractIds, int $by): array
{
    $s = absSettings($eventRow['settings'] ?? null);
    $sql = "SELECT a.id, a.track_id, a.status, d.outcome, d.presentation_type FROM abs_abstracts a
            JOIN abs_decisions d ON d.abstract_id = a.id
            WHERE a.event_id = ? AND a.status = 'decision_pending' AND d.outcome IS NOT NULL AND d.released_at IS NULL";
    $params = [(int)$eventRow['id']];
    if ($trackId) { $sql .= ' AND a.track_id = ?'; $params[] = $trackId; }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $released = 0;
    foreach ($stmt->fetchAll() as $r) {
        if ($abstractIds !== null && !in_array((int)$r['id'], array_map('intval', $abstractIds), true)) continue;
        if (!absReaches($access, $r['track_id'])) continue;
        $status = ['accept' => 'accepted', 'reject' => 'rejected', 'waitlist' => 'waitlisted'][$r['outcome']];
        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE abs_abstracts SET status = ?, final_type = ? WHERE id = ?')
                ->execute([$status, $r['outcome'] === 'accept' ? $r['presentation_type'] : null, (int)$r['id']]);
            $pdo->prepare('UPDATE abs_decisions SET released_at = NOW(), released_by = ?, attendance = ? WHERE abstract_id = ?')
                ->execute([$by, $r['outcome'] === 'accept' ? 'pending' : null, (int)$r['id']]);
            absAudit($pdo, (int)$eventRow['id'], (int)$r['id'], $by, 'decision_released', 'decision_pending', $status);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        absSendDecisionEmail($pdo, $config, $eventRow, $s, (int)$r['id']);
        $released++;
    }
    return ['released' => $released];
}

/** The reviewers' comments for authors, unsigned and numbered. */
function absCommentsForAuthors(PDO $pdo, int $abstractId): string
{
    $stmt = $pdo->prepare("SELECT comments_authors FROM abs_reviews WHERE abstract_id = ? AND status = 'submitted'
                           AND comments_authors IS NOT NULL AND comments_authors <> '' ORDER BY id");
    $stmt->execute([$abstractId]);
    $parts = [];
    foreach ($stmt->fetchAll() as $i => $r) $parts[] = 'Reviewer ' . ($i + 1) . ': ' . trim((string)$r['comments_authors']);
    return $parts ? implode("\n\n", $parts) : 'No written comments.';
}

function absSendDecisionEmail(PDO $pdo, array $config, array $eventRow, array $settings, int $abstractId): void
{
    $row = absAbstractRow($pdo, $abstractId);
    $d = absDecisionRow($pdo, $abstractId);
    if (!$row || !$d || !$d['outcome']) return;
    $authors = absAuthors($pdo, $abstractId);
    $submitter = absPersonName($pdo, (int)$row['submitter_user_id']);
    $presenter = '';
    $to = [strtolower($submitter['email'])];
    foreach ($authors as $a) {
        if ($a['presenting']) $presenter = trim($a['firstName'] . ' ' . $a['lastName']);
        if ($a['corresponding']) $to[] = strtolower($a['email']);
    }
    $types = $settings['presentationTypes'];
    $confirmBy = $settings['attendanceConfirmBy'] ? (new DateTimeImmutable($settings['attendanceConfirmBy']))->format('j F Y') : 'as soon as possible';
    $vars = [
        'name' => $submitter['name'], 'event' => (string)$eventRow['name'], 'reference' => (string)$row['reference'],
        'title' => (string)$row['title'], 'presentation' => strtolower($types[$d['presentation_type']] ?? (string)$d['presentation_type']),
        'presenter' => $presenter !== '' ? $presenter : 'the presenting author', 'confirm_by' => $confirmBy,
        'reviewer_comments' => absCommentsForAuthors($pdo, $abstractId), 'link' => absEmailUrl($config) . '#view/' . $abstractId,
    ];
    $template = ['accept' => 'decision_accepted', 'reject' => 'decision_rejected', 'waitlist' => 'decision_waitlisted'][$d['outcome']];
    foreach (array_unique(array_filter($to)) as $address) {
        absSendTemplate($pdo, $config, (int)$eventRow['id'], $abstractId, $template, $address, $vars);
    }
}

/** What the author may see of a released decision, for their page. Null before release. */
function absDecisionForAuthor(PDO $pdo, array $row, array $eventRow): ?array
{
    if (!in_array($row['status'], ABS_RELEASED, true) && !($row['status'] === 'withdrawn' && absDecisionReleased($pdo, (int)$row['id']))) return null;
    $d = absDecisionRow($pdo, (int)$row['id']);
    if (!$d || !$d['released_at']) return null;
    $s = absSettings($eventRow['settings'] ?? null);
    $comments = [];
    $stmt = $pdo->prepare("SELECT comments_authors FROM abs_reviews WHERE abstract_id = ? AND status = 'submitted'
                           AND comments_authors IS NOT NULL AND comments_authors <> '' ORDER BY id");
    $stmt->execute([(int)$row['id']]);
    foreach ($stmt->fetchAll() as $r) $comments[] = (string)$r['comments_authors'];
    return [
        'outcome' => $d['outcome'],
        'presentation' => $d['presentation_type'] !== null ? ($s['presentationTypes'][$d['presentation_type']] ?? $d['presentation_type']) : null,
        'comments' => $comments,
        'attendance' => $d['attendance'],
        'confirmBy' => $s['attendanceConfirmBy'],
    ];
}

function absDecisionReleased(PDO $pdo, int $abstractId): bool
{
    $d = absDecisionRow($pdo, $abstractId);
    return $d !== null && $d['released_at'] !== null;
}

// ---------------------------------------------------------------------------------------
// Attendance (DEC-5) and the waitlist (DEC-7)
// ---------------------------------------------------------------------------------------

/** The author confirms the presenter will attend, or declines - which withdraws the abstract. */
function absAttendance(PDO $pdo, array $config, array $eventRow, array $row, int $userId, bool $confirm, string $reason): ?string
{
    if ($row['status'] !== 'accepted') return $row['status'] === 'confirmed' ? 'Attendance is already confirmed.' : 'Only an accepted abstract needs confirming.';
    $label = $confirm ? 'confirmed' : 'declined';
    $pdo->prepare('UPDATE abs_decisions SET attendance = ?, attendance_at = NOW() WHERE abstract_id = ?')->execute([$label, (int)$row['id']]);
    if ($confirm) {
        $pdo->prepare("UPDATE abs_abstracts SET status = 'confirmed' WHERE id = ?")->execute([(int)$row['id']]);
        absAudit($pdo, (int)$row['event_id'], (int)$row['id'], $userId, 'attendance_confirmed', 'accepted', 'confirmed');
        return null;
    }
    absWithdraw($pdo, $eventRow, $row, $userId, $reason !== '' ? 'Declined to present: ' . $reason : 'Declined to present');
    absAudit($pdo, (int)$row['event_id'], (int)$row['id'], $userId, 'attendance_declined', null, null, $reason !== '' ? ['reason' => mb_substr($reason, 0, 500)] : null);
    absSendWithdrawalEmails($pdo, $config, $eventRow, (int)$row['id']);
    return null;
}

/** Accepted presenters who have not answered, 7 days and 1 day before the confirmation date. */
function absSendAttendanceReminders(PDO $pdo, array $config, array $eventRow): int
{
    $s = absSettings($eventRow['settings'] ?? null);
    if (!$s['attendanceConfirmBy']) return 0;
    $zone = absZone($eventRow);
    $by = new DateTimeImmutable($s['attendanceConfirmBy'] . ' 23:59:59', $zone);
    $hours = ($by->getTimestamp() - time()) / 3600;
    if ($hours < 0) return 0;
    $window = $hours <= 24 ? '1d' : ($hours <= 24 * 7 ? '7d' : null);
    if ($window === null) return 0;
    $stmt = $pdo->prepare("SELECT a.id, a.reference, a.title, a.submitter_user_id FROM abs_abstracts a JOIN abs_decisions d ON d.abstract_id = a.id
                           WHERE a.event_id = ? AND a.status = 'accepted' AND d.attendance = 'pending'");
    $stmt->execute([(int)$eventRow['id']]);
    $sent = 0;
    foreach ($stmt->fetchAll() as $r) {
        $p = absPersonName($pdo, (int)$r['submitter_user_id']);
        if (absQueueOnce($pdo, $config, (int)$eventRow['id'], 'attendance_reminder', $p['email'], [
            'name' => $p['name'], 'event' => (string)$eventRow['name'], 'reference' => (string)$r['reference'], 'title' => (string)$r['title'],
            'confirm_by' => $by->format('j F Y'), 'link' => absEmailUrl($config) . '#view/' . $r['id'],
        ], "attrem{$window}:{$r['id']}", (int)$r['id'])) $sent++;
    }
    return $sent;
}

/** Moves an abstract to another track (DEC-6). */
function absMoveTrack(PDO $pdo, array $eventRow, array $access, int $abstractId, int $trackId, int $by): ?string
{
    $row = absAbstractRow($pdo, $abstractId);
    if (!$row || (int)$row['event_id'] !== (int)$eventRow['id']) return 'No such abstract.';
    if (!absReaches($access, $row['track_id']) || !absReaches($access, $trackId)) return 'You can only move abstracts between tracks you chair.';
    $t = $pdo->prepare('SELECT name FROM abs_tracks WHERE id = ? AND event_id = ? AND active = 1');
    $t->execute([$trackId, (int)$eventRow['id']]);
    $name = $t->fetchColumn();
    if ($name === false) return 'Choose one of the event\'s tracks.';
    if ((int)$row['track_id'] === $trackId) return null;
    $pdo->prepare('UPDATE abs_abstracts SET track_id = ? WHERE id = ?')->execute([$trackId, $abstractId]);
    absAudit($pdo, (int)$row['event_id'], $abstractId, $by, 'track_changed', null, null, ['from' => $row['track_name'], 'to' => $name]);
    return null;
}

// ---------------------------------------------------------------------------------------
// Submitting on someone's behalf (ADM-4)
// ---------------------------------------------------------------------------------------

/**
 * Starts a draft owned by the author, for an administrator to fill in. The author's account
 * is found by email or created (verified, with a random password they can reset); either
 * way the confirmation goes to them when it is submitted, so they know it exists. The
 * draft stays open to edit for 14 days even if the call has closed.
 *
 * @return array{0: ?int, 1: ?string} [abstract id, error]
 */
function absStartOnBehalf(PDO $pdo, array $eventRow, array $data, int $by): array
{
    $email = strtolower(trim((string)($data['email'] ?? '')));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return [null, 'Enter the author\'s email address.'];
    $stmt = $pdo->prepare('SELECT id FROM users WHERE LOWER(email) = ? LIMIT 1');
    $stmt->execute([$email]);
    $userId = (int)$stmt->fetchColumn();
    $pdo->beginTransaction();
    try {
        if (!$userId) {
            $pdo->prepare('INSERT INTO users (email, password_hash, email_verified, role) VALUES (?, ?, 1, "member")')
                ->execute([$email, password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT)]);
            $userId = (int)$pdo->lastInsertId();
        }
        if (!absProfile($pdo, $userId)['complete']) {
            if (absProfileSave($pdo, $userId, $data) !== null) {
                $pdo->rollBack();
                return [null, 'This author is new: also give their first name, last name, affiliation and country.'];
            }
        }
        $until = (new DateTimeImmutable('now', absZone($eventRow)))->modify('+14 days')->format('Y-m-d H:i:s');
        $pdo->prepare('INSERT INTO abs_abstracts (event_id, submitter_user_id, reopened_until) VALUES (?, ?, ?)')
            ->execute([(int)$eventRow['id'], $userId, $until]);
        $id = (int)$pdo->lastInsertId();
        $p = absProfile($pdo, $userId);
        $pdo->prepare('INSERT INTO abs_authors (abstract_id, sort_order, first_name, last_name, email, affiliation, country, is_presenting, is_corresponding, user_id)
                       VALUES (?, 0, ?, ?, ?, ?, ?, 1, 1, ?)')
            ->execute([$id, (string)$p['firstName'], (string)$p['lastName'], $email, (string)$p['affiliation'], $p['country'] ?: null, $userId]);
        absAudit($pdo, (int)$eventRow['id'], $id, $by, 'draft_created_on_behalf', null, 'draft', ['author' => $email]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return [$id, null];
}

// ---------------------------------------------------------------------------------------
// Exports (ADM-6)
// ---------------------------------------------------------------------------------------

/** @return array<int, array<int, string|int|float|null>> the first row is the header */
function absExportRows(PDO $pdo, array $eventRow, array $access, string $kind): array
{
    $s = absSettings($eventRow['settings'] ?? null);
    $types = $s['presentationTypes'];
    $eventId = (int)$eventRow['id'];
    $abstracts = $pdo->prepare("SELECT a.*, t.name AS track_name, u.email AS submitter_email, d.outcome, d.presentation_type AS decided_type,
                                       d.decided_at, d.released_at, d.attendance,
                                       (SELECT AVG(total) FROM abs_reviews r WHERE r.abstract_id = a.id AND r.status = 'submitted') AS average,
                                       (SELECT COUNT(*) FROM abs_reviews r WHERE r.abstract_id = a.id AND r.status = 'submitted') AS review_count
                                FROM abs_abstracts a LEFT JOIN abs_tracks t ON t.id = a.track_id
                                LEFT JOIN users u ON u.id = a.submitter_user_id LEFT JOIN abs_decisions d ON d.abstract_id = a.id
                                WHERE a.event_id = ? ORDER BY (a.reference IS NULL), a.reference, a.id");
    $abstracts->execute([$eventId]);
    $list = array_values(array_filter($abstracts->fetchAll(), static fn($a) => absReaches($access, $a['track_id'])));

    if ($kind === 'authors') {
        $out = [['Reference', 'Title', 'Order', 'First name', 'Last name', 'Email', 'Affiliation', 'Country', 'Presenting', 'Corresponding', 'Status']];
        foreach ($list as $a) {
            foreach (absAuthors($pdo, (int)$a['id']) as $i => $au) {
                $out[] = [$a['reference'], $a['title'], $i + 1, $au['firstName'], $au['lastName'], $au['email'], $au['affiliation'],
                          $au['country'], $au['presenting'] ? 'Yes' : '', $au['corresponding'] ? 'Yes' : '', $a['status']];
            }
        }
        return $out;
    }
    if ($kind === 'reviews') {
        $head = ['Reference', 'Title', 'Track', 'Reviewer', 'Reviewer email', 'Status'];
        foreach ($s['criteria'] as $c) $head[] = $c['label'];
        $out = [array_merge($head, ['Overall', 'Recommendation', 'Recommended type', 'Comments for authors', 'Confidential comments', 'Declined because', 'Submitted at'])];
        $reviews = $pdo->prepare("SELECT * FROM abs_reviews WHERE abstract_id = ? AND status <> 'cancelled' ORDER BY id");
        foreach ($list as $a) {
            $reviews->execute([(int)$a['id']]);
            foreach ($reviews->fetchAll() as $r) {
                $p = absPersonName($pdo, (int)$r['reviewer_user_id']);
                $scores = json_decode((string)($r['scores'] ?? '{}'), true) ?: [];
                $line = [$a['reference'], $a['title'], $a['track_name'], $p['name'], $p['email'], $r['status']];
                foreach ($s['criteria'] as $c) $line[] = $scores[$c['key']] ?? null;
                $out[] = array_merge($line, [$r['total'] !== null ? (float)$r['total'] : null, $r['recommendation'],
                    $types[$r['recommended_type']] ?? $r['recommended_type'], $r['comments_authors'], $r['comments_committee'],
                    $r['decline_reason'], $r['submitted_at']]);
            }
        }
        return $out;
    }
    if ($kind === 'decisions') {
        $out = [['Reference', 'Title', 'Track', 'Presenter', 'Average score', 'Reviews', 'Decision', 'Presentation', 'Decided at', 'Released at', 'Attendance', 'Status']];
        foreach ($list as $a) {
            if ($a['outcome'] === null && !in_array($a['status'], ['under_review', 'decision_pending'], true)) continue;
            $presenter = array_values(array_filter(absAuthors($pdo, (int)$a['id']), static fn($x) => $x['presenting']))[0] ?? null;
            $out[] = [$a['reference'], $a['title'], $a['track_name'], $presenter ? trim($presenter['firstName'] . ' ' . $presenter['lastName']) : null,
                      $a['average'] !== null ? round((float)$a['average'], 2) : null, (int)$a['review_count'], $a['outcome'],
                      $types[$a['decided_type']] ?? $a['decided_type'], $a['decided_at'], $a['released_at'], $a['attendance'], $a['status']];
        }
        return $out;
    }
    // abstracts
    $out = [['Reference', 'Title', 'Track', 'Status', 'Preferred presentation', 'Final presentation', 'Keywords', 'Presenter', 'Presenter email',
             'Corresponding email', 'Submitted by', 'Authors', 'Average score', 'Reviews', 'Submitted at', 'Abstract']];
    foreach ($list as $a) {
        $authors = absAuthors($pdo, (int)$a['id']);
        $presenter = array_values(array_filter($authors, static fn($x) => $x['presenting']))[0] ?? null;
        $corresponding = array_values(array_filter($authors, static fn($x) => $x['corresponding']))[0] ?? null;
        $out[] = [$a['reference'], $a['title'], $a['track_name'], $a['status'], $types[$a['preferred_type']] ?? $a['preferred_type'],
                  $types[$a['final_type']] ?? $a['final_type'], implode('; ', json_decode((string)($a['keywords'] ?? '[]'), true) ?: []),
                  $presenter ? trim($presenter['firstName'] . ' ' . $presenter['lastName']) : null, $presenter['email'] ?? null,
                  $corresponding['email'] ?? null, $a['submitter_email'],
                  implode('; ', array_map(static fn($x) => trim($x['firstName'] . ' ' . $x['lastName']) . ' (' . $x['affiliation'] . ')', $authors)),
                  $a['average'] !== null ? round((float)$a['average'], 2) : null, (int)$a['review_count'], $a['submitted_at'], (string)$a['body']];
    }
    return $out;
}

/** CSV with a byte-order mark, so Excel opens accented names correctly. */
function absCsv(array $rows): string
{
    $fh = fopen('php://temp', 'r+');
    fwrite($fh, "\xEF\xBB\xBF");
    foreach ($rows as $row) {
        // A cell starting with = + - @ is a formula to a spreadsheet; prefixing it keeps a
        // submitted title from running as one when the file is opened. Formatting tokens
        // (<i> and the like) mean nothing in a spreadsheet, so they go.
        $row = array_map(static fn($v) => is_string($v) ? absPlain($v) : $v, $row);
        fputcsv($fh, array_map(static fn($v) => is_string($v) && $v !== '' && strpbrk($v[0], '=+-@') !== false ? "'" . $v : $v, $row));
    }
    rewind($fh);
    return (string)stream_get_contents($fh);
}

/** A minimal .xlsx: one sheet, inline strings, no dependency on the zip extension. */
function absXlsx(array $rows, string $sheetName = 'Sheet1'): string
{
    $col = static function (int $i): string { $s = ''; for ($i++; $i > 0; $i = intdiv($i - 1, 26)) $s = chr(65 + ($i - 1) % 26) . $s; return $s; };
    $esc = static fn($v) => htmlspecialchars((string)$v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
    foreach ($rows as $r => $row) {
        $xml .= '<row r="' . ($r + 1) . '">';
        foreach (array_values($row) as $c => $v) {
            $ref = $col($c) . ($r + 1);
            if ($v === null || $v === '') continue;
            if ((is_int($v) || is_float($v)) && $r > 0) $xml .= '<c r="' . $ref . '"><v>' . $v . '</v></c>';
            else $xml .= '<c r="' . $ref . '" t="inlineStr"' . ($r === 0 ? ' s="1"' : '') . '><is><t xml:space="preserve">' . $esc(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', absPlain((string)$v))) . '</t></is></c>';
        }
        $xml .= '</row>';
    }
    $xml .= '</sheetData></worksheet>';
    $files = [
        '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>',
        '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
        'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="' . $esc(mb_substr($sheetName, 0, 31)) . '" sheetId="1" r:id="rId1"/></sheets></workbook>',
        'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>',
        'xl/styles.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts><fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills><borders count="1"><border/></borders><cellStyleXfs count="1"><xf/></cellStyleXfs><cellXfs count="2"><xf/><xf fontId="1" applyFont="1"/></cellXfs></styleSheet>',
        'xl/worksheets/sheet1.xml' => $xml,
    ];
    return absZip($files);
}

/** A stored (uncompressed) ZIP archive - all an .xlsx needs to be valid. */
function absZip(array $files): string
{
    $data = '';
    $central = '';
    $offset = 0;
    foreach ($files as $name => $content) {
        $crc = crc32($content);
        $len = strlen($content);
        $head = pack('VvvvvvVVVvv', 0x04034b50, 20, 0x0800, 0, 0, 0x21, $crc, $len, $len, strlen($name), 0);
        $data .= $head . $name . $content;
        $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0x0800, 0, 0, 0x21, $crc, $len, $len, strlen($name), 0, 0, 0, 0, 0, $offset) . $name;
        $offset += strlen($head) + strlen($name) + $len;
    }
    return $data . $central . pack('VvvvvVVv', 0x06054b50, 0, 0, count($files), count($files), strlen($central), $offset, 0);
}

// ---------------------------------------------------------------------------------------
// The abstract book (ADM-7) and the public list (INT-5)
// ---------------------------------------------------------------------------------------

/** Accepted and confirmed abstracts whose decision is released, by track, then oral before poster. */
function absBookData(PDO $pdo, array $eventRow): array
{
    $s = absSettings($eventRow['settings'] ?? null);
    $order = array_keys(absFinalTypes($s));
    $stmt = $pdo->prepare("SELECT a.*, t.name AS track_name, t.sort_order FROM abs_abstracts a
                           JOIN abs_decisions d ON d.abstract_id = a.id AND d.released_at IS NOT NULL
                           LEFT JOIN abs_tracks t ON t.id = a.track_id
                           WHERE a.event_id = ? AND a.status IN ('accepted','confirmed')
                           ORDER BY t.sort_order, a.reference");
    $stmt->execute([(int)$eventRow['id']]);
    $tracks = [];
    foreach ($stmt->fetchAll() as $a) {
        $key = $a['track_name'] ?? 'Other';
        $authors = absAuthors($pdo, (int)$a['id']);
        $tracks[$key][] = [
            'reference' => $a['reference'], 'title' => $a['title'], 'type' => $a['final_type'],
            'typeLabel' => $s['presentationTypes'][$a['final_type']] ?? $a['final_type'],
            'authors' => array_map(static fn($x) => ['name' => trim($x['firstName'] . ' ' . $x['lastName']), 'affiliation' => $x['affiliation'],
                                                     'presenting' => $x['presenting']], $authors),
            'sections' => $a['sections'] ? json_decode((string)$a['sections'], true) : null,
            'body' => (string)$a['body'], 'keywords' => json_decode((string)($a['keywords'] ?? '[]'), true) ?: [],
            'figures' => function_exists('absFigures') ? absFigures($pdo, (int)$a['id']) : [],
            'status' => $a['status'],
        ];
    }
    $out = [];
    foreach ($tracks as $name => $list) {
        usort($list, static fn($x, $y) => [array_search($x['type'], $order, true), $x['reference']] <=> [array_search($y['type'], $order, true), $y['reference']]);
        $out[] = ['track' => $name, 'abstracts' => $list];
    }
    return ['event' => (string)$eventRow['name'], 'venue' => $eventRow['venue'], 'startsOn' => $eventRow['starts_on'],
            'endsOn' => $eventRow['ends_on'], 'tracks' => $out];
}

/** The book as an HTML document Word opens directly (saved as .doc). */
function absBookWordHtml(array $book): string
{
    $h = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    $html = '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:w="urn:schemas-microsoft-com:office:word"><head><meta charset="utf-8">'
        . '<title>' . $h($book['event']) . ' - Abstract book</title><style>body{font-family:Calibri,Arial,sans-serif;font-size:11pt}'
        . 'h1{font-size:22pt;color:#087539}h2{font-size:16pt;color:#087539;page-break-before:always}h3{font-size:12pt;margin:18pt 0 2pt}'
        . '.ref{color:#666;font-size:9pt}.aut{font-size:10pt}.aff{font-size:9pt;color:#555}.sec{margin:4pt 0}.kw{font-size:9pt;color:#555}</style></head><body>';
    $html .= '<h1>' . $h($book['event']) . '</h1><p>Abstract book</p>';
    foreach ($book['tracks'] as $t) {
        $html .= '<h2>' . $h($t['track']) . '</h2>';
        $currentType = null;
        foreach ($t['abstracts'] as $a) {
            if ($a['typeLabel'] !== $currentType) { $currentType = $a['typeLabel']; $html .= '<p><b>' . $h($currentType) . 's</b></p>'; }
            $affs = [];
            $names = [];
            foreach ($a['authors'] as $au) {
                $i = array_search($au['affiliation'], $affs, true);
                if ($i === false) { $affs[] = $au['affiliation']; $i = count($affs) - 1; }
                $names[] = ($au['presenting'] ? '<u>' . $h($au['name']) . '</u>' : $h($au['name'])) . '<sup>' . ($i + 1) . '</sup>';
            }
            $html .= '<h3>' . absRichHtml($a['title']) . '</h3><div class="ref">' . $h($a['reference']) . '</div><div class="aut">' . implode(', ', $names) . '</div>';
            foreach ($affs as $i => $aff) $html .= '<div class="aff"><sup>' . ($i + 1) . '</sup> ' . $h($aff) . '</div>';
            if (is_array($a['sections'])) {
                foreach ($a['sections'] as $sh => $st) $html .= '<p class="sec"><b>' . $h($sh) . ':</b> ' . nl2br(absRichHtml($st)) . '</p>';
            } else {
                $html .= '<p class="sec">' . nl2br(absRichHtml($a['body'])) . '</p>';
            }
            // Word does not reliably show images embedded in an HTML file, so the Word book
            // names each figure; the printable book page shows the figures themselves.
            foreach ($a['figures'] ?? [] as $i => $f) {
                $html .= '<p class="kw"><b>Figure ' . ($i + 1) . ':</b> ' . absRichHtml($f['caption']) . ' <i>(see the online abstract book)</i></p>';
            }
            if ($a['keywords']) $html .= '<p class="kw"><b>Keywords:</b> ' . $h(implode(', ', $a['keywords'])) . '</p>';
        }
    }
    return $html . '</body></html>';
}

/** What the conference website may show publicly once decisions are out (INT-5). */
function absAcceptedPublic(PDO $pdo, array $eventRow): array
{
    $book = absBookData($pdo, $eventRow);
    return array_map(static fn($t) => ['track' => $t['track'], 'abstracts' => array_map(static fn($a) => [
        'reference' => $a['reference'], 'title' => absPlain($a['title']), 'type' => $a['typeLabel'],
        'authors' => implode(', ', array_column($a['authors'], 'name')),
    ], $t['abstracts'])], $book['tracks']);
}

// ---------------------------------------------------------------------------------------
// Statistics (ADM-8)
// ---------------------------------------------------------------------------------------

function absStats(PDO $pdo, array $eventRow): array
{
    $eventId = (int)$eventRow['id'];
    $s = absSettings($eventRow['settings'] ?? null);
    $count = static function (string $sql) use ($pdo, $eventId): array {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$eventId]);
        return array_map(static fn($r) => ['label' => $r['label'] ?? 'Not given', 'count' => (int)$r['n']], $stmt->fetchAll());
    };
    $live = "a.status NOT IN ('draft','withdrawn')";
    return [
        'byStatus' => $count("SELECT a.status AS label, COUNT(*) AS n FROM abs_abstracts a WHERE a.event_id = ? GROUP BY a.status ORDER BY n DESC"),
        'byTrack' => $count("SELECT t.name AS label, COUNT(*) AS n FROM abs_abstracts a LEFT JOIN abs_tracks t ON t.id = a.track_id
                             WHERE a.event_id = ? AND $live GROUP BY t.name ORDER BY n DESC"),
        // Country of the presenting author, which is what a programme reports.
        // falling back to the submitter's profile when the author list left it blank.
        'byCountry' => $count("SELECT COALESCE(NULLIF(au.country, ''), NULLIF(p.country, ''), 'Not given') AS label, COUNT(*) AS n FROM abs_abstracts a
                               LEFT JOIN abs_authors au ON au.abstract_id = a.id AND au.is_presenting = 1
                               LEFT JOIN abs_profiles p ON p.user_id = a.submitter_user_id
                               WHERE a.event_id = ? AND $live GROUP BY label ORDER BY n DESC"),
        'byType' => array_map(static fn($r) => ['label' => $s['presentationTypes'][$r['label']] ?? $r['label'], 'count' => $r['count']],
                              $count("SELECT a.preferred_type AS label, COUNT(*) AS n FROM abs_abstracts a WHERE a.event_id = ? AND $live
                                      GROUP BY a.preferred_type ORDER BY n DESC")),
        'perDay' => $count("SELECT DATE(a.submitted_at) AS label, COUNT(*) AS n FROM abs_abstracts a
                            WHERE a.event_id = ? AND a.submitted_at IS NOT NULL GROUP BY DATE(a.submitted_at) ORDER BY label"),
    ];
}
