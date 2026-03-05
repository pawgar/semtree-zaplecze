<?php
/**
 * Parse XLSX content plan file.
 * Returns rows with: title, category_name, docx_filename.
 */
require_once __DIR__ . '/../auth.php';
header('Content-Type: application/json');
requireAdminApi();

if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['error' => 'Brak pliku lub blad uploadu']);
    exit;
}

try {
    $rows = parseXlsx($_FILES['file']['tmp_name']);
    echo json_encode(['success' => true, 'rows' => $rows]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

function parseXlsx(string $path): array {
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException('Nie mozna otworzyc pliku XLSX');
    }

    // 1. Read shared strings table
    $sharedStrings = [];
    $ssXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($ssXml) {
        $doc = new DOMDocument();
        $doc->loadXML($ssXml, LIBXML_NOERROR | LIBXML_NOWARNING);
        $ns = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
        foreach ($doc->getElementsByTagNameNS($ns, 'si') as $si) {
            $text = '';
            foreach ($si->getElementsByTagNameNS($ns, 't') as $t) {
                $text .= $t->textContent;
            }
            $sharedStrings[] = $text;
        }
    }

    // 2. Read first worksheet
    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();

    if (!$sheetXml) {
        throw new RuntimeException('Nie znaleziono arkusza w pliku XLSX');
    }

    $doc = new DOMDocument();
    $doc->loadXML($sheetXml, LIBXML_NOERROR | LIBXML_NOWARNING);
    $ns = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    // 3. Parse all rows
    $rows = [];
    foreach ($doc->getElementsByTagNameNS($ns, 'row') as $row) {
        $cells = [];
        foreach ($row->getElementsByTagNameNS($ns, 'c') as $cell) {
            $ref = $cell->getAttribute('r');               // e.g. "A1", "B2"
            $col = preg_replace('/\d+/', '', $ref);        // column letter(s)
            $type = $cell->getAttribute('t');               // 's' = shared string
            $vNodes = $cell->getElementsByTagNameNS($ns, 'v');
            $value = $vNodes->length > 0 ? $vNodes->item(0)->textContent : '';

            if ($type === 's') {
                $value = $sharedStrings[(int) $value] ?? '';
            }

            $cells[$col] = $value;
        }
        $rows[] = $cells;
    }

    if (count($rows) < 2) {
        throw new RuntimeException('Plik XLSX jest pusty lub ma tylko naglowek');
    }

    // 4. Skip header, extract articles
    $articles = [];
    for ($i = 1; $i < count($rows); $i++) {
        $r = $rows[$i];
        $title = trim($r['A'] ?? '');
        $category = trim($r['B'] ?? '');
        $docxPath = trim($r['C'] ?? '');

        if (empty($title)) continue;

        // Extract filename and keep full path for local reading
        $docxFilename = basename(str_replace('\\', '/', $docxPath));

        $articles[] = [
            'title' => $title,
            'category_name' => $category,
            'docx_filename' => $docxFilename,
            'docx_path' => $docxPath,
        ];
    }

    if (empty($articles)) {
        throw new RuntimeException('Nie znaleziono artykulow w pliku XLSX');
    }

    return $articles;
}
