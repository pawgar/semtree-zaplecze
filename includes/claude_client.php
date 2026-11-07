<?php
/**
 * Wspólny klient Claude Messages API (raw cURL — projekt bez Composera).
 *
 * - retry z backoffem dla 429 / 5xx / 529 / timeoutów (wcześniej każdy z tych błędów
 *   kończył wpis w kolejce jako "error" bez ponowienia)
 * - streaming (SSE) dla długich generacji — brak ryzyka timeoutu przy dużym max_tokens,
 *   plus callback postępu (podtrzymanie połączenia HTTP w generowaniu pojedynczym)
 * - prompt caching: system prompt wysyłany jako blok z cache_control (identyczny dla
 *   każdego artykułu, więc kolejne requesty czytają go z cache)
 * - obsługa pause_turn dla narzędzi serwerowych (web_search / web_fetch)
 * - server-side fallback przy odmowie (stop_reason = refusal)
 */

require_once __DIR__ . '/../db.php';

const CLAUDE_DEFAULT_MODEL  = 'claude-sonnet-5-5';
const CLAUDE_DEFAULT_EFFORT = 'medium';
const CLAUDE_AUX_MODEL      = 'claude-haiku-5-5';   // tanie zadania pomocnicze (filtr trafności fraz)

// Modele wybieralne w Ustawieniach (wartość => etykieta)
function claudeModelOptions(): array {
    return [
        'claude-sonnet-5-5' => 'Claude Sonnet 5.5 (domyślny)',
        'claude-opus-5-5'   => 'Claude Opus 5.5 (wyższa jakość, 2x droższy)',
    ];
}

function claudeEffortOptions(): array {
    return ['low' => 'Niski', 'medium' => 'Średni (domyślny)', 'high' => 'Wysoki'];
}

function claudeSetting(string $key): string {
    $stmt = getDb()->prepare('SELECT value FROM settings WHERE key = :k');
    $stmt->bindValue(':k', $key, SQLITE3_TEXT);
    $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    return $row ? trim((string)$row['value']) : '';
}

function claudeApiKey(): string {
    return claudeSetting('anthropic_api_key');
}

function claudeMainModel(): string {
    $model = claudeSetting('ai_model');
    // Stare wartości z listy sprzed aktualizacji (sonnet-5, opus-4-8, haiku-4-5) -> domyślny
    return array_key_exists($model, claudeModelOptions()) ? $model : CLAUDE_DEFAULT_MODEL;
}

function claudeMainEffort(): string {
    $effort = claudeSetting('ai_effort');
    return array_key_exists($effort, claudeEffortOptions()) ? $effort : CLAUDE_DEFAULT_EFFORT;
}

/**
 * Błąd z Claude API z informacją, czy warto ponowić (np. cały wpis w kolejce).
 */
class ClaudeApiException extends RuntimeException {
    public bool $retryable;
    public function __construct(string $message, bool $retryable = false) {
        parent::__construct($message);
        $this->retryable = $retryable;
    }
}

/**
 * Wywołanie Messages API.
 *
 * $opts:
 *   model, system (string), messages (array), max_tokens, effort (low|medium|high|xhigh|max),
 *   tools (array — narzędzia serwerowe), json_schema (array — structured output),
 *   stream (bool — domyślnie true bez narzędzi), on_progress (callable — wołane w trakcie streamu),
 *   timeout (s), retries
 *
 * Zwraca: ['text', 'content', 'usage', 'model', 'stop_reason']
 */
function claudeRequest(array $opts): array {
    $apiKey = claudeApiKey();
    if ($apiKey === '') throw new ClaudeApiException('Brak klucza Anthropic API w ustawieniach.');

    $model = $opts['model'] ?? claudeMainModel();
    $payload = claudeBuildPayload($opts, $model);

    // Przy odmowie klasyfikatora API samo przerzuca request na model zapasowy
    $betas = [];
    if (in_array($model, ['claude-opus-5-5', 'claude-sonnet-5-5'], true)) {
        $payload['fallbacks'] = 'default';
        $betas[] = 'server-side-fallback-2026-07-01';
    }

    return claudeRun($apiKey, $payload, $betas, $opts, $model);
}

/** Body requestu Messages API z opcji claudeRequest (bez fallbacków i streamu). */
function claudeBuildPayload(array $opts, string $model): array {
    $payload = [
        'model'      => $model,
        'max_tokens' => $opts['max_tokens'] ?? 32000,
        'messages'   => $opts['messages'],
    ];
    if (!empty($opts['system'])) {
        $payload['system'] = [[
            'type' => 'text',
            'text' => $opts['system'],
            'cache_control' => ['type' => 'ephemeral'],
        ]];
    }
    $outputConfig = [];
    if (!empty($opts['effort'])) $outputConfig['effort'] = $opts['effort'];
    if (!empty($opts['json_schema'])) {
        $outputConfig['format'] = ['type' => 'json_schema', 'schema' => $opts['json_schema']];
    }
    if ($outputConfig) $payload['output_config'] = $outputConfig;
    if (!empty($opts['tools'])) $payload['tools'] = $opts['tools'];
    return $payload;
}

function claudeRun(string $apiKey, array $payload, array $betas, array $opts, string $model): array {
    // Streaming tylko bez narzędzi: przy pause_turn trzeba odesłać bloki 1:1, a tych
    // nie odtwarzamy ze strumienia
    $stream = empty($opts['tools']) && ($opts['stream'] ?? true);
    $timeout = $opts['timeout'] ?? 600;
    $retries = $opts['retries'] ?? 3;
    $onProgress = $opts['on_progress'] ?? null;
    $usage = [];
    $content = [];

    for ($continuation = 0; $continuation < 5; $continuation++) {
        $data = claudeHttp($apiKey, $payload, $betas, $timeout, $retries, $stream, $onProgress);
        foreach (($data['usage'] ?? []) as $k => $v) {
            if (is_int($v)) $usage[$k] = ($usage[$k] ?? 0) + $v;
        }
        $searches = (int)($data['usage']['server_tool_use']['web_search_requests'] ?? 0);
        if ($searches) $usage['web_search_requests'] = ($usage['web_search_requests'] ?? 0) + $searches;
        $content = array_merge($content, $data['content'] ?? []);
        if (($data['stop_reason'] ?? '') !== 'pause_turn') break;
        // Narzędzia serwerowe przerwały turę po limicie iteracji — wznów (bez dodatkowej wiadomości usera)
        $payload['messages'][] = ['role' => 'assistant', 'content' => $data['content'] ?? []];
    }

    $stopReason = $data['stop_reason'] ?? '';
    if ($stopReason === 'refusal') {
        throw new ClaudeApiException('Claude odmówił wygenerowania treści (refusal).');
    }
    if ($stopReason === 'max_tokens') {
        throw new ClaudeApiException('Odpowiedź ucięta na limicie max_tokens.', true);
    }

    // Tekst z ostatniej odpowiedzi — przy narzędziach wcześniejsze bloki to komentarze między wywołaniami
    $text = '';
    foreach (($data['content'] ?? []) as $block) {
        if (($block['type'] ?? '') === 'text') $text .= $block['text'];
    }

    return [
        'text'        => $text,
        'content'     => $content,
        'usage'       => $usage,
        'model'       => $data['model'] ?? $model,
        'stop_reason' => $stopReason,
    ];
}

function claudeHttp(string $apiKey, array $payload, array $betas, int $timeout, int $retries, bool $stream, ?callable $onProgress): array {
    if ($stream) $payload['stream'] = true;

    $lastError = '';
    for ($attempt = 0; $attempt <= $retries; $attempt++) {
        if ($attempt > 0) sleep(min(60, 5 * (2 ** ($attempt - 1))) + rand(0, 3));

        $headers = [
            'Content-Type: application/json',
            'x-api-key: ' . $apiKey,
            'anthropic-version: 2023-06-01',
        ];
        if ($betas) $headers[] = 'anthropic-beta: ' . implode(',', $betas);

        $ch = curl_init('https://api.anthropic.com/v1/messages');
        $opts = [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 30,
        ];
        $sse = null;
        $raw = '';
        if ($stream) {
            $sse = new ClaudeSseAccumulator($onProgress);
            // Zerwany strumień (brak danych przez 120 s) = błąd do ponowienia
            $opts[CURLOPT_LOW_SPEED_LIMIT] = 1;
            $opts[CURLOPT_LOW_SPEED_TIME] = 120;
            $opts[CURLOPT_WRITEFUNCTION] = function ($ch, $chunk) use ($sse, &$raw) {
                $raw .= strlen($raw) < 20000 ? $chunk : '';   // do komunikatu błędu przy HTTP != 200
                $sse->feed($chunk);
                return strlen($chunk);
            };
        } else {
            $opts[CURLOPT_RETURNTRANSFER] = true;
        }
        curl_setopt_array($ch, $opts);
        $response = curl_exec($ch);
        $curlErr = curl_errno($ch) ? curl_error($ch) : '';
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($curlErr) { $lastError = 'cURL: ' . $curlErr; continue; }

        if ($httpCode === 200) {
            if (!$stream) return json_decode((string)$response, true) ?? [];
            if ($sse->error) {
                $lastError = 'Claude API (stream): ' . $sse->error;
                if ($sse->errorRetryable) continue;
                throw new ClaudeApiException($lastError, false);
            }
            return $sse->message();
        }

        $data = json_decode($stream ? $raw : (string)$response, true) ?? [];
        $msg = $data['error']['message'] ?? ('HTTP ' . $httpCode);
        $lastError = "Claude API HTTP $httpCode: $msg";

        // Konto/model bez wsparcia fallbacków — ponów od razu bez nich
        if ($httpCode === 400 && isset($payload['fallbacks']) && stripos($msg, 'fallback') !== false) {
            unset($payload['fallbacks']);
            $betas = array_values(array_filter($betas, fn($b) => !str_starts_with($b, 'server-side-fallback')));
            $attempt--;
            continue;
        }
        $retryable = $httpCode === 429 || $httpCode === 408 || $httpCode >= 500;
        if (!$retryable) throw new ClaudeApiException($lastError, false);
    }
    throw new ClaudeApiException($lastError, true);
}

/**
 * Składa finalną wiadomość z eventów SSE (tekst, thinking, usage, stop_reason).
 */
class ClaudeSseAccumulator {
    private string $buffer = '';
    private array $msg = ['content' => [], 'usage' => []];
    private $onProgress;
    private float $lastProgress = 0;
    public string $error = '';
    public bool $errorRetryable = false;

    public function __construct(?callable $onProgress) {
        $this->onProgress = $onProgress;
    }

    public function feed(string $chunk): void {
        $this->buffer .= $chunk;
        while (($pos = strpos($this->buffer, "\n\n")) !== false) {
            $event = substr($this->buffer, 0, $pos);
            $this->buffer = substr($this->buffer, $pos + 2);
            foreach (explode("\n", $event) as $line) {
                if (str_starts_with($line, 'data:')) $this->handle(json_decode(trim(substr($line, 5)), true) ?? []);
            }
        }
        if ($this->onProgress && microtime(true) - $this->lastProgress > 10) {
            $this->lastProgress = microtime(true);
            ($this->onProgress)();
        }
    }

    private function handle(array $e): void {
        switch ($e['type'] ?? '') {
            case 'message_start':
                $m = $e['message'] ?? [];
                $this->msg['model'] = $m['model'] ?? '';
                $this->msg['usage'] = $m['usage'] ?? [];
                break;
            case 'content_block_start':
                $this->msg['content'][$e['index']] = $e['content_block'] ?? [];
                break;
            case 'content_block_delta':
                $i = $e['index'];
                $d = $e['delta'] ?? [];
                $type = $d['type'] ?? '';
                if ($type === 'text_delta') $this->msg['content'][$i]['text'] = ($this->msg['content'][$i]['text'] ?? '') . $d['text'];
                elseif ($type === 'thinking_delta') $this->msg['content'][$i]['thinking'] = ($this->msg['content'][$i]['thinking'] ?? '') . $d['thinking'];
                elseif ($type === 'signature_delta') $this->msg['content'][$i]['signature'] = $d['signature'];
                break;
            case 'message_delta':
                $this->msg['stop_reason'] = $e['delta']['stop_reason'] ?? '';
                foreach (($e['usage'] ?? []) as $k => $v) $this->msg['usage'][$k] = $v;
                break;
            case 'error':
                $this->error = $e['error']['message'] ?? 'nieznany błąd';
                $this->errorRetryable = in_array($e['error']['type'] ?? '', ['overloaded_error', 'api_error', 'rate_limit_error'], true);
                break;
        }
    }

    public function message(): array {
        $this->msg['content'] = array_values($this->msg['content']);
        return $this->msg;
    }
}

// ── Message Batches API (50% taniej, wynik asynchronicznie, zwykle < 1 h, maks. 24 h) ──

/**
 * @param array $requests [custom_id => opts jak w claudeRequest (bez tools/stream)]
 * @return string ID batcha
 */
function claudeBatchCreate(array $requests): string {
    $body = ['requests' => []];
    foreach ($requests as $customId => $opts) {
        // Batches API nie przyjmuje parametru fallbacks ani stream
        $body['requests'][] = ['custom_id' => (string)$customId, 'params' => claudeBuildPayload($opts, $opts['model'] ?? claudeMainModel())];
    }
    // Bez ponawiania po błędzie sieci: timeout po wysłaniu nie mówi, czy batch powstał, a ponowienie
    // mogłoby utworzyć drugi, płatny batch. Nieudane wysłanie = wpisy wracają do pending (CRON).
    $data = claudeBatchHttp('POST', 'https://api.anthropic.com/v1/messages/batches', $body, false, false);
    if (empty($data['id'])) throw new ClaudeApiException('Batch API: brak ID w odpowiedzi', true);
    return $data['id'];
}

/** @return array {id, processing_status: in_progress|canceling|ended, results_url, request_counts} */
function claudeBatchGet(string $batchId): array {
    return claudeBatchHttp('GET', 'https://api.anthropic.com/v1/messages/batches/' . rawurlencode($batchId));
}

/**
 * Wyniki zakończonego batcha.
 * @return array [custom_id => {type: succeeded|errored|canceled|expired, text, usage, model, stop_reason, error}]
 */
function claudeBatchResults(string $resultsUrl): array {
    $raw = claudeBatchHttp('GET', $resultsUrl, null, true);
    $out = [];
    foreach (preg_split('/\r?\n/', (string)$raw) as $line) {
        if (trim($line) === '') continue;
        $row = json_decode($line, true);
        if (!isset($row['custom_id'])) continue;
        $res = $row['result'] ?? [];
        $msg = $res['message'] ?? [];
        $text = '';
        foreach (($msg['content'] ?? []) as $b) if (($b['type'] ?? '') === 'text') $text .= $b['text'];
        $out[$row['custom_id']] = [
            'type'        => $res['type'] ?? 'errored',
            'text'        => $text,
            'usage'       => array_filter($msg['usage'] ?? [], 'is_int'),
            'model'       => $msg['model'] ?? '',
            'stop_reason' => $msg['stop_reason'] ?? '',
            'error'       => $res['error']['error']['message'] ?? $res['error']['message'] ?? '',
        ];
    }
    return $out;
}

function claudeBatchHttp(string $method, string $url, ?array $body = null, bool $rawResponse = false, bool $retryNetwork = true) {
    $apiKey = claudeApiKey();
    if ($apiKey === '') throw new ClaudeApiException('Brak klucza Anthropic API w ustawieniach.');
    $lastError = '';
    for ($attempt = 0; $attempt <= 3; $attempt++) {
        if ($attempt > 0) sleep(5 * $attempt);
        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'x-api-key: ' . $apiKey, 'anthropic-version: 2023-06-01'],
            CURLOPT_TIMEOUT => 300,          // wysyłka/pobranie kilku MB (paczka, wyniki JSONL)
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_FOLLOWLOCATION => true,
        ];
        if ($method === 'POST') {
            $opts[CURLOPT_POST] = true;
            $opts[CURLOPT_POSTFIELDS] = json_encode($body);
        }
        curl_setopt_array($ch, $opts);
        $resp = curl_exec($ch);
        $err = curl_errno($ch) ? curl_error($ch) : '';
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($err) {
            $lastError = 'cURL: ' . $err;
            // Połączenie nie powstało = żądanie na pewno nie dotarło, ponowienie jest bezpieczne
            if ($retryNetwork || str_contains(strtolower($err), 'connect')) continue;
            throw new ClaudeApiException($lastError, true);
        }
        if ($code === 200) return $rawResponse ? $resp : (json_decode((string)$resp, true) ?? []);
        $data = json_decode((string)$resp, true) ?? [];
        $lastError = "Batch API HTTP $code: " . ($data['error']['message'] ?? 'nieznany błąd');
        if ($code !== 429 && $code < 500) throw new ClaudeApiException($lastError, false);
    }
    throw new ClaudeApiException($lastError, true);
}

/**
 * Parsuje JSON z odpowiedzi modelu (structured output albo tekst z ewentualnym code fence).
 */
function claudeParseJson(string $text): ?array {
    $text = trim(preg_replace('/^```(?:json)?\s*|\s*```$/m', '', trim($text)));
    $data = json_decode($text, true);
    if (is_array($data)) return $data;
    $start = strpos($text, '{');
    $end = strrpos($text, '}');
    if ($start === false || $end === false) return null;
    $data = json_decode(substr($text, $start, $end - $start + 1), true);
    return is_array($data) ? $data : null;
}
