<?php
/**
 * Submit URLs to Speed-Links.net for indexing.
 * POST: { urls: ["url1", "url2", ...] }
 */
set_time_limit(60);
require_once __DIR__ . '/../auth.php';
header('Content-Type: application/json');
requireLoginApi();

$input = json_decode(file_get_contents('php://input'), true);
$urls = $input['urls'] ?? [];

if (empty($urls) || !is_array($urls)) {
    http_response_code(400);
    echo json_encode(['error' => 'Brak URLi do indeksacji']);
    exit;
}

$db = getDb();
$stmt = $db->prepare('SELECT value FROM settings WHERE key = "speedlinks_api_key"');
$row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
$apiKey = $row ? trim($row['value']) : '';

if (!$apiKey) {
    http_response_code(400);
    echo json_encode(['error' => 'Brak klucza API Speed-Links. Ustaw go w ustawieniach.']);
    exit;
}

// Read method preference from settings (default: vip)
$methodStmt = $db->prepare('SELECT value FROM settings WHERE key = "speedlinks_method"');
$methodRow = $methodStmt->execute()->fetchArray(SQLITE3_ASSOC);
$method = ($methodRow && trim($methodRow['value'])) ? trim($methodRow['value']) : 'vip';

$qstring = 'apikey=' . urlencode($apiKey)
    . '&cmd=submit'
    . '&campaign='
    . '&urls=' . urlencode(implode('|', $urls))
    . '&dripfeed=1'
    . '&reporturl=1'
    . '&method=' . urlencode($method);

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_URL => 'http://speed-links.net/api.php',
    CURLOPT_HEADER => false,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 40,
    CURLOPT_POSTFIELDS => $qstring,
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

// Parse response: "OK|report_url" or "ERROR: ..."
$parts = explode('|', $result, 2);
$isOk = (strtoupper(trim($parts[0])) === 'OK');

if (!$isOk) {
    echo json_encode([
        'success' => false,
        'error' => 'Speed-Links: ' . trim($result),
        'submitted' => 0,
    ]);
    exit;
}

echo json_encode([
    'success' => true,
    'submitted' => count($urls),
    'report_url' => $parts[1] ?? '',
    'response' => $result,
]);
