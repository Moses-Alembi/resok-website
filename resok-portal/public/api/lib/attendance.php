<?php
declare(strict_types=1);

/**
 * Attendance, and the distribution of KMPDC CPD tokens.
 *
 * The rule the whole file exists to enforce: a token is released only to someone recorded as
 * having attended, and only to someone who has proved they hold the email address that
 * attendance was recorded against.
 *
 * That second half matters more than it looks. At a conference, everybody's email address is
 * on the delegate list, the sign-in sheet and the Zoom participant panel - so "type the email
 * you registered with" is not a check, it is a lookup on public information. Anyone could
 * collect a colleague's token before they did, and the real attendee would find it already
 * spent on the KMPDC portal with no way to get it back.
 *
 * So collection requires a six-digit code sent to that address. It restores the one thing the
 * old practice of emailing tokens had going for it - you must be able to open the mailbox -
 * while removing the thing that was wrong with it, which is that the token itself sat in an
 * inbox forever, forwardable by anyone.
 *
 * Members never see a code. They are already signed in, which is a stronger proof than any
 * email round-trip, so their tokens appear in the dashboard.
 */

const TOKEN_CODE_TTL_MINUTES = 15;
const TOKEN_CODE_MAX_ATTEMPTS = 5;

function attendanceEnsureTables(PDO $pdo): bool
{
    static $ready = null;
    if ($ready !== null) return $ready;

    try {
        $pdo->query('SELECT 1 FROM event_attendees LIMIT 1');
        $pdo->query('SELECT 1 FROM cpd_tokens LIMIT 1');
        $pdo->query('SELECT 1 FROM token_access_codes LIMIT 1');
        return $ready = true;
    } catch (Throwable $e) {
        // Absent - build them.
    }
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS event_attendees (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            event_id INT UNSIGNED NOT NULL,
            member_profile_id INT UNSIGNED NULL,
            full_name VARCHAR(160) NOT NULL,
            email VARCHAR(190) NOT NULL,
            kmpdc_number VARCHAR(60) NULL,
            phone VARCHAR(30) NULL,
            channel ENUM('in_person','online','unknown') NOT NULL DEFAULT 'unknown',
            minutes_attended SMALLINT UNSIGNED NULL,
            attended TINYINT(1) NOT NULL DEFAULT 0,
            attendance_method ENUM('register','zoom_report','venue_code','manual') NULL,
            confirmed_at DATETIME NULL,
            confirmed_by INT UNSIGNED NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY event_attendees_unique (event_id, email),
            KEY event_attendees_event (event_id, attended),
            KEY event_attendees_member (member_profile_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS cpd_tokens (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            event_id INT UNSIGNED NOT NULL,
            token_value VARCHAR(255) NOT NULL,
            token_hint VARCHAR(8) NULL,
            status ENUM('unissued','assigned','collected','void') NOT NULL DEFAULT 'unissued',
            attendee_id INT UNSIGNED NULL,
            assigned_at DATETIME NULL,
            collected_at DATETIME NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY cpd_tokens_attendee (attendee_id),
            KEY cpd_tokens_event (event_id, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS token_access_codes (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            attendee_id INT UNSIGNED NOT NULL,
            code_hash VARCHAR(255) NOT NULL,
            expires_at DATETIME NOT NULL,
            attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
            consumed_at DATETIME NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY token_access_attendee (attendee_id, expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        return $ready = true;
    } catch (Throwable $e) {
        error_log('attendance tables unavailable: ' . $e->getMessage());
        return $ready = false;
    }
}

/* ---------------------------------------------------------------------------------------
 * Attendance
 * ------------------------------------------------------------------------------------ */

/**
 * Parses a pasted attendance list.
 *
 * Deliberately forgiving about shape, because the input is whatever the admin has to hand -
 * a Zoom participant export, a typed register, a column copied out of a spreadsheet. The
 * email is found by looking for the field containing "@" rather than by trusting a column
 * position, so the same parser handles all of them.
 *
 * @return array{rows:array<int,array>,skipped:array<int,string>}
 */
function attendanceParseList(string $raw): array
{
    $rows = [];
    $skipped = [];
    $seen = [];

    foreach (preg_split('/\r\n|\r|\n/', $raw) ?: [] as $line) {
        $line = trim($line);
        if ($line === '') continue;

        $parts = array_map('trim', preg_split('/\t|,|;/', $line) ?: []);
        $email = '';
        foreach ($parts as $part) {
            if (strpos($part, '@') !== false && filter_var($part, FILTER_VALIDATE_EMAIL)) {
                $email = strtolower($part);
                break;
            }
        }
        // A Zoom export starts with a header row and often a summary block. Both fall out
        // here without needing to be recognised, because neither contains an address.
        if ($email === '') { $skipped[] = $line; continue; }
        if (isset($seen[$email])) continue;
        $seen[$email] = true;

        $name = '';
        $kmpdc = null;
        $minutes = null;
        foreach ($parts as $part) {
            if ($part === '' || strtolower($part) === $email) continue;
            // Zoom's duration column is a bare number of minutes.
            if ($minutes === null && preg_match('/^\d{1,4}$/', $part)) { $minutes = (int)$part; continue; }
            // A KMPDC number carries digits and is not a plain name.
            if ($kmpdc === null && $name !== '' && preg_match('/\d/', $part)) { $kmpdc = $part; continue; }
            if ($name === '') $name = $part;
        }
        if ($name === '') $name = explode('@', $email)[0];

        $rows[] = [
            'email' => $email,
            'name' => mb_substr($name, 0, 160),
            'kmpdc' => $kmpdc === null ? null : mb_substr($kmpdc, 0, 60),
            'minutes' => $minutes,
        ];
    }
    return ['rows' => $rows, 'skipped' => $skipped];
}

/**
 * Adds or updates attendees for an event.
 *
 * Idempotent on (event, email), so the same list can be pasted twice - or a venue register
 * added on top of a Zoom export for a hybrid session - without producing a second attendee
 * who could collect a second token.
 *
 * @return array{added:int,updated:int,linked:int}
 */
function attendanceRecord(PDO $pdo, int $eventId, array $rows, string $channel, string $method, ?int $adminUserId, bool $markAttended = true): array
{
    $added = $updated = $linked = 0;

    $find = $pdo->prepare('SELECT id FROM event_attendees WHERE event_id = ? AND email = ? LIMIT 1');
    $insert = $pdo->prepare('INSERT INTO event_attendees
        (event_id, member_profile_id, full_name, email, kmpdc_number, channel, minutes_attended,
         attended, attendance_method, confirmed_at, confirmed_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    // COALESCE keeps a KMPDC number that is already on file when the new list does not carry
    // one - re-importing a Zoom export should never erase what the register supplied.
    $update = $pdo->prepare('UPDATE event_attendees
        SET full_name = ?, kmpdc_number = COALESCE(?, kmpdc_number),
            channel = ?, minutes_attended = COALESCE(?, minutes_attended),
            attended = GREATEST(attended, ?), attendance_method = ?,
            confirmed_at = ?, confirmed_by = ?
        WHERE id = ?');
    // Links the attendee to a member account where the address matches one, so the token
    // shows up in that member's dashboard instead of needing an emailed code.
    $member = $pdo->prepare('SELECT mp.id FROM member_profiles mp
                             JOIN users u ON u.id = mp.user_id
                             WHERE LOWER(u.email) = ? LIMIT 1');

    $now = date('Y-m-d H:i:s');
    foreach ($rows as $row) {
        $member->execute([$row['email']]);
        $memberProfileId = ($found = $member->fetch()) ? (int)$found['id'] : null;
        if ($memberProfileId) $linked++;

        $find->execute([$eventId, $row['email']]);
        $existing = $find->fetch();

        if ($existing) {
            $update->execute([
                $row['name'], $row['kmpdc'], $channel, $row['minutes'],
                ($markAttended ? 1 : 0), $method, ($markAttended ? $now : null), $adminUserId,
                (int)$existing['id'],
            ]);
            $updated++;
        } else {
            $insert->execute([
                $eventId, $memberProfileId, $row['name'], $row['email'], $row['kmpdc'],
                $channel, $row['minutes'], ($markAttended ? 1 : 0), $method,
                ($markAttended ? $now : null), $adminUserId,
            ]);
            $added++;
        }
    }
    return ['added' => $added, 'updated' => $updated, 'linked' => $linked];
}

function attendanceList(PDO $pdo, int $eventId): array
{
    if (!attendanceEnsureTables($pdo)) return [];
    $stmt = $pdo->prepare('SELECT a.*, t.status AS token_status, t.token_hint, t.collected_at
                           FROM event_attendees a
                           LEFT JOIN cpd_tokens t ON t.attendee_id = a.id
                           WHERE a.event_id = ?
                           ORDER BY a.attended DESC, a.full_name ASC');
    $stmt->execute([$eventId]);

    return array_map(fn($row) => [
        'id'           => (int)$row['id'],
        'name'         => $row['full_name'],
        'email'        => $row['email'],
        'kmpdcNumber'  => $row['kmpdc_number'],
        'isMember'     => $row['member_profile_id'] !== null,
        'channel'      => $row['channel'],
        'minutes'      => $row['minutes_attended'] === null ? null : (int)$row['minutes_attended'],
        'attended'     => (bool)(int)$row['attended'],
        'tokenStatus'  => $row['token_status'],
        'tokenHint'    => $row['token_hint'],
        'collectedAt'  => $row['collected_at'],
    ], $stmt->fetchAll());
}

/* ---------------------------------------------------------------------------------------
 * Tokens
 * ------------------------------------------------------------------------------------ */

/**
 * Loads a batch of tokens generated on the KMPDC portal.
 *
 * Duplicates within the batch and against what is already stored are dropped rather than
 * inserted, because the same paste happening twice must not double the pool - two attendees
 * handed the same token means the second one finds it already redeemed.
 *
 * @return array{added:int,duplicates:int}
 */
function tokensLoad(PDO $pdo, array $config, int $eventId, string $raw): array
{
    $candidates = [];
    foreach (preg_split('/\r\n|\r|\n|,|\s{2,}|\t/', $raw) ?: [] as $piece) {
        $piece = trim($piece);
        if ($piece !== '' && mb_strlen($piece) <= 190) $candidates[$piece] = true;
    }

    // Every stored token is decrypted once to compare, which is the only way to spot a
    // duplicate when the stored form is encrypted - the same plaintext encrypts to different
    // ciphertext every time, by design.
    $existing = [];
    $stmt = $pdo->prepare('SELECT token_value FROM cpd_tokens WHERE event_id = ?');
    $stmt->execute([$eventId]);
    foreach ($stmt->fetchAll() as $row) {
        $plain = cryptoDecrypt($config, $row['token_value']);
        if ($plain !== null) $existing[$plain] = true;
    }

    $insert = $pdo->prepare('INSERT INTO cpd_tokens (event_id, token_value, token_hint) VALUES (?, ?, ?)');
    $added = $duplicates = 0;
    foreach (array_keys($candidates) as $token) {
        if (isset($existing[$token])) { $duplicates++; continue; }
        $insert->execute([$eventId, cryptoEncrypt($config, $token), mb_substr($token, -4)]);
        $existing[$token] = true;
        $added++;
    }
    return ['added' => $added, 'duplicates' => $duplicates];
}

/**
 * Hands an unissued token to every attendee who is marked as present and does not have one.
 *
 * Runs inside a transaction so a batch that runs short does not leave half the attendees
 * assigned and the admin unsure where it stopped.
 *
 * @return array{assigned:int,waiting:int,available:int,missingKmpdc:int}
 */
function tokensAssign(PDO $pdo, int $eventId): array
{
    $waitingStmt = $pdo->prepare('SELECT a.id, a.kmpdc_number FROM event_attendees a
                                  LEFT JOIN cpd_tokens t ON t.attendee_id = a.id
                                  WHERE a.event_id = ? AND a.attended = 1 AND t.id IS NULL
                                  ORDER BY a.id ASC');
    $waitingStmt->execute([$eventId]);
    $waiting = $waitingStmt->fetchAll();

    $freeStmt = $pdo->prepare("SELECT id FROM cpd_tokens
                                WHERE event_id = ? AND status = 'unissued' AND attendee_id IS NULL
                                ORDER BY id ASC");
    $freeStmt->execute([$eventId]);
    $free = $freeStmt->fetchAll();

    $assign = $pdo->prepare("UPDATE cpd_tokens
                             SET attendee_id = ?, status = 'assigned', assigned_at = NOW()
                             WHERE id = ? AND attendee_id IS NULL");

    $assigned = 0;
    $missingKmpdc = 0;
    $pdo->beginTransaction();
    try {
        foreach ($waiting as $index => $attendee) {
            if (!isset($free[$index])) break;   // ran out of tokens
            if (empty($attendee['kmpdc_number'])) $missingKmpdc++;
            $assign->execute([(int)$attendee['id'], (int)$free[$index]['id']]);
            $assigned++;
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    return [
        'assigned'     => $assigned,
        'waiting'      => max(0, count($waiting) - $assigned),
        'available'    => max(0, count($free) - $assigned),
        'missingKmpdc' => $missingKmpdc,
    ];
}

/** Counts for the admin reconciliation view. */
function tokensSummary(PDO $pdo, int $eventId): array
{
    if (!attendanceEnsureTables($pdo)) {
        return ['attendees' => 0, 'attended' => 0, 'tokens' => 0, 'unissued' => 0,
                'assigned' => 0, 'collected' => 0, 'waiting' => 0, 'missingKmpdc' => 0];
    }
    $one = function (string $sql) use ($pdo, $eventId): int {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$eventId]);
        return (int)($stmt->fetch()['c'] ?? 0);
    };
    return [
        'attendees'    => $one('SELECT COUNT(*) c FROM event_attendees WHERE event_id = ?'),
        'attended'     => $one('SELECT COUNT(*) c FROM event_attendees WHERE event_id = ? AND attended = 1'),
        'tokens'       => $one('SELECT COUNT(*) c FROM cpd_tokens WHERE event_id = ?'),
        'unissued'     => $one("SELECT COUNT(*) c FROM cpd_tokens WHERE event_id = ? AND status = 'unissued'"),
        'assigned'     => $one("SELECT COUNT(*) c FROM cpd_tokens WHERE event_id = ? AND status = 'assigned'"),
        'collected'    => $one("SELECT COUNT(*) c FROM cpd_tokens WHERE event_id = ? AND status = 'collected'"),
        'waiting'      => $one('SELECT COUNT(*) c FROM event_attendees a LEFT JOIN cpd_tokens t ON t.attendee_id = a.id
                                WHERE a.event_id = ? AND a.attended = 1 AND t.id IS NULL'),
        'missingKmpdc' => $one("SELECT COUNT(*) c FROM event_attendees
                                WHERE event_id = ? AND attended = 1 AND (kmpdc_number IS NULL OR kmpdc_number = '')"),
    ];
}

/* ---------------------------------------------------------------------------------------
 * Collection
 * ------------------------------------------------------------------------------------ */

/** The attendee row for one address at one event, or null. */
function attendeeFor(PDO $pdo, int $eventId, string $email): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM event_attendees WHERE event_id = ? AND email = ? LIMIT 1');
    $stmt->execute([$eventId, strtolower(trim($email))]);
    return $stmt->fetch() ?: null;
}

/**
 * Issues a six-digit code for an attendee and returns it, for the caller to email.
 *
 * Any earlier unused code for the same attendee is consumed first, so requesting a second
 * one invalidates the first rather than leaving two valid codes in circulation.
 */
function tokenCodeIssue(PDO $pdo, int $attendeeId): string
{
    $pdo->prepare('UPDATE token_access_codes SET consumed_at = NOW()
                   WHERE attendee_id = ? AND consumed_at IS NULL')->execute([$attendeeId]);

    // random_int, not rand: this is the only thing standing between a stranger and someone
    // else's CPD credit.
    $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);

    $pdo->prepare('INSERT INTO token_access_codes (attendee_id, code_hash, expires_at) VALUES (?, ?, ?)')
        ->execute([
            $attendeeId,
            password_hash($code, PASSWORD_DEFAULT),
            date('Y-m-d H:i:s', time() + TOKEN_CODE_TTL_MINUTES * 60),
        ]);
    return $code;
}

/**
 * Checks a submitted code.
 *
 * @return array{ok:bool,error:?string}
 */
function tokenCodeVerify(PDO $pdo, int $attendeeId, string $submitted): array
{
    $stmt = $pdo->prepare('SELECT * FROM token_access_codes
                           WHERE attendee_id = ? AND consumed_at IS NULL
                           ORDER BY id DESC LIMIT 1');
    $stmt->execute([$attendeeId]);
    $row = $stmt->fetch();

    if (!$row) return ['ok' => false, 'error' => 'Request a new code - that one has already been used.'];
    if (strtotime((string)$row['expires_at']) < time()) {
        return ['ok' => false, 'error' => 'That code has expired. Request a new one.'];
    }
    if ((int)$row['attempts'] >= TOKEN_CODE_MAX_ATTEMPTS) {
        // Burned rather than merely refused, so guessing cannot continue against this code.
        $pdo->prepare('UPDATE token_access_codes SET consumed_at = NOW() WHERE id = ?')->execute([(int)$row['id']]);
        return ['ok' => false, 'error' => 'Too many incorrect attempts. Request a new code.'];
    }

    $pdo->prepare('UPDATE token_access_codes SET attempts = attempts + 1 WHERE id = ?')->execute([(int)$row['id']]);

    if (!password_verify(trim($submitted), (string)$row['code_hash'])) {
        return ['ok' => false, 'error' => 'That code is not right.'];
    }
    $pdo->prepare('UPDATE token_access_codes SET consumed_at = NOW() WHERE id = ?')->execute([(int)$row['id']]);
    return ['ok' => true, 'error' => null];
}

/**
 * Returns the plaintext token for an attendee and marks it collected.
 *
 * Callers must have established who the attendee is first - a verified code, or a signed-in
 * member whose profile matches. This function does not decide that.
 */
function tokenRelease(PDO $pdo, array $config, int $attendeeId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM cpd_tokens WHERE attendee_id = ? LIMIT 1');
    $stmt->execute([$attendeeId]);
    $row = $stmt->fetch();
    if (!$row) return null;

    if ($row['status'] === 'assigned') {
        $pdo->prepare("UPDATE cpd_tokens SET status = 'collected', collected_at = NOW() WHERE id = ?")
            ->execute([(int)$row['id']]);
    }
    $plain = cryptoDecrypt($config, (string)$row['token_value']);
    if ($plain === null) {
        // Only reachable if the encryption key changed after the token was stored. Better to
        // say the token cannot be read than to hand over ciphertext that will fail at KMPDC.
        error_log('tokenRelease: token for attendee ' . $attendeeId . ' could not be decrypted.');
        return null;
    }
    return [
        'token'       => $plain,
        'collectedAt' => $row['collected_at'] ?? date('Y-m-d H:i:s'),
        'firstView'   => $row['status'] === 'assigned',
    ];
}

/** Every token belonging to a member, for the dashboard. Members need no code. */
function tokensForMember(PDO $pdo, array $config, int $memberProfileId): array
{
    if (!attendanceEnsureTables($pdo)) return [];

    $stmt = $pdo->prepare("SELECT t.*, a.id AS attendee_id, e.title, e.slug, e.starts_at,
                                  e.approved_points, e.approval_ref, e.regulator
                           FROM event_attendees a
                           JOIN cpd_tokens t ON t.attendee_id = a.id
                           JOIN cpd_events e ON e.id = a.event_id
                           WHERE a.member_profile_id = ? AND t.status IN ('assigned','collected')
                           ORDER BY e.starts_at DESC");
    $stmt->execute([$memberProfileId]);

    $out = [];
    foreach ($stmt->fetchAll() as $row) {
        $plain = cryptoDecrypt($config, (string)$row['token_value']);
        $out[] = [
            'event'       => $row['title'],
            'slug'        => $row['slug'],
            'date'        => $row['starts_at'],
            'points'      => $row['approved_points'] === null ? null : (float)$row['approved_points'],
            'regulator'   => $row['regulator'],
            'approvalRef' => $row['approval_ref'],
            // Null where the key has changed since it was stored; the dashboard says to
            // contact the office rather than showing something that will not work.
            'token'       => $plain,
            'collected'   => $row['status'] === 'collected',
        ];
    }
    return $out;
}
