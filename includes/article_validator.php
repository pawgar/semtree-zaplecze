<?php
/**
 * Walidator artykułu — automatyczna część 21-punktowego audytu ze skilla
 * seo-blog-post-writer-v2 (FAZA 4). Działa na Markdownie przed konwersją do HTML.
 *
 * validateArticle() zwraca listę checków {name, status: PASS|WARN|FAIL, message}.
 * FAIL-e idą do poprawki przez model (article_pipeline.php), to co da się naprawić
 * deterministycznie (znaki zapytania, myślniki w PL) naprawia autoFixArticle().
 */

const ARTICLE_MIN_CHARS = 8500;
const ARTICLE_MAX_CHARS = 9500;

// Zakazane frazy PL (references/banned-ai-patterns.md skilla v2 + grep z audytu)
const BANNED_PATTERNS_PL = [
    'kluczow[aąeyi][a-ząćęłńóśźż]*', 'fundament[a-ząćęłńóśźż]*', 'praktyczne wskazówki', 'praktyczne porady',
    'odgrywa (kluczową|ważną|istotną) rolę', 'warto zauważyć', 'należy podkreślić', 'niezwykle istotnym elementem',
    'w dzisiejszym świecie', 'w dziedzinie', 'jeśli chodzi o', 'nie trzeba mówić', 'ostatecznie', 'ponadto',
    'co więcej', 'istotnie', 'jak widzieliśmy', 'podsumowując', 'w gruncie rzeczy', 'nie da się ukryć',
    'warto mieć na uwadze', 'z całą pewnością', 'można zaobserwować', 'zapewnia wysoką jakość',
    'skutecznie wspiera', 'pozwala osiągnąć cele', 'warto rozważyć', 'kompleksow[a-ząćęłńóśźż]*',
    'wszystko, co musisz wiedzieć', 'dlaczego warto',
];
const BANNED_HEADINGS_PL = ['^podsumowanie$', '^co warto wiedzieć', 'przewodnik po', 'kluczowe aspekty', 'kluczowe elementy', '^znaczenie '];

const QUESTION_STARTERS = [
    'pl' => ['jak', 'czy', 'ile', 'co', 'kiedy', 'dlaczego', 'gdzie', 'który', 'która', 'które', 'jaki', 'jaka', 'jakie', 'jakich', 'czym', 'kto', 'skąd'],
    'en' => ['how', 'what', 'why', 'when', 'where', 'which', 'who', 'is', 'are', 'can', 'do', 'does', 'should'],
    'de' => ['wie', 'was', 'warum', 'wann', 'wo', 'welche', 'welcher', 'welches', 'wer'],
    'nl' => ['hoe', 'wat', 'waarom', 'wanneer', 'waar', 'welke', 'wie'],
    'es' => ['cómo', 'qué', 'por qué', 'cuándo', 'dónde', 'cuál', 'cuáles', 'quién'],
    'cs' => ['jak', 'co', 'proč', 'kdy', 'kde', 'který', 'která', 'které', 'kolik'],
];

/**
 * Rozbija Markdown na tytuł, sekcje H2 i statystyki.
 */
function articleStructure(string $markdown): array {
    $lines = preg_split('/\r?\n/', $markdown);
    $sections = [];          // [{heading, h3: [], lists: int, tables: int}]
    $current = null;
    $intro = ['h3' => [], 'lists' => 0, 'tables' => 0];
    $headings = [];
    $inList = false;
    $inTable = false;
    foreach ($lines as $line) {
        $t = trim($line);
        if (preg_match('/^(#{1,6})\s+(.+)$/', $t, $m)) {
            $inList = $inTable = false;
            $level = strlen($m[1]);
            $text = trim(str_replace('**', '', $m[2]));
            if ($level === 1) continue;
            $headings[] = ['level' => $level, 'text' => $text];
            if ($level === 2) {
                if ($current) $sections[] = $current;
                $current = ['heading' => $text, 'h3' => [], 'lists' => 0, 'tables' => 0];
            } elseif ($level === 3) {
                if ($current) $current['h3'][] = $text; else $intro['h3'][] = $text;
            }
            continue;
        }
        $isList = (bool)preg_match('/^([-*]|\d+[.)])\s+/', $t);
        $isTable = str_starts_with($t, '|') && str_ends_with($t, '|');
        if ($isList && !$inList) { if ($current) $current['lists']++; else $intro['lists']++; }
        if ($isTable && !$inTable) { if ($current) $current['tables']++; else $intro['tables']++; }
        if ($t !== '') { $inList = $isList; $inTable = $isTable; }
    }
    if ($current) $sections[] = $current;
    return ['sections' => $sections, 'intro' => $intro, 'headings' => $headings];
}

/**
 * Czysty tekst artykułu bez tytułu i składni Markdown (do liczenia znaków).
 */
function articlePlainText(string $markdown): string {
    $md = preg_replace('/^#\s+.+$/m', '', $markdown, 1);
    $md = preg_replace('/\[([^\]]+)\]\([^)]+\)/', '$1', $md);
    $md = preg_replace('/^\|[\s\-:|]+\|$/m', '', $md);          // separator tabeli
    $md = preg_replace('/^#{1,6}\s+/m', '', $md);
    $md = preg_replace('/^([-*]|\d+[.)])\s+/m', '', $md);
    $md = str_replace(['**', '|'], ['', ' '], $md);
    $md = preg_replace('/[ \t]+/', ' ', $md);
    $md = preg_replace('/\n{2,}/', "\n", $md);
    return trim($md);
}

function validateArticle(string $markdown, string $lang = 'pl', string $mainKeyword = '', string $notes = ''): array {
    $checks = [];
    $add = function (string $name, string $status, string $message) use (&$checks) {
        $checks[] = ['name' => $name, 'status' => $status, 'message' => $message];
    };

    $plain = articlePlainText($markdown);
    $chars = mb_strlen($plain, 'UTF-8');
    $st = articleStructure($markdown);
    $sections = $st['sections'];
    $h2 = count($sections);
    $h3 = count($st['intro']['h3']) + array_sum(array_map(fn($s) => count($s['h3']), $sections));
    $lists = $st['intro']['lists'] + array_sum(array_column($sections, 'lists'));
    $low = mb_strtolower($plain, 'UTF-8');

    // 1. Długość
    if ($chars >= ARTICLE_MIN_CHARS && $chars <= ARTICLE_MAX_CHARS) $add('Długość', 'PASS', "$chars znaków");
    else {
        $status = ($chars < ARTICLE_MIN_CHARS - 500 || $chars > ARTICLE_MAX_CHARS + 500) ? 'FAIL' : 'WARN';
        $add('Długość', $status, "$chars znaków (wymagane " . ARTICLE_MIN_CHARS . '-' . ARTICLE_MAX_CHARS . ')');
    }
    // 2-4. Nagłówki
    $add('Sekcje H2', ($h2 >= 4 && $h2 <= 5) ? 'PASS' : 'FAIL', "$h2 sekcji H2 (wymagane 4-5)");
    $add('Nagłówki H3', $h3 <= 5 ? 'PASS' : 'FAIL', "$h3 nagłówków H3 (maks. 5)");
    $tooManyH3 = array_filter($sections, fn($s) => count($s['h3']) > 2);
    $add('H3 na sekcję', $tooManyH3 ? 'FAIL' : 'PASS',
        $tooManyH3 ? 'Ponad 2 H3 w: ' . implode('; ', array_column($tooManyH3, 'heading')) : 'maks. 2 H3 w każdej sekcji');
    // 5. Urozmaicenie
    $walls = array_filter($sections, fn($s) => !$s['h3'] && !$s['lists'] && !$s['tables']);
    $add('Urozmaicenie sekcji', $walls ? 'FAIL' : 'PASS',
        $walls ? 'Sekcje bez H3/listy/tabeli: ' . implode('; ', array_column($walls, 'heading')) : 'każda sekcja H2 ma H3, listę lub tabelę');
    // 14-15. Listy
    $add('Listy wypunktowane', ($lists >= 1 && $lists <= 3) ? 'PASS' : 'FAIL', "$lists list (wymagane 1-3)");
    $multiList = array_filter($sections, fn($s) => $s['lists'] > 1);
    $add('Lista na sekcję', $multiList ? 'FAIL' : 'PASS',
        $multiList ? 'Ponad 1 lista w: ' . implode('; ', array_column($multiList, 'heading')) : 'maks. 1 lista w sekcji');
    // 9. Zakazane nagłówki
    if ($lang === 'pl') {
        $badHeads = [];
        foreach ($st['headings'] as $h) {
            foreach (BANNED_HEADINGS_PL as $p) {
                if (preg_match('/' . $p . '/iu', $h['text'])) { $badHeads[] = $h['text']; break; }
            }
        }
        $add('Ogólne nagłówki', $badHeads ? 'FAIL' : 'PASS', $badHeads ? implode('; ', $badHeads) : 'brak');
    }
    // 17. Znak zapytania
    $missingQ = articleQuestionHeadingsWithoutMark($st['headings'], $lang);
    $add('Znak zapytania w nagłówkach', $missingQ ? 'FAIL' : 'PASS', $missingQ ? implode('; ', $missingQ) : 'OK');
    // 6. Zakazane frazy
    if ($lang === 'pl') {
        $found = [];
        foreach (BANNED_PATTERNS_PL as $p) {
            if (preg_match_all('/(?<![\p{L}])' . $p . '/iu', $low, $m)) $found = array_merge($found, $m[0]);
        }
        $found = array_values(array_unique($found));
        $add('Zakazane frazy AI', $found ? 'FAIL' : 'PASS', $found ? implode(', ', $found) : 'brak');
    }
    // 21. Myślniki (tylko PL)
    if ($lang === 'pl') {
        $dashes = preg_match_all('/[\x{2013}\x{2014}]/u', $markdown);
        $add('Pauzy i półpauzy', $dashes ? 'FAIL' : 'PASS', $dashes ? "$dashes wystąpień — i –" : 'brak');
    }
    // 7. "My" jako starter zdania
    if ($lang === 'pl') {
        $my = preg_match_all('/(?:^|[.!?]\s+)My\s/mu', $plain);
        $add('Zaimek "My" na początku zdań', $my > 2 ? 'FAIL' : 'PASS', "$my razy");
    }
    // 8. Wyliczenia sekwencyjne
    $seq = preg_match('/\b(po (pierwsze|drugie|trzecie)|firstly|secondly|erstens|zweitens|ten eerste|en primer lugar|za prvé)\b/iu', $low);
    $add('Wyliczenia "po pierwsze"', $seq ? 'FAIL' : 'PASS', $seq ? 'znaleziono' : 'brak');
    // 11. Długie akapity
    $longParas = 0;
    foreach (preg_split('/\n\s*\n/', $markdown) as $para) {
        $para = trim($para);
        if ($para === '' || preg_match('/^(#|[-*]\s|\d+[.)]\s|\|)/', $para)) continue;
        if (preg_match_all('/[.!?](\s|$)/u', $para) > 5) $longParas++;
    }
    $add('Długość akapitów', $longParas ? 'WARN' : 'PASS', $longParas ? "$longParas akapitów ponad 5 zdań" : 'OK');
    // 12. Fraza w pierwszych 100 znakach (WARN — odmiana fleksyjna bywa naturalniejsza)
    if ($mainKeyword !== '') {
        $intro = mb_strtolower(mb_substr($plain, 0, 100, 'UTF-8'), 'UTF-8');
        $stem = mb_substr(mb_strtolower(trim($mainKeyword), 'UTF-8'), 0, max(4, (int)(mb_strlen($mainKeyword, 'UTF-8') * 0.7)), 'UTF-8');
        $add('Fraza we wstępie', str_contains($intro, $stem) ? 'PASS' : 'WARN', str_contains($intro, $stem) ? 'OK' : 'brak głównej frazy w pierwszych 100 znakach');
    }
    // Linki: tylko ten podany w notatkach
    preg_match_all('/\[[^\]]+\]\((https?:\/\/[^)\s]+)\)/', $markdown, $lm);
    $allowed = array_filter($lm[1], fn($u) => $notes !== '' && str_contains($notes, rtrim($u, '/')));
    $foreign = count($lm[1]) - count($allowed);
    $add('Linki', $foreign ? 'FAIL' : 'PASS', $foreign ? "$foreign linków spoza wskazówek" : count($lm[1]) . ' link(i) z wskazówek');
    // Interpunkcja
    $excl = substr_count($plain, '!');
    $dots = substr_count($plain, '...') + substr_count($plain, '…');
    $add('Wykrzykniki i wielokropki', ($excl <= 1 && !$dots) ? 'PASS' : 'WARN', "$excl wykrzykników, $dots wielokropków");

    return $checks;
}

function articleQuestionHeadingsWithoutMark(array $headings, string $lang): array {
    $starters = QUESTION_STARTERS[$lang] ?? QUESTION_STARTERS['pl'];
    $out = [];
    foreach ($headings as $h) {
        $first = mb_strtolower(preg_split('/[\s,:]+/u', $h['text'])[0] ?? '', 'UTF-8');
        if (in_array($first, $starters, true) && !str_ends_with(rtrim($h['text']), '?')) $out[] = $h['text'];
    }
    return $out;
}

function articleFailures(array $checks): array {
    return array_values(array_filter($checks, fn($c) => $c['status'] === 'FAIL'));
}

/**
 * Deterministyczne poprawki tego, czego model nie musi robić sam:
 * znak "?" w nagłówkach pytających i (PL) myślniki zastąpione przecinkiem / dywizem.
 */
function autoFixArticle(string $markdown, string $lang = 'pl'): string {
    $starters = QUESTION_STARTERS[$lang] ?? QUESTION_STARTERS['pl'];
    $markdown = preg_replace_callback('/^(#{2,3})\s+(.+?)\s*$/mu', function ($m) use ($starters) {
        $text = rtrim($m[2], " .:;");
        $first = mb_strtolower(preg_split('/[\s,:]+/u', $text)[0] ?? '', 'UTF-8');
        if (in_array($first, $starters, true) && !str_ends_with($text, '?')) $text .= '?';
        return $m[1] . ' ' . $text;
    }, $markdown);

    if ($lang === 'pl') {
        $markdown = preg_replace('/(\d)\s*[\x{2013}\x{2014}]\s*(\d)/u', '$1-$2', $markdown);   // zakresy liczb
        $markdown = preg_replace('/^(\s*[-*]\s+)[\x{2013}\x{2014}]\s*/mu', '$1', $markdown);   // myślnik na początku punktu
        $markdown = preg_replace('/\s*[\x{2013}\x{2014}]\s*/u', ', ', $markdown);
        $markdown = preg_replace('/,\s*([,.;:?!)])/u', '$1', $markdown);
        $markdown = preg_replace('/^(#{1,6}\s+|[-*]\s+|\|\s*),\s*/mu', '$1', $markdown);
    }
    return $markdown;
}
