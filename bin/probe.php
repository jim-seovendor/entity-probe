<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use SEOVendor\Evaluator\Client\LLMClient;
use SEOVendor\Evaluator\Schema\Validator;

$options = getopt('', ['entities:', 'out:', 'k::', 'n::', 'temperature::']);
$entitiesPath = $options['entities'] ?? null;
$outPath = $options['out'] ?? null;
$k = isset($options['k']) ? (int)$options['k'] : 100;
$n = isset($options['n']) ? (int)$options['n'] : 10;
$temperature = isset($options['temperature']) ? (float)$options['temperature'] : 0.5;

if (!$entitiesPath || !$outPath) {
    fwrite(STDERR, "Usage: php bin/probe.php --entities data/entities.jsonl --out data/results.jsonl [--k 100 --n 10 --temperature 0.5]\n");
    exit(1);
}

$client = new LLMClient();
$validator = new Validator();

$in = fopen($entitiesPath, 'r');
$out = fopen($outPath, 'w');
if (!$in || !$out) {
    fwrite(STDERR, "Error opening files.\n");
    exit(1);
}

while (($line = fgets($in)) !== false) {
    $rec = json_decode($line, true);
    if (!is_array($rec)) { continue; }
    $entity = (string)$rec['entity'];
    $locale = (string)($rec['locale'] ?? 'US');
    $language = (string)($rec['language'] ?? 'en');
    $category = (string)($rec['category'] ?? '');

    for ($i = 0; $i < $k; $i++) {
        $list = $client->call($entity, $locale, $n, $temperature);
        if (!$validator->validateList($list)) {
            $list = $client->call($entity, $locale, $n, $temperature);
            if (!$validator->validateList($list)) { continue; }
        }
        $outRec = [
            'entity' => $entity,
            'locale' => $locale,
            'language' => $language,
            'category' => $category,
            'list' => $list,
            'timestamp' => microtime(true),
        ];
        fwrite($out, json_encode($outRec, JSON_UNESCAPED_SLASHES) . "\n");
    }
}
fclose($in);
fclose($out);
echo "Wrote samples to {$outPath}\n";
