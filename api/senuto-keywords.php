<?php
/**
 * Frazy wspierające i pytania z Senuto (z filtrem trafności) dla formularza
 * "Zamów i opublikuj" — przycisk "Frazy z Senuto".
 */
set_time_limit(120);
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/article_pipeline.php';
header('Content-Type: application/json');
requireLoginApi();

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$mainKw = trim($input['main_keyword'] ?? '');
$title = trim($input['title'] ?? '') ?: $mainKw;

if ($mainKw === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Brak głównego słowa kluczowego']);
    exit;
}
if (!senutoConfigured()) {
    echo json_encode(['error' => 'Senuto nie jest skonfigurowane (Ustawienia → Senuto)']);
    exit;
}

try {
    echo json_encode(['success' => true] + senutoResearch($title, $mainKw, false));
} catch (Throwable $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
