<?php

declare(strict_types=1);

namespace Nora\Security;

use Closure;
use InvalidArgumentException;

final class SessionAuthenticator implements AuthenticatorInterface
{
    private const USER_KEY = 'auth.user_id';
    private const SESSION_VERSION_KEY = 'auth.session_version';

    /**
     * @param Closure(string): ?AuthenticatableInterface $userResolver
     */
    public function __construct(
        private readonly SessionStoreInterface $session,
        private readonly Closure $userResolver,
    ) {
    }

    public function user(): ?AuthenticatableInterface
    {
        $identifier = $this->session->get(self::USER_KEY);
        if (!is_string($identifier) || $identifier === '') {
            return null;
        }

        $user = ($this->userResolver)($identifier);
        if ($user === null) {
            $this->clearAuthenticationState();

            return null;
        }

        if ($user instanceof SessionVersionedAccountInterface) {
            $storedVersion = $this->session->get(self::SESSION_VERSION_KEY);
            if (!is_int($storedVersion) || $storedVersion !== $user->sessionVersion()) {
                $this->clearAuthenticationState();

                return null;
            }
        }

        return $user;
    }

    public function login(AuthenticatableInterface $user): void
    {
        $identifier = $user->authIdentifier();
        if ($identifier === '') {
            throw new InvalidArgumentException('An authenticated user identifier cannot be empty.');
        }

        $this->session->regenerateId();
        (new CsrfTokenManager($this->session))->invalidate();
        $this->session->set(self::USER_KEY, $identifier);
        if ($user instanceof SessionVersionedAccountInterface) {
            $this->session->set(self::SESSION_VERSION_KEY, $user->sessionVersion());
        } else {
            $this->session->remove(self::SESSION_VERSION_KEY);
        }
    }

    public function logout(): void
    {
        $this->clearAuthenticationState();
    }

    private function clearAuthenticationState(): void
    {
        $this->session->remove(self::USER_KEY);
        $this->session->remove(self::SESSION_VERSION_KEY);
        $this->session->regenerateId();
        (new CsrfTokenManager($this->session))->invalidate();
    }
}
