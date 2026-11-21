# Dependency Injection

Nora includes a lightweight dependency injection container at `Nora\Container\Container`.

## Bindings

```php
use Nora\Container\Container;

$container = new Container();

// Transient binding (new instance per resolution)
$container->bind(ClockInterface::class, SystemClock::class);

// Singleton binding (shared instance)
$container->singleton(LoggerInterface::class, static fn (Container $c): LoggerInterface => new FileLogger());

// Register an existing instance
$container->instance(Config::class, $config);

// Alias
$container->alias('db', DatabaseManager::class);
```

## Resolving services

```php
$service = $container->get(ClockInterface::class);
$logger = $container->get(LoggerInterface::class);
$same = $container->get(LoggerInterface::class); // same singleton instance
$db = $container->get('db'); // resolves alias
```

`has($id)` returns `true` for registered instances/bindings, aliases, and resolvable classes.

## Constructor injection

The container can autowire instantiable classes by reflecting constructor dependencies:

```php
final class ReportService
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly ClockInterface $clock,
    ) {}
}

$container->bind(LoggerInterface::class, FileLogger::class);
$container->bind(ClockInterface::class, SystemClock::class);
$report = $container->get(ReportService::class);
```

Resolution behavior:

- Class/interface-typed dependencies are resolved from the container when available.
- Parameters with default values use the default when a dependency cannot be resolved.
- Circular dependencies throw `Nora\Container\ResolutionException`.
- Factories must return objects and receive the container instance.
- Services bound/instanced for a class or interface name must be compatible with that type.
- Unresolvable parameters without defaults produce clear resolution exceptions.

## Application integration

`Nora\Core\Application` owns a container by default and registers core instances:

- `Application::class`
- `Nora\Container\Container`
- `Nora\Config\Config`

```php
$app = new Application(config: $config, basePath: $rootDir);
$container = $app->container();
```

Service providers use `$this->app->container()` to register services. This integration is additive and backward compatible with existing application construction patterns.