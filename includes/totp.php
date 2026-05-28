<?php
/**
 * Pure-PHP TOTP (RFC 6238) — no external library / Composer needed.
 *
 * Compatible with Google Authenticator, Authy, 1Password, Microsoft Authenticator.
 * Parameters: SHA1 / 6 digits / 30s step / window ±TOTP_WINDOW (config.php).
 */
require_once __DIR__ . '/../config.php';

const TOTP_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
const TOTP_DIGITS = 6;
const TOTP_PERIOD = 30;

/** Generate a 32-character (160-bit) base32 secret. */
function totpGenerateSecret(): string {
    $bytes = random_bytes(20);
    return totpBase32Encode($bytes);
}

function totpBase32Encode(string $bytes): string {
    if ($bytes === '') return '';
    $bin = '';
    for ($i = 0, $n = strlen($bytes); $i < $n; $i++) {
        $bin .= str_pad(decbin(ord($bytes[$i])), 8, '0', STR_PAD_LEFT);
    }
    $pad = (5 - (strlen($bin) % 5)) % 5;
    $bin .= str_repeat('0', $pad);
    $out = '';
    for ($i = 0, $n = strlen($bin); $i < $n; $i += 5) {
        $out .= TOTP_ALPHABET[bindec(substr($bin, $i, 5))];
    }
    return $out;
}

function totpBase32Decode(string $base32): string {
    $base32 = strtoupper(preg_replace('/[^A-Z2-7]/', '', $base32));
    if ($base32 === '') return '';
    $bin = '';
    for ($i = 0, $n = strlen($base32); $i < $n; $i++) {
        $idx = strpos(TOTP_ALPHABET, $base32[$i]);
        if ($idx === false) continue;
        $bin .= str_pad(decbin($idx), 5, '0', STR_PAD_LEFT);
    }
    $out = '';
    for ($i = 0, $n = strlen($bin); $i + 8 <= $n; $i += 8) {
        $out .= chr(bindec(substr($bin, $i, 8)));
    }
    return $out;
}

/** Compute the TOTP code at the given (or current) UNIX timestamp. */
function totpCode(string $secretBase32, ?int $timestamp = null): string {
    $time = $timestamp ?? time();
    $counter = (int) floor($time / TOTP_PERIOD);
    $key = totpBase32Decode($secretBase32);
    if ($key === '') return str_repeat('0', TOTP_DIGITS);

    // 8-byte big-endian counter
    $binCounter = pack('N*', 0, $counter);
    $hash = hash_hmac('sha1', $binCounter, $key, true);

    $offset = ord($hash[strlen($hash) - 1]) & 0x0F;
    $code = ((ord($hash[$offset]) & 0x7F) << 24)
          | ((ord($hash[$offset + 1]) & 0xFF) << 16)
          | ((ord($hash[$offset + 2]) & 0xFF) << 8)
          |  (ord($hash[$offset + 3]) & 0xFF);
    $code = $code % (10 ** TOTP_DIGITS);
    return str_pad((string) $code, TOTP_DIGITS, '0', STR_PAD_LEFT);
}

/** Verify a user-supplied 6-digit code, tolerating clock skew within $window steps. */
function totpVerify(string $secretBase32, string $userCode, int $window = TOTP_WINDOW): bool {
    $userCode = preg_replace('/\D/', '', $userCode);
    if (strlen($userCode) !== TOTP_DIGITS) return false;
    $time = time();
    for ($w = -$window; $w <= $window; $w++) {
        $check = totpCode($secretBase32, $time + ($w * TOTP_PERIOD));
        if (hash_equals($check, $userCode)) return true;
    }
    return false;
}

/** Build the otpauth:// URL embedded into QR codes. */
function totpOtpauthUrl(string $secretBase32, string $accountName, string $issuer): string {
    $label = rawurlencode($issuer . ':' . $accountName);
    $params = http_build_query([
        'secret' => $secretBase32,
        'issuer' => $issuer,
        'algorithm' => 'SHA1',
        'digits' => TOTP_DIGITS,
        'period' => TOTP_PERIOD,
    ]);
    return 'otpauth://totp/' . $label . '?' . $params;
}
