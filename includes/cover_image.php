<?php
/**
 * Grafiki do wpisów (okładki i ilustracje sekcji) przez Gemini / Imagen — BEZ NAPISÓW.
 *
 * Modele obrazkowe źle renderują tekst (zwłaszcza polskie znaki), a tytuł podany w cudzysłowie
 * w prompcie zachęca je do wpisania go na grafikę. Dlatego:
 *  1. tytuł nie trafia do promptu — tani model Claude zamienia temat na opis sceny (EN) bez słów,
 *  2. prompt zawiera twardy zakaz tekstu,
 *  3. gotową grafikę ogląda tani model Claude; jeśli widzi napisy — generujemy ponownie (maks. 3 próby).
 */
require_once __DIR__ . '/claude_client.php';

const IMAGE_MAX_ATTEMPTS = 3;

/**
 * Generuje grafikę bez napisów. Zwraca surowe bajty obrazka (PNG/JPEG) albo null ($errors wyjaśnia).
 * @param string $subject   temat (tytuł artykułu)
 * @param string $section   opcjonalnie nagłówek sekcji (ilustracja w treści)
 * @param string $aspect    '16:9' (okładka) | '3:2' (sekcja)
 */
function generateTextFreeImage(string $apiKey, string $subject, string $section, string $aspect, array &$errors = [], ?callable $log = null): ?string {
    $log = $log ?? fn($m) => null;
    $prompt = imageBuildPrompt(imageSceneDescription($subject, $section));
    for ($attempt = 1; $attempt <= IMAGE_MAX_ATTEMPTS; $attempt++) {
        $bin = geminiImageRaw($apiKey, $prompt, $aspect, $errors);
        if ($bin === null) return null;
        $text = imageDetectText($bin);
        if ($text === null) return $bin;
        $log("Grafika z napisem („{$text}”), próba $attempt/" . IMAGE_MAX_ATTEMPTS . ' — generuję ponownie');
    }
    $errors[] = 'Każda z ' . IMAGE_MAX_ATTEMPTS . ' prób zawierała napisy — grafika pominięta';
    return null;
}

/** Opis sceny po angielsku, bez tytułu i bez słów do wyrenderowania. Fallback: ogólna scena. */
function imageSceneDescription(string $subject, string $section = ''): string {
    $topic = $section !== '' ? "Article: {$subject}\nSection: {$section}" : "Article: {$subject}";
    try {
        $resp = claudeRequest([
            'model' => CLAUDE_AUX_MODEL,
            'messages' => [['role' => 'user', 'content' =>
                "Describe ONE concrete, photographable scene (in English, 1-2 sentences, max 50 words) that would work as a blog image for the topic below.\n"
                . "Rules: real objects, people, food, places or nature only. No text of any kind in the scene: no signs, labels, packaging with print, "
                . "screens, documents, books with titles, charts, diagrams, infographics, letters or numbers. Do not quote or translate the title.\n\n{$topic}"]],
            'json_schema' => [
                'type' => 'object',
                'properties' => ['scene' => ['type' => 'string']],
                'required' => ['scene'],
                'additionalProperties' => false,
            ],
            'effort' => 'low',
            'max_tokens' => 600,
            'stream' => false,
            'retries' => 1,
            'timeout' => 60,
        ]);
        $scene = trim((string)(claudeParseJson($resp['text'])['scene'] ?? ''));
        if ($scene !== '') return $scene;
    } catch (Throwable $e) {
        // brak klucza / błąd API — ogólna scena poniżej
    }
    return 'A calm, natural editorial scene with soft daylight related to everyday life and well-being.';
}

function imageBuildPrompt(string $scene): string {
    return "Photorealistic editorial photograph. Scene: {$scene}\n"
        . "Natural light, shallow depth of field, clean composition, wide landscape format.\n"
        . "STRICT RULE: the image must contain absolutely NO text — no letters, words, numbers, captions, titles, "
        . "labels, logos, watermarks, signs, typography or symbols resembling writing. Not a poster, not an infographic, not a diagram.";
}

/**
 * Sprawdza tanim modelem, czy na grafice jest tekst. Zwraca znaleziony tekst albo null (brak / błąd sprawdzenia).
 */
function imageDetectText(string $binary): ?string {
    $img = @imagecreatefromstring($binary);
    if (!$img) return null;
    $w = imagesx($img); $h = imagesy($img);
    $scale = min(1, 1024 / max($w, $h));
    $small = imagecreatetruecolor(max(1, (int)($w * $scale)), max(1, (int)($h * $scale)));
    imagecopyresampled($small, $img, 0, 0, 0, 0, imagesx($small), imagesy($small), $w, $h);
    ob_start(); imagejpeg($small, null, 85); $jpeg = ob_get_clean();
    imagedestroy($img); imagedestroy($small);

    try {
        $resp = claudeRequest([
            'model' => CLAUDE_AUX_MODEL,
            'messages' => [['role' => 'user', 'content' => [
                ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => 'image/jpeg', 'data' => base64_encode($jpeg)]],
                ['type' => 'text', 'text' => 'Does this image contain any visible text: letters, words, numbers, captions, labels, logos or pseudo-letters (garbled writing)? '
                    . 'Ignore tiny unreadable texture. If yes, quote what you see (max 60 chars).'],
            ]]],
            'json_schema' => [
                'type' => 'object',
                'properties' => ['has_text' => ['type' => 'boolean'], 'text' => ['type' => 'string']],
                'required' => ['has_text', 'text'],
                'additionalProperties' => false,
            ],
            'effort' => 'low',
            'max_tokens' => 400,
            'stream' => false,
            'retries' => 1,
            'timeout' => 60,
        ]);
        $r = claudeParseJson($resp['text']);
        if (!empty($r['has_text'])) return mb_substr(trim((string)($r['text'] ?? '')) ?: 'tekst', 0, 60);
    } catch (Throwable $e) {
        // sprawdzenie niedostępne — nie blokujemy publikacji
    }
    return null;
}

/** Gemini native, potem Imagen. Zwraca surowe bajty obrazka albo null. */
function geminiImageRaw(string $apiKey, string $prompt, string $aspect, array &$errors): ?string {
    $geminiModels = ['gemini-2.5-flash-image', 'gemini-3.1-flash-image-preview', 'gemini-3-pro-image-preview'];
    $imagenModels = ['imagen-4.0-fast-generate-001', 'imagen-4.0-generate-001'];

    foreach ($geminiModels as $model) {
        $r = geminiImageHttp("https://generativelanguage.googleapis.com/v1beta/models/$model:generateContent?key=$apiKey", [
            'contents' => [['parts' => [['text' => $prompt]]]],
            'generationConfig' => ['responseModalities' => ['IMAGE'], 'imageConfig' => ['aspectRatio' => $aspect]],
        ]);
        if ($r['error']) { $errors[] = "$model: {$r['error']}"; continue; }
        foreach ($r['data']['candidates'][0]['content']['parts'] ?? [] as $part) {
            if (isset($part['inlineData']['data'])) return base64_decode($part['inlineData']['data']);
        }
        $errors[] = "$model: brak obrazka w odpowiedzi";
    }
    foreach ($imagenModels as $model) {
        $r = geminiImageHttp("https://generativelanguage.googleapis.com/v1beta/models/$model:predict?key=$apiKey", [
            'instances' => [['prompt' => $prompt]],
            'parameters' => ['sampleCount' => 1, 'aspectRatio' => $aspect],
        ]);
        if ($r['error']) { $errors[] = "$model: {$r['error']}"; continue; }
        if (!empty($r['data']['predictions'][0]['bytesBase64Encoded'])) return base64_decode($r['data']['predictions'][0]['bytesBase64Encoded']);
        $errors[] = "$model: brak obrazka w odpowiedzi";
    }
    return null;
}

function geminiImageHttp(string $url, array $payload): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => 120,
    ]);
    $response = curl_exec($ch);
    $curlErr = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($curlErr) return ['error' => $curlErr, 'data' => null];
    $data = json_decode((string)$response, true) ?? [];
    if ($code !== 200) return ['error' => $data['error']['message'] ?? "HTTP $code", 'data' => null];
    return ['error' => null, 'data' => $data];
}
