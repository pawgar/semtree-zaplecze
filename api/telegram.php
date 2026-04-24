<?php
/**
 * Telegram settings & test API.
 */
require_once __DIR__ . '/../auth.php';
header('Content-Type: application/json');
requireAdminApi();

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$db = getDb();

try {
    switch ($action) {
        case 'get':
            $token = $db->querySingle("SELECT value FROM settings WHERE key = 'telegram_bot_token'", true);
            $chatId = $db->querySingle("SELECT value FROM settings WHERE key = 'telegram_chat_id'", true);
            echo json_encode([
                'success' => true,
                'bot_token' => $token ? $token['value'] : '',
                'chat_id' => $chatId ? $chatId['value'] : '',
            ]);
            break;

        case 'save':
            $input = json_decode(file_get_contents('php://input'), true);
            $botToken = trim($input['bot_token'] ?? '');
            $chatId = trim($input['chat_id'] ?? '');

            $stmt = $db->prepare('INSERT OR REPLACE INTO settings (key, value) VALUES (:k, :v)');
            $stmt->bindValue(':k', 'telegram_bot_token', SQLITE3_TEXT);
            $stmt->bindValue(':v', $botToken, SQLITE3_TEXT);
            $stmt->execute();

            $stmt->reset();
            $stmt->bindValue(':k', 'telegram_chat_id', SQLITE3_TEXT);
            $stmt->bindValue(':v', $chatId, SQLITE3_TEXT);
            $stmt->execute();

            echo json_encode(['success' => true]);
            break;

        case 'test':
            $token = $db->querySingle("SELECT value FROM settings WHERE key = 'telegram_bot_token'", true);
            $chatId = $db->querySingle("SELECT value FROM settings WHERE key = 'telegram_chat_id'", true);

            $botToken = $token ? trim($token['value']) : '';
            $chat = $chatId ? trim($chatId['value']) : '';

            if (!$botToken || !$chat) {
                throw new RuntimeException('Brak tokena bota lub Chat ID');
            }

            $msg = "\xE2\x9C\x85 *Test Semtree Zaplecze*\n\nPołączenie z Telegramem działa poprawnie!\n\xF0\x9F\x95\x90 " . date('Y-m-d H:i:s');

            $url = "https://api.telegram.org/bot$botToken/sendMessage";
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POSTFIELDS => http_build_query([
                    'chat_id' => $chat,
                    'text' => $msg,
                    'parse_mode' => 'Markdown',
                ]),
                CURLOPT_TIMEOUT => 15,
                CURLOPT_SSL_VERIFYPEER => false,
            ]);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            $data = json_decode($response, true);
            if (!($data['ok'] ?? false)) {
                throw new RuntimeException('Telegram API: ' . ($data['description'] ?? 'Unknown error'));
            }

            echo json_encode(['success' => true, 'message' => 'Wiadomość testowa wysłana']);
            break;

        // ── Webhook status (czy Skalmar aktywny) ───────────
        case 'webhook-status':
            $tokenRow = $db->querySingle("SELECT value FROM settings WHERE key = 'telegram_bot_token'", true);
            $botToken = $tokenRow ? trim($tokenRow['value']) : '';
            if (!$botToken) {
                echo json_encode(['success' => true, 'enabled' => false, 'url' => '']);
                break;
            }
            $ch = curl_init("https://api.telegram.org/bot$botToken/getWebhookInfo");
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_SSL_VERIFYPEER => false]);
            $resp = curl_exec($ch);
            curl_close($ch);
            $data = json_decode($resp, true);
            $info = $data['result'] ?? [];
            echo json_encode([
                'success' => true,
                'enabled' => !empty($info['url']),
                'url' => $info['url'] ?? '',
                'pending_update_count' => $info['pending_update_count'] ?? 0,
                'last_error_message' => $info['last_error_message'] ?? null,
            ]);
            break;

        // ── Aktywuj webhook "Skalmar" ──────────────────────
        case 'webhook-enable':
            $tokenRow = $db->querySingle("SELECT value FROM settings WHERE key = 'telegram_bot_token'", true);
            $botToken = $tokenRow ? trim($tokenRow['value']) : '';
            if (!$botToken) throw new RuntimeException('Najpierw zapisz Bot Token');

            // Generuj losowy secret jeśli jeszcze go nie ma
            $secretRow = $db->querySingle("SELECT value FROM settings WHERE key = 'telegram_webhook_secret'", true);
            $secret = ($secretRow && !empty($secretRow['value'])) ? trim($secretRow['value']) : bin2hex(random_bytes(32));
            $stmt = $db->prepare('INSERT OR REPLACE INTO settings (key, value) VALUES ("telegram_webhook_secret", :v)');
            $stmt->bindValue(':v', $secret, SQLITE3_TEXT);
            $stmt->execute();

            // Zbuduj URL webhooka
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'];
            $baseDir = dirname($_SERVER['SCRIPT_NAME']);
            $webhookUrl = "$scheme://$host$baseDir/telegram-webhook.php";

            // setWebhook w Telegram API
            $ch = curl_init("https://api.telegram.org/bot$botToken/setWebhook");
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POSTFIELDS => http_build_query([
                    'url' => $webhookUrl,
                    'secret_token' => $secret,
                    'allowed_updates' => json_encode(['message']),
                ]),
                CURLOPT_TIMEOUT => 15,
                CURLOPT_SSL_VERIFYPEER => false,
            ]);
            $resp = curl_exec($ch);
            curl_close($ch);
            $data = json_decode($resp, true);
            if (!($data['ok'] ?? false)) {
                throw new RuntimeException('Telegram: ' . ($data['description'] ?? 'Unknown'));
            }
            echo json_encode(['success' => true, 'url' => $webhookUrl, 'description' => $data['description'] ?? 'Webhook aktywowany']);
            break;

        // ── Wyłącz webhook ─────────────────────────────────
        case 'webhook-disable':
            $tokenRow = $db->querySingle("SELECT value FROM settings WHERE key = 'telegram_bot_token'", true);
            $botToken = $tokenRow ? trim($tokenRow['value']) : '';
            if (!$botToken) throw new RuntimeException('Brak Bot Tokena');

            $ch = curl_init("https://api.telegram.org/bot$botToken/deleteWebhook");
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_SSL_VERIFYPEER => false,
            ]);
            $resp = curl_exec($ch);
            curl_close($ch);
            $data = json_decode($resp, true);
            if (!($data['ok'] ?? false)) {
                throw new RuntimeException('Telegram: ' . ($data['description'] ?? 'Unknown'));
            }
            echo json_encode(['success' => true, 'description' => $data['description'] ?? 'Webhook usunięty']);
            break;

        default:
            throw new RuntimeException('Nieznana akcja');
    }
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
}
