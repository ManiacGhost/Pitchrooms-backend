<?php

declare(strict_types=1);

namespace PitchRooms\Core;

use PDO;
use PDOException;
use PDOStatement;

/**
 * Thin PDO wrapper. Every query in the app goes through here so that
 * prepared statements are never optional.
 */
final class Database
{
    private static ?PDO $pdo = null;

    public static function connection(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            Env::string('DB_HOST', '127.0.0.1'),
            Env::int('DB_PORT', 3306),
            Env::string('DB_NAME', 'pitchrooms'),
            Env::string('DB_CHARSET', 'utf8mb4')
        );

        try {
            self::$pdo = new PDO($dsn, Env::string('DB_USER', 'root'), Env::string('DB_PASS', ''), [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_STRINGIFY_FETCHES  => false,
            ]);
        } catch (PDOException $e) {
            throw new \RuntimeException('Database connection failed: ' . $e->getMessage(), 0, $e);
        }

        return self::$pdo;
    }

    public static function raw(PDO $pdo): void
    {
        self::$pdo = $pdo;
    }

    public static function run(string $sql, array $bindings = []): PDOStatement
    {
        $statement = self::connection()->prepare($sql);
        foreach ($bindings as $key => $value) {
            $param = is_int($key) ? $key + 1 : ':' . ltrim((string) $key, ':');
            $type = match (true) {
                is_int($value)  => PDO::PARAM_INT,
                is_bool($value) => PDO::PARAM_BOOL,
                is_null($value) => PDO::PARAM_NULL,
                default         => PDO::PARAM_STR,
            };
            $statement->bindValue($param, $value, $type);
        }
        $statement->execute();

        return $statement;
    }

    public static function select(string $sql, array $bindings = []): array
    {
        return self::run($sql, $bindings)->fetchAll();
    }

    public static function first(string $sql, array $bindings = []): ?array
    {
        $row = self::run($sql, $bindings)->fetch();
        return $row === false ? null : $row;
    }

    public static function scalar(string $sql, array $bindings = []): mixed
    {
        $value = self::run($sql, $bindings)->fetchColumn();
        return $value === false ? null : $value;
    }

    public static function statement(string $sql, array $bindings = []): int
    {
        return self::run($sql, $bindings)->rowCount();
    }

    public static function insert(string $table, array $data): void
    {
        $columns = array_keys($data);
        $sql = sprintf(
            'INSERT INTO `%s` (`%s`) VALUES (%s)',
            $table,
            implode('`, `', $columns),
            implode(', ', array_map(static fn ($c) => ':' . $c, $columns))
        );
        self::run($sql, $data);
    }

    /** INSERT ... ON DUPLICATE KEY UPDATE for the columns in $update. */
    public static function upsert(string $table, array $data, array $update): void
    {
        $columns = array_keys($data);
        $assignments = implode(', ', array_map(static fn ($c) => "`$c` = VALUES(`$c`)", $update));
        $sql = sprintf(
            'INSERT INTO `%s` (`%s`) VALUES (%s) ON DUPLICATE KEY UPDATE %s',
            $table,
            implode('`, `', $columns),
            implode(', ', array_map(static fn ($c) => ':' . $c, $columns)),
            $assignments
        );
        self::run($sql, $data);
    }

    public static function update(string $table, array $data, string $where, array $bindings = []): int
    {
        if ($data === []) {
            return 0;
        }
        $assignments = implode(', ', array_map(static fn ($c) => "`$c` = :set_$c", array_keys($data)));
        $params = [];
        foreach ($data as $key => $value) {
            $params['set_' . $key] = $value;
        }
        $sql = sprintf('UPDATE `%s` SET %s WHERE %s', $table, $assignments, $where);

        return self::statement($sql, array_merge($params, $bindings));
    }

    public static function transaction(callable $callback): mixed
    {
        $pdo = self::connection();
        // Nested calls join the outer transaction rather than starting a new one.
        if ($pdo->inTransaction()) {
            return $callback();
        }

        $pdo->beginTransaction();
        try {
            $result = $callback();
            $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
