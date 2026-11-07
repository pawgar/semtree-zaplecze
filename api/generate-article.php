<?php
/**
 * Generate an SEO blog article (Zamów i opublikuj — pojedynczo i masowo).
 * Ten sam pipeline co auto-publikacja: research Senuto + SERP -> pisanie -> audyt -> korekta.
 * Zwraca HTML + Markdown + raport audytu.
 */
set_time_limit(900);
ignore_user_abort(true);
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/article_pipeline.php';
header('Content-Type: application/json');
header('X-Accel-Buffering: no');
requireLoginApi();

$input = json_decode(file_get_contents('php://input'), true);

$title = trim($input['title'] ?? '');
if (!$title) {
    http_response_code(400);
    echo json_encode(['error' => 'Tytuł jest wymagany']);
    exit;
}

if (claudeApiKey() === '') {
    echo json_encode(['error' => 'Klucz Anthropic API nie jest skonfigurowany. Ustaw go w ustawieniach.']);
    exit;
}

// Sesja nie jest potrzebna dalej — zwolnij lock, żeby równoległe requesty (masowe zamówienie) nie czekały
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

// Pipeline trwa kilka minut. Spacja co ~10 s podtrzymuje połączenie (proxy/LiteSpeed
// nie zrywa go jako bezczynnego); JSON.parse ignoruje wiodące białe znaki.
while (ob_get_level() > 0) ob_end_flush();
$keepalive = function () {
    echo ' ';
    @flush();
};

try {
    $result = generateArticleFull([
        'title'              => $title,
        'main_keyword'       => trim($input['main_keyword'] ?? ''),
        'secondary_keywords' => trim($input['secondary_keywords'] ?? ''),
        'notes'              => trim($input['notes'] ?? ''),
        'lang'               => trim($input['lang'] ?? 'pl'),
        'category'           => trim($input['category'] ?? ''),
    ], $keepalive, fn($m) => $keepalive());
} catch (ClaudeApiException $e) {
    echo json_encode(['error' => 'Błąd API: ' . $e->getMessage()]);
    exit;
} catch (Throwable $e) {
    echo json_encode(['error' => $e->getMessage()]);
    exit;
}

$sum = fn(string $k) => array_sum(array_map(fn($u) => $u[$k] ?? 0, $result['usage']));

echo json_encode([
    'success'       => true,
    'html_content'  => $result['html'],
    'markdown'      => $result['markdown'],
    'char_count'    => $result['char_count'],
    'checks'        => $result['checks'],
    'research'      => $result['research'],
    'notes'         => $result['notes'],
    'cost_usd'      => $result['cost_usd'],
    'model'         => $result['model'],
    'proofread'     => true,
    'input_tokens'  => $sum('input_tokens'),
    'output_tokens' => $sum('output_tokens'),
]);
