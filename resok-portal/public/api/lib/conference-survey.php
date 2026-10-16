<?php
declare(strict_types=1);

/**
 * Post-conference evaluation (platform blueprint: "after the conference, attendees rate it
 * and the committee sees the results").
 *
 * Only delegates who were actually scanned in can answer, once each. The organisers see
 * totals, averages and the written comments without names: the registration is kept only so
 * nobody answers twice. Administrators open the survey; the hourly job then emails each
 * attendee once, and once more five days later if they have not answered.
 */

const CONF_SURVEY_TEXT_MAX = 2000;

/** The questions. Keys are stored with the answers, so change wording freely, keys never. */
function confSurveyQuestions(): array
{
    return [
        ['key' => 'overall', 'type' => 'rating', 'label' => 'Overall, how would you rate the conference?'],
        ['key' => 'content', 'type' => 'rating', 'label' => 'Scientific content'],
        ['key' => 'speakers', 'type' => 'rating', 'label' => 'Quality of the speakers'],
        ['key' => 'relevance', 'type' => 'rating', 'label' => 'Relevance to your practice'],
        ['key' => 'organisation', 'type' => 'rating', 'label' => 'Organisation and registration'],
        ['key' => 'venue', 'type' => 'rating', 'label' => 'Venue and catering'],
        ['key' => 'recommend', 'type' => 'scale10', 'label' => 'How likely are you to recommend this conference to a colleague?'],
        ['key' => 'again', 'type' => 'choice', 'label' => 'Would you attend next year?', 'options' => ['Yes', 'Maybe', 'No']],
        ['key' => 'best', 'type' => 'text', 'label' => 'What did you value most?'],
        ['key' => 'improve', 'type' => 'text', 'label' => 'What should we do better?'],
        ['key' => 'topics', 'type' => 'text', 'label' => 'Topics or speakers you would like next time'],
    ];
}

function confSurveySchema(): array
{
    $tail = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    return [
        "CREATE TABLE IF NOT EXISTS conf_survey_state (
            event_id INT UNSIGNED NOT NULL,
            open TINYINT(1) NOT NULL DEFAULT 0,
            opened_at DATETIME NULL,
            closes_at DATETIME NULL,
            PRIMARY KEY (event_id)
        )" . $tail,
        "CREATE TABLE IF NOT EXISTS conf_survey_responses (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            event_id INT UNSIGNED NOT NULL,
            registration_id INT UNSIGNED NOT NULL,
            answers TEXT NOT NULL,
            submitted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY conf_survey_once (event_id, registration_id)
        )" . $tail,
    ];
}

function confSurveyEnsureTables(PDO $pdo): bool
{
    static $ready = null;
    if ($ready !== null) return $ready;
    try {
        foreach (confSurveySchema() as $sql) $pdo->exec($sql);
        return $ready = true;
    } catch (Throwable $e) {
        error_log('Survey tables unavailable: ' . $e->getMessage());
        return $ready = false;
    }
}

function confSurveyState(PDO $pdo, int $eventId): array
{
    $stmt = $pdo->prepare('SELECT * FROM conf_survey_state WHERE event_id = ?');
    $stmt->execute([$eventId]);
    $row = $stmt->fetch() ?: [];
    $open = !empty($row['open']) && (empty($row['closes_at']) || strtotime((string)$row['closes_at']) > time());
    return ['open' => $open, 'switchedOn' => !empty($row['open']), 'openedAt' => $row['opened_at'] ?? null, 'closesAt' => $row['closes_at'] ?? null];
}

function confSurveySaveState(PDO $pdo, int $eventId, array $data, int $by): ?string
{
    $current = confSurveyState($pdo, $eventId);
    $open = array_key_exists('open', $data) ? !empty($data['open']) : $current['switchedOn'];
    $closes = $current['closesAt'];
    if (array_key_exists('closesAt', $data)) {
        $v = str_replace('T', ' ', trim((string)$data['closesAt']));
        $closes = null;
        if ($v !== '') {
            foreach (['Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d'] as $f) {
                $d = DateTimeImmutable::createFromFormat('!' . $f, $v);
                if ($d && $d->format($f) === $v) { $closes = $d->format('Y-m-d H:i:s'); break; }
            }
            if ($closes === null) return 'The closing date must look like 2027-09-30 17:00.';
        }
    }
    // opened_at marks when invitations start; the five-day reminder counts from it.
    $openedAt = $open ? ($current['switchedOn'] && $current['openedAt'] ? $current['openedAt'] : date('Y-m-d H:i:s')) : $current['openedAt'];
    $pdo->prepare('INSERT INTO conf_survey_state (event_id, open, opened_at, closes_at) VALUES (?, ?, ?, ?)
                   ON DUPLICATE KEY UPDATE open = VALUES(open), opened_at = VALUES(opened_at), closes_at = VALUES(closes_at)')
        ->execute([$eventId, $open ? 1 : 0, $openedAt, $closes]);
    if (function_exists('confAudit')) confAudit($pdo, $eventId, null, $by, 'survey_state', ['open' => $open, 'closesAt' => $closes]);
    return null;
}

/** Scanned in at least once: the only people whose opinion of the conference counts. */
function confSurveyAttended(PDO $pdo, array $reg): bool
{
    if (($reg['status'] ?? '') !== 'confirmed') return false;
    $stmt = $pdo->prepare('SELECT 1 FROM conf_scans WHERE registration_id = ? AND result = "ok" LIMIT 1');
    $stmt->execute([(int)$reg['id']]);
    return (bool)$stmt->fetch();
}

function confSurveyAnswered(PDO $pdo, int $eventId, int $registrationId): bool
{
    $stmt = $pdo->prepare('SELECT 1 FROM conf_survey_responses WHERE event_id = ? AND registration_id = ?');
    $stmt->execute([$eventId, $registrationId]);
    return (bool)$stmt->fetch();
}

/** What the delegate's page needs to show the survey, or why it cannot. */
function confSurveyForDelegate(PDO $pdo, array $event, ?array $reg): array
{
    $state = confSurveyState($pdo, (int)$event['id']);
    $attended = $reg ? confSurveyAttended($pdo, $reg) : false;
    return [
        'open' => $state['open'],
        'closesAt' => $state['closesAt'],
        'eligible' => $attended,
        'answered' => $reg ? confSurveyAnswered($pdo, (int)$event['id'], (int)$reg['id']) : false,
        'questions' => $state['open'] && $attended ? confSurveyQuestions() : [],
    ];
}

/** Checks every answer against its question; nothing outside the question list is kept. */
function confSurveySubmit(PDO $pdo, array $event, ?array $reg, array $data): ?string
{
    $eventId = (int)$event['id'];
    if (!confSurveyState($pdo, $eventId)['open']) return 'The evaluation is not open.';
    if (!$reg || !confSurveyAttended($pdo, $reg)) return 'The evaluation is for delegates who attended the conference.';
    if (confSurveyAnswered($pdo, $eventId, (int)$reg['id'])) return 'You have already sent your evaluation. Thank you.';
    $given = is_array($data['answers'] ?? null) ? $data['answers'] : [];
    $answers = [];
    foreach (confSurveyQuestions() as $q) {
        $v = $given[$q['key']] ?? null;
        if ($v === null || $v === '') continue;
        switch ($q['type']) {
            case 'rating':
            case 'scale10':
                $max = $q['type'] === 'rating' ? 5 : 10;
                $min = $q['type'] === 'rating' ? 1 : 0;
                if (!is_numeric($v) || (int)$v != $v || (int)$v < $min || (int)$v > $max) return 'Please give "' . $q['label'] . '" a score from ' . $min . ' to ' . $max . '.';
                $answers[$q['key']] = (int)$v;
                break;
            case 'choice':
                if (!in_array($v, $q['options'], true)) return 'Please choose an answer for "' . $q['label'] . '".';
                $answers[$q['key']] = $v;
                break;
            default:
                $t = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string)$v) ?? '');
                if ($t !== '') $answers[$q['key']] = mb_substr($t, 0, CONF_SURVEY_TEXT_MAX);
        }
    }
    if (!isset($answers['overall'])) return 'Please rate the conference overall.';
    try {
        $pdo->prepare('INSERT INTO conf_survey_responses (event_id, registration_id, answers) VALUES (?, ?, ?)')
            ->execute([$eventId, (int)$reg['id'], json_encode($answers, JSON_UNESCAPED_UNICODE)]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') return 'You have already sent your evaluation. Thank you.';
        throw $e;
    }
    return null;
}

/** Totals, averages and comments. Never names or references. */
function confSurveyResults(PDO $pdo, array $event): array
{
    $eventId = (int)$event['id'];
    $rows = $pdo->prepare('SELECT answers FROM conf_survey_responses WHERE event_id = ? ORDER BY id');
    $rows->execute([$eventId]);
    $all = array_map(fn($r) => json_decode((string)$r['answers'], true) ?: [], $rows->fetchAll());
    $attended = $pdo->prepare('SELECT COUNT(DISTINCT s.registration_id) FROM conf_scans s JOIN conf_registrations r ON r.id = s.registration_id
                               WHERE s.event_id = ? AND s.result = "ok" AND r.status = "confirmed"');
    $attended->execute([$eventId]);
    $out = [];
    foreach (confSurveyQuestions() as $q) {
        $vals = array_values(array_filter(array_map(fn($a) => $a[$q['key']] ?? null, $all), fn($v) => $v !== null));
        $item = ['key' => $q['key'], 'type' => $q['type'], 'label' => $q['label'], 'answers' => count($vals)];
        if ($q['type'] === 'rating' || $q['type'] === 'scale10') {
            $item['average'] = $vals ? round(array_sum($vals) / count($vals), 2) : null;
            $dist = [];
            for ($i = $q['type'] === 'rating' ? 1 : 0; $i <= ($q['type'] === 'rating' ? 5 : 10); $i++) $dist[(string)$i] = 0;
            foreach ($vals as $v) $dist[(string)$v]++;
            $item['distribution'] = $dist;
            if ($q['type'] === 'scale10' && $vals) {
                // Net promoter score: 9-10 promote, 0-6 detract.
                $pro = count(array_filter($vals, fn($v) => $v >= 9));
                $det = count(array_filter($vals, fn($v) => $v <= 6));
                $item['nps'] = (int)round(100 * ($pro - $det) / count($vals));
            }
        } elseif ($q['type'] === 'choice') {
            $item['distribution'] = array_fill_keys($q['options'], 0);
            foreach ($vals as $v) if (isset($item['distribution'][$v])) $item['distribution'][$v]++;
        } else {
            $item['comments'] = $vals;
        }
        $out[] = $item;
    }
    return ['responses' => count($all), 'attended' => (int)$attended->fetchColumn(), 'questions' => $out];
}

/** One row per response, shuffled so the order does not line up with when people answered. */
function confSurveyCsvRows(PDO $pdo, array $event): array
{
    $qs = confSurveyQuestions();
    $rows = [array_map(fn($q) => $q['label'], $qs)];
    $stmt = $pdo->prepare('SELECT answers FROM conf_survey_responses WHERE event_id = ?');
    $stmt->execute([(int)$event['id']]);
    $body = [];
    foreach ($stmt->fetchAll() as $r) {
        $a = json_decode((string)$r['answers'], true) ?: [];
        $body[] = array_map(fn($q) => isset($a[$q['key']]) ? (string)$a[$q['key']] : '', $qs);
    }
    shuffle($body);
    return array_merge($rows, $body);
}

/** Emails each attendee once when the survey opens, and once more five days on. */
function confSendSurveyInvites(PDO $pdo, array $config): int
{
    if (!confSurveyEnsureTables($pdo) || !function_exists('absSendLogged') || !function_exists('cardEmailHtml')) return 0;
    $sent = 0;
    foreach ($pdo->query("SELECT * FROM abs_events WHERE status = 'published'")->fetchAll() as $event) {
        $eventId = (int)$event['id'];
        $state = confSurveyState($pdo, $eventId);
        if (!$state['open']) continue;
        $stage = $state['openedAt'] && strtotime((string)$state['openedAt']) <= time() - 5 * 86400 ? 2 : 1;
        $stmt = $pdo->prepare('SELECT DISTINCT r.id, r.user_id, u.email, mp.title, mp.first_name, mp.surname
                               FROM conf_registrations r
                               JOIN users u ON u.id = r.user_id
                               LEFT JOIN member_profiles mp ON mp.user_id = r.user_id
                               JOIN conf_scans s ON s.registration_id = r.id AND s.result = "ok"
                               WHERE r.event_id = ? AND r.status = "confirmed"
                                 AND NOT EXISTS (SELECT 1 FROM conf_survey_responses x WHERE x.event_id = r.event_id AND x.registration_id = r.id)');
        try {
            $stmt->execute([$eventId]);
            $people = $stmt->fetchAll();
        } catch (Throwable $e) {
            continue;
        }
        $portal = rtrim((string)($config['portal_base_url'] ?? ''), '/') ?: 'https://www.resok.org/resok-portal/public';
        $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
        foreach ($people as $p) {
            $to = (string)$p['email'];
            if ($to === '') continue;
            $salutation = function_exists('emailSalutation') ? emailSalutation($p['title'] ?? null, $p['first_name'] ?? null, $p['surname'] ?? null) : 'Dear colleague,';
            $heading = $stage === 1 ? 'How was the conference?' : 'A last call for your feedback';
            $html = cardEmailHtml($heading, $salutation,
                cardEmailParagraph('Thank you for attending <strong>' . $h($event['name']) . '</strong>. We would value five minutes of your time to tell us what worked and what we should do better next year.')
                . cardEmailParagraph('The organisers see your answers without your name.', true),
                'Give your feedback', $portal . '/conference#survey', 'You are receiving this because you were checked in at the conference.');
            $text = "{$salutation}\n\nThank you for attending {$event['name']}. Please tell us how it went: {$portal}/conference#survey\n\nThe organisers see your answers without your name.\n\nReSoK ICT";
            if (absSendLogged($pdo, $config, $eventId, null, 'conf_survey', $to, $heading . ' ' . $event['name'], $text, $html,
                              'conf-survey-' . $p['id'] . '-' . $stage)) {
                $sent++;
            }
        }
    }
    return $sent;
}
