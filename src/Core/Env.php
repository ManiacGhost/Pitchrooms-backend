<?php

declare(strict_types=1);

namespace PitchRooms\Core;

/**
 * Minimal .env loader. No Composer, no vendor directory — the whole API is
 * uploadable to Hostinger as plain files.
 */
final class Env
{
    private static array $values = [];
    private static bool $loaded = false;

    public static function load(string $path): void
    {
        if (self::$loaded) {
            return;
        }
        self::$loaded = true;

        if (!is_file($path)) {
            return;
        }

        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (!str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);

            // Strip matching quotes, keep everything else verbatim.
            if (strlen($value) >= 2) {
                $first = $value[0];
                $last = $value[strlen($value) - 1];
                if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                    $value = substr($value, 1, -1);
                }
            }

            self::$values[$key] = $value;
        }
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $value = self::$values[$key] ?? getenv($key);
        if ($value === false || $value === null || $value === '') {
            return $default;
        }
        return $value;
    }

    public static function string(string $key, string $default = ''): string
    {
        return (string) self::get($key, $default);
    }

    public static function int(string $key, int $default = 0): int
    {
        $value = self::get($key);
        return $value === null ? $default : (int) $value;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::get($key);
        if ($value === null) {
            return $default;
        }
        return in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true);
    }

    /** Comma separated list, trimmed, empties removed. */
    public static function list(string $key): array
    {
        $raw = (string) self::get($key, '');
        if ($raw === '') {
            return [];
        }
        return array_values(array_filter(array_map('trim', explode(',', $raw)), fn ($v) => $v !== ''));
    }

    /**
     * The central DNS knob. Everything the API emits as an absolute URL is
     * built from here, so moving environments is a one-line .env change.
     */
    public static function appDns(): string
    {
        return rtrim(self::string('APP_DNS', 'http://localhost:8080'), '/');
    }

    public static function frontendDns(): string
    {
        return rtrim(self::string('FRONTEND_DNS', 'http://localhost:5173'), '/');
    }

    /** Absolute API URL for a path, e.g. url('/files/abc') */
    public static function url(string $path = ''): string
    {
        $prefix = rtrim(self::string('API_PREFIX', '/api/v1'), '/');
        $path = '/' . ltrim($path, '/');
        return self::appDns() . $prefix . ($path === '/' ? '' : $path);
    }

    /** Absolute frontend URL for a deep link, e.g. appUrl('/ab/meetings/1') */
    public static function appUrl(string $path = ''): string
    {
        return self::frontendDns() . '/' . ltrim($path, '/');
    }
}
