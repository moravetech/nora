<?php

declare(strict_types=1);

namespace Nora\Core;

abstract class ServiceProvider
{
    protected bool $defer = false;

    public function __construct(protected readonly Application $app)
    {
    }

    /**
     * Register container bindings and application services.
     */
    abstract public function register(): void;

    /**
     * Bootstrap services after all providers have been registered.
     */
    public function boot(): void
    {
    }

    public function isDeferred(): bool
    {
        return $this->defer;
    }
}