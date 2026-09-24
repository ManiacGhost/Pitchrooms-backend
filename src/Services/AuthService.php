<?php

declare(strict_types=1);

namespace PitchRooms\Services;

use PitchRooms\Core\Database;
use PitchRooms\Core\Env;
use PitchRooms\Core\HttpException;
use PitchRooms\Support\Id;
use PitchRooms\Support\Jwt;
use PitchRooms\Support\Str;

final class AuthService
{
    public const PANELS = [
        'agency-brand'      => ['agency', 'brand'],
        'employee-employer' => ['employee', 'employer'],
        'startup-investor'  => ['startup', 'investor'],
        'internal'          => ['admin', 'company'],
    ];

    /** Roles that pay to pitch. Buyers never hit the pass gate. */
    public const SELLER_ROLES = ['agency', 'employee', 'startup'];
    public const BUYER_ROLES  = ['brand', 'employer', 'investor', 'company', 'admin'];

    public static function panelForRole(string $role): string
    {
        foreach (self::PANELS as $panelId => $roles) {
            if (in_array($role, $roles, true)) {
                return $panelId;
            }
        }
        return 'internal';
    }

    public static function verticalForRole(string $role): string
    {
        return match ($role) {
            'agency', 'brand', 'company' => 'ab',
            'employee', 'employer'       => 'ee',
            'startup', 'investor'        => 'si',
            default                      => 'ab',
        };
    }

    public static function isSeller(string $role): bool
    {
        return in_array($role, self::SELLER_ROLES, true);
    }

    public static function isBuyer(string $role): bool
    {
        return in_array($role, self::BUYER_ROLES, true);
    }

    public static function hashPassword(string $password): string
    {
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => 11]);
    }

    public static function verifyPassword(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    /** @return array{accessToken:string, refreshToken:string, expiresIn:int} */
    public static function issueTokens(array $user, string $userAgent = '', string $ip = ''): array
    {
        $accessTtl = Env::int('JWT_ACCESS_TTL', 900);
        $refreshTtl = Env::int('JWT_REFRESH_TTL', 2592000);
        $now = time();

        $accessToken = Jwt::encode([
            'iss'   => Env::string('JWT_ISSUER', 'pitchrooms-api'),
            'sub'   => $user['id'],
            'typ'   => 'access',
            'role'  => $user['role'],
            'panel' => $user['panel_id'],
            'iat'   => $now,
            'exp'   => $now + $accessTtl,
        ]);

        $refreshToken = Id::token(32);

        Database::insert('refresh_tokens', [
            'id'         => Id::make('rt'),
            'user_id'    => $user['id'],
            'token_hash' => hash('sha256', $refreshToken),
            'user_agent' => substr($userAgent, 0, 255),
            'ip'         => substr($ip, 0, 64),
            'expires_at' => Str::dbDate($now + $refreshTtl),
            'revoked_at' => null,
            'created_at' => Str::dbDate($now),
        ]);

        return [
            'accessToken'  => $accessToken,
            'refreshToken' => $refreshToken,
            'expiresIn'    => $accessTtl,
            'tokenType'    => 'Bearer',
        ];
    }

    public static function rotateRefreshToken(string $refreshToken, string $userAgent = '', string $ip = ''): array
    {
        $hash = hash('sha256', $refreshToken);

        $row = Database::first(
            'SELECT id, user_id, expires_at, revoked_at FROM refresh_tokens WHERE token_hash = :hash',
            ['hash' => $hash]
        );

        if ($row === null || $row['revoked_at'] !== null) {
            throw HttpException::unauthenticated('This session is no longer valid. Sign in again.');
        }
        if (strtotime((string) $row['expires_at'] . ' UTC') < time()) {
            throw HttpException::unauthenticated('Your session has expired. Sign in again.');
        }

        $user = Database::first(
            'SELECT * FROM users WHERE id = :id AND deleted_at IS NULL',
            ['id' => $row['user_id']]
        );
        if ($user === null) {
            throw HttpException::unauthenticated('Account no longer exists.');
        }

        // Rotate: the presented token dies with this call.
        Database::update('refresh_tokens', ['revoked_at' => Str::dbDate()], 'id = :id', ['id' => $row['id']]);

        return self::issueTokens($user, $userAgent, $ip);
    }

    public static function revokeRefreshToken(string $refreshToken): void
    {
        Database::update(
            'refresh_tokens',
            ['revoked_at' => Str::dbDate()],
            'token_hash = :hash AND revoked_at IS NULL',
            ['hash' => hash('sha256', $refreshToken)]
        );
    }

    public static function revokeAllForUser(string $userId): void
    {
        Database::update(
            'refresh_tokens',
            ['revoked_at' => Str::dbDate()],
            'user_id = :user AND revoked_at IS NULL',
            ['user' => $userId]
        );
    }

    /** The user shape the frontend session expects. */
    public static function publicUser(array $user): array
    {
        return [
            'id'                 => $user['id'],
            'panelId'            => $user['panel_id'],
            'role'               => $user['role'],
            'vertical'           => self::verticalForRole((string) $user['role']),
            'name'               => $user['name'],
            'firstName'          => $user['first_name'],
            'lastName'           => $user['last_name'],
            'email'              => $user['email'],
            'phone'              => $user['phone'],
            'country'            => $user['country'],
            'company'            => $user['company'],
            'title'              => $user['title'],
            'avatar'             => isset($user['avatar_file_id']) && $user['avatar_file_id']
                ? Env::url('/files/' . $user['avatar_file_id'])
                : null,
            'verificationStatus' => $user['verification_status'] ?? 'Pending',
            'accountStatus'      => $user['account_status'] ?? 'Active',
            'emailVerified'      => !empty($user['email_verified_at']),
            'phoneVerified'      => !empty($user['phone_verified_at']),
            'isSeller'           => self::isSeller((string) $user['role']),
            'isBuyer'            => self::isBuyer((string) $user['role']),
            'createdAt'          => Str::toIso($user['created_at'] ?? null),
        ];
    }

    public static function findByEmail(string $email): ?array
    {
        return Database::first(
            'SELECT * FROM users WHERE email = :email AND deleted_at IS NULL',
            ['email' => Str::email($email)]
        );
    }

    public static function findById(string $id): ?array
    {
        return Database::first('SELECT * FROM users WHERE id = :id AND deleted_at IS NULL', ['id' => $id]);
    }

    public static function requireUser(string $id): array
    {
        $user = self::findById($id);
        if ($user === null) {
            throw HttpException::notFound('User not found.');
        }
        return $user;
    }
}
