<?php
/**
 * Uzupelnia kolejke auto-publikacji tematami wygenerowanymi przez Claude AI.
 *
 *   php bin/refill-queues.php                    dry-run: pokazuje ktore strony maja wyczerpana kolejke
 *   php bin/refill-queues.php --apply            faktycznie generuje i wstawia (30 na strone)
 *   php bin/refill-queues.php --apply --count 20 20 tematow na strone
 *   php bin/refill-queues.php --apply --site-id 5   tylko strona id=5 (nawet jesli ma pending)
 *   php bin/refill-queues.php --apply --threshold 10   uzupelnia gdy pending<10 (default: 3)
 *
 * Uwaga: --apply pobiera od Claude API 1 request/strona, zjada tokeny.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

require __DIR__ . '/../db.php';
require __DIR__ . '/../includes/topic_generator.php';

// ── Parse args ──────────────────────────────────────────────
$args = ['apply' => false, 'count' => 30, 'threshold' => 3, 'site-id' => 0];
for ($i = 1; $i < $argc; $i++) {
    $a = $argv[$i];
    if ($a === '--apply' || $a === '-y') { $args['apply'] = true; continue; }
    if (str_starts_with($a, '--')) {
        $key = substr($a, 2);
        $val = $argv[$i + 1] ?? '';
        if ($val !== '' && !str_starts_with($val, '--')) {
            $args[$key] = $val;
            $i++;
        } else {
            $args[$key] = true;
        }
    }
}

$count = max(1, min(60, (int)$args['count']));
$threshold = max(0, (int)$args['threshold']);
$siteFilter = (int)$args['site-id'];
$apply = (bool)$args['apply'];

echo str_repeat('─', 60) . "\n";
echo "Refill queues — " . ($apply ? 'APPLY' : 'DRY-RUN') . "\n";
echo "  count/site: $count | prog wyczerpania: $threshold pending\n";
if ($siteFilter) echo "  filtr: site_id=$siteFilter (bedzie potraktowana niezaleznie od threshold)\n";
echo str_repeat('─', 60) . "\n";

// ── Znajdz strony do uzupelnienia ───────────────────────────
if ($siteFilter) {
    $db = getDb();
    $stmt = $db->prepare('SELECT id, name FROM sites WHERE id = :id');
    $stmt->bindValue(':id', $siteFilter, SQLITE3_INTEGER);
    $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    if (!$row) { fwrite(STDERR, "Brak strony id=$siteFilter\n"); exit(1); }
    $targets = [['id' => (int)$row['id'], 'name' => $row['name'], 'pending_count' => -1]];
} else {
    $targets = findExhaustedQueues($threshold);
}

if (empty($targets)) {
    echo "Brak stron do uzupelnienia (wszystkie kolejki maja >= $threshold pendingow).\n";
    exit(0);
}

echo "Strony do uzupelnienia (" . count($targets) . "):\n";
foreach ($targets as $t) {
    printf("  [%3d] %-40s  pending=%s\n", $t['id'], mb_substr($t['name'], 0, 40), $t['pending_count'] === -1 ? '?' : (string)$t['pending_count']);
}
echo "\n";

if (!$apply) {
    echo "To byl dry-run. Dodaj --apply zeby faktycznie wygenerowac i wstawic.\n";
    echo "Zjawi to okolo " . count($targets) . " x jeden request do Claude API (~jednego duzego wygenerowania per strona).\n";
    exit(0);
}

// ── Apply ───────────────────────────────────────────────────
$totalInserted = 0;
$errors = 0;

foreach ($targets as $t) {
    echo str_repeat('─', 60) . "\n";
    printf("[%d] %s\n", $t['id'], $t['name']);

    $site = getSiteContextForTopics($t['id']);
    if (!$site) {
        echo "  ! Brak strony w bazie\n";
        $errors++;
        continue;
    }
    echo "  Jezyk: {$site['lang']} | Kategorie: " . (empty($site['categories']) ? '(brak)' : implode(', ', $site['categories'])) . "\n";
    echo "  Historia (wszystkie tytuly): " . count($site['historical_titles']) . " (queue+publications, anty-dupe)\n";
    echo "  Generuje $count tematow przez Claude API...\n";

    try {
        $t0 = microtime(true);
        $result = generateTopicsForSite($site, $count);
        $dt = round(microtime(true) - $t0, 1);
        $topics = $result['topics'] ?? [];
        $raw = $result['raw_count'] ?? count($topics);
        $dropped = $result['dropped_dupes'] ?? 0;
        printf("  Wygenerowano: %d (LLM zwrocil %d, odrzucono %d duplikatow) w %ss\n", count($topics), $raw, $dropped, $dt);

        if (!$topics) {
            echo "  ! Zero unikalnych tematow po dedupie, pomijam.\n";
            $errors++;
            continue;
        }

        // Pokaz sample
        $sample = array_slice($topics, 0, 3);
        foreach ($sample as $i => $s) {
            printf("    %d. %s  [kw: %s]\n", $i + 1, mb_substr($s['title'], 0, 65), mb_substr($s['main_keyword'] ?? '', 0, 30));
        }
        if (count($topics) > 3) echo "    ... i " . (count($topics) - 3) . " wiecej\n";

        $ins = insertTopicsIntoQueue($t['id'], $topics);
        echo "  Wstawiono do kolejki: {$ins}\n";
        $totalInserted += $ins;
    } catch (Throwable $e) {
        echo "  BLAD: " . $e->getMessage() . "\n";
        $errors++;
    }
}

echo str_repeat('─', 60) . "\n";
echo "Razem wstawionych: $totalInserted | Bledy: $errors | Strony: " . count($targets) . "\n";
