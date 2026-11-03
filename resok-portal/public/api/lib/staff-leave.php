<?php
declare(strict_types=1);

/** Staff leave requests. Types are configured by a super administrator; no allowances are assumed. */
function staffLeaveEnsureTables(PDO $pdo): bool
{
    static $ready = null;
    if ($ready !== null) return $ready;
    try {
        $tail = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        $pdo->exec("CREATE TABLE IF NOT EXISTS staff_leave_types (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT, name VARCHAR(120) NOT NULL,
            description VARCHAR(1000) NULL, active TINYINT(1) NOT NULL DEFAULT 1,
            created_by INT UNSIGNED NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY(id), UNIQUE KEY staff_leave_type_name(name)
        )" . $tail);
        $pdo->exec("CREATE TABLE IF NOT EXISTS staff_leave_requests (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT, user_id INT UNSIGNED NOT NULL,
            type_id INT UNSIGNED NOT NULL, type_name VARCHAR(120) NOT NULL,
            starts_on DATE NOT NULL, ends_on DATE NOT NULL, reason VARCHAR(1000) NULL,
            status ENUM('pending','approved','declined','cancelled') NOT NULL DEFAULT 'pending',
            decision_note VARCHAR(1000) NULL, decided_by INT UNSIGNED NULL, decided_at DATETIME NULL,
            cancelled_by INT UNSIGNED NULL, cancelled_at DATETIME NULL, cancellation_note VARCHAR(1000) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY(id), KEY staff_leave_owner(user_id,starts_on,ends_on), KEY staff_leave_queue(status,created_at)
        )" . $tail);
        return $ready = true;
    } catch (Throwable $error) {
        error_log('Staff leave tables unavailable: ' . $error->getMessage());
        return $ready = false;
    }
}

function staffLeaveTypes(PDO $pdo, bool $all): array
{
    return $pdo->query('SELECT id,name,description,active FROM staff_leave_types' . ($all ? '' : ' WHERE active=1') . ' ORDER BY name')->fetchAll();
}

function staffLeaveText($value): ?string
{
    if (!is_string($value) && $value !== null) throw new InvalidArgumentException('Send notes as text.');
    $value = trim((string)$value);
    if (mb_strlen($value) > 1000) throw new InvalidArgumentException('Keep notes within 1,000 characters.');
    return $value === '' ? null : $value;
}

function staffLeaveDate($value): string
{
    if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D',$value)) throw new InvalidArgumentException('Choose valid leave dates.');
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    if (!$date || $date->format('Y-m-d') !== $value) throw new InvalidArgumentException('Choose valid leave dates.');
    return $value;
}

function staffLeaveRows(PDO $pdo, ?int $owner = null, bool $pendingOnly = false): array
{
    $where = $owner === null ? [] : ['r.user_id=?'];
    if ($pendingOnly) $where[] = "r.status='pending'";
    $query = $pdo->prepare("SELECT r.id,r.user_id AS userId,r.type_id AS typeId,r.type_name AS type,
        r.starts_on AS startsOn,r.ends_on AS endsOn,r.reason,r.status,r.decision_note AS decisionNote,
        r.decided_at AS decidedAt,r.cancelled_at AS cancelledAt,r.cancellation_note AS cancellationNote,
        r.created_at AS createdAt,COALESCE(NULLIF(s.full_name,''),u.email) AS person,
        COALESCE(NULLIF(d.full_name,''),du.email) AS decidedBy
        FROM staff_leave_requests r JOIN users u ON u.id=r.user_id LEFT JOIN staff_accounts s ON s.user_id=r.user_id
        LEFT JOIN users du ON du.id=r.decided_by LEFT JOIN staff_accounts d ON d.user_id=r.decided_by" .
        ($where ? ' WHERE ' . implode(' AND ',$where) : '') .
        ($pendingOnly ? ' ORDER BY r.created_at,r.id LIMIT 300' : ' ORDER BY r.created_at DESC,r.id DESC LIMIT 300'));
    $query->execute($owner === null ? [] : [$owner]);
    return $query->fetchAll();
}

/** Lock the staff row so concurrent submissions cannot create overlapping active requests. */
function staffLeaveSubmit(PDO $pdo, int $userId, array $data): int
{
    $start = staffLeaveDate($data['startsOn'] ?? null); $end = staffLeaveDate($data['endsOn'] ?? null);
    if ($start < date('Y-m-d') || $end < $start) throw new InvalidArgumentException('Choose a start date from today onward and an end date on or after it.');
    $reason = staffLeaveText($data['reason'] ?? null);
    $pdo->beginTransaction();
    try {
        $staff = $pdo->prepare('SELECT user_id FROM staff_accounts WHERE user_id=? AND active=1 FOR UPDATE'); $staff->execute([$userId]);
        if (!$staff->fetchColumn()) throw new DomainException('An active employee record is required to request leave.');
        $type = $pdo->prepare('SELECT id,name FROM staff_leave_types WHERE id=? AND active=1 FOR UPDATE'); $type->execute([(int)($data['typeId'] ?? 0)]);
        $leaveType = $type->fetch();
        if (!$leaveType) throw new InvalidArgumentException('Choose an available leave type.');
        $overlap = $pdo->prepare("SELECT id FROM staff_leave_requests WHERE user_id=? AND status IN ('pending','approved') AND starts_on<=? AND ends_on>=? LIMIT 1");
        $overlap->execute([$userId,$end,$start]);
        if ($overlap->fetchColumn()) throw new InvalidArgumentException('These dates overlap an existing pending or approved leave request.');
        $pdo->prepare('INSERT INTO staff_leave_requests(user_id,type_id,type_name,starts_on,ends_on,reason) VALUES(?,?,?,?,?,?)')
            ->execute([$userId,$leaveType['id'],$leaveType['name'],$start,$end,$reason]);
        $id = (int)$pdo->lastInsertId();
        staffAudit($pdo,$userId,'leave_requested','leave',(string)$id,$leaveType['name'] . ': ' . $start . ' to ' . $end);
        $pdo->commit();
        return $id;
    } catch (Throwable $error) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $error; }
}

function staffLeaveChange(PDO $pdo, array $gate, int $actor, int $id, string $action, array $data): array
{
    if (!in_array($action,['approve','decline','cancel'],true)) throw new InvalidArgumentException('No such leave action.');
    $note = staffLeaveText($data['note'] ?? null);
    if ($action === 'decline' && $note === null) throw new InvalidArgumentException('Give the employee a reason for declining the request.');
    $pdo->beginTransaction();
    try {
        $query = $pdo->prepare('SELECT * FROM staff_leave_requests WHERE id=?' . (staffGateAdmin($gate) ? '' : ' AND user_id=?') . ' FOR UPDATE');
        $query->execute(staffGateAdmin($gate) ? [$id] : [$id,$actor]); $request = $query->fetch();
        if (!$request) throw new DomainException('No such leave request.');
        if ($action === 'cancel') {
            if (!in_array($request['status'], ['pending','approved'], true)) throw new InvalidArgumentException('This request is already declined or cancelled.');
            if ($request['ends_on'] < date('Y-m-d')) throw new InvalidArgumentException('Completed leave cannot be cancelled.');
            if ((int)$request['user_id'] !== $actor && $note === null) throw new InvalidArgumentException('Give a reason for cancelling another employee\'s leave.');
            $status = 'cancelled';
            $pdo->prepare("UPDATE staff_leave_requests SET status='cancelled',cancelled_by=?,cancelled_at=NOW(),cancellation_note=? WHERE id=?")->execute([$actor,$note,$id]);
        } else {
            if (!staffGateAdmin($gate)) throw new InvalidArgumentException('Only a staff administrator decides leave requests.');
            if ($request['status'] !== 'pending') throw new InvalidArgumentException('This request is no longer waiting for a decision.');
            $status = $action === 'approve' ? 'approved' : 'declined';
            $pdo->prepare('UPDATE staff_leave_requests SET status=?,decided_by=?,decided_at=NOW(),decision_note=? WHERE id=?')->execute([$status,$actor,$note,$id]);
        }
        staffAudit($pdo,$actor,'leave_' . $status,'leave',(string)$id,$request['type_name'] . ': ' . $request['starts_on'] . ' to ' . $request['ends_on']);
        $pdo->commit();
        return ['userId' => (int)$request['user_id'], 'status' => $status];
    } catch (Throwable $error) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $error; }
}

function staffLeaveHandle(PDO $pdo, array $config, string $route, string $method, array $user, array $gate): void
{
    if (!staffLeaveEnsureTables($pdo)) respond(503, ['error' => 'Leave records are unavailable.']);
    $me = (int)$user['userId'];
    if ($route === 'staff/leave' && $method === 'GET') {
        respond(200, ['mine' => staffLeaveRows($pdo,$me), 'requests' => staffGateAdmin($gate) ? staffLeaveRows($pdo) : [],
            'pending' => staffGateAdmin($gate) ? staffLeaveRows($pdo,null,true) : [],
            'pendingCount' => staffGateAdmin($gate) ? (int)$pdo->query("SELECT COUNT(*) FROM staff_leave_requests WHERE status='pending'")->fetchColumn() : 0,
            'types' => staffLeaveTypes($pdo,staffGateAdmin($gate)), 'canManage' => staffGateAdmin($gate), 'onStaffList' => (bool)$gate['row'],
            'today' => date('Y-m-d')]);
    }
    try {
        if ($route === 'staff/leave' && $method === 'POST') {
            if (!$gate['row']) respond(403, ['error' => 'Only employees request leave. You can manage requests without an employee record.']);
            $id = staffLeaveSubmit($pdo,$me,input());
            foreach ($pdo->query("SELECT DISTINCT u.id,u.email,u.role FROM users u LEFT JOIN staff_accounts s ON s.user_id=u.id AND s.active=1 LEFT JOIN staff_grants g ON g.user_id=s.user_id AND g.capability='staff.admin' WHERE u.role='admin' OR g.user_id IS NOT NULL")->fetchAll() as $admin) {
                if (staffIsAdmin($pdo,['userId'=>(int)$admin['id']]+$admin,$config)) staffNotify($pdo,(int)$admin['id'],'leave','A staff leave request is waiting for your decision','#leave-manage','leave-request:' . $id);
            }
            respond(201, ['id' => $id, 'message' => 'Leave request submitted.']);
        }
        if ($route === 'staff/leave/types' && $method === 'POST') {
            if (!staffGateAdmin($gate)) respond(403, ['error' => 'Only a staff administrator configures leave types.']);
            $data = input(); $name = staffLeaveText($data['name'] ?? null);
            if ($name === null || mb_strlen($name) > 120) throw new InvalidArgumentException('Give the leave type a name within 120 characters.');
            $description = staffLeaveText($data['description'] ?? null); $id = (int)($data['id'] ?? 0);
            $active = empty($data['inactive']) ? 1 : 0;
            if ($id) {
                $exists = $pdo->prepare('SELECT id FROM staff_leave_types WHERE id=?'); $exists->execute([$id]);
                if (!$exists->fetchColumn()) throw new DomainException('No such leave type.');
                $pdo->prepare('UPDATE staff_leave_types SET name=?,description=?,active=? WHERE id=?')->execute([$name,$description,$active,$id]);
            } else {
                $pdo->prepare('INSERT INTO staff_leave_types(name,description,active,created_by) VALUES(?,?,?,?)')->execute([$name,$description,$active,$me]);
                $id = (int)$pdo->lastInsertId();
            }
            staffAudit($pdo,$me,'leave_type_saved','leave_type',(string)$id,$name);
            respond(200, ['types' => staffLeaveTypes($pdo,true)]);
        }
        if (preg_match('#^staff/leave/(\d+)/(approve|decline|cancel)$#',$route,$match) && $method === 'POST') {
            if ($match[2] !== 'cancel' && !staffGateAdmin($gate)) respond(403, ['error' => 'Only a staff administrator decides leave requests.']);
            $result = staffLeaveChange($pdo,$gate,$me,(int)$match[1],$match[2],input());
            staffNotify($pdo,$result['userId'],'leave','Your leave request was ' . $result['status'],'#leave','leave-decision:' . $match[1] . ':' . $result['status']);
            respond(200, ['status' => $result['status']]);
        }
    } catch (DomainException $error) { respond(404, ['error' => $error->getMessage()]); }
    catch (InvalidArgumentException $error) { respond(400, ['error' => $error->getMessage()]); }
    catch (PDOException $error) {
        if ($error->getCode() === '23000') respond(400, ['error' => 'A leave type with that name already exists.']);
        throw $error;
    }
    respond(404, ['error' => 'No such leave action.']);
}
