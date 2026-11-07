<?php
/**
 * Generowanie artykułu w tle (Zamów i opublikuj — pojedynczo i masowo).
 *
 *   POST {title, main_keyword, secondary_keywords, notes, lang, category} -> {job_id}
 *        Start procesu CLI bin/run-generation-job.php w tle (jak "Uruchom teraz" w auto-publikacji).
 *        Gdy serwer nie pozwala uruchomić procesu: {fallback: true} — frontend woła api/generate-article.php.
 *   GET ?id=<job_id> -> {status: queued|running|done|error, progress[], result?, error?}
 *
 * Przeglądarka nie trzyma otwartego połączenia przez kilka minut generowania.
 */
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../db.php';
header('Content-Type: application/json');
requireLoginApi();

$db = getDb();
$userId = (int)($_SESSION['user_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $db->prepare('SELECT * FROM generation_jobs WHERE id = :id AND user_id = :uid');
    $stmt->bindValue(':id', (string)($_GET['id'] ?? ''), SQLITE3_TEXT);
    $stmt->bindValue(':uid', $userId, SQLITE3_INTEGER);
    $job = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    if (!$job) {
        http_response_code(404);
        echo json_encode(['error' => 'Nie znaleziono zadania']);
        exit;
    }
    // Proces nie wystartował albo padł (np. kill przez hosting) — nie czekaj w nieskończoność
    $stale = in_array($job['status'], ['queued', 'running'], true) && (time() - strtotime($job['updated_at'] . ' UTC')) > 900;
    echo json_encode([
        'status'   => $stale ? 'error' : $job['status'],
        'progress' => json_decode($job['progress'], true) ?: [],
        'result'   => $job['result'] ? json_decode($job['result'], true) : null,
        'error'    => $stale ? 'Zadanie przestało odpowiadać (brak postępu od 15 min)' : $job['error'],
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];
if (trim($input['title'] ?? '') === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Tytuł jest wymagany']);
    exit;
}
if (!function_exists('exec')) {
    echo json_encode(['fallback' => true]);
    exit;
}

// Sprzątanie starych zadań
$db->exec("DELETE FROM generation_jobs WHERE created_at < datetime('now', '-2 days')");

$jobId = bin2hex(random_bytes(12));
$stmt = $db->prepare('INSERT INTO generation_jobs (id, user_id, input) VALUES (:id, :uid, :in)');
$stmt->bindValue(':id', $jobId, SQLITE3_TEXT);
$stmt->bindValue(':uid', $userId, SQLITE3_INTEGER);
$stmt->bindValue(':in', json_encode([
    'title'              => trim($input['title'] ?? ''),
    'main_keyword'       => trim($input['main_keyword'] ?? ''),
    'secondary_keywords' => trim($input['secondary_keywords'] ?? ''),
    'notes'              => trim($input['notes'] ?? ''),
    'lang'               => trim($input['lang'] ?? 'pl'),
    'category'           => trim($input['category'] ?? ''),
], JSON_UNESCAPED_UNICODE), SQLITE3_TEXT);
$stmt->execute();

// PHP CLI jak w auto-publish.php (run-manual): SeoHost trzyma go w /usr/local/php83/bin/php
$phpBin = '/usr/local/php83/bin/php';
if (!is_executable($phpBin)) $phpBin = PHP_BINARY;
if (!is_executable($phpBin)) $phpBin = 'php';
$script = realpath(__DIR__ . '/../bin/run-generation-job.php');
$logFile = realpath(__DIR__ . '/../data') . '/generation-jobs.log';
exec(sprintf('nohup %s %s %s >> %s 2>&1 &',
    escapeshellcmd($phpBin), escapeshellarg($script), escapeshellarg($jobId), escapeshellarg($logFile)));

echo json_encode(['job_id' => $jobId]);
