<?php
/**
 * Generate an SEO blog article using Anthropic Claude API.
 * Returns HTML content converted from Markdown.
 */
set_time_limit(180);
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/article_prompt.php';
header('Content-Type: application/json');
requireLoginApi();

$input = json_decode(file_get_contents('php://input'), true);

$title = trim($input['title'] ?? '');
$mainKeyword = trim($input['main_keyword'] ?? '');
$secondaryKeywords = trim($input['secondary_keywords'] ?? '');
$notes = trim($input['notes'] ?? '');
$lang = trim($input['lang'] ?? 'pl');

if (!$title) {
    http_response_code(400);
    echo json_encode(['error' => 'Tytuł jest wymagany']);
    exit;
}

// Get Anthropic API key from settings
$db = getDb();
$stmt = $db->prepare('SELECT value FROM settings WHERE key = "anthropic_api_key"');
$row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
$apiKey = $row ? trim($row['value']) : '';

if (!$apiKey) {
    echo json_encode(['error' => 'Klucz Anthropic API nie jest skonfigurowany. Ustaw go w ustawieniach.']);
    exit;
}

$systemPrompt = getArticleSystemPrompt($lang);
$userPrompt = buildArticleUserPrompt($title, $mainKeyword, $secondaryKeywords, $notes, $lang);

// Call Anthropic Claude API
$url = 'https://api.anthropic.com/v1/messages';
$payload = [
    'model' => 'claude-sonnet-4-6',
    'max_tokens' => 16000,
    'system' => $systemPrompt,
    'messages' => [
        ['role' => 'user', 'content' => $userPrompt],
    ],
];

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'x-api-key: ' . $apiKey,
        'anthropic-version: 2023-06-01',
    ],
    CURLOPT_POSTFIELDS => json_encode($payload),
    CURLOPT_TIMEOUT => 150,
    CURLOPT_CONNECTTIMEOUT => 30,
    CURLOPT_SSL_VERIFYPEER => false,
]);

$response = curl_exec($ch);

if (curl_errno($ch)) {
    $error = curl_error($ch);
    curl_close($ch);
    echo json_encode(['error' => 'Błąd połączenia z API: ' . $error]);
    exit;
}

$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$data = json_decode($response, true) ?? [];

if ($httpCode === 401 || $httpCode === 403) {
    echo json_encode(['error' => 'Nieprawidłowy klucz Anthropic API.']);
    exit;
}

if ($httpCode === 429) {
    echo json_encode(['error' => 'Przekroczony limit zapytań API. Spróbuj za chwilę.']);
    exit;
}

if ($httpCode >= 400) {
    $msg = $data['error']['message'] ?? "HTTP {$httpCode}";
    echo json_encode(['error' => 'Błąd API: ' . $msg]);
    exit;
}

// Extract text content from response
$markdown = '';
$inputTokens = $data['usage']['input_tokens'] ?? 0;
$outputTokens = $data['usage']['output_tokens'] ?? 0;

if (isset($data['content'])) {
    foreach ($data['content'] as $block) {
        if (($block['type'] ?? '') === 'text') {
            $markdown .= $block['text'];
        }
    }
}

if (!$markdown) {
    echo json_encode(['error' => 'API nie zwróciło treści artykułu.']);
    exit;
}

// ── Step 2: Proofreading pass (Sonnet) ──────────────────────
$langName = getLanguageName($lang);
$proofSystemPrompt = "Jesteś profesjonalnym korektorem tekstów w języku {$langName}. "
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

$proofPayload = [
    'model' => 'claude-sonnet-4-6',
    'max_tokens' => 16000,
    'system' => $proofSystemPrompt,
    'messages' => [
        ['role' => 'user', 'content' => "Popraw błędy ortograficzne i literówki w poniższym artykule. Zwróć TYLKO poprawiony tekst:\n\n" . $markdown],
    ],
];

$ch2 = curl_init($url);
curl_setopt_array($ch2, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'x-api-key: ' . $apiKey,
        'anthropic-version: 2023-06-01',
    ],
    CURLOPT_POSTFIELDS => json_encode($proofPayload),
    CURLOPT_TIMEOUT => 120,
    CURLOPT_CONNECTTIMEOUT => 30,
    CURLOPT_SSL_VERIFYPEER => false,
]);

$proofResponse = curl_exec($ch2);
$proofHttpCode = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
curl_close($ch2);

if ($proofResponse && $proofHttpCode === 200) {
    $proofData = json_decode($proofResponse, true) ?? [];
    $proofText = '';
    if (isset($proofData['content'])) {
        foreach ($proofData['content'] as $block) {
            if (($block['type'] ?? '') === 'text') {
                $proofText .= $block['text'];
            }
        }
    }
    if (trim($proofText)) {
        $markdown = $proofText;
        $inputTokens += $proofData['usage']['input_tokens'] ?? 0;
        $outputTokens += $proofData['usage']['output_tokens'] ?? 0;
    }
}

// Remove H1 from markdown (title is separate)
$markdown = preg_replace('/^#\s+.+\n*/m', '', $markdown, 1);

// Convert Markdown to HTML and sanitize
$htmlContent = markdownToHtml($markdown);
$htmlContent = sanitizeArticleHtml($htmlContent);
$charCount = mb_strlen(strip_tags($htmlContent), 'UTF-8');

echo json_encode([
    'success' => true,
    'html_content' => $htmlContent,
    'markdown' => $markdown,
    'char_count' => $charCount,
    'input_tokens' => $inputTokens,
    'output_tokens' => $outputTokens,
]);
