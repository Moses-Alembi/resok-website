<?php
declare(strict_types=1);

require_once __DIR__ . '/SimplePdf.php';
require_once __DIR__ . '/SimpleMailer.php';

/**
 * The name as a letter addresses it: given name and surname, no title.
 *
 * Capitalisation is repaired only for words stored entirely in lower case, which covers the
 * "moses alembi" left by hurried data entry while leaving McDonald, O'Brien and van der Berg
 * exactly as their owner wrote them. Blanket ucwords() would corrupt all three.
 */
function portalGreetingName(array $member): string
{
    $parts = array_filter([$member['firstName'] ?? null, $member['surname'] ?? null]);
    $name = trim(preg_replace('/\s+/', ' ', implode(' ', $parts)));
    if ($name === '') {
        return 'ReSoK Member';
    }
    $words = array_map(static function (string $word): string {
        return $word === mb_strtolower($word, 'UTF-8') ? mb_convert_case($word, MB_CASE_TITLE, 'UTF-8') : $word;
    }, explode(' ', $name));
    return implode(' ', $words);
}

function portalMemberName(array $member): string
{
    $parts = array_filter([$member['title'] ?? null, $member['firstName'] ?? null, $member['middleName'] ?? null, $member['surname'] ?? null]);
    $name = trim(preg_replace('/\s+/', ' ', implode(' ', $parts)));
    return $name !== '' ? $name : 'ReSoK Member';
}

/**
 * The society's own welcome letter, personalised.
 *
 * The letter is a designed document - letterhead, signature, the CEO's wording - not
 * something to approximate in code. It is carried as a baseline JPEG of the artwork with the
 * two placeholders already painted out, so the only thing done per member is drawing the
 * date and their name onto it. The positions below were measured from the artwork itself.
 *
 * The template lives in private/, which Apache refuses to serve: it is a signed letterhead,
 * and a blank one that anyone could download is a forgery kit.
 *
 * If the template is missing, this falls back to the plain generated letter rather than
 * failing - an approved member should still receive something.
 */
function buildWelcomeLetterPdf(array $member): string
{
    $template = __DIR__ . '/../../../../private/welcome-letter-template.jpg';
    $pdf = new SimplePdf(612, 792);   // US Letter, matching the artwork

    // method_exists guards against the half-deploy this file has already caused once: this
    // function updated while SimplePdf.php did not, so calling image() was a fatal error -
    // swallowed by the try/catch around the send, which meant approval succeeded and the
    // member simply never received anything.
    if (is_file($template) && method_exists($pdf, 'image') && $pdf->image($template, 0, 0, 612, 792)) {
        // Measured from the 1275x1650 render at 0.48 pt per pixel. Baselines sit just under
        // the "Date:" and "Dear" labels already printed on the page.
        $pdf->setTextColor(31, 31, 31);
        $pdf->text(52, 166, date('j F Y'), 12);
        $pdf->text(53, 188, portalMemberName($member), 12);
        return $pdf->output();
    }

    // 'Missing' was the wrong word for two different faults: the file can be present and
    // still rejected by image(). Saying which one it was is the difference between a
    // one-line fix and hunting the wrong thing.
    error_log(sprintf(
        'Welcome letter artwork unused (%s at %s) - sending the plain letter instead.',
        is_file($template) ? 'present but rejected by SimplePdf::image()' : 'file not found',
        $template
    ));
    return buildPlainWelcomeLetterPdf($member);
}

/** The original generated letter, kept as the fallback when the artwork is unavailable. */
function buildPlainWelcomeLetterPdf(array $member): string
{
    $pdf = new SimplePdf(595, 842); // A4 portrait
    $pdf->setFillColor(0, 147, 46);
    $pdf->rect(0, 0, 595, 86, 'F');
    $pdf->setTextColor(255, 255, 255);
    $pdf->text(48, 40, 'RESPIRATORY SOCIETY OF KENYA', 20, true);
    $pdf->text(48, 64, 'Welcome Letter from the Chief Executive Officer', 11);

    $pdf->setTextColor(15, 23, 42);
    $name = portalMemberName($member);
    $membershipId = $member['membershipId'] ?? 'Pending';
    $portal = 'https://www.resok.org/resok-portal/public';

    $y = 140;
    $pdf->text(48, $y, 'Dear ' . $name . ',', 13, true);
    $y += 30;

    $body = "On behalf of the Respiratory Society of Kenya, welcome to our community of clinicians, researchers, and respiratory health advocates.\n\nYour membership journey begins here. We look forward to supporting your professional growth, CPD learning, and contribution to healthier lungs for all people in Kenya and beyond.\n\nYour membership number and digital membership card are attached to this email. Please keep your membership number for reference in all correspondence with the Society.";
    $y = $pdf->multilineText(48, $y, 500, $body, 12, 18);

    $y += 14;
    $pdf->setFillColor(247, 250, 248);
    $pdf->rect(48, $y - 18, 500, 46, 'F');
    $pdf->setTextColor(0, 147, 46);
    $pdf->text(64, $y + 4, 'Membership ID: ' . $membershipId, 13, true);
    $pdf->setTextColor(15, 23, 42);
    $pdf->text(64, $y + 22, 'Member portal: ' . $portal, 10);
    $y += 70;

    $pdf->text(48, $y, 'Warm regards,', 12);
    $y += 24;
    $pdf->text(48, $y, 'Chief Executive Officer', 12, true);
    $y += 16;
    $pdf->text(48, $y, 'Respiratory Society of Kenya', 12);

    return $pdf->output();
}

function buildMembershipCardPdf(array $member): string
{
    // The member already has a card in the portal: artwork with their details placed over it.
    // Generating a different-looking one for the email gave people two cards that disagreed,
    // so this draws the same background and the same fields, at half the artwork's scale.
    $template = __DIR__ . '/../../../../private/membership-card-bg.jpg';
    $scale = 0.5;
    $width = 1012 * $scale;
    $height = 645 * $scale;
    $pdf = new SimplePdf($width, $height);

    if (!is_file($template) || !method_exists($pdf, 'image') || !$pdf->image($template, 0, 0, $width, $height)) {
        error_log(sprintf(
            'Membership card artwork unused (%s at %s) - sending the plain card instead.',
            is_file($template) ? 'present but rejected by SimplePdf::image()' : 'file not found',
            $template
        ));
        return buildPlainMembershipCardPdf($member);
    }

    // Names and categories vary in length and must shrink rather than run off the artwork,
    // as the portal's card does. 0.58 is SimplePdf's own average glyph width for bold text.
    $fit = static function (string $text, float $size, float $maxWidth): float {
        while ($size > 7 && strlen($text) * $size * 0.58 > $maxWidth) {
            $size -= 0.5;
        }
        return $size;
    };

    $name = strtoupper(portalMemberName($member));
    $membershipId = (string)($member['membershipId'] ?? 'PENDING');
    $category = strtoupper((string)($member['category'] ?? 'MEMBER'));

    $renewalDue = (string)($member['renewalDue'] ?? '');
    $validThru = 'ANNUAL';
    if ($renewalDue !== '' && stripos($renewalDue, 'pending') === false) {
        $timestamp = strtotime($renewalDue);
        if ($timestamp !== false) {
            $validThru = date('m/y', $timestamp);
        }
    }

    $pdf->setTextColor(255, 255, 255);
    // These are the portal SVG's own coordinates multiplied by the scale, kept in that form
    // so that a change to either card can be mirrored in the other by inspection.
    $pdf->text(506 * $scale, 327 * $scale, $category, $fit($category, 56 * $scale, 930 * $scale), true, 'C');
    $pdf->text(56 * $scale, 441 * $scale, $membershipId, $fit($membershipId, 52 * $scale, 400 * $scale), true);
    $pdf->text(736 * $scale, 409 * $scale, 'VALID', 26 * $scale, true, 'R');
    $pdf->text(736 * $scale, 441 * $scale, 'THRU', 26 * $scale, true, 'R');
    $pdf->text(768 * $scale, 426 * $scale, $validThru, 42 * $scale, true);
    $pdf->text(506 * $scale, 600 * $scale, $name, $fit($name, 46 * $scale, 700 * $scale), true, 'C');

    return $pdf->output();
}

/** The original generated card, kept as the fallback when the artwork is unavailable. */
function buildPlainMembershipCardPdf(array $member): string
{
    $pdf = new SimplePdf(340, 214); // landscape, CR80-ish proportions in points
    $pdf->setFillColor(11, 95, 47);
    $pdf->rect(0, 0, 340, 214, 'F');
    $pdf->setFillColor(0, 147, 46);
    $pdf->rect(0, 0, 340, 214 * 0.62, 'F');

    $pdf->setFillColor(188, 11, 34);
    $pdf->rect(18, 16, 62, 20, 'F');
    $pdf->setTextColor(255, 255, 255);
    $pdf->text(49, 30, 'ReSoK', 10, true, 'C');

    $pdf->text(18, 62, 'MEMBERSHIP CARD', 9, true);

    $membershipId = (string)($member['membershipId'] ?? 'PENDING');
    $pdf->text(18, 92, $membershipId, 22, true);

    $name = strtoupper(portalMemberName($member));
    $pdf->text(18, 114, $name, 10, true);

    $category = strtoupper((string)($member['category'] ?? 'MEMBER'));
    $pdf->text(18, 130, $category, 8);

    $renewalDue = (string)($member['renewalDue'] ?? '');
    $validThru = 'MM/YY';
    if ($renewalDue !== '' && stripos($renewalDue, 'pending') === false) {
        $timestamp = strtotime($renewalDue);
        if ($timestamp !== false) {
            $validThru = date('m/y', $timestamp);
        }
    }
    $pdf->setTextColor(255, 255, 255);
    $pdf->text(18, 172, 'VALID THRU', 8, true);
    $pdf->text(18, 194, $validThru, 16, true);

    $pdf->setTextColor(255, 255, 255);
    $pdf->text(322, 194, 'Respiratory Society of Kenya', 7, false, 'R');

    return $pdf->output();
}

/**
 * Wraps email body content in ReSoK-branded HTML (green header, red accent bar, optional
 * CTA button, footer) so transactional emails look like part of the actual site rather
 * than plain unstyled text.
 */
function brandedEmailHtml(string $title, string $bodyHtml, ?string $ctaText = null, ?string $ctaUrl = null): string
{
    $cta = '';
    if ($ctaText !== null && $ctaUrl !== null) {
        $cta = '<div style="text-align:center;margin:28px 0 4px;">'
            . '<a href="' . htmlspecialchars($ctaUrl, ENT_QUOTES) . '" style="display:inline-block;background:#00932e;color:#ffffff;text-decoration:none;font-weight:700;padding:14px 32px;border-radius:6px;font-size:15px;font-family:Segoe UI,Arial,sans-serif;">' . htmlspecialchars($ctaText, ENT_QUOTES) . '</a>'
            . '</div>'
            . '<p style="margin:10px 0 0;font-size:11px;color:#98a2b3;word-break:break-all;">Or paste this link into your browser: ' . htmlspecialchars($ctaUrl, ENT_QUOTES) . '</p>';
    }
    return '<!doctype html><html><body style="margin:0;padding:0;background:#f5f7fa;font-family:Segoe UI,Arial,sans-serif;color:#0f172a;">'
        . '<div style="max-width:560px;margin:0 auto;padding:32px 20px;">'
        . '<div style="background:#ffffff;border-radius:10px;overflow:hidden;box-shadow:0 10px 28px rgba(15,23,42,.08);">'
        . '<div style="background:#00932e;padding:24px 32px;">'
        . '<div style="color:#ffffff;font-weight:800;font-size:20px;letter-spacing:.02em;">ReSoK <span style="font-weight:500;opacity:.85;font-size:14px;">&middot; Respiratory Society of Kenya</span></div>'
        . '</div>'
        . '<div style="height:4px;background:#bc0b22;"></div>'
        . '<div style="padding:32px;">'
        . '<h1 style="margin:0 0 18px;font-size:21px;color:#0f172a;">' . htmlspecialchars($title, ENT_QUOTES) . '</h1>'
        . $bodyHtml
        . $cta
        . '</div>'
        . '<div style="background:#f7faf8;padding:16px 32px;color:#667085;font-size:12px;border-top:1px solid #e7ebef;">Respiratory Society of Kenya &middot; www.resok.org</div>'
        . '</div></div></body></html>';
}

function sendVerificationEmail(array $config, string $email, string $token): bool
{
    $baseUrl = rtrim((string)($config['portal_base_url'] ?? ''), '/');
    $url = $baseUrl !== '' ? $baseUrl . '/api/index.php?route=' . rawurlencode('auth/verify/' . $token) : '';
    $text = "Welcome to the ReSoK Members' Portal.\n\nPlease verify your email address to activate your account:\n{$url}\n\nIf you did not create this account, you can ignore this email.";
    $html = brandedEmailHtml(
        'Verify your email to activate your account',
        '<p style="margin:0 0 14px;font-size:15px;line-height:1.65;">Welcome to the ReSoK Members\' Portal. Please confirm your email address to activate your account and continue your membership application.</p>'
        . '<p style="margin:0;font-size:13px;line-height:1.6;color:#667085;">If you did not create this account, you can safely ignore this email.</p>',
        'Verify My Email',
        $url
    );
    $mailer = new SimpleMailer($config);
    return $mailer->send($email, 'Verify your ReSoK membership account', $text, [], $html);
}

// $error is filled in on failure so a caller can say why, rather than only that it did
// not work. An administrator waiting at a screen cannot read the server's error log.
function sendWelcomePacketEmail(array $config, array $member, ?string &$error = null): bool
{
    $error = null;
    $email = (string)($member['email'] ?? '');
    if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        $error = $email === ''
            ? 'This member has no email address on file.'
            : 'The address on file is not a valid email address: ' . $email;
        return false;
    }

    $greeting = portalGreetingName($member);
    $text = "Dear {$greeting},\n\nWelcome to the Respiratory Society of Kenya (ReSoK)!\n\nWe are pleased to confirm that your ReSoK membership has been successfully processed. Please find attached your official ReSoK Welcome Letter and Membership Card for your records.\n\nWe are delighted to have you join the ReSoK membership community and look forward to your engagement in advancing lung health in Kenya and beyond.\n\nWelcome to ReSoK!\n\nBest regards,\nReSoK Secretariat\nRespiratory Society of Kenya (ReSoK)";

    $attachments = [
        ['filename' => 'ReSoK-Welcome-Letter.pdf', 'content' => buildWelcomeLetterPdf($member), 'mime' => 'application/pdf'],
        ['filename' => 'ReSoK-Membership-Card.pdf', 'content' => buildMembershipCardPdf($member), 'mime' => 'application/pdf']
    ];

    $mailer = new SimpleMailer($config);
    // No HTML part. A plain-text message is what a letter of transmittal looks like in an
    // inbox, and it puts the two attachments immediately below the words rather than
    // below a branded banner, heading and footer.
    $sent = $mailer->send($email, 'Welcome to ReSoK – Membership Confirmation', $text, $attachments);
    if (!$sent) $error = $mailer->lastError ?? 'The mail server did not accept the message.';
    return $sent;
}

function sendRenewalReminderEmail(array $config, array $member, int $daysLeft): bool
{
    $email = (string)($member['email'] ?? '');
    if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) return false;

    $name = portalMemberName($member);
    $portal = rtrim((string)($config['portal_base_url'] ?? ''), '/') ?: 'https://www.resok.org/resok-portal/public';
    $due = (string)($member['renewalDue'] ?? 'soon');
    $text = "Dear {$name},\n\nYour ReSoK membership is due for renewal on {$due} ({$daysLeft} day(s) from now).\n\nRenew via M-Pesa in the member portal: {$portal}/payment\n\nRespiratory Society of Kenya";

    $html = brandedEmailHtml(
        "Your membership renewal is due in {$daysLeft} day(s)",
        '<p style="margin:0 0 14px;font-size:15px;line-height:1.65;">Dear ' . htmlspecialchars($name, ENT_QUOTES) . ',</p>'
        . '<p style="margin:0 0 14px;font-size:15px;line-height:1.65;">Your ReSoK membership is due for renewal on <strong>' . htmlspecialchars($due, ENT_QUOTES) . '</strong>. Renew now via M-Pesa in the member portal to keep your membership active without interruption.</p>',
        'Renew My Membership',
        $portal . '/payment'
    );

    $mailer = new SimpleMailer($config);
    return $mailer->send($email, "Your ReSoK membership renewal is due in {$daysLeft} day(s)", $text, [], $html);
}

/**
 * The one notice sent when a membership has actually lapsed.
 *
 * Sent once, after the grace period, and deliberately not written as a warning: by this
 * point the member has already had three reminders and a month of grace, so the useful
 * content is what has changed and what puts it back, not another countdown.
 *
 * It says what they keep as well as what they lose. A member who thinks their account is
 * gone does not come back; one who knows their record is intact and one payment away often
 * does.
 */
function sendMembershipLapsedEmail(array $config, array $member): bool
{
    $email = (string)($member['email'] ?? '');
    if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) return false;

    $name = portalMemberName($member);
    $portal = rtrim((string)($config['portal_base_url'] ?? ''), '/') ?: 'https://www.resok.org/resok-portal/public';
    $due = (string)($member['renewalDue'] ?? $member['renewal_due'] ?? '');

    $text = "Dear {$name},

"
          . "Your ReSoK membership has now lapsed" . ($due ? " - it was due for renewal on {$due}" : '') . ".

"
          . "Your account, your profile and your CPD record are all unchanged. What has paused are "
          . "the member benefits: your membership card, members-only courses and member rates at events.

"
          . "One payment restores everything: {$portal}/payment

"
          . "If you believe this is a mistake, or you have already paid, reply to this message and "
          . "we will put it right.

Respiratory Society of Kenya";

    $html = brandedEmailHtml(
        'Your ReSoK membership has lapsed',
        '<p style="margin:0 0 14px;font-size:15px;line-height:1.65;">Dear ' . htmlspecialchars($name, ENT_QUOTES) . ',</p>'
        . '<p style="margin:0 0 14px;font-size:15px;line-height:1.65;">Your ReSoK membership has now lapsed'
        . ($due ? ' &mdash; it was due for renewal on <strong>' . htmlspecialchars($due, ENT_QUOTES) . '</strong>' : '')
        . '.</p>'
        . '<p style="margin:0 0 14px;font-size:15px;line-height:1.65;">Your account, your profile and your CPD '
        . 'record are all unchanged. What has paused are the member benefits: your membership card, '
        . 'members-only courses, and member rates at events.</p>'
        . '<p style="margin:0 0 14px;font-size:15px;line-height:1.65;">One payment restores everything. If you '
        . 'believe this is a mistake, or you have already paid, reply to this message and we will put it right.</p>',
        'Renew My Membership',
        $portal . '/payment'
    );

    $mailer = new SimpleMailer($config);
    return $mailer->send($email, 'Your ReSoK membership has lapsed', $text, [], $html);
}

/**
 * The six-digit code that releases a CPD token.
 *
 * The token itself is deliberately not in this email. That is the whole point of the code:
 * what travels by mail expires in fifteen minutes, so a message that is forwarded, left in a
 * shared inbox, or read on a borrowed laptop is worth nothing afterwards. The token stays on
 * the server until someone proves they can open this mailbox.
 */
function sendTokenAccessCodeEmail(array $config, string $email, string $name, string $eventTitle, string $code): bool
{
    $text = "Hello {$name},\n\n"
          . "Your one-time code for collecting your CPD token for {$eventTitle} is:\n\n"
          . "    {$code}\n\n"
          . "Enter it on the event page to see your KMPDC token. The code expires in 15 minutes.\n\n"
          . "If you did not ask for this, you can ignore this email - nobody can collect your token without it.";

    $html = brandedEmailHtml(
        'Your one-time code',
        '<p style="margin:0 0 14px;font-size:15px;line-height:1.65;">Hello ' . htmlspecialchars($name, ENT_QUOTES) . ','
        . '</p><p style="margin:0 0 18px;font-size:15px;line-height:1.65;">Here is your code for collecting your CPD token for <strong>'
        . htmlspecialchars($eventTitle, ENT_QUOTES) . '</strong>:</p>'
        . '<div style="margin:0 0 18px;padding:18px;background:#f5f9f6;border:1px dashed #cde5d5;border-radius:10px;text-align:center;">'
        . '<span style="font-size:32px;font-weight:800;letter-spacing:10px;color:#0a2e38;font-family:monospace;">'
        . htmlspecialchars($code, ENT_QUOTES) . '</span></div>'
        . '<p style="margin:0 0 14px;font-size:14px;line-height:1.6;">Enter it on the event page to see your KMPDC token. It expires in 15 minutes.</p>'
        . '<p style="margin:0;font-size:13px;line-height:1.6;color:#667085;">If you did not ask for this, you can ignore this email &mdash; nobody can collect your token without this code.</p>'
    );

    $mailer = new SimpleMailer($config);
    return $mailer->send($email, 'Your code for ' . $eventTitle, $text, [], $html);
}
