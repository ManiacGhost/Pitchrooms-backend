<?php

declare(strict_types=1);

namespace PitchRooms\Controllers;

use PitchRooms\Core\Database;
use PitchRooms\Core\HttpException;
use PitchRooms\Core\Request;
use PitchRooms\Core\Response;
use PitchRooms\Services\ActivityLog;
use PitchRooms\Services\AuthService;
use PitchRooms\Services\ProfileService;

final class ProfileController
{
    /** GET /profiles/{type}/{id} */
    public function show(Request $request, array $params): Response
    {
        $profile = ProfileService::forUser((string) $params['id']);
        if ($profile === null) {
            throw HttpException::notFound('Profile not found.');
        }

        // Contact details are for the counterparty and admins, not the public.
        $viewerId = $request->userId();
        if ($viewerId !== $params['id'] && !$request->isAdmin()) {
            unset($profile['email'], $profile['phone']);
        }

        return Response::json($profile);
    }

    /** PUT/PATCH /profiles/{type}/{id} */
    public function update(Request $request, array $params): Response
    {
        $targetId = (string) $params['id'];
        if ($targetId !== $request->userId() && !$request->isAdmin()) {
            throw HttpException::forbidden('You can only edit your own profile.');
        }

        $profile = ProfileService::update($targetId, $request->all());
        ActivityLog::record((string) $request->userId(), 'profile', $targetId, 'updated', [], $request);

        return Response::json($profile);
    }

    /** GET /profiles/me */
    public function me(Request $request): Response
    {
        return Response::json(ProfileService::requireForUser((string) $request->userId()));
    }

    /** PUT /profiles/me */
    public function updateMe(Request $request): Response
    {
        $profile = ProfileService::update((string) $request->userId(), $request->all());

        return Response::json($profile);
    }

    /** GET /profiles/{type}/{id}/trust-score */
    public function trustScore(Request $request, array $params): Response
    {
        AuthService::requireUser((string) $params['id']);

        return Response::json(ProfileService::trustScore((string) $params['id']));
    }

    /**
     * GET /directory/{type}
     * Powers EeTalent, SiDiscover, AbJobs' seller list and the admin tables.
     */
    public function directory(Request $request, array $params): Response
    {
        $type = (string) $params['type'];

        $roleMap = [
            'agencies'   => 'agency',
            'brands'     => 'brand',
            'candidates' => 'employee',
            'employers'  => 'employer',
            'startups'   => 'startup',
            'investors'  => 'investor',
            'companies'  => 'employer',
        ];

        if (!isset($roleMap[$type])) {
            throw HttpException::notFound('Unknown directory: ' . $type);
        }

        $where = ["u.role = :role", "u.deleted_at IS NULL", "u.account_status = 'Active'"];
        $bindings = ['role' => $roleMap[$type]];

        if ($q = $request->string('q')) {
            $where[] = '(u.name LIKE :q OR u.company LIKE :q2 OR p.headline LIKE :q3 OR p.bio LIKE :q4)';
            $like = '%' . $q . '%';
            $bindings += ['q' => $like, 'q2' => $like, 'q3' => $like, 'q4' => $like];
        }
        if ($location = $request->string('location')) {
            $where[] = '(p.location LIKE :location OR u.country LIKE :location2)';
            $bindings += ['location' => '%' . $location . '%', 'location2' => '%' . $location . '%'];
        }
        if ($sector = $request->string('sector')) {
            $where[] = 'p.sector = :sector';
            $bindings['sector'] = $sector;
        }
        if ($stage = $request->string('stage')) {
            $where[] = 'p.stage = :stage';
            $bindings['stage'] = $stage;
        }
        if ($experience = $request->string('experience')) {
            $where[] = 'p.experience LIKE :experience';
            $bindings['experience'] = '%' . $experience . '%';
        }
        if ($request->bool('verifiedOnly')) {
            $where[] = "u.verification_status = 'Verified'";
        }

        $skills = $request->array('skills');
        if ($skills !== []) {
            $placeholders = [];
            foreach (array_values($skills) as $index => $skill) {
                $key = 'skill' . $index;
                $placeholders[] = ':' . $key;
                $bindings[$key] = $skill;
            }
            $where[] = 'EXISTS (SELECT 1 FROM profile_skills ps WHERE ps.user_id = u.id AND ps.skill IN (' . implode(',', $placeholders) . '))';
        }

        $whereSql = implode(' AND ', $where);
        $page = $request->page();
        $perPage = $request->perPage(24);
        $offset = ($page - 1) * $perPage;

        $total = (int) Database::scalar(
            "SELECT COUNT(*) FROM users u LEFT JOIN profiles p ON p.user_id = u.id WHERE {$whereSql}",
            $bindings
        );

        $sort = match ($request->string('sort')) {
            'rating'     => 'p.rating DESC',
            'trust'      => 'p.trust_score DESC',
            'name'       => 'u.name ASC',
            default      => 'p.trust_score DESC, p.rating DESC, u.created_at DESC',
        };

        $rows = Database::select(
            // u.id last so it wins over a NULL p.user_id when no profile row exists yet
            "SELECT p.*, u.name, u.email, u.company, u.title, u.role, u.country, u.phone,
                    u.verification_status, u.account_status, u.avatar_file_id, u.id AS user_id
             FROM users u
             LEFT JOIN profiles p ON p.user_id = u.id
             WHERE {$whereSql}
             ORDER BY {$sort}
             LIMIT {$perPage} OFFSET {$offset}",
            $bindings
        );

        $items = array_map(static function (array $row): array {
            $profile = ProfileService::present($row);
            unset($profile['email'], $profile['phone']);
            return $profile;
        }, $rows);

        return Response::paginated($items, $total, $page, $perPage);
    }
}
