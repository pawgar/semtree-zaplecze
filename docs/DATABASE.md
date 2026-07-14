# Baza danych

SQLite3 w `data/database.sqlite`. WAL mode + foreign keys włączone. Singleton `getDb()` w `db.php`. Auto-tworzenie schematu przy 1. uruchomieniu (`initSchema`), migracje idempotentne (`migrateSchema`).

## Zasady migracji

- **Nigdy DROP TABLE / DROP COLUMN / RENAME COLUMN** (SQLite tego nie wspiera bez rebuild)
- Nowe kolumny: `ALTER TABLE ADD COLUMN` z domyślną wartością, obok tego `if (!in_array(...))` check przez `PRAGMA table_info`
- Nowe tabele: `CREATE TABLE IF NOT EXISTS`
- Migracja odpala się przy każdym `getDb()` — musi być pełna idempotencja
- Do jednorazowych operacji (typu wyczyszczenie legacy stanu) używaj flagi w `settings`:
  ```php
  $flag = $db->querySingle("SELECT value FROM settings WHERE key='migration_v42'");
  if ($flag !== '1') {
      $db->exec("...jednorazowa zmiana...");
      $db->exec("INSERT OR REPLACE INTO settings (key, value) VALUES ('migration_v42', '1')");
  }
  ```
  Przykład w kodzie: `lockout_purge_v1` w `db.php:migrateSchema()`.

## Schema

### `users` — konta panelu

| Kolumna | Typ | Opis |
|---|---|---|
| id | INTEGER PK | |
| username | TEXT UNIQUE | login |
| password | TEXT | bcrypt hash |
| role | TEXT | `admin` \| `worker` |
| created_at | DATETIME | |
| **totp_secret** | TEXT | AES-256-GCM ciphertext base32 secret |
| **totp_enabled** | INTEGER | 0 lub 1 |
| **totp_recovery_codes** | TEXT | JSON: `[{"hash":"bcrypt","used":false},...]` (8 pozycji) |
| **totp_enabled_at** | DATETIME | kiedy user aktywował |
| **totp_failed_attempts** | INTEGER | counter dla lockout |
| **totp_locked_until** | DATETIME | do kiedy zablokowany |

### `sites` — strony zapleczowe

| Kolumna | Typ | Opis |
|---|---|---|
| id | INTEGER PK | |
| name | TEXT | display name |
| url | TEXT | https://... |
| username | TEXT | WP login |
| app_password | TEXT | WP Application Password |
| categories | TEXT | comma-separated (`"Cat1, Cat2"`) |
| post_count | INTEGER | z WP REST, cache |
| http_status | INTEGER | ostatni HTTP code (CRON) |
| api_ok | INTEGER | 0/1 (CRON) |
| last_status_check | DATETIME | (CRON) |
| gsc_clicks, gsc_impressions, gsc_clicks_change, gsc_impressions_change, gsc_keywords_count | | dane z GSC (CRON) |
| gsc_last_update | DATETIME | |

### `links` — linki zewnętrzne z artykułów WP

| Kolumna | Typ | Opis |
|---|---|---|
| id | INTEGER PK | |
| site_id | INTEGER FK | ON DELETE CASCADE |
| client_id | INTEGER FK NULL | ON DELETE SET NULL |
| post_url | TEXT | URL artykułu na stronie zapleczowej |
| post_title | TEXT | |
| target_url | TEXT | URL docelowy (linkowana domena) |
| anchor_text | TEXT | |
| link_type | TEXT | `dofollow` \| `nofollow` |
| notes | TEXT | |
| created_at | DATETIME | |
| **UNIQUE(site_id, post_url, target_url)** | | dedup na skanowanie |

### `clients` — klienci / linkowane domeny

| Kolumna | Typ | Opis |
|---|---|---|
| id | INTEGER PK | |
| name | TEXT | display name |
| domain | TEXT | matcher (bez schema) |
| color | TEXT | #RRGGBB dla badge'y |
| created_at | DATETIME | |

### `publications` — ręcznie opublikowane artykuły

| Kolumna | Typ | Opis |
|---|---|---|
| id | INTEGER PK | |
| user_id | INTEGER FK | ON DELETE CASCADE |
| site_id | INTEGER FK | ON DELETE CASCADE |
| post_url | TEXT | |
| post_title | TEXT | |
| created_at | DATETIME | |

Do połączenia z auto-publish użyj `LEFT JOIN auto_publish_queue apq ON apq.published_url = p.post_url AND apq.status='published'` — jak match, to auto, inaczej manual.

### `gsc_cache` — cache danych GSC

| Kolumna | Typ | Opis |
|---|---|---|
| id | INTEGER PK | |
| site_url | TEXT | z prefiksem `https://` (format GSC) |
| metric_type | TEXT | `dashboard` \| `report` \| itd. |
| date_from, date_to | TEXT | YYYY-MM-DD |
| data | TEXT | JSON |
| fetched_at | DATETIME | TTL 6h |
| **UNIQUE(site_url, metric_type, date_from, date_to)** | | dedup |

### `settings` — key-value

| Klucz | Wartość |
|---|---|
| `anthropic_api_key` | Claude API key |
| `ai_model` | np. `claude-sonnet-4-6` (default) |
| `gemini_api_key` | Google Gemini key |
| `gsc_client_id`, `gsc_client_secret`, `gsc_access_token`, `gsc_refresh_token`, `gsc_token_expires` | OAuth GSC |
| `cron_token` | token dla /api/cron-* |
| `speed_links_api_key` | Speed-Links.net |
| `telegram_bot_token`, `telegram_chat_id` | Telegram raporty |
| `migration_flag_*` | flagi jednorazowych migracji |
| `lockout_purge_v1` | flaga: wyzerowano stare lockouty raz |

### `auto_publish_config` — konfiguracja per strona

| Kolumna | Typ | Domyślne |
|---|---|---|
| site_id | INTEGER PK FK | |
| daily_limit | INTEGER | 1 |
| use_speed_links | INTEGER | 0 |
| use_inline_images | INTEGER | 0 |
| random_author | INTEGER | 0 |
| lang | TEXT | `pl` |
| enabled | INTEGER | 1 |

### `auto_publish_queue` — kolejka do publikacji

| Kolumna | Typ | Opis |
|---|---|---|
| id | INTEGER PK | |
| site_id | INTEGER FK | |
| title | TEXT | |
| main_keyword | TEXT | |
| secondary_keywords | TEXT | comma-separated |
| category_name | TEXT | matched później do wp_category_id |
| notes | TEXT | wskazówki do promptu |
| status | TEXT | `pending`\|`generating`\|`generated`\|`publishing`\|`published`\|`error` |
| wp_category_id | INTEGER | po zmapowaniu |
| published_url | TEXT | po sukcesie |
| error_message | TEXT | po `error` |
| created_at | DATETIME | |
| scheduled_date | DATE | opcjonalna data planowana |
| published_at | DATETIME | po sukcesie |
| **INDEX idx_apq_site_status** | | (site_id, status) — hot path CRON |

### `auto_publish_category_map` — mapping kategorii

| Kolumna | Typ | Opis |
|---|---|---|
| id | INTEGER PK | |
| site_id | INTEGER FK | |
| category_name | TEXT | z content planu |
| wp_category_id | INTEGER | ID z WP REST /categories |
| wp_category_name | TEXT | display cache |
| **UNIQUE(site_id, category_name)** | | |

## Query patterns

**Dedup po URL między publications a auto_publish_queue:**
```sql
SELECT p.*, IIF(EXISTS(SELECT 1 FROM auto_publish_queue apq
                       WHERE apq.published_url = p.post_url AND apq.status='published'),
                'auto', 'manual') AS source
FROM publications p
WHERE ...
```

**Filtr kategorii strony (comma-separated safe):**
```sql
WHERE LOWER(',' || REPLACE(sites.categories, ', ', ',') || ',') LIKE '%,kategoria,%'
```
(Bez tego triku `LIKE '%kat%'` łapie `"Podkategoria"` jako fałszywy match.)

**Filtr po zakresie dat na created_at (DATETIME):**
```sql
WHERE DATE(created_at) >= '2026-05-01' AND DATE(created_at) <= '2026-05-31'
```
(SQLite `DATETIME` to string — `DATE()` funkcja parsuje pierwsze 10 znaków.)

## Backup

- Kopia `data/database.sqlite` = pełna kopia stanu
- `.sqlite-journal` / `.sqlite-wal` mogą istnieć równolegle w trakcie zapisu — do bezpiecznego backupu użyj `sqlite3 database.sqlite ".backup 'backup.sqlite'"` (atomic)
- **`data/app_key.php` MUSI być backupowany razem** — bez niego dane z DB (sekrety 2FA) są nieczytelne
- SQLite plik lokalny, brak sensu w replikacji multi-master — dla HA rozważ migrację do MySQL (znacznie większa robota)

## Rozmiar / limity

Aktualnie: ~2500 publications + kilka tysięcy linków + kilkadziesiąt stron → plik ~1-5MB. SQLite radzi sobie do gigabajtów bez zauważalnego degrade w tej aplikacji.

Bottleneck to praktyce: CRON job time (WP API + Claude API) i memoria PHP na load `sitesData` do JS.
