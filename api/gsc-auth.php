<?php
/**
 * GSC auth actions: initiate OAuth, disconnect, check status.
 */
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/gsc_api.php';
header('Content-Type: application/json');
requireLoginApi();

$action = $_GET['action'] ?? $_POST['action'] ?? '';

if ($action === 'status') {
    $gsc = new GscApi();
    $db = getDb();
    $revokedRow = $db->querySingle("SELECT value FROM settings WHERE key = 'gsc_token_revoked'", true);
    echo json_encode([
        'configured' => $gsc->isConfigured(),
        'connected' => $gsc->isConnected(),
        'revoked' => !empty($revokedRow),
        'revoked_at' => $revokedRow ? $revokedRow['value'] : null,
    ]);
    exit;
}

if ($action === 'connect') {
    $gsc = new GscApi();
    if (!$gsc->isConfigured()) {
        echo json_encode(['error' => 'Najpierw skonfiguruj Client ID i Client Secret.']);
        exit;
    }
    $redirectUri = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
        . '://' . $_SERVER['HTTP_HOST']
        . dirname($_SERVER['SCRIPT_NAME'])
        . '/gsc-callback.php';

    echo json_encode(['auth_url' => $gsc->getAuthUrl($redirectUri)]);
    exit;
}

if ($action === 'disconnect') {
    $db = getDb();
    foreach (['gsc_access_token', 'gsc_refresh_token', 'gsc_token_expires'] as $key) {
        $stmt = $db->prepare('DELETE FROM settings WHERE key = :key');
        $stmt->bindValue(':key', $key, SQLITE3_TEXT);
        $stmt->execute();
    }
    GscApi::invalidateCache();
    echo json_encode(['success' => true]);
    exit;
}

if ($action === 'sites') {
    try {
        $gsc = new GscApi();
        $sites = $gsc->getSiteList();
        echo json_encode(['success' => true, 'sites' => $sites]);
    } catch (Exception $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Nieznana akcja']);
