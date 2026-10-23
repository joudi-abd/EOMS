<?php

use Composer\Autoload\ClassLoader;

/*
 * Composer loads class files with include. A second include compiles the class
 * again, which is the "Cannot redeclare class" fatal. include_once treats a
 * Windows path with \ and the same path with / as one file.
 *
 * laravel/pint publishes App\, Database\Seeders\, and Database\Factories\,
 * and Composer merges those directories with this application.
 */

$useIncludeOnce = Closure::bind(static function (): void {
    self::$includeFile = static function (string $file): void {
        include_once $file;
    };
}, null, ClassLoader::class);

$useIncludeOnce();

foreach (ClassLoader::getRegisteredLoaders() as $loader) {
    foreach (['App\\', 'Database\\Seeders\\', 'Database\\Factories\\'] as $prefix) {
        $directories = $loader->getPrefixesPsr4()[$prefix] ?? null;

        if ($directories === null) {
            continue;
        }

        $loader->setPsr4($prefix, array_values(array_filter(
            $directories,
            static fn (string $directory): bool => ! str_contains(str_replace('\\', '/', $directory), '/laravel/pint/'),
        )));
    }
}
