<?php
declare(strict_types=1);

/**
 * Staff recruitment: ReSoK advertises a vacancy on the public careers page, candidates apply
 * with a CV, and recruiters shortlist, interview and hire.
 *
 * A CV is personal data handed over for one purpose, so four rules shape this module:
 *
 * 1. Applying needs no account. Anyone can apply from the vacancy page; a hidden field, a
 *    dwell-time check and a rate limit keep scripts out, and one person applies to one
 *    vacancy once.
 *
 * 2. Files are checked and locked away. A CV is a PDF or a Word .docx: a PDF carrying script
 *    and a .docx carrying macros are refused. Files are stored encrypted, outside the web
 *    root, and served only to recruiters.
 *
 * 3. Only recruiters see applications. Super administrators, and anyone a super
 *    administrator makes a recruiter. Every time someone opens an application or a CV it is
 *    written to careers_audit.
 *
 * 4. Unsuccessful candidates are not kept. careers_retention_months (6 by default) after a
 *    vacancy closes, every application not marked Hired loses its files, answers, notes and
 *    contact details; only the reference, status and county remain, for statistics.
 */

const CAREERS_TYPES = ['full_time' => 'Full-time', 'part_time' => 'Part-time', 'contract' => 'Contract',
                       'internship' => 'Internship', 'volunteer' => 'Volunteer'];
const CAREERS_STATUSES = ['new' => 'New', 'shortlisted' => 'Shortlisted', 'interview' => 'Interview', 'offered' => 'Offered',
                          'hired' => 'Hired', 'unsuccessful' => 'Not successful', 'withdrawn' => 'Withdrawn'];
const CAREERS_FILE_MAX_BYTES = 5 * 1024 * 1024;
const CAREERS_MAX_QUESTIONS = 8;

// ---------------------------------------------------------------------------------------
// Tables
// ---------------------------------------------------------------------------------------

function careersEnsureTables(PDO $pdo): bool
{
    static $ready = null;
    if ($ready !== null) return $ready;
    try {
        foreach (careersSchema() as $sql) $pdo->exec($sql);
        return $ready = true;
    } catch (Throwable $e) {
        error_log('Careers tables unavailable: ' . $e->getMessage());
        return $ready = false;
    }
}

/**
 * Kept here so the module creates its tables on first use; the same statements are in
 * server/schema-careers.sql for phpMyAdmin. Keep the two in step.
 *
 * @return string[]
 */
function careersSchema(): array
{
    return [
        "CREATE TABLE IF NOT EXISTS careers_jobs (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            slug VARCHAR(120) NOT NULL,
            title VARCHAR(160) NOT NULL,
            department VARCHAR(120) NULL,
            location VARCHAR(120) NULL,
            employment_type VARCHAR(20) NOT NULL DEFAULT 'full_time',
            salary VARCHAR(120) NULL,
            summary VARCHAR(400) NULL,
            description MEDIUMTEXT NULL,
            requirements MEDIUMTEXT NULL,
            questions TEXT NULL,
            closes_at DATETIME NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'draft',
            published_at DATETIME NULL,
            created_by INT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY careers_jobs_slug (slug),
            KEY careers_jobs_status (status, closes_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS careers_applications (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            job_id INT UNSIGNED NOT NULL,
            reference VARCHAR(20) NOT NULL,
            first_name VARCHAR(80) NULL,
            last_name VARCHAR(80) NULL,
            email VARCHAR(190) NULL,
            phone VARCHAR(255) NULL,
            county VARCHAR(60) NULL,
            answers TEXT NULL,
            cover_note TEXT NULL,
            cv_file VARCHAR(80) NULL,
            cv_mime VARCHAR(80) NULL,
            cv_bytes INT UNSIGNED NULL,
            letter_file VARCHAR(80) NULL,
            letter_mime VARCHAR(80) NULL,
            letter_bytes INT UNSIGNED NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'new',
            consent_at DATETIME NULL,
            purged_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY careers_applications_reference (reference),
            UNIQUE KEY careers_applications_once (job_id, email),
            KEY careers_applications_job (job_id, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS careers_notes (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            application_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            score TINYINT UNSIGNED NULL,
            body TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY careers_notes_application (application_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS careers_roles (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id INT UNSIGNED NOT NULL,
            role VARCHAR(20) NOT NULL,
            granted_by INT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY careers_roles_user_role (user_id, role)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS careers_audit (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            job_id INT UNSIGNED NULL,
            application_id INT UNSIGNED NULL,
            user_id INT UNSIGNED NULL,
            action VARCHAR(40) NOT NULL,
            detail TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY careers_audit_application (application_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ];
}

// ---------------------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------------------

function careersRetentionMonths(array $config): int
{
    return max(1, min(60, (int)($config['careers_retention_months'] ?? 6)));
}

function careersIsRecruiter(PDO $pdo, array $user, array $config): bool
{
    if (isSuperAdmin($user, $config)) return true;
    $stmt = $pdo->prepare("SELECT 1 FROM careers_roles WHERE user_id = ? AND role = 'recruiter' LIMIT 1");
    $stmt->execute([(int)($user['userId'] ?? 0)]);
    return (bool)$stmt->fetch();
}

function careersAudit(PDO $pdo, ?int $jobId, ?int $applicationId, ?int $userId, string $action, ?array $detail = null): void
{
    $pdo->prepare('INSERT INTO careers_audit (job_id, application_id, user_id, action, detail) VALUES (?, ?, ?, ?, ?)')
        ->execute([$jobId, $applicationId, $userId, $action, $detail !== null ? json_encode($detail, JSON_UNESCAPED_UNICODE) : null]);
}

function careersIso(?string $value): ?string
{
    if (!$value) return null;
    try {
        return (new DateTimeImmutable($value, new DateTimeZone('Africa/Nairobi')))->format(DATE_ATOM);
    } catch (Throwable $e) {
        return null;
    }
}

function careersText($value, int $max): string
{
    $text = str_replace(["\r\n", "\r"], "\n", (string)$value);
    $text = (string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);
    return mb_substr(trim($text), 0, $max);
}

function careersSlug(PDO $pdo, string $title, ?int $exceptId): string
{
    $base = trim((string)preg_replace('/[^a-z0-9]+/', '-', strtolower($title)), '-') ?: 'vacancy';
    $base = substr($base, 0, 100);
    $slug = $base;
    for ($n = 2; ; $n++) {
        $stmt = $pdo->prepare('SELECT id FROM careers_jobs WHERE slug = ? LIMIT 1');
        $stmt->execute([$slug]);
        $id = $stmt->fetchColumn();
        if (!$id || (int)$id === $exceptId) return $slug;
        $slug = $base . '-' . $n;
    }
}

/** Whether a vacancy takes applications right now. */
function careersIsOpen(array $job): bool
{
    if ($job['status'] !== 'published') return false;
    return !$job['closes_at'] || strtotime((string)$job['closes_at']) > time();
}

function careersQuestions(?string $stored): array
{
    $list = json_decode((string)$stored, true);
    return is_array($list) ? $list : [];
}

function careersJobShape(array $job, bool $forStaff = false): array
{
    $shape = [
        'id' => (int)$job['id'], 'slug' => $job['slug'], 'title' => $job['title'], 'department' => $job['department'],
        'location' => $job['location'], 'type' => $job['employment_type'], 'typeLabel' => CAREERS_TYPES[$job['employment_type']] ?? $job['employment_type'],
        'salary' => $job['salary'], 'summary' => $job['summary'], 'description' => $job['description'], 'requirements' => $job['requirements'],
        'questions' => careersQuestions($job['questions']), 'closesAt' => careersIso($job['closes_at']),
        'closesAtLocal' => $job['closes_at'], 'open' => careersIsOpen($job), 'publishedAt' => careersIso($job['published_at']),
    ];
    if ($forStaff) $shape['status'] = $job['status'];
    return $shape;
}

function careersJobRow(PDO $pdo, int $jobId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM careers_jobs WHERE id = ? LIMIT 1');
    $stmt->execute([$jobId]);
    return $stmt->fetch() ?: null;
}

function careersApplicationRow(PDO $pdo, int $applicationId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM careers_applications WHERE id = ? LIMIT 1');
    $stmt->execute([$applicationId]);
    return $stmt->fetch() ?: null;
}

// ---------------------------------------------------------------------------------------
// Vacancies
// ---------------------------------------------------------------------------------------

/** @return array{0: ?array, 1: ?string} [the vacancy, error] */
function careersJobSave(PDO $pdo, ?int $jobId, array $data, int $userId): array
{
    $title = careersText($data['title'] ?? '', 160);
    if ($title === '') return [null, 'Give the vacancy a title.'];
    $type = array_key_exists((string)($data['type'] ?? ''), CAREERS_TYPES) ? $data['type'] : 'full_time';
    $status = in_array($data['status'] ?? 'draft', ['draft', 'published', 'closed'], true) ? ($data['status'] ?? 'draft') : 'draft';

    $closes = null;
    if (!empty($data['closesAt'])) {
        $at = DateTimeImmutable::createFromFormat('Y-m-d H:i', substr(str_replace('T', ' ', (string)$data['closesAt']), 0, 16), new DateTimeZone('Africa/Nairobi'));
        if (!$at) return [null, 'The closing date is not valid.'];
        $closes = $at->format('Y-m-d H:i:00');
    }
    if ($status === 'published' && !$closes) return [null, 'Set a closing date before publishing.'];

    $questions = [];
    foreach (array_slice((array)($data['questions'] ?? []), 0, CAREERS_MAX_QUESTIONS) as $i => $q) {
        $label = careersText(is_array($q) ? ($q['label'] ?? '') : $q, 300);
        if ($label === '') continue;
        $questions[] = ['key' => 'q' . ($i + 1), 'label' => $label,
                        'type' => (is_array($q) && ($q['type'] ?? '') === 'yesno') ? 'yesno' : 'text',
                        'required' => !is_array($q) || !empty($q['required'])];
    }

    $existing = $jobId ? careersJobRow($pdo, $jobId) : null;
    if ($jobId && !$existing) return [null, 'No such vacancy.'];
    $values = [
        careersSlug($pdo, $title, $jobId), $title, careersText($data['department'] ?? '', 120) ?: null,
        careersText($data['location'] ?? '', 120) ?: null, $type, careersText($data['salary'] ?? '', 120) ?: null,
        careersText($data['summary'] ?? '', 400) ?: null, careersText($data['description'] ?? '', 20000) ?: null,
        careersText($data['requirements'] ?? '', 10000) ?: null, json_encode($questions, JSON_UNESCAPED_UNICODE), $closes, $status,
    ];
    if ($existing) {
        $pdo->prepare('UPDATE careers_jobs SET slug = ?, title = ?, department = ?, location = ?, employment_type = ?, salary = ?, summary = ?,
                       description = ?, requirements = ?, questions = ?, closes_at = ?, status = ?,
                       published_at = IF(? = "published", COALESCE(published_at, NOW()), published_at) WHERE id = ?')
            ->execute([...$values, $status, $jobId]);
    } else {
        $pdo->prepare('INSERT INTO careers_jobs (slug, title, department, location, employment_type, salary, summary, description, requirements,
                       questions, closes_at, status, published_at, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, IF(? = "published", NOW(), NULL), ?)')
            ->execute([...$values, $status, $userId]);
        $jobId = (int)$pdo->lastInsertId();
    }
    careersAudit($pdo, $jobId, null, $userId, $existing ? 'vacancy_updated' : 'vacancy_created', ['status' => $status]);
    return [careersJobShape(careersJobRow($pdo, $jobId), true), null];
}

// ---------------------------------------------------------------------------------------
// Applying
// ---------------------------------------------------------------------------------------

function careersFileDir(array $config): string
{
    return rtrim((string)$config['upload_dir'], '/\\') . '/careers';
}

/**
 * Checks one uploaded document and returns [mime, bytes] or an error. PDF or .docx only:
 * the formats every CV tool exports, and the two whose dangerous parts can be recognised.
 *
 * @return array{0: ?string, 1: ?string, 2: ?string} [mime, contents, error]
 */
function careersCheckDocument(array $file, string $what): array
{
    $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) return [null, null, "Your {$what} is larger than 5 MB."];
    if ($error !== UPLOAD_ERR_OK || !is_uploaded_file((string)($file['tmp_name'] ?? ''))) return [null, null, "Your {$what} did not upload. Please try again."];
    $data = (string)file_get_contents((string)$file['tmp_name']);
    if (strlen($data) > CAREERS_FILE_MAX_BYTES) return [null, null, "Your {$what} is larger than 5 MB."];
    if (strlen($data) < 100) return [null, null, "Your {$what} looks empty."];

    if (strncmp($data, '%PDF-', 5) === 0) {
        if (preg_match('#/(JavaScript|JS|Launch|EmbeddedFile)\b#', $data)) {
            return [null, null, "Your {$what} PDF contains active content. Save it again as a plain PDF (Print > Save as PDF) and retry."];
        }
        return ['application/pdf', $data, null];
    }
    if (strncmp($data, "PK\x03\x04", 4) === 0) {
        // A .docx is a zip; its part names are stored in plain text in the archive, so the
        // check needs no zip extension. A macro-enabled document carries vbaProject.bin.
        if (strpos($data, 'word/document.xml') === false) return [null, null, "Your {$what} is not a Word document. Upload a PDF or .docx."];
        if (stripos($data, 'vbaProject.bin') !== false || stripos($data, 'activeX') !== false) {
            return [null, null, "Your {$what} contains macros and cannot be accepted. Save it as a PDF and retry."];
        }
        return ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', $data, null];
    }
    return [null, null, "Upload your {$what} as a PDF or a Word .docx file."];
}

function careersStoreFile(array $config, string $data): ?string
{
    $dir = careersFileDir($config);
    if (!is_dir($dir) && !@mkdir($dir, 0750, true)) return null;
    $name = 'cv-' . bin2hex(random_bytes(16)) . '.bin';
    return file_put_contents($dir . '/' . $name, cryptoEncrypt($config, $data)) === false ? null : $name;
}

function careersNextReference(PDO $pdo): string
{
    $year = date('Y');
    $stmt = $pdo->prepare('SELECT reference FROM careers_applications WHERE reference LIKE ? ORDER BY reference DESC LIMIT 1');
    $stmt->execute(["CAR-{$year}-%"]);
    $last = (string)($stmt->fetchColumn() ?: '');
    return sprintf('CAR-%s-%04d', $year, $last !== '' ? (int)substr($last, -4) + 1 : 1);
}

/**
 * Validates and records one application. Files are written only after every field has
 * passed, so a rejected form leaves nothing behind.
 *
 * @return array{0: ?string, 1: ?string, 2: array} [reference, error, field errors]
 */
function careersApply(PDO $pdo, array $config, array $job, array $data, array $files): array
{
    if (!careersIsOpen($job)) return [null, 'This vacancy is no longer taking applications.', []];

    $fields = [];
    foreach (['firstName' => 'First name', 'lastName' => 'Last name'] as $key => $label) {
        $err = validateField($label, $data[$key] ?? '', 'name');
        if ($err) $fields[$key] = $err;
    }
    $email = strtolower(trim((string)($data['email'] ?? '')));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) $fields['email'] = 'Enter a valid email address.';
    $phone = preg_replace('/[\s-]/', '', (string)($data['phone'] ?? ''));
    if (preg_match('/^0[17]\d{8}$/', $phone)) $phone = '+254' . substr($phone, 1);
    if (!preg_match('/^\+[1-9]\d{7,14}$/', $phone)) $fields['phone'] = 'Enter your phone number, for example 0712 345 678 or +254712345678.';
    $county = careersText($data['county'] ?? '', 60);
    if ($county === '') $fields['county'] = 'Choose your county.';

    $answers = [];
    foreach (careersQuestions($job['questions']) as $q) {
        $value = careersText($data['answers'][$q['key']] ?? ($data['answer_' . $q['key']] ?? ''), 2000);
        if ($q['type'] === 'yesno' && $value !== '' && !in_array($value, ['yes', 'no'], true)) $value = '';
        if ($value === '' && !empty($q['required'])) $fields['answer_' . $q['key']] = 'Please answer this question.';
        $answers[] = ['label' => $q['label'], 'type' => $q['type'], 'answer' => $value];
    }
    if (empty($data['consent'])) $fields['consent'] = 'Please agree to how ReSoK will use your application.';

    [$cvMime, $cvData, $cvError] = careersCheckDocument($files['cv'] ?? [], 'CV');
    if ($cvError) $fields['cv'] = $cvError;
    $letter = $files['letter'] ?? [];
    [$letterMime, $letterData, $letterError] = (($letter['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE)
        ? [null, null, null] : careersCheckDocument($letter, 'cover letter');
    if ($letterError) $fields['letter'] = $letterError;

    if ($fields) return [null, count($fields) === 1 ? reset($fields) : 'Please check the highlighted fields.', $fields];

    $dup = $pdo->prepare('SELECT 1 FROM careers_applications WHERE job_id = ? AND email = ? LIMIT 1');
    $dup->execute([(int)$job['id'], $email]);
    if ($dup->fetch()) return [null, 'You have already applied for this vacancy with this email address. Contact us if you need to update your application.', []];

    $cvName = careersStoreFile($config, (string)$cvData);
    $letterName = $letterData !== null ? careersStoreFile($config, $letterData) : null;
    if ($cvName === null || ($letterData !== null && $letterName === null)) {
        return [null, 'Your files could not be saved. Please try again later.', []];
    }

    for ($attempt = 0; ; $attempt++) {
        $reference = careersNextReference($pdo);
        try {
            $pdo->prepare('INSERT INTO careers_applications (job_id, reference, first_name, last_name, email, phone, county, answers, cover_note,
                           cv_file, cv_mime, cv_bytes, letter_file, letter_mime, letter_bytes, consent_at)
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())')
                ->execute([(int)$job['id'], $reference, trim((string)$data['firstName']), trim((string)$data['lastName']), $email,
                           cryptoEncrypt($config, $phone), $county, cryptoEncrypt($config, json_encode($answers, JSON_UNESCAPED_UNICODE)),
                           cryptoEncrypt($config, careersText($data['coverNote'] ?? '', 4000)) ?: null,
                           $cvName, $cvMime, strlen((string)$cvData), $letterName, $letterMime, $letterData !== null ? strlen($letterData) : null]);
            break;
        } catch (PDOException $e) {
            // Two candidates in the same second take the same next number; the second retries.
            // A duplicate (job, email) is the same person twice and is answered as such.
            if (($e->errorInfo[1] ?? 0) !== 1062 || $attempt >= 4) throw $e;
            if (stripos($e->getMessage(), 'careers_applications_once') !== false) {
                @unlink(careersFileDir($config) . '/' . $cvName);
                if ($letterName) @unlink(careersFileDir($config) . '/' . $letterName);
                return [null, 'You have already applied for this vacancy with this email address.', []];
            }
        }
    }
    $id = (int)$pdo->lastInsertId();
    careersAudit($pdo, (int)$job['id'], $id, null, 'applied');

    careersSend($config, $email, 'Application received: ' . $job['title'],
        'Dear ' . trim((string)$data['firstName']) . ",\n\nThank you for applying for the position of " . $job['title'] . ' at the Respiratory Society of Kenya. '
        . "Your reference is {$reference}.\n\nWe will review every application after the closing date and contact shortlisted candidates. "
        . 'If you are not contacted within four weeks of the closing date, please take it that your application was not successful this time.');
    careersNotifyRecruiters($pdo, $config, 'New application for ' . $job['title'], "{$reference} has applied for {$job['title']}. Sign in to review it.");
    return [$reference, null, []];
}

// ---------------------------------------------------------------------------------------
// Reviewing
// ---------------------------------------------------------------------------------------

function careersApplicationSummary(PDO $pdo, array $a): array
{
    $score = $pdo->prepare('SELECT ROUND(AVG(score), 1) AS avg, COUNT(score) AS n FROM careers_notes WHERE application_id = ? AND score IS NOT NULL');
    $score->execute([(int)$a['id']]);
    $s = $score->fetch() ?: ['avg' => null, 'n' => 0];
    return [
        'id' => (int)$a['id'], 'reference' => $a['reference'], 'jobId' => (int)$a['job_id'],
        'name' => trim(($a['first_name'] ?? '') . ' ' . ($a['last_name'] ?? '')) ?: 'Removed under retention',
        'email' => $a['email'], 'county' => $a['county'], 'status' => $a['status'], 'statusLabel' => CAREERS_STATUSES[$a['status']] ?? $a['status'],
        'appliedAt' => careersIso($a['created_at']), 'purged' => $a['purged_at'] !== null,
        'score' => $s['avg'] !== null ? (float)$s['avg'] : null, 'scores' => (int)$s['n'],
    ];
}

function careersApplicationFull(PDO $pdo, array $config, array $a): array
{
    $notes = $pdo->prepare('SELECT n.*, u.email FROM careers_notes n LEFT JOIN users u ON u.id = n.user_id WHERE n.application_id = ? ORDER BY n.id');
    $notes->execute([(int)$a['id']]);
    $audit = $pdo->prepare('SELECT a.action, a.detail, a.created_at, u.email FROM careers_audit a LEFT JOIN users u ON u.id = a.user_id
                            WHERE a.application_id = ? ORDER BY a.id DESC LIMIT 100');
    $audit->execute([(int)$a['id']]);
    $answers = json_decode((string)cryptoDecrypt($config, $a['answers']), true);
    $base = 'api/index.php?route=careers/admin/applications/' . (int)$a['id'] . '/';
    return careersApplicationSummary($pdo, $a) + [
        'firstName' => $a['first_name'], 'lastName' => $a['last_name'],
        'phone' => cryptoDecrypt($config, $a['phone']),
        'answers' => is_array($answers) ? $answers : [],
        'coverNote' => (string)cryptoDecrypt($config, $a['cover_note']),
        'cv' => $a['cv_file'] ? ['url' => $base . 'cv', 'type' => $a['cv_mime'] === 'application/pdf' ? 'PDF' : 'Word', 'bytes' => (int)$a['cv_bytes']] : null,
        'letter' => $a['letter_file'] ? ['url' => $base . 'letter', 'type' => $a['letter_mime'] === 'application/pdf' ? 'PDF' : 'Word', 'bytes' => (int)$a['letter_bytes']] : null,
        'notes' => array_map(static fn($n) => ['by' => $n['email'], 'score' => $n['score'] !== null ? (int)$n['score'] : null,
                                               'body' => (string)cryptoDecrypt($config, $n['body']), 'at' => careersIso($n['created_at'])], $notes->fetchAll()),
        'audit' => array_map(static fn($e) => ['action' => $e['action'], 'by' => $e['email'], 'at' => careersIso($e['created_at']),
                                               'detail' => json_decode((string)$e['detail'], true)], $audit->fetchAll()),
    ];
}

/** @return never */
function careersFileSend(array $config, array $a, string $which): void
{
    $file = $a[$which . '_file'] ?? null;
    if (!$file) respond(404, ['error' => 'File not found.']);
    $path = careersFileDir($config) . '/' . basename((string)$file);
    if (!is_file($path)) respond(404, ['error' => 'File not found.']);
    $data = cryptoDecrypt($config, (string)file_get_contents($path));
    if ($data === null) respond(500, ['error' => 'This file could not be decrypted. Check the server\'s data_encryption_key.']);
    $pdf = $a[$which . '_mime'] === 'application/pdf';
    $name = $a['reference'] . '-' . ($which === 'cv' ? 'CV' : 'cover-letter') . ($pdf ? '.pdf' : '.docx');
    header_remove('Content-Type');
    header('Content-Type: ' . ($pdf ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'));
    header('Content-Length: ' . strlen($data));
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    echo $data;
    exit;
}

function careersSetStatus(PDO $pdo, array $a, string $status, int $userId): ?string
{
    if (!array_key_exists($status, CAREERS_STATUSES)) return 'Unknown status.';
    if ($a['purged_at'] !== null) return 'This application has been removed under the retention policy.';
    $pdo->prepare('UPDATE careers_applications SET status = ? WHERE id = ?')->execute([$status, (int)$a['id']]);
    careersAudit($pdo, (int)$a['job_id'], (int)$a['id'], $userId, 'status_changed', ['from' => $a['status'], 'to' => $status]);
    return null;
}

function careersAddNote(PDO $pdo, array $config, array $a, string $body, $score, int $userId): ?string
{
    $body = careersText($body, 3000);
    $score = ($score === null || $score === '') ? null : (int)$score;
    if ($score !== null && ($score < 1 || $score > 5)) return 'Scores run from 1 to 5.';
    if ($body === '' && $score === null) return 'Write a note or give a score.';
    $pdo->prepare('INSERT INTO careers_notes (application_id, user_id, score, body) VALUES (?, ?, ?, ?)')
        ->execute([(int)$a['id'], $userId, $score, $body !== '' ? cryptoEncrypt($config, $body) : null]);
    careersAudit($pdo, (int)$a['job_id'], (int)$a['id'], $userId, 'note_added', $score !== null ? ['score' => $score] : null);
    return null;
}

/**
 * Emails a group of candidates the same message, each with their own name and reference.
 * Used for interview invitations and regret letters.
 *
 * @return array{sent: int, failed: int}
 */
function careersBulkEmail(PDO $pdo, array $config, array $job, array $ids, string $subject, string $body, ?string $setStatus, int $userId): array
{
    $sent = 0;
    $failed = 0;
    foreach (array_slice(array_unique(array_map('intval', $ids)), 0, 300) as $id) {
        $a = careersApplicationRow($pdo, $id);
        if (!$a || (int)$a['job_id'] !== (int)$job['id'] || $a['purged_at'] !== null || !$a['email']) continue;
        $vars = ['{{name}}' => (string)$a['first_name'], '{{fullName}}' => trim($a['first_name'] . ' ' . $a['last_name']),
                 '{{reference}}' => $a['reference'], '{{position}}' => $job['title']];
        $ok = careersSend($config, $a['email'], strtr($subject, $vars), strtr($body, $vars), true);
        $ok ? $sent++ : $failed++;
        careersAudit($pdo, (int)$job['id'], $id, $userId, $ok ? 'emailed' : 'email_failed', ['subject' => mb_substr(strtr($subject, $vars), 0, 160)]);
        if ($ok && $setStatus !== null && array_key_exists($setStatus, CAREERS_STATUSES) && $a['status'] !== $setStatus) {
            careersSetStatus($pdo, $a, $setStatus, $userId);
        }
    }
    return ['sent' => $sent, 'failed' => $failed];
}

function careersExportCsv(PDO $pdo, array $config, array $job): string
{
    $stmt = $pdo->prepare('SELECT * FROM careers_applications WHERE job_id = ? ORDER BY id');
    $stmt->execute([(int)$job['id']]);
    $fh = fopen('php://temp', 'w+');
    $questions = careersQuestions($job['questions']);
    $header = ['Reference', 'First name', 'Last name', 'Email', 'Phone', 'County', 'Status', 'Average score', 'Applied'];
    foreach ($questions as $q) $header[] = $q['label'];
    fputcsv($fh, $header);
    // A cell starting with = + - @ is a formula to a spreadsheet; a quote in front keeps it text.
    $safe = static fn($v) => is_string($v) && $v !== '' && strpbrk($v[0], '=+-@') !== false ? "'" . $v : $v;
    foreach ($stmt->fetchAll() as $a) {
        $s = careersApplicationSummary($pdo, $a);
        $answers = json_decode((string)cryptoDecrypt($config, $a['answers']), true) ?: [];
        $row = [$a['reference'], $a['first_name'], $a['last_name'], $a['email'], cryptoDecrypt($config, $a['phone']), $a['county'],
                CAREERS_STATUSES[$a['status']] ?? $a['status'], $s['score'], $a['created_at']];
        foreach (array_keys($questions) as $i) $row[] = $answers[$i]['answer'] ?? '';
        fputcsv($fh, array_map($safe, $row));
    }
    rewind($fh);
    return (string)stream_get_contents($fh);
}

// ---------------------------------------------------------------------------------------
// Email
// ---------------------------------------------------------------------------------------

function careersSend(array $config, string $to, string $subject, string $text, bool $fromRecruiter = false): bool
{
    if (!class_exists('SimpleMailer') || !function_exists('brandedEmailHtml') || $to === '') return false;
    $signoff = "\n\nRespiratory Society of Kenya\nwww.resok.org";
    $paras = '';
    foreach (preg_split('/\n{2,}/', trim($text)) as $p) {
        $paras .= '<p style="margin:0 0 14px;font-size:15px;line-height:1.65;">' . nl2br(htmlspecialchars($p, ENT_QUOTES)) . '</p>';
    }
    try {
        $mailer = new SimpleMailer($config);
        $ok = $mailer->send($to, $subject, $text . $signoff, [], brandedEmailHtml($subject, $paras));
        if (!$ok) error_log('Careers email not sent to ' . $to . ': ' . ($mailer->lastError ?? 'unknown'));
        return $ok;
    } catch (Throwable $e) {
        error_log('Careers email failed: ' . $e->getMessage());
        return false;
    }
}

function careersNotifyRecruiters(PDO $pdo, array $config, string $subject, string $message): void
{
    $emails = array_column($pdo->query("SELECT u.email FROM careers_roles r JOIN users u ON u.id = r.user_id WHERE r.role = 'recruiter'")->fetchAll(), 'email');
    if (!$emails) $emails = (array)($config['super_admins'] ?? []);
    $base = rtrim((string)($config['portal_base_url'] ?? ''), '/') ?: 'https://www.resok.org/resok-portal/public';
    foreach (array_unique(array_map('strtolower', $emails)) as $email) {
        careersSend($config, $email, $subject, $message . "\n\n" . $base . '/careers-admin');
    }
}

// ---------------------------------------------------------------------------------------
// Retention
// ---------------------------------------------------------------------------------------

/**
 * Removes unsuccessful candidates' data once their vacancy has been closed for the
 * retention period. Hired candidates are kept: they are now staff records.
 *
 * @return int applications purged
 */
function careersPurgeDue(PDO $pdo, array $config, int $limit = 100): int
{
    $months = careersRetentionMonths($config);
    $stmt = $pdo->prepare("SELECT a.* FROM careers_applications a JOIN careers_jobs j ON j.id = a.job_id
                           WHERE a.purged_at IS NULL AND a.status <> 'hired'
                             AND (j.status = 'closed' OR j.closes_at IS NOT NULL)
                             AND COALESCE(j.closes_at, j.updated_at) < (NOW() - INTERVAL {$months} MONTH)
                           LIMIT {$limit}");
    $stmt->execute();
    $n = 0;
    foreach ($stmt->fetchAll() as $a) {
        foreach (['cv_file', 'letter_file'] as $col) {
            if ($a[$col]) @unlink(careersFileDir($config) . '/' . basename((string)$a[$col]));
        }
        $pdo->prepare('DELETE FROM careers_notes WHERE application_id = ?')->execute([(int)$a['id']]);
        $pdo->prepare('UPDATE careers_applications SET first_name = NULL, last_name = NULL, email = NULL, phone = NULL, answers = NULL,
                       cover_note = NULL, cv_file = NULL, letter_file = NULL, purged_at = NOW() WHERE id = ?')->execute([(int)$a['id']]);
        careersAudit($pdo, (int)$a['job_id'], (int)$a['id'], null, 'purged');
        $n++;
    }
    return $n;
}

// ---------------------------------------------------------------------------------------
// Routes
// ---------------------------------------------------------------------------------------

/**
 * Every careers/* route. Called from index.php once the route is known to start with
 * "careers".
 *
 * @return never
 */
function careersHandle(PDO $pdo, array $config, string $route, string $method): void
{
    if (!careersEnsureTables($pdo)) {
        respond(503, ['error' => 'Careers is unavailable: its tables could not be created. Import resok-portal/server/schema-careers.sql.']);
    }

    // ------------------------------------------------------------- public
    if ($route === 'careers/jobs' && $method === 'GET') {
        $rows = $pdo->query("SELECT * FROM careers_jobs WHERE status = 'published' AND (closes_at IS NULL OR closes_at > NOW()) ORDER BY closes_at")->fetchAll();
        respond(200, ['jobs' => array_map(static fn($j) => careersJobShape($j), $rows)]);
    }
    if (preg_match('#^careers/jobs/([a-z0-9-]{1,120})$#', $route, $m) && $method === 'GET') {
        $stmt = $pdo->prepare("SELECT * FROM careers_jobs WHERE slug = ? AND status IN ('published','closed') LIMIT 1");
        $stmt->execute([$m[1]]);
        $job = $stmt->fetch();
        if (!$job) respond(404, ['error' => 'This vacancy could not be found.']);
        respond(200, ['job' => careersJobShape($job)]);
    }
    if ($route === 'careers/apply' && $method === 'POST') {
        $data = $_POST;
        botScreenOrFakeSuccess($pdo, $config, $data, 'careers-apply', ['reference' => 'CAR-' . date('Y') . '-' . str_pad((string)random_int(1, 9999), 4, '0', STR_PAD_LEFT)]);
        $email = strtolower(trim((string)($data['email'] ?? '')));
        throttleCheck($pdo, $config, 'careers-apply', $email);
        $stmt = $pdo->prepare("SELECT * FROM careers_jobs WHERE slug = ? AND status = 'published' LIMIT 1");
        $stmt->execute([(string)($data['job'] ?? '')]);
        $job = $stmt->fetch();
        if (!$job) respond(404, ['error' => 'This vacancy could not be found.']);
        [$reference, $error, $fields] = careersApply($pdo, $config, $job, $data, $_FILES);
        if ($error) respond(400, ['error' => $error, 'fields' => $fields]);
        throttleFailure($pdo, $config, 'careers-apply', $email);   // every application counts towards the limit
        respond(201, ['reference' => $reference]);
    }

    // ------------------------------------------------------------- recruiters
    $user = auth($config);
    $userId = (int)$user['userId'];
    if (!careersIsRecruiter($pdo, $user, $config)) respond(404, ['error' => 'Route not found']);
    $super = isSuperAdmin($user, $config);

    if ($route === 'careers/admin' && $method === 'GET') {
        careersPurgeDue($pdo, $config, 20);
        $jobs = [];
        foreach ($pdo->query('SELECT * FROM careers_jobs ORDER BY created_at DESC')->fetchAll() as $j) {
            $count = $pdo->prepare('SELECT status, COUNT(*) AS n FROM careers_applications WHERE job_id = ? GROUP BY status');
            $count->execute([(int)$j['id']]);
            $by = array_column($count->fetchAll(), 'n', 'status');
            $jobs[] = careersJobShape($j, true) + ['applications' => array_sum(array_map('intval', $by)), 'byStatus' => array_map('intval', $by)];
        }
        respond(200, ['jobs' => $jobs, 'email' => $user['email'] ?? '', 'superAdmin' => $super,
                      'types' => CAREERS_TYPES, 'statuses' => CAREERS_STATUSES, 'retentionMonths' => careersRetentionMonths($config)]);
    }

    if ($route === 'careers/admin/jobs' && $method === 'POST') {
        $data = input();
        [$job, $error] = careersJobSave($pdo, (int)($data['id'] ?? 0) ?: null, $data, $userId);
        if ($error) respond(400, ['error' => $error]);
        respond(200, ['job' => $job]);
    }

    if (preg_match('#^careers/admin/jobs/(\d+)/(applications|email|export)$#', $route, $m)) {
        $job = careersJobRow($pdo, (int)$m[1]);
        if (!$job) respond(404, ['error' => 'No such vacancy.']);
        if ($m[2] === 'applications' && $method === 'GET') {
            $stmt = $pdo->prepare("SELECT * FROM careers_applications WHERE job_id = ?
                                   ORDER BY FIELD(status, 'new', 'shortlisted', 'interview', 'offered', 'hired', 'unsuccessful', 'withdrawn'), id");
            $stmt->execute([(int)$job['id']]);
            respond(200, ['job' => careersJobShape($job, true), 'applications' => array_map(static fn($a) => careersApplicationSummary($pdo, $a), $stmt->fetchAll())]);
        }
        if ($m[2] === 'email' && $method === 'POST') {
            $data = input();
            $subject = careersText($data['subject'] ?? '', 200);
            $body = careersText($data['body'] ?? '', 6000);
            if ($subject === '' || $body === '') respond(400, ['error' => 'Write a subject and a message.']);
            if (empty($data['ids'])) respond(400, ['error' => 'Choose at least one candidate.']);
            $status = isset($data['setStatus']) && $data['setStatus'] !== '' ? (string)$data['setStatus'] : null;
            respond(200, careersBulkEmail($pdo, $config, $job, (array)$data['ids'], $subject, $body, $status, $userId));
        }
        if ($m[2] === 'export' && $method === 'GET') {
            careersAudit($pdo, (int)$job['id'], null, $userId, 'exported');
            header_remove('Content-Type');
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $job['slug'] . '-applicants.csv"');
            header('Cache-Control: private, no-store');
            echo "\xEF\xBB\xBF" . careersExportCsv($pdo, $config, $job);
            exit;
        }
        respond(405, ['error' => 'Method not allowed']);
    }

    if (preg_match('#^careers/admin/applications/(\d+)(/[a-z]+)?$#', $route, $m)) {
        $a = careersApplicationRow($pdo, (int)$m[1]);
        if (!$a) respond(404, ['error' => 'No such application.']);
        $action = $m[2] ?? '';
        if ($action === '' && $method === 'GET') {
            careersAudit($pdo, (int)$a['job_id'], (int)$a['id'], $userId, 'viewed');
            respond(200, ['application' => careersApplicationFull($pdo, $config, $a)]);
        }
        if (($action === '/cv' || $action === '/letter') && $method === 'GET') {
            careersAudit($pdo, (int)$a['job_id'], (int)$a['id'], $userId, $action === '/cv' ? 'cv_downloaded' : 'letter_downloaded');
            careersFileSend($config, $a, ltrim($action, '/'));
        }
        if ($action === '/status' && $method === 'POST') {
            $error = careersSetStatus($pdo, $a, (string)(input()['status'] ?? ''), $userId);
            if ($error) respond(400, ['error' => $error]);
            respond(200, ['application' => careersApplicationFull($pdo, $config, careersApplicationRow($pdo, (int)$a['id']))]);
        }
        if ($action === '/notes' && $method === 'POST') {
            $data = input();
            $error = careersAddNote($pdo, $config, $a, (string)($data['body'] ?? ''), $data['score'] ?? null, $userId);
            if ($error) respond(400, ['error' => $error]);
            respond(200, ['application' => careersApplicationFull($pdo, $config, $a)]);
        }
        respond(405, ['error' => 'Method not allowed']);
    }

    if (strpos($route, 'careers/admin/roles') === 0) {
        if (!$super) respond(403, ['error' => 'Only a super administrator can appoint recruiters.']);
        $list = static fn() => array_map(static fn($r) => ['id' => (int)$r['id'], 'email' => $r['email'], 'since' => careersIso($r['created_at'])],
            $pdo->query("SELECT r.id, r.created_at, u.email FROM careers_roles r JOIN users u ON u.id = r.user_id WHERE r.role = 'recruiter' ORDER BY u.email")->fetchAll());
        if ($route === 'careers/admin/roles' && $method === 'GET') respond(200, ['roles' => $list()]);
        if ($route === 'careers/admin/roles' && $method === 'POST') {
            $stmt = $pdo->prepare('SELECT id FROM users WHERE LOWER(email) = ? LIMIT 1');
            $stmt->execute([strtolower(trim((string)(input()['email'] ?? '')))]);
            $target = (int)($stmt->fetchColumn() ?: 0);
            if (!$target) respond(400, ['error' => 'No portal account uses that email. Ask the person to create one first.']);
            $pdo->prepare("INSERT IGNORE INTO careers_roles (user_id, role, granted_by) VALUES (?, 'recruiter', ?)")->execute([$target, $userId]);
            careersAudit($pdo, null, null, $userId, 'recruiter_appointed', ['user' => $target]);
            respond(200, ['roles' => $list()]);
        }
        if (preg_match('#^careers/admin/roles/(\d+)/revoke$#', $route, $m) && $method === 'POST') {
            $pdo->prepare('DELETE FROM careers_roles WHERE id = ?')->execute([(int)$m[1]]);
            careersAudit($pdo, null, null, $userId, 'recruiter_removed', ['role' => (int)$m[1]]);
            respond(200, ['roles' => $list()]);
        }
    }

    respond(404, ['error' => 'Route not found']);
}
