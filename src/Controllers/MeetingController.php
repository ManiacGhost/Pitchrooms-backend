<?php

declare(strict_types=1);

namespace PitchRooms\Controllers;

use PitchRooms\Core\Database;
use PitchRooms\Core\Env;
use PitchRooms\Core\HttpException;
use PitchRooms\Core\Request;
use PitchRooms\Core\Response;
use PitchRooms\Services\ActivityLog;
use PitchRooms\Services\BillingService;
use PitchRooms\Services\MeetingService;
use PitchRooms\Services\RateLimiter;
use PitchRooms\Services\SlotService;
use PitchRooms\Support\Id;
use PitchRooms\Support\Mailer;
use PitchRooms\Support\Str;

/**
 * Inside the live room: the entry gate, the Jitsi token, the shared clock,
 * chat, Q&A, attendance, and premium slots.
 */
final class MeetingController
{
    // ------------------------------------------------------------ entry gate

    /** POST /events/{id}/access/code */
    public function verifyCode(Request $request, array $params): Response
    {
        $event = MeetingService::find((string) $params['id']);
        $userId = (string) $request->userId();
        MeetingService::assertParticipant($event, $userId, (string) $request->role());

        RateLimiter::hit('gate-code:' . $userId . ':' . $event['id'], 10, 600);

        $code = strtoupper(preg_replace('/\s+/', '', $request->string('code')) ?? '');
        if (!hash_equals(strtoupper((string) $event['meeting_code']), $code)) {
            throw HttpException::badRequest('That meeting code is not valid for this room.', 'BAD_MEETING_CODE');
        }

        $this->gateRow((string) $event['id'], $userId);
        Database::update('meeting_access', [
            'code_ok'    => 1,
            'ip'         => $request->ip(),
            'updated_at' => Str::dbDate(),
        ], 'event_id = :event AND user_id = :user', ['event' => $event['id'], 'user' => $userId]);

        return Response::json($this->gateState((string) $event['id'], $userId));
    }

    /** POST /events/{id}/access/otp/{channel} */
    public function sendOtp(Request $request, array $params): Response
    {
        $event = MeetingService::find((string) $params['id']);
        $userId = (string) $request->userId();
        MeetingService::assertParticipant($event, $userId, (string) $request->role());

        $channel = (string) $params['channel'];
        if (!in_array($channel, ['email', 'mobile'], true)) {
            throw HttpException::badRequest('Channel must be email or mobile.');
        }

        RateLimiter::hit('gate-otp:' . $userId . ':' . $channel, Env::int('RATE_LIMIT_OTP', 5), 600);

        $user = $request->user();
        $destination = $channel === 'email' ? (string) $user['email'] : (string) ($user['phone'] ?? '');
        if ($destination === '') {
            throw HttpException::unprocessable(
                'Add a mobile number to your profile before joining a secured room.',
                'NO_PHONE'
            );
        }

        $code = Id::numericOtp(6);

        Database::insert('otp_codes', [
            'id'          => Id::make('otp'),
            'user_id'     => $userId,
            'destination' => $destination,
            'channel'     => $channel,
            'purpose'     => 'meeting_access',
            'entity_id'   => (string) $event['id'],
            'code_hash'   => hash('sha256', $code),
            'attempts'    => 0,
            'expires_at'  => Str::dbDate(time() + Env::int('OTP_TTL', 600)),
            'consumed_at' => null,
            'created_at'  => Str::dbDate(),
        ]);

        $message = sprintf('Your PitchRooms entry code for "%s" is %s. It expires in 10 minutes.', $event['name'], $code);

        if ($channel === 'email') {
            Mailer::send($destination, 'Your pitch room entry code', '<p>' . $message . '</p>');
        } else {
            Mailer::sms($destination, $message);
        }

        return Response::json([
            'sent'      => true,
            'channel'   => $channel,
            'maskedTo'  => $this->mask($destination, $channel),
            'expiresIn' => Env::int('OTP_TTL', 600),
        ]);
    }

    /** POST /events/{id}/access/otp/{channel}/verify */
    public function verifyOtp(Request $request, array $params): Response
    {
        $event = MeetingService::find((string) $params['id']);
        $userId = (string) $request->userId();
        MeetingService::assertParticipant($event, $userId, (string) $request->role());

        $channel = (string) $params['channel'];
        $code = preg_replace('/\D/', '', $request->string('code')) ?? '';

        $otp = Database::first(
            "SELECT * FROM otp_codes
             WHERE user_id = :user AND channel = :channel AND purpose = 'meeting_access'
               AND entity_id = :event AND consumed_at IS NULL
             ORDER BY created_at DESC LIMIT 1",
            ['user' => $userId, 'channel' => $channel, 'event' => $event['id']]
        );

        if ($otp === null) {
            throw HttpException::badRequest('Request a new code first.', 'NO_OTP');
        }
        if (strtotime((string) $otp['expires_at'] . ' UTC') < time()) {
            throw HttpException::badRequest('That code expired. Request a new one.', 'OTP_EXPIRED');
        }
        if ((int) $otp['attempts'] >= Env::int('OTP_MAX_ATTEMPTS', 5)) {
            throw HttpException::tooManyRequests('Too many wrong codes. Request a new one.');
        }

        Database::update('otp_codes', ['attempts' => (int) $otp['attempts'] + 1], 'id = :id', ['id' => $otp['id']]);

        if (!hash_equals((string) $otp['code_hash'], hash('sha256', $code))) {
            throw HttpException::badRequest('That code is not correct.', 'BAD_OTP');
        }

        Database::update('otp_codes', ['consumed_at' => Str::dbDate()], 'id = :id', ['id' => $otp['id']]);

        $this->gateRow((string) $event['id'], $userId);
        Database::update('meeting_access', [
            $channel === 'email' ? 'email_otp_ok' : 'mobile_otp_ok' => 1,
            'updated_at' => Str::dbDate(),
        ], 'event_id = :event AND user_id = :user', ['event' => $event['id'], 'user' => $userId]);

        // The gate also serves as account verification.
        Database::update(
            'users',
            [$channel === 'email' ? 'email_verified_at' : 'phone_verified_at' => Str::dbDate()],
            'id = :id',
            ['id' => $userId]
        );

        return Response::json($this->gateState((string) $event['id'], $userId));
    }

    /** POST /events/{id}/access/device — camera check + device binding. */
    public function registerDevice(Request $request, array $params): Response
    {
        $event = MeetingService::find((string) $params['id']);
        $userId = (string) $request->userId();
        MeetingService::assertParticipant($event, $userId, (string) $request->role());

        $fingerprint = $request->string('deviceFingerprint');
        if ($fingerprint === '') {
            throw HttpException::badRequest('A device fingerprint is required.');
        }

        $this->gateRow((string) $event['id'], $userId);

        $existing = Database::first(
            'SELECT device_fingerprint, granted_at FROM meeting_access WHERE event_id = :event AND user_id = :user',
            ['event' => $event['id'], 'user' => $userId]
        );

        // One device per participant per room — a second one invalidates the
        // first, which is what the frontend's "session invalidated" state meant.
        if ($existing !== null
            && $existing['device_fingerprint'] !== null
            && $existing['device_fingerprint'] !== $fingerprint
            && $existing['granted_at'] !== null) {
            Database::update('meeting_access', [
                'revoked_at'    => Str::dbDate(),
                'revoke_reason' => 'Signed in from another device.',
                'updated_at'    => Str::dbDate(),
            ], 'event_id = :event AND user_id = :user', ['event' => $event['id'], 'user' => $userId]);
        }

        Database::update('meeting_access', [
            'camera_ok'          => $request->bool('cameraOk', true) ? 1 : 0,
            'device_fingerprint' => substr($fingerprint, 0, 120),
            'ip'                 => $request->ip(),
            'revoked_at'         => null,
            'revoke_reason'      => null,
            'updated_at'         => Str::dbDate(),
        ], 'event_id = :event AND user_id = :user', ['event' => $event['id'], 'user' => $userId]);

        return Response::json($this->gateState((string) $event['id'], $userId));
    }

    /** GET /events/{id}/access */
    public function accessState(Request $request, array $params): Response
    {
        $event = MeetingService::find((string) $params['id']);
        $userId = (string) $request->userId();
        MeetingService::assertParticipant($event, $userId, (string) $request->role());

        return Response::json($this->gateState((string) $event['id'], $userId));
    }

    /**
     * POST /events/{id}/access/grant
     * Final gate: all four checks, plus a valid seller pass, then the token.
     */
    public function grantAccess(Request $request, array $params): Response
    {
        $event = MeetingService::find((string) $params['id']);
        $user = $request->user();
        $userId = (string) $user['id'];
        $role = (string) $user['role'];

        MeetingService::assertParticipant($event, $userId, $role);

        if ($event['status'] === 'cancelled') {
            throw HttpException::unprocessable('This pitch room was cancelled.', 'EVENT_CANCELLED');
        }

        BillingService::assertAccess($userId, $role, (string) $event['id']);

        $state = $this->gateState((string) $event['id'], $userId);
        if (!$state['passed']) {
            throw HttpException::forbidden(
                'Complete the security checks before entering the room.',
                'GATE_INCOMPLETE'
            );
        }

        Database::update('meeting_access', [
            'granted_at' => Str::dbDate(),
            'expires_at' => Str::dbDate(time() + Env::int('JITSI_TOKEN_TTL', 7200)),
            'updated_at' => Str::dbDate(),
        ], 'event_id = :event AND user_id = :user', ['event' => $event['id'], 'user' => $userId]);

        $moderator = MeetingService::isModerator($event, $userId, $role);
        $token = MeetingService::issueRoomToken($event, $user, $moderator);

        ActivityLog::record($userId, 'event', (string) $event['id'], 'access_granted', [], $request);

        return Response::json($token + [
            'event' => MeetingService::present($event),
            'state' => MeetingService::presentState((string) $event['id']),
        ]);
    }

    /** POST /events/{id}/access/revoke — host kicks a participant. */
    public function revokeAccess(Request $request, array $params): Response
    {
        $event = MeetingService::find((string) $params['id']);

        if (!MeetingService::isModerator($event, (string) $request->userId(), (string) $request->role())) {
            throw HttpException::forbidden('Only the host can remove a participant.');
        }

        $targetId = $request->string('userId');
        if ($targetId === '') {
            throw HttpException::badRequest('Provide the userId to remove.');
        }

        Database::update('meeting_access', [
            'revoked_at'    => Str::dbDate(),
            'revoke_reason' => $request->string('reason') ?: 'Removed by the host.',
            'granted_at'    => null,
            'updated_at'    => Str::dbDate(),
        ], 'event_id = :event AND user_id = :user', ['event' => $event['id'], 'user' => $targetId]);

        ActivityLog::record((string) $request->userId(), 'event', (string) $event['id'], 'access_revoked', ['userId' => $targetId], $request);

        return Response::json(['revoked' => true]);
    }

    // --------------------------------------------------------- room lifecycle

    /** POST /meetings/{id}/token */
    public function token(Request $request, array $params): Response
    {
        $event = MeetingService::find((string) $params['id']);
        $user = $request->user();

        MeetingService::assertParticipant($event, (string) $user['id'], (string) $user['role']);
        BillingService::assertAccess((string) $user['id'], (string) $user['role'], (string) $event['id']);

        $access = Database::first(
            'SELECT granted_at, revoked_at FROM meeting_access WHERE event_id = :event AND user_id = :user',
            ['event' => $event['id'], 'user' => $user['id']]
        );

        if ($access === null || $access['granted_at'] === null || $access['revoked_at'] !== null) {
            throw HttpException::forbidden('Pass the room security checks first.', 'GATE_INCOMPLETE');
        }

        $moderator = MeetingService::isModerator($event, (string) $user['id'], (string) $user['role']);

        return Response::json(MeetingService::issueRoomToken($event, $user, $moderator));
    }

    /** POST /meetings/{id}/join */
    public function join(Request $request, array $params): Response
    {
        $event = MeetingService::find((string) $params['id']);
        $userId = (string) $request->userId();
        MeetingService::assertParticipant($event, $userId, (string) $request->role());

        Database::update('event_participants', [
            'joined_at'   => Str::dbDate(),
            'left_at'     => null,
            'rsvp_status' => 'Joined',
        ], 'event_id = :event AND user_id = :user', ['event' => $event['id'], 'user' => $userId]);

        return Response::json(MeetingService::presentState((string) $event['id']));
    }

    /** POST /meetings/{id}/leave */
    public function leave(Request $request, array $params): Response
    {
        $event = MeetingService::find((string) $params['id']);
        $userId = (string) $request->userId();

        $participant = Database::first(
            'SELECT joined_at, attendance_seconds FROM event_participants WHERE event_id = :event AND user_id = :user',
            ['event' => $event['id'], 'user' => $userId]
        );

        $seconds = (int) ($participant['attendance_seconds'] ?? 0);
        if ($participant !== null && $participant['joined_at'] !== null) {
            $joined = strtotime((string) $participant['joined_at'] . ' UTC');
            if ($joined !== false) {
                $seconds += max(0, time() - $joined);
            }
        }

        Database::update('event_participants', [
            'left_at'            => Str::dbDate(),
            'attendance_seconds' => $seconds,
        ], 'event_id = :event AND user_id = :user', ['event' => $event['id'], 'user' => $userId]);

        return Response::json(['left' => true, 'attendanceSeconds' => $seconds]);
    }

    /** GET /meetings/{id}/participants */
    public function participants(Request $request, array $params): Response
    {
        $event = MeetingService::find((string) $params['id']);
        MeetingService::assertParticipant($event, (string) $request->userId(), (string) $request->role());

        $rows = Database::select(
            'SELECT ep.*, u.name, u.company, u.role AS user_role
             FROM event_participants ep JOIN users u ON u.id = ep.user_id
             WHERE ep.event_id = :id ORDER BY ep.side DESC, ep.slot_position ASC',
            ['id' => $event['id']]
        );

        return Response::json(array_map(static fn (array $row): array => [
            'userId'   => $row['user_id'],
            'name'     => $row['name'],
            'company'  => $row['company'],
            'role'     => $row['user_role'],
            'side'     => $row['side'],
            'slotPosition' => $row['slot_position'] === null ? null : (int) $row['slot_position'],
            'inRoom'   => $row['joined_at'] !== null && $row['left_at'] === null,
            'joinedAt' => Str::toIso($row['joined_at']),
        ], $rows));
    }

    /** GET /meetings/{id}/state — the shared clock every client renders from. */
    public function state(Request $request, array $params): Response
    {
        $event = MeetingService::find((string) $params['id']);
        MeetingService::assertParticipant($event, (string) $request->userId(), (string) $request->role());

        // Self-healing: if the stage expired and no job has run yet, catch up
        // on read so a client never sees a stale countdown.
        if ($event['status'] === 'live') {
            MeetingService::advance((string) $event['id']);
        }

        return Response::json(MeetingService::presentState((string) $event['id']) + [
            'eventStatus' => Database::scalar('SELECT status FROM events WHERE id = :id', ['id' => $event['id']]),
        ]);
    }

    /** POST /meetings/{id}/stage/advance */
    public function advanceStage(Request $request, array $params): Response
    {
        $event = MeetingService::find((string) $params['id']);

        if (!MeetingService::isModerator($event, (string) $request->userId(), (string) $request->role())) {
            throw HttpException::forbidden('Only the host can move the agenda on.');
        }

        return Response::json(MeetingService::advance((string) $event['id'], true));
    }

    /** POST /meetings/{id}/stage/extend */
    public function extendStage(Request $request, array $params): Response
    {
        $event = MeetingService::find((string) $params['id']);

        if (!MeetingService::isModerator($event, (string) $request->userId(), (string) $request->role())) {
            throw HttpException::forbidden('Only the host can extend a stage.');
        }

        $seconds = max(5, min(600, $request->int('seconds', 30)));

        return Response::json(MeetingService::extend((string) $event['id'], $seconds));
    }

    // ------------------------------------------------------------- room chat

    /** GET /meetings/{id}/chat */
    public function chat(Request $request, array $params): Response
    {
        $event = MeetingService::find((string) $params['id']);
        MeetingService::assertParticipant($event, (string) $request->userId(), (string) $request->role());

        $since = $request->string('since');
        $bindings = ['id' => $event['id']];
        $clause = '';
        if ($since !== '') {
            $clause = ' AND created_at > :since';
            $bindings['since'] = Str::dbDate($since);
        }

        $rows = Database::select(
            "SELECT * FROM meeting_messages WHERE event_id = :id{$clause} ORDER BY created_at ASC LIMIT 300",
            $bindings
        );

        return Response::json(array_map(static fn (array $row): array => [
            'id'        => $row['id'],
            'fromId'    => $row['from_id'],
            'sender'    => $row['sender'],
            'role'      => $row['sender_role'],
            'text'      => $row['body'],
            'type'      => $row['type'],
            'at'        => Str::toIso($row['created_at']),
        ], $rows));
    }

    /** POST /meetings/{id}/chat */
    public function sendChat(Request $request, array $params): Response
    {
        $event = MeetingService::find((string) $params['id']);
        $user = $request->user();
        MeetingService::assertParticipant($event, (string) $user['id'], (string) $user['role']);

        $body = trim($request->string('body') ?: $request->string('text'));
        if ($body === '') {
            throw HttpException::badRequest('Write a message first.');
        }

        RateLimiter::hit('room-chat:' . $user['id'], Env::int('RATE_LIMIT_MESSAGE', 60), 60);

        $id = Id::make('rmsg');
        Database::insert('meeting_messages', [
            'id'          => $id,
            'event_id'    => $event['id'],
            'from_id'     => $user['id'],
            'sender'      => $user['name'],
            'sender_role' => $user['role'],
            'body'        => Str::limit($body, 2000, ''),
            'type'        => 'user',
            'created_at'  => Str::dbDate(),
        ]);

        return Response::created([
            'id'     => $id,
            'sender' => $user['name'],
            'role'   => $user['role'],
            'text'   => $body,
            'at'     => Str::iso(),
        ]);
    }

    // ---------------------------------------------------------------- Q and A

    /** GET /meetings/{id}/questions */
    public function questions(Request $request, array $params): Response
    {
        $event = MeetingService::find((string) $params['id']);
        MeetingService::assertParticipant($event, (string) $request->userId(), (string) $request->role());

        $rows = Database::select(
            'SELECT * FROM meeting_questions WHERE event_id = :id ORDER BY upvotes DESC, created_at ASC',
            ['id' => $event['id']]
        );

        return Response::json(array_map(static fn (array $row): array => [
            'id'          => $row['id'],
            'askedBy'     => $row['asked_by'],
            'askerName'   => $row['asker_name'],
            'presenterId' => $row['presenter_id'],
            'category'    => $row['category'],
            'question'    => $row['question'],
            'answer'      => $row['answer'],
            'answeredAt'  => Str::toIso($row['answered_at']),
            'upvotes'     => (int) $row['upvotes'],
            'createdAt'   => Str::toIso($row['created_at']),
        ], $rows));
    }

    /** POST /meetings/{id}/questions */
    public function askQuestion(Request $request, array $params): Response
    {
        $event = MeetingService::find((string) $params['id']);
        $user = $request->user();
        MeetingService::assertParticipant($event, (string) $user['id'], (string) $user['role']);

        $question = trim($request->string('question'));
        if ($question === '') {
            throw HttpException::badRequest('Write a question first.');
        }

        $state = MeetingService::state((string) $event['id']);
        $id = Id::make('q');

        Database::insert('meeting_questions', [
            'id'           => $id,
            'event_id'     => $event['id'],
            'asked_by'     => $user['id'],
            'asker_name'   => $user['name'],
            'presenter_id' => $request->string('presenterId') ?: ($state['presenter_id'] ?? null),
            'category'     => $request->string('category') ?: null,
            'question'     => Str::limit($question, 990, ''),
            'answer'       => null,
            'answered_by'  => null,
            'answered_at'  => null,
            'upvotes'      => 0,
            'created_at'   => Str::dbDate(),
        ]);

        return Response::created(['id' => $id, 'question' => $question]);
    }

    /** POST /meetings/{id}/questions/{questionId}/answer */
    public function answerQuestion(Request $request, array $params): Response
    {
        $event = MeetingService::find((string) $params['id']);
        $userId = (string) $request->userId();
        MeetingService::assertParticipant($event, $userId, (string) $request->role());

        $question = Database::first(
            'SELECT * FROM meeting_questions WHERE id = :id AND event_id = :event',
            ['id' => (string) $params['questionId'], 'event' => $event['id']]
        );
        if ($question === null) {
            throw HttpException::notFound('Question not found.');
        }

        // Only the presenter being asked (or the host) may answer.
        if ($question['presenter_id'] !== null
            && $question['presenter_id'] !== $userId
            && !MeetingService::isModerator($event, $userId, (string) $request->role())) {
            throw HttpException::forbidden('Only the presenter can answer this question.');
        }

        Database::update('meeting_questions', [
            'answer'      => Str::limit($request->string('answer'), 4000, ''),
            'answered_by' => $userId,
            'answered_at' => Str::dbDate(),
        ], 'id = :id', ['id' => $question['id']]);

        return Response::json(['answered' => true]);
    }

    /** POST /meetings/{id}/questions/{questionId}/upvote */
    public function upvoteQuestion(Request $request, array $params): Response
    {
        $event = MeetingService::find((string) $params['id']);
        $userId = (string) $request->userId();
        MeetingService::assertParticipant($event, $userId, (string) $request->role());

        $questionId = (string) $params['questionId'];

        $inserted = Database::statement(
            'INSERT IGNORE INTO meeting_question_votes (question_id, user_id, created_at) VALUES (:question, :user, :created)',
            ['question' => $questionId, 'user' => $userId, 'created' => Str::dbDate()]
        );

        if ($inserted > 0) {
            Database::statement(
                'UPDATE meeting_questions SET upvotes = upvotes + 1 WHERE id = :id',
                ['id' => $questionId]
            );
        }

        return Response::json([
            'upvoted' => true,
            'upvotes' => (int) Database::scalar('SELECT upvotes FROM meeting_questions WHERE id = :id', ['id' => $questionId]),
        ]);
    }

    // --------------------------------------------------------- premium slots

    /** GET /events/{id}/slots */
    public function slots(Request $request, array $params): Response
    {
        $event = MeetingService::find((string) $params['id']);
        MeetingService::assertParticipant($event, (string) $request->userId(), (string) $request->role());

        return Response::json(SlotService::board((string) $event['id']));
    }

    /** POST /events/{id}/slots/{position}/claim */
    public function claimSlot(Request $request, array $params): Response
    {
        $event = MeetingService::find((string) $params['id']);
        $userId = (string) $request->userId();
        MeetingService::assertParticipant($event, $userId, (string) $request->role());

        $position = (int) $params['position'];
        $paymentId = $request->string('paymentId') ?: null;

        // A paid slot needs a settled payment for this user, event and position.
        if ($paymentId !== null) {
            $payment = Database::first(
                "SELECT * FROM payments
                 WHERE id = :id AND user_id = :user AND event_id = :event
                   AND slot_position = :position AND status = 'paid'",
                ['id' => $paymentId, 'user' => $userId, 'event' => $event['id'], 'position' => $position]
            );
            if ($payment === null) {
                throw HttpException::unprocessable('That payment is not settled for this position.', 'PAYMENT_NOT_SETTLED');
            }
        }

        return Response::json(SlotService::claim((string) $event['id'], $userId, $position, $paymentId));
    }

    // ---------------------------------------------------------------- helpers

    private function gateRow(string $eventId, string $userId): void
    {
        Database::statement(
            'INSERT IGNORE INTO meeting_access
                (id, event_id, user_id, code_ok, email_otp_ok, mobile_otp_ok, camera_ok, created_at, updated_at)
             VALUES (:id, :event, :user, 0, 0, 0, 0, :created, :updated)',
            [
                'id'      => Id::make('gate'),
                'event'   => $eventId,
                'user'    => $userId,
                'created' => Str::dbDate(),
                'updated' => Str::dbDate(),
            ]
        );
    }

    private function gateState(string $eventId, string $userId): array
    {
        $row = Database::first(
            'SELECT * FROM meeting_access WHERE event_id = :event AND user_id = :user',
            ['event' => $eventId, 'user' => $userId]
        );

        $steps = [
            'code'   => (int) ($row['code_ok'] ?? 0) === 1,
            'email'  => (int) ($row['email_otp_ok'] ?? 0) === 1,
            'mobile' => (int) ($row['mobile_otp_ok'] ?? 0) === 1,
            'camera' => (int) ($row['camera_ok'] ?? 0) === 1,
        ];

        return [
            'eventId'   => $eventId,
            'steps'     => $steps,
            'passed'    => !in_array(false, $steps, true) && ($row['revoked_at'] ?? null) === null,
            'granted'   => ($row['granted_at'] ?? null) !== null && ($row['revoked_at'] ?? null) === null,
            'revoked'   => ($row['revoked_at'] ?? null) !== null,
            'revokeReason' => $row['revoke_reason'] ?? null,
            'nextStep'  => $steps['code'] ? ($steps['email'] ? ($steps['mobile'] ? ($steps['camera'] ? null : 'camera') : 'mobile') : 'email') : 'code',
        ];
    }

    private function mask(string $value, string $channel): string
    {
        if ($channel === 'email' && str_contains($value, '@')) {
            [$name, $domain] = explode('@', $value, 2);
            return substr($name, 0, 2) . str_repeat('*', max(1, strlen($name) - 2)) . '@' . $domain;
        }

        return str_repeat('*', max(0, strlen($value) - 4)) . substr($value, -4);
    }
}
