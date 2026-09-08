<?php
declare(strict_types=1);

/**
 * ReSoK Virtual Academy - catalogue, enrolment and progress.
 *
 * A course platform in the Alison mould, sitting under the website rather than beside it:
 * learners are rows in the existing users table, so login, password reset, two-factor and
 * rate limiting all already apply and nobody registers twice.
 *
 * Four decisions shape this file.
 *
 * Progress is derived, then cached. What a learner has finished lives in
 * academy_lesson_progress, one row per lesson; the percentage on the enrolment is
 * recomputed from those rows on every write rather than incremented. An incremented counter
 * drifts the first time a lesson is deleted, reordered or marked complete twice, and a
 * course that says 104% complete is worse than one that says nothing.
 *
 * A course's length is summed from its lessons for the same reason. Typing "about 3 hours"
 * into the catalogue guarantees it will be wrong within two edits.
 *
 * Published and draft are genuinely different things, not a flag the reader is trusted to
 * respect. The public catalogue query filters on status; the authoring routes are the only
 * ones that can see a draft, and they check the role rather than the request.
 *
 * The certificate is not in this file. Issuing one is a claim about a person that outlives
 * the enrolment, so it lives with the assessment logic that earns it.
 */

/** Who may build courses. Mirrors the blog: authoring and publishing are different rights. */
const ACADEMY_ROLES_EDIT    = ['author', 'editor', 'content_manager', 'admin'];
const ACADEMY_ROLES_PUBLISH = ['editor', 'content_manager', 'admin'];

const ACADEMY_LEVELS = ['introductory', 'intermediate', 'advanced'];
const ACADEMY_LESSON_KINDS = ['video', 'text', 'slides', 'document', 'link'];
const ACADEMY_COURSE_STATUSES = ['draft', 'published', 'retired'];

function academyRole(array $user): string
{
    return (string)($user['role'] ?? 'member');
}

function academyCanEdit(array $user): bool
{
    return in_array(academyRole($user), ACADEMY_ROLES_EDIT, true);
}

function academyCanPublish(array $user): bool
{
    return in_array(academyRole($user), ACADEMY_ROLES_PUBLISH, true);
}

function academyRequireEdit(array $user): void
{
    if (!academyCanEdit($user)) {
        respond(403, ['error' => 'You do not have permission to manage courses.']);
    }
}

function academyEnsureTables(PDO $pdo): bool
{
    static $ready = null;
    if ($ready !== null) return $ready;
    try {
        foreach (['academy_courses', 'academy_modules', 'academy_lessons',
                  'academy_enrolments', 'academy_lesson_progress'] as $table) {
            $pdo->query('SELECT 1 FROM ' . $table . ' LIMIT 1');
        }
        return $ready = true;
    } catch (Throwable $e) {
        error_log('Academy tables unavailable: ' . $e->getMessage());
        return $ready = false;
    }
}

/**
 * A URL-safe slug, made unique against what is already in the catalogue.
 *
 * The slug is the public address of a course, so it is generated once at creation and never
 * regenerated from the title afterwards: renaming a course must not break every link to it
 * that already exists.
 */
function academySlug(PDO $pdo, string $title, ?int $ignoreId = null): string
{
    $base = strtolower(trim($title));
    $base = preg_replace('/[^a-z0-9]+/', '-', $base) ?? $base;
    $base = trim((string)$base, '-');
    if ($base === '') $base = 'course';
    $base = substr($base, 0, 80);

    $slug = $base;
    for ($n = 2; $n < 200; $n++) {
        $sql = 'SELECT id FROM academy_courses WHERE slug = ?' . ($ignoreId ? ' AND id <> ?' : '') . ' LIMIT 1';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($ignoreId ? [$slug, $ignoreId] : [$slug]);
        if (!$stmt->fetch()) return $slug;
        $slug = substr($base, 0, 76) . '-' . $n;
    }
    return $base . '-' . bin2hex(random_bytes(3));
}

/**
 * Recomputes a course's length from its lessons.
 *
 * Called after any lesson change. Stored rather than computed on read because the catalogue
 * lists many courses at once and this is the difference between one query and one per row.
 */
function academyRecalcCourseMinutes(PDO $pdo, int $courseId): int
{
    $stmt = $pdo->prepare('SELECT COALESCE(SUM(l.duration_minutes), 0) AS total
                           FROM academy_lessons l
                           JOIN academy_modules m ON m.id = l.module_id
                           WHERE m.course_id = ?');
    $stmt->execute([$courseId]);
    $total = (int)($stmt->fetch()['total'] ?? 0);
    $pdo->prepare('UPDATE academy_courses SET estimated_minutes = ? WHERE id = ?')
        ->execute([min($total, 65535), $courseId]);
    return $total;
}

/** How many lessons a course has, which is the denominator of every progress figure. */
function academyLessonCount(PDO $pdo, int $courseId): int
{
    $stmt = $pdo->prepare('SELECT COUNT(*) AS n FROM academy_lessons l
                           JOIN academy_modules m ON m.id = l.module_id
                           WHERE m.course_id = ?');
    $stmt->execute([$courseId]);
    return (int)($stmt->fetch()['n'] ?? 0);
}

/**
 * Recomputes progress for one enrolment from the lesson rows, and returns the percentage.
 *
 * Completion is a side effect of finishing every lesson, set here rather than trusted from
 * the client. A course with no lessons is never complete: zero of zero is not a hundred
 * percent, it is a course that has not been built yet, and issuing a certificate for one
 * would be the worst kind of bug to find later.
 */
function academyRecalcProgress(PDO $pdo, int $enrolmentId): int
{
    $stmt = $pdo->prepare('SELECT course_id FROM academy_enrolments WHERE id = ? LIMIT 1');
    $stmt->execute([$enrolmentId]);
    $row = $stmt->fetch();
    if (!$row) return 0;

    $total = academyLessonCount($pdo, (int)$row['course_id']);
    $done = 0;
    if ($total > 0) {
        $stmt = $pdo->prepare('SELECT COUNT(*) AS n FROM academy_lesson_progress
                               WHERE enrolment_id = ? AND completed_at IS NOT NULL');
        $stmt->execute([$enrolmentId]);
        $done = (int)($stmt->fetch()['n'] ?? 0);
    }

    $percent = $total > 0 ? (int)floor(($done / $total) * 100) : 0;
    $complete = $total > 0 && $done >= $total;

    $pdo->prepare('UPDATE academy_enrolments
                   SET progress_percent = ?,
                       completed_at = CASE WHEN ? = 1 AND completed_at IS NULL THEN NOW()
                                           WHEN ? = 0 THEN NULL ELSE completed_at END
                   WHERE id = ?')
        ->execute([$percent, $complete ? 1 : 0, $complete ? 1 : 0, $enrolmentId]);
    return $percent;
}

/** @param array<string,mixed> $row */
function academyCourseShape(array $row, bool $forAdmin = false): array
{
    $minutes = (int)($row['estimated_minutes'] ?? 0);
    $out = [
        'id'          => (int)$row['id'],
        'slug'        => $row['slug'],
        'title'       => $row['title'],
        'summary'     => $row['summary'],
        'category'    => $row['category'],
        'level'       => $row['level'],
        'heroImage'   => $row['hero_image'],
        'membersOnly' => (bool)(int)$row['members_only'],
        'passMark'    => (int)$row['pass_mark'],
        'certificateEnabled' => (bool)(int)$row['certificate_enabled'],
        'estimatedMinutes'   => $minutes,
        // Pre-formatted because every surface that shows a course shows this, and each one
        // formatting it separately is how "1h 0m" and "60 minutes" end up on the same page.
        'duration'    => academyDuration($minutes),
        'lessonCount' => isset($row['lesson_count']) ? (int)$row['lesson_count'] : null,
        'publishedAt' => $row['published_at'] ?? null,
    ];
    if (array_key_exists('description', $row)) $out['description'] = $row['description'];
    if ($forAdmin) {
        $out['status']      = $row['status'];
        $out['createdBy']   = isset($row['created_by']) ? (int)$row['created_by'] : null;
        $out['createdAt']   = $row['created_at'] ?? null;
        $out['enrolments']  = isset($row['enrolment_count']) ? (int)$row['enrolment_count'] : null;
    }
    return $out;
}

function academyDuration(int $minutes): string
{
    if ($minutes <= 0) return 'Not set';
    if ($minutes < 60) return $minutes . ' min';
    $hours = intdiv($minutes, 60);
    $rest  = $minutes % 60;
    return $rest === 0 ? $hours . 'h' : $hours . 'h ' . $rest . 'm';
}

/**
 * The catalogue.
 *
 * Published only unless an author asks for otherwise, and the caller decides that from the
 * role, never from a query parameter.
 *
 * @param array<string,mixed> $filters
 * @return list<array<string,mixed>>
 */
function academyCourseList(PDO $pdo, array $filters = [], bool $includeUnpublished = false): array
{
    if (!academyEnsureTables($pdo)) return [];

    $where = [];
    $args  = [];
    if (!$includeUnpublished) {
        $where[] = "c.status = 'published'";
    } elseif (in_array((string)($filters['status'] ?? ''), ACADEMY_COURSE_STATUSES, true)) {
        $where[] = 'c.status = ?';
        $args[]  = $filters['status'];
    }
    $q = trim((string)($filters['q'] ?? ''));
    if ($q !== '') {
        $where[] = '(c.title LIKE ? OR c.summary LIKE ? OR c.category LIKE ?)';
        $like = '%' . $q . '%';
        array_push($args, $like, $like, $like);
    }
    $category = trim((string)($filters['category'] ?? ''));
    if ($category !== '') { $where[] = 'c.category = ?'; $args[] = $category; }
    $level = (string)($filters['level'] ?? '');
    if (in_array($level, ACADEMY_LEVELS, true)) { $where[] = 'c.level = ?'; $args[] = $level; }

    $clause = $where ? ' WHERE ' . implode(' AND ', $where) : '';
    $sql = 'SELECT c.*,
                   (SELECT COUNT(*) FROM academy_lessons l
                     JOIN academy_modules m ON m.id = l.module_id
                    WHERE m.course_id = c.id) AS lesson_count,
                   (SELECT COUNT(*) FROM academy_enrolments e WHERE e.course_id = c.id) AS enrolment_count
            FROM academy_courses c' . $clause . '
            ORDER BY c.published_at IS NULL, c.published_at DESC, c.title';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($args);
    return array_map(fn($r) => academyCourseShape($r, $includeUnpublished), $stmt->fetchAll());
}

/** Distinct categories that actually have a published course in them. */
function academyCategories(PDO $pdo): array
{
    if (!academyEnsureTables($pdo)) return [];
    $stmt = $pdo->query("SELECT category, COUNT(*) AS n FROM academy_courses
                         WHERE status = 'published' AND category IS NOT NULL AND category <> ''
                         GROUP BY category ORDER BY category");
    return $stmt ? array_map(fn($r) => ['name' => $r['category'], 'courses' => (int)$r['n']],
                             $stmt->fetchAll()) : [];
}

/** One course by slug or id, with its modules and lessons. */
function academyCourseFind(PDO $pdo, string $key, bool $includeUnpublished = false): ?array
{
    if (!academyEnsureTables($pdo)) return null;
    $sql = 'SELECT c.*,
                   (SELECT COUNT(*) FROM academy_lessons l
                     JOIN academy_modules m ON m.id = l.module_id
                    WHERE m.course_id = c.id) AS lesson_count
            FROM academy_courses c WHERE ' . (ctype_digit($key) ? 'c.id = ?' : 'c.slug = ?')
          . ($includeUnpublished ? '' : " AND c.status = 'published'") . ' LIMIT 1';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$key]);
    $row = $stmt->fetch();
    if (!$row) return null;

    $course = academyCourseShape($row, $includeUnpublished);
    $course['modules'] = academyOutline($pdo, (int)$row['id']);
    return $course;
}

/**
 * The modules and lessons of a course, in order.
 *
 * Lesson bodies and sources are deliberately absent: this is the contents page, shown to
 * anyone browsing the catalogue, and the material itself is what enrolment buys.
 *
 * @return list<array<string,mixed>>
 */
function academyOutline(PDO $pdo, int $courseId): array
{
    $stmt = $pdo->prepare('SELECT id, title, summary, position FROM academy_modules
                           WHERE course_id = ? ORDER BY position, id');
    $stmt->execute([$courseId]);
    $modules = $stmt->fetchAll();
    if (!$modules) return [];

    $ids = array_map(fn($m) => (int)$m['id'], $modules);
    $in  = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare('SELECT id, module_id, title, kind, duration_minutes, position
                           FROM academy_lessons WHERE module_id IN (' . $in . ')
                           ORDER BY position, id');
    $stmt->execute($ids);

    $byModule = [];
    foreach ($stmt->fetchAll() as $l) {
        $byModule[(int)$l['module_id']][] = [
            'id'       => (int)$l['id'],
            'title'    => $l['title'],
            'kind'     => $l['kind'],
            'minutes'  => (int)$l['duration_minutes'],
            'position' => (int)$l['position'],
        ];
    }
    return array_map(fn($m) => [
        'id'       => (int)$m['id'],
        'title'    => $m['title'],
        'summary'  => $m['summary'],
        'position' => (int)$m['position'],
        'lessons'  => $byModule[(int)$m['id']] ?? [],
    ], $modules);
}

/**
 * Enrols a learner, or returns the enrolment they already have.
 *
 * Enrolling twice is the same enrolment, not a second one - the unique key says so and this
 * agrees with it rather than raising an error the learner cannot act on.
 *
 * @return array{0:?array,1:?string}
 */
function academyEnrol(PDO $pdo, int $userId, string $courseKey, ?string $membershipStatus): array
{
    if (!academyEnsureTables($pdo)) return [null, 'The academy is not available on this server.'];
    $course = academyCourseFind($pdo, $courseKey);
    if (!$course) return [null, 'That course is not open for enrolment.'];

    if ($course['membersOnly'] && $membershipStatus !== 'active') {
        return [null, 'This course is part of ReSoK membership. Activate your membership to enrol.'];
    }
    if (($course['lessonCount'] ?? 0) < 1) {
        // Enrolling in an empty course produces a learner who can never make progress and
        // never complete it - a support ticket rather than an error message.
        return [null, 'This course has no lessons yet. It is not ready for learners.'];
    }

    $pdo->prepare('INSERT INTO academy_enrolments (user_id, course_id, last_active_at)
                   VALUES (?, ?, NOW())
                   ON DUPLICATE KEY UPDATE last_active_at = NOW()')
        ->execute([$userId, $course['id']]);

    // The learner row is created on first enrolment rather than at registration: most users
    // never open the academy, and a row per member that says nothing is not worth having.
    $pdo->prepare('INSERT IGNORE INTO academy_learners (user_id) VALUES (?)')->execute([$userId]);

    return [academyEnrolment($pdo, $userId, (int)$course['id']), null];
}

/** One learner's enrolment on one course, with progress. */
function academyEnrolment(PDO $pdo, int $userId, int $courseId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM academy_enrolments WHERE user_id = ? AND course_id = ? LIMIT 1');
    $stmt->execute([$userId, $courseId]);
    $row = $stmt->fetch();
    if (!$row) return null;

    $stmt = $pdo->prepare('SELECT lesson_id, seconds_spent, completed_at
                           FROM academy_lesson_progress WHERE enrolment_id = ?');
    $stmt->execute([(int)$row['id']]);
    $lessons = [];
    foreach ($stmt->fetchAll() as $p) {
        $lessons[(int)$p['lesson_id']] = [
            'seconds'     => (int)$p['seconds_spent'],
            'completed'   => !empty($p['completed_at']),
            'completedAt' => $p['completed_at'],
        ];
    }
    return [
        'id'           => (int)$row['id'],
        'courseId'     => (int)$row['course_id'],
        'enrolledAt'   => $row['enrolled_at'],
        'progress'     => (int)$row['progress_percent'],
        'lastLessonId' => $row['last_lesson_id'] === null ? null : (int)$row['last_lesson_id'],
        'lastActiveAt' => $row['last_active_at'],
        'completedAt'  => $row['completed_at'],
        'lessons'      => $lessons,
    ];
}

/** Everything one learner is enrolled in - what the academy dashboard shows. */
function academyMyCourses(PDO $pdo, int $userId): array
{
    if (!academyEnsureTables($pdo)) return [];
    $stmt = $pdo->prepare('SELECT c.*, e.progress_percent, e.completed_at AS enrolment_completed,
                                  e.last_active_at, e.enrolled_at, e.id AS enrolment_id,
                                  (SELECT COUNT(*) FROM academy_lessons l
                                    JOIN academy_modules m ON m.id = l.module_id
                                   WHERE m.course_id = c.id) AS lesson_count
                           FROM academy_enrolments e
                           JOIN academy_courses c ON c.id = e.course_id
                           WHERE e.user_id = ?
                           ORDER BY e.completed_at IS NOT NULL, e.last_active_at DESC');
    $stmt->execute([$userId]);
    return array_map(function ($r) {
        $course = academyCourseShape($r);
        $course['enrolmentId'] = (int)$r['enrolment_id'];
        $course['progress']    = (int)$r['progress_percent'];
        $course['completedAt'] = $r['enrolment_completed'];
        $course['lastActiveAt'] = $r['last_active_at'];
        $course['enrolledAt']  = $r['enrolled_at'];
        return $course;
    }, $stmt->fetchAll());
}

/**
 * A lesson with its material, for a learner who is entitled to it.
 *
 * The entitlement check is the whole point of the function: course material is what
 * enrolment buys, so a lesson id alone must not be enough to read one.
 *
 * @return array{0:?array,1:?string}
 */
function academyLesson(PDO $pdo, int $userId, int $lessonId): array
{
    if (!academyEnsureTables($pdo)) return [null, 'The academy is not available on this server.'];
    $stmt = $pdo->prepare('SELECT l.*, m.course_id, m.title AS module_title
                           FROM academy_lessons l
                           JOIN academy_modules m ON m.id = l.module_id
                           WHERE l.id = ? LIMIT 1');
    $stmt->execute([$lessonId]);
    $lesson = $stmt->fetch();
    if (!$lesson) return [null, 'That lesson does not exist.'];

    $enrolment = academyEnrolment($pdo, $userId, (int)$lesson['course_id']);
    if (!$enrolment) return [null, 'Enrol in the course to open its lessons.'];

    return [[
        'id'          => (int)$lesson['id'],
        'courseId'    => (int)$lesson['course_id'],
        'moduleId'    => (int)$lesson['module_id'],
        'moduleTitle' => $lesson['module_title'],
        'title'       => $lesson['title'],
        'kind'        => $lesson['kind'],
        'source'      => $lesson['source'],
        'body'        => $lesson['body'],
        'minutes'     => (int)$lesson['duration_minutes'],
        'completed'   => !empty($enrolment['lessons'][$lessonId]['completed']),
        'secondsSpent' => (int)($enrolment['lessons'][$lessonId]['seconds'] ?? 0),
    ], null];
}

/**
 * Records that a learner watched or finished a lesson.
 *
 * Time is added rather than replaced, so closing the tab and coming back does not reset it.
 * Completion is one-way here: a lesson already marked done is not un-done by a later visit
 * that happened to be short.
 *
 * @return array{0:?array,1:?string}
 */
function academyRecordProgress(PDO $pdo, int $userId, int $lessonId, array $data): array
{
    [$lesson, $error] = academyLesson($pdo, $userId, $lessonId);
    if ($error) return [null, $error];

    $enrolment = academyEnrolment($pdo, $userId, (int)$lesson['courseId']);
    if (!$enrolment) return [null, 'Enrol in the course first.'];
    $enrolmentId = (int)$enrolment['id'];

    // Bounded because it arrives from the browser: a tab left open overnight should not
    // record fourteen hours against a six-minute video.
    $seconds = max(0, min(7200, (int)($data['seconds'] ?? 0)));
    $complete = !empty($data['completed']);

    $pdo->prepare('INSERT INTO academy_lesson_progress (enrolment_id, lesson_id, seconds_spent, completed_at)
                   VALUES (?, ?, ?, ' . ($complete ? 'NOW()' : 'NULL') . ')
                   ON DUPLICATE KEY UPDATE
                     seconds_spent = seconds_spent + VALUES(seconds_spent),
                     completed_at = ' . ($complete ? 'COALESCE(completed_at, NOW())' : 'completed_at'))
        ->execute([$enrolmentId, $lessonId, $seconds]);

    $pdo->prepare('UPDATE academy_enrolments SET last_lesson_id = ?, last_active_at = NOW() WHERE id = ?')
        ->execute([$lessonId, $enrolmentId]);

    academyRecalcProgress($pdo, $enrolmentId);
    return [academyEnrolment($pdo, $userId, (int)$lesson['courseId']), null];
}


/* ======================================================================================= */
/* Authoring                                                                                */
/*                                                                                          */
/* Building a course is three nested things - course, module, lesson - and the edits follow  */
/* that shape: each level owns its own position, and reordering takes a full list of ids     */
/* rather than a swap, so a dragged item cannot leave two lessons claiming position 3.       */
/* ======================================================================================= */

/**
 * @param array<string,mixed> $data
 * @return array{0:?array,1:array<string,string>}
 */
function academyCourseCreate(PDO $pdo, array $data, int $authorId): array
{
    if (!academyEnsureTables($pdo)) return [null, ['_' => 'The academy tables are not available.']];

    $title = trim((string)($data['title'] ?? ''));
    if ($title === '') return [null, ['title' => 'A course needs a title.']];

    $level = in_array((string)($data['level'] ?? ''), ACADEMY_LEVELS, true)
        ? (string)$data['level'] : 'introductory';
    $passMark = (int)($data['passMark'] ?? 80);
    if ($passMark < 1 || $passMark > 100) {
        return [null, ['passMark' => 'A pass mark is a percentage between 1 and 100.']];
    }

    $pdo->prepare('INSERT INTO academy_courses
                    (slug, title, summary, description, category, level, hero_image,
                     members_only, certificate_enabled, pass_mark, status, created_by)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([
            academySlug($pdo, $title),
            mb_substr($title, 0, 200),
            mb_substr(trim((string)($data['summary'] ?? '')), 0, 500) ?: null,
            trim((string)($data['description'] ?? '')) ?: null,
            mb_substr(trim((string)($data['category'] ?? '')), 0, 80) ?: null,
            $level,
            mb_substr(trim((string)($data['heroImage'] ?? '')), 0, 255) ?: null,
            !empty($data['membersOnly']) ? 1 : 0,
            array_key_exists('certificateEnabled', $data) && !$data['certificateEnabled'] ? 0 : 1,
            $passMark,
            // Always a draft. A course is published by a deliberate act, never as a side
            // effect of creating it - the catalogue is the public face of the academy.
            'draft',
            $authorId,
        ]);
    return [academyCourseFind($pdo, (string)$pdo->lastInsertId(), true), []];
}

/** Fields an author may change, and the column each maps to. */
function academyCourseFields(): array
{
    return [
        'title' => 'title', 'summary' => 'summary', 'description' => 'description',
        'category' => 'category', 'level' => 'level', 'heroImage' => 'hero_image',
        'passMark' => 'pass_mark',
    ];
}

/** @return array{0:?array,1:array<string,string>} */
function academyCourseUpdate(PDO $pdo, int $id, array $data): array
{
    if (!academyEnsureTables($pdo)) return [null, ['_' => 'The academy tables are not available.']];
    if (!academyCourseFind($pdo, (string)$id, true)) return [null, ['_' => 'That course no longer exists.']];

    $set  = [];
    $args = [];
    foreach (academyCourseFields() as $key => $column) {
        if (!array_key_exists($key, $data)) continue;
        $value = $data[$key];
        if ($key === 'level') {
            if (!in_array((string)$value, ACADEMY_LEVELS, true)) {
                return [null, ['level' => 'Choose introductory, intermediate or advanced.']];
            }
        } elseif ($key === 'passMark') {
            $value = (int)$value;
            if ($value < 1 || $value > 100) {
                return [null, ['passMark' => 'A pass mark is a percentage between 1 and 100.']];
            }
        } elseif ($key === 'title') {
            $value = trim((string)$value);
            if ($value === '') return [null, ['title' => 'A course needs a title.']];
        } else {
            $value = trim((string)$value);
            if ($value === '') $value = null;
        }
        $set[]  = $column . ' = ?';
        $args[] = $value;
    }
    foreach (['membersOnly' => 'members_only', 'certificateEnabled' => 'certificate_enabled'] as $key => $column) {
        if (array_key_exists($key, $data)) {
            $set[]  = $column . ' = ?';
            $args[] = $data[$key] ? 1 : 0;
        }
    }
    if (!$set) return [academyCourseFind($pdo, (string)$id, true), []];

    $args[] = $id;
    $pdo->prepare('UPDATE academy_courses SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($args);
    return [academyCourseFind($pdo, (string)$id, true), []];
}

/**
 * Publishes or withdraws a course.
 *
 * A course with no lessons cannot be published. The catalogue is the public face of the
 * academy, and an entry that opens onto nothing is worse than one that is not there yet.
 *
 * @return array{0:?array,1:?string}
 */
function academyCoursePublish(PDO $pdo, int $id, string $status): array
{
    if (!in_array($status, ACADEMY_COURSE_STATUSES, true)) return [null, 'Unknown status.'];
    $course = academyCourseFind($pdo, (string)$id, true);
    if (!$course) return [null, 'That course no longer exists.'];

    if ($status === 'published') {
        if (academyLessonCount($pdo, $id) < 1) {
            return [null, 'Add at least one lesson before publishing. A course in the '
                        . 'catalogue that opens onto nothing is worse than one that is not '
                        . 'there yet.'];
        }
        $pdo->prepare("UPDATE academy_courses
                       SET status = 'published', published_at = COALESCE(published_at, NOW())
                       WHERE id = ?")->execute([$id]);
    } else {
        // published_at is kept. It records when the course first went out, which stays true
        // after it is withdrawn and is the only way to date what learners already saw.
        $pdo->prepare('UPDATE academy_courses SET status = ? WHERE id = ?')->execute([$status, $id]);
    }
    return [academyCourseFind($pdo, (string)$id, true), null];
}

/** @return array{0:?array,1:?string} */
function academyModuleSave(PDO $pdo, int $courseId, array $data, ?int $moduleId = null): array
{
    if (!academyEnsureTables($pdo)) return [null, 'The academy tables are not available.'];
    $title = trim((string)($data['title'] ?? ''));
    if ($title === '') return [null, 'A module needs a title.'];

    $summary = mb_substr(trim((string)($data['summary'] ?? '')), 0, 400) ?: null;
    if ($moduleId) {
        $pdo->prepare('UPDATE academy_modules SET title = ?, summary = ? WHERE id = ? AND course_id = ?')
            ->execute([mb_substr($title, 0, 200), $summary, $moduleId, $courseId]);
    } else {
        $stmt = $pdo->prepare('SELECT COALESCE(MAX(position), 0) + 1 AS next
                               FROM academy_modules WHERE course_id = ?');
        $stmt->execute([$courseId]);
        $next = (int)($stmt->fetch()['next'] ?? 1);
        $pdo->prepare('INSERT INTO academy_modules (course_id, title, summary, position)
                       VALUES (?, ?, ?, ?)')
            ->execute([$courseId, mb_substr($title, 0, 200), $summary, $next]);
    }
    return [academyOutline($pdo, $courseId), null];
}

/** @return array{0:?array,1:?string} */
function academyLessonSave(PDO $pdo, int $moduleId, array $data, ?int $lessonId = null): array
{
    if (!academyEnsureTables($pdo)) return [null, 'The academy tables are not available.'];

    $stmt = $pdo->prepare('SELECT course_id FROM academy_modules WHERE id = ? LIMIT 1');
    $stmt->execute([$moduleId]);
    $module = $stmt->fetch();
    if (!$module) return [null, 'That module does not exist.'];
    $courseId = (int)$module['course_id'];

    $title = trim((string)($data['title'] ?? ''));
    if ($title === '') return [null, 'A lesson needs a title.'];
    $kind = in_array((string)($data['kind'] ?? ''), ACADEMY_LESSON_KINDS, true)
        ? (string)$data['kind'] : 'video';
    $source = trim((string)($data['source'] ?? ''));
    $body   = trim((string)($data['body'] ?? ''));

    // A lesson has to carry something. The kind decides which of the two is required, and
    // saving one with neither produces a lesson that opens onto a blank page.
    if ($kind === 'text' && $body === '') {
        return [null, 'A written lesson needs its text.'];
    }
    if ($kind !== 'text' && $source === '') {
        return [null, 'A ' . $kind . ' lesson needs its link or file.'];
    }

    $minutes = max(0, min(600, (int)($data['minutes'] ?? 0)));

    if ($lessonId) {
        $pdo->prepare('UPDATE academy_lessons
                       SET title = ?, kind = ?, source = ?, body = ?, duration_minutes = ?
                       WHERE id = ? AND module_id = ?')
            ->execute([mb_substr($title, 0, 200), $kind, $source ?: null, $body ?: null,
                       $minutes, $lessonId, $moduleId]);
    } else {
        $stmt = $pdo->prepare('SELECT COALESCE(MAX(position), 0) + 1 AS next
                               FROM academy_lessons WHERE module_id = ?');
        $stmt->execute([$moduleId]);
        $next = (int)($stmt->fetch()['next'] ?? 1);
        $pdo->prepare('INSERT INTO academy_lessons
                        (module_id, title, kind, source, body, duration_minutes, position)
                       VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([$moduleId, mb_substr($title, 0, 200), $kind, $source ?: null,
                       $body ?: null, $minutes, $next]);
    }

    academyRecalcCourseMinutes($pdo, $courseId);
    // Everyone already enrolled now has a different denominator, so their percentages are
    // stale until recomputed. Left alone, adding a lesson would leave learners sitting at
    // 100% on a course they have not finished.
    academyRecalcAllProgress($pdo, $courseId);
    return [academyOutline($pdo, $courseId), null];
}

/** Recomputes progress for every enrolment on a course. */
function academyRecalcAllProgress(PDO $pdo, int $courseId): void
{
    $stmt = $pdo->prepare('SELECT id FROM academy_enrolments WHERE course_id = ?');
    $stmt->execute([$courseId]);
    foreach ($stmt->fetchAll() as $row) {
        academyRecalcProgress($pdo, (int)$row['id']);
    }
}

/**
 * Deletes a lesson or a module.
 *
 * Progress rows are removed explicitly rather than left to the database. academy_lesson_progress
 * has a foreign key to the enrolment but never had one to the lesson, so deleting a lesson left
 * its progress rows behind - and because progress counts completed rows against the lessons that
 * still exist, a learner who had finished the deleted lesson jumped to a hundred percent on a
 * course they had not finished. The schema now declares that key for new installs; this deletes
 * the rows anyway, so the behaviour does not depend on which version of the schema is in front
 * of it.
 *
 * Percentages are recomputed after, for the same reason adding a lesson recomputes them.
 *
 * @return array{0:?array,1:?string}
 */
function academyDelete(PDO $pdo, string $what, int $id): array
{
    if (!academyEnsureTables($pdo)) return [null, 'The academy tables are not available.'];

    if ($what === 'lesson') {
        $stmt = $pdo->prepare('SELECT m.course_id FROM academy_lessons l
                               JOIN academy_modules m ON m.id = l.module_id WHERE l.id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) return [null, 'That lesson does not exist.'];
        $courseId = (int)$row['course_id'];
        $pdo->prepare('DELETE FROM academy_lesson_progress WHERE lesson_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM academy_lessons WHERE id = ?')->execute([$id]);
    } elseif ($what === 'module') {
        $stmt = $pdo->prepare('SELECT course_id FROM academy_modules WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) return [null, 'That module does not exist.'];
        $courseId = (int)$row['course_id'];
        // The lessons cascade from the module, so their progress rows have to go first -
        // once the lessons are gone there is nothing left to find them by.
        $pdo->prepare('DELETE p FROM academy_lesson_progress p
                       JOIN academy_lessons l ON l.id = p.lesson_id
                       WHERE l.module_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM academy_modules WHERE id = ?')->execute([$id]);
    } else {
        return [null, 'Unknown thing to delete.'];
    }

    academyRecalcCourseMinutes($pdo, $courseId);
    academyRecalcAllProgress($pdo, $courseId);
    return [academyOutline($pdo, $courseId), null];
}

/**
 * Reorders modules within a course, or lessons within a module.
 *
 * Takes the full list of ids in their new order rather than a from/to pair, and checks that
 * list against what is actually there. A swap has to be applied to the same list the browser
 * was looking at; a full list means a stale page is refused outright instead of half-applying
 * a reorder against content that has since changed.
 *
 * @param list<int> $orderedIds
 * @return array{0:?array,1:?string}
 */
function academyReorder(PDO $pdo, string $what, int $parentId, array $orderedIds): array
{
    if (!academyEnsureTables($pdo)) return [null, 'The academy tables are not available.'];

    if ($what === 'module') {
        $table = 'academy_modules';
        $parentColumn = 'course_id';
        $courseId = $parentId;
    } elseif ($what === 'lesson') {
        $table = 'academy_lessons';
        $parentColumn = 'module_id';
        $stmt = $pdo->prepare('SELECT course_id FROM academy_modules WHERE id = ?');
        $stmt->execute([$parentId]);
        $row = $stmt->fetch();
        if (!$row) return [null, 'That module does not exist.'];
        $courseId = (int)$row['course_id'];
    } else {
        return [null, 'Unknown thing to reorder.'];
    }

    $stmt = $pdo->prepare('SELECT id FROM ' . $table . ' WHERE ' . $parentColumn . ' = ?');
    $stmt->execute([$parentId]);
    $actual = array_map(fn($r) => (int)$r['id'], $stmt->fetchAll());
    $given  = array_map('intval', $orderedIds);

    $check = $given;
    sort($actual);
    sort($check);
    if ($actual !== $check) {
        return [null, 'That list does not match what is in the course. Reload the page and try again.'];
    }

    $pdo->beginTransaction();
    try {
        $update = $pdo->prepare('UPDATE ' . $table . ' SET position = ? WHERE id = ?');
        foreach ($given as $index => $id) {
            $update->execute([$index + 1, $id]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        return [null, 'Nothing was reordered: ' . $e->getMessage()];
    }
    return [academyOutline($pdo, $courseId), null];
}

/** The numbers on the authoring dashboard. */
function academySummary(PDO $pdo): array
{
    $empty = ['courses' => 0, 'published' => 0, 'drafts' => 0, 'lessons' => 0,
              'learners' => 0, 'enrolments' => 0, 'completions' => 0];
    if (!academyEnsureTables($pdo)) return $empty;

    $stmt = $pdo->query("SELECT
        (SELECT COUNT(*) FROM academy_courses) AS courses,
        (SELECT COUNT(*) FROM academy_courses WHERE status = 'published') AS published,
        (SELECT COUNT(*) FROM academy_courses WHERE status = 'draft') AS drafts,
        (SELECT COUNT(*) FROM academy_lessons) AS lessons,
        (SELECT COUNT(DISTINCT user_id) FROM academy_enrolments) AS learners,
        (SELECT COUNT(*) FROM academy_enrolments) AS enrolments,
        (SELECT COUNT(*) FROM academy_enrolments WHERE completed_at IS NOT NULL) AS completions");
    $row = $stmt ? $stmt->fetch() : null;
    return $row ? array_map('intval', $row) : $empty;
}
