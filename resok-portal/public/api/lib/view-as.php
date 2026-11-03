<?php
/**
 * "View as": a super administrator sees a member or staff portal without their password.
 * Member previews allow existing profile corrections. Staff previews are read-only.
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
 *   - member previews require ordinary members; staff previews require active staff and
 *     retain a verified super administrator's staff-workspace permissions while all
 *     writes remain blocked. Ordinary staff never inherit the viewing actor's privileges.
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

/** A return destination is part of the signed preview, never an arbitrary redirect URL. */
function viewAsReturnTo(string $scope, $requested = null): string
{
    $allowed = $scope === 'staff' ? ['ict#staff', 'staff#people'] : ['admin-review'];
    return is_string($requested) && in_array($requested, $allowed, true) ? $requested : $allowed[0];
}

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
    if (!is_array($claims) || (int)($claims['exp'] ?? 0) < time() || (int)($claims['t'] ?? 0) <= 0 || (int)($claims['a'] ?? 0) <= 0
        || !in_array($claims['scope'] ?? 'member', ['member', 'staff'], true)) return null;
    return $claims;
}

/** The member account behind a profile or user id, or null when it cannot be viewed. */
function viewAsTarget(PDO $pdo, int $userId, string $scope = 'member', array $config = []): ?array
{
    if ($scope === 'staff' && (!function_exists('staffPortalEnsureTables') || !staffPortalEnsureTables($pdo))) return null;
    $stmt = $pdo->prepare('SELECT u.id, u.email, u.role, mp.id AS profile_id, mp.title, mp.first_name, mp.surname'
        . ($scope === 'staff' ? ', s.full_name AS staff_name, s.job_title' : '') . '
                             FROM users u LEFT JOIN member_profiles mp ON mp.user_id = u.id'
        . ($scope === 'staff' ? ' JOIN staff_accounts s ON s.user_id = u.id AND s.active = 1' : '') . ' WHERE u.id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    if (!$row) return null;
    if ($scope !== 'staff' && (string)$row['role'] !== 'member') return null;
    return $row;
}

function viewAsName(array $row): string
{
    if (trim((string)($row['staff_name'] ?? '')) !== '') return trim((string)$row['staff_name']);
    $name = trim(implode(' ', array_filter([$row['title'] ?? '', $row['first_name'] ?? '', $row['surname'] ?? ''])));
    return $name !== '' ? $name : (string)$row['email'];
}

/** Self-service reads for ordinary staff, and staff-management reads for verified peers. */
function viewAsStaffReadAllowed(string $route, bool $targetSuperAdmin = false): bool
{
    // The real actor is already a verified super admin. A verified peer may have their
    // staff management screens previewed too; this never opens other private modules.
    if ($targetSuperAdmin && ($route === 'admin/whoami' || strncmp($route, 'staff/', 6) === 0)) return true;
    if (in_array($route, ['members/me', 'staff/home', 'staff/profile', 'staff/directory', 'staff/departments', 'staff/notifications', 'staff/leave', 'welfare/me', 'welfare/live'], true)) return true;
    if ($route === 'staff/announcements') return empty($_GET['manage']);
    if (preg_match('#^staff/announcements/[1-9][0-9]*$#D', $route)) return true;
    // The report filter is mandatory even when the target independently holds ICT grants.
    if ($route === 'ict/tickets') return (string)($_GET['reported'] ?? '') === '1';
    if ($route === 'elections') return (string)($_GET['electorate'] ?? '') === 'staff';
    return (bool)preg_match('#^elections/[A-Za-z0-9-]+(?:/(?:ballot|nominations))?$#D', $route);
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

    $scope = (string)($claims['scope'] ?? 'member');
    $target = viewAsTarget(db($config), (int)$claims['t'], $scope, $config);
    if ($target === null) {
        viewAsClearCookie();
        if ($scope === 'staff') respond(403, ['error' => 'This staff preview has ended because the account is no longer available. Exit "View as" to return to your own account.', 'viewAs' => true]);
        return $actor;
    }
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $targetSuperAdmin = $scope === 'staff' && isSuperAdmin(['role'=>$target['role'],'email'=>$target['email']], $config);
    $refuse = static function (string $why) use ($target) {
        respond(403, ['error' => $why, 'viewAs' => true, 'viewingName' => viewAsName($target)]);
    };
    if ($scope === 'staff') {
        if (!in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            $refuse('You are viewing the staff portal as ' . viewAsName($target) . '. This preview is read-only; nothing can be changed or submitted in their name.');
        }
        if (!viewAsStaffReadAllowed($route, $targetSuperAdmin)) {
            $refuse('You are viewing the staff portal as ' . viewAsName($target) . '. Exit "View as" to open this area with your own account.');
        }
    } else {
        foreach (VIEW_AS_PRIVATE_PREFIXES as $prefix) {
            if (strncmp($route, $prefix, strlen($prefix)) === 0) {
                $refuse('You are viewing the portal as ' . viewAsName($target) . '. This area stays private to them. Exit "View as" to return to your own account.');
            }
        }
        if (!in_array($method, ['GET', 'HEAD', 'OPTIONS'], true) && (VIEW_AS_WRITES[$route] ?? null) !== $method) {
            $refuse('You are viewing the portal as ' . viewAsName($target) . '. Only their profile can be changed from here; nothing else is done in their name.');
        }
    }
    return [
        'userId' => (int)$target['id'],
        'email' => (string)$target['email'],
        'role' => $targetSuperAdmin ? 'admin' : 'member',
        'superAdmin' => $targetSuperAdmin,
        'mfaSetupRequired' => false,
        'exp' => (int)$claims['exp'],
        'viewAs' => [
            'actorId' => (int)$actor['userId'],
            'actorEmail' => (string)($actor['email'] ?? ''),
            'profileId' => $target['profile_id'] !== null ? (int)$target['profile_id'] : null,
            'scope' => $scope,
            'readOnly' => $scope === 'staff',
            'targetSuperAdmin' => $targetSuperAdmin,
            'returnTo' => viewAsReturnTo($scope, $claims['returnTo'] ?? null),
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
            respond(200, ['active' => false, 'returnTo' => viewAsReturnTo((string)($claims['scope'] ?? 'member'), $claims['returnTo'] ?? null)]);
        }
        $scope = (string)($claims['scope'] ?? 'member');
        $returnTo = viewAsReturnTo($scope, $claims['returnTo'] ?? null);
        $target = viewAsTarget($pdo, (int)$claims['t'], $scope, $config);
        if (!$target) {
            viewAsClearCookie();
            respond(200, ['active' => false, 'returnTo' => $returnTo]);
        }
        respond(200, [
            'active' => true,
            'name' => viewAsName($target),
            'email' => (string)$target['email'],
            'profileId' => $target['profile_id'] !== null ? (int)$target['profile_id'] : null,
            'endsAt' => (int)$claims['exp'],
            'scope' => $scope,
            'readOnly' => $scope === 'staff',
            'jobTitle' => $scope === 'staff' ? ($target['job_title'] ?? null) : null,
            'targetUserId' => (int)$target['id'],
            'targetSuperAdmin' => $scope === 'staff' && isSuperAdmin(['role'=>$target['role'],'email'=>$target['email']], $config),
            'returnTo' => $returnTo,
        ]);
    }

    if ($route === 'view-as/start' && $method === 'POST') {
        $actor = auth($config);
        requireSuperAdmin($actor, $config);
        $data = input();
        $scope = $data['scope'] ?? 'member';
        if (!in_array($scope, ['member', 'staff'], true)) respond(400, ['error' => 'Choose a member or staff preview.']);
        $returnTo = viewAsReturnTo($scope, $data['returnTo'] ?? null);
        $userId = (int)($data['userId'] ?? 0);
        if ($scope === 'member' && $userId <= 0 && (int)($data['profileId'] ?? 0) > 0) {
            $q = $pdo->prepare('SELECT user_id FROM member_profiles WHERE id = ? LIMIT 1');
            $q->execute([(int)$data['profileId']]);
            $userId = (int)$q->fetchColumn();
        }
        if ($userId <= 0) respond(404, ['error' => $scope === 'staff' ? 'No such staff account.' : 'No such member.']);
        if ($userId === (int)$actor['userId']) respond(400, ['error' => 'That is your own account.']);
        $target = viewAsTarget($pdo, $userId, $scope, $config);
        if (!$target) respond(403, ['error' => $scope === 'staff'
            ? 'Choose an active staff account.'
            : 'Only ordinary member accounts can be viewed. Administrators cannot be viewed as.']);

        $expires = time() + VIEW_AS_MINUTES * 60;
        if (!empty($actor['exp'])) $expires = min($expires, (int)$actor['exp']);
        $body = b64url(json_encode([
            't' => $userId,
            'a' => (int)$actor['userId'],
            'aj' => (string)($actor['jti'] ?? ''),
            'exp' => $expires,
            'scope' => $scope,
            'returnTo' => $returnTo,
        ]));
        viewAsSetCookie($body . '.' . b64url(hash_hmac('sha256', $body, viewAsKey($config), true)), $expires);

        $name = viewAsName($target);
        logAdminAction($pdo, (int)$actor['userId'], 'view_as_started', $target['profile_id'] !== null ? (int)$target['profile_id'] : null, ($scope === 'staff' ? 'Staff user ' . $userId . ': ' : '') . $name . ' <' . $target['email'] . '>');
        securityLog($pdo, $config, 'view_as_started', 'warning', 'view-as', 'Super admin viewing the ' . $scope . ' portal as ' . $target['email'], (int)$actor['userId']);
        respond(200, [
            'active' => true,
            'name' => $name,
            'email' => (string)$target['email'],
            'endsAt' => $expires,
            'scope' => $scope,
            'readOnly' => $scope === 'staff',
            'jobTitle' => $scope === 'staff' ? ($target['job_title'] ?? null) : null,
            'targetUserId' => (int)$target['id'],
            'targetSuperAdmin' => $scope === 'staff' && isSuperAdmin(['role'=>$target['role'],'email'=>$target['email']], $config),
            'returnTo' => $returnTo,
            // A member goes to their dashboard; an account with no membership is a learner.
            'goTo' => $scope === 'staff' ? 'staff' : ($target['profile_id'] !== null ? 'dashboard' : 'academy'),
        ]);
    }

    if ($route === 'view-as/end' && $method === 'POST') {
        $claims = viewAsClaims($config);
        $scope = (string)($claims['scope'] ?? 'member');
        $returnTo = viewAsReturnTo($scope, $claims['returnTo'] ?? null);
        viewAsClearCookie();
        if ($claims) {
            $actor = authOptional($config);
            if ($actor && !empty($actor['superAdmin']) && (int)$actor['userId'] === (int)$claims['a']
                && hash_equals((string)($claims['aj'] ?? ''), (string)($actor['jti'] ?? ''))) {
                $target = viewAsTarget($pdo, (int)$claims['t'], $scope, $config);
                logAdminAction($pdo, (int)$actor['userId'], 'view_as_ended', $target && $target['profile_id'] !== null ? (int)$target['profile_id'] : null,
                    $target ? viewAsName($target) : null);
            }
        }
        respond(200, ['active' => false, 'returnTo' => $returnTo, 'targetUserId' => $claims ? (int)$claims['t'] : null]);
    }
}
