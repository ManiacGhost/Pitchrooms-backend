<?php

declare(strict_types=1);

/**
 * Seeds the demo accounts the frontend already knows about, plus one
 * end-to-end flow per vertical (opportunity → proposal → shortlist → event).
 *
 *   php bin/seed.php           create anything missing
 *   php bin/seed.php --force   also reset demo passwords
 *
 * Demo password: demo123 (admin: admin123). Safe to re-run.
 */

use PitchRooms\Core\Autoloader;
use PitchRooms\Core\Database;
use PitchRooms\Core\Env;
use PitchRooms\Core\Paths;
use PitchRooms\Services\AuthService;
use PitchRooms\Services\MeetingService;
use PitchRooms\Services\ProfileService;
use PitchRooms\Services\SlotService;
use PitchRooms\Support\Id;
use PitchRooms\Support\Str;

$basePath = dirname(__DIR__);
require $basePath . '/src/Core/Autoloader.php';
Autoloader::register($basePath . '/src');

Env::load($basePath . '/.env');
Paths::setBase($basePath);

$force = in_array('--force', array_slice($argv, 1), true);

if (Env::string('APP_ENV', 'local') === 'production' && !$force) {
    echo "Refusing to seed demo data with APP_ENV=production (use --force if you really mean it).\n";
    exit(1);
}

function out(string $message): void
{
    echo $message . PHP_EOL;
}

$now = Str::dbDate();

$demoUsers = [
    ['role' => 'agency',   'first' => 'Ava',     'last' => 'Shah',   'email' => 'agency@pitchrooms.demo',   'company' => 'Northstar Studio',  'title' => 'Creative Director', 'country' => 'India'],
    ['role' => 'brand',    'first' => 'Noah',    'last' => 'Kapoor', 'email' => 'brand@pitchrooms.demo',    'company' => 'Aurora Retail',     'title' => 'Brand Lead',        'country' => 'United States'],
    ['role' => 'employee', 'first' => 'Nishtha', 'last' => 'Patel',  'email' => 'employee@pitchrooms.demo', 'company' => '',                  'title' => 'Product Designer',  'country' => 'India'],
    ['role' => 'employer', 'first' => 'Liam',    'last' => 'Mehta',  'email' => 'employer@pitchrooms.demo', 'company' => 'Horizon Labs',      'title' => 'Head of Talent',    'country' => 'United Kingdom'],
    ['role' => 'startup',  'first' => 'Zoe',     'last' => 'Rao',    'email' => 'startup@pitchrooms.demo',  'company' => 'Lumen Labs',        'title' => 'Founder',           'country' => 'Singapore'],
    ['role' => 'investor', 'first' => 'Ethan',   'last' => 'Iyer',   'email' => 'investor@pitchrooms.demo', 'company' => 'Northwind Capital', 'title' => 'Partner',           'country' => 'United Arab Emirates'],
    ['role' => 'admin',    'first' => 'Priya',   'last' => 'Nair',   'email' => 'admin@pitchrooms.demo',    'company' => 'PitchRooms',        'title' => 'Platform Admin',    'country' => 'India'],
];

$ids = [];

foreach ($demoUsers as $demo) {
    $existing = AuthService::findByEmail($demo['email']);

    if ($existing !== null) {
        $ids[$demo['role']] = (string) $existing['id'];

        if ($force) {
            Database::update('users', [
                'password_hash' => AuthService::hashPassword($demo['role'] === 'admin' ? 'admin123' : 'demo123'),
                'updated_at'    => $now,
            ], 'id = :id', ['id' => $existing['id']]);
            out('· reset password for ' . $demo['email']);
        } else {
            out('· exists: ' . $demo['email']);
        }
        continue;
    }

    $id = Id::make($demo['role']);
    $name = $demo['role'] === 'brand' ? $demo['company'] : trim($demo['first'] . ' ' . $demo['last']);

    Database::insert('users', [
        'id'                  => $id,
        'panel_id'            => AuthService::panelForRole($demo['role']),
        'role'                => $demo['role'],
        'first_name'          => $demo['first'],
        'last_name'           => $demo['last'],
        'name'                => $name,
        'email'               => $demo['email'],
        'password_hash'       => AuthService::hashPassword($demo['role'] === 'admin' ? 'admin123' : 'demo123'),
        'phone'               => '+91 98765 43210',
        'country'             => $demo['country'],
        'company'             => $demo['company'] ?: null,
        'title'               => $demo['title'],
        'avatar_file_id'      => null,
        'email_verified_at'   => $now,
        'phone_verified_at'   => $now,
        'verification_status' => 'Verified',
        'account_status'      => 'Active',
        'last_login_at'       => null,
        'created_at'          => $now,
        'updated_at'          => $now,
        'deleted_at'          => null,
    ]);

    ProfileService::createForUser($id, $demo['role'], [
        'company'    => $demo['company'],
        'title'      => $demo['title'],
        'location'   => $demo['country'],
        'bio'        => $name . ' on PitchRooms.',
        'experience' => 'Senior',
        'skills'     => match ($demo['role']) {
            'agency'   => ['Branding', 'Campaign', 'Performance Marketing', 'Social'],
            'employee' => ['Product Design', 'Figma', 'UX Research', 'Design Systems'],
            'startup'  => ['SaaS', 'Analytics', 'B2B'],
            default    => [],
        },
        'sector'     => in_array($demo['role'], ['startup', 'investor'], true) ? 'SaaS' : null,
        'stage'      => $demo['role'] === 'startup' ? 'Seed' : null,
    ]);

    Database::update('profiles', ['verified' => 1], 'user_id = :id', ['id' => $id]);

    $ids[$demo['role']] = $id;
    out('✓ created ' . $demo['email']);
}

// --------------------------------------------------------------------------
// One worked example per vertical, so the frontend has something to render.
// --------------------------------------------------------------------------

$flows = [
    [
        'vertical' => 'ab', 'buyer' => 'brand', 'seller' => 'agency',
        'title'    => 'D2C rebrand and 6-week launch campaign',
        'category' => 'Creative', 'skills' => ['Branding', 'Campaign', 'Social'],
        'budget'   => '$12,000',
    ],
    [
        'vertical' => 'ee', 'buyer' => 'employer', 'seller' => 'employee',
        'title'    => 'Senior Product Designer',
        'category' => 'Design', 'skills' => ['Product Design', 'Figma', 'UX Research'],
        'budget'   => '₹28,00,000 / year',
    ],
    [
        'vertical' => 'si', 'buyer' => 'investor', 'seller' => 'startup',
        'title'    => 'Lumen Labs — Seed round',
        'category' => 'Technology', 'skills' => ['SaaS', 'Analytics'],
        'budget'   => '$1.5M',
    ],
];

foreach ($flows as $flow) {
    $buyerId = $ids[$flow['buyer']] ?? null;
    $sellerId = $ids[$flow['seller']] ?? null;
    if ($buyerId === null || $sellerId === null) {
        continue;
    }

    $existing = Database::first(
        'SELECT id FROM opportunities WHERE owner_id = :owner AND title = :title',
        ['owner' => $buyerId, 'title' => $flow['title']]
    );
    if ($existing !== null) {
        out('· demo flow already seeded: ' . $flow['title']);
        continue;
    }

    $buyer = AuthService::requireUser($buyerId);
    $opportunityId = Id::make(match ($flow['vertical']) { 'ee' => 'ee-job', 'si' => 'si-raise', default => 'ab-job' });

    Database::insert('opportunities', [
        'id'                    => $opportunityId,
        'vertical'              => $flow['vertical'],
        'owner_id'              => $buyerId,
        'owner_name'            => $buyer['name'],
        'company'               => $buyer['company'] ?: $buyer['name'],
        'title'                 => $flow['title'],
        'category'              => $flow['category'],
        'subcategory'           => null,
        'industry'              => 'Technology',
        'description'           => 'Seeded demo opportunity for the PitchRooms live pitch flow. Replace before going live.',
        'requirements'          => null,
        'benefits'              => null,
        'budget'                => $flow['budget'],
        'budget_type'           => 'Fixed',
        'duration'              => '6 weeks',
        'location'              => 'Remote',
        'work_type'             => 'Live Pitch Room',
        'experience'            => 'Senior',
        'department'            => null,
        'job_type'              => null,
        'openings'              => 1,
        'deadline'              => Str::dbDate(time() + 14 * 86400),
        'expected_start_date'   => Str::dbDate(time() + 21 * 86400),
        'preferred_sellers'     => 'Top 5',
        'presentation_duration' => 30,
        'qa_duration'           => 30,
        'visibility'            => 'open',
        'featured'              => 1,
        'cover_file_id'         => null,
        'cover_url'             => null,
        'round'                 => $flow['vertical'] === 'si' ? 'Seed' : null,
        'amount'                => $flow['vertical'] === 'si' ? '$1.5M' : null,
        'valuation'             => $flow['vertical'] === 'si' ? '$12M post' : null,
        'stage'                 => $flow['vertical'] === 'si' ? 'Seed' : null,
        'use_of_funds'          => null,
        'deck_file_id'          => null,
        'status'                => 'Shortlisting',
        'event_id'              => null,
        'created_at'            => $now,
        'updated_at'            => $now,
        'deleted_at'            => null,
    ]);

    foreach ($flow['skills'] as $skill) {
        Database::statement(
            'INSERT IGNORE INTO opportunity_skills (opportunity_id, skill) VALUES (:id, :skill)',
            ['id' => $opportunityId, 'skill' => $skill]
        );
    }

    $seller = AuthService::requireUser($sellerId);
    $proposalId = Id::make(match ($flow['vertical']) { 'ee' => 'ee-app', 'si' => 'si-int', default => 'ab-pitch' });

    Database::insert('proposals', [
        'id'               => $proposalId,
        'opportunity_id'   => $opportunityId,
        'vertical'         => $flow['vertical'],
        'seller_id'        => $sellerId,
        'buyer_id'         => $buyerId,
        'seller_name'      => $seller['name'],
        'company'          => $seller['company'] ?: $seller['name'],
        'cover_letter'     => 'Seeded demo proposal, ready to present in the live pitch room.',
        'bid'              => $flow['budget'],
        'timeline_text'    => '6 weeks',
        'expected_salary'  => null,
        'notice_period'    => null,
        'resume_file_id'   => null,
        'deck_file_id'     => null,
        'note'             => null,
        'match_score'      => 88,
        'status'           => 'shortlisted',
        'rejection_reason' => null,
        'event_id'         => null,
        'created_at'       => $now,
        'updated_at'       => $now,
    ]);

    Database::insert('proposal_timeline', [
        'proposal_id' => $proposalId,
        'stage'       => 'shortlisted',
        'note'        => 'Seeded as shortlisted so the pitch room has a presenter.',
        'actor_id'    => $buyerId,
        'at'          => $now,
    ]);

    Database::upsert('shortlists', [
        'opportunity_id' => $opportunityId,
        'seller_id'      => $sellerId,
        'proposal_id'    => $proposalId,
        'position'       => 1,
        'match_score'    => 88,
        'locked_at'      => null,
        'created_by'     => $buyerId,
        'created_at'     => $now,
    ], ['proposal_id', 'position', 'match_score']);

    // A pitch room two days out, ready to join.
    $eventId = Id::make(match ($flow['vertical']) { 'ee' => 'ee-int', 'si' => 'si-meet', default => 'ab-meet' });
    $startAt = time() + 2 * 86400;

    Database::insert('events', [
        'id'                    => $eventId,
        'opportunity_id'        => $opportunityId,
        'vertical'              => $flow['vertical'],
        'buyer_id'              => $buyerId,
        'seller_id'             => $sellerId,
        'proposal_id'           => $proposalId,
        'name'                  => 'Live Pitch Room · ' . $flow['title'],
        'agenda'                => 'Intro, pitch, Q&A, and next steps.',
        'status'                => 'scheduled',
        'start_at'              => Str::dbDate($startAt),
        'end_at'                => Str::dbDate($startAt + 3600),
        'presentation_duration' => 30,
        'qa_duration'           => 30,
        'room_name'             => MeetingService::roomName($eventId),
        'meeting_code'          => MeetingService::generateMeetingCode(),
        'created_by'            => $buyerId,
        'accepted_by'           => $sellerId,
        'accepted_at'           => $now,
        'started_at'            => null,
        'ended_at'              => null,
        'cancelled_at'          => null,
        'cancel_reason'         => null,
        'decision'              => null,
        'recording_file_id'     => null,
        'created_at'            => $now,
        'updated_at'            => $now,
    ]);

    foreach ([[$buyerId, 'buyer'], [$sellerId, 'seller']] as [$participantId, $side]) {
        Database::insert('event_participants', [
            'event_id'           => $eventId,
            'user_id'            => $participantId,
            'role'               => (string) Database::scalar('SELECT role FROM users WHERE id = :id', ['id' => $participantId]),
            'side'               => $side,
            'slot_position'      => null,
            'fee_minor'          => 0,
            'currency'           => null,
            'rsvp_status'        => 'Confirmed',
            'joined_at'          => null,
            'left_at'            => null,
            'attendance_seconds' => 0,
            'created_at'         => $now,
        ]);
    }

    SlotService::rebuildAgenda($eventId);

    Database::update('opportunities', [
        'status'   => 'Event Scheduled',
        'event_id' => $eventId,
    ], 'id = :id', ['id' => $opportunityId]);

    Database::update('proposals', [
        'status'   => $flow['vertical'] === 'ee' ? 'interview_scheduled' : 'pitch_scheduled',
        'event_id' => $eventId,
    ], 'id = :id', ['id' => $proposalId]);

    out('✓ seeded ' . strtoupper($flow['vertical']) . ' flow: ' . $flow['title']);
}

out('');
out('Demo accounts (password: demo123, admin: admin123)');
foreach ($demoUsers as $demo) {
    out('  ' . str_pad($demo['role'], 9) . $demo['email']);
}
