<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/wp_api.php';
header('Content-Type: application/json');
requireLoginApi();

$siteId = (int) ($_GET['site_id'] ?? 0);
$type = $_GET['type'] ?? '';

if (!$siteId || !in_array($type, ['categories', 'authors'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Wymagane parametry: site_id, type (categories|authors)']);
    exit;
}

$db = getDb();
$stmt = $db->prepare('SELECT url, username, app_password FROM sites WHERE id = :id');
$stmt->bindValue(':id', $siteId, SQLITE3_INTEGER);
$site = $stmt->execute()->fetchArray(SQLITE3_ASSOC);

if (!$site) {
    http_response_code(404);
    echo json_encode(['error' => 'Strona nie znaleziona']);
    exit;
}

try {
    $api = new WpApi($site['url'], $site['username'], $site['app_password']);

    if ($type === 'categories') {
        $items = $api->getCategories();
        $result = array_map(fn($c) => ['id' => $c['id'], 'name' => $c['name']], $items);
    } else {
        $items = $api->getAuthors();
        $result = array_map(fn($u) => ['id' => $u['id'], 'name' => $u['name']], $items);
    }

    echo json_encode($result);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
