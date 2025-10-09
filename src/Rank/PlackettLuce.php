<?php
declare(strict_types=1);

namespace SEOVendor\Evaluator\Rank;

/**
 * Plackett–Luce with MM updates + weak additive prior (alpha) for stability.
 * Input: $lists = [ ['A','B','C'], ['B','A','C'], ... ] ordered best→worst.
 * Returns normalized worths summing to 1.
 */
class PlackettLuce
{
    /**
     * @param array<int,array<int,string>> $lists
     * @param int $maxIter
     * @param float $eps
     * @param float $alpha  Additive smoothing on both numerator and denominator
     *                      to avoid collapse with tiny data (default 0.1).
     * @return array<string,float>
     */
    public function fit(array $lists, int $maxIter = 200, float $eps = 1e-6, float $alpha = 0.1): array
    {
        // Universe + appearance counts (n_i = number of times i appears across lists)
        $items = [];
        $n_i = [];
        foreach ($lists as $L) {
            foreach ($L as $b) {
                $items[$b] = true;
                $n_i[$b]   = ($n_i[$b] ?? 0) + 1;
            }
        }
        $brands = array_keys($items);
        if (empty($brands)) return [];

        // Initialize uniformly
        $w = array_fill_keys($brands, 1.0 / count($brands));

        for ($iter = 0; $iter < $maxIter; $iter++) {
            // B_i accumulation
            $B = array_fill_keys($brands, 0.0);

            foreach ($lists as $L) {
                $S = $L;                 // remaining set
                $m = count($S);
                for ($r = 0; $r < $m; $r++) {
                    // denominator over remaining items
                    $den = 0.0;
                    foreach ($S as $j) { $den += $w[$j]; }
                    if ($den <= 0.0) { $den = 1e-12; }

                    // each item currently in S contributes 1/den
                    $inv = 1.0 / $den;
                    foreach ($S as $j) { $B[$j] += $inv; }

                    // remove the winner at this position (top of S)
                    array_shift($S);
                }
            }

            // MM update with smoothing
            $wNew = $w;
            foreach ($brands as $i) {
                $num = ($n_i[$i] ?? 0.0) + $alpha;
                $den = max($B[$i] + $alpha, 1e-12);
                $wNew[$i] = $num / $den;
            }

            // normalize
            $sum = array_sum($wNew);
            if ($sum > 0.0) {
                foreach ($wNew as $k => $v) { $wNew[$k] = $v / $sum; }
            }

            // convergence
            $delta = 0.0;
            foreach ($brands as $b) { $delta = max($delta, abs($wNew[$b] - $w[$b])); }
            $w = $wNew;
            if ($delta < $eps) break;
        }

        arsort($w);
        return $w;
    }
}
