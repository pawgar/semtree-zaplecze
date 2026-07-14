# Deploy

**Reguła nr 1:** deploy TYLKO przez `git push origin master`. Serwer prod ma auto-pull z GitHub. SSH służy WYŁĄCZNIE do debugowania i skryptów CLI z `bin/`.

Nigdy nie edytuj plików bezpośrednio na serwerze przez FTP/cPanel — zostaną nadpisane przy następnym pullu.

## Standardowy cykl

```bash
# 1. Zmień pliki lokalnie
# 2. Test składni
php -l zmieniony/plik.php
node -c assets/js/app.js   # jeśli ruszałeś JS

# 3. Commit
git add zmienione/pliki
git commit -m "Krótki opis co i po co"

# 4. Push (server pociągnie automatycznie)
git push origin master
```

Serwer prod pociąga commit w ciągu ~1 minuty. Jeśli zmieniałeś PHP + baza migruje, pierwsze wywołanie `getDb()` na żywej stronie odpali `migrateSchema()` idempotentnie.

## Cache

- **Przeglądarka:** `Ctrl+Shift+R` po każdym pushu który dotknął CSS/JS. Style + `app.js` mają `?v=filemtime()` w URL-u, więc powinny się odświeżyć same, ale favicony i vendor libs cachują się agresywnie.
- **PHP OPcache:** niektóre hostingi trzymają skompilowany bytecode przez kilka minut. Jeśli po pushu PHP nadal działa "po staremu" mimo pull-a, to jest ten sprawca. Zwykle mija samo w <5 min.

## Rollback

Nie ma dedykowanego mechanizmu — po prostu `git revert <commit>` + push.

```bash
# Cofnij ostatni commit (tworzy nowy, odwrotny)
git revert HEAD
git push origin master

# Cofnij dowolny commit z historii
git revert <sha>
git push origin master
```

## Debug przez SSH

Kiedy coś nie działa i logi Apache/PHP nie wystarczają:

```bash
# 1. SSH na serwer
ssh user@zaplecze.semtree.com.pl

# 2. Wejdź do katalogu aplikacji (typowo w home)
cd domains/zaplecze.semtree.com.pl/public_html
# lub cd public_html — sprawdź gdzie git repo jest

# 3. Sprawdź czy jesteś na bieżącym commicie
git log --oneline -5

# 4. Jeśli nie — force pull (na wypadek gdyby auto-deploy zawiódł)
git pull origin master

# 5. Uruchom CLI z bin/ (nie modyfikuj plików!)
php bin/2fa-debug.php            # diagnostyka 2FA
php bin/list-articles.php --help # listowanie artykułów
# itd. — patrz docs/CLI-TOOLS.md
```

## Pierwsza konfiguracja świeżej instalacji

1. Wgraj repo na serwer (git clone) do `public_html/` lub równorzędnego
2. Katalog `data/` musi być writable dla PHP (`chmod 755 data/`)
3. Otwórz `https://twoja-domena/` w przeglądarce
4. Baza + admin (`admin`/`admin`) tworzą się automatycznie przy 1. requeście
5. `data/app_key.php` wygeneruje się przy 1. użyciu 2FA (klik "Włącz 2FA")
6. `data/sessions/` powstanie przy 1. `session_start()`
7. Zaloguj się, ZMIEŃ HASŁO w Profilu, skonfiguruj 2FA
8. Ustawienia → klucze API (Claude, Gemini, GSC)
9. Cron w crontabie hostingu (patrz README.md → CRON)

## Co NIE trafia do gita

Sprawdź `.gitignore`:
- `data/database.sqlite`, `data/*.sqlite`, `data/*.db`
- `data/app_key.php` — utrata = wszyscy userzy muszą przepiąć 2FA
- `data/sessions/` (nawet katalog nie powinien być trackowany)
- `config.json` (jeśli tworzysz lokalne overridy)
- `CLAUDE.local.md` — prywatne notatki

Jeśli dodajesz nowy plik generowany runtime do `data/`, dopisz go do `.gitignore` W TYM SAMYM COMMICIE.
