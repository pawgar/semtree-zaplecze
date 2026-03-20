<?php
require_once __DIR__ . '/../auth.php';
header('Content-Type: application/json');
requireLoginApi();

$method = $_SERVER['REQUEST_METHOD'];
$db = getDb();
$currentUserId = (int) $_SESSION['user_id'];

// GET — user stats (own or other user for admin)
if ($method === 'GET') {
    $userId = (int) ($_GET['user_id'] ?? $currentUserId);

    // Non-admin can only view own stats
    if ($userId !== $currentUserId && !isAdmin()) {
        http_response_code(403);
        echo json_encode(['error' => 'Brak uprawnien']);
        exit;
    }

    // User info
    $stmt = $db->prepare('SELECT id, username, role, created_at FROM users WHERE id = :id');
    $stmt->bindValue(':id', $userId, SQLITE3_INTEGER);
    $user = $stmt->execute()->fetchArray(SQLITE3_ASSOC);

    if (!$user) {
        http_response_code(404);
        echo json_encode(['error' => 'Uzytkownik nie znaleziony']);
        exit;
    }

    // Monthly publication stats
    $stmt = $db->prepare("
        SELECT strftime('%Y-%m', p.created_at) AS month,
               COUNT(p.id) AS total_articles,
               COUNT(DISTINCT l.id) AS articles_with_links
        FROM publications p
        LEFT JOIN links l ON l.site_id = p.site_id AND l.post_url = p.post_url
        WHERE p.user_id = :uid
        GROUP BY month
        ORDER BY month DESC
        LIMIT 24
    ");
    $stmt->bindValue(':uid', $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $monthly = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $monthly[] = $row;
    }

    // Detailed link list: Blog >> linked domain > article URL > date > worker
    $stmt = $db->prepare("
        SELECT p.post_title, p.post_url, p.created_at,
               s.name AS site_name, s.url AS site_url,
               l.target_url, l.anchor_text,
               c.name AS client_name, c.domain AS client_domain
        FROM publications p
        JOIN sites s ON s.id = p.site_id
        LEFT JOIN links l ON l.site_id = p.site_id AND l.post_url = p.post_url
        LEFT JOIN clients c ON c.id = l.client_id
        WHERE p.user_id = :uid
        ORDER BY p.created_at DESC
        LIMIT 500
    ");
    $stmt->bindValue(':uid', $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $publications = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $publications[] = $row;
    }

    echo json_encode([
        'user' => $user,
        'monthly' => $monthly,
        'publications' => $publications,
    ]);
    exit;
}

// POST — change own password
if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $action = $input['action'] ?? 'change_password';

    if ($action === 'change_password') {
        $currentPassword = $input['current_password'] ?? '';
        $newPassword = $input['new_password'] ?? '';

        if (!$currentPassword || !$newPassword) {
            http_response_code(400);
            echo json_encode(['error' => 'Podaj aktualne i nowe haslo']);
            exit;
        }

        if (strlen($newPassword) < 4) {
            http_response_code(400);
            echo json_encode(['error' => 'Nowe haslo musi miec co najmniej 4 znaki']);
            exit;
        }

        // Verify current password
        $stmt = $db->prepare('SELECT password FROM users WHERE id = :id');
        $stmt->bindValue(':id', $currentUserId, SQLITE3_INTEGER);
        $user = $stmt->execute()->fetchArray(SQLITE3_ASSOC);

        if (!$user || !password_verify($currentPassword, $user['password'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Aktualne haslo jest nieprawidlowe']);
            exit;
        }

        $hash = password_hash($newPassword, PASSWORD_BCRYPT);
        $stmt = $db->prepare('UPDATE users SET password = :p WHERE id = :id');
        $stmt->bindValue(':p', $hash, SQLITE3_TEXT);
        $stmt->bindValue(':id', $currentUserId, SQLITE3_INTEGER);
        $stmt->execute();

        echo json_encode(['success' => true]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['error' => 'Nieznana akcja']);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
