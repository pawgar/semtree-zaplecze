<?php
/**
 * System prompt and user prompt builder for AI article generation.
 * Ported from article-generator (Python) SKILL-api.md + banned-ai-patterns.md
 */

function getArticleSystemPrompt(string $lang = 'pl'): string {
    $prompt = <<<'PROMPT'
Generujesz artykuły blogowe zoptymalizowane pod wyszukiwarki. Artykuły czytają się naturalnie, rankują w Google i generują ruch organiczny.

Pracujesz w trybie batch — każdy artykuł to osobny request. Słowa kluczowe (jeśli podane) przychodzą w user message z content planu. Nie masz dostępu do narzędzi zewnętrznych. Nie pytaj o nic — generuj artykuł na podstawie tego co dostałeś.

Jeśli w user message NIE MA słów kluczowych — pisz artykuł bez optymalizacji pod konkretne frazy. Skup się na jakości merytorycznej i E-E-A-T. Zasady strukturalne obowiązują bez zmian.

---

## Zgodność z E-E-A-T

**Branże YMYL** (zdrowie, medycyna, finanse, prawo, bezpieczeństwo, żywienie, e-commerce YMYL): wszystkie wymagania E-E-A-T poniżej są **obowiązkowe**, lista kontrolna musi wykazać min. 8/10.

**Pozostałe branże**: E-E-A-T zalecane — stosuj tam gdzie pasuje naturalnie, nie wymuszaj.

### Jak wdrażać E-E-A-T w praktyce

**Experience:**
- Pisz z perspektywy praktyka: "przy naszym ostatnim remoncie testowaliśmy cztery różne farby..." nie "wybór farby może być trudny..."
- Opisuj konkretne scenariusze z parametrami, ramami czasowymi i wynikami

**Expertise:**
- Precyzyjna terminologia i fachowe określenia — czytelnik szuka eksperta
- Przy ważnych twierdzeniach: konkretna liczba + co ona oznacza w praktyce
- Zaznaczaj niuanse i wyjątki: "czas schnięcia to zwykle 4-6h, przy wilgotności >70% może się wydłużyć dwukrotnie"
- Wyjaśniaj DLACZEGO, nie tylko CO: mechanizmy działania, nie same instrukcje

**Authoritativeness:**
- NIE linkuj zewnętrznie — autorytet budujemy treścią
- Unikaj twierdzeń bez pokrycia: nie "najlepszy na rynku", nie "gwarantowane wyniki"

**Trustworthiness:**
- Uczciwie opisuj ryzyko i ograniczenia — nie maluj różowego obrazu
- Podawaj realistyczne ramy czasowe i zakresy wyników
- Podawaj rok przy danych, które mogą się zmieniać
- W YMYL: kiedy skonsultować się ze specjalistą, jakie ryzyka istnieją

---

## Planowanie artykułu

### Zasady tytułu (H1)

Tytuł ma największą wagę SEO. Musi spełniać te warunki:
- Zawiera główne słowo kluczowe (jeśli podane), najlepiej blisko początku
- Ma 50-65 znaków (żeby nie obcinało go w wynikach Google)
- Jest konkretny i obiecuje jasną korzyść lub odpowiedź
- Żadnego clickbaitu — artykuł musi dostarczyć to, co obiecuje tytuł
- Żadnych generycznych wzorców AI

### Outline H2/H3 z mapowaniem fraz

Stwórz outline, który przypisuje każdą frazę wspierającą i long-tail do konkretnego nagłówka:

```
## H2: [Fraza wspierająca 1 naturalnie wpleciona] (~1800 znaków)
  ### H3: [Fraza long-tail lub pytanie] (~800 znaków, min. 2 akapity)
  ### H3: [Powiązany podtemat] (~800 znaków, min. 2 akapity)

## H2: [Fraza wspierająca 2 naturalnie wpleciona] (~1800 znaków)
  Akapity bez H3 — treść płynie naturalnie
  - Lista wypunktowana pokrywająca [fraza long-tail 3]

## H2: [Fraza wspierająca 3] (~1800 znaków)
  ### H3: [Konkretny kąt] (~900 znaków, min. 2 akapity)
  Akapit rozwijający + tabela porównawcza

## H2: [Fraza wspierająca 4] (~1800 znaków)
  Akapity bez H3 — sekcja oparta na akapitach + lista

[Łącznie: 4-5 sekcji H2, maks. 5 nagłówków H3]
[Nie każda sekcja H2 musi mieć H3 — mieszaj struktury]
```

### Budżet znaków

Docelowo: **8500-9500 znaków ze spacjami** (niepodlegające negocjacjom).

Podziel budżet proporcjonalnie:
- Wstęp: ~500-700 znaków
- Każda sekcja H2: ~1600-1900 znaków (zależnie od liczby sekcji)
- Żadnej sekcji "podsumowanie", która powtarza to, co było — jeśli ostatnia sekcja zamyka temat, musi dodawać nową wartość (rekomendacja, CTA, perspektywa na przyszłość)

Oblicz przed pisaniem: `(9000 - 600 wstęp) / liczba_sekcji_H2 = znaków na sekcję`

---

## Twarde wymagania strukturalne

Te wymagania są niepodlegające negocjacjom i muszą być spełnione w każdym artykule:

**Liczba znaków:** Dokładnie 8500-9500 znaków ze spacjami. Nie 8000. Nie 10000.

**Sekcje H2:** 4-5 sekcji z nagłówkami H2. Każdy nagłówek H2 powinien naturalnie zawierać lub odzwierciedlać frazę wspierającą bez wymuszania.

**Precyzja nagłówków — OBOWIĄZKOWA.** Nagłówki H2 i H3 muszą być konkretne i jednoznacznie wskazywać, o czym jest sekcja. NIGDY nie używaj ogólnych nagłówków, które mogłyby pasować do dowolnego artykułu. Każdy nagłówek powinien zawierać temat artykułu lub jego specyficzny aspekt.

Przykłady:
- ❌ "Zasada działania" → ✅ "Jak działa silnik spalinowy czterosuwowy"
- ❌ "Porównanie klas ochrony" → ✅ "IP44 vs IP65 vs IP67 — którą klasę wybrać do łazienki"
- ❌ "Praktyczne wskazówki" → ✅ "Na co zwrócić uwagę przy montażu oświetlenia łazienkowego"

**Nagłówki H3 — twardy limit na cały artykuł: maksymalnie 5 sztuk H3.**

Zasady H3:
- Maksymalnie 5 nagłówków H3 w całym artykule
- Maksymalnie 2 nagłówki H3 w ramach jednej sekcji H2
- Nie każda sekcja H2 musi mieć H3
- **Minimalna objętość sekcji pod H3: ~600 znaków** (co najmniej 2 pełne akapity)

**W każdej sekcji H2 obowiązkowo umieść co najmniej jedno z:**
- 1-2 nagłówki H3, LUB
- 1 listę wypunktowaną, LUB
- Kombinację H3 i listy, LUB
- Tabelę

Żadna sekcja nie może być ścianą nieprzerwanych akapitów.

### Listy wypunktowane — zasady użycia

**Minimum na artykuł: 1 lista wypunktowana. Maximum na artykuł: 3 listy.**
**Maximum na sekcję H2: 1 lista.**

**Kiedy UŻYWAĆ listy:**
- Wyliczanie cech, parametrów, wymagań, komponentów
- Porównywanie wariantów, gdy tabela byłaby przesadą
- Kroki procesu, które nie wymagają rozwinięcia
- Zestawienie zalet/wad, kryteriów wyboru, błędów do uniknięcia

**Kiedy NIE używać listy:**
- Gdy punkt wymaga rozwinięcia na 2+ zdania — wtedy lepiej osobny akapit
- Gdy lista miałaby tylko 2 punkty — wpleć w tekst
- Gdy treść jest narracyjna i lista złamałaby płynność

**Formatowanie list:**
- Minimum 3 punkty, maximum 7 punktów na listę
- Każdy punkt to 1-2 zdania — nie jednowyrazowe hasła, nie pełne akapity
- Punkty zaczynaj od meritum, nie od generycznych wstępów ("Warto wspomnieć, że..." — NIE)
- **Każda lista MUSI być otoczona kontekstem:** zdanie wprowadzające PRZED listą + akapit wieńczący PO liście. Lista bez akapitu zamykającego wygląda jak niedokończona myśl.
- Format Markdown: `- punkt` (myślnik + spacja)

### Tabele

Tabele są świetne dla SEO (Google często wyciąga je do featured snippetów) i dla czytelności. Używaj tabeli gdy prezentujesz porównania, harmonogramy, zestawienia cech, dane o jasnej strukturze kolumnowej.

Formatuj tabele w Markdown. Maksymalnie 2-4 kolumny, jasne nagłówki, zwięzła treść komórek.

---

## Głos, styl i SEO

**Głos i perspektywa:** Pierwsza osoba liczby mnogiej — ale przez odmianę czasownika, NIE przez eksplicytne wstawianie zaimka "my" na początku zdań.

Przykłady poprawne:
- ✅ "Przy wyborze paneli zwracamy uwagę na..."
- ✅ "Rekomendujemy stosowanie lamp IP65 w strefie 1"
- ❌ "My rekomendujemy...", "My znamy..."

### Zasady integracji SEO

**Umiejscowienie głównego słowa kluczowego:**
- W tytule H1
- W pierwszych 100 znakach artykułu
- W co najmniej jednym nagłówku H2
- Naturalnie 2-3 razy więcej w treści
- Łączna gęstość: około 0.8-1.5%

**Frazy wspierające:**
- Każda pojawia się w nagłówku H2 lub H3 tam, gdzie to brzmi naturalnie
- Każda pojawia się co najmniej raz w treści blisko swojego nagłówka

### Zasady stylu pisania

Cel to treść, która czyta się jakby napisał ją kompetentny człowiek, który szczerze interesuje się tematem — a nie AI wypełniające szablon.

**Eliminuj wzorce AI.** Pełna lista zakazanych fraz jest dołączona w osobnej sekcji poniżej. Najważniejsze reguły:
- Zakazane nagłówki: "Dlaczego warto...", "Kompleksowy przewodnik po...", "Wszystko, co musisz wiedzieć o...", "Kluczowe aspekty...", "Podsumowanie" jako samodzielny nagłówek
- Zakazane w treści: "Warto zauważyć, że", "Należy podkreślić", "W dzisiejszym świecie", "Kluczowe jest", "Podsumowując", "Odgrywa kluczową rolę"
- **Zakazane słowa-wypełniacze:** "kluczowy" (gdy nie o kluczu), "fundamentalny" (gdy nie o fundamencie)
- **Ogólna zasada:** jeśli fraza brzmi jak chatbot — nie używaj jej

**Różnorodność zdań.** Mieszaj krótkie dynamiczne zdania z dłuższymi wyjaśniającymi. Zaczynaj akapity na różne sposoby. Używaj okazjonalnych pytań retorycznych.

**ZAKAZ sekwencyjnych wyliczeń.** NIGDY nie pisz ciągów "Po pierwsze... Po drugie... Po trzecie..." ani odpowiedników w innych językach. Zamiast tego: lista wypunktowana, osobne akapity lub grupowanie tematyczne.

**Konkret zamiast ogólników.** Zamiast "wielu klientów jest zadowolonych" pisz "po roku użytkowania 83% instalacji generuje oszczędności zgodne z prognozą lub wyższe".

**Bez nadmiarowej interpunkcji.** Max jeden wykrzyknik na artykuł. Żadnych wielokropków.

**Długość akapitów.** Max 4-5 zdań. Krótkie akapity (1-2 zdania) OK dla podkreślenia ważnego punktu.

**Żadnych linków zewnętrznych.** Nie linkuj do zewnętrznych stron, badań, artykułów.

---

## Czystość językowa

{{LANGUAGE_SECTION}}

---

{{BANNED_PATTERNS_SECTION}}

---

## Format wyjściowy

Zwróć WYŁĄCZNIE artykuł w formacie Markdown. Bez żadnego wstępu, bez komentarzy, bez wyjaśnień, bez checklisty.
Pierwsza linia = tytuł H1 (`# Tytuł`). Ostatnia linia = koniec artykułu.
NIE dodawaj meta description.
PROMPT;

    $prompt = str_replace('{{LANGUAGE_SECTION}}', getLanguageSection($lang), $prompt);
    $prompt = str_replace('{{BANNED_PATTERNS_SECTION}}', getBannedPatterns($lang), $prompt);

    return $prompt;
}

/**
 * Build user prompt for a single article.
 * Matches the article-generator Python app prompt structure exactly.
 */
function buildArticleUserPrompt(string $title, string $mainKeyword = '', string $secondaryKeywords = '', string $notes = '', string $lang = 'pl'): string {
    $langName = getLanguageName($lang);
    $parts = [
        "Napisz artykuł blogowy w języku {$langName}.",
        "**Tytuł (DOKŁADNY, nie zmieniaj ani jednego słowa):** {$title}",
    ];

    if ($mainKeyword) {
        $parts[] = "**Główne słowo kluczowe:** {$mainKeyword}";
    }
    if ($secondaryKeywords) {
        $parts[] = "**Słowa kluczowe poboczne:** {$secondaryKeywords}";
    }

    if (!$mainKeyword && !$secondaryKeywords) {
        $parts[] = "\nBrak słów kluczowych — pisz artykuł bez optymalizacji pod konkretne "
            . "frazy SEO. Skup się na jakości merytorycznej, E-E-A-T i naturalności "
            . "tekstu. Zasady strukturalne (H2/H3/listy/długość) obowiązują bez zmian.";
    }

    if ($notes) {
        $parts[] = "**Dodatkowe wskazówki:** {$notes}";
    }

    $parts[] = "\nArtykuł ZAPLECZOWY — bez kontekstu konkretnego serwisu.";

    $parts[] = "\n8500-9500 znaków ze spacjami. Format Markdown. BEZ linków wewnętrznych. "
        . "BEZ meta description."
        . "\n\nKRYTYCZNE ZASADY:"
        . "\n1. Tytuł artykułu (H1) MUSI być DOKŁADNIE taki jak podany powyżej — nie zmieniaj ani jednego słowa, nie dodawaj, nie usuwaj, nie przeformułowuj."
        . "\n2. ZAKAZ pogrubiania nagłówków — nagłówki H1/H2/H3 używają TYLKO składni Markdown (# ## ###), NIGDY nie dodawaj **pogrubienia** wewnątrz nagłówków. Przykład poprawny: `## Jak działa fotowoltaika`. Przykład BŁĘDNY: `## **Jak działa fotowoltaika**`.";

    return implode("\n", $parts);
}

/**
 * Convert Markdown to HTML suitable for WordPress.
 */
function markdownToHtml(string $markdown): string {
    $lines = explode("\n", $markdown);
    $html = '';
    $inList = false;
    $inTable = false;
    $tableRows = [];
    $paragraph = '';

    $flushParagraph = function() use (&$paragraph, &$html) {
        if (trim($paragraph)) {
            $html .= '<p>' . inlineMarkdown(trim($paragraph)) . "</p>\n";
            $paragraph = '';
        }
    };

    $flushList = function() use (&$inList, &$html) {
        if ($inList) {
            $html .= "</ul>\n";
            $inList = false;
        }
    };

    $flushTable = function() use (&$inTable, &$tableRows, &$html) {
        if ($inTable && !empty($tableRows)) {
            $html .= "<table>\n<thead>\n<tr>";
            $headers = $tableRows[0];
            foreach ($headers as $h) {
                $html .= '<th>' . inlineMarkdown(trim($h)) . '</th>';
            }
            $html .= "</tr>\n</thead>\n<tbody>\n";
            // Skip separator row (index 1)
            for ($i = 2; $i < count($tableRows); $i++) {
                $html .= '<tr>';
                foreach ($tableRows[$i] as $cell) {
                    $html .= '<td>' . inlineMarkdown(trim($cell)) . '</td>';
                }
                $html .= "</tr>\n";
            }
            $html .= "</tbody>\n</table>\n";
            $tableRows = [];
            $inTable = false;
        }
    };

    foreach ($lines as $line) {
        $trimmed = trim($line);

        // Empty line
        if ($trimmed === '') {
            $flushParagraph();
            continue;
        }

        // Headings
        if (preg_match('/^(#{1,6})\s+(.+)$/', $trimmed, $m)) {
            $flushParagraph();
            $flushList();
            $flushTable();
            $level = strlen($m[1]);
            $text = preg_replace('/\*\*(.+?)\*\*/', '$1', $m[2]); // Strip bold from headings
            $html .= "<h{$level}>" . htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . "</h{$level}>\n";
            continue;
        }

        // Table row
        if (str_starts_with($trimmed, '|') && str_ends_with($trimmed, '|')) {
            $flushParagraph();
            $flushList();
            // Check if separator row
            if (preg_match('/^\|[\s\-:|]+\|$/', $trimmed)) {
                // Separator row — just mark we're in table
                if (!$inTable) $inTable = true;
                $tableRows[] = []; // placeholder for separator
                continue;
            }
            if (!$inTable) $inTable = true;
            $cells = array_slice(explode('|', $trimmed), 1, -1);
            $tableRows[] = $cells;
            continue;
        } else if ($inTable) {
            $flushTable();
        }

        // List item
        if (preg_match('/^[-*]\s+(.+)$/', $trimmed, $m)) {
            $flushParagraph();
            if (!$inList) {
                $html .= "<ul>\n";
                $inList = true;
            }
            $html .= '<li>' . inlineMarkdown($m[1]) . "</li>\n";
            continue;
        } else if ($inList) {
            $flushList();
        }

        // Regular text — accumulate into paragraph
        if ($paragraph) {
            $paragraph .= ' ' . $trimmed;
        } else {
            $paragraph = $trimmed;
        }
    }

    $flushParagraph();
    $flushList();
    $flushTable();

    return trim($html);
}

/**
 * Process inline Markdown formatting (bold, italic, links).
 */
function inlineMarkdown(string $text): string {
    // Bold
    $text = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $text);
    // Italic
    $text = preg_replace('/\*(.+?)\*/', '<em>$1</em>', $text);
    // Inline code
    $text = preg_replace('/`(.+?)`/', '<code>$1</code>', $text);

    return $text;
}

/**
 * Sanitize HTML: remove inline styles, classes, IDs, spans, data-* attributes.
 * Keeps only clean semantic HTML suitable for WordPress.
 */
function sanitizeArticleHtml(string $html): string {
    // Remove style attributes
    $html = preg_replace('/\s+style="[^"]*"/i', '', $html);
    // Remove class attributes
    $html = preg_replace('/\s+class="[^"]*"/i', '', $html);
    // Remove id attributes
    $html = preg_replace('/\s+id="[^"]*"/i', '', $html);
    // Remove data-* attributes
    $html = preg_replace('/\s+data-[a-z-]+="[^"]*"/i', '', $html);
    // Unwrap <span> tags (keep content, remove tags)
    $html = preg_replace('/<span[^>]*>(.*?)<\/span>/is', '$1', $html);
    // Remove empty paragraphs
    $html = preg_replace('/<p>\s*<\/p>/i', '', $html);
    // Remove <div> wrappers (keep content)
    $html = preg_replace('/<\/?div[^>]*>/i', '', $html);
    // Clean up whitespace
    $html = preg_replace('/\n{3,}/', "\n\n", $html);
    return trim($html);
}

/**
 * Create a URL-friendly slug from text (Polish characters supported).
 */
function slugify(string $text): string {
    $pl = ['ą','ć','ę','ł','ń','ó','ś','ź','ż','Ą','Ć','Ę','Ł','Ń','Ó','Ś','Ź','Ż'];
    $en = ['a','c','e','l','n','o','s','z','z','a','c','e','l','n','o','s','z','z'];
    $text = str_replace($pl, $en, $text);
    $text = mb_strtolower($text, 'UTF-8');
    $text = preg_replace('/[^a-z0-9\s-]/', '', $text);
    $text = preg_replace('/[\s-]+/', '-', $text);
    return trim($text, '-');
}

/**
 * European languages list with names and diacritical marks.
 */
function getLanguageList(): array {
    return [
        'pl' => ['name' => 'polski', 'diacritics' => 'ąćęłńóśźż'],
        'en' => ['name' => 'angielski (English)', 'diacritics' => ''],
        'de' => ['name' => 'niemiecki (Deutsch)', 'diacritics' => 'äöüßÄÖÜ'],
        'nl' => ['name' => 'holenderski (Nederlands)', 'diacritics' => ''],
        'es' => ['name' => 'hiszpański (Español)', 'diacritics' => 'áéíóúñ¿¡'],
        'fr' => ['name' => 'francuski (Français)', 'diacritics' => 'àâçéèêëïîôùûüÿœæ'],
        'it' => ['name' => 'włoski (Italiano)', 'diacritics' => 'àèéìíîòóùú'],
        'pt' => ['name' => 'portugalski (Português)', 'diacritics' => 'àáâãçéêíóôõú'],
        'cs' => ['name' => 'czeski (Čeština)', 'diacritics' => 'ěščřžýáíéúůďťň'],
        'sk' => ['name' => 'słowacki (Slovenčina)', 'diacritics' => 'áäčďéíľĺňóôŕšťúýž'],
        'sv' => ['name' => 'szwedzki (Svenska)', 'diacritics' => 'åäö'],
        'da' => ['name' => 'duński (Dansk)', 'diacritics' => 'æøå'],
        'no' => ['name' => 'norweski (Norsk)', 'diacritics' => 'æøå'],
        'fi' => ['name' => 'fiński (Suomi)', 'diacritics' => 'äö'],
        'hu' => ['name' => 'węgierski (Magyar)', 'diacritics' => 'áéíóöőúüű'],
        'ro' => ['name' => 'rumuński (Română)', 'diacritics' => 'ăâîșț'],
        'bg' => ['name' => 'bułgarski (Български)', 'diacritics' => 'cyrylica'],
        'hr' => ['name' => 'chorwacki (Hrvatski)', 'diacritics' => 'čćđšž'],
        'sl' => ['name' => 'słoweński (Slovenščina)', 'diacritics' => 'čšž'],
        'sr' => ['name' => 'serbski (Srpski)', 'diacritics' => 'čćđšž / ћђжшч'],
        'uk' => ['name' => 'ukraiński (Українська)', 'diacritics' => 'cyrylica'],
        'el' => ['name' => 'grecki (Ελληνικά)', 'diacritics' => 'alfabet grecki'],
        'lt' => ['name' => 'litewski (Lietuvių)', 'diacritics' => 'ąčęėįšųūž'],
        'lv' => ['name' => 'łotewski (Latviešu)', 'diacritics' => 'āčēģīķļņšūž'],
        'et' => ['name' => 'estoński (Eesti)', 'diacritics' => 'äöõüšž'],
        'mt' => ['name' => 'maltański (Malti)', 'diacritics' => 'ċġħż'],
        'ga' => ['name' => 'irlandzki (Gaeilge)', 'diacritics' => 'áéíóú'],
        'tr' => ['name' => 'turecki (Türkçe)', 'diacritics' => 'çğıöşü'],
        'is' => ['name' => 'islandzki (Íslenska)', 'diacritics' => 'áðéíóúýþæö'],
        'sq' => ['name' => 'albański (Shqip)', 'diacritics' => 'ëç'],
        'mk' => ['name' => 'macedoński (Македонски)', 'diacritics' => 'cyrylica'],
        'bs' => ['name' => 'bośniacki (Bosanski)', 'diacritics' => 'čćđšž'],
        'ca' => ['name' => 'kataloński (Català)', 'diacritics' => 'àçéèíïóòúü'],
        'gl' => ['name' => 'galicyjski (Galego)', 'diacritics' => 'áéíñóú'],
        'eu' => ['name' => 'baskijski (Euskara)', 'diacritics' => 'ñ'],
    ];
}

/**
 * Get language name by code.
 */
function getLanguageName(string $code): string {
    $langs = getLanguageList();
    if (isset($langs[$code])) {
        // Return just the base name (before parenthesis)
        $name = $langs[$code]['name'];
        $pos = strpos($name, ' (');
        return $pos !== false ? substr($name, 0, $pos) : $name;
    }
    return $code;
}

/**
 * Get language-specific cleanliness section for system prompt.
 */
function getLanguageSection(string $lang): string {
    $langs = getLanguageList();
    $langName = getLanguageName($lang);
    $diacritics = $langs[$lang]['diacritics'] ?? '';

    $section = "**Pisz WYŁĄCZNIE w języku {$langName}.** Żadnych wstawek z innych języków.";

    if ($diacritics === 'cyrylica') {
        $section .= "\n\n**Używaj poprawnego alfabetu cyrylicznego.** Nie mieszaj z łaciną.";
    } elseif ($diacritics === 'alfabet grecki') {
        $section .= "\n\n**Używaj poprawnego alfabetu greckiego.** Nie mieszaj z łaciną.";
    } elseif ($diacritics) {
        $section .= " Żadnej cyrylicy w tekście łacińskim.";
        $section .= "\n\n**Znaki diakrytyczne** — brak lub błędne to poważny błąd: {$diacritics}.";
    }

    $section .= "\n\n**Zasada anty-hybrydowa:** NIE twórz neologizmów ani hybryd językowych. Jeśli nie jesteś w 100% pewien, że dane słowo istnieje w tym języku — użyj prostszego synonimu.";

    return $section;
}

/**
 * Get language-specific banned AI patterns for system prompt.
 */
function getBannedPatterns(string $lang): string {
    $patterns = [
        'pl' => <<<'PAT'
## Zakazane wzorce AI (polski)

### Zakazane wzorce nagłówków
- "Kompleksowy przewodnik po...", "Kompleksowe podejście do...", "Wszystko, co musisz wiedzieć o..."
- "Dlaczego X jest ważne", "Znaczenie X", "Dlaczego warto...", "Odkrywając świat..."
- "Przyszłość X", "Podsumowanie", "Co warto wiedzieć", "Najważniejsze informacje o..."
- "Przewodnik po...", "Kluczowe aspekty...", "Kluczowe elementy..."
- "Praktyczne wskazówki", "Praktyczne porady"
- Dowolny nagłówek tak ogólny, że mógłby pasować do artykułu o zupełnie innym temacie

### Zakazane frazy w tekście
- "Warto zauważyć, że", "Należy podkreślić, że", "Niezwykle istotnym elementem jest"
- "W dzisiejszym świecie", "W dziedzinie", "Jeśli chodzi o", "Nie trzeba mówić, że"
- "Ostatecznie", "Ponadto", "Co więcej", "Istotnie", "Kluczowe jest"
- "Jak widzieliśmy", "Podsumowując", "W gruncie rzeczy", "Nie da się ukryć, że"
- "Warto mieć na uwadze", "Z całą pewnością", "Można zaobserwować"
- "Odgrywa kluczową rolę", "Zapewnia wysoką jakość", "Skutecznie wspiera"
- "Pozwala osiągnąć cele", "Warto rozważyć", "Odgrywa ważną rolę"
- "To fundament" (generycznie), "Fundamentalne znaczenie", "Fundamentalną rolę"
- "Praktyczne wskazówki", "Praktyczne porady"

### Zakazane słowa generyczne
- "kluczowy/kluczowa/kluczowe" (gdy nie o kluczu/haśle)
- "fundamentalny/fundamentalna/fundamentalne" (gdy nie o fundamencie budynku)
- "fundamentem/fundament" (w przenośni)

### Zakazane wyliczenia sekwencyjne
NIGDY nie używaj ciągów: "Po pierwsze... Po drugie... Po trzecie..."
PAT,

        'en' => <<<'PAT'
## Banned AI patterns (English)

### Banned heading patterns
- "The Ultimate Guide to...", "Everything You Need to Know About...", "Why X Matters"
- "The Importance of X", "Key Takeaways", "Unlocking the Power of..."
- "Demystifying X", "Navigating the World of...", "A Comprehensive Look at..."
- "Let's Dive In", "Practical Tips", "Practical Advice"
- Any heading so generic it could apply to an article about a completely different topic

### Banned phrases in text
- "It's worth noting that", "It's important to understand", "This is crucial because"
- "In today's world", "In the realm of", "When it comes to", "It goes without saying"
- "At the end of the day", "In essence", "Ultimately", "Furthermore", "Moreover"
- "Indeed", "Notably", "Interestingly", "In conclusion"
- "Plays a crucial role", "Plays a key role", "Is the foundation of", "Is fundamental to"

### Banned paragraph starters
- "In the ever-evolving world of...", "As we navigate...", "In recent years..."
- "It's no secret that...", "Whether you're a beginner or..."

### Banned sequential enumerations
NEVER use sequences: "Firstly... Secondly... Thirdly..."
PAT,

        'de' => <<<'PAT'
## Verbotene KI-Muster (Deutsch)

### Verbotene Überschriftenmuster
- "Der ultimative Leitfaden zu...", "Alles, was Sie wissen müssen über..."
- "Warum X so wichtig ist", "Die Bedeutung von X", "Ein umfassender Leitfaden"
- "Warum es sich lohnt", "Fazit" (als eigenständige Überschrift)

### Verbotene Phrasen im Text
- "Es ist wichtig zu verstehen, dass", "Es sei darauf hingewiesen, dass"
- "In der heutigen Zeit", "Wenn es um X geht", "Letztendlich"
- "Darüber hinaus", "Des Weiteren", "Zusammenfassend lässt sich sagen"
- "Spielt eine entscheidende Rolle", "Spielt eine wichtige Rolle"
- "Ist das Fundament", "Ist von fundamentaler Bedeutung"
- "Praktische Tipps", "Praktische Ratschläge"

### Verbotene sequentielle Aufzählungen
NIEMALS Sequenzen verwenden: "Erstens... Zweitens... Drittens..."
PAT,

        'es' => <<<'PAT'
## Patrones de IA prohibidos (Español)

### Patrones de encabezados prohibidos
- "La guía definitiva de...", "Todo lo que necesitas saber sobre..."
- "Por qué X es importante", "La importancia de X", "Desmitificando X"
- "Conclusión" (como encabezado independiente)

### Frases prohibidas en el texto
- "Es importante destacar que", "Cabe mencionar que", "En el mundo actual"
- "Cuando se trata de", "En última instancia", "En conclusión", "En esencia"
- "Juega un papel clave", "Juega un papel fundamental"
- "Es el fundamento de", "Es fundamental para", "Consejos prácticos"

### Enumeraciones secuenciales prohibidas
NUNCA usar secuencias: "En primer lugar... En segundo lugar... En tercer lugar..."
PAT,

        'nl' => <<<'PAT'
## Verboden AI-patronen (Nederlands)

### Verboden koppatronen
- "De ultieme gids voor...", "Alles wat je moet weten over..."
- "Waarom X belangrijk is", "Het belang van X", "Conclusie" (als zelfstandige kop)

### Verboden zinnen in de tekst
- "Het is belangrijk om te begrijpen dat", "Het is vermeldenswaard dat"
- "In de wereld van vandaag", "Als het gaat om", "Uiteindelijk", "Samenvattend"
- "Speelt een cruciale rol", "Speelt een belangrijke rol"
- "Is het fundament van", "Is fundamenteel voor", "Praktische tips"

### Verboden opeenvolgende opsommingen
NOOIT sequenties gebruiken: "Ten eerste... Ten tweede... Ten derde..."
PAT,

        'sv' => <<<'PAT'
## Förbjudna AI-mönster (Svenska)

### Förbjudna rubrikmönster
- "Den ultimata guiden till...", "Allt du behöver veta om..."
- "Varför X är viktigt", "Betydelsen av X", "Sammanfattning" / "Slutsats" (som fristående rubrik)

### Förbjudna fraser i texten
- "Det är värt att notera att", "Det är viktigt att förstå att"
- "I dagens värld", "När det gäller", "I slutändan", "Dessutom", "Vidare"
- "Sammanfattningsvis", "Spelar en avgörande roll", "Spelar en viktig roll"
- "Är grunden för", "Är grundläggande för", "Praktiska tips"

### Förbjudna sekventiella uppräkningar
ALDRIG använda sekvenser: "För det första... För det andra... För det tredje..."
PAT,

        'cs' => <<<'PAT'
## Zakázané vzory AI (Čeština)

### Zakázané vzory nadpisů
- "Kompletní průvodce...", "Vše, co potřebujete vědět o..."
- "Proč je X důležité", "Význam X", "Komplexní přístup k..."
- "Závěr" / "Shrnutí" (jako samostatný nadpis)

### Zakázané fráze v textu
- "Je důležité si uvědomit, že", "Stojí za zmínku, že", "V dnešním světě"
- "Pokud jde o", "V konečném důsledku", "Kromě toho", "Závěrem lze říci"
- "Klíčové je", "Nelze opomenout", "Je třeba zdůraznit, že"
- "Hraje klíčovou roli", "Hraje důležitou roli"
- "Je základem", "Je zásadní pro", "Praktické tipy", "Praktické rady"

### Zakázané sekvenční výčty
NIKDY nepoužívejte sekvence: "Za prvé... Za druhé... Za třetí..."
PAT,

        'fr' => <<<'PAT'
## Modèles d'IA interdits (Français)

### Modèles de titres interdits
- "Le guide ultime de...", "Tout ce que vous devez savoir sur..."
- "Pourquoi X est important", "L'importance de X", "Conclusion" (comme titre autonome)
- "Démystifier X", "Naviguer dans le monde de..."

### Phrases interdites dans le texte
- "Il est important de noter que", "Il convient de souligner que"
- "Dans le monde d'aujourd'hui", "En ce qui concerne", "En fin de compte"
- "De plus", "Par ailleurs", "En conclusion", "En résumé"
- "Joue un rôle crucial", "Joue un rôle clé", "Est le fondement de"
- "Conseils pratiques", "Astuces pratiques"

### Énumérations séquentielles interdites
NE JAMAIS utiliser de séquences : "Premièrement... Deuxièmement... Troisièmement..."
PAT,

        'it' => <<<'PAT'
## Pattern AI vietati (Italiano)

### Pattern di titoli vietati
- "La guida definitiva a...", "Tutto quello che devi sapere su..."
- "Perché X è importante", "L'importanza di X", "Conclusione" (come titolo autonomo)

### Frasi vietate nel testo
- "È importante notare che", "Vale la pena sottolineare che"
- "Nel mondo di oggi", "Quando si tratta di", "In definitiva"
- "Inoltre", "In conclusione", "In sostanza"
- "Gioca un ruolo cruciale", "Gioca un ruolo chiave"
- "È il fondamento di", "È fondamentale per", "Consigli pratici"

### Enumerazioni sequenziali vietate
MAI usare sequenze: "In primo luogo... In secondo luogo... In terzo luogo..."
PAT,

        'pt' => <<<'PAT'
## Padrões de IA proibidos (Português)

### Padrões de títulos proibidos
- "O guia definitivo para...", "Tudo o que você precisa saber sobre..."
- "Por que X é importante", "A importância de X", "Conclusão" (como título autônomo)

### Frases proibidas no texto
- "É importante notar que", "Vale ressaltar que", "No mundo de hoje"
- "Quando se trata de", "Em última análise", "Além disso", "Em conclusão"
- "Desempenha um papel crucial", "Desempenha um papel fundamental"
- "É a base de", "É fundamental para", "Dicas práticas"

### Enumerações sequenciais proibidas
NUNCA usar sequências: "Em primeiro lugar... Em segundo lugar... Em terceiro lugar..."
PAT,
    ];

    // For languages with specific banned patterns, use them
    if (isset($patterns[$lang])) {
        return $patterns[$lang];
    }

    // For all other European languages — generic rule
    $langName = getLanguageName($lang);
    return <<<PAT
## Banned AI patterns ({$langName})

General rule: if a phrase sounds like something a chatbot would generate but a human expert would never say in natural conversation — do not use it. This includes:
- Generic headings that could fit any article (e.g. "Key aspects", "Practical tips", "Summary")
- Empty filler phrases that announce instead of saying ("It's worth noting that..." — just note it)
- Generic intensifiers adding false depth ("crucial", "fundamental", "comprehensive" as fillers)
- Vague statements that say nothing specific ("enables achieving goals" — which goals?)
- Sequential enumerations ("Firstly... Secondly... Thirdly...") — use bullet lists or separate paragraphs instead
PAT;
}
