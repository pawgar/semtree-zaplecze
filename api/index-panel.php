<?php
/**
 * Panel zgłoszeń do indeksacji (zakładka Indeksacja → „Zgłoszenia i mapy witryn”).
 *   GET                      — KPI, ostatnie zgłoszenia ze stanem z GSC, projekty Rapid, mapy witryn per strona
 *   POST {action: sync-sitemaps} — synchronizacja map witryn teraz (admin)
 */
set_time_limit(300);
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/rapid_indexing.php';
header('Content-Type: application/json');
requireLoginApi();

$db = getDb();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireAdminApi();
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    if (($input['action'] ?? '') === 'sync-sitemaps') {
        require_once __DIR__ . '/../includes/indexnow.php';
        $iv = indexNowVerifySites();
        echo json_encode(['success' => true, 'indexnow_verified' => $iv['verified']] + indexSitemapsSync());
        exit;
    }
    http_response_code(400);
    echo json_encode(['error' => 'Nieznana akcja']);
    exit;
}

$q = fn(string $sql) => (int)$db->querySingle($sql);
$credits = null;
try {
    if (indexSetting('rapid_indexer_api_key') !== '') $credits = rapidCredits();
} catch (Throwable $e) {
    $credits = null;
}

$kpi = [
    'credits'       => $credits,
    'queued'        => $q("SELECT COUNT(*) FROM index_queue WHERE status = 'queued'"),
    'submitted_30d' => $q("SELECT COUNT(*) FROM index_queue WHERE status = 'submitted' AND submitted_at > datetime('now', '-30 days')"),
    'indexed_30d'   => $q("SELECT COUNT(*) FROM index_queue WHERE status = 'submitted' AND indexed_at IS NOT NULL AND submitted_at > datetime('now', '-30 days')"),
    'checked_30d'   => $q("SELECT COUNT(*) FROM index_queue WHERE status = 'submitted' AND gsc_checked_at IS NOT NULL AND submitted_at > datetime('now', '-30 days')"),
    'resubmitted'   => $q("SELECT COUNT(*) FROM index_queue WHERE resubmits > 0"),
    // Zgłoszony ponownie i nadal poza indeksem po kolejnych 14 dniach — zgłaszanie nie pomoże
    'quality'       => $q("SELECT COUNT(*) FROM index_queue WHERE status = 'submitted' AND resubmits > 0 AND indexed_at IS NULL
                           AND submitted_at < datetime('now', '-14 days')"),
    'skipped'       => $q("SELECT COUNT(*) FROM index_queue WHERE status = 'skipped'"),
    'indexnow_30d'  => $q("SELECT COUNT(*) FROM index_queue WHERE indexnow_at > datetime('now', '-30 days')"),
    'indexnow_sites'=> $q("SELECT COUNT(*) FROM site_indexnow WHERE verified = 1"),
];

$recent = [];
$r = $db->query("SELECT q.url, q.source, q.status, q.submitted_at, q.created_at, q.available_at, q.gsc_state, q.gsc_checked_at,
        q.indexed_at, q.resubmits, q.last_reason, q.rapid_project_id, q.indexnow_at, s.name AS site
    FROM index_queue q LEFT JOIN sites s ON s.id = q.site_id
    ORDER BY COALESCE(q.submitted_at, q.created_at) DESC LIMIT 300");
while ($row = $r->fetchArray(SQLITE3_ASSOC)) $recent[] = $row;

$projects = [];
$r = $db->query('SELECT * FROM rapid_projects ORDER BY created_at DESC LIMIT 30');
while ($row = $r->fetchArray(SQLITE3_ASSOC)) $projects[] = $row;

$sitemaps = [];
$r = $db->query('SELECT s.id, s.name, st.gsc_property, st.synced_at, st.last_error,
        COUNT(m.path) AS maps, MAX(m.last_submitted) AS last_submitted, MAX(m.last_downloaded) AS last_downloaded,
        COALESCE(SUM(m.errors), 0) AS errors, COALESCE(SUM(m.warnings), 0) AS warnings,
        COALESCE(SUM(m.submitted), 0) AS urls_submitted, COALESCE(SUM(m.indexed), 0) AS urls_indexed, MAX(m.is_pending) AS pending,
        MAX(ix.verified) AS indexnow, MAX(ix.checked_at) AS indexnow_checked
    FROM sites s
    LEFT JOIN site_sitemap_status st ON st.site_id = s.id
    LEFT JOIN site_indexnow ix ON ix.site_id = s.id
    LEFT JOIN site_sitemaps m ON m.site_id = s.id
    GROUP BY s.id ORDER BY s.name');
while ($row = $r->fetchArray(SQLITE3_ASSOC)) $sitemaps[] = $row;

echo json_encode([
    'kpi'      => $kpi,
    'recent'   => $recent,
    'projects' => $projects,
    'sitemaps' => $sitemaps,
], JSON_UNESCAPED_UNICODE);
