<?php

declare(strict_types=1);

namespace PitchRooms\Controllers;

use PitchRooms\Core\Database;
use PitchRooms\Core\Env;
use PitchRooms\Core\HttpException;
use PitchRooms\Core\Request;
use PitchRooms\Core\Response;
use PitchRooms\Services\NotificationService;
use PitchRooms\Services\RateLimiter;
use PitchRooms\Services\ThreadService;
use PitchRooms\Support\Id;
use PitchRooms\Support\Str;

final class ThreadController
{
    /** GET /threads */
    public function index(Request $request): Response
    {
        $userId = (string) $request->userId();
        $page = $request->page();
        $perPage = $request->perPage(30);
        $offset = ($page - 1) * $perPage;

        $total = (int) Database::scalar(
            'SELECT COUNT(*) FROM threads WHERE party_a_id = :a OR party_b_id = :b',
            ['a' => $userId, 'b' => $userId]
        );

        $rows = Database::select(
            "SELECT * FROM threads
             WHERE party_a_id = :a OR party_b_id = :b
             ORDER BY COALESCE(last_message_at, created_at) DESC
             LIMIT {$perPage} OFFSET {$offset}",
            ['a' => $userId, 'b' => $userId]
        );

        $items = array_map(static fn (array $row): array => ThreadService::present($row, $userId), $rows);

        return Response::paginated($items, $total, $page, $perPage, [
            'unreadTotal' => (int) Database::scalar(
                'SELECT COUNT(*) FROM messages WHERE to_id = :user AND read_at IS NULL',
                ['user' => $userId]
            ),
        ]);
    }

    /** POST /threads — 403 MESSAGING_LOCKED until a pitch has completed. */
    public function store(Request $request): Response
    {
        $userId = (string) $request->userId();
        $counterpartyId = $request->string('counterpartyId')
            ?: $request->string('toId')
            ?: $request->string('agencyId')
            ?: $request->string('employeeId')
            ?: $request->string('startupId');

        if ($counterpartyId === '') {
            throw HttpException::badRequest('Provide the person you want to message.', 'COUNTERPARTY_REQUIRED');
        }

        $thread = ThreadService::getOrCreate(
            $userId,
            $counterpartyId,
            $request->string('opportunityId') ?: null
        );

        return Response::json(ThreadService::present($thread, $userId));
    }

    /** GET /threads/{id} */
    public function show(Request $request, array $params): Response
    {
        $userId = (string) $request->userId();
        $thread = ThreadService::requireMember((string) $params['id'], $userId, (string) $request->role());

        return Response::json(ThreadService::present($thread, $userId));
    }

    /** GET /threads/{id}/messages — cursor paginated, newest last. */
    public function messages(Request $request, array $params): Response
    {
        $userId = (string) $request->userId();
        $thread = ThreadService::requireMember((string) $params['id'], $userId, (string) $request->role());

        $limit = max(1, min(100, $request->int('limit', 50)));
        $bindings = ['thread' => $thread['id']];
        $clause = '';

        if ($before = $request->string('before')) {
            $clause = ' AND created_at < :before';
            $bindings['before'] = Str::dbDate($before);
        }
        if ($since = $request->string('since')) {
            $clause = ' AND created_at > :since';
            $bindings['since'] = Str::dbDate($since);
        }

        $rows = Database::select(
            "SELECT * FROM messages WHERE thread_id = :thread{$clause} ORDER BY created_at DESC LIMIT {$limit}",
            $bindings
        );

        $rows = array_reverse($rows);

        return Response::json(array_map([ThreadService::class, 'presentMessage'], $rows));
    }

    /** POST /threads/{id}/messages */
    public function send(Request $request, array $params): Response
    {
        $userId = (string) $request->userId();
        $thread = ThreadService::requireMember((string) $params['id'], $userId, (string) $request->role());

        $body = trim($request->string('body'));
        if ($body === '') {
            throw HttpException::badRequest('Message body cannot be empty.', 'EMPTY_MESSAGE');
        }

        // Re-check the gate on every send — a thread that existed before an
        // event was cancelled must not stay open.
        $otherId = ThreadService::counterparty($thread, $userId);
        if (!ThreadService::canMessage($userId, $otherId)) {
            throw HttpException::forbidden(
                'Messaging stays locked until the pitch meeting is completed.',
                'MESSAGING_LOCKED'
            );
        }

        RateLimiter::hit('message:' . $userId, Env::int('RATE_LIMIT_MESSAGE', 60), 60);

        $clientMsgId = $request->string('clientMsgId') ?: null;

        // Idempotent retry: the same clientMsgId returns the original message.
        if ($clientMsgId !== null) {
            $existing = Database::first(
                'SELECT * FROM messages WHERE thread_id = :thread AND client_msg_id = :client',
                ['thread' => $thread['id'], 'client' => $clientMsgId]
            );
            if ($existing !== null) {
                return Response::json(ThreadService::presentMessage($existing));
            }
        }

        $id = Id::make('msg');
        $now = Str::dbDate();

        Database::transaction(static function () use ($id, $thread, $userId, $otherId, $body, $clientMsgId, $now): void {
            Database::insert('messages', [
                'id'            => $id,
                'thread_id'     => $thread['id'],
                'from_id'       => $userId,
                'to_id'         => $otherId,
                'body'          => $body,
                'type'          => 'user',
                'client_msg_id' => $clientMsgId,
                'read_at'       => null,
                'created_at'    => $now,
            ]);

            Database::update('threads', [
                'last_message_at'      => $now,
                'last_message_preview' => Str::limit($body, 200),
            ], 'id = :id', ['id' => $thread['id']]);
        });

        $muted = Str::fromJson($thread['muted_by'] ?? null, []);
        if (!in_array($otherId, is_array($muted) ? $muted : [], true)) {
            NotificationService::push(
                $otherId,
                'message',
                'New message',
                Str::limit($body, 120),
                '/inbox/' . $thread['id'],
                'Open conversation',
                'thread',
                (string) $thread['id']
            );
        }

        $message = Database::first('SELECT * FROM messages WHERE id = :id', ['id' => $id]);

        return Response::created(ThreadService::presentMessage($message));
    }

    /** POST /threads/{id}/read */
    public function markRead(Request $request, array $params): Response
    {
        $userId = (string) $request->userId();
        $thread = ThreadService::requireMember((string) $params['id'], $userId, (string) $request->role());

        $updated = Database::update(
            'messages',
            ['read_at' => Str::dbDate()],
            'thread_id = :thread AND to_id = :user AND read_at IS NULL',
            ['thread' => $thread['id'], 'user' => $userId]
        );

        return Response::json(['read' => $updated]);
    }

    /** POST /threads/{id}/archive and /mute — per-user flags. */
    public function toggleFlag(Request $request, array $params): Response
    {
        $userId = (string) $request->userId();
        $thread = ThreadService::requireMember((string) $params['id'], $userId, (string) $request->role());

        $flag = (string) $params['flag'];
        if (!in_array($flag, ['archive', 'mute'], true)) {
            throw HttpException::notFound('Unknown action.');
        }

        $column = $flag === 'archive' ? 'archived_by' : 'muted_by';
        $current = Str::fromJson($thread[$column] ?? null, []);
        $current = is_array($current) ? $current : [];

        $on = $request->bool('on', !in_array($userId, $current, true));

        $next = $on
            ? array_values(array_unique(array_merge($current, [$userId])))
            : array_values(array_diff($current, [$userId]));

        Database::update('threads', [$column => Str::json($next)], 'id = :id', ['id' => $thread['id']]);

        return Response::json([$flag => $on]);
    }

    /** GET /threads/{id}/messages/stream — SSE for shared PHP hosting. */
    public function stream(Request $request, array $params): Response
    {
        $userId = (string) $request->userId();
        $thread = ThreadService::requireMember((string) $params['id'], $userId, (string) $request->role());

        @set_time_limit(0);
        ignore_user_abort(false);

        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('X-Accel-Buffering: no');

        $cursor = $request->string('since') ?: Str::iso(time() - 60);
        $started = time();

        // Bounded: the client reconnects, which suits PHP-FPM far better than
        // holding a worker open indefinitely.
        while (!connection_aborted() && (time() - $started) < 25) {
            $rows = Database::select(
                'SELECT * FROM messages WHERE thread_id = :thread AND created_at > :since ORDER BY created_at ASC LIMIT 50',
                ['thread' => $thread['id'], 'since' => Str::dbDate($cursor)]
            );

            foreach ($rows as $row) {
                echo 'event: message' . "\n";
                echo 'data: ' . json_encode(ThreadService::presentMessage($row), JSON_UNESCAPED_SLASHES) . "\n\n";
                $cursor = Str::toIso($row['created_at']) ?? $cursor;
            }

            echo ": ping\n\n";
            @ob_flush();
            @flush();
            sleep(2);
        }

        exit;
    }
}
