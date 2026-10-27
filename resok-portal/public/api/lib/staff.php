<?php
declare(strict_types=1);

/**
 * Staff accounts: Society staff who use the portal for work, not as members.
 *
 * A staff account is an ordinary 'member' role account with a staff_accounts row beside it.
 * That is deliberate. It gives them exactly what every signed-in account already has - raising
 * ICT tickets and following their own - plus a staff home page with the assets in their care,
 * and nothing else: no member records, no ICT management, no admin panel. Anyone who needs
 * more is raised individually on the Administrators panel or under ICT Staff & access, where
 * the screen says what is being granted.
 *
 * Accounts are added in bulk by pasting a list as it comes out of an email client
 * ("Anne Mwangi <amwangi@resok.org>, ..."). Nobody is emailed at that point. "Send invites"
 * then mails each person a link to choose their own password - the same unique,
 * expiring link the member claim emails use, which also proves the address is theirs. A
 * password is never set or seen by the administrator.
 *
 * An address that already has a portal account (a member, say) is only marked as staff: its
 * password, role and membership are left exactly as they are.
 */

const STAFF_INVITE_TTL_DAYS = 14;

function staffEnsureTables(PDO $pdo): bool
{
    static $ready = null;
    if ($ready !== null) return $ready;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS staff_accounts (
            user_id INT UNSIGNED NOT NULL,
            full_name VARCHAR(160) NULL,
            added_by INT UNSIGNED NULL,
            added_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            invite_sent_at DATETIME NULL,
            invite_count INT UNSIGNED NOT NULL DEFAULT 0,
            invite_error VARCHAR(255) NULL,
            PRIMARY KEY (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        return $ready = true;
    } catch (Throwable $e) {
        error_log('Staff table unavailable: ' . $e->getMessage());
        return $ready = false;
    }
}

function staffIs(PDO $pdo, int $userId): bool
{
    if (!staffEnsureTables($pdo)) return false;
    $stmt = $pdo->prepare('SELECT * FROM staff_accounts WHERE user_id = ?');
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    // A deactivated member of staff (lib/staff-portal.php) is no longer staff.
    return $row !== false && (!isset($row['active']) || (int)$row['active'] === 1);
}

/**
 * Reads "Name <email>, Name <email>, email, ..." as pasted from an email client's To line,
 * or one person per line. A name that is only the mailbox part of the address
 * ("rhodapola <rhodapola@gmail.com>") is no name at all, so it is dropped rather than
 * used to greet someone as "Dear Rhodapola".
 *
 * @return array{people: array<int, array{email:string, name:?string}>, invalid: string[], duplicates: string[]}
 */
function staffParseList(string $text): array
{
    $people = [];
    $invalid = [];
    $duplicates = [];
    // Split on the commas, semicolons and line breaks between entries, but not inside <...>.
    $entries = preg_split('/[,;\n\r]+(?![^<]*>)/', $text) ?: [];
    foreach ($entries as $entry) {
        $entry = trim($entry);
        if ($entry === '' || preg_match('/^\.+$/', $entry)) continue;   // the "......" at the end of a copied list
        if (preg_match('/^(.*?)<\s*([^<>\s]+)\s*>\s*$/', $entry, $m)) {
            $name = trim($m[1], " \t\"'");
            $email = $m[2];
        } else {
            $name = '';
            $email = trim($entry, " \t\"'");
        }
        $email = strtolower($email);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190) {
            $invalid[] = mb_substr($entry, 0, 120);
            continue;
        }
        // "James Juma <jamesjuma@...>" is a name, even though it matches the mailbox; a single
        // word that is only the mailbox again ("rhodapola", "stf.bare") is not.
        $bare = static fn(string $s) => (string)preg_replace('/[^a-z0-9]/', '', strtolower($s));
        $local = (string)strstr($email, '@', true);
        $oneWord = !preg_match('/\s/u', $name);
        if ($name === '' || filter_var($name, FILTER_VALIDATE_EMAIL) || ($oneWord && $bare($name) === $bare($local))) {
            $name = null;
        } else {
            $name = mb_substr((string)preg_replace('/\s+/u', ' ', $name), 0, 160);
        }
        if (isset($people[$email])) { $duplicates[] = $email; continue; }
        $people[$email] = ['email' => $email, 'name' => $name];
    }
    return ['people' => array_values($people), 'invalid' => $invalid, 'duplicates' => array_values(array_unique($duplicates))];
}

/**
 * Creates the accounts and marks them as staff. Nobody is emailed.
 *
 * @return array{added: string[], marked: string[], already: string[], invalid: string[], duplicates: string[]}
 */
function staffAdd(PDO $pdo, string $text, int $adminId): array
{
    $parsed = staffParseList($text);
    $result = ['added' => [], 'marked' => [], 'already' => [], 'invalid' => $parsed['invalid'], 'duplicates' => $parsed['duplicates']];
    $find = $pdo->prepare('SELECT id FROM users WHERE LOWER(email) = ? LIMIT 1');
    $mark = $pdo->prepare('INSERT IGNORE INTO staff_accounts (user_id, full_name, added_by) VALUES (?, ?, ?)');
    foreach ($parsed['people'] as $p) {
        $find->execute([$p['email']]);
        $userId = (int)$find->fetchColumn();
        $new = false;
        if (!$userId) {
            // Unverified, with a password nobody knows: the account cannot be signed into
            // until its owner chooses a password from the invite link.
            $pdo->prepare("INSERT INTO users (email, password_hash, email_verified, role) VALUES (?, ?, 0, 'member')")
                ->execute([$p['email'], password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT)]);
            $userId = (int)$pdo->lastInsertId();
            $new = true;
        }
        $mark->execute([$userId, $p['name'], $adminId]);
        if ($mark->rowCount() === 0) {
            $result['already'][] = $p['email'];
            // A name supplied now fills one that was missing before.
            if ($p['name'] !== null) {
                $pdo->prepare('UPDATE staff_accounts SET full_name = ? WHERE user_id = ? AND full_name IS NULL')->execute([$p['name'], $userId]);
            }
        } else {
            $result[$new ? 'added' : 'marked'][] = $p['email'];
        }
    }
    return $result;
}

/** Everyone on the staff list, with where their invite and account have got to. */
function staffList(PDO $pdo): array
{
    if (!staffEnsureTables($pdo)) return [];
    $rows = $pdo->query('SELECT s.*, u.email, u.role, u.email_verified, mp.membership_status
                         FROM staff_accounts s
                         JOIN users u ON u.id = s.user_id
                         LEFT JOIN member_profiles mp ON mp.user_id = u.id
                         ORDER BY COALESCE(s.full_name, u.email)')->fetchAll();
    return array_map(static function ($r) {
        // "Active" means they have chosen a password (or had one already): a completed
        // invite verifies the address, and an existing account was verified long ago.
        $state = (int)$r['email_verified'] === 1 ? 'active' : ($r['invite_sent_at'] ? 'invited' : 'not_invited');
        return [
            'userId' => (int)$r['user_id'], 'name' => $r['full_name'], 'email' => $r['email'],
            'role' => $r['role'], 'member' => $r['membership_status'] !== null,
            'state' => $state, 'addedAt' => $r['added_at'], 'invitedAt' => $r['invite_sent_at'],
            'inviteCount' => (int)$r['invite_count'], 'inviteError' => $r['invite_error'],
        ];
    }, $rows);
}

/** The greeting: first name for colleagues, "Dear colleague," when no name was given. */
function staffSalutation(?string $name): string
{
    $first = $name ? explode(' ', trim($name))[0] : '';
    return $first !== '' ? emailSalutation(null, $first, null) : 'Dear colleague,';
}

/** Sends one invite: a fresh set-your-password link. */
function staffSendInvite(PDO $pdo, array $config, int $userId, ?string &$error = null): bool
{
    $error = null;
    $stmt = $pdo->prepare('SELECT s.full_name, u.email, u.email_verified FROM staff_accounts s JOIN users u ON u.id = s.user_id WHERE s.user_id = ?');
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    if (!$row) { $error = 'Not on the staff list.'; return false; }
    if ((int)$row['email_verified'] === 1) { $error = 'Already active: they can sign in, or use "Forgot password".'; return false; }

    $token = bin2hex(random_bytes(32));
    $pdo->prepare('UPDATE users SET reset_token = ?, reset_expires = DATE_ADD(NOW(), INTERVAL ? DAY) WHERE id = ?')
        ->execute([$token, STAFF_INVITE_TTL_DAYS, $userId]);
    $portal = rtrim((string)($config['portal_base_url'] ?? ''), '/') ?: 'https://www.resok.org/resok-portal/public';
    $link = $portal . '/forgot-password?claim=1&token=' . $token;
    $email = (string)$row['email'];
    $salutation = staffSalutation($row['full_name']);

    $intro = 'A ReSoK staff account has been set up for you on the Society portal. Use it to report ICT problems '
        . 'and follow them up, and to see the equipment assigned to you.';
    $text = "{$salutation}\n\n{$intro}\n\nChoose your password here:\n{$link}\n\n"
        . 'Sign in with this email address (' . $email . '). The link is unique to you and expires in '
        . STAFF_INVITE_TTL_DAYS . " days. If it expires, use \"Forgot password\" on the login page.\n\n"
        . "If you were not expecting this email, you can ignore it.\n\nRespiratory Society of Kenya";
    $sent = false;
    try {
        $html = cardEmailHtml(
            'Your staff account is ready',
            $salutation,
            cardEmailParagraph(htmlspecialchars($intro, ENT_QUOTES))
            . cardEmailParagraph('Choose your password to start. You will sign in with this email address.', true),
            'Choose my password',
            $link,
            'This link is unique to you and expires in ' . STAFF_INVITE_TTL_DAYS . ' days. If it expires, use '
            . '&ldquo;Forgot password&rdquo; on the login page with this email address.<br>'
            . 'If you were not expecting this email, you can ignore it.'
        );
        // The archive gets a copy with the token blanked out: the link sets the password.
        $sent = (new SimpleMailer($config))->withRedactedArchiveCopy($token)->send($email, 'Your ReSoK staff account is ready', $text, [], $html);
        if (!$sent) $error = 'The mail server did not accept the message.';
    } catch (Throwable $e) {
        $error = mb_substr($e->getMessage(), 0, 255);
        error_log('Staff invite failed for ' . $email . ': ' . $e->getMessage());
    }
    $pdo->prepare('UPDATE staff_accounts SET invite_sent_at = IF(?, NOW(), invite_sent_at), invite_count = invite_count + IF(?, 1, 0), invite_error = ? WHERE user_id = ?')
        ->execute([$sent ? 1 : 0, $sent ? 1 : 0, $sent ? null : $error, $userId]);
    return $sent;
}

/**
 * Invites the next few who have not had one. A small batch per request, as with the
 * member claim emails: each message is its own conversation with the mail server, and the
 * host's hourly limit is shared with everything else the portal sends.
 *
 * @return array{sent: string[], failed: array<int, array{email:string, error:?string}>, remaining: int}
 */
function staffSendBatch(PDO $pdo, array $config, int $limit): array
{
    $rows = $pdo->query('SELECT s.user_id, u.email FROM staff_accounts s JOIN users u ON u.id = s.user_id
                         WHERE s.invite_sent_at IS NULL AND u.email_verified = 0
                         ORDER BY s.added_at, s.user_id LIMIT ' . max(1, min(25, $limit)))->fetchAll();
    $result = ['sent' => [], 'failed' => []];
    foreach ($rows as $r) {
        $error = null;
        if (staffSendInvite($pdo, $config, (int)$r['user_id'], $error)) $result['sent'][] = $r['email'];
        else $result['failed'][] = ['email' => $r['email'], 'error' => $error];
    }
    $result['remaining'] = (int)$pdo->query('SELECT COUNT(*) FROM staff_accounts s JOIN users u ON u.id = s.user_id
                                              WHERE s.invite_sent_at IS NULL AND u.email_verified = 0')->fetchColumn();
    return $result;
}

// ---------------------------------------------------------------------------------------
// Asset holders. The register was imported with holders as typed names ("CHRISPHNE OKOTH",
// "DENNIS GARURE"), so nothing tied an item to an account and "My equipment" was empty.
// These suggest which account each name belongs to; nothing is linked until a super admin
// confirms it, because a wrong guess would show one person's equipment to another.
// ---------------------------------------------------------------------------------------

const STAFF_NICKNAMES = ['BETTY' => 'BEATRICE', 'BETTIE' => 'BEATRICE', 'ANN' => 'ANNE', 'HILDA' => 'HILDAH', 'JACKY' => 'JACKLINE', 'JEFF' => 'GEOFFREY'];

function staffNameTokens(string $name): array
{
    $clean = preg_replace('/[^A-Z ]+/', ' ', strtoupper($name));
    return array_values(array_filter(explode(' ', (string)$clean), static fn($t) => strlen($t) >= 2));
}

/** How alike two name words are: 1 exact, less for a typo or a nickname, 0 otherwise. */
function staffWordLike(string $a, string $b): float
{
    if ($a === $b) return 1.0;
    if ((STAFF_NICKNAMES[$a] ?? null) === $b || (STAFF_NICKNAMES[$b] ?? null) === $a) return 0.9;
    $len = min(strlen($a), strlen($b));
    $d = levenshtein($a, $b);
    if ($len >= 4 && $d === 1) return 0.9;
    if ($len >= 6 && $d === 2) return 0.75;
    return 0.0;
}

/** A holder name against one person: the share of its words found in their name (or, nameless, their email). */
function staffNameScore(array $holder, string $name, string $email): float
{
    if (!$holder) return 0.0;
    $words = staffNameTokens($name);
    $local = strtoupper((string)strstr($email, '@', true));
    $sum = 0.0;
    foreach ($holder as $h) {
        $best = 0.0;
        foreach ($words as $w) $best = max($best, staffWordLike($h, $w));
        if ($best < 0.75 && strlen($h) >= 4 && str_contains($local, $h)) $best = 0.8;
        $sum += $best;
    }
    return $sum / count($holder);
}

/** Every name the register holds items under, with a suggested account for each. */
function staffHolders(PDO $pdo, int $me): array
{
    $people = $pdo->query("SELECT s.user_id, COALESCE(s.full_name, '') AS name, u.email FROM staff_accounts s JOIN users u ON u.id = s.user_id
                           WHERE s.active = 1 ORDER BY s.full_name IS NULL, s.full_name")->fetchAll();
    // The super admin may hold equipment without being on the staff list (the ICT officer does).
    $ids = array_map('intval', array_column($people, 'user_id'));
    if (!in_array($me, $ids, true)) {
        $self = $pdo->prepare("SELECT u.id AS user_id, COALESCE(TRIM(CONCAT_WS(' ', NULLIF(m.first_name, ''), NULLIF(m.surname, ''))), '') AS name, u.email
                               FROM users u LEFT JOIN member_profiles m ON m.user_id = u.id WHERE u.id = ? LIMIT 1");
        $self->execute([$me]);
        if ($row = $self->fetch()) $people[] = $row + ['self' => true];
    }
    $rows = $pdo->query("SELECT h.holder_name, COUNT(*) AS n, MAX(h.holder_user_id) AS uid, COUNT(DISTINCT h.holder_user_id) AS uids, SUM(h.holder_user_id IS NULL) AS unlinked,
                                GROUP_CONCAT(a.asset_tag ORDER BY a.asset_tag SEPARATOR ', ') AS tags
                         FROM ict_assignments h JOIN ict_assets a ON a.id = h.asset_id
                         WHERE h.returned_at IS NULL AND COALESCE(h.holder_name, '') <> ''
                         GROUP BY h.holder_name ORDER BY h.holder_name")->fetchAll();
    $out = [];
    foreach ($rows as $r) {
        $tokens = staffNameTokens($r['holder_name']);
        $scores = [];
        foreach ($people as $p) {
            $s = staffNameScore($tokens, (string)$p['name'], (string)$p['email']);
            if ($s > 0) $scores[(int)$p['user_id']] = $s;
        }
        arsort($scores);
        $best = array_key_first($scores);
        $top = $best === null ? 0 : $scores[$best];
        $second = count($scores) > 1 ? array_values($scores)[1] : 0;
        // "Sure" means every word matched, at least two words, and nobody else came close.
        $confidence = $top >= 0.85 && count($tokens) >= 2 && $top - $second >= 0.3 ? 'sure' : ($top >= 0.5 ? 'maybe' : 'none');
        $shared = str_contains($r['holder_name'], '/') || str_contains($r['holder_name'], '&');
        $out[] = [
            'holderName' => $r['holder_name'], 'items' => (int)$r['n'], 'tags' => mb_substr((string)$r['tags'], 0, 300),
            'linkedUserId' => (int)$r['unlinked'] === 0 && (int)$r['uids'] === 1 ? (int)$r['uid'] : null,
            'suggestedUserId' => $shared || $confidence === 'none' ? null : $best,
            'confidence' => $shared ? 'shared' : $confidence,
        ];
    }
    return ['holders' => $out, 'people' => array_map(static fn($p) => ['userId' => (int)$p['user_id'], 'name' => $p['name'] !== '' ? $p['name'] : null,
        'email' => $p['email'], 'self' => !empty($p['self'])], $people)];
}

/** Ties every item currently held under one name to an account, and writes the name as the account has it. */
function staffLinkHolder(PDO $pdo, string $holderName, int $userId, int $me, ?int &$count = 0): ?string
{
    $count = 0;
    if (trim($holderName) === '') return 'Choose a holder name.';
    $stmt = $pdo->prepare("SELECT u.id, u.email, COALESCE(NULLIF(s.full_name, ''), TRIM(CONCAT_WS(' ', NULLIF(m.first_name, ''), NULLIF(m.surname, '')))) AS name, s.user_id AS staff
                           FROM users u LEFT JOIN staff_accounts s ON s.user_id = u.id AND s.active = 1 LEFT JOIN member_profiles m ON m.user_id = u.id WHERE u.id = ? LIMIT 1");
    $stmt->execute([$userId]);
    $p = $stmt->fetch();
    if (!$p || ($p['staff'] === null && $userId !== $me)) return 'Link items only to someone on the staff list.';
    $name = trim((string)$p['name']) !== '' ? trim((string)$p['name']) : $holderName;
    $up = $pdo->prepare('UPDATE ict_assignments SET holder_user_id = ?, holder_email = ?, holder_name = ? WHERE returned_at IS NULL AND holder_name = ?');
    $up->execute([$userId, $p['email'], $name, $holderName]);
    $count = $up->rowCount();
    logAdminAction($pdo, $me, 'asset_holder_linked', null, $holderName . ' -> ' . $p['email'] . ' (' . $count . ' items)');
    return null;
}

function staffHandle(PDO $pdo, array $config, string $route, string $method): void
{
    $user = auth($config);
    if (!staffEnsureTables($pdo)) respond(503, ['error' => 'The staff table could not be created.']);
    $userId = (int)$user['userId'];

    // The staff home page: who they are, and what is in their care.
    if ($route === 'staff/me' && $method === 'GET') {
        $stmt = $pdo->prepare('SELECT full_name FROM staff_accounts WHERE user_id = ?');
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        if (!$row) respond(403, ['error' => 'This account is not on the staff list.']);
        $assets = function_exists('ictAssetsHeldBy') ? ictAssetsHeldBy($pdo, (string)$user['email']) : [];
        respond(200, ['name' => $row['full_name'], 'email' => $user['email'], 'assets' => $assets]);
    }

    // Everything else that is not account administration is the staff intranet.
    if (strpos($route, 'staff/admin') !== 0) {
        requireModule('staffPortalHandle', 'lib/staff-portal.php');
        staffPortalHandle($pdo, $config, $route, $method, $user);
    }

    requireSuperAdmin($user, $config);

    if ($route === 'staff/admin' && $method === 'GET') {
        respond(200, ['staff' => staffList($pdo)]);
    }
    if ($route === 'staff/admin/add' && $method === 'POST') {
        $text = (string)(input()['list'] ?? '');
        if (trim($text) === '' || mb_strlen($text) > 50000) respond(400, ['error' => 'Paste the list of names and email addresses.']);
        $result = staffAdd($pdo, $text, $userId);
        logAdminAction($pdo, $userId, 'staff_added', null,
            count($result['added']) . ' new, ' . count($result['marked']) . ' existing accounts marked as staff');
        respond(200, $result + ['staff' => staffList($pdo)]);
    }
    if ($route === 'staff/admin/invite' && $method === 'POST') {
        if (function_exists('set_time_limit')) @set_time_limit(180);
        $result = staffSendBatch($pdo, $config, (int)(input()['limit'] ?? 20));
        logAdminAction($pdo, $userId, 'staff_invites_sent', null, count($result['sent']) . ' sent, ' . count($result['failed']) . ' failed');
        respond(200, $result + ['staff' => staffList($pdo)]);
    }
    if (preg_match('#^staff/admin/(\d+)/(invite|remove)$#', $route, $m) && $method === 'POST') {
        $target = (int)$m[1];
        if ($m[2] === 'invite') {
            $error = null;
            $sent = staffSendInvite($pdo, $config, $target, $error);
            logAdminAction($pdo, $userId, $sent ? 'staff_invite_sent' : 'staff_invite_failed', null, 'user #' . $target . ($error ? ': ' . $error : ''));
            if (!$sent) respond(400, ['error' => $error ?? 'Not sent.']);
            respond(200, ['staff' => staffList($pdo)]);
        }
        // Off the staff list. The account itself stays (it may be a member's too); an
        // unused invite link is cancelled so it cannot be finished later.
        $pdo->prepare('DELETE FROM staff_accounts WHERE user_id = ?')->execute([$target]);
        $pdo->prepare('UPDATE users SET reset_token = NULL, reset_expires = NULL WHERE id = ? AND email_verified = 0')->execute([$target]);
        logAdminAction($pdo, $userId, 'staff_removed', null, 'user #' . $target);
        respond(200, ['staff' => staffList($pdo)]);
    }

    // ----- linking the asset register's holder names to accounts -----
    if ($route === 'staff/admin/holders' && $method === 'GET') {
        respond(200, staffHolders($pdo, $userId));
    }
    if ($route === 'staff/admin/holders/link' && $method === 'POST') {
        $data = input();
        $links = isset($data['links']) && is_array($data['links']) ? $data['links'] : [['holderName' => $data['holderName'] ?? '', 'userId' => $data['userId'] ?? 0]];
        $done = 0;
        foreach (array_slice($links, 0, 200) as $l) {
            $error = staffLinkHolder($pdo, (string)($l['holderName'] ?? ''), (int)($l['userId'] ?? 0), $userId, $n);
            if ($error) respond(400, ['error' => $error]);
            $done += $n;
        }
        respond(200, ['linked' => $done] + staffHolders($pdo, $userId));
    }
    respond(404, ['error' => 'Route not found']);
}
