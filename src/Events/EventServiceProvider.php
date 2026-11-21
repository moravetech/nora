<?php

declare(strict_types=1);

namespace Nora\Events;

use Nora\Core\ServiceProvider;

final class EventServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $container = $this->app->container();

        $container->singleton(EventDispatcher::class, static fn (): EventDispatcher => new EventDispatcher());
        $container->alias('events', EventDispatcher::class);
    }

    public function boot(): void
    {
        $config = $this->app->config();
        $listeners = $config->get('events.listeners', []);
        if (!is_array($listeners) || $listeners === []) {
            return;
        }

        /** @var EventDispatcher $dispatcher */
        $dispatcher = $this->app->container()->get(EventDispatcher::class);
        foreach ($listeners as $event => $eventListeners) {
            if (!is_string($event) || $event === '') {
                continue;
            }
            $list = is_array($eventListeners) ? $eventListeners : [$eventListeners];
            foreach ($list as $listener) {
                $dispatcher->listen($event, $listener);
            }
        }
    }
}