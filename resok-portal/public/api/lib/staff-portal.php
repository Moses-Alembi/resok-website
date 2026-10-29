<?php
declare(strict_types=1);

/**
 * The staff intranet: profiles and directory, departments and reporting lines, announcements,
 * in-app notifications, staff permissions and their activity log.
 *
 * It sits on the staff accounts in lib/staff.php and on what the portal already has - sign-in,
 * two-factor, session revocation, the ICT helpdesk and the asset register - rather than being
 * a second application with its own logins.
 *
 * Rules that shape it:
 *
 * 1. Who gets in. Active staff (a staff_accounts row with active = 1) and super administrators.
 *    Members, learners and ordinary admins do not, whatever they guess at in a URL.
 *
 * 2. Two grantable permissions, both given only by a super administrator:
 *      staff.manage           staff records, departments, reporting managers, deactivation
 *      announcements.publish  write, publish and archive announcements
 *    Super administrators hold both. Nobody can grant a permission to themselves.
 *
 * 3. The directory shows work details only: name, title, department, manager, work email,
 *    work phone and office. Staff number and start date are HR fields, seen by the person
 *    themselves and by staff.manage, never in the directory.
 *
 * 4. A person edits only their own work phone, office and short bio. Title, department,
 *    manager and staff number belong to staff.manage.
 *
 * 5. Every permission change, record change, deactivation and announcement action is written
 *    to staff_audit.
 *
 * Timesheets, payroll, procurement and appraisals live in lib/staff-timesheet.php,
 * staff-payroll.php, staff-procurement.php and staff-appraisal.php.
 *
 * Leave, documents and policies, tasks and projects, and the calendar are not built: they
 * need the Society's HR policy and a decision on Microsoft 365, and show as "coming soon".
 */

const STAFF_CAPABILITIES = [
    'staff.manage'          => 'Manage staff records, departments and reporting managers',
    'announcements.publish' => 'Write and publish staff announcements',
    'payroll.manage'        => 'Run payroll: pay records, monthly runs and payslips (sees everyone\'s pay)',
    'payroll.approve'       => 'Approve a payroll run that someone else prepared',
    'procurement.approve'   => 'Finance approval of purchase requests, suppliers and purchase orders',
];

function staffPortalEnsureTables(PDO $pdo): bool
{
    static $ready = null;
    if ($ready !== null) return $ready;
    if (!staffEnsureTables($pdo)) return $ready = false;
    try {
        $tail = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        // Profile fields live on the staff row itself: one row per person, nothing to join.
        $have = array_column($pdo->query('SHOW COLUMNS FROM staff_accounts')->fetchAll(), 'Field');
        if (!in_array('job_title', $have, true)) {
            $pdo->exec('ALTER TABLE staff_accounts
                ADD COLUMN staff_number VARCHAR(30) NULL AFTER full_name,
                ADD COLUMN job_title VARCHAR(120) NULL AFTER staff_number,
                ADD COLUMN department_id INT UNSIGNED NULL AFTER job_title,
                ADD COLUMN manager_user_id INT UNSIGNED NULL AFTER department_id,
                ADD COLUMN work_phone VARCHAR(40) NULL AFTER manager_user_id,
                ADD COLUMN office VARCHAR(120) NULL AFTER work_phone,
                ADD COLUMN bio VARCHAR(500) NULL AFTER office,
                ADD COLUMN start_date DATE NULL AFTER bio,
                ADD COLUMN active TINYINT(1) NOT NULL DEFAULT 1 AFTER start_date,
                ADD COLUMN deactivated_at DATETIME NULL AFTER active,
                ADD UNIQUE KEY staff_accounts_number (staff_number),
                ADD KEY staff_accounts_department (department_id),
                ADD KEY staff_accounts_manager (manager_user_id)');
        }
        $pdo->exec("CREATE TABLE IF NOT EXISTS staff_departments (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(120) NOT NULL,
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY staff_departments_name (name)
        )" . $tail);
        $pdo->exec("CREATE TABLE IF NOT EXISTS staff_grants (
            user_id INT UNSIGNED NOT NULL,
            capability VARCHAR(60) NOT NULL,
            granted_by INT UNSIGNED NULL,
            granted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id, capability)
        )" . $tail);
        $pdo->exec("CREATE TABLE IF NOT EXISTS staff_announcements (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            title VARCHAR(200) NOT NULL,
            body MEDIUMTEXT NOT NULL,
            status ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
            department_id INT UNSIGNED NULL,
            pinned TINYINT(1) NOT NULL DEFAULT 0,
            requires_ack TINYINT(1) NOT NULL DEFAULT 0,
            created_by INT UNSIGNED NULL,
            updated_by INT UNSIGNED NULL,
            published_by INT UNSIGNED NULL,
            published_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY staff_announcements_feed (status, published_at)
        )" . $tail);
        $pdo->exec("CREATE TABLE IF NOT EXISTS staff_announcement_reads (
            announcement_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            read_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            acknowledged_at DATETIME NULL,
            PRIMARY KEY (announcement_id, user_id)
        )" . $tail);
        $pdo->exec("CREATE TABLE IF NOT EXISTS staff_notifications (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id INT UNSIGNED NOT NULL,
            kind VARCHAR(40) NOT NULL,
            title VARCHAR(255) NOT NULL,
            link VARCHAR(255) NULL,
            dedupe_key VARCHAR(120) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            read_at DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY staff_notifications_once (user_id, dedupe_key),
            KEY staff_notifications_inbox (user_id, read_at, id)
        )" . $tail);
        $pdo->exec("CREATE TABLE IF NOT EXISTS staff_audit (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            actor_user_id INT UNSIGNED NULL,
            action VARCHAR(60) NOT NULL,
            target_type VARCHAR(40) NULL,
            target_id VARCHAR(40) NULL,
            summary VARCHAR(300) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY staff_audit_time (created_at)
        )" . $tail);
        return $ready = true;
    } catch (Throwable $e) {
        error_log('Staff portal tables unavailable: ' . $e->getMessage());
        return $ready = false;
    }
}

function staffAudit(PDO $pdo, ?int $actor, string $action, ?string $type = null, ?string $id = null, ?string $summary = null): void
{
    try {
        $pdo->prepare('INSERT INTO staff_audit (actor_user_id, action, target_type, target_id, summary) VALUES (?, ?, ?, ?, ?)')
            ->execute([$actor, $action, $type, $id, $summary === null ? null : mb_substr($summary, 0, 300)]);
    } catch (Throwable $e) {
        error_log('Staff audit write failed: ' . $e->getMessage());
    }
}

// ---------------------------------------------------------------------------------------
// Who is asking
// ---------------------------------------------------------------------------------------

/** The capabilities this user holds. Super administrators hold all of them. */
function staffCaps(PDO $pdo, array $user, array $config): array
{
    if (isSuperAdmin($user, $config)) return array_keys(STAFF_CAPABILITIES);
    $stmt = $pdo->prepare('SELECT capability FROM staff_grants WHERE user_id = ?');
    $stmt->execute([(int)$user['userId']]);
    return array_values(array_intersect(array_keys(STAFF_CAPABILITIES), $stmt->fetchAll(PDO::FETCH_COLUMN)));
}

/**
 * The gate for every staff route. Returns the caller's staff row (null for a super admin who
 * is not on the staff list) and capabilities; refuses everyone else.
 *
 * @return array{row: ?array, caps: string[], super: bool}
 */
function staffGate(PDO $pdo, array $user, array $config): array
{
    $stmt = $pdo->prepare('SELECT * FROM staff_accounts WHERE user_id = ? AND active = 1');
    $stmt->execute([(int)$user['userId']]);
    $row = $stmt->fetch() ?: null;
    $super = isSuperAdmin($user, $config);
    if (!$row && !$super) respond(403, ['error' => 'This area is for ReSoK staff. Your account is not on the staff list.']);
    return ['row' => $row, 'caps' => staffCaps($pdo, $user, $config), 'super' => $super];
}

function staffNeed(array $gate, string $capability): void
{
    if (!in_array($capability, $gate['caps'], true)) {
        respond(403, ['error' => 'You do not have permission for this.', 'capability' => $capability]);
    }
}

// ---------------------------------------------------------------------------------------
// Notifications
// ---------------------------------------------------------------------------------------

/** One in-app notification. A dedupe key makes it once-only, so a retried event cannot repeat it. */
function staffNotify(PDO $pdo, int $userId, string $kind, string $title, ?string $link = null, ?string $dedupe = null): void
{
    try {
        if (!staffPortalEnsureTables($pdo)) return;
        $pdo->prepare('INSERT IGNORE INTO staff_notifications (user_id, kind, title, link, dedupe_key) VALUES (?, ?, ?, ?, ?)')
            ->execute([$userId, $kind, mb_substr($title, 0, 255), $link, $dedupe]);
    } catch (Throwable $e) {
        error_log('Staff notification failed: ' . $e->getMessage());
    }
}

/** For events that know an email address, not an account: asset hand-overs, tickets. */
function staffNotifyByEmail(PDO $pdo, ?string $email, string $kind, string $title, ?string $link = null, ?string $dedupe = null): void
{
    $email = strtolower(trim((string)$email));
    if ($email === '') return;
    try {
        if (!staffPortalEnsureTables($pdo)) return;
        $stmt = $pdo->prepare('SELECT s.user_id FROM staff_accounts s JOIN users u ON u.id = s.user_id WHERE LOWER(u.email) = ? AND s.active = 1');
        $stmt->execute([$email]);
        $userId = (int)$stmt->fetchColumn();
        if ($userId) staffNotify($pdo, $userId, $kind, $title, $link, $dedupe);
    } catch (Throwable $e) {
        error_log('Staff notification by email failed: ' . $e->getMessage());
    }
}

function staffNotifications(PDO $pdo, int $userId, int $limit = 30): array
{
    $stmt = $pdo->prepare('SELECT id, kind, title, link, created_at, read_at FROM staff_notifications WHERE user_id = ? ORDER BY id DESC LIMIT ' . max(1, min(100, $limit)));
    $stmt->execute([$userId]);
    $unread = $pdo->prepare('SELECT COUNT(*) FROM staff_notifications WHERE user_id = ? AND read_at IS NULL');
    $unread->execute([$userId]);
    return [
        'unread' => (int)$unread->fetchColumn(),
        'items' => array_map(static fn($r) => ['id' => (int)$r['id'], 'kind' => $r['kind'], 'title' => $r['title'],
            'link' => $r['link'], 'createdAt' => $r['created_at'], 'read' => $r['read_at'] !== null], $stmt->fetchAll()),
    ];
}

// ---------------------------------------------------------------------------------------
// People
// ---------------------------------------------------------------------------------------

function staffText($value, int $max): ?string
{
    $text = trim((string)preg_replace('/\s+/u', ' ', is_scalar($value) ? (string)$value : ''));
    return $text === '' ? null : mb_substr($text, 0, $max);
}

/**
 * The address shown in the directory: a work address on the Society's domain, or nothing.
 * Many staff sign in with a personal Gmail; that is theirs, not something every colleague
 * should be shown (Data Protection Act - seen on the live directory, 27 Oct 2026).
 * 'staff_email_domain' in config overrides resok.org.
 */
function staffWorkEmail(string $email): ?string
{
    global $config;
    $domain = strtolower(trim((string)($config['staff_email_domain'] ?? 'resok.org')));
    $email = strtolower(trim($email));
    return $domain !== '' && str_ends_with($email, '@' . $domain) ? $email : null;
}

/** Active staff for the directory: work details only. */
function staffDirectory(PDO $pdo, string $search = ''): array
{
    $sql = 'SELECT s.user_id, s.full_name, s.job_title, s.work_phone, s.office, s.bio, u.email,
                   d.name AS department, m.full_name AS manager_name
            FROM staff_accounts s
            JOIN users u ON u.id = s.user_id
            LEFT JOIN staff_departments d ON d.id = s.department_id
            LEFT JOIN staff_accounts m ON m.user_id = s.manager_user_id
            WHERE s.active = 1';
    $args = [];
    if ($search !== '') {
        // Searching by address matches work addresses only: a search must not reveal a hidden one.
        $sql .= ' AND (s.full_name LIKE ? OR s.job_title LIKE ? OR d.name LIKE ? OR (u.email LIKE ? AND u.email LIKE ?) OR s.office LIKE ?)';
        $like = '%' . $search . '%';
        $domain = strtolower(trim((string)($GLOBALS['config']['staff_email_domain'] ?? 'resok.org')));
        $args = [$like, $like, $like, $like, '%@' . $domain, $like];
    }
    $stmt = $pdo->prepare($sql . ' ORDER BY s.full_name IS NULL, s.full_name LIMIT 500');
    $stmt->execute($args);
    return array_map(static fn($r) => [
        'userId' => (int)$r['user_id'], 'name' => $r['full_name'], 'jobTitle' => $r['job_title'],
        'department' => $r['department'], 'manager' => $r['manager_name'], 'email' => staffWorkEmail((string)$r['email']),
        'workPhone' => $r['work_phone'], 'office' => $r['office'], 'bio' => $r['bio'],
    ], $stmt->fetchAll());
}

/** One full staff record, HR fields included: for the person themselves and staff.manage. */
function staffRecord(PDO $pdo, int $userId): ?array
{
    $stmt = $pdo->prepare('SELECT s.*, u.email, u.email_verified, d.name AS department, m.full_name AS manager_name
                           FROM staff_accounts s JOIN users u ON u.id = s.user_id
                           LEFT JOIN staff_departments d ON d.id = s.department_id
                           LEFT JOIN staff_accounts m ON m.user_id = s.manager_user_id
                           WHERE s.user_id = ?');
    $stmt->execute([$userId]);
    $r = $stmt->fetch();
    if (!$r) return null;
    return [
        'userId' => (int)$r['user_id'], 'name' => $r['full_name'], 'email' => $r['email'],
        'staffNumber' => $r['staff_number'], 'jobTitle' => $r['job_title'],
        'departmentId' => $r['department_id'] === null ? null : (int)$r['department_id'], 'department' => $r['department'],
        'managerUserId' => $r['manager_user_id'] === null ? null : (int)$r['manager_user_id'], 'manager' => $r['manager_name'],
        'workPhone' => $r['work_phone'], 'office' => $r['office'], 'bio' => $r['bio'], 'startDate' => $r['start_date'],
        'active' => (bool)$r['active'], 'deactivatedAt' => $r['deactivated_at'],
        // Where they may sign in from (lib/staff-geo.php); empty means anywhere in Kenya.
        'workCounties' => array_values(array_filter(array_map('trim', explode(',', (string)($r['work_counties'] ?? ''))))),
        'accountReady' => (int)$r['email_verified'] === 1,
    ];
}

/** What a person may change about themselves. */
function staffSaveOwn(PDO $pdo, int $userId, array $data): ?string
{
    $phone = staffText($data['workPhone'] ?? '', 40);
    if ($phone !== null && !preg_match('/^[0-9+()\s-]{7,40}$/', $phone)) return 'Enter the work phone as digits, e.g. +254 712 345 678.';
    $pdo->prepare('UPDATE staff_accounts SET work_phone = ?, office = ?, bio = ? WHERE user_id = ?')
        ->execute([$phone, staffText($data['office'] ?? '', 120), staffText($data['bio'] ?? '', 500), $userId]);
    return null;
}

/**
 * What staff.manage may change about someone. A manager must be another active staff member,
 * and may not be someone who already reports (directly or not) to this person, which would
 * make a loop nobody could ever resolve on an org chart.
 */
function staffSaveRecord(PDO $pdo, int $userId, array $data): ?string
{
    $number = staffText($data['staffNumber'] ?? '', 30);
    $dept = isset($data['departmentId']) && $data['departmentId'] !== '' && $data['departmentId'] !== null ? (int)$data['departmentId'] : null;
    $manager = isset($data['managerUserId']) && $data['managerUserId'] !== '' && $data['managerUserId'] !== null ? (int)$data['managerUserId'] : null;
    $start = staffText($data['startDate'] ?? '', 10);
    $name = staffText($data['name'] ?? '', 160);

    if ($start !== null && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) || !strtotime($start))) return 'Enter the start date as a date.';
    if ($number !== null) {
        $dup = $pdo->prepare('SELECT user_id FROM staff_accounts WHERE staff_number = ? AND user_id <> ?');
        $dup->execute([$number, $userId]);
        if ($dup->fetchColumn()) return 'Staff number ' . $number . ' is already used by someone else.';
    }
    if ($dept !== null) {
        $ok = $pdo->prepare('SELECT 1 FROM staff_departments WHERE id = ? AND active = 1');
        $ok->execute([$dept]);
        if (!$ok->fetchColumn()) return 'Choose a department from the list.';
    }
    if ($manager !== null) {
        if ($manager === $userId) return 'Someone cannot report to themselves.';
        $ok = $pdo->prepare('SELECT 1 FROM staff_accounts WHERE user_id = ? AND active = 1');
        $ok->execute([$manager]);
        if (!$ok->fetchColumn()) return 'The manager must be an active member of staff.';
        // Walk up from the proposed manager; meeting this person means a loop.
        $seen = [];
        $step = $pdo->prepare('SELECT manager_user_id FROM staff_accounts WHERE user_id = ?');
        for ($at = $manager; $at !== null && !isset($seen[$at]); ) {
            if ($at === $userId) return 'That would make a loop: this person is already above them in the reporting line.';
            $seen[$at] = true;
            $step->execute([$at]);
            $next = $step->fetchColumn();
            $at = ($next === false || $next === null) ? null : (int)$next;
        }
    }
    $pdo->prepare('UPDATE staff_accounts SET full_name = COALESCE(?, full_name), staff_number = ?, job_title = ?, department_id = ?, manager_user_id = ?, start_date = ? WHERE user_id = ?')
        ->execute([$name, $number, staffText($data['jobTitle'] ?? '', 120), $dept, $manager, $start, $userId]);
    // Counties of operation, only when the form sends them: an older page leaves them alone.
    if (array_key_exists('workCounties', $data) && function_exists('geoCounty') && geoEnsureTables($pdo)) {
        $counties = [];
        foreach ((array)$data['workCounties'] as $c) {
            $n = geoCounty(is_scalar($c) ? (string)$c : '');
            if ($n === null) return 'Choose counties from the list.';
            $counties[$n] = true;
        }
        $pdo->prepare('UPDATE staff_accounts SET work_counties = ? WHERE user_id = ?')
            ->execute([$counties ? implode(',', array_keys($counties)) : null, $userId]);
    }
    return null;
}

function staffDepartments(PDO $pdo, bool $includeInactive = false): array
{
    $rows = $pdo->query('SELECT d.id, d.name, d.active, (SELECT COUNT(*) FROM staff_accounts s WHERE s.department_id = d.id AND s.active = 1) AS people
                         FROM staff_departments d' . ($includeInactive ? '' : ' WHERE d.active = 1') . ' ORDER BY d.name')->fetchAll();
    return array_map(static fn($r) => ['id' => (int)$r['id'], 'name' => $r['name'], 'active' => (bool)$r['active'], 'people' => (int)$r['people']], $rows);
}

// ---------------------------------------------------------------------------------------
// Announcements
// ---------------------------------------------------------------------------------------

function staffAnnouncementShape(array $r): array
{
    return [
        'id' => (int)$r['id'], 'title' => $r['title'], 'body' => $r['body'], 'status' => $r['status'],
        'departmentId' => $r['department_id'] === null ? null : (int)$r['department_id'],
        'audience' => $r['department_name'] ?? null,
        'pinned' => (bool)$r['pinned'], 'requiresAck' => (bool)$r['requires_ack'],
        'author' => $r['author_name'] ?? null,
        'publishedAt' => $r['published_at'], 'updatedAt' => $r['updated_at'],
        'readAt' => $r['read_at'] ?? null, 'acknowledgedAt' => $r['acknowledged_at'] ?? null,
    ];
}

/**
 * Published announcements this person is in the audience for: everyone's, plus their own
 * department's. Pinned first, then newest.
 */
function staffAnnouncementFeed(PDO $pdo, int $userId, ?int $departmentId, int $limit = 50, string $search = ''): array
{
    $sql = 'SELECT a.*, d.name AS department_name, au.full_name AS author_name, r.read_at, r.acknowledged_at
            FROM staff_announcements a
            LEFT JOIN staff_departments d ON d.id = a.department_id
            LEFT JOIN staff_accounts au ON au.user_id = a.published_by
            LEFT JOIN staff_announcement_reads r ON r.announcement_id = a.id AND r.user_id = ?
            WHERE a.status = \'published\' AND (a.department_id IS NULL OR a.department_id = ?)';
    $args = [$userId, $departmentId ?? 0];
    if ($search !== '') { $sql .= ' AND (a.title LIKE ? OR a.body LIKE ?)'; $args[] = '%' . $search . '%'; $args[] = '%' . $search . '%'; }
    $stmt = $pdo->prepare($sql . ' ORDER BY a.pinned DESC, a.published_at DESC LIMIT ' . max(1, min(200, $limit)));
    $stmt->execute($args);
    return array_map('staffAnnouncementShape', $stmt->fetchAll());
}

/** Everything, every state, with read and acknowledgement counts: for editors. */
function staffAnnouncementsManage(PDO $pdo): array
{
    $rows = $pdo->query('SELECT a.*, d.name AS department_name, au.full_name AS author_name,
                                (SELECT COUNT(*) FROM staff_announcement_reads r WHERE r.announcement_id = a.id) AS reads_count,
                                (SELECT COUNT(*) FROM staff_announcement_reads r WHERE r.announcement_id = a.id AND r.acknowledged_at IS NOT NULL) AS acks_count
                         FROM staff_announcements a
                         LEFT JOIN staff_departments d ON d.id = a.department_id
                         LEFT JOIN staff_accounts au ON au.user_id = COALESCE(a.published_by, a.created_by)
                         ORDER BY (a.status = \'draft\') DESC, COALESCE(a.published_at, a.updated_at) DESC LIMIT 200')->fetchAll();
    return array_map(static fn($r) => staffAnnouncementShape($r) + ['reads' => (int)$r['reads_count'], 'acks' => (int)$r['acks_count']], $rows);
}

/** Who should see an announcement: active staff, all or one department. */
function staffAnnouncementAudience(PDO $pdo, array $a): array
{
    if ($a['department_id'] === null) {
        return $pdo->query('SELECT s.user_id, u.email, s.full_name FROM staff_accounts s JOIN users u ON u.id = s.user_id WHERE s.active = 1')->fetchAll();
    }
    $stmt = $pdo->prepare('SELECT s.user_id, u.email, s.full_name FROM staff_accounts s JOIN users u ON u.id = s.user_id WHERE s.active = 1 AND s.department_id = ?');
    $stmt->execute([(int)$a['department_id']]);
    return $stmt->fetchAll();
}

function staffAnnouncementRow(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM staff_announcements WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

/**
 * Publishes: notifies everyone in the audience in the portal and, if asked, by email through
 * the shared mail queue - so a staff-wide email stays under the host's hourly limit, and the
 * dedupe key means publishing twice never mails anyone twice.
 */
function staffAnnouncementPublish(PDO $pdo, array $config, array $a, int $userId, bool $email): int
{
    $pdo->prepare("UPDATE staff_announcements SET status = 'published', published_at = COALESCE(published_at, NOW()), published_by = COALESCE(published_by, ?), updated_by = ? WHERE id = ?")
        ->execute([$userId, $userId, (int)$a['id']]);
    $audience = staffAnnouncementAudience($pdo, $a);
    $link = 'staff#announcement-' . (int)$a['id'];
    foreach ($audience as $p) {
        staffNotify($pdo, (int)$p['user_id'], 'announcement', ($a['requires_ack'] ? 'Please read and confirm: ' : 'New announcement: ') . $a['title'],
            $link, 'announcement:' . (int)$a['id']);
    }
    if ($email && function_exists('absMailPump') && abstractsEnsureTables($pdo)) {
        // Everyone is queued first and one small batch sent after: sending as each is queued
        // would keep the editor waiting on the mail server once per member of staff.
        $insert = $pdo->prepare("INSERT IGNORE INTO abs_emails (template, to_email, subject, status, text_body, html_body, dedupe_key)
                                 VALUES ('staff-announcement', ?, ?, 'queued', ?, ?, ?)");
        $url = rtrim((string)($config['portal_base_url'] ?? ''), '/') . '/staff#announcement-' . (int)$a['id'];
        foreach ($audience as $p) {
            $salutation = function_exists('staffSalutation') ? staffSalutation($p['full_name']) : 'Dear colleague,';
            $text = $salutation . "\n\n" . trim((string)$a['body']) . "\n\nRead it on the staff portal:\n" . $url . "\n\nRespiratory Society of Kenya";
            $paras = '';
            foreach (preg_split("/\n{2,}/", trim((string)$a['body'])) ?: [] as $para) {
                $paras .= '<p style="margin:0 0 14px;font-size:15px;line-height:1.65;">' . nl2br(htmlspecialchars($para, ENT_QUOTES)) . '</p>';
            }
            $html = brandedEmailHtml((string)$a['title'],
                '<p style="margin:0 0 14px;font-size:15px;">' . htmlspecialchars($salutation, ENT_QUOTES) . '</p>' . $paras,
                $a['requires_ack'] ? 'Read and confirm' : 'Open the staff portal', $url);
            $insert->execute([mb_substr((string)$p['email'], 0, 190), mb_substr('ReSoK staff: ' . $a['title'], 0, 300), $text, $html,
                              'staff-ann:' . (int)$a['id'] . ':' . (int)$p['user_id']]);
        }
        absMailPump($pdo, $config, 10);
    }
    return count($audience);
}

// ---------------------------------------------------------------------------------------
// Routes
// ---------------------------------------------------------------------------------------

function staffPortalHandle(PDO $pdo, array $config, string $route, string $method, array $user): void
{
    if (!staffPortalEnsureTables($pdo)) respond(503, ['error' => 'The staff portal tables could not be created.']);
    $gate = staffGate($pdo, $user, $config);
    $me = (int)$user['userId'];
    $myDept = $gate['row'] && $gate['row']['department_id'] !== null ? (int)$gate['row']['department_id'] : null;

    // Field work and its reports (lib/staff-field.php), behind the same gate.
    if (strpos($route, 'staff/field') === 0) {
        requireModule('fieldHandle', 'lib/staff-field.php');
        fieldHandle($pdo, $config, $route, $method, $user, $gate);
    }

    // Timesheets, payroll, procurement and appraisals, each in its own file behind the same gate.
    foreach ([
        'staff/timesheets' => ['timesheetHandle', 'lib/staff-timesheet.php'],
        'staff/payroll'    => ['payrollHandle', 'lib/staff-payroll.php'],
        'staff/procurement'=> ['procurementHandle', 'lib/staff-procurement.php'],
        'staff/appraisals' => ['appraisalHandle', 'lib/staff-appraisal.php'],
        'staff/location'   => ['geoHandle', 'lib/staff-geo.php'],
        'staff/people/import' => ['hrImportHandle', 'lib/staff-hr-import.php'],
    ] as $prefix => [$fn, $file]) {
        if (strpos($route, $prefix) === 0) {
            requireModule($fn, $file);
            $fn($pdo, $config, $route, $method, $user, $gate);
        }
    }

    // ----- home -----
    if ($route === 'staff/home' && $method === 'GET') {
        $assets = function_exists('ictAssetsHeldBy') ? ictAssetsHeldBy($pdo, (string)$user['email']) : [];
        $pending = 0;
        $ackStmt = $pdo->prepare("SELECT COUNT(*) FROM staff_announcements a LEFT JOIN staff_announcement_reads r ON r.announcement_id = a.id AND r.user_id = ?
                                  WHERE a.status = 'published' AND a.requires_ack = 1 AND r.acknowledged_at IS NULL AND (a.department_id IS NULL OR a.department_id = ?)");
        $ackStmt->execute([$me, $myDept ?? 0]);
        // Only staff confirm notices; a super admin looking in has nothing to confirm.
        $pending = $gate['row'] ? (int)$ackStmt->fetchColumn() : 0;
        // A super admin looking in without a staff record is named from their member
        // record, if they have one, rather than shown as a bare address.
        $outsider = ['name' => null, 'email' => $user['email']];
        if (!$gate['row']) {
            try {
                $mp = $pdo->prepare("SELECT TRIM(CONCAT_WS(' ', NULLIF(title,''), NULLIF(first_name,''), NULLIF(surname,''))) FROM member_profiles WHERE user_id = ? LIMIT 1");
                $mp->execute([$me]);
                $outsider['name'] = ($n = trim((string)$mp->fetchColumn())) !== '' ? $n : null;
            } catch (Throwable $e) { /* no member record */ }
        }
        respond(200, [
            'me' => $gate['row'] ? staffRecord($pdo, $me) : $outsider,
            'onStaffList' => (bool)$gate['row'],
            'caps' => $gate['caps'], 'super' => $gate['super'],
            'announcements' => staffAnnouncementFeed($pdo, $me, $myDept, 5),
            'toAcknowledge' => $pending,
            'notifications' => staffNotifications($pdo, $me, 8),
            'assets' => $assets,
            'directoryCount' => (int)$pdo->query('SELECT COUNT(*) FROM staff_accounts WHERE active = 1')->fetchColumn(),
            // Decisions waiting for this person in the other modules.
            'todo' => [
                'timesheets' => function_exists('timesheetTodo') && timesheetEnsureTables($pdo) ? timesheetTodo($pdo, $gate, $me) : 0,
                'procurement' => function_exists('procurementTodo') ? procurementTodo($pdo, $gate, $me) : 0,
                'appraisals' => function_exists('appraisalTodo') ? appraisalTodo($pdo, $gate, $me) : 0,
                'payroll' => function_exists('payrollTodo') ? payrollTodo($pdo, $gate, $me) : 0,
            ],
        ]);
    }

    // ----- directory and departments -----
    if ($route === 'staff/directory' && $method === 'GET') {
        respond(200, ['people' => staffDirectory($pdo, trim(mb_substr((string)($_GET['q'] ?? ''), 0, 80))), 'departments' => staffDepartments($pdo)]);
    }
    if ($route === 'staff/departments' && $method === 'GET') {
        respond(200, ['departments' => staffDepartments($pdo, in_array('staff.manage', $gate['caps'], true))]);
    }
    if ($route === 'staff/departments' && $method === 'POST') {
        staffNeed($gate, 'staff.manage');
        $data = input();
        $name = staffText($data['name'] ?? '', 120);
        if ($name === null) respond(400, ['error' => 'Give the department a name.']);
        $id = isset($data['id']) && is_numeric($data['id']) ? (int)$data['id'] : 0;
        $dup = $pdo->prepare('SELECT id FROM staff_departments WHERE name = ? AND id <> ?');
        $dup->execute([$name, $id]);
        if ($dup->fetchColumn()) respond(400, ['error' => 'There is already a department called ' . $name . '.']);
        if ($id) {
            $active = !isset($data['active']) || !empty($data['active']);
            $pdo->prepare('UPDATE staff_departments SET name = ?, active = ? WHERE id = ?')->execute([$name, $active ? 1 : 0, $id]);
            staffAudit($pdo, $me, 'department_updated', 'department', (string)$id, $name . ($active ? '' : ' (closed)'));
        } else {
            $pdo->prepare('INSERT INTO staff_departments (name) VALUES (?)')->execute([$name]);
            staffAudit($pdo, $me, 'department_added', 'department', (string)$pdo->lastInsertId(), $name);
        }
        respond(200, ['departments' => staffDepartments($pdo, true)]);
    }

    // ----- my profile -----
    if ($route === 'staff/profile' && in_array($method, ['GET', 'POST'], true)) {
        if (!$gate['row']) respond(404, ['error' => 'You are not on the staff list, so there is no staff profile to show.']);
        if ($method === 'POST') {
            $error = staffSaveOwn($pdo, $me, input());
            if ($error) respond(400, ['error' => $error]);
            staffAudit($pdo, $me, 'profile_updated', 'staff', (string)$me, 'own contact details');
        }
        respond(200, ['profile' => staffRecord($pdo, $me)]);
    }

    // ----- staff records (staff.manage) -----
    if ($route === 'staff/people' && $method === 'GET') {
        staffNeed($gate, 'staff.manage');
        $ids = $pdo->query('SELECT s.user_id FROM staff_accounts s JOIN users u ON u.id = s.user_id ORDER BY s.active DESC, COALESCE(s.full_name, u.email)')->fetchAll(PDO::FETCH_COLUMN);
        if (function_exists('geoEnsureTables')) geoEnsureTables($pdo);   // the counties column
        respond(200, ['people' => array_map(static fn($id) => staffRecord($pdo, (int)$id), $ids), 'departments' => staffDepartments($pdo, true),
                      'counties' => defined('GEO_KENYA_COUNTIES') ? GEO_KENYA_COUNTIES : []]);
    }
    if (preg_match('#^staff/people/(\d+)(/active)?$#', $route, $m) && $method === 'POST') {
        staffNeed($gate, 'staff.manage');
        $target = (int)$m[1];
        $before = staffRecord($pdo, $target);
        if (!$before) respond(404, ['error' => 'No such member of staff.']);
        if (!empty($m[2])) {
            $active = !empty(input()['active']);
            if (!$active && $target === $me) respond(400, ['error' => 'You cannot deactivate your own account.']);
            $pdo->prepare('UPDATE staff_accounts SET active = ?, deactivated_at = ? WHERE user_id = ?')
                ->execute([$active ? 1 : 0, $active ? null : date('Y-m-d H:i:s'), $target]);
            if (!$active) {
                // Out of the staff area at once, not at their next sign-in; anyone reporting to
                // them keeps the link, so it shows who they reported to until reassigned.
                sessionRevokeAll($pdo, $target);
            }
            staffAudit($pdo, $me, $active ? 'staff_reactivated' : 'staff_deactivated', 'staff', (string)$target, $before['email']);
            respond(200, ['person' => staffRecord($pdo, $target)]);
        }
        $error = staffSaveRecord($pdo, $target, input());
        if ($error) respond(400, ['error' => $error]);
        $after = staffRecord($pdo, $target);
        $changed = [];
        foreach (['name', 'staffNumber', 'jobTitle', 'department', 'manager', 'startDate'] as $k) {
            if (($before[$k] ?? null) !== ($after[$k] ?? null)) $changed[] = $k;
        }
        staffAudit($pdo, $me, 'staff_record_updated', 'staff', (string)$target, $after['email'] . ($changed ? ': ' . implode(', ', $changed) : ''));
        respond(200, ['person' => $after]);
    }

    // ----- permissions (super administrators only) -----
    if ($route === 'staff/permissions' && $method === 'GET') {
        if (!$gate['super']) respond(403, ['error' => 'Only a super administrator can see or change staff permissions.']);
        $grants = [];
        foreach ($pdo->query('SELECT user_id, capability FROM staff_grants')->fetchAll() as $g) $grants[(int)$g['user_id']][] = $g['capability'];
        $people = $pdo->query('SELECT s.user_id, s.full_name, u.email FROM staff_accounts s JOIN users u ON u.id = s.user_id WHERE s.active = 1 ORDER BY COALESCE(s.full_name, u.email)')->fetchAll();
        respond(200, ['capabilities' => STAFF_CAPABILITIES, 'people' => array_map(static fn($p) => [
            'userId' => (int)$p['user_id'], 'name' => $p['full_name'], 'email' => $p['email'], 'caps' => $grants[(int)$p['user_id']] ?? [],
        ], $people)]);
    }
    if (preg_match('#^staff/permissions/(\d+)$#', $route, $m) && $method === 'POST') {
        if (!$gate['super']) respond(403, ['error' => 'Only a super administrator can see or change staff permissions.']);
        $target = (int)$m[1];
        if ($target === $me) respond(400, ['error' => 'You cannot change your own permissions.']);
        $ok = $pdo->prepare('SELECT 1 FROM staff_accounts WHERE user_id = ? AND active = 1');
        $ok->execute([$target]);
        if (!$ok->fetchColumn()) respond(404, ['error' => 'Permissions can only be given to active staff.']);
        $wanted = input()['caps'] ?? null;
        if (!is_array($wanted)) respond(400, ['error' => 'Send the permissions as a list.']);
        $wanted = array_values(array_unique(array_map('strval', $wanted)));
        $unknown = array_diff($wanted, array_keys(STAFF_CAPABILITIES));
        if ($unknown) respond(400, ['error' => 'Unknown permission: ' . implode(', ', $unknown)]);
        $pdo->beginTransaction();
        $pdo->prepare('DELETE FROM staff_grants WHERE user_id = ?')->execute([$target]);
        $add = $pdo->prepare('INSERT INTO staff_grants (user_id, capability, granted_by) VALUES (?, ?, ?)');
        foreach ($wanted as $cap) $add->execute([$target, $cap, $me]);
        $pdo->commit();
        // Their open sessions end, so a removed permission stops working at once.
        sessionRevokeAll($pdo, $target);
        staffAudit($pdo, $me, 'permissions_changed', 'staff', (string)$target, $wanted ? implode(', ', $wanted) : 'none');
        respond(200, ['ok' => true, 'caps' => $wanted]);
    }

    // ----- announcements -----
    if ($route === 'staff/announcements' && $method === 'GET') {
        if (!empty($_GET['manage'])) {
            staffNeed($gate, 'announcements.publish');
            respond(200, ['announcements' => staffAnnouncementsManage($pdo), 'departments' => staffDepartments($pdo)]);
        }
        respond(200, ['announcements' => staffAnnouncementFeed($pdo, $me, $myDept, 100, trim(mb_substr((string)($_GET['q'] ?? ''), 0, 80)))]);
    }
    if ($route === 'staff/announcements' && $method === 'POST') {
        staffNeed($gate, 'announcements.publish');
        $data = input();
        $title = staffText($data['title'] ?? '', 200);
        $body = trim(str_replace(["\r\n", "\r"], "\n", (string)($data['body'] ?? '')));
        if ($title === null || $body === '') respond(400, ['error' => 'Write a title and the announcement itself.']);
        if (mb_strlen($body) > 20000) respond(400, ['error' => 'The announcement is too long.']);
        $dept = isset($data['departmentId']) && $data['departmentId'] !== '' && $data['departmentId'] !== null ? (int)$data['departmentId'] : null;
        if ($dept !== null) {
            $ok = $pdo->prepare('SELECT 1 FROM staff_departments WHERE id = ? AND active = 1');
            $ok->execute([$dept]);
            if (!$ok->fetchColumn()) respond(400, ['error' => 'Choose the audience from the list.']);
        }
        $id = isset($data['id']) && is_numeric($data['id']) ? (int)$data['id'] : 0;
        $values = [$title, $body, $dept, !empty($data['pinned']) ? 1 : 0, !empty($data['requiresAck']) ? 1 : 0, $me];
        if ($id) {
            $row = staffAnnouncementRow($pdo, $id);
            if (!$row) respond(404, ['error' => 'That announcement no longer exists.']);
            if ($row['status'] === 'archived') respond(400, ['error' => 'An archived announcement cannot be edited.']);
            $pdo->prepare('UPDATE staff_announcements SET title = ?, body = ?, department_id = ?, pinned = ?, requires_ack = ?, updated_by = ? WHERE id = ?')
                ->execute(array_merge($values, [$id]));
            staffAudit($pdo, $me, 'announcement_edited', 'announcement', (string)$id, $title);
        } else {
            $pdo->prepare('INSERT INTO staff_announcements (title, body, department_id, pinned, requires_ack, updated_by, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)')
                ->execute(array_merge($values, [$me]));
            $id = (int)$pdo->lastInsertId();
            staffAudit($pdo, $me, 'announcement_drafted', 'announcement', (string)$id, $title);
        }
        respond(200, ['id' => $id]);
    }
    if (preg_match('#^staff/announcements/(\d+)(?:/(publish|archive|ack|delete))?$#', $route, $m)) {
        $a = staffAnnouncementRow($pdo, (int)$m[1]);
        $action = $m[2] ?? '';
        $canEdit = in_array('announcements.publish', $gate['caps'], true);
        // Object-level check: an announcement is visible only to its audience once published,
        // and drafts and archived ones only to editors - knowing the number is not enough.
        $visible = $a && ($canEdit || ($a['status'] === 'published' && ($a['department_id'] === null || (int)$a['department_id'] === $myDept)));
        if (!$visible) respond(404, ['error' => 'No such announcement.']);

        if ($action === '' && $method === 'GET') {
            if ($a['status'] === 'published' && $gate['row']) {
                $pdo->prepare('INSERT IGNORE INTO staff_announcement_reads (announcement_id, user_id) VALUES (?, ?)')->execute([(int)$a['id'], $me]);
                $pdo->prepare("UPDATE staff_notifications SET read_at = NOW() WHERE user_id = ? AND dedupe_key = ? AND read_at IS NULL")
                    ->execute([$me, 'announcement:' . (int)$a['id']]);
            }
            $one = $pdo->prepare('SELECT a.*, d.name AS department_name, au.full_name AS author_name, r.read_at, r.acknowledged_at
                                  FROM staff_announcements a LEFT JOIN staff_departments d ON d.id = a.department_id
                                  LEFT JOIN staff_accounts au ON au.user_id = a.published_by
                                  LEFT JOIN staff_announcement_reads r ON r.announcement_id = a.id AND r.user_id = ? WHERE a.id = ?');
            $one->execute([$me, (int)$a['id']]);
            respond(200, ['announcement' => staffAnnouncementShape($one->fetch())]);
        }
        if ($action === 'ack' && $method === 'POST') {
            if ($a['status'] !== 'published' || !$a['requires_ack'] || !$gate['row']) respond(400, ['error' => 'This announcement does not ask for confirmation.']);
            $pdo->prepare('INSERT INTO staff_announcement_reads (announcement_id, user_id, acknowledged_at) VALUES (?, ?, NOW())
                           ON DUPLICATE KEY UPDATE acknowledged_at = COALESCE(acknowledged_at, NOW())')->execute([(int)$a['id'], $me]);
            respond(200, ['ok' => true]);
        }
        if (in_array($action, ['publish', 'archive', 'delete'], true) && $method === 'POST') {
            staffNeed($gate, 'announcements.publish');
            if ($action === 'publish') {
                if ($a['status'] === 'archived') respond(400, ['error' => 'An archived announcement cannot be published again. Make a new one.']);
                $count = staffAnnouncementPublish($pdo, $config, $a, $me, !empty(input()['email']));
                staffAudit($pdo, $me, 'announcement_published', 'announcement', (string)$a['id'], $a['title'] . ' to ' . $count . ' staff' . (!empty(input()['email']) ? ' (with email)' : ''));
                respond(200, ['audience' => $count]);
            }
            if ($action === 'archive') {
                $pdo->prepare("UPDATE staff_announcements SET status = 'archived', updated_by = ? WHERE id = ?")->execute([$me, (int)$a['id']]);
                staffAudit($pdo, $me, 'announcement_archived', 'announcement', (string)$a['id'], $a['title']);
                respond(200, ['ok' => true]);
            }
            // Only a draft nobody has seen can be deleted; a published one is archived instead,
            // so the record of what staff were told is kept.
            if ($a['status'] !== 'draft') respond(400, ['error' => 'Only drafts can be deleted. Archive a published announcement instead.']);
            $pdo->prepare("DELETE FROM staff_announcements WHERE id = ? AND status = 'draft'")->execute([(int)$a['id']]);
            staffAudit($pdo, $me, 'announcement_deleted', 'announcement', (string)$a['id'], $a['title']);
            respond(200, ['ok' => true]);
        }
        respond(405, ['error' => 'Method not allowed']);
    }

    // ----- notifications -----
    if ($route === 'staff/notifications' && $method === 'GET') {
        respond(200, staffNotifications($pdo, $me, 50));
    }
    if ($route === 'staff/notifications/read' && $method === 'POST') {
        $data = input();
        if (!empty($data['all'])) {
            $pdo->prepare('UPDATE staff_notifications SET read_at = NOW() WHERE user_id = ? AND read_at IS NULL')->execute([$me]);
        } else {
            // Only the caller's own: the user_id condition makes someone else's id a no-op.
            $ids = array_slice(array_filter(array_map('intval', (array)($data['ids'] ?? []))), 0, 200);
            if ($ids) {
                $pdo->prepare('UPDATE staff_notifications SET read_at = NOW() WHERE user_id = ? AND read_at IS NULL AND id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')')
                    ->execute(array_merge([$me], $ids));
            }
        }
        respond(200, staffNotifications($pdo, $me, 50));
    }

    // ----- activity log (super administrators) -----
    if ($route === 'staff/audit' && $method === 'GET') {
        if (!$gate['super']) respond(403, ['error' => 'Only a super administrator can see the staff activity log.']);
        $rows = $pdo->query('SELECT a.*, COALESCE(s.full_name, u.email) AS actor FROM staff_audit a
                             LEFT JOIN users u ON u.id = a.actor_user_id LEFT JOIN staff_accounts s ON s.user_id = a.actor_user_id
                             ORDER BY a.id DESC LIMIT 200')->fetchAll();
        respond(200, ['entries' => array_map(static fn($r) => ['action' => $r['action'], 'summary' => $r['summary'], 'actor' => $r['actor'], 'at' => $r['created_at']], $rows)]);
    }

    respond(404, ['error' => 'Route not found']);
}
