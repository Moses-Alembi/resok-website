<?php
declare(strict_types=1);

/**
 * One-time recovery: recreate an administrator when none exists.
 *
 * This carries no secret, and does not need one, because it refuses to do anything unless
 * the users table contains no administrator at all. In that state there is nobody to protect
 * the site from - the site is already locked out of itself - and the moment an admin exists
 * this endpoint becomes permanently inert. That is a stronger guarantee than a key sitting
 * in a config file, which stays usable for as long as someone forgets to remove it.
 *
 * It exists because config.local.php holds the database password and JWT secret and is
 * therefore gitignored, so the normal setup_key route cannot be reached by anyone deploying
 * through git rather than a file manager.
 *
 * Delete this file once you are back in. It is inert while an admin exists, but a recovery
 * hatch left in place is one more thing to reason about later.
 */

header('Content-Type: application/json');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');

$config = require __DIR__ . '/config.php';

try {
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $config['db_host'], (int)($config['db_port'] ?? 3306), $config['db_name']),
        $config['db_user'], $config['db_pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (Throwable $e) {
    http_response_code(500);
    error_log('bootstrap-admin: database unreachable: ' . $e->getMessage());
    echo json_encode(['error' => 'Could not reach the database.']);
    exit;
}

$admins = (int)$pdo->query("SELECT COUNT(*) c FROM users WHERE role = 'admin'")->fetch()['c'];

// GET reports the state, so you can check whether recovery is even needed without sending
// anything. It never reveals who the administrators are, only how many.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    echo json_encode([
        'adminAccounts' => $admins,
        'available' => $admins === 0,
        'message' => $admins === 0
            ? 'No administrator exists. POST email and password to this URL to create one.'
            : 'An administrator already exists, so this recovery endpoint is disabled. Delete this file.',
    ]);
    exit;
}

if ($admins > 0) {
    http_response_code(403);
    echo json_encode([
        'error' => 'An administrator already exists, so recovery is not available.',
        'hint' => 'Sign in with that account, or remove this file and use setup_key instead.',
    ]);
    exit;
}

$raw = file_get_contents('php://input');
$data = json_decode((string)$raw, true);
if (!is_array($data)) $data = $_POST;

$email = strtolower(trim((string)($data['email'] ?? '')));
$password = (string)($data['password'] ?? '');

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['error' => 'A valid email address is required.']);
    exit;
}
// Same rule the portal's own setup route applies, so a recovered account is no weaker than
// one created any other way.
if (!preg_match('/^(?=.*[A-Z])(?=.*[a-z])(?=.*\d).{12,64}$/', $password)) {
    http_response_code(400);
    echo json_encode(['error' => 'Password must be 12-64 characters and include an uppercase letter, a lowercase letter and a digit.']);
    exit;
}

try {
    // Creates, or promotes and resets the password if the address is already a member -
    // which is what you want if the account was deleted and re-registered in between.
    $pdo->prepare(
        'INSERT INTO users (email, password_hash, email_verified, role)
         VALUES (?, ?, 1, "admin")
         ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash), email_verified = 1, role = "admin"'
    )->execute([$email, password_hash($password, PASSWORD_DEFAULT)]);
} catch (Throwable $e) {
    http_response_code(500);
    error_log('bootstrap-admin: could not create the account: ' . $e->getMessage());
    echo json_encode(['error' => 'Could not create the account.']);
    exit;
}

error_log('bootstrap-admin: administrator recreated for ' . $email);
echo json_encode([
    'message' => 'Administrator restored. Sign in, then delete this file.',
    'email' => $email,
    'superAdmin' => in_array($email, array_map('strtolower', (array)($config['super_admins'] ?? [])), true)
        ? 'This address is listed in super_admins, so it is a super administrator.'
        : 'This address is not in super_admins - add it there for threat assessment access.',
]);
