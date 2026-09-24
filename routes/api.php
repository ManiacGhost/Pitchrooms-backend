<?php

declare(strict_types=1);

use PitchRooms\Controllers\AdminController;
use PitchRooms\Controllers\AuthController;
use PitchRooms\Controllers\BillingController;
use PitchRooms\Controllers\EvaluationController;
use PitchRooms\Controllers\EventController;
use PitchRooms\Controllers\FileController;
use PitchRooms\Controllers\MeetingController;
use PitchRooms\Controllers\NotificationController;
use PitchRooms\Controllers\OpportunityController;
use PitchRooms\Controllers\ProfileController;
use PitchRooms\Controllers\ProposalController;
use PitchRooms\Controllers\SystemController;
use PitchRooms\Controllers\ThreadController;
use PitchRooms\Core\Router;

/**
 * All routes sit under API_PREFIX (default /api/v1).
 * Middleware: 'auth' (required), 'optional' (public but user-aware),
 * 'role:a,b' (admins always pass).
 */
return static function (Router $router): void {

    // ---------------------------------------------------------------- public
    $router->get('/health', [SystemController::class, 'health']);
    $router->get('/meta/taxonomies', [SystemController::class, 'taxonomies']);
    $router->get('/meta/statuses', [SystemController::class, 'statuses']);

    $router->post('/auth/register', [AuthController::class, 'register']);
    $router->post('/auth/login', [AuthController::class, 'login']);
    $router->post('/auth/refresh', [AuthController::class, 'refresh']);
    $router->get('/auth/check-email', [AuthController::class, 'checkEmail']);
    $router->post('/auth/password/forgot', [AuthController::class, 'forgotPassword']);
    $router->post('/auth/password/reset', [AuthController::class, 'resetPassword']);

    // Payment provider callback — authenticated by signature, not by a token.
    $router->post('/billing/webhook', [BillingController::class, 'webhook']);

    // Scheduler HTTP trigger — authenticated by X-Scheduler-Key.
    $router->post('/internal/scheduler/tick', [SystemController::class, 'schedulerTick']);

    // Browsing the marketplace works signed out; 'optional' still resolves the
    // caller when a token is present, so "already applied" state comes through.
    $router->get('/opportunities', [OpportunityController::class, 'index'], ['optional']);
    $router->get('/opportunities/{id}', [OpportunityController::class, 'show'], ['optional']);
    $router->get('/files/{id}', [FileController::class, 'show'], ['optional']);

    // ------------------------------------------------------------ authed core
    $router->group(['auth'], static function (Router $router): void {

        // account
        $router->post('/auth/logout', [AuthController::class, 'logout']);
        $router->get('/auth/me', [AuthController::class, 'me']);
        $router->patch('/auth/me', [AuthController::class, 'updateMe']);
        $router->post('/auth/password/change', [AuthController::class, 'changePassword']);

        // profiles & directories
        $router->get('/profiles/me', [ProfileController::class, 'me']);
        $router->write('/profiles/me', [ProfileController::class, 'updateMe']);
        $router->get('/profiles/{type}/{id}', [ProfileController::class, 'show']);
        $router->write('/profiles/{type}/{id}', [ProfileController::class, 'update']);
        $router->get('/profiles/{type}/{id}/trust-score', [ProfileController::class, 'trustScore']);
        $router->get('/directory/{type}', [ProfileController::class, 'directory']);

        // opportunities
        $router->post('/opportunities', [OpportunityController::class, 'store']);
        $router->write('/opportunities/{id}', [OpportunityController::class, 'update']);
        $router->delete('/opportunities/{id}', [OpportunityController::class, 'destroy']);
        $router->post('/opportunities/{id}/submit', [OpportunityController::class, 'submit']);
        $router->post('/opportunities/{id}/status', [OpportunityController::class, 'setStatusEndpoint']);
        $router->post('/opportunities/{id}/close', [OpportunityController::class, 'close']);
        $router->get('/opportunities/{id}/ranking', [OpportunityController::class, 'ranking']);
        $router->get('/opportunities/{id}/timeline', [OpportunityController::class, 'timeline']);

        // proposals
        $router->get('/proposals', [ProposalController::class, 'index']);
        $router->post('/proposals', [ProposalController::class, 'store']);
        $router->get('/proposals/{id}', [ProposalController::class, 'show']);
        $router->patch('/proposals/{id}', [ProposalController::class, 'update']);
        $router->post('/proposals/{id}/status', [ProposalController::class, 'setStatus']);
        $router->post('/proposals/{id}/withdraw', [ProposalController::class, 'withdraw']);
        $router->post('/proposals/{id}/reject', [ProposalController::class, 'reject']);

        // shortlisting
        $router->post('/opportunities/{id}/review', [ProposalController::class, 'bulkReview']);
        $router->get('/opportunities/{id}/shortlist', [ProposalController::class, 'shortlist']);
        $router->post('/opportunities/{id}/shortlist/auto', [ProposalController::class, 'autoShortlist']);
        $router->post('/opportunities/{id}/shortlist/confirm', [ProposalController::class, 'confirmShortlist']);
        $router->post('/opportunities/{id}/shortlist/{sellerId}', [ProposalController::class, 'shortlistSeller']);
        $router->delete('/opportunities/{id}/shortlist/{sellerId}', [ProposalController::class, 'removeFromShortlist']);

        // events & scheduling
        $router->get('/events', [EventController::class, 'index']);
        $router->post('/events', [EventController::class, 'store']);
        $router->get('/calendar', [EventController::class, 'calendar']);
        $router->get('/events/{id}', [EventController::class, 'show']);
        $router->post('/events/{id}/accept', [EventController::class, 'accept']);
        $router->post('/events/{id}/reschedule', [EventController::class, 'reschedule']);
        $router->post('/events/{id}/cancel', [EventController::class, 'cancel']);
        $router->post('/events/{id}/status', [EventController::class, 'setStatus']);
        $router->get('/events/{id}/agenda', [EventController::class, 'agenda']);
        $router->post('/events/{id}/agenda/reorder', [EventController::class, 'reorderAgenda']);

        // room entry gate
        $router->get('/events/{id}/access', [MeetingController::class, 'accessState']);
        $router->post('/events/{id}/access/code', [MeetingController::class, 'verifyCode']);
        $router->post('/events/{id}/access/otp/{channel}', [MeetingController::class, 'sendOtp']);
        $router->post('/events/{id}/access/otp/{channel}/verify', [MeetingController::class, 'verifyOtp']);
        $router->post('/events/{id}/access/device', [MeetingController::class, 'registerDevice']);
        $router->post('/events/{id}/access/grant', [MeetingController::class, 'grantAccess']);
        $router->post('/events/{id}/access/revoke', [MeetingController::class, 'revokeAccess']);

        // premium pitch positions
        $router->get('/events/{id}/slots', [MeetingController::class, 'slots']);
        $router->post('/events/{id}/slots/{position}/claim', [MeetingController::class, 'claimSlot']);

        // live room
        $router->post('/meetings/{id}/token', [MeetingController::class, 'token']);
        $router->post('/meetings/{id}/join', [MeetingController::class, 'join']);
        $router->post('/meetings/{id}/leave', [MeetingController::class, 'leave']);
        $router->get('/meetings/{id}/participants', [MeetingController::class, 'participants']);
        $router->get('/meetings/{id}/state', [MeetingController::class, 'state']);
        $router->post('/meetings/{id}/stage/advance', [MeetingController::class, 'advanceStage']);
        $router->post('/meetings/{id}/stage/extend', [MeetingController::class, 'extendStage']);
        $router->get('/meetings/{id}/chat', [MeetingController::class, 'chat']);
        $router->post('/meetings/{id}/chat', [MeetingController::class, 'sendChat']);
        $router->get('/meetings/{id}/questions', [MeetingController::class, 'questions']);
        $router->post('/meetings/{id}/questions', [MeetingController::class, 'askQuestion']);
        $router->post('/meetings/{id}/questions/{questionId}/answer', [MeetingController::class, 'answerQuestion']);
        $router->post('/meetings/{id}/questions/{questionId}/upvote', [MeetingController::class, 'upvoteQuestion']);

        // evaluation, decisions, reputation
        $router->get('/events/{id}/evaluations', [EvaluationController::class, 'index']);
        $router->post('/events/{id}/evaluations', [EvaluationController::class, 'store']);
        $router->get('/events/{id}/leaderboard', [EvaluationController::class, 'leaderboard']);
        $router->get('/events/{id}/decision', [EvaluationController::class, 'decision']);
        $router->post('/events/{id}/decision', [EvaluationController::class, 'decide']);
        $router->post('/events/{id}/followup', [EvaluationController::class, 'followUp']);
        $router->get('/ratings', [EvaluationController::class, 'ratings']);
        $router->post('/ratings', [EvaluationController::class, 'rate']);
        $router->get('/feedback', [EvaluationController::class, 'feedback']);
        $router->post('/feedback', [EvaluationController::class, 'giveFeedback']);
        $router->get('/investments', [EvaluationController::class, 'investments']);
        $router->post('/investments', [EvaluationController::class, 'invest']);

        // messaging — gated: unlocks only after a completed pitch meeting
        $router->get('/threads', [ThreadController::class, 'index']);
        $router->post('/threads', [ThreadController::class, 'store']);
        $router->get('/threads/{id}', [ThreadController::class, 'show']);
        $router->get('/threads/{id}/messages', [ThreadController::class, 'messages']);
        $router->post('/threads/{id}/messages', [ThreadController::class, 'send']);
        $router->get('/threads/{id}/messages/stream', [ThreadController::class, 'stream']);
        $router->post('/threads/{id}/read', [ThreadController::class, 'markRead']);
        $router->post('/threads/{id}/{flag}', [ThreadController::class, 'toggleFlag']);

        // notifications & realtime
        $router->get('/notifications', [NotificationController::class, 'index']);
        $router->get('/notifications/unread-count', [NotificationController::class, 'unreadCount']);
        $router->get('/notifications/preferences', [NotificationController::class, 'preferences']);
        $router->put('/notifications/preferences', [NotificationController::class, 'updatePreferences']);
        $router->post('/notifications/read-all', [NotificationController::class, 'markAllRead']);
        $router->post('/notifications/{id}/read', [NotificationController::class, 'markRead']);
        $router->delete('/notifications/{id}', [NotificationController::class, 'destroy']);
        $router->get('/realtime/poll', [NotificationController::class, 'poll']);

        // billing
        $router->get('/billing/plans', [BillingController::class, 'plans']);
        $router->get('/billing/pass', [BillingController::class, 'pass']);
        $router->get('/billing/access', [BillingController::class, 'access']);
        $router->post('/billing/checkout', [BillingController::class, 'checkout']);
        $router->post('/billing/pass/cancel', [BillingController::class, 'cancelPass']);
        $router->get('/billing/receipts', [BillingController::class, 'receipts']);
        $router->get('/billing/receipts/{id}', [BillingController::class, 'receipt']);
        $router->get('/billing/payments', [BillingController::class, 'payments']);

        // files
        $router->post('/files', [FileController::class, 'store']);
        $router->get('/files/{id}/meta', [FileController::class, 'meta']);
        $router->delete('/files/{id}', [FileController::class, 'destroy']);

        // dashboards & search
        $router->get('/dashboard', [SystemController::class, 'dashboard']);
        $router->get('/search', [SystemController::class, 'search']);
    });

    // ----------------------------------------------------------------- admin
    $router->group(['auth', 'role:admin'], static function (Router $router): void {
        $router->get('/admin/stats', [AdminController::class, 'stats']);
        $router->get('/admin/users', [AdminController::class, 'users']);
        $router->patch('/admin/users/{id}', [AdminController::class, 'updateUser']);
        $router->post('/admin/users/{id}/status', [AdminController::class, 'setAccountStatus']);
        $router->get('/admin/verification/queue', [AdminController::class, 'verificationQueue']);
        $router->post('/admin/verification/{userId}', [AdminController::class, 'setVerification']);
        $router->post('/admin/opportunities/{id}/approve', [AdminController::class, 'approveOpportunity']);
        $router->post('/admin/opportunities/{id}/reject', [AdminController::class, 'rejectOpportunity']);
        $router->get('/admin/pitch-rooms', [AdminController::class, 'pitchRooms']);
        $router->get('/admin/reports', [AdminController::class, 'reports']);
        $router->get('/admin/activity-log', [AdminController::class, 'activityLog']);
        $router->get('/admin/billing/transactions', [AdminController::class, 'transactions']);
        $router->post('/admin/billing/payments/{id}/settle', [BillingController::class, 'settleManually']);
        $router->get('/admin/settings', [AdminController::class, 'settings']);
        $router->put('/admin/settings', [AdminController::class, 'updateSettings']);
        $router->put('/admin/slot-pricing', [AdminController::class, 'updateSlotPricing']);
        $router->get('/internal/scheduler/status', [SystemController::class, 'schedulerStatus']);
        $router->post('/internal/scheduler/jobs', [SystemController::class, 'queueJob']);
    });
};
