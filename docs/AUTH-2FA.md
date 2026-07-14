# Autentykacja + 2FA + sesje

Cały system: hasło → OTP (obowiązkowe) → 24h absolute session.

## Konfiguracja PHP sesji

**Krytyczne:** domyślne wartości PHP są nieprzyjazne dla 24h sesji. Nasz `auth.php:startSession()` nadpisuje je PRZED `session_start()`:

| Ustawienie | Default PHP | Nasze | Dlaczego |
|---|---|---|---|
| `session.gc_maxlifetime` | 1440s (24 min) | 86400s (24h) | Inaczej GC kasuje pliki sesji po 24 minutach |
| `session.cookie_lifetime` | 0 (do zamknięcia karty) | 86400s | Cookie znikało po zamknięciu przeglądarki |
| `session_save_path` | `/tmp` (shared) | `data/sessions/` | Na shared hostingu `/tmp` jest wspólny z innymi vhostami — systemowy cron czyścił wg **najkrótszego** gc_maxlifetime |
| HttpOnly / SameSite / Secure | zależy od hostingu | 1 / Lax / auto (HTTPS) | Bezpieczeństwo |

**Zmiana `session_save_path` deloguje wszystkich raz.** Świadome przy pierwszym deployu — session files pod nową ścieżką nie istnieją.

## Flow logowania

```
┌─── login.php POST username+password ───┐
│  password_verify()                     │
│    ├─ FAIL       → "invalid"           │
│    └─ OK ──┐                           │
│            ├─ user ma totp_enabled=1   │
│            │    └─ pending_2fa flag    │
│            │       + redirect          │
│            │       → login-2fa.php     │
│            └─ user BEZ totp_enabled=1  │
│                 └─ full session +      │
│                    gate na 2fa-required│
└────────────────────────────────────────┘

login-2fa.php POST code
  ├─ isRecovery?
  │   └─ tfaConsumeRecoveryCode(id, code)
  │      (immune na lockout, jednorazowe)
  └─ nie:
      └─ tfaVerifyOtp(id, code)
         (lockout: 10 nieudanych → 15 min)
  → OK: finalizeLoginSession() (regen ID, login_at=now)
  → FAIL: register failure, pokaż diagnostyk

2fa-required.php (dedykowany wizard)
  ├─ Setup: /api/2fa-setup.php generuje sekret + QR + otpauth URL
  ├─ Enable: /api/2fa-enable.php weryfikuje kod, aktywuje, zwraca 8 recovery codes
  └─ Recovery codes displayed ONCE, potem gate zdjęty
```

## Hard gate — 2FA wymagane dla wszystkich

W `index.php` po `isLoggedIn()`:

```php
if (!hasTwoFactorEnabled()) {
    $allowedWithout2FA = ['2fa-required', 'logout'];
    if (!in_array($page, $allowedWithout2FA, true)) {
        header('Location: index.php?page=2fa-required');
        exit;
    }
}
```

W `auth.php:requireLoginApi()` / `requireAdminApi()`:
```php
enforceTwoFactorOnApi();  // whitelist: 2fa-setup, 2fa-enable, session-info
```

Bez whitelisty nie dałoby się nawet skonfigurować 2FA.

## TOTP (RFC 6238)

Własna implementacja w `includes/totp.php` — zero Composera:
- Base32 encode/decode
- HMAC-SHA1 na 8-bajtowym big-endian counter
- 6 cyfr, krok 30s
- Window: `TOTP_WINDOW=2` (±60s clock skew tolerance)
- Testy referencyjne RFC 6238 (`time=59` → `287082`, `1111111109` → `081804`) — smoke check w konsoli

Sekrety przechowywane szyfrowane AES-256-GCM (`includes/encryption.php`) z kluczem `appKey()` (patrz niżej).

## Recovery codes

- 8 kodów przy aktywacji, format `XXXX-XXXX`
- Bcrypt hash + `used: bool` w `users.totp_recovery_codes` (JSON)
- **Nie blokowane przez lockout** (bezpieczeństwo brute-force pokrywa bcrypt cost + one-time-use)
- Można wygenerować nowe przez UI (wymaga aktualnego hasła) lub w awarii przez CLI:
  ```bash
  # Reset 2FA usera (usuwa sekret + kody, wymusza nową konfigurację przy 1. loginie)
  php bin/2fa-debug.php --disable <username>
  ```

## Lockout

- `MAX_FAILED_ATTEMPTS = 10` w `config.php`
- `LOCKOUT_SECONDS = 900` (15 min)
- Dotyczy TYLKO OTP; recovery codes działają zawsze (escape hatch dla utraconego telefonu)
- Nie blokuje kroku hasła — user zawsze może przejść do 2FA challenge i wpisać recovery
- Odblokowanie ręczne:
  ```bash
  php bin/2fa-debug.php --unlock <username>
  ```

## APP_KEY — klucz szyfrujący sekrety

`appKey()` w `config.php`:
- 32 bajty (256 bit) w `data/app_key.php`
- Auto-generowany przy 1. użyciu (zapis `<?php return 'base64...';`)
- **NIE trafia do gita** (`.gitignore`)
- **Utrata = wszystkie sekrety 2FA nie do odszyfrowania** → wszyscy userzy muszą przepiąć aplikację

**Backup strategy:** zrób kopię `data/app_key.php` po pierwszym uruchomieniu i trzymaj poza serwerem. Bez tego migracja/reinstall/awaria dysku = katastrofa.

## Diagnostyka

Z panelu (widoczne przy nieudanym OTP):
- "Wpisany kod" vs "Oczekiwany TERAZ"
- Poprzedni krok (-30s), Następny (+30s)
- Czas serwera UTC
- Skrót sekretu (potwierdzenie że odszyfrowany OK)

Przez SSH:
```bash
php bin/2fa-debug.php                # status wszystkich userów + zegar
php bin/2fa-debug.php --server-time  # tylko czas serwera vs UTC
php bin/2fa-debug.php --unlock USER  # zdejmij lockout
php bin/2fa-debug.php --disable USER # nuklearka: wyłącz 2FA (wymusi nowy setup)
```

## Częste problemy

| Objaw | Prawdopodobna przyczyna | Naprawa |
|---|---|---|
| User loguje się co godzinę mimo 24h sesji | PHP `gc_maxlifetime` nie ustawiony / `save_path=/tmp` | Sprawdź `auth.php:startSession()` |
| Wszystkie kody 2FA odrzucane | Drift zegara serwera >±60s | Sprawdź `bin/2fa-debug.php --server-time` vs `time.is/UTC` |
| Wszystkie kody 2FA odrzucane, drift <60s | Aplikacja Authenticator ma stary sekret | Wyłącz w niej wpis Semtree, przepnij od nowa |
| "Sekret nie odszyfrował się" | `data/app_key.php` zregenerowany po aktywacji | Restore z backupu ALBO reset 2FA usera |
| Recovery code odrzucony | Case-sensitive OR spacja | Kody są normalizowane (upper, no dashes) — sprawdź czy w bazie są bcrypty znormalizowanej formy |
| User zablokowany, brak recovery codes | Ostatnia deska | `php bin/2fa-debug.php --disable USER` |
