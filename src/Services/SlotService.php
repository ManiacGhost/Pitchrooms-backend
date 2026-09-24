<?php

declare(strict_types=1);

namespace PitchRooms\Services;

use PitchRooms\Core\Database;
use PitchRooms\Core\HttpException;
use PitchRooms\Support\Id;
use PitchRooms\Support\Str;

/**
 * Premium pitch positions.
 *
 * Two agencies can want position #1 at the same instant, so the claim runs
 * inside a transaction with a row lock and a UNIQUE(event_id, slot_position)
 * constraint behind it. The loser gets a clean 409, not a duplicated slot.
 */
final class SlotService
{
    public static function board(string $eventId): array
    {
        $event = MeetingService::find($eventId);
        $vertical = (string) $event['vertical'];

        $pricing = Database::select(
            'SELECT * FROM slot_pricing WHERE vertical = :vertical AND active = 1 ORDER BY position ASC',
            ['vertical' => $vertical]
        );

        $claimed = Database::select(
            'SELECT p.slot_position, p.user_id, p.fee_minor, u.name, u.company
             FROM event_participants p
             JOIN users u ON u.id = p.user_id
             WHERE p.event_id = :event AND p.slot_position IS NOT NULL',
            ['event' => $eventId]
        );

        $byPosition = [];
        foreach ($claimed as $row) {
            $byPosition[(int) $row['slot_position']] = $row;
        }

        return array_map(static function (array $slot) use ($byPosition): array {
            $position = (int) $slot['position'];
            $holder = $byPosition[$position] ?? null;

            return [
                'position'      => $position,
                'title'         => $slot['title'],
                'description'   => $slot['description'],
                'fee'           => (int) $slot['fee_minor'] / 100,
                'feeMinor'      => (int) $slot['fee_minor'],
                'feeFormatted'  => (int) $slot['fee_minor'] === 0
                    ? 'Included'
                    : BillingService::formatMoney((int) $slot['fee_minor'], (string) $slot['currency']),
                'currency'      => $slot['currency'],
                'isPremium'     => (int) $slot['fee_minor'] > 0,
                'status'        => $holder === null ? 'Available' : 'Claimed',
                'claimedBy'     => $holder === null ? null : [
                    'userId'  => $holder['user_id'],
                    'name'    => $holder['name'],
                    'company' => $holder['company'],
                ],
            ];
        }, $pricing);
    }

    /**
     * Claim a position. Free positions are claimed directly; paid ones must
     * come through a settled payment (BillingService::settle calls this).
     */
    public static function claim(string $eventId, string $userId, int $position, ?string $paymentId = null): array
    {
        $event = MeetingService::find($eventId);
        $slot = BillingService::slot((string) $event['vertical'], $position);

        if ((int) $slot['fee_minor'] > 0 && $paymentId === null) {
            throw HttpException::unprocessable(
                'This is a paid position. Start a checkout first, then claim it once payment settles.',
                'PAYMENT_REQUIRED'
            );
        }

        return self::assign($eventId, $userId, $position, $paymentId);
    }

    /** The transactional write. Assumes payment (if any) is already settled. */
    public static function assign(string $eventId, string $userId, int $position, ?string $paymentId = null): array
    {
        return Database::transaction(static function () use ($eventId, $userId, $position, $paymentId): array {
            $event = Database::first('SELECT * FROM events WHERE id = :id FOR UPDATE', ['id' => $eventId]);
            if ($event === null) {
                throw HttpException::notFound('Meeting not found.');
            }

            $slot = BillingService::slot((string) $event['vertical'], $position);

            $holder = Database::first(
                'SELECT user_id FROM event_participants
                 WHERE event_id = :event AND slot_position = :position FOR UPDATE',
                ['event' => $eventId, 'position' => $position]
            );

            if ($holder !== null && $holder['user_id'] !== $userId) {
                throw HttpException::conflict(
                    sprintf('Position #%d was just claimed by another presenter. Pick another slot.', $position),
                    'SLOT_TAKEN'
                );
            }

            $participant = Database::first(
                'SELECT * FROM event_participants WHERE event_id = :event AND user_id = :user',
                ['event' => $eventId, 'user' => $userId]
            );

            if ($participant === null) {
                throw HttpException::forbidden('You are not on the agenda for this pitch room.', 'NOT_A_PARTICIPANT');
            }

            // Release whatever position this user held before moving.
            Database::update('event_participants', [
                'slot_position' => null,
                'fee_minor'     => 0,
            ], 'event_id = :event AND user_id = :user', ['event' => $eventId, 'user' => $userId]);

            Database::update('event_participants', [
                'slot_position' => $position,
                'fee_minor'     => (int) $slot['fee_minor'],
                'currency'      => $slot['currency'],
            ], 'event_id = :event AND user_id = :user', ['event' => $eventId, 'user' => $userId]);

            self::rebuildAgenda($eventId);

            $user = AuthService::requireUser($userId);
            $label = (int) $slot['fee_minor'] === 0
                ? 'Included'
                : BillingService::formatMoney((int) $slot['fee_minor'], (string) $slot['currency']);

            // Everyone in the room sees the queue change, as the frontend's
            // system chat message used to announce locally.
            Database::insert('meeting_messages', [
                'id'          => Id::make('rmsg'),
                'event_id'    => $eventId,
                'from_id'     => null,
                'sender'      => 'PitchRooms System',
                'sender_role' => 'system',
                'body'        => sprintf(
                    '%s secured %s (%s). Meeting queue updated.',
                    $user['company'] ?: $user['name'],
                    $slot['title'],
                    $label
                ),
                'type'        => 'system',
                'created_at'  => Str::dbDate(),
            ]);

            NotificationService::push(
                $userId,
                'payment',
                sprintf('Position #%d secured', $position),
                sprintf('You are presenting in %s for "%s".', $slot['title'], $event['name']),
                '/events/' . $eventId,
                'View agenda',
                'event',
                $eventId
            );

            return [
                'eventId'     => $eventId,
                'position'    => $position,
                'feeMinor'    => (int) $slot['fee_minor'],
                'feeFormatted'=> $label,
                'paymentId'   => $paymentId,
                'agenda'      => MeetingService::agenda($eventId),
            ];
        });
    }

    /**
     * Rebuild the agenda from claimed positions. Claimed slots keep their
     * number; everyone else fills the remaining ones in match-score order.
     */
    public static function rebuildAgenda(string $eventId): void
    {
        $event = Database::first('SELECT * FROM events WHERE id = :id', ['id' => $eventId]);
        if ($event === null) {
            return;
        }

        $participants = Database::select(
            "SELECT p.user_id, p.slot_position, p.fee_minor, u.name, u.company,
                    COALESCE(pr.match_score, 0) AS match_score
             FROM event_participants p
             JOIN users u ON u.id = p.user_id
             LEFT JOIN proposals pr
                    ON pr.seller_id = p.user_id AND pr.opportunity_id <=> :opportunity
             WHERE p.event_id = :event AND p.side = 'seller'
             ORDER BY match_score DESC, u.name ASC",
            ['event' => $eventId, 'opportunity' => $event['opportunity_id']]
        );

        $claimed = [];
        $unclaimed = [];
        foreach ($participants as $participant) {
            if ($participant['slot_position'] !== null) {
                $claimed[(int) $participant['slot_position']] = $participant;
            } else {
                $unclaimed[] = $participant;
            }
        }

        Database::statement('DELETE FROM event_agenda WHERE event_id = :event', ['event' => $eventId]);

        $total = count($participants);
        $position = 1;

        for ($i = 0; $i < $total; $i++) {
            $slotNumber = $i + 1;
            $presenter = $claimed[$slotNumber] ?? array_shift($unclaimed);
            if ($presenter === null) {
                continue;
            }

            Database::insert('event_agenda', [
                'id'                   => Id::make('agenda'),
                'event_id'             => $eventId,
                'position'             => $slotNumber,
                'seller_id'            => $presenter['user_id'],
                'title'                => sprintf('Pitch %d: %s', $slotNumber, $presenter['company'] ?: $presenter['name']),
                'type'                 => 'seller',
                'presentation_seconds' => (int) $event['presentation_duration'],
                'qa_seconds'           => (int) $event['qa_duration'],
                'is_premium'           => (int) ($presenter['fee_minor'] ?? 0) > 0 ? 1 : 0,
                'fee_minor'            => (int) ($presenter['fee_minor'] ?? 0),
            ]);

            $position++;
        }
    }
}
