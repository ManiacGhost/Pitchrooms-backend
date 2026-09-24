<?php

declare(strict_types=1);

namespace PitchRooms\Controllers;

use PitchRooms\Core\Database;
use PitchRooms\Core\Env;
use PitchRooms\Core\HttpException;
use PitchRooms\Core\Request;
use PitchRooms\Core\Response;
use PitchRooms\Services\ActivityLog;
use PitchRooms\Services\AuthService;
use PitchRooms\Services\ProfileService;
use PitchRooms\Services\RateLimiter;
use PitchRooms\Support\Id;
use PitchRooms\Support\Mailer;
use PitchRooms\Support\Str;
use PitchRooms\Support\Validator;

final class AuthController
{
    /** POST /auth/register — creates the user and its role profile together. */
    public function register(Request $request): Response
    {
        RateLimiter::hit('register:' . $request->ip(), 10, 3600);

        $data = Validator::make($request, [
            'role'      => 'required|in:agency,brand,employee,employer,startup,investor',
            'email'     => 'required|email|max:190',
            'password'  => 'required|min:6|max:100',
            'country'   => 'max:80',
            'firstName' => 'max:80',
            'lastName'  => 'max:80',
            'company'   => 'max:160',
            'title'     => 'max:120',
            'phone'     => 'max:32',
        ]);

        $role = (string) $data['role'];
        $panelId = AuthService::panelForRole($role);
        $email = Str::email((string) $data['email']);

        if (AuthService::findByEmail($email) !== null) {
            throw HttpException::conflict(
                'An account with this email already exists. Sign in instead.',
                'EMAIL_TAKEN'
            );
        }

        $company = trim((string) ($data['company'] ?? ''));
        $firstName = trim((string) ($data['firstName'] ?? ''));
        $lastName = trim((string) ($data['lastName'] ?? ''));

        // Brands register as an organisation; everyone else as a person.
        if ($role === 'brand') {
            $displayName = $company !== '' ? $company : ($firstName ?: 'Brand');
        } else {
            if ($firstName === '' || $lastName === '') {
                throw HttpException::validation([
                    'firstName' => $firstName === '' ? 'Enter your first name.' : null,
                    'lastName'  => $lastName === '' ? 'Enter your last name.' : null,
                ], 'Enter your first and last name.');
            }
            $displayName = trim($firstName . ' ' . $lastName);
        }

        $userId = Id::make($role);
        $now = Str::dbDate();

        Database::transaction(static function () use ($userId, $panelId, $role, $firstName, $lastName, $displayName, $email, $data, $company, $now, $request): void {
            Database::insert('users', [
                'id'                  => $userId,
                'panel_id'            => $panelId,
                'role'                => $role,
                'first_name'          => $firstName ?: null,
                'last_name'           => $lastName ?: null,
                'name'                => $displayName,
                'email'               => $email,
                'password_hash'       => AuthService::hashPassword((string) $data['password']),
                'phone'               => $data['phone'] ?? null,
                'country'             => $data['country'] ?? null,
                'company'             => $company ?: null,
                'title'               => $data['title'] ?? null,
                'avatar_file_id'      => null,
                'email_verified_at'   => null,
                'phone_verified_at'   => null,
                'verification_status' => 'Pending',
                'account_status'      => 'Active',
                'last_login_at'       => null,
                'created_at'          => $now,
                'updated_at'          => $now,
                'deleted_at'          => null,
            ]);

            ProfileService::createForUser($userId, $role, $request->all());
        });

        $user = AuthService::requireUser($userId);
        $tokens = AuthService::issueTokens($user, $request->userAgent(), $request->ip());

        ActivityLog::record($userId, 'user', $userId, 'registered', ['role' => $role], $request);

        Mailer::send($email, 'Welcome to PitchRooms', sprintf(
            '<p>Your %s account is ready. Complete your profile and verification to start pitching.</p>',
            htmlspecialchars($role, ENT_QUOTES)
        ));

        return Response::created([
            'user'    => AuthService::publicUser($user),
            'session' => $tokens,
        ]);
    }

    /** POST /auth/login */
    public function login(Request $request): Response
    {
        $email = Str::email($request->string('email'));
        RateLimiter::hit('login:' . $request->ip(), Env::int('RATE_LIMIT_LOGIN', 10), 300);
        RateLimiter::hit('login:' . $email, Env::int('RATE_LIMIT_LOGIN', 10), 300);

        Validator::make($request, [
            'email'    => 'required|email',
            'password' => 'required',
        ]);

        $user = AuthService::findByEmail($email);

        // Same message either way — do not leak which emails exist.
        if ($user === null || !AuthService::verifyPassword($request->string('password'), (string) $user['password_hash'])) {
            throw HttpException::unauthenticated('Incorrect email or password.');
        }

        if (($user['account_status'] ?? 'Active') === 'Suspended') {
            throw HttpException::forbidden('This account is suspended. Contact support.', 'ACCOUNT_SUSPENDED');
        }

        $panelId = $request->string('panelId');
        if ($panelId !== '' && $panelId !== $user['panel_id']) {
            throw HttpException::forbidden(
                'This account belongs to a different panel. Use the right sign-in page.',
                'WRONG_PANEL'
            );
        }

        Database::update('users', [
            'last_login_at' => Str::dbDate(),
            'updated_at'    => Str::dbDate(),
        ], 'id = :id', ['id' => $user['id']]);

        RateLimiter::clear('login:' . $email);
        $tokens = AuthService::issueTokens($user, $request->userAgent(), $request->ip());

        ActivityLog::record((string) $user['id'], 'user', (string) $user['id'], 'logged_in', [], $request);

        return Response::json([
            'user'    => AuthService::publicUser($user),
            'session' => $tokens,
        ]);
    }

    /** POST /auth/refresh */
    public function refresh(Request $request): Response
    {
        $token = $request->string('refreshToken');
        if ($token === '') {
            throw HttpException::badRequest('A refresh token is required.');
        }

        $tokens = AuthService::rotateRefreshToken($token, $request->userAgent(), $request->ip());

        return Response::json(['session' => $tokens]);
    }

    /** POST /auth/logout */
    public function logout(Request $request): Response
    {
        $token = $request->string('refreshToken');
        if ($token !== '') {
            AuthService::revokeRefreshToken($token);
        } elseif ($request->userId() !== null) {
            AuthService::revokeAllForUser((string) $request->userId());
        }

        return Response::json(['loggedOut' => true]);
    }

    /** GET /auth/me */
    public function me(Request $request): Response
    {
        $user = AuthService::requireUser((string) $request->userId());

        return Response::json([
            'user'    => AuthService::publicUser($user),
            'profile' => ProfileService::forUser((string) $user['id']),
        ]);
    }

    /** PATCH /auth/me — account fields only; profile data has its own endpoint. */
    public function updateMe(Request $request): Response
    {
        $userId = (string) $request->userId();
        $user = AuthService::requireUser($userId);

        $changes = [];
        foreach (['firstName' => 'first_name', 'lastName' => 'last_name', 'phone' => 'phone',
                  'country' => 'country', 'company' => 'company', 'title' => 'title'] as $input => $column) {
            if ($request->has($input)) {
                $changes[$column] = $request->string($input) ?: null;
            }
        }

        if ($request->has('email')) {
            $email = Str::email($request->string('email'));
            Validator::make(['email' => $email], ['email' => 'required|email|max:190']);

            $existing = AuthService::findByEmail($email);
            if ($existing !== null && $existing['id'] !== $userId) {
                throw HttpException::conflict('That email is already in use.', 'EMAIL_TAKEN');
            }
            if ($email !== $user['email']) {
                $changes['email'] = $email;
                $changes['email_verified_at'] = null; // re-verify after a change
            }
        }

        if ($changes !== []) {
            $first = $changes['first_name'] ?? $user['first_name'];
            $last = $changes['last_name'] ?? $user['last_name'];
            if (isset($changes['first_name']) || isset($changes['last_name'])) {
                $changes['name'] = $user['role'] === 'brand'
                    ? ($changes['company'] ?? $user['company'] ?? $user['name'])
                    : trim((string) $first . ' ' . (string) $last);
            }

            $changes['updated_at'] = Str::dbDate();
            Database::update('users', $changes, 'id = :id', ['id' => $userId]);
            ActivityLog::record($userId, 'user', $userId, 'profile_updated', array_keys($changes), $request);
        }

        return Response::json(['user' => AuthService::publicUser(AuthService::requireUser($userId))]);
    }

    /** GET /auth/check-email */
    public function checkEmail(Request $request): Response
    {
        $email = Str::email($request->string('email'));
        if ($email === '') {
            throw HttpException::badRequest('Provide an email to check.');
        }

        return Response::json(['available' => AuthService::findByEmail($email) === null]);
    }

    /** POST /auth/password/change */
    public function changePassword(Request $request): Response
    {
        Validator::make($request, [
            'currentPassword' => 'required',
            'newPassword'     => 'required|min:6|max:100',
        ]);

        $user = AuthService::requireUser((string) $request->userId());

        if (!AuthService::verifyPassword($request->string('currentPassword'), (string) $user['password_hash'])) {
            throw HttpException::badRequest('Your current password is incorrect.', 'WRONG_PASSWORD');
        }

        Database::update('users', [
            'password_hash' => AuthService::hashPassword($request->string('newPassword')),
            'updated_at'    => Str::dbDate(),
        ], 'id = :id', ['id' => $user['id']]);

        // Changing a password ends every other session.
        AuthService::revokeAllForUser((string) $user['id']);
        $tokens = AuthService::issueTokens($user, $request->userAgent(), $request->ip());

        ActivityLog::record((string) $user['id'], 'user', (string) $user['id'], 'password_changed', [], $request);

        return Response::json(['session' => $tokens]);
    }

    /** POST /auth/password/forgot — always reports success. */
    public function forgotPassword(Request $request): Response
    {
        $email = Str::email($request->string('email'));
        RateLimiter::hit('forgot:' . $request->ip(), 5, 900);

        $user = AuthService::findByEmail($email);

        if ($user !== null) {
            $token = Id::token(32);

            Database::insert('password_resets', [
                'id'         => Id::make('pwr'),
                'user_id'    => $user['id'],
                'token_hash' => hash('sha256', $token),
                'expires_at' => Str::dbDate(time() + 3600),
                'used_at'    => null,
                'created_at' => Str::dbDate(),
            ]);

            $link = Env::appUrl('/reset-password?token=' . $token);
            Mailer::send($email, 'Reset your PitchRooms password', sprintf(
                '<p>Use the link below within the hour to set a new password.</p><p><a href="%s">Reset password</a></p>',
                htmlspecialchars($link, ENT_QUOTES)
            ));
        }

        return Response::json([
            'sent'    => true,
            'message' => 'If that email has an account, a reset link is on its way.',
        ]);
    }

    /** POST /auth/password/reset */
    public function resetPassword(Request $request): Response
    {
        Validator::make($request, [
            'token'       => 'required',
            'newPassword' => 'required|min:6|max:100',
        ]);

        $row = Database::first(
            'SELECT * FROM password_resets WHERE token_hash = :hash AND used_at IS NULL',
            ['hash' => hash('sha256', $request->string('token'))]
        );

        if ($row === null || strtotime((string) $row['expires_at'] . ' UTC') < time()) {
            throw HttpException::badRequest('This reset link has expired. Request a new one.', 'RESET_EXPIRED');
        }

        Database::update('users', [
            'password_hash' => AuthService::hashPassword($request->string('newPassword')),
            'updated_at'    => Str::dbDate(),
        ], 'id = :id', ['id' => $row['user_id']]);

        Database::update('password_resets', ['used_at' => Str::dbDate()], 'id = :id', ['id' => $row['id']]);
        AuthService::revokeAllForUser((string) $row['user_id']);

        return Response::json(['reset' => true]);
    }
}
