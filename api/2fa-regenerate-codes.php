<?php
require_once __DIR__ . '/../auth.php';
header('Content-Type: application/json');
requireLoginApi();

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$password = (string)($input['password'] ?? '');
if ($password === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Podaj hasło, aby wygenerować nowe kody']);
    exit;
}

$db = getDb();
$stmt = $db->prepare('SELECT password FROM users WHERE id = :id');
$stmt->bindValue(':id', (int) $_SESSION['user_id'], SQLITE3_INTEGER);
$res = $stmt->execute();
$row = $res->fetchArray(SQLITE3_ASSOC);
if (!$row || !password_verify($password, $row['password'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Nieprawidłowe hasło']);
    exit;
}

try {
    $codes = tfaRegenerateRecoveryCodes((int) $_SESSION['user_id']);
    echo json_encode(['success' => true, 'recovery_codes' => $codes]);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
}
