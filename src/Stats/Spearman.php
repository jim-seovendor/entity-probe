<?php
declare(strict_types=1);

namespace SEOVendor\Evaluator\Stats;

class Spearman
{
    public static function rho(array $x, array $y): float
    {
        $keys = array_values(array_intersect(array_keys($x), array_keys($y)));
        if (count($keys) < 2) { return 0.0; }
        $rx = self::ranks($x, $keys);
        $ry = self::ranks($y, $keys);
        $n = count($keys);
        $sumd2 = 0.0;
        foreach ($keys as $k) {
            $d = $rx[$k] - $ry[$k];
            $sumd2 += $d * $d;
        }
        return 1.0 - (6.0 * $sumd2) / ($n * ($n*$n - 1.0));
    }

    private static function ranks(array $v, array $keys): array
    {
        $pairs = [];
        foreach ($keys as $k) { $pairs[] = ['k'=>$k, 'v'=>$v[$k]]; }
        usort($pairs, function($a,$b){ return $a['v'] < $b['v'] ? 1 : ($a['v'] > $b['v'] ? -1 : 0); });
        $r = []; $rank = 1;
        foreach ($pairs as $p) { $r[$p['k']] = (float)$rank++; }
        return $r;
    }
}
