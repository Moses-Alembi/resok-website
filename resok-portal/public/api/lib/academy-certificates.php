<?php
declare(strict_types=1);

/**
 * ReSoK Virtual Academy - certificates.
 *
 * A certificate is a claim about a person that outlives the enrolment, so four things are
 * settled here rather than left to the page.
 *
 * The code is random, not derived. A code built from ids or dates can be guessed, and a
 * guessable code turns the public verification page into a directory of who took which
 * course. Twelve characters from a 32-letter alphabet with no 0/O or 1/I: about 60 bits, and
 * still readable aloud over the telephone.
 *
 * What it says is frozen when it is issued. The learner's name and the course title are copied
 * onto the row, so renaming a course next year, or a member changing their name, does not
 * quietly change a certificate already in someone's hands.
 *
 * The learner confirms the name before it is issued, because afterwards it cannot be edited -
 * only revoked by an administrator. A certificate that is reprinted with a different name is
 * no longer evidence of anything.
 *
 * Earning one means finishing every lesson of a course that offers a certificate and, when the
 * course has a final assessment, passing it. The best passing score is printed on the
 * certificate.
 */

const ACADEMY_CERT_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

function academyCertificateCode(): string
{
    // 256 is an exact multiple of 32, so taking each random byte modulo 32 favours no letter.
    $bytes = random_bytes(12);
    $chars = '';
    for ($i = 0; $i < 12; $i++) {
        $chars .= ACADEMY_CERT_ALPHABET[ord($bytes[$i]) % 32];
    }
    return 'RSK-' . substr($chars, 0, 4) . '-' . substr($chars, 4, 4) . '-' . substr($chars, 8, 4);
}

/** Accepts a code however it was typed - lower case, spaces, missing hyphens - or null. */
function academyCertificateNormaliseCode(string $input): ?string
{
    $raw = strtoupper((string)preg_replace('/[^A-Za-z0-9]/', '', $input));
    if (str_starts_with($raw, 'RSK')) $raw = substr($raw, 3);
    if (strlen($raw) !== 12 || strspn($raw, ACADEMY_CERT_ALPHABET) !== 12) return null;
    return 'RSK-' . substr($raw, 0, 4) . '-' . substr($raw, 4, 4) . '-' . substr($raw, 8, 4);
}

function academyCertificateVerifyUrl(string $code): string
{
    return 'https://www.resok.org/verify?code=' . rawurlencode($code);
}

/** @param array<string,mixed> $row */
function academyCertificateShape(array $row): array
{
    return [
        'id'            => (int)$row['id'],
        'code'          => $row['code'],
        'learnerName'   => $row['learner_name'],
        'courseTitle'   => $row['course_title'],
        'scorePercent'  => $row['score_percent'] === null ? null : (int)$row['score_percent'],
        'issuedAt'      => $row['issued_at'],
        'revoked'       => !empty($row['revoked_at']),
        'revokedAt'     => $row['revoked_at'],
        'revokedReason' => $row['revoked_reason'],
        'verifyUrl'     => academyCertificateVerifyUrl((string)$row['code']),
    ];
}

function academyCertificateForEnrolment(PDO $pdo, int $enrolmentId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM academy_certificates WHERE enrolment_id = ? LIMIT 1');
    $stmt->execute([$enrolmentId]);
    $row = $stmt->fetch();
    return $row ? academyCertificateShape($row) : null;
}

/** Why a certificate cannot be issued yet, or null when it can. */
function academyCertificateBlocker(array $course, ?array $enrolment): ?string
{
    if (empty($course['certificateEnabled'])) return 'This course does not issue a certificate.';
    if (!$enrolment) return 'Enrol in the course first.';
    if (empty($enrolment['completedAt'])) return 'Finish every lesson in the course to earn its certificate.';
    return null;
}

/** @return array{0:?array,1:?array,2:?string} The course, the learner's enrolment, and any blocker. */
function academyCertificateContext(PDO $pdo, int $userId, string $courseKey): array
{
    $course = academyCourseFind($pdo, $courseKey);
    if (!$course) return [null, null, 'That course is not in the catalogue.'];
    $enrolment = academyEnrolment($pdo, $userId, (int)$course['id']);
    $blocker = academyCertificateBlocker($course, $enrolment);
    if ($blocker === null && academyCertificateAssessmentScore($pdo, $course, $enrolment) === false) {
        $blocker = 'Pass the final assessment to earn the certificate.';
    }
    return [$course, $enrolment, $blocker];
}

/**
 * The score a certificate carries: the best passing score on the course's final assessment,
 * null when the course has no final assessment, or false when it has one not yet passed.
 */
function academyCertificateAssessmentScore(PDO $pdo, array $course, array $enrolment): int|null|false
{
    if (!function_exists('academyFinalAssessment')) return null;
    $assessment = academyFinalAssessment($pdo, (int)$course['id']);
    if (!$assessment) return null;
    $score = academyAssessmentPassedScore($pdo, (int)$enrolment['id'], $assessment['id']);
    return $score === null ? false : $score;
}

/**
 * The name the certificate form starts with: the name chosen for an earlier certificate, else
 * the membership record, else nothing. Only a suggestion - the learner confirms it.
 */
function academyCertificateSuggestedName(PDO $pdo, int $userId): string
{
    $stmt = $pdo->prepare('SELECT certificate_name, display_name FROM academy_learners WHERE user_id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $learner = $stmt->fetch() ?: [];
    if (trim((string)($learner['certificate_name'] ?? '')) !== '') return trim((string)$learner['certificate_name']);

    $stmt = $pdo->prepare('SELECT title, first_name, middle_name, surname FROM member_profiles WHERE user_id = ? LIMIT 1');
    $stmt->execute([$userId]);
    if ($profile = $stmt->fetch()) {
        $parts = array_filter(array_map(fn($p) => trim((string)$p),
            [$profile['title'], $profile['first_name'], $profile['middle_name'], $profile['surname']]));
        if ($parts) return implode(' ', $parts);
    }
    return trim((string)($learner['display_name'] ?? ''));
}

function academyCertificateCleanName(string $name): ?string
{
    $name = trim((string)preg_replace('/\s+/u', ' ', $name));
    $length = mb_strlen($name);
    if ($length < 3 || $length > 120) return null;
    // Letters in any script, plus the punctuation real names carry. No digits, no symbols: a
    // certificate is not a place for a username.
    if (!preg_match("/^[\\p{L}\\p{M}][\\p{L}\\p{M} .,'\u{2019}()-]*$/u", $name)) return null;
    return $name;
}

/** What the course page shows: the certificate if there is one, else what is still needed. */
function academyCertificateStatus(PDO $pdo, int $userId, string $courseKey): array
{
    [$course, $enrolment, $blocker] = academyCertificateContext($pdo, $userId, $courseKey);
    if (!$course) {
        return ['available' => false, 'eligible' => false, 'reason' => $blocker, 'certificate' => null, 'suggestedName' => null];
    }
    // An issued certificate stays issued even if lessons are added to the course later: it was
    // earned against the course as it was.
    $certificate = $enrolment ? academyCertificateForEnrolment($pdo, (int)$enrolment['id']) : null;
    return [
        'available'     => (bool)$course['certificateEnabled'],
        'eligible'      => $blocker === null,
        'reason'        => $certificate ? null : $blocker,
        'certificate'   => $certificate,
        'suggestedName' => $certificate ? null : academyCertificateSuggestedName($pdo, $userId),
    ];
}

/**
 * Issues the learner's certificate for a course, or returns the one already issued.
 *
 * @return array{0:?array,1:?string}
 */
function academyCertificateIssue(PDO $pdo, int $userId, string $courseKey, string $name): array
{
    [$course, $enrolment, $blocker] = academyCertificateContext($pdo, $userId, $courseKey);
    if (!$course) return [null, $blocker];

    if ($enrolment) {
        $existing = academyCertificateForEnrolment($pdo, (int)$enrolment['id']);
        // Asking again returns what was issued. The name is not changed: it is frozen.
        if ($existing) return [$existing, null];
    }
    if ($blocker) return [null, $blocker];

    $clean = academyCertificateCleanName($name);
    if ($clean === null) {
        return [null, 'Enter the name to print on the certificate: 3 to 120 letters. Spaces, full stops, hyphens and apostrophes are fine.'];
    }

    $enrolmentId = (int)$enrolment['id'];
    // Remembered for the next certificate, so the learner is not asked to type it again.
    $pdo->prepare('INSERT INTO academy_learners (user_id, certificate_name) VALUES (?, ?)
                   ON DUPLICATE KEY UPDATE certificate_name = VALUES(certificate_name)')
        ->execute([$userId, $clean]);

    $score = academyCertificateAssessmentScore($pdo, $course, $enrolment);
    for ($attempt = 0; $attempt < 5; $attempt++) {
        try {
            $pdo->prepare('INSERT INTO academy_certificates (enrolment_id, code, learner_name, course_title, score_percent, issued_at)
                           VALUES (?, ?, ?, ?, ?, NOW())')
                ->execute([$enrolmentId, academyCertificateCode(), $clean, (string)$course['title'],
                           is_int($score) ? $score : null]);
            return [academyCertificateForEnrolment($pdo, $enrolmentId), null];
        } catch (PDOException $e) {
            if ((int)($e->errorInfo[1] ?? 0) !== 1062) throw $e;
            // A duplicate is either a second request that issued it a moment ago, answered with
            // what was issued, or a code collision, which is simply tried again.
            $existing = academyCertificateForEnrolment($pdo, $enrolmentId);
            if ($existing) return [$existing, null];
        }
    }
    return [null, 'The certificate could not be issued. Please try again.'];
}

/**
 * The public check. Says only what is printed on the certificate itself - never the learner's
 * email, and never why a certificate was revoked.
 */
function academyCertificateVerify(PDO $pdo, string $input): array
{
    $code = academyCertificateNormaliseCode($input);
    $shown = $code ?? mb_substr(strtoupper(trim($input)), 0, 40);
    if ($code === null || !academyEnsureTables($pdo)) return ['status' => 'not_found', 'code' => $shown];

    $stmt = $pdo->prepare('SELECT code, learner_name, course_title, score_percent, issued_at, revoked_at
                           FROM academy_certificates WHERE code = ? LIMIT 1');
    $stmt->execute([$code]);
    $row = $stmt->fetch();
    if (!$row) return ['status' => 'not_found', 'code' => $code];

    return [
        'status'       => $row['revoked_at'] ? 'revoked' : 'valid',
        'code'         => $row['code'],
        'learnerName'  => $row['learner_name'],
        'courseTitle'  => $row['course_title'],
        'issuedAt'     => substr((string)$row['issued_at'], 0, 10),
        'scorePercent' => $row['score_percent'] === null ? null : (int)$row['score_percent'],
        'revokedAt'    => $row['revoked_at'] ? substr((string)$row['revoked_at'], 0, 10) : null,
    ];
}

/** Issued certificates for administrators, newest first. */
function academyCertificateList(PDO $pdo, string $q = ''): array
{
    if (!academyEnsureTables($pdo)) return [];
    $sql = 'SELECT ce.*, u.email FROM academy_certificates ce
            JOIN academy_enrolments e ON e.id = ce.enrolment_id
            JOIN users u ON u.id = e.user_id';
    $args = [];
    $q = trim($q);
    if ($q !== '') {
        $sql .= ' WHERE ce.code LIKE ? OR ce.learner_name LIKE ? OR ce.course_title LIKE ? OR u.email LIKE ?';
        $like = '%' . $q . '%';
        $args = [$like, $like, $like, $like];
    }
    $stmt = $pdo->prepare($sql . ' ORDER BY ce.issued_at DESC LIMIT 500');
    $stmt->execute($args);
    return array_map(fn($r) => academyCertificateShape($r) + ['email' => $r['email']], $stmt->fetchAll());
}

/** @return array{0:?array,1:?string} */
function academyCertificateRevoke(PDO $pdo, int $id, string $reason): array
{
    $reason = trim((string)preg_replace('/\s+/u', ' ', $reason));
    if (mb_strlen($reason) < 3) return [null, 'Give a reason for revoking the certificate.'];
    $reason = mb_substr($reason, 0, 200);

    $stmt = $pdo->prepare('UPDATE academy_certificates SET revoked_at = NOW(), revoked_reason = ?
                           WHERE id = ? AND revoked_at IS NULL');
    $stmt->execute([$reason, $id]);

    $row = $pdo->prepare('SELECT * FROM academy_certificates WHERE id = ? LIMIT 1');
    $row->execute([$id]);
    $certificate = $row->fetch();
    if (!$certificate) return [null, 'No such certificate.'];
    if ($stmt->rowCount() === 0) return [null, 'That certificate is already revoked.'];
    return [academyCertificateShape($certificate), null];
}

/* ======================================================================================= */
/* The PDF                                                                                  */
/* ======================================================================================= */

/**
 * Tidies text for the certificate. With the embedded fonts available, a name keeps its accents
 * and letters from other scripts exactly as the learner confirmed it. Without them - the font
 * files not deployed - it is transliterated, so it still prints recognisably in Helvetica
 * rather than as garbage characters.
 */
function academyCertificatePdfText(string $text, bool $unicode): string
{
    $text = trim((string)preg_replace('/\s+/u', ' ', $text));
    if ($unicode) return (string)preg_replace('/[\x00-\x1F\x7F]/u', '', $text);
    $converted = function_exists('iconv') ? @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text) : false;
    if ($converted === false || trim($converted) === '') $converted = $text;
    return trim((string)preg_replace('/[^\x20-\x7E]/', '', $converted));
}

/**
 * Word-wraps for centred text to at most $maxLines, measuring with the PDF's own metrics.
 *
 * @param callable(string):float $measure
 */
function academyCertificatePdfLines(string $text, float $maxWidth, int $maxLines, callable $measure): array
{
    $lines = [];
    $line = '';
    foreach (preg_split('/\s+/u', trim($text)) ?: [] as $word) {
        $test = $line === '' ? $word : $line . ' ' . $word;
        if ($measure($test) > $maxWidth && $line !== '') {
            $lines[] = $line;
            $line = $word;
        } else {
            $line = $test;
        }
    }
    if ($line !== '') $lines[] = $line;
    if (count($lines) > $maxLines) {
        $lines = array_slice($lines, 0, $maxLines);
        $last = $lines[$maxLines - 1];
        while (mb_strlen($last) > 1 && $measure($last . '...') > $maxWidth) {
            $last = rtrim(mb_substr($last, 0, -1));
        }
        $lines[$maxLines - 1] = $last . '...';
    }
    return $lines;
}

/** A4 landscape. Code bottom left, where the verification page tells people to look. */
function academyCertificatePdf(array $certificate): string
{
    if (!class_exists('SimplePdf')) require_once __DIR__ . '/SimplePdf.php';

    $w = 842.0;
    $h = 595.0;
    $cx = $w / 2;
    $pdf = new SimplePdf($w, $h);
    // Noto Sans (SIL Open Font License, see api/fonts/OFL.txt). Only embedded in the PDF when
    // a name or title actually needs a letter Helvetica does not carry.
    $fonts = dirname(__DIR__) . '/fonts/';
    $unicode = $pdf->useUnicodeFonts($fonts . 'NotoSans-Regular.ttf', $fonts . 'NotoSans-Bold.ttf');

    // Ground, forest bands with a society-green rule inside them, and a quiet inner frame.
    $pdf->setFillColor(255, 255, 255);
    $pdf->rect(0, 0, $w, $h);
    $pdf->setFillColor(6, 48, 26);
    $pdf->rect(0, 0, $w, 16);
    $pdf->rect(0, $h - 16, $w, 16);
    $pdf->setFillColor(0, 147, 46);
    $pdf->rect(0, 16, $w, 4);
    $pdf->rect(0, $h - 20, $w, 4);
    $pdf->setStrokeColor(207, 218, 211);
    $pdf->rect(36, 44, $w - 72, $h - 88, 'S');

    // The Virtual Academy logo carries the platform's name, so no separate title line is needed.
    // A baseline JPEG rendering of assets/img/virtual-academy-logo.svg, because SimplePdf embeds JPEG only.
    $top = 74.0;
    $logo = dirname(__DIR__, 2) . '/assets/img/certificate-academy-logo.jpg';
    $logoSize = @getimagesize($logo);
    $logoW = 300.0;
    $logoH = $logoSize ? $logoW * $logoSize[1] / $logoSize[0] : 44.0;
    if ($pdf->image($logo, $cx - $logoW / 2, $top, $logoW, $logoH)) {
        $y = $top + $logoH + 12;
    } else {
        $pdf->setTextColor(6, 48, 26);
        $pdf->text($cx, $top + 26, 'RESOK VIRTUAL ACADEMY', 16, true, 'C');
        $y = $top + 40;
    }

    $y += 40;
    $pdf->setTextColor(19, 32, 25);
    $pdf->text($cx, $y, 'CERTIFICATE OF COMPLETION', 30, true, 'C');

    $y += 16;
    $pdf->setStrokeColor(188, 11, 34);
    $pdf->line($cx - 60, $y, $cx + 60, $y, 1.5);

    $y += 34;
    $pdf->setTextColor(109, 127, 116);
    $pdf->text($cx, $y, 'This certifies that', 13, false, 'C');

    $name = academyCertificatePdfText((string)$certificate['learnerName'], $unicode);
    $nameSize = 32.0;
    while ($nameSize > 14 && $pdf->measure($name, $nameSize, true) > 640) {
        $nameSize -= 0.5;
    }
    $y += 42;
    $pdf->setTextColor(19, 32, 25);
    $pdf->text($cx, $y, $name, $nameSize, true, 'C');

    $y += 34;
    $pdf->setTextColor(109, 127, 116);
    $pdf->text($cx, $y, 'has completed the course', 13, false, 'C');

    $y += 6;
    $pdf->setTextColor(0, 112, 31);
    $title = academyCertificatePdfText((string)$certificate['courseTitle'], $unicode);
    foreach (academyCertificatePdfLines($title, 640, 2, fn(string $s): float => $pdf->measure($s, 18, true)) as $line) {
        $y += 26;
        $pdf->text($cx, $y, $line, 18, true, 'C');
    }

    $issued = date('j F Y', (int)strtotime((string)$certificate['issuedAt']));
    $detail = 'Issued on ' . $issued
        . ($certificate['scorePercent'] !== null ? ' with a score of ' . $certificate['scorePercent'] . '%' : '');
    $y += 32;
    $pdf->setTextColor(61, 79, 69);
    $pdf->text($cx, $y, $detail, 12, false, 'C');

    $base = $h - 72;
    $pdf->setTextColor(109, 127, 116);
    $pdf->text(70, $base - 18, 'CERTIFICATE CODE', 8.5, true);
    $pdf->setTextColor(19, 32, 25);
    $pdf->text(70, $base, (string)$certificate['code'], 14, true);

    $pdf->setTextColor(109, 127, 116);
    $pdf->text($w - 70, $base - 18, 'VERIFY THIS CERTIFICATE AT', 8.5, true, 'R');
    $pdf->setTextColor(0, 112, 31);
    $pdf->text($w - 70, $base, 'www.resok.org/verify', 14, true, 'R');

    $pdf->setTextColor(147, 164, 154);
    $pdf->text($cx, $h - 34, 'Respiratory Society of Kenya  |  www.resok.org', 8.5, false, 'C');

    return $pdf->output();
}
