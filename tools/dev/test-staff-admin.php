<?php
declare(strict_types=1);

/** Synthetic staff administration checks on a disposable MariaDB instance, port 33317.
 * Never loads config.php or connects to the normal portal database.
 */
date_default_timezone_set('Africa/Nairobi');
$lib = __DIR__ . '/../../resok-portal/public/api/lib/';
foreach (['migrate', 'crypto', 'staff', 'staff-portal', 'staff-field', 'staff-timesheet', 'staff-procurement', 'staff-payroll', 'staff-appraisal', 'staff-geo'] as $module) require_once $lib . $module . '.php';

class StaffAdminResponse extends Exception
{
    public function __construct(public int $status, public array $body) { parent::__construct(); }
}
function respond(int $status, array $body): void { throw new StaffAdminResponse($status, $body); }
function input(): array { return $GLOBALS['adminInput'] ?? []; }
function isSuperAdmin(array $user, array $config): bool
{
    return ($user['role'] ?? '') === 'admin' && in_array(strtolower($user['email'] ?? ''), $config['super_admins'] ?? [], true);
}
function requireModule(string $function, string $path): void
{
    if (!function_exists($function)) throw new RuntimeException('Missing test module: ' . $path);
}
function sessionRevokeAll(PDO $pdo, int $userId): void { $GLOBALS['adminRevoked'][] = $userId; }
function adminCheck(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException('FAIL: ' . $message);
    $GLOBALS['adminPasses'] = ($GLOBALS['adminPasses'] ?? 0) + 1;
    echo 'ok: ' . $message . "\n";
}
function adminCall(PDO $pdo, array $config, array $user, string $route, string $method = 'GET', array $body = [], array $query = []): StaffAdminResponse
{
    $GLOBALS['adminInput'] = $body; $_GET = $query;
    try { staffPortalHandle($pdo, $config, $route, $method, $user); }
    catch (StaffAdminResponse $response) { return $response; }
    throw new RuntimeException('No response for ' . $route);
}
function adminConnect(?string $database = null): PDO
{
    if ($database !== null && !preg_match('/^resok_staff_admin_test_[a-f0-9]+$/', $database)) throw new RuntimeException('Unsafe test database');
    $pdo = new PDO('mysql:host=127.0.0.1;port=33317;' . ($database ? 'dbname=' . $database . ';' : '') . 'charset=utf8mb4', 'root', 'LocalWelfareTestsOnly!', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $pdo->exec("SET time_zone='+03:00'");
    return $pdo;
}

$server = adminConnect(); $database = 'resok_staff_admin_test_' . bin2hex(random_bytes(5));
$server->exec('CREATE DATABASE ' . $database); $pdo = adminConnect($database);
try {
    foreach (migrateSplit(file_get_contents(__DIR__ . '/../../resok-portal/server/schema.sql')) as $sql) $pdo->exec($sql);
    adminCheck(staffPortalEnsureTables($pdo), 'staff schema initializes in the disposable database');
    $pdo->exec("INSERT INTO users(email,password_hash,email_verified,role) VALUES
        ('super@staff-admin.test','unused',1,'admin'),('second@staff-admin.test','unused',1,'admin'),
        ('employee@staff-admin.test','unused',1,'member'),('ordinary-admin@staff-admin.test','unused',1,'admin'),
        ('listed-member@staff-admin.test','unused',1,'member')");
    $users = [];
    foreach ($pdo->query('SELECT id AS userId,email,role FROM users')->fetchAll() as $user) $users[$user['email']] = $user;
    $super = $users['super@staff-admin.test']; $second = $users['second@staff-admin.test']; $employee = $users['employee@staff-admin.test'];
    $config = ['super_admins' => ['super@staff-admin.test', 'second@staff-admin.test', 'listed-member@staff-admin.test']];
    $add = $pdo->prepare('INSERT INTO staff_accounts(user_id,full_name) VALUES(?,?)');
    foreach ([$second, $employee, $users['ordinary-admin@staff-admin.test'], $users['listed-member@staff-admin.test']] as $user) $add->execute([$user['userId'], $user['email']]);
    $gate = staffGate($pdo, $super, $config);
    adminCheck($gate['super'] && $gate['row'] === null, 'a verified super administrator manages staff without an employee record');
    adminCheck($gate['caps'] === array_keys(STAFF_CAPABILITIES), 'super administrators automatically hold every staff capability');
    staffNeed(['super' => true, 'caps' => []], 'staff.manage');
    adminCheck(true, 'super-admin authority does not depend on delegated staff grants');
    $home = adminCall($pdo, $config, $super, 'staff/home');
    adminCheck($home->status === 200 && $home->body['super'] && !$home->body['onStaffList'], 'the home page reports full access without inventing an employee record');
    foreach (['ordinary-admin@staff-admin.test', 'listed-member@staff-admin.test', 'employee@staff-admin.test'] as $email) {
        $otherGate = staffGate($pdo, $users[$email], $config);
        adminCheck(!$otherGate['super'] && !$otherGate['caps'], $email . ' does not inherit super-admin rights');
        adminCheck(adminCall($pdo, $config, $users[$email], 'staff/permissions')->status === 403, $email . ' cannot change staff permissions');
    }
    $permissions = adminCall($pdo, $config, $super, 'staff/permissions');
    $rows = array_column($permissions->body['people'], null, 'email');
    adminCheck($rows[$second['email']]['super'] && $rows[$second['email']]['caps'] === array_keys(STAFF_CAPABILITIES), 'the Permissions screen receives effective full access for another super administrator');
    adminCheck(!$rows[$employee['email']]['super'] && !$rows[$employee['email']]['caps'], 'ordinary staff still receive only their delegated grants');
    adminCheck(adminCall($pdo, $config, $super, 'staff/permissions/' . $second['userId'], 'POST', ['caps' => []])->status === 400, 'staff grants cannot be used to reduce another super administrator');
    adminCheck((int)$pdo->query('SELECT COUNT(*) FROM staff_grants')->fetchColumn() === 0, 'reviewing automatic access does not assign HR or other staff roles');

    $department = adminCall($pdo, $config, $super, 'staff/departments', 'POST', ['name' => 'Synthetic Operations']);
    adminCheck($department->status === 200, 'super administrator creates a department');
    $departmentId = $department->body['departments'][0]['id'];
    $record = adminCall($pdo, $config, $super, 'staff/people/' . $employee['userId'], 'POST', ['name' => 'Synthetic Employee', 'staffNumber' => 'ADMIN-1', 'jobTitle' => 'Programme Officer', 'departmentId' => $departmentId]);
    adminCheck($record->status === 200 && $record->body['person']['jobTitle'] === 'Programme Officer', 'super administrator changes another employee record');
    adminCheck(adminCall($pdo, $config, $employee, 'staff/departments', 'POST', ['name' => 'Forbidden'])->status === 403, 'ordinary staff cannot change department settings');
    foreach (['staff/people', 'staff/audit', 'staff/timesheets/approvals', 'staff/timesheets/report', 'staff/payroll', 'staff/payroll/records', 'staff/procurement/suppliers', 'staff/appraisals/cycles', 'staff/location'] as $route) {
        adminCheck(adminCall($pdo, $config, $super, $route)->status === 200, 'super administrator opens ' . $route);
    }
    adminCheck(adminCall($pdo, $config, $super, 'staff/procurement', 'GET', [], ['scope' => 'all'])->status === 200, 'super administrator opens all purchase requests');
    adminCheck(adminCall($pdo, $config, $super, 'staff/timesheets/codes', 'POST', ['code' => 'ADMIN-TEST', 'name' => 'Synthetic project', 'kind' => 'project'])->status === 200, 'super administrator maintains project charge codes');
    adminCheck(adminCall($pdo, $config, $super, 'staff/procurement/suppliers', 'POST', ['name' => 'Synthetic supplier', 'email' => 'supplier@staff-admin.test'])->status === 200, 'super administrator maintains suppliers');
    $cycle = adminCall($pdo, $config, $super, 'staff/appraisals/cycles', 'POST', ['name' => 'Synthetic cycle', 'periodStart' => '2026-01-01', 'periodEnd' => '2026-12-31', 'objectivesDue' => '2026-02-01', 'reviewDue' => '2026-12-01']);
    adminCheck($cycle->status === 200, 'super administrator maintains appraisal cycles');
    foreach (['staff/payroll', 'staff/procurement/suppliers', 'staff/appraisals/cycles', 'staff/audit'] as $route) {
        adminCheck(adminCall($pdo, $config, $employee, $route)->status === 403, 'ordinary staff cannot administer ' . $route);
    }
    adminCheck((int)$pdo->query('SELECT COUNT(*) FROM staff_audit')->fetchColumn() >= 4, 'management changes remain visible in the activity log');
    echo "\n" . $GLOBALS['adminPasses'] . " staff administration checks passed against a disposable database.\n";
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $pdo = null; $server->exec('DROP DATABASE ' . $database);
}
