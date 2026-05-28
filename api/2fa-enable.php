<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/totp.php';
header('Content-Type: application/json');
requireLoginApi();

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$code = trim((string)($input['code'] ?? ''));
if ($code === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Brak kodu']);
    exit;
}

try {
    $codes = tfaEnable((int) $_SESSION['user_id'], $code);
    echo json_encode([
        'success' => true,
        'recovery_codes' => $codes,
    ]);
} catch (Throwable $e) {
    // DIAGNOSTYKA: gdy weryfikacja zawiedzie, zwroc oczekiwany kod (to
    // sekret usera — nie ma leaku, bo user jest zalogowany i to JEGO sekret).
    // Pozwala porownac z tym co pokazuje aplikacja Authenticator.
    $extra = [];
    $user = tfaUserRow((int) $_SESSION['user_id']);
    if ($user) {
        $secret = tfaGetSecret($user);
        if ($secret) {
            $now = time();
            $extra['debug'] = [
                'expected_now'   => totpCode($secret, $now),
                'expected_prev'  => totpCode($secret, $now - 30),
                'expected_next'  => totpCode($secret, $now + 30),
                'server_time'    => gmdate('Y-m-d H:i:s') . ' UTC',
                'server_unix'    => $now,
                'totp_step'      => intdiv($now, 30),
                'received_code'  => preg_replace('/\D/', '', $code),
                'window'         => TOTP_WINDOW,
                'secret_loaded'  => true,
                'secret_first8'  => substr($secret, 0, 4) . '...' . substr($secret, -4),
            ];
        } else {
            $extra['debug'] = ['secret_loaded' => false, 'note' => 'Nie udało się odszyfrować sekretu — sprawdź data/app_key.php'];
        }
    }
    http_response_code(400);
    echo json_encode(array_merge(['error' => $e->getMessage()], $extra));
}
