<?php
declare(strict_types=1);

/**
 * Group registration (platform blueprint: "an institution registers and pays for several
 * delegates on one invoice; each delegate still gets their own QR code").
 *
 * Finance sets up a group for an institution and shares its join code. Delegates enter the
 * code when they register, so they are not asked to pay themselves; staff can also add a
 * registration to a group by its reference. One invoice lists every member. When the
 * institution pays, finance records the payment once and every member is confirmed, each
 * with their own badge and email.
 *
 * Links live in their own table rather than a column on conf_registrations, so no table is
 * altered.
 */

const CONF_GROUP_CODE_ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

function confGroupSchema(): array
{
    $tail = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    return [
        "CREATE TABLE IF NOT EXISTS conf_groups (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            event_id INT UNSIGNED NOT NULL,
            name VARCHAR(200) NOT NULL,
            contact_name VARCHAR(120) NULL,
            contact_email VARCHAR(190) NULL,
            code VARCHAR(12) NOT NULL,
            invoice_ref VARCHAR(30) NOT NULL,
            status ENUM('open','paid','cancelled') NOT NULL DEFAULT 'open',
            paid_reference VARCHAR(80) NULL,
            paid_at DATETIME NULL,
            created_by INT UNSIGNED NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY conf_groups_code (code),
            UNIQUE KEY conf_groups_invoice (invoice_ref),
            KEY conf_groups_event (event_id)
        )" . $tail,
        "CREATE TABLE IF NOT EXISTS conf_group_members (
            registration_id INT UNSIGNED NOT NULL,
            group_id INT UNSIGNED NOT NULL,
            added_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (registration_id),
            KEY conf_group_members_group (group_id)
        )" . $tail,
    ];
}

function confGroupsEnsureTables(PDO $pdo): bool
{
    static $ready = null;
    if ($ready !== null) return $ready;
    try {
        foreach (confGroupSchema() as $sql) $pdo->exec($sql);
        return $ready = true;
    } catch (Throwable $e) {
        error_log('Group tables unavailable: ' . $e->getMessage());
        return $ready = false;
    }
}

function confGroupRow(PDO $pdo, int $eventId, int $groupId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM conf_groups WHERE id = ? AND event_id = ?');
    $stmt->execute([$groupId, $eventId]);
    return $stmt->fetch() ?: null;
}

/** The open group a join code belongs to, or null. */
function confGroupByCode(PDO $pdo, int $eventId, string $code): ?array
{
    $stmt = $pdo->prepare("SELECT * FROM conf_groups WHERE event_id = ? AND code = ? AND status = 'open'");
    $stmt->execute([$eventId, strtoupper(trim($code))]);
    return $stmt->fetch() ?: null;
}

/** The group a registration belongs to, or null. */
function confGroupOf(PDO $pdo, int $registrationId): ?array
{
    try {
        $stmt = $pdo->prepare('SELECT g.* FROM conf_group_members m JOIN conf_groups g ON g.id = m.group_id WHERE m.registration_id = ?');
        $stmt->execute([$registrationId]);
        return $stmt->fetch() ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

function confGroupMembers(PDO $pdo, int $groupId): array
{
    $stmt = $pdo->prepare('SELECT r.id, r.ref, r.badge_name, r.status, r.amount_due, r.currency, r.fee_label, c.name AS category
                           FROM conf_group_members m JOIN conf_registrations r ON r.id = m.registration_id JOIN conf_categories c ON c.id = r.category_id
                           WHERE m.group_id = ? ORDER BY r.badge_name');
    $stmt->execute([$groupId]);
    return array_map(fn($r) => ['id' => (int)$r['id'], 'ref' => $r['ref'], 'name' => $r['badge_name'], 'status' => $r['status'],
                                'amount' => (float)$r['amount_due'], 'currency' => $r['currency'], 'fee' => $r['fee_label'], 'category' => $r['category']],
                     $stmt->fetchAll());
}

/** Totals of the members still to be paid for, per currency. */
function confGroupTotals(array $members): array
{
    $totals = [];
    foreach ($members as $m) {
        if ($m['status'] === 'cancelled') continue;
        $totals[$m['currency']] = ($totals[$m['currency']] ?? 0) + $m['amount'];
    }
    return $totals;
}

function confGroups(PDO $pdo, int $eventId): array
{
    $stmt = $pdo->prepare('SELECT * FROM conf_groups WHERE event_id = ? ORDER BY created_at DESC');
    $stmt->execute([$eventId]);
    return array_map(function ($g) use ($pdo) {
        $members = confGroupMembers($pdo, (int)$g['id']);
        return ['id' => (int)$g['id'], 'name' => $g['name'], 'contactName' => $g['contact_name'], 'contactEmail' => $g['contact_email'],
                'code' => $g['code'], 'invoiceRef' => $g['invoice_ref'], 'status' => $g['status'], 'paidReference' => $g['paid_reference'],
                'paidAt' => $g['paid_at'], 'members' => $members, 'totals' => confGroupTotals($members)];
    }, $stmt->fetchAll());
}

/** @return array{0: ?int, 1: ?string} */
function confSaveGroup(PDO $pdo, array $event, array $data, int $by): array
{
    $eventId = (int)$event['id'];
    $name = trim((string)($data['name'] ?? ''));
    if ($name === '' || mb_strlen($name) > 200) return [null, 'Give the group the institution\'s name.'];
    $email = trim((string)($data['contactEmail'] ?? ''));
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) return [null, 'The contact email is not valid.'];
    $contact = mb_substr(trim((string)($data['contactName'] ?? '')), 0, 120) ?: null;
    $id = (int)($data['id'] ?? 0);
    if ($id) {
        if (!confGroupRow($pdo, $eventId, $id)) return [null, 'No such group.'];
        $pdo->prepare('UPDATE conf_groups SET name = ?, contact_name = ?, contact_email = ? WHERE id = ?')->execute([$name, $contact, $email ?: null, $id]);
        return [$id, null];
    }
    $count = $pdo->prepare('SELECT COUNT(*) FROM conf_groups WHERE event_id = ?');
    $count->execute([$eventId]);
    $invoice = sprintf('%s-G%03d', (string)$event['ref_prefix'], (int)$count->fetchColumn() + 1);
    for ($i = 0; $i < 5; $i++) {
        $code = '';
        for ($c = 0; $c < 8; $c++) $code .= CONF_GROUP_CODE_ALPHABET[random_int(0, strlen(CONF_GROUP_CODE_ALPHABET) - 1)];
        try {
            $pdo->prepare('INSERT INTO conf_groups (event_id, name, contact_name, contact_email, code, invoice_ref, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)')
                ->execute([$eventId, $name, $contact, $email ?: null, $code, $invoice . ($i ? '-' . $i : ''), $by]);
            $id = (int)$pdo->lastInsertId();
            if (function_exists('confAudit')) confAudit($pdo, $eventId, null, $by, 'group_created', ['group' => $id, 'name' => $name]);
            return [$id, null];
        } catch (PDOException $e) {
            if ((int)($e->errorInfo[1] ?? 0) !== 1062) throw $e;
        }
    }
    return [null, 'Could not create the group. Please try again.'];
}

/** Adds a registration that is still waiting for payment. @return ?string an error */
function confGroupAdd(PDO $pdo, array $event, array $group, array $reg, ?int $by): ?string
{
    if ($group['status'] !== 'open') return 'This group has already been paid or cancelled.';
    if ((int)$reg['event_id'] !== (int)$event['id']) return 'No such registration.';
    if ($reg['status'] !== 'awaiting_payment') return 'Only a registration still waiting for payment can join a group.';
    $existing = confGroupOf($pdo, (int)$reg['id']);
    if ($existing) return $existing['id'] == $group['id'] ? 'Already in this group.' : 'This registration is already in another group.';
    $pending = $pdo->prepare("SELECT 1 FROM conf_payments WHERE registration_id = ? AND status IN ('pending','confirmed') LIMIT 1");
    $pending->execute([(int)$reg['id']]);
    if ($pending->fetch()) return 'This delegate has already submitted a payment of their own.';
    $members = confGroupMembers($pdo, (int)$group['id']);
    if ($members && $members[0]['currency'] !== $reg['currency']) return 'A group is invoiced in one currency; this registration is in ' . $reg['currency'] . '.';
    $pdo->prepare('INSERT INTO conf_group_members (registration_id, group_id) VALUES (?, ?)')->execute([(int)$reg['id'], (int)$group['id']]);
    if (function_exists('confAudit')) confAudit($pdo, (int)$event['id'], (int)$reg['id'], $by, 'group_joined', ['group' => (int)$group['id']]);
    return null;
}

function confGroupRemove(PDO $pdo, array $event, array $group, int $registrationId, int $by): ?string
{
    if ($group['status'] !== 'open') return 'This group has already been paid or cancelled.';
    $pdo->prepare('DELETE FROM conf_group_members WHERE registration_id = ? AND group_id = ?')->execute([$registrationId, (int)$group['id']]);
    if (function_exists('confAudit')) confAudit($pdo, (int)$event['id'], $registrationId, $by, 'group_left', ['group' => (int)$group['id']]);
    return null;
}

/**
 * The institution has paid: every member still waiting is settled and confirmed, each with
 * their own badge and email. Each member's payment carries the group's reference.
 */
function confGroupPaid(PDO $pdo, array $config, array $event, array $group, string $reference, int $by): array
{
    $reference = strtoupper(trim($reference));
    if ($group['status'] !== 'open') return [null, 'This group has already been paid or cancelled.'];
    if ($reference === '') return [null, 'Enter the bank or M-Pesa reference of the institution\'s payment.'];
    $confirmed = 0;
    foreach (confGroupMembers($pdo, (int)$group['id']) as $m) {
        if ($m['status'] !== 'awaiting_payment') continue;
        $reg = confRegistrationRow($pdo, $m['id']);
        $pdo->prepare('INSERT INTO conf_payments (registration_id, method, reference, amount, currency, status, note, submitted_by, reviewed_by, reviewed_at)
                       VALUES (?, "bank", ?, ?, ?, "confirmed", ?, ?, ?, NOW())')
            ->execute([$m['id'], $reference . '/' . $m['ref'], confBalance($pdo, $reg)['due'], $reg['currency'],
                       'Paid by ' . $group['name'] . ' (' . $group['invoice_ref'] . ')', $by, $by]);
        confConfirm($pdo, $config, $event, confRegistrationRow($pdo, $m['id']), $by, 'group');
        $confirmed++;
    }
    $pdo->prepare("UPDATE conf_groups SET status = 'paid', paid_reference = ?, paid_at = NOW() WHERE id = ?")->execute([$reference, (int)$group['id']]);
    if (function_exists('confAudit')) confAudit($pdo, (int)$event['id'], null, $by, 'group_paid', ['group' => (int)$group['id'], 'reference' => $reference, 'confirmed' => $confirmed]);
    return [['confirmed' => $confirmed], null];
}

/** A4 invoice for the institution. */
function confGroupInvoicePdf(array $event, array $group, array $members, string $payNotes): string
{
    if (!class_exists('SimplePdf')) require_once __DIR__ . '/SimplePdf.php';
    $clean = static fn(string $t, bool $u): string => function_exists('academyCertificatePdfText') ? academyCertificatePdfText($t, $u) : $t;
    $pdf = new SimplePdf(595, 842);
    $fonts = dirname(__DIR__) . '/fonts/';
    $u = $pdf->useUnicodeFonts($fonts . 'NotoSans-Regular.ttf', $fonts . 'NotoSans-Bold.ttf');

    $logo = dirname(__DIR__, 2) . '/assets/img/certificate-resok-logo.jpg';
    $size = @getimagesize($logo);
    $lw = 170.0;
    $lh = $size ? $lw * $size[1] / $size[0] : 45.0;
    $pdf->image($logo, 48, 44, $lw, $lh);
    $pdf->setTextColor(22, 32, 44);
    $pdf->text(547, 62, 'INVOICE', 20, true, 'R');
    $pdf->setTextColor(102, 112, 133);
    $pdf->text(547, 80, $group['invoice_ref'], 10.5, false, 'R');
    $pdf->text(547, 94, 'Date: ' . date('j F Y'), 10.5, false, 'R');
    $pdf->setFillColor(0, 147, 46);
    $pdf->rect(48, 44 + $lh + 14, 499, 2);

    $y = 44 + $lh + 44;
    $pdf->setTextColor(102, 112, 133);
    $pdf->text(48, $y, 'BILL TO', 9, true);
    $pdf->text(320, $y, 'FOR', 9, true);
    $y += 16;
    $pdf->setTextColor(22, 32, 44);
    $pdf->text(48, $y, $clean((string)$group['name'], $u), 11, true);
    $pdf->multilineText(320, $y, 227, $clean((string)$event['name'], $u), 10.5, 14, true);
    if ($group['contact_name'] || $group['contact_email']) {
        $pdf->text(48, $y + 15, $clean(trim(($group['contact_name'] ?? '') . ' ' . ($group['contact_email'] ? '<' . $group['contact_email'] . '>' : '')), $u), 10);
    }
    $y += 52;

    // Table
    $pdf->setFillColor(246, 250, 247);
    $pdf->rect(48, $y - 14, 499, 22);
    $pdf->setTextColor(71, 84, 103);
    $pdf->text(56, $y, 'Delegate', 9.5, true);
    $pdf->text(250, $y, 'Reference', 9.5, true);
    $pdf->text(350, $y, 'Category', 9.5, true);
    $pdf->text(539, $y, 'Amount', 9.5, true, 'R');
    $y += 22;
    $pdf->setTextColor(22, 32, 44);
    $currency = $members[0]['currency'] ?? 'KES';
    $total = 0.0;
    foreach ($members as $m) {
        if ($m['status'] === 'cancelled') continue;
        if ($y > 720) { $pdf->text(56, $y, '(continued on the delegate list attached)', 9); break; }
        $pdf->text(56, $y, $clean(mb_strimwidth((string)$m['name'], 0, 34, '...'), $u), 10);
        $pdf->text(250, $y, (string)$m['ref'], 10);
        // Truncated by measured width so a long category never runs into the amount column.
        $cat = $clean((string)$m['category'] . ($m['fee'] ? ' (' . $m['fee'] . ')' : ''), $u);
        while (mb_strlen($cat) > 4 && $pdf->measure($cat, 10) > 120) $cat = rtrim(mb_substr($cat, 0, -4)) . '...';
        $pdf->text(350, $y, $cat, 10);
        $pdf->text(539, $y, number_format($m['amount'], 2), 10, false, 'R');
        $pdf->setStrokeColor(228, 231, 236);
        $pdf->line(48, $y + 7, 547, $y + 7, 0.5);
        $total += $m['amount'];
        $y += 20;
    }
    $y += 8;
    $pdf->text(430, $y, 'Total due', 11, true, 'R');
    $pdf->text(539, $y, $currency . ' ' . number_format($total, 2), 12, true, 'R');
    $y += 36;

    $pdf->setTextColor(22, 32, 44);
    $pdf->text(48, $y, 'How to pay', 10.5, true);
    $y += 16;
    $notes = trim($payNotes) !== '' ? trim($payNotes) : 'Contact the conference secretariat for payment details.';
    foreach (preg_split('/\R/', $notes) ?: [] as $line) {
        if (trim($line) !== '') $y = $pdf->multilineText(48, $y, 499, $clean($line, $u), 10, 14);
    }
    $y += 6;
    $y = $pdf->multilineText(48, $y, 499, 'Quote ' . $group['invoice_ref'] . ' as the payment reference. Each delegate receives their own confirmation and conference QR code once the payment is received.', 10, 14);

    $pdf->setTextColor(102, 112, 133);
    $pdf->text(48, 800, 'Respiratory Society of Kenya · www.resok.org · kislcsecretariat@resok.org', 8.5);
    return $pdf->output();
}
