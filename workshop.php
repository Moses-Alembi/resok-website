<?php
declare(strict_types=1);

// /workshop?w=<slug> serves private/workshop-<slug>.html, the same way /research and
// /publication serve their own private/ pages. One router for every workshop write-up,
// so a new workshop is a new file in private/ plus a card on workshops-and-training.html -
// never a new PHP file.
$slug = (string)($_GET['w'] ?? '');

header('X-Content-Type-Options: nosniff');
if ($slug === '' || !preg_match('/^[a-z0-9-]+$/', $slug)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Workshop not found.';
    exit;
}

$page = __DIR__ . '/private/workshop-' . $slug . '.html';

if (!is_file($page)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Workshop not found.';
    exit;
}

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: public, max-age=300');
readfile($page);
