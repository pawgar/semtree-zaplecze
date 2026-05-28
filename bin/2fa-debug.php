<?php
/**
 * CLI debug + recovery tool for 2FA.
 *
 *   php bin/2fa-debug.php                     — status (all users + server time)
 *   php bin/2fa-debug.php --unlock <user>     — clear lockout / failed counter
 *   php bin/2fa-debug.php --disable <user>    — fully disable 2FA (escape hatch)
 *   php bin/2fa-debug.php --server-time       — server vs UTC time + TOTP step
 *
 * Designed to be run via SSH on the server when admin gets locked out.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script must be run from the command line.\n");
}

require __DIR__ . '/../auth.php';

$cmd = $argv[1] ?? '--status';
$target = $argv[2] ?? null;

function line($s = '') { echo $s . "\n"; }
function hr() { line(str_repeat('─', 60)); }

function showStatus(): void {
    $db = getDb();
    hr();
    line("Server time:    " . date('Y-m-d H:i:s T') . "  (UTC: " . gmdate('Y-m-d H:i:s') . ")");
    line("Unix time:      " . time());
    line("TOTP step (30s): " . floor(time() / 30));
    line("PHP TZ:         " . date_default_timezone_get());
    line("ABSOLUTE_SESSION_SECONDS: " . ABSOLUTE_SESSION_SECONDS);
    line("TOTP_WINDOW:    " . TOTP_WINDOW . " (±" . (TOTP_WINDOW * 30) . "s tolerance)");
    line("MAX_FAILED:     " . MAX_FAILED_ATTEMPTS . " / LOCKOUT: " . LOCKOUT_SECONDS . "s");
    hr();
    $appKeyPath = __DIR__ . '/../data/app_key.php';
    line("APP_KEY file:   " . $appKeyPath);
    if (is_file($appKeyPath)) {
        line("  exists:       YES  (mtime: " . date('Y-m-d H:i:s', filemtime($appKeyPath)) . ")");
        line("  size:         " . filesize($appKeyPath) . " bytes");
    } else {
        line("  exists:       NO — będzie wygenerowany przy pierwszym żądaniu szyfrowania");
    }
    try {
        $k = appKey();
        line("  loadable:     YES (key length: " . strlen($k) . " bytes)");
    } catch (Throwable $e) {
        line("  loadable:     NO — " . $e->getMessage());
    }
    hr();
    line(sprintf('%-4s %-20s %-8s %-9s %-8s %s', 'ID', 'USERNAME', '2FA', 'FAILED', 'LOCKED', 'ENABLED_AT'));
    hr();
    $r = $db->query('SELECT id, username, role, totp_enabled, totp_failed_attempts, totp_locked_until, totp_enabled_at, totp_secret FROM users ORDER BY id');
    while ($u = $r->fetchArray(SQLITE3_ASSOC)) {
        $hasSecret = !empty($u['totp_secret']);
        $enabled   = (int)$u['totp_enabled'] === 1 ? 'ON' : ($hasSecret ? 'setup' : 'off');
        $locked    = !empty($u['totp_locked_until']) && strtotime($u['totp_locked_until']) > time()
                     ? 'LOCK(' . max(0, strtotime($u['totp_locked_until']) - time()) . 's)'
                     : '-';
        line(sprintf('%-4d %-20s %-8s %-9d %-8s %s',
            $u['id'], $u['username'], $enabled,
            (int)$u['totp_failed_attempts'], $locked,
            $u['totp_enabled_at'] ?? '-'));
    }
    hr();
}

function findUser(string $name): ?array {
    $db = getDb();
    $stmt = $db->prepare('SELECT id, username FROM users WHERE username = :u');
    $stmt->bindValue(':u', $name, SQLITE3_TEXT);
    $r = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    return $r ?: null;
}

switch ($cmd) {
    case '--status':
    case 'status':
        showStatus();
        break;

    case '--server-time':
    case 'server-time':
        line("Server time: " . date('Y-m-d H:i:s T'));
        line("UTC:         " . gmdate('Y-m-d H:i:s'));
        line("Unix:        " . time());
        line("Sprawdź time.is/UTC na telefonie — różnica >60s = drift zegara serwera.");
        break;

    case '--unlock':
    case 'unlock':
        if (!$target) { line("Użycie: php bin/2fa-debug.php --unlock <username>"); exit(1); }
        $u = findUser($target);
        if (!$u) { line("Brak użytkownika: $target"); exit(1); }
        $db = getDb();
        $stmt = $db->prepare('UPDATE users SET totp_failed_attempts = 0, totp_locked_until = NULL WHERE id = :id');
        $stmt->bindValue(':id', $u['id'], SQLITE3_INTEGER);
        $stmt->execute();
        line("✔ Odblokowano: {$u['username']} (id={$u['id']}) — licznik prób wyzerowany, lockout usunięty.");
        break;

    case '--disable':
    case 'disable':
        if (!$target) { line("Użycie: php bin/2fa-debug.php --disable <username>"); exit(1); }
        $u = findUser($target);
        if (!$u) { line("Brak użytkownika: $target"); exit(1); }
        tfaDisable((int)$u['id']);
        line("✔ Wyłączono 2FA dla: {$u['username']} — przy następnym logowaniu zostanie poproszony o nową konfigurację.");
        break;

    default:
        line("Nieznana komenda: $cmd");
        line("");
        line("Dostępne:");
        line("  --status              status wszystkich userów + zegar serwera");
        line("  --server-time         tylko zegar serwera vs UTC");
        line("  --unlock <user>       wyzeruj licznik prób + zdejmij lockout");
        line("  --disable <user>      ewakuacja: wyłącz 2FA u usera (zostanie poproszony o setup)");
        exit(1);
}
