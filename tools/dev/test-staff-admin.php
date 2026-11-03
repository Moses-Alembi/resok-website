<?php
declare(strict_types=1);

/** Synthetic staff administration checks on a disposable MariaDB instance, port 33317.
 * Never loads config.php or connects to the normal portal database.
 */
date_default_timezone_set('Africa/Nairobi');
$lib = __DIR__ . '/../../resok-portal/public/api/lib/';
foreach (['migrate', 'crypto', 'staff', 'staff-portal', 'staff-field', 'staff-timesheet', 'staff-procurement', 'staff-payroll', 'staff-appraisal', 'staff-geo', 'staff-leave'] as $module) require_once $lib . $module . '.php';

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

if (($argv[1] ?? '') === '--leave-worker') {
    $pdo = adminConnect($argv[2]); echo "ready\n"; flush();
    try {
        $id = staffLeaveSubmit($pdo,(int)$argv[3],['typeId'=>(int)$argv[4],'startsOn'=>$argv[5],'endsOn'=>$argv[6]]);
        echo json_encode(['id'=>$id,'error'=>null]);
    } catch (InvalidArgumentException $error) { echo json_encode(['error'=>$error->getMessage()]); }
    exit;
}
function adminLeaveWorker(string $database, int $user, int $type, string $start, string $end): array
{
    $pipes = [];
    $process = proc_open([PHP_BINARY,__FILE__,'--leave-worker',$database,(string)$user,(string)$type,$start,$end],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if (!is_resource($process)) throw new RuntimeException('Could not start a concurrent request');
    fclose($pipes[0]);
    if (trim((string)fgets($pipes[1])) !== 'ready') throw new RuntimeException('Leave worker did not start');
    return [$process,$pipes];
}
function adminFinishLeaveWorker(array $worker): array
{
    $out = stream_get_contents($worker[1][1]); $error = stream_get_contents($worker[1][2]);
    fclose($worker[1][1]); fclose($worker[1][2]);
    if (proc_close($worker[0]) !== 0) throw new RuntimeException('Leave worker failed: ' . $error);
    return json_decode($out,true,512,JSON_THROW_ON_ERROR);
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
    foreach (['staff/home','staff/directory','staff/profile','staff/timesheets','staff/field','staff/procurement','staff/appraisals','staff/payroll/mine','staff/location','staff/leave'] as $route) {
        adminCheck(adminCall($pdo,$config,$employee,$route)->status === 200, 'ordinary staff can use ' . $route);
    }
    $leave = adminCall($pdo,$config,$employee,'staff/leave');
    adminCheck($leave->body['types'] === [] && !$leave->body['canManage'], 'no leave entitlement or type is invented for employees');
    adminCheck(adminCall($pdo,$config,$employee,'staff/leave/types','POST',['name'=>'Forbidden'])->status === 403, 'employees cannot configure leave types');
    $typeResponse = adminCall($pdo,$config,$super,'staff/leave/types','POST',['name'=>'Synthetic Leave','description'=>'Configured for the test only']);
    adminCheck($typeResponse->status === 200, 'super administrator configures leave types');
    $typeId = (int)$typeResponse->body['types'][0]['id'];
    adminCheck(adminCall($pdo,$config,$super,'staff/leave/types','POST',['name'=>'Synthetic Leave'])->status === 400, 'duplicate leave types are rejected');
    $dates = ['typeId'=>$typeId,'startsOn'=>date('Y-m-d',strtotime('+7 days')),'endsOn'=>date('Y-m-d',strtotime('+9 days')),'reason'=>'Private employee note'];
    adminCheck(adminCall($pdo,$config,$super,'staff/leave','POST',$dates)->status === 403, 'a super administrator without an employee record cannot invent personal leave');
    adminCheck(adminCall($pdo,$config,$employee,'staff/leave','POST',array_merge($dates,['startsOn'=>'2026-02-31']))->status === 400, 'invalid calendar dates are rejected');
    adminCheck(adminCall($pdo,$config,$employee,'staff/leave','POST',array_merge($dates,['startsOn'=>"2026-11-10\0"]))->status === 400, 'malformed dates are rejected without a server error');
    adminCheck(adminCall($pdo,$config,$employee,'staff/leave','POST',array_merge($dates,['endsOn'=>date('Y-m-d',strtotime('+6 days'))]))->status === 400, 'reversed leave dates are rejected');
    adminCheck(adminCall($pdo,$config,$employee,'staff/leave','POST',array_merge($dates,['startsOn'=>date('Y-m-d',strtotime('-1 day'))]))->status === 400, 'past start dates are rejected');
    $submitted = adminCall($pdo,$config,$employee,'staff/leave','POST',$dates);
    adminCheck($submitted->status === 201, 'an employee submits a leave request'); $leaveId = (int)$submitted->body['id'];
    adminCheck(adminCall($pdo,$config,$employee,'staff/leave','POST',$dates)->status === 400, 'overlapping requests are rejected');
    $other = $users['listed-member@staff-admin.test'];
    $private = adminCall($pdo,$config,$other,'staff/leave');
    adminCheck(!$private->body['mine'] && !$private->body['requests'] && !$private->body['pending'] && $private->body['pendingCount'] === 0 && !str_contains(json_encode($private->body),'Private employee note'), 'another employee cannot read someone else\'s leave or notes');
    adminCheck(adminCall($pdo,$config,$other,'staff/leave/' . $leaveId . '/cancel','POST')->status === 404, 'another employee cannot cancel the request by guessing its ID');
    adminCheck(adminCall($pdo,$config,$employee,'staff/leave/' . $leaveId . '/approve','POST')->status === 403, 'an employee cannot approve leave');
    adminCheck(adminCall($pdo,$config,$employee,'staff/leave/' . $leaveId . '/decline','POST',['note'=>'No'])->status === 403, 'an employee cannot decline leave');
    adminCheck(count(adminCall($pdo,$config,$super,'staff/leave')->body['requests']) === 1, 'the super administrator receives the leave approval queue');
    adminCheck(adminCall($pdo,$config,$super,'staff/leave/' . $leaveId . '/approve','POST')->status === 200, 'the super administrator approves a request');
    adminCheck(adminCall($pdo,$config,$employee,'staff/leave')->body['mine'][0]['status'] === 'approved', 'the employee sees the approval');
    adminCheck(adminCall($pdo,$config,$super,'staff/leave/' . $leaveId . '/approve','POST')->status === 400, 'a decided request cannot be approved twice');
    adminCheck(adminCall($pdo,$config,$employee,'staff/leave/' . $leaveId . '/cancel','POST')->status === 200, 'the employee cancels future approved leave');
    adminCheck((int)$pdo->query('SELECT decided_by FROM staff_leave_requests WHERE id=' . $leaveId)->fetchColumn() === (int)$super['userId'], 'cancellation preserves who approved the leave');
    $secondRequest = adminCall($pdo,$config,$employee,'staff/leave','POST',$dates); $secondId = (int)$secondRequest->body['id'];
    adminCheck($secondRequest->status === 201, 'cancelled leave does not block a replacement request');
    adminCheck(adminCall($pdo,$config,$super,'staff/leave/' . $secondId . '/decline','POST')->status === 400, 'declining leave requires a reason');
    adminCheck(adminCall($pdo,$config,$super,'staff/leave/' . $secondId . '/decline','POST',['note'=>'Please choose different dates.'])->status === 200, 'the super administrator declines leave with a reason');
    adminCheck(adminCall($pdo,$config,$employee,'staff/leave')->body['mine'][0]['decisionNote'] === 'Please choose different dates.', 'the employee sees the decline reason');
    $third = adminCall($pdo,$config,$employee,'staff/leave','POST',$dates); $thirdId = (int)$third->body['id'];
    adminCheck(adminCall($pdo,$config,$super,'staff/leave/' . $thirdId . '/cancel','POST')->status === 400, 'an administrative cancellation requires a reason');
    adminCheck(adminCall($pdo,$config,$super,'staff/leave/' . $thirdId . '/cancel','POST',['note'=>'Duplicate planned dates'])->status === 200, 'the super administrator can cancel with a recorded reason');
    $firstWorker = adminLeaveWorker($database,(int)$employee['userId'],$typeId,$dates['startsOn'],$dates['endsOn']);
    $secondWorker = adminLeaveWorker($database,(int)$employee['userId'],$typeId,$dates['startsOn'],$dates['endsOn']);
    $firstResult = adminFinishLeaveWorker($firstWorker); $secondResult = adminFinishLeaveWorker($secondWorker);
    adminCheck(($firstResult['error'] === null) !== ($secondResult['error'] === null), 'two concurrent overlapping submissions create exactly one leave request');
    $inactive = adminCall($pdo,$config,$super,'staff/leave/types','POST',['id'=>$typeId,'name'=>'Renamed Leave','inactive'=>true]);
    adminCheck($inactive->status === 200 && !adminCall($pdo,$config,$employee,'staff/leave')->body['types'], 'inactive leave types disappear from new employee requests');
    adminCheck(adminCall($pdo,$config,$employee,'staff/leave','POST',array_merge($dates,['startsOn'=>date('Y-m-d',strtotime('+20 days')),'endsOn'=>date('Y-m-d',strtotime('+21 days'))]))->status === 400, 'inactive leave types cannot be submitted directly');
    adminCheck($pdo->query('SELECT type_name FROM staff_leave_requests WHERE id=' . $leaveId)->fetchColumn() === 'Synthetic Leave', 'renaming a type preserves the original leave request history');
    $history = $pdo->prepare("INSERT INTO staff_leave_requests(user_id,type_id,type_name,starts_on,ends_on,status) VALUES(?,?,'Synthetic history','2026-01-01','2026-01-01','cancelled')");
    for ($n=0;$n<301;$n++) $history->execute([$employee['userId'],$typeId]);
    $queue = adminCall($pdo,$config,$super,'staff/leave')->body;
    adminCheck($queue['pendingCount'] === 1 && count($queue['pending']) === 1 && count($queue['requests']) === 300, 'old pending leave stays in the decision queue when recent history reaches its limit');
    adminCheck((int)$pdo->query("SELECT COUNT(*) FROM staff_notifications WHERE kind='leave'")->fetchColumn() >= 3, 'leave submissions and decisions create portal notifications');
    adminCheck((int)$pdo->query('SELECT COUNT(*) FROM staff_audit')->fetchColumn() >= 4, 'management changes remain visible in the activity log');
    echo "\n" . $GLOBALS['adminPasses'] . " staff administration checks passed against a disposable database.\n";
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $pdo = null; $server->exec('DROP DATABASE ' . $database);
}
