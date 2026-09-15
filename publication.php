<?php
declare(strict_types=1);

// /publication is open to the public, like the /research library that links to it. The page
// lives in private/publication.html and is emitted by this script.
require_once __DIR__ . '/public-page.php';

resok_serve_public_page(__DIR__ . '/private/publication.html');
