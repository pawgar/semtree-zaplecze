<?php
define('APP_NAME', 'Semtree Zaplecze');
define('DB_PATH', __DIR__ . '/data/database.sqlite');
define('SESSION_LIFETIME', 86400); // 24h

// Default admin credentials (created on first run)
define('DEFAULT_ADMIN_USER', 'admin');
define('DEFAULT_ADMIN_PASS', 'admin');

// ═══════════════════════════════════════════════════════════════
//  2FA / Session security
// ═══════════════════════════════════════════════════════════════

// Absolute session lifetime (from successful login, not idle)
define('ABSOLUTE_SESSION_SECONDS', 86400);   // 24h
define('PENDING_2FA_TTL', 600);              // 10 min to enter OTP after password
define('RECOVERY_CODE_COUNT', 8);            // generated on activation
define('MAX_FAILED_ATTEMPTS', 5);            // wrong OTP attempts before lockout
define('LOCKOUT_SECONDS', 900);              // 15 min cooldown
define('TOTP_WINDOW', 2);                    // ±60s clock skew tolerance (was 1)

/**
 * APP_KEY — used to encrypt TOTP secrets at rest (AES-256-GCM).
 * Auto-generated on first need, stored in data/app_key.php (NOT in git).
 * Losing this file invalidates all stored TOTP secrets — users must re-pair.
 */
function appKey(): string {
    static $key = null;
    if ($key !== null) return $key;

    $path = __DIR__ . '/data/app_key.php';
    if (!is_file($path)) {
        if (!is_dir(__DIR__ . '/data')) {
            @mkdir(__DIR__ . '/data', 0775, true);
        }
        $raw = random_bytes(32);
        $encoded = base64_encode($raw);
        $contents = "<?php\n// Auto-generated. Do NOT commit. Losing this file invalidates all 2FA setups.\nreturn '" . $encoded . "';\n";
        if (file_put_contents($path, $contents, LOCK_EX) === false) {
            throw new RuntimeException('Nie udało się zapisać data/app_key.php — sprawdź uprawnienia');
        }
        @chmod($path, 0640);
        $key = $raw;
        return $key;
    }
    $encoded = require $path;
    $decoded = base64_decode((string)$encoded, true);
    if ($decoded === false || strlen($decoded) !== 32) {
        throw new RuntimeException('Nieprawidłowy APP_KEY w data/app_key.php');
    }
    $key = $decoded;
    return $key;
}
