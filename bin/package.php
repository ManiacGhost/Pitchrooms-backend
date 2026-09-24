<?php

declare(strict_types=1);

/**
 * Builds upload-ready zips in ../deploy/.
 *
 *   php bin/package.php
 *
 * Produces:
 *   pitchrooms-api.zip       the backend, minus .env, logs, uploads and keys
 *   pitchrooms-frontend.zip  the built dist/, ready to unzip into public_html
 *   database/install.sql     and demo-users.sql are refreshed first
 *
 * Secrets are deliberately excluded — .env and the Jitsi private key are
 * uploaded separately by hand so they never sit inside a distributable file.
 */

$basePath = dirname(__DIR__);
$rootPath = dirname($basePath);
$deployPath = $rootPath . '/deploy';
$distPath = $rootPath . '/prooms/dist';

if (!class_exists('ZipArchive')) {
    fwrite(STDERR, "The zip extension is not enabled; cannot package.\n");
    exit(1);
}

if (!is_dir($deployPath) && !mkdir($deployPath, 0775, true)) {
    fwrite(STDERR, "Could not create $deployPath\n");
    exit(1);
}

// Refresh the SQL so a package always carries the current schema.
echo "Refreshing SQL …\n";
passthru(sprintf('%s %s', escapeshellarg(PHP_BINARY), escapeshellarg($basePath . '/bin/build-installer.php')));
passthru(sprintf('%s %s', escapeshellarg(PHP_BINARY), escapeshellarg($basePath . '/bin/build-demo-sql.php')));
echo "\n";

/** Paths that must never end up in a zip. */
function excluded(string $relative): bool
{
    $basename = basename($relative);

    // Anything env-shaped is a secret unless it is the committed template.
    // Deny-by-default: an enumerated skip list missed .env.bak2 once already.
    if (str_starts_with($basename, '.env') && $basename !== '.env.example') {
        return true;
    }

    // Stray backups and editor leftovers.
    if (preg_match('/\.(bak\d*|old|orig|save|swp|tmp|output|log)$/i', $basename) === 1) {
        return true;
    }
    if (str_ends_with($basename, '~')) {
        return true;
    }

    // Directory prefixes.
    $skipDirs = [
        'storage/keys/',        // the Jitsi private key
        'storage/logs/',
        'storage/uploads/',
        'storage/tmp/',
        '.git/',
        'node_modules/',
        'deploy/',
    ];

    foreach ($skipDirs as $pattern) {
        if ($relative === rtrim($pattern, '/') || str_starts_with($relative, $pattern)) {
            return true;
        }
    }

    return false;
}

/**
 * Last line of defence: refuse to write a zip that contains a secret.
 * A packaging bug that ships a signing key is not something to discover later.
 */
function auditZip(string $zipPath): array
{
    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) {
        return ['could not reopen the archive to audit it'];
    }

    $problems = [];

    // Only a long, high-entropy value counts as a live secret. Documentation
    // like `SMTP_PASS=<your key>` and shell such as `KEY=$(grep …)` must not
    // trip this, or the audit cries wolf and gets ignored.
    $secretPattern = '/(JWT_SECRET|SCHEDULER_KEY|SMTP_PASS|BREVO_API_KEY|DB_PASS|STRIPE_SECRET|RAZORPAY_KEY_SECRET)'
        . '\s*=\s*([A-Za-z0-9+\/=_-]{24,})/';

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        $base = basename($name);

        if (str_starts_with($base, '.env') && $base !== '.env.example') {
            $problems[] = $name . ' (env file)';
            continue;
        }
        if (str_contains($name, 'PRIVATE KEY') || preg_match('/privatekey|\.pem$|\.pk$|_rsa$/i', $base) === 1) {
            $problems[] = $name . ' (key file)';
            continue;
        }

        // Scan small text files for live-looking credentials.
        $stat = $zip->statIndex($i);
        if (($stat['size'] ?? 0) > 0 && ($stat['size'] ?? 0) < 200000
            && preg_match('/\.(php|sql|txt|md|json|ya?ml|sh|js)$/i', $base) === 1) {
            $contents = (string) $zip->getFromIndex($i);

            // Assembled at runtime so this file does not match its own check.
            $pemNeedle = '-----' . 'BEGIN' . ' ' . 'PRIVATE KEY' . '-----';
            $pemRsaNeedle = '-----' . 'BEGIN' . ' RSA ' . 'PRIVATE KEY' . '-----';

            if (str_contains($contents, $pemNeedle) || str_contains($contents, $pemRsaNeedle)) {
                $problems[] = $name . ' (embedded private key)';
            } elseif (preg_match($secretPattern, $contents, $m) === 1
                && !str_contains($m[2], 'change-me')
                && !str_ends_with($base, '.example')) {
                $problems[] = $name . ' (looks like a live ' . $m[1] . ')';
            }
        }
    }

    $zip->close();

    return $problems;
}

function addTree(ZipArchive $zip, string $sourceRoot, string $prefix = ''): int
{
    $count = 0;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($sourceRoot, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $item) {
        $absolute = str_replace('\\', '/', $item->getPathname());
        $relative = ltrim(substr($absolute, strlen(str_replace('\\', '/', $sourceRoot))), '/');

        if ($relative === '' || excluded($relative)) {
            continue;
        }

        $target = $prefix === '' ? $relative : $prefix . '/' . $relative;

        if ($item->isDir()) {
            $zip->addEmptyDir($target);
        } else {
            $zip->addFile($absolute, $target);
            $count++;
        }
    }

    return $count;
}

// ------------------------------------------------------------------ backend

$apiZipPath = $deployPath . '/pitchrooms-api.zip';
@unlink($apiZipPath);

$zip = new ZipArchive();
if ($zip->open($apiZipPath, ZipArchive::CREATE) !== true) {
    fwrite(STDERR, "Could not create $apiZipPath\n");
    exit(1);
}

$apiFiles = addTree($zip, $basePath, 'pitchrooms-api');

// Keep the writable directories present but empty.
foreach (['logs', 'uploads', 'tmp', 'keys'] as $dir) {
    $zip->addEmptyDir('pitchrooms-api/storage/' . $dir);
    $zip->addFromString('pitchrooms-api/storage/' . $dir . '/.gitkeep', '');
}

$zip->addFromString('pitchrooms-api/UPLOAD-NOTES.txt', <<<TXT
    PitchRooms API — upload notes

    1. Unpack this OUTSIDE the web root (e.g. /home/uXXXXXXXX/pitchrooms-api).
    2. Point the api subdomain's document root at pitchrooms-api/public.
    3. Create .env from .env.production (not included here — it holds secrets).
    4. Upload the Jitsi private key to storage/keys/privatekey.pk (not included).
    5. Make storage/ writable (755, or 775 if uploads fail).
    6. Import database/install.sql through phpMyAdmin.

    Deliberately excluded: .env, .env.production, storage/keys/*, logs, uploads.
    Full instructions: DEPLOY.md
    TXT);

$zip->close();

$problems = auditZip($apiZipPath);
if ($problems !== []) {
    @unlink($apiZipPath);
    fwrite(STDERR, "\nREFUSING TO PACKAGE — secrets found in the archive:\n");
    foreach ($problems as $problem) {
        fwrite(STDERR, '  - ' . $problem . "\n");
    }
    fwrite(STDERR, "\nThe zip has been deleted. Remove those files and re-run.\n");
    exit(1);
}

printf("pitchrooms-api.zip       %d files, %s  (secret audit passed)\n", $apiFiles, number_format(filesize($apiZipPath) / 1048576, 2) . ' MB');

// ----------------------------------------------------------------- frontend

if (!is_dir($distPath)) {
    echo "\nprooms/dist not found — run `npm run build` in prooms/ first.\n";
    exit(0);
}

$webZipPath = $deployPath . '/pitchrooms-frontend.zip';
@unlink($webZipPath);

$zip = new ZipArchive();
if ($zip->open($webZipPath, ZipArchive::CREATE) !== true) {
    fwrite(STDERR, "Could not create $webZipPath\n");
    exit(1);
}

// No prefix: these unzip straight into public_html.
$webFiles = addTree($zip, $distPath);

$zip->addFromString('UPLOAD-NOTES.txt', <<<TXT
    PitchRooms frontend — upload notes

    1. Unpack the CONTENTS of this zip into public_html/ (index.html must sit
       at the web root, not inside a dist/ folder).
    2. Confirm .htaccess arrived — file managers hide dotfiles, and without it
       refreshing any route returns 404.
    3. Edit config.js on the server and set apiDns / appDns to your real hosts.
       It is read at runtime, so changing it needs no rebuild.

    Full instructions: DEPLOY.md
    TXT);

$zip->close();

printf("pitchrooms-frontend.zip  %d files, %s\n", $webFiles, number_format(filesize($webZipPath) / 1048576, 2) . ' MB');

echo "\nBoth written to deploy/\n";
echo "Not included (upload separately): .env, storage/keys/privatekey.pk\n";
