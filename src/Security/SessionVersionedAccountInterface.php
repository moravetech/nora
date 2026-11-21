<?php

declare(strict_types=1);

namespace Nora\Security;

interface SessionVersionedAccountInterface extends AuthenticatableInterface
{
    public function sessionVersion(): int;
}