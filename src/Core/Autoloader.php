<?php

declare(strict_types=1);

namespace PitchRooms\Core;

/**
 * PSR-4 autoloader for PitchRooms\ => src/. No Composer needed, so the API
 * uploads to Hostinger as plain files.
 */
final class Autoloader
{
    public static function register(string $srcPath): void
    {
        $srcPath = rtrim(str_replace('\\', '/', $srcPath), '/');

        spl_autoload_register(static function (string $class) use ($srcPath): void {
            $prefix = 'PitchRooms\\';
            if (!str_starts_with($class, $prefix)) {
                return;
            }

            $relative = substr($class, strlen($prefix));
            $file = $srcPath . '/' . str_replace('\\', '/', $relative) . '.php';

            if (is_file($file)) {
                require_once $file;
            }
        });
    }
}
