<?php

declare(strict_types=1);

namespace Nora\Tests\Unit;

use Nora\Database\Model;
use Nora\Database\PdoConnection;
use Nora\Database\Schema;
use Nora\Database\Blueprint;
use PHPUnit\Framework\TestCase;

final class ModelTest extends TestCase
{
    private PdoConnection $connection;

    protected function setUp(): void
    {
        $this->connection = PdoConnection::connect('sqlite::memory:');
        Model::setConnection($this->connection);

        Schema::create($this->connection->pdo(), 'test_users', static function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email');
            $table->boolean('active');
            $table->timestamps();
        });

        Schema::create($this->connection->pdo(), 'test_posts', static function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->timestamp('deleted_at', true);
            $table->timestamps();
        });
    }

    public function testCreatesFindsUpdatesAndDeletesModel(): void
    {
        $user = TestUser::create([
            'name' => 'Alice',
            'email' => 'alice@example.test',
            'active' => true,
        ]);

        self::assertTrue($user->exists());
        self::assertNotNull($user->getKey());
        self::assertNotNull($user->created_at);
        self::assertNotNull($user->updated_at);

        $found = TestUser::find($user->getKey());
        self::assertNotNull($found);
        self::assertSame('Alice', $found->name);
        self::assertSame('alice@example.test', $found->email);

        $found->name = 'Alice Updated';
        self::assertTrue($found->save());
        $reloaded = TestUser::find($user->getKey());
        self::assertNotNull($reloaded);
        self::assertSame('Alice Updated', $reloaded->name);

        self::assertCount(1, TestUser::all());
        self::assertTrue($reloaded->delete());
        self::assertNull(TestUser::find($user->getKey()));
    }

    public function testSoftDeletesExcludeDeletedRecordsByDefault(): void
    {
        $post = TestPost::create(['title' => 'Hello']);
        self::assertNotNull(TestPost::find($post->getKey()));

        self::assertTrue($post->delete());
        self::assertNull(TestPost::find($post->getKey()));
        self::assertCount(0, TestPost::all());

        $row = $this->connection->fetchOne('SELECT deleted_at FROM test_posts WHERE id = ?', [$post->getKey()]);
        self::assertIsArray($row);
        self::assertNotNull($row['deleted_at']);
    }

    public function testMassAssignmentRespectsFillableAttributes(): void
    {
        $user = new FillableTestUser([
            'name' => 'Alice',
            'email' => 'alice@example.test',
            'role' => 'admin',
            'active' => true,
        ]);

        self::assertSame('Alice', $user->name);
        self::assertSame('alice@example.test', $user->email);
        self::assertNull($user->role, 'Non-fillable attributes must not be mass-assigned.');
        self::assertNull($user->active);

        $user->fill(['role' => 'admin', 'active' => false]);
        self::assertNull($user->role);
        self::assertNull($user->active);

        $user->role = 'editor';
        self::assertSame('editor', $user->role, 'Direct assignment remains available for explicit trusted code paths.');
    }

    public function testMassAssignmentRespectsGuardedAttributes(): void
    {
        $user = new GuardedTestUser([
            'name' => 'Bob',
            'email' => 'bob@example.test',
            'role' => 'admin',
            'permissions' => 'all',
        ]);

        self::assertSame('Bob', $user->name);
        self::assertSame('bob@example.test', $user->email);
        self::assertNull($user->role);
        self::assertNull($user->permissions);
    }

    public function testForceFillBypassesMassAssignmentProtection(): void
    {
        $user = new FillableTestUser();
        $user->forceFill(['name' => 'Carol', 'role' => 'admin']);

        self::assertSame('Carol', $user->name);
        self::assertSame('admin', $user->role);
    }
}

/**
 * @internal
 */
final class FillableTestUser extends Model
{
    protected static ?string $table = 'test_users';
    protected static bool $timestamps = false;

    /** @var list<string> */
    protected static array $fillable = ['name', 'email'];
}

/**
 * @internal
 */
final class GuardedTestUser extends Model
{
    protected static ?string $table = 'test_users';
    protected static bool $timestamps = false;

    /** @var list<string> */
    protected static array $guarded = ['role', 'permissions'];
}

/**
 * @internal
 */
final class TestUser extends Model
{
    protected static ?string $table = 'test_users';

    /** @var array<string, string> */
    protected array $casts = [
        'active' => 'bool',
    ];
}

/**
 * @internal
 */
final class TestPost extends Model
{
    protected static ?string $table = 'test_posts';
    protected static bool $softDeletes = true;
}