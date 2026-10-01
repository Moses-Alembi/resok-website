<?php
declare(strict_types=1);

/**
 * Import a manually uploaded private CPD dataset. No participant emails are sent.
 * CLI: php import-event-cpd.php --dataset=dataset-name [--start-time=HH:MM] [--apply]
 * HTTP: GET displays the maintenance form; POST requires the server's X-Cron-Key.
 * Dataset: ../cpd-imports/<dataset-name>.json, beside the site root and outside
 * the web document root. Never upload it through Git or into public_html.
 * Preview performs SELECTs only. Import preparation commits before token assignment;
 * a failed assignment can be resumed by rerunning the same dataset.
 */

final class CpdImportBlocked extends RuntimeException {}

function cpdImportDataset(string $name): array
{
    if (!preg_match('/\A[a-z0-9][a-z0-9-]{0,79}\z/D', $name)) {
        throw new CpdImportBlocked('Use a dataset name containing lowercase letters, digits and hyphens.');
    }
    $siteRoot = realpath(dirname(__DIR__, 4));
    if ($siteRoot === false) throw new CpdImportBlocked('The site root could not be verified.');
    $expectedRoot = dirname($siteRoot) . '/cpd-imports';
    $root = realpath($expectedRoot);
    $normalize = static function (string $path): string {
        $path = str_replace('\\', '/', $path);
        return PHP_OS_FAMILY === 'Windows' ? strtolower($path) : $path;
    };
    if ($root === false || $normalize($root) !== $normalize($expectedRoot)) {
        throw new CpdImportBlocked('The dataset directory outside the site root is missing or has been relocated.');
    }
    if (strpos($normalize($root) . '/', $normalize($siteRoot) . '/') === 0) {
        throw new CpdImportBlocked('The dataset directory must be outside the site root.');
    }
    if (PHP_SAPI !== 'cli') {
        $documentRoot = realpath((string)($_SERVER['DOCUMENT_ROOT'] ?? ''));
        if ($documentRoot === false || empty($_SERVER['DOCUMENT_ROOT'])) {
            throw new CpdImportBlocked('The server document root could not be verified.');
        }
        if (strpos($normalize($root) . '/', rtrim($normalize($documentRoot), '/') . '/') === 0) {
            throw new CpdImportBlocked('The dataset directory must be outside the web document root.');
        }
    }
    $path = realpath($root . '/' . $name . '.json');
    if ($path === false || dirname($path) !== $root || !is_file($path)) {
        throw new CpdImportBlocked('The private dataset was not found. Upload its JSON file first.');
    }
    if (filesize($path) > 2 * 1024 * 1024) throw new CpdImportBlocked('The dataset is too large.');
    try {
        $data = json_decode((string)file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
    } catch (Throwable $error) {
        throw new CpdImportBlocked('The private dataset is not valid JSON.');
    }
    if (!is_array($data)) throw new CpdImportBlocked('The private dataset must be a JSON object.');
    return cpdImportValidate($data);
}

function cpdImportValidate(array $data): array
{
    $event = $data['event'] ?? [];
    $expected = $data['expected'] ?? [];
    $rows = $data['attendees'] ?? [];
    $tokens = $data['tokens'] ?? [];
    if (!is_array($event) || !is_array($expected) || !is_array($rows) || !is_array($tokens)
        || !is_string($event['title'] ?? null) || trim($event['title']) === ''
        || mb_strlen($event['title']) > 200
        || !preg_match('/\A[a-z0-9][a-z0-9-]{0,79}\z/D', (string)($event['slug'] ?? ''))
        || !is_numeric($event['points'] ?? null) || !is_finite((float)$event['points'])
        || (float)$event['points'] < 0 || (float)$event['points'] > 999.9
        || round((float)$event['points'], 1) !== (float)$event['points']
        || ($event['regulator'] ?? null) !== 'KMPDC'
        || (isset($event['approvalRef']) && (!is_string($event['approvalRef']) || mb_strlen($event['approvalRef']) > 80))) {
        throw new CpdImportBlocked('Event metadata is incomplete or invalid.');
    }
    foreach (['date', 'tokenExpiresOn'] as $field) {
        $value = $event[$field] ?? '';
        $date = is_string($value) ? DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;
        if (!$date || $date->format('Y-m-d') !== $value) throw new CpdImportBlocked('Event dates are invalid.');
    }
    if (!is_int($expected['attendees'] ?? null) || !is_int($expected['tokens'] ?? null)
        || count($rows) !== $expected['attendees'] || count($tokens) !== $expected['tokens']
        || count($rows) < 1 || count($tokens) < count($rows)) {
        throw new CpdImportBlocked('Dataset counts do not match the approved totals.');
    }
    $seen = [];
    $sourceRows = 0;
    foreach ($rows as $index => $row) {
        if (!is_array($row) || !is_string($row['email'] ?? null) || !is_string($row['name'] ?? null)
            || trim($row['name']) === '' || mb_strlen($row['name']) > 160
            || !is_int($row['minutes'] ?? null) || $row['minutes'] < 0 || $row['minutes'] > 65535
            || (isset($row['kmpdc']) && (!is_string($row['kmpdc']) || mb_strlen($row['kmpdc']) > 60))
            || ($row['qualified'] ?? false) !== true) {
            throw new CpdImportBlocked('An attendee record or its approved eligibility is invalid.');
        }
        $email = strtolower(trim($row['email']));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190 || isset($seen[$email])) {
            throw new CpdImportBlocked('Attendee email addresses must be valid and unique.');
        }
        $seen[$email] = true;
        if (isset($row['durations'])) {
            if (!is_array($row['durations']) || !$row['durations']) throw new CpdImportBlocked('Attendance provenance is invalid.');
            foreach ($row['durations'] as $minutes) {
                if (!is_int($minutes) || $minutes < 0 || $minutes > 65535) throw new CpdImportBlocked('Attendance provenance is invalid.');
            }
            if (max($row['durations']) !== $row['minutes']) throw new CpdImportBlocked('Consolidated attendance must retain the longest recorded session.');
            $sourceRows += count($row['durations']);
            if (isset($row['sourceRows']) && (!is_array($row['sourceRows']) || count($row['sourceRows']) !== count($row['durations']))) {
                throw new CpdImportBlocked('Attendance source-row provenance is invalid.');
            }
        }
        $data['attendees'][$index]['email'] = $email;
        $data['attendees'][$index]['name'] = trim($row['name']);
        $data['attendees'][$index]['kmpdc'] = $row['kmpdc'] ?? null;
    }
    if (isset($expected['sourceRows']) && (!is_int($expected['sourceRows']) || $sourceRows !== $expected['sourceRows'])) {
        throw new CpdImportBlocked('Attendance provenance does not match the approved source-row count.');
    }
    $seen = [];
    foreach ($tokens as $token) {
        if (!is_string($token) || !preg_match('/\A[0-9]{11}\z/D', $token) || isset($seen[$token])) {
            throw new CpdImportBlocked('The KMPDC token batch must contain unique eleven-digit strings.');
        }
        $seen[$token] = true;
    }
    return $data;
}

function cpdImportPlan(PDO $pdo, array $config, array $data, string $startTime): array
{
    $summary = ['mode' => 'preview', 'database' => (string)$pdo->query('SELECT DATABASE()')->fetchColumn(),
        'attendeesExpected' => count($data['attendees']), 'tokensExpected' => count($data['tokens']),
        'eventMatched' => false, 'attendeesExisting' => 0, 'tokensExisting' => 0,
        'alreadyAssigned' => 0, 'missingKmpdcNumbers' => 0, 'blockers' => [], 'event' => [
            'title' => $data['event']['title'], 'date' => $data['event']['date'],
            'points' => (float)$data['event']['points'], 'regulator' => $data['event']['regulator'],
            'tokenExpiresOn' => $data['event']['tokenExpiresOn'], 'startTime' => $startTime !== '' ? $startTime : null,
        ]];
    $blockers = [];
    if ($startTime !== '' && !preg_match('/\A(?:[01][0-9]|2[0-3]):[0-5][0-9]\z/D', $startTime)) {
        $blockers[] = 'Start time must be HH:MM in Africa/Nairobi.';
    }
    if ($data['event']['tokenExpiresOn'] < date('Y-m-d')) $blockers[] = 'The uploaded token batch has expired.';
    $encrypted = cryptoAvailable($config);
    if (!$encrypted) $blockers[] = 'Configure a working data_encryption_key before importing tokens.';
    $required = ['cpd_events', 'event_attendees', 'cpd_tokens', 'token_access_codes', 'member_profiles', 'users'];
    $tables = $pdo->query('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()')->fetchAll(PDO::FETCH_COLUMN);
    if (array_diff($required, $tables)) {
        $blockers[] = 'Required database tables are missing. Import the portal, events and tokens schemas first.';
        $summary['blockers'] = $blockers;
        return ['summary' => $summary, 'event' => null, 'existing' => []];
    }
    $stmt = $pdo->prepare('SELECT * FROM cpd_events WHERE (BINARY title = ? AND DATE(starts_at) = ?) OR slug = ?');
    $stmt->execute([$data['event']['title'], $data['event']['date'], $data['event']['slug']]);
    $matches = [];
    foreach ($stmt->fetchAll() as $candidate) {
        if ($candidate['title'] === $data['event']['title'] && substr($candidate['starts_at'], 0, 10) === $data['event']['date']) {
            $matches[] = $candidate;
        } else {
            $blockers[] = 'The requested event slug belongs to a different event.';
        }
    }
    if (count($matches) > 1) $blockers[] = 'Multiple events match the exact title and date.';
    $event = count($matches) === 1 ? $matches[0] : null;
    $summary['eventMatched'] = $event !== null;
    if ($event) $summary['event']['startTime'] = substr($event['starts_at'], 11, 5);
    if (!$event && $startTime === '') $blockers[] = 'Enter the actual meeting start time before creating the event.';
    if ($event) {
        if ($event['regulator'] !== $data['event']['regulator'] || ($event['approved_points'] !== null
            && (float)$event['approved_points'] !== (float)$data['event']['points']) || $event['status'] === 'cancelled') {
            $blockers[] = 'Existing event accreditation or status conflicts with the dataset.';
        }
        if (isset($data['event']['approvalRef']) && $event['approval_ref'] !== null
            && $event['approval_ref'] !== $data['event']['approvalRef']) {
            $blockers[] = 'The existing approval reference conflicts with the dataset.';
        }
        if ($startTime !== '' && substr($event['starts_at'], 11, 5) !== $startTime) {
            $blockers[] = 'The supplied start time differs from the existing event.';
        }
    }
    $eventId = $event ? (int)$event['id'] : 0;
    $wanted = array_fill_keys(array_column($data['attendees'], 'email'), true);
    $existing = [];
    $attendeeIds = [];
    if ($event) {
        $stmt = $pdo->prepare('SELECT a.*, t.id AS token_id FROM event_attendees a LEFT JOIN cpd_tokens t ON t.attendee_id = a.id WHERE a.event_id = ?');
        $stmt->execute([$eventId]);
        foreach ($stmt->fetchAll() as $row) {
            $email = strtolower($row['email']);
            if (!isset($wanted[$email])) {
                if ((int)$row['attended'] === 1 && $row['token_id'] === null) $blockers[] = 'This event has waiting attendees outside the approved dataset.';
                continue;
            }
            $existing[$email] = $row;
            $attendeeIds[(int)$row['id']] = true;
        }
        foreach ($data['attendees'] as $row) {
            $old = $existing[$row['email']] ?? null;
            if ($old && $old['minutes_attended'] !== null && (int)$old['minutes_attended'] !== $row['minutes']) {
                $blockers[] = 'An existing attendance duration conflicts with the dataset.';
            }
        }
    }
    $summary['attendeesExisting'] = count($existing);
    $summary['missingKmpdcNumbers'] = count(array_filter($data['attendees'], static function (array $row) use ($existing): bool {
        return trim((string)($existing[$row['email']]['kmpdc_number'] ?? $row['kmpdc'])) === '';
    }));
    $batch = array_fill_keys($data['tokens'], true);
    $stock = count($batch);
    $storedBatch = [];
    if ($encrypted) {
        foreach ($pdo->query('SELECT event_id, attendee_id, status, token_value FROM cpd_tokens')->fetchAll() as $token) {
            $plain = cryptoDecrypt($config, $token['token_value']);
            if ($plain === null) { $blockers[] = 'An existing token cannot be decrypted; reuse checks cannot be completed.'; continue; }
            $inBatch = isset($batch[$plain]);
            if ($inBatch && (int)$token['event_id'] !== $eventId) $blockers[] = 'An uploaded token already belongs to another event.';
            if ((int)$token['event_id'] !== $eventId) continue;
            if (!$inBatch && ($token['status'] === 'unissued' || isset($attendeeIds[(int)$token['attendee_id']]))) {
                $blockers[] = 'The existing event token pool conflicts with the approved batch.';
            }
            if (!$inBatch) continue;
            if (isset($storedBatch[$plain])) $blockers[] = 'The uploaded batch has duplicate token records in the database.';
            $storedBatch[$plain] = true;
            $summary['tokensExisting']++;
            if (!cryptoIsEncrypted($token['token_value'])) $blockers[] = 'An existing batch token is stored without encryption.';
            if ($token['status'] === 'void') { $stock--; $blockers[] = 'An uploaded token has been voided.'; }
            if (($token['attendee_id'] === null && $token['status'] !== 'unissued')
                || ($token['attendee_id'] !== null && !in_array($token['status'], ['assigned', 'collected'], true))) {
                $blockers[] = 'An existing batch token has an inconsistent assignment state.';
            }
            if ($token['attendee_id'] !== null) {
                if (!isset($attendeeIds[(int)$token['attendee_id']])) { $stock--; $blockers[] = 'An uploaded token is assigned outside the approved dataset.'; }
                else $summary['alreadyAssigned']++;
            }
        }
        $probe = cryptoEncrypt($config, 'cpd-import-roundtrip');
        if (!cryptoIsEncrypted($probe) || cryptoDecrypt($config, $probe) !== 'cpd-import-roundtrip') {
            $blockers[] = 'The token encryption roundtrip failed.';
        }
    }
    if ($stock < count($wanted)) $blockers[] = 'There are insufficient usable tokens for all approved attendees.';
    $summary['blockers'] = array_values(array_unique($blockers));
    return ['summary' => $summary, 'event' => $event, 'existing' => $existing];
}

function cpdImportRun(PDO $pdo, array $config, array $data, bool $apply, string $startTime): array
{
    $data = cpdImportValidate($data);
    $plan = cpdImportPlan($pdo, $config, $data, $startTime);
    if (!$apply || $plan['summary']['blockers']) return $plan['summary'];
    $lockName = 'cpd-import-' . substr(hash('sha256', $data['event']['title'] . $data['event']['date']), 0, 40);
    $lock = $pdo->prepare('SELECT GET_LOCK(?, 0)');
    $lock->execute([$lockName]);
    if ((int)$lock->fetchColumn() !== 1) throw new CpdImportBlocked('Another import of this event is running.');
    $prepared = false;
    try {
        $plan = cpdImportPlan($pdo, $config, $data, $startTime);
        if ($plan['summary']['blockers']) return $plan['summary'];
        $pdo->beginTransaction();
        $event = $plan['event'];
        if (!$event) {
            [$created, $errors] = eventCreate($pdo, [
                'title' => $data['event']['title'], 'startsAt' => $data['event']['date'] . ' ' . $startTime . ':00',
                'format' => 'online', 'type' => 'CME', 'venue' => 'Zoom', 'points' => $data['event']['points'],
                'status' => 'published', 'approvalRef' => $data['event']['approvalRef'] ?? null,
            ], null);
            if ($errors || !$created) throw new CpdImportBlocked('The event could not be created.');
            $eventId = (int)$created['id'];
            // eventCreate derives a stable slug; preserve it on every subsequent run.
        } else {
            $eventId = (int)$event['id'];
            $patch = [];
            if ($event['approved_points'] === null) $patch['points'] = $data['event']['points'];
            if ($event['approval_ref'] === null && isset($data['event']['approvalRef'])) $patch['approvalRef'] = $data['event']['approvalRef'];
            if ($event['status'] === 'draft') $patch['status'] = 'published';
            if ($patch) {
                [, $errors] = eventUpdate($pdo, $eventId, $patch);
                if ($errors) throw new CpdImportBlocked('The event could not be prepared.');
            }
        }
        $newRows = [];
        foreach ($data['attendees'] as $row) {
            $old = $plan['existing'][$row['email']] ?? null;
            if (!$old) { $newRows[] = $row; continue; }
            if ((int)$old['attended'] !== 1) {
                $row['name'] = $old['full_name'];
                $row['kmpdc'] = $old['kmpdc_number'];
                attendanceRecord($pdo, $eventId, [$row], $old['channel'], $old['attendance_method'] ?: 'zoom_report', null, true);
            } elseif ($old['minutes_attended'] === null) {
                $fill = $pdo->prepare('UPDATE event_attendees SET minutes_attended = ? WHERE id = ? AND minutes_attended IS NULL');
                $fill->execute([$row['minutes'], $old['id']]);
            }
        }
        if ($newRows) attendanceRecord($pdo, $eventId, $newRows, 'online', 'zoom_report', null, true);
        $loaded = tokensLoad($pdo, $config, $eventId, implode("\n", $data['tokens']));
        $check = $pdo->prepare('SELECT token_value FROM cpd_tokens WHERE event_id = ?');
        $check->execute([$eventId]);
        foreach ($check->fetchAll(PDO::FETCH_COLUMN) as $value) {
            if (!cryptoIsEncrypted($value) || cryptoDecrypt($config, $value) === null) {
                throw new CpdImportBlocked('Token encryption verification failed; preparation was rolled back.');
            }
        }
        $pdo->commit();
        $prepared = true;
        $ready = cpdImportPlan($pdo, $config, $data, $startTime);
        if ($ready['summary']['blockers']) throw new CpdImportBlocked('Preparation committed, but assignment preflight now reports a conflict.');
        // This helper manages its own transaction. Preparation is already committed.
        $assigned = tokensAssign($pdo, $eventId);
        $verify = cpdImportPlan($pdo, $config, $data, $startTime);
        $result = $verify['summary'];
        $result['mode'] = 'apply';
        $result['tokensAdded'] = $loaded['added'];
        $result['tokensAssignedNow'] = $assigned['assigned'];
        $result['tokensSpare'] = $assigned['available'];
        $result['waiting'] = $assigned['waiting'];
        $result['complete'] = !$result['blockers'] && $result['attendeesExisting'] === count($data['attendees'])
            && $result['tokensExisting'] === count($data['tokens']) && $result['alreadyAssigned'] === count($data['attendees'])
            && $result['waiting'] === 0;
        return $result;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($prepared) throw new CpdImportBlocked('Preparation committed, but assignment or verification failed. Preview and rerun the same dataset to resume.');
        if ($error instanceof CpdImportBlocked) throw $error;
        throw new CpdImportBlocked('Import preparation failed and was rolled back. No database error details are exposed.');
    } finally {
        $release = $pdo->prepare('SELECT RELEASE_LOCK(?)');
        $release->execute([$lockName]);
    }
}

function cpdImportForm(): void
{
    header('Content-Type: text/html; charset=utf-8');
    echo <<<'HTML'
<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Import event CPD</title>
<style>body{font:16px system-ui;max-width:620px;margin:40px auto;padding:0 20px;color:#17312b}label{display:block;margin:18px 0 5px}input,button{font:inherit;padding:10px;border:1px solid #bbb;border-radius:6px}input{box-sizing:border-box;width:100%}button{margin:20px 8px 0 0;cursor:pointer}pre{white-space:pre-wrap;background:#f1f5f3;padding:16px;border-radius:6px}</style>
<h1>Import event CPD</h1><p>Upload the private JSON dataset first. Preview checks the records without changing them. Import records attendance and assigns tokens; it sends no emails.</p>
<form id="importForm"><label for="dataset">Dataset name (without .json)</label><input id="dataset" name="dataset" pattern="[a-z0-9][a-z0-9-]{0,79}" required autocomplete="off">
<label for="key">Server cron secret</label><input id="key" type="password" required autocomplete="off">
<label for="startTime">Actual start time in Kenya (required for a new event)</label><input id="startTime" type="time">
<button type="submit" value="preview">Preview</button><button type="submit" value="apply">Import</button></form><pre id="result" aria-live="polite">No import has run.</pre>
<script>document.getElementById('importForm').addEventListener('submit',async e=>{e.preventDefault();const result=document.getElementById('result');const buttons=[...document.querySelectorAll('button')];buttons.forEach(b=>b.disabled=true);result.textContent='Checking…';try{const response=await fetch(location.pathname,{method:'POST',headers:{'Content-Type':'application/json','X-Cron-Key':document.getElementById('key').value},body:JSON.stringify({dataset:document.getElementById('dataset').value,startTime:document.getElementById('startTime').value,apply:e.submitter?.value==='apply'})});const data=await response.json();result.textContent=JSON.stringify(data,null,2)}catch(error){result.textContent='The request failed. Preview again before retrying an import.'}finally{buttons.forEach(b=>b.disabled=false)}});</script></html>
HTML;
}

if (defined('CPD_IMPORT_LIBRARY_ONLY')) return;
ini_set('display_errors', '0');
date_default_timezone_set('Africa/Nairobi');
$isCli = PHP_SAPI === 'cli';
if (!$isCli) {
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex, nofollow');
    header('X-Content-Type-Options: nosniff');
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') { cpdImportForm(); exit; }
    header('Content-Type: application/json; charset=utf-8');
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); echo '{"error":"Use POST."}'; exit; }
}
try {
    $config = require __DIR__ . '/../config.php';
    require_once __DIR__ . '/../lib/crypto.php';
    require_once __DIR__ . '/../lib/events.php';
    require_once __DIR__ . '/../lib/attendance.php';
    cryptoConfig($config);
    if ($isCli) {
        $options = getopt('', ['dataset:', 'start-time:', 'apply', 'help']);
        if (isset($options['help'])) { echo "php import-event-cpd.php --dataset=dataset-name [--start-time=HH:MM] [--apply]\nWithout --apply, the database is only read.\n"; exit; }
        $input = ['dataset' => $options['dataset'] ?? '', 'startTime' => $options['start-time'] ?? '', 'apply' => isset($options['apply'])];
    } else {
        $secret = (string)($config['cron_secret'] ?? '');
        if ($secret === '' || !hash_equals($secret, (string)($_SERVER['HTTP_X_CRON_KEY'] ?? ''))) {
            http_response_code(403); throw new CpdImportBlocked('A configured server cron_secret and matching X-Cron-Key are required.');
        }
        if (isset($_SERVER['HTTP_ORIGIN'])) {
            $origin = parse_url($_SERVER['HTTP_ORIGIN']);
            $host = is_array($origin) ? strtolower((string)($origin['host'] ?? '')) . (isset($origin['port']) ? ':' . $origin['port'] : '') : '';
            if ($host !== strtolower((string)($_SERVER['HTTP_HOST'] ?? ''))) { http_response_code(403); throw new CpdImportBlocked('Cross-origin requests are not accepted.'); }
        }
        $input = json_decode((string)file_get_contents('php://input'), true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($input)) throw new CpdImportBlocked('Send a JSON request.');
    }
    if (!is_string($input['dataset'] ?? null) || !is_string($input['startTime'] ?? '') || !is_bool($input['apply'] ?? false)) {
        throw new CpdImportBlocked('Dataset, start time or import action is invalid.');
    }
    $data = cpdImportDataset($input['dataset']);
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $config['db_host'], (int)($config['db_port'] ?? 3306), $config['db_name']);
    $pdo = new PDO($dsn, $config['db_user'], $config['db_pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $pdo->exec("SET time_zone = '+03:00'");
    $result = cpdImportRun($pdo, $config, $data, $input['apply'] ?? false, $input['startTime'] ?? '');
    if (!$isCli && $result['blockers']) http_response_code(409);
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    if ($isCli && $result['blockers']) exit(2);
} catch (Throwable $error) {
    if (!$isCli && http_response_code() < 400) http_response_code(400);
    $message = $error instanceof CpdImportBlocked ? $error->getMessage() : 'The import could not run. Check server configuration and the private dataset.';
    echo json_encode(['error' => $message], JSON_UNESCAPED_SLASHES) . "\n";
    if ($isCli) exit(1);
}
