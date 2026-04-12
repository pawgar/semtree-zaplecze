<?php
/**
 * GSC data endpoint — returns cached or fresh GSC data.
 *
 * GET params:
 *   action: 'dashboard' | 'site-detail' | 'report'
 *   site_url: (for site-detail) the app site URL
 *   range: '7d' | '28d' | '3m' | '6m' | '12m' (default: 28d)
 *   force: '1' to bypass cache
 */
set_time_limit(120);
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/gsc_api.php';
header('Content-Type: application/json');
requireLoginApi();

$action = $_GET['action'] ?? '';
$range = $_GET['range'] ?? '28d';
$force = ($_GET['force'] ?? '0') === '1';

$gsc = new GscApi();
if (!$gsc->isConnected()) {
    echo json_encode(['error' => 'GSC nie jest połączone. Skonfiguruj w ustawieniach.']);
    exit;
}

// Calculate date range
[$dateFrom, $dateTo] = getDateRange($range);
// Previous period for comparison
[$prevFrom, $prevTo] = getPrevDateRange($dateFrom, $dateTo);

$ttl = $force ? 0 : 21600; // 6h or force refresh

try {
    switch ($action) {
        case 'dashboard':
            echo json_encode(getDashboardData($gsc, $dateFrom, $dateTo, $prevFrom, $prevTo, $ttl));
            break;

        case 'site-detail':
            $siteUrl = $_GET['site_url'] ?? '';
            if (!$siteUrl) {
                echo json_encode(['error' => 'Brak site_url']);
                exit;
            }
            echo json_encode(getSiteDetailData($gsc, $siteUrl, $dateFrom, $dateTo, $prevFrom, $prevTo, $ttl));
            break;

        case 'report':
            echo json_encode(getReportData($gsc, $dateFrom, $dateTo, $prevFrom, $prevTo, $ttl));
            break;

        case 'refresh':
            $siteUrl = $_GET['site_url'] ?? '';
            refreshGscData($gsc, $siteUrl);
            echo json_encode(['success' => true, 'message' => 'Dane GSC odświeżone.']);
            break;

        default:
            echo json_encode(['error' => 'Nieznana akcja: ' . $action]);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

// ── Dashboard: summary cards + per-site table ─────────────

function getDashboardData(GscApi $gsc, string $dateFrom, string $dateTo, string $prevFrom, string $prevTo, int $ttl): array {
    $db = getDb();

    // Read GSC data directly from sites table — NO API calls
    $sites = [];
    $result = $db->query('SELECT id, name, url, gsc_clicks, gsc_impressions, gsc_clicks_change, gsc_impressions_change, gsc_keywords_count FROM sites ORDER BY name');
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $sites[] = $row;
    }

    $totalClicks = 0;
    $totalImpressions = 0;
    $totalKeywords = 0;
    $siteData = [];

    foreach ($sites as $site) {
        if ($site['gsc_clicks'] === null && $site['gsc_impressions'] === null) continue;

        $clicks = (int)($site['gsc_clicks'] ?? 0);
        $impressions = (int)($site['gsc_impressions'] ?? 0);
        $kwCount = (int)($site['gsc_keywords_count'] ?? 0);

        $totalClicks += $clicks;
        $totalImpressions += $impressions;
        $totalKeywords += $kwCount;

        $siteData[] = [
            'site_id' => $site['id'],
            'name' => $site['name'],
            'url' => $site['url'],
            'clicks' => $clicks,
            'impressions' => $impressions,
            'clicks_change' => $site['gsc_clicks_change'],
            'impressions_change' => $site['gsc_impressions_change'],
            'keywords_count' => $kwCount,
        ];
    }

    // Calculate total changes from site-level data
    $totalClicksChange = 0;
    $totalImpressionsChange = 0;
    if (!empty($siteData)) {
        // Weighted average of changes based on current values
        $totalClicksChange = $totalClicks > 0
            ? array_sum(array_map(fn($s) => ($s['clicks_change'] ?? 0) * $s['clicks'], $siteData)) / $totalClicks
            : 0;
        $totalImpressionsChange = $totalImpressions > 0
            ? array_sum(array_map(fn($s) => ($s['impressions_change'] ?? 0) * $s['impressions'], $siteData)) / $totalImpressions
            : 0;
    }

    return [
        'success' => true,
        'totals' => [
            'clicks' => $totalClicks,
            'impressions' => $totalImpressions,
            'keywords' => $totalKeywords,
            'clicks_change' => round($totalClicksChange, 1),
            'impressions_change' => round($totalImpressionsChange, 1),
        ],
        'sites' => $siteData,
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
    ];
}

// ── Site detail: summary, daily chart, top kw, top pages ──

function getSiteDetailData(GscApi $gsc, string $appSiteUrl, string $dateFrom, string $dateTo, string $prevFrom, string $prevTo, int $ttl): array {
    $gscUrl = $gsc->matchSiteToProperty($appSiteUrl);
    if (!$gscUrl) {
        return ['error' => 'Strona nie znaleziona w GSC: ' . $appSiteUrl];
    }

    $summary = $gsc->getCachedOrFetch($gscUrl, 'summary', $dateFrom, $dateTo, $ttl);
    $prevSummary = $gsc->getCachedOrFetch($gscUrl, 'summary', $prevFrom, $prevTo, $ttl);
    $daily = $gsc->getCachedOrFetch($gscUrl, 'daily', $dateFrom, $dateTo, $ttl);
    $keywords = $gsc->getCachedOrFetch($gscUrl, 'keywords', $dateFrom, $dateTo, $ttl);
    $pages = $gsc->getCachedOrFetch($gscUrl, 'pages', $dateFrom, $dateTo, $ttl);

    return [
        'success' => true,
        'gsc_url' => $gscUrl,
        'summary' => $summary,
        'prev_summary' => $prevSummary,
        'daily' => $daily,
        'keywords' => $keywords,
        'pages' => $pages,
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
    ];
}

// ── Report: all sites aggregated ──────────────────────────

function getReportData(GscApi $gsc, string $dateFrom, string $dateTo, string $prevFrom, string $prevTo, int $ttl): array {
    $db = getDb();
    $sites = [];
    $result = $db->query('SELECT id, name, url FROM sites ORDER BY name');
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $sites[] = $row;
    }

    $siteData = [];
    foreach ($sites as $site) {
        try {
            $gscUrl = $gsc->matchSiteToProperty($site['url']);
            if (!$gscUrl) continue;

            $summary = $gsc->getCachedOrFetch($gscUrl, 'summary', $dateFrom, $dateTo, $ttl);
            $prevSummary = $gsc->getCachedOrFetch($gscUrl, 'summary', $prevFrom, $prevTo, $ttl);
            $daily = $gsc->getCachedOrFetch($gscUrl, 'daily', $dateFrom, $dateTo, $ttl);

            $siteData[] = [
                'site_id' => $site['id'],
                'name' => $site['name'],
                'url' => $site['url'],
                'gsc_url' => $gscUrl,
                'clicks' => $summary['clicks'] ?? 0,
                'impressions' => $summary['impressions'] ?? 0,
                'ctr' => $summary['ctr'] ?? 0,
                'position' => $summary['position'] ?? 0,
                'clicks_change' => calcChange($summary['clicks'] ?? 0, $prevSummary['clicks'] ?? 0),
                'impressions_change' => calcChange($summary['impressions'] ?? 0, $prevSummary['impressions'] ?? 0),
                'daily' => $daily,
            ];
        } catch (Exception $e) {
            // Skip sites with permission errors or API issues
            continue;
        }
    }

    return [
        'success' => true,
        'sites' => $siteData,
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
    ];
}

// ── Refresh: re-fetch from GSC API and store in sites table ──

function refreshGscData(GscApi $gsc, string $siteUrl = ''): void {
    $db = getDb();
    $dateTo = date('Y-m-d', strtotime('-3 days'));
    $dateFrom = date('Y-m-d', strtotime('-31 days'));
    $prevDays = (strtotime($dateTo) - strtotime($dateFrom)) / 86400;
    $prevTo = date('Y-m-d', strtotime($dateFrom) - 86400);
    $prevFrom = date('Y-m-d', strtotime($prevTo) - ($prevDays * 86400));

    if ($siteUrl) {
        // Refresh single site
        $stmt = $db->prepare('SELECT id, url FROM sites WHERE url = :url');
        $stmt->bindValue(':url', $siteUrl, SQLITE3_TEXT);
        $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
        if ($row) {
            refreshAndStoreSiteGsc($gsc, $db, $row, $dateFrom, $dateTo, $prevFrom, $prevTo);
        }
    } else {
        // Refresh all sites
        GscApi::invalidateCache();
        $sites = [];
        $result = $db->query('SELECT id, url FROM sites ORDER BY id');
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $sites[] = $row;
        }
        foreach ($sites as $site) {
            refreshAndStoreSiteGsc($gsc, $db, $site, $dateFrom, $dateTo, $prevFrom, $prevTo);
            usleep(300000); // 0.3s rate limit
        }
    }
}

function refreshAndStoreSiteGsc(GscApi $gsc, SQLite3 $db, array $site, string $dateFrom, string $dateTo, string $prevFrom, string $prevTo): void {
    try {
        $gscUrl = $gsc->matchSiteToProperty($site['url']);
        if (!$gscUrl) return;

        $summary = $gsc->getCachedOrFetch($gscUrl, 'summary', $dateFrom, $dateTo, 0);
        $prevSummary = $gsc->getCachedOrFetch($gscUrl, 'summary', $prevFrom, $prevTo, 0);
        $keywords = $gsc->getCachedOrFetch($gscUrl, 'keywords', $dateFrom, $dateTo, 0);

        // Also refresh daily and pages cache for site-detail/report
        $gsc->getCachedOrFetch($gscUrl, 'daily', $dateFrom, $dateTo, 0);
        $gsc->getCachedOrFetch($gscUrl, 'pages', $dateFrom, $dateTo, 0);

        $clicks = (int)($summary['clicks'] ?? 0);
        $impressions = (int)($summary['impressions'] ?? 0);
        $clicksChange = calcChange($clicks, (int)($prevSummary['clicks'] ?? 0));
        $impressionsChange = calcChange($impressions, (int)($prevSummary['impressions'] ?? 0));
        $kwCount = count($keywords);

        $stmt = $db->prepare('UPDATE sites SET gsc_clicks = :c, gsc_impressions = :i, gsc_clicks_change = :cc, gsc_impressions_change = :ic, gsc_keywords_count = :kw, gsc_last_update = datetime("now") WHERE id = :id');
        $stmt->bindValue(':c', $clicks, SQLITE3_INTEGER);
        $stmt->bindValue(':i', $impressions, SQLITE3_INTEGER);
        $stmt->bindValue(':cc', $clicksChange, SQLITE3_FLOAT);
        $stmt->bindValue(':ic', $impressionsChange, SQLITE3_FLOAT);
        $stmt->bindValue(':kw', $kwCount, SQLITE3_INTEGER);
        $stmt->bindValue(':id', $site['id'], SQLITE3_INTEGER);
        $stmt->execute();
    } catch (Exception $e) {
        // Skip sites with permission errors
    }
}

// ── Helpers ───────────────────────────────────────────────

function getDateRange(string $range): array {
    $dateTo = date('Y-m-d', strtotime('-3 days')); // GSC data has ~3 day delay
    $dateFrom = match ($range) {
        '7d' => date('Y-m-d', strtotime('-10 days')),
        '28d' => date('Y-m-d', strtotime('-31 days')),
        '3m' => date('Y-m-d', strtotime('-93 days')),
        '6m' => date('Y-m-d', strtotime('-183 days')),
        '12m' => date('Y-m-d', strtotime('-368 days')),
        default => date('Y-m-d', strtotime('-31 days')),
    };
    return [$dateFrom, $dateTo];
}

function getPrevDateRange(string $dateFrom, string $dateTo): array {
    $days = (strtotime($dateTo) - strtotime($dateFrom)) / 86400;
    $prevTo = date('Y-m-d', strtotime($dateFrom) - 86400);
    $prevFrom = date('Y-m-d', strtotime($prevTo) - ($days * 86400));
    return [$prevFrom, $prevTo];
}

function calcChange(int|float $current, int|float $previous): float {
    if ($previous == 0) return $current > 0 ? 100.0 : 0.0;
    return round((($current - $previous) / $previous) * 100, 1);
}
