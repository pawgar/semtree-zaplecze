<?php
require_once __DIR__ . '/../auth.php';
header('Content-Type: application/json');
requireAdminApi();

$method = $_SERVER['REQUEST_METHOD'];
$db = getDb();

if ($method === 'GET') {
    $key = $_GET['key'] ?? '';
    if (!$key) {
        http_response_code(400);
        echo json_encode(['error' => 'Brak klucza']);
        exit;
    }

    $stmt = $db->prepare('SELECT value FROM settings WHERE key = :key');
    $stmt->bindValue(':key', $key, SQLITE3_TEXT);
    $result = $stmt->execute();
    $row = $result->fetchArray(SQLITE3_ASSOC);

    echo json_encode(['key' => $key, 'value' => $row ? $row['value'] : '']);
    exit;
}

if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $key = trim($input['key'] ?? '');
    $value = trim($input['value'] ?? '');

    if (!$key) {
        http_response_code(400);
        echo json_encode(['error' => 'Brak klucza']);
        exit;
    }

    $stmt = $db->prepare('INSERT OR REPLACE INTO settings (key, value) VALUES (:key, :value)');
    $stmt->bindValue(':key', $key, SQLITE3_TEXT);
    $stmt->bindValue(':value', $value, SQLITE3_TEXT);
    $stmt->execute();

    echo json_encode(['success' => true]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
