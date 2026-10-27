<?php
declare(strict_types=1);

/**
 * Procurement: purchase requests, their approval, quotations, purchase orders (LPOs), goods
 * received, and the supplier register.
 *
 * The path of a request:
 *
 *   draft -> submitted -> (line manager) -> manager_approved -> (finance) -> approved
 *         -> ordered (LPO issued to the chosen quote) -> received (goods received note) -> closed (paid)
 *
 *   A manager or finance can return a request for changes (back to "returned", which the
 *   requester edits and resubmits) or reject it outright. The requester can cancel until it
 *   is approved. Someone with no line manager on record goes straight to finance.
 *
 * Rules that shape it:
 *
 * 1. Separation of duties. Nobody approves their own request at either step. The person who
 *    issued the LPO does not confirm the goods arrived. Finance approval and LPOs need
 *    procurement.approve.
 *
 * 2. Quotations by value. Under PROCUREMENT_THREE_QUOTES_FROM one quote is enough; from there
 *    three are needed before an LPO; from PROCUREMENT_TENDER_FROM the LPO also needs the
 *    tender committee's reference. An LPO may not exceed the approved total by more than
 *    PROCUREMENT_LPO_TOLERANCE without going back for approval. These thresholds are my
 *    defaults for the review, not ReSoK's finance policy.
 *
 * 3. Need to see: the requester; their reporting line; finance (procurement.approve) and
 *    super admins see all. Every step is kept in a timeline on the request.
 */

const PROCUREMENT_THREE_QUOTES_FROM = 50000;
const PROCUREMENT_TENDER_FROM = 1000000;
const PROCUREMENT_LPO_TOLERANCE = 0.10;

const PROCUREMENT_STATUSES = [
    'draft' => 'Draft', 'submitted' => 'With line manager', 'manager_approved' => 'With finance', 'approved' => 'Approved',
    'ordered' => 'Ordered', 'received' => 'Received', 'closed' => 'Closed', 'returned' => 'Returned for changes',
    'rejected' => 'Rejected', 'cancelled' => 'Cancelled',
];

function procurementEnsureTables(PDO $pdo): bool
{
    static $ready = null;
    if ($ready !== null) return $ready;
    if (!timesheetEnsureTables($pdo)) return $ready = false;
    try {
        $tail = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        $pdo->exec("CREATE TABLE IF NOT EXISTS staff_suppliers (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(160) NOT NULL,
            kra_pin VARCHAR(20) NULL,
            contact_person VARCHAR(120) NULL,
            phone VARCHAR(40) NULL,
            email VARCHAR(160) NULL,
            category VARCHAR(80) NULL,
            notes VARCHAR(500) NULL,
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_by INT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY staff_suppliers_name (name)
        )" . $tail);
        $pdo->exec("CREATE TABLE IF NOT EXISTS staff_procurement_requests (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            ref VARCHAR(20) NULL,
            requester_user_id INT UNSIGNED NOT NULL,
            department_id INT UNSIGNED NULL,
            charge_code_id INT UNSIGNED NULL,
            title VARCHAR(200) NOT NULL,
            justification TEXT NULL,
            needed_by DATE NULL,
            total DECIMAL(14,2) NOT NULL DEFAULT 0,
            status ENUM('draft','submitted','manager_approved','approved','ordered','received','closed','returned','rejected','cancelled') NOT NULL DEFAULT 'draft',
            manager_user_id INT UNSIGNED NULL,
            manager_decided_by INT UNSIGNED NULL,
            finance_decided_by INT UNSIGNED NULL,
            supplier_id INT UNSIGNED NULL,
            lpo_number VARCHAR(20) NULL,
            lpo_amount DECIMAL(14,2) NULL,
            tender_ref VARCHAR(120) NULL,
            ordered_by INT UNSIGNED NULL,
            ordered_at DATETIME NULL,
            received_by INT UNSIGNED NULL,
            received_at DATETIME NULL,
            payment_ref VARCHAR(120) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY staff_procurement_ref (ref),
            UNIQUE KEY staff_procurement_lpo (lpo_number),
            KEY staff_procurement_status (status),
            KEY staff_procurement_requester (requester_user_id)
        )" . $tail);
        $pdo->exec("CREATE TABLE IF NOT EXISTS staff_procurement_items (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            request_id INT UNSIGNED NOT NULL,
            description VARCHAR(300) NOT NULL,
            quantity DECIMAL(10,2) NOT NULL,
            unit VARCHAR(20) NULL,
            unit_cost DECIMAL(12,2) NOT NULL,
            PRIMARY KEY (id),
            KEY staff_procurement_items_req (request_id)
        )" . $tail);
        $pdo->exec("CREATE TABLE IF NOT EXISTS staff_procurement_quotes (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            request_id INT UNSIGNED NOT NULL,
            supplier_id INT UNSIGNED NOT NULL,
            amount DECIMAL(14,2) NOT NULL,
            valid_until DATE NULL,
            note VARCHAR(300) NULL,
            added_by INT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY staff_procurement_quotes_req (request_id)
        )" . $tail);
        $pdo->exec("CREATE TABLE IF NOT EXISTS staff_procurement_log (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            request_id INT UNSIGNED NOT NULL,
            actor_user_id INT UNSIGNED NULL,
            action VARCHAR(40) NOT NULL,
            note VARCHAR(500) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY staff_procurement_log_req (request_id)
        )" . $tail);
        return $ready = true;
    } catch (Throwable $e) {
        error_log('Procurement tables unavailable: ' . $e->getMessage());
        return $ready = false;
    }
}

function procurementLog(PDO $pdo, int $requestId, ?int $actor, string $action, ?string $note = null): void
{
    $pdo->prepare('INSERT INTO staff_procurement_log (request_id, actor_user_id, action, note) VALUES (?, ?, ?, ?)')
        ->execute([$requestId, $actor, $action, $note === null ? null : mb_substr($note, 0, 500)]);
}

/** Quotes required for an amount, and whether a tender reference is too. */
function procurementQuotesNeeded(float $total): array
{
    if ($total >= PROCUREMENT_TENDER_FROM) return [3, true];
    if ($total >= PROCUREMENT_THREE_QUOTES_FROM) return [3, false];
    return [1, false];
}

/** The next number in a yearly series: PR-2026-0001, LPO-2026-0001. */
function procurementNextNumber(PDO $pdo, string $column, string $prefix): string
{
    $year = date('Y');
    $stmt = $pdo->prepare("SELECT MAX(CAST(SUBSTRING_INDEX($column, '-', -1) AS UNSIGNED)) FROM staff_procurement_requests WHERE $column LIKE ?");
    $stmt->execute([$prefix . '-' . $year . '-%']);
    return sprintf('%s-%s-%04d', $prefix, $year, (int)$stmt->fetchColumn() + 1);
}

function procurementRow(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare("SELECT r.*, COALESCE(NULLIF(s.full_name, ''), u.email) AS requester, d.name AS department, c.code AS charge_code, c.name AS charge_name,
                                  sup.name AS supplier, m.full_name AS manager_name, ob.full_name AS ordered_by_name
                           FROM staff_procurement_requests r JOIN users u ON u.id = r.requester_user_id
                           LEFT JOIN staff_accounts s ON s.user_id = r.requester_user_id LEFT JOIN staff_departments d ON d.id = r.department_id
                           LEFT JOIN staff_charge_codes c ON c.id = r.charge_code_id LEFT JOIN staff_suppliers sup ON sup.id = r.supplier_id
                           LEFT JOIN staff_accounts m ON m.user_id = r.manager_user_id LEFT JOIN staff_accounts ob ON ob.user_id = r.ordered_by
                           WHERE r.id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

function procurementShape(PDO $pdo, array $r, bool $full): array
{
    $out = [
        'id' => (int)$r['id'], 'ref' => $r['ref'], 'title' => $r['title'], 'status' => $r['status'], 'statusLabel' => PROCUREMENT_STATUSES[$r['status']] ?? $r['status'],
        'requesterId' => (int)$r['requester_user_id'], 'requester' => $r['requester'], 'department' => $r['department'],
        'chargeCodeId' => $r['charge_code_id'] === null ? null : (int)$r['charge_code_id'], 'chargeCode' => $r['charge_code'], 'chargeName' => $r['charge_name'],
        'neededBy' => $r['needed_by'], 'total' => (float)$r['total'], 'manager' => $r['manager_name'],
        'supplier' => $r['supplier'], 'lpoNumber' => $r['lpo_number'], 'lpoAmount' => $r['lpo_amount'] === null ? null : (float)$r['lpo_amount'],
        'orderedAt' => $r['ordered_at'], 'receivedAt' => $r['received_at'], 'createdAt' => $r['created_at'], 'updatedAt' => $r['updated_at'],
    ];
    if (!$full) return $out;
    $id = (int)$r['id'];
    $items = $pdo->prepare('SELECT * FROM staff_procurement_items WHERE request_id = ? ORDER BY id');
    $items->execute([$id]);
    $quotes = $pdo->prepare('SELECT q.*, s.name AS supplier FROM staff_procurement_quotes q JOIN staff_suppliers s ON s.id = q.supplier_id WHERE q.request_id = ? ORDER BY q.amount');
    $quotes->execute([$id]);
    $log = $pdo->prepare("SELECT l.*, COALESCE(NULLIF(s.full_name, ''), u.email) AS actor FROM staff_procurement_log l LEFT JOIN users u ON u.id = l.actor_user_id
                          LEFT JOIN staff_accounts s ON s.user_id = l.actor_user_id WHERE l.request_id = ? ORDER BY l.id");
    $log->execute([$id]);
    [$needed, $tender] = procurementQuotesNeeded((float)$r['total']);
    return $out + [
        'justification' => $r['justification'], 'tenderRef' => $r['tender_ref'], 'paymentRef' => $r['payment_ref'], 'orderedBy' => $r['ordered_by_name'],
        'orderedById' => $r['ordered_by'] === null ? null : (int)$r['ordered_by'],
        'items' => array_map(static fn($i) => ['description' => $i['description'], 'quantity' => (float)$i['quantity'], 'unit' => $i['unit'],
            'unitCost' => (float)$i['unit_cost'], 'amount' => round((float)$i['quantity'] * (float)$i['unit_cost'], 2)], $items->fetchAll()),
        'quotes' => array_map(static fn($q) => ['id' => (int)$q['id'], 'supplierId' => (int)$q['supplier_id'], 'supplier' => $q['supplier'],
            'amount' => (float)$q['amount'], 'validUntil' => $q['valid_until'], 'note' => $q['note']], $quotes->fetchAll()),
        'quotesNeeded' => $needed, 'tenderNeeded' => $tender,
        'timeline' => array_map(static fn($l) => ['action' => $l['action'], 'note' => $l['note'], 'by' => $l['actor'], 'at' => $l['created_at']], $log->fetchAll()),
    ];
}

/** Requests waiting for this person: as line manager, and (with procurement.approve) as finance. */
function procurementQueue(PDO $pdo, array $gate, int $me): array
{
    if (!procurementEnsureTables($pdo)) return [];
    $finance = $gate['super'] || in_array('procurement.approve', $gate['caps'], true);
    $sql = "SELECT id FROM staff_procurement_requests WHERE requester_user_id <> ? AND ((status = 'submitted' AND manager_user_id = ?)"
         . ($finance ? " OR status IN ('manager_approved','approved','received')" : '') . ') ORDER BY updated_at';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$me, $me]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

function procurementTodo(PDO $pdo, array $gate, int $me): int
{
    if (!procurementEnsureTables($pdo)) return 0;
    $finance = $gate['super'] || in_array('procurement.approve', $gate['caps'], true);
    // Counts decisions only: approvals waiting, not orders to place.
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM staff_procurement_requests WHERE requester_user_id <> ? AND ((status = 'submitted' AND manager_user_id = ?)"
        . ($finance ? " OR status = 'manager_approved'" : '') . ')');
    $stmt->execute([$me, $me]);
    return (int)$stmt->fetchColumn();
}

function procurementSuppliers(PDO $pdo, bool $includeInactive): array
{
    $rows = $pdo->query('SELECT * FROM staff_suppliers' . ($includeInactive ? '' : ' WHERE active = 1') . ' ORDER BY name')->fetchAll();
    return array_map(static fn($s) => ['id' => (int)$s['id'], 'name' => $s['name'], 'kraPin' => $s['kra_pin'], 'contactPerson' => $s['contact_person'],
        'phone' => $s['phone'], 'email' => $s['email'], 'category' => $s['category'], 'notes' => $s['notes'], 'active' => (bool)$s['active']], $rows);
}

function procurementHandle(PDO $pdo, array $config, string $route, string $method, array $user, array $gate): void
{
    if (!procurementEnsureTables($pdo)) respond(503, ['error' => 'The procurement tables could not be created.']);
    $me = (int)$user['userId'];
    $finance = $gate['super'] || in_array('procurement.approve', $gate['caps'], true);
    $line = fieldVisiblePeople($pdo, $me, false);
    $name = static fn() => $line[$me] ?? 'A colleague';

    $load = function (int $id) use ($pdo, $me, $finance, $line): array {
        $r = procurementRow($pdo, $id);
        if (!$r) respond(404, ['error' => 'No such request.']);
        $mine = (int)$r['requester_user_id'] === $me;
        if (!$mine && !$finance && !isset($line[(int)$r['requester_user_id']]) && (int)$r['manager_user_id'] !== $me) respond(404, ['error' => 'No such request.']);
        return $r;
    };
    $notifyFinance = function (array $r, string $title) use ($pdo, $me): void {
        $ids = $pdo->query("SELECT g.user_id FROM staff_grants g JOIN staff_accounts s ON s.user_id = g.user_id AND s.active = 1 WHERE g.capability = 'procurement.approve'")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($ids as $u) if ((int)$u !== (int)$r['requester_user_id']) staffNotify($pdo, (int)$u, 'procurement', $title, '#procurement-' . $r['id'], 'pr:' . $r['id'] . ':' . $r['status'] . ':' . time());
    };

    // ----- lists -----
    if ($route === 'staff/procurement' && $method === 'GET') {
        $scope = (string)($_GET['scope'] ?? 'mine');
        if ($scope === 'queue') {
            $ids = procurementQueue($pdo, $gate, $me);
        } elseif ($scope === 'all' && $finance) {
            $where = [];
            $args = [];
            if (!empty($_GET['status']) && isset(PROCUREMENT_STATUSES[$_GET['status']])) { $where[] = 'status = ?'; $args[] = $_GET['status']; }
            $stmt = $pdo->prepare('SELECT id FROM staff_procurement_requests' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY id DESC LIMIT 300');
            $stmt->execute($args);
            $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        } elseif ($scope === 'team') {
            $people = array_values(array_diff(array_keys($line), [$me]));
            $ids = [];
            if ($people) {
                $stmt = $pdo->prepare('SELECT id FROM staff_procurement_requests WHERE status <> \'draft\' AND requester_user_id IN (' . implode(',', array_fill(0, count($people), '?')) . ') ORDER BY id DESC LIMIT 300');
                $stmt->execute($people);
                $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
            }
        } else {
            $stmt = $pdo->prepare('SELECT id FROM staff_procurement_requests WHERE requester_user_id = ? ORDER BY id DESC LIMIT 200');
            $stmt->execute([$me]);
            $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        }
        $rows = [];
        foreach ($ids as $id) if ($r = procurementRow($pdo, $id)) $rows[] = procurementShape($pdo, $r, false);
        respond(200, ['requests' => $rows, 'finance' => $finance, 'hasTeam' => count($line) > 1, 'onStaffList' => (bool)$gate['row'],
            'codes' => staffChargeCodes($pdo), 'statuses' => PROCUREMENT_STATUSES, 'toDecide' => procurementTodo($pdo, $gate, $me),
            'thresholds' => ['threeQuotesFrom' => PROCUREMENT_THREE_QUOTES_FROM, 'tenderFrom' => PROCUREMENT_TENDER_FROM, 'lpoTolerance' => PROCUREMENT_LPO_TOLERANCE]]);
    }

    // ----- suppliers -----
    if ($route === 'staff/procurement/suppliers' && $method === 'GET') {
        if (!$finance) respond(403, ['error' => 'The supplier register is kept by finance.']);
        respond(200, ['suppliers' => procurementSuppliers($pdo, true)]);
    }
    if ($route === 'staff/procurement/suppliers' && $method === 'POST') {
        if (!$finance) respond(403, ['error' => 'The supplier register is kept by finance.']);
        $d = input();
        $id = (int)($d['id'] ?? 0);
        $sname = fieldText($d['name'] ?? '', 160);
        if ($sname === null) respond(400, ['error' => 'Give the supplier\'s name.']);
        $pin = strtoupper((string)fieldText($d['kraPin'] ?? '', 20));
        if ($pin !== '' && !preg_match('/^[AP]\d{9}[A-Z]$/', $pin)) respond(400, ['error' => 'A KRA PIN looks like P051234567X.']);
        $email = fieldText($d['email'] ?? '', 160);
        if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) respond(400, ['error' => 'Check the email address.']);
        $dup = $pdo->prepare('SELECT id FROM staff_suppliers WHERE name = ? AND id <> ?');
        $dup->execute([$sname, $id]);
        if ($dup->fetchColumn()) respond(400, ['error' => 'A supplier with that name is already registered.']);
        $args = [$sname, $pin ?: null, fieldText($d['contactPerson'] ?? '', 120), fieldText($d['phone'] ?? '', 40), $email,
                 fieldText($d['category'] ?? '', 80), fieldText($d['notes'] ?? '', 500), empty($d['inactive']) ? 1 : 0];
        if ($id) $pdo->prepare('UPDATE staff_suppliers SET name = ?, kra_pin = ?, contact_person = ?, phone = ?, email = ?, category = ?, notes = ?, active = ? WHERE id = ?')->execute(array_merge($args, [$id]));
        else $pdo->prepare('INSERT INTO staff_suppliers (name, kra_pin, contact_person, phone, email, category, notes, active, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute(array_merge($args, [$me]));
        staffAudit($pdo, $me, 'supplier_saved', 'supplier', (string)($id ?: $pdo->lastInsertId()), $sname);
        respond(200, ['suppliers' => procurementSuppliers($pdo, true)]);
    }

    // ----- create or edit (requester, while draft or returned) -----
    if (preg_match('#^staff/procurement/(new|\d+)/save$#', $route, $m) && $method === 'POST') {
        if (!$gate['row']) respond(403, ['error' => 'Only staff raise purchase requests.']);
        $existing = null;
        if ($m[1] !== 'new') {
            $existing = $load((int)$m[1]);
            if ((int)$existing['requester_user_id'] !== $me) respond(403, ['error' => 'Only the person who raised a request edits it.']);
            if (!in_array($existing['status'], ['draft', 'returned'], true)) respond(400, ['error' => 'This request is ' . strtolower(PROCUREMENT_STATUSES[$existing['status']]) . ' and can no longer be edited.']);
        }
        $d = input();
        $title = fieldText($d['title'] ?? '', 200);
        if ($title === null) respond(400, ['error' => 'Say in a few words what is being bought.']);
        $codes = array_column(staffChargeCodes($pdo), null, 'id');
        $code = isset($d['chargeCodeId']) && $d['chargeCodeId'] !== '' ? (int)$d['chargeCodeId'] : null;
        if ($code !== null && !isset($codes[$code])) respond(400, ['error' => 'Choose the project or budget line from the list.']);
        $needed = (string)($d['neededBy'] ?? '');
        if ($needed !== '' && !DateTimeImmutable::createFromFormat('!Y-m-d', $needed)) respond(400, ['error' => 'Check the date needed.']);
        $items = [];
        foreach ((array)($d['items'] ?? []) as $it) {
            if (!is_array($it)) continue;
            $desc = fieldText($it['description'] ?? '', 300);
            if ($desc === null) continue;
            $qty = $it['quantity'] ?? null;
            $cost = $it['unitCost'] ?? null;
            if (!is_numeric($qty) || (float)$qty <= 0 || (float)$qty > 1000000) respond(400, ['error' => "Give a quantity for \"$desc\"."]);
            if (!is_numeric($cost) || (float)$cost < 0 || (float)$cost > 100000000) respond(400, ['error' => "Give an estimated unit cost for \"$desc\"."]);
            $items[] = [$desc, round((float)$qty, 2), fieldText($it['unit'] ?? '', 20), round((float)$cost, 2)];
        }
        if (count($items) > 50) respond(400, ['error' => 'Use at most 50 items; split a larger purchase.']);
        $submit = !empty($d['submit']);
        if ($submit && !$items) respond(400, ['error' => 'List at least one item before submitting.']);
        $justification = fieldText($d['justification'] ?? '', 4000);
        if ($submit && $justification === null) respond(400, ['error' => 'Say why it is needed before submitting.']);
        if ($submit && $code === null) respond(400, ['error' => 'Choose the project or budget line before submitting.']);
        $total = round(array_sum(array_map(static fn($i) => $i[1] * $i[3], $items)), 2);
        $dept = $gate['row']['department_id'] ?? null;

        $pdo->beginTransaction();
        if ($existing) {
            $id = (int)$existing['id'];
            $pdo->prepare('UPDATE staff_procurement_requests SET title = ?, justification = ?, charge_code_id = ?, needed_by = ?, total = ?, department_id = ? WHERE id = ?')
                ->execute([$title, $justification, $code, $needed ?: null, $total, $dept, $id]);
            $pdo->prepare('DELETE FROM staff_procurement_items WHERE request_id = ?')->execute([$id]);
        } else {
            $pdo->prepare('INSERT INTO staff_procurement_requests (requester_user_id, department_id, charge_code_id, title, justification, needed_by, total) VALUES (?, ?, ?, ?, ?, ?, ?)')
                ->execute([$me, $dept, $code, $title, $justification, $needed ?: null, $total]);
            $id = (int)$pdo->lastInsertId();
            procurementLog($pdo, $id, $me, 'created');
        }
        $add = $pdo->prepare('INSERT INTO staff_procurement_items (request_id, description, quantity, unit, unit_cost) VALUES (?, ?, ?, ?, ?)');
        foreach ($items as $i) $add->execute(array_merge([$id], $i));
        if ($submit) {
            $row = procurementRow($pdo, $id);
            if (!$row['ref']) $pdo->prepare('UPDATE staff_procurement_requests SET ref = ? WHERE id = ?')->execute([procurementNextNumber($pdo, 'ref', 'PR'), $id]);
            // The line manager decides first; with none on record (or an inactive one), finance does.
            $mgr = $pdo->prepare('SELECT s.manager_user_id FROM staff_accounts s JOIN staff_accounts m ON m.user_id = s.manager_user_id AND m.active = 1 WHERE s.user_id = ?');
            $mgr->execute([$me]);
            $manager = $mgr->fetchColumn() ?: null;
            $pdo->prepare('UPDATE staff_procurement_requests SET status = ?, manager_user_id = ? WHERE id = ?')
                ->execute([$manager ? 'submitted' : 'manager_approved', $manager, $id]);
            procurementLog($pdo, $id, $me, 'submitted', $manager ? null : 'No line manager on record, so it goes straight to finance.');
        }
        $pdo->commit();
        $row = procurementRow($pdo, $id);
        if ($submit) {
            if ($row['status'] === 'submitted') staffNotify($pdo, (int)$row['manager_user_id'], 'procurement', $name() . ' asks you to approve a purchase: ' . $title, '#procurement-' . $id, 'pr:' . $id . ':sub:' . time());
            else $notifyFinance($row, 'Purchase request ' . $row['ref'] . ' needs finance approval: ' . $title);
        }
        respond(200, ['request' => procurementShape($pdo, $row, true)]);
    }

    if (preg_match('#^staff/procurement/(\d+)$#', $route, $m) && $method === 'GET') {
        $r = $load((int)$m[1]);
        $mine = (int)$r['requester_user_id'] === $me;
        $actions = [];
        if ($mine && in_array($r['status'], ['draft', 'returned'], true)) $actions[] = 'edit';
        if ($mine && in_array($r['status'], ['draft', 'returned', 'submitted', 'manager_approved'], true)) $actions[] = 'cancel';
        if (!$mine && $r['status'] === 'submitted' && (int)$r['manager_user_id'] === $me) array_push($actions, 'approve', 'return', 'reject');
        if (!$mine && $finance && $r['status'] === 'manager_approved') array_push($actions, 'approve', 'return', 'reject');
        if ($finance && in_array($r['status'], ['approved', 'manager_approved'], true)) $actions[] = 'quotes';
        if (!$mine && $finance && $r['status'] === 'approved') $actions[] = 'order';
        if ($r['status'] === 'ordered' && ($mine || $finance) && (int)$r['ordered_by'] !== $me) $actions[] = 'receive';
        if ($finance && $r['status'] === 'received') $actions[] = 'close';
        respond(200, ['request' => procurementShape($pdo, $r, true), 'actions' => $actions,
            'suppliers' => $finance ? procurementSuppliers($pdo, false) : [], 'codes' => staffChargeCodes($pdo)]);
    }

    if (preg_match('#^staff/procurement/(\d+)/(approve|return|reject|cancel|quote|quote-remove|order|receive|close)$#', $route, $m) && $method === 'POST') {
        $r = $load((int)$m[1]);
        $id = (int)$r['id'];
        $act = $m[2];
        $d = input();
        $note = fieldText($d['note'] ?? '', 500);
        $mine = (int)$r['requester_user_id'] === $me;
        $set = function (string $status, array $extra = []) use ($pdo, $id): void {
            $cols = ['status = ?'];
            $args = [$status];
            foreach ($extra as $k => $v) { $cols[] = "$k = ?"; $args[] = $v; }
            $args[] = $id;
            $pdo->prepare('UPDATE staff_procurement_requests SET ' . implode(', ', $cols) . ' WHERE id = ?')->execute($args);
        };
        $tell = function (string $title) use ($pdo, $r, $id): void {
            staffNotify($pdo, (int)$r['requester_user_id'], 'procurement', $title, '#procurement-' . $id, 'pr:' . $id . ':' . md5($title) . ':' . time());
        };

        if (in_array($act, ['approve', 'return', 'reject'], true)) {
            if ($mine) respond(403, ['error' => 'Nobody approves their own request.']);
            $asManager = $r['status'] === 'submitted' && (int)$r['manager_user_id'] === $me;
            $asFinance = $r['status'] === 'manager_approved' && $finance;
            if (!$asManager && !$asFinance) respond(403, ['error' => $r['status'] === 'submitted' ? 'This request is with the requester\'s line manager.' : ($r['status'] === 'manager_approved' ? 'Finance approves at this step.' : 'This request is not waiting for a decision.')]);
            if ($act !== 'approve' && $note === null) respond(400, ['error' => 'Give a reason.']);
            if ($act === 'approve') {
                if ($asManager) { $set('manager_approved', ['manager_decided_by' => $me]); procurementLog($pdo, $id, $me, 'manager_approved', $note); $notifyFinance(procurementRow($pdo, $id), 'Purchase request ' . $r['ref'] . ' needs finance approval: ' . $r['title']); }
                else { $set('approved', ['finance_decided_by' => $me]); procurementLog($pdo, $id, $me, 'finance_approved', $note); $tell('Your purchase request ' . $r['ref'] . ' was approved'); }
            } else {
                $set($act === 'return' ? 'returned' : 'rejected');
                procurementLog($pdo, $id, $me, $act === 'return' ? 'returned' : 'rejected', $note);
                $tell('Your purchase request ' . $r['ref'] . ($act === 'return' ? ' was returned for changes: ' : ' was rejected: ') . $note);
            }
            staffAudit($pdo, $me, 'procurement_' . $act, 'procurement', (string)$id, $r['ref'] . ' ' . $r['title']);
        } elseif ($act === 'cancel') {
            if (!$mine) respond(403, ['error' => 'Only the person who raised a request cancels it.']);
            if (!in_array($r['status'], ['draft', 'returned', 'submitted', 'manager_approved'], true)) respond(400, ['error' => 'An approved request is not cancelled here. Ask finance.']);
            $set('cancelled');
            procurementLog($pdo, $id, $me, 'cancelled', $note);
        } elseif ($act === 'quote' || $act === 'quote-remove') {
            if (!$finance) respond(403, ['error' => 'Quotations are recorded by finance.']);
            if (!in_array($r['status'], ['manager_approved', 'approved'], true)) respond(400, ['error' => 'Quotations are recorded before the order is placed.']);
            if ($act === 'quote-remove') {
                $pdo->prepare('DELETE FROM staff_procurement_quotes WHERE id = ? AND request_id = ?')->execute([(int)($d['quoteId'] ?? 0), $id]);
                procurementLog($pdo, $id, $me, 'quote_removed');
            } else {
                $sup = (int)($d['supplierId'] ?? 0);
                $ok = $pdo->prepare('SELECT name FROM staff_suppliers WHERE id = ? AND active = 1');
                $ok->execute([$sup]);
                $supName = $ok->fetchColumn();
                if ($supName === false) respond(400, ['error' => 'Choose a registered supplier (add them under Suppliers first).']);
                if (!is_numeric($d['amount'] ?? null) || (float)$d['amount'] <= 0) respond(400, ['error' => 'Give the quoted amount.']);
                $dup = $pdo->prepare('SELECT 1 FROM staff_procurement_quotes WHERE request_id = ? AND supplier_id = ?');
                $dup->execute([$id, $sup]);
                if ($dup->fetchColumn()) respond(400, ['error' => 'That supplier has already quoted. Remove the old quote first.']);
                $valid = (string)($d['validUntil'] ?? '');
                $pdo->prepare('INSERT INTO staff_procurement_quotes (request_id, supplier_id, amount, valid_until, note, added_by) VALUES (?, ?, ?, ?, ?, ?)')
                    ->execute([$id, $sup, round((float)$d['amount'], 2), DateTimeImmutable::createFromFormat('!Y-m-d', $valid) ? $valid : null, $note, $me]);
                procurementLog($pdo, $id, $me, 'quote_added', $supName . ': KSh ' . number_format((float)$d['amount'], 2));
            }
        } elseif ($act === 'order') {
            if (!$finance) respond(403, ['error' => 'Purchase orders are issued by finance.']);
            if ($mine) respond(403, ['error' => 'Someone other than the requester issues the order.']);
            if ($r['status'] !== 'approved') respond(400, ['error' => 'Only an approved request is ordered.']);
            [$needed, $tender] = procurementQuotesNeeded((float)$r['total']);
            $count = $pdo->prepare('SELECT COUNT(*) FROM staff_procurement_quotes WHERE request_id = ?');
            $count->execute([$id]);
            if ((int)$count->fetchColumn() < $needed) respond(400, ['error' => "A purchase of this value needs $needed quotation" . ($needed > 1 ? 's' : '') . ' before the order.']);
            $tenderRef = fieldText($d['tenderRef'] ?? '', 120);
            if ($tender && $tenderRef === null) respond(400, ['error' => 'A purchase of this value needs the tender committee\'s reference.']);
            $q = $pdo->prepare('SELECT q.*, s.name FROM staff_procurement_quotes q JOIN staff_suppliers s ON s.id = q.supplier_id WHERE q.id = ? AND q.request_id = ?');
            $q->execute([(int)($d['quoteId'] ?? 0), $id]);
            $quote = $q->fetch();
            if (!$quote) respond(400, ['error' => 'Choose the quotation to order from.']);
            if ((float)$quote['amount'] > (float)$r['total'] * (1 + PROCUREMENT_LPO_TOLERANCE) + 0.005) {
                respond(400, ['error' => 'That quotation is more than ' . (int)(PROCUREMENT_LPO_TOLERANCE * 100) . '% above the approved amount. Return the request so it is approved at the new cost.']);
            }
            $cheapest = $pdo->prepare('SELECT MIN(amount) FROM staff_procurement_quotes WHERE request_id = ?');
            $cheapest->execute([$id]);
            if ((float)$quote['amount'] > (float)$cheapest->fetchColumn() && $note === null) respond(400, ['error' => 'This is not the lowest quotation. Say why it was chosen.']);
            $lpo = procurementNextNumber($pdo, 'lpo_number', 'LPO');
            $set('ordered', ['supplier_id' => (int)$quote['supplier_id'], 'lpo_number' => $lpo, 'lpo_amount' => (float)$quote['amount'],
                             'tender_ref' => $tenderRef, 'ordered_by' => $me, 'ordered_at' => date('Y-m-d H:i:s')]);
            procurementLog($pdo, $id, $me, 'ordered', $lpo . ' to ' . $quote['name'] . ($note ? ' (' . $note . ')' : ''));
            $tell('Your purchase ' . $r['ref'] . ' was ordered from ' . $quote['name'] . ' (' . $lpo . ')');
            staffAudit($pdo, $me, 'procurement_ordered', 'procurement', (string)$id, $r['ref'] . ' ' . $lpo);
        } elseif ($act === 'receive') {
            if ($r['status'] !== 'ordered') respond(400, ['error' => 'Only an ordered purchase is received.']);
            if (!$mine && !$finance) respond(403, ['error' => 'The requester or finance confirms delivery.']);
            if ((int)$r['ordered_by'] === $me) respond(403, ['error' => 'The person who issued the order does not confirm delivery.']);
            if ($note === null) respond(400, ['error' => 'Note what arrived and its condition (e.g. "All 5 delivered in good order, delivery note 4471").']);
            $set('received', ['received_by' => $me, 'received_at' => date('Y-m-d H:i:s')]);
            procurementLog($pdo, $id, $me, 'received', $note);
            if (!$mine) $tell('Your purchase ' . $r['ref'] . ' was received');
        } elseif ($act === 'close') {
            if (!$finance) respond(403, ['error' => 'Finance closes a request once paid.']);
            if ($r['status'] !== 'received') respond(400, ['error' => 'A request is closed after delivery.']);
            $ref = fieldText($d['paymentRef'] ?? '', 120);
            if ($ref === null) respond(400, ['error' => 'Give the payment reference (cheque, EFT or M-Pesa code).']);
            $set('closed', ['payment_ref' => $ref]);
            procurementLog($pdo, $id, $me, 'closed', 'Paid: ' . $ref);
            staffAudit($pdo, $me, 'procurement_closed', 'procurement', (string)$id, $r['ref'] . ' paid ' . $ref);
        }
        respond(200, ['request' => procurementShape($pdo, procurementRow($pdo, $id), true)]);
    }

    // ----- register export for finance -----
    if ($route === 'staff/procurement/export' && $method === 'GET') {
        if (!$finance) respond(403, ['error' => 'The procurement register is for finance.']);
        $from = (string)($_GET['from'] ?? date('Y-01-01'));
        $to = (string)($_GET['to'] ?? date('Y-m-d'));
        $stmt = $pdo->prepare('SELECT id FROM staff_procurement_requests WHERE ref IS NOT NULL AND DATE(created_at) BETWEEN ? AND ? ORDER BY id');
        $stmt->execute([$from, $to]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $r = procurementShape($pdo, procurementRow($pdo, (int)$id), false);
            $out[] = [$r['ref'], substr($r['createdAt'], 0, 10), $r['requester'], $r['department'], $r['chargeCode'], $r['title'], $r['total'], $r['statusLabel'], $r['supplier'], $r['lpoNumber'], $r['lpoAmount']];
        }
        staffAudit($pdo, $me, 'procurement_exported', 'procurement', null, "$from to $to");
        timesheetCsv("procurement-$from-to-$to.csv", ['Ref', 'Raised', 'Requested by', 'Department', 'Project', 'Purchase', 'Estimated total', 'Status', 'Supplier', 'LPO', 'LPO amount'], $out);
    }

    respond(404, ['error' => 'Route not found']);
}
