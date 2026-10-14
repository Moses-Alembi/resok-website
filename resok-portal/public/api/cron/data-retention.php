<?php
declare(strict_types=1);

/**
 * Data retention, once a day. Deletes personal data the society has promised not to keep:
 *
 * - ILD MDT cases: files and clinical text mdt_retention_months (24) after a case closes,
 *   and drafts untouched for 90 days. The anonymous outline is kept for audit.
 * - Job applications: unsuccessful candidates' CVs, answers, notes and contact details
 *   careers_retention_months (6) after the vacancy closes. Hired candidates are kept.
 *
 * Run it daily via cPanel's Cron Jobs:
 *   php /path/to/resok-portal/public/api/cron/data-retention.php
 * or, where the host only offers URL-triggered cron, with ?key=<cron_secret>.
 *
 * Safe to repeat. The MDT and recruitment admin pages also purge a few due records on each
 * visit, so retention still happens slowly if this job is never set up.
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

require_once __DIR__ . '/../lib/crypto.php';
// The modules call isSuperAdmin() only inside their routes, which this job never reaches.
if (!function_exists('isSuperAdmin')) {
    function isSuperAdmin(array $user, array $config): bool { return false; }
}
require_once __DIR__ . '/../lib/mdt.php';
require_once __DIR__ . '/../lib/careers.php';
cryptoConfig($config);

$dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $config['db_host'], (int)($config['db_port'] ?? 3306), $config['db_name']);
$pdo = new PDO($dsn, $config['db_user'], $config['db_pass'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->exec("SET time_zone = '+03:00'");

if (mdtEnsureTables($pdo)) {
    $report = mdtPurgeDue($pdo, $config, 500);
    echo 'MDT cases purged: ' . $report['purged'] . "\n" . 'MDT old drafts deleted: ' . $report['draftsDeleted'] . "\n";
} else {
    echo "MDT tables are unavailable.\n";
}
if (careersEnsureTables($pdo)) {
    echo 'Job applications purged: ' . careersPurgeDue($pdo, $config, 1000) . "\n";
} else {
    echo "Careers tables are unavailable.\n";
}
