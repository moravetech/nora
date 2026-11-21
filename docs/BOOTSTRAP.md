# Application Bootstrap & Lifecycle

Nora bootstraps applications through `Nora\Core\ApplicationBootstrap`, which prepares environment, configuration, logging, error handling, routing, and the core `Application` instance.

## Bootstrapping an application

```php
<?php

declare(strict_types=1);

use Nora\Core\ApplicationBootstrap;

require __DIR__ . '/vendor/autoload.php';

$app = (new ApplicationBootstrap())->boot(__DIR__);
$response = $app->handle(\Nora\Http\Request::fromGlobals());
$response->send();
```

## Bootstrap lifecycle

`ApplicationBootstrap::boot()` performs the following steps:

1. **Resolve root directory** and validate it exists.
2. **Load environment** from `<root>/.env` when present (without overriding existing process environment variables by default).
3. **Load configuration sources** and merge them in order:
   - framework defaults (`app.environment=production`, `app.debug=false`, `logging.level=info`)
   - `<root>/config/app.php` (if present)
   - `<root>/config/database.php` (if present, merged under `database`)
   - `<root>/config/{environment}.php` (if present)
   - environment-derived overrides (`app.environment`, optional debug/log level overrides)
4. **Validate configuration** (`app.environment`, `app.debug`, `logging.level`).
5. **Create core services**: logger and exception handler.
6. **Create the application instance** with router/pipeline/exception handler/config and base path.
7. **Register service providers** from config (`providers` or `app.providers`) when present. If no provider config key is present, bootstrap registers default core providers for unified runtime integration: `Nora\Database\DatabaseServiceProvider` and `Nora\Events\EventServiceProvider`. An explicitly empty provider list is supported.
8. **Boot the application/providers** once (`$app->boot()`). During boot, the application may dispatch lifecycle events (`application.booting`, `application.booted`) when an event dispatcher is available in the container.
9. **Load routes** from `<root>/routes.php` or `<root>/src/routes.php` when present. Route files must return a callable that receives the `Application`.
10. **Return the booted application** for request handling or CLI/runtime use.

## Application lifecycle

`Nora\Core\Application` manages:

- Route registration and dispatch
- Global/prepended middleware
- Exception handling
- Configuration access (`config()`)
- Dependency injection container access (`container()`)
- Base path (`basePath()`)
- Service provider registration and boot (`register()`, `boot()`)
- Application termination and best-effort resource cleanup (`terminate()`)

Example with configured providers in `config/app.php`:

```php
<?php

return [
    'app' => ['environment' => 'production', 'debug' => false],
    'providers' => [
        \Nora\Database\DatabaseServiceProvider::class,
        \Nora\Events\EventServiceProvider::class,
        \App\Providers\AppServiceProvider::class,
    ],
];
```

Manual registration is still supported:

```php
use Nora\Database\DatabaseServiceProvider;

$app = (new ApplicationBootstrap())->boot(__DIR__);
// If providers were configured/autoloaded, the app may already be booted.
// Additional providers can still be registered; late registration boots immediately when appropriate.
$app->register(DatabaseServiceProvider::class);

$response = $app->handle($request);
```

## Database and runtime integration

- Database configuration can be provided in `config/database.php` and/or environment variables.
- `DatabaseConfiguration` provides shared resolution for runtime/container and console database commands.
- `DatabaseServiceProvider` registers `DatabaseManager`, default `PdoConnection`, and default `PDO` services lazily and integrates ORM connection resolution with the application's database manager.
- `EventServiceProvider` registers the `EventDispatcher` as a per-application singleton service.
- Database connections are lazy: `DatabaseManager` creates/reuses connections on demand.
- `Application::terminate()` dispatches `application.terminating` and `application.terminated` (when an event dispatcher is available) and performs best-effort cleanup of resolved database connections via `DatabaseManager::disconnectAll()`. Lifecycle event payloads are arrays in the form `['application' => $app, 'event' => '...']`.

## Notes

- Existing applications continue to work with the current bootstrap and `Application` APIs. Provider/container/database services are additive.
- If no provider config key is present, bootstrap registers default core database/event providers for unified runtime integration (database setup remains optional/lazy until used).
- Console database commands prefer the bootstrapped application runtime/container for the working directory when available and fall back to legacy `DatabaseConfiguration`/environment resolution for compatibility (`NORA_DSN`, `NORA_DB_*`, `DB_*`, `config/database.php`). See [CLI.md](CLI.md) and [ORM_DATABASE_INTEGRATION.md](ORM_DATABASE_INTEGRATION.md).