<?php
declare(strict_types=1);

/** Integration tests against a dedicated disposable MariaDB instance on port 33317.
 * Uses synthetic people only. Never connects to config.php or the production database.
 * php tools/dev/test-welfare.php
 */
date_default_timezone_set('Africa/Nairobi');
require_once __DIR__.'/../../resok-portal/public/api/lib/elections.php';
require_once __DIR__.'/../../resok-portal/public/api/lib/staff.php';
require_once __DIR__.'/../../resok-portal/public/api/lib/staff-portal.php';
require_once __DIR__.'/../../resok-portal/public/api/lib/welfare.php';
require_once __DIR__.'/../../resok-portal/public/api/lib/staff-geo.php';
require_once __DIR__.'/../../resok-portal/public/api/lib/migrate.php';

class WelfareTestResponse extends Exception { public int $status; public array $body; function __construct(int $s,array $b){parent::__construct();$this->status=$s;$this->body=$b;} }
function respond(int $s,array $b): void { throw new WelfareTestResponse($s,$b); }
function auth(array $config): array { if(empty($GLOBALS['testUser']))respond(401,['error'=>'Sign in']); return $GLOBALS['testUser']; }
function isSuperAdmin(array $user,array $config): bool { return ($user['role']??'')==='admin'; }
function requireSuperAdmin(array $user,array $config): void { if(!isSuperAdmin($user,$config))respond(403,['error'=>'Super administrator required']); }
function input(): array { return $GLOBALS['testInput']??[]; }
function logAdminAction(PDO $pdo,?int $admin,string $action,?int $target,?string $reason=null): void {}
function connectTest(?string $db=null): PDO {
    if($db!==null&&!preg_match('/^resok_welfare_test_[a-f0-9]+$/',$db))throw new RuntimeException('Unsafe test database name');
    $p=new PDO('mysql:host=127.0.0.1;port=33317;'.($db?'dbname='.$db.';':'').'charset=utf8mb4','root','LocalWelfareTestsOnly!',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $p->exec("SET time_zone='+03:00'");return $p;
}
if(($argv[1]??'')==='--vote-worker'){
    $p=connectTest($argv[2]);echo "ready\n";flush();
    echo json_encode(electionCastVote($p,$argv[3],(int)$argv[4],[(int)$argv[5]=>[(int)$argv[6]]],null));exit;
}
$passes=0;
function check(bool $condition,string $message): void { global $passes;if(!$condition)throw new RuntimeException('FAIL: '.$message);$passes++;echo "ok: $message\n"; }
function reject(callable $fn,string $message): void {try{$fn();}catch(RuntimeException|InvalidArgumentException $e){check(true,$message);return;}check(false,$message);}
function endpoint(PDO $p,string $route,string $method,array $body=[]): WelfareTestResponse {
    $GLOBALS['testInput']=$body;try{welfareHandle($p,[],$route,$method);}catch(WelfareTestResponse $r){return $r;}throw new RuntimeException('No response');
}
function worker(string $db,string $slug,int $uid,int $position,int $candidate): array {
    $pipes=[];$process=proc_open([PHP_BINARY,__FILE__,'--vote-worker',$db,$slug,(string)$uid,(string)$position,(string)$candidate],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if(!is_resource($process))throw new RuntimeException('Cannot start concurrent voter');
    fclose($pipes[0]);if(trim((string)fgets($pipes[1]))!=='ready')throw new RuntimeException('Worker failed to start');return [$process,$pipes];
}
function finishWorker(array $w): array {$out=stream_get_contents($w[1][1]);$err=stream_get_contents($w[1][2]);fclose($w[1][1]);fclose($w[1][2]);$code=proc_close($w[0]);if($code!==0)throw new RuntimeException('Worker failed: '.$err);return json_decode($out,true,512,JSON_THROW_ON_ERROR);}
$server=connectTest();$db='resok_welfare_test_'.bin2hex(random_bytes(5));$server->exec('CREATE DATABASE '.$db);$p=connectTest($db);
try {
    $sqlDir=__DIR__.'/../../resok-portal/server/';
    foreach(['schema.sql','schema-elections.sql','schema-elections-ballot.sql','migration-election-nominations.sql','migration-election-external-nominees.sql','migration-election-staff.sql','migration-2026-11-03-welfare-automation.sql','migration-2026-11-03-welfare-staff-total-34.sql'] as $file){foreach(migrateSplit(file_get_contents($sqlDir.$file)) as $sql)$p->exec($sql);}
    check(staffPortalEnsureTables($p),'staff schema initializes in the isolated database');
    $p->exec("INSERT INTO users(email,password_hash,email_verified,role) VALUES('officer@welfare.test','unused',1,'admin'),('observer@welfare.test','unused',1,'member')");
    $officer=(int)$p->query("SELECT id FROM users WHERE email='officer@welfare.test'")->fetchColumn();
    $observer=(int)$p->query("SELECT id FROM users WHERE email='observer@welfare.test'")->fetchColumn();
    [$e,$error]=electionCreate($p,['electorate'=>'staff','title'=>'Synthetic Welfare Test','nominationsOpenAt'=>date('Y-m-d H:i:s',time()-300),'nominationsCloseAt'=>date('Y-m-d H:i:s',time()+1200),'opensAt'=>date('Y-m-d H:i:s',time()+2400),'closesAt'=>date('Y-m-d H:i:s',time()+3600)],$officer);
    check(!$error,'staff election is created');$id=$e['id'];
    $GLOBALS['testUser']=null;check(endpoint($p,'welfare/live','GET')->status===401,'anonymous viewers cannot read the live feed');
    $GLOBALS['testUser']=['userId'=>$observer,'role'=>'member','email'=>'observer@welfare.test'];
    check(endpoint($p,'admin/welfare/'.$id.'/configure','POST')->status===403,'ordinary observers cannot configure or create voters');
    $GLOBALS['testUser']=['userId'=>$officer,'role'=>'admin','email'=>'officer@welfare.test'];
    check(endpoint($p,'admin/welfare/'.$id.'/configure','POST')->status===200,'officer configures the automatic election');
    $r=welfareReadiness($p,$id);check(!$r['ready']&&$r['targetEmployees']===0&&$r['expectedVoters']===0,'an empty staff list is not ready and has no fixed voter target');
    reject(fn()=>welfareArm($p,$id,true),'an empty staff list cannot arm the schedule');
    [$unused,$error]=electionDrawRoll($p,$id,2026);check($error!==null,'manual drawing also rejects an empty staff list');
    $p->exec("UPDATE welfare_settings SET staff_target=32 WHERE election_id=$id");
    foreach(migrateSplit(file_get_contents($sqlDir.'migration-2026-11-03-welfare-staff-total-34.sql')) as $sql)$p->exec($sql);
    check((int)welfareSettings($p,$id)['staff_target']===34,'the historical migration updates its unopened-election metadata');
    $people=[];for($n=1;$n<=34;$n++)$people[]=['name'=>'Synthetic Employee '.$n,'email'=>'employee'.$n.'@welfare.test','staffNumber'=>'TEST-'.$n];
    welfareImport($p,$id,$people,'employee',$officer);
    $r=welfareReadiness($p,$id);check($r['ready']&&$r['targetEmployees']===34&&$r['employeesReady']===0&&$r['expectedVoters']===34,'pending email verification does not block the active staff electorate');
    check((int)$p->query('SELECT COUNT(*) FROM staff_accounts')->fetchColumn()===34,'34 distinct synthetic employees are provisioned');
    check((int)$p->query("SELECT COUNT(*) FROM users WHERE role='member' AND email LIKE 'employee%'")->fetchColumn()===34,'employee creation does not grant administrative roles');
    $p->exec("UPDATE staff_accounts SET job_title='Programme Officer' WHERE staff_number='TEST-1'");
    $titleUser=(int)$p->query("SELECT user_id FROM staff_accounts WHERE staff_number='TEST-1'")->fetchColumn();
    $GLOBALS['testUser']=['userId'=>$titleUser,'role'=>'member','email'=>'employee1@welfare.test'];
    $self=endpoint($p,'welfare/me','GET')->body['me'];
    check($self['jobTitle']==='Programme Officer'&&$self['name']==='Synthetic Employee 1','the election header receives the signed-in employee job title and name');
    check(array_keys($self)===['name','email','jobTitle'],'the election header does not expose additional HR fields');
    $GLOBALS['testUser']=['userId'=>$observer,'role'=>'member','email'=>'observer@welfare.test'];
    $self=endpoint($p,'welfare/me','GET')->body['me'];
    check($self['jobTitle']===null&&$self['name']==='observer@welfare.test','an account without a staff record keeps its email fallback and no job title');
    $GLOBALS['testUser']=['userId'=>$officer,'role'=>'admin','email'=>'officer@welfare.test'];
    reject(fn()=>welfareImport($p,$id,[$people[0],$people[0]],'employee',$officer),'duplicate email rows are rejected');
    $bad=[['name'=>'Conflicting Account','email'=>'conflict@welfare.test','staffNumber'=>'TEST-1']];
    reject(fn()=>welfareImport($p,$id,$bad,'employee',$officer),'duplicate employee numbers are rejected');
    check((int)$p->query("SELECT COUNT(*) FROM users WHERE email='conflict@welfare.test'")->fetchColumn()===0,'a failed import rolls back newly created accounts');
    $p->exec("UPDATE users SET password_hash='preserved',email_verified=1 WHERE email LIKE 'employee%'");
    welfareImport($p,$id,[$people[0]],'employee',$officer);
    check($p->query("SELECT password_hash FROM users WHERE email='employee1@welfare.test'")->fetchColumn()==='preserved','existing passwords are preserved');
    welfareImport($p,$id,[['name'=>$people[0]['name'],'email'=>$people[0]['email']]],'employee',$officer);
    check($p->query("SELECT s.staff_number FROM staff_accounts s JOIN users u ON u.id=s.user_id WHERE u.email='employee1@welfare.test'")->fetchColumn()==='TEST-1','omitting an employee number preserves an existing number');
    reject(fn()=>welfareImport($p,$id,[['name'=>'Synthetic Guest','email'=>'guest@welfare.test']],'guest',$officer),'additional voter imports are rejected');
    check((int)$p->query("SELECT COUNT(*) FROM users WHERE email='guest@welfare.test'")->fetchColumn()===0,'a rejected extra voter does not create an account');
    [$positions,$error]=electionPositionSave($p,$id,['title'=>'Welfare Chair','seats'=>1,'maxChoices'=>1,'allowAbstain'=>false]);
    check(!$error,'contested post is configured');$pos=$positions[0]['id'];
    foreach([33,32] as $total){
        $p->exec("UPDATE staff_accounts SET active=0 WHERE staff_number='TEST-".($total+1)."'");
        $r=welfareReadiness($p,$id);check($r['ready']&&$r['targetEmployees']===$total&&$r['expectedVoters']===$total,"$total verified active employees form a valid electorate");
        [$roll,$error]=electionDrawRoll($p,$id,2026);check(!$error&&$roll['onRoll']===$total,"manual drawing includes all $total active employees");
        welfareArm($p,$id,true);check((int)welfareSettings($p,$id)['staff_target']===$total,"arming stores the actual count of $total staff voters");
        welfareArm($p,$id,false);
    }
    $p->exec("UPDATE staff_accounts SET active=1 WHERE staff_number='TEST-33'");
    $p->exec("UPDATE staff_accounts SET active=1 WHERE staff_number='TEST-34'");
    welfareImport($p,$id,[['name'=>'Synthetic Extra Employee','email'=>'extra@welfare.test']],'employee',$officer);
    $extra=(int)$p->query("SELECT id FROM users WHERE email='extra@welfare.test'")->fetchColumn();
    check($p->query('SELECT staff_number FROM staff_accounts WHERE user_id='.$extra)->fetchColumn()===null,'a staff account can be imported without an employee number');
    $r=welfareReadiness($p,$id);check($r['ready']&&$r['expectedVoters']===35&&$r['unverifiedEmployees']===1,'an unverified active account remains eligible on the roster');
    check(electionRollSummary($p,$id)['eligibleButCannotSignIn']===1,'the roll summary counts active staff still awaiting email verification');
    [$roll,$error]=electionDrawRoll($p,$id,2026);check(!$error&&$roll['onRoll']===35&&electionRollEntry($p,$id,$extra)!==null,'manual staff roll includes unverified employees without employee numbers');
    welfareArm($p,$id,true);check((int)welfareSettings($p,$id)['staff_target']===35&&electionRollEntry($p,$id,$extra)!==null,'automatic schedule keeps unverified active staff on the roll');
    welfareArm($p,$id,false);
    $p->exec("UPDATE users SET email_verified=1 WHERE email='extra@welfare.test'");
    $r=welfareReadiness($p,$id);check($r['ready']&&$r['targetEmployees']===35&&$r['expectedVoters']===35,'35 verified active employees form a valid electorate');
    [$roll,$error]=electionDrawRoll($p,$id,2026);check(!$error&&$roll['onRoll']===35&&electionRollEntry($p,$id,$extra)!==null,'manual drawing includes verified employees without numbers');
    [$unused,$error]=electionCandidateSave($p,$pos,['userId'=>$extra,'status'=>'approved']);
    check(!$error&&(int)$p->query('SELECT COUNT(*) FROM election_candidates WHERE position_id='.$pos.' AND nominee_user_id='.$extra)->fetchColumn()===1,'an officer can add an active staff candidate without an employee number');
    $p->exec('DELETE FROM election_candidates WHERE position_id='.$pos.' AND nominee_user_id='.$extra);
    welfareArm($p,$id,true);check((int)welfareSettings($p,$id)['staff_target']===35&&electionRollEntry($p,$id,$extra)!==null,'arming freezes the actual 35 staff including employees without numbers');
    welfareArm($p,$id,false);
    $p->exec('UPDATE staff_accounts SET active=0 WHERE user_id='.$extra);
    // Legacy extra-voter rows must never enlarge the current staff-only electorate.
    $p->exec("INSERT INTO users(email,password_hash,email_verified,role) VALUES('guest@welfare.test','unused',1,'member')");$guest=(int)$p->lastInsertId();
    $p->prepare("INSERT INTO welfare_guests(election_id,user_id,name,added_by) VALUES(?,?,'Synthetic Legacy Guest',?)")->execute([$id,$guest,$officer]);
    $r=welfareReadiness($p,$id);check($r['ready']&&$r['expectedVoters']===34,'legacy guest rows do not change the total of 34');
    [$roll,$error]=electionDrawRoll($p,$id,2026);check(!$error&&(int)$p->query("SELECT COUNT(*) FROM election_roll WHERE election_id=$id")->fetchColumn()===34,'manual roll includes exactly 34 employees and no legacy guest');
    $r=welfareArm($p,$id,true);check($r['ready']&&$r['expectedVoters']===34,'the current active staff list is ready without additional voters');
    check((int)$p->query('SELECT COUNT(*) FROM election_roll WHERE election_id='.$id)->fetchColumn()===34,'the frozen roll contains exactly 34 distinct staff accounts');
    [$unused,$error]=electionDrawRoll($p,$id,2026);check($error!==null,'an armed roster cannot be redrawn');
    reject(fn()=>welfareImport($p,$id,[$people[0]],'employee',$officer),'roster imports are blocked after arming');
    $p->prepare("INSERT INTO election_roll(election_id,user_id,name,standing_at_cutoff) VALUES(?,?,'Synthetic Invalid Voter','welfare_guest')")->execute([$id,$guest]);
    welfareRunScheduled($p);check(electionFind($p,(string)$id)['status']==='draft'&&str_contains((string)welfareSettings($p,$id)['last_error'],'frozen roll'),'an oversized frozen roll cannot progress automatically');
    welfareArm($p,$id,false);welfareArm($p,$id,true);
    welfareRunScheduled($p);$e=electionFind($p,(string)$id);check($e['status']==='nominations','nominations open automatically at the scheduled time');
    $uids=$p->query("SELECT user_id FROM staff_accounts WHERE active=1 ORDER BY user_id")->fetchAll(PDO::FETCH_COLUMN);
    [$nom,$error]=electionNominate($p,$e['slug'],(int)$uids[0],['positionId'=>$pos,'userId'=>(int)$uids[1]]);
    check(!$error&&$nom['final']&&$nom['approved'],'a nomination is final and approved automatically');
    $cid=(int)$p->query('SELECT id FROM election_candidates WHERE nominee_user_id='.(int)$uids[1])->fetchColumn();
    $p->prepare("UPDATE staff_accounts SET job_title='Programme Coordinator' WHERE user_id=?")->execute([(int)$uids[1]]);
    $candidate=electionPositions($p,$id)[0]['candidates'][0];
    check($candidate['id']===$cid&&$candidate['jobTitle']==='Programme Coordinator','staff election details and ballots include the candidate job title');
    check(electionNominations($p,$id)[0]['jobTitle']==='Programme Coordinator','staff nomination rows include the nominee job title');
    $picker=array_column(electionNominatableMembers($p,$id),null,'userId');
    check($picker[(int)$uids[1]]['jobTitle']==='Programme Coordinator','staff nomination choices include staff job titles');
    $public=electionForVoters($e,['positions'=>electionPositions($p,$id)]);
    check($public['positions'][0]['candidates'][0]['jobTitle']==='Programme Coordinator'&&$public['positions'][0]['candidates'][0]['membershipId']===null,'job-title display preserves staff-number privacy');
    check($p->query('SELECT accepted_at FROM election_candidates WHERE id='.$cid)->fetchColumn()===null,'automatic registration does not fabricate personal consent');
    [$unused,$error]=electionRespondToNomination($p,$cid,(int)$uids[1],false);check($error!==null,'the nominee cannot decline through the API');
    [$unused,$error]=electionRespondToNomination($p,$cid,(int)$uids[1],true);check($error!==null,'no acceptance action is required');
    [$unused,$error]=electionNominate($p,$e['slug'],(int)$uids[2],['positionId'=>$pos,'userId'=>$guest]);check($error!==null,'an outsider cannot stand as an employee candidate');
    [$nom,$error]=electionNominate($p,$e['slug'],(int)$uids[2],['positionId'=>$pos,'userId'=>(int)$uids[3]]);check(!$error,'a second employee is nominated automatically');
    $cid2=(int)$p->query('SELECT id FROM election_candidates WHERE nominee_user_id='.(int)$uids[3])->fetchColumn();
    $guestUser=['userId'=>$guest,'role'=>'member','email'=>'guest@welfare.test'];
    check(!electionStaffMayView($p,[],$guestUser)&&!electionVisibleTo($p,[],$e,$guestUser),'legacy extra voters do not gain staff election access');
    check(welfareDuringWindow($p,(int)$uids[0]),'on-roll employees receive election-window access');
    check(!welfareDuringWindow($p,$observer),'an observer does not gain staff election access');
    check(geoEnsureTables($p),'access-control tables initialize separately');
    $p->exec("INSERT INTO staff_geo_settings(name,value) VALUES('hours','enforce'),('hoursFrom','00:00'),('hoursTo','00:00'),('country','off'),('county','off')");
    $GLOBALS['route']='elections/'.$e['slug'];
    check(geoCheck($p,[],(int)$uids[0],'member')===null,'an on-roll employee can reach election routes after hours');
    $GLOBALS['route']='staff/payroll';
    check((geoCheck($p,[],(int)$uids[0],'member')['kind']??null)==='hours','the election exception does not open other staff work routes');
    $GLOBALS['testUser']=['userId'=>$observer,'role'=>'member','email'=>'observer@welfare.test'];
    $live=endpoint($p,'welfare/live','GET');check($live->status===200&&count($live->body['elections'])===1,'a signed-in non-voter sees the live election');
    $json=json_encode($live->body);check(!str_contains($json,'@welfare.test')&&!str_contains($json,'TEST-1'),'live results do not expose emails or employee numbers');
    [$unused,$error]=electionCastVote($p,$e['slug'],$observer,[$pos=>[$cid]],null);check($error!==null,'viewing live totals does not confer a vote');
    $p->exec("UPDATE elections SET nominations_close_at=DATE_SUB(NOW(),INTERVAL 2 MINUTE),opens_at=DATE_SUB(NOW(),INTERVAL 1 MINUTE) WHERE id=$id");
    welfareRunScheduled($p);check(electionFind($p,(string)$id)['status']==='open','nominations close and voting opens automatically');
    $p->exec("UPDATE welfare_settings SET staff_target=32 WHERE election_id=$id");
    foreach(migrateSplit(file_get_contents($sqlDir.'migration-2026-11-03-welfare-staff-total-34.sql')) as $sql)$p->exec($sql);
    check((int)welfareSettings($p,$id)['staff_target']===32 && (int)$p->query("SELECT COUNT(*) FROM election_roll WHERE election_id=$id")->fetchColumn()===34,'the target migration preserves metadata and frozen rolls once voting has opened');
    $p->exec("UPDATE welfare_settings SET staff_target=34 WHERE election_id=$id");
    [$unused,$error]=electionCastVote($p,$e['slug'],(int)$uids[0],[$pos=>'abstain'],null);check($error!==null,'explicit abstention obeys the position rule');
    check((int)$p->query('SELECT COUNT(*) FROM election_ballots')->fetchColumn()===0,'invalid ballots write nothing');
    $w1=worker($db,$e['slug'],(int)$uids[0],$pos,$cid);$w2=worker($db,$e['slug'],(int)$uids[0],$pos,$cid);
    $v1=finishWorker($w1);$v2=finishWorker($w2);check(($v1[1]===null)!==($v2[1]===null),'two simultaneous submissions record exactly one ballot');
    foreach(array_slice($uids,1) as $i=>$uid){[$receipt,$error]=electionCastVote($p,$e['slug'],(int)$uid,[$pos=>[$i<23?$cid:$cid2]],null);if($error)throw new RuntimeException($error);}
    $live=welfareLive($p)['elections'][0];check($live['roll']['voted']===34&&$live['positions'][0]['ballotsCast']===34,'live count reconciles all 34 staff ballots');
    check(array_sum(array_column($live['positions'][0]['candidates'],'votes'))===34,'candidate totals match recorded ballots');
    $liveCandidates=array_column($live['positions'][0]['candidates'],null,'candidateId');
    check($liveCandidates[$cid]['jobTitle']==='Programme Coordinator','provisional live totals identify staff candidates by job title');
    $p->exec("UPDATE elections SET closes_at=DATE_SUB(NOW(),INTERVAL 1 SECOND) WHERE id=$id");
    $p->exec("CREATE TRIGGER welfare_test_failure BEFORE INSERT ON election_results FOR EACH ROW BEGIN IF NEW.votes<20 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic declaration failure'; END IF; END");
    welfareRunScheduled($p);
    check((int)$p->query("SELECT COUNT(*) FROM election_results WHERE election_id=$id")->fetchColumn()===0,'a declaration failure rolls back every partial result row');
    check(electionFind($p,(string)$id)['status']!=='published','a failed declaration cannot publish a partial result');
    $p->exec('DROP TRIGGER welfare_test_failure');welfareRunScheduled($p);
    check(electionFind($p,(string)$id)['status']==='published','voting closes, counts and publishes automatically');
    $finalLive=welfareLive($p)['elections'][0];
    check($finalLive['final']&&!array_key_exists('jobTitle',$finalLive['positions'][0]['candidates'][0]),'published live totals do not attach current job titles to the historical count');
    check(!array_key_exists('jobTitle',$finalLive['results']['positions'][0]['candidates'][0]),'historical declarations keep their original candidate fields');
    check(welfareSettings($p,$id)['last_error']===null,'a successful retry clears the old scheduler error');
    check(electionResults($p,$id)['declaration']===1,'a single final declaration is stored');welfareRunScheduled($p);check(electionResults($p,$id)['declaration']===1,'scheduler retries do not duplicate declarations');
    [$unused,$error]=electionCastVote($p,$e['slug'],(int)$uids[0],[$pos=>[$cid]],null);check($error!==null,'closed elections reject further votes');
    // A closing transaction wins against a ballot waiting for the same election lock.
    [$race,$error]=electionCreate($p,['electorate'=>'staff','title'=>'Synthetic Closing Race','opensAt'=>date('Y-m-d H:i:s',time()-600),'closesAt'=>date('Y-m-d H:i:s',time()+600)],$officer);$rid=$race['id'];
    $p->exec("INSERT INTO election_positions(election_id,title) VALUES($rid,'Chair')");$rpos=(int)$p->lastInsertId();$p->exec("INSERT INTO election_candidates(position_id,name,status) VALUES($rpos,'Synthetic Candidate','approved')");$rc=(int)$p->lastInsertId();
    $p->exec("INSERT INTO election_roll(election_id,user_id,name) VALUES($rid,{$uids[0]},'Synthetic voter')");$p->exec("UPDATE elections SET status='open',opened_at=NOW() WHERE id=$rid");
    $p->beginTransaction();$p->query("SELECT id FROM elections WHERE id=$rid FOR UPDATE");$waiting=worker($db,$race['slug'],(int)$uids[0],$rpos,$rc);
    [$unused,$error]=electionSetStatus($p,$rid,'closed');check(!$error,'officer closes while a ballot waits');$p->commit();$late=finishWorker($waiting);
    check($late[1]!==null,'waiting ballot rechecks closure after acquiring the lock');
    check((int)$p->query("SELECT COUNT(*) FROM election_ballots WHERE election_id=$rid")->fetchColumn()===0,'no ballot commits after closure wins the lock');
    $p->exec("UPDATE elections SET status='open',closes_at=DATE_ADD(NOW(),INTERVAL 10 MINUTE) WHERE id=$rid");
    $p->exec("UPDATE election_positions SET seats=2,max_choices=2,allow_abstain=1 WHERE id=$rpos");
    $p->exec("INSERT INTO election_candidates(position_id,name,status) VALUES($rpos,'Second Synthetic Candidate','approved')");$rc2=(int)$p->lastInsertId();
    [$receipt,$error]=electionCastVote($p,$race['slug'],(int)$uids[0],[$rpos=>[$rc,$rc2]],null);
    check(!$error,'a multi-seat ballot records both valid selections');
    $p->exec("UPDATE elections SET status='closed' WHERE id=$rid");[$multi,$error]=electionTally($p,$rid);
    check(!$error&&$multi['positions'][0]['ballotsCast']===1&&array_sum(array_column($multi['positions'][0]['candidates'],'votes'))===2,'multi-seat tallies distinguish one ballot from two selections');
    [$unused,$error]=electionSetStatus($p,$rid,'draft');check($error!==null,'a previously opened election cannot be rewound');

    // A tied result is never resolved by alphabetical order or by arbitrary automation.
    [$tie,$error]=electionCreate($p,['electorate'=>'staff','title'=>'Synthetic Tie','nominationsOpenAt'=>date('Y-m-d H:i:s',time()-1800),'nominationsCloseAt'=>date('Y-m-d H:i:s',time()-1200),'opensAt'=>date('Y-m-d H:i:s',time()-600),'closesAt'=>date('Y-m-d H:i:s',time()+600)],$officer);
    $tid=$tie['id'];$GLOBALS['testUser']=['userId'=>$officer,'role'=>'admin','email'=>'officer@welfare.test'];
    check(endpoint($p,'admin/welfare/'.$tid.'/configure','POST')->status===200,'second welfare election can be configured independently');
    [$pp,$error]=electionPositionSave($p,$tid,['title'=>'Chair','seats'=>1,'maxChoices'=>1]);$tp=$pp[0]['id'];
    foreach([(int)$uids[0],(int)$uids[1]] as $u){[$pp,$error]=electionCandidateSave($p,$tp,['userId'=>$u,'status'=>'approved']);if($error)throw new RuntimeException($error);}
    welfareArm($p,$tid,true);welfareRunScheduled($p);$tc=$p->query("SELECT id FROM election_candidates WHERE position_id=$tp ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
    foreach([0,1] as $i){[$receipt,$error]=electionCastVote($p,$tie['slug'],(int)$uids[$i],[$tp=>[(int)$tc[$i]]],null);if($error)throw new RuntimeException($error);}
    $p->exec("UPDATE elections SET closes_at=DATE_SUB(NOW(),INTERVAL 1 SECOND) WHERE id=$tid");welfareRunScheduled($p);
    check(electionFind($p,(string)$tid)['status']==='closed','a tied election remains closed rather than publishing an invented winner');
    check(str_contains((string)welfareSettings($p,$tid)['last_error'],'tied'),'a tie produces an actionable status');
    check(electionResults($p,$tid)['declaration']===0,'a tie does not create a final declaration');
    check(count(electionNominatableMembers($p,$id))===34,'the employee candidate picker contains exactly the 34 active staff');

    // Board elections retain their separate nomination and private-count rules.
    [$board,$error]=electionCreate($p,['title'=>'Synthetic Board Regression','eligibilityCutoff'=>date('Y-m-d'),'nominationsOpenAt'=>date('Y-m-d H:i:s',time()-300),'nominationsCloseAt'=>date('Y-m-d H:i:s',time()+600),'opensAt'=>date('Y-m-d H:i:s',time()+1200),'closesAt'=>date('Y-m-d H:i:s',time()+1800)],$officer);
    $bid=$board['id'];[$bp,$error]=electionPositionSave($p,$bid,['title'=>'Board Chair']);$bpos=$bp[0]['id'];$members=[];
    foreach([0,1] as $i){$u=(int)$uids[$i];$p->exec("INSERT INTO member_profiles(user_id,first_name,surname,mobile,membership_status,renewal_due) VALUES($u,'Synthetic','Member','0700000000','active',DATE_ADD(CURDATE(),INTERVAL 1 YEAR))");$mid=(int)$p->lastInsertId();$members[]=$mid;$p->exec("INSERT INTO election_roll(election_id,member_profile_id,user_id,name) VALUES($bid,$mid,$u,'Synthetic member')");}
    [$unused,$error]=electionSetStatus($p,$bid,'nominations');check(!$error,'board nominations still open normally');
    [$nom,$error]=electionNominate($p,$board['slug'],(int)$uids[0],['positionId'=>$bpos,'memberProfileId'=>$members[1]]);
    check(!$error&&!$nom['accepted'],'board nominees still decide whether to accept');
    check(!array_key_exists('jobTitle',electionPositions($p,$bid)[0]['candidates'][0]),'board candidates do not receive staff job titles');
    check(!array_key_exists('jobTitle',electionNominations($p,$bid)[0]),'board nominations keep their existing field shape');
    check(!array_key_exists('jobTitle',electionNominatableMembers($p,$bid)[0]),'board nomination choices keep their existing field shape');
    $bc=(int)$p->query("SELECT id FROM election_candidates WHERE position_id=$bpos")->fetchColumn();
    [$answer,$error]=electionRespondToNomination($p,$bc,(int)$uids[1],false);check(!$error&&!$answer['accepted'],'board nominees can still decline');
    [$unused,$error]=electionTally($p,$bid,true);check($error!==null,'the live-count option does not expose an ongoing board election');
    $p->exec("UPDATE elections SET status='closed' WHERE id=$bid");
    [$boardCount,$error]=electionTally($p,$bid);
    check(!$error&&!array_key_exists('jobTitle',$boardCount['positions'][0]['candidates'][0]),'a closed member-election count does not disclose staff job titles');
    echo "\n$passes integration checks passed against a disposable database.\n";
} finally { if($p->inTransaction())$p->rollBack();$p=null;$server->exec('DROP DATABASE '.$db); }
