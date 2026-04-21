<?php
/**
 * CRON: Auto-publish articles from queue.
 * Runs daily at 9:00 AM. For each enabled site, generates and publishes X articles.
 * Sends Telegram report after completion.
 *
 * Usage (CLI — recommended, no HTTP timeout):
 *   php cron-auto-publish.php --token=SECRET
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
require_once __DIR__ . '/../includes/article_prompt.php';
require_once __DIR__ . '/../includes/image_utils.php';

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

// Reset stuck articles from previous crashed runs
$db->exec("UPDATE auto_publish_queue SET status = 'pending' WHERE status IN ('generating', 'generated', 'publishing')");
cronLog('CRON start — stuck articles reset');

// ── Settings ─────────────────────────────────────────────────
$apiKeyRow = $db->querySingle("SELECT value FROM settings WHERE key = 'anthropic_api_key'", true);
$anthropicKey = $apiKeyRow ? trim($apiKeyRow['value']) : '';

$modelRow = $db->querySingle("SELECT value FROM settings WHERE key = 'ai_model'", true);
$aiModel = ($modelRow && !empty($modelRow['value'])) ? $modelRow['value'] : 'claude-sonnet-4-6';

$geminiKeyRow = $db->querySingle("SELECT value FROM settings WHERE key = 'gemini_api_key'", true);
$geminiKey = $geminiKeyRow ? trim($geminiKeyRow['value']) : '';

$speedLinksKeyRow = $db->querySingle("SELECT value FROM settings WHERE key = 'speedlinks_api_key'", true);
$speedLinksKey = $speedLinksKeyRow ? trim($speedLinksKeyRow['value']) : '';

$speedLinksMethodRow = $db->querySingle("SELECT value FROM settings WHERE key = 'speedlinks_method'", true);
$speedLinksMethod = ($speedLinksMethodRow && !empty($speedLinksMethodRow['value'])) ? $speedLinksMethodRow['value'] : 'vip';

if (!$anthropicKey) {
    echo json_encode(['error' => 'Brak klucza Anthropic API']);
    exit;
}

// ── Get enabled sites with pending articles ──────────────────
$sites = [];
$result = $db->query('
    SELECT s.id, s.name, s.url, s.username, s.app_password,
           apc.daily_limit, apc.use_speed_links, apc.use_inline_images, apc.random_author, apc.lang
    FROM auto_publish_config apc
    JOIN sites s ON s.id = apc.site_id
    WHERE apc.enabled = 1
    ORDER BY s.name
');
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    // Check if site has pending articles
    $pendingStmt = $db->prepare('SELECT COUNT(*) as cnt FROM auto_publish_queue WHERE site_id = :sid AND status = "pending"');
    $pendingStmt->bindValue(':sid', $row['id'], SQLITE3_INTEGER);
    $pending = $pendingStmt->execute()->fetchArray(SQLITE3_ASSOC)['cnt'];
    if ($pending > 0) {
        $row['pending_count'] = $pending;
        $sites[] = $row;
    }
}

if (empty($sites)) {
    echo json_encode(['success' => true, 'message' => 'Brak stron z oczekującymi artykułami.', 'published' => 0]);
    exit;
}

// ── Process each site ────────────────────────────────────────
$totalPublished = 0;
$totalErrors = 0;
$report = []; // For Telegram
$speedLinksUrls = [];

cronLog('Sites to process: ' . count($sites));

foreach ($sites as $site) {
    $siteId = $site['id'];
    $limit = max(1, (int)$site['daily_limit']);
    $lang = $site['lang'] ?: 'pl';
    cronLog("Processing site #$siteId: {$site['name']} (limit: $limit)");

    // Get pending articles (limit by daily_limit)
    $qStmt = $db->prepare('SELECT * FROM auto_publish_queue WHERE site_id = :sid AND status = "pending" ORDER BY id LIMIT :lim');
    $qStmt->bindValue(':sid', $siteId, SQLITE3_INTEGER);
    $qStmt->bindValue(':lim', $limit, SQLITE3_INTEGER);
    $result = $qStmt->execute();
    $articles = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) $articles[] = $row;

    if (empty($articles)) continue;

    // Get category mappings for this site
    $catStmt = $db->prepare('SELECT category_name, wp_category_id FROM auto_publish_category_map WHERE site_id = :sid');
    $catStmt->bindValue(':sid', $siteId, SQLITE3_INTEGER);
    $catResult = $catStmt->execute();
    $catMap = [];
    while ($row = $catResult->fetchArray(SQLITE3_ASSOC)) {
        $catMap[mb_strtolower($row['category_name'])] = (int)$row['wp_category_id'];
    }

    // Get authors if random_author enabled
    $authors = [];
    if ($site['random_author']) {
        try {
            $wpApi = new WpApi($site['url'], $site['username'], $site['app_password']);
            $authors = $wpApi->getAuthors();
        } catch (Exception $e) {
            // Fallback: no random author
        }
    }

    $sitePublished = 0;
    $siteErrors = 0;
    $siteArticles = [];

    foreach ($articles as $article) {
        $articleId = $article['id'];
        $articleTitle = $article['title'];
        cronLog("  Article #$articleId: $articleTitle");

        // Mark as generating
        $db->exec("UPDATE auto_publish_queue SET status = 'generating' WHERE id = $articleId");

        try {
            // 1. Generate article content via Claude API
            $systemPrompt = getArticleSystemPrompt($lang);
            $userPrompt = buildArticleUserPrompt(
                $articleTitle,
                $article['main_keyword'],
                $article['secondary_keywords'],
                $article['notes'],
                $lang
            );

            $payload = [
                'model' => $aiModel,
                'max_tokens' => 16000,
                'system' => $systemPrompt,
                'messages' => [['role' => 'user', 'content' => $userPrompt]],
            ];

            $ch = curl_init('https://api.anthropic.com/v1/messages');
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'x-api-key: ' . $anthropicKey,
                    'anthropic-version: 2023-06-01',
                ],
                CURLOPT_POSTFIELDS => json_encode($payload),
                CURLOPT_TIMEOUT => 180,
                CURLOPT_CONNECTTIMEOUT => 30,
                CURLOPT_SSL_VERIFYPEER => false,
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if (curl_errno($ch)) throw new RuntimeException('cURL: ' . curl_error($ch));
            curl_close($ch);

            if ($httpCode >= 400) {
                $errData = json_decode($response, true);
                throw new RuntimeException('Claude API HTTP ' . $httpCode . ': ' . ($errData['error']['message'] ?? 'Unknown error'));
            }

            $data = json_decode($response, true);
            $markdown = '';
            foreach (($data['content'] ?? []) as $block) {
                if (($block['type'] ?? '') === 'text') $markdown .= $block['text'];
            }
            if (!$markdown) throw new RuntimeException('Claude API nie zwróciło treści');

            // Remove H1, convert to HTML
            $markdown = preg_replace('/^#\s+.+\n*/m', '', $markdown, 1);
            $htmlContent = markdownToHtml($markdown);
            $htmlContent = sanitizeArticleHtml($htmlContent);

            // Mark as generated
            $db->exec("UPDATE auto_publish_queue SET status = 'generated' WHERE id = $articleId");

            // 2. Generate featured image via Gemini (always)
            $featuredMediaId = 0;
            $imageError = '';
            if ($geminiKey) {
                try {
                    $imagePrompt = "Professional blog header image for article titled: \"$articleTitle\". Modern, clean, editorial style. No text on image.";
                    $geminiErrors = [];
                    $imageData = generateGeminiImage($geminiKey, $imagePrompt, $geminiErrors);
                    if ($imageData) {
                        $wpApi = new WpApi($site['url'], $site['username'], $site['app_password']);
                        $optimized = optimizeImage(base64_decode($imageData), 'featured.jpg');
                        $featuredMediaId = $wpApi->uploadMedia($optimized['filename'], $optimized['data'], $optimized['mime']);
                    } else {
                        $imageError = 'Gemini: ' . implode('; ', $geminiErrors ?: ['brak danych']);
                    }
                } catch (Exception $e) {
                    $imageError = $e->getMessage();
                }
            } else {
                $imageError = 'Brak klucza Gemini API';
            }

            // 3. Publish to WordPress
            $db->exec("UPDATE auto_publish_queue SET status = 'publishing' WHERE id = $articleId");

            $wpApi = new WpApi($site['url'], $site['username'], $site['app_password']);

            // Publish immediately — WordPress uses current server time
            // Random delay 0-180s between articles makes publish times look natural
            $postData = [
                'title' => $articleTitle,
                'content' => $htmlContent,
                'status' => 'publish',
            ];

            // Category mapping
            $wpCatId = $article['wp_category_id'];
            if (!$wpCatId && $article['category_name']) {
                $wpCatId = $catMap[mb_strtolower($article['category_name'])] ?? 0;
            }
            if ($wpCatId > 0) $postData['categories'] = [$wpCatId];

            // Random author
            if ($site['random_author'] && !empty($authors)) {
                $randomAuthor = $authors[array_rand($authors)];
                $postData['author'] = $randomAuthor['id'];
            }

            // Featured image
            if ($featuredMediaId > 0) $postData['featured_media'] = $featuredMediaId;

            $result = $wpApi->createPost($postData);
            $postUrl = $result['link'] ?? '';

            // Mark as published
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
            $pubStmt->bindValue(':sid', $siteId, SQLITE3_INTEGER);
            $pubStmt->bindValue(':url', $postUrl, SQLITE3_TEXT);
            $pubStmt->bindValue(':title', $articleTitle, SQLITE3_TEXT);
            $pubStmt->execute();

            // Collect for Speed Links
            if ($site['use_speed_links'] && $postUrl) $speedLinksUrls[] = $postUrl;

            $sitePublished++;
            $totalPublished++;
            $articleInfo = ['title' => $articleTitle, 'url' => $postUrl, 'status' => 'ok'];
            if ($imageError) $articleInfo['image_error'] = $imageError;
            if (!$featuredMediaId) $articleInfo['no_image'] = true;
            $siteArticles[] = $articleInfo;

            cronLog("  Published: $postUrl");
            // Random delay between articles — avoids rate limits + makes publish times look natural
            sleep(rand(3, 15));

        } catch (Exception $e) {
            $errMsg = $e->getMessage();
            $errStmt = $db->prepare('UPDATE auto_publish_queue SET status = "error", error_message = :err WHERE id = :id');
            $errStmt->bindValue(':err', $errMsg, SQLITE3_TEXT);
            $errStmt->bindValue(':id', $articleId, SQLITE3_INTEGER);
            $errStmt->execute();

            $siteErrors++;
            $totalErrors++;
            $siteArticles[] = ['title' => $articleTitle, 'url' => '', 'status' => 'error', 'error' => $errMsg];
        }
    }

    $report[] = [
        'site' => $site['name'],
        'published' => $sitePublished,
        'errors' => $siteErrors,
        'articles' => $siteArticles,
    ];
}

// ── Speed Links submission ───────────────────────────────────
$speedLinksResult = '';
if (!empty($speedLinksUrls) && $speedLinksKey) {
    try {
        $qstring = 'apikey=' . urlencode($speedLinksKey)
            . '&cmd=submit'
            . '&campaign='
            . '&urls=' . urlencode(implode('|', $speedLinksUrls))
            . '&dripfeed=1'
            . '&reporturl=1'
            . '&method=' . urlencode($speedLinksMethod);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_URL => 'http://speed-links.net/api.php',
            CURLOPT_HEADER => false,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 40,
            CURLOPT_POSTFIELDS => $qstring,
        ]);
        $slResponse = curl_exec($ch);
        $slError = curl_error($ch);
        curl_close($ch);

        if ($slError) {
            $speedLinksResult = "Speed Links error: $slError";
        } else {
            $parts = explode('|', $slResponse, 2);
            $isOk = (strtoupper(trim($parts[0])) === 'OK');
            if ($isOk) {
                $speedLinksResult = "Speed Links: " . count($speedLinksUrls) . " URLs wysłano";
                if (!empty($parts[1])) $speedLinksResult .= " (raport: {$parts[1]})";
            } else {
                $speedLinksResult = "Speed Links error: " . trim($slResponse);
            }
        }
    } catch (Exception $e) {
        $speedLinksResult = "Speed Links error: " . $e->getMessage();
    }
}

// ── Telegram report ──────────────────────────────────────────
cronLog("Sending Telegram report...");
$telegramResult = sendTelegramReport($db, $report, $totalPublished, $totalErrors, $speedLinksResult);
cronLog("Telegram result: $telegramResult");
cronLog("Speed Links result: $speedLinksResult");
cronLog("CRON done — published: $totalPublished, errors: $totalErrors");

// Remove lock file
@unlink(__DIR__ . '/../data/cron-auto-publish.lock');

// ── Response ─────────────────────────────────────────────────
echo json_encode([
    'success' => true,
    'published' => $totalPublished,
    'errors' => $totalErrors,
    'report' => $report,
    'speed_links' => $speedLinksResult,
    'speed_links_debug' => [
        'urls_count' => count($speedLinksUrls),
        'has_key' => !empty($speedLinksKey),
    ],
    'telegram' => $telegramResult,
]);

// ── Helper: Generate image via Gemini ────────────────────────
function generateGeminiImage(string $apiKey, string $prompt, array &$errors = []): ?string {
    // Try Gemini native models first, then Imagen
    $geminiModels = ['gemini-2.5-flash-image', 'gemini-3.1-flash-image-preview', 'gemini-3-pro-image-preview'];
    $imagenModels = ['imagen-4.0-fast-generate-001', 'imagen-4.0-generate-001'];

    // Gemini native (responseModalities: IMAGE only)
    foreach ($geminiModels as $model) {
        $url = "https://generativelanguage.googleapis.com/v1beta/models/$model:generateContent?key=$apiKey";
        $payload = [
            'contents' => [['parts' => [['text' => $prompt]]]],
            'generationConfig' => [
                'responseModalities' => ['IMAGE'],
                'imageConfig' => ['aspectRatio' => '16:9'],
            ],
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_TIMEOUT => 60,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200) {
            $data = json_decode($response, true);
            $parts = $data['candidates'][0]['content']['parts'] ?? [];
            foreach ($parts as $part) {
                if (isset($part['inlineData']['data'])) {
                    return $part['inlineData']['data'];
                }
            }
            $errors[] = "$model: HTTP 200 but no image data";
        } else {
            $errData = json_decode($response, true);
            $errMsg = $errData['error']['message'] ?? "HTTP $httpCode";
            $errors[] = "$model: $errMsg";
        }
    }

    // Imagen fallback (predict endpoint)
    foreach ($imagenModels as $model) {
        $url = "https://generativelanguage.googleapis.com/v1beta/models/$model:predict?key=$apiKey";
        $payload = [
            'instances' => [['prompt' => $prompt]],
            'parameters' => ['sampleCount' => 1, 'aspectRatio' => '16:9'],
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_TIMEOUT => 60,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200) {
            $data = json_decode($response, true);
            $predictions = $data['predictions'] ?? [];
            if (!empty($predictions[0]['bytesBase64Encoded'])) {
                return $predictions[0]['bytesBase64Encoded'];
            }
            $errors[] = "$model: HTTP 200 but no predictions";
        } else {
            $errData = json_decode($response, true);
            $errMsg = $errData['error']['message'] ?? "HTTP $httpCode";
            $errors[] = "$model: $errMsg";
        }
    }

    return null;
}

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

    $msg = "$emoji <b>Auto-publikacja $date</b>\n\n";
    $msg .= "Opublikowano: <b>$totalPublished</b> | Błędy: <b>$totalErrors</b>\n\n";

    foreach ($report as $r) {
        $siteEmoji = $r['errors'] === 0 ? "\xE2\x9C\x85" : "\xE2\x9A\xA0\xEF\xB8\x8F";
        $msg .= "$siteEmoji <b>{$h($r['site'])}</b> ({$r['published']}/{$r['errors']})\n";
        foreach ($r['articles'] as $a) {
            if ($a['status'] === 'ok') {
                $imgIcon = !empty($a['no_image']) ? "\xF0\x9F\x96\xBC\xE2\x9D\x8C" : "\xF0\x9F\x96\xBC\xE2\x9C\x85";
                $msg .= "  \xE2\x80\xA2 {$h($a['title'])} {$imgIcon}\n";
                if (!empty($a['url'])) {
                    $msg .= "    <a href=\"{$h($a['url'])}\">{$h($a['url'])}</a>\n";
                }
                if (!empty($a['image_error'])) {
                    $msg .= "    \xE2\x9A\xA0 Obraz: {$h($a['image_error'])}\n";
                }
            } else {
                $msg .= "  \xE2\x9D\x8C {$h($a['title'])}: {$h($a['error'])}\n";
            }
        }
        $msg .= "\n";
    }

    if ($speedLinksResult) $msg .= "\xF0\x9F\x94\x97 {$h($speedLinksResult)}\n";
    $msg .= "\n\xF0\x9F\x95\x90 Następna publikacja: jutro o 9:00";

    // Send via Telegram Bot API (HTML mode — safe with special chars in titles)
    $url = "https://api.telegram.org/bot$botToken/sendMessage";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'chat_id' => $chatId,
            'text' => $msg,
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
        return 'Telegram error: ' . ($data['description'] ?? $response);
    }
    return 'ok';
}
