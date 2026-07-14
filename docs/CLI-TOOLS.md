# CLI Tools (`bin/`)

Wszystkie skrypty odpalane przez SSH na serwerze. Nie edytuj plików na serwerze — deploy leci przez git push, patrz [DEPLOY.md](DEPLOY.md).

```bash
cd /sciezka/do/semtree-zaplecze
php bin/<skrypt>.php [args]
```

## `bin/2fa-debug.php`

Diagnostyka i recovery dla 2FA.

```bash
# Status wszystkich userów + zegar serwera + stan APP_KEY
php bin/2fa-debug.php
php bin/2fa-debug.php --status         # to samo, jawnie

# Tylko czas serwera (do sprawdzenia driftu vs telefon)
php bin/2fa-debug.php --server-time

# Zdejmij lockout (wyzeruj failed counter + totp_locked_until)
php bin/2fa-debug.php --unlock <username>

# NUKLEARKA: wyłącz 2FA u usera (nowa konfiguracja przy 1. loginie)
php bin/2fa-debug.php --disable <username>
```

Output `--status` (przykład):
```
Server time:    2026-05-28 09:47:35 CEST  (UTC: 09:47:35)
Unix time:      1780004855
TOTP step (30s): 59333495
PHP TZ:         Europe/Warsaw
ABSOLUTE_SESSION_SECONDS: 86400
TOTP_WINDOW:    2 (±60s tolerance)
MAX_FAILED:     10 / LOCKOUT: 900s

APP_KEY file:   /path/data/app_key.php
  exists:       YES  (mtime: 2026-05-15 12:34:00)
  size:         140 bytes
  loadable:     YES (key length: 32 bytes)

ID   USERNAME             2FA      FAILED    LOCKED   ENABLED_AT
1    admin                ON       0         -        2026-05-15 12:34:56
2    pawel-a              ON       0         -        2026-05-16 09:12:00
3    worker-1             setup    0         -        -
```

## `bin/list-articles.php`

Lista opublikowanych artykułów po kategorii strony + zakresie dat. Łączy `publications` (ręczne) + `auto_publish_queue` (auto).

```bash
# Tabela (domyślnie)
php bin/list-articles.php --category "Prywatne zaplecze klienta" --month 2026-05

# Same URL-e (do pipe / clipboard)
php bin/list-articles.php --category "X" --month 2026-05 --format urls

# CSV do Excela
php bin/list-articles.php --category "X" --month 2026-05 --format csv > raport.csv

# JSON
php bin/list-articles.php --category "X" --month 2026-05 --format json

# Dowolny zakres dat
php bin/list-articles.php --category "X" --from 2026-05-15 --to 2026-06-15

# Tylko manual publications (bez auto)
php bin/list-articles.php --category "X" --month 2026-05 --source manual

# Tylko auto-publish
php bin/list-articles.php --category "X" --month 2026-05 --source auto
```

**Uwaga o UI:** to samo można zrobić w Linki → Historia artykułów z filtrem kategorii + dat + eksportem CSV. CLI jest dla masowych operacji z pipe / cron.

## `bin/refill-queues.php`

Uzupełnia kolejkę `auto_publish_queue` tematami wygenerowanymi przez Claude AI dla stron z wyczerpaną kolejką.

```bash
# Dry-run — pokazuje listę wyczerpanych bez zmian
php bin/refill-queues.php

# Zastosuj (30 tematów per strona przez Claude API)
php bin/refill-queues.php --apply

# Inne parametry
php bin/refill-queues.php --apply --count 20            # 20 zamiast 30
php bin/refill-queues.php --apply --threshold 10        # wyczerpana gdy pending<10
php bin/refill-queues.php --apply --site-id 5           # tylko strona id=5
```

Wymaga: `settings.anthropic_api_key`, `settings.ai_model` (default `claude-sonnet-4-6`).

Anty-dupe zapewniony przez `includes/topic_generator.php` (patrz [AUTO-PUBLISH.md](AUTO-PUBLISH.md)).

**Output przykład:**
```
[  7] example.com
  Jezyk: pl | Kategorie: Poradniki, Recenzje
  Historia (wszystkie tytuly): 234 (queue+publications, anty-dupe)
  Generuje 30 tematow przez Claude API...
  Wygenerowano: 27 (LLM zwrocil 30, odrzucono 3 duplikatow) w 42.3s
    1. Jak wybrać X w 2026 — kompletny poradnik  [kw: wybór X]
    2. Top 10 błędów przy Y  [kw: błędy Y]
    ...
  Wstawiono do kolejki: 27
```

## `bin/dump-refill-context.php`

Wypycha JSON z kontekstem stron o wyczerpanej kolejce — do przetworzenia offline (np. Claude Desktop, ChatGPT, własny skrypt).

```bash
# Standardowo: strony z <3 pendingów, do 300 historii per strona
php bin/dump-refill-context.php > refill.json

# Więcej stron (traktuj <10 jako wyczerpaną)
php bin/dump-refill-context.php --threshold 10 > refill.json

# Więcej historii (dłuższy JSON, mocniejszy anty-dupe)
php bin/dump-refill-context.php --titles-limit 500 > refill.json

# WSZYSTKIE strony (nie tylko wyczerpane) — na przygotowanie masówki
php bin/dump-refill-context.php --all > refill.json
```

Struktura outputu:
```json
{
  "generated_at": "2026-07-01 16:02:18",
  "threshold": 3,
  "titles_limit": 300,
  "sites_count": 12,
  "sites": [
    {
      "id": 5,
      "name": "example.com",
      "url": "https://example.com",
      "categories": ["Cat1", "Cat2"],
      "lang": "pl",
      "auto_enabled": true,
      "pending_count": 2,
      "historical_titles": ["Title 1", "Title 2", ...]
    }
  ]
}
```

## Dodawanie nowego CLI

Wzorzec z istniejących:

```php
<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}
require __DIR__ . '/../db.php';       // sam DB, bez auth
// LUB
require __DIR__ . '/../auth.php';     // jeśli potrzebujesz helperów, ale NIE wywołuj requireLogin (nie ma sesji w CLI)

// Parse args ręcznie — brak Symfony/Composera
$args = ['flag' => false, 'name' => 'default'];
for ($i = 1; $i < $argc; $i++) {
    $a = $argv[$i];
    if ($a === '--flag') { $args['flag'] = true; continue; }
    if (str_starts_with($a, '--')) {
        $key = substr($a, 2);
        $val = $argv[$i + 1] ?? '';
        if ($val !== '' && !str_starts_with($val, '--')) { $args[$key] = $val; $i++; }
    }
}

// Do work
$db = getDb();
// ...
```

Konwencje:
- Kod błędu 1 przy błędach walidacji, 0 przy sukcesie
- `fwrite(STDERR, ...)` dla błędów/notatek meta, `echo` dla właściwego outputu
- Wspieraj `--dry-run` / `--apply` gdy modyfikuje bazę
- Doprowadź do `bin/<nazwa>.php` (bez podfolderów)
- Dopisz do tego pliku (docs/CLI-TOOLS.md) po dodaniu
