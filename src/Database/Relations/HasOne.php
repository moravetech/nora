<?php

declare(strict_types=1);

namespace Nora\Database\Relations;

use Nora\Database\Model;
use Nora\Database\ModelQuery;
use Nora\Database\PdoConnection;

final class HasOne extends Relation
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
     * @return Model|null
     */
    public function getResults(?PdoConnection $connection = null): ?Model
    {
        $localValue = $this->parent->getAttribute($this->localKey);
        if ($localValue === null) {
            return null;
        }

        $connection ??= ($this->related)::connection();

        return $this->newQuery($connection)->first($connection);
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
            if ($key !== null) {
                $dictionary[(string) $key] = $result;
            }
        }

        foreach ($models as $model) {
            $key = $model->getAttribute($this->localKey);
            $model->setRelation($relation, $key === null ? null : ($dictionary[(string) $key] ?? null));
        }

        return $models;
    }
}