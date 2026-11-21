<?php

declare(strict_types=1);

namespace Nora\Database;

use InvalidArgumentException;
use PDO;

final class Schema
{
    public static function hasTable(PDO $pdo, string $table): bool
    {
        self::assertIdentifier($table);
        $driver = strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));

        if ($driver === 'sqlite') {
            $statement = $pdo->prepare("SELECT name FROM sqlite_master WHERE type = 'table' AND name = ?");
            $statement->execute([$table]);

            return $statement->fetchColumn() !== false;
        }

        if (in_array($driver, ['pgsql', 'postgres', 'postgresql'], true)) {
            $statement = $pdo->prepare('SELECT to_regclass(?)');
            $statement->execute(['public.' . $table]);

            return $statement->fetchColumn() !== null;
        }

        $statement = $pdo->prepare('SHOW TABLES LIKE ?');
        $statement->execute([$table]);

        return $statement->fetchColumn() !== false;
    }

    /**
     * @param callable(Blueprint): void $callback
     */
    public static function create(PDO $pdo, string $table, callable $callback): void
    {
        $blueprint = new Blueprint($table);
        $callback($blueprint);
        $driver = strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
        foreach ($blueprint->toCreateStatements($driver) as $statement) {
            $pdo->exec($statement);
        }
    }

    public static function drop(PDO $pdo, string $table): void
    {
        self::assertIdentifier($table);
        $pdo->exec('DROP TABLE ' . self::quote($pdo, $table));
    }

    public static function dropIfExists(PDO $pdo, string $table): void
    {
        self::assertIdentifier($table);
        $pdo->exec('DROP TABLE IF EXISTS ' . self::quote($pdo, $table));
    }

    private static function assertIdentifier(string $identifier): void
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $identifier) !== 1) {
            throw new InvalidArgumentException('Unsafe SQL identifier.');
        }
    }

    private static function quote(PDO $pdo, string $identifier): string
    {
        self::assertIdentifier($identifier);
        $driver = strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
        $quote = in_array($driver, ['pgsql', 'postgres', 'postgresql'], true) ? '"' : '`';

        return $quote . $identifier . $quote;
    }
}