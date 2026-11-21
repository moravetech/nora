# Events

Nora provides an event dispatcher for registering listeners and dispatching application events.

## Basic usage

```php
use Nora\Events\EventDispatcher;

$dispatcher = new EventDispatcher();

$dispatcher->listen(UserRegistered::class, static function (UserRegistered $event): void {
    // handle event
});

$event = new UserRegistered($user);
$dispatcher->dispatch($event); // returns the event object for object events
```

String event names are also supported:

```php
$dispatcher->listen('user.registered', static function (array $payload): void {
    // handle payload
});

$responses = $dispatcher->dispatch('user.registered', ['id' => 42]);
```

## Multiple listeners and priority

Listeners run in deterministic order: higher priority first, then registration order.

```php
$dispatcher->listen('invoice.paid', $auditListener, priority: 10);
$dispatcher->listen('invoice.paid', $notificationListener, priority: 0);
```

## Listener removal

```php
$dispatcher->forget('invoice.paid', $notificationListener); // remove one listener
$dispatcher->forget('invoice.paid'); // remove all listeners for the event
```

## Wildcard listeners

Wildcard patterns using `*` can match event names:

```php
$dispatcher->listen('user.*', static function (object|string $event): void {
    // handles user.created, user.deleted, etc.
});
```

## Subscribers

Subscribers can register multiple listeners. Implement `Nora\Events\EventSubscriberInterface`:

```php
use Nora\Events\EventDispatcher;
use Nora\Events\EventSubscriberInterface;

final class UserEventSubscriber implements EventSubscriberInterface
{
    public function subscribe(EventDispatcher $dispatcher): array
    {
        return [
            UserRegistered::class => [[$this, 'onRegistered'], 10],
            UserDeleted::class => [$this, 'onDeleted'],
        ];
    }

    public function onRegistered(UserRegistered $event): void {}
    public function onDeleted(UserDeleted $event): void {}
}

$dispatcher->subscribe(new UserEventSubscriber());
```

## Error handling

If a listener throws, the dispatcher wraps the failure in a `RuntimeException` that identifies the event name and preserves the original exception as the previous exception.

## Container and provider integration

`Nora\Events\EventServiceProvider` registers the dispatcher as a per-application container singleton:

```php
use Nora\Events\EventDispatcher;

$dispatcher = $app->container()->get(EventDispatcher::class);
// or via alias: $app->container()->get('events')
```

When bootstrapped with default/core providers (or when explicitly configured), each application instance receives its own dispatcher and listener state.

Providers can also register listeners during `boot()` or via `events.listeners` configuration:

```php
<?php

return [
    'events' => [
        'listeners' => [
            'user.registered' => [\App\Listeners\SendWelcomeEmail::class],
        ],
    ],
];
```

## Application lifecycle integration

When an application container has an `EventDispatcher`, `Nora\Core\Application` dispatches string lifecycle events with array payloads (`['application' => $app, 'event' => '<name>']`):

- `application.booting` and `application.booted` during `Application::boot()` (booted listeners configured via `events.listeners` and registered during provider boot are available for `application.booted`)
- `application.terminating` and `application.terminated` during `Application::terminate()`

Lifecycle event dispatch is best-effort and does not break boot/termination if the dispatcher is unavailable or a lifecycle dispatch cannot be completed.

## Current integration status

- Implemented as `Nora\Events\EventDispatcher` and injectable through the container/provider lifecycle.
- Per-application singleton registration is provided by `Nora\Events\EventServiceProvider`.
- No global static event facade is currently provided.
- Application/framework-wide domain event emission conventions can be extended incrementally where needed. See [SERVICE_PROVIDERS.md](SERVICE_PROVIDERS.md) and [BOOTSTRAP.md](BOOTSTRAP.md).