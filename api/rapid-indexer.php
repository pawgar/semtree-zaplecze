<?php
/**
 * Ręczne zgłoszenie URL-i do Rapid URL Indexer (Publikuj / Import — checkbox „Wyślij do indeksacji”).
 * POST: { urls: ["url1", "url2", ...], project_name?: "..." }
 *
 * Idzie przez wspólną kolejkę (includes/rapid_indexing.php) i od razu wysyła paczkę: sprawdzenie
 * 200/noindex, bez ponownego zgłoszenia w ciągu 30 dni, kontrola kredytów, własny User-Agent.
 * Publikacje przez api/publish.php trafiają do kolejki same — ten endpoint wtedy nic nie dubluje.
 */
set_time_limit(300);
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/rapid_indexing.php';
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
if (indexSetting('rapid_indexer_api_key') === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Brak klucza API Rapid URL Indexer. Ustaw go w ustawieniach.']);
    exit;
}

$db = getDb();
$queued = 0;
foreach ($urls as $u) {
    $u = trim((string)$u);
    if (!preg_match('#^https?://#i', $u)) continue;
    // site_id po domenie (0, gdy URL spoza zaplecza)
    $host = preg_replace('/^www\./', '', strtolower(parse_url($u, PHP_URL_HOST) ?? ''));
    $st = $db->prepare("SELECT id FROM sites WHERE REPLACE(REPLACE(REPLACE(LOWER(url), 'https://', ''), 'http://', ''), 'www.', '') LIKE :h LIMIT 1");
    $st->bindValue(':h', $host . '%', SQLITE3_TEXT);
    $siteId = (int)($st->execute()->fetchArray(SQLITE3_NUM)[0] ?? 0);
    if (indexQueueUrl($u, $siteId, 'ręcznie')) $queued++;
}

$res = indexFlush(true, $projectName ?: 'ręcznie');
if ($res['error'] && !$res['submitted']) {
    http_response_code(502);
    echo json_encode(['error' => $res['error'], 'queued' => $queued], JSON_UNESCAPED_UNICODE);
    exit;
}
echo json_encode([
    'success'      => true,
    'submitted'    => $res['submitted'],
    'project_id'   => $res['project_id'],
    'credits_left' => $res['credits_left'],
    'deferred'     => $res['deferred'],
    'already'      => count($urls) - $queued,   // zgłoszone wcześniej (np. automatycznie po publikacji)
], JSON_UNESCAPED_UNICODE);
