<?php

declare(strict_types=1);

namespace Nora\Security;

use DateTimeImmutable;

interface AccountLifecycleStoreInterface extends AccountProviderInterface
{
    public function createAccount(string $email, string $normalizedEmail, string $passwordHash): ?string;

    public function findByIdentifier(string $identifier): ?EmailVerifiableAccountInterface;

    public function issueToken(
        string $identifier,
        string $purpose,
        string $tokenHash,
        DateTimeImmutable $expiresAt,
    ): void;

    public function verifyEmailWithToken(string $tokenHash, DateTimeImmutable $now): bool;

    public function resetPasswordWithToken(
        string $tokenHash,
        string $passwordHash,
        DateTimeImmutable $now,
    ): bool;

    public function findByEmail(string $email): ?EmailVerifiableAccountInterface;
}
