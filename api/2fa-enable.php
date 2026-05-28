<?php
require_once __DIR__ . '/../auth.php';
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
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
}
