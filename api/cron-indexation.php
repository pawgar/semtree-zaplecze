<?php
/**
 * CRON: skan indeksacji wszystkich stron zapleczowych przez GSC URL Inspection.
 * Zapisuje index_status (per URL) i dzienną migawkę index_snapshots (per strona).
 *
 * Usage (CLI — zalecane, bez limitu HTTP):
 *   php cron-indexation.php --token=SECRET
 * Usage (HTTP — fallback):
 *   curl -s "https://app.com/api/cron-indexation.php?token=SECRET"
 */
set_time_limit(0);
ini_set('memory_limit', '512M');
ignore_user_abort(true);

$isCli = (PHP_SAPI === 'cli');
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/indexation.php';
if (!$isCli) header('Content-Type: application/json');

$db = getDb();
$tokenRow = $db->querySingle("SELECT value FROM settings WHERE key = 'cron_token'", true);
$cronToken = $tokenRow ? trim($tokenRow['value']) : '';

$providedToken = '';
if ($isCli) {
    foreach ($argv ?? [] as $arg) {
        if (preg_match('/^--token=(.+)$/', $arg, $m)) { $providedToken = $m[1]; break; }
    }
} else {
    $providedToken = $_GET['token'] ?? '';
}

if (!$cronToken || $providedToken !== $cronToken) {
    if (!$isCli) http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$gsc = new GscApi();
if (!$gsc->isConnected()) {
    echo json_encode(['error' => 'GSC nie jest połączone.', 'skipped' => true]);
    exit;
}

$log = function (string $m) use ($isCli) {
    $line = '[' . date('H:i:s') . '] ' . $m . "\n";
    if ($isCli) { echo $line; } else { echo "<!-- $line -->"; @ob_flush(); @flush(); }
};

$sites = [];
$r = $db->query('SELECT id, name, url, username, app_password FROM sites ORDER BY id');
while ($row = $r->fetchArray(SQLITE3_ASSOC)) $sites[] = $row;

$log('Start skanu indeksacji — stron: ' . count($sites));
$report = [];
$totalScanned = 0;

foreach ($sites as $site) {
    $log('Skan: ' . $site['name']);
    try {
        $res = scanSiteIndexation($db, $gsc, $site, $log, 1800);
    } catch (Throwable $e) {
        $log('  BŁĄD: ' . $e->getMessage());
        $res = ['scanned' => 0, 'indexed' => 0, 'not_indexed' => 0, 'total' => 0, 'skipped' => 0, 'error' => $e->getMessage()];
    }
    $totalScanned += $res['scanned'];
    $report[$site['name']] = $res;
    $log("  total={$res['total']} zaind={$res['indexed']} niezaind={$res['not_indexed']} sprawdzono={$res['scanned']} pominieto={$res['skipped']}"
        . ($res['error'] ? " ERR: {$res['error']}" : ''));
    usleep(300000); // 0.3s między stronami
}

$log("Koniec — sprawdzono URL-i łącznie: $totalScanned");

echo json_encode([
    'success' => true,
    'sites' => count($sites),
    'scanned' => $totalScanned,
    'report' => $report,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
