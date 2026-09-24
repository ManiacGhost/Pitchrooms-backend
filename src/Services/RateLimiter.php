<?php

declare(strict_types=1);

namespace PitchRooms\Services;

use PitchRooms\Core\Database;
use PitchRooms\Core\HttpException;
use PitchRooms\Support\Str;

/**
 * Fixed-window limiter backed by MySQL (no Redis on shared hosting).
 */
final class RateLimiter
{
    public static function hit(string $bucket, int $limit, int $windowSeconds = 60): void
    {
        if ($limit <= 0) {
            return;
        }

        $bucket = substr($bucket, 0, 190);
        $now = time();

        $row = Database::first('SELECT hits, window_started_at FROM rate_limits WHERE bucket = :bucket', ['bucket' => $bucket]);

        if ($row === null) {
            Database::upsert('rate_limits', [
                'bucket'            => $bucket,
                'hits'              => 1,
                'window_started_at' => Str::dbDate($now),
                'updated_at'        => Str::dbDate($now),
            ], ['hits', 'window_started_at', 'updated_at']);
            return;
        }

        $windowStart = strtotime((string) $row['window_started_at'] . ' UTC') ?: $now;

        if (($now - $windowStart) >= $windowSeconds) {
            Database::update('rate_limits', [
                'hits'              => 1,
                'window_started_at' => Str::dbDate($now),
                'updated_at'        => Str::dbDate($now),
            ], 'bucket = :bucket', ['bucket' => $bucket]);
            return;
        }

        $hits = (int) $row['hits'] + 1;
        if ($hits > $limit) {
            $retryAfter = max(1, $windowSeconds - ($now - $windowStart));
            throw HttpException::tooManyRequests(
                sprintf('Too many attempts. Try again in %d second(s).', $retryAfter)
            );
        }

        Database::update('rate_limits', [
            'hits'       => $hits,
            'updated_at' => Str::dbDate($now),
        ], 'bucket = :bucket', ['bucket' => $bucket]);
    }

    public static function clear(string $bucket): void
    {
        Database::statement('DELETE FROM rate_limits WHERE bucket = :bucket', ['bucket' => substr($bucket, 0, 190)]);
    }

    public static function prune(int $olderThanSeconds = 86400): int
    {
        return Database::statement(
            'DELETE FROM rate_limits WHERE updated_at < :cutoff',
            ['cutoff' => Str::dbDate(time() - $olderThanSeconds)]
        );
    }
}
