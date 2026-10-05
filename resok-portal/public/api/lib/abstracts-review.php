<?php
declare(strict_types=1);

/**
 * Abstract review (phase 2): roles, reviewer invitations, assignment, blind scoring,
 * progress and reminders. Builds on lib/abstracts.php.
 *
 * Four rules:
 *
 * 1. Access is decided on the server for every request (NFR-4). absAccess() says what the
 *    signed-in person may do in an event; nothing a page sends widens it. A track chair's
 *    reach is their tracks, checked against each abstract, not trusted from the request.
 *
 * 2. Blind means blind. absReviewForReviewer() builds the reviewer's copy of an abstract and
 *    leaves the authors out entirely under double-blind review - not hidden by the page, but
 *    never sent - and no reviewer ever receives another reviewer's scores or name (REV-11).
 *
 * 3. Conflicts are refused, not warned about (REV-4). absConflict() runs on every
 *    assignment, manual or automatic, so no route around it exists.
 *
 * 4. Once a review is submitted it is evidence. Unassigning a reviewer cancels only work
 *    not yet submitted; a submitted review stays on the record whatever happens next.
 */

const ABS_ACTIVE_REVIEW = ['assigned', 'in_progress', 'submitted'];

/** Webmail domains are shared by strangers, so a shared one says nothing about a conflict. */
const ABS_GENERIC_DOMAINS = ['gmail.com', 'googlemail.com', 'yahoo.com', 'yahoo.co.uk', 'ymail.com', 'hotmail.com',
                             'outlook.com', 'live.com', 'msn.com', 'icloud.com', 'me.com', 'aol.com', 'proton.me',
                             'protonmail.com', 'gmx.com', 'zoho.com', 'mail.com', 'yandex.com'];

// ---------------------------------------------------------------------------------------
// Access
// ---------------------------------------------------------------------------------------

/**
 * What the signed-in person may do in one event.
 *
 * @return array{admin: bool, programme: bool, trackIds: ?array, chair: bool, reviewer: bool}
 *         trackIds is null when their reach is every track.
 */
function absAccess(PDO $pdo, array $user, array $config, int $eventId): array
{
    $userId = (int)($user['userId'] ?? 0);
    $stmt = $pdo->prepare('SELECT role, track_id FROM abs_roles WHERE event_id = ? AND user_id = ?');
    $stmt->execute([$eventId, $userId]);
    $roles = $stmt->fetchAll();
    $has = static fn(string $r) => (bool)array_filter($roles, static fn($x) => $x['role'] === $r);

    $admin = (function_exists('isSuperAdmin') && isSuperAdmin($user, $config)) || $has('administrator');
    $programme = $admin || $has('programme_chair');
    $trackIds = null;
    if (!$programme) {
        $trackIds = array_values(array_unique(array_map('intval', array_column(
            array_filter($roles, static fn($x) => $x['role'] === 'track_chair' && $x['track_id'] !== null), 'track_id'))));
    }
    return [
        'admin' => $admin,
        'programme' => $programme,
        'trackIds' => $trackIds,
        'chair' => $programme || !empty($trackIds),
        'reviewer' => $has('reviewer'),
    ];
}

/** Whether this access reaches an abstract in the given track. */
function absReaches(array $access, $trackId): bool
{
    if ($access['programme']) return true;
    return $trackId !== null && in_array((int)$trackId, $access['trackIds'] ?? [], true);
}

/** Events the person chairs or administers - all of them for a super administrator. */
function absChairEvents(PDO $pdo, array $user, array $config): array
{
    if (function_exists('isSuperAdmin') && isSuperAdmin($user, $config)) return absEventsList($pdo);
    $stmt = $pdo->prepare("SELECT DISTINCT event_id FROM abs_roles WHERE user_id = ? AND role IN ('administrator','programme_chair','track_chair')");
    $stmt->execute([(int)($user['userId'] ?? 0)]);
    $ids = array_map('intval', array_column($stmt->fetchAll(), 'event_id'));
    return array_values(array_filter(absEventsList($pdo), static fn($e) => in_array($e['id'], $ids, true)));
}

// ---------------------------------------------------------------------------------------
// Roles (ADM-4)
// ---------------------------------------------------------------------------------------

function absPersonName(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare('SELECT u.email, p.title, p.first_name, p.last_name, p.affiliation
                           FROM users u LEFT JOIN abs_profiles p ON p.user_id = u.id WHERE u.id = ?');
    $stmt->execute([$userId]);
    $r = $stmt->fetch() ?: [];
    $name = trim(implode(' ', array_filter([$r['title'] ?? '', $r['first_name'] ?? '', $r['last_name'] ?? ''])));
    if ($name === '') {
        $profile = absProfile($pdo, $userId);
        $name = trim($profile['firstName'] . ' ' . $profile['lastName']);
    }
    return ['name' => $name !== '' ? $name : (string)($r['email'] ?? ''), 'email' => (string)($r['email'] ?? ''),
            'affiliation' => $r['affiliation'] ?? null];
}

function absRolesList(PDO $pdo, int $eventId): array
{
    $stmt = $pdo->prepare("SELECT r.id, r.user_id, r.role, r.track_id, t.name AS track_name FROM abs_roles r
                           LEFT JOIN abs_tracks t ON t.id = r.track_id
                           WHERE r.event_id = ? AND r.role <> 'reviewer' ORDER BY r.role, r.id");
    $stmt->execute([$eventId]);
    return array_map(static function ($r) use ($pdo) {
        $p = absPersonName($pdo, (int)$r['user_id']);
        return ['id' => (int)$r['id'], 'userId' => (int)$r['user_id'], 'name' => $p['name'], 'email' => $p['email'],
                'role' => $r['role'], 'trackId' => $r['track_id'] !== null ? (int)$r['track_id'] : null, 'trackName' => $r['track_name']];
    }, $stmt->fetchAll());
}

/** @return ?string an error, or null when granted */
function absRoleGrant(PDO $pdo, int $eventId, string $email, string $role, ?int $trackId, int $by): ?string
{
    if (!in_array($role, ['track_chair', 'programme_chair', 'administrator'], true)) return 'Unknown role.';
    $stmt = $pdo->prepare('SELECT id FROM users WHERE LOWER(email) = ? LIMIT 1');
    $stmt->execute([strtolower(trim($email))]);
    $userId = (int)$stmt->fetchColumn();
    if (!$userId) return 'No account uses that email. Ask them to create one on the abstracts page first.';
    if ($role === 'track_chair') {
        $t = $pdo->prepare('SELECT id FROM abs_tracks WHERE id = ? AND event_id = ? AND active = 1');
        $t->execute([(int)$trackId, $eventId]);
        if (!$t->fetch()) return 'Choose the track they will chair.';
    } else {
        $trackId = null;
    }
    $exists = $pdo->prepare('SELECT id FROM abs_roles WHERE event_id = ? AND user_id = ? AND role = ? AND track_id <=> ?');
    $exists->execute([$eventId, $userId, $role, $trackId]);
    if ($exists->fetch()) return 'They already hold that role.';
    $pdo->prepare('INSERT INTO abs_roles (event_id, user_id, role, track_id, granted_by) VALUES (?, ?, ?, ?, ?)')
        ->execute([$eventId, $userId, $role, $trackId, $by]);
    absAudit($pdo, $eventId, null, $by, 'role_granted', null, null, ['email' => $email, 'role' => $role, 'trackId' => $trackId]);
    return null;
}

function absRoleRevoke(PDO $pdo, int $eventId, int $roleId, int $by): ?string
{
    $stmt = $pdo->prepare("SELECT r.*, u.email FROM abs_roles r JOIN users u ON u.id = r.user_id WHERE r.id = ? AND r.event_id = ?");
    $stmt->execute([$roleId, $eventId]);
    $r = $stmt->fetch();
    if (!$r) return 'No such role.';
    $pdo->prepare('DELETE FROM abs_roles WHERE id = ?')->execute([$roleId]);
    absAudit($pdo, $eventId, null, $by, 'role_revoked', null, null, ['email' => $r['email'], 'role' => $r['role']]);
    return null;
}

// ---------------------------------------------------------------------------------------
// Reviewers and invitations (REV-1, INT-6)
// ---------------------------------------------------------------------------------------

function absReviewUrl(array $config, string $suffix = ''): string
{
    return rtrim((string)($config['portal_base_url'] ?? ''), '/') . '/abstracts-review' . $suffix;
}

/**
 * Invites someone to review. Only a hash of the link's token is stored, so a copy of the
 * database cannot be used to accept an invitation in somebody's name.
 *
 * @return ?string an error, or null when sent
 */
function absInvite(PDO $pdo, array $config, array $eventRow, string $email, string $name, string $message, int $by): ?string
{
    $email = strtolower(trim($email));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return "\"{$email}\" is not a valid email address.";
    $eventId = (int)$eventRow['id'];
    $already = $pdo->prepare("SELECT 1 FROM abs_roles r JOIN users u ON u.id = r.user_id
                              WHERE r.event_id = ? AND r.role = 'reviewer' AND LOWER(u.email) = ?");
    $already->execute([$eventId, $email]);
    if ($already->fetch()) return "{$email} is already a reviewer for this event.";

    $token = bin2hex(random_bytes(24));
    $hash = hash('sha256', $token);
    $pending = $pdo->prepare("SELECT id FROM abs_invitations WHERE event_id = ? AND email = ? AND status = 'pending' LIMIT 1");
    $pending->execute([$eventId, $email]);
    $name = mb_substr(trim($name), 0, 160);
    $message = mb_substr(trim($message), 0, 1000);
    if ($id = (int)$pending->fetchColumn()) {
        // Inviting again replaces the old link rather than leaving two that both work.
        $pdo->prepare('UPDATE abs_invitations SET token_hash = ?, name = COALESCE(NULLIF(?, ""), name), message = ?, invited_by = ? WHERE id = ?')
            ->execute([$hash, $name, $message !== '' ? $message : null, $by, $id]);
    } else {
        $pdo->prepare('INSERT INTO abs_invitations (event_id, email, name, token_hash, invited_by, message) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$eventId, $email, $name !== '' ? $name : null, $hash, $by, $message !== '' ? $message : null]);
    }

    absSendTemplate($pdo, $config, $eventId, null, 'reviewer_invitation', $email, [
        'name' => $name !== '' ? $name : 'colleague', 'event' => (string)$eventRow['name'], 'message' => $message,
        'review_deadline' => absDeadlineText($eventRow, 'review_deadline'), 'link' => absReviewUrl($config, '?invite=' . $token),
    ]);
    absAudit($pdo, $eventId, null, $by, 'reviewer_invited', null, null, ['email' => $email]);
    return null;
}

function absInvitationByToken(PDO $pdo, string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{48}$/', $token)) return null;
    $stmt = $pdo->prepare('SELECT * FROM abs_invitations WHERE token_hash = ? LIMIT 1');
    $stmt->execute([hash('sha256', $token)]);
    return $stmt->fetch() ?: null;
}

/**
 * Accepts or declines. Accepting needs a signed-in account (the reviewer works under it);
 * the account's details complete the reviewer's profile, which conflict checks rely on.
 *
 * @return ?string an error, or null when recorded
 */
function absInvitationRespond(PDO $pdo, array $invitation, ?int $userId, bool $accept, array $data): ?string
{
    if ($invitation['status'] !== 'pending') return 'This invitation has already been ' . $invitation['status'] . '.';
    $eventId = (int)$invitation['event_id'];
    if (!$accept) {
        $pdo->prepare("UPDATE abs_invitations SET status = 'declined', responded_at = NOW(), user_id = ? WHERE id = ?")
            ->execute([$userId, (int)$invitation['id']]);
        absAudit($pdo, $eventId, null, $userId, 'invitation_declined', null, null, ['email' => $invitation['email']]);
        return null;
    }
    if (!$userId) return 'Please sign in or create an account to accept.';
    $profileError = absProfileSave($pdo, $userId, $data);
    if ($profileError) return $profileError;
    $error = absReviewerProfileSave($pdo, $eventId, $userId, $data);
    if ($error) return $error;
    $pdo->prepare("INSERT IGNORE INTO abs_roles (event_id, user_id, role, granted_by) VALUES (?, ?, 'reviewer', ?)")
        ->execute([$eventId, $userId, $invitation['invited_by']]);
    $pdo->prepare("UPDATE abs_invitations SET status = 'accepted', responded_at = NOW(), user_id = ? WHERE id = ?")
        ->execute([$userId, (int)$invitation['id']]);
    absAudit($pdo, $eventId, null, $userId, 'invitation_accepted', null, null, ['email' => $invitation['email']]);
    return null;
}

/** The tracks a reviewer covers, their expertise and how many abstracts they will take. */
function absReviewerProfileSave(PDO $pdo, int $eventId, int $userId, array $data): ?string
{
    $tracks = [];
    foreach (is_array($data['tracks'] ?? null) ? $data['tracks'] : [] as $t) {
        $stmt = $pdo->prepare('SELECT id FROM abs_tracks WHERE id = ? AND event_id = ? AND active = 1');
        $stmt->execute([(int)$t, $eventId]);
        if ($stmt->fetch()) $tracks[] = (int)$t;
    }
    if (!$tracks) return 'Choose at least one track you can review.';
    $expertise = mb_substr(trim((string)($data['expertise'] ?? '')), 0, 500);
    $load = is_numeric($data['maxLoad'] ?? null) && (int)$data['maxLoad'] > 0 ? min(200, (int)$data['maxLoad']) : null;
    $pdo->prepare('INSERT INTO abs_reviewers (event_id, user_id, tracks, expertise, max_load) VALUES (?, ?, ?, ?, ?)
                   ON DUPLICATE KEY UPDATE tracks = VALUES(tracks), expertise = VALUES(expertise), max_load = VALUES(max_load)')
        ->execute([$eventId, $userId, json_encode(array_values(array_unique($tracks))), $expertise !== '' ? $expertise : null, $load]);
    return null;
}

function absInvitationsList(PDO $pdo, int $eventId): array
{
    $stmt = $pdo->prepare("SELECT id, email, name, status, created_at, responded_at FROM abs_invitations
                           WHERE event_id = ? AND status <> 'accepted' ORDER BY created_at DESC");
    $stmt->execute([$eventId]);
    return array_map(static fn($r) => ['id' => (int)$r['id'], 'email' => $r['email'], 'name' => $r['name'],
        'status' => $r['status'], 'invitedAt' => $r['created_at'], 'respondedAt' => $r['responded_at']], $stmt->fetchAll());
}

/** Bulk re-invite of an earlier event's reviewers (INT-6). @return array{invited:int, skipped:int} */
function absReinvitePrevious(PDO $pdo, array $config, array $eventRow, int $fromEventId, int $by): array
{
    $stmt = $pdo->prepare("SELECT u.email, p.first_name, p.last_name FROM abs_roles r JOIN users u ON u.id = r.user_id
                           LEFT JOIN abs_profiles p ON p.user_id = u.id WHERE r.event_id = ? AND r.role = 'reviewer'");
    $stmt->execute([$fromEventId]);
    $invited = $skipped = 0;
    foreach ($stmt->fetchAll() as $r) {
        $pending = $pdo->prepare("SELECT 1 FROM abs_invitations WHERE event_id = ? AND email = ? AND status IN ('pending','declined')");
        $pending->execute([(int)$eventRow['id'], strtolower($r['email'])]);
        if ($pending->fetch()) { $skipped++; continue; }
        $error = absInvite($pdo, $config, $eventRow, (string)$r['email'], trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')), '', $by);
        $error ? $skipped++ : $invited++;
    }
    return ['invited' => $invited, 'skipped' => $skipped];
}

/** Reviewers of an event with their workload (REV-12, per reviewer). */
function absReviewersList(PDO $pdo, array $eventRow): array
{
    $eventId = (int)$eventRow['id'];
    $overdue = absReviewOverdue($eventRow);
    $stmt = $pdo->prepare("SELECT r.user_id, v.tracks, v.expertise, v.max_load,
                                  SUM(rv.status IN ('assigned','in_progress')) AS open_count,
                                  SUM(rv.status = 'submitted') AS done_count,
                                  SUM(rv.status = 'declined') AS declined_count
                           FROM abs_roles r
                           LEFT JOIN abs_reviewers v ON v.event_id = r.event_id AND v.user_id = r.user_id
                           LEFT JOIN abs_reviews rv ON rv.reviewer_user_id = r.user_id
                                 AND rv.abstract_id IN (SELECT id FROM abs_abstracts WHERE event_id = r.event_id)
                           WHERE r.event_id = ? AND r.role = 'reviewer'
                           GROUP BY r.user_id, v.tracks, v.expertise, v.max_load");
    $stmt->execute([$eventId]);
    $tracks = array_column(absTracks($pdo, $eventId, false), 'name', 'id');
    return array_map(static function ($r) use ($pdo, $tracks, $overdue) {
        $p = absPersonName($pdo, (int)$r['user_id']);
        $ids = json_decode((string)($r['tracks'] ?? '[]'), true) ?: [];
        $open = (int)$r['open_count'];
        return [
            'userId' => (int)$r['user_id'], 'name' => $p['name'], 'email' => $p['email'], 'affiliation' => $p['affiliation'],
            'trackIds' => array_map('intval', $ids), 'tracks' => array_values(array_filter(array_map(static fn($i) => $tracks[$i] ?? null, $ids))),
            'expertise' => $r['expertise'], 'maxLoad' => $r['max_load'] !== null ? (int)$r['max_load'] : null,
            'open' => $open, 'completed' => (int)$r['done_count'], 'declined' => (int)$r['declined_count'],
            'overdue' => $overdue ? $open : 0,
        ];
    }, $stmt->fetchAll());
}

/** True once the review deadline has passed. */
function absReviewOverdue(array $eventRow): bool
{
    $zone = absZone($eventRow);
    $deadline = absAt($eventRow['review_deadline'] ?? null, $zone);
    return $deadline !== null && new DateTimeImmutable('now', $zone) > $deadline;
}

// ---------------------------------------------------------------------------------------
// Conflicts of interest (REV-4)
// ---------------------------------------------------------------------------------------

function absNormAffiliation(?string $a): string
{
    $a = strtolower(trim((string)$a));
    $a = preg_replace('/\b(the|of|and)\b/', ' ', $a);
    return trim((string)preg_replace('/[^a-z0-9]+/', ' ', (string)$a));
}

function absEmailDomain(string $email): string
{
    $at = strrpos($email, '@');
    return $at === false ? '' : strtolower(substr($email, $at + 1));
}

/** Why this reviewer may not review this abstract, or null when they may. */
function absConflict(PDO $pdo, int $abstractId, int $reviewerUserId): ?string
{
    $abs = $pdo->prepare('SELECT submitter_user_id FROM abs_abstracts WHERE id = ?');
    $abs->execute([$abstractId]);
    $submitter = (int)$abs->fetchColumn();
    if ($submitter === $reviewerUserId) return 'The reviewer submitted this abstract.';

    $who = $pdo->prepare('SELECT u.email, p.affiliation FROM users u LEFT JOIN abs_profiles p ON p.user_id = u.id WHERE u.id = ?');
    $who->execute([$reviewerUserId]);
    $reviewer = $who->fetch() ?: ['email' => '', 'affiliation' => ''];
    $rEmail = strtolower((string)$reviewer['email']);
    $rDomain = absEmailDomain($rEmail);
    $rAff = absNormAffiliation($reviewer['affiliation'] ?? '');

    $authors = $pdo->prepare('SELECT email, affiliation, user_id FROM abs_authors WHERE abstract_id = ?');
    $authors->execute([$abstractId]);
    foreach ($authors->fetchAll() as $a) {
        $aEmail = strtolower((string)$a['email']);
        if ((int)$a['user_id'] === $reviewerUserId || ($aEmail !== '' && $aEmail === $rEmail)) {
            return 'The reviewer is an author of this abstract.';
        }
        $domain = absEmailDomain($aEmail);
        if ($rDomain !== '' && $domain === $rDomain && !in_array($domain, ABS_GENERIC_DOMAINS, true)) {
            return "The reviewer shares an email domain ({$domain}) with an author.";
        }
        if ($rAff !== '' && absNormAffiliation($a['affiliation']) === $rAff) {
            return 'The reviewer and an author have the same affiliation.';
        }
    }
    return null;
}

// ---------------------------------------------------------------------------------------
// Assignment (REV-2, REV-3, REV-5)
// ---------------------------------------------------------------------------------------

/**
 * Assigns one reviewer to one abstract. Notifying the reviewer is the caller's job, so a
 * bulk assignment sends each reviewer one email rather than one per abstract.
 *
 * @return ?string an error, or null when assigned
 */
function absAssign(PDO $pdo, array $eventRow, array $access, int $abstractId, int $reviewerUserId, int $by): ?string
{
    $row = absAbstractRow($pdo, $abstractId);
    if (!$row || (int)$row['event_id'] !== (int)$eventRow['id']) return 'No such abstract in this event.';
    $label = $row['reference'] ?? ('#' . $abstractId);
    if (!absReaches($access, $row['track_id'])) return "{$label} is outside the tracks you chair.";
    if (!in_array($row['status'], ['submitted', 'under_review'], true)) {
        return "{$label} is " . str_replace('_', ' ', $row['status']) . ' and cannot be assigned.';
    }
    $role = $pdo->prepare("SELECT 1 FROM abs_roles WHERE event_id = ? AND user_id = ? AND role = 'reviewer'");
    $role->execute([(int)$eventRow['id'], $reviewerUserId]);
    if (!$role->fetch()) return 'That person is not a reviewer for this event.';
    if ($conflict = absConflict($pdo, $abstractId, $reviewerUserId)) return "{$label}: {$conflict}";

    $existing = $pdo->prepare('SELECT id, status FROM abs_reviews WHERE abstract_id = ? AND reviewer_user_id = ?');
    $existing->execute([$abstractId, $reviewerUserId]);
    $prior = $existing->fetch();
    if ($prior && in_array($prior['status'], ABS_ACTIVE_REVIEW, true)) return "{$label} is already assigned to this reviewer.";
    if ($prior && $prior['status'] === 'declined') return "This reviewer declined {$label}.";
    if ($prior) {
        $pdo->prepare("UPDATE abs_reviews SET status = 'assigned', assigned_by = ?, assigned_at = NOW(), decline_reason = NULL WHERE id = ?")
            ->execute([$by, (int)$prior['id']]);
    } else {
        $pdo->prepare('INSERT INTO abs_reviews (abstract_id, reviewer_user_id, assigned_by) VALUES (?, ?, ?)')
            ->execute([$abstractId, $reviewerUserId, $by]);
    }
    if ($row['status'] === 'submitted') {
        $pdo->prepare("UPDATE abs_abstracts SET status = 'under_review' WHERE id = ?")->execute([$abstractId]);
        absAudit($pdo, (int)$row['event_id'], $abstractId, $by, 'review_started', 'submitted', 'under_review');
    }
    absAudit($pdo, (int)$row['event_id'], $abstractId, $by, 'reviewer_assigned', null, null, ['reviewer' => absPersonName($pdo, $reviewerUserId)['email']]);
    return null;
}

/** An abstract with no live review left goes back to Submitted, ready to be reassigned. */
function absSettleAfterRemoval(PDO $pdo, int $abstractId, ?int $by): void
{
    $count = $pdo->prepare("SELECT COUNT(*) FROM abs_reviews WHERE abstract_id = ? AND status IN ('assigned','in_progress','submitted')");
    $count->execute([$abstractId]);
    if ((int)$count->fetchColumn() > 0) return;
    $row = absAbstractRow($pdo, $abstractId);
    if ($row && $row['status'] === 'under_review') {
        $pdo->prepare("UPDATE abs_abstracts SET status = 'submitted' WHERE id = ?")->execute([$abstractId]);
        absAudit($pdo, (int)$row['event_id'], $abstractId, $by, 'review_reset', 'under_review', 'submitted');
    }
}

/** @return ?string an error, or null when removed */
function absUnassign(PDO $pdo, array $eventRow, array $access, int $reviewId, int $by): ?string
{
    $stmt = $pdo->prepare('SELECT rv.*, a.event_id, a.track_id FROM abs_reviews rv JOIN abs_abstracts a ON a.id = rv.abstract_id WHERE rv.id = ?');
    $stmt->execute([$reviewId]);
    $rv = $stmt->fetch();
    if (!$rv || (int)$rv['event_id'] !== (int)$eventRow['id']) return 'No such assignment.';
    if (!absReaches($access, $rv['track_id'])) return 'That abstract is outside the tracks you chair.';
    if ($rv['status'] === 'submitted') return 'A submitted review stays on the record and cannot be unassigned.';
    if (!in_array($rv['status'], ['assigned', 'in_progress'], true)) return 'That assignment is no longer active.';
    $pdo->prepare("UPDATE abs_reviews SET status = 'cancelled' WHERE id = ?")->execute([$reviewId]);
    absAudit($pdo, (int)$rv['event_id'], (int)$rv['abstract_id'], $by, 'reviewer_unassigned', null, null, ['reviewer' => absPersonName($pdo, (int)$rv['reviewer_user_id'])['email']]);
    absSettleAfterRemoval($pdo, (int)$rv['abstract_id'], $by);
    return null;
}

/**
 * Proposes reviewers for every abstract short of its quota (REV-3). Nothing is written:
 * the chair sees the proposal and applies it, and each pair is checked again then.
 *
 * Abstracts with the fewest eligible reviewers are filled first, so the scarce experts go
 * where only they can go. For each, the reviewer chosen is the best topic match - their
 * track first, then keywords found in their expertise - and among equals the least loaded.
 *
 * @return array{pairs: array, unfilled: array}
 */
function absAutoProposal(PDO $pdo, array $eventRow, array $access): array
{
    $eventId = (int)$eventRow['id'];
    $need = absSettings($eventRow['settings'] ?? null)['reviewsPerAbstract'];
    $reviewers = [];
    foreach (absReviewersList($pdo, $eventRow) as $r) {
        $r['load'] = $r['open'] + $r['completed'];
        $r['words'] = array_filter(preg_split('/[^a-z0-9]+/', strtolower((string)$r['expertise'])) ?: [], static fn($w) => strlen($w) > 3);
        $reviewers[$r['userId']] = $r;
    }

    $stmt = $pdo->prepare("SELECT a.id, a.reference, a.title, a.track_id, a.keywords,
                                  (SELECT COUNT(*) FROM abs_reviews rv WHERE rv.abstract_id = a.id AND rv.status IN ('assigned','in_progress','submitted')) AS active
                           FROM abs_abstracts a WHERE a.event_id = ? AND a.status IN ('submitted','under_review')");
    $stmt->execute([$eventId]);
    $paired = [];
    foreach ($pdo->query("SELECT abstract_id, reviewer_user_id FROM abs_reviews")->fetchAll() as $p) {
        $paired[$p['abstract_id'] . ':' . $p['reviewer_user_id']] = true;
    }

    $work = [];
    foreach ($stmt->fetchAll() as $a) {
        if (!absReaches($access, $a['track_id'])) continue;
        $missing = $need - (int)$a['active'];
        if ($missing <= 0) continue;
        $keywords = array_map('strtolower', json_decode((string)($a['keywords'] ?? '[]'), true) ?: []);
        $candidates = [];
        foreach ($reviewers as $uid => $r) {
            if (isset($paired[$a['id'] . ':' . $uid])) continue;
            if (absConflict($pdo, (int)$a['id'], $uid)) continue;
            $score = in_array((int)$a['track_id'], $r['trackIds'], true) ? 10 : ($r['trackIds'] ? 0 : 3);
            foreach ($keywords as $k) {
                foreach (preg_split('/[^a-z0-9]+/', $k) ?: [] as $kw) {
                    if (strlen($kw) > 3 && in_array($kw, $r['words'], true)) $score += 2;
                }
            }
            $candidates[$uid] = $score;
        }
        $work[] = ['abstract' => $a, 'missing' => $missing, 'candidates' => $candidates];
    }
    usort($work, static fn($x, $y) => count($x['candidates']) <=> count($y['candidates']));

    $pairs = [];
    $unfilled = [];
    foreach ($work as $w) {
        $a = $w['abstract'];
        for ($i = 0; $i < $w['missing']; $i++) {
            $best = null;
            foreach ($w['candidates'] as $uid => $score) {
                $r = $reviewers[$uid];
                if ($r['maxLoad'] !== null && $r['load'] >= $r['maxLoad']) continue;
                if ($best === null || $score > $best[1] || ($score === $best[1] && $r['load'] < $reviewers[$best[0]]['load'])) {
                    $best = [$uid, $score];
                }
            }
            if ($best === null) {
                $unfilled[] = ['abstractId' => (int)$a['id'], 'reference' => $a['reference'], 'title' => $a['title'],
                               'missing' => $w['missing'] - $i];
                break;
            }
            [$uid, $score] = $best;
            unset($w['candidates'][$uid]);
            $reviewers[$uid]['load']++;
            $pairs[] = ['abstractId' => (int)$a['id'], 'reference' => $a['reference'], 'title' => $a['title'],
                        'reviewerUserId' => $uid, 'reviewerName' => $reviewers[$uid]['name'],
                        'match' => $score >= 10 ? 'track' . ($score > 10 ? ' + keywords' : '') : ($score > 0 ? 'keywords' : 'workload only')];
        }
    }
    return ['pairs' => $pairs, 'unfilled' => $unfilled];
}

/**
 * Applies a list of abstract/reviewer pairs, checking each again, and emails each reviewer
 * once with how many abstracts they were given.
 *
 * @return array{assigned:int, errors: string[]}
 */
function absAssignMany(PDO $pdo, array $config, array $eventRow, array $access, array $pairs, int $by): array
{
    $assigned = 0;
    $errors = [];
    $perReviewer = [];
    foreach ($pairs as $p) {
        $abstractId = (int)($p['abstractId'] ?? 0);
        $reviewer = (int)($p['reviewerUserId'] ?? 0);
        if (!$abstractId || !$reviewer) continue;
        $error = absAssign($pdo, $eventRow, $access, $abstractId, $reviewer, $by);
        if ($error) { $errors[] = $error; continue; }
        $assigned++;
        $perReviewer[$reviewer] = ($perReviewer[$reviewer] ?? 0) + 1;
    }
    foreach ($perReviewer as $reviewer => $count) absNotifyAssigned($pdo, $config, $eventRow, $reviewer, $count);
    return ['assigned' => $assigned, 'errors' => $errors];
}

function absNotifyAssigned(PDO $pdo, array $config, array $eventRow, int $reviewerUserId, int $count): void
{
    $p = absPersonName($pdo, $reviewerUserId);
    if ($p['email'] === '') return;
    absSendTemplate($pdo, $config, (int)$eventRow['id'], null, 'review_assignment', $p['email'], [
        'name' => $p['name'], 'event' => (string)$eventRow['name'], 'count' => $count,
        'review_deadline' => absDeadlineText($eventRow, 'review_deadline'), 'link' => absReviewUrl($config),
    ]);
}

// ---------------------------------------------------------------------------------------
// The chair's view (REV-12, REV-14)
// ---------------------------------------------------------------------------------------

/** Every abstract in reach with its reviews, average, spread and whether it is flagged. */
function absReviewBoard(PDO $pdo, array $eventRow, array $access): array
{
    $s = absSettings($eventRow['settings'] ?? null);
    $stmt = $pdo->prepare("SELECT a.id, a.reference, a.title, a.status, a.track_id, t.name AS track_name, a.preferred_type
                           FROM abs_abstracts a LEFT JOIN abs_tracks t ON t.id = a.track_id
                           WHERE a.event_id = ? AND a.status NOT IN ('draft','withdrawn')
                           ORDER BY a.reference");
    $stmt->execute([(int)$eventRow['id']]);
    $reviews = $pdo->prepare("SELECT id, reviewer_user_id, status, total, recommendation, decline_reason FROM abs_reviews
                              WHERE abstract_id = ? AND status <> 'cancelled' ORDER BY id");
    $names = [];
    $out = [];
    foreach ($stmt->fetchAll() as $a) {
        if (!absReaches($access, $a['track_id'])) continue;
        $reviews->execute([(int)$a['id']]);
        $list = [];
        $totals = [];
        foreach ($reviews->fetchAll() as $r) {
            $uid = (int)$r['reviewer_user_id'];
            $names[$uid] = $names[$uid] ?? absPersonName($pdo, $uid)['name'];
            $list[] = ['id' => (int)$r['id'], 'reviewerUserId' => $uid, 'reviewer' => $names[$uid], 'status' => $r['status'],
                       'total' => $r['total'] !== null ? (float)$r['total'] : null, 'recommendation' => $r['recommendation'],
                       'declineReason' => $r['decline_reason']];
            if ($r['status'] === 'submitted' && $r['total'] !== null) $totals[] = (float)$r['total'];
        }
        $active = count(array_filter($list, static fn($r) => in_array($r['status'], ABS_ACTIVE_REVIEW, true)));
        $spread = count($totals) >= 2 ? max($totals) - min($totals) : null;
        $out[] = [
            'id' => (int)$a['id'], 'reference' => $a['reference'], 'title' => $a['title'], 'status' => $a['status'],
            'trackId' => $a['track_id'] !== null ? (int)$a['track_id'] : null, 'trackName' => $a['track_name'],
            'preferredType' => $a['preferred_type'], 'reviews' => $list, 'active' => $active, 'needed' => max(0, $s['reviewsPerAbstract'] - $active),
            'completed' => count($totals), 'average' => $totals ? round(array_sum($totals) / count($totals), 2) : null,
            'spread' => $spread !== null ? round($spread, 2) : null,
            'flagged' => $spread !== null && $spread > $s['discrepancyThreshold'],
        ];
    }
    return $out;
}

/** Progress per track (REV-12, per track); per reviewer comes from absReviewersList. */
function absProgressByTrack(array $board, array $tracks, int $perAbstract): array
{
    $out = [];
    foreach ($tracks as $t) {
        $mine = array_filter($board, static fn($a) => $a['trackId'] === $t['id']);
        $out[] = ['trackId' => $t['id'], 'track' => $t['name'], 'abstracts' => count($mine),
                  'fullyAssigned' => count(array_filter($mine, static fn($a) => $a['needed'] === 0)),
                  'fullyReviewed' => count(array_filter($mine, static fn($a) => $a['completed'] >= $perAbstract)),
                  'reviewsDone' => array_sum(array_column($mine, 'completed')), 'reviewsWanted' => count($mine) * $perAbstract];
    }
    return $out;
}

// ---------------------------------------------------------------------------------------
// The reviewer's side (REV-5..11)
// ---------------------------------------------------------------------------------------

function absMyReviews(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare("SELECT rv.id, rv.status, rv.total, rv.submitted_at, a.reference, a.title, t.name AS track_name,
                                  e.id AS event_id, e.name AS event_name, e.review_deadline, e.timezone
                           FROM abs_reviews rv JOIN abs_abstracts a ON a.id = rv.abstract_id
                           JOIN abs_events e ON e.id = a.event_id LEFT JOIN abs_tracks t ON t.id = a.track_id
                           WHERE rv.reviewer_user_id = ? AND rv.status <> 'cancelled' AND e.status <> 'archived'
                           ORDER BY e.id DESC, FIELD(rv.status, 'assigned', 'in_progress', 'submitted', 'declined'), a.reference");
    $stmt->execute([$userId]);
    return array_map(static function ($r) {
        $zone = absZone($r);
        return ['id' => (int)$r['id'], 'status' => $r['status'], 'total' => $r['total'] !== null ? (float)$r['total'] : null,
                'reference' => $r['reference'], 'title' => $r['title'], 'trackName' => $r['track_name'],
                'eventId' => (int)$r['event_id'], 'eventName' => $r['event_name'],
                'reviewDeadline' => absIso($r['review_deadline'], $zone), 'submittedAt' => absIso($r['submitted_at'], $zone)];
    }, $stmt->fetchAll());
}

/** The reviewer's own review row with its event, or null if it is not theirs. */
function absOwnReview(PDO $pdo, int $reviewId, int $userId): ?array
{
    $stmt = $pdo->prepare("SELECT rv.*, a.event_id FROM abs_reviews rv JOIN abs_abstracts a ON a.id = rv.abstract_id
                           WHERE rv.id = ? AND rv.reviewer_user_id = ? AND rv.status <> 'cancelled'");
    $stmt->execute([$reviewId, $userId]);
    return $stmt->fetch() ?: null;
}

/** Whether the reviewer may still change the review, and why not. */
function absReviewEditable(array $eventRow, array $review): array
{
    if ($review['status'] === 'declined') return [false, 'You declined this abstract.'];
    if (absReviewOverdue($eventRow) && $review['status'] === 'submitted') return [false, 'The review deadline has passed.'];
    return [true, null];
}

/**
 * The abstract as a reviewer may see it. Under double-blind review the authors are not in
 * the response at all (REV-6); under single-blind they are; nobody else's review is ever
 * included (REV-11).
 */
function absReviewForReviewer(PDO $pdo, array $review, array $eventRow): array
{
    $s = absSettings($eventRow['settings'] ?? null);
    $a = absAbstractRow($pdo, (int)$review['abstract_id']);
    $sections = $a['sections'] ? json_decode((string)$a['sections'], true) : null;
    [$editable, $why] = absReviewEditable($eventRow, $review);
    $abstract = [
        'reference' => $a['reference'], 'title' => $a['title'], 'trackName' => $a['track_name'],
        'sections' => is_array($sections) ? $sections : null, 'body' => is_array($sections) ? null : (string)$a['body'],
        'keywords' => json_decode((string)($a['keywords'] ?? '[]'), true) ?: [],
        'preferredType' => $s['presentationTypes'][$a['preferred_type']] ?? $a['preferred_type'],
        'figures' => function_exists('absFigures') ? absFigures($pdo, (int)$a['id']) : [],
    ];
    if ($s['blind'] !== 'double') {
        $abstract['authors'] = array_map(static fn($x) => ['name' => trim($x['firstName'] . ' ' . $x['lastName']),
            'affiliation' => $x['affiliation'], 'presenting' => $x['presenting']], absAuthors($pdo, (int)$a['id']));
    }
    return [
        'id' => (int)$review['id'],
        'status' => $review['status'],
        'editable' => $editable,
        'lockedReason' => $why,
        'abstract' => $abstract,
        'blind' => $s['blind'],
        'criteria' => $s['criteria'],
        'scaleMax' => $s['scaleMax'],
        'presentationTypes' => $s['presentationTypes'],
        'reviewDeadline' => absIso($eventRow['review_deadline'] ?? null, absZone($eventRow)),
        'review' => [
            'scores' => json_decode((string)($review['scores'] ?? '{}'), true) ?: (object)[],
            'commentsAuthors' => (string)($review['comments_authors'] ?? ''),
            'commentsCommittee' => (string)($review['comments_committee'] ?? ''),
            'recommendation' => $review['recommendation'],
            'recommendedType' => $review['recommended_type'],
            'total' => $review['total'] !== null ? (float)$review['total'] : null,
        ],
    ];
}

/**
 * Saves or submits a review (REV-7..10). Saving keeps whatever is filled in; submitting
 * needs every score, a recommendation and a comment for the authors. A submitted review can
 * still be edited until the review deadline.
 *
 * @return array{0: bool, 1: ?string, 2: array} [ok, error, field errors]
 */
function absReviewSave(PDO $pdo, array $eventRow, array $review, array $data, bool $submit, int $userId): array
{
    [$editable, $why] = absReviewEditable($eventRow, $review);
    if (!$editable) return [false, $why, []];
    $s = absSettings($eventRow['settings'] ?? null);
    $errors = [];
    $scores = [];
    $incoming = is_array($data['scores'] ?? null) ? $data['scores'] : [];
    foreach ($s['criteria'] as $c) {
        $v = $incoming[$c['key']] ?? null;
        if ($v === null || $v === '') {
            if ($submit) $errors['score:' . $c['key']] = 'Score ' . $c['label'] . '.';
            continue;
        }
        if (!is_numeric($v) || (int)$v < 1 || (int)$v > $s['scaleMax']) {
            $errors['score:' . $c['key']] = "Scores run from 1 to {$s['scaleMax']}.";
            continue;
        }
        $scores[$c['key']] = (int)$v;
    }
    $authors = mb_substr(trim((string)($data['commentsAuthors'] ?? '')), 0, 8000);
    $committee = mb_substr(trim((string)($data['commentsCommittee'] ?? '')), 0, 8000);
    $recommendation = in_array($data['recommendation'] ?? '', ['accept', 'reject'], true) ? $data['recommendation'] : null;
    $type = array_key_exists((string)($data['recommendedType'] ?? ''), $s['presentationTypes']) ? (string)$data['recommendedType'] : null;
    if ($submit && $authors === '') $errors['commentsAuthors'] = 'Add a comment for the authors.';
    if ($submit && $recommendation === null) $errors['recommendation'] = 'Recommend accept or reject.';
    if ($errors) return [false, $submit ? 'Some parts of the review are missing.' : 'Some scores are out of range.', $errors];

    // The total is the weighted mean of the scores given, on the same 1..scaleMax scale.
    $total = null;
    $weights = 0.0;
    $sum = 0.0;
    foreach ($s['criteria'] as $c) {
        if (!isset($scores[$c['key']])) continue;
        $sum += $scores[$c['key']] * $c['weight'];
        $weights += $c['weight'];
    }
    if ($weights > 0 && count($scores) === count($s['criteria'])) $total = round($sum / $weights, 2);

    $status = $submit ? 'submitted' : ($review['status'] === 'submitted' ? 'submitted' : 'in_progress');
    // An edit to a submitted review is only accepted complete, so it cannot be saved half-done.
    if ($review['status'] === 'submitted' && !$submit) {
        return absReviewSave($pdo, $eventRow, $review, $data, true, $userId);
    }
    $pdo->prepare('UPDATE abs_reviews SET status = ?, scores = ?, total = ?, comments_authors = ?, comments_committee = ?,
                          recommendation = ?, recommended_type = ?, submitted_at = IF(? = "submitted", COALESCE(submitted_at, NOW()), submitted_at)
                   WHERE id = ?')
        ->execute([$status, json_encode($scores), $total, $authors !== '' ? $authors : null, $committee !== '' ? $committee : null,
                   $recommendation, $type, $status, (int)$review['id']]);
    if ($submit) {
        absAudit($pdo, (int)$eventRow['id'], (int)$review['abstract_id'], $userId,
                 $review['status'] === 'submitted' ? 'review_edited' : 'review_submitted', null, null, ['total' => $total]);
    }
    return [true, null, []];
}

/** A reviewer declines an abstract, usually for a conflict (REV-5); it goes back to the chair. */
function absReviewDecline(PDO $pdo, array $eventRow, array $review, string $reason, int $userId): ?string
{
    if ($review['status'] === 'submitted') return 'You have already submitted this review.';
    if ($review['status'] === 'declined') return 'You have already declined this abstract.';
    if (trim($reason) === '') return 'Please say briefly why - for example, a conflict of interest.';
    $pdo->prepare("UPDATE abs_reviews SET status = 'declined', decline_reason = ? WHERE id = ?")
        ->execute([mb_substr(trim($reason), 0, 500), (int)$review['id']]);
    absAudit($pdo, (int)$eventRow['id'], (int)$review['abstract_id'], $userId, 'review_declined', null, null, ['reason' => mb_substr(trim($reason), 0, 500)]);
    absSettleAfterRemoval($pdo, (int)$review['abstract_id'], $userId);
    return null;
}

// ---------------------------------------------------------------------------------------
// Reminders (REV-13, and the author deadline reminders in section 7)
// ---------------------------------------------------------------------------------------

/**
 * Reminds every reviewer with open reviews, at most once a day each (the dedupe key holds
 * the date). $force sends today's reminder regardless of how close the deadline is - the
 * chair's "send reminders now"; without it, reminders go out 7, 3 and 1 days before the
 * deadline and daily once it has passed.
 */
function absSendReviewReminders(PDO $pdo, array $config, array $eventRow, bool $force): int
{
    $zone = absZone($eventRow);
    $now = new DateTimeImmutable('now', $zone);
    $deadline = absAt($eventRow['review_deadline'] ?? null, $zone);
    if (!$force) {
        if (!$deadline) return 0;
        $days = (int)floor(($deadline->getTimestamp() - $now->getTimestamp()) / 86400);
        if ($deadline > $now && !in_array($days, [7, 3, 1, 0], true)) return 0;
    }
    $stmt = $pdo->prepare("SELECT rv.reviewer_user_id, COUNT(*) AS open_count FROM abs_reviews rv
                           JOIN abs_abstracts a ON a.id = rv.abstract_id
                           WHERE a.event_id = ? AND rv.status IN ('assigned','in_progress') GROUP BY rv.reviewer_user_id");
    $stmt->execute([(int)$eventRow['id']]);
    $sent = 0;
    foreach ($stmt->fetchAll() as $r) {
        $p = absPersonName($pdo, (int)$r['reviewer_user_id']);
        if ($p['email'] === '') continue;
        $key = 'revrem:' . $eventRow['id'] . ':' . $r['reviewer_user_id'] . ':' . $now->format('Y-m-d');
        if (absQueueOnce($pdo, $config, (int)$eventRow['id'], 'review_reminder', $p['email'], [
            'name' => $p['name'], 'event' => (string)$eventRow['name'], 'count' => (int)$r['open_count'],
            'review_deadline' => absDeadlineText($eventRow, 'review_deadline'), 'link' => absReviewUrl($config),
        ], $key)) $sent++;
    }
    return $sent;
}

/** Queues a templated message under a dedupe key; true when it was new (sent now or queued). */
function absQueueOnce(PDO $pdo, array $config, int $eventId, string $template, string $to, array $vars, string $key, ?int $abstractId = null): bool
{
    $exists = $pdo->prepare('SELECT 1 FROM abs_emails WHERE dedupe_key = ?');
    $exists->execute([$key]);
    if ($exists->fetch()) return false;
    absSendTemplate($pdo, $config, $eventId, $abstractId, $template, $to, $vars, $key);
    return true;
}

/** Authors with unsubmitted drafts, 7 days and 1 day before the submission deadline. */
function absSendDeadlineReminders(PDO $pdo, array $config, array $eventRow): int
{
    if (absCallState($eventRow) !== 'open') return 0;
    $zone = absZone($eventRow);
    $closes = absAt($eventRow['call_closes_at'], $zone);
    $hours = ($closes->getTimestamp() - time()) / 3600;
    $window = $hours <= 24 ? '1d' : ($hours <= 24 * 7 ? '7d' : null);
    if ($window === null) return 0;
    $stmt = $pdo->prepare("SELECT a.id, a.title, u.email FROM abs_abstracts a JOIN users u ON u.id = a.submitter_user_id
                           WHERE a.event_id = ? AND a.status = 'draft'");
    $stmt->execute([(int)$eventRow['id']]);
    $sent = 0;
    foreach ($stmt->fetchAll() as $r) {
        if (absQueueOnce($pdo, $config, (int)$eventRow['id'], 'deadline_reminder', (string)$r['email'], [
            'event' => (string)$eventRow['name'], 'title' => $r['title'] !== '' ? $r['title'] : 'Untitled draft',
            'deadline' => absDeadlineText($eventRow, 'call_closes_at'), 'link' => absEmailUrl($config),
        ], "deadline{$window}:{$r['id']}", (int)$r['id'])) $sent++;
    }
    return $sent;
}

/** Everything that runs on a schedule. Safe to call as often as you like. */
function absRunScheduled(PDO $pdo, array $config): array
{
    $report = ['deadlineReminders' => 0, 'reviewReminders' => 0];
    foreach ($pdo->query("SELECT * FROM abs_events WHERE status = 'published'")->fetchAll() as $event) {
        $report['deadlineReminders'] += absSendDeadlineReminders($pdo, $config, $event);
        $report['reviewReminders'] += absSendReviewReminders($pdo, $config, $event, false);
        if (function_exists('absSendAttendanceReminders')) {
            $report['attendanceReminders'] = ($report['attendanceReminders'] ?? 0) + absSendAttendanceReminders($pdo, $config, $event);
        }
    }
    $report['mail'] = absMailPump($pdo, $config, 200);
    return $report;
}
