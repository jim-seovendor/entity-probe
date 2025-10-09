<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use SEOVendor\Evaluator\Rank\BradleyTerry;
use SEOVendor\Evaluator\Rank\PlackettLuce;

$options = getopt('', ['in:', 'out:', 'method::']);
$inPath = $options['in'] ?? null;
$outPath = $options['out'] ?? null;
$method = strtolower($options['method'] ?? 'bt'); // bt|pl

if (!$inPath || !$outPath) {
    fwrite(STDERR, "Usage: php bin/aggregate.php --in data/results.jsonl --out data/scores.csv [--method bt|pl]\n");
    exit(1);
}

$fh = fopen($inPath, 'r');
if (!$fh) { fwrite(STDERR, "Cannot open input file.\n"); exit(1); }

if ($method === 'bt') {
    $pairWins = []; // [i][j] = wins of i over j
    while (($line = fgets($fh)) !== false) {
        $rec = json_decode($line, true);
        if (!is_array($rec) || !isset($rec['list'])) { continue; }
        $list = $rec['list'];
        $n = count($list);
        for ($a=0; $a < $n; $a++) {
            for ($b=$a+1; $b < $n; $b++) {
                $i = (string)$list[$a]['brand'];
                $j = (string)$list[$b]['brand'];
                if (!isset($pairWins[$i])) { $pairWins[$i] = []; }
                $pairWins[$i][$j] = ($pairWins[$i][$j] ?? 0) + 1;
            }
        }
    }
    fclose($fh);

    $bt = new BradleyTerry();
    $scores = $bt->fit($pairWins);

    // naive CI placeholder
    $total = 0;
    foreach ($pairWins as $i => $row) { foreach ($row as $j => $c) { $total += $c; } }
    $se = $total > 0 ? 1.0 / sqrt((float)$total) : 0.1;
    $ci95 = 1.96 * $se;

} else { // pl
    $lists = [];
    while (($line = fgets($fh)) !== false) {
        $rec = json_decode($line, true);
        if (!is_array($rec) || !isset($rec['list'])) { continue; }
        $L = [];
        foreach ($rec['list'] as $item) {
            $L[] = (string)$item['brand'];
        }
        if (!empty($L)) { $lists[] = $L; }
    }
    fclose($fh);

    $pl = new PlackettLuce();
    $scores = $pl->fit($lists);

    // naive CI placeholder proportional to 1/sqrt(total items across lists)
    $totalStages = 0;
    foreach ($lists as $L) { $totalStages += count($L); }
    $se = $totalStages > 0 ? 1.0 / sqrt((float)$totalStages) : 0.1;
    $ci95 = 1.96 * $se;
}

$fp = fopen($outPath, 'w');
fputcsv($fp, ['brand','worth','approx_ci_95']);
arsort($scores);
foreach ($scores as $brand => $worth) {
    fputcsv($fp, [$brand, number_format($worth, 6, '.', ''), number_format($ci95, 6, '.', '')]);
}
fclose($fp);
echo "Wrote scores to {$outPath} using method={$method}\n";
