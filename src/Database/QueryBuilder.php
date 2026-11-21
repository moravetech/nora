<?php

declare(strict_types=1);

namespace Nora\Database;

use InvalidArgumentException;

final class QueryBuilder
{
    private const SELECT = 'SELECT';
    private const INSERT = 'INSERT';
    private const UPDATE = 'UPDATE';
    private const DELETE = 'DELETE';

    /** @var list<string> */
    private array $columns = ['*'];

    /** @var list<array{boolean: string, type?: string, column: string, operator: string, value: mixed, values?: list<mixed>}> */
    private array $conditions = [];

    /** @var list<array{type: string, table: string, first: string, operator: string, second: string}> */
    private array $joins = [];

    /** @var list<array{column: string, direction: string}> */
    private array $orders = [];

    /** @var list<string> */
    private array $groups = [];

    /** @var list<array{boolean: string, column: string, operator: string, value: mixed}> */
    private array $havings = [];

    /** @var array<string, mixed> */
    private array $values = [];

    private ?int $limitValue = null;
    private ?int $offsetValue = null;

    private function __construct(
        private readonly string $table,
        private readonly string $operation = self::SELECT,
    )
    {
        self::assertIdentifier($table);
    }

    /**
     * @param list<string> $columns
     */
    public static function select(string $table, array $columns = ['*']): self
    {
        $builder = new self($table, self::SELECT);
        if ($columns !== ['*']) {
            $builder->selectColumns($columns);
        }

        return $builder;
    }

    /**
     * @param list<string> $columns
     */
    public function selectColumns(array $columns): self
    {
        if ($this->operation !== self::SELECT) {
            throw new InvalidArgumentException('Columns can only be set for SELECT queries.');
        }
        if ($columns === []) {
            throw new InvalidArgumentException('A SELECT query must include at least one column.');
        }
        foreach ($columns as $column) {
            self::assertSelectColumn($column);
        }
        $this->columns = array_values($columns);

        return $this;
    }

    /**
     * @param array<string, mixed> $values
     */
    public static function insert(string $table, array $values): self
    {
        if ($values === []) {
            throw new InvalidArgumentException('An INSERT query must include at least one value.');
        }

        $builder = new self($table, self::INSERT);
        $builder->values = self::validatedValues($values);

        return $builder;
    }

    /**
     * @param array<string, mixed> $values
     */
    public static function update(string $table, array $values): self
    {
        if ($values === []) {
            throw new InvalidArgumentException('An UPDATE query must include at least one value.');
        }

        $builder = new self($table, self::UPDATE);
        $builder->values = self::validatedValues($values);

        return $builder;
    }

    public static function delete(string $table): self
    {
        return new self($table, self::DELETE);
    }

    public function where(string $column, string $operator, mixed $value): self
    {
        $this->addCondition($this->conditions, 'AND', $column, $operator, $value);

        return $this;
    }

    public function orWhere(string $column, string $operator, mixed $value): self
    {
        $this->addCondition($this->conditions, 'OR', $column, $operator, $value);

        return $this;
    }

    /**
     * @param list<mixed> $values
     */
    public function whereIn(string $column, array $values): self
    {
        return $this->addInCondition('AND', $column, $values, false);
    }

    /**
     * @param list<mixed> $values
     */
    public function whereNotIn(string $column, array $values): self
    {
        return $this->addInCondition('AND', $column, $values, true);
    }

    public function join(string $table, string $first, string $operator = '=', ?string $second = null): self
    {
        return $this->addJoin('INNER', $table, $first, $operator, $second);
    }

    public function leftJoin(string $table, string $first, string $operator = '=', ?string $second = null): self
    {
        return $this->addJoin('LEFT', $table, $first, $operator, $second);
    }

    public function orderBy(string $column, string $direction = 'asc'): self
    {
        if ($this->operation !== self::SELECT) {
            throw new InvalidArgumentException('ORDER BY is supported only for SELECT queries.');
        }
        self::assertColumnIdentifier($column);
        $direction = strtoupper($direction);
        if (!in_array($direction, ['ASC', 'DESC'], true)) {
            throw new InvalidArgumentException('Unsupported sort direction.');
        }
        $this->orders[] = ['column' => $column, 'direction' => $direction];

        return $this;
    }

    public function groupBy(string ...$columns): self
    {
        if ($this->operation !== self::SELECT) {
            throw new InvalidArgumentException('GROUP BY is supported only for SELECT queries.');
        }
        if ($columns === []) {
            throw new InvalidArgumentException('GROUP BY requires at least one column.');
        }
        foreach ($columns as $column) {
            self::assertColumnIdentifier($column);
            $this->groups[] = $column;
        }

        return $this;
    }

    public function having(string $column, string $operator, mixed $value): self
    {
        $this->addHaving('AND', $column, $operator, $value);

        return $this;
    }

    public function orHaving(string $column, string $operator, mixed $value): self
    {
        $this->addHaving('OR', $column, $operator, $value);

        return $this;
    }

    public function limit(int $limit): self
    {
        if ($this->operation !== self::SELECT) {
            throw new InvalidArgumentException('LIMIT is supported only for SELECT queries.');
        }
        if ($limit < 0) {
            throw new InvalidArgumentException('Query limit cannot be negative.');
        }
        $this->limitValue = $limit;

        return $this;
    }

    public function offset(int $offset): self
    {
        if ($this->operation !== self::SELECT) {
            throw new InvalidArgumentException('OFFSET is supported only for SELECT queries.');
        }
        if ($offset < 0) {
            throw new InvalidArgumentException('Query offset cannot be negative.');
        }
        $this->offsetValue = $offset;

        return $this;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function get(PdoConnection $connection): array
    {
        [$sql, $parameters] = $this->toSql();

        return $connection->fetchAll($sql, $parameters);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function first(PdoConnection $connection): ?array
    {
        $clone = clone $this;
        $clone->limit(1);
        [$sql, $parameters] = $clone->toSql();
        $row = $connection->fetchOne($sql, $parameters);

        return $row === false ? null : $row;
    }

    public function count(PdoConnection $connection, string $column = '*'): int
    {
        if ($this->operation !== self::SELECT) {
            throw new InvalidArgumentException('count() is supported only for SELECT queries.');
        }
        if ($column !== '*') {
            self::assertColumnIdentifier($column);
        }
        $clone = clone $this;
        $clone->columns = [$column === '*' ? 'COUNT(*) AS aggregate' : 'COUNT(' . self::quoteColumnIdentifier($column) . ') AS aggregate'];
        $clone->orders = [];
        $clone->limitValue = null;
        $clone->offsetValue = null;
        [$sql, $parameters] = $clone->toSql();
        $row = $connection->fetchOne($sql, $parameters);
        $row = $row === false ? ['aggregate' => 0] : $row;

        return (int) ($row['aggregate'] ?? 0);
    }

    /**
     * @return array{data: list<array<string, mixed>>, total: int, per_page: int, current_page: int, last_page: int}
     */
    public function paginate(PdoConnection $connection, int $perPage = 15, int $page = 1): array
    {
        if ($perPage < 1) {
            throw new InvalidArgumentException('Pagination per-page must be at least 1.');
        }
        if ($page < 1) {
            throw new InvalidArgumentException('Pagination page must be at least 1.');
        }

        $total = $this->count($connection);
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min($page, $lastPage);
        $offset = ($page - 1) * $perPage;

        $paged = clone $this;
        $paged->limit($perPage)->offset($offset);

        return [
            'data' => $paged->get($connection),
            'total' => $total,
            'per_page' => $perPage,
            'current_page' => $page,
            'last_page' => $lastPage,
        ];
    }

    /**
     * @return array{string, list<mixed>}
     */
    public function toSql(): array
    {
        $table = self::quoteIdentifier($this->table);
        $parameters = array_values($this->values);

        if ($this->operation === self::INSERT) {
            $columns = array_keys($this->values);
            $sql = 'INSERT INTO ' . $table
                . ' (' . implode(', ', array_map(self::quoteIdentifier(...), $columns)) . ')'
                . ' VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')';

            return [$sql, $parameters];
        }

        if ($this->operation === self::UPDATE) {
            $this->assertDestructivePredicate();
            $assignments = [];
            foreach (array_keys($this->values) as $column) {
                $assignments[] = self::quoteIdentifier($column) . ' = ?';
            }
            $sql = 'UPDATE ' . $table . ' SET ' . implode(', ', $assignments);
        } elseif ($this->operation === self::DELETE) {
            $this->assertDestructivePredicate();
            $sql = 'DELETE FROM ' . $table;
        } else {
            $columns = $this->selectColumnsSql();
            $sql = 'SELECT ' . $columns . ' FROM ' . $table;
            $parameters = [];
        }

        if ($this->joins !== []) {
            foreach ($this->joins as $join) {
                $sql .= ' ' . $join['type'] . ' JOIN ' . self::quoteIdentifier($join['table'])
                    . ' ON ' . self::quoteColumnIdentifier($join['first'])
                    . ' ' . $join['operator'] . ' '
                    . self::quoteColumnIdentifier($join['second']);
            }
        }

        [$whereSql, $whereParams] = $this->compileConditions($this->conditions, 'WHERE');
        if ($whereSql !== '') {
            $sql .= $whereSql;
            array_push($parameters, ...$whereParams);
        }

        if ($this->operation === self::SELECT && $this->groups !== []) {
            $sql .= ' GROUP BY ' . implode(', ', array_map(self::quoteColumnIdentifier(...), $this->groups));
        }

        [$havingSql, $havingParams] = $this->compileConditions($this->havings, 'HAVING');
        if ($havingSql !== '') {
            $sql .= $havingSql;
            array_push($parameters, ...$havingParams);
        }

        if ($this->operation === self::SELECT && $this->orders !== []) {
            $orderParts = [];
            foreach ($this->orders as $order) {
                $orderParts[] = self::quoteColumnIdentifier($order['column']) . ' ' . $order['direction'];
            }
            $sql .= ' ORDER BY ' . implode(', ', $orderParts);
        }

        if ($this->operation === self::SELECT && $this->limitValue !== null) {
            $sql .= ' LIMIT ' . $this->limitValue;
        }
        if ($this->operation === self::SELECT && $this->offsetValue !== null) {
            if ($this->limitValue === null) {
                $sql .= ' LIMIT 18446744073709551615';
            }
            $sql .= ' OFFSET ' . $this->offsetValue;
        }

        return [$sql, $parameters];
    }

    public function operation(): string
    {
        return $this->operation;
    }

    /**
     * @param list<array{boolean: string, type?: string, column: string, operator: string, value: mixed, values?: list<mixed>}> $target
     */
    private function addCondition(array &$target, string $boolean, string $column, string $operator, mixed $value): void
    {
        if ($this->operation === self::INSERT) {
            throw new InvalidArgumentException('INSERT queries do not accept WHERE conditions.');
        }
        self::assertColumnIdentifier($column);
        $operator = strtoupper($operator);
        if (!in_array($operator, ['=', '!=', '<>', '<', '<=', '>', '>=', 'LIKE'], true)) {
            throw new InvalidArgumentException('Unsupported query comparison operator.');
        }

        $target[] = [
            'boolean' => $boolean,
            'type' => 'basic',
            'column' => $column,
            'operator' => $operator,
            'value' => $value,
        ];
    }

    /**
     * @param list<mixed> $values
     */
    private function addInCondition(string $boolean, string $column, array $values, bool $not): self
    {
        if ($this->operation === self::INSERT) {
            throw new InvalidArgumentException('INSERT queries do not accept WHERE conditions.');
        }
        if ($values === []) {
            throw new InvalidArgumentException('IN conditions require at least one value.');
        }
        self::assertColumnIdentifier($column);
        $this->conditions[] = [
            'boolean' => $boolean,
            'type' => 'in',
            'column' => $column,
            'operator' => $not ? 'NOT IN' : 'IN',
            'value' => null,
            'values' => array_values($values),
        ];

        return $this;
    }

    private function addHaving(string $boolean, string $column, string $operator, mixed $value): void
    {
        if ($this->operation !== self::SELECT) {
            throw new InvalidArgumentException('HAVING is supported only for SELECT queries.');
        }
        self::assertColumnIdentifier($column);
        $operator = strtoupper($operator);
        if (!in_array($operator, ['=', '!=', '<>', '<', '<=', '>', '>=', 'LIKE'], true)) {
            throw new InvalidArgumentException('Unsupported query comparison operator.');
        }

        $this->havings[] = [
            'boolean' => $boolean,
            'column' => $column,
            'operator' => $operator,
            'value' => $value,
        ];
    }

    private function addJoin(string $type, string $table, string $first, string $operator, ?string $second): self
    {
        if ($this->operation !== self::SELECT) {
            throw new InvalidArgumentException('JOIN is supported only for SELECT queries.');
        }
        if ($second === null) {
            $second = $operator;
            $operator = '=';
        }
        self::assertIdentifier($table);
        self::assertColumnIdentifier($first);
        self::assertColumnIdentifier($second);
        $operator = strtoupper($operator);
        if (!in_array($operator, ['=', '!=', '<>', '<', '<=', '>', '>='], true)) {
            throw new InvalidArgumentException('Unsupported join operator.');
        }

        $this->joins[] = [
            'type' => $type,
            'table' => $table,
            'first' => $first,
            'operator' => $operator,
            'second' => $second,
        ];

        return $this;
    }

    private function selectColumnsSql(): string
    {
        if ($this->columns === ['*']) {
            return '*';
        }

        $parts = [];
        foreach ($this->columns as $column) {
            if ($column === '*') {
                $parts[] = '*';
                continue;
            }
            if (preg_match('/^([A-Za-z_][A-Za-z0-9_]*)\.\*$/D', $column, $matches) === 1) {
                $parts[] = self::quoteIdentifier($matches[1]) . '.*';
                continue;
            }
            if (preg_match('/^([A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)?)\s+AS\s+([A-Za-z_][A-Za-z0-9_]*)$/iD', $column, $matches) === 1) {
                $parts[] = self::quoteColumnIdentifier($matches[1]) . ' AS ' . self::quoteIdentifier($matches[2]);
                continue;
            }
            if (str_starts_with($column, 'COUNT(') && str_ends_with($column, ') AS aggregate')) {
                $parts[] = $column;
                continue;
            }
            $parts[] = self::quoteColumnIdentifier($column);
        }

        return implode(', ', $parts);
    }

    /**
     * @param list<array{boolean: string, type?: string, column: string, operator: string, value: mixed, values?: list<mixed>}> $conditions
     * @return array{string, list<mixed>}
     */
    private function compileConditions(array $conditions, string $keyword): array
    {
        if ($conditions === []) {
            return ['', []];
        }

        $sql = ' ' . $keyword . ' ';
        $parameters = [];
        foreach ($conditions as $index => $condition) {
            if ($index > 0) {
                $sql .= ' ' . $condition['boolean'] . ' ';
            }
            $columnSql = self::quoteColumnIdentifier($condition['column']);
            $type = $condition['type'] ?? 'basic';
            if ($type === 'in') {
                $values = $condition['values'] ?? [];
                $placeholders = implode(', ', array_fill(0, count($values), '?'));
                $sql .= $columnSql . ' ' . $condition['operator'] . ' (' . $placeholders . ')';
                array_push($parameters, ...array_values($values));
                continue;
            }

            $value = $condition['value'];
            if ($value === null && in_array($condition['operator'], ['=', '!=', '<>'], true)) {
                $sql .= $columnSql . ($condition['operator'] === '=' ? ' IS NULL' : ' IS NOT NULL');
                continue;
            }
            $sql .= $columnSql . ' ' . $condition['operator'] . ' ?';
            $parameters[] = $value;
        }

        return [$sql, $parameters];
    }

    private function assertDestructivePredicate(): void
    {
        if ($this->conditions === []) {
            throw new InvalidArgumentException('UPDATE and DELETE queries require at least one WHERE condition.');
        }
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private static function validatedValues(array $values): array
    {
        foreach ($values as $column => $_value) {
            if (!is_string($column)) {
                throw new InvalidArgumentException('Query values must be keyed by column names.');
            }
            self::assertIdentifier($column);
        }

        return $values;
    }

    private static function assertIdentifier(string $identifier): void
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $identifier) !== 1) {
            throw new InvalidArgumentException('Unsafe SQL identifier.');
        }
    }

    private static function assertColumnIdentifier(string $identifier): void
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)?$/D', $identifier) !== 1) {
            throw new InvalidArgumentException('Unsafe SQL identifier.');
        }
    }

    private static function assertSelectColumn(string $column): void
    {
        if ($column === '*') {
            return;
        }
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*\.\*$/D', $column) === 1) {
            return;
        }
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)?\s+AS\s+[A-Za-z_][A-Za-z0-9_]*$/iD', $column) === 1) {
            return;
        }
        if (str_starts_with($column, 'COUNT(') && str_ends_with($column, ') AS aggregate')) {
            return;
        }

        self::assertColumnIdentifier($column);
    }

    private static function quoteIdentifier(string $identifier): string
    {
        self::assertIdentifier($identifier);

        return '`' . $identifier . '`';
    }

    private static function quoteColumnIdentifier(string $identifier): string
    {
        self::assertColumnIdentifier($identifier);
        $parts = explode('.', $identifier);

        return '`' . implode('`.`', $parts) . '`';
    }
}
