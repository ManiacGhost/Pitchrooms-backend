<?php

declare(strict_types=1);

namespace PitchRooms\Support;

final class Str
{
    public static function slug(string $value, string $separator = '-'): string
    {
        $value = preg_replace('/[^\p{L}\p{N}]+/u', $separator, $value) ?? '';
        return strtolower(trim($value, $separator));
    }

    public static function limit(string $value, int $length = 120, string $end = '…'): string
    {
        return mb_strlen($value) <= $length ? $value : mb_substr($value, 0, $length) . $end;
    }

    public static function email(string $value): string
    {
        return strtolower(trim($value));
    }

    /** "  a , b ,, c " => ['a','b','c'] ; arrays pass through cleaned. */
    public static function listOf(mixed $value): array
    {
        if (is_array($value)) {
            $items = $value;
        } elseif (is_string($value) && $value !== '') {
            $items = explode(',', $value);
        } else {
            return [];
        }

        $out = [];
        foreach ($items as $item) {
            if (!is_scalar($item)) {
                continue;
            }
            $item = trim((string) $item);
            if ($item !== '' && !in_array($item, $out, true)) {
                $out[] = $item;
            }
        }

        return $out;
    }

    public static function json(mixed $value): string
    {
        return json_encode($value === null ? [] : $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '[]';
    }

    public static function fromJson(?string $value, mixed $default = []): mixed
    {
        if ($value === null || $value === '') {
            return $default;
        }
        $decoded = json_decode($value, true);

        return $decoded === null ? $default : $decoded;
    }

    /** ISO-8601 UTC, the format every date in the API uses. */
    public static function iso(?int $timestamp = null): string
    {
        return gmdate('Y-m-d\TH:i:s.000\Z', $timestamp ?? time());
    }

    public static function toIso(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_int($value)) {
            return self::iso($value);
        }
        $timestamp = strtotime((string) $value);

        return $timestamp === false ? null : self::iso($timestamp);
    }

    /** MySQL DATETIME in UTC. */
    public static function dbDate(mixed $value = null): ?string
    {
        if ($value === null) {
            return gmdate('Y-m-d H:i:s');
        }
        if ($value === '') {
            return null;
        }
        $timestamp = is_int($value) ? $value : strtotime((string) $value);

        return $timestamp === false ? null : gmdate('Y-m-d H:i:s', $timestamp);
    }

    public static function initials(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $letters = array_map(static fn ($p) => mb_strtoupper(mb_substr($p, 0, 1)), array_slice($parts, 0, 2));

        return implode('', $letters) ?: 'PR';
    }
}
