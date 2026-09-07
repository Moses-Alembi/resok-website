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
                . 'portal is now open. Your membership card, CPD record, receipts and event registrations all live there.</p>'
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
