<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/wp_api.php';
header('Content-Type: application/json');
requireAdminApi();

$input = json_decode(file_get_contents('php://input'), true);
$siteId = (int) ($input['site_id'] ?? 0);
$newPassword = $input['password'] ?? '';

if (!$siteId || !$newPassword) {
    http_response_code(400);
    echo json_encode(['error' => 'Brak ID strony lub hasla']);
    exit;
}

if (strlen($newPassword) < 6) {
    http_response_code(400);
    echo json_encode(['error' => 'Haslo musi miec co najmniej 6 znakow']);
    exit;
}

$db = getDb();
$stmt = $db->prepare('SELECT name, url, username, app_password FROM sites WHERE id = :id');
$stmt->bindValue(':id', $siteId, SQLITE3_INTEGER);
$result = $stmt->execute();
$site = $result->fetchArray(SQLITE3_ASSOC);

if (!$site) {
    http_response_code(404);
    echo json_encode(['error' => 'Strona nie znaleziona']);
    exit;
}

try {
    $api = new WpApi($site['url'], $site['username'], $site['app_password']);
    $info = $api->changePassword($newPassword);
    echo json_encode([
        'success' => true,
        'site_id' => $siteId,
        'site_name' => $site['name'],
        'user' => $info['name'] ?? $site['username'],
    ]);
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'site_id' => $siteId,
        'site_name' => $site['name'],
        'error' => $e->getMessage(),
    ]);
}
