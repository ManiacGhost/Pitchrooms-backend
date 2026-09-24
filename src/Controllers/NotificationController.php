<?php

declare(strict_types=1);

namespace PitchRooms\Controllers;

use PitchRooms\Core\Database;
use PitchRooms\Core\HttpException;
use PitchRooms\Core\Request;
use PitchRooms\Core\Response;
use PitchRooms\Services\NotificationService;
use PitchRooms\Support\Str;

final class NotificationController
{
    /** GET /notifications */
    public function index(Request $request): Response
    {
        $userId = (string) $request->userId();
        $where = ['user_id = :user'];
        $bindings = ['user' => $userId];

        if ($request->bool('unread')) {
            $where[] = 'read_at IS NULL';
        }
        if ($type = $request->string('type')) {
            $where[] = 'type = :type';
            $bindings['type'] = $type;
        }

        $whereSql = implode(' AND ', $where);
        $page = $request->page();
        $perPage = $request->perPage(25);
        $offset = ($page - 1) * $perPage;

        $total = (int) Database::scalar("SELECT COUNT(*) FROM notifications WHERE {$whereSql}", $bindings);

        $rows = Database::select(
            "SELECT * FROM notifications WHERE {$whereSql} ORDER BY created_at DESC LIMIT {$perPage} OFFSET {$offset}",
            $bindings
        );

        return Response::paginated(
            array_map([NotificationService::class, 'present'], $rows),
            $total,
            $page,
            $perPage,
            ['unreadCount' => NotificationService::unreadCount($userId)]
        );
    }

    /** GET /notifications/unread-count — the bell badge. */
    public function unreadCount(Request $request): Response
    {
        return Response::json(['unreadCount' => NotificationService::unreadCount((string) $request->userId())]);
    }

    /** POST /notifications/{id}/read */
    public function markRead(Request $request, array $params): Response
    {
        NotificationService::markRead((string) $params['id'], (string) $request->userId());

        return Response::json(['read' => true]);
    }

    /** POST /notifications/read-all */
    public function markAllRead(Request $request): Response
    {
        return Response::json(['read' => NotificationService::markAllRead((string) $request->userId())]);
    }

    /** DELETE /notifications/{id} */
    public function destroy(Request $request, array $params): Response
    {
        $deleted = Database::statement(
            'DELETE FROM notifications WHERE id = :id AND user_id = :user',
            ['id' => (string) $params['id'], 'user' => (string) $request->userId()]
        );

        if ($deleted === 0) {
            throw HttpException::notFound('Notification not found.');
        }

        return Response::json(['deleted' => true]);
    }

    /** GET /notifications/preferences */
    public function preferences(Request $request): Response
    {
        $rows = Database::select(
            'SELECT * FROM notification_preferences WHERE user_id = :user',
            ['user' => (string) $request->userId()]
        );

        $byType = [];
        foreach ($rows as $row) {
            $byType[(string) $row['type']] = [
                'email' => (bool) $row['email'],
                'sms'   => (bool) $row['sms'],
                'inApp' => (bool) $row['in_app'],
            ];
        }

        // Types with no row saved fall back to email + in-app on.
        $defaults = [
            'pitch_submitted', 'shortlisted', 'meeting_requested', 'meeting_accepted',
            'meeting_reminder', 'interview', 'application', 'pipeline', 'message',
            'decision', 'verification', 'payment', 'feedback', 'opportunity', 'system',
        ];

        $preferences = [];
        foreach ($defaults as $type) {
            $preferences[$type] = $byType[$type] ?? ['email' => true, 'sms' => false, 'inApp' => true];
        }

        return Response::json($preferences);
    }

    /** PUT /notifications/preferences */
    public function updatePreferences(Request $request): Response
    {
        $userId = (string) $request->userId();
        $input = $request->all();

        foreach ($input as $type => $settings) {
            if (!is_array($settings)) {
                continue;
            }

            Database::upsert('notification_preferences', [
                'user_id' => $userId,
                'type'    => mb_substr((string) $type, 0, 40),
                'email'   => !empty($settings['email']) ? 1 : 0,
                'sms'     => !empty($settings['sms']) ? 1 : 0,
                'in_app'  => array_key_exists('inApp', $settings) ? (!empty($settings['inApp']) ? 1 : 0) : 1,
            ], ['email', 'sms', 'in_app']);
        }

        return $this->preferences($request);
    }

    /**
     * GET /realtime/poll
     * One request returns every delta a panel needs. This is what replaces
     * WebSockets on PHP hosting — the frontend polls this while a tab is open.
     */
    public function poll(Request $request): Response
    {
        $userId = (string) $request->userId();
        $since = Str::dbDate($request->string('since') ?: 'now -60 seconds');
        $now = Str::iso();

        $messages = Database::select(
            'SELECT * FROM messages WHERE to_id = :user AND created_at > :since ORDER BY created_at ASC LIMIT 100',
            ['user' => $userId, 'since' => $since]
        );

        $notifications = Database::select(
            'SELECT * FROM notifications WHERE user_id = :user AND created_at > :since ORDER BY created_at DESC LIMIT 50',
            ['user' => $userId, 'since' => $since]
        );

        $payload = [
            'serverTime'    => $now,
            'cursor'        => $now,
            'messages'      => array_map([\PitchRooms\Services\ThreadService::class, 'presentMessage'], $messages),
            'notifications' => array_map([NotificationService::class, 'present'], $notifications),
            'unreadCount'   => NotificationService::unreadCount($userId),
        ];

        // A live room's clock rides along so the countdown stays in step.
        if ($eventId = $request->string('eventId')) {
            $event = Database::first('SELECT * FROM events WHERE id = :id', ['id' => $eventId]);
            if ($event !== null) {
                \PitchRooms\Services\MeetingService::assertParticipant($event, $userId, (string) $request->role());

                if ($event['status'] === 'live') {
                    \PitchRooms\Services\MeetingService::advance($eventId);
                }

                $payload['meetingState'] = \PitchRooms\Services\MeetingService::presentState($eventId);
                $payload['roomChat'] = array_map(static fn (array $row): array => [
                    'id'     => $row['id'],
                    'fromId' => $row['from_id'],
                    'sender' => $row['sender'],
                    'role'   => $row['sender_role'],
                    'text'   => $row['body'],
                    'type'   => $row['type'],
                    'at'     => Str::toIso($row['created_at']),
                ], Database::select(
                    'SELECT * FROM meeting_messages WHERE event_id = :event AND created_at > :since ORDER BY created_at ASC LIMIT 100',
                    ['event' => $eventId, 'since' => $since]
                ));
            }
        }

        return Response::json($payload);
    }
}
