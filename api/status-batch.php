<?php
set_time_limit(120);
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

// Phase 1: HTTP status + API detection via curl_multi (HEAD requests)
$mh = curl_multi_init();
$handles = [];

foreach ($sites as $s) {
    // HEAD request to site URL (HTTP status)
    $ch = curl_init($s['url']);
    curl_setopt_array($ch, [
        CURLOPT_NOBODY => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT => 'SemtreeZaplecze/1.0',
    ]);
    $handles[$s['id']]['http'] = $ch;
    curl_multi_add_handle($mh, $ch);

    // HEAD request to detect permalink format
    $ch2 = curl_init(rtrim($s['url'], '/') . '/wp-json/wp/v2');
    curl_setopt_array($ch2, [
        CURLOPT_NOBODY => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT => 'SemtreeZaplecze/1.0',
    ]);
    $handles[$s['id']]['detect'] = $ch2;
    curl_multi_add_handle($mh, $ch2);
}

execMulti($mh);

// Collect Phase 1 results
$siteInfo = [];
foreach ($sites as $s) {
    $httpCode = (int) curl_getinfo($handles[$s['id']]['http'], CURLINFO_HTTP_CODE);
    $detectCode = (int) curl_getinfo($handles[$s['id']]['detect'], CURLINFO_HTTP_CODE);
    $usePlain = ($detectCode === 0 || $detectCode === 404);

    curl_multi_remove_handle($mh, $handles[$s['id']]['http']);
    curl_multi_remove_handle($mh, $handles[$s['id']]['detect']);
    curl_close($handles[$s['id']]['http']);
    curl_close($handles[$s['id']]['detect']);

    $siteInfo[$s['id']] = [
        'http_status' => $httpCode,
        'use_plain' => $usePlain,
        'url' => rtrim($s['url'], '/'),
        'username' => $s['username'],
        'app_password' => $s['app_password'],
    ];
}

// Phase 2: API test (/users/me) + post count in parallel
$handles2 = [];
foreach ($sites as $s) {
    $info = $siteInfo[$s['id']];
    $token = base64_encode($info['username'] . ':' . $info['app_password']);
    $auth = 'Authorization: Basic ' . $token;

    // users/me
    $meUrl = $info['use_plain']
        ? $info['url'] . '/?rest_route=/wp/v2/users/me&context=edit'
        : $info['url'] . '/wp-json/wp/v2/users/me?context=edit';

    $ch = curl_init($meUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [$auth],
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT => 'SemtreeZaplecze/1.0',
    ]);
    $handles2[$s['id']]['api'] = $ch;
    curl_multi_add_handle($mh, $ch);

    // post count
    $postsUrl = $info['use_plain']
        ? $info['url'] . '/?rest_route=/wp/v2/posts&per_page=1&status=publish&_fields=id'
        : $info['url'] . '/wp-json/wp/v2/posts?per_page=1&status=publish&_fields=id';

    $ch2 = curl_init($postsUrl);
    curl_setopt_array($ch2, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [$auth],
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HEADER => true,
        CURLOPT_USERAGENT => 'SemtreeZaplecze/1.0',
    ]);
    $handles2[$s['id']]['posts'] = $ch2;
    curl_multi_add_handle($mh, $ch2);
}

execMulti($mh);

// Collect Phase 2 results and build response
$response = [];
foreach ($sites as $s) {
    $id = $s['id'];

    // API check
    $apiBody = curl_exec_result($handles2[$id]['api']);
    $apiCode = (int) curl_getinfo($handles2[$id]['api'], CURLINFO_HTTP_CODE);
    $apiOk = ($apiCode >= 200 && $apiCode < 300);

    // Post count
    $postsRaw = curl_exec_result($handles2[$id]['posts']);
    $headerSize = curl_getinfo($handles2[$id]['posts'], CURLINFO_HEADER_SIZE);
    $postsHeaders = substr($postsRaw, 0, $headerSize);
    $postCount = null;
    if (preg_match('/X-WP-Total:\s*(\d+)/i', $postsHeaders, $m)) {
        $postCount = (int) $m[1];
    }

    curl_multi_remove_handle($mh, $handles2[$id]['api']);
    curl_multi_remove_handle($mh, $handles2[$id]['posts']);
    curl_close($handles2[$id]['api']);
    curl_close($handles2[$id]['posts']);

    $response[] = [
        'id' => $id,
        'http_status' => $siteInfo[$id]['http_status'],
        'api_ok' => $apiOk,
        'post_count' => $postCount,
    ];
}

curl_multi_close($mh);
echo json_encode($response);

// ─── Helpers ───

function execMulti($mh): void {
    $running = null;
    do {
        $status = curl_multi_exec($mh, $running);
        if ($running > 0) {
            curl_multi_select($mh, 1);
        }
    } while ($running > 0 && $status === CURLM_OK);
}

function curl_exec_result($ch): string {
    $content = curl_multi_getcontent($ch);
    return $content ?: '';
}
