<?php
/**
 * Scan all published posts on a WordPress site and extract external links.
 * POST: { site_id: N }
 */
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/wp_api.php';
require_once __DIR__ . '/../includes/link_extractor.php';
header('Content-Type: application/json');

set_time_limit(600);

requireAdminApi();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$siteId = (int) ($input['site_id'] ?? 0);

if (!$siteId) {
    http_response_code(400);
    echo json_encode(['error' => 'Brak site_id']);
    exit;
}

$db = getDb();

// Load site data
$stmt = $db->prepare('SELECT * FROM sites WHERE id = :id');
$stmt->bindValue(':id', $siteId, SQLITE3_INTEGER);
$site = $stmt->execute()->fetchArray(SQLITE3_ASSOC);

if (!$site) {
    http_response_code(404);
    echo json_encode(['error' => 'Strona nie znaleziona']);
    exit;
}

// Load client domains for auto-matching
$clientDomains = [];
$clientResult = $db->query('SELECT id, domain FROM clients');
while ($row = $clientResult->fetchArray(SQLITE3_ASSOC)) {
    $clientDomains[strtolower($row['domain'])] = (int) $row['id'];
}

// Get site domain for filtering internal links
$parsedSiteUrl = parse_url($site['url']);
$siteDomain = strtolower($parsedSiteUrl['host'] ?? '');
$siteDomain = preg_replace('/^www\./', '', $siteDomain);

try {
    $wp = new WpApi($site['url'], $site['username'], $site['app_password']);
    $posts = $wp->getPosts();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Blad pobierania postow: ' . $e->getMessage()]);
    exit;
}

// Prepare insert statement
$insertStmt = $db->prepare('
    INSERT OR IGNORE INTO links (site_id, client_id, post_url, post_title, target_url, anchor_text, link_type)
    VALUES (:site_id, :client_id, :post_url, :post_title, :target_url, :anchor_text, :link_type)
');

$totalInserted = 0;
$totalSkipped = 0;
$postsScanned = 0;

foreach ($posts as $post) {
    $postUrl = $post['link'] ?? '';
    $postTitle = $post['title']['rendered'] ?? '';
    $html = $post['content']['rendered'] ?? '';

    if (empty($html)) continue;

    $externalLinks = extractExternalLinks($html, $siteDomain);
    $postsScanned++;

    foreach ($externalLinks as $link) {
        $clientId = matchClientDomain($link['target_url'], $clientDomains);

        $insertStmt->bindValue(':site_id', $siteId, SQLITE3_INTEGER);
        $insertStmt->bindValue(':client_id', $clientId, $clientId ? SQLITE3_INTEGER : SQLITE3_NULL);
        $insertStmt->bindValue(':post_url', $postUrl, SQLITE3_TEXT);
        $insertStmt->bindValue(':post_title', $postTitle, SQLITE3_TEXT);
        $insertStmt->bindValue(':target_url', $link['target_url'], SQLITE3_TEXT);
        $insertStmt->bindValue(':anchor_text', $link['anchor_text'], SQLITE3_TEXT);
        $insertStmt->bindValue(':link_type', $link['link_type'], SQLITE3_TEXT);
        $insertStmt->execute();

        if ($db->changes() > 0) {
            $totalInserted++;
        } else {
            $totalSkipped++;
        }
        $insertStmt->reset();
    }
}

echo json_encode([
    'success' => true,
    'posts_scanned' => $postsScanned,
    'links_inserted' => $totalInserted,
    'links_skipped' => $totalSkipped,
    'site_name' => $site['name'],
]);
