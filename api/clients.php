<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/link_extractor.php';
header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];

// All operations require admin
requireAdminApi();

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

    echo json_encode(['id' => $db->lastInsertRowID(), 'success' => true]);
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

    $stmt = $db->prepare('UPDATE clients SET name=:name, domain=:domain, color=:color WHERE id=:id');
    $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
    $stmt->bindValue(':name', $name, SQLITE3_TEXT);
    $stmt->bindValue(':domain', $domain, SQLITE3_TEXT);
    $stmt->bindValue(':color', $color, SQLITE3_TEXT);
    $stmt->execute();

    echo json_encode(['success' => true]);
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
