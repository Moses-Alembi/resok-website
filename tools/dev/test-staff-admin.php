<?php
declare(strict_types=1);

/** Synthetic staff administration checks on a disposable MariaDB instance, port 33317.
 * Never loads config.php or connects to the normal portal database.
 */
date_default_timezone_set('Africa/Nairobi');
$lib = __DIR__ . '/../../resok-portal/public/api/lib/';
foreach (['migrate', 'ict', 'crypto', 'staff', 'staff-portal', 'staff-field', 'staff-timesheet', 'staff-procurement', 'staff-payroll', 'staff-appraisal', 'staff-geo', 'staff-leave', 'staff-hr-import'] as $module) require_once $lib . $module . '.php';

class StaffAdminResponse extends Exception
{
    public function __construct(public int $status, public array $body) { parent::__construct(); }
}
function respond(int $status, array $body): void { throw new StaffAdminResponse($status, $body); }
function input(): array { return $GLOBALS['adminInput'] ?? []; }
// Exercise the production website-role gates without running its HTTP dispatcher.
$indexSource = file_get_contents(__DIR__ . '/../../resok-portal/public/api/index.php');
$gateStart = strpos($indexSource, 'function requireAdmin(');
$gateEnd = strpos($indexSource, 'function requireFields(', $gateStart);
if ($gateStart === false || $gateEnd === false) throw new RuntimeException('Website authorization helpers not found');
eval(substr($indexSource, $gateStart, $gateEnd-$gateStart));
function auth(array $config): array { return $GLOBALS['adminAccountActor']; }
function logAdminAction(PDO $pdo, int $actor, string $action, ?int $profile = null, ?string $reason = null): void {
    $pdo->prepare('INSERT INTO admin_actions(admin_user_id,action,reason,created_at) VALUES(?,?,?,NOW())')->execute([$actor,$action,$reason]);
}
function adminAccountCall(PDO $pdo, array $config, array $user, string $route, string $method='GET', array $body=[]): StaffAdminResponse {
    $GLOBALS['adminAccountActor']=$user; $GLOBALS['adminInput']=$body; $_GET=[];
    try { staffHandle($pdo,$config,$route,$method); }
    catch (StaffAdminResponse $response) { return $response; }
    throw new RuntimeException('No account response');
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
    $previewNotice = adminCall($pdo,$config,$super,'staff/announcements','POST',['title'=>'Synthetic preview notice','body'=>'An announcement visible to staff.','requiresAck'=>true]);
    $previewNoticeId = (int)$previewNotice->body['id'];
    adminCheck(adminCall($pdo,$config,$super,'staff/announcements/' . $previewNoticeId . '/publish','POST')->status === 200, 'a published notice is available for the staff-preview regression');
    $previewEmployee = array_merge($employee,['viewAs'=>['actorId'=>$super['userId'],'scope'=>'staff']]);
    $previewRead = adminCall($pdo,$config,$previewEmployee,'staff/announcements/' . $previewNoticeId);
    adminCheck($previewRead->status === 200 && $previewRead->body['announcement']['readAt'] === null, 'viewing a notice as staff preserves its unread state');
    adminCheck((int)$pdo->query('SELECT COUNT(*) FROM staff_announcement_reads WHERE announcement_id=' . $previewNoticeId)->fetchColumn() === 0, 'staff preview does not insert an employee announcement-read record');
    $previewNotification = $pdo->prepare('SELECT read_at FROM staff_notifications WHERE user_id=? AND dedupe_key=?');
    $previewNotification->execute([$employee['userId'],'announcement:' . $previewNoticeId]);
    adminCheck($previewNotification->fetchColumn() === null, 'staff preview leaves the matching notification unread');
    $realRead = adminCall($pdo,$config,$employee,'staff/announcements/' . $previewNoticeId);
    adminCheck($realRead->status === 200 && $realRead->body['announcement']['readAt'] !== null, 'the employee opening their own notice still records the read');
    $previewNotification->execute([$employee['userId'],'announcement:' . $previewNoticeId]);
    adminCheck($previewNotification->fetchColumn() !== null, 'the employee opening their own notice still marks their notification read');
    // A photographed SN is not an employee number. Title-only updates must preserve access.
    $source = "1\tSynthetic Employee\tField Officer - Meru\n24\tMissing Colleague\tProject Driver";
    $pdo->prepare("UPDATE staff_accounts SET full_name='Other Colleague',staff_number='1' WHERE user_id=?")->execute([$other['userId']]);
    adminCheck(adminCall($pdo,$config,$other,'staff/people/import','POST',['text'=>$source,'titlesOnly'=>true])->status === 403, 'ordinary staff cannot import titles');
    $pdo->prepare("INSERT INTO staff_accounts(user_id,full_name,staff_number,job_title) VALUES(?,'Moses Alembi','OWNER-1','Original owner title')")->execute([$super['userId']]);
    $preview = adminCall($pdo,$config,$super,'staff/people/import','POST',['text'=>$source,'titlesOnly'=>true]);
    adminCheck($preview->status === 200 && $preview->body['titlesOnly'] && $preview->body['rows'][0]['userId'] === (int)$employee['userId'], 'title-only matching uses the name, not a conflicting printed serial number');
    adminCheck($preview->body['rows'][1]['userId'] === null, 'a weak one-word overlap is not preselected as an account match');
    $fullPreview = adminCall($pdo,$config,$super,'staff/people/import','POST',['text'=>$source]);
    adminCheck($fullPreview->body['rows'][0]['userId'] === (int)$other['userId'], 'the existing full HR import still recognises real employee numbers');
    $pdo->prepare("UPDATE staff_accounts SET work_counties='Nairobi',active=0 WHERE user_id=?")->execute([$employee['userId']]);
    $before = staffRecord($pdo,(int)$employee['userId']);
    $countBefore = (int)$pdo->query('SELECT COUNT(*) FROM staff_accounts')->fetchColumn();
    $applied = adminCall($pdo,$config,$super,'staff/people/import','POST',['text'=>$source,'titlesOnly'=>true,'apply'=>true,'choices'=>[0=>['userId'=>$employee['userId']],1=>['userId'=>null]]]);
    $after = staffRecord($pdo,(int)$employee['userId']);
    adminCheck($applied->status === 200 && $applied->body['applied'] === 1 && $after['jobTitle'] === 'Field Officer - Meru', 'a checked title-only import updates the matched job title');
    foreach (['name','staffNumber','workCounties','active','departmentId','managerUserId','accountReady'] as $field) adminCheck($before[$field] === $after[$field], 'title-only import preserves ' . $field);
    adminCheck(staffRecord($pdo,(int)$super['userId'])['jobTitle'] === 'Original owner title', 'omitting Moses Alembi leaves his account unchanged');
    adminCheck((int)$pdo->query('SELECT COUNT(*) FROM staff_accounts')->fetchColumn() === $countBefore, 'title imports do not create staff records or expand the voter population');
    $accounts = array_column(staffList($pdo),null,'userId');
    adminCheck($accounts[(int)$employee['userId']]['jobTitle'] === 'Field Officer - Meru', 'the account list API includes job titles for display below names');
    adminCheck((int)$pdo->query('SELECT COUNT(*) FROM staff_audit')->fetchColumn() >= 4, 'management changes remain visible in the activity log');
    $onlyStaffLog = adminCall($pdo,$config,$super,'staff/audit');
    adminCheck(in_array('staff',$onlyStaffLog->body['sources'],true),'the staff log works before optional audit modules have tables');
    $peerPreview = array_merge($second,['viewAs'=>['actorId'=>$super['userId'],'scope'=>'staff']]);
    $peerReport = adminCall($pdo,$config,$peerPreview,'staff/field/report','GET',[],['userId'=>$super['userId'],'period'=>'day','date'=>'2026-11-03']);
    adminCheck($peerReport->status === 200,'a peer preview can read its permitted staff management report');
    $reportActor = (int)$pdo->query("SELECT actor_user_id FROM staff_audit WHERE action='field_report_viewed' ORDER BY id DESC LIMIT 1")->fetchColumn();
    adminCheck($reportActor === (int)$super['userId'],'reading during preview is logged under the real operator rather than the person being observed');
    $pdo->exec('CREATE TABLE IF NOT EXISTS ict_audit(id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,actor_user_id INT UNSIGNED,action VARCHAR(60),summary VARCHAR(300),before_json TEXT,after_json TEXT,created_at DATETIME)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS admin_actions(id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,admin_user_id INT UNSIGNED,action VARCHAR(60),reason TEXT,created_at DATETIME)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS security_events(id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,user_id INT UNSIGNED,event_type VARCHAR(40),detail VARCHAR(300),client_hash CHAR(64),created_at DATETIME)');
    staffAudit($pdo,(int)$second['userId'],'synthetic_staff_action','staff',null,'Recorded staff work');
    $pdo->prepare("INSERT INTO ict_audit(actor_user_id,action,summary,before_json,after_json,created_at) VALUES(?,'synthetic_ict_action','Recorded ICT work','DO_NOT_EXPOSE_OLD','DO_NOT_EXPOSE_NEW',NOW())")->execute([$second['userId']]);
    $pdo->prepare("INSERT INTO admin_actions(admin_user_id,action,reason,created_at) VALUES(?,'synthetic_admin_action','Recorded administrator work',NOW())")->execute([$second['userId']]);
    $pdo->prepare("INSERT INTO security_events(user_id,event_type,detail,client_hash,created_at) VALUES(?,'synthetic_login','Recorded sign-in',REPEAT('a',64),NOW())")->execute([$second['userId']]);
    $filtered = adminCall($pdo,$config,$super,'staff/audit','GET',[],['actorUserId'=>$second['userId']]);
    adminCheck($filtered->status === 200 && $filtered->body['actor']['userId'] === (int)$second['userId'],'a super administrator can select another super administrator activity');
    adminCheck(count($filtered->body['entries']) === 4 && count(array_unique(array_column($filtered->body['entries'],'source'))) === 4,'the per-person view combines staff ICT admin and security actions');
    adminCheck(array_unique(array_column($filtered->body['entries'],'actorUserId')) === [(int)$second['userId']],'filtering never mixes another person actions into the selected history');
    $encoded = json_encode($filtered->body);
    adminCheck(!str_contains($encoded,'DO_NOT_EXPOSE') && !str_contains($encoded,'client_hash') && !str_contains($encoded,'before_json'),'activity responses exclude raw change payloads and client fingerprints');
    adminCheck(adminCall($pdo,$config,$super,'staff/audit','GET',[],['actorUserId'=>'not-an-id'])->status === 400,'invalid activity filters are refused');
    adminCheck(adminCall($pdo,$config,$super,'staff/audit','GET',[],['actorUserId'=>999999999])->status === 404,'unknown activity accounts are reported clearly');
    adminCheck(adminCall($pdo,$config,$second,'staff/audit','GET',[],['actorUserId'=>$super['userId']])->status === 200,'other verified super administrators have the same recorded-activity access');
    adminCheck(adminCall($pdo,$config,$other,'staff/audit','GET',[],['actorUserId'=>$second['userId']])->status === 403,'ordinary staff cannot use a selected-person filter to read audit history');
    $bulkAudit = $pdo->prepare("INSERT INTO staff_audit(actor_user_id,action,summary,created_at) VALUES(?,'synthetic_recent_action','Bounded history check','2099-01-01 00:00:00')");
    for ($i=0;$i<205;$i++) $bulkAudit->execute([$second['userId']]);
    $bounded = adminCall($pdo,$config,$super,'staff/audit','GET',[],['actorUserId'=>$second['userId']])->body;
    adminCheck(count($bounded['entries']) === 200 && $bounded['hasMore'] && $bounded['entries'][0]['at'] === '2099-01-01 00:00:00','activity is ordered by time and reports its latest-200 limit');
    // Staff Admin / HR has full staff workflows without becoming a website admin.
    $pdo->exec("INSERT INTO users(email,password_hash,email_verified,role) VALUES('hr@staff-admin.test','unused',1,'member')");
    $hrId = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO staff_accounts(user_id,full_name,job_title) VALUES(?,?,?)')->execute([$hrId,'Synthetic HR','Finance & Admin Manager']);
    $pdo->prepare("INSERT INTO staff_grants(user_id,capability) VALUES(?,'staff.admin')")->execute([$hrId]);
    $hr = ['userId'=>$hrId,'email'=>'hr@staff-admin.test','role'=>'member'];
    $hrGate = staffGate($pdo,$hr,$config);
    adminCheck($hrGate['admin'] && !$hrGate['super'] && !isSuperAdmin($hr,$config), 'HR authority is scoped to staff and never creates website super-admin access');
    adminCheck($hrGate['caps'] === array_keys(STAFF_CAPABILITIES), 'the single staff-admin grant expands to every staff capability');
    $hrHome = adminCall($pdo,$config,$hr,'staff/home')->body;
    adminCheck($hrHome['admin'] && !$hrHome['super'] && $hrHome['me']['jobTitle'] === 'Finance & Admin Manager', 'HR home identifies the scoped role and saved title');
    foreach (['staff/people','staff/announcements','staff/timesheets/approvals','staff/timesheets/report','staff/payroll','staff/payroll/records','staff/procurement/suppliers','staff/appraisals/cycles','staff/leave'] as $route) {
        adminCheck(adminCall($pdo,$config,$hr,$route)->status === 200, 'HR opens ' . $route);
    }
    adminCheck(adminCall($pdo,$config,$hr,'staff/departments','POST',['name'=>'HR department'])->status === 200, 'HR manages departments');
    adminCheck(adminCall($pdo,$config,$hr,'staff/leave/types','POST',['name'=>'HR configured leave'])->status === 200, 'HR configures leave types');
    adminCheck(adminCall($pdo,$config,$hr,'staff/leave')->body['canManage'], 'HR can review and decide staff leave requests');
    adminCheck(adminCall($pdo,$config,$hr,'staff/timesheets/codes','POST',['code'=>'HR-ONLY','name'=>'HR project','kind'=>'project'])->status === 200, 'HR maintains staff charge codes');
    foreach (['GET','POST'] as $method) adminCheck(adminCall($pdo,$config,$hr,$method === 'GET' ? 'staff/permissions' : 'staff/permissions/' . $employee['userId'],$method,['caps'=>['staff.admin']])->status === 403, 'HR cannot delegate permissions: ' . $method);
    adminCheck(adminCall($pdo,$config,$hr,'staff/people/' . $second['userId'] . '/active','POST',['active'=>false])->status === 403, 'HR cannot deactivate a website super administrator');
    $hrAudit = adminCall($pdo,$config,$hr,'staff/audit')->body;
    adminCheck($hrAudit['sources'] === ['staff'] && array_unique(array_column($hrAudit['entries'],'source')) === ['staff'], 'HR audit responses contain only staff activity, never website ICT or security logs');
    $location = adminCall($pdo,$config,$hr,'staff/location')->body;
    adminCheck(!$location['isSuper'] && $location['cloudflare'] === null, 'HR does not receive global location security settings or infrastructure diagnostics');
    adminCheck(adminCall($pdo,$config,$hr,'staff/location/settings','POST',[])->status === 403, 'HR cannot edit global location security rules');
    try { requireAdmin($hr); adminCheck(false,'HR cannot enter website admin routes'); }
    catch (StaffAdminResponse $response) { adminCheck($response->status === 403,'the production website admin gate rejects HR'); }
    try { requireSuperAdmin($hr,$config); adminCheck(false,'HR cannot enter global super-admin routes'); }
    catch (StaffAdminResponse $response) { adminCheck($response->status === 403,'the production global super-admin gate rejects HR'); }
    foreach (['credentials.view','assets.manage','tickets.manage','roles.manage'] as $cap) adminCheck(!ictCan($pdo,$hr,$config,$cap),'HR does not inherit ICT capability ' . $cap);
    adminCheck(adminAccountCall($pdo,$config,$hr,'staff/admin')->status === 200,'HR can list staff account invitations inside the staff portal');
    $addedAccount = adminAccountCall($pdo,$config,$hr,'staff/admin/add','POST',['list'=>'HR Created Employee <new@staff-admin.test>']);
    adminCheck($addedAccount->status === 200,'HR can add staff accounts');
    $newId = (int)$pdo->query("SELECT id FROM users WHERE email='new@staff-admin.test'")->fetchColumn();
    adminCheck($pdo->query('SELECT role FROM users WHERE id=' . $newId)->fetchColumn() === 'member' && !staffIsAdmin($pdo,['userId'=>$newId,'email'=>'new@staff-admin.test','role'=>'member'],$config),'HR-created accounts receive no administrative privileges');
    adminCheck(adminAccountCall($pdo,$config,$hr,'staff/admin/' . $second['userId'] . '/remove','POST')->status === 403,'HR cannot remove a website super administrator');
    adminCheck(adminAccountCall($pdo,$config,$employee,'staff/admin')->status === 403,'ordinary staff cannot read account administration');
    adminCheck(adminAccountCall($pdo,$config,$hr,'staff/admin/holders')->status === 403,'HR account tools cannot open ICT asset linking');
    adminCheck(in_array($hrId,timesheetDeciders($pdo,$newId),true),'a staff-admin-only grant receives unassigned HR approval notifications');
    $pdo->prepare('UPDATE staff_accounts SET active=0 WHERE user_id=?')->execute([$hrId]);
    adminCheck(!staffIsAdmin($pdo,$hr,$config) && adminCall($pdo,$config,$hr,'staff/home')->status === 403, 'deactivation immediately revokes scoped HR access');
    $pdo->prepare('UPDATE staff_accounts SET active=1 WHERE user_id=?')->execute([$hrId]);
    $pdo->prepare('DELETE FROM staff_grants WHERE user_id=?')->execute([$hrId]);
    adminCheck(!staffGate($pdo,$hr,$config)['admin'], 'removing the staff-admin grant revokes scoped authority immediately');
    echo "\n" . $GLOBALS['adminPasses'] . " staff administration checks passed against a disposable database.\n";
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $pdo = null; $server->exec('DROP DATABASE ' . $database);
}
