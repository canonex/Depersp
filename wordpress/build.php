<?php

declare(strict_types=1);

/*
 * Build the installable WordPress plugin zip:
 *   php wordpress/build.php            -> dist/depersp-wp-<version>.zip
 * The zip contains the plugin plus a copy of the library in depersp/lib/.
 */

$root = dirname(__DIR__);
$plugin = __DIR__ . '/depersp';
preg_match('/^\s*\*\s*Version:\s*(\S+)/m', file_get_contents($plugin . '/depersp.php'), $m);
$version = $m[1] ?? 'dev';

@mkdir($root . '/dist', 0775, true);
$zipPath = "$root/dist/depersp-wp-$version.zip";
@unlink($zipPath);

$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE) !== true) {
    fwrite(STDERR, "Cannot create $zipPath\n");
    exit(1);
}
foreach (['depersp.php', 'readme.txt'] as $f) {
    $zip->addFile("$plugin/$f", "depersp/$f");
}
$zip->addFile("$root/LICENSE", 'depersp/LICENSE');
foreach (glob("$root/src/*.php") as $f) {
    $zip->addFile($f, 'depersp/lib/' . basename($f));
}
$zip->close();

echo $zipPath, "\n";
