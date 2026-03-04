<?php
/**
 * Upload and optimize a single image to WordPress.
 * Used for parallel image uploads before post creation.
 */
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/wp_api.php';
require_once __DIR__ . '/../includes/image_utils.php';
header('Content-Type: application/json');
requireAdminApi();

$input = json_decode(file_get_contents('php://input'), true);
$siteId = (int) ($input['site_id'] ?? 0);
$imageData = $input['image_data'] ?? '';
$imageFilename = $input['image_filename'] ?? 'image.jpg';

if (!$siteId || !$imageData) {
    http_response_code(400);
    echo json_encode(['error' => 'Wymagane: site_id i image_data']);
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
    $binary = base64_decode($imageData);
    if ($binary === false) {
        throw new RuntimeException('Nieprawidlowe dane obrazka');
    }

    // Optimize: convert to JPEG, strip metadata, random filename
    $optimized = optimizeImage($binary, $imageFilename);

    $api = new WpApi($site['url'], $site['username'], $site['app_password']);
    $mediaId = $api->uploadMedia($optimized['filename'], $optimized['data'], $optimized['mime']);

    echo json_encode([
        'success' => true,
        'media_id' => $mediaId,
        'filename' => $optimized['filename'],
    ]);
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
    ]);
}
