<?php

declare(strict_types=1);

namespace Nora\Core;

use Closure;
use Nora\Config\Config;
use Nora\Config\ConfigException;
use Nora\Config\EnvironmentLoader;
use Nora\Logging\LoggerInterface;
use Nora\Logging\StructuredLogger;
use RuntimeException;

final class ApplicationBootstrap
{
    private readonly ?LoggerInterface $logger;

    private readonly ?Closure $errorResponseFactory;

    public function __construct(?LoggerInterface $logger = null, ?callable $errorResponseFactory = null)
    {
        $this->logger = $logger;
        $this->errorResponseFactory = $errorResponseFactory === null
            ? null
            : Closure::fromCallable($errorResponseFactory);
    }

    public function boot(string $rootDirectory): Application
    {
        $root = realpath($rootDirectory);
        if ($root === false || !is_dir($root)) {
            throw new RuntimeException('Application root directory does not exist.');
        }

        $environmentFile = $root . DIRECTORY_SEPARATOR . '.env';
        if (is_file($environmentFile)) {
            (new EnvironmentLoader())->load($environmentFile);
        }

        $applicationConfig = $this->loadConfigFile(
            $root . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'app.php',
        );
        $applicationSettings = $applicationConfig['app'] ?? [];
        if (!is_array($applicationSettings)) {
            throw new ConfigException('The "app" configuration section must be an array.');
        }
        $configuredEnvironment = $applicationSettings['environment'] ?? 'production';
        $processEnvironment = getenv('APP_ENV');
        $environment = $processEnvironment === false || $processEnvironment === ''
            ? $configuredEnvironment
            : $processEnvironment;
        if (!is_string($environment)) {
            throw new ConfigException('The configured application environment must be a string.');
        }
        if (preg_match('/^[A-Za-z0-9_-]+$/D', $environment) !== 1) {
            throw new ConfigException('APP_ENV contains invalid characters.');
        }

        $debugValue = getenv('APP_DEBUG');
        $debugOverride = null;
        if ($debugValue !== false) {
            $debugOverride = filter_var($debugValue, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if (!is_bool($debugOverride)) {
                throw new ConfigException('APP_DEBUG must be a boolean value.');
            }
        }

        $databaseConfig = $this->loadConfigFile(
            $root . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'database.php',
        );
        if ($databaseConfig !== [] && !isset($databaseConfig['database'])) {
            $databaseConfig = ['database' => $databaseConfig];
        }

        $sources = [
            ['app' => ['environment' => 'production', 'debug' => false], 'logging' => ['level' => 'info']],
            $applicationConfig,
            $databaseConfig,
            $this->loadConfigFile(
                $root . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . $environment . '.php',
            ),
            ['app' => ['environment' => $environment]],
        ];
        if ($debugOverride !== null) {
            $sources[] = ['app' => ['debug' => $debugOverride]];
        }
        $logLevel = getenv('NORA_LOG_LEVEL');
        if ($logLevel !== false) {
            $sources[] = ['logging' => ['level' => $logLevel]];
        }
        $config = Config::fromSources(...$sources);
        $config->validate([
            'app.environment' => 'non-empty-string',
            'app.debug' => 'boolean',
            'logging.level' => 'non-empty-string',
        ]);

        $logger = $this->logger ?? StructuredLogger::toErrorLog($config->get('logging.level'));
        $app = new Application(
            exceptionHandler: new ExceptionHandler(
                $logger,
                $config->get('app.debug'),
                $this->errorResponseFactory,
            ),
            config: $config,
            basePath: $root,
        );

        $this->registerConfiguredProviders($app, $config);
        $app->boot();

        $routes = $this->routeFile($root);
        if ($routes !== null) {
            $register = require $routes;
            if (!is_callable($register)) {
                throw new RuntimeException('Application routes file must return a callable.');
            }
            $register($app);
        }

        return $app;
    }

    /**
     * @param Config $config
     */
    private function registerConfiguredProviders(Application $app, Config $config): void
    {
        $rawProviders = $config->get('providers');
        if ($rawProviders === null) {
            $rawProviders = $config->get('app.providers');
        }

        if ($rawProviders === null) {
            // Default core providers for unified runtime integration when not explicitly configured.
            $providers = [
                \Nora\Database\DatabaseServiceProvider::class,
                \Nora\Events\EventServiceProvider::class,
            ];
        } elseif (!is_array($rawProviders)) {
            throw new ConfigException('The configured providers list must be an array.');
        } else {
            $providers = $rawProviders;
        }

        foreach ($providers as $provider) {
            if (!is_string($provider) || trim($provider) === '') {
                throw new ConfigException('Each configured provider must be a non-empty class name string.');
            }
            $app->register($provider);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function loadConfigFile(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }
        if (!is_readable($path)) {
            throw new RuntimeException('Configuration file is unreadable: ' . $path);
        }

        $config = require $path;
        if (!is_array($config)) {
            throw new ConfigException('Configuration file must return an array: ' . $path);
        }

        return $config;
    }

    private function routeFile(string $root): ?string
    {
        foreach ([
            $root . DIRECTORY_SEPARATOR . 'routes.php',
            $root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'routes.php',
        ] as $path) {
            if (is_file($path)) {
                if (!is_readable($path)) {
                    throw new RuntimeException('Application routes file is unreadable: ' . $path);
                }

                return $path;
            }
        }

        return null;
    }
}
