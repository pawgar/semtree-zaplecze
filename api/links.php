<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/link_extractor.php';
header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];
requireLoginApi();

$db = getDb();

if ($method === 'GET') {
    // Filterable link list
    $where = ['1=1'];
    $params = [];

    if (!empty($_GET['site_id'])) {
        $where[] = 'l.site_id = :site_id';
        $params[':site_id'] = (int) $_GET['site_id'];
    }
    if (!empty($_GET['client_id'])) {
        $where[] = 'l.client_id = :client_id';
        $params[':client_id'] = (int) $_GET['client_id'];
    }
    if (!empty($_GET['date_from'])) {
        $where[] = 'l.created_at >= :date_from';
        $params[':date_from'] = $_GET['date_from'];
    }
    if (!empty($_GET['date_to'])) {
        $where[] = 'l.created_at <= :date_to';
        $params[':date_to'] = $_GET['date_to'] . ' 23:59:59';
    }

    $limit = min((int) ($_GET['limit'] ?? 500), 2000);
    $offset = (int) ($_GET['offset'] ?? 0);

    $sql = "
        SELECT l.*, s.name AS site_name, s.url AS site_url,
               c.name AS client_name, c.domain AS client_domain, c.color AS client_color
        FROM links l
        LEFT JOIN sites s ON s.id = l.site_id
        LEFT JOIN clients c ON c.id = l.client_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY l.created_at DESC
        LIMIT {$limit} OFFSET {$offset}
    ";

    $stmt = $db->prepare($sql);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v, is_int($v) ? SQLITE3_INTEGER : SQLITE3_TEXT);
    }

    $result = $stmt->execute();
    $links = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $links[] = $row;
    }

    // Also return total count
    $countSql = "SELECT COUNT(*) as cnt FROM links l WHERE " . implode(' AND ', $where);
    $countStmt = $db->prepare($countSql);
    foreach ($params as $k => $v) {
        $countStmt->bindValue($k, $v, is_int($v) ? SQLITE3_INTEGER : SQLITE3_TEXT);
    }
    $total = $countStmt->execute()->fetchArray(SQLITE3_ASSOC)['cnt'];

    echo json_encode(['links' => $links, 'total' => (int) $total]);
    exit;
}

if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);

    // Bulk insert: {links: [...], site_id: N}
    $linksData = $input['links'] ?? [];
    $siteId = (int) ($input['site_id'] ?? 0);

    if (!$siteId || empty($linksData)) {
        http_response_code(400);
        echo json_encode(['error' => 'site_id i links sa wymagane']);
        exit;
    }

    // Load client domains for auto-matching
    $clientDomains = [];
    $clientResult = $db->query('SELECT id, domain FROM clients');
    while ($row = $clientResult->fetchArray(SQLITE3_ASSOC)) {
        $clientDomains[strtolower($row['domain'])] = (int) $row['id'];
    }

    $stmt = $db->prepare('
        INSERT OR IGNORE INTO links (site_id, client_id, post_url, post_title, target_url, anchor_text, link_type)
        VALUES (:site_id, :client_id, :post_url, :post_title, :target_url, :anchor_text, :link_type)
    ');

    $inserted = 0;
    $skipped = 0;

    foreach ($linksData as $link) {
        $targetUrl = trim($link['target_url'] ?? '');
        if (!$targetUrl) { $skipped++; continue; }

        $clientId = matchClientDomain($targetUrl, $clientDomains);

        $stmt->bindValue(':site_id', $siteId, SQLITE3_INTEGER);
        $stmt->bindValue(':client_id', $clientId, $clientId ? SQLITE3_INTEGER : SQLITE3_NULL);
        $stmt->bindValue(':post_url', trim($link['post_url'] ?? ''), SQLITE3_TEXT);
        $stmt->bindValue(':post_title', trim($link['post_title'] ?? ''), SQLITE3_TEXT);
        $stmt->bindValue(':target_url', $targetUrl, SQLITE3_TEXT);
        $stmt->bindValue(':anchor_text', trim($link['anchor_text'] ?? ''), SQLITE3_TEXT);
        $stmt->bindValue(':link_type', trim($link['link_type'] ?? 'dofollow'), SQLITE3_TEXT);
        $stmt->execute();

        if ($db->changes() > 0) {
            $inserted++;
        } else {
            $skipped++;
        }
        $stmt->reset();
    }

    // Re-match any unmatched links against current client domains
    $rematched = rematchClientLinks($db);

    echo json_encode(['success' => true, 'inserted' => $inserted, 'skipped' => $skipped, 'rematched' => $rematched]);
    exit;
}

if ($method === 'DELETE') {
    $input = json_decode(file_get_contents('php://input'), true);

    // Delete all links
    if (!empty($input['all'])) {
        $db->exec('DELETE FROM links');
        echo json_encode(['success' => true, 'deleted' => $db->changes()]);
        exit;
    }

    // Delete by site_id
    if (!empty($input['site_id'])) {
        $stmt = $db->prepare('DELETE FROM links WHERE site_id = :site_id');
        $stmt->bindValue(':site_id', (int) $input['site_id'], SQLITE3_INTEGER);
        $stmt->execute();
        echo json_encode(['success' => true, 'deleted' => $db->changes()]);
        exit;
    }

    // Delete single link
    $id = (int) ($input['id'] ?? 0);
    if (!$id) {
        http_response_code(400);
        echo json_encode(['error' => 'Brak ID']);
        exit;
    }

    $stmt = $db->prepare('DELETE FROM links WHERE id = :id');
    $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
    $stmt->execute();

    echo json_encode(['success' => true]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
