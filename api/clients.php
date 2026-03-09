<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/link_extractor.php';
header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];

// All operations require admin
requireLoginApi();

$db = getDb();

if ($method === 'GET') {
    // List clients with link count and site count
    $result = $db->query('
        SELECT c.*,
            COUNT(DISTINCT l.id) AS link_count,
            COUNT(DISTINCT l.site_id) AS site_count
        FROM clients c
        LEFT JOIN links l ON l.client_id = c.id
        GROUP BY c.id
        ORDER BY c.name
    ');
    $clients = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $clients[] = $row;
    }
    echo json_encode($clients);
    exit;
}

if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $name = trim($input['name'] ?? '');
    $domain = normalizeDomain($input['domain'] ?? '');
    $color = trim($input['color'] ?? '#6c757d');

    if (!$name || !$domain) {
        http_response_code(400);
        echo json_encode(['error' => 'Nazwa i domena sa wymagane']);
        exit;
    }

    $stmt = $db->prepare('INSERT INTO clients (name, domain, color) VALUES (:name, :domain, :color)');
    $stmt->bindValue(':name', $name, SQLITE3_TEXT);
    $stmt->bindValue(':domain', $domain, SQLITE3_TEXT);
    $stmt->bindValue(':color', $color, SQLITE3_TEXT);
    $stmt->execute();

    $newId = $db->lastInsertRowID();

    // Re-match existing unmatched links against all client domains (including the new one)
    $rematched = rematchClientLinks($db);

    echo json_encode(['id' => $newId, 'success' => true, 'rematched' => $rematched]);
    exit;
}

if ($method === 'PUT') {
    $input = json_decode(file_get_contents('php://input'), true);
    $id = (int) ($input['id'] ?? 0);
    $name = trim($input['name'] ?? '');
    $domain = normalizeDomain($input['domain'] ?? '');
    $color = trim($input['color'] ?? '#6c757d');

    if (!$id || !$name || !$domain) {
        http_response_code(400);
        echo json_encode(['error' => 'ID, nazwa i domena sa wymagane']);
        exit;
    }

    // If domain changed, unlink old matches so they can be re-evaluated
    $oldStmt = $db->prepare('SELECT domain FROM clients WHERE id = :id');
    $oldStmt->bindValue(':id', $id, SQLITE3_INTEGER);
    $oldClient = $oldStmt->execute()->fetchArray(SQLITE3_ASSOC);
    $oldDomain = $oldClient ? $oldClient['domain'] : '';

    $stmt = $db->prepare('UPDATE clients SET name=:name, domain=:domain, color=:color WHERE id=:id');
    $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
    $stmt->bindValue(':name', $name, SQLITE3_TEXT);
    $stmt->bindValue(':domain', $domain, SQLITE3_TEXT);
    $stmt->bindValue(':color', $color, SQLITE3_TEXT);
    $stmt->execute();

    // If domain changed, reset client_id for links that were matched to the old domain
    if ($oldDomain && strtolower($oldDomain) !== strtolower($domain)) {
        $resetStmt = $db->prepare('UPDATE links SET client_id = NULL WHERE client_id = :cid');
        $resetStmt->bindValue(':cid', $id, SQLITE3_INTEGER);
        $resetStmt->execute();
    }

    // Re-match all unmatched links
    $rematched = rematchClientLinks($db);

    echo json_encode(['success' => true, 'rematched' => $rematched]);
    exit;
}

if ($method === 'DELETE') {
    $input = json_decode(file_get_contents('php://input'), true);
    $id = (int) ($input['id'] ?? 0);

    if (!$id) {
        http_response_code(400);
        echo json_encode(['error' => 'Brak ID']);
        exit;
    }

    $stmt = $db->prepare('DELETE FROM clients WHERE id = :id');
    $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
    $stmt->execute();

    echo json_encode(['success' => true]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
