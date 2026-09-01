<?php
declare(strict_types=1);

/**
 * Safaricom Daraja STK push helper, ported from the (unused) Node reference
 * implementation at server/utils/mpesa.js. Requires real mpesa_* keys in config.php —
 * without them, initiateStkPush() throws and the caller surfaces a friendly error.
 */
function mpesaBaseUrl(array $config): string
{
    return (($config['mpesa_env'] ?? 'sandbox') === 'production')
        ? 'https://api.safaricom.co.ke'
        : 'https://sandbox.safaricom.co.ke';
}

function mpesaConfigured(array $config): bool
{
    return !empty($config['mpesa_consumer_key']) && !empty($config['mpesa_consumer_secret'])
        && !empty($config['mpesa_shortcode']) && !empty($config['mpesa_passkey']);
}

function mpesaAccessToken(array $config): string
{
    $url = mpesaBaseUrl($config) . '/oauth/v1/generate?grant_type=client_credentials';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD => $config['mpesa_consumer_key'] . ':' . $config['mpesa_consumer_secret'],
        CURLOPT_TIMEOUT => 15
    ]);
    $response = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);
    if ($response === false) throw new RuntimeException('Could not reach M-Pesa (' . $error . ')');
    $data = json_decode($response, true);
    if (!is_array($data) || empty($data['access_token'])) throw new RuntimeException('M-Pesa authentication failed');
    return (string)$data['access_token'];
}

/** Normalizes a Kenyan phone number to the 2547XXXXXXXX format Daraja expects. */
function mpesaNormalizePhone(string $phone): string
{
    $digits = preg_replace('/\D/', '', $phone);
    if (str_starts_with($digits, '0')) return '254' . substr($digits, 1);
    if (str_starts_with($digits, '254')) return $digits;
    if (str_starts_with($digits, '7') || str_starts_with($digits, '1')) return '254' . $digits;
    return $digits;
}

/** @return array{merchantRequestId:string, checkoutRequestId:string} */
function initiateStkPush(array $config, float $amount, string $phone, string $reference): array
{
    if (!mpesaConfigured($config)) {
        throw new RuntimeException('M-Pesa is not configured yet. Contact the ReSoK ICT team.');
    }
    $token = mpesaAccessToken($config);
    $shortcode = (string)$config['mpesa_shortcode'];
    $timestamp = date('YmdHis');
    $password = base64_encode($shortcode . $config['mpesa_passkey'] . $timestamp);
    $msisdn = mpesaNormalizePhone($phone);
    $callbackUrl = (string)($config['mpesa_callback_url'] ?? '');

    $payload = [
        'BusinessShortCode' => $shortcode,
        'Password' => $password,
        'Timestamp' => $timestamp,
        'TransactionType' => 'CustomerPayBillOnline',
        'Amount' => (int)round($amount),
        'PartyA' => $msisdn,
        'PartyB' => $shortcode,
        'PhoneNumber' => $msisdn,
        'CallBackURL' => $callbackUrl,
        'AccountReference' => substr($reference, 0, 20),
        'TransactionDesc' => 'ReSoK Membership'
    ];

    $ch = curl_init(mpesaBaseUrl($config) . '/mpesa/stkpush/v1/processrequest');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => 20
    ]);
    $response = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);
    if ($response === false) throw new RuntimeException('Could not reach M-Pesa (' . $error . ')');
    $data = json_decode($response, true);
    if (!is_array($data) || empty($data['CheckoutRequestID'])) {
        $message = is_array($data) ? ($data['errorMessage'] ?? $data['ResponseDescription'] ?? 'STK push failed') : 'STK push failed';
        throw new RuntimeException((string)$message);
    }
    return [
        'merchantRequestId' => (string)($data['MerchantRequestID'] ?? ''),
        'checkoutRequestId' => (string)$data['CheckoutRequestID']
    ];
}
