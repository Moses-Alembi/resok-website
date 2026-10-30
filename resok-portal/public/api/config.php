<?php
declare(strict_types=1);

$localPath = __DIR__ . '/config.local.php';
$local = file_exists($localPath) ? require $localPath : [];

function config_value(array $local, string $key, ?string $env = null, $default = null) {
    if (array_key_exists($key, $local)) return $local[$key];
    $value = getenv($env ?: strtoupper($key));
    return $value === false ? $default : $value;
}

/** A list setting: an array in config.local.php, or a comma-separated environment variable. */
function config_list(array $local, string $key, string $env): array
{
    $value = config_value($local, $key, $env, []);
    if (!is_array($value)) $value = explode(',', (string)$value);
    return array_values(array_filter(array_map('trim', $value)));
}

return [
    'db_host' => config_value($local, 'db_host', 'RESOK_DB_HOST', 'localhost'),
    'db_port' => (int)config_value($local, 'db_port', 'RESOK_DB_PORT', 3306),
    'db_name' => config_value($local, 'db_name', 'RESOK_DB_NAME', ''),
    'db_user' => config_value($local, 'db_user', 'RESOK_DB_USER', ''),
    'db_pass' => config_value($local, 'db_pass', 'RESOK_DB_PASS', ''),

    'jwt_secret' => config_value($local, 'jwt_secret', 'RESOK_JWT_SECRET', ''),
    // The key before a rotation, still accepted for a week so nobody is signed out
    // (tokenSignedWith() in index.php). Empty except while rotating.
    'jwt_secret_previous' => config_list($local, 'jwt_secret_previous', 'RESOK_JWT_SECRET_PREVIOUS'),
    'require_email_verification' => filter_var(config_value($local, 'require_email_verification', 'RESOK_REQUIRE_EMAIL_VERIFICATION', true), FILTER_VALIDATE_BOOLEAN),

    'smtp_host' => config_value($local, 'smtp_host', 'RESOK_SMTP_HOST', ''),
    'smtp_port' => (int)config_value($local, 'smtp_port', 'RESOK_SMTP_PORT', 587),
    'smtp_user' => config_value($local, 'smtp_user', 'RESOK_SMTP_USER', ''),
    'smtp_pass' => config_value($local, 'smtp_pass', 'RESOK_SMTP_PASS', ''),

    // Master switch for offering STK push in the portal. Off until a real payment has
    // been tested end to end - having credentials is not the same as being ready.
    'mpesa_enabled' => config_value($local, 'mpesa_enabled', 'RESOK_MPESA_ENABLED', false),
    // Go-live switch for STK push ("Pay Now" on the payment page). Off unless set: the STK
    // code is deployed but members only see the paybill until this AND mpesa_enabled are on.
    'stk_push_enabled' => config_value($local, 'stk_push_enabled', 'RESOK_STK_PUSH_ENABLED', false),
    'mpesa_env' => config_value($local, 'mpesa_env', 'RESOK_MPESA_ENV', 'sandbox'),
    'mpesa_consumer_key' => config_value($local, 'mpesa_consumer_key', 'RESOK_MPESA_CONSUMER_KEY', ''),
    'mpesa_consumer_secret' => config_value($local, 'mpesa_consumer_secret', 'RESOK_MPESA_CONSUMER_SECRET', ''),
    'mpesa_shortcode' => config_value($local, 'mpesa_shortcode', 'RESOK_MPESA_SHORTCODE', ''),
    'mpesa_passkey' => config_value($local, 'mpesa_passkey', 'RESOK_MPESA_PASSKEY', ''),
    'mpesa_callback_url' => config_value($local, 'mpesa_callback_url', 'RESOK_MPESA_CALLBACK_URL', ''),
    // Optional. When set, the callback must arrive with &key=<this> - append it to
    // mpesa_callback_url. A second lock on top of the STK status query.
    'mpesa_callback_secret' => config_value($local, 'mpesa_callback_secret', 'RESOK_MPESA_CALLBACK_SECRET', ''),

    'upload_dir' => config_value($local, 'upload_dir', 'RESOK_UPLOAD_DIR', __DIR__ . '/../../uploads'),
    'max_file_size' => (int)config_value($local, 'max_file_size', 'RESOK_MAX_FILE_SIZE', 5242880),

    'allow_approve_without_payment' => filter_var(config_value($local, 'allow_approve_without_payment', 'RESOK_ALLOW_APPROVE_WITHOUT_PAYMENT', false), FILTER_VALIDATE_BOOLEAN),
    'setup_key' => config_value($local, 'setup_key', 'RESOK_SETUP_KEY', ''),
    // Staff accounts (admin, ict, content and analytics managers) must set up two-factor
    // before they can reach anything; until then a sign-in reaches only the setup page.
    // On by default. Turning it off takes server access, which is the point: it is the
    // way back in if every admin is somehow stuck, not a setting for everyday use.
    'require_staff_mfa' => filter_var(config_value($local, 'require_staff_mfa', 'RESOK_REQUIRE_STAFF_MFA', true), FILTER_VALIDATE_BOOLEAN),
    // Encrypts ID numbers and two-factor secrets at rest. Deliberately NOT derived from
    // jwt_secret: rotating the session key is routine, and would otherwise destroy this
    // data permanently. Unset means nothing is encrypted - which is the safe default,
    // because encrypting under a key nobody has backed up is worse than not encrypting.
    'data_encryption_key' => config_value($local, 'data_encryption_key', 'RESOK_DATA_KEY', ''),
    // Emails of super administrators - the only accounts that may see the threat assessment
    // and change who is an admin. Stored here rather than in the database so it takes server
    // access to change, and so it needs no schema migration. Comma-separated in the env var.
    'super_admins' => config_list($local, 'super_admins', 'RESOK_SUPER_ADMINS'),
    'cron_secret' => config_value($local, 'cron_secret', 'RESOK_CRON_SECRET', ''),
    // Abstract emails sent per hour (lib/abstracts.php). Keep it under the host's own limit.
    // National ILD MDT case submission (lib/mdt.php): off until launch; case files are kept
    // this many months after a case closes.
    'mdt_enabled' => filter_var(config_value($local, 'mdt_enabled', 'RESOK_MDT_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
    // Staff recruitment (lib/careers.php): unsuccessful candidates' data is deleted this many
    // months after a vacancy closes.
    'careers_retention_months' => (int)config_value($local, 'careers_retention_months', 'RESOK_CAREERS_RETENTION_MONTHS', 6),
    'mdt_retention_months' => (int)config_value($local, 'mdt_retention_months', 'RESOK_MDT_RETENTION_MONTHS', 24),
    'abstracts_mail_hourly_limit' => (int)config_value($local, 'abstracts_mail_hourly_limit', 'RESOK_ABSTRACTS_MAIL_HOURLY_LIMIT', 150),
    // "Continue with Google" (lib/google-signin.php). The OAuth client ID of a Web application
    // in Google Cloud Console; empty keeps the button hidden everywhere.
    'google_client_id' => config_value($local, 'google_client_id', 'RESOK_GOOGLE_CLIENT_ID', ''),
    'portal_base_url' => rtrim((string)config_value($local, 'portal_base_url', 'RESOK_PORTAL_BASE_URL', ''), '/'),
    'mail_from' => config_value($local, 'mail_from', 'RESOK_MAIL_FROM', ''),

    // Who receives the 8-hourly security report (cron/security-digest.php): an address or a
    // list. Unset means the script's own default; '' stops the emails.
    'security_digest_to' => config_value($local, 'security_digest_to', 'RESOK_SECURITY_DIGEST_TO', null),
    // Proxy ranges, beyond Cloudflare's, whose forwarded visitor address may be believed.
    'trusted_proxy_ranges' => config_list($local, 'trusted_proxy_ranges', 'RESOK_TRUSTED_PROXY_RANGES'),
    // Staff sign-in rules (lib/staff-geo.php). edge_secret must equal the X-Resok-Edge header
    // Cloudflare's Transform Rule adds; once set, staff requests without it are refused.
    // staff_geo => 'off' is the break-glass switch for the location and working-hours rules.
    'edge_secret' => (string)config_value($local, 'edge_secret', 'RESOK_EDGE_SECRET', ''),
    'staff_geo' => (string)config_value($local, 'staff_geo', 'RESOK_STAFF_GEO', ''),
    // "Verifying your browser" before sign-in, sign-up and the public forms (lib/proof.php).
    // Off until switched on. Bits: each extra one doubles the work; 16 is about a second on
    // a mid-range phone.
    'proof_of_work' => filter_var(config_value($local, 'proof_of_work', 'RESOK_PROOF_OF_WORK', false), FILTER_VALIDATE_BOOLEAN),
    'proof_of_work_bits' => (int)config_value($local, 'proof_of_work_bits', 'RESOK_PROOF_OF_WORK_BITS', 16),

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

