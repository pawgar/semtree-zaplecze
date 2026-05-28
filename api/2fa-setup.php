<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/totp.php';
header('Content-Type: application/json');
requireLoginApi();

try {
    $uid = (int) $_SESSION['user_id'];
    $row = tfaUserRow($uid);
    if ($row && tfaIsEnabled($row)) {
        http_response_code(409);
        echo json_encode(['error' => '2FA jest już aktywne. Najpierw je wyłącz, jeśli chcesz przepiąć aplikację.']);
        exit;
    }
    $secret = tfaSetupSecret($uid);
    $account = (string)($_SESSION['username'] ?? 'user');
    $issuer = APP_NAME;
    echo json_encode([
        'success' => true,
        'secret'  => $secret,
        'otpauth' => totpOtpauthUrl($secret, $account, $issuer),
        'issuer'  => $issuer,
        'account' => $account,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
