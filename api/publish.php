<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/wp_api.php';
require_once __DIR__ . '/../includes/image_utils.php';
header('Content-Type: application/json');
requireAdminApi();

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
        'status' => in_array($status, ['draft', 'publish']) ? $status : 'draft',
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

    echo json_encode([
        'success' => true,
        'post_id' => $result['id'] ?? 0,
        'post_url' => $result['link'] ?? '',
        'title' => $title,
    ]);
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'title' => $title,
        'error' => $e->getMessage(),
    ]);
}
