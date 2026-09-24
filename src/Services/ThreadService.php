<?php

declare(strict_types=1);

namespace PitchRooms\Services;

use PitchRooms\Core\Database;
use PitchRooms\Core\HttpException;
use PitchRooms\Support\Id;
use PitchRooms\Support\Str;

/**
 * Messaging, with the rule the three frontend stores enforced in the browser:
 *
 *   "Messaging unlocks only after a completed pitch meeting."
 *
 * That gate is now server-side, checked on thread creation AND on every send,
 * because a client-side check is a suggestion, not a rule.
 */
final class ThreadService
{
    /** Thread keys are order-independent: (a,b) and (b,a) are one thread. */
    public static function pair(string $userA, string $userB): array
    {
        return strcmp($userA, $userB) <= 0 ? [$userA, $userB] : [$userB, $userA];
    }

    /** The event that unlocked this pair, or null when still locked. */
    public static function unlockingEvent(string $userA, string $userB): ?array
    {
        return Database::first(
            "SELECT e.* FROM events e
             WHERE e.status IN ('completed','evaluation','decision_made')
               AND (
                    (e.buyer_id = :a1 AND e.seller_id = :b1)
                 OR (e.buyer_id = :b2 AND e.seller_id = :a2)
                 OR (e.id IN (
                        SELECT p1.event_id FROM event_participants p1
                        JOIN event_participants p2 ON p1.event_id = p2.event_id
                        WHERE p1.user_id = :a3 AND p2.user_id = :b3
                     ))
               )
             ORDER BY e.ended_at DESC, e.updated_at DESC
             LIMIT 1",
            ['a1' => $userA, 'b1' => $userB, 'a2' => $userA, 'b2' => $userB, 'a3' => $userA, 'b3' => $userB]
        );
    }

    public static function canMessage(string $userA, string $userB): bool
    {
        return self::unlockingEvent($userA, $userB) !== null;
    }

    public static function getOrCreate(string $userId, string $counterpartyId, ?string $opportunityId = null): array
    {
        if ($userId === $counterpartyId) {
            throw HttpException::badRequest('You cannot message yourself.');
        }

        $counterparty = AuthService::findById($counterpartyId);
        if ($counterparty === null) {
            throw HttpException::notFound('That person is not on PitchRooms.');
        }

        [$partyA, $partyB] = self::pair($userId, $counterpartyId);

        $existing = Database::first(
            'SELECT * FROM threads
             WHERE party_a_id = :a AND party_b_id = :b
               AND (opportunity_id <=> :opportunity)
             LIMIT 1',
            ['a' => $partyA, 'b' => $partyB, 'opportunity' => $opportunityId]
        );

        if ($existing !== null) {
            return $existing;
        }

        $event = self::unlockingEvent($userId, $counterpartyId);
        if ($event === null) {
            throw HttpException::forbidden(
                'Messaging unlocks only after a completed pitch meeting.',
                'MESSAGING_LOCKED'
            );
        }

        // The caller may not have named an opportunity, in which case the
        // thread is keyed to the unlocking event's one. Look again with that
        // resolved id before inserting, or we collide with a thread that
        // unlockForEvent already opened.
        $resolvedOpportunityId = $opportunityId ?: $event['opportunity_id'];

        if ($resolvedOpportunityId !== $opportunityId) {
            $existing = Database::first(
                'SELECT * FROM threads
                 WHERE party_a_id = :a AND party_b_id = :b AND (opportunity_id <=> :opportunity)
                 LIMIT 1',
                ['a' => $partyA, 'b' => $partyB, 'opportunity' => $resolvedOpportunityId]
            );
            if ($existing !== null) {
                return $existing;
            }
        }

        $thread = [
            'id'                   => Id::make('thread'),
            'vertical'             => $event['vertical'],
            'party_a_id'           => $partyA,
            'party_b_id'           => $partyB,
            'opportunity_id'       => $resolvedOpportunityId,
            'unlocked_by_event_id' => $event['id'],
            'last_message_at'      => null,
            'last_message_preview' => null,
            'archived_by'          => Str::json([]),
            'muted_by'             => Str::json([]),
            'created_at'           => Str::dbDate(),
        ];

        try {
            Database::insert('threads', $thread);
        } catch (\PDOException $e) {
            // Two tabs can race here; the unique index decides, and the
            // loser just reads back the winner's row.
            if (!str_contains($e->getMessage(), '1062')) {
                throw $e;
            }

            $existing = Database::first(
                'SELECT * FROM threads
                 WHERE party_a_id = :a AND party_b_id = :b AND (opportunity_id <=> :opportunity)
                 LIMIT 1',
                ['a' => $partyA, 'b' => $partyB, 'opportunity' => $resolvedOpportunityId]
            );
            if ($existing === null) {
                throw $e;
            }

            return $existing;
        }

        return $thread;
    }

    /**
     * Once a meeting completes, open the thread for its participants so the
     * inbox is ready without anyone having to ask for it.
     */
    public static function unlockForEvent(string $eventId): void
    {
        $event = Database::first('SELECT * FROM events WHERE id = :id', ['id' => $eventId]);
        if ($event === null) {
            return;
        }

        $buyerId = (string) $event['buyer_id'];
        $sellers = Database::select(
            "SELECT user_id FROM event_participants WHERE event_id = :id AND side = 'seller'",
            ['id' => $eventId]
        );

        if ($event['seller_id']) {
            $sellers[] = ['user_id' => $event['seller_id']];
        }

        foreach ($sellers as $seller) {
            $sellerId = (string) $seller['user_id'];
            if ($sellerId === '' || $sellerId === $buyerId) {
                continue;
            }

            [$partyA, $partyB] = self::pair($buyerId, $sellerId);

            $exists = Database::scalar(
                'SELECT COUNT(*) FROM threads WHERE party_a_id = :a AND party_b_id = :b AND (opportunity_id <=> :opp)',
                ['a' => $partyA, 'b' => $partyB, 'opp' => $event['opportunity_id']]
            );
            if ((int) $exists > 0) {
                continue;
            }

            Database::insert('threads', [
                'id'                   => Id::make('thread'),
                'vertical'             => $event['vertical'],
                'party_a_id'           => $partyA,
                'party_b_id'           => $partyB,
                'opportunity_id'       => $event['opportunity_id'],
                'unlocked_by_event_id' => $eventId,
                'last_message_at'      => null,
                'last_message_preview' => null,
                'archived_by'          => Str::json([]),
                'muted_by'             => Str::json([]),
                'created_at'           => Str::dbDate(),
            ]);

            NotificationService::push(
                $sellerId,
                'message',
                'Messaging unlocked',
                'Your pitch meeting is complete — you can now message directly on PitchRooms.',
                '/inbox',
                'Open inbox',
                'thread',
                $eventId
            );
        }
    }

    public static function requireMember(string $threadId, string $userId, string $role = ''): array
    {
        $thread = Database::first('SELECT * FROM threads WHERE id = :id', ['id' => $threadId]);
        if ($thread === null) {
            throw HttpException::notFound('Conversation not found.');
        }

        if ($role !== 'admin' && $thread['party_a_id'] !== $userId && $thread['party_b_id'] !== $userId) {
            throw HttpException::forbidden('This conversation is not yours.');
        }

        return $thread;
    }

    public static function counterparty(array $thread, string $userId): string
    {
        return $thread['party_a_id'] === $userId ? (string) $thread['party_b_id'] : (string) $thread['party_a_id'];
    }

    public static function present(array $thread, string $userId): array
    {
        $otherId = self::counterparty($thread, $userId);
        $other = Database::first(
            'SELECT id, name, company, role, avatar_file_id FROM users WHERE id = :id',
            ['id' => $otherId]
        );

        $unread = (int) Database::scalar(
            'SELECT COUNT(*) FROM messages WHERE thread_id = :thread AND to_id = :user AND read_at IS NULL',
            ['thread' => $thread['id'], 'user' => $userId]
        );

        $archived = Str::fromJson($thread['archived_by'] ?? null, []);
        $muted = Str::fromJson($thread['muted_by'] ?? null, []);

        return [
            'id'                 => $thread['id'],
            'vertical'           => $thread['vertical'],
            'opportunityId'      => $thread['opportunity_id'],
            'unlockedByEventId'  => $thread['unlocked_by_event_id'],
            'counterparty'       => $other === null ? null : [
                'id'      => $other['id'],
                'name'    => $other['name'],
                'company' => $other['company'],
                'role'    => $other['role'],
            ],
            'lastMessageAt'      => Str::toIso($thread['last_message_at']),
            'lastMessagePreview' => $thread['last_message_preview'],
            'unreadCount'        => $unread,
            'archived'           => in_array($userId, is_array($archived) ? $archived : [], true),
            'muted'              => in_array($userId, is_array($muted) ? $muted : [], true),
            'createdAt'          => Str::toIso($thread['created_at']),
        ];
    }

    public static function presentMessage(array $message): array
    {
        return [
            'id'          => $message['id'],
            'threadId'    => $message['thread_id'],
            'fromId'      => $message['from_id'],
            'toId'        => $message['to_id'],
            'body'        => $message['body'],
            'type'        => $message['type'],
            'clientMsgId' => $message['client_msg_id'],
            'read'        => $message['read_at'] !== null,
            'readAt'      => Str::toIso($message['read_at']),
            'createdAt'   => Str::toIso($message['created_at']),
        ];
    }
}
