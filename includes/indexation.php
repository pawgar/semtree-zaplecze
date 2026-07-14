<?php
/**
 * Wspólna logika indeksacji: skan strony przez GSC URL Inspection + wysyłka do Rapid Indexer.
 * Używane przez api/cron-indexation.php (nocny skan) oraz api/indexation.php (refresh/submit on-demand).
 */
require_once __DIR__ . '/gsc_api.php';
require_once __DIR__ . '/wp_api.php';

/**
 * Skanuje indeksację jednej strony zapleczowej:
 *  - pobiera URL-e (homepage + opublikowane wpisy WP),
 *  - sprawdza status w GSC URL Inspection (z pominięciem świeżo zaindeksowanych < 7 dni),
 *  - zapisuje/aktualizuje index_status,
 *  - zapisuje dzienną migawkę do index_snapshots.
 *
 * @return array{scanned:int,indexed:int,not_indexed:int,total:int,skipped:int,error:?string}
 */
function scanSiteIndexation(SQLite3 $db, GscApi $gsc, array $site, ?callable $log = null, int $maxPerRun = 1800): array {
    $lg = $log ?? function ($m) {};
    $siteId = (int) $site['id'];
    $res = ['scanned' => 0, 'indexed' => 0, 'not_indexed' => 0, 'total' => 0, 'skipped' => 0, 'error' => null];

    $gscUrl = $gsc->matchSiteToProperty($site['url']);
    if (!$gscUrl) {
        $res['error'] = 'brak dopasowania w GSC';
        return $res;
    }

    // Uniwersum URL-i: homepage + wszystkie opublikowane wpisy WP
    $urls = [rtrim($site['url'], '/') . '/'];
    try {
        $wp = new WpApi($site['url'], $site['username'], $site['app_password']);
        foreach ($wp->getPostUrls() as $u) {
            $urls[] = $u;
        }
    } catch (Throwable $e) {
        $lg('  WP błąd (' . $site['name'] . '): ' . $e->getMessage());
    }
    $urls = array_values(array_unique($urls));
    $res['total'] = count($urls);

    $freshCut = date('Y-m-d H:i:s', time() - 7 * 86400);
    // $maxPerRun: limit sprawdzeń na jeden przebieg (cron 1800 < kwota 2000/dzień; on-demand mniejszy)

    $existStmt = $db->prepare('SELECT is_indexed, checked_at FROM index_status WHERE site_id = :sid AND url = :url');
    $upsert = $db->prepare('INSERT INTO index_status (site_id, url, verdict, coverage_state, is_indexed, last_crawl, checked_at)
        VALUES (:sid, :url, :v, :cs, :ii, :lc, datetime("now"))
        ON CONFLICT(site_id, url) DO UPDATE SET verdict = :v, coverage_state = :cs, is_indexed = :ii, last_crawl = :lc, checked_at = datetime("now")');

    foreach ($urls as $u) {
        if ($res['scanned'] >= $maxPerRun) {
            $res['skipped']++;
            continue;
        }

        // Pomiń świeżo zaindeksowane (rzadko się odindeksowują) — oszczędza kwotę
        $existStmt->reset();
        $existStmt->bindValue(':sid', $siteId, SQLITE3_INTEGER);
        $existStmt->bindValue(':url', $u, SQLITE3_TEXT);
        $ex = $existStmt->execute()->fetchArray(SQLITE3_ASSOC);
        if ($ex && (int) $ex['is_indexed'] === 1 && !empty($ex['checked_at']) && $ex['checked_at'] > $freshCut) {
            $res['skipped']++;
            continue;
        }

        try {
            $r = $gsc->inspectUrl($gscUrl, $u);
        } catch (Throwable $e) {
            $msg = $e->getMessage();
            $lg('  inspect błąd: ' . substr($msg, 0, 140));
            if (stripos($msg, 'quota') !== false || stripos($msg, '429') !== false || stripos($msg, 'rate') !== false) {
                $res['error'] = 'limit GSC (quota) — przerwano skan tej strony';
                break;
            }
            continue;
        }

        $upsert->reset();
        $upsert->bindValue(':sid', $siteId, SQLITE3_INTEGER);
        $upsert->bindValue(':url', $u, SQLITE3_TEXT);
        $upsert->bindValue(':v', $r['verdict'], SQLITE3_TEXT);
        $upsert->bindValue(':cs', $r['coverage_state'], SQLITE3_TEXT);
        $upsert->bindValue(':ii', $r['is_indexed'], SQLITE3_INTEGER);
        $upsert->bindValue(':lc', $r['last_crawl'], $r['last_crawl'] === null ? SQLITE3_NULL : SQLITE3_TEXT);
        $upsert->execute();
        $res['scanned']++;
        usleep(200000); // 0.2s — pod limitem 600/min
    }

    // Migawka dzienna z aktualnego stanu index_status
    $cnt = $db->prepare('SELECT COUNT(*) t, COALESCE(SUM(is_indexed), 0) idx FROM index_status WHERE site_id = :sid');
    $cnt->bindValue(':sid', $siteId, SQLITE3_INTEGER);
    $c = $cnt->execute()->fetchArray(SQLITE3_ASSOC);
    $total = (int) $c['t'];
    $indexed = (int) $c['idx'];
    $notIndexed = $total - $indexed;
    $res['indexed'] = $indexed;
    $res['not_indexed'] = $notIndexed;

    $snap = $db->prepare('INSERT INTO index_snapshots (site_id, snap_date, total, indexed, not_indexed)
        VALUES (:sid, date("now"), :t, :i, :n)
        ON CONFLICT(site_id, snap_date) DO UPDATE SET total = :t, indexed = :i, not_indexed = :n');
    $snap->bindValue(':sid', $siteId, SQLITE3_INTEGER);
    $snap->bindValue(':t', $total, SQLITE3_INTEGER);
    $snap->bindValue(':i', $indexed, SQLITE3_INTEGER);
    $snap->bindValue(':n', $notIndexed, SQLITE3_INTEGER);
    $snap->execute();

    return $res;
}

/**
 * Wysyła listę URL-i jako JEDEN projekt do Rapid URL Indexer.
 * @return array{success:bool,submitted:int,project_id?:mixed,project_name?:string,error?:string}
 */
function rapidSubmitProject(string $apiKey, string $projectName, array $urls): array {
    $urls = array_values(array_filter(array_map('trim', $urls), function ($u) {
        return $u !== '' && preg_match('#^https?://#i', $u);
    }));
    if (empty($urls)) {
        return ['success' => false, 'submitted' => 0, 'error' => 'brak poprawnych URL-i'];
    }

    $payload = json_encode([
        'project_name' => $projectName,
        'urls' => $urls,
        'notify_on_status_change' => false,
        'apex_mode_enabled' => false,
    ]);

    $ch = curl_init('https://rapidurlindexer.com/wp-json/api/v1/projects');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-API-Key: ' . $apiKey],
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_CONNECTTIMEOUT => 20,
    ]);
    $resp = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($err) {
        return ['success' => false, 'submitted' => 0, 'error' => 'cURL: ' . $err];
    }
    $data = json_decode($resp, true) ?? [];
    if ($code === 201 || $code === 200) {
        return ['success' => true, 'submitted' => count($urls), 'project_id' => $data['project_id'] ?? null, 'project_name' => $projectName];
    }
    return ['success' => false, 'submitted' => 0, 'error' => 'Rapid Indexer: ' . ($data['message'] ?? ('HTTP ' . $code))];
}
