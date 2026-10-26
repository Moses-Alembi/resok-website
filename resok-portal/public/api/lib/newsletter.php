<?php
declare(strict_types=1);

/**
 * The newsletter list and sending to it (super administrators).
 *
 * People join from the footer and home-page forms through /newsletter.php, which does the
 * double opt-in. This is the other half: seeing the list, exporting it, and writing to it.
 *
 * Three rules shape it:
 *
 * 1. Nobody is added by hand. An address is on the list only because its owner clicked the
 *    confirmation link, which is the consent the Data Protection Act asks us to be able to
 *    show. An administrator can take someone off, never put someone on.
 *
 * 2. Newsletters go through the shared mail queue (abs_emails, see absMailPump), so a send to
 *    the whole list can never push the host past its hourly email limit and starve the
 *    abstract and conference emails that people are waiting for. A large list goes out over
 *    a few hours; the cron empties the queue every 15 minutes.
 *
 * A newsletter can also be a designed PDF. The PDF is not attached: it is kept on the site
 * and the email shows its first page as a picture, linked to the PDF, with a button. Sent
 * as an attachment, every copy would carry the whole file - slower to send, heavier on the
 * host's limits, and far more likely to be filed as spam. The picture is made in the
 * administrator's browser with pdf.js, because the host has no image tools for PDFs.
 *
 * 3. Every newsletter carries its own unsubscribe link. Subscribers' tokens are stored only
 *    as hashes, so that link is signed instead (newsletterLeaveUrl) and newsletter.php checks
 *    the signature. The archive inbox gets one copy per newsletter, not one per subscriber.
 */

const NEWSLETTER_PDF_MAX_BYTES = 20 * 1024 * 1024;
const NEWSLETTER_CHUNK_BYTES = 1024 * 1024;
const NEWSLETTER_COVER_MAX_BYTES = 3 * 1024 * 1024;

function newsletterEnsureTables(PDO $pdo): bool
{
    static $ready = null;
    if ($ready !== null) return $ready;
    try {
        $tail = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        $pdo->exec("CREATE TABLE IF NOT EXISTS newsletter_subscribers (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            email VARCHAR(254) NOT NULL,
            token_hash CHAR(64) NOT NULL,
            status ENUM('pending','confirmed','unsubscribed') NOT NULL DEFAULT 'pending',
            source VARCHAR(120) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            confirmed_at DATETIME NULL,
            unsubscribed_at DATETIME NULL,
            last_sent_at DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY newsletter_subscribers_email (email),
            UNIQUE KEY newsletter_subscribers_token (token_hash)
        )" . $tail);
        $pdo->exec("CREATE TABLE IF NOT EXISTS newsletter_campaigns (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            subject VARCHAR(200) NOT NULL,
            body MEDIUMTEXT NOT NULL,
            cta_label VARCHAR(60) NULL,
            cta_url VARCHAR(500) NULL,
            pdf_key CHAR(32) NULL,
            pdf_name VARCHAR(200) NULL,
            pdf_bytes INT UNSIGNED NULL,
            pdf_received INT UNSIGNED NOT NULL DEFAULT 0,
            pdf_ready TINYINT(1) NOT NULL DEFAULT 0,
            cover_ready TINYINT(1) NOT NULL DEFAULT 0,
            status ENUM('draft','sent') NOT NULL DEFAULT 'draft',
            recipients INT UNSIGNED NOT NULL DEFAULT 0,
            created_by INT UNSIGNED NULL,
            sent_by INT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            sent_at DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY newsletter_campaigns_pdf (pdf_key)
        )" . $tail);
        // Tables made before PDF newsletters (26 Oct 2026) gain the columns.
        $have = array_column($pdo->query('SHOW COLUMNS FROM newsletter_campaigns')->fetchAll(), 'Field');
        if (!in_array('pdf_key', $have, true)) {
            $pdo->exec('ALTER TABLE newsletter_campaigns ADD COLUMN pdf_key CHAR(32) NULL AFTER cta_url,
                ADD COLUMN pdf_name VARCHAR(200) NULL AFTER pdf_key, ADD COLUMN pdf_bytes INT UNSIGNED NULL AFTER pdf_name,
                ADD COLUMN pdf_received INT UNSIGNED NOT NULL DEFAULT 0 AFTER pdf_bytes,
                ADD COLUMN pdf_ready TINYINT(1) NOT NULL DEFAULT 0 AFTER pdf_received,
                ADD COLUMN cover_ready TINYINT(1) NOT NULL DEFAULT 0 AFTER pdf_ready,
                ADD UNIQUE KEY newsletter_campaigns_pdf (pdf_key)');
        }
        return $ready = true;
    } catch (Throwable $e) {
        error_log('Newsletter tables unavailable: ' . $e->getMessage());
        return $ready = false;
    }
}

/**
 * The key that signs unsubscribe links. It is derived rather than used directly, so a link
 * reveals nothing about the key it comes from. data_encryption_key is preferred because it is
 * never rotated; rotating it would break the links in every newsletter already sent.
 */
function newsletterSigningKey(array $config): string
{
    $base = (string)($config['data_encryption_key'] ?? '') ?: (string)($config['jwt_secret'] ?? '');
    return $base === '' ? '' : hash('sha256', 'newsletter-unsubscribe|' . $base);
}

function newsletterLeaveSignature(array $config, int $id, string $email): string
{
    return substr(hash_hmac('sha256', $id . '|' . strtolower($email), newsletterSigningKey($config)), 0, 32);
}

/** The unsubscribe link in a newsletter: ?leave=<subscriber id>.<signature>. */
function newsletterLeaveUrl(array $config, int $id, string $email): string
{
    return newsletterSiteUrl($config) . '/newsletter.php?leave=' . $id . '.' . newsletterLeaveSignature($config, $id, $email);
}

/** The public site's root, from portal_base_url (…/resok-portal/public). */
function newsletterSiteUrl(array $config): string
{
    $base = rtrim((string)($config['portal_base_url'] ?? 'https://www.resok.org/resok-portal/public'), '/');
    return (string)preg_replace('#/resok-portal/public$#', '', $base);
}

function newsletterPdfUrl(array $config, string $key): string
{
    return newsletterSiteUrl($config) . '/newsletter.php?pdf=' . $key;
}

/** Where PDFs and their cover pictures are kept: outside the web root, served by newsletter.php. */
function newsletterDir(array $config): string
{
    $dir = rtrim((string)$config['upload_dir'], '/\\') . '/newsletters';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    return $dir;
}

function newsletterRemoveFiles(array $config, ?string $key): void
{
    if (!$key || !preg_match('/^[a-f0-9]{32}$/', $key)) return;
    foreach (['.pdf', '.part', '.jpg'] as $ext) @unlink(newsletterDir($config) . '/' . $key . $ext);
}

/** Why a finished upload is not an acceptable PDF, or null when it is. */
function newsletterPdfProblem(string $path): ?string
{
    $data = (string)@file_get_contents($path);
    if (strncmp($data, '%PDF-', 5) !== 0) return 'That file is not a PDF.';
    // The same line the careers CVs draw: a newsletter has no business running code or
    // carrying other files inside it.
    if (preg_match('#/(JavaScript|JS|Launch|EmbeddedFile)\b#', $data)) {
        return 'This PDF contains scripts or embedded files, which are not accepted. Export it again as a plain PDF (in Canva: Share > Download > PDF Standard).';
    }
    return null;
}

/** The text a newsletter must have before it can be tested or sent, or an error. */
function newsletterContentProblem(array $campaign): ?string
{
    if ($campaign['pdf_key'] && !$campaign['pdf_ready']) return 'The PDF has not finished uploading. Upload it again, or remove it.';
    if (trim((string)$campaign['body']) === '' && !$campaign['pdf_ready']) return 'Write a message or upload a PDF.';
    return null;
}

function newsletterText($value, int $max): string
{
    $text = str_replace(["\r\n", "\r"], "\n", is_scalar($value) ? (string)$value : '');
    return mb_substr(trim($text), 0, $max);
}

/**
 * The email for one subscriber. The body is plain text, as the administrator typed it: blank
 * lines separate paragraphs and web addresses become links. Returns [subject, text, html].
 */
function newsletterRender(array $config, array $campaign, string $leaveUrl): array
{
    $subject = (string)$campaign['subject'];
    $body = trim((string)$campaign['body']);
    $ctaLabel = trim((string)($campaign['cta_label'] ?? ''));
    $ctaUrl = trim((string)($campaign['cta_url'] ?? ''));
    $pdf = !empty($campaign['pdf_ready']) ? newsletterPdfUrl($config, (string)$campaign['pdf_key']) : null;
    if ($pdf !== null) {
        // A PDF newsletter's button opens the PDF.
        $ctaLabel = 'Read the newsletter';
        $ctaUrl = $pdf;
    }
    $hasCta = $ctaLabel !== '' && $ctaUrl !== '';

    $text = $body !== '' ? $body . "\n\n" : '';
    if ($hasCta) $text .= $ctaLabel . ($pdf !== null ? ' (PDF)' : '') . ":\n" . $ctaUrl . "\n\n";
    $text .= "--\nRespiratory Society of Kenya\nwww.resok.org\n\n"
           . "You are receiving this because you subscribed to the ReSoK newsletter.\n"
           . "Unsubscribe: " . $leaveUrl . "\n";

    $html = '';
    foreach ($body === '' ? [] : (preg_split("/\n{2,}/", $body) ?: []) as $para) {
        $escaped = nl2br(htmlspecialchars($para, ENT_QUOTES));
        $escaped = (string)preg_replace_callback('#https?://[^\s<]+#i', static function ($m) {
            // A full stop or comma closing a sentence is not part of the address.
            $url = rtrim($m[0], '.,;:!?)');
            return '<a href="' . $url . '" style="color:#087539;">' . $url . '</a>' . substr($m[0], strlen($url));
        }, $escaped);
        $html .= '<p style="margin:0 0 14px;font-size:15px;line-height:1.65;">' . $escaped . '</p>';
    }
    if ($pdf !== null && !empty($campaign['cover_ready'])) {
        $cover = htmlspecialchars(newsletterSiteUrl($config) . '/newsletter.php?cover=' . $campaign['pdf_key'], ENT_QUOTES);
        $html .= '<p style="margin:0;text-align:center;"><a href="' . htmlspecialchars($pdf, ENT_QUOTES) . '">'
            . '<img src="' . $cover . '" width="496" alt="' . htmlspecialchars($subject . ' - open the newsletter', ENT_QUOTES) . '"'
            . ' style="display:block;width:100%;max-width:496px;height:auto;margin:0 auto;border:1px solid #e4e7ec;border-radius:6px;"></a></p>';
    }
    $html = brandedEmailHtml($subject, $html, $hasCta ? $ctaLabel : null, $hasCta ? $ctaUrl : null);
    $footer = '<p style="margin:14px auto 0;max-width:520px;text-align:center;font-size:12px;line-height:1.6;color:#98a2b3;">'
        . 'You are receiving this because you subscribed to the ReSoK newsletter. '
        . '<a href="' . htmlspecialchars($leaveUrl, ENT_QUOTES) . '" style="color:#667085;">Unsubscribe</a></p>';
    $html = str_replace('</div></div></body></html>', '</div>' . $footer . '</div></body></html>', $html);
    return [$subject, $text, $html];
}

function newsletterCampaignRow(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM newsletter_campaigns WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

/** Where each sent newsletter has got to in the mail queue. */
function newsletterProgress(PDO $pdo, int $id): array
{
    $stmt = $pdo->prepare("SELECT status, COUNT(*) AS n FROM abs_emails WHERE template = 'newsletter' AND dedupe_key LIKE ? GROUP BY status");
    $stmt->execute(['nl:' . $id . ':%']);
    $counts = array_map('intval', array_column($stmt->fetchAll(), 'n', 'status'));
    return [
        'sent' => $counts['sent'] ?? 0,
        'waiting' => ($counts['queued'] ?? 0) + ($counts['sending'] ?? 0),
        'failed' => $counts['failed'] ?? 0,
    ];
}

function newsletterCampaignShape(PDO $pdo, array $r): array
{
    return [
        'id' => (int)$r['id'], 'subject' => $r['subject'], 'body' => $r['body'],
        'ctaLabel' => $r['cta_label'], 'ctaUrl' => $r['cta_url'], 'status' => $r['status'],
        'pdf' => $r['pdf_key'] ? ['name' => $r['pdf_name'], 'bytes' => (int)$r['pdf_bytes'], 'ready' => (bool)$r['pdf_ready'],
                                  'cover' => (bool)$r['cover_ready'], 'url' => '../../newsletter.php?pdf=' . $r['pdf_key']] : null,
        'recipients' => (int)$r['recipients'], 'createdAt' => $r['created_at'], 'updatedAt' => $r['updated_at'], 'sentAt' => $r['sent_at'],
        'progress' => $r['status'] === 'sent' ? newsletterProgress($pdo, (int)$r['id']) : null,
    ];
}

/** Checks and stores a draft; returns an error message, or null. */
function newsletterSaveDraft(PDO $pdo, array $data, int $userId, ?int &$id): ?string
{
    $subject = newsletterText($data['subject'] ?? '', 200);
    $body = newsletterText($data['body'] ?? '', 20000);
    $ctaLabel = newsletterText($data['ctaLabel'] ?? '', 60);
    $ctaUrl = newsletterText($data['ctaUrl'] ?? '', 500);
    if ($subject === '') return 'Write a subject.';
    if (($ctaLabel === '') !== ($ctaUrl === '')) return 'A button needs both a label and a link, or neither.';
    if ($ctaUrl !== '' && !preg_match('#^https://[^\s]+$#i', $ctaUrl)) return 'The button link must be a full https:// address.';
    $subject = (string)preg_replace('/\s+/', ' ', $subject);

    if ($id) {
        $row = newsletterCampaignRow($pdo, $id);
        if (!$row) return 'That newsletter no longer exists.';
        if ($row['status'] !== 'draft') return 'This newsletter has already been sent and can no longer be changed.';
        $pdo->prepare('UPDATE newsletter_campaigns SET subject = ?, body = ?, cta_label = ?, cta_url = ? WHERE id = ?')
            ->execute([$subject, $body, $ctaLabel ?: null, $ctaUrl ?: null, $id]);
    } else {
        $pdo->prepare('INSERT INTO newsletter_campaigns (subject, body, cta_label, cta_url, created_by) VALUES (?, ?, ?, ?, ?)')
            ->execute([$subject, $body, $ctaLabel ?: null, $ctaUrl ?: null, $userId]);
        $id = (int)$pdo->lastInsertId();
    }
    return null;
}

/**
 * Puts one copy per confirmed subscriber into the mail queue and marks the newsletter sent.
 *
 * The dedupe key makes each subscriber's copy once-only, so a double click, or two
 * administrators pressing Send together, cannot mail anyone twice.
 */
function newsletterQueue(PDO $pdo, array $config, array $campaign, int $userId): int
{
    $subscribers = $pdo->query("SELECT id, email FROM newsletter_subscribers WHERE status = 'confirmed' ORDER BY id")->fetchAll();
    $insert = $pdo->prepare("INSERT IGNORE INTO abs_emails (template, to_email, subject, status, text_body, html_body, dedupe_key)
                             VALUES ('newsletter', ?, ?, 'queued', ?, ?, ?)");
    $queued = 0;
    $pdo->beginTransaction();
    try {
        // Claim the newsletter first: only the request that moves it out of draft sends it.
        $claim = $pdo->prepare("UPDATE newsletter_campaigns SET status = 'sent', sent_at = NOW(), sent_by = ? WHERE id = ? AND status = 'draft'");
        $claim->execute([$userId, (int)$campaign['id']]);
        if ($claim->rowCount() === 0) { $pdo->rollBack(); return -1; }
        foreach ($subscribers as $s) {
            [$subject, $text, $html] = newsletterRender($config, $campaign, newsletterLeaveUrl($config, (int)$s['id'], (string)$s['email']));
            $insert->execute([mb_substr((string)$s['email'], 0, 190), mb_substr($subject, 0, 300), $text, $html, 'nl:' . (int)$campaign['id'] . ':' . (int)$s['id']]);
            $queued += $insert->rowCount();
        }
        // The archive inbox's one copy, in place of a copy of every subscriber's.
        $archive = trim((string)($config['mail_archive_bcc'] ?? 'respiratorysockenya@gmail.com'));
        if ($archive !== '' && filter_var($archive, FILTER_VALIDATE_EMAIL)) {
            [$subject, $text, $html] = newsletterRender($config, $campaign, newsletterSiteUrl($config) . '/');
            $insert->execute([$archive, mb_substr('[Archive] ' . $subject, 0, 300), $text, $html, 'nl:' . (int)$campaign['id'] . ':archive']);
        }
        $pdo->prepare('UPDATE newsletter_campaigns SET recipients = ? WHERE id = ?')->execute([$queued, (int)$campaign['id']]);
        $pdo->prepare('UPDATE newsletter_subscribers SET last_sent_at = NOW() WHERE status = ?')->execute(['confirmed']);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    absMailPump($pdo, $config, 20);
    return $queued;
}

function newsletterExportCsv(PDO $pdo): string
{
    $rows = $pdo->query("SELECT email, confirmed_at, source FROM newsletter_subscribers WHERE status = 'confirmed' ORDER BY confirmed_at, id")->fetchAll();
    $fh = fopen('php://temp', 'w+');
    fputcsv($fh, ['Email', 'Confirmed on', 'Signed up from']);
    // A cell starting with = + - @ is a formula to a spreadsheet; a quote in front keeps it text.
    $safe = static fn($v) => is_string($v) && $v !== '' && strpbrk($v[0], '=+-@') !== false ? "'" . $v : $v;
    foreach ($rows as $r) fputcsv($fh, array_map($safe, [$r['email'], $r['confirmed_at'], $r['source']]));
    rewind($fh);
    return (string)stream_get_contents($fh);
}

function newsletterHandle(PDO $pdo, array $config, string $route, string $method): void
{
    $user = auth($config);
    requireSuperAdmin($user, $config);
    if (!newsletterEnsureTables($pdo) || !abstractsEnsureTables($pdo)) {
        respond(503, ['error' => 'The newsletter tables could not be created.']);
    }
    $userId = (int)$user['userId'];

    if ($route === 'newsletter/admin' && $method === 'GET') {
        $subscribers = $pdo->query('SELECT id, email, status, source, created_at, confirmed_at, unsubscribed_at FROM newsletter_subscribers ORDER BY id DESC')->fetchAll();
        $counts = ['pending' => 0, 'confirmed' => 0, 'unsubscribed' => 0];
        foreach ($subscribers as $s) $counts[$s['status']]++;
        $campaigns = $pdo->query('SELECT * FROM newsletter_campaigns ORDER BY (status = "draft") DESC, COALESCE(sent_at, updated_at) DESC LIMIT 100')->fetchAll();
        $archiveOn = trim((string)($config['mail_archive_bcc'] ?? 'on')) !== '';
        respond(200, [
            'counts' => $counts,
            'subscribers' => array_map(static fn($s) => [
                'id' => (int)$s['id'], 'email' => $s['email'], 'status' => $s['status'], 'source' => $s['source'],
                'createdAt' => $s['created_at'], 'confirmedAt' => $s['confirmed_at'], 'unsubscribedAt' => $s['unsubscribed_at'],
            ], $subscribers),
            'campaigns' => array_map(static fn($c) => newsletterCampaignShape($pdo, $c), $campaigns),
            'perHour' => intdiv(absMailHourlyLimit($config), $archiveOn ? 2 : 1),
            'canSend' => newsletterSigningKey($config) !== '',
            'me' => (string)$user['email'],
        ]);
    }

    if ($route === 'newsletter/admin/export' && $method === 'GET') {
        logAdminAction($pdo, $userId, 'newsletter_export', null);
        header_remove('Content-Type');
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="resok-newsletter-subscribers-' . date('Y-m-d') . '.csv"');
        header('Cache-Control: private, no-store');
        echo "\xEF\xBB\xBF" . newsletterExportCsv($pdo);
        exit;
    }

    if (preg_match('#^newsletter/admin/subscribers/(\d+)/unsubscribe$#', $route, $m) && $method === 'POST') {
        $stmt = $pdo->prepare("UPDATE newsletter_subscribers SET status = 'unsubscribed', unsubscribed_at = NOW() WHERE id = ? AND status <> 'unsubscribed'");
        $stmt->execute([(int)$m[1]]);
        // Anything still waiting in the queue for them is withdrawn too.
        $pdo->prepare("DELETE FROM abs_emails WHERE template = 'newsletter' AND status = 'queued' AND dedupe_key LIKE ?")
            ->execute(['nl:%:' . (int)$m[1]]);
        logAdminAction($pdo, $userId, 'newsletter_unsubscribe', null, 'subscriber ' . (int)$m[1]);
        respond(200, ['ok' => true]);
    }

    if ($route === 'newsletter/admin/campaigns' && $method === 'POST') {
        $data = input();
        $id = isset($data['id']) && is_numeric($data['id']) ? (int)$data['id'] : null;
        $error = newsletterSaveDraft($pdo, $data, $userId, $id);
        if ($error) respond(400, ['error' => $error]);
        respond(200, ['campaign' => newsletterCampaignShape($pdo, newsletterCampaignRow($pdo, $id))]);
    }

    // A designed PDF, sent in 1 MB pieces so a large file gets past the host's upload limit,
    // then its first page as a picture (made in the browser) for the email.
    if (preg_match('#^newsletter/admin/campaigns/(\d+)/(pdf-start|pdf-chunk|pdf-finish|pdf-remove|cover)$#', $route, $m) && $method === 'POST') {
        $campaign = newsletterCampaignRow($pdo, (int)$m[1]);
        if (!$campaign) respond(404, ['error' => 'That newsletter no longer exists.']);
        if ($campaign['status'] !== 'draft') respond(400, ['error' => 'This newsletter has already been sent.']);
        $id = (int)$campaign['id'];
        $key = (string)$campaign['pdf_key'];
        $dir = newsletterDir($config);

        if ($m[2] === 'pdf-start') {
            $data = input();
            $name = newsletterText($data['fileName'] ?? '', 200);
            $size = (int)($data['size'] ?? 0);
            if (!preg_match('/\.pdf$/i', $name)) respond(400, ['error' => 'Upload a PDF file.']);
            if ($size < 100 || $size > NEWSLETTER_PDF_MAX_BYTES) {
                respond(400, ['error' => 'The PDF must be under ' . (NEWSLETTER_PDF_MAX_BYTES >> 20) . ' MB. In Canva, choose "Compress file" when downloading.']);
            }
            // A new key for every upload, so a link to an earlier version never shows the new one.
            newsletterRemoveFiles($config, $key);
            $key = bin2hex(random_bytes(16));
            $pdo->prepare('UPDATE newsletter_campaigns SET pdf_key = ?, pdf_name = ?, pdf_bytes = ?, pdf_received = 0, pdf_ready = 0, cover_ready = 0 WHERE id = ?')
                ->execute([$key, preg_replace('/[^\w .()\-]/u', '_', $name), $size, $id]);
            respond(200, ['chunkBytes' => NEWSLETTER_CHUNK_BYTES]);
        }
        if ($m[2] === 'pdf-remove') {
            newsletterRemoveFiles($config, $key);
            $pdo->prepare('UPDATE newsletter_campaigns SET pdf_key = NULL, pdf_name = NULL, pdf_bytes = NULL, pdf_received = 0, pdf_ready = 0, cover_ready = 0 WHERE id = ?')
                ->execute([$id]);
            respond(200, ['campaign' => newsletterCampaignShape($pdo, newsletterCampaignRow($pdo, $id))]);
        }
        if ($key === '') respond(400, ['error' => 'Start the PDF upload first.']);

        if ($m[2] === 'pdf-chunk') {
            $offset = (int)($_GET['offset'] ?? -1);
            $received = (int)$campaign['pdf_received'];
            if ($campaign['pdf_ready']) respond(400, ['error' => 'This PDF is already uploaded.']);
            if ($offset !== $received) respond(409, ['error' => 'Out of order.', 'received' => $received]);
            $bytes = (string)file_get_contents('php://input', false, null, 0, NEWSLETTER_CHUNK_BYTES + 1);
            $len = strlen($bytes);
            if ($len === 0 || $len > NEWSLETTER_CHUNK_BYTES) respond(400, ['error' => 'Each piece must be between 1 byte and 1 MB.']);
            if ($offset + $len > (int)$campaign['pdf_bytes']) respond(400, ['error' => 'The file is larger than announced.']);
            if (file_put_contents($dir . '/' . $key . '.part', $bytes, FILE_APPEND | LOCK_EX) !== $len) {
                respond(500, ['error' => 'The server could not store the upload.']);
            }
            $pdo->prepare('UPDATE newsletter_campaigns SET pdf_received = pdf_received + ? WHERE id = ? AND pdf_received = ?')->execute([$len, $id, $offset]);
            respond(200, ['received' => $offset + $len]);
        }
        if ($m[2] === 'pdf-finish') {
            $part = $dir . '/' . $key . '.part';
            if ((int)$campaign['pdf_received'] !== (int)$campaign['pdf_bytes'] || @filesize($part) !== (int)$campaign['pdf_bytes']) {
                respond(400, ['error' => 'The upload is incomplete. Please try again.']);
            }
            $problem = newsletterPdfProblem($part);
            if ($problem) {
                newsletterRemoveFiles($config, $key);
                $pdo->prepare('UPDATE newsletter_campaigns SET pdf_key = NULL, pdf_name = NULL, pdf_bytes = NULL, pdf_received = 0 WHERE id = ?')->execute([$id]);
                respond(400, ['error' => $problem]);
            }
            rename($part, $dir . '/' . $key . '.pdf');
            $pdo->prepare('UPDATE newsletter_campaigns SET pdf_ready = 1 WHERE id = ?')->execute([$id]);
            respond(200, ['campaign' => newsletterCampaignShape($pdo, newsletterCampaignRow($pdo, $id))]);
        }
        // cover: the first page as a JPEG, drawn by pdf.js in the administrator's browser.
        if (!$campaign['pdf_ready']) respond(400, ['error' => 'Upload the PDF first.']);
        $bytes = (string)file_get_contents('php://input', false, null, 0, NEWSLETTER_COVER_MAX_BYTES + 1);
        $info = $bytes !== '' && strlen($bytes) <= NEWSLETTER_COVER_MAX_BYTES ? @getimagesizefromstring($bytes) : false;
        if (!$info || $info[2] !== IMAGETYPE_JPEG || $info[0] < 200 || $info[0] > 2000) {
            respond(400, ['error' => 'The preview picture could not be made. The newsletter will go out with a button only.']);
        }
        file_put_contents($dir . '/' . $key . '.jpg', $bytes, LOCK_EX);
        $pdo->prepare('UPDATE newsletter_campaigns SET cover_ready = 1 WHERE id = ?')->execute([$id]);
        respond(200, ['campaign' => newsletterCampaignShape($pdo, newsletterCampaignRow($pdo, $id))]);
    }

    if (preg_match('#^newsletter/admin/campaigns/(\d+)/(test|send|delete)$#', $route, $m) && $method === 'POST') {
        $campaign = newsletterCampaignRow($pdo, (int)$m[1]);
        if (!$campaign) respond(404, ['error' => 'That newsletter no longer exists.']);
        if ($campaign['status'] !== 'draft') respond(400, ['error' => 'This newsletter has already been sent.']);

        if ($m[2] === 'delete') {
            $pdo->prepare("DELETE FROM newsletter_campaigns WHERE id = ? AND status = 'draft'")->execute([(int)$campaign['id']]);
            newsletterRemoveFiles($config, $campaign['pdf_key']);
            respond(200, ['ok' => true]);
        }

        $problem = newsletterContentProblem($campaign);
        if ($problem) respond(400, ['error' => $problem]);

        if ($m[2] === 'test') {
            // Straight to the administrator, not through the queue: they are waiting to look at it.
            [$subject, $text, $html] = newsletterRender($config, $campaign, newsletterSiteUrl($config) . '/');
            $ok = (new SimpleMailer($config))->withoutArchiveCopy()->send((string)$user['email'], '[Test] ' . $subject, $text, [], $html);
            if (!$ok) respond(502, ['error' => 'The mail server did not accept the test email. Try again in a few minutes.']);
            respond(200, ['ok' => true, 'to' => $user['email']]);
        }

        // send
        if (newsletterSigningKey($config) === '') {
            respond(400, ['error' => 'Set data_encryption_key in config.local.php first: the unsubscribe links are signed with it.']);
        }
        $confirmed = (int)$pdo->query("SELECT COUNT(*) FROM newsletter_subscribers WHERE status = 'confirmed'")->fetchColumn();
        if ($confirmed === 0) respond(400, ['error' => 'There are no confirmed subscribers to send to yet.']);
        // The number the administrator agreed to must still be the number on the list.
        if ((int)(input()['expected'] ?? -1) !== $confirmed) {
            respond(409, ['error' => 'The list has changed since the page loaded. Reload and check the number before sending.']);
        }
        $queued = newsletterQueue($pdo, $config, $campaign, $userId);
        if ($queued < 0) respond(400, ['error' => 'This newsletter has already been sent.']);
        logAdminAction($pdo, $userId, 'newsletter_send', null, 'campaign ' . (int)$campaign['id'] . ' to ' . $queued);
        respond(200, ['queued' => $queued, 'campaign' => newsletterCampaignShape($pdo, newsletterCampaignRow($pdo, (int)$campaign['id']))]);
    }

    respond(404, ['error' => 'Route not found']);
}
