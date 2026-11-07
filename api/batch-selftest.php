<?php
/**
 * TYMCZASOWY test ścieżki Batch API na prawdziwym kluczu (admin). Do usunięcia po teście.
 *   POST {action: start}       — mini-paczka w formacie CRON-a (articleWriteParams), krótka odpowiedź
 *   POST {action: start-full}  — pełny artykuł: articlePrepare (Senuto/SERP) -> paczka, kontekst zapisany
 *                                 jak w kolejce (JSON); bez publikacji w WP
 *   GET ?id=<batch_id>[&full=1] — status; po zakończeniu parser CRON-a (claudeBatchResults),
 *                                 dla full: articleFinish (audyt, poprawki, korekta) jak w Fazie A
 */
set_time_limit(900);
ignore_user_abort(true);
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/article_pipeline.php';
header('Content-Type: application/json');
requireAdminApi();

$ctxFile = __DIR__ . '/../data/batch-selftest-ctx.json';

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        if (($input['action'] ?? 'start') === 'start-full') {
            $ctx = articlePrepare([
                'title' => 'Jak wybrać kosiarkę akumulatorową do małego ogrodu?',
                'main_keyword' => 'kosiarka akumulatorowa', 'secondary_keywords' => '', 'notes' => '',
                'lang' => 'pl', 'category' => 'Ogród',
            ]);
            $id = claudeBatchCreate(['q999998' => articleWriteParams($ctx)]);
            file_put_contents($ctxFile, json_encode(['ctx' => $ctx], JSON_UNESCAPED_UNICODE));   // jak quality_report w kolejce
            echo json_encode(['batch_id' => $id, 'research_sources' => $ctx['research']['sources'], 'notes' => $ctx['pipeline_notes']], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $ctx = [
            'title' => 'Test Batch API', 'main_kw' => 'test', 'secondary' => '', 'notes' => '',
            'lang' => 'pl', 'category' => '', 'model' => claudeMainModel(), 'usage' => [], 'pipeline_notes' => [],
            'research' => ['supporting' => [], 'questions' => [], 'groups' => [], 'serp' => [], 'sources' => []],
        ];
        $params = articleWriteParams($ctx);
        $params['messages'] = [['role' => 'user', 'content' => 'To jest test techniczny. Odpowiedz jednym zdaniem po polsku: "Batch działa."']];
        $params['max_tokens'] = 2000;
        $id = claudeBatchCreate(['q999999' => $params]);
        echo json_encode(['batch_id' => $id, 'model' => $params['model'], 'effort' => $params['effort'],
            'system_chars' => mb_strlen($params['system']), 'ctx_roundtrip_ok' => json_decode(json_encode(['ctx' => $ctx]), true)['ctx'] == $ctx]);
        exit;
    }

    $full = !empty($_GET['full']);
    $info = claudeBatchGet((string)($_GET['id'] ?? ''));
    $out = ['processing_status' => $info['processing_status'] ?? null, 'request_counts' => $info['request_counts'] ?? null];
    if (($info['processing_status'] ?? '') === 'ended') {
        $res = claudeBatchResults($info['results_url']);
        $r = $res[$full ? 'q999998' : 'q999999'] ?? null;
        $out['result_type'] = $r['type'] ?? null;
        $out['stop_reason'] = $r['stop_reason'] ?? null;
        $out['error'] = $r['error'] ?? null;
        if (!$full) {
            $out['text'] = $r['text'] ?? null;
            $ctx = ['usage' => []];
            if ($r) articleTrackUsage($ctx, $r);
            $out['usage_tracked'] = $ctx['usage'];
        } elseif ($r && $r['type'] === 'succeeded') {
            // Faza A CRON-a: kontekst z "kolejki", usage, articleFinish
            $saved = json_decode((string)@file_get_contents($ctxFile), true) ?: [];
            $ctx = $saved['ctx'] ?? null;
            if (!$ctx) throw new RuntimeException('Brak zapisanego kontekstu');
            articleTrackUsage($ctx, $r);
            $result = articleFinish($ctx, $r['text']);
            $out['chars'] = $result['char_count'];
            $out['checks'] = array_map(fn($c) => $c['status'] . ' ' . $c['name'] . ': ' . $c['message'], $result['checks']);
            $out['cost_usd_at_full_price'] = $result['cost_usd'];
            $out['usage'] = $result['usage'];
            $out['notes'] = $result['notes'];
            $out['headings'] = array_values(array_filter(preg_split('/\n/', $result['markdown']), fn($l) => str_starts_with($l, '#')));
            $out['html_start'] = mb_substr($result['html'], 0, 300);
        }
    }
    echo json_encode($out, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
