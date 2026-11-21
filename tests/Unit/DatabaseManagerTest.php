<?php

declare(strict_types=1);

namespace Nora\Tests\Unit;

use Nora\Database\DatabaseException;
use Nora\Database\DatabaseManager;
use Nora\Database\PdoConnection;
use PHPUnit\Framework\TestCase;

final class DatabaseManagerTest extends TestCase
{
    public function testBuildsSqliteMysqlAndPgsqlDsns(): void
    {
        $manager = new DatabaseManager();

        self::assertSame('sqlite::memory:', $manager->dsnFor(['driver' => 'sqlite', 'database' => ':memory:']));
        self::assertSame('sqlite:/tmp/nora.sqlite', $manager->dsnFor(['driver' => 'sqlite', 'database' => '/tmp/nora.sqlite']));
        self::assertSame(
            'mysql:host=127.0.0.1;port=3306;dbname=nora;charset=utf8mb4',
            $manager->dsnFor(['driver' => 'mysql', 'database' => 'nora', 'host' => '127.0.0.1']),
        );
        self::assertSame(
            'mysql:unix_socket=/var/run/mysqld/mysqld.sock;dbname=nora;charset=utf8mb4',
            $manager->dsnFor(['driver' => 'mariadb', 'database' => 'nora', 'unix_socket' => '/var/run/mysqld/mysqld.sock']),
        );
        self::assertSame(
            'pgsql:host=localhost;port=5432;dbname=nora;sslmode=require',
            $manager->dsnFor(['driver' => 'pgsql', 'database' => 'nora', 'host' => 'localhost', 'sslmode' => 'require']),
        );
    }

    public function testBuildsDsnFromDatabaseUrl(): void
    {
        $manager = new DatabaseManager();

        self::assertSame(
            'pgsql:host=db.example.test;port=5432;dbname=app;sslmode=prefer',
            $manager->dsnFor(['url' => 'postgres://db.example.test/app?sslmode=prefer']),
        );
        self::assertSame(
            'sqlite:/tmp/app.sqlite',
            $manager->dsnFor(['url' => 'sqlite:/tmp/app.sqlite']),
        );
    }

    public function testManagesNamedConnectionsAndLazilyCreatesSqliteConnection(): void
    {
        $manager = new DatabaseManager([
            'default' => 'testing',
            'connections' => [
                'testing' => ['driver' => 'sqlite', 'database' => ':memory:'],
            ],
        ]);

        $connection = $manager->connection();
        self::assertInstanceOf(PdoConnection::class, $connection);
        self::assertSame($connection, $manager->connection('testing'));

        $manager->disconnect('testing');
        self::assertNotSame($connection, $manager->connection('testing'));
    }

    public function testReadsCredentialsFromEnvironmentVariables(): void
    {
        $userVar = 'NORA_TEST_DB_USER_' . strtoupper(bin2hex(random_bytes(4)));
        $passVar = 'NORA_TEST_DB_PASS_' . strtoupper(bin2hex(random_bytes(4)));
        $previousUser = getenv($userVar);
        $previousPass = getenv($passVar);
        putenv($userVar . '=nora_user');
        putenv($passVar . '=nora_secret');
        $_ENV[$userVar] = 'nora_user';
        $_ENV[$passVar] = 'nora_secret';

        try {
            $manager = new DatabaseManager([
                'default' => 'broken',
                'connections' => [
                    'broken' => [
                        'driver' => 'mysql',
                        'host' => '127.0.0.1',
                        'port' => 65000,
                        'database' => 'nora_missing',
                        'username_env' => $userVar,
                        'password_env' => $passVar,
                    ],
                ],
            ]);

            $this->expectException(DatabaseException::class);
            $this->expectExceptionMessage('Unable to connect to database connection "broken".');
            $manager->connection();
        } finally {
            if ($previousUser === false) {
                putenv($userVar);
                unset($_ENV[$userVar]);
            } else {
                putenv($userVar . '=' . $previousUser);
                $_ENV[$userVar] = $previousUser;
            }
            if ($previousPass === false) {
                putenv($passVar);
                unset($_ENV[$passVar]);
            } else {
                putenv($passVar . '=' . $previousPass);
                $_ENV[$passVar] = $previousPass;
            }
        }
    }

    public function testRejectsInvalidOrMissingConnectionConfiguration(): void
    {
        $manager = new DatabaseManager(['default' => 'missing', 'connections' => []]);

        try {
            $manager->connection('missing');
            self::fail('Expected missing connection to fail.');
        } catch (DatabaseException $exception) {
            self::assertStringContainsString('not configured', $exception->getMessage());
        }

        $invalid = new DatabaseManager([
            'default' => 'bad',
            'connections' => ['bad' => ['driver' => 'oracle', 'database' => 'x']],
        ]);

        try {
            $invalid->connection();
            self::fail('Expected unsupported driver to fail.');
        } catch (DatabaseException $exception) {
            self::assertStringContainsString('Unsupported database driver', $exception->getMessage());
        }
    }

    public function testWrapsConnectionFailures(): void
    {
        $manager = new DatabaseManager([
            'default' => 'bad',
            'connections' => [
                'bad' => ['dsn' => 'sqlite:/definitely/not/a/valid/nora/path/database.sqlite'],
            ],
        ]);

        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('Unable to connect to database connection "bad".');
        $manager->connection();
    }

    public function testDisconnectAllClearsResolvedConnections(): void
    {
        $manager = new DatabaseManager([
            'default' => 'sqlite',
            'connections' => [
                'sqlite' => ['driver' => 'sqlite', 'database' => ':memory:'],
                'secondary' => ['driver' => 'sqlite', 'database' => ':memory:'],
            ],
        ]);

        $primary = $manager->connection();
        $secondary = $manager->connection('secondary');

        self::assertSame($primary, $manager->connection());
        self::assertSame($secondary, $manager->connection('secondary'));

        $manager->disconnectAll();

        self::assertNotSame($primary, $manager->connection());
        self::assertNotSame($secondary, $manager->connection('secondary'));
    }
}