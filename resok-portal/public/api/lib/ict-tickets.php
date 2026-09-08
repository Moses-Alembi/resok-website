<?php
declare(strict_types=1);

/**
 * ICT helpdesk.
 *
 * The module most likely to go unused, and built accordingly. Raising a ticket asks for a
 * subject and nothing else that can be skipped, because if it is harder than sending a
 * WhatsApp message people will send the WhatsApp message - and then the helpdesk shows an
 * empty queue while the real requests arrive somewhere else. An empty queue that means "no
 * problems" and an empty queue that means "nobody uses this" look identical.
 *
 * Two things are measured separately on purpose:
 *
 *   how long somebody waited to hear anything at all, and
 *   how long the problem lasted.
 *
 * They are different failures. A ticket resolved in a day after four days of silence is not
 * the same as one answered in an hour and fixed on day five, and one average would hide both.
 */

const ICT_TICKET_CATEGORIES = ['hardware', 'software', 'network', 'email', 'internet',
                               'printer', 'account', 'website', 'security', 'other'];
const ICT_TICKET_PRIORITIES = ['low', 'normal', 'high', 'urgent'];
const ICT_TICKET_STATUSES = ['new', 'assigned', 'in_progress', 'waiting', 'resolved', 'closed'];

/**
 * Hours allowed before a ticket counts as overdue, by priority.
 *
 * Response first, resolution second. These are working targets, not promises to anybody -
 * their job is to sort a queue so the oldest urgent thing is visible above the newest trivial
 * one.
 */
const ICT_TICKET_TARGETS = [
    'urgent' => ['response' => 2,  'resolution' => 8],
    'high'   => ['response' => 4,  'resolution' => 24],
    'normal' => ['response' => 24, 'resolution' => 72],
    'low'    => ['response' => 48, 'resolution' => 168],
];

function ictTicketsEnsureTables(PDO $pdo): bool
{
    static $ready = null;
    if ($ready !== null) return $ready;
    try {
        $pdo->query('SELECT 1 FROM ict_tickets LIMIT 1');
        $pdo->query('SELECT 1 FROM ict_ticket_comments LIMIT 1');
        return $ready = true;
    } catch (Throwable $e) {
        error_log('ICT ticket tables unavailable: ' . $e->getMessage());
        return $ready = false;
    }
}

/** Sequential within the year, so the reference itself says roughly when it was raised. */
function ictTicketNextReference(PDO $pdo): string
{
    $year = date('Y');
    $prefix = 'ICT-' . $year . '-';
    $stmt = $pdo->prepare('SELECT reference FROM ict_tickets WHERE reference LIKE ?
                           ORDER BY id DESC LIMIT 1');
    $stmt->execute([$prefix . '%']);
    $last = $stmt->fetch();

    $next = 1;
    if ($last && preg_match('/(\d+)$/', (string)$last['reference'], $m)) $next = (int)$m[1] + 1;
    return $prefix . str_pad((string)$next, 4, '0', STR_PAD_LEFT);
}

/**
 * Whether a ticket has missed its target, and which one.
 *
 * A resolved ticket is never overdue, however long it took - the queue is about what still
 * needs doing, and a closed ticket that once ran late belongs in the report rather than at
 * the top of somebody's list.
 */
function ictTicketBreach(array $row): ?array
{
    if (in_array($row['status'], ['resolved', 'closed'], true)) return null;
    $target = ICT_TICKET_TARGETS[$row['priority']] ?? ICT_TICKET_TARGETS['normal'];
    $opened = strtotime((string)$row['created_at']);
    if ($opened === false) return null;
    $hours = (time() - $opened) / 3600;

    if ($row['first_response_at'] === null && $hours > $target['response']) {
        return ['kind' => 'response', 'hoursLate' => (int)round($hours - $target['response'])];
    }
    if ($hours > $target['resolution']) {
        return ['kind' => 'resolution', 'hoursLate' => (int)round($hours - $target['resolution'])];
    }
    return null;
}

function ictTicketShape(array $row, array $comments = []): array
{
    $hours = function (?string $from, ?string $to): ?float {
        if (!$from || !$to) return null;
        $a = strtotime($from); $b = strtotime($to);
        return ($a === false || $b === false) ? null : round(($b - $a) / 3600, 1);
    };
    return [
        'id'             => (int)$row['id'],
        'reference'      => $row['reference'],
        'requesterName'  => $row['requester_name'],
        'requesterEmail' => $row['requester_email'],
        'department'     => $row['department'],
        'category'       => $row['category'],
        'subject'        => $row['subject'],
        'description'    => $row['description'],
        'priority'       => $row['priority'],
        'status'         => $row['status'],
        'assigneeUserId' => $row['assignee_user_id'] === null ? null : (int)$row['assignee_user_id'],
        'assigneeEmail'  => $row['assignee_email'] ?? null,
        'assetId'        => $row['asset_id'] === null ? null : (int)$row['asset_id'],
        'assetTag'       => $row['asset_tag'] ?? null,
        'resolution'     => $row['resolution'],
        'createdAt'      => $row['created_at'],
        'firstResponseAt'=> $row['first_response_at'],
        'resolvedAt'     => $row['resolved_at'],
        'closedAt'       => $row['closed_at'],
        'responseHours'  => $hours($row['created_at'], $row['first_response_at']),
        'resolutionHours'=> $hours($row['created_at'], $row['resolved_at']),
        'breach'         => ictTicketBreach($row),
        'comments'       => $comments,
    ];
}

/**
 * The queue.
 *
 * Open before closed, then urgent before trivial, then oldest first - so the thing that has
 * been waiting longest at the highest priority is at the top without anyone sorting.
 *
 * @param array{status?:string,assignee?:int,requester?:int,search?:string,mine?:bool} $filters
 */
function ictTicketsList(PDO $pdo, array $filters = []): array
{
    if (!ictTicketsEnsureTables($pdo)) return [];

    $where = [];
    $args = [];
    if (!empty($filters['status'])) {
        if ($filters['status'] === 'open') {
            $where[] = "t.status NOT IN ('resolved','closed')";
        } elseif (in_array($filters['status'], ICT_TICKET_STATUSES, true)) {
            $where[] = 't.status = ?';
            $args[] = $filters['status'];
        }
    }
    if (!empty($filters['requester'])) {
        $where[] = 't.requester_user_id = ?';
        $args[] = (int)$filters['requester'];
    }
    if (!empty($filters['assignee'])) {
        $where[] = 't.assignee_user_id = ?';
        $args[] = (int)$filters['assignee'];
    }
    if (!empty($filters['search'])) {
        $where[] = '(t.reference LIKE ? OR t.subject LIKE ? OR t.requester_name LIKE ?)';
        $like = '%' . $filters['search'] . '%';
        array_push($args, $like, $like, $like);
    }
    $clause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $sql = "SELECT t.*, u.email AS assignee_email, a.asset_tag
            FROM ict_tickets t
            LEFT JOIN users u ON u.id = t.assignee_user_id
            LEFT JOIN ict_assets a ON a.id = t.asset_id
            {$clause}
            ORDER BY t.status IN ('resolved','closed'),
                     FIELD(t.priority,'urgent','high','normal','low'),
                     t.created_at
            LIMIT 300";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($args);
    return array_map(fn($r) => ictTicketShape($r), $stmt->fetchAll());
}

/**
 * One ticket with its thread.
 *
 * $includeInternal is false for the person who raised it - notes between ICT staff are not
 * for them, and the split is what makes staff willing to write anything down at all.
 */
function ictTicketFind(PDO $pdo, int $id, bool $includeInternal = true): ?array
{
    if (!ictTicketsEnsureTables($pdo)) return null;
    $stmt = $pdo->prepare('SELECT t.*, u.email AS assignee_email, a.asset_tag
                           FROM ict_tickets t
                           LEFT JOIN users u ON u.id = t.assignee_user_id
                           LEFT JOIN ict_assets a ON a.id = t.asset_id
                           WHERE t.id = ? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) return null;

    $sql = 'SELECT * FROM ict_ticket_comments WHERE ticket_id = ?';
    if (!$includeInternal) $sql .= ' AND is_internal = 0';
    $sql .= ' ORDER BY created_at, id LIMIT 300';
    $comments = $pdo->prepare($sql);
    $comments->execute([$id]);

    return ictTicketShape($row, array_map(fn($c) => [
        'id'         => (int)$c['id'],
        'author'     => $c['author_name'],
        'body'       => $c['body'],
        'isInternal' => (bool)(int)$c['is_internal'],
        'isSystem'   => (bool)(int)$c['is_system'],
        'at'         => $c['created_at'],
    ], $comments->fetchAll()));
}

/** Adds a line to the thread. System entries are how the workflow narrates itself. */
function ictTicketComment(PDO $pdo, int $ticketId, string $body, string $authorName,
                          ?int $authorUserId, bool $internal = false, bool $system = false): void
{
    $body = trim($body);
    if ($body === '') return;
    $pdo->prepare('INSERT INTO ict_ticket_comments
                    (ticket_id, author_user_id, author_name, body, is_internal, is_system, created_at)
                   VALUES (?, ?, ?, ?, ?, ?, NOW())')
        ->execute([$ticketId, $authorUserId, mb_substr($authorName, 0, 160),
                   mb_substr($body, 0, 8000), $internal ? 1 : 0, $system ? 1 : 0]);
}

/**
 * Raises a ticket.
 *
 * Only a subject is required. Every other field has a sensible default, because a form that
 * demands a category and a priority before it will accept "the printer is broken" is a form
 * people work around.
 *
 * @return array{0:?array,1:array<string,string>}
 */
function ictTicketCreate(PDO $pdo, array $data, array $user): array
{
    if (!ictTicketsEnsureTables($pdo)) {
        return [null, ['_' => 'The helpdesk tables are not available. Apply schema-ict-tickets.sql.']];
    }
    $subject = trim((string)($data['subject'] ?? ''));
    if ($subject === '') return [null, ['subject' => 'What is the problem, in a few words?']];

    $category = in_array($data['category'] ?? '', ICT_TICKET_CATEGORIES, true) ? $data['category'] : 'other';
    $priority = in_array($data['priority'] ?? '', ICT_TICKET_PRIORITIES, true) ? $data['priority'] : 'normal';

    // Named from the account unless a name is given, so someone raising a ticket on a
    // colleague's behalf can say whose problem it is.
    $name = trim((string)($data['requesterName'] ?? '')) ?: (string)($user['email'] ?? 'Unknown');

    $assetId = null;
    if (!empty($data['assetId'])) {
        $exists = $pdo->prepare('SELECT id FROM ict_assets WHERE id = ? LIMIT 1');
        $exists->execute([(int)$data['assetId']]);
        if ($exists->fetch()) $assetId = (int)$data['assetId'];
    }

    $reference = ictTicketNextReference($pdo);
    $pdo->prepare('INSERT INTO ict_tickets
                    (reference, requester_user_id, requester_name, requester_email, department,
                     category, subject, description, priority, asset_id, status, created_at)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())')
        ->execute([$reference, (int)($user['userId'] ?? 0) ?: null, mb_substr($name, 0, 160),
                   (string)($user['email'] ?? '') ?: null,
                   mb_substr(trim((string)($data['department'] ?? '')), 0, 120) ?: null,
                   $category, mb_substr($subject, 0, 200),
                   mb_substr(trim((string)($data['description'] ?? '')), 0, 8000) ?: null,
                   $priority, $assetId, 'new']);

    return [ictTicketFind($pdo, (int)$pdo->lastInsertId()), []];
}

/**
 * Moves a ticket along.
 *
 * The first note or status change by anybody other than the requester sets
 * first_response_at, because that is the moment the person waiting heard something. It is
 * recorded once and never revised.
 *
 * @return array{0:?array,1:?string}
 */
function ictTicketUpdate(PDO $pdo, int $id, array $data, array $actor): array
{
    if (!ictTicketsEnsureTables($pdo)) return [null, 'The helpdesk tables are not available.'];
    $existing = ictTicketFind($pdo, $id);
    if (!$existing) return [null, 'That ticket no longer exists.'];

    $actorId = (int)($actor['userId'] ?? 0) ?: null;
    $actorName = (string)($actor['email'] ?? 'ICT');
    $columns = [];
    $notes = [];

    if (array_key_exists('priority', $data) && in_array($data['priority'], ICT_TICKET_PRIORITIES, true)
        && $data['priority'] !== $existing['priority']) {
        $columns['priority'] = $data['priority'];
        $notes[] = 'Priority changed from ' . $existing['priority'] . ' to ' . $data['priority'] . '.';
    }
    if (array_key_exists('category', $data) && in_array($data['category'], ICT_TICKET_CATEGORIES, true)) {
        $columns['category'] = $data['category'];
    }

    if (array_key_exists('assigneeUserId', $data)) {
        $assignee = $data['assigneeUserId'] === null || $data['assigneeUserId'] === ''
            ? null : (int)$data['assigneeUserId'];
        if ($assignee !== null) {
            $stmt = $pdo->prepare('SELECT email FROM users WHERE id = ? LIMIT 1');
            $stmt->execute([$assignee]);
            $found = $stmt->fetch();
            if (!$found) return [null, 'No such person to assign it to.'];
            $notes[] = 'Assigned to ' . $found['email'] . '.';
        } else {
            $notes[] = 'Assignment cleared.';
        }
        $columns['assignee_user_id'] = $assignee;
        // Picking a ticket up is itself progress; leaving it at "new" once someone owns it
        // makes the queue lie about what is being worked on.
        if ($assignee !== null && $existing['status'] === 'new') $columns['status'] = 'assigned';
    }

    if (array_key_exists('status', $data) && in_array($data['status'], ICT_TICKET_STATUSES, true)
        && $data['status'] !== $existing['status']) {
        $status = (string)$data['status'];
        $columns['status'] = $status;
        $notes[] = 'Status changed to ' . str_replace('_', ' ', $status) . '.';

        if ($status === 'resolved' && $existing['resolvedAt'] === null) {
            $columns['resolved_at'] = date('Y-m-d H:i:s');
        }
        if ($status === 'closed' && $existing['closedAt'] === null) {
            $columns['closed_at'] = date('Y-m-d H:i:s');
            if ($existing['resolvedAt'] === null) $columns['resolved_at'] = date('Y-m-d H:i:s');
        }
        // Reopening clears the finish times, so a ticket that comes back does not keep a
        // resolution time it did not earn.
        if (!in_array($status, ['resolved', 'closed'], true) && $existing['resolvedAt'] !== null) {
            $columns['resolved_at'] = null;
            $columns['closed_at'] = null;
            $notes[] = 'Reopened.';
        }
    }

    if (array_key_exists('resolution', $data)) {
        $columns['resolution'] = mb_substr(trim((string)$data['resolution']), 0, 8000) ?: null;
    }

    // Anyone who is not the requester responding counts as the first response.
    $isRequester = $existing['requesterEmail'] !== null
        && strcasecmp((string)$existing['requesterEmail'], $actorName) === 0;
    if (!$isRequester && $existing['firstResponseAt'] === null
        && ($columns || trim((string)($data['comment'] ?? '')) !== '')) {
        $columns['first_response_at'] = date('Y-m-d H:i:s');
    }

    if ($columns) {
        $set = implode(', ', array_map(fn($c) => "{$c} = ?", array_keys($columns)));
        $pdo->prepare("UPDATE ict_tickets SET {$set} WHERE id = ?")
            ->execute([...array_values($columns), $id]);
    }
    foreach ($notes as $note) {
        ictTicketComment($pdo, $id, $note, $actorName, $actorId, false, true);
    }
    $comment = trim((string)($data['comment'] ?? ''));
    if ($comment !== '') {
        ictTicketComment($pdo, $id, $comment, $actorName, $actorId, !empty($data['internal']));
    }

    return [ictTicketFind($pdo, $id), null];
}

/** The numbers a helpdesk is judged on. */
function ictTicketsSummary(PDO $pdo): array
{
    if (!ictTicketsEnsureTables($pdo)) {
        return ['open' => 0, 'unassigned' => 0, 'overdue' => 0, 'resolvedThisMonth' => 0,
                'medianResponseHours' => null, 'medianResolutionHours' => null];
    }
    $one = fn(string $sql) => (int)($pdo->query($sql)->fetch()['c'] ?? 0);

    // Overdue is computed in PHP because the target depends on priority, and encoding four
    // different intervals in SQL would put the rule in two places.
    $overdue = 0;
    foreach (ictTicketsList($pdo, ['status' => 'open']) as $ticket) {
        if ($ticket['breach']) $overdue++;
    }

    // Median rather than mean: one ticket that sat over a holiday weekend should not make a
    // responsive month look bad.
    $median = function (string $column) use ($pdo): ?float {
        $rows = $pdo->query("SELECT TIMESTAMPDIFF(MINUTE, created_at, {$column}) m
                             FROM ict_tickets
                             WHERE {$column} IS NOT NULL
                               AND created_at > DATE_SUB(NOW(), INTERVAL 90 DAY)
                             ORDER BY m")->fetchAll();
        if (!$rows) return null;
        $mid = (int)floor(count($rows) / 2);
        return round(((float)$rows[$mid]['m']) / 60, 1);
    };

    return [
        'open'       => $one("SELECT COUNT(*) c FROM ict_tickets WHERE status NOT IN ('resolved','closed')"),
        'unassigned' => $one("SELECT COUNT(*) c FROM ict_tickets
                               WHERE status NOT IN ('resolved','closed') AND assignee_user_id IS NULL"),
        'overdue'    => $overdue,
        'resolvedThisMonth' => $one("SELECT COUNT(*) c FROM ict_tickets
                                      WHERE resolved_at IS NOT NULL
                                        AND resolved_at > DATE_SUB(NOW(), INTERVAL 30 DAY)"),
        'medianResponseHours'   => $median('first_response_at'),
        'medianResolutionHours' => $median('resolved_at'),
    ];
}
