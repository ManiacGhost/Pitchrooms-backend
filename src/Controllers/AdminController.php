<?php

declare(strict_types=1);

namespace PitchRooms\Controllers;

use PitchRooms\Core\Database;
use PitchRooms\Core\HttpException;
use PitchRooms\Core\Request;
use PitchRooms\Core\Response;
use PitchRooms\Services\ActivityLog;
use PitchRooms\Services\AuthService;
use PitchRooms\Services\NotificationService;
use PitchRooms\Support\Str;

/**
 * Every route here sits behind role:admin.
 */
final class AdminController
{
    /** GET /admin/stats */
    public function stats(Request $request): Response
    {
        $counts = Database::first(
            "SELECT
               (SELECT COUNT(*) FROM users WHERE deleted_at IS NULL) AS users,
               (SELECT COUNT(*) FROM users WHERE verification_status = 'Pending') AS pending_verification,
               (SELECT COUNT(*) FROM opportunities WHERE deleted_at IS NULL) AS opportunities,
               (SELECT COUNT(*) FROM proposals) AS proposals,
               (SELECT COUNT(*) FROM events) AS events,
               (SELECT COUNT(*) FROM events WHERE status = 'live') AS live_events,
               (SELECT COUNT(*) FROM events WHERE status = 'scheduled') AS scheduled_events,
               (SELECT COUNT(*) FROM threads) AS threads,
               (SELECT COUNT(*) FROM messages) AS messages,
               (SELECT COUNT(*) FROM passes WHERE status = 'active') AS active_passes,
               (SELECT COALESCE(SUM(amount_minor), 0) FROM payments WHERE status = 'paid') AS revenue_minor"
        );

        $byRole = Database::select(
            'SELECT role, COUNT(*) AS total FROM users WHERE deleted_at IS NULL GROUP BY role'
        );

        return Response::json([
            'users'               => (int) $counts['users'],
            'pendingVerification' => (int) $counts['pending_verification'],
            'opportunities'       => (int) $counts['opportunities'],
            'proposals'           => (int) $counts['proposals'],
            'events'              => (int) $counts['events'],
            'liveEvents'          => (int) $counts['live_events'],
            'scheduledEvents'     => (int) $counts['scheduled_events'],
            'threads'             => (int) $counts['threads'],
            'messages'            => (int) $counts['messages'],
            'activePasses'        => (int) $counts['active_passes'],
            'revenue'             => (int) $counts['revenue_minor'] / 100,
            'usersByRole'         => array_column($byRole, 'total', 'role'),
        ]);
    }

    /** GET /admin/users */
    public function users(Request $request): Response
    {
        $where = ['u.deleted_at IS NULL'];
        $bindings = [];

        foreach ([
            'role'         => 'u.role = :role',
            'status'       => 'u.account_status = :status',
            'verification' => 'u.verification_status = :verification',
            'panelId'      => 'u.panel_id = :panelId',
        ] as $key => $clause) {
            $value = $request->string($key);
            if ($value !== '') {
                $where[] = $clause;
                $bindings[$key] = $value;
            }
        }

        if ($q = $request->string('q')) {
            $where[] = '(u.name LIKE :q OR u.email LIKE :q2 OR u.company LIKE :q3)';
            $like = '%' . $q . '%';
            $bindings += ['q' => $like, 'q2' => $like, 'q3' => $like];
        }

        $whereSql = implode(' AND ', $where);
        $page = $request->page();
        $perPage = $request->perPage(25);
        $offset = ($page - 1) * $perPage;

        $total = (int) Database::scalar("SELECT COUNT(*) FROM users u WHERE {$whereSql}", $bindings);

        $rows = Database::select(
            "SELECT u.*, p.trust_score, p.rating
             FROM users u LEFT JOIN profiles p ON p.user_id = u.id
             WHERE {$whereSql}
             ORDER BY u.created_at DESC
             LIMIT {$perPage} OFFSET {$offset}",
            $bindings
        );

        $items = array_map(static fn (array $row): array => AuthService::publicUser($row) + [
            'trustScore'  => (int) ($row['trust_score'] ?? 0),
            'rating'      => (float) ($row['rating'] ?? 0),
            'lastLoginAt' => Str::toIso($row['last_login_at']),
        ], $rows);

        return Response::paginated($items, $total, $page, $perPage);
    }

    /** PATCH /admin/users/{id} */
    public function updateUser(Request $request, array $params): Response
    {
        $user = AuthService::requireUser((string) $params['id']);

        $changes = [];
        foreach (['name' => 'name', 'company' => 'company', 'title' => 'title',
                  'phone' => 'phone', 'country' => 'country'] as $input => $column) {
            if ($request->has($input)) {
                $changes[$column] = $request->string($input) ?: null;
            }
        }

        if ($changes !== []) {
            $changes['updated_at'] = Str::dbDate();
            Database::update('users', $changes, 'id = :id', ['id' => $user['id']]);
        }

        ActivityLog::record((string) $request->userId(), 'user', (string) $user['id'], 'admin_updated', array_keys($changes), $request);

        return Response::json(AuthService::publicUser(AuthService::requireUser((string) $user['id'])));
    }

    /** GET /admin/verification/queue */
    public function verificationQueue(Request $request): Response
    {
        $status = $request->string('status') ?: 'Pending';

        $rows = Database::select(
            'SELECT u.*, p.payload, p.trust_score
             FROM users u LEFT JOIN profiles p ON p.user_id = u.id
             WHERE u.verification_status = :status AND u.deleted_at IS NULL
             ORDER BY u.created_at ASC
             LIMIT 200',
            ['status' => $status]
        );

        return Response::json(array_map(static function (array $row): array {
            $payload = Str::fromJson($row['payload'] ?? null, []);

            return AuthService::publicUser($row) + [
                'submittedAt' => Str::toIso($row['created_at']),
                'documents'   => is_array($payload) ? ($payload['documents'] ?? []) : [],
                'gst'         => is_array($payload) ? ($payload['gst'] ?? null) : null,
                'trustScore'  => (int) ($row['trust_score'] ?? 0),
            ];
        }, $rows));
    }

    /** POST /admin/verification/{userId} */
    public function setVerification(Request $request, array $params): Response
    {
        $user = AuthService::requireUser((string) $params['userId']);
        $status = $request->string('status');

        if (!in_array($status, ['Verified', 'Rejected', 'Pending'], true)) {
            throw HttpException::badRequest('Status must be Verified, Rejected or Pending.', 'INVALID_STATUS');
        }

        Database::update('users', [
            'verification_status' => $status,
            'updated_at'          => Str::dbDate(),
        ], 'id = :id', ['id' => $user['id']]);

        Database::update('profiles', [
            'verified'   => $status === 'Verified' ? 1 : 0,
            'updated_at' => Str::dbDate(),
        ], 'user_id = :id', ['id' => $user['id']]);

        NotificationService::push(
            (string) $user['id'],
            'verification',
            $status === 'Verified' ? 'Account verified' : 'Verification update',
            $status === 'Verified'
                ? 'Your account is verified. The verified badge now shows on your profile and proposals.'
                : ($request->string('note') ?: 'Your verification needs more information. Check your documents.'),
            '/profile',
            'View profile',
            'user',
            (string) $user['id'],
            true
        );

        ActivityLog::record((string) $request->userId(), 'user', (string) $user['id'], 'verification:' . $status, ['note' => $request->string('note')], $request);

        return Response::json(['userId' => $user['id'], 'verificationStatus' => $status]);
    }

    /** POST /admin/users/{id}/status — Active / Suspended. */
    public function setAccountStatus(Request $request, array $params): Response
    {
        $user = AuthService::requireUser((string) $params['id']);
        $status = $request->string('status');

        if (!in_array($status, ['Active', 'Suspended'], true)) {
            throw HttpException::badRequest('Status must be Active or Suspended.', 'INVALID_STATUS');
        }
        if ($user['role'] === 'admin' && $status === 'Suspended') {
            throw HttpException::unprocessable('Admin accounts cannot be suspended here.', 'ADMIN_PROTECTED');
        }

        Database::update('users', [
            'account_status' => $status,
            'updated_at'     => Str::dbDate(),
        ], 'id = :id', ['id' => $user['id']]);

        // Suspension ends every live session immediately.
        if ($status === 'Suspended') {
            AuthService::revokeAllForUser((string) $user['id']);
        }

        ActivityLog::record((string) $request->userId(), 'user', (string) $user['id'], 'account:' . $status, [], $request);

        return Response::json(['userId' => $user['id'], 'accountStatus' => $status]);
    }

    /** POST /admin/opportunities/{id}/approve */
    public function approveOpportunity(Request $request, array $params): Response
    {
        $opportunity = OpportunityController::findOrFail((string) $params['id']);

        OpportunityController::setStatusFor($opportunity, 'Approved', $request->string('note') ?: 'Approved by admin.', (string) $request->userId());

        NotificationService::push(
            (string) $opportunity['owner_id'],
            'opportunity',
            'Opportunity approved',
            sprintf('"%s" is approved and now visible to sellers.', $opportunity['title']),
            '/opportunities/' . $opportunity['id'],
            'View opportunity',
            'opportunity',
            (string) $opportunity['id'],
            true
        );

        return Response::json(['approved' => true]);
    }

    /** POST /admin/opportunities/{id}/reject */
    public function rejectOpportunity(Request $request, array $params): Response
    {
        $opportunity = OpportunityController::findOrFail((string) $params['id']);
        $reason = $request->string('reason') ?: 'Did not meet listing guidelines.';

        OpportunityController::setStatusFor($opportunity, 'Draft', 'Rejected by admin: ' . $reason, (string) $request->userId());

        NotificationService::push(
            (string) $opportunity['owner_id'],
            'opportunity',
            'Opportunity needs changes',
            sprintf('"%s" was sent back: %s', $opportunity['title'], $reason),
            '/opportunities/' . $opportunity['id'],
            'Edit opportunity',
            'opportunity',
            (string) $opportunity['id'],
            true
        );

        return Response::json(['rejected' => true]);
    }

    /** GET /admin/pitch-rooms — the live monitor. */
    public function pitchRooms(Request $request): Response
    {
        $rows = Database::select(
            "SELECT e.*, o.title AS opportunity_title,
                    (SELECT COUNT(*) FROM event_participants ep WHERE ep.event_id = e.id) AS participant_count,
                    (SELECT COUNT(*) FROM event_participants ep WHERE ep.event_id = e.id AND ep.joined_at IS NOT NULL AND ep.left_at IS NULL) AS in_room,
                    ms.stage, ms.presenter_id, ms.stage_ends_at
             FROM events e
             LEFT JOIN opportunities o ON o.id = e.opportunity_id
             LEFT JOIN meeting_state ms ON ms.event_id = e.id
             WHERE e.status IN ('waiting_room','live','scheduled')
             ORDER BY e.start_at ASC
             LIMIT 100"
        );

        return Response::json(array_map(static fn (array $row): array => [
            'id'               => $row['id'],
            'name'             => $row['name'],
            'status'           => $row['status'],
            'stage'            => $row['stage'],
            'presenterId'      => $row['presenter_id'],
            'stageEndsAt'      => Str::toIso($row['stage_ends_at']),
            'startAt'          => Str::toIso($row['start_at']),
            'roomName'         => $row['room_name'],
            'participantCount' => (int) $row['participant_count'],
            'inRoom'           => (int) $row['in_room'],
            'opportunityTitle' => $row['opportunity_title'],
        ], $rows));
    }

    /** GET /admin/reports */
    public function reports(Request $request): Response
    {
        $from = Str::dbDate($request->string('from') ?: 'now -30 days');
        $to = Str::dbDate($request->string('to') ?: 'now');

        $signups = Database::select(
            'SELECT DATE(created_at) AS day, role, COUNT(*) AS total
             FROM users WHERE created_at BETWEEN :from AND :to
             GROUP BY day, role ORDER BY day ASC',
            ['from' => $from, 'to' => $to]
        );

        $events = Database::select(
            'SELECT DATE(created_at) AS day, status, COUNT(*) AS total
             FROM events WHERE created_at BETWEEN :from AND :to
             GROUP BY day, status ORDER BY day ASC',
            ['from' => $from, 'to' => $to]
        );

        $revenue = Database::select(
            "SELECT DATE(created_at) AS day, kind, currency, SUM(amount_minor) AS total_minor, COUNT(*) AS payments
             FROM payments WHERE status = 'paid' AND created_at BETWEEN :from AND :to
             GROUP BY day, kind, currency ORDER BY day ASC",
            ['from' => $from, 'to' => $to]
        );

        $funnel = Database::first(
            'SELECT
               (SELECT COUNT(*) FROM opportunities WHERE created_at BETWEEN :f1 AND :t1) AS opportunities,
               (SELECT COUNT(*) FROM proposals WHERE created_at BETWEEN :f2 AND :t2) AS proposals,
               (SELECT COUNT(*) FROM shortlists WHERE created_at BETWEEN :f3 AND :t3) AS shortlisted,
               (SELECT COUNT(*) FROM events WHERE created_at BETWEEN :f4 AND :t4) AS events,
               (SELECT COUNT(*) FROM decisions WHERE created_at BETWEEN :f5 AND :t5) AS decisions',
            [
                'f1' => $from, 't1' => $to, 'f2' => $from, 't2' => $to, 'f3' => $from,
                't3' => $to, 'f4' => $from, 't4' => $to, 'f5' => $from, 't5' => $to,
            ]
        );

        return Response::json([
            'range'   => ['from' => Str::toIso($from), 'to' => Str::toIso($to)],
            'signups' => $signups,
            'events'  => $events,
            'revenue' => array_map(static fn (array $row): array => [
                'day'      => $row['day'],
                'kind'     => $row['kind'],
                'currency' => $row['currency'],
                'total'    => (int) $row['total_minor'] / 100,
                'payments' => (int) $row['payments'],
            ], $revenue),
            'funnel'  => array_map('intval', $funnel ?? []),
        ]);
    }

    /** GET /admin/activity-log */
    public function activityLog(Request $request): Response
    {
        $where = [];
        $bindings = [];

        foreach (['entityType' => 'a.entity_type = :entityType', 'entityId' => 'a.entity_id = :entityId',
                  'actorId' => 'a.actor_id = :actorId', 'action' => 'a.action = :action'] as $key => $clause) {
            $value = $request->string($key);
            if ($value !== '') {
                $where[] = $clause;
                $bindings[$key] = $value;
            }
        }

        $whereSql = $where === [] ? '1=1' : implode(' AND ', $where);
        $page = $request->page();
        $perPage = $request->perPage(50);
        $offset = ($page - 1) * $perPage;

        $total = (int) Database::scalar("SELECT COUNT(*) FROM activity_log a WHERE {$whereSql}", $bindings);

        $rows = Database::select(
            "SELECT a.*, u.name AS actor_name, u.role AS actor_role
             FROM activity_log a LEFT JOIN users u ON u.id = a.actor_id
             WHERE {$whereSql} ORDER BY a.created_at DESC
             LIMIT {$perPage} OFFSET {$offset}",
            $bindings
        );

        return Response::paginated(array_map([ActivityLog::class, 'present'], $rows), $total, $page, $perPage);
    }

    /** GET /admin/billing/transactions */
    public function transactions(Request $request): Response
    {
        $rows = Database::select(
            'SELECT p.*, u.name, u.email, u.role
             FROM payments p JOIN users u ON u.id = p.user_id
             ORDER BY p.created_at DESC LIMIT 200'
        );

        return Response::json(array_map(static fn (array $row): array => [
            'id'           => $row['id'],
            'userId'       => $row['user_id'],
            'userName'     => $row['name'],
            'userEmail'    => $row['email'],
            'role'         => $row['role'],
            'kind'         => $row['kind'],
            'planId'       => $row['plan_id'],
            'eventId'      => $row['event_id'],
            'slotPosition' => $row['slot_position'],
            'amount'       => (int) $row['amount_minor'] / 100,
            'currency'     => $row['currency'],
            'status'       => $row['status'],
            'provider'     => $row['provider'],
            'providerRef'  => $row['provider_ref'],
            'createdAt'    => Str::toIso($row['created_at']),
        ], $rows));
    }

    /** GET /admin/settings */
    public function settings(Request $request): Response
    {
        $rows = Database::select('SELECT * FROM settings');

        $settings = [];
        foreach ($rows as $row) {
            $settings[(string) $row['key']] = Str::fromJson($row['value'] ?? null, null);
        }

        $settings['plans'] = Database::select('SELECT * FROM plans ORDER BY sort_order');
        $settings['slotPricing'] = Database::select('SELECT * FROM slot_pricing ORDER BY vertical, position');

        return Response::json($settings);
    }

    /** PUT /admin/settings */
    public function updateSettings(Request $request): Response
    {
        foreach ($request->all() as $key => $value) {
            if (in_array($key, ['plans', 'slotPricing'], true)) {
                continue; // these have their own tables and endpoints
            }

            Database::upsert('settings', [
                'key'        => mb_substr((string) $key, 0, 120),
                'value'      => Str::json($value),
                'updated_at' => Str::dbDate(),
                'updated_by' => (string) $request->userId(),
            ], ['value', 'updated_at', 'updated_by']);
        }

        return $this->settings($request);
    }

    /** PUT /admin/slot-pricing — pricing moves without a frontend deploy. */
    public function updateSlotPricing(Request $request): Response
    {
        $rows = $request->array('slots');

        foreach ($rows as $slot) {
            if (!isset($slot['vertical'], $slot['position'])) {
                continue;
            }

            Database::upsert('slot_pricing', [
                'vertical'    => (string) $slot['vertical'],
                'position'    => (int) $slot['position'],
                'title'       => (string) ($slot['title'] ?? 'Position #' . $slot['position']),
                'description' => $slot['description'] ?? null,
                'fee_minor'   => (int) round(((float) ($slot['fee'] ?? 0)) * 100),
                'currency'    => (string) ($slot['currency'] ?? 'INR'),
                'active'      => !empty($slot['active']) ? 1 : 0,
            ], ['title', 'description', 'fee_minor', 'currency', 'active']);
        }

        ActivityLog::record((string) $request->userId(), 'settings', 'slot_pricing', 'updated', [], $request);

        return Response::json(Database::select('SELECT * FROM slot_pricing ORDER BY vertical, position'));
    }
}
