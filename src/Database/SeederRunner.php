<?php

declare(strict_types=1);

namespace Nora\Database;

use PDO;
use RuntimeException;
use Throwable;

final class SeederRunner
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $directory,
    ) {
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    /**
     * @return list<string>
     */
    public function run(?string $seeder = null): array
    {
        $files = $this->seederFiles();
        if ($seeder !== null) {
            $seeder = basename($seeder, '.php');
            if (!isset($files[$seeder])) {
                throw new RuntimeException('Seeder not found: ' . $seeder . '.');
            }
            $files = [$seeder => $files[$seeder]];
        }

        $ran = [];
        foreach ($files as $name => $path) {
            $instance = $this->loadSeeder($path);
            $instance->run($this->pdo);
            $ran[] = $name;
        }

        return $ran;
    }

    /**
     * @return array<string, string>
     */
    private function seederFiles(): array
    {
        if (!is_dir($this->directory)) {
            return [];
        }
        if (!is_readable($this->directory)) {
            throw new RuntimeException('Seeder directory is unreadable.');
        }

        $files = glob(rtrim($this->directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '*.php');
        if ($files === false) {
            throw new RuntimeException('Unable to list seeder files.');
        }
        sort($files, SORT_STRING);

        $seeders = [];
        foreach ($files as $path) {
            $name = basename($path, '.php');
            if (preg_match('/^[A-Za-z0-9_]+$/D', $name) !== 1) {
                throw new RuntimeException('Invalid seeder file name: ' . basename($path));
            }
            $seeders[$name] = $path;
        }

        return $seeders;
    }

    private function loadSeeder(string $path): SeederInterface
    {
        $seeder = require $path;
        if (!$seeder instanceof SeederInterface) {
            throw new RuntimeException(
                'Seeder file must return an instance of ' . SeederInterface::class . '.',
            );
        }

        return $seeder;
    }
}