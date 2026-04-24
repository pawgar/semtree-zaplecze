<?php
/**
 * Telegram webhook — odbiera wiadomości z Telegram Bot API.
 * Publiczny endpoint (Telegram musi móc tu POST-ować).
 * Zabezpieczenie: secret_token w URL (?secret=...) walidowany z settings.
 *
 * Jak działa:
 *  1. Telegram POST-uje tu JSON update gdy ktoś napisze do bota / grupy.
 *  2. Sprawdzamy czy w treści jest słowo "Skalmar" (case insensitive).
 *  3. Jeśli tak — odpowiadamy reply-to z losową ciekawostką + miłym komunikatem.
 *
 * Żeby aktywować: Ustawienia → Telegram → "Aktywuj Skalmara" (rejestruje webhook).
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/squidward_facts.php';
header('Content-Type: application/json');

// Zawsze 200 dla Telegrama, żeby nie próbował retransmisji
register_shutdown_function(function () {
    if (!headers_sent()) http_response_code(200);
});

try {
    $db = getDb();

    // Walidacja secretu (Telegram przesyła go w nagłówku X-Telegram-Bot-Api-Secret-Token lub ?secret=)
    $expectedSecret = '';
    $row = $db->querySingle("SELECT value FROM settings WHERE key = 'telegram_webhook_secret'", true);
    if ($row) $expectedSecret = trim($row['value']);

    $providedSecret = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? ($_GET['secret'] ?? '');
    if (!$expectedSecret || $providedSecret !== $expectedSecret) {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden']);
        exit;
    }

    // Pobierz update z body
    $raw = file_get_contents('php://input');
    $update = json_decode($raw, true);
    if (!$update || !isset($update['message'])) {
        echo json_encode(['ok' => true, 'skipped' => 'no_message']);
        exit;
    }

    $msg = $update['message'];
    $text = $msg['text'] ?? '';
    $chatId = $msg['chat']['id'] ?? null;
    $messageId = $msg['message_id'] ?? null;
    $fromName = $msg['from']['first_name'] ?? '';

    if (!$chatId || !$text) {
        echo json_encode(['ok' => true, 'skipped' => 'no_chat_or_text']);
        exit;
    }

    // Sprawdź czy w treści jest "Skalmar" (case insensitive, UTF-8 safe)
    if (mb_stripos($text, 'skalmar', 0, 'UTF-8') === false) {
        echo json_encode(['ok' => true, 'skipped' => 'no_trigger']);
        exit;
    }

    // Pobierz konfigurację bota
    $tokenRow = $db->querySingle("SELECT value FROM settings WHERE key = 'telegram_bot_token'", true);
    $botToken = $tokenRow ? trim($tokenRow['value']) : '';
    if (!$botToken) {
        echo json_encode(['ok' => false, 'error' => 'no_bot_token']);
        exit;
    }

    // Zbuduj odpowiedź — krótki miły komunikat + ciekawostka
    $greetings = [
        "Hej $fromName! \xF0\x9F\x91\x8B",
        "Cześć $fromName, dzięki że mnie wołasz! \xF0\x9F\x98\x8A",
        "$fromName, jestem tu \xF0\x9F\x99\x8C",
        "Dzięki za zawołanie, $fromName! \xE2\x9C\xA8",
        "Do usług, $fromName \xF0\x9F\x8E\xB7",
        "Tak $fromName, o co chodzi? \xF0\x9F\x99\x83",
        "Melduję się, $fromName \xF0\x9F\xAB\xA1",
    ];
    $greeting = $greetings[array_rand($greetings)];
    $fact = randomSquidwardFact();

    $reply = "$greeting\n\n\xF0\x9F\x92\xA1 <b>Czy wiesz, że...</b>\n" . htmlspecialchars($fact, ENT_QUOTES, 'UTF-8');

    // Wyślij reply
    $url = "https://api.telegram.org/bot$botToken/sendMessage";
    $ch = curl_init($url);
    $postFields = [
        'chat_id' => $chatId,
        'text' => $reply,
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => true,
    ];
    if ($messageId) {
        $postFields['reply_parameters'] = json_encode(['message_id' => $messageId, 'allow_sending_without_reply' => true]);
    }
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS => http_build_query($postFields),
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);

    $result = json_decode($resp, true);
    echo json_encode(['ok' => true, 'telegram_ok' => $result['ok'] ?? false]);
} catch (Exception $e) {
    // Zwróć 200 żeby Telegram nie retransmitował błędów
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
