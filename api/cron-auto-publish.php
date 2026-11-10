<?php
/**
 * CRON: Auto-publish articles from queue.
 * Runs daily at 9:00 AM. For each enabled site, generates and publishes X articles.
 * Sends Telegram report after completion.
 *
 * Usage (CLI — recommended, no HTTP timeout):
 *   php cron-auto-publish.php --token=SECRET          (Batch API, jeśli włączone w Ustawieniach)
 *   php cron-auto-publish.php --token=SECRET --sync   (wszystko w jednym przebiegu)
 *
 * Usage (HTTP — fallback, may hit server timeout for many sites):
 *   curl -s "https://app.com/api/cron-auto-publish.php?token=SECRET"
 */
set_time_limit(0); // unlimited
ini_set('memory_limit', '512M');
ignore_user_abort(true); // Keep running even if HTTP client disconnects

$isCli = (PHP_SAPI === 'cli');

// Ignore SIGPIPE so closed output pipe (e.g. `| head`) doesn't kill the process
if ($isCli && function_exists('pcntl_signal')) {
    pcntl_signal(SIGPIPE, SIG_IGN);
}

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/wp_api.php';
require_once __DIR__ . '/../includes/article_pipeline.php';
require_once __DIR__ . '/../includes/image_utils.php';
require_once __DIR__ . '/../includes/cover_image.php';
require_once __DIR__ . '/../includes/rapid_indexing.php';

if (!$isCli) header('Content-Type: application/json');

// Heartbeat flush to keep LiteSpeed/Apache from killing idle connection.
// In CLI this just echoes to stdout.
function cronLog(string $msg): void {
    global $isCli;
    $line = '[' . date('H:i:s') . '] ' . $msg . "\n";
    if ($isCli) {
        echo $line;
    } else {
        echo "<!-- $line -->";
        @ob_flush();
        @flush();
    }
}

// ── Auth ─────────────────────────────────────────────────────
$db = getDb();
$tokenRow = $db->querySingle("SELECT value FROM settings WHERE key = 'cron_token'", true);
$cronToken = $tokenRow ? trim($tokenRow['value']) : '';

// Read token from CLI argv or HTTP GET
$providedToken = '';
if ($isCli) {
    foreach ($argv ?? [] as $arg) {
        if (preg_match('/^--token=(.+)$/', $arg, $m)) { $providedToken = $m[1]; break; }
    }
} else {
    $providedToken = $_GET['token'] ?? '';
}

if (!$cronToken || $providedToken !== $cronToken) {
    if (!$isCli) http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// ── Jeden przebieg naraz ─────────────────────────────────────
// flock na osobnym pliku: drugi przebieg (CRON + ręczny, dwa ręczne) kończy się od razu.
// Bez tego drugi przebieg resetował "generating" pierwszego i oba brały te same wpisy (duplikaty).
$runLock = fopen(__DIR__ . '/../data/cron-auto-publish.run.lock', 'c');
if (!$runLock || !flock($runLock, LOCK_EX | LOCK_NB)) {
    cronLog('Inny przebieg auto-publikacji już trwa — kończę bez zmian.');
    echo json_encode(['success' => false, 'message' => 'Inny przebieg auto-publikacji już trwa.']);
    exit;
}
// Znacznik dla UI ("działa w tle") — także dla przebiegów z crona; sprzątany przy każdym wyjściu
$markerFile = __DIR__ . '/../data/cron-auto-publish.lock';
@file_put_contents($markerFile, (string)time());
register_shutdown_function(function () use ($markerFile, $runLock) {
    @unlink($markerFile);
    flock($runLock, LOCK_UN);
});

// Ręczny pełny przebieg („Uruchom teraz”, --manual) zastępuje zaplanowany przebieg tego dnia
$isManualRun = $isCli && in_array('--manual', $argv ?? [], true);
$today = date('Y-m-d');
if ($isManualRun) {
    $st = $db->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES ('auto_publish_manual_date', :d)");
    $st->bindValue(':d', $today, SQLITE3_TEXT);
    $st->execute();
} else {
    $manualDate = $db->querySingle("SELECT value FROM settings WHERE key = 'auto_publish_manual_date'");
    if ($manualDate === $today) {
        cronLog("Pominięty: dziś ($today) był już ręczny pełny przebieg.");
        echo json_encode(['success' => true, 'message' => 'Pominięty — dziś był ręczny przebieg.', 'published' => 0]);
        exit;
    }
}

// Reset stuck articles from previous crashed runs (bezpieczne — trzymamy blokadę)
$db->exec("UPDATE auto_publish_queue SET status = 'pending' WHERE status IN ('generating', 'generated', 'publishing')");
cronLog('CRON start' . ($isManualRun ? ' (ręczny)' : '') . ' — stuck articles reset');

// ── Settings ─────────────────────────────────────────────────
$apiKeyRow = $db->querySingle("SELECT value FROM settings WHERE key = 'anthropic_api_key'", true);
$anthropicKey = $apiKeyRow ? trim($apiKeyRow['value']) : '';


$geminiKeyRow = $db->querySingle("SELECT value FROM settings WHERE key = 'gemini_api_key'", true);
$geminiKey = $geminiKeyRow ? trim($geminiKeyRow['value']) : '';

$rapidKey = indexSetting('rapid_indexer_api_key');

if (!$anthropicKey) {
    echo json_encode(['error' => 'Brak klucza Anthropic API']);
    exit;
}

// ── Tryb Batch API ───────────────────────────────────────────
// Włączony (domyślnie): przebieg N wysyła pisanie artykułów jako jeden batch (50% taniej),
// przebieg N+1 odbiera wyniki, kończy audyt/korektę i publikuje. Przy dziennym cronie
// artykuł wychodzi następnego dnia. Wyłączony: wszystko synchronicznie w jednym przebiegu.
$forceSync = $isCli && in_array('--sync', $argv ?? [], true);   // ręczne „Uruchom teraz” z panelu
$batchMode = claudeSetting('auto_publish_batch') !== '0' && !$forceSync;

// ── Enabled sites ────────────────────────────────────────────
$sites = [];
$result = $db->query('
    SELECT s.id, s.name, s.url, s.username, s.app_password,
           apc.daily_limit, apc.use_speed_links, apc.use_inline_images, apc.random_author, apc.lang
    FROM auto_publish_config apc
    JOIN sites s ON s.id = apc.site_id
    WHERE apc.enabled = 1
    ORDER BY s.name
');
while ($row = $result->fetchArray(SQLITE3_ASSOC)) $sites[(int)$row['id']] = $row;

$hasWork = (int)$db->querySingle("SELECT COUNT(*) FROM auto_publish_queue WHERE status IN ('pending', 'batched')");
if (empty($sites) || !$hasWork) {
    echo json_encode(['success' => true, 'message' => 'Brak stron z oczekującymi artykułami.', 'published' => 0]);
    exit;
}

$totalPublished = 0;
$totalErrors = 0;
$report = [];          // site_id => {site, published, errors, articles[]} (Telegram)
$speedLinksUrls = [];
$siteCtxCache = [];

cronLog('Tryb: ' . ($batchMode ? 'Batch API (publikacja w kolejnym przebiegu)' : 'synchroniczny'));

// ── Faza A: odbiór zakończonych batchy i publikacja ─────────
$batchedItems = [];
$r = $db->query("SELECT * FROM auto_publish_queue WHERE status = 'batched' AND batch_id IS NOT NULL ORDER BY id");
while ($row = $r->fetchArray(SQLITE3_ASSOC)) $batchedItems[$row['batch_id']][] = $row;

foreach ($batchedItems as $batchId => $items) {
    try {
        $info = claudeBatchGet($batchId);
    } catch (Exception $e) {
        cronLog("Batch $batchId: błąd odczytu statusu: " . $e->getMessage());
        continue;
    }
    if (($info['processing_status'] ?? '') !== 'ended') {
        cronLog("Batch $batchId: w trakcie (" . count($items) . ' artykułów), sprawdzę w kolejnym przebiegu');
        continue;
    }
    try {
        $results = claudeBatchResults($info['results_url']);
    } catch (Exception $e) {
        cronLog("Batch $batchId: błąd pobrania wyników: " . $e->getMessage());
        continue;
    }
    cronLog("Batch $batchId: zakończony, " . count($results) . ' wyników');

    foreach ($items as $article) {
        $site = $sites[(int)$article['site_id']] ?? null;
        if (!$site) {
            // Strona wyłączona w międzyczasie — wpis wraca do kolejki
            $db->exec("UPDATE auto_publish_queue SET status = 'pending', batch_id = NULL WHERE id = " . (int)$article['id']);
            continue;
        }
        cronLog("  [{$site['name']}] #{$article['id']}: {$article['title']}");
        $db->exec("UPDATE auto_publish_queue SET status = 'generating' WHERE id = " . (int)$article['id']);
        try {
            $res = $results['q' . $article['id']] ?? null;
            if (!$res || $res['type'] !== 'succeeded') {
                $why = $res ? ($res['type'] . ($res['error'] ? ': ' . $res['error'] : '')) : 'brak wyniku w batchu';
                throw new ClaudeApiException("Batch: $why", true);
            }
            if ($res['stop_reason'] === 'max_tokens') throw new ClaudeApiException('Batch: odpowiedź ucięta (max_tokens)', true);
            $saved = json_decode((string)$article['quality_report'], true) ?: [];
            $ctx = $saved['ctx'] ?? null;
            if (!$ctx) throw new RuntimeException('Brak zapisanego kontekstu researchu');
            // Pisanie z batcha liczone po stawce Batch API (osobny klucz "batch:<model>" w usage)
            articleTrackUsage($ctx, ['model' => 'batch:' . ($res['model'] ?: $ctx['model'])] + $res, 'write');
            $article['_batch'] = true;
            $result = articleFinish($ctx, $res['text'], null, fn($m) => cronLog("    $m"));
            cronPublishArticle($site, $article, $result);
        } catch (Exception $e) {
            cronArticleFailed($site, $article, $e);
        }
    }
}

// ── Faza B: nowe artykuły z kolejki ─────────────────────────
$batchRequests = [];
$batchContexts = [];

foreach ($sites as $siteId => $site) {
    $limit = max(1, (int)$site['daily_limit']);
    $lang = $site['lang'] ?: 'pl';

    // Pending gotowe do generacji — pomija niezmapowane kategorie (zostają pending, user zmapuje)
    $qStmt = $db->prepare('
        SELECT * FROM auto_publish_queue
        WHERE site_id = :sid AND status = "pending"
        AND (
            category_name = ""
            OR wp_category_id IS NOT NULL
            OR LOWER(category_name) IN (SELECT LOWER(category_name) FROM auto_publish_category_map WHERE site_id = :sid2)
        )
        ORDER BY id LIMIT :lim
    ');
    $qStmt->bindValue(':sid', $siteId, SQLITE3_INTEGER);
    $qStmt->bindValue(':sid2', $siteId, SQLITE3_INTEGER);
    $qStmt->bindValue(':lim', $limit, SQLITE3_INTEGER);
    $res = $qStmt->execute();
    $articles = [];
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) $articles[] = $row;
    if (empty($articles)) continue;

    cronLog("Site #$siteId: {$site['name']} (limit: $limit)");

    foreach ($articles as $article) {
        $articleId = (int)$article['id'];
        cronLog("  Article #$articleId: {$article['title']}");
        $db->exec("UPDATE auto_publish_queue SET status = 'generating' WHERE id = $articleId");

        $in = [
            'title'              => $article['title'],
            'main_keyword'       => $article['main_keyword'],
            'secondary_keywords' => $article['secondary_keywords'],
            'notes'              => $article['notes'],
            'lang'               => $lang,
            'category'           => $article['category_name'],
        ];
        try {
            if ($batchMode) {
                // Research teraz (Senuto + SERP), samo pisanie w batchu
                $ctx = articlePrepare($in, fn($m) => cronLog("    $m"));
                $batchRequests['q' . $articleId] = articleWriteParams($ctx);
                $batchContexts[$articleId] = $ctx;
            } else {
                $result = generateArticleFull($in, null, fn($m) => cronLog("    $m"));
                cronPublishArticle($site, $article, $result);
            }
        } catch (Exception $e) {
            cronArticleFailed($site, $article, $e);
        }
    }
}

if ($batchRequests) {
    try {
        $batchId = claudeBatchCreate($batchRequests);
        $st = $db->prepare('UPDATE auto_publish_queue SET status = "batched", batch_id = :b, quality_report = :qr WHERE id = :id');
        foreach ($batchContexts as $articleId => $ctx) {
            $st->bindValue(':b', $batchId, SQLITE3_TEXT);
            $st->bindValue(':qr', json_encode(['ctx' => $ctx], JSON_UNESCAPED_UNICODE), SQLITE3_TEXT);
            $st->bindValue(':id', $articleId, SQLITE3_INTEGER);
            $st->execute();
            $st->reset();
        }
        cronLog("Batch $batchId wysłany: " . count($batchRequests) . ' artykułów (publikacja w kolejnym przebiegu)');
    } catch (Exception $e) {
        // Nie udało się wysłać batcha — wpisy wracają do kolejki bez zużycia prób
        $ids = implode(',', array_map('intval', array_keys($batchContexts)));
        $db->exec("UPDATE auto_publish_queue SET status = 'pending' WHERE id IN ($ids)");
        cronLog('Błąd wysyłki batcha: ' . $e->getMessage());
        $totalErrors++;
    }
}

$report = array_values($report);

// ── Helpers: publikacja / błąd pojedynczego wpisu ────────────

/**
 * Losowa data publikacji (UTC, format WP REST) z przedziału [teraz − N h, teraz − 5 min],
 * bez godzin nocnych 0:00–5:59 czasu polskiego. null = wyłączone (WP ustawi bieżącą godzinę).
 * Ustawienia: auto_publish_backdate (0/1, domyślnie 1), auto_publish_backdate_hours (1–23, domyślnie 12).
 */
function cronBackdateGmt(): ?string {
    if (claudeSetting('auto_publish_backdate') === '0') return null;
    $hours = max(1, min(23, (int)(claudeSetting('auto_publish_backdate_hours') ?: 12)));
    $now = time();
    $tz = new DateTimeZone('Europe/Warsaw');
    for ($i = 0; $i < 50; $i++) {
        $ts = $now - random_int(300, $hours * 3600);
        $hour = (int)(new DateTime('@' . $ts))->setTimezone($tz)->format('G');
        if ($hour >= 6) return gmdate('Y-m-d\TH:i:s', $ts);
    }
    return gmdate('Y-m-d\TH:i:s', $now - 300);   // całe okno w nocy — 5 minut temu
}

/** Kategorie i autorzy strony — pobierane raz na przebieg. */
function cronSiteContext(array $site): array {
    global $db, $siteCtxCache;
    $siteId = (int)$site['id'];
    if (isset($siteCtxCache[$siteId])) return $siteCtxCache[$siteId];

    $catStmt = $db->prepare('SELECT category_name, wp_category_id FROM auto_publish_category_map WHERE site_id = :sid');
    $catStmt->bindValue(':sid', $siteId, SQLITE3_INTEGER);
    $catResult = $catStmt->execute();
    $catMap = [];
    while ($row = $catResult->fetchArray(SQLITE3_ASSOC)) $catMap[mb_strtolower($row['category_name'])] = (int)$row['wp_category_id'];

    $authors = [];
    if ($site['random_author']) {
        try {
            $authors = (new WpApi($site['url'], $site['username'], $site['app_password']))->getAuthors();
        } catch (Exception $e) {
            // Fallback: no random author
        }
    }
    return $siteCtxCache[$siteId] = ['catMap' => $catMap, 'authors' => $authors];
}

function cronReportSite(array $site): void {
    global $report;
    $report[$site['id']] ??= ['site' => $site['name'], 'published' => 0, 'errors' => 0, 'articles' => []];
}

/** Okładka (Gemini) + publikacja w WP + zapis w kolejce i publications. */
function cronPublishArticle(array $site, array $article, array $result): void {
    global $db, $geminiKey, $speedLinksUrls, $report, $totalPublished;
    $articleId = (int)$article['id'];
    $articleTitle = $article['title'];
    $siteCtx = cronSiteContext($site);
    cronReportSite($site);

    $failed = array_column(articleFailures($result['checks']), 'name');
    cronLog('    Audyt: ' . ($failed ? 'FAIL ' . implode(', ', $failed) : 'OK') . ", {$result['char_count']} znaków, ~\${$result['cost_usd']}" . (!empty($article['_batch']) ? ' (batch)' : ''));
    if (!empty($result['steps'])) cronLog('    Koszt kroków: ' . articleStepsSummary($result['steps']));
    foreach ($result['notes'] as $n) cronLog("    $n");

    $qrStmt = $db->prepare('UPDATE auto_publish_queue SET status = "generated", quality_report = :qr WHERE id = :id');
    $qrStmt->bindValue(':qr', json_encode([
        'checks' => $result['checks'], 'research' => $result['research'], 'usage' => $result['usage'], 'steps' => $result['steps'] ?? [],
        'cost_usd' => $result['cost_usd'], 'model' => $result['model'], 'notes' => $result['notes'],
        'batch' => !empty($article['_batch']),
    ], JSON_UNESCAPED_UNICODE), SQLITE3_TEXT);
    $qrStmt->bindValue(':id', $articleId, SQLITE3_INTEGER);
    $qrStmt->execute();

    // Okładka przez Gemini
    $featuredMediaId = 0;
    $imageError = '';
    if ($geminiKey) {
        try {
            // Bez napisów: tytuł nie idzie do promptu, gotowa grafika jest sprawdzana pod kątem tekstu
            $geminiErrors = [];
            $imageBin = generateTextFreeImage($geminiKey, $articleTitle, '', '16:9', $geminiErrors, fn($m) => cronLog("    $m"));
            $imageData = $imageBin !== null ? base64_encode($imageBin) : null;
            if ($imageData) {
                $wpApi = new WpApi($site['url'], $site['username'], $site['app_password']);
                // Nazwa pliku i alt z frazy / tytułu (wcześniej losowy hex i brak alt)
                $imageSlug = slugify($article['main_keyword'] ?: $articleTitle) ?: 'okladka';
                $optimized = optimizeImage(base64_decode($imageData), $imageSlug . '.jpg', true);
                $featuredMediaId = $wpApi->uploadMedia($optimized['filename'], $optimized['data'], $optimized['mime']);
                if ($featuredMediaId) {
                    try {
                        $wpApi->updateMedia($featuredMediaId, ['alt_text' => $articleTitle, 'title' => $articleTitle]);
                    } catch (Exception $e) {
                        cronLog('    Alt obrazka nie zapisany: ' . $e->getMessage());
                    }
                }
            } else {
                $imageError = 'Gemini: ' . implode('; ', $geminiErrors ?: ['brak danych']);
            }
        } catch (Exception $e) {
            $imageError = $e->getMessage();
        }
    } else {
        $imageError = 'Brak klucza Gemini API';
    }

    // Publikacja w WordPress (czas publikacji = czas serwera WP)
    $db->exec("UPDATE auto_publish_queue SET status = 'publishing' WHERE id = $articleId");
    $wpApi = new WpApi($site['url'], $site['username'], $site['app_password']);
    $postData = ['title' => $articleTitle, 'content' => $result['html'], 'status' => 'publish'];

    $wpCatId = $article['wp_category_id'];
    if (!$wpCatId && $article['category_name']) $wpCatId = $siteCtx['catMap'][mb_strtolower($article['category_name'])] ?? 0;
    if ($wpCatId > 0) $postData['categories'] = [$wpCatId];

    if ($site['random_author'] && !empty($siteCtx['authors'])) {
        $postData['author'] = $siteCtx['authors'][array_rand($siteCtx['authors'])]['id'];
    }
    if ($featuredMediaId > 0) $postData['featured_media'] = $featuredMediaId;

    // Losowa godzina publikacji wstecz (każdy wpis osobno) — bez wspólnego wzorca godzin w sieci.
    // date_gmt (UTC) = niezależnie od strefy czasowej WP; tylko przeszłość, więc wpis nie staje się "zaplanowany".
    $backdate = cronBackdateGmt();
    if ($backdate) $postData['date_gmt'] = $backdate;

    $created = $wpApi->createPost($postData);
    if (($created['status'] ?? 'publish') !== 'publish' && !empty($created['id'])) {
        // Zabezpieczenie: WP uznał datę za przyszłą (np. zła godzina serwera) — publikujemy z bieżącą godziną
        cronLog('    WP oznaczył wpis jako "' . ($created['status'] ?? '?') . '" — publikuję z bieżącą godziną');
        $created = $wpApi->updatePost((int)$created['id'], ['status' => 'publish', 'date_gmt' => gmdate('Y-m-d\TH:i:s', time() - 60)]);
    } elseif ($backdate) {
        cronLog('    Data publikacji: ' . (new DateTime($backdate . 'Z'))->setTimezone(new DateTimeZone('Europe/Warsaw'))->format('Y-m-d H:i'));
    }
    $postUrl = $created['link'] ?? '';

    $upStmt = $db->prepare('UPDATE auto_publish_queue SET status = "published", published_url = :url, published_at = datetime("now") WHERE id = :id');
    $upStmt->bindValue(':url', $postUrl, SQLITE3_TEXT);
    $upStmt->bindValue(':id', $articleId, SQLITE3_INTEGER);
    $upStmt->execute();

    // Record publication — use first admin as the CRON "user" (FK to users.id)
    static $cronUserId = null;
    if ($cronUserId === null) {
        $u = $db->querySingle("SELECT id FROM users WHERE role = 'admin' ORDER BY id LIMIT 1", true);
        $cronUserId = $u ? (int)$u['id'] : 1;
    }
    $pubStmt = $db->prepare('INSERT INTO publications (user_id, site_id, post_url, post_title) VALUES (:uid, :sid, :url, :title)');
    $pubStmt->bindValue(':uid', $cronUserId, SQLITE3_INTEGER);
    $pubStmt->bindValue(':sid', (int)$site['id'], SQLITE3_INTEGER);
    $pubStmt->bindValue(':url', $postUrl, SQLITE3_TEXT);
    $pubStmt->bindValue(':title', $articleTitle, SQLITE3_TEXT);
    $pubStmt->execute();

    // Indeksacja (Rapid URL Indexer): każda publikacja do kolejki, paczka wysyłana na koniec przebiegu
    if ($postUrl && (indexAutoEnabled() || $site['use_speed_links'])) {
        if (indexQueueUrl($postUrl, (int)$site['id'], 'auto-publikacja')) $speedLinksUrls[] = $postUrl;
    }

    $report[$site['id']]['published']++;
    $totalPublished++;
    $articleInfo = ['title' => $articleTitle, 'url' => $postUrl, 'status' => 'ok'];
    if ($imageError) $articleInfo['image_error'] = $imageError;
    if (!$featuredMediaId) $articleInfo['no_image'] = true;
    $report[$site['id']]['articles'][] = $articleInfo;

    cronLog("  Published: $postUrl");
    // Losowa przerwa między publikacjami — rate limit WP + naturalne godziny publikacji
    sleep(rand(3, 15));
}

function cronArticleFailed(array $site, array $article, Exception $e): void {
    global $db, $report, $totalErrors;
    cronReportSite($site);
    $errMsg = $e->getMessage();
    // Błąd przejściowy API (przeciążenie, timeout, nieudany wynik batcha) po wyczerpaniu retry
    // w kliencie: wpis wraca do kolejki i spróbuje w kolejnym przebiegu, maks. 3 razy
    $retry = ($e instanceof ClaudeApiException && $e->retryable && (int)($article['attempts'] ?? 0) < 2);
    $errStmt = $db->prepare('UPDATE auto_publish_queue SET status = :st, error_message = :err, attempts = attempts + 1, batch_id = NULL WHERE id = :id');
    $errStmt->bindValue(':st', $retry ? 'pending' : 'error', SQLITE3_TEXT);
    $errStmt->bindValue(':err', $errMsg, SQLITE3_TEXT);
    $errStmt->bindValue(':id', (int)$article['id'], SQLITE3_INTEGER);
    $errStmt->execute();
    cronLog(($retry ? '    Błąd przejściowy, wpis wraca do kolejki: ' : '    Błąd: ') . $errMsg);

    $report[$site['id']]['errors']++;
    $totalErrors++;
    $report[$site['id']]['articles'][] = ['title' => $article['title'], 'url' => '', 'status' => 'error', 'error' => $errMsg];
}

// ── Rapid URL Indexer — jedna paczka z publikacjami tego przebiegu (includes/rapid_indexing.php) ──
$speedLinksResult = '';
if (!empty($speedLinksUrls)) {
    $idx = indexFlush(true, 'auto-publikacja');
    if ($idx['submitted']) {
        $speedLinksResult = "Rapid Indexer: {$idx['submitted']} URL-i wysłano (projekt #{$idx['project_id']}, kredyty: {$idx['credits_left']})";
    } elseif ($idx['error']) {
        $speedLinksResult = "Rapid Indexer error: {$idx['error']} (URL-e czekają w kolejce)";
    }
    if ($idx['deferred']) $speedLinksResult .= " | {$idx['deferred']} niedostępnych, ponowienie za 6 h";
    cronLog($speedLinksResult);
}

// ── Telegram report ──────────────────────────────────────────
cronLog("Sending Telegram report...");
$telegramResult = sendTelegramReport($db, $report, $totalPublished, $totalErrors, $speedLinksResult);
cronLog("Telegram result: $telegramResult");
cronLog("Indexer result: $speedLinksResult");
cronLog("CRON done — published: $totalPublished, errors: $totalErrors");


// ── Response ─────────────────────────────────────────────────
echo json_encode([
    'success' => true,
    'published' => $totalPublished,
    'errors' => $totalErrors,
    'report' => $report,
    'indexer' => $speedLinksResult,
    'indexer_debug' => [
        'urls_count' => count($speedLinksUrls),
        'has_key' => !empty($rapidKey),
    ],
    'telegram' => $telegramResult,
]);


// ── Helper: Send Telegram report ─────────────────────────────
function sendTelegramReport(SQLite3 $db, array $report, int $totalPublished, int $totalErrors, string $speedLinksResult): string {
    $tokenRow = $db->querySingle("SELECT value FROM settings WHERE key = 'telegram_bot_token'", true);
    $chatRow = $db->querySingle("SELECT value FROM settings WHERE key = 'telegram_chat_id'", true);

    $botToken = $tokenRow ? trim($tokenRow['value']) : '';
    $chatId = $chatRow ? trim($chatRow['value']) : '';

    if (!$botToken || !$chatId) return 'Brak tokena lub chat_id';

    $date = date('Y-m-d');
    $emoji = $totalErrors === 0 ? "\xE2\x9C\x85" : "\xE2\x9A\xA0\xEF\xB8\x8F";
    $h = fn($s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

    // ── Part 1: Summary message (always short) ───────────────
    $sitesCount = count($report);
    $noImgCount = 0;
    foreach ($report as $r) {
        foreach ($r['articles'] as $a) {
            if (!empty($a['no_image'])) $noImgCount++;
        }
    }
    $summary = "$emoji <b>Auto-publikacja $date</b>\n\n";
    $summary .= "Opublikowano: <b>$totalPublished</b> | Błędy: <b>$totalErrors</b>\n";
    $summary .= "Stron: <b>$sitesCount</b>";
    if ($noImgCount > 0) $summary .= " | Bez obrazu: <b>$noImgCount</b>";
    $summary .= "\n";
    if ($speedLinksResult) $summary .= "\n\xF0\x9F\x94\x97 {$h($speedLinksResult)}\n";

    $sendResult = telegramSendMessage($botToken, $chatId, $summary);
    if ($sendResult !== 'ok') return $sendResult;

    // ── Part 2+: Details chunked into messages under 4096 chars ─
    $chunks = [];
    $current = '';
    foreach ($report as $r) {
        $siteEmoji = $r['errors'] === 0 ? "\xE2\x9C\x85" : "\xE2\x9A\xA0\xEF\xB8\x8F";
        $siteBlock = "$siteEmoji <b>{$h($r['site'])}</b> ({$r['published']}/{$r['errors']})\n";
        foreach ($r['articles'] as $a) {
            if ($a['status'] === 'ok') {
                $imgIcon = !empty($a['no_image']) ? "\xF0\x9F\x96\xBC\xE2\x9D\x8C" : "\xF0\x9F\x96\xBC\xE2\x9C\x85";
                $siteBlock .= "  \xE2\x80\xA2 {$h($a['title'])} {$imgIcon}\n";
                if (!empty($a['url'])) {
                    $siteBlock .= "    <a href=\"{$h($a['url'])}\">{$h($a['url'])}</a>\n";
                }
                if (!empty($a['image_error'])) {
                    $siteBlock .= "    \xE2\x9A\xA0 {$h($a['image_error'])}\n";
                }
            } else {
                $siteBlock .= "  \xE2\x9D\x8C {$h($a['title'])}: {$h($a['error'])}\n";
            }
        }
        $siteBlock .= "\n";

        // If adding this block would exceed 3800 chars (safe margin below 4096), start new chunk
        if (strlen($current) + strlen($siteBlock) > 3800) {
            if ($current) $chunks[] = $current;
            $current = $siteBlock;
        } else {
            $current .= $siteBlock;
        }
    }
    if ($current) $chunks[] = $current;

    // Send each chunk with 1s delay to avoid rate limits
    $totalChunks = count($chunks);
    foreach ($chunks as $i => $chunk) {
        $header = "\xF0\x9F\x93\x9D <b>Szczegóły " . ($i + 1) . "/$totalChunks</b>\n\n";
        $result = telegramSendMessage($botToken, $chatId, $header . $chunk);
        if ($result !== 'ok') return "Chunk " . ($i + 1) . " failed: $result";
        if ($i < $totalChunks - 1) sleep(1);
    }

    return 'ok';
}

function telegramSendMessage(string $botToken, string $chatId, string $text): string {
    $url = "https://api.telegram.org/bot$botToken/sendMessage";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
        ]),
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $response = curl_exec($ch);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($curlErr) return "cURL error: $curlErr";
    $data = json_decode($response, true);
    if (!($data['ok'] ?? false)) {
        return 'Telegram error: ' . ($data['description'] ?? substr($response ?: '', 0, 200));
    }
    return 'ok';
}
