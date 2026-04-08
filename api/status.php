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

// Persist status to database
$upd = $db->prepare('UPDATE sites SET post_count = :pc, http_status = :hs, api_ok = :ao, last_status_check = datetime("now") WHERE id = :id');
$upd->bindValue(':pc', $response['post_count'], $response['post_count'] !== null ? SQLITE3_INTEGER : SQLITE3_NULL);
$upd->bindValue(':hs', $response['http_status'], SQLITE3_INTEGER);
$upd->bindValue(':ao', $response['api_ok'] ? 1 : 0, SQLITE3_INTEGER);
$upd->bindValue(':id', $id, SQLITE3_INTEGER);
$upd->execute();

echo json_encode($response);
