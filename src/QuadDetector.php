<?php

declare(strict_types=1);

namespace Depersp;

use Imagick;

/**
 * Finds the four corners of a dark rectangular frame drawn on a light sheet.
 *
 * Pipeline (all in-process, no shell commands):
 *  1. downscale to a working size and convert to grayscale;
 *  2. classify "ink" pixels against a local background estimate, which makes
 *     the threshold robust to uneven lighting and shadows;
 *  3. flood-fill the light background from the image border: light pixels the
 *     fill cannot reach are enclosed by ink; the largest enclosed region is the
 *     inside of the frame;
 *  4. take that region's outer contour, find the largest inscribed quadrilateral
 *     of its convex hull, then refine each side with a robust least-squares
 *     line fit and intersect the four lines for sub-pixel corners.
 */
final class QuadDetector
{
    private const FREE = 0;
    private const INK = 1;
    private const OUTSIDE = 2;
    private const OTHER = 3;
    private const REGION = 4;

    public function __construct(
        /** Longest side, in pixels, of the image analysed. */
        private readonly int $workSize = 800,
        /**
         * Detection passes, tried in order until one succeeds: [inkRatio, closeRadius].
         * inkRatio: a pixel is ink when darker than this fraction of the local
         * background (higher = accepts fainter lines). closeRadius: ink is dilated
         * by this many working pixels to close gaps in thin or broken frames.
         * Later passes only run when the earlier ones fail.
         *
         * @var list<array{0:float,1:int}>
         */
        private readonly array $passes = [[0.6, 1], [0.7, 3], [0.85, 4]],
        /** Minimum frame area as a fraction of the image area. */
        private readonly float $minAreaRatio = 0.05,
        /** Minimum (region area / fitted quad area); rejects non-rectangular blobs. */
        private readonly float $minFill = 0.85,
    ) {
    }

    /**
     * @return list<array{0:float,1:float}> TL, TR, BR, BL in the coordinates of $image
     *
     * @throws DetectionException
     */
    public function detect(Imagick $image): array
    {
        $origW = $image->getImageWidth();
        $origH = $image->getImageHeight();

        $work = clone $image;
        $scale = min(1.0, $this->workSize / max($origW, $origH));
        if ($scale < 1.0) {
            // Shrink first: the grayscale conversion then runs on few pixels.
            $work->resizeImage(max(1, (int) round($origW * $scale)), max(1, (int) round($origH * $scale)), Imagick::FILTER_TRIANGLE, 1);
        }
        $work->transformImageColorspace(Imagick::COLORSPACE_GRAY);
        $w = $work->getImageWidth();
        $h = $work->getImageHeight();

        // Local background: heavy low-pass done cheaply by shrinking and enlarging.
        $bg = clone $work;
        $bg->resizeImage(max(1, intdiv($w, 24)), max(1, intdiv($h, 24)), Imagick::FILTER_BOX, 1);
        $bg->resizeImage($w, $h, Imagick::FILTER_TRIANGLE, 1);

        $gray = $work->exportImagePixels(0, 0, $w, $h, 'I', Imagick::PIXEL_CHAR);
        $back = $bg->exportImagePixels(0, 0, $w, $h, 'I', Imagick::PIXEL_CHAR);
        $work->clear();
        $bg->clear();

        $error = new DetectionException('No detection pass configured.');
        foreach ($this->passes as [$ratio, $radius]) {
            try {
                $corners = $this->detectPass($gray, $back, $w, $h, (float) $ratio, (int) $radius);
            } catch (DetectionException $e) {
                $error = $e;
                continue;
            }
            $sx = $origW / $w;
            $sy = $origH / $h;

            return array_map(static fn ($p) => [$p[0] * $sx, $p[1] * $sy], $corners);
        }

        throw $error;
    }

    /**
     * One detection attempt on the working image.
     *
     * @param list<int> $gray
     * @param list<int> $back
     * @return list<array{0:float,1:float}> corners in working-image pixels
     */
    private function detectPass(array $gray, array $back, int $w, int $h, float $ratio, int $radius): array
    {
        $n = $w * $h;
        $state = array_fill(0, $n, self::FREE);
        for ($i = 0; $i < $n; $i++) {
            if ($gray[$i] < $back[$i] * $ratio) {
                $state[$i] = self::INK;
            }
        }

        if ($radius > 0) {
            $state = $this->dilate($state, $w, $h, $radius);
        }

        // 3. Flood the background from every border pixel.
        $border = [];
        for ($x = 0; $x < $w; $x++) {
            $border[] = $x;
            $border[] = ($h - 1) * $w + $x;
        }
        for ($y = 1; $y < $h - 1; $y++) {
            $border[] = $y * $w;
            $border[] = $y * $w + $w - 1;
        }
        $this->flood($state, $w, $h, $border, self::FREE, self::OUTSIDE);

        // Largest enclosed light region = inside of the frame.
        $bestSize = 0;
        $bestSeed = -1;
        for ($i = 0; $i < $n; $i++) {
            if ($state[$i] === self::FREE) {
                $size = $this->flood($state, $w, $h, [$i], self::FREE, self::OTHER);
                if ($size > $bestSize) {
                    $bestSize = $size;
                    $bestSeed = $i;
                }
            }
        }
        if ($bestSeed < 0 || $bestSize < $this->minAreaRatio * $n) {
            throw new DetectionException('No closed frame found in the image.');
        }
        $this->flood($state, $w, $h, [$bestSeed], self::OTHER, self::REGION);

        // 4. Outer contour of the region: region pixels touching anything that is
        //    reachable from the image border without crossing the region (this
        //    ignores the outlines of text or drawings inside the frame).
        $outer = array_fill(0, $n, false);
        $queue = [];
        foreach ($border as $i) {
            if ($state[$i] !== self::REGION && !$outer[$i]) {
                $outer[$i] = true;
                $queue[] = $i;
            }
        }
        for ($head = 0; $head < count($queue); $head++) {
            $i = $queue[$head];
            $x = $i % $w;
            foreach ([$x > 0 ? $i - 1 : -1, $x < $w - 1 ? $i + 1 : -1, $i - $w, $i + $w] as $j) {
                if ($j >= 0 && $j < $n && !$outer[$j] && $state[$j] !== self::REGION) {
                    $outer[$j] = true;
                    $queue[] = $j;
                }
            }
        }
        $filledArea = $n - count($queue);
        unset($queue);

        $contour = [];
        for ($i = 0; $i < $n; $i++) {
            if ($state[$i] !== self::REGION) {
                continue;
            }
            $x = $i % $w;
            if (($x > 0 && $outer[$i - 1]) || ($x < $w - 1 && $outer[$i + 1])
                || ($i >= $w && $outer[$i - $w]) || ($i + $w < $n && $outer[$i + $w])) {
                // Pixel centres follow the ImageMagick convention (+0.5).
                $contour[] = [$x + 0.5, intdiv($i, $w) + 0.5];
            }
        }
        unset($state, $outer);

        $hull = Geometry::convexHull($contour);
        $quad = Geometry::orderCorners(Geometry::maxAreaQuad($hull));
        // A real quadrilateral region coincides with its inscribed quad: the fill
        // ratio is ~1. Concave blobs go below, round ones (circle: pi/2) above.
        $quadArea = abs(Geometry::polygonArea($quad));
        $fill = $quadArea > 0 ? $filledArea / $quadArea : 0.0;
        if ($fill < $this->minFill || $fill > 2.0 - $this->minFill) {
            throw new DetectionException(sprintf('The enclosed region is not a quadrilateral (fill %.2f).', $fill));
        }

        return $this->refine($quad, $contour, $w, $h, $radius);
    }

    /**
     * Fit a line to the contour points lying along each side of the rough
     * quad and intersect neighbouring lines. Falls back to the rough corner
     * whenever the refinement looks unreliable. Each line is moved outwards by
     * $offset pixels to undo the shrinking caused by dilating the ink.
     */
    private function refine(array $quad, array $contour, int $w, int $h, float $offset): array
    {
        $cx = ($quad[0][0] + $quad[1][0] + $quad[2][0] + $quad[3][0]) / 4;
        $cy = ($quad[0][1] + $quad[1][1] + $quad[2][1] + $quad[3][1]) / 4;
        $diag = hypot($w, $h);
        $tol = max(3.0, 0.02 * $diag);
        $lines = [];
        for ($k = 0; $k < 4; $k++) {
            $a = $quad[$k];
            $b = $quad[($k + 1) % 4];
            $len = Geometry::distance($a, $b);
            if ($len < 1) {
                return $quad;
            }
            $dx = ($b[0] - $a[0]) / $len;
            $dy = ($b[1] - $a[1]) / $len;
            $pts = [];
            foreach ($contour as $p) {
                $rx = $p[0] - $a[0];
                $ry = $p[1] - $a[1];
                $t = ($rx * $dx + $ry * $dy) / $len;
                // Skip the rounded/blurred corner zones.
                if ($t < 0.1 || $t > 0.9) {
                    continue;
                }
                if (abs($rx * -$dy + $ry * $dx) <= $tol) {
                    $pts[] = $p;
                }
            }
            if (count($pts) < 10) {
                return $quad;
            }
            $line = Geometry::fitLine($pts);
            if ($line === null) {
                return $quad;
            }
            // Outward normal: pointing away from the quad centre.
            $nx = -$line[3];
            $ny = $line[2];
            if (($line[0] - $cx) * $nx + ($line[1] - $cy) * $ny < 0) {
                $nx = -$nx;
                $ny = -$ny;
            }
            $line[0] += $nx * $offset;
            $line[1] += $ny * $offset;
            $lines[$k] = $line;
        }

        $out = [];
        for ($k = 0; $k < 4; $k++) {
            // Corner k is shared by side k-1 (ending at it) and side k (starting at it).
            $p = Geometry::intersect($lines[($k + 3) % 4], $lines[$k]);
            if ($p === null || Geometry::distance($p, $quad[$k]) > 0.05 * $diag) {
                return $quad;
            }
            $out[] = $p;
        }

        return Geometry::isConvex($out) ? $out : $quad;
    }

    /**
     * Breadth-first flood fill over 4-connected pixels whose state is $from.
     *
     * @param list<int> $state
     * @param list<int> $seeds
     * @return int number of pixels changed
     */
    private function flood(array &$state, int $w, int $h, array $seeds, int $from, int $to): int
    {
        $n = $w * $h;
        $queue = [];
        foreach ($seeds as $s) {
            if ($state[$s] === $from) {
                $state[$s] = $to;
                $queue[] = $s;
            }
        }
        for ($head = 0; $head < count($queue); $head++) {
            $i = $queue[$head];
            $x = $i % $w;
            if ($x > 0 && $state[$i - 1] === $from) { $state[$i - 1] = $to; $queue[] = $i - 1; }
            if ($x < $w - 1 && $state[$i + 1] === $from) { $state[$i + 1] = $to; $queue[] = $i + 1; }
            if ($i >= $w && $state[$i - $w] === $from) { $state[$i - $w] = $to; $queue[] = $i - $w; }
            if ($i + $w < $n && $state[$i + $w] === $from) { $state[$i + $w] = $to; $queue[] = $i + $w; }
        }

        return count($queue);
    }

    /** Separable square dilation of the INK class. */
    private function dilate(array $state, int $w, int $h, int $r): array
    {
        $tmp = $state;
        for ($y = 0; $y < $h; $y++) {
            $row = $y * $w;
            for ($x = 0; $x < $w; $x++) {
                if ($state[$row + $x] === self::INK) {
                    for ($k = max(0, $x - $r), $e = min($w - 1, $x + $r); $k <= $e; $k++) {
                        $tmp[$row + $k] = self::INK;
                    }
                }
            }
        }
        $out = $tmp;
        for ($y = 0; $y < $h; $y++) {
            $row = $y * $w;
            for ($x = 0; $x < $w; $x++) {
                if ($tmp[$row + $x] === self::INK) {
                    for ($k = max(0, $y - $r), $e = min($h - 1, $y + $r); $k <= $e; $k++) {
                        $out[$k * $w + $x] = self::INK;
                    }
                }
            }
        }

        return $out;
    }
}
