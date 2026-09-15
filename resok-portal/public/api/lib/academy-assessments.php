<?php
declare(strict_types=1);

/**
 * ReSoK Virtual Academy - final assessments.
 *
 * A course's final assessment is the row in academy_assessments with no module. When a course
 * has one with questions in it, passing it is a condition of the certificate; when it has none,
 * finishing the lessons is enough.
 *
 * Four decisions shape this file.
 *
 * Marking happens here and nowhere else. The browser is sent prompts and options, never which
 * option is right, and a submission is scored against the database. A quiz marked in the page
 * is a quiz anyone can pass by reading the page.
 *
 * An attempt fixes its questions when it starts. The drawn questions are written as answer rows
 * with no option chosen, in the order they were drawn, so a learner cannot reload until an
 * easier draw comes up, and a submission can only answer the questions it was given.
 *
 * The right answers are shown only once they cannot be used to pass: after passing, or after
 * the last permitted attempt. Showing them after a failed attempt turns the retake into a
 * memory exercise.
 *
 * Nothing about an attempt is trusted from the client except which options were chosen. The
 * score, the pass, the attempt count and the time of submission are all decided here.
 */

function academyAssessmentsReady(PDO $pdo): bool
{
    static $ready = null;
    if ($ready !== null) return $ready;
    try {
        foreach (['academy_assessments', 'academy_questions', 'academy_options',
                  'academy_attempts', 'academy_answers'] as $table) {
            $pdo->query('SELECT 1 FROM ' . $table . ' LIMIT 1');
        }
        return $ready = true;
    } catch (Throwable $e) {
        error_log('Academy assessment tables unavailable: ' . $e->getMessage());
        return $ready = false;
    }
}

/** A course's final assessment, or null when it has none with questions in it. */
function academyFinalAssessment(PDO $pdo, int $courseId): ?array
{
    if (!academyAssessmentsReady($pdo)) return null;
    $stmt = $pdo->prepare('SELECT a.*, c.pass_mark AS course_pass_mark,
                                  (SELECT COUNT(*) FROM academy_questions q WHERE q.assessment_id = a.id) AS question_total
                           FROM academy_assessments a
                           JOIN academy_courses c ON c.id = a.course_id
                           WHERE a.course_id = ? AND a.module_id IS NULL
                           ORDER BY a.id LIMIT 1');
    $stmt->execute([$courseId]);
    $row = $stmt->fetch();
    // An assessment with no questions cannot be passed, so it must not stand between a
    // learner and a certificate.
    if (!$row || (int)$row['question_total'] < 1) return null;

    $total = (int)$row['question_total'];
    $drawn = (int)$row['question_count'];
    return [
        'id'            => (int)$row['id'],
        'title'         => $row['title'],
        // NULL on the assessment means the course's pass mark applies.
        'passMark'      => (int)($row['pass_mark'] ?? $row['course_pass_mark']),
        'maxAttempts'   => (int)$row['max_attempts'],
        'questionCount' => $drawn > 0 ? min($drawn, $total) : $total,
        'shuffle'       => (bool)(int)$row['shuffle_questions'],
    ];
}

/** The best score among passed attempts, or null when the assessment has not been passed. */
function academyAssessmentPassedScore(PDO $pdo, int $enrolmentId, int $assessmentId): ?int
{
    $stmt = $pdo->prepare('SELECT MAX(score_percent) AS best FROM academy_attempts
                           WHERE enrolment_id = ? AND assessment_id = ? AND passed = 1 AND submitted_at IS NOT NULL');
    $stmt->execute([$enrolmentId, $assessmentId]);
    $best = $stmt->fetch()['best'] ?? null;
    return $best === null ? null : (int)$best;
}

/** @return array{0:?array,1:?array,2:?array} The course, the learner's enrolment, the assessment. */
function academyAssessmentContext(PDO $pdo, int $userId, string $courseKey): array
{
    $course = academyCourseFind($pdo, $courseKey);
    if (!$course) return [null, null, null];
    return [
        $course,
        academyEnrolment($pdo, $userId, (int)$course['id']),
        academyFinalAssessment($pdo, (int)$course['id']),
    ];
}

function academyAssessmentBlocker(?array $enrolment, bool $passed, ?int $attemptsLeft, ?int $openAttemptId): ?string
{
    if (!$enrolment) return 'Enrol in the course first.';
    if (empty($enrolment['completedAt'])) return 'The final assessment opens when you have finished every lesson.';
    if ($passed) return 'You have already passed the final assessment.';
    if ($openAttemptId === null && $attemptsLeft === 0) {
        return 'You have used every attempt at this assessment. Contact ReSoK at info@resok.org if you need another.';
    }
    return null;
}

/** Where a learner stands with a course's final assessment. Null when the course does not exist. */
function academyAssessmentStatus(PDO $pdo, int $userId, string $courseKey): ?array
{
    [$course, $enrolment, $assessment] = academyAssessmentContext($pdo, $userId, $courseKey);
    if (!$course) return null;
    if (!$assessment) return ['assessment' => null];

    $attempts = [];
    if ($enrolment) {
        $stmt = $pdo->prepare('SELECT id, score_percent, passed, submitted_at FROM academy_attempts
                               WHERE enrolment_id = ? AND assessment_id = ? ORDER BY id');
        $stmt->execute([(int)$enrolment['id'], $assessment['id']]);
        $attempts = $stmt->fetchAll();
    }

    $used = 0;
    $passed = false;
    $best = null;
    $last = null;
    $open = null;
    foreach ($attempts as $attempt) {
        if ($attempt['submitted_at'] === null) {
            $open = (int)$attempt['id'];
            continue;
        }
        $used++;
        $score = (int)$attempt['score_percent'];
        $best = $best === null ? $score : max($best, $score);
        $last = $score;
        if ((int)$attempt['passed'] === 1) $passed = true;
    }
    $left = $assessment['maxAttempts'] > 0 ? max(0, $assessment['maxAttempts'] - $used) : null;
    $blocker = academyAssessmentBlocker($enrolment, $passed, $left, $open);

    return [
        'assessment'    => $assessment,
        'attemptsUsed'  => $used,
        'attemptsLeft'  => $left,
        'passed'        => $passed,
        'bestScore'     => $best,
        'lastScore'     => $last,
        'openAttemptId' => $passed ? null : $open,
        'canStart'      => $blocker === null,
        'reason'        => $blocker,
    ];
}

/** The questions an attempt was given, in the order it was given them. */
function academyAttemptQuestionIds(PDO $pdo, int $attemptId): array
{
    $stmt = $pdo->prepare('SELECT question_id FROM academy_answers WHERE attempt_id = ?
                           GROUP BY question_id ORDER BY MIN(id)');
    $stmt->execute([$attemptId]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * Questions with their options, keyed by id. Which options are correct, and the explanation,
 * are included only when $reveal is true.
 */
function academyQuestionsById(PDO $pdo, array $ids, bool $reveal): array
{
    if (!$ids) return [];
    $in = implode(',', array_fill(0, count($ids), '?'));

    $stmt = $pdo->prepare('SELECT id, prompt, kind, explanation FROM academy_questions WHERE id IN (' . $in . ')');
    $stmt->execute($ids);
    $out = [];
    foreach ($stmt->fetchAll() as $q) {
        $out[(int)$q['id']] = ['id' => (int)$q['id'], 'prompt' => $q['prompt'], 'kind' => $q['kind'], 'options' => []]
            + ($reveal ? ['explanation' => $q['explanation']] : []);
    }

    $stmt = $pdo->prepare('SELECT id, question_id, label, is_correct FROM academy_options
                           WHERE question_id IN (' . $in . ') ORDER BY position, id');
    $stmt->execute($ids);
    foreach ($stmt->fetchAll() as $o) {
        $qid = (int)$o['question_id'];
        if (!isset($out[$qid])) continue;
        $out[$qid]['options'][] = ['id' => (int)$o['id'], 'label' => $o['label']]
            + ($reveal ? ['correct' => (bool)(int)$o['is_correct']] : []);
    }
    return $out;
}

/** What the learner sees when an attempt opens: questions and options, and nothing else. */
function academyAttemptPaper(PDO $pdo, int $attemptId, array $assessment, array $course): array
{
    $ids = academyAttemptQuestionIds($pdo, $attemptId);
    $questions = academyQuestionsById($pdo, $ids, false);
    $ordered = [];
    foreach ($ids as $id) {
        if (isset($questions[$id])) $ordered[] = $questions[$id];
    }
    return [
        'attemptId'   => $attemptId,
        'courseSlug'  => $course['slug'],
        'courseTitle' => $course['title'],
        'title'       => $assessment['title'],
        'passMark'    => $assessment['passMark'],
        'questions'   => $ordered,
    ];
}

/**
 * Opens an attempt, or returns the one already open.
 *
 * @return array{0:?array,1:?string}
 */
function academyAssessmentStart(PDO $pdo, int $userId, string $courseKey): array
{
    [$course, $enrolment, $assessment] = academyAssessmentContext($pdo, $userId, $courseKey);
    if (!$course) return [null, 'That course is not in the catalogue.'];
    if (!$assessment) return [null, 'This course has no final assessment.'];

    $status = academyAssessmentStatus($pdo, $userId, $courseKey);
    // Returning the open attempt rather than drawing again is what stops a learner reloading
    // until the questions they know come up.
    if ($status['openAttemptId']) {
        return [academyAttemptPaper($pdo, (int)$status['openAttemptId'], $assessment, $course), null];
    }
    if (!$status['canStart']) return [null, $status['reason']];

    $stmt = $pdo->prepare('SELECT id FROM academy_questions WHERE assessment_id = ? ORDER BY position, id');
    $stmt->execute([$assessment['id']]);
    $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    if ($assessment['shuffle']) {
        for ($i = count($ids) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]];
        }
    }
    $ids = array_slice($ids, 0, $assessment['questionCount']);

    $pdo->beginTransaction();
    try {
        $pdo->prepare('INSERT INTO academy_attempts (enrolment_id, assessment_id, started_at) VALUES (?, ?, NOW())')
            ->execute([(int)$enrolment['id'], $assessment['id']]);
        $attemptId = (int)$pdo->lastInsertId();
        $insert = $pdo->prepare('INSERT INTO academy_answers (attempt_id, question_id, option_id, was_correct) VALUES (?, ?, NULL, 0)');
        foreach ($ids as $questionId) {
            $insert->execute([$attemptId, $questionId]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return [academyAttemptPaper($pdo, $attemptId, $assessment, $course), null];
}

/**
 * Marks an attempt.
 *
 * $answers maps a question id to the chosen option id, or to a list of them. An option that
 * does not belong to the question is ignored, and a question left unanswered is wrong.
 *
 * @return array{0:?array,1:?string}
 */
function academyAssessmentSubmit(PDO $pdo, int $userId, string $courseKey, int $attemptId, $answers): array
{
    [$course, $enrolment, $assessment] = academyAssessmentContext($pdo, $userId, $courseKey);
    if (!$course || !$assessment || !$enrolment) return [null, 'That assessment is not available.'];

    $stmt = $pdo->prepare('SELECT id, submitted_at FROM academy_attempts
                           WHERE id = ? AND enrolment_id = ? AND assessment_id = ? LIMIT 1');
    $stmt->execute([$attemptId, (int)$enrolment['id'], $assessment['id']]);
    $attempt = $stmt->fetch();
    if (!$attempt) return [null, 'That attempt was not found.'];
    if ($attempt['submitted_at'] !== null) return [null, 'That attempt has already been submitted.'];

    $ids = academyAttemptQuestionIds($pdo, $attemptId);
    $questions = academyQuestionsById($pdo, $ids, true);
    $given = is_array($answers) ? $answers : [];

    $marked = [];
    $correctCount = 0;
    foreach ($ids as $questionId) {
        // A question deleted since the attempt began is left out of the score entirely rather
        // than counted against the learner.
        if (!isset($questions[$questionId])) continue;
        $question = $questions[$questionId];
        $valid = array_map(fn($o) => $o['id'], $question['options']);

        $raw = $given[$questionId] ?? [];
        $chosen = array_values(array_unique(array_filter(
            array_map('intval', is_array($raw) ? $raw : [$raw]),
            fn($id) => in_array($id, $valid, true)
        )));
        $right = array_values(array_map(fn($o) => $o['id'], array_filter($question['options'], fn($o) => $o['correct'])));
        sort($chosen);
        sort($right);

        // Multiple choice is right only when exactly the right set is chosen: half the correct
        // options is not a correct answer. Single choice is right only with one option chosen.
        $isCorrect = $chosen !== [] && $chosen === $right
            && ($question['kind'] === 'multiple' || count($chosen) === 1);
        if ($isCorrect) $correctCount++;
        $marked[] = ['question' => $question, 'chosen' => $chosen, 'correct' => $isCorrect];
    }

    $total = count($marked);
    $score = $total > 0 ? (int)round($correctCount * 100 / $total) : 0;
    $passed = $total > 0 && $score >= $assessment['passMark'];

    $pdo->beginTransaction();
    try {
        // Closed with a condition on submitted_at, so two submissions racing each other cannot
        // both be marked.
        $close = $pdo->prepare('UPDATE academy_attempts SET score_percent = ?, passed = ?, submitted_at = NOW()
                                WHERE id = ? AND submitted_at IS NULL');
        $close->execute([$score, $passed ? 1 : 0, $attemptId]);
        if ($close->rowCount() === 0) {
            $pdo->rollBack();
            return [null, 'That attempt has already been submitted.'];
        }

        // What was chosen is kept, so a disputed result can be looked at rather than argued
        // about. Rewritten in the order the questions were drawn.
        $pdo->prepare('DELETE FROM academy_answers WHERE attempt_id = ?')->execute([$attemptId]);
        $insert = $pdo->prepare('INSERT INTO academy_answers (attempt_id, question_id, option_id, was_correct) VALUES (?, ?, ?, ?)');
        foreach ($marked as $mark) {
            $questionId = $mark['question']['id'];
            if (!$mark['chosen']) {
                $insert->execute([$attemptId, $questionId, null, 0]);
                continue;
            }
            foreach ($mark['chosen'] as $optionId) {
                $insert->execute([$attemptId, $questionId, $optionId, $mark['correct'] ? 1 : 0]);
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    $status = academyAssessmentStatus($pdo, $userId, $courseKey);
    $reveal = $passed || $status['attemptsLeft'] === 0;

    $review = [];
    foreach ($marked as $mark) {
        $question = $mark['question'];
        $review[] = [
            'id'      => $question['id'],
            'prompt'  => $question['prompt'],
            'kind'    => $question['kind'],
            'options' => array_map(fn($o) => ['id' => $o['id'], 'label' => $o['label']]
                + ($reveal ? ['correct' => $o['correct']] : []), $question['options']),
            'chosen'  => $mark['chosen'],
            'correct' => $mark['correct'],
        ] + ($reveal ? ['explanation' => $question['explanation']] : []);
    }

    return [[
        'attemptId'    => $attemptId,
        'scorePercent' => $score,
        'passed'       => $passed,
        'passMark'     => $assessment['passMark'],
        'correctCount' => $correctCount,
        'total'        => $total,
        'revealed'     => $reveal,
        'review'       => $review,
        'status'       => $status,
    ], null];
}
