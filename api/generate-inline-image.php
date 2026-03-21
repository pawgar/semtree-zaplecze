<?php
/**
 * Generate an inline image for a blog section using Gemini API.
 * Returns image with friendly filename and stripped AI metadata.
 */
require_once __DIR__ . '/../auth.php';
header('Content-Type: application/json');
requireLoginApi();

$input = json_decode(file_get_contents('php://input'), true);
$sectionTitle = trim($input['section_title'] ?? '');
$articleTitle = trim($input['article_title'] ?? '');

if (!$sectionTitle) {
    http_response_code(400);
    echo json_encode(['error' => 'Brak tytulu sekcji']);
    exit;
}

// Get Gemini API key
$db = getDb();
$stmt = $db->prepare('SELECT value FROM settings WHERE key = "gemini_api_key"');
$row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
$apiKey = $row ? trim($row['value']) : '';

if (!$apiKey) {
    echo json_encode(['error' => 'Gemini API key nie jest skonfigurowany.']);
    exit;
}

$prompt = "Create a relevant, high-quality illustration for a blog article section. "
    . "Article topic: \"{$articleTitle}\". "
    . "Section: \"{$sectionTitle}\". "
    . "The image should be relevant to this specific section content, "
    . "photorealistic style, visually appealing. No text on the image. "
    . "Landscape orientation, wide format.";

// Models to try
$models = ['gemini-2.5-flash-image', 'gemini-3.1-flash-image-preview', 'gemini-3-pro-image-preview'];
$errors = [];

foreach ($models as $model) {
    $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";
    $payload = [
        'contents' => [['parts' => [['text' => $prompt]]]],
        'generationConfig' => [
            'responseModalities' => ['IMAGE'],
            'imageConfig' => ['aspectRatio' => '3:2'],
        ],
    ];

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
        $errors[] = "{$model}: " . curl_error($ch);
        curl_close($ch);
        continue;
    }

    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode >= 400) {
        $data = json_decode($response, true) ?? [];
        $errors[] = "{$model}: " . ($data['error']['message'] ?? "HTTP {$httpCode}");
        continue;
    }

    $data = json_decode($response, true) ?? [];
    $candidates = $data['candidates'] ?? [];
    if (empty($candidates)) {
        $errors[] = "{$model}: brak kandydatow";
        continue;
    }

    $parts = $candidates[0]['content']['parts'] ?? [];
    foreach ($parts as $part) {
        if (isset($part['inlineData']['data'])) {
            $rawBinary = base64_decode($part['inlineData']['data']);

            // Strip AI metadata by re-encoding through GD
            $img = @imagecreatefromstring($rawBinary);
            if ($img) {
                $w = imagesx($img);
                $h = imagesy($img);
                $canvas = imagecreatetruecolor($w, $h);
                $white = imagecolorallocate($canvas, 255, 255, 255);
                imagefill($canvas, 0, 0, $white);
                imagecopy($canvas, $img, 0, 0, 0, 0, $w, $h);
                imagedestroy($img);
                ob_start();
                imagejpeg($canvas, null, 90);
                $cleanData = ob_get_clean();
                imagedestroy($canvas);
            } else {
                $cleanData = $rawBinary;
            }

            // Create friendly filename from section title
            $slug = slugifyFilename($sectionTitle);
            $filename = $slug . '.jpg';

            echo json_encode([
                'success' => true,
                'image_data' => base64_encode($cleanData),
                'image_filename' => $filename,
                'alt_text' => $sectionTitle,
                'mime_type' => 'image/jpeg',
            ]);
            exit;
        }
    }

    $errors[] = "{$model}: brak obrazka w odpowiedzi";
}

echo json_encode(['error' => 'Nie udalo sie wygenerowac grafiki: ' . implode('; ', $errors)]);

function slugifyFilename(string $text): string {
    $pl = ['ą','ć','ę','ł','ń','ó','ś','ź','ż','Ą','Ć','Ę','Ł','Ń','Ó','Ś','Ź','Ż'];
    $en = ['a','c','e','l','n','o','s','z','z','a','c','e','l','n','o','s','z','z'];
    $text = str_replace($pl, $en, $text);
    $text = mb_strtolower($text, 'UTF-8');
    $text = preg_replace('/[^a-z0-9\s-]/', '', $text);
    $text = preg_replace('/[\s-]+/', '-', $text);
    $text = trim($text, '-');
    if (mb_strlen($text) > 60) $text = mb_substr($text, 0, 60);
    return $text ?: 'ilustracja';
}
