<?php

declare(strict_types=1);

namespace Nora\Security;

interface EmailVerifiableAccountInterface extends PasswordAccountInterface
{
    public function isEmailVerified(): bool;
}
