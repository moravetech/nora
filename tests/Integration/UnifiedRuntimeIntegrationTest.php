<?php

declare(strict_types=1);

namespace Nora\Tests\Integration;

use Nora\Config\Config;
use Nora\Core\Application;
use Nora\Core\ApplicationBootstrap;
use Nora\Core\ServiceProvider;
use Nora\Database\DatabaseManager;
use Nora\Database\Model;
use Nora\Database\PdoConnection;
use Nora\Database\Schema;
use Nora\Database\Blueprint;
use Nora\Events\EventDispatcher;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class UnifiedRuntimeIntegrationTest extends TestCase
{
    /** @var list<string> */
    private array $temporaryDirectories = [];

    protected function tearDown(): void
    {
        Model::clearConnectionState();
        foreach ($this->temporaryDirectories as $directory) {
            $this->removeDirectory($directory);
        }
        $this->temporaryDirectories = [];
    }

    public function testBootstrapAutoloadsProvidersFromConfigAppProviders(): void
    {
        $root = $this->createApplication([
            'config/app.php' => "<?php\nreturn ['providers' => ['" . addslashes(UnifiedRuntimeMarkerProvider::class) . "']];\n",
            'config/database.php' => "<?php\nreturn ['default' => 'sqlite', 'connections' => ['sqlite' => ['driver' => 'sqlite', 'database' => ':memory:']]];\n",
        ]);

        $app = (new ApplicationBootstrap())->boot($root);
        self::assertInstanceOf(Application::class, $app);
        self::assertTrue($app->container()->has('unified.runtime.marker'));
        self::assertSame('registered-and-booted', $app->container()->get('unified.runtime.marker')->value);
        self::assertTrue($app->hasBeenBooted());
    }

    public function testBootstrapBootsNormallyWithEmptyConfiguredProviderList(): void
    {
        $root = $this->createApplication([
            'config/app.php' => "<?php\nreturn ['providers' => []];\n",
        ]);

        $app = (new ApplicationBootstrap())->boot($root);
        self::assertInstanceOf(Application::class, $app);
        self::assertTrue($app->hasBeenBooted());
    }

    public function testInvalidConfiguredProviderFailsClearly(): void
    {
        $root = $this->createApplication([
            'config/app.php' => "<?php\nreturn ['providers' => ['Not\\\\A\\\\Real\\\\Provider']];\n",
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Service provider class does not exist');
        (new ApplicationBootstrap())->boot($root);
    }

    public function testEventDispatcherIsPerApplicationSingletonAndInjectable(): void
    {
        $root = $this->createApplication([
            'config/database.php' => "<?php\nreturn ['default' => 'sqlite', 'connections' => ['sqlite' => ['driver' => 'sqlite', 'database' => ':memory:']]];\n",
        ]);

        $app = (new ApplicationBootstrap())->boot($root);
        $container = $app->container();

        self::assertTrue($container->has(EventDispatcher::class));
        $dispatcher1 = $container->get(EventDispatcher::class);
        $dispatcher2 = $container->get(EventDispatcher::class);
        self::assertSame($dispatcher1, $dispatcher2);
        self::assertInstanceOf(EventDispatcher::class, $dispatcher1);

        $seen = [];
        $dispatcher1->listen('unified.event', static function (mixed $event) use (&$seen): void {
            if (is_object($event) && property_exists($event, 'name')) {
                $seen[] = $event->name;
            } else {
                $seen[] = $event;
            }
        });
        $dispatcher1->dispatch('unified.event', (object) ['name' => 'hello']);
        self::assertSame(['hello'], $seen);
    }

    public function testMultipleApplicationsHaveIsolatedCoreServices(): void
    {
        $root1 = $this->createApplication([
            'config/app.php' => "<?php\nreturn ['app' => ['name' => 'AppOne']];\n",
            'config/database.php' => "<?php\nreturn ['default' => 'sqlite', 'connections' => ['sqlite' => ['driver' => 'sqlite', 'database' => ':memory:']]];\n",
        ]);
        $root2 = $this->createApplication([
            'config/app.php' => "<?php\nreturn ['app' => ['name' => 'AppTwo']];\n",
            'config/database.php' => "<?php\nreturn ['default' => 'sqlite', 'connections' => ['sqlite' => ['driver' => 'sqlite', 'database' => ':memory:']]];\n",
        ]);

        $app1 = (new ApplicationBootstrap())->boot($root1);
        $app2 = (new ApplicationBootstrap())->boot($root2);

        self::assertNotSame($app1, $app2);
        self::assertNotSame($app1->container(), $app2->container());
        self::assertNotSame($app1->container()->get(EventDispatcher::class), $app2->container()->get(EventDispatcher::class));
        self::assertNotSame($app1->container()->get(DatabaseManager::class), $app2->container()->get(DatabaseManager::class));

        $app1->container()->get(EventDispatcher::class)->listen('only.one', static fn (): null => null);
        self::assertTrue($app1->container()->get(EventDispatcher::class)->hasListeners('only.one'));
        self::assertFalse($app2->container()->get(EventDispatcher::class)->hasListeners('only.one'));
    }

    public function testOrmResolvesThroughBootstrappedApplicationDatabaseManager(): void
    {
        $root = $this->createApplication([
            'config/database.php' => "<?php\nreturn ['default' => 'sqlite', 'connections' => ['sqlite' => ['driver' => 'sqlite', 'database' => ':memory:']]];\n",
        ]);

        $app = (new ApplicationBootstrap())->boot($root);
        /** @var DatabaseManager $manager */
        $manager = $app->container()->get(DatabaseManager::class);
        $connection = $manager->connection();

        Schema::create($connection->pdo(), 'runtime_users', static function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        RuntimeUser::create(['name' => 'Runtime']);
        $found = RuntimeUser::find(1);
        self::assertNotNull($found);
        self::assertSame('Runtime', $found->name);
        self::assertCount(1, RuntimeUser::with([])->get());
    }

    public function testApplicationLifecycleEventsAndTerminationCleanup(): void
    {
        UnifiedRuntimeLifecycleListener::reset();
        $root = $this->createApplication([
            'config/app.php' => "<?php\nreturn ['events' => ['listeners' => ['application.booted' => ['" . addslashes(UnifiedRuntimeLifecycleListener::class) . "'], 'application.terminated' => ['" . addslashes(UnifiedRuntimeLifecycleListener::class) . "']]]];\n",
            'config/database.php' => "<?php\nreturn ['default' => 'sqlite', 'connections' => ['sqlite' => ['driver' => 'sqlite', 'database' => ':memory:']]];\n",
        ]);

        $app = (new ApplicationBootstrap())->boot($root);
        self::assertContains('application.booted', UnifiedRuntimeLifecycleListener::$events);

        /** @var DatabaseManager $manager */
        $manager = $app->container()->get(DatabaseManager::class);
        $connection = $manager->connection();
        self::assertSame($connection, $manager->connection());

        $app->terminate();

        self::assertContains('application.terminated', UnifiedRuntimeLifecycleListener::$events);
        self::assertNotSame($connection, $manager->connection());
    }

    /**
     * @param array<string, string> $files
     */
    private function createApplication(array $files): string
    {
        $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nora-unified-' . bin2hex(random_bytes(8));
        if (!mkdir($root, 0777, true) && !is_dir($root)) {
            throw new RuntimeException('Unable to create temporary application directory.');
        }
        $this->temporaryDirectories[] = $root;

        if (!mkdir($root . DIRECTORY_SEPARATOR . 'config', 0777, true) && !is_dir($root . DIRECTORY_SEPARATOR . 'config')) {
            throw new RuntimeException('Unable to create config directory.');
        }

        foreach ($files as $relativePath => $contents) {
            $path = $root . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relativePath);
            $directory = dirname($path);
            if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
                throw new RuntimeException('Unable to create directory: ' . $directory);
            }
            if (file_put_contents($path, $contents) === false) {
                throw new RuntimeException('Unable to write file: ' . $path);
            }
        }

        return $root;
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            if (is_file($path)) {
                @unlink($path);
            }
            return;
        }

        $items = scandir($path);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $child = $path . DIRECTORY_SEPARATOR . $item;
            if (is_dir($child)) {
                $this->removeDirectory($child);
            } else {
                @unlink($child);
            }
        }
        @rmdir($path);
    }
}

/** @internal */
final class UnifiedRuntimeMarkerProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->container()->instance('unified.runtime.marker', (object) ['value' => 'registered']);
    }

    public function boot(): void
    {
        $this->app->container()->instance('unified.runtime.marker', (object) ['value' => 'registered-and-booted']);
    }
}

/** @internal */
final class UnifiedRuntimeLifecycleListener
{
    /** @var list<string> */
    public static array $events = [];

    public static function reset(): void
    {
        self::$events = [];
    }

    /**
     * @param mixed $event
     */
    public function handle(mixed $event): void
    {
        if (is_array($event) && isset($event['event']) && is_string($event['event'])) {
            self::$events[] = $event['event'];
            return;
        }

        if (is_string($event)) {
            self::$events[] = $event;
        }
    }
}

/** @internal */
final class RuntimeUser extends Model
{
    protected static ?string $table = 'runtime_users';
}