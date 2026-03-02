<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/wp_api.php';
header('Content-Type: application/json');
requireLoginApi();

$input = json_decode(file_get_contents('php://input'), true);
$id = (int) ($input['id'] ?? 0);

if (!$id) {
    http_response_code(400);
    echo json_encode(['error' => 'Brak ID strony']);
    exit;
}

$db = getDb();
$stmt = $db->prepare('SELECT url, username, app_password FROM sites WHERE id = :id');
$stmt->bindValue(':id', $id, SQLITE3_INTEGER);
$result = $stmt->execute();
$site = $result->fetchArray(SQLITE3_ASSOC);

if (!$site) {
    http_response_code(404);
    echo json_encode(['error' => 'Strona nie znaleziona']);
    exit;
}

$response = ['id' => $id, 'http_status' => 0, 'post_count' => null, 'api_ok' => false, 'error' => null];

// Get HTTP status
$response['http_status'] = WpApi::getHttpStatus($site['url']);

// Test API connection and get post count
try {
    $api = new WpApi($site['url'], $site['username'], $site['app_password']);
    $api->testConnection();
    $response['api_ok'] = true;
    $response['post_count'] = $api->getPostCount();
} catch (Exception $e) {
    $response['error'] = $e->getMessage();
}

echo json_encode($response);
