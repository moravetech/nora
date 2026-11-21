<?php

declare(strict_types=1);

namespace Nora\Database;

use Closure;
use InvalidArgumentException;

/**
 * @template TModel of Model
 */
final class ModelQuery
{
    /** @var class-string<TModel> */
    private string $modelClass;

    /** @var array<string, mixed> */
    private array $eagerLoad = [];

    /**
     * @param class-string<TModel> $modelClass
     */
    public function __construct(string $modelClass, private readonly QueryBuilder $query)
    {
        $this->modelClass = $modelClass;
    }

    /**
     * @return class-string<TModel>
     */
    public function modelClass(): string
    {
        return $this->modelClass;
    }

    public function query(): QueryBuilder
    {
        return $this->query;
    }

    /**
     * @param array<int, string|Closure>|string $relations
     * @return $this
     */
    public function with(array|string $relations): self
    {
        $relations = is_string($relations) ? [$relations] : $relations;
        foreach ($relations as $name => $constraints) {
            if (is_int($name)) {
                if (!is_string($constraints) || $constraints === '') {
                    throw new InvalidArgumentException('Eager-loaded relation names must be non-empty strings.');
                }
                $this->eagerLoad[$constraints] = $this->eagerLoad[$constraints] ?? null;
                continue;
            }
            if (!is_string($name) || $name === '') {
                throw new InvalidArgumentException('Eager-loaded relation names must be non-empty strings.');
            }
            if ($constraints !== null && !$constraints instanceof Closure) {
                throw new InvalidArgumentException('Relation constraints must be a closure or null.');
            }
            $this->eagerLoad[$name] = $constraints;
        }

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function getEagerLoads(): array
    {
        return $this->eagerLoad;
    }

    public function where(string $column, string $operator, mixed $value): self
    {
        $this->query->where($column, $operator, $value);

        return $this;
    }

    public function orWhere(string $column, string $operator, mixed $value): self
    {
        $this->query->orWhere($column, $operator, $value);

        return $this;
    }

    /**
     * @param list<mixed> $values
     */
    public function whereIn(string $column, array $values): self
    {
        $this->query->whereIn($column, $values);

        return $this;
    }

    public function join(string $table, string $first, string $operator = '=', ?string $second = null): self
    {
        $this->query->join($table, $first, $operator, $second);

        return $this;
    }

    public function leftJoin(string $table, string $first, string $operator = '=', ?string $second = null): self
    {
        $this->query->leftJoin($table, $first, $operator, $second);

        return $this;
    }

    public function orderBy(string $column, string $direction = 'asc'): self
    {
        $this->query->orderBy($column, $direction);

        return $this;
    }

    public function groupBy(string ...$columns): self
    {
        $this->query->groupBy(...$columns);

        return $this;
    }

    public function having(string $column, string $operator, mixed $value): self
    {
        $this->query->having($column, $operator, $value);

        return $this;
    }

    public function limit(int $limit): self
    {
        $this->query->limit($limit);

        return $this;
    }

    public function offset(int $offset): self
    {
        $this->query->offset($offset);

        return $this;
    }

    /**
     * @return list<TModel>
     */
    public function get(?PdoConnection $connection = null): array
    {
        $connection ??= $this->modelClass::connection();
        $rows = $this->query->get($connection);
        $models = $this->modelClass::hydrateFromRows($rows);
        $this->eagerLoadRelations($models, $connection);

        return $models;
    }

    /**
     * @return TModel|null
     */
    public function first(?PdoConnection $connection = null): ?Model
    {
        $this->query->limit(1);
        $results = $this->get($connection);

        return $results[0] ?? null;
    }

    public function find(mixed $id, ?PdoConnection $connection = null): ?Model
    {
        $key = $this->modelClass::primaryKey();
        $table = $this->modelClass::table();

        return $this->where($table . '.' . $key, '=', $id)->first($connection);
    }

    /**
     * @param list<TModel> $models
     */
    private function eagerLoadRelations(array $models, PdoConnection $connection): void
    {
        if ($models === [] || $this->eagerLoad === []) {
            return;
        }

        $eagerLoad = $this->parseWithRelations($this->eagerLoad);
        foreach ($eagerLoad as $name => $constraints) {
            if (!str_contains($name, '.')) {
                $models[0]->loadRelation($models, $name, $constraints);
            }
        }

        foreach ($eagerLoad as $name => $constraints) {
            if (!str_contains($name, '.')) {
                continue;
            }
            $this->eagerLoadNestedRelation($models, $name, $constraints);
        }
    }

    /**
     * @param array<string, mixed> $relations
     * @return array<string, mixed>
     */
    private function parseWithRelations(array $relations): array
    {
        $results = [];
        foreach ($relations as $name => $constraints) {
            $results[$name] = $constraints;
            if (str_contains($name, '.')) {
                $segments = explode('.', $name);
                array_pop($segments);
                $progress = '';
                foreach ($segments as $segment) {
                    $progress = $progress === '' ? $segment : $progress . '.' . $segment;
                    if (!array_key_exists($progress, $results)) {
                        $results[$progress] = null;
                    }
                }
            }
        }

        ksort($results);

        return $results;
    }

    /**
     * @param list<Model> $models
     */
    private function eagerLoadNestedRelation(array $models, string $name, mixed $constraints): void
    {
        $parts = explode('.', $name);
        $first = array_shift($parts);
        $rest = implode('.', $parts);
        if ($first === null || $first === '' || $rest === '') {
            return;
        }

        $results = [];
        foreach ($models as $model) {
            $relation = $model->getRelationValue($first);
            if ($relation instanceof Model) {
                $results[] = $relation;
                continue;
            }
            if (is_array($relation)) {
                foreach ($relation as $item) {
                    if ($item instanceof Model) {
                        $results[] = $item;
                    }
                }
            }
        }

        if ($results === []) {
            return;
        }

        $subQuery = $results[0]::newQuery()->with([$rest => $constraints]);
        $subQuery->eagerLoadRelations($results, $results[0]::connection());
    }
}