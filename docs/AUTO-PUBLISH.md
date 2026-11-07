# Auto-publikacje

Cykl: **content plan (XLSX/CSV) → kolejka → CRON generuje treść (Claude) → publikacja WP**.

## Tabele

- `auto_publish_config` — per strona: `daily_limit`, `use_speed_links`, `use_inline_images`, `random_author`, `lang`, `enabled`
- `auto_publish_queue` — pojedyncze pozycje z `status`:
  - `pending` — czeka na generację
  - `generating` — Claude API w trakcie (nie ruszaj, blokada wyścigu)
  - `generated` — treść gotowa, czeka na publikację
  - `batched` — pisanie wysłane w Batch API, wynik odbierze kolejny przebieg CRON (`batch_id`)
  - `publishing` — wysyłka do WP w toku
  - `published` — sukces, `published_url` + `published_at` uzupełnione
  - `error` — coś poszło źle, `error_message` z detalami
- `auto_publish_category_map` — mapowanie `category_name` (z content planu) → `wp_category_id` (WP)

## Flow

```
1. UŻYTKOWNIK: wgrywa XLSX w Auto publikacje (kolumny rozpoznawane po nagłówkach:
   Tytuł, Główne słowo kluczowe, Frazy poboczne, Kategoria, Notatki)
   → wybiera stronę zapleczową → wpisy trafiają do auto_publish_queue jako "pending"

2. CRON: php api/cron-auto-publish.php --token=X (codziennie 9:00)
   Tryb Batch API (domyślny, Ustawienia → "Auto-publikacja przez Batch API"):
     Faza A — wpisy "batched" z poprzedniego przebiegu: odbiór wyników batcha,
              audyt + poprawki + korekta, okładka, publikacja w WP
     Faza B — nowe "pending" (do daily_limit na stronę): research Senuto + SERP teraz,
              pisanie wysłane jednym batchem (50% taniej) → status "batched"
   Tryb synchroniczny (Batch wyłączony albo flaga --sync, np. „Uruchom teraz” z panelu):
     cały pipeline + publikacja w jednym przebiegu
   → Telegram raport na koniec przebiegu
   Zawsze tylko jeden przebieg naraz (flock na data/cron-auto-publish.run.lock) — drugi kończy się od razu.
   Ręczne „Uruchom teraz” (--sync --manual) zapisuje datę w settings.auto_publish_manual_date;
   zaplanowany przebieg z crona tego samego dnia jest wtedy pomijany.

3. UŻYTKOWNIK: monitoruje w UI Auto publikacje (queue stats per strona)
   albo w Linki → Historia artykułów (wszystkie publikacje z filtrem)
```

## Pipeline treści (`includes/article_pipeline.php`)

Wspólny dla auto-publikacji i „Zamów i opublikuj” (pojedynczo i masowo, w tle przez `api/generate-job.php` + `bin/run-generation-job.php`):

1. **Research Senuto** (tylko PL, gdy Senuto skonfigurowane): `getKeywords` (60), `getQuestions` (20), `getGroups` (12) → filtr trafności na Claude Haiku 5.5 (model może tylko wybierać pozycje z listy, wynik przecinany z wejściem). Frazy poboczne z content planu traktowane jako zatwierdzone (Senuto dokłada wtedy tylko pytania i grupy). Cache `senuto_cache` 30 dni.
2. **Analiza SERP**: `web_search` + `web_fetch` top 3-5 wyników → format, encje semantyczne, sekcje, fakty. Cache `serp_cache` 14 dni per fraza. Wyłączalne w Ustawieniach.
3. **Pisanie**: model z Ustawień (domyślnie Claude Sonnet 5.5, effort medium), prompt `includes/article_prompt.php` zgodny ze skillem seo-blog-post-writer-v2.
4. **Audyt** `includes/article_validator.php` (automatyczna część 21-punktowego checklistu skilla): długość, H2/H3, listy, zakazane frazy, myślniki PL, „?” w nagłówkach pytających, linki. FAIL → poprawka modelem (maks. 2 rundy). Znaki zapytania i myślniki poprawiane też deterministycznie.
5. **Korekta językowa** (ten sam prompt co `api/proofread-article.php`), przyjmowana tylko gdy nie pogarsza audytu.

Raport (checki, research, usage, koszt w USD) zapisywany w `auto_publish_queue.quality_report`.

Błędy przejściowe API (429/5xx/529/timeout, nieudany wynik batcha) po retry w kliencie: wpis wraca do `pending`, maks. 3 próby (`attempts`).

## Category mapping

Content plan ma kategorie jako tekst (`"Poradniki"`), WordPress jako `wp_category_id`. Mapping trzyma `auto_publish_category_map`.

- CRON przetwarza tylko wpisy z pustą kategorią, z ustawionym `wp_category_id` albo z kategorią obecną w mapowaniu. **Niezmapowana kategoria NIE jest dopasowywana po nazwie**: wpis czeka w `pending`, aż połączysz ją w UI Auto publikacje → „Niezmapowane kategorie”.
- Formatki content planu (lista rozwijana kategorii) zawierają tylko nazwy już zmapowane dla danej domeny.
- Generator tematów (`includes/topic_generator.php`) bierze kategorie z mapowania, żeby nowe wpisy nie utykały w kolejce, a frazy główne z puli Senuto (wolumen) zamiast wymyślonych.

## Refill kolejki przez AI

Kiedy strona ma <3 pendingów, `bin/refill-queues.php` może dorzucić 30 nowych tematów przez Claude API:

```bash
# Dry-run — pokazuje wyczerpane kolejki bez zmian
php bin/refill-queues.php

# Faktyczne generowanie i wstawianie
php bin/refill-queues.php --apply

# Konkretna strona
php bin/refill-queues.php --apply --site-id 5

# Inny threshold i count
php bin/refill-queues.php --apply --threshold 10 --count 20
```

**Anty-dupe (3 warstwy):**
1. Prompt zawiera do 200 ostatnich zajętych tytułów (wszystkie statusy queue + publications)
2. Normalizacja kluczy porównawczych: `"Jak wybrać X?"` == `"Jak wybrac X"` (lower + polskie znaki → ascii + tylko alfanum + single space)
3. Post-filtr LLM response: każdy zwrócony tytuł sprawdzany przeciw pełnej historii + duplikatom w batchu

Zwrotka: `{topics, raw_count, dropped_dupes}` — CLI raportuje `"Wygenerowano X (LLM zwrocil Y, odrzucono Z duplikatow)"`.

Prompt design (patrz `includes/topic_generator.php:generateTopicsForSite`):
- Model z `settings.ai_model`, klucz z `settings.anthropic_api_key`
- System: "Odpowiadasz WYŁĄCZNIE prawidłowym JSON-em, bez markdown code fence"
- User: nazwa/URL strony, kategorie, język (PL/EN/DE/FR/ES/IT/NL/CS z labelem), lista zajętych tytułów, format wymaganego JSON
- Parse: strip markdown fence, znajdź `[...]`, `json_decode`, snap `category_name` do rzeczywistej kategorii strony case-insensitive

## Alternatywa: dump kontekstu do offline processing

Jeśli chcesz wygenerować tematy poza serwerem (np. w Claude Desktop / GPT / ręcznie):

```bash
php bin/dump-refill-context.php > refill.json
# → JSON z: sites (nazwa, url, kategorie, lang, historia zajętych tytułów)
```

Potem ładujesz do dowolnego LLM, generujesz CSV, importujesz przez UI.

## Format XLSX/CSV do importu

Kolumny (nazwy dowolne — user mapuje w UI):
- **Tytuł** (wymagane)
- **Główna fraza** (main_keyword)
- **Frazy poboczne** (secondary_keywords, oddziel przecinkami)
- **Kategoria** (category_name — jedna nazwa)
- **Notatki** (notes, opcjonalne — trafi do prompta jako wskazówka)

CSV: `;` jako separator, UTF-8 z BOM (Excel PL od razu otworzy).

## CRON troubleshooting

| Objaw | Sprawdź |
|---|---|
| Kolejka nie posuwa się | Czy cron token się zgadza? Czy `enabled=1` w config? Czy `pending` są na tej stronie? |
| Wszystko w `error` z "kategoria niezmapowana" | Uzupełnij mapping w Auto publikacje → Niezmapowane kategorie |
| `error: Claude API 429` | Rate limit — zmniejsz `daily_limit` per strona |
| `error: WP REST 401` | Application password wygasł/został cofnięty w WP |
| `error: image generation failed` | Gemini limit / klucz nieważny — cron kontynuuje bez obrazka jeśli tak jest w flow |
| Publikacje bez linków | Sprawdź czy `use_speed_links` włączone i klucz Speed-Links w settings |

## Powiązane pliki

- `pages/auto-publish.php` — UI
- `api/auto-publish.php` — endpointy (sites, add, save-config, mappings)
- `api/cron-auto-publish.php` — sam CRON (długi timeout, batch processing)
- `includes/topic_generator.php` — refill przez Claude AI
- `bin/refill-queues.php`, `bin/dump-refill-context.php` — CLI
