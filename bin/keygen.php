<?php

declare(strict_types=1);

/**
 * Generates the secrets .env needs.
 *
 *   php bin/keygen.php          print fresh values
 *   php bin/keygen.php --write  write them into .env (only if still default)
 */

$basePath = dirname(__DIR__);
$envPath = $basePath . '/.env';
$write = in_array('--write', array_slice($argv, 1), true);

$secrets = [
    'JWT_SECRET'    => bin2hex(random_bytes(32)),
    'SCHEDULER_KEY' => bin2hex(random_bytes(24)),
];

if (!$write) {
    echo "Add these to .env:\n\n";
    foreach ($secrets as $key => $value) {
        echo $key . '=' . $value . PHP_EOL;
    }
    echo "\nRun with --write to apply them automatically.\n";
    exit(0);
}

if (!is_file($envPath)) {
    if (!copy($basePath . '/.env.example', $envPath)) {
        echo "✗ No .env and could not copy .env.example.\n";
        exit(1);
    }
    echo "Created .env from .env.example\n";
}

$contents = (string) file_get_contents($envPath);

foreach ($secrets as $key => $value) {
    if (preg_match('/^' . $key . '=(.*)$/m', $contents, $matches)) {
        $current = trim($matches[1]);

        // Never clobber a real secret that is already in place.
        if ($current !== '' && !str_starts_with($current, 'change-me')) {
            echo "· {$key} already set — leaving it alone\n";
            continue;
        }

        $contents = preg_replace('/^' . $key . '=.*$/m', $key . '=' . $value, $contents) ?? $contents;
    } else {
        $contents .= PHP_EOL . $key . '=' . $value;
    }

    echo "✓ {$key} written\n";
}

file_put_contents($envPath, $contents);
echo "\nDone. Keep .env out of version control.\n";
