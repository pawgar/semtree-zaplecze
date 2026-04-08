<?php
/**
 * Proxy for Claude API status check.
 * Fetches status from Atlassian Statuspage and returns API component status.
 */
header('Content-Type: application/json');
header('Cache-Control: public, max-age=60');

$ch = curl_init('https://status.claude.com/api/v2/summary.json');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 10,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_SSL_VERIFYPEER => false,
]);

$response = curl_exec($ch);
$ok = !curl_errno($ch) && curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200;
curl_close($ch);

if (!$ok || !$response) {
    echo json_encode(['status' => 'unknown', 'description' => 'Nie udało się pobrać statusu']);
    exit;
}

$data = json_decode($response, true);
$components = $data['components'] ?? [];

// Find "Claude API" component
$apiStatus = 'unknown';
$apiDesc = '';
foreach ($components as $c) {
    if (stripos($c['name'], 'Claude API') !== false) {
        $apiStatus = $c['status'];
        break;
    }
}

$statusMap = [
    'operational'          => ['status' => 'ok',      'description' => 'Claude API działa poprawnie'],
    'degraded_performance' => ['status' => 'warning',  'description' => 'Claude API — obniżona wydajność'],
    'partial_outage'       => ['status' => 'error',    'description' => 'Claude API — częściowa awaria'],
    'major_outage'         => ['status' => 'error',    'description' => 'Claude API — poważna awaria'],
    'under_maintenance'    => ['status' => 'warning',  'description' => 'Claude API — konserwacja'],
];

$result = $statusMap[$apiStatus] ?? ['status' => 'unknown', 'description' => 'Status nieznany'];
$result['raw_status'] = $apiStatus;
$result['page_description'] = $data['status']['description'] ?? '';

echo json_encode($result);
