<?php

declare(strict_types=1);

/*
 * Minimal examples.   php examples/basic.php
 */

use Depersp\DetectionException;
use Depersp\InvalidImageException;
use Depersp\Rectifier;

// With Composer: require 'vendor/autoload.php';
require __DIR__ . '/../src/autoload.php';

$photo = __DIR__ . '/../tests/fixtures/sample.jpeg';
$rectifier = new Rectifier();

try {
    // 1. In memory: bytes in, bytes out. Nothing touches the disk.
    $result = $rectifier->rectifyBlob(file_get_contents($photo), 'png');
    printf("In memory: %dx%d %s, %d bytes, %.2fs\n",
        $result->width, $result->height, $result->mimeType, strlen($result->data), $result->seconds);

    // 2. File to file (written atomically; the input is never modified).
    $out = sys_get_temp_dir() . '/depersp-example.jpg';
    $result = (new Rectifier(outputWidth: 1024, outputHeight: 1024, quality: 85))->rectifyFile($photo, $out);
    printf("To file:   %s (%dx%d)\n", $result->outputPath, $result->width, $result->height);
    unlink($out); // this is just a demo: don't leave it behind

    // 3. Detected corners (top-left, top-right, bottom-right, bottom-left).
    foreach ($result->corners as [$x, $y]) {
        printf("  corner %.1f, %.1f\n", $x, $y);
    }
} catch (DetectionException $e) {
    echo "No frame found: {$e->getMessage()}\n"; // valid photo, but nothing to straighten
} catch (InvalidImageException $e) {
    echo "Not a usable image: {$e->getMessage()}\n";
}
