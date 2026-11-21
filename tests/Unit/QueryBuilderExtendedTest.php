<?php

declare(strict_types=1);

namespace Nora\Tests\Unit;

use Nora\Database\PdoConnection;
use Nora\Database\QueryBuilder;
use PHPUnit\Framework\TestCase;

final class QueryBuilderExtendedTest extends TestCase
{
    public function testBuildsSelectWithJoinsOrderingGroupingAndOrConditions(): void
    {
        [$sql, $params] = QueryBuilder::select('users')
            ->join('profiles', 'users.id', '=', 'profiles.user_id')
            ->leftJoin('teams', 'users.team_id', '=', 'teams.id')
            ->where('users.active', '=', 1)
            ->orWhere('users.role', '=', 'admin')
            ->groupBy('users.id')
            ->having('users.id', '>=', 1)
            ->orderBy('users.name', 'desc')
            ->limit(10)
            ->offset(5)
            ->toSql();

        self::assertSame(
            'SELECT * FROM `users`'
            . ' INNER JOIN `profiles` ON `users`.`id` = `profiles`.`user_id`'
            . ' LEFT JOIN `teams` ON `users`.`team_id` = `teams`.`id`'
            . ' WHERE `users`.`active` = ? OR `users`.`role` = ?'
            . ' GROUP BY `users`.`id`'
            . ' HAVING `users`.`id` >= ?'
            . ' ORDER BY `users`.`name` DESC'
            . ' LIMIT 10 OFFSET 5',
            $sql,
        );
        self::assertSame([1, 'admin', 1], $params);
    }

    public function testExecutesGetFirstCountAndPaginateAgainstSqlite(): void
    {
        $connection = PdoConnection::connect('sqlite::memory:');
        $connection->execute('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, age INTEGER NOT NULL)');
        $connection->execute('INSERT INTO users (name, age) VALUES (?, ?)', ['Alice', 30]);
        $connection->execute('INSERT INTO users (name, age) VALUES (?, ?)', ['Bob', 25]);
        $connection->execute('INSERT INTO users (name, age) VALUES (?, ?)', ['Carol', 40]);

        $adults = QueryBuilder::select('users')
            ->where('age', '>=', 30)
            ->orderBy('age', 'asc')
            ->get($connection);
        self::assertCount(2, $adults);
        self::assertSame('Alice', $adults[0]['name']);
        self::assertSame('Carol', $adults[1]['name']);

        $first = QueryBuilder::select('users')->where('name', '=', 'Bob')->first($connection);
        self::assertNotNull($first);
        self::assertSame(25, (int) $first['age']);

        self::assertSame(
            2,
            QueryBuilder::select('users')->where('age', '>=', 30)->count($connection),
        );

        $page = QueryBuilder::select('users')->orderBy('id', 'asc')->paginate($connection, 2, 1);
        self::assertSame(3, $page['total']);
        self::assertSame(2, $page['per_page']);
        self::assertSame(1, $page['current_page']);
        self::assertSame(2, $page['last_page']);
        self::assertCount(2, $page['data']);
        self::assertSame('Alice', $page['data'][0]['name']);
    }

    public function testSupportsLeftJoinExecutionWithSqlite(): void
    {
        $connection = PdoConnection::connect('sqlite::memory:');
        $connection->execute('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');
        $connection->execute('CREATE TABLE profiles (user_id INTEGER NOT NULL, bio TEXT NOT NULL)');
        $connection->execute('INSERT INTO users (id, name) VALUES (1, "Alice"), (2, "Bob")');
        $connection->execute('INSERT INTO profiles (user_id, bio) VALUES (1, "Engineer")');

        $rows = QueryBuilder::select('users')
            ->leftJoin('profiles', 'users.id', '=', 'profiles.user_id')
            ->orderBy('users.id', 'asc')
            ->get($connection);

        self::assertCount(2, $rows);
        self::assertSame('Engineer', $rows[0]['bio']);
        self::assertNull($rows[1]['bio']);
    }
}