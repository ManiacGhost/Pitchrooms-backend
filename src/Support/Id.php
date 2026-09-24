<?php

declare(strict_types=1);

namespace PitchRooms\Support;

/**
 * Prefixed, sortable IDs. The frontend already builds links from ID strings
 * like "ab-job-…" / "ee-app-…" / "si-meet-…", so we keep that readable shape.
 */
final class Id
{
    public static function make(string $prefix = 'id'): string
    {
        return sprintf(
            '%s-%s-%s',
            trim($prefix, '-'),
            base_convert((string) (int) (microtime(true) * 1000), 10, 36),
            bin2hex(random_bytes(4))
        );
    }

    public static function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /** Human-friendly, unambiguous code — meeting codes, receipt numbers. */
    public static function code(int $length = 6): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        return $out;
    }

    public static function numericOtp(int $length = 6): string
    {
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= (string) random_int(0, 9);
        }
        return $out;
    }

    public static function token(int $bytes = 32): string
    {
        return bin2hex(random_bytes($bytes));
    }
}
