<?php
declare(strict_types=1);

/**
 * Abstract submission's scheduled work: sends queued email, reminds authors with unsubmitted
 * drafts 7 days and 1 day before the deadline, reminds reviewers with open reviews 7, 3
 * and 1 days before the review deadline (and daily once it has passed), and reminds
 * accepted presenters who have not confirmed 7 days and 1 day before the confirmation date.
 *
 * Run it every 15 minutes via cPanel's Cron Jobs:
 *   php /path/to/resok-portal/public/api/cron/abstracts.php
 * or, where the host only offers URL-triggered cron, with ?key=<cron_secret>.
 *
 * Everything in it is safe to repeat: each reminder carries a key that makes it once-only,
 * and the mail queue never sends more than the hourly limit. If this job is never set up,
 * queued email still goes out a little at a time as people use the abstracts pages - but
 * the reminders only come from here.
 */

$isCli = PHP_SAPI === 'cli';
$config = require __DIR__ . '/../config.php';
date_default_timezone_set('Africa/Nairobi');

if (!$isCli) {
    header('Content-Type: text/plain');
    $cronSecret = (string)($config['cron_secret'] ?? '');
    if ($cronSecret === '' || !hash_equals($cronSecret, (string)($_GET['key'] ?? ''))) {
        http_response_code(403);
        exit("Forbidden\n");
    }
}

foreach (['SimpleMailer', 'portal-mail', 'abstracts', 'abstracts-review', 'abstracts-decisions'] as $module) {
    require_once __DIR__ . '/../lib/' . $module . '.php';
}

$dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $config['db_host'], (int)($config['db_port'] ?? 3306), $config['db_name']);
$pdo = new PDO($dsn, $config['db_user'], $config['db_pass'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->exec("SET time_zone = '+03:00'");

if (!abstractsEnsureTables($pdo)) exit("Abstract tables are unavailable.\n");
$report = absRunScheduled($pdo, $config);
echo 'Deadline reminders queued: ' . $report['deadlineReminders'] . "\n"
   . 'Review reminders queued: ' . $report['reviewReminders'] . "\n"
   . 'Attendance reminders queued: ' . ($report['attendanceReminders'] ?? 0) . "\n"
   . 'Mail sent: ' . $report['mail']['sent'] . ', failed attempts: ' . $report['mail']['failed']
   . ', still waiting: ' . $report['mail']['waiting'] . "\n";
