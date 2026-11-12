<?php

declare(strict_types=1);

// Backwards-compatible entry point:  php index.php <image>
// Prints the rectified file name (<image>-dp.png). See bin/depersp for options.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/intersection.php';

$out = depersp($argv[1] ?? '');
echo $out, "\n";
exit($out === ($argv[1] ?? '') ? 1 : 0);
