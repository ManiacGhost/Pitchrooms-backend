<?php

declare(strict_types=1);

/**
 * Verifies mail and SMS delivery end to end.
 *
 *   php bin/mailtest.php you@example.com          send a test email
 *   php bin/mailtest.php you@example.com --debug  show the SMTP transcript
 *   php bin/mailtest.php --sms +919876543210      send a test SMS
 *   php bin/mailtest.php --check                  config check only, sends nothing
 *
 * Run this right after pasting the Brevo key. If it fails, the reason it
 * prints is the reason production would fail.
 */

use PitchRooms\Core\Autoloader;
use PitchRooms\Core\Env;
use PitchRooms\Core\Paths;
use PitchRooms\Support\Mailer;
use PitchRooms\Support\Smtp;

$basePath = dirname(__DIR__);
require $basePath . '/src/Core/Autoloader.php';
Autoloader::register($basePath . '/src');

Env::load($basePath . '/.env');
Paths::setBase($basePath);

$args = array_slice($argv, 1);
$debug = in_array('--debug', $args, true);
$checkOnly = in_array('--check', $args, true);
$smsMode = in_array('--sms', $args, true);
$target = '';
foreach ($args as $arg) {
    if (!str_starts_with($arg, '--')) {
        $target = $arg;
        break;
    }
}

function line(string $message = ''): void
{
    echo $message . PHP_EOL;
}

$driver = strtolower(Env::string('MAIL_DRIVER', 'log'));
$smsDriver = strtolower(Env::string('SMS_DRIVER', 'log'));

line('Mail configuration');
line('  MAIL_DRIVER   ' . $driver);
line('  MAIL_FROM     ' . Env::string('MAIL_FROM', '(unset)') . ' as "' . Env::string('MAIL_FROM_NAME', '') . '"');

if (in_array($driver, ['brevo', 'smtp'], true)) {
    line('  SMTP_HOST     ' . (Env::string('SMTP_HOST', '') ?: ($driver === 'brevo' ? 'smtp-relay.brevo.com (default)' : '(unset)')));
    line('  SMTP_PORT     ' . Env::int('SMTP_PORT', 587) . ' (' . Env::string('SMTP_SECURE', 'tls') . ')');
    line('  SMTP_USER     ' . (Env::string('SMTP_USER', '') ?: '(unset)'));
    line('  SMTP_PASS     ' . (Env::string('SMTP_PASS', '') !== '' ? 'set, ' . strlen(Env::string('SMTP_PASS', '')) . ' chars' : '(unset)'));
}
if (in_array($driver, ['brevo_api', 'brevoapi', 'api'], true) || $smsDriver === 'brevo') {
    $key = Env::string('BREVO_API_KEY', '');
    line('  BREVO_API_KEY ' . ($key !== '' ? substr($key, 0, 12) . '… (' . strlen($key) . ' chars)' : '(unset)'));
}
line('  SMS_DRIVER    ' . $smsDriver);
line();

$warnings = Mailer::configWarnings();
if ($warnings !== []) {
    line('Warnings');
    foreach ($warnings as $warning) {
        line('  ! ' . $warning);
    }
    line();
}

if ($checkOnly) {
    line($warnings === [] ? 'Configuration looks consistent.' : 'Fix the warnings above, then re-run.');
    exit($warnings === [] ? 0 : 1);
}

if ($target === '') {
    line('Give a destination:  php bin/mailtest.php you@example.com');
    line('              or:  php bin/mailtest.php --sms +919876543210');
    exit(1);
}

// ---------------------------------------------------------------- SMS branch

if ($smsMode) {
    line('Sending SMS to ' . $target . ' …');
    $ok = Mailer::sms($target, 'PitchRooms test message. If this arrived, the mobile OTP for the pitch room gate will work too.');
    line($ok ? '✓ SMS accepted by the provider.' : '✗ SMS failed — see storage/logs/api-*.log for the reason.');
    exit($ok ? 0 : 1);
}

// --------------------------------------------------------------- mail branch

if ($debug && in_array($driver, ['brevo', 'smtp'], true)) {
    // Drive the SMTP client directly so the protocol transcript is visible.
    line('Connecting to SMTP with transcript …');
    line();

    $smtp = new Smtp(
        Env::string('SMTP_HOST', 'smtp-relay.brevo.com'),
        Env::int('SMTP_PORT', 587),
        Env::string('SMTP_USER', ''),
        Env::string('SMTP_PASS', ''),
        Env::string('SMTP_SECURE', 'tls'),
        Env::int('SMTP_TIMEOUT', 20)
    );

    try {
        $smtp->send(
            ['email' => Env::string('MAIL_FROM', 'no-reply@pitchrooms.com'), 'name' => Env::string('MAIL_FROM_NAME', 'PitchRooms')],
            [$target],
            'PitchRooms SMTP test',
            '<p>This is a test from <strong>bin/mailtest.php</strong>. Delivery is working.</p>'
        );
        foreach ($smtp->transcript() as $entry) {
            line('  ' . $entry);
        }
        line();
        line('✓ Delivered. Check ' . $target . ' (including spam).');
        exit(0);
    } catch (Throwable $e) {
        foreach ($smtp->transcript() as $entry) {
            line('  ' . $entry);
        }
        line();
        line('✗ ' . $e->getMessage());
        exit(1);
    }
}

line('Sending test email to ' . $target . ' via "' . $driver . '" …');

$ok = Mailer::send(
    $target,
    'PitchRooms mail test',
    '<p>This is a test from <strong>bin/mailtest.php</strong>.</p>'
    . '<p>If you are reading this, password resets, meeting invitations and the '
    . 'pitch room entry OTP will all deliver.</p>'
);

line();
if ($ok) {
    line('✓ Accepted by the provider. Check ' . $target . ', including the spam folder.');
    if ($driver === 'log') {
        line('  Note: MAIL_DRIVER=log writes to storage/logs/messages-*.log and sends nothing.');
    }
    exit(0);
}

line('✗ Send failed. The reason is in storage/logs/api-' . gmdate('Y-m-d') . '.log');
line('  Re-run with --debug to see the SMTP conversation.');
exit(1);
