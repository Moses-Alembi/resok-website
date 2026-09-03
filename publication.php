<?php
declare(strict_types=1);

// /publication is members-only, like the /research library that links to it - gating the
// listing but not the detail pages would leave every publication readable by URL. The page
// lives in private/publication.html and is emitted only by this script.
require_once __DIR__ . '/member-gate.php';

resok_gate_serve(__DIR__ . '/private/publication.html', 'the publications library');
