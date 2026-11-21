# ORM database integration

Nora ORM models can resolve database connections automatically through `Nora\Database\DatabaseManager` while preserving direct `PdoConnection` usage and legacy `Model::setConnection()`.

## Automatic resolution

When `DatabaseServiceProvider` is registered/booted (or `Model::setDatabaseManager($manager)` is called), models resolve connections lazily:

```php
use Nora\Database\DatabaseManager;
use Nora\Database\Model;

Model::setDatabaseManager($manager);

$user = User::find(1);
$users = User::with('posts')->get();
```

No connection is opened until the ORM actually needs it.

## Connection precedence

1. **Explicit per-model connection**: `Model::setConnectionFor(User::class, $connection)` (or `User::setConnectionFor(User::class, $connection)`).
2. **Legacy global connection**: `Model::setConnection($connection)` — preserved for backward compatibility and overrides manager resolution.
3. **DatabaseManager / resolver**: per-model named connection (`protected static ?string $connectionName = 'analytics';`) resolved via `DatabaseManager::connection($name)` or `null` for the default connection; or a custom resolver set with `Model::setConnectionResolver(callable)`.

## Named and multiple connections

```php
final class Report extends Model
{
    protected static ?string $table = 'reports';
    protected static ?string $connectionName = 'analytics';
}
```

Different models may use different configured connections. Eager loading and lazy relation loading resolve the related model’s connection from that model’s configuration/precedence.

## Direct PDO/PdoConnection compatibility

```php
$connection = PdoConnection::connect('sqlite::memory:');
Model::setConnection($connection); // legacy/global
// or
Model::setConnectionFor(User::class, $connection); // explicit per-model
```

You can still pass `PdoConnection` instances directly to query/model operations where supported.

## Multi-application / state control

Model connection integration uses static state for compatibility. In tests or multi-runtime scenarios, clear or reconfigure state explicitly:

```php
Model::clearConnectionState();
Model::setDatabaseManager($app->container()->get(DatabaseManager::class));
```

Each bootstrapped application has its own container-managed `DatabaseManager` and `EventDispatcher` instances.

## Lifecycle and cleanup

- `DatabaseManager::disconnectAll()` clears resolved connection instances (allowing fresh connections on next use).
- `Application::terminate()` performs best-effort cleanup of resolved database connections when a `DatabaseManager` is available in the container.
- Connection resolution is lazy; missing database configuration does not fail application bootstrap unless database services are actually resolved/used in a way that requires it.

## Notes

- SQLite integration is covered by real PHPUnit tests.
- Real MySQL/PostgreSQL runtime integration depends on available external database environments and credentials.