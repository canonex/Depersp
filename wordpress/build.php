<?php

declare(strict_types=1);

/*
 * Build the installable WordPress plugin zip:
 *   php wordpress/build.php                   -> dist/depersp-wp-<version>.zip
 *   php wordpress/build.php --expect=1.2.0    also fail unless every version
 *                                             in the plugin is 1.2.0 (CI)
 * The zip contains the plugin plus a copy of the library in depersp/lib/.
 */

$root = dirname(__DIR__);
$plugin = __DIR__ . '/depersp';
$main = file_get_contents($plugin . '/depersp.php');
$readme = file_get_contents($plugin . '/readme.txt');

$versions = [
    'depersp.php header "Version:"' => preg_match('/^\s*\*\s*Version:\s*(\S+)/m', $main, $m) ? $m[1] : null,
    'Depersp_WP::VERSION' => preg_match("/const VERSION = '([^']+)'/", $main, $m) ? $m[1] : null,
    'readme.txt "Stable tag:"' => preg_match('/^Stable tag:\s*(\S+)/m', $readme, $m) ? $m[1] : null,
];
$version = $versions['depersp.php header "Version:"'] ?? 'dev';

$expect = null;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--expect=')) {
        $expect = ltrim(substr($arg, 9), 'v');
    }
}
$problems = [];
foreach ($versions as $where => $v) {
    if ($v !== $version) {
        $problems[] = "$where is " . ($v ?? 'missing') . ", header says $version";
    }
    if ($expect !== null && $v !== $expect) {
        $problems[] = "$where is " . ($v ?? 'missing') . ", expected $expect";
    }
}
if ($problems) {
    fwrite(STDERR, "Version mismatch:\n  " . implode("\n  ", array_unique($problems)) . "\n");
    exit(1);
}

@mkdir($root . '/dist', 0775, true);
$zipPath = "$root/dist/depersp-wp-$version.zip";
@unlink($zipPath);

$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE) !== true) {
    fwrite(STDERR, "Cannot create $zipPath\n");
    exit(1);
}
foreach (['depersp.php', 'readme.txt', 'assets/admin.js'] as $f) {
    $zip->addFile("$plugin/$f", "depersp/$f");
}
$zip->addFile("$root/LICENSE", 'depersp/LICENSE');
foreach (glob("$root/src/*.php") as $f) {
    $zip->addFile($f, 'depersp/lib/' . basename($f));
}
$zip->close();

echo $zipPath, "\n";
