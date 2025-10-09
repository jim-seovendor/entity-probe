<?php
declare(strict_types=1);

namespace SEOVendor\Evaluator\Client;

class LLMClient
{
    public function call(string $entity, string $locale, int $n, float $temperature): array
    {
        // TODO: Replace with real vendor API call using Structured Outputs (JSON schema).
        $brands = ['Acme','Bravo','Canyon','Delta','Echo','Foxtrot','Gamma','Helix','Ion'];
        shuffle($brands);
        $out = [];
        for ($i=0; $i < $n; $i++) {
            $b = $brands[$i];
            $out[] = [
                'brand' => $b,
                'site' => 'https://' . strtolower($b) . '.example.com',
                'locale' => $locale
            ];
        }
        return $out;
    }
}
