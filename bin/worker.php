<?php
/**
 * Worker kolejki — uruchamiany z crona CO MINUTĘ:
 *
 *   * * * * * /usr/local/php83/bin/php /SCIEZKA/public_html/bin/worker.php >> /SCIEZKA/public_html/data/worker.log 2>&1
 *
 * Każde wywołanie:
 *   1. zapisuje heartbeat (panel wie, że worker działa),
 *   2. jeśli panel poprosił o ręczny przebieg auto-publikacji ("Uruchom teraz") — zabiera prośbę
 *      i wykonuje pełny przebieg w tym procesie (--sync --manual, log do cron-auto-publish.log),
 *   3. w przeciwnym razie przetwarza zadania generowania artykułów (generation_jobs) jedno po drugim.
 *
 * Kolejne wywołania co minutę nie dublują pracy: przebieg auto-publikacji trzyma swój flock,
 * a zadania generowania — flock data/worker-jobs.lock (zadanie i tak zabierane atomowo).
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}
set_time_limit(0);
ini_set('memory_limit', '512M');

require __DIR__ . '/../includes/worker_queue.php';

workerSetSetting('worker_heartbeat', (string)time());

// ── Ręczny pełny przebieg auto-publikacji ───────────────────
if (claimAutoPublishRun()) {
    $logFile = __DIR__ . '/../data/cron-auto-publish.log';
    echo '[' . date('H:i:s') . "] Worker: ręczny przebieg auto-publikacji\n";
    // Wyjście przebiegu trafia do tego samego logu co CRON (podgląd w panelu)
    ob_start(function ($chunk) use ($logFile) {
        file_put_contents($logFile, $chunk, FILE_APPEND);
        return '';
    }, 1);
    $argv = [__DIR__ . '/../api/cron-auto-publish.php', '--token=' . workerSetting('cron_token'), '--sync', '--manual'];
    $argc = count($argv);
    require __DIR__ . '/../api/cron-auto-publish.php';
    exit;
}

// ── Zadania generowania artykułów ───────────────────────────
$jobsLock = fopen(__DIR__ . '/../data/worker-jobs.lock', 'c');
if (!$jobsLock || !flock($jobsLock, LOCK_EX | LOCK_NB)) exit;   // poprzednie wywołanie jeszcze pracuje

$started = time();
while (time() - $started < 3300 && ($job = claimGenerationJob())) {
    echo '[' . date('H:i:s') . "] Worker: zadanie {$job['id']}\n";
    runGenerationJob($job);
    workerSetSetting('worker_heartbeat', (string)time());
}
