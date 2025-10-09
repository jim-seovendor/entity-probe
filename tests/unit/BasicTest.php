<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use SEOVendor\Evaluator\Rank\BradleyTerry;
use SEOVendor\Evaluator\Stats\Spearman;

final class BasicTest extends TestCase
{
    public function testBradleyTerryOrder(): void
    {
        $wins = [
            'A' => ['B'=>10,'C'=>10],
            'B' => ['C'=>10]
        ];
        $bt = new BradleyTerry();
        $w = $bt->fit($wins);
        $brands = array_keys($w);
        $this->assertSame('A', $brands[0]);
        $this->assertTrue($w['A'] > $w['B'] && $w['B'] > $w['C']);
    }

    public function testSpearman(): void
    {
        $x = ['A'=>0.9,'B'=>0.7,'C'=>0.5];
        $y = ['A'=>0.8,'B'=>0.6,'C'=>0.4];
        $rho = Spearman::rho($x,$y);
        $this->assertGreaterThan(0.9, $rho);
    }
}
