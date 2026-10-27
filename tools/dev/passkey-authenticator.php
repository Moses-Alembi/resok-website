<?php
declare(strict_types=1);

/**
 * A software stand-in for a phone's passkey hardware, for tools/dev/test-passkey.sh.
 *
 *   php passkey-authenticator.php create <state.json> <optionsJson> <origin> [flags] [rpIdOverride]
 *   php passkey-authenticator.php get    <state.json> <optionsJson> <origin> [flags] [signCount] [rpIdOverride]
 *
 * create makes a P-256 key, keeps it in state.json and prints a registration response;
 * get signs the challenge with it and prints an assertion. flags defaults to UP|UV (|AT on
 * create); passing a different value is how the test sends a response without user
 * verification, and rpIdOverride how it pretends to be another website.
 */

function b64u(string $b): string { return rtrim(strtr(base64_encode($b), '+/', '-_'), '='); }
function b64uDecode(string $s): string { return base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4)); }

function cbor($v): string
{
    $head = static function (int $major, int $n): string {
        if ($n < 24) return chr(($major << 5) | $n);
        if ($n < 256) return chr(($major << 5) | 24) . chr($n);
        if ($n < 65536) return chr(($major << 5) | 25) . pack('n', $n);
        return chr(($major << 5) | 26) . pack('N', $n);
    };
    if (is_int($v)) return $v >= 0 ? $head(0, $v) : $head(1, -1 - $v);
    if (is_array($v) && array_key_exists('__bytes', $v)) return $head(2, strlen($v['__bytes'])) . $v['__bytes'];
    if (is_string($v)) return $head(3, strlen($v)) . $v;
    if (is_array($v)) { $out = $head(5, count($v)); foreach ($v as $k => $x) $out .= cbor($k) . cbor($x); return $out; }
    throw new RuntimeException('cannot encode');
}

[$cmd, $statePath, $optionsJson, $origin] = array_slice($argv, 1, 4);
$opts = json_decode($optionsJson, true)['publicKey'] ?? json_decode($optionsJson, true);
$state = is_file($statePath) ? json_decode(file_get_contents($statePath), true) : [];

if ($cmd === 'create') {
    $flags = isset($argv[5]) ? (int)$argv[5] : 0x45;
    $rpId = $argv[6] ?? $opts['rp']['id'];
    // XAMPP's PHP on Windows needs to be told where its OpenSSL configuration is.
    $cnf = is_file('C:/xampp/php/extras/ssl/openssl.cnf') ? ['config' => 'C:/xampp/php/extras/ssl/openssl.cnf'] : [];
    $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1'] + $cnf);
    openssl_pkey_export($key, $pem, null, $cnf);
    $d = openssl_pkey_get_details($key)['ec'];
    $credId = random_bytes(32);
    $cose = cbor([1 => 2, 3 => -7, -1 => 1, -2 => ['__bytes' => str_pad($d['x'], 32, "\0", STR_PAD_LEFT)], -3 => ['__bytes' => str_pad($d['y'], 32, "\0", STR_PAD_LEFT)]]);
    $authData = hash('sha256', $rpId, true) . chr($flags) . pack('N', 0) . str_repeat("\0", 16) . pack('n', strlen($credId)) . $credId . $cose;
    $client = json_encode(['type' => 'webauthn.create', 'challenge' => $opts['challenge'], 'origin' => $origin, 'crossOrigin' => false]);
    file_put_contents($statePath, json_encode(['pem' => $pem, 'id' => b64u($credId)]));
    echo json_encode(['clientDataJSON' => b64u($client), 'attestationObject' => b64u(cbor(['fmt' => 'none', 'attStmt' => [], 'authData' => ['__bytes' => $authData]]))]);
    exit;
}

if ($cmd === 'get') {
    $flags = isset($argv[5]) ? (int)$argv[5] : 0x05;
    $count = isset($argv[6]) ? (int)$argv[6] : 0;
    $rpId = $argv[7] ?? $opts['rpId'];
    $authData = hash('sha256', $rpId, true) . chr($flags) . pack('N', $count);
    $client = json_encode(['type' => 'webauthn.get', 'challenge' => $opts['challenge'], 'origin' => $origin, 'crossOrigin' => false]);
    openssl_sign($authData . hash('sha256', $client, true), $sig, $state['pem'], OPENSSL_ALGO_SHA256);
    echo json_encode(['id' => $state['id'], 'clientDataJSON' => b64u($client), 'authenticatorData' => b64u($authData), 'signature' => b64u($sig)]);
    exit;
}
fwrite(STDERR, "usage\n");
exit(2);
