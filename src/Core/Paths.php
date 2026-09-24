<?php

declare(strict_types=1);

namespace PitchRooms\Core;

final class Paths
{
    private static string $base = '';

    public static function setBase(string $path): void
    {
        self::$base = rtrim(str_replace('\\', '/', $path), '/');
    }

    public static function base(string $append = ''): string
    {
        return self::$base . ($append === '' ? '' : '/' . ltrim($append, '/'));
    }

    public static function storage(string $append = ''): string
    {
        return self::base('storage' . ($append === '' ? '' : '/' . ltrim($append, '/')));
    }

    public static function uploads(string $append = ''): string
    {
        $dir = Env::string('UPLOAD_DIR', 'storage/uploads');
        $absolute = str_starts_with($dir, '/') || preg_match('#^[A-Za-z]:#', $dir)
            ? rtrim($dir, '/')
            : self::base($dir);

        return $absolute . ($append === '' ? '' : '/' . ltrim($append, '/'));
    }

    public static function ensureDir(string $path): void
    {
        if (!is_dir($path)) {
            @mkdir($path, 0775, true);
        }
    }
}
