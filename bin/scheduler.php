<?php

declare(strict_types=1);

/**
 * Scheduler CLI — the third way to drive the in-code cron.
 *
 *   php bin/scheduler.php            run one tick and exit
 *   php bin/scheduler.php --daemon   keep running, tick every --interval
 *   php bin/scheduler.php --force    ignore the throttle for this tick
 *   php bin/scheduler.php --status   print queue and task state
 *
 * Nothing here is registered with the hosting panel. Use this when you want a
 * dedicated worker; otherwise inline ticks (after API responses) and the
 * /internal/scheduler/tick endpoint already keep jobs moving.
 */

use PitchRooms\Core\Autoloader;
use PitchRooms\Core\Env;
use PitchRooms\Core\Paths;
use PitchRooms\Services\SchedulerService;

$basePath = dirname(__DIR__);
require $basePath . '/src/Core/Autoloader.php';
Autoloader::register($basePath . '/src');

Env::load($basePath . '/.env');
Paths::setBase($basePath);
date_default_timezone_set(Env::string('APP_TIMEZONE', 'UTC'));

$args = array_slice($argv, 1);
$daemon = in_array('--daemon', $args, true);
$force = in_array('--force', $args, true);
$status = in_array('--status', $args, true);

$interval = 30;
foreach ($args as $arg) {
    if (str_starts_with($arg, '--interval=')) {
        $interval = max(5, (int) substr($arg, 11));
    }
}

$scheduler = new SchedulerService();

function line(string $message): void
{
    echo '[' . gmdate('H:i:s') . '] ' . $message . PHP_EOL;
}

if ($status) {
    $state = $scheduler->status();
    line(sprintf(
        'enabled=%s inline=%s pending=%d running=%d failed=%d lastTick=%s',
        $state['enabled'] ? 'yes' : 'no',
        $state['inline'] ? 'yes' : 'no',
        $state['pendingJobs'],
        $state['runningJobs'],
        $state['failedJobs'],
        $state['lastTickAt'] ?? 'never'
    ));

    foreach ($state['tasks'] as $task) {
        line(sprintf(
            '  %-26s every %5ds  last=%s %s',
            $task['handler'],
            (int) $task['interval_seconds'],
            $task['last_run_at'] ?? 'never',
            $task['last_status'] ?? ''
        ));
    }
    exit(0);
}

$run = static function () use ($scheduler, $force): void {
    $result = $scheduler->tick('cli', $force);

    if ($result['skipped'] ?? false) {
        line('skipped (' . ($result['reason'] ?? 'unknown') . ')');
        return;
    }

    line(sprintf('ran %d job(s)', $result['ran']));
    foreach ($result['jobs'] as $job) {
        line(sprintf(
            '  %-24s %s%s',
            $job['handler'],
            $job['status'],
            isset($job['error']) ? ' — ' . $job['error'] : ''
        ));
    }
};

if (!$daemon) {
    $run();
    exit(0);
}

line(sprintf('Scheduler daemon started (tick every %ds). Ctrl-C to stop.', $interval));

if (function_exists('pcntl_signal')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGINT, static function (): void {
        line('Stopping.');
        exit(0);
    });
    pcntl_signal(SIGTERM, static function (): void {
        line('Stopping.');
        exit(0);
    });
}

while (true) {
    try {
        $run();
    } catch (Throwable $e) {
        line('✗ ' . $e->getMessage());
    }

    sleep($interval);
}
