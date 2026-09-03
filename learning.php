<?php
declare(strict_types=1);

// /learning is members-only. The page itself lives in private/learning.html, which Apache
// refuses to serve directly (see private/.htaccess), so this script is the only way to
// read it - and it only emits it for a logged-in member whose membership is active.
require_once __DIR__ . '/member-gate.php';

resok_gate_serve(__DIR__ . '/private/learning.html', 'the Learning & CME library');
