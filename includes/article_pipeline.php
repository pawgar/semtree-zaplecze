<?php
/**
 * Pipeline generowania artykułu zapleczowego — wspólny dla:
 *   - Zamów i opublikuj (pojedynczo i masowo) — api/generate-article.php
 *   - Auto publikacji — api/cron-auto-publish.php
 *
 * Kroki (wzorowane na skillu seo-blog-post-writer-v2 i pipeline Ateny):
 *   1. Research: Senuto (frazy, pytania, grupy) + filtr trafności tanim modelem
 *   2. Analiza SERP: web_search + web_fetch top wyników (format, encje, sekcje, fakty)
 *   3. Pisanie: główny model, prompt z includes/article_prompt.php
 *   4. Audyt: includes/article_validator.php, poprawka modelem przy FAIL-ach (maks. 2 razy)
 *   5. Korekta językowa + deterministyczne poprawki (znaki zapytania, myślniki)
 *
 * Każdy krok researchu jest opcjonalny: błąd Senuto/SERP nie blokuje artykułu, trafia do logu.
 */

require_once __DIR__ . '/claude_client.php';
require_once __DIR__ . '/senuto_api.php';
require_once __DIR__ . '/article_prompt.php';
require_once __DIR__ . '/article_validator.php';

const SERP_CACHE_DAYS = 14;

/**
 * Pełny pipeline synchronicznie (generowanie pojedyncze, auto-publikacja bez Batch API).
 *
 * @param array $in {title, main_keyword, secondary_keywords, notes, lang, category}
 * @param callable|null $progress wołane co kilka sekund w trakcie długich requestów (keepalive HTTP)
 * @param callable|null $log      fn(string $msg) — postęp kroków
 * @return array {markdown, html, char_count, checks, research, usage, cost_usd, model, notes}
 */
function generateArticleFull(array $in, ?callable $progress = null, ?callable $log = null): array {
    $log = $log ?? fn($m) => null;
    $ctx = articlePrepare($in, $log);

    // 3. Pisanie
    $log('Pisanie artykułu (' . $ctx['model'] . ')');
    $resp = claudeRequest(articleWriteParams($ctx) + ['on_progress' => $progress]);
    articleTrackUsage($ctx, $resp);

    return articleFinish($ctx, $resp['text'], $progress, $log);
}

/**
 * Etap 1-2: normalizacja wejścia + research (Senuto, SERP). Kontekst przechodzi przez
 * kolejne etapy — w trybie Batch API jest zapisywany w kolejce między przebiegami CRON-a.
 */
function articlePrepare(array $in, ?callable $log = null): array {
    $log = $log ?? fn($m) => null;
    $ctx = [
        'title'     => trim($in['title'] ?? ''),
        'main_kw'   => trim($in['main_keyword'] ?? ''),
        'secondary' => trim($in['secondary_keywords'] ?? ''),
        'notes'     => trim($in['notes'] ?? ''),
        'lang'      => trim($in['lang'] ?? 'pl') ?: 'pl',
        'category'  => trim($in['category'] ?? ''),
        'model'     => claudeMainModel(),
        'usage'     => [],
        'pipeline_notes' => [],
    ];
    $notes = [];
    $track = function (array $resp) use (&$ctx) { articleTrackUsage($ctx, $resp); };
    $ctx['research'] = articleResearch($ctx['title'], $ctx['main_kw'], $ctx['secondary'], $ctx['lang'], $track, $notes, $log);
    $ctx['pipeline_notes'] = $notes;
    return $ctx;
}

/** Parametry requestu pisania (wspólne dla wywołania bezpośredniego i Batch API). */
function articleWriteParams(array $ctx): array {
    return [
        'model' => $ctx['model'],
        'system' => getArticleSystemPrompt($ctx['lang']),
        'messages' => [['role' => 'user', 'content' => buildArticleUserPrompt(
            $ctx['title'], $ctx['main_kw'], $ctx['secondary'], $ctx['notes'], $ctx['lang'], $ctx['category'], $ctx['research'])]],
        'effort' => claudeMainEffort(),
        'max_tokens' => 32000,
    ];
}

function articleTrackUsage(array &$ctx, array $resp): void {
    $m = $resp['model'] ?? '';
    foreach ($resp['usage'] ?? [] as $k => $v) {
        if (is_int($v)) $ctx['usage'][$m][$k] = ($ctx['usage'][$m][$k] ?? 0) + $v;
    }
}

/**
 * Etap 4-5: audyt + poprawki modelem, korekta językowa, konwersja do HTML.
 */
function articleFinish(array $ctx, string $markdown, ?callable $progress = null, ?callable $log = null): array {
    $log = $log ?? fn($m) => null;
    $lang = $ctx['lang'];
    $model = $ctx['model'];
    $system = getArticleSystemPrompt($lang);
    $markdown = trim($markdown);
    if ($markdown === '') throw new ClaudeApiException('Claude API nie zwróciło treści artykułu.', true);

    // 4. Audyt + poprawki
    $markdown = autoFixArticle($markdown, $lang);
    $checks = validateArticle($markdown, $lang, $ctx['main_kw'], $ctx['notes']);
    for ($round = 1; $round <= 2 && articleFailures($checks); $round++) {
        $issues = implode("\n", array_map(fn($c) => "- {$c['name']}: {$c['message']}", articleFailures($checks)));
        $log("Poprawka po audycie (runda $round)");
        $fix = claudeRequest([
            'model' => $model,
            'system' => $system,   // ten sam system prompt = odczyt z cache
            'messages' => [['role' => 'user', 'content' =>
                "Poniższy artykuł nie przeszedł audytu zgodności. Usterki:\n{$issues}\n\n"
                . "Popraw WYŁĄCZNIE te usterki, zachowując treść, tytuł H1, styl i pozostałe zasady. "
                . "Jeśli długość jest poza zakresem, rozbuduj lub skróć sekcje merytorycznie (bez lania wody). "
                . "Zwróć cały poprawiony artykuł w Markdown, bez komentarzy.\n\n---\n\n" . $markdown]],
            'effort' => claudeMainEffort(),
            'max_tokens' => 32000,
            'on_progress' => $progress,
        ]);
        articleTrackUsage($ctx, $fix);
        if (trim($fix['text']) !== '') $markdown = autoFixArticle(trim($fix['text']), $lang);
        $checks = validateArticle($markdown, $lang, $ctx['main_kw'], $ctx['notes']);
    }

    // 5. Korekta językowa (po niej znów deterministyczne poprawki — korektor potrafi wstawić myślnik)
    try {
        $log('Korekta językowa');
        $proof = proofreadMarkdown($markdown, $lang, $progress);
        articleTrackUsage($ctx, $proof);
        if (trim($proof['text']) !== '') {
            $candidate = autoFixArticle(trim($proof['text']), $lang);
            $candidateChecks = validateArticle($candidate, $lang, $ctx['main_kw'], $ctx['notes']);
            // Korekta nie może pogorszyć audytu (np. obciąć tekstu)
            if (count(articleFailures($candidateChecks)) <= count(articleFailures($checks))) {
                $markdown = $candidate;
                $checks = $candidateChecks;
            }
        }
    } catch (Throwable $e) {
        $ctx['pipeline_notes'][] = 'Korekta pominięta: ' . $e->getMessage();
    }

    $body = preg_replace('/^#\s+.+\n*/m', '', $markdown, 1);
    return [
        'markdown'   => $body,
        'html'       => sanitizeArticleHtml(markdownToHtml($body)),
        'char_count' => mb_strlen(articlePlainText($markdown), 'UTF-8'),
        'checks'     => $checks,
        'research'   => $ctx['research'],
        'usage'      => $ctx['usage'],
        'cost_usd'   => articleCostUsd($ctx['usage']),
        'model'      => $model,
        'notes'      => $ctx['pipeline_notes'],
    ];
}

/**
 * Research: Senuto + SERP. Zwraca {supporting[], questions[], groups[], serp{}, sources[]}.
 */
function articleResearch(string $title, string $mainKw, string $secondary, string $lang, callable $track, array &$notes, callable $log): array {
    $out = ['supporting' => [], 'questions' => [], 'groups' => [], 'serp' => [], 'sources' => []];
    if ($mainKw === '') return $out;

    if ($lang === 'pl' && senutoConfigured()) {
        try {
            $log('Senuto: frazy, pytania i grupy dla „' . $mainKw . '”');
            $sen = senutoResearch($title, $mainKw, $secondary !== '', $track);
            $out = array_merge($out, $sen);
            $out['sources'][] = 'senuto';
        } catch (Throwable $e) {
            $notes[] = 'Senuto pominięte: ' . $e->getMessage();
        }
    }

    if (claudeSetting('serp_enabled') !== '0') {
        try {
            $log('Analiza SERP top wyników Google');
            $out['serp'] = serpAnalysis($mainKw, $title, $lang, $track);
            if ($out['serp']) $out['sources'][] = 'serp';
        } catch (Throwable $e) {
            $notes[] = 'Analiza SERP pominięta: ' . $e->getMessage();
        }
    }
    return $out;
}

/**
 * Senuto -> kandydaci -> filtr trafności (port z Ateny: BlogPostV3Skill::researchPhase + BaseSkill::filterRelevant).
 * Gdy content plan ma już frazy poboczne ($supportingApproved), Senuto dokłada tylko pytania i grupy.
 */
function senutoResearch(string $topic, string $mainKw, bool $supportingApproved, ?callable $track = null): array {
    $keywords = senutoGetKeywords($mainKw, 'pl', 60);
    $questions = senutoGetQuestions($mainKw, 'pl', 20);
    $groups = senutoGetGroups($mainKw, 'pl', 12);

    usort($keywords, fn($a, $b) => ($b['searches'] ?? 0) <=> ($a['searches'] ?? 0));
    $mainLower = mb_strtolower($mainKw, 'UTF-8');
    $candidates = [];
    foreach ($keywords as $k) {
        $kw = trim((string)($k['keyword'] ?? ''));
        if ($kw !== '' && mb_strtolower($kw, 'UTF-8') !== $mainLower) $candidates[] = $kw;
    }
    $candidates = array_slice(array_values(array_unique($candidates)), 0, 20);
    $qList = array_slice(array_values(array_unique(array_filter(array_map(fn($q) => trim((string)($q['keyword'] ?? '')), $questions)))), 0, 12);
    $gList = senutoGroupNames($groups, 12);

    $filtered = filterRelevantKeywords($topic, $mainKw, $supportingApproved ? [] : $candidates, $qList, $gList, $track);
    return [
        'supporting' => $supportingApproved ? [] : array_slice($filtered['supporting'], 0, 8),
        'questions'  => array_slice($filtered['questions'], 0, 6),
        'groups'     => array_slice($filtered['groups'], 0, 8),
    ];
}

/** Format odpowiedzi getGroups bywa różny — pierwsze sensowne pole tekstowe (jak w Atenie). */
function senutoGroupNames(array $rows, int $limit): array {
    $names = [];
    foreach ($rows as $row) {
        if (is_string($row)) { $names[] = $row; continue; }
        if (!is_array($row)) continue;
        foreach (['group_name', 'name', 'group', 'title', 'keyword', 'main_keyword'] as $f) {
            if (isset($row[$f]) && is_scalar($row[$f]) && trim((string)$row[$f]) !== '') { $names[] = trim((string)$row[$f]); break; }
        }
    }
    return array_slice(array_values(array_unique($names)), 0, $limit);
}

/**
 * Tani model wybiera z list Senuto tylko pozycje trafne dla tematu. Może wyłącznie kopiować
 * pozycje z wejścia — wynik jest przecinany z wejściem, więc nic nie dopisze.
 */
function filterRelevantKeywords(string $topic, string $mainKw, array $supporting, array $questions, array $groups, ?callable $track = null): array {
    if (!$supporting && !$questions && !$groups) return ['supporting' => [], 'questions' => [], 'groups' => []];

    $lists = '';
    foreach (['supporting' => [$supporting, 'Frazy wspierające'], 'questions' => [$questions, 'Pytania'], 'groups' => [$groups, 'Klastry tematyczne']] as $k => [$items, $label]) {
        $lists .= "\n## {$label} (`{$k}`)\n" . ($items ? '- ' . implode("\n- ", $items) : '(brak)') . "\n";
    }
    $user = "Temat tekstu: „{$topic}”\nGłówne słowo kluczowe: „{$mainKw}”\n\n"
        . "Z poniższych list (dane z narzędzia SEO) wybierz WYŁĄCZNIE pozycje, które dotyczą dokładnie tego samego tematu i pasują do tekstu na ten temat. "
        . "Odrzuć pozycje o innym obiekcie (np. inna dyscyplina, inny produkt, inna usługa), zbyt ogólne i nawigacyjne (nazwy sklepów, marek niezwiązanych z tematem) "
        . "oraz o innej intencji niż tekst poradnikowy (wzór/druk/pdf do pobrania, generator, kalkulator online, opinie o konkretnej firmie), chyba że temat dotyczy właśnie tego.\n"
        . "Kopiuj wybrane pozycje dosłownie, bez zmian. Pusta lista jest poprawną odpowiedzią.\n{$lists}";

    $schema = [
        'type' => 'object',
        'properties' => [
            'supporting' => ['type' => 'array', 'items' => ['type' => 'string']],
            'questions'  => ['type' => 'array', 'items' => ['type' => 'string']],
            'groups'     => ['type' => 'array', 'items' => ['type' => 'string']],
        ],
        'required' => ['supporting', 'questions', 'groups'],
        'additionalProperties' => false,
    ];
    try {
        $resp = claudeRequest([
            'model' => CLAUDE_AUX_MODEL,
            'messages' => [['role' => 'user', 'content' => $user]],
            'json_schema' => $schema,
            'effort' => 'low',
            'max_tokens' => 4000,
            'stream' => false,
            'timeout' => 120,
        ]);
        if ($track) $track($resp, 'filter');
        $data = claudeParseJson($resp['text']);
    } catch (Throwable $e) {
        $data = null;
    }
    if (!$data) return ['supporting' => $supporting, 'questions' => $questions, 'groups' => $groups];

    $pick = function (array $source, $chosen): array {
        $allowed = [];
        foreach ($source as $s) $allowed[mb_strtolower($s, 'UTF-8')] = $s;
        $res = [];
        foreach ((array)$chosen as $c) {
            $k = mb_strtolower(trim((string)$c), 'UTF-8');
            if (isset($allowed[$k])) $res[] = $allowed[$k];
        }
        return array_values(array_unique($res));
    };
    return [
        'supporting' => $pick($supporting, $data['supporting'] ?? []),
        'questions'  => $pick($questions, $data['questions'] ?? []),
        'groups'     => $pick($groups, $data['groups'] ?? []),
    ];
}

/**
 * Analiza SERP (FAZA 1B skilla): web_search + web_fetch top wyników, wynik w JSON.
 * Cache na 14 dni per fraza+język. Prompt jak w Atenie (BlogPostV3Skill::serpPhase).
 */
function serpAnalysis(string $mainKw, string $topic, string $lang, ?callable $track = null): array {
    $db = getDb();
    $key = md5(mb_strtolower($mainKw, 'UTF-8') . '|' . $lang);
    $stmt = $db->prepare('SELECT data FROM serp_cache WHERE cache_key = :k AND fetched_at > datetime("now", :age)');
    $stmt->bindValue(':k', $key, SQLITE3_TEXT);
    $stmt->bindValue(':age', '-' . SERP_CACHE_DAYS . ' days', SQLITE3_TEXT);
    $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    if ($row && is_array($cached = json_decode($row['data'], true))) return $cached;

    $langName = getLanguageName($lang);
    $system = "Jesteś analitykiem SEO. Badasz top wyniki Google dla frazy, pod którą powstanie artykuł blogowy.\n\n"
        . "Kroki:\n1. web_search: wyszukaj frazę w Google dla rynku języka „{$langName}”.\n"
        . "2. web_fetch: pobierz 3-5 najwyżej rankujących ARTYKUŁÓW (pomijaj sklepy, fora, agregatory, wideo). Czytaj pełną treść: strukturę H2, format, głębokość, dane.\n"
        . "3. Zwróć wynik jako JSON.\n\nPola JSON:\n"
        . "- `format_serp`: dominujący format top wyników, jedno z: poradnik, lista, definicja, qa, porównanie\n"
        . "- `depth`: 1-2 zdania o głębokości top wyników (typowa długość, poziom szczegółu, czy mają tabele/dane)\n"
        . "- `top_domains`: domeny przeanalizowanych wyników (bez https://)\n"
        . "- `sections_observed`: tematy sekcji H2 powtarzające się w top wynikach (krótko, 4-10 pozycji)\n"
        . "- `entities`: 8-12 encji semantycznych: krótkie terminy 1-3 słowa (pojęcia, parametry, normy, nazwy własne), które pojawiają się w kilku top wynikach. BEZ nawiasów i objaśnień.\n"
        . "- `facts`: 3-5 konkretnych, sprawdzonych w pobranych stronach faktów przydatnych czytelnikowi (normy, parametry, zakresy), zapisanych jako wiedza, NIE jako opis braków w innych artykułach. Bez cen konkretnych firm.\n\n"
        . "Zwracasz WYŁĄCZNIE JSON, bez komentarzy:\n"
        . '{"format_serp": "...", "depth": "...", "top_domains": [], "sections_observed": [], "entities": [], "facts": []}';

    $resp = claudeRequest([
        'model' => claudeMainModel(),
        'system' => $system,
        'messages' => [['role' => 'user', 'content' => "Fraza: \"{$mainKw}\"\nTemat artykułu: \"{$topic}\"\nJęzyk: {$langName}"]],
        'tools' => [
            ['type' => 'web_search_20260209', 'name' => 'web_search', 'max_uses' => 3],
            ['type' => 'web_fetch_20260209', 'name' => 'web_fetch', 'max_uses' => 5, 'max_content_tokens' => 8000],
        ],
        'effort' => 'low',
        'max_tokens' => 16000,
        'timeout' => 300,
    ]);
    if ($track) $track($resp, 'serp');

    $data = claudeParseJson($resp['text']) ?? [];
    $list = fn($v, $n) => array_slice(array_values(array_filter(array_map(fn($x) => trim((string)$x), (array)$v))), 0, $n);
    $format = mb_strtolower(trim((string)($data['format_serp'] ?? '')), 'UTF-8');
    $serp = [
        'format_serp'       => in_array($format, ['poradnik', 'lista', 'definicja', 'qa', 'porównanie'], true) ? $format : '',
        'depth'             => trim((string)($data['depth'] ?? '')),
        'top_domains'       => $list($data['top_domains'] ?? [], 8),
        'sections_observed' => $list($data['sections_observed'] ?? [], 10),
        'entities'          => array_map(fn($e) => trim(preg_replace('/\s*\(.*?\)\s*/u', ' ', $e)), $list($data['entities'] ?? [], 12)),
        'facts'             => $list($data['facts'] ?? [], 5),
    ];
    if ($serp['format_serp'] !== '' || $serp['entities']) {
        $stmt = $db->prepare('INSERT OR REPLACE INTO serp_cache (cache_key, keyword, data, fetched_at) VALUES (:k, :kw, :d, datetime("now"))');
        $stmt->bindValue(':k', $key, SQLITE3_TEXT);
        $stmt->bindValue(':kw', $mainKw, SQLITE3_TEXT);
        $stmt->bindValue(':d', json_encode($serp, JSON_UNESCAPED_UNICODE), SQLITE3_TEXT);
        $stmt->execute();
    }
    return $serp;
}

/**
 * Korekta językowa (ten sam prompt co w api/proofread-article.php).
 */
function proofreadMarkdown(string $markdown, string $lang, ?callable $progress = null): array {
    $langName = getLanguageName($lang);
    $system = "Jesteś profesjonalnym korektorem tekstów w języku {$langName}. "
        . "Twoim JEDYNYM zadaniem jest poprawienie błędów ortograficznych, literówek, "
        . "błędnych znaków diakrytycznych i drobnych błędów gramatycznych.\n\n"
        . "ZASADY:\n"
        . "- Poprawiaj WYŁĄCZNIE błędy językowe (ortografia, literówki, interpunkcja, fleksja)\n"
        . "- NIE zmieniaj treści merytorycznej, stylu, struktury ani formatowania Markdown\n"
        . "- NIE dodawaj ani nie usuwaj akapitów, nagłówków, list ani tabel\n"
        . "- NIE zmieniaj kolejności zdań ani nie przeformułowuj ich\n"
        . ($lang === 'pl' ? "- NIE wstawiaj pauz (—) ani półpauz (–)\n" : '')
        . "- NIE dodawaj komentarzy, wyjaśnień ani listy zmian\n"
        . "- Zwróć WYŁĄCZNIE poprawiony tekst w tym samym formacie Markdown\n"
        . "- Jeśli tekst nie zawiera błędów — zwróć go bez zmian";
    return claudeRequest([
        'model' => claudeMainModel(),
        'system' => $system,
        'messages' => [['role' => 'user', 'content' => "Popraw błędy ortograficzne i literówki w poniższym artykule. Zwróć TYLKO poprawiony tekst:\n\n" . $markdown]],
        'effort' => 'low',
        'max_tokens' => 32000,
        'on_progress' => $progress,
    ]);
}

/**
 * Szacunkowy koszt w USD z usage (cennik platform.claude.com/docs/en/about-claude/pricing, 2026-11).
 * cache_creation liczone po stawce 5-min.
 */
function articleCostUsd(array $usage): float {
    $prices = [   // [input, cache_write_5m, cache_read, output] USD / MTok
        'claude-sonnet-5-5' => [2.0, 2.5, 0.10, 10.0],
        'claude-opus-5-5'   => [4.0, 5.0, 0.20, 20.0],
        'claude-haiku-5-5'  => [0.10, 0.125, 0.01, 0.50],
    ];
    $total = 0.0;
    foreach ($usage as $model => $u) {
        $p = null;
        // "batch:<model>" = wynik z Message Batches API, 50% ceny standardowej
        $batch = str_starts_with((string)$model, 'batch:');
        $name = $batch ? substr((string)$model, 6) : (string)$model;
        foreach ($prices as $prefix => $pp) if (str_starts_with($name, $prefix)) $p = $pp;
        if (!$p) continue;
        if ($batch) $p = array_map(fn($v) => $v / 2, $p);
        $total += (($u['input_tokens'] ?? 0) * $p[0] + ($u['cache_creation_input_tokens'] ?? 0) * $p[1]
            + ($u['cache_read_input_tokens'] ?? 0) * $p[2] + ($u['output_tokens'] ?? 0) * $p[3]) / 1e6;
        $total += ($u['web_search_requests'] ?? 0) * 0.01;   // $10 / 1000 wyszukiwań
    }
    return round($total, 4);
}
