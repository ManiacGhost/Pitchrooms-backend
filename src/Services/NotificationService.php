<?php

declare(strict_types=1);

namespace PitchRooms\Services;

use PitchRooms\Core\Database;
use PitchRooms\Core\Env;
use PitchRooms\Support\Id;
use PitchRooms\Support\Mailer;
use PitchRooms\Support\Str;

/**
 * Every notification the three stores used to push client-side is emitted
 * here instead, so a notification exists even when the recipient is offline.
 *
 * `link` is a frontend path (e.g. /ab/meetings/123). It is stored relative and
 * expanded with FRONTEND_DNS for email, so moving the frontend host is a
 * one-variable change.
 */
final class NotificationService
{
    public static function push(
        string $userId,
        string $type,
        string $title,
        string $body = '',
        ?string $link = null,
        ?string $actionLabel = null,
        ?string $entityType = null,
        ?string $entityId = null,
        bool $email = false
    ): string {
        $id = Id::make('notif');

        Database::insert('notifications', [
            'id'           => $id,
            'user_id'      => $userId,
            'role'         => Database::scalar('SELECT role FROM users WHERE id = :id', ['id' => $userId]),
            'type'         => $type,
            'title'        => $title,
            'body'         => Str::limit($body, 990, ''),
            'link'         => $link,
            'action_label' => $actionLabel,
            'entity_type'  => $entityType,
            'entity_id'    => $entityId,
            'read_at'      => null,
            'created_at'   => Str::dbDate(),
        ]);

        if ($email && self::wantsEmail($userId, $type)) {
            self::email($userId, $title, $body, $link, $actionLabel);
        }

        return $id;
    }

    /** Push the same notification to several users (deduplicated). */
    public static function pushMany(array $userIds, string $type, string $title, string $body = '', ?string $link = null, ?string $entityType = null, ?string $entityId = null): void
    {
        foreach (array_unique(array_filter($userIds)) as $userId) {
            self::push($userId, $type, $title, $body, $link, null, $entityType, $entityId);
        }
    }

    private static function wantsEmail(string $userId, string $type): bool
    {
        $row = Database::first(
            'SELECT email FROM notification_preferences WHERE user_id = :user AND type = :type',
            ['user' => $userId, 'type' => $type]
        );

        return $row === null ? true : (int) $row['email'] === 1;
    }

    private static function email(string $userId, string $title, string $body, ?string $link, ?string $actionLabel): void
    {
        $user = Database::first('SELECT email, name FROM users WHERE id = :id', ['id' => $userId]);
        if ($user === null || empty($user['email'])) {
            return;
        }

        $html = '<p>' . htmlspecialchars($body, ENT_QUOTES) . '</p>';
        if ($link !== null) {
            $url = str_starts_with($link, 'http') ? $link : Env::appUrl($link);
            $label = $actionLabel ?: 'Open PitchRooms';
            $html .= sprintf(
                '<p><a href="%s" style="display:inline-block;background:#ff512f;color:#fff;padding:10px 18px;border-radius:8px;text-decoration:none;font-weight:600">%s</a></p>',
                htmlspecialchars($url, ENT_QUOTES),
                htmlspecialchars($label, ENT_QUOTES)
            );
        }

        Mailer::send((string) $user['email'], $title, $html);
    }

    public static function markRead(string $id, string $userId): bool
    {
        return Database::update(
            'notifications',
            ['read_at' => Str::dbDate()],
            'id = :id AND user_id = :user AND read_at IS NULL',
            ['id' => $id, 'user' => $userId]
        ) > 0;
    }

    public static function markAllRead(string $userId): int
    {
        return Database::update(
            'notifications',
            ['read_at' => Str::dbDate()],
            'user_id = :user AND read_at IS NULL',
            ['user' => $userId]
        );
    }

    public static function unreadCount(string $userId): int
    {
        return (int) Database::scalar(
            'SELECT COUNT(*) FROM notifications WHERE user_id = :user AND read_at IS NULL',
            ['user' => $userId]
        );
    }

    public static function present(array $row): array
    {
        return [
            'id'          => $row['id'],
            'userId'      => $row['user_id'],
            'role'        => $row['role'],
            'type'        => $row['type'],
            'title'       => $row['title'],
            'body'        => $row['body'],
            'link'        => $row['link'],
            'actionLabel' => $row['action_label'],
            'entityType'  => $row['entity_type'],
            'entityId'    => $row['entity_id'],
            'read'        => $row['read_at'] !== null,
            'readAt'      => Str::toIso($row['read_at']),
            'createdAt'   => Str::toIso($row['created_at']),
        ];
    }
}
