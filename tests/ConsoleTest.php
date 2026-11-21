<?php

declare(strict_types=1);

namespace Nora\Tests;

use Nora\Console\ConsoleApplication;
use PHPUnit\Framework\TestCase;

final class ConsoleTest extends TestCase
{
    public function testHelpAndVersionCommandsAreFunctional(): void
    {
        [$app, $output] = $this->application(sys_get_temp_dir());

        self::assertSame(0, $app->run(['--help']));
        self::assertSame(0, $app->run(['--version']));
        rewind($output);
        $result = stream_get_contents($output);
        fclose($output);

        self::assertStringContainsString('make:controller', (string) $result);
        self::assertStringContainsString('make:component', (string) $result);
        self::assertStringContainsString('development build', (string) $result);
    }

    public function testScaffoldsStarterApplicationWithoutOverwriting(): void
    {
        $workspace = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nora-cli-' . bin2hex(random_bytes(5));
        self::assertTrue(mkdir($workspace));
        [$app, $output] = $this->application($workspace);

        try {
            self::assertSame(0, $app->run(['new', 'sample-app']));
            self::assertFileExists($workspace . DIRECTORY_SEPARATOR . 'sample-app' . DIRECTORY_SEPARATOR . 'composer.json');
            self::assertFileExists($workspace . DIRECTORY_SEPARATOR . 'sample-app' . DIRECTORY_SEPARATOR . 'package.json');
            self::assertFileExists(
                $workspace . DIRECTORY_SEPARATOR . 'sample-app' . DIRECTORY_SEPARATOR
                    . 'resources' . DIRECTORY_SEPARATOR . 'css' . DIRECTORY_SEPARATOR . 'app.css',
            );
            self::assertFileExists($workspace . DIRECTORY_SEPARATOR . 'sample-app' . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'index.php');
            self::assertFileExists($workspace . DIRECTORY_SEPARATOR . 'sample-app' . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Reactive' . DIRECTORY_SEPARATOR . 'CounterPage.php');
            self::assertDirectoryExists($workspace . DIRECTORY_SEPARATOR . 'sample-app' . DIRECTORY_SEPARATOR . 'migrations');
            $counterSource = (string) file_get_contents(
                $workspace . DIRECTORY_SEPARATOR . 'sample-app' . DIRECTORY_SEPARATOR
                    . 'src' . DIRECTORY_SEPARATOR . 'Reactive' . DIRECTORY_SEPARATOR . 'CounterPage.php',
            );
            self::assertStringContainsString('extends Component', $counterSource);
            self::assertStringContainsString('UI::each(', $counterSource);
            self::assertStringNotContainsString("UI::element('link'", $counterSource);
            $routesSource = (string) file_get_contents(
                $workspace . DIRECTORY_SEPARATOR . 'sample-app' . DIRECTORY_SEPARATOR
                    . 'src' . DIRECTORY_SEPARATOR . 'routes.php',
            );
            self::assertStringContainsString("\$runtime->mount(\$app, 'counter', '/', 'Nora', '/assets/app.css');", $routesSource);
            $manifest = json_decode(
                (string) file_get_contents($workspace . DIRECTORY_SEPARATOR . 'sample-app' . DIRECTORY_SEPARATOR . 'composer.json'),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
            self::assertSame('dev-main', $manifest['require']['morave/nora']);
            self::assertSame([[
                'type' => 'vcs',
                'url' => 'https://github.com/Yasser-devb/nora.git',
            ]], $manifest['repositories']);
            self::assertSame(['App\\' => 'src/'], $manifest['autoload']['psr-4']);
            self::assertSame(['App\\Tests\\' => 'tests/'], $manifest['autoload-dev']['psr-4']);
            self::assertSame('phpunit', $manifest['scripts']['test']);
            self::assertSame('^11.5', $manifest['require-dev']['phpunit/phpunit']);
            self::assertFileExists(
                $workspace . DIRECTORY_SEPARATOR . 'sample-app' . DIRECTORY_SEPARATOR . 'phpunit.xml',
            );
            self::assertFileExists(
                $workspace . DIRECTORY_SEPARATOR . 'sample-app' . DIRECTORY_SEPARATOR . 'tests'
                    . DIRECTORY_SEPARATOR . 'Feature' . DIRECTORY_SEPARATOR . 'HomeRouteTest.php',
            );
            self::assertFileExists(
                $workspace . DIRECTORY_SEPARATOR . 'sample-app' . DIRECTORY_SEPARATOR . 'src'
                    . DIRECTORY_SEPARATOR . 'Controllers' . DIRECTORY_SEPARATOR . 'HomeController.php',
            );
            self::assertFileExists(
                $workspace . DIRECTORY_SEPARATOR . 'sample-app' . DIRECTORY_SEPARATOR . 'src'
                    . DIRECTORY_SEPARATOR . 'Views' . DIRECTORY_SEPARATOR . 'home.nora',
            );
            self::assertStringContainsString(
                "'/welcome', new HomeController()",
                (string) file_get_contents(
                    $workspace . DIRECTORY_SEPARATOR . 'sample-app' . DIRECTORY_SEPARATOR
                        . 'src' . DIRECTORY_SEPARATOR . 'routes.php',
                ),
            );
            $generatedConfig = $workspace . DIRECTORY_SEPARATOR . 'sample-app' . DIRECTORY_SEPARATOR
                . 'config' . DIRECTORY_SEPARATOR . 'app.php';
            self::assertFileExists($generatedConfig);
            token_get_all((string) file_get_contents($generatedConfig), TOKEN_PARSE);
            $config = require $generatedConfig;
            self::assertSame('production', $config['app']['environment']);
            self::assertFalse($config['app']['debug']);
            $projectReadme = (string) file_get_contents(
                $workspace . DIRECTORY_SEPARATOR . 'sample-app' . DIRECTORY_SEPARATOR . 'README.md',
            );
            self::assertStringContainsString('Composer maps `App\\` to that directory', $projectReadme);
            self::assertStringContainsString('npm run nora:assets:build', $projectReadme);
            self::assertStringContainsString('debug output disabled', $projectReadme);
            self::assertStringContainsString('composer test', $projectReadme);
            self::assertNotSame('path', $manifest['repositories'][0]['type']);
            $npmManifest = json_decode(
                (string) file_get_contents($workspace . DIRECTORY_SEPARATOR . 'sample-app' . DIRECTORY_SEPARATOR . 'package.json'),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
            self::assertSame('^4.0.0', $npmManifest['devDependencies']['tailwindcss']);
            self::assertArrayHasKey('nora:assets:build', $npmManifest['scripts']);
            foreach ([
                'public/index.php',
                'public/router.php',
                'src/routes.php',
                'src/Controllers/HomeController.php',
                'src/Reactive/CounterPage.php',
            ] as $relativePath) {
                token_get_all(
                    (string) file_get_contents($workspace . DIRECTORY_SEPARATOR . 'sample-app'
                        . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath)),
                    TOKEN_PARSE,
                );
            }
            $generatedStylesheet = (string) file_get_contents(
                $workspace . DIRECTORY_SEPARATOR . 'sample-app' . DIRECTORY_SEPARATOR
                    . 'resources' . DIRECTORY_SEPARATOR . 'css' . DIRECTORY_SEPARATOR . 'app.css',
            );
            self::assertStringContainsString('@source "../../src";', $generatedStylesheet);
            self::assertStringNotContainsString('@source "../../views";', $generatedStylesheet);
            self::assertStringContainsString('dark:bg-slate-950', (string) file_get_contents(
                $workspace . DIRECTORY_SEPARATOR . 'sample-app' . DIRECTORY_SEPARATOR
                    . 'src' . DIRECTORY_SEPARATOR . 'Views' . DIRECTORY_SEPARATOR . 'home.nora',
            ));

            self::assertSame(1, $app->run(['new', 'sample-app']));
            self::assertSame(1, $app->run(['new', '..']));
            self::assertSame(1, $app->run(['new', 'CON']));
        } finally {
            fclose($output);
            $this->removeTree($workspace);
        }
    }

    public function testTailwindInitializerPreservesExistingNpmConfigurationAndChecksBuildOutput(): void
    {
        $workspace = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nora-cli-' . bin2hex(random_bytes(5));
        self::assertTrue(mkdir($workspace));
        $manifestPath = $workspace . DIRECTORY_SEPARATOR . 'package.json';
        $original = [
            'name' => 'existing-app',
            'scripts' => ['test' => 'node --test'],
            'devDependencies' => ['playwright' => '^1.64.1'],
        ];
        self::assertNotFalse(file_put_contents(
            $manifestPath,
            json_encode($original, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
        ));
        [$app, $output] = $this->application($workspace);

        try {
            self::assertSame(0, $app->run(['tailwind:init']));
            $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('node --test', $manifest['scripts']['test']);
            self::assertSame('^1.64.1', $manifest['devDependencies']['playwright']);
            self::assertSame('^4.0.0', $manifest['devDependencies']['tailwindcss']);
            self::assertStringContainsString(
                '@import "tailwindcss" source(none);',
                (string) file_get_contents(
                    $workspace . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'css'
                        . DIRECTORY_SEPARATOR . 'app.css',
                ),
            );
            self::assertSame(1, $app->run(['assets:check']));
            self::assertTrue(mkdir(
                $workspace . DIRECTORY_SEPARATOR . 'node_modules' . DIRECTORY_SEPARATOR . '@tailwindcss'
                    . DIRECTORY_SEPARATOR . 'cli',
                0755,
                true,
            ));
            self::assertTrue(mkdir(
                $workspace . DIRECTORY_SEPARATOR . 'node_modules' . DIRECTORY_SEPARATOR . 'tailwindcss',
                0755,
                true,
            ));
            $assets = $workspace . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'assets';
            self::assertTrue(mkdir($assets, 0755, true));
            self::assertNotFalse(file_put_contents(
                $assets . DIRECTORY_SEPARATOR . 'app.css',
                '@layer theme, base, utilities;',
            ));
            self::assertSame(0, $app->run(['assets:check']));
            self::assertSame(1, $app->run(['tailwind:init']));
        } finally {
            fclose($output);
            $this->removeTree($workspace);
        }
    }

    public function testTailwindInitializerRejectsScriptConflictsWithoutCreatingFiles(): void
    {
        $workspace = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nora-cli-' . bin2hex(random_bytes(5));
        self::assertTrue(mkdir($workspace));
        $manifestPath = $workspace . DIRECTORY_SEPARATOR . 'package.json';
        self::assertNotFalse(file_put_contents($manifestPath, json_encode([
            'scripts' => ['nora:assets:build' => 'custom-build'],
        ], JSON_THROW_ON_ERROR)));
        [$app, $output] = $this->application($workspace);

        try {
            self::assertSame(1, $app->run(['tailwind:init']));
            self::assertFileDoesNotExist(
                $workspace . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'css'
                    . DIRECTORY_SEPARATOR . 'app.css',
            );
            self::assertSame(
                ['scripts' => ['nora:assets:build' => 'custom-build']],
                json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR),
            );
        } finally {
            fclose($output);
            $this->removeTree($workspace);
        }
    }

    public function testGeneratorsCreateClassesAndRejectExistingFiles(): void
    {
        $workspace = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nora-cli-' . bin2hex(random_bytes(5));
        self::assertTrue(mkdir($workspace));
        [$app, $output] = $this->application($workspace);

        try {
            self::assertSame(0, $app->run(['make:controller', 'User']));
            self::assertSame(0, $app->run(['make:model', 'User']));
            self::assertSame(0, $app->run(['make:middleware', 'Audit']));
            self::assertSame(0, $app->run(['make:service', 'Mailer']));
            self::assertSame(0, $app->run(['make:component', 'Alert']));
            self::assertSame(0, $app->run(['make:view', 'profile']));
            self::assertSame(0, $app->run(['make:test', 'Profile']));
            $generatedComponent = $workspace . DIRECTORY_SEPARATOR . 'src'
                . DIRECTORY_SEPARATOR . 'Reactive' . DIRECTORY_SEPARATOR . 'Alert.php';
            self::assertFileExists($generatedComponent);
            self::assertStringContainsString('extends Component', (string) file_get_contents($generatedComponent));
            token_get_all((string) file_get_contents($generatedComponent), TOKEN_PARSE);
            require $generatedComponent;
            self::assertStringContainsString(
                'Alert',
                (new \App\Reactive\Alert())->render([]),
            );
            self::assertSame(8, file_put_contents($generatedComponent, 'sentinel'));
            self::assertSame(1, $app->run(['make:component', 'Alert']));
            self::assertSame('sentinel', file_get_contents($generatedComponent));
            self::assertSame(1, $app->run(['make:component', 'bad-name']));
            self::assertSame(1, $app->run(['make:view', '../outside']));
            self::assertSame(1, $app->run(['make:middleware', 'Audit']));
            self::assertSame(1, $app->run(['make:service', 'Mailer']));
            self::assertSame(1, $app->run(['make:test', 'Profile']));
            self::assertSame(1, $app->run(['make:test', 'bad-name']));
            self::assertSame(1, $app->run(['make:model', '..']));
            self::assertSame(1, $app->run(['make:model', 'User']));
            self::assertStringContainsString(
                'class UserController',
                (string) file_get_contents($workspace . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Controllers' . DIRECTORY_SEPARATOR . 'UserController.php'),
            );
            self::assertStringContainsString(
                'namespace App\\Models;',
                (string) file_get_contents($workspace . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Models' . DIRECTORY_SEPARATOR . 'User.php'),
            );
            $controllerPath = $workspace . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR
                . 'Controllers' . DIRECTORY_SEPARATOR . 'UserController.php';
            require $controllerPath;
            $controllerResponse = (new \App\Controllers\UserController())(
                \Nora\Http\Request::fromUri('GET', '/'),
                [],
            );
            self::assertSame('UserController is ready.', $controllerResponse->body());
            $generatedMiddleware = (string) file_get_contents(
                $workspace . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Middleware'
                    . DIRECTORY_SEPARATOR . 'AuditMiddleware.php',
            );
            self::assertStringContainsString('implements MiddlewareInterface', $generatedMiddleware);
            self::assertStringContainsString('return $next($request);', $generatedMiddleware);
            token_get_all($generatedMiddleware, TOKEN_PARSE);
            require $workspace . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR
                . 'Middleware' . DIRECTORY_SEPARATOR . 'AuditMiddleware.php';
            $middlewareResponse = (new \App\Middleware\AuditMiddleware())->process(
                \Nora\Http\Request::fromUri('GET', '/'),
                static fn (\Nora\Http\Request $request): \Nora\Http\Response =>
                    \Nora\Http\Response::text('passed through'),
            );
            self::assertSame('passed through', $middlewareResponse->body());
            $generatedService = $workspace . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR
                . 'Services' . DIRECTORY_SEPARATOR . 'MailerService.php';
            self::assertFileExists($generatedService);
            self::assertStringContainsString('namespace App\\Services;', (string) file_get_contents($generatedService));
            token_get_all((string) file_get_contents($generatedService), TOKEN_PARSE);
            require $generatedService;
            self::assertInstanceOf(\App\Services\MailerService::class, new \App\Services\MailerService());
            $generatedTest = $workspace . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR
                . 'Feature' . DIRECTORY_SEPARATOR . 'ProfileTest.php';
            self::assertFileExists($generatedTest);
            self::assertStringContainsString(
                'ApplicationBootstrap())->boot(dirname(__DIR__, 2))',
                (string) file_get_contents($generatedTest),
            );
            token_get_all((string) file_get_contents($generatedTest), TOKEN_PARSE);
            $renderedView = (new \Nora\View\TemplateRenderer(
                $workspace . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Views',
            ))->render('profile', ['title' => '<script>alert(1)</script>']);
            self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $renderedView);
            self::assertStringContainsString(
                '{{ title }}',
                (string) file_get_contents(
                    $workspace . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Views'
                        . DIRECTORY_SEPARATOR . 'profile.nora',
                ),
            );
            token_get_all(
                (string) file_get_contents($workspace . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Controllers' . DIRECTORY_SEPARATOR . 'UserController.php'),
                TOKEN_PARSE,
            );
            self::assertStringNotContainsString(
                'Not implemented',
                (string) file_get_contents(
                    $workspace . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Controllers'
                        . DIRECTORY_SEPARATOR . 'UserController.php',
                ),
            );
            token_get_all(
                (string) file_get_contents($workspace . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Models' . DIRECTORY_SEPARATOR . 'User.php'),
                TOKEN_PARSE,
            );
        } finally {
            fclose($output);
            $this->removeTree($workspace);
        }
    }

    public function testMigrateRequiresConfiguredDatabaseInsteadOfPretendingSuccess(): void
    {
        $workspace = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nora-cli-' . bin2hex(random_bytes(5));
        self::assertTrue(mkdir($workspace));
        $previousDsn = getenv('NORA_DSN');
        putenv('NORA_DSN');
        [$app, $output] = $this->application($workspace);

        try {
            self::assertSame(1, $app->run(['migrate']));
        } finally {
            if ($previousDsn !== false) {
                putenv('NORA_DSN=' . $previousDsn);
            }
            fclose($output);
            rmdir($workspace);
        }
    }

    public function testServeRejectsInvalidHostWithoutStartingAProcess(): void
    {
        $workspace = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nora-cli-' . bin2hex(random_bytes(5));
        self::assertTrue(mkdir($workspace));
        [$app, $output] = $this->application($workspace);

        try {
            self::assertSame(1, $app->run(['serve', 'not-an-ip', '8000']));
        } finally {
            fclose($output);
            rmdir($workspace);
        }
    }

    public function testDatabaseCommandsUseProjectConfigurationAndUnifiedRuntime(): void
    {
        $workspace = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nora-cli-' . bin2hex(random_bytes(5));
        self::assertTrue(mkdir($workspace));
        self::assertTrue(mkdir($workspace . DIRECTORY_SEPARATOR . 'config'));
        self::assertTrue(mkdir($workspace . DIRECTORY_SEPARATOR . 'migrations', 0777, true));
        $databasePath = $workspace . DIRECTORY_SEPARATOR . 'database.sqlite';

        $databaseConfig = <<<'PHP'
<?php

return [
    'default' => 'sqlite',
    'connections' => [
        'sqlite' => [
            'driver' => 'sqlite',
            'database' => __DIR__ . '/../database.sqlite',
        ],
    ],
];
PHP;
        file_put_contents($workspace . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'database.php', $databaseConfig);

        $migration = <<<'PHP'
<?php

declare(strict_types=1);

use Nora\Database\MigrationInterface;

return new class implements MigrationInterface {
    public function up(PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE cli_items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)');
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE cli_items');
    }
};
PHP;
        file_put_contents($workspace . DIRECTORY_SEPARATOR . 'migrations' . DIRECTORY_SEPARATOR . '20260101000000_create_cli_items_table.php', $migration);

        $previousDsn = getenv('NORA_DSN');
        putenv('NORA_DSN');

        try {
            [$app, $output, $error] = $this->applicationWithError($workspace);
            self::assertSame(0, $app->run(['make:seeder', 'CliItemSeeder']));
            $seederPath = $workspace . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'seeders' . DIRECTORY_SEPARATOR . 'CliItemSeeder.php';
            self::assertFileExists($seederPath);
            $seederContents = <<<'PHP'
<?php

declare(strict_types=1);

use Nora\Database\SeederInterface;

return new class implements SeederInterface {
    public function run(PDO $pdo): void
    {
        $pdo->exec("INSERT INTO cli_items (name) VALUES ('seeded')");
    }
};
PHP;
            file_put_contents($seederPath, $seederContents);

            rewind($output);
            self::assertStringContainsString('Created', (string) stream_get_contents($output));
            fclose($output);
            fclose($error);

            [$app, $output, $error] = $this->applicationWithError($workspace);
            self::assertSame(0, $app->run(['migrate:status']));
            rewind($output);
            $statusOutput = (string) stream_get_contents($output);
            self::assertStringContainsString('20260101000000_create_cli_items_table pending', $statusOutput);
            fclose($output);
            fclose($error);

            [$app, $output, $error] = $this->applicationWithError($workspace);
            self::assertSame(0, $app->run(['migrate']));
            rewind($output);
            self::assertStringContainsString('20260101000000_create_cli_items_table', (string) stream_get_contents($output));
            fclose($output);
            fclose($error);

            [$app, $output, $error] = $this->applicationWithError($workspace);
            self::assertSame(0, $app->run(['db:seed', 'CliItemSeeder']));
            rewind($output);
            self::assertStringContainsString('CliItemSeeder', (string) stream_get_contents($output));
            fclose($output);
            fclose($error);

            $pdo = new \PDO('sqlite:' . $databasePath);
            $count = $pdo->query('SELECT COUNT(*) FROM cli_items')->fetchColumn();
            self::assertSame(1, (int) $count);
            $pdo = null;

            [$app, $output, $error] = $this->applicationWithError($workspace);
            self::assertSame(0, $app->run(['migrate:rollback']));
            rewind($output);
            self::assertStringContainsString('20260101000000_create_cli_items_table', (string) stream_get_contents($output));
            fclose($output);
            fclose($error);
            unset($app);
            gc_collect_cycles();
        } finally {
            if ($previousDsn !== false) {
                putenv('NORA_DSN=' . $previousDsn);
            } else {
                putenv('NORA_DSN');
            }
            gc_collect_cycles();
            clearstatcache();
            $this->removeTree($workspace);
        }
    }

    public function testDatabaseCommandsReportBootstrapFailureWhenFallbackIsUnavailable(): void
    {
        $workspace = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nora-cli-bootstrap-' . bin2hex(random_bytes(5));
        self::assertTrue(mkdir($workspace));
        self::assertTrue(mkdir($workspace . DIRECTORY_SEPARATOR . 'config'));

        file_put_contents(
            $workspace . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'app.php',
            "<?php\nreturn ['providers' => ['Not\\\\A\\\\Real\\\\Provider']];\n",
        );

        $envVars = ['NORA_DSN', 'NORA_DB_USERNAME', 'NORA_DB_PASSWORD', 'DB_CONNECTION', 'DB_DATABASE', 'DB_HOST', 'DB_PORT', 'DB_USERNAME', 'DB_PASSWORD'];
        $previous = [];
        foreach ($envVars as $name) {
            $previous[$name] = getenv($name);
            putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);
        }

        try {
            [$app, $output, $error] = $this->applicationWithError($workspace);

            self::assertSame(1, $app->run(['migrate:status']));

            rewind($error);
            $stderr = (string) stream_get_contents($error);
            rewind($output);
            fclose($output);
            fclose($error);

            self::assertStringContainsString('Application bootstrap failed', $stderr);
            self::assertStringContainsString('Service provider class does not exist', $stderr);
        } finally {
            foreach ($previous as $name => $value) {
                if ($value === false) {
                    putenv($name);
                    unset($_ENV[$name], $_SERVER[$name]);
                } else {
                    putenv($name . '=' . $value);
                    $_ENV[$name] = $value;
                    $_SERVER[$name] = $value;
                }
            }
            $this->removeTree($workspace);
        }
    }

    /**
     * @return array{ConsoleApplication, resource}
     */
    private function application(string $workspace): array
    {
        $output = fopen('php://memory', 'w+b');
        $error = fopen('php://memory', 'w+b');
        self::assertIsResource($output);
        self::assertIsResource($error);

        return [
            new ConsoleApplication(dirname(__DIR__), $workspace, $output, $error),
            $output,
        ];
    }

    /**
     * @return array{ConsoleApplication, resource, resource}
     */
    private function applicationWithError(string $workspace): array
    {
        $output = fopen('php://memory', 'w+b');
        $error = fopen('php://memory', 'w+b');
        self::assertIsResource($output);
        self::assertIsResource($error);

        return [
            new ConsoleApplication(dirname(__DIR__), $workspace, $output, $error),
            $output,
            $error,
        ];
    }

    private function removeTree(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $entries = scandir($directory);
        self::assertNotFalse($entries);
        foreach (array_diff($entries, ['.', '..']) as $entry) {
            $path = $directory . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($path)) {
                $this->removeTree($path);
            } else {
                if (is_file($path)) {
                    @unlink($path);
                    if (is_file($path)) {
                        gc_collect_cycles();
                        clearstatcache();
                        @unlink($path);
                    }
                }
            }
        }
        @rmdir($directory);
    }
}
