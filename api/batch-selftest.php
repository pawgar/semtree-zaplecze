<?php
/**
 * TYMCZASOWY test ścieżki Batch API na prawdziwym kluczu (admin). Do usunięcia po teście.
 *   POST {action: start} — paczka z 1 krótkim zapytaniem w formacie CRON-a (articleWriteParams:
 *        prompt systemowy z cache_control, model, effort), zwraca batch_id
 *   GET ?id=<batch_id>   — status; po zakończeniu wynik z claudeBatchResults (parser CRON-a)
 *                          + test zapisu/odczytu kontekstu jak w kolejce (json quality_report)
 */
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/article_pipeline.php';
header('Content-Type: application/json');
requireAdminApi();

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Kontekst jak z articlePrepare, ale bez researchu (bez kosztu Senuto/SERP)
        $ctx = [
            'title' => 'Test Batch API', 'main_kw' => 'test', 'secondary' => '', 'notes' => '',
            'lang' => 'pl', 'category' => '', 'model' => claudeMainModel(), 'usage' => [], 'pipeline_notes' => [],
            'research' => ['supporting' => [], 'questions' => [], 'groups' => [], 'serp' => [], 'sources' => []],
        ];
        $params = articleWriteParams($ctx);
        // Ten sam format, krótka odpowiedź (koszt grosze)
        $params['messages'] = [['role' => 'user', 'content' => 'To jest test techniczny. Odpowiedz jednym zdaniem po polsku: "Batch działa."']];
        $params['max_tokens'] = 2000;
        $id = claudeBatchCreate(['q999999' => $params]);
        echo json_encode(['batch_id' => $id, 'model' => $params['model'], 'effort' => $params['effort'],
            'system_chars' => mb_strlen($params['system']), 'ctx_roundtrip_ok' => json_decode(json_encode(['ctx' => $ctx]), true)['ctx'] == $ctx]);
        exit;
    }
    $info = claudeBatchGet((string)($_GET['id'] ?? ''));
    $out = ['processing_status' => $info['processing_status'] ?? null, 'request_counts' => $info['request_counts'] ?? null];
    if (($info['processing_status'] ?? '') === 'ended') {
        $res = claudeBatchResults($info['results_url']);
        $r = $res['q999999'] ?? null;
        $out['result'] = $r;
        // Dalsza część Fazy A: usage do kontekstu tak jak w CRON-ie
        if ($r) {
            $ctx = ['usage' => []];
            articleTrackUsage($ctx, $r);
            $out['usage_tracked'] = $ctx['usage'];
            $out['cost_usd_full_price'] = articleCostUsd($ctx['usage']);
        }
    }
    echo json_encode($out, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
