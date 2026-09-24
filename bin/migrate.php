<?php

declare(strict_types=1);

/**
 * Schema migrator.
 *
 *   php bin/migrate.php            apply pending migrations
 *   php bin/migrate.php --status   list applied / pending
 *   php bin/migrate.php --fresh    DROP every table, then re-apply (dev only)
 *
 * Migrations are plain .sql files in database/migrations, applied in name
 * order and recorded in `migrations`. Running it twice is safe.
 */

use PitchRooms\Core\Autoloader;
use PitchRooms\Core\Database;
use PitchRooms\Core\Env;
use PitchRooms\Core\Paths;

$basePath = dirname(__DIR__);
require $basePath . '/src/Core/Autoloader.php';
Autoloader::register($basePath . '/src');

Env::load($basePath . '/.env');
Paths::setBase($basePath);

$args = array_slice($argv, 1);
$status = in_array('--status', $args, true);
$fresh = in_array('--fresh', $args, true);

function out(string $message): void
{
    echo $message . PHP_EOL;
}

try {
    Database::connection();
} catch (Throwable $e) {
    out('✗ ' . $e->getMessage());
    out('  Check DB_HOST / DB_NAME / DB_USER / DB_PASS in .env');
    exit(1);
}

Database::statement(
    'CREATE TABLE IF NOT EXISTS migrations (
        id INT AUTO_INCREMENT PRIMARY KEY,
        filename VARCHAR(190) NOT NULL UNIQUE,
        applied_at DATETIME NOT NULL
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
);

$files = glob($basePath . '/database/migrations/*.sql') ?: [];
sort($files);

$applied = array_column(Database::select('SELECT filename FROM migrations'), 'filename');

if ($status) {
    out('Migrations in ' . Env::string('DB_NAME', 'pitchrooms') . ':');
    foreach ($files as $file) {
        $name = basename($file);
        out(sprintf('  [%s] %s', in_array($name, $applied, true) ? 'x' : ' ', $name));
    }
    exit(0);
}

if ($fresh) {
    if (Env::string('APP_ENV', 'local') === 'production') {
        out('✗ Refusing to run --fresh with APP_ENV=production.');
        exit(1);
    }

    out('Dropping all tables…');
    Database::statement('SET FOREIGN_KEY_CHECKS = 0');
    foreach (Database::select('SHOW TABLES') as $row) {
        $table = array_values($row)[0];
        Database::statement(sprintf('DROP TABLE IF EXISTS `%s`', $table));
    }
    Database::statement('SET FOREIGN_KEY_CHECKS = 1');

    Database::statement(
        'CREATE TABLE migrations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            filename VARCHAR(190) NOT NULL UNIQUE,
            applied_at DATETIME NOT NULL
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
    $applied = [];
}

$ran = 0;

foreach ($files as $file) {
    $name = basename($file);
    if (in_array($name, $applied, true)) {
        continue;
    }

    $sql = file_get_contents($file);
    if ($sql === false || trim($sql) === '') {
        continue;
    }

    out('→ ' . $name);

    try {
        // Split on semicolons at end of line: statements here never contain
        // procedural bodies, so this is safe and keeps us dependency-free.
        foreach (preg_split('/;\s*\n/', $sql) as $statement) {
            // Drop leading comment lines; a chunk usually opens with them.
            $statement = trim(preg_replace('/^\s*--[^\n]*\n/m', '', $statement) ?? '');
            if ($statement === '') {
                continue;
            }
            Database::statement(rtrim($statement, ';'));
        }

        Database::insert('migrations', [
            'filename'   => $name,
            'applied_at' => gmdate('Y-m-d H:i:s'),
        ]);

        $ran++;
        out('  ok');
    } catch (Throwable $e) {
        out('  ✗ ' . $e->getMessage());
        exit(1);
    }
}

out($ran === 0 ? 'Nothing to migrate — schema is current.' : sprintf('Applied %d migration(s).', $ran));
