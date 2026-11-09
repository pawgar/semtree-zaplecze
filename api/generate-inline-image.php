<?php
/**
 * Generate an inline image for a blog section using Gemini API.
 * Returns image with friendly filename and stripped AI metadata.
 */
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/cover_image.php';
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

// Bez napisów: tytuł nie idzie do promptu, gotowa grafika jest sprawdzana pod kątem tekstu
$errors = [];
$rawBinary = generateTextFreeImage($apiKey, $articleTitle ?: $sectionTitle, $sectionTitle, '3:2', $errors);
if ($rawBinary !== null) {

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
