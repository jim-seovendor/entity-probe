#!/usr/bin/env php
<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use SEOVendor\Evaluator\Rank\BradleyTerry;
use SEOVendor\Evaluator\Rank\PlackettLuce;

// Optional .env
if (class_exists(\Dotenv\Dotenv::class)) {
    \Dotenv\Dotenv::createImmutable(__DIR__ . '/..')->safeLoad();
}

/**
 * CLI flags:
 *   --in=FILE.jsonl
 *   --out=PATH_OR_FILE            (file for non-grouped, directory prefix for grouped)
 *   --method=pl|bt|freq
 *   --alpha=0.2                   (PL smoothing)
 *   --bootstrap=0                 (B resamples; 0 = disabled)
 *   --group-by=entity|locale|category
 *   --topk=3                      (for method=freq)
 *   --verbose
 */
$opts = getopt('', [
    'in:',
    'out:',
    'method:',
    'alpha:',
    'bootstrap:',
    'group-by:',
    'topk:',
    'verbose'
]);

$inPath   = $opts['in'] ?? null;
$outPath  = $opts['out'] ?? null;
$method   = ($opts['method'] ?? 'pl');
$alpha    = isset($opts['alpha']) ? (float)$opts['alpha'] : 0.2;
$B        = isset($opts['bootstrap']) ? (int)$opts['bootstrap'] : 0;
$groupBy  = $opts['group-by'] ?? null;
$topk     = isset($opts['topk']) ? (int)$opts['topk'] : 3;
$verbose  = array_key_exists('verbose', $opts);

if (!$inPath || !$outPath) {
    fwrite(STDERR, "Usage: php bin/aggregate.php --in data/results.jsonl --out data/scores --method pl|bt|freq [--alpha 0.2] [--bootstrap 1000] [--group-by entity] [--topk 3] [--verbose]\n");
    exit(1);
}
if (!is_readable($inPath)) {
    fwrite(STDERR, "ERROR: input not readable: {$inPath}\n");
    exit(1);
}

if ($verbose) {
    fwrite(STDOUT, "Input : " . (realpath($inPath) ?: $inPath) . "\n");
    fwrite(STDOUT, "Output: {$outPath}\n");
    fwrite(STDOUT, "Method: {$method}\n");
    if ($groupBy) fwrite(STDOUT, "Group : {$groupBy}\n");
    if ($method === 'pl') fwrite(STDOUT, "Alpha : {$alpha}\n");
    if ($B > 0) fwrite(STDOUT, "Boot  : {$B}\n");
}

function read_jsonl(string $p): array {
    $fh = fopen($p, 'r'); if (!$fh) return [];
    $rows = [];
    while (($line = fgets($fh)) !== false) {
        $line = trim($line);
        if ($line === '') continue;
        $r = json_decode($line, true);
        if (!$r || !isset($r['list']) || !is_array($r['list'])) continue;
        $rows[] = $r;
    }
    fclose($fh);
    return $rows;
}

$rows = read_jsonl($inPath);
if (empty($rows)) {
    fwrite(STDERR, "No rows.\n");
    exit(1);
}

// Group rows
$groups = ['ALL' => $rows];
if ($groupBy) {
    $groups = [];
    foreach ($rows as $r) {
        $key = $r[$groupBy] ?? 'UNKNOWN';
        $groups[$key] ??= [];
        $groups[$key][] = $r;
    }
}

function get_lists(array $rows): array {
    $out = [];
    foreach ($rows as $r) {
        $brands = [];
        foreach ($r['list'] as $it) {
            if (isset($it['brand'])) $brands[] = (string)$it['brand'];
        }
        if (!empty($brands)) $out[] = $brands;
    }
    return $out;
}

function bt_fit(array $rows): array {
    $pairWins = [];
    foreach ($rows as $r) {
        $L = [];
        foreach ($r['list'] as $it) { if (isset($it['brand'])) $L[] = (string)$it['brand']; }
        $n = count($L);
        for ($a=0; $a<$n; $a++) {
            for ($b=$a+1; $b<$n; $b++) {
                $i = $L[$a]; $j = $L[$b];
                $pairWins[$i][$j] = ($pairWins[$i][$j] ?? 0) + 1;
            }
        }
    }
    $bt = new BradleyTerry();
    return $bt->fit($pairWins);
}

function pl_fit(array $lists, float $alpha=0.2): array {
    $pl = new PlackettLuce();
    return $pl->fit($lists, 200, 1e-6, $alpha);
}

function freq_counts(array $lists, int $topk=3): array {
    $freq = [];
    foreach ($lists as $L) {
        $m = min($topk, count($L));
        for ($i=0; $i<$m; $i++) {
            $b = $L[$i];
            $freq[$b] = ($freq[$b] ?? 0) + 1;
        }
    }
    arsort($freq);
    // normalize to probability of appearing in top-k
    $den = count($lists);
    $scores = [];
    foreach ($freq as $b => $c) { $scores[$b] = $den>0 ? $c/$den : 0.0; }
    return $scores;
}

function bootstrap_pl(array $lists, float $alpha, int $B): array {
    $brands = [];
    foreach ($lists as $L) foreach ($L as $b) $brands[$b]=true;
    $brands = array_keys($brands);
    $boot = [];
    for ($t=0; $t<$B; $t++) {
        $resamp = [];
        $n = count($lists);
        for ($i=0; $i<$n; $i++) { $resamp[] = $lists[random_int(0, $n-1)]; }
        $w = pl_fit($resamp, $alpha);
        foreach ($brands as $b) {
            $boot[$b][] = $w[$b] ?? 0.0;
        }
    }
    $cis = [];
    foreach ($boot as $b => $vals) {
        sort($vals);
        $lo = $vals[(int)floor(0.025*count($vals))] ?? 0.0;
        $hi = $vals[(int)floor(0.975*count($vals))] ?? 0.0;
        $cis[$b] = [$lo,$hi];
    }
    return $cis;
}

foreach ($groups as $gkey => $grows) {
    $lists = get_lists($grows);
    if (empty($lists)) continue;

    if ($method === 'bt') {
        $scores = bt_fit($grows);
        $ci = []; // optional: bootstrap BT too
    } elseif ($method === 'freq') {
        $scores = freq_counts($lists, $topk);
        $ci = [];
    } else { // pl
        $scores = pl_fit($lists, $alpha);
        $ci = [];
        if ($B > 0) {
            if ($verbose) fwrite(STDOUT, "  bootstrapping B={$B} for group '{$gkey}'...\n");
            $ci = bootstrap_pl($lists, $alpha, $B);
        }
    }

    arsort($scores);
    // Decide output path
    if ($groupBy) {
        @mkdir($outPath, 0775, true);
        $safe = preg_replace('/[^A-Za-z0-9_.-]+/', '_', (string)$gkey);
        $outFile = rtrim($outPath, '/')."/{$safe}.csv";
    } else {
        $outFile = $outPath;
    }
    $fp = fopen($outFile, 'w');
    if (!$fp) { fwrite(STDERR, "ERROR: cannot write {$outFile}\n"); continue; }

    if ($method === 'pl' && $B > 0) {
        fputcsv($fp, ['brand','worth','worth_lo','worth_hi','n_lists']);
    } else {
        fputcsv($fp, ['brand','worth','approx_ci_95','n_lists']);
    }

    $nLists = count($lists);
    $approx_ci = $nLists>0 ? 1.96/sqrt($nLists*10.0) : 0.0; // rough placeholder

    foreach ($scores as $brand => $worth) {
        if ($method === 'pl' && $B > 0 && isset($ci[$brand])) {
            [$lo,$hi] = $ci[$brand];
            fputcsv($fp, [$brand, number_format($worth,6,'.',''), number_format($lo,6,'.',''), number_format($hi,6,'.',''), $nLists]);
        } else {
            fputcsv($fp, [$brand, number_format($worth,6,'.',''), number_format($approx_ci,6,'.',''), $nLists]);
        }
    }
    fclose($fp);
    if ($verbose) fwrite(STDOUT, "  wrote: {$outFile}\n");
}
