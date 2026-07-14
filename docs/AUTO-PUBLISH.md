# Auto-publikacje

Cykl: **content plan (XLSX/CSV) → kolejka → CRON generuje treść (Claude) → publikacja WP**.

## Tabele

- `auto_publish_config` — per strona: `daily_limit`, `use_speed_links`, `use_inline_images`, `random_author`, `lang`, `enabled`
- `auto_publish_queue` — pojedyncze pozycje z `status`:
  - `pending` — czeka na generację
  - `generating` — Claude API w trakcie (nie ruszaj, blokada wyścigu)
  - `generated` — treść gotowa, czeka na publikację
  - `publishing` — wysyłka do WP w toku
  - `published` — sukces, `published_url` + `published_at` uzupełnione
  - `error` — coś poszło źle, `error_message` z detalami
- `auto_publish_category_map` — mapowanie `category_name` (z content planu) → `wp_category_id` (WP)

## Flow

```
1. UŻYTKOWNIK: wgrywa XLSX/CSV w Auto publikacje
   → mapuje kolumny (Tytuł, Główna fraza, Frazy poboczne, Kategoria)
   → wybiera stronę zapleczową
   → wpisy trafiają do auto_publish_queue jako "pending"

2. CRON: /api/cron-auto-publish.php?token=X (co dzień, godzinowo, whatever)
   → dla każdej strony z enabled=1:
      → bierze do daily_limit pending items z tej strony
      → dla każdego:
         a. UPDATE status='generating'
         b. Claude API: full article (topic, keywords, category context)
         c. Gemini API: featured image (jeśli image_gen w config)
         d. UPDATE status='generated'
         e. WP REST: create post (matched category via auto_publish_category_map)
         f. UPDATE status='published', published_url, published_at
         g. Opcjonalnie Speed-Links VIP indexing
         h. Opcjonalnie inline images przez Gemini (linkowanie w treści)
      → Telegram raport na koniec cyklu

3. UŻYTKOWNIK: monitoruje w UI Auto publikacje (queue stats per strona)
   albo w Linki → Historia artykułów (wszystkie publikacje z filtrem)
```

## Category mapping

Content plan zwykle ma kategorie jako string (`"Poradniki"`, `"Recenzje"`). WordPress ma je jako `wp_category_id`. Mapping trzyma `auto_publish_category_map`.

- Pierwsza publikacja z nową kategorią → CRON próbuje zmatchować po nazwie w WP, jeśli znajdzie → zapisuje mapping, jeśli nie → status=`error` z komunikatem "kategoria niezmapowana"
- UI Auto publikacje ma sekcję "Niezmapowane kategorie" gdzie ręcznie łączysz `category_name` → wybór z dropdownu WP kategorii
- Pozycje z `wp_category_id IS NOT NULL` lub z pustym `category_name` — trafiają na produkcję bez blokera

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
