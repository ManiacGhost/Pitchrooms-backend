<?php

declare(strict_types=1);

namespace PitchRooms\Services;

use PitchRooms\Core\Database;
use PitchRooms\Core\Env;
use PitchRooms\Core\HttpException;
use PitchRooms\Support\Str;

/**
 * One profile row per user. The indexed columns are what we filter and sort
 * on; `payload` carries the rest of the role-specific shape the frontend
 * already renders (case studies, education, portfolio, traction…), so adding
 * a display field never needs a migration.
 */
final class ProfileService
{
    public const TYPES = ['agency', 'brand', 'candidate', 'employer', 'startup', 'investor', 'company'];

    public static function typeForRole(string $role): string
    {
        return match ($role) {
            'agency'   => 'agency',
            'brand'    => 'brand',
            'employee' => 'candidate',
            'employer' => 'employer',
            'startup'  => 'startup',
            'investor' => 'investor',
            'company'  => 'company',
            default    => 'agency',
        };
    }

    public static function createForUser(string $userId, string $role, array $input = []): void
    {
        $type = self::typeForRole($role);
        $now = Str::dbDate();

        Database::insert('profiles', [
            'user_id'      => $userId,
            'type'         => $type,
            'display_name' => $input['company'] ?? $input['name'] ?? null,
            'headline'     => $input['title'] ?? null,
            'bio'          => $input['bio'] ?? null,
            'location'     => $input['location'] ?? $input['country'] ?? null,
            'website'      => $input['website'] ?? null,
            'linkedin'     => $input['linkedin'] ?? null,
            'logo_file_id' => $input['logoFileId'] ?? null,
            'sector'       => $input['sector'] ?? null,
            'stage'        => $input['stage'] ?? null,
            'specialty'    => $input['specialty'] ?? null,
            'experience'   => $input['experience'] ?? null,
            'rating'       => 0,
            'reviews'      => 0,
            'trust_score'  => 0,
            'verified'     => 0,
            'payload'      => Str::json(self::extraPayload($type, $input)),
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);

        self::syncSkills($userId, Str::listOf($input['skills'] ?? []));
    }

    /** Role-specific fields that live in the JSON payload. */
    private static function extraPayload(string $type, array $input): array
    {
        $keys = match ($type) {
            'agency'    => ['teamSize', 'languages', 'clients', 'caseStudies', 'hourly', 'gst', 'portfolio'],
            'brand', 'company' => ['industry', 'companySize', 'gst', 'brands'],
            'candidate' => ['department', 'employmentType', 'noticePeriod', 'salary', 'education', 'personal', 'resumeFileId'],
            'employer'  => ['industry', 'companySize', 'hiringFor', 'benefits'],
            'startup'   => ['founder', 'traction', 'tags', 'deckFileId', 'round', 'valuation'],
            'investor'  => ['firm', 'investorType', 'thesis', 'sectors', 'stages', 'portfolio', 'chequeSize', 'geography'],
            default     => [],
        };

        $payload = [];
        foreach ($keys as $key) {
            if (isset($input[$key]) && $input[$key] !== '') {
                $payload[$key] = $input[$key];
            }
        }

        return $payload;
    }

    public static function forUser(string $userId): ?array
    {
        $row = Database::first(
            'SELECT p.*, u.name, u.email, u.company, u.title, u.role, u.country, u.phone,
                    u.verification_status, u.account_status, u.avatar_file_id, u.created_at AS user_created_at
             FROM profiles p
             JOIN users u ON u.id = p.user_id
             WHERE p.user_id = :id',
            ['id' => $userId]
        );

        return $row === null ? null : self::present($row);
    }

    public static function requireForUser(string $userId): array
    {
        $profile = self::forUser($userId);
        if ($profile === null) {
            throw HttpException::notFound('Profile not found.');
        }
        return $profile;
    }

    public static function update(string $userId, array $input): array
    {
        $existing = Database::first('SELECT * FROM profiles WHERE user_id = :id', ['id' => $userId]);
        if ($existing === null) {
            $user = AuthService::requireUser($userId);
            self::createForUser($userId, (string) $user['role'], $input);
            $existing = Database::first('SELECT * FROM profiles WHERE user_id = :id', ['id' => $userId]);
        }

        $map = [
            'displayName' => 'display_name',
            'headline'    => 'headline',
            'bio'         => 'bio',
            'location'    => 'location',
            'website'     => 'website',
            'linkedin'    => 'linkedin',
            'logoFileId'  => 'logo_file_id',
            'sector'      => 'sector',
            'stage'       => 'stage',
            'specialty'   => 'specialty',
            'experience'  => 'experience',
        ];

        $changes = [];
        foreach ($map as $input_key => $column) {
            if (array_key_exists($input_key, $input)) {
                $value = $input[$input_key];
                $changes[$column] = is_scalar($value) ? (trim((string) $value) ?: null) : null;
            }
        }

        // Merge, do not replace, so a partial update never wipes other fields.
        $payload = Str::fromJson($existing['payload'] ?? null, []);
        $type = (string) $existing['type'];
        $incoming = self::extraPayload($type, $input);
        if ($incoming !== []) {
            $payload = array_merge(is_array($payload) ? $payload : [], $incoming);
            $changes['payload'] = Str::json($payload);
        }

        if ($changes !== []) {
            $changes['updated_at'] = Str::dbDate();
            Database::update('profiles', $changes, 'user_id = :id', ['id' => $userId]);
        }

        if (array_key_exists('skills', $input)) {
            self::syncSkills($userId, Str::listOf($input['skills']));
        }

        // Keep the mirrored fields on `users` in step.
        $userChanges = [];
        foreach (['company' => 'company', 'title' => 'title', 'phone' => 'phone', 'country' => 'country'] as $key => $column) {
            if (array_key_exists($key, $input) && $input[$key] !== '') {
                $userChanges[$column] = (string) $input[$key];
            }
        }
        if (isset($input['avatarFileId'])) {
            $userChanges['avatar_file_id'] = $input['avatarFileId'];
        }
        if ($userChanges !== []) {
            $userChanges['updated_at'] = Str::dbDate();
            Database::update('users', $userChanges, 'id = :id', ['id' => $userId]);
        }

        return self::requireForUser($userId);
    }

    public static function syncSkills(string $userId, array $skills): void
    {
        Database::statement('DELETE FROM profile_skills WHERE user_id = :id', ['id' => $userId]);

        foreach (array_slice($skills, 0, 40) as $skill) {
            Database::statement(
                'INSERT IGNORE INTO profile_skills (user_id, skill) VALUES (:user, :skill)',
                ['user' => $userId, 'skill' => mb_substr((string) $skill, 0, 80)]
            );
        }
    }

    public static function skills(string $userId): array
    {
        return array_column(
            Database::select('SELECT skill FROM profile_skills WHERE user_id = :id ORDER BY skill', ['id' => $userId]),
            'skill'
        );
    }

    /**
     * Trust score: verification, completed pitches, ratings, responsiveness.
     * Recomputed on read so it always reflects current activity.
     */
    public static function trustScore(string $userId): array
    {
        $user = AuthService::requireUser($userId);

        $verified = ($user['verification_status'] ?? '') === 'Verified' ? 25 : 0;
        $emailPhone = (!empty($user['email_verified_at']) ? 5 : 0) + (!empty($user['phone_verified_at']) ? 5 : 0);

        $completed = (int) Database::scalar(
            "SELECT COUNT(*) FROM event_participants ep
             JOIN events e ON e.id = ep.event_id
             WHERE ep.user_id = :user AND e.status IN ('completed','evaluation','decision_made')",
            ['user' => $userId]
        );
        $pitchPoints = min(25, $completed * 5);

        $rating = (float) (Database::scalar(
            'SELECT AVG(score) FROM ratings WHERE to_id = :user',
            ['user' => $userId]
        ) ?? 0);
        $ratingPoints = (int) round(min(25, $rating / 5 * 25));

        $noShows = (int) Database::scalar(
            "SELECT COUNT(*) FROM event_participants ep
             JOIN events e ON e.id = ep.event_id
             WHERE ep.user_id = :user AND ep.joined_at IS NULL
               AND e.status IN ('completed','evaluation','decision_made')",
            ['user' => $userId]
        );
        $reliability = max(0, 15 - $noShows * 5);

        $total = min(100, $verified + $emailPhone + $pitchPoints + $ratingPoints + $reliability);

        Database::update('profiles', ['trust_score' => $total, 'updated_at' => Str::dbDate()], 'user_id = :id', ['id' => $userId]);

        return [
            'userId' => $userId,
            'score'  => $total,
            'band'   => $total >= 80 ? 'Excellent' : ($total >= 60 ? 'Strong' : ($total >= 40 ? 'Building' : 'New')),
            'breakdown' => [
                ['key' => 'verification',   'label' => 'Verified identity',   'points' => $verified + $emailPhone, 'max' => 35],
                ['key' => 'pitches',        'label' => 'Completed pitches',   'points' => $pitchPoints,            'max' => 25],
                ['key' => 'ratings',        'label' => 'Counterparty ratings','points' => $ratingPoints,           'max' => 25],
                ['key' => 'reliability',    'label' => 'Attendance record',   'points' => $reliability,            'max' => 15],
            ],
            'completedPitches' => $completed,
            'averageRating'    => round($rating, 2),
            'noShows'          => $noShows,
        ];
    }

    public static function present(array $row): array
    {
        $payload = Str::fromJson($row['payload'] ?? null, []);

        return array_merge(is_array($payload) ? $payload : [], [
            'userId'      => $row['user_id'],
            'type'        => $row['type'],
            'role'        => $row['role'] ?? null,
            'name'        => $row['name'] ?? null,
            'displayName' => $row['display_name'] ?: ($row['company'] ?? $row['name'] ?? null),
            'company'     => $row['company'] ?? null,
            'title'       => $row['title'] ?? null,
            'headline'    => $row['headline'],
            'bio'         => $row['bio'],
            'location'    => $row['location'],
            'country'     => $row['country'] ?? null,
            'website'     => $row['website'],
            'linkedin'    => $row['linkedin'],
            'email'       => $row['email'] ?? null,
            'phone'       => $row['phone'] ?? null,
            'sector'      => $row['sector'],
            'stage'       => $row['stage'],
            'specialty'   => $row['specialty'],
            'experience'  => $row['experience'],
            'skills'      => self::skills((string) $row['user_id']),
            'rating'      => (float) $row['rating'],
            'reviews'     => (int) $row['reviews'],
            'trustScore'  => (int) $row['trust_score'],
            'verified'    => (bool) $row['verified'] || ($row['verification_status'] ?? '') === 'Verified',
            'verificationStatus' => $row['verification_status'] ?? null,
            'logo'        => $row['logo_file_id'] ? Env::url('/files/' . $row['logo_file_id']) : null,
            'avatar'      => !empty($row['avatar_file_id']) ? Env::url('/files/' . $row['avatar_file_id']) : null,
            'createdAt'   => Str::toIso($row['created_at'] ?? null),
            'updatedAt'   => Str::toIso($row['updated_at'] ?? null),
        ]);
    }
}
