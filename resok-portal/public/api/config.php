<?php
declare(strict_types=1);

$localPath = __DIR__ . '/config.local.php';
$local = file_exists($localPath) ? require $localPath : [];

function config_value(array $local, string $key, ?string $env = null, $default = null) {
    if (array_key_exists($key, $local)) return $local[$key];
    $value = getenv($env ?: strtoupper($key));
    return $value === false ? $default : $value;
}

return [
    'db_host' => config_value($local, 'db_host', 'RESOK_DB_HOST', 'localhost'),
    'db_port' => (int)config_value($local, 'db_port', 'RESOK_DB_PORT', 3306),
    'db_name' => config_value($local, 'db_name', 'RESOK_DB_NAME', ''),
    'db_user' => config_value($local, 'db_user', 'RESOK_DB_USER', ''),
    'db_pass' => config_value($local, 'db_pass', 'RESOK_DB_PASS', ''),

    'jwt_secret' => config_value($local, 'jwt_secret', 'RESOK_JWT_SECRET', ''),
    'require_email_verification' => filter_var(config_value($local, 'require_email_verification', 'RESOK_REQUIRE_EMAIL_VERIFICATION', true), FILTER_VALIDATE_BOOLEAN),

    'smtp_host' => config_value($local, 'smtp_host', 'RESOK_SMTP_HOST', ''),
    'smtp_port' => (int)config_value($local, 'smtp_port', 'RESOK_SMTP_PORT', 587),
    'smtp_user' => config_value($local, 'smtp_user', 'RESOK_SMTP_USER', ''),
    'smtp_pass' => config_value($local, 'smtp_pass', 'RESOK_SMTP_PASS', ''),

    'mpesa_env' => config_value($local, 'mpesa_env', 'RESOK_MPESA_ENV', 'sandbox'),
    'mpesa_consumer_key' => config_value($local, 'mpesa_consumer_key', 'RESOK_MPESA_CONSUMER_KEY', ''),
    'mpesa_consumer_secret' => config_value($local, 'mpesa_consumer_secret', 'RESOK_MPESA_CONSUMER_SECRET', ''),
    'mpesa_shortcode' => config_value($local, 'mpesa_shortcode', 'RESOK_MPESA_SHORTCODE', ''),
    'mpesa_passkey' => config_value($local, 'mpesa_passkey', 'RESOK_MPESA_PASSKEY', ''),
    'mpesa_callback_url' => config_value($local, 'mpesa_callback_url', 'RESOK_MPESA_CALLBACK_URL', ''),

    'upload_dir' => config_value($local, 'upload_dir', 'RESOK_UPLOAD_DIR', __DIR__ . '/../../uploads'),
    'max_file_size' => (int)config_value($local, 'max_file_size', 'RESOK_MAX_FILE_SIZE', 5242880),

    'allow_approve_without_payment' => filter_var(config_value($local, 'allow_approve_without_payment', 'RESOK_ALLOW_APPROVE_WITHOUT_PAYMENT', false), FILTER_VALIDATE_BOOLEAN),
    'setup_key' => config_value($local, 'setup_key', 'RESOK_SETUP_KEY', ''),
    'cron_secret' => config_value($local, 'cron_secret', 'RESOK_CRON_SECRET', ''),
    'portal_base_url' => rtrim((string)config_value($local, 'portal_base_url', 'RESOK_PORTAL_BASE_URL', ''), '/'),
    'mail_from' => config_value($local, 'mail_from', 'RESOK_MAIL_FROM', ''),

    'paybill_number' => config_value($local, 'paybill_number', 'RESOK_PAYBILL_NUMBER', '303030'),
    'paybill_account' => config_value($local, 'paybill_account', 'RESOK_PAYBILL_ACCOUNT', '2038334878'),
    'membership_fee' => (int)config_value($local, 'membership_fee', 'RESOK_MEMBERSHIP_FEE', 5000),
    'membership_categories' => config_value($local, 'membership_categories', 'RESOK_MEMBERSHIP_CATEGORIES', [
        ['name' => 'Associate Membership', 'description' => 'For allied HCPs & students', 'amount' => 2000],
        ['name' => 'Ordinary Membership', 'description' => 'For individuals', 'amount' => 5000],
        ['name' => 'Benefactor Membership - Bronze', 'description' => 'For individuals', 'amount' => 20000],
        ['name' => 'Benefactor Membership - Silver', 'description' => 'For individuals', 'amount' => 30000],
        ['name' => 'Benefactor Membership - Gold', 'description' => 'For individuals', 'amount' => 50000],
        ['name' => 'Organization Membership', 'description' => 'For institutions/corporates', 'amount' => 50000],
    ]),
];

