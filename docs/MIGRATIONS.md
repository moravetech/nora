# Migrations

Migrations are PHP files in the application's root `migrations/` directory.
Names must match `YYYY_name_in_lowercase.php` with at least four leading
digits. Each file returns an object implementing
`Nora\Database\MigrationInterface`:

```php
<?php

declare(strict_types=1);

use Nora\Database\MigrationInterface;
use PDO;

return new class implements MigrationInterface {
    public function up(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE example_items (id INTEGER PRIMARY KEY, label VARCHAR(100) NOT NULL)',
        );
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE example_items');
    }
};
```

Apply, inspect, and roll back migrations from the application root:

```powershell
composer exec nora -- make:migration create_example_items
composer exec nora -- migrate
composer exec nora -- migrate:status
composer exec nora -- migrate:rollback
composer exec nora -- migrate:rollback 2
```

Seeders live in `database/seeders/` and implement
`Nora\Database\SeederInterface` (`run(PDO $pdo): void`):

```powershell
composer exec nora -- make:seeder ExampleSeeder
composer exec nora -- db:seed
composer exec nora -- db:seed ExampleSeeder
```

These commands read `NORA_DSN`, `NORA_DB_USERNAME`, and
`NORA_DB_PASSWORD` from the process environment or the application's `.env`.
The migration runner creates a `nora_migrations` history table, applies files
in lexicographic order, records successful names and batches, and skips
previously applied migrations. `migrate:rollback` runs `down()` for the last
batch by default, or for the given number of most-recent applied migrations.

For drivers other than MySQL, the runner wraps each migration and its history
changes in a PDO transaction. MySQL migrations are not wrapped because DDL may
commit implicitly. A failed MySQL migration can leave partial schema changes
without a history record; inspect and repair that state before retrying. Back
up important data and test migrations on a disposable environment before
production use.

`Nora\Database\Schema` and `Nora\Database\Blueprint` provide basic
driver-aware schema helpers for common table creation/drop operations; they do
not replace a full schema/alteration system.

The repository's MySQL integration tests require a dedicated loopback test
database and explicit `NORA_TEST_DSN` plus `NORA_TEST_DB_NAME`. They are not
equivalent to a passing unit test when skipped.
