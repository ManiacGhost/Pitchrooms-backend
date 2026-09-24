<?php

declare(strict_types=1);

namespace PitchRooms\Controllers;

use PitchRooms\Core\Database;
use PitchRooms\Core\HttpException;
use PitchRooms\Core\Request;
use PitchRooms\Core\Response;
use PitchRooms\Services\ActivityLog;
use PitchRooms\Services\AuthService;
use PitchRooms\Services\MeetingService;
use PitchRooms\Services\NotificationService;
use PitchRooms\Services\SchedulerService;
use PitchRooms\Services\SlotService;
use PitchRooms\Support\Id;
use PitchRooms\Support\Str;
use PitchRooms\Support\Validator;

/**
 * Scheduling for every meeting shape in the product: the multi-seller AB/SI
 * pitch event, the 1:1 EE interview, and the SI founder/investor pitch.
 */
final class EventController
{
    public const STATUSES = [
        'requested', 'scheduled', 'waiting_room', 'live',
        'evaluation', 'decision_made', 'completed', 'cancelled',
    ];

    /** GET /events */
    public function index(Request $request): Response
    {
        $where = [];
        $bindings = [];

        foreach ([
            'opportunityId' => 'e.opportunity_id = :opportunityId',
            'buyerId'       => 'e.buyer_id = :buyerId',
            'sellerId'      => 'e.seller_id = :sellerId',
            'status'        => 'e.status = :status',
            'vertical'      => 'e.vertical = :vertical',
        ] as $key => $clause) {
            $value = $request->string($key);
            if ($value !== '') {
                $where[] = $clause;
                $bindings[$key] = $value;
            }
        }

        if ($from = $request->string('from')) {
            $where[] = 'e.start_at >= :from';
            $bindings['from'] = Str::dbDate($from);
        }
        if ($to = $request->string('to')) {
            $where[] = 'e.start_at <= :to';
            $bindings['to'] = Str::dbDate($to);
        }

        if ($request->string('window') === 'upcoming') {
            $where[] = "(e.start_at IS NULL OR e.start_at >= UTC_TIMESTAMP()) AND e.status NOT IN ('completed','cancelled')";
        } elseif ($request->string('window') === 'past') {
            $where[] = "(e.start_at < UTC_TIMESTAMP() OR e.status IN ('completed','cancelled'))";
        }

        // You see the rooms you are in, unless you are an admin.
        if (!$request->isAdmin()) {
            $userId = (string) $request->userId();
            $where[] = '(e.buyer_id = :me1 OR e.seller_id = :me2 OR e.created_by = :me3
                         OR EXISTS (SELECT 1 FROM event_participants ep WHERE ep.event_id = e.id AND ep.user_id = :me4))';
            $bindings += ['me1' => $userId, 'me2' => $userId, 'me3' => $userId, 'me4' => $userId];
        }

        $whereSql = $where === [] ? '1=1' : implode(' AND ', $where);
        $page = $request->page();
        $perPage = $request->perPage(20);
        $offset = ($page - 1) * $perPage;

        $total = (int) Database::scalar("SELECT COUNT(*) FROM events e WHERE {$whereSql}", $bindings);

        $rows = Database::select(
            "SELECT e.*, o.title AS opportunity_title, o.company AS opportunity_company
             FROM events e
             LEFT JOIN opportunities o ON o.id = e.opportunity_id
             WHERE {$whereSql}
             ORDER BY COALESCE(e.start_at, e.created_at) DESC
             LIMIT {$perPage} OFFSET {$offset}",
            $bindings
        );

        $items = array_map(static fn (array $row): array => MeetingService::present($row, [
            'opportunityTitle'   => $row['opportunity_title'] ?? null,
            'opportunityCompany' => $row['opportunity_company'] ?? null,
        ]), $rows);

        return Response::paginated($items, $total, $page, $perPage);
    }

    /** GET /events/{id} */
    public function show(Request $request, array $params): Response
    {
        $event = MeetingService::find((string) $params['id']);
        MeetingService::assertParticipant($event, (string) $request->userId(), (string) $request->role());

        return Response::json(MeetingService::present($event, [
            'participants' => $this->participants((string) $event['id']),
            'agendaItems'  => MeetingService::presentState((string) $event['id'])['agenda'],
            'meetingCode'  => MeetingService::isModerator($event, (string) $request->userId(), (string) $request->role())
                ? $event['meeting_code']
                : null,
        ]));
    }

    /**
     * POST /events
     * Buyer schedules a room (or a seller requests one — the other side then
     * accepts, which is the rule the SI/AB stores already enforced).
     */
    public function store(Request $request): Response
    {
        $user = $request->user();
        $userId = (string) $user['id'];

        $opportunityId = $request->string('opportunityId') ?: null;
        $opportunity = $opportunityId === null ? null : OpportunityController::findOrFail($opportunityId);

        $sellerIds = $request->array('sellerIds');
        $singleSeller = $request->string('sellerId');
        if ($singleSeller !== '' && !in_array($singleSeller, $sellerIds, true)) {
            $sellerIds[] = $singleSeller;
        }

        // No explicit sellers? Use the confirmed shortlist.
        if ($sellerIds === [] && $opportunity !== null) {
            $sellerIds = array_column(
                Database::select(
                    'SELECT seller_id FROM shortlists WHERE opportunity_id = :id ORDER BY position ASC',
                    ['id' => $opportunity['id']]
                ),
                'seller_id'
            );
        }

        if ($sellerIds === []) {
            throw HttpException::unprocessable(
                'Shortlist at least one seller before scheduling a pitch room.',
                'NO_SELLERS'
            );
        }

        $buyerId = $opportunity !== null
            ? (string) $opportunity['owner_id']
            : (AuthService::isBuyer((string) $user['role']) ? $userId : $request->string('buyerId'));

        if ($buyerId === '') {
            throw HttpException::badRequest('A buyer is required for a pitch room.');
        }
        if ($buyerId !== $userId && !in_array($userId, $sellerIds, true) && !$request->isAdmin()) {
            throw HttpException::forbidden('You are not part of this meeting.');
        }

        $startAt = $request->string('startAt');
        if ($startAt !== '') {
            Validator::make(['startAt' => $startAt], ['startAt' => 'required|date|future']);
        }

        $vertical = $opportunity['vertical'] ?? AuthService::verticalForRole((string) $user['role']);
        $eventId = Id::make(match ($vertical) { 'ee' => 'ee-int', 'si' => 'si-meet', default => 'ab-meet' });

        $presentation = $opportunity['presentation_duration'] ?? $request->int('presentationDuration', 30);
        $qa = $opportunity['qa_duration'] ?? $request->int('qaDuration', 30);

        // A scheduled time from the buyer is already agreed; a seller's
        // request waits for the buyer to accept.
        $isScheduled = $startAt !== '' && $buyerId === $userId;
        $now = Str::dbDate();

        Database::transaction(function () use ($eventId, $opportunity, $vertical, $buyerId, $sellerIds, $request, $startAt, $isScheduled, $presentation, $qa, $userId, $now): void {
            $name = $request->string('title') ?: sprintf(
                'Live Pitch Room · %s',
                $opportunity['title'] ?? 'Pitch'
            );

            Database::insert('events', [
                'id'                    => $eventId,
                'opportunity_id'        => $opportunity['id'] ?? null,
                'vertical'              => $vertical,
                'buyer_id'              => $buyerId,
                'seller_id'             => count($sellerIds) === 1 ? $sellerIds[0] : null,
                'proposal_id'           => $request->string('proposalId') ?: null,
                'name'                  => $name,
                'agenda'                => $request->string('agenda') ?: 'Intro, pitch, Q&A, and next steps.',
                'status'                => $isScheduled ? 'scheduled' : 'requested',
                'start_at'              => $startAt === '' ? null : Str::dbDate($startAt),
                'end_at'                => $startAt === '' ? null : Str::dbDate(
                    strtotime($startAt) + (count($sellerIds) * ($presentation + $qa)) + 600
                ),
                'presentation_duration' => (int) $presentation,
                'qa_duration'           => (int) $qa,
                'room_name'             => MeetingService::roomName($eventId),
                'meeting_code'          => MeetingService::generateMeetingCode(),
                'created_by'            => $userId,
                'accepted_by'           => $isScheduled ? $userId : null,
                'accepted_at'           => $isScheduled ? $now : null,
                'started_at'            => null,
                'ended_at'              => null,
                'cancelled_at'          => null,
                'cancel_reason'         => null,
                'decision'              => null,
                'recording_file_id'     => null,
                'created_at'            => $now,
                'updated_at'            => $now,
            ]);

            Database::insert('event_participants', [
                'event_id'           => $eventId,
                'user_id'            => $buyerId,
                'role'               => (string) Database::scalar('SELECT role FROM users WHERE id = :id', ['id' => $buyerId]),
                'side'               => 'buyer',
                'slot_position'      => null,
                'fee_minor'          => 0,
                'currency'           => null,
                'rsvp_status'        => 'Confirmed',
                'joined_at'          => null,
                'left_at'            => null,
                'attendance_seconds' => 0,
                'created_at'         => $now,
            ]);

            foreach ($sellerIds as $sellerId) {
                Database::statement(
                    "INSERT IGNORE INTO event_participants
                       (event_id, user_id, role, side, slot_position, fee_minor, rsvp_status, attendance_seconds, created_at)
                     VALUES (:event, :user, :role, 'seller', NULL, 0, 'Pending', 0, :created)",
                    [
                        'event'   => $eventId,
                        'user'    => $sellerId,
                        'role'    => (string) Database::scalar('SELECT role FROM users WHERE id = :id', ['id' => $sellerId]),
                        'created' => $now,
                    ]
                );

                if ($opportunity !== null) {
                    Database::update('proposals', [
                        'status'     => $vertical === 'ee' ? 'interview_scheduled' : 'pitch_scheduled',
                        'event_id'   => $eventId,
                        'updated_at' => $now,
                    ], 'opportunity_id = :opportunity AND seller_id = :seller', [
                        'opportunity' => $opportunity['id'],
                        'seller'      => $sellerId,
                    ]);
                }
            }

            SlotService::rebuildAgenda($eventId);

            if ($opportunity !== null) {
                Database::update('opportunities', [
                    'status'     => 'Event Scheduled',
                    'event_id'   => $eventId,
                    'updated_at' => $now,
                ], 'id = :id', ['id' => $opportunity['id']]);
            }
        });

        $event = MeetingService::find($eventId);

        $recipients = array_merge([$buyerId], $sellerIds);
        foreach (array_unique(array_filter($recipients)) as $recipient) {
            if ($recipient === $userId) {
                continue;
            }
            NotificationService::push(
                (string) $recipient,
                $isScheduled ? 'meeting_accepted' : 'meeting_requested',
                $isScheduled ? 'Pitch room scheduled' : 'Pitch room requested',
                $isScheduled
                    ? sprintf('"%s" is scheduled for %s.', $event['name'], Str::toIso($event['start_at']))
                    : sprintf('%s requested a pitch room: "%s". Confirm a time.', $user['name'], $event['name']),
                '/events/' . $eventId,
                $isScheduled ? 'Open pitch room' : 'Confirm slot',
                'event',
                $eventId,
                true
            );
        }

        if ($isScheduled) {
            SchedulerService::queue('meeting.reminders', [], 0, 'reminders:' . $eventId);
        }

        ActivityLog::record($userId, 'event', $eventId, 'created', ['sellers' => count($sellerIds)], $request);

        return Response::created(MeetingService::present($event, [
            'participants' => $this->participants($eventId),
        ]));
    }

    /**
     * POST /events/{id}/accept
     * The counterparty confirms — never the requester. That rule came
     * straight from the SI/AB stores and is enforced here.
     */
    public function accept(Request $request, array $params): Response
    {
        $event = MeetingService::find((string) $params['id']);
        $userId = (string) $request->userId();

        MeetingService::assertParticipant($event, $userId, (string) $request->role());

        if ($event['status'] !== 'requested') {
            throw HttpException::unprocessable('This meeting is not awaiting acceptance.', 'NOT_PENDING');
        }
        if ($event['created_by'] === $userId && !$request->isAdmin()) {
            throw HttpException::forbidden(
                'The other party needs to accept this pitch.',
                'REQUESTER_CANNOT_ACCEPT'
            );
        }

        $startAt = $request->string('startAt') ?: Str::toIso($event['start_at']);
        if ($startAt === null || $startAt === '') {
            throw HttpException::badRequest('Pick a meeting time.', 'START_REQUIRED');
        }
        Validator::make(['startAt' => $startAt], ['startAt' => 'required|date|future']);

        $sellerCount = max(1, (int) Database::scalar(
            "SELECT COUNT(*) FROM event_participants WHERE event_id = :id AND side = 'seller'",
            ['id' => $event['id']]
        ));
        $duration = $sellerCount * ((int) $event['presentation_duration'] + (int) $event['qa_duration']) + 600;

        Database::update('events', [
            'status'      => 'scheduled',
            'start_at'    => Str::dbDate($startAt),
            'end_at'      => Str::dbDate(strtotime($startAt) + $duration),
            'accepted_by' => $userId,
            'accepted_at' => Str::dbDate(),
            'updated_at'  => Str::dbDate(),
        ], 'id = :id', ['id' => $event['id']]);

        Database::update('event_participants', [
            'rsvp_status' => 'Confirmed',
        ], 'event_id = :event AND user_id = :user', ['event' => $event['id'], 'user' => $userId]);

        NotificationService::push(
            (string) $event['created_by'],
            'meeting_accepted',
            'Pitch accepted',
            sprintf('%s accepted "%s" for %s.', $request->user()['name'], $event['name'], $startAt),
            '/events/' . $event['id'],
            'Open pitch room',
            'event',
            (string) $event['id'],
            true
        );

        SchedulerService::queue('meeting.reminders', [], 0, 'reminders:' . $event['id'] . ':' . time());
        ActivityLog::record($userId, 'event', (string) $event['id'], 'accepted', ['startAt' => $startAt], $request);

        return Response::json(MeetingService::present(MeetingService::find((string) $event['id'])));
    }

    /** POST /events/{id}/reschedule */
    public function reschedule(Request $request, array $params): Response
    {
        $event = MeetingService::find((string) $params['id']);
        $userId = (string) $request->userId();

        if (!MeetingService::isModerator($event, $userId, (string) $request->role())) {
            throw HttpException::forbidden('Only the host can reschedule this room.');
        }
        if (in_array((string) $event['status'], ['live', 'completed'], true)) {
            throw HttpException::unprocessable('A live or finished room cannot be rescheduled.', 'ALREADY_RUN');
        }

        Validator::make($request, ['startAt' => 'required|date|future']);
        $startAt = $request->string('startAt');

        $sellerCount = max(1, (int) Database::scalar(
            "SELECT COUNT(*) FROM event_participants WHERE event_id = :id AND side = 'seller'",
            ['id' => $event['id']]
        ));
        $duration = $sellerCount * ((int) $event['presentation_duration'] + (int) $event['qa_duration']) + 600;

        Database::update('events', [
            'status'     => 'scheduled',
            'start_at'   => Str::dbDate($startAt),
            'end_at'     => Str::dbDate(strtotime($startAt) + $duration),
            'updated_at' => Str::dbDate(),
        ], 'id = :id', ['id' => $event['id']]);

        foreach ($this->participantIds((string) $event['id']) as $participantId) {
            if ($participantId === $userId) {
                continue;
            }
            NotificationService::push(
                $participantId,
                'meeting_requested',
                'Pitch room rescheduled',
                sprintf('"%s" moved to %s.', $event['name'], $startAt),
                '/events/' . $event['id'],
                'View new time',
                'event',
                (string) $event['id'],
                true
            );
        }

        return Response::json(MeetingService::present(MeetingService::find((string) $event['id'])));
    }

    /** POST /events/{id}/cancel */
    public function cancel(Request $request, array $params): Response
    {
        $event = MeetingService::find((string) $params['id']);
        $userId = (string) $request->userId();

        if (!MeetingService::isModerator($event, $userId, (string) $request->role())) {
            throw HttpException::forbidden('Only the host can cancel this room.');
        }

        $reason = $request->string('reason') ?: 'Cancelled by the host.';

        Database::update('events', [
            'status'        => 'cancelled',
            'cancelled_at'  => Str::dbDate(),
            'cancel_reason' => $reason,
            'updated_at'    => Str::dbDate(),
        ], 'id = :id', ['id' => $event['id']]);

        foreach ($this->participantIds((string) $event['id']) as $participantId) {
            if ($participantId === $userId) {
                continue;
            }
            NotificationService::push(
                $participantId,
                'meeting_requested',
                'Pitch room cancelled',
                sprintf('"%s" was cancelled. %s', $event['name'], $reason),
                '/events',
                'View events',
                'event',
                (string) $event['id'],
                true
            );
        }

        ActivityLog::record($userId, 'event', (string) $event['id'], 'cancelled', ['reason' => $reason], $request);

        return Response::json(['cancelled' => true]);
    }

    /** POST /events/{id}/status */
    public function setStatus(Request $request, array $params): Response
    {
        $event = MeetingService::find((string) $params['id']);
        $status = $request->string('status');

        if (!in_array($status, self::STATUSES, true)) {
            throw HttpException::badRequest('Unknown status. Allowed: ' . implode(', ', self::STATUSES), 'INVALID_STATUS');
        }
        if (!MeetingService::isModerator($event, (string) $request->userId(), (string) $request->role())) {
            throw HttpException::forbidden('Only the host can change the room status.');
        }

        if ($status === 'live') {
            MeetingService::start((string) $event['id']);
        } elseif (in_array($status, ['completed', 'evaluation'], true)) {
            MeetingService::complete((string) $event['id']);
        } else {
            Database::update('events', [
                'status'     => $status,
                'updated_at' => Str::dbDate(),
            ], 'id = :id', ['id' => $event['id']]);
        }

        return Response::json(MeetingService::present(MeetingService::find((string) $event['id'])));
    }

    /** GET /events/{id}/agenda */
    public function agenda(Request $request, array $params): Response
    {
        $event = MeetingService::find((string) $params['id']);
        MeetingService::assertParticipant($event, (string) $request->userId(), (string) $request->role());

        return Response::json(MeetingService::presentState((string) $event['id'])['agenda']);
    }

    /** POST /events/{id}/agenda/reorder — admin/host override. */
    public function reorderAgenda(Request $request, array $params): Response
    {
        $event = MeetingService::find((string) $params['id']);

        if (!MeetingService::isModerator($event, (string) $request->userId(), (string) $request->role())) {
            throw HttpException::forbidden('Only the host can reorder the agenda.');
        }

        $order = $request->array('order'); // [sellerId, sellerId, …]
        if ($order === []) {
            throw HttpException::badRequest('Provide the seller order.');
        }

        Database::transaction(static function () use ($order, $event): void {
            // Clear first: the UNIQUE(event_id, slot_position) index would
            // otherwise collide mid-reorder.
            Database::update('event_participants', ['slot_position' => null], 'event_id = :event', ['event' => $event['id']]);

            foreach (array_values($order) as $index => $sellerId) {
                Database::update('event_participants', [
                    'slot_position' => $index + 1,
                ], 'event_id = :event AND user_id = :user', ['event' => $event['id'], 'user' => (string) $sellerId]);
            }

            SlotService::rebuildAgenda((string) $event['id']);
        });

        return Response::json(MeetingService::presentState((string) $event['id']));
    }

    /** GET /calendar */
    public function calendar(Request $request): Response
    {
        $userId = (string) $request->userId();
        $from = Str::dbDate($request->string('from') ?: 'now -30 days');
        $to = Str::dbDate($request->string('to') ?: 'now +90 days');

        $rows = Database::select(
            "SELECT e.*, o.title AS opportunity_title
             FROM events e
             LEFT JOIN opportunities o ON o.id = e.opportunity_id
             WHERE e.status <> 'cancelled'
               AND e.start_at BETWEEN :from AND :to
               AND (e.buyer_id = :me1 OR e.seller_id = :me2
                    OR EXISTS (SELECT 1 FROM event_participants ep WHERE ep.event_id = e.id AND ep.user_id = :me3))
             ORDER BY e.start_at ASC",
            ['from' => $from, 'to' => $to, 'me1' => $userId, 'me2' => $userId, 'me3' => $userId]
        );

        return Response::json(array_map(static fn (array $row): array => [
            'id'      => $row['id'],
            'title'   => $row['name'],
            'status'  => $row['status'],
            'startAt' => Str::toIso($row['start_at']),
            'endAt'   => Str::toIso($row['end_at']),
            'link'    => '/events/' . $row['id'],
            'opportunityTitle' => $row['opportunity_title'],
        ], $rows));
    }

    // ---------------------------------------------------------------- helpers

    private function participants(string $eventId): array
    {
        $rows = Database::select(
            'SELECT ep.*, u.name, u.company, u.role AS user_role
             FROM event_participants ep
             JOIN users u ON u.id = ep.user_id
             WHERE ep.event_id = :id
             ORDER BY ep.side DESC, ep.slot_position ASC',
            ['id' => $eventId]
        );

        return array_map(static fn (array $row): array => [
            'userId'       => $row['user_id'],
            'name'         => $row['name'],
            'company'      => $row['company'],
            'role'         => $row['user_role'],
            'side'         => $row['side'],
            'slotPosition' => $row['slot_position'] === null ? null : (int) $row['slot_position'],
            'rsvpStatus'   => $row['rsvp_status'],
            'joinedAt'     => Str::toIso($row['joined_at']),
            'leftAt'       => Str::toIso($row['left_at']),
            'attendanceSeconds' => (int) $row['attendance_seconds'],
        ], $rows);
    }

    private function participantIds(string $eventId): array
    {
        return array_column(
            Database::select('SELECT user_id FROM event_participants WHERE event_id = :id', ['id' => $eventId]),
            'user_id'
        );
    }
}
