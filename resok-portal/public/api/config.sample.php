<?php
declare(strict_types=1);

return [
    'db_host' => 'localhost',
    'db_port' => 3306,
    'db_name' => 'resok_portal',
    'db_user' => 'resok_user',
    'db_pass' => 'replace_with_database_password',

    'jwt_secret' => 'replace_with_a_random_secret_of_at_least_32_characters',
    'require_email_verification' => true,

    // SMTP is optional: leave smtp_host blank to fall back to PHP's mail().
    'smtp_host' => '',
    'smtp_port' => 587,
    'smtp_user' => '',
    'smtp_pass' => '',

    // M-Pesa Daraja STK push (required for the payment flow to work; sandbox
    // credentials are free from developer.safaricom.co.ke for testing).
    'mpesa_env' => 'sandbox',
    'mpesa_consumer_key' => '',
    'mpesa_consumer_secret' => '',
    'mpesa_shortcode' => '',
    'mpesa_passkey' => '',
    'mpesa_callback_url' => 'https://www.resok.org/resok-portal/public/api/index.php?route=payments/mpesa/callback',

    'upload_dir' => __DIR__ . '/../../uploads',
    'max_file_size' => 5242880,

    'allow_approve_without_payment' => false,
    'setup_key' => '',
    // Set this and pass ?key=... to cron/renewal-reminders.php if the host only offers URL-triggered cron.
    'cron_secret' => '',
    'portal_base_url' => 'https://www.resok.org/resok-portal/public',
    'mail_from' => 'no-reply@example.org',

    'paybill_number' => '303030',
    'paybill_account' => '2038334878',
    'membership_fee' => 5000,
    'membership_categories' => [
        ['name' => 'Associate Membership', 'description' => 'For allied HCPs & students', 'amount' => 2000],
        ['name' => 'Ordinary Membership', 'description' => 'For individuals', 'amount' => 5000],
        ['name' => 'Benefactor Membership - Bronze', 'description' => 'For individuals', 'amount' => 20000],
        ['name' => 'Benefactor Membership - Silver', 'description' => 'For individuals', 'amount' => 30000],
        ['name' => 'Benefactor Membership - Gold', 'description' => 'For individuals', 'amount' => 50000],
        ['name' => 'Organization Membership', 'description' => 'For institutions/corporates', 'amount' => 50000],
    ],
];

