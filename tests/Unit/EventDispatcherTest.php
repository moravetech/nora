<?php

declare(strict_types=1);

namespace Nora\Tests\Unit;

use Nora\Events\EventDispatcher;
use Nora\Events\EventSubscriberInterface;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class EventDispatcherTest extends TestCase
{
    public function testDispatchesMultipleListenersInPriorityAndRegistrationOrder(): void
    {
        $dispatcher = new EventDispatcher();
        $calls = [];

        $dispatcher->listen('event', static function () use (&$calls): void { $calls[] = 'low'; }, 0);
        $dispatcher->listen('event', static function () use (&$calls): void { $calls[] = 'high-1'; }, 10);
        $dispatcher->listen('event', static function () use (&$calls): void { $calls[] = 'high-2'; }, 10);
        $dispatcher->listen('event', static function () use (&$calls): void { $calls[] = 'mid'; }, 5);

        $responses = $dispatcher->dispatch('event', ['id' => 1]);

        self::assertSame(['high-1', 'high-2', 'mid', 'low'], $calls);
        self::assertCount(4, $responses);
    }

    public function testDispatchesObjectEventAndReturnsEventInstance(): void
    {
        $dispatcher = new EventDispatcher();
        $event = new class {
            public bool $handled = false;
        };
        $dispatcher->listen($event::class, static function (object $event): void {
            $event->handled = true;
        });

        $result = $dispatcher->dispatch($event);

        self::assertSame($event, $result);
        self::assertTrue($event->handled);
    }

    public function testSupportsWildcardListeners(): void
    {
        $dispatcher = new EventDispatcher();
        $calls = [];
        $dispatcher->listen('user.*', static function () use (&$calls): void { $calls[] = 'wild'; });
        $dispatcher->listen('user.registered', static function () use (&$calls): void { $calls[] = 'exact'; });

        $dispatcher->dispatch('user.registered');
        $dispatcher->dispatch('user.deleted');

        self::assertSame(['wild', 'exact', 'wild'], $calls);
        self::assertTrue($dispatcher->hasListeners('user.anything'));
        self::assertFalse($dispatcher->hasListeners('order.created'));
    }

    public function testRemovesSpecificListenerOrAllListenersForEvent(): void
    {
        $dispatcher = new EventDispatcher();
        $calls = [];
        $a = static function () use (&$calls): void { $calls[] = 'a'; };
        $b = static function () use (&$calls): void { $calls[] = 'b'; };
        $dispatcher->listen('event', $a);
        $dispatcher->listen('event', $b);
        $dispatcher->forget('event', $a);
        $dispatcher->dispatch('event');
        self::assertSame(['b'], $calls);

        $dispatcher->forget('event');
        $dispatcher->dispatch('event');
        self::assertSame(['b'], $calls);
        self::assertFalse($dispatcher->hasListeners('event'));
    }

    public function testWrapsListenerExceptions(): void
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->listen('event', static function (): void {
            throw new \InvalidArgumentException('boom');
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Event listener failed for "event": boom');
        $dispatcher->dispatch('event');
    }

    public function testSupportsEventSubscribers(): void
    {
        $dispatcher = new EventDispatcher();
        $subscriber = new class implements EventSubscriberInterface {
            /** @var list<string> */
            public array $calls = [];

            /**
             * @return array<string, callable|object|string|array{0: callable|object|string, 1: int}>
             */
            public function subscribe(EventDispatcher $dispatcher): array
            {
                return [
                    'user.created' => [function (): void { $this->calls[] = 'created'; }, 10],
                    'user.deleted' => function (): void { $this->calls[] = 'deleted'; },
                ];
            }
        };

        $dispatcher->subscribe($subscriber);
        $dispatcher->dispatch('user.created');
        $dispatcher->dispatch('user.deleted');

        self::assertSame(['created', 'deleted'], $subscriber->calls);
    }

    public function testRejectsInvalidEventNamesAndListeners(): void
    {
        $dispatcher = new EventDispatcher();

        try {
            $dispatcher->listen('', static fn () => null);
            self::fail('Expected exception for empty event name.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('Event name cannot be empty', $exception->getMessage());
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Event listener is not callable');
        $dispatcher->listen('event', 'NotAListenerClass');
    }
}