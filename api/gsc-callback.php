<?php
/**
 * OAuth 2.0 callback for Google Search Console.
 * Exchanges authorization code for tokens and redirects to settings.
 */
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/gsc_api.php';

startSession();
if (!isLoggedIn()) {
    header('Location: ../index.php?page=login');
    exit;
}

$code = $_GET['code'] ?? '';
$error = $_GET['error'] ?? '';

if ($error) {
    header('Location: ../index.php?page=settings&gsc_error=' . urlencode($error));
    exit;
}

if (!$code) {
    header('Location: ../index.php?page=settings&gsc_error=no_code');
    exit;
}

try {
    $gsc = new GscApi();
    $redirectUri = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
        . '://' . $_SERVER['HTTP_HOST']
        . dirname($_SERVER['SCRIPT_NAME'])
        . '/gsc-callback.php';

    $gsc->exchangeCode($code, $redirectUri);

    header('Location: ../index.php?page=settings&gsc_connected=1');
} catch (Exception $e) {
    header('Location: ../index.php?page=settings&gsc_error=' . urlencode($e->getMessage()));
}
