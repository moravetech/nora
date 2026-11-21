# ORM relationships and eager loading

Nora includes an opt-in `Nora\Database\Model` base class with CRUD helpers and
a relationship/eager-loading system. It is intentionally small and incremental:
it does **not** replace raw `PdoConnection`/`QueryBuilder` usage, schema
migrations, repositories, or the existing plain model scaffold created by
`make:model`.

Use relationships when you want convenient, queryable associations on models
that extend `Nora\Database\Model`.

## Defining models

Configure a shared connection, then extend `Nora\Database\Model`:

```php
<?php

declare(strict_types=1);

use Nora\Database\Model;
use Nora\Database\PdoConnection;
use Nora\Database\Relations\BelongsTo;
use Nora\Database\Relations\BelongsToMany;
use Nora\Database\Relations\HasMany;
use Nora\Database\Relations\HasOne;

$pdo = PdoConnection::connect('sqlite::memory:');
Model::setConnection($pdo);

class User extends Model
{
    protected static ?string $table = 'users';
}

class Profile extends Model
{
    protected static ?string $table = 'profiles';
    protected static bool $timestamps = false;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
```

By default the table name is inferred from the class name (`User` => `users`),
the primary key is `id`, timestamps are enabled (`created_at`, `updated_at`),
and soft deletes are disabled.

## HasOne

A `HasOne` relation returns one related model or `null`.

```php
class User extends Model
{
    protected static ?string $table = 'users';

    public function profile(): HasOne
    {
        return $this->hasOne(Profile::class);
    }
}

$user = User::find(1);
$profile = $user->profile;      // Profile|null (lazy-loads if not loaded)
$query   = $user->profile();    // HasOne relation/query object
```

Default convention: foreign key is `{parent_snake_name}_id` on the related table
(e.g. `user_id`), local key is the parent primary key (`id`).

## HasMany

A `HasMany` relation returns a list (array) of related models, or an empty
array.

```php
class Post extends Model
{
    protected static ?string $table = 'posts';

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }
}

$post = Post::find(1);
$comments = $post->comments;   // list<Comment>
```

Default convention: foreign key is `{parent_snake_name}_id` on the related
table, local key is the parent primary key.

## BelongsTo

A `BelongsTo` relation returns one parent model or `null`. It correctly returns
`null` when the foreign key is `null`.

```php
class Post extends Model
{
    protected static ?string $table = 'posts';

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

$post = Post::find(1);
$author = $post->author;       // User|null
```

Default convention: foreign key is `{related_snake_name}_id` on the current
model (e.g. `user_id`), owner key is the related model primary key.

## BelongsToMany

A `BelongsToMany` relation reads records through a pivot table and returns a
list of related models (or an empty array).

```php
class User extends Model
{
    protected static ?string $table = 'users';

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class);
    }
}

class Role extends Model
{
    protected static ?string $table = 'roles';
    protected static bool $timestamps = false;
}

$user = User::find(1);
$roles = $user->roles;         // list<Role>
```

Default convention: pivot table is the alphabetical join of the parent and
related class basenames in snake_case (e.g. `User` + `Role` => `role_user`),
foreign pivot key is `{parent_snake_name}_id`, related pivot key is
`{related_snake_name}_id`, and parent/related keys are primary keys.

## Custom keys

All relation helpers accept explicit keys:

```php
class User extends Model
{
    protected static ?string $table = 'users';

    // hasOne/hasMany: (related, foreignKey, localKey)
    public function profile(): HasOne
    {
        return $this->hasOne(Profile::class, 'account_id', 'id');
    }

    // belongsTo: (related, foreignKey, ownerKey)
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'team_ref', 'id');
    }

    // belongsToMany: (related, pivotTable, foreignPivotKey, relatedPivotKey, parentKey, relatedKey)
    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(
            Group::class,
            'memberships',
            'member_id',
            'group_id',
            'id',
            'id',
        );
    }
}
```

## Eager loading

Use `with()` to load relations in batches and avoid N+1 queries:

```php
$users = User::with('profile')->get();
$users = User::with(['profile', 'posts'])->get();

// constrained eager load (closure receives a ModelQuery)
$users = User::with([
    'posts' => static function (Nora\Database\ModelQuery $query): void {
        $query->orderBy('id', 'desc');
    },
])->get();
```

`Model::with()` returns a `ModelQuery`. You can chain common query methods
(`where`, `orWhere`, `whereIn`, `join`, `leftJoin`, `orderBy`, `groupBy`,
`having`, `limit`, `offset`) before executing with `get()`, `first()`, or
`find($id)`.

`all()` and `find()` on the model class remain available with their existing
behavior.

## Nested eager loading

Load relations of relations with dot notation:

```php
$users = User::with('posts.comments.author')->get();

foreach ($users as $user) {
    foreach ($user->posts as $post) {
        foreach ($post->comments as $comment) {
            $author = $comment->author; // User|null, already loaded
        }
    }
}
```

## Query behavior vs relationship results

- Calling a relation method (e.g. `$user->posts()`) returns a relation/query
  object (`HasOne`, `HasMany`, `BelongsTo`, or `BelongsToMany`) that you can
  inspect or execute (`getResults()`), and whose base query is available via
  `newQuery()`.
- Accessing a relation as a property (e.g. `$user->posts`) returns the loaded
  result: a model or `null` for `HasOne`/`BelongsTo`, and an array for
  `HasMany`/`BelongsToMany`.
- If a relation is already loaded, property access does not run additional
  database queries. If it is not loaded, property access lazily loads and caches
  the result.

## Result handling

- **HasOne:** one model instance or `null`.
- **BelongsTo:** one model instance or `null` (including when the foreign key
  is `null`).
- **HasMany:** array of model instances (empty array when none).
- **BelongsToMany:** array of model instances (empty array when none).

Loaded relations are included by `toArray()`.

## Soft deletes and connections

- If a related model uses `protected static bool $softDeletes = true;`, relation
  queries respect the default soft-delete filter and do not return
  soft-deleted records unless you customize the query.
- Relations use the configured model/database connection. `Model::setConnection()`
  sets the shared base connection used by models unless a subclass overrides it.

## Common mistakes and limitations

- **Pivot table conventions:** the default BelongsToMany pivot table is derived
  from class basenames in snake_case and sorted alphabetically. If your schema
  differs, pass the table and pivot keys explicitly.
- **No relation write helpers:** attaching/detaching/syncing pivot records is
  not provided by this API.
- **No automatic constraints beyond defaults:** complex scopes, polymorphic
  relations, and relation aggregates are not included.
- **Identifier safety:** table/column/key identifiers are validated; unsafe
  identifiers raise `InvalidArgumentException`.
- **`make:model` scaffold:** the CLI still generates plain model classes for
  backward compatibility. Extend `Nora\Database\Model` and configure
  `$table`/keys/casts/timestamps as needed.
- **Not a full ORM:** this is an incremental layer focused on core CRUD and
  relationships/eager loading. Use `PdoConnection`, `QueryBuilder`, `Schema`,
  and migrations for lower-level or schema-specific needs.
