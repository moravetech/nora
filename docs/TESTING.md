# Testing

## Requirements

- PHP 8.1+ (project currently tested on PHP 8.2.34 in this environment)
- Composer dependencies installed (`vendor/`)
- Node.js/npm dependencies installed (`node_modules/`) for frontend/Tailwind/browser tests

## Backend / PHP

```bash
composer validate --strict
php vendor/phpunit/phpunit/phpunit --do-not-cache-result --display-warnings
```

Useful targeted suites:

```bash
php vendor/phpunit/phpunit/phpunit --do-not-cache-result --filter DatabaseConfigurationTest
php vendor/phpunit/phpunit/phpunit --do-not-cache-result --filter ServiceProviderTest
php vendor/phpunit/phpunit/phpunit --do-not-cache-result --filter EventDispatcherTest
php vendor/phpunit/phpunit/phpunit --do-not-cache-result --filter ModelDatabaseIntegrationTest
php vendor/phpunit/phpunit/phpunit --do-not-cache-result --filter UnifiedRuntimeIntegrationTest
php vendor/phpunit/phpunit/phpunit --do-not-cache-result --filter ConsoleTest
```

## Frontend / assets / Tailwind

From repository root (`package.json` scripts):

```bash
npm run assets:build
npm run assets:dev
npm run test:tailwind
npm run test:browser
```

- `test:tailwind` builds assets and runs `tests/tailwind.test.js`.
- `test:browser` runs `pretest:browser` (`assets:build`) then executes browser tests for reactive runtime and Tailwind dashboard examples (requires Playwright browsers installed).

## Database integration environments

- **SQLite**: fully testable locally (in-memory or file-based); covered by real PHPUnit tests.
- **MySQL/MariaDB/PostgreSQL**: runtime integration requires real database servers/credentials. Tests or validation should be marked **BLOCKED** when environments are unavailable; do not claim PASS without real execution.

## Notes

- Some negative-path tests intentionally emit JSON/log output to stderr; PHPUnit summary/exit code indicate pass/fail.
- On Windows PowerShell, prefer running PHPUnit and checking its summary/exit code rather than treating stderr text as a command failure.
- Preserve and run regression suites after changes to bootstrap, providers, container, ORM, CLI, or frontend integration.