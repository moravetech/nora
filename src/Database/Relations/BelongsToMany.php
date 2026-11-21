<?php

declare(strict_types=1);

namespace Nora\Database\Relations;

use Nora\Database\Model;
use Nora\Database\ModelQuery;
use Nora\Database\PdoConnection;

final class BelongsToMany extends Relation
{
    /**
     * @param class-string<Model> $related
     */
    public function __construct(
        Model $parent,
        string $related,
        private readonly string $table,
        private readonly string $foreignPivotKey,
        private readonly string $relatedPivotKey,
        string $parentKey,
        private readonly string $relatedKey,
    ) {
        parent::__construct($parent, $related, $foreignPivotKey, $parentKey);
    }

    public function getTable(): string
    {
        return $this->table;
    }

    public function newQuery(?PdoConnection $connection = null): ModelQuery
    {
        $related = $this->related;
        $connection ??= $related::connection();
        $query = $related::newQuery($connection);
        $this->addJoin($query);
        $query->where($this->table . '.' . $this->foreignPivotKey, '=', $this->parent->getAttribute($this->localKey));

        return $query;
    }

    public function addEagerConstraints(ModelQuery $query, array $models): void
    {
        $this->addJoin($query);
        $query->whereIn($this->table . '.' . $this->foreignPivotKey, $this->getKeys($models, $this->localKey));
    }

    /**
     * @return list<Model>
     */
    public function getResults(?PdoConnection $connection = null): array
    {
        $parentKey = $this->parent->getAttribute($this->localKey);
        if ($parentKey === null) {
            return [];
        }

        $connection ??= ($this->related)::connection();

        return $this->newQuery($connection)->get($connection);
    }

    /**
     * @param list<Model> $models
     * @param list<Model> $results
     * @return list<Model>
     */
    public function match(array $models, array $results, string $relation): array
    {
        $dictionary = [];
        foreach ($results as $result) {
            $key = $result->getAttribute('pivot_' . $this->foreignPivotKey);
            if ($key === null) {
                continue;
            }
            $dictionary[(string) $key][] = $result;
        }

        foreach ($models as $model) {
            $key = $model->getAttribute($this->localKey);
            $model->setRelation($relation, $key === null ? [] : ($dictionary[(string) $key] ?? []));
        }

        return $models;
    }

    private function addJoin(ModelQuery $query): void
    {
        $related = $this->related;
        $relatedTable = $related::table();
        $query->join($this->table, $this->table . '.' . $this->relatedPivotKey, '=', $relatedTable . '.' . $this->relatedKey);

        $base = $query->query();
        $base->selectColumns([
            $relatedTable . '.*',
            $this->table . '.' . $this->foreignPivotKey . ' AS pivot_' . $this->foreignPivotKey,
            $this->table . '.' . $this->relatedPivotKey . ' AS pivot_' . $this->relatedPivotKey,
        ]);
    }
}