<?php
declare(strict_types=1);

// /research is open to the public. The page still lives in private/research.html, which
// Apache refuses to serve directly (see private/.htaccess), so this script is how it is read.
// Self-contained on purpose: it depends on no other PHP file, so a partial deploy cannot break it.
$page = __DIR__ . '/private/research.html';

header('X-Content-Type-Options: nosniff');
if (!is_file($page)) {
    error_log('research.php: content missing at ' . $page);
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Page not found.';
    exit;
}

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: public, max-age=300');
readfile($page);
