# Semtree Zaplecze

Aplikacja webowa do zarządzania siecią stron zapleczowych WordPress (PBN). Centralne monitorowanie statusów, publikowanie artykułów, zarządzanie linkami afiliacyjnymi i integracja z Google Search Console.

## Wymagania

- PHP 8.0+ z rozszerzeniami: SQLite3, cURL, GD
- Apache z `mod_rewrite` (lub nginx z odpowiednim konfigurem)
- HTTPS (wymagany przez WordPress Application Passwords)
- WordPress 5.6+ na stronach zapleczowych (Application Passwords)

## Instalacja

1. Wgraj pliki na serwer webowy
2. Upewnij się, że katalog `data/` jest zapisywalny przez PHP (`chmod 755 data/`)
3. Otwórz w przeglądarce — baza danych i konto admina utworzą się automatycznie
4. Zaloguj się: `admin` / `admin`
5. **Zmień domyślne hasło** w Ustawienia > Profil

## Konfiguracja

### Klucze API (Ustawienia)

| Klucz | Do czego | Wymagany |
|-------|----------|----------|
| Anthropic API Key | Generowanie artykułów AI (Claude) | Opcjonalny |
| Gemini API Key | Generowanie obrazów AI | Opcjonalny |
| GSC Client ID + Secret | Dane z Google Search Console | Opcjonalny |

### Google Search Console

1. Utwórz projekt w [Google Cloud Console](https://console.cloud.google.com/)
2. Aktywuj Search Console API
3. Utwórz OAuth 2.0 credentials (Web application)
4. Redirect URI: `https://twoja-domena.pl/api/gsc-callback.php`
5. Wklej Client ID i Client Secret w Ustawieniach
6. Kliknij "Połącz" i autoryzuj kontem Google z dostępem do GSC

### CRON (automatyczne odświeżanie)

Ustaw w crontab serwera:

```bash
# Odświeżanie statusów stron + skanowanie linków (codziennie o 23:00)
0 23 * * * curl -s "https://twoja-domena.pl/api/cron-status.php?token=TWOJ_TOKEN" > /dev/null

# Odświeżanie danych GSC (codziennie o 6:00)
0 6 * * * curl -s "https://twoja-domena.pl/api/cron-gsc.php?token=TWOJ_TOKEN" > /dev/null
```

Token CRON ustawisz w Ustawieniach aplikacji.

## Funkcjonalności

### Dashboard
- Lista wszystkich stron zapleczowych ze statusami HTTP/API
- Kafelki sumaryczne (stron, wpisów, linków, błędów, dane GSC)
- Sortowanie i filtrowanie po kategoriach/klientach
- Szybki dostęp do karty strony, publikacji, edycji

### Zarządzanie stronami
- Dodawanie/edycja/usuwanie stron z danymi WordPress (URL, login, app password)
- Kategorie do organizacji stron
- Import/eksport CSV (separator: `;`)
- Monitorowanie statusu HTTP i API

### Zarządzanie linkami
- Automatyczne skanowanie wpisów WordPress w poszukiwaniu linków zewnętrznych
- Przypisywanie linków do klientów/domen afiliacyjnych
- Filtrowanie linków po stronie, kliencie, typie (dofollow/nofollow)
- Usuwanie linków z wpisów WordPress (zachowuje tekst, usuwa tag `<a>`)

### Publikowanie artykułów
- Generowanie artykułów przez AI (Claude API)
- Upload obrazków z optymalizacją (GD library)
- Import artykułów z plików DOCX
- Wybór autora, kategorii, daty publikacji
- Zamówienia zbiorcze z pliku CSV/XLSX

### Google Search Console
- Dane o kliknięciach, wyświetleniach, pozycjach
- Raporty per strona z wykresami Chart.js
- Zbiorczy raport GSC ze sparkline'ami
- Cache danych (6h TTL) z automatycznym odświeżaniem przez CRON

### Zarządzanie użytkownikami
- Role: Admin (pełny dostęp) i Worker (ograniczony)
- Statystyki publikacji per użytkownik
- Historia aktywności

## Role i uprawnienia

| Funkcja | Admin | Worker |
|---------|-------|--------|
| Przeglądanie stron i statusów | Tak | Tak |
| Dodawanie/edycja/usuwanie stron | Tak | Nie |
| Import/eksport CSV | Tak | Nie |
| Zarządzanie użytkownikami | Tak | Nie |
| Publikowanie artykułów | Tak | Tak |
| Zarządzanie linkami | Tak | Tak |
| Ustawienia aplikacji | Tak | Nie |
| Raport GSC | Tak | Tak |

## Format CSV

```
name;url;username;app_password
MojaStrona;https://example.com;admin;XXXX XXXX XXXX XXXX XXXX XXXX
```

## Struktura plików

```
/api/           — Endpointy REST API (JSON)
/pages/         — Szablony stron (PHP + HTML)
/includes/      — Biblioteki (GSC API, WP API, parsery)
/assets/css/    — Arkusze stylów
/assets/js/     — JavaScript frontendowy
/data/          — Baza danych SQLite (automatycznie tworzona)
```

## Bezpieczeństwo

- Hasła hashowane bcrypt
- Sesje PHP z 24h limitem
- `.htaccess` blokuje dostęp do: `data/`, `config.php`, `db.php`, `auth.php`
- Prepared statements (ochrona przed SQL injection)
- Endpointy CRON chronione tokenem
- HTTPS wymagany dla WordPress Application Passwords

## Rozwiązywanie problemów

| Problem | Rozwiązanie |
|---------|-------------|
| Baza się nie tworzy | Sprawdź uprawnienia zapisu do katalogu `data/` |
| API WordPress nie działa | Sprawdź czy strona ma HTTPS i włączone Application Passwords |
| GSC "invalid_client" | Sprawdź czy Client ID zawiera prefix numeru projektu Google |
| GSC brak wyboru konta | OAuth prompt musi zawierać `select_account consent` |
| CRON nie działa | Sprawdź token w URL i w Ustawieniach aplikacji |
| Linki się nie zliczają | Uruchom ręczne odświeżenie statusów (skanuje linki) |
