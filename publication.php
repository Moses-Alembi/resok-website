<?php
declare(strict_types=1);

// /publication is open to the public, like the /research library that links to it. The page
// lives in private/publication.html, which Apache refuses to serve directly, and is emitted here.
// Self-contained on purpose: it depends on no other PHP file, so a partial deploy cannot break it.
$page = __DIR__ . '/private/publication.html';

header('X-Content-Type-Options: nosniff');
if (!is_file($page)) {
    error_log('publication.php: content missing at ' . $page);
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Page not found.';
    exit;
}

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: public, max-age=300');
readfile($page);
