<?php

declare(strict_types=1);

namespace PitchRooms\Services;

use PitchRooms\Core\Database;
use PitchRooms\Core\Env;
use PitchRooms\Core\HttpException;
use PitchRooms\Support\Id;
use PitchRooms\Support\Str;

/**
 * Seller passes and premium pitch slots.
 *
 * The frontend used to grant a pass by writing to localStorage. Here a pass
 * only becomes active through a settled payment — the checkout call just
 * creates an intent, and the webhook (or an admin/manual settle) activates it.
 */
final class BillingService
{
    public static function plans(): array
    {
        $rows = Database::select('SELECT * FROM plans WHERE active = 1 ORDER BY sort_order ASC');

        return array_map(static fn (array $row): array => [
            'id'             => $row['id'],
            'name'           => $row['name'],
            'tag'            => $row['tag'],
            'badge'          => $row['badge'],
            'description'    => $row['description'],
            'price'          => (int) $row['price_minor'] / 100,
            'priceMinor'     => (int) $row['price_minor'],
            'priceFormatted' => self::formatMoney((int) $row['price_minor'], (string) $row['currency']),
            'currency'       => $row['currency'],
            'period'         => $row['period'],
            'durationDays'   => (int) $row['duration_days'],
            'unlimited'      => (bool) $row['unlimited'],
            'features'       => Str::fromJson($row['features'] ?? null, []),
        ], $rows);
    }

    public static function plan(string $planId): array
    {
        $plan = Database::first('SELECT * FROM plans WHERE id = :id AND active = 1', ['id' => $planId]);
        if ($plan === null) {
            throw HttpException::notFound('That plan is not available.');
        }
        return $plan;
    }

    public static function activePass(string $userId): ?array
    {
        return Database::first(
            "SELECT * FROM passes
             WHERE user_id = :user AND status = 'active'
               AND (expires_at IS NULL OR expires_at > UTC_TIMESTAMP())
             ORDER BY expires_at DESC LIMIT 1",
            ['user' => $userId]
        );
    }

    /**
     * The one gate used by the room and by the pitch-submit button.
     * Buyers never pay to attend; sellers need an unlimited pass or a single
     * event pass that covers this specific event.
     */
    public static function access(string $userId, string $role, ?string $eventId = null): array
    {
        if (AuthService::isBuyer($role)) {
            return ['hasAccess' => true, 'reason' => 'buyer_role', 'pass' => null];
        }

        $pass = self::activePass($userId);
        if ($pass === null) {
            return ['hasAccess' => false, 'reason' => 'no_pass', 'pass' => null];
        }

        if ((int) $pass['unlimited'] === 1) {
            return ['hasAccess' => true, 'reason' => 'unlimited_pass', 'pass' => self::presentPass($pass)];
        }

        if ($eventId === null) {
            return ['hasAccess' => false, 'reason' => 'event_required', 'pass' => self::presentPass($pass)];
        }

        $covered = (int) Database::scalar(
            'SELECT COUNT(*) FROM pass_events WHERE pass_id = :pass AND event_id = :event',
            ['pass' => $pass['id'], 'event' => $eventId]
        );

        return $covered > 0
            ? ['hasAccess' => true, 'reason' => 'single_event_pass', 'pass' => self::presentPass($pass)]
            : ['hasAccess' => false, 'reason' => 'event_not_covered', 'pass' => self::presentPass($pass)];
    }

    public static function assertAccess(string $userId, string $role, ?string $eventId = null): void
    {
        $access = self::access($userId, $role, $eventId);
        if (!$access['hasAccess']) {
            throw HttpException::forbidden(
                'An active seller pass is required to enter the live pitch room.',
                'PASS_REQUIRED'
            );
        }
    }

    /** Creates a pending payment; nothing is granted until it settles. */
    public static function checkout(array $user, string $kind, array $options = []): array
    {
        $provider = Env::string('PAYMENT_PROVIDER', 'manual');
        $paymentId = Id::make('pay');

        if ($kind === 'pass') {
            $plan = self::plan((string) $options['planId']);
            $amountMinor = (int) $plan['price_minor'];
            $currency = (string) $plan['currency'];
            $planId = (string) $plan['id'];
            $eventId = $options['eventId'] ?? null;
            $slotPosition = null;

            if ((int) $plan['unlimited'] === 0 && empty($eventId)) {
                throw HttpException::badRequest('A single event pass needs an eventId.', 'EVENT_REQUIRED');
            }
        } else {
            $slot = self::slot((string) $options['vertical'], (int) $options['position']);
            $amountMinor = (int) $slot['fee_minor'];
            $currency = (string) $slot['currency'];
            $planId = null;
            $eventId = (string) $options['eventId'];
            $slotPosition = (int) $options['position'];

            if ($amountMinor <= 0) {
                throw HttpException::badRequest('That position is included at no cost — claim it directly.', 'FREE_SLOT');
            }
        }

        Database::insert('payments', [
            'id'            => $paymentId,
            'user_id'       => $user['id'],
            'provider'      => $provider,
            'provider_ref'  => null,
            'kind'          => $kind,
            'plan_id'       => $planId,
            'event_id'      => $eventId,
            'slot_position' => $slotPosition,
            'amount_minor'  => $amountMinor,
            'currency'      => $currency,
            'status'        => 'created',
            'metadata'      => Str::json($options),
            'created_at'    => Str::dbDate(),
            'updated_at'    => Str::dbDate(),
        ]);

        return [
            'paymentId'      => $paymentId,
            'provider'       => $provider,
            'amountMinor'    => $amountMinor,
            'amount'         => $amountMinor / 100,
            'amountFormatted'=> self::formatMoney($amountMinor, $currency),
            'currency'       => $currency,
            'kind'           => $kind,
            'status'         => 'created',
            // The client hands this to the PSP SDK. Card data never reaches us.
            'checkoutUrl'    => null,
            'clientSecret'   => null,
            'webhookUrl'     => Env::url('/billing/webhook'),
        ];
    }

    /**
     * Settle a payment and grant what it paid for. Idempotent: replaying a
     * webhook for an already-paid payment changes nothing.
     */
    public static function settle(string $paymentId, ?string $providerRef = null, array $metadata = []): array
    {
        return Database::transaction(static function () use ($paymentId, $providerRef, $metadata): array {
            $payment = Database::first('SELECT * FROM payments WHERE id = :id FOR UPDATE', ['id' => $paymentId]);
            if ($payment === null) {
                throw HttpException::notFound('Payment not found.');
            }
            if ($payment['status'] === 'paid') {
                return ['payment' => $payment, 'alreadySettled' => true];
            }

            Database::update('payments', [
                'status'       => 'paid',
                'provider_ref' => $providerRef,
                'metadata'     => Str::json(array_merge(Str::fromJson($payment['metadata'] ?? null, []), $metadata)),
                'updated_at'   => Str::dbDate(),
            ], 'id = :id', ['id' => $paymentId]);

            $granted = $payment['kind'] === 'pass'
                ? self::grantPass($payment)
                : SlotService::assign(
                    (string) $payment['event_id'],
                    (string) $payment['user_id'],
                    (int) $payment['slot_position'],
                    (string) $payment['id']
                );

            $receipt = self::issueReceipt($payment);

            return ['payment' => $payment, 'granted' => $granted, 'receipt' => $receipt, 'alreadySettled' => false];
        });
    }

    private static function grantPass(array $payment): array
    {
        $plan = self::plan((string) $payment['plan_id']);
        $durationDays = (int) $plan['duration_days'];
        $unlimited = (int) $plan['unlimited'] === 1;

        $existing = self::activePass((string) $payment['user_id']);

        // Renewing an unlimited pass extends it instead of stacking a new one.
        if ($existing !== null && $unlimited && (int) $existing['unlimited'] === 1) {
            $base = $existing['expires_at'] ? strtotime((string) $existing['expires_at'] . ' UTC') : time();
            $expiresAt = max(time(), (int) $base) + $durationDays * 86400;

            Database::update('passes', [
                'expires_at' => Str::dbDate($expiresAt),
                'payment_id' => $payment['id'],
            ], 'id = :id', ['id' => $existing['id']]);

            return ['passId' => $existing['id'], 'extended' => true, 'expiresAt' => Str::iso($expiresAt)];
        }

        $passId = Id::make('pass');
        $expiresAt = time() + $durationDays * 86400;

        Database::insert('passes', [
            'id'           => $passId,
            'user_id'      => $payment['user_id'],
            'plan_id'      => $plan['id'],
            'payment_id'   => $payment['id'],
            'status'       => 'active',
            'price_minor'  => $payment['amount_minor'],
            'currency'     => $payment['currency'],
            'unlimited'    => $unlimited ? 1 : 0,
            'purchased_at' => Str::dbDate(),
            'expires_at'   => Str::dbDate($expiresAt),
            'cancelled_at' => null,
            'created_at'   => Str::dbDate(),
        ]);

        if (!$unlimited && !empty($payment['event_id'])) {
            Database::statement(
                'INSERT IGNORE INTO pass_events (pass_id, event_id) VALUES (:pass, :event)',
                ['pass' => $passId, 'event' => $payment['event_id']]
            );
        }

        NotificationService::push(
            (string) $payment['user_id'],
            'payment',
            $unlimited ? 'All-Access Pass active' : 'Event pass confirmed',
            $unlimited
                ? 'Your monthly all-access pass is active. Pitch in unlimited rooms for the next ' . $durationDays . ' days.'
                : 'Your single event pass is confirmed. You can now enter the live pitch room.',
            '/billing/receipts',
            'View receipt',
            'pass',
            $passId,
            true
        );

        return ['passId' => $passId, 'extended' => false, 'expiresAt' => Str::iso($expiresAt)];
    }

    private static function issueReceipt(array $payment): array
    {
        $amount = (int) $payment['amount_minor'];
        // 18% GST on INR-denominated line items; USD passes are billed net.
        $tax = strtoupper((string) $payment['currency']) === 'INR' ? (int) round($amount * 0.18) : 0;

        $receiptId = Id::make('rcpt');
        $number = 'PR-INV-' . strtoupper(Id::code(8));

        Database::insert('receipts', [
            'id'             => $receiptId,
            'payment_id'     => $payment['id'],
            'user_id'        => $payment['user_id'],
            'receipt_number' => $number,
            'description'    => $payment['kind'] === 'pass'
                ? 'PitchRooms seller pass'
                : 'Premium pitch position #' . $payment['slot_position'],
            'amount_minor'   => $amount,
            'tax_minor'      => $tax,
            'total_minor'    => $amount + $tax,
            'currency'       => $payment['currency'],
            'issued_at'      => Str::dbDate(),
            'pdf_file_id'    => null,
        ]);

        return ['receiptId' => $receiptId, 'receiptNumber' => $number];
    }

    public static function slot(string $vertical, int $position): array
    {
        $slot = Database::first(
            'SELECT * FROM slot_pricing WHERE vertical = :vertical AND position = :position AND active = 1',
            ['vertical' => $vertical, 'position' => $position]
        );

        if ($slot === null) {
            throw HttpException::notFound('That pitch position does not exist for this vertical.');
        }

        return $slot;
    }

    public static function presentPass(?array $pass): ?array
    {
        if ($pass === null) {
            return null;
        }

        $expiresAt = $pass['expires_at'] ? strtotime((string) $pass['expires_at'] . ' UTC') : null;
        $secondsLeft = $expiresAt === null ? null : max(0, $expiresAt - time());

        return [
            'id'          => $pass['id'],
            'planId'      => $pass['plan_id'],
            'status'      => $pass['status'],
            'unlimited'   => (bool) $pass['unlimited'],
            'price'       => (int) $pass['price_minor'] / 100,
            'currency'    => $pass['currency'],
            'purchasedAt' => Str::toIso($pass['purchased_at']),
            'expiresAt'   => Str::toIso($pass['expires_at']),
            'daysLeft'    => $secondsLeft === null ? null : (int) ceil($secondsLeft / 86400),
            'hoursLeft'   => $secondsLeft === null ? null : (int) ceil($secondsLeft / 3600),
            'isExpired'   => $expiresAt !== null && $expiresAt <= time(),
            'coveredEventIds' => (int) $pass['unlimited'] === 1
                ? ['*']
                : array_column(
                    Database::select('SELECT event_id FROM pass_events WHERE pass_id = :pass', ['pass' => $pass['id']]),
                    'event_id'
                ),
        ];
    }

    public static function formatMoney(int $minor, string $currency): string
    {
        $symbols = ['USD' => '$', 'INR' => '₹', 'EUR' => '€', 'GBP' => '£'];
        $symbol = $symbols[strtoupper($currency)] ?? ($currency . ' ');
        $amount = $minor / 100;

        return $symbol . number_format($amount, fmod($amount, 1.0) === 0.0 ? 0 : 2);
    }
}
