<?php

declare(strict_types=1);

$env = static function (string $key, mixed $default = null): mixed {
    $value = getenv($key);
    if ($value === false || $value === '') {
        return $default;
    }

    return $value;
};

$default = (string) $env('DB_CONNECTION', 'sqlite');

return [
    'default' => $default,
    'connections' => [
        'sqlite' => [
            'driver' => 'sqlite',
            'database' => (string) $env('DB_DATABASE', 'database/database.sqlite'),
            'foreign_key_constraints' => filter_var($env('DB_FOREIGN_KEYS', 'true'), FILTER_VALIDATE_BOOLEAN),
        ],
        'mysql' => [
            'driver' => 'mysql',
            'host' => (string) $env('DB_HOST', '127.0.0.1'),
            'port' => (int) $env('DB_PORT', 3306),
            'database' => (string) $env('DB_DATABASE', 'nora'),
            'username' => (string) $env('DB_USERNAME', 'root'),
            'password' => (string) $env('DB_PASSWORD', ''),
            'charset' => (string) $env('DB_CHARSET', 'utf8mb4'),
            'options' => [],
        ],
        'mariadb' => [
            'driver' => 'mariadb',
            'host' => (string) $env('DB_HOST', '127.0.0.1'),
            'port' => (int) $env('DB_PORT', 3306),
            'database' => (string) $env('DB_DATABASE', 'nora'),
            'username' => (string) $env('DB_USERNAME', 'root'),
            'password' => (string) $env('DB_PASSWORD', ''),
            'charset' => (string) $env('DB_CHARSET', 'utf8mb4'),
            'options' => [],
        ],
        'pgsql' => [
            'driver' => 'pgsql',
            'host' => (string) $env('DB_HOST', '127.0.0.1'),
            'port' => (int) $env('DB_PORT', 5432),
            'database' => (string) $env('DB_DATABASE', 'nora'),
            'username' => (string) $env('DB_USERNAME', 'postgres'),
            'password' => (string) $env('DB_PASSWORD', ''),
            'sslmode' => (string) $env('DB_SSLMODE', 'prefer'),
            'options' => [],
        ],
    ],
];