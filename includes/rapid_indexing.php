<?php
/**
 * Indeksacja publikacji przez Rapid URL Indexer (Google).
 * Klient przeniesiony z Hefajstosa (lib/rapid-indexer.ts) przez port w seo-ai (includes/indexing.php).
 *
 * API: https://rapidurlindexer.com/wp-json/api/v1, nagłówek X-API-Key (settings.rapid_indexer_api_key).
 * 1 kredyt = 1 URL, kredyt wraca za URL niezaindeksowany po 14 dniach.
 * UWAGA: Rapid zwraca 403 HTML dla domyślnego User-Agenta curla — zawsze wysyłamy własny.
 *
 * Kolejka index_queue zbiera KAŻDĄ publikację (auto-publikacja, Publikuj, Publikuj masowo,
 * Zamów i opublikuj, Import). Wysyłka paczkami (jeden projekt Rapid):
 *   - worker (bin/worker.php, co minutę) — gdy najstarszy URL czeka >= 10 min albo jest >= 50 URL-i,
 *   - koniec przebiegu auto-publikacji — wszystko, co ten przebieg opublikował,
 *   - od razu po publikacji, gdy worker nie działa.
 * Przed wysyłką: URL musi zwracać 200 i nie mieć noindex (wpisy zaplanowane na przyszłość
 * czekają do daty publikacji), bez ponownego zgłoszenia w ciągu 30 dni, kontrola salda kredytów.
 * Status projektów (ile zaindeksowanych) odświeżany co godzinę przez workera.
 */

require_once __DIR__ . '/../db.php';

const RAPID_API_BASE = 'https://rapidurlindexer.com/wp-json/api/v1';
const RAPID_USER_AGENT = 'SemtreeZapleczeIndexer/1.0 (+https://zaplecze.semtree.com.pl)';
const INDEX_DEDUPE_DAYS = 30;
const INDEX_MIN_CREDITS = 5;
const INDEX_BATCH_MAX = 500;
const INDEX_FLUSH_AGE = 600;        // s — tyle najdłużej czeka URL na zebranie paczki
const INDEX_FLUSH_COUNT = 50;       // albo tyle URL-i w kolejce
const INDEX_MAX_ATTEMPTS = 12;      // nieosiągalny URL: ponowienia co 6 h (~3 dni), potem "skipped"

function indexSetting(string $key): string {
    $stmt = getDb()->prepare('SELECT value FROM settings WHERE key = :k');
    $stmt->bindValue(':k', $key, SQLITE3_TEXT);
    $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    return $row ? trim((string)$row['value']) : '';
}

/** Automatyczna indeksacja wszystkich publikacji (Ustawienia, domyślnie włączona). */
function indexAutoEnabled(): bool {
    return indexSetting('index_auto') !== '0' && indexSetting('rapid_indexer_api_key') !== '';
}

// ── Rapid API ───────────────────────────────────────────────

function rapidRequest(string $method, string $path, ?array $body = null): array {
    $key = indexSetting('rapid_indexer_api_key');
    if ($key === '') throw new RuntimeException('Brak klucza Rapid URL Indexer w Ustawieniach');
    for ($attempt = 0; $attempt < 3; $attempt++) {
        if ($attempt > 0) sleep(5 * $attempt);
        $ch = curl_init(RAPID_API_BASE . $path);
        $headers = ['X-API-Key: ' . $key, 'Accept: application/json'];
        if ($body !== null) $headers[] = 'Content-Type: application/json';
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_POSTFIELDS     => $body !== null ? json_encode($body, JSON_UNESCAPED_SLASHES) : null,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_USERAGENT      => RAPID_USER_AGENT,
        ]);
        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($resp === false) { $last = "Rapid: brak połączenia ($err)"; continue; }
        $json = json_decode((string)$resp, true) ?: [];
        if ($code >= 200 && $code < 300) return $json;
        $msg = is_string($json['message'] ?? null) ? $json['message'] : "HTTP $code";
        $why = match (true) {
            $code === 401 => 'zły albo nieaktywny klucz API',
            $code === 403 => 'brak kredytów albo konto zawieszone',
            $code === 429 => 'limit 100 żądań na minutę',
            default       => 'błąd serwera',
        };
        $last = "Rapid: $why ($msg)";
        if ($code !== 429 && $code < 500) break;
    }
    throw new RuntimeException($last);
}

function rapidCredits(): int {
    return (int)(rapidRequest('GET', '/credits/balance')['credits'] ?? 0);
}

/** @return array{project_id: string, credits_charged: ?int} */
function rapidCreateProject(string $name, array $urls): array {
    $json = rapidRequest('POST', '/projects', [
        'project_name'            => mb_substr($name, 0, 200),
        'urls'                    => array_values($urls),
        'notify_on_status_change' => false,
        'apex_mode_enabled'       => false,
    ]);
    if (!isset($json['project_id'])) throw new RuntimeException('Rapid nie zwrócił identyfikatora projektu');
    return ['project_id' => (string)$json['project_id'], 'credits_charged' => isset($json['credits_charged']) ? (int)$json['credits_charged'] : null];
}

function rapidProjectStatus(string $id): array {
    $j = rapidRequest('GET', '/projects/' . rawurlencode($id));
    return ['status' => (string)($j['status'] ?? 'pending'), 'submitted' => (int)($j['submitted_links'] ?? 0), 'indexed' => (int)($j['indexed_links'] ?? 0)];
}

// ── Kolejka ─────────────────────────────────────────────────

/**
 * Dodaje opublikowany URL do kolejki indeksacji. Wpis zaplanowany na przyszłość: $availableAt = data publikacji.
 * Nie dubluje: URL zgłoszony w ostatnich 30 dniach albo już czekający zostaje bez zmian.
 */
function indexQueueUrl(string $url, int $siteId, string $source, ?string $availableAt = null): bool {
    $url = trim($url);
    if (!preg_match('#^https?://#i', $url)) return false;
    $db = getDb();
    $st = $db->prepare('SELECT status, submitted_at FROM index_queue WHERE url = :u');
    $st->bindValue(':u', $url, SQLITE3_TEXT);
    $row = $st->execute()->fetchArray(SQLITE3_ASSOC);
    if ($row && ($row['status'] === 'queued'
        || ($row['status'] === 'submitted' && strtotime($row['submitted_at'] . ' UTC') > time() - INDEX_DEDUPE_DAYS * 86400))) {
        return false;
    }
    $st = $db->prepare('INSERT INTO index_queue (url, site_id, source, status, attempts, available_at, created_at)
        VALUES (:u, :s, :src, "queued", 0, :a, datetime("now"))
        ON CONFLICT(url) DO UPDATE SET status = "queued", attempts = 0, available_at = :a, source = :src, last_reason = NULL, created_at = datetime("now")');
    $st->bindValue(':u', $url, SQLITE3_TEXT);
    $st->bindValue(':s', $siteId, SQLITE3_INTEGER);
    $st->bindValue(':src', $source, SQLITE3_TEXT);
    $st->bindValue(':a', $availableAt ? gmdate('Y-m-d H:i:s', strtotime($availableAt)) : gmdate('Y-m-d H:i:s'), SQLITE3_TEXT);
    $st->execute();
    return true;
}

/**
 * Czy URL zwraca 200 i nie ma noindex. Przekierowania w obrębie tej samej domeny są śledzone
 * (zaplanowany wpis WP ma najpierw link ?p=ID, po publikacji przekierowuje na permalink).
 * @return array{0: ?string, 1: string} [powód odrzucenia albo null, końcowy URL]
 */
function indexCheckLive(string $url): array {
    $host = strtolower(parse_url($url, PHP_URL_HOST) ?? '');
    for ($hop = 0; $hop < 4; $hop++) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERAGENT      => RAPID_USER_AGENT,
        ]);
        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $hsize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $location = (string)curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        curl_close($ch);
        if ($resp === false) return ['brak odpowiedzi', $url];
        if (in_array($code, [301, 302, 307, 308], true) && $location !== '') {
            $next = strtolower(parse_url($location, PHP_URL_HOST) ?? '');
            if (preg_replace('/^www\./', '', $next) !== preg_replace('/^www\./', '', $host)) return ["przekierowanie na inną domenę ($next)", $url];
            $url = $location;
            continue;
        }
        if ($code !== 200) return ["HTTP $code", $url];
        if (preg_match('/^x-robots-tag:[^\r\n]*noindex/im', substr($resp, 0, $hsize))) return ['X-Robots-Tag noindex', $url];
        if (preg_match('/<meta[^>]+name=["\']robots["\'][^>]*content=["\'][^"\']*noindex/i', substr($resp, $hsize, 200000))) return ['meta noindex', $url];
        return [null, $url];
    }
    return ['za dużo przekierowań', $url];
}

/**
 * Wysyła gotowe URL-e z kolejki jako jeden projekt Rapid. Nigdy nie rzuca wyjątku.
 * $force = false: tylko gdy paczka "dojrzała" (najstarszy URL czeka >= 10 min albo >= 50 URL-i).
 * @return array{submitted:int, project_id:?string, credits_left:?int, skipped:int, deferred:int, error:?string}
 */
function indexFlush(bool $force = false, string $label = ''): array {
    $out = ['submitted' => 0, 'project_id' => null, 'credits_left' => null, 'skipped' => 0, 'deferred' => 0, 'error' => null];
    if (indexSetting('rapid_indexer_api_key') === '') { $out['error'] = 'brak klucza Rapid'; return $out; }
    // Jedna wysyłka naraz (worker co minutę, koniec przebiegu CRON, publikacja bez workera) — bez podwójnych zgłoszeń
    $lock = fopen(__DIR__ . '/../data/index-flush.lock', 'c');
    if (!$lock || !flock($lock, $force ? LOCK_EX : LOCK_EX | LOCK_NB)) return $out;
    $db = getDb();
    try {
        $due = (int)$db->querySingle("SELECT COUNT(*) FROM index_queue WHERE status = 'queued' AND available_at <= datetime('now')");
        if (!$due) return $out;
        if (!$force) {
            $oldest = $db->querySingle("SELECT MIN(available_at) FROM index_queue WHERE status = 'queued' AND available_at <= datetime('now')");
            if ($due < INDEX_FLUSH_COUNT && strtotime($oldest . ' UTC') > time() - INDEX_FLUSH_AGE) return $out;
        }

        $rows = [];
        $r = $db->query("SELECT * FROM index_queue WHERE status = 'queued' AND available_at <= datetime('now') ORDER BY available_at LIMIT " . INDEX_BATCH_MAX);
        while ($row = $r->fetchArray(SQLITE3_ASSOC)) $rows[] = $row;

        $ok = [];
        $upd = $db->prepare('UPDATE index_queue SET status = :st, attempts = attempts + 1, last_reason = :why, available_at = :a WHERE id = :id');
        foreach ($rows as $row) {
            [$why, $finalUrl] = indexCheckLive($row['url']);
            if ($why === null) { $ok[$row['id']] = $finalUrl; continue; }
            // Nieosiągalny (np. zaplanowany wpis jeszcze 404, chwilowa awaria) — spróbuj za 6 h
            $giveUp = (int)$row['attempts'] + 1 >= INDEX_MAX_ATTEMPTS;
            $upd->bindValue(':st', $giveUp ? 'skipped' : 'queued', SQLITE3_TEXT);
            $upd->bindValue(':why', $why, SQLITE3_TEXT);
            $upd->bindValue(':a', gmdate('Y-m-d H:i:s', time() + 6 * 3600), SQLITE3_TEXT);
            $upd->bindValue(':id', $row['id'], SQLITE3_INTEGER);
            $upd->execute();
            $upd->reset();
            $giveUp ? $out['skipped']++ : $out['deferred']++;
        }
        if (!$ok) return $out;

        $credits = rapidCredits();
        if ($credits - count($ok) < INDEX_MIN_CREDITS) {
            $out['error'] = "za mało kredytów Rapid ($credits) na " . count($ok) . ' URL-i';
            return $out;   // zostają w kolejce, pójdą po doładowaniu
        }
        $sources = [];
        $r = $db->query('SELECT DISTINCT source FROM index_queue WHERE id IN (' . implode(',', array_keys($ok)) . ')');
        while ($s = $r->fetchArray(SQLITE3_NUM)) $sources[] = $s[0];
        $name = 'Zaplecze / ' . ($label ?: implode(', ', $sources)) . ' / ' . date('Y-m-d H:i') . ' / ' . count($ok) . ' URL';
        $p = rapidCreateProject($name, array_values($ok));

        $st = $db->prepare('INSERT OR REPLACE INTO rapid_projects (id, name, url_count, status, indexed, created_at) VALUES (:id, :n, :c, "pending", 0, datetime("now"))');
        $st->bindValue(':id', $p['project_id'], SQLITE3_TEXT);
        $st->bindValue(':n', $name, SQLITE3_TEXT);
        $st->bindValue(':c', count($ok), SQLITE3_INTEGER);
        $st->execute();

        $done = $db->prepare('UPDATE OR IGNORE index_queue SET status = "submitted", submitted_at = datetime("now"), rapid_project_id = :p, last_reason = NULL, url = :u WHERE id = :id');
        $idx = $db->prepare('UPDATE index_status SET submitted_at = datetime("now") WHERE url = :u');
        foreach ($ok as $id => $url) {
            $done->bindValue(':p', $p['project_id'], SQLITE3_TEXT);
            $done->bindValue(':u', $url, SQLITE3_TEXT);   // końcowy URL po przekierowaniu
            $done->bindValue(':id', $id, SQLITE3_INTEGER);
            $done->execute();
            $done->reset();
            $idx->bindValue(':u', $url, SQLITE3_TEXT);   // zakładka Indeksacja: data zgłoszenia
            $idx->execute();
            $idx->reset();
        }
        $out['submitted'] = count($ok);
        $out['project_id'] = $p['project_id'];
        $out['credits_left'] = $credits - ($p['credits_charged'] ?? count($ok));
    } catch (Throwable $e) {
        $out['error'] = $e->getMessage();
    }
    return $out;
}

/** Odświeża status projektów Rapid z ostatnich 20 dni (ile zaindeksowanych). */
function indexRefreshProjects(): array {
    $db = getDb();
    $sum = ['checked' => 0, 'indexed' => 0, 'submitted' => 0];
    $r = $db->query("SELECT * FROM rapid_projects WHERE created_at > datetime('now', '-20 days')
        AND NOT (status IN ('completed', 'refunded', 'failed') AND checked_at > datetime(created_at, '+15 days'))");
    $rows = [];
    while ($row = $r->fetchArray(SQLITE3_ASSOC)) $rows[] = $row;
    $st = $db->prepare('UPDATE rapid_projects SET status = :s, indexed = :i, checked_at = datetime("now") WHERE id = :id');
    foreach ($rows as $row) {
        try {
            $s = rapidProjectStatus($row['id']);
            $st->bindValue(':s', $s['status'], SQLITE3_TEXT);
            $st->bindValue(':i', $s['indexed'], SQLITE3_INTEGER);
            $st->bindValue(':id', $row['id'], SQLITE3_TEXT);
            $st->execute();
            $st->reset();
            $sum['checked']++;
            $sum['indexed'] += $s['indexed'];
            $sum['submitted'] += $s['submitted'];
        } catch (Throwable $e) {
            // pojedynczy projekt — spróbuje przy następnym odświeżeniu
        }
    }
    return $sum;
}

/** Podsumowanie do Ustawień / Telegrama. */
function indexStats(): array {
    $db = getDb();
    $q = fn($sql) => (int)$db->querySingle($sql);
    return [
        'queued'         => $q("SELECT COUNT(*) FROM index_queue WHERE status = 'queued'"),
        'submitted_7d'   => $q("SELECT COUNT(*) FROM index_queue WHERE status = 'submitted' AND submitted_at > datetime('now', '-7 days')"),
        'skipped_7d'     => $q("SELECT COUNT(*) FROM index_queue WHERE status = 'skipped' AND created_at > datetime('now', '-7 days')"),
        'indexed_20d'    => $q("SELECT COALESCE(SUM(indexed), 0) FROM rapid_projects WHERE created_at > datetime('now', '-20 days')"),
        'submitted_20d'  => $q("SELECT COALESCE(SUM(url_count), 0) FROM rapid_projects WHERE created_at > datetime('now', '-20 days')"),
    ];
}
