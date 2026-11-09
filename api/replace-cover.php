<?php
/**
 * Podmiana okładki opublikowanego wpisu na nową grafikę bez napisów (admin).
 *   POST {url: "https://domena.pl/slug-wpisu/"}
 * Stara grafika zostaje w bibliotece mediów WP (nic nie kasujemy), wpis dostaje nowe featured_media.
 */
set_time_limit(300);
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/wp_api.php';
require_once __DIR__ . '/../includes/image_utils.php';
require_once __DIR__ . '/../includes/article_prompt.php';
require_once __DIR__ . '/../includes/cover_image.php';
header('Content-Type: application/json');
requireAdminApi();

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$postUrl = trim($input['url'] ?? '');
$host = strtolower((string)parse_url($postUrl, PHP_URL_HOST));
$slug = trim(basename((string)parse_url($postUrl, PHP_URL_PATH)), '/');
if ($host === '' || $slug === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Podaj pełny adres wpisu']);
    exit;
}

$db = getDb();
$site = null;
$r = $db->query('SELECT id, name, url, username, app_password FROM sites');
while ($row = $r->fetchArray(SQLITE3_ASSOC)) {
    $h = preg_replace('/^www\./', '', strtolower((string)parse_url($row['url'], PHP_URL_HOST)));
    if ($h === preg_replace('/^www\./', '', $host)) { $site = $row; break; }
}
if (!$site) {
    echo json_encode(['error' => "Strona $host nie jest w aplikacji"]);
    exit;
}
$geminiKey = trim((string)$db->querySingle("SELECT value FROM settings WHERE key = 'gemini_api_key'"));
if ($geminiKey === '') {
    echo json_encode(['error' => 'Brak klucza Gemini API']);
    exit;
}

try {
    $wp = new WpApi($site['url'], $site['username'], $site['app_password']);
    $found = $wp->findPostBySlug($slug);
    if (!$found) throw new RuntimeException("Nie znaleziono wpisu o slugu $slug");
    $post = $wp->getPost((int)$found['id']);
    $title = html_entity_decode(strip_tags($post['title']['rendered'] ?? $post['title']['raw'] ?? ''), ENT_QUOTES, 'UTF-8');

    $log = [];
    $errors = [];
    $bin = generateTextFreeImage($geminiKey, $title, '', '16:9', $errors, function ($m) use (&$log) { $log[] = $m; });
    if ($bin === null) throw new RuntimeException('Grafika: ' . implode('; ', $errors));

    $old = (int)($post['featured_media'] ?? 0);
    $optimized = optimizeImage($bin, (slugify($slug) ?: 'okladka') . '.jpg', true);
    $mediaId = $wp->uploadMedia($optimized['filename'], $optimized['data'], $optimized['mime']);
    if (!$mediaId) throw new RuntimeException('Nie udało się wgrać grafiki');
    $wp->updateMedia($mediaId, ['alt_text' => $title, 'title' => $title]);
    $wp->updatePost((int)$found['id'], ['featured_media' => $mediaId]);
    $media = $wp->getMedia($mediaId);

    echo json_encode([
        'success' => true,
        'site' => $site['name'],
        'post_id' => (int)$found['id'],
        'old_media_id' => $old,
        'new_media_id' => $mediaId,
        'image_url' => $media['source_url'] ?? null,
        'log' => $log,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
