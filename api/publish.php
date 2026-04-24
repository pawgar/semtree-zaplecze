<?php
set_time_limit(180); // Allow up to 3 minutes for publish + image upload
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/wp_api.php';
require_once __DIR__ . '/../includes/image_utils.php';
require_once __DIR__ . '/../includes/link_extractor.php';
header('Content-Type: application/json');
requireLoginApi();

$input = json_decode(file_get_contents('php://input'), true);

$siteId = (int) ($input['site_id'] ?? 0);
$title = trim($input['title'] ?? '');
$content = $input['content'] ?? '';
$status = $input['status'] ?? 'draft';
$categoryId = (int) ($input['category_id'] ?? 0);
$authorId = (int) ($input['author_id'] ?? 0);
$publishDate = $input['publish_date'] ?? '';
$imageData = $input['image_data'] ?? '';
$imageFilename = $input['image_filename'] ?? 'image.jpg';
$mediaId = (int) ($input['media_id'] ?? 0);

if (!$siteId || !$title) {
    http_response_code(400);
    echo json_encode(['error' => 'Wymagane: site_id i title']);
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

    // Featured image: use pre-uploaded media_id, or upload with optimization
    $featuredMediaId = 0;
    if ($mediaId > 0) {
        $featuredMediaId = $mediaId;
    } elseif ($imageData) {
        $binary = base64_decode($imageData);
        if ($binary === false) {
            throw new RuntimeException('Nieprawidlowe dane obrazka');
        }
        $optimized = optimizeImage($binary, $imageFilename);
        $featuredMediaId = $api->uploadMedia($optimized['filename'], $optimized['data'], $optimized['mime']);
    }

    // Build post data
    $postData = [
        'title' => $title,
        'content' => $content,
        'status' => in_array($status, ['draft', 'publish', 'future']) ? $status : 'draft',
    ];

    if ($categoryId > 0) {
        $postData['categories'] = [$categoryId];
    }
    if ($authorId > 0) {
        $postData['author'] = $authorId;
    }
    if ($featuredMediaId > 0) {
        $postData['featured_media'] = $featuredMediaId;
    }
    if ($publishDate) {
        // datetime-local gives "YYYY-MM-DDTHH:MM", WP needs "YYYY-MM-DDTHH:MM:SS"
        $dt = date_create($publishDate);
        if ($dt) {
            $postData['date'] = $dt->format('Y-m-d\TH:i:s');
        }
    }

    $result = $api->createPost($postData);

    // Record publication for user stats
    $postUrl = $result['link'] ?? '';
    $linksAdded = 0;
    if ($postUrl) {
        $pubStmt = $db->prepare('INSERT INTO publications (user_id, site_id, post_url, post_title) VALUES (:uid, :sid, :url, :title)');
        $pubStmt->bindValue(':uid', (int) $_SESSION['user_id'], SQLITE3_INTEGER);
        $pubStmt->bindValue(':sid', $siteId, SQLITE3_INTEGER);
        $pubStmt->bindValue(':url', $postUrl, SQLITE3_TEXT);
        $pubStmt->bindValue(':title', $title, SQLITE3_TEXT);
        $pubStmt->execute();

        // ── Immediately refresh site stats for this ONE site ──────────
        // Previously users had to wait for the nightly CRON. Now: incremental
        // update right after publish so the dashboard reflects reality.
        $db->exec("UPDATE sites SET post_count = COALESCE(post_count, 0) + 1, last_status_check = datetime('now') WHERE id = $siteId");

        // Scan the just-published content for external links → insert into
        // links table with client matching (same logic as cron-status.php).
        $renderedHtml = $result['content']['rendered'] ?? $content;
        if (!empty($renderedHtml)) {
            $parsedUrl = parse_url($site['url']);
            $siteDomain = strtolower(preg_replace('/^www\./', '', $parsedUrl['host'] ?? ''));

            $clientDomains = [];
            $clientResult = $db->query('SELECT id, domain FROM clients');
            while ($row = $clientResult->fetchArray(SQLITE3_ASSOC)) {
                $clientDomains[strtolower($row['domain'])] = (int) $row['id'];
            }

            $externalLinks = extractExternalLinks($renderedHtml, $siteDomain);
            if (!empty($externalLinks)) {
                $insertStmt = $db->prepare('
                    INSERT OR IGNORE INTO links (site_id, client_id, post_url, post_title, target_url, anchor_text, link_type)
                    VALUES (:site_id, :client_id, :post_url, :post_title, :target_url, :anchor_text, :link_type)
                ');
                foreach ($externalLinks as $link) {
                    $clientId = matchClientDomain($link['target_url'], $clientDomains);
                    $insertStmt->bindValue(':site_id', $siteId, SQLITE3_INTEGER);
                    $insertStmt->bindValue(':client_id', $clientId, $clientId ? SQLITE3_INTEGER : SQLITE3_NULL);
                    $insertStmt->bindValue(':post_url', $postUrl, SQLITE3_TEXT);
                    $insertStmt->bindValue(':post_title', $title, SQLITE3_TEXT);
                    $insertStmt->bindValue(':target_url', $link['target_url'], SQLITE3_TEXT);
                    $insertStmt->bindValue(':anchor_text', $link['anchor_text'], SQLITE3_TEXT);
                    $insertStmt->bindValue(':link_type', $link['link_type'], SQLITE3_TEXT);
                    if ($insertStmt->execute()) $linksAdded++;
                    $insertStmt->reset();
                }
            }
        }
    }

    echo json_encode([
        'success' => true,
        'post_id' => $result['id'] ?? 0,
        'post_url' => $postUrl,
        'title' => $title,
        'links_added' => $linksAdded,
    ]);
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'title' => $title,
        'error' => $e->getMessage(),
    ]);
}
