<?php
declare(strict_types=1);

/**
 * Members-only gate for public-site pages (currently only /learning).
 *
 * The portal already issues an httpOnly `resok_token` cookie on login (issueAuthCookie()
 * in resok-portal/public/api/index.php) scoped to path "/", so it is sent with requests
 * to the marketing site too. This file re-verifies that same HMAC token - deliberately
 * duplicating the check rather than calling the API over HTTP, so a gated page costs one
 * signature check plus one small query instead of an internal round trip.
 *
 * A valid token only proves "logged in". Membership tier is NOT in the token payload
 * (it holds userId/email/role), so the current membership_status is read from the
 * database on every request - that way a lapsed or rejected member loses access
 * immediately instead of at token expiry, up to 7 days later.
 *
 * The gate fails CLOSED: a missing config, an unreadable database, or any unexpected
 * error blocks the page rather than leaking it.
 */

const RESOK_GATE_LOGIN_URL = '/resok-portal/public/login';
const RESOK_GATE_JOIN_URL = '/membership-benefits';
const RESOK_GATE_PAYMENT_URL = '/resok-portal/public/payment';
const RESOK_GATE_DASHBOARD_URL = '/resok-portal/public/dashboard';

/**
 * @return array{state:string,userId:int,email:string,role:string,status:string}
 *   state is one of: active | anonymous | inactive | unavailable
 */
function resok_gate_check(): array
{
    $anonymous = ['state' => 'anonymous', 'userId' => 0, 'email' => '', 'role' => '', 'status' => ''];
    $unavailable = ['state' => 'unavailable', 'userId' => 0, 'email' => '', 'role' => '', 'status' => ''];

    $configPath = __DIR__ . '/resok-portal/public/api/config.php';
    if (!is_file($configPath)) {
        error_log('member-gate: portal config missing at ' . $configPath);
        return $unavailable;
    }
    $config = require $configPath;
    $secret = is_array($config) ? (string)($config['jwt_secret'] ?? '') : '';
    if (strlen($secret) < 32 || strpos($secret, 'replace_with') === 0) {
        error_log('member-gate: jwt_secret is unset or still the placeholder');
        return $unavailable;
    }

    $payload = resok_gate_verify_token((string)($_COOKIE['resok_token'] ?? ''), $secret);
    if ($payload === null) return $anonymous;

    $userId = (int)($payload['userId'] ?? 0);
    $email = (string)($payload['email'] ?? '');
    $role = (string)($payload['role'] ?? 'member');
    if ($userId <= 0) return $anonymous;

    // Admins get in regardless of their own membership record - they need to see exactly
    // what members see when reviewing or updating the learning content.
    if ($role === 'admin') {
        return ['state' => 'active', 'userId' => $userId, 'email' => $email, 'role' => $role, 'status' => 'active'];
    }

    try {
        $pdo = new PDO(
            sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                (string)$config['db_host'],
                (int)($config['db_port'] ?? 3306),
                (string)$config['db_name']
            ),
            (string)$config['db_user'],
            (string)$config['db_pass'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
        $stmt = $pdo->prepare('SELECT membership_status FROM member_profiles WHERE user_id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
    } catch (Throwable $error) {
        error_log('member-gate: membership lookup failed: ' . $error->getMessage());
        $unavailable['userId'] = $userId;
        $unavailable['email'] = $email;
        $unavailable['role'] = $role;
        return $unavailable;
    }

    // No profile row yet means the account was created but the membership form was never
    // completed - handled the same as payment_required.
    $status = (string)($row['membership_status'] ?? 'payment_required');

    return [
        'state' => $status === 'active' ? 'active' : 'inactive',
        'userId' => $userId,
        'email' => $email,
        'role' => $role,
        'status' => $status,
    ];
}

/** Verifies the portal's `body.signature` HMAC token. Mirrors auth() in the portal API. */
function resok_gate_verify_token(string $token, string $secret): ?array
{
    if ($token === '') return null;
    [$body, $sig] = array_pad(explode('.', $token, 2), 2, '');
    if ($body === '' || $sig === '') return null;

    $expected = rtrim(strtr(base64_encode(hash_hmac('sha256', $body, $secret, true)), '+/', '-_'), '=');
    if (!hash_equals($expected, $sig)) return null;

    $decoded = base64_decode(strtr($body, '-_', '+/'), true);
    if ($decoded === false) return null;
    $payload = json_decode($decoded, true);
    if (!is_array($payload) || (int)($payload['exp'] ?? 0) < time()) return null;

    return $payload;
}

/**
 * Gate the current request. Serves $contentPath to active members; otherwise sends the
 * visitor to login or renders an explanatory page. Never returns to the caller unless
 * access was granted.
 */
function resok_gate_serve(string $contentPath, string $pageName = 'this page'): void
{
    // Gated pages are per-visitor, so they must never sit in a shared or browser cache
    // where the next person on the machine could read them back out.
    header('Cache-Control: private, no-store, max-age=0');
    header('X-Content-Type-Options: nosniff');
    header('X-Robots-Tag: noindex, nofollow');

    $gate = resok_gate_check();

    if ($gate['state'] === 'active') {
        if (!is_file($contentPath)) {
            error_log('member-gate: gated content missing at ' . $contentPath);
            resok_gate_render_notice(503, 'Temporarily unavailable', $pageName, 'unavailable');
        }
        header('Content-Type: text/html; charset=UTF-8');
        readfile($contentPath);
        exit;
    }

    if ($gate['state'] === 'anonymous') {
        $next = '/' . ltrim((string)strtok((string)($_SERVER['REQUEST_URI'] ?? '/'), '?'), '/');
        header('Location: ' . RESOK_GATE_LOGIN_URL . '?next=' . rawurlencode($next), true, 302);
        exit;
    }

    if ($gate['state'] === 'unavailable') {
        resok_gate_render_notice(503, 'Temporarily unavailable', $pageName, 'unavailable');
    }

    resok_gate_render_notice(403, 'Members only', $pageName, $gate['status']);
}

/** Renders a small standalone page explaining why access was refused, then exits. */
function resok_gate_render_notice(int $httpStatus, string $heading, string $pageName, string $status): void
{
    $messages = [
        'payment_required' => [
            'Your ReSoK membership is not active yet. Complete your membership payment in the members\' portal and ' . $pageName . ' unlocks as soon as your payment is approved.',
            ['Complete payment' => RESOK_GATE_PAYMENT_URL, 'Go to my dashboard' => RESOK_GATE_DASHBOARD_URL],
        ],
        'under_review' => [
            'Your membership application is with our team for review. You will get access to ' . $pageName . ' as soon as it is approved.',
            ['Go to my dashboard' => RESOK_GATE_DASHBOARD_URL],
        ],
        'rejected' => [
            'Your membership application was not approved, so ' . $pageName . ' is not available on your account. Please contact the secretariat if you believe this is an error.',
            ['Contact ReSoK' => '/contact', 'Go to my dashboard' => RESOK_GATE_DASHBOARD_URL],
        ],
        // Nothing writes "expired" yet - the renewal cron only sends reminders. Kept so
        // that whenever lapsing is automated, lapsed members get the renewal copy rather
        // than the generic fallback below.
        'expired' => [
            'Your ReSoK membership has lapsed. Renew in the members\' portal to restore access to ' . $pageName . '.',
            ['Renew membership' => RESOK_GATE_PAYMENT_URL, 'Go to my dashboard' => RESOK_GATE_DASHBOARD_URL],
        ],
        'unavailable' => [
            'We could not verify your membership just now. Please try again in a few minutes, or email info@resok.org if it keeps happening.',
            ['Back to home' => '/'],
        ],
    ];

    [$message, $links] = $messages[$status] ?? [
        'Your ReSoK membership is not active, so ' . $pageName . ' is not available on your account yet.',
        ['Go to my dashboard' => RESOK_GATE_DASHBOARD_URL, 'Membership benefits' => RESOK_GATE_JOIN_URL],
    ];

    http_response_code($httpStatus);
    header('Content-Type: text/html; charset=UTF-8');

    $buttons = '';
    $primary = true;
    foreach ($links as $label => $href) {
        $buttons .= sprintf(
            '<a class="%s" href="%s">%s</a>',
            $primary ? 'btn btn-primary' : 'btn btn-muted',
            htmlspecialchars((string)$href, ENT_QUOTES),
            htmlspecialchars((string)$label, ENT_QUOTES)
        );
        $primary = false;
    }

    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta name="robots" content="noindex,nofollow">'
        . '<title>' . htmlspecialchars($heading, ENT_QUOTES) . ' | Respiratory Society of Kenya</title>'
        . '<link rel="icon" href="/favicon.jpg" type="image/jpeg">'
        . '<link href="https://fonts.googleapis.com/css2?family=Mulish:wght@700;800;900&family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">'
        . '<style>'
        . '*{box-sizing:border-box;margin:0;padding:0}'
        . 'body{min-height:100vh;display:grid;place-items:center;padding:32px;'
        . 'font-family:"Poppins","Segoe UI",sans-serif;background:#f5f7fa;color:#111f35;line-height:1.65}'
        . '.card{width:min(560px,100%);background:#fff;border:1px solid #e7ebef;border-radius:10px;'
        . 'padding:40px 34px;text-align:center;box-shadow:0 14px 38px rgba(15,23,42,.08)}'
        . '.lock{width:64px;height:64px;margin:0 auto 20px;border-radius:50%;display:grid;place-items:center;'
        . 'background:rgba(188,11,34,.08);font-size:26px}'
        . 'h1{font-family:"Mulish","Poppins",sans-serif;text-transform:uppercase;font-size:1.6rem;margin-bottom:12px}'
        . 'p{color:#667085;margin-bottom:26px}'
        . '.btns{display:flex;flex-wrap:wrap;gap:10px;justify-content:center}'
        . '.btn{border-radius:6px;padding:12px 18px;font-weight:700;font-size:.9rem;text-decoration:none;display:inline-block}'
        . '.btn-primary{background:#00932e;color:#fff}.btn-muted{background:#eef2f7;color:#344054}'
        . '</style></head><body><main class="card">'
        . '<div class="lock" aria-hidden="true">&#128274;</div>'
        . '<h1>' . htmlspecialchars($heading, ENT_QUOTES) . '</h1>'
        . '<p>' . htmlspecialchars($message, ENT_QUOTES) . '</p>'
        . '<div class="btns">' . $buttons . '</div>'
        . '</main></body></html>';
    exit;
}
