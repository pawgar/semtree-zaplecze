<?php
/**
 * AES-256-GCM symmetric encryption used for TOTP secrets at rest.
 *
 * Storage format (base64-encoded for SQLite TEXT columns):
 *   [12-byte IV] | [16-byte auth tag] | [ciphertext]
 *
 * Key source: appKey() from config.php (32 bytes, auto-generated, not in git).
 */
require_once __DIR__ . '/../config.php';

function encryptString(string $plaintext): string {
    $key = appKey();
    $iv = random_bytes(12);
    $tag = '';
    $ciphertext = openssl_encrypt(
        $plaintext,
        'aes-256-gcm',
        $key,
        OPENSSL_RAW_DATA,
        $iv,
        $tag,
        '',
        16
    );
    if ($ciphertext === false) {
        throw new RuntimeException('Szyfrowanie nie powiodło się');
    }
    return base64_encode($iv . $tag . $ciphertext);
}

function decryptString(string $payload): string {
    $key = appKey();
    $raw = base64_decode($payload, true);
    if ($raw === false || strlen($raw) < 12 + 16 + 1) {
        throw new RuntimeException('Nieprawidłowy ciphertext');
    }
    $iv = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $ciphertext = substr($raw, 28);
    $plain = openssl_decrypt(
        $ciphertext,
        'aes-256-gcm',
        $key,
        OPENSSL_RAW_DATA,
        $iv,
        $tag,
        ''
    );
    if ($plain === false) {
        throw new RuntimeException('Deszyfrowanie nie powiodło się (zły klucz lub uszkodzone dane)');
    }
    return $plain;
}
