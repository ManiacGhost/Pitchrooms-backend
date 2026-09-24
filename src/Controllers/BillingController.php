<?php

declare(strict_types=1);

namespace PitchRooms\Controllers;

use PitchRooms\Core\Database;
use PitchRooms\Core\Env;
use PitchRooms\Core\HttpException;
use PitchRooms\Core\Request;
use PitchRooms\Core\Response;
use PitchRooms\Services\ActivityLog;
use PitchRooms\Services\BillingService;
use PitchRooms\Support\Logger;
use PitchRooms\Support\Str;
use PitchRooms\Support\Validator;

final class BillingController
{
    /** GET /billing/plans */
    public function plans(Request $request): Response
    {
        return Response::json(BillingService::plans());
    }

    /** GET /billing/pass */
    public function pass(Request $request): Response
    {
        $user = $request->user();

        return Response::json([
            'pass'   => BillingService::presentPass(BillingService::activePass((string) $user['id'])),
            'access' => BillingService::access((string) $user['id'], (string) $user['role'], $request->string('eventId') ?: null),
        ]);
    }

    /** GET /billing/access — the single gate check the UI calls. */
    public function access(Request $request): Response
    {
        $user = $request->user();

        return Response::json(BillingService::access(
            (string) $user['id'],
            (string) $user['role'],
            $request->string('eventId') ?: null
        ));
    }

    /**
     * POST /billing/checkout
     * Creates an intent only. Nothing is granted until the webhook settles it.
     */
    public function checkout(Request $request): Response
    {
        $user = $request->user();
        $kind = $request->string('kind') ?: 'pass';

        if (!in_array($kind, ['pass', 'slot'], true)) {
            throw HttpException::badRequest('kind must be pass or slot.');
        }

        if ($kind === 'pass') {
            Validator::make($request, ['planId' => 'required']);
            $options = [
                'planId'  => $request->string('planId'),
                'eventId' => $request->string('eventId') ?: null,
            ];
        } else {
            Validator::make($request, [
                'eventId'  => 'required',
                'position' => 'required|integer|min:1',
            ]);

            $event = Database::first('SELECT vertical FROM events WHERE id = :id', ['id' => $request->string('eventId')]);
            if ($event === null) {
                throw HttpException::notFound('Meeting not found.');
            }

            $options = [
                'eventId'  => $request->string('eventId'),
                'position' => $request->int('position'),
                'vertical' => (string) $event['vertical'],
            ];
        }

        $checkout = BillingService::checkout($user, $kind, $options);

        ActivityLog::record((string) $user['id'], 'payment', $checkout['paymentId'], 'checkout:' . $kind, $options, $request);

        return Response::created($checkout);
    }

    /**
     * POST /billing/webhook — the only place a pass is granted.
     * Signature is verified before anything is read from the body.
     */
    public function webhook(Request $request): Response
    {
        $provider = Env::string('PAYMENT_PROVIDER', 'manual');
        $raw = file_get_contents('php://input') ?: '';

        if (!$this->verifySignature($provider, $raw, $request)) {
            Logger::warn('Rejected payment webhook: bad signature', ['provider' => $provider, 'ip' => $request->ip()]);
            throw HttpException::forbidden('Invalid webhook signature.', 'BAD_SIGNATURE');
        }

        $paymentId = $request->string('paymentId')
            ?: ($request->all()['data']['object']['metadata']['paymentId'] ?? '');
        $providerRef = $request->string('providerRef')
            ?: ($request->all()['data']['object']['id'] ?? null);

        if ($paymentId === '') {
            throw HttpException::badRequest('No paymentId in the webhook payload.');
        }

        $result = BillingService::settle($paymentId, $providerRef, ['webhookAt' => Str::iso()]);

        Logger::info('Payment settled', ['paymentId' => $paymentId, 'already' => $result['alreadySettled']]);

        return Response::json([
            'settled'        => true,
            'alreadySettled' => $result['alreadySettled'],
        ]);
    }

    /**
     * POST /billing/payments/{id}/settle
     * Admin-only manual settlement — bank transfers, and the path used while
     * PAYMENT_PROVIDER is still "manual".
     */
    public function settleManually(Request $request, array $params): Response
    {
        $result = BillingService::settle(
            (string) $params['id'],
            $request->string('providerRef') ?: 'manual-' . time(),
            ['settledBy' => $request->userId(), 'manual' => true]
        );

        ActivityLog::record((string) $request->userId(), 'payment', (string) $params['id'], 'settled_manually', [], $request);

        return Response::json($result);
    }

    /** POST /billing/pass/cancel */
    public function cancelPass(Request $request): Response
    {
        $pass = BillingService::activePass((string) $request->userId());
        if ($pass === null) {
            throw HttpException::notFound('You do not have an active pass.');
        }

        // Cancel = do not renew. Access continues to the paid-through date.
        Database::update('passes', [
            'status'       => 'cancelled',
            'cancelled_at' => Str::dbDate(),
        ], 'id = :id', ['id' => $pass['id']]);

        return Response::json([
            'cancelled'   => true,
            'accessUntil' => Str::toIso($pass['expires_at']),
        ]);
    }

    /** GET /billing/receipts */
    public function receipts(Request $request): Response
    {
        $rows = Database::select(
            'SELECT r.*, p.kind, p.plan_id, p.event_id, p.slot_position
             FROM receipts r JOIN payments p ON p.id = r.payment_id
             WHERE r.user_id = :user ORDER BY r.issued_at DESC LIMIT 100',
            ['user' => (string) $request->userId()]
        );

        return Response::json(array_map(static fn (array $row): array => [
            'id'             => $row['id'],
            'receiptNumber'  => $row['receipt_number'],
            'paymentId'      => $row['payment_id'],
            'description'    => $row['description'],
            'kind'           => $row['kind'],
            'planId'         => $row['plan_id'],
            'eventId'        => $row['event_id'],
            'slotPosition'   => $row['slot_position'],
            'amount'         => (int) $row['amount_minor'] / 100,
            'tax'            => (int) $row['tax_minor'] / 100,
            'total'          => (int) $row['total_minor'] / 100,
            'totalFormatted' => BillingService::formatMoney((int) $row['total_minor'], (string) $row['currency']),
            'currency'       => $row['currency'],
            'issuedAt'       => Str::toIso($row['issued_at']),
        ], $rows));
    }

    /** GET /billing/receipts/{id} */
    public function receipt(Request $request, array $params): Response
    {
        $row = Database::first(
            'SELECT r.*, p.kind, p.plan_id, p.event_id, p.slot_position, p.provider, p.provider_ref,
                    u.name, u.email, u.company
             FROM receipts r
             JOIN payments p ON p.id = r.payment_id
             JOIN users u ON u.id = r.user_id
             WHERE r.id = :id',
            ['id' => (string) $params['id']]
        );

        if ($row === null) {
            throw HttpException::notFound('Receipt not found.');
        }
        if ($row['user_id'] !== $request->userId() && !$request->isAdmin()) {
            throw HttpException::forbidden('That receipt is not yours.');
        }

        return Response::json([
            'id'            => $row['id'],
            'receiptNumber' => $row['receipt_number'],
            'billedTo'      => [
                'name'    => $row['name'],
                'email'   => $row['email'],
                'company' => $row['company'],
            ],
            'description'   => $row['description'],
            'amount'        => (int) $row['amount_minor'] / 100,
            'tax'           => (int) $row['tax_minor'] / 100,
            'total'         => (int) $row['total_minor'] / 100,
            'currency'      => $row['currency'],
            'provider'      => $row['provider'],
            'providerRef'   => $row['provider_ref'],
            'issuedAt'      => Str::toIso($row['issued_at']),
            'issuer'        => [
                'name' => Env::string('APP_NAME', 'PitchRooms'),
                'site' => Env::frontendDns(),
            ],
        ]);
    }

    /** GET /billing/payments — the caller's own payment history. */
    public function payments(Request $request): Response
    {
        $rows = Database::select(
            'SELECT * FROM payments WHERE user_id = :user ORDER BY created_at DESC LIMIT 100',
            ['user' => (string) $request->userId()]
        );

        return Response::json(array_map(static fn (array $row): array => [
            'id'           => $row['id'],
            'kind'         => $row['kind'],
            'planId'       => $row['plan_id'],
            'eventId'      => $row['event_id'],
            'slotPosition' => $row['slot_position'],
            'amount'       => (int) $row['amount_minor'] / 100,
            'currency'     => $row['currency'],
            'status'       => $row['status'],
            'provider'     => $row['provider'],
            'createdAt'    => Str::toIso($row['created_at']),
        ], $rows));
    }

    private function verifySignature(string $provider, string $raw, Request $request): bool
    {
        if ($provider === 'stripe') {
            $secret = Env::string('STRIPE_WEBHOOK_SECRET', '');
            $header = (string) $request->header('stripe-signature', '');
            if ($secret === '' || $header === '') {
                return false;
            }

            $timestamp = null;
            $signatures = [];
            foreach (explode(',', $header) as $part) {
                [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');
                if ($key === 't') {
                    $timestamp = $value;
                } elseif ($key === 'v1') {
                    $signatures[] = $value;
                }
            }
            if ($timestamp === null || $signatures === []) {
                return false;
            }
            // Reject replays of old events.
            if (abs(time() - (int) $timestamp) > 300) {
                return false;
            }

            $expected = hash_hmac('sha256', $timestamp . '.' . $raw, $secret);
            foreach ($signatures as $signature) {
                if (hash_equals($expected, $signature)) {
                    return true;
                }
            }

            return false;
        }

        if ($provider === 'razorpay') {
            $secret = Env::string('RAZORPAY_WEBHOOK_SECRET', '');
            $header = (string) $request->header('x-razorpay-signature', '');
            if ($secret === '' || $header === '') {
                return false;
            }

            return hash_equals(hash_hmac('sha256', $raw, $secret), $header);
        }

        // manual: the scheduler key doubles as the shared secret so the
        // endpoint is never open while a real PSP is not wired up yet.
        $key = Env::string('SCHEDULER_KEY', '');

        return $key !== '' && hash_equals($key, (string) $request->header('x-webhook-key', ''));
    }
}
