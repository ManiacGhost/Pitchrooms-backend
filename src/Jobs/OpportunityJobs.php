<?php

declare(strict_types=1);

namespace PitchRooms\Jobs;

use PitchRooms\Core\Database;
use PitchRooms\Services\NotificationService;

final class OpportunityJobs
{
    /**
     * Stop collecting proposals once the deadline passes, and nudge the buyer
     * to shortlist. Nothing here deletes anything.
     */
    public static function closeExpired(array $payload = []): array
    {
        $expired = Database::select(
            "SELECT id, owner_id, title FROM opportunities
             WHERE deleted_at IS NULL
               AND deadline IS NOT NULL
               AND deadline < UTC_TIMESTAMP()
               AND status IN ('Submitted','Under Review','Approved','Sourcing','open')"
        );

        foreach ($expired as $opportunity) {
            Database::update('opportunities', [
                'status'     => 'Shortlisting',
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ], 'id = :id', ['id' => $opportunity['id']]);

            Database::insert('opportunity_timeline', [
                'opportunity_id' => $opportunity['id'],
                'stage'          => 'Shortlisting',
                'note'           => 'Deadline reached — proposals closed automatically.',
                'actor_id'       => null,
                'at'             => gmdate('Y-m-d H:i:s'),
            ]);

            $count = (int) Database::scalar(
                'SELECT COUNT(*) FROM proposals WHERE opportunity_id = :id',
                ['id' => $opportunity['id']]
            );

            NotificationService::push(
                (string) $opportunity['owner_id'],
                'opportunity',
                'Proposals closed — time to shortlist',
                sprintf('"%s" reached its deadline with %d proposal(s). Shortlist who pitches live.', $opportunity['title'], $count),
                '/opportunities/' . $opportunity['id'],
                'Review proposals',
                'opportunity',
                (string) $opportunity['id'],
                true
            );
        }

        return ['closed' => count($expired)];
    }
}
