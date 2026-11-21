<?php

declare(strict_types=1);

namespace Nora\Tests\Unit;

use Nora\Database\SeederInterface;
use Nora\Database\SeederRunner;
use PDO;
use PHPUnit\Framework\TestCase;

final class SeederRunnerTest extends TestCase
{
    public function testRunsSeedersInSortedOrderAndSupportsSingleSeeder(): void
    {
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nora-seeders-' . bin2hex(random_bytes(5));
        self::assertTrue(mkdir($directory, 0755, true));

        file_put_contents($directory . DIRECTORY_SEPARATOR . 'BSeeder.php', $this->seeder('b'));
        file_put_contents($directory . DIRECTORY_SEPARATOR . 'ASeeder.php', $this->seeder('a'));

        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE seeded (name TEXT NOT NULL)');

        try {
            $runner = new SeederRunner($pdo, $directory);
            self::assertSame(['ASeeder', 'BSeeder'], $runner->run());
            $rows = $pdo->query('SELECT name FROM seeded ORDER BY rowid')->fetchAll(PDO::FETCH_COLUMN);
            self::assertSame(['a', 'b'], $rows);

            self::assertSame(['BSeeder'], $runner->run('BSeeder'));
        } finally {
            foreach (glob($directory . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($directory);
        }
    }

    public function testReturnsEmptyWhenSeederDirectoryIsMissing(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $runner = new SeederRunner($pdo, sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nora-no-seeders-' . bin2hex(random_bytes(5)));
        self::assertSame([], $runner->run());
    }

    private function seeder(string $name): string
    {
        $escaped = var_export($name, true);

        return '<?php return new class implements ' . SeederInterface::class . ' {'
            . ' public function run(PDO $pdo): void {'
            . ' $statement = $pdo->prepare(\'INSERT INTO seeded (name) VALUES (?)\');'
            . ' $statement->execute([' . $escaped . ']);'
            . ' }'
            . '};';
    }
}