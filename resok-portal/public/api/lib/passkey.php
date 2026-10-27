<?php
declare(strict_types=1);

/**
 * Passkeys (WebAuthn) as a second factor: face or fingerprint on the person's own phone or
 * laptop instead of typing the six-digit code.
 *
 * What the portal never sees is the face or the fingerprint. The device matches it inside
 * its own secure hardware and then signs a one-time challenge with a key that never leaves
 * it; the portal checks that signature against the public key it stored when the passkey
 * was added. So there is no biometric data here to protect, and a signature made for any
 * other website - a phishing copy of resok.org included - fails the check.
 *
 * Rules:
 *
 * 1. A passkey stands in for the code, not for the password. The password is still the first
 *    step, and passkeys can only be added to an account that already has two-factor on, so
 *    the authenticator app and the recovery codes remain the way back in.
 *
 * 2. Adding one needs a current code from the authenticator app. Otherwise anyone holding a
 *    stolen session could add their own passkey and walk past two-factor from then on.
 *    Removing one needs the password, as turning two-factor off does.
 *
 * 3. User verification is required. The device must have checked the face, fingerprint or
 *    device PIN at that moment (the UV flag), not merely that a device is present.
 *
 * 4. Challenges are random, single use and short lived, kept in the database.
 *
 * Supported keys: ES256 (what phones and Windows Hello make) and RS256. Attestation is not
 * requested; what is verified is possession of the key, every time.
 */

const PASSKEY_CHALLENGE_SECONDS = 180;
const PASSKEY_ALG_ES256 = -7;
const PASSKEY_ALG_RS256 = -257;

function passkeyEnsureTables(PDO $pdo): bool
{
    static $ready = null;
    if ($ready !== null) return $ready;
    try {
        $tail = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        $pdo->exec("CREATE TABLE IF NOT EXISTS user_passkeys (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id INT UNSIGNED NOT NULL,
            credential_id VARCHAR(255) NOT NULL,
            public_key TEXT NOT NULL,
            alg INT NOT NULL,
            sign_count INT UNSIGNED NOT NULL DEFAULT 0,
            name VARCHAR(80) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_used_at DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY user_passkeys_credential (credential_id),
            KEY user_passkeys_user (user_id)
        )" . $tail);
        $pdo->exec("CREATE TABLE IF NOT EXISTS webauthn_challenges (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            challenge CHAR(43) NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            purpose ENUM('register','login') NOT NULL,
            expires_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY webauthn_challenges_value (challenge),
            KEY webauthn_challenges_expiry (expires_at)
        )" . $tail);
        return $ready = true;
    } catch (Throwable $e) {
        error_log('Passkey tables unavailable: ' . $e->getMessage());
        return $ready = false;
    }
}

// b64url() comes from index.php; the decoder is this file's own.
function b64urlDecode(string $text): ?string
{
    if ($text === '' || !preg_match('/^[A-Za-z0-9_-]+={0,2}$/', $text)) return null;
    $out = base64_decode(strtr($text, '-_', '+/') . str_repeat('=', (4 - strlen($text) % 4) % 4), true);
    return $out === false ? null : $out;
}

/**
 * The relying party: the registrable domain the passkeys are bound to. "resok.org" covers
 * both resok.org and www.resok.org; on the laptop it is localhost.
 */
function passkeyRpId(array $config): string
{
    $host = strtolower((string)parse_url((string)($config['portal_base_url'] ?? ''), PHP_URL_HOST));
    if ($host === '') $host = strtolower((string)preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? 'localhost')));
    return (string)preg_replace('/^www\./', '', $host);
}

/** The browser's origin must be this site: https on the rp domain or a subdomain of it (http only on localhost). */
function passkeyOriginOk(array $config, string $origin): bool
{
    $p = parse_url($origin);
    if (!$p || empty($p['host'])) return false;
    $rp = passkeyRpId($config);
    $host = strtolower($p['host']);
    if ($host !== $rp && !str_ends_with($host, '.' . $rp)) return false;
    return ($p['scheme'] ?? '') === 'https' || ($rp === 'localhost' && ($p['scheme'] ?? '') === 'http');
}

function passkeyIssueChallenge(PDO $pdo, int $userId, string $purpose): string
{
    $pdo->exec('DELETE FROM webauthn_challenges WHERE expires_at < NOW()');
    $challenge = b64url(random_bytes(32));
    $pdo->prepare('INSERT INTO webauthn_challenges (challenge, user_id, purpose, expires_at) VALUES (?, ?, ?, NOW() + INTERVAL ? SECOND)')
        ->execute([$challenge, $userId, $purpose, PASSKEY_CHALLENGE_SECONDS]);
    return $challenge;
}

/** Spends a challenge: true once, for the user and purpose it was issued to, before it expires. */
function passkeyTakeChallenge(PDO $pdo, string $challenge, int $userId, string $purpose): bool
{
    $stmt = $pdo->prepare('DELETE FROM webauthn_challenges WHERE challenge = ? AND user_id = ? AND purpose = ? AND expires_at >= NOW()');
    $stmt->execute([$challenge, $userId, $purpose]);
    return $stmt->rowCount() === 1;
}

// ---------------------------------------------------------------------------------------
// CBOR, enough for an attestation object and a COSE key
// ---------------------------------------------------------------------------------------

/** @return mixed */
function cborDecode(string $data, int &$pos = 0, int $depth = 0)
{
    if ($depth > 16 || $pos >= strlen($data)) throw new RuntimeException('Bad CBOR');
    $initial = ord($data[$pos++]);
    $major = $initial >> 5;
    $info = $initial & 0x1f;
    if ($info < 24) $len = $info;
    elseif ($info >= 24 && $info <= 27) {
        $n = 1 << ($info - 24);
        if ($pos + $n > strlen($data)) throw new RuntimeException('Bad CBOR');
        $len = 0;
        for ($i = 0; $i < $n; $i++) $len = ($len << 8) | ord($data[$pos++]);
    } else {
        throw new RuntimeException('Unsupported CBOR');
    }
    switch ($major) {
        case 0: return $len;
        case 1: return -1 - $len;
        case 2:
        case 3:
            if ($pos + $len > strlen($data)) throw new RuntimeException('Bad CBOR');
            $s = substr($data, $pos, $len); $pos += $len; return $s;
        case 4:
            $out = [];
            for ($i = 0; $i < $len; $i++) $out[] = cborDecode($data, $pos, $depth + 1);
            return $out;
        case 5:
            $out = [];
            for ($i = 0; $i < $len; $i++) { $k = cborDecode($data, $pos, $depth + 1); $out[$k] = cborDecode($data, $pos, $depth + 1); }
            return $out;
        case 7:
            if ($info === 20) return false;
            if ($info === 21) return true;
            if ($info === 22) return null;
            throw new RuntimeException('Unsupported CBOR');
    }
    throw new RuntimeException('Unsupported CBOR');
}

function derLength(int $n): string
{
    if ($n < 128) return chr($n);
    $b = ltrim(pack('N', $n), "\0");
    return chr(0x80 | strlen($b)) . $b;
}
function derInt(string $bytes): string
{
    $bytes = ltrim($bytes, "\0");
    if ($bytes === '' || (ord($bytes[0]) & 0x80)) $bytes = "\0" . $bytes;
    return "\x02" . derLength(strlen($bytes)) . $bytes;
}

/** A COSE public key as PEM, with its algorithm. @return array{0:string,1:int} */
function coseToPem(array $cose): array
{
    $alg = $cose[3] ?? null;
    if ($alg === PASSKEY_ALG_ES256 && ($cose[1] ?? null) === 2 && ($cose[-1] ?? null) === 1
        && strlen((string)($cose[-2] ?? '')) === 32 && strlen((string)($cose[-3] ?? '')) === 32) {
        $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . "\x04" . $cose[-2] . $cose[-3];
    } elseif ($alg === PASSKEY_ALG_RS256 && ($cose[1] ?? null) === 3 && is_string($cose[-1] ?? null) && is_string($cose[-2] ?? null)) {
        $rsa = derInt($cose[-1]) . derInt($cose[-2]);
        $rsa = "\x30" . derLength(strlen($rsa)) . $rsa;
        $bits = "\x03" . derLength(strlen($rsa) + 1) . "\x00" . $rsa;
        $algId = hex2bin('300d06092a864886f70d0101010500');
        $der = "\x30" . derLength(strlen($algId . $bits)) . $algId . $bits;
    } else {
        throw new RuntimeException('Unsupported key type');
    }
    return ["-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n", (int)$alg];
}

/** Splits authenticator data into what is checked. */
function passkeyAuthData(string $auth): array
{
    if (strlen($auth) < 37) throw new RuntimeException('Short authenticator data');
    return ['rpIdHash' => substr($auth, 0, 32), 'flags' => ord($auth[32]), 'signCount' => unpack('N', substr($auth, 33, 4))[1], 'rest' => substr($auth, 37)];
}

/** Checks the client data every ceremony shares. */
function passkeyClientData(array $config, string $json, string $type): ?array
{
    $c = json_decode($json, true);
    if (!is_array($c) || ($c['type'] ?? '') !== $type) return null;
    if (!passkeyOriginOk($config, (string)($c['origin'] ?? ''))) return null;
    return $c;
}

// ---------------------------------------------------------------------------------------
// Ceremonies
// ---------------------------------------------------------------------------------------

/**
 * Verifies a registration response and stores the passkey.
 * @return array{0:?array,1:?string}
 */
function passkeyRegister(PDO $pdo, array $config, int $userId, array $cred, string $name): array
{
    $clientJson = b64urlDecode((string)($cred['clientDataJSON'] ?? '')) ?? '';
    $client = passkeyClientData($config, $clientJson, 'webauthn.create');
    if (!$client) return [null, 'This passkey was not made for this site.'];
    if (!passkeyTakeChallenge($pdo, (string)($client['challenge'] ?? ''), $userId, 'register')) {
        return [null, 'That setup took too long or was already used. Try again.'];
    }
    try {
        $pos = 0;
        $att = cborDecode(b64urlDecode((string)($cred['attestationObject'] ?? '')) ?? '', $pos);
        $auth = passkeyAuthData((string)($att['authData'] ?? ''));
        if (!hash_equals(hash('sha256', passkeyRpId($config), true), $auth['rpIdHash'])) return [null, 'This passkey was not made for this site.'];
        if (!($auth['flags'] & 0x01) || !($auth['flags'] & 0x04)) return [null, 'Your device did not confirm it was you. Use your face, fingerprint or device PIN.'];
        if (!($auth['flags'] & 0x40)) return [null, 'Your device did not return a key.'];
        $rest = $auth['rest'];
        $idLen = unpack('n', substr($rest, 16, 2))[1];
        $credId = substr($rest, 18, $idLen);
        if ($idLen < 16 || $idLen > 1023 || strlen($credId) !== $idLen) return [null, 'Your device returned a key that could not be read.'];
        $p = 0;
        $cose = cborDecode(substr($rest, 18 + $idLen), $p);
        [$pem, $alg] = coseToPem(is_array($cose) ? $cose : []);
        if (openssl_pkey_get_public($pem) === false) return [null, 'Your device returned a key that could not be read.'];
    } catch (Throwable $e) {
        return [null, 'Your device returned something that could not be read. Try again.'];
    }
    $idText = b64url($credId);
    if (strlen($idText) > 255) return [null, 'Your device returned a key that could not be read.'];
    try {
        $pdo->prepare('INSERT INTO user_passkeys (user_id, credential_id, public_key, alg, sign_count, name) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$userId, $idText, $pem, $alg, $auth['signCount'], $name]);
    } catch (Throwable $e) {
        return [null, 'That passkey is already added.'];
    }
    return [['id' => (int)$pdo->lastInsertId(), 'name' => $name], null];
}

/**
 * Verifies a sign-in assertion for this user. Returns the passkey's name, or null.
 */
function passkeyVerifyAssertion(PDO $pdo, array $config, int $userId, array $cred, ?string &$why = null): ?string
{
    $why = null;
    $clientJson = b64urlDecode((string)($cred['clientDataJSON'] ?? '')) ?? '';
    $client = passkeyClientData($config, $clientJson, 'webauthn.get');
    if (!$client) { $why = 'origin'; return null; }
    if (!passkeyTakeChallenge($pdo, (string)($client['challenge'] ?? ''), $userId, 'login')) { $why = 'challenge'; return null; }
    $stmt = $pdo->prepare('SELECT * FROM user_passkeys WHERE credential_id = ? AND user_id = ? LIMIT 1');
    $stmt->execute([(string)($cred['id'] ?? ''), $userId]);
    $row = $stmt->fetch();
    if (!$row) { $why = 'unknown key'; return null; }
    $authRaw = b64urlDecode((string)($cred['authenticatorData'] ?? '')) ?? '';
    $sig = b64urlDecode((string)($cred['signature'] ?? '')) ?? '';
    try { $auth = passkeyAuthData($authRaw); } catch (Throwable $e) { $why = 'auth data'; return null; }
    if (!hash_equals(hash('sha256', passkeyRpId($config), true), $auth['rpIdHash'])) { $why = 'rp'; return null; }
    if (!($auth['flags'] & 0x01) || !($auth['flags'] & 0x04)) { $why = 'not verified'; return null; }
    $ok = openssl_verify($authRaw . hash('sha256', $clientJson, true), $sig, (string)$row['public_key'], OPENSSL_ALGO_SHA256);
    if ($ok !== 1) { $why = 'signature'; return null; }
    // A counter that goes backwards means a copied key. Devices that keep no counter send 0.
    $stored = (int)$row['sign_count'];
    if (($stored > 0 || $auth['signCount'] > 0) && $auth['signCount'] <= $stored) { $why = 'counter'; return null; }
    $pdo->prepare('UPDATE user_passkeys SET sign_count = ?, last_used_at = NOW() WHERE id = ?')->execute([$auth['signCount'], (int)$row['id']]);
    return (string)$row['name'];
}

function passkeyList(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare('SELECT id, name, created_at, last_used_at FROM user_passkeys WHERE user_id = ? ORDER BY id');
    $stmt->execute([$userId]);
    return array_map(static fn($r) => ['id' => (int)$r['id'], 'name' => $r['name'], 'createdAt' => $r['created_at'], 'lastUsedAt' => $r['last_used_at']], $stmt->fetchAll());
}

function passkeyCount(PDO $pdo, int $userId): int
{
    if (!passkeyEnsureTables($pdo)) return 0;
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM user_passkeys WHERE user_id = ?');
    $stmt->execute([$userId]);
    return (int)$stmt->fetchColumn();
}
