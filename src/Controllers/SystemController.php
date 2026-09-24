<?php

declare(strict_types=1);

namespace PitchRooms\Controllers;

use PitchRooms\Core\Database;
use PitchRooms\Core\Env;
use PitchRooms\Core\HttpException;
use PitchRooms\Core\Request;
use PitchRooms\Core\Response;
use PitchRooms\Jobs\JobRegistry;
use PitchRooms\Services\SchedulerService;
use PitchRooms\Support\Str;
use Throwable;

/**
 * Health, reference data, dashboards, and the HTTP entry point for the
 * in-code scheduler.
 */
final class SystemController
{
    /** GET /health */
    public function health(Request $request): Response
    {
        $database = 'down';
        $error = null;

        try {
            Database::scalar('SELECT 1');
            $database = 'up';
        } catch (Throwable $e) {
            $error = Env::bool('APP_DEBUG') ? $e->getMessage() : 'unavailable';
        }

        // Config mistakes that only bite later — a wrong Jitsi domain, mail
        // still on the log driver in production — are better seen here.
        $warnings = \PitchRooms\Services\MeetingService::jitsiConfigWarnings();
        $warnings = array_merge($warnings, \PitchRooms\Support\Mailer::configWarnings());

        return Response::json([
            'ok'        => $database === 'up',
            'service'   => 'pitchrooms-api',
            'env'       => Env::string('APP_ENV', 'local'),
            'appDns'    => Env::appDns(),
            'frontend'  => Env::frontendDns(),
            'database'  => $database,
            'error'     => $error,
            'meetings'  => [
                'domain'  => Env::string('JITSI_DOMAIN', 'meet.jit.si'),
                'secured' => Env::string('JITSI_APP_ID', '') !== ''
                    && \PitchRooms\Services\MeetingService::jitsiPrivateKey() !== null,
            ],
            'mail'      => [
                'driver' => Env::string('MAIL_DRIVER', 'log'),
                'from'   => Env::string('MAIL_FROM', ''),
            ],
            'warnings'  => $warnings,
            'php'       => PHP_VERSION,
            'timestamp' => Str::iso(),
        ], $database === 'up' ? 200 : 503);
    }

    /** GET /meta/taxonomies — was hardcoded in the frontend bundle. */
    public function taxonomies(Request $request): Response
    {
        $stored = Str::fromJson(
            (string) (Database::scalar("SELECT value FROM settings WHERE `key` = 'taxonomies'") ?? ''),
            null
        );

        if (is_array($stored) && $stored !== []) {
            return Response::json($stored);
        }

        return Response::json([
            'categories' => ['Technology', 'Marketing', 'Creative', 'Development', 'Design', 'Media', 'Research'],
            'subcategories' => [
                'Technology'  => ['Product Engineering', 'AI / ML', 'Platform'],
                'Marketing'   => ['Performance Marketing', 'Brand Marketing', 'Content Marketing', 'SEO'],
                'Creative'    => ['Brand Identity', 'Creative Production', 'Campaign Creative'],
                'Development' => ['Web Development', 'Mobile Development', 'Product Engineering'],
                'Design'      => ['UX Research', 'UI/UX Design', 'Product Design'],
                'Media'       => ['Media Buying', 'Programmatic'],
                'Research'    => ['Market Research', 'User Research'],
            ],
            'industries' => ['Real Estate', 'Technology', 'Healthcare', 'Retail', 'BFSI', 'Education', 'Hospitality', 'Consumer'],
            'countries'  => ['United States', 'India', 'United Kingdom', 'Canada', 'Australia', 'Germany', 'France',
                             'United Arab Emirates', 'Singapore', 'Japan', 'Brazil', 'South Africa', 'Other'],
            'sectors'    => ['SaaS', 'Fintech', 'Healthtech', 'Consumer', 'Marketplace', 'Deeptech', 'Climate', 'Edtech'],
            'stages'     => ['Pre-seed', 'Seed', 'Series A', 'Series B', 'Growth'],
            'investorTypes' => ['Angel', 'VC firm', 'Family office', 'Corporate VC', 'Syndicate'],
            'workTypes'  => ['Remote', 'Hybrid', 'On-site', 'Live Pitch Room'],
            'experience' => ['Entry', 'Mid-level', 'Senior', 'Lead'],
            'sellerCounts' => ['Top 3', 'Top 5', 'Top 7'],
        ]);
    }

    /** GET /meta/statuses */
    public function statuses(Request $request): Response
    {
        return Response::json([
            'opportunity' => OpportunityController::STATUSES,
            'proposal'    => ProposalController::STATUSES,
            'event'       => EventController::STATUSES,
            'evaluationCriteria' => [
                ['key' => 'industryExpertise', 'label' => 'Industry Expertise'],
                ['key' => 'creativity',        'label' => 'Creativity'],
                ['key' => 'teamConfidence',    'label' => 'Team Confidence'],
                ['key' => 'communication',     'label' => 'Communication'],
                ['key' => 'caseStudies',       'label' => 'Case Studies'],
                ['key' => 'commercialFit',     'label' => 'Commercial Fit'],
            ],
            'decisionOutcomes' => [
                'ab' => ['won', 'backup', 'rejected'],
                'ee' => ['hired', 'backup', 'talent_pool', 'rejected'],
                'si' => ['invest', 'due_diligence', 'pass'],
            ],
            'shortlistLimit' => Env::int('SHORTLIST_LIMIT', 5),
        ]);
    }

    /**
     * GET /dashboard
     * One payload per role, so a dashboard is one request instead of six.
     */
    public function dashboard(Request $request): Response
    {
        $user = $request->user();
        $userId = (string) $user['id'];
        $role = (string) $user['role'];
        $isBuyer = \PitchRooms\Services\AuthService::isBuyer($role);

        $upcoming = Database::select(
            "SELECT e.*, o.title AS opportunity_title FROM events e
             LEFT JOIN opportunities o ON o.id = e.opportunity_id
             WHERE e.status IN ('scheduled','waiting_room','live','requested')
               AND (e.buyer_id = :me1 OR e.seller_id = :me2
                    OR EXISTS (SELECT 1 FROM event_participants ep WHERE ep.event_id = e.id AND ep.user_id = :me3))
             ORDER BY e.start_at ASC LIMIT 5",
            ['me1' => $userId, 'me2' => $userId, 'me3' => $userId]
        );

        $notifications = Database::select(
            'SELECT * FROM notifications WHERE user_id = :user ORDER BY created_at DESC LIMIT 5',
            ['user' => $userId]
        );

        if ($isBuyer) {
            $stats = Database::first(
                "SELECT
                   (SELECT COUNT(*) FROM opportunities WHERE owner_id = :u1 AND deleted_at IS NULL) AS opportunities,
                   (SELECT COUNT(*) FROM opportunities WHERE owner_id = :u2 AND deleted_at IS NULL
                     AND status NOT IN ('Completed','Closed','Draft')) AS active_opportunities,
                   (SELECT COUNT(*) FROM proposals WHERE buyer_id = :u3) AS proposals,
                   (SELECT COUNT(*) FROM proposals WHERE buyer_id = :u4 AND status = 'submitted') AS new_proposals,
                   (SELECT COUNT(*) FROM events WHERE buyer_id = :u5) AS events",
                ['u1' => $userId, 'u2' => $userId, 'u3' => $userId, 'u4' => $userId, 'u5' => $userId]
            );

            $recent = Database::select(
                'SELECT p.*, o.title AS opportunity_title, u.name AS seller_display_name
                 FROM proposals p
                 JOIN opportunities o ON o.id = p.opportunity_id
                 JOIN users u ON u.id = p.seller_id
                 WHERE p.buyer_id = :user ORDER BY p.created_at DESC LIMIT 5',
                ['user' => $userId]
            );
        } else {
            $stats = Database::first(
                "SELECT
                   (SELECT COUNT(*) FROM proposals WHERE seller_id = :u1) AS proposals,
                   (SELECT COUNT(*) FROM proposals WHERE seller_id = :u2 AND status = 'shortlisted') AS shortlisted,
                   (SELECT COUNT(*) FROM proposals WHERE seller_id = :u3 AND status = 'accepted') AS won,
                   (SELECT COUNT(*) FROM event_participants WHERE user_id = :u4) AS pitch_rooms,
                   (SELECT COUNT(*) FROM opportunities WHERE deleted_at IS NULL
                     AND status IN ('Approved','Sourcing','Submitted')) AS open_opportunities",
                ['u1' => $userId, 'u2' => $userId, 'u3' => $userId, 'u4' => $userId]
            );

            $recent = Database::select(
                'SELECT p.*, o.title AS opportunity_title
                 FROM proposals p JOIN opportunities o ON o.id = p.opportunity_id
                 WHERE p.seller_id = :user ORDER BY p.created_at DESC LIMIT 5',
                ['user' => $userId]
            );
        }

        $proposalController = new ProposalController();

        return Response::json([
            'role'          => $role,
            'stats'         => array_map('intval', $stats ?? []),
            'upcomingEvents'=> array_map(static fn (array $row): array =>
                \PitchRooms\Services\MeetingService::present($row, ['opportunityTitle' => $row['opportunity_title']]), $upcoming),
            'recentProposals' => array_map(static fn (array $row): array => $proposalController->present($row), $recent),
            'notifications' => array_map([\PitchRooms\Services\NotificationService::class, 'present'], $notifications),
            'unreadCount'   => \PitchRooms\Services\NotificationService::unreadCount($userId),
            'pass'          => \PitchRooms\Services\BillingService::presentPass(
                \PitchRooms\Services\BillingService::activePass($userId)
            ),
            'trustScore'    => \PitchRooms\Services\ProfileService::trustScore($userId)['score'],
        ]);
    }

    /** GET /search — one box across opportunities, people and events. */
    public function search(Request $request): Response
    {
        $q = $request->string('q');
        if (mb_strlen($q) < 2) {
            throw HttpException::badRequest('Type at least two characters.', 'QUERY_TOO_SHORT');
        }

        $like = '%' . $q . '%';
        $type = $request->string('type');
        $results = [];

        if ($type === '' || $type === 'opportunities') {
            $results['opportunities'] = Database::select(
                "SELECT id, title, company, status, vertical FROM opportunities
                 WHERE deleted_at IS NULL AND status <> 'Draft'
                   AND (title LIKE :q OR description LIKE :q2 OR company LIKE :q3)
                 LIMIT 10",
                ['q' => $like, 'q2' => $like, 'q3' => $like]
            );
        }

        if ($type === '' || $type === 'people') {
            $results['people'] = Database::select(
                "SELECT id, name, company, role FROM users
                 WHERE deleted_at IS NULL AND account_status = 'Active'
                   AND (name LIKE :q OR company LIKE :q2)
                 LIMIT 10",
                ['q' => $like, 'q2' => $like]
            );
        }

        if ($type === '' || $type === 'events') {
            $userId = (string) $request->userId();
            $results['events'] = Database::select(
                'SELECT e.id, e.name, e.status, e.start_at FROM events e
                 WHERE e.name LIKE :q
                   AND (e.buyer_id = :me1 OR e.seller_id = :me2
                        OR EXISTS (SELECT 1 FROM event_participants ep WHERE ep.event_id = e.id AND ep.user_id = :me3))
                 LIMIT 10',
                ['q' => $like, 'me1' => $userId, 'me2' => $userId, 'me3' => $userId]
            );
        }

        return Response::json($results);
    }

    // -------------------------------------------------------------- scheduler

    /**
     * POST /internal/scheduler/tick
     * The HTTP trigger for the in-code cron. Guarded by X-Scheduler-Key so an
     * uptime monitor (or anything else) can drive it without a hosting-panel
     * cron entry. The same work also runs inline after normal API responses.
     */
    public function schedulerTick(Request $request): Response
    {
        $this->assertSchedulerKey($request);

        $result = (new SchedulerService())->tick('http', $request->bool('force'));

        return Response::json($result);
    }

    /** GET /internal/scheduler/status */
    public function schedulerStatus(Request $request): Response
    {
        $this->assertSchedulerKey($request, allowAdmin: true);

        return Response::json((new SchedulerService())->status() + [
            'handlers' => JobRegistry::handlers(),
        ]);
    }

    /** POST /internal/scheduler/jobs — queue a job by hand. */
    public function queueJob(Request $request): Response
    {
        $this->assertSchedulerKey($request, allowAdmin: true);

        $handler = $request->string('handler');
        if (!in_array($handler, JobRegistry::handlers(), true)) {
            throw HttpException::badRequest(
                'Unknown handler. Known: ' . implode(', ', JobRegistry::handlers()),
                'UNKNOWN_HANDLER'
            );
        }

        $id = SchedulerService::queue(
            $handler,
            $request->array('payload'),
            $request->int('delaySeconds', 0),
            $request->string('uniqueKey') ?: null
        );

        return Response::created(['jobId' => $id, 'handler' => $handler]);
    }

    private function assertSchedulerKey(Request $request, bool $allowAdmin = false): void
    {
        if ($allowAdmin && $request->isAdmin()) {
            return;
        }

        $expected = Env::string('SCHEDULER_KEY', '');
        $provided = (string) $request->header('x-scheduler-key', $request->string('key'));

        if ($expected === '' || !hash_equals($expected, $provided)) {
            throw HttpException::forbidden('Invalid scheduler key.', 'BAD_SCHEDULER_KEY');
        }
    }
}
