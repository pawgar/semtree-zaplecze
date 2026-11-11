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

$dateTo = date('Y-m-d', strtotime('-2 days')); // GSC data delay ~2 days (stable)
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
    if (gscRefreshSiteMetrics($gsc, $db, $site, $dateFrom, $dateTo, $prevFrom, $prevTo)) {
        $refreshed++;
    } else {
        $errors[] = $site['name'] . ': ' . $db->querySingle('SELECT gsc_error FROM sites WHERE id = ' . (int)$site['id']);
    }
    usleep(300000); // 0.3s between sites to avoid rate limits
}

echo json_encode([
    'success' => true,
    'refreshed' => $refreshed,
    'total_sites' => count($sites),
    'errors' => $errors,
]);
