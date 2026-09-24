<?php

declare(strict_types=1);

namespace PitchRooms\Jobs;

use PitchRooms\Core\Database;
use PitchRooms\Services\MeetingService;
use PitchRooms\Services\NotificationService;
use PitchRooms\Services\SchedulerService;
use PitchRooms\Support\Str;

/**
 * Everything the live room needs to happen without anyone clicking:
 * reminders, opening the room, moving the clock on, closing it.
 * All driven by the in-code scheduler.
 */
final class MeetingJobs
{
    /** Queue 24h and 1h reminders for events that have not had them yet. */
    public static function queueReminders(array $payload = []): array
    {
        $queued = 0;

        foreach ([24 * 3600 => '24h', 3600 => '1h'] as $lead => $label) {
            $windowStart = Str::dbDate(time() + $lead - 300);
            $windowEnd = Str::dbDate(time() + $lead + 300);

            $events = Database::select(
                "SELECT id, name, start_at FROM events
                 WHERE status IN ('scheduled','waiting_room')
                   AND start_at BETWEEN :start AND :end",
                ['start' => $windowStart, 'end' => $windowEnd]
            );

            foreach ($events as $event) {
                $id = SchedulerService::queue(
                    'meeting.reminder.send',
                    ['eventId' => $event['id'], 'label' => $label],
                    0,
                    'reminder:' . $event['id'] . ':' . $label
                );
                if ($id !== null) {
                    $queued++;
                }
            }
        }

        return ['queued' => $queued];
    }

    public static function sendReminder(array $payload): array
    {
        $eventId = (string) ($payload['eventId'] ?? '');
        $label = (string) ($payload['label'] ?? 'soon');

        $event = Database::first('SELECT * FROM events WHERE id = :id', ['id' => $eventId]);
        if ($event === null) {
            return ['skipped' => 'event-missing'];
        }
        if (in_array($event['status'], ['cancelled', 'completed'], true)) {
            return ['skipped' => 'event-' . $event['status']];
        }

        $recipients = array_column(
            Database::select('SELECT user_id FROM event_participants WHERE event_id = :id', ['id' => $eventId]),
            'user_id'
        );
        $recipients[] = (string) $event['buyer_id'];
        if ($event['seller_id']) {
            $recipients[] = (string) $event['seller_id'];
        }

        $when = Str::toIso($event['start_at']);
        $sent = 0;

        foreach (array_unique(array_filter($recipients)) as $userId) {
            NotificationService::push(
                $userId,
                'meeting_reminder',
                $label === '24h' ? 'Pitch room tomorrow' : 'Pitch room starts in an hour',
                sprintf('"%s" starts at %s. Run your camera and mic check before joining.', $event['name'], $when),
                '/events/' . $eventId,
                'Open pitch room',
                'event',
                $eventId,
                true
            );
            $sent++;
        }

        return ['sent' => $sent, 'label' => $label];
    }

    /** Open the waiting room 10 minutes out, go live at start time. */
    public static function autoStart(array $payload = []): array
    {
        $toWaiting = Database::statement(
            "UPDATE events
             SET status = 'waiting_room', updated_at = UTC_TIMESTAMP()
             WHERE status = 'scheduled'
               AND start_at IS NOT NULL
               AND start_at <= DATE_ADD(UTC_TIMESTAMP(), INTERVAL 10 MINUTE)
               AND start_at > UTC_TIMESTAMP()"
        );

        $due = Database::select(
            "SELECT id FROM events
             WHERE status IN ('scheduled','waiting_room')
               AND start_at IS NOT NULL
               AND start_at <= UTC_TIMESTAMP()"
        );

        foreach ($due as $event) {
            MeetingService::start((string) $event['id']);
        }

        return ['waitingRoom' => $toWaiting, 'started' => count($due)];
    }

    /**
     * Move any live room whose current stage has expired. This is what keeps
     * every participant's countdown in sync even if the moderator's tab is
     * closed.
     */
    public static function advanceStages(array $payload = []): array
    {
        $expired = Database::select(
            "SELECT s.event_id FROM meeting_state s
             JOIN events e ON e.id = s.event_id
             WHERE s.stage NOT IN ('completed','waiting')
               AND s.paused_at IS NULL
               AND s.stage_ends_at IS NOT NULL
               AND s.stage_ends_at <= UTC_TIMESTAMP()
               AND e.status = 'live'"
        );

        foreach ($expired as $row) {
            MeetingService::advance((string) $row['event_id']);
        }

        return ['advanced' => count($expired)];
    }

    /** Close rooms that ran past their end time (or 3h past start). */
    public static function autoClose(array $payload = []): array
    {
        $stale = Database::select(
            "SELECT id FROM events
             WHERE status IN ('live','waiting_room')
               AND (
                    (end_at IS NOT NULL AND end_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 15 MINUTE))
                 OR (start_at IS NOT NULL AND start_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 3 HOUR))
               )"
        );

        foreach ($stale as $event) {
            MeetingService::complete((string) $event['id']);
        }

        return ['closed' => count($stale)];
    }
}
