<?php
require_once __DIR__ . '/../auth.php';
header('Content-Type: application/json');
requireAdminApi();

$method = $_SERVER['REQUEST_METHOD'];
$db = getDb();

if ($method === 'GET') {
    $result = $db->query('SELECT id, username, role, created_at FROM users ORDER BY role, username');
    $users = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $users[] = $row;
    }
    echo json_encode($users);
    exit;
}

if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $username = trim($input['username'] ?? '');
    $password = $input['password'] ?? '';

    if (!$username || !$password) {
        http_response_code(400);
        echo json_encode(['error' => 'Login i haslo sa wymagane']);
        exit;
    }

    if (strlen($password) < 4) {
        http_response_code(400);
        echo json_encode(['error' => 'Haslo musi miec co najmniej 4 znaki']);
        exit;
    }

    // Check for duplicate username
    $check = $db->prepare('SELECT id FROM users WHERE username = :u');
    $check->bindValue(':u', $username, SQLITE3_TEXT);
    if ($check->execute()->fetchArray()) {
        http_response_code(409);
        echo json_encode(['error' => "Uzytkownik '$username' juz istnieje"]);
        exit;
    }

    $hash = password_hash($password, PASSWORD_BCRYPT);
    $stmt = $db->prepare('INSERT INTO users (username, password, role) VALUES (:u, :p, "worker")');
    $stmt->bindValue(':u', $username, SQLITE3_TEXT);
    $stmt->bindValue(':p', $hash, SQLITE3_TEXT);
    $stmt->execute();

    echo json_encode(['id' => $db->lastInsertRowID(), 'success' => true]);
    exit;
}

if ($method === 'PATCH') {
    $input = json_decode(file_get_contents('php://input'), true);
    $id = (int) ($input['id'] ?? 0);
    $action = $input['action'] ?? '';

    if (!$id) {
        http_response_code(400);
        echo json_encode(['error' => 'Brak ID']);
        exit;
    }

    // Change role
    if ($action === 'change_role') {
        $newRole = $input['role'] ?? '';
        if (!in_array($newRole, ['admin', 'worker'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Nieprawidlowa rola']);
            exit;
        }

        // Prevent removing last admin
        if ($newRole === 'worker') {
            $stmt = $db->prepare('SELECT role FROM users WHERE id = :id');
            $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
            $user = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
            if ($user && $user['role'] === 'admin') {
                $adminCount = $db->querySingle('SELECT COUNT(*) FROM users WHERE role = "admin"');
                if ($adminCount <= 1) {
                    http_response_code(400);
                    echo json_encode(['error' => 'Nie mozna usunac ostatniego admina']);
                    exit;
                }
            }
        }

        $stmt = $db->prepare('UPDATE users SET role = :role WHERE id = :id');
        $stmt->bindValue(':role', $newRole, SQLITE3_TEXT);
        $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
        $stmt->execute();

        // Update session if changing own role
        if ($id === (int) $_SESSION['user_id']) {
            $_SESSION['role'] = $newRole;
        }

        echo json_encode(['success' => true]);
        exit;
    }

    // Reset password (admin sets password for worker)
    if ($action === 'reset_password') {
        $newPassword = $input['password'] ?? '';
        if (strlen($newPassword) < 4) {
            http_response_code(400);
            echo json_encode(['error' => 'Haslo musi miec co najmniej 4 znaki']);
            exit;
        }

        $hash = password_hash($newPassword, PASSWORD_BCRYPT);
        $stmt = $db->prepare('UPDATE users SET password = :p WHERE id = :id');
        $stmt->bindValue(':p', $hash, SQLITE3_TEXT);
        $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
        $stmt->execute();

        echo json_encode(['success' => true]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['error' => 'Nieznana akcja']);
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

    // Prevent deleting own account
    if ($id === (int) $_SESSION['user_id']) {
        http_response_code(400);
        echo json_encode(['error' => 'Nie mozna usunac wlasnego konta']);
        exit;
    }

    // Prevent deleting last admin
    $stmt = $db->prepare('SELECT role FROM users WHERE id = :id');
    $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
    $user = $stmt->execute()->fetchArray(SQLITE3_ASSOC);

    if ($user && $user['role'] === 'admin') {
        $adminCount = $db->querySingle('SELECT COUNT(*) FROM users WHERE role = "admin"');
        if ($adminCount <= 1) {
            http_response_code(400);
            echo json_encode(['error' => 'Nie mozna usunac ostatniego admina']);
            exit;
        }
    }

    $stmt = $db->prepare('DELETE FROM users WHERE id = :id');
    $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
    $stmt->execute();

    echo json_encode(['success' => true]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
