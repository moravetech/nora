<?php

declare(strict_types=1);

namespace Nora\Events;

interface EventSubscriberInterface
{
    /**
     * @return array<string, callable|object|string|array{0: callable|object|string, 1: int}>
     */
    public function subscribe(EventDispatcher $dispatcher): array;
}