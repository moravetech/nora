<p align="center">
  <img src="assets/brand/nora-logo.png" alt="Nora Framework" width="360">
</p>

# Nora Framework

Nora is an independent, modular PHP application framework created in Morocco by **Morave Technologies** (`Yasser-devb`). It provides an explicit PHP-first core for HTTP applications, configuration/bootstrap, dependency injection, service providers, routing and middleware, database/ORM APIs, events, CLI tooling, and an experimental server-rendered reactive runtime that can be styled with Tailwind CSS.

Nora has its own framework core and public APIs. Laravel and Symfony are not runtime dependencies. The project is in active development and is **pre-stable (maturing)**: core runtime, database, CLI, and tooling capabilities are increasingly integrated and tested, but APIs, workflows, and environment-specific behavior may still evolve. Evaluate and use each capability based on verified implementation and your target environment.

- **Repository:** [moravetech/nora](https://github.com/moravetech/nora)
- **Maintainer identity:** [Yasser-devb](https://github.com/Yasser-devb)
- **License:** [MIT](LICENSE) (see [TRADEMARKS.md](TRADEMARKS.md) for name/branding use)

## Project Vision and Philosophy

Nora aims to offer a small, understandable framework core with explicit wiring, practical tooling, and security-conscious defaults. The current development direction is to unify existing subsystems through a coherent application runtime and lifecycle (bootstrap, container, providers, database/ORM, events, CLI, HTTP/reactive integration) without replacing Nora’s existing architecture.

## Key Features

| Area | Summary | Status |
| --- | --- | --- |
| HTTP core | `Application`, `Router`, `Request`, `Response`, middleware pipeline, exception handling | **Implemented** |
| Bootstrap & config | `ApplicationBootstrap`, `.env` loading, layered config, validation, route loading | **Implemented** |
| Dependency injection | Lightweight container with bindings, singletons, aliases, autowiring, circular-dependency checks | **Implemented** |
| Service providers | Provider registration/boot lifecycle, config-driven/default core providers | **Implemented** |
| Database & ORM | `DatabaseManager`, `PdoConnection`, query builder, schema helpers, migrations/seeders, model/relationship APIs, manager-aware ORM resolution | **Partially implemented** (runtime integration implemented; live MySQL/PostgreSQL verification depends on environment) |
| Event dispatcher | Listener registration/dispatch, priorities, wildcards, subscribers, per-application container integration, lifecycle events | **Implemented** (core dispatcher/lifecycle hooks) |
| CLI | `bin/nora` / `ConsoleApplication` for scaffolding, migrations, seeders, assets, and dev server; unified bootstrap attempted for DB commands | **Implemented** (command availability may differ between source checkout and generated/installed contexts) |
| Reactive runtime | Server-rendered PHP reactive engine/components, explicit actions, CSRF/revision/session protections, DOM reconciliation runtime | **Experimental** |
| Tailwind integration | Tailwind CSS v4 build-time integration and local stylesheet mounting for reactive/UI pages | **Partially implemented** (build-time integration/examples/tests exist; environment/browser/tooling verification varies) |
| Security building blocks | Session/auth utilities, CSRF, authorization gate, validation, upload/storage helpers, account lifecycle APIs | **Partially implemented** (APIs/helpers exist; not a complete turnkey auth system or security certification) |

## Feature Implementation Status

| Feature | Status | Evidence / Notes |
| --- | --- | --- |
| Application lifecycle, routing, middleware, request/response | **Implemented** | `src/Core/Application.php`, `src/Routing/*`, `src/Http/*`, PHPUnit unit/integration coverage |
| Bootstrap, env/config loading, provider autoloading | **Implemented** | `src/Core/ApplicationBootstrap.php`, `src/Config/*`, `docs/BOOTSTRAP.md` |
| Container and service providers | **Implemented** | `src/Container/Container.php`, `src/Core/ServiceProvider.php`, provider tests/integration |
| Database manager, PDO wrapper, query/schema/migrations | **Implemented (core APIs)** | `src/Database/*`, migration/DB tests; SQLite widely exercised |
| ORM + named connections + resolver/manager integration | **Partially implemented** | `src/Database/Model.php`, `DatabaseServiceProvider`, model integration tests; real external DB environments vary |
| Event dispatcher and lifecycle event hooks | **Implemented** | `src/Events/*`, `Application` boot/terminate dispatch, integration tests |
| CLI runtime integration for DB commands | **Implemented** | `src/Console/ConsoleApplication.php`, `bin/nora`, console/integration tests; legacy env/config fallback preserved |
| Nora Reactive engine and PHP component model | **Experimental** | `src/Reactive/*`, reactive integration/browser tests, architecture/spec docs |
| Nora Tailwind (build-time CSS + reactive styling) | **Partially implemented** | `resources/css/app.css`, Tailwind example, npm scripts/tests, `ReactiveEngine::mount(..., stylesheetPath)` |
| Authentication/account lifecycle end-to-end product flows | **Partially implemented** | Security classes/migration/store/services exist; no complete controllers/email delivery/MFA/session-revocation-everywhere guarantees |
| MySQL/MariaDB/PostgreSQL production/runtime compatibility claims | **Not verified (in general)** | Driver/config support exists in code; live server compatibility depends on environment and CI/runtime evidence |
| Public Packagist install (`composer require morave/nora`) | **Not verified** | `composer.json` metadata exists; public package publication/availability not verified in-repo |

## Architecture Overview

Nora centers on `Nora\Core\Application` and `Nora\Core\ApplicationBootstrap` as the runtime entry points. Bootstrap loads environment/configuration, constructs core services, registers and boots service providers, loads route definitions, and returns an application instance. The application owns a container, router, middleware pipeline, configuration, and exception handler. Core providers integrate database services (`DatabaseManager`, default connection/PDO, ORM resolver wiring) and a per-application `EventDispatcher`. CLI database commands attempt to reuse the bootstrapped application runtime for the working directory and fall back to legacy database configuration resolution for compatibility.

```mermaid
flowchart TD
    A[ApplicationBootstrap] -->|loads .env/config/routes| B[Application]
    B --> C[Container]
    B --> D[Config]
    B --> E[Router / Middleware Pipeline]
    B --> F[ExceptionHandler]

    A -->|registers & boots| G[Service Providers]
    G --> H[DatabaseServiceProvider]
    G --> I[EventServiceProvider]
    G --> J[App Providers (configured)]

    H --> K[DatabaseManager]
    K --> L[PdoConnection / PDO]
    K --> M[ORM / Models]
    I --> N[EventDispatcher]
    B -. boot/terminate lifecycle events .-> N

    E --> O[HTTP Request/Response]
    P[CLI (bin/nora / ConsoleApplication)] -->|boot working app when available| A
    P -->|DB commands use manager/fallback| K

    Q[Nora Reactive Runtime] -->|mounted into routes/app| B
    Q --> R[Tailwind CSS (build-time stylesheet)]
    R --> O

    S[Planned / incremental integrations]:::planned
    Q -. deeper DI integration .-> S
    B -. broader lifecycle event taxonomy .-> S

    classDef planned stroke-dasharray: 5 5,fill:#fff,stroke:#666,color:#333;
```

**Notes:** relationships shown reflect verified code paths and documented behavior in this repository. Deeper reactive DI integration, universal termination-hook adoption across all entrypoints, and broader event/domain conventions are incremental/planned areas.

## Requirements

- **PHP:** `^8.2` (repository `composer.json`); CI workflows in this checkout test PHP 8.2/8.3 where configured
- **Composer:** 2.x
- **PHP extensions:** `filter`, `json`, `pdo`, `session` (required), `pdo_mysql` suggested for MySQL/MariaDB usage
- **Node.js/npm:** for Tailwind builds and browser tests (`package.json` scripts use Tailwind CSS v4 and Playwright tooling)
- **Database servers:** SQLite works without an external server; MySQL/MariaDB/PostgreSQL support depends on drivers, configuration, and a reachable database server

## Installation

### Framework source checkout (this repository)

```powershell
composer install
composer validate --strict
composer test -- --display-warnings
```

Optional frontend/browser checks (require Node/npm dependencies and browsers where applicable):

```powershell
npm ci
npm run assets:build
npm run test:tailwind
npx playwright install chromium
npm run test:browser
```

### Package installation status

- Composer package name in metadata: `morave/nora`
- Public Packagist availability: **not verified** in this repository. Do not assume `composer require morave/nora` works until published and verified.
- For source/VCS-based consumption in controlled environments, follow [docs/PACKAGE_RELEASE.md](docs/PACKAGE_RELEASE.md) and [docs/INSTALLATION.md](docs/INSTALLATION.md) for the currently supported/verified paths.

## Quick Start

### Bootstrap an application (library/runtime usage)

```php
<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Nora\Core\ApplicationBootstrap;

$app = (new ApplicationBootstrap())->boot(__DIR__);
$app->run();
```

`ApplicationBootstrap` returns a booted `Nora\Core\Application`. It loads `<root>/.env` (if present), merges config sources, validates core app settings, registers/boots providers, loads routes from `<root>/routes.php` or `<root>/src/routes.php` (if present), and returns the app.

### Define routes

```php
<?php

declare(strict_types=1);

use Nora\Core\Application;
use Nora\Http\Request;
use Nora\Http\Response;

return static function (Application $app): void {
    $app->route('GET', '/hello/{name}', static function (Request $request, array $parameters): Response {
        return Response::text('Hello ' . $parameters['name']);
    });
};
```

### Scaffold a new application (CLI)

```powershell
php bin/nora new my-app
cd my-app
composer install
copy .env.example .env
composer exec nora -- serve
```

Generated project structure, npm/Tailwind setup, and Composer source constraints depend on the scaffold templates and current branch/release state. Inspect generated files (especially `composer.json`, `package.json`, `README.md`, `routes.php`/`src/routes.php`) before relying on them for production workflows.

## Project Structure

```text
.
├─ bin/                  # CLI entrypoint (`bin/nora`)
├─ config/               # Framework/default config files (e.g. database.php)
├─ docs/                 # User/developer documentation and audit/release notes
├─ architecture/         # Architecture and specification documents
├─ examples/             # Example applications/demos (including Tailwind dashboard)
├─ resources/            # Frontend source assets (e.g. Tailwind input CSS)
├─ src/                  # Framework source (Nora\*)
│  ├─ Config/ Console/ Container/ Core/ Database/ Events/ Http/
│  ├─ Logging/ Reactive/ Routing/ Security/ Validation/ View/ ...
├─ tests/                # Unit, integration, security, browser, Tailwind tests
├─ composer.json         # Package metadata, autoloading, scripts
├─ package.json          # Node/Tailwind/Playwright scripts for asset and browser checks
└─ phpunit.xml           # PHPUnit configuration
```

## Application Lifecycle

`Nora\Core\ApplicationBootstrap` performs the runtime bootstrap for an application root:

1. Resolve and validate the root directory
2. Load `<root>/.env` if present (without overriding existing process env vars by default loader behavior)
3. Load and merge config: defaults, `config/app.php`, `config/database.php` (wrapped under `database` when needed), `config/{APP_ENV}.php`, and environment-derived overrides
4. Validate core config (`app.environment`, `app.debug`, `logging.level`)
5. Create logger/exception handler and construct `Application`
6. Register configured providers (`providers` or `app.providers`) or default core providers (`DatabaseServiceProvider`, `EventServiceProvider`) when no provider config key is present
7. Boot the application/providers (`Application::boot()`)
8. Load `routes.php` or `src/routes.php` if present (must return a callable receiving `Application`)
9. Return the booted application

`Nora\Core\Application` supports route registration, middleware registration, request handling/dispatch, provider registration/boot, container/config/base-path access, and termination cleanup:

- `boot()` is idempotent, boots providers in registration order, and emits best-effort lifecycle events `application.booting` and `application.booted` when an event dispatcher is available in the container.
- `terminate()` emits best-effort `application.terminating` and `application.terminated` events (when available) and performs best-effort cleanup of resolved database connections via `DatabaseManager::disconnectAll()`.

Lifecycle event payloads are arrays: `['application' => $app, 'event' => '<name>']`.

## Dependency Injection

Container: `Nora\Container\Container`

- **Bindings:** `bind()`, `singleton()`, `instance()`, `alias()`
- **Resolution:** `get()`, `has()` (supports bindings/instances/aliases/resolvable classes)
- **Autowiring:** constructor injection for instantiable classes with type-hinted dependencies and/or default values
- **Safety/behavior:** circular dependency detection, compatibility checks for registered instances, factory return validation

`Application` exposes `$app->container()` and registers core instances: `Application::class`, `Container::class`, and `Config::class`.

```php
<?php

use Nora\Container\Container;

$container = new Container();
$container->singleton(DateTimeImmutable::class, static fn (): DateTimeImmutable => new DateTimeImmutable('now'));
$clock = $container->get(DateTimeImmutable::class);
```

## Service Providers

Base class: `Nora\Core\ServiceProvider`

- `register(): void` — register bindings/services
- `boot(): void` — startup logic after registration phase
- `isDeferred(): bool` — available in API (deferred loading behavior is not a full auto-discovery system)

Providers are registered via `Application::register(...)` (class-string or instance). Duplicate registration throws. If the application is already booted, a later provider’s `boot()` runs immediately after registration.

`ApplicationBootstrap` loads providers from config in deterministic order. If no provider config key is present, it registers default core providers for unified runtime integration:

- `Nora\Database\DatabaseServiceProvider`
- `Nora\Events\EventServiceProvider`

## Database and ORM

### Configuration and manager

- Config file: `config/database.php` (default + named connections, env-driven in framework config template)
- Runtime services: `Nora\Database\DatabaseManager` resolves named/default connections lazily and caches resolved `PdoConnection` instances
- Helpers: `DatabaseConfiguration` supports shared config normalization/loading (including environment-based fallback paths used by CLI compatibility)
- Cleanup/lifecycle: `disconnect($name)`, `disconnectAll()`, `reconnect($name)`; `Application::terminate()` performs best-effort cleanup when manager is resolved

### ORM integration (current state)

`DatabaseServiceProvider` registers database services and integrates ORM resolution with the application’s `DatabaseManager` (lazy). `Nora\Database\Model` supports:

- Explicit per-model connection (`Model::setConnectionFor(...)`)
- Legacy/global connection (`Model::setConnection(...)`)
- Database manager / resolver-based resolution
- Named model connection selection (`protected static ?string $connectionName`)
- Mass-assignment controls (`fillable`/`guarded`, `fill()`, `forceFill()`) with backward-compatible defaults

Model connection integration uses static state for compatibility; tests/multi-runtime scenarios should manage/reset state explicitly (e.g. `Model::clearConnectionState()`).

### Query, schema, migrations, seeders

- `Nora\Database\QueryBuilder` (parameterized query building)
- `Nora\Database\Schema` / `Blueprint` (schema helpers)
- Migrations/seeders and CLI commands exist (`migrate`, `migrate:status`, `migrate:rollback`, `make:migration`, `make:seeder`, `db:seed`)
- Real MySQL/PostgreSQL execution and compatibility depend on configured drivers/servers/credentials; SQLite-backed verification is the most consistently exercised path in-repo

## Event Dispatcher

`Nora\Events\EventDispatcher` supports:

- String and object event dispatch
- Multiple listeners with priority and deterministic ordering
- Wildcard listeners (`*`)
- Listener removal (`forget`) and subscriber registration (`subscribe`)
- Error wrapping with event context when a listener throws

`EventServiceProvider` registers the dispatcher as a per-application singleton (`EventDispatcher::class` and `events` alias) and can register listeners from config (`events.listeners`) during boot.

### Application lifecycle events

When a dispatcher is available, `Application` emits:

- `application.booting`, `application.booted` (during `boot()`)
- `application.terminating`, `application.terminated` (during `terminate()`)

Dispatch is best-effort. Configured listeners registered during provider boot are available for `application.booted` and termination events; `application.booting` timing may precede provider-boot-registered listeners.

## CLI and Console

CLI entrypoint: `bin/nora` → `Nora\Console\ConsoleApplication`.

Verified command names/descriptions from `ConsoleApplication::help()` / dispatch:

```text
--help, -h, help, list      Show help
--version, -V               Show framework development version
new <name>                  Create a safe starter application
make:controller <name>      Generate a controller under src/Controllers
make:model <name>           Generate a model under src/Models
make:middleware <name>      Generate middleware under src/Middleware
make:service <name>         Generate a plain PHP service under src/Services
make:component <name>       Generate a reactive PHP component under src/Reactive
make:view <name>            Generate an escaped .nora template under src/Views
make:test <name>            Generate a PHPUnit route test under tests/Feature
tailwind:init               Add Tailwind CSS v4 without replacing existing scripts
assets:dev                  Watch and compile Tailwind CSS for development
assets:build                Compile minified Tailwind CSS for production
assets:check                Validate Tailwind configuration and compiled CSS
migrate                     Apply pending migrations using NORA_DSN
migrate:status              Show migration state using NORA_DSN
migrate:rollback [n]        Roll back the last batch or n migrations using NORA_DSN
make:migration <name>       Generate a timestamped migration in migrations/
make:seeder <name>          Generate a seeder in database/seeders/
db:seed [name]              Run seeders from database/seeders/ using NORA_DSN
serve [host] [port]         Run the generated app with PHP's development server
```

**Runtime behavior (database commands):** CLI loads `.env` from the working directory, attempts to boot the application runtime via `ApplicationBootstrap` for the working directory (to reuse container/providers/database services when available), and falls back to legacy `DatabaseConfiguration`/environment resolution for compatibility. If unified bootstrap fails and fallback cannot satisfy the command, CLI errors now include bootstrap failure details for traceability.

## Routing and Middleware

- **Router:** `Nora\Routing\Router` supports method/path registration, path parameters, fallback handlers, and dispatch returning `Nora\Http\Response`
- **Middleware contract:** `Nora\Routing\MiddlewareInterface::process(Request $request, callable $next): Response`
- **Pipeline:** `Nora\Routing\MiddlewarePipeline` composes middleware around a destination handler
- **Application API:** `$app->route()`, `$app->middleware()`, `$app->prependMiddleware()`, `$app->fallback()`, `$app->handle()`, `$app->run()`

Example middleware:

```php
<?php

declare(strict_types=1);

use Nora\Http\Request;
use Nora\Http\Response;
use Nora\Routing\MiddlewareInterface;

final class AddHeaderMiddleware implements MiddlewareInterface
{
    public function process(Request $request, callable $next): Response
    {
        $response = $next($request);
        return $response->withHeader('X-Example', 'nora');
    }
}
```

## Nora Reactive

Nora Reactive provides a PHP-first, server-rendered reactive runtime (`Nora\Reactive\ReactiveEngine`) and component/action APIs. It is **experimental** and not a production-readiness guarantee.

**Verified/implemented characteristics (from source and tests/docs):**
- Explicit component registration and mount into an `Application`
- Server-owned state (session-backed page state), action validation/authorization hooks, CSRF and revision protections in the reactive flow
- Component APIs: `ReactiveComponent`, `Component` (safe view composition via `SafeHtml`), `ReactiveAction` (including `publicAction(...)`), `UI` helpers
- DOM reconciliation/runtime assets served by the engine; same-origin stylesheet path can be supplied when mounting

**Integration with application runtime:** reactive pages are mounted on application routes and participate in the HTTP request/response flow. Deeper container-injection conventions for reactive components/services are not fully standardized in the current API.

## Nora Tailwind Integration

Tailwind CSS is a **build-time styling layer** that works alongside Nora Reactive (and other server-rendered HTML output). It does **not** replace the reactive engine.

**Repository evidence:**
- `resources/css/app.css` uses Tailwind CSS v4 directives and `@source` scanning for framework/example source
- `package.json` defines asset build/test scripts (`assets:build`, `assets:dev`, `test:tailwind`, browser tests)
- `examples/nora-tailwind-dashboard/` demonstrates a reactive dashboard mounted with a local stylesheet (`ReactiveEngine::mount(..., '/assets/app.css')`)
- CLI includes `tailwind:init`, `assets:dev`, `assets:build`, `assets:check` commands in this checkout

**Important:** keep utility classes statically discoverable for Tailwind builds. Runtime-generated class fragments may not be emitted. Production CSS should be compiled and served locally (not via CDN/browser compilation) per the project’s integration approach.

## Components and UI Architecture

Nora’s UI/reactive composition in the current reactive API centers on:

- **Reactive components/pages:** implement `Nora\Reactive\ReactiveComponent` or extend `Nora\Reactive\Component` and return safe markup
- **Actions/state transitions:** `Nora\Reactive\ReactiveAction` with argument validation and explicit authorization callbacks (deny-by-default unless authorized; `publicAction(...)` for intentionally public actions)
- **Safe HTML composition:** `Nora\Reactive\UI` and `Nora\Reactive\SafeHtml` for escaped text, validated elements/attributes, keyed collections, and action buttons

Legacy components implementing `ReactiveComponent` directly return trusted markup strings and must handle escaping/markup safety appropriately.

## Configuration

- **Bootstrap-loaded files:** `<root>/.env`, `<root>/config/app.php`, `<root>/config/database.php`, `<root>/config/{environment}.php`
- **Merge order (effective):** framework defaults → app config → database config → environment-specific config → environment-derived overrides (including `APP_ENV`, optional `APP_DEBUG`, `NORA_LOG_LEVEL`)
- **Validation:** bootstrap validates `app.environment`, `app.debug`, and `logging.level`
- **Provider config:** `providers` or `app.providers` (array of provider class names); if no provider config key is present, default core DB/Event providers are auto-registered

Example `config/app.php`:

```php
<?php

declare(strict_types=1);

return [
    'app' => [
        'environment' => 'development',
        'debug' => true,
    ],
    'logging' => [
        'level' => 'debug',
    ],
];
```

## Environment Variables

Common variables observed in repository config/examples/CLI/runtime paths (actual usage depends on component and app config):

| Variable | Purpose | Status/Notes |
| --- | --- | --- |
| `APP_ENV` | Application environment name | **Implemented** (bootstrap/config resolution) |
| `APP_DEBUG` | Debug flag (`true/false` semantics validated by bootstrap parsing) | **Implemented** |
| `NORA_LOG_LEVEL` | Logging level override | **Implemented** (`debug`, `info`, `notice`, `warning`, `error`, `critical`) |
| `DB_CONNECTION` | Default DB connection name | **Implemented** (config/database and CLI fallback resolution paths) |
| `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, `DB_CHARSET`, `DB_SSLMODE` | Database connection parameters | **Implemented** (driver/config dependent) |
| `NORA_DSN`, `NORA_DB_USERNAME`, `NORA_DB_PASSWORD` | Legacy/explicit DB connection settings used by CLI/database flows | **Implemented (compatibility paths)** |
| `NORA_REQUIRE_TEST_DB`, `NORA_TEST_*` | Test/integration environment controls (CI/local DB tests) | **Implemented in tests/CI context** (environment-specific) |

See [.env.example](.env.example) and [config/database.php](config/database.php) for checked-in examples/defaults.

## Security

Nora includes security-related building blocks (sessions, CSRF, authentication helpers/services, authorization gate, validation, upload/storage helpers, account lifecycle APIs/migrations in supported contexts), but **security posture depends on correct application integration, configuration, deployment, and environment**.

- Treat README claims as capability descriptions, not security certification.
- Prefer verified docs: [docs/SECURITY.md](docs/SECURITY.md), [architecture/SECURITY_ARCHITECTURE.md](architecture/SECURITY_ARCHITECTURE.md), [docs/audit/SECURITY_FINDINGS.md](docs/audit/SECURITY_FINDINGS.md), [docs/audit/PHASE_3_AUDIT.md](docs/audit/PHASE_3_AUDIT.md).
- Existing Phase 2/3 hardening work (as documented in audit/release artifacts) should be preserved; do not assume all optional auth/account workflows are fully productized.

## Testing

### PHP (Composer/PHPUnit)

```powershell
composer install
composer validate --strict
composer test -- --display-warnings
```

Additional scripts (from `composer.json`): `composer run test:unit`, `composer run check`.

PHPUnit suites configured in `phpunit.xml`: `Unit`, `Integration`, `Security`.

### Frontend / browser / Tailwind checks (Node/npm)

```powershell
npm ci
npm run assets:build
npm run test:tailwind
npx playwright install chromium
npm run test:browser
```

Availability and results depend on local tooling, browser binaries, and environment.

### CI (repository workflows)

GitHub Actions in `.github/workflows/ci.yml` define package-consumer, PHP matrix/MySQL-backed test jobs, and reactive browser/Tailwind-related checks (as configured in this checkout). Treat CI configuration as evidence of intended automation, not a guarantee of pass status on every branch/environment.

## Supported Databases

| Database | Status | Notes |
| --- | --- | --- |
| SQLite | **Implemented / commonly verified in-repo** | Works without external DB server; used broadly in tests/examples |
| MySQL | **Partially implemented / environment-dependent** | Driver/config/migration support present; live integration depends on server/credentials/extensions and CI/local setup |
| MariaDB | **Partially implemented / not independently verified as distinct target** | Uses MySQL-family driver/config paths; verify in target environment |
| PostgreSQL | **Partially implemented / environment-dependent** | DSN/config support present; verify with real server and application schema requirements |

## Code Examples

### 1) Boot and run an application

```php
<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Nora\Core\ApplicationBootstrap;

(new ApplicationBootstrap())->boot(__DIR__)->run();
```

### 2) Register routes and middleware

```php
<?php

declare(strict_types=1);

use Nora\Core\Application;
use Nora\Http\Request;
use Nora\Http\Response;
use Nora\Routing\MiddlewareInterface;

final class ExampleMiddleware implements MiddlewareInterface
{
    public function process(Request $request, callable $next): Response
    {
        return $next($request)->withHeader('X-Nora', 'example');
    }
}

return static function (Application $app): void {
    $app->middleware(new ExampleMiddleware());
    $app->route('GET', '/ping', static fn (): Response => Response::json(['ok' => true]));
};
```

### 3) Register a service provider

```php
<?php

declare(strict_types=1);

use Nora\Core\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->container()->singleton('clock', static fn (): DateTimeImmutable => new DateTimeImmutable('now'));
    }
}
```

`config/app.php` (provider registration):

```php
<?php

return [
    'providers' => [
        Nora\Database\DatabaseServiceProvider::class,
        Nora\Events\EventServiceProvider::class,
        AppServiceProvider::class,
    ],
];
```

### 4) Events (dispatcher)

```php
<?php

use Nora\Events\EventDispatcher;

/** @var EventDispatcher $dispatcher */
$dispatcher = $app->container()->get(EventDispatcher::class);
$dispatcher->listen('user.registered', static function (array $payload): void {
    // handle event payload
});
$dispatcher->dispatch('user.registered', ['id' => 42]);
```

### 5) Database/ORM (manager-aware resolution)

```php
<?php

use Nora\Database\DatabaseManager;
use Nora\Database\Model;

final class User extends Model
{
    protected static ?string $table = 'users';
}

// When bootstrapped with DatabaseServiceProvider, ORM resolution can use the app DatabaseManager.
// In manual/test setups:
Model::setDatabaseManager($app->container()->get(DatabaseManager::class));

// Example usage depends on schema/data present:
// $user = User::find(1);
```

### 6) Reactive component (experimental API)

```php
<?php

declare(strict_types=1);

use Nora\Reactive\Component;
use Nora\Reactive\ReactiveAction;
use Nora\Reactive\SafeHtml;
use Nora\Reactive\State;
use Nora\Reactive\UI;

final class CounterPage extends Component
{
    public function initialState(): array
    {
        return ['count' => 0];
    }

    public function actions(): array
    {
        return [
            'increment' => ReactiveAction::publicAction(
                ['amount' => 'integer|min:1|max:10'],
                static fn (array $state, array $arguments): array => [
                    ...$state,
                    'count' => $state['count'] + (int) $arguments['amount'],
                ],
            ),
        ];
    }

    protected function view(State $state): SafeHtml
    {
        return UI::element('main', [], [
            UI::element('p', [], ['Count: ', UI::text((string) $state->get('count'))]),
            UI::actionButton('increment', 'Increment', ['amount' => 1]),
        ]);
    }
}
```

## Development Guide

- Read [CONTRIBUTING.md](CONTRIBUTING.md), [CODE_OF_CONDUCT.md](CODE_OF_CONDUCT.md), and [SECURITY.md](SECURITY.md) before proposing changes.
- Preserve backward compatibility and existing security behavior unless a verified fix requires otherwise.
- Run the smallest relevant checks for your change, then broaden as needed (`composer test`, targeted PHPUnit suites, frontend/browser checks when applicable).
- Update documentation when behavior or supported workflows change; distinguish implemented, experimental, and unverified capabilities.
- See also: [docs/TESTING.md](docs/TESTING.md), [docs/ROADMAP_IMPLEMENTATION.md](docs/ROADMAP_IMPLEMENTATION.md), [docs/PACKAGE_RELEASE.md](docs/PACKAGE_RELEASE.md).

## Roadmap

Planned and evolving areas (verify current status before relying on them):

- Continued runtime cohesion across bootstrap/container/providers/events/CLI/HTTP/reactive subsystems
- Deeper and more explicit ORM/database integration patterns across environments
- Broader lifecycle and domain event conventions
- Reactive runtime maturation and integration ergonomics
- Tailwind/UI workflow refinements and documentation alignment
- Additional tooling, docs, and environment verification coverage

See [docs/ROADMAP.md](docs/ROADMAP.md) and [docs/PROJECT_POSITIONING.md](docs/PROJECT_POSITIONING.md) for project direction and positioning context.

## Contributing

Contributions are welcome through issues/discussions and pull requests in the official repository, subject to maintainer review and repository policies.

- [CONTRIBUTING.md](CONTRIBUTING.md)
- [CODE_OF_CONDUCT.md](CODE_OF_CONDUCT.md)
- [SUPPORT.md](SUPPORT.md)
- [RELEASE_POLICY.md](RELEASE_POLICY.md)
- [SECURITY.md](SECURITY.md)

## License

Nora Framework source code in this repository is licensed under the [MIT License](LICENSE), unless otherwise noted. Name and branding usage is governed separately by [TRADEMARKS.md](TRADEMARKS.md).

## Author and Repository Links

- **Project:** Nora Framework (`morave/nora`)
- **Maintainer:** Morave Technologies / [Yasser-devb](https://github.com/Yasser-devb)
- **Repository:** [https://github.com/moravetech/nora](https://github.com/moravetech/nora)
- **Issues:** [https://github.com/moravetech/nora/issues](https://github.com/moravetech/nora/issues)
- **Source:** [https://github.com/moravetech/nora](https://github.com/moravetech/nora)

For implementation details and deeper guides, start with [docs/INSTALLATION.md](docs/INSTALLATION.md), [docs/BOOTSTRAP.md](docs/BOOTSTRAP.md), [docs/API_REFERENCE.md](docs/API_REFERENCE.md), and [architecture/SYSTEM_OVERVIEW.md](architecture/SYSTEM_OVERVIEW.md).Accuracy is prioritized over completeness claims: when a capability is not fully verified, this README marks it accordingly.
