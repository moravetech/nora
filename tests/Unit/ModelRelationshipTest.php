<?php

declare(strict_types=1);

namespace Nora\Tests\Unit;

use InvalidArgumentException;
use Nora\Database\Model;
use Nora\Database\PdoConnection;
use Nora\Database\QueryBuilder;
use Nora\Database\Relations\BelongsTo;
use Nora\Database\Relations\BelongsToMany;
use Nora\Database\Relations\HasMany;
use Nora\Database\Relations\HasOne;
use Nora\Database\Schema;
use Nora\Database\Blueprint;
use Nora\Logging\LoggerInterface;
use PHPUnit\Framework\TestCase;

final class ModelRelationshipTest extends TestCase
{
    private PdoConnection $connection;

    /** @var list<array{string, string, array<string, mixed>}> */
    private array $logs = [];

    protected function setUp(): void
    {
        $this->logs = [];
        $logger = new class($this->logs) implements LoggerInterface {
            /** @param list<array{string, string, array<string, mixed>}> $logs */
            public function __construct(private array &$logs)
            {
            }

            public function log(string $level, string $message, array $context = []): void
            {
                $this->logs[] = [$level, $message, $context];
            }
        };

        $this->connection = PdoConnection::connect('sqlite::memory:', null, null, [], $logger);
        Model::setConnection($this->connection);

        Schema::create($this->connection->pdo(), 'rel_users', static function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamp('deleted_at', true);
            $table->timestamps();
        });
        Schema::create($this->connection->pdo(), 'rel_profiles', static function (Blueprint $table): void {
            $table->id();
            $table->integer('rel_user_id');
            $table->string('bio');
        });
        $this->connection->execute(
            'CREATE TABLE rel_posts (id INTEGER PRIMARY KEY AUTOINCREMENT, rel_user_id INTEGER NULL, title VARCHAR(255) NOT NULL, deleted_at TIMESTAMP NULL, created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL)'
        );
        $this->connection->execute(
            'CREATE TABLE rel_comments (id INTEGER PRIMARY KEY AUTOINCREMENT, rel_post_id INTEGER NOT NULL, rel_user_id INTEGER NULL, body VARCHAR(255) NOT NULL)'
        );
        Schema::create($this->connection->pdo(), 'rel_roles', static function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });
        Schema::create($this->connection->pdo(), 'rel_role_rel_user', static function (Blueprint $table): void {
            $table->integer('rel_user_id');
            $table->integer('rel_role_id');
        });
        Schema::create($this->connection->pdo(), 'custom_links', static function (Blueprint $table): void {
            $table->integer('person_ref');
            $table->integer('group_ref');
        });
        Schema::create($this->connection->pdo(), 'rel_groups', static function (Blueprint $table): void {
            $table->id();
            $table->string('label');
        });
        Schema::create($this->connection->pdo(), 'rel_accounts', static function (Blueprint $table): void {
            $table->id();
            $table->integer('owner_ref', false, true);
            $table->string('provider');
        });
    }

    public function testHasOneRelationAndEagerLoading(): void
    {
        $alice = RelUser::create(['name' => 'Alice']);
        $bob = RelUser::create(['name' => 'Bob']);
        $this->connection->execute('INSERT INTO rel_profiles (rel_user_id, bio) VALUES (?, ?)', [$alice->getKey(), 'Engineer']);

        $profile = $alice->profile();
        self::assertInstanceOf(HasOne::class, $profile);
        self::assertNotNull($alice->profile);
        self::assertSame('Engineer', $alice->profile->bio);
        self::assertNull($bob->profile);

        $users = RelUser::with('profile')->get();
        self::assertCount(2, $users);
        self::assertInstanceOf(RelProfile::class, $users[0]->profile);
        self::assertSame('Engineer', $users[0]->profile->bio);
        self::assertNull($users[1]->profile);
        self::assertTrue($users[0]->relationLoaded('profile'));
    }

    public function testHasManyRelationAndEagerLoading(): void
    {
        $alice = RelUser::create(['name' => 'Alice']);
        $bob = RelUser::create(['name' => 'Bob']);
        RelPost::create(['rel_user_id' => $alice->getKey(), 'title' => 'A1']);
        RelPost::create(['rel_user_id' => $alice->getKey(), 'title' => 'A2']);
        RelPost::create(['rel_user_id' => $bob->getKey(), 'title' => 'B1']);

        $posts = $alice->posts();
        self::assertInstanceOf(HasMany::class, $posts);
        self::assertIsArray($alice->posts);
        self::assertCount(2, $alice->posts);

        $users = RelUser::with('posts')->orderBy('id', 'asc')->get();
        self::assertCount(2, $users);
        self::assertCount(2, $users[0]->posts);
        self::assertCount(1, $users[1]->posts);
        self::assertSame('A1', $users[0]->posts[0]->title);
        self::assertTrue($users[1]->relationLoaded('posts'));
    }

    public function testBelongsToRelationHandlesNullForeignKeysAndEagerLoading(): void
    {
        $alice = RelUser::create(['name' => 'Alice']);
        $post = RelPost::create(['rel_user_id' => $alice->getKey(), 'title' => 'Owned']);
        $orphan = RelPost::create(['rel_user_id' => null, 'title' => 'Orphan']);

        self::assertInstanceOf(BelongsTo::class, $post->user());
        self::assertInstanceOf(RelUser::class, $post->user);
        self::assertSame('Alice', $post->user->name);
        self::assertNull($orphan->user);

        $posts = RelPost::with('user')->orderBy('id', 'asc')->get();
        self::assertInstanceOf(RelUser::class, $posts[0]->user);
        self::assertSame('Alice', $posts[0]->user->name);
        self::assertNull($posts[1]->user);
    }

    public function testBelongsToManyRelationWithDefaultPivot(): void
    {
        $alice = RelUser::create(['name' => 'Alice']);
        $bob = RelUser::create(['name' => 'Bob']);
        $admin = RelRole::create(['name' => 'admin']);
        $editor = RelRole::create(['name' => 'editor']);
        $viewer = RelRole::create(['name' => 'viewer']);

        $this->connection->execute('INSERT INTO rel_role_rel_user (rel_user_id, rel_role_id) VALUES (?, ?)', [$alice->getKey(), $admin->getKey()]);
        $this->connection->execute('INSERT INTO rel_role_rel_user (rel_user_id, rel_role_id) VALUES (?, ?)', [$alice->getKey(), $editor->getKey()]);
        $this->connection->execute('INSERT INTO rel_role_rel_user (rel_user_id, rel_role_id) VALUES (?, ?)', [$bob->getKey(), $viewer->getKey()]);

        self::assertInstanceOf(BelongsToMany::class, $alice->roles());
        $roles = $alice->roles;
        self::assertCount(2, $roles);
        self::assertSame(['admin', 'editor'], array_map(static fn (RelRole $r): string => (string) $r->name, $roles));

        $users = RelUser::with('roles')->orderBy('id', 'asc')->get();
        self::assertCount(2, $users);
        self::assertCount(2, $users[0]->roles);
        self::assertCount(1, $users[1]->roles);
        self::assertSame('viewer', $users[1]->roles[0]->name);
    }

    public function testCustomForeignLocalKeysAndPivotConfiguration(): void
    {
        $user = RelUser::create(['name' => 'Carol']);
        $this->connection->execute('INSERT INTO rel_accounts (owner_ref, provider) VALUES (?, ?)', [$user->getKey(), 'github']);
        $group = RelGroup::create(['label' => 'core']);
        $this->connection->execute('INSERT INTO custom_links (person_ref, group_ref) VALUES (?, ?)', [$user->getKey(), $group->getKey()]);

        self::assertCount(1, $user->accounts);
        self::assertSame('github', $user->accounts[0]->provider);

        self::assertCount(1, $user->customGroups);
        self::assertSame('core', $user->customGroups[0]->label);

        $eager = RelUser::with(['accounts', 'customGroups'])->find($user->getKey());
        self::assertNotNull($eager);
        self::assertTrue($eager->relationLoaded('accounts'));
        self::assertTrue($eager->relationLoaded('customGroups'));
        self::assertCount(1, $eager->accounts);
        self::assertCount(1, $eager->customGroups);
    }

    public function testNestedEagerLoadingAvoidsNPlusOne(): void
    {
        $user = RelUser::create(['name' => 'Alice']);
        $post = RelPost::create(['rel_user_id' => $user->getKey(), 'title' => 'Hello']);
        $this->connection->execute('INSERT INTO rel_comments (rel_post_id, rel_user_id, body) VALUES (?, ?, ?)', [$post->getKey(), $user->getKey(), 'Nice']);
        $this->connection->execute('INSERT INTO rel_comments (rel_post_id, rel_user_id, body) VALUES (?, ?, ?)', [$post->getKey(), null, 'Anon']);

        $users = RelUser::with('posts.comments.author')->get();
        self::assertCount(1, $users);
        self::assertCount(1, $users[0]->posts);
        self::assertCount(2, $users[0]->posts[0]->comments);
        self::assertSame('Alice', $users[0]->posts[0]->comments[0]->author->name);
        self::assertNull($users[0]->posts[0]->comments[1]->author);

        // bounded eager-load query counts for nested relations
        $this->logs = [];
        RelUser::with('posts.comments.author')->get();
        self::assertLessThanOrEqual(6, $this->countQueries());
    }

    public function testEagerLoadingIsBoundedForManyParents(): void
    {
        for ($i = 1; $i <= 100; $i++) {
            $user = RelUser::create(['name' => 'User ' . $i]);
            RelPost::create(['rel_user_id' => $user->getKey(), 'title' => 'Post A ' . $i]);
            RelPost::create(['rel_user_id' => $user->getKey(), 'title' => 'Post B ' . $i]);
        }

        $this->logs = [];
        $users = RelUser::with('posts')->get();
        self::assertCount(100, $users);
        foreach ($users as $user) {
            self::assertCount(2, $user->posts);
        }
        $queries = $this->countQueries();
        self::assertLessThanOrEqual(4, $queries, 'Expected bounded eager-load queries, got ' . $queries);
    }

    public function testAlreadyLoadedRelationsDoNotTriggerAdditionalQueries(): void
    {
        $user = RelUser::create(['name' => 'Alice']);
        $this->connection->execute('INSERT INTO rel_profiles (rel_user_id, bio) VALUES (?, ?)', [$user->getKey(), 'Bio']);
        $loaded = RelUser::with('profile')->find($user->getKey());
        self::assertNotNull($loaded);

        $before = $this->countQueries();
        self::assertNotNull($loaded->profile);
        self::assertSame('Bio', $loaded->profile->bio);
        self::assertSame($before, $this->countQueries());
    }

    public function testRelationshipResultsRespectSoftDeletes(): void
    {
        $user = RelUser::create(['name' => 'Alice']);
        $live = RelSoftPost::create(['rel_user_id' => $user->getKey(), 'title' => 'Live']);
        $deleted = RelSoftPost::create(['rel_user_id' => $user->getKey(), 'title' => 'Deleted']);
        $deleted->delete();

        self::assertCount(1, $user->softPosts);
        self::assertSame('Live', $user->softPosts[0]->title);

        $loaded = RelUser::with('softPosts')->find($user->getKey());
        self::assertNotNull($loaded);
        self::assertCount(1, $loaded->softPosts);
        self::assertSame($live->getKey(), $loaded->softPosts[0]->getKey());
    }

    public function testEmptyCollectionsAndMissingResultsAreHandled(): void
    {
        $user = RelUser::create(['name' => 'Lonely']);
        self::assertNull($user->profile);
        self::assertSame([], $user->posts);
        self::assertSame([], $user->roles);

        $loaded = RelUser::with(['profile', 'posts', 'roles'])->find($user->getKey());
        self::assertNotNull($loaded);
        self::assertNull($loaded->profile);
        self::assertSame([], $loaded->posts);
        self::assertSame([], $loaded->roles);
    }

    public function testToArrayIncludesLoadedRelations(): void
    {
        $user = RelUser::create(['name' => 'Alice']);
        $this->connection->execute('INSERT INTO rel_profiles (rel_user_id, bio) VALUES (?, ?)', [$user->getKey(), 'Bio']);
        RelPost::create(['rel_user_id' => $user->getKey(), 'title' => 'P1']);

        $loaded = RelUser::with(['profile', 'posts'])->find($user->getKey());
        self::assertNotNull($loaded);
        $array = $loaded->toArray();
        self::assertIsArray($array['profile']);
        self::assertSame('Bio', $array['profile']['bio']);
        self::assertIsArray($array['posts']);
        self::assertCount(1, $array['posts']);
    }

    public function testRejectsUnsafePivotIdentifiers(): void
    {
        $user = RelUser::create(['name' => 'Alice']);
        $bad = new class($user->getAttributes(), true) extends RelUser {
            public function badRoles(): BelongsToMany
            {
                return $this->belongsToMany(RelRole::class, 'role_user; DROP TABLE rel_users');
            }
        };
        $bad->setAttribute('id', $user->getKey());

        $this->expectException(InvalidArgumentException::class);
        $bad->badRoles()->getResults($this->connection);
    }

    public function testExistingModelCrudBehaviorRemainsIntact(): void
    {
        $user = RelUser::create(['name' => 'Zed']);
        self::assertNotNull(RelUser::find($user->getKey()));
        $user->name = 'Zed 2';
        $user->save();
        $reloaded = RelUser::find($user->getKey());
        self::assertNotNull($reloaded);
        self::assertSame('Zed 2', $reloaded->name);
        self::assertNotNull($reloaded->created_at);
        self::assertNotNull($reloaded->updated_at);
        self::assertSame($user->getKey(), $reloaded->getKey());
    }

    private function countQueries(): int
    {
        $count = 0;
        foreach ($this->logs as [$level, $message]) {
            if ($level === 'debug' && $message === 'Database query executed.') {
                $count++;
            }
        }

        return $count;
    }
}

/** @internal */
class RelUser extends Model
{
    protected static ?string $table = 'rel_users';

    public function profile(): HasOne
    {
        return $this->hasOne(RelProfile::class, 'rel_user_id', 'id');
    }

    public function posts(): HasMany
    {
        return $this->hasMany(RelPost::class, 'rel_user_id', 'id');
    }

    public function softPosts(): HasMany
    {
        return $this->hasMany(RelSoftPost::class, 'rel_user_id', 'id');
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(RelRole::class, 'rel_role_rel_user', 'rel_user_id', 'rel_role_id', 'id', 'id');
    }

    public function accounts(): HasMany
    {
        return $this->hasMany(RelAccount::class, 'owner_ref', 'id');
    }

    public function customGroups(): BelongsToMany
    {
        return $this->belongsToMany(RelGroup::class, 'custom_links', 'person_ref', 'group_ref', 'id', 'id');
    }
}

/** @internal */
class RelProfile extends Model
{
    protected static ?string $table = 'rel_profiles';
    protected static bool $timestamps = false;

    public function user(): BelongsTo
    {
        return $this->belongsTo(RelUser::class, 'rel_user_id', 'id');
    }
}

/** @internal */
class RelPost extends Model
{
    protected static ?string $table = 'rel_posts';

    public function user(): BelongsTo
    {
        return $this->belongsTo(RelUser::class, 'rel_user_id', 'id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(RelComment::class, 'rel_post_id', 'id');
    }
}

/** @internal */
class RelSoftPost extends Model
{
    protected static ?string $table = 'rel_posts';
    protected static bool $softDeletes = true;

    public function user(): BelongsTo
    {
        return $this->belongsTo(RelUser::class, 'rel_user_id', 'id');
    }
}

/** @internal */
class RelComment extends Model
{
    protected static ?string $table = 'rel_comments';
    protected static bool $timestamps = false;

    public function author(): BelongsTo
    {
        return $this->belongsTo(RelUser::class, 'rel_user_id', 'id');
    }
}

/** @internal */
class RelRole extends Model
{
    protected static ?string $table = 'rel_roles';
    protected static bool $timestamps = false;
}

/** @internal */
class RelGroup extends Model
{
    protected static ?string $table = 'rel_groups';
    protected static bool $timestamps = false;
}

/** @internal */
class RelAccount extends Model
{
    protected static ?string $table = 'rel_accounts';
    protected static bool $timestamps = false;
}