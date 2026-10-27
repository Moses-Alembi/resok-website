<?php
declare(strict_types=1);

/**
 * Staff appraisals: a yearly (or any-period) cycle run by HR, in which each member of staff
 * and their appraiser agree objectives, the person assesses themselves, the appraiser reviews,
 * and the person acknowledges the result.
 *
 * The stages of one appraisal:
 *
 *   objectives            the person drafts 1-8 objectives, each with a measure and a weight;
 *                         weights add up to 100
 *   objectives_submitted  the appraiser agrees them (-> self_assessment) or returns them
 *   self_assessment       the person rates each objective and each competency 1-5
 *   manager_review        the appraiser rates the same, writes strengths, development needs
 *                         and training
 *   acknowledge           the person reads it, agrees or not, and may comment
 *   completed
 *
 * Rules that shape it:
 *
 * 1. The appraiser is the person's line manager when the cycle opens (Staff records); HR can
 *    change it. With no manager, HR (staff.manage) acts as appraiser. Nobody appraises
 *    themselves.
 *
 * 2. Each side sees the other's ratings only once they are submitted: the appraiser does not
 *    see the self-assessment while it is being written, and the person does not see the
 *    appraiser's ratings until the review is finished.
 *
 * 3. Need to see: the person, their appraiser, and HR. Not colleagues, and not the rest of
 *    the reporting line.
 *
 * 4. The score: objectives weighted by their weights (APPRAISAL_OBJECTIVES_SHARE of the
 *    total) plus the average of the competencies. The split, the competencies and the rating
 *    words are my defaults for the review, not ReSoK's HR policy.
 */

const APPRAISAL_COMPETENCIES = [
    'quality'        => 'Quality of work',
    'communication'  => 'Communication',
    'teamwork'       => 'Teamwork and collaboration',
    'initiative'     => 'Initiative and problem solving',
    'reliability'    => 'Reliability and time management',
    'integrity'      => 'Integrity and professionalism',
];

const APPRAISAL_RATINGS = [5 => 'Outstanding', 4 => 'Exceeds expectations', 3 => 'Meets expectations', 2 => 'Partly meets expectations', 1 => 'Does not meet expectations'];
const APPRAISAL_OBJECTIVES_SHARE = 0.7;

const APPRAISAL_STAGES = [
    'objectives' => 'Setting objectives', 'objectives_submitted' => 'Objectives with appraiser', 'self_assessment' => 'Self-assessment',
    'manager_review' => 'Appraiser\'s review', 'acknowledge' => 'Waiting for acknowledgement', 'completed' => 'Completed',
];

function appraisalEnsureTables(PDO $pdo): bool
{
    static $ready = null;
    if ($ready !== null) return $ready;
    try {
        $tail = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        $pdo->exec("CREATE TABLE IF NOT EXISTS staff_appraisal_cycles (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(120) NOT NULL,
            period_start DATE NOT NULL,
            period_end DATE NOT NULL,
            objectives_due DATE NULL,
            review_due DATE NULL,
            status ENUM('draft','open','closed') NOT NULL DEFAULT 'draft',
            created_by INT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        )" . $tail);
        $pdo->exec("CREATE TABLE IF NOT EXISTS staff_appraisals (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            cycle_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            appraiser_user_id INT UNSIGNED NULL,
            stage ENUM('objectives','objectives_submitted','self_assessment','manager_review','acknowledge','completed') NOT NULL DEFAULT 'objectives',
            objectives_return_note VARCHAR(500) NULL,
            objectives_agreed_at DATETIME NULL,
            self_comment TEXT NULL,
            self_submitted_at DATETIME NULL,
            manager_comment TEXT NULL,
            strengths TEXT NULL,
            development_needs TEXT NULL,
            training_needs TEXT NULL,
            manager_submitted_at DATETIME NULL,
            score DECIMAL(3,2) NULL,
            staff_agrees TINYINT(1) NULL,
            staff_comment TEXT NULL,
            acknowledged_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY staff_appraisals_once (cycle_id, user_id),
            KEY staff_appraisals_appraiser (appraiser_user_id)
        )" . $tail);
        $pdo->exec("CREATE TABLE IF NOT EXISTS staff_appraisal_objectives (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            appraisal_id INT UNSIGNED NOT NULL,
            sort TINYINT UNSIGNED NOT NULL,
            objective VARCHAR(500) NOT NULL,
            measure VARCHAR(500) NULL,
            weight TINYINT UNSIGNED NOT NULL,
            self_rating TINYINT UNSIGNED NULL,
            self_comment VARCHAR(1000) NULL,
            manager_rating TINYINT UNSIGNED NULL,
            manager_comment VARCHAR(1000) NULL,
            PRIMARY KEY (id),
            KEY staff_appraisal_objectives_a (appraisal_id)
        )" . $tail);
        $pdo->exec("CREATE TABLE IF NOT EXISTS staff_appraisal_competencies (
            appraisal_id INT UNSIGNED NOT NULL,
            competency VARCHAR(40) NOT NULL,
            self_rating TINYINT UNSIGNED NULL,
            manager_rating TINYINT UNSIGNED NULL,
            manager_comment VARCHAR(1000) NULL,
            PRIMARY KEY (appraisal_id, competency)
        )" . $tail);
        return $ready = true;
    } catch (Throwable $e) {
        error_log('Appraisal tables unavailable: ' . $e->getMessage());
        return $ready = false;
    }
}

function appraisalRatingWord(?float $score): ?string
{
    if ($score === null) return null;
    return APPRAISAL_RATINGS[max(1, min(5, (int)round($score)))];
}

function appraisalRow(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare("SELECT a.*, c.name AS cycle_name, c.period_start, c.period_end, c.status AS cycle_status, c.objectives_due, c.review_due,
                                  COALESCE(NULLIF(s.full_name, ''), u.email) AS person, s.job_title, d.name AS department, ap.full_name AS appraiser
                           FROM staff_appraisals a JOIN staff_appraisal_cycles c ON c.id = a.cycle_id JOIN users u ON u.id = a.user_id
                           LEFT JOIN staff_accounts s ON s.user_id = a.user_id LEFT JOIN staff_departments d ON d.id = s.department_id
                           LEFT JOIN staff_accounts ap ON ap.user_id = a.appraiser_user_id WHERE a.id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

/** The caller's role on this appraisal: 'self', 'appraiser', 'hr', or null (no access). */
function appraisalRole(array $a, int $me, bool $isHr): ?string
{
    if ((int)$a['user_id'] === $me) return 'self';
    if ($a['appraiser_user_id'] !== null && (int)$a['appraiser_user_id'] === $me) return 'appraiser';
    if ($isHr) return $a['appraiser_user_id'] === null ? 'appraiser' : 'hr';
    return null;
}

/** What one person may see of an appraisal. Ratings stay hidden until the other side submits. */
function appraisalShape(PDO $pdo, array $a, ?string $role): array
{
    $order = array_keys(APPRAISAL_STAGES);
    $at = array_search($a['stage'], $order, true);
    $selfVisible = $role === 'self' || $at >= array_search('manager_review', $order, true);
    $managerVisible = $role !== 'self' || $at >= array_search('acknowledge', $order, true);
    $obj = $pdo->prepare('SELECT * FROM staff_appraisal_objectives WHERE appraisal_id = ? ORDER BY sort, id');
    $obj->execute([(int)$a['id']]);
    $comp = $pdo->prepare('SELECT * FROM staff_appraisal_competencies WHERE appraisal_id = ?');
    $comp->execute([(int)$a['id']]);
    $c = array_column($comp->fetchAll(), null, 'competency');
    $int = static fn($v) => $v === null ? null : (int)$v;
    return [
        'id' => (int)$a['id'], 'cycleId' => (int)$a['cycle_id'], 'cycle' => $a['cycle_name'], 'periodStart' => $a['period_start'], 'periodEnd' => $a['period_end'],
        'cycleStatus' => $a['cycle_status'], 'objectivesDue' => $a['objectives_due'], 'reviewDue' => $a['review_due'],
        'userId' => (int)$a['user_id'], 'person' => $a['person'], 'jobTitle' => $a['job_title'], 'department' => $a['department'],
        'appraiserId' => $a['appraiser_user_id'] === null ? null : (int)$a['appraiser_user_id'], 'appraiser' => $a['appraiser'] ?? ($a['appraiser_user_id'] === null ? 'HR' : null),
        'stage' => $a['stage'], 'stageLabel' => APPRAISAL_STAGES[$a['stage']], 'role' => $role,
        'objectivesReturnNote' => $a['objectives_return_note'], 'objectivesAgreedAt' => $a['objectives_agreed_at'],
        'objectives' => array_map(static fn($o) => [
            'objective' => $o['objective'], 'measure' => $o['measure'], 'weight' => (int)$o['weight'],
            'selfRating' => $selfVisible ? $int($o['self_rating']) : null, 'selfComment' => $selfVisible ? $o['self_comment'] : null,
            'managerRating' => $managerVisible ? $int($o['manager_rating']) : null, 'managerComment' => $managerVisible ? $o['manager_comment'] : null,
        ], $obj->fetchAll()),
        'competencies' => array_map(static fn($k, $label) => [
            'key' => $k, 'label' => $label,
            'selfRating' => $selfVisible ? $int($c[$k]['self_rating'] ?? null) : null,
            'managerRating' => $managerVisible ? $int($c[$k]['manager_rating'] ?? null) : null,
            'managerComment' => $managerVisible ? ($c[$k]['manager_comment'] ?? null) : null,
        ], array_keys(APPRAISAL_COMPETENCIES), APPRAISAL_COMPETENCIES),
        'selfComment' => $selfVisible ? $a['self_comment'] : null, 'selfSubmittedAt' => $a['self_submitted_at'],
        'managerComment' => $managerVisible ? $a['manager_comment'] : null, 'strengths' => $managerVisible ? $a['strengths'] : null,
        'developmentNeeds' => $managerVisible ? $a['development_needs'] : null, 'trainingNeeds' => $managerVisible ? $a['training_needs'] : null,
        'managerSubmittedAt' => $a['manager_submitted_at'],
        'score' => $managerVisible && $a['score'] !== null ? (float)$a['score'] : null,
        'rating' => $managerVisible && $a['score'] !== null ? appraisalRatingWord((float)$a['score']) : null,
        'staffAgrees' => $a['staff_agrees'] === null ? null : (bool)$a['staff_agrees'], 'staffComment' => $a['staff_comment'], 'acknowledgedAt' => $a['acknowledged_at'],
    ];
}

function appraisalRating($v): ?int
{
    return is_numeric($v) && (int)$v >= 1 && (int)$v <= 5 && (float)$v == (int)$v ? (int)$v : null;
}

function appraisalTodo(PDO $pdo, array $gate, int $me): int
{
    if (!appraisalEnsureTables($pdo)) return 0;
    $isHr = $gate['super'] || in_array('staff.manage', $gate['caps'], true);
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM staff_appraisals a JOIN staff_appraisal_cycles c ON c.id = a.cycle_id AND c.status = 'open'
                           WHERE (a.user_id = ? AND a.stage IN ('objectives','self_assessment','acknowledge'))
                              OR (a.user_id <> ? AND (a.appraiser_user_id = ?" . ($isHr ? ' OR a.appraiser_user_id IS NULL' : '') . ") AND a.stage IN ('objectives_submitted','manager_review'))");
    $stmt->execute([$me, $me, $me]);
    return (int)$stmt->fetchColumn();
}

function appraisalHandle(PDO $pdo, array $config, string $route, string $method, array $user, array $gate): void
{
    if (!appraisalEnsureTables($pdo)) respond(503, ['error' => 'The appraisal tables could not be created.']);
    $me = (int)$user['userId'];
    $isHr = $gate['super'] || in_array('staff.manage', $gate['caps'], true);

    $load = function (int $id) use ($pdo, $me, $isHr): array {
        $a = appraisalRow($pdo, $id);
        $role = $a ? appraisalRole($a, $me, $isHr) : null;
        if (!$role) respond(404, ['error' => 'No such appraisal.']);
        return [$a, $role];
    };

    // ----- my appraisals and the ones I appraise -----
    if ($route === 'staff/appraisals' && $method === 'GET') {
        $mine = $pdo->prepare("SELECT a.id FROM staff_appraisals a JOIN staff_appraisal_cycles c ON c.id = a.cycle_id WHERE a.user_id = ? AND c.status <> 'draft' ORDER BY c.period_end DESC");
        $mine->execute([$me]);
        $theirs = $pdo->prepare("SELECT a.id FROM staff_appraisals a JOIN staff_appraisal_cycles c ON c.id = a.cycle_id
                                 WHERE a.user_id <> ? AND c.status = 'open' AND (a.appraiser_user_id = ?" . ($isHr ? ' OR a.appraiser_user_id IS NULL' : '') . ') ORDER BY a.stage, a.id');
        $theirs->execute([$me, $me]);
        $brief = static function (array $ids) use ($pdo, $me, $isHr): array {
            $out = [];
            foreach ($ids as $id) {
                $a = appraisalRow($pdo, (int)$id);
                $s = appraisalShape($pdo, $a, appraisalRole($a, $me, $isHr));
                $out[] = ['id' => $s['id'], 'cycle' => $s['cycle'], 'person' => $s['person'], 'jobTitle' => $s['jobTitle'], 'appraiser' => $s['appraiser'],
                          'stage' => $s['stage'], 'stageLabel' => $s['stageLabel'], 'rating' => $s['rating'], 'score' => $s['score'], 'cycleStatus' => $s['cycleStatus'],
                          'reviewDue' => $s['reviewDue']];
            }
            return $out;
        };
        respond(200, ['mine' => $brief($mine->fetchAll(PDO::FETCH_COLUMN)), 'toAppraise' => $brief($theirs->fetchAll(PDO::FETCH_COLUMN)),
            'isHr' => $isHr, 'competencies' => APPRAISAL_COMPETENCIES, 'ratings' => APPRAISAL_RATINGS, 'stages' => APPRAISAL_STAGES]);
    }

    if (preg_match('#^staff/appraisals/(\d+)$#', $route, $m) && $method === 'GET') {
        [$a, $role] = $load((int)$m[1]);
        if ($role === 'hr') staffAudit($pdo, $me, 'appraisal_viewed', 'appraisal', (string)$a['id'], $a['person'] . ' ' . $a['cycle_name']);
        respond(200, ['appraisal' => appraisalShape($pdo, $a, $role), 'competencies' => APPRAISAL_COMPETENCIES, 'ratings' => APPRAISAL_RATINGS]);
    }

    if (preg_match('#^staff/appraisals/(\d+)/(objectives|agree|return-objectives|self|review|acknowledge|appraiser)$#', $route, $m) && $method === 'POST') {
        [$a, $role] = $load((int)$m[1]);
        $id = (int)$a['id'];
        $act = $m[2];
        $d = input();
        $submit = !empty($d['submit']);
        if ($a['cycle_status'] !== 'open' && $act !== 'appraiser') respond(400, ['error' => 'This appraisal cycle is closed.']);
        $stageIs = static function (string ...$stages) use ($a): void {
            if (!in_array($a['stage'], $stages, true)) respond(400, ['error' => 'The appraisal is at "' . APPRAISAL_STAGES[$a['stage']] . '", so that cannot be done now.']);
        };
        $toAppraiser = function (string $title) use ($pdo, $a, $id): void {
            if ($a['appraiser_user_id'] !== null) {
                staffNotify($pdo, (int)$a['appraiser_user_id'], 'appraisal', $title, '#appraisal-' . $id, 'ap:' . $id . ':' . md5($title));
                return;
            }
            $hr = $pdo->query("SELECT user_id FROM staff_grants WHERE capability = 'staff.manage'")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($hr as $u) if ((int)$u !== (int)$a['user_id']) staffNotify($pdo, (int)$u, 'appraisal', $title, '#appraisal-' . $id, 'ap:' . $id . ':' . md5($title));
        };
        $toPerson = function (string $title) use ($pdo, $a, $id): void {
            staffNotify($pdo, (int)$a['user_id'], 'appraisal', $title, '#appraisal-' . $id, 'ap:' . $id . ':' . md5($title));
        };

        if ($act === 'objectives') {
            if ($role !== 'self') respond(403, ['error' => 'The person being appraised drafts their objectives.']);
            $stageIs('objectives');
            $rows = [];
            foreach ((array)($d['objectives'] ?? []) as $o) {
                if (!is_array($o)) continue;
                $text = fieldText($o['objective'] ?? '', 500);
                if ($text === null) continue;
                $w = $o['weight'] ?? null;
                if (!is_numeric($w) || (int)$w < 5 || (int)$w > 100) respond(400, ['error' => 'Give each objective a weight between 5 and 100.']);
                $rows[] = [$text, fieldText($o['measure'] ?? '', 500), (int)$w];
            }
            if (count($rows) > 8) respond(400, ['error' => 'Keep to at most 8 objectives.']);
            if ($submit) {
                if (!$rows) respond(400, ['error' => 'Write at least one objective.']);
                if (array_sum(array_column($rows, 2)) !== 100) respond(400, ['error' => 'The weights must add up to 100 (they add up to ' . array_sum(array_column($rows, 2)) . ').']);
                foreach ($rows as $r) if ($r[1] === null) respond(400, ['error' => 'Say how each objective will be measured.']);
            }
            $pdo->beginTransaction();
            $pdo->prepare('DELETE FROM staff_appraisal_objectives WHERE appraisal_id = ?')->execute([$id]);
            $add = $pdo->prepare('INSERT INTO staff_appraisal_objectives (appraisal_id, sort, objective, measure, weight) VALUES (?, ?, ?, ?, ?)');
            foreach ($rows as $i => $r) $add->execute([$id, $i + 1, $r[0], $r[1], $r[2]]);
            if ($submit) $pdo->prepare("UPDATE staff_appraisals SET stage = 'objectives_submitted' WHERE id = ?")->execute([$id]);
            $pdo->commit();
            if ($submit) $toAppraiser($a['person'] . ' sent their objectives for you to agree');
        } elseif ($act === 'agree' || $act === 'return-objectives') {
            if ($role !== 'appraiser') respond(403, ['error' => 'Only the appraiser agrees the objectives.']);
            $stageIs('objectives_submitted');
            if ($act === 'agree') {
                $pdo->prepare("UPDATE staff_appraisals SET stage = 'self_assessment', objectives_agreed_at = NOW(), objectives_return_note = NULL WHERE id = ?")->execute([$id]);
                $toPerson('Your objectives for ' . $a['cycle_name'] . ' were agreed');
            } else {
                $note = fieldText($d['note'] ?? '', 500);
                if ($note === null) respond(400, ['error' => 'Say what to change.']);
                $pdo->prepare("UPDATE staff_appraisals SET stage = 'objectives', objectives_return_note = ? WHERE id = ?")->execute([$note, $id]);
                $toPerson('Your appraiser asked for changes to your objectives: ' . $note);
            }
        } elseif ($act === 'self') {
            if ($role !== 'self') respond(403, ['error' => 'Only the person being appraised writes the self-assessment.']);
            $stageIs('self_assessment');
            $obj = $pdo->prepare('SELECT id FROM staff_appraisal_objectives WHERE appraisal_id = ? ORDER BY sort, id');
            $obj->execute([$id]);
            $ids = $obj->fetchAll(PDO::FETCH_COLUMN);
            $given = array_values((array)($d['objectives'] ?? []));
            $up = $pdo->prepare('UPDATE staff_appraisal_objectives SET self_rating = ?, self_comment = ? WHERE id = ?');
            $pdo->beginTransaction();
            foreach ($ids as $i => $oid) {
                $r = appraisalRating($given[$i]['rating'] ?? null);
                if ($submit && $r === null) { $pdo->rollBack(); respond(400, ['error' => 'Rate every objective from 1 to 5.']); }
                $up->execute([$r, fieldText($given[$i]['comment'] ?? '', 1000), (int)$oid]);
            }
            $comp = $pdo->prepare('INSERT INTO staff_appraisal_competencies (appraisal_id, competency, self_rating) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE self_rating = VALUES(self_rating)');
            foreach (APPRAISAL_COMPETENCIES as $k => $label) {
                $r = appraisalRating(($d['competencies'] ?? [])[$k] ?? null);
                if ($submit && $r === null) { $pdo->rollBack(); respond(400, ['error' => "Rate \"$label\" from 1 to 5."]); }
                $comp->execute([$id, $k, $r]);
            }
            $pdo->prepare('UPDATE staff_appraisals SET self_comment = ?' . ($submit ? ", stage = 'manager_review', self_submitted_at = NOW()" : '') . ' WHERE id = ?')
                ->execute([fieldText($d['comment'] ?? '', 4000), $id]);
            $pdo->commit();
            if ($submit) $toAppraiser($a['person'] . ' finished their self-assessment; your review is next');
        } elseif ($act === 'review') {
            if ($role !== 'appraiser') respond(403, ['error' => 'Only the appraiser writes the review.']);
            $stageIs('manager_review');
            $obj = $pdo->prepare('SELECT id, weight FROM staff_appraisal_objectives WHERE appraisal_id = ? ORDER BY sort, id');
            $obj->execute([$id]);
            $objs = $obj->fetchAll();
            $given = array_values((array)($d['objectives'] ?? []));
            $up = $pdo->prepare('UPDATE staff_appraisal_objectives SET manager_rating = ?, manager_comment = ? WHERE id = ?');
            $weighted = 0.0;
            $weights = 0;
            $pdo->beginTransaction();
            foreach ($objs as $i => $o) {
                $r = appraisalRating($given[$i]['rating'] ?? null);
                if ($submit && $r === null) { $pdo->rollBack(); respond(400, ['error' => 'Rate every objective from 1 to 5.']); }
                $up->execute([$r, fieldText($given[$i]['comment'] ?? '', 1000), (int)$o['id']]);
                if ($r !== null) { $weighted += $r * (int)$o['weight']; $weights += (int)$o['weight']; }
            }
            $comp = $pdo->prepare('INSERT INTO staff_appraisal_competencies (appraisal_id, competency, manager_rating, manager_comment) VALUES (?, ?, ?, ?)
                                   ON DUPLICATE KEY UPDATE manager_rating = VALUES(manager_rating), manager_comment = VALUES(manager_comment)');
            $compSum = 0;
            $compN = 0;
            foreach (APPRAISAL_COMPETENCIES as $k => $label) {
                $r = appraisalRating(($d['competencies'] ?? [])[$k] ?? null);
                if ($submit && $r === null) { $pdo->rollBack(); respond(400, ['error' => "Rate \"$label\" from 1 to 5."]); }
                $comp->execute([$id, $k, $r, fieldText(($d['competencyComments'] ?? [])[$k] ?? '', 1000)]);
                if ($r !== null) { $compSum += $r; $compN++; }
            }
            $comment = fieldText($d['comment'] ?? '', 4000);
            if ($submit && $comment === null) { $pdo->rollBack(); respond(400, ['error' => 'Write an overall comment.']); }
            $score = null;
            if ($submit) {
                $objScore = $weights ? $weighted / $weights : null;
                $compScore = $compN ? $compSum / $compN : null;
                $score = $objScore === null ? $compScore : ($compScore === null ? $objScore : APPRAISAL_OBJECTIVES_SHARE * $objScore + (1 - APPRAISAL_OBJECTIVES_SHARE) * $compScore);
                $score = $score === null ? null : round($score, 2);
            }
            $pdo->prepare('UPDATE staff_appraisals SET manager_comment = ?, strengths = ?, development_needs = ?, training_needs = ?'
                . ($submit ? ", stage = 'acknowledge', manager_submitted_at = NOW(), score = ?" : '') . ' WHERE id = ?')
                ->execute(array_merge([$comment, fieldText($d['strengths'] ?? '', 4000), fieldText($d['developmentNeeds'] ?? '', 4000), fieldText($d['trainingNeeds'] ?? '', 4000)],
                    $submit ? [$score] : [], [$id]));
            $pdo->commit();
            if ($submit) {
                $toPerson('Your appraisal for ' . $a['cycle_name'] . ' is ready to read and acknowledge');
                staffAudit($pdo, $me, 'appraisal_reviewed', 'appraisal', (string)$id, $a['person'] . ' ' . $a['cycle_name']);
            }
        } elseif ($act === 'acknowledge') {
            if ($role !== 'self') respond(403, ['error' => 'Only the person appraised acknowledges it.']);
            $stageIs('acknowledge');
            if (!isset($d['agrees'])) respond(400, ['error' => 'Say whether you agree with the appraisal.']);
            $agrees = !empty($d['agrees']);
            $comment = fieldText($d['comment'] ?? '', 4000);
            if (!$agrees && $comment === null) respond(400, ['error' => 'Say what you disagree with; HR will see it.']);
            $pdo->prepare("UPDATE staff_appraisals SET stage = 'completed', staff_agrees = ?, staff_comment = ?, acknowledged_at = NOW() WHERE id = ?")->execute([$agrees ? 1 : 0, $comment, $id]);
            $toAppraiser($a['person'] . ($agrees ? ' acknowledged their appraisal' : ' acknowledged their appraisal and disagrees with it'));
            if (!$agrees) {
                foreach ($pdo->query("SELECT user_id FROM staff_grants WHERE capability = 'staff.manage'")->fetchAll(PDO::FETCH_COLUMN) as $u) {
                    if ((int)$u !== (int)$a['user_id']) staffNotify($pdo, (int)$u, 'appraisal', $a['person'] . ' disagrees with their appraisal (' . $a['cycle_name'] . ')', '#appraisal-' . $id, 'ap-dis:' . $id);
                }
            }
        } elseif ($act === 'appraiser') {
            if (!$isHr) respond(403, ['error' => 'Only HR changes an appraiser.']);
            $new = isset($d['appraiserId']) && $d['appraiserId'] !== '' && $d['appraiserId'] !== null ? (int)$d['appraiserId'] : null;
            if ($new === (int)$a['user_id']) respond(400, ['error' => 'Nobody appraises themselves.']);
            if ($new !== null) {
                $ok = $pdo->prepare('SELECT 1 FROM staff_accounts WHERE user_id = ? AND active = 1');
                $ok->execute([$new]);
                if (!$ok->fetchColumn()) respond(400, ['error' => 'Choose an active member of staff.']);
            }
            $pdo->prepare('UPDATE staff_appraisals SET appraiser_user_id = ? WHERE id = ?')->execute([$new, $id]);
            staffAudit($pdo, $me, 'appraiser_changed', 'appraisal', (string)$id, $a['person'] . ' -> ' . ($new ?? 'HR'));
        }
        [$a, $role] = $load($id);
        respond(200, ['appraisal' => appraisalShape($pdo, $a, $role)]);
    }

    // ----- cycles (HR) -----
    if (!$isHr && strpos($route, 'staff/appraisals/cycles') === 0) respond(403, ['error' => 'Appraisal cycles are run by HR.']);

    if ($route === 'staff/appraisals/cycles' && $method === 'GET') {
        $cycles = $pdo->query("SELECT c.*, COUNT(a.id) AS people, SUM(a.stage = 'completed') AS done FROM staff_appraisal_cycles c
                               LEFT JOIN staff_appraisals a ON a.cycle_id = c.id GROUP BY c.id ORDER BY c.period_end DESC")->fetchAll();
        respond(200, ['cycles' => array_map(static fn($c) => ['id' => (int)$c['id'], 'name' => $c['name'], 'periodStart' => $c['period_start'], 'periodEnd' => $c['period_end'],
            'objectivesDue' => $c['objectives_due'], 'reviewDue' => $c['review_due'], 'status' => $c['status'], 'people' => (int)$c['people'], 'done' => (int)$c['done']], $cycles)]);
    }
    if ($route === 'staff/appraisals/cycles' && $method === 'POST') {
        $d = input();
        $id = (int)($d['id'] ?? 0);
        $cname = fieldText($d['name'] ?? '', 120);
        if ($cname === null) respond(400, ['error' => 'Name the cycle, e.g. "2026 annual appraisal".']);
        $dates = [];
        foreach (['periodStart' => true, 'periodEnd' => true, 'objectivesDue' => false, 'reviewDue' => false] as $k => $req) {
            $v = (string)($d[$k] ?? '');
            $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $v);
            if ($v === '' && !$req) { $dates[$k] = null; continue; }
            if (!$dt || $dt->format('Y-m-d') !== $v) respond(400, ['error' => 'Check the dates.']);
            $dates[$k] = $v;
        }
        if ($dates['periodEnd'] <= $dates['periodStart']) respond(400, ['error' => 'The period must end after it starts.']);
        $args = [$cname, $dates['periodStart'], $dates['periodEnd'], $dates['objectivesDue'], $dates['reviewDue']];
        if ($id) $pdo->prepare('UPDATE staff_appraisal_cycles SET name = ?, period_start = ?, period_end = ?, objectives_due = ?, review_due = ? WHERE id = ?')->execute(array_merge($args, [$id]));
        else { $pdo->prepare('INSERT INTO staff_appraisal_cycles (name, period_start, period_end, objectives_due, review_due, created_by) VALUES (?, ?, ?, ?, ?, ?)')->execute(array_merge($args, [$me])); $id = (int)$pdo->lastInsertId(); }
        staffAudit($pdo, $me, 'appraisal_cycle_saved', 'appraisal_cycle', (string)$id, $cname);
        respond(200, ['ok' => true, 'id' => $id]);
    }
    // Opening a cycle gives every active member of staff an appraisal, with their line manager as appraiser.
    // Run again later, it adds only the people who joined since.
    if (preg_match('#^staff/appraisals/cycles/(\d+)/(open|close)$#', $route, $m) && $method === 'POST') {
        $c = $pdo->prepare('SELECT * FROM staff_appraisal_cycles WHERE id = ?');
        $c->execute([(int)$m[1]]);
        $cycle = $c->fetch();
        if (!$cycle) respond(404, ['error' => 'No such cycle.']);
        if ($m[2] === 'close') {
            $pdo->prepare("UPDATE staff_appraisal_cycles SET status = 'closed' WHERE id = ?")->execute([(int)$cycle['id']]);
            staffAudit($pdo, $me, 'appraisal_cycle_closed', 'appraisal_cycle', (string)$cycle['id'], $cycle['name']);
            respond(200, ['ok' => true]);
        }
        $pdo->prepare("UPDATE staff_appraisal_cycles SET status = 'open' WHERE id = ?")->execute([(int)$cycle['id']]);
        $add = $pdo->prepare('INSERT IGNORE INTO staff_appraisals (cycle_id, user_id, appraiser_user_id) VALUES (?, ?, ?)');
        $added = 0;
        foreach ($pdo->query('SELECT s.user_id, m.user_id AS manager FROM staff_accounts s LEFT JOIN staff_accounts m ON m.user_id = s.manager_user_id AND m.active = 1 WHERE s.active = 1')->fetchAll() as $p) {
            $add->execute([(int)$cycle['id'], (int)$p['user_id'], $p['manager'] === null ? null : (int)$p['manager']]);
            if ($add->rowCount()) {
                $added++;
                staffNotify($pdo, (int)$p['user_id'], 'appraisal', $cycle['name'] . ' has started: set your objectives', '#appraisals', 'ap-open:' . $cycle['id']);
            }
        }
        staffAudit($pdo, $me, 'appraisal_cycle_opened', 'appraisal_cycle', (string)$cycle['id'], $cycle['name'] . ': ' . $added . ' added');
        respond(200, ['ok' => true, 'added' => $added]);
    }
    if (preg_match('#^staff/appraisals/cycles/(\d+)$#', $route, $m) && $method === 'GET') {
        $stmt = $pdo->prepare('SELECT id FROM staff_appraisals WHERE cycle_id = ?');
        $stmt->execute([(int)$m[1]]);
        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $aid) {
            $a = appraisalRow($pdo, (int)$aid);
            $rows[] = ['id' => (int)$a['id'], 'userId' => (int)$a['user_id'], 'person' => $a['person'], 'jobTitle' => $a['job_title'], 'department' => $a['department'],
                       'appraiserId' => $a['appraiser_user_id'] === null ? null : (int)$a['appraiser_user_id'], 'appraiser' => $a['appraiser'] ?? 'HR',
                       'stage' => $a['stage'], 'stageLabel' => APPRAISAL_STAGES[$a['stage']],
                       'score' => $a['score'] === null ? null : (float)$a['score'], 'rating' => appraisalRatingWord($a['score'] === null ? null : (float)$a['score']),
                       'agrees' => $a['staff_agrees'] === null ? null : (bool)$a['staff_agrees'], 'trainingNeeds' => $a['training_needs']];
        }
        usort($rows, static fn($x, $y) => strnatcasecmp((string)$x['person'], (string)$y['person']));
        if (!empty($_GET['csv'])) {
            staffAudit($pdo, $me, 'appraisal_cycle_exported', 'appraisal_cycle', $m[1]);
            timesheetCsv('appraisals-' . $m[1] . '.csv', ['Staff', 'Job title', 'Department', 'Appraiser', 'Stage', 'Score', 'Rating', 'Agrees', 'Training needs'],
                array_map(static fn($r) => [$r['person'], $r['jobTitle'], $r['department'], $r['appraiser'], $r['stageLabel'], $r['score'], $r['rating'],
                    $r['agrees'] === null ? '' : ($r['agrees'] ? 'Yes' : 'No'), $r['trainingNeeds']], $rows));
        }
        $staff = $pdo->query("SELECT s.user_id, COALESCE(NULLIF(s.full_name, ''), u.email) AS name FROM staff_accounts s JOIN users u ON u.id = s.user_id WHERE s.active = 1 ORDER BY 2")->fetchAll();
        respond(200, ['appraisals' => $rows, 'staff' => array_map(static fn($s) => ['userId' => (int)$s['user_id'], 'name' => $s['name']], $staff), 'stages' => APPRAISAL_STAGES]);
    }

    respond(404, ['error' => 'Route not found']);
}
