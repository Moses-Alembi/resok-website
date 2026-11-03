<?php
declare(strict_types=1);

// cPanel cron, every minute: php /path/to/resok-portal/public/api/cron/welfare.php
// CLI only: neither URLs nor secret-bearing query strings can trigger this job.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
date_default_timezone_set('Africa/Nairobi');
$config = require __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/elections.php';
require_once __DIR__ . '/../lib/welfare.php';
$dsn=sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',$config['db_host'],(int)($config['db_port']??3306),$config['db_name']);
$pdo=new PDO($dsn,$config['db_user'],$config['db_pass'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$pdo->exec("SET time_zone='+03:00'");
if (!welfareReady($pdo)) { fwrite(STDERR,"Apply the welfare automation migration before scheduling this job.\n"); exit(1); }
welfareRunScheduled($pdo);
echo "Welfare schedules checked.\n";
