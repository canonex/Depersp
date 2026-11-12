<?php

declare(strict_types=1);

namespace Depersp;

/**
 * Pure-math helpers. Points are [x, y] float pairs, lines are
 * [px, py, dx, dy] (a point on the line and a unit direction).
 */
final class Geometry
{
    /**
     * Convex hull (Andrew's monotone chain), counter-clockwise in a y-down frame.
     *
     * @param list<array{0:float,1:float}> $points
     * @return list<array{0:float,1:float}>
     */
    public static function convexHull(array $points): array
    {
        $n = count($points);
        if ($n < 3) {
            return $points;
        }
        usort($points, static fn ($a, $b) => $a[0] <=> $b[0] ?: $a[1] <=> $b[1]);

        $cross = static fn ($o, $a, $b) => ($a[0] - $o[0]) * ($b[1] - $o[1]) - ($a[1] - $o[1]) * ($b[0] - $o[0]);

        $lower = [];
        foreach ($points as $p) {
            while (count($lower) >= 2 && $cross($lower[count($lower) - 2], $lower[count($lower) - 1], $p) <= 0) {
                array_pop($lower);
            }
            $lower[] = $p;
        }
        $upper = [];
        for ($i = $n - 1; $i >= 0; $i--) {
            $p = $points[$i];
            while (count($upper) >= 2 && $cross($upper[count($upper) - 2], $upper[count($upper) - 1], $p) <= 0) {
                array_pop($upper);
            }
            $upper[] = $p;
        }
        array_pop($lower);
        array_pop($upper);

        return array_merge($lower, $upper);
    }

    /** Signed area (shoelace). */
    public static function polygonArea(array $poly): float
    {
        $a = 0.0;
        $n = count($poly);
        for ($i = 0; $i < $n; $i++) {
            $p = $poly[$i];
            $q = $poly[($i + 1) % $n];
            $a += $p[0] * $q[1] - $q[0] * $p[1];
        }

        return $a / 2.0;
    }

    /**
     * Largest-area quadrilateral whose vertices are hull vertices.
     * Seeded with axis/diagonal extremes, then improved by coordinate ascent
     * (move one vertex at a time to the best hull point between its neighbours).
     *
     * @param list<array{0:float,1:float}> $hull convex hull, ordered
     * @return list<array{0:float,1:float}> 4 points in hull order
     */
    public static function maxAreaQuad(array $hull): array
    {
        $n = count($hull);
        if ($n < 4) {
            throw new DetectionException('Contour too small to fit a quadrilateral.');
        }

        // Seed: extreme points along x+y and x-y (works well up to ~45° rotation).
        $seed = [0, 0, 0, 0];
        $best = [INF, -INF, -INF, INF]; // min(x+y), max(x-y), max(x+y), min(x-y)
        foreach ($hull as $i => [$x, $y]) {
            if ($x + $y < $best[0]) { $best[0] = $x + $y; $seed[0] = $i; }
            if ($x - $y > $best[1]) { $best[1] = $x - $y; $seed[1] = $i; }
            if ($x + $y > $best[2]) { $best[2] = $x + $y; $seed[2] = $i; }
            if ($x - $y < $best[3]) { $best[3] = $x - $y; $seed[3] = $i; }
        }
        $idx = array_values(array_unique($seed));
        if (count($idx) < 4) {
            $idx = [0, intdiv($n, 4), intdiv($n, 2), intdiv(3 * $n, 4)];
        }
        sort($idx);

        $area = static function (array $ids) use ($hull): float {
            return abs(self::polygonArea([$hull[$ids[0]], $hull[$ids[1]], $hull[$ids[2]], $hull[$ids[3]]]));
        };

        $current = $area($idx);
        for ($iter = 0; $iter < 20; $iter++) {
            $improved = false;
            for ($k = 0; $k < 4; $k++) {
                $prev = $idx[($k + 3) % 4];
                $next = $idx[($k + 1) % 4];
                // Walk the hull strictly between prev and next.
                for ($j = ($prev + 1) % $n; $j !== $next; $j = ($j + 1) % $n) {
                    $cand = $idx;
                    $cand[$k] = $j;
                    $a = $area($cand);
                    if ($a > $current + 1e-9) {
                        $current = $a;
                        $idx = $cand;
                        $improved = true;
                    }
                }
            }
            if (!$improved) {
                break;
            }
        }

        return [$hull[$idx[0]], $hull[$idx[1]], $hull[$idx[2]], $hull[$idx[3]]];
    }

    /**
     * Total-least-squares line fit with iterative outlier rejection.
     *
     * @param list<array{0:float,1:float}> $points
     * @return array{0:float,1:float,2:float,3:float}|null [px, py, dx, dy]
     */
    public static function fitLine(array $points, int $iterations = 3): ?array
    {
        $line = null;
        for ($it = 0; $it <= $iterations; $it++) {
            $n = count($points);
            if ($n < 2) {
                return $line;
            }
            $mx = $my = 0.0;
            foreach ($points as [$x, $y]) {
                $mx += $x;
                $my += $y;
            }
            $mx /= $n;
            $my /= $n;
            $sxx = $syy = $sxy = 0.0;
            foreach ($points as [$x, $y]) {
                $dx = $x - $mx;
                $dy = $y - $my;
                $sxx += $dx * $dx;
                $syy += $dy * $dy;
                $sxy += $dx * $dy;
            }
            // Principal eigenvector of the 2x2 covariance matrix.
            $theta = 0.5 * atan2(2 * $sxy, $sxx - $syy);
            $line = [$mx, $my, cos($theta), sin($theta)];

            if ($it === $iterations) {
                break;
            }
            // Residuals = distance along the normal.
            $nx = -$line[3];
            $ny = $line[2];
            $res = [];
            $sum = 0.0;
            foreach ($points as $i => [$x, $y]) {
                $r = abs(($x - $mx) * $nx + ($y - $my) * $ny);
                $res[$i] = $r;
                $sum += $r * $r;
            }
            $rms = sqrt($sum / $n);
            $limit = max(1.0, 2.0 * $rms);
            $kept = [];
            foreach ($points as $i => $p) {
                if ($res[$i] <= $limit) {
                    $kept[] = $p;
                }
            }
            if (count($kept) === $n || count($kept) < 2) {
                break;
            }
            $points = $kept;
        }

        return $line;
    }

    /** Intersection of two infinite lines, or null when (almost) parallel. */
    public static function intersect(array $l1, array $l2): ?array
    {
        $den = $l1[2] * $l2[3] - $l1[3] * $l2[2];
        if (abs($den) < 1e-9) {
            return null;
        }
        $t = (($l2[0] - $l1[0]) * $l2[3] - ($l2[1] - $l1[1]) * $l2[2]) / $den;

        return [$l1[0] + $t * $l1[2], $l1[1] + $t * $l1[3]];
    }

    /**
     * Order 4 points as top-left, top-right, bottom-right, bottom-left (y-down image frame).
     *
     * @param list<array{0:float,1:float}> $pts
     * @return list<array{0:float,1:float}>
     */
    public static function orderCorners(array $pts): array
    {
        $cx = array_sum(array_column($pts, 0)) / 4;
        $cy = array_sum(array_column($pts, 1)) / 4;
        // Clockwise on screen == increasing atan2 in a y-down frame.
        usort($pts, static fn ($a, $b) => atan2($a[1] - $cy, $a[0] - $cx) <=> atan2($b[1] - $cy, $b[0] - $cx));
        // Rotate so that the point with the smallest x+y comes first.
        $start = 0;
        foreach ($pts as $i => $p) {
            if ($p[0] + $p[1] < $pts[$start][0] + $pts[$start][1]) {
                $start = $i;
            }
        }

        return array_merge(array_slice($pts, $start), array_slice($pts, 0, $start));
    }

    public static function isConvex(array $quad): bool
    {
        $sign = 0;
        for ($i = 0; $i < 4; $i++) {
            $a = $quad[$i];
            $b = $quad[($i + 1) % 4];
            $c = $quad[($i + 2) % 4];
            $z = ($b[0] - $a[0]) * ($c[1] - $b[1]) - ($b[1] - $a[1]) * ($c[0] - $b[0]);
            if (abs($z) < 1e-9) {
                return false;
            }
            $s = $z > 0 ? 1 : -1;
            if ($sign !== 0 && $s !== $sign) {
                return false;
            }
            $sign = $s;
        }

        return true;
    }

    public static function distance(array $a, array $b): float
    {
        return hypot($a[0] - $b[0], $a[1] - $b[1]);
    }

    /**
     * Estimate the real width/height ratio of a rectangle seen in perspective
     * (Zhang & He, "Whiteboard scanning and image enhancement", 2007),
     * assuming square pixels and the principal point at the image centre.
     * When the focal length cannot be recovered from the quad (pure keystone or
     * near-affine views) $defaultFocal * image diagonal is assumed: 0.7 matches
     * the main camera of most phones (~26-28 mm equivalent).
     *
     * @param list<array{0:float,1:float}> $c TL, TR, BR, BL
     */
    public static function estimateAspectRatio(array $c, float $imgW, float $imgH, float $defaultFocal = 0.7): float
    {
        $u0 = $imgW / 2;
        $v0 = $imgH / 2;
        $h = static fn ($p) => [$p[0] - $u0, $p[1] - $v0, 1.0];
        // Paper notation: m1 TL, m2 TR, m3 BL, m4 BR.
        [$m1, $m2, $m4, $m3] = array_map($h, $c);

        $cross = static fn ($a, $b) => [
            $a[1] * $b[2] - $a[2] * $b[1],
            $a[2] * $b[0] - $a[0] * $b[2],
            $a[0] * $b[1] - $a[1] * $b[0],
        ];
        $dot = static fn ($a, $b) => $a[0] * $b[0] + $a[1] * $b[1] + $a[2] * $b[2];

        $fallback = (
            (self::distance($c[0], $c[1]) + self::distance($c[3], $c[2])) /
            max(1e-9, self::distance($c[0], $c[3]) + self::distance($c[1], $c[2]))
        );

        $d2 = $dot($cross($m2, $m4), $m3);
        $d3 = $dot($cross($m3, $m4), $m2);
        if (abs($d2) < 1e-9 || abs($d3) < 1e-9) {
            return $fallback;
        }
        $k2 = $dot($cross($m1, $m4), $m3) / $d2;
        $k3 = $dot($cross($m1, $m4), $m2) / $d3;
        $n2 = [$k2 * $m2[0] - $m1[0], $k2 * $m2[1] - $m1[1], $k2 - 1.0];
        $n3 = [$k3 * $m3[0] - $m1[0], $k3 * $m3[1] - $m1[1], $k3 - 1.0];

        $diag = hypot($imgW, $imgH);
        $f2 = null;
        // The focal length is only observable when both vanishing points are
        // finite; with a pure keystone (one pair of sides parallel) n.z ~ 0.
        if (abs($n2[2]) > 0.02 && abs($n3[2]) > 0.02) {
            $est = -($n2[0] * $n3[0] + $n2[1] * $n3[1]) / ($n2[2] * $n3[2]);
            // Accept only plausible focal lengths (roughly 15°..140° field of view).
            if ($est > (0.2 * $diag) ** 2 && $est < (8 * $diag) ** 2) {
                $f2 = $est;
            }
        }
        $f2 ??= ($defaultFocal * $diag) ** 2;

        $a = ($n2[0] ** 2 + $n2[1] ** 2) / $f2 + $n2[2] ** 2;
        $b = ($n3[0] ** 2 + $n3[1] ** 2) / $f2 + $n3[2] ** 2;
        $ratio = $b > 0 ? sqrt($a / $b) : $fallback;

        // Guard against numerically wild results.
        if (!is_finite($ratio) || $ratio <= 0 || $ratio > 2 * $fallback || $ratio < 0.5 * $fallback) {
            return $fallback;
        }

        return $ratio;
    }
}
