<?php

declare(strict_types=1);

namespace PitchRooms\Services;

use PitchRooms\Core\Database;

/**
 * Match scoring and ranking.
 *
 * This drove shortlisting in the browser (scoreCandidateForJob, matchScore,
 * rankApplications, rankApplicants). A score that decides who gets into a
 * paid pitch room cannot be computed on the client, so it lives here.
 *
 * Weighting: skills 45, experience 20, location/work type 15,
 * profile completeness 10, reputation 10.
 */
final class MatchService
{
    public static function score(string $opportunityId, string $sellerId): int
    {
        $opportunity = Database::first('SELECT * FROM opportunities WHERE id = :id', ['id' => $opportunityId]);
        $seller = Database::first(
            'SELECT u.*, p.experience AS profile_experience, p.location AS profile_location,
                    p.rating, p.reviews, p.trust_score, p.bio, p.sector, p.stage, p.verified
             FROM users u LEFT JOIN profiles p ON p.user_id = u.id
             WHERE u.id = :id',
            ['id' => $sellerId]
        );

        if ($opportunity === null || $seller === null) {
            return 0;
        }

        $opportunitySkills = array_map('strtolower', array_column(
            Database::select('SELECT skill FROM opportunity_skills WHERE opportunity_id = :id', ['id' => $opportunityId]),
            'skill'
        ));
        $sellerSkills = array_map('strtolower', array_column(
            Database::select('SELECT skill FROM profile_skills WHERE user_id = :id', ['id' => $sellerId]),
            'skill'
        ));

        $score = 0.0;

        // Skills — the dominant signal.
        if ($opportunitySkills === []) {
            $score += 30;
        } else {
            $overlap = count(array_intersect($opportunitySkills, $sellerSkills));
            $score += 45 * min(1.0, $overlap / max(1, count($opportunitySkills)));
        }

        // Experience band.
        $score += 20 * self::experienceFit(
            (string) ($opportunity['experience'] ?? ''),
            (string) ($seller['profile_experience'] ?? '')
        );

        // Location / work type.
        $score += 15 * self::locationFit(
            (string) ($opportunity['work_type'] ?? ''),
            (string) ($opportunity['location'] ?? ''),
            (string) ($seller['profile_location'] ?? $seller['country'] ?? '')
        );

        // Profile completeness — rewards sellers who filled in their profile.
        $completeness = 0;
        foreach (['bio', 'profile_location', 'profile_experience'] as $field) {
            if (!empty($seller[$field])) {
                $completeness++;
            }
        }
        if ($sellerSkills !== []) {
            $completeness++;
        }
        $score += 10 * ($completeness / 4);

        // Reputation.
        $rating = (float) ($seller['rating'] ?? 0);
        $verified = (int) ($seller['verified'] ?? 0) === 1 || ($seller['verification_status'] ?? '') === 'Verified';
        $score += 7 * min(1.0, $rating / 5) + ($verified ? 3 : 0);

        return (int) round(max(0, min(100, $score)));
    }

    private static function experienceFit(string $required, string $actual): float
    {
        if ($required === '' || $actual === '') {
            return 0.6;
        }

        $rank = static function (string $value): int {
            $value = strtolower($value);
            if (str_contains($value, 'entry') || str_contains($value, 'junior') || str_contains($value, 'fresher')) {
                return 1;
            }
            if (str_contains($value, 'mid')) {
                return 2;
            }
            if (str_contains($value, 'senior')) {
                return 3;
            }
            if (str_contains($value, 'lead') || str_contains($value, 'principal') || str_contains($value, 'director')) {
                return 4;
            }
            if (preg_match('/(\d+)/', $value, $m)) {
                $years = (int) $m[1];
                return $years <= 2 ? 1 : ($years <= 5 ? 2 : ($years <= 9 ? 3 : 4));
            }
            return 2;
        };

        $gap = abs($rank($required) - $rank($actual));

        return match ($gap) {
            0 => 1.0,
            1 => 0.7,
            2 => 0.4,
            default => 0.2,
        };
    }

    private static function locationFit(string $workType, string $location, string $sellerLocation): float
    {
        $workType = strtolower($workType);
        if (str_contains($workType, 'remote') || str_contains($workType, 'live pitch')) {
            return 1.0;
        }
        if ($sellerLocation === '' || $location === '') {
            return 0.5;
        }

        $normalise = static fn (string $value): array => array_filter(
            preg_split('/[\s,·\-\/]+/', strtolower($value)) ?: [],
            static fn ($part) => strlen($part) > 2
        );

        $overlap = array_intersect($normalise($location), $normalise($sellerLocation));

        return $overlap === [] ? 0.35 : 1.0;
    }

    /**
     * Rank an opportunity's proposals. Returns rows ordered best-first with a
     * freshly computed score.
     */
    public static function rank(string $opportunityId): array
    {
        $proposals = Database::select(
            "SELECT p.*, u.name AS user_name, u.company AS user_company,
                    pr.rating, pr.trust_score
             FROM proposals p
             JOIN users u ON u.id = p.seller_id
             LEFT JOIN profiles pr ON pr.user_id = p.seller_id
             WHERE p.opportunity_id = :id
               AND p.status NOT IN ('withdrawn','rejected','declined')",
            ['id' => $opportunityId]
        );

        foreach ($proposals as $index => $proposal) {
            $score = self::score($opportunityId, (string) $proposal['seller_id']);
            $proposals[$index]['match_score'] = $score;

            Database::update('proposals', ['match_score' => $score], 'id = :id', ['id' => $proposal['id']]);
        }

        usort($proposals, static function (array $a, array $b): int {
            $byScore = ($b['match_score'] ?? 0) <=> ($a['match_score'] ?? 0);
            if ($byScore !== 0) {
                return $byScore;
            }
            return strtotime((string) $a['created_at']) <=> strtotime((string) $b['created_at']);
        });

        return $proposals;
    }

    /** "Top 5" -> 5. Drives how many sellers reach the pitch room. */
    public static function shortlistTarget(?string $preferredSellers, int $fallback = 5): int
    {
        if ($preferredSellers !== null && preg_match('/(\d+)/', $preferredSellers, $matches)) {
            return max(1, min(20, (int) $matches[1]));
        }
        return $fallback;
    }
}
