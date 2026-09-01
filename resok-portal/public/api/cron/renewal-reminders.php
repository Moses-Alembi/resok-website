<?php
declare(strict_types=1);

/**
 * Renewal reminder cron. Run once a day via cPanel's Cron Jobs (or any scheduler):
 *   php /path/to/resok-portal/public/api/cron/renewal-reminders.php
 * If the host only offers URL-triggered cron, this file also accepts
 * ?key=<cron_secret> as a GET request (set cron_secret in config.php first).
 */

$isCli = PHP_SAPI === 'cli';
$config = require __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/portal-mail.php';

if (!$isCli) {
    $providedKey = $_GET['key'] ?? '';
    $cronSecret = (string)($config['cron_secret'] ?? '');
    if ($cronSecret === '' || !hash_equals($cronSecret, (string)$providedKey)) {
        http_response_code(403);
        echo "Forbidden\n";
        exit;
    }
}

$dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $config['db_host'], (int)($config['db_port'] ?? 3306), $config['db_name']);
$pdo = new PDO($dsn, $config['db_user'], $config['db_pass'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
]);

$columns = [];
foreach ($pdo->query('SHOW COLUMNS FROM member_profiles')->fetchAll() as $column) {
    $columns[$column['Field']] = true;
}
if (empty($columns['last_reminder_days'])) {
    $pdo->exec('ALTER TABLE member_profiles ADD COLUMN last_reminder_days INT NULL AFTER renewal_due');
}

$reminderDays = [30, 14, 1];
$sent = 0;

foreach ($reminderDays as $days) {
    $stmt = $pdo->prepare(
        "SELECT mp.*, u.email
         FROM member_profiles mp
         JOIN users u ON u.id = mp.user_id
         WHERE mp.membership_status = 'active'
           AND mp.renewal_due = DATE_ADD(CURDATE(), INTERVAL ? DAY)
           AND (mp.last_reminder_days IS NULL OR mp.last_reminder_days <> ?)"
    );
    $stmt->execute([$days, $days]);

    foreach ($stmt->fetchAll() as $row) {
        $member = [
            'title' => $row['title'], 'firstName' => $row['first_name'], 'middleName' => $row['middle_name'], 'surname' => $row['surname'],
            'email' => $row['email'], 'renewalDue' => $row['renewal_due']
        ];
        try {
            if (sendRenewalReminderEmail($config, $member, $days)) {
                $pdo->prepare('UPDATE member_profiles SET last_reminder_days = ? WHERE id = ?')->execute([$days, (int)$row['id']]);
                $sent++;
            }
        } catch (Throwable $error) {
            error_log('Renewal reminder failed for member_profile ' . $row['id'] . ': ' . $error->getMessage());
        }
    }
}

echo "Renewal reminders sent: {$sent}\n";
