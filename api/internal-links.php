<?php
/**
 * Fetch published posts from a WordPress site for internal linking.
 * Returns list of posts with id, title, and link.
 */
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/wp_api.php';
header('Content-Type: application/json');
requireLoginApi();

$siteId = (int) ($_GET['site_id'] ?? 0);
if (!$siteId) {
    http_response_code(400);
    echo json_encode(['error' => 'Brak site_id']);
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
    $posts = $api->getPosts();

    $result = [];
    foreach ($posts as $post) {
        $title = $post['title']['rendered'] ?? ($post['title'] ?? '');
        $title = html_entity_decode(strip_tags($title), ENT_QUOTES, 'UTF-8');
        $result[] = [
            'id' => $post['id'] ?? 0,
            'title' => $title,
            'link' => $post['link'] ?? '',
        ];
    }

    echo json_encode(['success' => true, 'posts' => $result]);
} catch (Exception $e) {
    echo json_encode(['error' => 'Błąd pobierania postów: ' . $e->getMessage()]);
}
