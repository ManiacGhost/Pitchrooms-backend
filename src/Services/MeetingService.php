<?php

declare(strict_types=1);

namespace PitchRooms\Services;

use PitchRooms\Core\Database;
use PitchRooms\Core\Env;
use PitchRooms\Core\HttpException;
use PitchRooms\Core\Paths;
use PitchRooms\Support\Id;
use PitchRooms\Support\Jwt;
use PitchRooms\Support\Logger;
use PitchRooms\Support\Str;

/**
 * The live pitch room: deterministic room names, JWT minting, and the
 * server-authoritative stage clock.
 *
 * The frontend used to run the 30s pitch / 30s Q&A countdown in each browser,
 * which drifts between participants. Here the stage and its end time live in
 * one row and every client renders from it.
 */
final class MeetingService
{
    public const STAGE_WAITING      = 'waiting';
    public const STAGE_PRESENTATION = 'presentation';
    public const STAGE_QA           = 'qa';
    public const STAGE_COMPLETED    = 'completed';

    /** The free public server: no auth, so no token is worth minting for it. */
    public const PUBLIC_JITSI_DOMAIN = 'meet.jit.si';

    public static function roomName(string $eventId): string
    {
        $safe = preg_replace('/[^a-zA-Z0-9\-_]/', '-', $eventId) ?: 'event';
        $safe = trim(preg_replace('/-+/', '-', $safe) ?: 'event', '-');

        return 'PitchRooms-' . $safe;
    }

    public static function find(string $eventId): array
    {
        $event = Database::first('SELECT * FROM events WHERE id = :id', ['id' => $eventId]);
        if ($event === null) {
            throw HttpException::notFound('Meeting not found.');
        }
        return $event;
    }

    /** Buyer, seller, any listed participant, or an admin. */
    public static function assertParticipant(array $event, string $userId, string $role = ''): void
    {
        if ($role === 'admin') {
            return;
        }
        if ($event['buyer_id'] === $userId || $event['seller_id'] === $userId || $event['created_by'] === $userId) {
            return;
        }

        $isParticipant = Database::scalar(
            'SELECT COUNT(*) FROM event_participants WHERE event_id = :event AND user_id = :user',
            ['event' => $event['id'], 'user' => $userId]
        );

        if ((int) $isParticipant === 0) {
            throw HttpException::forbidden('You are not a participant in this meeting.', 'NOT_A_PARTICIPANT');
        }
    }

    public static function isModerator(array $event, string $userId, string $role): bool
    {
        return $role === 'admin'
            || $event['buyer_id'] === $userId
            || $event['created_by'] === $userId
            || AuthService::isBuyer($role);
    }

    /**
     * Mint the participant token. HS256 for self-hosted Jitsi with a shared
     * secret; RS256 for JaaS (8x8), which requires the tenant private key.
     */
    public static function issueRoomToken(array $event, array $user, bool $moderator): array
    {
        $domain = Env::string('JITSI_DOMAIN', 'meet.jit.si');
        $appId = Env::string('JITSI_APP_ID', '');
        $ttl = Env::int('JITSI_TOKEN_TTL', 7200);
        $now = time();

        $endsAt = $event['end_at'] ? strtotime((string) $event['end_at'] . ' UTC') : null;
        $expires = $endsAt ? min($now + $ttl, $endsAt + 900) : $now + $ttl;
        if ($expires <= $now) {
            $expires = $now + 900;
        }

        $roomName = (string) $event['room_name'];
        $jwt = null;

        $context = [
            'user' => [
                'id'        => $user['id'],
                'name'      => $user['name'],
                'email'     => $user['email'],
                'moderator' => $moderator ? 'true' : 'false',
            ],
            'features' => [
                'recording'    => $moderator,
                'livestreaming'=> false,
                'transcription'=> false,
                'outbound-call'=> false,
            ],
        ];

        $privateKey = self::jitsiPrivateKey();
        $apiKey = Env::string('JITSI_API_KEY', '');

        // JaaS serves every room under the tenant, so the joinable name is
        // "<appId>/<room>" while the JWT's `room` claim stays the bare name.
        $tenant = null;
        $joinRoomName = $roomName;

        if ($appId !== '' && $privateKey !== null) {
            $tenant = $appId;
            $joinRoomName = $appId . '/' . $roomName;

            // JaaS: RS256, kid = <appId>/<apiKeyId>
            $jwt = Jwt::encode([
                'aud'     => 'jitsi',
                'iss'     => 'chat',
                'sub'     => $appId,
                'room'    => $roomName,
                'exp'     => $expires,
                'nbf'     => $now - 10,
                'context' => $context,
            ], $privateKey, 'RS256', ['kid' => $apiKey]);
        } elseif ($apiKey !== '') {
            // Self-hosted Jitsi with a shared secret.
            $jwt = Jwt::encode([
                'aud'     => 'jitsi',
                'iss'     => $appId ?: 'pitchrooms',
                'sub'     => $domain,
                'room'    => $roomName,
                'exp'     => $expires,
                'nbf'     => $now - 10,
                'context' => $context,
            ], $apiKey, 'HS256');
        }

        return [
            // What the client passes to the Jitsi SDK as roomName.
            'roomName'    => $joinRoomName,
            // The bare name, matching the JWT's room claim.
            'room'        => $roomName,
            'tenant'      => $tenant,
            'domain'      => $domain,
            'jwt'         => $jwt, // null on meet.jit.si, which has no auth
            'secured'     => $jwt !== null,
            'moderator'   => $moderator,
            'displayName' => $user['name'],
            'email'       => $user['email'],
            'expiresAt'   => Str::iso($expires),
            'warnings'    => self::jitsiConfigWarnings(),
        ];
    }

    /**
     * Reads the JaaS private key.
     *
     * The path may be absolute or relative to the project root — a Windows
     * absolute path in .env would break the moment the API is uploaded to
     * Hostinger, so a relative path like `storage/keys/privatekey.pk` is the
     * portable form.
     */
    public static function jitsiPrivateKey(): ?string
    {
        $configured = trim(Env::string('JITSI_PRIVATE_KEY_PATH', ''));
        if ($configured === '') {
            return null;
        }

        $path = str_replace('\\', '/', $configured);
        $isAbsolute = str_starts_with($path, '/') || preg_match('#^[A-Za-z]:#', $path) === 1;
        $candidates = $isAbsolute ? [$path] : [Paths::base($path), $path];

        foreach ($candidates as $candidate) {
            if (is_file($candidate) && is_readable($candidate)) {
                $contents = file_get_contents($candidate);
                if ($contents !== false && str_contains($contents, 'PRIVATE KEY')) {
                    return $contents;
                }
            }
        }

        Logger::warn('Jitsi private key not readable', ['path' => $configured]);

        return null;
    }

    /**
     * Configuration mistakes that silently break the live room. Surfaced on
     * /health and in the token response instead of failing mid-meeting.
     */
    public static function jitsiConfigWarnings(): array
    {
        $warnings = [];
        $domain = strtolower(Env::string('JITSI_DOMAIN', 'meet.jit.si'));
        $appId = Env::string('JITSI_APP_ID', '');
        $apiKey = Env::string('JITSI_API_KEY', '');
        $keyPath = Env::string('JITSI_PRIVATE_KEY_PATH', '');

        if ($appId !== '' && $domain === self::PUBLIC_JITSI_DOMAIN) {
            $warnings[] = 'JITSI_APP_ID is set (JaaS) but JITSI_DOMAIN is still meet.jit.si — set JITSI_DOMAIN=8x8.vc or the minted tokens are ignored.';
        }
        if ($appId !== '' && $keyPath === '') {
            $warnings[] = 'JITSI_APP_ID is set but JITSI_PRIVATE_KEY_PATH is empty — JaaS needs the private key to sign RS256 tokens.';
        }
        if ($appId !== '' && $keyPath !== '' && self::jitsiPrivateKey() === null) {
            $warnings[] = 'JITSI_PRIVATE_KEY_PATH does not point at a readable private key: ' . $keyPath;
        }
        if ($appId !== '' && $apiKey !== '' && !str_contains($apiKey, '/')) {
            $warnings[] = 'JITSI_API_KEY should be the full JaaS key id in the form <appId>/<keyId>.';
        }
        if ($appId === '' && $domain !== self::PUBLIC_JITSI_DOMAIN && $apiKey === '') {
            $warnings[] = 'A self-hosted JITSI_DOMAIN is set without JITSI_API_KEY — rooms will be unauthenticated.';
        }
        if ($domain === self::PUBLIC_JITSI_DOMAIN && Env::string('APP_ENV', 'local') === 'production') {
            $warnings[] = 'Production is pointed at public meet.jit.si — rooms are open to anyone with the link.';
        }

        return $warnings;
    }

    public static function state(string $eventId, bool $create = true): ?array
    {
        $state = Database::first('SELECT * FROM meeting_state WHERE event_id = :id', ['id' => $eventId]);

        if ($state === null && $create) {
            Database::insert('meeting_state', [
                'event_id'         => $eventId,
                'stage'            => self::STAGE_WAITING,
                'presenter_index'  => 0,
                'presenter_id'     => null,
                'stage_started_at' => null,
                'stage_ends_at'    => null,
                'paused_at'        => null,
                'updated_at'       => Str::dbDate(),
            ]);
            $state = Database::first('SELECT * FROM meeting_state WHERE event_id = :id', ['id' => $eventId]);
        }

        return $state;
    }

    public static function agenda(string $eventId): array
    {
        return Database::select(
            'SELECT a.*, u.name AS seller_name, u.company AS seller_company
             FROM event_agenda a
             LEFT JOIN users u ON u.id = a.seller_id
             WHERE a.event_id = :id
             ORDER BY a.position ASC',
            ['id' => $eventId]
        );
    }

    /** Start the room: first presenter, presentation stage, clock running. */
    public static function start(string $eventId): array
    {
        $agenda = self::agenda($eventId);
        $first = $agenda[0] ?? null;
        // Same floor writeStage() applies, so the first stage cannot be
        // shorter than every stage that follows it.
        $seconds = max(5, (int) ($first['presentation_seconds'] ?? 30));

        Database::upsert('meeting_state', [
            'event_id'         => $eventId,
            'stage'            => self::STAGE_PRESENTATION,
            'presenter_index'  => 0,
            'presenter_id'     => $first['seller_id'] ?? null,
            'stage_started_at' => Str::dbDate(),
            'stage_ends_at'    => Str::dbDate(time() + $seconds),
            'paused_at'        => null,
            'updated_at'       => Str::dbDate(),
        ], ['stage', 'presenter_index', 'presenter_id', 'stage_started_at', 'stage_ends_at', 'paused_at', 'updated_at']);

        Database::update('events', [
            'status'     => 'live',
            'started_at' => Str::dbDate(),
            'updated_at' => Str::dbDate(),
        ], 'id = :id', ['id' => $eventId]);

        return self::presentState($eventId);
    }

    /**
     * Move the clock on: presentation -> qa -> next presenter -> completed.
     * Called by a moderator and by the meeting.advance scheduled job.
     */
    public static function advance(string $eventId, bool $force = false): array
    {
        $state = self::state($eventId);
        if ($state === null) {
            return self::presentState($eventId);
        }

        $stage = (string) $state['stage'];
        if ($stage === self::STAGE_COMPLETED) {
            return self::presentState($eventId);
        }

        if (!$force && $state['stage_ends_at'] !== null) {
            $endsAt = strtotime((string) $state['stage_ends_at'] . ' UTC');
            if ($endsAt !== false && $endsAt > time()) {
                return self::presentState($eventId); // not due yet
            }
        }

        $agenda = self::agenda($eventId);
        $index = (int) $state['presenter_index'];

        if ($stage === self::STAGE_WAITING) {
            return self::start($eventId);
        }

        if ($stage === self::STAGE_PRESENTATION) {
            $seconds = (int) ($agenda[$index]['qa_seconds'] ?? 30);
            self::writeStage($eventId, self::STAGE_QA, $index, $agenda[$index]['seller_id'] ?? null, $seconds);

            return self::presentState($eventId);
        }

        // Q&A finished — next presenter, or the room is done.
        $next = $index + 1;
        if ($next >= count($agenda)) {
            self::complete($eventId);
            return self::presentState($eventId);
        }

        $seconds = (int) ($agenda[$next]['presentation_seconds'] ?? 30);
        self::writeStage($eventId, self::STAGE_PRESENTATION, $next, $agenda[$next]['seller_id'] ?? null, $seconds);

        return self::presentState($eventId);
    }

    public static function extend(string $eventId, int $seconds): array
    {
        $state = self::state($eventId);
        if ($state === null || $state['stage_ends_at'] === null) {
            return self::presentState($eventId);
        }

        $endsAt = strtotime((string) $state['stage_ends_at'] . ' UTC') ?: time();

        Database::update('meeting_state', [
            'stage_ends_at' => Str::dbDate(max(time(), $endsAt) + max(5, $seconds)),
            'updated_at'    => Str::dbDate(),
        ], 'event_id = :id', ['id' => $eventId]);

        return self::presentState($eventId);
    }

    public static function complete(string $eventId): void
    {
        Database::update('meeting_state', [
            'stage'         => self::STAGE_COMPLETED,
            'stage_ends_at' => null,
            'updated_at'    => Str::dbDate(),
        ], 'event_id = :id', ['id' => $eventId]);

        Database::update('events', [
            'status'     => 'evaluation',
            'ended_at'   => Str::dbDate(),
            'updated_at' => Str::dbDate(),
        ], "id = :id AND status IN ('live','waiting_room','scheduled')", ['id' => $eventId]);

        // Messaging unlocks once the pitch is done — mirrors the rule the
        // three frontend stores enforced locally.
        ThreadService::unlockForEvent($eventId);
    }

    private static function writeStage(string $eventId, string $stage, int $index, ?string $presenterId, int $seconds): void
    {
        Database::update('meeting_state', [
            'stage'            => $stage,
            'presenter_index'  => $index,
            'presenter_id'     => $presenterId,
            'stage_started_at' => Str::dbDate(),
            'stage_ends_at'    => Str::dbDate(time() + max(5, $seconds)),
            'updated_at'       => Str::dbDate(),
        ], 'event_id = :id', ['id' => $eventId]);
    }

    public static function presentState(string $eventId): array
    {
        $state = self::state($eventId);
        $agenda = self::agenda($eventId);

        $endsAt = $state && $state['stage_ends_at'] ? strtotime((string) $state['stage_ends_at'] . ' UTC') : null;
        $remaining = $endsAt === null ? null : max(0, $endsAt - time());

        return [
            'eventId'        => $eventId,
            'stage'          => $state['stage'] ?? self::STAGE_WAITING,
            'presenterIndex' => (int) ($state['presenter_index'] ?? 0),
            'presenterId'    => $state['presenter_id'] ?? null,
            'stageStartedAt' => Str::toIso($state['stage_started_at'] ?? null),
            'stageEndsAt'    => Str::toIso($state['stage_ends_at'] ?? null),
            'remainingSeconds' => $remaining,
            // Clients compare their clock to this to correct for drift.
            'serverTime'     => Str::iso(),
            'agenda'         => array_map(static fn (array $item): array => [
                'id'                  => $item['id'],
                'position'            => (int) $item['position'],
                'sellerId'            => $item['seller_id'],
                'sellerName'          => $item['seller_name'] ?? null,
                'company'             => $item['seller_company'] ?? null,
                'title'               => $item['title'],
                'type'                => $item['type'],
                'presentationSeconds' => (int) $item['presentation_seconds'],
                'qaSeconds'           => (int) $item['qa_seconds'],
                'isPremium'           => (bool) $item['is_premium'],
            ], $agenda),
        ];
    }

    public static function present(array $event, array $extra = []): array
    {
        return array_merge([
            'id'                   => $event['id'],
            'opportunityId'        => $event['opportunity_id'],
            'vertical'             => $event['vertical'],
            'buyerId'              => $event['buyer_id'],
            'sellerId'             => $event['seller_id'],
            'proposalId'           => $event['proposal_id'],
            'name'                 => $event['name'],
            'title'                => $event['name'],
            'agenda'               => $event['agenda'],
            'status'               => $event['status'],
            'startAt'              => Str::toIso($event['start_at']),
            'endAt'                => Str::toIso($event['end_at']),
            'presentationDuration' => (int) $event['presentation_duration'],
            'qaDuration'           => (int) $event['qa_duration'],
            'roomName'             => $event['room_name'],
            'createdBy'            => $event['created_by'],
            'acceptedBy'           => $event['accepted_by'],
            'acceptedAt'           => Str::toIso($event['accepted_at']),
            'startedAt'            => Str::toIso($event['started_at']),
            'endedAt'              => Str::toIso($event['ended_at']),
            'decision'             => $event['decision'],
            'createdAt'            => Str::toIso($event['created_at']),
        ], $extra);
    }

    public static function generateMeetingCode(): string
    {
        return Id::code(6);
    }
}
