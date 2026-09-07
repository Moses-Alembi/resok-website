<?php
declare(strict_types=1);

/**
 * Encryption at rest for the few fields that are sensitive but must be read back.
 *
 * Passwords are hashed and never decrypted. National ID numbers and two-factor secrets
 * cannot be: an ID number is shown to admins reviewing an application, and a TOTP secret
 * has to be compared against a code every time someone signs in. So they need reversible
 * encryption, which means a key, which means the key becomes the thing that matters.
 *
 * Three decisions follow from that:
 *
 * 1. The key is its OWN config value, never derived from jwt_secret. Rotating the session
 *    key is a routine, safe thing to do; if ID numbers were encrypted with it, that routine
 *    act would silently destroy them.
 *
 * 2. With no key configured, nothing is encrypted and values are stored exactly as before.
 *    Encrypting with a key that has not been deliberately set and backed up is worse than
 *    not encrypting - it converts readable data into data nobody can recover. The threat
 *    assessment reports the missing key instead.
 *
 * 3. Reads accept both forms. Ciphertext carries a marker, so existing plaintext rows keep
 *    working and can be migrated whenever, rather than needing a flag day.
 *
 * AES-256-GCM: authenticated, so a tampered-with value is rejected rather than decrypting
 * to something plausible.
 */

const CRYPTO_MARKER = 'enc.v1.';

/**
 * Holds the config for callers too deep to receive it - mapMember() is shaped by the
 * database row alone, and threading config through every call site to decrypt one field
 * would touch far more code than the change is worth.
 */
function cryptoConfig(?array $set = null): array
{
    static $config = [];
    if ($set !== null) $config = $set;
    return $config;
}

function cryptoKey(array $config): ?string
{
    $key = (string)($config['data_encryption_key'] ?? '');
    // A short key is a typo or a placeholder, not a decision. Treated as absent so it fails
    // safe rather than encrypting everything under something guessable.
    if (strlen($key) < 32) return null;
    // Normalised to 32 bytes; the config value is a passphrase, not raw key material.
    return hash('sha256', $key, true);
}

function cryptoAvailable(array $config): bool
{
    return cryptoKey($config) !== null && function_exists('openssl_encrypt');
}

/** Returns the value unchanged when no key is configured, so callers need no branching. */
function cryptoEncrypt(array $config, ?string $plain): ?string
{
    if ($plain === null || $plain === '') return $plain;
    $key = cryptoKey($config);
    if ($key === null || !function_exists('openssl_encrypt')) return $plain;
    if (cryptoIsEncrypted($plain)) return $plain;   // never double-encrypt

    $iv = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    if ($cipher === false) {
        error_log('cryptoEncrypt failed; storing the value unencrypted rather than losing it.');
        return $plain;
    }
    return CRYPTO_MARKER . base64_encode($iv . $tag . $cipher);
}

function cryptoIsEncrypted(?string $value): bool
{
    return is_string($value) && strncmp($value, CRYPTO_MARKER, strlen(CRYPTO_MARKER)) === 0;
}

/**
 * Decrypts if the value carries the marker, otherwise returns it as-is - which is what lets
 * rows written before the key existed keep working.
 */
function cryptoDecrypt(array $config, ?string $stored): ?string
{
    if ($stored === null || !cryptoIsEncrypted($stored)) return $stored;

    $key = cryptoKey($config);
    if ($key === null || !function_exists('openssl_decrypt')) {
        // The key was removed or changed after this row was written. Say so rather than
        // returning ciphertext that would then be displayed as if it were an ID number.
        error_log('cryptoDecrypt: encrypted value found but no usable key is configured.');
        return null;
    }

    $raw = base64_decode(substr($stored, strlen(CRYPTO_MARKER)), true);
    if ($raw === false || strlen($raw) < 29) return null;

    $iv = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $cipher = substr($raw, 28);
    $plain = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    if ($plain === false) {
        error_log('cryptoDecrypt: value failed authentication - wrong key, or it was altered.');
        return null;
    }
    return $plain;
}

/**
 * Encrypts any plaintext rows in one column, in batches. Idempotent: rows already carrying
 * the marker are skipped, so it can be run again safely and stopped part way without
 * leaving the table in a state that needs untangling.
 *
 * @return array{scanned:int,encrypted:int}
 */
function cryptoMigrateColumn(PDO $pdo, array $config, string $table, string $idColumn, string $column, int $limit = 500): array
{
    if (!cryptoAvailable($config)) {
        throw new RuntimeException('No data_encryption_key is configured, so there is nothing to migrate to.');
    }
    // Table and column names cannot be bound as parameters, so they are restricted to a
    // known-safe shape rather than trusted.
    foreach ([$table, $idColumn, $column] as $identifier) {
        if (!preg_match('/^[a-z_][a-z0-9_]*$/i', $identifier)) {
            throw new RuntimeException('Refusing an unexpected table or column name.');
        }
    }

    $rows = $pdo->query("SELECT {$idColumn} AS id, {$column} AS value FROM {$table}
                          WHERE {$column} IS NOT NULL AND {$column} <> ''
                            AND {$column} NOT LIKE '" . CRYPTO_MARKER . "%'
                          LIMIT " . (int)$limit)->fetchAll();

    $update = $pdo->prepare("UPDATE {$table} SET {$column} = ? WHERE {$idColumn} = ?");
    $encrypted = 0;
    foreach ($rows as $row) {
        $cipher = cryptoEncrypt($config, (string)$row['value']);
        if (cryptoIsEncrypted($cipher)) {
            $update->execute([$cipher, $row['id']]);
            $encrypted++;
        }
    }
    return ['scanned' => count($rows), 'encrypted' => $encrypted];
}
