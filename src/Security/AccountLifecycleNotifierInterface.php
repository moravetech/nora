<?php

declare(strict_types=1);

namespace Nora\Security;

interface AccountLifecycleNotifierInterface
{
    public function sendEmailVerification(string $email, string $token): void;

    public function sendPasswordReset(string $email, string $token): void;
}
