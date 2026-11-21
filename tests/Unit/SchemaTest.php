<?php

declare(strict_types=1);

namespace Nora\Tests\Unit;

use Nora\Database\Blueprint;
use Nora\Database\Schema;
use PDO;
use PHPUnit\Framework\TestCase;

final class SchemaTest extends TestCase
{
    public function testCreatesChecksAndDropsTablesWithBlueprintOnSqlite(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        self::assertFalse(Schema::hasTable($pdo, 'users'));

        Schema::create($pdo, 'users', static function (Blueprint $table): void {
            $table->id();
            $table->string('email');
            $table->string('name');
            $table->boolean('active');
            $table->timestamps();
            $table->unique('email');
            $table->index('name');
        });

        self::assertTrue(Schema::hasTable($pdo, 'users'));

        $statement = $pdo->prepare('INSERT INTO users (email, name, active, created_at, updated_at) VALUES (?, ?, ?, ?, ?)');
        $statement->execute(['a@example.test', 'Alice', 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00']);
        $row = $pdo->query('SELECT email, name, active FROM users')->fetch(PDO::FETCH_ASSOC);
        self::assertSame(['email' => 'a@example.test', 'name' => 'Alice', 'active' => 1], $row);

        Schema::drop($pdo, 'users');
        self::assertFalse(Schema::hasTable($pdo, 'users'));

        Schema::dropIfExists($pdo, 'users');
        self::assertFalse(Schema::hasTable($pdo, 'users'));
    }

    public function testRejectsUnsafeIdentifiers(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $this->expectException(\InvalidArgumentException::class);
        Schema::create($pdo, 'bad-name', static function (Blueprint $table): void {
            $table->id();
        });
    }
}