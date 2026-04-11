<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/wp_api.php';
require_once __DIR__ . '/../includes/link_extractor.php';
header('Content-Type: application/json');
requireLoginApi();

set_time_limit(120);

$input = json_decode(file_get_contents('php://input'), true);
$id = (int) ($input['id'] ?? 0);

if (!$id) {
    http_response_code(400);
    echo json_encode(['error' => 'Brak ID strony']);
    exit;
}

$db = getDb();
$stmt = $db->prepare('SELECT * FROM sites WHERE id = :id');
$stmt->bindValue(':id', $id, SQLITE3_INTEGER);
$result = $stmt->execute();
$site = $result->fetchArray(SQLITE3_ASSOC);

if (!$site) {
    http_response_code(404);
    echo json_encode(['error' => 'Strona nie znaleziona']);
    exit;
}

$response = ['id' => $id, 'http_status' => 0, 'post_count' => null, 'api_ok' => false, 'link_count' => 0, 'error' => null];

// Get HTTP status
$response['http_status'] = WpApi::getHttpStatus($site['url']);

// Test API connection, get post count, and scan links
try {
    $api = new WpApi($site['url'], $site['username'], $site['app_password']);
    $api->testConnection();
    $response['api_ok'] = true;
    $response['post_count'] = $api->getPostCount();

    // Scan links
    $posts = $api->getPosts();
    $parsedSiteUrl = parse_url($site['url']);
    $siteDomain = strtolower(preg_replace('/^www\./', '', $parsedSiteUrl['host'] ?? ''));

    $clientDomains = [];
    $clientResult = $db->query('SELECT id, domain FROM clients');
    while ($row = $clientResult->fetchArray(SQLITE3_ASSOC)) {
        $clientDomains[strtolower($row['domain'])] = (int) $row['id'];
    }

    $insertStmt = $db->prepare('
        INSERT OR IGNORE INTO links (site_id, client_id, post_url, post_title, target_url, anchor_text, link_type)
        VALUES (:site_id, :client_id, :post_url, :post_title, :target_url, :anchor_text, :link_type)
    ');

    foreach ($posts as $post) {
        $postUrl = $post['link'] ?? '';
        $postTitle = $post['title']['rendered'] ?? '';
        $html = $post['content']['rendered'] ?? '';
        if (empty($html)) continue;

        $externalLinks = extractExternalLinks($html, $siteDomain);
        foreach ($externalLinks as $link) {
            $clientId = matchClientDomain($link['target_url'], $clientDomains);
            $insertStmt->bindValue(':site_id', $id, SQLITE3_INTEGER);
            $insertStmt->bindValue(':client_id', $clientId, $clientId ? SQLITE3_INTEGER : SQLITE3_NULL);
            $insertStmt->bindValue(':post_url', $postUrl, SQLITE3_TEXT);
            $insertStmt->bindValue(':post_title', $postTitle, SQLITE3_TEXT);
            $insertStmt->bindValue(':target_url', $link['target_url'], SQLITE3_TEXT);
            $insertStmt->bindValue(':anchor_text', $link['anchor_text'], SQLITE3_TEXT);
            $insertStmt->bindValue(':link_type', $link['link_type'], SQLITE3_TEXT);
            $insertStmt->execute();
            $insertStmt->reset();
        }
    }

    rematchClientLinks($db);

    // Count links for this site
    $countStmt = $db->prepare('SELECT COUNT(*) as cnt FROM links WHERE site_id = :id');
    $countStmt->bindValue(':id', $id, SQLITE3_INTEGER);
    $response['link_count'] = (int) $countStmt->execute()->fetchArray(SQLITE3_ASSOC)['cnt'];

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
