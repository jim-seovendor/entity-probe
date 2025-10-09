<?php
declare(strict_types=1);

namespace SEOVendor\Evaluator\Stats;

class Bootstrap
{
    public static function meanWithCI(array $arr, int $B = 1000): array
    {
        $n = count($arr);
        if ($n === 0) { return ['mean'=>0.0, 'ci95'=>0.0]; }
        $means = [];
        for ($b=0; $b<$B; $b++) {
            $sum = 0.0;
            for ($i=0; $i<$n; $i++) {
                $sum += $arr[random_int(0, $n-1)];
            }
            $means[] = $sum / $n;
        }
        sort($means);
        $lo = $means[int(0.025 * $B)];
        $hi = $means[int(0.975 * $B)];
        $mu = array_sum($arr) / $n;
        return ['mean'=>$mu, 'ci95'=>($hi - $lo)/2.0];
    }
}
