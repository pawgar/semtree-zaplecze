<?php
/**
 * Senuto REST API — keyword research dla treści PL.
 * Port 1:1 z Ateny (app/Services/SenutoApiService.php), przepisany na funkcje + cURL.
 *
 * Autoryzacja: POST /api/users/token (email + hasło) -> JWT ważny ~30 dni.
 * Token odświeżany sam: gdy do wygaśnięcia zostało <5 dni albo request zwróci 401.
 * Hasło trzymane zaszyfrowane (AES-256-GCM, includes/encryption.php), tak jak w Hermesie.
 *
 * Settings: senuto_email, senuto_password_enc, senuto_bearer_token, senuto_token_expires_at
 *
 * Country IDs (endpointy keywords_analysis NIE przyjmują 200): pl = 1
 * Wyniki cachowane w senuto_cache na 30 dni (oszczędza punkty Senuto).
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/encryption.php';

const SENUTO_BASE_URL = 'https://api.senuto.com';
const SENUTO_CACHE_DAYS = 30;
const SENUTO_COUNTRY_IDS = ['pl' => 1, 'en' => 50, 'de' => 49, 'es' => 34, 'cs' => 420, 'nl' => 31];

function senutoSetting(string $key): string {
    $stmt = getDb()->prepare('SELECT value FROM settings WHERE key = :k');
    $stmt->bindValue(':k', $key, SQLITE3_TEXT);
    $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    return $row ? (string)$row['value'] : '';
}

function senutoSetSetting(string $key, string $value): void {
    $stmt = getDb()->prepare('INSERT OR REPLACE INTO settings (key, value) VALUES (:k, :v)');
    $stmt->bindValue(':k', $key, SQLITE3_TEXT);
    $stmt->bindValue(':v', $value, SQLITE3_TEXT);
    $stmt->execute();
}

function senutoConfigured(): bool {
    return senutoSetting('senuto_email') !== '' && senutoSetting('senuto_password_enc') !== '';
}

function senutoPassword(): string {
    $enc = senutoSetting('senuto_password_enc');
    if ($enc === '') return '';
    try { return decryptString($enc); } catch (Throwable $e) { return ''; }
}

/** @return array{ok:bool, message:string} */
function senutoTestConnection(): array {
    if (!senutoConfigured()) return ['ok' => false, 'message' => 'Brak e-maila lub hasła Senuto.'];
    try {
        senutoRefreshToken();
        $resp = senutoHttp('GET', '/api/users/getLoggedUser');
    } catch (Throwable $e) {
        return ['ok' => false, 'message' => $e->getMessage()];
    }
    $email = $resp['data']['email'] ?? $resp['email'] ?? 'nieznany';
    $expires = senutoSetting('senuto_token_expires_at') ?: 'nieznane';
    return ['ok' => true, 'message' => "Połączenie OK. Konto: {$email}. Token ważny do: {$expires}."];
}

// ── Endpointy (te same parametry co w Atenie) ───────────────

function senutoGetKeywords(string $seed, string $lang = 'pl', int $limit = 50, string $matchMode = 'wide'): array {
    return senutoCached('keywords', [$seed, $lang, $limit, $matchMode], fn() => senutoExtractData(senutoPostJson(
        '/api/keywords_analysis/reports/keywords/getKeywords', [
            'parameters' => [['data_fetch_mode' => 'keyword', 'value' => [$seed]]],
            'country_id' => (string)senutoCountryId($lang),
            'match_mode' => $matchMode,
            'filtering'  => [['filters' => []]],
            'limit'      => $limit,
        ])));
}

function senutoGetQuestions(string $seed, string $lang = 'pl', int $limit = 30): array {
    return senutoCached('questions', [$seed, $lang, $limit], fn() => senutoExtractData(senutoPostJson(
        '/api/keywords_analysis/reports/keywords/getQuestions', [
            'parameters' => [['data_fetch_mode' => 'keyword', 'value' => [$seed]]],
            'country_id' => (string)senutoCountryId($lang),
            'match_mode' => 'wide',
            'filtering'  => [['filters' => []]],
            'limit'      => $limit,
        ])));
}

function senutoGetGroups(string $seed, string $lang = 'pl', int $limit = 20): array {
    return senutoCached('groups', [$seed, $lang, $limit], fn() => senutoExtractData(senutoPostJson(
        '/api/keywords_analysis/reports/keywords/getGroups', [
            'parameters' => [['data_fetch_mode' => 'keyword', 'value' => [$seed]]],
            'country_id' => (string)senutoCountryId($lang),
            'match_mode' => 'wide',
            'filtering'  => [['filters' => []]],
            'limit'      => $limit,
        ])));
}

// ── Cache ───────────────────────────────────────────────────

function senutoCached(string $type, array $params, callable $fetch): array {
    $db = getDb();
    $key = $type . ':' . md5(json_encode($params, JSON_UNESCAPED_UNICODE));
    $stmt = $db->prepare('SELECT data FROM senuto_cache WHERE cache_key = :k AND fetched_at > datetime("now", :age)');
    $stmt->bindValue(':k', $key, SQLITE3_TEXT);
    $stmt->bindValue(':age', '-' . SENUTO_CACHE_DAYS . ' days', SQLITE3_TEXT);
    $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    if ($row) {
        $data = json_decode($row['data'], true);
        if (is_array($data)) return $data;
    }
    $data = $fetch();
    $stmt = $db->prepare('INSERT OR REPLACE INTO senuto_cache (cache_key, data, fetched_at) VALUES (:k, :d, datetime("now"))');
    $stmt->bindValue(':k', $key, SQLITE3_TEXT);
    $stmt->bindValue(':d', json_encode($data, JSON_UNESCAPED_UNICODE), SQLITE3_TEXT);
    $stmt->execute();
    return $data;
}

// ── Token ───────────────────────────────────────────────────

function senutoEnsureValidToken(): void {
    $token = senutoSetting('senuto_bearer_token');
    $expires = senutoSetting('senuto_token_expires_at');
    if ($token === '') { senutoRefreshToken(); return; }
    if ($expires !== '') {
        $expTime = strtotime($expires);
        if ($expTime !== false && $expTime - time() < 5 * 86400) senutoRefreshToken();
    }
}

function senutoRefreshToken(): void {
    $email = senutoSetting('senuto_email');
    $password = senutoPassword();
    if ($email === '' || $password === '') {
        throw new RuntimeException('Senuto: brak e-maila/hasła do odświeżenia tokenu.');
    }
    $ch = curl_init(SENUTO_BASE_URL . '/api/users/token');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_POSTFIELDS => json_encode(['email' => $email, 'password' => $password]),
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);
    $body = curl_exec($ch);
    $err = curl_errno($ch) ? curl_error($ch) : '';
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($err) throw new RuntimeException('Senuto token request failed: ' . $err);
    if ($code !== 200) throw new RuntimeException("Senuto token: HTTP $code — " . substr((string)$body, 0, 200));

    $data = json_decode((string)$body, true) ?? [];
    $token = $data['data']['token'] ?? $data['token'] ?? $data['bearer_token'] ?? null;
    if (!$token) throw new RuntimeException('Senuto token: brak tokenu w odpowiedzi.');

    senutoSetSetting('senuto_bearer_token', (string)$token);
    senutoSetSetting('senuto_token_expires_at', senutoJwtExpiry((string)$token) ?? date('c', strtotime('+30 days')));
}

function senutoJwtExpiry(string $jwt): ?string {
    $parts = explode('.', $jwt);
    if (count($parts) < 2) return null;
    $payload = base64_decode(strtr($parts[1], '-_', '+/') . str_repeat('=', (4 - strlen($parts[1]) % 4) % 4));
    $data = $payload ? json_decode($payload, true) : null;
    return isset($data['exp']) ? date('c', (int)$data['exp']) : null;
}

// ── HTTP ────────────────────────────────────────────────────

function senutoPostJson(string $path, array $body): array {
    senutoEnsureValidToken();
    return senutoHttp('POST', $path, $body);
}

function senutoHttp(string $method, string $path, ?array $body = null, bool $retried = false): array {
    $ch = curl_init(SENUTO_BASE_URL . $path);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . senutoSetting('senuto_bearer_token'),
            'Accept: application/json',
            'Content-Type: application/json',
        ],
        CURLOPT_TIMEOUT => 60,
        CURLOPT_CONNECTTIMEOUT => 5,
    ];
    if ($method === 'POST') {
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = json_encode($body ?? []);
    }
    curl_setopt_array($ch, $opts);
    $resp = curl_exec($ch);
    $err = curl_errno($ch) ? curl_error($ch) : '';
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($err) throw new RuntimeException("Senuto $path: $err");

    if ($code === 401 && !$retried) {
        // Token mógł wygasnąć mimo sprawdzenia daty — odśwież i ponów raz
        senutoRefreshToken();
        return senutoHttp($method, $path, $body, true);
    }
    if ($code !== 200) throw new RuntimeException("Senuto $path: HTTP $code — " . substr((string)$resp, 0, 300));

    $decoded = json_decode((string)$resp, true);
    return is_array($decoded) ? $decoded : [];
}

function senutoExtractData(array $resp): array {
    if (isset($resp['data']) && is_array($resp['data'])) return $resp['data'];
    if (isset($resp['result']) && is_array($resp['result'])) return $resp['result'];
    return $resp;
}

function senutoCountryId(string $lang): int {
    return SENUTO_COUNTRY_IDS[strtolower($lang)] ?? 1;
}
