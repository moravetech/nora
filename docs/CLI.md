# CLI

Nora provides the `nora` console entrypoint (`bin/nora`) backed by `Nora\Console\ConsoleApplication`.

## Common commands

```bash
php nora new <name> [destination]
php nora serve [host] [port]
php nora migrate
php nora migrate:status
php nora migrate:rollback [steps]
php nora make:migration <snake_case_name>
php nora make:seeder <Name>
php nora db:seed [name]
php nora make:controller <Name>
php nora make:service <Name>
php nora make:repository <Name>
php nora make:view <name>
php nora make:component <Name>
php nora make:test <Name>
php nora assets:build
php nora assets:dev
```

## Runtime and database configuration

Database commands (`migrate`, `migrate:status`, `migrate:rollback`, `db:seed`) resolve configuration from the working project:

- Loads `.env` from the working directory when present.
- Attempts to boot the application runtime via `ApplicationBootstrap` for the working directory, using the same config/container/providers/database services when available.
- Falls back to `DatabaseConfiguration` resolution for compatibility (including legacy `NORA_DSN` / `NORA_DB_*` and `DB_*` environment variables).

Configuration sources:

- `config/database.php` (`default`, `connections`, driver/options)
- Environment variables such as `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, `NORA_DSN`

## Errors and behavior

- Missing or invalid database configuration produces clear runtime errors (e.g. configure `NORA_DSN` or `config/database.php`).
- If unified application bootstrap fails and fallback database resolution cannot satisfy the command, CLI errors include bootstrap failure details to improve runtime/config/provider traceability.
- Migrations are read from `<working-directory>/migrations`.
- Seeders are read from `<working-directory>/database/seeders`.
- Commands preserve existing names and exit code conventions (`0` on success, `1` on error with message output).

## Notes

- Generated project test scaffolding may include a smoke test that boots the application; ensure project dependencies/configuration are valid for the generated environment.
- Real MySQL/PostgreSQL CLI execution requires an available database server and valid credentials. SQLite works without external services.