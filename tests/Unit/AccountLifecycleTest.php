<?php

declare(strict_types=1);

namespace Nora\Tests\Unit;

use DateTimeImmutable;
use Nora\Security\AccountLifecycleNotifierInterface;
use Nora\Security\AccountLifecycleService;
use Nora\Security\AccountLifecycleStoreInterface;
use Nora\Security\AuthenticatorInterface;
use Nora\Security\EmailVerifiableAccountInterface;
use Nora\Security\PasswordHasher;
use Nora\Security\PasswordLoginService;
use Nora\Security\RateLimiter;
use Nora\Security\RateLimitStoreInterface;
use Nora\Security\SessionAuthenticator;
use Nora\Security\SessionVersionedAccountInterface;
use PHPUnit\Framework\TestCase;

final class AccountLifecycleTest extends TestCase
{
    public function testRegistrationStoresOnlyPasswordHashAndSendsOpaqueVerificationToken(): void
    {
        $store = new LifecycleMemoryStore();
        $notifier = new LifecycleRecordingNotifier();
        $service = $this->service($store, $notifier);

        self::assertTrue($service->register(
            ' Person@Example.Test ',
            'correct horse battery',
            'register:client-1',
        )->isAccepted());
        self::assertCount(1, $store->accounts);
        self::assertSame('Person@Example.Test', $store->accounts[0]->email());
        self::assertNotSame('correct horse battery', $store->accounts[0]->passwordHash());
        self::assertTrue(password_verify('correct horse battery', $store->accounts[0]->passwordHash()));
        self::assertCount(1, $notifier->verificationMessages);
        self::assertMatchesRegularExpression('/\A[A-Za-z0-9_-]{43}\z/D', $notifier->verificationMessages[0][1]);
        self::assertStringNotContainsString(
            $notifier->verificationMessages[0][1],
            implode(',', array_keys($store->tokens)),
        );

        self::assertTrue($service->register(
            'person@example.test',
            'correct horse battery',
            'register:client-2',
        )->isAccepted());
        self::assertCount(1, $store->accounts);
        self::assertCount(1, $notifier->verificationMessages);
    }

    public function testVerificationTokensExpireAndCanOnlyBeUsedOnce(): void
    {
        $store = new LifecycleMemoryStore();
        $notifier = new LifecycleRecordingNotifier();
        $service = $this->service($store, $notifier);
        $this->register($service);
        $account = $store->accounts[0];
        $authenticator = new LifecycleRecordingAuthenticator();
        $login = new PasswordLoginService($store, $authenticator, new PasswordHasher(), $this->limiter());

        self::assertFalse($login->attempt(
            'person@example.test',
            'correct horse battery',
            'login:unverified',
        )->isAuthenticated());
        self::assertNull($authenticator->user());

        $token = $notifier->verificationMessages[0][1];
        self::assertTrue($service->verifyEmail($token, 'verify:first')->isAccepted());
        self::assertTrue($account->isEmailVerified());
        self::assertFalse($service->verifyEmail($token, 'verify:replay')->isAccepted());
        self::assertTrue($login->attempt(
            'person@example.test',
            'correct horse battery',
            'login:verified',
        )->isAuthenticated());
    }

    public function testPasswordResetIsGenericForUnknownEmailsAndConsumesTokenAtomically(): void
    {
        $store = new LifecycleMemoryStore();
        $notifier = new LifecycleRecordingNotifier();
        $service = $this->service($store, $notifier);
        $this->register($service);

        self::assertTrue($service->requestPasswordReset(
            'missing@example.test',
            'reset:missing',
        )->isAccepted());
        self::assertCount(0, $notifier->resetMessages);

        $verificationToken = $notifier->verificationMessages[0][1];
        self::assertTrue($service->verifyEmail($verificationToken, 'verify:reset')->isAccepted());
        self::assertTrue($service->requestPasswordReset(
            ' PERSON@example.test ',
            'reset:known',
        )->isAccepted());
        self::assertCount(1, $notifier->resetMessages);

        $token = $notifier->resetMessages[0][1];
        self::assertTrue($service->resetPassword(
            $token,
            'a new correct horse battery',
            'reset:complete',
        )->isAccepted());
        self::assertTrue(password_verify(
            'a new correct horse battery',
            $store->accounts[0]->passwordHash(),
        ));
        self::assertSame(2, $store->accounts[0]->sessionVersion());
        self::assertFalse($service->resetPassword(
            $token,
            'another new correct horse battery',
            'reset:replay',
        )->isAccepted());
    }

    public function testPasswordResetRevokesExistingAuthenticatedSessions(): void
    {
        $store = new LifecycleMemoryStore();
        $notifier = new LifecycleRecordingNotifier();
        $service = $this->service($store, $notifier);
        $this->register($service);

        $verificationToken = $notifier->verificationMessages[0][1];
        self::assertTrue($service->verifyEmail($verificationToken, 'verify:session')->isAccepted());
        $session = new AccountLifecycleArraySessionStore();
        $authenticator = new SessionAuthenticator(
            $session,
            static fn (string $identifier): ?EmailVerifiableAccountInterface => $store->findByIdentifier($identifier),
        );
        $authenticator->login($store->accounts[0]);
        self::assertNotNull($authenticator->user());

        self::assertTrue($service->requestPasswordReset('person@example.test', 'reset:session')->isAccepted());
        $resetToken = $notifier->resetMessages[0][1];
        self::assertTrue($service->resetPassword($resetToken, 'another correct horse battery', 'reset:session-complete')->isAccepted());
        self::assertNull($authenticator->user(), 'Existing sessions must be invalid after password reset.');
    }

    public function testExpiredAndMalformedTokensAreRejected(): void
    {
        $store = new LifecycleMemoryStore();
        $notifier = new LifecycleRecordingNotifier();
        $now = 1_000;
        $service = $this->service($store, $notifier, static function () use (&$now): int {
            return $now;
        });
        $this->register($service);
        $token = $notifier->verificationMessages[0][1];
        $now += 86_400;

        self::assertFalse($service->verifyEmail($token, 'verify:expired')->isAccepted());
        self::assertFalse($service->verifyEmail('not-a-token', 'verify:malformed')->isAccepted());
        self::assertFalse($store->accounts[0]->isEmailVerified());
    }

    public function testRateLimitsAreAppliedBeforeAccountLookupOrCreation(): void
    {
        $store = new LifecycleMemoryStore();
        $notifier = new LifecycleRecordingNotifier();
        $service = $this->service($store, $notifier, null, 1);

        self::assertTrue($service->register(
            'person@example.test',
            'correct horse battery',
            'register:same-client',
        )->isAccepted());
        $throttled = $service->register(
            'other@example.test',
            'correct horse battery',
            'register:same-client',
        );

        self::assertFalse($throttled->isAccepted());
        self::assertSame(60, $throttled->retryAfterSeconds());
        self::assertCount(1, $store->accounts);
        self::assertCount(1, $notifier->verificationMessages);
    }

    private function register(AccountLifecycleService $service): void
    {
        self::assertTrue($service->register(
            'person@example.test',
            'correct horse battery',
            'register:fixture',
        )->isAccepted());
    }

    private function service(
        LifecycleMemoryStore $store,
        LifecycleRecordingNotifier $notifier,
        ?callable $clock = null,
        int $limit = 10,
    ): AccountLifecycleService {
        return new AccountLifecycleService(
            $store,
            $notifier,
            new PasswordHasher(),
            $this->limiter($limit),
            86_400,
            3_600,
            $clock ?? static fn (): int => 1_000,
        );
    }

    private function limiter(int $limit = 10): RateLimiter
    {
        return new RateLimiter(new LifecycleMemoryRateLimitStore(), $limit, 60, static fn (): int => 120);
    }
}

/**
 * @internal
 */
final class LifecycleMemoryStore implements AccountLifecycleStoreInterface
{
    /** @var list<LifecycleMemoryAccount> */
    public array $accounts = [];

    /** @var array<string, array{identifier: string, purpose: string, expires: int}> */
    public array $tokens = [];

    public function createAccount(string $email, string $normalizedEmail, string $passwordHash): ?string
    {
        if ($this->findByEmail($normalizedEmail) !== null) {
            return null;
        }
        $identifier = 'account-' . count($this->accounts);
        $this->accounts[] = new LifecycleMemoryAccount($identifier, $email, $passwordHash, true, false);

        return $identifier;
    }

    public function findByEmail(string $email): ?EmailVerifiableAccountInterface
    {
        foreach ($this->accounts as $account) {
            if (strtolower($account->email()) === strtolower($email)) {
                return $account;
            }
        }

        return null;
    }

    public function findByIdentifier(string $identifier): ?EmailVerifiableAccountInterface
    {
        foreach ($this->accounts as $account) {
            if ($account->authIdentifier() === $identifier) {
                return $account;
            }
        }

        return null;
    }

    public function updatePasswordHash(string $identifier, string $passwordHash): void
    {
        $account = $this->findByIdentifier($identifier);
        if ($account instanceof LifecycleMemoryAccount) {
            $account->replaceHash($passwordHash);
        }
    }

    public function issueToken(
        string $identifier,
        string $purpose,
        string $tokenHash,
        DateTimeImmutable $expiresAt,
    ): void {
        foreach ($this->tokens as $hash => $token) {
            if ($token['identifier'] === $identifier && $token['purpose'] === $purpose) {
                unset($this->tokens[$hash]);
            }
        }
        $this->tokens[$tokenHash] = [
            'identifier' => $identifier,
            'purpose' => $purpose,
            'expires' => $expiresAt->getTimestamp(),
        ];
    }

    public function verifyEmailWithToken(string $tokenHash, DateTimeImmutable $now): bool
    {
        $token = $this->consumeToken($tokenHash, AccountLifecycleService::EMAIL_VERIFICATION, $now);
        $account = $token === null ? null : $this->findByIdentifier($token['identifier']);
        if (!$account instanceof LifecycleMemoryAccount) {
            return false;
        }
        $account->verifyEmail();

        return true;
    }

    public function resetPasswordWithToken(
        string $tokenHash,
        string $passwordHash,
        DateTimeImmutable $now,
    ): bool {
        $token = $this->consumeToken($tokenHash, AccountLifecycleService::PASSWORD_RESET, $now);
        $account = $token === null ? null : $this->findByIdentifier($token['identifier']);
        if (!$account instanceof LifecycleMemoryAccount) {
            return false;
        }
        $account->replaceHash($passwordHash);
        $account->bumpSessionVersion();

        return true;
    }

    /**
     * @return array{identifier: string, purpose: string, expires: int}|null
     */
    private function consumeToken(string $hash, string $purpose, DateTimeImmutable $now): ?array
    {
        $token = $this->tokens[$hash] ?? null;
        unset($this->tokens[$hash]);

        return $token !== null && $token['purpose'] === $purpose && $token['expires'] > $now->getTimestamp()
            ? $token
            : null;
    }
}

/**
 * @internal
 */
final class LifecycleMemoryAccount implements EmailVerifiableAccountInterface, SessionVersionedAccountInterface
{
    private int $sessionVersion = 1;

    public function __construct(
        private readonly string $identifier,
        private readonly string $emailAddress,
        private string $hash,
        private readonly bool $enabled,
        private bool $emailVerified,
    ) {
    }

    public function authIdentifier(): string
    {
        return $this->identifier;
    }

    public function email(): string
    {
        return $this->emailAddress;
    }

    public function passwordHash(): string
    {
        return $this->hash;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function isEmailVerified(): bool
    {
        return $this->emailVerified;
    }

    public function sessionVersion(): int
    {
        return $this->sessionVersion;
    }

    public function verifyEmail(): void
    {
        $this->emailVerified = true;
    }

    public function replaceHash(string $hash): void
    {
        $this->hash = $hash;
    }

    public function bumpSessionVersion(): void
    {
        $this->sessionVersion++;
    }
}

/**
 * @internal
 */
final class LifecycleRecordingNotifier implements AccountLifecycleNotifierInterface
{
    /** @var list<array{string, string}> */
    public array $verificationMessages = [];

    /** @var list<array{string, string}> */
    public array $resetMessages = [];

    public function sendEmailVerification(string $email, string $token): void
    {
        $this->verificationMessages[] = [$email, $token];
    }

    public function sendPasswordReset(string $email, string $token): void
    {
        $this->resetMessages[] = [$email, $token];
    }
}

/**
 * @internal
 */
final class LifecycleRecordingAuthenticator implements AuthenticatorInterface
{
    private ?EmailVerifiableAccountInterface $account = null;

    public function user(): ?EmailVerifiableAccountInterface
    {
        return $this->account;
    }

    public function login(\Nora\Security\AuthenticatableInterface $user): void
    {
        $this->account = $user instanceof EmailVerifiableAccountInterface ? $user : null;
    }

    public function logout(): void
    {
        $this->account = null;
    }
}

/**
 * @internal
 */
final class LifecycleMemoryRateLimitStore implements RateLimitStoreInterface
{
    /** @var array<string, int> */
    private array $counts = [];

    public function consume(string $bucketHash, int $limit, int $windowStart, int $resetAt): int
    {
        return $this->counts[$bucketHash] = ($this->counts[$bucketHash] ?? 0) + 1;
    }

    public function cleanup(int $now): int
    {
        return 0;
    }
}

/**
 * @internal
 */
final class AccountLifecycleArraySessionStore implements \Nora\Security\SessionStoreInterface
{
    /** @var array<string, mixed> */
    private array $values = [];

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->values[$key] ?? $default;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->values);
    }

    public function set(string $key, mixed $value): void
    {
        $this->values[$key] = $value;
    }

    public function remove(string $key): void
    {
        unset($this->values[$key]);
    }

    public function regenerateId(): void
    {
    }
}
