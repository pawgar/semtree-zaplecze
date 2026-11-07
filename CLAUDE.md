# Semtree Zaplecze — Kontekst projektu

Aplikacja PHP do zarządzania siecią stron zapleczowych WordPress (PBN). Monitoruje statusy, publikuje artykuły masowo, śledzi linki, generuje treści AI (Claude + Gemini), integruje się z Google Search Console, wymusza 2FA + auto-logout 24h.

**Repo:** `github.com/pawgar/semtree-zaplecze` · **Prod:** `zaplecze.semtree.com.pl`

## Stack

- **Backend:** PHP 8.0+ natywnie (bez frameworka, bez Composer), SQLite3 (WAL, foreign keys)
- **Frontend:** Tabler 1.4 (Bootstrap 5.3), Tabler Icons + Bootstrap Icons (migracja), vanilla JS (jeden `app.js` ~6200 linii, brak modułów), Tom Select do przeszukiwalnych dropdownów
- **Baza:** SQLite3 w `data/database.sqlite` (auto-tworzona przy 1. uruchomieniu, migracje idempotentne)
- **Serwer:** Apache + `mod_rewrite`, HTTPS wymagany (WP Application Passwords + secure cookies)

## Zasady operacyjne — KRYTYCZNE

Reguły które MUSISZ przestrzegać przy każdej zmianie w tym repo:

1. **Deploy TYLKO przez `git push origin master`.** SSH tylko do debugowania i diagnostyki. Nigdy nie edytuj plików bezpośrednio na serwerze — zostaną nadpisane przy następnym pullu.
2. **Testuj przed pushem.** Minimum: `php -l` na zmienionych plikach PHP, `node -c assets/js/app.js` na JS. Nie pushuj kodu z syntax errorem.
3. **`data/app_key.php`, `data/database.sqlite`, `data/sessions/` NIE trafiają do gita.** Sprawdź `.gitignore` przed dodaniem nowych plików w `data/`.
4. **Utrata `data/app_key.php` = utrata wszystkich sekretów 2FA.** Wszyscy userzy muszą przepiąć aplikację. Backup mile widziany.
5. **Zmiana `session_save_path` deloguje wszystkich raz.** Świadomie: session files pod nową ścieżką nie istnieją. To OK przy pierwszym deployu po zmianie, ale nie rób tego "przy okazji".
6. **2FA jest wymagane dla wszystkich** — nowy zalogowany user bez `totp_enabled=1` zostanie przekierowany na `?page=2fa-required` i nie ruszy się dalej.
7. **Absolutny timeout sesji 24h od `login_at`** (nie idle). Konfig w `config.php:ABSOLUTE_SESSION_SECONDS`.

Powyższe pochodzą z incydentów i decyzji projektowych — nie znoś ich bez rozmowy.

## Struktura repo

```
/                         → index.php (router), auth.php, db.php, config.php
/api/          (39 pl)    → REST endpointy (JSON in/out, requireLogin* auth)
/pages/        (15 pl)    → Szablony PHP renderujące HTML skeleton
/includes/     (13 pl)    → Biblioteki: wp_api, gsc_api, docx_parser, encryption,
                            totp, two_factor, topic_generator, article_prompt,
                            image_utils, link_extractor, header/footer, squidward_facts
/bin/                     → CLI tools (2fa-debug, list-articles, refill-queues,
                            dump-refill-context) — patrz docs/CLI-TOOLS.md
/assets/css/style.css     → Style projektu (klasy .stat-card, .session-timer, itd.)
/assets/js/app.js         → CAŁA logika frontendu (globalne funkcje, brak modułów)
/assets/js/session-timer  → Countdown 24h w topbarze
/assets/vendor/           → Tabler, Bootstrap, Tabler Icons, Tom Select, qrcode
/assets/img/favicon/      → Favicony (32/192/180) skopiowane z semtree.pl
/data/                    → SQLite DB, app_key, sessions (blokowane przez .htaccess)
/docs/                    → Dokumentacja topikowa (deploy, 2fa, auto-publish, itd.)
```

## Routing

`index.php` → `?page=X` dispatchuje do `pages/X.php`. Strony renderują skeleton HTML, `app.js` pobiera dane przez fetch i renderuje dynamicznie (SPA-like flow).

Kolejność w `index.php`:
1. `logout` → destruct sesji
2. `login` POST → `auth.php:login($u, $p)` → `ok` / `pending_2fa` / `locked` / `invalid`
3. `login-2fa` POST → `completeTwoFactor($code, $isRecovery)` → `ok` / `invalid` / `expired`
4. `login-2fa` GET jeśli pending → render 2FA challenge
5. Jeśli `!isLoggedIn()` → render `pages/login.php`
6. **Gate 2FA:** jeśli logged-in ale `!hasTwoFactorEnabled()` → force redirect do `?page=2fa-required`, dopuszczalne tylko `2fa-required` i `logout`
7. Switch przez `?page=` do `pages/*.php` (dashboard, order, publish, publish-bulk, import, links, gsc-report, auto-publish, site-card, settings, users, profile, 2fa-required)

## Autentykacja + 2FA (patrz docs/AUTH-2FA.md)

- Sesje PHP 24h absolute (nie idle) z `login_at` w sesji
- **Własna konfiguracja sesji** w `auth.php:startSession()`:
  - `session.gc_maxlifetime = 86400` (default PHP 1440s = 24 min był problemem)
  - `session.cookie_lifetime = 86400`
  - `session_save_path = data/sessions/` (izolacja od `/tmp` shared)
  - HttpOnly, SameSite=Lax, Secure na HTTPS, use_strict_mode
- 2FA TOTP (RFC 6238, własna implementacja, zero Composera) — sekret AES-256-GCM (klucz `data/app_key.php`, auto-gen)
- 8 kodów odzyskiwania bcrypt, jednorazowe, immune-na-lockout
- Rate limit: 10 nieudanych OTP → 15 min lockout
- Whitelist API dla setup wizardu: `2fa-setup.php`, `2fa-enable.php`, `session-info.php`
- Escape hatch: `php bin/2fa-debug.php --disable <username>` przez SSH

## Baza danych — tabele

Singleton `getDb()` w `db.php`. Migracje w `migrateSchema()` — idempotentne (sprawdza `PRAGMA table_info`, dodaje kolumny przez ALTER TABLE).

| Tabela | Kolumny (klucze) |
|---|---|
| `users` | id, username, password (bcrypt), role, created_at, **totp_secret** (encrypted), **totp_enabled**, **totp_recovery_codes** (JSON), **totp_enabled_at**, **totp_failed_attempts**, **totp_locked_until** |
| `sites` | id, name, url, username, app_password, categories (comma-sep), post_count, http_status, api_ok, last_status_check, gsc_clicks, gsc_impressions, gsc_clicks_change, gsc_impressions_change, gsc_keywords_count, gsc_last_update |
| `links` | site_id (FK), client_id (FK nullable), post_url, target_url, anchor_text, link_type, notes, UNIQUE(site_id, post_url, target_url) |
| `clients` | id, name, domain, color |
| `publications` | user_id (FK), site_id (FK), post_url, post_title, created_at |
| `gsc_cache` | site_url, metric_type, date_from, date_to, data (JSON), fetched_at, UNIQUE(site_url, metric_type, date_from, date_to) |
| `settings` | key (PK), value — klucze API, tokeny OAuth, cron_token, ai_model, gemini_api_key, anthropic_api_key, gsc_*, telegram_bot_token, telegram_chat_id |
| `auto_publish_config` | site_id (PK), daily_limit, use_speed_links, use_inline_images, random_author, lang, enabled |
| `auto_publish_queue` | id, site_id, title, main_keyword, secondary_keywords, category_name, notes, status (pending/generating/generated/publishing/published/error), wp_category_id, published_url, error_message, scheduled_date, published_at, created_at |
| `auto_publish_category_map` | site_id, category_name, wp_category_id, wp_category_name, UNIQUE(site_id, category_name) |

## Integracje zewnętrzne

- **WordPress REST API** (`includes/wp_api.php`) — Basic Auth (App Passwords), auto-detect pretty vs `?rest_route=`, posts CRUD + media + users + categories
- **Google Search Console** (`includes/gsc_api.php`) — OAuth 2.0 offline, tokeny w settings, cache 6h w `gsc_cache`
- **Claude API** — wspólny klient `includes/claude_client.php` (raw cURL: retry 429/5xx/529, streaming SSE, prompt caching, pause_turn, server-side fallback, Message Batches). Model z `settings.ai_model` (default `claude-sonnet-5-5`), effort z `settings.ai_effort` (default `medium`), klucz w `settings.anthropic_api_key`. Pipeline treści `includes/article_pipeline.php` (research → pisanie → audyt `includes/article_validator.php` → korekta), prompty w `includes/article_prompt.php`. Szczegóły: docs/AUTO-PUBLISH.md
- **Senuto** — `includes/senuto_api.php` (port z Ateny): login e-mail+hasło → JWT ~30 dni, auto-refresh (<5 dni lub 401), hasło szyfrowane (`settings.senuto_password_enc`). Klucze `senuto_*` NIE są dostępne przez `api/settings.php` — tylko `api/senuto-settings.php`. Cache `senuto_cache` 30 dni. `country_id` = 1 dla PL (endpointy keywords_analysis nie przyjmują 200)
- **Google Gemini** — obrazki wyróżniające + inline, fallback przez kilka modeli (native → Imagen)
- **Rapid URL Indexer** — `includes/rapid_indexing.php` (klient z Hefajstosa przez port w seo-ai): KAŻDA publikacja trafia do kolejki `index_queue` (api/publish.php, CRON), wysyłka paczkami jako projekt Rapid (koniec przebiegu CRON / worker / od razu bez workera). Przed wysyłką 200 + brak noindex, dedupe 30 dni, kontrola kredytów, wpisy zaplanowane czekają do daty. **Rapid zwraca 403 HTML dla domyślnego User-Agenta curla** — zawsze `CURLOPT_USERAGENT`. Status projektów (`rapid_projects`) odświeża worker co godzinę. Wyłącznik: `settings.index_auto`
- **Kontrola po zgłoszeniu** — `indexFollowUp()` czyta stan z `index_status` (nocny skan GSC, bez dodatkowej kwoty): zaindeksowany → `indexed_at`; po 14 dniach nadal poza indeksem → jedno ponowne zgłoszenie (`resubmits`). Panel: zakładka Indeksacja → „Zgłoszenia do indeksacji” (`api/index-panel.php`)
- **Mapy witryn w GSC** — `indexSitemapsSync()` raz na dobę (worker, po 7:00): stan map (`site_sitemaps`), brak mapy → zgłoszenie mapy z robots.txt, nowe zgłoszenia → ponowne zgłoszenie mapy. Zgłaszanie wymaga zakresu OAuth `webmasters` (nie `.readonly`) — po zmianie zakresu trzeba połączyć GSC ponownie w Ustawieniach
- **IndexNow** (Bing, Yandex, Seznam, Naver, Yep, Amazon — nie Google) — `includes/indexnow.php`, wysyłka razem z paczką Rapid, tylko dla domen, na których odpowiada plik klucza (`site_indexnow`, sprawdzane raz na dobę). Plik klucza serwuje mu-plugin `wp-plugins/semtree-indexnow-key.php` — do wgrania do `wp-content/mu-plugins/` każdej strony zaplecza
- **Telegram Bot** — raporty z CRON auto-publish

## CRON

Chronione tokenem `settings.cron_token`:
- `api/cron-status.php?token=X` — HTTP + WP API statusy + skanowanie linków (900s timeout)
- `api/cron-gsc.php?token=X` — refresh GSC dla wszystkich stron (600s timeout)
- `api/cron-auto-publish.php --token=X` — kolejka auto-publikacji; z Batch API (domyślnie) publikuje wyniki poprzedniego przebiegu i wysyła kolejny batch, `--sync` robi wszystko od razu. Jeden przebieg naraz (flock `data/cron-auto-publish.run.lock`); ręczne „Uruchom teraz” (`--manual`) wyłącza zaplanowany przebieg tego dnia (patrz docs/AUTO-PUBLISH.md)
- `api/cron-indexation.php --token=X` — skan GSC URL Inspection wszystkich stron → `index_status` + dzienna migawka `index_snapshots` (zasila zakładkę Indeksacja). **Tylko CLI** — przebieg trwa kilka godzin, po HTTP padnie na timeoucie.

- `bin/worker.php` (CLI, **co minutę**) — kolejka: ręczne przebiegi auto-publikacji i zadania generowania artykułów

Przy dodawaniu nowego crona: dopisz go do crontaba na prodzie **oraz** do README i tej listy.
Zakładka Indeksacja stała 2 dni z zamrożonymi danymi, bo `cron-indexation.php` powstał
razem z zakładką, ale nikt go nie wpiął w crontab — kod bez wpisu w crontabie to martwy kod.

## Konwencje kodu

- **PHP:** brak typów w sygnaturach (poza `getDb(): SQLite3` i nowszych). Proceduralne endpointy w `api/`, funkcje w `includes/`. Prepared statements zawsze.
- **JS:** vanilla, bez modułów. Globalne funkcje. `api(method, url, body)` = fetch wrapper. `esc(str)` = HTML escape. `showToast(msg, type)` = Bootstrap toast. Sortowanie/filtrowanie client-side.
- **CSS:** zmienne w `:root`, klasy `.stat-card` (analytics tiles), `.content-card`, `.session-timer`, `.searchable-select`. Nazewnictwo BEM-like.
- **Tabler CSS variables:** `--tblr-bg-surface`, `--tblr-border-color`, `--tblr-body-color`. NIE używaj `--tblr-card-bg` (nie istnieje w Tabler 1.4 — było źródłem bugów w light mode).
- **Theme toggle:** klasy Tablera `.hide-theme-light` / `.hide-theme-dark` do warunkowego renderowania. Klik na sun/moon w topbarze zapisuje `localStorage['tabler-theme']` = `light` | `dark`.
- **Migracje DB:** zawsze przez `PRAGMA table_info` check + `ALTER TABLE ADD COLUMN` — nigdy DROP/RENAME (brak w SQLite bez rebuild).
- **Język UI:** polski (etykiety, komunikaty). Komentarze w kodzie mieszane PL/EN.

## Ważne szczegóły / gotcha

- **Frontend jest SPA-like:** `pages/*.php` renderuje pusty skeleton, `app.js` pobiera dane i renderuje dynamicznie
- **Kategorie stron** (`sites.categories`) są comma-separated. Filtr kategorii używa `LOWER(',' || REPLACE(categories, ', ', ',') || ',') LIKE '%,kat,%'` — dokładny match bez false positive substring.
- **Anty-dupe dla auto-publish** (patrz `includes/topic_generator.php`): trzy warstwy — pełna historia queue+publications w promcie, normalizacja kluczy (lower + polskie znaki→ascii + tylko alfanum), post-filtr LLM response.
- **XLSX parser** (`api/parse-bulk-file.php`) — własny w PHP, bez PhpSpreadsheet. Czyta SharedStrings + arkusz 1, obsługuje inline strings i formula strings.
- **QR code na 2FA setup** — biblioteka `qrcode-generator@1.4.4` w `assets/vendor/qrcode/qrcode.min.js` (nie CDN — CDN qrcode@1.5.3 miało 404, a `/lib/browser.min.js` to CommonJS bez browser bundle).
- **Global JS state:** `gscDashboardData`, `articles`, `bulkArticles`, `bulkSitesList`, `bulkWpDataCache`, `publishedUrls`, `articlesData`, `sitesData`. Jak `null` — kolumny/tabele ukryte.
- **Hosting blokuje `exec()`** — strona nie może startować procesów. Długie zadania wykonuje `bin/worker.php` z crona co minutę (`includes/worker_queue.php`): „Uruchom teraz” zapisuje `settings.auto_publish_run_requested`, generowanie pojedyncze/masowe tworzy wpis w `generation_jobs`, frontend odpytuje `api/generate-job.php`. Worker zapisuje `settings.worker_heartbeat`; bez świeżego heartbeatu generowanie przechodzi na synchroniczne `api/generate-article.php` (keepalive), a „Uruchom teraz” zwraca błąd z instrukcją.
- **Audyt artykułu jest automatyczny** (`includes/article_validator.php`): reguły skilla seo-blog-post-writer-v2. Zmieniając prompt, zmień też walidator, i odwrotnie.
- **Session timer w topbarze** (`assets/js/session-timer.js`) — poll `/api/session-info.php` co 60s, tick locally 1s, warning <30min, danger pulse <5min.

## Dokumentacja szczegółowa

- `docs/DEPLOY.md` — flow deployu, jak odzyskać się z popsutego stanu, jak debug przez SSH
- `docs/AUTH-2FA.md` — cały flow 2FA, session config, lockout, escape hatchy
- `docs/AUTO-PUBLISH.md` — content plan → queue → cron, category mapping, AI refill
- `docs/CLI-TOOLS.md` — wszystkie skrypty w `bin/` z przykładami
- `docs/DATABASE.md` — pełna schema + migracja + query patterns
