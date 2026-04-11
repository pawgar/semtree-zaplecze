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
    $sites = [];
    $result = $db->query('SELECT id, name, url FROM sites ORDER BY name');
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $sites[] = $row;
    }

    $totalClicks = 0;
    $totalImpressions = 0;
    $totalClicksPrev = 0;
    $totalImpressionsPrev = 0;
    $siteData = [];
    $totalKeywords = 0;

    foreach ($sites as $site) {
        try {
            $gscUrl = $gsc->matchSiteToProperty($site['url']);
            if (!$gscUrl) continue;

            $summary = $gsc->getCachedOrFetch($gscUrl, 'summary', $dateFrom, $dateTo, $ttl);
            $prevSummary = $gsc->getCachedOrFetch($gscUrl, 'summary', $prevFrom, $prevTo, $ttl);
            $keywords = $gsc->getCachedOrFetch($gscUrl, 'keywords', $dateFrom, $dateTo, $ttl);

            $totalClicks += $summary['clicks'] ?? 0;
            $totalImpressions += $summary['impressions'] ?? 0;
            $totalClicksPrev += $prevSummary['clicks'] ?? 0;
            $totalImpressionsPrev += $prevSummary['impressions'] ?? 0;
            $totalKeywords += count($keywords);

            $clicksChange = calcChange($summary['clicks'] ?? 0, $prevSummary['clicks'] ?? 0);
            $impressionsChange = calcChange($summary['impressions'] ?? 0, $prevSummary['impressions'] ?? 0);

            $siteData[] = [
                'site_id' => $site['id'],
                'name' => $site['name'],
                'url' => $site['url'],
                'gsc_url' => $gscUrl,
                'clicks' => $summary['clicks'] ?? 0,
                'impressions' => $summary['impressions'] ?? 0,
                'ctr' => $summary['ctr'] ?? 0,
                'position' => $summary['position'] ?? 0,
                'clicks_change' => $clicksChange,
                'impressions_change' => $impressionsChange,
                'keywords_count' => count($keywords),
            ];
        } catch (Exception $e) {
            // Skip sites with permission errors or API issues
            continue;
        }
    }

    return [
        'success' => true,
        'totals' => [
            'clicks' => $totalClicks,
            'impressions' => $totalImpressions,
            'keywords' => $totalKeywords,
            'clicks_change' => calcChange($totalClicks, $totalClicksPrev),
            'impressions_change' => calcChange($totalImpressions, $totalImpressionsPrev),
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

// ── Refresh: invalidate cache and re-fetch ────────────────

function refreshGscData(GscApi $gsc, string $siteUrl = ''): void {
    if ($siteUrl) {
        $gscUrl = $gsc->matchSiteToProperty($siteUrl);
        if ($gscUrl) {
            GscApi::invalidateCache($gscUrl);
        }
    } else {
        GscApi::invalidateCache();
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
