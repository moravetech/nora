<?php

declare(strict_types=1);

namespace Nora\Database;

use Nora\Config\Config;
use Nora\Config\EnvironmentLoader;
use RuntimeException;

final class DatabaseConfiguration
{
    /**
     * @return array<string, mixed>
     */
    public static function fromConfig(Config $config): array
    {
        $database = $config->get('database');
        if (is_array($database)) {
            return self::normalize($database);
        }

        $connections = $config->get('connections');
        $default = $config->get('default');
        if (is_array($connections)) {
            return self::normalize([
                'default' => is_string($default) ? $default : '',
                'connections' => $connections,
            ]);
        }

        throw new DatabaseException('Database configuration is missing or invalid.');
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public static function normalize(array $config): array
    {
        $normalized = $config;
        if (isset($config['connections']) && is_array($config['connections'])) {
            $normalized['connections'] = $config['connections'];
            $normalized['default'] = isset($config['default']) && is_string($config['default'])
                ? $config['default']
                : '';
        } elseif (isset($config['database']) && is_array($config['database'])) {
            $normalized = $config['database'];
        }

        $default = $normalized['default'] ?? '';
        $connections = $normalized['connections'] ?? null;
        if (!is_string($default) || trim($default) === '') {
            throw new DatabaseException('The default database connection must be configured.');
        }
        if (!is_array($connections) || $connections === []) {
            throw new DatabaseException('At least one database connection must be configured.');
        }
        if (!array_key_exists($default, $connections) || !is_array($connections[$default])) {
            throw new DatabaseException('The default database connection "' . $default . '" is not defined.');
        }

        foreach ($connections as $name => $definition) {
            if (!is_string($name) || $name === '' || !is_array($definition)) {
                throw new DatabaseException('Database connection definitions must be named arrays.');
            }
        }

        return [
            'default' => $default,
            'connections' => $connections,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function load(string $rootDirectory): array
    {
        $root = realpath($rootDirectory);
        if ($root === false || !is_dir($root)) {
            throw new RuntimeException('Application root directory does not exist.');
        }

        self::loadEnvironment($root);

        $file = $root . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'database.php';
        if (is_file($file)) {
            $config = require $file;
            if (!is_array($config)) {
                throw new DatabaseException('Database configuration file must return an array: ' . $file);
            }

            return self::normalize($config);
        }

        return self::fromEnvironment();
    }

    public static function manager(string $rootDirectory): DatabaseManager
    {
        return DatabaseManager::fromConfig(self::load($rootDirectory));
    }

    public static function loadEnvironment(string $rootDirectory): void
    {
        $envFile = $rootDirectory . DIRECTORY_SEPARATOR . '.env';
        if (is_file($envFile)) {
            (new EnvironmentLoader())->load($envFile);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public static function fromEnvironment(): array
    {
        $dsn = getenv('NORA_DSN');
        $connectionName = getenv('DB_CONNECTION');
        $database = getenv('DB_DATABASE');

        if (is_string($dsn) && $dsn !== '') {
            $driver = self::driverFromDsn($dsn);
            $definition = ['driver' => $driver, 'dsn' => $dsn];
            $username = getenv('NORA_DB_USERNAME');
            $password = getenv('NORA_DB_PASSWORD');
            if ($username !== false && $username !== '') {
                $definition['username'] = $username;
            }
            if ($password !== false && $password !== '') {
                $definition['password'] = $password;
            }

            $name = is_string($connectionName) && $connectionName !== '' ? $connectionName : $driver;

            return self::normalize([
                'default' => $name,
                'connections' => [$name => $definition],
            ]);
        }

        $name = is_string($connectionName) && $connectionName !== '' ? $connectionName : '';
        $hasDbEnv = is_string($database) && $database !== '';
        if ($name !== '' || $hasDbEnv) {
            $name = $name !== '' ? $name : 'sqlite';
            $driver = $name === 'postgres' || $name === 'postgresql' ? 'pgsql' : $name;
            $definition = ['driver' => $driver];
            if ($driver === 'sqlite') {
                $definition['database'] = $hasDbEnv ? $database : ':memory:';
            } else {
                $host = getenv('DB_HOST');
                $port = getenv('DB_PORT');
                $username = getenv('DB_USERNAME');
                $password = getenv('DB_PASSWORD');
                $charset = getenv('DB_CHARSET');
                $sslmode = getenv('DB_SSLMODE');
                $definition['host'] = $host !== false && $host !== '' ? $host : '127.0.0.1';
                if ($port !== false && $port !== '') {
                    $definition['port'] = ctype_digit($port) ? (int) $port : $port;
                }
                $definition['database'] = $hasDbEnv ? $database : '';
                if ($username !== false && $username !== '') {
                    $definition['username'] = $username;
                }
                if ($password !== false && $password !== '') {
                    $definition['password'] = $password;
                }
                if ($charset !== false && $charset !== '') {
                    $definition['charset'] = $charset;
                }
                if ($sslmode !== false && $sslmode !== '') {
                    $definition['sslmode'] = $sslmode;
                }
            }

            return self::normalize([
                'default' => $name,
                'connections' => [$name => $definition],
            ]);
        }

        throw new DatabaseException(
            'Set NORA_DSN in the environment or provide config/database.php before running database commands.',
        );
    }

    private static function driverFromDsn(string $dsn): string
    {
        $separator = strpos($dsn, ':');
        if ($separator === false || $separator === 0) {
            throw new DatabaseException('The configured database DSN is invalid.');
        }

        $driver = strtolower(substr($dsn, 0, $separator));

        return match ($driver) {
            'sqlite', 'mysql', 'mariadb', 'pgsql', 'postgres', 'postgresql' => $driver,
            default => throw new DatabaseException('Unsupported database driver in DSN "' . $driver . '".'),
        };
    }
}