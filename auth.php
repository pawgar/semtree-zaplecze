<?php
require_once __DIR__ . '/db.php';

function startSession(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}

function login(string $username, string $password): bool {
    $db = getDb();
    $stmt = $db->prepare('SELECT id, username, password, role FROM users WHERE username = :u');
    $stmt->bindValue(':u', $username, SQLITE3_TEXT);
    $result = $stmt->execute();
    $user = $result->fetchArray(SQLITE3_ASSOC);

    if (!$user || !password_verify($password, $user['password'])) {
        return false;
    }

    startSession();
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['role'] = $user['role'];
    return true;
}

function logout(): void {
    startSession();
    session_destroy();
}

function isLoggedIn(): bool {
    startSession();
    return isset($_SESSION['user_id']);
}

function isAdmin(): bool {
    startSession();
    return ($_SESSION['role'] ?? '') === 'admin';
}

function requireLogin(): void {
    if (!isLoggedIn()) {
        header('Location: index.php?page=login');
        exit;
    }
}

function requireAdmin(): void {
    requireLogin();
    if (!isAdmin()) {
        http_response_code(403);
        echo json_encode(['error' => 'Brak uprawnien']);
        exit;
    }
}

function requireAdminApi(): void {
    startSession();
    if (!isLoggedIn() || !isAdmin()) {
        http_response_code(403);
        echo json_encode(['error' => 'Brak uprawnien']);
        exit;
    }
}

function requireLoginApi(): void {
    startSession();
    if (!isLoggedIn()) {
        http_response_code(401);
        echo json_encode(['error' => 'Nie zalogowano']);
        exit;
    }
}
