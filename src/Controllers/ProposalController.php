<?php

declare(strict_types=1);

namespace PitchRooms\Controllers;

use PitchRooms\Core\Database;
use PitchRooms\Core\Env;
use PitchRooms\Core\HttpException;
use PitchRooms\Core\Request;
use PitchRooms\Core\Response;
use PitchRooms\Services\ActivityLog;
use PitchRooms\Services\AuthService;
use PitchRooms\Services\MatchService;
use PitchRooms\Services\NotificationService;
use PitchRooms\Support\Id;
use PitchRooms\Support\Str;
use PitchRooms\Support\Validator;

/**
 * Pitches (AB), applications (EE) and interests (SI).
 *
 * The rules the browser used to enforce all live here now: one proposal per
 * seller per opportunity, a deck/resume on file before pitching, and a hard
 * shortlist cap.
 */
final class ProposalController
{
    public const STATUSES = [
        'submitted', 'under_review', 'shortlisted', 'invited', 'interview_scheduled',
        'pitch_scheduled', 'completed', 'accepted', 'rejected', 'withdrawn', 'declined',
    ];

    /** GET /proposals */
    public function index(Request $request): Response
    {
        $where = [];
        $bindings = [];

        foreach ([
            'opportunityId' => 'p.opportunity_id = :opportunityId',
            'sellerId'      => 'p.seller_id = :sellerId',
            'buyerId'       => 'p.buyer_id = :buyerId',
            'status'        => 'p.status = :status',
            'vertical'      => 'p.vertical = :vertical',
        ] as $key => $clause) {
            $value = $request->string($key);
            if ($value !== '') {
                $where[] = $clause;
                $bindings[$key] = $value;
            }
        }

        // Without an explicit filter, you see your own side of the table.
        $userId = (string) $request->userId();
        if (!$request->isAdmin() && !$request->has('sellerId') && !$request->has('buyerId') && !$request->has('opportunityId')) {
            $where[] = '(p.seller_id = :me OR p.buyer_id = :me2)';
            $bindings += ['me' => $userId, 'me2' => $userId];
        }

        $whereSql = $where === [] ? '1=1' : implode(' AND ', $where);
        $page = $request->page();
        $perPage = $request->perPage(20);
        $offset = ($page - 1) * $perPage;

        $total = (int) Database::scalar("SELECT COUNT(*) FROM proposals p WHERE {$whereSql}", $bindings);

        $rows = Database::select(
            "SELECT p.*, o.title AS opportunity_title, o.company AS opportunity_company,
                    o.vertical AS opportunity_vertical, u.name AS seller_display_name
             FROM proposals p
             JOIN opportunities o ON o.id = p.opportunity_id
             JOIN users u ON u.id = p.seller_id
             WHERE {$whereSql}
             ORDER BY p.created_at DESC
             LIMIT {$perPage} OFFSET {$offset}",
            $bindings
        );

        $this->assertVisible($rows, $request);

        return Response::paginated(array_map([$this, 'present'], $rows), $total, $page, $perPage);
    }

    /** GET /proposals/{id} */
    public function show(Request $request, array $params): Response
    {
        $proposal = $this->find((string) $params['id']);
        $this->assertParty($proposal, $request);

        return Response::json($this->present($proposal, true));
    }

    /** POST /proposals — pitch / apply / express interest. */
    public function store(Request $request): Response
    {
        $user = $request->user();
        $role = (string) $user['role'];

        if (!AuthService::isSeller($role) && !$request->isAdmin()) {
            throw HttpException::forbidden('Only sellers can submit a proposal.', 'SELLER_ONLY');
        }

        Validator::make($request, ['opportunityId' => 'required']);

        $opportunity = OpportunityController::findOrFail($request->string('opportunityId'));

        if (in_array((string) $opportunity['status'], ['Draft', 'Closed', 'Completed'], true)) {
            throw HttpException::unprocessable(
                'This opportunity is no longer accepting proposals.',
                'OPPORTUNITY_CLOSED'
            );
        }

        if ($opportunity['deadline'] !== null && strtotime((string) $opportunity['deadline'] . ' UTC') < time()) {
            throw HttpException::unprocessable('The deadline for this opportunity has passed.', 'DEADLINE_PASSED');
        }

        $sellerId = (string) $user['id'];

        $existing = Database::first(
            'SELECT id FROM proposals WHERE opportunity_id = :opportunity AND seller_id = :seller',
            ['opportunity' => $opportunity['id'], 'seller' => $sellerId]
        );
        if ($existing !== null) {
            throw HttpException::conflict('You have already submitted a proposal for this opportunity.', 'ALREADY_APPLIED');
        }

        // A candidate needs a CV, an agency/startup a deck — the frontend
        // blocked this locally ("Upload your resume in Profile before applying").
        $resumeFileId = $request->string('resumeFileId') ?: $this->profileFile($sellerId, 'resumeFileId');
        $deckFileId = $request->string('deckFileId') ?: $this->profileFile($sellerId, 'deckFileId');

        if ($role === 'employee' && $resumeFileId === null) {
            throw HttpException::unprocessable(
                'Upload your resume before applying.',
                'RESUME_REQUIRED'
            );
        }

        $id = Id::make(match ((string) $opportunity['vertical']) { 'ee' => 'ee-app', 'si' => 'si-int', default => 'ab-pitch' });
        $now = Str::dbDate();

        Database::transaction(function () use ($id, $opportunity, $user, $sellerId, $request, $resumeFileId, $deckFileId, $now): void {
            Database::insert('proposals', [
                'id'              => $id,
                'opportunity_id'  => $opportunity['id'],
                'vertical'        => $opportunity['vertical'],
                'seller_id'       => $sellerId,
                'buyer_id'        => $opportunity['owner_id'],
                'seller_name'     => $user['name'],
                'company'         => $user['company'] ?: $user['name'],
                'cover_letter'    => $request->string('coverLetter') ?: null,
                'bid'             => $request->string('bid') ?: null,
                'timeline_text'   => $request->string('timeline') ?: null,
                'expected_salary' => $request->string('expectedSalary') ?: null,
                'notice_period'   => $request->string('noticePeriod') ?: null,
                'resume_file_id'  => $resumeFileId,
                'deck_file_id'    => $deckFileId,
                'note'            => $request->string('note') ?: null,
                'match_score'     => null,
                'status'          => 'submitted',
                'rejection_reason'=> null,
                'event_id'        => null,
                'created_at'      => $now,
                'updated_at'      => $now,
            ]);

            $this->addTimeline($id, 'submitted', 'Proposal submitted.', $sellerId);
        });

        // Score after insert so the buyer's ranking is immediately usable.
        $score = MatchService::score((string) $opportunity['id'], $sellerId);
        Database::update('proposals', ['match_score' => $score], 'id = :id', ['id' => $id]);

        NotificationService::push(
            (string) $opportunity['owner_id'],
            'pitch_submitted',
            'New proposal received',
            sprintf('%s submitted a proposal for "%s".', $user['company'] ?: $user['name'], $opportunity['title']),
            '/opportunities/' . $opportunity['id'],
            'Review proposal',
            'proposal',
            $id,
            true
        );

        ActivityLog::record($sellerId, 'proposal', $id, 'created', ['opportunityId' => $opportunity['id']], $request);

        return Response::created($this->present($this->find($id), true));
    }

    /** PATCH /proposals/{id} — editable until the buyer starts reviewing. */
    public function update(Request $request, array $params): Response
    {
        $proposal = $this->find((string) $params['id']);

        if ($proposal['seller_id'] !== $request->userId() && !$request->isAdmin()) {
            throw HttpException::forbidden('This proposal is not yours.');
        }
        if (!in_array((string) $proposal['status'], ['submitted', 'under_review'], true)) {
            throw HttpException::unprocessable(
                'This proposal can no longer be edited.',
                'PROPOSAL_LOCKED'
            );
        }

        $map = [
            'coverLetter'    => 'cover_letter',
            'bid'            => 'bid',
            'timeline'       => 'timeline_text',
            'expectedSalary' => 'expected_salary',
            'noticePeriod'   => 'notice_period',
            'resumeFileId'   => 'resume_file_id',
            'deckFileId'     => 'deck_file_id',
            'note'           => 'note',
        ];

        $changes = [];
        foreach ($map as $input => $column) {
            if ($request->has($input)) {
                $changes[$column] = $request->string($input) ?: null;
            }
        }

        if ($changes !== []) {
            $changes['updated_at'] = Str::dbDate();
            Database::update('proposals', $changes, 'id = :id', ['id' => $proposal['id']]);
        }

        return Response::json($this->present($this->find((string) $proposal['id']), true));
    }

    /** POST /proposals/{id}/status */
    public function setStatus(Request $request, array $params): Response
    {
        $proposal = $this->find((string) $params['id']);
        $status = $request->string('status');

        if (!in_array($status, self::STATUSES, true)) {
            throw HttpException::badRequest('Unknown status. Allowed: ' . implode(', ', self::STATUSES), 'INVALID_STATUS');
        }

        // Sellers may only withdraw; every other transition is the buyer's.
        $isBuyer = $proposal['buyer_id'] === $request->userId() || $request->isAdmin();
        if (!$isBuyer && !($proposal['seller_id'] === $request->userId() && $status === 'withdrawn')) {
            throw HttpException::forbidden('Only the buyer can change a proposal status.');
        }

        if ($status === 'shortlisted') {
            $this->assertShortlistCapacity((string) $proposal['opportunity_id'], (string) $proposal['id']);
        }

        $this->transition($proposal, $status, $request->string('note') ?: null, $request);

        return Response::json($this->present($this->find((string) $proposal['id'])));
    }

    /** POST /proposals/{id}/withdraw */
    public function withdraw(Request $request, array $params): Response
    {
        $proposal = $this->find((string) $params['id']);

        if ($proposal['seller_id'] !== $request->userId()) {
            throw HttpException::forbidden('This proposal is not yours.');
        }

        $final = ['accepted', 'rejected', 'withdrawn', 'declined'];
        if (in_array((string) $proposal['status'], $final, true)) {
            throw HttpException::unprocessable('This proposal can no longer be withdrawn.', 'ALREADY_FINAL');
        }
        if (in_array((string) $proposal['status'], ['interview_scheduled', 'pitch_scheduled'], true)) {
            throw HttpException::unprocessable(
                'Cancel your scheduled pitch before withdrawing.',
                'MEETING_SCHEDULED'
            );
        }

        $this->transition($proposal, 'withdrawn', $request->string('reason') ?: 'Withdrawn by seller.', $request);

        return Response::json($this->present($this->find((string) $proposal['id'])));
    }

    /** POST /proposals/{id}/reject */
    public function reject(Request $request, array $params): Response
    {
        $proposal = $this->find((string) $params['id']);

        if ($proposal['buyer_id'] !== $request->userId() && !$request->isAdmin()) {
            throw HttpException::forbidden('Only the buyer can reject a proposal.');
        }

        $reason = $request->string('reason') ?: 'Not selected.';
        Database::update('proposals', ['rejection_reason' => $reason], 'id = :id', ['id' => $proposal['id']]);
        $this->transition($proposal, 'rejected', $reason, $request);

        return Response::json($this->present($this->find((string) $proposal['id'])));
    }

    /** POST /opportunities/{id}/review — bulk submitted to under_review. */
    public function bulkReview(Request $request, array $params): Response
    {
        $opportunity = OpportunityController::findOrFail((string) $params['id']);
        $this->assertBuyer($opportunity, $request);

        $pending = Database::select(
            "SELECT * FROM proposals WHERE opportunity_id = :id AND status = 'submitted'",
            ['id' => $opportunity['id']]
        );

        foreach ($pending as $proposal) {
            $this->transition($proposal, 'under_review', 'Buyer started reviewing proposals.', $request);
        }

        if ($pending !== [] && $opportunity['status'] === 'Submitted') {
            OpportunityController::setStatusFor($opportunity, 'Under Review', 'Proposals under review.', (string) $request->userId());
        }

        return Response::json(['reviewed' => count($pending)]);
    }

    /** POST /opportunities/{id}/shortlist/auto — rank, then shortlist the top N. */
    public function autoShortlist(Request $request, array $params): Response
    {
        $opportunity = OpportunityController::findOrFail((string) $params['id']);
        $this->assertBuyer($opportunity, $request);

        $ranked = MatchService::rank((string) $opportunity['id']);
        if ($ranked === []) {
            throw HttpException::unprocessable('No proposals have been submitted yet.', 'NO_PROPOSALS');
        }

        $limit = min(
            MatchService::shortlistTarget((string) $opportunity['preferred_sellers'], Env::int('SHORTLIST_LIMIT', 5)),
            Env::int('SHORTLIST_LIMIT', 5)
        );

        $keep = array_slice($ranked, 0, $limit);
        $keepIds = array_column($keep, 'id');

        Database::transaction(function () use ($opportunity, $ranked, $keep, $keepIds, $request): void {
            Database::statement('DELETE FROM shortlists WHERE opportunity_id = :id AND locked_at IS NULL', ['id' => $opportunity['id']]);

            foreach ($keep as $index => $proposal) {
                Database::upsert('shortlists', [
                    'opportunity_id' => $opportunity['id'],
                    'seller_id'      => $proposal['seller_id'],
                    'proposal_id'    => $proposal['id'],
                    'position'       => $index + 1,
                    'match_score'    => (int) $proposal['match_score'],
                    'locked_at'      => null,
                    'created_by'     => $request->userId(),
                    'created_at'     => Str::dbDate(),
                ], ['proposal_id', 'position', 'match_score']);

                if ($proposal['status'] !== 'shortlisted') {
                    $this->transition($proposal, 'shortlisted', 'Shortlisted on match score.', $request);
                }
            }

            // Anyone previously shortlisted but now outside the cut goes back
            // to the review pool rather than being rejected outright.
            foreach ($ranked as $proposal) {
                if (!in_array($proposal['id'], $keepIds, true) && $proposal['status'] === 'shortlisted') {
                    $this->transition($proposal, 'under_review', 'Moved back to the review pool.', $request);
                }
            }

            OpportunityController::setStatusFor($opportunity, 'Shortlisting', 'Top ' . count($keep) . ' shortlisted.', (string) $request->userId());
        });

        return Response::json([
            'shortlisted' => count($keep),
            'limit'       => $limit,
            'sellers'     => array_column($keep, 'seller_id'),
        ]);
    }

    /** POST /opportunities/{id}/shortlist/{sellerId} */
    public function shortlistSeller(Request $request, array $params): Response
    {
        $opportunity = OpportunityController::findOrFail((string) $params['id']);
        $this->assertBuyer($opportunity, $request);

        $sellerId = (string) $params['sellerId'];

        $proposal = Database::first(
            'SELECT * FROM proposals WHERE opportunity_id = :opportunity AND seller_id = :seller',
            ['opportunity' => $opportunity['id'], 'seller' => $sellerId]
        );
        if ($proposal === null) {
            throw HttpException::notFound('That seller has not pitched this opportunity.');
        }

        $this->assertShortlistCapacity((string) $opportunity['id'], (string) $proposal['id']);

        $position = (int) Database::scalar(
            'SELECT COALESCE(MAX(position), 0) + 1 FROM shortlists WHERE opportunity_id = :id',
            ['id' => $opportunity['id']]
        );

        Database::upsert('shortlists', [
            'opportunity_id' => $opportunity['id'],
            'seller_id'      => $sellerId,
            'proposal_id'    => $proposal['id'],
            'position'       => $position,
            'match_score'    => $proposal['match_score'],
            'locked_at'      => null,
            'created_by'     => $request->userId(),
            'created_at'     => Str::dbDate(),
        ], ['proposal_id', 'position', 'match_score']);

        $this->transition($proposal, 'shortlisted', 'Shortlisted by the buyer.', $request);

        return Response::json(['shortlisted' => true, 'sellerId' => $sellerId, 'position' => $position]);
    }

    /** DELETE /opportunities/{id}/shortlist/{sellerId} */
    public function removeFromShortlist(Request $request, array $params): Response
    {
        $opportunity = OpportunityController::findOrFail((string) $params['id']);
        $this->assertBuyer($opportunity, $request);

        $row = Database::first(
            'SELECT * FROM shortlists WHERE opportunity_id = :opportunity AND seller_id = :seller',
            ['opportunity' => $opportunity['id'], 'seller' => (string) $params['sellerId']]
        );
        if ($row === null) {
            throw HttpException::notFound('That seller is not on the shortlist.');
        }
        if ($row['locked_at'] !== null) {
            throw HttpException::unprocessable(
                'The shortlist is confirmed — invitations have already gone out.',
                'SHORTLIST_LOCKED'
            );
        }

        Database::statement(
            'DELETE FROM shortlists WHERE opportunity_id = :opportunity AND seller_id = :seller',
            ['opportunity' => $opportunity['id'], 'seller' => (string) $params['sellerId']]
        );

        $proposal = Database::first(
            'SELECT * FROM proposals WHERE opportunity_id = :opportunity AND seller_id = :seller',
            ['opportunity' => $opportunity['id'], 'seller' => (string) $params['sellerId']]
        );
        if ($proposal !== null && $proposal['status'] === 'shortlisted') {
            $this->transition($proposal, 'under_review', 'Removed from the shortlist.', $request);
        }

        return Response::json(['removed' => true]);
    }

    /** POST /opportunities/{id}/shortlist/confirm — locks it and invites. */
    public function confirmShortlist(Request $request, array $params): Response
    {
        $opportunity = OpportunityController::findOrFail((string) $params['id']);
        $this->assertBuyer($opportunity, $request);

        $rows = Database::select(
            'SELECT * FROM shortlists WHERE opportunity_id = :id ORDER BY position ASC',
            ['id' => $opportunity['id']]
        );
        if ($rows === []) {
            throw HttpException::unprocessable('Shortlist at least one seller first.', 'EMPTY_SHORTLIST');
        }

        Database::transaction(function () use ($rows, $opportunity, $request): void {
            Database::update('shortlists', ['locked_at' => Str::dbDate()], 'opportunity_id = :id', ['id' => $opportunity['id']]);

            foreach ($rows as $row) {
                $invitationId = Id::make('inv');

                Database::statement(
                    'INSERT INTO invitations (id, opportunity_id, event_id, seller_id, buyer_id, proposal_id,
                                              status, rsvp_status, selected_position, message, created_at, updated_at)
                     VALUES (:id, :opportunity, NULL, :seller, :buyer, :proposal, :status, :rsvp, :position, :message, :created, :updated)
                     ON DUPLICATE KEY UPDATE status = VALUES(status), selected_position = VALUES(selected_position), updated_at = VALUES(updated_at)',
                    [
                        'id'          => $invitationId,
                        'opportunity' => $opportunity['id'],
                        'seller'      => $row['seller_id'],
                        'buyer'       => $opportunity['owner_id'],
                        'proposal'    => $row['proposal_id'],
                        'status'      => 'Sent',
                        'rsvp'        => 'Pending',
                        'position'    => $row['position'],
                        'message'     => 'You are shortlisted for the live pitch room.',
                        'created'     => Str::dbDate(),
                        'updated'     => Str::dbDate(),
                    ]
                );

                $proposal = Database::first('SELECT * FROM proposals WHERE id = :id', ['id' => $row['proposal_id']]);
                if ($proposal !== null) {
                    $this->transition($proposal, 'invited', 'Invited to the live pitch room.', $request);
                }
            }

            OpportunityController::setStatusFor($opportunity, 'Invitations Sent', 'Shortlist confirmed and invitations sent.', (string) $request->userId());
        });

        return Response::json(['confirmed' => true, 'invited' => count($rows)]);
    }

    /** GET /opportunities/{id}/shortlist */
    public function shortlist(Request $request, array $params): Response
    {
        $opportunity = OpportunityController::findOrFail((string) $params['id']);

        $rows = Database::select(
            'SELECT s.*, u.name, u.company, p.status AS proposal_status, p.bid
             FROM shortlists s
             JOIN users u ON u.id = s.seller_id
             LEFT JOIN proposals p ON p.id = s.proposal_id
             WHERE s.opportunity_id = :id
             ORDER BY s.position ASC',
            ['id' => $opportunity['id']]
        );

        return Response::json(array_map(static fn (array $row): array => [
            'sellerId'       => $row['seller_id'],
            'name'           => $row['name'],
            'company'        => $row['company'],
            'proposalId'     => $row['proposal_id'],
            'proposalStatus' => $row['proposal_status'],
            'bid'            => $row['bid'],
            'position'       => (int) $row['position'],
            'matchScore'     => (int) $row['match_score'],
            'locked'         => $row['locked_at'] !== null,
        ], $rows));
    }

    // ---------------------------------------------------------------- helpers

    public static function findOrFail(string $id): array
    {
        $row = Database::first('SELECT * FROM proposals WHERE id = :id', ['id' => $id]);
        if ($row === null) {
            throw HttpException::notFound('Proposal not found.');
        }
        return $row;
    }

    private function find(string $id): array
    {
        return self::findOrFail($id);
    }

    private function assertParty(array $proposal, Request $request): void
    {
        if ($request->isAdmin()) {
            return;
        }
        if ($proposal['seller_id'] !== $request->userId() && $proposal['buyer_id'] !== $request->userId()) {
            throw HttpException::forbidden('This proposal is not yours.');
        }
    }

    private function assertVisible(array $rows, Request $request): void
    {
        if ($request->isAdmin()) {
            return;
        }
        $userId = $request->userId();
        foreach ($rows as $row) {
            if ($row['seller_id'] !== $userId && $row['buyer_id'] !== $userId) {
                throw HttpException::forbidden('You can only list your own proposals.');
            }
        }
    }

    private function assertBuyer(array $opportunity, Request $request): void
    {
        if ($request->isAdmin()) {
            return;
        }
        if ($opportunity['owner_id'] !== $request->userId()) {
            throw HttpException::forbidden('Only the opportunity owner can do that.');
        }
    }

    /** The hard cap the frontend advertised but could not enforce. */
    private function assertShortlistCapacity(string $opportunityId, string $proposalId): void
    {
        $limit = Env::int('SHORTLIST_LIMIT', 5);

        $count = (int) Database::scalar(
            'SELECT COUNT(*) FROM shortlists WHERE opportunity_id = :id AND proposal_id <> :proposal',
            ['id' => $opportunityId, 'proposal' => $proposalId]
        );

        if ($count >= $limit) {
            throw HttpException::unprocessable(
                sprintf('Only %d sellers can be shortlisted for one opportunity.', $limit),
                'SHORTLIST_LIMIT_REACHED'
            );
        }
    }

    private function profileFile(string $userId, string $key): ?string
    {
        $payload = Str::fromJson(
            (string) (Database::scalar('SELECT payload FROM profiles WHERE user_id = :id', ['id' => $userId]) ?? ''),
            []
        );

        return is_array($payload) && !empty($payload[$key]) ? (string) $payload[$key] : null;
    }

    private function addTimeline(string $proposalId, string $stage, string $note, ?string $actorId): void
    {
        Database::insert('proposal_timeline', [
            'proposal_id' => $proposalId,
            'stage'       => $stage,
            'note'        => $note,
            'actor_id'    => $actorId,
            'at'          => Str::dbDate(),
        ]);
    }

    /** Status change + timeline + notification, in one place. */
    public function transition(array $proposal, string $status, ?string $note, Request $request): void
    {
        if ($proposal['status'] === $status) {
            return;
        }

        Database::update('proposals', [
            'status'     => $status,
            'updated_at' => Str::dbDate(),
        ], 'id = :id', ['id' => $proposal['id']]);

        $this->addTimeline((string) $proposal['id'], $status, $note ?? '', (string) $request->userId());

        $opportunity = Database::first(
            'SELECT title, company FROM opportunities WHERE id = :id',
            ['id' => $proposal['opportunity_id']]
        );
        $title = $opportunity['title'] ?? 'your proposal';

        [$heading, $body] = match ($status) {
            'under_review'        => ['Proposal under review', sprintf('Your proposal for "%s" is being reviewed.', $title)],
            'shortlisted'         => ['You have been shortlisted', sprintf('You are shortlisted for the live pitch room on "%s".', $title)],
            'invited'             => ['Pitch room invitation', sprintf('You are invited to pitch for "%s". Confirm your slot.', $title)],
            'interview_scheduled',
            'pitch_scheduled'     => ['Pitch scheduled', sprintf('Your pitch for "%s" is scheduled.', $title)],
            'accepted'            => ['You won the pitch', sprintf('%s selected you for "%s".', $opportunity['company'] ?? 'The buyer', $title)],
            'rejected'            => ['Proposal not selected', sprintf('Your proposal for "%s" was not selected.', $title)],
            'declined'            => ['Opportunity filled', sprintf('"%s" was awarded to another seller.', $title)],
            'withdrawn'           => ['Proposal withdrawn', sprintf('You withdrew from "%s".', $title)],
            default               => ['Proposal updated', sprintf('Your proposal for "%s" is now %s.', $title, $status)],
        };

        if ($status !== 'withdrawn') {
            NotificationService::push(
                (string) $proposal['seller_id'],
                'application',
                $heading,
                $body,
                '/proposals/' . $proposal['id'],
                'View proposal',
                'proposal',
                (string) $proposal['id'],
                in_array($status, ['shortlisted', 'invited', 'accepted', 'rejected'], true)
            );
        } else {
            NotificationService::push(
                (string) $proposal['buyer_id'],
                'application',
                'Proposal withdrawn',
                sprintf('%s withdrew from "%s".', $proposal['seller_name'], $title),
                '/opportunities/' . $proposal['opportunity_id'],
                'View opportunity',
                'proposal',
                (string) $proposal['id']
            );
        }

        ActivityLog::record((string) $request->userId(), 'proposal', (string) $proposal['id'], 'status:' . $status, ['note' => $note], $request);
    }

    public function present(array $row, bool $detailed = false): array
    {
        $data = [
            'id'              => $row['id'],
            'opportunityId'   => $row['opportunity_id'],
            'opportunityTitle'=> $row['opportunity_title'] ?? null,
            'vertical'        => $row['vertical'],
            'sellerId'        => $row['seller_id'],
            'agencyId'        => $row['seller_id'],
            'employeeId'      => $row['seller_id'],
            'buyerId'         => $row['buyer_id'],
            'sellerName'      => $row['seller_name'],
            'company'         => $row['company'],
            'coverLetter'     => $row['cover_letter'],
            'bid'             => $row['bid'],
            'timeline'        => $row['timeline_text'],
            'expectedSalary'  => $row['expected_salary'],
            'noticePeriod'    => $row['notice_period'],
            'note'            => $row['note'],
            'matchScore'      => $row['match_score'] === null ? null : (int) $row['match_score'],
            'status'          => $row['status'],
            'rejectionReason' => $row['rejection_reason'],
            'eventId'         => $row['event_id'],
            'resume'          => $row['resume_file_id'] ? Env::url('/files/' . $row['resume_file_id']) : null,
            'deck'            => $row['deck_file_id'] ? Env::url('/files/' . $row['deck_file_id']) : null,
            'createdAt'       => Str::toIso($row['created_at']),
            'updatedAt'       => Str::toIso($row['updated_at']),
        ];

        if ($detailed) {
            $data['timelineEvents'] = array_map(static fn (array $event): array => [
                'stage'   => $event['stage'],
                'note'    => $event['note'],
                'actorId' => $event['actor_id'],
                'at'      => Str::toIso($event['at']),
            ], Database::select(
                'SELECT * FROM proposal_timeline WHERE proposal_id = :id ORDER BY at ASC',
                ['id' => $row['id']]
            ));
        }

        return $data;
    }
}
