# Phase 4 Test Results — Critical Integration Closure (RC1 Track)

**Date:** 2026-11-21 (evidence closure run)  
**Environment:** Windows, PHP 8.2.34 (CLI, ZTS Visual C++ 2019 x64), Composer 2.10.3, PHPUnit 11.5.57 (repo) / PHPUnit 11.5.58 (installed scaffold app), Node v24.20.0, npm 11.19.0, Docker 29.9.3 (daemon unavailable), MySQL client 8.4 at `C:\Program Files\MySQL\MySQL Server 8.4\bin\mysql.exe`, `psql`/`pg_isready`/`sqlite3` CLI not found. PHP extensions include `pdo_mysql`, `pdo_pgsql`, `pdo_sqlite`.  
**Workspace safety:** Pre-existing modified/untracked files preserved (see git safety snapshot in audit/readiness report). Phase 4 docs/tests were updated based on actual results from this run.

## Git safety snapshot (preflight)
- Branch/HEAD: `main` / `a6db327fc3ab646ffd15fb46093a2dd34cd0bbad`
- `git status --short` (start of run): `M .env.example`, `M CHANGELOG.md`, `M README.md`, `M composer.json`, `M docs/releases/RC_CHECKLIST.md`, `M src/Console/ConsoleApplication.php`, `M tests/Integration/UnifiedRuntimeIntegrationTest.php`, `?? SUPPORT.md`, `?? docs/audit/PHASE_4_AUDIT.md`, `?? docs/audit/PHASE_4_TEST_RESULTS.md`, `?? docs/releases/PHASE_4_READINESS_REPORT.md`, `?? tests/Integration/DatabaseServerPostgreSqlTest.php`
- `git diff --stat` (start of run): 7 files changed, 212 insertions(+), 89 deletions(-)

## Baseline / local quality gates (current workspace state)

| Command | Result | Notes |
|---|---|---|
| `composer validate --strict` | **PASS** (exit 0) | `./composer.json is valid` |
| `composer audit` | **PASS** (exit 0) | `No security vulnerability advisories found.` |
| `vendor\bin\phpunit --testsuite Unit --do-not-cache-result` | **PASS** (207 tests, 1100 assertions, exit 0) |  |
| `vendor\bin\phpunit --testsuite Security --do-not-cache-result` | **PASS** (1 test, 3 assertions, exit 0) |  |
| `vendor\bin\phpunit --testsuite Integration --do-not-cache-result` | **OK, but some tests were skipped** (31 tests, 193 assertions, 5 skipped, exit 0 summary) | Expected reactive HTTP warning/error JSON logs on stderr; 5 skips include PostgreSQL-gated tests (not configured) and other environment-gated integration checks |
| `vendor\bin\phpunit --do-not-cache-result` (full) | **OK, but some tests were skipped** (239 tests, 1296 assertions, 5 skipped, exit 0 summary) | Expected reactive HTTP warning/error JSON logs on stderr; no failures |

## Phase 4.1 — Real MySQL & PostgreSQL integration execution

### Environment probes (safe, loopback)
- Test DB env vars: `NORA_TEST_DSN`, `NORA_TEST_DB_NAME`, `NORA_TEST_DB_USERNAME`, `NORA_TEST_DB_PASSWORD`, `NORA_REQUIRE_TEST_DB`, `NORA_TEST_PG_*` all **not set**.
- TCP connectivity: `127.0.0.1:3306` reachable, `127.0.0.1:5432` reachable (and localhost equivalents).
- MySQL probe: `mysql -h 127.0.0.1 -P 3306 -u root --connect-timeout=3 -e "SELECT 1 AS ok;"` → **failed** (`ERROR 1045 (28000): Access denied for user 'root'@'localhost' (using password: NO)`).
- PostgreSQL probe (PDO, read-only): `postgres` with empty password and `postgres`/`postgres` both **failed** authentication/no-password-supplied as appropriate.
- Docker: CLI installed (`Docker version 29.9.3`) but daemon unavailable (`docker info`/`docker ps` failed to connect to Docker Desktop engine), so containerized DB setup was not possible in this run.

### Results
| Backend / suite | Executed | Result | Evidence |
|---|---|---|---|
| `tests/Integration/DatabaseServerTest.php` (MySQL) | **No (live DB)** | **Not verified** | Env not configured; MySQL reachable but credentials/admin access not available; `NORA_REQUIRE_TEST_DB` not set. Existing test remains intact with loopback/`nora_test` safeguards. |
| `tests/Integration/AccountLifecycleMySqlTest.php` | **No (live DB)** | **Not verified** | Requires configured dedicated loopback `nora_test` MySQL DB/credentials; not executed. |
| `tests/Integration/DatabaseServerPostgreSqlTest.php` (new, PostgreSQL-gated) | **Loaded/skipped in suites** | **Skipped (2 tests, 0 assertions)** when not configured | Present and syntactically valid (`php -l` PASS). Skips when `NORA_TEST_PG_DSN`/PostgreSQL `NORA_TEST_DSN` not configured; fails clearly if `NORA_REQUIRE_TEST_PG_DB=1`. Real PostgreSQL execution **not verified** (reachable port, no accessible credentials). |

**Accuracy:** Skipped tests are **not** counted as passes. SQLite coverage in unit/integration suites does **not** constitute MySQL/PostgreSQL compatibility evidence. Built-in account lifecycle migration/store remain MySQL/MariaDB-specific; PostgreSQL account-lifecycle parity is **not implemented / not verified**.

## Phase 4.2 — “New app from scratch” as an external developer (GitHub/VCS install path)

Executed in isolated temporary directory outside repo: `C:\Users\PRODESK\AppData\Local\Temp\nora-phase4-smoke-399367b7cad043d09c89fa9ca40aa658`

| Step | Command | Result | Evidence |
|---|---|---|---|
| Scaffold generation | `php 'C:\Users\PRODESK\Desktop\Nora\bin\nora' new SmokeApp` (cwd = temp dir) | **PASS** (exit 0) | Created expected structure: `composer.json`, `package.json`, `.env.example`, `config/app.php`, `src/routes.php`, controller/view/reactive component, `public/index.php`, `public/router.php`, `phpunit.xml`, `tests/Feature/HomeRouteTest.php`, Tailwind resources/readme, `migrations/` |
| Default manifest inspection | `Get-Content SmokeApp\composer.json` | **Verified** | VCS repo `https://github.com/Yasser-devb/nora.git`, `require morave/nora: dev-main`, `autoload App\ => src/`, `autoload-dev App\Tests\ => tests/`, `scripts.test=phpunit`, `require-dev phpunit/phpunit:^11.5` |
| Generated PHP lint | `Get-ChildItem ...\SmokeApp -Recurse -File -Include *.php | php -l` (pre-install generated files) | **PASS** | No syntax errors in generated app PHP files |
| Environment config | `Copy-Item .env.example .env -Force` | **PASS** | `.env` created in scaffold |
| Composer install (supported remote VCS flow) | `composer install --no-interaction --prefer-dist` (in `SmokeApp`) | **PASS** (exit 0) | No lock present; resolved/installed dependencies including `morave/nora (dev-main a6db327)` (commit matches repo HEAD), PHPUnit 11.5.58 and deps; `composer.lock` and `vendor/` created |
| Generated tests in installed app context | `vendor\bin\phpunit --do-not-cache-result` (in `SmokeApp`) | **PASS** (1 test, 4 assertions, exit 0) | `tests/Feature/HomeRouteTest.php` passed against installed `vendor/autoload.php` and installed `morave/nora` |

**Observed manifest/source detail (factual):** Scaffold composer repository points to `https://github.com/Yasser-devb/nora.git`; installed `composer.lock` records `morave/nora` source as `https://github.com/moravetech/nora.git` at reference `a6db327fc3ab646ffd15fb46093a2dd34cd0bbad`. End-to-end install/build/test completed successfully in this run.

## Phase 4.3 — Composer, npm, Tailwind build, and Reactive runtime verification

| Step | Command | Result | Evidence |
|---|---|---|---|
| npm install | `npm install --no-audit --no-fund` (in `SmokeApp`) | **PASS** (exit 0) | Added 28 packages; warning noted for `@parcel/watcher@2.6.0` install script not covered by allowScripts (npm advisory/warning, install completed) |
| Tailwind/Nora asset build | `npm run nora:assets:build` (in `SmokeApp`) | **PASS** (exit 0) | `tailwindcss v4.3.5`, `Done in 95ms`; `public/assets/app.css` generated (7762 bytes, Tailwind v4 output) |
| Build artifacts | filesystem checks | **PASS** | `public/assets/app.css` exists, `node_modules` exists, `vendor/morave/nora` exists, `composer.lock` exists |
| Runtime behavior (installed app, HTTP) | `php -S 127.0.0.1:18765 -t public public/router.php` then `Invoke-WebRequest` to `/welcome` and `/` | **PASS** | `/welcome`: 200, `text/html; charset=utf-8`, body contains `Welcome to Nora` (1013 bytes). `/`: 200, `text/html; charset=utf-8`, body contains `data-nora-page`, `Counter`, and `/assets/app.css` (3263 bytes). Server stopped after verification. |

**Additional note:** Ad-hoc CLI bootstrap scripts that mixed repo framework autoload with the scaffold root returned 500 (`RuntimeException: Unable to apply secure PHP session settings`) in this execution context; this did **not** reproduce in the scaffold's installed PHPUnit test or HTTP server runtime, which both passed. Browser/Playwright checks for the scaffolded app were **not run** in this temp app (not part of scaffold default flow executed); repository-level Playwright/CI browser gates remain **not verified locally** for an RC commit.

## Phase 4.4 — CI and RC1 gate verification

| Gate | Status | Evidence / limitation |
|---|---|---|
| `composer validate --strict` | **PASS (local)** | Exit 0 |
| `composer audit` | **PASS (local)** | No advisories |
| Relevant PHPUnit suites (Unit/Integration/Security/full) | **PASS with expected skips (local)** | 239 tests, 1296 assertions, 5 skipped |
| CI workflows identified | **Known, not executed locally** | `.github/workflows/ci.yml`: `package-consumer` (archive/consumer verification), `test` (Ubuntu, MySQL 8.4 service, PHP 8.2/8.3 matrix, syntax checks, `composer test` with required DB env), `reactive-browser` (Node 22, `npm ci`, `test:tailwind`, `npm audit`, Playwright Chromium, `test:browser`) |
| CI green on candidate commit | **Not verified** | Local environment only; no push/PR workflow run evidence collected here for a candidate commit |

## Not executed / not verified
- Real MySQL/PostgreSQL integration suite execution against configured dedicated loopback `nora_test` databases (no accessible configured credentials/services; Docker daemon unavailable).
- CI jobs on an RC candidate commit (package-consumer, PHP matrix, DB-backed, reactive-browser/Tailwind/Playwright).
- Generated-app browser automation (Playwright) and cross-browser/runtime certification beyond executed evidence.
