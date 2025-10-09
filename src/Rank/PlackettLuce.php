<?php
declare(strict_types=1);

namespace SEOVendor\Evaluator\Rank;

/**
 * Plackett–Luce model via MM updates (Hunter, 2004) for full/partial top-N lists.
 * Input: array of lists, each list is an ordered array of brand strings (best to worst).
 * Returns: worth scores w_i normalized to sum=1.
 */
class PlackettLuce
{
    /**
     * @param array<int,array<int,string>> $lists
     * @param int $maxIter
     * @param float $eps
     * @return array<string,float>
     */
    public function fit(array $lists, int $maxIter = 200, float $eps = 1e-6): array
    {
        // Collect universe of items and appearance counts n_i
        $items = [];
        $n_i = []; // appearances
        foreach ($lists as $L) {
            $seen = [];
            foreach ($L as $b) {
                $items[$b] = true;
                if (!isset($seen[$b])) {
                    $n_i[$b] = ($n_i[$b] ?? 0) + 1;
                    $seen[$b] = true;
                }
            }
        }
        $brands = array_keys($items);
        if (empty($brands)) { return []; }

        $w = array_fill_keys($brands, 1.0 / count($brands));

        for ($iter = 0; $iter < $maxIter; $iter++) {
            // Compute B_i = sum over lists and stages where i is in remaining set S_{lr} of 1 / sum_{j in S_{lr}} w_j
            $B = array_fill_keys($brands, 0.0);

            foreach ($lists as $L) {
                // Remaining set S starts as the whole list (remove chosen items as we descend positions)
                $S = $L;
                $m = count($S);
                for ($r = 0; $r < $m; $r++) {
                    // Denominator over remaining items
                    $den = 0.0;
                    foreach ($S as $j) { $den += $w[$j]; }
                    if ($den <= 0.0) { $den = 1e-12; }

                    // Every item currently in S contributes 1/den to its B_i
                    foreach ($S as $j) { $B[$j] += 1.0 / $den; }

                    // Remove the selected winner at this position (top of S)
                    array_shift($S); // move to next stage
                }
            }

            // MM update: w_i <- n_i / B_i
            $wNew = $w;
            foreach ($brands as $i) {
                $num = (float)($n_i[$i] ?? 0.0);
                $den = max($B[$i], 1e-12);
                $wNew[$i] = $num / $den;
            }

            // Normalize
            $sum = array_sum($wNew);
            if ($sum > 0.0) {
                foreach ($wNew as $k => $v) { $wNew[$k] = $v / $sum; }
            }

            // Convergence
            $delta = 0.0;
            foreach ($brands as $b) {
                $delta = max($delta, abs($wNew[$b] - $w[$b]));
            }
            $w = $wNew;
            if ($delta < $eps) { break; }
        }

        arsort($w);
        return $w;
    }
}
