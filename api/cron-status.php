<?php
/**
 * Cron endpoint: refresh all site statuses.
 * Call via cron or scheduled task, e.g.:
 *   0 23 * * * curl -s https://your-app.com/api/cron-status.php?token=YOUR_SECRET
 *
 * Requires a cron_token setting in the database for security.
 */
set_time_limit(600);
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/wp_api.php';
header('Content-Type: application/json');

// Authenticate via token
$db = getDb();
$tokenStmt = $db->prepare('SELECT value FROM settings WHERE key = "cron_token"');
$tokenRow = $tokenStmt->execute()->fetchArray(SQLITE3_ASSOC);
$cronToken = $tokenRow ? trim($tokenRow['value']) : '';

$providedToken = $_GET['token'] ?? '';

if (!$cronToken || $providedToken !== $cronToken) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// Get all sites
$sites = [];
$result = $db->query('SELECT id, url, username, app_password FROM sites ORDER BY id');
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $sites[] = $row;
}

$results = [];

foreach ($sites as $site) {
    $status = [
        'id' => $site['id'],
        'http_status' => 0,
        'post_count' => null,
        'api_ok' => false,
    ];

    // HTTP status
    $status['http_status'] = WpApi::getHttpStatus($site['url']);

    // API test + post count
    try {
        $api = new WpApi($site['url'], $site['username'], $site['app_password']);
        $api->testConnection();
        $status['api_ok'] = true;
        $status['post_count'] = $api->getPostCount();
    } catch (Exception $e) {
        // keep defaults
    }

    // Persist
    $upd = $db->prepare('UPDATE sites SET post_count = :pc, http_status = :hs, api_ok = :ao, last_status_check = datetime("now") WHERE id = :id');
    $upd->bindValue(':pc', $status['post_count'], $status['post_count'] !== null ? SQLITE3_INTEGER : SQLITE3_NULL);
    $upd->bindValue(':hs', $status['http_status'], SQLITE3_INTEGER);
    $upd->bindValue(':ao', $status['api_ok'] ? 1 : 0, SQLITE3_INTEGER);
    $upd->bindValue(':id', $site['id'], SQLITE3_INTEGER);
    $upd->execute();

    $results[] = $status;

    // Small delay between sites to avoid overwhelming
    usleep(500000); // 0.5s
}

echo json_encode([
    'success' => true,
    'checked' => count($results),
    'ok' => count(array_filter($results, fn($r) => $r['api_ok'])),
    'failed' => count(array_filter($results, fn($r) => !$r['api_ok'])),
]);
