<?php

declare(strict_types=1);

namespace Nora\Database;

use DateTimeImmutable;
use InvalidArgumentException;
use Nora\Database\Relations\BelongsTo;
use Nora\Database\Relations\BelongsToMany;
use Nora\Database\Relations\HasMany;
use Nora\Database\Relations\HasOne;
use Nora\Database\Relations\Relation;
use RuntimeException;

abstract class Model
{
    /**
     * Legacy/global explicit connection (backward compatibility, precedence 2).
     */
    protected static ?PdoConnection $connection = null;

    /**
     * Per-model named connection for DatabaseManager resolution (precedence 3 selector).
     */
    protected static ?string $connectionName = null;

    /**
     * Explicit per-model PdoConnection overrides (precedence 1), keyed by model class.
     *
     * @var array<class-string<Model>, PdoConnection>
     */
    private static array $explicitConnections = [];

    private static ?DatabaseManager $databaseManager = null;

    /** @var callable(class-string<Model>, ?string): ?PdoConnection|null */
    private static $connectionResolver = null;

    /** @var string|null */
    protected static ?string $table = null;

    protected static string $primaryKey = 'id';

    protected static bool $timestamps = true;

    protected static bool $softDeletes = false;

    /** @var list<string> */
    protected static array $fillable = [];

    /** @var list<string> */
    protected static array $guarded = [];

    public const CREATED_AT = 'created_at';
    public const UPDATED_AT = 'updated_at';
    public const DELETED_AT = 'deleted_at';

    /** @var array<string, mixed> */
    protected array $attributes = [];

    /** @var array<string, mixed> */
    protected array $original = [];

    /** @var array<string, mixed> */
    protected array $relations = [];

    protected bool $exists = false;

    /** @var array<string, string> */
    protected array $casts = [];

    /**
     * @param array<string, mixed> $attributes
     */
    public function __construct(array $attributes = [], bool $exists = false)
    {
        $this->fill($attributes);
        $this->exists = $exists;
        $this->syncOriginal();
    }

    public static function setConnection(PdoConnection $connection): void
    {
        static::$connection = $connection;
    }

    public static function setConnectionFor(string $modelClass, ?PdoConnection $connection): void
    {
        if ($connection === null) {
            unset(self::$explicitConnections[$modelClass]);
            return;
        }

        self::$explicitConnections[$modelClass] = $connection;
    }

    public static function setDatabaseManager(?DatabaseManager $manager): void
    {
        self::$databaseManager = $manager;
    }

    public static function databaseManager(): ?DatabaseManager
    {
        return self::$databaseManager;
    }

    /**
     * @param callable(class-string<Model>, ?string): ?PdoConnection|null $resolver
     */
    public static function setConnectionResolver(?callable $resolver): void
    {
        self::$connectionResolver = $resolver;
    }

    public static function clearConnectionState(): void
    {
        static::$connection = null;
        self::$explicitConnections = [];
        self::$databaseManager = null;
        self::$connectionResolver = null;
    }

    public static function getConnectionName(): ?string
    {
        $name = static::$connectionName;
        return is_string($name) && $name !== '' ? $name : null;
    }

    public static function connection(): PdoConnection
    {
        // 1) Explicit per-model connection.
        $explicit = self::$explicitConnections[static::class] ?? null;
        if ($explicit instanceof PdoConnection) {
            return $explicit;
        }

        // 2) Explicit legacy/global Model connection.
        if (static::$connection instanceof PdoConnection) {
            return static::$connection;
        }

        // 3) Application/DatabaseManager resolution.
        $resolver = self::$connectionResolver;
        if ($resolver !== null) {
            $resolved = $resolver(static::class, static::getConnectionName());
            if ($resolved instanceof PdoConnection) {
                return $resolved;
            }
            if ($resolved !== null) {
                throw new RuntimeException('Model connection resolver must return a PdoConnection instance or null.');
            }
        }

        if (self::$databaseManager instanceof DatabaseManager) {
            return self::$databaseManager->connection(static::getConnectionName());
        }

        throw new RuntimeException('No database connection configured for model ' . static::class . '.');
    }

    public static function table(): string
    {
        if (is_string(static::$table) && static::$table !== '') {
            return static::$table;
        }

        $base = static::classBasename();
        $snake = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $base));

        return $snake . 's';
    }

    public static function primaryKey(): string
    {
        return static::$primaryKey;
    }

    public static function query(): QueryBuilder
    {
        $query = QueryBuilder::select(static::table());
        if (static::$softDeletes) {
            $query->where(static::DELETED_AT, '=', null);
        }

        return $query;
    }

    /**
     * @return ModelQuery<static>
     */
    public static function newQuery(?PdoConnection $connection = null): ModelQuery
    {
        $query = QueryBuilder::select(static::table());
        if (static::$softDeletes) {
            $query->where(static::DELETED_AT, '=', null);
        }

        return new ModelQuery(static::class, $query);
    }

    /**
     * @param array<int, string|\Closure>|string $relations
     * @return ModelQuery<static>
     */
    public static function with(array|string $relations): ModelQuery
    {
        return static::newQuery()->with($relations);
    }

    /**
     * @return list<static>
     */
    public static function all(): array
    {
        return static::hydrateMany(static::query()->get(static::connection()));
    }

    public static function find(mixed $id): ?static
    {
        $query = QueryBuilder::select(static::table())->where(static::primaryKey(), '=', $id);
        if (static::$softDeletes) {
            $query->where(static::DELETED_AT, '=', null);
        }
        $row = $query->first(static::connection());

        return $row === null ? null : static::newFromRow($row);
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public static function create(array $attributes): static
    {
        $model = new static($attributes);
        $model->save();

        return $model;
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function fill(array $attributes): self
    {
        foreach ($attributes as $key => $value) {
            if (!is_string($key)) {
                throw new InvalidArgumentException('Model attributes must use string keys.');
            }
            if ($this->isFillable($key)) {
                $this->setAttribute($key, $value);
            }
        }

        return $this;
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function forceFill(array $attributes): self
    {
        foreach ($attributes as $key => $value) {
            if (!is_string($key)) {
                throw new InvalidArgumentException('Model attributes must use string keys.');
            }
            $this->setAttribute($key, $value);
        }

        return $this;
    }

    public function isFillable(string $key): bool
    {
        $fillable = static::$fillable;
        $guarded = static::$guarded;

        if ($fillable !== []) {
            return in_array($key, $fillable, true)
                && !in_array($key, $guarded, true)
                && !in_array('*', $guarded, true);
        }

        if (in_array('*', $guarded, true)) {
            return false;
        }

        return !in_array($key, $guarded, true);
    }

    public function isGuarded(string $key): bool
    {
        return !$this->isFillable($key);
    }

    public function save(): bool
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        if (static::$timestamps) {
            if (!$this->exists && !array_key_exists(static::CREATED_AT, $this->attributes)) {
                $this->attributes[static::CREATED_AT] = $now;
            }
            $this->attributes[static::UPDATED_AT] = $now;
        }

        if ($this->exists) {
            $key = $this->getKey();
            if ($key === null) {
                throw new RuntimeException('Cannot update a model without a primary key value.');
            }
            $dirty = $this->getDirty();
            if ($dirty === []) {
                return true;
            }
            $query = QueryBuilder::update(static::table(), $this->attributesForStorage($dirty))
                ->where(static::primaryKey(), '=', $key);
            static::connection()->executeBuilder($query);
        } else {
            $data = $this->attributesForStorage($this->attributes);
            $query = QueryBuilder::insert(static::table(), $data);
            static::connection()->executeBuilder($query);
            $id = static::connection()->pdo()->lastInsertId();
            if ($id !== false && $id !== '' && !array_key_exists(static::primaryKey(), $this->attributes)) {
                $this->attributes[static::primaryKey()] = ctype_digit((string) $id) ? (int) $id : $id;
            }
            $this->exists = true;
        }

        $this->syncOriginal();

        return true;
    }

    public function delete(): bool
    {
        if (!$this->exists) {
            return false;
        }
        $key = $this->getKey();
        if ($key === null) {
            throw new RuntimeException('Cannot delete a model without a primary key value.');
        }

        if (static::$softDeletes) {
            $this->attributes[static::DELETED_AT] = (new DateTimeImmutable())->format('Y-m-d H:i:s');
            $this->save();

            return true;
        }

        $query = QueryBuilder::delete(static::table())->where(static::primaryKey(), '=', $key);
        static::connection()->executeBuilder($query);
        $this->exists = false;

        return true;
    }

    public function getKey(): mixed
    {
        return $this->attributes[static::primaryKey()] ?? null;
    }

    public function exists(): bool
    {
        return $this->exists;
    }

    /**
     * @return array<string, mixed>
     */
    public function getAttributes(): array
    {
        return $this->attributes;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $array = $this->attributes;
        foreach ($this->relations as $key => $relation) {
            if ($relation instanceof self) {
                $array[$key] = $relation->toArray();
                continue;
            }
            if (is_array($relation)) {
                $array[$key] = array_map(
                    static fn (mixed $item): mixed => $item instanceof self ? $item->toArray() : $item,
                    $relation,
                );
            } else {
                $array[$key] = $relation;
            }
        }

        return $array;
    }

    public function setAttribute(string $key, mixed $value): void
    {
        $this->attributes[$key] = $this->castSetValue($key, $value);
    }

    public function getAttribute(string $key): mixed
    {
        if (!array_key_exists($key, $this->attributes)) {
            return null;
        }

        return $this->castGetValue($key, $this->attributes[$key]);
    }

    public function __get(string $name): mixed
    {
        if (array_key_exists($name, $this->relations)) {
            return $this->relations[$name];
        }

        if (array_key_exists($name, $this->attributes)) {
            return $this->getAttribute($name);
        }

        if ($this->relationMethodExists($name)) {
            return $this->getRelationValue($name);
        }

        return null;
    }

    public function __set(string $name, mixed $value): void
    {
        $this->setAttribute($name, $value);
    }

    public function __isset(string $name): bool
    {
        return isset($this->attributes[$name]) || isset($this->relations[$name]);
    }

    /**
     * @return array<string, mixed>
     */
    public function getDirty(): array
    {
        $dirty = [];
        foreach ($this->attributes as $key => $value) {
            if (!array_key_exists($key, $this->original) || $this->original[$key] !== $value) {
                $dirty[$key] = $value;
            }
        }

        return $dirty;
    }

    protected function syncOriginal(): void
    {
        $this->original = $this->attributes;
    }

    protected function castGetValue(string $key, mixed $value): mixed
    {
        $cast = $this->casts[$key] ?? null;
        if ($cast === null || $value === null) {
            return $value;
        }

        return match ($cast) {
            'int', 'integer' => (int) $value,
            'float', 'double' => (float) $value,
            'bool', 'boolean' => (bool) $value,
            'string' => (string) $value,
            'array', 'json' => is_string($value) ? (json_decode($value, true) ?? []) : (array) $value,
            default => $value,
        };
    }

    protected function castSetValue(string $key, mixed $value): mixed
    {
        $cast = $this->casts[$key] ?? null;
        if ($cast === null || $value === null) {
            return $value;
        }

        return match ($cast) {
            'array', 'json' => is_string($value) ? $value : json_encode($value, JSON_THROW_ON_ERROR),
            default => $value,
        };
    }

    /**
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    protected function attributesForStorage(array $attributes): array
    {
        return $attributes;
    }

    /**
     * @param array<string, mixed> $row
     */
    protected static function newFromRow(array $row): static
    {
        $model = new static([], true);
        foreach ($row as $key => $value) {
            if (is_string($key)) {
                $model->attributes[$key] = $value;
            }
        }
        $model->syncOriginal();

        return $model;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<static>
     */
    protected static function hydrateMany(array $rows): array
    {
        $models = [];
        foreach ($rows as $row) {
            $models[] = static::newFromRow($row);
        }

        return $models;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<static>
     */
    public static function hydrateFromRows(array $rows): array
    {
        return static::hydrateMany($rows);
    }

    public function relationLoaded(string $key): bool
    {
        return array_key_exists($key, $this->relations);
    }

    public function setRelation(string $key, mixed $value): void
    {
        $this->relations[$key] = $value;
    }

    public function getRelation(string $key): mixed
    {
        return $this->relations[$key] ?? null;
    }

    public function getRelationValue(string $key): mixed
    {
        if ($this->relationLoaded($key)) {
            return $this->relations[$key];
        }

        if (!$this->relationMethodExists($key)) {
            return null;
        }

        $relation = $this->{$key}();
        if (!$relation instanceof Relation) {
            return null;
        }

        $results = $relation->getResults();
        $this->setRelation($key, $results);

        return $results;
    }

    /**
     * @param list<self> $models
     */
    public function loadRelation(array $models, string $name, mixed $constraints, ?PdoConnection $connection = null): void
    {
        $relation = $this->{$name}();
        if (!$relation instanceof Relation) {
            throw new RuntimeException('Relation method "' . $name . '" must return a Relation instance.');
        }

        // Reset query constraints to be eager-safe: build a fresh base query from related model.
        // Use the related model's resolved connection unless an explicit connection override is provided.
        $related = $relation->getRelated();
        $connection ??= $related::connection();
        $query = $related::newQuery($connection);
        $relation->addEagerConstraints($query, $models);
        if ($constraints instanceof \Closure) {
            $constraints($query);
        }
        $results = $query->get($connection);
        $relation->match($models, $results, $name);
    }

    protected function relationMethodExists(string $key): bool
    {
        if (method_exists($this, $key)) {
            $method = new \ReflectionMethod($this, $key);
            return $method->isPublic() && $method->getNumberOfRequiredParameters() === 0;
        }

        return false;
    }

    /**
     * @param class-string<self> $related
     */
    protected function hasOne(string $related, ?string $foreignKey = null, ?string $localKey = null): HasOne
    {
        $instance = $this->newRelatedInstance($related);
        $foreignKey ??= $this->foreignKeyName();
        $localKey ??= static::primaryKey();

        return new HasOne($this, $related, $foreignKey, $localKey);
    }

    /**
     * @param class-string<self> $related
     */
    protected function hasMany(string $related, ?string $foreignKey = null, ?string $localKey = null): HasMany
    {
        $this->newRelatedInstance($related);
        $foreignKey ??= $this->foreignKeyName();
        $localKey ??= static::primaryKey();

        return new HasMany($this, $related, $foreignKey, $localKey);
    }

    /**
     * @param class-string<self> $related
     */
    protected function belongsTo(string $related, ?string $foreignKey = null, ?string $ownerKey = null): BelongsTo
    {
        $instance = $this->newRelatedInstance($related);
        $foreignKey ??= $instance->foreignKeyName();
        $ownerKey ??= $related::primaryKey();

        return new BelongsTo($this, $related, $foreignKey, $ownerKey);
    }

    /**
     * @param class-string<self> $related
     */
    protected function belongsToMany(
        string $related,
        ?string $table = null,
        ?string $foreignPivotKey = null,
        ?string $relatedPivotKey = null,
        ?string $parentKey = null,
        ?string $relatedKey = null,
    ): BelongsToMany {
        $instance = $this->newRelatedInstance($related);
        $table ??= $this->joiningTable($related);
        $foreignPivotKey ??= $this->foreignKeyName();
        $relatedPivotKey ??= $instance->foreignKeyName();
        $parentKey ??= static::primaryKey();
        $relatedKey ??= $related::primaryKey();

        return new BelongsToMany($this, $related, $table, $foreignPivotKey, $relatedPivotKey, $parentKey, $relatedKey);
    }

    /**
     * @param class-string<self> $related
     */
    protected function newRelatedInstance(string $related): self
    {
        if (!class_exists($related)) {
            throw new InvalidArgumentException('Related model class "' . $related . '" does not exist.');
        }

        $instance = new $related();
        if (!$instance instanceof self) {
            throw new InvalidArgumentException('Related class "' . $related . '" must extend ' . self::class . '.');
        }

        return $instance;
    }

    protected function foreignKeyName(): string
    {
        return static::snakeCase(static::classBasename()) . '_id';
    }

    protected function joiningTable(string $related): string
    {
        $segments = [static::snakeCase(static::classBasename()), static::snakeCase($related::classBasename())];
        sort($segments);

        return implode('_', $segments);
    }

    protected static function classBasename(): string
    {
        $class = static::class;
        $pos = strrpos($class, '\\');

        return $pos === false ? $class : substr($class, $pos + 1);
    }

    protected static function snakeCase(string $value): string
    {
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $value));
    }
}