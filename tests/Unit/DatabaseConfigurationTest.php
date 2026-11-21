<?php

declare(strict_types=1);

namespace Nora\Tests\Unit;

use Nora\Database\DatabaseConfiguration;
use Nora\Database\DatabaseManager;
use Nora\Database\DatabaseException;
use PHPUnit\Framework\TestCase;

final class DatabaseConfigurationTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $envBackup = [];

    protected function setUp(): void
    {
        foreach (['DB_CONNECTION', 'DB_DATABASE', 'DB_HOST', 'DB_PORT', 'DB_USERNAME', 'DB_PASSWORD', 'DB_CHARSET', 'DB_SSLMODE', 'NORA_DSN', 'NORA_DB_USERNAME', 'NORA_DB_PASSWORD'] as $name) {
            $this->envBackup[$name] = getenv($name);
            putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->envBackup as $name => $value) {
            if ($value === false) {
                putenv($name);
                unset($_ENV[$name], $_SERVER[$name]);
                continue;
            }
            putenv($name . '=' . $value);
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }
    }

    public function testNormalizesDefaultAndNamedConnections(): void
    {
        $config = DatabaseConfiguration::normalize([
            'default' => 'sqlite',
            'connections' => [
                'sqlite' => ['driver' => 'sqlite', 'database' => ':memory:'],
                'mysql' => ['driver' => 'mysql', 'host' => '127.0.0.1', 'database' => 'app'],
            ],
        ]);

        self::assertSame('sqlite', $config['default']);
        self::assertArrayHasKey('sqlite', $config['connections']);
        self::assertArrayHasKey('mysql', $config['connections']);
    }

    public function testWrapsDatabaseKeyInConfiguration(): void
    {
        $config = DatabaseConfiguration::normalize([
            'database' => [
                'default' => 'sqlite',
                'connections' => ['sqlite' => ['driver' => 'sqlite', 'database' => ':memory:']],
            ],
        ]);

        self::assertSame('sqlite', $config['default']);
    }

    public function testRejectsMissingDefaultOrConnections(): void
    {
        $this->expectException(DatabaseException::class);
        DatabaseConfiguration::normalize(['connections' => ['sqlite' => ['driver' => 'sqlite']]]);
    }

    public function testRejectsUnknownDefaultConnection(): void
    {
        $this->expectException(DatabaseException::class);
        DatabaseConfiguration::normalize([
            'default' => 'mysql',
            'connections' => ['sqlite' => ['driver' => 'sqlite', 'database' => ':memory:']],
        ]);
    }

    public function testBuildsManagerFromEnvironmentDsnFallback(): void
    {
        putenv('NORA_DSN=sqlite::memory:');
        $config = DatabaseConfiguration::fromEnvironment();
        $manager = DatabaseManager::fromConfig($config);
        $row = $manager->connection()->fetchOne('SELECT 1 AS ok');

        self::assertIsArray($row);
        self::assertEquals(1, $row['ok']);
    }

    public function testBuildsManagerFromDatabaseEnvironmentVariables(): void
    {
        putenv('DB_CONNECTION=sqlite');
        putenv('DB_DATABASE=:memory:');
        $config = DatabaseConfiguration::fromEnvironment();
        $manager = DatabaseManager::fromConfig($config);
        self::assertSame('sqlite', $config['default']);
        self::assertIsArray($manager->connection()->fetchOne('SELECT 1 AS ok'));
    }

    public function testLoadsDatabaseConfigFileFromRoot(): void
    {
        $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nora-db-config-' . bin2hex(random_bytes(4));
        $configDir = $root . DIRECTORY_SEPARATOR . 'config';
        self::assertTrue(mkdir($configDir, 0755, true));
        file_put_contents(
            $configDir . DIRECTORY_SEPARATOR . 'database.php',
            "<?php return ['default' => 'sqlite', 'connections' => ['sqlite' => ['driver' => 'sqlite', 'database' => ':memory:']]];",
        );

        try {
            $manager = DatabaseConfiguration::manager($root);
            $row = $manager->connection()->fetchOne('SELECT 1 AS ok');
            self::assertIsArray($row);
            self::assertEquals(1, $row['ok']);
        } finally {
            @unlink($configDir . DIRECTORY_SEPARATOR . 'database.php');
            @rmdir($configDir);
            @rmdir($root);
        }
    }

    public function testEnvironmentFallbackRequiresConfiguration(): void
    {
        $this->expectException(DatabaseException::class);
        DatabaseConfiguration::fromEnvironment();
    }
}