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
use PitchRooms\Services\ThreadService;
use PitchRooms\Support\Id;
use PitchRooms\Support\Str;
use PitchRooms\Support\Validator;

/**
 * Scorecards, the winner decision and its cascades, plus ratings and feedback.
 */
final class EvaluationController
{
    public const CRITERIA = [
        'industryExpertise' => 'industry_expertise',
        'creativity'        => 'creativity',
        'teamConfidence'    => 'team_confidence',
        'communication'     => 'communication',
        'caseStudies'       => 'case_studies',
        'commercialFit'     => 'commercial_fit',
    ];

    /** POST /events/{id}/evaluations */
    public function store(Request $request, array $params): Response
    {
        $event = MeetingService::find((string) $params['id']);
        $user = $request->user();

        if (!AuthService::isBuyer((string) $user['role'])) {
            throw HttpException::forbidden('Only the buyer panel scores a pitch.', 'BUYER_ONLY');
        }
        MeetingService::assertParticipant($event, (string) $user['id'], (string) $user['role']);

        Validator::make($request, ['sellerId' => 'required']);
        $sellerId = $request->string('sellerId');

        $isPresenter = (int) Database::scalar(
            "SELECT COUNT(*) FROM event_participants WHERE event_id = :event AND user_id = :user AND side = 'seller'",
            ['event' => $event['id'], 'user' => $sellerId]
        );
        if ($isPresenter === 0) {
            throw HttpException::badRequest('That seller did not present in this room.', 'NOT_A_PRESENTER');
        }

        $scores = [];
        $total = 0;
        foreach (self::CRITERIA as $input => $column) {
            $value = max(0, min(10, $request->int($input, 0)));
            $scores[$column] = $value;
            $total += $value;
        }

        $average = round($total / count(self::CRITERIA), 2);

        Database::upsert('evaluations', array_merge($scores, [
            'id'           => Id::make('eval'),
            'event_id'     => $event['id'],
            'evaluator_id' => $user['id'],
            'seller_id'    => $sellerId,
            'total_score'  => $average,
            'notes'        => $request->string('notes') ?: null,
            'submitted_at' => Str::dbDate(),
        ]), array_merge(array_values(self::CRITERIA), ['total_score', 'notes', 'submitted_at']));

        ActivityLog::record((string) $user['id'], 'event', (string) $event['id'], 'evaluated', ['sellerId' => $sellerId], $request);

        return Response::created([
            'eventId'   => $event['id'],
            'sellerId'  => $sellerId,
            'scores'    => $scores,
            'average'   => $average,
        ]);
    }

    /**
     * GET /events/{id}/evaluations
     * Sellers never see raw scorecards; buyers see their own panel's.
     */
    public function index(Request $request, array $params): Response
    {
        $event = MeetingService::find((string) $params['id']);
        $user = $request->user();
        MeetingService::assertParticipant($event, (string) $user['id'], (string) $user['role']);

        if (!AuthService::isBuyer((string) $user['role'])) {
            throw HttpException::forbidden('Scorecards are visible to the buyer panel only.', 'BUYER_ONLY');
        }

        $rows = Database::select(
            'SELECT e.*, u.name AS seller_name, u.company AS seller_company, ev.name AS evaluator_name
             FROM evaluations e
             JOIN users u ON u.id = e.seller_id
             JOIN users ev ON ev.id = e.evaluator_id
             WHERE e.event_id = :id
             ORDER BY e.total_score DESC',
            ['id' => $event['id']]
        );

        return Response::json(array_map(static function (array $row): array {
            $scores = [];
            foreach (self::CRITERIA as $key => $column) {
                $scores[$key] = (int) $row[$column];
            }

            return [
                'evaluatorId'   => $row['evaluator_id'],
                'evaluatorName' => $row['evaluator_name'],
                'sellerId'      => $row['seller_id'],
                'sellerName'    => $row['seller_name'],
                'company'       => $row['seller_company'],
                'scores'        => $scores,
                'average'       => (float) $row['total_score'],
                'notes'         => $row['notes'],
                'submittedAt'   => Str::toIso($row['submitted_at']),
            ];
        }, $rows));
    }

    /** GET /events/{id}/leaderboard — aggregate across every evaluator. */
    public function leaderboard(Request $request, array $params): Response
    {
        $event = MeetingService::find((string) $params['id']);
        $user = $request->user();
        MeetingService::assertParticipant($event, (string) $user['id'], (string) $user['role']);

        $rows = Database::select(
            'SELECT e.seller_id, u.name, u.company,
                    AVG(e.total_score) AS average,
                    COUNT(*) AS evaluator_count,
                    AVG(e.industry_expertise) AS industry_expertise,
                    AVG(e.creativity) AS creativity,
                    AVG(e.team_confidence) AS team_confidence,
                    AVG(e.communication) AS communication,
                    AVG(e.case_studies) AS case_studies,
                    AVG(e.commercial_fit) AS commercial_fit
             FROM evaluations e
             JOIN users u ON u.id = e.seller_id
             WHERE e.event_id = :id
             GROUP BY e.seller_id, u.name, u.company
             ORDER BY average DESC',
            ['id' => $event['id']]
        );

        $isBuyer = AuthService::isBuyer((string) $user['role']);

        $items = [];
        foreach ($rows as $index => $row) {
            $entry = [
                'rank'       => $index + 1,
                'sellerId'   => $row['seller_id'],
                'name'       => $row['name'],
                'company'    => $row['company'],
                'average'    => round((float) $row['average'], 2),
                'evaluators' => (int) $row['evaluator_count'],
            ];

            // A seller sees only their own breakdown.
            if ($isBuyer || $row['seller_id'] === $user['id']) {
                $entry['breakdown'] = [
                    'industryExpertise' => round((float) $row['industry_expertise'], 2),
                    'creativity'        => round((float) $row['creativity'], 2),
                    'teamConfidence'    => round((float) $row['team_confidence'], 2),
                    'communication'     => round((float) $row['communication'], 2),
                    'caseStudies'       => round((float) $row['case_studies'], 2),
                    'commercialFit'     => round((float) $row['commercial_fit'], 2),
                ];
            }

            $items[] = $entry;
        }

        return Response::json($items);
    }

    /**
     * POST /events/{id}/decision
     * Publishing a winner cascades: winner accepted, everyone else declined,
     * opportunity closed, messaging unlocked.
     */
    public function decide(Request $request, array $params): Response
    {
        $event = MeetingService::find((string) $params['id']);
        $user = $request->user();

        if (!MeetingService::isModerator($event, (string) $user['id'], (string) $user['role'])) {
            throw HttpException::forbidden('Only the buyer can publish the decision.');
        }

        Validator::make($request, ['outcome' => 'required|in:won,hired,invest,backup,talent_pool,due_diligence,rejected,pass,no_investment']);

        $outcome = $request->string('outcome');
        $winnerId = $request->string('winnerId') ?: null;
        $backupIds = $request->array('backupIds');
        $isWin = in_array($outcome, ['won', 'hired', 'invest'], true);

        if ($isWin && $winnerId === null) {
            throw HttpException::badRequest('Name the winner.', 'WINNER_REQUIRED');
        }

        $decisionId = Id::make('dec');

        Database::transaction(function () use ($decisionId, $event, $user, $outcome, $winnerId, $backupIds, $request, $isWin): void {
            Database::upsert('decisions', [
                'id'             => $decisionId,
                'event_id'       => $event['id'],
                'opportunity_id' => $event['opportunity_id'],
                'winner_id'      => $winnerId,
                'outcome'        => $outcome,
                'backup_ids'     => Str::json($backupIds),
                'note'           => $request->string('note') ?: null,
                'created_by'     => $user['id'],
                'published_at'   => Str::dbDate(),
                'created_at'     => Str::dbDate(),
            ], ['winner_id', 'outcome', 'backup_ids', 'note', 'published_at']);

            Database::update('events', [
                'status'     => 'decision_made',
                'decision'   => $outcome,
                'updated_at' => Str::dbDate(),
            ], 'id = :id', ['id' => $event['id']]);

            if ($event['opportunity_id'] !== null) {
                $proposals = Database::select(
                    'SELECT * FROM proposals WHERE opportunity_id = :id',
                    ['id' => $event['opportunity_id']]
                );

                $proposalController = new ProposalController();

                foreach ($proposals as $proposal) {
                    $sellerId = (string) $proposal['seller_id'];

                    if ($isWin && $sellerId === $winnerId) {
                        $proposalController->transition($proposal, 'accepted', 'Selected after the live pitch.', $request);
                    } elseif (in_array($sellerId, $backupIds, true)) {
                        $proposalController->transition($proposal, 'shortlisted', 'Kept as a backup after the pitch.', $request);
                    } elseif ($isWin) {
                        if (!in_array((string) $proposal['status'], ['withdrawn', 'rejected', 'declined'], true)) {
                            $proposalController->transition($proposal, 'declined', 'Another seller was selected.', $request);
                        }
                    } elseif ($outcome === 'rejected' || $outcome === 'pass' || $outcome === 'no_investment') {
                        if (!in_array((string) $proposal['status'], ['withdrawn', 'rejected', 'declined'], true)) {
                            $proposalController->transition($proposal, 'rejected', 'Not selected after the pitch.', $request);
                        }
                    }
                }

                if ($isWin) {
                    $opportunity = Database::first('SELECT * FROM opportunities WHERE id = :id', ['id' => $event['opportunity_id']]);
                    if ($opportunity !== null) {
                        OpportunityController::setStatusFor($opportunity, 'Completed', 'Winner selected.', (string) $user['id']);
                    }
                }
            }
        });

        // Messaging opens for everyone who pitched, win or lose.
        ThreadService::unlockForEvent((string) $event['id']);

        ActivityLog::record((string) $user['id'], 'event', (string) $event['id'], 'decision:' . $outcome, ['winnerId' => $winnerId], $request);

        return Response::json([
            'decisionId' => $decisionId,
            'eventId'    => $event['id'],
            'outcome'    => $outcome,
            'winnerId'   => $winnerId,
            'backupIds'  => $backupIds,
        ]);
    }

    /** GET /events/{id}/decision */
    public function decision(Request $request, array $params): Response
    {
        $event = MeetingService::find((string) $params['id']);
        MeetingService::assertParticipant($event, (string) $request->userId(), (string) $request->role());

        $row = Database::first(
            'SELECT d.*, u.name AS winner_name, u.company AS winner_company
             FROM decisions d LEFT JOIN users u ON u.id = d.winner_id
             WHERE d.event_id = :id',
            ['id' => $event['id']]
        );

        if ($row === null) {
            return Response::json(null);
        }

        return Response::json([
            'id'            => $row['id'],
            'eventId'       => $row['event_id'],
            'opportunityId' => $row['opportunity_id'],
            'winnerId'      => $row['winner_id'],
            'winnerName'    => $row['winner_name'],
            'winnerCompany' => $row['winner_company'],
            'outcome'       => $row['outcome'],
            'backupIds'     => Str::fromJson($row['backup_ids'] ?? null, []),
            'note'          => $row['note'],
            'publishedAt'   => Str::toIso($row['published_at']),
        ]);
    }

    /** POST /events/{id}/followup */
    public function followUp(Request $request, array $params): Response
    {
        $event = MeetingService::find((string) $params['id']);

        if (!MeetingService::isModerator($event, (string) $request->userId(), (string) $request->role())) {
            throw HttpException::forbidden('Only the buyer can schedule a follow-up.');
        }

        $id = Id::make('fup');

        Database::insert('follow_ups', [
            'id'           => $id,
            'event_id'     => $event['id'],
            'seller_id'    => $request->string('sellerId') ?: null,
            'scheduled_at' => Str::dbDate($request->string('scheduledAt') ?: ''),
            'notes'        => $request->string('notes') ?: null,
            'created_by'   => (string) $request->userId(),
            'created_at'   => Str::dbDate(),
        ]);

        if ($sellerId = $request->string('sellerId')) {
            NotificationService::push(
                $sellerId,
                'meeting_requested',
                'Follow-up scheduled',
                sprintf('The buyer scheduled a follow-up after "%s".', $event['name']),
                '/events/' . $event['id'],
                'View details',
                'event',
                (string) $event['id'],
                true
            );
        }

        return Response::created(['id' => $id]);
    }

    /** POST /ratings */
    public function rate(Request $request): Response
    {
        Validator::make($request, [
            'toId'  => 'required',
            'score' => 'required|numeric|min:1|max:5',
        ]);

        $toId = $request->string('toId');
        $fromId = (string) $request->userId();

        if ($toId === $fromId) {
            throw HttpException::badRequest('You cannot rate yourself.');
        }

        $eventId = $request->string('eventId') ?: null;

        // You may only rate someone you actually met.
        if ($eventId !== null) {
            $shared = (int) Database::scalar(
                'SELECT COUNT(*) FROM event_participants a
                 JOIN event_participants b ON a.event_id = b.event_id
                 WHERE a.event_id = :event AND a.user_id = :from AND b.user_id = :to',
                ['event' => $eventId, 'from' => $fromId, 'to' => $toId]
            );
            if ($shared === 0) {
                throw HttpException::forbidden('You can only rate someone you pitched with.', 'NOT_A_COUNTERPARTY');
            }
        }

        Database::upsert('ratings', [
            'id'             => Id::make('rate'),
            'from_id'        => $fromId,
            'to_id'          => $toId,
            'event_id'       => $eventId,
            'opportunity_id' => $request->string('opportunityId') ?: null,
            'score'          => $request->float('score'),
            'comment'        => $request->string('comment') ?: null,
            'created_at'     => Str::dbDate(),
        ], ['score', 'comment']);

        // Keep the profile's cached rating in step.
        $aggregate = Database::first(
            'SELECT AVG(score) AS average, COUNT(*) AS total FROM ratings WHERE to_id = :to',
            ['to' => $toId]
        );

        Database::update('profiles', [
            'rating'     => round((float) $aggregate['average'], 2),
            'reviews'    => (int) $aggregate['total'],
            'updated_at' => Str::dbDate(),
        ], 'user_id = :id', ['id' => $toId]);

        return Response::created([
            'toId'    => $toId,
            'score'   => $request->float('score'),
            'average' => round((float) $aggregate['average'], 2),
            'reviews' => (int) $aggregate['total'],
        ]);
    }

    /** GET /ratings */
    public function ratings(Request $request): Response
    {
        $userId = $request->string('userId') ?: (string) $request->userId();

        $rows = Database::select(
            'SELECT r.*, u.name AS from_name, u.company AS from_company
             FROM ratings r JOIN users u ON u.id = r.from_id
             WHERE r.to_id = :to ORDER BY r.created_at DESC LIMIT 100',
            ['to' => $userId]
        );

        return Response::json(array_map(static fn (array $row): array => [
            'id'        => $row['id'],
            'fromId'    => $row['from_id'],
            'fromName'  => $row['from_name'],
            'company'   => $row['from_company'],
            'eventId'   => $row['event_id'],
            'score'     => (float) $row['score'],
            'comment'   => $row['comment'],
            'createdAt' => Str::toIso($row['created_at']),
        ], $rows));
    }

    /** POST /feedback */
    public function giveFeedback(Request $request): Response
    {
        Validator::make($request, [
            'sellerId' => 'required',
            'body'     => 'required|min:5',
        ]);

        if (!AuthService::isBuyer((string) $request->role())) {
            throw HttpException::forbidden('Only buyers can leave pitch feedback.', 'BUYER_ONLY');
        }

        $id = Id::make('fb');
        $sellerId = $request->string('sellerId');

        Database::insert('feedback', [
            'id'           => $id,
            'event_id'     => $request->string('eventId') ?: null,
            'seller_id'    => $sellerId,
            'buyer_id'     => (string) $request->userId(),
            'body'         => $request->string('body'),
            'strengths'    => $request->string('strengths') ?: null,
            'improvements' => $request->string('improvements') ?: null,
            'visibility'   => $request->string('visibility') ?: 'seller',
            'created_at'   => Str::dbDate(),
        ]);

        NotificationService::push(
            $sellerId,
            'feedback',
            'New pitch feedback',
            'The buyer left feedback on your pitch.',
            '/feedback',
            'Read feedback',
            'feedback',
            $id,
            true
        );

        return Response::created(['id' => $id]);
    }

    /** GET /feedback */
    public function feedback(Request $request): Response
    {
        $userId = (string) $request->userId();

        $rows = Database::select(
            'SELECT f.*, u.name AS buyer_name, u.company AS buyer_company, e.name AS event_name
             FROM feedback f
             JOIN users u ON u.id = f.buyer_id
             LEFT JOIN events e ON e.id = f.event_id
             WHERE f.seller_id = :seller OR f.buyer_id = :buyer
             ORDER BY f.created_at DESC LIMIT 100',
            ['seller' => $userId, 'buyer' => $userId]
        );

        return Response::json(array_map(static fn (array $row): array => [
            'id'           => $row['id'],
            'eventId'      => $row['event_id'],
            'eventName'    => $row['event_name'],
            'sellerId'     => $row['seller_id'],
            'buyerId'      => $row['buyer_id'],
            'buyerName'    => $row['buyer_name'],
            'buyerCompany' => $row['buyer_company'],
            'body'         => $row['body'],
            'strengths'    => $row['strengths'],
            'improvements' => $row['improvements'],
            'createdAt'    => Str::toIso($row['created_at']),
        ], $rows));
    }

    /** POST /investments */
    public function invest(Request $request): Response
    {
        if ((string) $request->role() !== 'investor' && !$request->isAdmin()) {
            throw HttpException::forbidden('Only investors can record an investment.', 'INVESTOR_ONLY');
        }

        Validator::make($request, [
            'startupId' => 'required',
            'status'    => 'in:Invest,Due Diligence,No Investment,Decision Pending,Investment Made',
        ]);

        $id = Id::make('inv');

        Database::insert('investments', [
            'id'             => $id,
            'event_id'       => $request->string('eventId') ?: null,
            'opportunity_id' => $request->string('opportunityId') ?: null,
            'investor_id'    => (string) $request->userId(),
            'startup_id'     => $request->string('startupId'),
            'amount_minor'   => (int) round($request->float('amount') * 100),
            'currency'       => $request->string('currency') ?: 'USD',
            'instrument'     => $request->string('instrument') ?: null,
            'terms'          => $request->string('terms') ?: null,
            'status'         => $request->string('status') ?: 'Decision Pending',
            'created_at'     => Str::dbDate(),
            'updated_at'     => Str::dbDate(),
        ]);

        NotificationService::push(
            $request->string('startupId'),
            'decision',
            'Investor decision recorded',
            sprintf('An investor recorded a decision: %s.', $request->string('status') ?: 'Decision Pending'),
            '/si/pipeline',
            'View pipeline',
            'investment',
            $id,
            true
        );

        return Response::created(['id' => $id]);
    }

    /** GET /investments */
    public function investments(Request $request): Response
    {
        $userId = (string) $request->userId();

        $rows = Database::select(
            'SELECT i.*, s.name AS startup_name, s.company AS startup_company,
                    inv.name AS investor_name, inv.company AS investor_firm
             FROM investments i
             JOIN users s ON s.id = i.startup_id
             JOIN users inv ON inv.id = i.investor_id
             WHERE i.investor_id = :investor OR i.startup_id = :startup
             ORDER BY i.created_at DESC',
            ['investor' => $userId, 'startup' => $userId]
        );

        return Response::json(array_map(static fn (array $row): array => [
            'id'            => $row['id'],
            'eventId'       => $row['event_id'],
            'investorId'    => $row['investor_id'],
            'investorName'  => $row['investor_name'],
            'investorFirm'  => $row['investor_firm'],
            'startupId'     => $row['startup_id'],
            'startupName'   => $row['startup_company'] ?: $row['startup_name'],
            'amount'        => (int) $row['amount_minor'] / 100,
            'currency'      => $row['currency'],
            'instrument'    => $row['instrument'],
            'terms'         => $row['terms'],
            'status'        => $row['status'],
            'createdAt'     => Str::toIso($row['created_at']),
        ], $rows));
    }
}
