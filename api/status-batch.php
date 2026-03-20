<?php
set_time_limit(180);
require_once __DIR__ . '/../auth.php';
header('Content-Type: application/json');
requireLoginApi();

$db = getDb();
$result = $db->query('SELECT id, url, username, app_password FROM sites ORDER BY id');
$sites = [];
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $sites[] = $row;
}

if (empty($sites)) {
    echo json_encode([]);
    exit;
}

$BATCH_SIZE = 10;
$response = [];

for ($offset = 0; $offset < count($sites); $offset += $BATCH_SIZE) {
    $batch = array_slice($sites, $offset, $BATCH_SIZE);
    $batchResults = processBatch($batch);
    $response = array_merge($response, $batchResults);
}

echo json_encode($response);

function processBatch(array $batch): array {
    $mh = curl_multi_init();
    // Limit max concurrent connections in the multi handle
    curl_multi_setopt($mh, CURLMOPT_MAXCONNECTS, 20);

    $handles = [];

    foreach ($batch as $s) {
        $id = $s['id'];
        $baseUrl = rtrim($s['url'], '/');
        $token = base64_encode($s['username'] . ':' . $s['app_password']);
        $auth = 'Authorization: Basic ' . $token;

        // 1. HTTP HEAD — check site status
        $handles[$id]['http'] = createCurl($s['url'], [
            CURLOPT_NOBODY => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 10,
        ]);
        curl_multi_add_handle($mh, $handles[$id]['http']);

        // 2. HEAD — detect permalink format (/wp-json/ vs ?rest_route=)
        $handles[$id]['detect'] = createCurl($baseUrl . '/wp-json/wp/v2', [
            CURLOPT_NOBODY => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 8,
        ]);
        curl_multi_add_handle($mh, $handles[$id]['detect']);
    }

    execMulti($mh);

    // Collect permalink detection results, remove phase 1 handles
    $siteUrls = [];
    foreach ($batch as $s) {
        $id = $s['id'];
        $baseUrl = rtrim($s['url'], '/');
        $detectCode = (int) curl_getinfo($handles[$id]['detect'], CURLINFO_HTTP_CODE);
        $usePlain = ($detectCode === 0 || $detectCode === 404);
        $siteUrls[$id] = ['base' => $baseUrl, 'plain' => $usePlain];

        curl_multi_remove_handle($mh, $handles[$id]['detect']);
        curl_close($handles[$id]['detect']);
        // Keep 'http' handle — we'll read it later but it's already done
    }

    // Phase 2: API test + post count (add to same multi handle)
    foreach ($batch as $s) {
        $id = $s['id'];
        $info = $siteUrls[$id];
        $token = base64_encode($s['username'] . ':' . $s['app_password']);
        $auth = 'Authorization: Basic ' . $token;

        // users/me — API connectivity test
        $meUrl = $info['plain']
            ? $info['base'] . '/?rest_route=/wp/v2/users/me&context=edit'
            : $info['base'] . '/wp-json/wp/v2/users/me?context=edit';

        $handles[$id]['api'] = createCurl($meUrl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [$auth],
            CURLOPT_TIMEOUT => 15,
        ]);
        curl_multi_add_handle($mh, $handles[$id]['api']);

        // posts — get X-WP-Total header for post count
        $postsUrl = $info['plain']
            ? $info['base'] . '/?rest_route=/wp/v2/posts&per_page=1&status=publish&_fields=id'
            : $info['base'] . '/wp-json/wp/v2/posts?per_page=1&status=publish&_fields=id';

        $handles[$id]['posts'] = createCurl($postsUrl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [$auth],
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HEADER => true,
        ]);
        curl_multi_add_handle($mh, $handles[$id]['posts']);
    }

    execMulti($mh);

    // Collect all results
    $results = [];
    foreach ($batch as $s) {
        $id = $s['id'];

        // HTTP status (from phase 1, already completed)
        $httpCode = (int) curl_getinfo($handles[$id]['http'], CURLINFO_HTTP_CODE);

        // API check
        $apiCode = (int) curl_getinfo($handles[$id]['api'], CURLINFO_HTTP_CODE);
        $apiOk = ($apiCode >= 200 && $apiCode < 300);

        // Post count from X-WP-Total header
        $postsRaw = curl_multi_getcontent($handles[$id]['posts']) ?: '';
        $headerSize = curl_getinfo($handles[$id]['posts'], CURLINFO_HEADER_SIZE);
        $postsHeaders = substr($postsRaw, 0, $headerSize);
        $postCount = null;
        if (preg_match('/X-WP-Total:\s*(\d+)/i', $postsHeaders, $m)) {
            $postCount = (int) $m[1];
        }

        $results[] = [
            'id' => $id,
            'http_status' => $httpCode,
            'api_ok' => $apiOk,
            'post_count' => $postCount,
        ];

        // Cleanup
        foreach (['http', 'api', 'posts'] as $key) {
            if (isset($handles[$id][$key])) {
                curl_multi_remove_handle($mh, $handles[$id][$key]);
                curl_close($handles[$id][$key]);
            }
        }
    }

    curl_multi_close($mh);
    return $results;
}

function createCurl(string $url, array $opts): CurlHandle {
    $ch = curl_init($url);
    curl_setopt_array($ch, $opts + [
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT => 'SemtreeZaplecze/1.0',
        CURLOPT_RETURNTRANSFER => true,
    ]);
    return $ch;
}

function execMulti($mh): void {
    $running = null;
    do {
        $status = curl_multi_exec($mh, $running);
        if ($running > 0) {
            curl_multi_select($mh, 1);
        }
    } while ($running > 0 && $status === CURLM_OK);
}
