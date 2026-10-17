<?php
declare(strict_types=1);

/**
 * Presentation uploads: presenters send their slides before the conference, and the
 * programme team downloads them for the session-room computers.
 *
 * Who may upload, and for what (a "slot"):
 *   abstract:<id>               an accepted, released abstract - its presenting author or its
 *                               submitter
 *   speaker:<speakerId>:<sid>   an invited speaker's linked session (conference-speakers.php)
 *
 * Files: PowerPoint (.pptx, .ppt) or PDF, checked by content, not by name. Macro-enabled
 * formats (.pptm, .ppsm) are refused: these files are opened on shared room computers.
 * Slides are routinely 20-50 MB, above what shared hosting accepts in one request, so the
 * browser sends 1 MB pieces that are appended in order and checked when complete.
 *
 * Every upload is kept as a version; the newest is the one the room gets. Files live outside
 * the web root and are served through the API as downloads only.
 */

const CONF_PRES_MAX_BYTES = 104857600;       // 100 MB
const CONF_PRES_CHUNK_BYTES = 1048576;       // 1 MB pieces
const CONF_PRES_EXTENSIONS = ['pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                              'ppt' => 'application/vnd.ms-powerpoint', 'pdf' => 'application/pdf'];

function confPresSchema(): array
{
    $tail = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    return [
        "CREATE TABLE IF NOT EXISTS conf_presentation_settings (
            event_id INT UNSIGNED NOT NULL,
            open TINYINT(1) NOT NULL DEFAULT 0,
            deadline DATETIME NULL,
            PRIMARY KEY (event_id)
        )" . $tail,
        "CREATE TABLE IF NOT EXISTS conf_presentations (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            event_id INT UNSIGNED NOT NULL,
            slot VARCHAR(40) NOT NULL,
            version SMALLINT UNSIGNED NOT NULL DEFAULT 1,
            file_name VARCHAR(80) NOT NULL,
            original_name VARCHAR(200) NOT NULL,
            mime VARCHAR(120) NOT NULL,
            size_bytes INT UNSIGNED NOT NULL,
            sha256 CHAR(64) NOT NULL,
            uploaded_by INT UNSIGNED NOT NULL,
            uploaded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY conf_presentations_slot (event_id, slot, version)
        )" . $tail,
        "CREATE TABLE IF NOT EXISTS conf_presentation_uploads (
            id CHAR(32) NOT NULL,
            event_id INT UNSIGNED NOT NULL,
            slot VARCHAR(40) NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            original_name VARCHAR(200) NOT NULL,
            ext VARCHAR(5) NOT NULL,
            size_bytes INT UNSIGNED NOT NULL,
            received INT UNSIGNED NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY conf_presentation_uploads_user (user_id)
        )" . $tail,
    ];
}

function confPresEnsureTables(PDO $pdo): bool
{
    static $ready = null;
    if ($ready !== null) return $ready;
    try {
        foreach (confPresSchema() as $sql) $pdo->exec($sql);
        return $ready = true;
    } catch (Throwable $e) {
        error_log('Presentation tables unavailable: ' . $e->getMessage());
        return $ready = false;
    }
}

function confPresDir(array $config): string
{
    return rtrim((string)$config['upload_dir'], '/\\') . '/conference-presentations';
}

function confPresSettings(PDO $pdo, int $eventId): array
{
    $stmt = $pdo->prepare('SELECT * FROM conf_presentation_settings WHERE event_id = ?');
    $stmt->execute([$eventId]);
    $row = $stmt->fetch() ?: [];
    $deadline = $row['deadline'] ?? null;
    $switchedOn = !empty($row['open']);
    return ['switchedOn' => $switchedOn, 'deadline' => $deadline,
            'open' => $switchedOn && (!$deadline || strtotime((string)$deadline) > time())];
}

function confPresSaveSettings(PDO $pdo, int $eventId, array $data, int $by): ?string
{
    $cur = confPresSettings($pdo, $eventId);
    $open = array_key_exists('open', $data) ? !empty($data['open']) : $cur['switchedOn'];
    $deadline = $cur['deadline'];
    if (array_key_exists('deadline', $data)) {
        $v = str_replace('T', ' ', trim(confStr($data['deadline'])));
        $deadline = null;
        if ($v !== '') {
            foreach (['Y-m-d H:i', 'Y-m-d H:i:s', 'Y-m-d'] as $f) {
                $d = DateTimeImmutable::createFromFormat('!' . $f, $v);
                if ($d && $d->format($f) === $v) { $deadline = $d->format($f === 'Y-m-d' ? 'Y-m-d 23:59:00' : 'Y-m-d H:i:s'); break; }
            }
            if ($deadline === null) return 'The deadline must look like 2027-08-01 23:59.';
        }
    }
    $pdo->prepare('INSERT INTO conf_presentation_settings (event_id, open, deadline) VALUES (?, ?, ?)
                   ON DUPLICATE KEY UPDATE open = VALUES(open), deadline = VALUES(deadline)')
        ->execute([$eventId, $open ? 1 : 0, $deadline]);
    if (function_exists('confAudit')) confAudit($pdo, $eventId, null, $by, 'presentations_settings', ['open' => $open, 'deadline' => $deadline]);
    return null;
}

/**
 * Every slot of the conference, with who presents it and where it sits in the programme.
 * @return list<array<string,mixed>>
 */
function confPresAllSlots(PDO $pdo, int $eventId): array
{
    $slots = [];
    $stmt = $pdo->prepare("SELECT a.id, a.reference, a.title, a.submitter_user_id, d.presentation_type,
                                  au.first_name, au.last_name, au.email AS presenter_email, au.user_id AS presenter_user_id
                           FROM abs_abstracts a
                           JOIN abs_decisions d ON d.abstract_id = a.id
                           LEFT JOIN abs_authors au ON au.abstract_id = a.id AND au.is_presenting = 1
                           WHERE a.event_id = ? AND d.outcome = 'accept' AND d.released_at IS NOT NULL AND a.status <> 'withdrawn'
                           ORDER BY a.reference");
    $stmt->execute([$eventId]);
    foreach ($stmt->fetchAll() as $r) {
        $slots['abstract:' . $r['id']] = [
            'slot' => 'abstract:' . $r['id'], 'kind' => 'abstract', 'reference' => $r['reference'],
            'title' => function_exists('absPlain') ? absPlain((string)$r['title']) : strip_tags((string)$r['title']),
            'type' => $r['presentation_type'], 'presenter' => trim($r['first_name'] . ' ' . $r['last_name']),
            'presenterEmail' => $r['presenter_email'], '_users' => array_filter([(int)$r['submitter_user_id'], (int)$r['presenter_user_id']]),
            '_emails' => array_filter([strtolower((string)$r['presenter_email'])]), 'session' => null,
        ];
    }
    // Where each abstract sits in the programme, if it has been placed.
    try {
        $place = $pdo->prepare('SELECT i.abstract_id, s.id, s.title, s.day, s.starts_at, r.name AS room FROM conf_session_items i
                                JOIN conf_sessions s ON s.id = i.session_id LEFT JOIN conf_rooms r ON r.id = s.room_id
                                WHERE s.event_id = ? AND i.abstract_id IS NOT NULL');
        $place->execute([$eventId]);
        foreach ($place->fetchAll() as $p) {
            $k = 'abstract:' . $p['abstract_id'];
            if (isset($slots[$k])) $slots[$k]['session'] = ['id' => (int)$p['id'], 'title' => $p['title'], 'day' => $p['day'],
                                                             'startsAt' => substr((string)$p['starts_at'], 0, 5), 'room' => $p['room']];
        }
        $sp = $pdo->prepare('SELECT sp.id AS speaker_id, sp.name, sp.honorific, sp.email, sp.user_id, ss.role, s.id AS session_id, s.title, s.day, s.starts_at, r.name AS room
                             FROM conf_speakers sp JOIN conf_speaker_sessions ss ON ss.speaker_id = sp.id
                             JOIN conf_sessions s ON s.id = ss.session_id LEFT JOIN conf_rooms r ON r.id = s.room_id
                             WHERE sp.event_id = ? AND ss.role <> "chair" ORDER BY s.day, s.starts_at');
        $sp->execute([$eventId]);
        foreach ($sp->fetchAll() as $r) {
            $k = 'speaker:' . $r['speaker_id'] . ':' . $r['session_id'];
            $slots[$k] = [
                'slot' => $k, 'kind' => 'speaker', 'reference' => null, 'title' => $r['title'], 'type' => $r['role'],
                'presenter' => trim(($r['honorific'] ? $r['honorific'] . ' ' : '') . $r['name']), 'presenterEmail' => $r['email'],
                '_users' => array_filter([(int)$r['user_id']]), '_emails' => array_filter([strtolower((string)$r['email'])]),
                'session' => ['id' => (int)$r['session_id'], 'title' => $r['title'], 'day' => $r['day'],
                              'startsAt' => substr((string)$r['starts_at'], 0, 5), 'room' => $r['room']],
            ];
        }
    } catch (Throwable $e) {
        // Programme or speaker tables not created yet: abstracts only.
    }
    return array_values($slots);
}

/** The slots this person may upload to: by account, or by their verified email. */
function confPresSlotsFor(PDO $pdo, int $eventId, array $user): array
{
    $userId = (int)($user['userId'] ?? 0);
    $stmt = $pdo->prepare('SELECT LOWER(email) FROM users WHERE id = ? AND email_verified = 1');
    $stmt->execute([$userId]);
    $email = (string)($stmt->fetchColumn() ?: '');
    return array_values(array_filter(confPresAllSlots($pdo, $eventId), fn($s) =>
        in_array($userId, $s['_users'], true) || ($email !== '' && in_array($email, $s['_emails'], true))));
}

function confPresSlotFor(PDO $pdo, int $eventId, array $user, string $slot): ?array
{
    foreach (confPresSlotsFor($pdo, $eventId, $user) as $s) if ($s['slot'] === $slot) return $s;
    return null;
}

/** Latest file per slot, and how many versions. @return array<string,array> */
function confPresLatest(PDO $pdo, int $eventId): array
{
    $stmt = $pdo->prepare('SELECT p.*, (SELECT COUNT(*) FROM conf_presentations x WHERE x.event_id = p.event_id AND x.slot = p.slot) AS versions
                           FROM conf_presentations p
                           WHERE p.event_id = ? AND p.version = (SELECT MAX(y.version) FROM conf_presentations y WHERE y.event_id = p.event_id AND y.slot = p.slot)');
    $stmt->execute([$eventId]);
    $out = [];
    foreach ($stmt->fetchAll() as $r) {
        $out[$r['slot']] = ['id' => (int)$r['id'], 'version' => (int)$r['version'], 'versions' => (int)$r['versions'],
                            'name' => $r['original_name'], 'size' => (int)$r['size_bytes'], 'uploadedAt' => $r['uploaded_at'],
                            'downloadUrl' => 'api/index.php?route=conference/presentations/file/' . (int)$r['id']];
    }
    return $out;
}

function confPresShape(array $slot, array $latest): array
{
    unset($slot['_users'], $slot['_emails']);
    return $slot + ['file' => $latest[$slot['slot']] ?? null];
}

/** Starts an upload. @return array{0: ?string, 1: ?string} [upload id, error] */
function confPresStart(PDO $pdo, array $event, array $user, array $data): array
{
    $eventId = (int)$event['id'];
    if (!confPresSettings($pdo, $eventId)['open']) return [null, 'Presentation uploads are closed.'];
    $slot = confStr($data['slot'] ?? '');
    if (!confPresSlotFor($pdo, $eventId, $user, $slot)) return [null, 'You are not the presenter of that item.'];
    $name = trim(preg_replace('/[\x00-\x1F\x7F\/\\\\]+/u', '', confStr($data['fileName'] ?? '')) ?? '');
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (!isset(CONF_PRES_EXTENSIONS[$ext])) return [null, 'Upload PowerPoint (.pptx or .ppt) or PDF. Macro-enabled files (.pptm) are not accepted.'];
    $size = (int)($data['size'] ?? 0);
    if ($size <= 0) return [null, 'The file is empty.'];
    if ($size > CONF_PRES_MAX_BYTES) return [null, 'The file must be 100 MB or smaller. Compress large images or videos in PowerPoint and try again.'];
    // One upload in progress per person: abandoned ones are replaced, not piled up.
    foreach ($pdo->query('SELECT id FROM conf_presentation_uploads WHERE user_id = ' . (int)$user['userId'])->fetchAll(PDO::FETCH_COLUMN) as $old) {
        @unlink(confPresTempPath($old));
    }
    $pdo->prepare('DELETE FROM conf_presentation_uploads WHERE user_id = ?')->execute([(int)$user['userId']]);
    $id = bin2hex(random_bytes(16));
    $pdo->prepare('INSERT INTO conf_presentation_uploads (id, event_id, slot, user_id, original_name, ext, size_bytes) VALUES (?, ?, ?, ?, ?, ?, ?)')
        ->execute([$id, $eventId, $slot, (int)$user['userId'], mb_substr($name, 0, 200), $ext, $size]);
    return [$id, null];
}

/**
 * Pieces wait inside the uploads folder (never served; see .htaccess), not the system temp
 * folder: on shared hosting that is shared with other accounts and may be emptied mid-upload.
 */
function confPresTempPath(string $uploadId): string
{
    $base = rtrim((string)($GLOBALS['config']['upload_dir'] ?? sys_get_temp_dir()), '/\\') . '/conference-presentations/.parts';
    if (!is_dir($base)) @mkdir($base, 0755, true);
    return $base . '/' . preg_replace('/[^a-f0-9]/', '', $uploadId) . '.part';
}

function confPresUploadRow(PDO $pdo, string $uploadId, int $userId): ?array
{
    if (!preg_match('/^[a-f0-9]{32}$/', $uploadId)) return null;
    $stmt = $pdo->prepare('SELECT * FROM conf_presentation_uploads WHERE id = ? AND user_id = ?');
    $stmt->execute([$uploadId, $userId]);
    return $stmt->fetch() ?: null;
}

/** Appends one piece at the expected offset. @return array{0: ?int, 1: ?string} [received, error] */
function confPresChunk(PDO $pdo, array $user, string $uploadId, int $offset, string $bytes): array
{
    $up = confPresUploadRow($pdo, $uploadId, (int)$user['userId']);
    if (!$up) return [null, 'That upload has expired. Start again.'];
    if ($offset !== (int)$up['received']) return [(int)$up['received'], 'out_of_order'];
    $len = strlen($bytes);
    if ($len === 0 || $len > CONF_PRES_CHUNK_BYTES) return [null, 'Each piece must be between 1 byte and 1 MB.'];
    if ($offset + $len > (int)$up['size_bytes']) return [null, 'The file is larger than announced.'];
    if (file_put_contents(confPresTempPath($uploadId), $bytes, FILE_APPEND | LOCK_EX) !== $len) return [null, 'The server could not store the upload.'];
    $pdo->prepare('UPDATE conf_presentation_uploads SET received = received + ? WHERE id = ? AND received = ?')->execute([$len, $uploadId, $offset]);
    return [$offset + $len, null];
}

/** What the bytes actually are; null when they are not an accepted presentation. */
function confPresSniff(string $path, string $ext): ?string
{
    $fh = @fopen($path, 'rb');
    if (!$fh) return null;
    $head = (string)fread($fh, 8);
    $size = filesize($path) ?: 0;
    // The ZIP central directory sits at the end: enough to see which parts the package has.
    fseek($fh, max(0, $size - 1048576));
    $tail = (string)fread($fh, 1048576);
    fclose($fh);
    if ($ext === 'pdf') return strncmp($head, '%PDF-', 5) === 0 ? CONF_PRES_EXTENSIONS['pdf'] : null;
    if ($ext === 'ppt') return $head === "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1" ? CONF_PRES_EXTENSIONS['ppt'] : null;
    if ($ext === 'pptx') {
        if (strncmp($head, "PK\x03\x04", 4) !== 0) return null;
        if (strpos($tail, '[Content_Types].xml') === false || strpos($tail, 'ppt/presentation.xml') === false) return null;
        // Macros make it a .pptm whatever the name says.
        if (strpos($tail, 'vbaProject.bin') !== false) return null;
        return CONF_PRES_EXTENSIONS['pptx'];
    }
    return null;
}

/** Checks the finished file and keeps it as the next version. @return array{0: ?array, 1: ?string} */
function confPresFinish(PDO $pdo, array $config, array $event, array $user, string $uploadId): array
{
    $up = confPresUploadRow($pdo, $uploadId, (int)$user['userId']);
    if (!$up) return [null, 'That upload has expired. Start again.'];
    $temp = confPresTempPath($uploadId);
    $drop = function () use ($pdo, $uploadId, $temp): void {
        @unlink($temp);
        $pdo->prepare('DELETE FROM conf_presentation_uploads WHERE id = ?')->execute([$uploadId]);
    };
    if ((int)$up['received'] !== (int)$up['size_bytes'] || !is_file($temp) || filesize($temp) !== (int)$up['size_bytes']) {
        $drop();
        return [null, 'The upload was incomplete. Please try again.'];
    }
    $eventId = (int)$event['id'];
    if (!confPresSlotFor($pdo, $eventId, $user, (string)$up['slot'])) { $drop(); return [null, 'You are not the presenter of that item.']; }
    if (!confPresSettings($pdo, $eventId)['open']) { $drop(); return [null, 'Presentation uploads are closed.']; }
    $mime = confPresSniff($temp, (string)$up['ext']);
    if (!$mime) {
        $drop();
        return [null, 'That file is not a valid ' . strtoupper((string)$up['ext']) . '. Save it again from PowerPoint as .pptx (or as PDF) and upload that. Macro-enabled presentations are not accepted.'];
    }
    $dir = confPresDir($config);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) { $drop(); return [null, 'Presentations cannot be stored on the server yet.']; }
    $file = 'pres-' . bin2hex(random_bytes(12)) . '.' . $up['ext'];
    if (!@rename($temp, $dir . '/' . $file) && !(@copy($temp, $dir . '/' . $file) && @unlink($temp))) { $drop(); return [null, 'The file could not be saved.']; }
    $ver = $pdo->prepare('SELECT COALESCE(MAX(version), 0) + 1 FROM conf_presentations WHERE event_id = ? AND slot = ?');
    $ver->execute([$eventId, $up['slot']]);
    $version = (int)$ver->fetchColumn();
    $pdo->prepare('INSERT INTO conf_presentations (event_id, slot, version, file_name, original_name, mime, size_bytes, sha256, uploaded_by)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$eventId, $up['slot'], $version, $file, $up['original_name'], $mime, (int)$up['size_bytes'],
                   hash_file('sha256', $dir . '/' . $file), (int)$user['userId']]);
    $pdo->prepare('DELETE FROM conf_presentation_uploads WHERE id = ?')->execute([$uploadId]);
    if (function_exists('confAudit')) confAudit($pdo, $eventId, null, (int)$user['userId'], 'presentation_uploaded', ['slot' => $up['slot'], 'version' => $version]);
    return [confPresLatest($pdo, $eventId)[$up['slot']] ?? null, null];
}

/** A stored file, if this person may have it: its presenter, or the programme team. */
function confPresFileFor(PDO $pdo, int $eventId, array $user, int $fileId, bool $isStaff): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM conf_presentations WHERE id = ? AND event_id = ?');
    $stmt->execute([$fileId, $eventId]);
    $row = $stmt->fetch();
    if (!$row) return null;
    if ($isStaff || confPresSlotFor($pdo, $eventId, $user, (string)$row['slot'])) return $row;
    return null;
}

/** Removes abandoned uploads older than a day. Run from the hourly job. */
function confPresCleanup(PDO $pdo): int
{
    if (!confPresEnsureTables($pdo)) return 0;
    $old = $pdo->query('SELECT id FROM conf_presentation_uploads WHERE created_at < (NOW() - INTERVAL 1 DAY)')->fetchAll(PDO::FETCH_COLUMN);
    foreach ($old as $id) @unlink(confPresTempPath((string)$id));
    if ($old) $pdo->exec('DELETE FROM conf_presentation_uploads WHERE created_at < (NOW() - INTERVAL 1 DAY)');
    // Pieces whose upload record is already gone (cancelled, replaced) are removed by age.
    foreach (glob(dirname(confPresTempPath(str_repeat('0', 32))) . '/*.part') ?: [] as $f) {
        if (filemtime($f) < time() - 86400) @unlink($f);
    }
    return count($old);
}

/** Reminds presenters without a file, 14 and 3 days before the deadline. */
function confPresSendReminders(PDO $pdo, array $config): int
{
    if (!confPresEnsureTables($pdo) || !function_exists('absSendLogged') || !function_exists('cardEmailHtml')) return 0;
    $sent = 0;
    foreach ($pdo->query("SELECT * FROM abs_events WHERE status = 'published'")->fetchAll() as $event) {
        $eventId = (int)$event['id'];
        $st = confPresSettings($pdo, $eventId);
        if (!$st['open'] || !$st['deadline']) continue;
        $daysLeft = (int)floor((strtotime((string)$st['deadline']) - time()) / 86400);
        $stage = $daysLeft <= 3 ? 3 : ($daysLeft <= 14 ? 14 : 0);
        if (!$stage) continue;
        $latest = confPresLatest($pdo, $eventId);
        $portal = rtrim((string)($config['portal_base_url'] ?? ''), '/') ?: 'https://www.resok.org/resok-portal/public';
        $h = fn($x) => htmlspecialchars((string)$x, ENT_QUOTES);
        $when = date('j F Y, H:i', strtotime((string)$st['deadline']));
        foreach (confPresAllSlots($pdo, $eventId) as $s) {
            if (isset($latest[$s['slot']]) || empty($s['presenterEmail'])) continue;
            $what = $s['reference'] ? $s['reference'] . ', "' . $s['title'] . '"' : '"' . $s['title'] . '"';
            $html = cardEmailHtml('Please upload your presentation', 'Dear ' . $s['presenter'] . ',',
                cardEmailParagraph('Please upload your slides for ' . $h($what) . ' at <strong>' . $h($event['name']) . '</strong> by <strong>' . $h($when) . '</strong>.')
                . cardEmailParagraph('PowerPoint (.pptx) or PDF, up to 100 MB. You can replace the file until the deadline; the newest version is the one loaded in your session room.', true),
                'Upload my presentation', $portal . '/conference-presentations', 'Sign in with the email address this message was sent to.');
            $text = "Dear {$s['presenter']},\n\nPlease upload your slides for {$what} at {$event['name']} by {$when}: {$portal}/conference-presentations\n\nPowerPoint (.pptx) or PDF, up to 100 MB.\n\nReSoK ICT";
            if (absSendLogged($pdo, $config, $eventId, null, 'conf_presentation_reminder', (string)$s['presenterEmail'],
                              'Please upload your presentation: ' . $event['name'], $text, $html, 'conf-pres-' . $s['slot'] . '-' . $stage)) {
                $sent++;
            }
        }
    }
    return $sent;
}
