<?php
declare(strict_types=1);

/**
 * Where browsers report what the site-wide Content-Security-Policy would have blocked
 * (30 Oct 2026 audit, item 79).
 *
 * The policy is sent as Content-Security-Policy-Report-Only by the root .htaccess, so nothing
 * is blocked yet: the pages still carry inline scripts, and enforcing it blind could stop a
 * page working. Each report lands in security_events as 'csp_violation', which Admin > Threat
 * Assessment and the 8-hourly digest already show. Once a few weeks pass with nothing
 * unexpected here, the header can be switched to enforce.
 *
 * Anyone can post here, so it stores very little: the directive, the blocked host (never a
 * full URL, which could carry a token) and the page path. Each combination is logged once a
 * day, and no more than 300 a day in all, so a flood cannot fill the table.
 */

http_response_code(204);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') exit;

$raw = (string)file_get_contents('php://input', false, null, 0, 16384);
$data = json_decode($raw, true);
if (!is_array($data)) exit;
// report-uri sends {"csp-report": {...}}; the newer Reporting API sends [{"body": {...}}].
$report = $data['csp-report'] ?? ($data[0]['body'] ?? null);
if (!is_array($report)) exit;

$directive = (string)($report['effective-directive'] ?? $report['effectiveDirective'] ?? $report['violated-directive'] ?? '');
$blocked = (string)($report['blocked-uri'] ?? $report['blockedURL'] ?? '');
$page = (string)($report['document-uri'] ?? $report['documentURL'] ?? '');
$directive = preg_replace('/[^a-z-]/', '', strtolower(strtok($directive, ' ') ?: '')) ?? '';
if ($directive === '') exit;
// 'inline', 'eval', 'data' come through as words; anything else is reduced to its host.
$what = preg_match('#^[a-z-]+$#', $blocked) ? $blocked : (string)parse_url($blocked, PHP_URL_HOST);
$where = (string)parse_url($page, PHP_URL_PATH);
$detail = mb_substr($directive . ' blocked ' . ($what !== '' ? $what : 'unknown') . ' on ' . ($where !== '' ? $where : '?'), 0, 300);

$config = require __DIR__ . '/config.php';
require_once __DIR__ . '/lib/throttle.php';
try {
    $pdo = new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $config['db_host'], (int)($config['db_port'] ?? 3306), $config['db_name']), $config['db_user'], $config['db_pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec("SET time_zone = '+03:00'");
    if (!throttleEnsureTables($pdo)) exit;
    $today = $pdo->query("SELECT COUNT(*) FROM security_events WHERE event_type = 'csp_violation' AND created_at >= CURDATE()")->fetchColumn();
    if ((int)$today >= 300) exit;
    $seen = $pdo->prepare("SELECT 1 FROM security_events WHERE event_type = 'csp_violation' AND created_at >= CURDATE() AND detail = ? LIMIT 1");
    $seen->execute([$detail]);
    if ($seen->fetch()) exit;
    securityLog($pdo, $config, 'csp_violation', 'info', $directive, $detail);
} catch (Throwable $e) {
    error_log('CSP report not stored: ' . $e->getMessage());
}
