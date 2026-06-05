<?php
/**
 * Articles history — all published articles (from publications table).
 * Unlike links history, shows ALL articles regardless of whether they contain links.
 *
 * GET params (all optional):
 *   site_id   — filter by site
 *   user_id   — filter by publisher
 *   date_from — YYYY-MM-DD
 *   date_to   — YYYY-MM-DD
 *   limit     — max rows (default 1000)
 *
 * Returns: { success, total, articles: [{id, created_at, site_id, site_name,
 *            post_url, post_title, user_id, publisher, is_auto}], users, sites }
 */
require_once __DIR__ . '/../auth.php';
header('Content-Type: application/json');
requireLoginApi();

$db = getDb();

$siteId   = (int)($_GET['site_id'] ?? 0);
$userId   = (int)($_GET['user_id'] ?? 0);
$category = trim($_GET['category'] ?? '');
$dateFrom = trim($_GET['date_from'] ?? '');
$dateTo   = trim($_GET['date_to'] ?? '');
$limit    = min(5000, max(1, (int)($_GET['limit'] ?? 1000)));

try {
    // Build WHERE
    $where = [];
    $params = [];
    if ($siteId)   { $where[] = 'p.site_id = :sid'; $params[':sid'] = $siteId; }
    if ($userId)   { $where[] = 'p.user_id = :uid'; $params[':uid'] = $userId; }
    if ($dateFrom) { $where[] = "DATE(p.created_at) >= :df";  $params[':df'] = $dateFrom; }
    if ($dateTo)   { $where[] = "DATE(p.created_at) <= :dt";  $params[':dt'] = $dateTo; }
    // Filtr kategorii — pole sites.categories jest comma-separated.
    // Normalizujemy do ",a,b,c," i szukamy ",<kategoria>," (LOWER po obu stronach).
    if ($category) {
        $where[] = "LOWER(',' || REPLACE(s.categories, ', ', ',') || ',') LIKE :catlike";
        $params[':catlike'] = '%,' . strtolower($category) . ',%';
    }
    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    // Total (unfiltered by limit)
    $countSql = "SELECT COUNT(*) FROM publications p LEFT JOIN sites s ON s.id = p.site_id $whereSql";
    $countStmt = $db->prepare($countSql);
    foreach ($params as $k => $v) $countStmt->bindValue($k, $v);
    $total = (int)($countStmt->execute()->fetchArray(SQLITE3_NUM)[0] ?? 0);

    // Detect auto-publish: post_url present in auto_publish_queue with status=published
    $hasApTable = $db->querySingle("SELECT name FROM sqlite_master WHERE type='table' AND name='auto_publish_queue'");

    $sql = "
        SELECT p.id, p.created_at, p.site_id, p.user_id, p.post_url, p.post_title,
               s.name AS site_name,
               u.username AS publisher" .
            ($hasApTable
                ? ", (SELECT COUNT(*) FROM auto_publish_queue apq WHERE apq.published_url = p.post_url AND apq.status='published') AS is_auto"
                : ", 0 AS is_auto") . "
        FROM publications p
        LEFT JOIN sites s ON s.id = p.site_id
        LEFT JOIN users u ON u.id = p.user_id
        $whereSql
        ORDER BY p.created_at DESC
        LIMIT :lim
    ";
    $stmt = $db->prepare($sql);
    foreach ($params as $k => $v) $stmt->bindValue($k, $v);
    $stmt->bindValue(':lim', $limit, SQLITE3_INTEGER);
    $res = $stmt->execute();

    $articles = [];
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $isAuto = (int)($row['is_auto'] ?? 0) > 0;
        $articles[] = [
            'id'         => (int)$row['id'],
            'created_at' => $row['created_at'],
            'site_id'    => (int)$row['site_id'],
            'site_name'  => $row['site_name'] ?: '(usunięta strona)',
            'post_url'   => $row['post_url'],
            'post_title' => $row['post_title'],
            'user_id'    => (int)$row['user_id'],
            'publisher'  => $isAuto ? 'Auto-publikacja' : ($row['publisher'] ?: '—'),
            'is_auto'    => $isAuto,
        ];
    }

    // Filter dropdowns data: sites with publications + users with publications
    $sites = [];
    $sres = $db->query("SELECT DISTINCT s.id, s.name FROM publications p JOIN sites s ON s.id = p.site_id ORDER BY s.name");
    while ($r = $sres->fetchArray(SQLITE3_ASSOC)) $sites[] = ['id' => (int)$r['id'], 'name' => $r['name']];

    $users = [];
    $ures = $db->query("SELECT DISTINCT u.id, u.username FROM publications p JOIN users u ON u.id = p.user_id ORDER BY u.username");
    while ($r = $ures->fetchArray(SQLITE3_ASSOC)) $users[] = ['id' => (int)$r['id'], 'username' => $r['username']];

    // Unique kategorie ze stron, ktore maja publikacje (do dropdownu filtra)
    $categories = [];
    $cres = $db->query("
        SELECT DISTINCT s.categories
        FROM publications p
        JOIN sites s ON s.id = p.site_id
        WHERE s.categories IS NOT NULL AND s.categories <> ''
    ");
    $catSet = [];
    while ($r = $cres->fetchArray(SQLITE3_ASSOC)) {
        foreach (explode(',', (string)$r['categories']) as $c) {
            $c = trim($c);
            if ($c !== '') $catSet[$c] = true;
        }
    }
    $categories = array_keys($catSet);
    sort($categories, SORT_NATURAL | SORT_FLAG_CASE);

    echo json_encode([
        'success'    => true,
        'total'      => $total,
        'articles'   => $articles,
        'sites'      => $sites,
        'users'      => $users,
        'categories' => $categories,
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
