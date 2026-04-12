# Semtree Zaplecze — Kontekst projektu

Aplikacja PHP do zarządzania siecią stron zapleczowych WordPress (PBN). Monitoruje statusy, publikuje artykuły, zarządza linkami, integruje się z Google Search Console.

## Stack technologiczny

- **Backend:** PHP 8.0+ (natywny, bez frameworka), SQLite3 (WAL mode, foreign keys)
- **Frontend:** Bootstrap 5.3, Bootstrap Icons, vanilla JS (jeden plik app.js ~4500 linii)
- **Baza danych:** SQLite3 w `data/database.sqlite` (auto-tworzona przy pierwszym uruchomieniu)
- **Serwer:** Apache z mod_rewrite, HTTPS wymagany

## Struktura katalogów

```
/api/           — 27 endpointów REST (JSON request/response)
/pages/         — 11 szablonów PHP (server-rendered HTML)
/includes/      — Biblioteki: gsc_api.php, wp_api.php, link_extractor.php, article_prompt.php, docx_parser.php, image_utils.php
/assets/css/    — style.css (Bootstrap overrides + custom)
/assets/js/     — app.js (cała logika frontendowa, AJAX via fetch API)
/data/          — SQLite database (chroniony przez .htaccess)
```

Pliki root: `index.php` (router), `auth.php` (sesje + RBAC), `db.php` (schema + migracje), `config.php` (stałe)

## Architektura i wzorce

### Routing
`index.php` obsługuje `?page=X` → dispatchuje do `pages/X.php`. Strony renderują HTML, JS robi AJAX do `/api/*.php`.

### Autentykacja
- Sesje PHP (24h), bcrypt, role: `admin` / `worker`
- `requireLogin()` / `requireAdmin()` — dla stron
- `requireLoginApi()` / `requireAdminApi()` — dla API (zwraca JSON 401/403)
- Domyślne konto: admin/admin (tworzone w initSchema)

### Baza danych
Singleton `getDb()` w db.php. Migracje w `migrateSchema()` — kolumny dodawane przez ALTER TABLE z check PRAGMA table_info. Tabele:
- `users` — id, username, password(bcrypt), role
- `sites` — id, name, url, username, app_password, categories, post_count, http_status, api_ok, last_status_check
- `links` — site_id(FK), client_id(FK nullable), post_url, target_url, anchor_text, link_type, UNIQUE(site_id, post_url, target_url)
- `clients` — id, name, domain, color
- `publications` — user_id(FK), site_id(FK), post_url, post_title
- `gsc_cache` — site_url, metric_type, date_from, date_to, data(JSON), fetched_at, UNIQUE(site_url, metric_type, date_from, date_to)
- `settings` — key(PK), value (klucze API, tokeny OAuth, konfiguracja)

### API pattern
Każdy endpoint w `/api/` wymaga auth, przyjmuje JSON body (POST/PUT/DELETE), zwraca JSON. Prepared statements wszędzie (no raw SQL).

## Integracje zewnętrzne

### WordPress REST API (`includes/wp_api.php`)
- Basic Auth (username + application password)
- Auto-detect URL format (pretty permalinks vs plain `?rest_route=`)
- Endpointy: posts CRUD, media upload, users, categories

### Google Search Console (`includes/gsc_api.php`)
- OAuth 2.0 (offline access, auto-refresh tokenów)
- Tokeny w tabeli `settings` (gsc_client_id, gsc_client_secret, gsc_access_token, gsc_refresh_token, gsc_token_expires)
- Cache w tabeli `gsc_cache` (6h TTL)
- Flow: `/api/gsc-auth.php?action=connect` → Google → `/api/gsc-callback.php` → tokeny zapisane

### Claude API (`api/generate-article.php`)
- Model konfigurowalny w settings (klucz: `ai_model`)
- Klucz API w settings (klucz: `anthropic_api_key`)
- Prompty w `includes/article_prompt.php` (wielojęzyczne)
- Max 16000 tokenów, timeout 150s

### Google Gemini (`api/gemini-generate.php`)
- Generowanie obrazów (fallback przez kilka modeli)
- Klucz API w settings (klucz: `gemini_api_key`)

## CRON

Dwa endpointy chronione tokenem (`settings.cron_token`):
- `api/cron-status.php?token=X` — odświeża statusy HTTP/API + skanuje linki (timeout 900s)
- `api/cron-gsc.php?token=X` — odświeża dane GSC dla wszystkich stron (timeout 600s)

## Deploy

Upload plików na serwer Apache z PHP 8.0+. Katalog `data/` musi być writable. Baza tworzy się automatycznie. Zabezpieczenia w `.htaccess` (blokuje dostęp do data/, config.php, db.php, auth.php).

## Konwencje kodu

- **PHP:** Brak typów w sygnaturach (poza getDb(): SQLite3). Proceduralne API endpointy, funkcje w includes.
- **JS:** Vanilla JS, bez modułów. Globalne funkcje. AJAX przez `api()` helper (fetch wrapper). Sortowanie/filtrowanie po stronie klienta.
- **CSS:** Zmienne CSS w :root, klasy `.stat-card`, `.content-card`, `.sidebar-*`. BEM-like nazewnictwo.
- **Baza:** Prepared statements. Migracje w migrateSchema() — sprawdź czy kolumna istnieje, jeśli nie — ALTER TABLE.
- **Język UI:** Polski (etykiety, komunikaty, komentarze w kodzie mieszane PL/EN)

## Ważne szczegóły

- Frontend jest SPA-like: `pages/*.php` renderuje skeleton HTML, `app.js` pobiera dane i renderuje dynamicznie
- `gscDashboardData` zmienna globalna JS — jeśli null, kolumny GSC są ukryte
- Link scanning: `includes/link_extractor.php` parsuje HTML postów WordPressa, szuka linków zewnętrznych, matchuje do klientów
- Kafelki sumaryczne używają klasy `.stat-card` (okrągłe ikony, analytics-style)
- Tabela dashboard nie ma kolumny URL — zamiast tego ikonka oka w Akcjach
