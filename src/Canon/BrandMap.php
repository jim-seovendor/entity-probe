<?php
declare(strict_types=1);

namespace SEOVendor\Evaluator\Canon;

class BrandMap
{
    /** @var array<string,string> */
    private array $aliases = [
        'P&G' => 'Procter & Gamble',
        'Procter and Gamble' => 'Procter & Gamble',
    ];

    public function canonicalize(string $brand): string
    {
        return $this->aliases[$brand] ?? $brand;
    }
}
