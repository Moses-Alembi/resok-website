<?php
declare(strict_types=1);

/**
 * Figures on an abstract (SUB-10): a chart, table image or scan, each with a caption.
 *
 * Files live in upload_dir/abstract-figures, outside the web root, and are only ever served
 * through the API after checking who is asking: the author, the chairs who reach the
 * abstract's track, and reviewers assigned to it.
 *
 * Only JPEG and PNG are accepted, and their metadata is cut out before the file is stored.
 * A photo's EXIF block or a PNG text chunk can carry the author's name, their institution or
 * the camera owner, which would quietly undo blind review. The stripping is done by walking
 * the file's own structure rather than re-encoding through GD, so it works on any host and
 * never changes a pixel.
 */

const ABS_FIGURE_MAX_BYTES = 3 * 1024 * 1024;
const ABS_FIGURE_MAX_SIDE = 10000;

function absFigureDir(array $config): string
{
    return rtrim((string)$config['upload_dir'], '/\\') . '/abstract-figures';
}

function absFigureUrl(int $figureId): string
{
    return 'api/index.php?route=abstracts/figures/' . $figureId;
}

/** The figures of one abstract, in order, as the pages use them. */
function absFigures(PDO $pdo, int $abstractId): array
{
    $stmt = $pdo->prepare('SELECT * FROM abs_figures WHERE abstract_id = ? ORDER BY sort_order, id');
    $stmt->execute([$abstractId]);
    return array_map(static fn($f) => [
        'id' => (int)$f['id'],
        'caption' => (string)$f['caption'],
        'width' => (int)$f['width'],
        'height' => (int)$f['height'],
        'bytes' => (int)$f['bytes'],
        'url' => absFigureUrl((int)$f['id']),
    ], $stmt->fetchAll());
}

function absFigureRow(PDO $pdo, int $figureId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM abs_figures WHERE id = ? LIMIT 1');
    $stmt->execute([$figureId]);
    return $stmt->fetch() ?: null;
}

/**
 * JPEG without its metadata segments. Kept: JFIF (APP0), the colour profile (APP2
 * ICC_PROFILE) and Adobe's colour-transform marker (APP14), which change how the picture
 * looks. Dropped: EXIF and XMP (APP1), every other APPn, and comments.
 */
function absStripJpeg(string $data): ?string
{
    if (substr($data, 0, 2) !== "\xFF\xD8") return null;
    $out = "\xFF\xD8";
    $pos = 2;
    $len = strlen($data);
    while ($pos + 4 <= $len) {
        if ($data[$pos] !== "\xFF") return null;
        $marker = ord($data[$pos + 1]);
        if ($marker === 0xFF) { $pos++; continue; } // fill byte
        if ($marker === 0xD8 || ($marker >= 0xD0 && $marker <= 0xD7) || $marker === 0x01) {
            $out .= substr($data, $pos, 2);
            $pos += 2;
            continue;
        }
        $segLen = unpack('n', substr($data, $pos + 2, 2))[1];
        if ($segLen < 2 || $pos + 2 + $segLen > $len) return null;
        $segment = substr($data, $pos, 2 + $segLen);
        if ($marker === 0xDA) return $out . substr($data, $pos); // start of scan: the picture itself
        $payload = substr($segment, 4);
        $keep = !($marker >= 0xE1 && $marker <= 0xEF) && $marker !== 0xFE;
        if ($marker === 0xE2 && strncmp($payload, 'ICC_PROFILE', 11) === 0) $keep = true;
        if ($marker === 0xEE && strncmp($payload, 'Adobe', 5) === 0) $keep = true;
        if ($keep) $out .= $segment;
        $pos += 2 + $segLen;
    }
    return null;
}

/** PNG without its text, EXIF and timestamp chunks. */
function absStripPng(string $data): ?string
{
    $signature = "\x89PNG\r\n\x1A\n";
    if (strncmp($data, $signature, 8) !== 0) return null;
    $out = $signature;
    $pos = 8;
    $len = strlen($data);
    while ($pos + 12 <= $len) {
        $chunkLen = unpack('N', substr($data, $pos, 4))[1];
        $type = substr($data, $pos + 4, 4);
        if ($pos + 12 + $chunkLen > $len) return null;
        if (!in_array($type, ['tEXt', 'zTXt', 'iTXt', 'eXIf', 'tIME'], true)) {
            $out .= substr($data, $pos, 12 + $chunkLen);
        }
        $pos += 12 + $chunkLen;
        if ($type === 'IEND') return $out;
    }
    return null;
}

/**
 * Adds a figure to an abstract the author may still edit.
 *
 * @return array{0: ?array, 1: ?string} [the abstract's figures after the upload, error]
 */
function absFigureUpload(PDO $pdo, array $config, array $eventRow, array $row, array $file, string $caption, int $userId): array
{
    [$editable, $why] = absEditable($eventRow, $row);
    if (!$editable) return [null, $why];
    $s = absSettings($eventRow['settings'] ?? null);
    if ($s['figuresMax'] < 1) return [null, 'This conference does not accept figures.'];
    $count = $pdo->prepare('SELECT COUNT(*) FROM abs_figures WHERE abstract_id = ?');
    $count->execute([(int)$row['id']]);
    if ((int)$count->fetchColumn() >= $s['figuresMax']) {
        return [null, "An abstract can have at most {$s['figuresMax']} figure(s). Remove one first."];
    }

    $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) return [null, 'That file is larger than 3 MB.'];
    if ($error !== UPLOAD_ERR_OK || !is_uploaded_file((string)($file['tmp_name'] ?? ''))) return [null, 'The upload did not arrive. Please try again.'];
    $max = min(ABS_FIGURE_MAX_BYTES, (int)($config['max_file_size'] ?? ABS_FIGURE_MAX_BYTES));
    if ((int)$file['size'] > $max) return [null, 'That file is larger than ' . round($max / 1048576, 1) . ' MB.'];

    $data = (string)file_get_contents((string)$file['tmp_name']);
    $info = @getimagesizefromstring($data);
    if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG], true)) {
        return [null, 'Upload the figure as a JPG or PNG image.'];
    }
    [$width, $height, $type] = $info;
    if ($width < 1 || $height < 1 || $width > ABS_FIGURE_MAX_SIDE || $height > ABS_FIGURE_MAX_SIDE) {
        return [null, 'That image is too large in pixels. Export it at a smaller size.'];
    }
    $clean = $type === IMAGETYPE_JPEG ? absStripJpeg($data) : absStripPng($data);
    if ($clean === null || !@getimagesizefromstring($clean)) {
        return [null, 'That image file looks damaged. Export it again and retry.'];
    }

    $dir = absFigureDir($config);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) return [null, 'Figures cannot be stored on the server yet. Please tell the secretariat.'];
    $extension = $type === IMAGETYPE_JPEG ? 'jpg' : 'png';
    $name = 'fig-' . bin2hex(random_bytes(16)) . '.' . $extension;
    if (file_put_contents($dir . '/' . $name, $clean) === false) return [null, 'The figure could not be saved. Please try again.'];

    $caption = absRichClean(trim((string)preg_replace('/\s+/u', ' ', $caption)));
    $pdo->prepare('INSERT INTO abs_figures (abstract_id, file, mime, bytes, width, height, caption, sort_order, uploaded_by)
                   VALUES (?, ?, ?, ?, ?, ?, ?, (SELECT COALESCE(MAX(f.sort_order), -1) + 1 FROM abs_figures f WHERE f.abstract_id = ?), ?)')
        ->execute([(int)$row['id'], $name, $type === IMAGETYPE_JPEG ? 'image/jpeg' : 'image/png', strlen($clean), $width, $height,
                   mb_substr($caption, 0, 300), (int)$row['id'], $userId]);
    $id = (int)$pdo->lastInsertId();
    if ($row['status'] !== 'draft') absAudit($pdo, (int)$row['event_id'], (int)$row['id'], $userId, 'figure_added', null, null, ['figure' => $id]);
    return [absFigures($pdo, (int)$row['id']), null];
}

/** @return ?string an error, or null when saved */
function absFigureCaption(PDO $pdo, array $eventRow, array $row, int $figureId, string $caption, int $userId): ?string
{
    [$editable, $why] = absEditable($eventRow, $row);
    if (!$editable) return $why;
    $caption = absRichClean(trim((string)preg_replace('/\s+/u', ' ', $caption)));
    if (mb_strlen($caption) > 300) return 'Keep the caption to 300 characters.';
    $stmt = $pdo->prepare('UPDATE abs_figures SET caption = ? WHERE id = ? AND abstract_id = ?');
    $stmt->execute([$caption, $figureId, (int)$row['id']]);
    return null;
}

/** @return ?string an error, or null when removed */
function absFigureDelete(PDO $pdo, array $config, array $eventRow, array $row, int $figureId, int $userId): ?string
{
    [$editable, $why] = absEditable($eventRow, $row);
    if (!$editable) return $why;
    $figure = absFigureRow($pdo, $figureId);
    if (!$figure || (int)$figure['abstract_id'] !== (int)$row['id']) return 'No such figure.';
    $pdo->prepare('DELETE FROM abs_figures WHERE id = ?')->execute([$figureId]);
    @unlink(absFigureDir($config) . '/' . basename((string)$figure['file']));
    if ($row['status'] !== 'draft') absAudit($pdo, (int)$row['event_id'], (int)$row['id'], $userId, 'figure_removed', null, null, ['figure' => $figureId]);
    return null;
}

/** Problems that stop a submission: captions missing, or more figures than the event now allows. */
function absFigureErrors(PDO $pdo, array $settings, int $abstractId): ?string
{
    $figures = absFigures($pdo, $abstractId);
    if (count($figures) > $settings['figuresMax']) {
        return $settings['figuresMax'] > 0 ? "Remove figures until there are at most {$settings['figuresMax']}." : 'This conference does not accept figures; remove them.';
    }
    foreach ($figures as $i => $f) {
        if (trim(absPlain($f['caption'])) === '') return 'Add a caption to figure ' . ($i + 1) . '.';
    }
    return null;
}

/**
 * Whether this person may see a figure: the submitter, a chair or administrator who reaches
 * the abstract's track, or a reviewer with a live assignment to the abstract.
 */
function absFigureVisible(PDO $pdo, array $user, array $config, array $abstract): bool
{
    $userId = (int)($user['userId'] ?? 0);
    if ((int)$abstract['submitter_user_id'] === $userId) return true;
    if (function_exists('absAccess')) {
        $access = absAccess($pdo, $user, $config, (int)$abstract['event_id']);
        if ($access['chair'] && absReaches($access, $abstract['track_id'])) return true;
    } elseif (absIsEventAdmin($pdo, $user, $config, (int)$abstract['event_id'])) {
        return true;
    }
    $stmt = $pdo->prepare("SELECT 1 FROM abs_reviews WHERE abstract_id = ? AND reviewer_user_id = ?
                           AND status NOT IN ('cancelled','declined') LIMIT 1");
    $stmt->execute([(int)$abstract['id'], $userId]);
    return (bool)$stmt->fetch();
}

/**
 * Sends the image and stops. Served as exactly the type it was checked to be, with nosniff,
 * so a browser can never be talked into treating it as a page.
 *
 * @return never
 */
function absFigureSend(array $config, array $figure, ?string $reference): void
{
    $path = absFigureDir($config) . '/' . basename((string)$figure['file']);
    if (!is_file($path)) respond(404, ['error' => 'Figure not found.']);
    $mime = $figure['mime'] === 'image/png' ? 'image/png' : 'image/jpeg';
    $name = preg_replace('/[^A-Za-z0-9-]/', '', (string)($reference ?: 'abstract')) . '-figure-' . (int)$figure['id']
          . ($mime === 'image/png' ? '.png' : '.jpg');
    header_remove('Content-Type');
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($path));
    header('Content-Disposition: inline; filename="' . $name . '"');
    header("Content-Security-Policy: default-src 'none'; sandbox");
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=3600');
    readfile($path);
    exit;
}
