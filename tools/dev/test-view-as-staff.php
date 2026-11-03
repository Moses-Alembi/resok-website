<?php
declare(strict_types=1);

/** Synthetic view-as checks on disposable MariaDB 33317; never loads config.php. */
ob_start();
date_default_timezone_set('Africa/Nairobi');
$lib = __DIR__ . '/../../resok-portal/public/api/lib/';
foreach (['migrate', 'staff', 'staff-portal', 'view-as'] as $module) require_once $lib . $module . '.php';

class ViewAsTestResponse extends Exception
{
    public function __construct(public int $status, public array $body) { parent::__construct(); }
}
function respond(int $status, array $body): void { throw new ViewAsTestResponse($status, $body); }
function input(): array { return $GLOBALS['testInput'] ?? []; }
function b64url(string $value): string { return rtrim(strtr(base64_encode($value), '+/', '-_'), '='); }
function requestIsSecure(): bool { return false; }
function db(array $config): PDO { return $GLOBALS['testPdo']; }
function isSuperAdmin(array $user, array $config): bool
{
    return ($user['role'] ?? '') === 'admin' && in_array(strtolower($user['email'] ?? ''), $config['super_admins'], true);
}
function requireSuperAdmin(array $user, array $config): void
{
    if (!isSuperAdmin($user, $config)) respond(403, ['error' => 'Only super administrators.']);
}
function auth(array $config): array
{
    if (!$GLOBALS['testActor']) respond(401, ['error' => 'Sign in.']);
    return viewAsApply($config, $GLOBALS['testActor']);
}
function authOptional(array $config): ?array
{
    return $GLOBALS['testActor'] ? viewAsApply($config, $GLOBALS['testActor']) : null;
}
function logAdminAction(PDO $pdo, int $actor, string $action, ?int $profile = null, ?string $reason = null): void
{
    $pdo->prepare('INSERT INTO admin_actions(admin_user_id,action,target_member_profile_id,reason) VALUES(?,?,?,?)')->execute([$actor,$action,$profile,$reason]);
}
function securityLog(PDO $pdo, array $config, string $action, string $level, string $area, string $message, ?int $actor = null): void
{
    $GLOBALS['testSecurity'][] = compact('action','actor');
}
function check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException('FAIL: ' . $message);
    $GLOBALS['testPasses'] = ($GLOBALS['testPasses'] ?? 0) + 1;
    echo 'ok: ' . $message . "\n";
}
function callPreview(PDO $pdo, array $config, string $route, string $method = 'GET', array $input = []): ViewAsTestResponse
{
    $GLOBALS['route'] = $route; $_SERVER['REQUEST_METHOD'] = $method; $GLOBALS['testInput'] = $input;
    try { viewAsHandle($pdo,$config,$route,$method); }
    catch (ViewAsTestResponse $response) { return $response; }
    throw new RuntimeException('Missing endpoint response.');
}
function previewCookie(array $config, array $actor, int $target, array $overrides = []): string
{
    $claims = array_merge(['t'=>$target,'a'=>$actor['userId'],'aj'=>$actor['jti'],'exp'=>time()+1200,'scope'=>'staff','returnTo'=>'staff#people'],$overrides);
    $body = b64url(json_encode($claims));
    return $body . '.' . b64url(hash_hmac('sha256',$body,viewAsKey($config),true));
}
function asRequest(array $config, array $actor, string $route, string $method = 'GET', array $query = []): array
{
    $GLOBALS['route'] = $route; $_SERVER['REQUEST_METHOD'] = $method; $_GET = $query;
    return viewAsApply($config,$actor);
}
function denied(callable $request, string $message): void
{
    try { $request(); throw new RuntimeException('Expected a refusal.'); }
    catch (ViewAsTestResponse $response) { check($response->status === 403,$message); }
}
function connectViewAs(?string $database = null): PDO
{
    if ($database !== null && !preg_match('/^resok_view_as_test_[a-f0-9]+$/D',$database)) throw new RuntimeException('Unsafe test database.');
    return new PDO('mysql:host=127.0.0.1;port=33317;' . ($database ? 'dbname='.$database.';' : '') . 'charset=utf8mb4','root','LocalWelfareTestsOnly!',[
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC
    ]);
}

$server = connectViewAs(); $database = 'resok_view_as_test_' . bin2hex(random_bytes(5));
$server->exec('CREATE DATABASE ' . $database); $pdo = connectViewAs($database); $GLOBALS['testPdo'] = $pdo;
try {
    foreach (migrateSplit(file_get_contents(__DIR__ . '/../../resok-portal/server/schema.sql')) as $sql) $pdo->exec($sql);
    check(staffPortalEnsureTables($pdo),'staff schema initializes in isolation');
    $pdo->exec("INSERT INTO users(email,password_hash,email_verified,role) VALUES
        ('super@view-as.test','unused',1,'admin'),('employee@view-as.test','unused',1,'member'),
        ('staff-admin@view-as.test','unused',1,'admin'),('other-super@view-as.test','unused',1,'admin'),
        ('member@view-as.test','unused',1,'member'),('admin@view-as.test','unused',1,'admin'),
        ('inactive@view-as.test','unused',1,'member')");
    $ids = $pdo->query('SELECT email,id FROM users')->fetchAll(PDO::FETCH_KEY_PAIR);
    foreach (['employee'=>['Synthetic Employee','Programme Officer',1], 'staff-admin'=>['Synthetic ICT','ICT Officer',1], 'other-super'=>['Other Super','Director',1], 'inactive'=>['Former Employee','Former title',0]] as $mail=>$person) {
        $pdo->prepare('INSERT INTO staff_accounts(user_id,full_name,job_title,active) VALUES(?,?,?,?)')->execute([(int)$ids[$mail.'@view-as.test'],...$person]);
    }
    $pdo->prepare("INSERT INTO member_profiles(user_id,title,first_name,surname,mobile) VALUES(?,'Dr','Member','Example','0700000000')")->execute([(int)$ids['member@view-as.test']]);
    $memberProfile = (int)$pdo->lastInsertId();
    $config = ['jwt_secret'=>'Synthetic-view-as-test-secret-only','super_admins'=>['super@view-as.test','other-super@view-as.test']];
    $actor = ['userId'=>(int)$ids['super@view-as.test'],'email'=>'super@view-as.test','role'=>'admin','superAdmin'=>true,'jti'=>'synthetic-live-session','exp'=>time()+3600];
    $employee = (int)$ids['employee@view-as.test'];
    $GLOBALS['testActor'] = null;
    check(callPreview($pdo,$config,'view-as/start','POST',['scope'=>'staff','userId'=>$employee])->status===401,'anonymous callers cannot start staff previews');
    foreach (['member','admin'] as $mail) {
        $GLOBALS['testActor'] = ['userId'=>(int)$ids[$mail.'@view-as.test'],'email'=>$mail.'@view-as.test','role'=>$mail,'superAdmin'=>false,'jti'=>'ordinary','exp'=>time()+3600];
        check(callPreview($pdo,$config,'view-as/start','POST',['scope'=>'staff','userId'=>$employee])->status===403,'an ordinary '.$mail.' cannot start staff previews');
    }
    $GLOBALS['testActor'] = $actor;
    foreach (['inactive','member','admin'] as $mail) {
        check(callPreview($pdo,$config,'view-as/start','POST',['scope'=>'staff','userId'=>(int)$ids[$mail.'@view-as.test']])->status===403,'staff preview refuses '.$mail.' target');
    }
    check(callPreview($pdo,$config,'view-as/start','POST',['scope'=>'staff','userId'=>$actor['userId']])->status===400,'staff preview refuses the actor own account');
    check(callPreview($pdo,$config,'view-as/start','POST',['scope'=>'unknown','userId'=>$employee])->status===400,'unknown preview scopes cannot be requested');
    $start = callPreview($pdo,$config,'view-as/start','POST',['scope'=>'staff','userId'=>$employee,'returnTo'=>'staff#people']);
    check($start->status===200&&$start->body['goTo']==='staff'&&$start->body['readOnly']===true,'super administrators can enter a read-only staff workspace');
    check($start->body['name']==='Synthetic Employee'&&$start->body['jobTitle']==='Programme Officer'&&$start->body['targetUserId']===$employee,'staff preview identifies the account with staff name and title');
    check($start->body['returnTo']==='staff#people'&&$start->body['endsAt']<=time()+VIEW_AS_MINUTES*60,'preview keeps its staff return destination and maximum lifetime');
    check((int)$pdo->query("SELECT COUNT(*) FROM admin_actions WHERE action='view_as_started' AND admin_user_id=".$actor['userId'])->fetchColumn()===1,'starting the preview is audited under the actor');
    $external = callPreview($pdo,$config,'view-as/start','POST',['scope'=>'staff','userId'=>$employee,'returnTo'=>'https://outside.test']);
    check($external->body['returnTo']==='ict#staff','external return destinations are replaced by the safe staff default');
    $_COOKIE['resok_viewas'] = previewCookie($config,$actor,$employee);
    $effective = asRequest($config,$actor,'staff/home');
    check($effective['userId']===$employee&&$effective['role']==='member'&&!$effective['superAdmin'],'staff requests act as the selected employee without super privileges');
    check($effective['viewAs']['scope']==='staff'&&$effective['viewAs']['readOnly']&&$effective['viewAs']['actorId']===$actor['userId'],'effective requests retain actor provenance and read-only mode');
    foreach (['staff/home','staff/profile','staff/directory','staff/departments','staff/notifications','staff/leave','staff/announcements','staff/announcements/1','welfare/me','welfare/live','members/me','elections/welfare-test','elections/welfare-test/ballot','elections/welfare-test/nominations'] as $route) {
        check(asRequest($config,$actor,$route)['userId']===$employee,'target self-service read works: '.$route);
    }
    check(asRequest($config,$actor,'ict/tickets','GET',['reported'=>'1'])['userId']===$employee,'ICT helpdesk reads retain the selected staff requester');
    check(asRequest($config,$actor,'elections','GET',['electorate'=>'staff'])['userId']===$employee,'the staff election listing uses the selected account');
    foreach (['admin/whoami','staff/people','staff/permissions','staff/payroll/mine','ict/credentials','ict/tickets/assignees','ict/tickets/1','auth/mfa/status','auth/passkey/list','mdt','abstracts/reviews'] as $route) {
        denied(fn()=>asRequest($config,$actor,$route),'preview refuses management or unrelated private reads: '.$route);
    }
    denied(fn()=>asRequest($config,$actor,'staff/announcements','GET',['manage'=>'1']),'preview cannot select managed announcement drafts');
    denied(fn()=>asRequest($config,$actor,'ict/tickets'),'preview cannot broaden the requester filter');
    denied(fn()=>asRequest($config,$actor,'elections'),'preview cannot broaden the electoral listing');
    foreach ([['staff/profile','PATCH'],['members/me','PATCH'],['members/me/profile-image','POST'],['staff/leave','POST'],['staff/notifications/read','POST'],['ict/tickets','POST'],['elections/welfare-test/vote','POST'],['elections/welfare-test/nominate','POST'],['auth/password','POST']] as [$route,$method]) {
        denied(fn()=>asRequest($config,$actor,$route,$method),'staff preview refuses employee action: '.$method.' '.$route);
    }
    foreach (['view-as/status','view-as/end','auth/logout'] as $route) check(asRequest($config,$actor,$route)['userId']===$actor['userId'],'own control route retains the real actor: '.$route);
    $status = callPreview($pdo,$config,'view-as/status');
    check($status->body['active']&&$status->body['scope']==='staff'&&$status->body['targetUserId']===$employee&&$status->body['returnTo']==='staff#people','status exposes only the validated preview and signed return path');
    $peerId = (int)$ids['other-super@view-as.test'];
    $peer = callPreview($pdo,$config,'view-as/start','POST',['scope'=>'staff','userId'=>$peerId]);
    check($peer->status === 200 && $peer->body['readOnly'] && $peer->body['targetSuperAdmin'],'a super administrator can preview a peer staff workspace read-only');
    $_COOKIE['resok_viewas'] = previewCookie($config,$actor,$peerId);
    $peerUser = asRequest($config,$actor,'staff/home');
    check($peerUser['userId'] === $peerId && $peerUser['role'] === 'admin' && $peerUser['superAdmin'] && $peerUser['viewAs']['actorId'] === $actor['userId'],'peer preview uses that peer verified permissions while preserving the real actor');
    foreach (['staff/people','staff/permissions','staff/audit','staff/payroll','admin/whoami'] as $route) {
        check(asRequest($config,$actor,$route)['userId'] === $peerId,'peer preview reads its staff management screen: ' . $route);
    }
    foreach ([['staff/people/1','POST'],['staff/permissions/1','POST'],['staff/profile','POST'],['elections/test/vote','POST'],['auth/password','POST']] as [$route,$method]) {
        denied(fn()=>asRequest($config,$actor,$route,$method),'peer preview still cannot perform actions: ' . $route);
    }
    foreach (['auth/mfa/status','ict/credentials','mdt','abstracts/reviews'] as $route) denied(fn()=>asRequest($config,$actor,$route),'peer staff preview does not open unrelated private areas: ' . $route);
    $removed = $config; $removed['super_admins'] = ['super@view-as.test'];
    check(!asRequest($removed,$actor,'staff/home')['superAdmin'],'revoking the peer super-admin configuration takes effect during preview');
    denied(fn()=>asRequest($removed,$actor,'staff/people'),'a peer whose privileges were removed cannot retain staff management reads');
    $peerStatus = callPreview($pdo,$config,'view-as/status');
    check($peerStatus->body['targetSuperAdmin'] === true,'preview status exposes the current peer permission level for tab reconciliation');
    $_COOKIE['resok_viewas'] = previewCookie($config,$actor,$employee);
    foreach ([['a'=>$actor['userId']+999],['aj'=>'different-session'],['exp'=>time()-1],['scope'=>'invalid']] as $override) {
        $_COOKIE['resok_viewas'] = previewCookie($config,$actor,$employee,$override);
        check(asRequest($config,$actor,'staff/home')['userId']===$actor['userId']&&!isset($_COOKIE['resok_viewas']),'invalid or mismatched preview is discarded');
    }
    $_COOKIE['resok_viewas'] = previewCookie($config,$actor,$employee) . 'tamper';
    check(asRequest($config,$actor,'staff/home')['userId']===$actor['userId'],'a tampered signature cannot impersonate staff');
    $_COOKIE['resok_viewas'] = previewCookie($config,$actor,$employee);
    $noLongerSuper = array_replace($actor,['superAdmin'=>false]);
    check(asRequest($config,$noLongerSuper,'staff/home')['userId']===$actor['userId']&&!isset($_COOKIE['resok_viewas']),'revoking super-admin status ends the preview');
    $_COOKIE['resok_viewas'] = previewCookie($config,$actor,(int)$ids['staff-admin@view-as.test']);
    $staffAdmin = asRequest($config,$actor,'staff/home');
    check($staffAdmin['userId']===(int)$ids['staff-admin@view-as.test']&&$staffAdmin['role']==='member'&&!$staffAdmin['superAdmin'],'an admin staff target is previewed with ordinary staff authority');
    $_COOKIE['resok_viewas'] = previewCookie($config,$actor,$employee);
    $pdo->prepare('UPDATE staff_accounts SET active=0 WHERE user_id=?')->execute([$employee]);
    denied(fn()=>asRequest($config,$actor,'staff/home'),'deactivating the target ends staff preview without falling back to privileged execution');
    check(!isset($_COOKIE['resok_viewas']),'deactivated target preview cookie is cleared');
    $pdo->prepare('UPDATE staff_accounts SET active=1 WHERE user_id=?')->execute([$employee]);
    $_COOKIE['resok_viewas'] = previewCookie($config,$actor,$employee);
    $end = callPreview($pdo,$config,'view-as/end','POST');
    check(!$end->body['active']&&$end->body['returnTo']==='staff#people'&&!isset($_COOKIE['resok_viewas']),'ending a staff preview restores its management destination');
    check((int)$pdo->query("SELECT COUNT(*) FROM admin_actions WHERE action='view_as_ended' AND admin_user_id=".$actor['userId'])->fetchColumn()===1,'ending remains audited under the actor');
    $member = callPreview($pdo,$config,'view-as/start','POST',['profileId'=>$memberProfile]);
    check($member->status===200&&$member->body['goTo']==='dashboard'&&$member->body['scope']==='member'&&!$member->body['readOnly'],'existing member preview routing and profile-edit mode remain supported');
    $memberUser = (int)$ids['member@view-as.test'];
    $_COOKIE['resok_viewas'] = previewCookie($config,$actor,$memberUser,['scope'=>'member']);
    check(asRequest($config,$actor,'members/me','PATCH')['userId']===$memberUser,'existing member profile edits remain permitted');
    denied(fn()=>asRequest($config,$actor,'staff/home'),'member preview still cannot enter staff private data');
    denied(fn()=>asRequest($config,$actor,'elections/welfare-test/vote','POST'),'member preview still cannot cast ballots');
    $old = ['t'=>$memberUser,'a'=>$actor['userId'],'aj'=>$actor['jti'],'exp'=>time()+300];
    $body = b64url(json_encode($old)); $_COOKIE['resok_viewas']=$body.'.'.b64url(hash_hmac('sha256',$body,viewAsKey($config),true));
    check(asRequest($config,$actor,'members/me')['userId']===$memberUser,'already-issued member preview cookies remain compatible');
    $_COOKIE['resok_viewas'] = previewCookie($config,$actor,$employee);
    $pdo->prepare("INSERT INTO staff_grants(user_id,capability) VALUES(?,'staff.admin')")->execute([$employee]);
    $hrPreview = asRequest($config,$actor,'staff/people');
    check($hrPreview['userId'] === $employee && !$hrPreview['superAdmin'] && $hrPreview['role'] === 'member', 'owner can preview HR management screens without turning HR into a website administrator');
    check(staffGate($pdo,$hrPreview,$config)['admin'], 'preview resolves the HR target own staff grant');
    foreach (['admin/whoami','admin/elections','ict/credentials'] as $route) denied(fn()=>asRequest($config,$actor,$route), 'HR preview refuses website administration: ' . $route);
    denied(fn()=>asRequest($config,$actor,'staff/people/1','POST'), 'HR preview remains read-only');
    $pdo->prepare('DELETE FROM staff_grants WHERE user_id=?')->execute([$employee]);
    denied(fn()=>asRequest($config,$actor,'staff/people'), 'HR grant revocation applies during an existing preview');
    echo "\n".$GLOBALS['testPasses']." view-as integration checks passed against a disposable database.\n";
} finally {
    unset($_COOKIE['resok_viewas']); $GLOBALS['testPdo']=null; $pdo=null; $server->exec('DROP DATABASE '.$database);
    ob_end_flush();
}
