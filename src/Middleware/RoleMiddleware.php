<?php

declare(strict_types=1);

namespace PitchRooms\Middleware;

use PitchRooms\Core\HttpException;
use PitchRooms\Core\Request;

final class RoleMiddleware
{
    /** Admins pass every role gate. */
    public function handle(Request $request, array $roles): void
    {
        $user = $request->user();
        if ($user === null) {
            throw HttpException::unauthenticated('Sign in to continue.');
        }

        $roles = array_values(array_filter(array_map('trim', $roles)));
        if ($roles === []) {
            return;
        }

        $role = (string) $user['role'];
        if ($role === 'admin' || in_array($role, $roles, true)) {
            return;
        }

        // Convenience aliases: the legacy dashboards call buyers "company",
        // the AB panel calls the same actor "brand".
        $aliases = [
            'brand'   => ['company'],
            'company' => ['brand'],
        ];
        foreach ($aliases[$role] ?? [] as $alias) {
            if (in_array($alias, $roles, true)) {
                return;
            }
        }

        throw HttpException::forbidden(
            sprintf('This action is limited to: %s.', implode(', ', $roles)),
            'ROLE_NOT_ALLOWED'
        );
    }
}
