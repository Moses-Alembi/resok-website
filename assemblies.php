<?php
declare(strict_types=1);

// /assemblies is members-only. The page lives in private/assemblies.html, which Apache
// refuses to serve directly (see private/.htaccess), so this script is the only way to
// read it - and only for a logged-in member whose membership is active.
require_once __DIR__ . '/member-gate.php';

resok_gate_serve(__DIR__ . '/private/assemblies.html', 'Assemblies & Working Groups');
