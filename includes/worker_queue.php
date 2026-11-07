<?php
/**
 * Kolejka zadań dla workera (bin/worker.php uruchamiany z crona co minutę).
 *
 * Hosting blokuje exec(), więc panel nie może startować procesów w tle. Zamiast tego
 * zapisuje zadanie w bazie, a worker (proces CLI z crona — bez limitów HTTP) je wykonuje:
 *   - generation_jobs: generowanie artykułu (Zamów i opublikuj)
 *   - settings.auto_publish_run_requested: ręczny pełny przebieg auto-publikacji
 *
 * Worker zapisuje settings.worker_heartbeat przy każdym starcie; brak świeżego heartbeatu
 * = worker niepodpięty w crontabie -> panel przechodzi na tryb synchroniczny / zgłasza błąd.
 */

require_once __DIR__ . '/../db.php';

const WORKER_HEARTBEAT_MAX_AGE = 180;   // s — cron co minutę, margines na opóźnienia

function workerSetting(string $key): string {
    $stmt = getDb()->prepare('SELECT value FROM settings WHERE key = :k');
    $stmt->bindValue(':k', $key, SQLITE3_TEXT);
    $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    return $row ? (string)$row['value'] : '';
}

function workerSetSetting(string $key, string $value): void {
    $stmt = getDb()->prepare('INSERT OR REPLACE INTO settings (key, value) VALUES (:k, :v)');
    $stmt->bindValue(':k', $key, SQLITE3_TEXT);
    $stmt->bindValue(':v', $value, SQLITE3_TEXT);
    $stmt->execute();
}

function workerAlive(): bool {
    $hb = (int)workerSetting('worker_heartbeat');
    return $hb > 0 && time() - $hb <= WORKER_HEARTBEAT_MAX_AGE;
}

/** Panel: poproś workera o ręczny pełny przebieg auto-publikacji. */
function requestAutoPublishRun(): void {
    workerSetSetting('auto_publish_run_requested', (string)time());
}

function autoPublishRunRequested(): bool {
    return workerSetting('auto_publish_run_requested') !== '';
}

/** Worker: atomowo zabiera prośbę o przebieg (tylko jeden proces ją dostanie). */
function claimAutoPublishRun(): bool {
    $db = getDb();
    $db->exec("UPDATE settings SET value = '' WHERE key = 'auto_publish_run_requested' AND value <> ''");
    return $db->changes() === 1;
}

/** Worker: najstarsze oczekujące zadanie generowania (atomowo przestawione na running). */
function claimGenerationJob(): ?array {
    $db = getDb();
    $id = $db->querySingle("SELECT id FROM generation_jobs WHERE status = 'queued' ORDER BY created_at LIMIT 1");
    if (!$id) return null;
    $stmt = $db->prepare("UPDATE generation_jobs SET status = 'running', updated_at = datetime('now') WHERE id = :id AND status = 'queued'");
    $stmt->bindValue(':id', $id, SQLITE3_TEXT);
    $stmt->execute();
    if ($db->changes() !== 1) return null;   // zabrał go inny proces
    $stmt = $db->prepare('SELECT * FROM generation_jobs WHERE id = :id');
    $stmt->bindValue(':id', $id, SQLITE3_TEXT);
    return $stmt->execute()->fetchArray(SQLITE3_ASSOC) ?: null;
}

/**
 * Wykonuje zadanie generowania: postęp i wynik zapisywane w generation_jobs,
 * przeglądarka odpytuje GET api/generate-job.php?id=.
 */
function runGenerationJob(array $job): void {
    require_once __DIR__ . '/article_pipeline.php';
    $db = getDb();
    $jobId = $job['id'];
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
    // Heartbeat zadania co ~30 s w trakcie długich requestów — GET nie uzna go za martwe
    $lastBeat = time();
    $beat = function () use (&$lastBeat, $db, $jobId) {
        if (time() - $lastBeat < 30) return;
        $lastBeat = time();
        $st = $db->prepare('UPDATE generation_jobs SET updated_at = datetime("now") WHERE id = :id');
        $st->bindValue(':id', $jobId, SQLITE3_TEXT);
        $st->execute();
    };

    try {
        $result = generateArticleFull(json_decode($job['input'], true) ?: [], $beat, $log);
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
}
