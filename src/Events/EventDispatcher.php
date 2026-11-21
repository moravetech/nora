<?php

declare(strict_types=1);

namespace Nora\Events;

use Closure;
use RuntimeException;
use Throwable;

final class EventDispatcher
{
    /**
     * @var array<string, array<int, list<array{id: int, listener: callable}>>>
     */
    private array $listeners = [];

    /**
     * @var array<string, array<int, list<array{id: int, listener: callable}>>>
     */
    private array $wildcards = [];

    private int $sequence = 0;

    /**
     * @param callable|object|string $listener
     */
    public function listen(string $event, callable|object|string $listener, int $priority = 0): void
    {
        $event = $this->assertEventName($event);
        $resolved = $this->resolveListener($listener);
        $entry = ['id' => $this->sequence++, 'listener' => $resolved];

        if (str_contains($event, '*')) {
            $this->wildcards[$event][$priority][] = $entry;
            krsort($this->wildcards[$event]);
            return;
        }

        $this->listeners[$event][$priority][] = $entry;
        krsort($this->listeners[$event]);
    }

    /**
     * @param object|string $event
     */
    public function dispatch(object|string $event, mixed $payload = null): mixed
    {
        [$name, $eventPayload] = $this->normalizeEvent($event, $payload);
        $responses = [];

        foreach ($this->getListeners($name) as $listener) {
            try {
                $responses[] = $listener($eventPayload);
            } catch (Throwable $exception) {
                throw new RuntimeException(
                    'Event listener failed for "' . $name . '": ' . $exception->getMessage(),
                    0,
                    $exception,
                );
            }
        }

        if (is_object($event)) {
            return $event;
        }

        return $responses;
    }

    public function forget(string $event, ?callable $listener = null): void
    {
        $event = $this->assertEventName($event);
        $bucket = str_contains($event, '*') ? 'wildcards' : 'listeners';
        if (!isset($this->{$bucket}[$event])) {
            return;
        }

        if ($listener === null) {
            unset($this->{$bucket}[$event]);
            return;
        }

        foreach ($this->{$bucket}[$event] as $priority => $entries) {
            $filtered = [];
            foreach ($entries as $entry) {
                if ($entry['listener'] !== $listener) {
                    $filtered[] = $entry;
                }
            }
            if ($filtered === []) {
                unset($this->{$bucket}[$event][$priority]);
            } else {
                $this->{$bucket}[$event][$priority] = $filtered;
            }
        }
        if ($this->{$bucket}[$event] === []) {
            unset($this->{$bucket}[$event]);
        }
    }

    public function hasListeners(string $event): bool
    {
        $event = $this->assertEventName($event);
        return $this->getListeners($event) !== [];
    }

    /**
     * @param object|string $subscriber
     */
    public function subscribe(object|string $subscriber): void
    {
        $instance = is_string($subscriber) ? $this->instantiateSubscriber($subscriber) : $subscriber;

        if ($instance instanceof EventSubscriberInterface) {
            foreach ($instance->subscribe($this) as $event => $definition) {
                $this->registerSubscriberDefinition((string) $event, $definition);
            }
            return;
        }

        if (!method_exists($instance, 'subscribe')) {
            throw new RuntimeException('Event subscriber must implement subscribe().');
        }

        /** @var mixed $subscriptions */
        $subscriptions = $instance->subscribe($this);
        if (!is_array($subscriptions)) {
            throw new RuntimeException('Event subscriber subscriptions must be an array.');
        }
        foreach ($subscriptions as $event => $definition) {
            $this->registerSubscriberDefinition((string) $event, $definition);
        }
    }

    /**
     * @return list<callable>
     */
    public function getListeners(string $event): array
    {
        $event = $this->assertEventName($event);
        $all = [];

        foreach ($this->listeners[$event] ?? [] as $priority => $entries) {
            foreach ($entries as $entry) {
                $all[] = [$priority, $entry['id'], $entry['listener']];
            }
        }

        foreach ($this->wildcards as $pattern => $priorities) {
            if (!$this->matchesWildcard($pattern, $event)) {
                continue;
            }
            foreach ($priorities as $priority => $entries) {
                foreach ($entries as $entry) {
                    $all[] = [$priority, $entry['id'], $entry['listener']];
                }
            }
        }

        usort($all, static function (array $a, array $b): int {
            if ($a[0] !== $b[0]) {
                return $b[0] <=> $a[0];
            }
            return $a[1] <=> $b[1];
        });

        $listeners = [];
        foreach ($all as $item) {
            $listeners[] = $item[2];
        }

        return $listeners;
    }

    /**
     * @param callable|object|string $listener
     */
    private function resolveListener(callable|object|string $listener): callable
    {
        if (is_callable($listener)) {
            return $listener;
        }
        if (is_object($listener) && method_exists($listener, 'handle')) {
            return Closure::fromCallable([$listener, 'handle']);
        }
        if (is_string($listener) && class_exists($listener)) {
            $instance = new $listener();
            if (is_callable($instance)) {
                return $instance;
            }
            if (method_exists($instance, 'handle')) {
                return Closure::fromCallable([$instance, 'handle']);
            }
        }

        throw new RuntimeException('Event listener is not callable.');
    }

    /**
     * @param mixed $definition
     */
    private function registerSubscriberDefinition(string $event, mixed $definition): void
    {
        if (is_array($definition) && isset($definition[0], $definition[1]) && is_int($definition[1])) {
            $listener = $definition[0];
            $priority = $definition[1];
            if (!is_callable($listener) && !is_string($listener) && !is_object($listener)) {
                throw new RuntimeException('Invalid subscriber listener definition.');
            }
            $this->listen($event, $listener, $priority);
            return;
        }
        if (!is_callable($definition) && !is_string($definition) && !is_object($definition)) {
            throw new RuntimeException('Invalid subscriber listener definition.');
        }
        $this->listen($event, $definition);
    }

    private function instantiateSubscriber(string $subscriber): object
    {
        if (!class_exists($subscriber)) {
            throw new RuntimeException('Event subscriber class does not exist: ' . $subscriber);
        }

        return new $subscriber();
    }

    /**
     * @return array{0: string, 1: mixed}
     */
    private function normalizeEvent(object|string $event, mixed $payload): array
    {
        if (is_object($event)) {
            return [$event::class, $event];
        }

        return [$this->assertEventName($event), $payload];
    }

    private function assertEventName(string $event): string
    {
        $event = trim($event);
        if ($event === '') {
            throw new RuntimeException('Event name cannot be empty.');
        }

        return $event;
    }

    private function matchesWildcard(string $pattern, string $event): bool
    {
        $quoted = preg_quote($pattern, '/');
        $regex = '/^' . str_replace('\*', '.*', $quoted) . '$/';

        return preg_match($regex, $event) === 1;
    }
}