<?php
declare(strict_types=1);

// Tiny autoloader so the standalone version runs without composer.
// The core is looked up next to this folder (the layout in the repo) and
// inside it (the layout a release zip will use).
spl_autoload_register(function (string $class): void {
    $maps = [
        'LiveChat\\Core\\'       => [__DIR__ . '/core/src/', __DIR__ . '/../core/src/'],
        'LiveChat\\Standalone\\' => [__DIR__ . '/src/'],
    ];

    foreach ($maps as $prefix => $dirs) {
        if (!str_starts_with($class, $prefix)) {
            continue;
        }
        $relative = str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        foreach ($dirs as $dir) {
            if (is_file($dir . $relative)) {
                require $dir . $relative;
                return;
            }
        }
    }
});
