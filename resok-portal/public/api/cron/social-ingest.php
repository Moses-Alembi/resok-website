<?php
declare(strict_types=1);

/**
 * Polls the organisation's social channels and fills the review queue.
 *
 * Run hourly from cPanel's cron:
 *   /usr/local/bin/php /home/resokorg/public_html/resok-portal/public/api/cron/social-ingest.php
 *
 * If the host only offers URL-triggered cron, set cron_secret in config.local.php and call
 * it with ?key=... instead. Without that secret set, the URL path refuses to run at all -
 * an unauthenticated endpoint that makes outbound API calls is an obvious way to burn
 * someone's rate limits.
 *
 * This only queues posts. Nothing it fetches appears on the site until an editor imports it.
 */

$isCli = PHP_SAPI === 'cli';
$config = require __DIR__ . '/../config.php';

if (!$isCli) {
    header('Content-Type: application/json');
    $secret = (string)($config['cron_secret'] ?? '');
    if ($secret === '' || !hash_equals($secret, (string)($_GET['key'] ?? ''))) {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden']);
        exit;
    }
}

require_once __DIR__ . '/../lib/blog.php';
require_once __DIR__ . '/../lib/social-ingest.php';

// respond() lives in index.php, which this script must not include - socialImportItem() is
// the only function that calls it, and the cron never imports.
if (!function_exists('respond')) {
    function respond(int $status, array $payload): void
    {
        http_response_code($status);
        echo json_encode($payload);
        exit;
    }
}

try {
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $config['db_host'], (int)($config['db_port'] ?? 3306), $config['db_name']),
        $config['db_user'], $config['db_pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
    $results = socialIngestAll($pdo);
} catch (Throwable $e) {
    error_log('social-ingest cron failed: ' . $e->getMessage());
    if (!$isCli) http_response_code(500);
    echo $isCli ? "failed: {$e->getMessage()}\n" : json_encode(['error' => $e->getMessage()]);
    exit(1);
}

$added = array_sum(array_map(fn($r) => $r['added'] ?? 0, $results));
if ($isCli) {
    foreach ($results as $r) {
        echo isset($r['error'])
            ? sprintf("  %-12s %s  ERROR: %s\n", $r['platform'], $r['source'], $r['error'])
            : sprintf("  %-12s %s  seen=%d added=%d\n", $r['platform'], $r['source'], $r['seen'], $r['added']);
    }
    echo "queued {$added} new post(s)\n";
} else {
    echo json_encode(['queued' => $added, 'sources' => $results]);
}
