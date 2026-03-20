<?php
/**
 * Remove a link to a client domain from a WordPress post.
 * The post stays — only the <a> tag is unwrapped (replaced with its text).
 *
 * POST: { link_id: int }
 */
set_time_limit(60);
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/wp_api.php';
require_once __DIR__ . '/../includes/link_extractor.php';
header('Content-Type: application/json');
requireLoginApi();

$input = json_decode(file_get_contents('php://input'), true);
$linkId = (int) ($input['link_id'] ?? 0);

if (!$linkId) {
    http_response_code(400);
    echo json_encode(['error' => 'Brak link_id']);
    exit;
}

$db = getDb();

$stmt = $db->prepare('
    SELECT l.id, l.site_id, l.post_url, l.target_url,
           s.url AS site_url, s.username, s.app_password,
           c.domain AS client_domain
    FROM links l
    JOIN sites s ON s.id = l.site_id
    LEFT JOIN clients c ON c.id = l.client_id
    WHERE l.id = :id
');
$stmt->bindValue(':id', $linkId, SQLITE3_INTEGER);
$link = $stmt->execute()->fetchArray(SQLITE3_ASSOC);

if (!$link) {
    http_response_code(404);
    echo json_encode(['error' => 'Link nie znaleziony']);
    exit;
}

try {
    $api = new WpApi($link['site_url'], $link['username'], $link['app_password']);

    // Extract slug from post URL
    $slug = extractSlugFromUrl($link['post_url']);
    $post = $slug ? $api->findPostBySlug($slug) : null;

    if (!$post) {
        // Post not found on WP — just remove from local DB
        deleteLocalLink($db, $linkId);
        echo json_encode([
            'success' => true,
            'link_id' => $linkId,
            'warning' => 'Post nie znaleziony na WP — usunieto tylko z bazy',
        ]);
        exit;
    }

    $postId = (int) $post['id'];
    $content = $post['content']['raw'] ?? ($post['content']['rendered'] ?? '');

    if (empty(trim($content))) {
        deleteLocalLink($db, $linkId);
        echo json_encode([
            'success' => true,
            'link_id' => $linkId,
            'warning' => 'Post pusty — usunieto tylko z bazy',
        ]);
        exit;
    }

    // Strip <a> tags pointing to target URL or client domain
    $domain = $link['client_domain'] ? normalizeDomain($link['client_domain']) : '';
    $newContent = stripLinksFromHtml($content, $link['target_url'], $domain);
    $changed = ($newContent !== $content);

    if ($changed) {
        $api->updatePost($postId, ['content' => $newContent]);
    }

    deleteLocalLink($db, $linkId);

    echo json_encode([
        'success' => true,
        'link_id' => $linkId,
        'post_id' => $postId,
        'content_changed' => $changed,
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage(), 'link_id' => $linkId]);
}

// ─── Helpers ───

function extractSlugFromUrl(string $url): string {
    $path = parse_url($url, PHP_URL_PATH) ?: '';
    $path = rtrim($path, '/');
    $slug = basename($path);
    // Strip .html/.htm extension if present
    $slug = preg_replace('/\.(html?|php)$/i', '', $slug);
    return $slug;
}

function stripLinksFromHtml(string $html, string $targetUrl, string $targetDomain): string {
    if (empty($targetDomain) && empty($targetUrl)) return $html;

    // Use regex approach to avoid DOMDocument mangling WordPress block markup
    // Pattern: <a ...href="..."...>text</a>
    $result = preg_replace_callback(
        '/<a\s[^>]*href=["\']([^"\']*)["\'][^>]*>(.*?)<\/a>/si',
        function ($m) use ($targetUrl, $targetDomain) {
            $href = $m[1];
            $text = $m[2];

            $parsed = @parse_url($href);
            if (!$parsed || empty($parsed['host'])) return $m[0]; // keep

            $linkDomain = strtolower(preg_replace('/^www\./', '', $parsed['host']));

            // Match by exact target URL
            if ($targetUrl && strcasecmp(rtrim($href, '/'), rtrim($targetUrl, '/')) === 0) {
                return $text;
            }
            // Match by domain
            if ($targetDomain && ($linkDomain === $targetDomain || str_ends_with($linkDomain, '.' . $targetDomain))) {
                return $text;
            }

            return $m[0]; // keep
        },
        $html
    );

    return $result ?? $html;
}

function deleteLocalLink(SQLite3 $db, int $linkId): void {
    $stmt = $db->prepare('DELETE FROM links WHERE id = :id');
    $stmt->bindValue(':id', $linkId, SQLITE3_INTEGER);
    $stmt->execute();
}
