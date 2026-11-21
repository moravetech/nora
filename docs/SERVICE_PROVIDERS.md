# Service Providers

Nora supports a simple service provider architecture for registering and booting application services in a predictable lifecycle.

## Service provider base class

Providers extend `Nora\Core\ServiceProvider` and receive the application instance.

```php
<?php

declare(strict_types=1);

use Nora\Core\ServiceProvider;

final class ExampleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Register bindings/services in the container.
        $this->app->container()->singleton(ExampleService::class, static fn (): ExampleService => new ExampleService());
    }

    public function boot(): void
    {
        // Run startup logic after all providers have been registered.
    }
}
```

## Registering providers

Register providers on the application:

```php
$app->register(ExampleServiceProvider::class);
$app->register(new ExampleServiceProvider($app));

// Boot all registered providers once registration is complete.
$app->boot();
```

Notes:

- Providers are registered in order and each provider's `register()` method runs immediately.
- Duplicate provider registration throws a `RuntimeException`.
- Calling `boot()` is idempotent.
- If a provider is registered after the application has already been booted, its `boot()` method runs immediately.

## Autoloading providers from configuration

`ApplicationBootstrap` can load providers from `config/app.php` (or merged app config):

```php
<?php

return [
    'providers' => [
        \Nora\Database\DatabaseServiceProvider::class,
        \Nora\Events\EventServiceProvider::class,
    ],
];
```

- Providers are registered in deterministic config order, then the application is booted.
- An empty configured provider list is supported (application boots normally).
- Invalid provider config (non-array, empty/invalid class names, missing/non-provider classes) fails clearly.
- If no provider config key is present, bootstrap auto-registers default core providers: `Nora\Database\DatabaseServiceProvider` and `Nora\Events\EventServiceProvider` (database integration remains lazy/optional until used).

## Application services

The application exposes core framework services:

```php
$container = $app->container(); // Nora\Container\Container
$config = $app->config();       // Nora\Config\Config
$root = $app->basePath();       // Application root/base path
```

`ApplicationBootstrap` passes the resolved root directory as the application base path.

## Built-in providers

### Database service provider

`Nora\Database\DatabaseServiceProvider` registers:

- `Nora\Database\DatabaseManager` (singleton, lazy)
- `Nora\Database\PdoConnection` (default connection, lazy)
- `PDO` (default connection PDO instance, lazy)

It resolves database configuration from application config (`database` or root-level `default`/`connections`) with environment/config-file fallback via `DatabaseConfiguration`, and integrates ORM auto-resolution with the application's `DatabaseManager` (without eagerly opening connections).

### Event service provider

`Nora\Events\EventServiceProvider` registers:

- `Nora\Events\EventDispatcher` as a per-application singleton
- `events` container alias

It may also register listeners from `events.listeners` config (event name => listener or list of listeners) when provided.

## Lifecycle

A typical lifecycle is:

1. Load environment/configuration.
2. Create the application and container.
3. Register configured/core service providers.
4. Boot providers once.
5. Load routes and handle HTTP requests or console commands.

See [BOOTSTRAP.md](BOOTSTRAP.md), [DATABASE.md](DATABASE.md), [ORM_DATABASE_INTEGRATION.md](ORM_DATABASE_INTEGRATION.md), and [EVENTS.md](EVENTS.md).