<?php
/**
 * Cron endpoint: refresh GSC data for all sites.
 * Fetches from GSC API and stores metrics in sites table for instant dashboard loading.
 * Call via cron, e.g.:
 *   0 6 * * * curl -s "https://your-app.com/api/cron-gsc.php?token=YOUR_SECRET"
 */
set_time_limit(600);
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/gsc_api.php';
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

$gsc = new GscApi();
if (!$gsc->isConnected()) {
    echo json_encode(['error' => 'GSC nie jest połączone.', 'skipped' => true]);
    exit;
}

// Clear all cache and refetch for 28d range
GscApi::invalidateCache();

$dateTo = date('Y-m-d', strtotime('-3 days'));
$dateFrom = date('Y-m-d', strtotime('-31 days'));
$prevDays = (strtotime($dateTo) - strtotime($dateFrom)) / 86400;
$prevTo = date('Y-m-d', strtotime($dateFrom) - 86400);
$prevFrom = date('Y-m-d', strtotime($prevTo) - ($prevDays * 86400));

$sites = [];
$result = $db->query('SELECT id, name, url FROM sites ORDER BY id');
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $sites[] = $row;
}

$refreshed = 0;
$errors = [];

foreach ($sites as $site) {
    try {
        $gscUrl = $gsc->matchSiteToProperty($site['url']);
        if (!$gscUrl) continue;

        // Fetch and cache all metric types
        $summary = $gsc->getCachedOrFetch($gscUrl, 'summary', $dateFrom, $dateTo, 0);
        $prevSummary = $gsc->getCachedOrFetch($gscUrl, 'summary', $prevFrom, $prevTo, 0);
        $keywords = $gsc->getCachedOrFetch($gscUrl, 'keywords', $dateFrom, $dateTo, 0);
        $gsc->getCachedOrFetch($gscUrl, 'pages', $dateFrom, $dateTo, 0);
        $gsc->getCachedOrFetch($gscUrl, 'daily', $dateFrom, $dateTo, 0);

        // Store metrics in sites table for instant dashboard loading
        $clicks = (int)($summary['clicks'] ?? 0);
        $impressions = (int)($summary['impressions'] ?? 0);
        $clicksChange = ($prevSummary['clicks'] ?? 0) == 0
            ? ($clicks > 0 ? 100.0 : 0.0)
            : round((($clicks - ($prevSummary['clicks'] ?? 0)) / ($prevSummary['clicks'] ?? 1)) * 100, 1);
        $impressionsChange = ($prevSummary['impressions'] ?? 0) == 0
            ? ($impressions > 0 ? 100.0 : 0.0)
            : round((($impressions - ($prevSummary['impressions'] ?? 0)) / ($prevSummary['impressions'] ?? 1)) * 100, 1);

        $stmt = $db->prepare('UPDATE sites SET gsc_clicks = :c, gsc_impressions = :i, gsc_clicks_change = :cc, gsc_impressions_change = :ic, gsc_keywords_count = :kw, gsc_last_update = datetime("now") WHERE id = :id');
        $stmt->bindValue(':c', $clicks, SQLITE3_INTEGER);
        $stmt->bindValue(':i', $impressions, SQLITE3_INTEGER);
        $stmt->bindValue(':cc', $clicksChange, SQLITE3_FLOAT);
        $stmt->bindValue(':ic', $impressionsChange, SQLITE3_FLOAT);
        $stmt->bindValue(':kw', count($keywords), SQLITE3_INTEGER);
        $stmt->bindValue(':id', $site['id'], SQLITE3_INTEGER);
        $stmt->execute();

        $refreshed++;
        usleep(300000); // 0.3s between sites to avoid rate limits
    } catch (Exception $e) {
        $errors[] = $site['name'] . ': ' . $e->getMessage();
    }
}

echo json_encode([
    'success' => true,
    'refreshed' => $refreshed,
    'total_sites' => count($sites),
    'errors' => $errors,
]);
