<?php
/**
 * Parse CSV or XLSX file for bulk article orders.
 * Returns raw headers and rows for client-side column mapping.
 */
require_once __DIR__ . '/../auth.php';
header('Content-Type: application/json');
requireLoginApi();

if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['error' => 'Brak pliku lub błąd uploadu']);
    exit;
}

$file = $_FILES['file'];
$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

try {
    if ($ext === 'xlsx') {
        $result = parseXlsxBulk($file['tmp_name']);
    } elseif ($ext === 'csv' || $ext === 'txt') {
        $result = parseCsvBulk($file['tmp_name']);
    } else {
        throw new RuntimeException('Nieobsługiwany format pliku. Dozwolone: CSV, XLSX.');
    }

    echo json_encode([
        'success' => true,
        'headers' => $result['headers'],
        'rows' => $result['rows'],
        'format' => $ext,
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

// ── CSV parser ──────────────────────────────────────────────
function parseCsvBulk(string $path): array {
    $content = file_get_contents($path);
    $content = preg_replace('/^\xEF\xBB\xBF/', '', $content); // strip BOM

    // Try to detect delimiter: ; or , or \t
    $firstLine = strtok($content, "\r\n");
    $semicolons = substr_count($firstLine, ';');
    $commas = substr_count($firstLine, ',');
    $tabs = substr_count($firstLine, "\t");
    $delimiter = ';';
    if ($tabs > $semicolons && $tabs > $commas) $delimiter = "\t";
    elseif ($commas > $semicolons) $delimiter = ',';

    $lines = preg_split('/\r?\n/', $content);
    $lines = array_filter($lines, fn($l) => trim($l) !== '');

    if (count($lines) < 2) {
        throw new RuntimeException('Plik CSV jest pusty lub ma tylko nagłówek');
    }

    // First line = headers
    $headers = array_map('trim', str_getcsv(array_shift($lines), $delimiter));

    $rows = [];
    foreach ($lines as $line) {
        $cells = array_map('trim', str_getcsv($line, $delimiter));
        // Pad or trim to match header count
        while (count($cells) < count($headers)) $cells[] = '';
        $row = [];
        foreach ($headers as $i => $h) {
            $row[$i] = $cells[$i] ?? '';
        }
        $rows[] = $row;
    }

    return ['headers' => $headers, 'rows' => $rows];
}

// ── XLSX parser ─────────────────────────────────────────────
function parseXlsxBulk(string $path): array {
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException('Nie można otworzyć pliku XLSX');
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

    // 3. Parse all rows into column-indexed arrays
    $rawRows = [];
    $maxColIndex = 0;
    foreach ($doc->getElementsByTagNameNS($ns, 'row') as $row) {
        $cells = [];
        foreach ($row->getElementsByTagNameNS($ns, 'c') as $cell) {
            $ref = $cell->getAttribute('r');
            $colLetters = preg_replace('/\d+/', '', $ref);
            $colIdx = colLetterToIndex($colLetters);
            $type = $cell->getAttribute('t');
            $vNodes = $cell->getElementsByTagNameNS($ns, 'v');
            $value = $vNodes->length > 0 ? $vNodes->item(0)->textContent : '';

            if ($type === 's') {
                $value = $sharedStrings[(int) $value] ?? '';
            } elseif ($type === 'inlineStr') {
                $isNodes = $cell->getElementsByTagNameNS($ns, 'is');
                if ($isNodes->length > 0) {
                    $tNodes = $isNodes->item(0)->getElementsByTagNameNS($ns, 't');
                    $value = '';
                    foreach ($tNodes as $tNode) {
                        $value .= $tNode->textContent;
                    }
                }
            }

            $cells[$colIdx] = trim($value);
            if ($colIdx > $maxColIndex) $maxColIndex = $colIdx;
        }
        $rawRows[] = $cells;
    }

    if (count($rawRows) < 2) {
        throw new RuntimeException('Plik XLSX jest pusty lub ma tylko nagłówek');
    }

    // 4. First row = headers
    $headerRow = array_shift($rawRows);
    $headers = [];
    for ($i = 0; $i <= $maxColIndex; $i++) {
        $headers[$i] = $headerRow[$i] ?? 'Kolumna ' . indexToColLetter($i);
    }

    // 5. Data rows
    $rows = [];
    foreach ($rawRows as $raw) {
        $row = [];
        for ($i = 0; $i <= $maxColIndex; $i++) {
            $row[$i] = $raw[$i] ?? '';
        }
        // Skip completely empty rows
        if (implode('', $row) === '') continue;
        $rows[] = $row;
    }

    return ['headers' => array_values($headers), 'rows' => $rows];
}

function colLetterToIndex(string $letters): int {
    $letters = strtoupper($letters);
    $index = 0;
    for ($i = 0; $i < strlen($letters); $i++) {
        $index = $index * 26 + (ord($letters[$i]) - ord('A') + 1);
    }
    return $index - 1;
}

function indexToColLetter(int $index): string {
    $letter = '';
    $index++;
    while ($index > 0) {
        $index--;
        $letter = chr(65 + ($index % 26)) . $letter;
        $index = intdiv($index, 26);
    }
    return $letter;
}
