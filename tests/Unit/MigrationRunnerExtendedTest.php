<?php

declare(strict_types=1);

namespace Nora\Tests\Unit;

use Nora\Database\MigrationInterface;
use Nora\Database\MigrationRunner;
use PDO;
use PHPUnit\Framework\TestCase;

final class MigrationRunnerExtendedTest extends TestCase
{
    public function testRollsBackLastBatchAndByStepsAgainstSqlite(): void
    {
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nora-migrate-' . bin2hex(random_bytes(5));
        self::assertTrue(mkdir($directory));

        $file1 = $directory . DIRECTORY_SEPARATOR . '202610270001_create_alpha.php';
        $file2 = $directory . DIRECTORY_SEPARATOR . '202610270002_create_beta.php';
        $file3 = $directory . DIRECTORY_SEPARATOR . '202610270003_create_gamma.php';
        file_put_contents($file1, $this->migrationClass('alpha'));
        file_put_contents($file2, $this->migrationClass('beta'));
        file_put_contents($file3, $this->migrationClass('gamma'));

        $dbPath = $directory . DIRECTORY_SEPARATOR . 'test.sqlite';
        $pdo = new PDO('sqlite:' . $dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        try {
            $runner = new MigrationRunner($pdo, $directory);
            self::assertSame(
                ['202610270001_create_alpha', '202610270002_create_beta', '202610270003_create_gamma'],
                $runner->migrate(),
            );

            $rolled = $runner->rollback(1);
            self::assertSame(['202610270003_create_gamma'], $rolled);

            $status = $runner->status();
            self::assertTrue($this->statusFor($status, '202610270001_create_alpha')['applied']);
            self::assertTrue($this->statusFor($status, '202610270002_create_beta')['applied']);
            self::assertFalse($this->statusFor($status, '202610270003_create_gamma')['applied']);

            self::assertSame(['202610270003_create_gamma'], $runner->migrate());

            $rolledBatch = $runner->rollback();
            self::assertSame(['202610270003_create_gamma'], $rolledBatch);
            $status = $runner->status();
            self::assertTrue($this->statusFor($status, '202610270001_create_alpha')['applied']);
            self::assertTrue($this->statusFor($status, '202610270002_create_beta')['applied']);
            self::assertFalse($this->statusFor($status, '202610270003_create_gamma')['applied']);

            $rolledBatch2 = $runner->rollback();
            self::assertSame(['202610270002_create_beta', '202610270001_create_alpha'], $rolledBatch2);
            foreach ($runner->status() as $item) {
                self::assertFalse($item['applied'], 'Expected all migrations rolled back.');
            }
            self::assertSame([], $runner->rollback());
        } finally {
            unset($pdo, $runner);
            foreach (glob($directory . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($directory);
        }
    }

    private function migrationClass(string $table): string
    {
        return '<?php return new class implements ' . MigrationInterface::class . ' {'
            . ' public function up(\\PDO $pdo): void { $pdo->exec("CREATE TABLE ' . $table . ' (id INTEGER PRIMARY KEY)"); }'
            . ' public function down(\\PDO $pdo): void { $pdo->exec("DROP TABLE ' . $table . '"); }'
            . '};';
    }

    /**
     * @param list<array{migration: string, applied: bool, batch: int|null}> $status
     * @return array{migration: string, applied: bool, batch: int|null}
     */
    private function statusFor(array $status, string $migration): array
    {
        foreach ($status as $item) {
            if ($item['migration'] === $migration) {
                return $item;
            }
        }
        self::fail('Migration not found in status: ' . $migration);
    }
}