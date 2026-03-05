<?php
/**
 * Read and parse a DOCX file from local filesystem path.
 * Used by Import Masowy to auto-load DOCX files referenced in XLSX.
 */
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/docx_parser.php';
header('Content-Type: application/json');
requireAdminApi();

$input = json_decode(file_get_contents('php://input'), true);
$path = $input['path'] ?? '';

if (!$path) {
    http_response_code(400);
    echo json_encode(['error' => 'Brak sciezki do pliku']);
    exit;
}

// Normalize path separators
$path = str_replace('/', '\\', $path);

if (!file_exists($path)) {
    http_response_code(404);
    echo json_encode(['error' => 'Plik nie istnieje: ' . basename($path), 'path' => $path]);
    exit;
}

$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
if ($ext !== 'docx') {
    http_response_code(400);
    echo json_encode(['error' => 'Plik nie jest plikiem DOCX: ' . basename($path)]);
    exit;
}

try {
    $result = parseDocx($path, basename($path));
    echo json_encode($result);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
