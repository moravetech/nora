<?php

declare(strict_types=1);

namespace Nora\Security;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final class AccountLifecycleService
{
    public const EMAIL_VERIFICATION = 'email_verification';
    public const PASSWORD_RESET = 'password_reset';

    private readonly Closure $clock;

    public function __construct(
        private readonly AccountLifecycleStoreInterface $accounts,
        private readonly AccountLifecycleNotifierInterface $notifier,
        private readonly PasswordHasher $passwordHasher,
        private readonly RateLimiter $rateLimiter,
        private readonly int $verificationTtlSeconds = 86_400,
        private readonly int $passwordResetTtlSeconds = 3_600,
        ?callable $clock = null,
    ) {
        if ($verificationTtlSeconds < 60 || $verificationTtlSeconds > 2_592_000) {
            throw new InvalidArgumentException('Email verification tokens must expire within 60 seconds to 30 days.');
        }
        if ($passwordResetTtlSeconds < 60 || $passwordResetTtlSeconds > 86_400) {
            throw new InvalidArgumentException('Password reset tokens must expire within 60 seconds to 24 hours.');
        }

        $this->clock = Closure::fromCallable($clock ?? static fn (): int => time());
    }

    public function register(string $email, string $password, string $rateLimitKey): AccountLifecycleResult
    {
        $limit = $this->consumeRateLimit($rateLimitKey);
        if ($limit !== null) {
            return $limit;
        }

        $normalizedEmail = self::normalizeEmail($email);
        if ($normalizedEmail === null || !self::validPassword($password)) {
            return AccountLifecycleResult::rejected();
        }

        $identifier = $this->accounts->createAccount(
            trim($email),
            $normalizedEmail,
            $this->passwordHasher->hash($password),
        );
        if ($identifier !== null) {
            $this->sendToken(
                $identifier,
                trim($email),
                self::EMAIL_VERIFICATION,
                $this->verificationTtlSeconds,
            );
        }

        return AccountLifecycleResult::accepted();
    }

    public function requestEmailVerification(string $email, string $rateLimitKey): AccountLifecycleResult
    {
        $limit = $this->consumeRateLimit($rateLimitKey);
        if ($limit !== null) {
            return $limit;
        }

        $normalizedEmail = self::normalizeEmail($email);
        if ($normalizedEmail !== null) {
            $account = $this->accounts->findByEmail($normalizedEmail);
            if ($account !== null && !$account->isEmailVerified() && $account->isEnabled()) {
                $this->sendToken(
                    $account->authIdentifier(),
                    $account->email(),
                    self::EMAIL_VERIFICATION,
                    $this->verificationTtlSeconds,
                );
            }
        }

        return AccountLifecycleResult::accepted();
    }

    public function verifyEmail(string $token, string $rateLimitKey): AccountLifecycleResult
    {
        $limit = $this->consumeRateLimit($rateLimitKey);
        if ($limit !== null) {
            return $limit;
        }
        if (!self::validToken($token)) {
            return AccountLifecycleResult::rejected();
        }

        return $this->accounts->verifyEmailWithToken(
            hash('sha256', $token),
            $this->now(),
        ) ? AccountLifecycleResult::accepted() : AccountLifecycleResult::rejected();
    }

    public function requestPasswordReset(string $email, string $rateLimitKey): AccountLifecycleResult
    {
        $limit = $this->consumeRateLimit($rateLimitKey);
        if ($limit !== null) {
            return $limit;
        }

        $normalizedEmail = self::normalizeEmail($email);
        if ($normalizedEmail !== null) {
            $account = $this->accounts->findByEmail($normalizedEmail);
            if ($account !== null && $account->isEnabled() && $account->isEmailVerified()) {
                $this->sendToken(
                    $account->authIdentifier(),
                    $account->email(),
                    self::PASSWORD_RESET,
                    $this->passwordResetTtlSeconds,
                );
            }
        }

        return AccountLifecycleResult::accepted();
    }

    public function resetPassword(
        string $token,
        string $password,
        string $rateLimitKey,
    ): AccountLifecycleResult {
        $limit = $this->consumeRateLimit($rateLimitKey);
        if ($limit !== null) {
            return $limit;
        }
        if (!self::validToken($token) || !self::validPassword($password)) {
            return AccountLifecycleResult::rejected();
        }

        return $this->accounts->resetPasswordWithToken(
            hash('sha256', $token),
            $this->passwordHasher->hash($password),
            $this->now(),
        ) ? AccountLifecycleResult::accepted() : AccountLifecycleResult::rejected();
    }

    private function sendToken(string $identifier, string $email, string $purpose, int $ttl): void
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $now = $this->now();
        $this->accounts->issueToken(
            $identifier,
            $purpose,
            hash('sha256', $token),
            $now->modify('+' . $ttl . ' seconds'),
        );

        if ($purpose === self::EMAIL_VERIFICATION) {
            $this->notifier->sendEmailVerification($email, $token);
        } else {
            $this->notifier->sendPasswordReset($email, $token);
        }
    }

    private function consumeRateLimit(string $key): ?AccountLifecycleResult
    {
        $rateLimit = $this->rateLimiter->consume($key);

        return $rateLimit->allowed()
            ? null
            : AccountLifecycleResult::throttled($rateLimit->retryAfterSeconds() ?? 1);
    }

    private function now(): DateTimeImmutable
    {
        $timestamp = ($this->clock)();
        if (!is_int($timestamp) || $timestamp < 0) {
            throw new InvalidArgumentException('The account lifecycle clock returned an invalid timestamp.');
        }

        return (new DateTimeImmutable('@' . $timestamp))->setTimezone(new DateTimeZone('UTC'));
    }

    private static function normalizeEmail(string $email): ?string
    {
        $normalized = strtolower(trim($email));

        return strlen($normalized) <= 254 && filter_var($normalized, FILTER_VALIDATE_EMAIL) !== false
            ? $normalized
            : null;
    }

    private static function validPassword(string $password): bool
    {
        return strlen($password) >= 12 && strlen($password) <= 72;
    }

    private static function validToken(string $token): bool
    {
        return preg_match('/\A[A-Za-z0-9_-]{43}\z/D', $token) === 1;
    }
}
