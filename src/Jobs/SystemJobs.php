<?php

declare(strict_types=1);

namespace PitchRooms\Jobs;

use PitchRooms\Core\Database;
use PitchRooms\Core\Paths;
use PitchRooms\Services\NotificationService;
use PitchRooms\Services\RateLimiter;

final class SystemJobs
{
    /** Housekeeping: expired credentials, finished jobs, old logs. */
    public static function cleanup(array $payload = []): array
    {
        $otps = Database::statement(
            'DELETE FROM otp_codes WHERE expires_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 DAY)'
        );

        $tokens = Database::statement(
            'DELETE FROM refresh_tokens
             WHERE expires_at < UTC_TIMESTAMP()
                OR (revoked_at IS NOT NULL AND revoked_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 7 DAY))'
        );

        $resets = Database::statement(
            'DELETE FROM password_resets WHERE expires_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 DAY)'
        );

        $jobs = Database::statement(
            "DELETE FROM scheduled_jobs
             WHERE status = 'completed' AND completed_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 3 DAY)"
        );

        $limits = RateLimiter::prune();

        // Log files older than 14 days.
        $removedLogs = 0;
        $logDir = Paths::storage('logs');
        if (is_dir($logDir)) {
            foreach (glob($logDir . '/*.log') ?: [] as $file) {
                if (filemtime($file) < time() - 14 * 86400) {
                    @unlink($file);
                    $removedLogs++;
                }
            }
        }

        return [
            'otps' => $otps, 'tokens' => $tokens, 'resets' => $resets,
            'jobs' => $jobs, 'rateLimits' => $limits, 'logFiles' => $removedLogs,
        ];
    }

    /** Deferred notification, used when a request should not wait on email. */
    public static function pushNotification(array $payload): array
    {
        NotificationService::push(
            (string) ($payload['userId'] ?? ''),
            (string) ($payload['type'] ?? 'system'),
            (string) ($payload['title'] ?? 'PitchRooms'),
            (string) ($payload['body'] ?? ''),
            $payload['link'] ?? null,
            $payload['actionLabel'] ?? null,
            $payload['entityType'] ?? null,
            $payload['entityId'] ?? null,
            (bool) ($payload['email'] ?? false)
        );

        return ['pushed' => 1];
    }
}
