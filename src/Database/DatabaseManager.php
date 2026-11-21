<?php

declare(strict_types=1);

namespace Nora\Database;

use InvalidArgumentException;
use Nora\Logging\LoggerInterface;
use PDO;
use RuntimeException;
use Throwable;

final class DatabaseManager
{
    /**
     * @param array{
     *     default?: string,
     *     connections?: array<string, array<string, mixed>>
     * } $config
     * @var array<string, PdoConnection>
     */
    private array $connections = [];

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        private readonly array $config = [],
        private readonly ?LoggerInterface $logger = null,
        private readonly ?QueryDiagnostics $diagnostics = null,
    ) {
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function fromConfig(
        array $config,
        ?LoggerInterface $logger = null,
        ?QueryDiagnostics $diagnostics = null,
    ): self {
        return new self($config, $logger, $diagnostics);
    }

    public function connection(?string $name = null): PdoConnection
    {
        $name ??= $this->defaultConnection();
        if ($name === '') {
            throw new DatabaseException('The default database connection name is not configured.');
        }
        if (isset($this->connections[$name])) {
            return $this->connections[$name];
        }

        $definition = $this->connectionConfig($name);
        $dsn = $this->resolveDsn($name, $definition);
        [$username, $password] = $this->resolveCredentials($definition);
        $options = $this->resolveOptions($definition);

        try {
            $this->connections[$name] = PdoConnection::connect(
                $dsn,
                $username,
                $password,
                $options,
                $this->logger,
                $this->diagnostics,
            );
        } catch (Throwable $exception) {
            throw new DatabaseException(
                'Unable to connect to database connection "' . $name . '".',
                0,
                $exception,
            );
        }

        return $this->connections[$name];
    }

    public function reconnect(?string $name = null): PdoConnection
    {
        $name ??= $this->defaultConnection();
        unset($this->connections[$name]);

        return $this->connection($name);
    }

    public function disconnect(?string $name = null): void
    {
        $name ??= $this->defaultConnection();
        unset($this->connections[$name]);
    }

    public function disconnectAll(): void
    {
        $this->connections = [];
    }

    public function hasResolvedConnection(string $name): bool
    {
        return isset($this->connections[$name]);
    }

    public function defaultConnection(): string
    {
        $default = $this->config['default'] ?? null;
        if (!is_string($default)) {
            return '';
        }

        return $default;
    }

    /**
     * @param array<string, mixed> $definition
     */
    public function dsnFor(array $definition): string
    {
        if (isset($definition['dsn'])) {
            if (!is_string($definition['dsn']) || trim($definition['dsn']) === '') {
                throw new InvalidArgumentException('The database DSN must be a non-empty string.');
            }

            return $definition['dsn'];
        }
        if (isset($definition['url'])) {
            if (!is_string($definition['url']) || trim($definition['url']) === '') {
                throw new InvalidArgumentException('The database URL must be a non-empty string.');
            }

            return $this->dsnFromUrl($definition['url']);
        }

        $driver = $definition['driver'] ?? null;
        if (!is_string($driver) || $driver === '') {
            throw new InvalidArgumentException('The database driver is required.');
        }
        $driver = strtolower($driver);

        return match ($driver) {
            'sqlite' => $this->sqliteDsn($definition),
            'mysql', 'mariadb' => $this->mysqlDsn($definition),
            'pgsql', 'postgres', 'postgresql' => $this->pgsqlDsn($definition),
            default => throw new InvalidArgumentException('Unsupported database driver "' . $driver . '".'),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function connectionConfig(string $name): array
    {
        $connections = $this->config['connections'] ?? null;
        if (!is_array($connections) || !array_key_exists($name, $connections)) {
            throw new DatabaseException('Database connection "' . $name . '" is not configured.');
        }
        $definition = $connections[$name];
        if (!is_array($definition)) {
            throw new DatabaseException('Database connection "' . $name . '" must be an array configuration.');
        }

        return $definition;
    }

    /**
     * @param array<string, mixed> $definition
     */
    private function resolveDsn(string $name, array $definition): string
    {
        try {
            return $this->dsnFor($definition);
        } catch (InvalidArgumentException $exception) {
            throw new DatabaseException(
                'Database connection "' . $name . '" is invalid: ' . $exception->getMessage(),
                0,
                $exception,
            );
        }
    }

    /**
     * @param array<string, mixed> $definition
     * @return array{0: ?string, 1: ?string}
     */
    private function resolveCredentials(array $definition): array
    {
        $driver = strtolower((string) ($definition['driver'] ?? ''));
        if ($driver === 'sqlite') {
            return [null, null];
        }

        $username = $definition['username'] ?? null;
        $password = $definition['password'] ?? null;

        if ($username === null && isset($definition['username_env'])) {
            $username = $this->envValue($definition['username_env'], 'username_env');
        }
        if ($password === null && isset($definition['password_env'])) {
            $password = $this->envValue($definition['password_env'], 'password_env');
        }

        if ($username !== null && !is_string($username)) {
            throw new InvalidArgumentException('The database username must be a string.');
        }
        if ($password !== null && !is_string($password)) {
            throw new InvalidArgumentException('The database password must be a string.');
        }

        return [$username, $password];
    }

    private function envValue(mixed $name, string $key): ?string
    {
        if (!is_string($name) || $name === '') {
            throw new InvalidArgumentException('The database ' . $key . ' must be a non-empty string.');
        }
        $value = getenv($name);

        return $value === false ? null : $value;
    }

    /**
     * @param array<string, mixed> $definition
     * @return array<int, mixed>
     */
    private function resolveOptions(array $definition): array
    {
        $options = $definition['options'] ?? [];
        if (!is_array($options)) {
            throw new DatabaseException('Database connection options must be an array.');
        }

        $normalized = [];
        foreach ($options as $key => $value) {
            if (is_string($key) && ctype_digit($key)) {
                $key = (int) $key;
            }
            $normalized[$key] = $value;
        }

        if (!array_key_exists(PDO::ATTR_ERRMODE, $normalized)) {
            $normalized[PDO::ATTR_ERRMODE] = PDO::ERRMODE_EXCEPTION;
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $definition
     */
    private function sqliteDsn(array $definition): string
    {
        $database = $definition['database'] ?? ':memory:';
        if (!is_string($database) || $database === '') {
            throw new InvalidArgumentException('The SQLite database path is required.');
        }
        if ($database === ':memory:') {
            return 'sqlite::memory:';
        }
        if (str_starts_with($database, 'file:')) {
            return 'sqlite:' . $database;
        }
        $this->assertDsnValue($database, 'database');

        return 'sqlite:' . $database;
    }

    /**
     * @param array<string, mixed> $definition
     */
    private function mysqlDsn(array $definition): string
    {
        $database = $definition['database'] ?? null;
        $host = $definition['host'] ?? '127.0.0.1';
        $port = $definition['port'] ?? 3306;
        $charset = $definition['charset'] ?? 'utf8mb4';
        $socket = $definition['unix_socket'] ?? null;

        if (!is_string($database) || $database === '') {
            throw new InvalidArgumentException('The MySQL database name is required.');
        }
        $this->assertDsnValue($database, 'database');

        $parts = [];
        if (is_string($socket) && $socket !== '') {
            $this->assertDsnValue($socket, 'unix_socket');
            $parts[] = 'unix_socket=' . $socket;
        } else {
            if (!is_string($host) || $host === '') {
                throw new InvalidArgumentException('The MySQL host must be a non-empty string.');
            }
            $this->assertDsnValue($host, 'host');
            $parts[] = 'host=' . $host;
            $parts[] = 'port=' . $this->normalizePort($port);
        }
        $parts[] = 'dbname=' . $database;
        if (is_string($charset) && $charset !== '') {
            $this->assertDsnValue($charset, 'charset');
            $parts[] = 'charset=' . $charset;
        }

        return 'mysql:' . implode(';', $parts);
    }

    /**
     * @param array<string, mixed> $definition
     */
    private function pgsqlDsn(array $definition): string
    {
        $database = $definition['database'] ?? null;
        $host = $definition['host'] ?? '127.0.0.1';
        $port = $definition['port'] ?? 5432;

        if (!is_string($database) || $database === '') {
            throw new InvalidArgumentException('The PostgreSQL database name is required.');
        }
        if (!is_string($host) || $host === '') {
            throw new InvalidArgumentException('The PostgreSQL host must be a non-empty string.');
        }
        $this->assertDsnValue($host, 'host');
        $this->assertDsnValue($database, 'database');

        $parts = [
            'host=' . $host,
            'port=' . $this->normalizePort($port),
            'dbname=' . $database,
        ];
        $sslmode = $definition['sslmode'] ?? null;
        if (is_string($sslmode) && $sslmode !== '') {
            $this->assertDsnValue($sslmode, 'sslmode');
            $parts[] = 'sslmode=' . $sslmode;
        }

        return 'pgsql:' . implode(';', $parts);
    }

    private function dsnFromUrl(string $url): string
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'])) {
            throw new InvalidArgumentException('The database URL is invalid.');
        }
        $scheme = strtolower((string) $parts['scheme']);
        $driver = match ($scheme) {
            'sqlite' => 'sqlite',
            'mysql', 'mariadb' => 'mysql',
            'pgsql', 'postgres', 'postgresql' => 'pgsql',
            default => throw new InvalidArgumentException('Unsupported database URL scheme "' . $scheme . '".'),
        };

        if ($driver === 'sqlite') {
            $path = $parts['path'] ?? '';
            if (!is_string($path) || $path === '') {
                return 'sqlite::memory:';
            }

            return 'sqlite:' . $path;
        }

        $database = isset($parts['path']) ? ltrim((string) $parts['path'], '/') : '';
        $definition = [
            'driver' => $driver,
            'host' => $parts['host'] ?? '127.0.0.1',
            'port' => $parts['port'] ?? ($driver === 'pgsql' ? 5432 : 3306),
            'database' => $database,
        ];
        $query = [];
        if (isset($parts['query'])) {
            parse_str((string) $parts['query'], $query);
        }
        foreach (['charset', 'sslmode', 'unix_socket'] as $key) {
            if (isset($query[$key]) && is_string($query[$key])) {
                $definition[$key] = $query[$key];
            }
        }

        return $this->dsnFor($definition);
    }

    private function normalizePort(mixed $port): int
    {
        if (is_string($port) && ctype_digit($port)) {
            $port = (int) $port;
        }
        if (!is_int($port) || $port < 1 || $port > 65535) {
            throw new InvalidArgumentException('The database port must be between 1 and 65535.');
        }

        return $port;
    }

    private function assertDsnValue(string $value, string $name): void
    {
        if (str_contains($value, ';') || str_contains($value, "\0") || str_contains($value, "\n") || str_contains($value, "\r")) {
            throw new InvalidArgumentException('The database ' . $name . ' contains invalid characters.');
        }
    }
}