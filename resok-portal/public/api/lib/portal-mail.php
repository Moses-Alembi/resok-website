<?php
declare(strict_types=1);

require_once __DIR__ . '/SimplePdf.php';
require_once __DIR__ . '/SimpleMailer.php';

function portalMemberName(array $member): string
{
    $parts = array_filter([$member['title'] ?? null, $member['firstName'] ?? null, $member['middleName'] ?? null, $member['surname'] ?? null]);
    $name = trim(preg_replace('/\s+/', ' ', implode(' ', $parts)));
    return $name !== '' ? $name : 'ReSoK Member';
}

function buildWelcomeLetterPdf(array $member): string
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

function sendWelcomePacketEmail(array $config, array $member): bool
{
    $email = (string)($member['email'] ?? '');
    if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) return false;

    $name = portalMemberName($member);
    $membershipId = (string)($member['membershipId'] ?? 'Pending');
    $portal = rtrim((string)($config['portal_base_url'] ?? ''), '/') ?: 'https://www.resok.org/resok-portal/public';
    $text = "Welcome to ReSoK, {$name}.\n\nYour membership (ID: {$membershipId}) is now active. Your welcome letter and digital membership card are attached to this email.\n\nManage your membership anytime at {$portal}.\n\nRespiratory Society of Kenya";

    $html = brandedEmailHtml(
        'Welcome to ReSoK Membership',
        '<p style="margin:0 0 14px;font-size:15px;line-height:1.65;">Dear ' . htmlspecialchars($name, ENT_QUOTES) . ',</p>'
        . '<p style="margin:0 0 14px;font-size:15px;line-height:1.65;">On behalf of the Respiratory Society of Kenya, congratulations &mdash; your membership is now <strong style="color:#00932e;">active</strong>.</p>'
        . '<div style="background:#f7faf8;border:1px solid rgba(0,147,46,.18);border-radius:8px;padding:16px 18px;margin:18px 0;"><div style="font-size:12px;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:#667085;margin-bottom:4px;">Membership ID</div><div style="font-size:20px;font-weight:800;color:#00932e;">' . htmlspecialchars($membershipId, ENT_QUOTES) . '</div></div>'
        . '<p style="margin:0;font-size:15px;line-height:1.65;">Your welcome letter and digital membership card are attached to this email as PDFs.</p>',
        'Go to My Portal',
        $portal
    );

    $attachments = [
        ['filename' => 'ReSoK-Welcome-Letter.pdf', 'content' => buildWelcomeLetterPdf($member), 'mime' => 'application/pdf'],
        ['filename' => 'ReSoK-Membership-Card.pdf', 'content' => buildMembershipCardPdf($member), 'mime' => 'application/pdf']
    ];

    $mailer = new SimpleMailer($config);
    return $mailer->send($email, 'Welcome to ReSoK Membership', $text, $attachments, $html);
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
