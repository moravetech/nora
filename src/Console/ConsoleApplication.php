<?php

declare(strict_types=1);

namespace Nora\Console;

use Nora\Config\EnvironmentLoader;
use Nora\Core\Application;
use Nora\Core\ApplicationBootstrap;
use Nora\Database\DatabaseManager;
use Nora\Database\MigrationRunner;
use Nora\Database\PdoConnection;
use PDO;
use RuntimeException;
use Throwable;

final class ConsoleApplication
{
    /**
     * @param resource|null $output
     * @param resource|null $error
     */
    private ?Application $bootedApplication = null;
    private bool $applicationBootAttempted = false;
    private ?Throwable $bootstrapException = null;

    public function __construct(
        private readonly string $frameworkRoot,
        private readonly string $workingDirectory = '',
        private mixed $output = null,
        private mixed $error = null,
    ) {
        $this->output ??= STDOUT;
        $this->error ??= STDERR;
    }

    /**
     * @param list<string> $arguments
     */
    public function run(array $arguments): int
    {
        try {
            return $this->dispatch($arguments);
        } catch (Throwable $exception) {
            fwrite($this->error, 'nora: ' . $exception->getMessage() . PHP_EOL);

            return 1;
        }
    }

    /**
     * @param list<string> $arguments
     */
    private function dispatch(array $arguments): int
    {
        $command = $arguments[0] ?? '--help';
        $parameters = array_slice($arguments, 1);

        if (in_array($command, ['--help', '-h', 'help', 'list'], true)) {
            $this->write($this->help());

            return 0;
        }

        if (in_array($command, ['--version', '-V'], true)) {
            $this->write("Nora Framework development build" . PHP_EOL);

            return 0;
        }

        return match ($command) {
            'new' => $this->newProject($parameters),
            'list' => $this->dispatch(['--help']),
            'make:controller' => $this->makeClass($parameters, 'Controllers', 'Controller'),
            'make:model' => $this->makeClass($parameters, 'Models', ''),
            'make:middleware' => $this->makeClass($parameters, 'Middleware', 'Middleware'),
            'make:service' => $this->makeClass($parameters, 'Services', 'Service'),
            'make:component' => $this->makeComponent($parameters),
            'make:view' => $this->makeView($parameters),
            'make:test' => $this->makeTest($parameters),
            'tailwind:init' => $this->initializeTailwind($parameters),
            'assets:dev' => $this->runAssetScript($parameters, 'nora:assets:dev'),
            'assets:build' => $this->runAssetScript($parameters, 'nora:assets:build'),
            'assets:check' => $this->checkAssets($parameters),
            'migrate' => $this->migrate(false),
            'migrate:status' => $this->migrate(true),
            'migrate:rollback' => $this->migrateRollback($parameters),
            'make:migration' => $this->makeMigration($parameters),
            'make:seeder' => $this->makeSeeder($parameters),
            'db:seed' => $this->runSeeders($parameters),
            'serve' => $this->serve($parameters),
            default => throw new RuntimeException('Unknown command "' . $command . '". Use --help.'),
        };
    }

    /**
     * @param list<string> $arguments
     */
    private function newProject(array $arguments): int
    {
        if (count($arguments) !== 1 || !$this->isSafeProjectName($arguments[0])) {
            throw new RuntimeException('Usage: nora new <project-name> (a single safe directory name).');
        }

        $name = $arguments[0];
        $target = $this->workingDirectory() . DIRECTORY_SEPARATOR . $name;
        if (file_exists($target)) {
            throw new RuntimeException('Project destination already exists: ' . $target);
        }

        if (!mkdir($target, 0755)) {
            throw new RuntimeException('Unable to create project directory: ' . $target);
        }

        if (!mkdir($target . DIRECTORY_SEPARATOR . 'migrations', 0755)) {
            rmdir($target);
            throw new RuntimeException('Unable to create project migration directory.');
        }
        $files = [
            'composer.json' => json_encode([
                'name' => 'app/' . strtolower($name),
                'type' => 'project',
                'repositories' => [[
                    'type' => 'vcs',
                    'url' => 'https://github.com/Yasser-devb/nora.git',
                ]],
                'require' => ['morave/nora' => 'dev-main'],
                'autoload' => ['psr-4' => ['App\\' => 'src/']],
                'autoload-dev' => ['psr-4' => ['App\\Tests\\' => 'tests/']],
                'scripts' => ['test' => 'phpunit'],
                'require-dev' => ['phpunit/phpunit' => '^11.5'],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL,
            'package.json' => json_encode([
                'name' => strtolower($name),
                'private' => true,
                'scripts' => [
                    'nora:assets:dev' => 'tailwindcss -i resources/css/app.css -o public/assets/app.css --watch',
                    'nora:assets:build' => 'tailwindcss -i resources/css/app.css -o public/assets/app.css --minify',
                ],
                'devDependencies' => [
                    '@tailwindcss/cli' => '^4.0.0',
                    'tailwindcss' => '^4.0.0',
                ],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL,
            'resources/css/app.css' => $this->tailwindStylesheetTemplate(true),
            '.env.example' => "APP_ENV=development\nAPP_DEBUG=true\nNORA_DSN=\nNORA_DB_USERNAME=\nNORA_DB_PASSWORD=\n",
            'config/app.php' => $this->applicationConfigTemplate(),
            'src/routes.php' => $this->routeTemplate(),
            'src/Controllers/HomeController.php' => $this->homeControllerTemplate(),
            'src/Views/home.nora' => $this->homeViewTemplate(),
            'src/Reactive/CounterPage.php' => $this->reactiveComponentTemplate(),
            'phpunit.xml' => $this->phpUnitConfigTemplate(),
            'tests/Feature/HomeRouteTest.php' => $this->testTemplate('HomeRouteTest'),
            'public/index.php' => $this->publicIndexTemplate(),
            'public/router.php' => $this->serverRouterTemplate(),
            'README.md' => $this->projectReadmeTemplate($name),
        ];
        $createdFiles = [];

        try {
            foreach ($files as $relative => $contents) {
                $path = $target . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
                $directory = dirname($path);
                if (!is_dir($directory) && !mkdir($directory, 0755, true)) {
                    throw new RuntimeException('Unable to create project directory: ' . $directory);
                }

                $stream = fopen($path, 'x');
                if ($stream === false) {
                    throw new RuntimeException('Unable to safely create project file: ' . $relative);
                }
                $createdFiles[] = $path;
                $written = fwrite($stream, $contents);
                fclose($stream);
                if ($written !== strlen($contents)) {
                    throw new RuntimeException('Unable to completely write project file: ' . $relative);
                }
            }
        } catch (Throwable $exception) {
            foreach ($createdFiles as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
            $this->removeEmptyDirectories($target);

            throw $exception;
        }

        $this->write('Created ' . $target . PHP_EOL);
        $this->write(
            'Next: cd ' . $name . ' && composer install && npm install'
                . ' && npm run nora:assets:build && copy .env.example .env' . PHP_EOL,
        );

        return 0;
    }

    /**
     * @param list<string> $arguments
     */
    private function initializeTailwind(array $arguments): int
    {
        if ($arguments !== []) {
            throw new RuntimeException('Usage: nora tailwind:init.');
        }

        $root = $this->workingDirectory();
        $stylesheetPath = $root . DIRECTORY_SEPARATOR . 'resources'
            . DIRECTORY_SEPARATOR . 'css' . DIRECTORY_SEPARATOR . 'app.css';
        if (file_exists($stylesheetPath)) {
            throw new RuntimeException('Tailwind input already exists; refusing to overwrite: ' . $stylesheetPath);
        }

        $manifestPath = $root . DIRECTORY_SEPARATOR . 'package.json';
        $manifest = ['private' => true];
        if (is_file($manifestPath)) {
            $contents = file_get_contents($manifestPath);
            if ($contents === false) {
                throw new RuntimeException('Unable to read package.json.');
            }
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
                throw new RuntimeException('package.json must contain a JSON object.');
            }
            $manifest = $decoded;
        }

        $manifest['scripts'] = $manifest['scripts'] ?? [];
        $manifest['devDependencies'] = $manifest['devDependencies'] ?? [];
        if (!is_array($manifest['scripts']) || !is_array($manifest['devDependencies'])) {
            throw new RuntimeException('package.json scripts and devDependencies must be objects.');
        }
        $scripts = [
            'nora:assets:dev' => 'tailwindcss -i resources/css/app.css -o public/assets/app.css --watch',
            'nora:assets:build' => 'tailwindcss -i resources/css/app.css -o public/assets/app.css --minify',
        ];
        foreach ($scripts as $name => $value) {
            if (isset($manifest['scripts'][$name]) && $manifest['scripts'][$name] !== $value) {
                throw new RuntimeException('package.json already defines a different "' . $name . '" script.');
            }
            $manifest['scripts'][$name] = $value;
        }
        foreach (['@tailwindcss/cli', 'tailwindcss'] as $package) {
            $version = $manifest['devDependencies'][$package] ?? null;
            if ($version !== null && (!is_string($version) || preg_match('/^[~^]?4(?:\.|$)/', $version) !== 1)) {
                throw new RuntimeException(
                    'package.json already declares an incompatible ' . $package . ' version; resolve it before initializing Tailwind v4.',
                );
            }
            $manifest['devDependencies'][$package] = $version ?? '^4.0.0';
        }

        $cssDirectory = dirname($stylesheetPath);
        if (!is_dir($cssDirectory) && !mkdir($cssDirectory, 0755, true)) {
            throw new RuntimeException('Unable to create Tailwind stylesheet directory: ' . $cssDirectory);
        }
        $stream = fopen($stylesheetPath, 'x');
        if ($stream === false) {
            throw new RuntimeException('Unable to safely create Tailwind input: ' . $stylesheetPath);
        }
        $stylesheet = $this->tailwindStylesheetTemplate();
        $written = fwrite($stream, $stylesheet);
        fclose($stream);
        if ($written !== strlen($stylesheet)) {
            unlink($stylesheetPath);
            throw new RuntimeException('Unable to completely write Tailwind input: ' . $stylesheetPath);
        }

        $encodedManifest = json_encode(
            $manifest,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ) . PHP_EOL;
        if (file_put_contents($manifestPath, $encodedManifest, LOCK_EX) !== strlen($encodedManifest)) {
            unlink($stylesheetPath);
            throw new RuntimeException('Unable to update package.json with the Nora Tailwind scripts.');
        }

        $this->write("Added Tailwind CSS v4 input and npm scripts without replacing existing scripts." . PHP_EOL);
        $this->write("Next: npm install, then php vendor/bin/nora assets:build." . PHP_EOL);

        return 0;
    }

    /**
     * @param list<string> $arguments
     */
    private function runAssetScript(array $arguments, string $script): int
    {
        if ($arguments !== []) {
            throw new RuntimeException('Usage: nora assets:' . ($script === 'nora:assets:dev' ? 'dev' : 'build') . '.');
        }
        $this->assertTailwindConfiguration();
        $outputDirectory = $this->workingDirectory() . DIRECTORY_SEPARATOR . 'public'
            . DIRECTORY_SEPARATOR . 'assets';
        if (!is_dir($outputDirectory) && !mkdir($outputDirectory, 0755, true)) {
            throw new RuntimeException('Unable to create public asset directory: ' . $outputDirectory);
        }

        $command = PHP_OS_FAMILY === 'Windows'
            ? 'npm.cmd run ' . escapeshellarg($script)
            : ['npm', 'run', $script];
        $process = proc_open(
            $command,
            [0 => STDIN, 1 => STDOUT, 2 => STDERR],
            $pipes,
            $this->workingDirectory(),
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start npm. Install Node.js and npm before building Tailwind assets.');
        }

        return proc_close($process);
    }

    /**
     * @param list<string> $arguments
     */
    private function checkAssets(array $arguments): int
    {
        if ($arguments !== []) {
            throw new RuntimeException('Usage: nora assets:check.');
        }
        $this->assertTailwindConfiguration();
        $stylesheetPath = $this->workingDirectory() . DIRECTORY_SEPARATOR . 'public'
            . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'app.css';
        if (!is_file($stylesheetPath) || filesize($stylesheetPath) === 0) {
            throw new RuntimeException('Compiled CSS is missing. Run nora assets:build first.');
        }
        $contents = file_get_contents($stylesheetPath);
        if ($contents === false || !str_contains($contents, '@layer')) {
            throw new RuntimeException('Compiled CSS does not appear to contain Tailwind output.');
        }

        $this->write('Tailwind configuration and compiled CSS are present.' . PHP_EOL);

        return 0;
    }

    private function assertTailwindConfiguration(): void
    {
        $root = $this->workingDirectory();
        $manifestPath = $root . DIRECTORY_SEPARATOR . 'package.json';
        if (!is_file($manifestPath)) {
            throw new RuntimeException('Tailwind is not initialized. Run nora tailwind:init first.');
        }
        $contents = file_get_contents($manifestPath);
        if ($contents === false) {
            throw new RuntimeException('Unable to read package.json.');
        }
        $manifest = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        if (
            !is_array($manifest)
            || !is_array($manifest['scripts'] ?? null)
            || !is_array($manifest['devDependencies'] ?? null)
            || ($manifest['scripts']['nora:assets:dev'] ?? null)
                !== 'tailwindcss -i resources/css/app.css -o public/assets/app.css --watch'
            || ($manifest['scripts']['nora:assets:build'] ?? null)
                !== 'tailwindcss -i resources/css/app.css -o public/assets/app.css --minify'
        ) {
            throw new RuntimeException('Tailwind configuration is incomplete. Run nora tailwind:init.');
        }
        foreach (['@tailwindcss/cli', 'tailwindcss'] as $package) {
            $version = $manifest['devDependencies'][$package] ?? null;
            if (!is_string($version) || preg_match('/^[~^]?4(?:\.|$)/', $version) !== 1) {
                throw new RuntimeException('The Nora asset commands require Tailwind CSS v4.');
            }
            if (!is_dir($root . DIRECTORY_SEPARATOR . 'node_modules'
                . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $package))) {
                throw new RuntimeException('Tailwind packages are not installed. Run npm install first.');
            }
        }
        $stylesheetPath = $root . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'css'
            . DIRECTORY_SEPARATOR . 'app.css';
        $stylesheet = is_file($stylesheetPath) ? file_get_contents($stylesheetPath) : false;
        if (
            $stylesheet === false
            || !str_contains($stylesheet, '@import "tailwindcss"')
            || !str_contains($stylesheet, '@source')
        ) {
            throw new RuntimeException('Tailwind input is missing its import or source detection. Run nora tailwind:init.');
        }
    }

    private function tailwindStylesheetTemplate(bool $sourceFirst = false): string
    {
        return "@import \"tailwindcss\" source(none);\n\n"
            . "@custom-variant dark (&:where(.dark, .dark *));\n\n"
            . "@source \"../../src\";\n"
            . ($sourceFirst ? '' : "@source \"../../views\";\n@source \"../../templates\";\n");
    }

    /**
     * @param list<string> $arguments
     */
    private function makeClass(array $arguments, string $directory, string $suffix): int
    {
        if (count($arguments) !== 1 || preg_match('/^[A-Z][A-Za-z0-9]*$/D', $arguments[0]) !== 1) {
            throw new RuntimeException('Usage: nora make:' . strtolower(rtrim($directory, 's')) . ' <ClassName>.');
        }

        $name = $arguments[0];
        if ($suffix !== '' && !str_ends_with($name, $suffix)) {
            $name .= $suffix;
        }

        $base = $this->workingDirectory() . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . $directory;
        if (!is_dir($base) && !mkdir($base, 0755, true)) {
            throw new RuntimeException('Unable to create source directory: ' . $base);
        }

        $path = $base . DIRECTORY_SEPARATOR . $name . '.php';
        if (file_exists($path)) {
            throw new RuntimeException('File already exists or could not be created: ' . $path);
        }
        $stream = @fopen($path, 'x');
        if ($stream === false) {
            throw new RuntimeException('File already exists or could not be created: ' . $path);
        }

        $body = match ($directory) {
            'Controllers' => $this->controllerTemplate($name),
            'Middleware' => $this->middlewareTemplate($name),
            'Services' => $this->serviceTemplate($name),
            default => $this->modelTemplate($name),
        };
        $written = fwrite($stream, $body);
        fclose($stream);
        if ($written !== strlen($body)) {
            throw new RuntimeException('Unable to completely write generated class: ' . $path);
        }

        $this->write('Created ' . $path . PHP_EOL);

        return 0;
    }

    /**
     * @param list<string> $arguments
     */
    private function makeView(array $arguments): int
    {
        if (count($arguments) !== 1 || preg_match('/^[A-Za-z][A-Za-z0-9_-]*$/D', $arguments[0]) !== 1) {
            throw new RuntimeException('Usage: nora make:view <view-name>.');
        }

        $name = $arguments[0];
        $base = $this->workingDirectory() . DIRECTORY_SEPARATOR . 'src'
            . DIRECTORY_SEPARATOR . 'Views';
        if (!is_dir($base) && !mkdir($base, 0755, true)) {
            throw new RuntimeException('Unable to create view source directory: ' . $base);
        }

        $path = $base . DIRECTORY_SEPARATOR . $name . '.nora';
        $stream = @fopen($path, 'x');
        if ($stream === false) {
            throw new RuntimeException('File already exists or could not be created: ' . $path);
        }

        $contents = $this->viewTemplate();
        $written = fwrite($stream, $contents);
        fclose($stream);
        if ($written !== strlen($contents)) {
            if (is_file($path) && !unlink($path)) {
                throw new RuntimeException('Unable to remove an incomplete view file: ' . $path);
            }
            throw new RuntimeException('Unable to completely write generated view: ' . $path);
        }

        $this->write('Created ' . $path . PHP_EOL);

        return 0;
    }

    /**
     * @param list<string> $arguments
     */
    private function makeTest(array $arguments): int
    {
        if (count($arguments) !== 1 || preg_match('/^[A-Z][A-Za-z0-9]*$/D', $arguments[0]) !== 1) {
            throw new RuntimeException('Usage: nora make:test <TestClassName>.');
        }

        $name = $arguments[0];
        if (!str_ends_with($name, 'Test')) {
            $name .= 'Test';
        }
        $base = $this->workingDirectory() . DIRECTORY_SEPARATOR . 'tests'
            . DIRECTORY_SEPARATOR . 'Feature';
        if (!is_dir($base) && !mkdir($base, 0755, true)) {
            throw new RuntimeException('Unable to create feature test directory: ' . $base);
        }

        $path = $base . DIRECTORY_SEPARATOR . $name . '.php';
        $stream = @fopen($path, 'x');
        if ($stream === false) {
            throw new RuntimeException('File already exists or could not be created: ' . $path);
        }

        $contents = $this->testTemplate($name);
        $written = fwrite($stream, $contents);
        fclose($stream);
        if ($written !== strlen($contents)) {
            if (is_file($path) && !unlink($path)) {
                throw new RuntimeException('Unable to remove an incomplete test file: ' . $path);
            }
            throw new RuntimeException('Unable to completely write generated test: ' . $path);
        }

        $this->write('Created ' . $path . PHP_EOL);

        return 0;
    }

    /**
     * @param list<string> $arguments
     */
    private function makeComponent(array $arguments): int
    {
        if (count($arguments) !== 1 || preg_match('/^[A-Z][A-Za-z0-9]*$/D', $arguments[0]) !== 1) {
            throw new RuntimeException('Usage: nora make:component <ClassName>.');
        }

        $name = $arguments[0];
        $base = $this->workingDirectory() . DIRECTORY_SEPARATOR . 'src'
            . DIRECTORY_SEPARATOR . 'Reactive';
        if (!is_dir($base) && !mkdir($base, 0755, true)) {
            throw new RuntimeException('Unable to create component source directory: ' . $base);
        }
        $path = $base . DIRECTORY_SEPARATOR . $name . '.php';
        $stream = @fopen($path, 'x');
        if ($stream === false) {
            throw new RuntimeException('File already exists or could not be created: ' . $path);
        }

        $contents = "<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Reactive;\n\n"
            . "use Nora\\Reactive\\Component;\nuse Nora\\Reactive\\ReactiveAction;\n"
            . "use Nora\\Reactive\\SafeHtml;\nuse Nora\\Reactive\\State;\nuse Nora\\Reactive\\UI;\n\n"
            . "final class {$name} extends Component\n{\n"
            . "    public function initialState(): array\n    {\n        return [];\n    }\n\n"
            . "    protected function view(State \$state): SafeHtml\n    {\n"
            . "        return UI::element('section', [], '{$name}');\n    }\n\n"
            . "    public function actions(): array\n    {\n        return [];\n    }\n}\n";
        $written = fwrite($stream, $contents);
        fclose($stream);
        if ($written !== strlen($contents)) {
            if (is_file($path) && !unlink($path)) {
                throw new RuntimeException('Unable to remove an incomplete component file: ' . $path);
            }
            throw new RuntimeException('Unable to completely write component class: ' . $path);
        }

        $this->write('Created ' . $path . PHP_EOL);

        return 0;
    }

    private function migrate(bool $showStatus): int
    {
        $runner = $this->migrationRunner();

        if ($showStatus) {
            $status = $runner->status();
            if ($status === []) {
                $this->write('No migrations found.' . PHP_EOL);
            }
            foreach ($status as $item) {
                $state = $item['applied'] ? 'applied' : 'pending';
                $this->write($item['migration'] . ' ' . $state . PHP_EOL);
            }
            return 0;
        }

        $migrated = $runner->migrate();
        $this->write($migrated === [] ? 'Nothing to migrate.' . PHP_EOL : implode(PHP_EOL, $migrated) . PHP_EOL);

        return 0;
    }

    /**
     * @param list<string> $arguments
     */
    private function migrateRollback(array $arguments): int
    {
        if (count($arguments) > 1) {
            throw new RuntimeException('Usage: nora migrate:rollback [steps].');
        }

        $steps = null;
        if (isset($arguments[0])) {
            if (ctype_digit($arguments[0]) !== true || (int) $arguments[0] < 1) {
                throw new RuntimeException('Rollback steps must be a positive integer.');
            }
            $steps = (int) $arguments[0];
        }

        $rolledBack = $this->migrationRunner()->rollback($steps);
        $this->write(
            $rolledBack === []
                ? 'Nothing to roll back.' . PHP_EOL
                : implode(PHP_EOL, $rolledBack) . PHP_EOL,
        );

        return 0;
    }

    private function bootedApplication(): ?Application
    {
        if ($this->applicationBootAttempted) {
            return $this->bootedApplication;
        }

        $this->applicationBootAttempted = true;
        $this->loadEnvironmentFile();

        try {
            $this->bootedApplication = (new ApplicationBootstrap())->boot($this->workingDirectory());
        } catch (Throwable $exception) {
            $this->bootedApplication = null;
            $this->bootstrapException = $exception;
        }

        return $this->bootedApplication;
    }

    private function databaseConnection(): \Nora\Database\PdoConnection
    {
        $this->loadEnvironmentFile();

        $app = $this->bootedApplication();
        if ($app instanceof Application) {
            try {
                $container = $app->container();
                if ($container->has(DatabaseManager::class)) {
                    /** @var DatabaseManager $manager */
                    $manager = $container->get(DatabaseManager::class);
                    return $manager->connection();
                }
            } catch (Throwable $exception) {
                if ($exception instanceof \Nora\Database\DatabaseException || $exception instanceof RuntimeException) {
                    $message = $exception->getMessage();
                    if (str_contains($message, 'Set NORA_DSN') || str_contains($message, 'Database configuration is missing') || str_contains($message, 'not configured')) {
                        throw new RuntimeException(
                            'Set NORA_DSN in the environment or provide config/database.php before running database commands.',
                            0,
                            $exception,
                        );
                    }
                    throw new RuntimeException($message, 0, $exception);
                }
                throw new RuntimeException($exception->getMessage(), 0, $exception);
            }
        }

        try {
            return \Nora\Database\DatabaseConfiguration::manager($this->workingDirectory())->connection();
        } catch (\Nora\Database\DatabaseException $exception) {
            $message = $exception->getMessage();
            if (str_contains($message, 'Set NORA_DSN') || str_contains($message, 'Database configuration is missing')) {
                $fallbackMessage = 'Set NORA_DSN in the environment or provide config/database.php before running database commands.';
                if ($this->bootstrapException instanceof Throwable) {
                    $fallbackMessage .= ' Application bootstrap failed: ' . $this->bootstrapException->getMessage();
                }
                throw new RuntimeException(
                    $fallbackMessage,
                    0,
                    $exception,
                );
            }
            throw new RuntimeException($message, 0, $exception);
        } catch (Throwable $exception) {
            if ($this->bootstrapException instanceof Throwable) {
                throw new RuntimeException(
                    'Database command failed and application bootstrap failed: ' . $this->bootstrapException->getMessage(),
                    0,
                    $exception,
                );
            }
            throw $exception;
        }
    }

    private function migrationRunner(): MigrationRunner
    {
        $connection = $this->databaseConnection();

        return new MigrationRunner(
            $connection->pdo(),
            $this->workingDirectory() . DIRECTORY_SEPARATOR . 'migrations',
        );
    }

    /**
     * @param list<string> $arguments
     */
    private function makeMigration(array $arguments): int
    {
        if (count($arguments) !== 1 || preg_match('/^[a-z0-9_]+$/D', $arguments[0]) !== 1) {
            throw new RuntimeException('Usage: nora make:migration <snake_case_name>.');
        }

        $directory = $this->workingDirectory() . DIRECTORY_SEPARATOR . 'migrations';
        if (!is_dir($directory) && !mkdir($directory, 0755, true)) {
            throw new RuntimeException('Unable to create migrations directory: ' . $directory);
        }

        $name = gmdate('YmdHis') . '_' . $arguments[0];
        $path = $directory . DIRECTORY_SEPARATOR . $name . '.php';
        $stream = @fopen($path, 'x');
        if ($stream === false) {
            throw new RuntimeException('File already exists or could not be created: ' . $path);
        }

        $contents = "<?php\n\ndeclare(strict_types=1);\n\nuse Nora\\Database\\MigrationInterface;\n\n"
            . "return new class implements MigrationInterface {\n"
            . "    public function up(PDO \$pdo): void\n    {\n    }\n\n"
            . "    public function down(PDO \$pdo): void\n    {\n    }\n};\n";
        $written = fwrite($stream, $contents);
        fclose($stream);
        if ($written !== strlen($contents)) {
            if (is_file($path)) {
                @unlink($path);
            }
            throw new RuntimeException('Unable to completely write migration: ' . $path);
        }

        $this->write('Created ' . $path . PHP_EOL);

        return 0;
    }

    /**
     * @param list<string> $arguments
     */
    private function makeSeeder(array $arguments): int
    {
        if (count($arguments) !== 1 || preg_match('/^[A-Za-z0-9_]+$/D', $arguments[0]) !== 1) {
            throw new RuntimeException('Usage: nora make:seeder <Name>.');
        }

        $directory = $this->workingDirectory() . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'seeders';
        if (!is_dir($directory) && !mkdir($directory, 0755, true)) {
            throw new RuntimeException('Unable to create seeders directory: ' . $directory);
        }

        $name = $arguments[0];
        $path = $directory . DIRECTORY_SEPARATOR . $name . '.php';
        $stream = @fopen($path, 'x');
        if ($stream === false) {
            throw new RuntimeException('File already exists or could not be created: ' . $path);
        }

        $contents = "<?php\n\ndeclare(strict_types=1);\n\nuse Nora\\Database\\SeederInterface;\n\n"
            . "return new class implements SeederInterface {\n"
            . "    public function run(PDO \$pdo): void\n    {\n    }\n};\n";
        $written = fwrite($stream, $contents);
        fclose($stream);
        if ($written !== strlen($contents)) {
            if (is_file($path)) {
                @unlink($path);
            }
            throw new RuntimeException('Unable to completely write seeder: ' . $path);
        }

        $this->write('Created ' . $path . PHP_EOL);

        return 0;
    }

    /**
     * @param list<string> $arguments
     */
    private function runSeeders(array $arguments): int
    {
        if (count($arguments) > 1) {
            throw new RuntimeException('Usage: nora db:seed [name].');
        }

        $connection = $this->databaseConnection();

        $runner = new \Nora\Database\SeederRunner(
            $connection->pdo(),
            $this->workingDirectory() . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'seeders',
        );
        $seeded = $runner->run($arguments[0] ?? null);
        $this->write($seeded === [] ? 'No seeders were run.' . PHP_EOL : implode(PHP_EOL, $seeded) . PHP_EOL);

        return 0;
    }

    /**
     * @param list<string> $arguments
     */
    private function serve(array $arguments): int
    {
        if (count($arguments) > 2) {
            throw new RuntimeException('Usage: nora serve [host] [port].');
        }

        $host = $arguments[0] ?? '127.0.0.1';
        $port = isset($arguments[1]) ? filter_var($arguments[1], FILTER_VALIDATE_INT) : 8000;
        if (filter_var($host, FILTER_VALIDATE_IP) === false
            || $port === false
            || $port < 1
            || $port > 65535
        ) {
            throw new RuntimeException('Serve host must be an IP address and port must be between 1 and 65535.');
        }

        $public = $this->workingDirectory() . DIRECTORY_SEPARATOR . 'public';
        $index = $public . DIRECTORY_SEPARATOR . 'index.php';
        $router = $public . DIRECTORY_SEPARATOR . 'router.php';
        if (!is_file($index) || !is_file($router)) {
            throw new RuntimeException('No generated Nora app found. Run nora new <project-name> first.');
        }

        $address = str_contains($host, ':') ? '[' . $host . ']:' . $port : $host . ':' . $port;
        $command = [
            PHP_BINARY,
            '-S',
            $address,
            '-t',
            $public,
            $router,
        ];
        $process = proc_open($command, [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes, $this->workingDirectory());
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start the PHP development server.');
        }

        return proc_close($process);
    }

    private function loadEnvironmentFile(): void
    {
        $path = $this->workingDirectory() . DIRECTORY_SEPARATOR . '.env';
        if (is_file($path)) {
            (new EnvironmentLoader())->load($path);
        }
    }

    private function workingDirectory(): string
    {
        if ($this->workingDirectory !== '') {
            return $this->workingDirectory;
        }

        $directory = getcwd();
        if ($directory === false) {
            throw new RuntimeException('Unable to determine the current working directory.');
        }

        return $directory;
    }

    private function isSafeProjectName(string $name): bool
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/D', $name) !== 1 || $name === '.' || $name === '..') {
            return false;
        }

        return preg_match('/^(CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])(?:\.|$)/i', $name) !== 1;
    }

    private function removeEmptyDirectories(string $directory): void
    {
        $entries = scandir($directory);
        if ($entries === false) {
            return;
        }
        foreach (array_diff($entries, ['.', '..']) as $entry) {
            $path = $directory . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($path)) {
                $this->removeEmptyDirectories($path);
            }
        }
        rmdir($directory);
    }

    private function controllerTemplate(string $name): string
    {
        return "<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Controllers;\n\n"
            . "use Nora\\Http\\Request;\nuse Nora\\Http\\Response;\n\n"
            . 'final class ' . $name . "\n{\n"
            . "    public function __invoke(Request \$request, array \$parameters): Response\n"
            . "    {\n        return Response::text('" . $name . " is ready.');\n    }\n}\n";
    }

    private function serviceTemplate(string $name): string
    {
        return "<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Services;\n\n"
            . 'final class ' . $name . "\n{\n}\n";
    }

    private function homeControllerTemplate(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Controllers;

use Nora\Http\Request;
use Nora\Http\Response;
use Nora\View\TemplateRenderer;

final class HomeController
{
    public function __invoke(Request $request, array $parameters): Response
    {
        $templates = new TemplateRenderer(dirname(__DIR__) . '/Views');
        $html = $templates->render('home', [
            'title' => 'Welcome to Nora',
            'message' => 'Your application code, routes, and views live in src/.',
        ]);

        return new Response($html, 200, ['Content-Type' => 'text/html; charset=utf-8']);
    }
}
PHP . "\n";
    }

    private function homeViewTemplate(): string
    {
        return <<<'HTML'
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ title }}</title>
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body class="min-h-screen bg-slate-50 text-slate-900 antialiased dark:bg-slate-950 dark:text-slate-100">
    <main class="mx-auto max-w-3xl px-6 py-16">
        <p class="text-sm font-semibold uppercase tracking-wide text-indigo-700 dark:text-indigo-300">Nora Framework</p>
        <h1 class="mt-3 text-4xl font-bold tracking-tight">{{ title }}</h1>
        <p class="mt-4 text-lg text-slate-600 dark:text-slate-300">{{ message }}</p>
        <a class="mt-8 inline-flex rounded-lg bg-indigo-600 px-4 py-2 font-semibold text-white hover:bg-indigo-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600"
           href="/">Open the reactive counter</a>
    </main>
</body>
</html>
HTML . "\n";
    }

    private function middlewareTemplate(string $name): string
    {
        return "<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Middleware;\n\n"
            . "use Nora\\Http\\Request;\nuse Nora\\Http\\Response;\nuse Nora\\Routing\\MiddlewareInterface;\n\n"
            . 'final class ' . $name . " implements MiddlewareInterface\n{\n"
            . "    public function process(Request \$request, callable \$next): Response\n"
            . "    {\n        return \$next(\$request);\n    }\n}\n";
    }

    private function viewTemplate(): string
    {
        return <<<'HTML'
<main class="mx-auto max-w-3xl px-6 py-12">
    <h1 class="text-3xl font-semibold text-slate-950 dark:text-slate-100">{{ title }}</h1>
</main>
HTML . "\n";
    }

    private function modelTemplate(string $name): string
    {
        return "<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Models;\n\n"
            . 'final class ' . $name . "\n{\n}\n";
    }

    private function routeTemplate(): string
    {
        return "<?php\n\ndeclare(strict_types=1);\n\nuse Nora\\Core\\Application;\n"
            . "use App\\Controllers\\HomeController;\nuse App\\Reactive\\CounterPage;\n"
            . "use Nora\\Reactive\\ReactiveEngine;\nuse Nora\\Security\\SessionManager;\n\n"
            . "return static function (Application \$app): void {\n"
            . "    \$app->route('GET', '/welcome', new HomeController());\n"
            . "    \$runtime = new ReactiveEngine(new SessionManager("
            . "\$app->config()->get('app.environment') === 'production'));\n"
            . "    \$runtime->register('counter', new CounterPage());\n"
            . "    \$runtime->mount(\$app, 'counter', '/', 'Nora', '/assets/app.css');\n};\n";
    }

    private function applicationConfigTemplate(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

return [
    'app' => [
        'environment' => 'production',
        'debug' => false,
    ],
    'logging' => [
        'level' => 'info',
    ],
];
PHP . "\n";
    }

    private function phpUnitConfigTemplate(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<phpunit bootstrap="vendor/autoload.php" colors="true" failOnWarning="true">
    <testsuites>
        <testsuite name="Application">
            <directory>tests</directory>
        </testsuite>
    </testsuites>
</phpunit>
XML . "\n";
    }

    private function testTemplate(string $name): string
    {
        return "<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Tests\\Feature;\n\n"
            . "use Nora\\Core\\ApplicationBootstrap;\nuse Nora\\Http\\Request;\n"
            . "use PHPUnit\\Framework\\TestCase;\n\n"
            . 'final class ' . $name . " extends TestCase\n{\n"
            . "    public function testWelcomeRouteRendersTheApplicationView(): void\n"
            . "    {\n        \$application = (new ApplicationBootstrap())->boot(dirname(__DIR__, 2));\n"
            . "        \$response = \$application->handle(Request::fromUri('GET', '/welcome'));\n\n"
            . "        self::assertSame(200, \$response->status());\n"
            . "        self::assertSame('text/html; charset=utf-8', \$response->headers()['Content-Type']);\n"
            . "        self::assertStringContainsString('Welcome to Nora', \$response->body());\n"
            . "        self::assertStringContainsString('Your application code, routes, and views live in src/.', \$response->body());\n"
            . "    }\n}\n";
    }

    private function reactiveComponentTemplate(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Reactive;

use Nora\Reactive\Component;
use Nora\Reactive\Components\Dropdown;
use Nora\Reactive\Components\Modal;
use Nora\Reactive\ReactiveAction;
use Nora\Reactive\SafeHtml;
use Nora\Reactive\State;
use Nora\Reactive\UI;
use Nora\Reactive\Components\Tabs;

final class CounterPage extends Component
{
    public function initialState(): array
    {
        return ['count' => 0];
    }

    protected function view(State $state): SafeHtml
    {
        $count = $state->get('count');
        $counters = UI::each(
            [
                ['key' => 'primary', 'title' => 'Primary counter'],
                ['key' => 'secondary', 'title' => 'Coordinated counter'],
            ],
            static fn (array $counter): string => $counter['key'],
            static fn (array $counter): SafeHtml => UI::element('section', [
                'data-nora-component' => 'counter',
                'class' => $count % 2 === 0
                    ? 'text-indigo-700 dark:text-indigo-300'
                    : 'text-emerald-700 dark:text-emerald-300',
            ], [
                UI::element('h2', [], $counter['title']),
                UI::element('p', [], [
                    'Shared count: ',
                    UI::element('output', [], $count),
                ]),
                UI::actionButton('increment', 'Increment', ['amount' => 1], [
                    'class' => 'rounded-lg bg-indigo-600 px-4 py-2 text-white hover:bg-indigo-700',
                ]),
            ]),
        );

        return UI::fragment([
            UI::element('h1', [], 'Nora reactive counter'),
            UI::element('p', [], 'Change this field, then activate a counter to verify focus and input preservation.'),
            UI::element('label', [], [
                'Unsubmitted note ',
                UI::element('input', ['type' => 'text', 'value' => 'Keep my input']),
            ]),
            $counters,
            Modal::render(
                ['id' => 'counter-help', 'title' => 'About Nora', 'trigger' => 'About'],
                UI::element('p', [], 'This dialog is accessible and uses the browser dialog element.'),
            ),
            Tabs::render('counter-tabs', 'Counter information', [
                ['id' => 'overview', 'label' => 'Overview', 'content' => UI::element('p', [], 'Server-rendered overview.')],
                ['id' => 'details', 'label' => 'Details', 'content' => UI::element('p', [], 'Additional details.')],
            ]),
            Dropdown::render(
                ['label' => 'More options'],
                UI::element('a', ['href' => '/health'], 'Health endpoint'),
            ),
        ]);
    }

    public function actions(): array
    {
        return [
            'increment' => new ReactiveAction(
                ['amount' => 'integer|min:1|max:10'],
                static fn (array $state, array $arguments): array => [
                    'count' => $state['count'] + (int) $arguments['amount'],
                ],
                static fn (array $state, array $arguments): bool => $state['count'] < 10,
            ),
        ];
    }
}
PHP;
    }

    private function publicIndexTemplate(): string
    {
        return "<?php\n\ndeclare(strict_types=1);\n\nrequire dirname(__DIR__) . '/vendor/autoload.php';\n\n"
            . "use Nora\\Core\\ApplicationBootstrap;\n\n"
            . "(new ApplicationBootstrap())->boot(dirname(__DIR__))->run();\n";
    }

    private function serverRouterTemplate(): string
    {
        return "<?php\n\n\$path = parse_url(\$_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);\n"
            . "\$root = realpath(__DIR__);\n\$file = is_string(\$path) ? realpath(__DIR__ . \$path) : false;\n"
            . "if (\$root !== false && \$file !== false && is_file(\$file)\n"
            . "    && (DIRECTORY_SEPARATOR === '\\\\'\n"
            . "        ? str_starts_with(strtolower(\$file), strtolower(\$root . DIRECTORY_SEPARATOR))\n"
            . "        : str_starts_with(\$file, \$root . DIRECTORY_SEPARATOR))) {\n"
            . "    return false;\n}\nrequire __DIR__ . '/index.php';\n";
    }

    private function projectReadmeTemplate(string $name): string
    {
        return '# ' . $name . "\n\n"
            . "Generated with Nora Framework. This starter includes a controller-rendered `/welcome` page, a server-rendered reactive counter at `/`, and Tailwind CSS v4. Application classes, routes, reactive pages, and `.nora` templates live under `src/`; Composer maps `App\\` to that directory. Routes and middleware are registered explicitly. Composer installs Nora from the official Git repository's moving `dev-main` branch because no stable package release is available yet. Review the [Nora release instructions](https://github.com/Yasser-devb/nora/blob/main/docs/PACKAGE_RELEASE.md) before changing this to a tagged version.\n\n"
            . "The default configuration is production mode with debug output disabled. Copy `.env.example` to `.env` for local development. The scaffold includes PHPUnit and a feature test for the welcome route; add application tests under `tests/` and run `composer test`.\n\n"
            . "```powershell\ncomposer install\nnpm install\ncopy .env.example .env\ncomposer test\nnpm run nora:assets:build\ncomposer exec nora -- serve\n```\n\n"
            . "Use `npm run nora:assets:dev` in another terminal while editing. Tailwind scans only `src/`, including PHP and `.nora` templates, and does not scan dependencies or generated output. Services are plain application classes instantiated explicitly; Nora does not provide an automatic service container. The reactive API remains experimental; see the Nora Reactive UI Engine documentation for limitations and security guidance.\n";
    }

    private function help(): string
    {
        return "Nora Framework CLI\n\n"
            . "Usage: php bin/nora <command> [arguments]\n\n"
            . "  --help, -h, help, list Show this help\n"
            . "  --version, -V          Show the framework development version\n"
            . "  new <name>             Create a safe starter application\n"
            . "  make:controller <name> Generate a controller under src/Controllers\n"
            . "  make:model <name>      Generate a model under src/Models\n"
            . "  make:middleware <name> Generate middleware under src/Middleware\n"
            . "  make:service <name>    Generate a plain PHP service under src/Services\n"
            . "  make:component <name>  Generate a reactive PHP component under src/Reactive\n"
            . "  make:view <name>       Generate an escaped .nora template under src/Views\n"
            . "  make:test <name>       Generate a PHPUnit route test under tests/Feature\n"
            . "  tailwind:init          Add Tailwind CSS v4 without replacing existing scripts\n"
            . "  assets:dev             Watch and compile Tailwind CSS for development\n"
            . "  assets:build           Compile minified Tailwind CSS for production\n"
            . "  assets:check           Validate Tailwind configuration and compiled CSS\n"
            . "  migrate                Apply pending migrations using NORA_DSN\n"
            . "  migrate:status         Show migration state using NORA_DSN\n"
            . "  migrate:rollback [n]   Roll back the last batch or n migrations using NORA_DSN\n"
            . "  make:migration <name>  Generate a timestamped migration in migrations/\n"
            . "  make:seeder <name>     Generate a seeder in database/seeders/\n"
            . "  db:seed [name]         Run seeders from database/seeders/ using NORA_DSN\n"
            . "  serve [host] [port]    Run the generated app with PHP's development server\n";
    }

    private function write(string $message): void
    {
        fwrite($this->output, $message);
    }
}
