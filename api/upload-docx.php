<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/docx_parser.php';
header('Content-Type: application/json');
requireLoginApi();

if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['error' => 'Brak pliku lub blad uploadu']);
    exit;
}

$tmpPath = $_FILES['file']['tmp_name'];
$filename = $_FILES['file']['name'];

try {
    $result = parseDocx($tmpPath, $filename);
    echo json_encode($result);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
