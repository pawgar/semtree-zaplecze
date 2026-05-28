<?php
/**
 * Two-factor authentication service.
 * Adapted from Atena's TwoFactorService.php to procedural PHP + SQLite.
 *
 * Responsibilities:
 *   - generate / store / decrypt TOTP secrets (encrypted at rest)
 *   - generate / validate recovery codes (bcrypt-hashed, one-time use)
 *   - rate-limit failed attempts (MAX_FAILED_ATTEMPTS / LOCKOUT_SECONDS)
 *   - enable / disable 2FA on a user account
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/encryption.php';
require_once __DIR__ . '/totp.php';

/** Fetch a single user row by id. Returns null if not found. */
function tfaUserRow(int $userId): ?array {
    $db = getDb();
    $stmt = $db->prepare('SELECT id, username, password, role, totp_secret, totp_enabled, totp_recovery_codes, totp_enabled_at, totp_failed_attempts, totp_locked_until FROM users WHERE id = :id');
    $stmt->bindValue(':id', $userId, SQLITE3_INTEGER);
    $res = $stmt->execute();
    $row = $res->fetchArray(SQLITE3_ASSOC);
    return $row ?: null;
}

/** True if user has 2FA fully activated (totp_enabled=1 AND has a secret). */
function tfaIsEnabled(?array $user): bool {
    if (!$user) return false;
    return (int)($user['totp_enabled'] ?? 0) === 1 && !empty($user['totp_secret']);
}

/** True if user is currently locked out due to too many failed attempts. */
function tfaIsLocked(array $user): bool {
    $until = $user['totp_locked_until'] ?? null;
    if (!$until) return false;
    return strtotime((string)$until) > time();
}

/** Seconds remaining on lockout (0 if not locked). */
function tfaLockoutRemaining(array $user): int {
    $until = $user['totp_locked_until'] ?? null;
    if (!$until) return 0;
    $diff = strtotime((string)$until) - time();
    return $diff > 0 ? $diff : 0;
}

/**
 * Generate (or rotate) a TOTP secret for the user and persist it encrypted.
 * Does NOT enable 2FA — user must verify a code via tfaEnable() first.
 * Returns the plaintext base32 secret (the only time it's visible).
 */
function tfaSetupSecret(int $userId): string {
    $secret = totpGenerateSecret();
    $enc = encryptString($secret);
    $db = getDb();
    $stmt = $db->prepare('UPDATE users SET totp_secret = :s, totp_enabled = 0, totp_recovery_codes = NULL, totp_enabled_at = NULL, totp_failed_attempts = 0, totp_locked_until = NULL WHERE id = :id');
    $stmt->bindValue(':s', $enc, SQLITE3_TEXT);
    $stmt->bindValue(':id', $userId, SQLITE3_INTEGER);
    $stmt->execute();
    return $secret;
}

/** Read & decrypt the stored secret. Returns null if not set / corrupt. */
function tfaGetSecret(array $user): ?string {
    if (empty($user['totp_secret'])) return null;
    try {
        return decryptString((string)$user['totp_secret']);
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Verify a 6-digit code against the user's secret. Honors lockout and increments
 * failed counter on miss. Returns true if valid (and resets counters).
 */
function tfaVerifyOtp(int $userId, string $code): bool {
    $user = tfaUserRow($userId);
    if (!$user) return false;
    if (tfaIsLocked($user)) return false;
    $secret = tfaGetSecret($user);
    if (!$secret) return false;

    if (totpVerify($secret, $code, TOTP_WINDOW)) {
        tfaResetFailures($userId);
        return true;
    }
    tfaRegisterFailure($userId, $user);
    return false;
}

/** Normalize a recovery code to its canonical hashable form (no dashes, uppercase). */
function tfaNormalizeRecoveryCode(string $code): string {
    return strtoupper(preg_replace('/[^A-Z0-9]/i', '', $code));
}

/** Verify a recovery code; on hit, mark it consumed. Returns true if used.
 *  Recovery codes are deliberately NOT blocked by lockout — they are the
 *  escape hatch when the user lost the OTP device or got locked out brute-forcing.
 *  Each recovery code is one-time-use and bcrypt-hashed, so unlimited attempts
 *  remain computationally infeasible. */
function tfaConsumeRecoveryCode(int $userId, string $code): bool {
    $code = tfaNormalizeRecoveryCode($code);
    if ($code === '') return false;
    $user = tfaUserRow($userId);
    if (!$user) return false;
    if (empty($user['totp_recovery_codes'])) return false;

    $codes = json_decode((string)$user['totp_recovery_codes'], true);
    if (!is_array($codes)) return false;

    $matchIdx = null;
    foreach ($codes as $i => $entry) {
        if (!is_array($entry) || empty($entry['hash']) || !empty($entry['used'])) continue;
        if (password_verify($code, (string)$entry['hash'])) {
            $matchIdx = $i;
            break;
        }
    }
    if ($matchIdx === null) {
        tfaRegisterFailure($userId, $user);
        return false;
    }
    $codes[$matchIdx]['used'] = true;
    $codes[$matchIdx]['used_at'] = date('Y-m-d H:i:s');

    $db = getDb();
    $stmt = $db->prepare('UPDATE users SET totp_recovery_codes = :c, totp_failed_attempts = 0, totp_locked_until = NULL WHERE id = :id');
    $stmt->bindValue(':c', json_encode($codes, JSON_UNESCAPED_UNICODE), SQLITE3_TEXT);
    $stmt->bindValue(':id', $userId, SQLITE3_INTEGER);
    $stmt->execute();
    return true;
}

/** Count unused recovery codes for the user. */
function tfaRecoveryRemaining(?array $user): int {
    if (!$user || empty($user['totp_recovery_codes'])) return 0;
    $codes = json_decode((string)$user['totp_recovery_codes'], true);
    if (!is_array($codes)) return 0;
    $n = 0;
    foreach ($codes as $entry) {
        if (is_array($entry) && empty($entry['used'])) $n++;
    }
    return $n;
}

/**
 * Activate 2FA after the user proves possession by entering a valid OTP.
 * Returns an array of RECOVERY_CODE_COUNT plain-text codes (shown once).
 * Throws RuntimeException if code invalid / no secret prepared.
 */
function tfaEnable(int $userId, string $code): array {
    $user = tfaUserRow($userId);
    if (!$user) throw new RuntimeException('Użytkownik nie istnieje');
    $secret = tfaGetSecret($user);
    if (!$secret) throw new RuntimeException('Brak przygotowanego sekretu — uruchom konfigurację 2FA');
    if (!totpVerify($secret, $code, TOTP_WINDOW)) {
        throw new RuntimeException('Nieprawidłowy kod — spróbuj ponownie');
    }
    $plainCodes = tfaGenerateRecoveryCodes(RECOVERY_CODE_COUNT);
    $stored = [];
    foreach ($plainCodes as $c) {
        // Hash the NORMALIZED form so users can type with or without dashes
        $stored[] = ['hash' => password_hash(tfaNormalizeRecoveryCode($c), PASSWORD_BCRYPT), 'used' => false];
    }
    $db = getDb();
    $stmt = $db->prepare('UPDATE users SET totp_enabled = 1, totp_recovery_codes = :c, totp_enabled_at = :t, totp_failed_attempts = 0, totp_locked_until = NULL WHERE id = :id');
    $stmt->bindValue(':c', json_encode($stored, JSON_UNESCAPED_UNICODE), SQLITE3_TEXT);
    $stmt->bindValue(':t', date('Y-m-d H:i:s'), SQLITE3_TEXT);
    $stmt->bindValue(':id', $userId, SQLITE3_INTEGER);
    $stmt->execute();
    return $plainCodes;
}

/** Disable 2FA — clears all related columns. */
function tfaDisable(int $userId): void {
    $db = getDb();
    $stmt = $db->prepare('UPDATE users SET totp_secret = NULL, totp_enabled = 0, totp_recovery_codes = NULL, totp_enabled_at = NULL, totp_failed_attempts = 0, totp_locked_until = NULL WHERE id = :id');
    $stmt->bindValue(':id', $userId, SQLITE3_INTEGER);
    $stmt->execute();
}

/** Generate a fresh batch of recovery codes (requires existing 2FA setup). */
function tfaRegenerateRecoveryCodes(int $userId): array {
    $user = tfaUserRow($userId);
    if (!$user || !tfaIsEnabled($user)) {
        throw new RuntimeException('2FA nie jest aktywne dla tego użytkownika');
    }
    $plainCodes = tfaGenerateRecoveryCodes(RECOVERY_CODE_COUNT);
    $stored = [];
    foreach ($plainCodes as $c) {
        $stored[] = ['hash' => password_hash(tfaNormalizeRecoveryCode($c), PASSWORD_BCRYPT), 'used' => false];
    }
    $db = getDb();
    $stmt = $db->prepare('UPDATE users SET totp_recovery_codes = :c WHERE id = :id');
    $stmt->bindValue(':c', json_encode($stored, JSON_UNESCAPED_UNICODE), SQLITE3_TEXT);
    $stmt->bindValue(':id', $userId, SQLITE3_INTEGER);
    $stmt->execute();
    return $plainCodes;
}

/** Produce $count human-friendly recovery codes (XXXX-XXXX format). */
function tfaGenerateRecoveryCodes(int $count): array {
    $codes = [];
    for ($i = 0; $i < $count; $i++) {
        $a = strtoupper(bin2hex(random_bytes(2)));
        $b = strtoupper(bin2hex(random_bytes(2)));
        $codes[] = $a . '-' . $b;
    }
    return $codes;
}

function tfaResetFailures(int $userId): void {
    $db = getDb();
    $stmt = $db->prepare('UPDATE users SET totp_failed_attempts = 0, totp_locked_until = NULL WHERE id = :id');
    $stmt->bindValue(':id', $userId, SQLITE3_INTEGER);
    $stmt->execute();
}

function tfaRegisterFailure(int $userId, array $user): void {
    $attempts = (int)($user['totp_failed_attempts'] ?? 0) + 1;
    $db = getDb();
    if ($attempts >= MAX_FAILED_ATTEMPTS) {
        $until = date('Y-m-d H:i:s', time() + LOCKOUT_SECONDS);
        $stmt = $db->prepare('UPDATE users SET totp_failed_attempts = :a, totp_locked_until = :u WHERE id = :id');
        $stmt->bindValue(':a', 0, SQLITE3_INTEGER); // reset counter once lockout fires
        $stmt->bindValue(':u', $until, SQLITE3_TEXT);
    } else {
        $stmt = $db->prepare('UPDATE users SET totp_failed_attempts = :a WHERE id = :id');
        $stmt->bindValue(':a', $attempts, SQLITE3_INTEGER);
    }
    $stmt->bindValue(':id', $userId, SQLITE3_INTEGER);
    $stmt->execute();
}
