<?php

declare(strict_types=1);

namespace Nora\Database;

use Nora\Config\Config;
use Nora\Core\Application;
use Nora\Core\ServiceProvider;
use PDO;

final class DatabaseServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $container = $this->app->container();

        $container->singleton(DatabaseManager::class, function () use ($container): DatabaseManager {
            $config = $this->databaseConfigArray();
            $manager = DatabaseManager::fromConfig($config);
            // Integrate ORM resolution with this application's database manager (lazy-safe: no connection opened).
            Model::setDatabaseManager($manager);
            Model::setConnectionResolver(
                static fn (string $modelClass, ?string $connectionName): PdoConnection => $manager->connection($connectionName)
            );
            return $manager;
        });

        $container->singleton(PdoConnection::class, function () use ($container): PdoConnection {
            /** @var DatabaseManager $manager */
            $manager = $container->get(DatabaseManager::class);
            return $manager->connection();
        });

        $container->singleton(PDO::class, function () use ($container): PDO {
            /** @var PdoConnection $connection */
            $connection = $container->get(PdoConnection::class);
            return $connection->pdo();
        });
    }

    public function boot(): void
    {
        // Ensure ORM can resolve connections when the provider is booted, even if manager not yet resolved.
        // Do not fail application boot when database configuration is absent and not needed.
        if ($this->app->container()->has(DatabaseManager::class)) {
            try {
                /** @var DatabaseManager $manager */
                $manager = $this->app->container()->get(DatabaseManager::class);
                if (!Model::databaseManager() instanceof DatabaseManager) {
                    Model::setDatabaseManager($manager);
                }
                // Keep ORM resolver aligned with the current application's manager when this app is booted.
                Model::setConnectionResolver(
                    static fn (string $modelClass, ?string $connectionName): PdoConnection => $manager->connection($connectionName)
                );
            } catch (DatabaseException|\RuntimeException) {
                // Database is optional for applications that do not configure or use it.
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function databaseConfigArray(): array
    {
        $appConfig = $this->app->config();
        $database = $appConfig->get('database');
        if (is_array($database)) {
            return DatabaseConfiguration::normalize($database);
        }

        // Compatibility: support root-level database config shape.
        $connections = $appConfig->get('connections');
        $default = $appConfig->get('default');
        if (is_array($connections)) {
            return DatabaseConfiguration::normalize([
                'default' => is_string($default) ? $default : '',
                'connections' => $connections,
            ]);
        }

        // Fallback to environment/NORA_DSN-based resolution for applications without config/database.php.
        DatabaseConfiguration::loadEnvironment($this->app->basePath());

        return DatabaseConfiguration::fromEnvironment();
    }
}