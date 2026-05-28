<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/two_factor.php';

function startSession(): void {
    if (session_status() !== PHP_SESSION_NONE) return;

    // ── Konfiguracja sesji PHP MUSI byc ustawiona PRZED session_start() ──
    //
    // Domyslne PHP: session.gc_maxlifetime=1440 (24min), session.cookie_lifetime=0
    // (do zamkniecia przegladarki). Przez to PHP usuwal pliki sesji po 24 minutach
    // niezaleznie od naszej logiki login_at + ABSOLUTE_SESSION_SECONDS.
    //
    // Plus na shared hostingu (Nazwa.pl/inne) defaultowy save_path = /tmp dzielony
    // z innymi aplikacjami, gdzie systemowy cron czysci globalnie wedlug NAJKROTSZEJ
    // wartosci gc_maxlifetime ze wszystkich vhostów. Dlatego trzymamy session files
    // we wlasnym katalogu data/sessions/.
    @ini_set('session.gc_maxlifetime', (string) SESSION_LIFETIME);
    @ini_set('session.gc_probability', '1');
    @ini_set('session.gc_divisor', '100');
    @ini_set('session.use_strict_mode', '1');
    @ini_set('session.cookie_httponly', '1');

    $sessionDir = __DIR__ . '/data/sessions';
    if (!is_dir($sessionDir)) {
        @mkdir($sessionDir, 0700, true);
        // Twardo blokujemy direct access przez Apache (na wszelki wypadek)
        @file_put_contents($sessionDir . '/.htaccess', "Require all denied\nDeny from all\n");
    }
    if (is_dir($sessionDir) && is_writable($sessionDir)) {
        session_save_path($sessionDir);
    }

    // Cookie lifetime musi byc ustawiany przez session_set_cookie_params, nie ini_set
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
           || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_set_cookie_params([
        'lifetime' => SESSION_LIFETIME,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}

/**
 * Step 1 of login — verify username + password.
 *
 * Returns:
 *   'ok'         — fully logged in (user has no 2FA)
 *   'pending_2fa'— password correct but 2FA required; call completeTwoFactor() next
 *   'invalid'    — credentials wrong
 *   'locked'     — 2FA is on but too many failed OTP attempts → cooldown
 */
function login(string $username, string $password): string {
    $db = getDb();
    $stmt = $db->prepare('SELECT id, username, password, role, totp_enabled, totp_secret, totp_locked_until FROM users WHERE username = :u');
    $stmt->bindValue(':u', $username, SQLITE3_TEXT);
    $result = $stmt->execute();
    $user = $result->fetchArray(SQLITE3_ASSOC);

    if (!$user || !password_verify($password, $user['password'])) {
        return 'invalid';
    }

    startSession();
    session_regenerate_id(true);

    if (tfaIsEnabled($user)) {
        // Park identity in pending state — DO NOT set user_id yet.
        // Note: nie blokujemy tu logowania w razie lockout — user musi miec mozliwosc
        // uzycia kodu odzyskiwania jako escape hatch. Lockout sprawdzamy ponizej,
        // przy konkretnej probie OTP.
        $_SESSION['pending_2fa'] = [
            'user_id'  => (int)$user['id'],
            'username' => $user['username'],
            'role'     => $user['role'],
            'started'  => time(),
        ];
        // Wipe any prior identity
        unset($_SESSION['user_id'], $_SESSION['username'], $_SESSION['role'], $_SESSION['login_at']);
        return 'pending_2fa';
    }

    finalizeLoginSession((int)$user['id'], $user['username'], $user['role']);
    return 'ok';
}

/**
 * Step 2 of login — verify TOTP (or recovery) code for the pending user.
 *
 * Returns:
 *   'ok'       — promoted to full session
 *   'invalid'  — wrong code, attempt counted
 *   'locked'   — too many wrong codes, cooldown active
 *   'expired'  — pending state older than PENDING_2FA_TTL, must re-enter password
 *   'no_pending'— there is no pending login on this session
 */
function completeTwoFactor(string $code, bool $isRecovery = false): string {
    startSession();
    if (empty($_SESSION['pending_2fa']) || !is_array($_SESSION['pending_2fa'])) {
        return 'no_pending';
    }
    $p = $_SESSION['pending_2fa'];
    if ((time() - (int)($p['started'] ?? 0)) > PENDING_2FA_TTL) {
        unset($_SESSION['pending_2fa']);
        return 'expired';
    }
    $uid = (int)$p['user_id'];
    $row = tfaUserRow($uid);
    if (!$row) return 'invalid';
    // Lockout dotyczy TYLKO klasycznego OTP — recovery code zawsze ma szanse zadziałać,
    // bo to jedyna droga ucieczki dla usera, który zgubił telefon / brute-forceował się sam.
    if (!$isRecovery && tfaIsLocked($row)) return 'locked';

    $ok = $isRecovery
        ? tfaConsumeRecoveryCode($uid, $code)
        : tfaVerifyOtp($uid, $code);
    if (!$ok) {
        // Re-read for fresh lockout state
        $row2 = tfaUserRow($uid);
        if ($row2 && tfaIsLocked($row2)) return 'locked';
        return 'invalid';
    }

    unset($_SESSION['pending_2fa']);
    finalizeLoginSession($uid, (string)$p['username'], (string)$p['role']);
    return 'ok';
}

/** Promotes a verified identity into the full session. */
function finalizeLoginSession(int $userId, string $username, string $role): void {
    startSession();
    session_regenerate_id(true);
    $_SESSION['user_id']  = $userId;
    $_SESSION['username'] = $username;
    $_SESSION['role']     = $role;
    $_SESSION['login_at'] = time();
}

function logout(): void {
    startSession();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}

/** True once the user has cleared password + (if applicable) 2FA. */
function isLoggedIn(): bool {
    startSession();
    if (!isset($_SESSION['user_id'])) return false;

    // Backward-compat: pre-existing sessions without login_at — anchor now,
    // don't immediately log everyone out on upgrade.
    if (empty($_SESSION['login_at'])) {
        $_SESSION['login_at'] = time();
    }
    if (isSessionExpired()) {
        logout();
        return false;
    }
    return true;
}

/** Is there a pending 2FA challenge waiting for OTP input? */
function pendingTwoFactor(): bool {
    startSession();
    if (empty($_SESSION['pending_2fa']) || !is_array($_SESSION['pending_2fa'])) return false;
    if ((time() - (int)($_SESSION['pending_2fa']['started'] ?? 0)) > PENDING_2FA_TTL) {
        unset($_SESSION['pending_2fa']);
        return false;
    }
    return true;
}

function isAdmin(): bool {
    startSession();
    return ($_SESSION['role'] ?? '') === 'admin';
}

/** UNIX timestamp at which the current session must end (absolute, not idle). */
function sessionExpiresAt(): int {
    startSession();
    $loginAt = (int)($_SESSION['login_at'] ?? 0);
    return $loginAt > 0 ? ($loginAt + ABSOLUTE_SESSION_SECONDS) : 0;
}

function sessionSecondsRemaining(): int {
    $exp = sessionExpiresAt();
    if ($exp === 0) return 0;
    $diff = $exp - time();
    return $diff > 0 ? $diff : 0;
}

function isSessionExpired(): bool {
    return sessionExpiresAt() !== 0 && time() >= sessionExpiresAt();
}

/** Convenience: does the current user have 2FA active? */
function hasTwoFactorEnabled(): bool {
    if (!isLoggedIn()) return false;
    $row = tfaUserRow((int)$_SESSION['user_id']);
    return tfaIsEnabled($row);
}

function requireLogin(): void {
    if (!isLoggedIn()) {
        header('Location: index.php?page=login');
        exit;
    }
}

function requireAdmin(): void {
    requireLogin();
    if (!isAdmin()) {
        http_response_code(403);
        echo json_encode(['error' => 'Brak uprawnien']);
        exit;
    }
}

function requireAdminApi(): void {
    startSession();
    if (!isLoggedIn() || !isAdmin()) {
        http_response_code(403);
        echo json_encode(['error' => 'Brak uprawnien']);
        exit;
    }
    enforceTwoFactorOnApi();
}

function requireLoginApi(): void {
    startSession();
    if (!isLoggedIn()) {
        http_response_code(401);
        echo json_encode(['error' => 'Nie zalogowano']);
        exit;
    }
    enforceTwoFactorOnApi();
}

/**
 * Enforce mandatory 2FA on API endpoints. The wizard's own bootstrap endpoints
 * (setup/enable) and session-info are whitelisted — otherwise the user could
 * never activate 2FA in the first place.
 */
function enforceTwoFactorOnApi(): void {
    $script = basename($_SERVER['SCRIPT_NAME'] ?? '');
    $whitelist = ['2fa-setup.php', '2fa-enable.php', 'session-info.php'];
    if (in_array($script, $whitelist, true)) return;
    if (hasTwoFactorEnabled()) return;
    http_response_code(403);
    echo json_encode(['error' => '2FA wymagane', 'require_2fa' => true]);
    exit;
}
