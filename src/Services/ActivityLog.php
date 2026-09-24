<?php

declare(strict_types=1);

namespace PitchRooms\Services;

use PitchRooms\Core\Database;
use PitchRooms\Core\Request;
use PitchRooms\Support\Str;

/**
 * Audit trail. Also the source for the timeline views the UI renders and for
 * admin reporting.
 */
final class ActivityLog
{
    public static function record(
        ?string $actorId,
        string $entityType,
        ?string $entityId,
        string $action,
        array $diff = [],
        ?Request $request = null
    ): void {
        Database::insert('activity_log', [
            'actor_id'    => $actorId,
            'entity_type' => $entityType,
            'entity_id'   => $entityId,
            'action'      => $action,
            'diff'        => $diff === [] ? null : Str::json($diff),
            'ip'          => $request?->ip(),
            'user_agent'  => $request?->userAgent(),
            'created_at'  => Str::dbDate(),
        ]);
    }

    public static function forEntity(string $entityType, string $entityId, int $limit = 100): array
    {
        return Database::select(
            'SELECT a.*, u.name AS actor_name, u.role AS actor_role
             FROM activity_log a
             LEFT JOIN users u ON u.id = a.actor_id
             WHERE a.entity_type = :type AND a.entity_id = :id
             ORDER BY a.created_at DESC
             LIMIT ' . max(1, min(500, $limit)),
            ['type' => $entityType, 'id' => $entityId]
        );
    }

    public static function present(array $row): array
    {
        return [
            'id'         => (int) $row['id'],
            'actorId'    => $row['actor_id'],
            'actorName'  => $row['actor_name'] ?? null,
            'actorRole'  => $row['actor_role'] ?? null,
            'entityType' => $row['entity_type'],
            'entityId'   => $row['entity_id'],
            'action'     => $row['action'],
            'diff'       => Str::fromJson($row['diff'] ?? null, null),
            'ip'         => $row['ip'],
            'createdAt'  => Str::toIso($row['created_at']),
        ];
    }
}
