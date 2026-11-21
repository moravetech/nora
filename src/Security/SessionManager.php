<?php

declare(strict_types=1);

namespace Nora\Security;

use InvalidArgumentException;
use RuntimeException;

final class SessionManager implements SessionStoreInterface
{
    private const CREATED_AT_KEY = '_nora.session.created_at';
    private const LAST_ACTIVITY_KEY = '_nora.session.last_activity';

    public function __construct(
        private readonly bool $secureCookies = true,
        private readonly int $idleTimeoutSeconds = 0,
        private readonly int $absoluteLifetimeSeconds = 0,
    ) {
        if ($idleTimeoutSeconds < 0 || $absoluteLifetimeSeconds < 0) {
            throw new InvalidArgumentException('Session timeout values cannot be negative.');
        }
    }

    public function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $this->enforceExpiration();

            return;
        }

        if (session_status() !== PHP_SESSION_NONE) {
            throw new RuntimeException('A PHP session cannot be started in the current state.');
        }

        if (ini_set('session.use_strict_mode', '1') === false
            || ini_set('session.use_only_cookies', '1') === false
            || ini_set('session.use_trans_sid', '0') === false
        ) {
            throw new RuntimeException('Unable to apply secure PHP session settings.');
        }

        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => $this->secureCookies,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        if (!session_start()) {
            throw new RuntimeException('Unable to start the PHP session.');
        }

        $this->enforceExpiration();
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $this->assertStarted();
        $this->enforceExpiration();

        return $_SESSION[$key] ?? $default;
    }

    public function has(string $key): bool
    {
        $this->assertStarted();
        $this->enforceExpiration();

        return array_key_exists($key, $_SESSION);
    }

    public function set(string $key, mixed $value): void
    {
        $this->assertStarted();
        $this->enforceExpiration();
        $_SESSION[$key] = $value;
    }

    public function remove(string $key): void
    {
        $this->assertStarted();
        $this->enforceExpiration();
        unset($_SESSION[$key]);
    }

    public function regenerateId(): void
    {
        $this->assertStarted();
        if (!session_regenerate_id(true)) {
            throw new RuntimeException('Unable to regenerate the session identifier.');
        }
    }

    public function close(): void
    {
        $this->assertStarted();
        if (!session_write_close()) {
            throw new RuntimeException('Unable to close the PHP session.');
        }
    }

    public function destroy(): void
    {
        $this->assertStarted();
        $_SESSION = [];
        if (!session_destroy()) {
            throw new RuntimeException('Unable to destroy the PHP session.');
        }

        $params = session_get_cookie_params();
        $expired = setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $params['path'],
            'domain' => $params['domain'],
            'secure' => $params['secure'],
            'httponly' => $params['httponly'],
            'samesite' => $params['samesite'],
        ]);
        if (!$expired) {
            throw new RuntimeException('Unable to expire the PHP session cookie.');
        }
    }

    private function assertStarted(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            throw new RuntimeException('Start the session before accessing session data.');
        }
    }

    private function enforceExpiration(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }

        if ($this->idleTimeoutSeconds === 0 && $this->absoluteLifetimeSeconds === 0) {
            return;
        }

        $now = time();
        $createdAt = $this->timestampValue($_SESSION[self::CREATED_AT_KEY] ?? null);
        $lastActivity = $this->timestampValue($_SESSION[self::LAST_ACTIVITY_KEY] ?? null);

        if ($createdAt === null || $lastActivity === null) {
            $_SESSION[self::CREATED_AT_KEY] = $now;
            $_SESSION[self::LAST_ACTIVITY_KEY] = $now;

            return;
        }

        $expired = (
            $this->absoluteLifetimeSeconds > 0
            && ($now - $createdAt) >= $this->absoluteLifetimeSeconds
        ) || (
            $this->idleTimeoutSeconds > 0
            && ($now - $lastActivity) >= $this->idleTimeoutSeconds
        );

        if ($expired) {
            $_SESSION = [];
            if (!session_regenerate_id(true)) {
                throw new RuntimeException('Unable to rotate an expired session.');
            }
            $now = time();
            $_SESSION[self::CREATED_AT_KEY] = $now;
            $_SESSION[self::LAST_ACTIVITY_KEY] = $now;

            return;
        }

        $_SESSION[self::LAST_ACTIVITY_KEY] = $now;
    }

    private function timestampValue(mixed $value): ?int
    {
        return is_int($value) && $value >= 0 ? $value : null;
    }
}
