<?php
/**
 * Dumpuje JSON z kontekstem dla wszystkich stron ktorych kolejka auto-publish
 * jest wyczerpana (< threshold pendingow). Bez wolan zewnetrznego API — sama
 * odczytka z bazy.
 *
 * Wyjscie: JSON na stdout, gotowy do skopiowania i wkleijenia mi w rozmowie.
 * Ja z tego wygeneruje tematy i zapisze pliki CSV per domena.
 *
 *   php bin/dump-refill-context.php                        threshold=3 default
 *   php bin/dump-refill-context.php --threshold 10         gdy kolejka <10
 *   php bin/dump-refill-context.php --titles-limit 300     cap historical titles per site
 *   php bin/dump-refill-context.php --all                  wszystkie strony (nie tylko wyczerpane)
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

require __DIR__ . '/../db.php';

$args = ['threshold' => 3, 'titles-limit' => 300, 'all' => false];
for ($i = 1; $i < $argc; $i++) {
    $a = $argv[$i];
    if ($a === '--all') { $args['all'] = true; continue; }
    if (str_starts_with($a, '--')) {
        $key = substr($a, 2);
        $val = $argv[$i + 1] ?? '';
        if ($val !== '' && !str_starts_with($val, '--')) { $args[$key] = $val; $i++; }
    }
}
$threshold   = max(0, (int)$args['threshold']);
$titlesLimit = max(50, min(2000, (int)$args['titles-limit']));
$allSites    = (bool)$args['all'];

$db = getDb();

if ($allSites) {
    $stmt = $db->prepare('
        SELECT s.id, s.name, s.url, s.categories, COALESCE(apc.lang, "pl") AS lang, COALESCE(apc.enabled, 0) AS enabled,
               COALESCE((SELECT COUNT(*) FROM auto_publish_queue q WHERE q.site_id=s.id AND q.status="pending"), 0) AS pending_count
        FROM sites s
        LEFT JOIN auto_publish_config apc ON apc.site_id = s.id
        ORDER BY s.name
    ');
} else {
    $stmt = $db->prepare('
        SELECT s.id, s.name, s.url, s.categories, COALESCE(apc.lang, "pl") AS lang, apc.enabled,
               COALESCE(SUM(CASE WHEN q.status="pending" THEN 1 ELSE 0 END), 0) AS pending_count
        FROM sites s
        JOIN auto_publish_config apc ON apc.site_id = s.id AND apc.enabled = 1
        LEFT JOIN auto_publish_queue q ON q.site_id = s.id
        GROUP BY s.id
        HAVING pending_count < :thr
        ORDER BY s.name
    ');
    $stmt->bindValue(':thr', $threshold, SQLITE3_INTEGER);
}
$res = $stmt->execute();

$sites = [];
while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
    $siteId = (int)$row['id'];

    // Historia zajetych tytulow — WSZYSTKIE statusy z queue + wszystkie z publications
    $titles = [];
    $t = $db->prepare('SELECT title FROM auto_publish_queue WHERE site_id = :id AND title <> "" ORDER BY id DESC LIMIT :lim');
    $t->bindValue(':id', $siteId, SQLITE3_INTEGER);
    $t->bindValue(':lim', $titlesLimit, SQLITE3_INTEGER);
    $r = $t->execute();
    while ($x = $r->fetchArray(SQLITE3_ASSOC)) $titles[] = $x['title'];

    $t = $db->prepare('SELECT post_title FROM publications WHERE site_id = :id AND post_title <> "" ORDER BY created_at DESC LIMIT :lim');
    $t->bindValue(':id', $siteId, SQLITE3_INTEGER);
    $t->bindValue(':lim', $titlesLimit, SQLITE3_INTEGER);
    $r = $t->execute();
    while ($x = $r->fetchArray(SQLITE3_ASSOC)) $titles[] = $x['post_title'];

    // Dedup po znormalizowanym kluczu
    $seen = [];
    $titles = array_values(array_filter($titles, function($tt) use (&$seen) {
        $k = mb_strtolower(preg_replace('/\s+/', ' ', trim($tt)));
        if ($k === '' || isset($seen[$k])) return false;
        $seen[$k] = true;
        return true;
    }));
    // Cap na 300
    $titles = array_slice($titles, 0, $titlesLimit);

    $sites[] = [
        'id'                => $siteId,
        'name'              => (string)$row['name'],
        'url'               => (string)$row['url'],
        'categories'        => array_values(array_filter(array_map('trim', explode(',', (string)$row['categories'])))),
        'lang'              => (string)$row['lang'],
        'auto_enabled'      => (int)($row['enabled'] ?? 0) === 1,
        'pending_count'     => (int)$row['pending_count'],
        'historical_titles' => $titles,
    ];
}

echo json_encode([
    'generated_at'   => date('Y-m-d H:i:s'),
    'threshold'      => $threshold,
    'titles_limit'   => $titlesLimit,
    'sites_count'    => count($sites),
    'sites'          => $sites,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
