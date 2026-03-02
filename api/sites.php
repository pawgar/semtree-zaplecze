<?php
require_once __DIR__ . '/../auth.php';
header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    requireLoginApi();
    $db = getDb();
    $result = $db->query('SELECT id, name, url, username, app_password, created_at FROM sites ORDER BY name');
    $sites = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $sites[] = $row;
    }
    echo json_encode($sites);
    exit;
}

// All write operations require admin
requireAdminApi();

if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $name = trim($input['name'] ?? '');
    $url = trim($input['url'] ?? '');
    $username = trim($input['username'] ?? '');
    $appPassword = trim($input['app_password'] ?? '');

    if (!$name || !$url || !$username || !$appPassword) {
        http_response_code(400);
        echo json_encode(['error' => 'Wszystkie pola sa wymagane']);
        exit;
    }

    if (!str_starts_with($url, 'http')) {
        $url = 'https://' . $url;
    }
    $url = rtrim($url, '/');

    $db = getDb();
    $stmt = $db->prepare('INSERT INTO sites (name, url, username, app_password) VALUES (:name, :url, :username, :app_password)');
    $stmt->bindValue(':name', $name, SQLITE3_TEXT);
    $stmt->bindValue(':url', $url, SQLITE3_TEXT);
    $stmt->bindValue(':username', $username, SQLITE3_TEXT);
    $stmt->bindValue(':app_password', $appPassword, SQLITE3_TEXT);
    $stmt->execute();

    echo json_encode(['id' => $db->lastInsertRowID(), 'success' => true]);
    exit;
}

if ($method === 'PUT') {
    $input = json_decode(file_get_contents('php://input'), true);
    $id = (int) ($input['id'] ?? 0);
    $name = trim($input['name'] ?? '');
    $url = trim($input['url'] ?? '');
    $username = trim($input['username'] ?? '');
    $appPassword = trim($input['app_password'] ?? '');

    if (!$id || !$name || !$url || !$username || !$appPassword) {
        http_response_code(400);
        echo json_encode(['error' => 'Wszystkie pola sa wymagane']);
        exit;
    }

    if (!str_starts_with($url, 'http')) {
        $url = 'https://' . $url;
    }
    $url = rtrim($url, '/');

    $db = getDb();
    $stmt = $db->prepare('UPDATE sites SET name=:name, url=:url, username=:username, app_password=:app_password WHERE id=:id');
    $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
    $stmt->bindValue(':name', $name, SQLITE3_TEXT);
    $stmt->bindValue(':url', $url, SQLITE3_TEXT);
    $stmt->bindValue(':username', $username, SQLITE3_TEXT);
    $stmt->bindValue(':app_password', $appPassword, SQLITE3_TEXT);
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

    $db = getDb();
    $stmt = $db->prepare('DELETE FROM sites WHERE id = :id');
    $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
    $stmt->execute();

    echo json_encode(['success' => true]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
