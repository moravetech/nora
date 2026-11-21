# Database

Nora provides a PDO-backed database layer with a connection wrapper, a limited fluent query builder, named/lazy connections, shared configuration resolution, migrations, seeders, basic schema helpers, and an opt-in base `Model` with relationships and eager loading.

## Configuration

Applications can define database connections in `config/database.php`:

```php
<?php

declare(strict_types=1);

return [
    'default' => getenv('DB_CONNECTION') ?: 'sqlite',
    'connections' => [
        'sqlite' => [
            'driver' => 'sqlite',
            'database' => getenv('DB_DATABASE') ?: 'database/database.sqlite',
        ],
        'mysql' => [
            'driver' => 'mysql',
            'host' => getenv('DB_HOST') ?: '127.0.0.1',
            'port' => (int) (getenv('DB_PORT') ?: 3306),
            'database' => getenv('DB_DATABASE') ?: 'nora',
            'username' => getenv('DB_USERNAME') ?: 'root',
            'password' => getenv('DB_PASSWORD') ?: '',
            'charset' => getenv('DB_CHARSET') ?: 'utf8mb4',
        ],
        'pgsql' => [
            'driver' => 'pgsql',
            'host' => getenv('DB_HOST') ?: '127.0.0.1',
            'port' => (int) (getenv('DB_PORT') ?: 5432),
            'database' => getenv('DB_DATABASE') ?: 'nora',
            'username' => getenv('DB_USERNAME') ?: 'postgres',
            'password' => getenv('DB_PASSWORD') ?: '',
            'sslmode' => getenv('DB_SSLMODE') ?: 'prefer',
        ],
    ],
];
```

`Nora\Core\ApplicationBootstrap` loads `config/database.php` when present and merges it into application config under the `database` key.

`Nora\Database\DatabaseConfiguration` provides shared database resolution for runtime and console:

- `load($rootDirectory)`: loads `<root>/.env` when present, reads `config/database.php` if available, or falls back to environment resolution.
- `fromEnvironment()`: supports legacy `NORA_DSN`/`NORA_DB_USERNAME`/`NORA_DB_PASSWORD` and `DB_*` variables.
- `manager($rootDirectory)`: returns a configured `Nora\Database\DatabaseManager`.
- `normalize(...)`: validates/normalizes default and named connection definitions.

## Connections and DatabaseManager

`PdoConnection::connect($dsn, $username, $password, $options)` creates a PDO connection configured for exception errors, associative fetches, and native prepared statements. `query()`, `fetchAll()`, `fetchOne()`, and `execute()` accept SQL and bound values separately. `select(QueryBuilder)` returns the executed `PDOStatement`. `transaction()` supports nested callbacks using savepoints (driver behavior varies, especially for DDL).

Direct `PdoConnection` usage remains supported.

`DatabaseManager` resolves named connections lazily and reuses them:

```php
use Nora\Database\DatabaseManager;

$manager = DatabaseManager::fromConfig([
    'default' => 'sqlite',
    'connections' => [
        'sqlite' => ['driver' => 'sqlite', 'database' => ':memory:'],
        'mysql' => ['driver' => 'mysql', 'host' => '127.0.0.1', 'database' => 'app', 'username' => 'user', 'password' => 'secret'],
    ],
]);

$db = $manager->connection();
$reports = $manager->connection('mysql');
```

Supported drivers/DSN generation: `sqlite`, `mysql`, `mariadb`, `pgsql`/`postgres`/`postgresql`. Connection definitions may provide `dsn`, `url`, driver-specific options, or credentials via `username`/`password` or `username_env`/`password_env`.

## Service/container integration

`Nora\Database\DatabaseServiceProvider` can register database services in the application container:

- `Nora\Database\DatabaseManager` (lazy singleton)
- `Nora\Database\PdoConnection` (default connection, lazy)
- `PDO` (default connection PDO instance, lazy)

```php
use Nora\Database\DatabaseServiceProvider;

$app->register(DatabaseServiceProvider::class);
$app->boot();

$manager = $app->container()->get(\Nora\Database\DatabaseManager::class);
```

The provider resolves config from application config (`database` or root-level `default`/`connections`) and falls back to environment/config-file resolution using the application base path. It also integrates ORM automatic connection resolution with the application's `DatabaseManager` without eagerly opening connections.

`ApplicationBootstrap` auto-registers default core providers (including the database provider) when no provider config key is present; database usage remains lazy/optional until needed.

## ORM integration

Nora ORM models can resolve connections automatically through `DatabaseManager` while preserving `Model::setConnection()` and direct `PdoConnection` usage. See [ORM_DATABASE_INTEGRATION.md](ORM_DATABASE_INTEGRATION.md) and [ORM_RELATIONSHIPS.md](ORM_RELATIONSHIPS.md).

## Console integration

Database CLI commands use shared configuration resolution from the working directory and the same underlying connection manager/config logic:

- `php nora migrate [--status]`
- `php nora migrate:rollback [steps]`
- `php nora make:migration <snake_case_name>`
- `php nora make:seeder <Name>`
- `php nora db:seed [name]`

Commands load `.env`/`config/database.php` when available and remain compatible with `NORA_DSN`, `NORA_DB_USERNAME`, and `NORA_DB_PASSWORD`.

## Query builder

`Nora\Database\QueryBuilder` supports SELECT, INSERT, UPDATE, and DELETE with validated identifiers and bound values, plus joins, `where`/`orWhere`, `whereIn`/`whereNotIn`, ordering, grouping, having, `get/first/count`, and pagination. `selectColumns()` supports controlled columns including `table.*` and validated aliases. UPDATE and DELETE require a predicate. It is a safe, limited builder rather than a complete SQL grammar.

## Migrations, schema, and seeders

See [MIGRATIONS.md](MIGRATIONS.md) for migration/seeder/schema behavior and limitations. `MigrationRunner` supports migrate/status/rollback; `SeederRunner` runs file-based seeders; `Schema`/`Blueprint` provide basic driver-aware helpers.

## Models and relationships

`Nora\Database\Model` is an opt-in base class with table/key configuration, attribute access/casting, timestamps, soft deletes, dirty tracking, CRUD/query helpers, and relationships (`HasOne`, `HasMany`, `BelongsTo`, `BelongsToMany`) with eager loading (`with`, nested paths). `Model::setConnection()` remains supported for direct connection injection.

See [ORM_RELATIONSHIPS.md](ORM_RELATIONSHIPS.md) for details and limitations.

## Security and operational notes

- Always use prepared statements/parameter binding for untrusted values. Never interpolate untrusted input into SQL.
- SQL identifiers are validated in supported query/schema helpers; do not pass untrusted identifiers without validation.
- Keep credentials out of source control. Avoid logging passwords, DSNs, or exposing connection errors/secrets in responses.
- MySQL/MariaDB/PostgreSQL DSN generation and configuration are implemented, but runtime integration in this environment was primarily validated with SQLite and PDO driver support checks. Run real MySQL/PostgreSQL integration tests before production reliance.

See also [BOOTSTRAP.md](BOOTSTRAP.md), [SERVICE_PROVIDERS.md](SERVICE_PROVIDERS.md), [DEPENDENCY_INJECTION.md](DEPENDENCY_INJECTION.md), and [EVENTS.md](EVENTS.md).
