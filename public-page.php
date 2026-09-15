<?php
declare(strict_types=1);

/**
 * Serves a page kept under private/ to every visitor, with no membership check.
 *
 * Used by pages that were once members-only (see member-gate.php) and have since been opened
 * to the public. Their HTML stays under private/ so existing URLs and rewrites keep working.
 */
function resok_serve_public_page(string $contentPath): void
{
    header('X-Content-Type-Options: nosniff');

    if (!is_file($contentPath)) {
        error_log('public-page: content missing at ' . $contentPath);
        http_response_code(404);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Page not found.';
        exit;
    }

    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: public, max-age=300');
    readfile($contentPath);
    exit;
}
