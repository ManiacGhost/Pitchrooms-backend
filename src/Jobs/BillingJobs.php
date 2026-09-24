<?php

declare(strict_types=1);

namespace PitchRooms\Jobs;

use PitchRooms\Core\Database;
use PitchRooms\Services\NotificationService;
use PitchRooms\Support\Str;

final class BillingJobs
{
    /** Expire passes, and warn holders three days out. */
    public static function expirePasses(array $payload = []): array
    {
        $expiring = Database::select(
            "SELECT id, user_id, expires_at FROM passes
             WHERE status = 'active'
               AND expires_at IS NOT NULL
               AND expires_at BETWEEN UTC_TIMESTAMP() AND DATE_ADD(UTC_TIMESTAMP(), INTERVAL 3 DAY)"
        );

        foreach ($expiring as $pass) {
            NotificationService::push(
                (string) $pass['user_id'],
                'payment',
                'Your pitch pass expires soon',
                sprintf('Your all-access pass expires on %s. Renew to keep pitching without per-event fees.', Str::toIso($pass['expires_at'])),
                '/billing',
                'Renew pass',
                'pass',
                (string) $pass['id']
            );
        }

        $expired = Database::select(
            "SELECT id, user_id FROM passes
             WHERE status = 'active' AND expires_at IS NOT NULL AND expires_at <= UTC_TIMESTAMP()"
        );

        foreach ($expired as $pass) {
            Database::update('passes', ['status' => 'expired'], 'id = :id', ['id' => $pass['id']]);

            NotificationService::push(
                (string) $pass['user_id'],
                'payment',
                'Pitch pass expired',
                'Your seller pass has expired. Renew it to enter live pitch rooms again.',
                '/billing',
                'Renew pass',
                'pass',
                (string) $pass['id'],
                true
            );
        }

        // Abandoned checkouts do not linger as "created" forever.
        $abandoned = Database::statement(
            "UPDATE payments SET status = 'abandoned', updated_at = UTC_TIMESTAMP()
             WHERE status = 'created' AND created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 24 HOUR)"
        );

        return ['warned' => count($expiring), 'expired' => count($expired), 'abandoned' => $abandoned];
    }
}
