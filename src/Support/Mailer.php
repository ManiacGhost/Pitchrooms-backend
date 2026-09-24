<?php

declare(strict_types=1);

namespace PitchRooms\Support;

use PitchRooms\Core\Env;
use PitchRooms\Core\Paths;
use RuntimeException;
use Throwable;

/**
 * Mail and SMS.
 *
 * Drivers:
 *   log        — writes to storage/logs (default for local and staging)
 *   brevo      — Brevo's SMTP relay, using an SMTP key (dependency-free client)
 *   brevo_api  — Brevo's HTTP API; use this when the host blocks outbound SMTP
 *   smtp       — any other SMTP server
 *   mail       — PHP's mail(), a last resort with no auth
 *   none       — silently drop everything
 *
 * Every link inside a message is built from FRONTEND_DNS / APP_DNS, so moving
 * environments never leaves a stale localhost link in someone's inbox.
 */
final class Mailer
{
    private const BREVO_SMTP_HOST = 'smtp-relay.brevo.com';
    private const BREVO_API_URL = 'https://api.brevo.com/v3/smtp/email';
    private const BREVO_SMS_URL = 'https://api.brevo.com/v3/transactionalSMS/sms';

    /**
     * Sends one transactional email.
     *
     * Returns false rather than throwing: a failed notification email must
     * never break the API call that triggered it. Failures are logged, and
     * the message body is written to the log so nothing is lost silently.
     */
    public static function send(string $to, string $subject, string $body, array $context = []): bool
    {
        $driver = strtolower(Env::string('MAIL_DRIVER', 'log'));

        if ($driver === 'none') {
            return true;
        }

        $to = trim($to);
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            Logger::warn('Mail skipped: invalid recipient', ['to' => $to, 'subject' => $subject]);
            return false;
        }

        if ($driver === 'log') {
            self::log('MAIL', $to, $subject, $body, $context);
            return true;
        }

        $html = self::wrap($subject, $body);
        $from = [
            'email' => Env::string('MAIL_FROM', 'no-reply@pitchrooms.com'),
            'name'  => Env::string('MAIL_FROM_NAME', 'PitchRooms'),
        ];
        $replyTo = Env::string('MAIL_REPLY_TO', '') ?: null;

        try {
            $sent = match ($driver) {
                'brevo_api', 'brevoapi', 'api' => self::sendViaBrevoApi($from, $to, $subject, $html, $replyTo),
                'brevo', 'smtp'                => self::sendViaSmtp($from, $to, $subject, $html, $replyTo, $driver),
                'mail'                         => self::sendViaPhpMail($from, $to, $subject, $html, $replyTo),
                default => throw new RuntimeException('Unknown MAIL_DRIVER: ' . $driver),
            };

            if ($sent) {
                return true;
            }

            throw new RuntimeException('The mail driver reported failure.');
        } catch (Throwable $e) {
            // Keep the message recoverable from the log, and say why it failed.
            Logger::error('Mail send failed', [
                'driver'  => $driver,
                'to'      => $to,
                'subject' => $subject,
                'error'   => $e->getMessage(),
            ]);
            self::log('MAIL-FAILED', $to, $subject, $body, $context + ['error' => $e->getMessage()]);

            return false;
        }
    }

    /** Brevo SMTP relay (or any SMTP server) via the built-in client. */
    private static function sendViaSmtp(array $from, string $to, string $subject, string $html, ?string $replyTo, string $driver): bool
    {
        $isBrevo = $driver === 'brevo';

        $host = Env::string('SMTP_HOST', $isBrevo ? self::BREVO_SMTP_HOST : '');
        $user = Env::string('SMTP_USER', '');
        $pass = Env::string('SMTP_PASS', '');

        if ($host === '') {
            throw new RuntimeException('SMTP_HOST is not set.');
        }
        if ($user === '' || $pass === '') {
            throw new RuntimeException(
                $isBrevo
                    ? 'Set SMTP_USER to your Brevo login email and SMTP_PASS to your Brevo SMTP key.'
                    : 'SMTP_USER and SMTP_PASS are required.'
            );
        }

        $smtp = new Smtp(
            $host,
            Env::int('SMTP_PORT', 587),
            $user,
            $pass,
            Env::string('SMTP_SECURE', 'tls'),
            Env::int('SMTP_TIMEOUT', 20)
        );

        return $smtp->send($from, [$to], $subject, $html, '', $replyTo);
    }

    /**
     * Brevo's HTTP API. Preferred on hosts that block port 587 outbound —
     * uses an API key (xkeysib-…), not the SMTP key.
     */
    private static function sendViaBrevoApi(array $from, string $to, string $subject, string $html, ?string $replyTo): bool
    {
        $apiKey = Env::string('BREVO_API_KEY', '');
        if ($apiKey === '') {
            throw new RuntimeException('BREVO_API_KEY is required for the brevo_api driver (an xkeysib-… key from Brevo > SMTP & API > API keys).');
        }

        $payload = [
            'sender'      => ['email' => $from['email'], 'name' => $from['name']],
            'to'          => [['email' => $to]],
            'subject'     => $subject,
            'htmlContent' => $html,
        ];

        if ($replyTo !== null) {
            $payload['replyTo'] = ['email' => $replyTo];
        }

        [$status, $response] = self::httpPost(self::BREVO_API_URL, $payload, [
            'api-key: ' . $apiKey,
            'content-type: application/json',
            'accept: application/json',
        ]);

        if ($status >= 200 && $status < 300) {
            return true;
        }

        throw new RuntimeException(sprintf('Brevo API returned %d: %s', $status, self::brevoError($response)));
    }

    private static function sendViaPhpMail(array $from, string $to, string $subject, string $html, ?string $replyTo): bool
    {
        $headers = [
            'From: ' . sprintf('%s <%s>', $from['name'], $from['email']),
            'Reply-To: ' . ($replyTo ?? $from['email']),
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'X-Mailer: PitchRooms',
        ];

        return @mail($to, $subject, $html, implode("\r\n", $headers));
    }

    // ------------------------------------------------------------------- SMS

    /**
     * Transactional SMS — the meeting entry gate's mobile OTP depends on this.
     * Drivers: log (default), brevo, none.
     */
    public static function sms(string $to, string $message): bool
    {
        $driver = strtolower(Env::string('SMS_DRIVER', 'log'));

        if ($driver === 'none') {
            return true;
        }
        if ($driver === 'log') {
            self::log('SMS', $to, 'SMS', $message, []);
            return true;
        }

        try {
            if ($driver !== 'brevo') {
                throw new RuntimeException('Unknown SMS_DRIVER: ' . $driver);
            }

            $apiKey = Env::string('BREVO_API_KEY', '');
            if ($apiKey === '') {
                throw new RuntimeException('BREVO_API_KEY is required to send SMS through Brevo.');
            }

            // Brevo wants E.164 with no spaces or punctuation.
            $recipient = preg_replace('/[^0-9+]/', '', $to) ?? '';
            if (!str_starts_with($recipient, '+')) {
                throw new RuntimeException('SMS recipient must be in international format, e.g. +919876543210.');
            }

            [$status, $response] = self::httpPost(self::BREVO_SMS_URL, [
                'type'      => 'transactional',
                'sender'    => Env::string('SMS_FROM', 'PITCHR'),
                'recipient' => $recipient,
                'content'   => $message,
            ], [
                'api-key: ' . $apiKey,
                'content-type: application/json',
                'accept: application/json',
            ]);

            if ($status >= 200 && $status < 300) {
                return true;
            }

            throw new RuntimeException(sprintf('Brevo SMS API returned %d: %s', $status, self::brevoError($response)));
        } catch (Throwable $e) {
            Logger::error('SMS send failed', ['to' => $to, 'error' => $e->getMessage()]);
            self::log('SMS-FAILED', $to, 'SMS', $message, ['error' => $e->getMessage()]);

            return false;
        }
    }

    // --------------------------------------------------------------- helpers

    /** @return array{0:int, 1:string} [status, body] */
    private static function httpPost(string $url, array $payload, array $headers): array
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('The cURL extension is required for the Brevo API drivers.');
        }

        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => Env::int('HTTP_TIMEOUT', 15),
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $error = curl_error($handle);
        curl_close($handle);

        if ($body === false) {
            throw new RuntimeException('HTTP request failed: ' . ($error !== '' ? $error : 'unknown error'));
        }

        return [$status, (string) $body];
    }

    /** Brevo returns {code, message}; fall back to the raw body. */
    private static function brevoError(string $response): string
    {
        $decoded = json_decode($response, true);
        if (is_array($decoded) && isset($decoded['message'])) {
            return (string) $decoded['message'] . (isset($decoded['code']) ? ' (' . $decoded['code'] . ')' : '');
        }

        return Str::limit($response, 300, '…');
    }

    /** Surfaced on /health so a misconfiguration is visible before launch. */
    public static function configWarnings(): array
    {
        $warnings = [];
        $driver = strtolower(Env::string('MAIL_DRIVER', 'log'));
        $isProduction = Env::string('APP_ENV', 'local') === 'production';

        if ($isProduction && in_array($driver, ['log', 'none'], true)) {
            $warnings[] = 'MAIL_DRIVER is "' . $driver . '" in production — password resets and OTP emails are not being delivered.';
        }
        if ($driver === 'brevo' || $driver === 'smtp') {
            if (Env::string('SMTP_USER', '') === '' || Env::string('SMTP_PASS', '') === '') {
                $warnings[] = 'MAIL_DRIVER=' . $driver . ' but SMTP_USER / SMTP_PASS are empty.';
            }
            if ($driver === 'brevo' && Env::string('SMTP_HOST', '') !== '' && Env::string('SMTP_HOST', '') !== self::BREVO_SMTP_HOST) {
                $warnings[] = 'MAIL_DRIVER=brevo but SMTP_HOST is not ' . self::BREVO_SMTP_HOST . '.';
            }
        }
        if (in_array($driver, ['brevo_api', 'brevoapi', 'api'], true) && Env::string('BREVO_API_KEY', '') === '') {
            $warnings[] = 'MAIL_DRIVER=brevo_api but BREVO_API_KEY is empty.';
        }
        if (strtolower(Env::string('SMS_DRIVER', 'log')) === 'log' && $isProduction) {
            $warnings[] = 'SMS_DRIVER is "log" in production — the meeting gate\'s mobile OTP cannot be delivered.';
        }
        if (strtolower(Env::string('SMS_DRIVER', 'log')) === 'brevo' && Env::string('BREVO_API_KEY', '') === '') {
            $warnings[] = 'SMS_DRIVER=brevo but BREVO_API_KEY is empty.';
        }

        $from = Env::string('MAIL_FROM', '');
        if ($from !== '' && !filter_var($from, FILTER_VALIDATE_EMAIL)) {
            $warnings[] = 'MAIL_FROM is not a valid email address.';
        }

        return $warnings;
    }

    private static function wrap(string $subject, string $body): string
    {
        $appName = htmlspecialchars(Env::string('APP_NAME', 'PitchRooms'), ENT_QUOTES);
        $frontend = htmlspecialchars(Env::frontendDns(), ENT_QUOTES);
        $safeSubject = htmlspecialchars($subject, ENT_QUOTES);

        return <<<HTML
        <!doctype html>
        <html><body style="margin:0;background:#f4f5f8;font-family:Inter,Arial,sans-serif;color:#0f172a">
          <div style="max-width:560px;margin:0 auto;padding:24px">
            <p style="font-weight:700;font-size:18px;margin:0 0 16px">{$appName}</p>
            <div style="background:#fff;border-radius:12px;padding:24px;line-height:1.6">
              <h1 style="font-size:18px;margin:0 0 12px">{$safeSubject}</h1>
              {$body}
            </div>
            <p style="font-size:12px;color:#64748b;margin-top:16px">
              <a href="{$frontend}" style="color:#64748b">{$frontend}</a>
            </p>
          </div>
        </body></html>
        HTML;
    }

    private static function log(string $channel, string $to, string $subject, string $body, array $context): void
    {
        $dir = Paths::storage('logs');
        Paths::ensureDir($dir);

        $entry = sprintf(
            "\n===== %s %s =====\nTo: %s\nSubject: %s\n%s\n%s\n",
            $channel,
            gmdate('Y-m-d H:i:s'),
            $to,
            $subject,
            $context === [] ? '' : 'Context: ' . json_encode($context, JSON_UNESCAPED_SLASHES),
            strip_tags($body)
        );

        @file_put_contents($dir . '/messages-' . gmdate('Y-m-d') . '.log', $entry, FILE_APPEND | LOCK_EX);
    }
}
