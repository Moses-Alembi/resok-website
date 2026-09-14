<?php
declare(strict_types=1);

/**
 * Member invitations: send an existing member a link that lets them claim their portal
 * account and fill in their own details.
 *
 * The alternative - creating accounts in bulk and emailing passwords - was rejected
 * deliberately. Registration asks for fifteen fields, and most of them (mobile, county,
 * division, profession, specialisation, institution, physical address, ID number, the
 * category they are paying for) are things only the member knows. Pre-filling them means
 * guessing, and owning every guess. It also puts a working password in an inbox, where it
 * stays, gets forwarded, and gets reused.
 *
 * So an invitation carries no credential. It proves only that this address was invited, and
 * the member sets their own password when they complete the form.
 *
 * The table builds itself on first use and reports failure rather than throwing: a host that
 * withholds CREATE should disable invitations, not break the admin panel around them.
 */

const INVITE_TTL_DAYS = 30;

function invitesEnsureTable(PDO $pdo): bool
{
    static $state = null;
    if ($state !== null) return $state;
    try {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS member_invites (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                email VARCHAR(190) NOT NULL,
                name VARCHAR(160) NULL,
                token CHAR(64) NOT NULL,
                invited_by INT UNSIGNED NULL,
                sent_at DATETIME NULL,
                send_error VARCHAR(255) NULL,
                claimed_at DATETIME NULL,
                claimed_user_id INT UNSIGNED NULL,
                revoked_at DATETIME NULL,
                expires_at DATETIME NOT NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY member_invites_email (email),
                UNIQUE KEY member_invites_token (token),
                KEY member_invites_state (claimed_at, expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
        $state = true;
    } catch (Throwable $e) {
        error_log('Invitations unavailable - could not create member_invites: ' . $e->getMessage());
        $state = false;
    }
    return $state;
}

function invitesRequire(PDO $pdo): void
{
    if (!invitesEnsureTable($pdo)) {
        respond(503, ['error' => 'Invitations are not available on this server: the database user cannot create the table they need.']);
    }
}

function inviteLink(array $config, string $token): string
{
    $portal = rtrim((string)($config['portal_base_url'] ?? ''), '/') ?: 'https://www.resok.org/resok-portal/public';
    return $portal . '/?invite=' . $token;
}

/**
 * Creates or refreshes an invitation. Re-inviting an address reuses its row and issues a
 * new token, so a resend cannot leave two live links for one person - and the old link
 * stops working, which is what someone forwarding an invitation on would expect.
 */
function inviteCreate(PDO $pdo, array $config, string $email, ?string $name, int $invitedBy): array
{
    $email = strtolower(trim($email));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Not a valid email address: ' . $email);
    }

    $existing = $pdo->prepare('SELECT id FROM users WHERE LOWER(email) = ? LIMIT 1');
    $existing->execute([$email]);
    if ($existing->fetch()) {
        throw new RuntimeException('Already has a portal account: ' . $email);
    }

    $token = bin2hex(random_bytes(32));
    $pdo->prepare(
        'INSERT INTO member_invites (email, name, token, invited_by, expires_at, created_at)
         VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? DAY), NOW())
         ON DUPLICATE KEY UPDATE
            name = VALUES(name), token = VALUES(token), invited_by = VALUES(invited_by),
            expires_at = VALUES(expires_at), revoked_at = NULL, send_error = NULL'
    )->execute([$email, $name ?: null, $token, $invitedBy, INVITE_TTL_DAYS]);

    $row = $pdo->prepare('SELECT * FROM member_invites WHERE email = ? LIMIT 1');
    $row->execute([$email]);
    return $row->fetch() ?: [];
}

/** Sends the invitation. Failure is recorded against the row rather than thrown, so one bad
 *  address in a batch does not stop the rest. */
function inviteSend(PDO $pdo, array $config, array $invite): bool
{
    $link = inviteLink($config, (string)$invite['token']);
    $name = trim((string)($invite['name'] ?? ''));
    $greeting = $name !== '' ? 'Dear ' . $name : 'Hello';

    $text = "{$greeting},\n\n"
        . "The Respiratory Society of Kenya members' portal is now open, and your account is ready to claim.\n\n"
        . "Claim it here:\n{$link}\n\n"
        . "You will be asked to set your own password and confirm your details. The link is unique to you "
        . "and expires in " . INVITE_TTL_DAYS . " days.\n\n"
        . "Respiratory Society of Kenya";

    $sent = false;
    try {
        if (function_exists('brandedEmailHtml') && class_exists('SimpleMailer')) {
            $html = brandedEmailHtml(
                'Your ReSoK portal account is ready',
                '<p style="margin:0 0 14px;font-size:15px;line-height:1.65;">' . htmlspecialchars($greeting, ENT_QUOTES) . ',</p>'
                . '<p style="margin:0 0 14px;font-size:15px;line-height:1.65;">The Respiratory Society of Kenya members&rsquo; '
                . 'portal is now open. Your membership card, receipts and renewals all live there.</p>'
                . '<p style="margin:0 0 14px;font-size:15px;line-height:1.65;">Claim your account below. You will set your own '
                . 'password and confirm your details, so everything on your record is right from the start.</p>'
                . '<p style="margin:0;font-size:13px;color:#667085;">This link is unique to you and expires in '
                . INVITE_TTL_DAYS . ' days.</p>',
                'Claim my account',
                $link
            );
            $sent = (new SimpleMailer($config))->send((string)$invite['email'],
                'Your ReSoK portal account is ready', $text, [], $html);
        }
    } catch (Throwable $e) {
        error_log('Invite send failed for ' . $invite['email'] . ': ' . $e->getMessage());
        $pdo->prepare('UPDATE member_invites SET send_error = ? WHERE id = ?')
            ->execute([mb_substr($e->getMessage(), 0, 255), (int)$invite['id']]);
        return false;
    }

    if ($sent) {
        $pdo->prepare('UPDATE member_invites SET sent_at = NOW(), send_error = NULL WHERE id = ?')
            ->execute([(int)$invite['id']]);
    } else {
        $pdo->prepare('UPDATE member_invites SET send_error = ? WHERE id = ?')
            ->execute(['The mail server did not accept the message.', (int)$invite['id']]);
    }
    return $sent;
}

/** Looks an invitation up by token. Returns null for anything not currently claimable. */
function inviteByToken(PDO $pdo, string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) return null;
    $stmt = $pdo->prepare('SELECT * FROM member_invites
                            WHERE token = ? AND claimed_at IS NULL AND revoked_at IS NULL
                              AND expires_at > NOW() LIMIT 1');
    $stmt->execute([$token]);
    return $stmt->fetch() ?: null;
}

/** Marks an invitation used. Never throws: a registration that succeeded must not be undone
 *  because the bookkeeping around it failed. */
function inviteMarkClaimed(PDO $pdo, string $token, int $userId): void
{
    try {
        if (!invitesEnsureTable($pdo)) return;
        $pdo->prepare('UPDATE member_invites SET claimed_at = NOW(), claimed_user_id = ?
                        WHERE token = ? AND claimed_at IS NULL')->execute([$userId, $token]);
    } catch (Throwable $e) {
        error_log('Could not mark invite claimed: ' . $e->getMessage());
    }
}

function inviteList(PDO $pdo): array
{
    $rows = $pdo->query('SELECT * FROM member_invites ORDER BY created_at DESC LIMIT 500')->fetchAll();
    return array_map(function (array $r): array {
        $status = 'pending';
        if ($r['claimed_at']) $status = 'claimed';
        elseif ($r['revoked_at']) $status = 'revoked';
        elseif (strtotime((string)$r['expires_at']) < time()) $status = 'expired';
        elseif ($r['send_error']) $status = 'failed';
        elseif (!$r['sent_at']) $status = 'unsent';
        return [
            'id' => (int)$r['id'],
            'email' => $r['email'],
            'name' => $r['name'],
            'status' => $status,
            'sentAt' => $r['sent_at'],
            'claimedAt' => $r['claimed_at'],
            'expiresAt' => $r['expires_at'],
            'error' => $r['send_error'],
        ];
    }, $rows);
}

/* ---------------------------------------------------------------------------------------
 * Claim emails for members imported from the register.
 *
 * Imported accounts already exist, carrying the member's name, membership number and paid
 * years, but nobody knows their password and their address is unverified, so they cannot
 * sign in. An invitation cannot reach them: inviteCreate() refuses any address that already
 * has an account, and a fresh registration would create a second, empty record beside the
 * real one - asking a paid-up member to pay again.
 *
 * So the claim link is a password-reset link with a longer life. Completing a reset already
 * marks the address verified (auth/reset-password), which is exactly what claiming needs: the
 * link went to that mailbox, and somebody who could open it chose a password. If the link
 * lapses, "Forgot password" issues an ordinary one to the same account.
 * ------------------------------------------------------------------------------------- */

const CLAIM_TTL_DAYS = 30;

function claimsEnsureTable(PDO $pdo): bool
{
    static $state = null;
    if ($state !== null) return $state;
    try {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS member_claim_emails (
                user_id INT UNSIGNED NOT NULL,
                sent_at DATETIME NULL,
                send_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                send_error VARCHAR(255) NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
        $state = true;
    } catch (Throwable $e) {
        error_log('Claim emails unavailable - could not create member_claim_emails: ' . $e->getMessage());
        $state = false;
    }
    return $state;
}

/** Member accounts nobody can sign in to yet: a profile, and an address never verified. */
function claimableFrom(): string
{
    return "FROM users u
            JOIN member_profiles mp ON mp.user_id = u.id
            LEFT JOIN member_claim_emails c ON c.user_id = u.id
            WHERE u.role = 'member' AND u.email_verified = 0";
}

function claimsSummary(PDO $pdo): array
{
    $row = $pdo->query(
        'SELECT COUNT(*) AS unclaimed,
                COALESCE(SUM(c.sent_at IS NOT NULL AND c.send_error IS NULL), 0) AS sent,
                COALESCE(SUM(c.send_error IS NOT NULL), 0) AS failed ' . claimableFrom()
    )->fetch();
    $unclaimed = (int)$row['unclaimed'];
    $sent = (int)$row['sent'];
    $failed = (int)$row['failed'];
    return [
        'unclaimed' => $unclaimed,
        'sent' => $sent,
        'failed' => $failed,
        'notSent' => max(0, $unclaimed - $sent - $failed),
        'batchSize' => 20,
    ];
}

/**
 * Issues a fresh claim link and emails it. A resend replaces the token, so only the newest
 * link works. The outcome is recorded against the member either way.
 *
 * @param array<string,mixed> $member A member_profiles row joined to users (user_id, email).
 */
function claimSend(PDO $pdo, array $config, array $member, ?string &$error = null): bool
{
    $error = null;
    $userId = (int)$member['user_id'];
    $email = (string)$member['email'];

    $token = bin2hex(random_bytes(32));
    $pdo->prepare('UPDATE users SET reset_token = ?, reset_expires = DATE_ADD(NOW(), INTERVAL ? DAY) WHERE id = ?')
        ->execute([$token, CLAIM_TTL_DAYS, $userId]);

    $portal = rtrim((string)($config['portal_base_url'] ?? ''), '/') ?: 'https://www.resok.org/resok-portal/public';
    $link = $portal . '/forgot-password?claim=1&token=' . $token;

    $name = trim(implode(' ', array_filter([
        trim((string)($member['title'] ?? '')),
        trim((string)($member['first_name'] ?? '')),
        trim((string)($member['surname'] ?? '')),
    ])));
    $greeting = $name !== '' ? 'Dear ' . $name : 'Dear member';
    $number = trim((string)($member['membership_id'] ?? ''));
    $until = !empty($member['renewal_due']) ? date('j F Y', (int)strtotime((string)$member['renewal_due'])) : '';

    $recordLine = 'Your account has been set up from our membership records'
        . ($number !== '' ? ', under membership number ' . $number : '') . '.';
    $untilLine = $until !== '' ? 'Your membership is active until ' . $until . '.' : '';

    $text = "{$greeting},\n\n"
        . "The Respiratory Society of Kenya members' portal is now open. {$recordLine}"
        . ($untilLine !== '' ? " {$untilLine}" : '') . "\n\n"
        . "To start using it, choose your password here:\n{$link}\n\n"
        . "Once you are signed in, please check your details and add anything missing. The link is unique "
        . "to you and expires in " . CLAIM_TTL_DAYS . " days. If it expires, use \"Forgot password\" on the "
        . "login page with this email address.\n\n"
        . "If you were not expecting this email, you can ignore it.\n\n"
        . "Respiratory Society of Kenya";

    $sent = false;
    try {
        if (!function_exists('brandedEmailHtml') || !class_exists('SimpleMailer')) {
            throw new RuntimeException('The mail helpers are not loaded on this server.');
        }
        $p = '<p style="margin:0 0 14px;font-size:15px;line-height:1.65;">';
        $html = brandedEmailHtml(
            'Your ReSoK portal account is ready',
            $p . htmlspecialchars($greeting, ENT_QUOTES) . ',</p>'
            . $p . 'The Respiratory Society of Kenya members&rsquo; portal is now open. '
            . htmlspecialchars($recordLine . ($untilLine !== '' ? ' ' . $untilLine : ''), ENT_QUOTES) . '</p>'
            . $p . 'Choose your password to start using it. Once you are signed in, please check your '
            . 'details and add anything missing.</p>'
            . '<p style="margin:0;font-size:13px;color:#667085;">This link is unique to you and expires in '
            . CLAIM_TTL_DAYS . ' days. If it expires, use &ldquo;Forgot password&rdquo; on the login page '
            . 'with this email address. If you were not expecting this email, you can ignore it.</p>',
            'Choose my password',
            $link
        );
        $sent = (new SimpleMailer($config))->send($email, 'Your ReSoK portal account is ready', $text, [], $html);
        if (!$sent) $error = 'The mail server did not accept the message.';
    } catch (Throwable $e) {
        $error = mb_substr($e->getMessage(), 0, 255);
        error_log('Claim email failed for ' . $email . ': ' . $e->getMessage());
    }

    $pdo->prepare(
        'INSERT INTO member_claim_emails (user_id, sent_at, send_count, send_error, updated_at)
         VALUES (?, ?, ?, ?, NOW())
         ON DUPLICATE KEY UPDATE
            sent_at = COALESCE(VALUES(sent_at), sent_at),
            send_count = send_count + VALUES(send_count),
            send_error = VALUES(send_error),
            updated_at = NOW()'
    )->execute([$userId, $sent ? date('Y-m-d H:i:s') : null, $sent ? 1 : 0, $sent ? null : $error]);

    return $sent;
}

/**
 * Emails the next batch of imported members who have never been sent a claim link.
 *
 * Only never-attempted members are picked up. A failed address is not retried by the batch -
 * otherwise one bad address would be attempted, and fail, on every run - and is resent from
 * the member's own record once corrected.
 *
 * @return array{sent: list<string>, failed: list<array{email:string,reason:string}>}
 */
function claimsSendBatch(PDO $pdo, array $config, int $limit): array
{
    $stmt = $pdo->prepare(
        'SELECT mp.*, u.email ' . claimableFrom() . ' AND c.user_id IS NULL
         ORDER BY mp.id
         LIMIT ?'
    );
    $stmt->bindValue(1, $limit, PDO::PARAM_INT);
    $stmt->execute();

    $sent = [];
    $failed = [];
    foreach ($stmt->fetchAll() as $member) {
        $reason = null;
        if (claimSend($pdo, $config, $member, $reason)) {
            $sent[] = (string)$member['email'];
        } else {
            $failed[] = ['email' => (string)$member['email'], 'reason' => $reason ?? 'The email could not be sent.'];
        }
    }
    return ['sent' => $sent, 'failed' => $failed];
}
