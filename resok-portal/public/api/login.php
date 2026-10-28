<?php
/**
 * Password sign-in, at an address of its own.
 *
 * Cloudflare's free plan can rate-limit by path only, not by ?route=, so sign-in needs its own
 * path for the "Login attempts" rule to see it (28 Oct 2026). This is the same API: it sets
 * the route and hands over to index.php. On the live server index.php refuses auth/login
 * arriving any other way, so the limit cannot be stepped round.
 */
define('RESOK_LOGIN_ENTRY', true);
$_GET['route'] = 'auth/login';
require __DIR__ . '/index.php';
