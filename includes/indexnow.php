<?php
/**
 * IndexNow (Bing, Yandex, Seznam, Naver, Yep, Amazon — NIE Google). Darmowy, do 10 000 URL-i na żądanie.
 * Spec: https://www.indexnow.org/documentation — klucz musi być dostępny na każdym hoście
 * jako https://{host}/{klucz}.txt (położenie pliku wyznacza zakres URL-i, więc tylko katalog główny).
 *
 * Plik klucza serwuje na stronach WordPress mu-plugin wp-plugins/semtree-indexnow-key.php
 * (do wgrania do wp-content/mu-plugins na każdej stronie zaplecza). Zaplecze raz na dobę sprawdza,
 * na których domenach plik odpowiada (site_indexnow), i wysyła tylko dla nich — reszta jest pomijana.
 *
 * Klucz nie jest sekretem (z definicji leży publicznie na każdej stronie), dlatego jest stałą w kodzie,
 * tą samą co w mu-pluginie.
 */

require_once __DIR__ . '/../db.php';

const INDEXNOW_KEY = 'f1abc58f9a9f293363eea23b4a693d1a';
const INDEXNOW_ENDPOINT = 'https://api.indexnow.org/indexnow';
const INDEXNOW_UA = 'SemtreeZapleczeIndexer/1.0 (+https://zaplecze.semtree.com.pl)';

function indexNowHostOf(string $url): string {
    return strtolower((string)parse_url($url, PHP_URL_HOST));
}

/** Czy plik klucza odpowiada na hoście (https://host/klucz.txt zwraca dokładnie klucz). */
function indexNowCheckHost(string $host): bool {
    $ch = curl_init('https://' . $host . '/' . INDEXNOW_KEY . '.txt');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_FOLLOWLOCATION => false, CURLOPT_USERAGENT => INDEXNOW_UA]);
    $body = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $code === 200 && trim($body) === INDEXNOW_KEY;
}

/** Raz na dobę (worker): stan pliku klucza na wszystkich stronach. @return array{sites:int, verified:int} */
function indexNowVerifySites(): array {
    $db = getDb();
    $sum = ['sites' => 0, 'verified' => 0];
    $st = $db->prepare('INSERT INTO site_indexnow (site_id, host, verified, checked_at) VALUES (:sid, :h, :v, datetime("now"))
        ON CONFLICT(site_id) DO UPDATE SET host = :h, verified = :v, checked_at = datetime("now")');
    $r = $db->query('SELECT id, url FROM sites');
    $rows = [];
    while ($s = $r->fetchArray(SQLITE3_ASSOC)) $rows[] = $s;
    foreach ($rows as $s) {
        $host = indexNowHostOf($s['url']);
        if ($host === '') continue;
        $ok = indexNowCheckHost($host);
        $st->bindValue(':sid', (int)$s['id'], SQLITE3_INTEGER);
        $st->bindValue(':h', $host, SQLITE3_TEXT);
        $st->bindValue(':v', $ok ? 1 : 0, SQLITE3_INTEGER);
        $st->execute();
        $st->reset();
        $sum['sites']++;
        if ($ok) $sum['verified']++;
    }
    return $sum;
}

function indexNowVerifiedHosts(): array {
    $hosts = [];
    $r = getDb()->query('SELECT host FROM site_indexnow WHERE verified = 1');
    while ($row = $r->fetchArray(SQLITE3_NUM)) $hosts[$row[0]] = true;
    return $hosts;
}

/**
 * Wysyła URL-e do IndexNow — osobne żądanie per host (host i keyLocation są w body), tylko dla
 * hostów ze zweryfikowanym plikiem klucza. Nigdy nie rzuca wyjątku.
 * @return array{sent:int, hosts:int, skipped_hosts:int, errors:string[]}
 */
function indexNowSubmit(array $urls): array {
    $out = ['sent' => 0, 'hosts' => 0, 'skipped_hosts' => 0, 'errors' => []];
    if (indexNowSettingOff()) return $out;
    $verified = indexNowVerifiedHosts();
    $byHost = [];
    foreach ($urls as $u) $byHost[indexNowHostOf($u)][] = $u;
    foreach ($byHost as $host => $list) {
        if (!isset($verified[$host])) { $out['skipped_hosts']++; continue; }
        $ch = curl_init(INDEXNOW_ENDPOINT);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json; charset=utf-8'],
            CURLOPT_POSTFIELDS => json_encode([
                'host' => $host,
                'key' => INDEXNOW_KEY,
                'keyLocation' => 'https://' . $host . '/' . INDEXNOW_KEY . '.txt',
                'urlList' => array_values(array_slice($list, 0, 10000)),
            ], JSON_UNESCAPED_SLASHES),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_USERAGENT => INDEXNOW_UA,
        ]);
        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($resp !== false && ($code === 200 || $code === 202)) {
            $out['sent'] += count($list);
            $out['hosts']++;
        } else {
            $out['errors'][] = "$host: HTTP $code";
        }
    }
    return $out;
}

function indexNowSettingOff(): bool {
    $row = getDb()->querySingle("SELECT value FROM settings WHERE key = 'indexnow_enabled'");
    return $row === '0';
}
