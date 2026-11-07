<?php
/**
 * Statystyki indeksacji (Ustawienia): kolejka, zgłoszenia, zaindeksowane, saldo kredytów Rapid.
 */
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/rapid_indexing.php';
header('Content-Type: application/json');
requireLoginApi();

$credits = null;
try {
    if (indexSetting('rapid_indexer_api_key') !== '') $credits = rapidCredits();
} catch (Throwable $e) {
    $credits = null;
}
echo json_encode([
    'auto'    => indexSetting('index_auto') !== '0',
    'stats'   => indexStats(),
    'credits' => $credits,
]);
