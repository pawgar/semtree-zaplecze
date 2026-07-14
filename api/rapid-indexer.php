<?php
/**
 * Submit URLs to Rapid URL Indexer as a single project (batch).
 * Zastępuje dawną integrację Speed-Links.
 * POST: { urls: ["url1", "url2", ...], project_name?: "..." }
 * API: POST https://rapidurlindexer.com/wp-json/api/v1/projects  (X-API-Key)
 */
set_time_limit(90);
require_once __DIR__ . '/../auth.php';
header('Content-Type: application/json');
requireLoginApi();

$input = json_decode(file_get_contents('php://input'), true);
$urls = $input['urls'] ?? [];
$projectName = trim($input['project_name'] ?? '');

if (empty($urls) || !is_array($urls)) {
    http_response_code(400);
    echo json_encode(['error' => 'Brak URLi do indeksacji']);
    exit;
}

// Wyczyść i odfiltruj puste / niepoprawne (Rapid wymaga http:// lub https://)
$urls = array_values(array_filter(array_map('trim', $urls), function ($u) {
    return $u !== '' && preg_match('#^https?://#i', $u);
}));

if (empty($urls)) {
    http_response_code(400);
    echo json_encode(['error' => 'Brak poprawnych URLi do indeksacji (wymagany http:// lub https://)']);
    exit;
}

$db = getDb();
$row = $db->querySingle("SELECT value FROM settings WHERE key = 'rapid_indexer_api_key'", true);
$apiKey = $row ? trim($row['value']) : '';

if (!$apiKey) {
    http_response_code(400);
    echo json_encode(['error' => 'Brak klucza API Rapid URL Indexer. Ustaw go w ustawieniach.']);
    exit;
}

if ($projectName === '') {
    $projectName = 'Zaplecze — ' . date('Y-m-d H:i');
}

$payload = json_encode([
    'project_name' => $projectName,
    'urls' => $urls,
    'notify_on_status_change' => false,
    'apex_mode_enabled' => false,
]);

$ch = curl_init('https://rapidurlindexer.com/wp-json/api/v1/projects');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'X-API-Key: ' . $apiKey,
    ],
    CURLOPT_POSTFIELDS => $payload,
    CURLOPT_TIMEOUT => 60,
    CURLOPT_CONNECTTIMEOUT => 20,
]);
$result = curl_exec($ch);
$httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

if ($error) {
    http_response_code(500);
    echo json_encode(['error' => 'cURL error: ' . $error]);
    exit;
}

$data = json_decode($result, true) ?? [];

if ($httpCode === 201 || $httpCode === 200) {
    echo json_encode([
        'success' => true,
        'submitted' => count($urls),
        'project_id' => $data['project_id'] ?? null,
        'project_name' => $projectName,
    ]);
} else {
    echo json_encode([
        'success' => false,
        'submitted' => 0,
        'error' => 'Rapid Indexer: ' . ($data['message'] ?? ('HTTP ' . $httpCode . ' ' . trim((string) $result))),
    ]);
}
