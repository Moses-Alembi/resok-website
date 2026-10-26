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
    $stmt = $pdo->prepare('SELECT 1 FROM staff_accounts WHERE user_id = ?');
    $stmt->execute([$userId]);
    return (bool)$stmt->fetchColumn();
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
    respond(404, ['error' => 'Route not found']);
}
