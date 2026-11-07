<?php
/**
 * Ustawienia Senuto: GET (status, bez hasła), POST {email, password} (zapis + test), POST {action: test}.
 * Hasło zapisywane zaszyfrowane, nigdy nie wraca do przeglądarki.
 */
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/senuto_api.php';
header('Content-Type: application/json');
requireLoginApi();

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    echo json_encode([
        'email'         => senutoSetting('senuto_email'),
        'has_password'  => senutoSetting('senuto_password_enc') !== '',
        'token_expires' => senutoSetting('senuto_token_expires_at'),
    ]);
    exit;
}

if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    if (($input['action'] ?? '') !== 'test') {
        if (!isAdmin()) {
            http_response_code(403);
            echo json_encode(['error' => 'Zmiana danych Senuto wymaga roli admin']);
            exit;
        }
        $email = trim($input['email'] ?? '');
        $password = (string)($input['password'] ?? '');
        if ($email === '') {
            http_response_code(400);
            echo json_encode(['error' => 'Podaj e-mail konta Senuto']);
            exit;
        }
        senutoSetSetting('senuto_email', $email);
        if ($password !== '') senutoSetSetting('senuto_password_enc', encryptString($password));
        // Nowe dane logowania = nowy token
        senutoSetSetting('senuto_bearer_token', '');
        senutoSetSetting('senuto_token_expires_at', '');
    }
    echo json_encode(senutoTestConnection());
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
