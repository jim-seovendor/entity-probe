#!/usr/bin/env php
<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use SEOVendor\Evaluator\Client\LLMClient;
use SEOVendor\Evaluator\Schema\Validator;

// Optional .env
if (class_exists(\Dotenv\Dotenv::class)) {
    \Dotenv\Dotenv::createImmutable(__DIR__ . '/..')->safeLoad();
}

/**
 * CLI flags:
 *   --entities=FILE.jsonl      (required)
 *   --out=FILE.jsonl           (required)
 *   --append                   (append to out instead of overwrite)
 *   --temperature=0.5
 *   --n=5                      list length per sample
 *   --max-retries=2            retry only on parse/schema failure
 *   --no-validate              skip schema validation
 *   --verbose
 *   --stop-every=10            adaptive stopping interval (0 = disabled)
 *   --stop-overlap=0.8         stop if top-3 overlap between split halves >= threshold twice in a row
 *   --log=FILE.tsv             progress log (append)
 */
$opts = getopt('', [
    'entities:',
    'out:',
    'append',
    'temperature:',
    'n:',
    'max-retries:',
    'no-validate',
    'verbose',
    'stop-every:',
    'stop-overlap:',
    'log:',
]);

$entitiesPath = $opts['entities'] ?? null;
$outPath      = $opts['out'] ?? null;
$append       = array_key_exists('append', $opts);
$temperature  = isset($opts['temperature']) ? (float)$opts['temperature'] : 0.5;
$n            = isset($opts['n']) ? (int)$opts['n'] : 5;
$maxRetries   = isset($opts['max-retries']) ? (int)$opts['max-retries'] : 2;
$validate     = !array_key_exists('no-validate', $opts);
$verbose      = array_key_exists('verbose', $opts);
$stopEvery    = isset($opts['stop-every']) ? max(0, (int)$opts['stop-every']) : 10;
$stopOverlap  = isset($opts['stop-overlap']) ? (float)$opts['stop-overlap'] : 0.8;
$logPath      = $opts['log'] ?? null;

if (!$entitiesPath || !$outPath) {
    fwrite(STDERR, "Usage: php bin/probe.php --entities data/entities.jsonl --out data/results.jsonl [--append] [--n 5] [--temperature 0.5] [--max-retries 2] [--no-validate] [--stop-every 10] [--stop-overlap 0.8] [--log data/probe.progress.tsv] [--verbose]\n");
    exit(1);
}

if (!is_readable($entitiesPath)) {
    fwrite(STDERR, "ERROR: entities file not readable: {$entitiesPath}\n");
    exit(1);
}
if (!$append) {
    // fresh file
    $fout = fopen($outPath, 'w');
    if (!$fout) { fwrite(STDERR, "ERROR: cannot open output for writing: {$outPath}\n"); exit(1); }
    fclose($fout);
}
if ($verbose) {
    fwrite(STDOUT, "Entities: {$entitiesPath}\n");
    fwrite(STDOUT, "Output  : " . (realpath($outPath) ?: $outPath) . "\n");
}

$client    = new LLMClient();
$validator = new Validator();
$totalLists = 0; $written = 0; $invalid = 0;

$progressFH = null;
if ($logPath) {
    $progressFH = fopen($logPath, 'a');
    if ($progressFH && 0 === filesize($logPath)) {
        fwrite($progressFH, "entity_id\tentity\tlocale\tk_done\tstopped_early\treason\ttimestamp\n");
    }
}

// Helpers
function topk(array $brands, int $k): array {
    return array_slice($brands, 0, $k);
}
function overlapAtK(array $A, array $B, int $k): float {
    $a = array_slice($A, 0, $k);
    $b = array_slice($B, 0, $k);
    if (!$a || !$b) return 0.0;
    $inter = count(array_intersect($a, $b));
    return $inter / (float)$k;
}
function extractBrands(array $list): array {
    $out = [];
    foreach ($list as $it) {
        if (isset($it['brand'])) $out[] = (string)$it['brand'];
    }
    return $out;
}

// Iterate entities
$fin = fopen($entitiesPath, 'r');
if (!$fin) { fwrite(STDERR, "ERROR: cannot open entities: {$entitiesPath}\n"); exit(1); }
$now = function(): float { return microtime(true); };

while (($line = fgets($fin)) !== false) {
    $line = trim($line);
    if ($line === '') continue;
    $rec = json_decode($line, true);
    if (!is_array($rec)) continue;

    $entityId   = $rec['id'] ?? null;
    $entity     = $rec['entity'] ?? null;
    $disamb     = $rec['disambiguation'] ?? '';
    $category   = $rec['category'] ?? '';
    $localesArr = $rec['locales'] ?? [];
    $language   = $rec['language'] ?? '';
    $popBin     = $rec['popularity_bin'] ?? 'torso';

    if (!$entity || empty($localesArr)) continue;

    // Decide k by popularity bin (head/torso=40, tail=20)
    $kTarget = ($popBin === 'tail') ? 20 : 40;

    foreach ($localesArr as $locale) {
        if ($verbose) fwrite(STDOUT, "Entity='{$entity}' locale={$locale} k={$kTarget} n={$n}\n");

        $done = 0; $stoppedEarly = false; $stopReason = '';
        $halfA = []; $halfB = [];
        $okInRow = 0;

        while ($done < $kTarget) {
            // get one list (with retries on schema/parse only)
            $tries = 0; $list = [];
            while ($tries <= $maxRetries) {
                $tries++;
                $items = $client->call($entity, $locale, $n, $temperature);
                if (!$items || !is_array($items)) { continue; }
                if ($validate && !$validator->validateList($items)) {
                    continue; // retry on schema/parse
                }
                $list = $items;
                break;
            }

            $totalLists++;
            if (empty($list)) {
                $invalid++;
                if ($verbose) fwrite(STDOUT, "  [skip] invalid after retry\n");
                // do not count towards done; try again
                continue;
            }

            // write one JSONL row
            $row = [
                'entity'   => $entity,
                'locale'   => $locale,
                'language' => $language,
                'category' => $category,
                'disambiguation' => $disamb,
                'list'     => $list,
                'timestamp'=> $now(),
            ];
            $fout = fopen($outPath, 'a');
            fwrite($fout, json_encode($row, JSON_UNESCAPED_SLASHES) . "\n");
            fclose($fout);
            $written++;
            $done++;
            if ($verbose) fwrite(STDOUT, "  [ok] wrote list #{$written}\n");

            // Adaptive stop check
            if ($stopEvery > 0 && $done % $stopEvery === 0 && $done >= 2*$stopEvery) {
                // Split current sample by alternating assignment
                $halfA = []; $halfB = [];
                $i = 0;
                $fh = fopen($outPath, 'r');
                // scan backwards only for the lines belonging to this entity+locale (cheap-ish for moderate files)
                $recent = [];
                while (($ln = fgets($fh)) !== false) {
                    $r = json_decode($ln, true);
                    if (!$r || $r['entity'] !== $entity || $r['locale'] !== $locale) continue;
                    $recent[] = $r;
                }
                fclose($fh);
                // use only last $done lists for this entity/locale
                $recent = array_slice($recent, -$done);
                foreach ($recent as $idx => $r) {
                    $brands = extractBrands($r['list']);
                    if ($idx % 2 === 0) $halfA[] = $brands; else $halfB[] = $brands;
                }
                // Build consensus top lists (simple frequency of appearing at rank 1..k, tie-break by appearance)
                $getTop = function(array $lists, int $k=3): array {
                    $score = [];
                    foreach ($lists as $L) {
                        $m = count($L);
                        foreach ($L as $pos => $b) {
                            $gain = max(0, ($m - $pos)); // higher gain for higher rank
                            $score[$b] = ($score[$b] ?? 0) + $gain;
                        }
                    }
                    arsort($score);
                    return array_slice(array_keys($score), 0, $k);
                };
                $topA = $getTop($halfA, 3);
                $topB = $getTop($halfB, 3);
                $ovl  = overlapAtK($topA, $topB, 3);

                if ($ovl >= $stopOverlap) $okInRow++; else $okInRow = 0;

                if ($okInRow >= 2) { // two consecutive passes
                    $stoppedEarly = true;
                    $stopReason = "overlap@3={$ovl}";
                    if ($verbose) fwrite(STDOUT, "  [stop] adaptive stop: {$stopReason}\n");
                    break;
                }
            }
        } // while done < kTarget

        if ($progressFH) {
            $ts = date('c');
            fwrite($progressFH, ($entityId ?? '') . "\t{$entity}\t{$locale}\t{$done}\t" . ($stoppedEarly?'1':'0') . "\t{$stopReason}\t{$ts}\n");
        }
    }
}
fclose($fin);
if ($progressFH) fclose($progressFH);

if ($verbose) {
    fwrite(STDOUT, "DONE total_lists={$totalLists} written={$written} invalid={$invalid}\n");
}
