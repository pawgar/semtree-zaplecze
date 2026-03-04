<?php
/**
 * Generate featured image using Google Gemini API.
 * Tries Gemini native models first, then Imagen as fallback.
 */
require_once __DIR__ . '/../auth.php';
header('Content-Type: application/json');
requireAdminApi();

$input = json_decode(file_get_contents('php://input'), true);
$title = trim($input['title'] ?? '');

if (!$title) {
    http_response_code(400);
    echo json_encode(['error' => 'Brak tytulu']);
    exit;
}

// Get Gemini API key from settings
$db = getDb();
$stmt = $db->prepare('SELECT value FROM settings WHERE key = "gemini_api_key"');
$result = $stmt->execute();
$row = $result->fetchArray(SQLITE3_ASSOC);
$apiKey = $row ? trim($row['value']) : '';

if (!$apiKey) {
    echo json_encode(['error' => 'Gemini API key nie jest skonfigurowany. Ustaw go w ustawieniach.']);
    exit;
}

$prompt = "Create a professional, high-quality blog featured image for an article titled: "
    . "\"{$title}\". The image should be visually appealing, relevant to the topic, "
    . "photorealistic style, suitable for a blog header. No text on the image. "
    . "Landscape orientation, wide format.";

// Models to try in order
$geminiModels = ['gemini-2.5-flash-image', 'gemini-3.1-flash-image-preview', 'gemini-3-pro-image-preview'];
$imagenModels = ['imagen-4.0-fast-generate-001', 'imagen-4.0-generate-001'];

$errors = [];

// Try Gemini native models
foreach ($geminiModels as $model) {
    $result = tryGeminiNative($apiKey, $model, $prompt);
    if ($result['success']) {
        echo json_encode($result);
        exit;
    }
    $errors[] = $result['error'];
}

// Try Imagen models
foreach ($imagenModels as $model) {
    $result = tryImagen($apiKey, $model, $prompt);
    if ($result['success']) {
        echo json_encode($result);
        exit;
    }
    $errors[] = $result['error'];
}

echo json_encode(['error' => 'Wszystkie modele zawiodly: ' . implode('; ', $errors)]);

// ── Gemini Native Model ─────────────────────────────────────
function tryGeminiNative(string $apiKey, string $model, string $prompt): array {
    $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";

    $payload = [
        'contents' => [['parts' => [['text' => $prompt]]]],
        'generationConfig' => [
            'responseModalities' => ['IMAGE'],
            'imageConfig' => ['aspectRatio' => '3:2'],
        ],
    ];

    $response = geminiCurl($url, $payload);
    if ($response['error']) {
        return handleGeminiError($model, $response['error']);
    }

    $data = $response['data'];

    // Extract image from response
    $candidates = $data['candidates'] ?? [];
    if (empty($candidates)) {
        return ['success' => false, 'error' => "{$model}: Brak kandydatow w odpowiedzi."];
    }

    $parts = $candidates[0]['content']['parts'] ?? [];
    foreach ($parts as $part) {
        if (isset($part['inlineData']['data'])) {
            $imageData = $part['inlineData']['data'];
            $mimeType = $part['inlineData']['mimeType'] ?? 'image/png';
            $ext = str_contains($mimeType, 'png') ? 'png' : 'jpg';

            return [
                'success' => true,
                'image_data' => $imageData, // already base64
                'image_filename' => 'gemini_' . bin2hex(random_bytes(4)) . '.' . $ext,
                'mime_type' => $mimeType,
            ];
        }
    }

    return ['success' => false, 'error' => "{$model}: Odpowiedz nie zawiera obrazka."];
}

// ── Imagen Model ─────────────────────────────────────────────
function tryImagen(string $apiKey, string $model, string $prompt): array {
    $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:predict?key={$apiKey}";

    $payload = [
        'instances' => [['prompt' => $prompt]],
        'parameters' => [
            'sampleCount' => 1,
            'aspectRatio' => '3:2',
            'outputOptions' => ['mimeType' => 'image/jpeg'],
        ],
    ];

    $response = geminiCurl($url, $payload);
    if ($response['error']) {
        return handleGeminiError($model, $response['error']);
    }

    $data = $response['data'];
    $predictions = $data['predictions'] ?? [];

    if (!empty($predictions) && isset($predictions[0]['bytesBase64Encoded'])) {
        return [
            'success' => true,
            'image_data' => $predictions[0]['bytesBase64Encoded'],
            'image_filename' => 'gemini_' . bin2hex(random_bytes(4)) . '.jpg',
            'mime_type' => 'image/jpeg',
        ];
    }

    return ['success' => false, 'error' => "{$model}: Brak obrazkow w odpowiedzi."];
}

// ── Helpers ──────────────────────────────────────────────────
function geminiCurl(string $url, array $payload): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => 120,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);

    $response = curl_exec($ch);

    if (curl_errno($ch)) {
        $error = curl_error($ch);
        curl_close($ch);
        return ['data' => null, 'error' => 'cURL: ' . $error];
    }

    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode($response, true) ?? [];

    if ($httpCode >= 400) {
        $msg = $data['error']['message'] ?? "HTTP {$httpCode}";
        return ['data' => null, 'error' => $msg];
    }

    return ['data' => $data, 'error' => null];
}

function handleGeminiError(string $model, string $error): array {
    if (stripos($error, 'API_KEY') !== false || str_contains($error, '401') || str_contains($error, '403')) {
        return ['success' => false, 'error' => 'Nieprawidlowy klucz Gemini API.'];
    }
    if (str_contains($error, '429') || stripos($error, 'RESOURCE_EXHAUSTED') !== false) {
        if (str_contains($error, 'limit: 0') || str_contains($error, 'limit:0')) {
            return ['success' => false, 'error' => 'Generowanie obrazkow wymaga platnego planu Gemini API.'];
        }
        return ['success' => false, 'error' => "{$model}: Limit zapytan. Sprobuj pozniej."];
    }
    return ['success' => false, 'error' => "{$model}: {$error}"];
}
