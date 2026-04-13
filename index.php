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
    case 'order':
        requireLogin();
        require __DIR__ . '/pages/order.php';
        break;
    case 'publish':
        requireLogin();
        require __DIR__ . '/pages/publish.php';
        break;
    case 'import':
        requireLogin();
        require __DIR__ . '/pages/import.php';
        break;
    case 'links':
        requireLogin();
        require __DIR__ . '/pages/links.php';
        break;
    case 'gsc-report':
        requireLogin();
        require __DIR__ . '/pages/gsc-report.php';
        break;
    case 'auto-publish':
        requireLogin();
        require __DIR__ . '/pages/auto-publish.php';
        break;
    case 'site-card':
        requireLogin();
        require __DIR__ . '/pages/site-card.php';
        break;
    case 'settings':
        requireLogin();
        require __DIR__ . '/pages/settings.php';
        break;
    case 'users':
        requireAdmin();
        require __DIR__ . '/pages/users.php';
        break;
    case 'profile':
        requireLogin();
        // Non-admin can only view own profile
        if (isset($_GET['user_id']) && (int)$_GET['user_id'] !== (int)$_SESSION['user_id'] && !isAdmin()) {
            http_response_code(403);
            echo 'Brak uprawnien';
            exit;
        }
        require __DIR__ . '/pages/profile.php';
        break;
    default:
        require __DIR__ . '/pages/dashboard.php';
        break;
}
