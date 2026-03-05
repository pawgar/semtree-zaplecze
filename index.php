<?php
require_once __DIR__ . '/auth.php';

$page = $_GET['page'] ?? '';

// Handle logout
if ($page === 'logout') {
    logout();
    header('Location: index.php?page=login');
    exit;
}

// Handle login POST
if ($page === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (login($username, $password)) {
        header('Location: index.php');
        exit;
    }
    $loginError = 'Nieprawidlowy login lub haslo';
}

// Not logged in → show login
if (!isLoggedIn()) {
    require __DIR__ . '/pages/login.php';
    exit;
}

// Logged in → route to page
switch ($page) {
    case 'publish':
        requireAdmin();
        require __DIR__ . '/pages/publish.php';
        break;
    case 'import':
        requireAdmin();
        require __DIR__ . '/pages/import.php';
        break;
    case 'users':
        requireAdmin();
        require __DIR__ . '/pages/users.php';
        break;
    default:
        require __DIR__ . '/pages/dashboard.php';
        break;
}
