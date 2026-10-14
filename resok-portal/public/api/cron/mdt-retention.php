<?php
declare(strict_types=1);

/**
 * ILD MDT retention: purges case files and clinical text once a case has been closed for
 * mdt_retention_months (24 by default), and deletes drafts untouched for 90 days. The
 * anonymous outline of each case - age, sex, county, diagnosis, pattern - is kept for audit.
 *
 * Run it once a day via cPanel's Cron Jobs:
 *   php /path/to/resok-portal/public/api/cron/mdt-retention.php
 * or, where the host only offers URL-triggered cron, with ?key=<cron_secret>.
 *
 * Safe to repeat. The coordinators' case list also purges a few due cases on each visit,
 * so retention still happens slowly if this job is never set up.
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
// mdt.php calls isSuperAdmin() only inside routes, which this job never reaches.
if (!function_exists('isSuperAdmin')) {
    function isSuperAdmin(array $user, array $config): bool { return false; }
}
require_once __DIR__ . '/../lib/mdt.php';
cryptoConfig($config);

$dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $config['db_host'], (int)($config['db_port'] ?? 3306), $config['db_name']);
$pdo = new PDO($dsn, $config['db_user'], $config['db_pass'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->exec("SET time_zone = '+03:00'");

if (!mdtEnsureTables($pdo)) exit("MDT tables are unavailable.\n");
$report = mdtPurgeDue($pdo, $config, 500);
echo 'Cases purged: ' . $report['purged'] . "\n" . 'Old drafts deleted: ' . $report['draftsDeleted'] . "\n";
