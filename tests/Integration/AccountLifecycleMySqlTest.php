<?php

declare(strict_types=1);

namespace Nora\Tests\Integration;

use DateTimeImmutable;
use Nora\Database\PdoConnection;
use Nora\Security\AccountLifecycleMigration;
use Nora\Security\AccountLifecycleService;
use Nora\Security\PdoAccountLifecycleStore;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

final class AccountLifecycleMySqlTest extends TestCase
{
    private ?PDO $pdo = null;
    private bool $migrationStarted = false;

    protected function setUp(): void
    {
        $dsn = getenv('NORA_TEST_DSN');
        if ($dsn === false || $dsn === '') {
            if (getenv('NORA_REQUIRE_TEST_DB') === '1') {
                self::fail('NORA_TEST_DSN is required when NORA_REQUIRE_TEST_DB=1.');
            }

            self::markTestSkipped('Set NORA_TEST_DSN to the dedicated loopback nora_test database.');
        }
        if (!str_starts_with(strtolower($dsn), 'mysql:')) {
            self::fail('NORA_TEST_DSN must use the MySQL PDO driver.');
        }

        $parts = [];
        foreach (explode(';', substr($dsn, strlen('mysql:'))) as $part) {
            if ($part === '') {
                continue;
            }
            $pair = explode('=', $part, 2);
            if (count($pair) === 2) {
                $parts[strtolower($pair[0])] = $pair[1];
            }
        }
        if (
            !in_array(strtolower($parts['host'] ?? ''), ['127.0.0.1', 'localhost'], true)
            || ($parts['dbname'] ?? '') !== 'nora_test'
            || getenv('NORA_TEST_DB_NAME') !== 'nora_test'
        ) {
            self::fail('Account lifecycle integration tests require the dedicated loopback nora_test database.');
        }

        $this->pdo = new PDO(
            $dsn,
            getenv('NORA_TEST_DB_USERNAME') ?: null,
            getenv('NORA_TEST_DB_PASSWORD') ?: null,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
        $existing = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()"
            . " AND table_name IN ('nora_accounts', 'nora_account_tokens')",
        )->fetchColumn();
        if ($existing !== 0) {
            self::fail('The dedicated nora_test database must not contain Nora account lifecycle tables.');
        }
    }

    public function testMySqlAccountLifecycleUsesHashedSingleUseExpiringTokens(): void
    {
        $pdo = $this->pdo ?? self::fail('The MySQL test connection was not initialized.');
        $migration = new AccountLifecycleMigration();
        $this->migrationStarted = true;

        try {
            $migration->up($pdo);
            $store = new PdoAccountLifecycleStore(new PdoConnection($pdo));
            $identifier = $store->createAccount(
                'Person@example.test',
                'person@example.test',
                password_hash('correct horse battery', PASSWORD_DEFAULT),
            );
            self::assertIsString($identifier);
            self::assertNull($store->createAccount(
                'person@EXAMPLE.test',
                'person@example.test',
                password_hash('different password', PASSWORD_DEFAULT),
            ));

            $verificationToken = bin2hex(random_bytes(32));
            $store->issueToken(
                $identifier,
                AccountLifecycleService::EMAIL_VERIFICATION,
                hash('sha256', $verificationToken),
                new DateTimeImmutable('+10 minutes'),
            );
            self::assertTrue($store->verifyEmailWithToken(
                hash('sha256', $verificationToken),
                new DateTimeImmutable(),
            ));
            self::assertFalse($store->verifyEmailWithToken(
                hash('sha256', $verificationToken),
                new DateTimeImmutable(),
            ));
            self::assertTrue($store->findByIdentifier($identifier)?->isEmailVerified());

            $resetToken = bin2hex(random_bytes(32));
            $store->issueToken(
                $identifier,
                AccountLifecycleService::PASSWORD_RESET,
                hash('sha256', $resetToken),
                new DateTimeImmutable('+10 minutes'),
            );
            $newPasswordHash = password_hash('a new correct horse battery', PASSWORD_DEFAULT);
            self::assertTrue($store->resetPasswordWithToken(
                hash('sha256', $resetToken),
                $newPasswordHash,
                new DateTimeImmutable(),
            ));
            self::assertTrue(password_verify(
                'a new correct horse battery',
                $store->findByEmail('person@example.test')->passwordHash(),
            ));
            self::assertFalse($store->resetPasswordWithToken(
                hash('sha256', $resetToken),
                password_hash('replayed reset', PASSWORD_DEFAULT),
                new DateTimeImmutable(),
            ));

            $expiredToken = bin2hex(random_bytes(32));
            $store->issueToken(
                $identifier,
                AccountLifecycleService::PASSWORD_RESET,
                hash('sha256', $expiredToken),
                new DateTimeImmutable('-1 second'),
            );
            self::assertFalse($store->resetPasswordWithToken(
                hash('sha256', $expiredToken),
                password_hash('expired reset', PASSWORD_DEFAULT),
                new DateTimeImmutable(),
            ));
            self::assertSame(
                hash('sha256', $expiredToken),
                $pdo->query(
                    'SELECT token_hash FROM nora_account_tokens WHERE purpose = '
                    . $pdo->quote(AccountLifecycleService::PASSWORD_RESET),
                )->fetchColumn(),
                'Expired tokens must not be consumed as valid resets.',
            );
        } finally {
            $migration->down($pdo);
            $this->migrationStarted = false;
        }
    }

    protected function tearDown(): void
    {
        if ($this->migrationStarted && $this->pdo !== null) {
            try {
                (new AccountLifecycleMigration())->down($this->pdo);
            } catch (Throwable $exception) {
                throw new RuntimeException('Unable to clean up account lifecycle test tables.', 0, $exception);
            }
        }
    }
}
