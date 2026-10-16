<?php
declare(strict_types=1);

/**
 * Speaker profiles (platform blueprint: "invited speakers: bio, photo, sessions, travel").
 *
 * The programme team adds a speaker with the email they use, links them to their sessions,
 * and publishes the profile when it is ready. The speaker signs in with that email and fills
 * in their own bio, photo and travel. The public sees published profiles only: name, role,
 * institution, bio, photo and sessions. Email and travel never leave the staff side; travel
 * is shown to conference administrators only, who book hotels and airport pick-ups.
 *
 * Photos are JPG or PNG, stored outside the web root, with EXIF, XMP and PNG text stripped:
 * a phone photo's EXIF block often carries the GPS position of the speaker's home.
 */

const CONF_SPEAKER_PHOTO_MAX_BYTES = 2097152;
const CONF_SPEAKER_ROLES = ['speaker' => 'Speaker', 'keynote' => 'Keynote', 'chair' => 'Chair',
                            'panellist' => 'Panellist', 'moderator' => 'Moderator'];
const CONF_SPEAKER_ACCOMMODATION = ['' => 'Not said', 'needed' => 'Needs a room', 'own' => 'Own arrangements'];

function confSpeakerSchema(): array
{
    $tail = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    return [
        "CREATE TABLE IF NOT EXISTS conf_speakers (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            event_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NULL,
            email VARCHAR(190) NULL,
            honorific VARCHAR(30) NULL,
            name VARCHAR(120) NOT NULL,
            position VARCHAR(160) NULL,
            institution VARCHAR(200) NULL,
            country VARCHAR(60) NULL,
            bio TEXT NULL,
            photo_file VARCHAR(80) NULL,
            photo_mime VARCHAR(40) NULL,
            featured TINYINT(1) NOT NULL DEFAULT 0,
            published TINYINT(1) NOT NULL DEFAULT 0,
            sort_order INT NOT NULL DEFAULT 0,
            arrival_at DATETIME NULL,
            departure_at DATETIME NULL,
            flight VARCHAR(200) NULL,
            accommodation VARCHAR(10) NOT NULL DEFAULT '',
            travel_notes TEXT NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY conf_speakers_event (event_id, published, sort_order),
            KEY conf_speakers_user (user_id),
            KEY conf_speakers_email (email)
        )" . $tail,
        "CREATE TABLE IF NOT EXISTS conf_speaker_sessions (
            speaker_id INT UNSIGNED NOT NULL,
            session_id INT UNSIGNED NOT NULL,
            role VARCHAR(12) NOT NULL DEFAULT 'speaker',
            PRIMARY KEY (speaker_id, session_id),
            KEY conf_speaker_sessions_session (session_id)
        )" . $tail,
    ];
}

function confSpeakersEnsureTables(PDO $pdo): bool
{
    static $ready = null;
    if ($ready !== null) return $ready;
    try {
        foreach (confSpeakerSchema() as $sql) $pdo->exec($sql);
        return $ready = true;
    } catch (Throwable $e) {
        error_log('Speaker tables unavailable: ' . $e->getMessage());
        return $ready = false;
    }
}

function confSpeakerPhotoDir(array $config): string
{
    return rtrim((string)$config['upload_dir'], '/\\') . '/conference-speakers';
}

function confSpeakerRow(PDO $pdo, int $eventId, int $speakerId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM conf_speakers WHERE id = ? AND event_id = ?');
    $stmt->execute([$speakerId, $eventId]);
    return $stmt->fetch() ?: null;
}

/** The signed-in person's own profile: linked to their account, or waiting under their email. */
function confSpeakerForUser(PDO $pdo, int $eventId, array $user): ?array
{
    $userId = (int)($user['userId'] ?? 0);
    $stmt = $pdo->prepare('SELECT * FROM conf_speakers WHERE event_id = ? AND user_id = ? LIMIT 1');
    $stmt->execute([$eventId, $userId]);
    if ($row = $stmt->fetch()) return $row;
    // First visit: claim the profile the programme team set up under this (verified) email.
    $email = $pdo->prepare('SELECT email FROM users WHERE id = ?');
    $email->execute([$userId]);
    $address = strtolower(trim((string)$email->fetchColumn()));
    if ($address === '') return null;
    $stmt = $pdo->prepare('SELECT * FROM conf_speakers WHERE event_id = ? AND user_id IS NULL AND LOWER(email) = ? ORDER BY id LIMIT 1');
    $stmt->execute([$eventId, $address]);
    $row = $stmt->fetch();
    if (!$row) return null;
    $pdo->prepare('UPDATE conf_speakers SET user_id = ? WHERE id = ? AND user_id IS NULL')->execute([$userId, (int)$row['id']]);
    $row['user_id'] = $userId;
    return $row;
}

/** Sessions per speaker; times only once the programme is public, unless staff are asking. */
function confSpeakerSessions(PDO $pdo, array $speakerIds): array
{
    if (!$speakerIds) return [];
    $ids = implode(',', array_map('intval', $speakerIds));
    $out = [];
    try {
        $rows = $pdo->query("SELECT ss.speaker_id, ss.role, s.id, s.title, s.day, s.starts_at, s.ends_at, r.name AS room
                             FROM conf_speaker_sessions ss JOIN conf_sessions s ON s.id = ss.session_id LEFT JOIN conf_rooms r ON r.id = s.room_id
                             WHERE ss.speaker_id IN ($ids) ORDER BY s.day, s.starts_at")->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
    foreach ($rows as $r) {
        $out[(int)$r['speaker_id']][] = ['sessionId' => (int)$r['id'], 'title' => $r['title'], 'day' => $r['day'],
            'startsAt' => substr((string)$r['starts_at'], 0, 5), 'endsAt' => substr((string)$r['ends_at'], 0, 5),
            'room' => $r['room'], 'role' => $r['role'], 'roleLabel' => CONF_SPEAKER_ROLES[$r['role']] ?? 'Speaker'];
    }
    return $out;
}

/**
 * $view: 'public' (published only, no email or travel), 'staff' (everything but travel),
 * 'admin' (everything) or 'self' (the speaker's own, everything).
 */
function confSpeakerShape(array $r, array $sessions, string $view): array
{
    $out = [
        'id' => (int)$r['id'], 'honorific' => $r['honorific'], 'name' => $r['name'], 'position' => $r['position'],
        'institution' => $r['institution'], 'country' => $r['country'], 'bio' => $r['bio'], 'featured' => (bool)$r['featured'],
        'photoUrl' => $r['photo_file'] ? 'api/index.php?route=conference/speakers/' . (int)$r['id'] . '/photo&v=' . substr(md5((string)$r['photo_file']), 0, 8) : null,
        'sessions' => $sessions,
    ];
    if ($view === 'public') return $out;
    $out += ['published' => (bool)$r['published'], 'sortOrder' => (int)$r['sort_order'], 'email' => $r['email'],
             'linked' => $r['user_id'] !== null];
    if ($view === 'admin' || $view === 'self') {
        $out['travel'] = [
            'arrivalAt' => $r['arrival_at'] ? substr((string)$r['arrival_at'], 0, 16) : null,
            'departureAt' => $r['departure_at'] ? substr((string)$r['departure_at'], 0, 16) : null,
            'flight' => $r['flight'], 'accommodation' => $r['accommodation'],
            'accommodationLabel' => CONF_SPEAKER_ACCOMMODATION[$r['accommodation']] ?? '', 'notes' => $r['travel_notes'],
        ];
    }
    return $out;
}

function confSpeakers(PDO $pdo, int $eventId, string $view, bool $programmePublished = true): array
{
    $stmt = $pdo->prepare('SELECT * FROM conf_speakers WHERE event_id = ?' . ($view === 'public' ? ' AND published = 1' : '')
                          . ' ORDER BY featured DESC, sort_order, name');
    $stmt->execute([$eventId]);
    $rows = $stmt->fetchAll();
    // Before the programme is published its sessions are not public, so neither are a
    // speaker's slots in it.
    $sessions = ($view !== 'public' || $programmePublished) ? confSpeakerSessions($pdo, array_column($rows, 'id')) : [];
    return array_map(fn($r) => confSpeakerShape($r, $sessions[(int)$r['id']] ?? [], $view), $rows);
}

/** Profile fields the speaker and the programme team may both edit. */
function confSpeakerProfileValues(array $data): array
{
    $text = static fn(string $k, int $max) => mb_substr(trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', confStr($data[$k] ?? '')) ?? ''), 0, $max) ?: null;
    return [
        'honorific' => $text('honorific', 30), 'name' => $text('name', 120), 'position' => $text('position', 160),
        'institution' => $text('institution', 200), 'country' => $text('country', 60), 'bio' => $text('bio', 3000),
    ];
}

/** @return array{0: ?string, 1: ?string} the parsed value and an error */
function confSpeakerDateTime($v): array
{
    $v = str_replace('T', ' ', trim(confStr($v)));
    if ($v === '') return [null, null];
    foreach (['Y-m-d H:i', 'Y-m-d H:i:s'] as $f) {
        $d = DateTimeImmutable::createFromFormat('!' . $f, $v);
        if ($d && $d->format($f) === $v) return [$d->format('Y-m-d H:i:s'), null];
    }
    return [null, 'Travel dates and times must look like 2027-08-09 14:30.'];
}

/** Travel, from the speaker or a conference administrator. */
function confSpeakerSaveTravel(PDO $pdo, int $speakerId, array $travel): ?string
{
    [$arrival, $e1] = confSpeakerDateTime($travel['arrivalAt'] ?? '');
    [$departure, $e2] = confSpeakerDateTime($travel['departureAt'] ?? '');
    if ($e1 || $e2) return $e1 ?: $e2;
    if ($arrival && $departure && $departure <= $arrival) return 'Departure must be after arrival.';
    $acc = confStr($travel['accommodation'] ?? '');
    if (!array_key_exists($acc, CONF_SPEAKER_ACCOMMODATION)) return 'Choose an accommodation option.';
    $pdo->prepare('UPDATE conf_speakers SET arrival_at = ?, departure_at = ?, flight = ?, accommodation = ?, travel_notes = ? WHERE id = ?')
        ->execute([$arrival, $departure, mb_substr(trim(confStr($travel['flight'] ?? '')), 0, 200) ?: null, $acc,
                   mb_substr(trim(confStr($travel['notes'] ?? '')), 0, 2000) ?: null, $speakerId]);
    return null;
}

/** The programme team's save: profile, email, publishing and sessions. @return array{0: ?int, 1: ?string} */
function confSaveSpeaker(PDO $pdo, int $eventId, array $data, int $by): array
{
    $id = (int)($data['id'] ?? 0);
    $existing = $id ? confSpeakerRow($pdo, $eventId, $id) : null;
    if ($id && !$existing) return [null, 'No such speaker.'];
    // Only the fields sent are changed: publishing a profile must not wipe the bio the
    // speaker wrote themselves.
    if ($existing) {
        $keep = ['honorific' => 'honorific', 'name' => 'name', 'position' => 'position', 'institution' => 'institution',
                 'country' => 'country', 'bio' => 'bio', 'email' => 'email', 'featured' => 'featured', 'published' => 'published',
                 'sortOrder' => 'sort_order'];
        foreach ($keep as $key => $col) if (!array_key_exists($key, $data)) $data[$key] = $existing[$col];
    }
    $v = confSpeakerProfileValues($data);
    if (!$v['name']) return [null, 'Give the speaker a name.'];
    $email = strtolower(trim(confStr($data['email'] ?? '')));
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) return [null, 'The email is not valid.'];
    $sessions = [];
    foreach ((array)($data['sessions'] ?? []) as $s) {
        if (!is_array($s)) continue;
        $sid = (int)($s['sessionId'] ?? 0);
        $role = confStr($s['role'] ?? 'speaker');
        if (!array_key_exists($role, CONF_SPEAKER_ROLES)) return [null, 'Unknown session role.'];
        $chk = $pdo->prepare('SELECT 1 FROM conf_sessions WHERE id = ? AND event_id = ?');
        $chk->execute([$sid, $eventId]);
        if (!$chk->fetch()) return [null, 'One of the sessions is not in this conference.'];
        $sessions[$sid] = $role;
    }
    // Changing the email unlinks the account, so the profile goes to whoever owns the new one.
    $userId = $existing && strtolower((string)$existing['email']) === $email ? $existing['user_id'] : null;
    $values = [$v['honorific'], $v['name'], $v['position'], $v['institution'], $v['country'], $v['bio'], $email ?: null, $userId,
               !empty($data['featured']) ? 1 : 0, !empty($data['published']) ? 1 : 0, (int)($data['sortOrder'] ?? 0)];
    if ($existing) {
        $pdo->prepare('UPDATE conf_speakers SET honorific = ?, name = ?, position = ?, institution = ?, country = ?, bio = ?, email = ?, user_id = ?,
                       featured = ?, published = ?, sort_order = ? WHERE id = ? AND event_id = ?')
            ->execute(array_merge($values, [$id, $eventId]));
    } else {
        $pdo->prepare('INSERT INTO conf_speakers (honorific, name, position, institution, country, bio, email, user_id, featured, published, sort_order, event_id)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute(array_merge($values, [$eventId]));
        $id = (int)$pdo->lastInsertId();
    }
    if (array_key_exists('sessions', $data)) {
        $pdo->prepare('DELETE FROM conf_speaker_sessions WHERE speaker_id = ?')->execute([$id]);
        $ins = $pdo->prepare('INSERT INTO conf_speaker_sessions (speaker_id, session_id, role) VALUES (?, ?, ?)');
        foreach ($sessions as $sid => $role) $ins->execute([$id, $sid, $role]);
    }
    if (function_exists('confAudit')) confAudit($pdo, $eventId, null, $by, $existing ? 'speaker_updated' : 'speaker_added', ['speakerId' => $id]);
    return [$id, null];
}

/** The speaker's own save: profile text only. Publishing, email and sessions stay with staff. */
function confSpeakerSaveOwn(PDO $pdo, array $speaker, array $data): ?string
{
    foreach (['honorific', 'name', 'position', 'institution', 'country', 'bio'] as $k) {
        if (!array_key_exists($k, $data)) $data[$k] = $speaker[$k];
    }
    $v = confSpeakerProfileValues($data);
    if (!$v['name']) return 'Your name cannot be empty.';
    $pdo->prepare('UPDATE conf_speakers SET honorific = ?, name = ?, position = ?, institution = ?, country = ?, bio = ? WHERE id = ?')
        ->execute([$v['honorific'], $v['name'], $v['position'], $v['institution'], $v['country'], $v['bio'], (int)$speaker['id']]);
    if (is_array($data['travel'] ?? null)) return confSpeakerSaveTravel($pdo, (int)$speaker['id'], $data['travel']);
    return null;
}

/** A JPG or PNG up to 2 MB, its metadata stripped, stored outside the web root. */
function confSpeakerPhoto(PDO $pdo, array $config, array $speaker, ?array $file): ?string
{
    if (!$file || (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string)$file['tmp_name'])) return 'The upload did not arrive.';
    if ((int)$file['size'] > CONF_SPEAKER_PHOTO_MAX_BYTES) return 'The photo must be 2 MB or smaller.';
    $info = @getimagesize((string)$file['tmp_name']);
    if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG], true)) return 'Upload the photo as a JPG or PNG.';
    $data = (string)file_get_contents((string)$file['tmp_name']);
    $clean = $info[2] === IMAGETYPE_JPEG
        ? (function_exists('absStripJpeg') ? absStripJpeg($data) : null)
        : (function_exists('absStripPng') ? absStripPng($data) : null);
    if ($clean === null) return 'That image could not be read. Try saving it again as a JPG.';
    $dir = confSpeakerPhotoDir($config);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) return 'Photos cannot be stored on the server yet.';
    $name = 'speaker-' . bin2hex(random_bytes(12)) . ($info[2] === IMAGETYPE_JPEG ? '.jpg' : '.png');
    if (file_put_contents($dir . '/' . $name, $clean) === false) return 'The photo could not be saved.';
    if ($speaker['photo_file']) @unlink($dir . '/' . basename((string)$speaker['photo_file']));
    $pdo->prepare('UPDATE conf_speakers SET photo_file = ?, photo_mime = ? WHERE id = ?')->execute([$name, $info['mime'], (int)$speaker['id']]);
    return null;
}

function confSpeakerDelete(PDO $pdo, array $config, int $eventId, int $speakerId, int $by): ?string
{
    $s = confSpeakerRow($pdo, $eventId, $speakerId);
    if (!$s) return 'No such speaker.';
    if ($s['photo_file']) @unlink(confSpeakerPhotoDir($config) . '/' . basename((string)$s['photo_file']));
    $pdo->prepare('DELETE FROM conf_speaker_sessions WHERE speaker_id = ?')->execute([$speakerId]);
    $pdo->prepare('DELETE FROM conf_speakers WHERE id = ?')->execute([$speakerId]);
    if (function_exists('confAudit')) confAudit($pdo, $eventId, null, $by, 'speaker_deleted', ['speakerId' => $speakerId, 'name' => $s['name']]);
    return null;
}

/** Asks the speaker to sign in and complete their profile. At most once a day per speaker. */
function confSpeakerInvite(PDO $pdo, array $config, array $event, int $speakerId): ?string
{
    $s = confSpeakerRow($pdo, (int)$event['id'], $speakerId);
    if (!$s) return 'No such speaker.';
    if (!$s['email']) return 'Add the speaker\'s email first.';
    if (!function_exists('absSendLogged') || !function_exists('cardEmailHtml')) return 'Email is not available on this server.';
    $portal = rtrim((string)($config['portal_base_url'] ?? ''), '/') ?: 'https://www.resok.org/resok-portal/public';
    $h = fn($x) => htmlspecialchars((string)$x, ENT_QUOTES);
    // "Dear Prof Mwangi," with a title; "Dear Jane Mwangi," without one.
    $parts = preg_split('/\s+/u', trim((string)$s['name'])) ?: [];
    $salutation = function_exists('emailSalutation') ? emailSalutation($s['honorific'], $s['name'], (string)end($parts)) : 'Dear ' . $s['name'] . ',';
    $html = cardEmailHtml('Your speaker profile', $salutation,
        cardEmailParagraph('Thank you for agreeing to speak at <strong>' . $h($event['name']) . '</strong>. Please sign in to the ReSoK portal with this email address (<strong>' . $h($s['email']) . '</strong>) to add your photo, a short bio and your travel details.')
        . cardEmailParagraph('If you do not have a portal account yet, create one with this same email address and your profile will be waiting for you.', true),
        'Complete my profile', $portal . '/conference#speaker', 'Your travel details are seen only by the conference organisers.');
    $text = "{$salutation}\n\nThank you for agreeing to speak at {$event['name']}. Please sign in to the ReSoK portal with {$s['email']} to add your photo, bio and travel details: {$portal}/conference#speaker\n\nReSoK ICT";
    absSendLogged($pdo, $config, (int)$event['id'], null, 'conf_speaker_invite', (string)$s['email'], 'Your speaker profile: ' . $event['name'],
                  $text, $html, 'conf-speaker-' . $speakerId . '-' . date('Ymd'));
    return null;
}
