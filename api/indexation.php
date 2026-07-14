<?php
/**
 * API zakładki Indeksacja.
 *   overview     GET  — per domena: total / zaindeksowane / niezaindeksowane / % / ostatni skan
 *   timeseries   GET  — dzienne migawki zbiorczo (wykres)
 *   site-detail  GET  — lista niezaindeksowanych URL-i danej strony
 *   refresh      POST — {site_id} skan jednej strony teraz (on-demand)
 *   export       GET  — CSV niezaindeksowanych (site_ids=all|csv)
 *   submit-index POST — {site_ids:[...]} zgłoś niezaindeksowane do Rapid Indexer (jeden projekt)
 */
set_time_limit(600);
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/indexation.php';
requireLoginApi();

$action = $_GET['action'] ?? '';
$db = getDb();

// ── EXPORT CSV (własne nagłówki, przed application/json) ──────
if ($action === 'export') {
    $sitesParam = trim($_GET['site_ids'] ?? 'all');
    $where = 'ist.is_indexed = 0';
    if ($sitesParam !== '' && $sitesParam !== 'all') {
        $ids = array_values(array_filter(array_map('intval', explode(',', $sitesParam)), fn($x) => $x > 0));
        if (!empty($ids)) {
            $where .= ' AND ist.site_id IN (' . implode(',', $ids) . ')';
        }
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="niezaindeksowane-' . date('Y-m-d') . '.csv"');
    echo "\xEF\xBB\xBF"; // UTF-8 BOM (Excel PL)
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Domena', 'URL', 'Stan (GSC)', 'Ostatni crawl', 'Zgłoszony do indexera'], ';');
    $res = $db->query("SELECT s.name domena, ist.url, ist.coverage_state, ist.last_crawl, ist.submitted_at
                       FROM index_status ist JOIN sites s ON s.id = ist.site_id
                       WHERE $where ORDER BY s.name, ist.url");
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        fputcsv($out, [
            $row['domena'],
            $row['url'],
            $row['coverage_state'] ?: '',
            $row['last_crawl'] ?: '',
            $row['submitted_at'] ?: '',
        ], ';');
    }
    fclose($out);
    exit;
}

header('Content-Type: application/json');

// ── OVERVIEW ─────────────────────────────────────────────────
if ($action === 'overview') {
    $sites = [];
    $totals = ['total' => 0, 'indexed' => 0, 'not_indexed' => 0];
    $res = $db->query('SELECT s.id site_id, s.name, s.url,
            COUNT(ist.id) total_cnt,
            COALESCE(SUM(ist.is_indexed), 0) indexed_cnt,
            SUM(CASE WHEN ist.is_indexed = 0 AND ist.submitted_at IS NOT NULL THEN 1 ELSE 0 END) submitted_pending,
            MAX(ist.checked_at) last_check
        FROM sites s
        LEFT JOIN index_status ist ON ist.site_id = s.id
        GROUP BY s.id ORDER BY s.name');
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $total = (int) $row['total_cnt'];
        $indexed = (int) $row['indexed_cnt'];
        $notIndexed = $total - $indexed;
        $sites[] = [
            'site_id' => (int) $row['site_id'],
            'name' => $row['name'],
            'url' => $row['url'],
            'total' => $total,
            'indexed' => $indexed,
            'not_indexed' => $notIndexed,
            'pct' => $total > 0 ? round($indexed / $total * 100, 1) : null,
            'submitted_pending' => (int) $row['submitted_pending'],
            'last_check' => $row['last_check'],
        ];
        $totals['total'] += $total;
        $totals['indexed'] += $indexed;
        $totals['not_indexed'] += $notIndexed;
    }
    $totals['pct'] = $totals['total'] > 0 ? round($totals['indexed'] / $totals['total'] * 100, 1) : null;
    echo json_encode(['success' => true, 'sites' => $sites, 'totals' => $totals], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── TIMESERIES (zbiorczo, ostatnie 90 dni) ───────────────────
if ($action === 'timeseries') {
    $series = [];
    $res = $db->query("SELECT snap_date, SUM(total) total_cnt, SUM(\"indexed\") indexed_cnt, SUM(not_indexed) not_indexed
                       FROM index_snapshots
                       WHERE snap_date >= date('now', '-90 days')
                       GROUP BY snap_date ORDER BY snap_date");
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $series[] = [
            'date' => $row['snap_date'],
            'total' => (int) $row['total_cnt'],
            'indexed' => (int) $row['indexed_cnt'],
            'not_indexed' => (int) $row['not_indexed'],
        ];
    }
    echo json_encode(['success' => true, 'series' => $series], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── SITE DETAIL (niezaindeksowane URL-e strony) ──────────────
if ($action === 'site-detail') {
    $siteId = (int) ($_GET['site_id'] ?? 0);
    if ($siteId <= 0) { echo json_encode(['error' => 'Brak site_id']); exit; }
    $name = $db->querySingle("SELECT name FROM sites WHERE id = $siteId") ?: '';
    $urls = [];
    $stmt = $db->prepare('SELECT url, verdict, coverage_state, last_crawl, checked_at, submitted_at
                          FROM index_status WHERE site_id = :sid AND is_indexed = 0 ORDER BY url');
    $stmt->bindValue(':sid', $siteId, SQLITE3_INTEGER);
    $r = $stmt->execute();
    while ($row = $r->fetchArray(SQLITE3_ASSOC)) $urls[] = $row;
    echo json_encode(['success' => true, 'site_id' => $siteId, 'name' => $name, 'urls' => $urls], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── REFRESH (skan jednej strony teraz) ───────────────────────
if ($action === 'refresh') {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $siteId = (int) ($input['site_id'] ?? 0);
    if ($siteId <= 0) { echo json_encode(['error' => 'Brak site_id']); exit; }

    $stmt = $db->prepare('SELECT id, name, url, username, app_password FROM sites WHERE id = :sid');
    $stmt->bindValue(':sid', $siteId, SQLITE3_INTEGER);
    $site = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    if (!$site) { echo json_encode(['error' => 'Nie znaleziono strony']); exit; }

    $gsc = new GscApi();
    if (!$gsc->isConnected()) { echo json_encode(['error' => 'GSC nie jest połączone.']); exit; }

    // On-demand: mniejszy limit, by nie przekroczyć timeoutu HTTP serwera
    $res = scanSiteIndexation($db, $gsc, $site, null, 500);
    echo json_encode(array_merge(['success' => true, 'name' => $site['name']], $res), JSON_UNESCAPED_UNICODE);
    exit;
}

// ── SUBMIT-INDEX (zgłoś niezaindeksowane zaznaczonych stron) ──
if ($action === 'submit-index') {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $siteIds = $input['site_ids'] ?? [];
    $siteIds = array_values(array_filter(array_map('intval', is_array($siteIds) ? $siteIds : []), fn($x) => $x > 0));
    if (empty($siteIds)) { echo json_encode(['error' => 'Zaznacz przynajmniej jedną domenę']); exit; }

    $rapidKey = $db->querySingle("SELECT value FROM settings WHERE key = 'rapid_indexer_api_key'", true);
    $rapidKey = $rapidKey ? trim($rapidKey['value']) : '';
    if (!$rapidKey) { echo json_encode(['error' => 'Brak klucza Rapid URL Indexer w ustawieniach']); exit; }

    // Niezaindeksowane, nie zgłaszane w ostatnich 7 dniach (ochrona kredytów)
    $ph = implode(',', array_fill(0, count($siteIds), '?'));
    $sql = "SELECT id, url FROM index_status
            WHERE is_indexed = 0 AND site_id IN ($ph)
              AND (submitted_at IS NULL OR submitted_at < datetime('now', '-7 days'))
            ORDER BY id";
    $stmt = $db->prepare($sql);
    foreach ($siteIds as $i => $sid) $stmt->bindValue($i + 1, $sid, SQLITE3_INTEGER);
    $r = $stmt->execute();
    $ids = [];
    $urls = [];
    while ($row = $r->fetchArray(SQLITE3_ASSOC)) { $ids[] = (int) $row['id']; $urls[] = $row['url']; }

    if (empty($urls)) {
        echo json_encode(['success' => true, 'submitted' => 0, 'message' => 'Brak nowych niezaindeksowanych URL-i do zgłoszenia (mogły być zgłoszone w ostatnich 7 dniach).']);
        exit;
    }

    $projectName = 'Indeksacja zaplecze ' . date('Y-m-d H:i');
    $result = rapidSubmitProject($rapidKey, $projectName, $urls);

    if (!empty($result['success'])) {
        // oznacz jako zgłoszone
        $db->exec('UPDATE index_status SET submitted_at = datetime("now") WHERE id IN (' . implode(',', $ids) . ')');
        echo json_encode([
            'success' => true,
            'submitted' => $result['submitted'],
            'project_id' => $result['project_id'] ?? null,
            'project_name' => $projectName,
        ], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode(['error' => $result['error'] ?? 'Błąd zgłoszenia do Rapid Indexer'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

echo json_encode(['error' => 'Nieznana akcja: ' . $action]);
