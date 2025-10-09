<?php
declare(strict_types=1);

namespace SEOVendor\Evaluator\Rank;

class BradleyTerry
{
    /**
     * @param array<string,array<string,int>> $wins  wins[i][j] = times i beats j
     * @return array<string,float> worth scores normalized to sum=1
     */
    public function fit(array $wins): array
    {
        $brands = [];
        foreach ($wins as $i => $row) {
            $brands[$i] = true;
            foreach ($row as $j => $_) { $brands[$j] = true; }
        }
        $brands = array_keys($brands);
        $w = array_fill_keys($brands, 1.0);
        $maxIter = 200;
        $eps = 1e-6;

        for ($iter=0; $iter<$maxIter; $iter++) {
            $wNew = $w;
            foreach ($brands as $i) {
                $num = 0.0; $den = 0.0;
                foreach ($brands as $j) {
                    $n_ij = $wins[$i][$j] ?? 0;
                    $n_ji = $wins[$j][$i] ?? 0;
                    $n = $n_ij + $n_ji;
                    if ($n > 0) {
                        $num += $n_ij;
                        $den += $n / (1.0 + ($w[$j] / max($w[$i], 1e-12)));
                    }
                }
                if ($den > 0.0) {
                    $wNew[$i] = $num / $den;
                }
            }
            // normalize
            $sum = array_sum($wNew);
            if ($sum > 0) {
                foreach ($wNew as $k => $v) { $wNew[$k] = $v / $sum; }
            }
            $delta = 0.0;
            foreach ($brands as $b) {
                $delta = max($delta, abs($wNew[$b] - $w[$b]));
            }
            $w = $wNew;
            if ($delta < $eps) { break; }
        }
        // final normalization
        $sum = array_sum($w);
        if ($sum > 0) {
            foreach ($w as $k => $v) { $w[$k] = $v / $sum; }
        }
        arsort($w);
        return $w;
    }
}
