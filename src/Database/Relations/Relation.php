<?php

declare(strict_types=1);

namespace Nora\Database\Relations;

use Nora\Database\Model;
use Nora\Database\ModelQuery;
use Nora\Database\PdoConnection;

abstract class Relation
{
    /**
     * @param class-string<Model> $related
     */
    public function __construct(
        protected readonly Model $parent,
        protected readonly string $related,
        protected readonly string $foreignKey,
        protected readonly string $localKey,
    ) {
    }

    abstract public function addEagerConstraints(ModelQuery $query, array $models): void;

    /**
     * @param list<Model> $models
     * @param list<Model> $results
     * @return list<Model>
     */
    abstract public function match(array $models, array $results, string $relation): array;

    /**
     * @return list<Model>|Model|null
     */
    abstract public function getResults(?PdoConnection $connection = null): array|Model|null;

    abstract public function newQuery(?PdoConnection $connection = null): ModelQuery;

    /**
     * @param list<Model> $models
     * @return list<mixed>
     */
    protected function getKeys(array $models, string $key): array
    {
        $keys = [];
        foreach ($models as $model) {
            $value = $model->getAttribute($key);
            if ($value !== null) {
                $keys[] = $value;
            }
        }

        return array_values(array_unique($keys, SORT_REGULAR));
    }

    /**
     * @return class-string<Model>
     */
    public function getRelated(): string
    {
        return $this->related;
    }

    public function getForeignKey(): string
    {
        return $this->foreignKey;
    }

    public function getLocalKey(): string
    {
        return $this->localKey;
    }
}