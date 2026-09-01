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

function sendVerificationEmail(array $config, string $email, string $token): bool
{
    $baseUrl = rtrim((string)($config['portal_base_url'] ?? ''), '/');
    $url = $baseUrl !== '' ? $baseUrl . '/api/index.php?route=' . rawurlencode('auth/verify/' . $token) : '';
    $text = "Welcome to the ReSoK Members' Portal.\n\nPlease verify your email address to activate your account:\n{$url}\n\nIf you did not create this account, you can ignore this email.";
    $mailer = new SimpleMailer($config);
    return $mailer->send($email, 'Verify your ReSoK membership account', $text);
}

function sendWelcomePacketEmail(array $config, array $member): bool
{
    $email = (string)($member['email'] ?? '');
    if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) return false;

    $name = portalMemberName($member);
    $membershipId = $member['membershipId'] ?? 'Pending';
    $portal = rtrim((string)($config['portal_base_url'] ?? ''), '/') ?: 'https://www.resok.org/resok-portal/public';
    $text = "Welcome to ReSoK, {$name}.\n\nYour membership (ID: {$membershipId}) is now active. Your welcome letter and digital membership card are attached to this email.\n\nManage your membership anytime at {$portal}.\n\nRespiratory Society of Kenya";

    $attachments = [
        ['filename' => 'ReSoK-Welcome-Letter.pdf', 'content' => buildWelcomeLetterPdf($member), 'mime' => 'application/pdf'],
        ['filename' => 'ReSoK-Membership-Card.pdf', 'content' => buildMembershipCardPdf($member), 'mime' => 'application/pdf']
    ];

    $mailer = new SimpleMailer($config);
    return $mailer->send($email, 'Welcome to ReSoK Membership', $text, $attachments);
}

function sendRenewalReminderEmail(array $config, array $member, int $daysLeft): bool
{
    $email = (string)($member['email'] ?? '');
    if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) return false;

    $name = portalMemberName($member);
    $portal = rtrim((string)($config['portal_base_url'] ?? ''), '/') ?: 'https://www.resok.org/resok-portal/public';
    $due = $member['renewalDue'] ?? 'soon';
    $text = "Dear {$name},\n\nYour ReSoK membership is due for renewal on {$due} ({$daysLeft} day(s) from now).\n\nRenew via M-Pesa in the member portal: {$portal}/payment\n\nRespiratory Society of Kenya";

    $mailer = new SimpleMailer($config);
    return $mailer->send($email, "Your ReSoK membership renewal is due in {$daysLeft} day(s)", $text);
}
