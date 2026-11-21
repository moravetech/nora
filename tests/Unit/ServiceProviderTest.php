<?php

declare(strict_types=1);

namespace Nora\Tests\Unit;

use Nora\Config\Config;
use Nora\Container\Container;
use Nora\Core\Application;
use Nora\Core\ServiceProvider;
use Nora\Database\DatabaseManager;
use Nora\Database\DatabaseServiceProvider;
use Nora\Database\PdoConnection;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ServiceProviderTest extends TestCase
{
    public function testApplicationProvidesContainerAndBasePath(): void
    {
        $container = new Container();
        $app = new Application(config: new Config(['app' => ['environment' => 'test']]), container: $container, basePath: __DIR__);

        self::assertSame($container, $app->container());
        self::assertSame(__DIR__, $app->basePath());
        self::assertSame($app, $container->get(Application::class));
        self::assertInstanceOf(Config::class, $container->get(Config::class));
    }

    public function testRegistersAndBootsProvidersInOrder(): void
    {
        $app = new Application(config: new Config());
        $log = [];
        $app->register(new class($app, $log) extends ServiceProvider {
            /** @param list<string> $log */
            public function __construct(Application $app, private array &$log) { parent::__construct($app); }
            public function register(): void { $this->log[] = 'a-register'; }
            public function boot(): void { $this->log[] = 'a-boot'; }
        });
        $app->register(new class($app, $log) extends ServiceProvider {
            /** @param list<string> $log */
            public function __construct(Application $app, private array &$log) { parent::__construct($app); }
            public function register(): void { $this->log[] = 'b-register'; }
            public function boot(): void { $this->log[] = 'b-boot'; }
        });
        $app->boot();

        self::assertSame(['a-register', 'b-register', 'a-boot', 'b-boot'], $log);
        self::assertTrue($app->hasBeenBooted());
    }

    public function testBootIsIdempotentAndLateRegistrationBootsImmediately(): void
    {
        $app = new Application(config: new Config());
        $log = [];
        $app->register(new class($app, $log) extends ServiceProvider {
            /** @param list<string> $log */
            public function __construct(Application $app, private array &$log) { parent::__construct($app); }
            public function register(): void { $this->log[] = 'register'; }
            public function boot(): void { $this->log[] = 'boot'; }
        });
        $app->boot();
        $app->boot();
        $app->register(new class($app, $log) extends ServiceProvider {
            /** @param list<string> $log */
            public function __construct(Application $app, private array &$log) { parent::__construct($app); }
            public function register(): void { $this->log[] = 'late-register'; }
            public function boot(): void { $this->log[] = 'late-boot'; }
        });

        self::assertSame(['register', 'boot', 'late-register', 'late-boot'], $log);
    }

    public function testPreventsDuplicateProviderRegistration(): void
    {
        $app = new Application(config: new Config());
        $provider = new class($app) extends ServiceProvider {
            public function register(): void {}
        };
        $app->register($provider);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Service provider already registered');
        $app->register($provider);
    }

    public function testDatabaseServiceProviderRegistersDatabaseServices(): void
    {
        $app = new Application(config: new Config([
            'database' => [
                'default' => 'sqlite',
                'connections' => [
                    'sqlite' => ['driver' => 'sqlite', 'database' => ':memory:'],
                ],
            ],
        ]), basePath: sys_get_temp_dir());

        $app->register(DatabaseServiceProvider::class);
        $app->boot();

        $manager = $app->container()->get(DatabaseManager::class);
        self::assertInstanceOf(DatabaseManager::class, $manager);
        $connection = $app->container()->get(PdoConnection::class);
        self::assertInstanceOf(PdoConnection::class, $connection);
        $pdo = $app->container()->get(PDO::class);
        self::assertInstanceOf(PDO::class, $pdo);
        self::assertSame($connection->pdo(), $pdo);
        self::assertSame($connection, $app->container()->get(PdoConnection::class));
        self::assertEquals(1, $connection->fetchOne('SELECT 1 AS ok')['ok'] ?? null);
    }

    public function testDatabaseServiceProviderSupportsRootLevelDatabaseConfig(): void
    {
        $app = new Application(config: new Config([
            'default' => 'sqlite',
            'connections' => [
                'sqlite' => ['driver' => 'sqlite', 'database' => ':memory:'],
            ],
        ]), basePath: sys_get_temp_dir());

        $app->register(DatabaseServiceProvider::class);

        self::assertInstanceOf(DatabaseManager::class, $app->container()->get(DatabaseManager::class));
        self::assertEquals(1, $app->container()->get(PdoConnection::class)->fetchOne('SELECT 1 AS ok')['ok'] ?? null);
    }
}