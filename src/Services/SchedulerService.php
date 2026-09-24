<?php

declare(strict_types=1);

namespace PitchRooms\Services;

use PitchRooms\Core\Database;
use PitchRooms\Core\Env;
use PitchRooms\Jobs\JobRegistry;
use PitchRooms\Support\Id;
use PitchRooms\Support\Logger;
use PitchRooms\Support\Str;
use Throwable;

/**
 * Cron, in code.
 *
 * Nothing is registered in the hosting panel. A tick can be driven three ways,
 * and all three call this same method:
 *
 *   1. inline  — after an API response is flushed (Kernel::terminate)
 *   2. http    — POST /internal/scheduler/tick with X-Scheduler-Key
 *   3. cli     — php bin/scheduler.php --daemon | --once
 *
 * Ticks are throttled by SCHEDULER_MIN_INTERVAL and guarded by a DB lock, so
 * concurrent web requests cannot run the same job twice.
 */
final class SchedulerService
{
    private const LOCK_NAME = 'global';
    private const LOCK_TTL = 120;

    /**
     * @return array{ran:int, skipped:bool, reason?:string, jobs:array}
     */
    public function tick(string $source = 'cli', bool $force = false): array
    {
        if (!Env::bool('SCHEDULER_ENABLED', true) && !$force) {
            return ['ran' => 0, 'skipped' => true, 'reason' => 'disabled', 'jobs' => []];
        }

        if (!$this->acquireLock($force)) {
            return ['ran' => 0, 'skipped' => true, 'reason' => 'locked-or-throttled', 'jobs' => []];
        }

        $results = [];

        try {
            $this->promoteRecurringTasks();
            $results = $this->runDueJobs();
        } catch (Throwable $e) {
            Logger::error('Scheduler tick failed', ['source' => $source, 'message' => $e->getMessage()]);
        } finally {
            $this->releaseLock();
        }

        return ['ran' => count($results), 'skipped' => false, 'source' => $source, 'jobs' => $results];
    }

    /**
     * Turn every due recurring task into a one-off job. `unique_key` stops a
     * second copy being queued while one is still pending.
     */
    private function promoteRecurringTasks(): void
    {
        $tasks = Database::select(
            'SELECT * FROM scheduled_tasks
             WHERE enabled = 1 AND (next_run_at IS NULL OR next_run_at <= UTC_TIMESTAMP())'
        );

        foreach ($tasks as $task) {
            $interval = max(15, (int) $task['interval_seconds']);
            $nextRun = time() + $interval;

            Database::update('scheduled_tasks', [
                'next_run_at' => Str::dbDate($nextRun),
            ], 'id = :id', ['id' => $task['id']]);

            self::queue(
                (string) $task['handler'],
                Str::fromJson($task['payload'] ?? null, []),
                0,
                'task:' . $task['id'] . ':' . gmdate('YmdHis')
            );
        }
    }

    /** @return array<int, array{id:string, handler:string, status:string, error?:string}> */
    private function runDueJobs(): array
    {
        $limit = max(1, Env::int('SCHEDULER_MAX_JOBS_PER_TICK', 25));
        $workerId = Id::make('worker');
        $results = [];

        for ($i = 0; $i < $limit; $i++) {
            $job = $this->claimNextJob($workerId);
            if ($job === null) {
                break;
            }

            $handler = (string) $job['handler'];
            $payload = Str::fromJson($job['payload'] ?? null, []);

            try {
                $callable = JobRegistry::resolve($handler);
                $summary = $callable($payload);

                Database::update('scheduled_jobs', [
                    'status'       => 'completed',
                    'completed_at' => Str::dbDate(),
                    'locked_at'    => null,
                    'locked_by'    => null,
                    'last_error'   => null,
                ], 'id = :id', ['id' => $job['id']]);

                $this->recordTaskResult($job, 'completed', null);
                $results[] = ['id' => $job['id'], 'handler' => $handler, 'status' => 'completed', 'summary' => $summary];
            } catch (Throwable $e) {
                $attempts = (int) $job['attempts'];
                $failed = $attempts >= (int) $job['max_attempts'];

                Database::update('scheduled_jobs', [
                    'status'     => $failed ? 'failed' : 'pending',
                    // exponential-ish backoff: 1m, 5m, 15m
                    'run_at'     => Str::dbDate(time() + min(900, 60 * (2 ** max(0, $attempts - 1)))),
                    'locked_at'  => null,
                    'locked_by'  => null,
                    'last_error' => Str::limit($e->getMessage(), 990, ''),
                ], 'id = :id', ['id' => $job['id']]);

                $this->recordTaskResult($job, 'failed', $e->getMessage());
                Logger::error('Job failed', ['handler' => $handler, 'message' => $e->getMessage()]);
                $results[] = ['id' => $job['id'], 'handler' => $handler, 'status' => 'failed', 'error' => $e->getMessage()];
            }
        }

        return $results;
    }

    /**
     * Claim one job atomically. The conditional UPDATE is the claim — two
     * concurrent workers cannot both win the same row.
     */
    private function claimNextJob(string $workerId): ?array
    {
        $candidate = Database::first(
            "SELECT id, handler, payload, attempts, max_attempts, unique_key
             FROM scheduled_jobs
             WHERE status = 'pending'
               AND run_at <= UTC_TIMESTAMP()
               AND (locked_at IS NULL OR locked_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL :ttl SECOND))
             ORDER BY run_at ASC
             LIMIT 1",
            ['ttl' => self::LOCK_TTL]
        );

        if ($candidate === null) {
            return null;
        }

        $claimed = Database::statement(
            "UPDATE scheduled_jobs
             SET status = 'running', locked_at = UTC_TIMESTAMP(), locked_by = :worker, attempts = attempts + 1
             WHERE id = :id AND status = 'pending'",
            ['worker' => $workerId, 'id' => $candidate['id']]
        );

        if ($claimed === 0) {
            return null; // someone else got it
        }

        $candidate['attempts'] = (int) $candidate['attempts'] + 1;

        return $candidate;
    }

    private function recordTaskResult(array $job, string $status, ?string $error): void
    {
        $uniqueKey = (string) ($job['unique_key'] ?? '');
        if (!str_starts_with($uniqueKey, 'task:')) {
            return;
        }

        $parts = explode(':', $uniqueKey);
        $taskId = $parts[1] ?? '';
        if ($taskId === '') {
            return;
        }

        Database::statement(
            'UPDATE scheduled_tasks
             SET last_run_at = UTC_TIMESTAMP(), last_status = :status, last_error = :error, runs = runs + 1
             WHERE id = :id',
            ['status' => $status, 'error' => $error === null ? null : Str::limit($error, 990, ''), 'id' => $taskId]
        );
    }

    /** Queue a one-off job. $delaySeconds schedules it into the future. */
    public static function queue(string $handler, array $payload = [], int $delaySeconds = 0, ?string $uniqueKey = null): ?string
    {
        $id = Id::make('job');

        try {
            Database::insert('scheduled_jobs', [
                'id'           => $id,
                'handler'      => $handler,
                'payload'      => Str::json($payload),
                'unique_key'   => $uniqueKey === null ? null : substr($uniqueKey, 0, 190),
                'run_at'       => Str::dbDate(time() + max(0, $delaySeconds)),
                'status'       => 'pending',
                'attempts'     => 0,
                'max_attempts' => 3,
                'locked_at'    => null,
                'locked_by'    => null,
                'last_error'   => null,
                'completed_at' => null,
                'created_at'   => Str::dbDate(),
            ]);
        } catch (Throwable $e) {
            // Duplicate unique_key simply means it is already queued.
            if (str_contains($e->getMessage(), '1062')) {
                return null;
            }
            throw $e;
        }

        return $id;
    }

    private function acquireLock(bool $force): bool
    {
        Database::statement(
            'INSERT IGNORE INTO scheduler_locks (name, ticks) VALUES (:name, 0)',
            ['name' => self::LOCK_NAME]
        );

        $minInterval = $force ? 0 : max(0, Env::int('SCHEDULER_MIN_INTERVAL', 60));

        // One statement does both the throttle check and the lock take, so
        // parallel PHP-FPM workers cannot both pass it.
        $taken = Database::statement(
            'UPDATE scheduler_locks
             SET locked_at = UTC_TIMESTAMP(), locked_by = :worker, last_tick_at = UTC_TIMESTAMP(), ticks = ticks + 1
             WHERE name = :name
               AND (locked_at IS NULL OR locked_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL :ttl SECOND))
               AND (last_tick_at IS NULL OR last_tick_at <= DATE_SUB(UTC_TIMESTAMP(), INTERVAL :interval SECOND))',
            [
                'worker'   => gethostname() . ':' . getmypid(),
                'name'     => self::LOCK_NAME,
                'ttl'      => self::LOCK_TTL,
                'interval' => $minInterval,
            ]
        );

        return $taken > 0;
    }

    private function releaseLock(): void
    {
        Database::statement(
            'UPDATE scheduler_locks SET locked_at = NULL, locked_by = NULL WHERE name = :name',
            ['name' => self::LOCK_NAME]
        );
    }

    public function status(): array
    {
        $lock = Database::first('SELECT * FROM scheduler_locks WHERE name = :name', ['name' => self::LOCK_NAME]);

        return [
            'enabled'      => Env::bool('SCHEDULER_ENABLED', true),
            'inline'       => Env::bool('SCHEDULER_INLINE', true),
            'minInterval'  => Env::int('SCHEDULER_MIN_INTERVAL', 60),
            'lastTickAt'   => Str::toIso($lock['last_tick_at'] ?? null),
            'ticks'        => (int) ($lock['ticks'] ?? 0),
            'locked'       => !empty($lock['locked_at']),
            'pendingJobs'  => (int) Database::scalar("SELECT COUNT(*) FROM scheduled_jobs WHERE status = 'pending'"),
            'runningJobs'  => (int) Database::scalar("SELECT COUNT(*) FROM scheduled_jobs WHERE status = 'running'"),
            'failedJobs'   => (int) Database::scalar("SELECT COUNT(*) FROM scheduled_jobs WHERE status = 'failed'"),
            'tasks'        => Database::select(
                'SELECT id, handler, description, interval_seconds, enabled, last_run_at, next_run_at, last_status, last_error, runs
                 FROM scheduled_tasks ORDER BY id'
            ),
        ];
    }
}
