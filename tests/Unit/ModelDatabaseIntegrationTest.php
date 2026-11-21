<?php

declare(strict_types=1);

namespace Nora\Tests\Unit;

use Nora\Database\DatabaseManager;
use Nora\Database\Model;
use Nora\Database\PdoConnection;
use Nora\Database\QueryBuilder;
use Nora\Database\Schema;
use Nora\Database\Blueprint;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ModelDatabaseIntegrationTest extends TestCase
{
    protected function tearDown(): void
    {
        Model::clearConnectionState();
    }

    public function testResolvesDefaultConnectionThroughDatabaseManager(): void
    {
        $manager = DatabaseManager::fromConfig([
            'default' => 'sqlite',
            'connections' => ['sqlite' => ['driver' => 'sqlite', 'database' => ':memory:']],
        ]);
        Model::setDatabaseManager($manager);

        $this->createUsersTable(ModelIntegrationUser::connection(), 'model_users');
        ModelIntegrationUser::create(['name' => 'Alice']);

        $user = ModelIntegrationUser::find(1);
        self::assertNotNull($user);
        self::assertSame('Alice', $user->name);

        $users = ModelIntegrationUser::all();
        self::assertCount(1, $users);
        self::assertSame('Alice', $users[0]->name);
    }

    public function testSupportsNamedModelConnectionAndMultipleConnections(): void
    {
        $manager = DatabaseManager::fromConfig([
            'default' => 'primary',
            'connections' => [
                'primary' => ['driver' => 'sqlite', 'database' => ':memory:'],
                'secondary' => ['driver' => 'sqlite', 'database' => ':memory:'],
            ],
        ]);
        Model::setDatabaseManager($manager);

        $primary = $manager->connection('primary');
        $secondary = $manager->connection('secondary');
        $this->createUsersTable($primary, 'model_users');
        $this->createUsersTable($secondary, 'model_reports');

        ModelIntegrationUser::create(['name' => 'Primary User']);
        ModelIntegrationReport::create(['name' => 'Secondary User']);

        self::assertSame('Primary User', ModelIntegrationUser::find(1)?->name);
        self::assertSame('Secondary User', ModelIntegrationReport::find(1)?->name);
        self::assertNotSame($primary, $secondary);
        self::assertCount(1, $primary->fetchAll('SELECT * FROM model_users'));
        self::assertSame('Primary User', $primary->fetchOne('SELECT name FROM model_users')['name'] ?? null);
        self::assertCount(1, $secondary->fetchAll('SELECT * FROM model_reports'));
        self::assertSame('Secondary User', $secondary->fetchOne('SELECT name FROM model_reports')['name'] ?? null);
    }

    public function testExplicitPerModelConnectionTakesPrecedence(): void
    {
        $manager = DatabaseManager::fromConfig([
            'default' => 'primary',
            'connections' => [
                'primary' => ['driver' => 'sqlite', 'database' => ':memory:'],
                'secondary' => ['driver' => 'sqlite', 'database' => ':memory:'],
            ],
        ]);
        $explicit = PdoConnection::connect('sqlite::memory:');
        $this->createUsersTable($manager->connection('primary'), 'model_users');
        $this->createUsersTable($explicit, 'model_users');

        Model::setDatabaseManager($manager);
        Model::setConnectionFor(ModelIntegrationUser::class, $explicit);

        ModelIntegrationUser::create(['name' => 'Explicit']);
        self::assertCount(1, $explicit->fetchAll('SELECT * FROM model_users'));
        self::assertCount(0, $manager->connection('primary')->fetchAll('SELECT * FROM model_users'));
    }

    public function testLegacySetConnectionTakesPrecedenceOverDatabaseManager(): void
    {
        $manager = DatabaseManager::fromConfig([
            'default' => 'sqlite',
            'connections' => ['sqlite' => ['driver' => 'sqlite', 'database' => ':memory:']],
        ]);
        $legacy = PdoConnection::connect('sqlite::memory:');
        $this->createUsersTable($manager->connection(), 'model_users');
        $this->createUsersTable($legacy, 'model_users');

        Model::setDatabaseManager($manager);
        Model::setConnection($legacy); // legacy/global precedence 2 over manager

        ModelIntegrationUser::create(['name' => 'Legacy']);
        self::assertCount(1, $legacy->fetchAll('SELECT * FROM model_users'));
        self::assertCount(0, $manager->connection()->fetchAll('SELECT * FROM model_users'));
    }

    public function testDirectPdoConnectionCompatibilityAndLazyResolution(): void
    {
        $connection = PdoConnection::connect('sqlite::memory:');
        $this->createUsersTable($connection, 'model_users');
        Model::setConnection($connection);

        self::assertSame($connection, ModelIntegrationUser::connection());
        ModelIntegrationUser::create(['name' => 'Direct']);
        self::assertSame('Direct', ModelIntegrationUser::find(1)?->name);

        // Lazy manager resolution: no connection opened until accessed.
        $manager = DatabaseManager::fromConfig([
            'default' => 'sqlite',
            'connections' => ['sqlite' => ['driver' => 'sqlite', 'database' => ':memory:']],
        ]);
        Model::clearConnectionState();
        Model::setDatabaseManager($manager);
        self::assertFalse($manager->hasResolvedConnection('sqlite'));
        $resolved = ModelIntegrationUser::connection();
        self::assertInstanceOf(PdoConnection::class, $resolved);
        self::assertTrue($manager->hasResolvedConnection('sqlite'));
    }

    public function testEagerLoadingNestedRelationsAndConnectionConsistency(): void
    {
        $manager = DatabaseManager::fromConfig([
            'default' => 'sqlite',
            'connections' => ['sqlite' => ['driver' => 'sqlite', 'database' => ':memory:']],
        ]);
        Model::setDatabaseManager($manager);
        $conn = $manager->connection();
        $this->createUsersTable($conn, 'model_users');
        Schema::create($conn->pdo(), 'model_posts', static function (Blueprint $table): void {
            $table->id();
            $table->integer('model_integration_user_id');
            $table->string('title');
            $table->timestamps();
        });
        Schema::create($conn->pdo(), 'model_comments', static function (Blueprint $table): void {
            $table->id();
            $table->integer('model_integration_post_id');
            $table->string('body');
        });

        $user = ModelIntegrationUser::create(['name' => 'Alice']);
        $post = ModelIntegrationPost::create(['model_integration_user_id' => $user->getKey(), 'title' => 'Hello']);
        $conn->execute('INSERT INTO model_comments (model_integration_post_id, body) VALUES (?, ?)', [$post->getKey(), 'Nice']);

        $users = ModelIntegrationUser::with('posts.comments')->get();
        self::assertCount(1, $users);
        self::assertCount(1, $users[0]->posts);
        self::assertSame('Hello', $users[0]->posts[0]->title);
        self::assertCount(1, $users[0]->posts[0]->comments);
        self::assertSame('Nice', $users[0]->posts[0]->comments[0]->body);
    }

    public function testTransactionConsistencyWithResolvedConnection(): void
    {
        $manager = DatabaseManager::fromConfig([
            'default' => 'sqlite',
            'connections' => ['sqlite' => ['driver' => 'sqlite', 'database' => ':memory:']],
        ]);
        Model::setDatabaseManager($manager);
        $this->createUsersTable($manager->connection(), 'model_users');

        try {
            ModelIntegrationUser::connection()->transaction(function (): void {
                ModelIntegrationUser::create(['name' => 'Tx']);
                throw new \RuntimeException('rollback');
            });
        } catch (\RuntimeException $exception) {
            self::assertSame('rollback', $exception->getMessage());
        }

        self::assertCount(0, ModelIntegrationUser::all());
    }

    public function testMissingConnectionErrorsAreClear(): void
    {
        Model::clearConnectionState();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No database connection configured');
        ModelIntegrationUser::connection();
    }

    public function testMultipleApplicationManagersCanBeUsedWithoutAccidentalLeakage(): void
    {
        $manager1 = DatabaseManager::fromConfig([
            'default' => 'sqlite',
            'connections' => ['sqlite' => ['driver' => 'sqlite', 'database' => ':memory:']],
        ]);
        $manager2 = DatabaseManager::fromConfig([
            'default' => 'sqlite',
            'connections' => ['sqlite' => ['driver' => 'sqlite', 'database' => ':memory:']],
        ]);

        $this->createUsersTable($manager1->connection(), 'model_users');
        $this->createUsersTable($manager2->connection(), 'model_users');

        // App/manager 1 context
        Model::clearConnectionState();
        Model::setDatabaseManager($manager1);
        ModelIntegrationUser::create(['name' => 'App1']);
        self::assertCount(1, $manager1->connection()->fetchAll('SELECT * FROM model_users'));
        self::assertCount(0, $manager2->connection()->fetchAll('SELECT * FROM model_users'));

        // Switch to app/manager 2 context (explicit state control avoids leakage)
        Model::clearConnectionState();
        Model::setDatabaseManager($manager2);
        self::assertCount(0, ModelIntegrationUser::all());
        ModelIntegrationUser::create(['name' => 'App2']);
        self::assertCount(1, $manager2->connection()->fetchAll('SELECT * FROM model_users'));
        self::assertCount(1, $manager1->connection()->fetchAll('SELECT * FROM model_users'));
        self::assertSame('App1', $manager1->connection()->fetchOne('SELECT name FROM model_users')['name'] ?? null);
        self::assertSame('App2', $manager2->connection()->fetchOne('SELECT name FROM model_users')['name'] ?? null);

        // Explicit per-model connection remains isolated to that model.
        $explicit = PdoConnection::connect('sqlite::memory:');
        $this->createUsersTable($explicit, 'model_users');
        Model::setConnectionFor(ModelIntegrationUser::class, $explicit);
        ModelIntegrationUser::create(['name' => 'Explicit']);
        self::assertCount(1, $explicit->fetchAll('SELECT * FROM model_users'));
        self::assertCount(1, $manager2->connection()->fetchAll('SELECT * FROM model_users'));
    }

    private function createUsersTable(PdoConnection $connection, string $table): void
    {
        Schema::create($connection->pdo(), $table, static function (Blueprint $blueprint): void {
            $blueprint->id();
            $blueprint->string('name');
            $blueprint->timestamps();
        });
    }
}

/** @internal */
final class ModelIntegrationUser extends Model
{
    protected static ?string $table = 'model_users';

    /** @return \Nora\Database\Relations\HasMany */
    public function posts(): \Nora\Database\Relations\HasMany
    {
        return $this->hasMany(ModelIntegrationPost::class);
    }
}

/** @internal */
final class ModelIntegrationReport extends Model
{
    protected static ?string $table = 'model_reports';
    protected static ?string $connectionName = 'secondary';
}

/** @internal */
final class ModelIntegrationPost extends Model
{
    protected static ?string $table = 'model_posts';

    /** @return \Nora\Database\Relations\HasMany */
    public function comments(): \Nora\Database\Relations\HasMany
    {
        return $this->hasMany(ModelIntegrationComment::class);
    }
}

/** @internal */
final class ModelIntegrationComment extends Model
{
    protected static ?string $table = 'model_comments';
    protected static bool $timestamps = false;
}