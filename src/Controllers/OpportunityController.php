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
 * Briefs (AB), jobs (EE) and raises (SI) are one resource with a `vertical`
 * discriminator, so listing, shortlisting and scheduling work identically
 * across the three panels.
 */
final class OpportunityController
{
    public const STATUSES = [
        'Draft', 'Submitted', 'Under Review', 'Approved', 'Sourcing', 'Shortlisting',
        'Invitations Sent', 'Event Scheduled', 'Live', 'Evaluation', 'Completed', 'Closed',
    ];

    /** GET /opportunities */
    public function index(Request $request): Response
    {
        $where = ['o.deleted_at IS NULL'];
        $bindings = [];

        $filters = [
            'vertical'    => 'o.vertical = :vertical',
            'ownerId'     => 'o.owner_id = :ownerId',
            'brandId'     => 'o.owner_id = :brandId',
            'employerId'  => 'o.owner_id = :employerId',
            'startupId'   => 'o.owner_id = :startupId',
            'category'    => 'o.category = :category',
            'subcategory' => 'o.subcategory = :subcategory',
            'industry'    => 'o.industry = :industry',
            'status'      => 'o.status = :status',
            'workType'    => 'o.work_type = :workType',
            'experience'  => 'o.experience = :experience',
            'stage'       => 'o.stage = :stage',
        ];

        foreach ($filters as $key => $clause) {
            $value = $request->string($key);
            if ($value !== '') {
                $where[] = $clause;
                $bindings[$key] = $value;
            }
        }

        if ($request->has('featured')) {
            $where[] = 'o.featured = :featured';
            $bindings['featured'] = $request->bool('featured') ? 1 : 0;
        }

        if ($q = $request->string('q')) {
            $where[] = '(o.title LIKE :q OR o.description LIKE :q2 OR o.company LIKE :q3)';
            $like = '%' . $q . '%';
            $bindings += ['q' => $like, 'q2' => $like, 'q3' => $like];
        }

        if ($skills = $request->array('skills')) {
            $placeholders = [];
            foreach (array_values($skills) as $index => $skill) {
                $key = 'skill' . $index;
                $placeholders[] = ':' . $key;
                $bindings[$key] = $skill;
            }
            $where[] = 'EXISTS (SELECT 1 FROM opportunity_skills os WHERE os.opportunity_id = o.id AND os.skill IN (' . implode(',', $placeholders) . '))';
        }

        // Sellers browsing the marketplace never see drafts or deleted briefs.
        $role = (string) ($request->role() ?? '');
        if (AuthService::isSeller($role) || $role === '') {
            $where[] = "o.status NOT IN ('Draft')";
            $where[] = "o.visibility = 'open'";
        }

        if ($request->bool('mine') && $request->userId()) {
            $where[] = 'o.owner_id = :mine';
            $bindings['mine'] = $request->userId();
        }

        $whereSql = implode(' AND ', $where);
        $page = $request->page();
        $perPage = $request->perPage(20);
        $offset = ($page - 1) * $perPage;

        $total = (int) Database::scalar("SELECT COUNT(*) FROM opportunities o WHERE {$whereSql}", $bindings);

        $sort = match ($request->string('sort')) {
            'deadline' => 'o.deadline ASC',
            'budget'   => 'o.budget DESC',
            'oldest'   => 'o.created_at ASC',
            default    => 'o.featured DESC, o.created_at DESC',
        };

        $rows = Database::select(
            "SELECT o.* FROM opportunities o WHERE {$whereSql} ORDER BY {$sort} LIMIT {$perPage} OFFSET {$offset}",
            $bindings
        );

        $items = array_map(fn (array $row): array => $this->present($row, $request), $rows);

        return Response::paginated($items, $total, $page, $perPage);
    }

    /** GET /opportunities/{id} */
    public function show(Request $request, array $params): Response
    {
        $opportunity = $this->find((string) $params['id']);

        return Response::json($this->present($opportunity, $request, true));
    }

    /** POST /opportunities */
    public function store(Request $request): Response
    {
        $user = $request->user();
        $role = (string) $user['role'];

        if (AuthService::isSeller($role)) {
            throw HttpException::forbidden('Only buyers can post an opportunity.', 'BUYER_ONLY');
        }

        Validator::make($request, [
            'title'       => 'required|min:4|max:255',
            'description' => 'required|min:20',
            'deadline'    => 'date',
            'openings'    => 'integer|min:1|max:999',
        ]);

        $vertical = $request->string('vertical') ?: AuthService::verticalForRole($role);
        $id = Id::make(match ($vertical) { 'ee' => 'ee-job', 'si' => 'si-raise', default => 'ab-job' });
        $now = Str::dbDate();

        $status = $request->string('status') === 'draft' ? 'Draft' : 'Submitted';

        Database::transaction(function () use ($id, $vertical, $user, $request, $status, $now): void {
            Database::insert('opportunities', [
                'id'                    => $id,
                'vertical'              => $vertical,
                'owner_id'              => $user['id'],
                'owner_name'            => $user['name'],
                'company'               => $user['company'] ?: $user['name'],
                'title'                 => $request->string('title'),
                'category'              => $request->string('category') ?: null,
                'subcategory'           => $request->string('subcategory') ?: null,
                'industry'              => $request->string('industry') ?: null,
                'description'           => $request->string('description'),
                'requirements'          => $request->string('requirements') ?: null,
                'benefits'              => $request->string('benefits') ?: null,
                'budget'                => $request->string('budget') ?: $request->string('salary') ?: null,
                'budget_type'           => $request->string('budgetType') ?: null,
                'duration'              => $request->string('duration') ?: null,
                'location'              => $request->string('location') ?: 'Remote',
                'work_type'             => $request->string('workType') ?: 'Remote',
                'experience'            => $request->string('experience') ?: null,
                'department'            => $request->string('department') ?: null,
                'job_type'              => $request->string('jobType') ?: null,
                'openings'              => max(1, $request->int('openings', 1)),
                'deadline'              => Str::dbDate($request->string('deadline') ?: ''),
                'expected_start_date'   => Str::dbDate($request->string('expectedStartDate') ?: ''),
                'preferred_sellers'     => $request->string('preferredSellers') ?: 'Top 5',
                'presentation_duration' => max(15, $request->int('presentationDuration', 30)),
                'qa_duration'           => max(15, $request->int('qaDuration', 30)),
                'visibility'            => $request->string('visibility') ?: 'open',
                'featured'              => $request->bool('featured') ? 1 : 0,
                'cover_file_id'         => $request->string('coverFileId') ?: null,
                'cover_url'             => $request->string('cover') ?: null,
                'round'                 => $request->string('round') ?: null,
                'amount'                => $request->string('amount') ?: null,
                'valuation'             => $request->string('valuation') ?: null,
                'stage'                 => $request->string('stage') ?: null,
                'use_of_funds'          => $request->string('useOfFunds') ?: null,
                'deck_file_id'          => $request->string('deckFileId') ?: null,
                'status'                => $status,
                'event_id'              => null,
                'created_at'            => $now,
                'updated_at'            => $now,
                'deleted_at'            => null,
            ]);

            $this->syncSkills($id, Str::listOf($request->input('skills', [])));
            $this->syncFiles($id, $request->array('attachmentFileIds'));
            $this->addTimeline($id, $status, 'Opportunity created.', (string) $request->userId());
        });

        ActivityLog::record((string) $request->userId(), 'opportunity', $id, 'created', ['vertical' => $vertical], $request);

        return Response::created($this->present($this->find($id), $request, true));
    }

    /** PUT/PATCH /opportunities/{id} */
    public function update(Request $request, array $params): Response
    {
        $opportunity = $this->find((string) $params['id']);
        $this->assertOwner($opportunity, $request);

        // Once sellers have pitched, the brief they pitched against is locked.
        $locked = ['Event Scheduled', 'Live', 'Evaluation', 'Completed', 'Closed'];
        if (in_array((string) $opportunity['status'], $locked, true) && !$request->isAdmin()) {
            throw HttpException::unprocessable(
                'This opportunity is locked once its pitch event is scheduled.',
                'OPPORTUNITY_LOCKED'
            );
        }

        $map = [
            'title' => 'title', 'category' => 'category', 'subcategory' => 'subcategory',
            'industry' => 'industry', 'description' => 'description', 'requirements' => 'requirements',
            'benefits' => 'benefits', 'budget' => 'budget', 'budgetType' => 'budget_type',
            'duration' => 'duration', 'location' => 'location', 'workType' => 'work_type',
            'experience' => 'experience', 'department' => 'department', 'jobType' => 'job_type',
            'preferredSellers' => 'preferred_sellers', 'visibility' => 'visibility',
            'round' => 'round', 'amount' => 'amount', 'valuation' => 'valuation',
            'stage' => 'stage', 'useOfFunds' => 'use_of_funds', 'deckFileId' => 'deck_file_id',
            'coverFileId' => 'cover_file_id',
        ];

        $changes = [];
        foreach ($map as $input => $column) {
            if ($request->has($input)) {
                $changes[$column] = $request->string($input) ?: null;
            }
        }
        if ($request->has('openings')) {
            $changes['openings'] = max(1, $request->int('openings', 1));
        }
        if ($request->has('featured')) {
            $changes['featured'] = $request->bool('featured') ? 1 : 0;
        }
        if ($request->has('deadline')) {
            $changes['deadline'] = Str::dbDate($request->string('deadline'));
        }
        if ($request->has('presentationDuration')) {
            $changes['presentation_duration'] = max(15, $request->int('presentationDuration', 30));
        }
        if ($request->has('qaDuration')) {
            $changes['qa_duration'] = max(15, $request->int('qaDuration', 30));
        }

        if ($changes !== []) {
            $changes['updated_at'] = Str::dbDate();
            Database::update('opportunities', $changes, 'id = :id', ['id' => $opportunity['id']]);
        }

        if ($request->has('skills')) {
            $this->syncSkills((string) $opportunity['id'], Str::listOf($request->input('skills', [])));
        }
        if ($request->has('attachmentFileIds')) {
            $this->syncFiles((string) $opportunity['id'], $request->array('attachmentFileIds'));
        }

        ActivityLog::record((string) $request->userId(), 'opportunity', (string) $opportunity['id'], 'updated', array_keys($changes), $request);

        return Response::json($this->present($this->find((string) $opportunity['id']), $request, true));
    }

    /** DELETE /opportunities/{id} — soft delete. */
    public function destroy(Request $request, array $params): Response
    {
        $opportunity = $this->find((string) $params['id']);
        $this->assertOwner($opportunity, $request);

        $proposals = (int) Database::scalar(
            'SELECT COUNT(*) FROM proposals WHERE opportunity_id = :id',
            ['id' => $opportunity['id']]
        );
        if ($proposals > 0 && !$request->isAdmin()) {
            throw HttpException::unprocessable(
                'Sellers have already pitched — close this opportunity instead of deleting it.',
                'HAS_PROPOSALS'
            );
        }

        Database::update('opportunities', [
            'deleted_at' => Str::dbDate(),
            'updated_at' => Str::dbDate(),
        ], 'id = :id', ['id' => $opportunity['id']]);

        ActivityLog::record((string) $request->userId(), 'opportunity', (string) $opportunity['id'], 'deleted', [], $request);

        return Response::json(['deleted' => true]);
    }

    /** POST /opportunities/{id}/submit — Draft to Submitted. */
    public function submit(Request $request, array $params): Response
    {
        $opportunity = $this->find((string) $params['id']);
        $this->assertOwner($opportunity, $request);

        if ($opportunity['status'] !== 'Draft') {
            throw HttpException::unprocessable('Only a draft can be submitted.', 'NOT_A_DRAFT');
        }

        $this->setStatus($opportunity, 'Submitted', 'Submitted for review.', $request);

        return Response::json($this->present($this->find((string) $opportunity['id']), $request));
    }

    /** POST /opportunities/{id}/status */
    public function setStatusEndpoint(Request $request, array $params): Response
    {
        $opportunity = $this->find((string) $params['id']);
        $status = $request->string('status');

        if (!in_array($status, self::STATUSES, true)) {
            throw HttpException::badRequest(
                'Unknown status. Allowed: ' . implode(', ', self::STATUSES),
                'INVALID_STATUS'
            );
        }

        // Approval-type transitions are the admin's call, not the owner's.
        $adminOnly = ['Approved', 'Under Review', 'Sourcing'];
        if (in_array($status, $adminOnly, true) && !$request->isAdmin()) {
            throw HttpException::forbidden('Only an admin can move an opportunity to ' . $status . '.');
        }
        if (!$request->isAdmin()) {
            $this->assertOwner($opportunity, $request);
        }

        $this->setStatus($opportunity, $status, $request->string('note') ?: null, $request);

        return Response::json($this->present($this->find((string) $opportunity['id']), $request));
    }

    /** POST /opportunities/{id}/close */
    public function close(Request $request, array $params): Response
    {
        $opportunity = $this->find((string) $params['id']);
        $this->assertOwner($opportunity, $request);
        $this->setStatus($opportunity, 'Closed', $request->string('note') ?: 'Closed by owner.', $request);

        return Response::json($this->present($this->find((string) $opportunity['id']), $request));
    }

    /** GET /opportunities/{id}/ranking — server-side match ranking. */
    public function ranking(Request $request, array $params): Response
    {
        $opportunity = $this->find((string) $params['id']);
        $this->assertOwner($opportunity, $request);

        $ranked = MatchService::rank((string) $opportunity['id']);
        $target = MatchService::shortlistTarget(
            (string) $opportunity['preferred_sellers'],
            Env::int('SHORTLIST_LIMIT', 5)
        );

        $items = [];
        foreach ($ranked as $index => $proposal) {
            $items[] = [
                'rank'          => $index + 1,
                'proposalId'    => $proposal['id'],
                'sellerId'      => $proposal['seller_id'],
                'sellerName'    => $proposal['user_name'],
                'company'       => $proposal['user_company'],
                'matchScore'    => (int) $proposal['match_score'],
                'status'        => $proposal['status'],
                'bid'           => $proposal['bid'],
                'rating'        => (float) ($proposal['rating'] ?? 0),
                'trustScore'    => (int) ($proposal['trust_score'] ?? 0),
                'inShortlistCut'=> $index < $target,
            ];
        }

        return Response::json([
            'opportunityId'  => $opportunity['id'],
            'shortlistTarget'=> $target,
            'ranking'        => $items,
        ]);
    }

    /** GET /opportunities/{id}/timeline */
    public function timeline(Request $request, array $params): Response
    {
        $opportunity = $this->find((string) $params['id']);

        $rows = Database::select(
            'SELECT * FROM opportunity_timeline WHERE opportunity_id = :id ORDER BY at ASC',
            ['id' => $opportunity['id']]
        );

        return Response::json(array_map(static fn (array $row): array => [
            'stage'   => $row['stage'],
            'note'    => $row['note'],
            'actorId' => $row['actor_id'],
            'at'      => Str::toIso($row['at']),
        ], $rows));
    }

    // ---------------------------------------------------------------- helpers

    public static function findOrFail(string $id): array
    {
        $row = Database::first(
            'SELECT * FROM opportunities WHERE id = :id AND deleted_at IS NULL',
            ['id' => $id]
        );

        if ($row === null) {
            throw HttpException::notFound('Opportunity not found.');
        }

        return $row;
    }

    private function find(string $id): array
    {
        return self::findOrFail($id);
    }

    private function assertOwner(array $opportunity, Request $request): void
    {
        if ($request->isAdmin()) {
            return;
        }
        if ($opportunity['owner_id'] !== $request->userId()) {
            throw HttpException::forbidden('This opportunity belongs to another account.');
        }
    }

    public static function setStatusFor(array $opportunity, string $status, ?string $note, ?string $actorId): void
    {
        Database::update('opportunities', [
            'status'     => $status,
            'updated_at' => Str::dbDate(),
        ], 'id = :id', ['id' => $opportunity['id']]);

        Database::insert('opportunity_timeline', [
            'opportunity_id' => $opportunity['id'],
            'stage'          => $status,
            'note'           => $note,
            'actor_id'       => $actorId,
            'at'             => Str::dbDate(),
        ]);
    }

    private function setStatus(array $opportunity, string $status, ?string $note, Request $request): void
    {
        self::setStatusFor($opportunity, $status, $note, (string) $request->userId());

        ActivityLog::record((string) $request->userId(), 'opportunity', (string) $opportunity['id'], 'status:' . $status, [], $request);

        if ($opportunity['owner_id'] !== $request->userId()) {
            NotificationService::push(
                (string) $opportunity['owner_id'],
                'opportunity',
                'Opportunity status updated',
                sprintf('"%s" is now %s.', $opportunity['title'], $status),
                '/opportunities/' . $opportunity['id'],
                'View',
                'opportunity',
                (string) $opportunity['id']
            );
        }
    }

    private function addTimeline(string $opportunityId, string $stage, string $note, ?string $actorId): void
    {
        Database::insert('opportunity_timeline', [
            'opportunity_id' => $opportunityId,
            'stage'          => $stage,
            'note'           => $note,
            'actor_id'       => $actorId,
            'at'             => Str::dbDate(),
        ]);
    }

    private function syncSkills(string $opportunityId, array $skills): void
    {
        Database::statement('DELETE FROM opportunity_skills WHERE opportunity_id = :id', ['id' => $opportunityId]);

        foreach (array_slice($skills, 0, 30) as $skill) {
            Database::statement(
                'INSERT IGNORE INTO opportunity_skills (opportunity_id, skill) VALUES (:id, :skill)',
                ['id' => $opportunityId, 'skill' => mb_substr((string) $skill, 0, 80)]
            );
        }
    }

    private function syncFiles(string $opportunityId, array $fileIds): void
    {
        Database::statement('DELETE FROM opportunity_files WHERE opportunity_id = :id', ['id' => $opportunityId]);

        foreach (array_slice($fileIds, 0, 20) as $fileId) {
            Database::statement(
                'INSERT IGNORE INTO opportunity_files (opportunity_id, file_id, kind) VALUES (:id, :file, :kind)',
                ['id' => $opportunityId, 'file' => (string) $fileId, 'kind' => 'attachment']
            );
        }
    }

    public function present(array $row, Request $request, bool $detailed = false): array
    {
        $id = (string) $row['id'];

        $data = [
            'id'                    => $id,
            'vertical'              => $row['vertical'],
            'ownerId'               => $row['owner_id'],
            'ownerName'             => $row['owner_name'],
            'brandId'               => $row['owner_id'],
            'employerId'            => $row['owner_id'],
            'company'               => $row['company'],
            'title'                 => $row['title'],
            'eventName'             => $row['title'],
            'category'              => $row['category'],
            'subcategory'           => $row['subcategory'],
            'industry'              => $row['industry'],
            'description'           => $row['description'],
            'requirements'          => $row['requirements'],
            'benefits'              => $row['benefits'],
            'budget'                => $row['budget'],
            'salary'                => $row['budget'],
            'budgetType'            => $row['budget_type'],
            'duration'              => $row['duration'],
            'location'              => $row['location'],
            'workType'              => $row['work_type'],
            'experience'            => $row['experience'],
            'department'            => $row['department'],
            'jobType'               => $row['job_type'],
            'openings'              => (int) $row['openings'],
            'deadline'              => Str::toIso($row['deadline']),
            'expectedStartDate'     => Str::toIso($row['expected_start_date']),
            'preferredSellers'      => $row['preferred_sellers'],
            'presentationDuration'  => (int) $row['presentation_duration'],
            'qaDuration'            => (int) $row['qa_duration'],
            'visibility'            => $row['visibility'],
            'featured'              => (bool) $row['featured'],
            'cover'                 => $row['cover_file_id'] ? Env::url('/files/' . $row['cover_file_id']) : $row['cover_url'],
            'round'                 => $row['round'],
            'amount'                => $row['amount'],
            'valuation'             => $row['valuation'],
            'stage'                 => $row['stage'],
            'useOfFunds'            => $row['use_of_funds'],
            'deck'                  => $row['deck_file_id'] ? Env::url('/files/' . $row['deck_file_id']) : null,
            'status'                => $row['status'],
            'eventId'               => $row['event_id'],
            'skills'                => array_column(
                Database::select('SELECT skill FROM opportunity_skills WHERE opportunity_id = :id', ['id' => $id]),
                'skill'
            ),
            'proposalCount'         => (int) Database::scalar(
                'SELECT COUNT(*) FROM proposals WHERE opportunity_id = :id',
                ['id' => $id]
            ),
            'shortlistCount'        => (int) Database::scalar(
                'SELECT COUNT(*) FROM shortlists WHERE opportunity_id = :id',
                ['id' => $id]
            ),
            'createdAt'             => Str::toIso($row['created_at']),
            'updatedAt'             => Str::toIso($row['updated_at']),
        ];

        // Has the caller already pitched? Drives the apply button's state.
        $userId = $request->userId();
        if ($userId !== null) {
            $own = Database::first(
                'SELECT id, status FROM proposals WHERE opportunity_id = :id AND seller_id = :user',
                ['id' => $id, 'user' => $userId]
            );
            $data['myProposal'] = $own === null ? null : ['id' => $own['id'], 'status' => $own['status']];
            $data['isOwner'] = $row['owner_id'] === $userId;
        }

        if ($detailed) {
            $data['attachments'] = array_map(static fn (array $file): array => [
                'fileId' => $file['file_id'],
                'kind'   => $file['kind'],
                'url'    => Env::url('/files/' . $file['file_id']),
            ], Database::select('SELECT * FROM opportunity_files WHERE opportunity_id = :id', ['id' => $id]));
        }

        return $data;
    }
}
