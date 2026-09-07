<?php
declare(strict_types=1);

/**
 * Input validation and automated-submission screening.
 *
 * Two concerns in one file, deliberately: every additional file is another chance for a
 * half-deploy, which has broken this portal three times. They share callers and are always
 * wanted together.
 *
 * ---------------------------------------------------------------------------------------
 * SCREENING
 *
 * No CAPTCHA. A CAPTCHA taxes every real member - disproportionately those on poor
 * connections or using a screen reader - to inconvenience a bot author for an afternoon.
 * Two quieter signals catch the automation that actually turns up:
 *
 *   honeypot  - a field styled out of sight. A person never fills it; a script that posts
 *               every field it finds does.
 *   dwell     - the time between the form loading and being submitted. A person completing
 *               fifteen fields takes far longer than a second.
 *
 * Both fail OPEN. A missing field means an older page or a browser that stripped it, not an
 * attack, and treating that as one would lock out real members. They raise the cost of bulk
 * automation; they are not access control, and the rate limiter is what actually bounds it.
 *
 * ---------------------------------------------------------------------------------------
 * VALIDATION
 *
 * Registration accepted fifteen fields and checked four. The rest went to the database at
 * whatever length and shape arrived. These are not security boundaries on their own -
 * queries are bound and output is escaped - but unbounded input is how a name field ends up
 * holding a kilobyte of someone's pasted CV, and how a column silently truncates.
 */

const BOT_MIN_SECONDS = 3;
const BOT_HONEYPOT_FIELD = 'website';
const BOT_TIMESTAMP_FIELD = 'formLoadedAt';

/**
 * Screens one submission. Returns null when it looks human, or a reason when it does not.
 * The caller decides what to do - usually answer as though it worked, so a script learns
 * nothing about which signal caught it.
 */
function botScreen(array $data, int $minSeconds = BOT_MIN_SECONDS): ?string
{
    $honeypot = trim((string)($data[BOT_HONEYPOT_FIELD] ?? ''));
    if ($honeypot !== '') return 'honeypot';

    // $minSeconds of 0 turns the dwell signal off. Login and password reset pass 0: they are
    // one or two fields, and a member whose password manager fills them and submits in two
    // seconds is doing nothing unusual. Silently failing that person - with a wrong-password
    // message, or a reset email that never arrives - costs more than the automation it stops.
    // The honeypot still applies to them, and the rate limiter is what actually bounds abuse.
    $loadedAt = $minSeconds > 0 ? ($data[BOT_TIMESTAMP_FIELD] ?? null) : null;
    if ($loadedAt !== null && is_numeric($loadedAt)) {
        // Milliseconds from the browser clock, so only the elapsed span is used - never the
        // absolute time, which may be wrong by hours on a device with a bad clock.
        $elapsed = (microtime(true) * 1000 - (float)$loadedAt) / 1000;
        if ($elapsed >= 0 && $elapsed < $minSeconds) return 'too-fast';
    }
    return null;
}

/** Screens, logs, and answers as though it succeeded. Never reveals which signal fired. */
function botScreenOrFakeSuccess(PDO $pdo, array $config, array $data, string $action, array $pretendResponse, int $minSeconds = BOT_MIN_SECONDS): void
{
    $reason = botScreen($data, $minSeconds);
    if ($reason === null) return;
    if (function_exists('securityLog')) {
        securityLog($pdo, $config, 'bot_blocked', 'info', $action, $reason);
    }
    respond(200, $pretendResponse);
}

/* ------------------------------------------------------------------------------------- */

/**
 * Checks one field against a shape. Returns an error message, or null when it is fine.
 *
 * @param string $rule text|name|phone|email|id|address|choice
 */
function validateField(string $label, $value, string $rule, bool $required = true, array $options = []): ?string
{
    $value = is_string($value) ? trim($value) : $value;

    if ($value === null || $value === '') {
        return $required ? "{$label} is required." : null;
    }
    if (!is_string($value)) return "{$label} is not valid.";

    $length = mb_strlen($value);
    switch ($rule) {
        case 'name':
            // Letters, spaces, apostrophes, hyphens and full stops. Unicode-aware, because
            // Kenyan names carry accents and an ASCII-only rule would reject real people.
            if ($length < 2 || $length > 80) return "{$label} must be between 2 and 80 characters.";
            if (!preg_match("/^[\p{L}\p{M}][\p{L}\p{M}\s'.\-]*$/u", $value)) {
                return "{$label} may only contain letters, spaces, apostrophes and hyphens.";
            }
            return null;

        case 'phone':
            if (!preg_match('/^\+[1-9]\d{7,14}$/', $value)) {
                return "{$label} must be in international format, for example +254712345678.";
            }
            return null;

        case 'email':
            if ($length > 190 || !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                return "{$label} must be a valid email address.";
            }
            return null;

        case 'id':
            // Deliberately permissive on shape - national IDs, passports and practising
            // licences all land here - but bounded, and no characters that only appear in
            // an attempt to smuggle markup.
            if ($length < 4 || $length > 40) return "{$label} must be between 4 and 40 characters.";
            if (!preg_match('/^[A-Za-z0-9\/\-. ]+$/', $value)) {
                return "{$label} may only contain letters, numbers, spaces and - / .";
            }
            return null;

        case 'address':
            if ($length > 300) return "{$label} must be 300 characters or fewer.";
            if (preg_match('/[<>]/', $value)) return "{$label} may not contain < or >.";
            return null;

        case 'choice':
            $allowed = $options['allowed'] ?? [];
            if ($allowed && !in_array($value, $allowed, true)) return "{$label} is not one of the available options.";
            return null;

        case 'text':
        default:
            $max = (int)($options['max'] ?? 200);
            if ($length > $max) return "{$label} must be {$max} characters or fewer.";
            if (preg_match('/[<>]/', $value)) return "{$label} may not contain < or >.";
            return null;
    }
}

/**
 * Validates a whole submission and responds with every problem at once, rather than making
 * someone resubmit to discover the next one.
 *
 * @param array<string,array{0:string,1:string,2?:bool,3?:array}> $rules field => [label, rule, required?, options?]
 */
function validateInput(array $data, array $rules): void
{
    $errors = [];
    foreach ($rules as $field => $spec) {
        $error = validateField($spec[0], $data[$field] ?? null, $spec[1], $spec[2] ?? true, $spec[3] ?? []);
        if ($error !== null) $errors[$field] = $error;
    }
    if ($errors) {
        respond(400, [
            'error' => count($errors) === 1 ? reset($errors) : 'Please check the highlighted fields.',
            'fields' => $errors,
        ]);
    }
}
