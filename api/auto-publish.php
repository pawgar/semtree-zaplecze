<?php
/**
 * Auto-publish API endpoint.
 * Manages content plans, queue, configs, and category mappings.
 */
require_once __DIR__ . '/../auth.php';
header('Content-Type: application/json');
requireLoginApi();

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    switch ($action) {
        // ── List sites with queue stats ─────────────────────
        case 'sites':
            $db = getDb();
            $sites = [];
            $result = $db->query('
                SELECT s.id, s.name, s.url,
                    apc.daily_limit, apc.use_speed_links, apc.use_inline_images, apc.random_author, apc.lang, apc.enabled
                FROM sites s
                LEFT JOIN auto_publish_config apc ON apc.site_id = s.id
                ORDER BY s.name
            ');
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $siteId = $row['id'];
                // Queue stats
                $st = $db->prepare('SELECT status, COUNT(*) as cnt FROM auto_publish_queue WHERE site_id = :sid GROUP BY status');
                $st->bindValue(':sid', $siteId, SQLITE3_INTEGER);
                $res = $st->execute();
                $stats = ['pending' => 0, 'generating' => 0, 'generated' => 0, 'publishing' => 0, 'published' => 0, 'error' => 0];
                while ($s = $res->fetchArray(SQLITE3_ASSOC)) {
                    $stats[$s['status']] = (int)$s['cnt'];
                }
                $row['queue'] = $stats;
                $row['queue_total'] = array_sum($stats);

                // Count unmapped categories (pending articles with category but no wp_category_id)
                $umSt = $db->prepare('
                    SELECT COUNT(DISTINCT category_name) as cnt
                    FROM auto_publish_queue
                    WHERE site_id = :sid AND category_name != "" AND wp_category_id IS NULL AND status = "pending"
                    AND LOWER(category_name) NOT IN (
                        SELECT LOWER(category_name) FROM auto_publish_category_map WHERE site_id = :sid2
                    )
                ');
                $umSt->bindValue(':sid', $siteId, SQLITE3_INTEGER);
                $umSt->bindValue(':sid2', $siteId, SQLITE3_INTEGER);
                $umRes = $umSt->execute()->fetchArray(SQLITE3_ASSOC);
                $row['unmapped_categories'] = (int)($umRes['cnt'] ?? 0);

                $sites[] = $row;
            }
            echo json_encode(['success' => true, 'sites' => $sites]);
            break;

        // ── Upload content plan XLSX ────────────────────────
        case 'upload-plan':
            if ($method !== 'POST') throw new RuntimeException('POST required');
            $siteId = (int)($_POST['site_id'] ?? 0);
            if (!$siteId) throw new RuntimeException('Brak site_id');
            if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
                throw new RuntimeException('Brak pliku lub błąd uploadu');
            }

            $articles = parseContentPlan($_FILES['file']['tmp_name']);
            if (empty($articles)) throw new RuntimeException('Nie znaleziono artykułów w pliku');

            $db = getDb();

            // Collect unique categories for mapping
            $categories = [];
            foreach ($articles as $a) {
                $cat = trim($a['category_name'] ?? '');
                if ($cat && !in_array($cat, $categories)) $categories[] = $cat;
            }

            // Insert into queue
            $inserted = 0;
            $stmt = $db->prepare('INSERT INTO auto_publish_queue (site_id, title, main_keyword, secondary_keywords, category_name, notes) VALUES (:sid, :t, :mk, :sk, :cn, :n)');
            foreach ($articles as $a) {
                $stmt->bindValue(':sid', $siteId, SQLITE3_INTEGER);
                $stmt->bindValue(':t', $a['title'], SQLITE3_TEXT);
                $stmt->bindValue(':mk', $a['main_keyword'] ?? '', SQLITE3_TEXT);
                $stmt->bindValue(':sk', $a['secondary_keywords'] ?? '', SQLITE3_TEXT);
                $stmt->bindValue(':cn', $a['category_name'] ?? '', SQLITE3_TEXT);
                $stmt->bindValue(':n', $a['notes'] ?? '', SQLITE3_TEXT);
                $stmt->execute();
                $stmt->reset();
                $inserted++;
            }

            // Ensure config exists
            $db->exec("INSERT OR IGNORE INTO auto_publish_config (site_id) VALUES ($siteId)");

            echo json_encode([
                'success' => true,
                'inserted' => $inserted,
                'categories' => $categories,
                'message' => "Załadowano $inserted artykułów do kolejki."
            ]);
            break;

        // ── Save site config ────────────────────────────────
        case 'save-config':
            if ($method !== 'POST') throw new RuntimeException('POST required');
            $input = json_decode(file_get_contents('php://input'), true);
            $siteId = (int)($input['site_id'] ?? 0);
            if (!$siteId) throw new RuntimeException('Brak site_id');

            $db = getDb();
            $stmt = $db->prepare('INSERT OR REPLACE INTO auto_publish_config (site_id, daily_limit, use_speed_links, use_inline_images, random_author, lang, enabled) VALUES (:sid, :dl, :sl, :ii, :ra, :lang, :en)');
            $stmt->bindValue(':sid', $siteId, SQLITE3_INTEGER);
            $stmt->bindValue(':dl', (int)($input['daily_limit'] ?? 1), SQLITE3_INTEGER);
            $stmt->bindValue(':sl', (int)($input['use_speed_links'] ?? 0), SQLITE3_INTEGER);
            $stmt->bindValue(':ii', (int)($input['use_inline_images'] ?? 0), SQLITE3_INTEGER);
            $stmt->bindValue(':ra', (int)($input['random_author'] ?? 0), SQLITE3_INTEGER);
            $stmt->bindValue(':lang', $input['lang'] ?? 'pl', SQLITE3_TEXT);
            $stmt->bindValue(':en', (int)($input['enabled'] ?? 1), SQLITE3_INTEGER);
            $stmt->execute();

            echo json_encode(['success' => true]);
            break;

        // ── Get queue for a site ────────────────────────────
        case 'queue':
            $siteId = (int)($_GET['site_id'] ?? 0);
            if (!$siteId) throw new RuntimeException('Brak site_id');
            $db = getDb();
            $stmt = $db->prepare('SELECT * FROM auto_publish_queue WHERE site_id = :sid ORDER BY id');
            $stmt->bindValue(':sid', $siteId, SQLITE3_INTEGER);
            $result = $stmt->execute();
            $items = [];
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) $items[] = $row;
            echo json_encode(['success' => true, 'items' => $items]);
            break;

        // ── Delete queue items ──────────────────────────────
        case 'clear-queue':
            if ($method !== 'POST') throw new RuntimeException('POST required');
            $input = json_decode(file_get_contents('php://input'), true);
            $siteId = (int)($input['site_id'] ?? 0);
            $status = $input['status'] ?? 'pending'; // clear only pending by default
            if (!$siteId) throw new RuntimeException('Brak site_id');

            $db = getDb();
            if ($status === 'all') {
                $stmt = $db->prepare('DELETE FROM auto_publish_queue WHERE site_id = :sid AND status != "published"');
            } else {
                $stmt = $db->prepare('DELETE FROM auto_publish_queue WHERE site_id = :sid AND status = :st');
                $stmt->bindValue(':st', $status, SQLITE3_TEXT);
            }
            $stmt->bindValue(':sid', $siteId, SQLITE3_INTEGER);
            $stmt->execute();
            $deleted = $db->changes();

            echo json_encode(['success' => true, 'deleted' => $deleted]);
            break;

        // ── Get category mappings ───────────────────────────
        case 'category-map':
            $siteId = (int)($_GET['site_id'] ?? 0);
            if (!$siteId) throw new RuntimeException('Brak site_id');
            $db = getDb();

            // Get saved mappings
            $stmt = $db->prepare('SELECT * FROM auto_publish_category_map WHERE site_id = :sid');
            $stmt->bindValue(':sid', $siteId, SQLITE3_INTEGER);
            $result = $stmt->execute();
            $mappings = [];
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) $mappings[] = $row;

            // Get unmapped categories from queue
            $stmt2 = $db->prepare('SELECT DISTINCT category_name FROM auto_publish_queue WHERE site_id = :sid AND category_name != "" AND wp_category_id IS NULL');
            $stmt2->bindValue(':sid', $siteId, SQLITE3_INTEGER);
            $res2 = $stmt2->execute();
            $unmapped = [];
            while ($row = $res2->fetchArray(SQLITE3_ASSOC)) {
                $catName = $row['category_name'];
                // Check if mapping already exists
                $exists = false;
                foreach ($mappings as $m) {
                    if (mb_strtolower($m['category_name']) === mb_strtolower($catName)) { $exists = true; break; }
                }
                if (!$exists) $unmapped[] = $catName;
            }

            echo json_encode(['success' => true, 'mappings' => $mappings, 'unmapped' => $unmapped]);
            break;

        // ── Save category mapping ───────────────────────────
        case 'save-category-map':
            if ($method !== 'POST') throw new RuntimeException('POST required');
            $input = json_decode(file_get_contents('php://input'), true);
            $siteId = (int)($input['site_id'] ?? 0);
            $maps = $input['mappings'] ?? [];
            if (!$siteId) throw new RuntimeException('Brak site_id');

            $db = getDb();
            $stmt = $db->prepare('INSERT OR REPLACE INTO auto_publish_category_map (site_id, category_name, wp_category_id, wp_category_name) VALUES (:sid, :cn, :wid, :wn)');
            foreach ($maps as $m) {
                $stmt->bindValue(':sid', $siteId, SQLITE3_INTEGER);
                $stmt->bindValue(':cn', $m['category_name'], SQLITE3_TEXT);
                $stmt->bindValue(':wid', (int)$m['wp_category_id'], SQLITE3_INTEGER);
                $stmt->bindValue(':wn', $m['wp_category_name'] ?? '', SQLITE3_TEXT);
                $stmt->execute();
                $stmt->reset();
            }

            // Apply mappings to queue items
            $applyStmt = $db->prepare('UPDATE auto_publish_queue SET wp_category_id = :wid WHERE site_id = :sid AND LOWER(category_name) = LOWER(:cn) AND status = "pending"');
            foreach ($maps as $m) {
                $applyStmt->bindValue(':wid', (int)$m['wp_category_id'], SQLITE3_INTEGER);
                $applyStmt->bindValue(':sid', $siteId, SQLITE3_INTEGER);
                $applyStmt->bindValue(':cn', $m['category_name'], SQLITE3_TEXT);
                $applyStmt->execute();
                $applyStmt->reset();
            }

            echo json_encode(['success' => true]);
            break;

        default:
            throw new RuntimeException('Nieznana akcja: ' . $action);
    }
} catch (Exception $e) {
    http_response_code($e->getCode() >= 400 ? $e->getCode() : 400);
    echo json_encode(['error' => $e->getMessage()]);
}

// ── Content plan parser (handles inlineStr) ─────────────────
function parseContentPlan(string $path): array {
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) throw new RuntimeException('Nie można otworzyć pliku XLSX');

    // Read shared strings
    $sharedStrings = [];
    $ssXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($ssXml) {
        $doc = new DOMDocument();
        $doc->loadXML($ssXml, LIBXML_NOERROR | LIBXML_NOWARNING);
        $ns = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
        foreach ($doc->getElementsByTagNameNS($ns, 'si') as $si) {
            $text = '';
            foreach ($si->getElementsByTagNameNS($ns, 't') as $t) $text .= $t->textContent;
            $sharedStrings[] = $text;
        }
    }

    // Read first worksheet
    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();
    if (!$sheetXml) throw new RuntimeException('Nie znaleziono arkusza w pliku XLSX');

    $doc = new DOMDocument();
    $doc->loadXML($sheetXml, LIBXML_NOERROR | LIBXML_NOWARNING);
    $ns = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    // Parse rows
    $rows = [];
    foreach ($doc->getElementsByTagNameNS($ns, 'row') as $row) {
        $cells = [];
        foreach ($row->getElementsByTagNameNS($ns, 'c') as $cell) {
            $ref = $cell->getAttribute('r');
            $col = preg_replace('/\d+/', '', $ref);
            $type = $cell->getAttribute('t');

            if ($type === 'inlineStr') {
                $value = '';
                $isNodes = $cell->getElementsByTagNameNS($ns, 'is');
                if ($isNodes->length > 0) {
                    foreach ($isNodes->item(0)->getElementsByTagNameNS($ns, 't') as $t) {
                        $value .= $t->textContent;
                    }
                }
            } elseif ($type === 's') {
                $vNodes = $cell->getElementsByTagNameNS($ns, 'v');
                $idx = $vNodes->length > 0 ? (int)$vNodes->item(0)->textContent : 0;
                $value = $sharedStrings[$idx] ?? '';
            } else {
                $vNodes = $cell->getElementsByTagNameNS($ns, 'v');
                $value = $vNodes->length > 0 ? $vNodes->item(0)->textContent : '';
            }

            $cells[$col] = trim($value);
        }
        $rows[] = $cells;
    }

    if (count($rows) < 2) throw new RuntimeException('Plik XLSX jest pusty lub ma tylko nagłówek');

    // Auto-detect columns from header row
    $colMap = ['title' => null, 'keyword' => null, 'secondary' => null, 'category' => null, 'notes' => null];
    $titlePat = ['tytuł', 'tytul', 'title', 'temat'];
    $kwPat = ['główne słowo', 'glowne slowo', 'main keyword', 'keyword', 'słowo kluczowe'];
    $secPat = ['poboczne', 'secondary', 'dodatkowe słowa', 'supporting'];
    $catPat = ['kategoria', 'category', 'cat', 'dział'];
    $notesPat = ['dodatkowe informacje', 'notatki', 'notes', 'uwagi', 'wskazówki'];

    $headerRow = $rows[0];
    foreach ($headerRow as $col => $val) {
        $lower = mb_strtolower($val);
        if (!$colMap['title']) foreach ($titlePat as $p) { if (str_contains($lower, $p)) { $colMap['title'] = $col; break; } }
        if (!$colMap['keyword']) foreach ($kwPat as $p) { if (str_contains($lower, $p)) { $colMap['keyword'] = $col; break; } }
        if (!$colMap['secondary']) foreach ($secPat as $p) { if (str_contains($lower, $p)) { $colMap['secondary'] = $col; break; } }
        if (!$colMap['category']) foreach ($catPat as $p) { if (str_contains($lower, $p)) { $colMap['category'] = $col; break; } }
        if (!$colMap['notes']) foreach ($notesPat as $p) { if (str_contains($lower, $p)) { $colMap['notes'] = $col; break; } }
    }

    // Fallback
    if (!$colMap['title']) $colMap['title'] = 'A';
    if (!$colMap['keyword']) $colMap['keyword'] = 'B';
    if (!$colMap['category']) $colMap['category'] = 'D';

    // Extract articles
    $articles = [];
    for ($i = 1; $i < count($rows); $i++) {
        $r = $rows[$i];
        $title = $r[$colMap['title']] ?? '';
        if (empty($title) || is_numeric($title)) continue;

        $articles[] = [
            'title' => $title,
            'main_keyword' => $colMap['keyword'] ? ($r[$colMap['keyword']] ?? '') : '',
            'secondary_keywords' => $colMap['secondary'] ? ($r[$colMap['secondary']] ?? '') : '',
            'category_name' => $colMap['category'] ? ($r[$colMap['category']] ?? '') : '',
            'notes' => $colMap['notes'] ? ($r[$colMap['notes']] ?? '') : '',
        ];
    }

    return $articles;
}
