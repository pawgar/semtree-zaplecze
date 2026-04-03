<?php
/**
 * Proofread an article using Anthropic Claude API (separate request from generation).
 * Fixes typos, spelling errors and diacritics without changing content or structure.
 */
set_time_limit(180);
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/article_prompt.php';
header('Content-Type: application/json');
requireLoginApi();

$input = json_decode(file_get_contents('php://input'), true);

$markdown = trim($input['markdown'] ?? '');
$lang     = trim($input['lang'] ?? 'pl');

if (!$markdown) {
    http_response_code(400);
    echo json_encode(['error' => 'Brak treści do korekty']);
    exit;
}

// Get Anthropic API key from settings
$db   = getDb();
$stmt = $db->prepare('SELECT value FROM settings WHERE key = "anthropic_api_key"');
$row  = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
$apiKey = $row ? trim($row['value']) : '';

if (!$apiKey) {
    echo json_encode(['error' => 'Klucz Anthropic API nie jest skonfigurowany']);
    exit;
}

$langName = getLanguageName($lang);

$systemPrompt = "Jesteś profesjonalnym korektorem tekstów w języku {$langName}. "
    . "Twoim JEDYNYM zadaniem jest poprawienie błędów ortograficznych, literówek, "
    . "błędnych znaków diakrytycznych i drobnych błędów gramatycznych.\n\n"
    . "ZASADY:\n"
    . "- Poprawiaj WYŁĄCZNIE błędy językowe (ortografia, literówki, interpunkcja, fleksja)\n"
    . "- NIE zmieniaj treści merytorycznej, stylu, struktury ani formatowania Markdown\n"
    . "- NIE dodawaj ani nie usuwaj akapitów, nagłówków, list ani tabel\n"
    . "- NIE zmieniaj kolejności zdań ani nie przeformułowuj ich\n"
    . "- NIE dodawaj komentarzy, wyjaśnień ani listy zmian\n"
    . "- Zwróć WYŁĄCZNIE poprawiony tekst w tym samym formacie Markdown\n"
    . "- Jeśli tekst nie zawiera błędów — zwróć go bez zmian";

$payload = [
    'model'      => 'claude-sonnet-4-6',
    'max_tokens' => 16000,
    'system'     => $systemPrompt,
    'messages'   => [
        ['role' => 'user', 'content' => "Popraw błędy ortograficzne i literówki w poniższym artykule. Zwróć TYLKO poprawiony tekst:\n\n" . $markdown],
    ],
];

$ch = curl_init('https://api.anthropic.com/v1/messages');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'x-api-key: ' . $apiKey,
        'anthropic-version: 2023-06-01',
    ],
    CURLOPT_POSTFIELDS     => json_encode($payload),
    CURLOPT_TIMEOUT        => 150,
    CURLOPT_CONNECTTIMEOUT => 30,
    CURLOPT_SSL_VERIFYPEER => false,
]);

$response = curl_exec($ch);

if (curl_errno($ch)) {
    $error = curl_error($ch);
    curl_close($ch);
    echo json_encode(['error' => 'Błąd połączenia: ' . $error]);
    exit;
}

$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$data = json_decode($response, true) ?? [];

if ($httpCode >= 400) {
    $msg = $data['error']['message'] ?? "HTTP {$httpCode}";
    echo json_encode(['error' => 'Błąd API: ' . $msg]);
    exit;
}

$proofText = '';
foreach (($data['content'] ?? []) as $block) {
    if (($block['type'] ?? '') === 'text') {
        $proofText .= $block['text'];
    }
}

if (!trim($proofText)) {
    echo json_encode(['error' => 'Brak odpowiedzi z API']);
    exit;
}

// Convert corrected markdown back to HTML
$htmlContent = markdownToHtml($proofText);
$htmlContent = sanitizeArticleHtml($htmlContent);

echo json_encode([
    'success'       => true,
    'markdown'      => $proofText,
    'html_content'  => $htmlContent,
    'input_tokens'  => $data['usage']['input_tokens'] ?? 0,
    'output_tokens' => $data['usage']['output_tokens'] ?? 0,
]);
