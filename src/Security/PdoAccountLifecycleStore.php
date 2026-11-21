<?php

declare(strict_types=1);

namespace Nora\Security;

use DateTimeImmutable;
use DateTimeZone;
use Nora\Database\PdoConnection;
use PDOException;
use RuntimeException;

final class PdoAccountLifecycleStore implements AccountLifecycleStoreInterface
{
    public function __construct(private readonly PdoConnection $connection)
    {
    }

    public function createAccount(string $email, string $normalizedEmail, string $passwordHash): ?string
    {
        if ($normalizedEmail !== strtolower(trim($email))) {
            throw new \InvalidArgumentException('The normalized account email does not match the supplied address.');
        }

        $identifier = self::uuid();
        try {
            $this->connection->execute(
                'INSERT INTO nora_accounts'
                . ' (id, email, normalized_email, password_hash, email_verified_at, enabled, session_version, created_at, updated_at)'
                . ' VALUES (?, ?, ?, ?, NULL, 1, 1, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))',
                [$identifier, trim($email), $normalizedEmail, $passwordHash],
            );
        } catch (PDOException $exception) {
            if ($exception->getCode() === '23000' && (int) ($exception->errorInfo[1] ?? 0) === 1062) {
                return null;
            }

            throw $exception;
        }

        return $identifier;
    }

    public function findByEmail(string $email): ?EmailVerifiableAccountInterface
    {
        $row = $this->connection->fetchOne(
            'SELECT id, email, password_hash, email_verified_at, enabled, session_version'
            . ' FROM nora_accounts WHERE normalized_email = ? LIMIT 1',
            [strtolower(trim($email))],
        );

        return $row === false ? null : self::accountFromRow($row);
    }

    public function findByIdentifier(string $identifier): ?EmailVerifiableAccountInterface
    {
        $row = $this->connection->fetchOne(
            'SELECT id, email, password_hash, email_verified_at, enabled, session_version'
            . ' FROM nora_accounts WHERE id = ? LIMIT 1',
            [$identifier],
        );

        return $row === false ? null : self::accountFromRow($row);
    }

    public function updatePasswordHash(string $identifier, string $passwordHash): void
    {
        $this->connection->execute(
            'UPDATE nora_accounts SET password_hash = ?, updated_at = UTC_TIMESTAMP(6) WHERE id = ?',
            [$passwordHash, $identifier],
        );
    }

    public function issueToken(
        string $identifier,
        string $purpose,
        string $tokenHash,
        DateTimeImmutable $expiresAt,
    ): void {
        self::assertTokenData($purpose, $tokenHash);
        $this->connection->transaction(function () use ($identifier, $purpose, $tokenHash, $expiresAt): void {
            $this->connection->execute(
                'DELETE FROM nora_account_tokens WHERE account_id = ? AND purpose = ?',
                [$identifier, $purpose],
            );
            $this->connection->execute(
                'INSERT INTO nora_account_tokens (token_hash, account_id, purpose, expires_at, created_at)'
                . ' VALUES (?, ?, ?, ?, UTC_TIMESTAMP(6))',
                [$tokenHash, $identifier, $purpose, self::formatDate($expiresAt)],
            );
        });
    }

    public function verifyEmailWithToken(string $tokenHash, DateTimeImmutable $now): bool
    {
        return $this->consumeToken(
            AccountLifecycleService::EMAIL_VERIFICATION,
            $tokenHash,
            $now,
            static function (self $store, string $identifier): void {
                $store->connection->execute(
                    'UPDATE nora_accounts SET email_verified_at = COALESCE(email_verified_at, UTC_TIMESTAMP(6)),'
                    . ' updated_at = UTC_TIMESTAMP(6) WHERE id = ?',
                    [$identifier],
                );
            },
        );
    }

    public function resetPasswordWithToken(
        string $tokenHash,
        string $passwordHash,
        DateTimeImmutable $now,
    ): bool {
        return $this->consumeToken(
            AccountLifecycleService::PASSWORD_RESET,
            $tokenHash,
            $now,
            static function (self $store, string $identifier) use ($passwordHash): void {
                $store->connection->execute(
                    'UPDATE nora_accounts'
                    . ' SET password_hash = ?, session_version = session_version + 1, updated_at = UTC_TIMESTAMP(6)'
                    . ' WHERE id = ?',
                    [$passwordHash, $identifier],
                );
            },
        );
    }

    private function consumeToken(
        string $purpose,
        string $tokenHash,
        DateTimeImmutable $now,
        callable $apply,
    ): bool {
        if (preg_match('/\A[a-f0-9]{64}\z/D', $tokenHash) !== 1) {
            return false;
        }

        return $this->connection->transaction(function () use ($purpose, $tokenHash, $now, $apply): bool {
            $row = $this->connection->fetchOne(
                'SELECT account_id FROM nora_account_tokens'
                . ' WHERE token_hash = ? AND purpose = ? AND expires_at > ? FOR UPDATE',
                [$tokenHash, $purpose, self::formatDate($now)],
            );
            if ($row === false || !is_string($row['account_id'] ?? null)) {
                return false;
            }

            $deleted = $this->connection->execute(
                'DELETE FROM nora_account_tokens WHERE token_hash = ? AND purpose = ?',
                [$tokenHash, $purpose],
            );
            if ($deleted !== 1) {
                return false;
            }
            $apply($this, $row['account_id']);

            return true;
        });
    }

    private static function accountFromRow(array $row): EmailVerifiableAccountInterface
    {
        foreach (['id', 'email', 'password_hash'] as $field) {
            if (!is_string($row[$field] ?? null)) {
                throw new RuntimeException('The account table returned an invalid account record.');
            }
        }
        if (!is_string($row['enabled'] ?? null) && !is_int($row['enabled'] ?? null)) {
            throw new RuntimeException('The account table returned an invalid enabled state.');
        }
        $sessionVersion = $row['session_version'] ?? 1;
        if (!is_int($sessionVersion) && !is_string($sessionVersion)) {
            throw new RuntimeException('The account table returned an invalid session version.');
        }

        return new PdoLifecycleAccount(
            $row['id'],
            $row['email'],
            $row['password_hash'],
            (bool) $row['enabled'],
            is_string($row['email_verified_at'] ?? null),
            max(1, (int) $sessionVersion),
        );
    }

    private static function assertTokenData(string $purpose, string $tokenHash): void
    {
        if (!in_array($purpose, [
            AccountLifecycleService::EMAIL_VERIFICATION,
            AccountLifecycleService::PASSWORD_RESET,
        ], true)) {
            throw new \InvalidArgumentException('The account token purpose is invalid.');
        }
        if (preg_match('/\A[a-f0-9]{64}\z/D', $tokenHash) !== 1) {
            throw new \InvalidArgumentException('The account token hash is invalid.');
        }
    }

    private static function formatDate(DateTimeImmutable $date): string
    {
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    private static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-'
            . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }
}

/**
 * @internal
 */
final readonly class PdoLifecycleAccount implements EmailVerifiableAccountInterface, SessionVersionedAccountInterface
{
    public function __construct(
        private string $identifier,
        private string $emailAddress,
        private string $hash,
        private bool $enabled,
        private bool $emailVerified,
        private int $sessionVersion = 1,
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
}
