<?php
require_once __DIR__ . '/../auth.php';
header('Content-Type: application/json');

startSession();
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Nie zalogowano', 'expired' => true]);
    exit;
}

$remaining = sessionSecondsRemaining();
$expiresAt = sessionExpiresAt();
$expired   = isSessionExpired();

echo json_encode([
    'success'    => true,
    'remaining'  => $remaining,
    'expires_at' => $expiresAt,
    'expired'    => $expired,
    'absolute_lifetime' => ABSOLUTE_SESSION_SECONDS,
]);
