<?php

declare(strict_types=1);

namespace Nora\Core;

use Nora\Config\Config;
use Nora\Container\Container;
use Nora\Database\DatabaseManager;
use Nora\Events\EventDispatcher;
use Nora\Http\Request;
use Nora\Http\Response;
use RuntimeException;
use Throwable;
use Nora\Routing\MiddlewareInterface;
use Nora\Routing\MiddlewarePipeline;
use Nora\Routing\Router;

final class Application
{
    /** @var list<MiddlewareInterface> */
    private array $middleware = [];

    private readonly Config $config;

    private Container $container;

    /** @var array<class-string<ServiceProvider>, ServiceProvider> */
    private array $providers = [];

    /** @var list<ServiceProvider> */
    private array $providerOrder = [];

    private bool $booted = false;

    public function __construct(
        private readonly Router $router = new Router(),
        private readonly MiddlewarePipeline $pipeline = new MiddlewarePipeline(),
        private readonly ExceptionHandler $exceptionHandler = new ExceptionHandler(),
        ?Config $config = null,
        ?Container $container = null,
        private ?string $basePath = null,
    ) {
        $this->config = $config ?? new Config();
        $this->container = $container ?? new Container();
        $this->container->instance(self::class, $this);
        $this->container->instance(Container::class, $this->container);
        $this->container->instance(Config::class, $this->config);
    }

    public function route(string $method, string $pattern, callable $handler): self
    {
        $this->router->add($method, $pattern, $handler);

        return $this;
    }

    public function fallback(callable $handler): self
    {
        $this->router->fallback($handler);

        return $this;
    }

    public function middleware(MiddlewareInterface $middleware): self
    {
        $this->middleware[] = $middleware;

        return $this;
    }

    public function prependMiddleware(MiddlewareInterface $middleware): self
    {
        array_unshift($this->middleware, $middleware);

        return $this;
    }

    public function config(): Config
    {
        return $this->config;
    }

    public function container(): Container
    {
        return $this->container;
    }

    public function basePath(): string
    {
        if (is_string($this->basePath) && $this->basePath !== '') {
            return $this->basePath;
        }

        $cwd = getcwd();
        if ($cwd === false || $cwd === '') {
            throw new RuntimeException('Unable to determine the application base path.');
        }

        $this->basePath = $cwd;

        return $this->basePath;
    }

    /**
     * @param class-string<ServiceProvider>|ServiceProvider $provider
     */
    public function register(string|ServiceProvider $provider): self
    {
        $instance = is_string($provider) ? $this->resolveProvider($provider) : $provider;
        $class = $instance::class;

        if (isset($this->providers[$class])) {
            throw new RuntimeException('Service provider already registered: ' . $class);
        }

        $this->providers[$class] = $instance;
        $this->providerOrder[] = $instance;
        $instance->register();

        if ($this->booted) {
            $instance->boot();
        }

        return $this;
    }

    public function boot(): self
    {
        if ($this->booted) {
            return $this;
        }

        $this->dispatchLifecycleEvent('application.booting');

        foreach ($this->providerOrder as $provider) {
            $provider->boot();
        }

        $this->booted = true;
        $this->dispatchLifecycleEvent('application.booted');

        return $this;
    }

    public function hasBeenBooted(): bool
    {
        return $this->booted;
    }

    public function terminate(): void
    {
        $this->dispatchLifecycleEvent('application.terminating');

        try {
            if ($this->container->has(DatabaseManager::class)) {
                $manager = $this->container->get(DatabaseManager::class);
                if ($manager instanceof DatabaseManager) {
                    $manager->disconnectAll();
                }
            }
        } catch (Throwable) {
            // Resource cleanup is best-effort during termination.
        }

        $this->dispatchLifecycleEvent('application.terminated');
    }

    private function dispatchLifecycleEvent(string $event): void
    {
        try {
            if (!class_exists(EventDispatcher::class) || !$this->container->has(EventDispatcher::class)) {
                return;
            }

            $dispatcher = $this->container->get(EventDispatcher::class);
            if ($dispatcher instanceof EventDispatcher) {
                $dispatcher->dispatch($event, [
                    'application' => $this,
                    'event' => $event,
                ]);
            }
        } catch (Throwable) {
            // Lifecycle event dispatch must not break application boot or termination.
        }
    }

    /**
     * @param class-string<ServiceProvider> $provider
     */
    private function resolveProvider(string $provider): ServiceProvider
    {
        if (!class_exists($provider)) {
            throw new RuntimeException('Service provider class does not exist: ' . $provider);
        }
        if (!is_subclass_of($provider, ServiceProvider::class)) {
            throw new RuntimeException('Class is not a service provider: ' . $provider);
        }

        return new $provider($this);
    }

    public function handle(Request $request): Response
    {
        try {
            return $this->pipeline->handle(
                $request,
                $this->middleware,
                fn (Request $request): Response => $this->router->dispatch($request),
            );
        } catch (Throwable $exception) {
            return $this->exceptionHandler->handle($exception);
        }
    }

    public function run(?Request $request = null): Response
    {
        try {
            $request ??= Request::fromGlobals();
        } catch (Throwable $exception) {
            $response = $this->exceptionHandler->handle($exception);
            $response->send();

            return $response;
        }

        $response = $this->handle($request);
        $response->send();

        return $response;
    }
}
