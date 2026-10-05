<?php

require_once __DIR__ . '/../config/qr_secret.php';


function encryptQRPayload(string $brn, int $ff_id): string
{
    $payload = json_encode([
        'brn'       => $brn,
        'ff_id'     => $ff_id,
        'issued_at' => time(),
    ]);

    $iv  = random_bytes(12);
    $tag = '';

    $ciphertext = openssl_encrypt(
        $payload,
        'aes-256-gcm',
        QR_SECRET_KEY,
        OPENSSL_RAW_DATA,
        $iv,
        $tag,
        '',
        16
    );

    if ($ciphertext === false) {
        throw new RuntimeException('QR encryption failed: ' . openssl_error_string());
    }


    $packed = $iv . $tag . $ciphertext;


    return rtrim(strtr(base64_encode($packed), '+/', '-_'), '=');
}


function decryptQRPayload(string $token): array
{

    $padded = strtr($token, '-_', '+/');
    $rem    = strlen($padded) % 4;
    if ($rem) $padded .= str_repeat('=', 4 - $rem);

    $packed = base64_decode($padded, true);
    if ($packed === false || strlen($packed) < 28) {
        throw new RuntimeException('Invalid QR token format.');
    }

    $iv         = substr($packed, 0,  12);
    $tag        = substr($packed, 12, 16);
    $ciphertext = substr($packed, 28);

    $plaintext = openssl_decrypt(
        $ciphertext,
        'aes-256-gcm',
        QR_SECRET_KEY,
        OPENSSL_RAW_DATA,
        $iv,
        $tag
    );

    if ($plaintext === false) {
        throw new RuntimeException('QR token authentication failed — invalid or tampered.');
    }

    $payload = json_decode($plaintext, true);
    if (!$payload || !isset($payload['brn'], $payload['ff_id'], $payload['issued_at'])) {
        throw new RuntimeException('QR token payload malformed.');
    }


    if (defined('QR_TOKEN_TTL') && QR_TOKEN_TTL > 0) {
        if (time() - $payload['issued_at'] > QR_TOKEN_TTL) {
            throw new RuntimeException('QR token has expired.');
        }
    }

    return $payload;
}