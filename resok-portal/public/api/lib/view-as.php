<?php
/**
 * "View as member": a super administrator sees the member portal exactly as one member sees
 * it, without that member's password, and can correct their profile for them.
 *
 * How it works. Starting it sets a second cookie, resok_viewas, beside the administrator's own
 * session. On every request auth() reads it and, if it is genuine, answers as the member
 * instead. It is only honoured together with the super administrator's own live session -
 * the same account, the same sign-in (its jti) - so it is useless on its own, dies with that
 * session (logout, idle timeout, sign-out-everywhere, losing super admin) and lasts at most
 * VIEW_AS_MINUTES. It is signed with a key derived from the session secret, so it can never
 * be passed off as a session cookie or the other way round.
 *
 * What the administrator can do while viewing is narrower than what the member can:
 *   - reading is allowed, except the areas below that are private even from a super admin
 *     acting as someone (their two-factor and passkeys, staff pay and HR, ICT, MDT patient
 *     cases, blinded abstract reviews);
 *   - the only writes are the member's own profile and photo (VIEW_AS_WRITES). Payments,
 *     votes, nominations, course attempts, registrations and passwords are refused, so
 *     nothing is ever done in the member's name that they did not do;
 *   - only ordinary member accounts can be viewed, never another administrator.
 * Starting, ending and every edit go to the admin audit log (admin_actions) and the security
 * log, with the administrator's account.
 */

const VIEW_AS_MINUTES = 30;

/** Writes allowed while viewing: the member's own profile. Route => method. */
const VIEW_AS_WRITES = [
    'members/me' => 'PATCH',
    'members/me/profile-image' => 'POST',
];

/** Read even by the member themselves, but not by an administrator acting as them. */
const VIEW_AS_PRIVATE_PREFIXES = ['auth/mfa', 'auth/passkey', 'staff/', 'ict/', 'mdt', 'abstracts/review'];

/** Routes that always act as the administrator, never as the member being viewed. */
const VIEW_AS_OWN_ROUTES = ['view-as/start', 'view-as/end', 'view-as/status', 'auth/logout'];

function viewAsKey(array $config): string
{
    return hash_hmac('sha256', 'resok-view-as', (string)$config['jwt_secret']);
}

function viewAsSetCookie(string $value, int $expires): void
{
    setcookie('resok_viewas', $value, [
        'expires' => $expires,
        'path' => '/',
        'secure' => requestIsSecure(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function viewAsClearCookie(): void
{
    if (!isset($_COOKIE['resok_viewas'])) return;
    viewAsSetCookie('', time() - 3600);
    unset($_COOKIE['resok_viewas']);
}

/** The cookie's claims if it is genuine and current, otherwise null. Says nothing about the actor. */
function viewAsClaims(array $config): ?array
{
    $raw = (string)($_COOKIE['resok_viewas'] ?? '');
    if ($raw === '') return null;
    [$body, $sig] = array_pad(explode('.', $raw, 2), 2, '');
    if ($body === '' || $sig === '' || !hash_equals(b64url(hash_hmac('sha256', $body, viewAsKey($config), true)), $sig)) return null;
    $claims = json_decode(base64_decode(strtr($body, '-_', '+/')), true);
    if (!is_array($claims) || (int)($claims['exp'] ?? 0) < time() || (int)($claims['t'] ?? 0) <= 0 || (int)($claims['a'] ?? 0) <= 0) return null;
    return $claims;
}

/** The member account behind a profile or user id, or null when it cannot be viewed. */
function viewAsTarget(PDO $pdo, int $userId): ?array
{
    $stmt = $pdo->prepare('SELECT u.id, u.email, u.role, mp.id AS profile_id, mp.title, mp.first_name, mp.surname
                             FROM users u LEFT JOIN member_profiles mp ON mp.user_id = u.id WHERE u.id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    if (!$row || (string)$row['role'] !== 'member') return null;
    return $row;
}

function viewAsName(array $row): string
{
    $name = trim(implode(' ', array_filter([$row['title'] ?? '', $row['first_name'] ?? '', $row['surname'] ?? ''])));
    return $name !== '' ? $name : (string)$row['email'];
}

/**
 * Given the signed-in administrator (already checked by auth()), who this request acts as.
 * Returns the administrator unchanged when there is no view-as cookie, and the member when
 * there is a valid one - refusing here anything the administrator may not do as them.
 */
function viewAsApply(array $config, array $actor): array
{
    if (!isset($_COOKIE['resok_viewas'])) return $actor;
    $claims = viewAsClaims($config);
    $sameSession = $claims !== null
        && !empty($actor['superAdmin'])
        && (int)$claims['a'] === (int)($actor['userId'] ?? 0)
        && hash_equals((string)($claims['aj'] ?? ''), (string)($actor['jti'] ?? ''));
    if (!$sameSession) {
        viewAsClearCookie();
        return $actor;
    }
    $route = (string)($GLOBALS['route'] ?? '');
    if (in_array($route, VIEW_AS_OWN_ROUTES, true)) return $actor;

    $target = viewAsTarget(db($config), (int)$claims['t']);
    if ($target === null) {
        viewAsClearCookie();
        return $actor;
    }
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $refuse = static function (string $why) use ($target) {
        respond(403, ['error' => $why, 'viewAs' => true, 'viewingName' => viewAsName($target)]);
    };
    foreach (VIEW_AS_PRIVATE_PREFIXES as $prefix) {
        if (strncmp($route, $prefix, strlen($prefix)) === 0) {
            $refuse('You are viewing the portal as ' . viewAsName($target) . '. This area stays private to them. Exit "View as" to return to your own account.');
        }
    }
    if (!in_array($method, ['GET', 'HEAD', 'OPTIONS'], true) && (VIEW_AS_WRITES[$route] ?? null) !== $method) {
        $refuse('You are viewing the portal as ' . viewAsName($target) . '. Only their profile can be changed from here; nothing else is done in their name.');
    }
    return [
        'userId' => (int)$target['id'],
        'email' => (string)$target['email'],
        'role' => 'member',
        'superAdmin' => false,
        'mfaSetupRequired' => false,
        'exp' => (int)$claims['exp'],
        'viewAs' => [
            'actorId' => (int)$actor['userId'],
            'actorEmail' => (string)($actor['email'] ?? ''),
            'profileId' => $target['profile_id'] !== null ? (int)$target['profile_id'] : null,
        ],
    ];
}

/** Record an edit made while viewing as a member, against that member, under the administrator. */
function viewAsLogEdit(PDO $pdo, array $user, string $what): void
{
    if (empty($user['viewAs'])) return;
    try {
        logAdminAction($pdo, (int)$user['viewAs']['actorId'], 'view_as_edit', $user['viewAs']['profileId'], $what);
    } catch (Throwable $e) {
        error_log('Could not log a view-as edit: ' . $e->getMessage());
    }
}

/** view-as/start, view-as/end, view-as/status. */
function viewAsHandle(PDO $pdo, array $config, string $route, string $method): void
{
    if ($route === 'view-as/status' && $method === 'GET') {
        $claims = viewAsClaims($config);
        $actor = $claims ? authOptional($config) : null;
        if (!$claims || !$actor || empty($actor['superAdmin']) || (int)$claims['a'] !== (int)$actor['userId']
            || !hash_equals((string)($claims['aj'] ?? ''), (string)($actor['jti'] ?? ''))) {
            respond(200, ['active' => false]);
        }
        $target = viewAsTarget($pdo, (int)$claims['t']);
        if (!$target) respond(200, ['active' => false]);
        respond(200, [
            'active' => true,
            'name' => viewAsName($target),
            'email' => (string)$target['email'],
            'profileId' => $target['profile_id'] !== null ? (int)$target['profile_id'] : null,
            'endsAt' => (int)$claims['exp'],
        ]);
    }

    if ($route === 'view-as/start' && $method === 'POST') {
        $actor = auth($config);
        requireSuperAdmin($actor, $config);
        $data = input();
        $userId = (int)($data['userId'] ?? 0);
        if ($userId <= 0 && (int)($data['profileId'] ?? 0) > 0) {
            $q = $pdo->prepare('SELECT user_id FROM member_profiles WHERE id = ? LIMIT 1');
            $q->execute([(int)$data['profileId']]);
            $userId = (int)$q->fetchColumn();
        }
        if ($userId <= 0) respond(404, ['error' => 'No such member.']);
        if ($userId === (int)$actor['userId']) respond(400, ['error' => 'That is your own account.']);
        $target = viewAsTarget($pdo, $userId);
        if (!$target) respond(403, ['error' => 'Only ordinary member accounts can be viewed. Administrators cannot be viewed as.']);

        $expires = time() + VIEW_AS_MINUTES * 60;
        if (!empty($actor['exp'])) $expires = min($expires, (int)$actor['exp']);
        $body = b64url(json_encode([
            't' => $userId,
            'a' => (int)$actor['userId'],
            'aj' => (string)($actor['jti'] ?? ''),
            'exp' => $expires,
        ]));
        viewAsSetCookie($body . '.' . b64url(hash_hmac('sha256', $body, viewAsKey($config), true)), $expires);

        $name = viewAsName($target);
        logAdminAction($pdo, (int)$actor['userId'], 'view_as_started', $target['profile_id'] !== null ? (int)$target['profile_id'] : null, $name . ' <' . $target['email'] . '>');
        securityLog($pdo, $config, 'view_as_started', 'warning', 'view-as', 'Super admin viewing the portal as ' . $target['email'], (int)$actor['userId']);
        respond(200, [
            'active' => true,
            'name' => $name,
            'email' => (string)$target['email'],
            'endsAt' => $expires,
            // A member goes to their dashboard; an account with no membership is a learner.
            'goTo' => $target['profile_id'] !== null ? 'dashboard' : 'academy',
        ]);
    }

    if ($route === 'view-as/end' && $method === 'POST') {
        $claims = viewAsClaims($config);
        viewAsClearCookie();
        if ($claims) {
            $actor = authOptional($config);
            if ($actor && (int)$actor['userId'] === (int)$claims['a']) {
                $target = viewAsTarget($pdo, (int)$claims['t']);
                logAdminAction($pdo, (int)$actor['userId'], 'view_as_ended', $target && $target['profile_id'] !== null ? (int)$target['profile_id'] : null,
                    $target ? viewAsName($target) : null);
            }
        }
        respond(200, ['active' => false]);
    }
}
