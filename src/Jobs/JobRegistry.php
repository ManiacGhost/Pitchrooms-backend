<?php

declare(strict_types=1);

namespace PitchRooms\Jobs;

use RuntimeException;

/**
 * Handler name -> callable. Job payloads are plain arrays so a job row stays
 * readable in phpMyAdmin.
 */
final class JobRegistry
{
    /** @return callable(array): mixed */
    public static function resolve(string $handler): callable
    {
        $map = [
            'meeting.reminders'      => [MeetingJobs::class, 'queueReminders'],
            'meeting.reminder.send'  => [MeetingJobs::class, 'sendReminder'],
            'meeting.autostart'      => [MeetingJobs::class, 'autoStart'],
            'meeting.advance'        => [MeetingJobs::class, 'advanceStages'],
            'meeting.autoclose'      => [MeetingJobs::class, 'autoClose'],
            'billing.passExpiry'     => [BillingJobs::class, 'expirePasses'],
            'opportunity.deadlines'  => [OpportunityJobs::class, 'closeExpired'],
            'system.cleanup'         => [SystemJobs::class, 'cleanup'],
            'notify.push'            => [SystemJobs::class, 'pushNotification'],
        ];

        if (!isset($map[$handler])) {
            throw new RuntimeException('No job handler registered for: ' . $handler);
        }

        return $map[$handler];
    }

    public static function handlers(): array
    {
        return [
            'meeting.reminders', 'meeting.reminder.send', 'meeting.autostart',
            'meeting.advance', 'meeting.autoclose', 'billing.passExpiry',
            'opportunity.deadlines', 'system.cleanup', 'notify.push',
        ];
    }
}
