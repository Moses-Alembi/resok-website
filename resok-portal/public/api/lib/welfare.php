<?php
declare(strict_types=1);

/** Automatic welfare elections and aggregate live results for signed-in portal users. */
function welfareReady(PDO $pdo): bool
{
    static $ready = null;
    if ($ready !== null) return $ready;
    try {
        foreach (['welfare_settings', 'welfare_guests', 'welfare_events'] as $table) $pdo->query('SELECT 1 FROM ' . $table . ' LIMIT 1');
        return $ready = true;
    } catch (Throwable $e) { return $ready = false; }
}

function welfareSettings(PDO $pdo, int $id): ?array
{
    if (!welfareReady($pdo)) return null;
    $s = $pdo->prepare('SELECT * FROM welfare_settings WHERE election_id = ?');
    $s->execute([$id]);
    return $s->fetch() ?: null;
}

function welfareEvent(PDO $pdo, int $id, string $message): void
{
    $pdo->prepare('INSERT INTO welfare_events (election_id, message) VALUES (?, ?)')->execute([$id, mb_substr($message, 0, 255)]);
}

/** No addresses, staff numbers, voter identities or individual choices in this response. */
function welfareLive(PDO $pdo, ?int $viewerId = null): array
{
    if (!welfareReady($pdo)) return ['elections' => [], 'refreshSeconds' => 5];
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
    $ids = $pdo->query("SELECT e.id FROM elections e JOIN welfare_settings w ON w.election_id=e.id
                        WHERE w.armed_at IS NOT NULL AND e.status NOT IN ('draft','cancelled') ORDER BY e.opens_at DESC LIMIT 4")->fetchAll(PDO::FETCH_COLUMN);
    $out = [];
    foreach ($ids as $id) {
        $e = electionFind($pdo, (string)$id);
        [$count, $error] = electionTally($pdo, (int)$id, true);
        if ($error) continue;
        $s = welfareSettings($pdo, (int)$id);
        $events = $pdo->prepare('SELECT message, created_at AS createdAt FROM welfare_events WHERE election_id=? ORDER BY id DESC LIMIT 8');
        $events->execute([$id]);
        $out[] = ['election' => $e, 'canParticipate' => $viewerId !== null && electionRollEntry($pdo,(int)$id,$viewerId) !== null, 'roll' => $count['roll'], 'positions' => $count['positions'],
                  'final' => $e['status'] === 'published', 'results' => $e['status'] === 'published' ? electionResults($pdo, (int)$id) : null,
                  'attention' => $s['last_error'], 'events' => $events->fetchAll()];
    }
    $result = ['elections' => $out, 'refreshSeconds' => 5, 'serverTime' => date('c')];
    if ($own) $pdo->commit();
    return $result;
    } catch (Throwable $e) { if ($own && $pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}

function welfareGuests(PDO $pdo, int $id): array
{
    if (!welfareReady($pdo)) return [];
    $s = $pdo->prepare('SELECT g.user_id AS userId,g.name,u.email,u.email_verified AS ready FROM welfare_guests g JOIN users u ON u.id=g.user_id WHERE g.election_id=? ORDER BY g.name');
    $s->execute([$id]);
    return $s->fetchAll();
}

function welfareReadiness(PDO $pdo, int $id): array
{
    $settings = welfareSettings($pdo, $id);
    $employees = $pdo->query("SELECT COUNT(*) AS total, COALESCE(SUM(u.email_verified=1),0) AS ready
        FROM staff_accounts s JOIN users u ON u.id=s.user_id WHERE s.active=1 AND s.staff_number IS NOT NULL AND s.staff_number<>''")->fetch();
    $guests = welfareGuests($pdo, $id);
    $target = (int)($settings['staff_target'] ?? 32);
    $guestReady = count(array_filter($guests, fn($g) => (bool)$g['ready']));
    return ['targetEmployees' => $target, 'employees' => (int)$employees['total'], 'employeesReady' => (int)$employees['ready'],
        'additionalVoters' => count($guests), 'additionalReady' => $guestReady,
        'expectedVoters' => $target + count($guests),
        'ready' => (int)$employees['total'] === $target && (int)$employees['ready'] === $target && $guestReady === count($guests),
        'armed' => !empty($settings['armed_at']), 'lastError' => $settings['last_error'] ?? null];
}

/** Validate the complete import before touching accounts. Existing passwords and roles stay intact. */
function welfareImport(PDO $pdo, int $id, array $people, string $kind, int $admin): array
{
    if (!in_array($kind, ['employee', 'guest'], true) || !$people || count($people) > 200) throw new InvalidArgumentException('Supply between 1 and 200 named people.');
    $seen = []; $numbers = [];
    foreach ($people as &$p) {
        if (!is_array($p)) throw new InvalidArgumentException('Every row needs a name and email.');
        $p['name'] = trim((string)($p['name'] ?? ''));
        $p['email'] = strtolower(trim((string)($p['email'] ?? '')));
        $p['staffNumber'] = trim((string)($p['staffNumber'] ?? ''));
        if ($p['name'] === '' || mb_strlen($p['name']) > 160 || !filter_var($p['email'], FILTER_VALIDATE_EMAIL) || strlen($p['email']) > 190) throw new InvalidArgumentException('Every person needs a full name and valid email.');
        if (isset($seen[$p['email']])) throw new InvalidArgumentException('An email appears twice in the import.');
        $seen[$p['email']] = true;
        if ($kind === 'employee') {
            if ($p['staffNumber'] === '' || strlen($p['staffNumber']) > 30 || isset($numbers[$p['staffNumber']])) throw new InvalidArgumentException('Employees need distinct employee numbers.');
            $numbers[$p['staffNumber']] = true;
        }
    }
    unset($p);
    $pdo->beginTransaction();
    try {
        $lock = $pdo->prepare('SELECT id,opened_at FROM elections WHERE id=? FOR UPDATE'); $lock->execute([$id]);
        $e = $lock->fetch();
        $settings = welfareSettings($pdo, $id);
        if (!$e || $e['opened_at'] || !empty($settings['armed_at'])) throw new RuntimeException('Disarm the schedule before changing accounts or the roll. Voting must not have opened.');
        foreach ($people as $p) {
            $find = $pdo->prepare('SELECT id FROM users WHERE email=?'); $find->execute([$p['email']]);
            $uid = (int)$find->fetchColumn();
            if (!$uid) {
                $pdo->prepare("INSERT INTO users (email,password_hash,email_verified,role) VALUES (?,?,0,'member')")
                    ->execute([$p['email'], password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT)]);
                $uid = (int)$pdo->lastInsertId();
            }
            if ($kind === 'employee') {
                $other = $pdo->prepare('SELECT user_id FROM staff_accounts WHERE staff_number=? AND user_id<>?'); $other->execute([$p['staffNumber'], $uid]);
                if ($other->fetch()) throw new RuntimeException('Employee number ' . $p['staffNumber'] . ' belongs to another account.');
                $pdo->prepare('INSERT INTO staff_accounts (user_id,full_name,staff_number,added_by) VALUES (?,?,?,?)
                    ON DUPLICATE KEY UPDATE full_name=VALUES(full_name),staff_number=VALUES(staff_number)')->execute([$uid,$p['name'],$p['staffNumber'],$admin]);
                $pdo->prepare('DELETE FROM welfare_guests WHERE election_id=? AND user_id=?')->execute([$id,$uid]);
            } else {
                $check = $pdo->prepare("SELECT user_id FROM staff_accounts WHERE user_id=? AND active=1 AND staff_number IS NOT NULL AND staff_number<>''"); $check->execute([$uid]);
                if ($check->fetch()) throw new RuntimeException($p['name'] . ' is already counted as an employee.');
                $pdo->prepare('INSERT INTO welfare_guests (election_id,user_id,name,added_by) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE name=VALUES(name)')->execute([$id,$uid,$p['name'],$admin]);
            }
        }
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    return ['processed' => count($people), 'readiness' => welfareReadiness($pdo,$id), 'message' => 'Accounts reconciled. New people must set a password through the existing invite or password-reset flow before the election can be armed.'];
}

function welfareAppendGuests(PDO $pdo, int $id): void
{
    if (!welfareReady($pdo)) return;
    $pdo->prepare("INSERT INTO election_roll (election_id,member_profile_id,user_id,name,membership_id,email,standing_at_cutoff)
        SELECT g.election_id,NULL,g.user_id,g.name,NULL,u.email,'welfare_guest' FROM welfare_guests g JOIN users u ON u.id=g.user_id
        WHERE g.election_id=? AND u.email_verified=1 AND NOT EXISTS
          (SELECT 1 FROM election_roll r WHERE r.election_id=g.election_id AND r.user_id=g.user_id)")->execute([$id]);
}

function welfareGuestMayView(PDO $pdo, int $uid): bool
{
    if (!welfareReady($pdo)) return false;
    $s=$pdo->prepare('SELECT 1 FROM welfare_guests WHERE user_id=? LIMIT 1'); $s->execute([$uid]);
    return (bool)$s->fetchColumn();
}

/** After-hours election access lasts through the window and 24 hours for reading results. */
function welfareDuringWindow(PDO $pdo, int $uid): bool
{
    if (!welfareReady($pdo)) return false;
    $s=$pdo->prepare("SELECT 1 FROM welfare_settings w JOIN elections e ON e.id=w.election_id
        JOIN election_roll r ON r.election_id=e.id WHERE r.user_id=? AND w.armed_at IS NOT NULL
        AND e.status<>'cancelled' AND NOW()>=e.nominations_open_at AND NOW()<=DATE_ADD(e.closes_at, INTERVAL 1 DAY) LIMIT 1");
    $s->execute([$uid]);
    return (bool)$s->fetchColumn();
}

/** Arm only a complete, reconciled roll; never invent voters to reach the target. */
function welfareArm(PDO $pdo, int $id, bool $arm): array
{
    $pdo->beginTransaction();
    try {
        $s=$pdo->prepare('SELECT * FROM elections WHERE id=? FOR UPDATE');$s->execute([$id]);$raw=$s->fetch();
        if (!$raw || $raw['opened_at']) throw new RuntimeException('The schedule cannot change once voting has opened.');
        if (!$arm) {
            $pdo->prepare('UPDATE welfare_settings SET armed_at=NULL,last_error=NULL WHERE election_id=?')->execute([$id]);
        } else {
            if (!welfareSettings($pdo,$id)) throw new RuntimeException('Set up the automatic election first.');
            if ($raw['status']==='cancelled') throw new RuntimeException('A cancelled election cannot be armed.');
            $r=welfareReadiness($pdo,$id);
            if (!$r['ready']) throw new RuntimeException('Exactly 32 employees and every additional voter must have an activated account. Reconcile the roster first.');
            if (!electionPositions($pdo,$id)) throw new RuntimeException('Add the contested posts first.');
            if (!$raw['nominations_open_at'] || !$raw['nominations_close_at']) throw new RuntimeException('Set nomination dates and voting dates first.');
            if (new DateTimeImmutable($raw['closes_at']) <= new DateTimeImmutable('now')) throw new RuntimeException('The voting window has already ended.');
            $pdo->prepare('DELETE FROM election_roll WHERE election_id=?')->execute([$id]);
            $pdo->prepare("INSERT INTO election_roll (election_id,member_profile_id,user_id,name,membership_id,email,standing_at_cutoff)
                SELECT ?,NULL,u.id,COALESCE(NULLIF(s.full_name,''),u.email),s.staff_number,u.email,'staff'
                FROM staff_accounts s JOIN users u ON u.id=s.user_id WHERE s.active=1 AND u.email_verified=1 AND s.staff_number IS NOT NULL AND s.staff_number<>''")->execute([$id]);
            welfareAppendGuests($pdo,$id);
            $count=$pdo->prepare('SELECT COUNT(*) FROM election_roll WHERE election_id=?');$count->execute([$id]);
            if ((int)$count->fetchColumn() !== $r['expectedVoters']) throw new RuntimeException('The voter roll does not match the expected employees and additional voters. Check duplicate accounts.');
            // Finality is an election rule, not a claim that nominees personally consented.
            $pdo->prepare("UPDATE election_candidates c JOIN election_positions p ON p.id=c.position_id SET c.status='approved' WHERE p.election_id=? AND c.status='nominated'")->execute([$id]);
            $pdo->prepare('UPDATE welfare_settings SET armed_at=NOW(),last_error=NULL WHERE election_id=?')->execute([$id]);
            welfareEvent($pdo,$id,'Schedule armed. The voter roll is fixed: 32 employees plus the listed additional voters.');
        }
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    return welfareReadiness($pdo,$id);
}

/** Runs from cron and authenticated election requests; each transition shares the ballot lock. */
function welfareRunScheduled(PDO $pdo): void
{
    if (!welfareReady($pdo)) return;
    $ids=$pdo->query("SELECT w.election_id FROM welfare_settings w JOIN elections e ON e.id=w.election_id
        WHERE w.armed_at IS NOT NULL AND e.status NOT IN ('published','cancelled')")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($ids as $id) {
        $pdo->beginTransaction();
        try {
            $s=$pdo->prepare('SELECT * FROM elections WHERE id=? FOR UPDATE');$s->execute([$id]);$raw=$s->fetch();
            $e=electionShape($raw);$now=new DateTimeImmutable('now');
            if ($now >= new DateTimeImmutable($raw['closes_at']) && !$e['openedAt']) throw new RuntimeException('The voting window elapsed before voting could open. Review the election; no result was invented.');
            if (in_array($e['status'],['draft','roll_published'],true) && $now >= new DateTimeImmutable($raw['nominations_open_at'])) {
                [$e,$error]=electionSetStatus($pdo,(int)$id,'nominations'); if($error)throw new RuntimeException($error);
                welfareEvent($pdo,(int)$id,'Nominations opened automatically. Nominations are final and approved automatically.');
            }
            if ($e['status']==='nominations' && $now >= new DateTimeImmutable($raw['nominations_close_at'])) {
                [$e,$error]=electionSetStatus($pdo,(int)$id,'nominations_closed');if($error)throw new RuntimeException($error);
                welfareEvent($pdo,(int)$id,'Nominations closed automatically.');
            }
            if ($e['status']==='nominations_closed' && $now >= new DateTimeImmutable($raw['opens_at'])) {
                [$e,$error]=electionSetStatus($pdo,(int)$id,'open');if($error)throw new RuntimeException($error);
                welfareEvent($pdo,(int)$id,'Voting opened automatically. Candidate totals are provisional until voting closes.');
            }
            if ($e['status']==='open' && $now >= new DateTimeImmutable($raw['closes_at'])) {
                [$e,$error]=electionSetStatus($pdo,(int)$id,'closed');if($error)throw new RuntimeException($error);
                welfareEvent($pdo,(int)$id,'Voting closed automatically.');
            }
            if ($e['status']==='closed') {
                [$result,$error]=electionDeclareResult($pdo,(int)$id,null,'Automatic welfare election declaration');
                if ($error) {
                    // Keep a tied election closed. A runoff is a new ballot, never a fabricated winner.
                    $pdo->prepare('UPDATE welfare_settings SET last_error=? WHERE election_id=?')->execute([mb_substr($error,0,500),$id]);
                } else {
                    [$e,$error]=electionSetStatus($pdo,(int)$id,'published');if($error)throw new RuntimeException($error);
                    $pdo->prepare('UPDATE welfare_settings SET last_error=NULL WHERE election_id=?')->execute([$id]);
                    welfareEvent($pdo,(int)$id,'Voting totals reconciled and final results published automatically.');
                }
            } else $pdo->prepare('UPDATE welfare_settings SET last_error=NULL WHERE election_id=?')->execute([$id]);
            $pdo->commit();
        } catch (Throwable $e) {
            if($pdo->inTransaction())$pdo->rollBack();
            $pdo->prepare('UPDATE welfare_settings SET last_error=? WHERE election_id=?')->execute([mb_substr($e->getMessage(),0,500),$id]);
        }
    }
}

function welfareHandle(PDO $pdo, array $config, string $route, string $method): void
{
    $user=auth($config);
    if ($route==='welfare/me' && $method==='GET') {
        $s=$pdo->prepare('SELECT s.full_name FROM staff_accounts s WHERE user_id=?');$s->execute([(int)$user['userId']]);
        respond(200,['me'=>['name'=>$s->fetchColumn()?:$user['email'],'email'=>$user['email']]]);
    }
    if ($route==='welfare/live' && $method==='GET') {
        if (!headers_sent()) header('Cache-Control: no-store');
        welfareRunScheduled($pdo);
        respond(200,welfareLive($pdo,(int)$user['userId']));
    }
    requireSuperAdmin($user,$config);
    if (!welfareReady($pdo)) respond(503,['error'=>'Apply migration-2026-11-03-welfare-automation.sql first.']);
    if (!preg_match('#^admin/welfare/(\d+)(?:/(configure|import|arm|disarm|remove-guest))?$#',$route,$m)) respond(404,['error'=>'No such welfare action.']);
    $id=(int)$m[1];$e=electionFind($pdo,(string)$id);
    if (!$e || $e['electorate']!=='staff') respond(404,['error'=>'Choose a staff welfare election.']);
    if (!staffPortalEnsureTables($pdo)) respond(503,['error'=>'Staff records are unavailable.']);
    $action=$m[2]??'';
    if ($method==='GET' && $action==='') respond(200,['settings'=>welfareSettings($pdo,$id),'readiness'=>welfareReadiness($pdo,$id),'guests'=>welfareGuests($pdo,$id)]);
    if ($method!=='POST') respond(405,['error'=>'Use POST for changes.']);
    $data=input();
    try {
        if ($action==='arm' || $action==='disarm') $result=['readiness'=>welfareArm($pdo,$id,$action==='arm')];
        elseif ($action==='configure') {
            if ($e['locked']) throw new RuntimeException('Voting has already opened.');
            $pdo->prepare('INSERT IGNORE INTO welfare_settings (election_id,staff_target,configured_by) VALUES (?,32,?)')->execute([$id,(int)$user['userId']]);
            $result=['readiness'=>welfareReadiness($pdo,$id)];
        } elseif ($action==='import') $result=welfareImport($pdo,$id,is_array($data['people']??null)?$data['people']:[],(string)($data['kind']??''),(int)$user['userId']);
        elseif ($action==='remove-guest') {
            if ($e['locked'] || !empty(welfareSettings($pdo,$id)['armed_at'])) throw new RuntimeException('Disarm the election before changing voters.');
            $pdo->prepare('DELETE FROM welfare_guests WHERE election_id=? AND user_id=?')->execute([$id,(int)($data['userId']??0)]);
            $result=['readiness'=>welfareReadiness($pdo,$id)];
        } else respond(404,['error'=>'No such welfare action.']);
        logAdminAction($pdo,(int)$user['userId'],'welfare_'.$action,null,'Election '.$id);
        respond(200,$result);
    } catch (InvalidArgumentException | RuntimeException $err) { respond(400,['error'=>$err->getMessage()]); }
}
