<?php
/**
 * Auto-generuje pomysly na artykuly blogowe dla stron zapleczowych
 * za pomoca Claude API i wstawia je do auto_publish_queue.
 *
 * Uzycie:
 *   $exhausted = findExhaustedQueues($threshold = 3);
 *   foreach ($exhausted as $siteId) {
 *       $site = getSiteContextForTopics($siteId);
 *       $topics = generateTopicsForSite($site, 30);
 *       insertTopicsIntoQueue($siteId, $topics);
 *   }
 */

require_once __DIR__ . '/../db.php';

/**
 * Zwraca ID stron ktorych kolejka ma mniej niz $threshold pendingow.
 * Uwzglednia tylko strony z ENABLED auto-publish configiem — bo nie ma sensu
 * generowac dla wylaczonych.
 */
function findExhaustedQueues(int $threshold = 3): array {
    $db = getDb();
    $r = $db->query("
        SELECT s.id, s.name,
               COALESCE(SUM(CASE WHEN q.status = 'pending' THEN 1 ELSE 0 END), 0) AS pending_count
        FROM sites s
        JOIN auto_publish_config apc ON apc.site_id = s.id AND apc.enabled = 1
        LEFT JOIN auto_publish_queue q ON q.site_id = s.id
        GROUP BY s.id
        HAVING pending_count < :thr
        ORDER BY s.name
    ");
    // sqlite3::query nie ma bindow — musimy uzyc prepared statementu:
    $stmt = $db->prepare("
        SELECT s.id, s.name,
               COALESCE(SUM(CASE WHEN q.status = 'pending' THEN 1 ELSE 0 END), 0) AS pending_count
        FROM sites s
        JOIN auto_publish_config apc ON apc.site_id = s.id AND apc.enabled = 1
        LEFT JOIN auto_publish_queue q ON q.site_id = s.id
        GROUP BY s.id
        HAVING pending_count < :thr
        ORDER BY s.name
    ");
    $stmt->bindValue(':thr', $threshold, SQLITE3_INTEGER);
    $res = $stmt->execute();
    $out = [];
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $out[] = [
            'id'            => (int)$row['id'],
            'name'          => $row['name'],
            'pending_count' => (int)$row['pending_count'],
        ];
    }
    return $out;
}

/**
 * Zbiera kontekst potrzebny do wygenerowania sensownych tematow:
 *   - nazwa strony, URL, kategorie (comma-separated string z sites.categories)
 *   - jezyk (auto_publish_config.lang, default 'pl')
 *   - lista niedawnych opublikowanych tytulow (anty-duplikat)
 */
function getSiteContextForTopics(int $siteId, int $promptTitlesLimit = 200): ?array {
    $db = getDb();
    $stmt = $db->prepare('
        SELECT s.id, s.name, s.url, s.categories, COALESCE(apc.lang, "pl") AS lang
        FROM sites s
        LEFT JOIN auto_publish_config apc ON apc.site_id = s.id
        WHERE s.id = :id
    ');
    $stmt->bindValue(':id', $siteId, SQLITE3_INTEGER);
    $site = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    if (!$site) return null;

    // WSZYSTKIE tytuly z auto_publish_queue niezaleznie od statusu — pending,
    // generating, generated, publishing, published, error. Kazdy z nich juz
    // ZAJMUJE dany temat i NIE MOZE zostac powtorzony.
    $stmt = $db->prepare('
        SELECT title FROM auto_publish_queue
        WHERE site_id = :id AND title <> ""
        ORDER BY id DESC
    ');
    $stmt->bindValue(':id', $siteId, SQLITE3_INTEGER);
    $res = $stmt->execute();
    $historicalTitles = [];
    while ($r = $res->fetchArray(SQLITE3_ASSOC)) $historicalTitles[] = $r['title'];

    // Plus tytuly recznie opublikowane (publications) — tez sa juz zajete
    $stmt = $db->prepare('
        SELECT post_title FROM publications
        WHERE site_id = :id AND post_title <> ""
        ORDER BY created_at DESC
    ');
    $stmt->bindValue(':id', $siteId, SQLITE3_INTEGER);
    $res = $stmt->execute();
    while ($r = $res->fetchArray(SQLITE3_ASSOC)) {
        $t = trim((string)$r['post_title']);
        if ($t !== '') $historicalTitles[] = $t;
    }

    // Dedup po znormalizowanym kluczu — "Jak wybrac X?" i "Jak wybrać X" laczy sie.
    $seen = [];
    $historicalTitles = array_values(array_filter($historicalTitles, function($t) use (&$seen) {
        $k = normalizeTopicTitle($t);
        if ($k === '' || isset($seen[$k])) return false;
        $seen[$k] = true;
        return true;
    }));

    // Kategorie do array
    $cats = array_filter(array_map('trim', explode(',', (string)$site['categories'])));

    return [
        'id'         => (int)$site['id'],
        'name'       => (string)$site['name'],
        'url'        => (string)$site['url'],
        'categories' => array_values($cats),
        'lang'       => (string)$site['lang'],
        // WSZYSTKIE zajete tytuly — do post-filtra (zeby wywalic to co LLM zwroci mimo instrukcji)
        'historical_titles' => $historicalTitles,
        // Podzbior wyswietlany w promcie (cap zeby prompt nie pucznal na 2000+ tytulow)
        'recent_titles'     => array_slice($historicalTitles, 0, $promptTitlesLimit),
    ];
}

/**
 * Normalizuje tytul do klucza porownawczego:
 * lower-case + polskie znaki -> ascii + tylko alfanum + single spacja.
 * "Jak wybrać X?" i "Jak wybrac X" daja ten sam klucz.
 */
function normalizeTopicTitle(string $s): string {
    $s = mb_strtolower(trim($s), 'UTF-8');
    $map = ['ą'=>'a','ć'=>'c','ę'=>'e','ł'=>'l','ń'=>'n','ó'=>'o','ś'=>'s','ź'=>'z','ż'=>'z','ü'=>'u','ö'=>'o','ä'=>'a','ß'=>'ss'];
    $s = strtr($s, $map);
    $s = preg_replace('/[^a-z0-9]+/', ' ', $s);
    $s = preg_replace('/\s+/', ' ', $s);
    return trim($s);
}

/**
 * Wywoluje Claude API i prosi o $count pomyslow na artykuly w formacie JSON.
 * Zwraca:
 *   ['topics' => [{title, main_keyword, secondary_keywords, category_name, notes}, ...],
 *    'raw_count'     => ile zwrocil LLM przed dedupem,
 *    'dropped_dupes' => ile odrzucono jako duplikaty (vs historia + w batchu)]
 *
 * Rzuca RuntimeException gdy brak API key, HTTP error, niepoprawny JSON.
 */
function generateTopicsForSite(array $site, int $count = 30): array {
    $db = getDb();
    $apiKeyRow = $db->querySingle("SELECT value FROM settings WHERE key = 'anthropic_api_key'", true);
    $apiKey = $apiKeyRow ? trim((string)$apiKeyRow['value']) : '';
    if ($apiKey === '') {
        throw new RuntimeException('Brak anthropic_api_key w settings.');
    }
    $modelRow = $db->querySingle("SELECT value FROM settings WHERE key = 'ai_model'", true);
    $model = ($modelRow && !empty($modelRow['value'])) ? $modelRow['value'] : 'claude-sonnet-4-6';

    $catsStr = empty($site['categories']) ? '(brak zdefiniowanych kategorii)' : implode(', ', $site['categories']);
    $totalHistCount = count($site['historical_titles'] ?? []);
    $shownCount = count($site['recent_titles'] ?? []);
    $recent = empty($site['recent_titles'])
        ? '(brak historii publikacji na tej stronie)'
        : implode("\n", array_map(fn($t) => '- ' . $t, $site['recent_titles']));
    $histNote = ($totalHistCount > $shownCount)
        ? " (pokazane najnowsze {$shownCount} z {$totalHistCount} zajetych tytulow)"
        : '';

    $langLabel = match ($site['lang']) {
        'en' => 'angielskim',
        'de' => 'niemieckim',
        'fr' => 'francuskim',
        'es' => 'hiszpanskim',
        'it' => 'wloskim',
        'nl' => 'niderlandzkim',
        'cs' => 'czeskim',
        default => 'polskim',
    };

    $system = "Jestes ekspertem SEO i content strategiem. Generujesz pomysly na artykuly blogowe dla stron niszowych. "
        . "Odpowiadasz WYLACZNIE prawidlowym JSON-em, bez markdown code fence, bez zadnej preambuly i komentarzy — sama tablica.";

    $userPrompt = <<<PROMPT
Wygeneruj {$count} nowych pomyslow na artykuly blogowe w jezyku {$langLabel} dla strony:

Nazwa: {$site['name']}
URL: {$site['url']}
Kategorie strony: {$catsStr}

Tematy JUZ ZAJETE (opublikowane albo w kolejce){$histNote} — NIE POWTARZAJ ani zadnego z nich, ani ich blizniaczych wariantow (np. inne slowo pytające, inna liczba mnoga, inny szyk):
{$recent}

Kazdy artykul MUSI byc:
- ORYGINALNY, konkretny, nie za ogolny (np. NIE "Wszystko o X", "Kompendium wiedzy o X"),
- Roznorodny — mieszanka poradnikow, list, porownan, case-studies, odpowiedzi na konkretne pytania,
- Bezposrednio zwiazany z tematyka strony (nie generyczny content),
- Dobrany do jednej z podanych kategorii strony.

Format odpowiedzi — tablica JSON z {$count} obiektami:
[
  {
    "title": "Tytul artykulu (40-75 znakow, atrakcyjny SEO, jasny content promise)",
    "main_keyword": "glowna fraza kluczowa 1-3 slowa",
    "secondary_keywords": "5-8 fraz pobocznych oddzielonych przecinkami",
    "category_name": "dokladnie jedna z kategorii strony podanych wyzej"
  }
]

Zwroc TYLKO tablice JSON. Nic wiecej.
PROMPT;

    $payload = [
        'model' => $model,
        'max_tokens' => 12000,
        'system' => $system,
        'messages' => [
            ['role' => 'user', 'content' => $userPrompt],
        ],
    ];

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'x-api-key: ' . $apiKey,
            'anthropic-version: 2023-06-01',
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => 180,
        CURLOPT_CONNECTTIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $response = curl_exec($ch);
    if (curl_errno($ch)) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('cURL: ' . $err);
    }
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode($response, true);
    if ($httpCode >= 400 || !$data) {
        $msg = $data['error']['message'] ?? "HTTP {$httpCode}";
        throw new RuntimeException('Claude API: ' . $msg);
    }

    $text = $data['content'][0]['text'] ?? '';
    if ($text === '') throw new RuntimeException('Pusta odpowiedz Claude API.');

    $topics = parseTopicsJson($text, $site['categories']);

    // POST-FILTR ANTY-DUPE: LLM potrafi zignorowac liste w promcie. Odsiewamy
    // tytuly ktorych znormalizowany klucz pasuje do:
    //   a) ktoregokolwiek z tytulow historycznych (auto_publish_queue + publications)
    //   b) innego tytulu w tej samej odpowiedzi (LLM czasem zwraca 2 podobne)
    $blocked = [];
    foreach (($site['historical_titles'] ?? []) as $t) {
        $k = normalizeTopicTitle($t);
        if ($k !== '') $blocked[$k] = true;
    }
    $seenInBatch = [];
    $filtered = [];
    $droppedDup = 0;
    foreach ($topics as $topic) {
        $k = normalizeTopicTitle($topic['title']);
        if ($k === '') { $droppedDup++; continue; }
        if (isset($blocked[$k]) || isset($seenInBatch[$k])) { $droppedDup++; continue; }
        $seenInBatch[$k] = true;
        $filtered[] = $topic;
    }
    return [
        'topics'         => $filtered,
        'raw_count'      => count($topics),
        'dropped_dupes'  => $droppedDup,
    ];
}

/**
 * Sanityzuje odpowiedz LLM (usuwa markdown code fence, preambule) i parsuje JSON.
 * Waliduje kazdy element — pomija brakujace tytuly.
 */
function parseTopicsJson(string $text, array $siteCategories): array {
    // Wytnij markdown code fence jesli LLM go dodal
    $text = preg_replace('/^```(?:json)?\s*/m', '', $text);
    $text = preg_replace('/```\s*$/m', '', $text);

    // Znajdz pierwsza [ i ostatnia ] — chroni przed preambula/postambula
    $start = strpos($text, '[');
    $end = strrpos($text, ']');
    if ($start === false || $end === false || $end <= $start) {
        throw new RuntimeException('Odpowiedz nie zawiera tablicy JSON: ' . substr($text, 0, 200));
    }
    $json = substr($text, $start, $end - $start + 1);

    $arr = json_decode($json, true);
    if (!is_array($arr)) {
        throw new RuntimeException('Nieprawidlowy JSON w odpowiedzi: ' . substr($json, 0, 200));
    }

    $catLower = array_map('mb_strtolower', $siteCategories);
    $out = [];
    foreach ($arr as $item) {
        if (!is_array($item)) continue;
        $title = trim((string)($item['title'] ?? ''));
        if ($title === '') continue;

        // Category snap — jesli model wygenerowal category ktorej nie ma w site.categories,
        // sprobuj dopasowac case-insensitive; jesli nie pasuje, uzyj pustej (queue akceptuje)
        $cat = trim((string)($item['category_name'] ?? ''));
        if ($cat !== '' && $siteCategories) {
            $idx = array_search(mb_strtolower($cat), $catLower, true);
            $cat = ($idx !== false) ? $siteCategories[$idx] : $cat;
        }

        $out[] = [
            'title'              => mb_substr($title, 0, 200),
            'main_keyword'       => trim((string)($item['main_keyword'] ?? '')),
            'secondary_keywords' => trim((string)($item['secondary_keywords'] ?? '')),
            'category_name'      => $cat,
            'notes'              => trim((string)($item['notes'] ?? '')),
        ];
    }
    return $out;
}

/**
 * Wstawia tematy do auto_publish_queue jako pendingi. Zwraca ile faktycznie
 * zostalo wstawionych (mozliwy skip przy blednym rekordzie).
 */
function insertTopicsIntoQueue(int $siteId, array $topics): int {
    $db = getDb();
    $stmt = $db->prepare('
        INSERT INTO auto_publish_queue (site_id, title, main_keyword, secondary_keywords, category_name, notes, status)
        VALUES (:sid, :t, :mk, :sk, :cn, :n, "pending")
    ');
    $inserted = 0;
    foreach ($topics as $t) {
        if (empty($t['title'])) continue;
        $stmt->bindValue(':sid', $siteId, SQLITE3_INTEGER);
        $stmt->bindValue(':t',   $t['title'], SQLITE3_TEXT);
        $stmt->bindValue(':mk',  $t['main_keyword'] ?? '', SQLITE3_TEXT);
        $stmt->bindValue(':sk',  $t['secondary_keywords'] ?? '', SQLITE3_TEXT);
        $stmt->bindValue(':cn',  $t['category_name'] ?? '', SQLITE3_TEXT);
        $stmt->bindValue(':n',   $t['notes'] ?? '', SQLITE3_TEXT);
        $stmt->execute();
        $stmt->reset();
        $inserted++;
    }
    return $inserted;
}
