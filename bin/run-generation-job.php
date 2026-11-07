<?php
/**
 * Wykonuje zadanie generowania artykułu w tle (startowane przez api/generate-job.php).
 *
 *   php bin/run-generation-job.php <job_id>
 *
 * Postęp i wynik zapisywane w generation_jobs; przeglądarka odpytuje GET api/generate-job.php?id=.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}
set_time_limit(0);
ini_set('memory_limit', '512M');

require __DIR__ . '/../includes/article_pipeline.php';

$jobId = $argv[1] ?? '';
$db = getDb();
$stmt = $db->prepare('SELECT * FROM generation_jobs WHERE id = :id AND status = "queued"');
$stmt->bindValue(':id', $jobId, SQLITE3_TEXT);
$job = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
if (!$job) exit("Brak zadania $jobId w stanie queued.\n");

$progress = [];
$save = function (array $fields) use ($db, $jobId) {
    $sets = [];
    foreach (array_keys($fields) as $k) $sets[] = "$k = :$k";
    $st = $db->prepare('UPDATE generation_jobs SET ' . implode(', ', $sets) . ', updated_at = datetime("now") WHERE id = :id');
    foreach ($fields as $k => $v) $st->bindValue(":$k", $v, SQLITE3_TEXT);
    $st->bindValue(':id', $jobId, SQLITE3_TEXT);
    $st->execute();
};
$log = function (string $msg) use (&$progress, $save) {
    $progress[] = ['t' => date('H:i:s'), 'msg' => $msg];
    $save(['progress' => json_encode($progress, JSON_UNESCAPED_UNICODE)]);
};

$save(['status' => 'running']);
try {
    $result = generateArticleFull(json_decode($job['input'], true) ?: [], null, $log);
    $sum = fn(string $k) => array_sum(array_map(fn($u) => $u[$k] ?? 0, $result['usage']));
    $save([
        'status' => 'done',
        'result' => json_encode([
            'success'       => true,
            'html_content'  => $result['html'],
            'markdown'      => $result['markdown'],
            'char_count'    => $result['char_count'],
            'checks'        => $result['checks'],
            'research'      => $result['research'],
            'notes'         => $result['notes'],
            'cost_usd'      => $result['cost_usd'],
            'model'         => $result['model'],
            'proofread'     => true,
            'input_tokens'  => $sum('input_tokens'),
            'output_tokens' => $sum('output_tokens'),
        ], JSON_UNESCAPED_UNICODE),
    ]);
} catch (Throwable $e) {
    $save(['status' => 'error', 'error' => $e->getMessage()]);
}
