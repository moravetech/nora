<?php

declare(strict_types=1);

namespace Nora\Database\Relations;

use Nora\Database\Model;
use Nora\Database\ModelQuery;
use Nora\Database\PdoConnection;

final class HasMany extends Relation
{
    public function newQuery(?PdoConnection $connection = null): ModelQuery
    {
        $related = $this->related;
        $connection ??= $related::connection();
        $query = $related::newQuery($connection);
        $query->where($related::table() . '.' . $this->foreignKey, '=', $this->parent->getAttribute($this->localKey));

        return $query;
    }

    public function addEagerConstraints(ModelQuery $query, array $models): void
    {
        $related = $this->related;
        $query->whereIn($related::table() . '.' . $this->foreignKey, $this->getKeys($models, $this->localKey));
    }

    /**
     * @return list<Model>
     */
    public function getResults(?PdoConnection $connection = null): array
    {
        $localValue = $this->parent->getAttribute($this->localKey);
        if ($localValue === null) {
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
            $key = $result->getAttribute($this->foreignKey);
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
}