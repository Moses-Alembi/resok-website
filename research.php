<?php
declare(strict_types=1);

// /research is open to the public. The page still lives in private/research.html, which
// Apache refuses to serve directly (see private/.htaccess), so this script is how it is read.
require_once __DIR__ . '/public-page.php';

resok_serve_public_page(__DIR__ . '/private/research.html');
