<?php

declare(strict_types=1);

/*
 * Legacy API kept for existing callers. New code should use Depersp\Rectifier
 * directly (see README.md): it reports *why* a photo could not be processed.
 */

require_once __DIR__ . '/src/autoload.php';

if (!function_exists('depersp')) {
    /**
     * Rectify $imageName into "$imageName-dp.png" (1024x1024, like the original
     * shell pipeline). Returns the new file name, or $imageName unchanged when
     * the image cannot be processed. The input file is never modified.
     */
    function depersp(string $imageName, bool $debug = false): string
    {
        try {
            return (new Depersp\Rectifier(outputWidth: 1024, outputHeight: 1024))
                ->rectifyFile($imageName, $imageName . '-dp.png')
                ->outputPath;
        } catch (Depersp\DeperspException $e) {
            if ($debug) {
                error_log('depersp: ' . $e->getMessage());
            }

            return $imageName;
        }
    }
}
