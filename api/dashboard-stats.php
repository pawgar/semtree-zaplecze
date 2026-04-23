<?php
/**
 * Dashboard stats — aggregates everything the new dashboard needs in one call.
 *
 * Returns:
 *  - greeting      (username)
 *  - today_pubs    (publications created today by anyone)
 *  - week_pubs     (publications last 7 days)
 *  - success_rate  (auto-publish success % last 30d)
 *  - network       ({ total, http_ok, api_ok })
 *  - gsc           ({ total_clicks, total_impressions, total_keywords,
 *                     clicks_change, impressions_change, daily: [{date, clicks, impressions}, ...] })
 *  - pubs_daily    ([{date, count}, ...] — last 30 days)
 *  - info_boxes    ({ pending_queue, today_auto_pubs, errors, next_cron })
 */
require_once __DIR__ . '/../auth.php';
header('Content-Type: application/json');
requireLoginApi();

$db = getDb();
$username = $_SESSION['username'] ?? 'Użytkowniku';

try {
    // ── Publications counts ──────────────────────────────
    $todayPubs = (int)($db->querySingle("SELECT COUNT(*) FROM publications WHERE DATE(created_at) = DATE('now','localtime')") ?: 0);
    $weekPubs = (int)($db->querySingle("SELECT COUNT(*) FROM publications WHERE created_at >= datetime('now','-7 days')") ?: 0);

    // Daily pubs for last 30 days (for bar chart)
    $pubsDaily = [];
    for ($i = 29; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-$i days"));
        $pubsDaily[$date] = 0;
    }
    $res = $db->query("SELECT DATE(created_at) as d, COUNT(*) as c FROM publications WHERE created_at >= datetime('now','-30 days') GROUP BY DATE(created_at)");
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        if (isset($pubsDaily[$row['d']])) $pubsDaily[$row['d']] = (int)$row['c'];
    }
    $pubsDailyArr = [];
    foreach ($pubsDaily as $d => $c) $pubsDailyArr[] = ['date' => $d, 'count' => $c];

    // ── Links total ───────────────────────────────────────
    $totalLinks = (int)($db->querySingle("SELECT COUNT(*) FROM links") ?: 0);

    // ── CRON activity (last runs + effects) ──────────────
    $cron = [
        'status' => [
            'last_run' => $db->querySingle("SELECT MAX(last_status_check) FROM sites"),
            'next_run' => date('Y-m-d 23:00:00', strtotime(date('H') >= '23' ? 'tomorrow' : 'today')),
            'label' => 'Statusy stron (HTTP + API)',
        ],
        'gsc' => [
            'last_run' => $db->querySingle("SELECT MAX(gsc_last_update) FROM sites"),
            'next_run' => date('Y-m-d 06:00:00', strtotime(date('H') >= '6' ? 'tomorrow' : 'today')),
            'label' => 'Odświeżanie danych Google Search Console',
        ],
        'auto_publish' => [
            'last_run' => $tblExists ? $db->querySingle("SELECT MAX(published_at) FROM auto_publish_queue WHERE status='published'") : null,
            'next_run' => date('Y-m-d 09:00:00', strtotime(date('H') >= '9' ? 'tomorrow' : 'today')),
            'label' => 'Automatyczne publikacje artykułów',
            'published_today' => $infoBoxes['today_auto_pubs'],
            'errors_total' => $infoBoxes['errors'],
        ],
    ];

    // Publications 7-day sparkline for CRON card
    $pubs7 = array_slice($pubsDailyArr, -7);

    // ── Network health ───────────────────────────────────
    $netRow = $db->querySingle("
        SELECT
            COUNT(*) as total,
            SUM(CASE WHEN http_status >= 200 AND http_status < 400 THEN 1 ELSE 0 END) as http_ok,
            SUM(CASE WHEN api_ok = 1 THEN 1 ELSE 0 END) as api_ok
        FROM sites
        WHERE last_status_check IS NOT NULL
    ", true);
    $network = [
        'total' => (int)($netRow['total'] ?? 0),
        'http_ok' => (int)($netRow['http_ok'] ?? 0),
        'api_ok' => (int)($netRow['api_ok'] ?? 0),
        'total_sites' => (int)($db->querySingle("SELECT COUNT(*) FROM sites") ?: 0),
    ];

    // ── GSC totals from sites table ──────────────────────
    $gscRow = $db->querySingle("
        SELECT
            COALESCE(SUM(gsc_clicks),0) as clicks,
            COALESCE(SUM(gsc_impressions),0) as impressions,
            COALESCE(SUM(gsc_keywords_count),0) as keywords
        FROM sites
    ", true);
    $totalClicks = (int)($gscRow['clicks'] ?? 0);
    $totalImpressions = (int)($gscRow['impressions'] ?? 0);
    $totalKeywords = (int)($gscRow['keywords'] ?? 0);

    // Aggregate % change weighted by absolute values (simplified: sum of weighted changes)
    $changeRow = $db->querySingle("
        SELECT
            AVG(gsc_clicks_change) as avg_c,
            AVG(gsc_impressions_change) as avg_i
        FROM sites
        WHERE gsc_clicks IS NOT NULL
    ", true);

    // ── GSC daily series (last 30 days) — aggregate from gsc_cache daily metrics ──
    $gscDaily = [];
    for ($i = 29; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-$i days"));
        $gscDaily[$date] = ['clicks' => 0, 'impressions' => 0];
    }
    $dateFrom = date('Y-m-d', strtotime('-31 days'));
    $dateTo = date('Y-m-d', strtotime('-2 days'));
    $res = $db->query("SELECT data FROM gsc_cache WHERE metric_type = 'daily' AND date_from = '$dateFrom' AND date_to = '$dateTo'");
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $data = json_decode($row['data'], true) ?: [];
        foreach ($data as $day) {
            if (!isset($day['date'])) continue;
            $d = $day['date'];
            if (!isset($gscDaily[$d])) continue;
            $gscDaily[$d]['clicks'] += (int)($day['clicks'] ?? 0);
            $gscDaily[$d]['impressions'] += (int)($day['impressions'] ?? 0);
        }
    }
    $gscDailyArr = [];
    foreach ($gscDaily as $d => $v) $gscDailyArr[] = ['date' => $d, 'clicks' => $v['clicks'], 'impressions' => $v['impressions']];

    // ── Auto-publish info boxes ──────────────────────────
    $infoBoxes = [
        'pending_queue' => 0,
        'today_auto_pubs' => 0,
        'errors' => 0,
        'next_cron' => 'jutro 9:00',
    ];
    // Check if auto_publish_queue table exists
    $tblExists = $db->querySingle("SELECT name FROM sqlite_master WHERE type='table' AND name='auto_publish_queue'");
    if ($tblExists) {
        $infoBoxes['pending_queue'] = (int)($db->querySingle("SELECT COUNT(*) FROM auto_publish_queue WHERE status = 'pending'") ?: 0);
        $infoBoxes['today_auto_pubs'] = (int)($db->querySingle("SELECT COUNT(*) FROM auto_publish_queue WHERE status = 'published' AND DATE(published_at) = DATE('now','localtime')") ?: 0);
        $infoBoxes['errors'] = (int)($db->querySingle("SELECT COUNT(*) FROM auto_publish_queue WHERE status = 'error'") ?: 0);
        // If CRON is running (lock file present), set next_cron = "w trakcie"
        $lockFile = __DIR__ . '/../data/cron-auto-publish.lock';
        if (file_exists($lockFile) && (time() - filemtime($lockFile)) < 7200) {
            $infoBoxes['next_cron'] = 'działa teraz';
        } else {
            // Next 9 AM
            $next = strtotime('tomorrow 09:00:00');
            if (date('H:i') < '09:00') $next = strtotime('today 09:00:00');
            $infoBoxes['next_cron'] = date('H:i', $next) . (date('Y-m-d', $next) === date('Y-m-d') ? ' (dziś)' : ' (jutro)');
        }
    }

    // Success rate: published vs (published+error) in last 30 days
    $successRate = null;
    if ($tblExists) {
        $ok = (int)($db->querySingle("SELECT COUNT(*) FROM auto_publish_queue WHERE status='published' AND published_at >= datetime('now','-30 days')") ?: 0);
        $err = (int)($db->querySingle("SELECT COUNT(*) FROM auto_publish_queue WHERE status='error'") ?: 0);
        if ($ok + $err > 0) $successRate = round($ok * 100 / ($ok + $err), 1);
    }

    echo json_encode([
        'success' => true,
        'greeting' => $username,
        'today_pubs' => $todayPubs,
        'week_pubs' => $weekPubs,
        'success_rate' => $successRate,
        'network' => $network,
        'gsc' => [
            'total_clicks' => $totalClicks,
            'total_impressions' => $totalImpressions,
            'total_keywords' => $totalKeywords,
            'clicks_change' => $changeRow['avg_c'] !== null ? round((float)$changeRow['avg_c'], 1) : null,
            'impressions_change' => $changeRow['avg_i'] !== null ? round((float)$changeRow['avg_i'], 1) : null,
            'daily' => $gscDailyArr,
        ],
        'pubs_daily' => $pubsDailyArr,
        'info_boxes' => $infoBoxes,
        'total_links' => $totalLinks,
        'cron' => $cron,
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
