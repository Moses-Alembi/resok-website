<?php
declare(strict_types=1);

/**
 * Payroll: pay records, the monthly run, payslips, and the files for the bank and for the
 * statutory returns.
 *
 * Rules that shape it:
 *
 * 1. Pay is the most private thing the portal holds. Pay records and runs are seen only by
 *    payroll.manage (who prepares) and payroll.approve (who approves). Each member of staff
 *    sees their own payslips and nothing else, and only once the run is approved. Bank
 *    account, M-Pesa number, KRA PIN, NSSF and SHIF numbers are encrypted at rest.
 *
 * 2. Four eyes. The person who prepared a run cannot approve it, whatever their
 *    permissions. Nobody edits their own pay record. Every change is in the activity log.
 *
 * 3. Statutory rates are data, not code. PAYE bands, personal relief, SHIF, the Housing
 *    Levy and the NSSF limits sit in a dated settings record. The defaults are the rates as
 *    known when this was written (Oct 2026) and are marked unconfirmed: no run can be
 *    submitted until someone with payroll.manage has checked them against KRA and NSSF and
 *    saved them. A run keeps a copy of the rates it used, so a later change never alters a
 *    payslip already issued.
 *
 * 4. An approved run is final. A mistake is corrected in the next month's run (an earning or
 *    deduction item), or, before payment, voided by a super administrator with a reason.
 *
 * The calculation, per month:
 *   taxable earnings  = basic salary + taxable allowances
 *   NSSF (employee)   = rate x pay up to the upper limit (tier I to the lower limit, tier II above)
 *   SHIF              = rate x gross, never below the minimum
 *   Housing Levy      = rate x gross (employer pays the same again)
 *   taxable pay       = taxable earnings - NSSF and pension (to the cap) - SHIF - Housing Levy
 *   PAYE              = bands on taxable pay, less personal relief, never below zero
 *   net pay           = all earnings - statutory deductions - other deductions
 */

const PAYROLL_ITEM_KINDS = [
    'earning_taxable'    => 'Allowance (taxable)',
    'earning_nontaxable' => 'Reimbursement (not taxed)',
    'deduction_pretax'   => 'Pension contribution (before tax)',
    'deduction'          => 'Deduction (after tax)',
];

const PAYROLL_DEFAULT_SETTINGS = [
    'payeBands' => [[24000, 10], [8333, 25], [467667, 30], [300000, 32.5], [null, 35]],
    'personalRelief' => 2400,
    'shifRate' => 2.75, 'shifMinimum' => 300,
    'housingLevyRate' => 1.5, 'housingLevyEmployerRate' => 1.5,
    'nssfRate' => 6, 'nssfLowerLimit' => 9000, 'nssfUpperLimit' => 108000,
    'pensionCap' => 30000,
    'shifAndLevyDeductible' => true,
];

function payrollEnsureTables(PDO $pdo): bool
{
    static $ready = null;
    if ($ready !== null) return $ready;
    try {
        $tail = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        $pdo->exec("CREATE TABLE IF NOT EXISTS staff_pay_records (
            user_id INT UNSIGNED NOT NULL,
            basic_salary DECIMAL(12,2) NOT NULL DEFAULT 0,
            payment_method ENUM('bank','mpesa') NOT NULL DEFAULT 'bank',
            bank_name VARCHAR(80) NULL,
            bank_branch VARCHAR(80) NULL,
            bank_account TEXT NULL,
            mpesa_phone TEXT NULL,
            kra_pin TEXT NULL,
            nssf_number TEXT NULL,
            shif_number TEXT NULL,
            on_payroll TINYINT(1) NOT NULL DEFAULT 1,
            updated_by INT UNSIGNED NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id)
        )" . $tail);
        $pdo->exec("CREATE TABLE IF NOT EXISTS staff_pay_items (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id INT UNSIGNED NOT NULL,
            run_id INT UNSIGNED NULL,
            kind VARCHAR(30) NOT NULL,
            label VARCHAR(80) NOT NULL,
            amount DECIMAL(12,2) NOT NULL,
            created_by INT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY staff_pay_items_person (user_id, run_id)
        )" . $tail);
        $pdo->exec("CREATE TABLE IF NOT EXISTS staff_payroll_settings (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            effective_from DATE NOT NULL,
            settings TEXT NOT NULL,
            confirmed_by INT UNSIGNED NULL,
            note VARCHAR(300) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY staff_payroll_settings_from (effective_from)
        )" . $tail);
        $pdo->exec("CREATE TABLE IF NOT EXISTS staff_payroll_runs (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            period CHAR(7) NOT NULL,
            status ENUM('draft','submitted','approved','void') NOT NULL DEFAULT 'draft',
            settings TEXT NOT NULL,
            prepared_by INT UNSIGNED NOT NULL,
            submitted_at DATETIME NULL,
            approved_by INT UNSIGNED NULL,
            approved_at DATETIME NULL,
            return_reason VARCHAR(500) NULL,
            voided_by INT UNSIGNED NULL,
            voided_at DATETIME NULL,
            void_reason VARCHAR(500) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY staff_payroll_runs_period (period)
        )" . $tail);
        $pdo->exec("CREATE TABLE IF NOT EXISTS staff_payroll_lines (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            run_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            name VARCHAR(160) NOT NULL,
            staff_number VARCHAR(30) NULL,
            job_title VARCHAR(120) NULL,
            department VARCHAR(120) NULL,
            basic DECIMAL(12,2) NOT NULL,
            items TEXT NOT NULL,
            gross DECIMAL(12,2) NOT NULL,
            taxable_pay DECIMAL(12,2) NOT NULL,
            nssf DECIMAL(12,2) NOT NULL,
            shif DECIMAL(12,2) NOT NULL,
            housing_levy DECIMAL(12,2) NOT NULL,
            paye DECIMAL(12,2) NOT NULL,
            pension DECIMAL(12,2) NOT NULL,
            other_deductions DECIMAL(12,2) NOT NULL,
            net DECIMAL(12,2) NOT NULL,
            employer_nssf DECIMAL(12,2) NOT NULL,
            employer_housing_levy DECIMAL(12,2) NOT NULL,
            payment_method VARCHAR(10) NOT NULL,
            bank_name VARCHAR(80) NULL,
            bank_branch VARCHAR(80) NULL,
            account TEXT NULL,
            kra_pin TEXT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY staff_payroll_lines_once (run_id, user_id)
        )" . $tail);
        return $ready = true;
    } catch (Throwable $e) {
        error_log('Payroll tables unavailable: ' . $e->getMessage());
        return $ready = false;
    }
}

/** The rates in force on a date: the latest saved version, or the unconfirmed defaults. */
function payrollSettings(PDO $pdo, string $onDate): array
{
    $stmt = $pdo->prepare('SELECT ps.*, s.full_name FROM staff_payroll_settings ps LEFT JOIN staff_accounts s ON s.user_id = ps.confirmed_by
                           WHERE ps.effective_from <= ? ORDER BY ps.effective_from DESC, ps.id DESC LIMIT 1');
    $stmt->execute([$onDate]);
    $row = $stmt->fetch();
    if (!$row) return ['rates' => PAYROLL_DEFAULT_SETTINGS, 'confirmed' => false, 'effectiveFrom' => null, 'confirmedBy' => null, 'confirmedAt' => null, 'note' => null];
    return ['rates' => array_merge(PAYROLL_DEFAULT_SETTINGS, json_decode($row['settings'], true) ?: []), 'confirmed' => true,
            'effectiveFrom' => $row['effective_from'], 'confirmedBy' => $row['full_name'], 'confirmedAt' => $row['created_at'], 'note' => $row['note']];
}

/** Checks rates sent from the form; returns [rates, error]. */
function payrollCleanRates(array $in): array
{
    $num = static function ($v, float $min, float $max) { return is_numeric($v) && (float)$v >= $min && (float)$v <= $max ? (float)$v : null; };
    $out = [];
    foreach (['personalRelief' => [0, 100000], 'shifRate' => [0, 20], 'shifMinimum' => [0, 10000], 'housingLevyRate' => [0, 10],
              'housingLevyEmployerRate' => [0, 10], 'nssfRate' => [0, 20], 'nssfLowerLimit' => [0, 1000000], 'nssfUpperLimit' => [0, 10000000],
              'pensionCap' => [0, 1000000]] as $k => [$lo, $hi]) {
        $v = $num($in[$k] ?? null, $lo, $hi);
        if ($v === null) return [null, "Check the value for $k."];
        $out[$k] = $v;
    }
    if ($out['nssfUpperLimit'] < $out['nssfLowerLimit']) return [null, 'The NSSF upper limit must be above the lower limit.'];
    $bands = [];
    $list = array_values((array)($in['payeBands'] ?? []));
    foreach ($list as $i => $b) {
        if (!is_array($b) || count($b) !== 2) return [null, 'Each PAYE band needs a width and a rate.'];
        $last = $i === count($list) - 1;
        $width = $last ? null : $num($b[0], 1, 100000000);
        $rate = $num($b[1], 0, 100);
        if ((!$last && $width === null) || $rate === null) return [null, 'Check PAYE band ' . ($i + 1) . '.'];
        $bands[] = [$width, $rate];
    }
    if (count($bands) < 2 || count($bands) > 10) return [null, 'Give between 2 and 10 PAYE bands; the last has no upper limit.'];
    $out['payeBands'] = $bands;
    $out['shifAndLevyDeductible'] = !empty($in['shifAndLevyDeductible']);
    return [$out, null];
}

/** One person's month. $items are [kind, label, amount] rows. */
function payrollCompute(array $rates, float $basic, array $items): array
{
    $r2 = static fn(float $v) => round($v + 0.0, 2);
    $taxableEarnings = $basic;
    $nonTaxable = 0.0;
    $pension = 0.0;
    $deductions = 0.0;
    foreach ($items as $it) {
        $amt = (float)$it['amount'];
        switch ($it['kind']) {
            case 'earning_taxable': $taxableEarnings += $amt; break;
            case 'earning_nontaxable': $nonTaxable += $amt; break;
            case 'deduction_pretax': $pension += $amt; break;
            case 'deduction': $deductions += $amt; break;
        }
    }
    $nssfRate = $rates['nssfRate'] / 100;
    $nssfPay = min($taxableEarnings, (float)$rates['nssfUpperLimit']);
    $nssf = $r2($nssfRate * max(0.0, $nssfPay));
    $shif = $taxableEarnings > 0 ? $r2(max((float)$rates['shifMinimum'], $taxableEarnings * $rates['shifRate'] / 100)) : 0.0;
    $levy = $r2($taxableEarnings * $rates['housingLevyRate'] / 100);
    $levyEmployer = $r2($taxableEarnings * $rates['housingLevyEmployerRate'] / 100);
    $retirementRelief = min($nssf + $pension, (float)$rates['pensionCap']);
    $taxable = max(0.0, $taxableEarnings - $retirementRelief - (!empty($rates['shifAndLevyDeductible']) ? $shif + $levy : 0.0));
    $tax = 0.0;
    $left = $taxable;
    foreach ($rates['payeBands'] as [$width, $rate]) {
        if ($left <= 0) break;
        $slice = $width === null ? $left : min($left, (float)$width);
        $tax += $slice * $rate / 100;
        $left -= $slice;
    }
    $paye = $r2(max(0.0, $tax - (float)$rates['personalRelief']));
    $gross = $r2($taxableEarnings + $nonTaxable);
    $net = $r2($gross - $nssf - $shif - $levy - $paye - $pension - $deductions);
    return [
        'gross' => $gross, 'taxablePay' => $r2($taxable), 'nssf' => $nssf, 'shif' => $shif, 'housingLevy' => $levy, 'paye' => $paye,
        'pension' => $r2($pension), 'otherDeductions' => $r2($deductions), 'net' => $net,
        'employerNssf' => $nssf, 'employerHousingLevy' => $levyEmployer,
    ];
}

function payrollCan(array $gate, string $what): bool
{
    return $gate['super'] || in_array($what, $gate['caps'], true);
}

function payrollMask(?string $plain): ?string
{
    if ($plain === null || $plain === '') return null;
    return strlen($plain) <= 4 ? str_repeat('•', strlen($plain)) : str_repeat('•', 4) . substr($plain, -4);
}

function payrollRun(PDO $pdo, int $id): array
{
    $stmt = $pdo->prepare('SELECT r.*, p.full_name AS preparer, a.full_name AS approver FROM staff_payroll_runs r
                           LEFT JOIN staff_accounts p ON p.user_id = r.prepared_by LEFT JOIN staff_accounts a ON a.user_id = r.approved_by WHERE r.id = ?');
    $stmt->execute([$id]);
    $run = $stmt->fetch();
    if (!$run) respond(404, ['error' => 'No such payroll run.']);
    return $run;
}

function payrollRunShape(PDO $pdo, array $run): array
{
    $t = $pdo->prepare('SELECT COUNT(*) AS people, COALESCE(SUM(gross),0) AS gross, COALESCE(SUM(net),0) AS net, COALESCE(SUM(paye),0) AS paye,
                               COALESCE(SUM(nssf),0) AS nssf, COALESCE(SUM(shif),0) AS shif, COALESCE(SUM(housing_levy),0) AS levy,
                               COALESCE(SUM(employer_nssf),0) AS enssf, COALESCE(SUM(employer_housing_levy),0) AS elevy, SUM(net < 0) AS negative
                        FROM staff_payroll_lines WHERE run_id = ?');
    $t->execute([(int)$run['id']]);
    $x = $t->fetch();
    return [
        'id' => (int)$run['id'], 'period' => $run['period'], 'label' => date('F Y', strtotime($run['period'] . '-01')), 'status' => $run['status'],
        'preparedBy' => $run['preparer'] ?? null, 'preparedById' => (int)$run['prepared_by'], 'submittedAt' => $run['submitted_at'],
        'approvedBy' => $run['approver'] ?? null, 'approvedAt' => $run['approved_at'], 'returnReason' => $run['return_reason'],
        'voidedAt' => $run['voided_at'], 'voidReason' => $run['void_reason'],
        'totals' => ['people' => (int)$x['people'], 'gross' => (float)$x['gross'], 'net' => (float)$x['net'], 'paye' => (float)$x['paye'],
                     'nssf' => (float)$x['nssf'], 'shif' => (float)$x['shif'], 'housingLevy' => (float)$x['levy'],
                     'employerNssf' => (float)$x['enssf'], 'employerHousingLevy' => (float)$x['elevy'], 'negative' => (int)$x['negative']],
    ];
}

function payrollLineShape(array $config, array $l, bool $withAccount): array
{
    $out = [
        'userId' => (int)$l['user_id'], 'name' => $l['name'], 'staffNumber' => $l['staff_number'], 'jobTitle' => $l['job_title'],
        'department' => $l['department'], 'basic' => (float)$l['basic'], 'items' => json_decode($l['items'], true) ?: [],
        'gross' => (float)$l['gross'], 'taxablePay' => (float)$l['taxable_pay'], 'nssf' => (float)$l['nssf'], 'shif' => (float)$l['shif'],
        'housingLevy' => (float)$l['housing_levy'], 'paye' => (float)$l['paye'], 'pension' => (float)$l['pension'],
        'otherDeductions' => (float)$l['other_deductions'], 'net' => (float)$l['net'],
        'employerNssf' => (float)$l['employer_nssf'], 'employerHousingLevy' => (float)$l['employer_housing_levy'],
        'paymentMethod' => $l['payment_method'], 'bankName' => $l['bank_name'], 'bankBranch' => $l['bank_branch'],
    ];
    $account = cryptoDecrypt($config, $l['account']);
    $out['account'] = $withAccount ? $account : payrollMask($account);
    $out['kraPin'] = cryptoDecrypt($config, $l['kra_pin']);
    return $out;
}

/** (Re)builds a draft run's lines from the current pay records and items. */
function payrollBuild(PDO $pdo, array $config, int $runId, array $rates): void
{
    $people = $pdo->query("SELECT p.*, s.full_name, s.staff_number, s.job_title, d.name AS department, u.email
                           FROM staff_pay_records p JOIN staff_accounts s ON s.user_id = p.user_id AND s.active = 1
                           JOIN users u ON u.id = p.user_id LEFT JOIN staff_departments d ON d.id = s.department_id
                           WHERE p.on_payroll = 1")->fetchAll();
    $items = $pdo->prepare('SELECT kind, label, amount FROM staff_pay_items WHERE user_id = ? AND (run_id IS NULL OR run_id = ?) ORDER BY run_id IS NOT NULL, id');
    $pdo->beginTransaction();
    $pdo->prepare('DELETE FROM staff_payroll_lines WHERE run_id = ?')->execute([$runId]);
    $add = $pdo->prepare('INSERT INTO staff_payroll_lines (run_id, user_id, name, staff_number, job_title, department, basic, items, gross, taxable_pay,
                            nssf, shif, housing_levy, paye, pension, other_deductions, net, employer_nssf, employer_housing_levy,
                            payment_method, bank_name, bank_branch, account, kra_pin)
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    foreach ($people as $p) {
        $items->execute([(int)$p['user_id'], $runId]);
        $list = array_map(static fn($i) => ['kind' => $i['kind'], 'label' => $i['label'], 'amount' => (float)$i['amount']], $items->fetchAll());
        $c = payrollCompute($rates, (float)$p['basic_salary'], $list);
        $account = $p['payment_method'] === 'mpesa' ? $p['mpesa_phone'] : $p['bank_account'];
        $add->execute([$runId, (int)$p['user_id'], $p['full_name'] ?: $p['email'], $p['staff_number'], $p['job_title'], $p['department'],
            (float)$p['basic_salary'], json_encode($list), $c['gross'], $c['taxablePay'], $c['nssf'], $c['shif'], $c['housingLevy'], $c['paye'],
            $c['pension'], $c['otherDeductions'], $c['net'], $c['employerNssf'], $c['employerHousingLevy'],
            $p['payment_method'], $p['bank_name'], $p['bank_branch'], $account, $p['kra_pin']]);
    }
    $pdo->commit();
}

function payrollTodo(PDO $pdo, array $gate, int $me): int
{
    if (!payrollCan($gate, 'payroll.approve') || !payrollEnsureTables($pdo)) return 0;
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM staff_payroll_runs WHERE status = 'submitted' AND prepared_by <> ?");
    $stmt->execute([$me]);
    return (int)$stmt->fetchColumn();
}

function payrollHandle(PDO $pdo, array $config, string $route, string $method, array $user, array $gate): void
{
    if (!payrollEnsureTables($pdo)) respond(503, ['error' => 'The payroll tables could not be created.']);
    $me = (int)$user['userId'];
    $manage = payrollCan($gate, 'payroll.manage');
    $approve = payrollCan($gate, 'payroll.approve');

    // ----- my payslips: every member of staff, approved runs only -----
    if ($route === 'staff/payroll/mine' && $method === 'GET') {
        $stmt = $pdo->prepare("SELECT r.id, r.period, l.net, l.gross FROM staff_payroll_lines l JOIN staff_payroll_runs r ON r.id = l.run_id
                               WHERE l.user_id = ? AND r.status = 'approved' ORDER BY r.period DESC");
        $stmt->execute([$me]);
        respond(200, ['payslips' => array_map(static fn($r) => ['runId' => (int)$r['id'], 'period' => $r['period'],
            'label' => date('F Y', strtotime($r['period'] . '-01')), 'net' => (float)$r['net'], 'gross' => (float)$r['gross']], $stmt->fetchAll()),
            'manage' => $manage, 'approve' => $approve]);
    }
    if (preg_match('#^staff/payroll/mine/(\d+)$#', $route, $m) && $method === 'GET') {
        $stmt = $pdo->prepare("SELECT l.*, r.period FROM staff_payroll_lines l JOIN staff_payroll_runs r ON r.id = l.run_id
                               WHERE l.run_id = ? AND l.user_id = ? AND r.status = 'approved'");
        $stmt->execute([(int)$m[1], $me]);
        $l = $stmt->fetch();
        if (!$l) respond(404, ['error' => 'No such payslip.']);
        respond(200, ['payslip' => payrollLineShape($config, $l, false) + ['period' => $l['period'], 'label' => date('F Y', strtotime($l['period'] . '-01'))]]);
    }

    if (!$manage && !$approve) respond(403, ['error' => 'Payroll is open only to the people who run and approve it.']);

    // ----- overview -----
    if ($route === 'staff/payroll' && $method === 'GET') {
        $runs = $pdo->query('SELECT r.*, p.full_name AS preparer, a.full_name AS approver FROM staff_payroll_runs r
                             LEFT JOIN staff_accounts p ON p.user_id = r.prepared_by LEFT JOIN staff_accounts a ON a.user_id = r.approved_by
                             ORDER BY r.period DESC, r.id DESC LIMIT 36')->fetchAll();
        $missing = (int)$pdo->query('SELECT COUNT(*) FROM staff_accounts s LEFT JOIN staff_pay_records p ON p.user_id = s.user_id WHERE s.active = 1 AND p.user_id IS NULL')->fetchColumn();
        respond(200, [
            'runs' => array_map(static fn($r) => payrollRunShape($pdo, $r), $runs),
            'settings' => payrollSettings($pdo, date('Y-m-d')), 'defaults' => PAYROLL_DEFAULT_SETTINGS,
            'withoutPayRecord' => $missing, 'manage' => $manage, 'approve' => $approve, 'me' => $me, 'itemKinds' => PAYROLL_ITEM_KINDS,
        ]);
    }

    // ----- rates -----
    if ($route === 'staff/payroll/settings' && $method === 'POST') {
        if (!$manage) respond(403, ['error' => 'Only payroll can change the statutory rates.']);
        $data = input();
        [$rates, $error] = payrollCleanRates((array)($data['rates'] ?? []));
        if ($error) respond(400, ['error' => $error]);
        $from = (string)($data['effectiveFrom'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}$/', $from)) respond(400, ['error' => 'Say from which month these rates apply.']);
        $note = fieldText($data['note'] ?? '', 300);
        if ($note === null) respond(400, ['error' => 'Note where the rates were checked (e.g. "KRA and NSSF websites, 27 Oct 2026").']);
        $pdo->prepare('INSERT INTO staff_payroll_settings (effective_from, settings, confirmed_by, note) VALUES (?, ?, ?, ?)')
            ->execute([$from . '-01', json_encode($rates), $me, $note]);
        staffAudit($pdo, $me, 'payroll_rates_saved', 'payroll', null, 'From ' . $from . ': ' . $note);
        respond(200, ['settings' => payrollSettings($pdo, date('Y-m-d'))]);
    }

    // ----- pay records (payroll.manage only) -----
    if ($route === 'staff/payroll/records' && $method === 'GET') {
        if (!$manage) respond(403, ['error' => 'Only payroll sees pay records.']);
        $rows = $pdo->query("SELECT s.user_id, COALESCE(NULLIF(s.full_name, ''), u.email) AS name, s.staff_number, s.job_title, p.basic_salary, p.payment_method,
                                    p.bank_name, p.bank_account, p.mpesa_phone, p.on_payroll, p.kra_pin, (p.user_id IS NOT NULL) AS has_record
                             FROM staff_accounts s JOIN users u ON u.id = s.user_id LEFT JOIN staff_pay_records p ON p.user_id = s.user_id
                             WHERE s.active = 1 ORDER BY s.full_name IS NULL, s.full_name")->fetchAll();
        $counts = [];
        foreach ($pdo->query('SELECT user_id, COUNT(*) AS n FROM staff_pay_items WHERE run_id IS NULL GROUP BY user_id')->fetchAll() as $c) $counts[(int)$c['user_id']] = (int)$c['n'];
        respond(200, ['people' => array_map(static fn($r) => [
            'userId' => (int)$r['user_id'], 'name' => $r['name'], 'staffNumber' => $r['staff_number'], 'jobTitle' => $r['job_title'],
            'hasRecord' => (bool)$r['has_record'], 'onPayroll' => (bool)$r['on_payroll'], 'basic' => $r['basic_salary'] === null ? null : (float)$r['basic_salary'],
            'paymentMethod' => $r['payment_method'], 'bankName' => $r['bank_name'],
            'account' => payrollMask(cryptoDecrypt($config, $r['payment_method'] === 'mpesa' ? $r['mpesa_phone'] : $r['bank_account'])),
            'hasKraPin' => (string)$r['kra_pin'] !== '', 'recurringItems' => $counts[(int)$r['user_id']] ?? 0, 'me' => (int)$r['user_id'] === $me,
        ], $rows), 'encrypted' => cryptoAvailable($config)]);
    }
    if (preg_match('#^staff/payroll/records/(\d+)$#', $route, $m)) {
        if (!$manage) respond(403, ['error' => 'Only payroll sees pay records.']);
        $uid = (int)$m[1];
        $s = $pdo->prepare('SELECT COALESCE(NULLIF(s.full_name, \'\'), u.email) FROM staff_accounts s JOIN users u ON u.id = s.user_id WHERE s.user_id = ?');
        $s->execute([$uid]);
        $name = $s->fetchColumn();
        if ($name === false) respond(404, ['error' => 'No such member of staff.']);
        if ($method === 'GET') {
            $p = $pdo->prepare('SELECT * FROM staff_pay_records WHERE user_id = ?');
            $p->execute([$uid]);
            $r = $p->fetch() ?: [];
            $i = $pdo->prepare('SELECT id, kind, label, amount FROM staff_pay_items WHERE user_id = ? AND run_id IS NULL ORDER BY id');
            $i->execute([$uid]);
            if ($uid !== $me) staffAudit($pdo, $me, 'pay_record_viewed', 'staff', (string)$uid, $name);
            respond(200, ['record' => [
                'userId' => $uid, 'name' => $name, 'exists' => (bool)$r, 'basic' => isset($r['basic_salary']) ? (float)$r['basic_salary'] : null,
                'paymentMethod' => $r['payment_method'] ?? 'bank', 'bankName' => $r['bank_name'] ?? null, 'bankBranch' => $r['bank_branch'] ?? null,
                'bankAccount' => cryptoDecrypt($config, $r['bank_account'] ?? null), 'mpesaPhone' => cryptoDecrypt($config, $r['mpesa_phone'] ?? null),
                'kraPin' => cryptoDecrypt($config, $r['kra_pin'] ?? null), 'nssfNumber' => cryptoDecrypt($config, $r['nssf_number'] ?? null),
                'shifNumber' => cryptoDecrypt($config, $r['shif_number'] ?? null), 'onPayroll' => (bool)($r['on_payroll'] ?? true),
                'items' => array_map(static fn($x) => ['id' => (int)$x['id'], 'kind' => $x['kind'], 'label' => $x['label'], 'amount' => (float)$x['amount']], $i->fetchAll()),
                'mine' => $uid === $me,
            ]]);
        }
        if ($method === 'POST') {
            if ($uid === $me) respond(403, ['error' => 'Nobody changes their own pay record. Ask another payroll officer.']);
            $d = input();
            if (!is_numeric($d['basic'] ?? null) || (float)$d['basic'] < 0 || (float)$d['basic'] > 10000000) respond(400, ['error' => 'Give the basic monthly salary in shillings.']);
            $methodPay = ($d['paymentMethod'] ?? 'bank') === 'mpesa' ? 'mpesa' : 'bank';
            $clean = static fn($k, $max) => fieldText($d[$k] ?? '', $max);
            $kra = strtoupper((string)$clean('kraPin', 20));
            if ($kra !== '' && !preg_match('/^[AP]\d{9}[A-Z]$/', $kra)) respond(400, ['error' => 'A KRA PIN looks like A123456789B.']);
            $account = $clean('bankAccount', 40);
            $phone = $clean('mpesaPhone', 20);
            if ($methodPay === 'bank' && ($account === null || $clean('bankName', 80) === null)) respond(400, ['error' => 'Give the bank and account number.']);
            if ($methodPay === 'mpesa' && ($phone === null || !preg_match('/^(\+?254|0)[17]\d{8}$/', str_replace(' ', '', $phone)))) respond(400, ['error' => 'Give the M-Pesa number, e.g. 0712 345 678.']);
            $items = [];
            foreach ((array)($d['items'] ?? []) as $it) {
                if (!is_array($it)) continue;
                $label = fieldText($it['label'] ?? '', 80);
                if ($label === null && !is_numeric($it['amount'] ?? null)) continue;
                if (!isset(PAYROLL_ITEM_KINDS[$it['kind'] ?? '']) || $label === null || !is_numeric($it['amount'] ?? null) || (float)$it['amount'] <= 0) {
                    respond(400, ['error' => 'Each allowance or deduction needs a type, a name and an amount above zero.']);
                }
                $items[] = [$it['kind'], $label, round((float)$it['amount'], 2)];
            }
            if (count($items) > 20) respond(400, ['error' => 'Use at most 20 recurring items.']);
            $old = $pdo->prepare('SELECT basic_salary FROM staff_pay_records WHERE user_id = ?');
            $old->execute([$uid]);
            $oldBasic = $old->fetchColumn();
            $pdo->beginTransaction();
            $pdo->prepare('INSERT INTO staff_pay_records (user_id, basic_salary, payment_method, bank_name, bank_branch, bank_account, mpesa_phone, kra_pin, nssf_number, shif_number, on_payroll, updated_by)
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                           ON DUPLICATE KEY UPDATE basic_salary = VALUES(basic_salary), payment_method = VALUES(payment_method), bank_name = VALUES(bank_name),
                             bank_branch = VALUES(bank_branch), bank_account = VALUES(bank_account), mpesa_phone = VALUES(mpesa_phone), kra_pin = VALUES(kra_pin),
                             nssf_number = VALUES(nssf_number), shif_number = VALUES(shif_number), on_payroll = VALUES(on_payroll), updated_by = VALUES(updated_by)')
                ->execute([$uid, round((float)$d['basic'], 2), $methodPay, $clean('bankName', 80), $clean('bankBranch', 80),
                    cryptoEncrypt($config, $account), cryptoEncrypt($config, $phone), cryptoEncrypt($config, $kra ?: null),
                    cryptoEncrypt($config, $clean('nssfNumber', 30)), cryptoEncrypt($config, $clean('shifNumber', 30)), empty($d['offPayroll']) ? 1 : 0, $me]);
            $pdo->prepare('DELETE FROM staff_pay_items WHERE user_id = ? AND run_id IS NULL')->execute([$uid]);
            $add = $pdo->prepare('INSERT INTO staff_pay_items (user_id, run_id, kind, label, amount, created_by) VALUES (?, NULL, ?, ?, ?, ?)');
            foreach ($items as [$k, $l, $a]) $add->execute([$uid, $k, $l, $a, $me]);
            $pdo->commit();
            // The log says that pay changed and by whom, not to what.
            $what = $oldBasic === false ? 'pay record created' : ((float)$oldBasic !== round((float)$d['basic'], 2) ? 'basic salary changed' : 'pay record updated');
            staffAudit($pdo, $me, 'pay_record_saved', 'staff', (string)$uid, $name . ': ' . $what . ', ' . count($items) . ' recurring items');
            respond(200, ['ok' => true]);
        }
    }

    // ----- runs -----
    if ($route === 'staff/payroll/runs' && $method === 'POST') {
        if (!$manage) respond(403, ['error' => 'Only payroll prepares a run.']);
        $period = (string)(input()['period'] ?? '');
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period)) respond(400, ['error' => 'Choose the month.']);
        $busy = $pdo->prepare("SELECT status FROM staff_payroll_runs WHERE period = ? AND status <> 'void'");
        $busy->execute([$period]);
        if ($st = $busy->fetchColumn()) respond(400, ['error' => 'There is already a ' . $st . ' run for that month.']);
        $settings = payrollSettings($pdo, $period . '-01');
        $pdo->prepare('INSERT INTO staff_payroll_runs (period, settings, prepared_by) VALUES (?, ?, ?)')->execute([$period, json_encode($settings['rates']), $me]);
        $id = (int)$pdo->lastInsertId();
        payrollBuild($pdo, $config, $id, $settings['rates']);
        staffAudit($pdo, $me, 'payroll_run_created', 'payroll', (string)$id, $period);
        respond(201, ['run' => payrollRunShape($pdo, payrollRun($pdo, $id))]);
    }

    if (preg_match('#^staff/payroll/runs/(\d+)(?:/([a-z-]+))?(?:/(\d+))?(?:/(delete))?$#', $route, $m)) {
        $run = payrollRun($pdo, (int)$m[1]);
        $id = (int)$run['id'];
        $action = $m[2] ?? '';
        $draftOnly = function () use ($run, $manage): void {
            if (!$manage) respond(403, ['error' => 'Only payroll changes a run.']);
            if ($run['status'] !== 'draft') respond(400, ['error' => 'Only a draft run can be changed.']);
        };

        if ($action === '' && $method === 'GET') {
            $lines = $pdo->prepare('SELECT * FROM staff_payroll_lines WHERE run_id = ? ORDER BY name');
            $lines->execute([$id]);
            $oneOff = $pdo->prepare('SELECT i.id, i.user_id, i.kind, i.label, i.amount, COALESCE(s.full_name, \'\') AS name FROM staff_pay_items i LEFT JOIN staff_accounts s ON s.user_id = i.user_id WHERE i.run_id = ? ORDER BY name, i.id');
            $oneOff->execute([$id]);
            $missing = $pdo->query("SELECT COALESCE(NULLIF(s.full_name, ''), u.email) FROM staff_accounts s JOIN users u ON u.id = s.user_id LEFT JOIN staff_pay_records p ON p.user_id = s.user_id
                                    WHERE s.active = 1 AND (p.user_id IS NULL OR p.on_payroll = 0) ORDER BY 1")->fetchAll(PDO::FETCH_COLUMN);
            respond(200, ['run' => payrollRunShape($pdo, $run), 'rates' => json_decode($run['settings'], true),
                'lines' => array_map(static fn($l) => payrollLineShape($config, $l, false), $lines->fetchAll()),
                'oneOff' => array_map(static fn($i) => ['id' => (int)$i['id'], 'userId' => (int)$i['user_id'], 'name' => $i['name'], 'kind' => $i['kind'], 'label' => $i['label'], 'amount' => (float)$i['amount']], $oneOff->fetchAll()),
                'notOnRun' => $missing, 'ratesConfirmed' => payrollSettings($pdo, $run['period'] . '-01')['confirmed'],
                'mayApprove' => $approve && (int)$run['prepared_by'] !== $me && $run['status'] === 'submitted']);
        }
        if ($action === 'recalculate' && $method === 'POST') {
            $draftOnly();
            $settings = payrollSettings($pdo, $run['period'] . '-01');
            $pdo->prepare('UPDATE staff_payroll_runs SET settings = ? WHERE id = ?')->execute([json_encode($settings['rates']), $id]);
            payrollBuild($pdo, $config, $id, $settings['rates']);
            respond(200, ['run' => payrollRunShape($pdo, payrollRun($pdo, $id))]);
        }
        if ($action === 'items' && empty($m[3]) && $method === 'POST') {
            $draftOnly();
            $d = input();
            $uid = (int)($d['userId'] ?? 0);
            if ($uid === $me) respond(403, ['error' => 'Nobody adds pay items for themselves.']);
            $on = $pdo->prepare('SELECT 1 FROM staff_payroll_lines WHERE run_id = ? AND user_id = ?');
            $on->execute([$id, $uid]);
            if (!$on->fetchColumn()) respond(400, ['error' => 'That person is not on this run.']);
            $label = fieldText($d['label'] ?? '', 80);
            if (!isset(PAYROLL_ITEM_KINDS[$d['kind'] ?? '']) || $label === null || !is_numeric($d['amount'] ?? null) || (float)$d['amount'] <= 0 || (float)$d['amount'] > 10000000) {
                respond(400, ['error' => 'Give a type, a name and an amount above zero.']);
            }
            $pdo->prepare('INSERT INTO staff_pay_items (user_id, run_id, kind, label, amount, created_by) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([$uid, $id, $d['kind'], $label, round((float)$d['amount'], 2), $me]);
            payrollBuild($pdo, $config, $id, json_decode($run['settings'], true));
            staffAudit($pdo, $me, 'payroll_item_added', 'payroll', (string)$id, $run['period'] . ': ' . $label . ' for user ' . $uid);
            respond(200, ['ok' => true]);
        }
        if ($action === 'items' && !empty($m[3]) && !empty($m[4]) && $method === 'POST') {
            $draftOnly();
            $pdo->prepare('DELETE FROM staff_pay_items WHERE id = ? AND run_id = ?')->execute([(int)$m[3], $id]);
            payrollBuild($pdo, $config, $id, json_decode($run['settings'], true));
            respond(200, ['ok' => true]);
        }
        if ($action === 'submit' && $method === 'POST') {
            $draftOnly();
            if (!payrollSettings($pdo, $run['period'] . '-01')['confirmed']) respond(400, ['error' => 'Confirm the statutory rates (Payroll > Rates) before submitting a run.']);
            $count = $pdo->prepare('SELECT COUNT(*), SUM(net < 0) FROM staff_payroll_lines WHERE run_id = ?');
            $count->execute([$id]);
            [$n, $neg] = $count->fetch(PDO::FETCH_NUM);
            if (!(int)$n) respond(400, ['error' => 'The run has nobody on it.']);
            if ((int)$neg) respond(400, ['error' => 'Someone\'s net pay is below zero. Correct their deductions first.']);
            $pdo->prepare("UPDATE staff_payroll_runs SET status = 'submitted', submitted_at = NOW(), return_reason = NULL WHERE id = ?")->execute([$id]);
            $approvers = $pdo->query("SELECT user_id FROM staff_grants WHERE capability = 'payroll.approve'")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($approvers as $a) if ((int)$a !== (int)$run['prepared_by']) staffNotify($pdo, (int)$a, 'payroll', 'Payroll for ' . date('F Y', strtotime($run['period'] . '-01')) . ' is ready for approval', '#payroll', 'pr-sub:' . $id . ':' . time());
            staffAudit($pdo, $me, 'payroll_run_submitted', 'payroll', (string)$id, $run['period']);
            respond(200, ['run' => payrollRunShape($pdo, payrollRun($pdo, $id))]);
        }
        if (($action === 'approve' || $action === 'return') && $method === 'POST') {
            if (!$approve) respond(403, ['error' => 'Only a payroll approver decides a run.']);
            if ((int)$run['prepared_by'] === $me) respond(403, ['error' => 'You prepared this run, so someone else must approve it.']);
            if ($run['status'] !== 'submitted') respond(400, ['error' => 'This run is not waiting for approval.']);
            if ($action === 'return') {
                $reason = fieldText(input()['reason'] ?? '', 500);
                if ($reason === null) respond(400, ['error' => 'Say what needs correcting.']);
                $pdo->prepare("UPDATE staff_payroll_runs SET status = 'draft', return_reason = ?, submitted_at = NULL WHERE id = ?")->execute([$reason, $id]);
                staffNotify($pdo, (int)$run['prepared_by'], 'payroll', 'Payroll for ' . date('F Y', strtotime($run['period'] . '-01')) . ' was returned: ' . $reason, '#payroll', 'pr-ret:' . $id . ':' . time());
                staffAudit($pdo, $me, 'payroll_run_returned', 'payroll', (string)$id, $run['period'] . ': ' . $reason);
            } else {
                $pdo->prepare("UPDATE staff_payroll_runs SET status = 'approved', approved_by = ?, approved_at = NOW() WHERE id = ? AND status = 'submitted'")->execute([$me, $id]);
                $label = date('F Y', strtotime($run['period'] . '-01'));
                $who = $pdo->prepare('SELECT user_id FROM staff_payroll_lines WHERE run_id = ?');
                $who->execute([$id]);
                foreach ($who->fetchAll(PDO::FETCH_COLUMN) as $u) staffNotify($pdo, (int)$u, 'payslip', 'Your payslip for ' . $label . ' is ready', '#payslips', 'payslip:' . $id);
                staffAudit($pdo, $me, 'payroll_run_approved', 'payroll', (string)$id, $run['period']);
            }
            respond(200, ['run' => payrollRunShape($pdo, payrollRun($pdo, $id))]);
        }
        if ($action === 'delete' && $method === 'POST') {
            $draftOnly();
            $pdo->prepare('DELETE FROM staff_payroll_lines WHERE run_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM staff_pay_items WHERE run_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM staff_payroll_runs WHERE id = ?')->execute([$id]);
            staffAudit($pdo, $me, 'payroll_run_deleted', 'payroll', (string)$id, $run['period']);
            respond(200, ['ok' => true]);
        }
        if ($action === 'void' && $method === 'POST') {
            if (!$gate['super']) respond(403, ['error' => 'Only a super administrator voids an approved run.']);
            if ($run['status'] !== 'approved') respond(400, ['error' => 'Only an approved run is voided; a draft is simply deleted.']);
            $reason = fieldText(input()['reason'] ?? '', 500);
            if ($reason === null) respond(400, ['error' => 'Give the reason for voiding.']);
            $pdo->prepare("UPDATE staff_payroll_runs SET status = 'void', voided_by = ?, voided_at = NOW(), void_reason = ? WHERE id = ?")->execute([$me, $reason, $id]);
            staffAudit($pdo, $me, 'payroll_run_voided', 'payroll', (string)$id, $run['period'] . ': ' . $reason);
            respond(200, ['ok' => true]);
        }

        // ----- files: payroll register, bank or M-Pesa list, statutory summary -----
        if ($action === 'export' && $method === 'GET') {
            $type = (string)($_GET['type'] ?? '');
            if ($type === 'bank' && $run['status'] !== 'approved') respond(400, ['error' => 'The payment file is made only from an approved run.']);
            $lines = $pdo->prepare('SELECT * FROM staff_payroll_lines WHERE run_id = ? ORDER BY name');
            $lines->execute([$id]);
            $all = array_map(static fn($l) => payrollLineShape($config, $l, true), $lines->fetchAll());
            $ref = 'Salary ' . date('M Y', strtotime($run['period'] . '-01'));
            staffAudit($pdo, $me, 'payroll_exported', 'payroll', (string)$id, $run['period'] . ' ' . $type);
            if ($type === 'register') {
                timesheetCsv('payroll-' . $run['period'] . '.csv',
                    ['Name', 'Staff no.', 'KRA PIN', 'Basic', 'Gross', 'NSSF', 'SHIF', 'Housing Levy', 'Taxable pay', 'PAYE', 'Pension', 'Other deductions', 'Net pay', 'Employer NSSF', 'Employer Housing Levy'],
                    array_map(static fn($l) => [$l['name'], $l['staffNumber'], $l['kraPin'], $l['basic'], $l['gross'], $l['nssf'], $l['shif'], $l['housingLevy'], $l['taxablePay'],
                        $l['paye'], $l['pension'], $l['otherDeductions'], $l['net'], $l['employerNssf'], $l['employerHousingLevy']], $all));
            }
            if ($type === 'bank') {
                timesheetCsv('payments-' . $run['period'] . '.csv', ['Name', 'Method', 'Bank', 'Branch', 'Account / phone', 'Amount', 'Reference'],
                    array_map(static fn($l) => [$l['name'], $l['paymentMethod'] === 'mpesa' ? 'M-Pesa' : 'Bank', $l['bankName'], $l['bankBranch'], $l['account'], number_format($l['net'], 2, '.', ''), $ref], $all));
            }
            if ($type === 'statutory') {
                $sum = static fn($k) => round(array_sum(array_column($all, $k)), 2);
                timesheetCsv('statutory-' . $run['period'] . '.csv', ['Return', 'Employee', 'Employer', 'Total to remit'], [
                    ['PAYE (KRA)', $sum('paye'), 0, $sum('paye')],
                    ['NSSF', $sum('nssf'), $sum('employerNssf'), round($sum('nssf') + $sum('employerNssf'), 2)],
                    ['SHIF (SHA)', $sum('shif'), 0, $sum('shif')],
                    ['Affordable Housing Levy', $sum('housingLevy'), $sum('employerHousingLevy'), round($sum('housingLevy') + $sum('employerHousingLevy'), 2)],
                ]);
            }
            respond(400, ['error' => 'Choose register, bank or statutory.']);
        }
    }

    respond(404, ['error' => 'Route not found']);
}
