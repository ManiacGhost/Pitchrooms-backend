<?php

declare(strict_types=1);

namespace PitchRooms\Middleware;

use PitchRooms\Core\Database;
use PitchRooms\Core\HttpException;
use PitchRooms\Core\Request;
use PitchRooms\Support\Jwt;

/**
 * Resolves the caller from the access token. Identity NEVER comes from the
 * request body — the frontend still sends fromId/agencyId/employerId in some
 * payloads and those are ignored on purpose.
 */
final class AuthMiddleware
{
    public function handle(Request $request, bool $optional = false): void
    {
        $token = $request->bearerToken();

        if ($token === null || $token === '') {
            if ($optional) {
                return;
            }
            throw HttpException::unauthenticated('Sign in to continue.');
        }

        $claims = Jwt::decode($token);
        if ($claims === null || ($claims['typ'] ?? '') !== 'access') {
            if ($optional) {
                return;
            }
            throw HttpException::unauthenticated('Your session has expired. Sign in again.');
        }

        $user = Database::first(
            'SELECT id, panel_id, role, name, first_name, last_name, email, phone, company, title,
                    country, avatar_file_id, verification_status, account_status,
                    email_verified_at, phone_verified_at, created_at
             FROM users WHERE id = :id AND deleted_at IS NULL',
            ['id' => (string) ($claims['sub'] ?? '')]
        );

        if ($user === null) {
            if ($optional) {
                return;
            }
            throw HttpException::unauthenticated('Account no longer exists.');
        }

        if (($user['account_status'] ?? 'Active') === 'Suspended') {
            throw HttpException::forbidden('This account is suspended. Contact support.', 'ACCOUNT_SUSPENDED');
        }

        $request->setAttribute('user', $user);
        $request->setAttribute('claims', $claims);
    }
}
