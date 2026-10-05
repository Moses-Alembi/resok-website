<?php
declare(strict_types=1);

/**
 * "Continue with Google" (ACC-3).
 *
 * The browser shows Google's own button (Google Identity Services). When someone picks their
 * account, Google hands the page a signed ID token, the page posts it to auth/google, and
 * this file decides whether to believe it. Nothing else from the browser is trusted: the
 * email address, the name and the "verified" flag are all read from inside the token.
 *
 * A token is believed only when all of these hold:
 *   - it is signed (RS256) by one of Google's current keys, fetched from Google and cached
 *     for as long as Google says they are valid;
 *   - it was issued by accounts.google.com for this site's client ID (the "aud" claim), so a
 *     token minted for some other website cannot be replayed here;
 *   - it has not expired, and Google says the email address is verified.
 *
 * No library is needed: openssl_verify does the signature check against the certificate.
 *
 * Off until google_client_id is set in config.
 */

const GOOGLE_CERTS_URL = 'https://www.googleapis.com/oauth2/v1/certs';

function googleClientId(array $config): string
{
    return trim((string)($config['google_client_id'] ?? ''));
}

function googleSignInEnabled(array $config): bool
{
    return googleClientId($config) !== '' && function_exists('openssl_verify');
}

function googleB64(string $part): string
{
    return (string)base64_decode(strtr($part, '-_', '+/') . str_repeat('=', (4 - strlen($part) % 4) % 4), true);
}

/** Fetches a URL, with curl when the host has it. @return array{0: ?string, 1: array<string,string>} [body, headers] */
function googleFetch(string $url): array
{
    $headers = [];
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$headers): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
                return strlen($line);
            },
        ]);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return [$status === 200 && is_string($body) ? $body : null, $headers];
    }
    $body = @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 8]]));
    foreach ($http_response_header ?? [] as $line) {
        $parts = explode(':', $line, 2);
        if (count($parts) === 2) $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
    }
    return [is_string($body) ? $body : null, $headers];
}

/**
 * Google's signing certificates by key ID. Cached in a file until the max-age Google sends;
 * a stale copy is used only if Google cannot be reached, because keys rotate slowly and an
 * outage at their end should not stop people signing in.
 *
 * @return array<string,string>
 */
function googleCerts(array $config, bool $refresh = false): array
{
    $cacheDir = rtrim((string)($config['upload_dir'] ?? sys_get_temp_dir()), '/\\') . '/.cache';
    $cacheFile = $cacheDir . '/google-certs.json';
    $cached = is_file($cacheFile) ? json_decode((string)file_get_contents($cacheFile), true) : null;
    if (!$refresh && is_array($cached) && ($cached['expires'] ?? 0) > time() && !empty($cached['certs'])) {
        return $cached['certs'];
    }
    [$body, $headers] = googleFetch(GOOGLE_CERTS_URL);
    $certs = $body !== null ? json_decode($body, true) : null;
    if (!is_array($certs) || !$certs) {
        error_log('Google sign-in: could not fetch signing certificates');
        return is_array($cached['certs'] ?? null) ? $cached['certs'] : [];
    }
    $maxAge = preg_match('/max-age=(\d+)/', $headers['cache-control'] ?? '', $m) ? (int)$m[1] : 3600;
    if (!is_dir($cacheDir)) @mkdir($cacheDir, 0755, true);
    @file_put_contents($cacheFile, json_encode(['expires' => time() + min($maxAge, 86400), 'certs' => $certs]));
    return $certs;
}

/**
 * The verified claims of a Google ID token, or null with the reason in $why.
 *
 * @return ?array{sub: string, email: string, given_name?: string, family_name?: string, name?: string}
 */
function googleVerifyIdToken(array $config, string $jwt, ?string &$why = null): ?array
{
    $why = null;
    $parts = explode('.', $jwt);
    if (count($parts) !== 3) { $why = 'malformed'; return null; }
    [$h, $p, $sig] = $parts;
    $header = json_decode(googleB64($h), true);
    $claims = json_decode(googleB64($p), true);
    $signature = googleB64($sig);
    if (!is_array($header) || !is_array($claims) || $signature === '') { $why = 'malformed'; return null; }
    if (($header['alg'] ?? '') !== 'RS256' || !is_string($header['kid'] ?? null)) { $why = 'unexpected algorithm'; return null; }

    $certs = googleCerts($config);
    // A key ID we have not seen means Google rotated keys since the cache was written.
    if (!isset($certs[$header['kid']])) $certs = googleCerts($config, true);
    $cert = $certs[$header['kid']] ?? null;
    if (!is_string($cert)) { $why = 'unknown signing key'; return null; }
    $key = openssl_pkey_get_public($cert);
    if ($key === false || openssl_verify($h . '.' . $p, $signature, $key, OPENSSL_ALGO_SHA256) !== 1) {
        $why = 'bad signature';
        return null;
    }

    $now = time();
    $skew = 300;
    if (!in_array($claims['iss'] ?? '', ['accounts.google.com', 'https://accounts.google.com'], true)) { $why = 'wrong issuer'; return null; }
    $aud = $claims['aud'] ?? null;
    if (!(is_string($aud) ? hash_equals(googleClientId($config), $aud) : false)) { $why = 'wrong audience'; return null; }
    if ((int)($claims['exp'] ?? 0) < $now - $skew) { $why = 'expired'; return null; }
    if ((int)($claims['iat'] ?? 0) > $now + $skew) { $why = 'issued in the future'; return null; }
    $verified = $claims['email_verified'] ?? false;
    if (!($verified === true || $verified === 'true')) { $why = 'email not verified'; return null; }
    $email = strtolower(trim((string)($claims['email'] ?? '')));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !is_string($claims['sub'] ?? null)) { $why = 'no email'; return null; }

    return [
        'sub' => $claims['sub'],
        'email' => $email,
        'given_name' => trim((string)($claims['given_name'] ?? '')),
        'family_name' => trim((string)($claims['family_name'] ?? '')),
        'name' => trim((string)($claims['name'] ?? '')),
    ];
}
