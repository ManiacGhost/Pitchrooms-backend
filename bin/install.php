<?php

declare(strict_types=1);

/**
 * Guided server-side installer. With SSH this replaces the manual checklist.
 *
 *   php bin/install.php --api-dns=https://api.example.com \
 *                       --frontend-dns=https://app.example.com \
 *                       --db-name=u1_pitchrooms --db-user=u1_pitchrooms --db-pass='…' \
 *                       [--demo] [--force]
 *
 * It writes .env (generating secrets), checks the environment, creates the
 * schema, optionally seeds demo accounts, and prints what is left to do.
 * Safe to re-run: an existing .env is preserved unless --force is given.
 */

use PitchRooms\Core\Autoloader;
use PitchRooms\Core\Database;
use PitchRooms\Core\Env;
use PitchRooms\Core\Paths;

$basePath = dirname(__DIR__);
require $basePath . '/src/Core/Autoloader.php';
Autoloader::register($basePath . '/src');

// ------------------------------------------------------------------ helpers

function line(string $message = ''): void
{
    echo $message . PHP_EOL;
}

function ok(string $message): void
{
    line('  [ok]   ' . $message);
}

function warn(string $message): void
{
    line('  [warn] ' . $message);
}

function fail(string $message): void
{
    line('  [FAIL] ' . $message);
}

/** @return array<string, string> */
function parseArgs(array $argv): array
{
    $out = [];
    foreach (array_slice($argv, 1) as $arg) {
        if (!str_starts_with($arg, '--')) {
            continue;
        }
        $pair = substr($arg, 2);
        if (str_contains($pair, '=')) {
            [$key, $value] = explode('=', $pair, 2);
            $out[$key] = $value;
        } else {
            $out[$pair] = '1';
        }
    }
    return $out;
}

function normaliseUrl(string $url): string
{
    $url = trim($url);
    if ($url === '') {
        return '';
    }
    if (!preg_match('#^https?://#i', $url)) {
        $url = 'https://' . $url;
    }
    return rtrim($url, '/');
}

$args = parseArgs($argv);
$force = isset($args['force']);
$withDemo = isset($args['demo']);

line('===========================================================');
line(' PitchRooms API installer');
line('===========================================================');

// ------------------------------------------------------- 1. environment
line('');
line('1. Environment');

$problems = 0;

if (PHP_VERSION_ID < 80100) {
    fail('PHP ' . PHP_VERSION . ' — this API needs 8.1 or newer. Change it in hPanel > PHP Configuration.');
    $problems++;
} else {
    ok('PHP ' . PHP_VERSION);
}

foreach (['pdo_mysql' => true, 'openssl' => true, 'curl' => false, 'fileinfo' => false, 'zip' => false] as $ext => $required) {
    if (extension_loaded($ext)) {
        ok('extension ' . $ext);
    } elseif ($required) {
        fail('extension ' . $ext . ' is missing and is required');
        $problems++;
    } else {
        warn('extension ' . $ext . ' is missing (' . match ($ext) {
            'curl'     => 'needed only for the Brevo HTTP API driver',
            'fileinfo' => 'uploads fall back to weaker type detection',
            'zip'      => 'only bin/package.php needs it',
            default    => 'optional',
        } . ')');
    }
}

Paths::setBase($basePath);
foreach (['storage', 'storage/logs', 'storage/uploads', 'storage/keys'] as $dir) {
    $path = $basePath . '/' . $dir;
    if (!is_dir($path)) {
        @mkdir($path, 0775, true);
    }
    if (is_writable($path)) {
        ok($dir . ' is writable');
    } else {
        fail($dir . ' is not writable — run: chmod -R 775 storage');
        $problems++;
    }
}

if ($problems > 0) {
    line('');
    line('Fix the failures above, then re-run.');
    exit(1);
}

// ------------------------------------------------------------- 2. .env
line('');
line('2. Configuration');

$envPath = $basePath . '/.env';
$envExists = is_file($envPath);

if ($envExists && !$force) {
    ok('.env already exists — keeping your values (use --force to rewrite from scratch)');

    // Patch in place: never disturb credentials that are already correct, but
    // a placeholder signing key must not survive. "change-me-to-a-64-char-…"
    // is long enough to pass validation, so it would silently ship as a
    // publicly known secret and anyone could forge a token.
    $contents = (string) file_get_contents($envPath);
    $patched = [];

    $readValue = static function (string $key) use ($contents): string {
        return preg_match('/^' . $key . '=(.*)$/m', $contents, $m) === 1 ? trim($m[1]) : '';
    };

    $setValue = static function (string $key, string $value) use (&$contents): void {
        $line = $key . '=' . $value;
        $contents = preg_match('/^' . $key . '=.*$/m', $contents) === 1
            ? (string) preg_replace('/^' . $key . '=.*$/m', $line, $contents)
            : rtrim($contents) . PHP_EOL . $line . PHP_EOL;
    };

    foreach (['JWT_SECRET' => 32, 'SCHEDULER_KEY' => 24] as $key => $bytes) {
        $current = $readValue($key);
        $weak = $current === ''
            || strlen($current) < 24
            || str_contains(strtolower($current), 'change-me');

        if ($weak) {
            $setValue($key, bin2hex(random_bytes($bytes)));
            $patched[] = $key . ' (was a placeholder — generated a real one)';
        }
    }

    // Only overwrite the URLs when explicitly passed.
    if (isset($args['api-dns'])) {
        $new = normaliseUrl($args['api-dns']);
        if ($new !== '' && $new !== $readValue('APP_DNS')) {
            $setValue('APP_DNS', $new);
            $patched[] = 'APP_DNS = ' . $new;
        }
    }
    if (isset($args['frontend-dns'])) {
        $new = normaliseUrl($args['frontend-dns']);
        if ($new !== '' && $new !== $readValue('FRONTEND_DNS')) {
            $setValue('FRONTEND_DNS', $new);
            $patched[] = 'FRONTEND_DNS = ' . $new;
        }
    }
    if (isset($args['env'])) {
        $setValue('APP_ENV', $args['env']);
        $setValue('APP_DEBUG', $args['env'] === 'production' ? 'false' : 'true');
        $patched[] = 'APP_ENV = ' . $args['env'];
    }

    if ($patched !== []) {
        copy($envPath, $envPath . '.previous');
        file_put_contents($envPath, $contents);
        @chmod($envPath, 0600);
        foreach ($patched as $change) {
            ok('updated ' . $change);
        }
        warn('previous .env saved as .env.previous — delete it once you are happy');
    }
} else {
    $apiDns = normaliseUrl($args['api-dns'] ?? '');
    $frontendDns = normaliseUrl($args['frontend-dns'] ?? '');

    if ($apiDns === '') {
        fail('--api-dns is required, e.g. --api-dns=https://api.example.com');
        exit(1);
    }
    if ($frontendDns === '') {
        // Not fatal: a browser-less API still works, but CORS will block the app.
        warn('--frontend-dns not given; CORS will not allow any browser origin yet');
    }

    $template = (string) file_get_contents($basePath . '/.env.example');

    $values = [
        'APP_DNS'       => $apiDns,
        'FRONTEND_DNS'  => $frontendDns !== '' ? $frontendDns : 'https://change-me.example',
        'APP_ENV'       => $args['env'] ?? 'production',
        'APP_DEBUG'     => 'false',
        'DB_HOST'       => $args['db-host'] ?? 'localhost',
        'DB_NAME'       => $args['db-name'] ?? '',
        'DB_USER'       => $args['db-user'] ?? '',
        'DB_PASS'       => $args['db-pass'] ?? '',
        'JWT_SECRET'    => bin2hex(random_bytes(32)),
        'SCHEDULER_KEY' => bin2hex(random_bytes(24)),
    ];

    foreach ($values as $key => $value) {
        $replacement = $key . '=' . $value;
        $template = preg_match('/^' . $key . '=.*$/m', $template) === 1
            ? (string) preg_replace('/^' . $key . '=.*$/m', $replacement, $template)
            : $template . PHP_EOL . $replacement;
    }

    if (file_put_contents($envPath, $template) === false) {
        fail('could not write .env');
        exit(1);
    }
    @chmod($envPath, 0600);

    ok('.env written (JWT_SECRET and SCHEDULER_KEY generated, file mode 600)');
}

Env::load($envPath);

if (Env::string('DB_NAME', '') === '') {
    fail('DB_NAME is empty in .env — set DB_NAME / DB_USER / DB_PASS and re-run');
    exit(1);
}

ok('APP_DNS      ' . Env::appDns());
ok('FRONTEND_DNS ' . Env::frontendDns());

// -------------------------------------------------------------- 3. database
line('');
line('3. Database');

try {
    Database::connection();
    ok('connected to ' . Env::string('DB_NAME'));
} catch (Throwable $e) {
    fail($e->getMessage());
    line('');
    line('Check DB_HOST / DB_NAME / DB_USER / DB_PASS in .env.');
    line('On Hostinger the database and user names are prefixed, e.g. u123456789_pitchrooms.');
    exit(1);
}

line('  running migrations …');
$migrateOutput = [];
$migrateStatus = 0;
exec(sprintf('%s %s 2>&1', escapeshellarg(PHP_BINARY), escapeshellarg($basePath . '/bin/migrate.php')), $migrateOutput, $migrateStatus);
foreach ($migrateOutput as $outputLine) {
    line('    ' . $outputLine);
}
if ($migrateStatus !== 0) {
    fail('migrations failed');
    exit(1);
}

$tables = (int) Database::scalar(
    'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = :db',
    ['db' => Env::string('DB_NAME')]
);
ok($tables . ' tables present');

if ($withDemo) {
    line('  seeding demo data …');
    $seedOutput = [];
    $seedStatus = 0;

    // --force because the seeder refuses to touch a production APP_ENV
    // unless told explicitly, and --demo is that explicit instruction.
    exec(
        sprintf('%s %s --force 2>&1', escapeshellarg(PHP_BINARY), escapeshellarg($basePath . '/bin/seed.php')),
        $seedOutput,
        $seedStatus
    );

    $userCount = (int) Database::scalar('SELECT COUNT(*) FROM users');

    if ($seedStatus === 0 && $userCount > 0) {
        ok($userCount . ' demo accounts created (password demo123, admin123 for admin)');
        warn('demo data is test data — delete it before the platform goes live');
    } else {
        fail('seeding did not create any accounts');
        foreach ($seedOutput as $outputLine) {
            line('    ' . $outputLine);
        }
    }
}

// ------------------------------------------------------------- 4. services
line('');
line('4. Services');

$mailDriver = Env::string('MAIL_DRIVER', 'log');
if (in_array($mailDriver, ['log', 'none'], true)) {
    warn('MAIL_DRIVER=' . $mailDriver . ' — no email is delivered. Set MAIL_DRIVER=brevo with SMTP_USER / SMTP_PASS.');
} else {
    ok('MAIL_DRIVER=' . $mailDriver);
}

if (Env::string('JITSI_APP_ID', '') !== '') {
    $keyPresent = \PitchRooms\Services\MeetingService::jitsiPrivateKey() !== null;
    $keyPresent
        ? ok('Jitsi JaaS configured (' . Env::string('JITSI_DOMAIN') . ')')
        : fail('JITSI_APP_ID is set but the private key is missing — upload it to storage/keys/privatekey.pk');
} else {
    warn('no Jitsi credentials — the live room will fall back to an unauthenticated public server');
}

foreach (\PitchRooms\Services\MeetingService::jitsiConfigWarnings() as $warning) {
    warn($warning);
}
foreach (\PitchRooms\Support\Mailer::configWarnings() as $warning) {
    warn($warning);
}

// ---------------------------------------------------------------- 5. done
line('');
line('===========================================================');
line(' Installed.');
line('===========================================================');
line('');
line('Check the API is reachable:');
line('  curl ' . Env::appDns() . '/api/v1/health');
line('');
line('If that 404s or returns HTML, the document root is wrong — point the');
line('subdomain at pitchrooms-api/public (not the project folder).');
line('');
line('Frontend: upload the contents of prooms/dist to the web root, then edit');
line('config.js there and set:');
line('  apiDns: "' . Env::appDns() . '"');
line('  appDns: "' . Env::frontendDns() . '"');
line('');
line('Scheduler key (for an external uptime monitor, optional):');
line('  POST ' . Env::appDns() . '/api/v1/internal/scheduler/tick');
line('  Header: X-Scheduler-Key: ' . Env::string('SCHEDULER_KEY'));
line('');
