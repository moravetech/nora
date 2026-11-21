<?php

declare(strict_types=1);

namespace Nora\Security;

final readonly class AccountLifecycleResult
{
    private function __construct(
        private bool $accepted,
        private ?int $retryAfterSeconds,
    ) {
    }

    public static function accepted(): self
    {
        return new self(true, null);
    }

    public static function rejected(): self
    {
        return new self(false, null);
    }

    public static function throttled(int $retryAfterSeconds): self
    {
        return new self(false, $retryAfterSeconds);
    }

    public function isAccepted(): bool
    {
        return $this->accepted;
    }

    public function retryAfterSeconds(): ?int
    {
        return $this->retryAfterSeconds;
    }
}
