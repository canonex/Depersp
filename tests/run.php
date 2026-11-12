<?php

declare(strict_types=1);

/**
 * Dependency-free test suite.  Run:  php tests/run.php
 * Exit code is non-zero when any test fails (CI friendly).
 */

use Depersp\DetectionException;
use Depersp\Geometry;
use Depersp\InvalidImageException;
use Depersp\QuadDetector;
use Depersp\Rectifier;

require __DIR__ . '/../src/autoload.php';

$root = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/depersp-tests-' . bin2hex(random_bytes(4));
mkdir($tmp, 0700, true);
register_shutdown_function(static function () use ($tmp): void {
    foreach (glob($tmp . '/*') ?: [] as $f) {
        unlink($f);
    }
    @rmdir($tmp);
});

$failures = 0;
$count = 0;
function test(string $name, callable $fn): void
{
    global $failures, $count;
    $count++;
    $t = microtime(true);
    try {
        $fn();
        printf("  ok   %-62s %5.0f ms\n", $name, (microtime(true) - $t) * 1000);
    } catch (Throwable $e) {
        $failures++;
        printf("  FAIL %s\n       %s: %s\n", $name, get_class($e), $e->getMessage());
    }
}
function check(bool $cond, string $msg): void
{
    if (!$cond) {
        throw new RuntimeException($msg);
    }
}
function expectException(string $class, callable $fn): void
{
    try {
        $fn();
    } catch (Throwable $e) {
        check($e instanceof $class, 'expected ' . $class . ', got ' . get_class($e) . ': ' . $e->getMessage());

        return;
    }
    throw new RuntimeException('expected ' . $class . ', nothing thrown');
}
function maxCornerError(array $a, array $b): float
{
    $m = 0.0;
    foreach ($a as $k => $p) {
        $m = max($m, Geometry::distance($p, $b[$k]));
    }

    return $m;
}

/**
 * Project the corners of a flat W x H rectangle (centred at the origin,
 * optionally grown by $grow on every side) through a pinhole camera.
 *
 * @return list<array{0:float,1:float}> TL, TR, BR, BL in image pixels
 */
function project(float $w, float $h, float $grow, array $cam): array
{
    [$rx, $ry, $rz, $dist, $f, $imgW, $imgH] = $cam;
    $out = [];
    foreach ([[-1, -1], [1, -1], [1, 1], [-1, 1]] as [$sx, $sy]) {
        $x = $sx * ($w / 2 + $grow);
        $y = $sy * ($h / 2 + $grow);
        $z = 0.0;
        // rotate around z, then x, then y
        [$x, $y] = [$x * cos($rz) - $y * sin($rz), $x * sin($rz) + $y * cos($rz)];
        [$y, $z] = [$y * cos($rx) - $z * sin($rx), $y * sin($rx) + $z * cos($rx)];
        [$x, $z] = [$x * cos($ry) + $z * sin($ry), -$x * sin($ry) + $z * cos($ry)];
        $z += $dist;
        $out[] = [$imgW / 2 + $f * $x / $z, $imgH / 2 + $f * $y / $z];
    }

    return $out;
}

/** Paper with uneven lighting (built with an operator: the 'gradient:' coder may be disabled by policy). */
function shadedPaper(int $w, int $h): Imagick
{
    $img = new Imagick();
    $img->newImage($w, $h, 'white');
    $img->sparseColorImage(Imagick::SPARSECOLORMETHOD_BARYCENTRIC, [
        0, 0, 0.95, 0.94, 0.91,
        $w, 0, 0.80, 0.78, 0.74,
        0, $h, 0.58, 0.56, 0.52,
    ], Imagick::CHANNEL_RED | Imagick::CHANNEL_GREEN | Imagick::CHANNEL_BLUE);

    return $img;
}

/** Insert a minimal EXIF block carrying only the Orientation tag into a JPEG. */
function withExifOrientation(string $jpeg, int $orientation): string
{
    $tiff = "MM\x00\x2A\x00\x00\x00\x08" . "\x00\x01"
        . "\x01\x12\x00\x03\x00\x00\x00\x01" . pack('n', $orientation) . "\x00\x00"
        . "\x00\x00\x00\x00";
    $data = "Exif\x00\x00" . $tiff;

    return "\xFF\xD8\xFF\xE1" . pack('n', strlen($data) + 2) . $data . substr($jpeg, 2);
}

/** Draw a photo-like test image: shaded paper, thick dark frame, "text" inside. */
function synthPhoto(string $path, array $outer, array $inner, int $w, int $h, bool $gap = false): void
{
    $img = shadedPaper($w, $h);
    $d = new ImagickDraw();
    $d->setFillColor('#1a1a1a');
    $d->polygon(array_map(static fn ($p) => ['x' => $p[0], 'y' => $p[1]], $outer));
    $d->setFillColor('#e6e2da');
    $d->polygon(array_map(static fn ($p) => ['x' => $p[0], 'y' => $p[1]], $inner));
    if ($gap) {
        // Break the left side of the frame open.
        $mid = [($outer[0][0] + $outer[3][0]) / 2, ($outer[0][1] + $outer[3][1]) / 2];
        $d->setFillColor('#e6e2da');
        $d->rectangle($mid[0] - 40, $mid[1] - 30, $mid[0] + 40, $mid[1] + 30);
    }
    // Some ink inside the frame (must not disturb detection).
    $cx = array_sum(array_column($inner, 0)) / 4;
    $cy = array_sum(array_column($inner, 1)) / 4;
    $d->setFillColor('#202020');
    $d->rectangle($cx - 60, $cy - 15, $cx + 60, $cy + 15);
    $d->circle($cx + 30, $cy + 80, $cx + 45, $cy + 80);
    $img->drawImage($d);
    $img->addNoiseImage(Imagick::NOISE_GAUSSIAN, Imagick::CHANNEL_DEFAULT);
    $img->blurImage(0, 0.8);
    $img->setImageFormat('jpeg');
    $img->setImageCompressionQuality(88);
    $img->writeImage($path);
}

echo "Depersp test suite\n";

// --- Geometry -------------------------------------------------------------

test('orderCorners returns TL, TR, BR, BL', function () {
    $o = Geometry::orderCorners([[90, 95], [10, 5], [5, 90], [100, 10]]);
    check($o === [[10, 5], [100, 10], [90, 95], [5, 90]], json_encode($o));
});

test('fitLine ignores outliers', function () {
    $pts = [];
    for ($i = 0; $i < 100; $i++) {
        $pts[] = [$i, 0.5 * $i + 3 + (($i % 7) - 3) * 0.05];
    }
    $pts[] = [50, 80];
    $pts[] = [20, -40];
    $l = Geometry::fitLine($pts);
    check(abs($l[3] / $l[2] - 0.5) < 0.01, 'slope ' . ($l[3] / $l[2]));
});

test('aspect ratio is recovered from a true pinhole projection', function () {
    foreach ([[0.5, 1.0], [0.7071, 1.0], [1.0, 1.0], [1.5, 1.0]] as [$ar, $_]) {
        foreach ([[0.5, 0.3, 0.2], [-0.4, 0.5, -0.3], [0.6, -0.2, 0.1]] as [$rx, $ry, $rz]) {
            $c = project(1000 * $ar, 1000, 0, [$rx, $ry, $rz, 2600, 1800, 2000, 1500]);
            $est = Geometry::estimateAspectRatio($c, 2000, 1500);
            check(abs($est / $ar - 1) < 0.01, sprintf('ar %.3f est %.3f (rot %.1f,%.1f,%.1f)', $ar, $est, $rx, $ry, $rz));
        }
    }
});

test('aspect ratio with pure keystone uses the default focal length', function () {
    // Camera f = 0.72 * diagonal, close to the 0.7 default.
    foreach ([[0.65, 0.0, 0.0], [0.0, 0.55, 0.0], [0.65, 0.01, 0.0], [0.5, 0.0, 0.02]] as [$rx, $ry, $rz]) {
        $c = project(707, 1000, 0, [$rx, $ry, $rz, 2600, 1800, 2000, 1500]);
        $est = Geometry::estimateAspectRatio($c, 2000, 1500);
        check(abs($est / 0.707 - 1) < 0.04, sprintf('est %.3f (rot %.2f,%.2f,%.2f)', $est, $rx, $ry, $rz));
    }
});

// --- Reference image ------------------------------------------------------

test('reference photo 0-test.jpeg is rectified like 0-test.jpeg-dp.png', function () use ($root, $tmp) {
    $in = $root . '/0-test.jpeg';
    $hash = sha1_file($in);
    $res = (new Rectifier())->rectifyFile($in, $tmp . '/ref.png');
    check(sha1_file($in) === $hash, 'input file was modified');
    // Inner edge of the drawn frame, measured by hand on the 512px photo.
    $expected = [[62, 40], [457, 47], [433, 410], [85, 475]];
    $err = maxCornerError($res->corners, $expected);
    check($err < 4, sprintf('corner error %.1f px: %s', $err, json_encode($res->corners)));
    check(abs($res->width / $res->height - 1) < 0.06, "square sheet expected, got {$res->width}x{$res->height}");

    // Compare with the reference output after bringing both to the same size.
    $a = new Imagick($tmp . '/ref.png');
    $b = new Imagick($root . '/0-test.jpeg-dp.png');
    $a->resizeImage(256, 256, Imagick::FILTER_TRIANGLE, 1);
    $b->resizeImage(256, 256, Imagick::FILTER_TRIANGLE, 1);
    [, $rmse] = $a->compareImages($b, Imagick::METRIC_ROOTMEANSQUAREDERROR);
    check($rmse < 0.10, sprintf('normalised RMSE vs reference %.3f', $rmse));
});

test('fixed output size (legacy 1024x1024 behaviour)', function () use ($root, $tmp) {
    $res = (new Rectifier(outputWidth: 1024, outputHeight: 1024))->rectifyFile($root . '/0-test.jpeg', $tmp . '/ref1024.jpg');
    $i = new Imagick($tmp . '/ref1024.jpg');
    check($i->getImageWidth() === 1024 && $i->getImageHeight() === 1024, 'size');
    check($i->getImageFormat() === 'JPEG', 'format');
});

// --- Synthetic photos with known ground truth ------------------------------

$cases = [
    'A4 portrait, mild tilt' => [0.7071, [0.25, 0.15, 0.05]],
    'A4 portrait, strong keystone' => [0.7071, [0.65, 0.0, 0.0]],
    'landscape, rotated 30 deg' => [1.4142, [0.3, -0.3, 0.52]],
    'square, side view' => [1.0, [0.0, 0.6, -0.1]],
    'upside-down-ish, 170 deg' => [0.7071, [0.2, 0.2, 2.97]],
];
foreach ($cases as $name => [$ar, $rot]) {
    test("synthetic: $name", function () use ($ar, $rot, $tmp) {
        $W = 1600;
        $H = 1200;
        $cam = [$rot[0], $rot[1], $rot[2], 2200, 1500, $W, $H];
        $inner = project(800 * $ar, 800, 0, $cam);
        $outer = project(800 * $ar, 800, 25, $cam);
        $path = $tmp . '/synth.jpg';
        synthPhoto($path, $outer, $inner, $W, $H);
        $res = (new Rectifier())->rectifyFile($path, $tmp . '/synth-out.png');
        $err = maxCornerError($res->corners, Geometry::orderCorners($inner));
        check($err < 0.006 * hypot($W, $H), sprintf('corner error %.1f px', $err));
        $got = $res->width / $res->height;
        check(abs($got / $ar - 1) < 0.05, sprintf('aspect %.3f, expected %.3f', $got, $ar));
    });
}

// --- Failure modes -------------------------------------------------------

test('blank paper -> DetectionException', function () use ($tmp) {
    $img = shadedPaper(800, 600);
    $img->writeImage('jpeg:' . $tmp . '/blank.jpg');
    expectException(DetectionException::class, fn () => (new Rectifier())->rectifyFile($tmp . '/blank.jpg', $tmp . '/x.png'));
});

test('frame with a big gap -> DetectionException', function () use ($tmp) {
    $cam = [0.2, 0.1, 0.05, 2200, 1500, 1600, 1200];
    synthPhoto($tmp . '/gap.jpg', project(800, 800, 25, $cam), project(800, 800, 0, $cam), 1600, 1200, true);
    expectException(DetectionException::class, fn () => (new Rectifier())->rectifyFile($tmp . '/gap.jpg', $tmp . '/x.png'));
});

test('round blob is not accepted as a sheet', function () use ($tmp) {
    $img = new Imagick();
    $img->newImage(800, 600, 'white');
    $d = new ImagickDraw();
    $d->setStrokeColor('black');
    $d->setStrokeWidth(12);
    $d->setFillColor('white');
    $d->circle(400, 300, 400, 80);
    $img->drawImage($d);
    $img->writeImage('png:' . $tmp . '/circle.png');
    expectException(DetectionException::class, fn () => (new Rectifier())->rectifyFile($tmp . '/circle.png', $tmp . '/x.png'));
});

test('non-image upload -> InvalidImageException', function () use ($tmp) {
    file_put_contents($tmp . '/evil.jpg', "<?php echo 'hi';");
    expectException(InvalidImageException::class, fn () => (new Rectifier())->load($tmp . '/evil.jpg'));
});

test('SVG/MVG renamed to .jpg is refused (no ImageTragick-style decoding)', function () use ($tmp) {
    file_put_contents($tmp . '/vector.jpg', '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><image href="file:///etc/passwd"/></svg>');
    expectException(InvalidImageException::class, fn () => (new Rectifier())->load($tmp . '/vector.jpg'));
    file_put_contents($tmp . '/vector.mvg', "push graphic-context\nviewbox 0 0 10 10\nimage over 0,0 0,0 'text:/etc/passwd'\npop graphic-context");
    expectException(InvalidImageException::class, fn () => (new Rectifier())->load($tmp . '/vector.mvg'));
});

test('oversized image is refused before decoding', function () use ($tmp) {
    $img = new Imagick();
    $img->newImage(3000, 2000, 'white');
    $img->writeImage('png:' . $tmp . '/huge.png');
    expectException(InvalidImageException::class, fn () => (new Rectifier(maxPixels: 1_000_000))->load($tmp . '/huge.png'));
});

test('missing file -> InvalidImageException', function () {
    expectException(InvalidImageException::class, fn () => (new Rectifier())->load('/nonexistent/file.jpg'));
});

test('EXIF orientation is honoured', function () use ($root, $tmp) {
    // Rotate the pixels 90° but tag the file so viewers rotate it back.
    $img = new Imagick($root . '/0-test.jpeg');
    $img->rotateImage('white', -90);
    $img->setImageFormat('jpeg');
    file_put_contents($tmp . '/rotated.jpg', withExifOrientation($img->getImageBlob(), 6)); // 6 = rotate 90° CW to view
    $res = (new Rectifier())->rectifyFile($tmp . '/rotated.jpg', $tmp . '/rotated-out.png');
    $expected = [[62, 40], [457, 47], [433, 410], [85, 475]];
    check(maxCornerError($res->corners, $expected) < 5, json_encode($res->corners));
});

test('large photo (12 MP) stays within time and memory budget', function () use ($root, $tmp) {
    $img = new Imagick($root . '/0-test.jpeg');
    $img->resizeImage(4000, 3000, Imagick::FILTER_LANCZOS, 1);
    $img->writeImage('jpeg:' . $tmp . '/big.jpg');
    $before = memory_get_peak_usage(true);
    $res = (new Rectifier())->rectifyFile($tmp . '/big.jpg', $tmp . '/big-out.jpg');
    check($res->seconds < 5, sprintf('took %.2fs', $res->seconds));
    check(max($res->width, $res->height) === 2048, 'output capped at 2048');
    check(memory_get_peak_usage(true) - $before < 128 * 1024 * 1024, 'PHP memory');
});

printf("\n%d tests, %d failed\n", $count, $failures);
exit($failures > 0 ? 1 : 0);
