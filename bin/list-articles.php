<?php
/**
 * Lista artykulow opublikowanych w danym miesiacu dla stron z konkretna kategoria.
 *
 *   php bin/list-articles.php --category "Prywatne zaplecze klienta" --month 2026-05
 *   php bin/list-articles.php --category "Prywatne zaplecze klienta" --month 2026-05 --format urls
 *   php bin/list-articles.php --category "Prywatne zaplecze klienta" --from 2026-05-01 --to 2026-05-31
 *
 * Argumenty:
 *   --category "<nazwa>"   wymagane; szuka kategorii w sites.categories (case-insensitive, partial match)
 *   --month YYYY-MM        skrot do --from=YYYY-MM-01 --to=YYYY-MM-<lastday>
 *   --from YYYY-MM-DD      data od (inclusive)
 *   --to YYYY-MM-DD        data do (inclusive)
 *   --format urls|table|csv|json   domyslnie: table
 *   --source manual|auto|both       domyslnie: both (publikacje recznie + auto-publish)
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

require __DIR__ . '/../db.php';

// ── Parse args ──────────────────────────────────────────────
$args = [];
for ($i = 1; $i < $argc; $i++) {
    $a = $argv[$i];
    if (strpos($a, '--') === 0) {
        $key = substr($a, 2);
        $val = $argv[$i + 1] ?? '';
        if ($val !== '' && strpos($val, '--') !== 0) {
            $args[$key] = $val;
            $i++;
        } else {
            $args[$key] = true;
        }
    }
}

$category = trim((string)($args['category'] ?? ''));
$format = (string)($args['format'] ?? 'table');
$source = (string)($args['source'] ?? 'both');

if (!$category) {
    fwrite(STDERR, "Brak --category. Uzycie: php bin/list-articles.php --category \"Prywatne zaplecze klienta\" --month 2026-05\n");
    exit(1);
}

// Date range
$from = $to = null;
if (!empty($args['month'])) {
    if (!preg_match('/^\d{4}-\d{2}$/', (string)$args['month'])) {
        fwrite(STDERR, "Nieprawidlowy format --month, oczekiwane YYYY-MM\n");
        exit(1);
    }
    $from = $args['month'] . '-01';
    $to = date('Y-m-t', strtotime($from));
} else {
    if (!empty($args['from'])) $from = (string)$args['from'];
    if (!empty($args['to']))   $to   = (string)$args['to'];
}
if (!$from || !$to) {
    fwrite(STDERR, "Podaj zakres dat (--month YYYY-MM albo --from + --to)\n");
    exit(1);
}

$fromTs = $from . ' 00:00:00';
$toTs   = $to   . ' 23:59:59';

$db = getDb();

// ── Manual publications ─────────────────────────────────────
$rows = [];
if ($source === 'manual' || $source === 'both') {
    $stmt = $db->prepare("
        SELECT p.post_url, p.post_title, p.created_at, s.name AS site_name, s.url AS site_url, s.categories,
               u.username, 'manual' AS source_type
        FROM publications p
        JOIN sites s ON s.id = p.site_id
        LEFT JOIN users u ON u.id = p.user_id
        WHERE p.created_at >= :from AND p.created_at <= :to
          AND s.categories IS NOT NULL
          AND LOWER(',' || REPLACE(s.categories, ', ', ',') || ',') LIKE :catlike
        ORDER BY p.created_at ASC
    ");
    $stmt->bindValue(':from', $fromTs, SQLITE3_TEXT);
    $stmt->bindValue(':to', $toTs, SQLITE3_TEXT);
    $stmt->bindValue(':catlike', '%,' . strtolower($category) . ',%', SQLITE3_TEXT);
    $r = $stmt->execute();
    while ($row = $r->fetchArray(SQLITE3_ASSOC)) $rows[] = $row;
}

// ── Auto-publish queue (published items) ────────────────────
if ($source === 'auto' || $source === 'both') {
    $stmt = $db->prepare("
        SELECT q.published_url AS post_url, q.title AS post_title, q.published_at AS created_at,
               s.name AS site_name, s.url AS site_url, s.categories,
               'auto-publish' AS username, 'auto' AS source_type
        FROM auto_publish_queue q
        JOIN sites s ON s.id = q.site_id
        WHERE q.status = 'published'
          AND q.published_at >= :from AND q.published_at <= :to
          AND q.published_url IS NOT NULL AND q.published_url <> ''
          AND s.categories IS NOT NULL
          AND LOWER(',' || REPLACE(s.categories, ', ', ',') || ',') LIKE :catlike
        ORDER BY q.published_at ASC
    ");
    $stmt->bindValue(':from', $fromTs, SQLITE3_TEXT);
    $stmt->bindValue(':to', $toTs, SQLITE3_TEXT);
    $stmt->bindValue(':catlike', '%,' . strtolower($category) . ',%', SQLITE3_TEXT);
    $r = $stmt->execute();
    while ($row = $r->fetchArray(SQLITE3_ASSOC)) $rows[] = $row;
}

// Dedup po URL (gdyby ten sam post byl w obu tabelach)
$seen = [];
$rows = array_values(array_filter($rows, function($r) use (&$seen) {
    $u = $r['post_url'] ?? '';
    if ($u === '' || isset($seen[$u])) return false;
    $seen[$u] = true;
    return true;
}));

// Sort chronologicznie
usort($rows, fn($a, $b) => strcmp($a['created_at'] ?? '', $b['created_at'] ?? ''));

// ── Output ──────────────────────────────────────────────────
fwrite(STDERR, sprintf("# Kategoria: %s | Zakres: %s..%s | Wynikow: %d\n", $category, $from, $to, count($rows)));

switch ($format) {
    case 'urls':
        foreach ($rows as $r) echo $r['post_url'] . "\n";
        break;
    case 'csv':
        echo "data,strona,tytul,url,zrodlo\n";
        foreach ($rows as $r) {
            echo csvField($r['created_at']) . ',' .
                 csvField($r['site_name']) . ',' .
                 csvField($r['post_title']) . ',' .
                 csvField($r['post_url']) . ',' .
                 csvField($r['source_type']) . "\n";
        }
        break;
    case 'json':
        echo json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        break;
    case 'table':
    default:
        echo str_repeat('─', 100) . "\n";
        printf("%-19s | %-25s | %s\n", 'DATA', 'STRONA', 'URL');
        echo str_repeat('─', 100) . "\n";
        foreach ($rows as $r) {
            printf("%-19s | %-25s | %s\n",
                substr($r['created_at'] ?? '', 0, 19),
                mb_substr($r['site_name'] ?? '', 0, 25),
                $r['post_url']);
        }
        echo str_repeat('─', 100) . "\n";
        echo "Razem: " . count($rows) . " artykulow\n";
}

function csvField($v): string {
    $v = (string)$v;
    if (strpbrk($v, ",\"\n") !== false) return '"' . str_replace('"', '""', $v) . '"';
    return $v;
}
