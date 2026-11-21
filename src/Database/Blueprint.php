<?php

declare(strict_types=1);

namespace Nora\Database;

use InvalidArgumentException;
use PDO;

final class Blueprint
{
    /** @var list<string> */
    private array $columns = [];

    /** @var list<string> */
    private array $commands = [];

    public function __construct(private readonly string $table)
    {
        self::assertIdentifier($table);
    }

    public function table(): string
    {
        return $this->table;
    }

    public function id(string $name = 'id'): self
    {
        $this->columns[] = $this->quoteForColumn($name) . ' INTEGER PRIMARY KEY AUTOINCREMENT';

        return $this;
    }

    public function increments(string $name = 'id'): self
    {
        return $this->id($name);
    }

    public function bigIncrements(string $name = 'id'): self
    {
        return $this->id($name);
    }

    public function integer(string $name): self
    {
        $this->columns[] = $this->columnDefinition($name, 'INTEGER');

        return $this;
    }

    public function bigInteger(string $name): self
    {
        $this->columns[] = $this->columnDefinition($name, 'BIGINT');

        return $this;
    }

    public function string(string $name, int $length = 255): self
    {
        if ($length < 1) {
            throw new InvalidArgumentException('String length must be at least 1.');
        }
        $this->columns[] = $this->columnDefinition($name, 'VARCHAR(' . $length . ')');

        return $this;
    }

    public function text(string $name): self
    {
        $this->columns[] = $this->columnDefinition($name, 'TEXT');

        return $this;
    }

    public function boolean(string $name): self
    {
        $this->columns[] = $this->columnDefinition($name, 'BOOLEAN');

        return $this;
    }

    public function timestamp(string $name, bool $nullable = true): self
    {
        $definition = $this->quoteForColumn($name) . ' TIMESTAMP';
        $definition .= $nullable ? ' NULL' : ' NOT NULL';
        $this->columns[] = $definition;

        return $this;
    }

    public function timestamps(): self
    {
        $this->timestamp('created_at', true);
        $this->timestamp('updated_at', true);

        return $this;
    }

    public function nullableTimestamps(): self
    {
        return $this->timestamps();
    }

    public function primary(string $name): self
    {
        $this->commands[] = 'PRIMARY KEY (__Q__' . $name . '__Q__)';

        return $this;
    }

    public function unique(string $name): self
    {
        $this->commands[] = 'UNIQUE (__Q__' . $name . '__Q__)';

        return $this;
    }

    public function index(string $name): self
    {
        // Indexes are created as separate statements by Schema for portability.
        $this->commands[] = '__INDEX__:' . $name;

        return $this;
    }

    /**
     * @return list<string>
     */
    public function toCreateStatements(string $driver): array
    {
        if ($this->columns === [] && $this->commands === []) {
            throw new InvalidArgumentException('A table blueprint must define at least one column.');
        }

        $driver = strtolower($driver);
        $definitions = [];
        foreach ($this->columns as $column) {
            $definitions[] = $this->normalizeColumnDefinition($column, $driver);
        }
        foreach ($this->commands as $command) {
            if (str_starts_with($command, '__INDEX__:')) {
                continue;
            }
            $definitions[] = $this->applyQuoting($command, $driver);
        }

        $sql = 'CREATE TABLE ' . $this->quoteForDriver($this->table, $driver) . ' (' . implode(', ', $definitions) . ')';
        $statements = [$sql];

        foreach ($this->commands as $command) {
            if (!str_starts_with($command, '__INDEX__:')) {
                continue;
            }
            $column = substr($command, strlen('__INDEX__:'));
            self::assertIdentifier($column);
            $indexName = $this->table . '_' . $column . '_index';
            $statements[] = 'CREATE INDEX ' . $this->quoteForDriver($indexName, $driver)
                . ' ON ' . $this->quoteForDriver($this->table, $driver)
                . ' (' . $this->quoteForDriver($column, $driver) . ')';
        }

        return $statements;
    }

    private function columnDefinition(string $name, string $type): string
    {
        return $this->quoteForColumn($name) . ' ' . $type . ' NOT NULL';
    }

    private function normalizeColumnDefinition(string $definition, string $driver): string
    {
        // Column definitions are stored with placeholder quoting `__Q__name__Q__` for portability.
        $definition = $this->applyQuoting($definition, $driver);
        if ($driver !== 'sqlite' && str_contains($definition, ' INTEGER PRIMARY KEY AUTOINCREMENT')) {
            $quotedName = substr($definition, 0, strpos($definition, ' INTEGER PRIMARY KEY AUTOINCREMENT') ?: 0);
            $type = in_array($driver, ['pgsql', 'postgres', 'postgresql'], true)
                ? 'BIGSERIAL PRIMARY KEY'
                : 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY';

            return trim($quotedName) . ' ' . $type;
        }

        return $definition;
    }

    private function quoteForColumn(string $identifier): string
    {
        self::assertIdentifier($identifier);

        return '__Q__' . $identifier . '__Q__';
    }

    private function applyQuoting(string $value, string $driver): string
    {
        $quote = in_array($driver, ['pgsql', 'postgres', 'postgresql'], true) ? '"' : '`';

        return preg_replace_callback(
            '/__Q__([A-Za-z_][A-Za-z0-9_]*)__Q__/',
            static fn (array $m): string => $quote . $m[1] . $quote,
            $value,
        ) ?? $value;
    }

    private function quoteForDriver(string $identifier, string $driver): string
    {
        self::assertIdentifier($identifier);
        $quote = in_array($driver, ['pgsql', 'postgres', 'postgresql'], true) ? '"' : '`';

        return $quote . $identifier . $quote;
    }

    private static function assertIdentifier(string $identifier): void
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $identifier) !== 1) {
            throw new InvalidArgumentException('Unsafe SQL identifier.');
        }
    }
}