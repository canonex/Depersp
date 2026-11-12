<?php

declare(strict_types=1);

// Minimal PSR-4 autoloader for installs without Composer.
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'Depersp\\')) {
        $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, 8)) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});
