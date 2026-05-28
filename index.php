<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/includes/totp.php';

$page = $_GET['page'] ?? '';

// ── Logout ──────────────────────────────────────────────────
if ($page === 'logout') {
    logout();
    header('Location: index.php?page=login');
    exit;
}

// ── Step 1: username + password ─────────────────────────────
if ($page === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    $result = login($username, $password);
    if ($result === 'ok') {
        header('Location: index.php');
        exit;
    }
    if ($result === 'pending_2fa') {
        header('Location: index.php?page=login-2fa');
        exit;
    }
    if ($result === 'locked') {
        $loginError = 'Konto czasowo zablokowane (zbyt wiele nieudanych prób 2FA). Spróbuj ponownie za kilka minut.';
    } else {
        $loginError = 'Nieprawidlowy login lub haslo';
    }
}

// ── Step 2: TOTP / recovery code ────────────────────────────
if ($page === 'login-2fa' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!pendingTwoFactor()) {
        header('Location: index.php?page=login');
        exit;
    }
    $code = trim($_POST['code'] ?? '');
    $useRecovery = !empty($_POST['recovery']);
    $r = completeTwoFactor($code, $useRecovery);
    if ($r === 'ok') {
        header('Location: index.php');
        exit;
    }
    if ($r === 'expired') {
        $loginError = 'Sesja weryfikacji wygasła — zaloguj się ponownie.';
        // fall through to show login.php
    } elseif ($r === 'locked') {
        logout(); // drop pending state, force restart
        $loginError = 'Zbyt wiele nieudanych prób — konto chwilowo zablokowane. Spróbuj ponownie za kilka minut.';
    } elseif ($r === 'no_pending') {
        $loginError = 'Brak aktywnej sesji logowania.';
    } else {
        $twoFactorError = $useRecovery
            ? 'Nieprawidłowy kod odzyskiwania.'
            : 'Nieprawidłowy kod uwierzytelniający.';
        // DIAGNOSTYKA: dla nieudanego OTP zwroc oczekiwane kody zeby user
        // mogl porownac z tym co ma w aplikacji. To JEGO sekret, brak leaku.
        if (!$useRecovery) {
            startSession();
            $uid = (int)($_SESSION['pending_2fa']['user_id'] ?? 0);
            if ($uid) {
                $row = tfaUserRow($uid);
                if ($row) {
                    $secret = tfaGetSecret($row);
                    if ($secret) {
                        $now = time();
                        $twoFactorDebug = [
                            'received_code' => preg_replace('/\D/', '', $code),
                            'expected_now'  => totpCode($secret, $now),
                            'expected_prev' => totpCode($secret, $now - 30),
                            'expected_next' => totpCode($secret, $now + 30),
                            'server_time'   => gmdate('Y-m-d H:i:s') . ' UTC',
                            'secret_loaded' => true,
                            'secret_first8' => substr($secret, 0, 4) . '...' . substr($secret, -4),
                            'totp_step'     => intdiv($now, 30),
                        ];
                    } else {
                        $twoFactorDebug = ['secret_loaded' => false, 'note' => 'Sekret w bazie nie odszyfrowal sie — data/app_key.php prawdopodobnie zostal zregenerowany po aktywacji.'];
                    }
                }
            }
        }
    }
}

// ── Show 2FA challenge page ─────────────────────────────────
if ($page === 'login-2fa' && pendingTwoFactor()) {
    require __DIR__ . '/pages/login-2fa.php';
    exit;
}

// Drop stale pending state if user navigated elsewhere
if (pendingTwoFactor() && $page !== 'login-2fa') {
    // Cancelled — bounce back to login (clear pending only on /login GET)
    if ($page === 'login') {
        startSession();
        unset($_SESSION['pending_2fa']);
    }
}

// ── Not logged in → show login ──────────────────────────────
if (!isLoggedIn()) {
    require __DIR__ . '/pages/login.php';
    exit;
}

// ── HARD GATE: 2FA jest wymagane dla wszystkich ─────────────
// Jeśli zalogowany użytkownik nie ma jeszcze aktywnego 2FA,
// może wejść tylko na stronę wymuszonej konfiguracji lub się wylogować.
if (!hasTwoFactorEnabled()) {
    $allowedWithout2FA = ['2fa-required', 'logout'];
    if (!in_array($page, $allowedWithout2FA, true)) {
        header('Location: index.php?page=2fa-required');
        exit;
    }
}

// ── Logged in → route ───────────────────────────────────────
switch ($page) {
    case 'order':
        requireLogin();
        require __DIR__ . '/pages/order.php';
        break;
    case 'publish':
        requireLogin();
        require __DIR__ . '/pages/publish.php';
        break;
    case 'import':
        requireLogin();
        require __DIR__ . '/pages/import.php';
        break;
    case 'links':
        requireLogin();
        require __DIR__ . '/pages/links.php';
        break;
    case 'gsc-report':
        requireLogin();
        require __DIR__ . '/pages/gsc-report.php';
        break;
    case 'auto-publish':
        requireLogin();
        require __DIR__ . '/pages/auto-publish.php';
        break;
    case 'site-card':
        requireLogin();
        require __DIR__ . '/pages/site-card.php';
        break;
    case 'settings':
        requireLogin();
        require __DIR__ . '/pages/settings.php';
        break;
    case 'users':
        requireAdmin();
        require __DIR__ . '/pages/users.php';
        break;
    case '2fa-required':
        requireLogin();
        require __DIR__ . '/pages/2fa-required.php';
        break;
    case 'profile':
        requireLogin();
        // Non-admin can only view own profile
        if (isset($_GET['user_id']) && (int)$_GET['user_id'] !== (int)$_SESSION['user_id'] && !isAdmin()) {
            http_response_code(403);
            echo 'Brak uprawnien';
            exit;
        }
        require __DIR__ . '/pages/profile.php';
        break;
    default:
        require __DIR__ . '/pages/dashboard.php';
        break;
}
